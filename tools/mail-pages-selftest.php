<?php
// Path: tools/mail-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The SMTP mail route, on the REAL pages — self-test ✉️🧭 (#592, part 2)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * The portal can send its e-mail through an ordinary mailbox on a mail server
 * (SMTP). That means a mailbox's password is stored, a certificate is checked,
 * and "Forgot password" depends on both. Every one of those can go wrong
 * without any page failing: a password stored in plain text, a certificate
 * that is quietly not checked, a reset link that is never sent while the
 * person is told it was. So this file runs the portal and looks.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file and encryption key, starts PHP's built-in web server on it
 * behind a small router that imitates `.htaccess`, and sends real requests
 * with real cookies. It starts the mail library's own STAND-IN mail servers
 * (`tools/maillib/tests/smtp-standin.php`, unchanged): each is a made-up mail
 * server on 127.0.0.1 with one scripted behaviour, which records what it is
 * sent and sends nothing anywhere. The certificates are made for this run, by
 * the certificate maker lifted as text from `tools/maillib/tests/run.php`.
 *
 * NO FILE OF THE PORTAL IS CHANGED IN THE COPY. The portal's web server is
 * started with PHP's own setting `openssl.cafile` naming this run's made-up
 * certificate authority, which is what "the machine's list of trusted
 * authorities" means to PHP. So the code under test is exactly the code that
 * ships, and a certificate from any other authority is still refused.
 *
 * The parts are numbered as in the plan (P1 to P13) and run in the order the
 * set-up needs, which is not the order of the numbers:
 *
 *   P9   The database: the six settings are seeded, the password's row is
 *        marked as a secret, two dead settings are gone, the send log has its
 *        two new columns; migration 213 turns an older database into the same
 *        thing, keeps a value somebody saved, and is harmless a second time.
 *   P5   An administrator of ONE organisation, and an ordinary member: saving,
 *        choosing the route and sending a test are each refused, nothing is
 *        written, and each refusal is in the activity log. A global
 *        administrator's own request is refused too when it carries no form
 *        token: for the save, for the route chooser and for "Send test".
 *   P4   Saving through the real page: the password is stored encrypted and
 *        marked as a secret; no page shows it afterwards; an empty box keeps
 *        it; the tick box removes it; and what the general Settings page
 *        shows for it.
 *   P7   A line break in the "from" name or the reply-to address, and a server
 *        name, port or kind of encryption that could never be right, are
 *        refused at save and nothing is stored.
 *   P3   Mailer::canSend(): yes when everything is there; no, naming the
 *        piece, for each missing piece, an unreadable password, a bad kind of
 *        encryption, a "from" address the mail library would refuse, and an
 *        hourly limit that is not a number.
 *   P1   "Forgot password" through SMTP: the stand-in receives one message for
 *        that person holding a link that works; the send log says sent by
 *        smtp, with a moment in UTC.
 *   P2   A known address, an unknown one, a mail server that refuses the
 *        sign-in, one that stalls and one with a bad certificate: the person
 *        is shown exactly the same thing each time, and before the mail goes.
 *   P6   "Send test": the four steps and the server's last line when it is
 *        accepted; the right reason in plain words when the sign-in is
 *        refused, the server stalls or cannot be reached; and it tests the
 *        SMTP mailbox even while another route is in use.
 *   P11  "Send test" against five bad certificates names the right fault each
 *        time and sends no sign-in; a certificate that could not be read a
 *        second time, and one only the library's own name rule refuses, still
 *        get a true message; a failure is shown as a standing notice, which a
 *        message stopped before the mail server is tried does not clear, and
 *        which goes when a message is next accepted.
 *   P12  Mailer::send() with SMTP chosen and nothing filled in answers false,
 *        logs "not set up" and throws nothing; an event broadcast ends
 *        normally; lastOutcome() gives the right word for each ending. A
 *        message the server took and never answered for is NOT counted as
 *        sent. After a refused sign-in the later messages of the same request
 *        do not sign in again; after a refused recipient they still go.
 *   P13  A mail server, or a choice of route, named in ONE organisation's own
 *        settings changes nothing; a password row not marked as a secret is
 *        "not set up", whether it holds the password in plain text or a good
 *        encrypted one.
 *   P14  The Integrations page's own three tests ("Send Test Email" among
 *        them, which goes through the SMTP mailbox once that is the route)
 *        are for a global administrator only: refused, logged, and not shown.
 *   P10  The hourly limit: counted in UTC across both clock-change nights, in
 *        Europe/London and America/New_York, with the database connection and
 *        PHP both in the changing zone; and on the pages, the message over the
 *        limit is not sent and is listed as such, whichever route is in use.
 *   P8   The dashboard's "set up e-mail" step is ticked only when the portal
 *        can send; the two help pages say what is true.
 *   Last The password is in nothing the portal showed, logged or stored
 *        unencrypted; nothing went wrong behind the pages; everything is put
 *        back.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every account it makes is numbered 929000 to 929999; every
 *   record it causes carries a 198.51.100.x address or one of those numbers.
 *   They are removed at the START (in case an earlier run was interrupted)
 *   and at the END.
 * - P9 runs migration 213 against COPIES of the three tables it touches,
 *   made for the run and dropped afterwards, never against the real ones. Its
 *   first version changed the shape of the real send log for a moment; a run
 *   that died there left the database without its two new columns, and every
 *   later run then died in the same place.
 * - It changes installation-wide settings for the run and puts each back
 *   exactly, including whether it was marked as stored encrypted. So do not
 *   run it at the same moment as another test on the same database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds this
 *   run's marker. `web/_auth_keys` is never read or copied.
 * - Both servers are started with their commands as lists (no shell), and
 *   each is stopped by its own process number, never by a name. It refuses to
 *   start if anything already answers on its web port. Every answer must
 *   carry this run's header. The stand-in mail servers listen on ports the
 *   system chooses, on 127.0.0.1 only (that is how `smtp-standin.php` is
 *   written), and stop themselves if this file is killed.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real mail server accepts a message, or that one arrives. Nothing
 *   here touches a real mail server; no e-mail leaves this machine.
 * - That PHP on the host trusts the right authorities. Here PHP is TOLD which
 *   authority to trust; a host whose list is missing or out of date is a
 *   different fault, and "Send test" on that host is what shows it.
 * - That "Forgot password" answers before the e-mail is sent on every web
 *   server. PHP's built-in one lets the browser go first; one that holds
 *   PHP's output back until the script ends does not (see the page's notes).
 * - The hourly limit when two messages are sent at the same moment: both can
 *   be let through at the limit's edge. Not run here.
 * - A page at phone width, or how anything looks. Those need a browser.
 * - Apache, a real proxy or https: the router only imitates `.htaccess`, on
 *   plain http on 127.0.0.1.
 * - MariaDB. It has been run on MySQL 8.0.36 and 8.4.11.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/mail-pages-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9580). The database must already
 *         hold `web/_sql/full_schema.sql` and know time zones by name (P10).
 *         Needs PHP's curl, openssl and sodium extensions and `proc_open()`.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/592
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 108;

/** What a child process prints before its findings, so they can be found in whatever else it printed. */
const RESULT_MARK = '@@mail-pages-selftest@@';

// =============================================================================
// 🧒 THE CHILD PROCESS
// =============================================================================
// This same file is run again, as `php mail-pages-selftest.php --child <scratch
// web folder>`, to call Mailer::send() directly: a page cannot show what it
// returned, whether it threw, or what lastOutcome() says afterwards. It runs
// at the TOP LEVEL, not inside a function, because that is where the portal's
// own entry page loads its start-up file from, and the start-up file sets
// $mysqli and $SETTINGS as globals.
if (($argv[1] ?? '') === '--child') {
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/';
    require (string) ($argv[2] ?? '') . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $job = (array) json_decode((string) getenv('SELFTEST_JOB'), true);
    $out = ['sent' => null, 'threw' => '', 'outcome' => '', 'canSend' => \Portal\Core\Mailer::canSend(), 'each' => []];
    // `then` is more lists of recipients, sent one after another IN THIS SAME
    // PROCESS: what a broadcast or a reminder run does in one request. `sent`
    // and `outcome` are the LAST send's; `each` holds every send's, in order.
    foreach (array_merge([$job['to']], (array) ($job['then'] ?? [])) as $recipients) {
        try {
            $out['sent'] = \Portal\Core\Mailer::send($recipients, (string) $job['subject'], (string) $job['body'], (array) ($job['files'] ?? []), (array) ($job['options'] ?? []));
        } catch (Throwable $thrown) {
            $out['sent']  = null;
            $out['threw'] = get_class($thrown) . ': ' . $thrown->getMessage();
        }
        $out['outcome'] = \Portal\Core\Mailer::lastOutcome();
        $out['each'][]  = [$out['sent'], $out['outcome']];
    }
    echo "\n" . RESULT_MARK . json_encode($out) . "\n";
    exit(0);
}

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
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9580 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;

/** ✅ Report one check. `$detail` is shown only on failure. */
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
/** The accounts. */
const PEOPLE = [
    'root'     => 929001, // a global administrator
    'orgadmin' => 929002, // an administrator of ONE organisation, and nothing more
    'member'   => 929011, // an ordinary member, who asks for reset links
    'second'   => 929012, // more members, for the hourly limit and the broadcast
    'third'    => 929013,
    'fourth'   => 929014,
];
/** The made-up event the broadcast of P12 is about. */
const EVENT_ID = 929500;
/** How this test names itself to the portal, so the records it causes can be found and removed. */
const AGENT = 'mail-pages-selftest';
/** The "from" details saved through the page. */
const SENDER       = 'office@mailpages.selftest.invalid';
const SENDER_NAME  = 'Selftest Office';
const REPLY_TO     = 'replies@mailpages.selftest.invalid';
/** The mailbox's user name: not a secret, and looked for to prove the search for the password looks in the right place. */
const MAILBOX_USER = 'selftest-mailbox-user';
/** The mail server's name. The stand-in's certificates are made for this name. */
const MAIL_HOST = 'localhost';

/** A made-up visitor address. 198.51.100.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '198.51.100.' . $last;
}

/** The sign-in name of one of the people. */
function login(string $who): string
{
    return 'mps-' . $who;
}

/** The e-mail address of one of the people. */
function mailOf(string $who): string
{
    return $who . '@mailpages.selftest.invalid';
}

/** The limiter's bucket for one visitor address's requests for a reset link. */
function bucket(string $kind, string $ip): string
{
    return 'pwreset:' . $kind . ':' . hash('sha256', $ip);
}

function removeWorld(): void
{
    q('DELETE FROM tblPasswordResets WHERE userID BETWEEN 929000 AND 929999');
    q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%mailpages.selftest.invalid%'");
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 929000 AND 929999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 929000 AND 929999 OR visitorIP LIKE '198.51.100.%'");
    for ($last = 1; $last <= 254; $last++) {
        q('DELETE FROM tblApiRateLimits WHERE bucket IN (?, ?)', 'ss', [bucket('ask', ip($last)), bucket('use', ip($last))]);
    }
    q('DELETE FROM tblEventBroadcasts WHERE eventID = ?', 'i', [EVENT_ID]);
    q('DELETE FROM tblEventRSVPs WHERE eventID = ?', 'i', [EVENT_ID]);
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [EVENT_ID]);
    // The one organisation's own mail rows that P13 plants. A real database has none.
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey IN ('mail.smtp.host', 'mail.smtp.port', 'mail.smtp.username', 'mail.provider')");
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 929000 AND 929999');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 929000 AND 929999');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 929000 AND 929999');
    dropCopies();
}

// =============================================================================
// 🧰 The servers, and one request
// =============================================================================
$state = [
    'proc' => null, 'mailProc' => null, 'mailPipe' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [],
    'started' => false, 'cleaned' => false, 'read' => [], 'ports' => [], 'haystack' => '',
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
    $until = microtime(true) + 5;
    while (proc_get_status($proc)['running'] === true && microtime(true) < $until) {
        proc_terminate($proc);
        usleep(100000);
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
    // The stand-in mail servers stop themselves when this file appears.
    $child = (string) $state['child'];
    if ($child !== '' && is_dir($child . DIRECTORY_SEPARATOR . 'standin') === true) {
        @touch($child . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . 'stop');
        $until = microtime(true) + 5;
        while (is_resource($state['mailProc']) === true && proc_get_status($state['mailProc'])['running'] === true && microtime(true) < $until) {
            usleep(100000);
        }
    }
    if (is_resource($state['mailPipe']) === true) {
        fclose($state['mailPipe']);
    }
    stopServer($state['mailProc']);
    try {
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$before['settingValue'], (int) $before['isSensitive'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.mail-pages-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One installation-wide setting for the run, written straight into the
 * database. The value it had, and whether it was marked as a secret, are
 * remembered the first time and put back by the clean-up.
 *
 * @param int $secret 1 marks the row as a secret (only the password's row is ever written so here).
 */
function setting(string $key, string $value, int $secret = 0): void
{
    global $state;
    $row = one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = $row;
    }
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, ?)', 'sssi', [$key, $value, $value, $secret]);
    } else {
        q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$value, $secret, (int) $row['settingID']]);
    }
}

/** One installation-wide setting as it is stored now: [value, the "is a secret" flag]. */
function stored(string $key): array
{
    $row = one('SELECT settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);

    return $row === null ? ['(no row)', -1] : [(string) $row['settingValue'], (int) $row['isSensitive']];
}

/** Every installation-wide and organisation mail setting, as one text, to prove that nothing was written. */
function mailRows(): string
{
    return (string) json_encode(q("SELECT settingKey, siteID, settingValue, isSensitive FROM tblSettings WHERE settingKey LIKE 'mail.%' ORDER BY settingKey, siteID"));
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects. Every answer is also
 * added to the text that is searched for the password at the end.
 *
 * @param array<string,mixed>|null $post
 * @param string                   $ip   The visitor's address, sent as a proxy would send it. '' sends none.
 *
 * @return array{status:int, location:string, body:string, head:string, ms:int}
 */
function req(string $jar, string $method, string $path, ?array $post = null, string $ip = ''): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'User-Agent: ' . AGENT];
    if ($ip !== '') {
        $headers[] = 'X-Forwarded-For: ' . $ip;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $began = microtime(true);
    $raw   = curl_exec($handle);
    $ms    = (int) round((microtime(true) - $began) * 1000);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => '', 'ms' => $ms];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Mail-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => '', 'ms' => $ms];
    }
    $state['haystack'] .= "\n" . $raw;

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
        'ms'       => $ms,
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A form token for a signed-in browser, from whichever of its pages carries one. */
function anyToken(string $jar, string $ip): string
{
    foreach (['/admin/integrations/email', '/account', '/settings', '/'] as $path) {
        $found = token(req($jar, 'GET', $path, null, $ip)['body']);
        if ($found !== '') {
            return $found;
        }
    }

    return '';
}

