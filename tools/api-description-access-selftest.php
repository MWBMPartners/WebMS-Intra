<?php
// Path: tools/api-description-access-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Who may read the API description and the API documentation page 🔐 (#1131, D-215)
 * -----------------------------------------------------------------------------
 * D-215 (the owner, 17 November 2026): the browsable API documentation page
 * (`/api-docs`) and the description of every API address (`/openapi.json`) are
 * for signed-in administrators and people holding an API key, and nobody else.
 * The rule is `Portal\Core\ApiDescriptionAccess`; this test drives it over real
 * HTTP against a copy of `web/`, as a visitor, a member, an organisation's
 * administrator, a global administrator and five kinds of API key.
 *
 * WHAT IT CHECKS
 * --------------
 *   C  the rule as a TABLE: all 32 combinations of its five yes/no facts,
 *      compared with a table written out by hand below (never worked out from
 *      the class: a table copied from the thing it tests tests nothing). It
 *      needs no server and runs first.
 *   A  the PAGE, on three spellings of its address, each judged separately:
 *      `/api-docs/` and `/api-docs/index.php` (the web server runs the page
 *      itself, with nothing loaded) and `/api-docs//` (a server that hands
 *      every address to the portal's front controller). A visitor is sent to
 *      sign in and brought back; a member gets the portal's 404 page; both
 *      kinds of administrator get the page; a valid key does NOT (the page is
 *      for administrators: a browser cannot send a key).
 *   B  the DESCRIPTION: a visitor and a member get 404; both administrators get
 *      it; a valid key with a NARROW scope gets it with no session at all and
 *      leaves its "last used" mark; an unknown, switched-off or out-of-date key
 *      gets 401 in the API's own words; a bearer that is not one of ours is
 *      ignored; when a key is sent the KEY decides, whoever is signed in; and
 *      every answer carrying the description forbids a shared cache keeping it.
 *   D  PLANTS. Each is made in the scratch copy, never in the repository: the
 *      named checks must go red, the checks that must be unaffected must stay
 *      green, and the file is then put back byte for byte and everything must
 *      be green again. The counts are printed, never written here.
 *
 * WHAT IT CANNOT PROVE
 * --------------------
 * - Apache. The router imitates `.htaccess`, including that Apache answers
 *   `/api-docs/` with that folder's own index.php (tools/htaccess-apache-
 *   selftest.php covers which file a real Apache runs).
 * - A shared cache. Only the header that forbids storing is checked.
 * - A real browser, or how the page looks.
 * - A pre-release copy: its staff gate answers first and a key holder cannot
 *   read the description there (tools/channel-selftest.php covers that gate).
 * - The plants P1-P8 reach the page and the description through the files
 *   they ask the rule from; a fault in some OTHER place that serves these two
 *   addresses is not imagined here.
 *
 * Its made-up people are numbered 939001 to 939099, its keys are named
 * "selftest apidesc ..." and its scratch folder is made new each run; all are
 * removed at the START, at the end and from a shutdown function.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_apidesc SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/api-description-access-selftest.php
 *         SELFTEST_DB_HOST (default 127.0.0.1), SELFTEST_DB_USER (default root),
 *         SELFTEST_WEB_PORT (default 9131), SELFTEST_WEB_DIR (scratch parent,
 *         default the system's temporary folder). The database must already
 *         hold `web/_sql/full_schema.sql`. Needs curl and proc_open().
 * Exit:   0 only when every check ran and passed; 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1131
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const U_MEMBER = 939001; // a member of the default organisation, no administrator flag
const U_ORGADM = 939002; // an administrator of the default organisation only
const U_GLOBAL = 939003; // a global administrator
const KEY_NAME = 'selftest apidesc';
const BAD_WORDS = 'Invalid or expired API key';

function refuse(string $why): never
{
    echo "REFUSED — {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
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
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9131 : (int) $portRaw;
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
$db = new mysqli($creds['db_host'], $creds['db_user'], $creds['db_pass'], $creds['db_name'], $creds['db_port']);
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
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
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

// =============================================================================
// C. THE RULE AS A TABLE (no server)
// =============================================================================
/**
 * [page?, key sent?, key valid?, signed in?, administrator?, the verdict]. WRITTEN OUT BY HAND, on purpose.
 * Read as: the page decides on the session alone (a key is ignored); the description lets a SENT key decide
 * alone, and otherwise wants a signed-in administrator; a signed-out visitor to the page is sent to sign in.
 */
const TABLE = [
    [true, false, false, false, false, 'sign-in'],
    [true, false, false, false, true, 'sign-in'],
    [true, false, false, true, false, 'not-found'],
    [true, false, false, true, true, 'allow'],
    [true, false, true, false, false, 'sign-in'],
    [true, false, true, false, true, 'sign-in'],
    [true, false, true, true, false, 'not-found'],
    [true, false, true, true, true, 'allow'],
    [true, true, false, false, false, 'sign-in'],
    [true, true, false, false, true, 'sign-in'],
    [true, true, false, true, false, 'not-found'],
    [true, true, false, true, true, 'allow'],
    [true, true, true, false, false, 'sign-in'],
    [true, true, true, false, true, 'sign-in'],
    [true, true, true, true, false, 'not-found'],
    [true, true, true, true, true, 'allow'],
    [false, false, false, false, false, 'not-found'],
    [false, false, false, false, true, 'not-found'],
    [false, false, false, true, false, 'not-found'],
    [false, false, false, true, true, 'allow'],
    [false, false, true, false, false, 'not-found'],
    [false, false, true, false, true, 'not-found'],
    [false, false, true, true, false, 'not-found'],
    [false, false, true, true, true, 'allow'],
    [false, true, false, false, false, 'bad-key'],
    [false, true, false, false, true, 'bad-key'],
    [false, true, false, true, false, 'bad-key'],
    [false, true, false, true, true, 'bad-key'],
    [false, true, true, false, false, 'allow'],
    [false, true, true, false, true, 'allow'],
    [false, true, true, true, false, 'allow'],
    [false, true, true, true, true, 'allow'],
];

/**
 * Ask a class FILE what verdict() says for each row, in a separate PHP process (so the same function can judge
 * the repository's copy and a planted scratch copy). The file is loaded alone: the class reaches nothing else
 * until enforce() runs.
 *
 * @return array{verdicts:list<string>, constants:list<string>}|null
 */
function askClass(string $file): ?array
{
    $code = 'require $argv[1]; $o = []; foreach (json_decode($argv[2], true) as $r) { '
        . '$o[] = \Portal\Core\ApiDescriptionAccess::verdict($r[0], $r[1], $r[2], $r[3], $r[4]); } '
        . 'echo json_encode(["verdicts" => $o, "constants" => [\Portal\Core\ApiDescriptionAccess::ALLOW, '
        . '\Portal\Core\ApiDescriptionAccess::SIGN_IN, \Portal\Core\ApiDescriptionAccess::NOT_FOUND, \Portal\Core\ApiDescriptionAccess::BAD_KEY]]);';
    $run = proc_open([PHP_BINARY, '-r', $code, '--', $file, json_encode(TABLE)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($run) === false) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($run);
    $decoded = json_decode($out, true);

    return is_array($decoded) === true ? $decoded : null;
}

/** Rows of TABLE where the class at $file disagrees, as words; null when it could not be asked. */
function tableProblems(string $file): ?array
{
    $answer = askClass($file);
    if ($answer === null || count($answer['verdicts']) !== count(TABLE)) {
        return null;
    }
    $bad = [];
    foreach (TABLE as $i => $row) {
        if ($answer['verdicts'][$i] !== $row[5]) {
            $bad[] = 'row ' . ($i + 1) . ' ' . json_encode(array_slice($row, 0, 5)) . ' expected ' . $row[5] . ' got ' . $answer['verdicts'][$i];
        }
    }
    if ($answer['constants'] !== ['allow', 'sign-in', 'not-found', 'bad-key']) {
        $bad[] = 'the four words are ' . json_encode($answer['constants']);
    }

    return $bad;
}

echo "Who may read the API description and the API documentation page — self-test (#1131, D-215)\n";
head('C. The rule as a table: every combination of its five facts');
$combos = count(TABLE);
ok('C01 the hand-written table holds all 32 combinations, each exactly once', $combos === 32 && count(array_unique(array_map(static fn (array $r): string => json_encode(array_slice($r, 0, 5)), TABLE))) === 32);
$bad = tableProblems($webSrc . $sep . '_core' . $sep . 'ApiDescriptionAccess.php');
ok('C02 ApiDescriptionAccess::verdict() agrees with the table on all 32, and its four words are allow, sign-in, not-found and bad-key', $bad === [], $bad === null ? 'the class could not be asked' : implode('; ', $bad));

// =============================================================================
// 🏗️ The world, the scratch copy and the server
// =============================================================================
$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'started' => false, 'cleaned' => false];
$child = $parent . $sep . 'apidesc-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.apidesc-selftest-owner', $state['token']);

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

function stopServer(): void
{
    global $state, $port;
    if (is_resource($state['proc']) === false) {
        return;
    }
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
    $until         = microtime(true) + 5;
    while (portAnswers($port) === true && microtime(true) < $until) {
        usleep(50000);
    }
}

/** Remove this test's rows: only its own account numbers and keys carrying its name. */
function removeWorld(): void
{
    q('DELETE FROM `tblApiKeys` WHERE `name` LIKE ?', 's', [KEY_NAME . '%']);
    q('DELETE FROM `tblActivityLogs` WHERE `userID` BETWEEN 939001 AND 939099');
    q('DELETE FROM `tblUserSites` WHERE `userID` BETWEEN 939001 AND 939099');
    q('DELETE FROM `tblLocalAccounts` WHERE `userID` BETWEEN 939001 AND 939099');
    q('DELETE FROM `tblUsers` WHERE `userID` BETWEEN 939001 AND 939099');
}

function cleanup(): void
{
    global $state;
    if ($state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    stopServer();
    if ($state['started'] === true) {
        try {
            foreach ($state['settings'] as $key => $before) {
                q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
            removeWorld();
        } catch (Throwable $e) {
            echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
        }
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false && @file_get_contents($child . DIRECTORY_SEPARATOR . '.apidesc-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}
register_shutdown_function('cleanup');

if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}; this test never drives a server it did not start");
}
$wanted = ['multisite.enabled' => 'false', 'portal.maintenance.active' => '0'];
foreach ($wanted as $key => $value) {
    $rows = q('SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    if (count($rows) !== 1) {
        refuse("the database must hold exactly one portal-wide row for the setting {$key} (found " . count($rows) . ')');
    }
    $state['settings'][$key] = $rows[0];
}
$state['started'] = true;
removeWorld();
foreach ($wanted as $key => $value) {
    q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$value, (int) $state['settings'][$key]['settingID']]);
}

$people = [
    'member' => [U_MEMBER, 0, 0, 0],
    'orgadm' => [U_ORGADM, 0, 0, 1],
    'global' => [U_GLOBAL, 1, 0, 0],
];
$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
foreach ($people as $who => [$id, $isAdmin, $isRoot, $siteAdmin]) {
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, ?, ?)', 'issii', [$id, 'Selftest ApiDesc ' . $who, $who . '.apidesc@selftest.invalid', $isAdmin, $isRoot]);
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, 1, 1, ?, 0)', 'ii', [$id, $siteAdmin]);
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-apidesc-' . $who, $hash]);
}

