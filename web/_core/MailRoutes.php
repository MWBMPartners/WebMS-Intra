<?php
// Path: _core/MailRoutes.php
/**
 * -----------------------------------------------------------------------------
 * Mail routes ✉️🧭 — the mail settings, and the SMTP route built from them
 * -----------------------------------------------------------------------------
 * THE ONE PLACE that reads the SMTP mail settings, decrypts the mailbox's
 * password, and hands a message to the mail library (`web/_core/maillib/`,
 * namespace `MWBM\Mail`). `Mailer` asks this class; no page reads these
 * settings itself.
 *
 * WHY IT READS THE DATABASE ITSELF, AND NOT `$SETTINGS`
 * -----------------------------------------------------
 * Two reasons, both found by checking (#497, #597):
 *
 * 1. The portal's start-up never decrypts a setting that is stored encrypted
 *    (it compares the "is a secret" flag with the text '1', and a prepared
 *    statement hands that flag back as the number 1). So `$SETTINGS` holds
 *    the encrypted text, not the password. This class decrypts it itself, and
 *    accepts the flag as the number 1 or the text '1', so it works before and
 *    after that fault is put right.
 * 2. `$SETTINGS` is the installation's settings with ONE organisation's own
 *    rows laid over them. A mail server's name or a mailbox's password must
 *    never come from an organisation's row: it would let one organisation's
 *    administrator send everybody's mail, password included, to a server of
 *    their choosing. Every query here says `siteID IS NULL`.
 *
 * THE PASSWORD
 * ------------
 * - saveSmtp() below (called by the page at Admin, Integrations, Email) is
 *   the place MEANT to write it: encrypted with the installation's key and
 *   marked as a secret. IT IS NOT THE ONLY THING THAT CAN. The general
 *   Settings page can change the same row, for a global administrator. (This
 *   used to say "written ONLY by saveSmtp()", which was not true.) What this
 *   class does promise is about READING: a stored value is used only when
 *   its row is marked as a secret AND it decrypts with the installation's
 *   key. Since #597 was fixed the general Settings page stores it encrypted
 *   and keeps the mark, so a password typed there passes both tests and IS
 *   used. (That page used to store it in plain text with the mark removed.)
 * - It is read ONLY by password() below, and leaves this class only inside a
 *   `MWBM\Mail\Secret`, which cannot be printed, dumped or serialised.
 * - A stored password that is NOT marked as a secret, or that cannot be
 *   decrypted, is treated as "no password: enter it again". Until #597 was
 *   fixed the general Settings page stored what was typed into it as plain
 *   text and removed the mark; a password left that way is not used as it
 *   stands. REJECTED: accepting an unmarked value as it stands. It would
 *   work, and it would leave the mailbox's password readable in the database.
 * - Nothing here logs it, and no message built here contains it.
 *
 * ONE ROUTE FOR THE WHOLE INSTALLATION, FOR NOW
 * ---------------------------------------------
 * The owner's decision (12 October 2026): one mail route, set by a global
 * administrator. forSite() takes an organisation's number so that a route per
 * organisation can be added later without changing its callers; today it
 * ignores the number.
 *
 * WHAT IS WRONG WITH THE ROUTE, KEPT FROM ONE REQUEST TO THE NEXT (package M3)
 * -------------------------------------------------------------------------
 * Two faults are about the ROUTE and not about any one message, so they are
 * put on record (one row of tblMailRouteState) and every later send, in any
 * request or run of the mail job, reads that record first:
 * - THE CERTIFICATE FAILED ITS CHECK. Nothing is sent. Every message is HELD
 *   in the mail queue without a connection being made, and each run of the
 *   job makes exactly one checked connection to see whether it now passes.
 * - THE MAIL SERVER REFUSED THE SIGN-IN. Nothing is sent. Messages WAIT in
 *   the queue, and the job signs in again only after a wait that grows (15
 *   minutes, 1 hour, 3 hours, then every 6), because offering a refused
 *   password again and again is what gets a mailbox locked.
 * Either record goes when a message is accepted, when a connection gets past
 * the step that failed, or when the mail settings are changed (the record
 * carries a fingerprint of them, so a new password is tried at once).
 * "Send test" always tries, whatever is on record: it is how an
 * administrator finds out.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * - The mail server's certificate is always checked and there is no way to
 *   switch that off. A certificate that fails holds every message until the
 *   mail server's certificate is put right. (`mail.smtp.certCheck` is seeded
 *   for the page package M4 adds; NOTHING HERE READS IT.)
 * - canSend's facts are settings only. They cannot show that the mail server
 *   will accept the mailbox's password: only "Send test" shows that.
 * - The hourly limit is counted from this portal's own log. It knows nothing
 *   of mail the same mailbox sends from anywhere else. The mail job runs one
 *   at a time, so what IT sends is counted exactly; two ordinary requests
 *   arriving at the same moment can still both be let through at the limit's
 *   edge. See roomLeft().
 * - The record of a fault is as old as the last attempt. A certificate put
 *   right since still shows until the job's next run, or a Send test.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use MWBM\Mail\Client;
use MWBM\Mail\Message;
use MWBM\Mail\MessageRefused;
use MWBM\Mail\Secret;
use MWBM\Mail\SendResult;
use MWBM\Mail\Smtp\SmtpConfig;
use MWBM\Mail\Smtp\SmtpTransport;
use MWBM\Mail\SystemClock;
use mysqli;

// 📦 The mail library has its own loader, because it is written to be lifted
//    out of the portal (the way Pdf.php loads dompdf). This is the only file
//    of the portal that names the library, so it is loaded here.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maillib' . DIRECTORY_SEPARATOR . 'autoload.php';

final class MailRoutes
{
    /** @var string What `mail.provider` holds when this route is chosen, and what the send log calls it. */
    public const SMTP = 'smtp';

    /** @var string The route's name for a person to read ("E-mail is set to go through …"). */
    public const SMTP_LABEL = 'an SMTP mailbox';

    /** @var list<string> The two kinds of encryption. There is no unencrypted choice, here or in the library. */
    public const SECURITY_CHOICES = [SmtpConfig::STARTTLS, SmtpConfig::TLS];

    /** @var int The most recipients an hour that can be typed as the limit. A larger number is a typing mistake. */
    public const LARGEST_LIMIT = 1000000;

    /** @var string The send log's word for "the hourly limit stopped it". */
    public const OVER_LIMIT = 'over-hourly-limit';

    /** @var string The send log's word for "the route is missing a setting, so nothing was tried". The mail library's own word for it is the same. */
    private const NOT_SET_UP = SendResult::REASON_NOT_SET_UP;

    /** @var string The send log's word for "this could not be made into an e-mail, so nothing was tried". */
    private const MESSAGE_REFUSED = 'message-refused';

    /** @var string The send log's word for "not tried: the mail server refused the sign-in earlier in this request". See sendSmtp(). */
    public const SIGN_IN_NOT_REPEATED = 'sign-in-not-repeated';

    // 🧭 What should become of a message after an attempt (`next` in what
    //    sendSmtp() returns). Mailer and the mail queue act on this word and
    //    on nothing else, so the rules below are written once: see whatNext().
    public const NEXT_SENT             = 'sent';             // a mail server accepted it
    public const NEXT_LATER            = 'later';            // keep it and try again
    public const NEXT_REFUSED          = 'refused';          // about THIS message: never tried again
    public const NEXT_HOLD_CERTIFICATE = 'hold-certificate'; // the certificate failed: held, with every other message
    public const NEXT_HOLD_UNKNOWN     = 'hold-unknown';     // handed over whole and no answer: held for a person
    public const NEXT_SIGN_IN          = 'sign-in';          // the sign-in was refused: it waits, uncounted
    public const NEXT_NOT_SET_UP       = 'not-set-up';       // a setting is missing: nothing was tried

    /** @var string The one row of tblMailRouteState there is today: the route every organisation shares. A route for one organisation, later, gets a row of its own. */
    public const ROUTE_KEY = 'installation';

    // What can be on record as wrong with the route (tblMailRouteState.problem).
    public const PROBLEM_CERTIFICATE = 'certificate';
    public const PROBLEM_SIGN_IN     = 'sign-in-refused';

    /**
     * @var list<int> After a refused sign-in, how long the job waits before
     *      it signs in again, in seconds: 15 minutes, 1 hour, 3 hours, then 6
     *      hours each time. Long, on purpose: a mailbox is locked by its
     *      provider for repeated wrong passwords, and a person has to act
     *      before another try can do any good.
     */
    private const SIGN_IN_WAITS = [900, 3600, 10800, 21600];

    /** @var int How many failures in a row to switch encryption on before the health page says so. */
    public const ENCRYPTION_FAILURES_WORTH_SAYING = 5;

    /**
     * ⏱️ How long each step of a send may take, in seconds.
     *
     * Shorter than the library's own defaults (8, 8, 10, 10, 20, 30), on
     * purpose. In this package every send happens inside a web request, with a
     * person or a web server waiting, and many hosts end a request after 30
     * seconds. With these numbers the longest a send can take is 15 seconds up
     * to handing the message over, plus 12 for the server's verdict: 27.
     *
     * The cost: a mail server slower than this is given up on sooner. The
     * mail queue's job (package M3) uses these numbers too, and not the
     * library's longer defaults: on shared hosting the job is itself a web
     * request, started by the hosting company's scheduler, and is ended by the
     * same limits. A message a slow server would not take in time is tried
     * again at the job's next wait.
     */
    private const SECONDS = ['connect' => 5.0, 'handshake' => 5.0, 'reply' => 6.0, 'write' => 6.0, 'verdict' => 12.0, 'message' => 15.0];

    /** @var array<string, array{value: string, secret: bool}>|null The installation-wide `mail.*` rows, read once a request. */
    private static ?array $rows = null;

    /**
     * @var array{missing: list<string>, config: SmtpConfig, client: Client, from: string, fromName: string, replyTo: string}|null
     *      The route once it has been opened, kept for the rest of the request so several messages share one connection.
     */
    private static ?array $open = null;

    /** @var bool Whether the "close the connection when the request ends" step has been registered. */
    private static bool $closesAtEnd = false;

    /** @var bool True once the mail server has refused the mailbox's user name and password in this request. See sendSmtp(). */
    private static bool $signInRefused = false;

    /** @var array{problem: string, words: string, since: string, seenAt: string, tryNow: bool}|null What standingProblem() found, kept for the rest of the request. */
    private static ?array $standing = null;

    // =========================================================================
    // 📖 Reading
    // =========================================================================

    /**
     * The installation-wide `mail.*` settings, straight from the database.
     *
     * `siteID IS NULL` is the point: see the file header. A failure to read is
     * treated as "nothing is set", which every caller reports as "not set up".
     *
     * @return array<string, array{value: string, secret: bool}>
     */
    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        $rows = [];
        try {
            $stmt = App::db()->prepare(
                "SELECT settingKey, settingValue, isSensitive FROM tblSettings WHERE siteID IS NULL AND settingKey LIKE 'mail.%'"
            );
            if ($stmt !== false) {
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $rows[(string) $row['settingKey']] = [
                        'value'  => (string) ($row['settingValue'] ?? ''),
                        // 🔢 The flag arrives as the number 1 from a prepared
                        //    statement and as the text '1' from other kinds of
                        //    query. Both mean yes; nothing else does.
                        'secret' => ($row['isSensitive'] === 1 || $row['isSensitive'] === '1'),
                    ];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('MailRoutes: the mail settings could not be read: ' . $e->getMessage());
        }
        self::$rows = $rows;

        return $rows;
    }

    /** One setting's value with the spaces around it removed; '' when it is not stored. */
    private static function value(string $key): string
    {
        return trim(self::rows()[$key]['value'] ?? '');
    }

    /**
     * 🧭 What `mail.provider` holds for the WHOLE INSTALLATION, in small
     * letters; '' when it is not stored. Mailer::route() turns it into a route.
     *
     * Mailer used to read this from `$SETTINGS`, where one organisation's own
     * rows lie over the installation's. With such a row, every request made
     * for that organisation followed the organisation's choice: its e-mail
     * page described that route, and "Use this route" (which writes the
     * installation's row, see saveChoice()) said it had changed the route
     * while the page went on showing the old one. One route serves every
     * organisation, so the choice is now read where it is written.
     *
     * WHAT THIS CANNOT DO: give one organisation a route of its own. An
     * organisation's `mail.provider` row is ignored, as its mail server and
     * password rows always were. The general Settings page refuses to add
     * one (a name that exists for the whole installation cannot be added
     * again there), so such a row arrives only by SQL or in an older database.
     */
    public static function chosenRoute(): string
    {
        return strtolower(self::value('mail.provider'));
    }

    /**
     * 🔑 The mailbox's password, and what state the stored one is in.
     *
     * @return array{state: string, secret: Secret} state is one of:
     *         'none'       nothing is stored;
     *         'not-secret' something is stored but is not marked as a secret,
     *                      so it was not written by saveSmtp() and is not used;
     *         'unreadable' it is marked as a secret but cannot be decrypted
     *                      (the installation's key file has changed or gone);
     *         'stored'     it was decrypted, and `secret` holds it.
     *         In every state but the last, `secret` is empty.
     */
    private static function password(): array
    {
        $row = self::rows()['mail.smtp.password'] ?? ['value' => '', 'secret' => false];
        if ($row['value'] === '') {
            return ['state' => 'none', 'secret' => new Secret('')];
        }
        if ($row['secret'] !== true) {
            return ['state' => 'not-secret', 'secret' => new Secret('')];
        }
        // Auth::decrypt() gives '' for anything it cannot decrypt, and never throws.
        $plain = Auth::decrypt($row['value']);
        if ($plain === '') {
            return ['state' => 'unreadable', 'secret' => new Secret('')];
        }

        return ['state' => 'stored', 'secret' => new Secret($plain)];
    }

    /**
     * 🧾 What the e-mail page shows in its form. NEVER the password: only
     * which of the four states the stored one is in (see password()).
     *
     * @return array{host: string, port: string, security: string, username: string, passwordState: string,
     *               fromAddress: string, fromName: string, replyTo: string, perHour: string}
     */
    public static function formValues(): array
    {
        $password = self::password();
        $state    = $password['state'];
        $password['secret']->wipe();

        return [
            'host'          => self::value('mail.smtp.host'),
            'port'          => self::value('mail.smtp.port'),
            'security'      => self::value('mail.smtp.security'),
            'username'      => self::value('mail.smtp.username'),
            'passwordState' => $state,
            'fromAddress'   => self::value('mail.defaultFromAddress'),
            'fromName'      => self::value('mail.defaultFromName'),
            'replyTo'       => self::value('mail.defaultReplyTo'),
            'perHour'       => self::value('mail.limit.perHour'),
        ];
    }

    /**
     * The name this portal gives when it introduces itself to the mail server.
     *
     * Mail servers expect the client's own host name. It is taken from the
     * portal's address as an administrator SET it (PortalAddress::saved(),
     * the one reader of the `site.url` setting, #500), or failing that from
     * the domain of the "from" address. REJECTED: the Host header of the
     * request, which is why this asks for the saved address and not
     * PortalAddress::base(). A visitor chooses that header, and a name the
     * library does not accept would make every send answer "not set up". When
     * neither gives a usable name the library's own default ("localhost") is
     * used, which mail servers accept.
     */
    private static function clientName(string $fromAddress): string
    {
        $candidates = [
            (string) parse_url(PortalAddress::saved(), PHP_URL_HOST),
            (string) substr((string) strrchr($fromAddress, '@'), 1),
        ];
        foreach ($candidates as $name) {
            if ($name !== '' && filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && str_contains($name, '.') === true) {
                return $name;
            }
        }

        return 'localhost';
    }

    /**
     * The words a failed step is described in. Each finishes the sentence
     * "It stopped while …". The keys are the library's own stage names.
     */
    private const STOPPED_WHILE = [
        SendResult::STAGE_CONFIG    => 'reading the settings',
        SendResult::STAGE_MESSAGE   => "checking the message's size",
        SendResult::STAGE_CONNECT   => 'reaching the mail server',
        SendResult::STAGE_GREETING  => "waiting for the mail server's first line",
        SendResult::STAGE_EHLO      => 'introducing itself to the mail server',
        SendResult::STAGE_STARTTLS  => 'asking the mail server for encryption',
        SendResult::STAGE_TLS       => "switching encryption on and checking the mail server's certificate",
        SendResult::STAGE_AUTH      => 'signing in to the mailbox',
        SendResult::STAGE_RESET     => 'clearing the connection for the next message',
        SendResult::STAGE_SENDER    => 'naming who the message is from',
        SendResult::STAGE_RECIPIENT => 'naming who the message is for',
        SendResult::STAGE_DATA      => 'asking to send the message',
        SendResult::STAGE_BODY      => "handing the message over and waiting for the mail server's answer",
    ];

    /**
     * Would the library make an e-mail with this sender? '' when it would;
     * otherwise its own reason, which never repeats the bad value.
     *
     * Asked of the library itself, by building a message that is never sent,
     * because its rules are stricter than PHP's (an address needs a dot in its
     * domain, for one). Without this, canSend() said yes to a "from" address
     * that every send then refused.
     */
    private static function senderProblem(string $fromAddress, string $fromName, string $replyTo): string
    {
        try {
            new Message(1, $fromAddress, $fromName, [$fromAddress], 'x', 'x', 'x', $replyTo);
        } catch (MessageRefused $refusal) {
            return $refusal->getMessage();
        }

        return '';
    }

    /**
     * 🧱 The SMTP route as the settings describe it.
     *
     * `missing` is what Mailer::sendingRoute() shows an administrator, on the
     * e-mail page AND on the health page, so it is in plain words and never
     * says "here" or "this page". It lists exactly what a send refuses to go
     * without: this method's own checks first, then anything more the
     * library's own check finds.
     *
     * The caller owns `config` and must wipe its password when finished with
     * it (`$config->password->wipe()`); smtpMissing() and the client's close
     * do so.
     *
     * @return array{missing: list<string>, config: SmtpConfig|null, from: string, fromName: string, replyTo: string}
     *         `config` is null whenever `missing` is not empty.
     */
    private static function smtp(): array
    {
        $host     = self::value('mail.smtp.host');
        $port     = self::value('mail.smtp.port');
        $security = strtolower(self::value('mail.smtp.security'));
        $username = self::value('mail.smtp.username');
        $from     = self::value('mail.defaultFromAddress');
        $password = self::password();
        $missing  = [];

        if ($host === '') {
            $missing[] = "the mail server's name";
        }
        if (preg_match('/\A[0-9]{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            $missing[] = "the mail server's port (a number from 1 to 65535; most mail providers use 587)";
        }
        if (in_array($security, self::SECURITY_CHOICES, true) === false) {
            $missing[] = 'the kind of encryption ("starttls" or "tls"; the portal does not send without encryption)';
        }
        if ($username === '') {
            $missing[] = "the mailbox's user name";
        }
        if ($password['state'] === 'none') {
            $missing[] = "the mailbox's password";
        } elseif ($password['state'] === 'not-secret') {
            $missing[] = "the mailbox's password: the one stored was not saved by the e-mail page, so it is not used. Type it in again at Admin, Integrations, Email";
        } elseif ($password['state'] === 'unreadable') {
            $missing[] = "the mailbox's password: the one stored cannot be read (the installation's key file may have changed). Type it in again at Admin, Integrations, Email";
        }
        $fromName = self::value('mail.defaultFromName');
        $replyTo  = self::value('mail.defaultReplyTo');
        if ($from === '') {
            $missing[] = 'an address to send from (the "from" address)';
        } else {
            $problem = self::senderProblem($from, $fromName, $replyTo);
            if ($problem !== '') {
                $missing[] = 'a usable "from" address, "from" name and reply-to address. ' . $problem;
            }
        }

        $config = null;
        if ($missing === []) {
            $config = new SmtpConfig(
                $host,
                (int) $port,
                $security,
                $username,
                $password['secret'],
                '',
                self::clientName($from),
                self::SECONDS['connect'],
                self::SECONDS['handshake'],
                self::SECONDS['reply'],
                self::SECONDS['write'],
                self::SECONDS['verdict'],
                self::SECONDS['message'],
            );
            // The library's own check is the one a send obeys. Anything it
            // finds that the checks above did not (a server name that is not
            // a name, a control character in the user name) is passed on in
            // the library's own sentence.
            foreach ($config->missing() as $problem) {
                $missing[] = $problem;
            }
        }
        if ($missing !== []) {
            $password['secret']->wipe();
            $config = null;
        }

        return [
            'missing'  => $missing,
            'config'   => $config,
            'from'     => $from,
            'fromName' => $fromName,
            'replyTo'  => $replyTo,
        ];
    }

    /**
     * ✅ What the SMTP route still needs before it can send. Empty = ready.
     *
     * For Mailer::sendingRoute(), and so for Mailer::canSend(). Settings only:
     * it does not contact the mail server.
     *
     * @return list<string>
     */
    public static function smtpMissing(): array
    {
        $route = self::smtp();
        if ($route['config'] !== null) {
            $route['config']->password->wipe();
        }

        return $route['missing'];
    }

    /**
     * 🧭 The route for one organisation, ready to send, or null when it is
     * not set up.
     *
     * TODAY THE NUMBER IS IGNORED: there is one route for the whole
     * installation. The parameter is here so that a route per organisation
     * can be added later inside this method, with no caller changing.
     *
     * One `Client` is kept for the rest of the request, for two reasons the
     * library gives: several messages then share one connection, and after two
     * failures to reach the server the rest are answered "try later" at once
     * in place of waiting on a dead server once per message. The connection is
     * closed, and the password wiped, when the request ends.
     */
    public static function forSite(?int $siteId): ?Client
    {
        return self::open()['client'];
    }

    /**
     * The route, opened once a request: what smtp() gives, plus the `client`
     * that sends through it (null when it is not set up, and then nothing is
     * kept, so the next call reads the settings afresh).
     *
     * @return array{missing: list<string>, config: SmtpConfig|null, client: Client|null, from: string, fromName: string, replyTo: string}
     */
    private static function open(): array
    {
        if (self::$open !== null) {
            return self::$open;
        }
        $route = self::smtp();
        if ($route['config'] === null) {
            return $route + ['client' => null];
        }
        self::$open = $route + ['client' => new Client(new SmtpTransport($route['config']))];
        if (self::$closesAtEnd === false) {
            self::$closesAtEnd = true;
            register_shutdown_function([self::class, 'forget']);
        }

        return self::$open;
    }

    /**
     * 🧹 Close the open connection and drop what was read from the database.
     *
     * Called when a request ends, and by the e-mail page straight after a
     * save so that what it shows next is what is now stored.
     */
    public static function forget(): void
    {
        if (self::$open !== null) {
            // close() says goodbye to the server and never throws. The
            // password is needed until then (a connection may be opened
            // again for a later message), and not after.
            self::$open['client']->close();
            self::$open['config']->password->wipe();
            self::$open = null;
        }
        self::$rows = null;
        // A save may have just stored a new password, so the one the mail
        // server refused is no longer the one that would be offered.
        self::$signInRefused = false;
        // And what is on record about the route is read again: a save
        // changes the fingerprint the record is compared with.
        self::$standing = null;
    }

    // =========================================================================
    // ⏳ The hourly limit
    // =========================================================================

    /**
     * The limit as stored: a number of recipients an hour, null for "no limit
     * of the portal's own" (the setting is empty), or false when what is
     * stored is not a whole number from 1 upward.
     */
    private static function limit(): int|null|false
    {
        $stored = self::value('mail.limit.perHour');
        if ($stored === '') {
            return null;
        }
        if (preg_match('/\A[0-9]{1,7}\z/', $stored) !== 1 || (int) $stored < 1 || (int) $stored > self::LARGEST_LIMIT) {
            return false;
        }

        return (int) $stored;
    }

    /**
     * What is wrong with the stored limit, in plain words, or '' when it is
     * empty or a usable number.
     *
     * Mailer::sendingRoute() adds this to what a route still needs, for every
     * route. REJECTED: treating an unreadable limit as "no limit". An
     * administrator who typed "50 an hour" into the general Settings page
     * would believe a limit applied when none did, and would find out from
     * their hosting company.
     */
    public static function limitProblem(): string
    {
        return self::limit() === false
            ? 'a usable hourly limit: what is stored is not a whole number from 1 to ' . self::LARGEST_LIMIT
                . '. Correct it, or clear it, at Admin, Integrations, Email'
            : '';
    }

    /**
     * 🧮 How many recipients were sent to in the hour before now.
     *
     * Counted on `sentAtUtc`, a moment in UTC, against the database's own UTC
     * clock, as an exact 3,600 seconds. REJECTED: counting on `sentAt`. That
     * column is written by the database server's own clock, which repeats an
     * hour on the night the clocks go back: mail sent at 01:10 and 01:50
     * before the change and at 01:20 after it would all read as inside one
     * hour, when the first is 70 minutes before the last.
     *
     * Rows from before migration 213 have no UTC moment and are not counted.
     * Only messages a mail service ACCEPTED count: a failed attempt used up
     * none of the mailbox's allowance.
     *
     * @param mysqli $db The connection to count on. Passed in so that a test
     *                   can use one whose clock it has set.
     */
    public static function recipientsInTheLastHour(mysqli $db): int
    {
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(recipientCount), 0) AS n FROM tblEmailLog "
            . "WHERE status = 'sent' AND sentAtUtc > UTC_TIMESTAMP() - INTERVAL 3600 SECOND"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['n'] ?? 0);
    }

    /**
     * 🧯 How much of the hourly limit is kept back for URGENT mail.
     *
     * A tenth of the limit, and never fewer than 5, so that a mailing which
     * fills the hour cannot keep somebody's password-reset link waiting. But
     * never more than half the limit: with a small limit (3 an hour, say)
     * "at least 5" would otherwise leave ordinary mail no room at all, ever.
     */
    public static function reserve(int $limit): int
    {
        return min(max(5, intdiv($limit + 9, 10)), intdiv($limit, 2));
    }

    /**
     * 🧮 How many more recipients may be sent to within the hour: null when
     * the portal applies no limit of its own, otherwise a number (0 or less
     * means none).
     *
     * Ordinary and bulk mail may use the limit less the reserve; urgent mail
     * may use all of it.
     *
     * HOW EXACT THIS IS. The mail queue's job runs one at a time and asks
     * here before each message, so the job never passes the limit by itself.
     * A message sent inside an ordinary request asks here too, but two such
     * requests arriving at the same moment both see the same room and can
     * both use it: the limit can then be passed by as many messages as there
     * are requests at that instant. Closing that would mean a lock around
     * every send in every request, which would make one slow mail server
     * hold up every page that sends.
     *
     * @param mysqli $db     The connection to count on (see recipientsInTheLastHour()).
     * @param bool   $urgent True for urgent mail.
     *
     * @throws \Throwable When the count cannot be made. The caller must then
     *                    send nothing: see overLimit().
     */
    public static function roomLeft(mysqli $db, bool $urgent): ?int
    {
        $limit = self::limit();
        if ($limit === null) {
            return null;
        }
        if ($limit === false) {
            return 0; // not a number: Mailer::sendingRoute() already says "not set up"
        }

        return $limit - ($urgent === true ? 0 : self::reserve($limit)) - self::recipientsInTheLastHour($db);
    }

    /**
     * 🚦 Would sending to this many more recipients pass the hourly limit?
     *
     * @param bool $urgent True for urgent mail, which may use the part of the
     *                     limit kept back from everything else.
     *
     * @return string '' when the message may go; otherwise the reason, in
     *                plain words, for the send log and for the administrator.
     *                Mailer keeps such a message in the queue when it can.
     */
    public static function overLimit(int $recipients, bool $urgent = false): string
    {
        $limit = self::limit();
        if ($limit === null) {
            return '';
        }
        if ($limit === false) {
            // sendingRoute() already reports this as "not set up", so a send
            // does not normally get here. Refused all the same: see limitProblem().
            return 'Not sent: the hourly limit stored is not a number, so the portal cannot tell whether this message is within it.';
        }
        try {
            $room = self::roomLeft(App::db(), $urgent);
        } catch (\Throwable $e) {
            // The count could not be made (the log table is missing its new
            // columns, or the database is in trouble). Sending anyway would
            // make the limit a promise that is quietly not kept.
            error_log('MailRoutes: the hourly count could not be made: ' . $e->getMessage());

            return 'Not sent: the portal could not count how much mail went in the last hour, so it cannot tell whether this message is within the limit of ' . $limit . ' recipients an hour.';
        }
        if ($room !== null && $recipients > $room) {
            $reserve = $urgent === true ? 0 : self::reserve($limit);
            $already = $limit - $reserve - $room;

            return 'Not sent now: the limit is ' . $limit . ' recipients an hour, ' . $already . ' were sent to in the last hour, and this message is for ' . $recipients . '.'
                . ($reserve > 0 ? ' (The last ' . $reserve . ' of the ' . $limit . ' are kept for urgent mail, such as password resets.)' : '');
        }

        return '';
    }

    /**
     * 🚫 Is this message for more recipients than the hourly limit allows in
     * a WHOLE hour, so that it could never go however long it waited?
     *
     * Ordinary and bulk mail may use the limit less the part kept for urgent
     * mail; urgent mail may use all of it. A message for more than that has
     * no hour it fits in. It used to be kept "until the hour has room".
     *
     * @return string '' when it fits, or when the portal applies no limit of
     *                its own (or the stored limit is not a number: that is
     *                limitProblem()'s to report). Otherwise the reason, in
     *                plain words that name the limit. It holds no address.
     */
    public static function neverFits(int $recipients, bool $urgent): string
    {
        $limit = self::limit();
        if ($limit === null || $limit === false) {
            return '';
        }
        $reserve = $urgent === true ? 0 : self::reserve($limit);
        if ($recipients <= $limit - $reserve) {
            return '';
        }

        return 'Not sent, and not kept to try again: this message is for ' . $recipients . ' recipients, and the limit is ' . $limit . ' recipients an hour'
            . ($reserve > 0 ? ' (of which the last ' . $reserve . ' are kept for urgent mail, such as password resets)' : '')
            . ', so there is no hour in which it could go. Send it to fewer people at a time, or raise the limit at Admin, Integrations, Email.';
    }

    // =========================================================================
    // 📤 Sending
    // =========================================================================

    /**
     * Send one message through the SMTP route. Never throws.
     *
     * It does not write the send log and does not apply the hourly limit:
     * Mailer does both, so that they are done the same way for every route.
     *
     * Every recipient is on the "To" line of ONE message, as the Microsoft 365
     * route has always done, so they see each other's addresses. A caller that
     * must not show them sends one message per person. The library takes at
     * most 100 recipients in one message and refuses more.
     *
     * UNLIKE the Microsoft 365 route, a file that cannot be attached (missing,
     * unreadable, over 10 MB) stops the message. That route leaves the file
     * out and sends the rest; a statement with no statement attached is worse
     * than no e-mail, so this one says so and sends nothing.
     *
     * @param list<string> $to    The recipients' addresses.
     * @param list<string> $files Paths of files to attach.
     * @param array{messageId?: string, date?: int, repeatSafe?: bool} $again
     *        For the mail queue. `messageId` and `date` (seconds since 1970,
     *        UTC) are the ones the message was first tried with, so that every
     *        attempt is the SAME message to a mail system that has seen it
     *        before. `repeatSafe` true means a second copy does no harm.
     *
     * @return array{sent: bool, outcome: string, reason: string, code: int|null, words: string, from: string,
     *               attachments: int, steps: list<string>, certificate: bool, next: string, stage: string,
     *               messageId: string, routeFault: bool}
     *         next:        what should become of the message: one of the NEXT_
     *                      values. See whatNext().
     *         stage:       the step the attempt stopped at, in the mail
     *                      library's word.
     *         messageId:   the message's identifier, to keep for a later
     *                      attempt. '' when no message was made.
     *         routeFault:  true when what went wrong would go wrong for every
     *                      message alike (the server, the encryption, the
     *                      sign-in, the sender). The job then stops its run.
     *         outcome:     one of Mailer's OUTCOME_ values, as "Send test"
     *                      shows it: sent, refused, not-set-up or not-sent.
     *         reason:      a short word for the send log ('not-set-up',
     *                      'message-refused', 'sign-in-not-repeated', or the
     *                      library's own reason, such as 'certificate' or
     *                      'timeout').
     *         code:        the mail server's reply number, when it gave one.
     *         words:       what happened, in plain words. Holds no password.
     *                      It can hold e-mail addresses (a server's refusal
     *                      names the address it refused).
     *         steps:       the conversation with the mail server, line by
     *                      line, with the sign-in lines replaced. For "Send
     *                      test" only: it holds e-mail addresses too.
     *         certificate: true when the server's certificate is what failed.
     */
    public static function sendSmtp(array $to, string $subject, string $text, string $html, array $files = [], array $again = []): array
    {
        $route  = self::open();
        $answer = [
            'sent' => false, 'outcome' => Mailer::OUTCOME_NOT_SET_UP, 'reason' => self::NOT_SET_UP, 'code' => null, 'words' => '',
            'from' => $route['from'], 'attachments' => 0, 'steps' => [], 'certificate' => false,
            'next' => self::NEXT_NOT_SET_UP, 'stage' => SendResult::STAGE_CONFIG, 'messageId' => '', 'routeFault' => true,
        ];
        $client = $route['client'];
        if ($client === null) {
            $answer['words'] = 'Not sent: the SMTP mail route is not set up. Still needed: ' . implode('; ', $route['missing']) . '.';

            return $answer;
        }

        // 🧱 Build the message. The library refuses anything that cannot
        //    safely become an e-mail (a line break in a subject or a name, an
        //    address it cannot send to, a file it cannot read), and its
        //    refusal never repeats the bad value.
        try {
            $attachments = [];
            foreach ($files as $path) {
                $attachments[] = Message::fileAttachment((string) $path);
            }
            $message = new Message(
                // A message from the queue keeps the moment it was written;
                // a new one is dated now.
                (int) ($again['date'] ?? 0) > 0 ? (int) $again['date'] : (new SystemClock())->now(),
                $route['from'],
                $route['fromName'],
                array_values($to),
                $subject,
                // The library insists on both versions. A message whose HTML
                // holds no words at all (a picture alone) would otherwise be
                // refused, where the other routes send it.
                trim($text) === '' ? '(This message has no plain-text version.)' : $text,
                $html,
                $route['replyTo'],
                $attachments,
                // The identifier it was first tried under, or '' for a new one.
                (string) ($again['messageId'] ?? ''),
            );
            $message->size();
        } catch (MessageRefused $refusal) {
            $answer['outcome']    = Mailer::OUTCOME_REFUSED;
            $answer['reason']     = self::MESSAGE_REFUSED;
            $answer['next']       = self::NEXT_REFUSED;
            $answer['stage']      = SendResult::STAGE_MESSAGE;
            $answer['routeFault'] = false;
            $answer['words']      = 'Not sent: the message could not be made into an e-mail. ' . $refusal->getMessage();

            return $answer;
        }
        $answer['attachments'] = $message->attachmentCount();
        $answer['messageId']   = $message->messageId();

        // 🔐 A sign-in the mail server has already refused in this request is
        //    NOT offered again. This used to sign in afresh for every message:
        //    a reminder run for 200 people with an out-of-date password was
        //    200 refused sign-ins within seconds, which is what gets a mailbox
        //    locked by its provider. The password cannot change part way
        //    through a request, so the answer would be the same each time.
        //    Each later message is answered here, without contacting the mail
        //    server, and its line in the send log says why.
        //
        //    FROM ONE REQUEST TO THE NEXT the refusal is on record in the
        //    database (see noteResult() and standingProblem()): Mailer keeps
        //    a message in the queue without coming here at all, and the mail
        //    job signs in again only after a wait. This flag is what covers
        //    the rest of the request in which the refusal was first met.
        if (self::$signInRefused === true) {
            $answer['outcome'] = Mailer::OUTCOME_REFUSED;
            $answer['reason']  = self::SIGN_IN_NOT_REPEATED;
            $answer['next']    = self::NEXT_SIGN_IN;
            $answer['stage']   = SendResult::STAGE_AUTH;
            $answer['words']   = 'Not sent, and the mail server was not contacted. It refused the mailbox\'s user name and password for an '
                . 'earlier message sent in the same run, and offering a refused password again and again can get a mailbox locked. '
                . 'Check both, and type the password in again at Admin, Integrations, Email.';

            return $answer;
        }

        $result = $client->send($message);
        // ONLY a refused sign-in is remembered: the mail server said no (a
        // reply beginning with 5) to the user name and password. A refusal of
        // one message (its sender, a recipient, its size) says nothing about
        // the next message, and a failure that may pass (the server said
        // "later", did not answer, could not be reached) is the mail
        // library's to count: see the note on forSite().
        if ($result->outcome === SendResult::REFUSED && $result->stage === SendResult::STAGE_AUTH) {
            self::$signInRefused = true;
        }

        // 📒 What this attempt showed about the ROUTE goes on record, for
        //    every later request and run: see noteResult().
        self::noteResult($result);

        $answer['steps']       = $result->transcript;
        $answer['code']        = $result->code > 0 ? $result->code : null;
        $answer['reason']      = $result->reason;
        $answer['certificate'] = $result->reason === SendResult::REASON_CERTIFICATE;
        $answer['stage']       = $result->stage;
        $answer['next']        = self::whatNext($result, ($again['repeatSafe'] ?? false) === true);
        $answer['routeFault']  = $result->accepted() === false
            && ($result->isAboutTheMessage() === false || $result->stage === SendResult::STAGE_SENDER);
        if ($result->accepted() === true) {
            $answer['sent']    = true;
            $answer['outcome'] = Mailer::OUTCOME_SENT;
            $answer['words']   = 'The mail server accepted the message.';

            return $answer;
        }
        // 🚫 Not accepted. `outcome` is the word "Send test" shows, as it
        //    always was: 'refused' for a "no" that the same attempt would
        //    meet again, 'not-sent' for everything else. What Mailer and the
        //    mail queue DO about it is in `next`.
        $answer['outcome'] = $result->outcome === SendResult::REFUSED ? Mailer::OUTCOME_REFUSED : Mailer::OUTCOME_NOT_SENT;
        $answer['words']   = self::inWords($result);

        return $answer;
    }

    /**
     * 🧭 What should become of a message after this attempt. THE ONE PLACE
     * the mail library's four outcomes are turned into what the portal does.
     *
     *   accepted                          -> sent.
     *   the certificate failed its check  -> held, with every other message,
     *                                        until it passes.
     *   handed over whole, and no answer  -> NOBODY CAN TELL whether it went.
     *                                        Held for a person; tried again
     *                                        only when a second copy does no
     *                                        harm.
     *   "try later"                       -> kept and tried again.
     *   refused at the SIGN-IN            -> about the mailbox, not the
     *                                        message: it waits.
     *   refused BEFORE the message itself -> kept and tried again. A "no"
     *     (the greeting, the encryption,     that arrives before encryption
     *     the sender)                        is on can be forged by somebody
     *                                        on the network path, and a "no"
     *                                        to the sender is very often
     *                                        about the mailbox (a daily
     *                                        limit, "may not send as"). Mail
     *                                        is never thrown away on those.
     *   refused for a recipient, or for   -> refused: about THIS message. It
     *     the message's content or size      is never tried again.
     *
     * REJECTED: treating every reply beginning with 5 as final. A mailbox
     * that had reached its provider's daily limit would then have thrown
     * away every message given to it that day.
     */
    private static function whatNext(SendResult $result, bool $repeatSafe): string
    {
        if ($result->accepted() === true) {
            return self::NEXT_SENT;
        }
        if ($result->outcome === SendResult::NEEDS_PERSON) {
            if ($result->reason === SendResult::REASON_CERTIFICATE) {
                return self::NEXT_HOLD_CERTIFICATE;
            }

            return $repeatSafe === true ? self::NEXT_LATER : self::NEXT_HOLD_UNKNOWN;
        }
        if ($result->outcome !== SendResult::REFUSED) {
            return self::NEXT_LATER;
        }
        if ($result->stage === SendResult::STAGE_AUTH) {
            return self::NEXT_SIGN_IN;
        }

        return ($result->isAboutTheMessage() === false || $result->stage === SendResult::STAGE_SENDER) ? self::NEXT_LATER : self::NEXT_REFUSED;
    }

    /**
     * 🆔 A new identifier for a message that is being kept in the mail queue,
     * in the form the mail library makes for itself: a random part, then the
     * domain of the "from" address.
     *
     * Made WHEN THE MESSAGE IS KEPT, and not at its first attempt, so that
     * every attempt there ever is carries the same one. (It was first stored
     * after the first attempt. A run that died just after the mail server
     * took the message had then not stored it, and a second copy, where one
     * is allowed, would have gone out under a different identifier, which is
     * the one case the identifier is kept for.)
     *
     * '' when there is no usable "from" address: the route is then not set
     * up, and the mail library makes one at the first attempt.
     */
    public static function newMessageId(): string
    {
        $domain = (string) substr((string) strrchr(self::value('mail.defaultFromAddress'), '@'), 1);
        if (preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $domain) !== 1) {
            return '';
        }

        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }

    /**
     * The words for the step an attempt stopped at, to finish the sentence
     * "It stopped while …". For the health page, which shows no reply of the
     * mail server's (a reply can name an e-mail address).
     */
    public static function stoppedWhile(string $stage): string
    {
        return self::STOPPED_WHILE[$stage] ?? 'at a step the portal has no name for';
    }

    /**
     * What a failed send comes to, in words an administrator can act on.
     *
     * The library gives the facts (where it stopped, why, and the mail
     * server's own reply). A reply such as "535 5.7.8 credentials invalid" is
     * true but is not plain, so a plain sentence is put in front of it and
     * the server's words are kept after it, for whoever runs the mail server.
     */
    private static function inWords(SendResult $result): string
    {
        // 🪪 A failed certificate: the library's sentence is already whole and
        //    plain (and, when it could not read the certificate a second time
        //    to say what is wrong with it, says exactly that). It is kept as
        //    it stands, so that the send log's 500 characters hold all of it.
        if ($result->reason === SendResult::REASON_CERTIFICATE) {
            return $result->serverWords;
        }
        $while = 'It stopped while ' . (self::STOPPED_WHILE[$result->stage] ?? 'at the step ' . $result->stage) . '.';
        if ($result->reason === SendResult::REASON_UNKNOWN_IF_SENT) {
            // The one case where "not sent" would be untrue.
            return 'Nobody can tell whether this message went: something failed after the whole message had been handed to the '
                . 'mail server. It is not sent again automatically. ' . $result->serverWords;
        }
        if ($result->code === 0) {
            // The server gave no reply to quote: the words are the library's own, and already plain.
            return 'Not sent. ' . $while . ' ' . $result->serverWords;
        }
        // "Later" is asked FIRST. It used to be asked last, so a mail server
        // that said "try again later" while naming the sender or a recipient
        // (a busy mailbox, greylisting) was reported as having REFUSED the
        // "from" address or a recipient's address, which it had not.
        $lead = 'The mail server refused the message.';
        if ($result->outcome === SendResult::TRY_LATER) {
            $lead = 'The mail server asked for the message to be sent again later.';
        } elseif ($result->stage === SendResult::STAGE_AUTH) {
            $lead = 'The mail server refused the user name and password. Check both, and type the password in again.';
        } elseif ($result->stage === SendResult::STAGE_SENDER) {
            $lead = 'The mail server refused the "from" address. The mailbox may not be allowed to send as that address.';
        } elseif ($result->stage === SendResult::STAGE_RECIPIENT) {
            $lead = "The mail server refused a recipient's address.";
        }

        return 'Not sent. ' . $lead . ' ' . $while . ' The mail server said: ' . $result->code . ' ' . $result->serverWords;
    }

    // =========================================================================
    // 📒 What is on record about the route (tblMailRouteState)
    // =========================================================================

    /**
     * A fingerprint of the settings a fault was met with: which route is
     * chosen, the mail server, the port, the encryption, the user name and
     * the stored (encrypted) password. When any of them changes, a fault on
     * record is no longer assumed, so a corrected password is tried at once.
     * The password's stored form changes on every save, even when the same
     * password is typed again, which is what an administrator saving it
     * again would expect.
     */
    public static function settingsMark(): string
    {
        $parts = [];
        foreach (['mail.provider', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.security', 'mail.smtp.username', 'mail.smtp.password'] as $key) {
            $parts[] = self::rows()[$key]['value'] ?? '';
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * The route's row as stored, or a row of empty values when there is none
     * yet. `tryDue` is 1 when a wait after a refused sign-in is over, worked
     * out on the database's own UTC clock.
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable When the table cannot be read (the database has not
     *                    been upgraded to hold it).
     */
    public static function state(mysqli $db): array
    {
        $key  = self::ROUTE_KEY;
        $stmt = $db->prepare(
            'SELECT problem, problemWords, problemSince, problemSeenAt, settingsMark, tries, nextTryAt, certReasons, certNames, certSubject, certIssuer, '
            . 'certValidFrom, certValidTo, certSha256, encryptionFailures, jobLastRanAt, (nextTryAt IS NULL OR nextTryAt <= UTC_TIMESTAMP()) AS tryDue '
            . 'FROM tblMailRouteState WHERE routeKey = ?'
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?? [
            'problem' => '', 'problemWords' => '', 'problemSince' => null, 'problemSeenAt' => null, 'settingsMark' => '', 'tries' => 0,
            'nextTryAt' => null, 'certReasons' => '', 'certNames' => '', 'certSubject' => '', 'certIssuer' => '', 'certValidFrom' => null,
            'certValidTo' => null, 'certSha256' => '', 'encryptionFailures' => 0, 'jobLastRanAt' => null, 'tryDue' => 1,
        ];
    }

    /**
     * 🚧 The fault on record for the route, if there is one that still
     * stands. Mailer asks before every send, and the mail job before every
     * run. Read once a request.
     *
     * A fault recorded under OTHER settings no longer stands: it is cleared
     * here, and mail held for a certificate is released, so that changing
     * the mail server or typing the password again starts mail off again
     * without anybody having to press anything else.
     *
     * Never throws. If the record cannot be read, the answer is "nothing on
     * record": a send is then tried in the ordinary way.
     *
     * @return array{problem: string, words: string, since: string, seenAt: string, tryNow: bool}
     *         problem: '' or one of the PROBLEM_ values.
     *         since, seenAt: moments in UTC, 'Y-m-d H:i:s'.
     *         tryNow:  for a refused sign-in, true once the wait before the
     *                  next try is over.
     */
    public static function standingProblem(mysqli $db): array
    {
        if (self::$standing !== null) {
            return self::$standing;
        }
        $none = ['problem' => '', 'words' => '', 'since' => '', 'seenAt' => '', 'tryNow' => true];
        try {
            $state = self::state($db);
            if ((string) $state['problem'] === '') {
                return self::$standing = $none;
            }
            if (hash_equals((string) $state['settingsMark'], self::settingsMark()) === false) {
                self::clearProblem($db, (string) $state['problem']);

                return self::$standing = $none;
            }

            return self::$standing = [
                'problem' => (string) $state['problem'],
                'words'   => (string) $state['problemWords'],
                'since'   => (string) ($state['problemSince'] ?? ''),
                'seenAt'  => (string) ($state['problemSeenAt'] ?? ''),
                'tryNow'  => (int) $state['tryDue'] === 1,
            ];
        } catch (\Throwable $e) {
            return $none; // not kept: the next call asks again
        }
    }

    /**
     * @var int How long one request's claim on "the next try after a refused
     *      sign-in" lasts, in seconds: see claimSignInTry(). Two minutes: a
     *      single send is given up on after about 50 seconds, and the job
     *      comes round every five minutes.
     */
    private const SIGN_IN_TRY_CLAIM_SECONDS = 120;

    /**
     * 🔐 CLAIM THE ONE TRY that is due after a refused sign-in. True for the
     * ONE request that may now sign in again; false for every other.
     *
     * WHY. Once the wait after a refused sign-in is over, whoever sends next
     * tries the mail server again. "Whoever" was not one: twelve pages that
     * sent at the same moment each saw "the wait is over" and each signed in,
     * twelve refused sign-ins in a second, which is exactly what gets a
     * mailbox locked by its provider.
     *
     * HOW. ONE statement moves "not before" on by two minutes, and only
     * where it is not already in the future. The database gives that change
     * to one statement only: the request whose statement changed the row has
     * the try, and the rest find the moment already moved and do not contact
     * the mail server. REJECTED: reading "is the wait over?" and then
     * writing. Two requests both read "yes" before either has written.
     *
     * What the try then shows replaces the two minutes: refused again, the
     * next wait (noteResult()); signed in, the record is cleared. If the try
     * never reaches the sign-in (the server cannot be reached), the two
     * minutes stand, and the next request after them may try.
     *
     * Never throws. If the claim cannot be made (the record cannot be
     * written), the answer is false: nobody signs in, and mail waits.
     */
    public static function claimSignInTry(mysqli $db): bool
    {
        try {
            $key     = self::ROUTE_KEY;
            $problem = self::PROBLEM_SIGN_IN;
            $hold    = self::SIGN_IN_TRY_CLAIM_SECONDS;
            $stmt    = $db->prepare(
                'UPDATE tblMailRouteState SET nextTryAt = UTC_TIMESTAMP() + INTERVAL ? SECOND '
                . 'WHERE routeKey = ? AND problem = ? AND (nextTryAt IS NULL OR nextTryAt <= UTC_TIMESTAMP())'
            );
            $stmt->bind_param('iss', $hold, $key, $problem);
            $stmt->execute();
            $won = $stmt->affected_rows === 1;
            $stmt->close();
            // What this request read about the route is now out of date.
            self::$standing = null;

            return $won;
        } catch (\Throwable $e) {
            error_log('MailRoutes: the try after a refused sign-in could not be claimed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Take the fault off the record. When it was the certificate, the mail
     * held for it goes back into the queue, due at once.
     */
    private static function clearProblem(mysqli $db, string $was): void
    {
        $key  = self::ROUTE_KEY;
        $stmt = $db->prepare(
            "UPDATE tblMailRouteState SET problem = '', problemWords = '', problemSince = NULL, problemSeenAt = NULL, settingsMark = '', tries = 0, nextTryAt = NULL, "
            . "certReasons = '', certNames = '', certSubject = '', certIssuer = '', certValidFrom = NULL, certValidTo = NULL, certSha256 = '' WHERE routeKey = ?"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
        self::$standing = null;
        if ($was === self::PROBLEM_CERTIFICATE) {
            MailQueue::releaseHeldForCertificate($db);
        }
    }

    /**
     * 📒 Put on record what one attempt showed about the ROUTE. Called for
     * every attempt through SMTP: a real send, the mail job, and "Send test".
     *
     *   The certificate failed its check   -> on record, with its facts for
     *                                         the page package M4 adds; every
     *                                         queued message is held.
     *   The sign-in was refused            -> on record, and the wait before
     *                                         the next try grows.
     *   The attempt got PAST a step that
     *   is on record as failing            -> that record is cleared: a
     *                                         connection that reached the
     *                                         sign-in had a certificate that
     *                                         passed, and one that reached
     *                                         the message had signed in.
     *   Encryption could not be switched
     *   on, and the certificate was not
     *   blamed                             -> counted. Somebody on the
     *                                         network path can cause this at
     *                                         will, and a future PHP may word
     *                                         a refused certificate in a way
     *                                         the mail library does not know,
     *                                         so a run of them is reported on
     *                                         the health page without holding
     *                                         mail as a certificate fault does.
     *
     * A write is made only when something changes, so an ordinary accepted
     * message costs one small read and no write.
     *
     * Never throws: a record that cannot be kept must not stop mail. What it
     * could not write goes to the server's error log.
     */
    private static function noteResult(SendResult $result): void
    {
        try {
            $db    = App::db();
            $state = self::state($db);
            $key   = self::ROUTE_KEY;
            $mark  = self::settingsMark();
            $was   = (string) $state['problem'];
            $words = mb_substr($result->serverWords, 0, 1000);

            // 🪪 The certificate failed.
            if ($result->reason === SendResult::REASON_CERTIFICATE) {
                $facts  = $result->certificate;
                $utc    = static fn (int $moment): ?string => $moment > 0 ? gmdate('Y-m-d H:i:s', $moment) : null;
                $values = [
                    mb_substr(implode(',', $facts === null ? [] : $facts->reasons), 0, 255),
                    mb_substr(implode(',', $facts === null ? [] : $facts->names), 0, 1000),
                    mb_substr($facts === null ? '' : $facts->subject, 0, 300),
                    mb_substr($facts === null ? '' : $facts->issuer, 0, 300),
                    $facts === null ? null : $utc($facts->validFrom),
                    $facts === null ? null : $utc($facts->validTo),
                    $facts === null ? '' : strtolower($facts->sha256),
                ];
                $problem = self::PROBLEM_CERTIFICATE;
                $stmt    = $db->prepare(
                    'INSERT INTO tblMailRouteState (routeKey, problem, problemWords, problemSince, problemSeenAt, settingsMark, certReasons, certNames, certSubject, certIssuer, '
                    . 'certValidFrom, certValidTo, certSha256) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE problemSince = IF(problem = VALUES(problem), problemSince, VALUES(problemSince)), problem = VALUES(problem), '
                    . 'problemWords = VALUES(problemWords), problemSeenAt = VALUES(problemSeenAt), settingsMark = VALUES(settingsMark), tries = 0, nextTryAt = NULL, '
                    . 'certReasons = VALUES(certReasons), certNames = VALUES(certNames), certSubject = VALUES(certSubject), certIssuer = VALUES(certIssuer), '
                    . 'certValidFrom = VALUES(certValidFrom), certValidTo = VALUES(certValidTo), certSha256 = VALUES(certSha256)'
                );
                $stmt->bind_param('sssssssssss', $key, $problem, $words, $mark, ...$values);
                $stmt->execute();
                $stmt->close();
                self::$standing = null;
                // Everything still queued would meet the same refusal.
                MailQueue::holdAllForCertificate($db, $words);

                return;
            }

            // 🔐 The sign-in was refused. (A connection that got this far had
            //    a certificate that passed, so a certificate record goes.)
            if ($result->outcome === SendResult::REFUSED && $result->stage === SendResult::STAGE_AUTH) {
                if ($was === self::PROBLEM_CERTIFICATE) {
                    self::clearProblem($db, $was);
                    $state = self::state($db);
                }
                $tries   = (string) $state['problem'] === self::PROBLEM_SIGN_IN ? (int) $state['tries'] + 1 : 1;
                $wait    = self::SIGN_IN_WAITS[min($tries, count(self::SIGN_IN_WAITS)) - 1];
                $problem = self::PROBLEM_SIGN_IN;
                $said    = mb_substr('The mail server said: ' . ($result->code > 0 ? $result->code . ' ' : '') . $result->serverWords, 0, 1000);
                $stmt    = $db->prepare(
                    'INSERT INTO tblMailRouteState (routeKey, problem, problemWords, problemSince, problemSeenAt, settingsMark, tries, nextTryAt) '
                    . 'VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND) '
                    . 'ON DUPLICATE KEY UPDATE problemSince = IF(problem = VALUES(problem), problemSince, VALUES(problemSince)), problem = VALUES(problem), '
                    . 'problemWords = VALUES(problemWords), problemSeenAt = VALUES(problemSeenAt), settingsMark = VALUES(settingsMark), tries = VALUES(tries), '
                    . 'nextTryAt = VALUES(nextTryAt), encryptionFailures = 0'
                );
                $stmt->bind_param('ssssii', $key, $problem, $said, $mark, $tries, $wait);
                $stmt->execute();
                $stmt->close();
                self::$standing = null;

                return;
            }

            // 🔒 Encryption could not be switched on, and it was not the certificate.
            if ($result->reason === SendResult::REASON_ENCRYPTION_FAILED) {
                $stmt = $db->prepare(
                    'INSERT INTO tblMailRouteState (routeKey, encryptionFailures) VALUES (?, 1) '
                    . 'ON DUPLICATE KEY UPDATE encryptionFailures = LEAST(encryptionFailures + 1, 60000)'
                );
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $stmt->close();

                return;
            }

            // ✅ How far did this attempt get? Past the sign-in, both records
            //    are disproved; at the sign-in or past the encryption, the
            //    certificate's is. An attempt that never reached the
            //    encryption (the server could not be reached) shows nothing.
            $pastSignIn = $result->accepted() === true
                || in_array($result->stage, [SendResult::STAGE_RESET, SendResult::STAGE_SENDER, SendResult::STAGE_RECIPIENT, SendResult::STAGE_DATA, SendResult::STAGE_BODY], true) === true;
            $pastEncryption = $pastSignIn === true || $result->stage === SendResult::STAGE_AUTH;
            if ($pastEncryption === false) {
                return;
            }
            if (($was === self::PROBLEM_CERTIFICATE) || ($was === self::PROBLEM_SIGN_IN && $pastSignIn === true)) {
                self::clearProblem($db, $was);
            }
            if ((int) $state['encryptionFailures'] > 0) {
                $stmt = $db->prepare('UPDATE tblMailRouteState SET encryptionFailures = 0 WHERE routeKey = ?');
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('MailRoutes: what the attempt showed about the mail route could not be recorded: ' . $e->getMessage());
        }
    }

    /**
     * 🕰️ Record that the mail queue's SCHEDULED JOB has just run (a moment in
     * UTC, from the database's clock).
     *
     * ONLY the job's own page calls this (`_apps/cron/mail-queue.php`), once
     * the scheduler's token has been accepted. MailQueue::run() used to call
     * it, and an event broadcast makes a run of its own: after one broadcast
     * the health page read "the job last ran at …" on an installation that
     * had no job. What the moment is used for: MailQueue::jobIsRunning().
     */
    public static function jobRan(mysqli $db): void
    {
        $key  = self::ROUTE_KEY;
        $stmt = $db->prepare('INSERT INTO tblMailRouteState (routeKey, jobLastRanAt) VALUES (?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE jobLastRanAt = UTC_TIMESTAMP()');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 🪪 The certificate problem on record, for the e-mail page's notice.
     *
     * UNTIL PACKAGE M3 this was read from the send log: the newest SMTP row
     * that got as far as the mail server. With a queue that no longer works:
     * while a certificate fault stands, new messages are held WITHOUT the
     * mail server being tried, so the newest row is no longer an attempt.
     * It is now the record itself (see noteResult()). The notice stays until
     * a connection passes the check, or the mail settings change. A message
     * stopped before the mail server is tried (the hourly limit, a missing
     * setting) does not touch it.
     *
     * WHAT IT CANNOT DO: it knows only what was true at the last attempt. A
     * certificate put right since still shows here until the mail job's next
     * run, or a Send test.
     *
     * @return array{words: string, at: string}|null `at` is the moment it was
     *         last seen, in UTC, 'Y-m-d H:i:s'. Null when nothing is on
     *         record, or the record cannot be read.
     */
    public static function certificateProblem(mysqli $db): ?array
    {
        $problem = self::standingProblem($db);

        return $problem['problem'] === self::PROBLEM_CERTIFICATE ? ['words' => $problem['words'], 'at' => $problem['seenAt']] : null;
    }

    // =========================================================================
    // 💾 Saving (the e-mail page only)
    // =========================================================================

    /**
     * Check and save the SMTP form. ALL of it is saved, or none of it.
     *
     * The caller (the e-mail page) has already checked the form's token and
     * that the person is a global administrator. This method does not: it has
     * no way to know who is asking.
     *
     * An empty box is allowed for everything: a half-filled form can be saved
     * and finished later, and Mailer::sendingRoute() says what is still
     * needed. What IS refused is a value that could never be right.
     *
     * The password:
     *   - a new one typed: stored encrypted, marked as a secret;
     *   - the box left empty: the stored one is KEPT, untouched;
     *   - "remove" ticked: the stored one is emptied, still marked as a secret;
     *   - both a new one and "remove": refused, because the two contradict.
     *
     * @param array<string, mixed> $input The form's fields: host, port,
     *        security, username, password, removePassword, fromAddress,
     *        fromName, replyTo, perHour.
     *
     * @return array{errors: list<string>, password: string} `errors` empty
     *         means saved. `password` says what happened to the stored one:
     *         'kept', 'stored' or 'removed' (for the activity log; never the
     *         password itself).
     */
    public static function saveSmtp(array $input): array
    {
        $raw  = static fn (string $field): string => is_string($input[$field] ?? null) === true ? $input[$field] : '';
        $text = static fn (string $field): string => trim($raw($field));

        $host        = strtolower($text('host'));
        $port        = $text('port');
        $security    = strtolower($text('security'));
        $username    = $text('username');
        $fromAddress = $text('fromAddress');
        $fromName    = $text('fromName');
        $replyTo     = $text('replyTo');
        $perHour     = $text('perHour');
        // 🔑 The password is NOT trimmed: a space at either end can be part of it.
        $newPassword = $raw('password');
        $remove      = isset($input['removePassword']) === true;
        $errors      = [];

        // 🚫 A line break or other control character, looked for in what was
        //    sent BEFORE it is trimmed, so one at the very end is refused too
        //    and not quietly removed. One in the middle is how a second header
        //    line is smuggled into an e-mail ("Office", a line break, "Bcc:
        //    somebody"). The mail library refuses such a message when it is
        //    sent; refusing it here tells the administrator at the moment they
        //    can put it right.
        $labels = [
            'host' => "The mail server's name", 'username' => 'The user name', 'fromAddress' => 'The "from" address',
            'fromName' => 'The "from" name', 'replyTo' => 'The reply-to address',
        ];
        foreach ($labels as $field => $label) {
            if (preg_match('/[\x00-\x1F\x7F]/', $raw($field)) === 1) {
                $errors[] = $label . ' contains a line break or another character that cannot be typed. Nothing was saved.';
            }
        }
        if ($host !== '' && (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || strlen($host) > 253)) {
            $errors[] = "The mail server's name must be a server name such as your mail provider gives: letters, digits, dots and hyphens only, with no \"https://\" and no port.";
        }
        if ($port !== '' && (preg_match('/\A[0-9]{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535)) {
            $errors[] = 'The port must be a number from 1 to 65535. Most mail providers use 587.';
        }
        if ($security !== '' && in_array($security, self::SECURITY_CHOICES, true) === false) {
            $errors[] = 'Choose one of the two kinds of encryption. The portal does not send e-mail without encryption.';
        }
        if (strlen($username) > 320) {
            $errors[] = 'The user name is too long.';
        }
        if ($fromAddress !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'The "from" address is not an e-mail address.';
        }
        if (mb_strlen($fromName) > 200) {
            $errors[] = 'The "from" name is too long (200 characters at most).';
        }
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'The reply-to address is not an e-mail address.';
        }
        if ($perHour !== '' && (preg_match('/\A[0-9]{1,7}\z/', $perHour) !== 1 || (int) $perHour < 1 || (int) $perHour > self::LARGEST_LIMIT)) {
            $errors[] = 'The hourly limit must be a whole number from 1 to ' . self::LARGEST_LIMIT . ', or left empty for no limit.';
        }
        if ($newPassword !== '' && $remove === true) {
            $errors[] = 'Either type a new password, or tick the box to remove the stored one. Not both.';
        }
        if ($newPassword !== '' && (preg_match('/[\x00-\x1F\x7F]/', $newPassword) === 1 || strlen($newPassword) > 1000)) {
            $errors[] = 'The password contains a character that cannot be typed, or is longer than 1,000 characters.';
        }
        // The library's own, stricter rules for a sender, asked only when the
        // simpler checks above found nothing (or the same fault is said twice).
        if ($errors === [] && $fromAddress !== '') {
            $problem = self::senderProblem($fromAddress, $fromName, $replyTo);
            if ($problem !== '') {
                $errors[] = $problem;
            }
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'password' => 'kept'];
        }

        // 🔐 Encrypt BEFORE anything is written, so that a missing key file
        //    stops the save with nothing half done.
        $rows = [
            'mail.smtp.host'          => [$host, 0],
            'mail.smtp.port'          => [$port, 0],
            'mail.smtp.security'      => [$security, 0],
            'mail.smtp.username'      => [$username, 0],
            'mail.defaultFromAddress' => [$fromAddress, 0],
            'mail.defaultFromName'    => [$fromName, 0],
            'mail.defaultReplyTo'     => [$replyTo, 0],
            'mail.limit.perHour'      => [$perHour, 0],
        ];
        $passwordChange = 'kept';
        if ($newPassword !== '') {
            try {
                $rows['mail.smtp.password'] = [Auth::encrypt($newPassword), 1];
            } catch (\Throwable $e) {
                // The exception names the key file's path, never the password.
                error_log('MailRoutes: the password could not be encrypted: ' . $e->getMessage());

                return ['errors' => ["The password could not be encrypted, so nothing was saved. The installation's key file is missing or cannot be read; see the server's error log."], 'password' => 'kept'];
            }
            $passwordChange = 'stored';
        } elseif ($remove === true) {
            $rows['mail.smtp.password'] = ['', 1];
            $passwordChange = 'removed';
        }
        // (The box left empty and "remove" not ticked: no password row is in
        //  $rows at all, so the stored one cannot be touched by this save.)

        $db = App::db();
        try {
            $db->begin_transaction();
            foreach ($rows as $key => [$value, $isSecret]) {
                self::writeRow($db, $key, $value, $isSecret);
            }
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // The connection has gone; there is nothing left to undo on it.
            }
            error_log('MailRoutes: the mail settings could not be saved: ' . $e->getMessage());

            return ['errors' => ['The settings could not be saved because the database refused them. Nothing was changed. The reason is in the server\'s error log.'], 'password' => 'kept'];
        }
        self::forget();

        return ['errors' => [], 'password' => $passwordChange];
    }

    /**
     * Change which route the portal sends through. The caller has checked
     * the form's token and that the person is a global administrator.
     *
     * @param string       $route   What was chosen.
     * @param list<string> $allowed The routes Mailer knows.
     *
     * @return bool False when the choice is not one of the routes, or the
     *              database refused it. Nothing is changed then.
     */
    public static function saveChoice(string $route, array $allowed): bool
    {
        if (in_array($route, $allowed, true) === false) {
            return false;
        }
        try {
            self::writeRow(App::db(), 'mail.provider', $route, 0);
        } catch (\Throwable $e) {
            error_log('MailRoutes: the choice of mail route could not be saved: ' . $e->getMessage());

            return false;
        }
        self::forget();

        return true;
    }

    /**
     * Write one installation-wide setting: change the row that is there, or
     * add it. The "is a secret" mark is written every time, so a row whose
     * mark was removed elsewhere is put right by the next save here.
     *
     * Looked up first and then changed or added, the way the other save pages
     * do it, so it does not depend on which unique keys an older database has.
     */
    private static function writeRow(mysqli $db, string $key, string $value, int $isSecret): void
    {
        $find = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
        $find->bind_param('s', $key);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();

        if (is_array($row) === true) {
            $id   = (int) $row['settingID'];
            $save = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() WHERE settingID = ?');
            $save->bind_param('sii', $value, $isSecret, $id);
        } else {
            $save = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, ?, NULL, NOW())');
            $save->bind_param('ssi', $key, $value, $isSecret);
        }
        $save->execute();
        $save->close();
    }
}