/** A new, empty cookie jar: a browser that has never been here. */
function newJar(): string
{
    global $state;

    return $state['child'] . DIRECTORY_SEPARATOR . 'jar-' . bin2hex(random_bytes(6)) . '.txt';
}

/** A page as the words a person reads: tags out, entities turned back, spaces tidied. */
function words(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** Sign one of the people in through the real page. Returns their cookie jar. */
function signIn(string $who, string $password, string $ip): string
{
    $jar    = newJar();
    $form   = req($jar, 'GET', '/login', null, $ip);
    $answer = req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => login($who), 'password' => $password], $ip);
    if ($answer['status'] !== 302 || $answer['location'] !== '/') {
        refuse($who . ' could not sign in (HTTP ' . $answer['status'] . ' to ' . $answer['location'] . ')');
    }

    return $jar;
}

/**
 * Wait until the portal server has finished what it is doing.
 *
 * "Forgot password" answers the browser first and only then sends the e-mail
 * (#580). The server this test starts is ONE process that serves one request
 * at a time, so a second request is not served until the first script has
 * ended, work after the answer included. This sends that second request: an
 * address beginning with a dot, which the router refuses before any portal
 * code runs.
 */
function settle(): void
{
    req('', 'GET', '/.selftest-settle');
}

/** Ask for a reset link through the real page. `ms` is how long the ANSWER took; the e-mail is sent after it, and this waits for that too. */
function askForLink(string $ip, string $email): array
{
    $jar  = newJar();
    $form = req($jar, 'GET', '/forgot-password', null, $ip);
    $sent = req($jar, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $ip);
    settle();

    return $sent + ['jar' => $jar];
}

/** Everything about an answer that a stranger could compare, with the parts that differ on every request taken out. */
function shape(array $answer): string
{
    $lines = array_filter((array) preg_split('/\R/', trim($answer['head'])), static fn (string $line): bool => stripos($line, 'Date:') !== 0);
    $lines = array_map(static fn (string $line): string => stripos($line, 'Set-Cookie:') === 0 ? 'Set-Cookie: (a cookie)' : $line, $lines);
    sort($lines);

    return implode("\n", $lines) . "\n\n" . trim($answer['body']);
}

/** The page a person lands on after asking for a link, with its form token taken out. */
function landing(array $sent, string $ip): string
{
    $page = req((string) $sent['jar'], 'GET', $sent['location'] === '' ? '/forgot-password' : $sent['location'], null, $ip);

    return $page['status'] . "\n" . preg_replace('/[0-9a-f]{64}/', '(token)', $page['body']);
}

/** How many records of one kind one visitor address has in the activity log. */
function records(string $ip, string $type): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE visitorIP = ? AND activityType = ?', 'ss', [$ip, $type])['n'];
}

// -----------------------------------------------------------------------------
// 🎭 The stand-in mail servers
// -----------------------------------------------------------------------------

/**
 * Everything one stand-in server has seen, once every connection to it has
 * ended: [connection number, 1 if encryption was on, kind, the rest].
 *
 * @return list<array{0:int,1:int,2:string,3:string}>
 */
function saw(string $scenario): array
{
    global $state;
    $file   = $state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $scenario . '.log';
    $events = [];
    for ($tries = 0; $tries < 150; $tries++) {
        $events = [];
        $open   = 0;
        foreach (is_file($file) === true ? (array) file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $parts    = explode(' ', (string) $line, 4);
            $events[] = [(int) $parts[0], (int) ($parts[1] ?? 0), $parts[2] ?? '', stripcslashes($parts[3] ?? '')];
            $open    += ($parts[2] ?? '') === 'connect' ? 1 : (($parts[2] ?? '') === 'close' ? -1 : 0);
        }
        if ($open <= 0) {
            break;
        }
        usleep(100000);
    }

    return $events;
}

/**
 * What one stand-in server has seen SINCE THE LAST CALL for it.
 *
 * @return array{connections:int, signIns:int, messages:list<array{from:string, to:list<string>, encrypted:bool, head:string, html:string, text:string, raw:string}>}
 */
function mailSince(string $scenario): array
{
    global $state;
    $events = saw($scenario);
    $new    = array_slice($events, $state['read'][$scenario] ?? 0);
    $state['read'][$scenario] = count($events);

    $out  = ['connections' => 0, 'signIns' => 0, 'messages' => []];
    $from = [];
    $to   = [];
    foreach ($new as [$connection, $encrypted, $kind, $rest]) {
        if ($kind === 'connect') {
            $out['connections']++;
        } elseif ($kind === 'auth') {
            $out['signIns']++;
        } elseif ($kind === 'line' && stripos($rest, 'MAIL FROM:<') === 0) {
            $from[$connection] = substr($rest, 11, (int) strpos($rest, '>') - 11);
            $to[$connection]   = [];
        } elseif ($kind === 'line' && stripos($rest, 'RCPT TO:<') === 0) {
            $to[$connection][] = substr($rest, 9, (int) strpos($rest, '>') - 9);
        } elseif ($kind === 'body' && preg_match('/complete=1 file=(\S+)/', $rest, $m) === 1) {
            $raw   = (string) file_get_contents($state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . $m[1]);
            $parts = [];
            // Each part of the message is base64 under its own small heading.
            foreach (['text/plain' => 'text', 'text/html' => 'html'] as $type => $name) {
                $parts[$name] = preg_match('#Content-Type: ' . preg_quote($type, '#') . '[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--#s', $raw, $p) === 1
                    ? (string) base64_decode((string) preg_replace('/\s+/', '', $p[1]))
                    : '';
            }
            $out['messages'][] = [
                'from' => $from[$connection] ?? '', 'to' => $to[$connection] ?? [], 'encrypted' => $encrypted === 1,
                'head' => (string) strstr($raw, "\r\n\r\n", true), 'html' => $parts['html'], 'text' => $parts['text'], 'raw' => $raw,
            ];
        }
    }

    return $out;
}

/** Point the portal's SMTP settings at one stand-in server. */
function useServer(string $scenario): void
{
    global $state;
    setting('mail.smtp.port', (string) $state['ports'][$scenario]);
}

/** A port on which nothing is listening. */
function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $free   = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $free;
}

/**
 * Call Mailer::send() in a PHP process of its own, on the scratch copy.
 *
 * @param list<string>       $to
 * @param list<string>       $files
 * @param list<list<string>> $then  More lists of recipients, each sent the same
 *                                  message afterwards IN THE SAME PROCESS.
 *
 * @return array{sent: mixed, threw: string, outcome: string, canSend: mixed, each: list<array{0: mixed, 1: string}>, output: string}
 *         `sent` and `outcome` are the last send's; `each` is every send's [sent, outcome], in order.
 */
function sendDirect(array $to, string $subject, string $body, array $files = [], array $options = [], array $then = []): array
{
    global $state;
    $child   = (string) $state['child'];
    $sep     = DIRECTORY_SEPARATOR;
    $env     = getenv();
    $env['PORTAL_ENV']   = 'prod';
    $env['SELFTEST_JOB'] = (string) json_encode(['to' => $to, 'subject' => $subject, 'body' => $body, 'files' => $files, 'options' => $options, 'then' => $then]);
    $process = proc_open(
        [PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
            '-d', 'openssl.cafile=' . $child . $sep . 'standin' . $sep . 'certs' . $sep . 'ca.pem',
            '-d', 'session.save_path=' . $child . $sep . 'sessions', __FILE__, '--child', $child . $sep . 'web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $child,
        $env
    );
    if (is_resource($process) === false) {
        return ['sent' => null, 'threw' => 'the child process could not be started', 'outcome' => '', 'canSend' => null, 'each' => [], 'output' => ''];
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $state['haystack'] .= "\n" . $output;
    $at    = strrpos($output, RESULT_MARK);
    $found = $at === false ? null : json_decode((string) strtok(substr($output, $at + strlen(RESULT_MARK)), "\n"), true);

    return (is_array($found) === true ? $found : ['sent' => null, 'threw' => 'the child printed no result', 'outcome' => '', 'canSend' => null, 'each' => []]) + ['output' => $output];
}

/** Encrypt the way the portal's encrypt_setting() does, with the scratch copy's key. The test's own code, so it can make any row it needs. */
function sealed(string $plain, string $keyText): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyText))));
}

/** Decrypt the way the portal's decrypt_setting() does. '' when it cannot be decrypted. */
function unsealed(string $encoded, string $keyText): string
{
    $bin = base64_decode($encoded, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_hex2bin(hash('sha256', $keyText)));

    return $plain === false ? '' : $plain;
}

/** Every form the password could turn up in. */
function passwordForms(string $password): array
{
    return [
        'as typed'                     => $password,
        'escaped for a page'           => htmlspecialchars($password, ENT_QUOTES, 'UTF-8'),
        'as a web address would carry' => rawurlencode($password),
        'as a form would carry'        => urlencode($password),
        'as the sign-in sends it (1)'  => base64_encode($password),
        'as the sign-in sends it (2)'  => base64_encode("\0" . MAILBOX_USER . "\0" . $password),
        'as JSON'                      => trim((string) json_encode($password), '"'),
    ];
}

/** The first form of the password found in a text, as words; '' when none is. */
function passwordIn(string $text, string $password): string
{
    foreach (passwordForms($password) as $what => $form) {
        if (str_contains($text, $form) === true) {
            return 'the password, ' . $what . ', is in it';
        }
    }

    return '';
}

/** Run several SQL statements, as a .sql file holds them, on the test's own connection. Throws on the first error. */
function runSql(string $sql): void
{
    global $db;
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($db->more_results() === true && $db->next_result() === true);
}

/**
 * The three tables migration 213 touches, and the COPY of each that P9 runs
 * it against. The real tables are never changed by P9.
 */
const COPIES = ['tblEmailLog' => 'selftestMailPagesLog', 'tblSettings' => 'selftestMailPagesSettings', 'tblMigrations' => 'selftestMailPagesMigrations'];

function dropCopies(): void
{
    global $db;
    foreach (COPIES as $copy) {
        $db->query('DROP TABLE IF EXISTS `' . $copy . '`');
    }
}

/** The send log's copy as the database describes it, without the counter that moves with every row. */
function copyShape(): string
{
    return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) one('SHOW CREATE TABLE `' . COPIES['tblEmailLog'] . '`')['Create Table']);
}