/** One API key row. Returns its plaintext. $expires is a SQL date text or null. */
function makeKey(string $label, string $scopes, int $active, ?string $expires): string
{
    $plain = 'wbms_' . bin2hex(random_bytes(16));
    q(
        'INSERT INTO `tblApiKeys` (`siteID`, `name`, `keyHash`, `keyPrefix`, `scopes`, `expiresAt`, `isActive`, `createdByID`) VALUES (1, ?, ?, ?, ?, ?, ?, NULL)',
        'sssssi',
        [KEY_NAME . ' ' . $label, hash('sha256', $plain), substr($plain, 0, 12), $scopes, $expires, $active]
    );

    return $plain;
}
$keys = [
    'valid'   => makeKey('valid narrow', 'events:read', 1, null),
    'off'     => makeKey('switched off', 'events:read', 0, null),
    'expired' => makeKey('out of date', 'events:read', 1, gmdate('Y-m-d H:i:s', time() - 86400)),
    'unknown' => 'wbms_' . bin2hex(random_bytes(16)), // never stored
];

// The scratch copy of web/, with a throwaway connection file and key.
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries', '.channel'], true) === true) {
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/api-description-access-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$router = <<<'ROUTER'
<?php
// Written by tools/api-description-access-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Apidesc-Selftest: __TOKEN__');
$root = (string) realpath((string) $_SERVER['DOCUMENT_ROOT']);
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
// Apache answers /api-docs/ with that folder's OWN index.php and never hands it to the portal's index.php.
// Any other spelling, /api-docs// for one, falls through to index.php: the way a server that hands every
// address to the front controller reaches the same page.
if ($uri === '/api-docs' || $uri === '/api-docs/') { $uri = '/api-docs/index.php'; }
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

