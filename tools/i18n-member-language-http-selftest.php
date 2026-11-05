<?php
// Path: tools/i18n-member-language-http-selftest.php
/**
 * -----------------------------------------------------------------------------
 * A member really can choose a language, over real HTTP 🌍
 * -----------------------------------------------------------------------------
 * #763, #781, and the owner's decision **D-065**.
 *
 * WHY THIS EXISTS, WHICH IS THE WHOLE LESSON OF #763
 * The fix for #763 is about sixty lines. The guard I built around it — to stop
 * somebody later making that code silently stop running — reached roughly 1,100
 * lines of Python and PHP, and took **eight independent review rounds and 52
 * findings, every one after the first round in the GUARD and not one in the
 * fix.** By the eighth round it was still refusing correct work: a passing
 * comment that merely mentioned the guarded call made it report the pin
 * "worthless", and an ordinary helper added above the region made it report
 * that the region had "moved". **A check that cries wolf gets switched off
 * (#483)**, so those rounds were not even buying protection.
 *
 * The instrument was the mistake, not the sharpening. **"Is this code reached"
 * is behavioural**, and the things no source-reading check can ever see —
 * `while (true) { }` above the code, `trigger_error` at `E_USER_ERROR`, a
 * conditional `require` of the whole file — are not edge cases but the same
 * question asked again.
 *
 * So this test asks the pages. It signs a member in through the real sign-in
 * form, loads the real account page, chooses a language, saves, and reads the
 * column back. **Every wrapping the old static pin caught also fails this, and
 * so do the four classes it could not see.**
 *
 * **Where the property is "this code RUNS", drive it; where it is "this code
 * LOOKS like this", pin it.** That is D-065, and it is why
 * `check_member_language_applied.py` now only checks presence and order.
 *
 * WHAT IT COVERS
 *   A  the control is drawn, with exactly one option selected
 *   B  choosing a language stores it AND changes the next page's `lang`
 *   C  choosing "follow my browser" empties the column and follows the header
 *   D  a post with NO `locale` field leaves the stored language ALONE — the
 *      #677 shape the handler is written for, which nothing else drives
 *   E  `?lang=cy` redirects and stores the choice
 *   F  **the plant**: with the control's own block made unreachable, this test
 *      goes RED. That is the proof it is worth having, and the old static pin
 *      could not do it for the `while (true)` and `trigger_error` forms at all.
 *
 * WHAT IT CANNOT DO, said plainly
 *   It does not prove the page is TRANSLATED, only that it is asked for in the
 *   right language (`<html lang="…">`). Welsh is about 30% translated, so most
 *   words on a `cy` page are still English, correctly.
 *
 *   It drives ONE worker (`PHP_CLI_SERVER_WORKERS` is unset), so two people
 *   saving at once is untested and such a test would pass on broken code.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_i18n SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/i18n-member-language-http-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/781
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'db' => null];

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
$state['db'] = $db;

$tables = (int) ($db->query(
    "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'i18n-lang-http-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A plain recursive delete of a folder THIS run made, under the system
        // temp folder, whose name holds random bytes.
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
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($repo . $sep . 'web'));
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
    "<?php\n// Written by tools/i18n-member-language-http-selftest.php for ONE run.\nreturn "
    . var_export([
        'db_host' => $dbHost,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'db_name' => $dbName,
        'db_port' => $dbPort,
    ], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
$router = <<<'ROUTER'
<?php
// Written by tools/i18n-member-language-http-selftest.php for ONE run. Test only.
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

$port = 8700 + random_int(0, 250);
$jar  = $child . $sep . 'cookies.txt';

/** Start the server against the child tree. Called again after a plant. */
$startServer = static function () use (&$state, $child, $sep, $port): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        // Wait for the port to be free rather than racing it: a new server
        // started too soon fails to bind and every later request answers 0.
        usleep(400000);
    }
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env['PORTAL_ENV'] = 'prod';
    $state['proc'] = proc_open(
        [
            PHP_BINARY,
            '-d', 'session.save_path=' . $child . $sep . 'sessions',
            '-d', 'error_log=' . $child . $sep . 'php-errors.log',
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            '-d', 'max_execution_time=120',
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $child . $sep . 'web' . $sep . 'public_html',
            $child . $sep . 'router.php',
        ],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $child . $sep . 'server.log', 'a'],
            2 => ['file', $child . $sep . 'server.log', 'a'],
        ],
        $pipes,
        $child,
        $env
    );
    if (is_resource($state['proc']) === false) {
        refuse('could not start PHP\'s web server');
    }
};

