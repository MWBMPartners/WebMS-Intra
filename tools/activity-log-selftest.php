<?php
// Path: tools/activity-log-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The activity log keeps no sign-in identifier and no copy of a session,
 * on the REAL portal — self-test 🔏 (#622)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * Every activity record used to store the browser session's own identifier
 * (which IS the sign-in cookie) and a copy of everything in the session. So
 * the log, and every backup of it, held working sign-ins, every set of
 * two-step backup codes, and the authenticator key of anybody whose set-up
 * page had been open. Nothing failed while that was so: every page worked.
 * So this file runs the portal and looks at what it stored.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder with a throwaway connection
 * file and key, and starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`. NO FILE OF THE PORTAL IS CHANGED IN THE
 * COPY. It makes its own accounts, turns the test database into one that has
 * NOT had the upgrade (old records, old backup codes, no trace of migration
 * 220), and then does what an administrator would do.
 *
 * The parts:
 *
 *   U  THE UPGRADE, on the real Upgrade page, with the cleaning held up (a
 *      second cleaner is "running"). The upgrade file runs, the old backup
 *      codes go, the version is recorded and the portal is open, while old
 *      records are still waiting. The page and the health page say so, to a
 *      global administrator and to nobody else.
 *   K  THE CLEANER, piece by piece: a piece that fails is undone whole; an
 *      account whose old records held an authenticator key gets ONE record,
 *      with no key in it, however the pieces fall; an account with only
 *      backup codes in its old records gets none; a record already in the
 *      new form is left exactly as it is; a fingerprint made by the database
 *      is the one PHP makes.
 *   B  PIECE BOUNDARIES: a DENSE log — PIECE + 3 records at consecutive
 *      numbers — cleaned end to end, with the database read back afterwards.
 *      Part K's records are sparse, so no piece boundary ever falls on a
 *      record that exists; a one-character fault in "how far" therefore
 *      skipped a record at every boundary and part K still passed. This part
 *      holds the cleaner to two promises that do not depend on the shape of
 *      the fault: when it says it has finished, NOTHING anywhere still holds
 *      an identifier or an old copy; and it may never report fewer records
 *      left than really hold something.
 *   H  The health page's line for accounts that still sign in with a key the
 *      log once held: who is counted, and when an account stops being
 *      counted.
 *   N  NEW RECORDS, on the real pages: a sign-in, a settings change and a
 *      two-step set-up. No value anywhere in the log is the browser's cookie;
 *      the copy of the session holds the named facts and nothing else; a
 *      made-up secret put into the session does not reach the log; one
 *      session has one fingerprint and two sessions have two. The consent
 *      page stores a fingerprint as well.
 *   D  A SECOND RUN of the upgrade file and of the cleaner changes nothing,
 *      and a backup code made after the upgrade is still there.
 *   R  A BACKUP TAKEN BEFORE THE UPGRADE is put back after it, with the
 *      portal's own backup code, and the next press of the Upgrade page's
 *      button cleans everything again and deletes the old codes again.
 *   I  THE INSTALL SCRIPT's two statements for migration 220, read out of
 *      the real file and run in a scratch database: a new database, the
 *      installer's "carry on" choice over an older one, "carry on" over one
 *      that has already been upgraded, and a database with backup codes but
 *      an EMPTY activity log — the one case where "no backup code exists"
 *      and "the log is empty" do not agree, and so the only case that shows
 *      the record being written on the wrong table.
 *
 * WHAT IT CANNOT SHOW
 * -------------------
 *   - Anything in a browser: pages are read as text.
 *   - Two presses at truly the same moment. The second cleaner is stood in
 *     for by this test holding the cleaner's own database lock.
 *   - A request cut off by a web server. A piece that fails is made by a
 *     database trigger this test plants and removes.
 *   - "Key replaced" and "two-step sign-in switched off" are written into the
 *     account's row as the two-step pages write them; those pages are not
 *     pressed for it (part N presses the set-up page for its own purpose).
 *   - A large log. Timing on 500,000 records was measured separately.
 *   - The whole install script on a whole database: tools/e2e-migrations/
 *     run.sh does that. Part I runs only its two statements for migration 220.
 *   - Apache, https, a real proxy, MariaDB. It has been run on MySQL 8.0.36
 *     and 8.4.11.
 *
 * DATES. Nothing here depends on today's date.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/activity-log-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9810). The database must already
 *         hold `web/_sql/full_schema.sql`, and its user must be allowed to
 *         create a trigger and a second database (part I makes and drops
 *         one, named after SELFTEST_DB_NAME with "_i622" on the end). Needs
 *         PHP's curl extension and `proc_open()`.
 *         THE DATABASE MUST BE THIS TEST'S ALONE: part R puts the whole
 *         database back to how it was when the test began.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/622
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 77;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false || preg_match('/^[A-Za-z0-9_]{1,50}$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ and is letters, digits and underscores only (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this PHP has no {$needed}()");
    }
}
$sep       = DIRECTORY_SEPARATOR;
$repo      = (string) realpath(dirname(__DIR__));
$webSrc    = $repo . $sep . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent    = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9810 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

// The portal's own code, called directly where a page is not the point:
// the cleaner piece by piece, the migration runner, the backup, a six-digit
// code. Each file is a class and nothing else.
foreach (['Logger', 'ActivityLogCleaner', 'Migrator', 'DbBackup', 'Totp'] as $class) {
    require $webSrc . $sep . '_core' . $sep . $class . '.php';
}

use Portal\Core\ActivityLogCleaner;
use Portal\Core\DbBackup;
use Portal\Core\Logger;
use Portal\Core\Migrator;
use Portal\Core\Totp;

$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
/** A new connection to the test database. */
function connect(): mysqli
{
    global $creds;
    $link = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
    $link->set_charset('utf8mb4');

    return $link;
}
$db = connect();

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
/** The accounts, all numbered 962200 to 962299. */
const PEOPLE = [
    'admin'     => 962201, // a global administrator: the Upgrade page, the health page, settings
    'second'    => 962202, // a second global administrator: sets two-step sign-in up on the real page
    'orgadmin'  => 962203, // an administrator of the organisation only: must NOT be shown the two health lines
    'member'    => 962204, // an ordinary member: the portal is open to them while old records wait
    'keyed'     => 962205, // old records held an authenticator key; two-step sign-in is ON
    'keyedoff'  => 962206, // old records held a key; two-step sign-in is OFF
    'codesonly' => 962207, // old records held backup codes and no key
    'cutshort'  => 962208, // a key in an old copy that is not well-formed (cut short); two-step ON
];
/** How this test names itself to the portal, so the records it causes can be found. */
const AGENT = 'activity-log-selftest';
/** The migration this is about. */
const MIGRATION = '220_activity_log_fingerprints.sql';
/** The six names a copy of a session may hold, in order. */
const FACTS = ['format', 'signedIn', 'userID', 'organisationID', 'waitingForCodeUserID', 'kioskUserID'];
/** The three column comments migration 220 writes all carry this. */
const COMMENT_MARK = '%(#622)%';

function login(string $who): string
{
    return 'al622-' . $who;
}

/** Thirty-two characters as PHP makes a session identifier: digits and a to f. */
function madeUpIdentifier(): string
{
    return bin2hex(random_bytes(16));
}

/** Thirty-two characters as the old set-up page made an authenticator key: A to Z and 2 to 7. */
function madeUpKey(): string
{
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $key     = '';
    for ($i = 0; $i < 32; $i++) {
        $key .= $letters[random_int(0, 31)];
    }

    return $key;
}

function removeWorld(): void
{
    q('DELETE FROM tblTotpBackupCodes WHERE userID BETWEEN 962200 AND 962299');
    q('DELETE FROM tblConsentLog WHERE userID BETWEEN 962200 AND 962299 OR userAgent = ?', 's', [AGENT]);
    q('DELETE FROM tblActivityLogs WHERE userID BETWEEN 962200 AND 962299 OR userAgent = ? OR visitorIP = ?', 'ss', [AGENT, '192.0.2.62']);
    q('DELETE FROM tblErrors WHERE userID BETWEEN 962200 AND 962299 OR userAgent = ?', 's', [AGENT]);
    q('DELETE FROM tblTrustedDevices WHERE userID BETWEEN 962200 AND 962299');
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 962200 AND 962299');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 962200 AND 962299');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 962200 AND 962299');
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false,
    'scratchDb' => '', 'trigger' => false, 'lockHolder' => null, 'firstSnapshot' => ''];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Stop the server by the process proc_open() gave — never by a name. */
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
    global $state, $db;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    stopServer($state['proc']);
    try {
        if ($state['lockHolder'] instanceof mysqli) {
            $state['lockHolder']->close();
        }
        if ($state['trigger'] === true) {
            $db->query('DROP TRIGGER IF EXISTS al622_stop_piece');
        }
        if ($state['scratchDb'] !== '') {
            $db->query('DROP DATABASE IF EXISTS `' . $state['scratchDb'] . '`');
        }
        // The database as it was when the test began, whole: the surest way
        // back after an upgrade, a restore and a second upgrade.
        if ($state['firstSnapshot'] !== '') {
            $back = (new DbBackup(connect(), $state['child'] . DIRECTORY_SEPARATOR . 'test-backups'))->restoreAll($state['firstSnapshot'], false);
            if ($back['success'] !== true) {
                echo 'FAIL — the clean-up could not put the database back as it was: ' . (string) ($back['error'] ?? '') . "\n";
            }
            // A restore puts rows back, not the shape of a table. The three
            // column comments were taken off at the start; migration 220 puts
            // them back and, being recorded there, does nothing else.
            runMigration();
        }
        removeWorld();
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE tblSettings SET settingValue = ?, isSensitive = ? WHERE settingID = ?', 'sii', [$before['settingValue'], (int) $before['isSensitive'], (int) $before['settingID']]);
            }
        }
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    // The scratch child: only the one this run made, and only while it still holds this run's marker.
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.activity-log-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One portal-wide setting for the run. The value it had is remembered the
 * first time and put back by the clean-up.
 */
function setting(string $key, string $value): void
{
    global $state;
    $row = one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);
    if (array_key_exists($key, $state['settings']) === false) {
        $state['settings'][$key] = $row;
    }
    if ($row === null) {
        q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE tblSettings SET settingValue = ?, isSensitive = 0 WHERE settingID = ?', 'si', [$value, (int) $row['settingID']]);
    }
}

/** The installation-wide value of a setting now, or null when there is no such row. */
function stored(string $key): ?string
{
    $row = one('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [$key]);

    return $row === null ? null : (string) $row['settingValue'];
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:', 'User-Agent: ' . AGENT],
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
    if (preg_match('/^X-Activity-Log-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
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
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** POST a form with a fresh token read from `$formPage`, as a browser would. */
function post(string $jar, string $formPage, string $to, array $fields): array
{
    $fields['csrf_token'] = token(req($jar, 'GET', $formPage)['body']);

    return req($jar, 'POST', $to, $fields);
}

/** A page's words, without its tags or its scripts, on one line. */
function words(string $html): string
{
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** The words inside the element with this id, or '' when the page has none. Elements here hold no element of their own kind. */
function part(string $html, string $id, string $tag = 'p'): string
{
    return preg_match('#<' . $tag . '\b[^>]*\bid="' . preg_quote($id, '#') . '"[^>]*>(.*?)</' . $tag . '>#s', $html, $m) === 1 ? words($m[1]) : '';
}

/** "HTTP 302 to /" of an answer, for failure messages. */
function said(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']);
}

/** A new, empty cookie jar: a browser that has never been here. */
function newJar(string $name): string
{
    global $state, $sep;

    return $state['child'] . $sep . 'jar-' . $name . '-' . bin2hex(random_bytes(4)) . '.txt';
}

/** Sign in on the real page, in a new browser, and give its cookie jar back. */
function signIn(string $who): string
{
    global $password;
    $jar = newJar($who);
    $tok = token(req($jar, 'GET', '/login')['body']);
    $in  = req($jar, 'POST', '/login', ['csrf_token' => $tok, 'username' => login($who), 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse('signing in as ' . login($who) . ' did not work (' . said($in) . ')');
    }

    return $jar;
}

/**
 * The session identifier a browser holds NOW: the cookie in its jar that
 * names a session file. '' when it holds none.
 */
function cookieOf(string $jar): string
{
    global $state, $sep;
    foreach ((array) @file($jar, FILE_IGNORE_NEW_LINES) as $line) {
        $fields = explode("\t", (string) preg_replace('/^#HttpOnly_/', '', (string) $line));
        if (count($fields) === 7 && is_file($state['child'] . $sep . 'sessions' . $sep . 'sess_' . $fields[6]) === true) {
            return $fields[6];
        }
    }

    return '';
}

/**
 * The authenticator key the two-step set-up page shows, or '' when the page
 * does not show one.
 *
 * WHY IT IS READ THIS WAY (changed 27 October 2026). This used to be one
 * pattern looking for 16 to 128 characters of A to Z and 2 to 7 straight
 * after `user-select-all">`. Since #623 the page prints the key in eight
 * groups of four, each in its own `<span>`, so that 32 letters can be typed
 * without losing the place — and the pattern then matched NOTHING. The run
 * carried on with an empty key: the checks that followed searched the log for
 * an empty string and for backup codes that were never made, and passed
 * while proving nothing. So this takes the whole `<code id="totpKey">` block,
 * throws the tags away and keeps only the characters a key is made of, which
 * works whatever the page does with spacing, and it answers '' rather than
 * something half-read. `keyOn()` in tools/two-step-selftest.php reads the
 * same page the same way; keep the two in step.
 */
function keyOnSetupPage(string $html): string
{
    if (preg_match('#<code[^>]*\bid="totpKey"[^>]*>(.*?)</code>#s', $html, $m) !== 1) {
        return '';
    }
    $key = (string) preg_replace('/[^A-Z2-7]/', '', (string) preg_replace('#<[^>]+>#', ' ', $m[1]));

    return preg_match('/^[A-Z2-7]{16,128}$/', $key) === 1 ? $key : '';
}

/** The text of a browser's session file: what the portal has put in its session. */
function sessionText(string $jar): string
{
    global $state, $sep;

    return (string) @file_get_contents($state['child'] . $sep . 'sessions' . $sep . 'sess_' . cookieOf($jar));
}

/** Put one more value into a browser's session, as a page of the portal would. */
function plant(string $jar, string $name, mixed $value): void
{
    global $state, $sep;
    file_put_contents($state['child'] . $sep . 'sessions' . $sep . 'sess_' . cookieOf($jar), $name . '|' . serialize($value), FILE_APPEND | LOCK_EX);
}

/**
 * How many activity records hold this text, in ANY column.
 *
 * IT REFUSES AN EMPTY TEXT, AND THAT IS THE POINT (added 27 October 2026).
 * The independent check found part N5 reading the authenticator key off the
 * set-up page with a pattern the page had outgrown: the pattern matched
 * nothing, the key became '', and this function was then asked for ''. Every
 * record holds the empty text, so the check reported a leak it had not found
 * — and, worse, the two checks after it searched for backup codes that the
 * run had never made ("0 of 0 found"), which is a check that cannot see the
 * very leak it is named after. A check that passes while proving nothing is
 * worse than one that fails, so the whole run stops here instead: whoever is
 * reading has to fix the test rather than trust it.
 */
function logHolds(string $text): int
{
    if ($text === '') {
        refuse('logHolds() was asked to search the log for an empty text, which every record holds. Something this test meant to read off a page — a key, a code, a cookie — came back empty, so the check that called this would have proved nothing. Fix what reads it');
    }

    return (int) one(
        "SELECT COUNT(*) AS n FROM tblActivityLogs WHERE CONCAT_WS('|', COALESCE(activityType, ''), COALESCE(activityDescription, ''), "
        . "COALESCE(requestHeaders, ''), COALESCE(sessionID, ''), COALESCE(visitorIP, ''), COALESCE(userAgent, ''), "
        . "COALESCE(sessionDataSnapshot, '')) LIKE ?",
        's',
        ['%' . addcslashes($text, '%_\\') . '%']
    )['n'];
}

/**
 * Where the first RUN of `$run` characters of this secret sits inside it, if
 * any run of it is in the log anywhere: the offset, counted from 0, or -1
 * when no run of it is there. It never returns any of the secret itself, so
 * what a failing check prints holds no key, code or identifier.
 *
 * WHY A RUN AND NOT THE WHOLE SECRET (added 27 October 2026). This check used
 * to look for each secret whole and for its FIRST eight characters. The
 * independent check planted a fault that appends the LAST EIGHT characters of
 * the authenticator key to the words of the key record — the one record in
 * this whole package that is meant to hold nothing of the key — and the test
 * passed. Any eight characters of a 32-character key are enough to make
 * guessing the rest worth somebody's while, and a fragment can come from
 * anywhere in it, so every position is tried.
 *
 * Eight is the shortest run looked for, because eight is what that fault
 * wrote. The cost of going that short: eight characters of plain digits and
 * a to f could in principle turn up by chance in some unrelated fingerprint
 * in the log. Worked out for the log this test makes, that is about one run
 * in a thousand — loud and inspectable when it happens (the offset is
 * printed), never a quiet pass.
 */
function logHoldsRunOf(string $secret, int $run = 8): int
{
    if (strlen($secret) <= $run) {
        return logHolds($secret) > 0 ? 0 : -1;
    }
    for ($at = 0; $at + $run <= strlen($secret); $at++) {
        if (logHolds(substr($secret, $at, $run)) > 0) {
            return $at;
        }
    }

    return -1;
}

/** The activity records this test's requests have caused, newest last. */
function myRecords(string $where = '1 = 1', string $types = '', array $params = []): array
{
    return q('SELECT logID, userID, activityType, activityDescription, sessionID, sessionDataSnapshot FROM tblActivityLogs WHERE userAgent = ? AND ' . $where . ' ORDER BY logID', 's' . $types, array_merge([AGENT], $params));
}

/** Is this the copy of a session that Logger::sessionFacts() writes: exactly the six names, each a whole number, true/false or nothing? */
function isFacts(?string $copy): bool
{
    $facts = json_decode((string) $copy, true);
    if (is_array($facts) === false || array_keys($facts) !== FACTS || $facts['format'] !== Logger::SESSION_FACTS_FORMAT || is_bool($facts['signedIn']) === false) {
        return false;
    }
    foreach (['userID', 'organisationID', 'waitingForCodeUserID', 'kioskUserID'] as $name) {
        if ($facts[$name] !== null && is_int($facts[$name]) === false) {
            return false;
        }
    }

    return str_starts_with((string) $copy, '{"format":"facts-1",') === true;
}

/** Change the tagline setting on the real Settings page, to a value it does not hold now, so that every call is a real change. */
function changeTagline(string $jar): array
{
    $row = one("SELECT settingID, settingValue FROM tblSettings WHERE settingKey = 'site.tagline' AND siteID IS NULL");

    return post($jar, '/settings', '/settings/save', [
        'settingID'    => (int) $row['settingID'],
        'settingKey'   => 'site.tagline',
        'settingValue' => (string) $row['settingValue'] === 'a tagline for the self-test' ? 'another tagline for the self-test' : 'a tagline for the self-test',
    ]);
}

/** One card on the health page: "warning | 3 to clean | detail…", or '' when the page has no such card. */
function healthCard(string $html, string $name): string
{
    if (preg_match('#<h3[^>]*>' . preg_quote($name, '#') . '</h3>\s*<p[^>]*><span class="badge bg-(\w+)">([^<]*)</span></p>\s*<p[^>]*>([^<]*)</p>#', $html, $m) !== 1) {
        return '';
    }

    return $m[1] . ' | ' . html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8') . ' | ' . html_entity_decode(trim($m[3]), ENT_QUOTES, 'UTF-8');
}

/** Run one migration file through the portal's own runner, on a connection of its own. */
function runMigration(?mysqli $on = null): array
{
    global $webSrc, $sep;

    return (new Migrator($on ?? connect(), $webSrc . $sep . '_sql'))->runOne(MIGRATION);
}

/** What the old records, the codes and the marks look like now, as one line. */
function upgradeState(): string
{
    $o = one(
        "SELECT (SELECT COUNT(*) FROM tblActivityLogs WHERE sessionID IS NOT NULL AND sessionID <> '' AND sessionID NOT LIKE 'sha256:%') AS identifiers, "
        . "(SELECT COUNT(*) FROM tblActivityLogs WHERE sessionDataSnapshot IS NOT NULL AND sessionDataSnapshot NOT LIKE '{\"format\":\"facts-1\",%') AS copies, "
        . '(SELECT COUNT(*) FROM tblTotpBackupCodes WHERE userID BETWEEN 962200 AND 962299) AS codes, '
        . "(SELECT COUNT(*) FROM tblConsentLog WHERE userAgent = '" . AGENT . "' AND sessionID IS NOT NULL AND sessionID NOT LIKE 'sha256:%') AS consent, "
        . "(SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . MIGRATION . "') AS recorded, "
        . '(SELECT COUNT(*) FROM tblActivityLogs WHERE activityType = ?) AS keyLines',
        's',
        [ActivityLogCleaner::KEY_LINE_TYPE]
    );

    return 'identifiers ' . $o['identifiers'] . ', old copies ' . $o['copies'] . ', backup codes ' . $o['codes'] . ', consent identifiers ' . $o['consent']
        . ', 220 recorded ' . $o['recorded'] . ', key records ' . $o['keyLines'] . ', how far ' . (stored(ActivityLogCleaner::THROUGH_KEY) ?? '(no row)');
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$scratchDb = $dbName . '_i622';
if (one('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', 's', [$scratchDb]) !== null) {
    refuse("the scratch database {$scratchDb} already exists; this test only drops one it made. Drop it by hand if it is left from a run that was killed");
}
if (one("SELECT filename FROM tblMigrations WHERE filename = '" . MIGRATION . "'") === null) {
    refuse('the test database does not hold today\'s install script (migration 220 is not recorded in it)');
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

// The scratch copy of web/, with a throwaway connection file and key.
$child = $parent . $sep . 'activity-log-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.activity-log-selftest-owner', $state['token']); // the marker FIRST
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
foreach (['web' . $sep . '_auth_keys', 'web' . $sep . '_backups', 'sessions', 'test-backups'] as $folder) {
    mkdir($child . $sep . $folder, 0700);
}
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/activity-log-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// 📸 The database as it is before this test touches it, so that it can be
//    put back whole at the end (see cleanup()).
$backup = new DbBackup(connect(), $child . $sep . 'test-backups');
$first  = $backup->snapshot('activity-log-selftest, the starting state');
if ($first['success'] !== true) {
    refuse('the starting state of the database could not be saved: ' . (string) $first['error']);
}
$state['firstSnapshot'] = (string) $first['directory'];

// The accounts.
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (PEOPLE as $who => $id) {
    $global = in_array($who, ['admin', 'second'], true) === true ? 1 : 0;
    $local  = ($global === 1 || $who === 'orgadmin') ? 1 : 0;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest log ' . $who, $who . '.al622@selftest.invalid', $global, $global]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, ?)', 'iii', [$id, $local, $global]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
$cronToken = bin2hex(random_bytes(16));
setting('maintenance.cronToken', $cronToken);

// 🕰️ AN OLDER DATABASE: one that has not had migration 220.
//    Its record is taken away, the cleaner's two settings are taken away, the
//    three column comments are taken off (the column's own definition written
//    back without one), and old records, old backup codes and old consent
//    rows are put in, written as the portal wrote them before #622.
q("DELETE FROM tblMigrations WHERE filename = '" . MIGRATION . "'");
q('DELETE FROM tblSettings WHERE settingKey IN (?, ?)', 'ss', [ActivityLogCleaner::THROUGH_KEY, ActivityLogCleaner::KEY_LINES_KEY]);
foreach ([['tblActivityLogs', 'sessionID'], ['tblActivityLogs', 'sessionDataSnapshot'], ['tblConsentLog', 'sessionID']] as [$table, $column]) {
    $is = one('SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', [$table, $column]);
    $db->query('ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` ' . $is['COLUMN_TYPE'] . ' CHARACTER SET ' . $is['CHARACTER_SET_NAME'] . ' COLLATE ' . $is['COLLATION_NAME'] . " NULL COMMENT ''");
}

$key = ['keyed' => madeUpKey(), 'keyedoff' => madeUpKey(), 'cutshort' => madeUpKey(), 'nobody' => madeUpKey()];
$oldCodes = [];
for ($i = 0; $i < 8; $i++) {
    $oldCodes[] = strtoupper(bin2hex(random_bytes(4)));
}
// The stored, encrypted key of the two accounts with two-step sign-in ON.
// Made-up text stands in for the encrypted form: nothing here decrypts it.
q("UPDATE tblUsers SET totpEnabled = 1, totpSecret = 'stored-encrypted-key-of-keyed-BEFORE' WHERE userID = ?", 'i', [PEOPLE['keyed']]);
q("UPDATE tblUsers SET totpEnabled = 1, totpSecret = 'stored-encrypted-key-of-cutshort-BEFORE' WHERE userID = ?", 'i', [PEOPLE['cutshort']]);
foreach (['keyed', 'codesonly'] as $who) {
    foreach ($oldCodes as $code) {
        q('INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (?, ?)', 'is', [PEOPLE[$who], password_hash($code . $who, PASSWORD_BCRYPT, ['cost' => 4])]);
    }
}

// The old records. Their numbers start well above anything in the table, and
// one sits 12,000 numbers further on, so that it falls in a LATER piece.
$base = (int) one('SELECT COALESCE(MAX(logID), 0) + 100 AS n FROM tblActivityLogs')['n'];
$id   = ['keyed' => madeUpIdentifier(), 'codesonly' => madeUpIdentifier(), 'keyedoff' => madeUpIdentifier(), 'cutshort' => madeUpIdentifier(),
    'nobody' => madeUpIdentifier(), 'late' => madeUpIdentifier(), 'newform' => madeUpIdentifier()];
$encryptedLook = base64_encode(random_bytes(72)); // 96 characters: how the set-up page keeps the key since #613
$newFacts      = (string) json_encode(['format' => 'facts-1', 'signedIn' => true, 'userID' => PEOPLE['member'], 'organisationID' => 1, 'waitingForCodeUserID' => null, 'kioskUserID' => null]);
$old = [
    // [number, the record's own account, identifier, copy of the session]
    1  => [null, $id['keyed'], json_encode(['user_id' => PEOPLE['keyed'], 'user_name' => 'Selftest log keyed', 'totp_setup_secret' => $key['keyed'], 'flash_msg' => 'x'])],
    2  => [PEOPLE['keyed'], $id['keyed'], json_encode(['user_id' => PEOPLE['keyed'], 'totp_backup_codes' => $oldCodes])],
    3  => [PEOPLE['codesonly'], $id['codesonly'], json_encode(['user_id' => PEOPLE['codesonly'], 'totp_backup_codes' => $oldCodes])],
    4  => [PEOPLE['keyedoff'], $id['keyedoff'], json_encode(['user_id' => (string) PEOPLE['keyedoff'], 'totp_setup_secret' => $key['keyedoff']])],
    5  => [PEOPLE['member'], Logger::fingerprintOf($id['newform']), $newFacts],             // already in the new form
    6  => [null, '', '[]'],                                                                 // an old record with no session
    7  => [null, null, null],                                                               // nothing about a session at all
    8  => [PEOPLE['cutshort'], $id['cutshort'], '{"user_id":' . PEOPLE['cutshort'] . ',"totp_setup_secret":"' . $key['cutshort'] . '","note":"cut sho'], // not well-formed
    9  => [null, $id['nobody'], json_encode(['totp_setup_secret' => $key['nobody']])],      // a key, and nothing says whose
    10 => [PEOPLE['codesonly'], $id['codesonly'], json_encode(['user_id' => PEOPLE['codesonly'], 'totp_setup_secret' => $encryptedLook])], // not a readable key
    12000 => [PEOPLE['keyed'], $id['late'], json_encode(['user_id' => PEOPLE['keyed'], 'totp_setup_secret' => $key['keyed']])],             // the same account, a later piece
];
foreach ($old as $n => [$account, $identifier, $copy]) {
    q(
        "INSERT INTO tblActivityLogs (logID, siteID, userID, activityType, activityDescription, requestHeaders, sessionID, visitorIP, userAgent, sessionDataSnapshot) "
        . "VALUES (?, 1, ?, 'SelfTestOld', ?, '{}', ?, '192.0.2.62', 'an old browser', ?)",
        'iisss',
        [$base + $n, $account, 'old record ' . $n, $identifier, $copy]
    );
}
$oldConsent = ['a' => madeUpIdentifier(), 'b' => madeUpIdentifier()];
foreach ([$oldConsent['a'], $oldConsent['b'], null, Logger::fingerprintOf('already-a-fingerprint')] as $value) {
    q("INSERT INTO tblConsentLog (siteID, userID, sessionID, consentType, decision, policyHash, ipAddress, userAgent) VALUES (1, NULL, ?, 'cookies', 'accept', ?, '192.0.2.62', ?)", 'sss', [$value, hash('sha256', 'p'), AGENT]);
}
// Of the eleven: eight hold an identifier (5 holds a fingerprint, 6 an empty
// one, 7 nothing), and nine hold an old copy (5 holds the new form, 7 nothing).
const OLD_IDENTIFIERS = 8;
const OLD_COPIES      = 9;

// 📸 A backup of the OLDER database, as an administrator might have taken the
//    day before upgrading. Part R puts it back after the upgrade.
$older = $backup->snapshot('activity-log-selftest, before migration 220');
if ($older['success'] !== true) {
    refuse('the backup of the older database could not be taken: ' . (string) $older['error']);
}

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/activity-log-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Activity-Log-Selftest: __TOKEN__');
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

// The server: the command as a LIST (no shell in between), ONE process (no
// PHP_CLI_SERVER_WORKERS), so nothing can outlive it.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$quiet             = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']];
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    $quiet,
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse("the server never answered on port {$port}");
}

echo "The activity log keeps no sign-in identifier and no copy of a session, on the real pages — self-test (#622)\n";
echo 'portal server process ' . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', PHP ' . PHP_VERSION . ', database '
    . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo 'the older database: ' . upgradeState() . "\n";

$filesVersion = (string) require $webSrc . $sep . '_core' . $sep . 'version.php';
$lockSql      = "CONCAT('portal-log-cleaner:', SHA1(DATABASE()))";

// =============================================================================
head('U. The upgrade, on the real Upgrade page, with the cleaning held up');
// =============================================================================
$admin = signIn('admin');
$page  = req($admin, 'GET', '/admin/upgrade');
ok(
    'before the button is pressed, the Upgrade page lists migration 220 as waiting, says how many old records there are, and offers NO separate cleaning button (the upgrade button does both)',
    $page['status'] === 200 && str_contains($page['body'], MIGRATION) === true && part($page['body'], 'upgrade-old-records-left') !== ''
        && str_contains($page['body'], 'id="upgrade-old-records-form"') === false && str_contains(words($page['body']), 'The upgrade button below deletes the old backup codes') === true,
    said($page) . '; the card: ' . part($page['body'], 'upgrade-old-records-left')
);
ok(
    'and it does NOT say the old backup codes are gone, because they are not yet',
    str_contains(part($page['body'], 'upgrade-old-records-meanwhile'), 'already gone') === false && part($page['body'], 'upgrade-old-records-meanwhile') !== '',
    part($page['body'], 'upgrade-old-records-meanwhile')
);

// The cleaning form, sent by hand while the upgrade is still waiting (the
// page draws no such button then).
$before = upgradeState();
$early  = post($admin, '/admin/upgrade', '/admin/upgrade', ['clean_old_records' => '1']);
$page   = req($admin, 'GET', '/admin/upgrade');
ok(
    'the cleaning form sent while the upgrade is still waiting cleans nothing, and the page says to run the upgrade first',
    $early['status'] === 302 && upgradeState() === $before && str_contains(words($page['body']), 'The old activity records were not cleaned, because a database upgrade is waiting') === true,
    said($early) . '; before: ' . $before . '; after: ' . upgradeState()
);

// A second cleaner is "running": this test holds the cleaner's own lock.
$state['lockHolder'] = connect();
$held = (int) $state['lockHolder']->query('SELECT GET_LOCK(' . $lockSql . ', 0) AS got')->fetch_assoc()['got'];
if ($held !== 1) {
    refuse('this test could not take the cleaner\'s lock to stand in for a second cleaner');
}
$before  = upgradeState();
$pressed = req($admin, 'POST', '/admin/upgrade', ['csrf_token' => token($page['body'])]);
$after   = upgradeState();
ok(
    'the button runs migration 220: every old backup code is deleted, the consent log holds fingerprints, and the migration is recorded',
    $pressed['status'] === 200 && str_contains($after, 'backup codes 0, consent identifiers 0, 220 recorded 1') === true,
    said($pressed) . '; before: ' . $before . '; after: ' . $after
);
$consent = array_column(q("SELECT sessionID FROM tblConsentLog WHERE userAgent = ? ORDER BY consentID", 's', [AGENT]), 'sessionID');
ok(
    'each consent row holds the fingerprint PHP makes of the identifier it held; the empty one and the one that was already a fingerprint are as they were',
    $consent === [Logger::fingerprintOf($oldConsent['a']), Logger::fingerprintOf($oldConsent['b']), null, Logger::fingerprintOf('already-a-fingerprint')],
    json_encode($consent)
);
ok(
    'the three column comments say what the columns hold now',
    (int) one("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('sessionID', 'sessionDataSnapshot') AND TABLE_NAME IN ('tblActivityLogs', 'tblConsentLog') AND COLUMN_COMMENT LIKE ?", 's', [COMMENT_MARK])['n'] === 3
);
ok(
    'THE VERSION IS RECORDED AND THE PORTAL IS OPEN although the old records have not been cleaned: the cleaning is not part of the upgrade gate',
    stored('portal.installed_version') === $filesVersion && stored('portal.maintenance.active') !== '1'
        && str_contains($pressed['body'], 'id="upgrade-version-recorded"') === true && str_starts_with($after, 'identifiers ' . OLD_IDENTIFIERS . ', old copies ' . OLD_COPIES . ',') === true,
    'recorded: ' . json_encode(stored('portal.installed_version')) . ' (the files are ' . $filesVersion . '); switch: ' . json_encode(stored('portal.maintenance.active')) . '; ' . $after
);
$member = signIn('member');
$home   = req($member, 'GET', '/');
ok(
    'an ordinary member signs in and is shown the portal, not the holding page, while old records wait',
    in_array($home['status'], [200, 302], true) === true && str_contains($home['location'], 'login') === false && str_contains($home['body'], 'Portal Maintenance') === false,
    said($home)
);
ok(
    'the page says the other cleaner is running, how many records are still to go through, and offers the cleaning button',
    str_contains(part($pressed['body'], 'upgrade-old-records-result'), 'Another cleaning') === true && part($pressed['body'], 'upgrade-old-records-left') !== ''
        && str_contains($pressed['body'], 'id="upgrade-old-records-form"') === true,
    'result: ' . part($pressed['body'], 'upgrade-old-records-result') . '; left: ' . part($pressed['body'], 'upgrade-old-records-left')
);
$meanwhile = part($pressed['body'], 'upgrade-old-records-meanwhile');
ok(
    'and it says plainly what is true meanwhile: new records are safe, the old backup codes are gone, an old record still holds an identifier that may be in use and perhaps an authenticator key, so finish today',
    str_contains($meanwhile, 'every record the portal writes now is already safe') === true && str_contains($meanwhile, 'backup codes made before this version are already gone') === true
        && str_contains($meanwhile, 'session identifier, which may still be in use') === true && str_contains($meanwhile, 'authenticator key in readable text') === true
        && str_contains($meanwhile, 'finish this today') === true && str_contains($meanwhile, 'portal stays open') === true,
    $meanwhile
);
$leftBefore = (int) ActivityLogCleaner::left($db);
$health     = req($admin, 'GET', '/admin/maintenance/health');
$leftAfter  = (int) ActivityLogCleaner::left($db);
$card       = healthCard($health['body'], 'Old activity records');
$onCard     = preg_match('/^warning \| ([0-9,]+) to clean \| /', $card, $m) === 1 ? (int) str_replace(',', '', $m[1]) : -1;
ok(
    'the health page tells a global administrator, in amber, how many old records are left and what to do',
    $onCard >= $leftBefore && $onCard <= $leftAfter && $leftBefore >= OLD_COPIES && str_contains($card, 'Open Admin, Upgrade and press') === true
        && str_contains($card, 'session identifier, which may still be in use') === true,
    said($health) . '; the card: ' . $card . '; left by the cleaner\'s own count: ' . $leftBefore . ' before the page was asked for, ' . $leftAfter . ' after'
);
$orgAdmin  = signIn('orgadmin');
$orgHealth = req($orgAdmin, 'GET', '/admin/maintenance/health');
$monitor   = req('', 'GET', '/cron/health?token=' . $cronToken);
ok(
    'an administrator of one organisation is shown the health page WITHOUT that line, and the monitor\'s address does not carry it either',
    $orgHealth['status'] === 200 && str_contains($orgHealth['body'], 'Database') === true && healthCard($orgHealth['body'], 'Old activity records') === ''
        && str_contains($orgHealth['body'], 'Authenticator keys') === false
        && isset(json_decode($monitor['body'], true)['probes']['Database']) === true && str_contains($monitor['body'], 'Old activity records') === false,
    said($orgHealth) . '; card: ' . healthCard($orgHealth['body'], 'Old activity records') . '; monitor: ' . said($monitor)
);

$before = upgradeState();
$busy   = post($admin, '/admin/upgrade', '/admin/upgrade', ['clean_old_records' => '1']);
$shown  = req($admin, 'GET', $busy['location'] === '' ? '/admin/upgrade' : $busy['location']);
ok(
    'ONLY ONE CLEANER AT A TIME: the cleaning button pressed while another cleaner runs does nothing, and says so',
    $busy['status'] === 302 && upgradeState() === $before && str_contains(words($shown['body']), 'Another cleaning of the old activity records is running') === true,
    said($busy) . '; before: ' . $before . '; after: ' . upgradeState()
);
$state['lockHolder']->close();
$state['lockHolder'] = null;

// =============================================================================
head('K. The cleaner, piece by piece');
// =============================================================================
$untouchedBefore = q("SELECT logID, MD5(CONCAT_WS('|', COALESCE(sessionID, '~'), COALESCE(sessionDataSnapshot, '~'), COALESCE(activityDescription, '~'))) AS mark FROM tblActivityLogs "
    . "WHERE (sessionID IS NULL OR sessionID = '' OR sessionID LIKE 'sha256:%') AND (sessionDataSnapshot IS NULL OR sessionDataSnapshot LIKE '{\"format\":\"facts-1\",%') ORDER BY logID");
ok('there are records already in the new form to leave alone: the made-up one, and the ones this run\'s sign-ins have just written', count($untouchedBefore) >= 3, count($untouchedBefore) . ' such record(s)');

// K1. A piece that fails is undone whole. A trigger refuses the step that
//     moves "how far" on, which is the LAST step of a piece: by then the key
//     records have been written and the piece's records cleaned.
$db->query(
    'CREATE TRIGGER al622_stop_piece BEFORE UPDATE ON tblSettings FOR EACH ROW BEGIN '
    . "IF NEW.settingKey = '" . ActivityLogCleaner::THROUGH_KEY . "' AND NOT (NEW.settingValue <=> OLD.settingValue) THEN "
    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted by the activity log self-test'; END IF; END"
);
$state['trigger'] = true;
$before = upgradeState();
$failed = ActivityLogCleaner::run($db, 0.0);
ok(
    'A PIECE THAT IS CUT OFF IS UNDONE WHOLE: with its last step refused, no record is cleaned, no key record is kept, and "how far" has not moved',
    $failed['error'] !== '' && $failed['finished'] === false && $failed['cleaned'] === 0 && str_contains($before, 'key records 0, how far (no row)') === true
        && upgradeState() === str_replace('how far (no row)', 'how far 0', $before),
    'the cleaner said: ' . json_encode($failed) . '; before: ' . $before . '; after: ' . upgradeState()
);
ok('the words for the page say it stopped and that what was finished is kept, without the database\'s own words', str_contains(ActivityLogCleaner::inWords($failed), 'The cleaning then stopped') === true && str_contains(ActivityLogCleaner::inWords($failed), 'planted') === false, ActivityLogCleaner::inWords($failed));
$db->query('DROP TRIGGER al622_stop_piece');
$state['trigger'] = false;

// K2. One piece at a time (a time budget of nothing still does one piece).
$calls    = [];
$linesAt  = [];
$lateDone = 0;
for ($n = 1; $n <= 12; $n++) {
    $r         = ActivityLogCleaner::run($db, 0.0);
    $calls[]   = $r;
    $linesAt[] = (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = ?', 's', [ActivityLogCleaner::KEY_LINE_TYPE])['n'];
    if ($lateDone === 0 && str_starts_with((string) one('SELECT sessionID FROM tblActivityLogs WHERE logID = ?', 'i', [$base + 12000])['sessionID'], 'sha256:') === true) {
        $lateDone = $n;
    }
    if ($r['finished'] === true || $r['error'] !== '') {
        break;
    }
}
$last = $calls[count($calls) - 1];
ok(
    'WITH NO TIME LEFT IT STILL DOES ONE PIECE AND STOPS: each call did exactly one piece, and the first did not finish the log',
    array_unique(array_column($calls, 'pieces')) === [1] && $calls[0]['finished'] === false && $calls[0]['left'] > 0 && $calls[0]['cleaned'] > 0,
    json_encode(array_map(static fn (array $c): array => ['pieces' => $c['pieces'], 'cleaned' => $c['cleaned'], 'left' => $c['left'], 'finished' => $c['finished']], $calls))
);
ok(
    'the pieces fell apart as planned: the first piece cleaned the first old records and a LATER call cleaned the one 12,000 numbers on',
    $lateDone >= 2 && $last['finished'] === true && $last['error'] === '' && $last['left'] === 0,
    'the late record was cleaned by call ' . $lateDone . ' of ' . count($calls) . '; the last call: ' . json_encode($last)
);
ok('when every record has been gone through, "how far" says all and nothing is left', stored(ActivityLogCleaner::THROUGH_KEY) === ActivityLogCleaner::DONE && ActivityLogCleaner::left($db) === 0, 'how far: ' . json_encode(stored(ActivityLogCleaner::THROUGH_KEY)));

$state622 = upgradeState();
ok('no record holds an identifier or an old copy of a session any more', str_starts_with($state622, 'identifiers 0, old copies 0,') === true, $state622);
$now = [];
foreach (q('SELECT logID, sessionID, sessionDataSnapshot FROM tblActivityLogs WHERE logID BETWEEN ? AND ?', 'ii', [$base, $base + 12000]) as $row) {
    $now[(int) $row['logID'] - $base] = $row;
}
ok(
    'THE DATABASE\'S FINGERPRINT IS PHP\'S: each cleaned record holds exactly Logger::fingerprintOf() of the identifier it held, and two records of one session still match',
    $now[1]['sessionID'] === Logger::fingerprintOf($id['keyed']) && $now[2]['sessionID'] === $now[1]['sessionID'] && $now[3]['sessionID'] === Logger::fingerprintOf($id['codesonly'])
        && $now[8]['sessionID'] === Logger::fingerprintOf($id['cutshort']) && $now[12000]['sessionID'] === Logger::fingerprintOf($id['late']) && $now[1]['sessionID'] !== $now[3]['sessionID'],
    json_encode(array_map(static fn (array $r): ?string => $r['sessionID'], $now))
);
ok(
    'every old copy is blanked, the one that was only "[]" included; an old record with no session keeps its empty identifier',
    $now[1]['sessionDataSnapshot'] === null && $now[2]['sessionDataSnapshot'] === null && $now[6]['sessionDataSnapshot'] === null && $now[6]['sessionID'] === ''
        && $now[8]['sessionDataSnapshot'] === null && $now[10]['sessionDataSnapshot'] === null,
    json_encode(array_map(static fn (array $r): ?string => $r['sessionDataSnapshot'], $now))
);
$untouchedAfter = q('SELECT logID, MD5(CONCAT_WS(\'|\', COALESCE(sessionID, \'~\'), COALESCE(sessionDataSnapshot, \'~\'), COALESCE(activityDescription, \'~\'))) AS mark FROM tblActivityLogs WHERE logID IN (' . implode(',', array_map('intval', array_column($untouchedBefore, 'logID'))) . ') ORDER BY logID');
ok(
    'A RECORD ALREADY IN THE NEW FORM IS LEFT EXACTLY AS IT IS: the made-up one keeps its fingerprint and its facts, and so does every record written while old ones waited',
    $untouchedAfter === $untouchedBefore && $now[5]['sessionID'] === Logger::fingerprintOf($id['newform']) && $now[5]['sessionDataSnapshot'] === $newFacts && $now[7]['sessionID'] === null,
    count($untouchedBefore) . ' before, ' . count($untouchedAfter) . ' after; the made-up one: ' . json_encode($now[5])
);

// K3. The key records.
$lines = q('SELECT logID, userID, siteID, activityDescription, sessionID, sessionDataSnapshot, requestHeaders FROM tblActivityLogs WHERE activityType = ? ORDER BY userID', 's', [ActivityLogCleaner::KEY_LINE_TYPE]);
ok(
    'AN ACCOUNT WHOSE OLD RECORDS HELD AN AUTHENTICATOR KEY GETS ITS RECORD: the one named by the session copy, the one whose account number was text, and the one whose copy was cut short',
    array_map('intval', array_column($lines, 'userID')) === [PEOPLE['keyed'], PEOPLE['keyedoff'], PEOPLE['cutshort']],
    'records for accounts: ' . json_encode(array_column($lines, 'userID'))
);
ok(
    'an account with only BACKUP CODES in its old records gets none, and neither does one whose set-up key was kept encrypted',
    (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = ? AND userID = ?', 'si', [ActivityLogCleaner::KEY_LINE_TYPE, PEOPLE['codesonly']])['n'] === 0
);
ok(
    'ONE RECORD FOR AN ACCOUNT HOWEVER THE PIECES FALL: the account whose key was in two pieces has one, written by the first piece',
    $linesAt[0] === 3 && $linesAt[count($linesAt) - 1] === 3 && array_sum(array_column($calls, 'keyLines')) === 3,
    'key records after each call: ' . json_encode($linesAt) . '; written by each call: ' . json_encode(array_column($calls, 'keyLines'))
);
ok('the key that named no account is counted and said, not passed over', array_sum(array_column($calls, 'keysWithoutAccount')) === 1 && str_contains(ActivityLogCleaner::inWords($calls[0]), 'without saying whose') === true, json_encode(array_column($calls, 'keysWithoutAccount')));
// Every secret of the made-up world, against every column of every record:
// whole, and by EVERY run of eight of its characters (see logHoldsRunOf()).
// Nothing printed below holds any of a secret — only how long it was and
// where in it the run that was found begins.
$secrets = array_merge(array_values($key), $oldCodes, array_values($id), array_values($oldConsent));
$leaked  = [];
foreach ($secrets as $secret) {
    $at = logHoldsRunOf($secret);
    if ($at >= 0) {
        $leaked[] = 'a secret of ' . strlen($secret) . ' characters, from character ' . ($at + 1) . ' of it';
    }
}
ok(
    'NO SECRET IS LEFT ANYWHERE IN THE LOG: no key, no backup code and no session identifier, whole OR BY ANY RUN OF EIGHT OF ITS CHARACTERS, in any column of any record, the key records included',
    $leaked === [],
    count($leaked) . ' of ' . count($secrets) . ' still found: ' . json_encode($leaked)
);
ok(
    'a key record says what happened and what to do, in plain words, and carries nothing about a session',
    str_contains((string) $lines[0]['activityDescription'], 'readable copy of an authenticator key') === true && str_contains((string) $lines[0]['activityDescription'], 'set it up again') === true
        && $lines[0]['sessionID'] === null && $lines[0]['sessionDataSnapshot'] === null && $lines[0]['requestHeaders'] === null && (int) $lines[0]['siteID'] === 1,
    json_encode($lines[0])
);
$listed = array_map('intval', explode(',', (string) stored(ActivityLogCleaner::KEY_LINES_KEY)));
sort($listed);
$lineIds = array_map('intval', array_column($lines, 'logID'));
sort($lineIds);
ok('the cleaner keeps the numbers of the key records it wrote, which is how the health page finds them without reading the log', $listed === $lineIds, 'kept: ' . json_encode($listed) . '; written: ' . json_encode($lineIds));

// K4. The real button, with nothing left to do.
$done  = post($admin, '/admin/upgrade', '/admin/upgrade', ['clean_old_records' => '1']);
$shown = req($admin, 'GET', '/admin/upgrade');
ok(
    'with none left, the Upgrade page shows no card about old records and no cleaning button, and a form sent anyway is answered "none are left"',
    $shown['status'] === 200 && str_contains($shown['body'], 'id="upgrade-old-records"') === false && str_contains($shown['body'], 'clean_old_records') === false && $done['status'] === 302,
    said($shown) . '; the form: ' . said($done)
);
$health = req($admin, 'GET', '/admin/maintenance/health');
ok('and the health page says NOTHING about old records: a line that is always there is not read', healthCard($health['body'], 'Old activity records') === '' && str_contains($health['body'], 'Old activity records') === false);

// =============================================================================
head('H. Accounts that still sign in with a key the log once held');
// =============================================================================
ok('the cleaner counts two: the two accounts with two-step sign-in ON (the third had it off when its record was written)', ActivityLogCleaner::accountsWithReadableKey($db) === 2, json_encode(ActivityLogCleaner::accountsWithReadableKey($db)));
$card = healthCard($health['body'], 'Authenticator keys');
ok(
    'the health page tells a global administrator so, in amber, names nobody, and says what to do',
    str_starts_with($card, 'warning | 2 to replace | 2 accounts sign in with an authenticator key') === true && str_contains($card, 'set it up again') === true
        && str_contains($card, 'Selftest') === false && str_contains($card, 'al622') === false && str_contains($card, (string) PEOPLE['keyed']) === false,
    $card
);
ok('an administrator of one organisation is not shown that line either', healthCard(req($orgAdmin, 'GET', '/admin/maintenance/health')['body'], 'Authenticator keys') === '');
// Two-step sign-in set up again: the set-up page stores a NEW encrypted key.
q("UPDATE tblUsers SET totpSecret = 'stored-encrypted-key-of-keyed-AFTER', totpEnabled = 1 WHERE userID = ?", 'i', [PEOPLE['keyed']]);
ok('AN ACCOUNT STOPS BEING COUNTED WHEN ITS STORED KEY CHANGES (two-step sign-in set up again): one is left', ActivityLogCleaner::accountsWithReadableKey($db) === 1 && str_starts_with(healthCard(req($admin, 'GET', '/admin/maintenance/health')['body'], 'Authenticator keys'), 'warning | 1 to replace | 1 account signs in') === true, json_encode(ActivityLogCleaner::accountsWithReadableKey($db)));
// Two-step sign-in switched off: the page empties the stored key.
q('UPDATE tblUsers SET totpSecret = NULL, totpEnabled = 0 WHERE userID = ?', 'i', [PEOPLE['cutshort']]);
ok('AND WHEN TWO-STEP SIGN-IN IS SWITCHED OFF: none is left, and the health page says nothing', ActivityLogCleaner::accountsWithReadableKey($db) === 0 && str_contains(req($admin, 'GET', '/admin/maintenance/health')['body'], 'Authenticator keys') === false, json_encode(ActivityLogCleaner::accountsWithReadableKey($db)));
// An account that had it OFF when its record was written, and switches it on later, has a new key by then.
q("UPDATE tblUsers SET totpSecret = 'stored-encrypted-key-of-keyedoff-NEW', totpEnabled = 1 WHERE userID = ?", 'i', [PEOPLE['keyedoff']]);
ok('an account that had two-step sign-in off and sets it up later is not counted: its key was made after the fix', ActivityLogCleaner::accountsWithReadableKey($db) === 0, json_encode(ActivityLogCleaner::accountsWithReadableKey($db)));
// Put the two back as the upgrade found them, for part R.
q("UPDATE tblUsers SET totpEnabled = 1, totpSecret = 'stored-encrypted-key-of-keyed-BEFORE' WHERE userID = ?", 'i', [PEOPLE['keyed']]);
q("UPDATE tblUsers SET totpEnabled = 1, totpSecret = 'stored-encrypted-key-of-cutshort-BEFORE' WHERE userID = ?", 'i', [PEOPLE['cutshort']]);
q('UPDATE tblUsers SET totpEnabled = 0, totpSecret = NULL WHERE userID = ?', 'i', [PEOPLE['keyedoff']]);

// =============================================================================
head('N. New records, on the real pages');
// =============================================================================
$cookies = []; // every identifier any browser of this run has held
$collect = static function (string $jar) use (&$cookies): string {
    $cookie = cookieOf($jar);
    if ($cookie !== '') {
        $cookies[$cookie] = true;
    }

    return $cookie;
};

// N1. A sign-in.
$jarA   = newJar('n-first');
$form   = req($jarA, 'GET', '/login');
$before = $collect($jarA);
$mark   = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
$in     = req($jarA, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => login('admin'), 'password' => $password]);
$cookieA = $collect($jarA);
$signIn  = myRecords("logID > ? AND activityType = 'LoginLocal'", 'i', [$mark]);
ok(
    'a sign-in writes its record, and the browser is given a new identifier',
    $in['status'] === 302 && count($signIn) === 1 && $before !== '' && $cookieA !== '' && $cookieA !== $before,
    said($in) . '; records: ' . count($signIn) . '; the identifier changed: ' . json_encode($cookieA !== $before)
);
ok(
    'that record holds a FINGERPRINT of an identifier this browser held, and the named facts',
    count($signIn) === 1 && in_array($signIn[0]['sessionID'], [Logger::fingerprintOf($before), Logger::fingerprintOf($cookieA)], true) === true && isFacts($signIn[0]['sessionDataSnapshot']) === true,
    json_encode($signIn[0] ?? null)
);

// N2. A settings change, twice, in that session.
if (one("SELECT settingID FROM tblSettings WHERE settingKey = 'site.tagline' AND siteID IS NULL") === null) {
    refuse('the test database has no site.tagline setting to change');
}
$mark = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
changeTagline($jarA);
changeTagline($jarA);
$saves = myRecords("logID > ? AND activityType = 'SettingsUpdate'", 'i', [$mark]);
ok(
    'a settings change writes its record with the fingerprint of the browser\'s identifier, and TWO RECORDS OF ONE SESSION CARRY THE SAME FINGERPRINT',
    count($saves) === 2 && $saves[0]['sessionID'] === Logger::fingerprintOf($cookieA) && $saves[1]['sessionID'] === $saves[0]['sessionID'] && $collect($jarA) === $cookieA,
    count($saves) . ' record(s): ' . json_encode(array_column($saves, 'sessionID'))
);
$facts = json_decode((string) ($saves[0]['sessionDataSnapshot'] ?? ''), true);
ok(
    'the copy of the session is the six named facts and nothing else: signed in, this account, its organisation, nobody waiting for a code, no kiosk',
    count($saves) === 2 && isFacts($saves[0]['sessionDataSnapshot']) === true && $facts['signedIn'] === true && $facts['userID'] === PEOPLE['admin']
        && ($facts['organisationID'] === null || $facts['organisationID'] === 1) && $facts['waitingForCodeUserID'] === null && $facts['kioskUserID'] === null,
    (string) ($saves[0]['sessionDataSnapshot'] ?? '(no record)')
);

// N3. A second browser, the same account: another session.
$jarB    = signIn('admin');
$cookieB = $collect($jarB);
$mark    = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
changeTagline($jarB);
$other = myRecords("logID > ? AND activityType = 'SettingsUpdate'", 'i', [$mark]);
ok(
    'RECORDS OF TWO SESSIONS CARRY DIFFERENT FINGERPRINTS, the same person in two browsers included',
    count($other) === 1 && $other[0]['sessionID'] === Logger::fingerprintOf($cookieB) && $cookieB !== $cookieA && $other[0]['sessionID'] !== $saves[0]['sessionID'],
    json_encode([$saves[0]['sessionID'] ?? null, $other[0]['sessionID'] ?? null])
);

// N4. Secrets a page might put in the session.
$planted = ['plain' => 'PLANTED-' . bin2hex(random_bytes(12)), 'asFact' => 'PLANTED-AS-A-FACT-' . bin2hex(random_bytes(8)), 'inList' => 'PLANTED-IN-A-LIST-' . bin2hex(random_bytes(8))];
plant($jarB, 'al622_made_up_secret', $planted['plain']);
plant($jarB, 'kiosk_user_id', $planted['asFact']);                 // text under the name of a fact
plant($jarB, 'al622_made_up_codes', [$planted['inList'], 'x']);
$mark = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
changeTagline($jarB);
$after = myRecords("logID > ? AND activityType = 'SettingsUpdate'", 'i', [$mark]);
ok(
    'the planted values really were in the session when the record was written',
    count($after) === 1 && str_contains(sessionText($jarB), $planted['plain']) === true && str_contains(sessionText($jarB), $planted['asFact']) === true && str_contains(sessionText($jarB), $planted['inList']) === true,
    count($after) . ' record(s)'
);
ok(
    'A MADE-UP SECRET PUT IN THE SESSION DOES NOT REACH THE LOG: not as a value of its own, not inside a list, and not as text kept under the name of a fact',
    logHolds($planted['plain']) === 0 && logHolds($planted['asFact']) === 0 && logHolds($planted['inList']) === 0 && logHolds('PLANTED-') === 0,
    'records holding each: ' . json_encode([logHolds($planted['plain']), logHolds($planted['asFact']), logHolds($planted['inList'])])
);
$facts = json_decode((string) ($after[0]['sessionDataSnapshot'] ?? ''), true);
plant($jarB, 'kiosk_user_id', '31');                               // the same name, now digits written as text
$mark = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
changeTagline($jarB);
$digits = myRecords("logID > ? AND activityType = 'SettingsUpdate'", 'i', [$mark]);
ok(
    'the copy is still the six facts: text under a fact\'s name is left out, and a number written as digits is kept as a number',
    count($after) === 1 && isFacts($after[0]['sessionDataSnapshot']) === true && $facts['kioskUserID'] === null && $facts['userID'] === PEOPLE['admin']
        && count($digits) === 1 && isFacts($digits[0]['sessionDataSnapshot']) === true && json_decode((string) $digits[0]['sessionDataSnapshot'], true)['kioskUserID'] === 31,
    (string) ($after[0]['sessionDataSnapshot'] ?? '(no record)') . ' then ' . (string) ($digits[0]['sessionDataSnapshot'] ?? '(no record)')
);

// N5. A two-step set-up, on the real page, by the second administrator.
$jarC   = signIn('second');
$setup  = req($jarC, 'GET', '/auth/2fa/setup');
$shownKey = keyOnSetupPage($setup['body']);
$kept     = preg_match('/totp_setup_secret\|s:\d+:"([^"]*)";/', sessionText($jarC), $m) === 1 ? $m[1] : '';
$collect($jarC);
ok('the set-up page shows the person a new authenticator key, and keeps one in the session', $setup['status'] === 200 && $shownKey !== '' && $kept !== '', said($setup) . '; a key shown: ' . json_encode($shownKey !== '') . '; kept in the session: ' . json_encode($kept !== ''));
$mark = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
changeTagline($jarC);
$open = myRecords("logID > ? AND activityType = 'SettingsUpdate'", 'i', [$mark]);
// Each search is made once and kept: a run search is one query per position,
// and asking again only to print the detail would double them all.
$shownKeyAt = logHoldsRunOf($shownKey);
$keptAt     = logHoldsRunOf($kept);
ok(
    'A RECORD WRITTEN WHILE THE SET-UP PAGE IS OPEN DOES NOT HOLD THE KEY, in the form the person is shown or in the form the session keeps',
    count($open) === 1 && isFacts($open[0]['sessionDataSnapshot']) === true && $shownKeyAt === -1 && $keptAt === -1,
    count($open) . ' record(s); a run of the shown key found at character ' . ($shownKeyAt + 1) . '; of the kept form at ' . ($keptAt + 1) . ' (0 means none)'
);
$mark    = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
// The form is opened again for its token: a form token is used once, and the
// settings change above has used the one the first page carried. The key is
// the same one, because the session still holds it.
$setup   = req($jarC, 'GET', '/auth/2fa/setup');
$confirm = req($jarC, 'POST', '/auth/2fa/setup', ['csrf_token' => token($setup['body']), 'code' => Totp::getCode($shownKey)]);
$midway  = sessionText($jarC);
// WHERE THE CODES ARE READ FROM, AND WHY IT CHANGED (27 October 2026). This
// used to expect the POST to send the browser somewhere else (302) and then
// read the codes off the page it landed on, and also out of the session.
// Since #613 neither is so: the set-up page answers the POST ITSELF with the
// codes on it (200), because they are shown only in the answer to the request
// that made them, and nothing readable is left in the session. The session is
// still looked at — it must hold no code, and if it ever does again, the
// count below will find them and the leak checks will say so.
// A code is eight characters of 0 to 9 and A to F, each in a <code> of its
// own: the same shape codesOn() reads in tools/two-step-selftest.php.
$codesKept = preg_match('/totp_backup_codes\|(a:\d+:\{.*?\})/s', $midway, $m) === 1 ? array_values(array_filter((array) @unserialize($m[1]), 'is_string')) : [];
preg_match_all('#<code[^>]*>\s*([0-9A-F]{8})\s*</code>#', $confirm['body'], $shownCodes);
$codes   = array_values(array_unique(array_merge($codesKept, array_values(array_diff($shownCodes[1], [$shownKey])))));
$enabled = myRecords("logID > ? AND activityType = 'TotpEnabled'", 'i', [$mark]);
ok(
    'the right six-digit code switches two-step sign-in on, a record is written, and the answer to that very request shows the person their eight backup codes',
    $confirm['status'] === 200 && count($enabled) === 1 && (int) one('SELECT totpEnabled FROM tblUsers WHERE userID = ?', 'i', [PEOPLE['second']])['totpEnabled'] === 1
        && count($shownCodes[1]) === 8 && count($codes) === 8 && $codesKept === [],
    said($confirm) . '; records: ' . count($enabled) . '; codes on the answer: ' . count($shownCodes[1]) . '; codes left readable in the session: ' . count($codesKept)
);
changeTagline($jarC);
// THE COUNT OF CODES IS PART OF THE CHECK, not only the leaks. When the key
// could not be read off the page (see keyOnSetupPage()), two-step sign-in was
// never switched on, no code was ever made, and this check used to report
// "0 of 0 backup code(s) found in the log" and PASS — a check that cannot see
// the leak it is named after. The set-up page makes eight codes, so fewer
// than eight here means the run never got far enough to prove anything.
$codeLeaks  = array_values(array_filter($codes, static fn (string $code): bool => logHoldsRunOf($code) >= 0));
$shownKeyAt = logHoldsRunOf($shownKey);
$keptAt     = logHoldsRunOf($kept);
ok(
    'NO BACKUP CODE AND NO KEY IS IN THE LOG after the set-up, in the record of switching it on or in any record that session wrote afterwards — with all eight codes really in hand to look for',
    $codeLeaks === [] && count($codes) >= 8 && $shownKeyAt === -1 && $keptAt === -1 && count($enabled) === 1 && isFacts($enabled[0]['sessionDataSnapshot']) === true,
    count($codeLeaks) . ' of ' . count($codes) . ' backup code(s) found in the log (fewer than 8 codes means the set-up did not finish and this check proved nothing)'
);

// N6. Every identifier this run's browsers have held, against every record.
foreach ([$admin, $member, $orgAdmin, $jarA, $jarB, $jarC] as $jar) {
    $collect($jar);
}
$found = array_values(array_filter(array_keys($cookies), static fn (string $cookie): bool => logHolds($cookie) > 0));
ok(
    'NO VALUE ANYWHERE IN THE ACTIVITY LOG IS A BROWSER\'S COOKIE: every identifier any browser of this run has held, against every column of every record',
    count($cookies) >= 7 && $found === [],
    count($found) . ' of ' . count($cookies) . ' identifier(s) found in the log'
);
$errorsHold = static fn (string $cookie): int => (int) one("SELECT COUNT(*) AS n FROM tblErrors WHERE CONCAT_WS('|', COALESCE(errorTitle, ''), COALESCE(errorDetail, ''), COALESCE(requestURL, ''), COALESCE(requestHeaders, '')) LIKE ?", 's', ['%' . $cookie . '%'])['n'];
// The count is part of this check as well: with no identifier in hand there
// would be nothing to look for, and "none found" would mean nothing. (The
// same lesson as keyOnSetupPage() and logHolds(): a check that passes while
// proving nothing is worse than one that fails.)
ok(
    'nor is one in the error log',
    count($cookies) >= 7 && array_sum(array_map($errorsHold, array_keys($cookies))) === 0,
    count($cookies) . ' identifier(s) looked for'
);
$mine = myRecords('1 = 1');
$odd  = array_values(array_filter($mine, static fn (array $r): bool => ($r['sessionID'] !== null && preg_match('/^sha256:[0-9a-f]{64}$/', (string) $r['sessionID']) !== 1) || ($r['sessionDataSnapshot'] !== null && isFacts($r['sessionDataSnapshot']) === false)));
ok(
    'EVERY record this run\'s requests wrote holds a fingerprint or nothing, and the six facts or nothing',
    count($mine) >= 12 && $odd === [],
    count($odd) . ' of ' . count($mine) . ' record(s) hold something else; the first: ' . json_encode($odd[0] ?? null)
);

// N7. The consent page.
$jarD   = newJar('n-consent');
$form   = req($jarD, 'GET', '/login');
$cookieD = cookieOf($jarD);
$answer  = req($jarD, 'POST', '/privacy/consent', ['csrf_token' => token($form['body']), 'type' => 'cookies', 'decision' => 'accept']);
$row     = one('SELECT sessionID FROM tblConsentLog WHERE userAgent = ? ORDER BY consentID DESC LIMIT 1', 's', [AGENT]);
ok(
    'THE CONSENT PAGE stores the fingerprint of the session that decided, never its identifier',
    $answer['status'] === 200 && str_contains($answer['body'], '"status":"ok"') === true && $cookieD !== '' && $row !== null && $row['sessionID'] === Logger::fingerprintOf($cookieD)
        && (int) one('SELECT COUNT(*) AS n FROM tblConsentLog WHERE sessionID = ?', 's', [$cookieD])['n'] === 0,
    said($answer) . ' ' . substr($answer['body'], 0, 80) . '; stored: ' . json_encode($row)
);

// N7b. A page that answers the browser first and closes its session early.
//      "Forgot password" does: it sends its answer, closes the session, and
//      only then writes its record.
$jarE    = newJar('n-forgot');
$form    = req($jarE, 'GET', '/forgot-password');
$cookieE = cookieOf($jarE);
$mark    = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
$asked   = req($jarE, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => 'nobody-at-all.al622@selftest.invalid']);
$late    = [];
for ($wait = 0; $wait < 50 && $late === []; $wait++) {
    usleep(100000); // the record is written after the answer has gone
    $late = myRecords("logID > ? AND activityType = 'PasswordResetNoUser'", 'i', [$mark]);
}
$facts = json_decode((string) ($late[0]['sessionDataSnapshot'] ?? ''), true);
ok(
    'a page that answers first and CLOSES ITS SESSION EARLY ("Forgot password") still writes the fingerprint of its session and the facts: nobody signed in',
    $asked['status'] === 302 && $cookieE !== '' && count($late) === 1 && $late[0]['sessionID'] === Logger::fingerprintOf($cookieE)
        && isFacts($late[0]['sessionDataSnapshot']) === true && $facts['signedIn'] === false && $facts['userID'] === null && logHolds($cookieE) === 0,
    said($asked) . '; records: ' . count($late) . '; ' . json_encode($late[0] ?? null)
);

// N8. PHP and the database make the same fingerprint, for every shape an identifier can have.
$alphabets = ['0123456789abcdef', '0123456789abcdefghijklmnopqrstuv', '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-'];
$differ    = 0;
$tried     = 0;
foreach ($alphabets as $letters) {
    foreach ([1, 22, 26, 32, 40, 48, 64, 128, 255] as $length) {
        $identifier = '';
        for ($i = 0; $i < $length; $i++) {
            $identifier .= $letters[random_int(0, strlen($letters) - 1)];
        }
        $tried++;
        $differ += (string) one("SELECT CONCAT('sha256:', SHA2(?, 256)) AS f", 's', [$identifier])['f'] === Logger::fingerprintOf($identifier) ? 0 : 1;
    }
}
ok(
    'PHP AND THE DATABASE MAKE THE SAME FINGERPRINT for identifiers of every length and alphabet PHP uses; it starts "sha256:", is 71 characters, and no identifier can look like one',
    $differ === 0 && $tried === 27 && preg_match('/^sha256:[0-9a-f]{64}$/', Logger::fingerprintOf('x')) === 1 && Logger::FINGERPRINT_MARK === 'sha256:'
        && preg_match('/^[a-zA-Z0-9,-]{1,256}$/', Logger::fingerprintOf('x')) !== 1,
    $differ . ' of ' . $tried . ' differ'
);

// =============================================================================
head('D. A second run changes nothing, and a code made after the upgrade is kept');
// =============================================================================
q('INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (?, ?)', 'is', [PEOPLE['member'], 'a code made AFTER the upgrade']);
$codesBefore = (int) one('SELECT COUNT(*) AS n FROM tblTotpBackupCodes')['n'];
$logBefore   = one("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', logID, COALESCE(sessionID, '~'), COALESCE(sessionDataSnapshot, '~')))), 0) AS mark FROM tblActivityLogs");
$consentBefore = q('SELECT consentID, sessionID FROM tblConsentLog ORDER BY consentID');
$again = runMigration();
ok(
    'MIGRATION 220 RUN A SECOND TIME DELETES NO BACKUP CODE: the one made after the upgrade, and the set the set-up page has just made, are all still there',
    $again['success'] === true && (int) one('SELECT COUNT(*) AS n FROM tblTotpBackupCodes')['n'] === $codesBefore && $codesBefore >= 2
        && (int) one('SELECT COUNT(*) AS n FROM tblTotpBackupCodes WHERE userID = ?', 'i', [PEOPLE['member']])['n'] === 1,
    json_encode($again) . '; codes before ' . $codesBefore . ', after ' . one('SELECT COUNT(*) AS n FROM tblTotpBackupCodes')['n']
);
ok('and it does not fingerprint a fingerprint: every consent row is as it was', q('SELECT consentID, sessionID FROM tblConsentLog ORDER BY consentID') === $consentBefore);
// The cleaner again, FROM THE START: its "how far" is taken away, as if it had never run.
q('DELETE FROM tblSettings WHERE settingKey = ?', 's', [ActivityLogCleaner::THROUGH_KEY]);
$linesBefore = (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = ?', 's', [ActivityLogCleaner::KEY_LINE_TYPE])['n'];
$rerun       = ActivityLogCleaner::run($db);
$logAfter    = one("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', logID, COALESCE(sessionID, '~'), COALESCE(sessionDataSnapshot, '~')))), 0) AS mark FROM tblActivityLogs");
ok(
    'THE CLEANER RUN AGAIN FROM THE FIRST RECORD CHANGES NOTHING: not one record differs, and no account gets a second key record',
    $rerun['finished'] === true && $rerun['cleaned'] === 0 && $rerun['keyLines'] === 0 && $rerun['error'] === '' && $logAfter === $logBefore
        && (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = ?', 's', [ActivityLogCleaner::KEY_LINE_TYPE])['n'] === $linesBefore,
    json_encode($rerun) . '; the log before: ' . json_encode($logBefore) . ', after: ' . json_encode($logAfter)
);
ok('it finished well inside its time: the whole log in one call', $rerun['pieces'] >= 2 && $rerun['slowestPiece'] < 5.0, 'pieces: ' . $rerun['pieces'] . '; slowest: ' . round($rerun['slowestPiece'], 3) . ' s');

// =============================================================================
head('R. A backup taken before the upgrade, put back after it');
// =============================================================================
$restored = (new DbBackup(connect(), $child . $sep . 'test-backups'))->restoreAll((string) $older['directory'], false);
$db->close();
$db      = connect(); // what this connection had read is from before the restore
$brought = upgradeState();
ok(
    'the portal\'s own restore puts the older backup back: the old identifiers, the old copies and the old backup codes are there again, with no record of migration 220 and no "how far"',
    $restored['success'] === true && $brought === 'identifiers ' . OLD_IDENTIFIERS . ', old copies ' . OLD_COPIES . ', backup codes 16, consent identifiers 2, 220 recorded 0, key records 0, how far (no row)',
    (string) ($restored['error'] ?? '') . ' ' . $brought
);
ok('the key and the backup codes are readable in the log again, which is exactly what must not be left like that', logHolds($key['keyed']) >= 1 && logHolds($oldCodes[0]) >= 1);
$page    = req($admin, 'GET', '/admin/upgrade');
$pressed = req($admin, 'POST', '/admin/upgrade', ['csrf_token' => token($page['body'])]);
$cleanedAgain = upgradeState();
ok(
    'THE NEXT PRESS OF THE UPGRADE BUTTON CLEANS IT ALL AGAIN, FROM THE START, AND DELETES THE OLD BACKUP CODES AGAIN',
    str_contains($page['body'], MIGRATION) === true && $pressed['status'] === 200
        && str_contains($cleanedAgain, 'identifiers 0, old copies 0, backup codes 0, consent identifiers 0, 220 recorded 1, key records 3, how far all') === true,
    said($pressed) . '; ' . $cleanedAgain
);
$result = part($pressed['body'], 'upgrade-old-records-result');
ok(
    'the page says, in plain words, how many old records were cleaned, that none are left, and that three accounts were found with a readable key',
    preg_match('/^\d+ old activity records were cleaned just now\. None are left\./', $result) === 1 && str_contains($result, '3 accounts were found') === true
        && str_contains($result, '1 old record held an authenticator key without saying whose') === true && str_contains($pressed['body'], 'id="upgrade-old-records-form"') === false,
    $result
);
ok(
    'each of the three accounts has ONE key record again, the secrets are gone from the log again, and the health page counts the two with two-step sign-in on',
    array_map('intval', array_column(q('SELECT userID FROM tblActivityLogs WHERE activityType = ? ORDER BY userID', 's', [ActivityLogCleaner::KEY_LINE_TYPE]), 'userID')) === [PEOPLE['keyed'], PEOPLE['keyedoff'], PEOPLE['cutshort']]
        && logHolds($key['keyed']) === 0 && logHolds($oldCodes[0]) === 0 && logHolds($id['keyed']) === 0 && ActivityLogCleaner::accountsWithReadableKey($db) === 2,
    'key records: ' . json_encode(array_column(q('SELECT userID FROM tblActivityLogs WHERE activityType = ? ORDER BY userID', 's', [ActivityLogCleaner::KEY_LINE_TYPE]), 'userID')) . '; counted: ' . json_encode(ActivityLogCleaner::accountsWithReadableKey($db))
);
$said = one("SELECT activityDescription FROM tblActivityLogs WHERE activityType = 'ActivityLogCleaned' ORDER BY logID DESC LIMIT 1");
ok('the press left its own record in the activity log, in the same words', $said !== null && str_contains((string) $said['activityDescription'], 'old activity records were cleaned just now') === true, json_encode($said));

// =============================================================================
head('I. The install script\'s two statements for migration 220, in a scratch database');
// =============================================================================
// Read out of the real file: every INSERT that names the migration or the
// cleaner's setting. There must be exactly two.
$script     = (string) file_get_contents($webSrc . $sep . '_sql' . $sep . 'full_schema.sql');
$script     = (string) preg_replace('/^\s*--.*$/m', '', $script);
$statements = array_values(array_filter(
    array_map('trim', (array) preg_split('/;\s*\n/', $script)),
    static fn (string $s): bool => str_starts_with($s, 'INSERT INTO') === true && (str_contains($s, MIGRATION) === true || str_contains($s, ActivityLogCleaner::THROUGH_KEY) === true)
));
ok(
    'the install script holds exactly two statements for migration 220, and each one is written with a test ("only where …")',
    count($statements) === 2 && str_contains($statements[0] . $statements[1], 'VALUES') === false && substr_count($statements[0] . $statements[1], 'WHERE NOT EXISTS') === 2,
    count($statements) . ' found: ' . json_encode(array_map(static fn (string $s): string => substr((string) preg_replace('/\s+/', ' ', $s), 0, 120), $statements))
);
$db->query('CREATE DATABASE `' . $scratchDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$state['scratchDb'] = $scratchDb;
$scratch = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $scratchDb, $creds['db_port']);
$scratch->set_charset('utf8mb4');
foreach (['tblSettings', 'tblMigrations', 'tblActivityLogs', 'tblConsentLog', 'tblTotpBackupCodes'] as $table) {
    $scratch->query('CREATE TABLE `' . $table . '` LIKE `' . $dbName . '`.`' . $table . '`'); // the same columns and keys; no links to other tables
}
/** The install script's part for migration 220, then migration 220 itself: what the installer runs, for these tables. */
$installThenMigrate = static function () use ($scratch, $statements, $webSrc, $sep): string {
    foreach ($statements as $statement) {
        $scratch->query($statement);
    }
    $afterScript = (int) $scratch->query("SELECT COUNT(*) FROM tblMigrations WHERE filename = '" . MIGRATION . "'")->fetch_row()[0];
    $ran         = (new Migrator($scratch, $webSrc . $sep . '_sql'))->runOne(MIGRATION);

    return 'recorded by the install script ' . $afterScript . ', migration ' . ($ran['success'] === true ? 'ran' : 'FAILED: ' . $ran['error'])
        . ', codes ' . $scratch->query('SELECT COUNT(*) FROM tblTotpBackupCodes')->fetch_row()[0]
        . ', how far ' . ($scratch->query("SELECT settingValue FROM tblSettings WHERE settingKey = '" . ActivityLogCleaner::THROUGH_KEY . "'")->fetch_row()[0] ?? '(no row)')
        . ', consent identifiers ' . $scratch->query("SELECT COUNT(*) FROM tblConsentLog WHERE sessionID IS NOT NULL AND sessionID NOT LIKE 'sha256:%'")->fetch_row()[0];
};
$new = $installThenMigrate();
ok(
    'A NEW INSTALLATION has nothing to do and says so: the install script records migration 220 and writes "every record has been gone through", and the migration then deletes nothing',
    $new === 'recorded by the install script 1, migration ran, codes 0, how far all, consent identifiers 0',
    $new
);
// An OLDER database: records in the log, backup codes, consent identifiers, and no trace of migration 220.
$scratch->query('DELETE FROM tblMigrations');
$scratch->query('DELETE FROM tblSettings');
$scratch->query("INSERT INTO tblActivityLogs (activityType, sessionID, sessionDataSnapshot) VALUES ('Old', '" . madeUpIdentifier() . "', '{\"user_id\":5}')");
$scratch->query("INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (5, 'an old code'), (5, 'another old code')");
$scratch->query("INSERT INTO tblConsentLog (siteID, sessionID, consentType, decision) VALUES (1, '" . madeUpIdentifier() . "', 'cookies', 'accept')");
$carryOn = $installThenMigrate();
ok(
    'THE INSTALLER\'S "CARRY ON" OVER AN OLDER DATABASE STILL DOES THE WORK: the install script does NOT record migration 220 there and does NOT say the log is clean; the migration deletes the old codes and fingerprints the consent log',
    $carryOn === 'recorded by the install script 0, migration ran, codes 0, how far (no row), consent identifiers 0',
    $carryOn . ' (if a code is left, the install script recorded the migration before it ran: the trap of #604)'
);
// The same database later: upgraded, and somebody has made a new set of codes.
$scratch->query("INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (5, 'a code made after the upgrade'), (6, 'and another')");
$later = $installThenMigrate();
ok(
    '"CARRY ON" OVER A DATABASE THAT HAS ALREADY BEEN UPGRADED DELETES NO CODE made since, and still does not claim the log is clean',
    $later === 'recorded by the install script 1, migration ran, codes 2, how far (no row), consent identifiers 0',
    $later
);
// THE ONE CASE WHERE THE TWO TESTS DIFFER: backup codes, and an EMPTY
// activity log. Added 27 October 2026, because the independent check planted
// exactly this fault — the install script recording migration 220 where the
// ACTIVITY LOG is empty instead of where NO BACKUP CODE exists — and nothing
// here saw it. The three cases above cannot: a new database has neither codes
// nor records, and the older database above has both, so the wrong test
// happens to give the right answer in all three. The end-to-end migration
// harness missed it for the same reason (its phase 7 database holds old
// activity records); a coda at the end of that phase now covers it too.
// What the wrong test would cost: in a database whose log has been emptied by
// retention, or that never wrote a record, every backup code made before the
// fix would stay alive, readable in every older backup, with the migration
// recorded as done so it can never run again.
$scratch->query('DELETE FROM tblMigrations');
$scratch->query('DELETE FROM tblSettings');
$scratch->query('DELETE FROM tblActivityLogs');
$scratch->query('DELETE FROM tblConsentLog');
$scratch->query('DELETE FROM tblTotpBackupCodes');
$scratch->query("INSERT INTO tblTotpBackupCodes (userID, codeHash) VALUES (7, 'an old code, in a database whose log is empty'), (7, 'and another')");
$emptyLog = $installThenMigrate();
ok(
    'BACKUP CODES WITH AN EMPTY ACTIVITY LOG: the install script must not record migration 220 there (a code exists, so the migration still has work to do), although it does mark the empty log as clean',
    $emptyLog === 'recorded by the install script 0, migration ran, codes 0, how far all, consent identifiers 0',
    $emptyLog . ' (if the codes are still there, the install script is testing the ACTIVITY LOG where it must test for a BACKUP CODE: the two agree in every other case, which is why only this one shows it)'
);
$scratch->close();
$db->query('DROP DATABASE `' . $scratchDb . '`');
$state['scratchDb'] = '';

// =============================================================================
head('B. Piece BOUNDARIES: a DENSE log, cleaned end to end, read back from the database');
// =============================================================================
// WHY THIS PART EXISTS, AND WHY IT IS NOT PART OF PART K (added 27 October
// 2026 after the independent check of this package).
//
// The check planted a ONE-CHARACTER fault in ActivityLogCleaner::onePiece():
// "how far" written as $to + 1 instead of $to. That makes the cleaner skip the
// record sitting one past the end of every piece. Those records keep the
// browser session's identifier AND the whole copy of the session FOR EVER,
// while run() answers "finished" and left() answers 0 — so the Upgrade page
// and the health page both say there is nothing left to clean. A privacy gap
// that reports itself as success. Part K passed all of its checks on it.
//
// Part K could not see it because its records are SPARSE: eleven of them and
// one 12,000 numbers further on. A piece covers [$from, $from + PIECE - 1],
// and with a sparse log there is no record at the number just past the end of
// a piece, so nothing is ever skipped.
//
// So this part makes a log that is DENSE ACROSS A BOUNDARY: PIECE + 3 records
// at CONSECUTIVE numbers, with "how far" set to the number just below the
// first of them, so the first piece certainly ends inside the block and the
// record one past its end certainly exists. It is written in terms of
// ActivityLogCleaner::PIECE, so it keeps crossing a boundary if that number
// is ever changed.
//
// AND IT BELIEVES THE DATABASE, NOT THE CLEANER. Both of the checks that
// matter here read the records themselves: what run() and left() answer is
// only compared with what is really there. The rule the cleaner must never
// break is written down as "the portal may never report fewer records left
// than really hold something", which is the broken promise the fault makes,
// whatever the shape of the fault.
$denseCount = ActivityLogCleaner::PIECE + 3;
$denseFirst = (int) one('SELECT COALESCE(MAX(logID), 0) + 10 AS n FROM tblActivityLogs')['n'];
$denseSeed  = bin2hex(random_bytes(8));
/** The identifier the nth dense record holds: 32 characters of digits and a to f, as PHP makes one. Worked out again rather than kept, so 5,000 of them cost no memory. */
$denseIdentifier = static fn (int $n): string => md5($denseSeed . ':' . $n);
/** How many records ANYWHERE in the log still hold an identifier or an old copy of a session. The same two tests the cleaner itself uses. */
$stillHoldSomething = static fn (): int => (int) one(
    "SELECT COUNT(*) AS n FROM tblActivityLogs WHERE (sessionID IS NOT NULL AND sessionID <> '' AND sessionID NOT LIKE 'sha256:%') "
    . 'OR (sessionDataSnapshot IS NOT NULL AND sessionDataSnapshot NOT LIKE \'{"format":"facts-1",%\')'
)['n'];

$cleanBefore = $stillHoldSomething();
// One transaction for the lot: 5,000 single INSERTs would each be committed
// on its own, which on a server that writes to disk takes minutes.
$db->begin_transaction();
$stmt = $db->prepare(
    'INSERT INTO tblActivityLogs (logID, siteID, userID, activityType, activityDescription, requestHeaders, sessionID, visitorIP, userAgent, sessionDataSnapshot) '
    . "VALUES (?, 1, ?, 'SelfTestDense', ?, '{}', ?, '192.0.2.62', 'an old browser', ?)"
);
for ($n = 0; $n < $denseCount; $n++) {
    $denseId      = $denseFirst + $n;
    $denseAccount = PEOPLE['member'];
    $denseWords   = 'dense old record ' . $n;
    $denseIdent   = $denseIdentifier($n);
    // The old form, holding NO authenticator key: this part is about the
    // boundary, and a key here would write key records that part K has
    // already counted.
    $denseCopy = '{"user_id":' . PEOPLE['member'] . ',"n":' . $n . '}';
    $stmt->bind_param('iisss', $denseId, $denseAccount, $denseWords, $denseIdent, $denseCopy);
    $stmt->execute();
}
$stmt->close();
$db->commit();
setting(ActivityLogCleaner::THROUGH_KEY, (string) ($denseFirst - 1)); // put back by the clean-up
ok(
    'the dense block is in: the log was clean, and now holds PIECE + 3 old records at consecutive numbers with the cleaner told it has reached the number just below them',
    $cleanBefore === 0 && $stillHoldSomething() === $denseCount && ActivityLogCleaner::left($db) === $denseCount
        && (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID BETWEEN ? AND ?', 'ii', [$denseFirst, $denseFirst + $denseCount - 1])['n'] === $denseCount,
    'holding something before ' . $cleanBefore . ', after ' . $stillHoldSomething() . ' (PIECE + 3 = ' . $denseCount . '); left() says ' . json_encode(ActivityLogCleaner::left($db))
);

// One piece a call, so that what the portal says can be read between pieces.
$denseCalls   = [];
$neverLied    = true;  // left() never said fewer than really hold something
$endedOnARow  = false; // a piece really did end with a record one number further on
for ($n = 1; $n <= 20; $n++) {
    $r            = ActivityLogCleaner::run($db, 0.0);
    $reallyLeft   = $stillHoldSomething();
    $through      = stored(ActivityLogCleaner::THROUGH_KEY);
    $denseCalls[] = ['cleaned' => $r['cleaned'], 'said left' => $r['left'], 'really hold something' => $reallyLeft, 'finished' => $r['finished'], 'how far' => $through];
    if ($r['left'] === null || $r['left'] < $reallyLeft) {
        $neverLied = false;
    }
    if ($through !== ActivityLogCleaner::DONE && $through !== null
        && (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID = ?', 'i', [(int) $through + 1])['n'] === 1) {
        $endedOnARow = true;
    }
    if ($r['finished'] === true || $r['error'] !== '') {
        break;
    }
}
$denseLast = $denseCalls[count($denseCalls) - 1];
ok(
    'THE PORTAL NEVER SAYS THERE IS LESS TO DO THAN THERE IS: at every step, after every piece, left() was at least the number of records that really still held an identifier or an old copy — and a piece really did end one number short of a record that exists, which is where a record gets skipped',
    $neverLied === true && $endedOnARow === true && count($denseCalls) >= 2,
    json_encode($denseCalls)
);
$denseRows = q('SELECT logID, sessionID, sessionDataSnapshot FROM tblActivityLogs WHERE logID BETWEEN ? AND ? ORDER BY logID', 'ii', [$denseFirst, $denseFirst + $denseCount - 1]);
$denseWrong = [];
foreach ($denseRows as $row) {
    $n = (int) $row['logID'] - $denseFirst;
    if ($row['sessionID'] !== Logger::fingerprintOf($denseIdentifier($n)) || $row['sessionDataSnapshot'] !== null) {
        $denseWrong[] = $n;
    }
}
ok(
    'EVERY ONE OF THE DENSE RECORDS IS CLEANED, THE ONES AT THE BOUNDARY INCLUDED: each holds exactly the fingerprint PHP makes of the identifier it held, and no copy of a session at all',
    count($denseRows) === $denseCount && $denseWrong === [],
    count($denseWrong) . ' of ' . count($denseRows) . ' record(s) are not as they should be; the first few, counted from the start of the block: '
        . json_encode(array_slice($denseWrong, 0, 10)) . ' (a piece ends at ' . (ActivityLogCleaner::PIECE - 1) . ', so number ' . ActivityLogCleaner::PIECE . ' is the one a boundary fault skips)'
);
ok(
    'and when it says it has finished, it really has: not one record ANYWHERE in the log still holds an identifier or an old copy, left() says 0 and "how far" says all',
    $denseLast['finished'] === true && $stillHoldSomething() === 0 && ActivityLogCleaner::left($db) === 0
        && stored(ActivityLogCleaner::THROUGH_KEY) === ActivityLogCleaner::DONE,
    'still holding something: ' . $stillHoldSomething() . '; left(): ' . json_encode(ActivityLogCleaner::left($db)) . '; how far: ' . json_encode(stored(ActivityLogCleaner::THROUGH_KEY))
);

// =============================================================================
// 🧹 Clean-up and result
// =============================================================================
$serverErrors = (string) @file_get_contents($child . $sep . 'php-errors.log');
ok('the portal wrote nothing to PHP\'s own error log during the run, apart from the one stop this test planted', trim((string) preg_replace('/^.*ActivityLogCleaner stopped:.*planted by the activity log self-test.*$/m', '', $serverErrors)) === '', substr($serverErrors, 0, 600));
cleanup();
$db = connect();
ok(
    'afterwards the database is as it was when the test began: migration 220 recorded, the log marked as clean, no account, record or backup code of this test left',
    (int) one("SELECT COUNT(*) AS n FROM tblMigrations WHERE filename = '" . MIGRATION . "'")['n'] === 1
        && (int) one('SELECT (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 962200 AND 962299) + (SELECT COUNT(*) FROM tblActivityLogs WHERE userID BETWEEN 962200 AND 962299 OR userAgent = ?) + (SELECT COUNT(*) FROM tblTotpBackupCodes WHERE userID BETWEEN 962200 AND 962299) AS n', 's', [AGENT])['n'] === 0
        && one('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', 's', [$scratchDb]) === null
        && stored(ActivityLogCleaner::THROUGH_KEY) === ActivityLogCleaner::DONE, // this was in the label but not in the check until 27 October 2026
    'how far: ' . json_encode(stored(ActivityLogCleaner::THROUGH_KEY))
);

echo "\n" . $pass . ' passed, ' . $fail . ' failed, ' . ($pass + $fail) . ' of ' . EXPECTED_CHECKS . " checks ran\n";
if ($pass + $fail !== EXPECTED_CHECKS) {
    echo 'FAIL — ' . ($pass + $fail) . ' checks ran and ' . EXPECTED_CHECKS . " were expected: a block was skipped or ran twice.\n";
    exit(1);
}
if ($fail > 0) {
    exit(1);
}
echo "THE RUN REACHED ITS END.\n";
exit(0);
