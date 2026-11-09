<?php
// Path: tools/notify-prefs-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Two pages save a member's notification choices, and neither may lose the
 * other's — self-test 🔔 (#667)
 * -----------------------------------------------------------------------------
 * A member's notification choices live in ONE column, `tblUsers.notifyPrefs`,
 * and two pages save them: the full Notification preferences page
 * (`/account/notifications`) and the small card on `/account`. Until #667 the
 * full page REBUILT the whole column from its own list and wrote it over the
 * top, dropping every key it did not list, and the card's read-then-write had
 * no lock. Both now go through `Portal\Core\NotifyPrefs::merge()`.
 *
 * WHAT IT COVERS — every section drives the REAL pages over HTTP and reads the
 * column back, except F, which is about two saves at once (see below):
 *   A  each page draws a hidden "this form drew me" marker beside EVERY
 *      switch, and beside nothing else
 *   B  saving on the full page keeps every key it does not draw — the card's
 *      retired keys, an unknown key, and the push choices it does not show —
 *      and an unticked switch it DOES draw is stored as off
 *   C  saving on the card keeps every key the full page owns, an unticked box
 *      reads off, and the retired `emailDigest` / `expenseUpdates` are no
 *      longer written
 *   D  a field that did not ARRIVE changes nothing (#677): a post with no
 *      markers, a marker for a key nobody may set, markers naming ONLY such
 *      keys (which must not unsubscribe the member from the newsletter
 *      either), and the Sabbath choice when its buttons were not drawn; and a
 *      tick counts only when it is exactly '1'
 *   E  a stored value that is valid JSON but not a set of named choices is
 *      replaced by the keys just saved, and the replacement is RECORDED — on
 *      BOTH pages
 *   F  two saves at once lose nothing: the real `merge()` runs in its own
 *      PROCESS while this test holds the row, the way
 *      `erasure-review-selftest.php` section T does it; and F3 MEASURES that
 *      its read and write are one transaction, which no before-and-after
 *      comparison of the keys can see (a scratch trigger records when the
 *      writing connection's transaction began)
 *   G  when the database refuses the save (a scratch trigger refuses it for
 *      real), both pages say so and nothing changes
 *   H  THE PLANTS: ten faults, each put into a COPY of the code, each of which
 *      must turn the check that guards it RED, and all green again after
 *
 * WHY SECTION F IS NOT AN HTTP TEST. The page harness runs PHP's built-in
 * server with ONE worker, so two requests never overlap and a race test built
 * on it would pass on the BROKEN code (#647). So F drives the class the pages
 * call. Section A/B/C prove the pages call it; F proves the lock in it holds.
 *
 * WHAT IT CANNOT DO, said plainly:
 *   - Invalid TEXT in the column cannot be stored here to test: MySQL's JSON
 *     column refuses it and MariaDB adds a JSON_VALID check. `merge()` still
 *     handles it; only the valid-but-not-an-object case is driven (E).
 *   - It does not drive the push switches as SHOWN, because push is not set up
 *     on a fresh install; `go-live-button-selftest.php` covers that page with
 *     push set up.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_notifyprefs SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/notify-prefs-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * Exit:   0 only when every check ran and passed.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/667
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
$state  = ['proc' => null, 'child' => null, 'worker' => null];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

/** One check. `$detail` is printed only when it fails. */
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo '      ' . $detail . "\n";
    }
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
$child = sys_get_temp_dir() . $sep . 'notify-prefs-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state): void {
    foreach (['worker', 'proc'] as $which) {
        if (is_resource($state[$which]) === true) {
            proc_terminate($state[$which]);
            proc_close($state[$which]);
        }
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
    "<?php\n// Written by tools/notify-prefs-selftest.php for ONE run.\nreturn "
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
// Written by tools/notify-prefs-selftest.php for ONE run. Test only.
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

// The worker for section F: the REAL class, from the COPY (so a plant in the
// copy reaches it), on its own connection, in its own process.
$worker = <<<'WORKER'
<?php
// Written by tools/notify-prefs-selftest.php for ONE run. Test only.
declare(strict_types=1);
require $argv[1];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli((string) getenv('NP_HOST'), (string) getenv('NP_USER'), (string) getenv('NP_PASS'), (string) getenv('NP_NAME'), (int) getenv('NP_PORT'));
$answer = \Portal\Core\NotifyPrefs::merge($db, (int) $argv[2], ['taskReminders' => false]);
echo json_encode($answer);
WORKER;
file_put_contents($child . $sep . 'worker.php', $worker);

// A range no other self-test uses (8700-8950 and 8960-9210 are taken, and the
// fixed SELFTEST_WEB_PORT values run 9068-9893), so this can run beside them.
$port = 10300 + random_int(0, 200);
$jar  = $child . $sep . 'cookies.txt';

// ⚠️ ONE server for the whole run. opcache is OFF, so a file the plants change
//    in the copy is read afresh on the next request — no restart is needed,
//    and a restart is a race with the port.
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

/**
 * One request. `CURLOPT_FOLLOWLOCATION` is OFF on purpose: where a save sends
 * the member is a fact this test reads.
 *
 * @param array<string,mixed> $post
 * @return array{status:int, location:string, body:string}
 */
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
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
        CURLOPT_HTTPHEADER     => ['Expect:'],
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

// Wait for the server by asking it for a page, never by sleeping blind.
$up = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $up = true;
        break;
    }
    usleep(250000);
}
if ($up === false) {
    refuse('the test server never answered');
}

/**
 * The fields a browser would post from ONE form on a page: every hidden
 * field, and every checkbox or radio button that is ticked. `$pick` chooses
 * the form by a piece of its opening tag or body.
 *
 * Field names are kept WHOLE (`prefsShown[eventReminders]`), and
 * http_build_query() turns them back into PHP arrays on the other side.
 *
 * @return array{found:bool, action:string, fields:array<string,string>, switches:list<string>, markers:list<string>}
 */
$formOn = static function (string $html, string $pick): array {
    $none = ['found' => false, 'action' => '', 'fields' => [], 'switches' => [], 'markers' => []];
    if (preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $html, $forms, PREG_SET_ORDER) < 1) {
        return $none;
    }
    foreach ($forms as $form) {
        if (str_contains($form[0], $pick) === false) {
            continue;
        }
        $action = preg_match('/\baction="([^"]*)"/', $form[1], $a) === 1 ? html_entity_decode($a[1], ENT_QUOTES, 'UTF-8') : '';
        $fields = [];
        $switches = [];
        $markers  = [];
        preg_match_all('/<input\b[^>]*>/i', $form[2], $inputs);
        foreach ($inputs[0] as $input) {
            if (preg_match('/\bname="([^"]+)"/', $input, $n) !== 1) {
                continue;
            }
            $name  = html_entity_decode($n[1], ENT_QUOTES, 'UTF-8');
            $value = preg_match('/\bvalue="([^"]*)"/', $input, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8') : '';
            $type  = preg_match('/\btype="([^"]+)"/', $input, $t) === 1 ? strtolower($t[1]) : 'text';
            if (preg_match('/^prefs\[([A-Za-z]+)\]$/', $name, $k) === 1 && $type === 'checkbox') {
                $switches[] = $k[1];
            }
            if (preg_match('/^prefsShown\[([A-Za-z]+)\]$/', $name, $k) === 1 && $type === 'hidden') {
                $markers[] = $k[1];
            }
            if (($type === 'checkbox' || $type === 'radio') && preg_match('/\bchecked\b/', $input) !== 1) {
                continue;
            }
            $fields[$name] = $value;
        }
        sort($switches);
        sort($markers);

        return ['found' => true, 'action' => $action, 'fields' => $fields, 'switches' => $switches, 'markers' => $markers];
    }

    return $none;
};

// ── The member ──────────────────────────────────────────────────────────────
/**
 * Remove a member and everything that names them, children first. The tables
 * are asked of the schema rather than listed from memory: signing in writes
 * activity records, and `tblActivityLogs.userID` has no cascade.
 */
$removeMember = static function (int $who) use ($db): int {
    if ($who <= 0) {
        return 0;
    }
    $rs = $db->query(
        'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '
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
        }
    }
    $rs->free();
    try {
        $db->query("DELETE FROM `tblErrors` WHERE `userID` = {$who}");
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        return -1;
    }
    return $cleared;
};

