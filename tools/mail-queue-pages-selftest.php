<?php
// Path: tools/mail-queue-pages-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The mail queue's page, and the ways past a failed certificate — self-test
 * 📬🪪 (#592, part 4)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * A global administrator can send mail past a failed certificate check. A
 * mistake there hands the mailbox's password, and every password-reset link,
 * to whoever is on the network path. So this file runs the REAL pages against
 * stand-in mail servers and looks at what the mail servers were actually
 * given, not at what the pages say they did.
 *
 * WHAT IT DOES
 * ------------
 * The same way as `tools/mail-queue-selftest.php`: it copies `web/` into a
 * NEW scratch folder with a throwaway connection file and encryption key,
 * starts PHP's built-in web server on it behind a small router that imitates
 * `.htaccess`, and starts the mail library's own STAND-IN mail servers
 * (`tools/maillib/tests/smtp-standin.php`), which record what they are sent
 * and send nothing anywhere. ONE FILE OF THE PORTAL IS CHANGED IN THE COPY,
 * in two places: the two addresses the Microsoft 365 route calls are pointed
 * at a stand-in on 127.0.0.1 (part R1), the way
 * `tools/sign-in-limits-selftest.php` does it. Nothing else in the copy is
 * changed.
 * Messages are put in the queue the way the portal puts them there: through
 * "Forgot password" (the real page) and through Mailer::send(), called by
 * this same file run again as a child process on the scratch copy.
 *
 * ONE STAND-IN CHANGES ITS CERTIFICATE WHEN THIS TEST SAYS SO ("switch"; the
 * stand-in's `certNamedIn`). That is how "the certificate is put right",
 * "the server shows a different one between the page and the button" and
 * "the accepted certificate comes back" are each made to happen at an exact
 * moment, without counting connections.
 *
 * The parts. C1 to C9 are the plan's own; the rest are what was decided after
 * the plan was written, and what the package's independent check found
 * (S1, W1, G1, O1 and several checks inside the others):
 *
 *   C1  An expired certificate: nothing reaches the mail server past the
 *       handshake; the message is held; the dashboard, the health page and
 *       the e-mail page say why.
 *   C7  A held reset is listed first with how long its link has left; sent
 *       in time it arrives; left alone it expires unsent; the person's
 *       screen is the same either way.
 *   C3  "Send this one": that message arrives and the others stay held; the
 *       activity log names who and when; a certificate swapped between the
 *       page and the button refuses the send; it does not work on a message
 *       held for another reason.
 *   C5  An organisation's administrator, a member, a stranger, and a POST
 *       with no form token: every one of the seven buttons refused, nothing
 *       changed.
 *   C2  The certificate put right: the next run of the job sends the held
 *       messages with nobody pressing anything.
 *   N1  "Accept only this certificate": mail goes while the server shows it;
 *       a different certificate holds mail again and says why; the accepted
 *       one coming back lets it go.
 *   N2  Checking switches itself back on when the certificate is good again,
 *       from "one certificate" and from "off", with its line in the activity
 *       log; a server that cannot be reached changes nothing.
 *   C4  "Switch off": refused without the typed server name; afterwards held
 *       mail goes, the standing warning is on all three pages for another
 *       administrator too (the health page and an organisation's
 *       administrator see the date and not the name), and switching back on
 *       removes it.
 *   C6  With checking off, a server with no encryption is still refused, and
 *       the password never reaches it.
 *   C9  The general Settings page refuses the certificate setting, for
 *       everybody (change, add, delete; no Edit or Delete control is drawn).
 *       A value put in the database by hand still shows the standing warning.
 *   S1  With checking off, the mail server's name cannot be changed on the
 *       general Settings page: nothing changes and the stored password is
 *       offered to no other server. On the e-mail page the same change needs
 *       the password typed again and puts checking back on.
 *   W1  Who set it, and when: the stored moment is shown once the log's line
 *       has gone, and nobody is named for a change they did not make.
 *   G1  "Send this one" pressed after the certificate was put right: the
 *       good certificate is not recorded as a fault, and mail goes.
 *   O1  An organisation's own row of the certificate setting is never read.
 *   R1  A fault found while TESTING the SMTP mailbox, with Microsoft 365 as
 *       the route in use (a refused sign-in, then a failed certificate):
 *       nothing is stopped or held, the health line is not critical, the
 *       job sends by Microsoft 365, and the e-mail page shows the
 *       certificate so it can be decided about before switching. Then the
 *       switch to SMTP (the first message finds the certificate again and is
 *       held), and the same refused sign-in with SMTP in use (it applies).
 *   N3  A held message of each other reason is sent again, with the words
 *       about a possible second copy; a message is deleted; one that is too
 *       old is not sent, whoever asks.
 *   N4  "Send waiting mail now" sends, and does not make the scheduled job
 *       look as if it runs.
 *   N5  The mail server's name, or its port, changed without the password
 *       typed again is refused, and the stored password never reaches the
 *       new server; a changed name or port puts certificate checking back
 *       on, from "off" and from "one certificate accepted".
 *   N6  What an organisation's administrator sees: their own organisation's
 *       messages, no button, none of the mail server's own words, no
 *       mailbox user name. "Recent sends" labels each kind of line.
 *   N7  Microsoft 365 and Google get no way past a failed check. With one
 *       of them in use, "off" that is still stored for the SMTP mailbox
 *       still shows its warning, still lapses by itself, and can still be
 *       switched back on.
 *   N8  The page itself: one top-level heading, no <table>, "Nothing is
 *       waiting." for an empty queue, and every moment on it in ONE zone,
 *       the organisation's (its own row, the one place it is kept since
 *       #604; an old "site.timezone" setting put back by hand is not read),
 *       with the zone named.
 *   C8  Nothing any page printed, and nothing logged or stored, holds a
 *       message body, a reset link or the mailbox's password, after a run
 *       that used all three ways through.
 *
 * SAFETY
 * ------
 * - It writes rows AND RUNS THE MAIL JOB, which works through the whole
 *   queue, so it REFUSES unless the database's name starts with `selftest_`
 *   and the queue holds no row that is not this test's own.
 * - Every account it makes is numbered 932000 to 932999; every record it
 *   causes carries a 198.51.100.x address, one of those numbers, or an
 *   address at `queuepages.selftest.invalid`. They are removed at the START
 *   (in case an earlier run was interrupted) and at the END.
 * - It changes installation-wide settings for the run and puts each back
 *   exactly. Do not run it at the same moment as another test on the same
 *   database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder), deleted at the end only if it still holds this run's
 *   marker. `web/_auth_keys` is never read or copied.
 * - Servers are started with their commands as lists (no shell) and each is
 *   stopped by its own process number, never by a name. Every answer must
 *   carry this run's header.
 * - NO E-MAIL LEAVES THIS MACHINE: the only mail servers are the stand-ins,
 *   on 127.0.0.1, and so is the stand-in for Microsoft 365. The run stops
 *   if the copy of Mailer.php would call any other address.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real mail server accepts a message, or that one arrives.
 * - That a real impostor is refused: the "impostor" here is the same stand-in
 *   showing a different certificate. The refusal is by fingerprint, which is
 *   the same test either way.
 * - How anything looks, or fits a phone: that needs a browser. This test
 *   reads the page's HTML only.
 * - Apache, a real proxy or https; MariaDB (run on MySQL 8.0.36 and 8.4.11).
 * - That time passing on a real clock behaves as rows made older do (see
 *   `tools/mail-queue-selftest.php`, which makes the same choice).
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/mail-queue-pages-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9800), SELFTEST_MAIL_PORT (the
 *         stand-in for Microsoft 365; default the web port + 1). SELFTEST_ONLY=C3,N1 runs
 *         only those parts, for looking at one thing: such a run never exits
 *         0. The database must already hold `web/_sql/full_schema.sql`.
 *         Needs PHP's curl, openssl and sodium extensions and `proc_open()`.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 2 for a run of some parts only that passed;
 *         1 otherwise, including every refusal to run.
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
const EXPECTED_CHECKS = 118;

/** What a child process prints before its findings, so they can be found in whatever else it printed. */
const RESULT_MARK = '@@mail-queue-pages-selftest@@';

// =============================================================================
// 🧒 THE CHILD PROCESS
// =============================================================================
// This same file, run as `php mail-queue-pages-selftest.php --child <scratch
// web folder>`, to call the portal's own code directly. It runs at the TOP
// LEVEL, not inside a function, because the portal's start-up file sets
// $mysqli and $SETTINGS as globals. What to do arrives in SELFTEST_JOB.
if (($argv[1] ?? '') === '--child') {
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/';
    require (string) ($argv[2] ?? '') . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $job = (array) json_decode((string) getenv('SELFTEST_JOB'), true);
    $db  = \Portal\Core\App::db();
    $out = ['threw' => ''];
    try {
        switch ((string) $job['task']) {
            case 'send': // messages through Mailer::send(), as a page sends them, in ONE process
                foreach ((array) $job['messages'] as $m) {
                    $sent          = \Portal\Core\Mailer::send($m['to'], (string) $m['subject'], (string) $m['body'], [], (array) ($m['options'] ?? []));
                    $out['each'][] = [$sent, \Portal\Core\Mailer::lastOutcome(), \Portal\Core\Mailer::lastQueueId()];
                }
                break;
            case 'add': // rows straight into the queue, as MailQueue keeps them
                foreach ((array) $job['messages'] as $m) {
                    $out['ids'][] = \Portal\Core\MailQueue::add($db, $m);
                }
                break;
            case 'facts': // what only the portal's own code can say
                $out['jobIsRunning'] = \Portal\Core\MailQueue::jobIsRunning($db);
                $out['check']        = \Portal\Core\MailRoutes::certificateCheck();
                break;
            default:
                $out['threw'] = 'the child was given a task it does not know';
        }
    } catch (Throwable $thrown) {
        $out['threw'] = get_class($thrown) . ': ' . $thrown->getMessage();
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
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows and runs the mail job");
}
foreach (['curl_init', 'proc_open', 'openssl_x509_fingerprint', 'sodium_crypto_secretbox'] as $needed) {
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
$port    = ($portRaw === false || $portRaw === '') ? 9800 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
// 🎭 A second port, for the stand-in that answers as Microsoft 365 (part R1).
$graphPortRaw = getenv('SELFTEST_MAIL_PORT');
$graphPort    = ($graphPortRaw === false || $graphPortRaw === '') ? $port + 1 : (int) $graphPortRaw;
if ($graphPort < 1024 || $graphPort > 65535 || $graphPort === $port) {
    refuse('SELFTEST_MAIL_PORT must be between 1024 and 65535, and not the same as SELFTEST_WEB_PORT');
}
/** The parts to run; empty means all of them. */
$only = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) getenv('SELFTEST_ONLY'))))));

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
// This test's own connection works in UTC, so that a moment it reads back is
// the moment as stored.
$db->query("SET time_zone = '+00:00'");

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

/** Start a part. False when SELFTEST_ONLY leaves it out. */
function part(string $code, string $text): bool
{
    global $only;
    if ($only !== [] && in_array($code, $only, true) === false) {
        return false;
    }
    echo "\n=== " . $code . '. ' . $text . " ===\n";
    startClean();

    return true;
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
    'root'     => 932001, // a global administrator: the one who presses the buttons
    'second'   => 932002, // ANOTHER global administrator, who must see every standing warning without having set it
    'orgadmin' => 932003, // an administrator of organisation 1 only
    'member'   => 932011, // an ordinary member, who asks for reset links
    'other'    => 932012, // another member
    'third'    => 932013,
];
/** How this test names itself to the portal, so the records it causes can be found and removed. */
const AGENT = 'mail-queue-pages-selftest';
const DOMAIN = 'queuepages.selftest.invalid';
const SENDER = 'office@' . DOMAIN;
const MAILBOX_USER = 'selftest-pages-mailbox';
/** The mail server's name. The stand-in's certificates are made for this name, and for 127.0.0.1. */
const MAIL_HOST = 'localhost';
/** The organisation's time zone for the run: one that is never UTC, so a time shown in UTC by mistake is seen. */
const ORG_ZONE = 'America/New_York';
const EMAIL_PAGE = '/admin/integrations/email';
/** The seven actions of the queue's part of the e-mail page, each with fields that would do something if it were let through. */
const SEVEN = ['queue_run', 'queue_send_again', 'queue_send_past_check', 'queue_delete', 'cert_accept', 'cert_off', 'cert_on'];

/** A made-up visitor address. 198.51.100.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '198.51.100.' . $last;
}

function login(string $who): string
{
    return 'mqp-' . $who;
}

function mailOf(string $who): string
{
    return $who . '@' . DOMAIN;
}

/** The limiter's bucket for one visitor address's requests for a reset link. */
function bucket(string $kind, string $ip): string
{
    return 'pwreset:' . $kind . ':' . hash('sha256', $ip);
}

/** Everything this test leaves in the queue, the send log and the route's record. */
function removeMail(): void
{
    q("DELETE FROM tblMailQueue WHERE toRecipients LIKE '%" . DOMAIN . "%' OR toRecipients = ''");
    q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%" . DOMAIN . "%' OR toRecipients = ''");
    q('DELETE FROM tblMailRouteState');
    q('DELETE FROM tblPasswordResets WHERE userID BETWEEN 932000 AND 932999');
    for ($last = 1; $last <= 254; $last++) {
        q('DELETE FROM tblApiRateLimits WHERE bucket IN (?, ?)', 'ss', [bucket('ask', ip($last)), bucket('use', ip($last))]);
    }
}

function removeWorld(): void
{
    removeMail();
    // An ORGANISATION'S own rows of the three settings only the e-mail page may change. Part O1 puts two there
    // to show that they are not read; parts C9 and S1 try to add them through a page, which must refuse.
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey IN ('mail.smtp.certCheck', 'mail.smtp.certCheckOffAt', 'mail.smtp.host')");
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 932000 AND 932999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 932000 AND 932999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 932000 AND 932999');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 932000 AND 932999');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 932000 AND 932999');
}

// =============================================================================
// 🧰 The servers, and one request
// =============================================================================
$state = [
    'proc' => null, 'mailProc' => null, 'mailPipe' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [],
    'started' => false, 'cleaned' => false, 'read' => [], 'ports' => [], 'haystack' => '', 'kids' => [], 'baseline' => [],
    'graphProc' => null, 'graphRead' => 0,
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

/** Stop one process by the handle proc_open() gave — never by a name. */
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
    global $state, $db;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    foreach ($state['kids'] as &$kid) {
        stopServer($kid);
    }
    unset($kid);
    stopServer($state['proc']);
    stopServer($state['graphProc']);
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
        $db->query('ROLLBACK');
        $db->query('DO RELEASE_ALL_LOCKS()');
        if (isset($state['siteZone']) === true) {
            q('UPDATE tblSites SET timezone = ? WHERE siteID = 1', 's', [(string) $state['siteZone']]);
        }
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
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.mail-queue-pages-selftest-owner') === $state['token']) {
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

/** What one installation-wide setting holds now, read from the database. */
function stored(string $key): string
{
    return (string) (one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key])['settingValue'] ?? '(no such row)');
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects. Every answer is also
 * added to the text that is searched at the end.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string, head:string}
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 170,
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
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle), 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Mail-Queue-Pages-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }
    $state['haystack'] .= "\n" . $raw;

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
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A form token for a signed-in browser, from whichever of its pages carries one. */
function anyToken(string $jar, string $ip): string
{
    foreach (['/account', '/settings', '/'] as $path) {
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
 * Wait until the portal server has finished what it is doing. "Forgot
 * password" answers first and works afterwards; the server this test starts
 * is ONE process, so a second request is served only once the first script
 * has ended. This sends that second request, which the router refuses before
 * any portal code runs.
 */
function settle(): void
{
    req('', 'GET', '/.selftest-settle');
}

/** Ask for a reset link through the real page. */
function askForLink(string $ip, string $email): array
{
    $jar  = newJar();
    $form = req($jar, 'GET', '/forgot-password', null, $ip);
    $sent = req($jar, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $ip);
    settle();

    return $sent + ['jar' => $jar];
}

/** Everything about an answer that a stranger could compare, with the parts that differ on every request taken out. */
/**
 * 🎭 TAKE THE ONE-OFF SCRIPT NUMBER OUT BEFORE COMPARING TWO ANSWERS.
 *
 * Since #625 every answer carries a fresh `nonce` -- 32 hex characters from
 * App::cspNonce() -- in its Content-Security-Policy header and on every script
 * written into the page. It is MEANT to differ on every answer; that is what
 * makes it worth having. So two answers to the same request are never byte for
 * byte alike any more, and the checks that ask "is the person shown the same
 * thing whatever became of the e-mail?" began failing on the difference rather
 * than on anything a person could see. Those checks exist to prove the portal
 * does not reveal whether an address has an account, so a false failure there
 * is worse than noise: it hides the thing being guarded.
 *
 * The pattern is deliberately narrow -- only `nonce-<hex>` and
 * `nonce="<hex>"` -- rather than blanking every 32-character hex string,
 * which would also blank an identifier or a checksum that a check may want to
 * compare.
 */
function withoutTheNonce(string $text): string
{
    $text = (string) preg_replace('/nonce-[0-9a-f]{32}/', 'nonce-(one-off)', $text);

    return (string) preg_replace('/nonce="[0-9a-f]{32}"/', 'nonce="(one-off)"', $text);
}

function shape(array $answer): string
{
    $lines = array_filter((array) preg_split('/\R/', trim($answer['head'])), static fn (string $line): bool => stripos($line, 'Date:') !== 0);
    $lines = array_map(static fn (string $line): string => stripos($line, 'Set-Cookie:') === 0 ? 'Set-Cookie: (a cookie)' : $line, $lines);
    sort($lines);

    return withoutTheNonce(implode("\n", $lines) . "\n\n" . trim($answer['body']));
}

/** The page a person lands on after asking for a link, with its form token and its one-off script number taken out. */
function landing(array $sent, string $ip): string
{
    $page = req((string) $sent['jar'], 'GET', $sent['location'] === '' ? '/forgot-password' : $sent['location'], null, $ip);

    return $page['status'] . "\n" . withoutTheNonce((string) preg_replace('/[0-9a-f]{64}/', '(token)', $page['body']));
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
 * `private` counts everything a mail server must never be given without a
 * sign-in it accepted over encryption: every sign-in line, address, "here
 * comes the message" and message. `clearSignIns` counts sign-in lines that
 * arrived BEFORE encryption was on, which must always be none.
 *
 * @return array{connections:int, signIns:int, clearSignIns:int, private:int, shown:list<string>, messages:list<array{to:list<string>, subject:string, html:string, text:string}>}
 */
function mailSince(string $scenario): array
{
    global $state;
    $events = saw($scenario);
    $new    = array_slice($events, $state['read'][$scenario] ?? 0);
    $state['read'][$scenario] = count($events);

    $out = ['connections' => 0, 'signIns' => 0, 'clearSignIns' => 0, 'private' => 0, 'shown' => [], 'messages' => []];
    $to  = [];
    foreach ($new as [$connection, $encrypted, $kind, $rest]) {
        if ($kind === 'connect') {
            $out['connections']++;
        } elseif ($kind === 'tls' && preg_match('/certificate=(\S+)/', $rest, $c) === 1) {
            $out['shown'][] = $c[1]; // the certificate each completed handshake was shown
        } elseif ($kind === 'auth') {
            $out['signIns']++;
            $out['private']++;
            $out['clearSignIns'] += $encrypted === 1 ? 0 : 1;
        } elseif ($kind === 'line' && preg_match('/\A(MAIL FROM|RCPT TO|DATA|AUTH)\b/i', $rest) === 1) {
            $out['private']++;
            if (stripos($rest, 'MAIL FROM:<') === 0) {
                $to[$connection] = [];
            } elseif (stripos($rest, 'RCPT TO:<') === 0) {
                $to[$connection][] = substr($rest, 9, (int) strpos($rest, '>') - 9);
            }
        } elseif ($kind === 'body') {
            $out['private']++;
            if (preg_match('/complete=1 file=(\S+)/', $rest, $m) === 1) {
                $raw   = (string) file_get_contents($state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . $m[1]);
                $head  = (string) strstr($raw, "\r\n\r\n", true);
                $parts = [];
                foreach (['text/plain' => 'text', 'text/html' => 'html'] as $type => $name) {
                    $parts[$name] = preg_match('#Content-Type: ' . preg_quote($type, '#') . '[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--#s', $raw, $p) === 1
                        ? (string) base64_decode((string) preg_replace('/\s+/', '', $p[1]))
                        : '';
                }
                $out['messages'][] = [
                    'to' => $to[$connection] ?? [],
                    'subject' => preg_match('/^Subject:\s*(.*)$/mi', $head, $j) === 1 ? trim($j[1]) : '',
                    'html' => $parts['html'], 'text' => $parts['text'],
                ];
            }
        }
    }

    return $out;
}

/**
 * What the stand-in for MICROSOFT 365 has been sent since the last call: one entry for each message it was
 * asked to send (a request for a key is not a message, and is left out).
 *
 * @return list<array{to: string, subject: string}>
 */
function graphSince(): array
{
    global $state;
    $file  = $state['child'] . DIRECTORY_SEPARATOR . 'graph-requests.jsonl';
    $lines = is_file($file) === true ? (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $new   = array_slice($lines, $state['graphRead']);
    $state['graphRead'] = count($lines);
    $out = [];
    foreach ($new as $line) {
        $request = (array) json_decode((string) $line, true);
        if (str_starts_with((string) ($request['path'] ?? ''), '/graph/') === true) {
            $message = (array) (((array) json_decode((string) ($request['body'] ?? ''), true))['message'] ?? []);
            $out[]   = ['to' => (string) ($message['toRecipients'][0]['emailAddress']['address'] ?? ''), 'subject' => (string) ($message['subject'] ?? '')];
        }
    }

    return $out;
}

/** The subjects Microsoft 365's stand-in was asked to send, sorted. */
function graphSubjects(array $sent): array
{
    $subjects = array_column($sent, 'subject');
    sort($subjects);

    return $subjects;
}

/** 🧭 Make Microsoft 365 the route in use, with everything it needs filled in: the stand-in answers for it. */
function useMs365(): void
{
    setting('mail.provider', 'ms365');
    setting('auth.ms365.tenantID', 'standin-tenant');
    setting('auth.ms365.appwide.clientID', 'standin-client');
    setting('auth.ms365.appwide.clientSecret', 'standin-secret');
}

/** The route's record as stored: what fault is on record for the SMTP mailbox, and for which certificate. */
function onRecord(): array
{
    return (array) (one("SELECT problem, certSha256 FROM tblMailRouteState WHERE routeKey = 'installation'") ?? []) + ['problem' => '', 'certSha256' => ''];
}

/** The subjects of the messages a stand-in received, in order. */
function subjects(array $mail): array
{
    return array_column($mail['messages'], 'subject');
}

/** Point the portal's SMTP settings at one stand-in server. */
function useServer(string $scenario): void
{
    global $state;
    setting('mail.smtp.port', (string) $state['ports'][$scenario]);
}

/** A port on which nothing is listening: a mail server that is down. */
function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $free   = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $free;
}

/** 🪪 Make the "switch" stand-in show this certificate from its next connection on. */
function showCertificate(string $name): void
{
    global $state;
    file_put_contents($state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . 'switch-cert.txt', $name);
}

/** The SHA-256 fingerprint of one of the run's certificates, worked out by PHP's own OpenSSL from the file: the test's yardstick, not the portal's. */
function fingerprintOf(string $name): string
{
    global $state;

    return strtolower((string) openssl_x509_fingerprint((string) file_get_contents($state['child'] . DIRECTORY_SEPARATOR . 'standin' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . $name . '.pem'), 'sha256'));
}

/**
 * Start this file again as a child, on the scratch copy, and wait for it.
 *
 * @param array<string,mixed> $job See the child at the top of this file.
 *
 * @return array<string,mixed> Its findings. `threw` is '' when nothing was thrown.
 */
function child(array $job): array
{
    global $state;
    $child = (string) $state['child'];
    $sep   = DIRECTORY_SEPARATOR;
    $env   = getenv();
    $env['PORTAL_ENV']   = 'prod';
    $env['SELFTEST_JOB'] = (string) json_encode($job);
    $process = proc_open(
        [PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'max_execution_time=0',
            '-d', 'openssl.cafile=' . $child . $sep . 'standin' . $sep . 'certs' . $sep . 'ca.pem',
            '-d', 'session.save_path=' . $child . $sep . 'sessions', __FILE__, '--child', $child . $sep . 'web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $child,
        $env
    );
    if (is_resource($process) === false) {
        refuse('a child process could not be started');
    }
    $state['kids'][] = $process;
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $state['kids'] = array_values(array_filter($state['kids'], static fn (mixed $p): bool => $p !== $process));
    $state['haystack'] .= "\n" . $output;
    $at    = strrpos($output, RESULT_MARK);
    $found = $at === false ? null : json_decode((string) strtok(substr($output, $at + strlen(RESULT_MARK)), "\n"), true);
    $found = is_array($found) === true ? $found : ['threw' => 'the child printed no result'];
    if ($found['threw'] !== '') {
        note('a child process threw: ' . $found['threw']);
    }

    return $found;
}

/** Send one message as a page sends it (Mailer::send() in a child). Returns [sent, outcome, queue row number]. */
function send(string|array $to, string $subject, string $body = 'A message.', array $options = []): array
{
    return child(['task' => 'send', 'messages' => [['to' => $to, 'subject' => $subject, 'body' => $body, 'options' => $options]]])['each'][0] ?? [false, 'the child gave no answer', 0];
}

/** Put one row straight into the queue, as MailQueue keeps one. Returns its number. */
function addRow(array $to, string $subject, array $more = []): int
{
    return (int) (child(['task' => 'add', 'messages' => [$more + [
        'siteId' => 1, 'priority' => 5, 'to' => $to, 'subject' => $subject, 'html' => '<p>' . $subject . '</p>', 'text' => $subject,
        'files' => [], 'expiresAt' => null, 'repeatSafe' => false,
    ]]])['ids'][0] ?? 0);
}

/**
 * ⏩ Make time pass: every moment this test's rows hold becomes this many
 * seconds OLDER, which to every statement the queue runs is the same as the
 * clock having moved on by that much.
 */
function later(int $seconds, string $where = '1 = 1'): void
{
    q(
        'UPDATE tblMailQueue SET createdAt = createdAt - INTERVAL ? SECOND, statusAt = statusAt - INTERVAL ? SECOND, nextAttemptAt = nextAttemptAt - INTERVAL ? SECOND, '
        . "expiresAt = expiresAt - INTERVAL ? SECOND WHERE toRecipients LIKE '%" . DOMAIN . "%' AND " . $where,
        'iiii',
        array_fill(0, 4, $seconds)
    );
}

/** This test's rows in the queue, oldest first. Never the body. */
function queued(string $where = '1 = 1'): array
{
    return q(
        'SELECT queueID, siteID, priority, status, holdReason, lastError, attempts, expiresAt, toRecipients, subject, origin, createdAt '
        . "FROM tblMailQueue WHERE toRecipients LIKE '%" . DOMAIN . "%' AND " . $where . ' ORDER BY queueID'
    );
}

/** The one row with this subject, or null. */
function rowOf(string $subject): ?array
{
    return queued("subject = '" . addslashes($subject) . "'")[0] ?? null;
}

/** "status/reason" of the row with this subject; 'gone' when it is not in the queue. */
function stateOf(string $subject): string
{
    $row = rowOf($subject);

    return $row === null ? 'gone' : $row['status'] . ($row['holdReason'] === '' ? '' : '/' . $row['holdReason']);
}

/** Encrypt the way the portal's encrypt_setting() does, with a key of this test's choosing. */
function sealed(string $plain, string $keyText): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, sodium_hex2bin(hash('sha256', $keyText))));
}

/** ⏰ The scheduled job has just run: what its own page records when the scheduler calls it. Every part starts in this state. */
function jobRunning(): void
{
    q("INSERT INTO tblMailRouteState (routeKey, jobLastRanAt) VALUES ('installation', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE jobLastRanAt = UTC_TIMESTAMP()");
}

/** The scheduled job, as a scheduler calls it: its own page, with the token. Its numbers. */
function job(): array
{
    global $jobToken;
    $answer = req('', 'GET', '/cron/mail-queue?token=' . $jobToken, null, ip(2));

    return (array) json_decode($answer['body'], true) + ['http' => $answer['status']];
}

/** The health page's "Mail queue" line, as an outside monitor reads it. */
function healthLine(): array
{
    global $jobToken;
    $health = json_decode(req('', 'GET', '/cron/health?token=' . $jobToken, null, ip(2))['body'], true);

    return (array) ($health['probes']['Mail queue'] ?? []) + ['state' => '', 'label' => '', 'detail' => ''];
}

/** A stored UTC moment as the page must show it: in the organisation's zone, with the zone's short name. Worked out here, not by the portal. */
function shownInZone(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(ORG_ZONE))->format('j M Y, H:i T');
}

