<?php
// Path: tools/settings-secrets-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Secret settings, on the REAL pages — self-test 🔐 (#597, #497)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG (both run on 12 October 2026)
 * --------------------------------------------
 * #497: the portal never decrypted a stored secret. The settings load compared
 * the "this is a secret" mark with the TEXT '1', and the database hands it
 * back as the NUMBER 1. Everything that used a secret was handed the scramble.
 * #597: the Settings page's edit box stored an edited secret as plain text,
 * cleared its mark, and then showed it to every administrator. Mail through
 * Microsoft 365 and Google only worked BECAUSE of that second fault.
 * Neither failed loudly. Every page answered and every answer looked normal,
 * so this file runs the portal and reads what was stored and what was sent.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file and a key file OF ITS OWN, and starts PHP's built-in web
 * server on it twice (once as the live portal, once as a development copy, so
 * that the debug panel can be looked at) behind a small router that imitates
 * `.htaccess`. It starts a STAND-IN for the two mail services, which records
 * what it is sent and sends nothing; in the scratch copy ONLY, the four
 * addresses the mailer calls are pointed at it. It signs in as a global
 * administrator, as an administrator of ONE organisation and as an ordinary
 * member, and sends real requests with real cookies.
 *
 *   A. Start-up, run directly from the real `bootstrap.php`, for EVERY setting
 *      the install script marks as a secret, in each state a row can be in:
 *      encrypted once; encrypted three times; plain text with the mark on
 *      (including text short enough to make the old decrypt call throw);
 *      plain text with the mark off; encrypted with a different key file; an
 *      encrypted empty value. That the new code and the two old helper
 *      functions open each other's work. And that, once started, PHP's
 *      record of an error no longer carries what each function was handed.
 *      And that a key file of EVERY length still works exactly as before:
 *      one byte, the single character "0", raw bytes, 63, 65, 4096.
 *   B. A secret saved through the edit box is stored encrypted and marked,
 *      and is in NO page afterwards, for a global administrator or for an
 *      administrator of one organisation. An empty box keeps it. A new value
 *      replaces it. The tick removes it. An ordinary setting behaves as before.
 *   C. The protections: no form token, a GET, nobody signed in, an ordinary
 *      member, and an administrator of one organisation trying to change an
 *      installation-wide secret (refused, recorded, nothing changed).
 *   D. An organisation's own secret, added with the box ticked by its own
 *      administrator; and one added empty.
 *   E. Rows #597 left behind (plain text, mark cleared): they go on working,
 *      migration 214 puts the mark back without changing the value, they are
 *      then in no page, the page names them as needing to be entered again
 *      (and says why), and the next save encrypts them. The migration is run twice and rows are counted.
 *      A podcast-feed token in that state is left exactly as it is.
 *   F. Rows that do not decrypt, through the real mail route: start-up
 *      carries on and the stored text is used. With the key file gone, pages
 *      still load, a secret cannot be saved, the person is told why, and what
 *      they typed is in no record.
 *   G. #497 closed end to end: a tenant, a client ID and a client secret each
 *      saved through the page; ONE real "Forgot password"; the stand-in
 *      Microsoft 365 is sent the secret exactly as typed. The same through
 *      Google, where the secret setting is a key file's name. And a scheduled
 *      job's token saved through the page lets the job in.
 *   H. Admin, Captcha never prints a secret key, an empty box keeps the stored
 *      one, and emptying both boxes removes it. The QR page shows eight dots
 *      whatever the key's length.
 *   J. The owner's first decision of 13 September 2026 on #497. With the
 *      bot check's keys stored but unreadable (encrypted with another key
 *      file): sign-in and password reset go on WITHOUT the bot check and are
 *      still limited (five wrong passwords block an address; the sixth
 *      request for a link in 15 minutes is not sent); a public form is
 *      refused and says so; a form that first asks "is a bot check set up?"
 *      is refused too; and administrators are told on Admin, Captcha, on the
 *      health page and at the monitor's address, on the Settings page, and by
 *      ONE record in the error log. With keys the portal can read, all three
 *      kinds of form behave as before. It also reads the code for every
 *      place that asks the bot check, and holds the count and what each says.
 *   K. The owner's second decision. A database as an older release left it,
 *      then the real Upgrade page: secrets stored as readable text are
 *      encrypted (the ones the old page left unmarked, one that only LOOKS
 *      encrypted, an organisation's own copy, one that was marked); ones
 *      that already decrypt are not touched (encrypted once, and twice);
 *      ones that cannot be read are left and named; a row the database
 *      refuses to rewrite is left and named and the run goes on; everything
 *      reads back as the same secret; the page and the activity log give
 *      names and never a value. Then the same Upgrade again (nothing
 *      changes), with the key file missing (nothing changes, and it says
 *      so), and with somebody changing a secret while it runs (not
 *      overwritten).
 *   L. What the independent check of 13 October 2026 found, each one run.
 *      (1) Admin, Captcha with keys that cannot be read: "Save" pressed with
 *      the form as drawn changes NOTHING and says so, the bot check does not
 *      go off, one key typed replaces only that key, and with the key file
 *      put back the same keys read again. (2) An EMPTY key file, and a
 *      folder in its place, are "no key": saving is refused, Upgrade writes
 *      nothing, and with the real file back the secret is encrypted with
 *      the real key. A SHORT key file is named on the health report and
 *      stops nothing. (3) The two forms with no place for a bot check, the
 *      visitor form and live chat, say in plain words that they are closed.
 *      (4) An error on a scheduled job's address is recorded, and sent to
 *      the outside error service, WITHOUT the job's token. And the line
 *      written to the web server's log on every request is one short line.
 *   I. Nothing leaves: about a hundred administration pages fetched as both
 *      administrators, on the live copy and on the development copy with the
 *      debug panel open; every text column of every table; the scratch
 *      folder's logs and session files. It runs last, so it also searches
 *      what parts J and K left in the logs.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every account it makes is numbered 914000 to 914099; every
 *   record it causes carries a 198.51.100.x address (a range kept for
 *   examples, so nobody's) or this test's name as its browser.
 * - It changes installation-wide settings for the run and puts each back
 *   exactly, value and mark. So do not run it at the same moment as another
 *   test on the same database.
 * - It runs migration 214 against that database, more than once. That is
 *   what the migration is for. Part K also takes that migration's record
 *   out of `tblMigrations`, so that the Upgrade page runs it for real, and
 *   the page (and the clean-up) puts the record back.
 * - Part K adds a TRIGGER to `tblSettings` (`selftest_s597_refuse`), which
 *   makes the database refuse to rewrite ONE named row, so that "one row
 *   failed" is a real refusal. It is dropped when part K ends, at the start
 *   of every run, and by the clean-up. The account therefore needs to be
 *   allowed to make triggers, as a throwaway server's root account is.
 * - Part K presses the real Upgrade button, which closes the portal while
 *   it works, takes a snapshot of the whole database into the SCRATCH
 *   copy's `_backups` folder, and opens the portal again.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds this
 *   run's marker. `web/_auth_keys` is never read or copied: the key file
 *   this test encrypts with is one it makes itself.
 * - Each server is started with its command as a list (no shell), so each
 *   process number is the server's own, and each is stopped by that number,
 *   never by a name. It refuses to start if anything already answers on one
 *   of its three ports. Every answer must carry this run's header.
 * - A bot check's keys are stored for parts H and J. In the scratch copy
 *   ONLY, the three addresses the bot check asks "was that a person?" are
 *   pointed at the stand-in, exactly three or the run stops, so no request
 *   leaves this machine.
 * - Part L makes one event (number 914050), viewer sessions and chat
 *   messages for it, and visitors called "S597 selftest visitor…"; all are
 *   removed. It switches on live chat, the visitor form and error reporting
 *   for the run and puts each back. The "outside error service" it reports
 *   to is this run's own stand-in, on this machine. It replaces the scratch
 *   copy's key file with an empty one, a folder and a short one, in turn,
 *   and puts the run's own back each time.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real provider accepts a secret. The stand-in says yes to
 *   everything; nothing here touches Microsoft or Google. The stand-in bot
 *   check says "a person" for one fixed word and "not a person" otherwise;
 *   only the first provider (Turnstile) is run.
 * - Every form the bot check protects. Part J reads all thirteen call sites
 *   from the code and runs five: sign-in, the two password-reset pages, the
 *   public prayer request and the decision card. Part L runs two more, the
 *   visitor form and live chat. The other six are read, not run.
 * - A key file that is the WRONG one for only some rows, or an upgrade ended
 *   half way by the host. Part K runs "missing" and "one row refused".
 * - Two people pressing Upgrade at the same moment. Part K has one upgrade
 *   and one other writer.
 * - That every READER works with the real value. Part A proves what start-up
 *   hands over for every marked setting; only mail (both routes), one
 *   scheduled job, the bot check's page and the QR page are run end to end.
 * - A page this file does not fetch. Part I fetches the administration pages
 *   whose address does not look like an action; a secret printed by a page
 *   outside that list, or only after a form is sent, would not be seen.
 * - A secret written out in some other form (reversed, or encoded). Part I
 *   looks for the first twelve characters of each one, which are letters and
 *   digits, so plain, HTML, JSON and web-address forms are all caught, and so
 *   is a cut-off copy in an error trace.
 * - What the chat box SCRIPT shows. Part L proves what the server answers
 *   to a chat message; no browser runs web/public_html/assets/js/livechat-widget.js here.
 * - The alert e-mail and the fallback line in PHP's own log carrying an
 *   address without a job's token. Part L runs the error log's row and the
 *   outside error service; those two use the same function and were read.
 * - A key file the web server is not allowed to read, or a short key file
 *   on a real host. Part L runs missing, empty, a folder and 16 bytes.
 * - Apache, a real proxy, or https: the router only imitates `.htaccess`.
 * - MariaDB. It has been run on MySQL 8.0.36 and 8.4.11.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/settings-secrets-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9640), SELFTEST_MAIL_PORT (default
 *         9641; the port after it must be free too, for the development
 *         copy), SELFTEST_MAX_SECONDS (how long one page may run; unset
 *         leaves PHP's own limit). The database must already hold
 *         `web/_sql/full_schema.sql`, and its account must be allowed to
 *         make a trigger and to read `performance_schema.data_lock_waits`
 *         (part K). Needs PHP's curl, openssl and sodium extensions and
 *         `proc_open()`.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/597
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 151;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'openssl_pkey_new', 'sodium_crypto_secretbox'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . DIRECTORY_SEPARATOR . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($parent . DIRECTORY_SEPARATOR, $repo . DIRECTORY_SEPARATOR) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw     = getenv('SELFTEST_WEB_PORT');
$port        = ($portRaw === false || $portRaw === '') ? 9640 : (int) $portRaw;
$mailPortRaw = getenv('SELFTEST_MAIL_PORT');
$mailPort    = ($mailPortRaw === false || $mailPortRaw === '') ? 9641 : (int) $mailPortRaw;
$devPort     = $mailPort + 1;
foreach ([$port, $mailPort, $devPort] as $onePort) {
    if ($onePort < 1024 || $onePort > 65535) {
        refuse('SELFTEST_WEB_PORT, SELFTEST_MAIL_PORT and the port after it must be between 1024 and 65535');
    }
}
if ($port === $mailPort || $port === $devPort) {
    refuse('SELFTEST_WEB_PORT must not be SELFTEST_MAIL_PORT or the port after it');
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure, and must never hold a secret's value. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/** A line that tells the reader something and is NOT a check. */
function note(string $text): void
{
    echo 'NOTE — ' . $text . "\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function q(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<mixed> $params */
function one(string $sql, string $types = '', array $params = []): ?array
{
    return q($sql, $types, $params)[0] ?? null;
}

// =============================================================================
// 🗺️ The made-up world
// =============================================================================
const PEOPLE = [
    'global' => 914001, // a global administrator
    'local'  => 914002, // an administrator of ONE organisation (organisation 1), and nothing more
    'member' => 914003, // an ordinary member
    'asker'  => 914004, // the person who asks for a "Forgot password" link
];
/** How this test names itself to the portal, so the records it causes can be found and removed. */
const AGENT = 'settings-secrets-selftest';
/** The address the mail is sent from. */
const SENDER = 'office@selftest.invalid';
/** The made-up Google key file, written into the scratch copy only. */
const GOOGLE_KEY_FILE = 'selftest-settings-secrets-google-key.json';
/** Settings this test adds for organisation 1 all begin with this, and are deleted by it. */
const OWN_PREFIX = 'selftest_s597.';
/**
 * How many settings migration 214 names: the 56 the install script marked as
 * secrets on 12 October 2026, and `mail.smtp.password`, which migration 213
 * adds in the same release. A secret first seeded AFTER #597 was fixed never
 * met the old edit box, so 214 has no mark to put back on it. When one is
 * added, list it in SEEDED_SINCE below; otherwise part E fails, on purpose,
 * because it cannot tell "added since" from "214 forgot it".
 */
const NAMED_BY_214 = 57;
const SEEDED_SINCE = [];
/** Ordinary (not secret) installation-wide settings this test sets for the run. */
const ORDINARY_KEYS = [
    'mail.provider', 'mail.fallbackProvider', 'mail.ms365.sharedMailbox', 'mail.defaultFromAddress', 'mail.google.delegateUser',
    'portal.trustedProxies', 'portal.trustedProxyHeader', 'portal.gatekeeper.enabled', 'site.name',
    'auth.rateLimit.maxAttempts', 'auth.rateLimit.windowMinutes', 'auth.rateLimit.maxAttemptsByUsername', 'auth.captcha.priority',
    // Written by the Upgrade page itself (part K).
    'portal.maintenance.active', 'portal.maintenance.message', 'portal.installed_version',
    // Switched on for part L.
    'visitors.enabled', 'visitors.public_capture_enabled', 'chat.enabled', 'monitoring.enabled',
];
/** The name of the migration part K makes "not yet run", so that the Upgrade page has something to do. */
const MIGRATION_214 = '214_secret_setting_marks.sql';
/** The database trigger part K uses to make ONE row impossible to rewrite. Dropped at the start and at the end. */
const REFUSING_TRIGGER = 'selftest_s597_refuse';
/** What a prayer request made by part J is called, so that it can be found and removed. */
const REQUEST_SUBJECT = 'S597 selftest request';
/** Part L's public event (for live chat), and what its visitors and chat senders are called. */
const CHAT_EVENT   = 914050;
const VISITOR_NAME = 'S597 selftest visitor';
const CHAT_NAME    = 'S597 selftest';

/** A made-up visitor address. 198.51.100.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '198.51.100.' . $last;
}

function login(string $who): string
{
    return 's597-' . $who;
}

function mailOf(string $who): string
{
    return $who . '.s597@selftest.invalid';
}

/**
 * A made-up secret. Its first twelve characters are letters and digits and
 * are different on every run: that is what part I searches for, because it
 * reads the same in a page, in JSON, in a web address and in a cut-off error
 * trace. The tail holds the characters most likely to be mangled on the way.
 */
function secret(string $tag): string
{
    return 'S5' . $tag . bin2hex(random_bytes(8)) . '~typed.by-the_admin\\/8Q+=&"\'<x>';
}

/** The part of a secret that is searched for. */
function marker(string $secretValue): string
{
    return substr($secretValue, 0, 12);
}

function removeWorld(): void
{
    q('DELETE FROM tblPasswordResets WHERE userID BETWEEN 914000 AND 914099');
    q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%.s597@selftest.invalid%'");
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 914000 AND 914099 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 914000 AND 914099 OR visitorIP LIKE '198.51.100.%'");
    for ($last = 1; $last <= 254; $last++) {
        q('DELETE FROM tblApiRateLimits WHERE bucket IN (?, ?)', 'ss', ['pwreset:ask:' . hash('sha256', ip($last)), 'pwreset:use:' . hash('sha256', ip($last))]);
    }
    q('DELETE FROM tblSettings WHERE settingKey LIKE ?', 's', [str_replace('_', '\\_', OWN_PREFIX) . '%']);
    // Part J's "cannot be read" record and its prayer requests; part K's trigger and migration record.
    q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
    q('DELETE FROM tblPrayerRequests WHERE subject LIKE ?', 's', [REQUEST_SUBJECT . '%']);
    // Part L's visitors, and its event with the viewer sessions and chat messages made for it.
    q('DELETE FROM tblVisitor WHERE fullName LIKE ?', 's', [VISITOR_NAME . '%']);
    q('DELETE FROM tblLiveChatMessages WHERE eventID = ?', 'i', [CHAT_EVENT]);
    q('DELETE FROM tblLivestreamSessions WHERE eventID = ?', 'i', [CHAT_EVENT]);
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [CHAT_EVENT]);
    $GLOBALS['db']->query('DROP TRIGGER IF EXISTS ' . REFUSING_TRIGGER);
    q('INSERT INTO tblMigrations (filename) VALUES (?) ON DUPLICATE KEY UPDATE filename = filename', 's', [MIGRATION_214]);
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = 'auth.google.clientSecret'"); // part K's "an organisation's own copy"
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 914000 AND 914099');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 914000 AND 914099');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 914000 AND 914099');
}

// =============================================================================
// 🧰 The servers, and one request
// =============================================================================
$state = [
    'proc' => null, 'devProc' => null, 'mailProc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)),
    'rows' => [], 'podcast' => false, 'started' => false, 'cleaned' => false, 'mailRead' => 0, 'nextIp' => 10, 'key' => '',
];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Stop one server by the process proc_open() gave — never by a name. */
function stopServer(mixed &$proc): void
{
    if (is_resource($proc) === false) {
        return;
    }
    proc_terminate($proc);
    $until = microtime(true) + 5;
    while (proc_get_status($proc)['running'] === true && microtime(true) < $until) {
        usleep(50000);
    }
    if (proc_get_status($proc)['running'] === true) {
        proc_terminate($proc, 9);
    }
    proc_close($proc);
    $proc = null;
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    stopServer($state['proc']);
    stopServer($state['devProc']);
    stopServer($state['mailProc']);
    try {
        foreach ($state['rows'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$before['settingValue'], (int) $before['isSensitive'], (int) $before['settingID']]);
            }
        }
        if ($state['podcast'] !== false) {
            q("DELETE FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = 1");
            if (is_array($state['podcast']) === true) {
                q(
                    "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'recordings.podcast_token', ?, '', ?)",
                    'si',
                    [$state['podcast']['settingValue'], (int) $state['podcast']['isSensitive']]
                );
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.settings-secrets-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/** An installation-wide row as it is stored now. `isSensitive` arrives as a NUMBER here, as it does in the portal. */
function row(string $key): ?array
{
    return one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
}

/**
 * Write an installation-wide row directly, value and mark. What it held
 * before this run first touched it is remembered and put back by the clean-up.
 */
function store(string $key, string $value, int $mark): void
{
    global $state;
    $now = row($key);
    if (array_key_exists($key, $state['rows']) === false) {
        $state['rows'][$key] = $now;
    }
    if ($now === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, ?)', 'sssi', [$key, $value, '', $mark]);
    } else {
        q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$value, $mark, (int) $now['settingID']]);
    }
}

// --- This test's own copy of the encryption, with the key file IT made. -----
// Deliberately written out again here instead of calling the portal's code:
// a test that asked the portal "is this encrypted?" would believe whatever
// the portal said.

/** Encrypt the way the portal does, with any key file's contents. */
function sealWith(string $keyFileContents, string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyFileContents))));
}