$email      = 'notify.prefs@selftest.invalid';
$signInName = 'selftest-notify-prefs';
$password   = 'Sel' . bin2hex(random_bytes(8)) . '!9x';

$old = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
$old->bind_param('s', $email);
$old->execute();
$leftovers = $old->get_result();
while ($row = $leftovers->fetch_assoc()) {
    $removeMember((int) $row['userID']);
}
$old->close();

$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation to put a member in');
}

$ins = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `createdAt`) '
    . "VALUES ('Notify Prefs Member', ?, 1, 0, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES ({$siteId}, {$userId}, 1)");

$hash = password_hash($password, PASSWORD_DEFAULT);
$acc  = $db->prepare(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)'
);
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

/** Put a raw value in the column (null for SQL NULL). */
$setStored = static function (?string $raw) use ($db, $userId): void {
    $st = $db->prepare('UPDATE `tblUsers` SET `notifyPrefs` = ? WHERE `userID` = ?');
    $st->bind_param('si', $raw, $userId);
    $st->execute();
    $st->close();
};

/** The column, decoded as an ARRAY with its keys sorted, or null for NULL. */
$stored = static function () use ($db, $userId): ?array {
    $row = $db->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId}")->fetch_assoc();
    if ($row === null || $row['notifyPrefs'] === null) {
        return null;
    }
    $decoded = json_decode((string) $row['notifyPrefs'], true);
    if (is_array($decoded) === false) {
        return ['(not an object)' => (string) $row['notifyPrefs']];
    }
    ksort($decoded);
    return $decoded;
};

/** The same column decoded as an OBJECT, so a nested `{}` can be told from `[]`. */
$storedObject = static function () use ($db, $userId): mixed {
    $row = $db->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId}")->fetch_assoc();
    return json_decode((string) ($row['notifyPrefs'] ?? 'null'), false);
};

/** Sign in through the REAL form, with the REAL token. */
$signIn = static function () use ($http, $signInName, $password, $jar): void {
    @unlink($jar);
    $page = $http('GET', '/login');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m) !== 1) {
        refuse('the sign-in page carried no form token, so nothing can be posted');
    }
    $in = $http('POST', '/login', ['csrf_token' => $m[1], 'username' => $signInName, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse('signing in did not answer a redirect (HTTP ' . $in['status'] . ')');
    }
};
$signIn();

/**
 * Open the full page, untick `$untick`, tick everything else it draws, and
 * press Save. Returns the save's answer.
 *
 * @param list<string> $untick
 * @return array{status:int, location:string, body:string}
 */
$saveFullPage = static function (array $untick) use ($http, $formOn): array {
    $page = $http('GET', '/account/notifications');
    $form = $formOn($page['body'], 'account/notifications/save');
    if ($form['found'] === false) {
        return ['status' => -1, 'location' => '', 'body' => 'the form was not on the page'];
    }
    $fields = $form['fields'];
    foreach ($form['switches'] as $key) {
        unset($fields['prefs[' . $key . ']']);
        if (in_array($key, $untick, true) === false) {
            $fields['prefs[' . $key . ']'] = '1';
        }
    }
    return $http('POST', $form['action'], $fields);
};

