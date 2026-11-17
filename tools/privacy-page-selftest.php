<?php
// Path: tools/privacy-page-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The Privacy page does not show its set-up notice to the public (#1138 item 10) 🔒🧪
 * -----------------------------------------------------------------------------
 * REAL MySQL and PHP's built-in web server, driving the real /privacy page.
 *
 * WHAT IT PROVES, with the two settings that name the data controller EMPTY:
 *  A. A visitor (not signed in) and an ordinary member see no "Admin notice" and
 *     no "not configured". They are told the data controller is "the
 *     organisation that runs this portal".
 *  B. An administrator DOES see the notice, and it says only administrators see it.
 *  C. With a controller name set, everybody sees that name and nobody sees the notice.
 *  D. PLANT: the administrator-only condition removed in a COPY of the page turns
 *     check A red, and the copy is put back byte for byte.
 *
 * WHAT IT CANNOT PROVE: that the sentence is TRUE or well worded; a person reads
 * the page as rendered at review.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=… [SELFTEST_DB_PASS=…] \
 *         [SELFTEST_WEB_PORT=9946] php tools/privacy-page-selftest.php
 *         The database must already hold web/_sql/full_schema.sql.
 * Exit:   0 only when every check passed; 1 on a failure; 2 refused.
 *
 * It changes, and puts back: made-up people numbered 9461xx (addresses ending
 * @pp1.invalid) and the two privacy settings' rows. A shutdown function does that
 * even after an early stop.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

const PP_ADMIN  = 946101;
const PP_MEMBER = 946102;

$sep    = DIRECTORY_SEPARATOR;
$repo   = dirname(__DIR__);
$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'saved' => null];

function pp_refuse(string $why): never
{
    echo "REFUSED: {$why}.\nA skipped test reads exactly like a passing one, so this refuses.\n";
    exit(2);
}

function pp_check(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    if ($ok === true) {
        $passed++;
        echo "  ok   {$name}\n";

        return true;
    }
    $failed++;
    echo "  FAIL {$name}\n";
    if ($detail !== '') {
        echo '       ' . substr($detail, 0, 400) . "\n";
    }

    return false;
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
    pp_refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}')");
}
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        pp_refuse("PHP's {$needed}() is missing");
    }
}
$dbHost = getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1';
$dbUser = getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root';
$dbPass = getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '';
$dbPort = getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$db->set_charset('utf8mb4');
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch_row()[0] < 100) {
    pp_refuse('load web/_sql/full_schema.sql first');
}

// 🏗️ A copy of web/, so a planted fault never touches the repository.
$child = sys_get_temp_dir() . $sep . 'privacy-page-' . bin2hex(random_bytes(6));
$state['child'] = $child;

/** Put the two privacy settings back as they were, remove the made-up people. */
$cleanUp = static function () use ($db, &$state): void {
    // Signing in wrote activity lines that name these people; they go first.
    $db->query('DELETE FROM `tblActivityLogs` WHERE `userID` IN (' . PP_ADMIN . ', ' . PP_MEMBER . ')');
    $db->query('DELETE FROM `tblUserSites` WHERE `userID` IN (' . PP_ADMIN . ', ' . PP_MEMBER . ')');
    $db->query('DELETE FROM `tblLocalAccounts` WHERE `userID` IN (' . PP_ADMIN . ', ' . PP_MEMBER . ')');
    $db->query('DELETE FROM `tblUsers` WHERE `userID` IN (' . PP_ADMIN . ', ' . PP_MEMBER . ')');
    if ($state['saved'] !== null) {
        $db->query("DELETE FROM `tblSettings` WHERE `settingKey` IN ('privacy.controllerName', 'privacy.contactEmail', 'privacy.policyURL', 'privacy.erasureContact')");
        foreach ($state['saved'] as $row) {
            $stmt = $db->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('isssi', $row['siteID'], $row['settingKey'], $row['settingValue'], $row['defaultValue'], $row['isSensitive']);
            $stmt->execute();
            $stmt->close();
        }
        $state['saved'] = null;
    }
};
register_shutdown_function(static function () use (&$state, $cleanUp): void {
    $cleanUp();
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_file($state['child'] . DIRECTORY_SEPARATOR . 'privacy-page.marker') === true) {
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
file_put_contents($child . $sep . 'privacy-page.marker', 'made by tools/privacy-page-selftest.php');
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if (str_starts_with($relative, $sep . '_auth_keys') === true) {
        continue;
    }
    $to = $child . $sep . 'web' . $relative;
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    pp_refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\nreturn " . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/privacy-page-selftest.php for ONE run. Test only.
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
ROUTER);

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9946;
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    pp_refuse("something already answers on port {$port}");
}
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
        '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
        '-S', '127.0.0.1:' . $port, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes,
    $child,
    $env
);
if (is_resource($state['proc']) === false) {
    pp_refuse('could not start PHP\'s web server');
}