/** The settings in the copy: name => [value, default, the "is a secret" flag]. */
function copyRows(): array
{
    $rows = [];
    foreach (q('SELECT settingKey, settingValue, defaultValue, isSensitive FROM `' . COPIES['tblSettings'] . '` WHERE siteID IS NULL ORDER BY settingKey') as $row) {
        $rows[(string) $row['settingKey']] = [(string) $row['settingValue'], (string) $row['defaultValue'], (int) $row['isSensitive']];
    }

    return $rows;
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (PEOPLE as $who => $id) {
    $global = $who === 'root' ? 1 : 0;
    $site   = ($who === 'root' || $who === 'orgadmin') ? 1 : 0;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest mail ' . $who, mailOf($who), $global, $global]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, ?)', 'iii', [$id, $site, $site]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
// An event with two people going to it, for the broadcast of P12. Its date is worked out from today.
$eventDay = date('Y-m-d', strtotime('+9 days'));
q(
    'INSERT INTO tblEvents (eventID, siteID, eventSlug, eventName, description, startDateTime, endDateTime, timezone, eventTimezone, status, isPublic, isDeleted) '
    . "VALUES (?, 1, 'mail-pages-selftest', 'Selftest mail event', 'Made by the mail pages self-test', ?, ?, 'UTC', 'UTC', 'published', 0, 0)",
    'iss',
    [EVENT_ID, $eventDay . ' 10:00:00', $eventDay . ' 11:00:00']
);
foreach (['second', 'third'] as $who) {
    q("INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, status) VALUES (?, ?, 1, 'going', 'confirmed')", 'ii', [EVENT_ID, PEOPLE[$who]]);
}

// 🔑 The mailbox's password for this run. Long, and holding characters a page
//    must escape, so that a page which printed it would be caught whichever
//    way it printed it. It begins and ends with a space, which is part of it:
//    a save that tidied the spaces away would store a different password.
$mailPassword = ' Mp<&' . bin2hex(random_bytes(10)) . '>"q9! ';

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'mail-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.mail-pages-selftest-owner', $state['token']); // the marker FIRST
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
mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/mail-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
$keyText = bin2hex(random_bytes(32));
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', $keyText);
// A small file for P12 to attach.
$attachment = $child . $sep . 'selftest-attachment.txt';
file_put_contents($attachment, "A file attached by the mail pages self-test.\n");

// 🪪 The certificates, made for this run. The maker is the mail library's own
//    test's, lifted as text: it is written inside `run.php`, which cannot be
//    loaded without running that whole test, and this package may not change
//    it. If its shape ever changes, this stops here and says so.
$standinDir = $child . $sep . 'standin';
foreach (['', $sep . 'certs', $sep . 'log', $sep . 'bodies'] as $sub) {
    mkdir($standinDir . $sub, 0700);
}
$libraryTest = (string) file_get_contents($repo . $sep . 'tools' . $sep . 'maillib' . $sep . 'tests' . $sep . 'run.php');
$from        = strpos($libraryTest, "// 🪪 The test's own certificates, written byte by byte");
$to          = strpos($libraryTest, '// 🎭 What each stand-in server does');
if ($from === false || $to === false || $to <= $from) {
    refuse('the certificate maker could not be found in tools/maillib/tests/run.php, where this test lifts it from');
}
file_put_contents($child . $sep . 'certificate-maker.php', "<?php\n// Lifted from tools/maillib/tests/run.php by tools/mail-pages-selftest.php for ONE run. Test only.\ndeclare(strict_types=1);\n" . substr($libraryTest, $from, $to - $from));
require $child . $sep . 'certificate-maker.php';
if (function_exists('makeCertificates') === false) {
    refuse('the text lifted from tools/maillib/tests/run.php did not define makeCertificates()');
}
makeCertificates($standinDir . $sep . 'certs');
foreach (['ca', 'good', 'expired', 'notyet', 'wrongname', 'selfsigned', 'privateca', 'name-list-other'] as $made) {
    if (is_file($standinDir . $sep . 'certs' . $sep . $made . '.pem') === false) {
        refuse("the certificate maker did not make '{$made}.pem'");
    }
}

// 🎭 What each stand-in mail server does (the words are smtp-standin.php's).
$mailbox   = ['user' => MAILBOX_USER, 'pass' => $mailPassword];
$scenarios = [
    'accept'          => $mailbox,
    'thief'           => $mailbox, // a second server that would accept everything: P13 proves nothing reaches it
    'refuse-signin'   => ['user' => MAILBOX_USER, 'pass' => 'a-different-password-' . bin2hex(random_bytes(6))],
    'stall'           => $mailbox + ['replies' => ['EHLO' => '@silent']],
    // Takes the WHOLE message and then hangs up with no answer: nobody can tell whether it went.
    'drop-after'      => $mailbox + ['replies' => ['BODY' => '@drop-after']],
    // Signs in as usual and refuses ONE recipient's address. Every other recipient is accepted.
    'refuse-recipient' => $mailbox + ['rcpt' => ['nobody@mailpages.selftest.invalid' => '550 5.1.1 no such mailbox here']],
    'cert-expired'    => $mailbox + ['certs' => ['expired']],
    'cert-notyet'     => $mailbox + ['certs' => ['notyet']],
    'cert-wrongname'  => $mailbox + ['certs' => ['wrongname']],
    'cert-selfsigned' => $mailbox + ['certs' => ['selfsigned']],
    'cert-privateca'  => $mailbox + ['certs' => ['privateca']],
    // Serves ONE connection, with an expired certificate, and hangs up on every later one: so the
    // library's second connection, made to read the certificate and say why, fails. Usable once a run.
    'cert-unreadable' => $mailbox + ['certs' => ['expired'], 'maxConnections' => 1],
    // In date and from the trusted authority, with the right name only in the old "common name" field.
    'cert-name-rule'  => $mailbox + ['certs' => ['name-list-other']],
];
file_put_contents($standinDir . $sep . 'scenarios.ser', serialize($scenarios));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/mail-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Mail-Pages-Selftest: __TOKEN__');
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

// The servers: each command as a LIST (no shell in between), ONE process each.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$quiet             = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']];
// 🎭 The stand-in mail servers. `--watch-parent`: the pipe to its standard
//    input stays open for as long as this file lives. If this file is killed,
//    the stand-in sees the pipe close, deletes its keys and folder, and stops.
$state['mailProc'] = proc_open(
    [PHP_BINARY, $repo . $sep . 'tools' . $sep . 'maillib' . $sep . 'tests' . $sep . 'smtp-standin.php', $standinDir, '--watch-parent'],
    [0 => ['pipe', 'r'], 1 => ['file', $standinDir . $sep . 'standin.out', 'w'], 2 => ['file', $standinDir . $sep . 'standin.err', 'w']],
    $mailPipes
);
$state['mailPipe'] = $mailPipes[0] ?? null;
// 🌐 The portal. `openssl.cafile` is PHP's own setting for "the authorities
//    this machine trusts": it is how this run's made-up authority is trusted
//    without one line of the portal being changed. `max_execution_time` is
//    raised because a busy machine can make a page slow, and a limit meant
//    for a real web server must not decide a test.
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'max_execution_time=180', '-d', 'openssl.cafile=' . $standinDir . $sep . 'certs' . $sep . 'ca.pem',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    $quiet,
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false || is_resource($state['mailProc']) === false) {
    refuse('a server could not be started');
}
$deadline = microtime(true) + 30;
while ((portAnswers($port) === false || is_file($standinDir . $sep . 'ports.ser') === false) && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false || is_file($standinDir . $sep . 'ports.ser') === false) {
    refuse("a server never answered (the portal on port {$port}; the stand-in mail servers said: " . trim((string) @file_get_contents($standinDir . $sep . 'standin.err')) . ')');
}
$state['ports'] = (array) unserialize((string) file_get_contents($standinDir . $sep . 'ports.ser'), ['allowed_classes' => false]);
if (array_keys($state['ports']) !== array_keys($scenarios)) {
    refuse('the stand-in mail servers did not start one server for each scenario');
}

echo "The SMTP mail route, on the real pages — self-test (#592, part 2)\n";
echo 'portal server process ' . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', stand-in mail servers process ' . (int) proc_get_status($state['mailProc'])['pid']
    . ' (' . count($state['ports']) . ' servers, 127.0.0.1 only), PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// =============================================================================
