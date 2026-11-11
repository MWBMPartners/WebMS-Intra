<?php
// Path: tools/activity-description-selftest.php
/**
 * -----------------------------------------------------------------------------
 * An activity record names its person by NUMBER, never in its words, on the
 * REAL pages — self-test 🔏 (#933)
 * -----------------------------------------------------------------------------
 * WHAT THIS HOLDS IN PLACE
 * ------------------------
 * An erasure empties an activity record's account number, network address,
 * browser name and session copy, and keeps its DESCRIPTION as written (#626:
 * a record names its person in `userID`, never in its words). Under D-117 an
 * installation may keep the log for ever. Until #933, 53 writers put a
 * person's details into those words — the e-mail address on every password
 * reset and provider link, a registrant's name, a new member's name and
 * address, among others — so those details outlived every erasure. Many of
 * the records belong to whoever ACTED (an administrator), so erasing the
 * person in the words could never reach them at all. 41 now write numbers and
 * fixed words. A failed sign-in keeps the sign-in name that was typed,
 * readable, on purpose (the owner, D-137), and an organisation's own labels
 * stay (D-138).
 *
 * Nothing fails while that is so: every page works. So this file drives the
 * real pages and reads what they stored.
 *
 * WHAT IT DOES
 * ------------
 * It copies `web/` into a NEW scratch folder with a throwaway connection file
 * and key, and starts PHP's built-in web server on it behind a small router
 * that imitates `.htaccess` (the harness of `erasure-pages-selftest.php`). No
 * file of the portal is changed in the copy. Then:
 *
 *   A  A wrong password for a real person, and a made-up name: each failure
 *      record keeps the sign-in name, readable (D-137), and nothing else of the
 *      person — not their full name, not their e-mail address.
 *   B  The same person asks for a password reset by e-mail address: the
 *      record carries their account number in its words AND in its own
 *      column (so an erasure reaches it), and not the address.
 *   C  A signed-in member registers somebody for an event by name: the record
 *      carries the registration's number, not the name.
 *   D  A global administrator creates an account on the Users page: the record
 *      carries the new account's number, not its name or e-mail address.
 *   E  The same administrator adds a person to an organisation on the
 *      organisation's member list: numbers only (it always was; driven
 *      because the issue named it).
 *   F  The member removes a sign-in provider link on their own account page:
 *      numbers and the provider's name, never the provider's e-mail address,
 *      and their account in the record's own column.
 *   G  A provider link being MADE, which only happens inside a Microsoft or
 *      Google sign-in: no test here can drive a real provider, so
 *      `Auth::linkAccount()` is called in a separate PHP process that loads
 *      the scratch copy's own start-up, exactly as a page does.
 *   Z  Every record this run wrote, every column but `userID` and
 *      `visitorIP`: none holds any of the names or e-mail addresses the run
 *      typed, except the sign-in names D-137 keeps in the words of a failed
 *      sign-in. (`visitorIP` is the one column made to hold a network address,
 *      and an erasure empties it.)
 *
 * WHAT IT CANNOT SHOW
 * -------------------
 * - The other changed writers are not driven (this drives five of the 41);
 *   they are held by `tools/audit-checks/check_activity_descriptions_personal.py`,
 *   which reads the code and names the three it cannot see.
 * - A real Microsoft or Google sign-in (part G calls the method instead).
 * - Records written BEFORE #933. No customer installation exists yet (D-135),
 *   so there are none; on an installation that had them, they would stay
 *   until the log's own clear-out removed them, or for ever under D-117.
 * - Apache: the router only imitates `.htaccess`.
 *
 * SAFETY
 * ------
 * - SELFTEST_DB_NAME must start with selftest_; the made-up rows use numbers
 *   933xxx and are removed at the start and the end, together with EVERY
 *   activity record and rate-limit hit written while it ran (a failed
 *   sign-in names nobody, so nothing else could find those). So it must run
 *   ALONE on its database.
 * - The scratch folder is a NEW child of SELFTEST_WEB_DIR (or the system's
 *   temporary folder), refused when it and the repository contain each
 *   other, and deleted at the end only while it holds this run's marker.
 *   `web/_auth_keys` is never read or copied.
 * - The server is started with its command as a list (no shell), stopped by
 *   its own process number, and refuses to start if its port answers.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/activity-description-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default
 *         root), SELFTEST_WEB_PORT (default 9933), SELFTEST_WEB_DIR. The
 *         database must already hold `web/_sql/full_schema.sql`.
 * Exit:   0 only when every check ran and passed; 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/933
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 16;

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
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
$port    = ($portRaw === false || $portRaw === '') ? 9933 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
$db = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $dbName, $creds['db_port']);
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
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 900)) . "\n";
}

function head(string $text): void
{
    echo "\n=== " . $text . " ===\n";
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
// 🗺️ The made-up world (numbers 933xxx; everything typed is unmistakable)
// =============================================================================
const ROOT  = 933001; // a global administrator
const PIA   = 933002; // a member with a password; fails a sign-in, asks for a reset, registers somebody, unlinks a provider
const QUIN  = 933003; // a person in NO organisation, added to one on the member list
const EVENT = 933101; // a public event open for registration
const LINK  = 933201; // Pia's Google link, removed in part F

const PIA_NAME      = 'Pia Privacyperson';
const PIA_EMAIL     = 'pia.privacy933@selftest.invalid';
const PIA_LOGIN     = 'pia-privacy-933';
const GOOGLE_EMAIL  = 'pia.google933@selftest.invalid';
const MS_EMAIL      = 'pia.microsoft933@selftest.invalid';
const MADE_UP_LOGIN = 'nobody.here933@selftest.invalid';
const REGISTRANT    = 'Rory Registrantchild';
const PARENT_EMAIL  = 'rory.parent933@selftest.invalid';
const NEW_NAME      = 'Uma Newmemberson';
const NEW_EMAIL     = 'uma.newmember933@selftest.invalid';
const QUIN_NAME     = 'Quin Quietperson';
const QUIN_EMAIL    = 'quin.quiet933@selftest.invalid';
const SLUG          = 'selftest-933-holiday-club';

/** Every piece of personal text this run types or seeds. Part Z looks for each one, in lower case. */
const PERSONAL_TEXTS = [PIA_NAME, PIA_EMAIL, PIA_LOGIN, GOOGLE_EMAIL, MS_EMAIL, MADE_UP_LOGIN, REGISTRANT, PARENT_EMAIL, NEW_NAME, NEW_EMAIL, QUIN_NAME, QUIN_EMAIL];