/** Encrypt with THIS run's key file. */
function seal(string $plain): string
{
    global $state;

    return sealWith($state['key'], $plain);
}

/** What THIS run's key file opens the stored text to; null when it does not open it. */
function unseal(string $stored): ?string
{
    global $state;

    return unsealWith($state['key'], $stored);
}

/** What a key file holding `$keyFileContents` opens the stored text to; null when it does not open it. */
function unsealWith(string $keyFileContents, string $stored): ?string
{
    $bytes = base64_decode($stored, true);
    if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(
        substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        sodium_hex2bin(hash('sha256', $keyFileContents))
    );

    return $plain === false ? null : $plain;
}

/**
 * One real HTTP request to a server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param int                      $onPort 0 = the live copy.
 * @param string                   $ip     The visitor's address, sent as a proxy
 *                                         would send it. '' = this test's usual one.
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, int $onPort = 0, string $ip = ''): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . ($onPort === 0 ? $port : $onPort) . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ['Expect:', 'User-Agent: ' . AGENT, 'X-Forwarded-For: ' . ($ip === '' ? ip(1) : $ip)],
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Settings-Secrets-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A new, empty cookie jar: a browser that has never been here. */
function newJar(): string
{
    global $state;

    return $state['child'] . DIRECTORY_SEPARATOR . 'jar-' . bin2hex(random_bytes(6)) . '.txt';
}

/** What a page tells the person: "danger: …" (its first message box), or '' when there is none. */
function said(string $html): string
{
    if (preg_match('#<div class="alert alert-(\w+)[^"]*"[^>]*>(.*?)</div>#s', $html, $m) !== 1) {
        return '';
    }

    return $m[1] . ': ' . trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8')));
}

/** Sign one of the people in through the real page; their cookie jar, or '' when it did not work. */
function signIn(string $who, string $password): string
{
    $try = trySignIn($who, $password);

    return $try['in'] === true ? $try['jar'] : '';
}

/**
 * One try at signing in through the real page, from a browser that has never
 * been here: open the page, then send the form.
 *
 * @param string               $ip   The visitor's address; '' = the usual one.
 * @param array<string,string> $more Further form fields (a bot check's answer).
 *
 * @return array{in: bool, jar: string, said: string, form: string} `said` is
 *         the page's message when the try was refused; `form` is the page as
 *         it was opened, before anything was sent.
 */
function trySignIn(string $who, string $password, string $ip = '', array $more = []): array
{
    $jar  = newJar();
    $form = req($jar, 'GET', '/login', null, 0, $ip);
    $sent = req($jar, 'POST', '/login', array_merge(['csrf_token' => token($form['body']), 'username' => login($who), 'password' => $password], $more), 0, $ip);

    return ['in' => $sent['status'] === 302 && $sent['location'] === '/', 'jar' => $jar, 'said' => said($sent['body']), 'form' => $form['body']];
}

/** The Settings page, as one signed-in person sees it. */
function settingsPage(string $jar): array
{
    return req($jar, 'GET', '/settings');
}

/** One setting's own row in the Settings page (from its name to the next row), or '' when it is not there. */
function rowHtml(string $html, string $key): string
{
    $start = strpos($html, 'data-setting-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"');
    if ($start === false) {
        return '';
    }
    $next = strpos($html, 'portal-setting-row"', $start + 10);

    return $next === false ? substr($html, $start, 4000) : substr($html, $start, $next - $start);
}

/**
 * Send the Settings page's EDIT box for one installation-wide setting, as a
 * browser would: open the page (for the form token), then post the four
 * fields the box sends, plus anything in `$more`. Returns the answer and the
 * message the page shows afterwards.
 *
 * @param array<string,string> $more
 *
 * @return array{answer: array, said: string, page: string}
 */
function edit(string $jar, string $key, string $value, array $more = [], ?int $settingId = null): array
{
    $page   = settingsPage($jar);
    $id     = $settingId ?? (int) (row($key)['settingID'] ?? 0);
    $answer = req($jar, 'POST', '/settings/save', array_merge(
        ['csrf_token' => token($page['body']), 'settingID' => (string) $id, 'settingKey' => $key, 'settingValue' => $value],
        $more
    ));
    $after = settingsPage($jar);

    return ['answer' => $answer, 'said' => said($after['body']), 'page' => $after['body']];
}

/** Wait until the live portal server has finished what it is doing (mail is sent AFTER "Forgot password" has answered). */
function settle(): void
{
    req('', 'GET', '/.selftest-settle');
}

/**
 * What the stand-in mail service has been sent since the last call.
 *
 * @return list<array{path:string, form:array<string,mixed>, raw:string}> `form`
 *         is the request read as a web form, which is how a key is asked for.
 */
function mailSince(): array
{
    global $state;
    $file  = $state['child'] . DIRECTORY_SEPARATOR . 'standin-requests.jsonl';
    $lines = is_file($file) === true ? (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $new   = array_slice($lines, $state['mailRead']);
    $state['mailRead'] = count($lines);
    $out = [];
    foreach ($new as $line) {
        $request = (array) json_decode((string) $line, true);
        parse_str((string) ($request['body'] ?? ''), $form);
        $out[] = ['path' => (string) ($request['path'] ?? ''), 'form' => $form, 'raw' => (string) ($request['body'] ?? '')];
    }

    return $out;
}

/**
 * One real "Forgot password" for the asker, and what the stand-in was sent
 * because of it. By default each call comes from a visitor address of its
 * own, so the limit on requests from one address is never reached.
 *
 * @param string               $ip   The visitor's address; '' = a new one.
 * @param array<string,string> $more Further form fields (a bot check's answer).
 *
 * @return array{sent: array, mail: list<array>, form: string, link: string}
 *         `form` is the page as opened; `link` is the reset link's token from
 *         the message, or ''.
 */
function forgot(string $ip = '', array $more = []): array
{
    global $state;
    mailSince();
    $jar     = newJar();
    $address = $ip === '' ? ip($state['nextIp']++) : $ip;
    $form    = req($jar, 'GET', '/forgot-password', null, 0, $address);
    $sent    = req($jar, 'POST', '/forgot-password/save', array_merge(['csrf_token' => token($form['body']), 'email' => mailOf('asker')], $more), 0, $address);
    settle();
    $mail = mailSince();
    $link = '';
    foreach ($mail as $request) {
        if (preg_match('#reset-password\?token=([0-9a-f]{64})#', stripslashes($request['raw']), $m) === 1) {
            $link = $m[1];
        }
    }

    return ['sent' => $sent, 'mail' => $mail, 'form' => $form['body'], 'link' => $link];
}

/** The requests the stand-in was sent to check a bot check's answer, from a list of mailSince(). */
function botChecks(array $mail): array
{
    return array_values(array_filter($mail, static fn (array $request): bool => $request['path'] === '/bot-check'));
}

/**
 * Send the Upgrade page's button, as a browser would, and read the "Stored
 * secrets" card it answers with. The form token comes from the Settings
 * page: the Upgrade page only draws its own form when a migration is waiting.
 *
 * @return array{status: int, body: string, card: string, lists: array<string, list<string>>}
 *         `lists` holds, for each part of the card that is there, the setting
 *         names it gives (an empty list for a part that names none).
 */
function upgrade(string $jar): array
{
    $answer = req($jar, 'POST', '/admin/upgrade', ['csrf_token' => token(settingsPage($jar)['body'])]);

    return upgradeAnswer($answer);
}

/** Read an answer of the Upgrade page: see upgrade(). */
function upgradeAnswer(array $answer): array
{
    $card  = preg_match('#<div class="card mb-4[^"]*" id="upgrade-secrets">(.*?)</div>\s*</div>\s*(?:<!--|<div class="card)#s', $answer['body'], $m) === 1 ? $m[1] : '';
    $lists = [];
    if (preg_match_all('#<p class="mb-[0-9][^"]*" data-secrets="([a-z-]+)">(.*?)</p>(\s*<p class="mb-3">(.*?)</p>)?#s', $card, $parts, PREG_SET_ORDER) > 0) {
        foreach ($parts as $part) {
            preg_match_all('#<code class="me-2 text-break">([^<]*)</code>#', $part[4] ?? '', $names);
            $lists[$part[1]] = array_map(static fn (string $n): string => html_entity_decode($n, ENT_QUOTES, 'UTF-8'), $names[1]);
        }
    }

    return ['status' => $answer['status'], 'body' => $answer['body'], 'card' => $card, 'lists' => $lists];
}

/** Every marked row that holds something, as it is stored now: "id" => [value, mark]. For "nothing changed" checks. */
function markedRows(): array
{
    $rows = [];
    foreach (q("SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE isSensitive = 1 OR settingKey LIKE 'recordings.%' ORDER BY settingID") as $r) {
        $rows[(string) $r['settingID']] = [(string) $r['settingValue'], $r['isSensitive']];
    }

    return $rows;
}

/** The request for a key the stand-in Microsoft 365 was sent, or null. */
function msKeyRequest(array $mail): ?array
{
    foreach ($mail as $request) {
        if (str_starts_with($request['path'], '/ms-login/') === true) {
            return $request;
        }
    }

    return null;
}

/** Was a message handed to the stand-in (either provider)? */
function messageSent(array $mail): bool
{
    foreach ($mail as $request) {
        if (str_starts_with($request['path'], '/graph/') === true || str_starts_with($request['path'], '/gmail/') === true) {
            return true;
        }
    }

    return false;
}

/**
 * Run the REAL start-up (`bootstrap.php` in the scratch copy) in a process of
 * its own and ask it what it holds. It answers with fingerprints, never with
 * values.
 *
 * @param list<string> $keys    Setting names to report on.
 * @param bool         $podcast Also ask for organisation 1's podcast-feed token.
 *
 * @return array<string,mixed> ['values' => [name => ['sha' => …, 'len' => …] | null], 'agree' => […], 'podcast' => sha, 'error' => text]
 */
function startUp(array $keys, bool $podcast = false): array
{
    return runProbe('start-up-probe.php', [(string) json_encode($keys), $podcast === true ? 'podcast' : '']);
}

/**
 * Ask the REAL start-up, in a process of its own, what it makes of the key
 * file AS IT IS NOW, and what the logger makes of some addresses. Unlike
 * startUp() it never asks the portal to encrypt without catching the
 * refusal, so it can be run while there is no key at all.
 *
 * @param list<string> $addresses Addresses to hand to Logger::withoutJobToken().
 *
 * @return array<string,mixed> ['classEncrypts' => bool, 'helperEncrypts' => bool, 'classOpensNothingKey' => bool,
 *                              'helperOpensNothingKey' => bool, 'note' => text, 'addresses' => [address => what is recorded], 'error' => text]
 */
function keyProbe(array $addresses = []): array
{
    return runProbe('key-probe.php', [(string) json_encode($addresses)]);
}

/**
 * Run one of this test's probe scripts in the scratch copy, as a process of
 * its own, and read the JSON it prints.
 *
 * @param list<string> $arguments
 *
 * @return array<string,mixed>
 */
function runProbe(string $script, array $arguments): array
{
    global $state;
    $child = $state['child'];
    $env   = getenv();
    $env['PORTAL_ENV'] = 'prod';
    $proc = proc_open(
        array_merge(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $child . DIRECTORY_SEPARATOR . 'php-errors.log',
                $child . DIRECTORY_SEPARATOR . $script],
            $arguments
        ),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $child . DIRECTORY_SEPARATOR . 'server-output.log', 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($proc) === false) {
        return ['error' => 'the start-up process could not be started'];
    }
    $out  = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($proc);
    $data = json_decode($out, true);
    if (is_array($data) === false) {
        return ['error' => 'start-up ended with exit code ' . $exit . ' and no answer (' . strlen($out) . ' bytes of output)'];
    }

    return $data + ['error' => ''];
}

/**
 * Give EVERY marked setting a stored value made by `$make(name, real value)`
 * and a mark, start the portal up, and name the settings for which start-up
 * did not hand over `$expect(name, real value, stored text)`.
 *
 * @param list<string> $names
 *
 * @return string '' when every one matched; otherwise the first few names, or the start-up error.
 */
function startUpGives(array $names, int $mark, callable $make, callable $expect): string
{
    $want = [];
    foreach ($names as $i => $name) {
        // Every setting gets a value of its own, so one handed to the wrong name would show.
        $real   = 'real-' . $i . '-' . bin2hex(random_bytes(6));
        $stored = (string) $make($name, $real);
        store($name, $stored, $mark);
        $want[$name] = (string) $expect($name, $real, $stored);
    }
    $got = startUp($names);
    if (($got['error'] ?? '') !== '') {
        return (string) $got['error'];
    }
    $wrong = [];
    foreach ($want as $name => $value) {
        $have = $got['values'][$name] ?? null;
        if (is_array($have) === false || $have['sha'] !== hash('sha256', $value)) {
            $wrong[] = $name;
        }
    }

    return $wrong === [] ? '' : count($wrong) . ' of ' . count($names) . ' wrong, starting with: ' . implode(', ', array_slice($wrong, 0, 4));
}

