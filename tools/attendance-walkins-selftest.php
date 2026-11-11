<?php
// Path: tools/attendance-walkins-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The attendance grid and a walk-in written in different capitals 🚶🧪 (#655)
 * -----------------------------------------------------------------------------
 * Serves a scratch copy of the portal with PHP's own web server on a REAL
 * database and DRIVES the grid over HTTP (D-065: where the property is "this
 * code runs", drive it).
 *
 * THE FAULT (#655). The grid listed walk-ins with `SELECT DISTINCT walkinName`
 * (the database compares case-insensitively) and keyed its ticks in PHP by
 * exact bytes. "Dave" on one day and "dave" on another therefore drew as ONE
 * row whose second day looked unattended; its cell posted `toggle=1`, which
 * the database's own unique key swallowed, so the row could be neither seen
 * nor removed.
 *
 * WHAT IT CHECKS (each scenario is run on the real code, then with the fault
 * planted, then with the code put back):
 *   A. "Dave" (day 1, posted through the real mark handler), "dave" (day 2,
 *      likewise) and "DAVE " with a trailing space (day 3, written straight
 *      into the table, because the handler trims — the shape restored or
 *      hand-edited data has): ONE walk-in row, and ALL THREE cells ticked.
 *   B. Pressing a ticked cell exactly as the page posts it (`toggle=0` and the
 *      name the page printed) removes THAT day's row and only that day's.
 *   C. The database's own rule is what groups: "Zoe" and "Zoë" (equal in
 *      utf8mb4_general_ci) are one walk-in; "Strasse" and "Strasse" spelt with
 *      the sharp s (not equal) are two. Measured on MySQL 8.0.36 and asserted
 *      here so a change in the database's rule is noticed, not assumed.
 *   D. PLANTED FAULT: the grid's grouping put back to exact bytes must turn
 *      checks A and B red. A test that cannot see the fault proves nothing.
 *
 * WHAT IT CANNOT DO. It runs as one made-up administrator on one engine (the
 * one SELFTEST_DB_* points at; CI uses MySQL 8.0.36). It does not prove the
 * rule on MariaDB or on a server whose column collation was changed, and it
 * does not look at how the grid LOOKS.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… SELFTEST_DB_PASS=… \
 *             php tools/attendance-walkins-selftest.php
 *         Also SELFTEST_DB_HOST (127.0.0.1), SELFTEST_DB_USER (root),
 *         SELFTEST_WEB_DIR (a folder outside the repository), and
 *         SELFTEST_WEB_PORT (default 9655). The database must already hold
 *         `web/_sql/full_schema.sql`. Needs curl and proc_open.
 * Exit:   0 only when every check ran and passed; 1 otherwise.
 *
 * Rows it adds: one person and one event, numbered 927001; removed afterwards.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/655
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const ADMIN = 927001;
const EVENT = 927001;
const EVENT_OTHER = 927002;      // same organisation, another event
const EVENT_OTHER_ORG = 927003;  // another organisation's event
const SITE_OTHER = 927001;       // another organisation (inactive)

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
$sep     = DIRECTORY_SEPARATOR;
$repo    = (string) realpath(dirname(__DIR__));
$webSrc  = $repo . $sep . 'web';
$parentRaw = getenv('SELFTEST_WEB_DIR');
$parent  = realpath(($parentRaw === false || $parentRaw === '') ? sys_get_temp_dir() : (string) $parentRaw);
if ($parent === false || is_dir($parent) === false) {
    refuse('the scratch parent folder (SELFTEST_WEB_DIR) does not exist');
}
if (str_starts_with($repo . $sep, $parent . $sep) === true || str_starts_with($parent . $sep, $repo . $sep) === true) {
    refuse("the scratch parent folder '{$parent}' and the repository must not contain each other; choose another with SELFTEST_WEB_DIR");
}
$portRaw = getenv('SELFTEST_WEB_PORT');
$port    = ($portRaw === false || $portRaw === '') ? 9655 : (int) $portRaw;
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
/** @var list<string> every label that failed, for the planted runs to name */
$failedLabels = [];

/** ✅ Report one check. */
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail, $failedLabels;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    $failedLabels[] = $label;
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

/** Remove this test's rows; returns how many are left (must be none). */
function removeWorld(): int
{
    foreach ([EVENT, EVENT_OTHER, EVENT_OTHER_ORG] as $e) {
        q('DELETE FROM `tblEventAttendance` WHERE `eventID` = ?', 'i', [$e]);
        q('DELETE FROM `tblEvents` WHERE `eventID` = ?', 'i', [$e]);
    }
    q('DELETE FROM `tblSites` WHERE `siteID` = ?', 'i', [SITE_OTHER]);
    q('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([['tblActivityLog', 'userID'], ['tblLocalAccounts', 'userID'], ['tblUserSites', 'userID'], ['tblUsers', 'userID']] as [$t, $c]) {
            try {
                q('DELETE FROM `' . $t . '` WHERE `' . $c . '` = ?', 'i', [ADMIN]);
            } catch (Throwable $e) {
                // a table this schema does not have: nothing of ours is in it
            }
        }
    } finally {
        q('SET FOREIGN_KEY_CHECKS = 1');
    }

    return (int) q('SELECT COUNT(*) AS `n` FROM `tblUsers` WHERE `userID` = ?', 'i', [ADMIN])[0]['n']
        + (int) q('SELECT COUNT(*) AS `n` FROM `tblEvents` WHERE `eventID` IN (?, ?, ?)', 'iii', [EVENT, EVENT_OTHER, EVENT_OTHER_ORG])[0]['n']
        + (int) q('SELECT COUNT(*) AS `n` FROM `tblSites` WHERE `siteID` = ?', 'i', [SITE_OTHER])[0]['n'];
}