/** Remove every row this test makes. Run first (a killed run leaves rows) and last. */
function removeWorld(): void
{
    $people = [ROOT, PIA, QUIN];
    $new    = array_map('intval', array_column(q('SELECT userID FROM tblUsers WHERE emailAddress = ?', 's', [NEW_EMAIL]), 'userID'));
    $all    = array_merge($people, $new);
    $marks  = implode(',', array_fill(0, count($all), '?'));
    $types  = str_repeat('i', count($all));
    q('DELETE FROM tblEventRegistrations WHERE eventID = ?', 'i', [EVENT]);
    q('DELETE FROM tblEvents WHERE eventID = ?', 'i', [EVENT]);
    foreach (['tblLinkedAccounts', 'tblLocalAccounts', 'tblUserSites', 'tblUserRoles', 'tblPasswordResets', 'tblActivityLogs'] as $table) {
        q("DELETE FROM `{$table}` WHERE `userID` IN ({$marks})", $types, $all);
    }
    q("DELETE FROM `tblUsers` WHERE `userID` IN ({$marks})", $types, $all);
    // The per-person reset limit (three an hour) is a bucket named by account.
    q("DELETE FROM `tblApiRateLimits` WHERE `bucket` LIKE 'pwreset:person:933%'");
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'started' => false, 'cleaned' => false, 'logMark' => null, 'hitMark' => null];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