head('P9. The database: what a fresh install holds, and what migration 213 does to an older one');
// =============================================================================
// Run FIRST, before this test writes any setting, so that what is read here is
// what web/_sql/full_schema.sql put there.
$seeds = [];
foreach (q("SELECT settingKey, defaultValue, isSensitive FROM tblSettings WHERE siteID IS NULL AND (settingKey LIKE 'mail.smtp.%' OR settingKey = 'mail.limit.perHour') ORDER BY settingKey") as $row) {
    $seeds[(string) $row['settingKey']] = [(string) $row['defaultValue'], (int) $row['isSensitive']];
}
$expectedSeeds = [
    'mail.limit.perHour' => ['', 0], 'mail.smtp.host' => ['', 0], 'mail.smtp.password' => ['', 1],
    'mail.smtp.port' => ['587', 0], 'mail.smtp.security' => ['starttls', 0], 'mail.smtp.username' => ['', 0],
];
ok('the install script seeds the six settings, with port 587 and STARTTLS as defaults, and marks the password\'s row (and only that one) as a secret', $seeds === $expectedSeeds, (string) json_encode($seeds));
$dead = (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey IN ('email.provider', 'email.from')")['n'];
ok('the two settings nothing reads (email.provider, email.from) are not there', $dead === 0, $dead . ' row(s) found');
$columns = [];
foreach (q("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog'") as $row) {
    $columns[(string) $row['COLUMN_NAME']] = $row;
}
$hasKey = (int) one("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND INDEX_NAME = 'idx_emaillog_sent_utc' AND COLUMN_NAME = 'sentAtUtc'")['n'];
ok(
    'the send log has a moment in UTC (whose description says so), a recipient count that starts at 1, a key on the moment, and descriptions that name SMTP',
    ($columns['sentAtUtc']['COLUMN_TYPE'] ?? '') === 'datetime' && ($columns['sentAtUtc']['IS_NULLABLE'] ?? '') === 'YES' && str_contains((string) ($columns['sentAtUtc']['COLUMN_COMMENT'] ?? ''), 'A MOMENT IN UTC') === true
        && str_contains((string) ($columns['recipientCount']['COLUMN_TYPE'] ?? ''), 'smallint') === true && (string) ($columns['recipientCount']['COLUMN_DEFAULT'] ?? '') === '1' && $hasKey === 1
        && str_contains((string) ($columns['provider']['COLUMN_COMMENT'] ?? ''), 'smtp') === true && str_contains((string) ($columns['httpCode']['COLUMN_COMMENT'] ?? ''), 'SMTP reply code') === true,
    (string) json_encode([$columns['sentAtUtc'] ?? null, $columns['recipientCount'] ?? null, $hasKey, $columns['provider']['COLUMN_COMMENT'] ?? null, $columns['httpCode']['COLUMN_COMMENT'] ?? null])
);

// An OLDER database, made here from copies of the three tables the migration
// touches: the send log as it was before migration 213, five of the six
// settings missing (the password's among them), the sixth holding a value
// somebody saved, and the two dead settings back. The migration's own text is
// then run with the three table names changed to the copies' names, and
// nothing else changed.
//
// Only ONE of the six used to be missing here, and not the password's. A
// migration that added the password's row WITHOUT its "is a secret" mark then
// passed, because that row was already there and was never added.
foreach (COPIES as $real => $copy) {
    $db->query('CREATE TABLE `' . $copy . '` LIKE `' . $real . '`');
}
$log      = '`' . COPIES['tblEmailLog'] . '`';
$settings = '`' . COPIES['tblSettings'] . '`';
q('INSERT INTO ' . $settings . " (siteID, settingKey, settingValue, isSensitive, defaultValue) SELECT siteID, settingKey, settingValue, isSensitive, defaultValue FROM tblSettings WHERE siteID IS NULL AND settingKey LIKE 'mail.%'");
$fresh    = copyShape();
$expected = copyRows();
$expected['mail.smtp.host'][0] = 'kept.mailpages.selftest.invalid';
$db->query('ALTER TABLE ' . $log . ' DROP KEY idx_emaillog_sent_utc, DROP COLUMN sentAtUtc, DROP COLUMN recipientCount');
$db->query('ALTER TABLE ' . $log . " MODIFY COLUMN provider VARCHAR(20) NOT NULL COMMENT 'ms365, ms365-shared, or google'");
$db->query('ALTER TABLE ' . $log . " MODIFY COLUMN httpCode SMALLINT DEFAULT NULL COMMENT 'Provider HTTP response code, when one was received'");
q('UPDATE ' . $settings . " SET settingValue = 'kept.mailpages.selftest.invalid' WHERE settingKey = 'mail.smtp.host'");
q('DELETE FROM ' . $settings . " WHERE settingKey IN ('mail.limit.perHour', 'mail.smtp.password', 'mail.smtp.port', 'mail.smtp.security', 'mail.smtp.username')");
$missingBefore = (int) one('SELECT COUNT(*) AS n FROM ' . $settings . " WHERE settingKey = 'mail.smtp.password'")['n'];
q('INSERT INTO ' . $settings . " (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'email.provider', 'smtp', 'smtp', 0), (NULL, 'email.from', 'somebody@mailpages.selftest.invalid', '', 0)");
$older     = copyShape();
$migration = strtr((string) file_get_contents($webSrc . $sep . '_sql' . $sep . '213_smtp_mail_route.sql'), COPIES);
$error     = '';
try {
    runSql($migration);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$afterOnce = [copyShape(), copyRows(), (int) one('SELECT COUNT(*) AS n FROM `' . COPIES['tblMigrations'] . "` WHERE filename = '213_smtp_mail_route.sql'")['n']];
ok(
    'migration 213 on an older database: the send log comes out exactly as a fresh install has it, the five missing settings are added exactly as a fresh install has them, the saved value is kept, the two dead settings go, and the migration records itself',
    $error === '' && $older !== $fresh && $afterOnce[0] === $fresh && $afterOnce[1] === $expected && $afterOnce[2] === 1,
    ($error === '' ? '' : 'error: ' . $error . '; ') . 'shape ' . ($afterOnce[0] === $fresh ? 'same' : "differs:\n" . $afterOnce[0] . "\nfresh:\n" . $fresh)
        . '; settings ' . ($afterOnce[1] === $expected ? 'as expected' : 'are ' . json_encode($afterOnce[1]) . ', expected ' . json_encode($expected)) . '; recorded ' . $afterOnce[2] . ' time(s)'
);
ok(
    'migration 213 adds the password\'s row MARKED as a secret when an older database does not have it (an unmarked row is one the general Settings page would show in full)',
    $missingBefore === 0 && ($afterOnce[1]['mail.smtp.password'] ?? null) === ['', '', 1],
    'rows before the migration: ' . $missingBefore . '; the row after it [value, default, the "is a secret" flag]: ' . json_encode($afterOnce[1]['mail.smtp.password'] ?? null)
);
$error = '';
try {
    runSql($migration);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
ok('migration 213 a second time: no error, and nothing changes', $error === '' && [copyShape(), copyRows(), 1] === $afterOnce, $error === '' ? 'something changed on the second run' : 'error: ' . $error);
dropCopies();

// =============================================================================
// The settings every part below starts from
// =============================================================================
// Believe the "X-Forwarded-For" header from 127.0.0.1, so each part has a visitor address of its own.
setting('portal.trustedProxies', '127.0.0.1');
setting('portal.trustedProxyHeader', 'x-forwarded-for');
// 🕐 A time zone that is never UTC, so that a time shown without being converted would be seen.
setting('site.timezone', 'Pacific/Auckland');
setting('site.name', 'Selftest Portal');
// The calendar is switched off on a fresh install; P12 sends an event broadcast.
setting('calendar.enabled', 'true');
$cronToken = bin2hex(random_bytes(16));
setting('maintenance.cronToken', $cronToken);
// Nothing set up for any route, and Microsoft 365 the route in use, as on a fresh install.
foreach (['mail.fallbackProvider', 'mail.ms365.sharedMailbox', 'auth.ms365.tenantID', 'auth.ms365.appwide.clientID', 'auth.ms365.appwide.clientSecret',
    'mail.defaultFromAddress', 'mail.defaultFromName', 'mail.defaultReplyTo', 'mail.google.serviceAccountKeyFile', 'mail.google.delegateUser',
    'mail.smtp.host', 'mail.smtp.username', 'mail.limit.perHour'] as $key) {
    setting($key, '');
}
setting('mail.provider', 'ms365');
setting('mail.smtp.port', '587');
setting('mail.smtp.security', 'starttls');
setting('mail.smtp.password', '', 1);

$rootJar  = signIn('root', $password, ip(1));
$orgJar   = signIn('orgadmin', $password, ip(2));
$memberJar = signIn('member', $password, ip(3));
$emailPage = '/admin/integrations/email';

/** What the SMTP form posts when everything is filled in for the accepting stand-in. */
$fullForm = static function (array $over = []) use (&$state, $mailPassword): array {
    return $over + [
        'action' => 'save_smtp', 'smtpHost' => MAIL_HOST, 'smtpPort' => (string) $state['ports']['accept'], 'smtpSecurity' => 'starttls',
        'smtpUsername' => MAILBOX_USER, 'smtpPassword' => $mailPassword, 'fromAddress' => SENDER, 'fromName' => SENDER_NAME, 'replyTo' => REPLY_TO, 'perHour' => '',
    ];
};
/** Send one of the e-mail page's forms as the browser in `$jar`. */
$post = static function (string $jar, string $ip, array $fields) use ($emailPage): array {
    return req($jar, 'POST', $emailPage, ['csrf_token' => anyToken($jar, $ip)] + $fields, $ip);
};
/** The administrator's notice on the e-mail page, as words: "ready: E-mail is set to…"; '' when the page has none. */
$notice = static function (array $page): string {
    return preg_match('#<div class="alert alert-\w+" role="\w+" data-mail-status="([a-z-]+)">(.*?)</div>#s', $page['body'], $m) === 1 ? $m[1] . ': ' . words($m[2]) : '';
};
/** What the monitor's address says about e-mail: [state, label, detail]. It asks Mailer::sendingRoute() and nothing else. */
$monitor = static function () use ($cronToken): array {
    $found = (array) json_decode(req('', 'GET', '/cron/health?token=' . $cronToken, null, ip(9))['body'], true);
    $probe = (array) ($found['probes']['Email'] ?? []);

    return [(string) ($probe['state'] ?? ''), (string) ($probe['label'] ?? ''), (string) ($probe['detail'] ?? '')];
};
/** What an SMTP "Send test" showed: whether it was sent, each step's state, the reason, the last line. */
$testShown = static function (array $page): array {
    preg_match_all('/data-step-state="([a-z-]+)"/', $page['body'], $steps);

    return [
        'result' => preg_match('/data-mail-test="([a-z-]+)"/', $page['body'], $m) === 1 ? $m[1] : '',
        'steps'  => implode(',', $steps[1]),
        'reason' => preg_match('#<p class="text-break" data-mail-test-reason>(.*?)</p>#s', $page['body'], $m) === 1 ? words($m[1]) : '',
        'last'   => preg_match('#<code data-mail-test-last-line>(.*?)</code>#s', $page['body'], $m) === 1 ? words($m[1]) : '',
    ];
};
/** Send a test through the SMTP mailbox, as the global administrator. */
$sendTest = static function (string $to, string $ip) use ($post, $rootJar): array {
    return $post($rootJar, $ip, ['action' => 'test_send', 'to' => $to, 'through' => 'smtp']);
};

// =============================================================================
head('P5. Only a global administrator may save, choose the route or send a test');
// =============================================================================
$page = req($orgJar, 'GET', $emailPage, null, ip(2));
ok(
    'an administrator of one organisation can look at the page, and is shown none of its three forms',
    $page['status'] === 200 && str_contains($page['body'], 'data-mail-status') === true && str_contains($page['body'], 'name="smtpPassword"') === false
        && str_contains($page['body'], 'value="set_route"') === false && str_contains($page['body'], 'value="test_send"') === false
        && str_contains(words($page['body']), 'only a global administrator can change it or send a test') === true,
    'HTTP ' . $page['status']
);
// A send that belongs to no organisation (the portal's own), beside one of this organisation's.
q("INSERT INTO tblEmailLog (siteID, provider, fromAddress, toRecipients, subject, status, sentAtUtc) VALUES (NULL, 'smtp', ?, 'elsewhere@mailpages.selftest.invalid', 'P5 not this organisation', 'sent', UTC_TIMESTAMP()), (1, 'smtp', ?, 'here@mailpages.selftest.invalid', 'P5 this organisation', 'sent', UTC_TIMESTAMP())", 'ss', [SENDER, SENDER]);
$orgSees  = req($orgJar, 'GET', $emailPage, null, ip(2))['body'];
$rootSees = req($rootJar, 'GET', $emailPage, null, ip(1))['body'];
ok(
    '"Recent sends" shows that administrator their own organisation\'s sends only; a global administrator sees every one',
    str_contains($orgSees, 'here@mailpages.selftest.invalid') === true && str_contains($orgSees, 'elsewhere@mailpages.selftest.invalid') === false
        && str_contains($rootSees, 'here@mailpages.selftest.invalid') === true && str_contains($rootSees, 'elsewhere@mailpages.selftest.invalid') === true,
    ''
);
q("DELETE FROM tblEmailLog WHERE subject IN ('P5 not this organisation', 'P5 this organisation')");
$before = mailRows();
$answer = $post($orgJar, ip(2), $fullForm());
ok(
    'that administrator posts the SMTP form anyway: refused in plain words, nothing is written, and the refusal is in the activity log',
    $answer['status'] === 200 && str_contains(words($answer['body']), 'Only a global administrator can change them or send a test. Nothing was changed.') === true && mailRows() === $before
        && (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'SettingsGroupSaveRefused' AND activityDescription LIKE '%saving the SMTP settings%'", 'i', [PEOPLE['orgadmin']])['n'] === 1,
    'HTTP ' . $answer['status'] . '; rows ' . (mailRows() === $before ? 'unchanged' : 'CHANGED')
);
$answer = $post($orgJar, ip(2), ['action' => 'set_route', 'route' => 'smtp']);
ok(
    'that administrator tries to choose the route: refused, the route is unchanged, and the refusal is logged',
    $answer['status'] === 200 && stored('mail.provider') === ['ms365', 0] && mailRows() === $before
        && (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'SettingsGroupSaveRefused' AND activityDescription LIKE '%choosing the mail route%'", 'i', [PEOPLE['orgadmin']])['n'] === 1,
    'HTTP ' . $answer['status'] . '; mail.provider ' . json_encode(stored('mail.provider'))
);
mailSince('accept');
$logRows = (int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'];
$answer  = $post($orgJar, ip(2), ['action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp']);
$mail    = mailSince('accept');
ok(
    'that administrator tries to send a test through SMTP: refused, no mail server is contacted, nothing is added to the send log, and the refusal is logged',
    $answer['status'] === 200 && str_contains($answer['body'], 'data-mail-test') === false && $mail['connections'] === 0 && (int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'] === $logRows
        && (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'SettingsGroupSaveRefused' AND activityDescription LIKE '%sending a test message%'", 'i', [PEOPLE['orgadmin']])['n'] === 1,
    'HTTP ' . $answer['status'] . '; connections ' . $mail['connections']
);
$page   = req($memberJar, 'GET', $emailPage, null, ip(3));
$answer = req($memberJar, 'POST', $emailPage, ['csrf_token' => anyToken($memberJar, ip(3))] + $fullForm(), ip(3));
ok('an ordinary member is refused the page (403) whether they look or post, and nothing is written', $page['status'] === 403 && $answer['status'] === 403 && str_contains($page['body'], 'data-mail-status') === false && mailRows() === $before, 'GET ' . $page['status'] . ', POST ' . $answer['status']);
$answer = req($rootJar, 'POST', $emailPage, ['csrf_token' => str_repeat('0', 64)] + $fullForm(), ip(1));
ok('a global administrator\'s form with a wrong form token is refused and nothing is written', $answer['status'] === 200 && str_contains(words($answer['body']), 'This form had expired, so nothing was changed.') === true && mailRows() === $before, 'HTTP ' . $answer['status']);
// The same for the other two forms. Only the save was tried here before, so a
// route chooser or a "Send test" that took a request with no token passed.
// (That is how another website gets a signed-in administrator's browser to
// change the route, or to send mail, without their knowing.)
$noToken    = req($rootJar, 'POST', $emailPage, ['action' => 'set_route', 'route' => 'smtp'], ip(1));
$wrongToken = req($rootJar, 'POST', $emailPage, ['csrf_token' => str_repeat('0', 64), 'action' => 'set_route', 'route' => 'smtp'], ip(1));
$changes    = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'MailRouteChanged'", 'i', [PEOPLE['root']])['n'];
ok(
    'a global administrator\'s choice of route with NO form token, and with a wrong one, is refused: the route is unchanged and no change is logged',
    $noToken['status'] === 200 && $wrongToken['status'] === 200 && str_contains(words($noToken['body']), 'This form had expired, so nothing was changed.') === true
        && str_contains(words($wrongToken['body']), 'This form had expired, so nothing was changed.') === true && stored('mail.provider') === ['ms365', 0] && mailRows() === $before && $changes === 0,
    'HTTP ' . $noToken['status'] . ' and ' . $wrongToken['status'] . '; mail.provider ' . json_encode(stored('mail.provider')) . '; changes logged ' . $changes
);
mailSince('accept');
$logRows    = (int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'];
$noToken    = req($rootJar, 'POST', $emailPage, ['action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp'], ip(1));
$wrongToken = req($rootJar, 'POST', $emailPage, ['csrf_token' => str_repeat('0', 64), 'action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp'], ip(1));
$mail       = mailSince('accept');
ok(
    'a global administrator\'s "Send test" with NO form token, and with a wrong one, is refused: no test is run, no mail server is contacted and nothing is added to the send log',
    $noToken['status'] === 200 && $wrongToken['status'] === 200 && str_contains($noToken['body'], 'data-mail-test') === false && str_contains($wrongToken['body'], 'data-mail-test') === false
        && str_contains(words($noToken['body']), 'This form had expired, so nothing was changed.') === true && $mail['connections'] === 0 && (int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'] === $logRows,
    'HTTP ' . $noToken['status'] . ' and ' . $wrongToken['status'] . '; connections ' . $mail['connections'] . '; send log rows added ' . ((int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'] - $logRows)
);

// =============================================================================
head('P4. The password: stored encrypted, never shown again, kept by an empty box, removed by the tick box');
// =============================================================================
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    'before anything is saved the global administrator sees the form, told that no password is stored, and an empty state for the send log',
    $page['status'] === 200 && str_contains($page['body'], 'name="smtpPassword"') === true && str_contains($page['body'], 'data-password-state="none"') === true
        && str_contains(words($page['body']), 'No password is stored yet.') === true && preg_match_all('/<h1[\s>]/', $page['body']) === 1,
    'HTTP ' . $page['status']
);
ok(
    'the form says, beside the hourly limit, that it is not exact: messages sent at the same moment can together go a little over it',
    str_contains(words($page['body']), 'The limit is not exact: messages sent at the same moment can together go a little over it') === true,
    'the sentence is not on the page'
);
$answer = $post($rootJar, ip(1), $fullForm());
[$cipher, $secret] = stored('mail.smtp.password');
ok(
    'saving through the page stores the password ENCRYPTED and marked as a secret: the row holds neither the password nor anything that decodes to it, the installation\'s key turns it back exactly (the spaces at its ends included), and the activity log says who saved and that a password was stored',
    $answer['status'] === 302 && $secret === 1 && $cipher !== '' && passwordIn($cipher . ' ' . (string) base64_decode($cipher), $mailPassword) === '' && unsealed($cipher, $keyText) === $mailPassword
        && stored('mail.smtp.host') === [MAIL_HOST, 0] && stored('mail.defaultFromAddress') === [SENDER, 0]
        && (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'MailSettingsSaved' AND activityDescription = 'The SMTP mail settings were saved. A new mailbox password was stored.'", 'i', [PEOPLE['root']])['n'] === 1,
    'HTTP ' . $answer['status'] . '; secret flag ' . $secret . '; decrypts: ' . (unsealed($cipher, $keyText) === $mailPassword ? 'yes' : 'no')
);
$page = req($rootJar, 'GET', $answer['location'] === '' ? $emailPage : $answer['location'], null, ip(1));
ok(
    'the page afterwards says a password is stored, and does not hold it: the password box has no value at all',
    $page['status'] === 200 && str_contains($page['body'], 'data-password-state="stored"') === true && passwordIn($page['body'], $mailPassword) === ''
        && preg_match('/<input type="password" name="smtpPassword"[^>]*>/', $page['body'], $box) === 1 && str_contains($box[0], 'value=') === false
        && str_contains(words($page['body']), 'The SMTP settings were saved. A new mailbox password was stored.') === true,
    'HTTP ' . $page['status'] . '; ' . passwordIn($page['body'], $mailPassword)
);
$answer = $post($rootJar, ip(1), $fullForm(['smtpPassword' => '', 'fromName' => SENDER_NAME . ' Two']));
ok(
    'saving again with the password box EMPTY keeps the stored password, byte for byte, and saves the rest',
    $answer['status'] === 302 && stored('mail.smtp.password') === [$cipher, 1] && stored('mail.defaultFromName') === [SENDER_NAME . ' Two', 0],
    'HTTP ' . $answer['status'] . '; password row ' . (stored('mail.smtp.password') === [$cipher, 1] ? 'unchanged' : 'CHANGED')
);
$answer = $post($rootJar, ip(1), $fullForm(['smtpPassword' => 'another-' . $mailPassword, 'removePassword' => '1']));
ok('a new password AND the "remove" tick together are refused, and the stored password is untouched', $answer['status'] === 200 && str_contains(words($answer['body']), 'Not both.') === true && stored('mail.smtp.password') === [$cipher, 1], 'HTTP ' . $answer['status']);
$answer = $post($rootJar, ip(1), $fullForm(['smtpPassword' => '', 'removePassword' => '1']));
$page   = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    'the "remove" tick empties the stored password, leaves the row marked as a secret, and the page says none is stored',
    $answer['status'] === 302 && stored('mail.smtp.password') === ['', 1] && str_contains($page['body'], 'data-password-state="none"') === true,
    'HTTP ' . $answer['status'] . '; row ' . json_encode(stored('mail.smtp.password'))
);
$answer = $post($rootJar, ip(1), $fullForm(['fromName' => SENDER_NAME]));
[$cipher, $secret] = stored('mail.smtp.password');
ok('typing it in again stores it encrypted again', $answer['status'] === 302 && $secret === 1 && unsealed($cipher, $keyText) === $mailPassword, 'HTTP ' . $answer['status']);
// What the GENERAL Settings page shows for the row. It is not changed by this
// package; this holds in place what it can and cannot give away.
$page    = req($rootJar, 'GET', '/settings', null, ip(1));
$rowHtml = preg_match('#data-setting-key="mail\.smtp\.password">(.*?)<div class="portal-data-row#s', $page['body'], $m) === 1 ? $m[1] : '';
ok(
    'the general Settings page shows that row as a locked secret; to a global administrator it can reveal the ENCRYPTED text, and the password itself is nowhere on the page',
    $page['status'] === 200 && $rowHtml !== '' && str_contains($rowHtml, 'fa-lock') === true && str_contains($rowHtml, '••••••••') === true
        && str_contains($rowHtml, htmlspecialchars($cipher, ENT_QUOTES, 'UTF-8')) === true && str_contains($rowHtml, 'data-value=""') === true && passwordIn($page['body'], $mailPassword) === '',
    'HTTP ' . $page['status'] . '; row ' . ($rowHtml === '' ? 'not found' : 'found') . '; ' . passwordIn($page['body'], $mailPassword)
);
$page    = req($orgJar, 'GET', '/settings', null, ip(2));
$rowHtml = preg_match('#data-setting-key="mail\.smtp\.password">(.*?)<div class="portal-data-row#s', $page['body'], $m) === 1 ? $m[1] : '';
ok(
    'to an administrator of one organisation the general Settings page shows only dots for it: neither the password nor the encrypted text',
    $page['status'] === 200 && $rowHtml !== '' && str_contains($rowHtml, '••••••••') === true && str_contains($rowHtml, htmlspecialchars($cipher, ENT_QUOTES, 'UTF-8')) === false && passwordIn($page['body'], $mailPassword) === '',
    'HTTP ' . $page['status'] . '; row ' . ($rowHtml === '' ? 'not found' : 'found')
);

// =============================================================================
head('P7. What could never be right is refused at save, and nothing is stored');
// =============================================================================
$before = mailRows();
$answer = $post($rootJar, ip(1), $fullForm(['smtpPassword' => '', 'fromName' => "Office\r\nBcc: thief@mailpages.selftest.invalid"]));
ok(
    'a line break in the "from" name is refused, the page says which box, and nothing is stored',
    $answer['status'] === 200 && preg_match('#data-mail-form-errors>(.*?)</div>#s', $answer['body'], $m) === 1 && str_contains(words($m[1]), 'The "from" name contains a line break') === true && mailRows() === $before,
    'HTTP ' . $answer['status'] . '; rows ' . (mailRows() === $before ? 'unchanged' : 'CHANGED')
);
$answer = $post($rootJar, ip(1), $fullForm(['smtpPassword' => '', 'replyTo' => REPLY_TO . "\nBcc: thief@mailpages.selftest.invalid"]));
ok(
    'a line break in the reply-to address is refused, and nothing is stored',
    $answer['status'] === 200 && preg_match('#data-mail-form-errors>(.*?)</div>#s', $answer['body'], $m) === 1 && str_contains(words($m[1]), 'The reply-to address contains a line break') === true && mailRows() === $before,
    'HTTP ' . $answer['status'] . '; rows ' . (mailRows() === $before ? 'unchanged' : 'CHANGED')
);
$answer = $post($rootJar, ip(1), $fullForm(['smtpPassword' => '', 'smtpHost' => 'https://mail.mailpages.selftest.invalid:587', 'smtpPort' => '99999', 'smtpSecurity' => 'none', 'perHour' => 'fifty']));
$said   = preg_match('#data-mail-form-errors>(.*?)</div>#s', $answer['body'], $m) === 1 ? words($m[1]) : '';
ok(
    'a server name with "https://" in it, a port of 99999, "no encryption" and a limit of "fifty" are each refused in their own words, nothing is stored, and what was typed is shown again to be corrected',
    $answer['status'] === 200 && str_contains($said, 'must be a server name') === true && str_contains($said, 'The port must be a number from 1 to 65535') === true
        && str_contains($said, 'does not send e-mail without encryption') === true && str_contains($said, 'The hourly limit must be a whole number') === true && mailRows() === $before
        && str_contains($answer['body'], 'value="https://mail.mailpages.selftest.invalid:587"') === true,
    'HTTP ' . $answer['status'] . '; said: ' . $said
);

// =============================================================================
head('P3. Mailer::canSend(): yes when everything is there, and no, naming the piece, for each thing missing');
// =============================================================================
// The route is chosen through the real page, by the global administrator.
$answer  = $post($rootJar, ip(1), ['action' => 'set_route', 'route' => 'carrier-pigeon']);
$refused = $answer['status'] === 200 && stored('mail.provider') === ['ms365', 0] && str_contains(words($answer['body']), 'Choose one of the routes listed. Nothing was changed.') === true;
$answer  = $post($rootJar, ip(1), ['action' => 'set_route', 'route' => 'smtp']);
ok(
    'choosing the route on the page: a route the portal does not have is refused; "smtp" is stored, and the change is in the activity log with the old and new route',
    $refused === true && $answer['status'] === 302 && stored('mail.provider') === ['smtp', 0]
        && (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'MailRouteChanged' AND activityDescription = 'The mail route was changed from ms365 to smtp.'", 'i', [PEOPLE['root']])['n'] === 1,
    'unknown route refused: ' . ($refused === true ? 'yes' : 'NO') . '; HTTP ' . $answer['status'] . '; mail.provider ' . json_encode(stored('mail.provider'))
);
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
$seen = $monitor();
ok(
    'everything filled in and SMTP chosen: the e-mail page says mail goes through an SMTP mailbox and that this is not proof of delivery; the health monitor shows it as set up',
    str_starts_with($notice($page), 'ready: E-mail is set to go through an SMTP mailbox') === true && str_contains($notice($page), 'does not prove a message arrives') === true
        && $seen[0] === 'ok' && $seen[1] === 'Set up (an SMTP mailbox)',
    $notice($page) . ' / ' . json_encode($seen)
);
$good = ['mail.smtp.host' => MAIL_HOST, 'mail.smtp.port' => (string) $state['ports']['accept'], 'mail.smtp.security' => 'starttls', 'mail.smtp.username' => MAILBOX_USER, 'mail.defaultFromAddress' => SENDER, 'mail.limit.perHour' => ''];
foreach ([
    ['mail.smtp.host', '', "the mail server's name", 'no mail server name'],
    ['mail.smtp.host', 'not_a_server_name', 'is not a server name', 'a mail server name with a character no server name has (only the mail library\'s own check knows this one)'],
    ['mail.smtp.port', '0', "the mail server's port", 'a port of 0'],
    ['mail.smtp.security', 'none', 'the kind of encryption', 'a kind of encryption of "none"'],
    ['mail.smtp.username', '', "the mailbox's user name", 'no user name'],
    ['mail.defaultFromAddress', '', 'an address to send from', 'no "from" address'],
    ['mail.defaultFromAddress', 'office@localhost', 'a usable "from" address', 'a "from" address the mail library would refuse (no dot in its domain)'],
    ['mail.limit.perHour', 'fifty', 'a usable hourly limit', 'an hourly limit that is not a number'],
] as [$key, $bad, $names, $what]) {
    setting($key, $bad);
    $seen = $monitor();
    ok($what . ': not set up, and what is missing is named', $seen[0] === 'warn' && $seen[1] === 'Not set up' && str_contains($seen[2], $names) === true, (string) json_encode($seen));
    setting($key, $good[$key]);
}
setting('mail.smtp.password', '', 1);
$seen = $monitor();
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    'no password: not set up; the health monitor sends the administrator to the e-mail page (not to the general Settings page), and the e-mail page says the password is typed there and nowhere else',
    $seen[0] === 'warn' && str_contains($seen[2], "the mailbox's password") === true && str_contains($seen[2], 'Fill these in at Admin → Integrations → Email') === true && str_contains($seen[2], 'Admin → Settings') === false
        && str_starts_with($notice($page), 'not-ready: This portal cannot send e-mail yet') === true && str_contains($notice($page), 'must be typed there and nowhere else') === true,
    json_encode($seen) . ' / ' . $notice($page)
);
// A password encrypted with ANOTHER key: what a restored database on a new installation holds.
setting('mail.smtp.password', sealed($mailPassword, bin2hex(random_bytes(32))), 1);
$seen = $monitor();
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    'a stored password that cannot be decrypted: not set up, said as "cannot be read", and the form asks for it again',
    $seen[0] === 'warn' && str_contains($seen[2], 'cannot be read') === true && str_contains($page['body'], 'data-password-state="unreadable"') === true,
    (string) json_encode($seen)
);
setting('mail.smtp.password', $cipher, 1);
ok('with the password put back, the portal can send again', $monitor()[0] === 'ok', (string) json_encode($monitor()));

// =============================================================================
head('P1. "Forgot password" through SMTP');
// =============================================================================
useServer('accept');
mailSince('accept');
$sent = askForLink(ip(11), mailOf('member'));
$mail = mailSince('accept');
$link = preg_match('#/reset-password\?token=([0-9a-f]{64})#', (string) ($mail['messages'][0]['html'] ?? ''), $m) === 1 ? $m[1] : '';
ok(
    'the stand-in mail server receives ONE message, over an encrypted connection, from the saved address to that person and nobody else, holding a reset link in both its versions',
    count($mail['messages']) === 1 && $mail['messages'][0]['encrypted'] === true && $mail['messages'][0]['from'] === SENDER && $mail['messages'][0]['to'] === [mailOf('member')]
        && $link !== '' && str_contains($mail['messages'][0]['text'], $link) === true,
    count($mail['messages']) . ' message(s); ' . json_encode([$mail['messages'][0]['from'] ?? null, $mail['messages'][0]['to'] ?? null]) . '; link ' . ($link === '' ? 'not found' : 'found')
);
$headers = (string) ($mail['messages'][0]['head'] ?? '');
ok(
    'the message carries the saved "from" name and address and the saved reply-to address',
    preg_match('/^From: ' . preg_quote(SENDER_NAME, '/') . ' <' . preg_quote(SENDER, '/') . '>\r?$/m', $headers) === 1 && preg_match('/^Reply-To: ' . preg_quote(REPLY_TO, '/') . '\r?$/m', $headers) === 1,
    $headers
);
$opened = req(newJar(), 'GET', '/reset-password?token=' . $link, null, ip(11));
ok('the link in the message opens the page for choosing a new password', $link !== '' && $opened['status'] === 200 && str_contains($opened['body'], 'name="password_confirm"') === true, 'HTTP ' . $opened['status']);
$row = one("SELECT provider, status, httpCode, fromAddress, recipientCount, errorCode, ABS(TIMESTAMPDIFF(SECOND, sentAtUtc, UTC_TIMESTAMP())) AS age, sentAtUtc FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1", 's', [mailOf('member')]);
ok(
    'the send log says: sent, by smtp, the mail server\'s reply number 250, one recipient, at a moment in UTC that is now; and the activity log says the reset e-mail was sent',
    $row !== null && $row['provider'] === 'smtp' && $row['status'] === 'sent' && (int) $row['httpCode'] === 250 && $row['fromAddress'] === SENDER && (int) $row['recipientCount'] === 1
        && $row['age'] !== null && (int) $row['age'] < 300 && records(ip(11), 'PasswordResetEmailed') === 1,
    (string) json_encode($row) . '; PasswordResetEmailed: ' . records(ip(11), 'PasswordResetEmailed')
);
$page     = req($rootJar, 'GET', $emailPage, null, ip(1));
$expected = (new DateTimeImmutable((string) ($row['sentAtUtc'] ?? 'now'), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Auckland'))->format('j M Y, H:i T');
ok(
    '"Recent sends" lists it as sent, with its time shown in the organisation\'s own zone and the zone\'s short name beside it',
    preg_match('#data-mail-log-status="sent">(.*?)</div>\s*</div>#s', $page['body'], $m) === 1 && str_contains(words($m[1]), mailOf('member')) === true && str_contains(words($m[1]), $expected) === true,
    'expected the time ' . $expected . ' in: ' . (isset($m[1]) === true ? words($m[1]) : '(no sent row found)')
);
$baseline     = $sent;
$baselineLand = landing($sent, ip(11));

// =============================================================================
head('P2. The person is shown the same thing whatever happens to the e-mail');
// =============================================================================
foreach ([
    [21, 'accept', 'nobody-here@mailpages.selftest.invalid', 'an address with no account', 0, ''],
    [22, 'refuse-signin', mailOf('member'), 'a mail server that refuses the sign-in', 0, 'PasswordResetMailFailed'],
    [23, 'stall', mailOf('member'), 'a mail server that stops answering', 0, 'PasswordResetMailFailed'],
    [24, 'cert-expired', mailOf('member'), 'a mail server whose certificate has expired', 0, 'PasswordResetMailFailed'],
] as [$last, $scenario, $address, $what, $expectMessages, $record]) {
    useServer($scenario);
    mailSince($scenario);
    $sent = askForLink(ip($last), $address);
    $mail = mailSince($scenario);
    ok(
        $what . ': the same status, address and words as when the e-mail went, answered before the mail server was waited for, and no message is accepted',
        shape($sent) === shape($baseline) && landing($sent, ip($last)) === $baselineLand && $sent['ms'] < 4000 && count($mail['messages']) === $expectMessages
            && ($record === '' || records(ip($last), $record) === 1),
        'answered in ' . $sent['ms'] . ' ms; messages ' . count($mail['messages']) . ($record === '' ? '' : '; ' . $record . ': ' . records(ip($last), $record))
            . (shape($sent) === shape($baseline) ? '' : "\nthis:\n" . shape($sent) . "\nwhen sent:\n" . shape($baseline))
    );
}
$row = one("SELECT status, errorCode, errorDetail FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1", 's', [mailOf('member')]);
ok(
    'the failed certificate is in the send log as failed, with its reason in plain words',
    $row !== null && $row['status'] === 'failed' && $row['errorCode'] === 'certificate' && str_contains((string) $row['errorDetail'], 'The certificate expired on') === true,
    (string) json_encode($row)
);

// =============================================================================
head('P6. "Send test": how far it got, and why it stopped');
// =============================================================================
useServer('accept');
mailSince('accept');
$answer = $sendTest(mailOf('second'), ip(1));
$shown  = $testShown($answer);
$mail   = mailSince('accept');
ok(
    'against a server that accepts: all four steps are shown as done, with the mail server\'s own last line, and the stand-in holds the message for that address',
    $answer['status'] === 200 && $shown['result'] === 'sent' && $shown['steps'] === 'done,done,done,done' && str_starts_with($shown['last'], 'S: 250') === true
        && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('second')] && str_contains($mail['messages'][0]['text'], 'This is a test message from Selftest Portal.') === true,
    'HTTP ' . $answer['status'] . '; ' . json_encode($shown) . '; messages ' . count($mail['messages'])
);
setting('mail.provider', 'ms365');
mailSince('accept');
$answer = $sendTest(mailOf('second'), ip(1));
$mail   = mailSince('accept');
ok(
    'with Microsoft 365 the route in use (and not set up), a test "through the SMTP mailbox" still goes to the SMTP server: the mailbox can be proved before the portal is switched to it',
    $testShown($answer)['result'] === 'sent' && count($mail['messages']) === 1 && str_starts_with($notice($answer), 'not-ready:') === true && str_contains($notice($answer), 'go through Microsoft 365') === true,
    json_encode($testShown($answer)) . '; messages ' . count($mail['messages']) . '; ' . $notice($answer)
);
setting('mail.provider', 'smtp');
useServer('refuse-signin');
mailSince('refuse-signin');
$answer = $sendTest(mailOf('second'), ip(1));
$shown  = $testShown($answer);
$mail   = mailSince('refuse-signin');
ok(
    'against a server that refuses the sign-in: it stops at "Signed in", says in plain words that the user name and password were refused, keeps the server\'s own words, and no message is handed over',
    $shown['result'] === 'not-sent' && $shown['steps'] === 'done,done,stopped,not-reached' && str_contains($shown['reason'], 'The mail server refused the user name and password.') === true
        && str_contains($shown['reason'], 'The mail server said: 535') === true && count($mail['messages']) === 0,
    (string) json_encode($shown)
);
useServer('stall');
$answer = $sendTest(mailOf('second'), ip(1));
$shown  = $testShown($answer);
ok(
    'against a server that stops answering: it gives up inside its time limit, and says the server did not answer in time and where',
    $shown['result'] === 'not-sent' && $shown['steps'] === 'done,stopped,not-reached,not-reached' && str_contains($shown['reason'], 'It stopped while introducing itself to the mail server.') === true
        && str_contains($shown['reason'], 'did not answer in time') === true && $answer['ms'] < 20000,
    json_encode($shown) . '; took ' . $answer['ms'] . ' ms'
);
setting('mail.smtp.port', (string) closedPort());
$answer = $sendTest(mailOf('second'), ip(1));
$shown  = $testShown($answer);
$page   = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    'against a port where nothing listens: it says the mail server could not be reached; and a failure that is NOT about the certificate raises no certificate notice',
    $shown['result'] === 'not-sent' && str_contains($shown['reason'], 'It stopped while reaching the mail server.') === true && str_contains($shown['steps'], 'done') === false
        && str_contains($page['body'], 'data-mail-warning') === false,
    json_encode($shown) . '; certificate notice ' . (str_contains($page['body'], 'data-mail-warning') === true ? 'SHOWN' : 'not shown')
);
$answer = $post($rootJar, ip(1), ['action' => 'test_send', 'to' => 'not an address', 'through' => 'smtp']);
ok('an address that is not one is refused before anything is tried', $answer['status'] === 200 && str_contains($answer['body'], 'data-mail-test') === false && str_contains(words($answer['body']), 'Type the e-mail address the test message should go to.') === true, 'HTTP ' . $answer['status']);

// =============================================================================
head('P14. The Integrations page\'s own three tests are for a global administrator only');
// =============================================================================
// Admin, Integrations has a "Send Test Email" of its own. It goes through
// whichever route is in use, so through the SMTP mailbox here. Until this was
// checked, any administrator of one organisation could post it.
$integrations = '/admin/integrations';
useServer('accept');
mailSince('accept');
$answer = req($rootJar, 'POST', $integrations, ['csrf_token' => anyToken($rootJar, ip(1)), 'action' => 'send_test_email', 'test_recipient' => mailOf('fourth')], ip(1));
$mail   = mailSince('accept');
ok(
    'a GLOBAL administrator\'s "Send Test Email" on the Integrations page goes out through the SMTP mailbox (which is why that page has to refuse everybody else)',
    $answer['status'] === 200 && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('fourth')],
    'HTTP ' . $answer['status'] . '; messages ' . count($mail['messages'])
);
// Microsoft 365 and Google are NOT filled in while these three are posted, on
// purpose: if the refusal were ever lost, the two tests that would otherwise
// reach Microsoft or Google stop at "settings missing" and nothing leaves
// this machine. The first of the three would reach the stand-in mail server.
$logRows = (int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'];
foreach ([
    'send_test_email'        => 'sending a test message',
    'test_token'             => 'testing the sign-in to Microsoft 365 for sending mail',
    'send_google_test_email' => 'sending a test message through Google',
] as $action => $what) {
    mailSince('accept');
    $answer = req($orgJar, 'POST', $integrations, ['csrf_token' => anyToken($orgJar, ip(2)), 'action' => $action, 'test_recipient' => mailOf('member')], ip(2));
    $landed = req($orgJar, 'GET', $integrations, null, ip(2));
    $mail   = mailSince('accept');
    $noted  = (int) one(
        "SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = 'SettingsGroupSaveRefused' AND activityDescription = ?",
        'is',
        [PEOPLE['orgadmin'], 'Refused: ' . $what . ' on the Integrations page is for a global administrator only']
    )['n'];
    $added  = (int) one('SELECT COUNT(*) AS n FROM tblEmailLog')['n'] - $logRows;
    ok(
        'an administrator of one organisation posts "' . $action . '" to the Integrations page: refused in plain words, no mail server is contacted, nothing is added to the send log, and the refusal is in the activity log under their name',
        $answer['status'] === 302 && $answer['location'] === $integrations && str_contains(words($landed['body']), 'Only a global administrator can run them. Nothing was sent or tested.') === true
            && $mail['connections'] === 0 && $added === 0 && $noted === 1,
        'HTTP ' . $answer['status'] . ' to "' . $answer['location'] . '"; connections ' . $mail['connections'] . '; send log rows added ' . $added . '; refusals logged ' . $noted
    );
}
// The forms. The page shows them only when a route is filled in, so every
// route is filled in here with made-up values (and a made-up key file) for
// two page views. Nothing is posted while they are: a page view contacts nobody.
$madeUp = ['auth.ms365.tenantID' => 'selftest-made-up', 'auth.ms365.appwide.clientID' => 'selftest-made-up', 'auth.ms365.appwide.clientSecret' => 'selftest-made-up',
    'mail.google.serviceAccountKeyFile' => 'selftest-made-up-key.json', 'mail.google.delegateUser' => SENDER];
foreach ($madeUp as $key => $value) {
    setting($key, $value);
}
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'selftest-made-up-key.json', '{}');
$rootSees = req($rootJar, 'GET', $integrations, null, ip(1))['body'];
$orgSees  = req($orgJar, 'GET', $integrations, null, ip(2))['body'];
foreach (array_keys($madeUp) as $key) {
    setting($key, '');
}
unlink($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'selftest-made-up-key.json');
/** How many of the three test forms a page holds. */
$formsOn = static function (string $html): int {
    return (int) (str_contains($html, 'value="send_test_email"') === true) + (int) (str_contains($html, 'value="test_token"') === true) + (int) (str_contains($html, 'value="send_google_test_email"') === true);
};
ok(
    'with every route filled in, a global administrator is shown all three test forms; an administrator of one organisation is shown none, and is told why in each of the three places',
    $formsOn($rootSees) === 3 && substr_count($rootSees, 'data-global-admin-only=') === 0 && $formsOn($orgSees) === 0 && substr_count($orgSees, 'data-global-admin-only=') === 3
        && str_contains(words($orgSees), 'so only a global administrator can send one') === true,
    'forms shown to the global administrator: ' . $formsOn($rootSees) . ' of 3; to the organisation\'s administrator: ' . $formsOn($orgSees) . ', with ' . substr_count($orgSees, 'data-global-admin-only=') . ' explanation(s)'
);

// =============================================================================
head('P11. A bad certificate: the right fault is named, nothing is sent, and the administrator is told until it is put right');
// =============================================================================
foreach ([
    'cert-expired'    => ['The certificate expired on', 'expired'],
    'cert-notyet'     => ['The certificate is not valid until', 'not valid yet'],
    'cert-wrongname'  => ['The certificate is for another name (other.test, *.wild.test), not the server name in the settings (localhost).', 'for another server name'],
    'cert-selfsigned' => ['The certificate is signed by itself, not by a certificate authority.', 'signed by itself'],
    'cert-privateca'  => ['cannot be traced to an authority this server trusts', 'from an authority this server does not trust'],
] as $scenario => [$expected, $what]) {
    useServer($scenario);
    mailSince($scenario);
    $answer = $sendTest(mailOf('second'), ip(1));
    $shown  = $testShown($answer);
    $mail   = mailSince($scenario);
    ok(
        'a certificate that is ' . $what . ': "Send test" stops at the certificate step and names that fault; no sign-in and no message is sent',
        $shown['result'] === 'not-sent' && $shown['steps'] === 'done,stopped,not-reached,not-reached' && str_contains($shown['reason'], $expected) === true
            && str_contains($shown['reason'], 'nothing was sent') === true && $mail['signIns'] === 0 && count($mail['messages']) === 0,
        json_encode($shown) . '; sign-ins ' . $mail['signIns'] . '; messages ' . count($mail['messages'])
    );
}
useServer('cert-unreadable');
$answer = $sendTest(mailOf('second'), ip(1));
$shown  = $testShown($answer);
ok(
    'a refused certificate that could not be read a second time: the page still says something true (refused, nothing sent, could not be read to say why), and names no fault it does not know',
    $shown['result'] === 'not-sent' && str_contains($shown['reason'], 'certificate was refused, so nothing was sent') === true && str_contains($shown['reason'], 'could not be read a second time') === true
        && str_contains($shown['reason'], 'expired on') === false,
    (string) json_encode($shown)
);
useServer('cert-name-rule');
mailSince('cert-name-rule');
$answer = $sendTest(mailOf('second'), ip(1));
$shown  = $testShown($answer);
$mail   = mailSince('cert-name-rule');
ok(
    'a certificate in date and from the trusted authority, whose list of names is for another server: refused for its name, and no sign-in is sent',
    $shown['result'] === 'not-sent' && str_contains($shown['reason'], 'The certificate is for another name (other.test)') === true && $mail['signIns'] === 0,
    json_encode($shown) . '; sign-ins ' . $mail['signIns']
);
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    'after that the e-mail page carries a standing notice: the certificate did not pass, what the check found, and what to do',
    preg_match('#data-mail-warning="certificate">(.*?)</div>#s', $page['body'], $m) === 1 && str_contains(words($m[1]), 'The certificate is for another name (other.test)') === true
        && str_contains(words($m[1]), 'ask whoever runs the mail server') === true,
    isset($m[1]) === true ? words($m[1]) : 'no notice on the page'
);
// Three messages that are stopped BEFORE the mail server is tried. None of
// them says anything about the certificate, which is still failing, so none
// may clear the notice. (Each used to: the notice read the newest line of the
// send log, whatever it was, while saying it stays until a message is accepted.)
mailSince('cert-name-rule');
setting('mail.limit.perHour', '1');
sendDirect([mailOf('third')], 'P11 stopped by the hourly limit', 'A message.');
setting('mail.limit.perHour', '');
setting('mail.smtp.username', '');
sendDirect([mailOf('third')], 'P11 stopped by a missing setting', 'A message.');
setting('mail.smtp.username', MAILBOX_USER);
sendDirect([mailOf('third')], 'P11 stopped by a file that is not there', 'A message.', [$child . $sep . 'no-such-file.pdf']);
$mail  = mailSince('cert-name-rule');
// Read back, so that this check cannot pass because the three were NOT stopped
// (a message that reached this server would fail on its certificate, and the
// notice would be there for that reason).
$codes  = array_column(q("SELECT errorCode FROM tblEmailLog WHERE subject LIKE 'P11 stopped by %' ORDER BY emailLogID"), 'errorCode');
$page   = req($rootJar, 'GET', $emailPage, null, ip(1));
$notice11 = preg_match('#data-mail-warning="certificate">(.*?)</div>#s', $page['body'], $m) === 1 ? words($m[1]) : '';
ok(
    'a message stopped by the hourly limit, one stopped by a missing setting and one whose file cannot be attached do NOT clear the notice: the mail server was never tried, its certificate is still failing, and the notice says what does clear it',
    $codes === ['over-hourly-limit', 'not-set-up', 'message-refused'] && $mail['connections'] === 0 && str_contains($notice11, 'The certificate is for another name (other.test)') === true
        && str_contains($notice11, 'It goes when a later attempt ends another way') === true && str_contains($notice11, 'leaves it showing') === true,
    'the three were logged as ' . json_encode($codes) . '; connections ' . $mail['connections'] . '; notice: ' . ($notice11 === '' ? 'NOT on the page' : $notice11)
);
useServer('accept');
$sendTest(mailOf('second'), ip(1));
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok('once a message is accepted again, the notice is gone', str_contains($page['body'], 'data-mail-warning') === false && str_starts_with($notice($page), 'ready:') === true, $notice($page));

// =============================================================================
head('P12. send() never throws on this route, and says what became of the message');
// =============================================================================
foreach (['mail.smtp.host', 'mail.smtp.username', 'mail.defaultFromAddress'] as $key) {
    setting($key, '');
}
setting('mail.smtp.password', '', 1);
$ran = sendDirect([mailOf('third')], 'P12 nothing filled in', 'A message that cannot go.');
$row = one('SELECT provider, status, errorCode, errorDetail FROM tblEmailLog WHERE subject = ? ORDER BY emailLogID DESC LIMIT 1', 's', ['P12 nothing filled in']);
ok(
    'SMTP chosen and nothing filled in: send() answers false, throws nothing, lastOutcome() says "not-set-up", and the send log has a "not set up" line naming what is needed',
    $ran['sent'] === false && $ran['threw'] === '' && $ran['outcome'] === 'not-set-up' && $ran['canSend'] === false
        && $row !== null && $row['provider'] === 'smtp' && $row['status'] === 'failed' && $row['errorCode'] === 'not-set-up' && str_contains((string) $row['errorDetail'], "the mail server's name") === true,
    json_encode(array_diff_key($ran, ['output' => 1])) . ' / ' . json_encode($row)
);
$errors = (int) one('SELECT COUNT(*) AS n FROM tblErrors')['n'];
$answer = req($rootJar, 'POST', '/calendar/event/broadcast/send', ['csrf_token' => anyToken($rootJar, ip(1)), 'eventID' => (string) EVENT_ID, 'segment' => 'all-rsvps', 'subject' => 'P12 broadcast', 'body' => 'A broadcast that cannot go.'], ip(1));
$notSent = (int) one("SELECT COUNT(*) AS n FROM tblEmailLog WHERE subject = 'P12 broadcast' AND status = 'failed' AND errorCode = 'not-set-up'")['n'];
ok(
    'an event broadcast to two people, with SMTP not set up, ends normally: sent back to the broadcast page, recorded as sent to 0, two "not set up" lines in the send log and no error raised',
    $answer['status'] === 302 && str_contains($answer['location'], '/calendar/event/broadcast?eventID=' . EVENT_ID) === true
        && (int) (one('SELECT recipientCount FROM tblEventBroadcasts WHERE eventID = ? ORDER BY broadcastID DESC LIMIT 1', 'i', [EVENT_ID])['recipientCount'] ?? -1) === 0
        && $notSent === 2 && (int) one('SELECT COUNT(*) AS n FROM tblErrors')['n'] === $errors,
    'HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; "not set up" lines ' . $notSent . '; new error records ' . ((int) one('SELECT COUNT(*) AS n FROM tblErrors')['n'] - $errors)
);
setting('mail.smtp.host', MAIL_HOST);
setting('mail.smtp.username', MAILBOX_USER);
setting('mail.defaultFromAddress', SENDER);
setting('mail.smtp.password', $cipher, 1);
useServer('accept');
mailSince('accept');
$ran  = sendDirect([mailOf('third'), mailOf('fourth')], 'P12 two people and a file', 'A message with a file.', [$attachment], ['priority' => 'bulk', 'repeatSafe' => true]);
$mail = mailSince('accept');
$row  = one('SELECT status, recipientCount, attachmentCount FROM tblEmailLog WHERE subject = ? ORDER BY emailLogID DESC LIMIT 1', 's', ['P12 two people and a file']);
ok(
    'a message for two people with a file and the new fifth argument: accepted, lastOutcome() "sent", ONE message carrying the file to both, logged with a recipient count of 2',
    $ran['sent'] === true && $ran['threw'] === '' && $ran['outcome'] === 'sent' && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('third'), mailOf('fourth')]
        && str_contains($mail['messages'][0]['raw'], 'selftest-attachment.txt') === true && $row !== null && $row['status'] === 'sent' && (int) $row['recipientCount'] === 2 && (int) $row['attachmentCount'] === 1,
    json_encode(array_diff_key($ran, ['output' => 1])) . ' / messages ' . count($mail['messages']) . ' / ' . json_encode($row)
);
mailSince('accept');
$ran  = sendDirect([mailOf('third')], 'P12 a file that is not there', 'A message whose file is missing.', [$child . $sep . 'no-such-file.pdf']);
$mail = mailSince('accept');
$row  = one('SELECT status, errorCode, errorDetail FROM tblEmailLog WHERE subject = ? ORDER BY emailLogID DESC LIMIT 1', 's', ['P12 a file that is not there']);
ok(
    'a file that cannot be attached stops the message (it is not sent without it): false, "refused", nothing reaches the mail server, and the log says why',
    $ran['sent'] === false && $ran['threw'] === '' && $ran['outcome'] === 'refused' && $mail['connections'] === 0 && $row !== null && $row['errorCode'] === 'message-refused' && str_contains((string) $row['errorDetail'], 'An attachment could not be read.') === true,
    json_encode(array_diff_key($ran, ['output' => 1])) . ' / ' . json_encode($row)
);
useServer('refuse-signin');
$ran = sendDirect([mailOf('third')], 'P12 sign-in refused', 'A message the server will not take.');
ok('a refused sign-in: false, nothing thrown, lastOutcome() "refused"', $ran['sent'] === false && $ran['threw'] === '' && $ran['outcome'] === 'refused', (string) json_encode(array_diff_key($ran, ['output' => 1])));
// 🔐 Several messages in ONE request, as a broadcast or a reminder run sends
//    them. Every one used to sign in again and be refused again: 200 reminders
//    with an out-of-date password were 200 refused sign-ins in seconds, which
//    is how a mailbox gets locked.
mailSince('refuse-signin');
$ran  = sendDirect([mailOf('third')], 'P12 five in one request, sign-in refused', 'A message the server will not take.', [], [], [[mailOf('fourth')], [mailOf('second')], [mailOf('third')], [mailOf('fourth')]]);
$mail = mailSince('refuse-signin');
$rows = q('SELECT errorCode, httpCode, errorDetail FROM tblEmailLog WHERE subject = ? ORDER BY emailLogID', 's', ['P12 five in one request, sign-in refused']);
ok(
    'five messages in ONE request to a server that refuses the sign-in: the portal signs in ONCE; the other four are answered "refused" without contacting the server, and each is in the send log as not sent for that reason, in plain words',
    $ran['threw'] === '' && $ran['each'] === array_fill(0, 5, [false, 'refused']) && $mail['signIns'] === 1 && $mail['connections'] === 1
        && array_column($rows, 'errorCode') === ['server-said-no', 'sign-in-not-repeated', 'sign-in-not-repeated', 'sign-in-not-repeated', 'sign-in-not-repeated']
        && (int) ($rows[0]['httpCode'] ?? 0) === 535 && ($rows[4]['httpCode'] ?? null) === null
        && str_starts_with((string) ($rows[4]['errorDetail'] ?? ''), 'Not sent, and the mail server was not contacted. It refused the mailbox\'s user name and password for an earlier message') === true,
    'each send: ' . json_encode($ran['each']) . '; sign-ins the server saw: ' . $mail['signIns'] . '; connections: ' . $mail['connections'] . '; send log: ' . json_encode(array_column($rows, 'errorCode'))
);
// ONLY a refused sign-in is remembered. A refused recipient is about that one
// message: if it were remembered too, one bad address in a broadcast would
// stop the mail of everybody after it.
useServer('refuse-recipient');
mailSince('refuse-recipient');
$ran  = sendDirect(['nobody@mailpages.selftest.invalid'], 'P12 a refused recipient, then somebody else', 'A message.', [], [], [[mailOf('third')]]);
$mail = mailSince('refuse-recipient');
ok(
    'a refused RECIPIENT is not remembered that way: in the same request, the next message (for somebody else) is sent',
    $ran['threw'] === '' && $ran['each'] === [[false, 'refused'], [true, 'sent']] && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('third')],
    'each send: ' . json_encode($ran['each']) . '; messages the server took: ' . count($mail['messages'])
);
useServer('stall');
$ran = sendDirect([mailOf('third')], 'P12 server stalls', 'A message the server never answers.');
ok('a server that stops answering: false, nothing thrown, lastOutcome() "not-sent"', $ran['sent'] === false && $ran['threw'] === '' && $ran['outcome'] === 'not-sent', (string) json_encode(array_diff_key($ran, ['output' => 1])));
// 📭 The server takes the WHOLE message and then hangs up without saying
//    whether it accepted it. Nobody can tell whether it went, and that must
//    never be recorded as "sent": the person would be told a reset link or a
//    statement had gone when it may not have.
useServer('drop-after');
mailSince('drop-after');
$ran  = sendDirect([mailOf('third')], 'P12 nobody can tell', 'A message the server takes and never answers for.');
$mail = mailSince('drop-after');
$row  = one('SELECT status, errorCode, errorDetail FROM tblEmailLog WHERE subject = ? ORDER BY emailLogID DESC LIMIT 1', 's', ['P12 nobody can tell']);
ok(
    'a server that takes the whole message and hangs up without an answer: NOT counted as sent. send() answers false, lastOutcome() says "not-sent", and the send log says failed and that nobody can tell whether it went',
    $ran['sent'] === false && $ran['threw'] === '' && $ran['outcome'] === 'not-sent' && $mail['connections'] === 1 && $row !== null && $row['status'] === 'failed' && $row['errorCode'] === 'unknown-if-sent'
        && str_starts_with((string) $row['errorDetail'], 'Nobody can tell whether this message went') === true,
    json_encode(array_diff_key($ran, ['output' => 1, 'each' => 1])) . '; connections ' . $mail['connections'] . ' / ' . json_encode($row)
);

// =============================================================================
head('P13. One organisation\'s own settings cannot move the mail, and a password not marked as a secret is not used');
// =============================================================================
useServer('accept');
// Organisation 1's OWN rows, naming the second server. The portal's start-up
// lays an organisation's rows over the installation's, so $SETTINGS now names it.
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'mail.smtp.host', ?, '', 0), (1, 'mail.smtp.port', ?, '', 0), (1, 'mail.smtp.username', ?, '', 0)", 'sss', [MAIL_HOST, (string) $state['ports']['thief'], MAILBOX_USER]);
mailSince('accept');
mailSince('thief');
askForLink(ip(31), mailOf('member'));
$own   = mailSince('accept');
$thief = mailSince('thief');
ok(
    'with a mail server named in ONE organisation\'s own settings: the message still goes to the installation\'s server, and the other server is never contacted',
    count($own['messages']) === 1 && $thief['connections'] === 0,
    'installation\'s server: ' . count($own['messages']) . ' message(s); the other: ' . $thief['connections'] . ' connection(s)'
);
q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey IN ('mail.smtp.host', 'mail.smtp.port', 'mail.smtp.username')");
// 🧭 The same for the CHOICE of route. Mailer used to read it from $SETTINGS,
//    where this row lies over the installation's: the e-mail page then said
//    "Microsoft 365, not ready", and "Use this route" said it had changed the
//    route while the page went on showing the old one.
q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'mail.provider', 'ms365', '', 0)");
mailSince('accept');
askForLink(ip(34), mailOf('member'));
$mail = mailSince('accept');
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
$seen = $monitor();
ok(
    'with another route named in ONE organisation\'s own settings: the e-mail page, the health monitor and a real send all still follow the installation\'s choice, the SMTP mailbox',
    str_contains($page['body'], 'data-mail-route="smtp"') === true && str_starts_with($notice($page), 'ready: E-mail is set to go through an SMTP mailbox') === true
        && $seen[0] === 'ok' && $seen[1] === 'Set up (an SMTP mailbox)' && count($mail['messages']) === 1,
    'page: ' . $notice($page) . '; monitor: ' . json_encode($seen) . '; messages the mail server took: ' . count($mail['messages'])
);
$answer = $post($rootJar, ip(1), ['action' => 'set_route', 'route' => 'google']);
$page   = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    '"Use this route" then really changes the route: the installation\'s row is written, the page shows the new route, and the organisation\'s row is left as it was',
    $answer['status'] === 302 && stored('mail.provider') === ['google', 0] && str_contains($page['body'], 'data-mail-route="google"') === true && str_contains($notice($page), 'go through Google') === true
        && (string) (one("SELECT settingValue FROM tblSettings WHERE siteID = 1 AND settingKey = 'mail.provider'")['settingValue'] ?? '') === 'ms365',
    'HTTP ' . $answer['status'] . '; mail.provider ' . json_encode(stored('mail.provider')) . '; page: ' . $notice($page)
);
setting('mail.provider', 'smtp');
q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey = 'mail.provider'");
foreach ([
    'the password in plain text, as the general Settings page would store it' => $mailPassword,
    'a correctly ENCRYPTED password whose row has lost its mark'               => $cipher,
] as $what => $value) {
    setting('mail.smtp.password', $value, 0);
    $seen = $monitor();
    mailSince('accept');
    askForLink(ip($what === 'the password in plain text, as the general Settings page would store it' ? 32 : 33), mailOf('member'));
    $mail = mailSince('accept');
    ok(
        'a password row NOT marked as a secret, holding ' . $what . ': not set up, said as "not saved by the e-mail page", and nothing is sent',
        $seen[0] === 'warn' && str_contains($seen[2], 'was not saved by the e-mail page, so it is not used') === true && $mail['connections'] === 0,
        json_encode($seen) . '; connections ' . $mail['connections']
    );
}
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok('the e-mail page then asks for the password again, and still does not show the stored text', str_contains($page['body'], 'data-password-state="not-secret"') === true && str_contains($page['body'], htmlspecialchars($cipher, ENT_QUOTES, 'UTF-8')) === false, '');
setting('mail.smtp.password', $cipher, 1);

// =============================================================================
head('P10. The hourly limit: counted in UTC, on the nights the clocks change, and enforced on the pages');
// =============================================================================
// The portal's own two functions, on a connection of their own whose clock
// this part sets (SET TIMESTAMP changes what NOW() and UTC_TIMESTAMP() say on
// that connection only). The connection and PHP are both put in the zone
// whose clocks are changing, so anything read on either of those clocks
// would show. Mailer::logSend() finds the connection as the global $mysqli.
foreach (['App', 'Site', 'MailRoutes', 'Mailer'] as $class) {
    require_once $webSrc . $sep . '_core' . $sep . $class . '.php';
}
$mysqli   = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $dbName, $creds['db_port']);
// No pruning of old log rows while the clock is set to another day.
$SETTINGS = ['mail' => ['log' => ['retentionDays' => 0]]];
/** Set one thing on that connection: its time zone, or the moment its clock says it is. */
$setClock = static function (string $sql, string $type, string|int $value) use ($mysqli): void {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($type, $value);
    $stmt->execute();
    $stmt->close();
};
$phpZone = date_default_timezone_get();
foreach (['Europe/London', 'America/New_York'] as $changing) {
    try {
        $setClock('SET time_zone = ?', 's', $changing);
    } catch (Throwable $e) {
        refuse('the database server does not know the time zone ' . $changing . ' (its time zone tables are not loaded), so the clock-change nights cannot be run');
    }
    date_default_timezone_set($changing);
    $year    = (int) gmdate('Y');
    $changes = array_slice((new DateTimeZone($changing))->getTransitions(gmmktime(0, 0, 0, 1, 1, $year), gmmktime(0, 0, 0, 12, 31, $year)), 1);
    if (count($changes) !== 2) {
        refuse('expected two clock changes in ' . $changing . ' in ' . $year . ' and found ' . count($changes));
    }
    foreach ($changes as $change) {
        $at = (int) $change['ts'];
        q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%mailpages.selftest.invalid%'");
        // Three messages: 50 and 10 minutes before the change, and 20 minutes
        // after it. On the night the clocks go back in London those are 01:10
        // and 01:50 by the clock, and then 01:20: 70 minutes from first to last.
        $sentAt = [$at - 50 * 60, $at - 10 * 60, $at + 20 * 60];
        // Looked at from these moments. Each is counted here by plain arithmetic on seconds.
        $looks = [$at - 1, $at + 9 * 60, $at + 11 * 60, $at + 20 * 60 + 1, $at + 49 * 60, $at + 81 * 60];
        // What is already in the log around each moment (another test's rows): counted first and allowed for.
        $other = [];
        foreach ($looks as $look) {
            $setClock('SET TIMESTAMP = ?', 'i', $look);
            $other[$look] = \Portal\Core\MailRoutes::recipientsInTheLastHour($mysqli);
        }
        // Then the night is lived through in order, the clock only ever moving
        // forward: a message is logged when its moment comes, and each look
        // sees only what has been sent by then.
        $timeline = [];
        foreach ($sentAt as $n => $moment) {
            $timeline[] = [$moment, 'send', $n];
        }
        foreach ($looks as $look) {
            $timeline[] = [$look, 'look', 0];
        }
        sort($timeline);
        $wrong = [];
        foreach ($timeline as [$moment, $what, $n]) {
            $setClock('SET TIMESTAMP = ?', 'i', $moment);
            if ($what === 'send') {
                \Portal\Core\Mailer::logSend('smtp', SENDER, 'night' . $n . '@mailpages.selftest.invalid', 'P10 clock change', 0, 'sent', 250, '', '');
                continue;
            }
            $expected = $other[$moment] + count(array_filter($sentAt, static fn (int $sent): bool => $sent > $moment - 3600 && $sent <= $moment));
            $got      = \Portal\Core\MailRoutes::recipientsInTheLastHour($mysqli);
            if ($got !== $expected) {
                $wrong[] = 'looked at ' . (int) round(($moment - $at) / 60) . ' min from the change: counted ' . $got . ', expected ' . $expected;
            }
        }
        // Seconds since 1970 worked out from the stored text alone, with no time zone involved.
        $stored = array_map('intval', array_column(q("SELECT TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', sentAtUtc) AS t FROM tblEmailLog WHERE subject = 'P10 clock change' ORDER BY emailLogID"), 't'));
        ok(
            $changing . ', the night the clocks go ' . ($change['isdst'] === true ? 'FORWARD' : 'BACK') . ' (' . gmdate('j F Y H:i', $at) . ' UTC): three messages 70 minutes apart are each stored at their moment in UTC and counted in the hour they fall in, looked at from six moments',
            $wrong === [] && $stored === $sentAt,
            implode('; ', $wrong) . ($stored === $sentAt ? '' : ' stored moments ' . json_encode($stored) . ', expected ' . json_encode($sentAt))
        );
    }
}
date_default_timezone_set($phpZone);
$mysqli->close();
q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%mailpages.selftest.invalid%'");

// On the pages, with the real clock.
useServer('accept');
setting('mail.limit.perHour', '2');
mailSince('accept');
askForLink(ip(41), mailOf('member'));
askForLink(ip(42), mailOf('second'));
$third = askForLink(ip(43), mailOf('third'));
$mail  = mailSince('accept');
$row   = one('SELECT provider, status, errorCode, errorDetail, recipientCount FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1', 's', [mailOf('third')]);
ok(
    'with a limit of 2 recipients an hour, the third reset e-mail is NOT sent: the mail server gets two messages, the person is told the same as always, and the send log says it was over the limit, with the numbers',
    count($mail['messages']) === 2 && shape($third) === shape($baseline) && $row !== null && $row['provider'] === 'smtp' && $row['status'] === 'failed' && $row['errorCode'] === 'over-hourly-limit'
        && str_contains((string) $row['errorDetail'], 'the limit is 2 recipients an hour, 2 were sent to in the last hour, and this message is for 1') === true && records(ip(43), 'PasswordResetMailFailed') === 1,
    'messages ' . count($mail['messages']) . ' / ' . json_encode($row)
);
$page = req($rootJar, 'GET', $emailPage, null, ip(1));
ok(
    '"Recent sends" lists it as not sent, with that reason',
    preg_match('#data-mail-log-status="failed">(.*?)</div>\s*</div>#s', $page['body'], $m) === 1 && str_contains(words($m[1]), 'Not sent') === true && str_contains(words($m[1]), 'the limit is 2 recipients an hour') === true,
    isset($m[1]) === true ? words($m[1]) : 'no "not sent" row found'
);
mailSince('accept');
$answer = $sendTest(mailOf('fourth'), ip(1));
$mail   = mailSince('accept');
ok('"Send test" is not stopped by the limit (an administrator must be able to find out why mail is not going), and its message is counted', $testShown($answer)['result'] === 'sent' && count($mail['messages']) === 1, (string) json_encode($testShown($answer)));
setting('mail.limit.perHour', '5');
mailSince('accept');
$three = sendDirect([mailOf('third'), mailOf('fourth'), mailOf('second')], 'P10 three when two are left', 'A message for three people.');
$none  = mailSince('accept');
$two   = sendDirect([mailOf('third'), mailOf('fourth')], 'P10 two when two are left', 'A message for two people.');
$one   = sendDirect([mailOf('second')], 'P10 one when none is left', 'A message for one person.');
$mail  = mailSince('accept');
ok(
    'the limit adds up RECIPIENTS, not messages: with 3 sent to in the hour and a limit of 5, a message for three people is not sent (and none of the three gets it), one for two people is, and then one for one person is not',
    $three['sent'] === false && $three['threw'] === '' && $three['outcome'] === 'not-sent' && $none['connections'] === 0
        && $two['sent'] === true && $one['sent'] === false && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('third'), mailOf('fourth')],
    'for three: ' . json_encode($three['sent']) . ' (connections ' . $none['connections'] . '); for two: ' . json_encode($two['sent']) . '; for one: ' . json_encode($one['sent']) . '; messages ' . count($mail['messages'])
);
// The limit is the portal's, not the SMTP route's. Neither of the other two
// routes is filled in here, so a message that got past the limit would fail
// in its own way (Microsoft 365 by throwing) and contact nobody.
foreach (['ms365' => 'Microsoft 365', 'google' => 'Google'] as $other => $name) {
    setting('mail.provider', $other);
    $ran = sendDirect([mailOf('second')], 'P10 over the limit, through ' . $other, 'A message for one person.');
    $row = one('SELECT provider, status, errorCode FROM tblEmailLog WHERE subject = ? ORDER BY emailLogID DESC LIMIT 1', 's', ['P10 over the limit, through ' . $other]);
    ok(
        'the limit covers every route, not only SMTP: with ' . $name . ' the route in use and the limit reached, the message is not handed to ' . $name . ', nothing is thrown, and the send log says the limit stopped it',
        $ran['sent'] === false && $ran['threw'] === '' && $row !== null && $row['provider'] === $other && $row['status'] === 'failed' && $row['errorCode'] === 'over-hourly-limit',
        json_encode(array_diff_key($ran, ['output' => 1, 'each' => 1])) . ' / ' . json_encode($row)
    );
}
setting('mail.provider', 'smtp');
setting('mail.limit.perHour', 'fifty');
mailSince('accept');
$ran  = sendDirect([mailOf('third')], 'P10 a limit that is not a number', 'A message.');
$mail = mailSince('accept');
ok(
    'a stored limit that is not a number is not read as "no limit": the portal says it cannot send, and a message given to send() is not sent',
    $ran['canSend'] === false && $ran['sent'] === false && $ran['threw'] === '' && $mail['connections'] === 0,
    json_encode(array_diff_key($ran, ['output' => 1])) . '; connections ' . $mail['connections']
);
setting('mail.limit.perHour', '');
mailSince('accept');
$ran  = sendDirect([mailOf('third')], 'P10 no limit', 'A message.');
$mail = mailSince('accept');
ok('with the limit emptied again there is none: the message goes', $ran['sent'] === true && count($mail['messages']) === 1, (string) json_encode(array_diff_key($ran, ['output' => 1])));

// =============================================================================
head('P8. The dashboard and the help pages say what is true');
// =============================================================================
// The checklist is shown while the organisation still has its first name and no announcement is pinned.
setting('site.name', 'WebMS Intra');
setting('portal.first_run.dismissed', '0');
setting('portal.first_run.steps.email_delivery', '0');
$step = 'Set up e-mail (Microsoft 365, Google or an SMTP mailbox)';
$page = req($rootJar, 'GET', '/', null, ip(1));
ok(
    'while the portal can send, the dashboard\'s checklist shows the e-mail step as done',
    $page['status'] === 200 && str_contains($page['body'], 'portal-first-run') === true && str_contains($page['body'], '<s class="text-muted">' . $step . '</s>') === true,
    'HTTP ' . $page['status'] . '; checklist ' . (str_contains($page['body'], 'portal-first-run') === true ? 'shown' : 'NOT shown')
);
setting('mail.smtp.password', '', 1);
setting('portal.first_run.steps.email_delivery', '1');
$page = req($rootJar, 'GET', '/', null, ip(1));
ok(
    'with the password gone the step is "to do" again and links to the e-mail page, even with the old stored "done" mark set',
    str_contains($page['body'], '<a href="/admin/integrations/email">' . $step . '</a>') === true && str_contains($page['body'], '<s class="text-muted">' . $step . '</s>') === false,
    ''
);
setting('mail.smtp.password', $cipher, 1);
$page = req($rootJar, 'GET', '/help/admin-first-steps', null, ip(1));
$text = words($page['body']);
ok(
    'the first-steps help names the three routes, says where SMTP is set up and that mail is not kept to try again, and no longer calls the Microsoft 365 route "implementation pending"',
    $page['status'] === 200 && str_contains($text, 'An SMTP mailbox') === true && str_contains($text, 'not kept and not tried again') === true && str_contains($text, 'the mail server’s certificate is always checked') === true
        && str_contains($text, '#234') === false,
    'HTTP ' . $page['status']
);
$page = req($rootJar, 'GET', '/help/admin', null, ip(1));
$text = words($page['body']);
ok(
    'the administrator\'s guide has a section on the SMTP mailbox: the password is typed only on the e-mail page, the certificate is always checked, and mail is not kept to try again',
    $page['status'] === 200 && str_contains($page['body'], 'id="smtp-mailbox"') === true && str_contains($text, 'Type it only on that page.') === true
        && str_contains($text, 'certificate is always checked, and this cannot be switched off') === true && str_contains($text, 'It does not keep mail to try again.') === true,
    'HTTP ' . $page['status']
);

// =============================================================================
head('Last. The password is nowhere, nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
// Everything the portal showed, printed, logged or stored in a form a person can read.
$searched = $state['haystack'] . "\n" . $log . "\n" . (string) @file_get_contents($child . $sep . 'server-output.log') . "\n" . (string) @file_get_contents($standinDir . $sep . 'standin.err');
$searched .= "\n" . json_encode(q("SELECT * FROM tblEmailLog WHERE toRecipients LIKE '%mailpages.selftest.invalid%'"));
$searched .= "\n" . json_encode(q("SELECT activityType, activityDescription, requestHeaders, sessionDataSnapshot FROM tblActivityLogs WHERE userID BETWEEN 929000 AND 929999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]));
$searched .= "\n" . json_encode(q('SELECT * FROM tblErrors ORDER BY 1 DESC LIMIT 200'));
$searched .= "\n" . json_encode(q("SELECT settingKey, settingValue FROM tblSettings WHERE settingKey <> 'mail.smtp.password'"));
foreach ((array) glob($child . $sep . 'sessions' . $sep . '*') as $sessionFile) {
    $searched .= "\n" . (string) @file_get_contents((string) $sessionFile);
}
$found = passwordIn($searched, $mailPassword);
ok(
    'the mailbox\'s password is in nothing the portal showed, printed, logged, kept in a session or stored outside its own encrypted row (' . number_format(strlen($searched)) . ' bytes searched, which do hold the user name and the replaced sign-in lines)',
    $found === '' && strlen($searched) > 200000 && str_contains($searched, MAILBOX_USER) === true && substr_count($searched, '[removed]') >= 5,
    $found . ' (' . strlen($searched) . ' bytes; user name ' . (str_contains($searched, MAILBOX_USER) === true ? 'found' : 'NOT found') . '; replaced sign-in lines ' . substr_count($searched, '[removed]') . ')'
);
$bad = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4))));
$standinSaid = trim((string) @file_get_contents($standinDir . $sep . 'standin.err'));
ok('the stand-in mail servers reported no fault of their own', $standinSaid === '', $standinSaid);

