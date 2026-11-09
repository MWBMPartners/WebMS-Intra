<?php
// Path: tools/calendar-import-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The calendar file import, when the database refuses ONE row 📥🧪 (#790)
 * -----------------------------------------------------------------------------
 * `web/_apps/calendar/manage/import.php` saves a file's events one row at a
 * time. Under the portal's strict database reporting a refused row does not
 * answer "false" — it THROWS — and until #790 nothing in that loop caught it.
 * So one refused row in the middle of a file stopped the whole import:
 *
 *   - the rows before it were already saved, and stayed saved;
 *   - the rows after it were never tried;
 *   - the administrator got an error page instead of the list saying which
 *     rows were saved, so they could not tell which half had happened;
 *   - and the activity record of the import was never written.
 *
 * This test drives the REAL page over HTTP with a file whose MIDDLE row the
 * database is made to refuse — by a trigger on `tblEvents` that refuses one
 * event name, the technique `password-writer-selftest.php` section H uses —
 * and then reads the database back rather than believing the page:
 *
 *   A  the page finishes drawing and shows the results list
 *   B  each row the list calls imported really is in `tblEvents`, under the
 *      event number the list gives, with the name from the file
 *   C  the refused row is listed as NOT imported, with a true reason (the
 *      database's own words are shown), and nothing of it is in `tblEvents`
 *   D  the row AFTER the refused one was still tried and saved
 *   E  a row the preview had already refused is still listed as refused
 *   F  the activity record exists and gives both counts
 *   G  the refusal is recorded for whoever looks after the installation
 *   H  pressing Confirm again imports nothing a second time
 *   S  two rows with the SAME NAME, which needs no trigger at all: the
 *      second is refused in plain words and the row after it is imported
 *   T  a refusal carrying a SERVER code above 3000 (3819, a broken CHECK rule)
 *      is a refusal, not a lost connection: the row after it is still saved
 *   P  THE PLANT: with the loop's two `catch` blocks made unable to catch
 *      anything — exactly the old loop's behaviour — the same file goes red.
 *      Then the plant is taken out and the scenario is green again. A second
 *      plant puts back "every code from 2000 up is a lost connection", and
 *      section T's file goes red.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It does not drive a LOST CONNECTION part-way through (the "whether it
 *     was saved is not known" branch). Killing the page's own database
 *     connection mid-row needs a second request running alongside, and the
 *     rest of the page then cannot draw either, because the page's header
 *     reads the database too. That branch is read, not driven.
 *   - It runs ONE web worker, so two administrators importing at once is
 *     untested, and such a test would pass on broken code.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_… SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         php tools/calendar-import-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`, and the
 *         account must be allowed to CREATE TRIGGER. Needs PHP's curl extension
 *         and `proc_open()`. It REFUSES rather than skipping when anything is
 *         missing, because a skipped test reads exactly like a passing one.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/790
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

// ── The trigger that refuses ONE row ────────────────────────────────────────
// 🧨 The database refuses exactly one event name, so the file's MIDDLE row is
//    refused while the rows on either side are ordinary. A refusal by trigger
//    is what any real cause looks like from the page: `execute()` throws a
//    `mysqli_sql_exception`. Which cause it is does not matter to the loop,
//    and that is the point of #790 — the measured cause (a date out of range)
//    was closed by #777, the shape was not.
//
// ⚠️ A FAILED CREATE THROWS under strict reporting, so it is caught and turned
//    into a refusal of the run — an uncaught exception is a crash with a label,
//    not a check that reports (#735).
const REFUSED_NAME = 'P790 row refused';
const TRIGGER_TEXT = 'p790 test trigger: this row is refused on purpose';
// 🔢 A second name, refused with MYSQL_ERRNO 3819 — the code MySQL gives a row
//    that breaks a CHECK rule. A SERVER code of 3000 and up is a refusal, not a
//    lost connection; the first version of #790 read every code from 2000 up
//    as a lost connection, and section T exists to keep that from coming back.
const REFUSED_3819_NAME = 'P790 refused 3819';
const TRIGGER_3819_TEXT = 'p790 test trigger: refused with a server code above 3000';
$db->query('DROP TRIGGER IF EXISTS `p790_refuse_one_row`');
try {
    $db->query(
        'CREATE TRIGGER `p790_refuse_one_row` BEFORE INSERT ON `tblEvents` FOR EACH ROW '
        . "BEGIN IF NEW.`eventName` = '" . REFUSED_NAME . "' THEN "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" . TRIGGER_TEXT . "'; "
        . "ELSEIF NEW.`eventName` = '" . REFUSED_3819_NAME . "' THEN "
        . "SIGNAL SQLSTATE 'HY000' SET MESSAGE_TEXT = '" . TRIGGER_3819_TEXT . "', MYSQL_ERRNO = 3819; "
        . "END IF; END"
    );
} catch (Throwable $e) {
    refuse('could not create the test trigger on tblEvents (' . $e->getMessage()
        . '). The account needs the TRIGGER privilege');
}

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'calendar-import-' . bin2hex(random_bytes(6));
$state['child'] = $child;

register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['db'] instanceof mysqli) {
        try {
            $state['db']->query('DROP TRIGGER IF EXISTS `p790_refuse_one_row`');
        } catch (Throwable $e) {
            echo "  ! could not remove the test trigger p790_refuse_one_row; remove it by hand.\n";
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
        @mkdir($to, 0700, true);
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
    "<?php\n// Written by tools/calendar-import-selftest.php for ONE run.\nreturn "
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
// Written by tools/calendar-import-selftest.php for ONE run. Test only.
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

$port = 8790 + random_int(0, 150);
$jar  = $child . $sep . 'cookies.txt';

/** Start (or restart) the server against the child tree. */
$startServer = static function () use (&$state, $child, $sep, $port): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
        $state['proc'] = null;
        // Wait for the port to be free rather than racing it.
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
 * One request. `$files` sends a multipart body (the CSV upload); otherwise the
 * fields go as an ordinary form. Redirects are NOT followed, so a redirect is
 * something the caller sees.
 */
$http = static function (string $method, string $path, array $post = [], array $files = []) use ($port, $jar): array {
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
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        if (count($files) > 0) {
            $body = $post;
            foreach ($files as $field => $path) {
                $body[$field] = new CURLFile($path, 'text/csv', basename($path));
            }
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }
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

$waitForServer = static function () use ($http): void {
    for ($i = 0; $i < 80; $i++) {
        if ($http('GET', '/login')['status'] > 0) {
            return;
        }
        usleep(250000);
    }
    refuse('the test server never answered');
};

$startServer();
$waitForServer();

// ── The administrator ───────────────────────────────────────────────────────
$email      = 'calendar.import@selftest.invalid';
$signInName = 'selftest-calendar-import';
$password   = 'Sel' . bin2hex(random_bytes(8)) . '!9x';

/**
 * Remove the administrator and everything that names them, children first —
 * signing in writes activity records, and `tblActivityLogs.userID` points at
 * `tblUsers` with no cascade. The tables come from `information_schema`.
 */
$removeAdmin = static function (int $who) use ($db): int {
    if ($who <= 0) {
        return 0;
    }
    $db->query("DELETE FROM `tblEvents` WHERE `createdByID` = {$who}");
    $rs = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'tblUsers'"
    );
    $cleared = 0;
    while ($row = $rs->fetch_assoc()) {
        $table  = (string) $row['TABLE_NAME'];
        $column = (string) $row['COLUMN_NAME'];
        try {
            $db->query("DELETE FROM `{$table}` WHERE `{$column}` = {$who}");
            $cleared += 1;
        } catch (Throwable $e) {
            // A table whose own children still name the row; reported below
            // only if the account itself cannot go.
        }
    }
    $rs->free();
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        return -1;
    }
    return $cleared;
};

$old = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
$old->bind_param('s', $email);
$old->execute();
$leftovers = $old->get_result();
while ($row = $leftovers->fetch_assoc()) {
    $removeAdmin((int) $row['userID']);
}
$old->close();
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P790 %'");

$siteId = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteId <= 0) {
    refuse('the database has no organisation to import into');
}

$ins = $db->prepare(
    'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) '
    . "VALUES ('Calendar Import Admin', ?, 1, 1, 1, NOW())"
);
$ins->bind_param('s', $email);
$ins->execute();
$userId = (int) $db->insert_id;
$ins->close();
$db->query(
    'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`, `isSiteRootAdmin`) '
    . "VALUES ({$siteId}, {$userId}, 1, 1, 1)"
);
$hash = password_hash($password, PASSWORD_DEFAULT);
$acc  = $db->prepare(
    'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)'
);
$acc->bind_param('iss', $userId, $signInName, $hash);
$acc->execute();
$acc->close();