// -----------------------------------------------------------------------------
// 📄 The e-mail page
// -----------------------------------------------------------------------------

/** The e-mail page as one browser sees it. */
function emailPage(string $jar, string $ip): string
{
    return req($jar, 'GET', EMAIL_PAGE, null, $ip)['body'];
}

/**
 * Press one of the e-mail page's buttons as the browser in `$jar`, then read
 * the page it comes back to.
 *
 * @param array<string,string> $fields  The form's fields, without the token.
 * @param bool                 $noToken True for a hand-made POST with no form token.
 *
 * @return array{status:int, flash:string, kind:string, page:string} flash: what the page then says at its top; kind: 'success', 'warning', 'danger' or ''.
 */
function press(string $jar, string $ip, array $fields, bool $noToken = false): array
{
    $answer = req($jar, 'POST', EMAIL_PAGE, ($noToken === true ? [] : ['csrf_token' => anyToken($jar, $ip)]) + $fields, $ip);
    $page   = $answer['status'] === 302 && $answer['location'] !== '' && str_contains($answer['location'], 'login') === false
        ? req($jar, 'GET', $answer['location'], null, $ip)['body']
        : $answer['body'];
    $found = preg_match('#data-mail-flash="([a-z]+)">(.*?)</div>#s', $page, $m) === 1;

    return ['status' => $answer['status'], 'flash' => $found === true ? words($m[2]) : '', 'kind' => $found === true ? $m[1] : '', 'page' => $page];
}

/** One message's row on the page, as HTML: from its own marker to the next row's, or the end of the list. '' when it is not on the page. */
function rowOnPage(string $page, int $queueId): string
{
    $at = strpos($page, 'data-mail-queue-row="' . $queueId . '"');
    if ($at === false) {
        return '';
    }
    $next = strpos($page, 'data-mail-queue-row="', $at + 20);

    return substr($page, $at, $next === false ? 6000 : $next - $at);
}

/** The queue rows on a page, in the order they are listed: [queue number => status]. */
function rowsOnPage(string $page): array
{
    preg_match_all('/data-mail-queue-row="(\d+)" data-mail-queue-status="([a-z]+)"/', $page, $m);

    return array_combine(array_map('intval', $m[1]), $m[2]);
}

/** The fingerprint the page shows in its certificate card; '' when it shows none. */
function fingerprintOnPage(string $page): string
{
    return preg_match('/data-mail-certificate-fingerprint="([0-9a-f]{64})"/', $page, $m) === 1 ? $m[1] : '';
}

/** The general Settings page as one browser sees it. */
function settingsPage(string $jar, string $ip): string
{
    return req($jar, 'GET', '/settings', null, $ip)['body'];
}

/** What the general Settings page says at its top after a save or a delete: [kind, words]. ['', ''] when it says nothing. */
function settingsFlash(string $jar, string $ip): array
{
    return preg_match('#<div class="alert alert-([a-z]+) alert-dismissible fade show">(.*?)<button#s', settingsPage($jar, $ip), $m) === 1 ? [$m[1], words($m[2])] : ['', ''];
}

/** One setting's row on the general Settings page, as HTML: from its own marker to the next row's. '' when it is not on the page. */
function settingsRow(string $page, string $key): string
{
    $at = strpos($page, 'data-setting-key="' . $key . '"');
    if ($at === false) {
        return '';
    }
    $next = strpos($page, 'portal-setting-row', $at);

    return substr($page, $at, $next === false ? 4000 : $next - $at);
}

/** One of the page's notices, as words; '' when the page has none of that kind. */
function warningOnPage(string $page, string $kind): string
{
    return preg_match('#data-mail-warning="' . preg_quote($kind, '#') . '">(.*?)</div>#s', $page, $m) === 1 ? words($m[1]) : '';
}

/** The activity records of one kind written since a point: [who, what it says, how many seconds ago]. */
function logged(string $type, int $since): array
{
    return q('SELECT userID, activityDescription, TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS age FROM tblActivityLogs WHERE activityType = ? AND logID > ? ORDER BY logID', 'si', [$type, $since]);
}

