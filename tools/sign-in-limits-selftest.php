<?php
// Path: tools/sign-in-limits-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Sign-in limits and "Forgot password", on the REAL pages — self-test 🔐✉️ (#580)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG (found 12 October 2026, by running it)
 * -----------------------------------------------------
 * One wrong password was counted twice, so THREE wrong passwords blocked an
 * address, not five. Every try made while blocked added to the count, so the
 * block kept moving forward. While blocked, "Forgot password" said "sent" and
 * did nothing, and a reset link that had already arrived could not be used.
 * "Forgot password" also tested for Microsoft 365's mail settings only, so an
 * organisation sending through Google was told "sent" and sent nothing. None
 * of it failed loudly: every page answered, and every answer looked normal.
 * So this file runs the portal and counts.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder, gives the copy a throwaway
 * connection file, starts PHP's built-in web server on it behind a small
 * router that imitates `.htaccess`, and sends real requests with real
 * cookies. It also starts a STAND-IN for the two mail services, which records
 * what it is sent and sends nothing. In the scratch copy ONLY, the four
 * addresses the mailer calls (two of Microsoft's, two of Google's) are pointed
 * at the stand-in; it refuses to run unless it changed exactly those four.
 *
 * Each part uses its own made-up visitor address (203.0.113.x, a range kept
 * for examples), sent the way a proxy would send it. The portal is told for
 * the run to believe that header from 127.0.0.1, through its own
 * `portal.trustedProxies` setting, so the limits are counted by the portal's
 * own rules and one part cannot use up another's allowance.
 *
 * The portal is also given a time zone that is NOT the database server's.
 * The limits are counted by the database's clock; a "minutes left" worked out
 * on the page's clock instead was hours wrong, and with both on one zone that
 * cannot be seen (this file's own first run passed for exactly that reason).
 *
 *   A. One record per refused try: a wrong password, a made-up name, an empty
 *      password and an expired form each write exactly one.
 *   B. Five means five: after four wrong passwords the right one signs in;
 *      the fifth blocks; tries made while blocked are recorded but do not
 *      count, so the end of the block does not move; the screen says how long
 *      is left and is right, in a time zone that is not the database's; the
 *      block ends once the failures are 15 minutes old (the rows' times are
 *      moved, nothing waits).
 *   C. "Forgot password" while blocked, through Microsoft 365: the e-mail is
 *      sent, the link opens and saves, and the new password signs in once the
 *      block has ended. A reset does NOT end the block early (see below).
 *   D. The same through Google, with the Microsoft settings empty.
 *   E. The reset limits: the 6th request in 15 minutes sends nothing and looks
 *      the same; asking again does not lengthen the wait; the 11th try to use
 *      a link is refused with a plain message and changes nothing.
 *   F. No route, a half-filled route, a missing key file, and settings filled
 *      in for a route that is not the chosen one: nothing is sent, the same
 *      answer is shown, and it is recorded as "not set up".
 *   G. A known and an unknown address get the same answer, in each of: route
 *      set up, no route, over the limit.
 *   H. The administrator is told, on Admin, Integrations, Email and on the
 *      health page (and the monitor's address), when mail cannot be sent, and
 *      what is missing; and is not, when either route is set up.
 *   I. Guessing is still limited: 20 wrong passwords at four accounts from one
 *      browser are stopped by the limit for one address; 20 at one account
 *      from four addresses are stopped by the limit for one name.
 *
 * Parts J to S were added after an independent check planted 26 faults and
 * this file passed on 11 of them. Each of those 11 now fails a check here.
 *
 *   J. A switched-off account is answered exactly like no account: by
 *      "Forgot password" (nothing sent, nothing stored) and by sign-in. A
 *      list sent where text is expected is answered, not an error 500.
 *   K. A reset link: stored as a fingerprint; cancelled by a newer one; not
 *      left in the activity log by a refused save; refused once past its time
 *      (by UTC), and good before it; good once only; not valid if it was
 *      stored the way links were before this change; refused for an account
 *      switched off since it was sent.
 *   L. The limit for one name: an account tried by its name and by its e-mail
 *      address gets ten tries, not twenty; a block on a name says so; a
 *      made-up name is treated the same, whatever capitals it is typed in; a
 *      longer name that contains a real one does not block it; a completed
 *      reset frees the name, and deletes nothing.
 *   M. A refusal costs a password check whether or not the name has an
 *      account (see "what this cannot prove" for how far that goes).
 *   N. Forty tries sent at one moment, to eight portal servers: no more than
 *      five passwords are checked.
 *   O. "Forgot password" answers before it looks the address up: with a mail
 *      service made to take four seconds, the answer comes in under two.
 *   P. A forwarded header from a machine not on the trusted list is ignored,
 *      whichever of the two headers the portal is set to read.
 *   Q. An e-mail the provider refuses is recorded as failed, not as sent.
 *   R. The e-mail settings page refuses an ordinary member.
 *   S. A reset link lasts its 60 minutes across both clock-change nights, in
 *      Europe/London and America/New_York, with the database connection and
 *      PHP both in the changing zone.
 *
 * A DECISION THIS FILE HOLDS IN PLACE (part C)
 * --------------------------------------------
 * A successful reset does not clear a sign-in block. The block belongs to the
 * address, not the person: clearing it would let somebody with one account of
 * their own wipe the count for their address and go on guessing other
 * people's passwords.
 *
 * SAFETY
 * ------
 * - It writes rows, so it REFUSES unless the database's name starts with
 *   `selftest_`. Every account it makes is numbered 919000 to 919999; every
 *   record it causes carries a 203.0.113.x address or one of those numbers.
 *   They are removed at the START (in case an earlier run was interrupted)
 *   and at the END.
 * - It changes portal-wide settings for the run (the mail settings, the three
 *   sign-in limits, `portal.trustedProxies`, `site.timezone`,
 *   `maintenance.cronToken`) and puts each back exactly, including whether it
 *   was marked as stored encrypted.
 *   So do not run it at the same moment as another test on the same database.
 * - The scratch folder is a NEW child of `SELFTEST_WEB_DIR` (or the system's
 *   temporary folder). It refuses if that folder contains the repository or
 *   is inside it. The child is deleted at the end only if it still holds this
 *   run's marker. `web/_auth_keys` is never read or copied.
 * - Every server (the portal, the stand-in mail service, and the seven extra
 *   portal servers of part N) is started with its command as a list (no
 *   shell), so each process number is the server's own, and each is stopped
 *   by that number, never by a name. It refuses to start if anything already
 *   answers on any of its ports. Every answer must carry this run's header.
 * - The clean-up also runs from a shutdown function, so a crash part way
 *   still puts things back. WHAT IT CANNOT SURVIVE: being killed outright.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * - That a real mail provider accepts a message. The stand-in says yes to
 *   everything; nothing here touches Microsoft or Google.
 * - That every answer takes exactly the same TIME. Part M checks that a
 *   refused sign-in costs a password check either way, to within a factor of
 *   two, from seven tries of each kind: enough to catch the check being left
 *   out, not enough to prove nobody can measure a difference. Part O proves
 *   that "Forgot password" answers before the e-mail is sent ON THIS SERVER
 *   (PHP's built-in one). On a web server that holds PHP's output back until
 *   the script ends, the answer still waits; nothing here can see that.
 * - That tries at ONE NAME from many addresses at the same moment keep to
 *   ten. Only tries from one address take turns (part N).
 * - A block made of failures spread out over several minutes. The block ends
 *   when the OLDEST of the five failures is 15 minutes old, so it is shorter
 *   than 15 minutes when they were not typed one after another. Part B puts
 *   all five at one moment; it prints a spread-out case without checking it.
 * - The 15-minute windows on the two nights a year the clocks change. They
 *   are counted on the database server's own clock, as times without a zone.
 *   On a server whose clock changes, that hour is counted wrongly. Not run
 *   here, and not changed by #580. (A reset link's 60 minutes ARE run across
 *   those nights: part S.)
 * - Sign-in through Microsoft, Google or a passkey. They read the same count
 *   for an address; they are not run here.
 * - Apache, a portal behind a real proxy, or https: the router only imitates
 *   `.htaccess`, on plain http on 127.0.0.1.
 * - MariaDB. It has been run on MySQL 8.0.36 and 8.4.11.
 * - A captcha: none is set up in the test database.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_DIR=/a/quiet/folder php tools/sign-in-limits-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9520), SELFTEST_MAIL_PORT (default
 *         9521). The SEVEN ports after the mail port must be free as well
 *         (part N). The database must already hold `web/_sql/full_schema.sql`
 *         and know time zones by name (part S). Needs PHP's curl and openssl
 *         extensions and `proc_open()`.
 * Exit:   0 only when every check ran and passed and the last line says the
 *         run reached its end; 1 otherwise, including every refusal to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/580
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 114;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open', 'openssl_pkey_new'] as $needed) {
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
$port        = ($portRaw === false || $portRaw === '') ? 9520 : (int) $portRaw;
$mailPortRaw = getenv('SELFTEST_MAIL_PORT');
$mailPort    = ($mailPortRaw === false || $mailPortRaw === '') ? 9521 : (int) $mailPortRaw;
foreach ([$port, $mailPort] as $onePort) {
    if ($onePort < 1024 || $onePort > 65535) {
        refuse('SELFTEST_WEB_PORT and SELFTEST_MAIL_PORT must be between 1024 and 65535');
    }
}
if ($port === $mailPort) {
    refuse('SELFTEST_WEB_PORT and SELFTEST_MAIL_PORT must be two different ports');
}
// Part N sends tries at the same moment, which one server process cannot run
// together. It starts seven more portal servers for that part only, on the
// seven ports after the mail port.
$extraPorts = range($mailPort + 1, $mailPort + 7);
if (max($extraPorts) > 65535 || in_array($port, $extraPorts, true) === true) {
    refuse('the seven ports after SELFTEST_MAIL_PORT must exist and must not include SELFTEST_WEB_PORT');
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
/** The accounts. Each part has its own, so one part cannot use up another's allowance. */
const PEOPLE = [
    'admin'   => 919001, // a global administrator, for the two administrator's pages
    'alpha'   => 919011, // A, F and G: single tries, and requests for a link
    'bravo'   => 919012, // B: five means five
    'charlie' => 919013, // C: reset while blocked, Microsoft 365
    'delta'   => 919014, // D: reset while blocked, Google
    'echo'    => 919015, // E: the reset limits
    'foxtrot' => 919016, // I: one account guessed from four addresses
    'golf'    => 919017, // I: four accounts guessed from one browser
    'hotel'   => 919018,
    'india'   => 919019,
    'juliet'  => 919020,
    'kilo'    => 919021, // J and M: an account that is SWITCHED OFF
    'lima'    => 919022, // L: one account tried by its name AND by its e-mail address
    'mike'    => 919023, // L: a reset clears the count for the name
    'november' => 919024, // K and S: what a reset link will and will not do
    'oscar'   => 919025, // L and R: an ordinary member, whose name is part of a longer made-up one
    'papa'    => 919026, // N: forty tries at one moment
    'quebec'  => 919027, // M: how long a refusal takes
    'romeo'   => 919028, // N: forty tries at one moment, a second time
];
/** How this test names itself to the portal, so the records it causes can be found and removed whatever address they carry. */
const AGENT = 'sign-in-limits-selftest';
/** The address the mail is sent from, in every set-up that has one. */
const SENDER = 'office@selftest.invalid';
/** The made-up Google key file, written into the scratch copy only. */
const GOOGLE_KEY_FILE = 'selftest-sign-in-limits-google-key.json';

/** A made-up visitor address. 203.0.113.0/24 is kept for examples (RFC 5737), so it is nobody's. */
function ip(int $last): string
{
    return '203.0.113.' . $last;
}

/** The sign-in name of one of the people. */
function login(string $who): string
{
    return 'sil-' . $who;
}

/** The e-mail address of one of the people. */
function mailOf(string $who): string
{
    return $who . '.signin@selftest.invalid';
}

/** The limiter's bucket for one visitor address: `ask` for requests for a link, `use` for tries to use one. */
function bucket(string $kind, string $ip): string
{
    return 'pwreset:' . $kind . ':' . hash('sha256', $ip);
}

function removeWorld(): void
{
    q('DELETE FROM tblPasswordResets WHERE userID BETWEEN 919000 AND 919999');
    q("DELETE FROM tblEmailLog WHERE toRecipients LIKE '%.signin@selftest.invalid%'");
    q("DELETE FROM tblActivityLogs WHERE userID BETWEEN 919000 AND 919999 OR visitorIP LIKE '203.0.113.%' OR userAgent = ?", 's', [AGENT]);
    q("DELETE FROM tblErrors WHERE userID BETWEEN 919000 AND 919999 OR visitorIP LIKE '203.0.113.%'");
    // Only the buckets of this test's own made-up addresses.
    for ($last = 1; $last <= 254; $last++) {
        q('DELETE FROM tblApiRateLimits WHERE bucket IN (?, ?)', 'ss', [bucket('ask', ip($last)), bucket('use', ip($last))]);
    }
    q('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 919000 AND 919999');
    q('DELETE FROM tblUserSites WHERE userID BETWEEN 919000 AND 919999');
    q('DELETE FROM tblUsers WHERE userID BETWEEN 919000 AND 919999');
}

// =============================================================================
// 🧰 The two servers, and one request
// =============================================================================
$state = ['proc' => null, 'mailProc' => null, 'extra' => [], 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false, 'mailRead' => 0];

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
    stopServer($state['mailProc']);
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
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.sign-in-limits-selftest-owner') === $state['token']) {
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
 * the clean-up. It is written as plain text and marked NOT encrypted, so the
 * portal reads exactly what is written here whatever its start-up does with
 * encrypted values.
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
 * Set the mail settings to one of the situations the parts need. Every key
 * is written every time, so a situation never inherits from the one before.
 *
 *   none              nothing filled in (a fresh installation)
 *   ms365             Microsoft 365, complete
 *   ms365-half        Microsoft 365 with a client ID and a "from" address and
 *                     nothing else: exactly what the page's old test accepted
 *   google            Google, complete, Microsoft EMPTY
 *   google-no-file    Google chosen, but the key file named is not there
 *   google-not-chosen Google's settings complete, but the portal still set
 *                     to send through Microsoft 365, which is empty
 */
function mailSetup(string $kind): void
{
    $ms365  = in_array($kind, ['ms365', 'ms365-half'], true);
    $google = in_array($kind, ['google', 'google-no-file', 'google-not-chosen'], true);
    setting('mail.provider', in_array($kind, ['google', 'google-no-file'], true) === true ? 'google' : 'ms365');
    setting('mail.fallbackProvider', '');
    setting('mail.ms365.sharedMailbox', '');
    setting('auth.ms365.tenantID', $kind === 'ms365' ? 'standin-tenant' : '');
    setting('auth.ms365.appwide.clientID', $ms365 === true ? 'standin-client' : '');
    setting('auth.ms365.appwide.clientSecret', $kind === 'ms365' ? 'standin-secret' : '');
    setting('mail.defaultFromAddress', $ms365 === true ? SENDER : '');
    setting('mail.google.serviceAccountKeyFile', $google === true ? ($kind === 'google-no-file' ? 'selftest-no-such-key.json' : GOOGLE_KEY_FILE) : '');
    setting('mail.google.delegateUser', $google === true ? SENDER : '');
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else
 * and is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 * @param string                   $ip   The visitor's address, sent as a proxy
 *                                       would send it. '' sends none.
 * @param list<string>             $more Further request headers, whole lines.
 *
 * @return array{status:int, location:string, body:string, head:string, ms:int}
 */
function req(string $jar, string $method, string $path, ?array $post = null, string $ip = '', array $more = []): array
{
    global $port, $state;
    $handle  = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = array_merge(['Expect:', 'User-Agent: ' . AGENT], $more);
    if ($ip !== '') {
        $headers[] = 'X-Forwarded-For: ' . $ip;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90,
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
    if (preg_match('/^X-Sign-In-Limits-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header: it came from a server this run did not start', 'head' => '', 'ms' => $ms];
    }

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

/** A new, empty cookie jar: a browser that has never been here. */
function newJar(): string
{
    global $state;

    return $state['child'] . DIRECTORY_SEPARATOR . 'jar-' . bin2hex(random_bytes(6)) . '.txt';
}

/** What the page tells the person: "danger: Invalid credentials." (the first message box), or '' when there is none. */
function said(array $answer): string
{
    if (preg_match('#<div class="alert alert-(\w+)[^"]*"[^>]*>(.*?)</div>#s', $answer['body'], $m) !== 1) {
        return '';
    }

    return $m[1] . ': ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2])));
}

/**
 * One try at signing in through the real page: open it (which gives the
 * cookie and the form's token), then send the form.
 *
 * @param string $jar '' = a browser that has never been here.
 */
function tryPassword(string $ip, string $name, string $password, string $jar = ''): array
{
    $jar  = $jar === '' ? newJar() : $jar;
    $form = req($jar, 'GET', '/login', null, $ip);

    return req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => $name, 'password' => $password], $ip);
}

/** Did that try sign the person in? (Sent on to the front page, not shown the form again.) */
function signedIn(array $answer): bool
{
    return $answer['status'] === 302 && $answer['location'] === '/';
}

/** "HTTP 200, danger: Invalid credentials." — for a failed check's detail line. */
function describe(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']) . (said($answer) === '' ? '' : ', ' . said($answer));
}

/** How many records of one kind one visitor address has. */
function records(string $ip, string $type): int
{
    return (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE visitorIP = ? AND activityType = ?', 'ss', [$ip, $type])['n'];
}

/** Put every failed-sign-in record of one address at one moment, `$minutes` ago by the DATABASE's clock (the one the limit reads). */
function ageFailures(string $ip, int $minutes): void
{
    q("UPDATE tblActivityLogs SET timestamp = NOW() - INTERVAL ? MINUTE WHERE visitorIP = ? AND activityType = 'LoginFailed'", 'is', [$minutes, $ip]);
}

/**
 * Wait until the portal server has finished what it is doing.
 *
 * "Forgot password" answers the browser first and only then looks the
 * address up and sends the e-mail (#580). So when its answer arrives, the
 * e-mail has not gone yet. The server this test starts is ONE process that
 * serves one request at a time, so a second request is not served until the
 * first script has ended, work after the answer included. This sends that
 * second request: an address beginning with a dot, which the router refuses
 * with 403 before any portal code runs.
 */
function settle(): void
{
    req('', 'GET', '/.selftest-settle');
}

/** Ask for a reset link through the real page. `ms` is how long the ANSWER took; the e-mail is sent after it, and this waits for that too. */
function askForLink(string $ip, string $email, string $jar = ''): array
{
    $jar  = $jar === '' ? newJar() : $jar;
    $form = req($jar, 'GET', '/forgot-password', null, $ip);
    $sent = req($jar, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => $email], $ip);
    settle();

    return $sent + ['jar' => $jar];
}

/** How many wrong passwords were CHECKED for one visitor address (each leaves an "Incorrect password" record). */
function checkedFrom(string $ip): int
{
    return (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE visitorIP = ? AND activityType = 'LoginFailed' AND activityDescription LIKE 'Incorrect password for:%'", 's', [$ip])['n'];
}

/**
 * Send `$count` sign-in tries for one name from one visitor address AT THE
 * SAME MOMENT, each from a browser of its own, spread over several portal
 * servers so that they really do run together.
 *
 * @param list<int> $ports The ports of the portal servers to spread them over.
 *
 * @return array{invalid:int, blocked:int, other:int} How many were answered
 *         "Invalid credentials" (a password was checked), how many "Too many
 *         failed attempts" (none was), and how many anything else.
 */
function burst(array $ports, string $ip, string $name, int $count): array
{
    global $state;
    // The forms first, one at a time, so that only the tries themselves are sent together.
    $forms = [];
    for ($i = 0; $i < $count; $i++) {
        $jar     = newJar();
        $forms[] = [$jar, token(req($jar, 'GET', '/login', null, $ip)['body'])];
    }
    $multi   = curl_multi_init();
    $handles = [];
    foreach ($forms as $i => [$jar, $csrf]) {
        $handle = curl_init('http://127.0.0.1:' . $ports[$i % count($ports)] . '/login');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Expect:', 'User-Agent: ' . AGENT, 'X-Forwarded-For: ' . $ip], CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $csrf, 'username' => $name, 'password' => 'burst-' . $i]),
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
    $tally = ['invalid' => 0, 'blocked' => 0, 'other' => 0];
    foreach ($handles as $handle) {
        $raw  = (string) curl_multi_getcontent($handle);
        $mine = preg_match('/^X-Sign-In-Limits-Selftest:\s*' . $state['token'] . '\s*$/mi', $raw) === 1;
        if ($mine === true && str_contains($raw, 'Too many failed attempts') === true) {
            $tally['blocked']++;
        } elseif ($mine === true && str_contains($raw, 'Invalid credentials.') === true) {
            $tally['invalid']++;
        } else {
            $tally['other']++;
        }
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);

    return $tally;
}

/** Use a reset link through the real pages: open it, then send the new password. */
function useLink(string $ip, string $linkToken, string $newPassword): array
{
    $jar  = newJar();
    $form = req($jar, 'GET', '/reset-password?token=' . $linkToken, null, $ip);
    $csrf = token($form['body']);
    if ($csrf === '') {
        // A link the page does not accept shows no form. The save page is still tried, with the token from another form.
        $csrf = token(req($jar, 'GET', '/forgot-password', null, $ip)['body']);
    }

    return req($jar, 'POST', '/reset-password/save', ['csrf_token' => $csrf, 'token' => $linkToken, 'password' => $newPassword, 'password_confirm' => $newPassword], $ip)
        + ['opened' => $form['status'], 'hadForm' => str_contains($form['body'], 'name="password_confirm"')];
}

/** Does this password open this person's account, by the hash in the database? */
function passwordIs(string $who, string $password): bool
{
    return password_verify($password, (string) one('SELECT passwordHash FROM tblLocalAccounts WHERE userID = ?', 'i', [PEOPLE[$who]])['passwordHash']);
}

/**
 * What the stand-in mail service has been sent since the last call.
 *
 * @return list<array{path:string, to:string, link:string}> `to` and `link`
 *         are filled in for a send (not for a request for a key).
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
        $path    = (string) ($request['path'] ?? '');
        $body    = (array) json_decode((string) ($request['body'] ?? ''), true);
        $to      = '';
        $html    = '';
        if (str_starts_with($path, '/graph/') === true) {
            // Microsoft Graph: the message is JSON.
            $to   = (string) ($body['message']['toRecipients'][0]['emailAddress']['address'] ?? '');
            $html = (string) ($body['message']['body']['content'] ?? '');
        } elseif (str_starts_with($path, '/gmail/') === true) {
            // Gmail: the whole message, encoded; its body is encoded again.
            $mime  = (string) base64_decode(strtr((string) ($body['raw'] ?? ''), '-_', '+/'));
            $parts = explode("\r\n\r\n", $mime, 2);
            $to    = preg_match('/^To:\s*(\S+)\s*$/mi', $parts[0], $m) === 1 ? $m[1] : '';
            $html  = (string) base64_decode((string) preg_replace('/\s+/', '', $parts[1] ?? ''));
        }
        $out[] = ['path' => $path, 'to' => $to, 'link' => preg_match('#/reset-password\?token=([0-9a-f]{64})#', $html, $m) === 1 ? $m[1] : ''];
    }

    return $out;
}

/** The paths of a list from mailSince(), for a failed check's detail line. */
function paths(array $mail): string
{
    return $mail === [] ? '(nothing)' : implode(', ', array_column($mail, 'path'));
}

/**
 * Everything about an answer that a stranger could compare, with the parts
 * that differ on every request taken out (the time, and a cookie's value).
 */
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

/** One card on the health page: "warning | Not set up | detail…", or '' when the page has no such card. */
function healthCard(string $html, string $name): string
{
    if (preg_match('#<h3[^>]*>' . preg_quote($name, '#') . '</h3>\s*<p[^>]*><span class="badge bg-(\w+)">([^<]*)</span></p>\s*<p[^>]*>([^<]*)</p>#', $html, $m) !== 1) {
        return '';
    }

    return $m[1] . ' | ' . html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8') . ' | ' . html_entity_decode(trim($m[3]), ENT_QUOTES, 'UTF-8');
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
foreach (array_merge([$port, $mailPort], $extraPorts) as $onePort) {
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
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest sign-in ' . $who, mailOf($who), $who === 'admin' ? 1 : 0, $who === 'admin' ? 1 : 0]);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, ?, ?)', 'iii', [$id, $who === 'admin' ? 1 : 0, $who === 'admin' ? 1 : 0]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, login($who), $hash]);
}
// One account is switched OFF. It must be answered exactly like no account at all.
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [PEOPLE['kilo']]);
// The limits this file's numbers are written for, set rather than assumed.
setting('auth.rateLimit.maxAttempts', '5');
setting('auth.rateLimit.windowMinutes', '15');
setting('auth.rateLimit.maxAttemptsByUsername', '10');
// Believe the "X-Forwarded-For" header from 127.0.0.1, so each part has an address of its own.
setting('portal.trustedProxies', '127.0.0.1');
setting('portal.trustedProxyHeader', 'x-forwarded-for');
// 🕐 Give the portal a time zone that is NOT the database server's, and one
//    whose clocks change. The limits are counted by the database's clock and
//    the page works in the organisation's zone, so a "minutes left" worked
//    out from one clock and compared with the other is hours wrong. With both
//    on the same zone that mistake cannot be seen, which is how it sat in the
//    sign-in page unnoticed: this test's own first run passed for that reason.
$dbOffset = (int) one('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS m')['m'];
$zone     = '';
foreach (['Pacific/Auckland', 'America/Los_Angeles'] as $candidate) {
    if (abs(intdiv((new DateTimeImmutable('now', new DateTimeZone($candidate)))->getOffset(), 60) - $dbOffset) >= 120) {
        $zone = $candidate;
        break;
    }
}
if ($zone === '') {
    refuse('could not choose a time zone at least two hours away from the database server\'s');
}
setting('site.timezone', $zone);
// The monitor's address (part H) needs a token.
$cronToken = bin2hex(random_bytes(16));
setting('maintenance.cronToken', $cronToken);
mailSetup('none');

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'sign-in-limits-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.sign-in-limits-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/sign-in-limits-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// ✉️ A made-up Google key file, in the SCRATCH COPY only. The key in it is
//    made here, opens nothing, and is deleted with the folder. Google's route
//    signs its request with it, so it has to be a real key of the right kind.
$googleKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($googleKey === false || openssl_pkey_export($googleKey, $googleKeyText) === false) {
    refuse('this PHP could not make a throwaway key for the stand-in Google route');
}
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . GOOGLE_KEY_FILE,
    (string) json_encode(['client_email' => 'standin@selftest.invalid', 'private_key' => $googleKeyText])
);

// 🎯 Point the four addresses the mailer calls at the stand-in, in the SCRATCH
//    COPY only. Exactly four, each found exactly once, or the run stops: a
//    fifth address added later would otherwise be called for real.
//    REJECTED: a setting for the provider's address. That would be a switch in
//    the real portal able to send an organisation's mail password to any server.
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

// The stand-in mail service: says yes to everything, writes down what it was sent, sends nothing.
$standInCode = <<<'STANDIN'
<?php
// Written by tools/sign-in-limits-selftest.php for ONE run (deleted with its folder). Test only.
// A stand-in for Microsoft's and Google's mail services: it records each request and sends nothing.
declare(strict_types=1);
// Two switches, each a file the test puts beside this one for one part:
//   standin-slow    a request for a key takes four seconds (a slow provider)
//   standin-refuse  a message is refused (a provider that says no)
$path   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$forKey = str_starts_with($path, '/ms-login/') === true || $path === '/google-token';
$refuse = is_file(__DIR__ . '/standin-refuse') === true;
file_put_contents(__DIR__ . '/standin-requests.jsonl', json_encode(['path' => $path, 'body' => (string) file_get_contents('php://input')]) . "\n", FILE_APPEND | LOCK_EX);
if ($forKey === true && is_file(__DIR__ . '/standin-slow') === true) {
    sleep(4);
}
header('Content-Type: application/json');
if ($forKey === true) {
    echo json_encode(['access_token' => 'standin-token', 'expires_in' => 3600]);
} elseif ($refuse === true && (str_starts_with($path, '/graph/') === true || str_starts_with($path, '/gmail/') === true)) {
    http_response_code(403);
    echo json_encode(['error' => ['code' => 'standin-refused', 'message' => 'The stand-in was told to refuse this message.']]);
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

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/sign-in-limits-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Sign-In-Limits-Selftest: __TOKEN__');
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

// The two servers: each command as a LIST (no shell in between), ONE process
// each (no PHP_CLI_SERVER_WORKERS), so nothing can outlive them.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$quiet             = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']];
$state['mailProc'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $mailPort, $child . $sep . 'standin.php'], $quiet, $mailPipes, $child, $env);
/** Start one portal server process on one port. They share the sessions folder, so a form opened on one can be sent to another. */
$startPortal = static function (int $onPort) use ($child, $sep, $quiet, $env): mixed {
    return proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
            '-d', 'display_errors=0', '-S', '127.0.0.1:' . $onPort, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
        $quiet,
        $pipes,
        $child,
        $env
    );
};
$state['proc'] = $startPortal($port);
if (is_resource($state['proc']) === false || is_resource($state['mailProc']) === false) {
    refuse('a server could not be started');
}
$deadline = microtime(true) + 10;
while ((portAnswers($port) === false || portAnswers($mailPort) === false) && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false || portAnswers($mailPort) === false) {
    refuse("a server never answered (the portal on port {$port}, the stand-in mail service on port {$mailPort})");
}

echo "Sign-in limits and \"Forgot password\", on the real pages — self-test (#580)\n";
echo 'portal server process ' . (int) proc_get_status($state['proc'])['pid'] . ' on port ' . $port . ', stand-in mail process ' . (int) proc_get_status($state['mailProc'])['pid']
    . ' on port ' . $mailPort . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo 'the portal is set to the time zone ' . $zone . '; the database server\'s clock is ' . $dbOffset . " minute(s) from UTC\n";

// 🧭 Before anything is counted: the portal must be counting by the made-up
//    address. If it is not, every part below would share one address and the
//    numbers would mean nothing.
$first = tryPassword(ip(11), login('alpha'), 'wrong-' . bin2hex(random_bytes(4)));
if (records(ip(11), 'LoginFailed') < 1) {
    refuse('the portal did not record a failed sign-in against the made-up address ' . ip(11) . ' (' . describe($first) . '), so the limits cannot be told apart');
}

// =============================================================================
head('A. One record per refused try');
// =============================================================================
$rows = q("SELECT activityDescription FROM tblActivityLogs WHERE visitorIP = ? AND activityType = 'LoginFailed'", 's', [ip(11)]);
ok('a wrong password for a real name is answered "Invalid credentials" and writes exactly ONE failure record', said($first) === 'danger: Invalid credentials.' && count($rows) === 1, describe($first) . '; records: ' . count($rows));
ok('that one record names the person (the limit for one name depends on it)', count($rows) === 1 && str_contains((string) $rows[0]['activityDescription'], login('alpha')) === true, implode(' | ', array_column($rows, 'activityDescription')));
$answer = tryPassword(ip(12), 'sil-nobody-here', 'wrong-' . bin2hex(random_bytes(4)));
ok('a made-up name gets the same words and also writes exactly one', said($answer) === 'danger: Invalid credentials.' && records(ip(12), 'LoginFailed') === 1, describe($answer) . '; records: ' . records(ip(12), 'LoginFailed'));
$answer = tryPassword(ip(13), login('alpha'), '');
ok('an empty password is refused by the page itself and still writes one, as before', str_contains(said($answer), 'Please enter') === true && records(ip(13), 'LoginFailed') === 1, describe($answer) . '; records: ' . records(ip(13), 'LoginFailed'));
$jar    = newJar();
req($jar, 'GET', '/login', null, ip(14));
$answer = req($jar, 'POST', '/login', ['csrf_token' => str_repeat('0', 64), 'username' => login('alpha'), 'password' => $password], ip(14));
ok('an expired form is refused by the page itself and still writes one, as before', signedIn($answer) === false && records(ip(14), 'LoginFailed') === 1, describe($answer) . '; records: ' . records(ip(14), 'LoginFailed'));

// =============================================================================
head('B. Five means five, and a block that does not move');
// =============================================================================
$at = ip(21);
for ($i = 1; $i <= 4; $i++) {
    tryPassword($at, login('bravo'), 'wrong-' . $i);
}
$answer = tryPassword($at, login('bravo'), $password);
ok('after FOUR wrong passwords there are four records and the right password still signs in', records($at, 'LoginFailed') === 4 && signedIn($answer) === true, 'records: ' . records($at, 'LoginFailed') . '; ' . describe($answer));
$answer = tryPassword($at, login('bravo'), 'wrong-5');
ok('the FIFTH wrong password is still checked ("Invalid credentials") and makes five records', said($answer) === 'danger: Invalid credentials.' && records($at, 'LoginFailed') === 5, describe($answer) . '; records: ' . records($at, 'LoginFailed'));
ageFailures($at, 0);
$answer = tryPassword($at, login('bravo'), $password);
ok('now the RIGHT password is refused: the address is blocked', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true, describe($answer));
ok('the screen says to try again in 15 minutes', said($answer) === 'danger: Too many failed attempts. Please try again in 15 minute(s).', said($answer));
// One minute back, so that a failure record wrongly written by a try below
// would be NEWER than these five and could not hide in the same second.
ageFailures($at, 1);
$newest = (string) one("SELECT MAX(timestamp) AS t FROM tblActivityLogs WHERE visitorIP = ? AND activityType = 'LoginFailed'", 's', [$at])['t'];
tryPassword($at, login('bravo'), 'wrong-6');
tryPassword($at, login('bravo'), 'wrong-7');
tryPassword($at, login('bravo'), $password);
ok('four tries made while blocked add NO failure record (still five)', records($at, 'LoginFailed') === 5, 'records: ' . records($at, 'LoginFailed'));
ok('each of those tries is kept as a "LoginBlocked" record instead, which the limit does not count', records($at, 'LoginBlocked') === 4, 'LoginBlocked records: ' . records($at, 'LoginBlocked'));
$after = (string) one("SELECT MAX(timestamp) AS t FROM tblActivityLogs WHERE visitorIP = ? AND activityType = 'LoginFailed'", 's', [$at])['t'];
ok('so the newest failure is still the fifth one: the end of the block did not move', $after === $newest, "before {$newest}, after {$after}");
ageFailures($at, 10);
$answer = tryPassword($at, login('bravo'), $password);
ok('ten minutes on, the right password is still refused and the screen says 5 minutes are left', said($answer) === 'danger: Too many failed attempts. Please try again in 5 minute(s).', describe($answer));
ageFailures($at, 14);
$answer = tryPassword($at, login('bravo'), $password);
ok('fourteen minutes after the fifth failure the address is still blocked', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true, describe($answer));
// The LAST SECOND of a block. The five failures are put exactly 15 minutes
// back, which is still inside the block for the rest of that second on the
// database's clock. The screen used to say "0 minute(s)" then. The form is
// opened first and the moment chosen just after a second begins, so that the
// try lands inside it; a try that lands late signs in (the block is over,
// nothing is recorded) and another go is made.
$lastSecond = '';
for ($go = 1; $go <= 8 && $lastSecond === ''; $go++) {
    $jar  = newJar();
    $form = req($jar, 'GET', '/login', null, $at);
    while ((int) one('SELECT MICROSECOND(NOW(6)) AS u')['u'] > 150000) {
        usleep(20000);
    }
    ageFailures($at, 15);
    $answer = req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => login('bravo'), 'password' => $password], $at);
    if (signedIn($answer) === false) {
        $lastSecond = said($answer);
    }
}
ok(
    'in the LAST SECOND of the block the screen says 1 minute, never "0 minute(s)"',
    $lastSecond === 'danger: Too many failed attempts. Please try again in 1 minute(s).',
    $lastSecond === '' ? 'no try landed inside the last second in 8 goes, so this could not be checked: run again on a quieter machine' : $lastSecond
);
ageFailures($at, 16);
$answer = tryPassword($at, login('bravo'), $password);
ok('sixteen minutes after it, the block has ended and the right password signs in', signedIn($answer) === true, describe($answer));
// Not a check: what "15 minutes" means when the five failures were spread out.
$at = ip(22);
for ($i = 1; $i <= 5; $i++) {
    tryPassword($at, 'sil-nobody-here', 'wrong-' . $i);
}
$ids = array_column(q("SELECT logID FROM tblActivityLogs WHERE visitorIP = ? AND activityType = 'LoginFailed' ORDER BY logID", 's', [$at]), 'logID');
foreach ([14, 10, 6, 3, 0] as $n => $minutesAgo) {
    q('UPDATE tblActivityLogs SET timestamp = NOW() - INTERVAL ? MINUTE WHERE logID = ?', 'ii', [$minutesAgo, (int) ($ids[$n] ?? 0)]);
}
note('five failures spread over 14 minutes (the fifth just now): the page says "' . said(tryPassword($at, login('alpha'), $password)) . '". The block ends when the OLDEST of the five is 15 minutes old, so it is shorter than 15 minutes when they were not typed one after another.');