/**
 * Open /account, set the card's one switch, and press Save.
 *
 * @return array{status:int, location:string, body:string}
 */
$saveCard = static function (bool $eventReminders, array $extra = []) use ($http, $formOn): array {
    $page = $http('GET', '/account');
    $form = $formOn($page['body'], 'value="update_notifications"');
    if ($form['found'] === false) {
        return ['status' => -1, 'location' => '', 'body' => 'the card was not on the page'];
    }
    $fields = $form['fields'];
    unset($fields['prefs[eventReminders]']);
    if ($eventReminders === true) {
        $fields['prefs[eventReminders]'] = '1';
    }
    return $http('POST', $form['action'], $fields + $extra);
};

$fmt = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES);

// =============================================================================
echo "\nA. Each page marks every switch it draws, and nothing else\n";
// =============================================================================
$full = $http('GET', '/account/notifications');
check('A1 the Notification preferences page answers, and finished drawing (a truncated page still answers 200)',
    $full['status'] === 200 && str_contains($full['body'], '</html>') === true, 'HTTP ' . $full['status']);
$fullForm = $formOn($full['body'], 'account/notifications/save');
check('A2 its form has switches, and EXACTLY the same set of markers — a switch without one would never be saved, a marker without one would switch a choice off',
    $fullForm['found'] === true && $fullForm['switches'] !== [] && $fullForm['switches'] === $fullForm['markers'],
    'switches ' . $fmt($fullForm['switches']) . ', markers ' . $fmt($fullForm['markers']));
check('A3 push is not set up here, so neither a push switch nor a push marker is drawn (B relies on that)',
    array_intersect(['pushLivestream', 'pushServiceReminders', 'pushApprovals'], $fullForm['markers']) === []
    && str_contains($full['body'], 'name="prefs[pushLivestream]"') === false,
    'markers ' . $fmt($fullForm['markers']));
$account = $http('GET', '/account');
$card    = $formOn($account['body'], 'value="update_notifications"');
check('A4 the Account page answers and finished drawing', $account['status'] === 200 && str_contains($account['body'], '</html>') === true,
    'HTTP ' . $account['status']);
check('A5 its card draws ONE switch, "Event reminders", with its marker, and no marker for the retired emailDigest / expenseUpdates',
    $card['found'] === true && $card['switches'] === ['eventReminders'] && $card['markers'] === ['eventReminders'],
    'switches ' . $fmt($card['switches']) . ', markers ' . $fmt($card['markers']));

// =============================================================================
echo "\nB. Saving on the full page keeps every key it does not draw\n";
// =============================================================================
$beforeB = '{"emailDigest": false, "expenseUpdates": false, "pushLivestream": false, "pushApprovals": true,'
    . ' "zzFutureChoice": {"nested": {}, "count": 3, "words": "kept as stored"}, "taskReminders": true, "rotaReminders": false}';
/** @return array{0:bool, 1:string} */
$scenarioB = static function () use ($setStored, $stored, $storedObject, $saveFullPage, $beforeB, $fmt): array {
    $setStored($beforeB);
    $answer = $saveFullPage(['taskReminders']);
    $now    = $stored() ?? [];
    $obj    = $storedObject();
    $keptOthers = ($now['emailDigest'] ?? 'gone') === false && ($now['expenseUpdates'] ?? 'gone') === false
        && ($now['pushLivestream'] ?? 'gone') === false && ($now['pushApprovals'] ?? 'gone') === true
        && is_object($obj) === true && isset($obj->zzFutureChoice) === true && $obj->zzFutureChoice->nested instanceof \stdClass
        && $obj->zzFutureChoice->count === 3 && $obj->zzFutureChoice->words === 'kept as stored';
    return [$answer['status'] === 302 && $keptOthers, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now)];
};
[$okB, $detailB] = $scenarioB();
check('B1 THE #667 PROPERTY: the card\'s retired keys, an unknown key (with a nested {} still an object) and the undrawn push choices all survive',
    $okB, $detailB);
$afterB = $stored() ?? [];
check('B2 the switch left UNTICKED on the page is stored as off', ($afterB['taskReminders'] ?? null) === false, $fmt($afterB));
check('B3 a switch ticked on the page is stored as on (rotaReminders went from off to on)', ($afterB['rotaReminders'] ?? null) === true, $fmt($afterB));
check('B4 every switch the page draws was written, and nothing the page does not draw was added',
    array_keys($afterB) === (static function () use ($fullForm): array {
        $k = array_merge($fullForm['markers'], ['emailDigest', 'expenseUpdates', 'pushApprovals', 'pushLivestream', 'zzFutureChoice']);
        $k = array_values(array_unique($k));
        sort($k);
        return $k;
    })(), $fmt(array_keys($afterB)));

// =============================================================================
echo "\nC. Saving on the Account card keeps every key the full page owns\n";
// =============================================================================
$beforeC = '{"eventReminders": true, "givingStatements": false, "taskReminders": false, "rotaReminders": false,'
    . ' "approvalRequests": false, "pushLivestream": false, "zzFutureChoice": "kept"}';
/** @return array{0:bool, 1:string} */
$scenarioC = static function () use ($setStored, $stored, $saveCard, $beforeC, $fmt): array {
    $setStored($beforeC);
    $answer = $saveCard(false);
    $now    = $stored() ?? [];
    $want   = json_decode($beforeC, true);
    $want['eventReminders'] = false;
    ksort($want);
    return [$answer['status'] === 302 && $now === $want, 'save HTTP ' . $answer['status'] . ' to ' . $answer['location'] . '; stored ' . $fmt($now) . ', wanted ' . $fmt($want)];
};
[$okC, $detailC] = $scenarioC();
check('C1 the card\'s box left UNTICKED reads off, and every other key — the full page\'s five and an unknown one — is exactly as it was, with no emailDigest or expenseUpdates written',
    $okC, $detailC);