/** The newest activity record's number: "since now". */
function logMark(): int
{
    return (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
}

/** Everything that must stay as it is when a button is refused: the queue's rows, the certificate setting and the route's choice. */
function untouched(): string
{
    return (string) json_encode([
        q("SELECT queueID, status, holdReason, attempts FROM tblMailQueue WHERE toRecipients LIKE '%" . DOMAIN . "%' ORDER BY queueID"),
        stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt'), stored('mail.provider'),
        one("SELECT problem, certSha256 FROM tblMailRouteState WHERE routeKey = 'installation'"),
    ]);
}

/**
 * Put the test back to its starting state between parts: no mail of its own
 * anywhere, nothing on record for the route, the baseline settings (a mail
 * server with a good certificate that accepts, the certificate checked), and
 * the "switch" stand-in showing its good certificate.
 */
function startClean(): void
{
    global $state;
    removeMail();
    foreach ($state['baseline'] as $key => [$value, $secret]) {
        setting((string) $key, (string) $value, (int) $secret);
    }
    jobRunning();
    if ($state['ports'] !== []) {
        showCertificate('good');
        useServer('switch');
        foreach (array_keys($state['ports']) as $scenario) {
            mailSince((string) $scenario);
        }
    }
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true || portAnswers($graphPort) === true) {
    refuse("something already answers on port {$port} or {$graphPort}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();
$others = (int) one('SELECT COUNT(*) AS n FROM tblMailQueue')['n'];
if ($others > 0) {
    refuse("the mail queue holds {$others} row(s) that are not this test's. The test runs the mail job, which works through every row, so it will not start while another test's mail is waiting");
}
// Everything the portal records from here on is this run's, and is removed at the end.
$errorsFrom   = (int) (one('SELECT COALESCE(MAX(errorID), 0) AS n FROM tblErrors')['n']);
$activityFrom = logMark();

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (PEOPLE as $who => $id) {
    $global    = ($who === 'root' || $who === 'second') ? 1 : 0;
    $siteAdmin = ($global === 1 || $who === 'orgadmin') ? 1 : 0;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest pages ' . $who, mailOf($who), $global, $global]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, ?)', 'iii', [$id, $siteAdmin, $global]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
$state['siteZone'] = (string) one('SELECT timezone FROM tblSites WHERE siteID = 1')['timezone'];
q('UPDATE tblSites SET timezone = ? WHERE siteID = 1', 's', [ORG_ZONE]);

// 🔑 The mailbox's password for this run, long and with characters a page must escape.
$mailPassword  = 'Mq<&' . bin2hex(random_bytes(10)) . '>"p4!';
$otherPassword = 'Other-' . bin2hex(random_bytes(10)) . '-pw';
// 🔎 Words put in message bodies, so that a body turning up anywhere it should not be can be found.
$bodyMark = 'BODYMARK-' . bin2hex(random_bytes(8));
// 🔎 Words put where the mail server's own reply would be, with the mailbox's name in them, as a real refusal has.
$replyMark = 'REPLYMARK-' . bin2hex(random_bytes(6));
$jobToken  = 'JOBTOKEN' . bin2hex(random_bytes(16));

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'mail-queue-pages-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.mail-queue-pages-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/mail-queue-pages-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
$keyText = bin2hex(random_bytes(32));
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', $keyText);

// 🪪 The certificates, made for this run by the mail library's own test's
//    maker, lifted as text (it is written inside `run.php`, which cannot be
//    loaded without running that whole test). If its shape ever changes, this
//    stops here and says so.
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
file_put_contents($child . $sep . 'certificate-maker.php', "<?php\n// Lifted from tools/maillib/tests/run.php by tools/mail-queue-pages-selftest.php for ONE run. Test only.\ndeclare(strict_types=1);\n" . substr($libraryTest, $from, $to - $from));
require $child . $sep . 'certificate-maker.php';
if (function_exists('makeCertificates') === false) {
    refuse('the text lifted from tools/maillib/tests/run.php did not define makeCertificates()');
}
makeCertificates($standinDir . $sep . 'certs');
foreach (['ca', 'good', 'expired', 'expired2'] as $made) {
    if (is_file($standinDir . $sep . 'certs' . $sep . $made . '.pem') === false) {
        refuse("the certificate maker did not make '{$made}.pem'");
    }
}
if (fingerprintOf('expired') === fingerprintOf('expired2') || strlen(fingerprintOf('expired')) !== 64) {
    refuse('the two expired certificates of the run are not two different certificates, so "a different certificate" could not be staged');
}

// 🎭 What each stand-in mail server does (the words are smtp-standin.php's).
$mailbox   = ['user' => MAILBOX_USER, 'pass' => $mailPassword];
$scenarios = [
    // 🪪 The server whose certificate this test changes at will: whatever `switch-cert.txt` names, read at every connection.
    'switch'        => $mailbox + ['certNamedIn' => 'switch-cert.txt'],
    // A second mail server, under another NAME (127.0.0.1), which accepts another password only (N5).
    'other-name'    => ['user' => MAILBOX_USER, 'pass' => $otherPassword],
    // Does not list encryption among what it offers (C6). Its certificate has expired, so that the portal's
    // "would it pass now?" answers no and checking is still OFF when the message is tried.
    'no-encryption' => $mailbox + ['offers' => ['AUTH PLAIN LOGIN'], 'certs' => ['expired']],
    // Speaks no encryption at all (C6).
    'plain-only'    => $mailbox + ['tls' => 'none'],
];
file_put_contents($standinDir . $sep . 'scenarios.ser', serialize($scenarios));
file_put_contents($standinDir . $sep . 'switch-cert.txt', 'good');

// 🎯 MICROSOFT 365's STAND-IN (part R1), the way tools/sign-in-limits-selftest.php does it. The two addresses the
//    Microsoft 365 route calls are pointed at the stand-in, in the SCRATCH COPY of Mailer.php only. Each must be found
//    exactly once, and afterwards the copy must call no address but the stand-in's, or the run stops: an address added
//    later would otherwise be called for real. REJECTED: a setting for the provider's address. That would be a switch
//    in the real portal able to send an organisation's mail, and its key, to any server.
$graphStandIn = 'http://127.0.0.1:' . $graphPort;
$mailerCopy   = $child . $sep . 'web' . $sep . '_core' . $sep . 'Mailer.php';
$mailerCode   = (string) file_get_contents($mailerCopy);
foreach ([
    "'https://graph.microsoft.com/v1.0/users/'" => "'" . $graphStandIn . "/graph/v1.0/users/'",
    "'https://login.microsoftonline.com/'"      => "'" . $graphStandIn . "/ms-login/'",
] as $real => $standIn) {
    if (substr_count($mailerCode, $real) !== 1) {
        refuse("expected to find {$real} exactly once in the copy of Mailer.php, and found it " . substr_count($mailerCode, $real) . ' time(s)');
    }
    $mailerCode = str_replace($real, $standIn, $mailerCode);
}
if (preg_match('#curl_init\(\s*\'https?://(?!127\.0\.0\.1:' . $graphPort . ')#', $mailerCode) === 1) {
    refuse('the copy of Mailer.php still calls an address that is not the stand-in');
}
file_put_contents($mailerCopy, $mailerCode);
// It says yes to everything, writes down what it was sent, and sends nothing.
$graphCode = <<<'GRAPH'
<?php
// Written by tools/mail-queue-pages-selftest.php for ONE run (deleted with its folder). Test only.
// A stand-in for Microsoft's sign-in and mail services: it records each request and sends nothing.
declare(strict_types=1);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
file_put_contents(__DIR__ . '/graph-requests.jsonl', json_encode(['path' => $path, 'body' => (string) file_get_contents('php://input')]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if (str_starts_with($path, '/ms-login/') === true) {
    echo json_encode(['access_token' => 'standin-token', 'expires_in' => 3600]);
} elseif (str_starts_with($path, '/graph/') === true) {
    http_response_code(202);
} else {
    http_response_code(404);
}
return true;
GRAPH;
file_put_contents($child . $sep . 'graph-standin.php', $graphCode);

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/mail-queue-pages-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Mail-Queue-Pages-Selftest: __TOKEN__');
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
// 🎭 `--watch-parent`: if this file is killed, the stand-in sees its pipe close, deletes its keys and folder, and stops.
$state['mailProc'] = proc_open(
    [PHP_BINARY, $repo . $sep . 'tools' . $sep . 'maillib' . $sep . 'tests' . $sep . 'smtp-standin.php', $standinDir, '--watch-parent'],
    [0 => ['pipe', 'r'], 1 => ['file', $standinDir . $sep . 'standin.out', 'w'], 2 => ['file', $standinDir . $sep . 'standin.err', 'w']],
    $mailPipes
);
$state['mailPipe'] = $mailPipes[0] ?? null;
// 🌐 The portal. `openssl.cafile` is PHP's own setting for "the authorities
//    this machine trusts": this run's made-up authority is trusted without a
//    line of the portal being changed. `max_execution_time` is raised because
//    a busy machine must not decide a test.
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'max_execution_time=180', '-d', 'openssl.cafile=' . $standinDir . $sep . 'certs' . $sep . 'ca.pem',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    $quiet,
    $pipes,
    $child,
    $env
);
// 🎭 Microsoft 365's stand-in: ONE process, on 127.0.0.1 only.
$state['graphProc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $graphPort, $child . $sep . 'graph-standin.php'], $quiet, $graphPipes, $child, $env);
if (is_resource($state['proc']) === false || is_resource($state['mailProc']) === false || is_resource($state['graphProc']) === false) {
    refuse('a server could not be started');
}
$deadline = microtime(true) + 30;
while ((portAnswers($port) === false || portAnswers($graphPort) === false || is_file($standinDir . $sep . 'ports.ser') === false) && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($graphPort) === false) {
    refuse("the stand-in for Microsoft 365 never answered on port {$graphPort}");
}
if (portAnswers($port) === false || is_file($standinDir . $sep . 'ports.ser') === false) {
    refuse("a server never answered (the portal on port {$port}; the stand-in mail servers said: " . trim((string) @file_get_contents($standinDir . $sep . 'standin.err')) . ')');
}
$state['ports'] = (array) unserialize((string) file_get_contents($standinDir . $sep . 'ports.ser'), ['allowed_classes' => false]);
if (array_keys($state['ports']) !== array_keys($scenarios)) {
    refuse('the stand-in mail servers did not start one server for each scenario');
}

echo "The mail queue's page, and the ways past a failed certificate — self-test (#592, part 4)\n";
echo 'portal server process ' . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', stand-in mail servers process ' . (int) proc_get_status($state['mailProc'])['pid']
    . ' (' . count($state['ports']) . ' servers, 127.0.0.1 only), a stand-in for Microsoft 365 on port ' . $graphPort . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
if ($only !== []) {
    echo 'ONLY THE PARTS ' . implode(', ', $only) . " ARE RUN. This is not the whole test and cannot end with exit 0.\n";
}

$state['baseline'] = [
    // Believe the "X-Forwarded-For" header from 127.0.0.1, so each part has a visitor address of its own.
    'portal.trustedProxies' => ['127.0.0.1', 0], 'portal.trustedProxyHeader' => ['x-forwarded-for', 0],
    'mail.provider' => ['smtp', 0], 'mail.smtp.host' => [MAIL_HOST, 0], 'mail.smtp.security' => ['starttls', 0], 'mail.smtp.username' => [MAILBOX_USER, 0],
    'mail.smtp.password' => [sealed($mailPassword, $keyText), 1], 'mail.defaultFromAddress' => [SENDER, 0], 'mail.defaultFromName' => ['Selftest Pages Office', 0], 'mail.defaultReplyTo' => ['', 0],
    'mail.limit.perHour' => ['', 0], 'mail.fallbackProvider' => ['', 0],
    // Microsoft 365 is not set up, unless a part sets it up (useMs365(), part R1).
    'auth.ms365.tenantID' => ['', 0], 'auth.ms365.appwide.clientID' => ['', 0], 'auth.ms365.appwide.clientSecret' => ['', 0], 'mail.ms365.sharedMailbox' => ['', 0],
    'mail.queue.retryHours' => ['24', 0], 'mail.queue.maxAgeHours' => ['48', 0], 'mail.queue.keepFailedDays' => ['7', 0], 'mail.queue.holdDays' => ['7', 0],
    // 🪪 The certificate is checked: what a fresh installation holds.
    'mail.smtp.certCheck' => ['on', 0], 'mail.smtp.certCheckOffAt' => ['', 0],
    // The job's token, stored as plain text and NOT marked as a secret, so that it reads the same whether or not the portal's start-up decrypts secret settings.
    'maintenance.cronToken' => [$jobToken, 0],
    // The portal's own address: a link in an e-mail written by a child process (which has no visitor) points at the server this run started.
    'site.url' => ['http://127.0.0.1:' . $port, 0],
    'auth.passwordReset.tokenExpiry' => ['60', 0],
];
startClean();
$rootJar   = signIn('root', $password, ip(1));
$secondJar = signIn('second', $password, ip(3));
$orgJar    = signIn('orgadmin', $password, ip(4));
$memberJar = signIn('member', $password, ip(5));
$rootId    = PEOPLE['root'];
$rootName  = 'Selftest pages root';
/** Every reset link this run's mail servers were given, for the search at the end. */
$resetTokens = [];
$noteLinks = static function (array $mail) use (&$resetTokens): void {
    foreach ($mail['messages'] as $message) {
        if (preg_match_all('/reset-password\?token=([0-9a-f]{64})/', $message['html'] . $message['text'], $found) > 0) {
            $resetTokens = array_values(array_unique(array_merge($resetTokens, $found[1])));
        }
    }
};
/** Which of the three ways through this run has really used, for the search at the end. */
$waysUsed = [];

// =============================================================================
if (part('C1', 'An expired certificate: nothing reaches the mail server past the handshake, the message is held, and three pages say why') === true) {
// =============================================================================
    showCertificate('expired');
    $asked = askForLink(ip(11), mailOf('member'));
    $mail  = mailSince('switch');
    $row   = queued()[0] ?? null;
    ok(
        'a reset link is asked for through the real page while the mail server shows an expired certificate: the mail server is given NOTHING after the handshake (no sign-in, no address, no message), and the message is held, urgent, with its link\'s own use-by time',
        $asked['status'] === 302 && $mail['connections'] >= 1 && $mail['private'] === 0 && $mail['signIns'] === 0 && $mail['messages'] === []
            && $row !== null && $row['status'] === 'held' && $row['holdReason'] === 'certificate' && (int) $row['priority'] === 1 && $row['origin'] === 'reset:' . PEOPLE['member'] && $row['expiresAt'] !== null,
        'HTTP ' . $asked['status'] . '; the mail server saw ' . json_encode(['connections' => $mail['connections'], 'private' => $mail['private'], 'certificates' => $mail['shown']]) . '; the row: ' . json_encode($row)
    );
    $state1 = one("SELECT problem, certSha256, certReasons FROM tblMailRouteState WHERE routeKey = 'installation'");
    ok(
        'the fault is on record for the route, with the fingerprint of the very certificate the server showed (worked out here from the certificate file, not by the portal)',
        $state1 !== null && $state1['problem'] === 'certificate' && $state1['certSha256'] === fingerprintOf('expired') && str_contains((string) $state1['certReasons'], 'expired') === true,
        json_encode($state1) . ' against ' . fingerprintOf('expired')
    );
    // Two more messages, as pages send them: held WITHOUT the mail server being contacted at all.
    $sentA = send(mailOf('other'), 'C1 an ordinary message', 'Words of the first: ' . $bodyMark);
    $sentB = send(mailOf('third'), 'C1 another ordinary message', 'Words of the second: ' . $bodyMark);
    $mail  = mailSince('switch');
    ok(
        'two more messages sent by a page are held too, and the mail server is not contacted for either: the hold is NOT turned into a try with the check off',
        $sentA[1] === 'held' && $sentB[1] === 'held' && $mail['connections'] === 0 && stateOf('C1 an ordinary message') === 'held/certificate' && stateOf('C1 another ordinary message') === 'held/certificate',
        json_encode([$sentA, $sentB]) . '; connections ' . $mail['connections']
    );
    $page  = emailPage($rootJar, ip(1));
    $found = preg_match('#data-mail-certificate-found>(.*?)</div>#s', $page, $m) === 1 ? words($m[1]) : '';
    ok(
        'the e-mail page tells a global administrator why, and shows the certificate: what the check found (it expired), its fingerprint, and all three ways past the check, "accept only this certificate" before "switch off"',
        str_contains(warningOnPage($page, 'certificate'), 'The certificate expired on') === true && str_contains($found, 'The certificate expired on') === true
            && fingerprintOnPage($page) === fingerprintOf('expired') && str_contains($page, 'data-mail-certificate="readable"') === true
            && strpos($page, 'data-mail-way="one-message"') !== false && strpos($page, 'data-mail-way="accept"') < strpos($page, 'data-mail-way="off"') && strpos($page, 'data-mail-way="accept"') !== false,
        'notice: ' . warningOnPage($page, 'certificate') . ' | found: ' . $found . ' | fingerprint on the page: ' . fingerprintOnPage($page)
    );
    $dash    = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    $dashOrg = req($orgJar, 'GET', '/admin', null, ip(4))['body'];
    $notice  = preg_match('#id="mail-certificate-failed">(.*?)</div>#s', $dash, $m) === 1 ? words($m[1]) : '';
    ok(
        'the admin dashboard tells a global administrator (who cannot be told by e-mail): no e-mail is going out, why, how many are held, and where to go. An organisation\'s administrator is shown no such notice',
        str_contains($notice, 'No e-mail is going out') === true && str_contains($notice, 'The certificate expired on') === true && str_contains($notice, '(3 so far)') === true
            && str_contains($dash, 'href="' . EMAIL_PAGE . '"') === true && str_contains($dashOrg, 'mail-certificate-failed') === false && str_contains($dashOrg, 'Admin Dashboard') === true,
        'the notice: ' . ($notice === '' ? 'NOT on the dashboard' : $notice)
    );
    $health = healthLine();
    ok(
        'the health page, which an outside monitor reads, says so in red: mail is held, why, and that a global administrator can see the ways past the check',
        $health['state'] === 'crit' && $health['label'] === 'Mail is held' && str_contains($health['detail'], 'The certificate expired on') === true && str_contains($health['detail'], '0 waiting, 3 held') === true
            && str_contains($health['detail'], 'the ways past the check') === true,
        json_encode($health)
    );
}

// =============================================================================
if (part('C7', 'A held reset is listed first with how long its link has left; sent in time it arrives; left alone it expires unsent; the person\'s screen is the same') === true) {
// =============================================================================
    showCertificate('expired');
    // An urgent message that is OLDER than the reset: by urgency and age alone it would be listed first.
    $older = addRow([mailOf('other')], 'C7 urgent and older', ['priority' => 1, 'status' => 'held', 'holdReason' => 'certificate']);
    later(600);
    $askA = askForLink(ip(21), mailOf('member'));          // sent in time, below
    $askB = askForLink(ip(22), mailOf('third'));           // left alone
    $askC = askForLink(ip(23), 'nobody@' . DOMAIN);        // an address with no account
    mailSince('switch');
    $resetA = queued("origin = 'reset:" . PEOPLE['member'] . "'")[0] ?? null;
    $resetB = queued("origin = 'reset:" . PEOPLE['third'] . "'")[0] ?? null;
    $page   = emailPage($rootJar, ip(1));
    $order  = array_keys(rowsOnPage($page));
    $shown  = $resetA === null ? '' : rowOnPage($page, (int) $resetA['queueID']);
    $useBy  = preg_match('#data-mail-queue-use-by>(.*?)</span>#s', $shown, $m) === 1 ? words($m[1]) : '';
    ok(
        // (An hour's link reads "59 minutes", or "1 hour" when the database's clock is a second or two behind the web server's.)
        'the two held resets are listed FIRST, above an urgent message that is older, each as a "Password reset" with how long its link has left (about an hour, in hours and minutes) and the moment in the organisation\'s own zone',
        $resetA !== null && $resetB !== null && count($order) === 3 && $order[2] === $older && in_array((int) $resetA['queueID'], [$order[0], $order[1]], true) === true
            && str_contains(words($shown), 'Password reset') === true && preg_match('/\AIts link stops working in (1 hour|5[0-9] minutes) \(/', $useBy) === 1
            && str_contains($useBy, shownInZone((string) $resetA['expiresAt'])) === true && preg_match('/ E[SD]T\)\z/', $useBy) === 1,
        'listed in the order ' . json_encode($order) . ' (the older urgent one is ' . $older . '); the reset\'s row says: ' . $useBy
    );
    ok(
        'the three people were each shown exactly the same thing: the one whose reset will be sent, the one whose reset will expire, and the one with no account',
        shape($askA) === shape($askB) && shape($askB) === shape($askC) && landing($askA, ip(21)) === landing($askB, ip(22)) && landing($askB, ip(22)) === landing($askC, ip(23)),
        'the answers differ'
    );
    // Reset A is sent in time, past the check, by a global administrator.
    $sent = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) ($resetA['queueID'] ?? 0), 'fingerprint' => fingerprintOnPage($page), 'understood' => '1']);
    $mail = mailSince('switch');
    $noteLinks($mail);
    ok(
        'sent in time, the reset arrives: one message, for that person, carrying a reset link (in the e-mail, and nowhere on the page)',
        $sent['kind'] === 'success' && count($mail['messages']) === 1 && $mail['messages'][0]['to'] === [mailOf('member')] && preg_match('/reset-password\?token=[0-9a-f]{64}/', $mail['messages'][0]['html']) === 1
            && stripos($sent['page'], 'reset-password?token=') === false && $mail['clearSignIns'] === 0,
        $sent['flash'] . ' | messages: ' . json_encode(subjects($mail))
    );
    // Reset B's link runs out. BEFORE the job has noticed, a hand-made press for it (the page no longer offers the button).
    later(3700, "origin = 'reset:" . PEOPLE['third'] . "'");
    $page   = emailPage($rootJar, ip(1));
    $shownB = rowOnPage($page, (int) $resetB['queueID']);
    $late   = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $resetB['queueID'], 'fingerprint' => fingerprintOnPage($page), 'understood' => '1']);
    $mail   = mailSince('switch');
    ok(
        'past its use-by time a held reset is NEVER sent: the page says its time has passed and offers no button for it, and a hand-made press for it is refused, with nothing given to the mail server',
        str_contains(words($shownB), 'Its use-by time has passed: it will not be sent.') === true && str_contains($shownB, 'queue_send_past_check') === false
            && $late['kind'] === 'danger' && str_contains($late['flash'], 'its use-by moment has passed') === true && $mail['private'] === 0,
        'the row: ' . words($shownB) . ' | the press: ' . $late['flash'] . ' | the mail server was given ' . $mail['private'] . ' private line(s)'
    );
    $ran   = job();
    $mail  = mailSince('switch');
    $after = queued("origin = 'reset:" . PEOPLE['third'] . "'")[0] ?? null;
    $body  = one('SELECT bodyHtml, bodyText FROM tblMailQueue WHERE queueID = ?', 'i', [(int) $resetB['queueID']]);
    $page  = emailPage($rootJar, ip(1));
    ok(
        'left alone it expires unsent: the job marks it expired, removes its content at once, sends nothing, and the page lists it as "Expired, not sent"',
        ($ran['expired'] ?? 0) === 1 && $after !== null && $after['status'] === 'expired' && $body !== null && $body['bodyHtml'] === null && $body['bodyText'] === null
            && $mail['messages'] === [] && (rowsOnPage($page)[(int) $resetB['queueID']] ?? '') === 'expired' && str_contains(words(rowOnPage($page, (int) $resetB['queueID'])), 'Expired, not sent') === true,
        'the job said ' . json_encode($ran) . '; the row is ' . json_encode($after)
    );
}

// =============================================================================
if (part('C3', '"Send this one": that message arrives and the others stay held; a certificate swapped between the page and the button refuses the send') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'C3 the one to send', 'Its words: ' . $bodyMark);
    send(mailOf('other'), 'C3 stays held, first', 'Its words: ' . $bodyMark);
    send(mailOf('third'), 'C3 stays held, second', 'Its words: ' . $bodyMark);
    mailSince('switch');
    $one   = (int) (rowOf('C3 the one to send')['queueID'] ?? 0);
    $page  = emailPage($rootJar, ip(1));
    $shown = fingerprintOnPage($page);
    $form  = rowOnPage($page, $one);
    ok(
        'each held message has its own "Send this one with the check skipped", which says it sends this ONE message and only through the certificate shown, and carries that certificate\'s fingerprint; there is no button that sends them all',
        $shown === fingerprintOf('expired') && str_contains($form, 'name="action" value="queue_send_past_check"') === true && str_contains($form, 'name="fingerprint" value="' . $shown . '"') === true
            && str_contains(words($form), 'This sends this one message now, and only if the mail server still shows exactly the certificate described above') === true
            && str_contains(words($form), 'Every other message stays held.') === true && substr_count($page, 'value="queue_send_past_check"') === 3
            // Every "send" action the page carries names ONE message: there is no action that sends them all.
            && preg_match('/value="queue_send_(?!past_check"|again")/', $page) === 0 && substr_count($page, 'name="queueId"') >= 6,
        'the row\'s form: ' . words($form)
    );
    // 🔁 THE SWAP. The page showed one certificate; before the button is pressed the server starts showing another.
    showCertificate('expired2');
    $before  = untouched();
    $mark    = logMark();
    $swapped = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'fingerprint' => $shown, 'understood' => '1']);
    $mail    = mailSince('switch');
    ok(
        'the certificate is swapped between the page and the button: the send is REFUSED. The mail server that showed the other certificate is given no sign-in, no address and no message, and all three messages are still held',
        $swapped['kind'] !== 'success' && $mail['shown'] !== [] && in_array('expired', $mail['shown'], true) === false && $mail['private'] === 0 && $mail['messages'] === []
            && stateOf('C3 the one to send') === 'held/certificate' && stateOf('C3 stays held, first') === 'held/certificate' && stateOf('C3 stays held, second') === 'held/certificate'
            && str_contains($swapped['flash'], 'The mail server showed a different certificate from the one that was approved') === true,
        'the page said: ' . $swapped['flash'] . ' | the mail server showed ' . json_encode($mail['shown']) . ' and was given ' . $mail['private'] . ' private line(s)'
    );
    $page2 = emailPage($rootJar, ip(1));
    ok(
        'the page now shows the certificate the server really showed, so the next decision is made about that one; and the fingerprint the old page carried is refused from now on',
        fingerprintOnPage($page2) === fingerprintOf('expired2')
            && str_contains(press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'fingerprint' => $shown, 'understood' => '1'])['flash'], 'not the one this page showed you') === true
            && mailSince('switch')['connections'] === 0,
        'the page shows ' . fingerprintOnPage($page2) . '; expected ' . fingerprintOf('expired2')
    );
    // Refused without the tick, without a fingerprint, and with one that is nobody's.
    $noTick  = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'fingerprint' => fingerprintOf('expired2')]);
    $noPrint = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'understood' => '1']);
    $madeUp  = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'fingerprint' => str_repeat('ab', 32), 'understood' => '1']);
    $again   = press($rootJar, ip(1), ['action' => 'queue_send_again', 'queueId' => (string) $one, 'understood' => '1']);
    $mail    = mailSince('switch');
    ok(
        'it is refused without the tick, without a fingerprint, with a fingerprint that is not the certificate on record, and through "Send it again" (which is for a message nobody is sure about, not for this): the mail server is not contacted for any of them',
        $noTick['kind'] === 'danger' && $noPrint['kind'] === 'danger' && $madeUp['kind'] === 'danger' && $again['kind'] === 'danger' && str_contains($again['flash'], 'Send this one with the check skipped') === true
            && $mail['connections'] === 0 && stateOf('C3 the one to send') === 'held/certificate',
        json_encode([$noTick['flash'], $noPrint['flash'], $madeUp['flash'], $again['flash']]) . '; connections ' . $mail['connections']
    );
    // ✅ Pressed for the certificate the page shows NOW.
    $mark = logMark();
    $sent = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'fingerprint' => fingerprintOnPage($page2), 'understood' => '1']);
    $mail = mailSince('switch');
    $waysUsed['one message'] = count($mail['messages']) === 1;
    ok(
        'pressed for the certificate now on the page, THAT message arrives, through that certificate and after a sign-in over encryption; the other two are still held; the certificate fault is still on record and still on the dashboard',
        $sent['kind'] === 'success' && subjects($mail) === ['C3 the one to send'] && $mail['clearSignIns'] === 0 && $mail['signIns'] === 1 && array_unique($mail['shown']) === ['expired2']
            && stateOf('C3 the one to send') === 'gone' && stateOf('C3 stays held, first') === 'held/certificate' && stateOf('C3 stays held, second') === 'held/certificate'
            && (one("SELECT problem FROM tblMailRouteState WHERE routeKey = 'installation'")['problem'] ?? '') === 'certificate'
            && str_contains(req($rootJar, 'GET', '/admin', null, ip(1))['body'], 'id="mail-certificate-failed"') === true && stored('mail.smtp.certCheck') === 'on',
        'the page said: ' . $sent['flash'] . ' | arrived: ' . json_encode(subjects($mail)) . ' | the others: ' . stateOf('C3 stays held, first') . ', ' . stateOf('C3 stays held, second')
    );
    $record = logged('MailQueueSentPastCheck', $mark);
    ok(
        'the activity log names who and when: that administrator\'s account, in the last minute, with the message\'s number, the fingerprint that was accepted, and that it was sent',
        count($record) === 1 && (int) $record[0]['userID'] === $rootId && (int) $record[0]['age'] >= 0 && (int) $record[0]['age'] < 120
            && str_contains((string) $record[0]['activityDescription'], 'message ' . $one . ' ') === true && str_contains((string) $record[0]['activityDescription'], fingerprintOf('expired2')) === true
            && str_contains((string) $record[0]['activityDescription'], 'What came of it: sent.') === true,
        json_encode($record)
    );
    // The job comes round: the two that were not chosen are STILL not sent.
    $ran  = job();
    $mail = mailSince('switch');
    ok(
        'the job\'s next run sends neither of the other two: "send this one" let one message out, not the queue',
        $mail['messages'] === [] && $mail['private'] === 0 && stateOf('C3 stays held, first') === 'held/certificate' && stateOf('C3 stays held, second') === 'held/certificate' && ($ran['sent'] ?? -1) === 0,
        'the job said ' . json_encode($ran) . '; arrived: ' . json_encode(subjects($mail))
    );
    // 🚫 A message held because NOBODY CAN TELL whether it went, pressed by hand with everything "send this one" asks for:
    //    the certificate on record, and the tick. That tick says nothing about a second copy, which is what this message needs said.
    $unsure   = addRow([mailOf('member')], 'C3 nobody can tell', ['status' => 'held', 'holdReason' => 'unknown-if-sent']);
    $pageNow  = emailPage($rootJar, ip(1));
    $before   = untouched();
    $wrongOne = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $unsure, 'fingerprint' => fingerprintOnPage($pageNow), 'understood' => '1']);
    $mail     = mailSince('switch');
    ok(
        '"send with the check skipped" does NOT work on a message held because nobody can tell whether it went (that one goes only through "Send it again", with its own tick about a second copy): a hand-made press with the right certificate and the tick is refused in words, the mail server is not contacted, the message stays held as it was, and its row offers no such button',
        fingerprintOnPage($pageNow) === fingerprintOf('expired2') && $wrongOne['kind'] === 'danger' && str_contains($wrongOne['flash'], 'not held because of the certificate') === true
            && $mail['connections'] === 0 && $mail['messages'] === [] && untouched() === $before && stateOf('C3 nobody can tell') === 'held/unknown-if-sent'
            && str_contains(rowOnPage($pageNow, $unsure), 'queue_send_past_check') === false && str_contains(rowOnPage($pageNow, $unsure), 'queue_send_again') === true,
        'the press said: ' . $wrongOne['kind'] . ': ' . $wrongOne['flash'] . ' | the message is now ' . stateOf('C3 nobody can tell') . ' | the mail server saw ' . $mail['connections'] . ' connection(s) and ' . count($mail['messages']) . ' message(s)'
    );
}

// =============================================================================
if (part('C5', 'An organisation\'s administrator, a member, a stranger, and a POST with no form token: every one of the seven buttons is refused, and nothing changes') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'C5 held for the certificate', 'Its words: ' . $bodyMark);
    $unsure = addRow([mailOf('other')], 'C5 nobody can tell', ['status' => 'held', 'holdReason' => 'unknown-if-sent']);
    $wait   = addRow([mailOf('third')], 'C5 waiting');
    later(3600, 'queueID = ' . $wait); // not due again for a while, so the job leaves it be
    q('UPDATE tblMailQueue SET nextAttemptAt = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE queueID = ?', 'i', [$wait]);
    mailSince('switch');
    $held  = (int) rowOf('C5 held for the certificate')['queueID'];
    $print = fingerprintOf('expired');
    /** Fields that WOULD do something for each of the seven, if the button were let through. */
    $fields = [
        'queue_run'             => [],
        'queue_send_again'      => ['queueId' => (string) $unsure, 'understood' => '1'],
        'queue_send_past_check' => ['queueId' => (string) $held, 'fingerprint' => $print, 'understood' => '1'],
        'queue_delete'          => ['queueId' => (string) $wait, 'understood' => '1'],
        'cert_accept'           => ['fingerprint' => $print, 'understood' => '1'],
        'cert_off'              => ['serverName' => MAIL_HOST],
        'cert_on'               => [],
    ];
    $before = untouched();
    $mark   = logMark();
    $said   = [];
    foreach (SEVEN as $action) {
        $said[$action] = press($orgJar, ip(4), ['action' => $action] + $fields[$action]);
    }
    $mail    = mailSince('switch');
    $refused = logged('SettingsGroupSaveRefused', $mark);
    ok(
        'an administrator of ONE organisation presses each of the seven with a valid form token: every one is refused in plain words, nothing in the queue or the settings changes, the mail server is not contacted, and each refusal is in the activity log under their account',
        count(array_filter($said, static fn (array $a): bool => $a['kind'] === 'danger' && str_contains($a['flash'], 'Only a global administrator can change them') === true)) === 7
            && untouched() === $before && $mail['connections'] === 0 && count($refused) === 7 && array_unique(array_column($refused, 'userID')) == [PEOPLE['orgadmin']],
        count($refused) . ' refusals logged; ' . json_encode(array_map(static fn (array $a): string => $a['kind'], $said)) . '; ' . ($before === untouched() ? 'nothing changed' : 'SOMETHING CHANGED: ' . untouched())
    );
    $said = [];
    foreach (SEVEN as $action) {
        $said[$action] = press($rootJar, ip(1), ['action' => $action] + $fields[$action], true);
    }
    $mail = mailSince('switch');
    ok(
        'a global administrator\'s browser sends each of the seven by hand with NO form token (what another website can make a browser do): every one is refused, nothing changes, and the mail server is not contacted',
        count(array_filter($said, static fn (array $a): bool => $a['kind'] === 'danger' && str_contains($a['flash'], 'This form had expired, so nothing was changed') === true)) === 7
            && untouched() === $before && $mail['connections'] === 0,
        json_encode(array_map(static fn (array $a): string => $a['kind'] . ': ' . $a['flash'], $said)) . '; ' . ($before === untouched() ? 'nothing changed' : 'SOMETHING CHANGED')
    );
    $asMember   = [];
    $asStranger = [];
    foreach (SEVEN as $action) {
        $asMember[]   = req($memberJar, 'POST', EMAIL_PAGE, ['csrf_token' => anyToken($memberJar, ip(5)), 'action' => $action] + $fields[$action], ip(5))['status'];
        $asStranger[] = req('', 'POST', EMAIL_PAGE, ['action' => $action] + $fields[$action], ip(6));
    }
    $mail = mailSince('switch');
    ok(
        'an ordinary member is turned away from the page itself (403) for each of the seven, and somebody who is not signed in is sent to the sign-in page: nothing changes',
        array_unique($asMember) === [403] && count(array_filter($asStranger, static fn (array $a): bool => $a['status'] === 302 && str_contains($a['location'], 'login') === true)) === 7
            && untouched() === $before && $mail['connections'] === 0,
        'member: ' . json_encode($asMember) . '; stranger: ' . json_encode(array_map(static fn (array $a): string => $a['status'] . ' ' . $a['location'], $asStranger))
    );
    $get = req($rootJar, 'GET', EMAIL_PAGE . '?action=cert_off&serverName=' . MAIL_HOST . '&csrf_token=' . anyToken($rootJar, ip(1)), null, ip(1));
    ok(
        'nothing is done by a GET, even a global administrator\'s with every field in the address: the page is drawn and nothing changes',
        $get['status'] === 200 && untouched() === $before,
        'HTTP ' . $get['status']
    );
    // THE CONTROL: the same fields, from a global administrator, with a token, DO act. Without this the three checks above could pass because the fields were wrong.
    $acted = press($rootJar, ip(1), ['action' => 'queue_delete'] + $fields['queue_delete']);
    ok(
        'THE CONTROL: the very same fields, pressed by a global administrator with a form token, do act (the waiting message is deleted)',
        $acted['kind'] === 'success' && stateOf('C5 waiting') === 'gone',
        $acted['flash']
    );
}

