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
 *   key. Typed into the Settings page's Edit box it is stored in plain text
 *   with the mark removed, and is not used. Saved there by a request that
 *   asks for it to be encrypted and marked, it passes both tests and IS used.
 * - It is read ONLY by password() below, and leaves this class only inside a
 *   `MWBM\Mail\Secret`, which cannot be printed, dumped or serialised.
 * - A stored password that is NOT marked as a secret, or that cannot be
 *   decrypted, is treated as "no password: enter it again". The general
 *   Settings page stores what is typed into it as plain text and removes the
 *   mark (#597); a password typed there is therefore never used. REJECTED:
 *   accepting an unmarked value as it stands. It would work, and it would
 *   leave the mailbox's password readable on the Settings page.
 * - Nothing here logs it, and no message built here contains it.
 *
 * ONE ROUTE FOR THE WHOLE INSTALLATION, FOR NOW
 * ---------------------------------------------
 * The owner's decision (12 October 2026): one mail route, set by a global
 * administrator. forSite() takes an organisation's number so that a route per
 * organisation can be added later without changing its callers; today it
 * ignores the number.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * - There is no queue. A message that cannot be sent now is not kept and not
 *   tried again: the caller is told "not sent" and the attempt is logged.
 * - The mail server's certificate is always checked and there is no way to
 *   switch that off. A certificate that fails stops every message until the
 *   mail server's certificate is put right.
 * - canSend's facts are settings only. They cannot show that the mail server
 *   will accept the mailbox's password: only "Send test" shows that.
 * - The hourly limit is counted from this portal's own log. It knows nothing
 *   of mail the same mailbox sends from anywhere else, and two requests
 *   arriving at the same moment can both be let through at the limit's edge.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
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

    /**
     * ⏱️ How long each step of a send may take, in seconds.
     *
     * Shorter than the library's own defaults (8, 8, 10, 10, 20, 30), on
     * purpose. In this package every send happens inside a web request, with a
     * person or a web server waiting, and many hosts end a request after 30
     * seconds. With these numbers the longest a send can take is 15 seconds up
     * to handing the message over, plus 12 for the server's verdict: 27.
     *
     * The cost: a mail server slower than this is given up on sooner. When the
     * queue arrives (package M3) its job can use the library's defaults.
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
     * 🚦 Would sending to this many more recipients pass the hourly limit?
     *
     * @return string '' when the message may go; otherwise the reason, in
     *                plain words, for the send log and for the administrator.
     */
    public static function overLimit(int $recipients): string
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
            $already = self::recipientsInTheLastHour(App::db());
        } catch (\Throwable $e) {
            // The count could not be made (the log table is missing its new
            // columns, or the database is in trouble). Sending anyway would
            // make the limit a promise that is quietly not kept.
            error_log('MailRoutes: the hourly count could not be made: ' . $e->getMessage());

            return 'Not sent: the portal could not count how much mail went in the last hour, so it cannot tell whether this message is within the limit of ' . $limit . ' recipients an hour.';
        }
        if ($already + $recipients > $limit) {
            return 'Not sent: the limit is ' . $limit . ' recipients an hour, ' . $already . ' were sent to in the last hour, and this message is for ' . $recipients . '.';
        }

        return '';
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
     *
     * @return array{sent: bool, outcome: string, reason: string, code: int|null, words: string, from: string,
     *               attachments: int, steps: list<string>, certificate: bool}
     *         outcome:     one of Mailer's OUTCOME_ values.
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
    public static function sendSmtp(array $to, string $subject, string $text, string $html, array $files = []): array
    {
        $route  = self::open();
        $answer = [
            'sent' => false, 'outcome' => Mailer::OUTCOME_NOT_SET_UP, 'reason' => self::NOT_SET_UP, 'code' => null, 'words' => '',
            'from' => $route['from'], 'attachments' => 0, 'steps' => [], 'certificate' => false,
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
                (new SystemClock())->now(),
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
            );
            $message->size();
        } catch (MessageRefused $refusal) {
            $answer['outcome'] = Mailer::OUTCOME_REFUSED;
            $answer['reason']  = self::MESSAGE_REFUSED;
            $answer['words']   = 'Not sent: the message could not be made into an e-mail. ' . $refusal->getMessage();

            return $answer;
        }
        $answer['attachments'] = $message->attachmentCount();

        // 🔐 A sign-in the mail server has already refused in this request is
        //    NOT offered again. This used to sign in afresh for every message:
        //    a reminder run for 200 people with an out-of-date password was
        //    200 refused sign-ins within seconds, which is what gets a mailbox
        //    locked by its provider. The password cannot change part way
        //    through a request, so the answer would be the same each time.
        //    Each later message is answered here, without contacting the mail
        //    server, and its line in the send log says why.
        //
        //    WHAT THIS CANNOT DO: remember anything from one request to the
        //    next. Every new request, and every run of a scheduled job, still
        //    signs in once and is refused once.
        if (self::$signInRefused === true) {
            $answer['outcome'] = Mailer::OUTCOME_REFUSED;
            $answer['reason']  = self::SIGN_IN_NOT_REPEATED;
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

        $answer['steps']       = $result->transcript;
        $answer['code']        = $result->code > 0 ? $result->code : null;
        $answer['reason']      = $result->reason;
        $answer['certificate'] = $result->reason === SendResult::REASON_CERTIFICATE;
        if ($result->accepted() === true) {
            $answer['sent']    = true;
            $answer['outcome'] = Mailer::OUTCOME_SENT;
            $answer['words']   = 'The mail server accepted the message.';

            return $answer;
        }
        // 🚫 Not accepted. With no queue yet, "try later" and "needs a
        //    person" both come to the same thing for the caller: it did not
        //    go and is not kept. Only a refusal that the same attempt would
        //    meet again is told apart, as 'refused'.
        $answer['outcome'] = $result->outcome === SendResult::REFUSED ? Mailer::OUTCOME_REFUSED : Mailer::OUTCOME_NOT_SENT;
        $answer['words']   = self::inWords($result);

        return $answer;
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
        $lead = 'The mail server refused the message.';
        if ($result->stage === SendResult::STAGE_AUTH) {
            $lead = 'The mail server refused the user name and password. Check both, and type the password in again.';
        } elseif ($result->stage === SendResult::STAGE_SENDER) {
            $lead = 'The mail server refused the "from" address. The mailbox may not be allowed to send as that address.';
        } elseif ($result->stage === SendResult::STAGE_RECIPIENT) {
            $lead = "The mail server refused a recipient's address.";
        } elseif ($result->outcome === SendResult::TRY_LATER) {
            $lead = 'The mail server asked for the message to be sent again later.';
        }

        return 'Not sent. ' . $lead . ' ' . $while . ' The mail server said: ' . $result->code . ' ' . $result->serverWords;
    }

    /**
     * 🪪 The certificate problem on record, if the last attempt through SMTP
     * failed on the mail server's certificate.
     *
     * Read from the send log: the newest SMTP row for a message that got as
     * far as the mail server being tried. One good send after the failure
     * clears it, because the newest such row is then that send. So does a
     * later attempt that fails for another reason (the server cannot be
     * reached, or refuses the sign-in): the certificate is then no longer
     * what the last attempt found.
     *
     * A row for a message that was stopped BEFORE the mail server was tried
     * (the hourly limit, a missing setting, a message that could not be made
     * into an e-mail) is passed over. It used to count as "the newest row",
     * so the notice went after any of those, with the certificate still
     * failing and the page saying it stays until a message is accepted.
     *
     * WHAT IT CANNOT DO: it knows only what was true at the last attempt. A
     * certificate put right since still shows here until something is sent;
     * "Send test" is the way to find out. And a notice that has gone after a
     * different failure does not show that the certificate now passes.
     *
     * @return array{words: string, at: string}|null `at` is a moment in UTC,
     *         'Y-m-d H:i:s', or '' when the row has none.
     */
    public static function certificateProblem(mysqli $db): ?array
    {
        // `errorCode` is never NULL in this table (it defaults to ''), so
        // NOT IN cannot quietly drop a row the way it would for a NULL.
        $stmt = $db->prepare(
            'SELECT status, errorCode, errorDetail, sentAtUtc FROM tblEmailLog WHERE provider = ? AND errorCode NOT IN (?, ?, ?) '
            . 'ORDER BY emailLogID DESC LIMIT 1'
        );
        // The three reasons a message is stopped before the mail server is
        // tried. (Variables, because bind_param() takes them by reference.)
        $provider       = self::SMTP;
        $overLimit      = self::OVER_LIMIT;
        $notSetUp       = self::NOT_SET_UP;
        $messageRefused = self::MESSAGE_REFUSED;
        $stmt->bind_param('ssss', $provider, $overLimit, $notSetUp, $messageRefused);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($row) === false || (string) $row['status'] !== 'failed' || (string) $row['errorCode'] !== SendResult::REASON_CERTIFICATE) {
            return null;
        }

        return ['words' => (string) $row['errorDetail'], 'at' => (string) ($row['sentAtUtc'] ?? '')];
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
