<?php
/**
 * -----------------------------------------------------------------------------
 * Path: tools/visitors-access-selftest.php
 *
 * Who may open the first-time visitor pages (#1151, D-017).
 *
 * WHY: every file in web/_apps/visitors/ checked only that somebody was signed
 * in, so any member could read a first-time visitor's name, e-mail address and
 * telephone number, and add visitors or record contacts. The pages now ask
 * Capabilities::VISITORS_FOLLOW_UP (an administrator, or the role it is pointed
 * at; shipping `visitor_coordinator`). This drives the real pages over HTTP:
 * a plain member is refused (403) on all six and no visitor row is written; a
 * visitor coordinator and an administrator get in. A plain member also sees no
 * Visitors entry on the dashboard.
 *
 * WHAT IT CANNOT DO: one worker and one organisation. It does not check the
 * public "I'm new" form (/visit), which is meant for strangers and is not gated.
 *
 * Usage: SELFTEST_DB_NAME=selftest_visitors SELFTEST_DB_PASS=... SELFTEST_DB_PORT=... \
 *        php tools/visitors-access-selftest.php
 * Optional SELFTEST_WEB_PORT (default 9171). The database must already hold
 * web/_sql/full_schema.sql. Refuses rather than skipping when anything is missing.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1151
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    echo '      wanted: ' . var_export($want, true) . "\n";
    echo '      got:    ' . var_export($got, true) . "\n";
}

// ── What it needs ────────────────────────────────────────────────────────────
$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$tables = (int) ($db->query(
    'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'visitors-access-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A folder THIS run made, under the system temp folder, random name.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});
mkdir($child, 0700, true);
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $rel = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    // Nobody's real uploads come along: the copy starts with none.
    if (str_starts_with($rel, $sep . '_uploads') === true) {
        continue;
    }
    $to = $child . $sep . 'web' . $rel;
    if ($entry->isDir() === true) {
        mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/visitors-access-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/visitors-access-selftest.php for ONE run. Test only.
declare(strict_types=1);
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
file_put_contents($child . $sep . 'router.php', $router);

$port     = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9171;
$jar      = $child . $sep . 'cookies-member.txt';
$state['jar'] = $jar;
$phpLog   = $child . $sep . 'php-errors.log';

/** Start (or restart) the server against the child tree. */
$startServer = static function () use (&$state, $child, $sep, $port, $phpLog): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $state['proc'] = proc_open(
        [
            PHP_BINARY,
            // E_ALL, said on the command line as well as by the portal's own
            // start-up, so a deprecation cannot be hidden by either.
            '-d', 'error_reporting=' . E_ALL,
            '-d', 'session.save_path=' . $child . DIRECTORY_SEPARATOR . 'sessions',
            '-d', 'error_log=' . $phpLog,
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=24M',
            '-d', 'max_execution_time=120',
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $child . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html',
            $child . DIRECTORY_SEPARATOR . 'router.php',
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/**
 * One request. `$post` is either form fields (an array of strings) or, when
 * it holds a CURLFile, a multipart upload. Returns status, lower-cased
 * headers, the Location and the body.
 */
$http = static function (string $method, string $path, array $post = [], array $headers = [], bool $useJar = true) use ($port, &$state): array {
    $jar = $state['jar'];
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($useJar === true) {
        $opts[CURLOPT_COOKIEJAR]  = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($method === 'POST') {
        $multipart = false;
        foreach ($post as $v) {
            if ($v instanceof CURLFile) {
                $multipart = true;
            }
        }
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $multipart === true ? $post : http_build_query($post);
    }
    curl_setopt_array($handle, $opts);
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $size  = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $named = [];
    foreach (preg_split('/\r?\n/', trim(substr((string) $raw, 0, $size))) ?: [] as $i => $line) {
        if ($i === 0 || str_contains($line, ':') === false) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $named[strtolower(trim($k))] = trim($v);
    }
    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'headers'  => $named,
        'location' => $named['location'] ?? '',
        'body'     => substr((string) $raw, $size),
    ];
};

$tokenOn = static function (string $html): string {
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

$waitForServer = static function () use ($http): void {
    for ($i = 0; $i < 80; $i++) {
        if ($http('GET', '/login', [], [], false)['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

$startServer();
$waitForServer();

// ── The world: a plain member, a visitor coordinator, an administrator ──────
$siteId = (int) ($db->query('SELECT MIN(siteID) AS s FROM tblSites')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
$tag = 'va' . bin2hex(random_bytes(4));
$password = 'Va' . bin2hex(random_bytes(8)) . '!9x';
$hash = password_hash($password, PASSWORD_DEFAULT);

$makePerson = static function (string $label, bool $admin) use ($db, $siteId, $tag, $hash): array {
    $email = $tag . $label . '@selftest.invalid';
    $name  = 'Visitors Test ' . $label;
    $flag  = $admin === true ? 1 : 0;
    $ins = $db->prepare('INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, isRootAdmin, createdAt) VALUES (?, ?, 1, ?, ?, NOW())');
    $ins->bind_param('ssii', $name, $email, $flag, $flag);
    $ins->execute();
    $userId = (int) $db->insert_id;
    $ins->close();
    $db->query("INSERT IGNORE INTO tblUserSites (siteID, userID, isSiteAdmin, isSiteRootAdmin, isActive) VALUES ({$siteId}, {$userId}, {$flag}, {$flag}, 1)");
    $signIn = 'selftest-' . $tag . $label;
    $acc = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
    $acc->bind_param('iss', $userId, $signIn, $hash);
    $acc->execute();
    $acc->close();
    return ['id' => $userId, 'signIn' => $signIn, 'jar' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'va-' . $tag . $label . '.txt'];
};
$member = $makePerson('member', false);
$coord  = $makePerson('coord', false);
$admin  = $makePerson('admin', true);
$roleId = (int) ($db->query("SELECT roleID FROM tblRoles WHERE siteID = {$siteId} AND roleKey = 'visitor_coordinator' LIMIT 1")->fetch_assoc()['roleID'] ?? 0);
if ($roleId <= 0) {
    refuse('this organisation has no visitor_coordinator role');
}
$db->query("INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES ({$coord['id']}, {$roleId}, {$siteId})");
register_shutdown_function(static function () use ($member, $coord, $admin): void {
    foreach ([$member, $coord, $admin] as $p) {
        @unlink($p['jar']);
    }
});

// One visitor to look at, with details the member must never see.
$visitorName = 'Stranger ' . $tag;
$visitorMail = $tag . '-visitor@selftest.invalid';
$vs = $db->prepare("INSERT INTO tblVisitor (siteID, fullName, email, phone, assignedToID) VALUES (?, ?, ?, '07700900123', ?)");
$vs->bind_param('issi', $siteId, $visitorName, $visitorMail, $coord['id']);
$vs->execute();
$visitorId = (int) $db->insert_id;
$vs->close();
$visitorCount = static function () use ($db, $siteId): int {
    return (int) $db->query("SELECT COUNT(*) AS n FROM tblVisitor WHERE siteID = {$siteId}")->fetch_assoc()['n'];
};
$contactCount = static function () use ($db, $visitorId): int {
    return (int) $db->query("SELECT COUNT(*) AS n FROM tblVisitorContact WHERE visitorID = {$visitorId}")->fetch_assoc()['n'];
};

$signInAs = static function (array $who) use (&$state, $http, $tokenOn): void {
    $state['jar'] = $who['jar'];
    @unlink($who['jar']);
    $page  = $http('GET', '/login');
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $who['signIn'], 'password' => $GLOBALS['password']]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse('signing ' . $who['signIn'] . ' in did not redirect (status ' . $in['status'] . ')');
    }
};
$freshToken = static function () use ($http, $tokenOn): string {
    $t = $tokenOn($http('GET', '/')['body']);
    if ($t === '') {
        refuse('no form token on the dashboard');
    }
    return $t;
};

$pages = [
    ['GET',  '/visitors',                              []],
    ['GET',  '/visitors/profile?id=' . $visitorId,     []],
    ['GET',  '/visitors/new',                          []],
    ['GET',  '/visitors/my-follow-ups',                []],
];

echo "A. A plain member is refused on all six, and nothing is written\n";
$signInAs($member);
$before = $visitorCount();
foreach ($pages as [$method, $path]) {
    $r = $http($method, $path);
    check("A {$path} refused with 403", $r['status'], 403);
    check("A {$path} shows no visitor details", str_contains($r['body'], $visitorName) || str_contains($r['body'], $visitorMail) || str_contains($r['body'], '07700900123'), false);
}
$r = $http('POST', '/visitors/save', ['csrf_token' => $freshToken(), 'fullName' => 'Planted ' . $tag, 'source' => 'other']);
check('A POST /visitors/save refused with 403', $r['status'], 403);
check('A no visitor was added', $visitorCount(), $before);
$r = $http('POST', '/visitors/save', ['csrf_token' => $freshToken(), 'updateStatus' => '1', 'visitorID' => (string) $visitorId, 'status' => 'lost']);
check('A POST /visitors/save (status change) refused with 403', $r['status'], 403);
check('A the visitor\'s status is unchanged', (string) $db->query("SELECT status FROM tblVisitor WHERE visitorID = {$visitorId}")->fetch_assoc()['status'], 'new');
$r = $http('POST', '/visitors/contact-save', ['csrf_token' => $freshToken(), 'visitorID' => (string) $visitorId, 'method' => 'phone', 'notes' => 'planted ' . $tag]);
check('A POST /visitors/contact-save refused with 403', $r['status'], 403);
check('A no contact was recorded', $contactCount(), 0);
$home = $http('GET', '/')['body'];
check('A the dashboard offers a member no Visitors card', str_contains($home, 'href="/visitors"'), false);

echo "B. A visitor coordinator gets in (proves A could fail)\n";
$signInAs($coord);
foreach ($pages as [$method, $path]) {
    $r = $http($method, $path);
    check("B {$path} answers 200", $r['status'], 200);
}
check('B the board shows the visitor', str_contains($http('GET', '/visitors')['body'], $visitorName), true);
$r = $http('POST', '/visitors/save', ['csrf_token' => $freshToken(), 'fullName' => 'Added ' . $tag, 'source' => 'other']);
check('B a coordinator can add a visitor', [in_array($r['status'], [302, 303], true), $visitorCount()], [true, $before + 1]);
$r = $http('POST', '/visitors/contact-save', ['csrf_token' => $freshToken(), 'visitorID' => (string) $visitorId, 'method' => 'phone', 'notes' => 'ok ' . $tag]);
check('B a coordinator can record a contact', [in_array($r['status'], [302, 303], true), $contactCount()], [true, 1]);

check('B the dashboard offers a coordinator the Visitors card (proves the member check could fail)', str_contains($http('GET', '/')['body'], 'href="/visitors"'), true);

echo "C. An administrator gets in\n";
$signInAs($admin);
check('C /visitors answers 200', $http('GET', '/visitors')['status'], 200);
check('C /visitors/profile answers 200', $http('GET', '/visitors/profile?id=' . $visitorId)['status'], 200);

// Clean up what this run made.
$db->query("DELETE FROM tblVisitorContact WHERE visitorID = {$visitorId}");
$db->query("DELETE FROM tblVisitor WHERE siteID = {$siteId} AND (fullName LIKE '%{$tag}%')");
foreach ([$member, $coord, $admin] as $p) {
    $db->query("DELETE FROM tblActivityLogs WHERE userID = {$p['id']}");
    $db->query("DELETE FROM tblUserRoles WHERE userID = {$p['id']}");
    $db->query("DELETE FROM tblLocalAccounts WHERE userID = {$p['id']}");
    $db->query("DELETE FROM tblUserSites WHERE userID = {$p['id']}");
    $db->query("DELETE FROM tblUsers WHERE userID = {$p['id']}");
}
echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