// =============================================================================
if (part('C2', 'The certificate put right: the job\'s next run sends the held messages with nobody pressing anything') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'C2 first', 'Its words: ' . $bodyMark);
    send(mailOf('other'), 'C2 second', 'Its words: ' . $bodyMark);
    send(mailOf('third'), 'C2 third', 'Its words: ' . $bodyMark);
    $stillBad = job();
    $mail     = mailSince('switch');
    ok(
        'while the certificate is still wrong, a run of the job tries ONE checked connection, sends nothing, and leaves all three held',
        ($stillBad['sent'] ?? -1) === 0 && $mail['private'] === 0 && count(queued("status = 'held' AND holdReason = 'certificate'")) === 3,
        json_encode($stillBad) . '; the mail server was given ' . $mail['private'] . ' private line(s)'
    );
    showCertificate('good');
    $ran  = job();
    $mail = mailSince('switch');
    sort($mail['messages']);
    ok(
        'the certificate is put right and the job comes round: all three messages are sent, through the good certificate, with nobody pressing anything, and the fault is off the record',
        ($ran['sent'] ?? 0) === 3 && count($mail['messages']) === 3 && array_unique($mail['shown']) === ['good'] && queued() === []
            && (one("SELECT problem FROM tblMailRouteState WHERE routeKey = 'installation'")['problem'] ?? 'x') === '',
        'the job said ' . json_encode($ran) . '; arrived: ' . json_encode(subjects($mail))
    );
    $page   = emailPage($rootJar, ip(1));
    $health = healthLine();
    ok(
        'and the three pages stop saying so: no certificate notice or card on the e-mail page, none on the dashboard, and the health line is green',
        str_contains($page, 'data-mail-warning') === false && str_contains($page, 'id="mail-certificate"') === false
            && str_contains(req($rootJar, 'GET', '/admin', null, ip(1))['body'], 'mail-certificate-') === false && $health['state'] === 'ok',
        'health: ' . json_encode($health)
    );
}

// =============================================================================
if (part('N1', '"Accept only this certificate": mail goes while the server shows it; a different certificate holds mail again and says why') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'N1 held, then let go', 'Its words: ' . $bodyMark);
    mailSince('switch');
    $page  = emailPage($rootJar, ip(1));
    $shown = fingerprintOnPage($page);
    $warn  = preg_match('#data-mail-warning-text="accept">(.*?)</div>#s', $page, $m) === 1 ? words($m[1]) : '';
    ok(
        'the warning beside "Accept only this certificate" says, in plain words, what is being decided and what could happen: who would receive the password and could read and change the mail, reset links included; and how to be sure first',
        str_contains($warn, 'that the certificate above really is your mail server’s own') === true && str_contains($warn, 'The portal cannot tell you that.') === true
            && str_contains($warn, 'that person receives the mailbox’s user name and password, and can read and change every e-mail the portal sends') === true
            && str_contains($warn, 'password-reset links, which let whoever holds one take over the account it is for, an administrator’s included') === true
            && str_contains($warn, 'ask whoever runs the mail server to read you the SHA-256 fingerprint') === true && str_contains($warn, 'Checking switches itself back on') === true,
        $warn
    );
    $before  = untouched();
    $noTick  = press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => $shown]);
    $another = press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => fingerprintOf('expired2'), 'understood' => '1']);
    ok(
        'it is refused without the tick, and for a fingerprint that is not the certificate on the page: nothing is stored',
        $noTick['kind'] === 'danger' && $another['kind'] === 'danger' && str_contains($another['flash'], 'not the one this page showed you') === true && untouched() === $before,
        json_encode([$noTick['flash'], $another['flash']])
    );
    $mark     = logMark();
    $accepted = press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => $shown, 'understood' => '1']);
    $record   = logged('MailCertificateAccepted', $mark);
    ok(
        'accepted: the setting holds that one fingerprint, the moment is stored in UTC, the held message is back in the queue, and the activity log names the administrator, the fingerprint and what the check had found',
        $accepted['kind'] === 'success' && stored('mail.smtp.certCheck') === 'only:' . fingerprintOf('expired') && preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/', stored('mail.smtp.certCheckOffAt')) === 1
            && stateOf('N1 held, then let go') === 'queued' && count($record) === 1 && (int) $record[0]['userID'] === $rootId && (int) $record[0]['age'] < 120
            && str_contains((string) $record[0]['activityDescription'], fingerprintOf('expired')) === true && str_contains((string) $record[0]['activityDescription'], 'The certificate expired on') === true,
        $accepted['flash'] . ' | stored: ' . stored('mail.smtp.certCheck') . ' | ' . json_encode($record)
    );
    $ran  = job();
    $mail = mailSince('switch');
    $now  = send(mailOf('other'), 'N1 sent at once', 'Its words: ' . $bodyMark);
    $more = mailSince('switch');
    $waysUsed['accept one'] = count($mail['messages']) === 1 && count($more['messages']) === 1;
    ok(
        'mail goes while the server shows that certificate: the job sends the held message, and a new message goes at once; every sign-in was over encryption',
        ($ran['sent'] ?? 0) === 1 && subjects($mail) === ['N1 held, then let go'] && $now[1] === 'sent' && subjects($more) === ['N1 sent at once'] && $mail['clearSignIns'] + $more['clearSignIns'] === 0
            && stored('mail.smtp.certCheck') === 'only:' . fingerprintOf('expired'),
        'the job said ' . json_encode($ran) . '; ' . json_encode([subjects($mail), $now, subjects($more)])
    );
    $at      = stored('mail.smtp.certCheckOffAt');
    $when    = 'Set on ' . shownInZone($at);
    $expect  = $when . ' by ' . $rootName . '.';
    $warning = warningOnPage(emailPage($secondJar, ip(3)), 'one-certificate');
    $forOrg  = emailPage($orgJar, ip(4));
    $dash    = req($secondJar, 'GET', '/admin', null, ip(3))['body'];
    $health  = healthLine();
    ok(
        'the standing warning is on all three pages, for ANOTHER global administrator who did not set it, with the date in the organisation\'s zone: the e-mail page and the admin dashboard also say who set it; the health page (amber, with its own label) and an organisation\'s administrator are shown the date and NOT the name',
        str_contains($warning, 'One certificate is accepted without the full check') === true && str_contains($warning, $expect) === true && str_contains($warning, fingerprintOf('expired')) === true
            && preg_match('#id="mail-certificate-unchecked" data-mail-warning="one-certificate">(.*?)</div>#s', $dash, $m) === 1 && str_contains(words($m[1]), $expect) === true
            && $health['state'] === 'warn' && $health['label'] === 'One certificate is accepted without the full check'
            && str_contains($health['detail'], $when . '.') === true && str_contains($health['detail'], $rootName) === false
            && str_contains(warningOnPage($forOrg, 'one-certificate'), $when . '.') === true && str_contains($forOrg, $rootName) === false && str_contains($forOrg, 'value="cert_on"') === false,
        'expected "' . $expect . '" | e-mail page: ' . $warning . ' | an organisation\'s administrator: ' . warningOnPage($forOrg, 'one-certificate') . ' | health: ' . json_encode($health)
    );
    // 🔁 The server starts showing a DIFFERENT certificate.
    showCertificate('expired2');
    $held = send(mailOf('third'), 'N1 held again', 'Its words: ' . $bodyMark);
    $mail = mailSince('switch');
    $row  = rowOf('N1 held again');
    $page = emailPage($rootJar, ip(1));
    $dash = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    ok(
        'a DIFFERENT certificate holds mail again, and says why: the message is held, the mail server that showed it is given no sign-in and no message, and the e-mail page and the dashboard say the server showed a different certificate from the one approved',
        $held[1] === 'held' && $row !== null && $row['status'] === 'held' && $row['holdReason'] === 'certificate' && $mail['private'] === 0 && in_array('expired2', $mail['shown'], true) === true
            && str_contains((string) $row['lastError'], 'The mail server showed a different certificate from the one that was approved') === true
            && str_contains(warningOnPage($page, 'certificate'), 'The mail server showed a different certificate from the one that was approved') === true
            && fingerprintOnPage($page) === fingerprintOf('expired2') && str_contains($dash, 'id="mail-certificate-failed"') === true && str_contains($dash, 'id="mail-certificate-unchecked"') === true,
        json_encode($held) . ' | the row: ' . json_encode($row) . ' | the mail server was given ' . $mail['private'] . ' private line(s)'
    );
    // The accepted certificate comes back.
    showCertificate('expired');
    $ran  = job();
    $mail = mailSince('switch');
    ok(
        'when the server shows the ACCEPTED certificate again, the job sends the held message by itself and the fault is off the record; the setting still holds that one fingerprint',
        ($ran['sent'] ?? 0) === 1 && subjects($mail) === ['N1 held again'] && (one("SELECT problem FROM tblMailRouteState WHERE routeKey = 'installation'")['problem'] ?? 'x') === ''
            && stored('mail.smtp.certCheck') === 'only:' . fingerprintOf('expired'),
        'the job said ' . json_encode($ran) . '; arrived ' . json_encode(subjects($mail))
    );
}

// =============================================================================
if (part('N2', 'Checking switches itself back ON when the certificate is good again, from "one certificate" and from "off", with its line in the activity log') === true) {
// =============================================================================
    // (a) From "one certificate", with NOTHING in the queue: the job's run alone finds out.
    showCertificate('expired');
    send(mailOf('member'), 'N2 to put a fault on record', 'Its words: ' . $bodyMark);
    press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => fingerprintOnPage(emailPage($rootJar, ip(1))), 'understood' => '1']);
    job();
    mailSince('switch');
    $wasOnly = stored('mail.smtp.certCheck');
    $idle    = job(); // the certificate is still the expired one: nothing changes
    $stayed  = stored('mail.smtp.certCheck');
    showCertificate('good');
    $mark   = logMark();
    $ran    = job();
    $mail   = mailSince('switch');
    $record = logged('MailCertificateCheckBackOn', $mark);
    ok(
        'from "one certificate", with nothing waiting: while the certificate is still the failing one a run of the job changes nothing; once it is good, the next run switches checking back ON, empties the stored moment, and says so in the activity log (as the portal\'s own act, under no account)',
        $wasOnly === 'only:' . fingerprintOf('expired') && $stayed === $wasOnly && queued() === [] && stored('mail.smtp.certCheck') === 'on' && stored('mail.smtp.certCheckOffAt') === ''
            && count($record) === 1 && $record[0]['userID'] === null && str_contains((string) $record[0]['activityDescription'], 'switched itself back ON') === true
            && str_contains((string) $record[0]['activityDescription'], 'One certificate had been accepted (fingerprint ' . fingerprintOf('expired') . ')') === true,
        'before: ' . $wasOnly . ', after an idle run: ' . $stayed . ', after the good certificate: ' . stored('mail.smtp.certCheck') . ' | ' . json_encode($record)
    );
    ok(
        'asking "would it pass now?" gave the mail server nothing private: no sign-in, no address, no message, on any of those runs',
        $mail['private'] === 0 && $mail['connections'] >= 2,
        'the mail server saw ' . $mail['connections'] . ' connection(s) and ' . $mail['private'] . ' private line(s)'
    );
    $page = emailPage($secondJar, ip(3));
    ok(
        'the standing warning is gone from all three pages',
        str_contains($page, 'data-mail-warning') === false && str_contains(req($secondJar, 'GET', '/admin', null, ip(3))['body'], 'mail-certificate-unchecked') === false && healthLine()['state'] === 'ok',
        'health: ' . json_encode(healthLine())
    );
    // (b) From "off", found out by a PAGE that sends (no job run in between).
    showCertificate('expired');
    send(mailOf('member'), 'N2 held, to switch off for', 'Its words: ' . $bodyMark);
    $off = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]);
    job();
    mailSince('switch');
    $wasOff = stored('mail.smtp.certCheck');
    showCertificate('good');
    $mark   = logMark();
    $sent   = send(mailOf('other'), 'N2 sent as checking came back', 'Its words: ' . $bodyMark);
    $mail   = mailSince('switch');
    $record = logged('MailCertificateCheckBackOn', $mark);
    ok(
        'from "off": a page sends a message once the certificate is good. Checking switches itself back ON first, the message then goes CHECKED through the good certificate, and the activity log says checking had been switched off',
        $off['kind'] === 'warning' && $wasOff === 'off' && $sent[1] === 'sent' && stored('mail.smtp.certCheck') === 'on' && stored('mail.smtp.certCheckOffAt') === ''
            && subjects($mail) === ['N2 sent as checking came back'] && array_unique($mail['shown']) === ['good'] && count($record) === 1 && $record[0]['userID'] === null
            && str_contains((string) $record[0]['activityDescription'], 'Checking had been switched off.') === true,
        'before: ' . $wasOff . ', after: ' . stored('mail.smtp.certCheck') . ' | ' . json_encode($sent) . ' | ' . json_encode($record)
    );
    // (c) "Cannot tell" changes nothing: the mail server cannot be reached.
    showCertificate('expired');
    send(mailOf('member'), 'N2 held, to switch off for again', 'Its words: ' . $bodyMark);
    press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]);
    setting('mail.smtp.port', (string) closedPort());
    $mark = logMark();
    job();
    ok(
        'a mail server that cannot be reached is "cannot tell", and changes nothing: checking stays off, and no line is written saying it came back on',
        stored('mail.smtp.certCheck') === 'off' && logged('MailCertificateCheckBackOn', $mark) === [],
        'stored: ' . stored('mail.smtp.certCheck')
    );
}

// =============================================================================
if (part('C4', '"Switch off": refused without the typed server name; afterwards held mail goes, the standing warning is on all three pages, and switching back on removes it') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'C4 first', 'Its words: ' . $bodyMark);
    send(mailOf('other'), 'C4 second', 'Its words: ' . $bodyMark);
    mailSince('switch');
    $page = emailPage($rootJar, ip(1));
    $warn = preg_match('#data-mail-warning-text="off">(.*?)</div>#s', $page, $m) === 1 ? words($m[1]) : '';
    ok(
        'the warning beside "Switch certificate checking off" says, in plain words, what could actually happen: the portal cannot tell the mail server from an impostor; somebody on the path could read or change every e-mail, reset links included, and capture the mailbox\'s password; nothing would show it; and to use "Accept only this certificate" instead',
        str_contains($warn, 'the portal cannot tell your mail server from an impostor') === true && str_contains($warn, 'whatever answers at ' . MAIL_HOST) === true
            && str_contains($warn, 'could then read or change every e-mail the portal sends, including password-reset links') === true
            && str_contains($warn, 'capture the mailbox’s user name and password') === true && str_contains($warn, 'Nothing on any page would show that it had happened.') === true
            && str_contains($warn, 'Use “Accept only this certificate” instead if you can.') === true && str_contains($warn, 'Checking switches itself back on') === true,
        $warn
    );
    $before = untouched();
    $wrong  = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => 'mail.example.org']);
    $empty  = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => '']);
    $none   = press($rootJar, ip(1), ['action' => 'cert_off']);
    ok(
        'it is refused with the wrong server name typed, with none typed, and with the box missing: checking stays on and the messages stay held',
        $wrong['kind'] === 'danger' && $empty['kind'] === 'danger' && $none['kind'] === 'danger' && str_contains($wrong['flash'], 'type the mail server\'s name exactly') === true && untouched() === $before,
        json_encode([$wrong['flash'], $empty['flash'], $none['flash']]) . '; stored: ' . stored('mail.smtp.certCheck')
    );
    $mark   = logMark();
    $off    = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => ' LocalHost ']);
    $record = logged('MailCertificateCheckOff', $mark);
    $at     = stored('mail.smtp.certCheckOffAt');
    ok(
        'with the server\'s name typed (capitals and stray spaces are forgiven) checking is switched off: the setting says off, the moment is stored in UTC, the held messages are back in the queue, and the activity log names who, when and the server',
        $off['kind'] === 'warning' && stored('mail.smtp.certCheck') === 'off' && preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/', $at) === 1 && abs(strtotime($at . ' UTC') - time()) < 120
            && stateOf('C4 first') === 'queued' && stateOf('C4 second') === 'queued' && count($record) === 1 && (int) $record[0]['userID'] === $rootId && (int) $record[0]['age'] < 120
            && str_contains((string) $record[0]['activityDescription'], 'Switched certificate checking OFF for the mail server ' . MAIL_HOST) === true,
        $off['flash'] . ' | stored: ' . stored('mail.smtp.certCheck') . ' at ' . $at . ' | ' . json_encode($record)
    );
    $ranNow = press($rootJar, ip(1), ['action' => 'queue_run']);
    $mail   = mailSince('switch');
    sort($mail['messages']);
    $waysUsed['off'] = count($mail['messages']) === 2;
    ok(
        'held mail then goes ("Send waiting mail now"): both messages arrive through the expired certificate, each sign-in AFTER encryption was on, and the certificate was read first on a connection that was given nothing',
        $ranNow['kind'] === 'success' && count($mail['messages']) === 2 && $mail['clearSignIns'] === 0 && $mail['signIns'] === 1 && array_unique($mail['shown']) === ['expired'] && queued() === [],
        $ranNow['flash'] . ' | arrived: ' . json_encode(subjects($mail)) . ' | sign-ins in the clear: ' . $mail['clearSignIns']
    );
    $when    = 'Set on ' . shownInZone($at);
    $expect  = $when . ' by ' . $rootName . '.';
    $warning = warningOnPage(emailPage($secondJar, ip(3)), 'checking-off');
    $forOrg  = emailPage($orgJar, ip(4));
    $dash    = req($secondJar, 'GET', '/admin', null, ip(3))['body'];
    $dashOrg = req($orgJar, 'GET', '/admin', null, ip(4))['body'];
    $health  = healthLine();
    ok(
        'the standing warning is on the e-mail page for ANOTHER global administrator, in a browser that never pressed the button, with the date (in the organisation\'s zone, zone named) and who set it; an organisation\'s administrator is shown it too, with the date, WITHOUT the name and without the button',
        str_contains($warning, 'Certificate checking is OFF') === true && str_contains($warning, 'cannot tell the mail server from an impostor') === true && str_contains($warning, $expect) === true
            && preg_match('/ E[SD]T by /', $warning) === 1 && str_contains(emailPage($secondJar, ip(3)), 'value="cert_on"') === true
            && str_contains(warningOnPage($forOrg, 'checking-off'), $when . '.') === true && str_contains($forOrg, $rootName) === false && str_contains($forOrg, 'value="cert_on"') === false,
        'expected "' . $expect . '" | shown: ' . $warning . ' | to an organisation\'s administrator: ' . warningOnPage($forOrg, 'checking-off')
    );
    ok(
        'it is on the admin dashboard for that other global administrator, with the name (and not for an organisation\'s administrator), and on the health page, amber, labelled "Certificate checking is OFF", with the same date and NOBODY NAMED: every organisation\'s administrator and an outside monitor read that line',
        preg_match('#id="mail-certificate-unchecked" data-mail-warning="checking-off">(.*?)</div>#s', $dash, $m) === 1 && str_contains(words($m[1]), $expect) === true
            && str_contains($dashOrg, 'mail-certificate-unchecked') === false && $health['state'] === 'warn' && $health['label'] === 'Certificate checking is OFF'
            && str_contains($health['detail'], 'cannot tell the mail server from an impostor') === true && str_contains($health['detail'], $when . '.') === true
            && str_contains($health['detail'], $rootName) === false && str_contains((string) json_encode($health), 'Selftest pages') === false,
        'health: ' . json_encode($health)
    );
    $mark   = logMark();
    $on     = press($secondJar, ip(3), ['action' => 'cert_on']);
    $record = logged('MailCertificateCheckOn', $mark);
    $held   = send(mailOf('third'), 'C4 held once checking is back', 'Its words: ' . $bodyMark);
    $mail   = mailSince('switch');
    ok(
        'switching back on (by the OTHER global administrator) removes the warning from all three pages, is logged under that administrator, and the next message is checked again: it is held, and the mail server is given nothing',
        $on['kind'] === 'success' && stored('mail.smtp.certCheck') === 'on' && stored('mail.smtp.certCheckOffAt') === '' && count($record) === 1 && (int) $record[0]['userID'] === PEOPLE['second']
            && str_contains(emailPage($rootJar, ip(1)), 'data-mail-warning="checking-off"') === false && str_contains(req($rootJar, 'GET', '/admin', null, ip(1))['body'], 'mail-certificate-unchecked') === false
            && str_contains(healthLine()['detail'], 'switched OFF') === false && $held[1] === 'held' && $mail['private'] === 0,
        $on['flash'] . ' | stored: ' . stored('mail.smtp.certCheck') . ' | the next message: ' . json_encode($held)
    );
    $noFault = press($rootJar, ip(1), ['action' => 'cert_on']);
    removeMail();
    jobRunning();
    $none = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]);
    ok(
        'checking cannot be switched off while NO certificate is failing (a form kept open from an earlier fault): refused, and it stays on',
        $none['kind'] === 'danger' && str_contains($none['flash'], 'no certificate fault is on record') === true && stored('mail.smtp.certCheck') === 'on' && $noFault['kind'] === 'success',
        $none['flash']
    );
}

// =============================================================================
if (part('C6', 'With checking off, a server with no encryption is still refused, and the password never reaches it') === true) {
// =============================================================================
    setting('mail.smtp.certCheck', 'off');
    // THE CONTROL: with checking off, a server with a FAILING certificate and encryption is sent through. So what follows is about encryption, not about the mode.
    showCertificate('expired');
    $control = send(mailOf('member'), 'C6 the control', 'Its words: ' . $bodyMark);
    $mail    = mailSince('switch');
    ok(
        'THE CONTROL: checking is off, and a message does go through a server whose certificate has expired (so checking really is off for what follows)',
        $control[1] === 'sent' && subjects($mail) === ['C6 the control'] && $mail['clearSignIns'] === 0,
        json_encode($control)
    );
    useServer('no-encryption');
    $first = send(mailOf('member'), 'C6 to a server that lists no encryption', 'Its words: ' . $bodyMark);
    $seen1 = mailSince('no-encryption');
    setting('mail.smtp.security', 'tls');
    useServer('plain-only');
    $second = send(mailOf('other'), 'C6 to a server that speaks none', 'Its words: ' . $bodyMark);
    $seen2  = mailSince('plain-only');
    ok(
        'a server that does not offer encryption, and one that speaks none at all, are both refused with checking off: the message is kept to try later, and neither server is given a sign-in, an address or a message',
        $first[1] === 'queued' && $second[1] === 'queued' && $seen1['connections'] >= 1 && $seen2['connections'] >= 1 && $seen1['private'] === 0 && $seen2['private'] === 0
            && $seen1['signIns'] + $seen2['signIns'] === 0 && $seen1['messages'] === [] && $seen2['messages'] === [],
        json_encode([$first, $second]) . '; private lines: ' . $seen1['private'] . ' and ' . $seen2['private']
    );
    $logs  = (string) file_get_contents($standinDir . $sep . 'log' . $sep . 'no-encryption.log') . (string) file_get_contents($standinDir . $sep . 'log' . $sep . 'plain-only.log');
    $forms = [$mailPassword, base64_encode($mailPassword), base64_encode("\0" . MAILBOX_USER . "\0" . $mailPassword), addcslashes($mailPassword, "\0..\37\\")];
    ok(
        'the mailbox\'s password is in nothing either server was sent, in any form (as typed, or as a sign-in carries it), and both logs are there to be searched',
        strlen($logs) > 50 && array_filter($forms, static fn (string $form): bool => str_contains($logs, $form) === true) === [] && str_contains($logs, MAILBOX_USER) === false,
        strlen($logs) . ' bytes searched'
    );
    ok(
        'and checking is still off afterwards: a server that offers no usable encryption is "cannot tell", which changes nothing',
        stored('mail.smtp.certCheck') === 'off',
        'stored: ' . stored('mail.smtp.certCheck')
    );
    $bad = press($rootJar, ip(1), [
        'action' => 'save_smtp', 'smtpHost' => MAIL_HOST, 'smtpPort' => (string) $state['ports']['plain-only'], 'smtpSecurity' => 'none', 'smtpUsername' => MAILBOX_USER,
        'smtpPassword' => '', 'fromAddress' => SENDER, 'fromName' => 'Selftest Pages Office', 'replyTo' => '', 'perHour' => '',
    ]);
    ok(
        '"no encryption" cannot be chosen on the e-mail page either, with checking off: the save is refused and the stored kind of encryption does not change',
        str_contains(words($bad['page']), 'Choose one of the two kinds of encryption. The portal does not send e-mail without encryption.') === true && stored('mail.smtp.security') === 'tls',
        'stored: ' . stored('mail.smtp.security')
    );
}