// =============================================================================
head('C. "Forgot password" while blocked from signing in — Microsoft 365');
// =============================================================================
mailSetup('ms365');
$at = ip(31);
for ($i = 1; $i <= 5; $i++) {
    tryPassword($at, login('charlie'), 'wrong-' . $i);
}
ageFailures($at, 0);
$answer = tryPassword($at, login('charlie'), $password);
ok('set-up: five wrong passwords, and the address is blocked from signing in', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true, describe($answer));
mailSince();
$sent = askForLink($at, mailOf('charlie'));
$mail = mailSince();
ok('asking for a reset link from the blocked address is answered as always (sent on to the "sent" page)', $sent['status'] === 302 && $sent['location'] === '/forgot-password?sent=1', describe($sent));
$send = array_values(array_filter($mail, static fn (array $m): bool => str_starts_with($m['path'], '/graph/')));
ok(
    'and the e-mail IS sent: the stand-in was asked for a key at Microsoft\'s sign-in address, then given one message at the sender\'s mailbox',
    count($mail) === 2 && $mail[0]['path'] === '/ms-login/standin-tenant/oauth2/v2.0/token' && $mail[1]['path'] === '/graph/v1.0/users/' . urlencode(SENDER) . '/sendMail',
    paths($mail)
);
$link = (string) ($send[0]['link'] ?? '');
ok('the message is addressed to the person and holds a reset link', count($send) === 1 && $send[0]['to'] === mailOf('charlie') && $link !== '', 'to: ' . ($send[0]['to'] ?? '(none)') . '; link found: ' . ($link !== '' ? 'yes' : 'no'));
$log = one('SELECT provider, status FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1', 's', [mailOf('charlie')]);
ok('the e-mail log says it went through Microsoft 365, and the activity record says it was e-mailed', ($log['provider'] ?? '') === 'ms365' && ($log['status'] ?? '') === 'sent' && records($at, 'PasswordResetEmailed') === 1, json_encode($log) . '; PasswordResetEmailed: ' . records($at, 'PasswordResetEmailed'));
$newPassword = bin2hex(random_bytes(12)) . 'Bb2!';
$answer      = useLink($at, $link, $newPassword);
ok('the link opens the new-password form while the address is still blocked', $answer['opened'] === 200 && $answer['hadForm'] === true, 'HTTP ' . $answer['opened'] . ', form: ' . ($answer['hadForm'] === true ? 'yes' : 'no'));
ok('saving it changes the password and sends the person to sign in', $answer['status'] === 302 && $answer['location'] === '/login?reset=1' && passwordIs('charlie', $newPassword) === true, describe($answer) . '; password changed: ' . (passwordIs('charlie', $newPassword) === true ? 'yes' : 'no'));
$answer = tryPassword($at, login('charlie'), $newPassword);
ok('a reset does NOT end the block early: the new password is refused until the wait is over, and the five failures are still on record', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true && records($at, 'LoginFailed') === 5, describe($answer) . '; records: ' . records($at, 'LoginFailed'));
ageFailures($at, 16);
$answer = tryPassword($at, login('charlie'), $newPassword);
ok('once the block has ended, the NEW password signs in', signedIn($answer) === true, describe($answer));
$answer = tryPassword(ip(32), login('charlie'), $password);
ok('and the old password no longer does', signedIn($answer) === false && said($answer) === 'danger: Invalid credentials.', describe($answer));