$before  = $state['settings'];
$mailPid = (int) proc_get_status($state['mailProc'])['pid'];
cleanup();
$left = (int) one(
    "SELECT (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 929000 AND 929999) + (SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN 929000 AND 929999) "
    . "+ (SELECT COUNT(*) FROM tblActivityLogs WHERE visitorIP LIKE '198.51.100.%' OR userID BETWEEN 929000 AND 929999) + (SELECT COUNT(*) FROM tblPasswordResets WHERE userID BETWEEN 929000 AND 929999) "
    . "+ (SELECT COUNT(*) FROM tblEmailLog WHERE toRecipients LIKE '%mailpages.selftest.invalid%') + (SELECT COUNT(*) FROM tblEvents WHERE eventID = " . EVENT_ID . ") "
    . "+ (SELECT COUNT(*) FROM tblSettings WHERE siteID = 1 AND (settingKey LIKE 'mail.smtp.%' OR settingKey = 'mail.provider')) AS n"
)['n'];
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up accounts and their records are gone, and every setting is as it was', $left === 0 && $settingsBack === true, 'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'no'));
$stillListening = 0;
foreach ($state['ports'] as $onePort) {
    $stillListening += portAnswers((int) $onePort) === true ? 1 : 0;
}
ok('clean-up: the portal server and the stand-in mail servers (process ' . $mailPid . ') are stopped, nothing is left listening, and the scratch folder is deleted', portAnswers($port) === false && $stillListening === 0 && file_exists($child) === false, 'stand-in ports still answering: ' . $stillListening);

echo "\n{$pass} passed, {$fail} failed, " . ($pass + $fail) . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if (($pass + $fail) !== EXPECTED_CHECKS) {
    echo "FAIL — the number of checks that ran is not the number this file expects. A block was skipped or ran twice,\n";
    echo "or a check was added without updating the number at the top.\n";
    exit(1);
}
if ($fail > 0) {
    echo "FAIL — {$fail} check(s) failed. Read the FAIL lines above before releasing.\n";
    exit(1);
}
echo "PASS — all {$pass} checks passed.\n";
echo "REACHED THE END — the mail pages self-test ran every part.\n";
exit(0);