// =============================================================================
if (part('C9', 'The general Settings page refuses the certificate setting, for everybody; a value put in the database by hand still shows the standing warning') === true) {
// =============================================================================
    $rowId = (int) one("SELECT settingID FROM tblSettings WHERE settingKey = 'mail.smtp.certCheck' AND siteID IS NULL")['settingID'];
    $atId  = (int) one("SELECT settingID FROM tblSettings WHERE settingKey = 'mail.smtp.certCheckOffAt' AND siteID IS NULL")['settingID'];
    /** Save one setting through the general Settings page, as the browser in `$jar`; what the page then says. */
    $save = static function (string $jar, string $ip, int $id, string $key, string $value): array {
        $answer = req($jar, 'POST', '/settings/save', ['csrf_token' => anyToken($jar, $ip), 'settingID' => (string) $id, 'settingKey' => $key, 'settingValue' => $value], $ip);

        return ['status' => $answer['status'], 'said' => settingsFlash($jar, $ip)];
    };
    $told    = 'That setting is changed at Admin, Integrations, Email, and nowhere else.';
    $before  = [stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')];
    $mark    = logMark();
    $asOrg   = $save($orgJar, ip(4), $rowId, 'mail.smtp.certCheck', 'off');
    $asRoot  = $save($secondJar, ip(3), $rowId, 'mail.smtp.certCheck', 'off');
    $asOne   = $save($secondJar, ip(3), $rowId, 'mail.smtp.certCheck', 'only:' . fingerprintOf('expired'));
    $moment  = $save($secondJar, ip(3), $atId, 'mail.smtp.certCheckOffAt', '2020-01-01 00:00:00');
    $refused = logged('SettingsUpdateRefused', $mark);
    ok(
        'NOBODY can change the certificate setting on the general Settings page: an organisation\'s administrator and a global administrator are both refused ("off", "accept this certificate" and the stored moment alike), each is told where it IS changed, both rows stay as they were, nothing is logged as an update, and every refusal is in the activity log under the account that tried',
        $before === ['on', ''] && [stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')] === $before
            && $asOrg['said'][0] === 'danger' && str_contains($asOrg['said'][1], $told) === true && $asRoot['said'][0] === 'danger' && str_contains($asRoot['said'][1], $told) === true
            && str_contains($asOne['said'][1], $told) === true && str_contains($moment['said'][1], $told) === true && str_contains($asRoot['said'][1], 'Nothing was changed.') === true
            && count($refused) === 4 && array_map('intval', array_column($refused, 'userID')) === [PEOPLE['orgadmin'], PEOPLE['second'], PEOPLE['second'], PEOPLE['second']]
            && str_contains((string) $refused[1]['activityDescription'], '"mail.smtp.certCheck" is changed on the e-mail page') === true && logged('SettingsUpdate', $mark) === [],
        'stored: ' . json_encode([stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')]) . ' | said: ' . json_encode([$asOrg['said'], $asRoot['said'], $asOne['said'], $moment['said']]) . ' | refusals logged: ' . count($refused)
    );
    // Deleting the row, and adding it again (for this organisation, and under other capitals), are refused as well.
    $mark   = logMark();
    $delete = req($secondJar, 'POST', '/settings', ['csrf_token' => anyToken($secondJar, ip(3)), 'action' => 'delete', 'settingID' => (string) $rowId], ip(3));
    $saidD  = settingsFlash($secondJar, ip(3));
    $add    = req($secondJar, 'POST', '/settings/save', ['csrf_token' => anyToken($secondJar, ip(3)), 'settingKey' => 'mail.smtp.certCheck', 'settingValue' => 'off'], ip(3));
    $saidA  = settingsFlash($secondJar, ip(3));
    $addCap = req($orgJar, 'POST', '/settings/save', ['csrf_token' => anyToken($orgJar, ip(4)), 'settingKey' => 'MAIL.SMTP.CertCheck', 'settingValue' => 'off'], ip(4));
    $saidC  = settingsFlash($orgJar, ip(4));
    $rows   = (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'mail.smtp.certCheck'")['n'];
    ok(
        'it cannot be DELETED there, or ADDED there (as an organisation\'s own row, or under other capital letters) either: each is refused in the same words, the installation\'s row is still the only one and still says "on", and each refusal is logged',
        str_contains($saidD[1], $told) === true && str_contains($saidA[1], $told) === true && str_contains($saidC[1], $told) === true && $rows === 1 && stored('mail.smtp.certCheck') === 'on'
            && count(logged('SettingsDeleteRefused', $mark)) === 1 && count(logged('SettingsInsertRefused', $mark)) === 2 && logged('SettingsDelete', $mark) === [] && logged('SettingsInsert', $mark) === [],
        'rows of that name: ' . $rows . ' | said: ' . json_encode([$saidD, $saidA, $saidC]) . ' | HTTP ' . $delete['status'] . ', ' . $add['status'] . ', ' . $addCap['status']
    );
    $forRoot = settingsPage($secondJar, ip(3));
    $forOrg  = settingsPage($orgJar, ip(4));
    $drawn   = [];
    foreach (['mail.smtp.host', 'mail.smtp.certCheck', 'mail.smtp.certCheckOffAt'] as $key) {
        $row     = settingsRow($forRoot, $key);
        $rowOrg  = settingsRow($forOrg, $key);
        $drawn[] = $row !== '' && str_contains($row, '#editModal') === false && str_contains($row, 'value="delete"') === false
            && str_contains($row, 'href="' . EMAIL_PAGE . '"') === true && str_contains(words('<i ' . $row), 'Changed at Admin, Integrations, Email') === true
            && $rowOrg !== '' && str_contains($rowOrg, '#editModal') === false && str_contains($rowOrg, 'value="delete"') === false
            && str_contains(words('<i ' . $rowOrg), 'Changed by a global administrator, at Admin, Integrations, Email') === true;
    }
    $control = settingsRow($forRoot, 'mail.smtp.port');
    ok(
        'the general Settings page draws NO Edit and NO Delete control for the mail server\'s name and the two certificate rows, and puts a link to the e-mail page in their place, for a global administrator and for an organisation\'s administrator. THE CONTROL: the row beside them (the port) still has both',
        $drawn === [true, true, true] && str_contains($control, '#editModal') === true && str_contains($control, 'value="delete"') === true,
        'the three rows: ' . json_encode($drawn) . ' | the control row has an Edit button: ' . json_encode(str_contains($control, '#editModal'))
    );
    // 🗄️ A value put there straight in the database, which no page can stop. The warning is read from what is STORED, so it shows all the same.
    setting('mail.smtp.certCheck', 'off');
    $warning = warningOnPage(emailPage($rootJar, ip(1)), 'checking-off');
    $dash    = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    $health  = healthLine();
    ok(
        'a value put in the DATABASE by hand shows the SAME standing warning on the e-mail page, the admin dashboard and the health page, and names nobody: it says the portal has no record of who set it or when (no page set it, so no moment is stored and no line is in the activity log)',
        str_contains($warning, 'Certificate checking is OFF') === true && str_contains($warning, 'cannot tell the mail server from an impostor') === true
            && str_contains($warning, 'The portal has no record of who set this or when.') === true && str_contains($warning, ' by ') === false
            && preg_match('#id="mail-certificate-unchecked" data-mail-warning="checking-off">(.*?)</div>#s', $dash, $m) === 1 && str_contains(words($m[1]), 'The portal has no record of who set this or when.') === true
            && $health['state'] === 'warn' && $health['label'] === 'Certificate checking is OFF' && str_contains($health['detail'], 'The portal has no record of when this was set.') === true,
        'e-mail page: ' . $warning . ' | health: ' . json_encode($health)
    );
    showCertificate('expired');
    $sent = send(mailOf('member'), 'C9 sent with checking off', 'Its words: ' . $bodyMark);
    $mail = mailSince('switch');
    ok(
        'and it is really in force, not only shown: a message goes through a server whose certificate has expired',
        $sent[1] === 'sent' && subjects($mail) === ['C9 sent with checking off'] && $mail['clearSignIns'] === 0,
        json_encode($sent)
    );
    setting('mail.smtp.certCheck', 'disabled');
    $odd  = send(mailOf('other'), 'C9 a value the portal does not know', 'Its words: ' . $bodyMark);
    $mail = mailSince('switch');
    ok(
        'a value the portal does not know ("disabled", put in the database by hand) counts as ON, never as off: the next message is checked and held, the mail server is given nothing, and no page claims checking is off',
        stored('mail.smtp.certCheck') === 'disabled' && $odd[1] === 'held' && $mail['private'] === 0 && str_contains(emailPage($rootJar, ip(1)), 'data-mail-warning="checking-off"') === false,
        json_encode($odd) . '; stored: ' . stored('mail.smtp.certCheck')
    );
}

// =============================================================================
if (part('S1', 'With checking off, the mail server\'s name cannot be changed on the general Settings page: nothing changes and the stored password is offered to no other server') === true) {
// =============================================================================
    // Checking is switched OFF the real way, on the e-mail page, for a server whose certificate has expired.
    showCertificate('expired');
    send(mailOf('member'), 'S1 held, to switch off for', 'Its words: ' . $bodyMark);
    $off = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]);
    job();
    mailSince('switch');
    mailSince('other-name');
    $hostId = (int) one("SELECT settingID FROM tblSettings WHERE settingKey = 'mail.smtp.host' AND siteID IS NULL")['settingID'];
    $five   = static fn (): array => [stored('mail.smtp.host'), stored('mail.smtp.port'), stored('mail.smtp.password'), stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')];
    $was    = $five();
    $told   = 'That setting is changed at Admin, Integrations, Email, and nowhere else.';
    $mark   = logMark();
    $try    = req($secondJar, 'POST', '/settings/save', ['csrf_token' => anyToken($secondJar, ip(3)), 'settingID' => (string) $hostId, 'settingKey' => 'mail.smtp.host', 'settingValue' => '127.0.0.1'], ip(3));
    $said   = settingsFlash($secondJar, ip(3));
    $record = logged('SettingsUpdateRefused', $mark);
    ok(
        'with checking OFF, a global administrator tries to change the mail server\'s name on the general Settings page: it is refused in words that say where the name IS changed, NOTHING is changed (the name, the port, the stored password, the certificate setting and its moment are byte for byte as they were), and the refusal is in the activity log under their account',
        $off['kind'] === 'warning' && $was[0] === MAIL_HOST && $was[3] === 'off' && $try['status'] === 302 && $said[0] === 'danger' && str_contains($said[1], $told) === true && str_contains($said[1], 'Nothing was changed.') === true
            && $five() === $was && count($record) === 1 && (int) $record[0]['userID'] === PEOPLE['second']
            && str_contains((string) $record[0]['activityDescription'], '"mail.smtp.host" is changed on the e-mail page') === true && logged('SettingsUpdate', $mark) === [],
        'said: ' . json_encode($said) . ' | the name is now "' . stored('mail.smtp.host') . '" | the certificate setting is "' . stored('mail.smtp.certCheck') . '" | ' . json_encode($record)
    );
    note('S1, as seen. Before the try: name "' . $was[0] . '", port ' . $was[1] . ', certificate setting "' . $was[3] . '" since ' . $was[4] . ' UTC, a stored password of ' . strlen($was[2]) . ' encrypted characters. '
        . 'Tried on the general Settings page: name -> "127.0.0.1". The page answered HTTP ' . $try['status'] . ' and then said (' . $said[0] . '): "' . $said[1] . '" '
        . 'After: name "' . stored('mail.smtp.host') . '", port ' . stored('mail.smtp.port') . ', certificate setting "' . stored('mail.smtp.certCheck') . '", the stored password ' . (stored('mail.smtp.password') === $was[2] ? 'the same bytes' : 'CHANGED')
        . '. The activity log: ' . json_encode(array_column($record, 'activityDescription')));
    // The same for deleting the name, and for adding it again as an organisation's own row.
    $mark  = logMark();
    req($secondJar, 'POST', '/settings', ['csrf_token' => anyToken($secondJar, ip(3)), 'action' => 'delete', 'settingID' => (string) $hostId], ip(3));
    $saidD = settingsFlash($secondJar, ip(3));
    req($secondJar, 'POST', '/settings/save', ['csrf_token' => anyToken($secondJar, ip(3)), 'settingKey' => 'Mail.Smtp.Host', 'settingValue' => '127.0.0.1'], ip(3));
    $saidA = settingsFlash($secondJar, ip(3));
    $rows  = (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE settingKey = 'mail.smtp.host'")['n'];
    ok(
        'nor can the name be DELETED there, or ADDED there as an organisation\'s own row (under other capital letters): both are refused in the same words, the installation\'s row is still the only one, and nothing has changed',
        str_contains($saidD[1], $told) === true && str_contains($saidA[1], $told) === true && $rows === 1 && $five() === $was
            && count(logged('SettingsDeleteRefused', $mark)) === 1 && count(logged('SettingsInsertRefused', $mark)) === 1,
        'rows of that name: ' . $rows . ' | said: ' . json_encode([$saidD, $saidA])
    );
    // 🔑 Where does the stored password go now? A test is sent: the page shows the conversation, which names the server that was connected to.
    $test  = press($rootJar, ip(1), ['action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp']);
    $mail  = mailSince('switch');
    $other = mailSince('other-name');
    ok(
        'the stored password is offered to NO OTHER SERVER: a test sent straight afterwards connects under the name the password was saved for (read from the conversation the page shows), signs in there once, and the server under the other name is not connected to at all',
        str_contains($test['page'], 'data-mail-test="sent"') === true && str_contains($test['page'], 'Connected to ' . MAIL_HOST . ' on port ' . $state['ports']['switch'] . '.') === true
            && str_contains($test['page'], 'Connected to 127.0.0.1') === false && $mail['signIns'] === 1 && count($mail['messages']) === 1 && $mail['clearSignIns'] === 0 && $other['connections'] === 0,
        'the server named before saw ' . $mail['signIns'] . ' sign-in(s) and ' . count($mail['messages']) . ' message(s); the other saw ' . $other['connections'] . ' connection(s); "Connected to 127.0.0.1" on the page: ' . json_encode(str_contains($test['page'], 'Connected to 127.0.0.1'))
    );
    note('S1, as seen. The test sent straight afterwards: the page\'s conversation says "' . (preg_match('/Connected to [^<]+/', $test['page'], $m) === 1 ? $m[0] : '(no such line)') . '"; that server saw '
        . $mail['connections'] . ' connection(s), ' . $mail['signIns'] . ' sign-in(s), all after encryption was on (' . $mail['clearSignIns'] . ' in the clear), and ' . count($mail['messages']) . ' message(s); the server under the other name saw '
        . $other['connections'] . ' connection(s) and ' . $other['signIns'] . ' sign-in(s).');
    // 📧 The e-mail page is where the name IS changed, and it changes the two things with it. The NAME ONLY changes here: the port stays.
    $form = static function (array $over) use (&$state): array {
        return $over + [
            'action' => 'save_smtp', 'smtpHost' => '127.0.0.1', 'smtpPort' => (string) $state['ports']['switch'], 'smtpSecurity' => 'starttls', 'smtpUsername' => MAILBOX_USER,
            'smtpPassword' => '', 'fromAddress' => SENDER, 'fromName' => 'Selftest Pages Office', 'replyTo' => '', 'perHour' => '',
        ];
    };
    $kept   = press($rootJar, ip(1), $form([]));
    $errors = preg_match('#data-mail-form-errors>(.*?)</div>#s', $kept['page'], $m) === 1 ? words($m[1]) : '';
    $stayed = $five() === $was;
    $mark   = logMark();
    $moved  = press($rootJar, ip(1), $form(['smtpPassword' => $mailPassword]));
    $now    = $five();
    $record = logged('MailCertificateCheckOn', $mark);
    ok(
        'on the E-MAIL PAGE the same change of name (the port left as it is) needs the password typed again, and puts checking back on: with the box empty it is refused and nothing is saved; with the password typed the name is saved, a newly encrypted password is stored, checking is ON with its moment emptied, the page says so, and it is logged under that administrator',
        str_contains($errors, 'The mail server\'s name has changed, so type the mailbox\'s password again') === true && $stayed === true
            && $moved['kind'] === 'success' && $now[0] === '127.0.0.1' && $now[1] === $was[1] && $now[2] !== $was[2] && $now[2] !== '' && [$now[3], $now[4]] === ['on', '']
            && str_contains($moved['flash'], 'Certificate checking is back ON') === true && count($record) === 1 && (int) $record[0]['userID'] === $rootId
            && str_contains((string) $record[0]['activityDescription'], 'the mail server\'s name was changed') === true,
        'with the box empty: ' . $errors . ' | with the password: ' . $moved['flash'] . ' | stored now: ' . json_encode([$now[0], $now[1], $now[3], $now[4]])
    );
    note('S1, as seen. On the e-mail page, name -> "127.0.0.1" with the password box empty, the page said: "' . $errors . '" and nothing was saved (' . json_encode($stayed) . '). With the password typed it said: "' . $moved['flash']
        . '" After: name "' . $now[0] . '", port ' . $now[1] . ', certificate setting "' . $now[3] . '", its moment "' . $now[4] . '", the stored password ' . ($now[2] !== $was[2] ? 'newly encrypted' : 'THE SAME BYTES')
        . '. The activity log: ' . json_encode(array_column($record, 'activityDescription')));
}

// =============================================================================
if (part('W1', 'Who set it, and when: the stored moment is shown when the log\'s line has gone, and nobody is named for a change they did not make') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'W1 held, to accept for', 'Its words: ' . $bodyMark);
    $mark = logMark();
    press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => fingerprintOnPage(emailPage($rootJar, ip(1))), 'understood' => '1']);
    $at    = stored('mail.smtp.certCheckOffAt');
    $named = warningOnPage(emailPage($secondJar, ip(3)), 'one-certificate');
    // 🗑️ The activity log's line is cleared out, as the retention job does once it is a year old. The accepted certificate stays in force.
    q('DELETE FROM tblActivityLogs WHERE activityType = ? AND logID > ?', 'si', ['MailCertificateAccepted', $mark]);
    $gone   = warningOnPage(emailPage($secondJar, ip(3)), 'one-certificate');
    $health = healthLine();
    $stored = 'The moment stored beside this setting says it was set on ' . shownInZone($at) . '.';
    ok(
        'once the activity log\'s line has been cleared out, the standing warning STILL shows the moment stored beside the setting (in the organisation\'s zone) and says only that who set it is not on record. It used to say the portal had no record of when. THE CONTROL: while the line was there, the same page named the administrator',
        preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/', $at) === 1 && str_contains($named, 'Set on ' . shownInZone($at) . ' by ' . $rootName . '.') === true
            && str_contains($gone, $stored) === true && str_contains($gone, 'Who set it is not on record') === true && str_contains($gone, 'no record of who set this or when') === false
            && str_contains($gone, $rootName) === false && str_contains($health['detail'], $stored) === true && str_contains($health['detail'], 'no record of when') === false,
        'with the line: ' . $named . ' | without it: ' . $gone . ' | health: ' . $health['detail']
    );
    // 🗄️ An EARLIER change by an administrator, and then the value changed in the database: root switches checking off on the page...
    press($rootJar, ip(1), ['action' => 'cert_on']);
    removeMail();
    jobRunning();
    send(mailOf('member'), 'W1 held, to switch off for', 'Its words: ' . $bodyMark);
    $off     = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]);
    $control = warningOnPage(emailPage($secondJar, ip(3)), 'checking-off');
    // ...then, by SQL, the value becomes "accept this certificate". The moment beside it is still the one root's change stored.
    setting('mail.smtp.certCheck', 'only:' . fingerprintOf('expired'));
    $otherKind = warningOnPage(emailPage($secondJar, ip(3)), 'one-certificate');
    // ...and then "off" again by SQL, with a moment ten minutes away from root's line: the same KIND of change as the newest line, and not the same change.
    setting('mail.smtp.certCheck', 'off');
    setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s', time() - 600));
    $otherTime = warningOnPage(emailPage($secondJar, ip(3)), 'checking-off');
    $dash      = req($secondJar, 'GET', '/admin', null, ip(3))['body'];
    ok(
        'after a value is changed in the DATABASE, the administrator of an EARLIER change is not named for it: not when what is stored is another kind of decision than their line records, and not when it is the same kind with a moment that is not their line\'s. Each says who set it is not on record. THE CONTROL: before the database was touched, the page named them',
        $off['kind'] === 'warning' && str_contains($control, ' by ' . $rootName . '.') === true
            && str_contains($otherKind, 'One certificate is accepted') === true && str_contains($otherKind, $rootName) === false && str_contains($otherKind, 'Who set it is not on record') === true
            && str_contains($otherTime, 'Certificate checking is OFF') === true && str_contains($otherTime, $rootName) === false && str_contains($otherTime, 'Who set it is not on record') === true
            && preg_match('#id="mail-certificate-unchecked"[^>]*>(.*?)</div>#s', $dash, $m) === 1 && str_contains(words($m[1]), 'Who set it is not on record') === true && str_contains(words($m[1]), $rootName) === false,
        'the control: ' . $control . ' | another kind: ' . $otherKind . ' | another moment: ' . $otherTime
    );
}

