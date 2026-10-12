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
 * WHAT THAT ROUTE DOES NOT HAVE YET: a queue. A message that cannot be sent
 * now (the server is down, its certificate fails the check, the hourly limit
 * is reached) is NOT kept and NOT tried again. send() answers false and the
 * attempt is in the send log with its reason.
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
 * @version   1.1.0
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
    public const OUTCOME_NOT_SET_UP = 'not-set-up';
    public const OUTCOME_REFUSED    = 'refused';
    public const OUTCOME_NOT_SENT   = 'not-sent';

    /** @var string One of the OUTCOME_ values above. */
    private static string $lastOutcome = self::OUTCOME_NOT_SENT;

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
     * @param array        $options For the queue that package M3 adds:
     *                              `priority` ('urgent', 'normal' or 'bulk'),
     *                              `expiresAt` (a moment in UTC after which
     *                              sending is pointless) and `repeatSafe` (a
     *                              second copy does no harm). NOTHING READS IT
     *                              YET: with no queue, every message is tried
     *                              once, now, whatever is passed. It is here so
     *                              that callers can already say what they mean.
     *
     * @return bool True if sent successfully. lastOutcome() says more.
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
        $route             = self::route();
        self::$lastOutcome = self::canSend() === true ? self::OUTCOME_NOT_SENT : self::OUTCOME_NOT_SET_UP;

        // ⏳ The hourly limit, for every route (#592). Empty, which is how it
        //    is seeded, means the portal applies none, and then this changes
        //    nothing. Over it, the message is NOT sent and NOT kept: there is
        //    no queue yet. The send log says so, with the numbers.
        $over = MailRoutes::overLimit(count($recipients));
        if ($over !== '') {
            self::logSend($route, '', implode(', ', $recipients), $subj, 0, 'failed', null, MailRoutes::OVER_LIMIT, $over);

            return false;
        }

        // 🔀 Dispatch to the configured provider. route() is the one place
        //    that reads the choice, so that sendingRoute() and canSend()
        //    above can never describe a different route from the one used here.
        if ($route === self::ROUTE_SMTP) {
            // 🚫 This route never throws. With nothing filled in it answers
            //    false and logs "not set up". (The Microsoft 365 route throws
            //    there, which ends a page that sends without a try/catch, such
            //    as an event broadcast, in an error.)
            return self::sendViaSmtp($recipients, $subj, $htmlBody, $plainBody, $files)['sent'];
        }

        $sent = $route === self::ROUTE_GOOGLE
            ? MailerGoogle::send($recipients, $subj, $htmlBody, $files)
            // 📧 Default: Microsoft Graph (MS365)
            : self::sendViaGraph($recipients, $subj, $htmlBody, $files);
        self::$lastOutcome = $sent === true ? self::OUTCOME_SENT : self::OUTCOME_NOT_SENT;

        return $sent;
    }

    /**
     * 🧾 What became of the last message given to send(), for a page that
     * wants to say more than yes or no.
     *
     *   'sent'        a mail service accepted it. Not the same as delivered.
     *   'not-set-up'  the chosen route is missing something; nothing was tried.
     *   'refused'     SMTP only: the mail server, or the mail library, said no
     *                 in a way that trying again would not change (this
     *                 address, this sender, this message, the sign-in).
     *   'not-sent'    it did not go, for any other reason: the service could
     *                 not be reached, its certificate failed the check, the
     *                 hourly limit was reached, or Microsoft 365 or Google
     *                 said no.
     *
     * WHAT IT CANNOT SAY YET. The plan for this work names two more answers,
     * 'queued' and 'held', for a message that is being kept to try again or
     * kept for a person to look at. Neither is returned, because nothing is
     * kept: there is no queue until package M3. 'not-sent' is the true word
     * for both until then.
     *
     * It describes the LAST call in this request only.
     */
    public static function lastOutcome(): string
    {
        return self::$lastOutcome;
    }

    /**
     * 📮 Send through the SMTP route, and record the attempt.
     *
     * MailRoutes does the sending and never throws; this adds what Mailer
     * does for every route, the send log, and a line in the error log for a
     * real failure. "Not set up" gets no error-log line: it is a state, the
     * health page reports it, and a broadcast to 200 people would otherwise
     * write 200 of them.
     *
     * @param list<string> $to
     * @param list<string> $files
     *
     * @return array{sent: bool, outcome: string, reason: string, code: int|null, words: string, from: string,
     *               attachments: int, steps: list<string>, certificate: bool} See MailRoutes::sendSmtp().
     */
    private static function sendViaSmtp(array $to, string $subj, string $html, string $plain, array $files): array
    {
        $answer            = MailRoutes::sendSmtp($to, $subj, $plain, $html, $files);
        self::$lastOutcome = $answer['outcome'];

        if ($answer['sent'] === true) {
            self::logSend(self::ROUTE_SMTP, $answer['from'], implode(', ', $to), $subj, $answer['attachments'], 'sent', $answer['code'], '', '');

            return $answer;
        }
        // `words` never holds the mailbox's password: the mail library takes
        // it out of everything it returns, and MailRoutes adds none.
        self::logSend(self::ROUTE_SMTP, $answer['from'], implode(', ', $to), $subj, $answer['attachments'], 'failed', $answer['code'], $answer['reason'], $answer['words']);
        if ($answer['outcome'] !== self::OUTCOME_NOT_SET_UP) {
            Logger::errorPlatform('SMTP', 'Error', $answer['reason'], 'Mail send fail', $answer['words']);
        }

        return $answer;
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
     * @return array{sent: bool, outcome: string, reason: string, code: int|null, words: string, from: string,
     *               attachments: int, steps: list<string>, certificate: bool} See MailRoutes::sendSmtp().
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
     */
    public static function sendTemplated(
        string|array $to,
        string $subject,
        string $template,
        array $vars = [],
        array $files = []
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
        return self::send($to, $subject, self::renderBase($rendered), $files);
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
        string $errorDetail
    ): void {
        global $SETTINGS;

        try {
            $db     = App::db();
            $siteId = Site::id();

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