function portAnswers(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.5);
    if (is_resource($socket) === true) {
        fclose($socket);

        return true;
    }

    return false;
}

$state = ['started' => false, 'cleaned' => false, 'proc' => null, 'child' => '', 'token' => bin2hex(random_bytes(16)), 'left' => -1];

/** Put back everything this run changed, ONCE. Also a shutdown function. */
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
        $state['left'] = removeWorld();
    } catch (Throwable $e) {
        echo 'FAIL — the clean-up could not put the database back: ' . $e->getMessage() . "\n";
    }
    $child = (string) $state['child'];
    if ($child !== '' && is_link($child) === false
        && @file_get_contents($child . DIRECTORY_SEPARATOR . '.attendance-walkins-owner') === $state['token']) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($child, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $path => $info) {
            ($info->isDir() === true && $info->isLink() === false) ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($child);
    }
}

/**
 * One real HTTP request to the server this run started; redirects are not
 * followed. An answer without this run's header is returned as status -1.
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Expect:', 'Accept-Language: en'],
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
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);
    if (preg_match('/^X-Attendance-Walkins-Selftest:\s*' . $state['token'] . '\s*$/mi', $head) !== 1) {
        return ['status' => -1, 'location' => '', 'body' => 'an answer without this run\'s header'];
    }

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S*)\s*$/mi', $head, $l) === 1 ? $l[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
}

/** The form token on a page. */
function token(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

register_shutdown_function('cleanup');

// =============================================================================
// 🏗️ The world, the scratch copy and the server
// =============================================================================
$state['started'] = true;
if (removeWorld() > 0) {
    refuse('rows with this test\'s numbers (927001) are in the database and could not be removed');
}
$site = (int) (q('SELECT MIN(`siteID`) AS `s` FROM `tblSites` WHERE `isActive` = 1')[0]['s'] ?? 0);
if ($site <= 0) {
    refuse('the database has no organisation: load web/_sql/full_schema.sql first');
}

$password = bin2hex(random_bytes(12)) . 'Aa1!';
$hash     = password_hash($password, PASSWORD_DEFAULT);
q('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, 1, 1, 1)', 'iss',
    [ADMIN, 'Walkins Selftest Admin', 'selftest-walkins@attendance-walkins.selftest.invalid']);
q('INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, 1, 1)', 'ii', [ADMIN, $site]);
q('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)', 'iss', [ADMIN, 'selftest-walkins-admin', $hash]);
// A three-day event (UTC, so no clock change falls inside it).
q('INSERT INTO `tblEvents` (`eventID`, `siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`) VALUES (?, ?, ?, ?, ?, ?, ?)', 'iisssss',
    [EVENT, $site, 'Walk-in selftest event', 'walkins-selftest-' . EVENT, '2027-01-01 10:00:00', '2027-01-03 12:00:00', 'UTC']);
q('INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`, `isActive`) VALUES (?, ?, ?, 0)', 'iss', [SITE_OTHER, 'Walkins selftest other organisation', 'walkins-selftest-other']);
q('INSERT INTO `tblEvents` (`eventID`, `siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`) VALUES (?, ?, ?, ?, ?, ?, ?)', 'iisssss',
    [EVENT_OTHER, $site, 'Walk-in selftest other event', 'walkins-selftest-' . EVENT_OTHER, '2027-01-01 10:00:00', '2027-01-03 12:00:00', 'UTC']);
q('INSERT INTO `tblEvents` (`eventID`, `siteID`, `eventName`, `eventSlug`, `startDateTime`, `endDateTime`, `timezone`) VALUES (?, ?, ?, ?, ?, ?, ?)', 'iisssss',
    [EVENT_OTHER_ORG, SITE_OTHER, 'Walk-in selftest other organisation event', 'walkins-selftest-' . EVENT_OTHER_ORG, '2027-01-01 10:00:00', '2027-01-03 12:00:00', 'UTC']);
$days = ['2027-01-01', '2027-01-02', '2027-01-03'];

$child = $parent . $sep . 'attendance-walkins-selftest-' . getmypid() . '-' . bin2hex(random_bytes(8));
if (file_exists($child) === true || @mkdir($child, 0700) === false) {
    refuse("could not make a NEW scratch folder '{$child}'");
}
$state['child'] = $child;
file_put_contents($child . $sep . '.attendance-walkins-owner', $state['token']); // the marker FIRST
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
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\n// Written by tools/attendance-walkins-selftest.php for one run; deleted with its folder.\nreturn " . var_export($creds, true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

$router = <<<'ROUTER'
<?php
// Written by tools/attendance-walkins-selftest.php for ONE run (deleted with its folder). Test only.
declare(strict_types=1);
header('X-Attendance-Walkins-Selftest: __TOKEN__');
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
        '-d', 'display_errors=0', '-d', 'max_execution_time=120', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
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

echo "\nThe attendance grid and walk-ins in different capitals — self-test (#655)\n";
echo 'server process ' . (int) proc_get_status($state['proc'])['pid'] . ', port ' . $port . ', PHP ' . PHP_VERSION
    . ', database ' . (string) q('SELECT VERSION() AS `v`')[0]['v'] . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";

// Sign in through the real page.
$jar    = $child . $sep . 'jar-admin.txt';
$answer = req($jar, 'POST', '/login', ['csrf_token' => token(req($jar, 'GET', '/login')['body']), 'username' => 'selftest-walkins-admin', 'password' => $password]);
if ($answer['status'] !== 302 || str_contains($answer['location'], '/login') === true) {
    refuse("signing in did not work (HTTP {$answer['status']} {$answer['location']})");
}

/** Open the grid and read it as a person would. @return array{status:int, rows:list<array{name:string, walkin:bool, cells:list<array{toggle:string, ref:string, day:string}>}>} */
function readGrid(): array
{
    global $jar;
    $page = req($jar, 'GET', '/calendar/event/attendance?eventID=' . EVENT);
    $rows = [];
    if ($page['status'] !== 200) {
        return ['status' => $page['status'], 'rows' => []];
    }
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $page['body']);
    // 📌 The grid is a list of `portal-data-row` divs (not a <table>): one per
    //    person, the first cell the name, the others one form per day.
    foreach ($dom->getElementsByTagName('div') as $row) {
        $class = ' ' . $row->getAttribute('class') . ' ';
        if (str_contains($class, ' portal-data-row ') === false || str_contains($class, ' portal-data-header ') === true) {
            continue;
        }
        $cells  = [];
        $walkin = false;
        foreach ($row->getElementsByTagName('span') as $span) {
            if (trim($span->textContent) === 'WI') {
                $walkin = true;
            }
        }
        foreach ($row->getElementsByTagName('form') as $form) {
            $f = ['toggle' => '', 'ref' => '', 'day' => ''];
            foreach ($form->getElementsByTagName('input') as $in) {
                $n = $in->getAttribute('name');
                if ($n === 'toggle') {
                    $f['toggle'] = $in->getAttribute('value');
                } elseif ($n === 'walkinRef') {
                    $f['ref'] = $in->getAttribute('value');
                } elseif ($n === 'dayDate') {
                    $f['day'] = $in->getAttribute('value');
                }
            }
            $cells[] = $f;
        }
        $name = '';
        foreach ($row->getElementsByTagName('div') as $cell) {
            if (str_contains(' ' . $cell->getAttribute('class') . ' ', ' attendance-name ') === true) {
                $name = trim(preg_replace('/\s+WI\s*$/', '', trim($cell->textContent)) ?? '');
                break;
            }
        }
        $rows[] = ['name' => $name, 'walkin' => $walkin, 'cells' => $cells];
    }

    return ['status' => 200, 'rows' => $rows];
}