/** Run migration 214 exactly as it is written, every statement. '' when it ran; otherwise the database's error. */
function runMigration214(): string
{
    global $db, $webSrc;
    $sql = (string) file_get_contents($webSrc . DIRECTORY_SEPARATOR . '_sql' . DIRECTORY_SEPARATOR . '214_secret_setting_marks.sql');
    try {
        $db->multi_query($sql);
        do {
            $result = $db->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } while ($db->more_results() === true && $db->next_result() === true);
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return $db->errno === 0 ? '' : $db->error;
}

/**
 * Every place in the database that holds `$needle`: "table (n rows)".
 * Every text-like column of every table is read.
 *
 * @return list<string>
 */
function databaseHolds(string $needle): array
{
    global $dbName;
    $columns = q(
        "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c "
        . "JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME "
        . "WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE' "
        . "AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json','tinyblob','blob','mediumblob','longblob','varbinary','binary')",
        's',
        [$dbName]
    );
    $byTable = [];
    foreach ($columns as $column) {
        $byTable[(string) $column['TABLE_NAME']][] = 'CAST(`' . $column['COLUMN_NAME'] . '` AS CHAR CHARACTER SET utf8mb4)';
    }
    $found = [];
    foreach ($byTable as $table => $parts) {
        $n = (int) one('SELECT COUNT(*) AS n FROM `' . $table . '` WHERE LOCATE(?, CONCAT_WS(CHAR(10), ' . implode(', ', $parts) . ')) > 0', 's', [$needle])['n'];
        if ($n > 0) {
            $found[] = $table . ' (' . $n . ' row' . ($n === 1 ? '' : 's') . ')';
        }
    }

    return $found;
}

/** How many tables databaseHolds() reads, so that "nothing found" cannot mean "nothing read". */
function tablesRead(): int
{
    global $dbName;

    return (int) one("SELECT COUNT(DISTINCT c.TABLE_NAME) AS n FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE' AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')", 's', [$dbName])['n'];
}

/** Which of the markers a text holds: their labels, in order. */
function holds(string $text, array $markers): array
{
    $found = [];
    foreach ($markers as $label => $one) {
        if (str_contains($text, (string) $one) === true) {
            $found[] = (string) $label;
        }
    }

    return $found;
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
foreach ([$port, $mailPort, $devPort] as $onePort) {
    if (portAnswers($onePort) === true) {
        refuse("something already answers on port {$onePort}; this test never drives a server it did not start");
    }
}

// Every installation-wide setting the install script marks as a secret, read
// from the database BEFORE this run changes anything.
$marked = array_map(static fn (array $r): string => (string) $r['settingKey'], q('SELECT settingKey FROM tblSettings WHERE isSensitive = 1 AND siteID IS NULL ORDER BY settingKey'));
if (count($marked) < 50) {
    refuse('the database holds only ' . count($marked) . ' settings marked as secrets; it must hold web/_sql/full_schema.sql, untouched by an interrupted run');
}
// The names migration 214 puts the mark back on, read from the file itself.
$migrationText = (string) file_get_contents($webSrc . DIRECTORY_SEPARATOR . '_sql' . DIRECTORY_SEPARATOR . '214_secret_setting_marks.sql');
if (preg_match('/SET `isSensitive` = 1\s+WHERE `isSensitive` = 0\s+AND `settingKey` IN \((.*?)\);/s', $migrationText, $listed) !== 1
    || preg_match_all("/'([A-Za-z0-9_.]+)'/", $listed[1], $names214) < 1) {
    refuse('could not read the list of names in web/_sql/214_secret_setting_marks.sql');
}
$names214 = $names214[1];

$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

// Remember every row this run may change, before it changes any.
foreach (array_merge($marked, $names214, ORDINARY_KEYS, ['auth.ms365.tenantOnly']) as $name) {
    if (array_key_exists($name, $state['rows']) === false) {
        $state['rows'][$name] = row($name);
    }
}
$podcastBefore    = one("SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = 1");
$state['podcast'] = $podcastBefore ?? true;

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (PEOPLE as $who => $id) {
    $isGlobal = $who === 'global' ? 1 : 0;
    $isLocal  = $who === 'local' ? 1 : 0;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest secrets ' . $who, mailOf($who), $isGlobal, $isGlobal]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, 0)', 'ii', [$id, ($isGlobal === 1 || $isLocal === 1) ? 1 : 0]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
// Believe "X-Forwarded-For" from 127.0.0.1, so this run's records carry addresses of its own.
store('portal.trustedProxies', '127.0.0.1', 0);
store('portal.trustedProxyHeader', 'x-forwarded-for', 0);
// The development copy would otherwise ask for the pre-release sign-in first.
store('portal.gatekeeper.enabled', 'false', 0);
// The sign-in limits part J's numbers are written for, set here, not assumed.
store('auth.rateLimit.maxAttempts', '5', 0);
store('auth.rateLimit.windowMinutes', '15', 0);
store('auth.rateLimit.maxAttemptsByUsername', '10', 0);
store('auth.captcha.priority', 'turnstile,recaptcha,hcaptcha', 0);
// Start from a portal with no mail route and no secret set.
store('mail.provider', 'ms365', 0);
store('mail.fallbackProvider', '', 0);
store('mail.ms365.sharedMailbox', '', 0);
store('mail.defaultFromAddress', '', 0);
store('mail.google.delegateUser', '', 0);
foreach ($marked as $name) {
    store($name, '', 1);
}

// The scratch copy of web/, with a throwaway connection file and a key of this run's own.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'settings-secrets-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.settings-secrets-selftest-owner', $state['token']); // the marker FIRST
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . '); refusing to copy it');
    }
    if ($info->isDir() === true) {
        mkdir($child . $sep . 'web' . $sep . $rel, 0700);
    } else {
        copy((string) $path, $child . $sep . 'web' . $sep . $rel);
        $copied++;
    }
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
$keysDir = $child . $sep . 'web' . $sep . '_auth_keys';
mkdir($keysDir, 0700);
mkdir($child . $sep . 'sessions', 0700);
// The Upgrade page (part K) takes a snapshot of the database before it does anything, and needs somewhere to put it.
mkdir($child . $sep . 'web' . $sep . '_backups', 0700);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($keysDir . $sep . 'auth_creds.php', "<?php\n// Written by tools/settings-secrets-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
$state['key'] = bin2hex(random_bytes(32));
file_put_contents($keysDir . $sep . 'enc.key', $state['key']);

// ✉️ A made-up Google key file, in the SCRATCH COPY only (see tools/sign-in-limits-selftest.php, which this follows).
$googleKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($googleKey === false || openssl_pkey_export($googleKey, $googleKeyText) === false) {
    refuse('this PHP could not make a throwaway key for the stand-in Google route');
}
file_put_contents($keysDir . $sep . GOOGLE_KEY_FILE, (string) json_encode(['client_email' => 'standin@selftest.invalid', 'private_key' => $googleKeyText]));

// 🎯 Point the four addresses the mailer calls at the stand-in, in the SCRATCH
//    COPY only. Exactly four, each found exactly once, or the run stops: a
//    fifth address added later would otherwise be called for real.
$standIn = 'http://127.0.0.1:' . $mailPort;
$swaps   = [
    'Mailer.php' => [
        "'https://graph.microsoft.com/v1.0/users/'" => "'" . $standIn . "/graph/v1.0/users/'",
        "'https://login.microsoftonline.com/'"      => "'" . $standIn . "/ms-login/'",
    ],
    'MailerGoogle.php' => [
        "'https://gmail.googleapis.com/gmail/v1/users/'"  => "'" . $standIn . "/gmail/v1/users/'",
        "curl_init('https://oauth2.googleapis.com/token')" => "curl_init('" . $standIn . "/google-token')",
    ],
];
$swapped = 0;
foreach ($swaps as $file => $pairs) {
    $pathOfFile = $child . $sep . 'web' . $sep . '_core' . $sep . $file;
    $code       = (string) file_get_contents($pathOfFile);
    foreach ($pairs as $from => $to) {
        if (substr_count($code, $from) !== 1) {
            refuse("expected to find {$from} exactly once in the copy of {$file}, and found it " . substr_count($code, $from) . ' time(s)');
        }
        $code = str_replace($from, $to, $code);
        $swapped++;
    }
    if (preg_match('#curl_init\(\s*\'https?://(?!127\.0\.0\.1:' . $mailPort . ')#', $code) === 1) {
        refuse("the copy of {$file} still calls an address that is not the stand-in");
    }
    file_put_contents($pathOfFile, $code);
}
if ($swapped !== 4) {
    refuse("pointed {$swapped} mail addresses at the stand-in; exactly 4 were expected");
}
// 🤖 And the three addresses the bot check asks "was that a person?". Part J
//    stores keys the portal CAN read and sends forms; without this each of
//    those would be a real request to Cloudflare, Google or hCaptcha.
$captchaFile = $child . $sep . 'web' . $sep . '_core' . $sep . 'Captcha.php';
$captchaCode = (string) file_get_contents($captchaFile);
$verifyUrls  = [
    "'https://challenges.cloudflare.com/turnstile/v0/siteverify'",
    "'https://www.google.com/recaptcha/api/siteverify'",
    "'https://hcaptcha.com/siteverify'",
];
foreach ($verifyUrls as $from) {
    if (substr_count($captchaCode, $from) !== 1) {
        refuse("expected to find {$from} exactly once in the copy of Captcha.php, and found it " . substr_count($captchaCode, $from) . ' time(s)');
    }
    $captchaCode = str_replace($from, "'" . $standIn . "/bot-check'", $captchaCode);
}
if (preg_match('#_VERIFY_URL\s*=\s*\'https?://(?!127\.0\.0\.1:' . $mailPort . '/bot-check)#', $captchaCode) === 1) {
    refuse('the copy of Captcha.php still asks an address that is not the stand-in');
}
file_put_contents($captchaFile, $captchaCode);

// The stand-in mail service: says yes to everything, writes down what it was sent, sends nothing.
$standInCode = <<<'STANDIN'
<?php
// Written by tools/settings-secrets-selftest.php for ONE run (deleted with its folder). Test only.
// A stand-in for Microsoft's and Google's mail services, and for the bot-check providers: it records each request and sends nothing.
declare(strict_types=1);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
file_put_contents(__DIR__ . '/standin-requests.jsonl', json_encode(['path' => $path, 'body' => (string) file_get_contents('php://input')]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($path === '/bot-check') {
    // A stand-in for the three bot-check providers: "a person" only when the form carried the word the test uses.
    echo json_encode(['success' => ($_POST['response'] ?? '') === 'a-person', 'score' => 0.9, 'action' => 'submit']);
} elseif (str_starts_with($path, '/ms-login/') === true || $path === '/google-token') {
    echo json_encode(['access_token' => 'standin-token', 'expires_in' => 3600]);
} elseif (str_starts_with($path, '/graph/') === true) {
    http_response_code(202);
} elseif (str_starts_with($path, '/gmail/') === true) {
    echo json_encode(['id' => 'standin-message']);
} else {
    http_response_code(404);
}
return true;
STANDIN;
file_put_contents($child . $sep . 'standin.php', $standInCode);

// The start-up probe: requires the REAL bootstrap.php of the scratch copy, as
// index.php does, and prints fingerprints of what it holds. Never a value.
$probeCode = <<<'PROBE'
<?php
// Written by tools/settings-secrets-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_HOST']      = '127.0.0.1';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '/index.php';
chdir(__DIR__ . '/web/public_html');
ob_start();
require __DIR__ . '/web/_core/bootstrap.php';
$out = ['values' => [], 'agree' => [], 'reached' => true];
foreach ((array) json_decode((string) ($argv[1] ?? '[]'), true) as $key) {
    $value = \Portal\Core\App::settings((string) $key);
    $out['values'][(string) $key] = $value === null ? null : ['sha' => hash('sha256', (string) $value), 'len' => strlen((string) $value)];
}
// Do the new class and the two old helper functions open each other's work?
$sample = 'agree-' . bin2hex(random_bytes(8)) . "\n second line ~\\/+=";
$out['agree']['class opens what encrypt_setting() made']   = \Portal\Core\SecretSettings::open(encrypt_setting($sample)) === $sample;
$out['agree']['decrypt_setting() opens what seal() made']   = decrypt_setting((string) \Portal\Core\SecretSettings::seal($sample)) === $sample;
$out['agree']['seal() leaves an empty value empty']         = \Portal\Core\SecretSettings::seal('') === '';
$out['agree']['open() says no to plain text and to nothing'] = \Portal\Core\SecretSettings::open('true') === null && \Portal\Core\SecretSettings::open('') === null && \Portal\Core\SecretSettings::open($sample) === null;
$marks = [];
foreach ([[1, true], ['1', true], [0, false], ['0', false], [true, false], ['01', false], [1.0, false], [null, false], ['yes', false], [2, false]] as [$flag, $want]) {
    $marks[] = \Portal\Core\SecretSettings::isMarked($flag) === $want;
}
$out['agree']['the mark is on for the number 1 and the text "1" and for nothing else'] = in_array(false, $marks, true) === false;
// Does the record of an error carry what a function was called with?
$probeFails = static function (string $handedOver): never {
    throw new RuntimeException('a failure in code that was handed a secret');
};
try {
    $probeFails('S5trace0123456789');
} catch (Throwable $failure) {
    $out['trace'] = str_contains($failure->getTraceAsString(), 'S5trace') === false && str_contains($failure->getTraceAsString(), 'start-up-probe.php') === true;
}
// Only when asked: this call MAKES a token if the organisation has none.
if (($argv[2] ?? '') === 'podcast') {
    $out['podcast'] = hash('sha256', \Portal\Core\Recordings::podcastToken(1));
}
ob_end_clean();
echo json_encode($out);
PROBE;
file_put_contents($child . $sep . 'start-up-probe.php', $probeCode);

// The key probe: the same real start-up, asked what it makes of the key file
// as it is at that moment. It catches the refusal to encrypt, so it can be
// run while the key file is empty, a folder, or missing.
$keyProbeCode = <<<'KEYPROBE'
<?php
// Written by tools/settings-secrets-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_HOST']      = '127.0.0.1';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '/index.php';
chdir(__DIR__ . '/web/public_html');
ob_start();
require __DIR__ . '/web/_core/bootstrap.php';
// Text "encrypted" with the key an EMPTY key file used to give: the SHA-256 of nothing at all.
$nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$nothing = base64_encode($nonce . sodium_crypto_secretbox('probe', $nonce, sodium_hex2bin(hash('sha256', ''))));
$helperEncrypts = true;
try {
    encrypt_setting('probe');
} catch (Throwable) {
    $helperEncrypts = false;
}
$out = [
    'reached'               => true,
    'classEncrypts'         => \Portal\Core\SecretSettings::seal('probe') !== null,
    'helperEncrypts'        => $helperEncrypts,
    'classOpensNothingKey'  => \Portal\Core\SecretSettings::open($nothing) !== null,
    'helperOpensNothingKey' => decrypt_setting($nothing) !== '',
    'note'                  => \Portal\Core\SecretSettings::keyFileNote(),
    'addresses'             => [],
];
foreach ((array) json_decode((string) ($argv[1] ?? '[]'), true) as $address) {
    $out['addresses'][(string) $address] = \Portal\Core\Logger::withoutJobToken((string) $address);
}
ob_end_clean();
echo json_encode($out);
KEYPROBE;
file_put_contents($child . $sep . 'key-probe.php', $keyProbeCode);

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/settings-secrets-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Settings-Secrets-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Required at the TOP LEVEL, never inside a function: the portal's start-up
// sets $mysqli and $SETTINGS as globals.
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER;
file_put_contents($child . $sep . 'router.php', str_replace('__TOKEN__', $state['token'], $router));

// The three servers: each command as a LIST (no shell in between), ONE process each.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$quiet    = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']];
$maxRaw   = getenv('SELFTEST_MAX_SECONDS');
$maxParts = ($maxRaw === false || (int) $maxRaw < 1) ? [] : ['-d', 'max_execution_time=' . (int) $maxRaw];
/** Start one portal server on one port, as one release channel. Both share the sessions folder, so one sign-in serves both. */
$startPortal = static function (int $onPort, string $channel) use ($child, $sep, $quiet, $env, $maxParts): mixed {
    $env['PORTAL_ENV'] = $channel;

    return proc_open(
        array_merge(
            [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1', '-d', 'display_errors=0'],
            $maxParts,
            ['-S', '127.0.0.1:' . $onPort, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php']
        ),
        $quiet,
        $pipes,
        $child,
        $env
    );
};
$state['mailProc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $mailPort, $child . $sep . 'standin.php'], $quiet, $mailPipes, $child, $env);
$state['proc']     = $startPortal($port, 'prod');
$state['devProc']  = $startPortal($devPort, 'dev');
if (is_resource($state['proc']) === false || is_resource($state['devProc']) === false || is_resource($state['mailProc']) === false) {
    refuse('a server could not be started');
}
$deadline = microtime(true) + 15;
while ((portAnswers($port) === false || portAnswers($mailPort) === false || portAnswers($devPort) === false) && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false || portAnswers($mailPort) === false || portAnswers($devPort) === false) {
    refuse("a server never answered (the portal on ports {$port} and {$devPort}, the stand-in mail service on port {$mailPort})");
}

echo "Secret settings, on the real pages — self-test (#597, #497)\n";
echo 'portal server processes ' . (int) proc_get_status($state['proc'])['pid'] . ' (live copy, port ' . $port . ') and ' . (int) proc_get_status($state['devProc'])['pid']
    . ' (development copy, port ' . $devPort . '), stand-in mail process ' . (int) proc_get_status($state['mailProc'])['pid'] . ' on port ' . $mailPort
    . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo count($marked) . ' installation-wide settings are marked as secrets in this database; migration 214 names ' . count($names214) . ".\n";

// The secrets this run types. Their markers are what part I searches for.
$S = [
    'page'     => secret('pg'), // B: saved through the edit box
    'replaced' => secret('rp'), // B: the value that replaces it
    'own'      => secret('ow'), // D: an organisation's own secret
    'left'     => secret('lf'), // E: a secret #597 left as plain text
    'foreign'  => secret('fr'), // F: encrypted with another key file
    'nokey'    => secret('nk'), // F: typed while the key file is missing
    'mail'     => secret('ml'), // G: the Microsoft 365 client secret
    'job'      => 'S5jb' . bin2hex(random_bytes(12)), // G: a scheduled job's token (it travels in a web address)
    'captcha'  => secret('cp'), // H: a bot check's secret key
    'qr'       => 'S5qr' . bin2hex(random_bytes(4)), // H: 12 characters, so "one dot per character" would show 12
    'plain'    => secret('pn'), // I: held under a setting whose NAME does not look like a secret's
    'bot'      => secret('bt'), // J: the bot check's secret key
    'up1'      => secret('u1'), // K: stored as readable text, mark cleared (what #597 left)
    'up2'      => 'S5u2' . bin2hex(random_bytes(30)), // K: the same, but 64 letters and digits, so it LOOKS encrypted
    'up3'      => secret('u3'), // K: an organisation's own copy, readable, mark cleared
    'up4'      => secret('u4'), // K: readable text with the mark on
    'up5'      => secret('u5'), // K: properly encrypted already
    'up6'      => secret('u6'), // K: encrypted twice over already
    'up7'      => secret('u7'), // K: readable, marked, and the database refuses to rewrite its row
    'up8'      => secret('u8'), // K: readable, marked, found while the key file is missing
    'up9'      => secret('u9'), // K: readable, marked, and changed by somebody while the upgrade runs
];
$markers = array_map('marker', $S);

// =============================================================================
head('A. Start-up hands every reader the right value, whatever state a row is in');
// =============================================================================
$first = startUp([]);
ok('The portal starts with every secret empty, and the start-up probe reaches its end', ($first['error'] ?? 'x') === '' && ($first['reached'] ?? false) === true, (string) ($first['error'] ?? ''));
foreach ((array) ($first['agree'] ?? ['(no answer)' => false]) as $what => $agreed) {
    ok('The new class and the old helpers agree: ' . $what, $agreed === true);
}
ok('Once started, the record PHP keeps of an error names each step but not what it was handed, so a failure cannot write a secret into the error log', ($first['trace'] ?? false) === true);
ok(
    'Encrypted once, marked: every one of the ' . count($marked) . ' is handed over as its REAL value (#497)',
    ($why = startUpGives($marked, 1, static fn (string $n, string $real): string => seal($real), static fn (string $n, string $real, string $stored): string => $real)) === '',
    $why
);
ok(
    'Encrypted three times over, marked (what Admin, Captcha used to do on every save): the real value',
    ($why = startUpGives($marked, 1, static fn (string $n, string $real): string => seal(seal(seal($real))), static fn (string $n, string $real, string $stored): string => $real)) === '',
    $why
);
// Short plain text is the case that made the old decrypt call THROW: fewer bytes than libsodium needs.
$shorts = ['true', 'abc', '1', 'hello world', 'ABCDEFGHIJKLMNOPQRSTUVWX'];
ok(
    'Plain text with the mark ON (some of it too short to decrypt at all): start-up does not stop, and the stored text is used',
    ($why = startUpGives($marked, 1, static fn (string $n, string $real): string => crc32($n) % 2 === 0 ? $shorts[crc32($n) % 5] : $real, static fn (string $n, string $real, string $stored): string => $stored)) === '',
    $why
);
ok(
    'Plain text with the mark OFF (what #597 left behind): the stored text is used, as it is today',
    ($why = startUpGives($marked, 0, static fn (string $n, string $real): string => $real, static fn (string $n, string $real, string $stored): string => $stored)) === '',
    $why
);
$otherKey = bin2hex(random_bytes(32));
ok(
    'Encrypted with a DIFFERENT key file, marked: the stored text is used, as it is today',
    ($why = startUpGives($marked, 1, static fn (string $n, string $real): string => sealWith($otherKey, $real), static fn (string $n, string $real, string $stored): string => $stored)) === '',
    $why
);
ok(
    'An encrypted EMPTY value, marked: handed over as empty, not as 56 characters of scramble',
    ($why = startUpGives($marked, 1, static fn (string $n, string $real): string => seal(''), static fn (string $n, string $real, string $stored): string => '')) === '',
    $why
);
// A plain secret that is exactly 40 bytes of base64 has the same shape as an encrypted empty value.
ok(
    'Plain text that has the exact shape of an encrypted empty value, marked: the stored text is used, not "empty"',
    ($why = startUpGives($marked, 1, static fn (string $n, string $real): string => base64_encode(random_bytes(40)), static fn (string $n, string $real, string $stored): string => $stored)) === '',
    $why
);
// The key is the SHA-256 of the WHOLE key file, so a file of any length is a
// working key, and an installation may have every secret stored under one.
// (The check of 13 October 2026 proposed refusing a file under 32 bytes. That
// would have lost all of them.) Each kind below is put in place in turn; all
// the marked settings are encrypted under it by THIS file's own copy of the
// encryption, which is the old code's; and start-up must hand back every one.
$keyFile  = $keysDir . $sep . 'enc.key';
$keyKinds = [
    'one byte'                                 => 'k',
    'the single character 0'                   => '0',
    'one line break and nothing else'          => "\n",
    'two bytes'                                => 'ab',
    '16 bytes'                                 => bin2hex(random_bytes(8)),
    '31 bytes'                                 => substr(bin2hex(random_bytes(16)), 0, 31),
    '32 raw bytes that are not text'           => random_bytes(31) . "\0",
    '33 bytes'                                 => substr(bin2hex(random_bytes(17)), 0, 33),
    '63 bytes'                                 => substr(bin2hex(random_bytes(32)), 0, 63),
    'the installer\'s 64 and a line break'     => bin2hex(random_bytes(32)) . "\n",
    '128 bytes'                                => bin2hex(random_bytes(64)),
    '4096 bytes'                               => bin2hex(random_bytes(2048)),
];
$keyKindsWrong = [];
foreach ($keyKinds as $kind => $contents) {
    file_put_contents($keyFile, $contents);
    $why   = startUpGives($marked, 1, static fn (string $n, string $real): string => sealWith($contents, $real), static fn (string $n, string $real, string $stored): string => $real);
    $agree = (array) (startUp([])['agree'] ?? ['(no answer)' => false]);
    if ($why !== '' || in_array(false, $agree, true) === true || count($agree) < 5) {
        $keyKindsWrong[] = $kind . ' (' . ($why !== '' ? $why : 'the class and the old helpers disagree') . ')';
    }
}
file_put_contents($keyFile, $state['key']);
ok(
    'A key file of EVERY length still works exactly as before (' . count($keyKinds) . ' kinds, from one byte to 4096): each of the ' . count($marked)
        . ' secrets encrypted under it is handed over as its real value, and the class and the old helpers still open each other\'s work',
    $keyKindsWrong === [] && count($keyKinds) === 12,
    implode('; ', array_slice($keyKindsWrong, 0, 4))
);
foreach ($marked as $name) {
    store($name, '', 1);
}

// =============================================================================
head('B. A secret saved through the Settings page is stored encrypted and shown to nobody');
// =============================================================================
$global = signIn('global', $password);
$local  = signIn('local', $password);
$member = signIn('member', $password);
if ($global === '' || $local === '' || $member === '') {
    refuse('one of the three people could not sign in through the real page, so nothing further can be tested');
}
$KEY = 'auth.ms365.appwide.clientSecret';

$pageBefore = settingsPage($global);
ok(
    'The Settings page opens for a global administrator, with no "eye" button and no hidden copy of any value',
    $pageBefore['status'] === 200 && str_contains($pageBefore['body'], 'portal-reveal-btn') === false && str_contains($pageBefore['body'], 'revealed-text') === false
        && str_contains($pageBefore['body'], 'portal-masked-value') === false && str_contains($pageBefore['body'], 'fa-eye"') === false
        && str_contains($pageBefore['body'], 'data-setting-key="' . $KEY . '"') === true,
    'HTTP ' . $pageBefore['status']
);
ok('An unset secret is shown as "Not set"', str_contains(rowHtml($pageBefore['body'], $KEY), '>Not set<') === true);

// Exactly what the edit box sends: the four fields, and NO "this is a secret" field.
$saved  = edit($global, $KEY, $S['page']);
$stored = row($KEY);
ok('Saving it is answered with "updated", and says it will not be shown again', $saved['answer']['status'] === 302 && str_starts_with($saved['said'], 'success: Setting "' . $KEY . '" updated. It is stored encrypted') === true, $saved['said']);
ok('The stored row is still MARKED as a secret (the mark is the number 1)', $stored !== null && $stored['isSensitive'] === 1, 'mark: ' . var_export($stored['isSensitive'] ?? null, true));
ok(
    'The stored text is NOT what was typed, and this run\'s key opens it to exactly what was typed',
    $stored !== null && str_contains((string) $stored['settingValue'], marker($S['page'])) === false && unseal((string) $stored['settingValue']) === $S['page']
);
ok('It is not in the page it was saved from, in any form', holds($saved['page'], $markers) === [] && str_contains($saved['page'], substr((string) $stored['settingValue'], 0, 24)) === false);
ok('That page says "Set", and that it is stored encrypted', str_contains(rowHtml($saved['page'], $KEY), '>Set<') === true && str_contains(rowHtml($saved['page'], $KEY), 'Stored encrypted. Never shown.') === true);
ok('The edit button carries no value for a secret', preg_match('/data-key="' . preg_quote($KEY, '/') . '"\s+data-value=""\s+data-sensitive="1"/', $saved['page']) === 1);

$localPage = settingsPage($local);
ok(
    'An administrator of ONE organisation: the page opens, and holds neither the secret nor its stored text',
    $localPage['status'] === 200 && holds($localPage['body'], $markers) === [] && str_contains($localPage['body'], substr((string) $stored['settingValue'], 0, 24)) === false,
    'HTTP ' . $localPage['status']
);
ok(
    'They are not even told whether an installation-wide secret is set',
    str_contains(rowHtml($localPage['body'], $KEY), 'Not shown. A global administrator looks after this one.') === true
        && str_contains(rowHtml($localPage['body'], $KEY), '>Set<') === false && str_contains(rowHtml($localPage['body'], $KEY), '>Not set<') === false
        && str_contains(rowHtml($localPage['body'], 'payments.stripe.secret'), 'Not shown.') === true
);
ok('An ordinary member is refused the page', settingsPage($member)['status'] === 403);

$kept = edit($global, $KEY, '');
ok(
    'An EMPTY box keeps the stored secret, byte for byte, and says so',
    (row($KEY)['settingValue'] ?? '') === $stored['settingValue'] && row($KEY)['isSensitive'] === 1 && str_starts_with($kept['said'], 'info: Nothing was changed.') === true,
    $kept['said']
);
$replaced = edit($global, $KEY, $S['replaced']);
ok('A new value replaces it, encrypted, still marked', unseal((string) row($KEY)['settingValue']) === $S['replaced'] && row($KEY)['isSensitive'] === 1 && holds($replaced['page'], $markers) === []);
$cleared = edit($global, $KEY, 'this is ignored', ['clearValue' => '1']);
ok(
    'The "Remove the stored value" tick empties it; the row stays marked; the page says "Not set"',
    (row($KEY)['settingValue'] ?? 'x') === '' && row($KEY)['isSensitive'] === 1 && str_contains(rowHtml($cleared['page'], $KEY), '>Not set<') === true
        && str_starts_with($cleared['said'], 'success: The stored value of') === true,
    $cleared['said']
);
// A form that claims the row is "not a secret", or that it is one, changes nothing about the mark.
edit($global, $KEY, $S['page']);
edit($global, 'site.name', 'Selftest Portal', ['isSensitive' => '1']);
ok(
    'An ordinary setting is still stored as typed, stays ordinary whatever the form claims, and is still shown',
    (row('site.name')['settingValue'] ?? '') === 'Selftest Portal' && row('site.name')['isSensitive'] === 0 && str_contains(rowHtml(settingsPage($global)['body'], 'site.name'), 'Selftest Portal') === true
);
ok('…and an ordinary setting is still emptied by saving an empty box', edit($global, 'site.name', '')['answer']['status'] === 302 && (row('site.name')['settingValue'] ?? 'x') === '');

// =============================================================================
head('C. The same protections as before: who may save, the form token, POST only');
// =============================================================================
$before = row($KEY);
$same   = static fn (): bool => row($KEY)['settingValue'] === $before['settingValue'] && row($KEY)['isSensitive'] === $before['isSensitive'];

$noToken = req($global, 'POST', '/settings/save', ['settingID' => (string) $before['settingID'], 'settingKey' => $KEY, 'settingValue' => 'no-token']);
ok('No form token: refused, nothing changed', $same() === true && str_starts_with(said(settingsPage($global)['body']), 'danger: Invalid or expired form token') === true);

$byGet = req($global, 'GET', '/settings/save?settingID=' . $before['settingID'] . '&settingKey=' . $KEY . '&settingValue=by-get&csrf_token=' . token(settingsPage($global)['body']));
ok('A GET: sent back to the page, nothing changed, and nothing treated as a failed save', $byGet['status'] === 302 && $byGet['location'] === '/settings' && $same() === true && said(settingsPage($global)['body']) === '');

$byLocal   = edit($local, $KEY, 'typed-by-an-organisation-administrator');
$refusals  = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'SettingsUpdateRefused' AND activityDescription = ?", 'is', [PEOPLE['local'], 'Refused: portal-wide setting "' . $KEY . '" may only be changed by a global administrator'])['n'];
ok('An administrator of one organisation cannot change an installation-wide secret: refused, and told why', $same() === true && str_starts_with($byLocal['said'], 'danger: That setting is portal-wide') === true, $byLocal['said']);
ok('…and the refusal is recorded, as it was before', $refusals === 1, $refusals . ' record(s)');
$byLocalClear = edit($local, $KEY, '', ['clearValue' => '1']);
ok('…nor remove it with the tick', $same() === true && str_starts_with($byLocalClear['said'], 'danger: That setting is portal-wide') === true, $byLocalClear['said']);

$byMember = req($member, 'POST', '/settings/save', ['csrf_token' => token(req($member, 'GET', '/')['body']), 'settingID' => (string) $before['settingID'], 'settingKey' => $KEY, 'settingValue' => 'by-a-member']);
ok('An ordinary member: refused, nothing changed', $byMember['status'] === 403 && $same() === true, 'HTTP ' . $byMember['status']);
$byNobody = req(newJar(), 'POST', '/settings/save', ['settingID' => (string) $before['settingID'], 'settingKey' => $KEY, 'settingValue' => 'by-nobody']);
ok('Nobody signed in: sent to sign in, nothing changed', $byNobody['status'] === 302 && str_contains($byNobody['location'], 'login') === true && $same() === true, 'HTTP ' . $byNobody['status'] . ' ' . $byNobody['location']);

// =============================================================================
head('D. An organisation\'s own secret, added by its own administrator');
// =============================================================================
$ownKey = OWN_PREFIX . 'apiKey';
$addTo  = static function (string $jar, string $key, string $value): array {
    $page   = settingsPage($jar);
    $answer = req($jar, 'POST', '/settings/save', ['csrf_token' => token($page['body']), 'settingKey' => $key, 'settingValue' => $value, 'isSensitive' => '1']);
    $after  = settingsPage($jar);

    return ['answer' => $answer, 'said' => said($after['body']), 'page' => $after['body']];
};
$ownRow = static fn (string $key): ?array => one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID = 1', 's', [$key]);
$added  = $addTo($local, $ownKey, $S['own']);
ok(
    'Added with the "Sensitive" box ticked: stored encrypted and marked, for that organisation',
    $ownRow($ownKey) !== null && $ownRow($ownKey)['isSensitive'] === 1 && unseal((string) $ownRow($ownKey)['settingValue']) === $S['own'],
    $added['said']
);
ok('Its own administrator is told it is set, and is not shown it', str_contains(rowHtml($added['page'], $ownKey), '>Set<') === true && holds($added['page'], $markers) === []);
$ownEdit = edit($local, $ownKey, $S['own'] . '-again', [], (int) $ownRow($ownKey)['settingID']);
ok('Edited by its own administrator: still encrypted, still marked', $ownRow($ownKey)['isSensitive'] === 1 && unseal((string) $ownRow($ownKey)['settingValue']) === $S['own'] . '-again' && holds($ownEdit['page'], $markers) === [], $ownEdit['said']);
$addTo($local, OWN_PREFIX . 'empty', '');
ok('Added as a secret with NO value: stored empty (not 56 characters of scramble), and shown as "Not set"', ($ownRow(OWN_PREFIX . 'empty')['settingValue'] ?? 'x') === '' && $ownRow(OWN_PREFIX . 'empty')['isSensitive'] === 1 && str_contains(rowHtml(settingsPage($local)['body'], OWN_PREFIX . 'empty'), '>Not set<') === true);

// =============================================================================
head('E. Secrets #597 already left as plain text: they keep working, stop being shown, and are encrypted on the next save');
// =============================================================================
$unlisted = array_values(array_diff($marked, $names214, SEEDED_SINCE));
// A name 214 lists must be a secret here, or not be in this database at all
// (one that another package's migration adds, on a database from before it).
$notSecrets = array_values(array_filter(array_diff($names214, $marked), static fn (string $name): bool => row($name) !== null));
ok(
    'Migration 214 names all ' . count($names214) . ' settings that were seeded as secrets while #597 was live, each once, and nothing that is not a secret',
    $unlisted === [] && $notSecrets === [] && count($names214) === count(array_unique($names214)) && count($names214) === NAMED_BY_214,
    'marked but not named: ' . implode(', ', $unlisted) . ' | named but not a secret here: ' . implode(', ', $notSecrets) . ' | ' . count($names214) . ' named, ' . NAMED_BY_214 . ' expected'
);
// The state p4 found on 12 October 2026: plain text, mark cleared, for all three Microsoft 365 values.
store('auth.ms365.tenantID', 'left-tenant', 0);
store('auth.ms365.appwide.clientID', 'left-client', 0);
store($KEY, $S['left'], 0);
store('mail.defaultFromAddress', SENDER, 0);
store('mail.provider', 'ms365', 0);
// The row a fresh install used to seed: a yes/no, marked, holding plain text.
store('auth.ms365.tenantOnly', 'true', 1);
// One organisation's podcast-feed token in the same state. Migration 214 must leave it alone.
q("DELETE FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = 1");
$podcast = bin2hex(random_bytes(32));
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'recordings.podcast_token', ?, '', 0)", 's', [$podcast]);

$mailBefore = forgot();
$keyBefore  = msKeyRequest($mailBefore['mail']);
ok('BEFORE the migration it works: one real "Forgot password", and the stand-in is sent the secret exactly as stored', $keyBefore !== null && ($keyBefore['form']['client_secret'] ?? null) === $S['left'] && messageSent($mailBefore['mail']) === true);
ok('…and, before the migration, it is on the page in full (this is #597; the migration is what ends it)', holds(settingsPage($global)['body'], ['left' => $markers['left']]) === ['left']);

$rowsBefore = (int) one('SELECT COUNT(*) AS n FROM tblSettings')['n'];
$ran        = runMigration214();
$afterOnce  = (int) one('SELECT COUNT(*) AS n FROM tblSettings')['n'];
ok('Migration 214 runs', $ran === '', $ran);
ok(
    'It puts the mark back and changes no value',
    row($KEY)['isSensitive'] === 1 && row($KEY)['settingValue'] === $S['left'] && row('auth.ms365.tenantID')['isSensitive'] === 1 && row('auth.ms365.tenantID')['settingValue'] === 'left-tenant'
        && row('auth.ms365.appwide.clientID')['isSensitive'] === 1
);
ok('It takes the mark OFF auth.ms365.tenantOnly, a yes/no that was never a secret', row('auth.ms365.tenantOnly')['isSensitive'] === 0 && row('auth.ms365.tenantOnly')['settingValue'] === 'true');
ok('It leaves an ordinary setting alone', row('mail.defaultFromAddress')['isSensitive'] === 0 && row('mail.provider')['isSensitive'] === 0);
$podcastRow = one("SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = 1");
$podcastNow = startUp([], true);
ok(
    'It leaves an organisation\'s podcast-feed token exactly as it was, and the portal still answers with that same token',
    $podcastRow !== null && $podcastRow['isSensitive'] === 0 && $podcastRow['settingValue'] === $podcast && ($podcastNow['podcast'] ?? '') === hash('sha256', $podcast)
);
$ranAgain = runMigration214();
store('auth.ms365.tenantOnly', 'something-else', 1);
$ranThird = runMigration214();
ok(
    'Run a second and a third time: no row added (' . $rowsBefore . ' before, ' . $afterOnce . ' after one run), marks unchanged, recorded once',
    $ranAgain === '' && $ranThird === '' && $afterOnce === $rowsBefore && (int) one('SELECT COUNT(*) AS n FROM tblSettings')['n'] === $rowsBefore && row($KEY)['isSensitive'] === 1
        && (int) one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '214_secret_setting_marks.sql'")['n'] === 1,
    $ranAgain . $ranThird
);
ok('A tenantOnly row holding anything but a plain yes/no keeps its mark, so it stays hidden', row('auth.ms365.tenantOnly')['isSensitive'] === 1);
store('auth.ms365.tenantOnly', 'true', 0);

$mailAfter = forgot();
$keyAfter  = msKeyRequest($mailAfter['mail']);
ok('AFTER the migration it STILL works: the stand-in is sent the secret exactly as stored, though the row is marked and is not encrypted', $keyAfter !== null && ($keyAfter['form']['client_secret'] ?? null) === $S['left'] && ($keyAfter['form']['client_id'] ?? null) === 'left-client' && messageSent($mailAfter['mail']) === true);

$flagged      = settingsPage($global);
$flaggedLocal = settingsPage($local);
ok('It is no longer in the page, for a global administrator or for an administrator of one organisation', holds($flagged['body'], $markers) === [] && holds($flaggedLocal['body'], $markers) === []);
preg_match('#<div class="alert alert-warning" id="unprotected-secrets">(.*?)</div>#s', $flagged['body'], $notice);
ok(
    'A global administrator is told, by name and without the value, that it needs entering again, why (both causes), and what to do',
    isset($notice[1]) === true && str_contains($notice[1], '<code class="me-2 text-break">' . $KEY . '</code>') === true && str_contains($notice[1], 'auth.ms365.tenantID') === true
        && preg_match('/3 secret\s+settings need\s+entering again/', $notice[1]) === 1 && str_contains($notice[1], 'stored unprotected') === true
        && str_contains($notice[1], 'key file has been replaced') === true && preg_match('/enter the\s+value again/', $notice[1]) === 1
        && str_contains(rowHtml($flagged['body'], $KEY), 'Set, but needs entering again') === true
);
ok('An administrator of one organisation is told nothing about it', str_contains($flaggedLocal['body'], 'unprotected-secrets') === false && str_contains(rowHtml($flaggedLocal['body'], $KEY), 'entering again') === false);

$reentered = edit($global, $KEY, $S['left']);
ok('Entered again through the edit box: now stored encrypted, and no longer named in the notice', unseal((string) row($KEY)['settingValue']) === $S['left'] && row($KEY)['isSensitive'] === 1 && preg_match('/2 secret\s+settings need/', $reentered['page']) === 1 && str_contains($reentered['page'], '<code class="me-2 text-break">' . $KEY . '</code>') === false && str_contains(rowHtml($reentered['page'], $KEY), '>Set<') === true);
$mailReentered = forgot();
ok('…and mail still works with it', (msKeyRequest($mailReentered['mail'])['form']['client_secret'] ?? null) === $S['left'] && messageSent($mailReentered['mail']) === true);

// =============================================================================
head('F. A marked row that does not decrypt never stops the portal, and is used as stored');
// =============================================================================
store($KEY, 'abc', 1); // three characters: the old decrypt call threw on this
$short = forgot();
ok('Three characters of plain text in a marked row: the sign-in page still opens, and the stand-in is sent those three characters', req(newJar(), 'GET', '/login')['status'] === 200 && (msKeyRequest($short['mail'])['form']['client_secret'] ?? null) === 'abc');

$foreignStored = sealWith($otherKey, $S['foreign']);
store($KEY, $foreignStored, 1);
$foreign = forgot();
ok('Encrypted with a different key file: the stand-in is sent the stored text unchanged, as it is today', (msKeyRequest($foreign['mail'])['form']['client_secret'] ?? null) === $foreignStored);
ok('…and the page says that row needs entering again, without showing it', str_contains(rowHtml(settingsPage($global)['body'], $KEY), 'Set, but needs entering again') === true && str_contains(settingsPage($global)['body'], substr($foreignStored, 0, 24)) === false);

store($KEY, seal(''), 1);
$emptied = forgot();
ok('An encrypted empty value: the portal treats the secret as not set (nothing is sent), and the page says "Not set"', $emptied['mail'] === [] && str_contains(rowHtml(settingsPage($global)['body'], $KEY), '>Not set<') === true);

// The key file goes missing.
store($KEY, seal($S['mail']), 1);
rename($keysDir . $sep . 'enc.key', $keysDir . $sep . 'enc.key.away');
$noKeyPage = settingsPage($global);
ok('With the key file MISSING the portal still starts, and the page says every stored secret needs entering again, without showing any', $noKeyPage['status'] === 200 && str_contains(rowHtml($noKeyPage['body'], $KEY), 'Set, but needs entering again') === true && holds($noKeyPage['body'], $markers) === []);
$keptRow   = row($KEY)['settingValue'];
$noKeySave = edit($global, $KEY, $S['nokey']);
ok(
    'Saving a secret then is REFUSED and nothing is stored; the person is told the key file is missing',
    row($KEY)['settingValue'] === $keptRow && str_starts_with($noKeySave['said'], 'danger: Nothing was saved. "' . $KEY . '" is a secret, and the portal could not encrypt it') === true,
    $noKeySave['said']
);
$noKeyAdd = $addTo($local, OWN_PREFIX . 'nokey', $S['nokey']);
ok('Adding a secret then is refused too, and no row is made', $ownRow(OWN_PREFIX . 'nokey') === null && str_starts_with($noKeyAdd['said'], 'danger: Nothing was saved.') === true, $noKeyAdd['said']);
rename($keysDir . $sep . 'enc.key.away', $keysDir . $sep . 'enc.key');
ok('With the key file back, the same row is "Set" again', str_contains(rowHtml(settingsPage($global)['body'], $KEY), '>Set<') === true);

// =============================================================================
head('G. #497 closed end to end: a secret saved through the page reaches the provider exactly as typed');
// =============================================================================
foreach (['auth.ms365.tenantID', 'auth.ms365.appwide.clientID', $KEY] as $name) {
    store($name, '', 1);
}
$tenant = 'tenant-' . bin2hex(random_bytes(6));
$client = 'client-' . bin2hex(random_bytes(6));
edit($global, 'auth.ms365.tenantID', $tenant);
edit($global, 'auth.ms365.appwide.clientID', $client);
edit($global, $KEY, $S['mail']);
ok(
    'A tenant, a client ID and a client secret, each saved through the edit box: all three stored encrypted and marked',
    unseal((string) row('auth.ms365.tenantID')['settingValue']) === $tenant && unseal((string) row('auth.ms365.appwide.clientID')['settingValue']) === $client
        && unseal((string) row($KEY)['settingValue']) === $S['mail'] && row($KEY)['isSensitive'] === 1 && row('auth.ms365.tenantID')['isSensitive'] === 1
);
$end = forgot();
$key = msKeyRequest($end['mail']);
ok('ONE real "Forgot password": the stand-in Microsoft 365 is asked for a key at the tenant that was typed', $key !== null && $key['path'] === '/ms-login/' . $tenant . '/oauth2/v2.0/token', $key === null ? 'no request for a key arrived' : $key['path']);
ok('…with the client ID exactly as typed', ($key['form']['client_id'] ?? null) === $client);
ok('…and the client SECRET exactly as typed: ' . strlen($S['mail']) . ' characters, quotes, slashes and all', ($key['form']['client_secret'] ?? null) === $S['mail'], 'the stand-in was sent ' . strlen((string) ($key['form']['client_secret'] ?? '')) . ' characters');
ok('…and the message is then handed over', messageSent($end['mail']) === true && $end['sent']['status'] === 302);

// Google: the secret setting is the NAME of the key file.
store('mail.provider', 'google', 0);
store('mail.google.delegateUser', SENDER, 0);
edit($global, 'mail.google.serviceAccountKeyFile', GOOGLE_KEY_FILE);
$googleRow = row('mail.google.serviceAccountKeyFile');
$google    = forgot();
ok(
    'Through Google: the key file\'s name, saved through the page and stored encrypted, is read correctly; a signed request and the message arrive',
    $googleRow['isSensitive'] === 1 && unseal((string) $googleRow['settingValue']) === GOOGLE_KEY_FILE
        && in_array('/google-token', array_column($google['mail'], 'path'), true) === true && messageSent($google['mail']) === true,
    implode(', ', array_column($google['mail'], 'path'))
);
store('mail.provider', 'ms365', 0);

// A scheduled job's token.
edit($global, 'maintenance.cronToken', $S['job']);
$jobRight = req('', 'GET', '/cron/health?token=' . $S['job']);
$jobWrong = req('', 'GET', '/cron/health?token=' . rawurlencode((string) row('maintenance.cronToken')['settingValue']));
ok(
    'A scheduled job\'s token saved through the page: stored encrypted, the job is let in with the token as typed, and refused with the stored scramble',
    unseal((string) row('maintenance.cronToken')['settingValue']) === $S['job'] && str_contains($jobRight['body'], '"probes"') === true && str_contains($jobRight['body'], 'invalid_token') === false
        && $jobWrong['status'] === 403 && str_contains($jobWrong['body'], 'invalid_token') === true,
    'right token: HTTP ' . $jobRight['status'] . '; stored scramble: HTTP ' . $jobWrong['status']
);

// =============================================================================
head('H. Two pages that would have printed a real secret once start-up decrypts');
// =============================================================================
$captchaPost = static function (string $jar, array $fields): array {
    $page = req($jar, 'GET', '/admin/captcha');

    return req($jar, 'POST', '/admin/captcha/save', array_merge(
        ['csrf_token' => token($page['body']), 'action' => 'keys', 'turnstile_site' => '', 'turnstile_secret' => '', 'recaptcha_site' => '', 'recaptcha_secret' => '',
            'recaptcha_version' => 'v2', 'recaptcha_v3_action' => 'submit', 'recaptcha_v3_threshold' => '0.5', 'hcaptcha_site' => '', 'hcaptcha_secret' => ''],
        $fields
    ));
};
$siteKey = 'site-key-' . bin2hex(random_bytes(6));
$captchaPost($global, ['turnstile_site' => $siteKey, 'turnstile_secret' => $S['captcha']]);
$captchaPage = req($global, 'GET', '/admin/captcha');
ok('Admin, Captcha: a secret key saved there is stored encrypted, once', unseal((string) row('auth.turnstile.secretKey')['settingValue']) === $S['captcha'] && unseal((string) row('auth.turnstile.siteKey')['settingValue']) === $siteKey);
ok(
    'The page does not print the secret key, for a global administrator; its box is empty and says the key is stored',
    $captchaPage['status'] === 200 && holds($captchaPage['body'], $markers) === [] && str_contains($captchaPage['body'], 'placeholder="Stored. Leave empty to keep it"') === true
        && preg_match('/name="turnstile_secret"\s+value=""/', $captchaPage['body']) === 1
);
ok('…nor for an administrator of one organisation', holds(req($local, 'GET', '/admin/captcha')['body'], $markers) === []);
// The sign-in page is only OPENED here. Nothing is sent to it while a bot check is set up.
ok('The bot check on the sign-in page is given the real site key (it is public by design)', str_contains(req(newJar(), 'GET', '/login')['body'], 'data-sitekey="' . $siteKey . '"') === true);
$captchaPost($global, ['turnstile_site' => $siteKey . '-b', 'turnstile_secret' => '']);
ok(
    'Saved again with the secret box EMPTY: the stored secret key is kept, still encrypted once, and the site key changes',
    unseal((string) row('auth.turnstile.secretKey')['settingValue']) === $S['captcha'] && unseal((string) row('auth.turnstile.siteKey')['settingValue']) === $siteKey . '-b'
);
$captchaPost($global, []);
ok('Both boxes emptied: the secret key is removed with the site key', (row('auth.turnstile.secretKey')['settingValue'] ?? 'x') === '' && (row('auth.turnstile.siteKey')['settingValue'] ?? 'x') === '');
// With the key file gone the keys cannot be encrypted. The page must say so and store nothing, not fall over.
rename($keysDir . $sep . 'enc.key', $keysDir . $sep . 'enc.key.away');
$noKeyCaptcha = $captchaPost($global, ['turnstile_site' => $siteKey, 'turnstile_secret' => $S['captcha']]);
$noKeySaid    = said(req($global, 'GET', '/admin/captcha')['body']);
rename($keysDir . $sep . 'enc.key.away', $keysDir . $sep . 'enc.key');
ok(
    'Admin, Captcha with the key file MISSING: saving keys is refused in plain words and nothing is stored (no error page)',
    $noKeyCaptcha['status'] === 302 && str_starts_with($noKeySaid, 'danger: Nothing was saved. The keys are secrets, and the portal could not encrypt them') === true
        && (row('auth.turnstile.secretKey')['settingValue'] ?? 'x') === '' && (row('auth.turnstile.siteKey')['settingValue'] ?? 'x') === '',
    'HTTP ' . $noKeyCaptcha['status'] . '; ' . $noKeySaid
);
if ((row('auth.turnstile.secretKey')['settingValue'] ?? 'x') !== '' || (row('auth.turnstile.siteKey')['settingValue'] ?? 'x') !== '') {
    refuse('a bot check is still set up, and the next part posts to forms it protects');
}

store('portal.qr.cuercode.api_key', seal($S['qr']), 1);
$qrPage      = req($global, 'GET', '/admin/settings/qr');
$qrPageLocal = req($local, 'GET', '/admin/settings/qr');
ok(
    'The QR page shows eight dots for a twelve-character key, to both administrators, and never the key',
    $qrPage['status'] === 200 && str_contains($qrPage['body'], 'placeholder="' . str_repeat('•', 8) . ' (currently set)"') === true && str_contains($qrPage['body'], str_repeat('•', 9)) === false
        && $qrPageLocal['status'] === 200 && str_contains($qrPageLocal['body'], str_repeat('•', 9)) === false && holds($qrPage['body'] . $qrPageLocal['body'], $markers) === [],
    'HTTP ' . $qrPage['status'] . ' and ' . $qrPageLocal['status']
);

// =============================================================================
head('J. The bot check when its keys are stored but cannot be read: sign-in and password reset go on, every other form is refused');
// =============================================================================
// Every place that asks the bot check "was that a person?", read from the code itself.
$callSites = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS)) as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if ($info->isFile() === false || str_ends_with($rel, '.php') === false || $rel === '_core' . $sep . 'Captcha.php') {
        continue;
    }
    if (preg_match_all('/Captcha::verify\(\s*\$[A-Za-z_]+\s*(?:,\s*([^)]+))?\)/', (string) file_get_contents((string) $path), $calls, PREG_SET_ORDER) > 0) {
        foreach ($calls as $call) {
            $callSites[str_replace($sep, '/', $rel)] = trim($call[1] ?? '');
        }
    }
}
ksort($callSites);
$letThrough = array_keys(array_filter($callSites, static fn (string $form): bool => str_starts_with($form, 'Captcha::FOR_') === true));
ok(
    'All ' . count($callSites) . ' places that ask the bot check say which form they are; only sign-in and the two password-reset pages are the kind that is let through',
    count($callSites) === 13 && in_array('', $callSites, true) === false
        && $letThrough === ['_apps/auth/forgot-password/save.php', '_apps/auth/login/index.php', '_apps/auth/reset-password/save.php']
        && $callSites['_apps/auth/login/index.php'] === 'Captcha::FOR_SIGN_IN' && $callSites['_apps/auth/forgot-password/save.php'] === 'Captcha::FOR_PASSWORD_RESET'
        && $callSites['_apps/auth/reset-password/save.php'] === 'Captcha::FOR_PASSWORD_RESET',
    json_encode($callSites)
);
note('the ' . count($callSites) . ' places: ' . implode(', ', array_map(static fn (string $file, string $form): string => $file . ' (' . $form . ')', array_keys($callSites), $callSites)));

$person   = ['cf-turnstile-response' => 'a-person'];
$siteKeyJ = 'site-key-' . bin2hex(random_bytes(6));
/** Store the first provider's two keys, each already in the form given. */
$botKeys = static function (string $site, string $secretKey): void {
    store('auth.turnstile.siteKey', $site, 1);
    store('auth.turnstile.secretKey', $secretKey, 1);
};
/** Send the public prayer-request form from a browser that has never been here. */
$publicForm = static function (array $more, string $ip): array {
    $jar     = newJar();
    $page    = req($jar, 'GET', '/prayer-requests/anonymous', null, 0, $ip);
    $subject = REQUEST_SUBJECT . ' ' . bin2hex(random_bytes(4));
    $sent    = req($jar, 'POST', '/prayer-requests/anonymous/save', array_merge(
        ['csrf_token' => token($page['body']), 'name' => 'Selftest', 'email' => '', 'subject' => $subject, 'body' => 'Made by a test. Please ignore.'],
        $more
    ), 0, $ip);

    return ['page' => $page['body'], 'location' => $sent['location'], 'stored' => (int) one('SELECT COUNT(*) AS n FROM tblPrayerRequests WHERE subject = ?', 's', [$subject])['n']];
};
/** What the monitor's address says about stored secrets: [state, detail, overall]. */
$healthSays = static function () use ($S): array {
    $json = (array) json_decode(req('', 'GET', '/cron/health?token=' . $S['job'])['body'], true);

    return [(string) ($json['probes']['Stored secrets']['state'] ?? '(none)'), (string) ($json['probes']['Stored secrets']['detail'] ?? ''), (string) ($json['overall'] ?? '')];
};
$errorRows = static fn (): array => q("SELECT errorSeverity, errorTitle, errorDetail FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");

// --- The ordinary case first: keys the portal CAN read. Nothing about it may have changed.
// (Parts A and F stored secrets that cannot be read, on purpose, and the
// portal wrote that down as it should. That record is cleared here so that
// this part counts only its own.)
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
$botKeys(seal($siteKeyJ), seal($S['bot']));
mailSince();
$plainIn   = trySignIn('member', $password, ip(190));
$personIn  = trySignIn('member', $password, ip(190), $person);
ok(
    'Keys readable, sign-in: refused without the bot check\'s answer, let in with it, and the page shows the widget',
    $plainIn['in'] === false && str_contains($plainIn['said'], 'Captcha verification failed') === true && $personIn['in'] === true
        && str_contains($plainIn['form'], 'data-sitekey="' . $siteKeyJ . '"') === true,
    'without: ' . ($plainIn['in'] === true ? 'let in' : $plainIn['said']) . '; with: ' . ($personIn['in'] === true ? 'let in' : $personIn['said'])
);
$checksAsked = botChecks(mailSince());
ok('…and the provider is sent the secret key exactly as it was saved', count($checksAsked) === 1 && ($checksAsked[0]['form']['secret'] ?? null) === $S['bot'], count($checksAsked) . ' request(s)');
$plainAsk  = forgot(ip(191));
$personAsk = forgot(ip(192), $person);
ok('Keys readable, password reset: nothing is sent without the answer, and a link is sent with it', messageSent($plainAsk['mail']) === false && $personAsk['link'] !== '' && str_contains($plainAsk['form'], 'data-sitekey="' . $siteKeyJ . '"') === true);
$plainForm  = $publicForm([], ip(193));
$personForm = $publicForm($person, ip(193));
ok(
    'Keys readable, a public form: refused without the answer, stored with it',
    $plainForm['stored'] === 0 && str_contains($plainForm['location'], 'err=') === true && $personForm['stored'] === 1 && str_contains($personForm['location'], 'submitted=1') === true,
    $plainForm['location'] . ' | ' . $personForm['location']
);
$quiet = $healthSays();
ok(
    'Keys readable: no notice anywhere, and the health report says the stored secrets are readable',
    str_contains(req($global, 'GET', '/admin/captcha')['body'], 'bot-check-') === false && str_contains(settingsPage($global)['body'], 'settings-bot-check-unreadable') === false
        && $quiet[0] === 'ok' && $errorRows() === [],
    'health: ' . $quiet[0] . ' ' . $quiet[1] . '; error rows: ' . count($errorRows())
);

// --- Now the same two keys, encrypted with a key file this installation does not have.
$scrambledSite = sealWith($otherKey, $siteKeyJ);
$botKeys($scrambledSite, sealWith($otherKey, $S['bot']));
mailSince();
$brokenIn = trySignIn('member', $password, ip(200));
ok(
    'Keys unreadable, sign-in: let in WITHOUT the bot check; the page shows no widget and no notice to the public',
    $brokenIn['in'] === true && str_contains($brokenIn['form'], 'cf-turnstile') === false && str_contains($brokenIn['form'], 'data-bot-check') === false
        && str_contains($brokenIn['form'], substr($scrambledSite, 0, 24)) === false,
    $brokenIn['said']
);
$wrong = 0;
for ($i = 0; $i < 5; $i++) {
    $wrong += trySignIn('member', 'not-the-password-' . $i, ip(201))['in'] === false ? 1 : 0;
}
$sixth     = trySignIn('member', $password, ip(201));
$elsewhere = trySignIn('member', $password, ip(202));
ok(
    'Keys unreadable, sign-in is STILL limited: after five wrong passwords from one address the right one is refused there ("Too many failed attempts"), and works from another address',
    $wrong === 5 && $sixth['in'] === false && str_contains($sixth['said'], 'Too many failed attempts') === true && $elsewhere['in'] === true,
    'sixth try: ' . ($sixth['in'] === true ? 'LET IN' : $sixth['said']) . '; elsewhere: ' . ($elsewhere['in'] === true ? 'let in' : $elsewhere['said'])
);
$brokenAsk = forgot(ip(210));
$newWord   = bin2hex(random_bytes(10)) . 'Bb2?';
$resetJar  = newJar();
$resetPage = req($resetJar, 'GET', '/reset-password?token=' . $brokenAsk['link'], null, 0, ip(210));
$resetSent = req($resetJar, 'POST', '/reset-password/save', ['csrf_token' => token($resetPage['body']), 'token' => $brokenAsk['link'], 'password' => $newWord, 'password_confirm' => $newWord], 0, ip(210));
ok(
    'Keys unreadable, password reset: the link is sent without the bot check, opens, and the new password signs in',
    $brokenAsk['link'] !== '' && str_contains($resetPage['body'], 'name="password_confirm"') === true && str_contains($resetPage['body'], 'data-bot-check') === false
        && $resetSent['status'] === 302 && str_contains($resetSent['location'], 'login') === true && trySignIn('asker', $newWord, ip(211))['in'] === true,
    'link ' . ($brokenAsk['link'] === '' ? 'NOT sent' : 'sent') . '; save answered ' . $resetSent['status'] . ' ' . $resetSent['location']
);
$moreSent = 0;
for ($i = 0; $i < 5; $i++) {
    $moreSent += messageSent(forgot(ip(210))['mail']) === true ? 1 : 0;
}
ok('Keys unreadable, password reset is STILL limited: of six requests from one address, five are sent and the sixth is not', $moreSent === 4, (1 + $moreSent) . ' of 6 were sent');
$brokenForm = $publicForm($person, ip(193));
ok(
    'Keys unreadable, a public form: REFUSED even with an answer, nothing stored, the provider is not asked, and the page says plainly that the form cannot be sent',
    $brokenForm['stored'] === 0 && str_contains($brokenForm['location'], 'err=') === true && str_contains($brokenForm['page'], 'data-bot-check="unreadable"') === true
        && str_contains($brokenForm['page'], 'This form cannot be sent at the moment.') === true && str_contains($brokenForm['page'], 'cf-turnstile') === false
        && botChecks(mailSince()) === [],
    $brokenForm['location']
);
$cardJar  = newJar();
$cardPage = req($cardJar, 'GET', '/decision-card', null, 0, ip(194));
$cardSent = req($cardJar, 'POST', '/decision-card/save', ['csrf_token' => token($cardPage['body']), 'fullName' => 'Selftest Card', 'decision' => 'prayer'], 0, ip(194));
ok('…and a form that first asks "is a bot check set up?" is refused too (the decision card)', $cardSent['status'] === 400 && str_contains($cardSent['body'], 'Captcha failed.') === true, 'HTTP ' . $cardSent['status']);

$captchaTold      = req($global, 'GET', '/admin/captcha')['body'];
$captchaToldLocal = req($local, 'GET', '/admin/captcha')['body'];
preg_match('#<div class="alert alert-danger" role="alert" id="bot-check-unreadable">(.*?)</div>#s', $captchaTold, $toldNotice);
ok(
    'Administrators are told on Admin, Captcha: sign-in is running WITHOUT the bot check, which keys cannot be read, and what to do; the scramble is not shown',
    isset($toldNotice[1]) === true && str_contains($toldNotice[1], 'sign-in and password reset are running WITHOUT the bot check') === true
        && str_contains($toldNotice[1], 'auth.turnstile.siteKey') === true && str_contains($toldNotice[1], 'auth.turnstile.secretKey') === true
        && str_contains($toldNotice[1], 'type the provider&#039;s two keys in again') === true && str_contains($captchaTold, 'Cannot be read') === true
        && str_contains($captchaTold, substr($scrambledSite, 0, 24)) === false && preg_match('/name="turnstile_site"\s+value=""/', $captchaTold) === 1
        && str_contains($captchaToldLocal, 'id="bot-check-unreadable"') === true && str_contains($captchaToldLocal, 'Only a global administrator can put this right.') === true
);
$loud       = $healthSays();
$healthPage = req($global, 'GET', '/admin/maintenance/health')['body'];
ok(
    '…on the health page and at the monitor\'s address (red), and on the Settings page',
    $loud[0] === 'crit' && $loud[2] === 'crit' && str_contains($loud[1], 'running WITHOUT the bot check') === true && str_contains($loud[1], 'auth.turnstile.secretKey') === true
        && str_contains($healthPage, 'Stored secrets') === true && str_contains($healthPage, 'running WITHOUT the bot check') === true
        && str_contains(settingsPage($global)['body'], 'id="settings-bot-check-unreadable"') === true,
    'monitor: ' . $loud[0] . ' / overall ' . $loud[2]
);
$told = $errorRows();
ok(
    '…and in the error log: ONE record however many pages were opened, marked Critical so the alert e-mail goes out, naming the keys',
    count($told) === 1 && $told[0]['errorSeverity'] === 'Critical' && str_contains((string) $told[0]['errorTitle'], 'sign-in is running without its bot check') === true
        && str_contains((string) $told[0]['errorDetail'], 'auth.turnstile.secretKey') === true && str_contains((string) $told[0]['errorDetail'], 'running WITHOUT the bot check') === true,
    count($told) . ' record(s)'
);
// While the portal is closed for an upgrade the monitor's address still answers, and nothing may be written.
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
store('portal.maintenance.active', '1', 0);
$closed = $healthSays();
$writtenWhileClosed = count($errorRows());
store('portal.maintenance.active', '0', 0);
req($global, 'GET', '/admin/captcha');
ok('While the portal is closed for an upgrade the monitor is still told, and no record is written; one is written once it is open again', $closed[0] === 'crit' && $writtenWhileClosed === 0 && count($errorRows()) === 1, $writtenWhileClosed . ' written while closed, ' . count($errorRows()) . ' after');

// --- Putting it right the way the notice says: type the two keys in again on the page.
$captchaPost($global, ['turnstile_site' => $siteKeyJ, 'turnstile_secret' => $S['bot']]);
$mendedPlain  = trySignIn('member', $password, ip(205));
$mendedPerson = trySignIn('member', $password, ip(205), $person);
ok(
    'Typing the provider\'s two keys in again at Admin, Captcha puts it right: they are stored with this key file, the notice goes, and sign-in is checked again',
    unseal((string) row('auth.turnstile.secretKey')['settingValue']) === $S['bot'] && unseal((string) row('auth.turnstile.siteKey')['settingValue']) === $siteKeyJ
        && str_contains(req($global, 'GET', '/admin/captcha')['body'], 'bot-check-unreadable') === false && $mendedPlain['in'] === false && $mendedPerson['in'] === true,
    'without an answer: ' . ($mendedPlain['in'] === true ? 'LET IN' : 'refused') . '; with: ' . ($mendedPerson['in'] === true ? 'let in' : 'refused')
);

// --- A key that cannot be read, left under a provider that is NOT the one in use.
$botKeys(seal($siteKeyJ), seal($S['bot']));
store('auth.recaptcha.siteKey', seal('leftover-site-key'), 1);
store('auth.recaptcha.secretKey', sealWith($otherKey, 'leftover-secret-key'), 1);
$leftPlain  = trySignIn('member', $password, ip(203));
$leftPerson = trySignIn('member', $password, ip(203), $person);
$leftPage   = req($global, 'GET', '/admin/captcha')['body'];
ok(
    'An unreadable key under a provider further down the list changes nothing: the first provider still checks sign-in, and Admin, Captcha names the leftover',
    $leftPlain['in'] === false && $leftPerson['in'] === true && str_contains($leftPage, 'id="bot-check-leftover"') === true
        && str_contains($leftPage, 'id="bot-check-unreadable"') === false && str_contains($leftPage, 'auth.recaptcha.secretKey') === true,
    'without an answer: ' . ($leftPlain['in'] === true ? 'LET IN' : 'refused') . '; with: ' . ($leftPerson['in'] === true ? 'let in' : 'refused')
);
foreach (['auth.turnstile.siteKey', 'auth.turnstile.secretKey', 'auth.recaptcha.siteKey', 'auth.recaptcha.secretKey'] as $name) {
    store($name, '', 1);
}
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
ok('With no bot check set up at all, sign-in and the public form work as they always did', trySignIn('member', $password, ip(204))['in'] === true && $publicForm([], ip(195))['stored'] === 1);

// =============================================================================
head('K. Upgrade encrypts the secrets older code stored as readable text, and cannot lose one');
// =============================================================================
// A database as an older release left it: migration 214 not yet run, and
// stored secrets in every state there is.
$hexMarked = bin2hex(random_bytes(32)); // 64 letters and digits: readable, but it LOOKS encrypted
$elsewhere = sealWith($otherKey, 'encrypted-with-another-key-file');
$once      = seal($S['up5']);
$twice     = seal(seal($S['up6']));
q('DELETE FROM tblMigrations WHERE filename = ?', 's', [MIGRATION_214]);
store('auth.ms365.tenantOnly', 'true', 1);
store('sms.twilio.token', $S['up1'], 0);       // readable, mark cleared: what #597 left
store('giving.cron_token', $S['up2'], 0);      // the same, but it looks encrypted
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'auth.google.clientSecret', ?, '', 0)", 's', [$S['up3']]);
store('payments.stripe.secret', $S['up4'], 1); // readable, marked
store('zoom.webhookSecret', $hexMarked, 1);    // readable, marked, looks encrypted, and never seen unmarked
store('w3w.apiKey', $once, 1);                 // encrypted properly already
store('cfstream.apiToken', $twice, 1);         // encrypted twice over already
store('hymns.remote.apiKey', $elsewhere, 1);   // encrypted with a key file this installation does not have
store('geo.google.apiKey', $S['up7'], 1);      // readable, marked; the database will refuse to rewrite it
store('monitoring.sentryDsn', '', 1);          // a secret with nothing stored
store('site.name', 'Selftest Portal', 0);      // not a secret at all
// The one row the database refuses to change, so that "one row failed" is real and not imagined.
$db->query('CREATE TRIGGER ' . REFUSING_TRIGGER . " BEFORE UPDATE ON tblSettings FOR EACH ROW BEGIN "
    . "IF OLD.settingKey = 'geo.google.apiKey' AND NEW.settingValue <> '' AND NOT (NEW.settingValue <=> OLD.settingValue) THEN "
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the self-test'; END IF; END");
$ownCopy = static fn (): array => (array) one("SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = 'auth.google.clientSecret' AND siteID = 1");
$podcastNow = static fn (): ?array => one("SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = 'recordings.podcast_token' AND siteID = 1");
$podcastBeforeUpgrade = $podcastNow();

$first = upgrade($global);
ok(
    'The Upgrade page runs migration 214 and then answers with a "Stored secrets" card',
    $first['status'] === 200 && $first['card'] !== '' && str_contains($first['body'], MIGRATION_214) === true
        && (int) one('SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = ?', 's', [MIGRATION_214])['n'] === 1 && row('auth.ms365.tenantOnly')['isSensitive'] === 0,
    'HTTP ' . $first['status'] . ($first['card'] === '' ? '; no card. The page said: ' . said($first['body']) : '')
);
ok(
    'It says, by name, that four secrets stored as readable text are now encrypted: two the old page left unmarked, an organisation\'s own copy, and one that was marked',
    ($first['lists']['encrypted'] ?? null) === ['auth.google.clientSecret (organisation 1)', 'giving.cron_token', 'payments.stripe.secret', 'sms.twilio.token'],
    json_encode($first['lists'])
);
ok(
    'Each of the four is now marked and encrypted ONCE: this run\'s key opens the stored text, in one step, to exactly the secret it was',
    unseal((string) row('sms.twilio.token')['settingValue']) === $S['up1'] && row('sms.twilio.token')['isSensitive'] === 1
        && unseal((string) row('giving.cron_token')['settingValue']) === $S['up2'] && row('giving.cron_token')['isSensitive'] === 1
        && unseal((string) $ownCopy()['settingValue']) === $S['up3'] && $ownCopy()['isSensitive'] === 1
        && unseal((string) row('payments.stripe.secret')['settingValue']) === $S['up4']
);
ok(
    'A value that already decrypts is not touched: the one encrypted once, and the one encrypted twice, are byte for byte as they were',
    row('w3w.apiKey')['settingValue'] === $once && row('cfstream.apiToken')['settingValue'] === $twice
);
ok(
    'What looks encrypted and does not open is left exactly as it was and named: text from another key file, and a readable secret that only looks encrypted and was never seen unmarked',
    row('hymns.remote.apiKey')['settingValue'] === $elsewhere && row('zoom.webhookSecret')['settingValue'] === $hexMarked
        && ($first['lists']['cannot-be-read'] ?? null) === ['hymns.remote.apiKey', 'zoom.webhookSecret'],
    json_encode($first['lists']['cannot-be-read'] ?? null)
);
ok(
    'The row the database refused to rewrite is left exactly as it was and named, and the run went on to the rows after it',
    row('geo.google.apiKey')['settingValue'] === $S['up7'] && ($first['lists']['left-readable'] ?? null) === ['geo.google.apiKey']
        && in_array('sms.twilio.token', $first['lists']['encrypted'] ?? [], true) === true,
    json_encode($first['lists']['left-readable'] ?? null)
);
ok(
    'Nothing else is touched: an empty secret, an ordinary setting, and an organisation\'s podcast-feed token',
    row('monitoring.sentryDsn')['settingValue'] === '' && row('site.name')['settingValue'] === 'Selftest Portal' && row('site.name')['isSensitive'] === 0
        && $podcastNow() === $podcastBeforeUpgrade && $podcastBeforeUpgrade !== null && $podcastBeforeUpgrade['isSensitive'] === 0
);
$readBack = startUp(['sms.twilio.token', 'giving.cron_token', 'auth.google.clientSecret', 'payments.stripe.secret', 'w3w.apiKey', 'cfstream.apiToken', 'geo.google.apiKey', 'zoom.webhookSecret', 'hymns.remote.apiKey']);
$expectRead = ['sms.twilio.token' => $S['up1'], 'giving.cron_token' => $S['up2'], 'auth.google.clientSecret' => $S['up3'], 'payments.stripe.secret' => $S['up4'],
    'w3w.apiKey' => $S['up5'], 'cfstream.apiToken' => $S['up6'], 'geo.google.apiKey' => $S['up7'], 'zoom.webhookSecret' => $hexMarked, 'hymns.remote.apiKey' => $elsewhere];
$misread = [];
foreach ($expectRead as $name => $value) {
    if (($readBack['values'][$name]['sha'] ?? '') !== hash('sha256', $value)) {
        $misread[] = $name;
    }
}
ok('Afterwards the portal reads all nine back as the same secrets as before (checked by fingerprint at start-up)', ($readBack['error'] ?? 'x') === '' && $misread === [], implode(', ', $misread) . ($readBack['error'] ?? ''));
ok(
    'The Upgrade page gives names and never a value',
    holds($first['body'], $markers) === [] && str_contains($first['body'], $hexMarked) === false && str_contains($first['body'], substr($elsewhere, 0, 24)) === false
        && str_contains($first['card'], 'now stored encrypted') === true,
    implode(', ', holds($first['body'], $markers))
);
$logged = q("SELECT activityDescription FROM tblActivityLogs WHERE userID = ? AND activityType = 'SecretSettingsRepaired'", 'i', [PEOPLE['global']]);
ok('…and what it did is in the activity log, by name', count($logged) === 1 && str_contains((string) $logged[0]['activityDescription'], '4 encrypted now') === true && str_contains((string) $logged[0]['activityDescription'], 'giving.cron_token') === true, count($logged) . ' record(s)');

// The same Upgrade, pressed again.
$afterFirst = markedRows();
$second     = upgrade($global);
ok(
    'Pressed again: nothing is encrypted a second time. Every marked row is byte for byte as the first run left it, and the card reports none encrypted',
    $second['status'] === 200 && markedRows() === $afterFirst && isset($second['lists']['encrypted']) === false
        && ($second['lists']['cannot-be-read'] ?? null) === ['hymns.remote.apiKey', 'zoom.webhookSecret'] && ($second['lists']['left-readable'] ?? null) === ['geo.google.apiKey'],
    json_encode($second['lists'])
);

// The key file goes missing.
store('translation.deepl.apiKey', $S['up8'], 1);
$beforeNoKey = markedRows();
rename($keysDir . $sep . 'enc.key', $keysDir . $sep . 'enc.key.away');
$noKeyRun = upgrade($global);
rename($keysDir . $sep . 'enc.key.away', $keysDir . $sep . 'enc.key');
ok(
    'With the key file MISSING, Upgrade changes no stored secret at all, says so, and names what is still readable text',
    $noKeyRun['status'] === 200 && markedRows() === $beforeNoKey && isset($noKeyRun['lists']['key-file-missing']) === true && isset($noKeyRun['lists']['encrypted']) === false
        && in_array('translation.deepl.apiKey', $noKeyRun['lists']['left-readable'] ?? [], true) === true && holds($noKeyRun['body'], $markers) === [],
    json_encode($noKeyRun['lists'])
);
$keyBack = upgrade($global);
ok('With the key file back, the next Upgrade encrypts it', ($keyBack['lists']['encrypted'] ?? null) === ['translation.deepl.apiKey'] && unseal((string) row('translation.deepl.apiKey')['settingValue']) === $S['up8'], json_encode($keyBack['lists']));

// Somebody saves a setting while the upgrade is in the middle of its work.
// This test holds that row's lock, so the upgrade has to wait at exactly that
// row; the new value is then saved, and the upgrade is let go.
store('translation.google.apiKey', $S['up9'], 1);
$raceId = (int) row('translation.google.apiKey')['settingID'];
$other  = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
$other->set_charset('utf8mb4');
$other->begin_transaction();
$other->query('SELECT settingValue FROM tblSettings WHERE settingID = ' . $raceId . ' FOR UPDATE')->free();
$raceToken = token(settingsPage($global)['body']);
$raceCall  = curl_init('http://127.0.0.1:' . $port . '/admin/upgrade');
curl_setopt_array($raceCall, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
    CURLOPT_HTTPHEADER => ['Expect:', 'User-Agent: ' . AGENT, 'X-Forwarded-For: ' . ip(1)], CURLOPT_COOKIEJAR => $global, CURLOPT_COOKIEFILE => $global,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $raceToken]),
]);
$raceMulti = curl_multi_init();
curl_multi_add_handle($raceMulti, $raceCall);
$sawWaiting = false;
$until      = microtime(true) + 90;
do {
    curl_multi_exec($raceMulti, $raceRunning);
    $sawWaiting = (int) one('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits')['n'] > 0;
    if ($sawWaiting === false) {
        usleep(100000);
    }
} while ($raceRunning > 0 && $sawWaiting === false && microtime(true) < $until);
// The new value differs from the old one ONLY in capital letters. The
// database's own comparison calls those two the same text, so a repair that
// compared the way the column does would think nothing had changed and
// overwrite it. Any other change is caught the same way, and more easily.
$newValue = strtoupper($S['up9']);
$change   = $other->prepare('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?');
$change->bind_param('si', $newValue, $raceId);
$change->execute();
$change->close();
$other->commit();
$other->close();
do {
    curl_multi_exec($raceMulti, $raceRunning);
    if ($raceRunning > 0) {
        curl_multi_select($raceMulti, 1.0);
    }
} while ($raceRunning > 0);
$raceRaw  = (string) curl_multi_getcontent($raceCall);
$raceHead = (int) curl_getinfo($raceCall, CURLINFO_HEADER_SIZE);
curl_multi_remove_handle($raceMulti, $raceCall);
curl_multi_close($raceMulti);
$race = upgradeAnswer(['status' => (int) curl_getinfo($raceCall, CURLINFO_HTTP_CODE), 'body' => substr($raceRaw, $raceHead)]);
ok(
    'A secret somebody changes while the upgrade is running is NOT overwritten, even when only its capital letters changed: the upgrade waited at that row, the row holds the new value, and the page names it',
    $sawWaiting === true && $race['status'] === 200 && $newValue !== $S['up9'] && row('translation.google.apiKey')['settingValue'] === $newValue
        && ($race['lists']['changed-meanwhile'] ?? null) === ['translation.google.apiKey'] && stripos($race['body'], marker($S['up9'])) === false,
    'waited: ' . ($sawWaiting === true ? 'yes' : 'NO') . '; HTTP ' . $race['status'] . '; ' . json_encode($race['lists'])
);

