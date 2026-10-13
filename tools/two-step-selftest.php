<?php
// Path: tools/two-step-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Two-step sign-in and its backup codes, on the REAL pages — self-test 🔐🔑 (#613)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG (found 13 October 2026, by running the pages)
 * ------------------------------------------------------------
 * Switching two-step sign-in on made eight backup codes and never showed them,
 * so a lost phone locked the person out. The codes that nobody saw were
 * written into the activity log in readable text. One backup code could sign
 * two browsers in when both sent it at the same moment. The page that asks
 * for the code named three style files that are not in the portal. None of it
 * failed loudly: every page answered, and no test reached these pages.
 * So this file runs the portal and reads what it answers.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file and key, starts PHP's built-in web server on it behind a
 * small router that imitates `.htaccess`, and sends real requests with real
 * cookies. Every account switches two-step sign-in on THROUGH THE REAL PAGE,
 * so nothing here knows how the key or the codes are stored. The 6-digit
 * codes come from the portal's own `Totp` class, standing in for an
 * authenticator app.
 *
 *   A. Switching on: a wrong code changes nothing and says so; so does the
 *      RIGHT code on a form without the page's own token; the right one
 *      answers with eight codes; the database holds only fingerprints; the
 *      page is not shown a second time, whatever is in the address; and no
 *      code and no key is left in readable form in the activity log, in a
 *      session or in PHP's log.
 *   B. Nobody else is shown them: another account, a visitor who is not
 *      signed in, the same person in a second browser, a form that names
 *      somebody else's account, and a browser that has given the password
 *      but not the code.
 *   C. A backup code signs the person in when no authenticator code is
 *      given: once, and only once; typed in small letters too; a wrong one
 *      does not; using one is written to the activity log; an authenticator
 *      code still works; ANOTHER person's code does not sign this person in;
 *      and a right code on a form without the page's own token does nothing.
 *   D. One code, one sign-in, even when eight browsers send it at the same
 *      moment (eight portal servers, so they really do run together). And
 *      one account switched on from two browsers at the same moment ends
 *      with ONE set of codes and ONE key: those of the browser that was
 *      shown them. Two DIFFERENT people at the same moment are both served,
 *      switching on and making a new set (three pairs each: the first way
 *      of removing the old set refused one of every pair). One person asking
 *      for a new set from two browsers at once ends with eight codes stored,
 *      not sixteen.
 *   E. Wrong tries at the code page are counted and stop after five, for
 *      backup codes as for authenticator codes, and a right code sent while
 *      stopped is not used up.
 *   F. "Make new backup codes" on the Account page (for people who switched
 *      this on before the codes were ever shown): refused without a right
 *      authenticator code or a right form; with one, eight new codes are
 *      shown once, the old ones stop working, and it is recorded. The form
 *      carries the script that sends it only once.
 *   G. That form has its own limit of five wrong codes, which does not block
 *      the person's next sign-in.
 *   H. The two-step pages name no file that is not there, and ask for the
 *      same style files as the sign-in page.
 *   I. All or nothing: when the database refuses to store the codes,
 *      two-step sign-in is NOT switched on, and an old set is NOT lost; and
 *      a person with no old set is not told that their old codes still work.
 *   J. What the old code left in a session (readable codes, a readable key)
 *      is taken out the next time the person opens the page; and a code
 *      sent when no key is waiting is answered with a new key and a reason.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every account it makes is numbered 922000 to 922999; every
 *   record it causes carries one of those numbers, a 198.51.100.x address
 *   (a range kept for examples, so it is nobody's) or this test's name as
 *   the browser. They are removed at the START (in case an earlier run was
 *   interrupted) and at the END.
 * - It changes two portal-wide settings for the run (`portal.trustedProxies`
 *   and `portal.trustedProxyHeader`, so that each part has a visitor address
 *   of its own) and puts each back exactly. For part I it adds one trigger,
 *   `selftest_two_step_refuse`, which refuses backup codes for two of its
 *   own accounts only, and drops it. So do not run it at the same moment as
 *   another test on the same database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds
 *   this run's marker. `web/_auth_keys` is never read or copied.
 * - Every server is started with its command as a list (no shell), so each
 *   process number is the server's own, and each is stopped by that number,
 *   never by a name. It refuses to start if anything already answers on any
 *   of its eight ports. Every answer must carry this run's header.
 * - Nothing is sent outside this machine. The style files a page asks of
 *   another site are read from the page's text and never fetched.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - A real authenticator app, or scanning the picture on the set-up page.
 *   The codes here are worked out by the portal's own class from the key
 *   read off the page. (The picture is asked of another site and is not
 *   fetched here; see the report beside #613 on whether it can appear.)
 * - How any page LOOKS, or that a script on it RUNS. No browser is used: the
 *   pages' text is read. Part H proves which files a page asks for, not
 *   that it is drawn well. Check F15 proves the Account form carries the
 *   script that sends it once, with the mark the page's rules ask for; it
 *   cannot press a button twice.
 * - That the browser really forgets the page of codes. Part A reads the
 *   "do not keep this" header; what a browser does with it is the browser's.
 * - An account with no password (one that signs in through Microsoft or
 *   Google). Its sign-in cannot be run here, so its "make new codes" is not.
 * - Old records. Codes made before #613 are still in old activity-log rows
 *   in readable text; this file proves only that no NEW record carries one.
 * - Apache, a real proxy or https: the router only imitates `.htaccess`, on
 *   plain http on 127.0.0.1.
 * - MariaDB. It has been run on MySQL 8.0.36 and 8.4.11.
 * - That part D's requests overlap on every run. Each takes a second or
 *   more of work inside the database before it finishes, and they are sent
 *   together, so they do (with the first way of removing the old set, one
 *   of every pair was refused, every time); but nothing here measures it.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/two-step-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root; it must be allowed to make a trigger), SELFTEST_WEB_PORT
 *         (default 9740). The SEVEN ports after it must be free as well
 *         (part D). The database must already hold `web/_sql/full_schema.sql`.
 *         Needs PHP's curl and sodium extensions and `proc_open()`.
 *         It takes a minute or two: every backup code is a slow fingerprint
 *         on purpose, and a wrong one is compared with all eight.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/613
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 84;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'sodium_crypto_secretbox'] as $needed) {
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
$port    = ($portRaw === false || $portRaw === '') ? 9740 : (int) $portRaw;
if ($port < 1024 || $port > 65528) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65528 (the seven ports after it are used too)');
}
// Part D sends eight tries at the same moment, which one server process cannot
// run together. It starts seven more portal servers for that part only.
$extraPorts = range($port + 1, $port + 7);

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
/** The accounts. Each part has its own, so one part cannot use up another's allowance of wrong codes. */
const PEOPLE = [
    'anna'  => 922011, // A, B, C: switches it on, and signs in with her codes
    'ben'   => 922012, // B: somebody else, signed in, with two-step sign-in off
    'cara'  => 922013, // C: has it on herself, and tries one of Anna's codes
    'dev'   => 922014, // F: makes a new set from the Account page
    'finn'  => 922016, // E: wrong tries at the code page
    'gail'  => 922017, // D: one code sent by eight browsers at one moment
    'hugo'  => 922018, // G: wrong tries at "make new backup codes"
    'ivy'   => 922019, // I: the database refuses her codes while she switches it on
    'jack'  => 922020, // I: the database refuses his NEW codes
    'kim'   => 922021, // J: a session left by the code before #613
    'lena'  => 922022, // D: switches it on from two browsers at the same moment
    'mia'   => 922023, // D: three pairs of DIFFERENT people at the same moment
    'noah'  => 922024,
    'omar'  => 922025,
    'pia'   => 922026,
    'quin'  => 922027,
    'rosa'  => 922028,
];
/** How this test names itself to the portal, so the records it causes can be found and removed. */
const AGENT = 'two-step-selftest';
/** The trigger part I makes, and drops. */
const TRIGGER = 'selftest_two_step_refuse';

/** A made-up visitor address. 198.51.100.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '198.51.100.' . $last;
}

/** The sign-in name of one of the people. */
function login(string $who): string
{
    return 'tss-' . $who;
}

function removeWorld(): void
{
    global $db;
    $db->query('DROP TRIGGER IF EXISTS ' . TRIGGER);
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 922000 AND 922999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 922000 AND 922999 OR visitorIP LIKE '198.51.100.%'");
    // Only the limiter's rows for this test's own accounts.
    foreach (PEOPLE as $id) {
        q('DELETE FROM tblApiRateLimits WHERE bucket LIKE ? OR bucket = ? OR bucket = ?', 'sss', ['totp\\_verify:' . $id . ':%', 'totp_verify_user:' . $id, 'totp_newcodes:' . $id]);
    }
    q('DELETE FROM tblTrustedDevices WHERE userID BETWEEN 922000 AND 922999');
    q('DELETE FROM tblTotpBackupCodes WHERE userID BETWEEN 922000 AND 922999');
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 922000 AND 922999');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 922000 AND 922999');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 922000 AND 922999');
}

// =============================================================================
// 🧰 The servers, and one request
// =============================================================================
$state = ['proc' => null, 'extra' => [], 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false];

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
    foreach (array_keys($state['extra']) as $n) {
        stopServer($state['extra'][$n]);
    }
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
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.two-step-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One portal-wide setting for the run. The value it had, and whether it was
 * marked as stored encrypted, are remembered the first time and put back by
 * the clean-up.
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

/**
 * One real HTTP request to a server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param string                   $ip   The visitor's address, sent as a proxy would send it.
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
    $raw = curl_exec($handle);
    if ($raw === false) {
        $why = curl_error($handle);
        unset($handle);

        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . $why, 'head' => ''];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status   = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    // Let go of the request NOW: curl writes its cookies to the jar when it is
    // let go, and the next request must find them there.
    unset($handle);
    $head = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Two-Step-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => ''];
    }

    return [
        'status'   => $status,
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

/** The form token on a page (the first one; every form on a page carries the same). */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** A new, empty cookie jar: a browser that has never been here. */
function newJar(): string
{
    global $state;

    return $state['child'] . DIRECTORY_SEPARATOR . 'jar-' . bin2hex(random_bytes(6)) . '.txt';
}

/** What the page tells the person: "danger: Invalid code." (the first message box), or '' when there is none. */
function said(array $answer): string
{
    if (preg_match('#<div class="alert alert-(\w+)[^"]*"[^>]*>(.*?)</div>#s', $answer['body'], $m) !== 1) {
        return '';
    }

    return $m[1] . ': ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2])));
}

/** "HTTP 302 to /account, danger: …" — for a failed check's detail line. */
function describe(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']) . (said($answer) === '' ? '' : ', ' . said($answer));
}

