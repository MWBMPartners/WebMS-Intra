<?php
// Path: tools/care-case-person-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A care case records which member it is about (#1066) - self-test 🤝🧪
 * -----------------------------------------------------------------------------
 * `care/case-save.php` used to save only a typed name, so a case made in the
 * portal was invisible to the member's "My data", their data copy (D-184) and
 * an erasure (D-016, and #709, which reaches visit notes THROUGH
 * `tblCareCase.personUserID`). The form now makes the worker choose; this runs
 * the REAL pages and asks.
 *
 *   T01 the form lists the organisation's members, and not another
 *       organisation's, an erased account or a switched-off one; it has the
 *       placeholder, the `none` option and a `required` select.
 *   T02 choosing a member stores their number, no typed name, and the opener
 *       by name (D-110 untouched).
 *   T03 that member's My data counts the case; T04 their data copy holds its
 *       summary and not the worker's name or the case number (D-184).
 *   T05 an erasure of that member keeps the case row, empties its summary and
 *       name, and empties a visit note on it (#709); the worker's name stays.
 *   T06 every refusal writes NOTHING: another organisation's member, an erased
 *       account, a switched-off one, text, empty, an array, 0 and -1.
 *   T07 `none` plus a typed name stores the name and no member.
 *   T08 the field absent behaves as before (an older form still works).
 *
 * It copies web/ to a scratch folder with a throwaway connection file, starts
 * PHP's built-in server on it and signs real accounts in. Needs a database
 * whose name starts with selftest_ (it writes and erases rows):
 *
 *   SELFTEST_DB_NAME=selftest_p2a SELFTEST_DB_PORT=33762 SELFTEST_WEB_PORT=9162 \
 *     php tools/care-case-person-selftest.php
 *
 * WHAT IT CANNOT SHOW: a failure of the database part way through the
 * insert, and the look of the page in a real browser.
 */

declare(strict_types=1);

function refuse(string $why): never
{
    echo "REFUSED - {$why}. Nothing more was checked, and this run fails.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
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
    refuse('the scratch parent folder does not exist');
}
if (str_starts_with($repo . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR) === true
    || str_starts_with($parent . DIRECTORY_SEPARATOR, $repo . DIRECTORY_SEPARATOR) === true) {
    refuse('the scratch parent folder and the repository must not contain each other; set SELFTEST_WEB_DIR');
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9162 : (int) $portRaw;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$db     = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$db->set_charset('utf8mb4');

$runTag = bin2hex(random_bytes(4));
$pass = 0;
$fail = 0;
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS - ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL - ' . $label . ($detail === '' ? '' : "\n        " . mb_substr($detail, 0, 900)) . "\n";
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
const ORG_A  = 918001;
const ORG_B  = 918002;
const ADDR_A = '/selftest-p2a-a';
const ADM    = 918011; // administrator of A (runs the erasure)
const WRK    = 918012; // care-team worker in A
const MEM    = 918013; // the member the cases are about (A)
const NOB    = 918014; // a member of B only
const ERASED_ACC    = 918015; // an erased account that still has an A membership row
const OFF    = 918016; // a switched-off account in A
const ROLE_A = 918032;

function worldCases(): int
{
    return (int) one('SELECT COUNT(*) AS n FROM `tblCareCase` WHERE `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B])['n'];
}

function removeWorld(): void
{
    global $db;
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    q('DELETE v FROM `tblCareVisit` v JOIN `tblCareCase` c ON c.`caseID` = v.`caseID` WHERE c.`siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE a FROM `tblCareAccessLog` a JOIN `tblCareCase` c ON c.`caseID` = a.`caseID` WHERE c.`siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblCareCase` WHERE `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE a FROM `tblErasureAudit` a JOIN `tblErasureRequest` r ON r.`requestID` = a.`requestID` WHERE r.`siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblErasureRequest` WHERE `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblUserRoles` WHERE `siteID` IN (?, ?) OR `userID` BETWEEN 918000 AND 918999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblRoles` WHERE `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblLocalAccounts` WHERE `userID` BETWEEN 918000 AND 918999');
    q('DELETE FROM `tblActivityLogs` WHERE `userID` BETWEEN 918000 AND 918999 OR `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblErrors` WHERE `userID` BETWEEN 918000 AND 918999 OR `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblUserSites` WHERE `siteID` IN (?, ?) OR `userID` BETWEEN 918000 AND 918999', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblUsers` WHERE `userID` BETWEEN 918000 AND 918999');
    q('DELETE FROM `tblSettings` WHERE `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    q('DELETE FROM `tblSites` WHERE `siteID` IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

$state = ['proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'settings' => [], 'cleaned' => false, 'plants' => []];

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
    if ($state['cleaned'] === true) {
        return;
    }
    $state['cleaned'] = true;
    foreach ($state['plants'] as $file => $original) {
        file_put_contents($file, $original);
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
    }
    try {
        foreach ($state['settings'] as $key => $before) {
            if ($before === null) {
                q('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [(string) $key]);
            } else {
                q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$before['settingValue'], (int) $before['settingID']]);
            }
        }
        removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL - the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.p2a-selftest-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/** @param array<string,mixed>|null $post @return array{status:int, location:string, body:string} */
function req(string $jar, string $method, string $path, ?array $post = null): array
{
    global $port, $state;
    $handle = curl_init('http://127.0.0.1:' . $port . ADDR_A . $path);
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
    if (preg_match('/^X-P2a-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer from a server this run did not start'];
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
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

/** Replace text in the SCRATCH copy of a file (never the repository), remembered so it is always put back. */
function plant(string $relative, string $from, string $to): bool
{
    global $state;
    $file = $state['child'] . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . $relative;
    $text = (string) file_get_contents($file);
    if (substr_count($text, $from) !== 1) {
        return false;
    }
    $state['plants'][$file] ??= $text;
    file_put_contents($file, str_replace($from, $to, $text));

    return true;
}
function unplant(): void
{
    global $state;
    foreach ($state['plants'] as $file => $original) {
        file_put_contents($file, $original);
    }
    $state['plants'] = [];
}

// =============================================================================
// 🏗️ Set-up
// =============================================================================
if (portAnswers($port) === true) {
    refuse("something already answers on port {$port}");
}
register_shutdown_function('cleanup');
if (function_exists('pcntl_async_signals') === true) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (int $signal): void {
            echo "\nFAIL - stopped by signal {$signal}; cleaning up.\n";
            exit(1);
        });
    }
}
removeWorld();

q("INSERT INTO `tblSites` (`siteID`, `siteKey`, `siteName`, `isActive`) VALUES (?, 'selftest-p2a-a', 'Selftest P2a A', 1), (?, 'selftest-p2a-b', 'Selftest P2a B', 1)", 'ii', [ORG_A, ORG_B]);
$people = [
    ADM => ['Ada Admin', 1], WRK => ['Wanda Worker', 1], MEM => ['Mel Member', 1], NOB => ['Nob Otherorg', 1],
    ERASED_ACC => ['[Deleted User]', 0], OFF => ['Olly Off', 0],
];
foreach ($people as $id => [$name, $active]) {
    $email = $id === ERASED_ACC ? 'deleted-' . $id . '@example.invalid' : 'u' . $id . '.p2a@selftest.invalid';
    q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, ?, 0, 0)', 'issi', [$id, $name, $email, $active]);
}
foreach ([[ADM, ORG_A, 1], [WRK, ORG_A, 0], [MEM, ORG_A, 0], [NOB, ORG_B, 0], [ERASED_ACC, ORG_A, 0], [OFF, ORG_A, 0]] as [$user, $site, $admin]) {
    q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, 0)', 'iii', [$user, $site, $admin]);
}
// The shipped care_team role, A's own copy, held by the worker (so the care capability opens for her and not for Mel).
q("INSERT INTO `tblRoles` (`roleID`, `siteID`, `roleKey`, `roleName`, `description`, `isStandard`) VALUES (?, ?, 'care_team', 'Care Team', 'Opens the confidential pastoral care register', 1)", 'ii', [ROLE_A, ORG_A]);
q('INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES (?, ?, ?)', 'iii', [WRK, ROLE_A, ORG_A]);

foreach (['multisite.enabled' => 'true', 'multisite.detectionMode' => 'path'] as $key => $value) {
    $row                     = one('SELECT `settingID`, `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL', 's', [$key]);
    $state['settings'][$key] = $row;
    if ($row === null) {
        q('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, 0)', 'sss', [$key, $value, $value]);
    } else {
        q('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingID` = ?', 'si', [$value, (int) $row['settingID']]);
    }
}
q('DELETE FROM `tblSettings` WHERE `siteID` = ? AND `settingKey` = ?', 'is', [ORG_A, 'care.enabled']);
q("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, 'care.enabled', 'true', 'true', 0)", 'i', [ORG_A]);

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
$logins   = ['adm' => ADM, 'wrk' => WRK, 'mem' => MEM];
foreach ($logins as $who => $id) {
    q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [$id, 'selftest-p2a-' . $who, $hash]);
}

$sep   = DIRECTORY_SEPARATOR;
$child = $parent . $sep . 'p2a-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse('could not make a NEW scratch folder');
}
$state['child'] = $child;
file_put_contents($child . $sep . '.p2a-selftest-owner', $state['token']);
mkdir($child . $sep . 'web', 0700);
$copied = 0;
$tree   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webSrc, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $path => $info) {
    $rel = substr((string) $path, strlen($webSrc) + 1);
    if (in_array(explode($sep, $rel)[0], ['_auth_keys', '_uploads', '_backups', '_libraries'], true) === true) {
        continue;
    }
    if ($info->isLink() === true) {
        refuse('web/ holds a link (' . $rel . ')');
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
$creds = ['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort];
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$router = <<<'ROUTER'
<?php
declare(strict_types=1);
header('X-P2a-Selftest: __TOKEN__');
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

echo "A care case records which member it is about - self-test (#1066)\n";
echo 'port ' . $port . ', PHP ' . PHP_VERSION . ', database ' . (string) one('SELECT VERSION() AS v')['v'] . "\n";

$jar = [];
foreach (array_keys($logins) as $who) {
    $jar[$who] = $child . $sep . 'jar-' . $who . '.txt';
    $tok       = token(req($jar[$who], 'GET', '/login')['body']);
    $in        = req($jar[$who], 'POST', '/login', ['csrf_token' => $tok, 'username' => 'selftest-p2a-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 || str_contains($in['location'], 'login') === true) {
        refuse("signing in as {$who} did not work (HTTP {$in['status']} {$in['location']})");
    }
}

/** Post a new case as the worker, with a fresh form token. @param array<string,mixed> $fields */
$post = static function (array $fields) use (&$jar): array {
    $form = req($jar['wrk'], 'GET', '/care');
    $base = ['csrf_token' => token($form['body']), 'category' => 'family', 'summary' => 'P2A case summary'];

    return req($jar['wrk'], 'POST', '/care/case-save', array_merge($base, $fields));
};
/** The care page's message box text, or ''. */
$flash = static function () use (&$jar): string {
    $html = req($jar['wrk'], 'GET', '/care')['body'];

    return preg_match('/<div class="alert alert-\w+" role="alert" data-care-flash>(.*?)<\/div>/s', $html, $m) === 1 ? text($m[1]) : '';
};
/** The numbers of the members the form offers. @return list<string> */
$offered = static function () use (&$jar): array {
    $doc  = \Dom\HTMLDocument::createFromString(req($jar['wrk'], 'GET', '/care')['body'], LIBXML_NOERROR);
    $list = [];
    foreach ($doc->querySelectorAll('select[name="personUserID"] option') as $o) {
        $list[] = (string) $o->getAttribute('value');
    }

    return $list;
};

// =============================================================================
head('T01 the form');
// =============================================================================
$page = req($jar['wrk'], 'GET', '/care');
$doc  = \Dom\HTMLDocument::createFromString($page['body'], LIBXML_NOERROR);
$sel  = $doc->querySelector('select[name="personUserID"]');
$opts = $offered();
$first = $doc->querySelector('select[name="personUserID"] option');
ok('T01a the care page opens for the worker', $page['status'] === 200, 'HTTP ' . $page['status']);
ok('T01b the select is required, and starts on a disabled, selected placeholder, then the "none" option',
    $sel !== null && $sel->hasAttribute('required') === true && $first !== null && $first->getAttribute('value') === ''
    && $first->hasAttribute('disabled') === true && $first->hasAttribute('selected') === true && ($opts[1] ?? '') === 'none',
    json_encode($opts));
ok('T01c it lists this organisation\'s members (the worker, the administrator, Mel) and NOT the other organisation\'s member, an erased account or a switched-off one',
    in_array((string) MEM, $opts, true) && in_array((string) WRK, $opts, true) && in_array((string) ADM, $opts, true)
    && in_array((string) NOB, $opts, true) === false && in_array((string) ERASED_ACC, $opts, true) === false && in_array((string) OFF, $opts, true) === false,
    json_encode($opts));
ok('T01d the typed-name box is still there and says it is for somebody with no account',
    str_contains(text($page['body']), 'Their name, if they have no account here') && $doc->querySelector('input[name="personName"]') !== null);

// =============================================================================
head('T02 choosing a member');
// =============================================================================
$saved = $post(['personUserID' => (string) MEM, 'personName' => 'Typed Over']);
$row   = one('SELECT * FROM `tblCareCase` WHERE `siteID` = ? AND `summary` = ?', 'is', [ORG_A, 'P2A case summary']);
ok('T02 the case is about Mel (her number), no typed name is kept, and it records who opened it by name (D-110)',
    $saved['status'] === 302 && $row !== null && (int) $row['personUserID'] === MEM && $row['personName'] === null
    && (int) $row['openedByID'] === WRK && $row['openedByName'] === 'Wanda Worker', json_encode($row) . ' HTTP ' . $saved['status']);
$caseId = (int) ($row['caseID'] ?? 0);

// =============================================================================
head('T03/T04 the member can see it');
// =============================================================================
$myData = (string) req($jar['mem'], 'GET', '/account/my-data')['body'];
$count  = 0;
$mdoc   = \Dom\HTMLDocument::createFromString($myData, LIBXML_NOERROR);
foreach ($mdoc->querySelectorAll('.portal-data-list > .row') as $r) {
    $code = $r->querySelector('code');
    $n    = $r->querySelector('.col-md-1 strong');
    if ($code !== null && trim((string) $code->textContent) === 'tblCareCase' && $n !== null) {
        $count += (int) trim((string) $n->textContent);
    }
}
ok('T03 Mel\'s My data counts the case', $count === 1, 'counted ' . $count);
$copyRaw = (string) req($jar['mem'], 'GET', '/account/data-export')['body'];
$copy    = json_decode($copyRaw, true);
$cases   = is_array($copy) === true ? ($copy['data']['careCasesAboutYou'] ?? null) : null;
ok('T04 Mel\'s data copy holds the case\'s summary, and not the worker\'s name or the case number (D-184)',
    is_array($cases) === true && count($cases) === 1 && ($cases[0]['summary'] ?? '') === 'P2A case summary'
    && str_contains($copyRaw, 'Wanda') === false && str_contains($copyRaw, '"caseID"') === false, mb_substr($copyRaw, 0, 400));

// =============================================================================
head('T06 refusals write nothing');
// =============================================================================
$before   = worldCases();
$refusals = [
    'a member of the OTHER organisation' => (string) NOB,
    'an erased account'                  => (string) ERASED_ACC,
    'a switched-off account'             => (string) OFF,
    'text'                               => 'abc',
    'empty'                              => '',
    'an array'                           => ['1'],
    'zero'                               => '0',
    'minus one'                          => '-1',
    'a number with a leading zero'       => '0' . MEM,
];
foreach ($refusals as $label => $value) {
    $r    = $post(['personUserID' => $value, 'summary' => 'P2A refused ' . $label]);
    $told = $flash();
    ok('T06 ' . $label . ': refused with the plain message, nothing written, no server error',
        $r['status'] === 302 && worldCases() === $before && str_contains($told, 'That person cannot be chosen here')
        && (int) one('SELECT COUNT(*) AS n FROM `tblCareCase` WHERE `summary` LIKE ?', 's', ['P2A refused%'])['n'] === 0,
        'HTTP ' . $r['status'] . ' told "' . $told . '" rows ' . worldCases() . ' vs ' . $before);
}

// =============================================================================
head('T07 / T08 no account, and an older form');
// =============================================================================
$none = $post(['personUserID' => 'none', 'personName' => 'Fen Typed', 'summary' => 'P2A none']);
$rowN = one('SELECT `personUserID`, `personName` FROM `tblCareCase` WHERE `summary` = ?', 's', ['P2A none']);
ok('T07 "none" with a typed name stores the name and no member', $none['status'] === 302 && $rowN !== null && $rowN['personUserID'] === null && $rowN['personName'] === 'Fen Typed', json_encode($rowN));
$old  = $post(['personName' => 'Old Form', 'summary' => 'P2A old']);
$rowO = one('SELECT `personUserID`, `personName` FROM `tblCareCase` WHERE `summary` = ?', 's', ['P2A old']);
ok('T08 with the field absent (an older form) the typed name is stored as before', $old['status'] === 302 && $rowO !== null && $rowO['personUserID'] === null && $rowO['personName'] === 'Old Form', json_encode($rowO));

// =============================================================================
head('T05 an erasure of the member');
// =============================================================================
$visitPage = req($jar['wrk'], 'GET', '/care/case?id=' . $caseId);
req($jar['wrk'], 'POST', '/care/visit-save', ['csrf_token' => token($visitPage['body']), 'caseID' => (string) $caseId, 'kind' => 'call', 'notes' => 'P2A visit note about Mel.']);
ok('T05a premise: a visit note is on the case before the erasure', (int) one('SELECT COUNT(*) AS n FROM `tblCareVisit` WHERE `caseID` = ? AND `notes` = ?', 'is', [$caseId, 'P2A visit note about Mel.'])['n'] === 1);
$form = req($jar['mem'], 'GET', '/account/delete');
req($jar['mem'], 'POST', '/account/delete/confirm', ['csrf_token' => token($form['body']), 'confirm_phrase' => 'DELETE MY ACCOUNT']);
$request = one('SELECT `requestID` FROM `tblErasureRequest` WHERE `userID` = ? ORDER BY `requestID` DESC LIMIT 1', 'i', [MEM]);
$requestId = (int) ($request['requestID'] ?? 0);
$jar['adm2'] = $jar['adm'];
$list = req($jar['adm'], 'GET', '/admin/erasure-requests');
req($jar['adm'], 'POST', '/admin/erasure-requests/process', ['csrf_token' => token($list['body']), 'requestID' => $requestId, 'action' => 'execute']);
$after = one('SELECT * FROM `tblCareCase` WHERE `caseID` = ?', 'i', [$caseId]);
$visit = one('SELECT `notes` FROM `tblCareVisit` WHERE `caseID` = ?', 'i', [$caseId]);
$mem   = one('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ?', 'i', [MEM]);
ok('T05b the request was carried out (account emptied)', $mem !== null && $mem['fullName'] === '[Deleted User]', json_encode($mem));
ok('T05c the case row STAYS, its summary and typed name are emptied, and the worker\'s name on it is kept (D-016, D-110)',
    $after !== null && ($after['summary'] === null || $after['summary'] === '') && ($after['personName'] === null || $after['personName'] === '')
    && $after['openedByName'] === 'Wanda Worker' && (int) $after['openedByID'] === WRK, json_encode($after));
ok('T05d the visit note on that case is emptied too (#709: reached through the member\'s number)',
    $visit !== null && ($visit['notes'] === null || $visit['notes'] === ''), json_encode($visit));

// =============================================================================
head('Plants: each fault, put in the running copy, must turn its check red');
// =============================================================================
// A fresh member for the plants (Mel is erased now).
const MEM2 = 918017;
q("INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, 'Pat Plant', 'u918017.p2a@selftest.invalid', 1, 0, 0)", 'i', [MEM2]);
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 0, 0)', 'ii', [MEM2, ORG_A]);

$plants = [
    'P1 the chosen member\'s number not stored' => [
        '_apps/care/case-save.php', '$personUserId = $candidate;', '$personUserId = null;',
        static function () use ($post): bool {
            $tag = 'P2A plant one ' . bin2hex(random_bytes(4));
            $post(['personUserID' => (string) MEM2, 'summary' => $tag]);
            $row = one('SELECT `personUserID` FROM `tblCareCase` WHERE `summary` = ?', 's', [$tag]);

            return $row !== null && (int) $row['personUserID'] === MEM2;
        },
    ],
    'P2 the membership test removed' => [
        '_apps/care/case-save.php', 'Site::isOwnOrganisation($candidate, $siteId, $db) === true', 'true',
        static function () use ($post): bool {
            $before = worldCases();
            $post(['personUserID' => (string) NOB, 'summary' => 'P2A plant two ' . $GLOBALS['runTag']]);

            return worldCases() === $before;
        },
    ],
    'P4 the list with no organisation filter' => [
        '_core/Site.php', '`us`.`siteID` = ? AND `us`.`isActive` = 1 \'', '`us`.`siteID` > ? AND `us`.`isActive` = 1 \'',
        static function () use ($offered): bool {
            return in_array((string) NOB, $offered(), true) === false;
        },
    ],
    'P5 "?? \'\'" in place of "did the field arrive"' => [
        '_apps/care/case-save.php', "array_key_exists('personUserID', \$_POST) === true", "((\$_POST['personUserID'] ?? '') !== '')",
        static function () use ($post): bool {
            // An EMPTY value must be refused; read with `?? ''` it looks like an absent field and is saved as a plain typed-name case.
            $before = worldCases();
            $post(['personUserID' => '', 'summary' => 'P2A plant five ' . $GLOBALS['runTag']]);

            return worldCases() === $before;
        },
    ],
];
foreach ($plants as $name => [$file, $from, $to, $check]) {
    $applied = plant($file, $from, $to);
    if ($applied === false) {
        ok($name . ': the fault was applied to the running copy', false, 'the text to replace was not found exactly once');
        continue;
    }
    $caught = $check() === false;
    unplant();
    ok($name . ': planted, its check goes RED (and the copy is put back)', $caught === true);
    $restored = $check();
    ok($name . ': restored, the same check is green again', $restored === true);
}
// P1 and P2 style leftovers must not remain: the cases the plants wrote are removed by the clean-up.

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
exit($fail === 0 ? 0 : 1);