$tick = $saveCard(true);
check('C2 ticking it again stores it on', ($stored()['eventReminders'] ?? null) === true && $tick['status'] === 302, $fmt($stored()));
check('C3 the card sends the member back to /account saying it was saved (the old message went to the session and was never shown)',
    str_contains($tick['location'], 'notify_saved=1') === true, 'to ' . $tick['location']);
$shown = $http('GET', '/account?notify_saved=1');
check('C4 ...and the Account page really shows that sentence',
    str_contains($shown['body'], 'Your notification preferences have been saved.') === true, 'HTTP ' . $shown['status']);

// =============================================================================
echo "\nD. A field that did not arrive changes nothing (#677)\n";
// =============================================================================
$beforeD = '{"eventReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}';
/** @return array{0:bool, 1:string} */
$scenarioD = static function () use ($setStored, $stored, $http, $formOn, $beforeD, $fmt): array {
    $setStored($beforeD);
    $want = $stored();
    $page = $http('GET', '/account/notifications');
    $form = $formOn($page['body'], 'account/notifications/save');
    // Everything a browser would send EXCEPT the markers: an out-of-date page.
    $fields = array_filter($form['fields'], static fn (string $name): bool => str_starts_with($name, 'prefsShown[') === false, ARRAY_FILTER_USE_KEY);
    unset($fields['prefs[taskReminders]']);
    $answer = $http('POST', '/account/notifications/save', $fields);
    $now    = $stored();
    return [$answer['status'] === 302 && $now === $want, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now) . ', wanted ' . $fmt($want)];
};
[$okD, $detailD] = $scenarioD();
check('D1 the full page posted with NO markers (an out-of-date page) changes nothing — taskReminders, unticked, is still on', $okD, $detailD);
$told = $http('GET', '/account/notifications');
check('D2 ...and the member is told nothing was changed, rather than "saved"',
    str_contains($told['body'], 'This page was out of date, so nothing was changed.') === true, 'HTTP ' . $told['status']);

$setStored($beforeD);
$wantD3 = $stored();
$cardPage = $http('GET', '/account');
$cardForm = $formOn($cardPage['body'], 'value="update_notifications"');
$noMarker = array_filter($cardForm['fields'], static fn (string $name): bool => str_starts_with($name, 'prefsShown[') === false, ARRAY_FILTER_USE_KEY);
unset($noMarker['prefs[eventReminders]']);
$stale = $http('POST', '/account/save', $noMarker);
check('D3 the card posted with no marker changes nothing either, and says why',
    $stored() === $wantD3 && str_contains($stale['location'], 'error=notify_stale') === true,
    'to ' . $stale['location'] . '; stored ' . $fmt($stored()));

$crafted = $saveCard(true, [
    'prefsShown[emailDigest]' => '1', 'prefs[emailDigest]' => '1',
    'prefsShown[isAdmin]'     => '1', 'prefs[isAdmin]'     => '1',
    'prefsShown[0]'           => '1',
]);
$afterCraft = $stored() ?? [];
check('D4 a marker for a key no member may set (a retired one, "isAdmin", a number) is ignored — none is written',
    $crafted['status'] === 302 && array_key_exists('emailDigest', $afterCraft) === false && array_key_exists('isAdmin', $afterCraft) === false
    && array_key_exists('0', $afterCraft) === false && ($afterCraft['zzFutureChoice'] ?? null) === 'kept',
    $fmt($afterCraft));

$db->query("UPDATE `tblUsers` SET `sabbathHonour` = 'off' WHERE `userID` = {$userId}");
check('D5 (the premise) the Sabbath buttons are NOT drawn, because the organisation has the feature off',
    str_contains($http('GET', '/account/notifications')['body'], 'name="sabbathHonour"') === false);
$saveFullPage([]);
$sabbath = (string) ($db->query("SELECT `sabbathHonour` FROM `tblUsers` WHERE `userID` = {$userId}")->fetch_assoc()['sabbathHonour'] ?? '');
check('D6 so saving the page leaves the member\'s Sabbath choice ("off") alone — it used to be reset to "inherit"', $sabbath === 'off', 'is ' . $sabbath);

// Whatever happens below, the scratch triggers and the probe table this test
// makes on `tblUsers` must not outlive the run: a leftover BEFORE UPDATE
// trigger would refuse or record every later test's writes to that table.
register_shutdown_function(static function () use ($db): void {
    foreach (['DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`', 'DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`', 'DROP TABLE IF EXISTS `zzNotifyPrefsProbe`'] as $sql) {
        try {
            $db->query($sql);
        } catch (Throwable $e) {
            // The connection has gone; nothing more can be done from here.
        }
    }
});

/** Create a scratch trigger, refusing loudly when the database will not let us. */
$makeTrigger = static function (string $sql) use ($db): void {
    try {
        $db->query($sql);
    } catch (Throwable $e) {
        refuse('the database refused to create a scratch trigger on tblUsers (' . $e->getMessage() . '), which sections F and G need');
    }
};

/**
 * D7: the tick must be EXACTLY '1'. A marked switch posted as "on" is off.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioTick = static function () use ($setStored, $stored, $saveCard, $fmt): array {
    $setStored('{"eventReminders": true}');
    $answer = $saveCard(false, ['prefs[eventReminders]' => 'on']);
    $now    = $stored();
    return [$answer['status'] === 302 && $now === ['eventReminders' => false], 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now)];
};
[$okTick, $detailTick] = $scenarioTick();
check('D7 a marked switch posted as "on" rather than "1" is stored as OFF — only the exact value a page sends switches a choice on', $okTick, $detailTick);

/**
 * D8: markers that name ONLY keys nobody may set are no markers. With the
 * newsletter switched on, the old answer ("saved") also unsubscribed the
 * member, because the post carried no newsletter checkbox.
 *
 * @return array{0:bool, 1:string, 2:bool}  ok, detail, premise (the newsletter box is drawn)
 */