// Put part K's rows away again, so that nothing readable is left for part I to find.
$db->query('DROP TRIGGER IF EXISTS ' . REFUSING_TRIGGER);
foreach (['sms.twilio.token', 'giving.cron_token', 'payments.stripe.secret', 'zoom.webhookSecret', 'w3w.apiKey', 'cfstream.apiToken', 'hymns.remote.apiKey',
    'geo.google.apiKey', 'translation.deepl.apiKey', 'translation.google.apiKey'] as $name) {
    store($name, '', 1);
}
q("DELETE FROM tblSettings WHERE settingKey = 'auth.google.clientSecret' AND siteID = 1");
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
ok('Afterwards the portal is open again, not left closed for the upgrade', (row('portal.maintenance.active')['settingValue'] ?? '0') === '0' && settingsPage($member)['status'] === 403 && req($member, 'GET', '/')['status'] === 200);

// =============================================================================
head('L. What the independent check of 13 October 2026 found, each one run');
// =============================================================================
// Part J's closures are used again here: $botKeys (store the first provider's
// two keys), $publicForm (send the public prayer request), $healthSays (the
// monitor's address), $captchaPost (Admin, Captcha's "Save Provider Keys").
$captchaSaid = static fn (string $jar): array => [$page = req($jar, 'GET', '/admin/captcha')['body'], said($page)];
$logLines    = 0;
/** The lines the portal has written to PHP's own log since the last call. */
$logSince = static function () use ($child, $sep, &$logLines): array {
    $all      = is_file($child . $sep . 'php-errors.log') === true ? (array) file($child . $sep . 'php-errors.log', FILE_IGNORE_NEW_LINES) : [];
    $new      = array_slice($all, $logLines);
    $logLines = count($all);

    return $new;
};