/** The backup codes drawn on a page: eight characters, 0-9 and A-F, each in a `<code>` of its own. */
function codesOn(string $html): array
{
    preg_match_all('#<code[^>]*>\s*([0-9A-F]{8})\s*</code>#', $html, $m);

    return $m[1];
}

/**
 * The n-th of a list of codes read off a page, or '' when the page showed
 * fewer. A page that shows no codes must make the checks FAIL, not stop this
 * file part way with an error: a run that stops can be mistaken for a pass.
 */
function nth(array $codes, int $n): string
{
    return (string) ($codes[$n] ?? '');
}

/** The key for the authenticator app drawn on the set-up page (32 characters, A-Z and 2-7), or ''. */
function keyOn(string $html): string
{
    return preg_match('#<code[^>]*>\s*([A-Z2-7]{32})\s*</code>#', $html, $m) === 1 ? $m[1] : '';
}

/** The 6-digit code an authenticator app would show now for this key (the portal's own arithmetic). */
function appCode(string $key): string
{
    return \Portal\Core\Totp::getCode($key);
}

/** Six digits that are NOT a right code for this key now, a step ago or a step ahead. */
function wrongAppCode(string $key): string
{
    $right = [\Portal\Core\Totp::getCode($key, time() - 60), \Portal\Core\Totp::getCode($key, time() - 30), \Portal\Core\Totp::getCode($key), \Portal\Core\Totp::getCode($key, time() + 30), \Portal\Core\Totp::getCode($key, time() + 60)];
    do {
        $guess = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (in_array($guess, $right, true) === true);

    return $guess;
}

/** Eight characters shaped like a backup code that are not one of `$codes`. */
function wrongBackupCode(array $codes): string
{
    do {
        $guess = strtoupper(bin2hex(random_bytes(4)));
    } while (in_array($guess, $codes, true) === true);

    return $guess;
}

/**
 * The password step, through the real sign-in page, in a NEW browser.
 *
 * @return array{0:string, 1:array} The browser's jar, and the answer.
 */
function passwordStep(string $who, string $ip): array
{
    global $password;
    $jar  = newJar();
    $form = req($jar, 'GET', '/login', null, $ip);

    return [$jar, req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => login($who), 'password' => $password], $ip)];
}

/**
 * The code step, through the real page: open it (for the form's token), then send `$fields`.
 * `$fields` is ['code' => …] for an authenticator code, with 'use_backup' => '1' for a backup code.
 */
function codeStep(string $jar, string $ip, array $fields): array
{
    $form = req($jar, 'GET', '/auth/2fa/verify', null, $ip);

    return req($jar, 'POST', '/auth/2fa/verify', ['csrf_token' => token($form['body'])] + $fields, $ip);
}

/** Is this browser signed in? (The Account page answers; a browser that is not is sent to sign in.) */
function signedIn(string $jar, string $ip): bool
{
    return req($jar, 'GET', '/account', null, $ip)['status'] === 200;
}

/** Sign in with the password and an authenticator code, in a new browser. Returns the jar, or '' if it did not work. */
function signInWithApp(string $who, string $key, string $ip): string
{
    [$jar, $in] = passwordStep($who, $ip);
    if ($in['status'] !== 302 || $in['location'] !== '/auth/2fa/verify') {
        return '';
    }
    $done = codeStep($jar, $ip, ['code' => appCode($key)]);

    return ($done['status'] === 302 && str_contains($done['location'], '2fa') === false && signedIn($jar, $ip) === true) ? $jar : '';
}

/**
 * Switch two-step sign-in on for one person through the real pages: sign in,
 * open the set-up page, read the key, send the code.
 *
 * @return array{jar:string, key:string, codes:list<string>, answer:array}
 */
function switchOn(string $who, string $ip): array
{
    [$jar]  = passwordStep($who, $ip);
    $page   = req($jar, 'GET', '/auth/2fa/setup', null, $ip);
    $key    = keyOn($page['body']);
    $answer = req($jar, 'POST', '/auth/2fa/setup', ['csrf_token' => token($page['body']), 'code' => $key === '' ? '' : appCode($key)], $ip);

    return ['jar' => $jar, 'key' => $key, 'codes' => codesOn($answer['body']), 'answer' => $answer];
}

/** Ask for a new set of backup codes from the Account page's form. `$fields` replaces or adds to what the form sends. */
function askNewCodes(string $jar, string $ip, array $fields): array
{
    $account = req($jar, 'GET', '/account', null, $ip);

    return req($jar, 'POST', '/auth/2fa/setup', $fields + ['csrf_token' => token($account['body']), 'action' => 'new_backup_codes'], $ip);
}

/** The stored fingerprints of one person's codes, in order, as one text: for "nothing changed". */
function stored(string $who): string
{
    return implode(',', array_map(static fn (array $row): string => $row['codeID'] . ':' . $row['codeHash'] . ':' . $row['isUsed'], q('SELECT codeID, codeHash, isUsed FROM tblTotpBackupCodes WHERE userID = ? ORDER BY codeID', 'i', [PEOPLE[$who]])));
}

/**
 * How many of the codes shown match the stored fingerprints, each against
 * the fingerprint in the same place. The page stores the codes in the order
 * it shows them, so the n-th code belongs to the n-th row. (Comparing every
 * code with every row would prove the same and take eight times as long:
 * each comparison is slow on purpose.) If that order ever changes, this
 * answers fewer than eight and the check that uses it fails.
 *
 * @param list<string> $codes
 * @param list<string> $hashes In the order the rows were stored.
 */
function matching(array $codes, array $hashes): int
{
    $matched = 0;
    foreach (array_values($codes) as $n => $code) {
        $matched += (isset($hashes[$n]) === true && password_verify($code, (string) $hashes[$n]) === true) ? 1 : 0;
    }

    return $matched;
}

/** The numbers of one person's stored codes, as one text. */
function rowNumbers(string $who): string
{
    return implode(',', array_column(q('SELECT codeID FROM tblTotpBackupCodes WHERE userID = ? ORDER BY codeID', 'i', [PEOPLE[$who]]), 'codeID'));
}

/** How many of one person's codes are stored, and how many of those are unused. */
function counts(string $who): string
{
    $row = one('SELECT COUNT(*) AS n, COALESCE(SUM(isUsed = 0), 0) AS unused FROM tblTotpBackupCodes WHERE userID = ?', 'i', [PEOPLE[$who]]);

    return $row['n'] . ' stored, ' . $row['unused'] . ' unused';
}

/** How many activity records of one kind one person has. */
function records(string $who, string $type): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE userID = ? AND activityType = ?', 'is', [PEOPLE[$who], $type])['n'];
}

/**
 * Everywhere a secret could have been left in readable form: every column of
 * every activity record this run caused, every session file, and PHP's log.
 * Returns the places that hold any of `$secrets` (none is the right answer).
 *
 * @param list<string> $secrets
 *
 * @return list<string>
 */
function leftReadable(array $secrets): array
{
    global $state;
    $found = [];
    $rows  = q("SELECT logID, activityType, CONCAT_WS('\n', activityDescription, requestHeaders, sessionID, sessionDataSnapshot) AS everything FROM tblActivityLogs WHERE userID BETWEEN 922000 AND 922999 OR visitorIP LIKE '198.51.100.%' OR userAgent = ?", 's', [AGENT]);
    foreach ($rows as $row) {
        foreach ($secrets as $secret) {
            if (stripos((string) $row['everything'], $secret) !== false) {
                $found[] = 'activity record ' . $row['logID'] . ' (' . $row['activityType'] . ')';
                break;
            }
        }
    }
    $files = array_merge((array) glob($state['child'] . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . 'sess_*'), [$state['child'] . DIRECTORY_SEPARATOR . 'php-errors.log', $state['child'] . DIRECTORY_SEPARATOR . 'server-output.log']);
    foreach ($files as $file) {
        $text = (string) @file_get_contents((string) $file);
        foreach ($secrets as $secret) {
            if (stripos($text, $secret) !== false) {
                $found[] = (str_contains((string) $file, 'sess_') === true ? 'a session file' : basename((string) $file));
                break;
            }
        }
    }

    return $found;
}

/** The session file of the browser that owns this jar, or '' when it cannot be found. */
function sessionFile(string $jar): string
{
    global $state;
    foreach ((array) file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode("\t", (string) $line);
        $file  = $state['child'] . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . 'sess_' . (string) end($parts);
        if (count($parts) === 7 && preg_match('/^[A-Za-z0-9,-]{16,}$/', (string) end($parts)) === 1 && is_file($file) === true) {
            return $file;
        }
    }

    return '';
}

/** Every style sheet and script a page asks for, as the page's text names them. */
function filesAsked(string $html): array
{
    preg_match_all('#<link\b[^>]*\brel="stylesheet"[^>]*\bhref="([^"]+)"#', $html, $css);
    preg_match_all('#<script\b[^>]*\bsrc="([^"]+)"#', $html, $js);
    $all = array_values(array_unique(array_merge($css[1], $js[1])));
    sort($all);

    return $all;
}

/** Of a list from filesAsked(), the ones asked of THIS server that do not answer 200. */
function notThere(array $files): array
{
    $missing = [];
    foreach ($files as $file) {
        if (str_starts_with((string) $file, '/') === true && str_starts_with((string) $file, '//') === false && req('', 'GET', (string) $file)['status'] !== 200) {
            $missing[] = $file;
        }
    }

    return $missing;
}

/**
 * Send several forms AT THE SAME MOMENT, each from its own browser to its own
 * portal server, so that they really do run together.
 *
 * @param list<array{port:int, jar:string, path:string, fields:array<string,mixed>, ip:string}> $forms
 *
 * @return list<array{status:int, location:string, body:string}> In the order given.
 */