/** Put back everything this run changed, ONCE. Also registered as a shutdown function. */
function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        $until = microtime(true) + 5;
        while (proc_get_status($state['proc'])['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($state['proc'])['running'] === true) {
            proc_terminate($state['proc'], 9);
        }
        proc_close($state['proc']);
        $state['proc'] = null;
    }
    try {
        removeWorld();
        // 🧹 Everything else this run wrote. A failed sign-in names NOBODY in
        //    its column (that is the point of part A), so removeWorld() cannot
        //    find those records; and the address limits count them. Left in
        //    place, a third run within 15 minutes met "too many attempts" and
        //    refused to sign anybody in. So every activity record and every
        //    rate-limit hit written after this run began goes. That is why this
        //    test must run ALONE on its database, like access-pages-selftest.
        if ($state['logMark'] !== null) {
            q('DELETE FROM `tblActivityLogs` WHERE `logID` > ?', 'i', [(int) $state['logMark']]);
        }
        if ($state['hitMark'] !== null) {
            q('DELETE FROM `tblApiRateLimits` WHERE `hitID` > ?', 'i', [(int) $state['hitMark']]);
        }
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.activity-description-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started. Redirects are never
 * followed. An answer without this run's header came from somewhere else and
 * is returned as status -1, which no check expects.
 *
 * @param array<string,mixed>|null $post
 *
 * @return array{status:int, location:string, body:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Activity-Description-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** The activity records written since `$mark`, of one type. @return list<array<string,mixed>> */
function written(int $mark, string $type): array
{
    return q('SELECT * FROM tblActivityLogs WHERE logID > ? AND activityType = ? ORDER BY logID', 'is', [$mark, $type]);
}

/**
 * The first personal text found in one record's words, or '' when none is.
 *
 * @param list<string> $allowed texts kept on purpose in THIS record (D-137)
 */
function personalIn(string $words, array $allowed = []): string
{
    foreach (PERSONAL_TEXTS as $text) {
        if (in_array($text, $allowed, true) === true) {
            continue;
        }
        if (str_contains(mb_strtolower($words), mb_strtolower($text)) === true) {
            return $text;
        }
    }

    return '';
}

/** Highest log number now: everything after it was written by this run. */
function lastLog(): int
{
    return (int) (one('SELECT COALESCE(MAX(logID), 0) AS n FROM tblActivityLogs')['n'] ?? 0);
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$state['started'] = true;
register_shutdown_function('cleanup');
if (function_exists('pcntl_async_signals') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (int $signal): void {
            echo "\nFAIL — stopped by signal {$signal}; cleaning up before exiting.\n";
            exit(1);
        });
    }
}
removeWorld();
$state['logMark'] = (int) (one('SELECT COALESCE(MAX(`logID`), 0) AS `n` FROM `tblActivityLogs`')['n'] ?? 0);
$state['hitMark'] = (int) (one('SELECT COALESCE(MAX(`hitID`), 0) AS `n` FROM `tblApiRateLimits`')['n'] ?? 0);

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 1, 1)', 'iss', [ROOT, 'Rae Rootadmin', 'rae.root933@selftest.invalid']);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [PIA, PIA_NAME, PIA_EMAIL]);
q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [QUIN, QUIN_NAME, QUIN_EMAIL]);
q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, 1, 1, 1, 1), (?, 1, 1, 0, 0)', 'ii', [ROOT, PIA]);
q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1), (?, ?, ?, 1)', 'ississ', [ROOT, 'rae-root-933', $hash, PIA, PIA_LOGIN, $hash]);
q('INSERT INTO tblLinkedAccounts (linkID, userID, provider, providerSub, providerEmail) VALUES (?, ?, ?, ?, ?)', 'iisss', [LINK, PIA, 'google', 'selftest-933-sub', GOOGLE_EMAIL]);
q(
    "INSERT INTO tblEvents (eventID, siteID, eventName, eventSlug, startDateTime, endDateTime, timezone, status, isPublic, registrationEnabled) "
    . "VALUES (?, 1, 'Selftest 933 holiday club', ?, DATE_ADD(NOW(), INTERVAL 20 DAY), DATE_ADD(NOW(), INTERVAL 20 DAY) + INTERVAL 3 HOUR, 'UTC', 'published', 1, 1)",
    'is',
    [EVENT, SLUG]
);

// The scratch copy of web/, with a throwaway connection file and key.
$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'activity-description-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.activity-description-selftest-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/activity-description-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router: imitates web/public_html/.htaccess and marks every answer.
$router = <<<'ROUTER'
<?php
// Written by tools/activity-description-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Activity-Description-Selftest: __TOKEN__');
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
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

$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc']     = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server-output.log', 'a'], 2 => ['file', $child . $sep . 'server-output.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    refuse('the web server could not be started');
}
$deadline = microtime(true) + 10;
while (portAnswers($port) === false && microtime(true) < $deadline) {
    usleep(100000);
}
if (portAnswers($port) === false) {
    refuse('the web server never answered on port ' . $port);
}

echo "Activity records name their person by number, on the real pages — self-test (#933)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

$firstMark = lastLog();
$jarOf     = static fn (string $who): string => $child . $sep . 'jar-' . $who . '.txt';
$signIn    = static function (string $who, string $login, string $pw) use ($jarOf): array {
    $tok = token(req($jarOf($who), 'GET', '/login')['body']);

    return req($jarOf($who), 'POST', '/login', ['csrf_token' => $tok, 'username' => $login, 'password' => $pw]);
};

