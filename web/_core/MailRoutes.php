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
 * BOTH ARE ABOUT THE SMTP MAILBOX, AND STOP MAIL ONLY WHILE SMTP IS THE
 * ROUTE IN USE. "Send test" tries the SMTP mailbox while Microsoft 365 or
 * Google carries the mail, so that it can be proved first; what such a test
 * finds is put on record and SHOWN (the e-mail page shows the certificate
 * and lets a global administrator decide about it before switching), but it
 * holds nothing and stops nothing. standingProblem() is the one place that
 * says whether a fault stands in the way of mail; certificateOnRecord() and
 * certificateProblem() say what is on record, for showing.
 *
 * THE CERTIFICATE CHECK, AND THE THREE WAYS PAST A FAILED ONE (package M4)
 * ----------------------------------------------------------------------
 * The mail server's certificate is checked in full, always, unless a GLOBAL
 * ADMINISTRATOR has deliberately decided otherwise. A certificate that fails
 * holds every message. The owner (Salem874) decided the three ways through:
 *   1. ONE held message sent past the check, accepting only the certificate
 *      the administrator was shown (sendSmtp()'s `onlyCertificate`). It
 *      changes nothing that is stored.
 *   2. "Accept only this certificate": `mail.smtp.certCheck` holds
 *      `only:<fingerprint>`. Mail goes while the server shows exactly that
 *      certificate; any other holds mail again.
 *   3. Checking switched OFF: `mail.smtp.certCheck` holds `off`.
 * certificateCheck() is THE ONE PLACE that reads that setting, and anything
 * it does not recognise counts as "on": a mistyped value checks, it never
 * stops checking. Encryption is required in every one of them (the library
 * has no way to send without it).
 * CHECKING SWITCHES ITSELF BACK ON. While it is off, or one certificate is
 * accepted, every request that sends through SMTP, and every run of the mail
 * job WHATEVER ROUTE IS IN USE, first asks the mail server once whether its
 * certificate would now pass the full check (recheckCertificate()). When it
 * would, the setting goes back to `on` and a line is written to the
 * activity log.
 * These are for the SMTP route only. Microsoft 365 and Google are reached
 * by https, checked by PHP itself, and get no way through.
 * WHAT IS STORED OUTLIVES A CHANGE OF ROUTE. With `off` stored and
 * Microsoft 365 chosen, a "Send test" through the SMTP mailbox still goes
 * with the check off. So the standing warning, the button that switches
 * checking back on, and the job's question are all tied to what is STORED,
 * never to which route is in use. (They were tied to the route at first:
 * the warning vanished, the button was refused and "off" never lapsed.)
 *
 * THE E-MAIL PAGE IS THE ONE PLACE the mail server's name and the two
 * certificate rows are changed. The general Settings page refuses to add,
 * change or remove them, for everybody: see lockedInGeneralEditor().
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * - With checking off, tell the mail server from an impostor. That is what
 *   switching it off gives up, and the page that offers it says so.
 * - Stop somebody who can write to the DATABASE setting
 *   `mail.smtp.certCheck` to `off` without the warning and the typed server
 *   name the e-mail page asks for, or changing the mail server's name there
 *   and so keeping the stored password and the certificate decision. What
 *   it does do: the same standing warning shows wherever the value came
 *   from, because the warning is read from what is stored.
 * - Tie the stored password to the server it was saved for. saveSmtp() asks
 *   for the password again, and puts checking back on, when the name or the
 *   port is changed on the e-mail page. The port, the user name, the kind
 *   of encryption and the password can still be changed on the general
 *   Settings page, which does neither.
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
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use MWBM\Mail\Client;
use MWBM\Mail\Message;
use MWBM\Mail\MessageRefused;
use MWBM\Mail\Secret;
use MWBM\Mail\SendOptions;
use MWBM\Mail\SendResult;
use MWBM\Mail\Smtp\CertificateReader;
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

    // 🪪 How the mail server's certificate is treated (`mode` in what
    //    certificateCheck() returns). See the file header.
    public const CHECK_ON   = 'on';   // checked in full: the default, and what anything unrecognised means
    public const CHECK_ONLY = 'only'; // one certificate is accepted, by its fingerprint
    public const CHECK_OFF  = 'off';  // not checked at all

    /** @var string What `mail.smtp.certCheck` begins with when one certificate is accepted; the 64-character fingerprint follows. */
    private const CHECK_ONLY_PREFIX = 'only:';

    // 🏷️ The rule one attempt was made under (`rule` in what sendSmtp()
    //    returns), for "Send test" and the activity log to say truthfully.
    public const RULE_CHECKED     = 'checked';
    public const RULE_ONE         = 'one-certificate';
    public const RULE_NOT_CHECKED = 'not-checked';

    // 📒 The activity log's names for the deliberate acts. standingWarning()
    //    reads the newest of the four back, to say who set what is stored.
    public const LOG_ACCEPTED = 'MailCertificateAccepted';
    public const LOG_OFF      = 'MailCertificateCheckOff';
    public const LOG_ON       = 'MailCertificateCheckOn';
    public const LOG_BACK_ON  = 'MailCertificateCheckBackOn';

    /**
     * @var int How far apart, in seconds, the moment stored beside the
     *      certificate setting and the activity log's line may be and still
     *      count as ONE change: see standingWarning(). The page stores the
     *      setting and writes the line in the same request, one straight
     *      after the other, so a minute is generous for a slow host and far
     *      too short for two separate changes to be mistaken for one.
     */
    private const SAME_CHANGE_SECONDS = 60;

    /**
     * @var list<string> The settings the general Settings page must not add,
     *      change or remove, in small letters: see lockedInGeneralEditor().
     */
    private const LOCKED_IN_GENERAL_EDITOR = ['mail.smtp.host', 'mail.smtp.certcheck', 'mail.smtp.certcheckoffat'];

    /** What the general Settings page says when it refuses one of them. Plain text: that page escapes it. */
    public const LOCKED_MESSAGE = 'That setting is changed at Admin, Integrations, Email, and nowhere else. '
        . 'The mail server\'s name and the certificate settings decide which server is given the mailbox\'s password, '
        . 'so the e-mail page asks for the password again when the name changes, and shows a warning and asks for a '
        . 'confirmation before certificate checking is changed. Nothing was changed.';

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

    /**
     * @var string|null What recheckCertificate() found in this request: null
     *      before it has asked, then 'not-needed' (checking is on, or no SMTP
     *      mailbox is set up), 'passes' (and checking was switched back on),
     *      'fails' or 'cannot-tell'.
     */
    private static ?string $rechecked = null;

    /**
     * @var bool True once, in this request, ONE message sent past the check
     *      was refused because the server showed another certificate, and
     *      that other certificate was then found to pass the full check.
     *      See noteResult() and passedAfterRefusal().
     */
    private static bool $passedAfterRefusal = false;

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
        // (`$rechecked` is NOT reset here: the mail server is asked "would
        // your certificate pass now?" once a request, whatever is saved.)
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
     * @param array{messageId?: string, date?: int, repeatSafe?: bool, onlyCertificate?: string} $again
     *        For the mail queue. `messageId` and `date` (seconds since 1970,
     *        UTC) are the ones the message was first tried with, so that every
     *        attempt is the SAME message to a mail system that has seen it
     *        before. `repeatSafe` true means a second copy does no harm.
     *        `onlyCertificate` is for ONE held message a global administrator
     *        is sending past a failed check: the SHA-256 fingerprint of the
     *        certificate they were shown. The connection then accepts that
     *        certificate and no other, whatever `mail.smtp.certCheck` holds,
     *        and nothing this attempt shows clears the fault on record.
     *
     * @return array{sent: bool, outcome: string, reason: string, code: int|null, words: string, from: string,
     *               attachments: int, steps: list<string>, certificate: bool, next: string, stage: string,
     *               messageId: string, routeFault: bool, rule: string}
     *         rule:        how the certificate was treated for this attempt:
     *                      one of the RULE_ values.
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
        // 🪪 ONE message a global administrator is sending past a failed
        //    check names its certificate here; every other send follows the
        //    installation's standing rule (see further down).
        $oneOff   = strtolower(trim((string) ($again['onlyCertificate'] ?? '')));
        $standing = $oneOff === '';
        // 🩺 While that standing rule is not "checked in full", the mail
        //    server is first asked, once a request, whether its certificate
        //    would pass now. If it would, checking has just gone back on.
        //    ASKED BEFORE THE ROUTE IS OPENED, and it must stay there:
        //    switching checking back on forgets the open route and wipes
        //    the password it held, so a route opened first would be sent
        //    through with no password. (Built that way at first: the one
        //    message that found the certificate good again was answered
        //    "not set up" and kept to try later.)
        if ($standing === true) {
            self::recheckCertificate(App::db());
        }
        $route  = self::open();
        $answer = [
            'sent' => false, 'outcome' => Mailer::OUTCOME_NOT_SET_UP, 'reason' => self::NOT_SET_UP, 'code' => null, 'words' => '',
            'from' => $route['from'], 'attachments' => 0, 'steps' => [], 'certificate' => false,
            'next' => self::NEXT_NOT_SET_UP, 'stage' => SendResult::STAGE_CONFIG, 'messageId' => '', 'routeFault' => true,
            'rule' => self::RULE_CHECKED,
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

        // 🪪 HOW THE CERTIFICATE IS TREATED FOR THIS ATTEMPT. Three cases:
        //    - ONE message a global administrator is sending past a failed
        //      check (`onlyCertificate`): exactly the certificate they were
        //      shown, and no other. This is the fingerprint that MUST reach
        //      the mail library: without it the connection would accept
        //      whatever the server showed at that moment.
        //    - otherwise the installation's standing rule
        //      (certificateCheck()): checked in full, one accepted
        //      certificate, or not checked. (It was looked at again at the
        //      top of this method, and may have just gone back to "checked
        //      in full".)
        $options  = null;
        if ($standing === false) {
            if (SendOptions::isFingerprint($oneOff) === false) {
                // Refused here, in words. (The library refuses it too, by
                // throwing; this never gets that far.)
                $answer['outcome']    = Mailer::OUTCOME_NOT_SENT;
                $answer['reason']     = SendResult::REASON_CERTIFICATE;
                $answer['next']       = self::NEXT_HOLD_CERTIFICATE;
                $answer['stage']      = SendResult::STAGE_TLS;
                $answer['words']      = 'Not sent: no certificate was named to send it through. Nothing was tried.';

                return $answer;
            }
            $options        = SendOptions::onlyThisCertificate($oneOff);
            $answer['rule'] = self::RULE_ONE;
        } else {
            $check = self::certificateCheck();
            if ($check['mode'] === self::CHECK_OFF) {
                $options        = SendOptions::certificateNotChecked();
                $answer['rule'] = self::RULE_NOT_CHECKED;
            } elseif ($check['mode'] === self::CHECK_ONLY) {
                $options        = SendOptions::onlyThisCertificate($check['fingerprint']);
                $answer['rule'] = self::RULE_ONE;
            }
        }

        $result = $client->send($message, $options);
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
        self::noteResult($result, $standing);

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
     * chosen, the mail server, the port, the encryption, the user name, the
     * stored (encrypted) password, and how the certificate is to be treated.
     * When any of them changes, a fault on record is no longer assumed, so a
     * corrected password is tried at once. The password's stored form
     * changes on every save, even when the same password is typed again,
     * which is what an administrator saving it again would expect.
     *
     * `mail.smtp.certCheck` is part of it (package M4) so that accepting a
     * certificate, or switching checking off, releases the mail held for the
     * certificate WHEREVER the setting was changed: on the e-mail page, or
     * straight in the database, which knows nothing of the queue.
     */
    public static function settingsMark(): string
    {
        $parts = [];
        foreach (['mail.provider', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.security', 'mail.smtp.username', 'mail.smtp.password', 'mail.smtp.certCheck'] as $key) {
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
     * 🚧 THE FAULT THAT STANDS IN THE WAY OF MAIL NOW, if there is one.
     * Mailer asks before every send, the mail job before every run, the
     * e-mail page before it sends a held message again, and the health page.
     *
     * ONLY WHILE SMTP IS THE ROUTE IN USE. What is on record is about the
     * SMTP mailbox. "Send test" tries that mailbox whatever route is in use,
     * so that it can be proved before the portal is switched to it, and what
     * the test finds goes on record like any other attempt. With Microsoft
     * 365 or Google in use that record says nothing about the mail that is
     * going out, so this answers "nothing". WHAT USED TO HAPPEN: this
     * answered whatever was on record. One test with a wrong password, made
     * while the mailbox was being set up, stopped the job's runs until its
     * wait was over; one that failed the certificate check held every
     * queued message; and the health line went red. All of that mail was
     * going by the other route.
     * WHAT IS ON RECORD is still there to be SHOWN, whatever the route: see
     * onRecord(), certificateProblem() and certificateOnRecord().
     * WHEN THE PORTAL IS SWITCHED TO SMTP, a record made by a test under the
     * other route was made under other settings (the route is part of
     * settingsMark()), so it is cleared like any such record, and the fault
     * is found again by the first message: that message is held, or waits,
     * and the mail server is given nothing it should not have. The
     * certificate is still checked in full; nothing is trusted meanwhile.
     *
     * Never throws.
     *
     * @return array{problem: string, words: string, since: string, seenAt: string, tryNow: bool}
     *         problem: '' or one of the PROBLEM_ values.
     *         since, seenAt: moments in UTC, 'Y-m-d H:i:s'.
     *         tryNow:  for a refused sign-in, true once the wait before the
     *                  next try is over.
     */
    public static function standingProblem(mysqli $db): array
    {
        // Read FIRST, whatever the route: reading is also what clears a
        // record made under other settings and releases the mail held for it.
        $found = self::onRecord($db);

        return self::chosenRoute() === self::SMTP ? $found : ['problem' => '', 'words' => '', 'since' => '', 'seenAt' => '', 'tryNow' => true];
    }

    /**
     * 📒 The fault ON RECORD for the SMTP mailbox, if there is one that
     * still stands, WHATEVER ROUTE IS IN USE. For showing, and for
     * standingProblem() above, which is the one to ask before sending
     * anything. Read once a request.
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
     *         As standingProblem().
     */
    private static function onRecord(mysqli $db): array
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
     * AN ATTEMPT MADE FOR ONE MESSAGE, PAST THE CHECK (`$standing` false:
     * "send this one message with the check skipped") proves nothing about
     * the route as it normally is. The connection accepted one certificate
     * because a person said so, for that message. So such an attempt can PUT
     * a certificate fault on record (the server showed a different
     * certificate from the one that was approved), and getting THROUGH
     * clears nothing. REJECTED while this was designed: treating it like any
     * other attempt that got past the encryption. Clearing the fault
     * releases every held message, so "send this ONE" would have put the
     * whole queue back in line for the job's next run, and taken the notices
     * down until that run failed the check again.
     * ONE CASE DOES CLEAR THE FAULT, and it is not "the message got through":
     * the server showed a different certificate BECAUSE ITS CERTIFICATE HAS
     * BEEN PUT RIGHT. So after such a refusal the server is asked once, on a
     * fully checked connection, whether its certificate passes now
     * (wouldPassNow()). If it does, the fault goes and held mail is
     * released. WHAT USED TO HAPPEN: the good certificate was put on record
     * as "not the approved certificate", every message was held, new mail
     * was held without the server being tried, and the page offered three
     * ways past a certificate that passes. With no scheduled job it stayed
     * so until somebody pressed another button. "No" and "cannot tell" are
     * recorded as before: somebody on the network path can cause either,
     * and neither lets any mail go.
     *
     * Never throws: a record that cannot be kept must not stop mail. What it
     * could not write goes to the server's error log.
     *
     * @param bool $standing True when the attempt was made under the
     *                       installation's standing rule (certificateCheck()).
     */
    private static function noteResult(SendResult $result, bool $standing = true): void
    {
        try {
            if ($standing === false && $result->reason !== SendResult::REASON_CERTIFICATE) {
                return;
            }
            $db    = App::db();
            $state = self::state($db);
            $key   = self::ROUTE_KEY;
            $mark  = self::settingsMark();
            $was   = (string) $state['problem'];
            $words = mb_substr($result->serverWords, 0, 1000);

            // 🩺 ONE message past the check, refused because the server
            //    showed another certificate. If that other certificate
            //    passes the FULL check, the fault is over: see the note
            //    above this method. (MailQueue::sendOneHeld() then puts the
            //    one message back in the queue with the rest, and says so.)
            if ($standing === false && self::wouldPassNow() === true) {
                self::$passedAfterRefusal = true;
                if ($was === self::PROBLEM_CERTIFICATE) {
                    self::clearProblem($db, $was);
                }

                return;
            }

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
                // Everything still queued would meet the same refusal: but
                // ONLY when it would go through this mailbox. With another
                // route in use this attempt was a "Send test", and what is
                // queued goes by that route. (It used to be held all the
                // same, and then nothing sent it.)
                if (self::chosenRoute() === self::SMTP) {
                    MailQueue::holdAllForCertificate($db, $words);
                }

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
     * 🩺 In this request: was ONE message, sent past the check, refused
     * because the mail server now shows a certificate that PASSES the full
     * check? MailQueue::sendOneHeld() asks, so that what it tells the person
     * is what happened and not a guess from the fault having gone (which a
     * change of the mail settings by somebody else can cause too).
     */
    public static function passedAfterRefusal(): bool
    {
        return self::$passedAfterRefusal;
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
     *         record, or the record cannot be read. WHATEVER ROUTE IS IN
     *         USE: with another route in use it is what a "Send test" found,
     *         and the page says that no mail is held for it.
     */
    public static function certificateProblem(mysqli $db): ?array
    {
        // What is ON RECORD, whatever route is in use: this is for showing.
        $problem = self::onRecord($db);

        return $problem['problem'] === self::PROBLEM_CERTIFICATE ? ['words' => $problem['words'], 'at' => $problem['seenAt']] : null;
    }

    // =========================================================================
    // 🪪 The certificate check: on, one certificate accepted, or off (package M4)
    // =========================================================================

    /**
     * 🪪 HOW THE MAIL SERVER'S CERTIFICATE IS TO BE TREATED. THE ONE PLACE
     * `mail.smtp.certCheck` is read: do not read it anywhere else.
     *
     * The installation's own row only (see the file header: an
     * organisation's row is never a mail setting). What it may hold:
     *   on                  checked in full. Also what an EMPTY value means.
     *   only:<fingerprint>  one certificate is accepted: 64 hexadecimal
     *                       characters, its SHA-256 fingerprint.
     *   off                 not checked.
     * ANYTHING ELSE COUNTS AS "on", and `unreadable` says so. The direction
     * is chosen on purpose: a value somebody mistyped straight into the
     * database ("Off " is read, "no" and "disabled" are not) must never
     * stop the check; the worst it can do is leave it running.
     *
     * @return array{mode: string, fingerprint: string, unreadable: bool}
     *         mode: one of the CHECK_ values. fingerprint: for 'only', the
     *         accepted certificate's, in small letters; '' otherwise.
     */
    public static function certificateCheck(): array
    {
        $stored = strtolower(self::value('mail.smtp.certCheck'));
        $answer = ['mode' => self::CHECK_ON, 'fingerprint' => '', 'unreadable' => false];
        if ($stored === '' || $stored === self::CHECK_ON) {
            return $answer;
        }
        if ($stored === self::CHECK_OFF) {
            return ['mode' => self::CHECK_OFF] + $answer;
        }
        $fingerprint = str_starts_with($stored, self::CHECK_ONLY_PREFIX) === true ? substr($stored, strlen(self::CHECK_ONLY_PREFIX)) : '';
        if (SendOptions::isFingerprint($fingerprint) === true) {
            return ['mode' => self::CHECK_ONLY, 'fingerprint' => $fingerprint] + $answer;
        }

        return ['unreadable' => true] + $answer;
    }

    /**
     * 🔏 Store how the certificate is to be treated. The e-mail page calls
     * this for "accept only this certificate", "switch checking off" and
     * "switch checking back on"; recheckCertificate() calls it to switch
     * checking back on by itself.
     *
     * The caller has checked the form's token and that the person is a
     * global administrator, and writes the activity log. This method does
     * neither: it has no way to know who is asking.
     *
     * BOTH ROWS ARE WRITTEN BY ONE STATEMENT: the setting, and the moment
     * (UTC, from the database's own clock; emptied when checking goes back
     * on). One statement, so that there is never a moment when one is
     * written and the other is not. REJECTED: a transaction. This is also
     * called from inside a send, and starting a transaction there would
     * quietly commit whatever transaction the page that is sending was in
     * the middle of.
     *
     * AFTERWARDS a fault that was on record under the old rule no longer
     * stands (the setting is part of settingsMark()), so the mail held for
     * the certificate goes back into the queue, due at once.
     *
     * @param string $mode        One of the CHECK_ values.
     * @param string $fingerprint For CHECK_ONLY: the certificate to accept.
     *
     * @return bool False when nothing was changed: the mode or the
     *              fingerprint is not one this class knows, or the two rows
     *              are not in the database (the upgrade has not been run),
     *              or the database refused. The reason is in the server's
     *              error log.
     */
    public static function setCertificateCheck(mysqli $db, string $mode, string $fingerprint = ''): bool
    {
        $fingerprint = strtolower($fingerprint);
        if ($mode === self::CHECK_ONLY && SendOptions::isFingerprint($fingerprint) === false) {
            return false;
        }
        $value = [self::CHECK_ON => self::CHECK_ON, self::CHECK_OFF => self::CHECK_OFF, self::CHECK_ONLY => self::CHECK_ONLY_PREFIX . $fingerprint][$mode] ?? null;
        if ($value === null) {
            return false;
        }
        $stamp = $mode === self::CHECK_ON ? 0 : 1;
        try {
            $stmt = $db->prepare(
                "UPDATE tblSettings SET settingValue = CASE settingKey WHEN 'mail.smtp.certCheck' THEN ? "
                . "ELSE IF(? = 1, DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), '') END, isSensitive = 0, updatedAt = NOW() "
                . "WHERE siteID IS NULL AND settingKey IN ('mail.smtp.certCheck', 'mail.smtp.certCheckOffAt')"
            );
            $stmt->bind_param('si', $value, $stamp);
            $stmt->execute();
            $stmt->close();
            // Read it back: "no rows changed" is also what a database
            // without the two rows answers, and that must not read as done.
            $read = $db->prepare("SELECT settingValue FROM tblSettings WHERE siteID IS NULL AND settingKey = 'mail.smtp.certCheck'");
            $read->execute();
            $now = $read->get_result()->fetch_assoc();
            $read->close();
            if ($now === null || (string) $now['settingValue'] !== $value) {
                error_log('MailRoutes: the certificate setting could not be stored: its row is not in the database (has the upgrade been run?).');

                return false;
            }
        } catch (\Throwable $e) {
            error_log('MailRoutes: the certificate setting could not be stored: ' . $e->getMessage());

            return false;
        }
        self::forget();
        // The fault on record was met under the old rule: this clears it
        // and releases the mail held for it (see onRecord()), whatever
        // route is in use.
        self::onRecord($db);

        return true;
    }

    /**
     * 🩺 WHILE CHECKING IS OFF, OR ONE CERTIFICATE IS ACCEPTED: would the
     * mail server's certificate pass the full check NOW? If it would,
     * checking is switched back on, with a line in the activity log (the
     * owner's decision, 13 October 2026: a way past the check is for as long
     * as the certificate is wrong, and not a day longer).
     *
     * ASKED ONCE A REQUEST, before the first message it sends, and once at
     * the start of every run of the mail queue, so an installation that
     * sends nothing for a week still finds out at the job's next run. One
     * fully checked connection as far as the encryption, which says nothing
     * private (see the library's CertificateReader::wouldPass()).
     *
     * WHATEVER ROUTE IS IN USE. What is asked about is the SMTP mailbox as
     * it is set up, and "off" stays in force for it (a "Send test" through
     * it, and all mail the day the portal is switched back to it) while
     * Microsoft 365 or Google carries the mail. So the caller does not ask
     * which route is chosen, and neither does this. (MailQueue::send() used
     * to call this only when SMTP was the route in use: with another route
     * chosen, "off" then never lapsed by itself.)
     *
     * "Cannot tell" (the mail server could not be reached, or the handshake
     * failed in a way that is not the certificate's doing) CHANGES NOTHING.
     * Somebody on the network path can cause that at will; they cannot
     * cause a "yes".
     *
     * Never throws.
     *
     * @return string 'not-needed' (checking is on, or no SMTP mailbox is
     *                set up), 'passes' (and checking is now on), 'fails' or
     *                'cannot-tell'.
     */
    public static function recheckCertificate(mysqli $db): string
    {
        if (self::$rechecked !== null) {
            return self::$rechecked;
        }
        try {
            // The setting FIRST: while checking is on (nearly always), the
            // SMTP route is not opened, and its password not decrypted, for
            // a request that sends through another route.
            $check = self::certificateCheck();
            if ($check['mode'] === self::CHECK_ON || self::open()['config'] === null) {
                return self::$rechecked = 'not-needed';
            }
            $passes = self::wouldPassNow();
            if ($passes !== true) {
                return self::$rechecked = $passes === false ? 'fails' : 'cannot-tell';
            }
            if (self::setCertificateCheck($db, self::CHECK_ON) === false) {
                // Not switched back: the message about to go follows the
                // stored rule, as before. The reason is in the error log.
                return self::$rechecked = 'cannot-tell';
            }
            // No person did this, so no account is named: the portal did.
            Logger::activity(
                self::LOG_BACK_ON,
                'Certificate checking for the mail server switched itself back ON: the server\'s certificate passes the full check again. '
                . ($check['mode'] === self::CHECK_OFF ? 'Checking had been switched off.' : 'One certificate had been accepted (fingerprint ' . $check['fingerprint'] . ').'),
                null
            );

            return self::$rechecked = 'passes';
        } catch (\Throwable $e) {
            error_log('MailRoutes: the mail server\'s certificate could not be looked at again: ' . $e->getMessage());

            return self::$rechecked = 'cannot-tell';
        }
    }

    /**
     * 🩺 Would the SMTP mailbox's mail server pass the FULL certificate check
     * at this moment? One fully checked connection as far as the encryption,
     * which gives the server nothing private: the mail library's
     * CertificateReader::wouldPass().
     *
     * @return bool|null True or false; null for "cannot tell", and when no
     *                   SMTP mailbox is set up. A caller must treat null as
     *                   "nothing has changed".
     */
    private static function wouldPassNow(): ?bool
    {
        $config = self::open()['config'];

        return $config === null ? null : (new CertificateReader($config, new SystemClock()))->wouldPass();
    }

    /**
     * ⚠️ THE STANDING WARNING: what the e-mail page, the admin dashboard and
     * the health page all say while checking is off, or one certificate is
     * accepted. Null only when the certificate is checked in full.
     *
     * READ FROM WHAT IS STORED, every time, and from nothing else: the
     * setting itself, the moment stored beside it, and the activity log for
     * who set it. So it shows the same whoever set it and however (the
     * e-mail page, or a line of SQL), on every page, to every
     * administrator, until the setting is `on` again. REJECTED: a note kept
     * in the session of the person who switched it off. Nobody else would
     * have been warned, and they would have stopped being warned at their
     * next sign-in.
     *
     * WHATEVER ROUTE IS IN USE. This used to answer null unless SMTP was the
     * route in use. With checking off and Microsoft 365 chosen, no page then
     * said so, and "Send test" through the SMTP mailbox still went with the
     * check off. It now says which route IS in use and that the setting
     * applies to what is sent through the SMTP mailbox.
     *
     * WHEN: the moment stored beside the setting
     * (`mail.smtp.certCheckOffAt`, UTC, the database's clock), whenever it
     * is there. It is worded as "the moment stored beside this setting"
     * unless a log line vouches for it, because a change made in the
     * database can leave an older moment in place.
     * WHO: an administrator is NAMED ONLY WHEN THE LOG LINE AND THE STORED
     * MOMENT ARE THE SAME CHANGE. That means all of: the newest line of the
     * four this class writes is "accepted" or "switched off"; it is the kind
     * that matches what is stored now (and, for one certificate, names the
     * same fingerprint); and its moment is within SAME_CHANGE_SECONDS of the
     * stored one. WHAT WAS WRONG BEFORE: the newest line was believed
     * whenever a moment was stored. After a value was changed by SQL it
     * named the administrator of an EARLIER change; and once the log's line
     * had been cleared out with age it said "no record of who set this or
     * when" although the moment was still stored.
     *
     * WHAT THIS CANNOT DO:
     *   - name anybody when the setting was changed in the database, or the
     *     log line has been cleared out (the activity log is kept for a
     *     year by default; an accepted certificate can stay for longer);
     *   - name a person whose account has since been removed;
     *   - match the two moments across the night the clocks change, when
     *     the database server keeps its clock in a zone that changes: the
     *     activity log stores its times on that clock with no zone, so one
     *     of them is then an hour out, and nobody is named. That is the
     *     safe direction.
     *
     * @param string $zone The time zone to show the moment in.
     *
     * @return array{mode: string, fingerprint: string, headline: string, since: string, by: string, named: bool, line: string, publicLine: string}|null
     *         headline: a few words, for a label. since: the stored moment,
     *         as a person reads it, or ''. named: true when a log line
     *         vouches for who set it; by: that person's name, or '' when
     *         their account has gone. line: one plain paragraph holding all
     *         of it, FOR A GLOBAL ADMINISTRATOR. publicLine: the same with
     *         nobody named, for everybody else: an organisation's
     *         administrator, and the health line, which an outside monitor
     *         reads too.
     */
    public static function standingWarning(mysqli $db, string $zone): ?array
    {
        $check = self::certificateCheck();
        if ($check['mode'] === self::CHECK_ON) {
            return null;
        }
        $off      = $check['mode'] === self::CHECK_OFF;
        $stored   = self::value('mail.smtp.certCheckOffAt');
        $isMoment = preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/', $stored) === 1;
        $since    = $isMoment === true ? MailQueue::shown($stored, $zone) : '';
        $by       = '';
        $named    = false;
        try {
            if ($isMoment === true) {
                // The newest of the four lines, and how many seconds its
                // moment is from the stored one. The database does the sum:
                // the log's time is on its own clock (see the note above).
                $own  = [self::LOG_ACCEPTED, self::LOG_OFF, self::LOG_ON, self::LOG_BACK_ON];
                $stmt = $db->prepare(
                    'SELECT a.activityType, a.activityDescription, u.fullName, '
                    . 'ABS(TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP() - INTERVAL TIMESTAMPDIFF(SECOND, a.timestamp, NOW()) SECOND)) AS secondsApart '
                    . 'FROM tblActivityLogs a LEFT JOIN tblUsers u ON u.userID = a.userID '
                    . 'WHERE a.activityType IN (?, ?, ?, ?) ORDER BY a.logID DESC LIMIT 1'
                );
                $stmt->bind_param('sssss', $stored, $own[0], $own[1], $own[2], $own[3]);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $sameKind = $row !== null && ($off === true
                    ? (string) $row['activityType'] === self::LOG_OFF
                    : (string) $row['activityType'] === self::LOG_ACCEPTED && str_contains((string) $row['activityDescription'], 'fingerprint ' . $check['fingerprint'] . ' ') === true);
                if ($sameKind === true && $row['secondsApart'] !== null && (int) $row['secondsApart'] <= self::SAME_CHANGE_SECONDS) {
                    $named = true;
                    $by    = trim((string) ($row['fullName'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            // The warning still shows; only who set it is left out.
            error_log('MailRoutes: who changed the certificate setting could not be read: ' . $e->getMessage());
        }

        $headline = $off === true ? 'Certificate checking is OFF' : 'One certificate is accepted without the full check';
        // 🧭 Which route is in use decides what is true to say about it.
        $route = Mailer::provider();
        if ($route === self::SMTP) {
            $what = $off === true
                ? 'Certificate checking for the mail server is switched OFF. The portal cannot tell the mail server from an impostor: somebody on the network path could read or change every e-mail it sends, password-reset links included, and capture the mailbox\'s password.'
                : 'The portal is sending mail through one certificate that failed the full check, accepted by its fingerprint (' . $check['fingerprint'] . '). Mail goes only while the mail server shows exactly that certificate.';
        } else {
            $elsewhere = 'This applies to the SMTP mailbox, which is not the route in use now: e-mail is set to go through ' . Mailer::ROUTES[$route] . '. '
                . 'It is in force for anything sent through the SMTP mailbox (a "Send test" through it, and every e-mail if the portal is switched to it).';
            $what = $off === true
                ? 'Certificate checking for the SMTP mailbox\'s mail server is switched OFF. ' . $elsewhere . ' For those, the portal cannot tell the mail server from an impostor: somebody on the network path could read or change them, and capture the mailbox\'s password.'
                : 'One certificate that failed the full check is accepted for the SMTP mailbox\'s mail server, by its fingerprint (' . $check['fingerprint'] . '). ' . $elsewhere . ' Those go only while the mail server shows exactly that certificate.';
        }
        // 🕰️ When, and who. `publicWho` is the same with nobody named.
        if ($named === true) {
            $publicWho = 'Set on ' . $since . '.';
            $who       = 'Set on ' . $since . ' by ' . ($by === '' ? 'a person whose account is no longer held' : $by) . '.';
        } elseif ($since !== '') {
            $publicWho = 'The moment stored beside this setting says it was set on ' . $since . '.';
            $who       = $publicWho . ' Who set it is not on record: the activity log holds no line for that change.';
        } else {
            $publicWho = 'The portal has no record of when this was set.';
            $who       = 'The portal has no record of who set this or when.';
        }
        $after = ' Checking switches itself back on when the mail server\'s certificate passes the full check again; a global administrator can switch it back on sooner at Admin → Integrations → Email.';

        return [
            'mode' => $check['mode'], 'fingerprint' => $check['fingerprint'], 'headline' => $headline, 'since' => $since, 'by' => $by, 'named' => $named,
            'line' => $what . ' ' . $who . $after, 'publicLine' => $what . ' ' . $publicWho . $after,
        ];
    }

    /**
     * 🪪 The certificate that failed its check, as it is on record, for the
     * e-mail page to show before a global administrator decides anything.
     * Null when no certificate fault is on record.
     *
     * `sha256` is '' when the certificate could not be read to say what is
     * wrong with it. There is then nothing to accept and no message can be
     * sent past the check: an approval names a certificate by its
     * fingerprint, and there is none.
     *
     * @param string $zone The time zone to show moments in.
     *
     * @return array{words: string, names: string, issuer: string, validFrom: string, validTo: string, sha256: string, since: string, seenAt: string}|null
     */
    public static function certificateOnRecord(mysqli $db, string $zone): ?array
    {
        // What is ON RECORD, whatever route is in use: a certificate that a
        // "Send test" found while another route was in use is shown too, so
        // that it can be looked at, and decided about, before the portal is
        // switched to the SMTP mailbox. Whether it stops mail is
        // standingProblem()'s to say, not this method's.
        if (self::onRecord($db)['problem'] !== self::PROBLEM_CERTIFICATE) {
            return null;
        }
        try {
            $state = self::state($db);
        } catch (\Throwable $e) {
            return null;
        }
        $moment = static fn (mixed $utc): string => is_string($utc) === true && $utc !== '' ? MailQueue::shown($utc, $zone) : '';

        return [
            'words'     => (string) $state['problemWords'],
            'names'     => (string) $state['certNames'],
            'issuer'    => (string) $state['certIssuer'],
            'validFrom' => $moment($state['certValidFrom']),
            'validTo'   => $moment($state['certValidTo']),
            'sha256'    => strtolower((string) $state['certSha256']),
            'since'     => $moment($state['problemSince']),
            'seenAt'    => $moment($state['problemSeenAt']),
        ];
    }

    /**
     * 🔒 Is this setting kept out of the GENERAL settings editor (/settings)?
     *
     * Yes for three: the mail server's name, and the two rows of the
     * certificate setting. THE E-MAIL PAGE IS THE ONE PLACE THEY ARE
     * CHANGED, for everybody, a global administrator included.
     *
     * WHAT WAS WRONG (found by the check of package M4, by running it): with
     * certificate checking off, a global administrator changed
     * `mail.smtp.host` on the general Settings page. That page knows nothing
     * of saveSmtp()'s two rules, so the stored password and "off" were both
     * kept: the next message went to the new server, which showed a
     * certificate nobody had looked at, and it was given the sign-in line
     * carrying the password. And `off` could be typed into
     * `mail.smtp.certCheck` there with no warning, no typed server name and
     * no certificate fault on record.
     *
     * The editor asks this in THREE places, and all three refuse: changing
     * a row and adding one (web/_apps/settings/save.php), and deleting one
     * (web/_apps/settings/index.php). Deleting matters as much: with the
     * name's row gone no SMTP mail can go, and with the certificate rows
     * gone the e-mail page can no longer store a decision
     * (setCertificateCheck() changes the two rows, it does not add them).
     * An ORGANISATION'S own row of the same name is refused as well.
     * Nothing reads one (see the file header), so it would only mislead
     * whoever found it later.
     *
     * WHAT IT CANNOT DO: stop somebody changing the rows straight in the
     * database; or cover the port, the user name, the kind of encryption
     * and the password, which the general editor can still change.
     *
     * Names are compared without regard to capitals, as the database does.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return in_array(strtolower($key), self::LOCKED_IN_GENERAL_EDITOR, true) === true;
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
     * WHEN THE MAIL SERVER'S NAME OR ITS PORT IS CHANGED (package M4, from
     * #592's list; the port was added after the package's check, which found
     * that a changed port under the same name kept both):
     *   - A STORED PASSWORD IS NOT CARRIED OVER TO THE NEW SERVER. It must
     *     be typed again, or removed, in the same save. A stored password
     *     was saved for one server; with the box left empty it used to be
     *     kept, and was offered to whatever answered at the new name or
     *     port the next time a message was sent. Another port is another
     *     program answering, and with checking off nothing else tells the
     *     two apart.
     *   - CERTIFICATE CHECKING GOES BACK ON, from "off" AND from "one
     *     certificate accepted". Either was decided about the server that
     *     was set then (its name was typed to confirm it). It is not
     *     carried over to another.
     * WHAT THIS CANNOT DO: either of those when the port is changed on the
     * general Settings page, or anything is changed in the database. (The
     * general Settings page refuses to change the NAME: see
     * lockedInGeneralEditor().)
     *
     * @param array<string, mixed> $input The form's fields: host, port,
     *        security, username, password, removePassword, fromAddress,
     *        fromName, replyTo, perHour.
     *
     * @return array{errors: list<string>, password: string, checkingBackOn: bool, serverChange: string}
     *         `errors` empty means saved. `password` says what happened to
     *         the stored one: 'kept', 'stored' or 'removed' (for the activity
     *         log; never the password itself). `checkingBackOn` is true when
     *         this save put certificate checking back on. `serverChange`
     *         says which of the two changed, in words: '', 'name', 'port' or
     *         'name and port'.
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
        // 🔑 The server's name or port has changed and a stored password
        //    would be kept: refused. See the note above this method.
        //    (formValues() says only which state the stored password is in.)
        //    A port is compared as a number where it is one, so that "0587"
        //    typed over "587" is not taken for a different server.
        $asPort      = static fn (string $typed): string => preg_match('/\A[0-9]{1,5}\z/', $typed) === 1 ? (string) (int) $typed : $typed;
        $nameChanged = $host !== strtolower(self::value('mail.smtp.host'));
        $portChanged = $asPort($port) !== $asPort(self::value('mail.smtp.port'));
        $serverChange = trim(($nameChanged === true ? 'name' : '') . ($nameChanged === true && $portChanged === true ? ' and ' : '') . ($portChanged === true ? 'port' : ''));
        if ($serverChange !== '' && $newPassword === '' && $remove === false && self::formValues()['passwordState'] === 'stored') {
            // (This used to end "the portal does not send a password to a
            // server it was not saved for". That was more than is true: a
            // port changed on the general Settings page, or a name changed
            // in the database, does exactly that. What is true is what THIS
            // page does.)
            $errors[] = "The mail server's " . $serverChange . ($serverChange === 'name and port' ? ' have' : ' has') . ' changed, so type the mailbox\'s password again (or tick the box to remove the stored one). '
                . 'The stored password was saved for the server set before, and this page does not keep a stored password for a different server name or port.';
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
            return ['errors' => $errors, 'password' => 'kept', 'checkingBackOn' => false, 'serverChange' => ''];
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

                return ['errors' => ["The password could not be encrypted, so nothing was saved. The installation's key file is missing or cannot be read; see the server's error log."], 'password' => 'kept', 'checkingBackOn' => false, 'serverChange' => ''];
            }
            $passwordChange = 'stored';
        } elseif ($remove === true) {
            $rows['mail.smtp.password'] = ['', 1];
            $passwordChange = 'removed';
        }
        // (The box left empty and "remove" not ticked: no password row is in
        //  $rows at all, so the stored one cannot be touched by this save.)

        // 🪪 A new server name or port: certificate checking goes back on,
        //    from "off" and from "one certificate accepted" alike, in the
        //    same save. See the note above this method.
        $checkingBackOn = $serverChange !== '' && self::certificateCheck()['mode'] !== self::CHECK_ON;
        if ($checkingBackOn === true) {
            $rows['mail.smtp.certCheck']      = [self::CHECK_ON, 0];
            $rows['mail.smtp.certCheckOffAt'] = ['', 0];
        }

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

            return ['errors' => ['The settings could not be saved because the database refused them. Nothing was changed. The reason is in the server\'s error log.'], 'password' => 'kept', 'checkingBackOn' => false, 'serverChange' => ''];
        }
        self::forget();

        return ['errors' => [], 'password' => $passwordChange, 'checkingBackOn' => $checkingBackOn, 'serverChange' => $serverChange];
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