/** Press an existing walk-in's cell exactly as the page posts it (a reference, never a name). */
function press(string $ref, string $day, string $toggle, int $event = EVENT): array
{
    global $jar;
    $page = req($jar, 'GET', '/calendar/event/attendance?eventID=' . EVENT);

    return req($jar, 'POST', '/calendar/event/attendance/mark', [
        'csrf_token' => token($page['body']), 'eventID' => (string) $event, 'walkinRef' => $ref, 'dayDate' => $day, 'toggle' => $toggle,
    ]);
}

/** Enrol a NEW walk-in by typing the name, as the enrol form posts it. */
function enrol(string $name, string $day): array
{
    global $jar;
    $page = req($jar, 'GET', '/calendar/event/attendance?eventID=' . EVENT);

    return req($jar, 'POST', '/calendar/event/attendance/mark', [
        'csrf_token' => token($page['body']), 'eventID' => (string) EVENT, 'walkinName' => $name, 'dayDate' => $day, 'toggle' => '1',
    ]);
}

/** @return list<string> "day|name" for every walk-in row held for an event. */
function rowsHeld(int $event = EVENT): array
{
    return array_map(
        static fn (array $r): string => (string) $r['dayDate'] . '|' . (string) $r['walkinName'],
        q('SELECT `dayDate`, `walkinName` FROM `tblEventAttendance` WHERE `eventID` = ? AND `userID` IS NULL ORDER BY `dayDate`, `attendanceID`', 'i', [$event])
    );
}