// =============================================================================
if (part('G1', '"Send this one" pressed after the certificate was put right: the good certificate is not put on record as a fault, the page says what happened, and mail goes') === true) {
// =============================================================================
    showCertificate('expired');
    send(mailOf('member'), 'G1 the one pressed', 'Its words: ' . $bodyMark);
    send(mailOf('other'), 'G1 held with it', 'Its words: ' . $bodyMark);
    mailSince('switch');
    $one   = (int) (rowOf('G1 the one pressed')['queueID'] ?? 0);
    $shown = fingerprintOnPage(emailPage($rootJar, ip(1)));
    // 🔧 The certificate is put right AFTER the page was drawn and before the button is pressed.
    showCertificate('good');
    $pressed = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $one, 'fingerprint' => $shown, 'understood' => '1']);
    $mail    = mailSince('switch');
    $record  = one("SELECT problem, certSha256 FROM tblMailRouteState WHERE routeKey = 'installation'");
    $dash    = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    ok(
        'nothing is sent past the check (the server no longer shows the certificate that was approved, and is given no sign-in and no message), the GOOD certificate is NOT put on record as a fault, the page says what happened, both messages are back in the queue, and no certificate card, no way past the check and no "No e-mail is going out" notice is shown',
        $shown === fingerprintOf('expired') && $pressed['kind'] === 'warning' && str_contains($pressed['flash'], 'the mail server now shows a certificate that passes the full check') === true
            && str_contains($pressed['flash'], 'The certificate fault is off the record.') === true && $mail['private'] === 0 && $mail['messages'] === [] && in_array('good', $mail['shown'], true) === true
            && ($record['problem'] ?? 'x') === '' && ($record['certSha256'] ?? 'x') === '' && stateOf('G1 the one pressed') === 'queued' && stateOf('G1 held with it') === 'queued'
            && str_contains($pressed['page'], 'id="mail-certificate"') === false && str_contains($pressed['page'], 'data-mail-warning="certificate"') === false
            && str_contains($pressed['page'], 'value="cert_off"') === false && str_contains($dash, 'mail-certificate-failed') === false && stored('mail.smtp.certCheck') === 'on',
        'the page said: ' . $pressed['kind'] . ': ' . $pressed['flash'] . ' | on record: ' . json_encode($record) . ' | the two messages: ' . stateOf('G1 the one pressed') . ', ' . stateOf('G1 held with it')
    );
    $new  = send(mailOf('third'), 'G1 new mail, sent at once', 'Its words: ' . $bodyMark);
    $ran  = job();
    $mail = mailSince('switch');
    $got  = subjects($mail);
    sort($got);
    ok(
        'and mail goes, CHECKED: a new message is tried and sent at once (it used to be held without the server being tried), and the job\'s next run sends the two that were held, all three through the good certificate, with nobody pressing anything more',
        $new[1] === 'sent' && ($ran['sent'] ?? 0) === 2 && $got === ['G1 held with it', 'G1 new mail, sent at once', 'G1 the one pressed'] && array_unique($mail['shown']) === ['good']
            && $mail['clearSignIns'] === 0 && queued() === [],
        'the new message: ' . json_encode($new) . ' | the job said ' . json_encode($ran) . ' | arrived: ' . json_encode($got)
    );
}

// =============================================================================
if (part('O1', 'The certificate setting is the installation\'s alone: an organisation\'s own row of the same name is never read') === true) {
// =============================================================================
    // An organisation's own rows, which only SQL can put there (the general Settings page refuses to add them: part C9).
    showCertificate('expired');
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (1, 'mail.smtp.certCheck', 'off', '', 0), (1, 'mail.smtp.certCheckOffAt', ?, '', 0)", 's', [gmdate('Y-m-d H:i:s')]);
    $held   = send(mailOf('member'), 'O1 with an organisation row saying off', 'Its words: ' . $bodyMark);
    $mail   = mailSince('switch');
    $facts  = child(['task' => 'facts']);
    $pages  = emailPage($orgJar, ip(4)) . emailPage($rootJar, ip(1)) . req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    $health = healthLine();
    ok(
        'with the INSTALLATION\'S value "on" and an organisation\'s own row saying "off", the organisation\'s row is not read: a message sent for that organisation, while the mail server shows an expired certificate, is held, and the mail server is given nothing; the portal\'s one reader of the setting answers "on"; and no page shows a standing warning',
        (int) one("SELECT COUNT(*) AS n FROM tblSettings WHERE siteID = 1 AND settingKey = 'mail.smtp.certCheck' AND settingValue = 'off'")['n'] === 1 && stored('mail.smtp.certCheck') === 'on'
            && $held[1] === 'held' && $mail['private'] === 0 && $mail['messages'] === [] && ($facts['check']['mode'] ?? '') === 'on'
            && str_contains($pages, 'data-mail-warning="checking-off"') === false && str_contains($pages, 'mail-certificate-unchecked') === false && $health['label'] !== 'Certificate checking is OFF',
        json_encode($held) . ' | the mail server was given ' . $mail['private'] . ' private line(s) | the portal reads: ' . json_encode($facts['check'] ?? null) . ' | health: ' . $health['label']
    );
    q("UPDATE tblSettings SET settingValue = ? WHERE siteID = 1 AND settingKey = 'mail.smtp.certCheck'", 's', ['only:' . fingerprintOf('expired')]);
    $ran   = job();
    $mail  = mailSince('switch');
    $facts = child(['task' => 'facts']);
    ok(
        'nor is an organisation\'s own row saying "accept this certificate" (the very certificate the server shows): a run of the job sends nothing, gives the mail server nothing, and the message stays held',
        ($ran['sent'] ?? -1) === 0 && $mail['private'] === 0 && $mail['messages'] === [] && stateOf('O1 with an organisation row saying off') === 'held/certificate' && ($facts['check']['mode'] ?? '') === 'on',
        'the job said ' . json_encode($ran) . ' | the portal reads: ' . json_encode($facts['check'] ?? null)
    );
    q("DELETE FROM tblSettings WHERE siteID = 1 AND settingKey IN ('mail.smtp.certCheck', 'mail.smtp.certCheckOffAt')");
}

// =============================================================================
if (part('R1', 'A fault found while TESTING the SMTP mailbox does not stop or hold mail that goes by another route; with SMTP in use it applies as before') === true) {
// =============================================================================
    /** Three messages kept for the job, as a page keeps them. */
    $threeWaiting = static function (string $tag) use ($bodyMark): array {
        $subjects = [];
        foreach (['member', 'other', 'third'] as $who) {
            $subjects[] = 'R1 ' . $tag . ', for ' . $who;
            send(mailOf($who), 'R1 ' . $tag . ', for ' . $who, 'Its words: ' . $bodyMark, ['sendNow' => false]);
        }
        sort($subjects);

        return $subjects;
    };
    /** "Send test" through the SMTP mailbox, as a global administrator presses it; what the page then shows. */
    $testSmtp = static function () use ($rootJar): array {
        $page = press($rootJar, ip(1), ['action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp'])['page'];

        return ['sent' => str_contains($page, 'data-mail-test="sent"'), 'why' => preg_match('#data-mail-test-reason>(.*?)</p>#s', $page, $m) === 1 ? words($m[1]) : '', 'page' => $page];
    };
    // 🧭 Microsoft 365 is the route in use, and is set up: the stand-in answers for it. The scheduled job is running.
    useMs365();
    graphSince();
    $first = $threeWaiting('first');
    // 🔐 The SMTP mailbox is being set up, and is TESTED before the portal is switched to it. Its mail server refuses the password.
    useServer('other-name');
    mailSince('other-name');
    $test   = $testSmtp();
    $seen   = mailSince('other-name');
    $record = onRecord();
    $health = healthLine();
    $ran    = job();
    $went   = graphSince();
    ok(
        'Microsoft 365 is the route in use and three messages wait for the job. An SMTP "Send test" is REFUSED ITS SIGN-IN (THE CONTROL: the refusal is real, and is on record for the mailbox). It stops nothing: the health line is not critical, the job\'s next run sends all three by Microsoft 365, none is held or left waiting, and the SMTP mailbox is not signed in to again',
        $test['sent'] === false && str_contains($test['why'], 'refused the user name and password') === true && $seen['signIns'] === 1 && $record['problem'] === 'sign-in-refused'
            && count(queued("status = 'queued'")) + count($went) === 3 && $health['state'] !== 'crit' && $health['label'] !== 'Mail is waiting'
            && ($ran['sent'] ?? 0) === 3 && ($ran['stopped'] ?? 1) === 0 && graphSubjects($went) === $first && queued() === [] && mailSince('other-name')['signIns'] === 0,
        'the test: ' . $test['why'] . ' | on record: ' . json_encode($record) . ' | health: ' . $health['state'] . ' / ' . $health['label'] . ' | the job said ' . json_encode($ran) . ' | Microsoft 365 was given: ' . json_encode(graphSubjects($went)) . ' | left in the queue: ' . json_encode(array_map(static fn (array $r): string => $r['status'] . '/' . $r['holdReason'], queued()))
    );
    note('R1, as seen. Route in use: ' . stored('mail.provider') . '. The SMTP "Send test" said: "' . mb_substr($test['why'], 0, 200) . '" On record for the SMTP mailbox: ' . json_encode($record) . '. Health line: '
        . $health['state'] . ' / ' . $health['label'] . '. The job then said: ' . json_encode(array_intersect_key($ran, array_flip(['sent', 'held', 'stopped', 'leftQueued', 'leftHeld']))) . '. Microsoft 365\'s stand-in was given '
        . count($went) . ' message(s): ' . json_encode(graphSubjects($went)) . '.');
    // 🪪 The next test FAILS ITS CERTIFICATE: the mailbox's server shows one that has expired.
    $second = $threeWaiting('second');
    useServer('switch');
    showCertificate('expired');
    mailSince('switch');
    $test    = $testSmtp();
    $seen    = mailSince('switch');
    $record  = onRecord();
    $waiting = array_map(static fn (array $r): string => $r['status'] . ($r['holdReason'] === '' ? '' : '/' . $r['holdReason']), queued());
    $health  = healthLine();
    $dash    = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    ok(
        'then an SMTP "Send test" FAILS ITS CERTIFICATE (THE CONTROL: the fault is on record, with the fingerprint of the certificate the server showed, and the server was given nothing private). NO queued message is held for it, the admin dashboard does not say "No e-mail is going out", and the health line is not critical',
        $test['sent'] === false && str_contains($test['why'], 'The certificate expired on') === true && $seen['private'] === 0 && $record['problem'] === 'certificate' && $record['certSha256'] === fingerprintOf('expired')
            && $waiting === ['queued', 'queued', 'queued'] && str_contains($dash, 'mail-certificate-failed') === false && $health['state'] !== 'crit' && $health['label'] !== 'Mail is held',
        'the test: ' . $test['why'] . ' | on record: ' . json_encode($record) . ' | the three: ' . json_encode($waiting) . ' | health: ' . $health['state'] . ' / ' . $health['label']
    );
    $page   = emailPage($rootJar, ip(1));
    $notice = warningOnPage($page, 'certificate-not-in-use');
    $forOrg = emailPage($orgJar, ip(4));
    ok(
        'the e-mail page SHOWS what that test found, so that it can be decided about before switching: a notice that says first that no e-mail is held and which route is in use, then what the check found; the certificate card with its fingerprint; and "accept only this certificate" and "switch off". It does not say mail is being held, and offers no "send one held message", on the card or on any row. An organisation\'s administrator is shown the notice, and no card',
        str_contains($notice, 'No e-mail is held because of this.') === true && str_contains($notice, 'e-mail is going out through Microsoft 365') === true && str_contains($notice, 'The certificate expired on') === true
            && warningOnPage($page, 'certificate') === '' && fingerprintOnPage($page) === fingerprintOf('expired') && str_contains($page, 'data-mail-ways="before-switching"') === true
            && str_contains($page, 'data-mail-way="accept"') === true && str_contains($page, 'data-mail-way="off"') === true && str_contains($page, 'data-mail-way="one-message"') === false
            && str_contains($page, 'value="queue_send_past_check"') === false && str_contains(words($page), 'so mail is being held') === false
            && str_contains(words($page), 'No mail is held for it: the SMTP mailbox is not the route in use.') === true
            && str_contains(warningOnPage($forOrg, 'certificate-not-in-use'), 'No e-mail is held because of this.') === true && str_contains($forOrg, 'id="mail-certificate"') === false,
        'the notice: ' . ($notice === '' ? 'NOT ON THE PAGE' : $notice) . ' | the fingerprint on the page: ' . fingerprintOnPage($page)
    );
    $ran  = job();
    $went = graphSince();
    ok(
        'and the job\'s next run sends all three by Microsoft 365: none is held, none is left waiting, and the SMTP mail server is not contacted. The fault stays on record for the mailbox, where it belongs',
        ($ran['sent'] ?? 0) === 3 && ($ran['stopped'] ?? 1) === 0 && ($ran['held'] ?? 1) === 0 && graphSubjects($went) === $second && queued() === []
            && mailSince('switch')['connections'] === 0 && onRecord()['problem'] === 'certificate',
        'the job said ' . json_encode($ran) . ' | Microsoft 365 was given: ' . json_encode(graphSubjects($went)) . ' | left in the queue: ' . count(queued())
    );
    note('R1, as seen. Route in use: ' . stored('mail.provider') . '. After the SMTP "Send test" that failed its certificate: on record ' . json_encode($record) . '; the three waiting messages were ' . json_encode($waiting)
        . '; health line ' . $health['state'] . ' / ' . $health['label'] . '. The job then said: ' . json_encode(array_intersect_key($ran, array_flip(['sent', 'held', 'stopped', 'leftQueued', 'leftHeld'])))
        . '. Microsoft 365\'s stand-in was given ' . count($went) . ' message(s): ' . json_encode(graphSubjects($went)) . '.');
    // Two more readers of the record. A message held because nobody can tell whether it went is sent again by hand; and a
    // message that is still held "for the certificate" (left so by a route changed in the database) is found by the job.
    $unsure = addRow([mailOf('member')], 'R1 nobody can tell', ['status' => 'held', 'holdReason' => 'unknown-if-sent']);
    $stale  = addRow([mailOf('other')], 'R1 still held for the certificate', ['status' => 'held', 'holdReason' => 'certificate']);
    $again  = press($rootJar, ip(1), ['action' => 'queue_send_again', 'queueId' => (string) $unsure, 'understood' => '1']);
    $ran    = job();
    $went   = graphSince();
    ok(
        '"Send it again" is not refused for the SMTP mailbox\'s fault while another route is in use: the message goes by Microsoft 365. And a message still held "for the certificate" is put back in the queue by the job\'s next run and goes by Microsoft 365 too: nothing stays held for a fault that does not apply',
        $unsure > 0 && $stale > 0 && $again['kind'] === 'success' && ($ran['sent'] ?? 0) === 1 && graphSubjects($went) === ['R1 nobody can tell', 'R1 still held for the certificate'] && queued() === []
            && onRecord()['problem'] === 'certificate',
        '"Send it again" said: ' . $again['kind'] . ': ' . $again['flash'] . ' | the job said ' . json_encode($ran) . ' | Microsoft 365 was given: ' . json_encode(graphSubjects($went)) . ' | left: ' . json_encode(array_map(static fn (array $r): string => $r['subject'] . ' ' . $r['status'] . '/' . $r['holdReason'], queued()))
    );
    // ✅ DECIDED BEFORE SWITCHING. "Accept only this certificate", pressed while Microsoft 365 is still the route in use.
    $mark     = logMark();
    $accepted = press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => fingerprintOnPage($page), 'understood' => '1']);
    $record   = logged('MailCertificateAccepted', $mark);
    mailSince('switch');
    $test     = $testSmtp();
    $seen     = mailSince('switch');
    $warning  = warningOnPage(emailPage($rootJar, ip(1)), 'one-certificate');
    ok(
        'the certificate can be ACCEPTED before the portal is switched to SMTP: the setting holds that one fingerprint, it is logged under the administrator, the page says it is in force for a "Send test" now and for all mail after a switch, the fault is off the record, the standing warning shows and says it applies to the SMTP mailbox; and a "Send test" through the SMTP mailbox now GOES, through that certificate, signed in after encryption',
        $accepted['kind'] === 'success' && str_contains($accepted['flash'], 'The SMTP mailbox is not the route in use, so no mail was held for this.') === true
            && stored('mail.smtp.certCheck') === 'only:' . fingerprintOf('expired') && count($record) === 1 && (int) $record[0]['userID'] === $rootId && onRecord()['problem'] === ''
            && str_contains($warning, 'This applies to the SMTP mailbox, which is not the route in use now') === true
            && $test['sent'] === true && $seen['signIns'] === 1 && $seen['clearSignIns'] === 0 && count($seen['messages']) === 1 && array_unique($seen['shown']) === ['expired'] && stored('mail.provider') === 'ms365',
        'the press: ' . $accepted['kind'] . ': ' . $accepted['flash'] . ' | stored: ' . stored('mail.smtp.certCheck') . ' | the test was sent: ' . json_encode($test['sent']) . ' ' . $test['why']
    );
    // ...and "switch off", the same way: checking back on, the test fails again (so a fault is on record), then off by the typed name.
    press($rootJar, ip(1), ['action' => 'cert_on']);
    $testSmtp();
    $mark   = logMark();
    $off    = press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]);
    $record = logged('MailCertificateCheckOff', $mark);
    $wasOff = stored('mail.smtp.certCheck');
    $test   = $testSmtp();
    press($rootJar, ip(1), ['action' => 'cert_on']);
    ok(
        'checking can be SWITCHED OFF before the switch too, by the same typed server name and with a certificate fault on record: it is stored, logged under the administrator, the page says it is in force for a "Send test" now, and a test then goes. Switching back on works as before',
        $off['kind'] === 'warning' && str_contains($off['flash'], 'The SMTP mailbox is not the route in use, so no mail was held for this.') === true && $wasOff === 'off'
            && count($record) === 1 && (int) $record[0]['userID'] === $rootId && $test['sent'] === true && stored('mail.smtp.certCheck') === 'on' && stored('mail.provider') === 'ms365',
        'the press: ' . $off['kind'] . ': ' . $off['flash'] . ' | stored then: ' . $wasOff . ' | the test was sent: ' . json_encode($test['sent'])
    );
    // 🔀 THE SWITCH TO SMTP, WITH A TEST'S FAULT ON RECORD. Checking is on; the test fails its certificate again; three wait.
    $test   = $testSmtp();
    $before = onRecord();
    $third  = $threeWaiting('third');
    mailSince('switch');
    graphSince();
    $switch      = press($rootJar, ip(1), ['action' => 'set_route', 'route' => 'smtp']);
    $atTheSwitch = onRecord();
    $silent       = mailSince('switch');
    $firstSend   = send(mailOf('member'), 'R1 the first message after the switch', 'Its words: ' . $bodyMark);
    $seen        = mailSince('switch');
    $record      = onRecord();
    $states      = array_values(array_unique(array_map(static fn (array $r): string => $r['status'] . '/' . $r['holdReason'], queued())));
    $health      = healthLine();
    $dash        = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    $ran         = job();
    $pageNow     = emailPage($rootJar, ip(1));
    ok(
        'WHEN THE PORTAL IS SWITCHED TO SMTP with that test\'s fault on record, the certificate is never trusted: the record was made under the other route, so it is cleared at the switch (no mail server is contacted by switching); the VERY FIRST message then finds the certificate again on a checked connection, is HELD, and gives the mail server nothing private; the fault is on record again with the same fingerprint, the three that waited are held with it, the dashboard says no e-mail is going out, the health line is critical, and the job sends nothing by either route',
        $test['sent'] === false && $before['problem'] === 'certificate' && $switch['kind'] === 'success' && stored('mail.provider') === 'smtp' && $atTheSwitch['problem'] === '' && $silent['connections'] === 0
            && $firstSend[1] === 'held' && $seen['connections'] >= 1 && $seen['private'] === 0 && $seen['messages'] === []
            && $record['problem'] === 'certificate' && $record['certSha256'] === fingerprintOf('expired') && $states === ['held/certificate'] && count(queued()) === 4
            && str_contains($dash, 'id="mail-certificate-failed"') === true && $health['state'] === 'crit' && $health['label'] === 'Mail is held'
            && ($ran['sent'] ?? -1) === 0 && graphSince() === [] && mailSince('switch')['private'] === 0
            && warningOnPage($pageNow, 'certificate') !== '' && str_contains($pageNow, 'data-mail-way="one-message"') === true && fingerprintOnPage($pageNow) === fingerprintOf('expired'),
        'before the switch: ' . json_encode($before) . ' | straight after it: ' . json_encode($atTheSwitch) . ' | the first message: ' . json_encode($firstSend) . ', the mail server was given ' . $seen['private'] . ' private line(s) | on record: ' . json_encode($record)
            . ' | the queue: ' . json_encode($states) . ' | health: ' . $health['state'] . ' / ' . $health['label'] . ' | the job said ' . json_encode($ran)
    );
    note('R1, as seen. THE SWITCH TO SMTP. On record before it: ' . json_encode($before) . '. Straight after it: ' . json_encode($atTheSwitch) . ', and the mail server saw ' . $silent['connections'] . ' connection(s). The first message: '
        . json_encode($firstSend) . '; the mail server saw ' . $seen['connections'] . ' connection(s) and was given ' . $seen['private'] . ' private line(s). On record after it: ' . json_encode($record) . '. The queue: ' . count(queued()) . ' message(s), all '
        . json_encode($states) . '. Health line: ' . $health['state'] . ' / ' . $health['label'] . '. The job said: ' . json_encode(array_intersect_key($ran, array_flip(['sent', 'held', 'stopped', 'leftQueued', 'leftHeld']))) . '.');
    // 🔁 THE SAME REFUSED SIGN-IN, NOW WITH SMTP AS THE ROUTE IN USE: it applies as it always has.
    removeMail();
    jobRunning();
    showCertificate('good');
    useServer('other-name');
    $fourth = $threeWaiting('fourth');
    mailSince('other-name');
    graphSince();
    $test   = $testSmtp();
    $seen   = mailSince('other-name');
    $health = healthLine();
    $ran    = job();
    ok(
        'THE SAME, WITH SMTP AS THE ROUTE IN USE, applies as before: after a "Send test" that is refused its sign-in, the job\'s next run is stopped (nothing is sent by either route, the three still wait, and the refused password is not offered again), and the health line is critical',
        stored('mail.provider') === 'smtp' && $test['sent'] === false && $seen['signIns'] === 1 && onRecord()['problem'] === 'sign-in-refused'
            && ($ran['sent'] ?? -1) === 0 && ($ran['stopped'] ?? 0) === 1 && count(queued("status = 'queued'")) === 3 && count($fourth) === 3
            && $health['state'] === 'crit' && $health['label'] === 'Mail is waiting' && mailSince('other-name')['signIns'] === 0 && graphSince() === [],
        'the test: ' . $test['why'] . ' | health: ' . $health['state'] . ' / ' . $health['label'] . ' | the job said ' . json_encode($ran) . ' | still waiting: ' . count(queued("status = 'queued'"))
    );
}