// =============================================================================
head('A. A failed sign-in keeps the name that was typed, and only that (D-137)');
// =============================================================================
$mark = lastLog();
$signIn('stranger1', PIA_LOGIN, 'wrong-' . bin2hex(random_bytes(4)));
$signIn('stranger2', MADE_UP_LOGIN, 'wrong-' . bin2hex(random_bytes(4)));
$failed = written($mark, 'LoginFailed');
$words  = array_column($failed, 'activityDescription');
// The owner decided (D-137, 11 November 2026) that a failed sign-in keeps the
// sign-in name READABLE: an administrator looking into repeated failures needs
// to see which name was tried, and the limit for one name matches these exact
// words. #933's first build replaced the name with a fingerprint; that was
// withdrawn. So A1 pins the readable words, and A2 pins that NOTHING ELSE
// about the person rides along with them.
ok(
    'A1 a wrong password for a real person writes "Incorrect password for: <the account\'s sign-in name>", and a made-up name "Failed login attempt for: <what was typed>" (D-137)',
    count($failed) === 2
        && ($words[0] ?? '') === 'Incorrect password for: ' . PIA_LOGIN
        && ($words[1] ?? '') === 'Failed login attempt for: ' . MADE_UP_LOGIN,
    json_encode($words)
);
ok(
    'A2 neither record holds the person\'s full name or e-mail address: only the sign-in name D-137 keeps',
    count($failed) === 2 && personalIn(implode(' | ', $words), [PIA_LOGIN, MADE_UP_LOGIN]) === '',
    json_encode($words)
);
ok(
    'A3 both name nobody in their own column, so an erasure cannot reach them: the cost D-137 accepted, pinned so a change to it is a decision',
    count($failed) === 2 && $failed[0]['userID'] === null && $failed[1]['userID'] === null,
    json_encode(array_column($failed, 'userID'))
);

// =============================================================================
head('B. A password reset names the account by number');
// =============================================================================
$mark = lastLog();
$tok  = token(req($jarOf('resetter'), 'GET', '/forgot-password')['body']);
req($jarOf('resetter'), 'POST', '/forgot-password/save', ['csrf_token' => $tok, 'email' => PIA_EMAIL]);
// The reset page gives the browser its answer FIRST and does the work after
// (so the time taken cannot say whether an address has an account, #521), so
// the records are written after the answer has arrived. Waiting for the final
// record (the e-mail's outcome, or "no mail route") rather than reading at once.
$reset    = [];
$deadline = microtime(true) + 20;
do {
    $reset = q("SELECT * FROM tblActivityLogs WHERE logID > ? AND activityType LIKE 'PasswordReset%' ORDER BY logID", 'i', [$mark]);
    $ended = array_intersect(array_column($reset, 'activityType'), ['PasswordResetEmailed', 'PasswordResetMailWaiting', 'PasswordResetMailFailed', 'PasswordResetNoMailer', 'PasswordResetNoUser', 'PasswordResetBlocked']) !== [];
    if ($ended === false) {
        usleep(200000);
    }
} while ($ended === false && microtime(true) < $deadline);
$resetWords = array_column($reset, 'activityDescription');
ok(
    'B1 the reset was recorded for the account (at least one PasswordReset… record, and not "unknown email")',
    $reset !== [] && in_array('PasswordResetNoUser', array_column($reset, 'activityType'), true) === false,
    json_encode(array_map(static fn ($r) => $r['activityType'] . ': ' . $r['activityDescription'], $reset))
);
ok(
    'B2 every one of them carries "account #' . PIA . '" in its words AND the account in its own column, so an erasure reaches it',
    $reset !== [] && array_filter($reset, static fn ($r) => str_contains((string) $r['activityDescription'], 'account #' . PIA) === false || (int) $r['userID'] !== PIA) === [],
    json_encode(array_map(static fn ($r) => [$r['userID'], $r['activityDescription']], $reset))
);
ok(
    'B3 none of them holds the e-mail address that was typed',
    $reset !== [] && personalIn(implode(' | ', $resetWords)) === '',
    json_encode($resetWords)
);