function addRow(int $event, string $name, string $day): int
{
    q('INSERT INTO `tblEventAttendance` (`eventID`, `walkinName`, `dayDate`) VALUES (?, ?, ?)', 'iss', [$event, $name, $day]);

    return (int) q('SELECT LAST_INSERT_ID() AS `i`')[0]['i'];
}

function clearAll(): void
{
    foreach ([EVENT, EVENT_OTHER, EVENT_OTHER_ORG] as $e) {
        q('DELETE FROM `tblEventAttendance` WHERE `eventID` = ?', 'i', [$e]);
    }
}

/** @return list<array{name:string, walkin:bool, cells:list<array{toggle:string, ref:string, day:string}>}> */
function walkinRows(): array
{
    return array_values(array_filter(readGrid()['rows'], static fn (array $r): bool => $r['walkin'] === true));
}

/** The toggles of the walk-in row whose drawn name is $name, e.g. "0,1,1". */
function togglesOf(string $name): string
{
    foreach (walkinRows() as $row) {
        if ($row['name'] === $name) {
            return implode(',', array_map(static fn (array $c): string => $c['toggle'], $row['cells']));
        }
    }

    return 'no such row';
}

/**
 * 🧪 The whole scenario, run on the real code, then once per planted fault, then
 * on the restored code. `$tag` names the run in the labels. Returns how many
 * checks failed in this run.
 */