// =============================================================================
head('D. The same through Google, with the Microsoft settings EMPTY');
// =============================================================================
mailSetup('google');
$at = ip(41);
for ($i = 1; $i <= 5; $i++) {
    tryPassword($at, login('delta'), 'wrong-' . $i);
}
ageFailures($at, 0);
$answer = tryPassword($at, login('delta'), $password);
ok('set-up: five wrong passwords, and the address is blocked from signing in', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true, describe($answer));
mailSince();
$sent = askForLink($at, mailOf('delta'));
$mail = mailSince();
$send = array_values(array_filter($mail, static fn (array $m): bool => str_starts_with($m['path'], '/gmail/')));
ok(
    'the e-mail is sent through Google: the stand-in was asked for a key at Google\'s address, then given one message at the sender\'s mailbox',
    $sent['location'] === '/forgot-password?sent=1' && count($mail) === 2 && $mail[0]['path'] === '/google-token' && $mail[1]['path'] === '/gmail/v1/users/' . urlencode(SENDER) . '/messages/send',
    describe($sent) . '; ' . paths($mail)
);
$link = (string) ($send[0]['link'] ?? '');
ok('the message is addressed to the person and holds a reset link', count($send) === 1 && $send[0]['to'] === mailOf('delta') && $link !== '', 'to: ' . ($send[0]['to'] ?? '(none)') . '; link found: ' . ($link !== '' ? 'yes' : 'no'));
$log = one('SELECT provider, status FROM tblEmailLog WHERE toRecipients = ? ORDER BY emailLogID DESC LIMIT 1', 's', [mailOf('delta')]);
ok('the e-mail log says it went through Google', ($log['provider'] ?? '') === 'google' && ($log['status'] ?? '') === 'sent', (string) json_encode($log));
$newPassword = bin2hex(random_bytes(12)) . 'Cc3!';
$answer      = useLink($at, $link, $newPassword);
ok('the link works while blocked: the password is changed', $answer['status'] === 302 && $answer['location'] === '/login?reset=1' && passwordIs('delta', $newPassword) === true, describe($answer));
ageFailures($at, 16);
$answer = tryPassword($at, login('delta'), $newPassword);
ok('and the new password signs in once the block has ended', signedIn($answer) === true, describe($answer));