$signIn = static function () use ($http, $tokenOn, $signInName, $password, $jar): void {
    @unlink($jar);
    $token = $tokenOn($http('GET', '/login')['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $signInName, 'password' => $password]);
    if (($in['status'] !== 302 && $in['status'] !== 303) || str_contains($in['location'], 'login') === true) {
        refuse('the test administrator could not sign in (status ' . $in['status'] . ')');
    }
};

// ── The file ────────────────────────────────────────────────────────────────
// Row numbers are the SPREADSHEET's, as the page reports them: the header is
// row 1. Row 5's status is not one the import accepts, so the PREVIEW refuses
// it — that is the existing refusal, which must survive the change.
$csv = $child . $sep . 'p790.csv';
file_put_contents(
    $csv,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 first\",2031-02-01 10:00:00,2031-02-01 11:00:00,published\n"
    . '"' . REFUSED_NAME . "\",2031-02-02 10:00:00,2031-02-02 11:00:00,published\n"
    . "\"P790 last\",2031-02-03 10:00:00,2031-02-03 11:00:00,published\n"
    . "\"P790 bad status\",2031-02-04 10:00:00,2031-02-04 11:00:00,nonsense\n"
);

/**
 * The results list on the page, as [row number => [badge text, message]].
 * An empty array when the page drew no results list at all.
 */
$reportOf = static function (string $html): array {
    $start = strpos($html, 'Import results');
    if ($start === false) {
        return [];
    }
    $part = substr($html, $start);
    $out  = [];
    $n    = preg_match_all(
        '#<div class="portal-data-row">\s*<div class="col-12 col-md-2">(\d+)</div>\s*'
        . '<div class="col-12 col-md-2">\s*<span class="badge[^"]*">([^<]+)</span>\s*</div>\s*'
        . '<div class="col-12 col-md-8 small">\s*(.*?)\s*</div>#s',
        $part,
        $m,
        PREG_SET_ORDER
    );
    if ($n > 0) {
        foreach ($m as $one) {
            $out[(int) $one[1]] = [trim($one[2]), html_entity_decode(trim($one[3]), ENT_QUOTES, 'UTF-8')];
        }
    }
    return $out;
};

/**
 * One whole import: upload, preview, confirm. Returns everything the checks
 * need, read from the page AND from the database.
 */
$runImport = static function (?string $file = null) use ($http, $tokenOn, $signIn, $csv, $db, $reportOf, $userId): array {
    $file ??= $csv;
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P790 %'");
    $mark = (int) ($db->query('SELECT COALESCE(MAX(`logID`), 0) AS m FROM `tblActivityLogs`')->fetch_assoc()['m'] ?? 0);
    $errMark = (int) ($db->query('SELECT COALESCE(MAX(`errorID`), 0) AS m FROM `tblErrors`')->fetch_assoc()['m'] ?? 0);

    $signIn();
    $page    = $http('GET', '/calendar/manage/import');
    $preview = $http('POST', '/calendar/manage/import', ['csrf_token' => $tokenOn($page['body']), 'action' => 'preview'], ['csv_file' => $file]);
    $token   = $tokenOn($preview['body']) !== '' ? $tokenOn($preview['body']) : $tokenOn($page['body']);
    $confirm = $http('POST', '/calendar/manage/import', ['csrf_token' => $token, 'action' => 'confirm']);

    $events = [];
    $rs = $db->query("SELECT `eventID`, `eventName` FROM `tblEvents` WHERE `eventName` LIKE 'P790 %' ORDER BY `eventID`");
    while ($row = $rs->fetch_assoc()) {
        $events[(int) $row['eventID']] = (string) $row['eventName'];
    }
    $activity = [];
    $rs = $db->query(
        "SELECT `activityDescription` FROM `tblActivityLogs` WHERE `logID` > {$mark} "
        . "AND `activityType` = 'EventsImported' AND `userID` = {$userId}"
    );
    while ($row = $rs->fetch_assoc()) {
        $activity[] = (string) $row['activityDescription'];
    }
    $errors = [];
    $rs = $db->query("SELECT `errorSeverity`, `errorTitle`, `errorDetail` FROM `tblErrors` WHERE `errorID` > {$errMark}");
    while ($row = $rs->fetch_assoc()) {
        $errors[] = (string) $row['errorSeverity'] . ' | ' . (string) $row['errorTitle'] . ' | ' . (string) $row['errorDetail'];
    }

    return [
        'previewStatus' => $preview['status'],
        'previewReady'  => str_contains($preview['body'], 'Confirm &amp; import valid rows'),
        'status'        => $confirm['status'],
        'whole'         => str_contains($confirm['body'], '</html>'),
        'report'        => $reportOf($confirm['body']),
        'events'        => $events,
        'activity'      => $activity,
        'errors'        => $errors,
        'body'          => $confirm['body'],
    ];
};

/** The event number a "Imported as event #N" message names, or 0. */
$numberIn = static function (string $msg): int {
    return preg_match('/Imported as event #(\d+)\b/', $msg, $m) === 1 ? (int) $m[1] : 0;
};

echo "A. The page finishes and shows the results list\n";
$r = $runImport();
check('A0 the preview drew the Confirm button, so the confirm below is a real one',
    $r['previewReady'], true);
check('A1 the confirm answers 200', $r['status'], 200);
check('A2 ...and the page finished drawing (a page that throws part-way can still answer 200)',
    $r['whole'], true);
check('A3 THE RESULTS LIST IS THERE, one line for each of the four rows',
    array_keys($r['report']), [2, 3, 4, 5]);

echo "B. Every row the list calls imported really is in the database\n";
$firstId = $numberIn($r['report'][2][1] ?? '');
check('B1 row 2 is listed as imported', $r['report'][2][0] ?? null, 'OK');
check('B2 ...and the event number it gives holds row 2\'s event',
    $r['events'][$firstId] ?? null, 'P790 first');

echo "C. The refused row is listed as NOT imported, with a true reason\n";
check('C1 row 3 is listed as not imported', $r['report'][3][0] ?? null, 'Skipped');
$msg3 = $r['report'][3][1] ?? '';
check('C2 ...the reason says the database refused it',
    str_contains($msg3, 'the database refused this row'), true);
check('C3 ...and carries the database\'s own words, so the administrator can fix the row',
    str_contains($msg3, TRIGGER_TEXT), true);
check('C4 ...and says nothing of it was saved — which C5 proves is true',
    str_contains($msg3, 'Nothing from this row was saved'), true);
check('C5 NOTHING of the refused row is in the database',
    in_array(REFUSED_NAME, $r['events'], true), false);

echo "D. The row AFTER the refused one was still tried and saved\n";
$lastId = $numberIn($r['report'][4][1] ?? '');
check('D1 row 4 is listed as imported', $r['report'][4][0] ?? null, 'OK');
check('D2 THE WHOLE POINT: row 4 is really in the database under the number given',
    $r['events'][$lastId] ?? null, 'P790 last');
check('D3 exactly the two imported rows are in the database, nothing else',
    array_values($r['events']), ['P790 first', 'P790 last']);

echo "E. A row the preview had already refused is still listed as refused\n";
check('E1 row 5 is listed as not imported', $r['report'][5][0] ?? null, 'Skipped');
check('E2 ...with the preview\'s own reason',
    str_contains($r['report'][5][1] ?? '', 'status must be'), true);

echo "F. The activity record is written, with both counts\n";
check('F1 exactly one EventsImported record for this import', count($r['activity']), 1);
check('F2 ...saying 2 events were created',
    str_contains($r['activity'][0] ?? '', '2 events created'), true);
check('F3 ...and 2 rows were not imported (the refused one and the preview\'s)',
    str_contains($r['activity'][0] ?? '', '2 rows not imported'), true);

echo "G. The refusal is recorded for whoever looks after the installation\n";
$recorded = array_values(array_filter($r['errors'], static fn (string $e): bool => str_contains($e, TRIGGER_TEXT)));
check('G1 exactly one error record carries the database\'s words', count($recorded), 1);
check('G3 ...at "Notification", the level the alert e-mail leaves out by default, so a re-imported '
    . 'file of refused rows cannot send a message per row',
    str_starts_with($recorded[0] ?? '', 'Notification | '), true);
check('G4 ...and it never says the connection was lost, because it was not',
    str_contains($recorded[0] ?? '', 'connection was lost'), false);
check('G2 ...and it says which row of an import it was',
    str_contains($recorded[0] ?? '', 'row 3'), true);

echo "H. Pressing Confirm again imports nothing a second time\n";
$again = $http('POST', '/calendar/manage/import', [
    'csrf_token' => $tokenOn($r['body']) !== '' ? $tokenOn($r['body']) : $tokenOn($http('GET', '/calendar/manage/import')['body']),
    'action'     => 'confirm',
]);
check('H1 the page says there is nothing to import', str_contains($again['body'], 'No preview data'), true);
$count = (int) ($db->query("SELECT COUNT(*) AS n FROM `tblEvents` WHERE `eventName` LIKE 'P790 %'")->fetch_assoc()['n'] ?? -1);
check('H2 ...and the database still holds exactly the two events', $count, 2);

echo "S. The SAME NAME three times: the ordinary cause, with no trigger at all\n";
// 🔤 An imported event's web address is made from its NAME alone, and the
//    database refuses a second event at the same address (#877). So a file
//    holding the same service more than once — the commonest thing anybody
//    imports — had its second row refused, and before #790 that refusal
//    stopped the import exactly as the trigger does above. This section needs
//    no trigger: it is the cause a real file meets. THREE copies, so two rows
//    are refused and S5 can tell one record per import from one per row.
$dup = $child . $sep . 'p790-dup.csv';
file_put_contents(
    $dup,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 Sunday Service\",2031-03-02 10:00:00,2031-03-02 11:00:00,published\n"
    . "\"P790 Sunday Service\",2031-03-09 10:00:00,2031-03-09 11:00:00,published\n"
    . "\"P790 Sunday Service\",2031-03-16 10:00:00,2031-03-16 11:00:00,published\n"
    . "\"P790 after the twins\",2031-03-17 10:00:00,2031-03-17 11:00:00,published\n"
);
$s = $runImport($dup);
check('S1 the page finishes with a list of all four rows', array_keys($s['report']), [2, 3, 4, 5]);
check('S2 the first service is imported, the two copies are not, the row after is imported',
    array_map(static fn (array $line): string => $line[0], $s['report']),
    [2 => 'OK', 3 => 'Skipped', 4 => 'Skipped', 5 => 'OK']);
check('S3 ...and a copy\'s reason says, in plain words, that the name\'s web address is taken',
    str_contains($s['report'][3][1] ?? '', 'another event already has the web address made from this name'), true);
check('S4 the database holds exactly what the list says',
    array_values($s['events']), ['P790 Sunday Service', 'P790 after the twins']);
$dupRecords = array_values(array_filter($s['errors'], static fn (string $e): bool => str_contains($e, 'calendar file import')));
check('S6 TWO refused rows make ONE error record for the import, not one per row', count($dupRecords), 1);
check('S7 ...which counts both rows', str_contains($dupRecords[0] ?? '', '2 row(s) refused'), true);

echo "T. A server code above 3000 is a REFUSAL, not a lost connection\n";
$csv3819 = $child . $sep . 'p790-3819.csv';
file_put_contents(
    $csv3819,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 T before\",2031-04-01 10:00:00,2031-04-01 11:00:00,published\n"
    . '"' . REFUSED_3819_NAME . "\",2031-04-02 10:00:00,2031-04-02 11:00:00,published\n"
    . "\"P790 T after\",2031-04-03 10:00:00,2031-04-03 11:00:00,published\n"
);
/** Section T's file, reduced to what the checks and the plant compare. */
$scnServerCode = static function () use ($runImport, $csv3819): array {
    $t = $runImport($csv3819);
    return [
        'lines'   => array_map(static fn (array $line): string => $line[0], $t['report']),
        'msg3'    => $t['report'][3][1] ?? '',
        'events'  => array_values($t['events']),
        'lostBox' => str_contains($t['body'], 'The connection to the database was lost at row'),
    ];
};
$t = $scnServerCode();
check('T1 the row refused with code 3819 is "Skipped", and the rows either side are imported',
    $t['lines'], [2 => 'OK', 3 => 'Skipped', 4 => 'OK']);
check('T2 ...its reason is the database\'s refusal, with the code',
    str_contains($t['msg3'], 'the database refused this row (error 3819'), true);
check('T3 ...THE ROW AFTER IT IS SAVED — under the first version it was never tried',
    $t['events'], ['P790 T before', 'P790 T after']);
check('T4 ...and the page does not claim the connection was lost', $t['lostBox'], false);

// 🔇 Read BEFORE the plant, which throws on purpose and fills the log.
$phpLog  = (string) @file_get_contents($child . $sep . 'php-errors.log');
$ownLines = array_values(array_filter(
    explode("\n", $phpLog),
    static fn (string $line): bool => str_contains($line, 'manage' . DIRECTORY_SEPARATOR . 'import.php')
));
check('S5 the import page wrote no PHP error, warning or notice in sections A to T', $ownLines, []);

echo "P. The plant: the old loop, with nothing catching, goes RED\n";
// 🚨 THIS IS WHAT MAKES THE CHECKS ABOVE WORTH HAVING. The plant makes both
//    `catch` blocks name a class that does not exist, so they can never match
//    and a throw escapes exactly as it did before #790 — which is the old loop
//    without deleting a line of the new one. It goes into the CHILD copy,
//    never the repository, and it must apply, or the section refuses rather
//    than passing on a plant that never went in.
$pagePath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'calendar' . $sep . 'manage' . $sep . 'import.php';
$before   = (string) file_get_contents($pagePath);
$catches  = ['} catch (\mysqli_sql_exception $refused) {', '} catch (\Throwable $fault) {'];
foreach ($catches as $one) {
    if (substr_count($before, $one) !== 1) {
        refuse('section P could not find exactly one "' . $one . '" in the copied page, so it cannot plant anything');
    }
}
file_put_contents($pagePath, str_replace(
    $catches,
    ['} catch (\P790NeverThrownOne $refused) {', '} catch (\P790NeverThrownTwo $fault) {'],
    $before
));
clearstatcache(true, $pagePath);
$startServer();
$waitForServer();
$planted = $runImport();
check('P1 with nothing catching, the results list is GONE (the administrator cannot see which rows landed)',
    $planted['report'], []);
check('P2 ...row 2 was still saved before the throw — the half-import #790 is about',
    array_values($planted['events']), ['P790 first']);
check('P3 ...row 4, after the refused row, was never tried', in_array('P790 last', $planted['events'], true), false);
check('P4 ...and no activity record was written', $planted['activity'], []);

file_put_contents($pagePath, $before);
clearstatcache(true, $pagePath);

// 🔌 The second plant puts back the first version's test, "2000 and above is a
//    lost connection", and section T's file must go red.
$codeTest = "if (\$code >= 2000 && \$code <= 2999) {\n"
    . "                            return true;\n"
    . "                        }\n"
    . "                        return \$onMariaDb === true ? \$code === 1927 : \$code === 4031;";
if (substr_count($before, $codeTest) !== 1) {
    refuse('section P could not find exactly one copy of the lost-connection test in the copied page');
}
file_put_contents($pagePath, str_replace($codeTest, 'return $code >= 2000;', $before));
clearstatcache(true, $pagePath);
$startServer();
$waitForServer();
$t = $scnServerCode();
check('P7 with "2000 and above" put back, the 3819 row is reported as a lost connection ("Not known")',
    $t['lines'][3] ?? null, 'Not known');
check('P8 ...and the row after it is never tried', $t['events'], ['P790 T before']);
check('P9 ...and the page claims the connection was lost', $t['lostBox'], true);

file_put_contents($pagePath, $before);
clearstatcache(true, $pagePath);
$startServer();
$waitForServer();
$restored = $runImport();
check('P5 with the plant taken out, the same file gives the same list as section A again',
    array_map(static fn (array $line): string => $line[0], $restored['report']),
    [2 => 'OK', 3 => 'Skipped', 4 => 'OK', 5 => 'Skipped']);
check('P6 ...and the same two events', array_values($restored['events']), ['P790 first', 'P790 last']);

// ── Tidy up ─────────────────────────────────────────────────────────────────
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P790 %'");
if ($removeAdmin($userId) === -1) {
    echo "  ! could not remove the test administrator ({$userId}). Remove it by hand in {$dbName}.\n";
}

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "It drives the REAL import page over HTTP and reads the database back. It does\n";
echo "NOT drive a database connection lost part-way through, and it runs ONE web\n";
echo "worker, so two administrators importing at once is untested.\n";
exit($failed === 0 ? 0 : 1);
