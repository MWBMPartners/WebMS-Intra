<?php
/**
 * -----------------------------------------------------------------------------
 * Path: tools/noticeboard-save-selftest.php
 *
 * Two saves in one visit to the noticeboard both reach the database (#1141).
 *
 * WHY: the board's host calls api/noticeboard/save on every add, edit and
 * delete. After the first accepted post the session's form token is replaced
 * (Auth::verifyCsrf, #768), so a host that kept the token it was handed at page
 * load had its SECOND save refused while the change stayed on screen. This
 * drives the real handler over HTTP the way the fixed host does (each post
 * carries the replacement the last answer returned) and the way the old host
 * did (the page-load token again), and reads the rows back.
 *
 * WHAT IT CANNOT DO: it does not run the host's JavaScript; that is
 * tools/noticeboard-babel-browser-selftest.mjs (real browser). One worker, so
 * two simultaneous posts are untested (the host queues them; the browser test
 * shows that). Section C reads the served page's text, so it pins the host's
 * wording, not its behaviour.
 *
 * Usage: SELFTEST_DB_NAME=selftest_nbsave SELFTEST_DB_PASS=... SELFTEST_DB_PORT=... \
 *        php tools/noticeboard-save-selftest.php
 * The database must already hold web/_sql/full_schema.sql. Refuses rather than
 * skipping when anything is missing.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1141
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
$child = sys_get_temp_dir() . $sep . 'nb-save-' . bin2hex(random_bytes(6));
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
    "<?php\n// Written by tools/noticeboard-save-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/noticeboard-save-selftest.php for ONE run. Test only.
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

$port     = 8990 + random_int(0, 30);
$jar      = $child . $sep . 'cookies.txt';
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
$http = static function (string $method, string $path, array $post = [], array $headers = [], bool $useJar = true) use ($port, $jar): array {
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

// ── The world ───────────────────────────────────────────────────────────────
$siteId = (int) ($db->query('SELECT MIN(siteID) AS s FROM tblSites')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation');
}
$tag   = 'nb' . bin2hex(random_bytes(4));
$email = $tag . '@selftest.invalid';
$ins = $db->prepare(
    'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, isRootAdmin, createdAt) '
    . "VALUES ('Noticeboard Save Admin', ?, 1, 1, 1, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO tblUserSites (siteID, userID, isSiteAdmin, isSiteRootAdmin, isActive) VALUES ({$siteId}, {$userId}, 1, 1, 1)");
$password   = 'Nb' . bin2hex(random_bytes(8)) . '!9x';
$signInName = 'selftest-' . $tag;
$hash = password_hash($password, PASSWORD_DEFAULT);
$acc = $db->prepare('INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)');
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

$signIn = static function () use ($http, $tokenOn, $signInName, $password, $jar): void {
    @unlink($jar);
    $page  = $http('GET', '/login');
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $signInName, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse('signing the test administrator in did not redirect (status ' . $in['status'] . ')');
    }
};
/** A fresh form token: every accepted post replaces it (#768). */
$freshToken = static function (string $page = '/') use ($http, $tokenOn): string {
    $t = $tokenOn($http('GET', $page)['body']);
    if ($t === '') {
        refuse("no form token on {$page}");
    }
    return $t;
};


$signIn();
$posterTitles = static function () use ($db, $siteId): array {
    $rs = $db->query("SELECT title FROM tblNoticeboardPosters WHERE siteID = {$siteId} AND isDeleted = 0 ORDER BY sortOrder");
    $out = [];
    while ($row = $rs->fetch_assoc()) {
        $out[] = (string) $row['title'];
    }
    return $out;
};
$db->query("UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = {$siteId}");

/** One save as the host posts it: a JSON body, the token in the header. */
$saveJson = static function (string $token, array $posters) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . '/api/noticeboard/save');
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['posters' => $posters]),
    ]);
    $raw    = (string) curl_exec($h);
    $status = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    return ['status' => $status, 'json' => json_decode($raw, true)];
};

echo "A. Two saves in one visit, each carrying the token the last answer returned\n";
$t1 = $tokenOn($http('GET', '/noticeboard')['body']);
check('A0 the board page carries a form token (premise)', $t1 !== '', true);
$one = $saveJson($t1, [['id' => 'p1', 'title' => 'First poster ' . $tag]]);
$t2  = (string) ($one['json']['meta']['csrfToken'] ?? '');
check('A1 the first save is accepted', [$one['status'], $one['json']['status'] ?? null], [200, 'ok']);
check('A2 the answer carries a REPLACEMENT token (the host reads this)', $t2 !== '' && $t2 !== $t1, true);
$two = $saveJson($t2, [['id' => 'p1', 'title' => 'First poster ' . $tag], ['id' => 'p2', 'title' => 'Second poster ' . $tag]]);
$t3  = (string) ($two['json']['meta']['csrfToken'] ?? '');
check('A3 the second save, with the replacement, is accepted', [$two['status'], $two['json']['status'] ?? null], [200, 'ok']);
check('A4 the second answer carries another replacement', $t3 !== '' && $t3 !== $t2, true);
check('A5 BOTH posters are stored', $posterTitles(), ['First poster ' . $tag, 'Second poster ' . $tag]);
$three = $saveJson($t3, [['id' => 'p2', 'title' => 'Second poster ' . $tag]]);
check('A6 a third save (a delete) with the second replacement is accepted and stored',
    [$three['status'], $posterTitles()], [200, ['Second poster ' . $tag]]);

echo "B. The old host: the page-load token used again (proves A could fail)\n";
$signIn();
$db->query("UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = {$siteId}");
$loadToken = $tokenOn($http('GET', '/noticeboard')['body']);
$b1 = $saveJson($loadToken, [['id' => 'p1', 'title' => 'Old host first ' . $tag]]);
$b2 = $saveJson($loadToken, [['id' => 'p1', 'title' => 'Old host first ' . $tag], ['id' => 'p2', 'title' => 'Old host second ' . $tag]]);
check('B1 the first save with the page-load token works', $b1['status'], 200);
check('B2 the SECOND save with the same token is refused', $b2['status'] !== 200, true);
check('B3 and its poster was never stored', $posterTitles(), ['Old host first ' . $tag]);

echo "C. The served page's host script follows the rule (its text)\n";
$html = $http('GET', '/noticeboard')['body'];
check('C1 the host reads the live token at post time', str_contains($html, 'window.Portal.csrf()'), true);
check('C2 the host hands every answer to Portal.rememberCsrf', str_contains($html, 'window.Portal.rememberCsrf(answer)'), true);
check('C3 the host sends one save at a time (a queue)', str_contains($html, 'host.saveQueue = host.saveQueue.then('), true);
check('C4 the host says so when a save fails', str_contains($html, 'could not be saved'), true);
check('C5 the post uses the live token, not the load-time copy', str_contains($html, "'X-CSRF-Token': host.liveToken()"), true);

$db->query("UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = {$siteId}");
echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