// =============================================================================
head('E. The reset limits: 5 requests, and 10 tries to use a link, per address per 15 minutes');
// =============================================================================
$at = ip(51);
mailSince();
$firstSent = null;
for ($i = 1; $i <= 5; $i++) {
    $sent      = askForLink($at, mailOf('echo'));
    $firstSent = $firstSent ?? $sent;
}
$mail  = mailSince();
$sends = count(array_filter($mail, static fn (array $m): bool => str_starts_with($m['path'], '/gmail/')));
ok('five requests in a row each send an e-mail', $sends === 5, 'sent: ' . $sends . ' (' . paths($mail) . ')');
$links = (int) one('SELECT COUNT(*) AS n FROM tblPasswordResets WHERE userID = ?', 'i', [PEOPLE['echo']])['n'];
$sixth = askForLink($at, mailOf('echo'));
$mail  = mailSince();
ok('the SIXTH sends nothing and stores no link', $mail === [] && (int) one('SELECT COUNT(*) AS n FROM tblPasswordResets WHERE userID = ?', 'i', [PEOPLE['echo']])['n'] === $links, 'stand-in: ' . paths($mail));
ok('and looks exactly like the first: the same answer, and the same page after it', shape($sixth) === shape($firstSent) && landing($sixth, $at) === landing($firstSent, $at), "sixth:\n" . shape($sixth) . "\nfirst:\n" . shape($firstSent));
ok('it is recorded as refused, for the administrator', records($at, 'PasswordResetBlocked') === 1, 'PasswordResetBlocked: ' . records($at, 'PasswordResetBlocked'));
askForLink($at, mailOf('echo'));
$hits = (int) one('SELECT COUNT(*) AS n FROM tblApiRateLimits WHERE bucket = ?', 's', [bucket('ask', $at)])['n'];
ok('asking again while refused is not counted, so the wait does not get longer (still five on the count)', $hits === 5, 'on the count: ' . $hits);
q('UPDATE tblApiRateLimits SET hitAt = NOW() - INTERVAL 16 MINUTE WHERE bucket = ?', 's', [bucket('ask', $at)]);
mailSince();
askForLink($at, mailOf('echo'));
$mail = mailSince();
$send = array_values(array_filter($mail, static fn (array $m): bool => str_starts_with($m['path'], '/gmail/')));
$link = (string) ($send[0]['link'] ?? '');
ok('sixteen minutes on, a request is sent again', count($send) === 1 && $link !== '', paths($mail));

