<?php
// Path: _core/Mailer.php
/**
 * -----------------------------------------------------------------------------
 * Portal Mailer — Multi-Provider Dispatcher ✉️
 * -----------------------------------------------------------------------------
 * Sends HTML email (optionally with attachments) via the configured provider.
 * Dispatches to Microsoft Graph (MS365), the Gmail API (Google Workspace) or
 * an ordinary mailbox on a mail server (SMTP), by the `mail.provider` setting.
 *
 * THE SMTP ROUTE (#592, 12 October 2026) goes through the portal's own mail
 * library (`_core/maillib/`). Everything about it that touches the settings
 * or the mailbox's password is in MailRoutes.php; this class only asks it.
 *
 * THE QUEUE (#592, part 3). send() sends a message NOW when it can, and such
 * a message is never stored. A message that cannot go now is KEPT in the
 * mail queue (MailQueue.php) and send() answers TRUE: the mail server said
 * "later" or could not be reached, the hourly limit has no room, the request
 * has used its own budget (25 messages or 20 seconds), a fault with the
 * route is on record, or the caller asked for it to wait. The scheduled job
 * (`_apps/cron/mail-queue.php`) sends it. send() answers FALSE only when the
 * message was neither sent nor kept: the route is not set up, the mail
 * server refused THIS message, it is for more recipients than the hourly
 * limit allows in a whole hour, or the queue could not take it.
 * lastOutcome() tells the cases apart. What decides each case is in send()
 * and in MailRoutes::whatNext().
 *
 * "TRUE" IS NOT "SENT". A caller that RECORDS or SHOWS what became of a
 * message asks lastOutcome() (or lastIsWaiting()) and says "waiting" for a
 * message that was only kept. Several pages and records said "sent",
 * "delivered" and "e-mailed" for mail that was waiting, and nothing
 * corrected them when it later failed.
 *
 * WHILE THE SCHEDULED JOB IS NOT RUNNING (MailQueue::jobIsRunning() answers
 * no) nothing works through what is kept, so send() keeps as little as it
 * can: see requestMessages() and the refused sign-in in send().
 *
 * WHAT THE MICROSOFT 365 AND GOOGLE ROUTES DO WITH THE QUEUE: a message over
 * the hourly limit or the request's budget, or one asked to wait, is kept
 * and sent by the job through that route. A message one of those services
 * turns down in the request is NOT kept, as before: those routes answer only
 * yes or no, so "later" cannot be told from "never". They move onto the mail
 * library in a later package.
 *
 * The MS365 backend uses application-level OAuth2 (client credentials) and
 * Microsoft Graph. By default it sends as `mail.defaultFromAddress`
 * (`/users/{address}/sendMail`). When the admin-configured
 * `mail.ms365.sharedMailbox` setting is non-empty (#234), sending instead
 * routes through that shared mailbox: same app-only client-credentials
 * token, `POST /users/{sharedMailbox}/sendMail`, with an explicit
 * `message.from` identity and `saveToSentItems`. The shared mailbox is
 * ADMIN-CONFIG ONLY (never request/user-derived — see effectiveSender()).
 * Every send attempt (either mode, either provider) is recorded to
 * `tblEmailLog` via logSend() (#234, closes the #230 audit-trail
 * dependency too).
 *
 * The Google backend uses a service account with domain-wide delegation
 * and the Gmail API. See MailerGoogle.php for the implementation.
 *
 * Provider selection:
 *   mail.provider = 'ms365'   → Microsoft Graph (default)
 *   mail.provider = 'google'  → Gmail API via service account
 *   mail.provider = 'smtp'    → a mailbox on a mail server, see MailRoutes.php
 *
 * On a Graph failure, `mail.fallbackProvider = 'google'` (default '' =
 * off) makes one additional attempt via MailerGoogle::send() rather than
 * failing outright — logged as its own tblEmailLog row.
 *
 * Usage:
 *   Mailer::send(
 *     to:    ['alice@example.com'],
 *     subj:  'Expense Claim Approved',
 *     html:  '<p>Congrats…</p>',
 *     files: ['/path/file.pdf']
 *   );
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/234
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use RuntimeException;

class Mailer
{
    private static string $token    = '';
    private static int    $tokenExp = 0;

    /** @var string The Google route: the Gmail API, through a service account. */
    private const ROUTE_GOOGLE = 'google';

    /** @var string The Microsoft 365 route: Microsoft Graph. Also what any other value of `mail.provider` means. */
    private const ROUTE_MS365 = 'ms365';

    /** @var string The SMTP route: a mailbox on a mail server, through the portal's own mail library (#592). */
    private const ROUTE_SMTP = MailRoutes::SMTP;

    /**
     * @var array<string, string> Every route, with its name for a person to
     *      read. The e-mail page offers exactly these, so a route cannot be
     *      offered that send() does not know.
     */
    public const ROUTES = [
        self::ROUTE_MS365  => 'Microsoft 365',
        self::ROUTE_GOOGLE => 'Google',
        self::ROUTE_SMTP   => MailRoutes::SMTP_LABEL,
    ];

    // What became of the last message given to send(): see lastOutcome().
    public const OUTCOME_SENT       = 'sent';
    public const OUTCOME_QUEUED     = 'queued';
    public const OUTCOME_HELD       = 'held';
    public const OUTCOME_NOT_SET_UP = 'not-set-up';
    public const OUTCOME_REFUSED    = 'refused';
    public const OUTCOME_NOT_SENT   = 'not-sent';

    /** @var string One of the OUTCOME_ values above. */
    private static string $lastOutcome = self::OUTCOME_NOT_SENT;

    /** @var int The number of the mail queue row the last message was kept in; 0 when it was not kept. */
    private static int $lastQueueId = 0;

    /** @var string Why the last message could not be kept, when the fault was the message's own; '' otherwise. See keep(). */
    private static string $keepRefusal = '';

    /**
     * ⏱️ One request's own budget for sending: this many messages, or this
     * many seconds counted from its first, whichever comes first. Every
     * message after that is kept for the job. (The count applies only while
     * the scheduled job is running: see requestMessages(). The seconds
     * always apply.)
     *
     * WHY. A page that sends to many people one after another (a broadcast,
     * a reminder run) used to keep going until the web server ended it: on a
     * slow mail server a broadcast to five people was cut off at 30 seconds
     * with three sent, two logged and nothing recorded (#592).
     *
     * WHAT IT CANNOT DO: end a send that has already started. A message
     * begun just inside the 20 seconds can itself take up to 27 more. A page
     * that must lose nothing even then keeps EVERY message first and lets
     * the queue send them (`sendNow` false), as the event broadcast does.
     */
    public const REQUEST_MESSAGES = 25;
    public const REQUEST_SECONDS  = 20.0;

    /** @var int How many messages this request has given to send(). The fourth and later are bulk unless their caller says otherwise. */
    private static int $givenThisRequest = 0;

    /** @var int How many messages this request has tried to send now. */
    private static int $triedThisRequest = 0;

    /** @var int How many messages this request has KEPT in the mail queue: see keptThisRequest(). */
    private static int $keptThisRequest = 0;

    /** @var int|null When this request first tried to send, on PHP's steady clock (nanoseconds); null before that. */
    private static ?int $firstTriedAt = null;

    /**
     * 🧭 Which route send() will use: ROUTE_GOOGLE, ROUTE_SMTP or ROUTE_MS365.
     *
     * `mail.provider = 'google'` means Google and `'smtp'` means SMTP. Every
     * other value, including an empty one, means Microsoft 365, because that
     * is what send() has always done with it.
     *
     * UNTIL 12 OCTOBER 2026 'smtp' meant Microsoft 365 as well: there was no
     * SMTP route, although some screens offered one. An installation that had
     * 'smtp' stored and was really sending through Microsoft 365 now needs
     * `mail.provider` set to 'ms365'; the e-mail page shows which route is in
     * use and what it still needs. (`mail.provider` is seeded as 'ms365', and
     * no page of the portal ever wrote 'smtp' into it.)
     *
     * The CHOICE is the installation's own `mail.provider` row, read through
     * MailRoutes::chosenRoute(). It used to be read from `$SETTINGS`, where
     * one organisation's row lies over the installation's: the e-mail page,
     * "Use this route" and send() could then each describe a different route.
     * The settings the Microsoft 365 and Google routes need are still read
     * from `$SETTINGS`, as they always were.
     */
    private static function route(): string
    {
        $chosen = MailRoutes::chosenRoute();
        if ($chosen === self::ROUTE_GOOGLE) {
            return self::ROUTE_GOOGLE;
        }

        return $chosen === self::ROUTE_SMTP ? self::ROUTE_SMTP : self::ROUTE_MS365;
    }

    /**
     * ✅ Is a mail route set up that can send? (#580)
     *
     * THE ONE PLACE this question is answered. A page that needs to know
     * whether an e-mail can go out asks here, and never tests a provider's
     * settings itself.
     *
     * WHY IT EXISTS. "Forgot password" used to make its own test: a Microsoft
     * client ID and a "from" address. An organisation that sent through Google
     * has neither, so the page told people a reset link had been sent and sent
     * nothing, although the Google route worked.
     *
     * ADDING A ROUTE (SMTP, a queue, anything else) IS DONE HERE: teach
     * route() to choose it, send() to hand the message to it, and
     * sendingRoute() what it needs before it can send. No page has to change,
     * and none should: do not add a second test anywhere.
     *
     * WHAT THIS CANNOT TELL YOU. It reads settings and looks for one file. It
     * does not contact the provider, so "yes" means "everything send() insists
     * on is filled in", NOT "the provider will accept the message". A wrong
     * secret, a mailbox the application may not send as, or a provider that is
     * down all still answer "yes" here. Only a real send proves delivery:
     * Admin, Integrations, Email has a "Send test" button for that.
     *
     * @return bool True when the chosen route has everything send() needs.
     */
    public static function canSend(): bool
    {
        return self::sendingRoute()['missing'] === [];
    }

    /**
     * 🧾 The route send() will use, and what it still needs before it can send.
     *
     * canSend() is built on this, so the two cannot disagree. It is public
     * because the administrator's pages show the route's name and the list of
     * what is missing (Admin, Integrations, Email, and the health page).
     *
     * It describes the CHOSEN route only, because that is the only one send()
     * tries first. Settings filled in for the other route do not count: with
     * `mail.provider` left on Microsoft 365 and only Google filled in, send()
     * fails, so this says "not ready" and lists what Microsoft 365 needs.
     * (`mail.fallbackProvider` does not change that: the fallback is tried
     * only after Microsoft has refused a message, which cannot happen until
     * the Microsoft settings are filled in.)
     *
     * Each list below is exactly what the sending code refuses to work
     * without: accessToken() and effectiveSender() here for Microsoft 365,
     * MailerGoogle::send() and MailerGoogle::accessToken() for Google, and
     * MailRoutes::smtpMissing() for SMTP (which asks the mail library's own
     * check). If one of those starts needing something more, add it here too.
     *
     * ONE THING IS ASKED OF EVERY ROUTE: that the hourly limit, if one is
     * stored, is a number. See MailRoutes::limitProblem().
     *
     * The same limits as canSend(): settings and one file, nothing more.
     *
     * @return array{route: string, label: string, missing: list<string>}
     *         route:   'ms365', 'google' or 'smtp'.
     *         label:   the route's name for a person to read.
     *         missing: what is still needed, in plain words. Empty = ready.
     */
    public static function sendingRoute(): array
    {
        $found = self::routeAndItsNeeds();
        $limit = MailRoutes::limitProblem();
        if ($limit !== '') {
            $found['missing'][] = $limit;
        }

        return $found;
    }

    /**
     * What sendingRoute() answers, before the one check every route shares.
     *
     * @return array{route: string, label: string, missing: list<string>}
     */
    private static function routeAndItsNeeds(): array
    {
        global $SETTINGS;

        $missing = [];

        if (self::route() === self::ROUTE_SMTP) {
            return ['route' => self::ROUTE_SMTP, 'label' => self::ROUTES[self::ROUTE_SMTP], 'missing' => MailRoutes::smtpMissing()];
        }

        if (self::route() === self::ROUTE_GOOGLE) {
            $keyFile = trim((string) ($SETTINGS['mail']['google']['serviceAccountKeyFile'] ?? ''));
            if ($keyFile === '') {
                $missing[] = 'the name of the Google service account key file';
            } elseif (is_readable(PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . $keyFile) === false) {
                // The name is set but the file is not where MailerGoogle
                // looks for it. Without this, a half-finished set-up read
                // as ready and every send failed.
                $missing[] = 'the Google key file itself: "' . $keyFile . '" is not in the portal\'s _auth_keys folder, or cannot be read';
            }
            if (trim((string) ($SETTINGS['mail']['google']['delegateUser'] ?? '')) === '') {
                $missing[] = 'the Google address to send from (the delegate user)';
            }

            return ['route' => self::ROUTE_GOOGLE, 'label' => 'Google', 'missing' => $missing];
        }

        if (trim((string) ($SETTINGS['auth']['ms365']['tenantID'] ?? '')) === '') {
            $missing[] = 'the Microsoft 365 tenant ID';
        }
        if (trim((string) ($SETTINGS['auth']['ms365']['appwide']['clientID'] ?? '')) === '') {
            $missing[] = 'the Microsoft 365 application (client) ID';
        }
        if (trim((string) ($SETTINGS['auth']['ms365']['appwide']['clientSecret'] ?? '')) === '') {
            $missing[] = 'the Microsoft 365 client secret';
        }
        // The same rule send() uses for the sender, asked quietly: this runs on
        // the health page, which monitors load every few minutes, and must not
        // write an error record each time.
        if (self::effectiveSender(true) === null) {
            $missing[] = 'an address to send from (the "from" address, or a shared mailbox)';
        }

        return ['route' => self::ROUTE_MS365, 'label' => 'Microsoft 365', 'missing' => $missing];
    }

    /**
     * 📧 Send an email via the configured provider.
     *
     * Checks `mail.provider` setting and dispatches to the appropriate backend.
     * Defaults to MS365 (Microsoft Graph) for backward compatibility.
     *
     * @param string|array $to      Recipient (single email or array)
     * @param string       $subj    Email subject line
     * @param string       $body    Body — auto-detected as HTML if it contains `<` tags
     * @param array        $files   File paths to attach
     * @param array        $options What the caller knows about the message
     *                              (every part is optional):
     *                              `priority`   'urgent', 'normal' or 'bulk'.
     *                                           Left out, the first three
     *                                           messages of a request are
     *                                           normal and the rest bulk.
     *                              `expiresAt`  a moment in UTC, 'Y-m-d H:i:s',
     *                                           at or after which the message
     *                                           must never be sent.
     *                              `repeatSafe` true when a second copy does
     *                                           no harm (a reset link, an
     *                                           alert).
     *                              `sendNow`    false: do not try in this
     *                                           request; keep it for the job.
     *
     * @return bool True when the message was SENT, or KEPT to be sent
     *              (queued, or held). False when it was neither. lastOutcome()
     *              says which.
     */
    public static function send(string|array $to, string $subj, string $body, array $files = [], array $options = []): bool
    {
        // 🪞 Normalise $to to an array so callers can pass a single
        //    string without thinking about it.
        $recipients = is_array($to) ? $to : [$to];

        // 🪞 If the body looks like plain text (no HTML tags), wrap it in
        //    the branded base template so the recipient gets a themed
        //    email regardless of caller. Plain-text wrapper is generated
        //    automatically by sendTemplated()'s flow when used; callers
        //    using send() directly get the auto-wrap behaviour.
        $looksLikeHtml = (bool) preg_match('/<[a-zA-Z][^>]*>/', $body);
        $htmlBody = $looksLikeHtml === true
            ? $body
            : self::renderBase($body);

        // 🪞 Plain-text alternative (#254). Auto-derive from the HTML if the
        //    caller didn't supply one. This goes into the providers'
        //    multipart/alternative bodies so clients that prefer (or only
        //    accept) plain text get a readable version.
        $plainBody = $looksLikeHtml === true
            ? self::htmlToPlain($body)
            : $body;

        // 🧾 What lastOutcome() says if nothing below changes it. A route
        //    that is not set up may still throw further down, as the Microsoft
        //    365 route always has; the caller who catches that can still ask.
        $route              = self::route();
        $setUp              = self::canSend();
        self::$lastOutcome  = $setUp === true ? self::OUTCOME_NOT_SENT : self::OUTCOME_NOT_SET_UP;
        self::$lastQueueId  = 0;
        $wanted             = MailQueue::wanted($options, ++self::$givenThisRequest);
        $urgent             = $wanted['priority'] <= MailQueue::URGENT;
        // Everything the queue needs to keep this message, should it come to that.
        $message = [
            'siteId' => Site::id() > 0 ? Site::id() : null, 'priority' => $wanted['priority'], 'to' => array_map('strval', array_values($recipients)),
            'subject' => $subj, 'html' => $htmlBody, 'text' => $plainBody, 'files' => $files,
            'expiresAt' => $wanted['expiresAt'], 'repeatSafe' => $wanted['repeatSafe'], 'canBeKept' => $wanted['canBeKept'], 'origin' => $wanted['origin'],
        ];

        if ($setUp === true) {
            // 🚫 FOR MORE PEOPLE THAN A WHOLE HOUR ALLOWS: refused at once.
            //    It used to be kept "until the hour has room", which it never
            //    could have: it sat for two days and was then failed as "too
            //    old", and its sender had been answered "true". Asked before
            //    anything else, so that it is never kept by any road below.
            $never = MailRoutes::neverFits(count($recipients), $urgent);
            if ($never !== '') {
                self::$lastOutcome = self::OUTCOME_REFUSED;
                self::logSend($route, '', implode(', ', $recipients), $subj, 0, 'failed', null, MailRoutes::OVER_LIMIT, $never);

                return false;
            }

            // 🚧 REASONS NOT TO TRY NOW, in the order they are asked. Each
            //    keeps the message in the queue and answers true. `mayTry`
            //    says what happens when the queue cannot take it: a message
            //    that was only being kept for convenience is tried now after
            //    all; one that MUST not go now is not sent, and says so.
            $why = null;
            if ($wanted['sendNow'] === false) {
                $why = ['status' => 'queued', 'code' => 'asked-to-wait', 'mayTry' => true,
                    'words' => 'Kept for the mail job to send: the page that wrote it asked for it not to be sent inside the request.'];
            } elseif ($route === self::ROUTE_SMTP) {
                // A fault with the route that is already on record: the mail
                // server is NOT contacted. See MailRoutes, "what is wrong
                // with the route".
                $standing = MailRoutes::standingProblem(App::db());
                if ($standing['problem'] === MailRoutes::PROBLEM_CERTIFICATE) {
                    $why = ['status' => 'held', 'holdReason' => MailQueue::HELD_CERTIFICATE, 'code' => 'certificate', 'mayTry' => false, 'stage' => 'TLS',
                        'words' => 'Held, and the mail server was not contacted: its certificate failed the check. ' . $standing['words']];
                } elseif ($standing['problem'] === MailRoutes::PROBLEM_SIGN_IN
                    && ($standing['tryNow'] === false || MailQueue::jobIsRunning(App::db()) === true
                        || MailRoutes::claimSignInTry(App::db()) === false)) {
                    // 🔐 A refused sign-in is tried again only after a wait,
                    //    and it is the JOB that tries. But with no job
                    //    running, nothing ever tried again: one refused
                    //    sign-in stopped all mail from every page for good.
                    //    So once the wait is over and no job is running, ONE
                    //    request is not kept here: it goes on below and is
                    //    tried now. ONE, because the request must first
                    //    CLAIM that try (MailRoutes::claimSignInTry(), one
                    //    statement that only one request can win). Without
                    //    the claim, twelve pages sending at the same moment
                    //    each signed in, which is what gets a mailbox
                    //    locked; an earlier note here said a refused
                    //    password was "not offered again and again", and for
                    //    that case it was untrue. If the mail server refuses
                    //    again the wait grows, exactly as it does for the
                    //    job (MailRoutes::noteResult()).
                    //    The three tests are asked in that order on purpose:
                    //    the claim CHANGES the record, so it is made only
                    //    when this request would otherwise try.
                    $why = ['status' => 'queued', 'code' => MailRoutes::SIGN_IN_NOT_REPEATED, 'mayTry' => false, 'stage' => 'AUTH',
                        'words' => 'Waiting, and the mail server was not contacted: it has refused the mailbox\'s user name and password, and offering a refused '
                            . 'password again and again can get a mailbox locked. Check both, and type the password in again at Admin, Integrations, Email.'];
                }
            }
            if ($why === null && (self::$triedThisRequest >= self::requestMessages()
                || (self::$firstTriedAt !== null && (hrtime(true) - self::$firstTriedAt) / 1e9 >= self::REQUEST_SECONDS))) {
                $why = ['status' => 'queued', 'code' => 'request-budget', 'mayTry' => true,
                    'words' => MailQueue::jobIsRunning(App::db()) === true
                        ? 'Kept for the mail job to send: this request had already sent for ' . self::REQUEST_MESSAGES . ' messages or ' . (int) self::REQUEST_SECONDS . ' seconds.'
                        : 'Kept: this request had already been sending for ' . (int) self::REQUEST_SECONDS . ' seconds. It will NOT go until the mail queue\'s scheduled job is running, which it is not.'];
            }
            if ($why === null) {
                // ⏳ The hourly limit, for every route (#592). Empty, which
                //    is how it is seeded, means the portal applies none.
                $over = MailRoutes::overLimit(count($recipients), $urgent);
                if ($over !== '') {
                    $why = ['status' => 'queued', 'code' => MailRoutes::OVER_LIMIT, 'mayTry' => false,
                        'words' => 'Waiting in the mail queue until the hour has room. ' . $over];
                }
            }
            if ($why !== null) {
                if (self::keep($route, $message, $why) === true) {
                    return true;
                }
                if ($why['mayTry'] === false) {
                    // Not sent, and not kept. When the fault is the message's
                    // own (a file that cannot be attached) the log says that,
                    // and not the reason it was not tried.
                    $refused = self::$lastOutcome === self::OUTCOME_REFUSED;
                    self::logSend(
                        $route, '', implode(', ', $recipients), $subj, 0, 'failed', null,
                        $refused === true ? 'message-refused' : $why['code'],
                        $refused === true ? self::$keepRefusal : self::notKept($why['words'])
                    );

                    return false;
                }
                // Not kept, and allowed to go now: carry on and try it.
            }
        } else {
            // 🚫 Not set up: nothing is kept, because nobody can say when,
            //    or through what, it could ever go. One case still has to be
            //    stopped here: an hourly limit that is stored and is not a
            //    number makes the route "not set up" although it could send,
            //    and sending would make the limit a promise quietly not kept.
            $over = MailRoutes::overLimit(count($recipients), $urgent);
            if ($over !== '') {
                self::logSend($route, '', implode(', ', $recipients), $subj, 0, 'failed', null, MailRoutes::OVER_LIMIT, $over);

                return false;
            }
        }

        // 🔀 Try it now. route() is the one place that reads the choice, so
        //    that sendingRoute() and canSend() above can never describe a
        //    different route from the one used here.
        if ($setUp === true) {
            self::$triedThisRequest++;
            self::$firstTriedAt ??= hrtime(true);
        }
        if ($route === self::ROUTE_SMTP) {
            // 🚫 This route never throws. With nothing filled in it answers
            //    false and logs "not set up". (The Microsoft 365 route throws
            //    there, which ends a page that sends without a try/catch, such
            //    as an event broadcast, in an error.)
            $answer = MailRoutes::sendSmtp($message['to'], $subj, $plainBody, $htmlBody, $files, ['repeatSafe' => $wanted['repeatSafe']]);

            return self::afterSmtp($answer, $message);
        }

        $sent = $route === self::ROUTE_GOOGLE
            ? MailerGoogle::send($recipients, $subj, $htmlBody, $files)
            // 📧 Default: Microsoft Graph (MS365)
            : self::sendViaGraph($recipients, $subj, $htmlBody, $files);
        self::$lastOutcome = $sent === true ? self::OUTCOME_SENT : self::OUTCOME_NOT_SENT;

        return $sent;
    }

    /**
     * 🗂️ What to do with a message after one try through SMTP in a request:
     * record it, and keep it in the queue when it should be tried again or
     * looked at by a person.
     *
     * @param array<string, mixed> $answer  What MailRoutes::sendSmtp() returned.
     * @param array<string, mixed> $message What send() built for the queue.
     *
     * @return bool What send() answers.
     */
    private static function afterSmtp(array $answer, array $message): bool
    {
        $to   = $message['to'];
        $next = (string) $answer['next'];

        if ($next === MailRoutes::NEXT_SENT) {
            self::$lastOutcome = self::OUTCOME_SENT;
            self::logSmtp($answer, $to, $message['subject'], 'sent');

            return true;
        }
        if ($next === MailRoutes::NEXT_NOT_SET_UP || $next === MailRoutes::NEXT_REFUSED) {
            // Nothing to keep: a setting is missing, or the mail server said
            // no to THIS message and would say it again.
            self::$lastOutcome = $next === MailRoutes::NEXT_REFUSED ? self::OUTCOME_REFUSED : self::OUTCOME_NOT_SET_UP;
            self::logSmtp($answer, $to, $message['subject'], 'failed');

            return false;
        }

        // Kept. As what, and with what first wait, depends on what happened.
        $why = ['code' => (string) $answer['reason'], 'words' => (string) $answer['words'], 'stage' => (string) $answer['stage'], 'messageId' => (string) $answer['messageId'], 'logged' => true];
        if ($next === MailRoutes::NEXT_HOLD_CERTIFICATE) {
            $why += ['status' => 'held', 'holdReason' => MailQueue::HELD_CERTIFICATE];
        } elseif ($next === MailRoutes::NEXT_HOLD_UNKNOWN) {
            // Counted as an attempt: the whole message was handed over.
            $why += ['status' => 'held', 'holdReason' => MailQueue::HELD_UNKNOWN, 'attempts' => 1];
        } elseif ($next === MailRoutes::NEXT_SIGN_IN) {
            $why += ['status' => 'queued']; // about the mailbox: not counted against the message
        } else {
            $why += ['status' => 'queued', 'attempts' => 1, 'waitSeconds' => MailQueue::waitAfter(1, (int) $message['priority']) ?? 0];
        }
        $kept = self::keep(self::ROUTE_SMTP, $message, $why);
        // The attempt is in the send log either way, as what the message now
        // is: 'queued' or 'held' when it was kept, 'failed' when it was not.
        if ($kept === false) {
            $answer['words'] = self::notKept((string) $answer['words']);
        }
        self::logSmtp($answer, $to, $message['subject'], $kept === true ? $why['status'] : 'failed');

        return $kept;
    }

    /**
     * 📥 Keep one message in the mail queue, and say so in the send log.
     *
     * @param array<string, mixed> $message What send() built.
     * @param array<string, mixed> $why     `status` ('queued' or 'held'), `code` and
     *        `words` for the send log, and optionally `holdReason`, `stage`,
     *        `messageId`, `attempts`, `waitSeconds`. `logged` true means the
     *        caller writes the send-log line itself.
     *
     * @return bool False when it could not be kept. lastOutcome() is then
     *              'refused' when the message itself is at fault (a file it
     *              should carry cannot be attached), and otherwise
     *              'not-sent', with the reason in the server's error log
     *              (never the message, its subject or an address).
     */
    private static function keep(string $route, array $message, array $why): bool
    {
        if ($message['canBeKept'] === false) {
            error_log('Mailer: a message was not kept in the mail queue because the use-by moment its caller gave could not be read.');
            self::$lastOutcome = self::OUTCOME_NOT_SENT;

            return false;
        }
        self::$keepRefusal = '';
        try {
            self::$lastQueueId = MailQueue::add(App::db(), $message + [
                'status' => $why['status'], 'holdReason' => $why['holdReason'] ?? '', 'lastStage' => $why['stage'] ?? '', 'lastError' => $why['words'],
                'messageId' => $why['messageId'] ?? '', 'attempts' => $why['attempts'] ?? 0, 'waitSeconds' => $why['waitSeconds'] ?? 0,
            ]);
        } catch (\UnexpectedValueException $e) {
            // The MESSAGE is at fault: a file it should carry is missing,
            // empty or too big. Keeping it could never work. The text is
            // MailQueue's own and names no file and no address.
            self::$keepRefusal = 'Not sent, and not kept: ' . $e->getMessage() . '.';
            self::$lastOutcome = self::OUTCOME_REFUSED;

            return false;
        } catch (\Throwable $e) {
            // Most often: the files have been updated and the database
            // upgrade has not been run, so the queue's tables are not there.
            error_log('Mailer: a message could not be kept in the mail queue: ' . $e->getMessage());
            self::$lastOutcome = self::OUTCOME_NOT_SENT;

            return false;
        }
        self::$lastOutcome = $why['status'] === 'held' ? self::OUTCOME_HELD : self::OUTCOME_QUEUED;
        self::$keptThisRequest++;
        if (($why['logged'] ?? false) === false) {
            self::logSend($route, '', implode(', ', $message['to']), $message['subject'], count($message['files']), $why['status'], null, $why['code'], $why['words']);
        }

        return true;
    }

    /** What the send log says of a message that should have been kept and could not be. */
    private static function notKept(string $words): string
    {
        return $words . ' It could NOT be kept to try again: the mail queue did not take it (see the server\'s error log; the database upgrade may not have been run).';
    }

    /**
     * 🔢 The mail queue row the last message given to send() was kept in, or
     * 0 when it was sent, or not kept. For a page that queues a mailing and
     * then wants to say how much of it has gone.
     */
    public static function lastQueueId(): int
    {
        return self::$lastQueueId;
    }

    /**
     * 🧾 What became of the last message given to send(), for a page that
     * wants to say more than yes or no.
     *
     *   'sent'        a mail service accepted it. Not the same as delivered.
     *   'queued'      it is KEPT and will be tried (again) by the mail job:
     *                 the mail server could not be reached or said "later",
     *                 the hourly limit or the request's own budget was
     *                 reached, the mail server has refused the mailbox's
     *                 sign-in, or the caller asked for it to wait.
     *   'held'        it is KEPT and waits for a person, or for the mail
     *                 server's certificate to be put right: the certificate
     *                 failed its check, or the whole message was handed over
     *                 and no answer came, so nobody can tell whether it went.
     *   'not-set-up'  the chosen route is missing something; nothing was tried.
     *   'refused'     SMTP only: the mail server, or the mail library, said no
     *                 to THIS message in a way that trying again would not
     *                 change (a recipient's address, its content, its size).
     *                 It is not kept.
     *   'not-sent'    it did not go and is NOT kept: Microsoft 365 or Google
     *                 said no, or the message should have been kept and the
     *                 mail queue could not take it.
     *
     * send() answers true for the first three and false for the rest.
     *
     * It describes the LAST call in this request only.
     */
    public static function lastOutcome(): string
    {
        return self::$lastOutcome;
    }

    /**
     * ⏳ Was the last message given to send() only KEPT (queued, or held),
     * and not sent? lastOutcome() in one word, for a caller that counts or
     * records: send() answers true for a kept message, and "true" must not
     * be written down as "sent".
     */
    public static function lastIsWaiting(): bool
    {
        return self::$lastOutcome === self::OUTCOME_QUEUED || self::$lastOutcome === self::OUTCOME_HELD;
    }

    /**
     * 🔢 How many messages this request has kept in the mail queue so far.
     * For a job that sends many (the reminder jobs) and reports its numbers
     * at the end: "sent 3" beside "waiting 2", not "sent 5".
     */
    public static function keptThisRequest(): int
    {
        return self::$keptThisRequest;
    }

    /**
     * 🔢 How many messages one request may try to send before the rest are
     * kept: REQUEST_MESSAGES while the scheduled job is running, and no
     * limit of this kind while it is not.
     *
     * WHY. The 25 exists so that a mailing does not hold a request for
     * long: the job sends the rest. With no job running, "the rest" was
     * never sent at all: a broadcast to 60 reached 25 people and the other
     * 35 waited for ever. The time budget (REQUEST_SECONDS) stays either
     * way, because that is what stops the host ending the request half-way;
     * what does not fit in it is kept, and its line in the send log says
     * that it will not go until the job is running (the event broadcast
     * page says so on screen too).
     */
    public static function requestMessages(): int
    {
        return MailQueue::jobIsRunning(App::db()) === true ? self::REQUEST_MESSAGES : PHP_INT_MAX;
    }

    /**
     * 📮 Send through the SMTP route NOW and record the attempt as 'sent' or
     * 'failed'. Nothing is kept. Used by "Send test" only: send() goes
     * through afterSmtp(), which can keep a message.
     *
     * @param list<string> $to
     * @param list<string> $files
     *
     * @return array<string, mixed> See MailRoutes::sendSmtp().
     */
    private static function sendViaSmtp(array $to, string $subj, string $html, string $plain, array $files): array
    {
        $answer            = MailRoutes::sendSmtp($to, $subj, $plain, $html, $files);
        self::$lastOutcome = $answer['outcome'];
        self::logSmtp($answer, $to, $subj, $answer['sent'] === true ? 'sent' : 'failed');

        return $answer;
    }

    /**
     * 📝 One attempt through SMTP, in the send log, as what the message now
     * is ('sent', 'queued', 'held' or 'failed'), with a line on the Errors
     * page when an administrator should see it.
     *
     * MailRoutes does the sending and never throws; this adds what Mailer
     * does for every route. "Not set up" gets no line on the Errors page: it
     * is a state, the health page reports it, and a broadcast to 200 people
     * would otherwise write 200 of them.
     *
     * `words` never holds the mailbox's password: the mail library takes it
     * out of everything it returns, and MailRoutes adds none.
     *
     * @param array<string, mixed> $answer   What MailRoutes::sendSmtp() returned.
     * @param list<string>         $to
     * @param bool                 $errorRow False to leave the Errors page
     *        alone: the mail job passes it for a message that is simply
     *        waiting for its next try, or a mail server that is down would
     *        fill that page with one line for every run.
     * @param int|false|null       $siteId   The organisation the message is
     *        for, when that is not this request's (the mail job). False: use
     *        this request's.
     */
    private static function logSmtp(array $answer, array $to, string $subj, string $status, bool $errorRow = true, int|false|null $siteId = false): void
    {
        $sent = $status === 'sent';
        self::logSend(
            self::ROUTE_SMTP, (string) $answer['from'], implode(', ', $to), $subj, (int) $answer['attachments'], $status, $answer['code'],
            $sent === true ? '' : (string) $answer['reason'], $sent === true ? '' : (string) $answer['words'], $siteId
        );
        if ($sent === false && $errorRow === true && $answer['next'] !== MailRoutes::NEXT_NOT_SET_UP) {
            // These words can name a recipient's address (a mail server's
            // refusal quotes it). An erasure empties such lines by the name
            // 'SMTP' given here: GdprEraser::MAIL_ERROR_PLATFORMS. If this
            // name changes, or a new route writes its own, change that list.
            Logger::errorPlatform('SMTP', 'Error', (string) $answer['reason'], 'Mail send fail', (string) $answer['words']);
        }
    }

    // =========================================================================
    // 📬 For the mail queue (MailQueue.php). Not for pages.
    // =========================================================================

    /**
     * Hand ONE message from the queue to the chosen mail route, now. No
     * limit and no budget is applied here: the queue has already asked.
     * Nothing is written to the send log here either: the queue decides what
     * becomes of the row first, and then calls logQueueAttempt().
     *
     * NEVER NEEDS THE REQUEST'S ADDRESS. The message was written, link and
     * all, when it was queued; the job that calls this has no visitor.
     *
     * @param array<string, mixed> $row   The queue row (see MailQueue::claim()).
     * @param list<string>         $files Paths of the stored copies to attach.
     *
     * @return array<string, mixed> As MailRoutes::sendSmtp() returns. For the
     *         Microsoft 365 and Google routes, which answer only yes or no,
     *         `next` is 'sent' or 'later', and those routes write their own
     *         line in the send log.
     */
    public static function deliverQueued(array $row, string $html, string $text, array $files): array
    {
        $to     = array_values(array_filter(array_map('trim', explode(',', (string) $row['toRecipients'])), static fn (string $a): bool => $a !== ''));
        $route  = self::route();
        $answer = [
            'sent' => false, 'outcome' => self::OUTCOME_NOT_SENT, 'reason' => 'provider-said-no', 'code' => null, 'words' => '', 'from' => '',
            'attachments' => count($files), 'steps' => [], 'certificate' => false, 'next' => MailRoutes::NEXT_LATER, 'stage' => '',
            'messageId' => (string) $row['messageID'], 'routeFault' => false, 'ownLog' => true,
        ];
        if ($to === []) {
            // Everybody it was for has been taken off it (an erasure).
            return ['next' => MailRoutes::NEXT_REFUSED, 'reason' => 'nobody-to-send-to', 'words' => 'Not sent: nobody is left for it to go to.', 'ownLog' => false] + $answer;
        }
        if ($route === self::ROUTE_SMTP) {
            return MailRoutes::sendSmtp($to, (string) $row['subject'], $text, $html, $files, [
                'messageId' => (string) $row['messageID'], 'date' => (int) $row['createdEpoch'], 'repeatSafe' => (int) $row['repeatSafe'] === 1,
            ]) + ['ownLog' => false];
        }
        try {
            $sent = $route === self::ROUTE_GOOGLE
                ? MailerGoogle::send($to, (string) $row['subject'], $html, $files)
                : self::sendViaGraph($to, (string) $row['subject'], $html, $files);
        } catch (\Throwable $e) {
            // These routes throw when a setting is missing. The exception's
            // text names the setting, never the message.
            $sent                 = false;
            $answer['ownLog']     = false;
            $answer['routeFault'] = true;
            $answer['words']      = 'Not sent: ' . self::ROUTES[$route] . ' could not be used. ' . $e->getMessage();
        }
        if ($sent === true) {
            return ['sent' => true, 'outcome' => self::OUTCOME_SENT, 'reason' => '', 'next' => MailRoutes::NEXT_SENT, 'words' => 'The mail service accepted the message.'] + $answer;
        }
        if ($answer['words'] === '') {
            $answer['words'] = 'Not sent: ' . self::ROUTES[$route] . ' did not accept the message. Its own line in the send log says why.';
        }

        return $answer;
    }

    /**
     * 📝 The send-log line for one attempt the mail job made, written once
     * the queue has decided what the row now is.
     *
     * @param array<string, mixed> $row    The queue row.
     * @param array<string, mixed> $answer What deliverQueued() returned.
     * @param string               $status 'sent', 'queued', 'held' or 'failed'.
     */
    public static function logQueueAttempt(array $row, array $answer, string $status): void
    {
        if (($answer['ownLog'] ?? false) === true) {
            return; // Microsoft 365 and Google wrote their own line
        }
        $to     = array_map('trim', explode(',', (string) $row['toRecipients']));
        $siteId = $row['siteID'] === null ? null : (int) $row['siteID'];
        if (self::route() === self::ROUTE_SMTP && isset($answer['from']) === true && $answer['from'] !== '') {
            self::logSmtp($answer, $to, (string) $row['subject'], $status, $status === 'failed' || $status === 'held', $siteId);

            return;
        }
        self::logSend(self::route(), '', (string) $row['toRecipients'], (string) $row['subject'], (int) $row['attachmentCount'], $status, null, (string) $answer['reason'], (string) $answer['words'], $siteId);
    }

    /**
     * 📝 The send-log line for something that happened to a waiting message
     * WITHOUT an attempt: its use-by moment passed, it grew too old, it was
     * held too long, a run died while handing it over, or it cannot be read.
     * So that nothing leaves the queue unsent without a line saying why.
     *
     * @param array<string, mixed> $row    The queue row (never its body).
     * @param string               $status 'expired', 'failed' or 'held'.
     * @param string               $code   A short word for the reason.
     * @param string               $words  The reason, in plain words.
     */
    public static function logQueueEvent(array $row, string $status, string $code, string $words): void
    {
        self::logSend(
            self::route(), '', (string) $row['toRecipients'], (string) $row['subject'], (int) ($row['attachmentCount'] ?? 0), $status, null, $code, $words,
            $row['siteID'] === null ? null : (int) $row['siteID']
        );
    }

    /**
     * 🧪 "Send test" for the SMTP settings, WHATEVER route is in use.
     *
     * So an administrator can find out whether a mailbox works before
     * switching the portal over to it. The e-mail page shows what comes back:
     * how far the conversation with the mail server got, and its last line.
     *
     * It is recorded in the send log like any other message, and counts
     * toward the hourly limit, but the limit does not stop it: one message,
     * sent by an administrator to find out why mail is not going.
     *
     * The caller has checked that the person is a global administrator.
     *
     * It is never kept in the mail queue, and it always contacts the mail
     * server, whatever fault is on record for the route: it is how an
     * administrator finds out whether the fault is over. A test that is
     * accepted clears the record, and mail that was waiting then goes.
     *
     * @return array<string, mixed> See MailRoutes::sendSmtp().
     */
    public static function testSmtp(string $to, string $subject, string $text): array
    {
        return self::sendViaSmtp([$to], $subject, self::renderBase($text), $text, []);
    }

    /**
     * 📨 Send a templated email (#243).
     *
     * Renders the named template under web/_core/templates/email/ with the
     * given vars (escaped via htmlspecialchars), wraps in the base layout,
     * and dispatches via send().
     *
     * @param string|array         $to        Recipient(s)
     * @param string               $subject   Subject line
     * @param string               $template  Template basename — looked up at
     *                                        web/_core/templates/email/{name}.html.php
     * @param array<string, mixed> $vars      Template variables
     * @param array                $files     Attachments
     * @param array                $options   As send()'s fifth argument.
     */
    public static function sendTemplated(
        string|array $to,
        string $subject,
        string $template,
        array $vars = [],
        array $files = [],
        array $options = []
    ): bool {
        $rendered = self::renderTemplate($template, $vars);
        if ($rendered === null) {
            // 🪞 Template missing — fall back to a plain text mention of the
            //    template name so the alert still reaches the recipient.
            $rendered = sprintf(
                "(Template '%s' missing — please contact the portal admin.)",
                $template
            );
        }
        return self::send($to, $subject, self::renderBase($rendered), $files, $options);
    }

    /**
     * Render the named partial template under templates/email/{name}.html.php.
     *
     * @return string|null Rendered HTML, or null if template not found.
     */
    private static function renderTemplate(string $template, array $vars): ?string
    {
        if (preg_match('/^[A-Za-z0-9_\-]+$/', $template) !== 1) {
            return null;
        }
        $path = PORTAL_CORE
              . DIRECTORY_SEPARATOR . 'templates'
              . DIRECTORY_SEPARATOR . 'email'
              . DIRECTORY_SEPARATOR . $template . '.html.php';
        if (is_readable($path) === false) {
            return null;
        }
        // 🪞 Sandbox the template — vars are extracted into local scope.
        $render = static function (string $__path, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            include $__path;
            return (string) ob_get_clean();
        };
        try {
            return $render($path, $vars);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Email', 'Error', 'TPL_RENDER', $template, $e->getMessage());
            return null;
        }
    }

    /**
     * Wrap the given content HTML in the branded base layout.
     */
    private static function renderBase(string $content): string
    {
        global $SETTINGS;
        $base = PORTAL_CORE
              . DIRECTORY_SEPARATOR . 'templates'
              . DIRECTORY_SEPARATOR . 'email'
              . DIRECTORY_SEPARATOR . 'base.html.php';
        if (is_readable($base) === false) {
            // 🪞 Base template missing — return the content as-is wrapped in
            //    a minimal HTML shell so the email still renders.
            return '<!doctype html><html><body style="font-family:system-ui">'
                 . (preg_match('/<[a-zA-Z][^>]*>/', $content) === 1
                    ? $content
                    : '<pre style="white-space:pre-wrap">' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>')
                 . '</body></html>';
        }
        $portalName    = (string) ($SETTINGS['site']['name'] ?? 'WebMS Intra');
        // 🔗 The footer's "Visit the portal" link. PortalAddress is the one
        //    place that decides the address in a link the portal sends out
        //    (#500). This used to read the `site.url` setting itself; nothing
        //    seeded that setting, so the link was never shown. When there is
        //    no address to give this is '', and the template leaves the link
        //    out.
        $portalUrl     = PortalAddress::absolute('');
        $supportEmail  = (string) ($SETTINGS['portal']['support']['email'] ?? '');
        // 🪞 Auto-wrap plain text in <p> tags so paragraphs render.
        $contentHtml = preg_match('/<[a-zA-Z][^>]*>/', $content) === 1
            ? $content
            : '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';

        $render = static function (
            string $__path,
            string $__content,
            string $__portalName,
            string $__portalUrl,
            string $__supportEmail
        ): string {
            $content      = $__content;
            $portalName   = $__portalName;
            $portalUrl    = $__portalUrl;
            $supportEmail = $__supportEmail;
            ob_start();
            include $__path;
            return (string) ob_get_clean();
        };
        try {
            return $render($base, $contentHtml, $portalName, $portalUrl, $supportEmail);
        } catch (\Throwable $e) {
            Logger::errorPlatform('Email', 'Error', 'BASE_RENDER', 'base', $e->getMessage());
            return $contentHtml;
        }
    }

    /**
     * 🔍 Resolve the effective MS365 send identity (#234).
     *
     * Resolution order:
     *   1. `mail.ms365.sharedMailbox` — if non-empty AND a valid email
     *      address, this IS both the URL mailbox and the `from` address.
     *      Display name: `mail.ms365.sharedMailboxName`, falling back to
     *      `mail.defaultFromName`.
     *   2. Non-empty but INVALID (hand-edited via the generic settings
     *      editor) — log once and fall through to rule 3 so mail keeps
     *      flowing rather than dying on a typo.
     *   3. Empty (seeded default) — `mail.defaultFromAddress` exactly as
     *      before. Returns null when that is also empty (caller throws,
     *      unchanged behaviour).
     *
     * The shared mailbox is ADMIN-CONFIG ONLY — it is read exclusively
     * from `$SETTINGS` (tblSettings, written only by the CSRF'd admin
     * save handler at admin/integrations/ms365-mail-save.php). Nothing on
     * this path ever reads `$_POST`/`$_GET` — a caller cannot pick an
     * arbitrary mailbox to send as.
     *
     * @param bool $quiet True when only asking WHETHER there is a sender
     *                    (sendingRoute() does): rule 2 then writes no error
     *                    record. A real send leaves it false.
     *
     * @return array{mailbox: string, address: string, name: string, isShared: bool}|null
     */
    private static function effectiveSender(bool $quiet = false): ?array
    {
        global $SETTINGS;

        $shared = trim((string) ($SETTINGS['mail']['ms365']['sharedMailbox'] ?? ''));
        if ($shared !== '') {
            if (filter_var($shared, FILTER_VALIDATE_EMAIL) !== false) {
                $sharedName   = trim((string) ($SETTINGS['mail']['ms365']['sharedMailboxName'] ?? ''));
                $fallbackName = trim((string) ($SETTINGS['mail']['defaultFromName'] ?? ''));
                return [
                    'mailbox'  => $shared,
                    'address'  => $shared,
                    'name'     => $sharedName !== '' ? $sharedName : $fallbackName,
                    'isShared' => true,
                ];
            }
            // 🩹 Hand-edited to something invalid — never a hard failure,
            //    fall back to the legacy address so portal mail keeps
            //    flowing while an admin fixes the setting.
            if ($quiet === false) {
                Logger::errorPlatform(
                    'Graph',
                    'Error',
                    'BAD_SHARED_MAILBOX',
                    'mail.ms365.sharedMailbox is not a valid email address — falling back to mail.defaultFromAddress',
                    $shared
                );
            }
        }

        $fromAddr = trim((string) ($SETTINGS['mail']['defaultFromAddress'] ?? ''));
        if ($fromAddr === '') {
            return null;
        }
        return [
            'mailbox'  => $fromAddr,
            'address'  => $fromAddr,
            'name'     => trim((string) ($SETTINGS['mail']['defaultFromName'] ?? '')),
            'isShared' => false,
        ];
    }

    /**
     * 📧 Send an email via Microsoft Graph.
     *
     * @param array  $to    Recipient email addresses
     * @param string $subj  Subject
     * @param string $html  HTML body
     * @param array  $files File paths to attach
     *
     * @return bool True if sent successfully
     */
    private static function sendViaGraph(array $to, string $subj, string $html, array $files = []): bool
    {
        global $SETTINGS;

        $sender = self::effectiveSender();
        if ($sender === null) {
            throw new RuntimeException('From address missing');
        }

        $attachments     = self::attach($files);
        $attachmentCount = count($attachments);
        $toRecipients    = implode(', ', $to);
        $providerLabel   = $sender['isShared'] === true ? 'ms365-shared' : 'ms365';

        // 🪪 #234 — explicit `from` identity in BOTH modes (previously
        //    omitted entirely, so `mail.defaultFromName` never took effect
        //    on the MS365 path — Graph derived the sender purely from the
        //    URL mailbox). `sender` is intentionally never set — that is
        //    the delegated send-on-behalf field for the deferred
        //    Mail.Send.Shared model, not app-only send-as.
        $fromEmailAddress = ['address' => $sender['address']];
        if ($sender['name'] !== '') {
            $fromEmailAddress['name'] = $sender['name'];
        }

        $payload = [
            'message' => [
                'subject'      => $subj,
                'body'         => ['contentType' => 'HTML', 'content' => $html],
                'from'         => ['emailAddress' => $fromEmailAddress],
                'toRecipients' => array_map(
                    fn($e) => ['emailAddress' => ['address' => $e]],
                    $to
                ),
                'attachments'  => $attachments,
            ],
        ];

        if ($sender['isShared'] === true) {
            // 📥 Only meaningful — and only requested — when actually
            //    sending through the shared mailbox's own submission URL;
            //    that's what makes the copy land in ITS Sent Items.
            $payload['saveToSentItems'] = (($SETTINGS['mail']['ms365']['saveToSentItems'] ?? 'true') === 'true');
        }

        $url = 'https://graph.microsoft.com/v1.0/users/' . urlencode($sender['mailbox']) . '/sendMail';

        $result = self::graphSendRequest($url, $payload);

        // 🔁 401 — token expired/revoked mid-cache-window: clear the
        //    static cache and retry ONCE with a freshly acquired token.
        if ($result['code'] === 401) {
            self::$token = '';
            $result = self::graphSendRequest($url, $payload);
        }

        // 🔁 429 — throttled. Only retry when Graph's own Retry-After is
        //    short enough to sleep through inside a web request on shared
        //    hosting (≤5s); otherwise fail the same as any other error.
        //    Bounded to ONE retry — never loop.
        if ($result['code'] === 429) {
            $retryAfter = (int) ($result['headers']['retry-after'] ?? 0);
            if ($retryAfter > 0 && $retryAfter <= 5) {
                sleep($retryAfter);
                $result = self::graphSendRequest($url, $payload);
            }
        }

        if ($result['code'] >= 200 && $result['code'] < 300) {
            self::logSend($providerLabel, $sender['address'], $toRecipients, $subj, $attachmentCount, 'sent', $result['code'], '', '');
            return true;
        }

        // 🔍 403 ErrorAccessDenied / 404 ErrorInvalidUser|ErrorItemNotFound
        //    (mailbox outside the Application Access Policy scope, or
        //    deleted/consent missing) — parse Graph's own error.code so
        //    the admin UI can show a targeted remedial hint instead of a
        //    bare HTTP code. Never logs the token/secret — body only.
        $errData    = json_decode((string) $result['body'], true);
        $errCode    = (string) ($errData['error']['code'] ?? '');
        $errMessage = (string) ($errData['error']['message'] ?? (string) $result['body']);

        Logger::errorPlatform('Graph', 'Error', (string) $result['code'], 'Mail send fail', $errCode . ': ' . $errMessage);
        self::logSend($providerLabel, $sender['address'], $toRecipients, $subj, $attachmentCount, 'failed', $result['code'] > 0 ? $result['code'] : null, $errCode, $errMessage);

        // 🪂 Optional opt-in fallback (default OFF — a silent failover
        //    changes the visible sending identity and can break DMARC
        //    alignment, so an admin must explicitly choose it).
        $fallback = strtolower(trim((string) ($SETTINGS['mail']['fallbackProvider'] ?? '')));
        if ($fallback === 'google') {
            try {
                if (MailerGoogle::isConfigured() === true) {
                    return MailerGoogle::send($to, $subj, $html, $files);
                }
            } catch (\Throwable $e) {
                Logger::errorPlatform('Graph', 'Error', 'FALLBACK_FAIL', 'Google fallback failed', $e->getMessage());
            }
        }

        return false;
    }

    /**
     * 📡 Low-level Graph sendMail POST — returns code/body/headers so the
     * caller can implement the 401/429 retry policy without duplicating
     * the cURL plumbing.
     *
     * @return array{code: int, body: string, headers: array<string, string>}
     */
    private static function graphSendRequest(string $url, array $payload): array
    {
        $token   = self::accessToken();
        $headers = [];

        $ch = curl_init($url);
        if ($ch === false) {
            Logger::errorPlatform('Graph', 'Error', 'CURL_INIT', 'curl_init() failed for sendMail', '');
            return ['code' => 0, 'body' => '', 'headers' => []];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 📥 Capture response headers (lower-cased names) so a 429's
            //    Retry-After can be read without CURLOPT_HEADER's body-
            //    prepend-and-split dance.
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($body === false) {
            return ['code' => $code, 'body' => '', 'headers' => $headers];
        }

        return ['code' => $code, 'body' => (string) $body, 'headers' => $headers];
    }

    /**
     * 📝 Record one send attempt to `tblEmailLog` (#234, closes the #230
     * audit-trail dependency). Called from BOTH providers (Graph here;
     * MailerGoogle calls this directly on its own success/failure
     * branches — see MailerGoogle::send()), so it's public.
     *
     * Fail-soft by design: a logging exception must never break mail
     * sending — the send has already happened (or definitively failed)
     * by the time this runs.
     *
     * Opportunistically prunes rows older than `mail.log.retentionDays`
     * (default 90) on roughly 1-in-50 writes, so no separate cron
     * endpoint is needed for retention.
     *
     * SINCE MIGRATION 213 (#592) each row also carries the moment in UTC
     * (`sentAtUtc`, from the database's own UTC clock) and how many addresses
     * the message was for (`recipientCount`, counted from `$toRecipients`,
     * so the callers did not have to change). The hourly limit counts on
     * those two: see MailRoutes::recipientsInTheLastHour().
     *
     * `$httpCode` is whatever number the mail service answered with: an HTTP
     * status from Microsoft 365 or Google, an SMTP reply code from a mail
     * server.
     *
     * `$status` is one of the column's five choices. Besides 'sent' and
     * 'failed' there are, since migration 216, 'queued' (kept, and will be
     * tried again), 'held' (kept, waiting for a person or for the route to be
     * put right) and 'expired' (its use-by moment passed). Only 'sent' counts
     * toward the hourly limit.
     *
     * `$forSiteId` is the organisation the message is FOR when that is not
     * the organisation of this request: the mail queue's job, which has no
     * organisation of its own, passes the queue row's. Left as false, the
     * request's organisation is used, as it always was.
     */
    public static function logSend(
        string $provider,
        string $fromAddress,
        string $toRecipients,
        string $subject,
        int $attachmentCount,
        string $status,
        ?int $httpCode,
        string $errorCode,
        string $errorDetail,
        int|false|null $forSiteId = false
    ): void {
        global $SETTINGS;

        try {
            $db     = App::db();
            $siteId = $forSiteId === false ? Site::id() : $forSiteId;

            $subjectTrunc = mb_substr($subject, 0, 500);
            $errorCodeTrunc   = mb_substr($errorCode, 0, 100);
            $errorDetailTrunc = mb_substr($errorDetail, 0, 500);

            // 👥 One address per comma. The column holds at most 65,535.
            $recipientCount = min(65535, count(array_filter(
                array_map('trim', explode(',', $toRecipients)),
                static fn (string $address): bool => $address !== ''
            )));

            // ✍️ One row, written with or without the two columns of migration 213.
            $write = static function (bool $withNewColumns) use ($db, $siteId, $provider, $fromAddress, $toRecipients, $subjectTrunc, $attachmentCount, $status, $httpCode, $errorCodeTrunc, $errorDetailTrunc, $recipientCount): void {
                $stmt = $db->prepare(
                    'INSERT INTO tblEmailLog ' .
                    '(siteID, provider, fromAddress, toRecipients, subject, attachmentCount, status, httpCode, errorCode, errorDetail'
                    . ($withNewColumns === true ? ', sentAtUtc, recipientCount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)' : ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                );
                if ($stmt === false) {
                    return;
                }
                $values = [$siteId, $provider, $fromAddress, $toRecipients, $subjectTrunc, $attachmentCount, $status, $httpCode, $errorCodeTrunc, $errorDetailTrunc];
                if ($withNewColumns === true) {
                    $values[] = $recipientCount;
                }
                $stmt->bind_param('issssisiss' . ($withNewColumns === true ? 'i' : ''), ...$values);
                $stmt->execute();
                $stmt->close();
            };
            try {
                $write(true);
            } catch (\mysqli_sql_exception $e) {
                // 🩹 Error 1054 is "unknown column": the portal's files have
                //    been updated and the database upgrade (migration 213) has
                //    not been run yet. Without this, nothing sent in that gap
                //    would be logged at all. The row is written the way it was
                //    before the two columns existed, and so is not counted by
                //    the hourly limit, like every other row from before 213.
                if ($e->getCode() !== 1054) {
                    throw $e;
                }
                $write(false);
            }

            // 🧹 Opportunistic retention prune — no dedicated cron needed.
            if (random_int(1, 50) === 1) {
                $retentionDays = (int) ($SETTINGS['mail']['log']['retentionDays'] ?? 90);
                if ($retentionDays > 0) {
                    $prune = $db->prepare('DELETE FROM tblEmailLog WHERE sentAt < DATE_SUB(NOW(), INTERVAL ? DAY)');
                    if ($prune !== false) {
                        $prune->bind_param('i', $retentionDays);
                        $prune->execute();
                        $prune->close();
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let a logging failure take mail sending down with it.
            error_log('Mailer::logSend() failed: ' . $e->getMessage());
        }
    }

    /** Build attachment array (<= 4MB each inline) */
    /**
     * Derive a plain-text alternative from an HTML body. Used for
     * multipart/alternative emission (#254). Strips tags, decodes
     * entities, collapses whitespace.
     *
     * Note: MS365 Graph's sendMail accepts HTML body and lets Microsoft
     * generate the plain-text alternative server-side, so this helper
     * is most useful for SMTP and any future provider that needs both
     * parts on the wire.
     */
    public static function htmlToPlain(string $html): string
    {
        // Convert <br> / </p> / </div> / </li> to newlines BEFORE strip.
        $intermediate = (string) preg_replace(
            ['/<br\\s*\\/?>/i', '/<\\/(p|div|li|h[1-6]|tr)\\s*>/i'],
            ["\n", "\n"],
            $html
        );
        // Convert <a href="X">Y</a> → "Y (X)" so users on plain-text clients
        // still see the URLs.
        $intermediate = (string) preg_replace_callback(
            '/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\\/a>/i',
            static function (array $m): string {
                $text = trim(strip_tags($m[2]));
                return $text !== '' && $text !== $m[1] ? $text . ' (' . $m[1] . ')' : $m[1];
            },
            $intermediate
        );
        $text = strip_tags($intermediate);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of blank lines and trim each line.
        $lines = preg_split('/\\R/', $text);
        $clean = [];
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '' && (count($clean) === 0 || end($clean) === '')) {
                continue;
            }
            $clean[] = $line;
        }
        return trim(implode("\n", $clean));
    }

    private static function attach(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            if (is_file($p) === false || filesize($p) > 4 * 1024 * 1024) {
                continue;
            }
            $out[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => basename($p),
                'contentType'  => mime_content_type($p) ?: 'application/octet-stream',
                'contentBytes' => base64_encode(file_get_contents($p)),
            ];
        }
        return $out;
    }

    /**
     * 🔍 The route send() will use: 'ms365', 'google' or 'smtp'.
     *
     * It used to hand back whatever `mail.provider` held, in small letters,
     * so a mistyped value was shown on the e-mail page as if it were a route
     * while send() quietly used Microsoft 365. It now gives route()'s answer,
     * so the two cannot differ.
     */
    public static function provider(): string
    {
        return self::route();
    }

    /** Obtain / cache app token (MS365 client credentials) */
    private static function accessToken(): string
    {
        if (self::$token !== '' && time() < self::$tokenExp - 60) {
            return self::$token;
        }

        global $SETTINGS;

        $cid    = $SETTINGS['auth']['ms365']['appwide']['clientID'] ?? '';
        $sec    = $SETTINGS['auth']['ms365']['appwide']['clientSecret'] ?? '';
        $tenant = $SETTINGS['auth']['ms365']['tenantID'] ?? '';

        if ($cid === '' || $sec === '' || $tenant === '') {
            throw new RuntimeException('Graph creds missing');
        }

        $url  = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
        $post = [
            'client_id'     => $cid,
            'client_secret' => $sec,
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default',
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init() failed for token request');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new RuntimeException('Token request failed');
        }

        $data = json_decode($resp, true);
        if (isset($data['access_token']) === false) {
            throw new RuntimeException('Token parse fail');
        }

        self::$token    = $data['access_token'];
        self::$tokenExp = time() + (int) $data['expires_in'];

        return self::$token;
    }
}