// =============================================================================
head('C. An event registration names the registration by number');
// =============================================================================
$in = $signIn('pia', PIA_LOGIN, $password);
if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
    refuse('signing in as ' . PIA_LOGIN . ' did not work (HTTP ' . $in['status'] . ' ' . $in['location'] . ')');
}
$mark = lastLog();
$form = req($jarOf('pia'), 'GET', '/calendar/event/register?slug=' . SLUG);
$sent = req($jarOf('pia'), 'POST', '/calendar/event/register/save', [
    'csrf_token' => token($form['body']), 'slug' => SLUG, 'fullName' => REGISTRANT, 'parentEmail' => PARENT_EMAIL,
]);
$regRow = one('SELECT registrationID FROM tblEventRegistrations WHERE eventID = ? ORDER BY registrationID DESC LIMIT 1', 'i', [EVENT]);
$reg    = written($mark, 'EventRegistrationReceived');
ok(
    'C1 the registration was really stored (the form and its handler were reached)',
    $regRow !== null && count($reg) === 1,
    'form HTTP ' . $form['status'] . ', save HTTP ' . $sent['status'] . ' ' . $sent['location'] . '; stored: ' . json_encode($regRow) . '; ' . mb_substr(strip_tags($sent['body']), 0, 300)
);
ok(
    'C2 the record says "Event #' . EVENT . ' registration #<its number>" and nothing more',
    count($reg) === 1 && $regRow !== null && $reg[0]['activityDescription'] === 'Event #' . EVENT . ' registration #' . (int) $regRow['registrationID'],
    json_encode(array_column($reg, 'activityDescription'))
);
ok(
    'C3 it does not hold the registrant\'s name or the parent\'s e-mail address',
    count($reg) === 1 && personalIn((string) $reg[0]['activityDescription']) === '',
    json_encode(array_column($reg, 'activityDescription'))
);

// =============================================================================
head('D. A new account, made by an administrator, is named by number');
// =============================================================================
$in = $signIn('root', 'rae-root-933', $password);
if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
    refuse('signing in as the administrator did not work (HTTP ' . $in['status'] . ' ' . $in['location'] . ')');
}
$mark  = lastLog();
$users = req($jarOf('root'), 'GET', '/admin/users');
$made  = req($jarOf('root'), 'POST', '/admin/users/save', [
    'csrf_token' => token($users['body']), 'action' => 'create', 'fullName' => NEW_NAME, 'emailAddress' => NEW_EMAIL, 'isActive' => '1',
]);
$newRow  = one('SELECT userID FROM tblUsers WHERE emailAddress = ?', 's', [NEW_EMAIL]);
$created = written($mark, 'UserCreated');
ok(
    'D1 the account was made, and its record says "Created user #<its number>", written as the administrator',
    $newRow !== null && count($created) === 1 && $created[0]['activityDescription'] === 'Created user #' . (int) $newRow['userID'] && (int) $created[0]['userID'] === ROOT,
    'HTTP ' . $made['status'] . ' ' . $made['location'] . '; new row ' . json_encode($newRow) . '; records ' . json_encode($created)
);
ok(
    'D2 the record holds neither the new member\'s name nor their e-mail address (erasing them could never reach a record that is the administrator\'s)',
    count($created) === 1 && personalIn((string) $created[0]['activityDescription']) === '',
    json_encode(array_column($created, 'activityDescription'))
);

// =============================================================================
head('E. The organisation\'s member list names people by number');
// =============================================================================
$mark   = lastLog();
$list   = req($jarOf('root'), 'GET', '/admin/sites/users?site=1');
$added  = req($jarOf('root'), 'POST', '/admin/sites/users?site=1', ['csrf_token' => token($list['body']), 'action' => 'add', 'userID' => (string) QUIN]);
$addRec = written($mark, 'SiteUserAdd');
ok(
    'E1 adding a person writes "Added user #' . QUIN . ' to site #1", with no name or e-mail address',
    count($addRec) === 1 && $addRec[0]['activityDescription'] === 'Added user #' . QUIN . ' to site #1' && personalIn((string) $addRec[0]['activityDescription']) === '',
    'list HTTP ' . $list['status'] . ', add HTTP ' . $added['status'] . '; records ' . json_encode(array_column($addRec, 'activityDescription'))
);