$at       = ip(52);
$madeUp   = bin2hex(random_bytes(32));
$statuses = [];
for ($i = 1; $i <= 10; $i++) {
    $answer     = useLink($at, $madeUp, $newPassword);
    $statuses[] = $answer['location'];
}
ok('ten tries to use a made-up link are each answered as a link that is not valid', array_unique($statuses) === ['/reset-password?token=' . $madeUp], implode(' | ', array_unique($statuses)));
$echoPassword = bin2hex(random_bytes(12)) . 'Dd4!';
// The ten tries put at one moment, five minutes ago by the database's clock, so the wait is a known number: ten minutes.
q('UPDATE tblApiRateLimits SET hitAt = NOW() - INTERVAL 5 MINUTE WHERE bucket = ?', 's', [bucket('use', $at)]);
$answer = useLink($at, $link, $echoPassword);
ok('the ELEVENTH try is refused, even with a REAL link: the password is not changed and the link is not used up', $answer['location'] === '/reset-password?token=' . $link . '&wait=10' && passwordIs('echo', $echoPassword) === false && one('SELECT usedAt FROM tblPasswordResets WHERE tokenHash = ?', 's', [hash('sha256', $link)])['usedAt'] === null, describe($answer));
$page = req(newJar(), 'GET', $answer['location'], null, $at);
ok('the page it is sent to says, in plain words, to wait about 10 minutes and try again (the right number, in a time zone that is not the database\'s)', str_contains(said($page), 'Too many tries from this connection') === true && str_contains(said($page), 'wait about 10 minute(s)') === true, said($page));
$other = useLink($at, $madeUp, $newPassword);
ok('a made-up link is refused the same way, so the refusal says nothing about whether a link is real', $other['location'] === '/reset-password?token=' . $madeUp . '&wait=10', describe($other));
$hits = (int) one('SELECT COUNT(*) AS n FROM tblApiRateLimits WHERE bucket = ?', 's', [bucket('use', $at)])['n'];
ok('tries made while refused are not counted (still ten on the count)', $hits === 10, 'on the count: ' . $hits);
q('UPDATE tblApiRateLimits SET hitAt = NOW() - INTERVAL 16 MINUTE WHERE bucket = ?', 's', [bucket('use', $at)]);
$answer = useLink($at, $link, $echoPassword);
ok('sixteen minutes on, the same link works and the password is changed', $answer['location'] === '/login?reset=1' && passwordIs('echo', $echoPassword) === true, describe($answer));

// =============================================================================
head('F. When mail cannot be sent: nothing goes out, the same answer is shown, and it is recorded');
// =============================================================================
$normal = $firstSent; // an answer given when mail WAS sent, to compare with
foreach ([
    'none'              => [61, 'nothing set up'],
    'ms365-half'        => [62, 'Microsoft 365 half filled in (a client ID and a "from" address, which is all the old test asked for)'],
    'google-no-file'    => [63, 'Google chosen, but its key file is not there'],
    'google-not-chosen' => [64, 'Google filled in, but the portal still set to send through Microsoft 365'],
] as $kind => [$last, $words]) {
    mailSetup($kind);
    $at     = ip($last);
    $errors = (int) one('SELECT COUNT(*) AS n FROM tblErrors')['n'];
    mailSince();
    $sent = askForLink($at, mailOf('alpha'));
    $mail = mailSince();
    ok(
        $words . ': nothing is sent, it is recorded as "not set up", and no error is raised',
        $mail === [] && records($at, 'PasswordResetNoMailer') === 1 && records($at, 'PasswordResetEmailed') === 0 && (int) one('SELECT COUNT(*) AS n FROM tblErrors')['n'] === $errors,
        'stand-in: ' . paths($mail) . '; PasswordResetNoMailer: ' . records($at, 'PasswordResetNoMailer') . '; new error records: ' . ((int) one('SELECT COUNT(*) AS n FROM tblErrors')['n'] - $errors)
    );
    ok($words . ': the person is shown exactly what they are shown when mail IS sent', shape($sent) === shape($normal) && landing($sent, $at) === landing($normal, ip(51)), "this:\n" . shape($sent) . "\nwhen sent:\n" . shape($normal));
}

// =============================================================================
head('G. A known and an unknown address get the same answer');
// =============================================================================
$unknown = 'nobody.at.all.signin@selftest.invalid';
foreach (['google' => [71, 'mail set up'], 'none' => [73, 'no mail route']] as $kind => [$last, $words]) {
    mailSetup($kind);
    mailSince();
    $known   = askForLink(ip($last), mailOf('alpha'));
    $unknow  = askForLink(ip($last + 1), $unknown);
    $mail    = mailSince();
    $proof   = $kind === 'google' ? count($mail) === 2 : $mail === []; // the known one really was sent, or really was not
    ok(
        $words . ': the same status, the same address to go to, the same headers and the same page for both',
        $proof === true && shape($known) === shape($unknow) && landing($known, ip($last)) === landing($unknow, ip($last + 1)) && $known['location'] === '/forgot-password?sent=1',
        'stand-in: ' . paths($mail) . "\nknown:\n" . shape($known) . "\nunknown:\n" . shape($unknow)
    );
    if ($kind === 'google') {
        note('how long each ANSWER took: ' . $known['ms'] . ' ms for the known address, ' . $unknow['ms'] . ' ms for the unknown one. The e-mail is sent after the answer; part O checks that.');
    }
}
mailSetup('google');
$at = ip(75);
for ($i = 1; $i <= 5; $i++) {
    askForLink($at, $unknown);
}
mailSince();
$known  = askForLink($at, mailOf('alpha'));
$unknow = askForLink($at, $unknown);
$mail   = mailSince();
ok(
    'over the limit: the same again for both, the same as an ordinary answer, and nothing is sent',
    $mail === [] && shape($known) === shape($unknow) && shape($known) === shape($normal) && landing($known, $at) === landing($unknow, $at),
    'stand-in: ' . paths($mail) . "\nknown:\n" . shape($known) . "\nunknown:\n" . shape($unknow)
);
ok('an unknown address never has a link stored for it or a message sent', records(ip(72), 'PasswordResetNoUser') === 1 && records(ip(72), 'PasswordResetEmailed') === 0, 'PasswordResetNoUser: ' . records(ip(72), 'PasswordResetNoUser'));

// =============================================================================
head('H. The administrator is told when mail cannot be sent, and what is missing');
// =============================================================================
$at       = ip(81);
$adminJar = newJar();
$answer   = tryPassword($at, login('admin'), $password, $adminJar);
if (signedIn($answer) === false) {
    refuse('the administrator could not sign in (' . describe($answer) . ')');
}
/** The three places an administrator looks, for the mail situation that is set now. */
$look = static function () use ($adminJar, $at, $cronToken): array {
    $monitor = (array) json_decode(req('', 'GET', '/cron/health?token=' . $cronToken, null, $at)['body'], true);

    return [
        'email'   => req($adminJar, 'GET', '/admin/integrations/email', null, $at),
        'health'  => healthCard(req($adminJar, 'GET', '/admin/maintenance/health', null, $at)['body'], 'Email'),
        'monitor' => (array) ($monitor['probes']['Email'] ?? []),
    ];
};
/** The administrator's notice on the e-mail page, as text; '' when the page has none. */
$notice = static function (array $page): string {
    return preg_match('#<div class="alert alert-\w+" role="\w+" data-mail-status="([a-z-]+)">(.*?)</div>#s', $page['body'], $m) === 1
        ? $m[1] . ': ' . trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8')))
        : '';
};

mailSetup('none');
$unsent = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE activityType = 'PasswordResetNoMailer' AND timestamp > NOW() - INTERVAL 7 DAY")['n'];
$seen   = $look();
$text   = $notice($seen['email']);
ok('nothing set up — the e-mail page says so in plain words, and that reset links are not being sent', $seen['email']['status'] === 200 && str_starts_with($text, 'not-ready: This portal cannot send e-mail yet') === true && str_contains($text, 'Password-reset links are not being sent') === true, 'HTTP ' . $seen['email']['status'] . '; ' . $text);
ok(
    'nothing set up — it lists the four things Microsoft 365 needs, and sends the administrator to the Settings page, which is where they are typed',
    str_contains($text, 'tenant ID') === true && str_contains($text, 'application (client) ID') === true && str_contains($text, 'client secret') === true && str_contains($text, 'an address to send from') === true
        && str_contains($text, 'Fill these in on the Settings page.') === true && str_contains($seen['email']['body'], '<a href="/admin/settings" class="alert-link">Settings page</a>') === true,
    $text
);
ok('nothing set up — the health page shows a warning, says reset e-mails cannot be sent, counts the people who asked and got nothing, and names the Settings page', str_starts_with($seen['health'], 'warning | Not set up | Password-reset e-mails cannot be sent.') === true && str_contains($seen['health'], 'not sent in the last 7 days: ' . $unsent . '.') === true && $unsent >= 4 && str_contains($seen['health'], 'Fill these in at Admin → Settings') === true, $seen['health'] . ' (expected the count ' . $unsent . ')');
ok('nothing set up — the monitor\'s address reports the same warning', ($seen['monitor']['state'] ?? '') === 'warn' && ($seen['monitor']['label'] ?? '') === 'Not set up', (string) json_encode($seen['monitor']));

