<?php
// Path: tools/offboarding-return-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A person brought back after offboarding: erasure cancelled, sweep refuses,
 * old session dead — self-test on the REAL pages 🔙 (#1152, #1155)
 * -----------------------------------------------------------------------------
 * WHAT IT PROVES (real MySQL, real HTTP, one worker)
 *   A. Offboard with erasure, then tick Active on Admin, Users: the waiting
 *      erasure request is cancelled, the offboarding row is marked rehired by
 *      that administrator, and the offboarding list no longer offers Rehire.
 *      Rehire still does the same (same code, ReturnedMember).
 *   B. A suspended (never offboarded) member who filed their own erasure
 *      request keeps it when switched back on: the tick is not a decision to
 *      cancel it.
 *   C. THE NIGHTLY SWEEP (mode `after_undo_window`, run through the real job
 *      address with its token): with the window passed it erases an
 *      offboarded person who is still gone, and NEVER one whose account is
 *      switched on, even when the request still says `pending_review` and the
 *      offboarding row is unmarked (the state the tick left before #1152). The
 *      claim itself refuses an active account (checked with a trigger that
 *      switches the account on between selection and claim).
 *   D. A browser signed in BEFORE offboarding is refused after the person is
 *      brought back by the Active tick and by Rehire, before it ever made
 *      another request in between. The browser is NOT signed in afterwards.
 *   E. Plants: each fix put back to its old form turns its check red; every
 *      planted file is restored byte for byte.
 *
 * WHAT IT CANNOT PROVE: two requests at once (one worker); that a real
 * scheduler calls the job.
 *
 * SAFETY: like offboarding-return-selftest.php. Needs a database named selftest_*,
 * numbers 918400-918599 and one made-up organisation; its own scratch copy of
 * web/; its own server, stopped by process handle.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             SELFTEST_WEB_PORT=9172 php tools/offboarding-return-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes and erases rows");
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
    refuse('the scratch parent folder and the repository must not contain each other');
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9172 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
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
// 🗺️ The made-up world
// =============================================================================
// ONE-ORGANISATION portal, the pilot's shape: the Active tick only reaches an offboarded person there (D-213);
// with several organisations AccountGuard answers "not found" for an ended membership on that page.
const ORG_A = 1;
const KEY_A = 'default';
const ADM = 918411; // an administrator of A
const AAA = 918421; // offboarded with erasure, brought back by the Active tick
const BBB = 918422; // offboarded with erasure, brought back by Rehire
const CCC = 918423; // suspended, never offboarded, has a request of their own
const DDD = 918424; // offboarded with erasure, still gone: the sweep's control
const EEE = 918425; // sweep: account switched on, request left waiting (the pre-fix state)
const FFF = 918426; // sweep: account switched on between selection and claim (trigger)
const JOB_TOKEN = 'selftest-return-cron-token';

function addr(string $key): string
{
    return '/' . $key;
}

function removeWorld(): void
{
    global $db;
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $db->query('DROP TRIGGER IF EXISTS `selftest_return_flip`');
    $db->query('DELETE FROM tblErasureAudit WHERE requestID IN (SELECT requestID FROM tblErasureRequest WHERE userID BETWEEN 918400 AND 918499)');
    $db->query('DELETE FROM tblErasureRequest WHERE userID BETWEEN 918400 AND 918499');
    $db->query('DELETE FROM tblOffboarding WHERE userID BETWEEN 918400 AND 918499');
    $db->query('DELETE FROM tblLocalAccounts WHERE userID BETWEEN 918400 AND 918499');
    $db->query('DELETE FROM tblUserSites WHERE userID BETWEEN 918400 AND 918499');
    $db->query('DELETE FROM tblActivityLogs WHERE userID BETWEEN 918400 AND 918499');
    $db->query("DELETE FROM tblSettings WHERE siteID = " . ORG_A . " AND settingKey IN ('offboarding.enabled', 'site.name')");
    $db->query('DELETE FROM tblUsers WHERE userID BETWEEN 918400 AND 918499');
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

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

function siteSetting(int $site, string $key, string $value): void
{
    q('DELETE FROM tblSettings WHERE siteID = ? AND settingKey = ?', 'is', [$site, $key]);
    q('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES (?, ?, ?, ?, 0)', 'isss', [$site, $key, $value, $value]);
}

// =============================================================================
// 🧰 The server, and one request
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false, 'planted' => []];

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

function cleanup(): void
{
    global $state;
    if ($state['started'] === false || $state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    // A planted file is put back first, from the copy taken before planting.
    foreach ($state['planted'] as $path => $original) {
        file_put_contents((string) $path, (string) $original);
    }
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
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.offboarding-return-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/** @param array<string,mixed>|null $post @return array{status:int, location:string, body:string} */
function req(string $jar, string $method, string $path, ?array $post = null, string $address = ''): array
{
    global $port, $state;
    // '-' means no organisation part at all: the address of a ONE-organisation portal.
    $address = $address === '' ? '' : ($address === '-' ? '' : $address);
    $handle  = curl_init('http://127.0.0.1:' . $port . $address . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180,
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
    if (preg_match('/^X-Return-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

function text(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

/** What a person reads on a page: the body only, tags and scripts gone. */
function bodyText(string $html): string
{
    $body = preg_match('/<body[^>]*>(.*)<\/body>/s', $html, $m) === 1 ? $m[1] : $html;
    $body = (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $body);

    return text($body);
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

$people = [ADM => 'Ada Admin', AAA => 'Aaron Aaa', BBB => 'Bella Bbb', CCC => 'Cleo Ccc', DDD => 'Dan Ddd', EEE => 'Eve Eee', FFF => 'Finn Fff'];
foreach ($people as $id => $name) {
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, $name, 'u' . $id . '.return918@selftest.invalid']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, ?, 0)', 'iii', [$id, ORG_A, $id === ADM ? 1 : 0]);
}
setting('multisite.enabled', 'false');
siteSetting(ORG_A, 'offboarding.enabled', 'true');
siteSetting(ORG_A, 'site.name', 'Selftest return A');
setting('maintenance.cronToken', JOB_TOKEN);
setting('offboarding.erasureMode', 'after_undo_window');
setting('offboarding.undo_window_days', '7');
setting('site.url', 'http://127.0.0.1:' . $port);

$keyText  = bin2hex(random_bytes(32));
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$logins   = ['adm' => ADM, 'aaa' => AAA, 'bbb' => BBB];
foreach ($logins as $who => $id) {
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-return-' . $who, $hash]);
}

$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'offboarding-return-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.offboarding-return-selftest-owner', $state['token']);
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
mkdir($child . $sep . 'web' . $sep . '_uploads', 0700);
mkdir($child . $sep . 'sessions', 0700);
$creds = [
    'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    'db_name' => $dbName,
    'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/offboarding-return-selftest.php for one run.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', $keyText);

$router = <<<'ROUTER'
<?php
// Written by tools/offboarding-return-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Return-Selftest: __TOKEN__');
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
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=0', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
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
echo "A person brought back after offboarding, on the real pages — self-test (#1152, #1155)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . "\n";

$jar    = [];
$signIn = static function (string $who, string $key = KEY_A) use (&$jar, $child, $sep, $password): array {
    $jar[$who] = $child . $sep . 'jar-' . $who . '.txt';
    $tok       = token(req($jar[$who], 'GET', '/login', null, '-')['body']);

    return req($jar[$who], 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-return-' . $who, 'password' => $password], '-');
};
foreach ($logins as $who => $id) {
    $in = $signIn($who);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as selftest-return-{$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

// =============================================================================
// 🧰 Helpers for the scenarios
// =============================================================================
$anon = $child . $sep . 'jar-anon.txt';
$next = 918430;
/** A new made-up member of A with a password, signed in under its own cookie jar. @return array{0:int,1:string} */
$newMember = static function (bool $signedIn = true) use (&$next, &$jar, $db, $signIn, $hash): array {
    $id = $next++;
    q('INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, 1, 0, 0)', 'iss', [$id, 'Spare ' . $id, 'u' . $id . '.return918@selftest.invalid']);
    q('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 1, 0, 0)', 'ii', [$id, ORG_A]);
    q('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-return-s' . $id, $hash]);
    $who = 's' . $id;
    if ($signedIn === true) {
        $signIn($who);
    }

    return [$id, $who];
};
$adminToken = static function () use (&$jar): string {
    return token(req($jar['adm'], 'GET', '/admin/users')['body']);
};
$offboard = static function (int $id) use (&$jar, $adminToken): void {
    req($jar['adm'], 'POST', '/offboarding/do', ['csrf_token' => $adminToken(), 'userID' => $id, 'reason' => 'Selftest', 'dataDisposition' => 'erase', 'effectiveDate' => gmdate('Y-m-d')]);
};
$tick = static function (int $id) use (&$jar, $adminToken): array {
    $row = one('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ?', 'i', [$id]);

    return req($jar['adm'], 'POST', '/admin/users/save', ['csrf_token' => $adminToken(), 'action' => 'update', 'userID' => (string) $id,
        'fullName' => (string) $row['fullName'], 'emailAddress' => (string) $row['emailAddress'], 'phoneNumber' => '', 'isActive' => '1']);
};
$rehire = static function (int $offboardingId) use (&$jar, $adminToken): array {
    return req($jar['adm'], 'POST', '/offboarding/rehire', ['csrf_token' => $adminToken(), 'offboardingID' => $offboardingId]);
};
/** /offboarding is refused to a signed-in non-administrator with 403 and bounces a signed-out visitor to the sign-in page. */
$isSignedIn = static function (string $who) use (&$jar): bool {
    return req($jar[$who], 'GET', '/offboarding')['status'] === 403;
};
$requestOf = static fn (int $id): ?array => one('SELECT requestID, status FROM tblErasureRequest WHERE userID = ? ORDER BY requestID DESC LIMIT 1', 'i', [$id]);
$offRow = static fn (int $id): ?array => one('SELECT offboardingID, rehiredAt, rehiredByID FROM tblOffboarding WHERE userID = ? ORDER BY offboardingID DESC LIMIT 1', 'i', [$id]);
$sweep = static function () use ($anon): array {
    return req($anon, 'GET', '/cron/retention-sweep?token=' . JOB_TOKEN, null, '-');
};
$age = static function (int $id): void {
    q('UPDATE tblOffboarding SET offboardedAt = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE userID = ?', 'i', [$id]);
};

// =============================================================================
head('Premise');
// =============================================================================
ok('P1 the administrator, and both browsers, are signed in (a member gets 403 on the administrators\' page)', req($jar['adm'], 'GET', '/offboarding')['status'] === 200
    && $isSignedIn('aaa') && $isSignedIn('bbb'));

// =============================================================================
head('A. Offboard with erasure, then tick Active on Admin, Users (#1152)');
// =============================================================================
$offboard(AAA);
$rq = $requestOf(AAA);
ok('A1 premise: offboarding filed a waiting erasure request', $rq !== null && $rq['status'] === 'pending_review', json_encode($rq));
ok('A2 premise: the account is off', (int) one('SELECT isActive AS a FROM tblUsers WHERE userID = ?', 'i', [AAA])['a'] === 0);
ok('A3 the browser signed in BEFORE the offboarding is refused straight away', $isSignedIn('aaa') === false);
$tick(AAA);
ok('A4 the account is switched on again', (int) one('SELECT isActive AS a FROM tblUsers WHERE userID = ?', 'i', [AAA])['a'] === 1);
ok('A5 the waiting erasure request is cancelled', ($requestOf(AAA)['status'] ?? '') === 'cancelled', json_encode($requestOf(AAA)));
$o = $offRow(AAA);
ok('A6 the offboarding row says who brought them back', $o !== null && $o['rehiredAt'] !== null && (int) $o['rehiredByID'] === ADM, json_encode($o));
$list = req($jar['adm'], 'GET', '/offboarding')['body'];
ok('A7 the offboarding list no longer offers Rehire for them', str_contains($list, 'name="offboardingID" value="' . (int) $o['offboardingID'] . '"') === false);
ok('A8 the OLD browser stays refused after the person is back (the account is on again, the session is still dead; #1155)', $isSignedIn('aaa') === false);

// =============================================================================
head('A (Rehire). The Rehire button does the same, through the same code');
// =============================================================================
$offboard(BBB);
ok('A9 the browser signed in before offboarding is refused at once', $isSignedIn('bbb') === false);
$ob = $offRow(BBB);
$rehire((int) $ob['offboardingID']);
ok('A10 Rehire: account on, request cancelled, row marked', (int) one('SELECT isActive AS a FROM tblUsers WHERE userID = ?', 'i', [BBB])['a'] === 1
    && ($requestOf(BBB)['status'] ?? '') === 'cancelled' && $offRow(BBB)['rehiredAt'] !== null && (int) $offRow(BBB)['rehiredByID'] === ADM);
ok('A11 Rehire does not bring the old browser back either', $isSignedIn('bbb') === false);

// =============================================================================
head('B. A suspended member who was never offboarded keeps their own request');
// =============================================================================
q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [CCC]);
$tokenC = bin2hex(random_bytes(32));
q("INSERT INTO tblErasureRequest (siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, ?, 'ccc@selftest.invalid', 'Cleo Ccc', ?, 'pending_review', DATE_ADD(NOW(), INTERVAL 1 MONTH))", 'iis', [ORG_A, CCC, $tokenC]);
$tick(CCC);
ok('B1 switched back on', (int) one('SELECT isActive AS a FROM tblUsers WHERE userID = ?', 'i', [CCC])['a'] === 1);
ok('B2 their own waiting request is NOT cancelled by the tick', ($requestOf(CCC)['status'] ?? '') === 'pending_review', json_encode($requestOf(CCC)));
q("UPDATE tblErasureRequest SET status = 'cancelled' WHERE userID = ?", 'i', [CCC]);

// =============================================================================
head('C. The nightly sweep (after_undo_window)');
// =============================================================================
// DDD stays gone: the control that shows the sweep still erases the right person.
$offboard(DDD);
$age(DDD);
$dddRequest = (int) (one('SELECT MAX(requestID) AS m FROM tblErasureRequest WHERE userID = ?', 'i', [DDD])['m'] ?? 0);
// EEE: the state the tick left BEFORE #1152 (account on, request waiting, row unmarked).
$stateEee = static function () use ($offboard, $age): void {
    q("DELETE FROM tblErasureRequest WHERE userID = ?", 'i', [EEE]);
    q('DELETE FROM tblOffboarding WHERE userID = ?', 'i', [EEE]);
    q('INSERT INTO tblOffboarding (userID, effectiveDate, reason, dataDisposition, offboardedByID, offboardedAt) VALUES (?, CURDATE(), \'Selftest\', \'erase\', ?, DATE_SUB(NOW(), INTERVAL 30 DAY))', 'ii', [EEE, ADM]);
    q("INSERT INTO tblErasureRequest (siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, ?, 'eee@selftest.invalid', 'Eve Eee', ?, 'pending_review', DATE_ADD(NOW(), INTERVAL 1 MONTH))", 'iis', [ORG_A, EEE, bin2hex(random_bytes(32))]);
    q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [EEE]);
};
$stateEee();
$ran = $sweep();
ok('C1 the job ran and answered ok', $ran['status'] === 200 && str_contains($ran['body'], '"ok"'), substr($ran['body'], 0, 200));
ok('C2 control: the person still gone (DDD) was erased by the sweep', (string) (one('SELECT status AS s FROM tblErasureRequest WHERE requestID = ?', 'i', [$dddRequest])['s'] ?? '') === 'completed', (string) $dddRequest);
ok('C3 the person whose account is switched on (EEE) was NOT erased, though their request still waited and their row was unmarked', ($requestOf(EEE)['status'] ?? '') === 'pending_review', json_encode($requestOf(EEE)));
ok('C4 and their account row still holds their own name', (string) one('SELECT fullName AS n FROM tblUsers WHERE userID = ?', 'i', [EEE])['n'] === 'Eve Eee');

// =============================================================================
head('E. Planted faults: each fix put back to its old form turns its check red');
// =============================================================================
/** Change one file in the scratch copy, run $probe, put the file back, and prove it is back (hash). */
$plant = static function (string $label, string $relative, string $from, string $to, callable $probe, bool $expectRed = true) use ($child, $sep, &$state): void {
    $file     = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relative);
    $original = (string) file_get_contents($file);
    $hash     = md5($original);
    if (substr_count($original, $from) !== 1) {
        ok($label . ': the planted text occurs exactly once', false, 'found ' . substr_count($original, $from));

        return;
    }
    $state['planted'][$file] = $original;
    file_put_contents($file, str_replace($from, $to, $original));
    try {
        $result = $probe();
    } finally {
        file_put_contents($file, $original);
        unset($state['planted'][$file]);
    }
    ok($label . ($expectRed === true ? ': red when planted' : ': still green when planted (the other guard holds)'), $result === ($expectRed === false));
    ok($label . ': the file is back byte for byte', md5((string) file_get_contents($file)) === $hash);
};

// Probe 1: offboard a spare, tick Active; passes when the request is cancelled and the row is marked.
$probeTick = static function () use ($newMember, $offboard, $tick, $requestOf, $offRow): bool {
    [$id] = $newMember(false);
    $offboard($id);
    $tick($id);

    return ($requestOf($id)['status'] ?? '') === 'cancelled' && ($offRow($id)['rehiredAt'] ?? null) !== null;
};
ok('E0 the tick probe passes unplanted', $probeTick() === true);
$plant('E1 the Active tick no longer calls ReturnedMember', '_apps/admin/users/save.php', "\\Portal\\Core\\ReturnedMember::record(\$mysqli, (int) \$userID, (int) (\$_SESSION['user_id'] ?? 0));", '$userID = $userID;', $probeTick);

// Probe 2: sign a spare in, offboard, bring back by the tick; passes when the old browser stays refused.
$probeSession = static function () use ($newMember, $offboard, $tick, $isSignedIn): bool {
    [$id, $who] = $newMember(true);
    $offboard($id);
    $tick($id);

    return $isSignedIn($who) === false;
};
ok('E2 the session probe passes unplanted', $probeSession() === true);
$plant('E3 offboarding no longer ends existing sessions', '_apps/offboarding/do.php', '$sessionsEnded = Auth::endOtherSessionsOf($db, $userId);', '$sessionsEnded = true;', $probeSession);

// Probe 3: a suspended member with a request of their own; passes when the tick leaves it alone.
$probeOwn = static function () use ($newMember, $tick, $requestOf): bool {
    [$id] = $newMember(false);
    q('UPDATE tblUsers SET isActive = 0 WHERE userID = ?', 'i', [$id]);
    q("INSERT INTO tblErasureRequest (siteID, userID, subjectEmail, subjectName, confirmToken, status, dueBy) VALUES (?, ?, 'own@selftest.invalid', 'Spare', ?, 'pending_review', DATE_ADD(NOW(), INTERVAL 1 MONTH))", 'iis', [ORG_A, $id, bin2hex(random_bytes(32))]);
    $tick($id);

    return ($requestOf($id)['status'] ?? '') === 'pending_review';
};
ok('E4 the own-request probe passes unplanted', $probeOwn() === true);
$plant('E5 ReturnedMember cancels even with no offboarding row', '_core/ReturnedMember.php', 'if ($marked > 0) {', 'if (true) {', $probeOwn);

// Probe 4: the sweep and an account that is on. Passes when the request is left alone.
$probeSweep = static function () use ($stateEee, $sweep, $requestOf): bool {
    $stateEee();
    $sweep();

    return ($requestOf(EEE)['status'] ?? '') === 'pending_review';
};
ok('E6 the sweep probe passes unplanted', $probeSweep() === true);
$selection = "         . '  AND ru.isActive = 0 '\n";
$claim     = "AND `u`.`isActive` = 0 '";
$plant('E7 the sweep: both the selection and the claim stop asking whether the account is on', '_apps/cron/_retention-sweep.php', $selection, '', static function () use ($child, $sep, $probeSweep, &$state): bool {
    // second plant inside the first, same file: take the claim's test out too
    $file = $child . $sep . 'web' . $sep . '_apps' . $sep . 'cron' . $sep . '_retention-sweep.php';
    $text = (string) file_get_contents($file);
    $claimText = "AND `u`.`isActive` = 0 '";
    file_put_contents($file, str_replace($claimText, "AND 1 = 1 '", $text));

    return $probeSweep();
});
$plant('E8 the sweep: only the selection\'s test removed - the CLAIM alone still refuses an account that is on', '_apps/cron/_retention-sweep.php', $selection, '', $probeSweep, false);
$plant('E9 the sweep: only the claim\'s test removed - the selection alone still refuses', '_apps/cron/_retention-sweep.php', $claim, "AND 1 = 1 '", $probeSweep, false);

// Probe 5: the list. A person already on (the old tick left the row unmarked) must not be offered Rehire.
$probeList = static function () use ($newMember, $offboard, &$jar, $offRow): bool {
    [$id] = $newMember(false);
    $offboard($id);
    q('UPDATE tblUsers SET isActive = 1 WHERE userID = ?', 'i', [$id]);
    $o = $offRow($id);

    return str_contains(req($jar['adm'], 'GET', '/offboarding')['body'], 'name="offboardingID" value="' . (int) $o['offboardingID'] . '"') === false;
};
ok('E10 the list probe passes unplanted', $probeList() === true);
$plant('E11 the list does not notice the account is on', '_apps/offboarding/index.php', "(int) (\$r['accountIsActive'] ?? 0) === 1;", "(int) (\$r['accountIsActive'] ?? 0) === 2;", $probeList);

// =============================================================================
echo "\n" . ($fail === 0 ? 'ALL PASSED' : 'FAILED') . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