// --- 1. Admin, Captcha, "Save" pressed while the stored keys cannot be read.
// The page draws BOTH boxes of that provider empty. "Both boxes empty" is also
// how a provider is taken out of use, so one press used to empty both keys:
// the bot check went off everywhere and every warning stopped.
$scrambledSite   = sealWith($otherKey, $siteKeyJ);
$scrambledSecret = sealWith($otherKey, $S['bot']);
$botKeys($scrambledSite, $scrambledSecret);
[$drawnPage] = $captchaSaid($global);
$drawnSave   = $captchaPost($global, []); // every box empty: exactly what the page drew
[$afterDrawn, $drawnSaid] = $captchaSaid($global);
ok(
    'Keys unreadable, "Save Provider Keys" pressed with the form AS DRAWN (both boxes empty): neither stored key is changed, byte for byte',
    $drawnSave['status'] === 302 && preg_match('/name="turnstile_site"\s+value=""/', $drawnPage) === 1 && preg_match('/name="turnstile_secret"\s+value=""/', $drawnPage) === 1
        && row('auth.turnstile.siteKey')['settingValue'] === $scrambledSite && row('auth.turnstile.secretKey')['settingValue'] === $scrambledSecret
        && row('auth.turnstile.siteKey')['isSensitive'] === 1 && row('auth.turnstile.secretKey')['isSensitive'] === 1,
    'HTTP ' . $drawnSave['status'] . '; site key ' . (row('auth.turnstile.siteKey')['settingValue'] === $scrambledSite ? 'kept' : 'CHANGED') . ', secret key '
        . (row('auth.turnstile.secretKey')['settingValue'] === $scrambledSecret ? 'kept' : 'CHANGED')
);
ok(
    '…the page says so in words, as a warning and not as "saved"; the red notice is still there; and the sentence about empty boxes names the exception',
    str_starts_with($drawnSaid, 'warning: Saved, except for this. Cloudflare Turnstile: nothing was changed, because one of its stored keys cannot be read and both of its boxes were empty.') === true
        && str_contains($drawnSaid, 'An empty box never removes a key the portal cannot read.') === true
        && str_contains($afterDrawn, 'id="bot-check-unreadable"') === true && str_contains($afterDrawn, 'id="bot-check-empty-boxes"') === true
        && str_contains($drawnPage, 'id="bot-check-empty-boxes"') === true,
    $drawnSaid
);
$stillRefused = $publicForm($person, ip(196));
$stillLoud    = $healthSays();
ok(
    '…and the bot check has NOT gone off: the public form is still refused and says why, and the health report is still red',
    $stillRefused['stored'] === 0 && str_contains($stillRefused['page'], 'data-bot-check="unreadable"') === true && $stillLoud[0] === 'crit'
        && str_contains($stillLoud[1], 'running WITHOUT the bot check') === true,
    'stored: ' . $stillRefused['stored'] . '; health: ' . $stillLoud[0]
);
// One key typed, the other box left empty as drawn.
$captchaPost($global, ['turnstile_secret' => $S['bot']]);
[$oneTypedPage, $oneTypedSaid] = $captchaSaid($global);
ok(
    'Only the secret key typed in that state: it replaces the unreadable one, the unreadable site key is left as it is (not emptied), and the provider still counts as "cannot be read", so public forms stay refused',
    unseal((string) row('auth.turnstile.secretKey')['settingValue']) === $S['bot'] && row('auth.turnstile.siteKey')['settingValue'] === $scrambledSite
        && str_contains($oneTypedSaid, 'Cloudflare Turnstile: its stored site key cannot be read and its box was empty, so it was left as it is.') === true
        && str_contains($oneTypedPage, 'id="bot-check-unreadable"') === true && $publicForm($person, ip(197))['stored'] === 0,
    $oneTypedSaid
);