foreach (['ms365' => 'Microsoft 365', 'google' => 'Google'] as $kind => $name) {
    mailSetup($kind);
    $seen = $look();
    $text = $notice($seen['email']);
    ok($name . ' set up — the e-mail page says mail goes through ' . $name . ', says that is not proof of delivery, and shows no warning', str_starts_with($text, 'ready: E-mail is set to go through ' . $name) === true && str_contains($text, 'does not prove a message arrives') === true && str_contains($seen['email']['body'], 'cannot send e-mail') === false, $text);
    ok($name . ' set up — the health page and the monitor show it as set up, not as a warning', str_starts_with($seen['health'], 'success | Set up (' . $name . ') |') === true && ($seen['monitor']['state'] ?? '') === 'ok', $seen['health'] . ' / ' . json_encode($seen['monitor']));
}

mailSetup('ms365-half');
$seen = $look();
$text = $notice($seen['email']);
ok('Microsoft 365 half filled in — the warning shows and names what is still missing (the tenant ID and the secret), not what is there', str_starts_with($text, 'not-ready:') === true && str_contains($text, 'tenant ID') === true && str_contains($text, 'client secret') === true && str_contains($text, 'application (client) ID') === false && str_starts_with($seen['health'], 'warning | Not set up |') === true, $text . ' / ' . $seen['health']);
mailSetup('google-no-file');
$seen = $look();
$text = $notice($seen['email']);
ok('Google chosen but its key file is not there — the warning shows and names the file', str_starts_with($text, 'not-ready:') === true && str_contains($text, 'selftest-no-such-key.json') === true && str_starts_with($seen['health'], 'warning | Not set up |') === true, $text . ' / ' . $seen['health']);
mailSetup('google-not-chosen');
$seen = $look();
$text = $notice($seen['email']);
ok('Google filled in but Microsoft 365 still the chosen route — the warning shows, because that is the route the portal would use', str_starts_with($text, 'not-ready:') === true && str_contains($text, 'go through Microsoft 365') === true && ($seen['monitor']['state'] ?? '') === 'warn', $text . ' / ' . json_encode($seen['monitor']));

// =============================================================================
head('I. Guessing is still limited');
// =============================================================================
// One browser, one address, 20 wrong passwords at four different accounts.
$at      = ip(91);
$browser = newJar();
$checked = 0;
foreach (['golf', 'hotel', 'india', 'juliet'] as $who) {
    for ($i = 1; $i <= 5; $i++) {
        $checked += said(tryPassword($at, login($who), 'guess-' . $i, $browser)) === 'danger: Invalid credentials.' ? 1 : 0;
    }
}
ok('20 guesses at four accounts from ONE browser: only the first five were checked against a password', $checked === 5 && records($at, 'LoginFailed') === 5, 'answered "Invalid credentials": ' . $checked . '; failure records: ' . records($at, 'LoginFailed'));
ok('the other fifteen were refused without a check, each kept as a "LoginBlocked" record', records($at, 'LoginBlocked') === 15, 'LoginBlocked: ' . records($at, 'LoginBlocked'));
$answer = tryPassword($at, login('juliet'), $password, $browser);
ok('and the RIGHT password for one of them is refused from that address (what stops it: the limit of 5 for one address)', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true, describe($answer));

// One account, 20 wrong passwords from four addresses, a new browser every time.
$checked = 0;
foreach ([92, 93, 94, 95] as $last) {
    for ($i = 1; $i <= 5; $i++) {
        tryPassword(ip($last), login('foxtrot'), 'guess-' . $i);
    }
    $checked += records(ip($last), 'LoginFailed');
}
ok('20 guesses at ONE account from four addresses: only the first ten were checked against the password', $checked === 10 && records(ip(92), 'LoginFailed') === 5 && records(ip(93), 'LoginFailed') === 5 && records(ip(94), 'LoginFailed') === 0 && records(ip(95), 'LoginFailed') === 0, 'failure records: ' . $checked);
ok('the other ten were refused without a check, each kept as a "LoginBlocked" record', records(ip(94), 'LoginBlocked') + records(ip(95), 'LoginBlocked') === 10, 'LoginBlocked: ' . (records(ip(94), 'LoginBlocked') + records(ip(95), 'LoginBlocked')));
$answer = tryPassword(ip(96), login('foxtrot'), $password);
ok('and the RIGHT password is refused from a fifth address that has never failed (what stops it: the limit of 10 for one name)', signedIn($answer) === false && records(ip(96), 'LoginBlocked') === 1 && records(ip(96), 'LoginFailed') === 0, describe($answer));
$answer = tryPassword(ip(97), login('golf'), $password);
ok('somebody else can still sign in from an address of their own: the block is on that one name, not on everybody', signedIn($answer) === true, describe($answer));

// =============================================================================
head('J. Nothing tells a stranger which names and addresses have accounts');
// =============================================================================
mailSetup('google');
mailSince();
$off     = askForLink(ip(101), mailOf('kilo'));
$unknow  = askForLink(ip(102), $unknown);
$mail    = mailSince();
ok('"Forgot password" for a SWITCHED-OFF account is answered exactly like an address nobody has: the same answer, and the same page after it', shape($off) === shape($unknow) && landing($off, ip(101)) === landing($unknow, ip(102)) && $off['location'] === '/forgot-password?sent=1', "switched off:\n" . shape($off) . "\nunknown:\n" . shape($unknow));
$links = (int) one('SELECT COUNT(*) AS n FROM tblPasswordResets WHERE userID = ?', 'i', [PEOPLE['kilo']])['n'];
ok('and no e-mail is sent and no link is stored for the switched-off account', $mail === [] && $links === 0, 'stand-in: ' . paths($mail) . '; links stored: ' . $links);
$answer = tryPassword(ip(103), login('kilo'), $password);
ok('a switched-off account cannot sign in with its RIGHT password, and is told the same as for a wrong one', signedIn($answer) === false && said($answer) === 'danger: Invalid credentials.' && records(ip(103), 'LoginFailed') === 1, describe($answer) . '; records: ' . records(ip(103), 'LoginFailed'));
// A LIST sent where text is expected. These used to stop the page with an error 500.
$jar    = newJar();
$form   = req($jar, 'GET', '/login', null, ip(104));
$answer = req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => ['x'], 'password' => ['y']], ip(104));
ok('a list sent as the name and the password on the sign-in page is answered like empty fields, not with an error', $answer['status'] === 200 && str_contains(said($answer), 'Please enter') === true, describe($answer));
$jar    = newJar();
$form   = req($jar, 'GET', '/forgot-password', null, ip(104));
$answer = req($jar, 'POST', '/forgot-password/save', ['csrf_token' => token($form['body']), 'email' => ['x']], ip(104));
settle();
$other  = req($jar, 'POST', '/reset-password/save', ['csrf_token' => token($form['body']), 'token' => ['x'], 'password' => ['y'], 'password_confirm' => ['y']], ip(104));
ok('a list sent as the address on "Forgot password" gets the usual answer, and one sent as a reset link is refused like an empty one', $answer['status'] === 302 && $answer['location'] === '/forgot-password?sent=1' && $other['status'] === 302 && $other['location'] === '/forgot-password', describe($answer) . ' / ' . describe($other));

// =============================================================================
head('K. What a reset link will and will not do');
// =============================================================================
$at = ip(111);
mailSince();
askForLink($at, mailOf('november'));
$link1  = (string) (array_values(array_filter(mailSince(), static fn (array $m): bool => $m['link'] !== ''))[0]['link'] ?? '');
$stored = array_column(q('SELECT tokenHash FROM tblPasswordResets WHERE userID = ?', 'i', [PEOPLE['november']]), 'tokenHash');
ok(
    'the database holds a fingerprint of the link, never the link itself',
    $link1 !== '' && count($stored) === 1 && preg_match('/^[0-9a-f]{64}\z/', (string) $stored[0]) === 1 && $stored[0] !== $link1
        && (int) one('SELECT COUNT(*) AS n FROM tblPasswordResets WHERE tokenHash LIKE ?', 's', ['%' . $link1 . '%'])['n'] === 0,
    'link found: ' . ($link1 !== '' ? 'yes' : 'no') . '; rows: ' . count($stored) . '; stored as it is: ' . (($stored[0] ?? '') === $link1 ? 'YES' : 'no')
);
askForLink($at, mailOf('november'));
$link2       = (string) (array_values(array_filter(mailSince(), static fn (array $m): bool => $m['link'] !== ''))[0]['link'] ?? '');
$novPassword = bin2hex(random_bytes(12)) . 'Ee5!';
$answer      = useLink($at, $link1, $novPassword);
ok('asking for a new link cancels the one before it: the OLDER link is refused and changes nothing', $link2 !== '' && $link2 !== $link1 && $answer['hadForm'] === false && $answer['location'] === '/reset-password?token=' . $link1 && passwordIs('november', $novPassword) === false, describe($answer) . '; form shown: ' . ($answer['hadForm'] === true ? 'yes' : 'no'));

// The reset form is sent and REFUSED (the two passwords differ), by a browser
// that names the page it came from. That address is the link itself.
$jar    = newJar();
$form   = req($jar, 'GET', '/reset-password?token=' . $link2, null, $at);
$answer = req(
    $jar,
    'POST',
    '/reset-password/save',
    ['csrf_token' => token($form['body']), 'token' => $link2, 'password' => $novPassword, 'password_confirm' => $novPassword . 'x'],
    $at,
    ['Referer: http://127.0.0.1:' . $port . '/reset-password?token=' . $link2]
);
$kept  = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE CONCAT_WS('|', activityDescription, requestHeaders, sessionDataSnapshot) LIKE ?", 's', ['%' . $link2 . '%'])['n'];
$page  = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE visitorIP = ? AND activityType = 'PasswordResetFailed' AND requestHeaders LIKE '%reset-password\"%'", 's', [$at])['n'];
ok('a refused save does not leave the link in the activity log: the page the browser came from is kept, what follows its "?" is not', $answer['location'] === '/reset-password?token=' . $link2 && $kept === 0 && $page === 1, describe($answer) . '; records holding the link: ' . $kept . '; records naming the page without it: ' . $page);
ok('the reset page tells the browser never to pass its address on (Referrer-Policy: no-referrer, and no second policy)', preg_match_all('/^Referrer-Policy:\s*(.*?)\s*$/mi', $form['head'], $policies) === 1 && $policies[1][0] === 'no-referrer', implode(' | ', $policies[1] ?? []));