// The web folder is named exactly public_html, with no .channel file: the LIVE channel, where no staff gate
// stands in front of these two addresses and the new rule is the only thing deciding.
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS'], $env['PORTAL_ENV']);
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-d', 'log_errors=1',
        '-d', 'display_errors=1', '-d', 'html_errors=0', '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
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
echo 'server on port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) q('SELECT VERSION() AS v')[0]['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

/**
 * One real HTTP request. Redirects are never followed. An answer without this run's header came from
 * somewhere else and is returned as status -1.
 *
 * @param list<string> $headers
 * @return array{status:int, location:string, body:string, head:string}
 */
function req(string $jar, string $method, string $path, ?array $post = null, array $headers = []): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => array_merge(['Expect:', 'Accept-Language: en'], $headers),
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
    if (preg_match('/^X-Apidesc-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header', 'head' => ''];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
        'head'     => $head,
    ];
}

function said(array $answer): string
{
    return 'HTTP ' . $answer['status'] . ($answer['location'] === '' ? '' : ' to ' . $answer['location']) . '; first bytes ' . json_encode(substr(trim($answer['body']), 0, 80));
}

/** Sign one account in through the real sign-in page, optionally via a given sign-in address (query included). */
function signIn(string $who, string $loginPath = '/login'): array
{
    global $child, $sep, $password;
    $jar  = $child . $sep . 'jar-' . $who . '-' . bin2hex(random_bytes(3)) . '.txt';
    $form = req($jar, 'GET', $loginPath);
    $tok  = preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $form['body'], $m) === 1 ? $m[1] : '';
    $in   = req($jar, 'POST', $loginPath, ['csrf_token' => $tok, 'username' => 'selftest-apidesc-' . $who, 'password' => $password]);

    return ['jar' => $jar, 'answer' => $in, 'in' => $in['status'] === 302 && str_contains($in['location'], 'login') === false];
}