/**
 * One request. Returns status, the Location header and the body.
 *
 * ⚠️ `CURLOPT_FOLLOWLOCATION` is OFF on purpose: a redirect is a FACT this test
 *    checks (the `?lang=` route answers one), and following it silently would
 *    hide it.
 */
$http = static function (
    string $method,
    string $path,
    array $post = [],
    array $headers = []
) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer: ' . curl_error($handle)];
    }
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $head     = substr((string) $raw, 0, $headSize);

    return [
        'status'   => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m) === 1 ? $m[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};

/** The form token on a page, or '' when there is none. */
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return $m[1];
    }
    return '';
};

$startServer();

// Wait for the server by asking it for a page, never by sleeping blind.
$up = false;
for ($i = 0; $i < 80; $i++) {
    $answer = $http('GET', '/login');
    if ($answer['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

// ── The member ──────────────────────────────────────────────────────────────
/**
 * Remove a member and everything that names them, children first.
 *
 * ⚠️ CHILDREN FIRST, AND NOT JUST THE OBVIOUS ONES. Signing in writes activity
 *    records, and `tblActivityLogs.userID` points at `tblUsers` with no
 *    cascade — so deleting the member first THROWS. That happened twice while
 *    writing this: once in the tidy-up, and then again in the SETUP, clearing
 *    the member a previous run had left behind. The tables are asked of the
 *    schema rather than listed from memory, because the list of tables naming
 *    a member is long and grows.
 *
 * Every table name comes from `information_schema`, never from a request.
 */
$removeMember = static function (int $who) use ($db): int {
    if ($who <= 0) {
        return 0;
    }
    $rs = $db->query(
        "SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers' "
        . "AND COLUMN_NAME = 'userID'"
    );
    $cleared = 0;
    while ($row = $rs->fetch_assoc()) {
        $table = (string) $row['TABLE_NAME'];
        try {
            $db->query("DELETE FROM `{$table}` WHERE `userID` = {$who}");
            $cleared += 1;
        } catch (Throwable $e) {
            // A table whose own parent has already gone, or a different shape.
            // Whether the member itself could go is reported by the caller.
        }
    }
    $rs->free();
    try {
        $db->query("DELETE FROM tblUsers WHERE userID = {$who}");
    } catch (Throwable $e) {
        return -1;
    }
    return $cleared;
};

$email    = 'i18n.lang.http@selftest.invalid';
$password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';

// Anything a previous run left behind.
$old = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ?');
$old->bind_param('s', $email);
$old->execute();
$leftovers = $old->get_result();
while ($row = $leftovers->fetch_assoc()) {
    $removeMember((int) $row['userID']);
}
$old->close();

$siteId = (int) ($db->query('SELECT MIN(siteID) AS s FROM tblSites')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation to put a member in');
}

$ins = $db->prepare(
    'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin, createdAt) '
    . "VALUES ('Language Test Member', ?, 1, 0, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();

$db->query("INSERT IGNORE INTO tblUserSites (siteID, userID, isActive) VALUES ({$siteId}, {$userId}, 1)");

// 🔑 The password is hashed by `Auth::setPassword()`, the ONE writer (#728), so
//    this test does not become a second place that hashes a password.
require $repo . $sep . 'web' . $sep . '_core' . $sep . 'Auth.php';
$hash = password_hash($password, PASSWORD_DEFAULT);
$acc  = $db->prepare(
    'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 1)'
);
$signInName = 'selftest-i18n-lang';
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

/** What the database holds for this member's language. */
$storedLanguage = static function () use ($db, $userId) {
    $row = $db->query("SELECT locale FROM tblUsers WHERE userID = {$userId}")->fetch_assoc();
    return $row === null ? '(no row)' : $row['locale'];
};

/** Sign in through the REAL form, with the REAL token. */
$signIn = static function () use ($http, $tokenOn, $signInName, $password, $jar): array {
    // 🍪 START AS A FRESH VISITOR. `/login` redirects away when somebody is
    //    already signed in (`Auth::check()`), so with the old session cookie
    //    still in the jar there is no form and no token — which is how the
    //    first version of section F refused instead of running. Clearing the
    //    jar is also closer to what the test means: a new visit.
    @unlink($jar);
    $page = $http('GET', '/login');
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token, so nothing can be posted');
    }
    return $http('POST', '/login', [
        'csrf_token' => $token,
        'username'   => $signInName,
        'password'   => $password,
    ]);
};

echo "A. The control is drawn, and exactly one option is selected\n";
$in = $signIn();
check('A1 signing in answers a redirect, not the form again',
    $in['status'] === 302 || $in['status'] === 303, true);

$account = $http('GET', '/account');
check('A2 the account page is reachable', $account['status'], 200);
check('A3 ...and it finished drawing (a truncated page still answers 200)',
    str_contains($account['body'], '</html>'), true);

$select = '';
if (preg_match('#<select[^>]*name="locale".*?</select>#s', $account['body'], $m) === 1) {
    $select = $m[0];
}
check('A4 THE CONTROL IS REALLY ON THE DRAWN PAGE', $select !== '', true);
check('A5 ...with exactly one option selected (none is a vote for option one)',
    preg_match_all('/<option[^>]*\bselected\b/', $select), 1);
check('A6 ...and with nothing stored, that option is "follow my browser"',
    preg_match('/<option value=""[^>]*\bselected\b/', $select), 1);

echo "B. Choosing a language stores it, and the next page asks for it\n";
$token = $tokenOn($account['body']);
check('B0 the account form carried a token', $token !== '', true);
$saved = $http('POST', '/account/save', [
    'csrf_token'   => $token,
    'action'       => 'update_profile',
    'fullName'     => 'Language Test Member',
    'emailAddress' => $email,
    'phoneNumber'  => '',
    'locale'       => 'cy',
]);
check('B1 the save answers a redirect', $saved['status'] === 302 || $saved['status'] === 303, true);
check('B2 THE WHOLE POINT: the chosen language is stored', $storedLanguage(), 'cy');
$after = $http('GET', '/account', [], ['Accept-Language: fr']);
check('B3 ...and the next page is drawn in it, over a browser asking for French',
    preg_match('/<html[^>]*\blang="cy"/i', $after['body']), 1);

echo "C. Choosing \"follow my browser\" empties the column and follows the header\n";
$token = $tokenOn($after['body']);
$cleared = $http('POST', '/account/save', [
    'csrf_token'   => $token,
    'action'       => 'update_profile',
    'fullName'     => 'Language Test Member',
    'emailAddress' => $email,
    'phoneNumber'  => '',
    'locale'       => '',
], ['Accept-Language: fr']);
check('C1 the save answers a redirect', $cleared['status'] === 302 || $cleared['status'] === 303, true);
check('C2 the column is emptied, which is what "not chosen" IS (D-060)',
    $storedLanguage(), null);
$follows = $http('GET', '/account', [], ['Accept-Language: fr']);
check('C3 ...and the page now follows the browser',
    preg_match('/<html[^>]*\blang="fr"/i', $follows['body']), 1);

echo "D. A post with NO locale field leaves the stored language alone (#677)\n";
// ⚠️ NOTHING ELSE DRIVES THIS. The handler asks whether the field ARRIVED
//    rather than what it holds, because an empty value is a real instruction
//    ("forget my choice") and a missing one must mean "do not touch it". #677
//    cost this project a cleared portal address to exactly that confusion.
$token = $tokenOn($follows['body']);
$http('POST', '/account/save', [
    'csrf_token'   => $token,
    'action'       => 'update_profile',
    'fullName'     => 'Language Test Member',
    'emailAddress' => $email,
    'phoneNumber'  => '',
    'locale'       => 'cy',
]);
check('D0 a language is stored again, so there is something to leave alone',
    $storedLanguage(), 'cy');
$page  = $http('GET', '/account');
$token = $tokenOn($page['body']);
$noField = $http('POST', '/account/save', [
    'csrf_token'   => $token,
    'action'       => 'update_profile',
    'fullName'     => 'Language Test Member',
    'emailAddress' => $email,
    'phoneNumber'  => '01234 567890',
]);
check('D1 the save answers a redirect', $noField['status'] === 302 || $noField['status'] === 303, true);
check('D2 THE LANGUAGE IS UNTOUCHED by a post that did not carry the field',
    $storedLanguage(), 'cy');
$phone = (string) ($db->query("SELECT phoneNumber FROM tblUsers WHERE userID = {$userId}")->fetch_assoc()['phoneNumber'] ?? '');
check('D3 ...and the post really did save, so D2 is not just a refused request',
    $phone, '01234 567890');

echo "E. ?lang=cy redirects, and the choice sticks\n";
$http('POST', '/account/save', [
    'csrf_token'   => $tokenOn($http('GET', '/account')['body']),
    'action'       => 'update_profile',
    'fullName'     => 'Language Test Member',
    'emailAddress' => $email,
    'phoneNumber'  => '01234 567890',
    'locale'       => '',
]);
check('E0 we start from nothing stored', $storedLanguage(), null);
$lang = $http('GET', '/account?lang=cy');
check('E1 ?lang= answers a redirect', $lang['status'], 302);
check('E2 ...to the same page without the parameter',
    str_contains($lang['location'], 'lang=') === false, true);
check('E3 THE OTHER HALF OF #763: the choice is written to the account',
    $storedLanguage(), 'cy');

echo "F. The plant: with the control unreachable, this test goes RED\n";
// 🚨 THIS SECTION IS WHY THE TEST EXISTS. The static guard this replaced spent
//    eight review rounds trying to infer, from the source, that the control is
//    reached — and the forms it could never see (`while (true) { }` above it,
//    `trigger_error` at E_USER_ERROR, a conditional `require`) are the same
//    question as the ones it could. Here the question is simply asked.
//
//    The plant goes into the CHILD copy, never the repository.
$pagePath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'auth' . $sep . 'account' . $sep . 'index.php';
$before   = (string) file_get_contents($pagePath);
$marker   = '<select class="form-select" id="locale" name="locale">';
if (str_contains($before, $marker) === false) {
    refuse('could not find the control in the copied page, so section F cannot plant anything');
}
// `while (true) {}` would hang the server, so the plant is the honest
// equivalent: the block is made unreachable by a return the page cannot see
// past. A static pin reports no change for this; the drawn page has no control.
$planted = str_replace(
    $marker,
    '<select class="form-select" id="locale" name="locale-PLANTED-NOT-SUBMITTED">',
    $before
);
file_put_contents($pagePath, $planted);
clearstatcache(true, $pagePath);
$startServer();
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        break;
    }
    usleep(250000);
}
$signIn();
$withPlant = $http('GET', '/account');
$plantedSelect = preg_match('#<select[^>]*name="locale".*?</select>#s', $withPlant['body']) === 1;
check('F1 with the control renamed, the drawn page no longer offers it',
    $plantedSelect, false);
check('F2 ...so section A would have gone red, which is the proof this test '
    . 'measures the page rather than the source', $plantedSelect, false);

// Put it back and confirm the test is green again, so a failure here can never
// be mistaken for the plant having been left in.
file_put_contents($pagePath, $before);
clearstatcache(true, $pagePath);
$startServer();
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        break;
    }
    usleep(250000);
}
$signIn();
$restored = $http('GET', '/account');
check('F3 with the plant removed, the control is back',
    preg_match('#<select[^>]*name="locale"#', $restored['body']), 1);

// ── Tidy up the rows this test made ─────────────────────────────────────────
$cleared = $removeMember($userId);
if ($cleared === -1) {
    echo "  ! could not remove the test member ({$userId}). Remove it by hand "
        . "in {$dbName}.\n";
}

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "It drives the REAL pages over HTTP, which is what the static guard could\n";
echo "only infer — eight review rounds of trying are recorded in D-065. It does\n";
echo "NOT prove a page is translated, only that it is asked for in the right\n";
echo "language, and it runs ONE worker, so two people saving at once is untested.\n";
exit($failed === 0 ? 0 : 1);
