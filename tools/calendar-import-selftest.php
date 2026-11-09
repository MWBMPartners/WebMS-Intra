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
 *   S  the SAME NAME on several dates, which needs no trigger at all (#877):
 *      EVERY row is imported, each at its own web address — name and date,
 *      with a numbered ending where two share a day or a DELETED event
 *      already holds the address (the database's unique key covers deleted
 *      rows). Until #877 every row after the first was refused.
 *   V  a row ALREADY IN THE CALENDAR (same name letter for letter, same
 *      start) is left out unless "Import these anyway" is ticked (D-100):
 *      a re-upload adds nothing, the tick imports every row again at the next
 *      free addresses, a mixed file imports only the new rows, a DELETED event
 *      does not count, a COPIED-IN event of the organisation does, and an
 *      event added between preview and Confirm is caught at Confirm; only
 *      the exact value "1" counts as ticked
 *   X  a row the FILE repeats exactly is marked "repeats row N" in the
 *      preview and left out at Confirm unless ticked, so the two pages agree
 *   R  another writer taking the web address between the import asking
 *      whether it is free and saving the row (#877). First stood in for by
 *      the trigger: taken once, the row is saved on its second try; taken
 *      every time, it is refused after three tries and says why, and the rest
 *      of the file is still saved. Then a REAL second writer: this test holds
 *      the address in an open transaction while the real page saves, so the
 *      page's "is it free" question cannot see it and its INSERT waits on the
 *      key; once the test commits, the INSERT is refused and the page must
 *      ask again and save the event at the next free address (`-2`)
 *   T  a refusal carrying a SERVER code above 3000 (3819, a broken CHECK rule)
 *      is a refusal, not a lost connection: the row after it is still saved
 *   U  a refusal about the DATABASE (1114, a full table) is recorded as
 *      "Error", where a refusal about the ROW (section G) is "Notification"
 *   P  THE PLANT: with the loop's two `catch` blocks made unable to catch
 *      anything — exactly the old loop's behaviour — the same file goes red.
 *      Then the plant is taken out and the scenario is green again. A second
 *      plant puts back "every code from 2000 up is a lost connection", and
 *      section T's file goes red. Two more collapse the row-or-database
 *      split, once each way, and sections U and G go red. Five more (#877)
 *      put back the name-only address, leave deleted events out of the
 *      "is it free" question, drop the date from the address, allow only
 *      one try, and ask "is it free" only ONCE before the tries (so every
 *      try repeats an address already refused) — and sections S and R go
 *      red for each. Six more (D-100) remove the already-in-the-calendar
 *      question, ignore the tick, make Confirm trust the preview's mark,
 *      count deleted events, leave copied-in events out, and ignore
 *      capitals — and section V goes red for each. Three more count any
 *      value as ticked and make each page forget the file's own repeats
 *      (sections V and X go red).
 *   L  a connection lost BEFORE a row's INSERT is sent (during one of the two
 *      questions asked first) is reported as "not imported, nothing saved",
 *      never as "may have been saved". Driven by one line injected into the
 *      CHILD copy of `Events::freeSlug()` that throws a lost connection's own
 *      error code; a last plant calls every lost connection "not known"
 *      again and L goes red.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It does not drive a LOST CONNECTION part-way through (the "whether it
 *     was saved is not known" branch). Killing the page's own database
 *     connection mid-row needs a second request running alongside, and the
 *     rest of the page then cannot draw either, because the page's header
 *     reads the database too. That branch is read, not driven.
 *   - It runs ONE web worker, so two administrators pressing Confirm at once
 *     is not driven as two requests. What matters to the web address is not
 *     how many people there are but which two things happen in which order:
 *     another writer's row arriving between the page asking "is it free" and
 *     the page saving. Section R makes exactly that order happen with ONE
 *     page request and a transaction held open by this test, and checks the
 *     premise (the page's INSERT really is waiting on the key) before it
 *     believes the outcome. Anything else two simultaneous imports could do
 *     to each other is not covered.
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
// 🗄️ A third name, refused with MYSQL_ERRNO 1114 — "the table is full". That
//    is about the DATABASE, not the row: the person looking at the results
//    cannot fix it, the installation's administrator can, so it must be
//    recorded as "Error" (section U). 1644 above is about the ROW, so it is
//    "Notification" (section G). The first version recorded both the same.
const REFUSED_1114_NAME = 'P790 refused 1114';
const TRIGGER_1114_TEXT = 'p790 test trigger: the table is full, on purpose';
// 🏁 Two more names stand in for ANOTHER WRITER TAKING THE WEB ADDRESS between
//    the import asking whether it is free and saving the row (#877). One
//    process cannot race itself, so the trigger refuses the row with exactly
//    the refusal the unique key gives (1062, naming `uq_event_site_slug`):
//    "race once" only on its FIRST try, so the import's retry must save it;
//    "race always" on every try, so the retry must give up after a bounded
//    number and say so. Each try is counted in `p877_slug_tries`, a MEMORY
//    table, because a refused statement takes back what an InnoDB table was
//    given but NOT what a MEMORY table was given — the count survives the
//    refusal it is counting.
const RACE_ONCE_NAME   = 'P790 race once';
const RACE_ALWAYS_NAME = 'P790 race always';
const RACE_TEXT        = "Duplicate entry 'p877' for key 'uq_event_site_slug'";
$db->query('DROP TRIGGER IF EXISTS `p790_refuse_one_row`');
$db->query('DROP TABLE IF EXISTS `p877_slug_tries`');
try {
    $db->query(
        'CREATE TABLE `p877_slug_tries` (`eventName` VARCHAR(100) NOT NULL PRIMARY KEY, `tries` INT NOT NULL) '
        // ⚠️ THE COLLATION IS NAMED, and must match `tblEvents.eventName`'s
        //    (`utf8mb4_general_ci`), because the trigger compares the two. Left
        //    to the database's default, a database made with a plain CREATE
        //    DATABASE — as the workflows do — gets MySQL 8.0's
        //    utf8mb4_0900_ai_ci or MariaDB 11.4's utf8mb4_uca1400_ai_ci, and the
        //    comparison fails with 1267 "Illegal mix of collations" (found by
        //    the second independent check: 98 of 106 on both engines).
        . 'ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
} catch (Throwable $e) {
    refuse('could not create the test table p877_slug_tries (' . $e->getMessage() . ')');
}
try {
    $db->query(
        'CREATE TRIGGER `p790_refuse_one_row` BEFORE INSERT ON `tblEvents` FOR EACH ROW '
        . "BEGIN IF NEW.`eventName` = '" . REFUSED_NAME . "' THEN "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" . TRIGGER_TEXT . "'; "
        . "ELSEIF NEW.`eventName` = '" . REFUSED_3819_NAME . "' THEN "
        . "SIGNAL SQLSTATE 'HY000' SET MESSAGE_TEXT = '" . TRIGGER_3819_TEXT . "', MYSQL_ERRNO = 3819; "
        . "ELSEIF NEW.`eventName` = '" . REFUSED_1114_NAME . "' THEN "
        . "SIGNAL SQLSTATE 'HY000' SET MESSAGE_TEXT = '" . TRIGGER_1114_TEXT . "', MYSQL_ERRNO = 1114; "
        . "ELSEIF NEW.`eventName` IN ('" . RACE_ONCE_NAME . "', '" . RACE_ALWAYS_NAME . "') THEN "
        . "INSERT INTO `p877_slug_tries` (`eventName`, `tries`) VALUES (NEW.`eventName`, 1) "
        . "ON DUPLICATE KEY UPDATE `tries` = `tries` + 1; "
        . "IF NEW.`eventName` = '" . RACE_ALWAYS_NAME . "' "
        . "OR (SELECT `tries` FROM `p877_slug_tries` WHERE `eventName` = NEW.`eventName`) = 1 THEN "
        . "SIGNAL SQLSTATE '23000' SET MESSAGE_TEXT = '" . str_replace("'", "''", RACE_TEXT) . "', MYSQL_ERRNO = 1062; "
        . "END IF; "
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
        try {
            $state['db']->query('DROP TABLE IF EXISTS `p877_slug_tries`');
            $state['db']->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P877 %'");
        } catch (Throwable $e) {
            echo "  ! could not remove p877_slug_tries or the P877 holder event; remove them by hand.\n";
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
 *
 * `$clear` false keeps the events already there (D-100's re-import checks);
 * `$confirmExtra` adds fields to the Confirm press (the "Import these anyway"
 * tick); `$between` runs after the preview and before Confirm (an event added
 * in between).
 */
$runImport = static function (
    ?string $file = null,
    bool $clear = true,
    array $confirmExtra = [],
    ?callable $between = null
) use ($http, $tokenOn, $signIn, $csv, $db, $reportOf, $userId): array {
    $file ??= $csv;
    if ($clear === true) {
        $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P790 %'");
    }
    $db->query('DELETE FROM `p877_slug_tries`');
    $mark = (int) ($db->query('SELECT COALESCE(MAX(`logID`), 0) AS m FROM `tblActivityLogs`')->fetch_assoc()['m'] ?? 0);
    $errMark = (int) ($db->query('SELECT COALESCE(MAX(`errorID`), 0) AS m FROM `tblErrors`')->fetch_assoc()['m'] ?? 0);

    $signIn();
    $page    = $http('GET', '/calendar/manage/import');
    $preview = $http('POST', '/calendar/manage/import', ['csrf_token' => $tokenOn($page['body']), 'action' => 'preview'], ['csv_file' => $file]);
    $token   = $tokenOn($preview['body']) !== '' ? $tokenOn($preview['body']) : $tokenOn($page['body']);
    if ($between !== null) {
        $between();
    }
    $confirm = $http('POST', '/calendar/manage/import', ['csrf_token' => $token, 'action' => 'confirm'] + $confirmExtra);

    $events = [];
    $slugs  = [];
    $rs = $db->query(
        "SELECT `eventID`, `eventName`, `eventSlug` FROM `tblEvents` WHERE `eventName` LIKE 'P790 %' ORDER BY `eventID`"
    );
    while ($row = $rs->fetch_assoc()) {
        $events[(int) $row['eventID']] = (string) $row['eventName'];
        $slugs[(int) $row['eventID']]  = (string) $row['eventSlug'];
    }
    $tries = [];
    $rs = $db->query('SELECT `eventName`, `tries` FROM `p877_slug_tries` ORDER BY `eventName`');
    while ($row = $rs->fetch_assoc()) {
        $tries[(string) $row['eventName']] = (int) $row['tries'];
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
        'previewBody'   => $preview['body'],
        'status'        => $confirm['status'],
        'whole'         => str_contains($confirm['body'], '</html>'),
        'report'        => $reportOf($confirm['body']),
        'events'        => $events,
        'slugs'         => $slugs,
        'tries'         => $tries,
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
check('G5 ...and its title speaks of ONE row, because one was refused',
    str_contains($recorded[0] ?? '', '| A row of a calendar file import was refused by the database |'), true);

echo "H. Pressing Confirm again imports nothing a second time\n";
$again = $http('POST', '/calendar/manage/import', [
    'csrf_token' => $tokenOn($r['body']) !== '' ? $tokenOn($r['body']) : $tokenOn($http('GET', '/calendar/manage/import')['body']),
    'action'     => 'confirm',
]);
check('H1 the page says there is nothing to import', str_contains($again['body'], 'No preview data'), true);
$count = (int) ($db->query("SELECT COUNT(*) AS n FROM `tblEvents` WHERE `eventName` LIKE 'P790 %'")->fetch_assoc()['n'] ?? -1);
check('H2 ...and the database still holds exactly the two events', $count, 2);

echo "S. The SAME NAME on several dates imports EVERY row, each at its own web address (#877)\n";
// 🔤 An imported event's web address used to be made from its NAME alone, and
//    the database refuses a second event at the same address. So a file
//    holding the same service more than once — the commonest thing anybody
//    imports — had every row after the first refused (before #790 the refusal
//    stopped the whole import). Now the address is the name plus the date, and
//    a free one is ASKED OF THE DATABASE.
//
//    The file is built to reach every branch of that, with no trigger at all:
//      - THREE dates of "P790 Sunday Service", so the date alone separates them;
//      - a FOURTH on the same day as the third, evening, so the date does NOT
//        separate them and the numbered ending (`-2`) must;
//      - a DELETED event (named P877, so `$runImport` leaves it alone) already
//        holding the first date's address, because `uq_event_site_slug` covers
//        deleted rows: a probe that skipped deleted events would call the
//        address free and the database would then refuse it.
$holderSlug = 'p790-sunday-service-2031-03-02';
$db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P877 %'");
$holder = $db->prepare(
    'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, '
    . "`isDeleted`, `deletedAt`) VALUES (?, 'P877 deleted holder', ?, '2031-03-02 10:00:00', 'Europe/London', "
    . "'published', 1, NOW())"
);
$holder->bind_param('is', $siteId, $holderSlug);
$holder->execute();
$holderId = (int) $db->insert_id;
$holder->close();
if ($holderId <= 0) {
    refuse('could not make the deleted event that holds the first date\'s address');
}

$dup = $child . $sep . 'p790-dup.csv';
file_put_contents(
    $dup,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 Sunday Service\",2031-03-02 10:00:00,2031-03-02 11:00:00,published\n"
    . "\"P790 Sunday Service\",2031-03-09 10:00:00,2031-03-09 11:00:00,published\n"
    . "\"P790 Sunday Service\",2031-03-16 10:00:00,2031-03-16 11:00:00,published\n"
    . "\"P790 Sunday Service\",2031-03-16 18:00:00,2031-03-16 19:00:00,published\n"
    . "\"P790 after the services\",2031-03-17 10:00:00,2031-03-17 11:00:00,published\n"
);
/** Section S's file, reduced to what the checks and the plants compare. */
$scnSameName = static function () use ($runImport, $dup): array {
    $s = $runImport($dup);
    return [
        'keys'    => array_keys($s['report']),
        'lines'   => array_map(static fn (array $line): string => $line[0], $s['report']),
        'events'  => array_values($s['events']),
        'slugs'   => array_values($s['slugs']),
        'records' => array_values(array_filter(
            $s['errors'],
            static fn (string $e): bool => str_contains($e, 'calendar file import')
        )),
    ];
};
$s = $scnSameName();
check('S1 the page finishes with a list of all five rows', $s['keys'], [2, 3, 4, 5, 6]);
check('S2 THE WHOLE POINT: every row is imported — the same name on four dates, and the row after',
    $s['lines'], [2 => 'OK', 3 => 'OK', 4 => 'OK', 5 => 'OK', 6 => 'OK']);
check('S3 the database holds exactly what the list says',
    $s['events'],
    ['P790 Sunday Service', 'P790 Sunday Service', 'P790 Sunday Service', 'P790 Sunday Service', 'P790 after the services']);
check('S4 each has its own web address: name and date, "-2" where a DELETED event holds the first date\'s, '
    . 'and "-2" for the second service on one day',
    $s['slugs'],
    [
        'p790-sunday-service-2031-03-02-2',
        'p790-sunday-service-2031-03-09',
        'p790-sunday-service-2031-03-16',
        'p790-sunday-service-2031-03-16-2',
        'p790-after-the-services-2031-03-17',
    ]);
check('S6 nothing was refused, so nothing about this import is recorded as an error', $s['records'], []);
$heldBy = $db->query(
    "SELECT `eventID`, `isDeleted` FROM `tblEvents` WHERE `eventSlug` = '{$holderSlug}' AND `siteID` = {$siteId}"
)->fetch_all(MYSQLI_ASSOC);
check('S7 the deleted event still holds its address, untouched, and nothing else was given it',
    array_map(static fn (array $row): array => [(int) $row['eventID'], (int) $row['isDeleted']], $heldBy),
    [[$holderId, 1]]);

echo "V. A row ALREADY IN THE CALENDAR is left out unless the manager ticks to import it (D-100)\n";
// 📌 #877 removed an accidental safety net: with the address made from the
//    name alone, importing the same file twice was refused row by row. With
//    every row given its own address, a re-upload (what D-099 tells a manager
//    to do after fixing one bad row) would quietly double every event. The
//    owner chose (D-100): a row with the same name and the same start as an
//    event already in the organisation's calendar is left out unless
//    "Import these anyway" is ticked — asked at CONFIRM, not only at preview.
/**
 * Section S's file imported once from clean, then uploaded AGAIN without
 * clearing — with or without the tick. Returns the second import's outcome.
 */
$scnReimport = static function (bool $tick, string $tickValue = '1') use ($runImport, $dup, $db): array {
    $first  = $runImport($dup);
    $second = $runImport($dup, false, $tick === true ? ['importExisting' => $tickValue] : []);
    return [
        'firstLines'   => array_map(static fn (array $line): string => $line[0], $first['report']),
        'lines'        => array_map(static fn (array $line): string => $line[0], $second['report']),
        'msg2'         => $second['report'][2][1] ?? '',
        'slugs'        => array_values($second['slugs']),
        'count'        => count($second['events']),
        'activity'     => $second['activity'],
        'marked'       => substr_count($second['previewBody'], '>Already in the calendar</span>'),
        'tick'         => preg_match('/<input[^>]*name="importExisting"[^>]*>/', $second['previewBody'], $m) === 1 ? $m[0] : '',
    ];
};
$allPresent = [2 => 'Already in the calendar', 3 => 'Already in the calendar', 4 => 'Already in the calendar',
    5 => 'Already in the calendar', 6 => 'Already in the calendar'];
$allOk = [2 => 'OK', 3 => 'OK', 4 => 'OK', 5 => 'OK', 6 => 'OK'];
$v = $scnReimport(false);
check('V1 PREMISE: the first import of the file saved every row', $v['firstLines'], $allOk);
check('V2 the second upload\'s preview marks all five rows "Already in the calendar"', $v['marked'], 5);
check('V3 ...and offers ONE "Import these anyway" tick, NOT ticked',
    [$v['tick'] !== '', str_contains($v['tick'], 'checked')], [true, false]);
check('V4 THE WHOLE POINT: confirmed without the tick, every row is left out as already in the calendar',
    $v['lines'], $allPresent);
check('V5 ...so the calendar still holds exactly the five events, not ten', $v['count'], 5);
check('V6 ...each row says which event it matched, and to import a file holding ONLY that row with the tick '
    . '(the tick covers the whole file, so re-importing this one with it would repeat every row)',
    [
        str_contains($v['msg2'], 'Already in the calendar: event #'),
        str_contains($v['msg2'], 'import a file holding only this row and tick "Import these anyway"'),
        str_contains($v['msg2'], 'upload the file again and tick'),
    ],
    [true, true, false]);
check('V7 ...and the activity record counts them on their own, not as refused',
    $v['activity'], ['Bulk import: 0 events created, 5 already in the calendar, 0 repeated in the file, 0 rows not imported']);

$v = $scnReimport(true);
check('V8 WITH the tick, every row is imported again', $v['lines'], $allOk);
check('V9 ...the calendar now holds ten events', $v['count'], 10);
check('V10 ...and the second copies get the next free addresses',
    array_slice($v['slugs'], 5),
    [
        'p790-sunday-service-2031-03-02-3',
        'p790-sunday-service-2031-03-09-2',
        'p790-sunday-service-2031-03-16-3',
        'p790-sunday-service-2031-03-16-4',
        'p790-after-the-services-2031-03-17-2',
    ]);

$v = $scnReimport(true, 'on');
check('V18 only the exact value the box sends ("1") counts as ticked: "on" — what a box with no value sends — '
    . 'imports nothing', $v['lines'], $allPresent);

// 🧩 A file mixing rows already there with new ones, and the three edges of
//    what "already there" means: capitals, a DELETED event, and an event
//    COPIED IN from an outside calendar of the same organisation.
$mixed = $child . $sep . 'p790-mixed.csv';
file_put_contents(
    $mixed,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 Sunday Service\",2031-03-02 10:00:00,2031-03-02 11:00:00,published\n"
    . "\"P790 Sunday Service\",2031-03-23 10:00:00,2031-03-23 11:00:00,published\n"
    . "\"P790 SUNDAY SERVICE\",2031-03-09 10:00:00,2031-03-09 11:00:00,published\n"
    . "\"P790 deleted twin\",2031-09-01 10:00:00,2031-09-01 11:00:00,published\n"
    . "\"P790 copied in\",2031-09-02 10:00:00,2031-09-02 11:00:00,published\n"
);
/** Section S's file imported, two edge events added, then the mixed file. */
$scnMixed = static function () use ($runImport, $dup, $mixed, $db, $siteId): array {
    $runImport($dup);
    $db->query(
        'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `isDeleted`, `deletedAt`) '
        . "VALUES ({$siteId}, 'P790 deleted twin', 'p877-deleted-twin', '2031-09-01 10:00:00', 'Europe/London', 'published', 1, NOW())"
    );
    $db->query(
        'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`, `externalFeedID`) '
        . "VALUES ({$siteId}, 'P790 copied in', 'p877-copied-in', '2031-09-02 10:00:00', 'Europe/London', 'published', 987654)"
    );
    $r = $runImport($mixed, false);
    return array_map(static fn (array $line): string => $line[0], $r['report']);
};
$mx = $scnMixed();
check('V11 a mixed file imports only the new rows: the existing service is left out, a new date is imported',
    [$mx[2] ?? null, $mx[3] ?? null], ['Already in the calendar', 'OK']);
check('V12 ...the same name in DIFFERENT CAPITALS is a different event, and is imported', $mx[4] ?? null, 'OK');
check('V13 ...a DELETED event with the same name and start does not count — the manager deleted it', $mx[5] ?? null, 'OK');
check('V14 ...an event COPIED IN from an outside calendar of this organisation does count', $mx[6] ?? null, 'Already in the calendar');

// ⏱️ Asked at CONFIRM: an event added between the preview and the press.
$late = $child . $sep . 'p790-late.csv';
file_put_contents(
    $late,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 late arrival\",2031-08-01 10:00:00,2031-08-01 11:00:00,published\n"
);
/** The late file: previewed while the calendar is clear, the event added, then Confirm. */
$scnLate = static function () use ($runImport, $late, $db, $siteId): array {
    $r = $runImport($late, true, [], static function () use ($db, $siteId): void {
        $db->query(
            'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`) '
            . "VALUES ({$siteId}, 'P790 late arrival', 'p877-late-arrival', '2031-08-01 10:00:00', 'Europe/London', 'published')"
        );
    });
    return [
        'marked' => substr_count($r['previewBody'], '>Already in the calendar</span>'),
        'lines'  => array_map(static fn (array $line): string => $line[0], $r['report']),
        'count'  => count($r['events']),
    ];
};
$lt = $scnLate();
check('V15 PREMISE: the preview did NOT mark the row, because the event did not exist yet', $lt['marked'], 0);
check('V16 the event added between preview and Confirm is caught at Confirm, and the row is left out',
    $lt['lines'], [2 => 'Already in the calendar']);
check('V17 ...so the calendar holds that event once', $lt['count'], 1);

echo "X. A row the FILE repeats is marked in the preview and left out at Confirm, so the two pages agree (D-100)\n";
// 🔁 Found by the second independent check: such a row showed "Ready" in the
//    preview and was then skipped at Confirm as "Already in the calendar",
//    because its first copy had just been saved. Row 3 repeats row 2 exactly;
//    row 4 differs only in capitals and row 5 only in date, so neither is a
//    repeat.
$twice = $child . $sep . 'p790-twice.csv';
file_put_contents(
    $twice,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 twice\",2031-10-01 10:00:00,2031-10-01 11:00:00,published\n"
    . "\"P790 twice\",2031-10-01 10:00:00,2031-10-01 11:00:00,published\n"
    . "\"P790 TWICE\",2031-10-01 10:00:00,2031-10-01 11:00:00,published\n"
    . "\"P790 twice\",2031-10-02 10:00:00,2031-10-02 11:00:00,published\n"
);
/** The file with a repeated row, with or without the tick. */
$scnTwice = static function (bool $tick) use ($runImport, $twice): array {
    $r = $runImport($twice, true, $tick === true ? ['importExisting' => '1'] : []);
    return [
        'repeatMarks' => substr_count($r['previewBody'], '>Repeats an earlier row</span>'),
        'saysRow2'    => str_contains($r['previewBody'], 'row 2 of this file'),
        'ready'       => substr_count($r['previewBody'], '>Ready</span>'),
        'lines'       => array_map(static fn (array $line): string => $line[0], $r['report']),
        'msg3'        => $r['report'][3][1] ?? '',
        'count'       => count($r['events']),
        'activity'    => $r['activity'],
    ];
};
$x = $scnTwice(false);
check('X1 the preview marks the repeated row "Repeats an earlier row", naming row 2, and calls the other three Ready',
    [$x['repeatMarks'], $x['saysRow2'], $x['ready']], [1, true, 3]);
check('X2 Confirm, without the tick, leaves out exactly the row the preview marked — the two pages agree',
    $x['lines'], [2 => 'OK', 3 => 'Repeats an earlier row', 4 => 'OK', 5 => 'OK']);
check('X3 ...says which row it repeats, and how to import it anyway',
    [str_contains($x['msg3'], 'Repeats row 2 of this file'), str_contains($x['msg3'], 'import a file holding only this row')],
    [true, true]);
check('X4 ...three events are saved: different capitals and a different date are not repeats', $x['count'], 3);
check('X5 ...and the activity record counts the repeat on its own',
    $x['activity'], ['Bulk import: 3 events created, 0 already in the calendar, 1 repeated in the file, 0 rows not imported']);
$x = $scnTwice(true);
check('X6 WITH the tick, the repeated row is imported too', [$x['lines'], $x['count']], [[2 => 'OK', 3 => 'OK', 4 => 'OK', 5 => 'OK'], 4]);

echo "R. Another writer takes the address between asking and saving (#877)\n";
// 🏁 Stood in for by the trigger (see RACE_ONCE_NAME at the top): the database
//    refuses the row exactly as the unique key would if somebody else had just
//    taken the address. The import must ask again and save it; and where the
//    address is taken EVERY time it must stop after a bounded number of tries
//    and say so, while the rest of the file is still saved (D-099). Two rows
//    are given up on, so R9 can tell one error record per import from one per
//    row — the property section S used to show with refused duplicates, which
//    no longer happen.
$race = $child . $sep . 'p790-race.csv';
file_put_contents(
    $race,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 R before\",2031-06-01 10:00:00,2031-06-01 11:00:00,published\n"
    . '"' . RACE_ONCE_NAME . "\",2031-06-02 10:00:00,2031-06-02 11:00:00,published\n"
    . '"' . RACE_ALWAYS_NAME . "\",2031-06-03 10:00:00,2031-06-03 11:00:00,published\n"
    . '"' . RACE_ALWAYS_NAME . "\",2031-06-04 10:00:00,2031-06-04 11:00:00,published\n"
    . "\"P790 R after\",2031-06-05 10:00:00,2031-06-05 11:00:00,published\n"
);
/** Section R's file, reduced to what the checks and the plants compare. */
$scnRace = static function () use ($runImport, $race): array {
    $r = $runImport($race);
    return [
        'lines'   => array_map(static fn (array $line): string => $line[0], $r['report']),
        'msg4'    => $r['report'][4][1] ?? '',
        'events'  => array_values($r['events']),
        'slugs'   => array_values($r['slugs']),
        'tries'   => $r['tries'],
        'whole'   => $r['whole'],
        'records' => array_values(array_filter(
            $r['errors'],
            static fn (string $e): bool => str_contains($e, 'calendar file import')
        )),
    ];
};
$rr = $scnRace();
check('R1 the page finishes drawing', $rr['whole'], true);
check('R2 the row taken ONCE is saved on its second try; the rows taken EVERY time are not; the rest are saved',
    $rr['lines'], [2 => 'OK', 3 => 'OK', 4 => 'Skipped', 5 => 'Skipped', 6 => 'OK']);
check('R3 ...the row taken once was tried twice, and each row taken every time three times and no more',
    $rr['tries'], [RACE_ALWAYS_NAME => 6, RACE_ONCE_NAME => 2]);
check('R4 ...the database holds exactly the three rows the list calls imported',
    $rr['events'], ['P790 R before', RACE_ONCE_NAME, 'P790 R after']);
check('R5 ...and the row saved on its second try has its own name-and-date address',
    $rr['slugs'][1] ?? null, 'p790-race-once-2031-06-02');
check('R6 a row given up on says, in plain words, that every address tried was taken at the moment of saving',
    str_contains($rr['msg4'], 'every web address this page tried for this event'), true);
check('R7 ...and that nothing from it was saved',
    str_contains($rr['msg4'], 'Nothing from this row was saved'), true);
check('R8 ...and it no longer tells anybody to rename the event, which would not help',
    str_contains($rr['msg4'], 'different name'), false);
check('R9 TWO refused rows make ONE error record for the import, not one per row', count($rr['records']), 1);
check('R10 ...which counts both rows', str_contains($rr['records'][0] ?? '', '2 row(s) refused'), true);
check('R11 ...under a title that speaks of rows, and at "Notification" (a taken address is the row\'s problem)',
    str_starts_with($rr['records'][0] ?? '', 'Notification | Rows of a calendar file import were refused by the database |'), true);

// 🏁 A REAL SECOND WRITER, NOT A STAND-IN. The trigger above refuses the row
//    whatever address it carries, so it cannot tell a page that asks "is it
//    free" again before each try from one that asks ONCE and then re-sends
//    the same, already-refused address three times — both pass R1-R11
//    (measured by the independent check: R stayed 75/75 with the question
//    moved above the tries). Here somebody really holds the address:
//
//      1. this test, on its OWN connection, inserts an event at the address
//         the file's row will want, and leaves the transaction OPEN;
//      2. the real page is sent Confirm in the background. Its "is it free"
//         question cannot see an uncommitted row, so it calls the address
//         free, and its INSERT then WAITS on the unique key;
//      3. the test confirms that wait really is happening (otherwise every
//         check below would be about some other order of events), and only
//         then commits;
//      4. the waiting INSERT is refused (1062), and the page must ask again —
//         now seeing the committed row — and save the event at `-2`.
//
//    One page request and one held transaction are enough: what matters is
//    the ORDER of two things (another row arriving between asking and
//    saving), not how many people are importing.
$raceReal = $child . $sep . 'p790-race-real.csv';
file_put_contents(
    $raceReal,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 real race\",2031-07-01 10:00:00,2031-07-01 11:00:00,published\n"
);
/** Section R's real race, reduced to what the checks and the plant compare. */
$scnRealRace = static function () use (
    $http, $tokenOn, $signIn, $raceReal, $db, $reportOf, $port, $jar, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $siteId
): array {
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` LIKE 'P790 %' OR `eventName` = 'P877 race holder'");
    $signIn();
    $page    = $http('GET', '/calendar/manage/import');
    $preview = $http('POST', '/calendar/manage/import', ['csrf_token' => $tokenOn($page['body']), 'action' => 'preview'], ['csv_file' => $raceReal]);
    $token   = $tokenOn($preview['body']) !== '' ? $tokenOn($preview['body']) : $tokenOn($page['body']);

    // 1. Hold the address in an open transaction, on a connection of our own.
    $holder = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $holder->begin_transaction();
    $holder->query(
        'INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`) '
        . "VALUES ({$siteId}, 'P877 race holder', 'p790-real-race-2031-07-01', '2031-07-01 10:00:00', "
        . "'Europe/London', 'published')"
    );

    // 2. Confirm, in the background.
    $mh = curl_multi_init();
    $h  = curl_init('http://127.0.0.1:' . $port . '/calendar/manage/import');
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['csrf_token' => $token, 'action' => 'confirm']),
    ]);
    curl_multi_add_handle($mh, $h);

    // 3. Wait until the page's INSERT is really waiting on the key — asked of
    //    the database, never assumed from a sleep (#735: a sleep is a hope).
    $running = 1;
    $waiting = false;
    $until   = microtime(true) + 15;
    while (microtime(true) < $until) {
        curl_multi_exec($mh, $running);
        if ($running === 0) {
            break;
        }
        $n = (int) ($db->query(
            "SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE `trx_state` = 'LOCK WAIT' "
            . "AND `trx_query` LIKE '%INSERT INTO `tblEvents`%'"
        )->fetch_assoc()['n'] ?? 0);
        if ($n > 0) {
            $waiting = true;
            break;
        }
        curl_multi_select($mh, 0.1);
    }
    $stillWaiting = $running > 0;
    $holder->commit();
    $holder->close();

    // 4. Let the page finish.
    while ($running > 0) {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.2);
        }
    }
    $raw  = (string) curl_multi_getcontent($h);
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    curl_multi_remove_handle($mh, $h);
    curl_multi_close($mh);
    $report = $reportOf(substr($raw, $size));

    $rows = $db->query(
        "SELECT `eventName`, `eventSlug` FROM `tblEvents` WHERE `eventName` IN ('P790 real race', 'P877 race holder') "
        . 'ORDER BY `eventID`'
    )->fetch_all(MYSQLI_ASSOC);
    $db->query("DELETE FROM `tblEvents` WHERE `eventName` = 'P877 race holder'");

    return [
        'waiting'      => $waiting,
        'stillWaiting' => $stillWaiting,
        'lines'        => array_map(static fn (array $line): string => $line[0], $report),
        'rows'         => array_map(static fn (array $row): string => $row['eventName'] . ' = ' . $row['eventSlug'], $rows),
    ];
};
$real = $scnRealRace();
check('R12 PREMISE: the page\'s INSERT was really waiting on the address this test held, before the test let go',
    [$real['waiting'], $real['stillWaiting']], [true, true]);
check('R13 THE REAL RACE: once the other writer\'s row is committed, the page asks again and imports the row',
    $real['lines'], [2 => 'OK']);
check('R14 ...at the next free address, `-2`, beside the other writer\'s event at the plain one',
    $real['rows'], ['P877 race holder = p790-real-race-2031-07-01', 'P790 real race = p790-real-race-2031-07-01-2']);

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

echo "U. A refusal about the DATABASE, not the row, is recorded as an Error\n";
$csv1114 = $child . $sep . 'p790-1114.csv';
file_put_contents(
    $csv1114,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 U before\",2031-05-01 10:00:00,2031-05-01 11:00:00,published\n"
    . '"' . REFUSED_1114_NAME . "\",2031-05-02 10:00:00,2031-05-02 11:00:00,published\n"
    . "\"P790 U after\",2031-05-03 10:00:00,2031-05-03 11:00:00,published\n"
);
/** Section U's file, reduced to what the checks and the plants compare. */
$scnDatabaseRefusal = static function () use ($runImport, $csv1114): array {
    $u = $runImport($csv1114);
    $mine = array_values(array_filter($u['errors'], static fn (string $e): bool => str_contains($e, TRIGGER_1114_TEXT)));
    return [
        'lines'   => array_map(static fn (array $line): string => $line[0], $u['report']),
        'msg3'    => $u['report'][3][1] ?? '',
        'events'  => array_values($u['events']),
        'records' => $mine,
    ];
};
$u = $scnDatabaseRefusal();
check('U1 the row refused with 1114 is "Skipped", and the rows either side are imported',
    $u['lines'], [2 => 'OK', 3 => 'Skipped', 4 => 'OK']);
check('U2 ...and the page says it is a problem with the database, not with the row',
    str_contains($u['msg3'], 'This is a problem with the database, not with the row'), true);
check('U3 ...the database really holds the two rows either side', $u['events'], ['P790 U before', 'P790 U after']);
check('U4 exactly one error record carries the database\'s words', count($u['records']), 1);
check('U5 ...at "Error", the level an installation can choose to be e-mailed about, so the person who CAN fix '
    . 'a full table hears of it',
    str_starts_with($u['records'][0] ?? '', 'Error | '), true);
check('U6 ...with a singular title, because one row was refused',
    str_contains($u['records'][0] ?? '', '| The database refused a row of a calendar file import for a reason of its own |'), true);

// 🔇 Read BEFORE the plant, which throws on purpose and fills the log.
$phpLog  = (string) @file_get_contents($child . $sep . 'php-errors.log');
$ownLines = array_values(array_filter(
    explode("\n", $phpLog),
    static fn (string $line): bool => str_contains($line, 'manage' . DIRECTORY_SEPARATOR . 'import.php')
        || str_contains($line, '_core' . DIRECTORY_SEPARATOR . 'Events.php')
));
check('S5 the import page, and the class that makes its web addresses, wrote no PHP error, warning or notice '
    . 'in sections A to U', $ownLines, []);

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

// 🧾 The third and fourth plants collapse the row-or-database split, once in
//    each direction, so neither half of it is guarded by nothing.
$splitTest = 'if (in_array($code, [1048, 1062, 1264, 1265, 1292, 1366, 1406, 1452, 1644], true) === true) {';
if (substr_count($before, $splitTest) !== 1) {
    refuse('section P could not find exactly one copy of the row-or-database test in the copied page');
}
file_put_contents($pagePath, str_replace($splitTest, 'if (true) {', $before));
clearstatcache(true, $pagePath);
$startServer();
$waitForServer();
$u = $scnDatabaseRefusal();
check('P10 with every refusal counted as the row\'s problem, the full table is recorded as a mere Notification',
    str_starts_with($u['records'][0] ?? '', 'Notification | '), true);
check('P11 ...so section U\'s U5 would have gone red', str_starts_with($u['records'][0] ?? '', 'Error | '), false);

file_put_contents($pagePath, str_replace($splitTest, 'if (false) {', $before));
clearstatcache(true, $pagePath);
$startServer();
$waitForServer();
$g = $runImport();
$gRecorded = array_values(array_filter($g['errors'], static fn (string $e): bool => str_contains($e, TRIGGER_TEXT)));
check('P12 with no refusal counted as the row\'s problem, the trigger\'s refusal of one row is recorded as an Error',
    str_starts_with($gRecorded[0] ?? '', 'Error | '), true);
check('P13 ...so section G\'s G3 would have gone red', str_starts_with($gRecorded[0] ?? '', 'Notification | '), false);

// 🔤 #877's plants. Each puts ONE part of the web-address fix back the way it
//    was, or the way somebody might "simplify" it, and the section that guards
//    that part must go red. All four go into the CHILD copy; each must apply,
//    or the section refuses rather than passing on a plant that never went in.
$eventsPath   = $child . $sep . 'web' . $sep . '_core' . $sep . 'Events.php';
$eventsBefore = (string) file_get_contents($eventsPath);
/** Put one planted change into a child file, refusing if it does not apply exactly once. */
$plant = static function (string $path, string $original, string $find, string $replace, string $what) use ($startServer, $waitForServer): void {
    if (substr_count($original, $find) !== 1) {
        refuse('section P could not find exactly one copy of ' . $what . ' in the copied file');
    }
    file_put_contents($path, str_replace($find, $replace, $original));
    clearstatcache(true, $path);
    $startServer();
    $waitForServer();
};
$restoreBoth = static function () use ($pagePath, $before, $eventsPath, $eventsBefore): void {
    file_put_contents($pagePath, $before);
    file_put_contents($eventsPath, $eventsBefore);
    clearstatcache(true, $pagePath);
    clearstatcache(true, $eventsPath);
};

// (a) THE OLD ADDRESS: the name alone, with nobody asked whether it is free.
$plant(
    $pagePath,
    $before,
    '$slug = Events::freeSlug($mysqli, $siteId, $slugStem);',
    "\$slug = substr(strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', \$name), '-')), 0, 100);",
    'the free-address call'
);
$sp = $scnSameName();
check('P14 with the name-only address put back, the same name on later dates is refused again (#877 restored)',
    $sp['lines'], [2 => 'OK', 3 => 'Skipped', 4 => 'Skipped', 5 => 'Skipped', 6 => 'OK']);
check('P15 ...so section S\'s S2 would have gone red', $sp['lines'] === [2 => 'OK', 3 => 'OK', 4 => 'OK', 5 => 'OK', 6 => 'OK'], false);
$restoreBoth();

// (b) A PROBE THAT SKIPS DELETED EVENTS — the plausible "tidy-up". It calls the
//     deleted event's address free, and the database then refuses it.
$plant(
    $eventsPath,
    $eventsBefore,
    'WHERE `siteID` = ? AND `eventSlug` = ? LIMIT 1',
    'WHERE `siteID` = ? AND `eventSlug` = ? AND `isDeleted` = 0 LIMIT 1',
    'the free-address question'
);
$sp = $scnSameName();
check('P16 with deleted events left out of the question, the row whose address a deleted event holds is refused',
    $sp['lines'][2] ?? null, 'Skipped');
check('P17 ...and only that row: the other dates still import', $sp['lines'], [2 => 'Skipped', 3 => 'OK', 4 => 'OK', 5 => 'OK', 6 => 'OK']);
$restoreBoth();

// (c) NO DATE IN THE ADDRESS: every row still imports (the probe numbers them),
//     but the addresses are no longer the name and the date, so S4 must see it.
$plant(
    $eventsPath,
    $eventsBefore,
    "return \$date !== '' ? \$name . '-' . \$date : \$name;",
    'return $name;',
    'the name-and-date address'
);
$sp = $scnSameName();
check('P18 with the date left out of the address, the rows are numbered instead and S4 would have gone red',
    $sp['slugs'],
    [
        'p790-sunday-service',
        'p790-sunday-service-2',
        'p790-sunday-service-3',
        'p790-sunday-service-4',
        'p790-after-the-services',
    ]);
$restoreBoth();

// (d) NO SECOND TRY: an address taken between asking and saving refuses the row.
$plant(
    $pagePath,
    $before,
    '$slugTries = 3;',
    '$slugTries = 1;',
    'the number of tries'
);
$rp = $scnRace();
check('P19 with only one try, the row whose address was taken once is refused instead of saved',
    $rp['lines'][3] ?? null, 'Skipped');
check('P20 ...having been tried once, so section R\'s R2 and R3 would have gone red',
    $rp['tries'][RACE_ONCE_NAME] ?? null, 1);
$restoreBoth();

// (e) "IS IT FREE" ASKED ONCE, BEFORE THE TRIES: every try then re-sends the
//     address the database has just refused. The trigger in R1-R11 cannot see
//     this (it refuses whatever the address); only the real race can.
$plant(
    $pagePath,
    $before,
    "for (\$slugTry = 1; ; \$slugTry++) {\n"
    . "                                \$slug = Events::freeSlug(\$mysqli, \$siteId, \$slugStem);",
    "\$slug = Events::freeSlug(\$mysqli, \$siteId, \$slugStem);\n"
    . "                            for (\$slugTry = 1; ; \$slugTry++) {",
    'the free-address question inside the tries'
);
$rp = $scnRace();
check('P22 with "is it free" asked only once, the trigger\'s stand-in race is still green — which is why the real one exists',
    $rp['lines'], [2 => 'OK', 3 => 'OK', 4 => 'Skipped', 5 => 'Skipped', 6 => 'OK']);
$realPlanted = $scnRealRace();
check('P23 ...the real race still reaches its premise (the page\'s INSERT waits on the held address)',
    [$realPlanted['waiting'], $realPlanted['stillWaiting']], [true, true]);
check('P24 ...and the row is REFUSED, because every try repeats the address already taken, so R13 would have gone red',
    $realPlanted['lines'], [2 => 'Skipped']);
check('P25 ...and only the other writer\'s event is in the database',
    $realPlanted['rows'], ['P877 race holder = p790-real-race-2031-07-01']);
$restoreBoth();

// 📌 D-100's plants. Each takes one part of "already in the calendar" away.
// (f) NO QUESTION AT CONFIRM: the re-upload doubles every event again.
// (The find text carries the next line too: `$importExisting === false` also
// guards the in-file repeat above it, so the condition alone is not unique.)
$plant(
    $pagePath,
    $before,
    "if (\$importExisting === false) {\n                                \$there = Events::alreadyInCalendar(",
    "if (false) {\n                                \$there = Events::alreadyInCalendar(",
    'the already-in-the-calendar question'
);
$v = $scnReimport(false);
check('P26 with the question removed, a re-upload imports every row a second time, so V4 would have gone red',
    [$v['lines'], $v['count']], [$allOk, 10]);
$restoreBoth();

// (g) THE TICK IGNORED: "Import these anyway" does nothing.
$plant(
    $pagePath,
    $before,
    "\$importExisting = ((\$_POST['importExisting'] ?? '') === '1');",
    '$importExisting = false;',
    'the tick'
);
$v = $scnReimport(true);
check('P27 with the tick ignored, ticking it imports nothing, so V8 would have gone red', $v['lines'], $allPresent);
$restoreBoth();

// (h) CONFIRM TRUSTS THE PREVIEW'S MARK instead of asking again.
$plant(
    $pagePath,
    $before,
    '$there = Events::alreadyInCalendar($mysqli, $siteId, $name, $startSql);',
    "\$there = \$r['alreadyThere'] ?? null;",
    'the confirm-time question'
);
$lt = $scnLate();
check('P28 with Confirm trusting the preview, the event added in between is imported a second time, so V16 would have gone red',
    [$lt['lines'], $lt['count']], [[2 => 'OK'], 2]);
$v = $scnReimport(false);
check('P29 ...while a plain re-upload is still caught by the preview\'s mark — which is why V16 exists',
    $v['lines'], $allPresent);
$restoreBoth();

// (i) A DELETED EVENT COUNTS.
$plant($eventsPath, $eventsBefore, 'AND `isDeleted` = 0 AND `startDateTime`', 'AND `startDateTime`', 'the deleted-event rule');
$mx = $scnMixed();
check('P30 with deleted events counted, the deleted twin is left out, so V13 would have gone red',
    $mx[5] ?? null, 'Already in the calendar');
$restoreBoth();

// (j) COPIED-IN EVENTS LEFT OUT of the question.
$plant(
    $eventsPath,
    $eventsBefore,
    'AND `isDeleted` = 0 AND `startDateTime`',
    'AND `isDeleted` = 0 AND `externalFeedID` IS NULL AND `startDateTime`',
    'the place to leave copied-in events out'
);
$mx = $scnMixed();
check('P31 with copied-in events left out, the row matching the copied-in event is imported beside it, so V14 would have gone red',
    $mx[6] ?? null, 'OK');
$restoreBoth();

// (k) CAPITALS IGNORED.
$plant(
    $eventsPath,
    $eventsBefore,
    'CAST(`eventName` AS BINARY) = CAST(? AS BINARY)',
    '`eventName` = ?',
    'the letter-for-letter name test'
);
$mx = $scnMixed();
check('P32 with capitals ignored, "P790 SUNDAY SERVICE" is left out, so V12 would have gone red',
    $mx[4] ?? null, 'Already in the calendar');
$restoreBoth();

// (l) ANY VALUE COUNTS AS TICKED.
$plant(
    $pagePath,
    $before,
    "\$importExisting = ((\$_POST['importExisting'] ?? '') === '1');",
    "\$importExisting = ((\$_POST['importExisting'] ?? '') !== '');",
    'the exact tick value'
);
$v = $scnReimport(true, 'on');
check('P33 with any value counted as ticked, "on" imports every row again, so V18 would have gone red', $v['lines'], $allOk);
$restoreBoth();

// (m) CONFIRM FORGETS THE FILE'S OWN REPEATS: the repeated row falls through
//     to the calendar question and is called "Already in the calendar" —
//     the disagreement X2 exists to catch.
$plant($pagePath, $before, '$repeatOf  = $firstRowSeen[$repeatKey] ?? 0;', '$repeatOf  = 0;', 'the in-file repeat at Confirm');
$x = $scnTwice(false);
check('P34 with Confirm forgetting the file\'s repeats, the repeated row is described as already in the calendar, so X2 would have gone red',
    $x['lines'][3] ?? null, 'Already in the calendar');
$restoreBoth();

// (n) THE PREVIEW FORGETS THE FILE'S OWN REPEATS: it calls the row Ready again.
$plant(
    $pagePath,
    $before,
    "\$preview[\$i]['repeatsRow'] = \$firstRowFor[\$repeatKey];",
    "\$preview[\$i]['repeatsRow'] = 0;",
    'the in-file repeat in the preview'
);
$x = $scnTwice(false);
check('P35 with the preview forgetting the file\'s repeats, the repeated row shows Ready while Confirm skips it, so X1 would have gone red',
    [$x['repeatMarks'], $x['ready'], $x['lines'][3] ?? null], [0, 4, 'Repeats an earlier row']);
$restoreBoth();

echo "L. A connection lost BEFORE the row is sent is not reported as \"may have been saved\" (#877)\n";
// 🔌 Two questions now go to the database before a row's INSERT (is it
//    already there, is the address free). A connection lost during either
//    cannot have saved anything. Nothing in one test process can drop the
//    page's connection at that exact moment, so the CHILD copy of
//    `Events::freeSlug()` is given one injected line that throws the error
//    code of a lost connection (2006) for one row's name — the import cannot
//    tell it from the real thing, because it judges by the code. The row after
//    it is then "never sent", as for any lost connection.
$lostFile = $child . $sep . 'p790-lost.csv';
file_put_contents(
    $lostFile,
    "eventName,startDateTime,endDateTime,status\n"
    . "\"P790 L before\",2031-11-01 10:00:00,2031-11-01 11:00:00,published\n"
    . "\"P790 lost before send\",2031-11-02 10:00:00,2031-11-02 11:00:00,published\n"
    . "\"P790 L after\",2031-11-03 10:00:00,2031-11-03 11:00:00,published\n"
);
$inject = "    public static function freeSlug(mysqli \$db, int \$siteId, string \$stem): string\n    {\n";
$injected = $inject
    . "        if (str_starts_with(\$stem, 'p790-lost-before-send') === true) {\n"
    . "            throw new \\mysqli_sql_exception('injected by the self-test: MySQL server has gone away', 2006);\n"
    . "        }\n";
/** The lost-connection file, run against a page whose Events.php carries the injection. */
$scnLost = static function () use ($runImport, $lostFile): array {
    $r = $runImport($lostFile);
    return [
        'lines'    => array_map(static fn (array $line): string => $line[0], $r['report']),
        'msg3'     => $r['report'][3][1] ?? '',
        'events'   => array_values($r['events']),
        'activity' => $r['activity'],
    ];
};
$plant($eventsPath, $eventsBefore, $inject, $injected, 'the start of freeSlug()');
$lo = $scnLost();
check('L1 the row whose connection was lost before it was sent is "Skipped", not "Not known", and the row after is never sent',
    $lo['lines'], [2 => 'OK', 3 => 'Skipped', 4 => 'Skipped']);
check('L2 ...it says the connection was lost BEFORE the row was sent, so nothing was saved',
    [str_contains($lo['msg3'], 'lost before this row was sent'), str_contains($lo['msg3'], 'cannot tell whether it was saved')],
    [true, false]);
check('L3 ...only the row before it is in the database', $lo['events'], ['P790 L before']);
check('L4 ...and the activity record counts it as not imported, with no row "whose outcome is not known"',
    $lo['activity'], ['Bulk import: 1 events created, 0 already in the calendar, 0 repeated in the file, 2 rows not imported']);

// (o) EVERY LOST CONNECTION CALLED "NOT KNOWN" again — the old wording.
$plant($pagePath, $before, '$unknown   = $insertSent;', '$unknown   = true;', 'the sent-or-not test');
$lo = $scnLost();
check('P36 with every lost connection called "not known", the row that was never sent says it may have been saved, '
    . 'so L1 would have gone red', $lo['lines'][3] ?? null, 'Not known');
$restoreBoth();

$startServer();
$waitForServer();
$sameAgain = $scnSameName();
check('P21 with every #877 plant taken out, section S\'s file imports every row again',
    $sameAgain['lines'], [2 => 'OK', 3 => 'OK', 4 => 'OK', 5 => 'OK', 6 => 'OK']);
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
echo "worker, so two Confirm requests at once are not driven (section R drives the\n";
echo "one ordering that matters to the web address with a transaction held open).\n";
exit($failed === 0 ? 0 : 1);