// =============================================================================
head('F. Removing a sign-in provider link');
// =============================================================================
$mark    = lastLog();
$account = req($jarOf('pia'), 'GET', '/account');
$gone    = req($jarOf('pia'), 'POST', '/account/unlink', ['csrf_token' => token($account['body']), 'linkID' => (string) LINK]);
$unlink  = written($mark, 'AccountUnlink');
ok(
    'F1 the link went, and the record says "Unlinked a google sign-in (link #' . LINK . ') from account #' . PIA . '", in the account\'s own column too',
    one('SELECT linkID FROM tblLinkedAccounts WHERE linkID = ?', 'i', [LINK]) === null
        && count($unlink) === 1
        && $unlink[0]['activityDescription'] === 'Unlinked a google sign-in (link #' . LINK . ') from account #' . PIA
        && (int) $unlink[0]['userID'] === PIA,
    'HTTP ' . $gone['status'] . ' ' . $gone['location'] . '; records ' . json_encode($unlink)
);
ok(
    'F2 it does not hold the provider\'s e-mail address',
    count($unlink) === 1 && personalIn((string) $unlink[0]['activityDescription']) === '',
    json_encode(array_column($unlink, 'activityDescription'))
);

// =============================================================================
head('G. Making a sign-in provider link (the method, in the portal\'s own start-up)');
// =============================================================================
// No test can drive a real Microsoft or Google sign-in, which is the only
// place linkAccount() is called. So a separate PHP process loads the scratch
// copy's start-up — the same file every page loads — and calls the method,
// with no session: exactly the state a sign-in callback is in when it links.
$mark   = lastLog();
$script = $child . $sep . 'link-once.php';
file_put_contents($script, "<?php\n// Written by tools/activity-description-selftest.php for one run.\n"
    . "\$_SERVER['REQUEST_URI'] = '/'; \$_SERVER['HTTP_HOST'] = '127.0.0.1'; \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
    . 'require ' . var_export($child . $sep . 'web' . $sep . '_core' . $sep . 'bootstrap.php', true) . ";\n"
    . 'echo \Portal\Core\Auth::linkAccount(' . PIA . ", 'ms365', 'selftest-933-ms-sub', " . var_export(MS_EMAIL, true) . ", \$mysqli) === true ? 'LINKED' : 'NOT LINKED';\n");
$out  = [];
exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' 2>&1', $out, $code);
$link = written($mark, 'AccountLink');
ok(
    'G1 linking writes "Linked a ms365 sign-in to account #' . PIA . '", in the account\'s own column, with no e-mail address — although nobody is signed in',
    str_contains(implode("\n", $out), 'LINKED') === true && count($link) === 1
        && $link[0]['activityDescription'] === 'Linked a ms365 sign-in to account #' . PIA
        && (int) $link[0]['userID'] === PIA
        && personalIn((string) $link[0]['activityDescription']) === '',
    'exit ' . $code . ': ' . mb_substr(implode("\n", $out), 0, 500) . '; records ' . json_encode($link)
);

// =============================================================================
head('Z. Nothing this run typed is in any column of any record it wrote');
// =============================================================================
// Every column but userID (the account NUMBER, which is the point) and
// visitorIP (the one column made to hold a network address; an erasure
// empties it). requestHeaders and the session copy are included on purpose:
// a name could reach a record that way just as well as through its words.
$rows    = q('SELECT * FROM tblActivityLogs WHERE logID > ? ORDER BY logID', 'i', [$firstMark]);
$leaks   = [];
foreach ($rows as $row) {
    foreach ($row as $column => $value) {
        if (in_array($column, ['userID', 'visitorIP'], true) === true || $value === null) {
            continue;
        }
        // D-137: a failed or rate-limited sign-in keeps the sign-in name in
        //    its words, and nothing else of the person.
        $keptHere = (in_array($row['activityType'], ['LoginFailed', 'LoginBlocked'], true) === true && $column === 'activityDescription')
            ? [PIA_LOGIN, MADE_UP_LOGIN]
            : [];
        $found = personalIn((string) $value, $keptHere);
        if ($found !== '') {
            $leaks[] = $row['activityType'] . '.' . $column . ' holds "' . $found . '"';
        }
    }
}
ok(
    'Z1 ' . count($rows) . ' records were written by this run, and none holds a name, e-mail address or sign-in name it typed, except the sign-in names D-137 keeps in the failed sign-ins\' words',
    count($rows) >= 10 && $leaks === [],
    'records: ' . count($rows) . '; ' . implode('; ', $leaks)
);

// =============================================================================
echo "\n" . $pass . ' passed, ' . $fail . ' failed, ' . ($pass + $fail) . ' of ' . EXPECTED_CHECKS . " expected.\n";
exit(($fail === 0 && $pass + $fail === EXPECTED_CHECKS) ? 0 : 1);
