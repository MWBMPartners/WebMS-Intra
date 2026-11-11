<?php
// Path: tools/activity-log-retention-selftest.php
/**
 * -----------------------------------------------------------------------------
 * "Never delete" for the activity log, over real HTTP 📜♾️ (#932, D-117)
 * -----------------------------------------------------------------------------
 * It drives the REAL page — Admin, Maintenance, Retention — and the REAL
 * scheduled clear-out at `/cron/retention-sweep`, with a real sign-in, and reads
 * every result back out of the database rather than believing a message.
 * `tools/retention-caps-selftest.php` section F proves the readers and the save
 * class on their own; this proves the page and its handler use them, which no
 * reading of the source can.
 *
 * WHAT IT COVERS
 *   A  the page draws, and with the installation at a number of days the
 *      organisation's form does NOT offer "never" (a choice whose own Save
 *      always refuses is #675's fault)
 *   B  the installation's "Never delete" is stored as 0 — never as 3650 — the
 *      page then says so, and the organisation's form offers "never"
 *   C  an organisation's never under an installation at never is stored
 *   D  THE REAL CLEAR-OUT under never: 4000-day-old rows survive for an
 *      organisation that chose never, one that follows the installation's
 *      never, and rows owned by nobody — while an organisation that chose 7
 *      days has its own old row deleted
 *   E  D-119: lowering the installation below an organisation's own figure
 *      ("never" counting as the longest) is REFUSED, naming the organisation,
 *      and recorded with a count; once that organisation's OWN administrator
 *      (D-118) lowers its figure, the installation's lowering is accepted; a
 *      lowering nobody blocks is accepted
 *   F  THE REFUSAL, met by the organisation's own administrator: under an
 *      installation of 30 days an organisation's never is refused with the
 *      reason, nothing is stored, and the refusal is recorded; 0 typed as a
 *      NUMBER is refused too (never is its own choice); 31 is refused, never
 *      clamped; 20 is stored; "follow" removes the row
 *   G  the installation's 3651 days is refused, not clamped
 *   H  an untouched Save over a stored never the installation does not allow
 *      (SQL-only state) changes nothing (#687), and the page says the reader
 *      holds it to the installation's figure
 *   I  with ONE organisation, as `orgadmin` (whose `tblUsers.isAdmin` makes
 *      them an administrator everywhere): a member is refused the page, both
 *      forms and the sweep; `orgadmin` gets a page with only the organisation's
 *      form, and is refused (403) the installation's form and the sweep with
 *      no action. This does NOT show "only their own organisation" — M does.
 *   M  D-118 with SEVERAL organisations (session mode) and a TRUE organisation
 *      administrator (`isSiteAdmin` on organisation one, a plain member of
 *      two): a posted `siteID`/`site_id`/`siteId` naming organisation two
 *      changes only organisation one; on organisation one the installation's
 *      form, the sweep with no action, `action=sweep`, an unknown action and
 *      `action[]` are each refused (403); a post with no token changes
 *      nothing; switched to organisation two, the page and the organisation
 *      form are refused (403) and no row is written; a global administrator
 *      on organisation two saves organisation two's figure
 *   J  an action the page does not know runs NOTHING — not the sweep — while
 *      a post with no action is still the sweep, as it always was
 *   K  the ERROR log is untouched: its stored 0 still reads as a year on the
 *      page and the real clear-out still deletes a 400-day-old error row
 *   L  the form token: both activity-log forms posted with no token and with a
 *      wrong one are refused and store nothing; and each form SUBMITTED
 *      EXACTLY AS DRAWN changes nothing — above all an organisation with no row
 *      of its own still has none (#674)
 *   P  the plants: each guard taken out of a COPY of the portal, the scenario
 *      that should catch it run again, and it must go red. Count them from the
 *      `$plant(` calls, never from a comment.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It runs ONE engine at a time, whichever the caller points it at.
 *   - The server runs ONE worker, so two saves never overlap here. D-119's
 *     race — a lowering made while an organisation's never is being saved — is
 *     driven with a second PROCESS in `retention-caps-selftest.php` section F
 *     instead, which is where it can fail.
 *   - The general settings editor's refusal of `audit.retentionDays` is not
 *     driven here: `tools/settings-locks-selftest.php` finds every lock
 *     method by name and drives all three of the editor's paths for each.
 *   - It drives the page and the job; it does not prove the customer's
 *     scheduler really calls the job every night.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_alret SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=9932] php tools/activity-log-retention-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES rather than
 *         skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 * Exit:   0 only when every check ran and passed AND the number of checks is
 *         the number expected; 1 otherwise; 2 when it refused to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/932
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** How many checks a full run makes. A different number means a block was skipped or ran twice. */
const AL_EXPECTED_CHECKS = 73;

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
    'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()'
)->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// ── The two settings section M changes, put back on EVERY way out ───────────
// Section M switches several organisations on. Check round 3 found that it put
// the two rows back only on the normal path, so a failure inside M left
// `multisite.enabled = true` in the database — and the NEXT run then read that
// leftover as "the original" and put it back, so the database stayed poisoned.
//
// So: the original rows are captured ONCE, WHOLE (value, default, sensitivity,
// or "no row"), into a file named for this database, BEFORE anything is
// changed. If that file is already there, a previous run did not finish, and
// ITS capture is the original — the rows in the database now may be that
// run's leftovers, and are never trusted. They are put back by a shutdown
// function, which runs on a normal exit, on `exit()` from a refusal and on an
// uncaught error, and only then is the file removed. Only `kill -9` skips the
// shutdown function, and the file covers that: the next run restores from it.
const AL_SITE_KEYS = ['multisite.enabled', 'multisite.detectionMode'];
$alMultiFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alret-original-multisite-' . $dbName . '.json';
$alRowsNow = static function () use ($db): array {
    $out = [];
    foreach (AL_SITE_KEYS as $key) {
        $stmt = $db->prepare('SELECT `settingValue`, `defaultValue`, `isSensitive` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $out[$key] = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    return $out;
};
$alOriginal = null;
if (is_file($alMultiFile) === true) {
    $alOriginal = json_decode((string) file_get_contents($alMultiFile), true);
    echo "  ! an earlier run did not finish; its record of the original multi-organisation settings is used\n";
}
if (is_array($alOriginal) === false) {
    $alOriginal = $alRowsNow();
    file_put_contents($alMultiFile, json_encode($alOriginal));
}
/** Put the two rows back EXACTLY as captured: the same value, default and sensitivity, or no row. */
$alRestoreMulti = static function () use ($db, $alOriginal, $alMultiFile, $alRowsNow): void {
    // Already as found: touch nothing, so the rows keep their own numbers.
    if ($alRowsNow() === $alOriginal) {
        @unlink($alMultiFile);
        return;
    }
    // An existing row is UPDATED in place, so it keeps its own number; a row
    // that was not there originally is deleted; a missing one is re-inserted.
    foreach (AL_SITE_KEYS as $key) {
        $row = $alOriginal[$key] ?? null;
        if (is_array($row) === false) {
            $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
            $del->bind_param('s', $key);
            $del->execute();
            $del->close();
            continue;
        }
        $value     = $row['settingValue'];
        $default   = $row['defaultValue'];
        $sensitive = (int) $row['isSensitive'];
        $up = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ?, `defaultValue` = ?, `isSensitive` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
        $up->bind_param('ssis', $value, $default, $sensitive, $key);
        $up->execute();
        $up->close();
        $have = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
        $have->bind_param('s', $key);
        $have->execute();
        $exists = (int) ($have->get_result()->fetch_assoc()['n'] ?? 0) > 0;
        $have->close();
        if ($exists === false) {
            $ins = $db->prepare('INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, ?, ?)');
            $ins->bind_param('sssi', $key, $value, $default, $sensitive);
            $ins->execute();
            $ins->close();
        }
    }
    @unlink($alMultiFile);
};
// A run that did not finish: put its original back straight away, so every
// section before M runs in the world it expects.
$alRestoreMulti();
file_put_contents($alMultiFile, json_encode($alOriginal));
register_shutdown_function(static function () use ($alRestoreMulti): void {
    try {
        $alRestoreMulti();
    } catch (Throwable $e) {
        echo "  ! could not put the multi-organisation settings back; the file under the system temp folder still holds them.\n";
    }
});

// ── A copy of web/, so a planted fault never touches the repository ─────────
$child = sys_get_temp_dir() . $sep . 'alret-http-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_dir($state['child']) === true) {
        // A recursive delete of a folder THIS run made, under the system temp
        // folder, whose name holds random bytes.
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
    "<?php\n// Written by tools/activity-log-retention-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));

// The router imitates web/public_html/.htaccess, as the other page tests do.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/activity-log-retention-selftest.php for ONE run. Test only.
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

// SELFTEST_WEB_PORT when the runner or a workflow chooses one (so two tests
// running side by side never share a port); otherwise one picked at random.
$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9940 + random_int(0, 30);
$jar  = $child . $sep . 'cookies.txt';

$startServer = static function () use (&$state, $child, $sep, $port): void {
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
            '-d', 'session.save_path=' . $child . $sep . 'sessions',
            '-d', 'error_log=' . $child . $sep . 'php-errors.log',
            '-d', 'log_errors=1', '-d', 'display_errors=0',
            '-d', 'max_execution_time=120',
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-S', '127.0.0.1:' . $port,
            '-t', $child . $sep . 'web' . $sep . 'public_html',
            $child . $sep . 'router.php',
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

/** One request: status, Location and body. Redirects are NOT followed: they are facts checked here. */
$http = static function (string $method, string $path, array|string $post = []) use ($port, $jar): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($post) === true ? http_build_query($post) : $post);
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

$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
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

// ── The people ──────────────────────────────────────────────────────────────
$siteOne = (int) ($db->query('SELECT MIN(`siteID`) AS `s` FROM `tblSites`')->fetch_assoc()['s'] ?? 0);
if ($siteOne <= 0) {
    refuse('the database has no organisation');
}

$siteOneName = (string) ($db->query("SELECT `siteName` FROM `tblSites` WHERE `siteID` = {$siteOne}")->fetch_assoc()['siteName'] ?? '');
if ($siteOneName === '') {
    refuse('the first organisation has no name, so a refusal that names it cannot be checked');
}

/** Remove a person and everything naming them, children first (the i18n test's lesson). */
$removeMember = static function (int $who) use ($db): void {
    $rs = $db->query(
        "SELECT `TABLE_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() "
        . "AND `REFERENCED_TABLE_NAME` = 'tblUsers' AND `COLUMN_NAME` = 'userID'"
    );
    while ($row = $rs->fetch_assoc()) {
        try {
            $db->query('DELETE FROM `' . $row['TABLE_NAME'] . "` WHERE `userID` = {$who}");
        } catch (Throwable $e) {
            // A table of a different shape; whether the person goes is what matters.
        }
    }
    try {
        $db->query("DELETE FROM `tblUsers` WHERE `userID` = {$who}");
    } catch (Throwable $e) {
        echo "  ! could not remove test person {$who}; remove it by hand.\n";
    }
};

$people = [];
// 'siteadmin' is a TRUE organisation administrator: no `tblUsers.isAdmin` at
// all (that flag makes 'orgadmin' an administrator everywhere), and
// `isSiteAdmin` on organisation one only — set in section M, which is also where
// several organisations are switched on. Check round 2 found that without it
// "only their OWN organisation" was driven by nothing.
foreach (['orgadmin' => [1, 0], 'globaladmin' => [1, 1], 'member' => [0, 0], 'siteadmin' => [0, 0]] as $role => [$isAdmin, $isRoot]) {
    $email = "alret.{$role}@selftest.invalid";
    $old   = $db->prepare('SELECT `userID` FROM `tblUsers` WHERE `emailAddress` = ?');
    $old->bind_param('s', $email);
    $old->execute();
    foreach ($old->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $removeMember((int) $row['userID']);
    }
    $old->close();
    $ins = $db->prepare(
        'INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, 1, ?, ?, NOW())'
    );
    $name = 'Selftest ' . $role;
    $ins->bind_param('ssii', $name, $email, $isAdmin, $isRoot);
    $ins->execute();
    $id = (int) $db->insert_id;
    $ins->close();
    $db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES ({$siteOne}, {$id}, 1)");
    $password = 'Sel' . bin2hex(random_bytes(8)) . '!9x';
    $hash     = password_hash($password, PASSWORD_DEFAULT);
    $login    = 'selftest-alret-' . $role;
    $db->query("DELETE FROM `tblLocalAccounts` WHERE `username` = '{$login}'");
    $acc = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $acc->bind_param('iss', $id, $login, $hash);
    $acc->execute();
    $acc->close();
    $people[$role] = ['id' => $id, 'login' => $login, 'password' => $password];
}

// A second organisation, so "an organisation that follows the installation"
// and "one that chose for itself" can be told apart on real rows.
$db->query("DELETE FROM `tblSettings` WHERE `siteID` IN (SELECT `siteID` FROM `tblSites` WHERE `siteKey` = 'selftest-alret-two')");
$db->query("DELETE FROM `tblSites` WHERE `siteKey` = 'selftest-alret-two'");
$db->query("INSERT INTO `tblSites` (`siteKey`, `siteName`, `timezone`, `isActive`) VALUES ('selftest-alret-two', 'Selftest Alret Two', 'UTC', 1)");
$siteTwo = (int) $db->insert_id;

$signIn = static function (string $role) use ($http, $tokenOn, $people, $jar): void {
    @unlink($jar);
    $page  = $http('GET', '/login');
    $token = $tokenOn($page['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $people[$role]['login'], 'password' => $people[$role]['password']]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$role} did not answer a redirect (HTTP {$in['status']})");
    }
};

// ── Database helpers ────────────────────────────────────────────────────────
const AL_KEY  = 'audit.retentionDays';
const AL_ERRS = 'errors.retentionDays';

/** A settings row's value, or null when there is none. `$site` null = installation-wide. */
$setting = static function (string $key, ?int $site) use ($db): ?string {
    $stmt = $db->prepare('SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` ' . ($site === null ? 'IS NULL' : '= ?'));
    $site === null ? $stmt->bind_param('s', $key) : $stmt->bind_param('si', $key, $site);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? null : (string) $row['settingValue'];
};
/** Put a row in by SQL — the only way to reach some states. null value = delete it. */
$put = static function (string $key, ?int $site, ?string $value) use ($db): void {
    $del = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` ' . ($site === null ? 'IS NULL' : '= ?'));
    $site === null ? $del->bind_param('s', $key) : $del->bind_param('si', $key, $site);
    $del->execute();
    $del->close();
    if ($value !== null) {
        $ins = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, ?, '', 0)");
        $ins->bind_param('iss', $site, $key, $value);
        $ins->execute();
        $ins->close();
    }
};
/** The installation's row and both organisations' own rows, set in one go. */
$world = static function (?string $inst, ?string $orgOne, ?string $orgTwo) use ($put, $siteOne, $siteTwo): void {
    $put(AL_KEY, null, $inst);
    $put(AL_KEY, $siteOne, $orgOne);
    $put(AL_KEY, $siteTwo, $orgTwo);
};
$activityCount = static function (string $type) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `activityType` = ?');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n;
};

$lastActivity = static function (string $type) use ($db): string {
    $stmt = $db->prepare('SELECT `activityDescription` FROM `tblActivityLogs` WHERE `activityType` = ? ORDER BY `logID` DESC LIMIT 1');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['activityDescription'] ?? '');
};

// ── Page helpers ────────────────────────────────────────────────────────────
const AL_PAGE = '/admin/maintenance/retention';
$page = static fn (): array => $http('GET', AL_PAGE);
/** The flash this page prints, as type and text. */
$flashOf = static function (string $html): array {
    if (preg_match('#<div class="alert alert-(\w+) alert-dismissible[^"]*">\s*(.*?)\s*<button#s', $html, $m) === 1) {
        return ['type' => $m[1], 'text' => html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8')];
    }
    return ['type' => '', 'text' => ''];
};
/** Post one of the page's forms with the page's own token, then read the page it redirects back to. */
$save = static function (array $fields) use ($http, $tokenOn, $page, $flashOf): array {
    $token  = $tokenOn($page()['body']);
    $answer = $http('POST', AL_PAGE, ['csrf_token' => $token] + $fields);
    $after  = $page();
    return ['status' => $answer['status'], 'location' => $answer['location'], 'flash' => $flashOf($after['body']), 'page' => $after['body']];
};
$install = static fn (array $f): array => $save(['action' => 'activity-retention-installation'] + $f);

/**
 * The fields of ONE of the page's forms exactly as the browser would send them
 * untouched: its hidden inputs, the radio that is drawn `checked`, and the
 * number box's drawn value. Reading what the page DREW is the point (#674):
 * a form prefilled from the wrong layer looks right and saves wrong.
 */
$asDrawn = static function (string $html, string $action): array {
    $at = strpos($html, 'value="' . $action . '"');
    if ($at === false) {
        return [];
    }
    $start = (int) strrpos(substr($html, 0, $at), '<form');
    $end   = strpos($html, '</form>', $at);
    $form  = substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    $fields = [];
    preg_match_all('#<input\b[^>]*>#s', $form, $inputs);
    foreach ($inputs[0] as $tag) {
        $name  = preg_match('#name="([^"]*)"#', $tag, $m) === 1 ? $m[1] : '';
        $value = preg_match('#value="([^"]*)"#', $tag, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
        $type  = preg_match('#type="([^"]*)"#', $tag, $m) === 1 ? $m[1] : 'text';
        if ($name === '') {
            continue;
        }
        if ($type === 'radio') {
            if (preg_match('#\schecked\b#', $tag) === 1) {
                $fields[$name] = $value;
            }
            continue;
        }
        $fields[$name] = $value;
    }
    return $fields;
};
/** Submit one form exactly as drawn, with nothing touched. */
$submitAsDrawn = static function (string $action) use ($http, $page, $asDrawn): array {
    $fields = $asDrawn($page()['body'], $action);
    return ['fields' => $fields, 'answer' => $fields === [] ? null : $http('POST', AL_PAGE, $fields)];
};
/** Post one form with no token, or a wrong one: both must be refused and change nothing. */
$postWithToken = static function (array $fields, ?string $token) use ($http, $page, $flashOf): array {
    $answer = $http('POST', AL_PAGE, ($token === null ? [] : ['csrf_token' => $token]) + $fields);
    return ['status' => $answer['status'], 'flash' => $flashOf($page()['body'])];
};
$forOrg  = static fn (array $f): array => $save(['action' => 'activity-retention-organisation'] + $f);

// ── The real clear-out ──────────────────────────────────────────────────────
$cronToken = bin2hex(random_bytes(16));
$put('maintenance.cronToken', null, $cronToken);
/** One activity-log row, so many days old, for one organisation (null = nobody). */
$logRow = static function (?int $site, int $age) use ($db): int {
    $stmt = $db->prepare("INSERT INTO `tblActivityLogs` (`siteID`, `activityType`, `timestamp`) VALUES (?, 'SelftestAlret', DATE_SUB(NOW(), INTERVAL ? DAY))");
    $stmt->bind_param('ii', $site, $age);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
/** Run the job over HTTP; answer which of `$ids` survived (keyed as given), or a failure string. */
$runJob = static function (array $ids) use ($http, $cronToken, $db): array|string {
    $ran = $http('GET', '/cron/retention-sweep?token=' . $cronToken);
    if ($ran['status'] !== 200 || str_contains($ran['body'], '"status":"ok"') === false) {
        return 'the job did not run: ' . $ran['status'] . ' ' . substr($ran['body'], 0, 160);
    }
    $out = [];
    foreach ($ids as $label => $id) {
        $out[$label] = (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `logID` = " . (int) $id)->fetch_assoc()['n'] ?? 0) === 1;
    }
    return $out;
};
/** Under never: what survives the real job, for three owners, plus one organisation at 7 days. */
$scnNeverKeeps = static function () use ($db, $world, $logRow, $runJob, $siteOne, $siteTwo): array|string {
    $db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");
    // Organisation one chose never; organisation two follows the installation's never.
    $world('0', '0', null);
    return $runJob(['one' => $logRow($siteOne, 4000), 'two' => $logRow($siteTwo, 4000), 'nobody' => $logRow(null, 4000)]);
};

$startServer();
$waitForServer();

// ═════════════════════════════════════════════════════════════════════════════
// The scenarios the plants in section P re-run. Each answers TRUE when the guard
// it is about did its job, so a plant must make it answer FALSE.
// ═════════════════════════════════════════════════════════════════════════════

/** Under 30 days, an organisation's never — asked by ITS OWN administrator (D-118) — is refused and nothing is stored. */
$scnRefuseNever = static function () use ($world, $signIn, $forOrg, $setting, $siteOne): bool {
    $world('30', null, null);
    $signIn('orgadmin');
    $r = $forOrg(['choice' => 'never']);
    return $setting(AL_KEY, $siteOne) === null && $r['flash']['type'] === 'danger';
};
/** The installation's never is stored as 0 and read back as never by the page. */
$scnInstallNever = static function () use ($world, $signIn, $install, $setting): bool {
    $world('365', null, null);
    $signIn('globaladmin');
    $r = $install(['choice' => 'never', 'days' => '365']);
    // Both the organisation's figure (which reaches the installation's row
    // through its own fall-back) AND the installation's own figure, which is
    // what rows owned by nobody follow: a clamp to 3650 shows only in the second.
    return $setting(AL_KEY, null) === '0' && str_contains($r['page'], 'data-activity-log-in-force="0"')
        && str_contains($r['page'], '<strong>any number of days, or never deleted</strong>');
};
/** D-119: lowering the installation from never to 30 days while organisation one holds never is refused, NAMING it. */
$scnLoweringRefused = static function () use ($world, $signIn, $install, $setting, $siteOneName): bool {
    $world('0', '0', null);
    $signIn('globaladmin');
    $r = $install(['choice' => 'days', 'days' => '30']);
    return $setting(AL_KEY, null) === '0' && $r['flash']['type'] === 'danger'
        && str_contains($r['flash']['text'], $siteOneName . ' (never deleted)');
};
/** D-118: an organisation's own administrator stores their own figure within the installation's. */
$scnOrgAdminSaves = static function () use ($world, $signIn, $forOrg, $setting, $siteOne): bool {
    $world('30', null, null);
    $signIn('orgadmin');
    $r = $forOrg(['choice' => 'days', 'days' => '20']);
    return $setting(AL_KEY, $siteOne) === '20' && $r['flash']['type'] === 'success';
};
/** D-118: ...and cannot touch the installation's figure: 403, nothing stored. */
$scnOrgAdminNoInstallation = static function () use ($world, $signIn, $http, $tokenOn, $page, $setting): bool {
    $world('30', null, null);
    $signIn('orgadmin');
    $ans = $http('POST', AL_PAGE, ['csrf_token' => $tokenOn($page()['body']), 'action' => 'activity-retention-installation', 'choice' => 'never']);
    return $ans['status'] === 403 && $setting(AL_KEY, null) === '30';
};
/** D-118: ...nor run the sweep (a POST with no action): 403, and a 400-day-old row survives. */
$scnOrgAdminNoSweep = static function () use ($db, $world, $signIn, $http, $tokenOn, $page, $logRow): bool {
    $db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");
    $world('30', null, null);
    $old = $logRow(null, 400);
    $signIn('orgadmin');
    $ans = $http('POST', AL_PAGE, ['csrf_token' => $tokenOn($page()['body'])]);
    return $ans['status'] === 403
        && (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `logID` = {$old}")->fetch_assoc()['n'] ?? 0) === 1;
};
/**
 * #674 (check round 1, M6): an organisation with NO row of its own, its form
 * submitted exactly as drawn, must still have no row. Drawn from the merged
 * settings it would show the installation's 30 ticked as its own, and one
 * untouched Save would pin it.
 */
$scnPrefillUntouched = static function () use ($world, $signIn, $submitAsDrawn, $setting, $siteOne): bool {
    $world('30', null, null);
    $signIn('orgadmin');
    $sent = $submitAsDrawn('activity-retention-organisation');
    return ($sent['fields']['choice'] ?? null) === 'follow' && $sent['answer'] !== null
        && $setting(AL_KEY, $siteOne) === null;
};
/** The form token (M7): both forms refused with no token and with a wrong one, nothing stored. */
$scnTokenRefused = static function () use ($world, $signIn, $postWithToken, $setting, $siteOne): bool {
    $world('30', null, null);
    $out = [];
    foreach ([null, 'not-the-token'] as $token) {
        $signIn('globaladmin');
        $out[] = $postWithToken(['action' => 'activity-retention-installation', 'choice' => 'days', 'days' => '20'], $token);
        $out[] = $postWithToken(['action' => 'activity-retention-organisation', 'choice' => 'days', 'days' => '20'], $token);
    }
    foreach ($out as $one) {
        if ($one['flash']['type'] !== 'danger' || str_contains($one['flash']['text'], 'form token') === false) {
            return false;
        }
    }
    return $setting(AL_KEY, null) === '30' && $setting(AL_KEY, $siteOne) === null;
};
/** The real job keeps every row under never. */
$scnJobKeeps = static function () use ($scnNeverKeeps): bool {
    return $scnNeverKeeps() === ['one' => true, 'two' => true, 'nobody' => true];
};
/** 0 typed as a NUMBER is refused, never read as never. */
$scnZeroNumber = static function () use ($world, $signIn, $install, $setting): bool {
    $world('365', null, null);
    $signIn('globaladmin');
    $install(['choice' => 'days', 'days' => '0']);
    return $setting(AL_KEY, null) === '365';
};
/** An unknown action runs nothing: a 400-day-old row under an installation of 30 days survives. */
$scnUnknownAction = static function () use ($db, $world, $signIn, $http, $tokenOn, $page, $logRow, $setting): bool {
    $db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");
    $world('30', null, null);
    $old = $logRow(null, 400);
    $signIn('globaladmin');
    $http('POST', AL_PAGE, ['csrf_token' => $tokenOn($page()['body']), 'action' => 'something-else']);
    return (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `logID` = {$old}")->fetch_assoc()['n'] ?? 0) === 1
        && $setting(AL_KEY, null) === '30';
};
/** The error log's stored 0 still means a year: the page says 365 and the job deletes a 400-day-old error row. */
$scnErrorsYear = static function () use ($db, $put, $signIn, $page, $http, $cronToken): bool {
    $put(AL_ERRS, null, '0');
    $db->query('DELETE FROM `tblErrors`');
    $db->query("INSERT INTO `tblErrors` (`siteID`, `createdAt`) VALUES (NULL, DATE_SUB(NOW(), INTERVAL 400 DAY))");
    $old = (int) $db->insert_id;
    $db->query("INSERT INTO `tblErrors` (`siteID`, `createdAt`) VALUES (NULL, DATE_SUB(NOW(), INTERVAL 300 DAY))");
    $new = (int) $db->insert_id;
    $signIn('globaladmin');
    $body = $page()['body'];
    $http('GET', '/cron/retention-sweep?token=' . $cronToken);
    $left = [];
    $rs = $db->query("SELECT `errorID` FROM `tblErrors` WHERE `errorID` IN ({$old}, {$new})");
    while ($row = $rs->fetch_assoc()) {
        $left[] = (int) $row['errorID'];
    }
    $put(AL_ERRS, null, '365');
    return preg_match('#Error Log \(<code>tblErrors</code>\)</h2>\s*<p class="text-muted small mb-2">\s*Kept for <strong>365 days</strong>#', $body) === 1
        && $left === [$new];
};

// ═════════════════════════════════════════════════════════════════════════════
echo "A. The page, with the installation at a number of days\n";
$world('365', null, null);
$signIn('globaladmin');
$p = $page();
check('A1 the page answers 200 to a global administrator', $p['status'], 200);
check('A2 ...and finished drawing (a page that throws part-way still answers 200)', str_contains($p['body'], '</html>'), true);
check('A3 the activity-log card is there, with both forms', [
    str_contains($p['body'], 'data-activity-log-retention-card="1"'),
    str_contains($p['body'], 'value="activity-retention-installation"'),
    str_contains($p['body'], 'value="activity-retention-organisation"'),
], [true, true, true]);
check('A4 under 365 days the ORGANISATION\'s form does not offer "never" (a choice whose Save always refuses)',
    str_contains($p['body'], 'id="alrOwnNever"'), false);
check('A5 ...and says plainly why', str_contains($p['body'], 'is not available</strong>, because this installation'), true);
check('A6 "never" is explained where it is offered: the log grows without limit, and what records say about an erased person stays',
    str_contains($p['body'], 'grows without limit') && str_contains($p['body'], 'those descriptions stay for ever, even after'), true);
check('A7 the installation\'s box is prefilled with its own 365, inside its own max of 3650',
    preg_match('#name="days" aria-label="Number of days, for every organisation"\s+value="365"#', $p['body']) === 1
    && str_contains($p['body'], 'min="1" max="3650"'), true);

echo "B. The installation's \"Never delete\"\n";
$beforeSaved = $activityCount('ActivityLogRetentionSaved');
check('B1 (scenario) the installation\'s never is stored as 0 — not 3650 — and the page reads it back as never', $scnInstallNever(), true);
$p = $page()['body'];
check('B2 the page then says records are never deleted for this organisation', str_contains($p, '<strong>Never deleted</strong> for your organisation'), true);
check('B3 ...the organisation\'s form now offers "never"', str_contains($p, 'id="alrOwnNever"'), true);
check('B4 ...and the installation\'s "Never delete" is the one ticked', preg_match('#value="never" id="alrInstNever"\s+checked#', $p) === 1, true);
check('B5 the change was recorded', $activityCount('ActivityLogRetentionSaved'), $beforeSaved + 1);

echo "C. An organisation's never under an installation at never\n";
$r = $forOrg(['choice' => 'never']);
check('C1 it is stored, as 0', $setting(AL_KEY, $siteOne), '0');
check('C2 ...and the page says so', $r['flash']['type'] . ' | ' . $r['flash']['text'], 'success | Saved. This organisation\'s activity-log records are now never deleted.');
$r = $forOrg(['choice' => 'days', 'days' => '45']);
check('C3 any number of days is allowed under never too', $setting(AL_KEY, $siteOne), '45');

echo "D. The real clear-out under never\n";
check('D1 (scenario) 4000-day-old rows survive for an organisation that chose never, one that follows the installation\'s never, and rows owned by nobody',
    $scnNeverKeeps(), ['one' => true, 'two' => true, 'nobody' => true]);
$db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");
$world('0', '7', null);
check('D2 ...while an organisation that chose 7 days under that installation has its own old row deleted, and only that',
    $runJob(['one' => $logRow($siteOne, 400), 'two' => $logRow($siteTwo, 400), 'nobody' => $logRow(null, 400)]),
    ['one' => false, 'two' => true, 'nobody' => true]);
$db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");

echo "E. Lowering the installation (D-119), and an organisation's own administrator (D-118)\n";
$beforeRefused = $activityCount('ActivityLogRetentionRefused');
check('E1 (scenario) lowering the installation from never to 30 days while an organisation holds never is REFUSED, naming it, and nothing changes',
    $scnLoweringRefused(), true);
check('E2 ...the refusal is recorded, with a COUNT and not the name', [
    $activityCount('ActivityLogRetentionRefused'),
    str_contains($lastActivity('ActivityLogRetentionRefused'), '1 organisation(s) have chosen a longer figure'),
    str_contains($lastActivity('ActivityLogRetentionRefused'), $siteOneName),
], [$beforeRefused + 1, true, false]);
$signIn('orgadmin');
$r = $forOrg(['choice' => 'days', 'days' => '20']);
check('E3 the organisation\'s OWN administrator lowers its own figure to 20 days (D-118)', [$setting(AL_KEY, $siteOne), $r['flash']['type']], ['20', 'success']);
$signIn('globaladmin');
$r = $install(['choice' => 'days', 'days' => '10']);
check('E4 the installation\'s 10 days is still refused while that organisation holds 20, and says so', [
    $setting(AL_KEY, null), $r['flash']['type'], str_contains($r['flash']['text'], $siteOneName . ' (20 days)'),
], ['0', 'danger', true]);
$r = $install(['choice' => 'days', 'days' => '30']);
check('E5 ...while 30 days, which nobody is above, is ACCEPTED, and says nothing about holding anybody',
    [$setting(AL_KEY, null), $r['flash']['type'], str_contains($r['flash']['text'], 'held')], ['30', 'success', false]);
$world('0', null, null);
$r = $install(['choice' => 'days', 'days' => '90']);
check('E6 with no organisation figure at all, a lowering from never is accepted', [$setting(AL_KEY, null), $r['flash']['type']], ['90', 'success']);
check('E7 (scenario) the organisation\'s administrator saves their own figure (D-118)', $scnOrgAdminSaves(), true);

echo "F. The refusal, met by the organisation's own administrator\n";
$beforeRefused = $activityCount('ActivityLogRetentionRefused');
check('F1 (scenario) under 30 days an organisation\'s never is refused and nothing is stored', $scnRefuseNever(), true);
$r = $forOrg(['choice' => 'never']);
check('F2 ...the reason is given in the page\'s own words: "Never delete" is not allowed, choose 1 to 30, and it never names who set the limit',
    [str_contains($r['flash']['text'], 'does not allow "Never delete"'), str_contains($r['flash']['text'], 'between 1 and 30'),
     str_contains($r['flash']['text'], 'global administrator')], [true, true, false]);
check('F3 ...and each refusal is recorded', $activityCount('ActivityLogRetentionRefused'), $beforeRefused + 2);
$r = $forOrg(['choice' => 'days', 'days' => '0']);
// #940 round 4: this used to REQUIRE the words 'choose "Never delete" instead'
// here - under an installation of 30 days, where the organisation's form does
// not offer "Never delete" and may choose only 1 to 30. The check pinned the
// untruth. It now requires the range really allowed and no "Never delete".
check('F4 0 typed as a NUMBER is refused — never is its own choice — nothing is stored, and the words give the range this organisation may really choose (1 to 30) and do not offer "Never delete", which its form does not',
    [$setting(AL_KEY, $siteOne), $r['flash']['text']], [null, 'A number of days has to be a whole number from 1 to 30. Nothing was saved.']);
$r = $forOrg(['choice' => 'days', 'days' => '31']);
check('F5 31 days is refused, never clamped to 30', [$setting(AL_KEY, $siteOne), $r['flash']['type']], [null, 'danger']);
$r = $forOrg(['choice' => 'days', 'days' => '20']);
check('F6 20 days is stored', $setting(AL_KEY, $siteOne), '20');
$r = $forOrg(['choice' => 'follow']);
check('F7 "follow the installation" removes the organisation\'s own row', $setting(AL_KEY, $siteOne), null);
$r = $forOrg([]);
check('F8 a post with no choice at all changes nothing (#677) and says so',
    [$setting(AL_KEY, $siteOne), $r['flash']['type']], [null, 'danger']);

echo "G. The installation's hard maximum\n";
$signIn('globaladmin');
$r = $install(['choice' => 'days', 'days' => '3651']);
check('G1 3651 days is refused, not clamped, and the stored 30 is untouched', [$setting(AL_KEY, null), $r['flash']['type']], ['30', 'danger']);
check('G2 ...and the message points at "Never delete" for longer', str_contains($r['flash']['text'], 'choose "Never delete" instead'), true);
check('G3 (scenario) 0 typed as a number at the installation is refused, not taken as never', $scnZeroNumber(), true);

echo "H. An untouched Save over a stored never the installation does not allow\n";
$world('30', '0', null);
$signIn('globaladmin');
$r = $forOrg(['choice' => 'never']);
check('H1 it changes nothing and says so, rather than refusing a figure nobody typed', [$setting(AL_KEY, $siteOne), $r['flash']['type']], ['0', 'info']);
check('H2 ...and the page warns that the stored never is not being obeyed, and says what is in force: the READER caps it',
    [str_contains($r['page'], 'data-activity-log-never-held="1"'), str_contains($r['page'], 'data-activity-log-in-force="30"')], [true, true]);

echo "L. The form token, and a form submitted exactly as drawn (check round 1)\n";
check('L1 (scenario) both forms, posted with NO form token and with a WRONG one, are refused with the token message and store nothing',
    $scnTokenRefused(), true);
check('L2 (scenario) an organisation with no figure of its own: its form, submitted untouched, still leaves it with NO row (#674)',
    $scnPrefillUntouched(), true);
$world('0', null, null);
$signIn('globaladmin');
$sentInst = $submitAsDrawn('activity-retention-installation');
check('L3 the installation\'s form at never, submitted untouched, is drawn with "never" ticked and changes nothing',
    [$sentInst['fields']['choice'] ?? null, $setting(AL_KEY, null)], ['never', '0']);
$world('30', '20', null);
$signIn('orgadmin');
$sentOwn = $submitAsDrawn('activity-retention-organisation');
check('L4 an organisation\'s own 20 days, submitted untouched, is drawn as its own and stays 20',
    [$sentOwn['fields']['choice'] ?? null, $sentOwn['fields']['days'] ?? null, $setting(AL_KEY, $siteOne)], ['days', '20', '20']);

echo "I. Who may use it (D-118)\n";
$world('30', null, null);
$signIn('member');
$token = $tokenOn($http('GET', '/')['body']);
$m = [
    $http('GET', AL_PAGE)['status'],
    $http('POST', AL_PAGE, ['csrf_token' => $token, 'action' => 'activity-retention-installation', 'choice' => 'never'])['status'],
    $http('POST', AL_PAGE, ['csrf_token' => $token, 'action' => 'activity-retention-organisation', 'choice' => 'days', 'days' => '5'])['status'],
    $http('POST', AL_PAGE, ['csrf_token' => $token])['status'],
];
check('I1 a member is refused EVERYTHING — the page, both forms and the sweep — and nothing is stored',
    [$m, $setting(AL_KEY, null), $setting(AL_KEY, $siteOne)], [[403, 403, 403, 403], '30', null]);
$signIn('orgadmin');
$o = $page();
check('I2 an organisation\'s administrator gets the page (200), with their organisation\'s form and NOTHING installation-wide', [
    $o['status'], str_contains($o['body'], '</html>'),
    str_contains($o['body'], 'value="activity-retention-organisation"'),
    str_contains($o['body'], 'value="activity-retention-installation"'),
    str_contains($o['body'], 'Run Sweep Now'),
    str_contains($o['body'], 'Rows that would be deleted now'),
], [200, true, true, false, false, false]);
$beforeRefused = $activityCount('ActivityLogRetentionRefused');
check('I3 (scenario) ...posting the installation\'s form is refused (403), nothing is stored', $scnOrgAdminNoInstallation(), true);
check('I4 ...and that refusal is recorded', $activityCount('ActivityLogRetentionRefused'), $beforeRefused + 1);
check('I5 (scenario) ...and the old sweep action (a post with no action) is refused (403) and deletes nothing', $scnOrgAdminNoSweep(), true);

echo "J. The page's other POST\n";
check('J1 (scenario) an action the page does not know runs NOTHING — not the sweep, not a save', $scnUnknownAction(), true);
$db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");
$world('30', null, null);
$old = $logRow(null, 400);
$signIn('globaladmin');
$swept = $http('POST', AL_PAGE, ['csrf_token' => $tokenOn($page()['body'])]);
check('J2 a post with NO action is still the sweep, as it always was: the 400-day-old row is gone',
    [$swept['status'], (int) ($db->query("SELECT COUNT(*) AS `n` FROM `tblActivityLogs` WHERE `logID` = {$old}")->fetch_assoc()['n'] ?? 0)], [200, 0]);

echo "K. The error log is untouched\n";
check('K1 (scenario) its stored 0 still reads as a year on the page, and the real job deletes a 400-day-old error row and keeps a 300-day-old one',
    $scnErrorsYear(), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "P. The plants: each guard taken out of a COPY, and its scenario goes red\n";
$plant = static function (string $relPath, string $from, string $to, callable $scenario) use ($child, $sep, $startServer, $waitForServer): array {
    $path   = $child . $sep . 'web' . $sep . str_replace('/', $sep, $relPath);
    $before = (string) file_get_contents($path);
    if (substr_count($before, $from) !== 1) {
        return ['applied' => false, 'result' => null];
    }
    file_put_contents($path, str_replace($from, $to, $before));
    $startServer();
    $waitForServer();
    $result = $scenario();
    file_put_contents($path, $before);
    $startServer();
    $waitForServer();
    return ['applied' => true, 'result' => $result];
};

$p1 = $plant('_core/ActivityLogRetention.php', '$reason = self::whyNotAllowedForSite($days, $installationStored);',
    '$reason = $days === self::NEVER ? \'\' : self::whyNotAllowedForSite($days, $installationStored);', $scnRefuseNever);
check('P1 with an organisation\'s never let through under a number of days, the refusal scenario goes red', [$p1['applied'], $p1['result']], [true, false]);
$p2 = $plant('_core/ActivityLogRetention.php', "            return self::NEVER;\n        }\n\n        return SettingLimit::installationAllows(",
    "            return self::HARD_MAX_DAYS;\n        }\n\n        return SettingLimit::installationAllows(", $scnInstallNever);
check('P2 with never clamped to 3650, the installation\'s never no longer reads as never', [$p2['applied'], $p2['result']], [true, false]);
$p3 = $plant('_apps/cron/_retention-sweep.php', '$orphanDays === LOG_RETENTION_NEVER ? false : $db->prepare(', '$db->prepare(', $scnJobKeeps);
check('P3 with the sweep still deleting rows owned by nobody under never, the job scenario goes red', [$p3['applied'], $p3['result']], [true, false]);
$p4 = $plant('_apps/cron/_retention-sweep.php', "                if (\$days === LOG_RETENTION_NEVER) {\n", "                if (false) {\n", $scnJobKeeps);
check('P4 with the sweep still deleting an organisation\'s rows under never, the job scenario goes red', [$p4['applied'], $p4['result']], [true, false]);
$p5 = $plant('_apps/cron/_retention-sweep.php', 'return $ceiling < 1 ? LOG_RETENTION_DEFAULT_DAYS : $ceiling;',
    'return $ceiling < 1 ? LOG_RETENTION_NEVER : $ceiling;', $scnErrorsYear);
check('P5 with the error log changed by the same edit (its 0 read as never), the error-log scenario goes red', [$p5['applied'], $p5['result']], [true, false]);
$p6 = $plant('_apps/admin/maintenance/retention.php', "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && \$postedAction !== 'sweep') {",
    "if (false) {", $scnUnknownAction);
check('P6 with an unknown action let fall through to the sweep, the unknown-action scenario goes red', [$p6['applied'], $p6['result']], [true, false]);
$p7 = $plant('_apps/admin/maintenance/retention.php', "if (preg_match('/^\\d{1,6}\$/', \$alrRaw) !== 1 || (int) \$alrRaw < 1) {",
    "if (preg_match('/^\\d{1,6}\$/', \$alrRaw) !== 1) {", $scnZeroNumber);
check('P7 with 0 typed as a number accepted, the installation stores 0 and the zero-number scenario goes red', [$p7['applied'], $p7['result']], [true, false]);
$p9 = $plant('_core/ActivityLogRetention.php', 'if ($above !== []) {', 'if (false) {', $scnLoweringRefused);
check('P9 with D-119\'s refusal taken out, the installation is lowered beneath an organisation\'s never', [$p9['applied'], $p9['result']], [true, false]);
$p10 = $plant('_apps/admin/maintenance/retention.php', "|| (\$postedAction === 'activity-retention-installation' && \$isGlobalAdmin === true))",
    "|| \$postedAction === 'activity-retention-installation')", $scnOrgAdminNoInstallation);
check('P10 with the page letting an organisation\'s administrator into the installation\'s save, it is no longer refused with a 403', [$p10['applied'], $p10['result']], [true, false]);
$p11 = $plant('_apps/admin/maintenance/retention.php', 'ActivityLogRetention::saveForSite($mysqli, $alrSiteId, $alrDays, App::isAdmin())',
    'ActivityLogRetention::saveForSite($mysqli, $alrSiteId, $alrDays, App::isRootAdmin())', $scnOrgAdminSaves);
check('P11 with D-118 undone (global administrators only), the organisation\'s administrator cannot save their own figure', [$p11['applied'], $p11['result']], [true, false]);
$p12 = $plant('_apps/admin/maintenance/retention.php', 'if ($isGlobalAdmin === false) {', 'if (false) {', $scnOrgAdminNoSweep);
check('P12 with the global-administrator gate taken out, an organisation\'s administrator runs the sweep', [$p12['applied'], $p12['result']], [true, false]);
$p13 = $plant('_apps/admin/maintenance/retention.php', "        \$alrFinish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger');\n",
    "        // planted: the form-token refusal taken out\n", $scnTokenRefused);
check('P13 with the activity-log forms\' form-token refusal taken out, a post with no token or a wrong one is SAVED', [$p13['applied'], $p13['result']], [true, false]);
$p14 = $plant('_apps/admin/maintenance/retention.php', '$alrOwn       = ActivityLogRetention::ownDays($mysqli, Site::id());',
    '$alrOwn       = App::settings(\'audit.retentionDays\') === null ? null : (int) App::settings(\'audit.retentionDays\');', $scnPrefillUntouched);
check('P14 with the organisation\'s form prefilled from the merged settings (#674), one untouched Save pins the installation\'s figure as its own', [$p14['applied'], $p14['result']], [true, false]);
check('P8 with every plant removed again, the refusal is green once more', $scnRefuseNever(), true);

// ═════════════════════════════════════════════════════════════════════════════
echo "M. D-118 with several organisations and a TRUE organisation administrator\n";
// ═════════════════════════════════════════════════════════════════════════════
// Several organisations ON, decided by the SESSION (the person chooses at
// /site/choose), so `Site::id()` really can be organisation two. Only the
// VALUE is changed — the row's default and sensitivity are left as they are —
// and the original rows are put back whole, on every way out (see the top of
// this file), as well as straight after this section.
$alSetValueOnly = static function (string $key, string $value) use ($db): void {
    $up = $db->prepare('UPDATE `tblSettings` SET `settingValue` = ? WHERE `settingKey` = ? AND `siteID` IS NULL');
    $up->bind_param('ss', $value, $key);
    $up->execute();
    $changed = $up->affected_rows;
    $up->close();
    $have = $db->prepare('SELECT COUNT(*) AS `n` FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` IS NULL');
    $have->bind_param('s', $key);
    $have->execute();
    $exists = (int) ($have->get_result()->fetch_assoc()['n'] ?? 0) > 0;
    $have->close();
    if ($changed === 0 && $exists === false) {
        $ins = $db->prepare("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, '', 0)");
        $ins->bind_param('ss', $key, $value);
        $ins->execute();
        $ins->close();
    }
};
$alSetValueOnly('multisite.enabled', 'true');
$alSetValueOnly('multisite.detectionMode', 'session');
$sa = (int) $people['siteadmin']['id'];
$ga = (int) $people['globaladmin']['id'];
$db->query("UPDATE `tblUserSites` SET `isSiteAdmin` = 1 WHERE `userID` = {$sa} AND `siteID` = {$siteOne}");
$db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`) VALUES ({$siteTwo}, {$sa}, 1, 0)");
$db->query("INSERT IGNORE INTO `tblUserSites` (`siteID`, `userID`, `isActive`, `isSiteAdmin`) VALUES ({$siteTwo}, {$ga}, 1, 0)");

/** Sign in, then choose the organisation, as a person does with several. */
$signInAt = static function (string $role, int $site) use ($signIn, $http, $tokenOn): void {
    $signIn($role);
    $chooser = $http('GET', '/site/choose');
    $http('POST', '/site/switch', ['csrf_token' => $tokenOn($chooser['body']), 'site_id' => (string) $site]);
};
/** A post with this session's own token. */
$postHere = static function (array $fields) use ($http, $tokenOn): array {
    return $http('POST', AL_PAGE, ['csrf_token' => $tokenOn($http('GET', '/')['body'])] + $fields);
};
/** D-118: an organisation's figure can only be its OWN, whatever organisation the post names. */
$scnOwnOrganisationOnly = static function () use ($world, $signInAt, $postHere, $setting, $siteOne, $siteTwo): bool {
    $world('30', null, null);
    $signInAt('siteadmin', $siteOne);
    $postHere(['action' => 'activity-retention-organisation', 'choice' => 'days', 'days' => '20',
        'siteID' => (string) $siteTwo, 'site_id' => (string) $siteTwo, 'siteId' => (string) $siteTwo]);
    return $setting(AL_KEY, $siteOne) === '20' && $setting(AL_KEY, $siteTwo) === null;
};

$world('30', null, null);
$signInAt('siteadmin', $siteOne);
$g = $page();
check('M1 a true organisation administrator, on their own organisation, gets the page with ONLY its form', [
    $g['status'], str_contains($g['body'], 'value="activity-retention-organisation"'),
    str_contains($g['body'], 'value="activity-retention-installation"'), str_contains($g['body'], 'Run Sweep Now'),
], [200, true, false, false]);
check('M2 (scenario) a post naming organisation two (siteID, site_id and siteId) changes ONLY organisation one', $scnOwnOrganisationOnly(), true);
$refusedHere = [];
foreach ([
    'installation' => ['action' => 'activity-retention-installation', 'choice' => 'never'],
    'no action'    => [],
    'action=sweep' => ['action' => 'sweep'],
    'unknown'      => ['action' => 'something-else'],
    'capitals'     => ['action' => 'Activity-Retention-Organisation', 'choice' => 'days', 'days' => '5'],
    'action[]'     => ['action' => ['activity-retention-organisation'], 'choice' => 'days', 'days' => '5'],
] as $label => $fields) {
    $refusedHere[$label] = $postHere($fields)['status'];
}
check('M3 ...and on organisation one the installation\'s form, the sweep (no action, and action=sweep), an unknown action, a differently capitalised action and action[] are each refused (403), changing nothing',
    [$refusedHere, $setting(AL_KEY, null), $setting(AL_KEY, $siteOne)],
    [['installation' => 403, 'no action' => 403, 'action=sweep' => 403, 'unknown' => 403, 'capitals' => 403, 'action[]' => 403], '30', '20']);
$http('POST', AL_PAGE, ['action' => 'activity-retention-organisation', 'choice' => 'days', 'days' => '5']);
check('M4 ...a post with NO form token changes nothing, for the organisation administrator too', $setting(AL_KEY, $siteOne), '20');
$signInAt('siteadmin', $siteTwo);
$twoGet  = $page()['status'];
$twoPost = $postHere(['action' => 'activity-retention-organisation', 'choice' => 'days', 'days' => '7'])['status'];
check('M5 switched to organisation two, where they are a plain member: the page and the organisation form are refused (403), and no row is written',
    [$twoGet, $twoPost, $setting(AL_KEY, $siteTwo), $setting(AL_KEY, $siteOne)], [403, 403, null, '20']);
$signInAt('globaladmin', $siteTwo);
$gaSave = $postHere(['action' => 'activity-retention-organisation', 'choice' => 'days', 'days' => '15']);
check('M6 a global administrator on organisation two saves organisation TWO\'s figure, and organisation one is untouched',
    [$setting(AL_KEY, $siteTwo), $setting(AL_KEY, $siteOne)], ['15', '20']);
$p15 = $plant('_apps/admin/maintenance/retention.php', '$alrSiteId    = Site::id();',
    '$alrSiteId    = isset($_POST[\'siteID\']) === true ? (int) $_POST[\'siteID\'] : Site::id();', $scnOwnOrganisationOnly);
check('P15 with the organisation taken from the post, an organisation administrator writes ANOTHER organisation\'s figure', [$p15['applied'], $p15['result']], [true, false]);
check('M7 with that plant removed again, only their own organisation changes once more', $scnOwnOrganisationOnly(), true);
$alRestoreMulti();
check('M8 the multi-organisation settings are back EXACTLY as they were found (value, default and sensitivity)',
    $alRowsNow(), $alOriginal);
file_put_contents($alMultiFile, json_encode($alOriginal));

// 🧾 The server's own error log. A page that throws part-way can still answer
//    200, so the log is read as well: any fatal error, or anything naming this
//    package's class, fails the run.
$serverLog = (string) @file_get_contents($child . $sep . 'php-errors.log');
$ours      = [];
foreach (preg_split('/\R/', $serverLog) ?: [] as $line) {
    if (stripos($line, 'Fatal') !== false || stripos($line, 'ActivityLogRetention') !== false || stripos($line, 'Uncaught') !== false) {
        $ours[] = $line;
    }
}
check('Z1 the server logged no fatal error and nothing about this package', $ours, []);

// ── Tidy up the rows this test made ─────────────────────────────────────────
$db->query("DELETE FROM `tblActivityLogs` WHERE `activityType` = 'SelftestAlret'");
$world('365', null, null);
$put('maintenance.cronToken', null, '');
$db->query("DELETE FROM `tblSettings` WHERE `siteID` = {$siteTwo}");
try {
    $db->query("DELETE FROM `tblSites` WHERE `siteID` = {$siteTwo}");
} catch (Throwable $e) {
    echo "  ! could not remove the second organisation ({$siteTwo}); it is harmless in a selftest database.\n";
}
foreach ($people as $person) {
    $removeMember((int) $person['id']);
}

$total = $passed + $failed;
echo "\n{$total} checks: {$passed} passed, {$failed} failed (expected " . AL_EXPECTED_CHECKS . ").\n";
echo "It drives the REAL page and the REAL clear-out over HTTP and reads every result back from the database.\n";
echo "It runs one engine, one worker: the lock is not proved here (see the header).\n";
exit($failed === 0 && $total === AL_EXPECTED_CHECKS ? 0 : 1);