// The same press with the key file simply MISSING, which makes every stored
// secret unreadable. Then the file is put back.
$botKeys(seal($siteKeyJ), seal($S['bot']));
$goodSite   = row('auth.turnstile.siteKey')['settingValue'];
$goodSecret = row('auth.turnstile.secretKey')['settingValue'];
$logSince();
rename($keyFile, $keyFile . '.away');
$missingSave = $captchaPost($global, []);
[, $missingSaid] = $captchaSaid($global);
$missingForm   = $publicForm($person, ip(198));
$missingJob    = req('', 'GET', '/cron/health?token=' . $S['job']);
$missingHealth = req($global, 'GET', '/admin/maintenance/health')['body'];
$missingLines  = array_values(array_filter($logSince(), static fn (string $line): bool => str_contains($line, 'cannot be read with this installation') === true));
rename($keyFile . '.away', $keyFile);
ok(
    'The key file MISSING, and Save pressed with the form as drawn: neither key is changed, the page says so, the public form is still refused, and the health page names the missing key file',
    $missingSave['status'] === 302 && row('auth.turnstile.siteKey')['settingValue'] === $goodSite && row('auth.turnstile.secretKey')['settingValue'] === $goodSecret
        && str_starts_with($missingSaid, 'warning: Saved, except for this. Cloudflare Turnstile: nothing was changed') === true && $missingForm['stored'] === 0
        && str_contains($missingHealth, 'is missing. No stored secret can be read and none can be saved until it is put back from your backup.') === true,
    $missingSaid
);
mailSince();
$backPlain  = trySignIn('local', $password, ip(206));
$backPerson = trySignIn('local', $password, ip(206), $person);
$backAsked  = botChecks(mailSince());
ok(
    'The key file put back afterwards: the same stored keys read again. Sign-in is checked by the bot check again, the provider is sent the secret key as it was saved, and the notice has gone',
    $backPlain['in'] === false && $backPerson['in'] === true && count($backAsked) === 1 && ($backAsked[0]['form']['secret'] ?? null) === $S['bot']
        && str_contains($captchaSaid($global)[0], 'bot-check-unreadable') === false,
    'without an answer: ' . ($backPlain['in'] === true ? 'LET IN' : 'refused') . '; with: ' . ($backPerson['in'] === true ? 'let in' : 'refused') . '; provider asked ' . count($backAsked) . ' time(s)'
);
$longest = $missingLines === [] ? 0 : max(array_map('strlen', $missingLines));
ok(
    'While the key file was missing the web server\'s log got ONE SHORT line per request (the longest is ' . $longest . ' characters): how many secrets, and where to read more, with no setting named',
    count($missingLines) >= 4 && $longest < 260 && str_contains(implode("\n", $missingLines), 'auth.') === false && str_contains(implode("\n", $missingLines), 'cronToken') === false
        && preg_match('/\] \[WebMS-Intra\] \d+ stored secrets cannot be read with this installation\'s key file \(enc\.key\)\. Admin, Maintenance, Health names them and says what to do\.$/', $missingLines[0]) === 1,
    count($missingLines) . ' line(s), the longest ' . $longest . ' characters'
);
ok(
    'A scheduled job called with its own token is REFUSED while the key file is missing (what the runbook says of a lost key file), and let in again once the file is back',
    $missingJob['status'] === 403 && str_contains($missingJob['body'], 'invalid_token') === true && str_contains(req('', 'GET', '/cron/health?token=' . $S['job'])['body'], '"probes"') === true,
    'HTTP ' . $missingJob['status'] . ' while missing'
);