/** The page: 200, Swagger's holder element and its script. */
function isPage(array $a): bool
{
    return $a['status'] === 200 && str_contains($a['body'], 'id="swagger-ui"') === true && str_contains($a['body'], 'SwaggerUIBundle') === true;
}
function noSwagger(array $a): bool
{
    return str_contains($a['body'], 'swagger-ui') === false && str_contains($a['body'], 'SwaggerUIBundle') === false;
}
function is404Page(array $a): bool
{
    return $a['status'] === 404 && str_contains($a['body'], 'portal-error-code">404') === true;
}
function isDescription(array $a): bool
{
    $j = json_decode($a['body'], true);

    return $a['status'] === 200 && is_array($j) === true && isset($j['openapi']) === true && is_array($j['paths'] ?? null) === true && count($j['paths']) > 0;
}
function noDescription(array $a): bool
{
    return str_contains($a['body'], '"paths"') === false && str_contains($a['body'], '"openapi"') === false;
}
function badKeyAnswer(array $a): bool
{
    $j = json_decode($a['body'], true);

    return $a['status'] === 401 && is_array($j) === true && ($j['message'] ?? null) === BAD_WORDS && noDescription($a) === true;
}
/** Cache-Control on an answer forbids a shared cache keeping it. */
function forbidsSharedCache(array $a): bool
{
    preg_match_all('/^Cache-Control:\s*(.+)$/mi', $a['head'], $m);
    $all = strtolower(implode(', ', $m[1]));

    return str_contains($all, 'no-store') === true && str_contains($all, 'private') === true && str_contains($all, 'public') === false;
}

// People signed in once; the plants do not touch sessions.
$jars = [];
foreach (array_keys($people) as $who) {
    $in = signIn($who);
    if ($in['in'] === false) {
        refuse('could not sign in the made-up ' . $who . ': ' . said($in['answer']));
    }
    $jars[$who] = $in['jar'];
}
$jars['visitor'] = '';
$bearer = static fn (string $k): array => ['Authorization: Bearer ' . $k];