function scenario(string $tag): int
{
    global $days, $fail;
    $failBefore = $fail;
    clearAll();

    // A. "Dave", "dave" and "DAVE " on three days.
    ok($tag . ' A01 enrolling "Dave" on day 1 through the form is accepted', enrol('Dave', $days[0])['status'] === 302);
    $firstRef = (string) (q('SELECT MIN(`attendanceID`) AS `i` FROM `tblEventAttendance` WHERE `eventID` = ?', 'i', [EVENT])[0]['i'] ?? 0);
    // A second spelling on another day is reached the way the grid reaches it: a press of the empty cell.
    ok($tag . ' A02 pressing the empty day-2 cell of that row adds day 2 to the same walk-in', press($firstRef, $days[1], '1')['status'] === 302);
    q('UPDATE `tblEventAttendance` SET `walkinName` = ? WHERE `eventID` = ? AND `dayDate` = ?', 'sis', ['dave', EVENT, $days[1]]); // the other spelling, as restored or hand-edited data has it
    addRow(EVENT, 'DAVE ', $days[2]);
    $held = rowsHeld();
    ok($tag . ' A03 the table holds three rows, one per day', count($held) === 3, implode(', ', $held));

    $grid    = readGrid();
    $walkins = walkinRows();
    ok($tag . ' A04 the grid opens', $grid['status'] === 200, 'HTTP ' . $grid['status']);
    ok($tag . ' A05 the three spellings are ONE walk-in row', count($walkins) === 1, count($walkins) . ' walk-in row(s)');
    $cells = $walkins[0]['cells'] ?? [];
    ok($tag . ' A06 that row has a cell for each of the three days', count($cells) === 3, count($cells) . ' cell(s)');
    foreach ($cells as $i => $cell) {
        ok(
            $tag . ' A07 day ' . ($i + 1) . ' draws as ATTENDED (its button would remove, toggle=0)',
            $cell['toggle'] === '0',
            'toggle=' . $cell['toggle'] . ' for ' . $cell['day'] . ' — the cell looks empty although a row exists'
        );
    }

    // B. Pressing a ticked cell as the page posts it removes that day only.
    if (count($cells) === 3) {
        $press = press($cells[1]['ref'], $cells[1]['day'], $cells[1]['toggle']);
        ok($tag . ' B01 pressing day 2 as the page posts it is accepted', $press['status'] === 302);
        $after = rowsHeld();
        ok($tag . ' B02 day 2\'s row is gone and days 1 and 3 are untouched', count($after) === 2 && str_starts_with($after[0], $days[0]) && str_starts_with($after[1], $days[2]), implode(', ', $after));
        ok($tag . ' B03 the grid now shows day 2 empty and days 1 and 3 attended', togglesOf('Dave') === '0,1,0', togglesOf('Dave'));
    }

    // C. The database's own rule decides what is "the same name".
    clearAll();
    foreach ([['Zoe', 0], ['Zoë', 1], ['Strasse', 0], ['Straße', 1]] as [$n, $d]) {
        addRow(EVENT, $n, $days[$d]);
    }
    $names = walkinRows();
    ok($tag . ' C01 "Zoe" and "Zoë" (equal in utf8mb4_general_ci) are one walk-in; "Strasse" and "Straße" (not equal) are two: three rows', count($names) === 3, count($names) . ' row(s): ' . implode(' / ', array_column($names, 'name')));
    foreach ($names as $row) {
        $ticks = count(array_filter($row['cells'], static fn (array $c): bool => $c['toggle'] === '0'));
        ok($tag . ' C02 "' . $row['name'] . '" shows every row held for it as attended', $ticks === (stripos($row['name'], 'zo') === 0 ? 2 : 1), $ticks . ' tick(s)');
    }
    ok($tag . ' C03 the list is in the database\'s order: "Strasse", "Straße", "Zoe"', array_column($names, 'name') === ['Straße', 'Strasse', 'Zoe'], implode(' / ', array_column($names, 'name')));

    // E. The characters the database does NOT ignore: a trailing tab, a leading space.
    clearAll();
    addRow(EVENT, 'DAVE', $days[0]);
    addRow(EVENT, "DAVE\t", $days[1]);   // a DIFFERENT walk-in to the database
    addRow(EVENT, 'Ann', $days[2]);
    addRow(EVENT, ' Ann', $days[0]);     // likewise
    // The grid TRIMS what it draws, so rows are found by the name as STORED,
    // looked up from the row number the first cell carries.
    $byName = [];
    foreach (walkinRows() as $row) {
        $stored = (string) (q('SELECT `walkinName` AS `n` FROM `tblEventAttendance` WHERE `attendanceID` = ?', 'i', [(int) ($row['cells'][0]['ref'] ?? 0)])[0]['n'] ?? '');
        $byName[$stored] = $row;
    }
    ok($tag . ' E01 "DAVE", "DAVE<TAB>", "Ann" and " Ann" are FOUR walk-ins', count($byName) === 4, implode(' / ', array_map('json_encode', array_keys($byName))));
    $tabRow = $byName["DAVE\t"]['cells'][1] ?? null;
    if ($tabRow !== null) {
        press($tabRow['ref'], $tabRow['day'], $tabRow['toggle']);
    }
    $left = rowsHeld();
    ok($tag . ' E02 pressing the "DAVE<TAB>" cell removes the tab row and leaves DAVE\'s', count($left) === 3 && in_array('2027-01-01|DAVE', $left, true) === true && in_array("2027-01-02|DAVE\t", $left, true) === false, implode(', ', array_map('json_encode', $left)));
    $spaceRow = $byName[' Ann']['cells'][0] ?? null;
    if ($spaceRow !== null) {
        press($spaceRow['ref'], $spaceRow['day'], $spaceRow['toggle']);
    }
    ok($tag . ' E03 pressing the " Ann" ticked cell removes its row (the old trim() matched nothing)', in_array('2027-01-01| Ann', rowsHeld(), true) === false, implode(', ', array_map('json_encode', rowsHeld())));
    // A reference is the group's OLDEST row, and E03 just removed it, so the
    // grid is read again for a current one — exactly what a person's next page
    // load does.
    $spaceRow2 = null;
    // The " Ann" group has no rows left after E03, so a press needs a row of it:
    // put one back by direct SQL, then press ITS empty neighbour.
    q('DELETE FROM `tblEventAttendance` WHERE `eventID` = ? AND `walkinName` = ? AND `dayDate` = ?', 'iss', [EVENT, ' Ann', $days[0]]); // a planted run may still hold it
    addRow(EVENT, ' Ann', $days[0]);
    foreach (walkinRows() as $row) {
        $storedName = (string) (q('SELECT `walkinName` AS `n` FROM `tblEventAttendance` WHERE `attendanceID` = ?', 'i', [(int) ($row['cells'][0]['ref'] ?? 0)])[0]['n'] ?? '');
        if ($storedName === ' Ann') {
            $spaceRow2 = $row['cells'][1] ?? null;
        }
    }
    if ($spaceRow2 !== null) {
        press($spaceRow2['ref'], $spaceRow2['day'], '1');
    }
    $heldNow = rowsHeld();
    ok($tag . ' E04 pressing an empty " Ann" cell adds the row to " Ann", not to "Ann"', in_array('2027-01-02| Ann', $heldNow, true) === true && in_array('2027-01-02|Ann', $heldNow, true) === false, implode(', ', array_map('json_encode', $heldNow)));

    // F. Scope: another event of this organisation, and another organisation's event.
    clearAll();
    addRow(EVENT, 'Eve', $days[0]);
    addRow(EVENT_OTHER, 'eve', $days[1]);
    addRow(EVENT_OTHER, 'Eve', $days[2]);
    $foreign    = addRow(EVENT_OTHER, 'Zed', $days[0]);
    $foreignOrg = addRow(EVENT_OTHER_ORG, 'EVE', $days[2]);
    $foreignOrgZ = addRow(EVENT_OTHER_ORG, 'Yan', $days[0]);
    ok($tag . ' F01 another event\'s "eve" does not tick this event\'s "Eve" (only day 1 is ticked)', togglesOf('Eve') === '0,1,1', togglesOf('Eve'));
    ok($tag . ' F02 another event\'s walk-in is not listed on this event\'s grid', count(walkinRows()) === 1, implode(' / ', array_column(walkinRows(), 'name')));
    $before = [rowsHeld(EVENT), rowsHeld(EVENT_OTHER), rowsHeld(EVENT_OTHER_ORG)];
    $a = press((string) $foreign, $days[0], '0');
    $b = press((string) $foreignOrgZ, $days[0], '0');
    $c = press((string) $foreignOrg, $days[2], '1');
    $d = press((string) $foreignOrg, $days[2], '1', EVENT_OTHER_ORG); // the other organisation's own event, own row
    ok($tag . ' F03 a reference from another event is answered "not found" (404)', $a['status'] === 404, 'HTTP ' . $a['status']);
    ok($tag . ' F04 a reference from another organisation is answered "not found" (404)', $b['status'] === 404 && $c['status'] === 404 && $d['status'] === 404, 'HTTP ' . $b['status'] . ' / ' . $c['status'] . ' / ' . $d['status']);
    ok($tag . ' F05 and no row anywhere was added or removed by them', [rowsHeld(EVENT), rowsHeld(EVENT_OTHER), rowsHeld(EVENT_OTHER_ORG)] === $before);

    return $fail - $failBefore;
}