// A key that cannot be read, under a provider that is NOT the one in use. The
// page used to say "…or empty both of its boxes and save".
$leftoverSecret = sealWith($otherKey, 'leftover-secret-key');
store('auth.recaptcha.siteKey', seal('leftover-site-key'), 1);
store('auth.recaptcha.secretKey', $leftoverSecret, 1);
// The first provider's site key is in its box, as the page draws it; the leftover provider's two boxes are emptied.
$captchaPost($global, ['turnstile_site' => $siteKeyJ]);
[$leftoverPage, $leftoverSaid] = $captchaSaid($global);
$leftoverKept = row('auth.recaptcha.secretKey')['settingValue'] === $leftoverSecret && unseal((string) row('auth.recaptcha.siteKey')['settingValue']) === 'leftover-site-key';
$tickOne = edit($global, 'auth.recaptcha.secretKey', '', ['clearValue' => '1']);
$tickTwo = edit($global, 'auth.recaptcha.siteKey', '', ['clearValue' => '1']);
ok(
    'A leftover unreadable key under a provider that is not in use: emptying both of its boxes changes nothing and the page says so; removing its two keys on the Settings page, as the page now tells you to, does remove them; the provider in use is untouched throughout',
    $leftoverKept === true && str_contains($leftoverSaid, 'Google reCAPTCHA: nothing was changed, because one of its stored keys cannot be read and both of its boxes were empty.') === true
        && str_contains($leftoverPage, 'id="bot-check-leftover"') === true && str_contains($leftoverPage, 'tick "Remove the stored value"') === true
        && str_contains($leftoverPage, 'empty both of its boxes and save.</div>') === false
        && row('auth.recaptcha.secretKey')['settingValue'] === '' && row('auth.recaptcha.siteKey')['settingValue'] === ''
        && str_contains($captchaSaid($global)[0], 'bot-check-leftover') === false
        && unseal((string) row('auth.turnstile.secretKey')['settingValue']) === $S['bot'] && unseal((string) row('auth.turnstile.siteKey')['settingValue']) === $siteKeyJ,
    $leftoverSaid . ' | ' . $tickOne['said'] . ' | ' . $tickTwo['said']
);