const SPELLINGS = ['a' => '/api-docs/', 'b' => '/api-docs/index.php', 'c' => '/api-docs//'];

/**
 * Every HTTP check, as id => [label, passed, detail]. Run once for the report and again under each plant.
 *
 * @return array<string, array{0:string, 1:bool, 2:string}>
 */
function suite(): array
{
    global $jars, $keys, $bearer, $port;
    $r   = [];
    $put = static function (string $id, string $label, bool $passed, string $detail = '') use (&$r): void {
        $r[$id] = [$id . ' ' . $label, $passed, $detail];
    };
    $enc = static fn (string $addr): string => rawurlencode($addr);

    // ---- A. the page, on three spellings ----------------------------------------------------
    foreach (SPELLINGS as $s => $addr) {
        $v = req('', 'GET', $addr);
        $put("A01{$s}", "a visitor to {$addr} is sent to sign in, coming back to that address, and sees no Swagger",
            $v['status'] === 302 && str_contains($v['location'], 'login') === true && str_contains($v['location'], 'redirect=' . $enc($addr)) === true && noSwagger($v) === true, said($v));
        $m = req($jars['member'], 'GET', $addr);
        $put("A02{$s}", "a signed-in member to {$addr} gets the portal's 404 page and no Swagger", is404Page($m) === true && noSwagger($m) === true, said($m));
        $o = req($jars['orgadm'], 'GET', $addr);
        $put("A03{$s}", "an organisation's administrator gets the page at {$addr}", isPage($o) === true, said($o));
        $g = req($jars['global'], 'GET', $addr);
        $put("A04{$s}", "a global administrator gets the page at {$addr}", isPage($g) === true, said($g));
        $k = req('', 'GET', $addr, null, $bearer($keys['valid']));
        $put("A05{$s}", "a VALID key sent to {$addr} with no session is treated as a visitor: the page is for administrators, and a browser cannot send a key",
            $k['status'] === 302 && str_contains($k['location'], 'login') === true && noSwagger($k) === true, said($k));
    }
    // A06: the come-back works, end to end, through the real sign-in form.
    $first = req('', 'GET', '/api-docs/');
    $come  = $first['location'] !== '' ? signIn('global', $first['location']) : ['jar' => '', 'answer' => $first, 'in' => false];
    $back  = $come['answer']['location'];
    $final = ($come['in'] === true && $back !== '') ? req($come['jar'], 'GET', $back) : $come['answer'];
    $put('A06', 'after signing in from the sent-away address, the person is taken back to /api-docs/ and gets the page',
        $come['in'] === true && str_contains($back, 'api-docs') === true && isPage($final) === true, 'sent to ' . $first['location'] . ', then ' . said($come['answer']) . ', then ' . said($final));

    // ---- B. the description ------------------------------------------------------------------
    $v = req('', 'GET', '/openapi.json');
    $put('B01', 'a visitor gets 404 and none of the description', $v['status'] === 404 && noDescription($v) === true, said($v));
    $m = req($jars['member'], 'GET', '/openapi.json');
    $put('B02', 'a signed-in member gets 404 and none of the description', $m['status'] === 404 && noDescription($m) === true, said($m));
    $o = req($jars['orgadm'], 'GET', '/openapi.json');
    $put('B03', "an organisation's administrator gets the description", isDescription($o) === true, said($o));
    $g = req($jars['global'], 'GET', '/openapi.json');
    $put('B04', 'a global administrator gets the description', isDescription($g) === true, said($g));
    q('UPDATE `tblApiKeys` SET `lastUsedAt` = NULL WHERE `name` LIKE ?', 's', [KEY_NAME . '%']);
    $kv   = req('', 'GET', '/openapi.json', null, $bearer($keys['valid']));
    $used = q('SELECT `lastUsedAt` FROM `tblApiKeys` WHERE `keyHash` = ?', 's', [hash('sha256', $keys['valid'])]);
    $put('B05', 'a valid key limited to events:read, with no session, gets the description, and its "last used" mark is set',
        isDescription($kv) === true && count($used) === 1 && $used[0]['lastUsedAt'] !== null, said($kv));
    $ku = req('', 'GET', '/openapi.json', null, $bearer($keys['unknown']));
    $put('B06', 'an unknown key gets 401 with exactly "' . BAD_WORDS . '" and none of the description', badKeyAnswer($ku) === true, said($ku));
    $ko = req('', 'GET', '/openapi.json', null, $bearer($keys['off']));
    $put('B07', 'a switched-off key gets 401', badKeyAnswer($ko) === true, said($ko));
    $ke = req('', 'GET', '/openapi.json', null, $bearer($keys['expired']));
    $put('B08', 'an out-of-date key gets 401', badKeyAnswer($ke) === true, said($ke));
    $kn = req('', 'GET', '/openapi.json', null, ['Authorization: Bearer abc123']);
    $put('B09', 'a bearer that is not one of ours, from a visitor, is treated as no key: 404', $kn['status'] === 404 && noDescription($kn) === true, said($kn));
    $mk = req($jars['member'], 'GET', '/openapi.json', null, $bearer($keys['valid']));
    $put('B10a', 'a member who sends a VALID key gets the description: the key decides', isDescription($mk) === true, said($mk));
    $gk = req($jars['global'], 'GET', '/openapi.json', null, $bearer($keys['unknown']));
    $put('B10b', "a global administrator's session with an UNKNOWN key gets 401: the key decides", badKeyAnswer($gk) === true, said($gk));
    $bad = [];
    foreach (['B03' => $o, 'B04' => $g, 'B05' => $kv, 'B10a' => $mk] as $name => $answer) {
        if (isDescription($answer) === true && forbidsSharedCache($answer) === false) {
            $bad[] = $name;
        }
    }
    $put('B11', 'every answer that carries the description says private and no-store, never public', $bad === [] && isDescription($o) === true, 'Cache-Control wrong on ' . implode(', ', $bad) . ': ' . trim((string) preg_replace('/\s+/', ' ', (string) (preg_match('/^Cache-Control:.*$/mi', $o['head'], $cc) === 1 ? $cc[0] : 'no Cache-Control header'))));

    return $r;
}