$scenarioOnlyUnsettable = static function () use ($db, $http, $formOn, $setStored, $stored, $userId, $siteId, $beforeD, $fmt): array {
    $db->query("UPDATE `tblSettings` SET `settingValue` = '1' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
    $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    $token = bin2hex(random_bytes(20));
    $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteId}, {$userId}, 1, '{$token}')");
    try {
        $setStored($beforeD);
        $want    = $stored();
        $page    = $http('GET', '/account/notifications');
        $premise = str_contains($page['body'], 'name="newsletterOptedIn"') === true;
        $form    = $formOn($page['body'], 'account/notifications/save');
        $answer  = $http('POST', '/account/notifications/save', [
            'csrf_token'              => $form['fields']['csrf_token'] ?? '',
            'prefsShown[isAdmin]'     => '1',
            'prefs[isAdmin]'          => '1',
            'prefsShown[emailDigest]' => '1',
        ]);
        $now   = $stored();
        $subs  = $db->query("SELECT COUNT(*) AS n, MIN(`optedIn`) AS lowest FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}")->fetch_assoc();
        $told  = str_contains($http('GET', '/account/notifications')['body'], 'This page was out of date, so nothing was changed.') === true;
        $ok    = $answer['status'] === 302 && $now === $want && (int) $subs['n'] === 1 && (int) $subs['lowest'] === 1 && $told === true;
        return [$ok, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now) . '; newsletter rows ' . $subs['n'] . ', lowest optedIn ' . $subs['lowest'] . '; told out of date: ' . ($told ? 'yes' : 'no'), $premise];
    } finally {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '0' WHERE `settingKey` = 'newsletter.enabled' AND `siteID` IS NULL");
        $db->query("DELETE FROM `tblNewsletterSubscription` WHERE `userID` = {$userId}");
    }
};
[$okUnsettable, $detailUnsettable, $premiseUnsettable] = $scenarioOnlyUnsettable();
check('D8 (the premise) with the newsletter switched on, the page draws its checkbox', $premiseUnsettable);
check('D9 a post whose markers name ONLY keys nobody may set changes nothing, does NOT unsubscribe the member from the newsletter, and says the page was out of date',
    $okUnsettable, $detailUnsettable);

// =============================================================================
echo "\nE. A stored value that is not a set of named choices, on BOTH pages\n";
// =============================================================================
/**
 * Save on one page over a stored value that is valid JSON but not a set of
 * named choices, and read back what was stored and how many replacements
 * were recorded.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioUnreadable = static function (string $raw, callable $save, array $want) use ($db, $setStored, $stored, $fmt): array {
    $db->query("DELETE FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'");
    $setStored($raw);
    $answer   = $save();
    $now      = $stored();
    $recorded = (int) ($db->query("SELECT COUNT(*) AS n FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'")->fetch_assoc()['n'] ?? 0);
    ksort($want);
    return [$answer['status'] === 302 && $now === $want && $recorded === 1, 'save HTTP ' . $answer['status'] . '; stored ' . $fmt($now) . '; recorded ' . $recorded . ' time(s)'];
};
$fullDrawnOn = array_fill_keys($fullForm['markers'], true);
$scenarioECard = static fn (): array => $scenarioUnreadable('[false, true]', static fn (): array => $saveCard(false), ['eventReminders' => false]);
$scenarioEFull = static fn (): array => $scenarioUnreadable('[1, 2]', static fn (): array => $saveFullPage([]), $fullDrawnOn);

[$okE1, $detailE1] = $scenarioECard();
check('E1 on the CARD, a stored JSON list is replaced by the one choice just saved, and the replacement is RECORDED (every other choice still reads as ON, as it already did)', $okE1, $detailE1);
[$okE2, $detailE2] = $scenarioUnreadable('5', static fn (): array => $saveCard(true), ['eventReminders' => true]);
check('E2 ...and a bare number the same way', $okE2, $detailE2);
[$okE3, $detailE3] = $scenarioEFull();
check('E3 on the FULL PAGE, a stored JSON list is replaced by the switches it drew, and the replacement is RECORDED there too', $okE3, $detailE3);
$db->query("DELETE FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'");
$setStored(null);
$saveCard(true);
$quiet = (int) ($db->query("SELECT COUNT(*) AS n FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'")->fetch_assoc()['n'] ?? 0);
check('E4 an EMPTY column is "nothing chosen yet", saved without any record', $quiet === 0 && $stored() === ['eventReminders' => true], 'rows ' . $quiet . '; stored ' . $fmt($stored()));

// =============================================================================
echo "\nF. Two saves at once lose nothing\n";
// =============================================================================
/**
 * This test's own connection takes the row lock and holds it. The REAL
 * `merge()` then runs in its own process (one process cannot block on
 * itself) and must WAIT; while it waits, this connection changes a
 * different key and commits. With the lock, the worker reads the committed
 * value afterwards and keeps that change. Without it, the worker reads the
 * old value straight away, then waits only at its write, and writes the old
 * value back over this connection's change (F2).
 *
 * ⚠️ F2 CANNOT SEE THE TRANSACTION GO, and the independent check proved it:
 *    with `begin_transaction()` removed, the worker's `FOR UPDATE` read still
 *    BLOCKS here (this connection holds the row), then autocommits, reads the
 *    committed value and writes it — so the keys come out right. The lock
 *    still evaporated between the read and the write; it is just that THIS
 *    interleaving cannot exploit it. So F3 measures the transaction itself:
 *    a scratch BEFORE UPDATE trigger records, for the connection doing the
 *    write, when its InnoDB transaction BEGAN (`INNODB_TRX.trx_started`).
 *    With the transaction, it began at the blocked read — held here for
 *    seconds on purpose — so it began well before the write. Without it, it
 *    began AT the write. `trx_started` has whole seconds only, hence the
 *    2.5-second hold and the 1.5-second threshold.
 *
 * @return array{0:bool, 1:string, 2:bool, 3:bool, 4:string}  ok, detail, premise, one transaction, its detail
 */