// --- 3. The two forms that have no place for a bot check: the visitor form and live chat.
// They answered only "Verification failed", which blames the visitor.
store('visitors.enabled', '1', 0); // the app itself is off on a fresh install, and its public form with it
store('visitors.public_capture_enabled', '1', 0);
store('chat.enabled', 'true', 0);
$eventDay = date('Y-m-d', strtotime('+9 days')); // worked out from today, never a fixed date
q(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, status, isPublic, isDeleted) '
    . "VALUES (?, 1, 's597-selftest-chat', 'S597 selftest chat event', 'Made by a test. Please ignore.', ?, ?, 'UTC', 'published', 1, 0)",
    'iss',
    [CHAT_EVENT, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
/** Open the visitor form and send it, from a browser that has never been here. */
$visit = static function (string $tag, string $ip): array {
    $jar  = newJar();
    $name = VISITOR_NAME . ' ' . $tag . ' ' . bin2hex(random_bytes(4));
    $page = req($jar, 'GET', '/visit', null, 0, $ip);
    $sent = req($jar, 'POST', '/visit', ['csrf_token' => token($page['body']), 'fullName' => $name, 'email' => 'visitor.s597@selftest.invalid', 'phone' => '', 'notes' => ''], 0, $ip);

    return ['status' => $page['status'], 'page' => $page['body'], 'sent' => $sent['body'], 'stored' => (int) one('SELECT COUNT(*) AS n FROM tblVisitor WHERE fullName = ?', 's', [$name])['n']];
};
/** A viewer's FIRST chat message on the public event, from a viewer session of its own. */
$chatSend = static function (string $tag): array {
    $viewer = bin2hex(random_bytes(16));
    $name   = CHAT_NAME . ' ' . $tag . ' ' . bin2hex(random_bytes(3));
    q('INSERT INTO tblLivestreamSessions (siteID, eventID, sessionToken) VALUES (1, ?, ?)', 'is', [CHAT_EVENT, $viewer]);
    $sent = req('', 'POST', '/api/livechat/send', ['sessionToken' => $viewer, 'eventID' => (string) CHAT_EVENT, 'displayName' => $name, 'body' => 'Made by a test. Please ignore.']);

    return ['status' => $sent['status'], 'message' => (string) (((array) json_decode($sent['body'], true))['message'] ?? ''),
        'stored' => (int) one('SELECT COUNT(*) AS n FROM tblLiveChatMessages WHERE displayName = ?', 's', [$name])['n']];
};
$closedWords = ' cannot be sent at the moment. The check that keeps automated senders out is not working. Please try again later, or get in touch another way.';
$saysNothingOfKeys = static fn (string $text): bool => stripos($text, 'enc.key') === false && stripos($text, 'cannot be read') === false
    && stripos($text, 'turnstile') === false && stripos($text, 'secret') === false && stripos($text, 'Verification failed') === false;

$botKeys($scrambledSite, $scrambledSecret);
$closedVisit = $visit('closed', ip(220));
ok(
    'Keys unreadable, the visitor form (it has no place for a bot check): opening it says in plain words that it cannot be sent now and to try later or get in touch another way; it draws no form; it says nothing about keys',
    $closedVisit['status'] === 200 && str_contains($closedVisit['page'], 'This form' . $closedWords) === true && str_contains($closedVisit['page'], '<form') === false
        && $saysNothingOfKeys($closedVisit['page']) === true,
    'HTTP ' . $closedVisit['status']
);
ok(
    '…and sending it all the same stores nothing and says the same thing, not "Verification failed"',
    $closedVisit['stored'] === 0 && str_contains($closedVisit['sent'], 'This form' . $closedWords) === true && $saysNothingOfKeys($closedVisit['sent']) === true,
    'stored: ' . $closedVisit['stored']
);
$closedChat = $chatSend('closed');
ok(
    'Keys unreadable, live chat (no place for a bot check either): a first message is refused with the same plain words, as the answer the chat box shows (503), and nothing is stored',
    $closedChat['status'] === 503 && $closedChat['message'] === 'Your message' . $closedWords && $closedChat['stored'] === 0,
    'HTTP ' . $closedChat['status'] . ': ' . $closedChat['message']
);
// With a bot check that WORKS, the visitor form has never been sendable: it draws no challenge. Reported, not judged.
$botKeys(seal($siteKeyJ), seal($S['bot']));
$workingVisit = $visit('working', ip(221));
note('with a WORKING bot check the visitor form ' . ($workingVisit['stored'] === 1 ? 'stores the visitor' : 'stores nothing and answers "'
    . (preg_match('#<div class="flash flash-\w+">(.*?)</div>#s', $workingVisit['sent'], $flashed) === 1 ? html_entity_decode($flashed[1], ENT_QUOTES, 'UTF-8') : '(no message)')
    . '": it has no place for the challenge. That is older than this change and is not changed by it'));
$botKeys('', '');
$openVisit = $visit('open', ip(222));
$openChat  = $chatSend('open');
ok(
    'With no bot check set up, both work as they always did: the visitor form is drawn and stores the visitor, and the chat message is stored',
    str_contains($openVisit['page'], '<form') === true && $openVisit['stored'] === 1 && $openChat['status'] === 200 && $openChat['stored'] === 1,
    'visitor stored: ' . $openVisit['stored'] . '; chat HTTP ' . $openChat['status'] . ' ' . $openChat['message']
);
store('visitors.enabled', '0', 0);
store('visitors.public_capture_enabled', '0', 0);
store('chat.enabled', 'false', 0);

// --- 4. An error on a scheduled job's address is recorded WITHOUT the job's token.
// The portal writes its own "a stored secret cannot be read" record during
// whichever request comes first, and that can be the scheduler's. Error
// reporting to an outside service is switched on here, pointed at this run's
// stand-in: nothing leaves this machine.
$botKeys($scrambledSite, $scrambledSecret);
store('monitoring.enabled', '1', 0);
store('monitoring.sentryDsn', seal('http://s597standin@127.0.0.1:' . $mailPort . '/914'), 1);
$errorService = static fn (array $mail): array => array_values(array_filter($mail, static fn (array $request): bool => $request['path'] === '/api/914/store/'));
$recordedAt   = static fn (): array => array_column(q("SELECT requestURL FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'"), 'requestURL');
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
mailSince();
req('', 'GET', '/cron/health?probe=1&token=' . $S['job'] . '&after=kept');
$tokenRecorded = $recordedAt();
$tokenSent     = $errorService(mailSince());
ok(
    'An error recorded during a scheduled job\'s request ("?token=…"): the error log holds the address with the token\'s value cut out and everything else kept',
    $tokenRecorded === ['/cron/health?probe=1&token=[removed]&after=kept'],
    count($tokenRecorded) . ' record(s)' . (str_contains(implode(' ', $tokenRecorded), $S['job']) === true ? ', and the TOKEN IS IN the stored address' : '')
);
$sentUrl = (string) (((array) json_decode((string) ($tokenSent[0]['raw'] ?? ''), true))['request']['url'] ?? '');
ok(
    '…and the outside error service (this run\'s stand-in) is sent the same address without the token; the token is nowhere in what it was sent',
    count($tokenSent) === 1 && $sentUrl === 'http://127.0.0.1:' . $port . '/cron/health?probe=1&token=[removed]&after=kept' && str_contains((string) $tokenSent[0]['raw'], $S['job']) === false,
    count($tokenSent) . ' report(s) sent' . (str_contains(implode(' ', array_column($tokenSent, 'raw')), $S['job']) === true ? ', and the TOKEN IS IN what was sent' : '')
);
// The other name jobs use, and the spellings a scheduler's address might have.
$otherToken = 'S5jk' . bin2hex(random_bytes(12));
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");
req('', 'GET', '/cron/venue-reminders?key=' . $otherToken);
$keyRecorded = $recordedAt();
$spellings   = [
    '/cron/health?TOKEN=' . $otherToken              => '/cron/health?TOKEN=[removed]',
    '/cron/health?%74oken=' . $otherToken            => '/cron/health?%74oken=[removed]',
    '/cron/health?token[]=' . $otherToken            => '/cron/health?token[]=[removed]',
    '/cron/x?a=1&key=' . $otherToken . '&token=' . $otherToken . '&b=2' => '/cron/x?a=1&key=[removed]&token=[removed]&b=2',
    '/cron/health?token='                            => '/cron/health?token=',
    '/x?monkey=1&tokens=2&mytoken=3&k=4'             => '/x?monkey=1&tokens=2&mytoken=3&k=4',
    '/x/no-query'                                    => '/x/no-query',
];
$spelled = (array) (keyProbe(array_keys($spellings))['addresses'] ?? []);
ok(
    'The same for the other name jobs use ("?key=…"), and for the spellings an address might have: capitals, an encoded name, brackets, two tokens with other parameters kept; names that only look similar are left alone',
    $keyRecorded === ['/cron/venue-reminders?key=[removed]'] && $spelled === $spellings,
    json_encode($keyRecorded) . ' ' . json_encode(array_diff_assoc($spellings, $spelled))
);
store('monitoring.enabled', '0', 0);
store('monitoring.sentryDsn', '', 1);
$botKeys('', '');
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");

// --- 2. An EMPTY key file, and a folder where the key file should be, are "no key".
// An empty file used to count as a key: the SHA-256 of nothing, which anybody
// can work out. Upgrade "encrypted" the readable secrets with it and said it
// had protected them; with the real file back they were a scramble.
store('translation.deepl.apiKey', $S['up8'], 1); // readable text, marked: what Upgrade encrypts
$keptRow = row($KEY)['settingValue'];
file_put_contents($keyFile, '');
$emptyProbe  = keyProbe();
$emptySave   = edit($global, $KEY, $S['nokey']);
$emptyRun    = upgrade($global);
$emptyHealth = req($global, 'GET', '/admin/maintenance/health')['body'];
$emptyLeft   = row('translation.deepl.apiKey')['settingValue'];
unlink($keyFile);
mkdir($keyFile, 0700);
$folderProbe = keyProbe();
$folderSave  = edit($global, $KEY, $S['nokey']);
$folderLeft  = row('translation.deepl.apiKey')['settingValue'];
rmdir($keyFile);
file_put_contents($keyFile, $state['key']);
$noKeyAtAll = static fn (array $probe): bool => ($probe['reached'] ?? false) === true && ($probe['classEncrypts'] ?? true) === false && ($probe['helperEncrypts'] ?? true) === false
    && ($probe['classOpensNothingKey'] ?? true) === false && ($probe['helperOpensNothingKey'] ?? true) === false;
ok(
    'An EMPTY key file is "no key" to the new class and to the old helper functions alike: the portal still starts, neither will encrypt, and neither opens text encrypted with the key an empty file used to give',
    $noKeyAtAll($emptyProbe) === true && str_contains((string) ($emptyProbe['note'] ?? ''), 'is empty, so it is not used as a key') === true,
    json_encode(array_diff_key($emptyProbe, ['addresses' => 1]))
);
ok(
    'Saving a secret then is refused in words ("is missing, is empty or cannot be read") and nothing is stored',
    row($KEY)['settingValue'] === $keptRow && str_starts_with($emptySave['said'], 'danger: Nothing was saved. "' . $KEY . '" is a secret, and the portal could not encrypt it') === true
        && str_contains($emptySave['said'], 'is missing, is empty or cannot be read') === true,
    $emptySave['said']
);
ok(
    'Upgrade with an empty key file writes NOTHING: the readable secret is exactly as it was, the page says no secret was encrypted and why, and the health page names the empty key file',
    $emptyRun['status'] === 200 && $emptyLeft === $S['up8'] && isset($emptyRun['lists']['key-file-missing']) === true && isset($emptyRun['lists']['encrypted']) === false
        && str_contains($emptyRun['card'], 'is missing, is empty or cannot be read') === true && holds($emptyRun['body'], $markers) === []
        && str_contains($emptyHealth, 'is empty, so it is not used as a key') === true,
    json_encode($emptyRun['lists'])
);
ok(
    'A FOLDER where the key file should be is "no key" as well: nothing will encrypt, saving is refused, and the stored secret is untouched',
    $noKeyAtAll($folderProbe) === true && str_contains((string) ($folderProbe['note'] ?? ''), 'is not a file') === true && row($KEY)['settingValue'] === $keptRow
        && str_starts_with($folderSave['said'], 'danger: Nothing was saved.') === true && $folderLeft === $S['up8'],
    json_encode(array_diff_key($folderProbe, ['addresses' => 1])) . ' | ' . $folderSave['said']
);
$realRun = upgrade($global);
ok(
    'With the REAL key file back, Upgrade encrypts that secret with the real key, and it opens to exactly what it was (it used to be left a scramble)',
    ($realRun['lists']['encrypted'] ?? null) === ['translation.deepl.apiKey'] && unseal((string) row('translation.deepl.apiKey')['settingValue']) === $S['up8']
        && (keyProbe()['note'] ?? 'x') === '',
    json_encode($realRun['lists'])
);

// A key file SHORTER than the installer writes. It is a working key (part A),
// so it must go on working; the health report names it, and that is all.
// Every stored secret is set aside first, so that only the key file is in question.
$setAside = q("SELECT settingID, settingValue FROM tblSettings WHERE isSensitive = 1 AND settingValue IS NOT NULL AND settingValue <> ''");
q("UPDATE tblSettings SET settingValue = '' WHERE isSensitive = 1 AND settingValue IS NOT NULL AND settingValue <> ''");
$shortKey = bin2hex(random_bytes(8)); // 16 bytes
file_put_contents($keyFile, $shortKey);
store('maintenance.cronToken', seal($S['job']), 1); // encrypted with the key file that WAS there
$otherKeyJob = req('', 'GET', '/cron/health?token=' . $S['job']);
store('maintenance.cronToken', sealWith($shortKey, $S['job']), 1);
$shortSays = $healthSays();
$shortSave = edit($global, $KEY, $S['page']);
$shortRead = startUp([$KEY]);
$shortRow  = (string) row($KEY)['settingValue'];
file_put_contents($keyFile, $state['key']);
foreach ($setAside as $r) {
    q('UPDATE tblSettings SET settingValue = ? WHERE settingID = ?', 'si', [(string) $r['settingValue'], (int) $r['settingID']]);
}
ok(
    'A key file SHORTER than the installer writes (16 bytes, not 64): the health report names it in amber, says how long it is, and says nothing has been stopped',
    $shortSays[0] === 'warn' && str_contains($shortSays[1], 'is 16 bytes long; the installer writes 64') === true && str_contains($shortSays[1], 'nothing has been stopped') === true,
    $shortSays[0] . ': ' . $shortSays[1]
);
ok(
    '…and nothing HAS been stopped: a secret saved through the page is stored encrypted under that same short key, and start-up reads it back',
    str_starts_with($shortSave['said'], 'success: Setting "' . $KEY . '" updated.') === true && unsealWith($shortKey, $shortRow) === $S['page']
        && ($shortRead['values'][$KEY]['sha'] ?? '') === hash('sha256', $S['page']),
    $shortSave['said'] . ' ' . ($shortRead['error'] ?? '')
);
ok(
    'With a DIFFERENT key file in place, a scheduled job called with its old token is refused (what the runbook says); afterwards every secret that was set aside is back, and the real key file reads them',
    $otherKeyJob['status'] === 403 && unseal((string) row($KEY)['settingValue']) === $S['mail'] && unseal((string) row('maintenance.cronToken')['settingValue']) === $S['job']
        && str_contains(req('', 'GET', '/cron/health?token=' . $S['job'])['body'], '"probes"') === true,
    'HTTP ' . $otherKeyJob['status'] . ' with the other key file'
);
store('translation.deepl.apiKey', '', 1);
q("DELETE FROM tblErrors WHERE errorCode = 'SECRET_SETTING_UNREADABLE'");

// =============================================================================
head('I. Nothing leaves: pages, the debug panel, the database, the logs');
// =============================================================================
// Every secret the portal now holds is stored encrypted, so each one is in
// $SETTINGS as its real value on every request below.
store('payments.stripe.secret', seal($S['own']), 1);
store('push.vapidPrivateKey', seal($S['foreign']), 1);
store('sms.twilio.token', seal($S['left']), 1);
// The debug panel blanks anything whose NAME holds "secret", "token",
// "password" and the like. This one's name holds none of those, so a panel
// that printed the settings through that filter would still print it.
store('cfstream.signingKeyPem', seal($S['plain']), 1);
$held = startUp([$KEY, 'payments.stripe.secret', 'push.vapidPrivateKey', 'sms.twilio.token', 'portal.qr.cuercode.api_key', 'maintenance.cronToken', 'cfstream.signingKeyPem']);
ok(
    'Start-up now holds seven real secrets (checked by fingerprint), so the search below has something to find',
    ($held['values'][$KEY]['sha'] ?? '') === hash('sha256', $S['mail']) && ($held['values']['payments.stripe.secret']['sha'] ?? '') === hash('sha256', $S['own'])
        && ($held['values']['cfstream.signingKeyPem']['sha'] ?? '') === hash('sha256', $S['plain'])
        && ($held['values']['push.vapidPrivateKey']['sha'] ?? '') === hash('sha256', $S['foreign']) && ($held['values']['sms.twilio.token']['sha'] ?? '') === hash('sha256', $S['left'])
        && ($held['values']['portal.qr.cuercode.api_key']['sha'] ?? '') === hash('sha256', $S['qr']) && ($held['values']['maintenance.cronToken']['sha'] ?? '') === hash('sha256', $S['job']),
    (string) ($held['error'] ?? '')
);

// The administration pages, and the three others that show settings. Addresses
// that look like an action are left out: this is a search, not a button-press.
$addresses = array_map(
    static fn (array $r): string => (string) $r['routeKey'],
    q("SELECT routeKey FROM tblRoutes WHERE (routeKey LIKE 'admin%' OR routeKey IN ('settings', 'payments', 'venues/settings')) "
        . "AND routeKey NOT REGEXP '(save|delete|/act\$|export|download|callback|connect|webhook|toggle|dismiss|run|restore|purge|erase|send|/test|generate|approve|reject|revoke|rotate|process|/mark\$|import|upload|impersonate|logout|upgrade)' "
        . 'ORDER BY routeKey')
);
/** @return array{pages:int, panels:int, leaks:list<string>, broken:list<string>} */
$sweep = static function (string $jar, int $onPort, string $query) use ($addresses, $markers): array {
    $tally = ['pages' => 0, 'panels' => 0, 'leaks' => [], 'broken' => []];
    foreach ($addresses as $address) {
        $answer = req($jar, 'GET', '/' . $address . $query, null, $onPort);
        if ($answer['status'] === 200) {
            $tally['pages']++;
        }
        // 0 = no answer, -1 = not this run's server, 500 and up = the page fell over.
        if ($answer['status'] < 200 || $answer['status'] >= 500) {
            $tally['broken'][] = '/' . $address . ' (' . $answer['status'] . ')';
        }
        if (str_contains($answer['body'], 'id="portal-debug-bar"') === true) {
            $tally['panels']++;
        }
        $found = holds($answer['head'] . $answer['body'], $markers);
        if ($found !== []) {
            $tally['leaks'][] = '/' . $address . ' (' . implode(', ', $found) . ')';
        }
    }

    return $tally;
};
$liveGlobal = $sweep($global, 0, '');
ok(
    'The live copy, a global administrator: ' . $liveGlobal['pages'] . ' of ' . count($addresses) . ' administration pages opened, and no secret in any answer',
    $liveGlobal['pages'] >= 60 && $liveGlobal['leaks'] === [],
    $liveGlobal['pages'] . ' pages; ' . implode('; ', array_slice($liveGlobal['leaks'], 0, 5))
);
$devGlobal = $sweep($global, $devPort, '?debug=true');
ok(
    'The development copy with the DEBUG PANEL open, a global administrator: ' . $devGlobal['panels'] . ' pages drew the panel, and no secret in any answer',
    $devGlobal['pages'] >= 60 && $devGlobal['panels'] >= 40 && $devGlobal['leaks'] === [],
    $devGlobal['pages'] . ' pages, ' . $devGlobal['panels'] . ' panels; ' . implode('; ', array_slice($devGlobal['leaks'], 0, 5))
);
$devLocal = $sweep($local, $devPort, '?debug=true');
ok(
    'The same as an administrator of ONE organisation: ' . $devLocal['pages'] . ' pages opened, ' . $devLocal['panels'] . ' drew the panel, and no secret in any answer',
    $devLocal['pages'] >= 30 && $devLocal['panels'] >= 20 && $devLocal['leaks'] === [],
    $devLocal['pages'] . ' pages, ' . $devLocal['panels'] . ' panels; ' . implode('; ', array_slice($devLocal['leaks'], 0, 5))
);
// The pages people are sent to when something goes wrong, on both copies.
$broken = array_values(array_unique(array_merge($liveGlobal['broken'], $devGlobal['broken'], $devLocal['broken'])));
ok('No administration page fell over while the portal held real secrets (none answered 500 or did not answer)', $broken === [], implode('; ', array_slice($broken, 0, 8)));
$wrongPages = '';
foreach ([[0, '/no-such-page-s597'], [$devPort, '/no-such-page-s597?debug=true'], [$devPort, '/error.php?code=500&debug=true'], [0, '/error.php?code=403']] as [$onPort, $path]) {
    $wrongPages .= req($global, 'GET', $path, null, $onPort)['body'] . req($member, 'GET', $path, null, $onPort)['body'];
}
ok('The "not found" and error pages, on both copies, for an administrator and for a member: no secret', strlen($wrongPages) > 2000 && holds($wrongPages, $markers) === []);
$healthJson = req('', 'GET', '/cron/health?token=' . $S['job'])['body'];
ok('The health report the monitor reads says nothing of any other secret', str_contains($healthJson, '"probes"') === true && holds($healthJson, array_diff_key($markers, ['job' => 1])) === []);

settle();
$inDatabase = [];
foreach ($markers as $label => $one) {
    foreach (databaseHolds((string) $one) as $where) {
        $inDatabase[] = $label . ' in ' . $where;
    }
}
ok(
    'Every text column of ' . tablesRead() . ' tables: none of the ' . count($markers) . ' secrets is stored readable anywhere (not in a setting, the activity log, the error log or the mail log)',
    tablesRead() >= 150 && $inDatabase === [],
    implode('; ', array_slice($inDatabase, 0, 6))
);
// A search that cannot find anything proves nothing, so prove it can.
q("UPDATE tblUsers SET fullName = ? WHERE userID = ?", 'si', ['planted ' . $markers['mail'], PEOPLE['member']]);
ok('…and that search does find one planted on purpose', databaseHolds((string) $markers['mail']) === ['tblUsers (1 row)']);
q("UPDATE tblUsers SET fullName = 'Selftest secrets member' WHERE userID = ?", 'i', [PEOPLE['member']]);

$inFiles = [];
$files   = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS)) as $path => $info) {
    $rel = substr((string) $path, strlen($child) + 1);
    // The portal's own code is not a record; the stand-in's notebook holds the secrets by design.
    if ($info->isFile() === false || str_starts_with($rel, 'web' . $sep) === true || $rel === 'standin-requests.jsonl') {
        continue;
    }
    $files++;
    $found = holds((string) file_get_contents((string) $path), $markers);
    if ($found !== []) {
        $inFiles[] = (str_starts_with($rel, 'sessions' . $sep) === true ? 'a session file' : (str_starts_with($rel, 'jar-') === true ? 'a cookie jar' : $rel)) . ' (' . implode(', ', $found) . ')';
    }
}
ok(
    'The PHP error log, the servers\' output, ' . count((array) glob($child . $sep . 'sessions' . $sep . '*')) . ' session files and the cookie jars (' . $files . ' files): no secret, including the one typed while the key file was missing',
    $files >= 10 && is_file($child . $sep . 'server-output.log') === true && $inFiles === [],
    implode('; ', array_slice(array_unique($inFiles), 0, 6))
);
ok('The stand-in\'s own notebook DOES hold the mail secret, so the file search can find one', holds((string) file_get_contents($child . $sep . 'standin-requests.jsonl'), ['mail' => $markers['mail']]) === ['mail']);

// =============================================================================
// 🧹 Clean-up, and the verdict
// =============================================================================
$checkedRows = $state['rows'];
cleanup();
$notRestored = [];
foreach ($checkedRows as $name => $beforeRow) {
    $now = row((string) $name);
    if (($beforeRow === null) !== ($now === null) || ($beforeRow !== null && ($now['settingValue'] !== $beforeRow['settingValue'] || $now['isSensitive'] !== $beforeRow['isSensitive']))) {
        $notRestored[] = (string) $name;
    }
}
ok(
    'Afterwards: every one of the ' . count($checkedRows) . ' settings this run touched is back as it was, value and mark; its accounts and its own settings are gone; the scratch folder is gone',
    $notRestored === [] && (int) one('SELECT COUNT(*) AS n FROM tblUsers WHERE userID BETWEEN 914000 AND 914099')['n'] === 0
        && (int) one('SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey LIKE ?', 's', [str_replace('_', '\\_', OWN_PREFIX) . '%'])['n'] === 0 && is_dir($child) === false,
    'not put back: ' . implode(', ', array_slice($notRestored, 0, 6))
);

echo "\n" . $pass . ' passed, ' . $fail . ' failed, of ' . ($pass + $fail) . ' checks (' . EXPECTED_CHECKS . " expected).\n";
if ($pass + $fail !== EXPECTED_CHECKS) {
    echo 'FAIL — ' . ($pass + $fail) . ' checks ran and ' . EXPECTED_CHECKS . " were expected: a part was skipped or ran twice.\n";
    exit(1);
}
if ($fail > 0) {
    exit(1);
}
echo "REACHED THE END — every check ran and passed.\n";
exit(0);