head('A and B. The page and the description, over real HTTP');
$base = suite();
foreach ($base as [$label, $passed, $detail]) {
    ok($label, $passed, $detail);
}

// =============================================================================
// D. PLANTS
// =============================================================================
head('D. Plants: each fault made in the scratch copy must turn the named checks red, leave the others alone, and go away again');
$webCopy   = $child . $sep . 'web';
$pageFile  = $webCopy . $sep . 'public_html' . $sep . 'api-docs' . $sep . 'index.php';
$descFile  = $webCopy . $sep . 'public_html' . $sep . 'openapi.php';
$classFile = $webCopy . $sep . '_core' . $sep . 'ApiDescriptionAccess.php';

/**
 * @param list<array{0:string,1:string}> $edits  [from, to] pairs, each applied to $file, each found exactly once
 * @param list<string> $mustBeRed      suite ids that must now fail
 * @param list<string> $mustStayGreen  suite ids that must still pass
 * @param bool|null    $tableRed       true: the table must go red; false: it cannot; null: not asked
 */
function plant(string $name, string $file, array $edits, array $mustBeRed, array $mustStayGreen, ?bool $tableRed, string $why): void
{
    global $base;
    $original = (string) file_get_contents($file);
    $hash     = hash('sha256', $original);
    $changed  = $original;
    foreach ($edits as [$from, $to]) {
        if (substr_count($changed, $from) !== 1) {
            ok("{$name} could be applied exactly once ({$from})", false, 'found ' . substr_count($changed, $from) . ' times');
            return;
        }
        $changed = str_replace($from, $to, $changed);
    }
    file_put_contents($file, $changed);
    try {
        $with       = suite();
        $tableState = tableProblems($file);
    } finally {
        file_put_contents($file, $original);
    }
    $notRed = array_values(array_filter($mustBeRed, static fn (string $id): bool => ($with[$id][1] ?? true) === true));
    $moved  = array_values(array_filter($mustStayGreen, static fn (string $id): bool => ($with[$id][1] ?? false) === false));
    ok("{$name} planted ({$why}): every named check goes red", $notRed === [], 'still green: ' . implode(', ', $notRed));
    ok("{$name} planted: the checks that must be unaffected stay green", $moved === [], 'went red: ' . implode(', ', $moved));
    if ($tableRed !== null) {
        ok("{$name} planted: the hand-written table " . ($tableRed === true ? 'goes red' : 'cannot see it (the fault is outside verdict())'), $tableRed === true ? ($tableState !== null && $tableState !== []) : ($tableState === []), json_encode($tableState));
    }
    ok("{$name} put back byte for byte", hash('sha256', (string) file_get_contents($file)) === $hash);
    $again = suite();
    $stillRed = array_keys(array_filter($again, static fn (array $row): bool => $row[1] === false));
    ok("{$name} after it was put back: every check is green again", $stillRed === [], 'red: ' . implode(', ', $stillRed));
}