// The expiry is a moment in UTC, kept and compared by the database.
q('UPDATE tblPasswordResets SET expiresAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE userID = ? AND usedAt IS NULL', 'i', [PEOPLE['november']]);
$page   = req(newJar(), 'GET', '/reset-password?token=' . $link2, null, $at);
$answer = useLink($at, $link2, $novPassword);
ok('a link one minute past its time (by UTC) is refused: the page says it has expired, and saving changes nothing', str_contains(said($page), 'has expired') === true && $answer['hadForm'] === false && $answer['location'] === '/reset-password?token=' . $link2 && passwordIs('november', $novPassword) === false, said($page) . ' / ' . describe($answer));
q('UPDATE tblPasswordResets SET expiresAt = UTC_TIMESTAMP() + INTERVAL 30 MINUTE WHERE userID = ? AND usedAt IS NULL', 'i', [PEOPLE['november']]);
$answer = useLink($at, $link2, $novPassword);
ok('a link with 30 minutes left (by UTC) works, in a portal whose time zone is hours away from UTC', $answer['hadForm'] === true && $answer['location'] === '/login?reset=1' && passwordIs('november', $novPassword) === true, describe($answer));
$answer = useLink($at, $link2, $novPassword . 'again');
ok('a link works ONCE: used a second time it is refused and changes nothing', $answer['hadForm'] === false && $answer['location'] === '/reset-password?token=' . $link2 && passwordIs('november', $novPassword) === true, describe($answer));

// A link from BEFORE the expiry was kept in UTC. It is stored the old way (the
// plain SHA-256 of the link) with an expiry 12 hours ahead, which is what a
// local time in a zone ahead of UTC looks like when it is read as UTC. It
// must not come back to life: it is simply not valid any more.
$oldLink = bin2hex(random_bytes(32));
q('INSERT INTO tblPasswordResets (userID, tokenHash, expiresAt, createdIP) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 12 HOUR, ?)', 'iss', [PEOPLE['november'], hash('sha256', $oldLink), $at]);
$answer = useLink($at, $oldLink, $novPassword . 'old');
q('DELETE FROM tblPasswordResets WHERE tokenHash = ?', 's', [hash('sha256', $oldLink)]);
ok('a link stored the way links were before this change is not valid, however far ahead its expiry reads, and changes nothing', $answer['hadForm'] === false && $answer['location'] === '/reset-password?token=' . $oldLink && passwordIs('november', $novPassword) === true, describe($answer));

// The account is switched off AFTER its link was sent.
mailSince();
askForLink($at, mailOf('november'));
$link3 = (string) (array_values(array_filter(mailSince(), static fn (array $m): bool => $m['link'] !== ''))[0]['link'] ?? '');
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [PEOPLE['november']]);
$answer  = useLink(ip(112), $link3, $novPassword . 'off');
$offPage = said(req(newJar(), 'GET', '/reset-password?token=' . $link3, null, ip(112)));
$noPage  = said(req(newJar(), 'GET', '/reset-password?token=' . bin2hex(random_bytes(32)), null, ip(112)));
q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [PEOPLE['november']]);
ok('a link for an account switched off since it was sent is refused in the same words as a made-up link, and changes nothing', $link3 !== '' && $answer['hadForm'] === false && $answer['location'] === '/reset-password?token=' . $link3 && $offPage === $noPage && $offPage !== '' && passwordIs('november', $novPassword) === true, describe($answer) . '; page: ' . $offPage . ' / made-up: ' . $noPage);

// =============================================================================
head('L. The limit for one NAME: counted by account, matched exactly, said truthfully, cleared by a reset');
// =============================================================================
for ($i = 1; $i <= 5; $i++) {
    tryPassword(ip(121), login('lima'), 'wrong-' . $i);
    tryPassword(ip(122), mailOf('lima'), 'wrong-' . $i);
}
ageFailures(ip(121), 0);
ageFailures(ip(122), 0);
$byName = tryPassword(ip(123), login('lima'), $password);
$byMail = tryPassword(ip(124), mailOf('lima'), $password);
ok(
    'one account tried five times by its NAME and five by its E-MAIL ADDRESS has had its ten: the right password is then refused from a new address, by the name and by the address',
    checkedFrom(ip(121)) === 5 && checkedFrom(ip(122)) === 5 && signedIn($byName) === false && signedIn($byMail) === false
        && records(ip(123), 'LoginBlocked') === 1 && records(ip(123), 'LoginFailed') === 0 && records(ip(124), 'LoginBlocked') === 1 && records(ip(124), 'LoginFailed') === 0,
    'checked: ' . checkedFrom(ip(121)) . ' + ' . checkedFrom(ip(122)) . '; by name: ' . describe($byName) . '; by address: ' . describe($byMail)
);
ok('a block on a name SAYS so, and how long is left: "Too many failed attempts", 15 minutes', said($byName) === 'danger: Too many failed attempts. Please try again in 15 minute(s).' && said($byMail) === said($byName), said($byName) . ' / ' . said($byMail));
// A made-up name, typed three ways. It must be counted as ONE name and treated exactly like a real one.
for ($i = 1; $i <= 5; $i++) {
    tryPassword(ip(125), 'SIL-NOBODY-ZULU', 'wrong-' . $i);
    tryPassword(ip(126), 'sil-nobody-zulu', 'wrong-' . $i);
}
ageFailures(ip(125), 0);
ageFailures(ip(126), 0);
$madeUp = tryPassword(ip(127), ' Sil-Nobody-Zulu ', 'wrong');
ok('a MADE-UP name is counted and refused in the very same words (so the block gives nothing away), whatever capitals it is typed in', said($madeUp) === said($byName) && records(ip(127), 'LoginBlocked') === 1 && records(ip(127), 'LoginFailed') === 0, said($madeUp) . '; LoginBlocked: ' . records(ip(127), 'LoginBlocked'));
// "x-sil-oscar" CONTAINS "sil-oscar". Ten failures for the longer name must not touch the shorter one.
for ($i = 1; $i <= 5; $i++) {
    tryPassword(ip(128), 'x-' . login('oscar'), 'wrong-' . $i);
    tryPassword(ip(129), 'x-' . login('oscar'), 'wrong-' . $i);
}
$oscarJar = newJar();
$answer   = tryPassword(ip(130), login('oscar'), $password, $oscarJar);
ok('ten failures for a LONGER name that contains a real one do not block the real one: the name must match exactly', records(ip(128), 'LoginFailed') + records(ip(129), 'LoginFailed') === 10 && signedIn($answer) === true, 'failures for the longer name: ' . (records(ip(128), 'LoginFailed') + records(ip(129), 'LoginFailed')) . '; ' . describe($answer));
// A stranger blocks a name; its owner resets the password.
for ($i = 1; $i <= 5; $i++) {
    tryPassword(ip(131), login('mike'), 'wrong-' . $i);
    tryPassword(ip(132), login('mike'), 'wrong-' . $i);
}
$answer = tryPassword(ip(133), login('mike'), $password);
mailSince();
askForLink(ip(133), mailOf('mike'));
$link         = (string) (array_values(array_filter(mailSince(), static fn (array $m): bool => $m['link'] !== ''))[0]['link'] ?? '');
$mikePassword = bin2hex(random_bytes(12)) . 'Ff6!';
$reset        = useLink(ip(133), $link, $mikePassword);
$after        = tryPassword(ip(134), login('mike'), $mikePassword);
ok(
    'a name blocked by somebody else\'s ten wrong passwords is freed by its owner\'s completed reset: refused before it, signed in with the new password after it',
    str_starts_with(said($answer), 'danger: Too many failed attempts') === true && $reset['location'] === '/login?reset=1' && signedIn($after) === true,
    'before: ' . describe($answer) . '; reset: ' . describe($reset) . '; after: ' . describe($after)
);
ok('and nothing was deleted to do it: the ten failures are still on record', records(ip(131), 'LoginFailed') + records(ip(132), 'LoginFailed') === 10, 'on record: ' . (records(ip(131), 'LoginFailed') + records(ip(132), 'LoginFailed')));

// =============================================================================
head('M. A refusal takes as long for a name with no account as for one with');
// =============================================================================
// The fingerprint checked when there is no account must cost what a real one costs.
preg_match("/const NO_ACCOUNT_HASH = '([^']+)'/", (string) file_get_contents($webSrc . $sep . '_core' . $sep . 'Auth.php'), $found);
$costNone = password_get_info((string) ($found[1] ?? ''))['options']['cost'] ?? null;
$costReal = password_get_info(password_hash('x', PASSWORD_DEFAULT))['options']['cost'] ?? null;
ok('the made-up fingerprint checked for a name with no account has the cost this PHP gives a real password', $costNone !== null && $costNone === $costReal, 'made-up: ' . var_export($costNone, true) . '; a real one: ' . var_export($costReal, true));
// Seven tries of each kind, taken in turn so that a busy moment falls on all
// of them. Seven addresses, four tries each, so that none is blocked. The
// "empty password" try is refused by the page before any account is looked
// up: it shows what a try costs with NO password check at all, and is taken
// away from the others so that only the cost of the check is compared.
$times = ['real' => [], 'none' => [], 'off' => [], 'page' => []];
for ($i = 0; $i < 7; $i++) {
    $at              = ip(141 + $i);
    $times['real'][] = tryPassword($at, login('quebec'), 'wrong-' . $i)['ms'];
    $times['none'][] = tryPassword($at, 'sil-nobody-' . bin2hex(random_bytes(4)), 'wrong-' . $i)['ms'];
    $times['off'][]  = tryPassword($at, login('kilo'), 'wrong-' . $i)['ms'];
    $times['page'][] = tryPassword($at, login('quebec'), '')['ms'];
}
$middle = static function (array $ms): int {
    sort($ms);

    return (int) $ms[intdiv(count($ms), 2)];
};
$check = ['real' => $middle($times['real']) - $middle($times['page']), 'none' => $middle($times['none']) - $middle($times['page']), 'off' => $middle($times['off']) - $middle($times['page'])];
$told  = 'middle of 7, in ms: a real name ' . $middle($times['real']) . ', a made-up name ' . $middle($times['none']) . ', a switched-off account ' . $middle($times['off']) . ', no check at all ' . $middle($times['page']);
note($told);
ok('a wrong password for a real name costs a password check (the measure is working)', $check['real'] >= 20, $told);
ok('a MADE-UP name costs between half and twice as much: a check is made for it too', $check['real'] >= 20 && $check['none'] >= $check['real'] * 0.5 && $check['none'] <= $check['real'] * 2, $told);
ok('a SWITCHED-OFF account costs between half and twice as much as well', $check['real'] >= 20 && $check['off'] >= $check['real'] * 0.5 && $check['off'] <= $check['real'] * 2, $told);