/** One request with its own cookie jar: status, Location and body. Redirects are NOT followed. */
$http = static function (string $jar, string $method, string $path, array $post = []) use ($port): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => substr((string) $raw, $headSize)];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $mm) === 1
        || preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }

    return '';
};
$jars = ['visitor' => $child . $sep . 'visitor.txt', 'member' => $child . $sep . 'member.txt', 'admin' => $child . $sep . 'admin.txt'];
$answered = false;
for ($i = 0; $i < 80; $i++) {
    if ($http($jars['visitor'], 'GET', '/login')['status'] > 0) {
        $answered = true;
        break;
    }
    usleep(250000);
}
if ($answered === false) {
    pp_refuse('the test server never answered');
}

// 👥 One administrator (the portal-wide flag, which App::isAdmin() always honours) and one ordinary member.
$password = 'Pp' . bin2hex(random_bytes(8)) . '!9x';
$siteRow  = $db->query('SELECT `siteID` FROM `tblSites` ORDER BY `siteID` LIMIT 1')->fetch_row();
$site     = (int) ($siteRow[0] ?? 0);
if ($site < 1) {
    pp_refuse('the database holds no organisation');
}
foreach ([PP_ADMIN => ['admin', 1], PP_MEMBER => ['member', 0]] as $id => [$who, $isAdmin]) {
    $stmt = $db->prepare('INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, ?, ?, NOW())');
    $name = 'Selftest ' . $who;
    $mail = $who . '@pp1.invalid';
    $stmt->bind_param('issii', $id, $name, $mail, $isAdmin, $isAdmin);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) VALUES (?, ?, 1, ?, ?)');
    $stmt->bind_param('iiii', $site, $id, $isAdmin, $isAdmin);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $user = 'pp1-' . $who;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt->bind_param('iss', $id, $user, $hash);
    $stmt->execute();
    $stmt->close();
}
$signIn = static function (string $who) use ($http, $tokenOn, $jars, $password): void {
    $token = $tokenOn($http($jars[$who], 'GET', '/login')['body']);
    $in = $http($jars[$who], 'POST', '/login', ['csrf_token' => $token, 'username' => 'pp1-' . $who, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        pp_refuse("signing in as {$who} did not answer a redirect (HTTP {$in['status']})");
    }
};
$signIn('admin');
$signIn('member');

// ⚙️ Remember the privacy settings, then set the three as the tests need them.
$state['saved'] = $db->query("SELECT `siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive` FROM `tblSettings` WHERE `settingKey` IN ('privacy.controllerName', 'privacy.contactEmail', 'privacy.policyURL', 'privacy.erasureContact')")->fetch_all(MYSQLI_ASSOC);
$setSettings = static function (string $controller, string $email) use ($db): void {
    $db->query("DELETE FROM `tblSettings` WHERE `settingKey` IN ('privacy.controllerName', 'privacy.contactEmail', 'privacy.policyURL', 'privacy.erasureContact')");
    foreach (['privacy.controllerName' => $controller, 'privacy.contactEmail' => $email, 'privacy.policyURL' => '', 'privacy.erasureContact' => $email] as $key => $value) {
        $stmt = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, '', 0)");
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
};

/** What /privacy says to each kind of reader, as plain text. */
$read = static function () use ($http, $jars): array {
    $out = [];
    foreach ($jars as $who => $jar) {
        $page = $http($jar, 'GET', '/privacy');
        $out[$who] = ['status' => $page['status'], 'html' => $page['body'], 'text' => preg_replace('/\s+/', ' ', strip_tags($page['body'])) ?? ''];
    }

    return $out;
};
$noNotice = static fn (array $r): bool => $r['status'] === 200
    && str_contains($r['text'], 'Admin notice') === false && str_contains($r['text'], 'not configured') === false
    && str_contains($r['text'], 'The data controller is the organisation that runs this portal') === true;

echo "\nA and B. Both settings empty\n";
$setSettings('', '');
$r = $read();
pp_check('A1 a visitor sees no "Admin notice" and no "not configured", and is told the organisation that runs the portal', $noNotice($r['visitor']), $r['visitor']['status'] . ' ' . substr($r['visitor']['text'], 0, 200));
pp_check('A2 an ordinary member sees the same', $noNotice($r['member']), $r['member']['status'] . ' ' . substr($r['member']['text'], 0, 200));
pp_check('B1 an administrator DOES see the notice, and it says only administrators see it',
    $r['admin']['status'] === 200 && str_contains($r['admin']['text'], 'Admin notice') === true && str_contains($r['admin']['text'], 'only administrators see this') === true,
    $r['admin']['status'] . ' ' . substr($r['admin']['text'], 0, 200));

echo "\nC. A controller name is set\n";
$setSettings('Selftest Church', 'privacy@pp1.invalid');
$r = $read();
$allNamed = true;
foreach ($r as $who => $one) {
    $allNamed = $allNamed && $one['status'] === 200 && str_contains($one['text'], 'The data controller is Selftest Church') === true
        && str_contains($one['text'], 'Admin notice') === false;
}
pp_check('C1 everybody, the administrator included, sees the name and nobody sees the notice', $allNamed);

echo "\nC2. The policy page's contact line, with no erasure contact set\n";
$setSettings('', '');
$r = [];
foreach (['visitor', 'member', 'admin'] as $who) {
    $page = $http($jars[$who], 'GET', '/privacy/policy');
    $r[$who] = ['status' => $page['status'], 'text' => preg_replace('/\s+/', ' ', strip_tags($page['body'])) ?? ''];
}
$generic = static fn (array $one): bool => $one['status'] === 200 && str_contains($one['text'], 'not configured') === false
    && str_contains($one['text'], 'Only administrators see this') === false
    && str_contains($one['text'], 'please ask the organisation that runs this portal') === true;
pp_check('C2a a member reads the generic line, with no "not configured" and no administrator note', $generic($r['member']), $r['member']['status'] . ' ' . substr($r['member']['text'], -300));
pp_check('C2b a visitor (if the page opens to one) reads the generic line; otherwise it is a clean refusal',
    $r['visitor']['status'] === 200 ? $generic($r['visitor']) : in_array($r['visitor']['status'], [302, 303, 401, 403], true) === true, (string) $r['visitor']['status']);
pp_check('C2c an administrator is told it is unset and where to set it',
    $r['admin']['status'] === 200 && str_contains($r['admin']['text'], 'Only administrators see this: no erasure contact is set') === true, $r['admin']['status'] . ' ' . substr($r['admin']['text'], -300));

echo "\nD. Plant: the administrator-only condition removed in a copy of the page\n";
$setSettings('', '');
$path = $child . $sep . 'web' . $sep . '_apps' . $sep . 'privacy' . $sep . 'index.php';
$orig = (string) file_get_contents($path);
$from = " && App::isAdmin() === true): ?>";
if (substr_count($orig, $from) !== 1) {
    pp_check('D0 the line to plant is present exactly once', false, 'found ' . substr_count($orig, $from));
} else {
    file_put_contents($path, str_replace($from, '): ?>', $orig));
    try {
        $r = $read();
        $red = $noNotice($r['visitor']) === false && $noNotice($r['member']) === false;
    } finally {
        file_put_contents($path, $orig);
    }
    pp_check('D1 with the condition gone the visitor and member checks go red, and the copy is put back byte for byte',
        $red === true && (string) file_get_contents($path) === $orig);
}

$ppath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'privacy' . $sep . 'policy.php';
$porig = (string) file_get_contents($ppath);
$pfrom = '<?php elseif (App::isAdmin() === true): ?>';
if (substr_count($porig, $pfrom) !== 1) {
    pp_check('D2 the policy line to plant is present exactly once', false, 'found ' . substr_count($porig, $pfrom));
} else {
    file_put_contents($ppath, str_replace($pfrom, '<?php elseif (true): ?>', $porig));
    try {
        $red = str_contains($http($jars['member'], 'GET', '/privacy/policy')['body'], 'Only administrators see this') === true;
    } finally {
        file_put_contents($ppath, $porig);
    }
    pp_check('D2 with the policy page\'s administrator condition gone a member sees the administrator note (so C2a goes red), and the copy is put back byte for byte',
        $red === true && (string) file_get_contents($ppath) === $porig);
}

$errors = trim((string) @file_get_contents($child . $sep . 'php-errors.log'));
pp_check('PHP\'s own error log is empty after all of that', $errors === '', substr($errors, 0, 400));

echo "\n" . ($failed === 0 ? 'PASS' : 'FAIL') . " — {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
