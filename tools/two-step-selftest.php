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
 *   A. The set-up page: it carries a QR picture the portal drew itself,
 *      written into the page, and asks no other website for one (#623);
 *      reading the picture back gives the address an authenticator app
 *      needs, and the key in it is the key shown underneath in groups of
 *      four; the key is in no web address, no log and no other part of the
 *      page; and the browser is told not to keep the page.
 *      Switching on: a wrong code changes nothing and says so; so does the
 *      RIGHT code on a form without the page's own token; the right one
 *      answers with eight codes; the database holds only fingerprints; the
 *      page is not shown a second time, whatever is in the address; and no
 *      code and no key is left in readable form in the activity log, in a
 *      session or in PHP's log.
 *   B. Nobody else is shown them: another account, a visitor who is not
 *      signed in, the same person in a second browser, a form that names
 *      somebody else's account, and a browser that has given the password
 *      but not the code. (And with a long organisation name the picture
 *      still reads back: the address is then over 240 bytes.)
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
 *   K. What a sign-in keeps of the session before it (#900). A different
 *      person — by password, Microsoft, Google or an invitation, on a browser
 *      where nobody, somebody else, or somebody waiting for their code was —
 *      gets NONE of the waiting entries except the browser's language; the
 *      same person (finishing their code step, or signing in again with
 *      Microsoft as the "Add a password" page asks) keeps ALL of theirs; the
 *      proof of a fresh sign-in (#731) survives the sign-in that records it;
 *      and five planted faults are each caught. The Microsoft and Google
 *      sign-ins are driven through the real callbacks with the provider
 *      stubbed in the SCRATCH copy only (part K's own header says exactly how
 *      and what that cannot prove). Since #902 the PASSKEY sign-in is driven
 *      too, with nothing stubbed: the test makes a real P-256 key, registers
 *      it as the portal stores one, and signs the portal's own challenge, so
 *      `WebAuthn::verifyAuthentication()` checks a genuine signature (a
 *      tampered one is refused, which is what proves that). Also since #902:
 *      a kiosk browser (`kiosk_user_id`, the Asset Tracker hand-over) counts
 *      as NOBODY, so a real sign-in on it starts clean — with the kiosk
 *      member and the person signing in deliberately the SAME person, the
 *      one fixture in which "counts as nobody" and "counts as that member"
 *      give different answers; and an invitation whose sign-in fails AFTER
 *      the account is made says so, rather than "that username is taken".
 *      Four more plants, each caught.
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
 *   of its own), and a third for one check (`auth.totpIssuer`, the name an
 *   authenticator app shows), and puts each back exactly. For part I it adds one trigger,
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
 * - A real authenticator app, or a camera scanning the picture on the
 *   set-up page. The picture IS read back here, by the test-only reader in
 *   `tools/qr-test-reader.php` (proved on another program's pictures by
 *   `tools/qr-selftest.php`), from the drawing commands in the page. That
 *   proves the picture is a true QR code holding the right address. It does
 *   not prove that any one app accepts it. The codes here are worked out by
 *   the portal's own class from the key read off the page.
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
 * @version   1.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/613
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/623
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 127;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

// The test-only QR reader (#623). It reads the picture on the set-up page back.
require __DIR__ . DIRECTORY_SEPARATOR . 'qr-test-reader.php';

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
    'sam'   => 922029, // K: "A", signed in, with entries waiting in her session
    'tia'   => 922030, // K: "B", who signs in next on A's browser
    'uma'   => 922031, // K: "C", with two-step sign-in on
];
/** Part K's invitation: the account it makes is a NEW person, numbered by the database, so it is found by these. */
const INVITEE_EMAIL = 'invitee.twostep@selftest.invalid';
const INVITEE_LOGIN = 'tss-invitee';
/** #902: two more invitations, whose sign-in is made to fail after the account is made. */
const INVITEES_902 = [
    ['invitee.p902a.twostep@selftest.invalid', 'tss-invitee-p902a'],
    ['invitee.p902b.twostep@selftest.invalid', 'tss-invitee-p902b'],
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
    // Part K: the Microsoft and Google links its sign-ins make, and the invitation and the account it made.
    q('DELETE FROM tblLinkedAccounts WHERE userID BETWEEN 922000 AND 922999');
    foreach (array_merge([[INVITEE_EMAIL, INVITEE_LOGIN]], INVITEES_902) as [$inviteeEmail, $inviteeLogin]) {
        q('DELETE FROM tblInvitation WHERE email = ?', 's', [$inviteeEmail]);
        foreach (q('SELECT userID FROM tblUsers WHERE emailAddress = ?', 's', [$inviteeEmail]) as $invitee) {
            $inviteeId = (int) $invitee['userID'];
            foreach (['tblActivityLogs', 'tblErrors', 'tblLinkedAccounts', 'tblLocalAccounts', 'tblUserSites', 'tblUsers'] as $table) {
                q('DELETE FROM ' . $table . ' WHERE userID = ?', 'i', [$inviteeId]);
            }
        }
        q('DELETE FROM tblLocalAccounts WHERE username = ?', 's', [$inviteeLogin]);
    }
    // #902: the test passkeys (they would go with their accounts anyway, by the cascade).
    q('DELETE FROM tblWebAuthnCredentials WHERE userID BETWEEN 922000 AND 922999');
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
 * @param array<string,mixed>|string|null $post A form's fields, or (a string) a
 *                                          JSON body sent as the passkey
 *                                          script on the sign-in page sends it.
 * @param string                   $ip   The visitor's address, sent as a proxy would send it.
 *
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, array|string|null $post = null, string $ip = ''): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Expect:', 'User-Agent: ' . AGENT];
    if ($ip !== '') {
        $headers[] = 'X-Forwarded-For: ' . $ip;
    }
    if (is_string($post) === true) {
        $headers[] = 'Content-Type: application/json';
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
        curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($post) === true ? $post : http_build_query($post ?? []));
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

/**
 * The key for the authenticator app drawn on the set-up page (32 characters,
 * A-Z and 2-7), or ''. Since #623 the page shows it in eight groups of four,
 * each group in an element of its own, with no space character between them.
 * A key drawn any other way is "no key", so every check that needs one fails.
 */
function keyOn(string $html): string
{
    return preg_match('#<code[^>]*\bid="totpKey"[^>]*>((?:<span class="mx-1">[A-Z2-7]{4}</span><wbr>){8})</code>#', $html, $m) === 1 ? (string) preg_replace('#<[^>]+>#', '', $m[1]) : '';
}

/**
 * The pictures on a page: every `<img>`, with where each comes from.
 *
 * @return array{written:list<string>, own:list<string>, outside:list<string>}
 *         written  pictures written into the page itself ("data:" addresses)
 *         own      addresses on this server (they begin with one "/")
 *         outside  anything else: another website
 */
function picturesOn(string $html): array
{
    $found = ['written' => [], 'own' => [], 'outside' => []];
    preg_match_all('#<img\b[^>]*>#', $html, $tags);
    foreach ($tags[0] as $tag) {
        $src = preg_match('#\bsrc="([^"]*)"#', $tag, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
        if (str_starts_with($src, 'data:') === true) {
            $found['written'][] = $src;
        } elseif (str_starts_with($src, '/') === true && str_starts_with($src, '//') === false) {
            $found['own'][] = $src;
        } else {
            $found['outside'][] = $src;
        }
    }

    return $found;
}

/**
 * Read the QR picture written into a set-up page back, with the test-only
 * reader. It insists on the clear border of four squares.
 *
 * @return array{text:string, why:string, svg:string} The text in the picture
 *         ('' when it could not be read, with the reason in `why`), and the
 *         picture's own SVG text.
 */
function pictureRead(string $html): array
{
    $written = picturesOn($html)['written'];
    if (count($written) !== 1 || str_starts_with($written[0], 'data:image/svg+xml;base64,') === false) {
        return ['text' => '', 'why' => count($written) . ' picture(s) written into the page, or not an SVG', 'svg' => ''];
    }
    $svg = base64_decode(substr($written[0], strlen('data:image/svg+xml;base64,')), true);
    if ($svg === false) {
        return ['text' => '', 'why' => 'the picture is not base64', 'svg' => ''];
    }
    try {
        return ['text' => QrTestReader::readPicture(['mime' => 'image/svg+xml', 'bytes' => $svg])['text'], 'why' => '', 'svg' => $svg];
    } catch (\Throwable $e) {
        return ['text' => '', 'why' => $e->getMessage(), 'svg' => $svg];
    }
}

/** The parts of an authenticator address: its label (decoded) and what it asks for. */
function addressParts(string $address): array
{
    if (str_starts_with($address, 'otpauth://totp/') === false) {
        return ['label' => '', 'asks' => []];
    }
    $rest  = substr($address, strlen('otpauth://totp/'));
    $label = rawurldecode((string) strstr($rest . '?', '?', true));
    parse_str((string) substr((string) strstr($rest, '?'), 1), $asks);

    return ['label' => $label, 'asks' => $asks];
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
 *
 * "session.serialize_handler=php" is SET rather than assumed, because part K
 * reads and writes the session FILES and must know their format; and stored
 * compiled pages are switched off because part K plants faults into a file
 * and must be served the changed one.
 */
$startPortal = static function (int $onPort) use ($child, $sep, $quiet, $env): mixed {
    return proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'session.serialize_handler=php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
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

// 🔳 The picture for the authenticator app (#623). The page used to ask
//    another website to draw it, which the page's own rule refuses (and
//    which would have sent the key to that website). It must now be drawn by
//    the portal and written into the page.
$pictures = picturesOn($setup['body']);
$rule     = preg_match('/^Content-Security-Policy:.*$/mi', $setup['head'], $m) === 1 ? $m[0] : '';
// A picture asked of another website that has anything to do with a QR code
// or with the key. (Until #778 the page's top bar asked gravatar.com for the
// person's own photograph. Since #778 it does so only where the person and
// every organisation they belong to allow it — off by default — and that is
// tools/outside-services-selftest.php's business, not this test's. Whatever
// else is asked of another website is left alone here.)
$qrElsewhere = array_values(array_filter($pictures['outside'], static fn (string $u): bool => preg_match('#qr|chart|otpauth|secret#i', $u) === 1 || ($key !== '' && stripos($u, $key) !== false)));
ok('A2b the set-up page carries ONE picture written into the page itself, asks no other website for a QR picture, and its own rule still refuses pictures from other websites (so one could not appear anyway)',
    count($pictures['written']) === 1 && str_starts_with((string) ($pictures['written'][0] ?? ''), 'data:image/svg+xml;base64,') === true && $qrElsewhere === []
    && preg_match('#googleapis\.com/chart|chart\.googleapis|qrserver|[?&](chl|cht)=#i', $setup['body']) !== 1 && preg_match('#(src|href|data)="[^"]*/qr\?#', $setup['body']) !== 1
    && str_contains($rule, "img-src 'self' data:;") === true,
    'written into the page: ' . count($pictures['written']) . '; QR pictures asked of another website: ' . implode(' ', array_map(static fn (string $u): string => mb_substr($u, 0, 60), $qrElsewhere)) . '; rule: ' . (preg_match("/img-src[^;]*;/", $rule, $m) === 1 ? $m[0] : '(none)'));
$picture = pictureRead($setup['body']);
$parts   = addressParts($picture['text']);
ok('A2c reading the picture back gives an authenticator address whose key is the key the page shows in words, for this person\'s own e-mail address, six digits every thirty seconds',
    $key !== '' && ($parts['asks']['secret'] ?? '') === $key && str_ends_with($parts['label'], ':anna.twostep@selftest.invalid') === true && ($parts['asks']['issuer'] ?? '') !== ''
    && str_starts_with($parts['label'], (string) ($parts['asks']['issuer'] ?? '?') . ':') === true && ($parts['asks']['digits'] ?? '') === '6' && ($parts['asks']['period'] ?? '') === '30' && ($parts['asks']['algorithm'] ?? '') === 'SHA1',
    $picture['why'] !== '' ? 'the picture could not be read: ' . $picture['why'] : 'key in the picture ' . ((($parts['asks']['secret'] ?? '') === $key) ? 'the same' : 'DIFFERENT or missing') . '; label ends with the e-mail address: ' . (str_ends_with($parts['label'], ':anna.twostep@selftest.invalid') === true ? 'yes' : 'no'));
// Everything on the page EXCEPT the picture and the key's own box. The key
// must be in neither: not in the alt text, not in a hidden field, not in a
// link. (The box is found by keyOn(), which insists on eight groups of four.)
// The key is looked for in ANY shape: run together, or its groups of four
// parted by spaces, dashes or tags. The whole page is the control: there the
// same search must find it, in its box.
$rest     = (string) preg_replace('#<code[^>]*\bid="totpKey"[^>]*>.*?</code>#s', '', str_replace((string) ($pictures['written'][0] ?? '(none)'), '', $setup['body']));
$anyShape = '#' . implode('(?:<[^>]*>|[^A-Za-z0-9<])*', str_split($key === '' ? 'no key was found' : $key, 4)) . '#i';
$alt      = preg_match('#<img\b[^>]*\bsrc="data:[^>]*\balt="([^"]*)"#', $setup['body'], $m) === 1 ? $m[1] : '(none)';
ok('A2d the key is shown under the picture in eight groups of four and nowhere else on the page: not in the picture\'s alt text, not in its SVG as words, not in any link, form or hidden field; and the page says typing it is the other way',
    $key !== '' && strlen($rest) < strlen($setup['body']) && preg_match($anyShape, $setup['body']) === 1 && preg_match($anyShape, $rest) !== 1 && stripos($rest, 'otpauth') === false
    && $alt === 'QR code to scan with your authenticator app' && $picture['svg'] !== '' && preg_match($anyShape, $picture['svg']) !== 1 && stripos($picture['svg'], 'otpauth') === false
    && str_contains($setup['body'], 'Cannot scan it? Type this key into the app instead:') === true,
    'alt text: "' . $alt . '"; key elsewhere on the page: ' . (preg_match($anyShape, $rest) === 1 ? 'YES' : 'no') . '; control, key found in its box: ' . (preg_match($anyShape, $setup['body']) === 1 ? 'yes' : 'NO'));
// Nowhere on the server either. Activity records keep a copy of the session,
// error records keep the web address that was asked for, and the session
// keeps the key itself (encrypted). "otpauth" is looked for as well as the
// key, in case the address were ever kept in a form that hides the key.
// The control: this browser's session DOES hold the key under its name, so
// the right places are being looked in. (A2d already proved that nothing on
// the page builds a web address with the key in it. This test's own server
// keeps no list of the addresses it is asked for, so that cannot be read.)
$left     = leftReadable([$key, 'otpauth']);
$inErrors = (int) one("SELECT COUNT(*) AS n FROM tblErrors WHERE (userID BETWEEN 922000 AND 922999 OR visitorIP LIKE '198.51.100.%') AND (CONCAT_WS('\n', errorTitle, errorDetail, requestURL, requestHeaders) LIKE ? OR CONCAT_WS('\n', errorTitle, errorDetail, requestURL, requestHeaders) LIKE '%otpauth%')", 's', ['%' . $key . '%'])['n'];
$session  = sessionFile($annaJar) === '' ? '' : (string) file_get_contents(sessionFile($annaJar));
ok('A2e the key and the authenticator address are in no activity record, no error record (those keep the web address asked for), no log, and in the session only in a form that cannot be read',
    $key !== '' && $left === [] && $inErrors === 0 && str_contains($session, 'totp_setup_secret|') === true && stripos($session, $key) === false,
    'left in: ' . implode('; ', array_slice($left, 0, 5)) . '; error records: ' . $inErrors . '; control (her session holds the key under its name): ' . (str_contains($session, 'totp_setup_secret|') === true ? 'yes' : 'NO'));
ok('A2f the set-up page tells the browser not to keep it (said by the page itself: this server adds no such header of its own)', preg_match('/^Cache-Control:.*\bno-store\b/mi', $setup['head']) === 1, trim((string) (preg_match('/^Cache-Control:.*$/mi', $setup['head'], $cc) === 1 ? $cc[0] : 'no Cache-Control header')));

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

// A13 IS THE CONTROL FOR A14 AND A15. Those two say that no backup code and
// no authenticator key is left readable in an activity record. "Nothing
// found" would also be the answer if records held nothing about the session
// at all, so this check says what a record DOES hold about it, and names the
// person. If this fails, A14 and A15 prove nothing.
//
// WHAT CHANGED, AND WHY (27 October 2026, #622). It used to look for
// `"user_id":<her number>`, because a record carried a copy of EVERYTHING in
// the session — which is how every set of backup codes came to be in the log
// in readable text. #622 stopped that: a record now carries a FINGERPRINT of
// the session (never its identifier, which is the sign-in cookie) and a short
// list of named facts, and nothing else. So the control now asserts that new
// truth: exactly the six facts, her own account number under `userID`, and an
// identifier column that holds a fingerprint rather than a session's name.
// It still fails if the facts stop being written at all — an empty, missing
// or differently shaped copy fails the test below — and it fails if a whole
// copy of the session comes back, because then there are more than six names.
$enabledRow = one("SELECT sessionID, sessionDataSnapshot FROM tblActivityLogs WHERE userID = ? AND activityType = 'TotpEnabled'", 'i', [PEOPLE['anna']]);
$enabledFacts = json_decode((string) ($enabledRow['sessionDataSnapshot'] ?? ''), true);
$factNames    = ['format', 'signedIn', 'userID', 'organisationID', 'waitingForCodeUserID', 'kioskUserID'];
ok('A13 control: switching on was recorded once, a record was written while the set-up page was open, and the record says whose session it was — the six named facts with her own account number, and a fingerprint in place of the session\'s identifier',
    records('anna', 'TotpEnabled') === 1 && $control === 1
        && is_array($enabledFacts) === true && array_keys($enabledFacts) === $factNames && $enabledFacts['format'] === 'facts-1'
        && $enabledFacts['signedIn'] === true && $enabledFacts['userID'] === PEOPLE['anna']
        && preg_match('/^sha256:[0-9a-f]{64}$/', (string) ($enabledRow['sessionID'] ?? '')) === 1,
    'TotpEnabled: ' . records('anna', 'TotpEnabled') . '; written while set-up was open: ' . $control
        . '; the names in the copy: ' . json_encode(is_array($enabledFacts) === true ? array_keys($enabledFacts) : $enabledRow['sessionDataSnapshot'])
        . '; the account named in it: ' . json_encode(is_array($enabledFacts) === true ? ($enabledFacts['userID'] ?? null) : null)
        . '; the identifier column is a fingerprint: ' . (preg_match('/^sha256:[0-9a-f]{64}$/', (string) ($enabledRow['sessionID'] ?? '')) === 1 ? 'yes' : 'NO'));
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
// An organisation with a long name: the address in the picture is then well
// over the 213 bytes at which the old drawing code stopped with an error.
$nameBefore = one("SELECT settingValue FROM tblSettings WHERE settingKey = 'auth.totpIssuer' AND siteID IS NULL");
$longName   = 'The Parish Church of Saint Mary the Virgin and All Saints, Little Wittenham-on-Thames';
setting('auth.totpIssuer', $longName);
$benLong  = req($benJar, 'GET', '/auth/2fa/setup', null, ip(21));
$longRead = pictureRead($benLong['body']);
$longAsks = addressParts($longRead['text']);
if ($nameBefore !== null) {
    setting('auth.totpIssuer', (string) $nameBefore['settingValue']); // back at once; the clean-up puts back the exact row as well
}
ok('B1b with a long organisation name the picture still reads back: an address of ' . strlen($longRead['text']) . ' bytes (over 240), the same key as the page shows, the long name whole',
    $benLong['status'] === 200 && strlen($longRead['text']) > 240 && keyOn($benLong['body']) !== '' && ($longAsks['asks']['secret'] ?? '') === keyOn($benLong['body']) && keyOn($benLong['body']) === keyOn($benPage['body'])
    && ($longAsks['asks']['issuer'] ?? '') === $longName && $longAsks['label'] === $longName . ':ben.twostep@selftest.invalid',
    $longRead['why'] !== '' ? 'the picture could not be read: ' . $longRead['why'] : strlen($longRead['text']) . ' bytes; issuer read: ' . mb_substr((string) ($longAsks['asks']['issuer'] ?? '(none)'), 0, 40));
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
// Z. A list sent as the address to come back to does not sign anybody in
// =============================================================================
// WHY THIS IS HERE. Until 27 October 2026 all four two-step gates worked out
// where to send the person BEFORE they took the sign-in away, and
// Auth::safeRedirectUrl() was declared `string $url`. A request chooses the
// TYPE of what it sends as well as the value, so `?redirect[]=x` handed PHP an
// array, the call threw, the unset() never ran, and PHP wrote the session at
// shutdown - leaving somebody fully signed in who was never asked for a code.
// On this path that needed nothing but the person's password, which is the one
// thing two-step sign-in exists to survive. Found by the Codex review of the
// sign-in files, and reproduced live on the old code: the browser really was
// signed in.
//
// Z1 and Z2 fail if the gates go back to the old order AND safeRedirectUrl() is
// narrowed to `string` again. They do NOT fail if only the ordering is reversed,
// because the `mixed` signature stops the throw - that half is held by
// tools/audit-checks/check_2fa_gate_order.py instead. Both are needed, and this
// comment is here so nobody removes one believing the other covers it.
// Same family as C11 above ("a list sent where a code is expected").
head('Z. A list sent as the address to come back to does not sign anybody in');

$zJar  = newJar();
$zForm = req($zJar, 'GET', '/login', null, ip(90));
$zIn   = req($zJar, 'POST', '/login?redirect%5B%5D=x', ['csrf_token' => token($zForm['body']), 'username' => login('anna'), 'password' => $password], ip(90));
ok('Z1 the password form posted to /login?redirect[]=x leaves nobody signed in', signedIn($zJar, ip(90)) === false, describe($zIn));
ok('Z2 and it still sends the person to the code page', $zIn['status'] === 302 && $zIn['location'] === '/auth/2fa/verify', describe($zIn));

$yJar  = newJar();
$yForm = req($yJar, 'GET', '/login', null, ip(91));
$yIn   = req($yJar, 'POST', '/login?redirect=%2Faccount', ['csrf_token' => token($yForm['body']), 'username' => login('anna'), 'password' => $password], ip(91));
$yDone = codeStep($yJar, ip(91), ['code' => appCode($key)]);
ok('Z3 control: an ordinary ?redirect= still carries the person there after the code', $yIn['location'] === '/auth/2fa/verify' && $yDone['status'] === 302 && $yDone['location'] === '/account' && signedIn($yJar, ip(91)) === true, describe($yIn) . ' then ' . describe($yDone));

// =============================================================================
head('K. A sign-in keeps nothing of somebody else\'s session, and all of the same person\'s (#900)');
// =============================================================================
// WHAT WAS WRONG. Every sign-in path replaced the session's IDENTIFIER and kept
// every ENTRY in it, so a message, kept typing or anything else waiting for one
// person was handed to whoever signed in next on that browser. Found by editing
// a session file (#900), which is also how these checks put "the previous
// person's" entries in place: `putInSession()` appends real entries in PHP's own
// session format and proves they landed before anything is pressed.
//
// HOW THE MICROSOFT AND GOOGLE SIGN-INS ARE DRIVEN, said plainly because it is
// the one artificial thing here. No test can reach Microsoft or Google, so the
// SCRATCH copy of Auth.php (never the repository's) has three test stubs written
// into it, each required to apply exactly where expected: the token request
// answers with the "code" it was sent when that code starts with STUB, the key
// list is not fetched for such a token, and such a token is read as the claims
// rather than checked as a signature. Everything else in the callbacks is the
// real code: the state check, finding the account by e-mail, linking it, the
// grant, the proof, the two-factor gate and the redirect. What this does NOT
// prove is anything about a real provider's answer or a real signature check.
//
// THE PASSKEY SIGN-IN IS DRIVEN FOR REAL (#902), and needs no stub. A passkey
// is a key pair: the portal stores the public half and checks a signature over
// its own challenge. PHP's openssl makes a P-256 key pair as an authenticator
// does, so this test registers the public half exactly as the portal stores one
// (a COSE key, base64url) and signs what an authenticator signs. What that does
// NOT prove: anything about a real authenticator, a browser's own checks, or
// attestation at registration — only that the portal's sign-in takes a genuine
// assertion and what it then does with the session. Until #902 nothing drove
// it, and the independent check on #900 put webauthn.php back to a bare
// session_regenerate_id() with this file still at 117 of 117.

const STUB = 'p900stub:';

/**
 * Every entry in a browser's session file, by name, as PHP's 'php' format holds
 * them (name|value, one after another). Null when it cannot be read — every
 * caller treats that as a failure, never as "nothing there".
 *
 * @return array<string,mixed>|null
 */
function sessionEntries(string $jar): ?array
{
    $file = sessionFile($jar);
    if ($file === '') {
        return null;
    }
    clearstatcache(true, $file);
    $body = (string) file_get_contents($file);
    $out  = [];
    $at   = 0;
    while ($at < strlen($body)) {
        $bar = strpos($body, '|', $at);
        if ($bar === false) {
            return null;
        }
        $rest  = substr($body, $bar + 1);
        $value = @unserialize($rest, ['allowed_classes' => false]);
        if ($value === false && str_starts_with($rest, 'b:0;') === false) {
            return null;
        }
        $out[substr($body, $at, $bar - $at)] = $value;
        $at = $bar + 1 + strlen(serialize($value));
    }

    return $out;
}

/** The session identifier this browser holds now ('' when none). */
function sessionIdOf(string $jar): string
{
    $file = sessionFile($jar);

    return $file === '' ? '' : substr(basename($file), 5);
}

/**
 * Put entries into a browser's session, as a page would have left them, and
 * REFUSE unless every one reads back: an edit that did not land would make the
 * "it is gone" checks pass for the wrong reason.
 *
 * @param array<string,mixed> $entries
 */
function putInSession(string $jar, array $entries): void
{
    $file = sessionFile($jar);
    if ($file === '') {
        refuse('a browser has no session file, so the previous person\'s entries could not be put in place');
    }
    $add = '';
    foreach ($entries as $name => $value) {
        $add .= $name . '|' . serialize($value);
    }
    file_put_contents($file, $add, FILE_APPEND);
    $now = sessionEntries($jar);
    foreach ($entries as $name => $value) {
        if ($now === null || array_key_exists($name, $now) === false || $now[$name] !== $value) {
            refuse("the entry '{$name}' did not land in the session file, so nothing below would mean anything");
        }
    }
}

/** Which of `$entries` are still in the session with the same value. @return list<string> */
function stillThere(?array $now, array $entries): array
{
    $kept = [];
    foreach ($entries as $name => $value) {
        if ($now !== null && array_key_exists($name, $now) === true && $now[$name] === $value) {
            $kept[] = (string) $name;
        }
    }

    return $kept;
}

/** The password step in a browser that already exists (passwordStep() always makes a new one). */
function passwordIn(string $jar, string $who, string $ip, string $query = ''): array
{
    global $password;
    $form = req($jar, 'GET', '/login', null, $ip);

    return req($jar, 'POST', '/login' . $query, ['csrf_token' => token($form['body']), 'username' => login($who), 'password' => $password], $ip);
}

/** A person's e-mail address in the made-up world. */
function emailOf(string $who): string
{
    return $who . '.twostep@selftest.invalid';
}

/**
 * A Microsoft ('ms365') or Google ('google') sign-in as `$who`, in an existing
 * browser: the real start address (which stores the state), then the real
 * callback carrying that state and a stub code naming the person.
 *
 * @return array{start:array, state:string, back:array}
 */
function ssoIn(string $jar, string $provider, string $who, string $ip): array
{
    $start = req($jar, 'GET', '/login/' . $provider, null, $ip);
    $state = preg_match('/[?&]state=([0-9a-f]{32})(?:&|$)/', $start['location'], $m) === 1 ? $m[1] : '';
    $claims = $provider === 'ms365'
        ? ['sub' => 'p900-ms-' . $who, 'preferred_username' => emailOf($who), 'name' => 'Selftest two-step ' . $who]
        : ['sub' => 'p900-g-' . $who, 'email' => emailOf($who), 'name' => 'Selftest two-step ' . $who];
    $back = req($jar, 'GET', '/login/' . $provider . '/callback?' . http_build_query(['state' => $state, 'code' => STUB . json_encode($claims)]), null, $ip);

    return ['start' => $start, 'state' => $state, 'back' => $back];
}

/** The proof of a fresh sign-in (#731) in a session, as "userID:method", or '' when there is none. */
function proofIn(?array $entries): string
{
    $proof = $entries['reauth_proof'] ?? null;

    return is_array($proof) === true ? (string) ($proof['userID'] ?? '') . ':' . (string) ($proof['method'] ?? '') : '';
}

// 🧪 The stubs, in the SCRATCH copy only, each required to land exactly where meant.
$scratchAuth  = $child . $sep . 'web' . $sep . '_core' . $sep . 'Auth.php';
$authAsCopied = (string) file_get_contents($scratchAuth);
$stubbed      = $authAsCopied;
foreach ([
    "    public static function curlPost(string \$url, array \$data): ?string\n    {\n" => [1, "    public static function curlPost(string \$url, array \$data): ?string\n    {\n"
        . "        // TEST STUB written by tools/two-step-selftest.php into its scratch copy (#900).\n"
        . "        if (is_string(\$data['code'] ?? null) === true && str_starts_with(\$data['code'], '" . STUB . "') === true) {\n"
        . "            return json_encode(['id_token' => substr(\$data['code'], " . strlen(STUB) . ")]);\n"
        . "        }\n"],
    '$jwksJson = file_get_contents($jwksUri);' => [2, '$jwksJson = str_starts_with($idToken, \'{\') ? \'{}\' : file_get_contents($jwksUri);'],
    '$payload = SimpleJWT::decode($idToken, $jwks, [' => [2, '$payload = str_starts_with($idToken, \'{\') ? json_decode($idToken, true) : SimpleJWT::decode($idToken, $jwks, ['],
] as $from => [$times, $to]) {
    $count   = 0;
    $stubbed = str_replace($from, $to, $stubbed, $count);
    if ($count !== $times) {
        refuse("a provider stub matched {$count} place(s) in the scratch Auth.php, not {$times}: '" . mb_substr($from, 0, 60) . "'");
    }
}
file_put_contents($scratchAuth, $stubbed);
clearstatcache(true, $scratchAuth);
if ((string) file_get_contents($scratchAuth) !== $stubbed) {
    refuse('the provider stubs could not be written into the scratch copy');
}
setting('auth.ms365.enduser.clientID', 'p900-client');
setting('auth.ms365.enduser.clientSecret', 'p900-secret');
setting('auth.ms365.enduser.redirectURI', 'http://127.0.0.1:' . $port . '/login/ms365/callback');
setting('auth.ms365.tenantID', 'p900-tenant');
setting('auth.google.clientID', 'p900-client');
setting('auth.google.clientSecret', 'p900-secret');
setting('auth.google.redirectURI', 'http://127.0.0.1:' . $port . '/login/google/callback');
setting('auth.google.hostedDomain', '');

/**
 * What "the previous person" left waiting. A fresh marker every time, so an
 * entry left over from one scenario can never satisfy another. `portal_locale`
 * is the one entry a different person is MEANT to keep, so it is separate.
 *
 * @return array<string,mixed>
 */
function theirs(): array
{
    return [
        'flash_msg'        => 'P900 waiting for the previous person ' . bin2hex(random_bytes(4)),
        'flash_type'       => 'danger',
        'p900_kept_typing' => 'typed by the previous person ' . bin2hex(random_bytes(4)),
    ];
}
/** What a session may hold straight after a different person's password sign-in, and nothing else. */
const AFTER_A_FRESH_SIGN_IN = ['portal_locale', 'user_id', 'user_name', 'user_email', 'active_site_id', 'reauth_proof'];

// --- K1. Nobody signed in, a visitor's entries waiting; B signs in by password.
$kJar  = newJar();
req($kJar, 'GET', '/login', null, ip(101));
$waiting = theirs() + ['login_redirect' => '/p900-a-page-the-visitor-wanted'];
putInSession($kJar, $waiting + ['portal_locale' => 'cy']);
$kIdBefore = sessionIdOf($kJar);
$kIn       = passwordIn($kJar, 'tia', ip(101));
$kNow      = sessionEntries($kJar);
ok('K01 a password sign-in on a browser nobody was signed in on keeps NONE of the visitor\'s waiting entries (message, typing, come-back address)',
    $kIn['status'] === 302 && $kIn['location'] === '/' && (int) ($kNow['user_id'] ?? 0) === PEOPLE['tia'] && stillThere($kNow, $waiting) === [],
    describe($kIn) . '; still there: ' . implode(', ', stillThere($kNow, $waiting)));
$extra = $kNow === null ? ['(unreadable)'] : array_values(array_diff(array_keys($kNow), AFTER_A_FRESH_SIGN_IN));
ok('K02 ...it keeps the browser\'s chosen language and NOTHING ELSE the sign-in did not write itself',
    ($kNow['portal_locale'] ?? null) === 'cy' && $extra === [],
    'language: ' . var_export($kNow['portal_locale'] ?? null, true) . '; other entries: ' . implode(', ', $extra));
ok('K03 ...the proof of a fresh sign-in (#731) survives the very sign-in that records it, and names B',
    proofIn($kNow) === PEOPLE['tia'] . ':password', 'proof: ' . proofIn($kNow));
$kPage = req($kJar, 'GET', '/account/add-password', null, ip(101));
ok('K04 ...B\'s own page does not show the waiting message, and the session identifier was replaced',
    $kPage['status'] === 200 && str_contains($kPage['body'], (string) $waiting['flash_msg']) === false && $kIdBefore !== '' && sessionIdOf($kJar) !== $kIdBefore,
    describe($kPage) . '; identifier ' . ($kIdBefore === sessionIdOf($kJar) ? 'NOT replaced' : 'replaced'));

// --- K2. C has given her password and is waiting for her code; B signs in by password on that browser.
$uma = switchOn('uma', ip(102));
if ($uma['key'] === '' || count($uma['codes']) !== 8) {
    refuse('two-step sign-in could not be switched on for part K\'s account (' . describe($uma['answer']) . ')');
}
[$k2Jar, $k2Pending] = passwordStep('uma', ip(103));
$waiting = theirs();
putInSession($k2Jar, $waiting);
$k2In  = passwordIn($k2Jar, 'tia', ip(103));
$k2Now = sessionEntries($k2Jar);
ok('K05 B signing in by password on a browser where C is waiting for her code gets none of C\'s entries, and is not left holding C\'s waiting state',
    $k2Pending['location'] === '/auth/2fa/verify' && $k2In['status'] === 302 && $k2In['location'] === '/'
        && (int) ($k2Now['user_id'] ?? 0) === PEOPLE['tia'] && array_key_exists('2fa_user_id', (array) $k2Now) === false && stillThere($k2Now, $waiting) === [],
    describe($k2Pending) . ' then ' . describe($k2In) . '; still there: ' . implode(', ', stillThere($k2Now, $waiting)) . (array_key_exists('2fa_user_id', (array) $k2Now) === true ? '; 2fa_user_id LEFT' : ''));

// --- K3. C finishes her own two-step sign-in: the same person keeps everything of hers.
/** Run K3's journey (also used by the plants). @return array{done:array, now:?array, idBefore:string, waiting:array} */
$k3Journey = static function (string $ip) use ($uma): array {
    $jar  = newJar();
    $form = req($jar, 'GET', '/login', null, $ip);
    req($jar, 'POST', '/login?redirect=%2Faccount%2Fadd-password', ['csrf_token' => token($form['body']), 'username' => login('uma'), 'password' => $GLOBALS['password']], $ip);
    // The code page is opened FIRST and the entries put in AFTER it: the page
    // shows and removes a waiting message when it is drawn, which is right, and
    // would otherwise make "her own message is kept" fail for a reason that has
    // nothing to do with the sign-in.
    $page    = req($jar, 'GET', '/auth/2fa/verify', null, $ip);
    $waiting = theirs();
    putInSession($jar, $waiting);
    $idBefore = sessionIdOf($jar);
    $done     = req($jar, 'POST', '/auth/2fa/verify', ['csrf_token' => token($page['body']), 'code' => appCode($uma['key'])], $ip);

    return ['jar' => $jar, 'done' => $done, 'now' => sessionEntries($jar), 'idBefore' => $idBefore, 'waiting' => $waiting];
};
$k3 = $k3Journey(ip(104));
ok('K06 two-step sign-in still works, and the come-back address C asked for survives her own code step',
    $k3['done']['status'] === 302 && $k3['done']['location'] === '/account/add-password' && (int) ($k3['now']['user_id'] ?? 0) === PEOPLE['uma'],
    describe($k3['done']));
ok('K07 ...every entry of C\'s own that was waiting is still there after her code step',
    count(stillThere($k3['now'], $k3['waiting'])) === count($k3['waiting']),
    'still there: ' . implode(', ', stillThere($k3['now'], $k3['waiting'])));
ok('K08 ...her proof of a fresh sign-in, recorded at the password step, survives the code step, and the identifier is replaced at it',
    proofIn($k3['now']) === PEOPLE['uma'] . ':password' && $k3['idBefore'] !== '' && sessionIdOf($k3['jar']) !== $k3['idBefore'],
    'proof: ' . proofIn($k3['now']) . '; identifier ' . (sessionIdOf($k3['jar']) === $k3['idBefore'] ? 'NOT replaced' : 'replaced'));
$k3Page = req($k3['jar'], 'GET', '/account/add-password', null, ip(104));
ok('K09 ...and her own waiting message is shown to her',
    $k3Page['status'] === 200 && str_contains($k3Page['body'], (string) $k3['waiting']['flash_msg']) === true, describe($k3Page));

// --- K4. A is signed in; B signs in with Microsoft on that browser.
/** Run K4's journey (also used by the plants). */
$k4Journey = static function (string $provider, string $as, string $ip, string $comeBack): array {
    [$jar]   = passwordStep('sam', $ip);
    $waiting = theirs() + ['login_redirect' => $comeBack, 'user_locale' => 'cy'];
    putInSession($jar, $waiting + ['portal_locale' => 'cy']);
    $idBefore = sessionIdOf($jar);
    $sso      = ssoIn($jar, $provider, $as, $ip);

    return ['jar' => $jar, 'sso' => $sso, 'now' => sessionEntries($jar), 'idBefore' => $idBefore, 'waiting' => $waiting];
};
$k4 = $k4Journey('ms365', 'tia', ip(105), '/p900-sams-page');
ok('K10 /login/ms365 is NOT refused for a browser somebody is signed in on (the "Add a password" page relies on that, #731)',
    $k4['sso']['start']['status'] === 302 && str_starts_with($k4['sso']['start']['location'], 'https://login.microsoftonline.com/') === true && $k4['sso']['state'] !== '',
    describe($k4['sso']['start']));
ok('K11 the Microsoft callback signs B in, and sends B to the front page rather than to the page A was waiting to come back to',
    $k4['sso']['back']['status'] === 302 && $k4['sso']['back']['location'] === '/' && (int) ($k4['now']['user_id'] ?? 0) === PEOPLE['tia'],
    describe($k4['sso']['back']));
ok('K12 ...none of A\'s entries reach B (message, typing, come-back address, A\'s saved language), the browser\'s language is kept, and the proof is B\'s, by Microsoft',
    stillThere($k4['now'], $k4['waiting']) === [] && ($k4['now']['portal_locale'] ?? null) === 'cy' && proofIn($k4['now']) === PEOPLE['tia'] . ':Microsoft',
    'still there: ' . implode(', ', stillThere($k4['now'], $k4['waiting'])) . '; proof: ' . proofIn($k4['now']));
$k4Page = req($k4['jar'], 'GET', '/account/add-password', null, ip(105));
ok('K13 ...and B\'s own page does not show A\'s waiting message — the fault as #900 found it',
    $k4Page['status'] === 200 && str_contains($k4Page['body'], (string) $k4['waiting']['flash_msg']) === false, describe($k4Page));

// --- K5. The same with Google.
$k5 = $k4Journey('google', 'tia', ip(106), '/p900-sams-page');
ok('K14 the Google callback likewise signs B in with none of A\'s entries, and records B\'s proof by Google',
    $k5['sso']['back']['status'] === 302 && $k5['sso']['back']['location'] === '/' && (int) ($k5['now']['user_id'] ?? 0) === PEOPLE['tia']
        && stillThere($k5['now'], $k5['waiting']) === [] && proofIn($k5['now']) === PEOPLE['tia'] . ':Google',
    describe($k5['sso']['back']) . '; still there: ' . implode(', ', stillThere($k5['now'], $k5['waiting'])) . '; proof: ' . proofIn($k5['now']));

// --- K6. A proves herself again with Microsoft, as the "Add a password" page asks (#731): the same person.
$k6 = $k4Journey('ms365', 'sam', ip(107), '/account/add-password');
ok('K15 A signing in again with Microsoft keeps every entry of her own and comes back to the page that asked',
    $k6['sso']['back']['status'] === 302 && $k6['sso']['back']['location'] === '/account/add-password' && (int) ($k6['now']['user_id'] ?? 0) === PEOPLE['sam']
        && count(stillThere($k6['now'], array_diff_key($k6['waiting'], ['login_redirect' => true]))) === count($k6['waiting']) - 1,
    describe($k6['sso']['back']) . '; still there: ' . implode(', ', stillThere($k6['now'], $k6['waiting'])));
ok('K16 ...her proof is renewed as Microsoft\'s, and the identifier is replaced even for the same person',
    proofIn($k6['now']) === PEOPLE['sam'] . ':Microsoft' && $k6['idBefore'] !== '' && sessionIdOf($k6['jar']) !== $k6['idBefore'],
    'proof: ' . proofIn($k6['now']) . '; identifier ' . (sessionIdOf($k6['jar']) === $k6['idBefore'] ? 'NOT replaced' : 'replaced'));

// --- K7. A is signed in; a new person accepts an invitation on that browser.
$inviteToken = bin2hex(random_bytes(32));
q("INSERT INTO tblInvitation (siteID, email, tokenHash, intendedRole, expiresAt, createdByID) VALUES (1, ?, ?, 'user', DATE_ADD(NOW(), INTERVAL 1 DAY), NULL)", 'ss', [INVITEE_EMAIL, hash('sha256', $inviteToken)]);
[$k7Jar] = passwordStep('sam', ip(108));
$waiting = theirs();
putInSession($k7Jar, $waiting);
$k7Form = req($k7Jar, 'GET', '/auth/invite?token=' . $inviteToken, null, ip(108));
$k7In   = req($k7Jar, 'POST', '/auth/invite', ['token' => $inviteToken, 'csrf_token' => token($k7Form['body']), 'fullName' => 'Selftest two-step invitee', 'username' => INVITEE_LOGIN, 'password' => $password, 'passwordConfirm' => $password], ip(108));
$k7Now  = sessionEntries($k7Jar);
$k7Id   = (int) (one('SELECT userID FROM tblUsers WHERE emailAddress = ?', 's', [INVITEE_EMAIL])['userID'] ?? 0);
ok('K17 accepting an invitation on a browser A is signed in on signs the NEW person in with none of A\'s entries',
    $k7Form['status'] === 200 && $k7In['status'] === 302 && $k7Id > 0 && (int) ($k7Now['user_id'] ?? 0) === $k7Id && stillThere($k7Now, $waiting) === [],
    describe($k7Form) . ' then ' . describe($k7In) . '; new account ' . $k7Id . '; still there: ' . implode(', ', stillThere($k7Now, $waiting)));

// --- The plants. Each is written into the SCRATCH Auth.php (already stubbed),
//     proved applied, required to change what one of the journeys above sees,
//     and then taken out again with the file proved byte-identical.
$plantAuth = static function (array $swaps) use ($scratchAuth, $stubbed): void {
    $after = $stubbed;
    foreach ($swaps as $from => $to) {
        $count = 0;
        $after = str_replace($from, $to, $after, $count);
        if ($count !== 1) {
            refuse("the plant '" . mb_substr($from, 0, 60) . "' matched {$count} place(s), not exactly one");
        }
    }
    file_put_contents($scratchAuth, $after);
    clearstatcache(true, $scratchAuth);
    if ((string) file_get_contents($scratchAuth) !== $after) {
        refuse('a plant did not apply to the scratch Auth.php');
    }
};
$unplant = static function () use ($scratchAuth, $stubbed): void {
    file_put_contents($scratchAuth, $stubbed);
    clearstatcache(true, $scratchAuth);
    if ((string) file_get_contents($scratchAuth) !== $stubbed) {
        refuse('a plant could not be taken out of the scratch Auth.php');
    }
};
$theClearing = 'if (self::sessionPerson() !== $userId) {';
/** K1's journey again, for the plants: nobody signed in, a visitor's entries, B by password. */
$k1Again = static function (string $ip): array {
    $jar = newJar();
    req($jar, 'GET', '/login', null, $ip);
    $waiting = theirs();
    putInSession($jar, $waiting);
    passwordIn($jar, 'tia', $ip);

    return stillThere(sessionEntries($jar), $waiting);
};

$plantAuth([$theClearing => 'if (false) {']);
$p1Password = $k1Again(ip(111));
$p1Sso      = $k4Journey('ms365', 'tia', ip(112), '/p900-sams-page');
$unplant();
ok('K18 PLANT, the clearing removed: the visitor\'s waiting message reaches B by password, so K01 can fail',
    in_array('flash_msg', $p1Password, true) === true, 'still there under the plant: ' . implode(', ', $p1Password));
ok('K19 ...and A\'s waiting message reaches B by Microsoft, so K12 can fail',
    in_array('flash_msg', stillThere($p1Sso['now'], $p1Sso['waiting']), true) === true, 'still there under the plant: ' . implode(', ', stillThere($p1Sso['now'], $p1Sso['waiting'])));

$plantAuth(['array_flip(self::SESSION_KEYS_KEPT_FOR_A_DIFFERENT_PERSON)' => '$_SESSION']);
$p2 = $k1Again(ip(113));
$unplant();
ok('K20 PLANT, the list of what to keep widened to everything: the waiting message and typing reach B, so K01 can fail',
    in_array('flash_msg', $p2, true) === true && in_array('p900_kept_typing', $p2, true) === true, 'still there under the plant: ' . implode(', ', $p2));

$plantAuth([$theClearing => 'if (true) {']);
$p3Code = $k3Journey(ip(114));
$p3Sso  = $k4Journey('ms365', 'sam', ip(115), '/account/add-password');
$unplant();
ok('K21 PLANT, the clearing applied to the SAME person too: C loses her own message, her come-back address and her proof at her code step, so K06-K08 can fail',
    stillThere($p3Code['now'], $p3Code['waiting']) === [] && $p3Code['done']['location'] !== '/account/add-password' && proofIn($p3Code['now']) === '',
    describe($p3Code['done']) . '; still there: ' . implode(', ', stillThere($p3Code['now'], $p3Code['waiting'])) . '; proof: ' . proofIn($p3Code['now']));
ok('K22 ...and A signing in again with Microsoft loses hers and is not brought back, so K15 can fail',
    stillThere($p3Sso['now'], $p3Sso['waiting']) === [] && $p3Sso['sso']['back']['location'] !== '/account/add-password',
    describe($p3Sso['sso']['back']) . '; still there: ' . implode(', ', stillThere($p3Sso['now'], $p3Sso['waiting'])));

$plantAuth(["foreach (['user_id', '2fa_user_id'] as \$key) {" => "foreach (['user_id'] as \$key) {"]);
$p4 = $k3Journey(ip(116));
$unplant();
ok('K23 PLANT, a session waiting for a code counted as nobody\'s: C loses her own entries at her code step, so K07 can fail',
    stillThere($p4['now'], $p4['waiting']) === [] && $p4['done']['location'] !== '/account/add-password',
    describe($p4['done']) . '; still there: ' . implode(', ', stillThere($p4['now'], $p4['waiting'])));

$plantAuth(["on purpose (#731).\n        self::beginSessionFor(\$userId);" => "on purpose (#731).\n        session_regenerate_id(true);"]);
$p5 = $k4Journey('ms365', 'tia', ip(117), '/p900-sams-page');
$unplant();
ok('K24 PLANT, the Microsoft callback put back as it was before #900 (a bare session_regenerate_id): A\'s message reaches B, so K12 and K13 can fail',
    in_array('flash_msg', stillThere($p5['now'], $p5['waiting']), true) === true && (int) ($p5['now']['user_id'] ?? 0) === PEOPLE['tia'],
    'still there under the plant: ' . implode(', ', stillThere($p5['now'], $p5['waiting'])));

// =============================================================================
// K8-K10 (#902): a passkey, a kiosk browser, and an invitation whose sign-in fails.
// =============================================================================

/** Bytes as base64url, as WebAuthn writes them. */
function b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * A passkey for `$who`: a real P-256 key pair, the public half stored in
 * tblWebAuthnCredentials as the portal stores one (a COSE key in CBOR, then
 * base64url). The private half stays in this process and signs.
 *
 * @return array{key:\OpenSSLAsymmetricKey, id:string, count:int}
 */
function passkeyFor(string $who): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
        refuse('this PHP\'s openssl could not make a P-256 key, so no passkey can be made');
    }
    $ec = (array) (openssl_pkey_get_details($key)['ec'] ?? []);
    $x  = str_pad((string) ($ec['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
    $y  = str_pad((string) ($ec['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
    // A CBOR map of five: kty 1 => 2 (EC2), alg 3 => -7 (ES256), crv -1 => 1
    // (P-256), x -2 => 32 bytes, y -3 => 32 bytes.
    $cose = "\xA5" . "\x01\x02" . "\x03\x26" . "\x20\x01" . "\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
    $id   = b64u(random_bytes(16));
    q('INSERT INTO tblWebAuthnCredentials (userID, credentialID, publicKey, signCount, friendlyName) VALUES (?, ?, ?, 0, ?)', 'isss', [PEOPLE[$who], $id, b64u($cose), 'p902 test passkey']);

    return ['key' => $key, 'id' => $id, 'count' => 0];
}

/**
 * A passkey sign-in, as the sign-in page's script makes it: ask for the
 * options (which stores a challenge in the session), sign the challenge, send
 * the assertion. With `$tamper` the signature is over different bytes, so a
 * portal that really checks it must refuse.
 *
 * @param array{key:\OpenSSLAsymmetricKey, id:string, count:int} $pk
 *
 * @return array{rpId:string, verify:array, answer:array}
 */
function passkeyIn(string $jar, array &$pk, string $ip, string $redirect, bool $tamper = false): array
{
    global $port;
    $options   = req($jar, 'POST', '/login/webauthn', (string) json_encode(['action' => 'auth_options']), $ip);
    $decoded   = json_decode($options['body'], true);
    $challenge = (string) ($decoded['options']['challenge'] ?? '');
    $rpId      = (string) ($decoded['options']['rpId'] ?? '');
    $pk['count']++;
    // rpIdHash, flags (user present + user verified), signature counter.
    $authData   = hash('sha256', $rpId, true) . "\x05" . pack('N', $pk['count']);
    $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'http://127.0.0.1:' . $port, 'crossOrigin' => false]);
    $signed     = $authData . hash('sha256', $clientData . ($tamper === true ? 'tampered' : ''), true);
    $signature  = '';
    if (openssl_sign($signed, $signature, $pk['key'], OPENSSL_ALGO_SHA256) === false) {
        refuse('openssl could not sign the passkey assertion');
    }
    $verify = req($jar, 'POST', '/login/webauthn', (string) json_encode([
        'action'     => 'auth_verify',
        'redirect'   => $redirect,
        'credential' => ['id' => $pk['id'], 'rawId' => $pk['id'], 'type' => 'public-key', 'response' => [
            'clientDataJSON' => b64u($clientData), 'authenticatorData' => b64u($authData), 'signature' => b64u($signature), 'userHandle' => null,
        ]],
    ]), $ip);

    return ['rpId' => $rpId, 'verify' => $verify, 'answer' => (array) (json_decode($verify['body'], true) ?? [])];
}

/** Plant into any SCRATCH file, proved applied; returns what to put back. */
$plantFile = static function (string $rel, string $from, string $to) use ($child, $sep): string {
    $file   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
    $before = (string) file_get_contents($file);
    $count  = 0;
    $after  = str_replace($from, $to, $before, $count);
    if ($count !== 1) {
        refuse("the plant '" . mb_substr($from, 0, 60) . "' matched {$count} place(s) in the scratch {$rel}, not exactly one");
    }
    file_put_contents($file, $after);
    clearstatcache(true, $file);
    if ((string) file_get_contents($file) !== $after) {
        refuse("a plant did not apply to the scratch {$rel}");
    }

    return $before;
};
$unplantFile = static function (string $rel, string $before) use ($child, $sep): void {
    $file = $child . $sep . 'web' . $sep . str_replace('/', $sep, $rel);
    file_put_contents($file, $before);
    clearstatcache(true, $file);
    if ((string) file_get_contents($file) !== $before) {
        refuse("a plant could not be taken out of the scratch {$rel}");
    }
};

// The address the passkey is for. Read what a stock installation would send
// first — a NOTE, not a check — then set it as an administrator would.
$rpProbe = newJar();
$rpSeen  = (array) (json_decode(req($rpProbe, 'POST', '/login/webauthn', (string) json_encode(['action' => 'auth_options']), ip(118))['body'], true) ?? []);
note('with auth.webauthn.rpID as this database holds it, the passkey options name rpId ' . var_export($rpSeen['options']['rpId'] ?? null, true)
    . ' (an empty value is not one any browser accepts; see #902\'s notes)');
setting('auth.webauthn.rpID', '127.0.0.1');
$passkeys = ['sam' => passkeyFor('sam'), 'tia' => passkeyFor('tia')];

// --- K8. A is signed in; B signs in with a PASSKEY on that browser.
/** Run the passkey journey (also used by the plant). */
$pkJourney = static function (string $as, string $ip, string $comeBack, bool $tamper = false) use (&$passkeys): array {
    [$jar]   = passwordStep('sam', $ip);
    $waiting = theirs() + ['login_redirect' => '/p902-sams-page', 'user_locale' => 'cy'];
    putInSession($jar, $waiting + ['portal_locale' => 'cy']);
    $idBefore = sessionIdOf($jar);
    $pk       = passkeyIn($jar, $passkeys[$as], $ip, $comeBack, $tamper);

    return ['jar' => $jar, 'pk' => $pk, 'now' => sessionEntries($jar), 'idBefore' => $idBefore, 'waiting' => $waiting];
};
$k8t = $pkJourney('tia', ip(119), '/', true);
ok('K25 a passkey assertion with a TAMPERED signature is refused and changes nothing: the passkey journeys below are genuine signatures the portal really checks',
    ($k8t['pk']['answer']['success'] ?? null) === false && (int) ($k8t['now']['user_id'] ?? 0) === PEOPLE['sam'] && count(stillThere($k8t['now'], $k8t['waiting'])) === count($k8t['waiting']),
    'rpId ' . var_export($k8t['pk']['rpId'], true) . '; answer ' . $k8t['pk']['verify']['body']);
$k8 = $pkJourney('tia', ip(120), '/');
ok('K26 B signing in with a PASSKEY on a browser A is signed in on gets none of A\'s entries (message, typing, come-back address, A\'s saved language), keeps the browser\'s language, and the proof is B\'s, by passkey',
    ($k8['pk']['answer']['success'] ?? null) === true && ($k8['pk']['answer']['redirect'] ?? '') === '/' && (int) ($k8['now']['user_id'] ?? 0) === PEOPLE['tia']
        && stillThere($k8['now'], $k8['waiting']) === [] && ($k8['now']['portal_locale'] ?? null) === 'cy' && proofIn($k8['now']) === PEOPLE['tia'] . ':passkey',
    'answer ' . $k8['pk']['verify']['body'] . '; still there: ' . implode(', ', stillThere($k8['now'], $k8['waiting'])) . '; proof: ' . proofIn($k8['now']));
$k8Page = req($k8['jar'], 'GET', '/account/add-password', null, ip(120));
ok('K27 ...B\'s own page does not show A\'s waiting message, and the session identifier was replaced',
    $k8Page['status'] === 200 && str_contains($k8Page['body'], (string) $k8['waiting']['flash_msg']) === false && $k8['idBefore'] !== '' && sessionIdOf($k8['jar']) !== $k8['idBefore'],
    describe($k8Page) . '; identifier ' . (sessionIdOf($k8['jar']) === $k8['idBefore'] ? 'NOT replaced' : 'replaced'));
$k8s = $pkJourney('sam', ip(121), '/account/add-password');
ok('K28 A proving herself again with her own PASSKEY (as the "Add a password" page asks, #731) keeps every entry of hers, comes back to the page that asked, and her proof is renewed as passkey with a new identifier',
    ($k8s['pk']['answer']['success'] ?? null) === true && ($k8s['pk']['answer']['redirect'] ?? '') === '/account/add-password' && (int) ($k8s['now']['user_id'] ?? 0) === PEOPLE['sam']
        && count(stillThere($k8s['now'], $k8s['waiting'])) === count($k8s['waiting']) && proofIn($k8s['now']) === PEOPLE['sam'] . ':passkey'
        && $k8s['idBefore'] !== '' && sessionIdOf($k8s['jar']) !== $k8s['idBefore'],
    'answer ' . $k8s['pk']['verify']['body'] . '; still there: ' . implode(', ', stillThere($k8s['now'], $k8s['waiting'])) . '; proof: ' . proofIn($k8s['now']));
$webauthnRel = '_apps/auth/login/webauthn.php';
$wasWebauthn = $plantFile($webauthnRel, "            Auth::beginSessionFor(\$webauthnUserId);\n", "            session_regenerate_id(true);\n");
$p6 = $pkJourney('tia', ip(122), '/');
$unplantFile($webauthnRel, $wasWebauthn);
ok('K29 PLANT, the passkey sign-in put back as it was before #900 (a bare session_regenerate_id): A\'s message reaches B, so K26 and K27 can fail',
    ($p6['pk']['answer']['success'] ?? null) === true && (int) ($p6['now']['user_id'] ?? 0) === PEOPLE['tia'] && in_array('flash_msg', stillThere($p6['now'], $p6['waiting']), true) === true,
    'answer ' . $p6['pk']['verify']['body'] . '; still there under the plant: ' . implode(', ', stillThere($p6['now'], $p6['waiting'])));

// --- K9. A KIOSK browser: the Asset Tracker hand-over put a member in the
//     session (`kiosk_user_id`), which is NOT a sign-in. Then somebody really
//     signs in on it. The kiosk member and the person signing in are
//     deliberately the SAME person (B): only in that fixture do "a kiosk
//     counts as nobody" (start clean) and "a kiosk counts as its member" (the
//     same person, keep everything) give different answers. With two
//     different people both rules start clean and the check could not fail.
$kioskJourney = static function (string $ip): array {
    $jar = newJar();
    req($jar, 'GET', '/login', null, $ip);
    $kiosk   = ['kiosk_user_id' => PEOPLE['tia'], 'kiosk_user_name' => 'Selftest two-step tia', 'kiosk_expires' => time() + 600];
    $waiting = theirs();
    putInSession($jar, $kiosk + $waiting);
    $in = passwordIn($jar, 'tia', $ip);

    return ['in' => $in, 'now' => sessionEntries($jar), 'all' => $kiosk + $waiting];
};
$k9 = $kioskJourney(ip(123));
ok('K30 a real sign-in on a KIOSK browser starts clean: nothing of the kiosk hand-over (not even the member it named, who is the person signing in) and none of the waiting entries are kept',
    $k9['in']['status'] === 302 && $k9['in']['location'] === '/' && (int) ($k9['now']['user_id'] ?? 0) === PEOPLE['tia'] && stillThere($k9['now'], $k9['all']) === [],
    describe($k9['in']) . '; still there: ' . implode(', ', stillThere($k9['now'], $k9['all'])));
$plantAuth(["    private const SESSION_KEYS_KEPT_FOR_A_DIFFERENT_PERSON = [\n        'portal_locale',\n    ];" => "    private const SESSION_KEYS_KEPT_FOR_A_DIFFERENT_PERSON = [\n        'portal_locale',\n        'kiosk_user_id',\n    ];"]);
$p7 = $kioskJourney(ip(124));
$unplant();
ok('K31 PLANT, kiosk_user_id added to what a different person keeps: the kiosk hand-over survives a real sign-in, so K30 can fail',
    in_array('kiosk_user_id', stillThere($p7['now'], $p7['all']), true) === true, 'still there under the plant: ' . implode(', ', stillThere($p7['now'], $p7['all'])));
$plantAuth(["foreach (['user_id', '2fa_user_id'] as \$key) {" => "foreach (['user_id', '2fa_user_id', 'kiosk_user_id'] as \$key) {"]);
$p8 = $kioskJourney(ip(125));
$unplant();
ok('K32 PLANT, a kiosk session counted as its member\'s: the waiting entries survive B\'s sign-in, so K30 can fail (and would not, had the kiosk named somebody else)',
    in_array('flash_msg', stillThere($p8['now'], $p8['all']), true) === true, 'still there under the plant: ' . implode(', ', stillThere($p8['now'], $p8['all'])));

// --- K10. An invitation whose sign-in fails AFTER the account is made.
//     Nothing in the real code can make that happen today (accept.php's own
//     comment says why), so the scratch Auth::beginSessionFor() is made to
//     throw: that is the only way to reach the branch that reports it.
$inviteJourney = static function (array $invitee, string $ip) use ($password): array {
    [$email, $login] = $invitee;
    $token = bin2hex(random_bytes(32));
    q("INSERT INTO tblInvitation (siteID, email, tokenHash, intendedRole, expiresAt, createdByID) VALUES (1, ?, ?, 'user', DATE_ADD(NOW(), INTERVAL 1 DAY), NULL)", 'ss', [$email, hash('sha256', $token)]);
    $jar  = newJar();
    $form = req($jar, 'GET', '/auth/invite?token=' . $token, null, $ip);
    $in   = req($jar, 'POST', '/auth/invite', ['token' => $token, 'csrf_token' => token($form['body']), 'fullName' => 'Selftest two-step invitee p902', 'username' => $login, 'password' => $password, 'passwordConfirm' => $password], $ip);

    return ['form' => $form, 'in' => $in, 'id' => (int) (one('SELECT userID FROM tblUsers WHERE emailAddress = ?', 's', [$email])['userID'] ?? 0), 'now' => sessionEntries($jar)];
};
$throwAtGrant = ["    public static function beginSessionFor(int \$userId): void\n    {\n" => "    public static function beginSessionFor(int \$userId): void\n    {\n        throw new \\LogicException('p902: a sign-in made to fail on purpose');\n"];
$plantAuth($throwAtGrant);
$k10 = $inviteJourney(INVITEES_902[0], ip(126));
$unplant();
ok('K33 an invitation whose sign-in fails after the account is made says the account WAS created and to sign in, never "already in use", and leaves nobody signed in',
    $k10['form']['status'] === 200 && $k10['in']['status'] === 200 && $k10['id'] > 0 && str_contains($k10['in']['body'], 'Your account has been created') === true
        && str_contains($k10['in']['body'], 'already in use') === false && array_key_exists('user_id', (array) $k10['now']) === false,
    describe($k10['form']) . ' then ' . describe($k10['in']) . '; new account ' . $k10['id'] . '; body: ' . mb_substr(trim(strip_tags($k10['in']['body'])), 0, 160));
$plantAuth($throwAtGrant);
$wasAccept = $plantFile('_apps/invites/accept.php', 'if ($committed === true) {', 'if (false) {');
$p9 = $inviteJourney(INVITEES_902[1], ip(127));
$unplantFile('_apps/invites/accept.php', $wasAccept);
$unplant();
ok('K34 PLANT, accept.php\'s #902 branch switched off: the same failure tells a person whose account WAS made that their username is taken, so K33 can fail',
    $p9['id'] > 0 && str_contains($p9['in']['body'], 'already in use') === true,
    describe($p9['in']) . '; new account ' . $p9['id']);

// The scratch copy goes back to exactly what was copied, stubs and all removed.
file_put_contents($scratchAuth, $authAsCopied);
clearstatcache(true, $scratchAuth);
if ((string) file_get_contents($scratchAuth) !== $authAsCopied) {
    refuse('the scratch Auth.php could not be put back as copied');
}

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
    . "+ (SELECT COUNT(*) FROM tblApiRateLimits WHERE bucket LIKE 'totp\\_%:9220__%') + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '" . TRIGGER . "') "
    . "+ (SELECT COUNT(*) FROM tblLinkedAccounts WHERE userID BETWEEN 922000 AND 922999) + (SELECT COUNT(*) FROM tblInvitation WHERE email = '" . INVITEE_EMAIL . "') "
    . "+ (SELECT COUNT(*) FROM tblUsers WHERE emailAddress = '" . INVITEE_EMAIL . "') "
    . "+ (SELECT COUNT(*) FROM tblInvitation WHERE email IN ('" . INVITEES_902[0][0] . "', '" . INVITEES_902[1][0] . "')) "
    . "+ (SELECT COUNT(*) FROM tblUsers WHERE emailAddress IN ('" . INVITEES_902[0][0] . "', '" . INVITEES_902[1][0] . "')) "
    . "+ (SELECT COUNT(*) FROM tblWebAuthnCredentials WHERE userID BETWEEN 922000 AND 922999) AS n"
)['n'];
$settingsBack = true;
foreach ($before as $settingKey => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $settingKey]) === $row);
}
ok('clean-up: the made-up accounts, their codes and records and the trigger are gone, and every setting it changed is as it was', $left === 0 && $settingsBack === true, 'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'no'));
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