// =============================================================================
head('N. Forty tries sent at the same moment: still no more than five passwords checked');
// =============================================================================
foreach ($extraPorts as $onePort) {
    $state['extra'][] = $startPortal($onePort);
}
$deadline = microtime(true) + 15;
$allUp    = false;
while ($allUp === false && microtime(true) < $deadline) {
    usleep(100000);
    $allUp = count(array_filter($extraPorts, 'portAnswers')) === count($extraPorts);
}
if ($allUp === false) {
    refuse('the seven extra portal servers for part N did not all answer');
}
$allPorts = array_merge([$port], $extraPorts);
$told     = [];
$worst    = 0;
$fewest   = 40;
$allTold  = true;
foreach ([[ip(151), 'papa'], [ip(152), 'romeo']] as [$at, $who]) {
    $tally   = burst($allPorts, $at, login($who), 40);
    $checked = checkedFrom($at);
    $worst   = max($worst, $checked);
    $fewest  = min($fewest, $checked);
    $allTold = $allTold && $tally['other'] === 0 && $tally['invalid'] === $checked && $tally['blocked'] === 40 - $checked;
    $told[]  = $checked . ' checked (' . $tally['invalid'] . ' "Invalid credentials", ' . $tally['blocked'] . ' "Too many failed attempts", ' . $tally['other'] . ' anything else)';
}
foreach (array_keys($state['extra']) as $n) {
    stopServer($state['extra'][$n]);
}
$state['extra'] = [];
note('eight portal servers, forty tries at one moment, twice: ' . implode('; ', $told));
ok('in both rounds no more than FIVE passwords were checked, and at least one was', $worst <= 5 && $fewest >= 1, implode('; ', $told));
ok('and every try was answered one way or the other: a checked password as "Invalid credentials", every other try as "Too many failed attempts"', $allTold === true, implode('; ', $told));
$answer = tryPassword(ip(151), login('papa'), $password);
ok('after it the address is blocked: the right password is refused', signedIn($answer) === false && str_starts_with(said($answer), 'danger: Too many failed attempts') === true, describe($answer));

// =============================================================================
head('O. "Forgot password" answers BEFORE it looks the address up');
// =============================================================================
mailSetup('google');
touch($child . $sep . 'standin-slow'); // the stand-in now takes four seconds to hand out a key
mailSince();
$known  = askForLink(ip(161), mailOf('alpha'));
$unknow = askForLink(ip(162), $unknown);
unlink($child . $sep . 'standin-slow');
$mail = mailSince();
note('with a mail service that takes 4 seconds: the answer for a known address took ' . $known['ms'] . ' ms, for an unknown one ' . $unknow['ms'] . ' ms');
ok('with a mail service that takes four seconds, a KNOWN address is answered in under two: the answer does not wait for the e-mail', $known['ms'] < 2000 && $known['location'] === '/forgot-password?sent=1' && shape($known) === shape($unknow), $known['ms'] . ' ms; ' . describe($known));
ok('and the e-mail is still sent afterwards', count($mail) === 2 && $mail[1]['to'] === mailOf('alpha') && $mail[1]['link'] !== '', paths($mail));

// =============================================================================
head('P. A visitor cannot choose the address their tries are counted under');
// =============================================================================
// The portal is told to believe a header from a machine that is NOT the one
// these requests come from. The header must then be ignored, whichever of the
// two headers the portal is set to read. The made-up address is sent in both.
foreach (['x-forwarded-for' => 'X-Forwarded-For', 'cf-connecting-ip' => 'CF-Connecting-IP'] as $headerSetting => $headerName) {
    $lastLog = (int) one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'];
    setting('portal.trustedProxies', '198.51.100.7');
    setting('portal.trustedProxyHeader', $headerSetting);
    $jar         = newJar();
    $form        = req($jar, 'GET', '/login', null, ip(171), ['CF-Connecting-IP: ' . ip(171)]);
    $answer      = req($jar, 'POST', '/login', ['csrf_token' => token($form['body']), 'username' => 'sil-nobody-here', 'password' => 'wrong'], ip(171), ['CF-Connecting-IP: ' . ip(171)]);
    $underHeader = (int) one('SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID > ? AND visitorIP = ?', 'is', [$lastLog, ip(171)])['n'];
    $underReal   = (int) one("SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID > ? AND visitorIP = '127.0.0.1' AND activityType IN ('LoginFailed', 'LoginBlocked')", 'i', [$lastLog])['n'];
    setting('portal.trustedProxies', '127.0.0.1');
    setting('portal.trustedProxyHeader', 'x-forwarded-for');
    q("DELETE FROM tblActivityLogs WHERE logID > ? AND visitorIP = '127.0.0.1' AND userAgent = ?", 'is', [$lastLog, AGENT]);
    ok('the portal set to read ' . $headerName . ': that header from a machine that is not on the trusted list is ignored, and the try is recorded under the address the connection really came from', $underHeader === 0 && $underReal === 1, 'recorded under the header\'s address: ' . $underHeader . '; under the real one: ' . $underReal . '; ' . describe($answer));
}

// =============================================================================
head('Q. An e-mail the provider REFUSES is not recorded as sent');
// =============================================================================
mailSetup('ms365');
touch($child . $sep . 'standin-refuse');
mailSince();
$sent = askForLink(ip(181), mailOf('alpha'));
unlink($child . $sep . 'standin-refuse');
$mail = mailSince();
ok(
    'the provider said no: the record says the e-mail FAILED, not that it was sent, and the person is told the same as always',
    count($mail) === 2 && records(ip(181), 'PasswordResetMailFailed') === 1 && records(ip(181), 'PasswordResetEmailed') === 0 && shape($sent) === shape($normal),
    'stand-in: ' . paths($mail) . '; PasswordResetMailFailed: ' . records(ip(181), 'PasswordResetMailFailed') . '; PasswordResetEmailed: ' . records(ip(181), 'PasswordResetEmailed')
);

// =============================================================================
head('R. The e-mail settings page is for administrators only');
// =============================================================================
mailSetup('none');
$answer = req($oscarJar, 'GET', '/admin/integrations/email', null, ip(130));
$home   = req($oscarJar, 'GET', '/account', null, ip(130));
ok('an ordinary member who is signed in is refused the page (403) and shown none of its notice', $home['status'] === 200 && $answer['status'] === 403 && str_contains($answer['body'], 'data-mail-status') === false && str_contains($answer['body'], 'cannot send e-mail') === false, 'their own account page: HTTP ' . $home['status'] . '; the e-mail page: HTTP ' . $answer['status']);

// =============================================================================
head('S. A reset link lasts its 60 minutes on the nights the clocks change');
// =============================================================================
// The portal's own two functions, on a connection of their own whose clock
// this part sets (SET TIMESTAMP changes what NOW() and UTC_TIMESTAMP() say on
// that connection only). The connection and PHP are both put in the zone
// whose clocks are changing, so anything read on either of those clocks
// would show.
require_once $webSrc . $sep . '_core' . $sep . 'Auth.php';
$clock = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $dbName, $creds['db_port']);
/** Set one thing on that connection: its time zone, or the moment its clock says it is. */
$setClock = static function (string $sql, string $type, string|int $value) use ($clock): void {
    $stmt = $clock->prepare($sql);
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
        $wrong = [];
        // A link asked for 70 and 10 minutes before the change, and 30 minutes after it.
        foreach ([-70, -10, 30] as $askedAt) {
            $asked    = (int) $change['ts'] + $askedAt * 60;
            $linkText = bin2hex(random_bytes(32));
            $setClock('SET TIMESTAMP = ?', 'i', $asked);
            \Portal\Core\Auth::storeResetLink($clock, PEOPLE['november'], $linkText, 60, 'selftest');
            // Looked at 59, 61, 90, 125 and 185 minutes later: good at 59, never after.
            foreach ([59 => 'usable', 61 => 'expired', 90 => 'expired', 125 => 'expired', 185 => 'expired'] as $later => $expected) {
                $setClock('SET TIMESTAMP = ?', 'i', $asked + $later * 60);
                $got = \Portal\Core\Auth::findResetLink($clock, $linkText)['state'];
                if ($got !== $expected) {
                    $wrong[] = 'asked ' . $askedAt . ' min from the change, looked at ' . $later . ' min later: ' . $got . ', expected ' . $expected;
                }
            }
        }
        ok(
            $changing . ', the night the clocks go ' . ($change['isdst'] === true ? 'FORWARD' : 'BACK') . ' (' . gmdate('j F Y H:i', (int) $change['ts']) . ' UTC): a link is good at 59 minutes and not at 61, 90, 125 or 185, asked before or after the change',
            $wrong === [],
            implode('; ', $wrong)
        );
    }
}
date_default_timezone_set($phpZone);
$clock->close();

// =============================================================================
head('Nothing went wrong behind the pages, and everything is put back');
// =============================================================================
$log = (string) @file_get_contents($child . $sep . 'php-errors.log');
$bad = array_values(array_filter((array) preg_split('/\R/', $log), static fn (string $line): bool => preg_match('/PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught/', $line) === 1));
ok('the server\'s PHP error log holds no warning, notice or fatal error', $bad === [], count($bad) . ' found: ' . implode(' | ', array_map(static fn (string $line): string => mb_substr($line, 0, 220), array_slice($bad, 0, 4))));

$before = $state['settings'];
cleanup();
$left = (int) one(
    "SELECT (SELECT COUNT(*) FROM tblUsers WHERE userID BETWEEN 919000 AND 919999) + (SELECT COUNT(*) FROM tblLocalAccounts WHERE userID BETWEEN 919000 AND 919999) "
    . "+ (SELECT COUNT(*) FROM tblActivityLogs WHERE visitorIP LIKE '203.0.113.%') + (SELECT COUNT(*) FROM tblPasswordResets WHERE userID BETWEEN 919000 AND 919999) "
    . "+ (SELECT COUNT(*) FROM tblEmailLog WHERE toRecipients LIKE '%.signin@selftest.invalid%') + (SELECT COUNT(*) FROM tblApiRateLimits WHERE bucket LIKE 'pwreset:%') AS n"
)['n'];
$settingsBack = true;
foreach ($before as $key => $row) {
    $settingsBack = $settingsBack && (one('SELECT settingID, settingValue, isSensitive FROM tblSettings WHERE settingKey = ? AND siteID IS NULL', 's', [(string) $key]) === $row);
}
ok('clean-up: the made-up accounts and their records are gone, and every setting is as it was', $left === 0 && $settingsBack === true, 'rows left: ' . $left . '; settings back: ' . ($settingsBack === true ? 'yes' : 'no'));
ok('clean-up: both servers are stopped and the scratch folder is deleted', portAnswers($port) === false && portAnswers($mailPort) === false && file_exists($child) === false);

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
echo "REACHED THE END — the sign-in limits self-test ran every part.\n";
exit(0);