$scenarioF = static function () use (&$state, $db, $makeTrigger, $setStored, $stored, $userId, $child, $sep, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $fmt): array {
    $setStored('{"rotaReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}');
    $db->query('CREATE TABLE IF NOT EXISTS `zzNotifyPrefsProbe` (`probeID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `connID` BIGINT NULL, `trxStarted` DATETIME NULL, `seenAt` DATETIME(6) NOT NULL) ENGINE=InnoDB');
    $db->query('DELETE FROM `zzNotifyPrefsProbe`');
    $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`');
    try {
        $a = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        $aId = (int) $a->thread_id;
        // ⚠️ THE TRIGGER MUST NOT READ INNODB_TRX FOR THIS TEST'S OWN WRITE.
        //    MySQL serves INNODB_TRX from a cache that is refreshed only when it
        //    has not been read for 0.1 s. This connection's write comes a
        //    moment before the worker's, so when the first version recorded
        //    BOTH, the worker's lookup was answered from the cache filled by
        //    this connection's lookup a few milliseconds earlier — which still
        //    showed the worker's transaction from its blocked READ. So the
        //    plant that removes the transaction measured as "began at the
        //    read" and passed. Only the worker's own write reads it now, and
        //    nothing else here reads INNODB_TRX during the 2.5-second hold.
        $makeTrigger(
            'CREATE TRIGGER `zzNotifyPrefsProbeTrg` BEFORE UPDATE ON `tblUsers` FOR EACH ROW BEGIN '
            . "IF CONNECTION_ID() <> {$aId} THEN "
            . 'INSERT INTO `zzNotifyPrefsProbe` (`connID`, `trxStarted`, `seenAt`) '
            . 'SELECT CONNECTION_ID(), (SELECT `trx_started` FROM information_schema.INNODB_TRX WHERE `trx_mysql_thread_id` = CONNECTION_ID() LIMIT 1), NOW(6); '
            . 'END IF; END'
        );
        $a->begin_transaction();
        $held = $a->query("SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$userId} FOR UPDATE")->fetch_assoc();

        $out = $child . $sep . 'worker-' . bin2hex(random_bytes(4)) . '.out';
        $env = getenv();
        $env['NP_HOST'] = $dbHost;
        $env['NP_USER'] = $dbUser;
        $env['NP_PASS'] = $dbPass;
        $env['NP_NAME'] = $dbName;
        $env['NP_PORT'] = (string) $dbPort;
        $state['worker'] = proc_open(
            [PHP_BINARY, $child . $sep . 'worker.php', $child . $sep . 'web' . $sep . '_core' . $sep . 'NotifyPrefs.php', (string) $userId],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']],
            $pipes,
            $child,
            $env
        );
        // ⚠️ A SLEEP IS A HOPE; THE CHECK AFTER IT IS THE PREMISE. The worker
        //    must be connected and waiting on this row before this connection
        //    writes, or neither version of the code is being tested (#735).
        $waiting = false;
        for ($i = 0; $i < 60 && $waiting === false; $i++) {
            usleep(100000);
            $probe   = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
            $waiting = (int) ($probe->query("SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE `trx_state` = 'LOCK WAIT'")->fetch_assoc()['n'] ?? 0) > 0;
            $probe->close();
        }
        $running = proc_get_status($state['worker'])['running'] === true;
        // Hold the row long enough that a transaction begun at the worker's
        // read is measurably older than its write (see F3 above).
        usleep(2500000);

        $mine = json_decode((string) $held['notifyPrefs'], true);
        $mine['rotaReminders'] = false;
        $json = json_encode($mine);
        $w = $a->prepare('UPDATE `tblUsers` SET `notifyPrefs` = ? WHERE `userID` = ?');
        $w->bind_param('si', $json, $userId);
        $w->execute();
        $w->close();
        $a->commit();
        $a->close();

        for ($i = 0; $i < 300 && proc_get_status($state['worker'])['running'] === true; $i++) {
            usleep(100000);
        }
        proc_close($state['worker']);
        $state['worker'] = null;
        $said = (string) @file_get_contents($out);
        $now  = $stored() ?? [];
        $ok   = str_contains($said, '"outcome":"saved"') === true
            && ($now['rotaReminders'] ?? null) === false && ($now['taskReminders'] ?? null) === false && ($now['zzFutureChoice'] ?? null) === 'kept';

        $probeRow = $db->query(
            'SELECT `trxStarted`, `seenAt`, TIMESTAMPDIFF(MICROSECOND, `trxStarted`, `seenAt`) AS `gap` '
            . "FROM `zzNotifyPrefsProbe` WHERE `connID` <> {$aId} ORDER BY `probeID` DESC LIMIT 1"
        )->fetch_assoc();
        $gap      = ($probeRow !== null && $probeRow['gap'] !== null) ? ((int) $probeRow['gap']) / 1000000 : null;
        $oneTrx   = $gap !== null && $gap >= 1.5;
        $trxWords = $probeRow === null
            ? 'the worker\'s write was never recorded'
            : 'the worker\'s transaction began ' . ($probeRow['trxStarted'] ?? 'NOT RECORDED') . ', its write ran ' . $probeRow['seenAt']
                . ($gap === null ? '' : ' (' . number_format($gap, 2) . ' s later)');

        return [$ok, 'worker said ' . $said . ' ' . (string) @file_get_contents($out . '.err') . '; stored ' . $fmt($now), $waiting && $running, $oneTrx, $trxWords];
    } finally {
        $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsProbeTrg`');
    }
};
[$okF, $detailF, $premiseF, $oneTrxF, $trxDetailF] = $scenarioF();
check('F1 (the premise) the real merge() was connected and WAITING on the row while this test held it — otherwise F2 and F3 prove nothing', $premiseF);
check('F2 THE LOCK: both saves landed — this connection\'s rotaReminders=off AND the worker\'s taskReminders=off — and the unknown key survived', $okF, $detailF);
check('F3 THE TRANSACTION: merge()\'s read and write are ONE transaction — it began at the blocked read, well before the write — so its lock is held across both (#647, #694)',
    $oneTrxF, $trxDetailF);