// =============================================================================
if (part('N3', 'A held message of each other reason is sent again, with the words about a possible second copy; one is deleted; one that is too old is not sent') === true) {
// =============================================================================
    $ids = [
        'interrupted'     => addRow([mailOf('member')], 'N3 interrupted', ['status' => 'held', 'holdReason' => 'interrupted', 'html' => '<p>' . $bodyMark . '</p>', 'text' => $bodyMark]),
        'unknown-if-sent' => addRow([mailOf('other')], 'N3 nobody can tell', ['status' => 'held', 'holdReason' => 'unknown-if-sent']),
        'restored'        => addRow([mailOf('member'), mailOf('other'), mailOf('third')], 'N3 restored, for three', ['status' => 'held', 'holdReason' => 'restored']),
    ];
    $old    = addRow([mailOf('third')], 'N3 too old to send', ['status' => 'held', 'holdReason' => 'unknown-if-sent']);
    $delete = addRow([mailOf('third')], 'N3 to be deleted', ['status' => 'held', 'holdReason' => 'restored']);
    later(49 * 3600, 'queueID = ' . $old);
    $page = emailPage($rootJar, ip(1));
    $copy = [];
    foreach ($ids as $reason => $id) {
        $copy[$reason] = preg_match('#data-mail-queue-second-copy>(.*?)</p>#s', rowOnPage($page, $id), $m) === 1 ? words($m[1]) : '';
    }
    ok(
        'each of the three is listed as held, with why in plain words, and "Send it again" says plainly that the person may get a SECOND COPY (or "each" of the three people), that a first copy cannot be taken back, and to ask first',
        str_contains(words(rowOnPage($page, $ids['interrupted'])), 'Held: the mail job was interrupted while handing it to the mail server, so nobody can tell whether it went.') === true
            && str_contains(words(rowOnPage($page, $ids['unknown-if-sent'])), 'Held: the whole message was handed to the mail server and no answer came back, so nobody can tell whether it went.') === true
            && str_contains(words(rowOnPage($page, $ids['restored'])), 'Held: it came back with a restored backup. It was waiting when the backup was taken, and may have been sent since.') === true
            && str_starts_with($copy['interrupted'], 'The person this is for may get a second copy.') === true && str_starts_with($copy['unknown-if-sent'], 'The person this is for may get a second copy.') === true
            && str_starts_with($copy['restored'], 'The 3 people this is for may each get a second copy.') === true
            && str_contains($copy['interrupted'], 'sending it again cannot take a first copy back') === true && str_contains($copy['interrupted'], 'ask whether it arrived before you send it again') === true,
        json_encode($copy)
    );
    $before = untouched();
    $noTick = press($rootJar, ip(1), ['action' => 'queue_send_again', 'queueId' => (string) $ids['interrupted']]);
    $mail   = mailSince('switch');
    ok(
        'without the tick that says "I understand the person may get a second copy" nothing is sent, and nothing changes',
        $noTick['kind'] === 'danger' && str_contains($noTick['flash'], 'may get a second copy') === true && $mail['connections'] === 0 && untouched() === $before,
        $noTick['flash']
    );
    $mark = logMark();
    $said = [];
    foreach ($ids as $reason => $id) {
        $said[$reason] = press($rootJar, ip(1), ['action' => 'queue_send_again', 'queueId' => (string) $id, 'understood' => '1']);
    }
    $mail   = mailSince('switch');
    $record = logged('MailQueueSentAgain', $mark);
    $got    = subjects($mail);
    sort($got);
    ok(
        'with the tick, each of the three is sent, ONE press each, and leaves the queue; the activity log has a line for each, under that administrator, saying it may have been a second copy',
        count(array_filter($said, static fn (array $a): bool => $a['kind'] === 'success')) === 3 && $got === ['N3 interrupted', 'N3 nobody can tell', 'N3 restored, for three']
            && stateOf('N3 interrupted') === 'gone' && stateOf('N3 nobody can tell') === 'gone' && stateOf('N3 restored, for three') === 'gone'
            && count($record) === 3 && array_unique(array_column($record, 'userID')) == [$rootId] && str_contains((string) $record[0]['activityDescription'], 'knowing it may be a second copy') === true,
        json_encode(array_map(static fn (array $a): string => $a['kind'] . ': ' . $a['flash'], $said)) . ' | arrived: ' . json_encode($got)
    );
    $tooOld = rowOnPage($page, $old);
    $forced = press($rootJar, ip(1), ['action' => 'queue_send_again', 'queueId' => (string) $old, 'understood' => '1']);
    $mail   = mailSince('switch');
    ok(
        'a held message older than the longest wait is never sent, whoever asks: the page says it is too old and offers no "Send it again", and a hand-made press gives it up in words with nothing sent',
        str_contains(words($tooOld), 'It is now too old to be sent, whoever asks.') === true && str_contains($tooOld, 'queue_send_again') === false && str_contains($tooOld, 'queue_delete') === true
            && $forced['kind'] !== 'success' && str_contains($forced['flash'], 'nothing that old is sent') === true && $mail['messages'] === [] && stateOf('N3 too old to send') === 'failed',
        'the row: ' . words($tooOld) . ' | the press: ' . $forced['flash']
    );
    $noTick  = press($rootJar, ip(1), ['action' => 'queue_delete', 'queueId' => (string) $delete]);
    $kept    = stateOf('N3 to be deleted');
    $mark    = logMark();
    $deleted = press($rootJar, ip(1), ['action' => 'queue_delete', 'queueId' => (string) $delete, 'understood' => '1']);
    $logLine = one("SELECT status, errorCode, errorDetail FROM tblEmailLog WHERE subject = 'N3 to be deleted' ORDER BY emailLogID DESC LIMIT 1");
    $record  = logged('MailQueueDeleted', $mark);
    $mail    = mailSince('switch');
    ok(
        'Delete needs its own tick; with it the message is removed and never sent, "Recent sends" has a line saying an administrator deleted it, and the activity log names who (and the message\'s number, not who it was for)',
        $noTick['kind'] === 'danger' && $kept === 'held/restored' && $deleted['kind'] === 'success' && stateOf('N3 to be deleted') === 'gone' && $mail['messages'] === []
            && $logLine !== null && $logLine['status'] === 'failed' && $logLine['errorCode'] === 'removed-by-hand' && count($record) === 1 && (int) $record[0]['userID'] === $rootId
            && str_contains((string) $record[0]['activityDescription'], 'Deleted message ' . $delete . ' ') === true && str_contains((string) $record[0]['activityDescription'], DOMAIN) === false,
        $deleted['flash'] . ' | the send log: ' . json_encode($logLine) . ' | ' . json_encode($record)
    );
    $failedRow = rowOnPage(emailPage($rootJar, ip(1)), $old);
    ok(
        'a message that was given up on can be deleted but is NOT offered for sending again, and the page says to send it again from where it was written',
        str_contains($failedRow, 'queue_delete') === true && str_contains($failedRow, 'queue_send_again') === false && str_contains(words($failedRow), 'is not sent again from here') === true,
        words($failedRow)
    );
}

// =============================================================================
if (part('N4', '"Send waiting mail now" sends what is waiting, and does not make the scheduled job look as if it runs') === true) {
// =============================================================================
    q('UPDATE tblMailRouteState SET jobLastRanAt = NULL'); // no scheduled job has ever run
    send(mailOf('member'), 'N4 first', 'Its words: ' . $bodyMark, ['sendNow' => false]);
    send(mailOf('other'), 'N4 second', 'Its words: ' . $bodyMark, ['sendNow' => false]);
    $held = addRow([mailOf('third')], 'N4 held, not for this button', ['status' => 'held', 'holdReason' => 'unknown-if-sent']);
    mailSince('switch');
    $before = healthLine();
    $mark   = logMark();
    $ran    = press($rootJar, ip(1), ['action' => 'queue_run']);
    $mail   = mailSince('switch');
    $got    = subjects($mail);
    sort($got);
    $record = logged('MailQueueRunByHand', $mark);
    ok(
        'it sends both waiting messages in one press, leaves the held one held, says how many went, and is logged under that administrator',
        $ran['kind'] === 'success' && $got === ['N4 first', 'N4 second'] && stateOf('N4 held, not for this button') === 'held/unknown-if-sent' && str_starts_with($ran['flash'], '2 sent in this run') === true
            && count($record) === 1 && (int) $record[0]['userID'] === $rootId && str_contains((string) $record[0]['activityDescription'], '2 sent') === true,
        $ran['flash'] . ' | arrived: ' . json_encode($got)
    );
    $after = healthLine();
    $facts = child(['task' => 'facts']);
    ok(
        'the portal does NOT now believe the scheduled job is running: no moment is recorded for it, the one question the portal asks about it still answers no, and the health line still says the job has never run',
        (one("SELECT jobLastRanAt FROM tblMailRouteState WHERE routeKey = 'installation'")['jobLastRanAt'] ?? null) === null && ($facts['jobIsRunning'] ?? true) === false
            && str_contains($before['detail'], 'has never run') === true && str_contains($after['detail'], 'has never run') === true,
        'before: ' . $before['detail'] . ' | after: ' . $after['detail'] . ' | the portal says running: ' . json_encode($facts['jobIsRunning'] ?? null)
    );
}