/**
 * Plant ONE fault in the scratch copy, run the scenario, and return the labels
 * that failed (with the tag stripped). The caller puts the file back.
 *
 * @return list<string>
 */
function plantedRun(string $name, string $file, string $from, string $to): array
{
    global $pass, $fail, $failedLabels;
    $orig = (string) file_get_contents($file);
    if (substr_count($orig, $from) !== 1) {
        ok($name . ' the line to plant into is in the scratch copy exactly once', false, 'the text changed; update the plant: ' . $from);

        return [];
    }
    file_put_contents($file, str_replace($from, $to, $orig));
    clearstatcache(true, $file);
    $saved = [$pass, $fail];
    $failedLabels = [];
    scenario('[' . $name . ']');
    $labels = array_map(static fn (string $l): string => (string) preg_replace('/^\[[^\]]*\] (\S+).*$/u', '$1', $l), $failedLabels);
    // Failures here are EXPECTED: take them back out of the totals.
    [$pass, $fail] = $saved;
    file_put_contents($file, $orig);
    clearstatcache(true, $file);

    return $labels;
}

// =============================================================================
head('1. The real code');
$realFailures = scenario('[real]');

// =============================================================================
head('2. PLANTED FAULTS — each must turn the checks meant to catch it red');
$helper  = $child . $sep . 'web' . $sep . '_core' . $sep . 'AttendanceWalkins.php';
$handler = $child . $sep . 'web' . $sep . '_apps' . $sep . 'calendar' . $sep . 'event-attendance-mark.php';