echo "      ({$trxDetailF})\n";

// =============================================================================
echo "\nG. When the database refuses the save, both pages say so and nothing changes\n";
// =============================================================================
/**
 * A scratch trigger refuses any change to THIS member's choices, so the real
 * `merge()` meets a real refusal inside its transaction — no stand-in. Both
 * pages must then report the failure (the card by `?error=notify_db`, the
 * full page by its message) and the column must be as it was.
 *
 * @return array{0:bool, 1:string}
 */
$scenarioRefused = static function () use ($db, $makeTrigger, $http, $setStored, $stored, $saveCard, $saveFullPage, $userId, $fmt): array {
    $setStored('{"eventReminders": true, "taskReminders": true, "zzFutureChoice": "kept"}');
    $want = $stored();
    $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`');
    $makeTrigger(
        'CREATE TRIGGER `zzNotifyPrefsRefuseTrg` BEFORE UPDATE ON `tblUsers` FOR EACH ROW BEGIN '
        . "IF NEW.`userID` = {$userId} AND NOT (NEW.`notifyPrefs` <=> OLD.`notifyPrefs`) THEN "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by tools/notify-prefs-selftest.php'; END IF; END"
    );
    try {
        $card      = $saveCard(false);
        $afterCard = $stored();
        $cardSays  = str_contains($http('GET', '/account?error=notify_db')['body'], 'could not be saved, and nothing was changed') === true;
        $full      = $saveFullPage(['taskReminders']);
        $afterFull = $stored();
        $fullSays  = str_contains($http('GET', '/account/notifications')['body'], 'Database error saving preferences.') === true;
    } finally {
        $db->query('DROP TRIGGER IF EXISTS `zzNotifyPrefsRefuseTrg`');
    }
    $ok = str_contains($card['location'], 'error=notify_db') === true && $afterCard === $want && $cardSays === true
        && $full['status'] === 302 && $afterFull === $want && $fullSays === true;
    return [$ok, 'card to ' . $card['location'] . ', stored after ' . $fmt($afterCard) . ', card page says it: ' . ($cardSays ? 'yes' : 'no')
        . '; full page HTTP ' . $full['status'] . ', stored after ' . $fmt($afterFull) . ', page says it: ' . ($fullSays ? 'yes' : 'no')];
};
[$okG, $detailG] = $scenarioRefused();
check('G1 the card answers ?error=notify_db and the Account page says nothing was saved; the full page says "Database error saving preferences."; the column is unchanged by either',
    $okG, $detailG);

// =============================================================================
echo "\nH. The plants: each fault put back into a COPY must turn its check RED\n";
// =============================================================================
/**
 * Replace `$from` with `$to` in a file of the COPY, run `$scenario`, put the
 * file back, and report whether the plant really went in and whether the
 * scenario went red. A plant that did not apply is reported as such, never
 * counted as caught.
 *
 * @return array{applied:bool, red:bool, detail:string, restored:bool}
 */
$plant = static function (string $relative, string $from, string $to, callable $scenario) use ($child, $sep): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relative);
    $before = (string) file_get_contents($path);
    if (substr_count($before, $from) !== 1) {
        return ['applied' => false, 'red' => false, 'detail' => 'the text to replace was found ' . substr_count($before, $from) . ' times', 'restored' => true];
    }
    file_put_contents($path, str_replace($from, $to, $before));
    clearstatcache(true, $path);
    try {
        $result = $scenario();
    } finally {
        file_put_contents($path, $before);
        clearstatcache(true, $path);
    }
    return ['applied' => true, 'red' => $result[0] === false, 'detail' => $result[1], 'restored' => (string) file_get_contents($path) === $before];
};

$plants = [];

$plants['H1 the OLD whole-column rebuild, in the full page\'s handler, turns B1 RED'] = $plant(
    '_apps/auth/account/notifications-save.php',
    '$merged = NotifyPrefs::merge($mysqli, $userId, $changes);',
    '$rebuilt = []; foreach (NotifyPrefs::SETTABLE as $k) { $rebuilt[$k] = ($changes[$k] ?? false); }'
    . ' $rebuiltJson = json_encode($rebuilt); $st = $mysqli->prepare(\'UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?\');'
    . ' $st->bind_param(\'si\', $rebuiltJson, $userId); $st->execute(); $st->close();'
    . ' $merged = [\'outcome\' => NotifyPrefs::SAVED, \'replacedUnreadable\' => false];',
    $scenarioB
);

$plants['H2 the LOCK removed from merge() turns F2 RED (one key is lost)'] = $plant(
    '_core/NotifyPrefs.php',
    "'SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ? FOR UPDATE'",
    "'SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = ?'",
    static function () use ($scenarioF): array {
        $r = $scenarioF();
        return [$r[0], $r[1]];
    }
);

$plants['H3 the TRANSACTION removed from merge() (so FOR UPDATE autocommits) turns F3 RED'] = $plant(
    '_core/NotifyPrefs.php',
    '$began = $db->begin_transaction();',
    '$began = true;',
    static function () use ($scenarioF): array {
        $r = $scenarioF();
        return [$r[3], $r[4] . '; keys: ' . ($r[0] ? 'both landed' : 'one LOST')];
    }
);

$plants['H4 "no marker means change nothing" removed (a missing marker read as every switch drawn) turns D1 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "        if (array_key_exists(self::SHOWN_FIELD, \$post) === false || is_array(\$post[self::SHOWN_FIELD]) === false) {\n            return null;\n        }",
    "        if (array_key_exists(self::SHOWN_FIELD, \$post) === false || is_array(\$post[self::SHOWN_FIELD]) === false) {\n            \$post[self::SHOWN_FIELD] = array_fill_keys(self::SETTABLE, '1');\n        }",
    $scenarioD
);

// The card's handler as it stood after #612 and before #667: read, change its
// own keys (including the two it no longer draws, written as off because an
// absent box looks unticked), write back, with no lock and no marker.
$plants['H5 the card\'s OLD handler (#612\'s read-then-write, still writing the two retired keys as off) turns C1 RED'] = $plant(
    '_apps/auth/account/save.php',
    '    $merged = NotifyPrefs::merge($mysqli, $userId, $changes);',
    '    $oldRead = $mysqli->prepare(\'SELECT notifyPrefs FROM tblUsers WHERE userID = ? LIMIT 1\'); $oldRead->bind_param(\'i\', $userId);'
    . ' $oldRead->execute(); $oldRow = $oldRead->get_result()->fetch_assoc(); $oldRead->close();'
    . ' $oldStored = json_decode((string) ($oldRow[\'notifyPrefs\'] ?? \'\'), true); if (is_array($oldStored) === false) { $oldStored = []; }'
    . ' $oldStored[\'emailDigest\'] = isset($_POST[\'emailDigest\']) === true; $oldStored[\'expenseUpdates\'] = isset($_POST[\'expenseUpdates\']) === true;'
    . ' $oldStored[\'eventReminders\'] = isset($_POST[\'prefs\'][\'eventReminders\']) === true; $oldJson = json_encode($oldStored);'
    . ' $oldWrite = $mysqli->prepare(\'UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?\'); $oldWrite->bind_param(\'si\', $oldJson, $userId);'
    . ' $oldWrite->execute(); $oldWrite->close(); $merged = [\'outcome\' => NotifyPrefs::SAVED, \'replacedUnreadable\' => false];',
    $scenarioC
);

$plants['H6 the FULL PAGE\'s record of a replaced value switched off turns E3 RED'] = $plant(
    '_apps/auth/account/notifications-save.php',
    "if (\$merged['replacedUnreadable'] === true) {",
    'if (false) {',
    $scenarioEFull
);

$plants['H7 the CARD\'s record of a replaced value switched off turns E1 RED'] = $plant(
    '_apps/auth/account/save.php',
    "    if (\$merged['replacedUnreadable'] === true) {",
    '    if (false) {',
    $scenarioECard
);

$plants['H8 merge() answering "saved" when the database refused turns G1 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "rolls back for us.\n                }\n            }\n            return ['outcome' => self::FAILED, 'replacedUnreadable' => false];",
    "rolls back for us.\n                }\n            }\n            return ['outcome' => self::SAVED, 'replacedUnreadable' => false];",
    $scenarioRefused
);

$plants['H9 the tick loosened from exactly \'1\' to "anything truthy" turns D7 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "\$ticked[\$key] === '1');",
    '(bool) $ticked[$key] === true);',
    $scenarioTick
);

$plants['H10 markers naming only unsettable keys treated as a save (not as out of date) turns D9 RED'] = $plant(
    '_core/NotifyPrefs.php',
    "        if (\$changes === []) {\n            return null;\n        }",
    "        if (\$changes === []) {\n        }",
    static function () use ($scenarioOnlyUnsettable): array {
        $r = $scenarioOnlyUnsettable();
        return [$r[0], $r[1]];
    }
);

foreach ($plants as $words => $g) {
    check($words, $g['applied'] === true && $g['red'] === true && $g['restored'] === true,
        ($g['applied'] ? 'applied' : 'NOT applied') . '; ' . $g['detail']);
}

// What each plant did, printed whether or not its check passed, so a reader
// can see each went red for the RIGHT reason.
foreach ($plants as $words => $g) {
    echo '      ' . substr($words, 0, 3) . ' with the plant in: ' . $g['detail'] . "\n";
}

// With every plant taken out, all are green again — so a red above can never
// be mistaken for a plant left behind.
$againF = $scenarioF();
check('H11 with the plants removed, every scenario they broke is green again',
    $scenarioB()[0] === true && $scenarioC()[0] === true && $scenarioD()[0] === true && $againF[0] === true && $againF[3] === true
    && $scenarioEFull()[0] === true && $scenarioECard()[0] === true && $scenarioRefused()[0] === true && $scenarioTick()[0] === true
    && $scenarioOnlyUnsettable()[0] === true);

// ── Tidy up the rows this test made ─────────────────────────────────────────
$db->query("DELETE FROM `tblErrors` WHERE `errorCode` = 'NOTIFY_PREFS_UNREADABLE'");
$db->query('DROP TABLE IF EXISTS `zzNotifyPrefsProbe`');
if ($removeMember($userId) === -1) {
    echo "  ! could not remove the test member ({$userId}). Remove it by hand in {$dbName}.\n";
}

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "Sections A-E and G drive the REAL pages over HTTP with ONE worker; F races the\n";
echo "real merge() from two processes, because one worker can never run two saves at once.\n";
exit($failed === 0 ? 0 : 1);