function sendTogether(array $forms): array
{
    global $state;
    $multi   = curl_multi_init();
    $handles = [];
    foreach ($forms as $form) {
        $handle = curl_init('http://127.0.0.1:' . $form['port'] . $form['path']);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Expect:', 'User-Agent: ' . AGENT, 'X-Forwarded-For: ' . $form['ip']], CURLOPT_COOKIEFILE => $form['jar'], CURLOPT_COOKIEJAR => $form['jar'], CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form['fields']),
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);
    $answers = [];
    foreach ($handles as $handle) {
        $raw      = (string) curl_multi_getcontent($handle);
        $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $head     = substr($raw, 0, $headSize);
        // An answer without this run's header came from a server this run did not start: status -1, which no check expects.
        $mine      = preg_match('/^X-Two-Step-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) === 1;
        $answers[] = [
            'status'   => $mine === true ? (int) curl_getinfo($handle, CURLINFO_HTTP_CODE) : -1,
            'location' => ($mine === true && preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1) ? $l[1] : '',
            'body'     => $mine === true ? substr($raw, $headSize) : '',
        ];
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    // Let go of the requests NOW: curl writes each browser's cookies when its request is let go.
    unset($handles, $handle);

    return $answers;
}

/**
 * Send one backup code for one person from `$count` browsers at the same
 * moment, each already past the password step, spread over the portal servers.
 *
 * @param list<int> $ports
 *
 * @return list<string> The jars of the browsers, in the order sent.
 */
function burst(array $ports, string $who, string $ip, string $code, int $count): array
{
    // The password step and the form first, one at a time, so that only the code itself is sent together.
    $forms = [];
    for ($i = 0; $i < $count; $i++) {
        [$jar]   = passwordStep($who, $ip);
        $forms[] = ['port' => $ports[$i % count($ports)], 'jar' => $jar, 'path' => '/auth/2fa/verify', 'ip' => $ip,
            'fields' => ['csrf_token' => token(req($jar, 'GET', '/auth/2fa/verify', null, $ip)['body']), 'code' => $code, 'use_backup' => '1']];
    }
    sendTogether($forms);

    return array_column($forms, 'jar');
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
foreach (array_merge([$port], $extraPorts) as $onePort) {
    if (portAnswers($onePort) === true) {
        refuse("something already answers on port {$onePort}; this test never drives a server it did not start");
    }
}
$state['started'] = true;
register_shutdown_function('cleanup');
removeWorld();

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach (PEOPLE as $who => $id) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Selftest two-step ' . $who, $who . '.twostep@selftest.invalid']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 0, 0)', 'i', [$id]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
// Believe the "X-Forwarded-For" header from 127.0.0.1, so each part has a visitor address of its own.
setting('portal.trustedProxies', '127.0.0.1');
setting('portal.trustedProxyHeader', 'x-forwarded-for');

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'two-step-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.two-step-selftest-owner', $state['token']); // the marker FIRST
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
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/two-step-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The portal's own code generator, from the copy being tried. It needs nothing else of the portal.
require $child . $sep . 'web' . $sep . '_core' . $sep . 'Totp.php';

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/two-step-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Two-Step-Selftest: __TOKEN__');
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

// The servers: each command as a LIST (no shell in between), ONE process each
// (no PHP_CLI_SERVER_WORKERS), so nothing can outlive them.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$quiet             = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']];
/**
 * Start one portal server process on one port. They share the sessions folder, so a form opened on one can be sent to another.
 *
 * "session.cache_limiter=" switches OFF the "do not keep this page" headers
 * PHP adds by itself to every page that uses a session. Most servers leave
 * them on, but a host can change that setting. With them off here, checks A6
 * and F7 pass only if the page of codes says "do not keep" ITSELF. With them
 * on, PHP's own header already says "no-store" on every page, so those two
 * checks would pass whatever the page said.
 */
$startPortal = static function (int $onPort) use ($child, $sep, $quiet, $env): mixed {
    return proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
            '-d', 'display_errors=0', '-d', 'session.cache_limiter=', '-S', '127.0.0.1:' . $onPort, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
        $quiet,
        $pipes,
        $child,
        $env
    );
};
$state['proc'] = $startPortal($port);
if (is_resource($state['proc']) === false) {
    refuse('the portal server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse("the portal server never answered on port {$port}");
}

echo "Two-step sign-in and its backup codes, on the real pages — self-test (#613)\n";
echo 'portal server process ' . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// 🧭 Before anything is judged: the portal must answer, sign an account in,
//    and count by the made-up address. If it does not, nothing below means
//    anything.
[$annaJar, $first] = passwordStep('anna', ip(11));
if ($first['status'] !== 302 || $first['location'] !== '/') {
    refuse('a plain password sign-in did not end on the front page (' . describe($first) . '), so the pages cannot be driven');
}
// (The sign-in record names no account, so it is found by the address alone.)
if ((int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = 'LoginLocal' AND visitorIP = ?", 's', [ip(11)])['n'] < 1) {
    refuse('the portal did not record that sign-in against the made-up address ' . ip(11) . ', so the parts cannot be told apart');
}

// =============================================================================
head('A. Switching it on shows the codes, once');
// =============================================================================
$at    = ip(11);
$setup = req($annaJar, 'GET', '/auth/2fa/setup', null, $at);
$key   = keyOn($setup['body']);
ok('A1 the set-up page answers, shows a key for the authenticator app, and its form points at /auth/2fa/setup',
    $setup['status'] === 200 && $key !== '' && str_contains($setup['body'], 'action="/auth/2fa/setup"') === true, describe($setup) . '; key found: ' . ($key !== '' ? 'yes' : 'no'));

// While the set-up page is open, something else the person does is recorded.
// (A refused "switch off" is used because it writes a record and changes
// nothing.) Every record carries a copy of the session; the key must not be
// readable in it.
$account = req($annaJar, 'GET', '/account', null, $at);
req($annaJar, 'POST', '/auth/2fa/disable', ['csrf_token' => token($account['body']), 'current_password' => 'not-the-password'], $at);
$control = records('anna', 'TotpDisableFailed');

$setup = req($annaJar, 'GET', '/auth/2fa/setup', null, $at);
ok('A2 the key is the same when the page is opened again (the person\'s app may already hold it)', keyOn($setup['body']) === $key && $key !== '', 'first ' . ($key === '' ? '(none)' : 'shown') . ', then ' . (keyOn($setup['body']) === $key ? 'the same' : 'a different one or none'));

$wrong = req($annaJar, 'POST', '/auth/2fa/setup', ['csrf_token' => token($setup['body']), 'code' => wrongAppCode($key)], $at);
$again = req($annaJar, 'GET', '/auth/2fa/setup', null, $at);
$isOn  = (int) one('SELECT totpEnabled FROM tblUsers WHERE userID = ?', 'i', [PEOPLE['anna']])['totpEnabled'];
ok('A3 a WRONG code does not switch it on, makes no codes, and the page says the code is not right',
    $wrong['status'] === 302 && $wrong['location'] === '/auth/2fa/setup' && $isOn === 0 && counts('anna') === '0 stored, 0 unused' && str_starts_with(said($again), 'danger: That code is not right') === true && keyOn($again['body']) === $key,
    describe($wrong) . '; on: ' . $isOn . '; ' . counts('anna') . '; the page then said: ' . said($again));

// The RIGHT code, on a form that is not the page's own: with no token at all,
// and with the token of another browser. (Only the token is wrong, so only the
// token can be what refuses it.)
$noToken    = req($annaJar, 'POST', '/auth/2fa/setup', ['code' => appCode($key)], $at);
$otherToken = req($annaJar, 'POST', '/auth/2fa/setup', ['csrf_token' => token(req(newJar(), 'GET', '/login', null, $at)['body']), 'code' => appCode($key)], $at);
$afterBoth  = req($annaJar, 'GET', '/auth/2fa/setup', null, $at);
$isOn       = (int) one('SELECT totpEnabled FROM tblUsers WHERE userID = ?', 'i', [PEOPLE['anna']])['totpEnabled'];
ok('A3b the RIGHT code on a form with no token, or with another browser\'s token, does not switch it on and shows no code; the page says the form had expired',
    $noToken['status'] === 302 && $noToken['location'] === '/auth/2fa/setup' && $otherToken['status'] === 302 && $otherToken['location'] === '/auth/2fa/setup' && codesOn($noToken['body'] . $otherToken['body']) === []
    && $isOn === 0 && counts('anna') === '0 stored, 0 unused' && str_starts_with(said($afterBoth), 'danger: That form had expired') === true && keyOn($afterBoth['body']) === $key,
    describe($noToken) . ' / ' . describe($otherToken) . '; on: ' . $isOn . '; ' . counts('anna') . '; the page then said: ' . said($afterBoth));

$on         = req($annaJar, 'POST', '/auth/2fa/setup', ['csrf_token' => token($again['body']), 'code' => appCode($key)], $at);
$annaCodes  = codesOn($on['body']);
$annaStored = stored('anna');
ok('A4 the RIGHT code is answered with a page (not sent elsewhere) that shows exactly eight different backup codes',
    $on['status'] === 200 && count($annaCodes) === 8 && count(array_unique($annaCodes)) === 8, describe($on) . '; codes on the page: ' . count($annaCodes));
ok('A5 the page says two-factor authentication is now on, and that this is the only time the codes are shown',
    str_starts_with(said($on), 'success: Two-factor authentication is now on') === true && str_contains($on['body'], 'This is the only time these codes are shown') === true, said($on));
ok('A6 the answer tells the browser not to keep the page (said by the page itself: this server adds no such header of its own)', preg_match('/^Cache-Control:.*\bno-store\b/mi', $on['head']) === 1, trim((string) (preg_match('/^Cache-Control:.*$/mi', $on['head'], $cc) === 1 ? $cc[0] : 'no Cache-Control header')));

$row     = one('SELECT totpEnabled, totpSecret FROM tblUsers WHERE userID = ?', 'i', [PEOPLE['anna']]);
$hashes  = array_column(q('SELECT codeHash FROM tblTotpBackupCodes WHERE userID = ? AND isUsed = 0 ORDER BY codeID', 'i', [PEOPLE['anna']]), 'codeHash');
$matched = matching($annaCodes, $hashes);
ok('A7 the database says it is on, holds exactly eight unused codes, and each code on the page is one of them',
    (int) $row['totpEnabled'] === 1 && count($hashes) === 8 && count(array_unique($hashes)) === 8 && $matched === 8, 'on: ' . $row['totpEnabled'] . '; stored: ' . count($hashes) . '; matched: ' . $matched);
ok('A8 the database holds fingerprints only: no stored value is a code, and the key is not stored as it was shown',
    count(array_filter($hashes, static fn (string $h): bool => str_starts_with($h, '$2y$') === true && strlen($h) === 60)) === 8 && array_intersect($hashes, $annaCodes) === [] && (string) $row['totpSecret'] !== '' && stripos((string) $row['totpSecret'], $key) === false,
    'fingerprints: ' . count(array_filter($hashes, static fn (string $h): bool => str_starts_with($h, '$2y$') === true)) . ' of ' . count($hashes));

$second = req($annaJar, 'GET', '/auth/2fa/setup', null, $at);
$landed = req($annaJar, 'GET', $second['location'] === '' ? '/account' : $second['location'], null, $at);
ok('A9 opening the page again shows no code: the person is sent to Account, and no code is on either page',
    $second['status'] === 302 && str_starts_with($second['location'], '/account') === true && codesOn($second['body']) === [] && $landed['status'] === 200 && codesOn($landed['body']) === [] && array_filter($annaCodes, static fn (string $c): bool => str_contains($second['body'] . $landed['body'], $c) === true) === [],
    describe($second) . '; then HTTP ' . $landed['status']);
$marked = req($annaJar, 'GET', '/auth/2fa/setup?complete=1', null, $at);
ok('A10 "?complete=1" in the address shows no code either', $marked['status'] === 302 && str_starts_with($marked['location'], '/account') === true && array_filter($annaCodes, static fn (string $c): bool => str_contains($marked['body'], $c) === true) === [], describe($marked));
$resent = req($annaJar, 'POST', '/auth/2fa/setup', ['csrf_token' => token($again['body']), 'code' => appCode($key)], $at);
ok('A11 sending the same form a second time shows no code and makes no new ones',
    $resent['status'] === 302 && codesOn($resent['body']) === [] && stored('anna') === $annaStored, describe($resent) . '; stored codes ' . (stored('anna') === $annaStored ? 'unchanged' : 'CHANGED'));
ok('A12 the Account page says how many codes are left and offers to make a new set',
    str_contains($landed['body'], 'You have 8 unused backup codes') === true && str_contains($landed['body'], 'name="action" value="new_backup_codes"') === true && str_contains($landed['body'], 'Make New Backup Codes') === true,
    'said 8: ' . (str_contains($landed['body'], 'You have 8 unused backup codes') === true ? 'yes' : 'no'));

$enabledRow = one("SELECT sessionDataSnapshot FROM tblActivityLogs WHERE userID = ? AND activityType = 'TotpEnabled'", 'i', [PEOPLE['anna']]);
ok('A13 control: switching on was recorded once, a record was written while the set-up page was open, and records do carry a copy of the session',
    records('anna', 'TotpEnabled') === 1 && $control === 1 && str_contains((string) ($enabledRow['sessionDataSnapshot'] ?? ''), '"user_id":' . PEOPLE['anna']) === true,
    'TotpEnabled: ' . records('anna', 'TotpEnabled') . '; written while set-up was open: ' . $control . '; copy of the session: ' . mb_substr((string) ($enabledRow['sessionDataSnapshot'] ?? '(none)'), 0, 80));
// The codes are looked for by what they are, and by the name the old page kept
// them under ("totp_backup_codes"): on a page that never showed them, this
// file would not know what they were, and "none found" would mean nothing.
$left = leftReadable(array_merge($annaCodes, ['totp_backup_codes']));
ok('A14 no backup code is left in readable form: not in any activity record, not in any session, not in PHP\'s log', count($annaCodes) === 8 && $left === [], count($annaCodes) . ' codes looked for; ' . implode('; ', array_slice($left, 0, 5)));
$left = leftReadable([$key]);
ok('A15 nor is the key for the authenticator app', $key !== '' && $left === [], implode('; ', array_slice($left, 0, 5)));

// =============================================================================
head('B. Nobody else is shown them');
// =============================================================================
[$benJar, $benIn] = passwordStep('ben', ip(21));
$benPage          = req($benJar, 'GET', '/auth/2fa/setup?complete=1', null, ip(21));
ok('B1 another account that adds "?complete=1" gets its own set-up page, with a key of its own and none of the first person\'s codes',
    $benIn['status'] === 302 && $benPage['status'] === 200 && keyOn($benPage['body']) !== '' && keyOn($benPage['body']) !== $key && codesOn($benPage['body']) === [] && array_filter($annaCodes, static fn (string $c): bool => str_contains($benPage['body'], $c) === true) === [],
    describe($benPage));
$nobody = req(newJar(), 'GET', '/auth/2fa/setup?complete=1', null, ip(22));
ok('B2 a visitor who is not signed in is sent to sign in, and is shown nothing', $nobody['status'] === 302 && str_starts_with($nobody['location'], '/login') === true && codesOn($nobody['body']) === [], describe($nobody));
$annaTwo = signInWithApp('anna', $key, ip(23));
$other   = $annaTwo === '' ? ['status' => 0, 'location' => '', 'body' => '', 'head' => ''] : req($annaTwo, 'GET', '/auth/2fa/setup?complete=1', null, ip(23));
ok('B3 the same person, signed in on a second browser with the authenticator, is not shown them there',
    $annaTwo !== '' && $other['status'] === 302 && array_filter($annaCodes, static fn (string $c): bool => str_contains($other['body'], $c) === true) === [], $annaTwo === '' ? 'the second sign-in did not work' : describe($other));
// The other account sends the "new codes" form with the first person's account
// number in every field a careless page might read, and a code that IS right
// for the first person's key.
$forged = askNewCodes($benJar, ip(21), ['code' => appCode($key), 'userID' => PEOPLE['anna'], 'user_id' => PEOPLE['anna'], 'userId' => PEOPLE['anna'], 'id' => PEOPLE['anna']]);
ok('B4 a form from another account that names the first person\'s account is refused, shows no code, and changes nothing of hers',
    $forged['status'] === 302 && $forged['location'] === '/account?error=totp_off' && codesOn($forged['body']) === [] && stored('anna') === $annaStored && counts('ben') === '0 stored, 0 unused',
    describe($forged) . '; her codes ' . (stored('anna') === $annaStored ? 'unchanged' : 'CHANGED') . '; his: ' . counts('ben'));
// A browser that has given the password and NOT the code is exactly what somebody
// with a stolen password has. It is given the worst case: a code that is right.
[$halfJar] = passwordStep('anna', ip(24));
$halfPage  = req($halfJar, 'GET', '/auth/2fa/setup?complete=1', null, ip(24));
$halfForm  = req($halfJar, 'POST', '/auth/2fa/setup', ['csrf_token' => token(req($halfJar, 'GET', '/auth/2fa/verify', null, ip(24))['body']), 'action' => 'new_backup_codes', 'code' => appCode($key)], ip(24));
ok('B5 a browser that has given only the password is sent to sign in by both the page and the "new codes" form, and nothing of hers changes',
    $halfPage['status'] === 302 && str_starts_with($halfPage['location'], '/login') === true && codesOn($halfPage['body']) === [] && $halfForm['status'] === 302 && str_starts_with($halfForm['location'], '/login') === true && codesOn($halfForm['body']) === [] && stored('anna') === $annaStored,
    describe($halfPage) . ' / ' . describe($halfForm) . '; her codes ' . (stored('anna') === $annaStored ? 'unchanged' : 'CHANGED'));

// =============================================================================
head('C. A backup code signs the person in, once');
// =============================================================================
[$jar, $in] = passwordStep('anna', ip(31));
$codePage   = req($jar, 'GET', '/auth/2fa/verify', null, ip(31));
ok('C1 with it on, the password alone does not sign in: the person waits at the code page, which has a box for a backup code',
    $in['status'] === 302 && $in['location'] === '/auth/2fa/verify' && $codePage['status'] === 200 && str_contains($codePage['body'], 'name="use_backup" value="1"') === true && signedIn($jar, ip(31)) === false, describe($in) . '; code page: HTTP ' . $codePage['status']);
$refused = codeStep($jar, ip(31), ['code' => wrongBackupCode($annaCodes), 'use_backup' => '1']);
$after   = req($jar, 'GET', '/auth/2fa/verify', null, ip(31));
ok('C2 a WRONG backup code is refused, the page says so, and the person is not signed in',
    $refused['status'] === 302 && $refused['location'] === '/auth/2fa/verify' && said($after) === 'danger: Invalid code. Please try again.' && signedIn($jar, ip(31)) === false, describe($refused) . '; the page then said: ' . said($after));
$hits = (int) one('SELECT COUNT(*) AS n FROM tblApiRateLimits WHERE bucket = ?', 's', ['totp_verify_user:' . PEOPLE['anna']])['n'];
ok('C3 that wrong try was counted against the limit and recorded, and used up none of her codes', $hits === 1 && records('anna', 'TotpVerifyFailed') === 1 && stored('anna') === $annaStored, 'counted: ' . $hits . '; recorded: ' . records('anna', 'TotpVerifyFailed'));
$verifiedBefore = records('anna', 'TotpVerified');
$used           = codeStep($jar, ip(31), ['code' => strtolower(nth($annaCodes, 0)), 'use_backup' => '1']);
ok('C4 a right backup code, typed in small letters and with NO authenticator code, signs her in',
    $used['status'] === 302 && str_contains($used['location'], '2fa') === false && signedIn($jar, ip(31)) === true, describe($used));
$usedRow = one("SELECT activityDescription FROM tblActivityLogs WHERE userID = ? AND activityType = 'TotpVerified' ORDER BY logID DESC LIMIT 1", 'i', [PEOPLE['anna']]);
ok('C5 using it is written to the activity log, and the record says a backup code was used',
    records('anna', 'TotpVerified') === $verifiedBefore + 1 && str_contains((string) ($usedRow['activityDescription'] ?? ''), 'backup code') === true, 'records: ' . $verifiedBefore . ' then ' . records('anna', 'TotpVerified') . '; the newest says: ' . (string) ($usedRow['activityDescription'] ?? '(none)'));
$usedRows = q('SELECT codeHash, usedAt FROM tblTotpBackupCodes WHERE userID = ? AND isUsed = 1', 'i', [PEOPLE['anna']]);
ok('C6 exactly that one code is marked used, with the time, and seven are left',
    count($usedRows) === 1 && password_verify(nth($annaCodes, 0), (string) $usedRows[0]['codeHash']) === true && $usedRows[0]['usedAt'] !== null && counts('anna') === '8 stored, 7 unused', count($usedRows) . ' marked used; ' . counts('anna'));
[$jar2]  = passwordStep('anna', ip(32));
$twice   = codeStep($jar2, ip(32), ['code' => nth($annaCodes, 0), 'use_backup' => '1']);
ok('C7 the SAME code a second time, from another browser, is refused, and that browser is not signed in',
    $twice['status'] === 302 && $twice['location'] === '/auth/2fa/verify' && signedIn($jar2, ip(32)) === false && counts('anna') === '8 stored, 7 unused', describe($twice) . '; ' . counts('anna'));
ok('C8 the Account page now says seven are left', str_contains(req($jar, 'GET', '/account', null, ip(31))['body'], 'You have 7 unused backup codes') === true);
$next = codeStep($jar2, ip(32), ['code' => nth($annaCodes, 1), 'use_backup' => '1']);
ok('C9 a different unused code still works, in the browser that was just refused', $next['status'] === 302 && str_contains($next['location'], '2fa') === false && signedIn($jar2, ip(32)) === true && counts('anna') === '8 stored, 6 unused', describe($next) . '; ' . counts('anna'));
[$jar3]  = passwordStep('anna', ip(33));
$inBox   = codeStep($jar3, ip(33), ['code' => nth($annaCodes, 2)]);
ok('C10 a backup code is taken only as a backup code: sent as an authenticator code it is refused and not used up', $inBox['status'] === 302 && $inBox['location'] === '/auth/2fa/verify' && signedIn($jar3, ip(33)) === false && counts('anna') === '8 stored, 6 unused', describe($inBox) . '; ' . counts('anna'));
$list = codeStep($jar3, ip(33), ['code' => ['a', 'b'], 'use_backup' => '1']);
ok('C11 a list sent where a code is expected is answered like an empty box, not with an error page', $list['status'] === 302 && $list['location'] === '/auth/2fa/verify', describe($list));
$byApp = codeStep($jar3, ip(33), ['code' => appCode($key)]);
ok('C12 an authenticator code still signs her in, and uses up no backup code', $byApp['status'] === 302 && str_contains($byApp['location'], '2fa') === false && signedIn($jar3, ip(33)) === true && counts('anna') === '8 stored, 6 unused', describe($byApp) . '; ' . counts('anna'));

// Another person, with two-step sign-in on herself, waiting at the code page,
// sends one of Anna's UNUSED codes. A code belongs to one account.
$cara       = switchOn('cara', ip(34));
[$caraJar]  = passwordStep('cara', ip(35));
$annaBefore = stored('anna');
$theft      = codeStep($caraJar, ip(35), ['code' => nth($annaCodes, 3), 'use_backup' => '1']);
$caraHits   = (int) one('SELECT COUNT(*) AS n FROM tblApiRateLimits WHERE bucket = ?', 's', ['totp_verify_user:' . PEOPLE['cara']])['n'];
ok('C13 ANOTHER person\'s unused code does not sign this person in: refused, not signed in, counted against her, and the other person\'s codes untouched',
    count($cara['codes']) === 8 && count($annaCodes) === 8 && $theft['status'] === 302 && $theft['location'] === '/auth/2fa/verify' && signedIn($caraJar, ip(35)) === false
    && stored('anna') === $annaBefore && counts('anna') === '8 stored, 6 unused' && counts('cara') === '8 stored, 8 unused' && $caraHits === 1,
    describe($theft) . '; Anna: ' . counts('anna') . (stored('anna') === $annaBefore ? '' : ' (CHANGED)') . '; Cara: ' . counts('cara') . '; counted against Cara: ' . $caraHits);
// A RIGHT backup code of her own, on a form that is not the page's own.
[$jar4]     = passwordStep('anna', ip(36));
$noToken    = req($jar4, 'POST', '/auth/2fa/verify', ['code' => nth($annaCodes, 3), 'use_backup' => '1'], ip(36));
$otherToken = req($jar4, 'POST', '/auth/2fa/verify', ['csrf_token' => token(req(newJar(), 'GET', '/login', null, ip(36))['body']), 'code' => nth($annaCodes, 3), 'use_backup' => '1'], ip(36));
$afterBoth  = req($jar4, 'GET', '/auth/2fa/verify', null, ip(36));
ok('C14 a RIGHT backup code on a form with no token, or with another browser\'s token, does not sign her in and is not used up',
    $noToken['status'] === 302 && $noToken['location'] === '/auth/2fa/verify' && $otherToken['status'] === 302 && $otherToken['location'] === '/auth/2fa/verify' && said($afterBoth) === 'danger: Invalid or expired form token.'
    && signedIn($jar4, ip(36)) === false && counts('anna') === '8 stored, 6 unused', describe($noToken) . ' / ' . describe($otherToken) . '; the page then said: ' . said($afterBoth) . '; ' . counts('anna'));

// =============================================================================
head('D. One code, one sign-in, even at the same moment');
// =============================================================================
$gail = switchOn('gail', ip(41));
ok('D1 control: another account switches it on through the page and is shown eight codes', count($gail['codes']) === 8, describe($gail['answer']) . '; codes: ' . count($gail['codes']));
foreach ($extraPorts as $n => $extraPort) {
    $state['extra'][$n] = $startPortal($extraPort);
}
$deadline = microtime(true) + 15;
while (count(array_filter($extraPorts, 'portAnswers')) < count($extraPorts) && microtime(true) < $deadline) {
    usleep(100000);
}
$allPorts = array_merge([$port], $extraPorts);
$up       = count(array_filter($allPorts, 'portAnswers'));
$jars     = $up === 8 && count($gail['codes']) === 8 ? burst($allPorts, 'gail', ip(42), nth($gail['codes'], 0), 8) : [];
// Read NOW, for D2 and D3: Gail is used again further down (D5d), and that changes all three.
$in          = count(array_filter($jars, static fn (string $j): bool => signedIn($j, ip(42)) === true));
$gailCounts  = counts('gail');
$gailUses    = records('gail', 'TotpVerified');

// Two browsers of ONE account, each with the set-up page open (so each has a
// key of its own), both send a right code at the same moment.
$pair = [];
foreach ([0, 1] as $n) {
    [$jar]  = passwordStep('lena', ip(43 + $n));
    $page   = req($jar, 'GET', '/auth/2fa/setup', null, ip(43 + $n));
    $pair[] = ['port' => $allPorts[$n], 'jar' => $jar, 'path' => '/auth/2fa/setup', 'ip' => ip(43 + $n), 'key' => keyOn($page['body']),
        'fields' => ['csrf_token' => token($page['body']), 'code' => keyOn($page['body']) === '' ? '' : appCode(keyOn($page['body']))]];
}
$both = $up === 8 ? sendTogether($pair) : [['status' => 0, 'location' => '', 'body' => ''], ['status' => 0, 'location' => '', 'body' => '']];

// Two DIFFERENT people at the same moment, three pairs one after another.
// None of the six has a row of codes yet. That is the case the first way of
// removing the old set got wrong: both requests held the same empty stretch
// of the index, and the database stopped one of every pair.
$six  = ['mia', 'noah', 'omar', 'pia', 'quin', 'rosa'];
$open = [];
foreach ($six as $n => $who) {
    [$jar]      = passwordStep($who, ip(101 + $n));
    $open[$who] = ['jar' => $jar, 'ip' => ip(101 + $n), 'key' => '', 'codes' => [], 'new' => [], 'said' => ''];
}
/** One pair's two forms sent together; each answer is kept under its person's name. */
$sendPair = static function (array $pairOf, string $path, callable $fieldsFor) use (&$open, $allPorts, $up): void {
    $forms = [];
    foreach ($pairOf as $n => $who) {
        $forms[] = ['port' => $allPorts[$n], 'jar' => $open[$who]['jar'], 'path' => $path, 'ip' => $open[$who]['ip'], 'fields' => $fieldsFor($who)];
    }
    $answers = $up === 8 ? sendTogether($forms) : [];
    foreach ($pairOf as $n => $who) {
        $open[$who]['answer'] = $answers[$n] ?? ['status' => 0, 'location' => '', 'body' => ''];
    }
};
for ($round = 0; $round < 3; $round++) {
    $pairOf = [$six[2 * $round], $six[2 * $round + 1]];
    $sendPair($pairOf, '/auth/2fa/setup', static function (string $who) use (&$open): array {
        $page              = req($open[$who]['jar'], 'GET', '/auth/2fa/setup', null, $open[$who]['ip']);
        $open[$who]['key'] = keyOn($page['body']);

        return ['csrf_token' => token($page['body']), 'code' => $open[$who]['key'] === '' ? '' : appCode($open[$who]['key'])];
    });
    foreach ($pairOf as $who) {
        $open[$who]['codes'] = codesOn($open[$who]['answer']['body']);
        $open[$who]['said']  = describe($open[$who]['answer']);
    }
}
$shownOn  = count(array_filter($six, static fn (string $who): bool => count($open[$who]['codes']) === 8));
$storedOn = count(array_filter($six, static fn (string $who): bool => counts($who) === '8 stored, 8 unused' && (int) one('SELECT totpEnabled FROM tblUsers WHERE userID = ?', 'i', [PEOPLE[$who]])['totpEnabled'] === 1));
$refused  = implode('; ', array_map(static fn (string $who): string => $who . ': ' . $open[$who]['said'], array_values(array_filter($six, static fn (string $who): bool => count($open[$who]['codes']) !== 8))));

// Anybody that round left OFF is switched on now, alone. That is not part of
// the check above (its figures are already taken): it is so that the next
// check has six people with it on whatever the last one found, and so shows
// its own fault and not the last one's.
foreach ($six as $who) {
    if (count($open[$who]['codes']) !== 8) {
        $page                = req($open[$who]['jar'], 'GET', '/auth/2fa/setup', null, $open[$who]['ip']);
        $open[$who]['key']   = keyOn($page['body']);
        $alone               = req($open[$who]['jar'], 'POST', '/auth/2fa/setup', ['csrf_token' => token($page['body']), 'code' => $open[$who]['key'] === '' ? '' : appCode($open[$who]['key'])], $open[$who]['ip']);
        $open[$who]['codes'] = codesOn($alone['body']);
        note($who . ' was not switched on by the pair above, and was switched on alone afterwards (' . count($open[$who]['codes']) . ' codes shown)');
    }
}

// The same six, now WITH it on and with NO rows of codes (what everybody will
// have after an upgrade that removes the codes nobody was shown), ask for a
// new set, again in three pairs.
q('DELETE FROM tblTotpBackupCodes WHERE userID BETWEEN ? AND ?', 'ii', [PEOPLE['mia'], PEOPLE['rosa']]);
for ($round = 0; $round < 3; $round++) {
    $pairOf = [$six[2 * $round], $six[2 * $round + 1]];
    $sendPair($pairOf, '/auth/2fa/setup', static function (string $who) use (&$open): array {
        $account = req($open[$who]['jar'], 'GET', '/account', null, $open[$who]['ip']);

        return ['csrf_token' => token($account['body']), 'action' => 'new_backup_codes', 'code' => appCode($open[$who]['key'])];
    });
    foreach ($pairOf as $who) {
        $open[$who]['new']  = codesOn($open[$who]['answer']['body']);
        $open[$who]['said'] = describe($open[$who]['answer']);
    }
}
$shownNew   = count(array_filter($six, static fn (string $who): bool => count($open[$who]['new']) === 8));
// Each person's FIRST new code against their first stored row: six slow comparisons, not forty-eight.
$storedNew  = count(array_filter($six, static fn (string $who): bool => counts($who) === '8 stored, 8 unused'
    && password_verify(nth($open[$who]['new'], 0), (string) (one('SELECT codeHash FROM tblTotpBackupCodes WHERE userID = ? ORDER BY codeID LIMIT 1', 'i', [PEOPLE[$who]])['codeHash'] ?? '')) === true));
$refusedNew = implode('; ', array_map(static fn (string $who): string => $who . ': ' . $open[$who]['said'], array_values(array_filter($six, static fn (string $who): bool => count($open[$who]['new']) !== 8))));

// ONE person, two browsers, a new set asked for at the same moment. Both are
// answered (neither can know of the other), and the two requests must take
// turns: eight codes stored at the end, those of one of the two, not sixteen.
$gailTwo  = count($gail['codes']) === 8 ? signInWithApp('gail', $gail['key'], ip(47)) : '';
$twoForms = [];
foreach ([[$gail['jar'], ip(41)], [$gailTwo, ip(47)]] as $n => [$jar, $from]) {
    $twoForms[] = ['port' => $allPorts[$n], 'jar' => $jar, 'path' => '/auth/2fa/setup', 'ip' => $from,
        'fields' => ['csrf_token' => $jar === '' ? '' : token(req($jar, 'GET', '/account', null, $from)['body']), 'action' => 'new_backup_codes', 'code' => appCode($gail['key'])]];
}
$twoSets = ($up === 8 && $gailTwo !== '') ? sendTogether($twoForms) : [['status' => 0, 'location' => '', 'body' => ''], ['status' => 0, 'location' => '', 'body' => '']];
foreach (array_keys($state['extra']) as $n) {
    stopServer($state['extra'][$n]);
}
ok('D2 eight browsers send the SAME backup code at the same moment, to eight portal servers: exactly ONE is signed in', $up === 8 && count($jars) === 8 && $in === 1, 'servers up: ' . $up . ' of 8; browsers: ' . count($jars) . '; signed in: ' . $in);
ok('D3 the code is marked used once, the other seven codes are untouched, and one use is recorded',
    $gailCounts === '8 stored, 7 unused' && $gailUses === 1, $gailCounts . '; recorded uses: ' . $gailUses);
$winner = count(codesOn($both[0]['body'])) === 8 ? 0 : 1;
$loser  = 1 - $winner;
ok('D4 one account switches it on from two browsers at the same moment: ONE is shown eight codes, and the other is sent to Account and shown none',
    $pair[0]['key'] !== '' && $pair[1]['key'] !== '' && $pair[0]['key'] !== $pair[1]['key'] && $both[$winner]['status'] === 200 && count(codesOn($both[$winner]['body'])) === 8
    && $both[$loser]['status'] === 302 && $both[$loser]['location'] === '/account?totp_on=1' && codesOn($both[$loser]['body']) === [],
    'first: ' . describe($both[0]) . ', ' . count(codesOn($both[0]['body'])) . ' codes; second: ' . describe($both[1]) . ', ' . count(codesOn($both[1]['body'])) . ' codes');
$lenaHashes = array_column(q('SELECT codeHash FROM tblTotpBackupCodes WHERE userID = ? ORDER BY codeID', 'i', [PEOPLE['lena']]), 'codeHash');
ok('D5 and what is stored is that one browser\'s: its eight codes, and its key (the authenticator that holds it signs in; the other one\'s does not)',
    count($lenaHashes) === 8 && matching(codesOn($both[$winner]['body']), $lenaHashes) === 8 && signInWithApp('lena', $pair[$winner]['key'], ip(45)) !== '' && signInWithApp('lena', $pair[$loser]['key'], ip(46)) === '',
    count($lenaHashes) . ' stored; matched: ' . matching(codesOn($both[$winner]['body']), $lenaHashes));
ok('D5b three times, two DIFFERENT people switch it on at the same moment: all six are shown eight codes, and each has it on with eight stored',
    $shownOn === 6 && $storedOn === 6, 'shown eight codes: ' . $shownOn . ' of 6; on with eight stored: ' . $storedOn . ' of 6' . ($refused === '' ? '' : '; not shown: ' . $refused));
ok('D5c three times, two different people with it on and NO rows of codes ask for a new set at the same moment: all six are shown eight, and theirs are the ones stored',
    $shownNew === 6 && $storedNew === 6, 'shown eight codes: ' . $shownNew . ' of 6; stored as shown: ' . $storedNew . ' of 6' . ($refusedNew === '' ? '' : '; not shown: ' . $refusedNew));
$gailFirst = (string) (one('SELECT codeHash FROM tblTotpBackupCodes WHERE userID = ? ORDER BY codeID LIMIT 1', 'i', [PEOPLE['gail']])['codeHash'] ?? '');
$gailSets  = [codesOn($twoSets[0]['body']), codesOn($twoSets[1]['body'])];
$oneOfThem = (password_verify(nth($gailSets[0], 0), $gailFirst) === true) !== (password_verify(nth($gailSets[1], 0), $gailFirst) === true);
ok('D5d ONE person asks for a new set from two browsers at the same moment: both are answered with eight codes, and eight are stored at the end (one browser\'s), not sixteen',
    $gailTwo !== '' && count($gailSets[0]) === 8 && count($gailSets[1]) === 8 && counts('gail') === '8 stored, 8 unused' && $oneOfThem === true,
    ($gailTwo === '' ? 'her second sign-in did not work; ' : '') . 'first: ' . describe($twoSets[0]) . ', ' . count($gailSets[0]) . ' codes; second: ' . describe($twoSets[1]) . ', ' . count($gailSets[1]) . ' codes; ' . counts('gail') . '; stored set is exactly one of the two: ' . ($oneOfThem === true ? 'yes' : 'NO'));
ok('D6 the seven extra servers are stopped', count(array_filter($extraPorts, 'portAnswers')) === 0);

// =============================================================================
head('E. Wrong tries at the code page are counted, and stop');
// =============================================================================
$finn = switchOn('finn', ip(51));
// All but two of his codes are taken away, so that each wrong try below is
// compared with two fingerprints instead of eight. (Each comparison is slow
// on purpose; this only saves the run's time.)
$keep = array_column(q('SELECT codeID FROM tblTotpBackupCodes WHERE userID = ? ORDER BY codeID LIMIT 2', 'i', [PEOPLE['finn']]), 'codeID');
q('DELETE FROM tblTotpBackupCodes WHERE userID = ? AND codeID NOT IN (?, ?)', 'iii', [PEOPLE['finn'], (int) ($keep[0] ?? 0), (int) ($keep[1] ?? 0)]);
$finnCode    = '';
$finnHashes  = array_column(q('SELECT codeHash FROM tblTotpBackupCodes WHERE userID = ?', 'i', [PEOPLE['finn']]), 'codeHash');
foreach ($finn['codes'] as $code) {
    if (password_verify($code, (string) ($finnHashes[0] ?? '')) === true) {
        $finnCode = $code;
    }
}
[$jar] = passwordStep('finn', ip(52));
$answers = [];
for ($i = 1; $i <= 5; $i++) {
    $answers[] = codeStep($jar, ip(52), $i % 2 === 0 ? ['code' => wrongAppCode($finn['key'])] : ['code' => wrongBackupCode($finn['codes']), 'use_backup' => '1'])['location'];
}
ok('E1 five wrong codes (three backup codes and two authenticator codes) are each refused, and five failures are recorded',
    $finnCode !== '' && $answers === array_fill(0, 5, '/auth/2fa/verify') && records('finn', 'TotpVerifyFailed') === 5, 'answers: ' . implode(', ', $answers) . '; recorded: ' . records('finn', 'TotpVerifyFailed'));
$blocked = codeStep($jar, ip(52), ['code' => $finnCode, 'use_backup' => '1']);
$told    = req($jar, 'GET', '/auth/2fa/verify', null, ip(52));
ok('E2 the sixth try, with a RIGHT backup code, is refused: the page says there were too many attempts',
    $blocked['status'] === 302 && $blocked['location'] === '/auth/2fa/verify' && str_starts_with(said($told), 'danger: Too many attempts') === true && signedIn($jar, ip(52)) === false, describe($blocked) . '; the page then said: ' . said($told));
ok('E3 and that right code was NOT used up while he was stopped', counts('finn') === '2 stored, 2 unused', counts('finn'));
// Sixteen minutes later by the DATABASE's clock (the one the limit reads): the rows are moved, nothing waits.
q('UPDATE tblApiRateLimits SET hitAt = NOW() - INTERVAL 16 MINUTE WHERE bucket LIKE ?', 's', ['totp\\_verify:' . PEOPLE['finn'] . ':%']);
$later = codeStep($jar, ip(52), ['code' => $finnCode, 'use_backup' => '1']);
ok('E4 once those tries are 16 minutes old, the same code signs him in', $later['status'] === 302 && str_contains($later['location'], '2fa') === false && signedIn($jar, ip(52)) === true && counts('finn') === '2 stored, 1 unused', describe($later) . '; ' . counts('finn'));

// =============================================================================
head('F. "Make new backup codes" on the Account page');
// =============================================================================
$dev      = switchOn('dev', ip(61));
$devOld   = $dev['codes'];
$devStore = stored('dev');
$devRows  = explode(',', rowNumbers('dev'));
ok('F1 control: the account has it on, with eight codes it will now replace', count($devOld) === 8 && counts('dev') === '8 stored, 8 unused', counts('dev'));
$no = askNewCodes($dev['jar'], ip(61), ['code' => wrongAppCode($dev['key'])]);
$pg = req($dev['jar'], 'GET', $no['location'] === '' ? '/account' : $no['location'], null, ip(61));
ok('F2 a WRONG authenticator code is refused: no code is shown, the old set is untouched, and the Account page says why',
    $no['status'] === 302 && $no['location'] === '/account?error=totp_code' && codesOn($no['body']) === [] && stored('dev') === $devStore && str_starts_with(said($pg), 'danger: That code is not right') === true, describe($no) . '; Account then said: ' . said($pg));
ok('F3 that refusal is recorded', records('dev', 'TotpBackupCodesFailed') === 1, 'records: ' . records('dev', 'TotpBackupCodesFailed'));
q('DELETE FROM tblApiRateLimits WHERE bucket = ?', 's', ['totp_newcodes:' . PEOPLE['dev']]); // five more refusals follow; part G is about the count
$tried = [];
foreach (['no code at all' => [], 'an empty code' => ['code' => ''], 'one of his own backup codes' => ['code' => nth($devOld, 0)], 'a list' => ['code' => ['1', '2']], 'the right code with letters after it' => ['code' => appCode($dev['key']) . 'ab']] as $what => $fields) {
    $answer = askNewCodes($dev['jar'], ip(61), $fields);
    if ($answer['status'] !== 302 || $answer['location'] !== '/account?error=totp_code' || codesOn($answer['body']) !== [] || stored('dev') !== $devStore) {
        $tried[] = $what . ': ' . describe($answer);
    }
}
ok('F4 so are no code, an empty one, a backup code, a list, and a right code with letters after it', $tried === [], implode('; ', $tried));
q('DELETE FROM tblApiRateLimits WHERE bucket = ?', 's', ['totp_newcodes:' . PEOPLE['dev']]); // and again, before the right code is tried
$account = req($dev['jar'], 'GET', '/account', null, ip(61));
$badForm = req($dev['jar'], 'POST', '/auth/2fa/setup', ['csrf_token' => str_repeat('0', 64), 'action' => 'new_backup_codes', 'code' => appCode($dev['key'])], ip(61));
$badPage = req($dev['jar'], 'GET', $badForm['location'] === '' ? '/account' : $badForm['location'], null, ip(61));
ok('F5 a right code on a form that is not the page\'s own is refused and changes nothing; the Account page says the form had been sent or had expired, and what to do',
    $badForm['status'] === 302 && $badForm['location'] === '/account?error=totp_form' && stored('dev') === $devStore
    && str_starts_with(said($badPage), 'danger: That form had already been sent, or had expired. If you were making new backup codes and did not see them, make a set again') === true, describe($badForm) . '; Account then said: ' . said($badPage));
$byAddress = req($dev['jar'], 'GET', '/auth/2fa/setup?action=new_backup_codes&code=' . appCode($dev['key']) . '&csrf_token=' . token($account['body']), null, ip(61));
ok('F6 the same asked for in the address (not sent as a form) makes nothing and shows nothing', $byAddress['status'] === 302 && codesOn($byAddress['body']) === [] && stored('dev') === $devStore, describe($byAddress));
$made   = askNewCodes($dev['jar'], ip(61), ['code' => appCode($dev['key'])]);
$devNew = codesOn($made['body']);
ok('F7 the RIGHT code is answered with a page of eight new codes, none of them an old one, and the page says the old codes no longer work',
    $made['status'] === 200 && count($devNew) === 8 && count(array_unique($devNew)) === 8 && array_intersect($devNew, $devOld) === [] && str_starts_with(said($made), 'success: Your new backup codes are ready. Your old codes no longer work.') === true && preg_match('/^Cache-Control:.*\bno-store\b/mi', $made['head']) === 1,
    describe($made) . '; codes: ' . count($devNew));
$hashes  = array_column(q('SELECT codeHash FROM tblTotpBackupCodes WHERE userID = ? AND isUsed = 0 ORDER BY codeID', 'i', [PEOPLE['dev']]), 'codeHash');
$newOk   = matching($devNew, $hashes);
$oldRows = array_intersect($devRows, explode(',', rowNumbers('dev')));
ok('F8 the database holds exactly the eight new ones, as fingerprints, and not one of the old rows is left', count($hashes) === 8 && $newOk === 8 && count($devRows) === 8 && $oldRows === [] && counts('dev') === '8 stored, 8 unused', counts('dev') . '; new ones stored: ' . $newOk . '; old rows left: ' . count($oldRows));
$devNewStore = stored('dev');
$reopened    = req($dev['jar'], 'GET', '/auth/2fa/setup', null, ip(61));
$resent      = req($dev['jar'], 'POST', '/auth/2fa/setup', ['csrf_token' => token($account['body']), 'action' => 'new_backup_codes', 'code' => appCode($dev['key'])], ip(61));
ok('F9 opening the page again, or sending the same form again, shows no code and makes no further set',
    $reopened['status'] === 302 && codesOn($reopened['body']) === [] && $resent['status'] === 302 && codesOn($resent['body']) === [] && stored('dev') === $devNewStore, describe($reopened) . ' / ' . describe($resent));
ok('F10 making the set is recorded once, and no new code is left in readable form anywhere', records('dev', 'TotpBackupCodesMade') === 1 && count($devNew) === 8 && leftReadable($devNew) === [], 'records: ' . records('dev', 'TotpBackupCodesMade') . '; left: ' . implode('; ', array_slice(leftReadable($devNew), 0, 4)));
[$jar]  = passwordStep('dev', ip(62));
$oldTry = codeStep($jar, ip(62), ['code' => nth($devOld, 0), 'use_backup' => '1']);
ok('F11 an OLD code no longer signs him in', $oldTry['status'] === 302 && $oldTry['location'] === '/auth/2fa/verify' && signedIn($jar, ip(62)) === false, describe($oldTry));
$newTry = codeStep($jar, ip(62), ['code' => nth($devNew, 0), 'use_backup' => '1']);
ok('F12 a NEW code does, once', $newTry['status'] === 302 && str_contains($newTry['location'], '2fa') === false && signedIn($jar, ip(62)) === true && counts('dev') === '8 stored, 7 unused', describe($newTry) . '; ' . counts('dev'));
// Somebody who switched it on before #613 and has used or lost every code: the page must say so.
q('DELETE FROM tblTotpBackupCodes WHERE userID = ?', 'i', [PEOPLE['dev']]);
ok('F13 with no code left, the Account page says a lost phone would lock the person out and tells them to make a set',
    str_contains(req($jar, 'GET', '/account', null, ip(62))['body'], 'You have no unused backup codes, so a lost phone would lock you out. Make a set now.') === true);
$fromNone = askNewCodes($jar, ip(62), ['code' => appCode($dev['key'])]);
ok('F14 and from none, a right code makes eight', $fromNone['status'] === 200 && count(codesOn($fromNone['body'])) === 8 && counts('dev') === '8 stored, 8 unused', describe($fromNone) . '; ' . counts('dev'));
// The script that sends the form only once (a second press used to leave the
// person with a set they never saw). No browser is used here, so this reads
// the page: the form has the name the script looks for, and the script
// carries the mark the page's own rules ask of a script written into it.
$guardPage  = req($jar, 'GET', '/account', null, ip(62));
$ruleNonce  = preg_match("/^Content-Security-Policy:.*script-src[^;]*'nonce-([0-9a-f]{16,})'/mi", $guardPage['head'], $m) === 1 ? $m[1] : '';
$guardNonce = preg_match('#<script nonce="([0-9a-f]{16,})">\s*\(function \(\) \{\s*\'use strict\';\s*var form = document\.getElementById\(\'totpNewCodesForm\'\);#', $guardPage['body'], $m) === 1 ? $m[1] : '';
ok('F15 the Account form has the script that sends it only once, and the script carries the mark the page\'s own rules ask for',
    $guardPage['status'] === 200 && substr_count($guardPage['body'], '<form method="post" action="/auth/2fa/setup" class="mb-3"') === 1 && str_contains($guardPage['body'], 'id="totpNewCodesForm"') === true
    && $ruleNonce !== '' && $guardNonce === $ruleNonce && str_contains($guardPage['body'], 'button.disabled = true;') === true,
    'the form: ' . (str_contains($guardPage['body'], 'id="totpNewCodesForm"') === true ? 'found' : 'NOT found') . '; the rules\' mark: ' . ($ruleNonce === '' ? '(none)' : 'present') . '; the script\'s: ' . ($guardNonce === '' ? '(no such script, or no mark)' : ($guardNonce === $ruleNonce ? 'the same' : 'DIFFERENT')));

// =============================================================================
head('G. "Make new backup codes" has its own limit');
// =============================================================================
$hugo      = switchOn('hugo', ip(71));
$hugoStore = stored('hugo');
$answers   = [];
for ($i = 1; $i <= 5; $i++) {
    $answers[] = askNewCodes($hugo['jar'], ip(71), ['code' => wrongAppCode($hugo['key'])])['location'];
}
ok('G1 five wrong codes are each refused', count($hugo['codes']) === 8 && $answers === array_fill(0, 5, '/account?error=totp_code'), implode(', ', $answers));
$stopped = askNewCodes($hugo['jar'], ip(71), ['code' => appCode($hugo['key'])]);
$pg      = req($hugo['jar'], 'GET', $stopped['location'] === '' ? '/account' : $stopped['location'], null, ip(71));
ok('G2 the sixth try, with the RIGHT code, is refused, shows no code and changes nothing; the Account page says to wait',
    $stopped['status'] === 302 && $stopped['location'] === '/account?error=totp_wait' && codesOn($stopped['body']) === [] && stored('hugo') === $hugoStore && str_starts_with(said($pg), 'danger: Too many wrong codes') === true, describe($stopped) . '; Account then said: ' . said($pg));
ok('G3 those wrong codes do not stop his next sign-in: the authenticator signs him in from a new browser at the same address', signInWithApp('hugo', $hugo['key'], ip(71)) !== '');
q('UPDATE tblApiRateLimits SET hitAt = NOW() - INTERVAL 16 MINUTE WHERE bucket = ?', 's', ['totp_newcodes:' . PEOPLE['hugo']]);
$later = askNewCodes($hugo['jar'], ip(71), ['code' => appCode($hugo['key'])]);
ok('G4 once those tries are 16 minutes old, the right code makes a new set', $later['status'] === 200 && count(codesOn($later['body'])) === 8 && stored('hugo') !== $hugoStore, describe($later));

// =============================================================================
head('H. The two-step pages name no file that is not there');
// =============================================================================
[$jar]        = passwordStep('anna', ip(81));
$codePage     = req($jar, 'GET', '/auth/2fa/verify', null, ip(81));
$signInPage   = req(newJar(), 'GET', '/login', null, ip(81));
$asked        = filesAsked($codePage['body']);
$named        = array_values(array_filter(['href="/assets/css/bootstrap.min.css"', 'href="/assets/css/fontawesome/all.min.css"', 'src="/assets/js/bootstrap.bundle.min.js"'], static fn (string $n): bool => str_contains($codePage['body'], $n) === true));
ok('H1 the code page no longer asks, as its first choice, for any of the three files that are not in the portal', $codePage['status'] === 200 && $named === [], implode(', ', $named));
ok('H2 it asks for style sheets and a script, and every one it asks of this server is there', count($asked) >= 4 && notThere($asked) === [], count($asked) . ' asked; not there: ' . implode(', ', notThere($asked)));
ok('H3 every file it asks for is one the sign-in page asks for too', $signInPage['status'] === 200 && array_diff($asked, filesAsked($signInPage['body'])) === [], 'only on the code page: ' . implode(', ', array_diff($asked, filesAsked($signInPage['body']))));
$benSetup  = req($benJar, 'GET', '/auth/2fa/setup', null, ip(21));
$accountPg = req($benJar, 'GET', '/account', null, ip(21));
$bothAsked = array_values(array_unique(array_merge(filesAsked($benSetup['body']), filesAsked($on['body']))));
ok('H4 the set-up page and the page of codes ask for the same files as the Account page, and every one asked of this server is there',
    $benSetup['status'] === 200 && count($bothAsked) >= 4 && array_diff($bothAsked, filesAsked($accountPg['body'])) === [] && notThere($bothAsked) === [], count($bothAsked) . ' asked; not on Account: ' . implode(', ', array_diff($bothAsked, filesAsked($accountPg['body']))) . '; not there: ' . implode(', ', notThere($bothAsked)));

// =============================================================================
head('I. All or nothing, when the database refuses the codes');
// =============================================================================
$jack      = switchOn('jack', ip(91)); // BEFORE the trigger: he needs a set to lose
$jackStore = stored('jack');
$triggerMade = true;
try {
    $db->query('CREATE TRIGGER ' . TRIGGER . ' BEFORE INSERT ON tblTotpBackupCodes FOR EACH ROW BEGIN IF NEW.userID IN (' . PEOPLE['ivy'] . ', ' . PEOPLE['jack'] . ") THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the two-step self-test'; END IF; END");
} catch (Throwable $e) {
    $triggerMade = false;
    note('the trigger could not be made (' . $e->getMessage() . '), so the four checks of this part fail');
}
$ivy     = switchOn('ivy', ip(92));
$ivyPage = req($ivy['jar'], 'GET', $ivy['answer']['location'] === '' ? '/auth/2fa/setup' : $ivy['answer']['location'], null, ip(92));
$ivyRow  = one('SELECT totpEnabled, totpSecret FROM tblUsers WHERE userID = ?', 'i', [PEOPLE['ivy']]);
ok('I1 switching on while the codes cannot be stored: it is NOT switched on, no key is kept, and no code is shown',
    $triggerMade === true && $ivy['key'] !== '' && $ivy['codes'] === [] && $ivy['answer']['status'] === 302 && (int) $ivyRow['totpEnabled'] === 0 && $ivyRow['totpSecret'] === null && counts('ivy') === '0 stored, 0 unused',
    describe($ivy['answer']) . '; on: ' . $ivyRow['totpEnabled'] . '; ' . counts('ivy'));
ok('I2 the page says it could not be switched on and that nothing was changed, and shows the same key to try again',
    str_starts_with(said($ivyPage), 'danger: Two-factor authentication could not be switched on. Nothing was changed.') === true && keyOn($ivyPage['body']) === $ivy['key'], said($ivyPage));
$lost   = askNewCodes($jack['jar'], ip(91), ['code' => appCode($jack['key'])]);
$lostPg = req($jack['jar'], 'GET', $lost['location'] === '' ? '/account' : $lost['location'], null, ip(91));
ok('I3 making a new set while it cannot be stored: no code is shown, and the OLD set is still there, exactly as it was',
    $triggerMade === true && count($jack['codes']) === 8 && $lost['status'] === 302 && $lost['location'] === '/account?error=totp_codes_failed' && codesOn($lost['body']) === [] && stored('jack') === $jackStore && str_contains(said($lostPg), 'your old codes still work') === true,
    describe($lost) . '; old set ' . (stored('jack') === $jackStore ? 'unchanged' : 'CHANGED OR LOST') . ' (' . counts('jack') . '); Account then said: ' . said($lostPg));
[$jar]  = passwordStep('jack', ip(93));
$stillOk = codeStep($jar, ip(93), ['code' => nth($jack['codes'], 0), 'use_backup' => '1']);
ok('I4 and one of those old codes still signs him in', $stillOk['status'] === 302 && str_contains($stillOk['location'], '2fa') === false && signedIn($jar, ip(93)) === true, describe($stillOk));
// The same refusal for somebody who has NO old codes: the words must not promise him any.
q('DELETE FROM tblTotpBackupCodes WHERE userID = ?', 'i', [PEOPLE['jack']]);
$none   = askNewCodes($jar, ip(93), ['code' => appCode($jack['key'])]);
$nonePg = req($jar, 'GET', $none['location'] === '' ? '/account' : $none['location'], null, ip(93));
ok('I5 the same refusal with NO old set: he is told nothing was changed, and is NOT told that his old codes still work',
    $triggerMade === true && $none['status'] === 302 && $none['location'] === '/account?error=totp_codes_failed' && codesOn($none['body']) === [] && counts('jack') === '0 stored, 0 unused'
    && said($nonePg) === 'danger: New backup codes could not be made. Nothing was changed. Please try again.', describe($none) . '; ' . counts('jack') . '; Account then said: ' . said($nonePg));
$db->query('DROP TRIGGER IF EXISTS ' . TRIGGER);

// =============================================================================
head('J. What the old code left in a session is taken out');
// =============================================================================
[$kimJar] = passwordStep('kim', ip(95));
$file     = sessionFile($kimJar);
$text     = $file === '' ? '' : (string) file_get_contents($file);
$oldCodes = ['0BADC0DE', '0BADC0D3'];
$oldKey   = 'OLDKEYOLDKEYOLDKEYOLDKEYOLDKEY22';
// The form PHP's own session files have here: name|value;name|value;
$planted  = $file !== '' && str_contains($text, 'user_id|i:' . PEOPLE['kim'] . ';') === true
    && file_put_contents($file, $text . 'totp_backup_codes|' . serialize($oldCodes) . 'totp_setup_secret|' . serialize($oldKey), LOCK_EX) !== false;
$probe    = $planted === true ? req($kimJar, 'GET', '/account', null, ip(95)) : ['status' => 0];
$before   = $file === '' ? '' : (string) file_get_contents($file);
ok('J1 control: a session is given the readable codes and key the old code left, and the portal still reads it as her session',
    $planted === true && $probe['status'] === 200 && str_contains($before, $oldCodes[0]) === true && str_contains($before, $oldKey) === true, $file === '' ? 'her session file was not found' : 'planted: ' . ($planted === true ? 'yes' : 'no') . '; Account: HTTP ' . $probe['status']);
$kimPage = req($kimJar, 'GET', '/auth/2fa/setup', null, ip(95));
$after   = $file === '' ? '' : (string) file_get_contents($file);
ok('J2 opening the set-up page takes them out: the session holds neither, and the page shows a NEW key, not the old one',
    $kimPage['status'] === 200 && $after !== '' && str_contains($after, $oldCodes[0]) === false && str_contains($after, $oldCodes[1]) === false && str_contains($after, $oldKey) === false && keyOn($kimPage['body']) !== '' && keyOn($kimPage['body']) !== $oldKey && codesOn($kimPage['body']) === [],
    describe($kimPage) . '; old codes still in the session: ' . (str_contains($after, $oldCodes[0]) === true ? 'YES' : 'no') . '; old key: ' . (str_contains($after, $oldKey) === true ? 'YES' : 'no'));
[$coldJar] = passwordStep('kim', ip(96));
$cold      = req($coldJar, 'POST', '/auth/2fa/setup', ['csrf_token' => token(req($coldJar, 'GET', '/account', null, ip(96))['body']), 'code' => '123456'], ip(96));
$coldPage  = req($coldJar, 'GET', $cold['location'] === '' ? '/auth/2fa/setup' : $cold['location'], null, ip(96));
ok('J3 a code sent when no key is waiting switches nothing on, and the page says it has made a new key to add to the app',
    $cold['status'] === 302 && $cold['location'] === '/auth/2fa/setup' && str_starts_with(said($coldPage), 'warning: This page had been open too long, so it has made a new key') === true && keyOn($coldPage['body']) !== ''
    && (int) one('SELECT totpEnabled FROM tblUsers WHERE userID = ?', 'i', [PEOPLE['kim']])['totpEnabled'] === 0, describe($cold) . '; the page then said: ' . said($coldPage));

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log  = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad  = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the servers\' PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4))));
$mine = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => str_contains($line, 'auth/2fa/setup:') === true));
ok('it does hold the three lines part I caused, and each names the account and no code or key',
    count($mine) === 3 && str_contains($mine[0], 'account ' . PEOPLE['ivy']) === true && str_contains($mine[1], 'account ' . PEOPLE['jack']) === true && str_contains($mine[2], 'account ' . PEOPLE['jack']) === true
    && leftReadable(array_merge($ivy['key'] === '' ? [] : [$ivy['key']], $jack['codes'])) === [],
    count($mine) . ' line(s): ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 160), array_slice($mine, 0, 3))));
$everyCode = array_merge($annaCodes, $cara['codes'], $gail['codes'], $gailSets[0], $gailSets[1], codesOn($both[$winner]['body']), $finn['codes'], $devOld, $devNew, codesOn($fromNone['body']), $hugo['codes'], codesOn($later['body']), $jack['codes']);
$everyKey  = array_values(array_filter([$key, $cara['key'], $gail['key'], $pair[0]['key'], $pair[1]['key'], $finn['key'], $dev['key'], $hugo['key'], $jack['key'], $ivy['key'], keyOn($kimPage['body']), keyOn($coldPage['body']), keyOn($benPage['body'])], static fn (string $k): bool => $k !== ''));
foreach ($six as $who) {
    $everyCode = array_merge($everyCode, $open[$who]['codes'], $open[$who]['new']);
    if ($open[$who]['key'] !== '') {
        $everyKey[] = $open[$who]['key'];
    }
}
$left      = leftReadable(array_merge($everyCode, $everyKey, ['totp_backup_codes']));
ok('at the end of the whole run: none of the ' . count($everyCode) . ' backup codes and none of the ' . count($everyKey) . ' keys shown is left in readable form in any activity record, session or log',
    count($everyCode) === 200 && count($everyKey) === 19 && $left === [], count($everyCode) . ' codes, ' . count($everyKey) . ' keys; left: ' . implode('; ', array_slice($left, 0, 5)));

$before = $state['settings'];
cleanup();
$left = (int) one(
    "SELECT (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 922000 AND 922999) + (SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN 922000 AND 922999) "
    . "+ (SELECT COUNT(*) FROM tblTotpBackupCodes WHERE userID BETWEEN 922000 AND 922999) + (SELECT COUNT(*) FROM tblActivityLogs WHERE userID BETWEEN 922000 AND 922999 OR visitorIP LIKE '198.51.100.%' OR userAgent = '" . AGENT . "') "
    . "+ (SELECT COUNT(*) FROM tblApiRateLimits WHERE bucket LIKE 'totp\\_%:9220__%') + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '" . TRIGGER . "') AS n"
)['n'];
$settingsBack = true;
foreach ($before as $settingKey => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $settingKey]) === $row);
}
ok('clean-up: the made-up accounts, their codes and records and the trigger are gone, and both settings are as they were', $left === 0 && $settingsBack === true, 'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'no'));
ok('clean-up: every server is stopped and the scratch folder is deleted', count(array_filter($allPorts, 'portAnswers')) === 0 && file_exists($child) === false);

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
echo "REACHED THE END — the two-step sign-in self-test ran every part.\n";
exit(0);