$plants = [
    // [name, file, from, to, labels that MUST fail]
    ['byte-exact grouping', $helper, 'ON g.`walkinName` = a.`walkinName` AND', 'ON BINARY g.`walkinName` = BINARY a.`walkinName` AND', ['A07', 'B02', 'B03']],
    ['trim of the stored name in the handler (7a94388a)', $handler, '    $walkinName = $stored;', '    $walkinName = trim($stored);', ['E02', 'E03', 'E04']],
    ['grid not scoped to the event', $helper, 'WHERE a.`eventID` = ? \'', 'WHERE ? > 0 \'', ['F01']],
    ['reference lookup not scoped to the event', $helper, 'WHERE a.`attendanceID` = ? AND a.`eventID` = ? AND', 'WHERE a.`attendanceID` = ? AND ? > 0 AND', ['F03']],
];
foreach ($plants as [$name, $file, $from, $to, $mustFail]) {
    $labels = plantedRun($name, $file, $from, $to);
    $missing = array_values(array_diff($mustFail, $labels));
    ok(
        'D ' . $name . ' goes RED on ' . implode(', ', $mustFail) . ' (failed: ' . implode(', ', array_values(array_unique($labels))) . ')',
        $labels !== [] && $missing === [],
        'these checks did not fail and should have: ' . implode(', ', $missing)
    );
}

head('3. Put back, and green again');
$restored = scenario('[restored]');

cleanup();
head('Summary');
echo "Passed: {$pass}   Failed: {$fail}\n";
if ($state['left'] > 0) {
    echo "FAIL — {$state['left']} row(s) with this test's numbers are still in the database.\n";
    $fail++;
}
if ($fail > 0) {
    echo "\nThe grid and the database disagree about a walk-in, or this test could not see a planted fault.\n";
    exit(1);
}
echo "\nREACHED THE END — the grid agrees with the database, and every planted fault was seen.\n";
exit(0);