$ids = static fn (string $prefix, array $letters = ['a', 'b', 'c']): array => array_map(static fn (string $l): string => $prefix . $l, $letters);
$allB = array_values(array_filter(array_keys($base), static fn (string $id): bool => str_starts_with($id, 'B')));
$allA = array_values(array_filter(array_keys($base), static fn (string $id): bool => str_starts_with($id, 'A')));

plant('P1', $pageFile, [["\nApiDescriptionAccess::enforce(true);\n", "\n"]],
    array_merge($ids('A01'), $ids('A02'), $ids('A05'), ['A06']), $allB, null, 'the page no longer asks the rule (outside verdict(), so the table cannot see it)');
plant('P2', $descFile, [["ApiDescriptionAccess::enforce(false);\n", "\n"]],
    ['B01', 'B02', 'B06', 'B07', 'B08', 'B09'], $allA, null, 'the description no longer asks the rule');
plant('P3', $classFile, [['$administrator = $signedIn === true && App::isAdmin() === true;', '$administrator = $signedIn === true;']],
    array_merge($ids('A02'), ['B02']), array_merge($ids('A01'), $ids('A03'), $ids('A04'), ['B01', 'B03', 'B04', 'B05']), false, 'any signed-in person counts as an administrator');
plant('P4', $classFile, [['$keyValid = $keySent === true && ApiAuth::bearerKeyRow() !== null;', '$keyValid = $keySent === true;']],
    ['B05', 'B06', 'B07', 'B08'], array_merge($allA, ['B01', 'B02', 'B03', 'B04']), false, 'a key that was sent counts as valid (B05 goes red too: the key was never looked up, so its "last used" mark is not set)');
plant('P5', $pageFile, [
    ["\nApiDescriptionAccess::enforce(true);\n", "\n"],
    ["        Gatekeeper::enforce(PORTAL_ENV);\n    }\n}\n", "        Gatekeeper::enforce(PORTAL_ENV);\n    }\n    ApiDescriptionAccess::enforce(true);\n}\n"],
], ['A01c', 'A02c', 'A05c'], array_merge($ids('A01', ['a', 'b']), $ids('A02', ['a', 'b']), $ids('A05', ['a', 'b']), $ids('A03'), $ids('A04'), $allB), null, 'the call moved inside the block only the direct way in runs: the router way in is left open');
plant('P6', $descFile, [["header('Cache-Control: private, no-store');\necho json_encode(\$spec", "header('Cache-Control: public, max-age=300');\necho json_encode(\$spec"]],
    ['B11'], array_merge($allA, ['B01', 'B02', 'B03', 'B04', 'B05', 'B06']), null, 'the description is allowed into shared caches again');
plant('P7', $classFile, [['if ($forThePage === false && $keySent === true) {', 'if ($forThePage === true && $keySent === true) {']],
    ['B05', 'B06', 'B07', 'B08', 'B10a', 'B10b'], array_merge($allA, ['B01', 'B02', 'B03', 'B04']), true, 'the rule lets the PAGE take a key and the description ignore one (over HTTP only the description shows it, because enforce() never reports a key for the page; the table is what sees the page half)');
plant('P8', $classFile, [['if ($signedIn === true && $administrator === true) {', 'if ($signedIn === true && $administrator === true && false) {']],
    array_merge($ids('A03'), $ids('A04'), ['B03', 'B04']), array_merge($ids('A01'), $ids('A02'), ['B01', 'B02', 'B05', 'B06']), true, '&& false added to the allow condition');

// The class file's own plants above also changed what the table sees; the repository's own copy is untouched.
ok('D99 the repository\'s own files were never touched (the plants are in the scratch copy)', hash_file('sha256', $webSrc . $sep . '_core' . $sep . 'ApiDescriptionAccess.php') === hash_file('sha256', $classFile));

cleanup();
echo "\n" . $pass . ' passed, ' . $fail . " failed.\n";
exit($fail === 0 ? 0 : 1);