// =============================================================================
if (part('N5', 'The mail server\'s name changed without the password typed again is refused, and the stored password never reaches the new server') === true) {
// =============================================================================
    /** The SMTP form, filled in for the second server under its other name. */
    $form = static function (array $over) use (&$state): array {
        return $over + [
            'action' => 'save_smtp', 'smtpHost' => '127.0.0.1', 'smtpPort' => (string) $state['ports']['other-name'], 'smtpSecurity' => 'starttls', 'smtpUsername' => MAILBOX_USER,
            'smtpPassword' => '', 'fromAddress' => SENDER, 'fromName' => 'Selftest Pages Office', 'replyTo' => '', 'perHour' => '',
        ];
    };
    $page = emailPage($rootJar, ip(1));
    ok(
        'the form says so beside the server\'s name and beside the port: change either, and the password has to be typed again. It says what THIS PAGE does, and no longer claims that the portal never sends a stored password to another server',
        str_contains(words($page), 'If you change this name or the port, type the mailbox’s password again') === true
            && str_contains(words($page), 'this page does not keep a stored password for a different server name or port') === true
            && preg_match('#id="smtp-port-help">(.*?)</div>#s', $page, $m) === 1 && str_contains(words($m[1]), 'A changed port counts as a changed server') === true
            && str_contains(words($page), 'to a server it was not saved for') === false,
        ''
    );
    $was    = [stored('mail.smtp.host'), stored('mail.smtp.port'), stored('mail.smtp.password')];
    $kept   = press($rootJar, ip(1), $form([]));
    $errors = preg_match('#data-mail-form-errors>(.*?)</div>#s', $kept['page'], $m) === 1 ? words($m[1]) : '';
    ok(
        'a new server name and port with the password box left empty is REFUSED, in words that say why (and that claim no more than this page does), and nothing at all is saved: not the name, not the port',
        str_contains($errors, 'The mail server\'s name and port have changed, so type the mailbox\'s password again') === true
            && str_contains($errors, 'this page does not keep a stored password for a different server name or port') === true && str_contains($errors, 'it was not saved for') === false
            && [stored('mail.smtp.host'), stored('mail.smtp.port'), stored('mail.smtp.password')] === $was,
        'the page said: ' . $errors . ' | stored name: ' . stored('mail.smtp.host')
    );
    $test = press($rootJar, ip(1), ['action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp']);
    $new  = mailSince('other-name');
    $old  = mailSince('switch');
    ok(
        'the stored password never reaches the new server: a test sent straight afterwards goes to the server the password was saved for, and the new one is not contacted at all',
        $new['connections'] === 0 && $new['signIns'] === 0 && $old['signIns'] === 1 && count($old['messages']) === 1,
        'the new server saw ' . $new['connections'] . ' connection(s) and ' . $new['signIns'] . ' sign-in(s); the old one ' . $old['signIns'] . ' sign-in(s)'
    );
    // The same save with the name unchanged and the box empty keeps the password, as it always has.
    $same = press($rootJar, ip(1), $form(['smtpHost' => MAIL_HOST, 'smtpPort' => (string) $state['ports']['switch'], 'fromName' => 'Selftest Pages Office Two']));
    ok(
        'with the name UNCHANGED, leaving the box empty still keeps the stored password (the rule is about a new name only)',
        $same['kind'] === 'success' && stored('mail.smtp.password') === $was[2] && stored('mail.defaultFromName') === 'Selftest Pages Office Two',
        $same['flash']
    );
    // Checking is off (as if set while the old server's certificate was wrong), and the name is changed WITH the password typed.
    setting('mail.smtp.certCheck', 'off');
    setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s'));
    $mark   = logMark();
    $moved  = press($rootJar, ip(1), $form(['smtpPassword' => $otherPassword]));
    $record = logged('MailCertificateCheckOn', $mark);
    // Read NOW, before anything is sent: a send to this server (whose certificate is good) would switch checking
    // back on by itself, and this check is about the SAVE doing it.
    $checkAfterSave = [stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')];
    $test   = press($rootJar, ip(1), ['action' => 'test_send', 'to' => mailOf('member'), 'through' => 'smtp']);
    $new    = mailSince('other-name');
    ok(
        'with the password typed, the new name is saved and the new server is signed in to with the NEW password; and certificate checking, which had been off for the old server, goes back ON, is said on the page and is logged',
        $moved['kind'] === 'success' && stored('mail.smtp.host') === '127.0.0.1' && $new['signIns'] === 1 && count($new['messages']) === 1 && $new['clearSignIns'] === 0
            && $checkAfterSave === ['on', ''] && str_contains($moved['flash'], 'Certificate checking is back ON') === true
            && count($record) === 1 && (int) $record[0]['userID'] === $rootId && str_contains((string) $record[0]['activityDescription'], 'the mail server\'s name and port were changed') === true,
        $moved['flash'] . ' | stored: ' . stored('mail.smtp.host') . ', straight after the save: ' . json_encode($checkAfterSave) . ' | the new server: ' . $new['signIns'] . ' sign-in(s)'
    );
    $removed = press($rootJar, ip(1), $form(['smtpHost' => MAIL_HOST, 'smtpPort' => (string) $state['ports']['switch'], 'removePassword' => '1']));
    ok(
        'a new name with "remove the stored password" ticked is accepted too: the password is removed, so there is none to send anywhere',
        $removed['kind'] === 'success' && stored('mail.smtp.host') === MAIL_HOST && stored('mail.smtp.password') === '',
        $removed['flash']
    );
    // 🪪 ONE CERTIFICATE ACCEPTED (not "off") for the server set now, and then its NAME is changed, with the password typed.
    //    The accepted certificate was the old server's: it must not stay in force for the new name. (The port is left as it is.)
    setting('mail.smtp.certCheck', 'only:' . fingerprintOf('expired'));
    setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s'));
    $mark   = logMark();
    $back   = press($rootJar, ip(1), $form(['smtpPort' => (string) $state['ports']['switch'], 'smtpPassword' => $otherPassword]));
    $record = logged('MailCertificateCheckOn', $mark);
    ok(
        'a changed NAME puts checking back on from "one certificate accepted" too, not only from "off": straight after the save the setting is "on" with its moment emptied, the page says so, and it is logged. An accepted certificate is not carried over to another server',
        $back['kind'] === 'success' && stored('mail.smtp.host') === '127.0.0.1' && [stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')] === ['on', '']
            && str_contains($back['flash'], 'Certificate checking is back ON') === true && count($record) === 1 && str_contains((string) $record[0]['activityDescription'], 'the mail server\'s name was changed') === true,
        $back['flash'] . ' | stored: ' . json_encode([stored('mail.smtp.host'), stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')])
    );
    // 🔌 The PORT alone is changed, under the same name, with checking off. Another port is another program answering.
    setting('mail.smtp.certCheck', 'off');
    setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s'));
    $four   = static fn (): array => [stored('mail.smtp.host'), stored('mail.smtp.port'), stored('mail.smtp.password'), stored('mail.smtp.certCheck')];
    $was    = $four();
    $kept   = press($rootJar, ip(1), $form([]));
    $errors = preg_match('#data-mail-form-errors>(.*?)</div>#s', $kept['page'], $m) === 1 ? words($m[1]) : '';
    $stayed = $four() === $was;
    // THE CONTROL: the very same form with the port as it is stored is saved, box empty, and changes nothing about the certificate.
    $same   = press($rootJar, ip(1), $form(['smtpPort' => $was[1], 'fromName' => 'Selftest Pages Office Three']));
    $kept2  = $four() === $was;
    $mark   = logMark();
    $moved  = press($rootJar, ip(1), $form(['smtpPassword' => $otherPassword]));
    $record = logged('MailCertificateCheckOn', $mark);
    ok(
        'a changed PORT under the same name counts as a changed server: with the password box empty it is refused, in words, and nothing is saved (the stored password and "off" used to be kept). THE CONTROL: the same form with the port unchanged is saved and keeps both. With the password typed the new port is saved, checking goes back ON, and the log says it was the port',
        $was[0] === '127.0.0.1' && $was[1] === (string) $state['ports']['switch'] && $was[1] !== (string) $state['ports']['other-name'] && $was[3] === 'off'
            && str_contains($errors, 'The mail server\'s port has changed, so type the mailbox\'s password again') === true && $stayed === true
            && $same['kind'] === 'success' && str_contains($same['flash'], 'back ON') === false && $kept2 === true
            && $moved['kind'] === 'success' && stored('mail.smtp.port') === (string) $state['ports']['other-name'] && [stored('mail.smtp.certCheck'), stored('mail.smtp.certCheckOffAt')] === ['on', '']
            && str_contains($moved['flash'], 'Certificate checking is back ON') === true && count($record) === 1 && str_contains((string) $record[0]['activityDescription'], 'the mail server\'s port was changed') === true,
        'with the box empty: ' . $errors . ' | the same port: ' . $same['kind'] . ': ' . $same['flash'] . ' | with the password: ' . $moved['flash'] . ' | stored: ' . json_encode([stored('mail.smtp.port'), stored('mail.smtp.certCheck')])
    );
}

// =============================================================================
if (part('N6', 'What an organisation\'s administrator sees: their own organisation\'s messages, no button, none of the mail server\'s own words, no mailbox user name') === true) {
// =============================================================================
    $reply = 'Not sent. The mail server said: 550 5.7.1 <' . mailOf('other') . '> ' . $replyMark . ' relay denied for ' . MAILBOX_USER;
    $mine  = addRow([mailOf('member')], 'N6 this organisation, waiting', ['attempts' => 1, 'lastError' => $reply, 'waitSeconds' => 3600]);
    $unsure = addRow([mailOf('member')], 'N6 this organisation, held', ['status' => 'held', 'holdReason' => 'unknown-if-sent', 'lastError' => $reply]);
    $nobody = addRow(['elsewhere@' . DOMAIN], 'N6 belongs to no organisation', ['siteId' => null, 'attempts' => 1, 'lastError' => $reply, 'waitSeconds' => 3600]);
    $failed = addRow([mailOf('third')], 'N6 this organisation, given up on', ['attempts' => 2, 'lastError' => $reply]);
    q("UPDATE tblMailQueue SET status = 'failed' WHERE queueID = ?", 'i', [$failed]);
    q(
        "INSERT INTO tblEmailLog (siteID, provider, fromAddress, toRecipients, subject, status, errorCode, errorDetail, sentAtUtc) VALUES "
        . "(1, 'smtp', ?, ?, 'N6 log, failed', 'failed', 'server-said-no', ?, UTC_TIMESTAMP()), (1, 'smtp', ?, ?, 'N6 log, waiting', 'queued', 'timeout', ?, UTC_TIMESTAMP()), "
        . "(1, 'smtp', ?, ?, 'N6 log, held', 'held', 'certificate', ?, UTC_TIMESTAMP()), (1, 'smtp', ?, ?, 'N6 log, expired', 'expired', 'expired', ?, UTC_TIMESTAMP()), "
        . "(1, 'smtp', ?, ?, 'N6 log, sent', 'sent', '', '', UTC_TIMESTAMP())",
        'ssssssssssssss',
        [SENDER, mailOf('member'), $reply, SENDER, mailOf('member'), $reply, SENDER, mailOf('member'), $reply, SENDER, mailOf('member'), $reply, SENDER, mailOf('member')]
    );
    $forOrg  = emailPage($orgJar, ip(4));
    $forRoot = emailPage($rootJar, ip(1));
    ok(
        'THE CONTROL: a global administrator sees every organisation\'s messages, what the mail server said about each, and the buttons',
        count(rowsOnPage($forRoot)) === 4 && isset(rowsOnPage($forRoot)[$nobody]) === true && substr_count($forRoot, $replyMark) >= 4 && str_contains($forRoot, 'data-mail-queue-said') === true
            && str_contains($forRoot, 'value="queue_delete"') === true && str_contains($forRoot, 'value="queue_run"') === true && str_contains($forRoot, 'name="smtpUsername"') === true,
        count(rowsOnPage($forRoot)) . ' rows; the reply appears ' . substr_count($forRoot, $replyMark) . ' time(s)'
    );
    ok(
        'an organisation\'s administrator sees their OWN organisation\'s three messages and not the one that belongs to none; each says in the page\'s own words why it waits',
        array_keys(rowsOnPage($forOrg)) == [$mine, $unsure, $failed] && str_contains(words(rowOnPage($forOrg, $mine)), 'It did not go and will be tried again.') === true
            && str_contains(words(rowOnPage($forOrg, $unsure)), 'nobody can tell whether it went') === true && str_contains(words($forOrg), 'Only this organisation’s messages are listed.') === true,
        'rows: ' . json_encode(rowsOnPage($forOrg))
    );
    ok(
        'they are shown NONE of the mail server\'s own words (which name another person\'s address), in the queue or in "Recent sends", and the mailbox\'s user name is nowhere on their page',
        str_contains($forOrg, $replyMark) === false && str_contains($forOrg, 'data-mail-queue-said') === false && str_contains($forOrg, MAILBOX_USER) === false && str_contains($forOrg, 'relay denied') === false,
        'the reply appears ' . substr_count($forOrg, $replyMark) . ' time(s); the user name ' . substr_count($forOrg, MAILBOX_USER) . ' time(s)'
    );
    $buttons = array_filter(SEVEN, static fn (string $action): bool => str_contains($forOrg, 'value="' . $action . '"') === true);
    ok(
        'and they are shown no button of the seven, no certificate card, and none of the three other forms',
        $buttons === [] && str_contains($forOrg, 'id="mail-certificate"') === false && str_contains($forOrg, 'name="smtpPassword"') === false && str_contains($forOrg, 'value="test_send"') === false,
        'buttons shown: ' . json_encode(array_values($buttons))
    );
    /** The label of one line of "Recent sends", and the words beside it. */
    $line = static function (string $page, string $status): array {
        $found = preg_match('#data-mail-log-status="' . $status . '">(.*?)</div>\s*</div>#s', $page, $m) === 1;

        return [$found === true && preg_match('#<span class="badge bg-([a-z -]+)">(.*?)</span>#', $m[1], $b) === 1 ? $b[2] . ' (' . $b[1] . ')' : '', $found === true ? words($m[1]) : ''];
    };
    ok(
        '"Recent sends" gives each kind of line its own plain label, and only a message that was given up on is red: Sent, Waiting, Held, "Expired, not sent", and "Not sent"',
        $line($forRoot, 'sent')[0] === 'Sent (success)' && $line($forRoot, 'queued')[0] === 'Waiting (info text-dark)' && $line($forRoot, 'held')[0] === 'Held (warning text-dark)'
            && $line($forRoot, 'expired')[0] === 'Expired, not sent (secondary)' && $line($forRoot, 'failed')[0] === 'Not sent (danger)' && $line($forOrg, 'queued')[0] === 'Waiting (info text-dark)',
        json_encode(array_map(static fn (string $s): string => $line($forRoot, $s)[0], ['sent', 'queued', 'held', 'expired', 'failed']))
    );
    ok(
        'beside each line an organisation\'s administrator reads the page\'s own words for what it means, where a global administrator reads the recorded reason',
        str_contains($line($forOrg, 'queued')[1], 'Waiting in the mail queue: it has not gone yet, and will be tried again.') === true && str_contains($line($forOrg, 'failed')[1], 'Not sent. A global administrator can see why.') === true
            && str_contains($line($forOrg, 'held')[1], 'Held in the mail queue') === true && str_contains($line($forOrg, 'expired')[1], 'its use-by time passed') === true && str_contains($line($forRoot, 'failed')[1], $replyMark) === true,
        json_encode([$line($forOrg, 'queued')[1], $line($forOrg, 'failed')[1]])
    );
    q("DELETE FROM tblEmailLog WHERE subject LIKE 'N6 log,%'");
}

// =============================================================================
if (part('N7', 'Microsoft 365 and Google get no way past a failed check') === true) {
// =============================================================================
    // A certificate fault on record from when SMTP was the route, a message held for it, and then the route is changed.
    showCertificate('expired');
    send(mailOf('member'), 'N7 held for the certificate', 'Its words: ' . $bodyMark);
    $held  = (int) rowOf('N7 held for the certificate')['queueID'];
    $print = fingerprintOf('expired');
    mailSince('switch');
    foreach (['ms365' => 'Microsoft 365', 'google' => 'Google'] as $route => $name) {
        setting('mail.provider', $route);
        $page   = emailPage($rootJar, ip(1));
        $before = untouched();
        $said   = [
            press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) $held, 'fingerprint' => $print, 'understood' => '1']),
            press($rootJar, ip(1), ['action' => 'cert_accept', 'fingerprint' => $print, 'understood' => '1']),
            press($rootJar, ip(1), ['action' => 'cert_off', 'serverName' => MAIL_HOST]),
        ];
        $mail = mailSince('switch');
        // (This used to hold all three to the words "Microsoft 365 and Google have no way past…". Since the second fix round
        //  the two that only change the SMTP mailbox's own setting are no longer refused FOR THE ROUTE: a certificate that a
        //  "Send test" finds failing can be decided about before switching, which part R1 proves. Here the fault was found
        //  while SMTP was in use, so it went off the record with the change of route, and with nothing on record they are
        //  refused all the same. Sending a MESSAGE past the check is still refused for the route itself.)
        ok(
            'with ' . $name . ' as the route, and a certificate fault that was found while SMTP was in use: the fault went off the record with the change of route, so the e-mail page offers no certificate card and no way past a check; each of the three is refused if pressed by hand (sending a message past the check, because ' . $name . ' has no way past one; the other two, because no certificate is on record to decide about): nothing changes and no mail server is contacted',
            str_contains($page, 'id="mail-certificate"') === false && str_contains($page, 'value="cert_off"') === false && str_contains($page, 'value="queue_send_past_check"') === false
                && count(array_filter($said, static fn (array $a): bool => $a['kind'] === 'danger')) === 3
                && str_contains($said[0]['flash'], 'Microsoft 365 and Google have no way past a failed certificate check') === true
                && str_contains($said[1]['flash'], 'Nothing was changed') === true && str_contains($said[2]['flash'], 'no certificate fault is on record') === true
                && untouched() === $before && $mail['connections'] === 0 && stored('mail.smtp.certCheck') === 'on',
            json_encode(array_map(static fn (array $a): string => $a['kind'] . ': ' . $a['flash'], $said))
        );
    }
    // ⚠️ "Off" is STORED, and Microsoft 365 is the route in use. What is stored stays in force for the SMTP mailbox
    //    (a "Send test" through it, and all mail the day the portal is switched back), so nothing about it may go quiet.
    //    (This part used to assert the opposite: that no warning shows while SMTP is not the route in use.)
    setting('mail.provider', 'ms365');
    setting('mail.smtp.certCheck', 'off');
    setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s'));
    $page    = emailPage($rootJar, ip(1));
    $warning = warningOnPage($page, 'checking-off');
    $dash    = req($rootJar, 'GET', '/admin', null, ip(1))['body'];
    $health  = healthLine();
    $applies = 'This applies to the SMTP mailbox, which is not the route in use now: e-mail is set to go through Microsoft 365.';
    ok(
        'with "off" stored and Microsoft 365 as the route in use, the standing warning STILL shows on the e-mail page (with its button), the admin dashboard and the health page, and says that it applies to the SMTP mailbox, which is not the route in use now',
        str_contains($page, 'data-mail-route="ms365"') === true && str_contains($warning, 'Certificate checking is OFF') === true && str_contains($warning, $applies) === true
            && str_contains($warning, 'a "Send test" through it') === true && str_contains($page, 'value="cert_on"') === true
            && preg_match('#id="mail-certificate-unchecked" data-mail-warning="checking-off">(.*?)</div>#s', $dash, $m) === 1 && str_contains(words($m[1]), $applies) === true
            && $health['state'] === 'warn' && $health['label'] === 'Certificate checking is OFF' && str_contains($health['detail'], $applies) === true,
        'e-mail page: ' . ($warning === '' ? 'NO WARNING' : $warning) . ' | health: ' . json_encode($health)
    );
    // The SMTP mailbox's server still shows the expired certificate: a run of the job asks, and changes nothing. Then it is put right.
    mailSince('switch');
    $mark   = logMark();
    job();
    $stays  = stored('mail.smtp.certCheck');
    showCertificate('good');
    job();
    $mail   = mailSince('switch');
    $record = logged('MailCertificateCheckBackOn', $mark);
    ok(
        'and "off" still LAPSES BY ITSELF with another route in use: while the SMTP mailbox\'s certificate fails, a run of the job changes nothing; once it passes, the next run switches checking back ON, empties the moment and says so in the activity log. The mail server is asked twice and given nothing private; afterwards no page shows the warning',
        $stays === 'off' && stored('mail.smtp.certCheck') === 'on' && stored('mail.smtp.certCheckOffAt') === '' && stored('mail.provider') === 'ms365'
            && count($record) === 1 && $record[0]['userID'] === null && str_contains((string) $record[0]['activityDescription'], 'Checking had been switched off.') === true
            && $mail['connections'] === 2 && $mail['private'] === 0 && str_contains(emailPage($rootJar, ip(1)), 'data-mail-warning="checking-off"') === false
            && str_contains(req($rootJar, 'GET', '/admin', null, ip(1))['body'], 'mail-certificate-unchecked') === false && healthLine()['label'] !== 'Certificate checking is OFF',
        'after a run with the failing certificate: ' . $stays . '; after one with the good certificate: ' . stored('mail.smtp.certCheck') . ' | the mail server saw ' . $mail['connections'] . ' connection(s), ' . $mail['private'] . ' private line(s) | ' . json_encode($record)
    );
    // 🔘 Switching checking back ON by hand, with another route in use. (It used to be refused as "for an SMTP mailbox only".)
    showCertificate('expired');
    setting('mail.smtp.certCheck', 'only:' . fingerprintOf('expired'));
    setting('mail.smtp.certCheckOffAt', gmdate('Y-m-d H:i:s'));
    $shownBefore = warningOnPage(emailPage($rootJar, ip(1)), 'one-certificate');
    $mark   = logMark();
    $on     = press($rootJar, ip(1), ['action' => 'cert_on']);
    $record = logged('MailCertificateCheckOn', $mark);
    ok(
        '"Switch certificate checking back on" is ALLOWED with another route in use: for an accepted certificate too the warning was showing; one press puts checking on and empties the moment, it is logged under that administrator, and the warning is gone',
        str_contains($shownBefore, 'One certificate that failed the full check is accepted for the SMTP mailbox') === true && $on['kind'] === 'success'
            && stored('mail.smtp.certCheck') === 'on' && stored('mail.smtp.certCheckOffAt') === '' && count($record) === 1 && (int) $record[0]['userID'] === $rootId
            && str_contains($on['page'], 'data-mail-warning="one-certificate"') === false,
        'before: ' . ($shownBefore === '' ? 'NO WARNING' : $shownBefore) . ' | the press: ' . $on['kind'] . ': ' . $on['flash'] . ' | stored: ' . stored('mail.smtp.certCheck')
    );
}

// =============================================================================
if (part('N8', 'The page itself: one top-level heading, no <table>, "Nothing is waiting." for an empty queue, and times that name their zone') === true) {
// =============================================================================
    $empty = emailPage($rootJar, ip(1));
    ok(
        'an empty queue says "Nothing is waiting.", and offers no button that would do nothing',
        preg_match('#data-mail-queue="empty">(.*?)</p>#s', $empty, $m) === 1 && words($m[1]) === 'Nothing is waiting.' && str_contains($empty, 'value="queue_run"') === false && rowsOnPage($empty) === [],
        ''
    );
    showCertificate('expired');
    send(mailOf('member'), 'N8 held', 'Its words: ' . $bodyMark);
    $wait = addRow([mailOf('other')], 'N8 waiting, with a use-by time', ['attempts' => 1, 'waitSeconds' => 900, 'expiresAt' => gmdate('Y-m-d H:i:s', time() + 7200)]);
    $row  = rowOf('N8 waiting, with a use-by time');
    $full = emailPage($rootJar, ip(1));
    $text = words(rowOnPage($full, $wait));
    // The page as a browser builds it: without its comments (one of which names the tag this check looks for).
    $tags = (string) preg_replace('/<!--.*?-->/s', '', $full);
    ok(
        'with the certificate card and the queue drawn, the page still has exactly ONE top-level heading, headings that go in order, and no <table>',
        preg_match_all('/<h1[\s>]/i', $tags) === 1 && preg_match_all('/<table[\s>]/i', $tags) === 0 && str_contains($full, 'portal-data-list') === true
            && strpos($full, '<h2') < strpos($full, '<h3') && strpos($full, '<h3') < strpos($full, '<h4') && str_contains($full, 'id="mail-certificate"') === true,
        preg_match_all('/<h1[\s>]/i', $tags) . ' <h1, ' . preg_match_all('/<table[\s>]/i', $tags) . ' <table'
    );
    ok(
        'every moment in the queue is shown in the organisation\'s own time zone with the zone\'s short name beside it: when it was kept, when it is next tried, and the time after which it is not sent',
        str_contains($text, 'Kept ' . shownInZone((string) $row['createdAt'])) === true && str_contains($text, 'Not sent after ' . shownInZone((string) $row['expiresAt'])) === true
            && preg_match('/Next try not before \d{1,2} \w{3} \d{4}, \d\d:\d\d E[SD]T/', $text) === 1 && str_contains($text, 'Tried 1 time.') === true && str_contains($text, 'Message Waiting') === true,
        $text
    );
    // 🕰️ EVERY moment the page shows, wherever on it: the certificate notice at the top, the certificate card, the queue
    //    and "Recent sends". Each is written "13 Oct 2026, 09:12 EDT"; the letters after it are what is looked at.
    preg_match_all('/\d{1,2} [A-Z][a-z]{2} \d{4}, \d\d:\d\d ([A-Za-z0-9+-]{2,6})/', words($full), $moments);
    $recent = (string) strstr($full, 'Recent sends (last 10)');
    ok(
        'EVERY moment on the e-mail page is in the organisation\'s own time zone, with the zone named: the certificate notice at the top and "Recent sends" as well as the queue (those two used to be shown in another zone, so one page gave the same moment twice, differently)',
        preg_match('/\(\d{1,2} \w{3} \d{4}, \d\d:\d\d E[SD]T\)/', warningOnPage($full, 'certificate')) === 1
            && preg_match('/\d{1,2} \w{3} \d{4}, \d\d:\d\d E[SD]T/', words($recent)) === 1 && str_contains(words($recent), 'N8 held') === true
            && count($moments[1]) >= 8 && array_values(array_diff(array_unique($moments[1]), ['EDT', 'EST'])) === [],
        count($moments[1]) . ' moment(s) on the page, in the zone(s) ' . json_encode(array_values(array_unique($moments[1])))
    );
    // 🌏 The organisation's zone is its OWN ROW and nothing else (#604). This check used to leave the row at "UTC", set
    //    the installation's `site.timezone` setting to another zone and expect the whole page to follow the SETTING: that
    //    was the second place the zone was kept, and migration 217 removed it. What must hold now: the page follows the
    //    row when it is changed, and an old "site.timezone" setting put back by hand, naming yet another zone, is NOT read.
    q("UPDATE tblSites SET timezone = 'Pacific/Auckland' WHERE siteID = 1");
    q("INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, 'site.timezone', 'Asia/Tokyo', 'UTC', 0)");
    $byRow = emailPage($rootJar, ip(1));
    q("DELETE FROM tblSettings WHERE settingKey = 'site.timezone'");
    q('UPDATE tblSites SET timezone = ? WHERE siteID = 1', 's', [ORG_ZONE]);
    preg_match_all('/\d{1,2} [A-Z][a-z]{2} \d{4}, \d\d:\d\d ([A-Za-z0-9+-]{2,6})/', words($byRow), $momentsToo);
    ok(
        'and when the organisation\'s own zone is changed, every moment on the page follows it: still ONE zone for the whole page, the queue and "Recent sends" included; an old "site.timezone" setting put back by hand, naming a third zone, is not read',
        count($momentsToo[1]) >= 8 && array_values(array_diff(array_unique($momentsToo[1]), ['NZDT', 'NZST'])) === []
            && preg_match('/Kept \d{1,2} \w{3} \d{4}, \d\d:\d\d NZ[DS]T/', words(rowOnPage($byRow, $wait))) === 1,
        count($momentsToo[1]) . ' moment(s) on the page, in the zone(s) ' . json_encode(array_values(array_unique($momentsToo[1])))
    );
    // The queue's own part of the page: from the certificate card to the card after the queue.
    $own = substr($full, (int) strpos($full, 'id="mail-certificate"'), (int) strpos($full, 'DNS records for the sender') - (int) strpos($full, 'id="mail-certificate"'));
    preg_match_all('/\s(?:href|action)="([^"]*)"/', $own, $links);
    $php   = array_values(array_filter($links[1], static fn (string $link): bool => preg_match('/\.php(\?|#|$)/', $link) === 1));
    $dead  = array_values(array_filter($links[1], static fn (string $link): bool => $link === '#' || $link === ''));
    $forms = substr_count($own, '<form ');
    ok(
        'in the queue\'s part of the page no address ends in ".php", no link or form goes nowhere, every form posts back to the e-mail page and carries a form token, and nothing uses a browser\'s own "are you sure?" box',
        strlen($own) > 3000 && $php === [] && $dead === [] && $forms >= 5 && substr_count($own, 'name="csrf_token"') === $forms && array_unique($links[1]) === [EMAIL_PAGE]
            && stripos($own, 'confirm(') === false && stripos($own, 'onclick') === false,
        '.php: ' . json_encode($php) . '; dead: ' . count($dead) . '; forms ' . $forms . ' with ' . substr_count($own, 'name="csrf_token"') . ' tokens; addresses: ' . json_encode(array_values(array_unique($links[1])))
    );
    $help = words(req($rootJar, 'GET', '/help/admin', null, ip(1))['body']);
    ok(
        'the administrator\'s guide describes the queue\'s page and the three ways through, says checking switches itself back on, and no longer says a page that lists the queue "is not built yet"',
        str_contains($help, 'When the certificate fails: the three ways through') === true && str_contains($help, 'Accept only this certificate (use this before the next one)') === true
            && str_contains($help, 'Checking switches itself back on.') === true && str_contains($help, 'The person it is for may get a second copy') === true
            && str_contains($help, 'can release a held message, is not built yet') === false && str_contains($help, 'certificate is always checked, and this cannot be switched off') === false,
        ''
    );
}

// =============================================================================
if (part('C8', 'Nothing any page printed, and nothing logged or stored, holds a message body, a reset link or the mailbox\'s password') === true) {
// =============================================================================
    // Something of every kind is left in place to be searched: a held reset
    // (the only thing that ever stores a working link), a held message with
    // marked words in its body, and whatever the parts above left behind.
    showCertificate('expired');
    askForLink(ip(95), mailOf('member'));
    send(mailOf('other'), 'C8 a message with marked words', 'Words that must not be found: ' . $bodyMark);
    send(mailOf('third'), 'C8 another, sent past the check', 'More words that must not be found: ' . $bodyMark);
    $pages = [emailPage($rootJar, ip(1)), emailPage($secondJar, ip(3)), emailPage($orgJar, ip(4)), req($rootJar, 'GET', '/admin', null, ip(1))['body'], req($rootJar, 'GET', '/admin/maintenance/health', null, ip(1))['body']];
    healthLine();
    $resetRow = queued("origin = 'reset:" . PEOPLE['member'] . "'")[0] ?? null;
    $marked   = rowOf('C8 a message with marked words');
    // The held reset and one marked message are sent past the check, so that this test learns, FROM THE MAIL SERVER,
    // a real reset link and that the marked words really are in a message: both can then be looked for by name.
    $sent  = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) ($resetRow['queueID'] ?? 0), 'fingerprint' => fingerprintOnPage($pages[0]), 'understood' => '1']);
    $sent2 = press($rootJar, ip(1), ['action' => 'queue_send_past_check', 'queueId' => (string) (rowOf('C8 another, sent past the check')['queueID'] ?? 0), 'fingerprint' => fingerprintOnPage($pages[0]), 'understood' => '1']);
    $mail  = mailSince('switch');
    $noteLinks($mail);
    $arrivedMarked = count(array_filter($mail['messages'], static fn (array $m): bool => str_contains($m['html'] . $m['text'], $bodyMark) === true));
    $stored = (string) json_encode([
        q('SELECT * FROM tblMailQueue'), q('SELECT * FROM tblMailQueueFiles'), q('SELECT * FROM tblMailRouteState'), q('SELECT * FROM tblEmailLog'),
        q('SELECT * FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]), q('SELECT * FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom]),
        q("SELECT * FROM tblSettings WHERE settingKey LIKE 'mail.%'"),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sessions = '';
    foreach ((array) glob($child . $sep . 'sessions' . $sep . '*') as $file) {
        $sessions .= (string) file_get_contents((string) $file);
    }
    $printed = $state['haystack'] . "\n" . (string) @file_get_contents($child . $sep . 'php-errors.log') . "\n" . (string) @file_get_contents($child . $sep . 'server-output.log') . "\n" . $sessions;
    ok(
        'the run really used all three ways through, so what is searched is what they leave behind: one message past the check, one certificate accepted, and checking switched off',
        ($only !== []) || (($waysUsed['one message'] ?? false) === true && ($waysUsed['accept one'] ?? false) === true && ($waysUsed['off'] ?? false) === true),
        json_encode($waysUsed)
    );
    ok(
        'the search looks in the right places: the marked message\'s SUBJECT is in what is stored and on the pages, the marked words and a reset link DID reach the mail servers (inside the e-mails), and there is a great deal to search',
        $marked !== null && $marked['status'] === 'held' && str_contains($stored, 'C8 a message with marked words') === true && str_contains($pages[0], 'C8 a message with marked words') === true
            && $sent['kind'] === 'success' && $sent2['kind'] === 'success' && count($resetTokens) >= 1 && $arrivedMarked === 1
            && strlen($printed) > 300000 && strlen($stored) > 2000 && str_contains($pages[4], 'Mail queue') === true && str_contains($printed, fingerprintOf('expired')) === true && strlen($sessions) > 100,
        'printed ' . strlen($printed) . ' bytes, stored ' . strlen($stored) . ' bytes, ' . count($resetTokens) . ' reset link(s) known, ' . $arrivedMarked . ' marked message(s) arrived, the presses said: ' . $sent['flash'] . ' / ' . $sent2['flash']
    );
    note('C8, what was searched: ' . number_format(strlen($printed)) . ' bytes printed by pages, children, the job and PHP\'s own log, with the sessions; ' . number_format(strlen($stored)) . ' bytes stored in seven tables; for '
        . count($resetTokens) . ' reset link(s) the mail servers were given, the marked words of message bodies, and ' . count([$mailPassword, $otherPassword]) . ' mailbox passwords in seven forms each.');
    ok(
        'the marked words of message bodies are in NOTHING any page printed, nothing a child or the job printed, no log and no session, and nowhere readable in the queue, the send log, the route\'s record, the settings, the error records or the activity records',
        str_contains($printed, $bodyMark) === false && str_contains($stored, $bodyMark) === false,
        (str_contains($printed, $bodyMark) === true ? 'found in what was printed or logged. ' : '') . (str_contains($stored, $bodyMark) === true ? 'found in what is stored.' : '')
    );
    $foundLinks = array_values(array_filter($resetTokens, static fn (string $t): bool => str_contains($printed, $t) === true || str_contains($stored, $t) === true));
    ok(
        'no reset link is in any of them either: not one of the links the mail servers were given, and not the words a link begins with',
        $resetTokens !== [] && $foundLinks === [] && stripos($stored, 'reset-password?token=') === false && stripos($printed, 'reset-password?token=') === false,
        count($foundLinks) . ' of ' . count($resetTokens) . ' link(s) were found'
    );
    $forms = [];
    foreach ([$mailPassword, $otherPassword] as $secret) {
        $forms = array_merge($forms, [
            $secret, htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'), rawurlencode($secret), urlencode($secret), base64_encode($secret),
            base64_encode("\0" . MAILBOX_USER . "\0" . $secret), trim((string) json_encode($secret), '"'),
        ]);
    }
    // (The test itself POSTs the second password once, in N5, as the form's own field. That request is not an answer, and only answers are searched.)
    $found = array_values(array_filter($forms, static fn (string $form): bool => str_contains($printed, $form) === true || str_contains($stored, $form) === true));
    ok(
        'the mailbox\'s password, in every form it could turn up in (as typed, escaped for a page, as an address or a form carries it, as the sign-in sends it, as JSON), is in none of them',
        $found === [],
        count($found) . ' form(s) of a password were found'
    );
    $phpLog  = (string) @file_get_contents($child . $sep . 'php-errors.log');
    $faults  = (int) preg_match_all('/PHP (Fatal|Parse|Warning|Notice|Deprecated)|Uncaught/', $phpLog);
    $unknown = q("SELECT errorPlatform, errorCode, errorTitle FROM tblErrors WHERE errorID > ? AND NOT (errorPlatform = 'SMTP' AND errorTitle = 'Mail send fail')", 'i', [$errorsFrom]);
    ok(
        'nothing went wrong behind the pages: PHP reported no warning or error anywhere, and the only error records are the mail failures this test caused on purpose',
        $faults === 0 && $unknown === [],
        $faults . ' line(s) in PHP\'s log' . ($faults > 0 ? ': ' . mb_substr($phpLog, 0, 800) : '') . '; other error records: ' . json_encode(array_slice($unknown, 0, 5))
    );
}

// =============================================================================
// 🧹 The end
// =============================================================================
q('DELETE FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]);
q('DELETE FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom]);
cleanup();
$left = (int) one('SELECT COUNT(*) AS n FROM tblMailQueue')['n'] + (int) one('SELECT COUNT(*) AS n FROM tblMailRouteState')['n']
    + (int) one('SELECT COUNT(*) AS n FROM tblUsers WHERE userID BETWEEN 932000 AND 932999')['n'];
ok(
    'everything this test made has been removed, the certificate setting is back to "on", and the scratch folder is gone',
    $left === 0 && is_dir($child) === false && stored('mail.smtp.certCheck') === 'on' && stored('mail.smtp.certCheckOffAt') === '',
    $left . ' row(s) left; the setting holds "' . stored('mail.smtp.certCheck') . '"; the scratch folder is ' . (is_dir($child) === true ? 'still there' : 'gone')
);

$ran = $pass + $fail;
echo "\n" . $pass . ' passed, ' . $fail . ' failed, ' . $ran . ' ran' . ($only === [] ? ' of ' . EXPECTED_CHECKS . ' expected' : '') . ".\n";
if ($only !== []) {
    echo ($fail === 0 ? 'PARTIAL RUN — the parts ' . implode(', ', $only) . ' passed. This is NOT the whole test.' : 'FAIL — ' . $fail . ' check(s) failed in a run of some parts only.') . "\n";
    echo "REACHED THE END — of a run of some parts only.\n";
    exit($fail === 0 ? 2 : 1);
}
if ($fail > 0 || $ran !== EXPECTED_CHECKS) {
    echo 'FAIL — ' . ($fail > 0 ? $fail . ' check(s) failed. ' : '') . ($ran !== EXPECTED_CHECKS ? 'The number of checks that ran is not the number expected: a part was skipped or ran twice. ' : '') . "Read the FAIL lines above before releasing.\n";
    echo "REACHED THE END — the mail queue pages self-test ran to its last line, and did not pass.\n";
    exit(1);
}
echo 'PASS — all ' . EXPECTED_CHECKS . " checks passed.\n";
echo "REACHED THE END — the mail queue pages self-test ran every part.\n";
exit(0);
