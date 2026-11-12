<?php
// Path: tools/assets-api-update-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: the assets API updates only what it was sent (#1001) 📦✏️
 * -----------------------------------------------------------------------------
 * `POST /api/assets/update` (and `PUT`/`PATCH /api/v1/assets/{id}`) is
 * documented as "only fields present in the body are touched". It used to fill
 * every field the body left out from a row it read BEFORE
 * `AssetRegister::updateAsset()` took the asset's lock, and hand those values
 * on as if they had been posted — so a change somebody else made in between
 * (the edit page, a stocktake) was silently PUT BACK by an API call that never
 * mentioned that field. Now the API tells updateAsset() which fields it was
 * sent, and updateAsset() writes every other column from the row as it holds
 * it UNDER THE LOCK. This test proves, over real HTTP against a real database:
 *
 *   R  THE RACE. This test holds the asset row's lock in its own connection;
 *      the real API request (renaming the asset) runs in the web server and
 *      reads the row, then WAITS on that lock — asserted from
 *      performance_schema, never slept on. While it waits, this connection
 *      changes EVERY other column (room, category, kit, confidentiality,
 *      status, money, dates, texts — all but the six in AU_NOT_CHANGED, a list
 *      R0 checks against the schema) and commits. The rename must land AND the
 *      rest of the row must be exactly what the other writer left, compared
 *      whole. The audit record of the API call must name only what it changed.
 *      (Round 1 changed three columns; exempting `locationID` and
 *      `isConfidential` from the rule then left every check green.)
 *   N  THE OTHER DIRECTION. The asset starts with every column that may be
 *      empty filled in; while the request waits the other writer EMPTIES all
 *      of them (the list asked of the schema); the row must stay emptied.
 *      (Round 2 only ever set values, so falling back to the request's stale
 *      value whenever the locked one was empty — the `??` idiom — passed.)
 *   S  THE LOCKING READ. The same race, driven in a separate PHP process that
 *      loads the portal's own start-up but begins every transaction WITH a
 *      consistent snapshot, so a plain read under the lock would see the row
 *      from BEFORE the other writer committed. Only updateAsset()'s
 *      `FOR UPDATE` keeps the result right there; without that read mode
 *      nothing over HTTP can tell the two apart, because today the re-read is
 *      the transaction's first plain read (round 1's plant E).
 *   P  a field that IS sent is written, an explicit null clears it, and
 *      nothing else moves;
 *   C  currency still follows its own rules (#966, D-139): an unusable code is
 *      a 400, a change the asset's records forbid is a 409 with the reason and
 *      the asset untouched, a permitted change is written, and a currency
 *      changed by somebody else while the request waited survives a request
 *      that did not send one;
 *   Z  no PHP error in the server's log, and (Z2) after the tidy-up every
 *      table holds exactly as many rows as before the run.
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - the edit page (`/assets/save`, a FULL write) is not raced here: it posts
 *     every field by design, so the last person to press Save wins, as it
 *     always has. tools/currency-selftest.php drives that page;
 *   - two API requests at once: the harness's web server runs one worker
 *     (`PHP_CLI_SERVER_WORKERS` is unset), so the "other writer" is this
 *     test's own connection, which is the same thing to the database;
 *   - MariaDB: performance_schema is OFF there by default, and this test
 *     REFUSES rather than guessing without it;
 *   - section S swaps the connection the portal's classes use for one that
 *     differs in nothing but how it begins a transaction; it drives
 *     AssetRegister::updateAsset() as the handler does, not the handler itself
 *     (section R drives the handler).
 *
 * Run it on a THROWAWAY database built from web/_sql/full_schema.sql:
 *     SELFTEST_DB_NAME=selftest_assetsapi [SELFTEST_DB_HOST=… SELFTEST_DB_PORT=…
 *     SELFTEST_DB_USER=… SELFTEST_DB_PASS=…] [SELFTEST_WEB_PORT=9101]
 *         php tools/assets-api-update-selftest.php
 * It needs curl and proc_open, and performance_schema ON and readable, and
 * REFUSES rather than skipping without any of them.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1001
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 10019xx. */
const AU_ADMIN = 1001901;
/** Every asset this test makes is named with this, so the tidy-up can find it. */
const AU_PREFIX = 'Assets API selftest';

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);
$pass = 0;
$fail = 0;
$state = ['proc' => null, 'child' => null];

function refuse(string $why): never
{
    fwrite(STDERR, "REFUSED: {$why}\n");
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok === true) {
        $pass++;
        echo "  ok   — {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL — {$name}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
}

// ── The repository's own classes (for Site::preDetect) ──────────────────────
spl_autoload_register(static function (string $class) use ($repo, $sep): void {
    $prefix = 'Portal\\Core\\';
    if (str_starts_with($class, $prefix) === true) {
        $file = $repo . $sep . 'web' . $sep . '_core' . $sep . substr($class, strlen($prefix)) . '.php';
        if (is_file($file) === true) {
            require_once $file;
        }
    }
});

// ── What it needs ───────────────────────────────────────────────────────────
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
$db->set_charset('utf8mb4');

/** @param list<mixed> $params @return list<array<string,mixed>> */
function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out    = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $out;
}

/** @param list<mixed> $params */
function run(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();

    return $n;
}

$tables = (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()')[0]['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}

// 🔎 Every race asks performance_schema which row a request is waiting on.
//    Without it the premise check could only ever answer "not waiting", and
//    the race would be reported as a portal fault. Refuse instead.
$psOn = (string) (rows($db, 'SELECT @@performance_schema AS `on`')[0]['on'] ?? '0');
if ($psOn !== '1') {
    refuse('the race needs performance_schema ON, and this server has @@performance_schema = ' . $psOn);
}
foreach (['data_lock_waits', 'data_locks', 'threads'] as $psTable) {
    try {
        rows($db, 'SELECT 1 FROM `performance_schema`.`' . $psTable . '` LIMIT 1');
    } catch (Throwable $e) {
        refuse('the race needs to read performance_schema.' . $psTable . ', and ' . $dbUser . ' cannot (' . $e->getMessage() . ')');
    }
}

// =============================================================================
// The test server: a copy of web/, as the other page tests do
// =============================================================================
$child = sys_get_temp_dir() . $sep . 'assets-api-update-selftest-' . bin2hex(random_bytes(6));
$state['child'] = $child;
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    if ($state['child'] !== null && is_file($state['child'] . DIRECTORY_SEPARATOR . 'assets-api-update-selftest.marker') === true) {
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
file_put_contents($child . $sep . 'assets-api-update-selftest.marker', 'made by tools/assets-api-update-selftest.php');
$copied = 0;
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if (str_starts_with($relative, $sep . '_auth_keys') === true || str_starts_with($relative, $sep . '_uploads') === true) {
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
    refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
@mkdir($child . $sep . 'web' . $sep . '_uploads', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/assets-api-update-selftest.php for ONE run.\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
    . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/assets-api-update-selftest.php for ONE run. Imitates web/public_html/.htaccess.
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

$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9101;
if ($port < 1024 || $port > 65535) {
    refuse('SELFTEST_WEB_PORT must be between 1024 and 65535');
}
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    refuse("something already answers on port {$port}; choose another with SELFTEST_WEB_PORT");
}
$jar = $child . $sep . 'cookies.txt';
$env = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open(
    [
        PHP_BINARY,
        '-d', 'session.save_path=' . $child . $sep . 'sessions',
        '-d', 'error_log=' . $child . $sep . 'php-errors.log',
        '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
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

/** One ordinary request: status and body. Redirects are NOT followed. */
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($h);
    return ['status' => (int) curl_getinfo($h, CURLINFO_HTTP_CODE), 'body' => $body === false ? '' : (string) $body];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
};
$answered = false;
for ($i = 0; $i < 80; $i++) {
    if ($http('GET', '/login')['status'] > 0) {
        $answered = true;
        break;
    }
    usleep(250000);
}
if ($answered === false) {
    refuse('the test server never answered');
}

$site = \Portal\Core\Site::preDetect($db);
if (\Portal\Core\Site::detectionFailed() === true) {
    refuse('the portal could not say which organisation it serves (Site::preDetect() failed)');
}

// ── Rows this run makes, removed at the end (and any a killed run left) ─────
$removeAll = static function () use ($db, $site): void {
    foreach (rows($db, 'SELECT `assetID` FROM `tblAssets` WHERE `siteID` = ? AND `name` LIKE ?', 'is', [$site, AU_PREFIX . '%']) as $r) {
        $id = (int) $r['assetID'];
        run($db, 'DELETE FROM `tblAssetMaintenance` WHERE `assetID` = ?', 'i', [$id]);
        run($db, 'DELETE FROM `tblAssetAudit` WHERE `assetID` = ?', 'i', [$id]);
        // Every asset change is mirrored into the platform's audit trail and
        // activity log (AssetRegister::audit()). Round 3 of the check found
        // both left behind: the trail rows were removed by nothing, and the
        // activity record written by section S's worker carries NO account
        // (it has no session), so a match on the administrator missed it.
        // Matched here by THIS asset's number, which only this run's assets
        // carry: the trail by table and record, the activity log by the
        // fixed opening AssetRegister::audit() writes ("Asset #N — …").
        run($db, "DELETE FROM `tblAuditTrail` WHERE `tableName` = 'tblAssets' AND `recordID` = ?", 'i', [$id]);
        run($db, "DELETE FROM `tblActivityLogs` WHERE `activityType` LIKE 'Asset%' AND `activityDescription` LIKE ?", 's', ['Asset #' . $id . ' %']);
        run($db, 'DELETE FROM `tblAssets` WHERE `assetID` = ?', 'i', [$id]);
    }
    // The race's fixture rows (their links from the assets are ON DELETE SET NULL).
    run($db, 'DELETE FROM `tblAssetCategories` WHERE `siteID` = ? AND `categoryName` LIKE ?', 'is', [$site, AU_PREFIX . '%']);
    run($db, 'DELETE FROM `tblAssetLocations` WHERE `siteID` = ? AND `locationName` LIKE ?', 'is', [$site, AU_PREFIX . '%']);
    // Signing in and each API call write activity records naming the
    // administrator; they hold a link to the account, so they go first.
    run($db, 'DELETE FROM `tblActivityLogs` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblAuditTrail` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [AU_ADMIN]);
};
$removeAll();
/**
 * The exact number of rows in EVERY table of this database. Taken once here and
 * again after the tidy-up at the end (Z2), so "removed at the end" is a
 * measurement rather than a claim (round 3 of the check found rows left in
 * tblActivityLogs and tblAuditTrail under that very comment).
 *
 * @return array<string, int>
 */
$tableCounts = static function () use ($db): array {
    $out = [];
    foreach (rows($db, "SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE' ORDER BY `TABLE_NAME`") as $r) {
        $table = (string) $r['t'];
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            continue;
        }
        $out[$table] = (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `' . $table . '`')[0]['n'] ?? 0);
    }
    return $out;
};
$countsBefore = $tableCounts();
register_shutdown_function(static function () use ($removeAll): void {
    try {
        $removeAll();
    } catch (Throwable $e) {
        fwrite(STDERR, 'tidy-up failed: ' . $e->getMessage() . "\n");
    }
});

// ── A global administrator, signed in ───────────────────────────────────────
$password = 'Au' . bin2hex(random_bytes(8)) . '!9x';
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 1, 1, NOW())',
    'iss', [AU_ADMIN, 'Selftest assets API', 'assets-api@selftest.invalid']);
run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$site, AU_ADMIN]);
run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss', [AU_ADMIN, 'selftest-assets-api', password_hash($password, PASSWORD_DEFAULT)]);
$in = $http('POST', '/login', ['csrf_token' => $tokenOn($http('GET', '/login')['body']), 'username' => 'selftest-assets-api', 'password' => $password]);
if ($in['status'] !== 302 && $in['status'] !== 303) {
    refuse("signing in did not answer a redirect (HTTP {$in['status']})");
}
$freshToken = static function (string $path) use ($http, $tokenOn): string {
    return $tokenOn($http('GET', $path)['body']);
};

/** Make an asset directly, with every field the checks below look at set. */
$makeAsset = static function (string $name, string $currency) use ($db, $site): int {
    run($db, "INSERT INTO `tblAssets` (`siteID`, `name`, `serialNumber`, `status`, `insuredValuePence`, `currency`, `publicToken`)"
        . " VALUES (?, ?, 'SN-ORIGINAL', 'in-service', 1000, ?, ?)", 'isss', [$site, $name, $currency, bin2hex(random_bytes(16))]);
    return (int) $db->insert_id;
};
$assetRow = static fn (int $id): array => rows($db,
    'SELECT `name`, `serialNumber`, `status`, `insuredValuePence`, `currency`, `manufacturer` FROM `tblAssets` WHERE `assetID` = ?', 'i', [$id])[0] ?? [];

/** One API request with a JSON body, its form token in the header too. */
$apiPost = static function (array $body) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . '/api/assets/update');
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . (string) ($body['csrf_token'] ?? '')],
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    $answer = (string) curl_exec($h);
    return ['status' => (int) curl_getinfo($h, CURLINFO_HTTP_CODE), 'body' => $answer];
};

/**
 * 🔎 THE PREMISE OF THE RACE: is some transaction WAITING on a lock that
 * $holder's own thread holds on the PRIMARY-key row $id of $table? Asked of
 * performance_schema.data_lock_waits joined to data_locks, so it names the
 * exact row and the exact holder (tools/currency-selftest.php's round-8 helper:
 * information_schema.INNODB_TRX is a cache and can show a previous waiter).
 */
$waitingOnRow = static function (string $table, int $id, mysqli $holder) use ($db): bool {
    $holderThread = (int) (rows($db, 'SELECT `THREAD_ID` AS `t` FROM `performance_schema`.`threads` WHERE `PROCESSLIST_ID` = ?', 'i', [$holder->thread_id])[0]['t'] ?? 0);
    if ($holderThread === 0) {
        return false;
    }

    return (int) (rows($db, 'SELECT COUNT(*) AS `n` FROM `performance_schema`.`data_lock_waits` `w` '
        . 'JOIN `performance_schema`.`data_locks` `l` ON `l`.`ENGINE_LOCK_ID` = `w`.`BLOCKING_ENGINE_LOCK_ID` '
        . "WHERE `l`.`OBJECT_SCHEMA` = DATABASE() AND `l`.`OBJECT_NAME` = ? AND `l`.`INDEX_NAME` = 'PRIMARY' AND `l`.`LOCK_DATA` = ? "
        . 'AND `w`.`BLOCKING_THREAD_ID` = ?', 'ssi', [$table, (string) $id, $holderThread])[0]['n'] ?? 0) > 0;
};
/** Begin a transaction in a SECOND connection and lock one asset row in it. */
$holdAsset = static function (int $id) use ($dbHost, $dbUser, $dbPass, $dbName, $dbPort): mysqli {
    $h = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $h->set_charset('utf8mb4');
    $h->begin_transaction();
    $st = $h->prepare('SELECT 1 FROM `tblAssets` WHERE `assetID` = ? FOR UPDATE');
    $st->bind_param('i', $id);
    $st->execute();
    $st->get_result()->fetch_all();
    $st->close();
    return $h;
};
/**
 * Send one API request while $holder holds the asset's lock; wait until the
 * database says the request is WAITING on it; run $meanwhile (which commits
 * through $holder and so lets go); then wait for the answer.
 *
 * @return array{seen: bool, status: int, body: string}
 */
$raceApi = static function (array $body, mysqli $holder, int $assetId, callable $meanwhile) use ($port, $jar, $waitingOnRow): array {
    $multi = curl_multi_init();
    $req   = curl_init('http://127.0.0.1:' . $port . '/api/assets/update');
    curl_setopt_array($req, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-TOKEN: ' . (string) ($body['csrf_token'] ?? '')],
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    curl_multi_add_handle($multi, $req);
    $seen    = false;
    $running = 0;
    for ($i = 0; $i < 100 && $seen === false; $i++) {
        curl_multi_exec($multi, $running);
        $seen = $waitingOnRow('tblAssets', $assetId, $holder);
        if ($seen === false) {
            usleep(100000);
        }
    }
    $meanwhile();
    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0);
    $out = ['seen' => $seen, 'status' => (int) curl_getinfo($req, CURLINFO_HTTP_CODE), 'body' => (string) curl_multi_getcontent($req)];
    curl_multi_remove_handle($multi, $req);
    curl_multi_close($multi);
    return $out;
};

echo "Assets API partial update (#1001)\n";

// =============================================================================
// The "other writer": a second connection that changes EVERY column
// =============================================================================
// Round 2 of the independent check (#1001): the first version of this race
// changed three columns of about forty, not including the issue's own example
// (moving the asset to another room), and exempting `locationID` and
// `isConfidential` from the partial-write rule left every check green. So the
// other writer now changes every column except the six below, and the row is
// compared WHOLE afterwards. R0 asks the SCHEMA which columns exist, so a column
// added to tblAssets later that this writer does not change fails R0 — the list
// below is the one thing a reader has to keep up to date, and it fails both ways.

/** The only tblAssets columns the other writer leaves alone, and why. */
const AU_NOT_CHANGED = [
    'assetID',   // the row's identity
    'siteID',    // which organisation it belongs to: moving it is not an edit
    'name',      // the field the API request IS sent; it must win
    'createdAt', // when it was made
    'updatedAt', // set by the database on every write, including the API's
    'isDeleted', // a deleted asset is a different question (see the notes)
];

// Fixture rows the race needs so every link can really change: a category,
// two rooms (the asset starts in the first; the other writer moves it to the
// second) and a parent asset to bundle it under.
run($db, 'INSERT INTO `tblAssetCategories` (`siteID`, `categoryName`) VALUES (?, ?)', 'is', [$site, AU_PREFIX . ' category']);
$fixCategory = (int) $db->insert_id;
run($db, 'INSERT INTO `tblAssetLocations` (`siteID`, `locationName`) VALUES (?, ?)', 'is', [$site, AU_PREFIX . ' room one']);
$fixRoomOne = (int) $db->insert_id;
run($db, 'INSERT INTO `tblAssetLocations` (`siteID`, `locationName`) VALUES (?, ?)', 'is', [$site, AU_PREFIX . ' room two']);
$fixRoomTwo = (int) $db->insert_id;
$fixParent = $makeAsset(AU_PREFIX . ' parent kit', 'EUR');

/** The whole row, every column, as the test's own connection sees it. */
$fullRow = static fn (int $id): array => rows($db, 'SELECT * FROM `tblAssets` WHERE `assetID` = ?', 'i', [$id])[0] ?? [];
/** A race asset: an ordinary one, standing in room one. */
$makeRaceAsset = static function (string $name) use ($db, $makeAsset, $fixRoomOne): int {
    $id = $makeAsset($name, 'EUR');
    run($db, 'UPDATE `tblAssets` SET `locationID` = ? WHERE `assetID` = ?', 'ii', [$fixRoomOne, $id]);
    return $id;
};
/**
 * Inside $holder's open transaction (it already holds the row's lock), change
 * every column but AU_NOT_CHANGED to something different, and answer the row
 * as $holder now sees it — what the row must still hold once the API is done.
 */
$changeEverything = static function (mysqli $holder, int $id) use ($fixCategory, $fixRoomTwo, $fixParent): array {
    $tag   = 'AU-' . bin2hex(random_bytes(6));
    $token = bin2hex(random_bytes(16));
    $admin = AU_ADMIN;
    $u = $holder->prepare(
        "UPDATE `tblAssets` SET `assetKind` = 'digital', `description` = 'Changed meanwhile', `categoryID` = ?, `locationID` = ?,"
        . " `manufacturer` = 'Other maker', `model` = 'Other model', `serialNumber` = 'SN-CHANGED-MEANWHILE', `assetTagCode` = ?,"
        . " `features` = 'Other features', `conditionState` = 'poor', `status` = 'in-repair', `purchaseDate` = '2020-01-02',"
        . " `purchaseStore` = 'Other shop', `purchaseCostPence` = 12345, `currency` = 'USD', `warrantyExpiry` = '2030-01-01',"
        . " `warrantyDetails` = 'Other warranty', `licenseKey` = 'not-a-real-cipher-text', `licenseSeats` = 7, `renewalDate` = '2031-02-03',"
        . " `accessUrl` = 'https://example.invalid/other', `depreciationMethod` = 'straight-line', `usefulLifeMonths` = 36,"
        . " `salvageValuePence` = 100, `currentValuePence` = 999, `valuationDate` = '2026-05-06', `insurerName` = 'Other insurer',"
        . " `insurancePolicyNumber` = 'POL-OTHER', `insuredValuePence` = 4321, `insuranceRenewalDate` = '2027-07-08',"
        . " `ownershipTerms` = 'Other terms', `isConfidential` = 1, `publicToken` = ?, `publicPageEnabled` = 0,"
        . " `labelSymbology` = 'code128', `parentAssetID` = ?, `createdByID` = ? WHERE `assetID` = ?"
    );
    $u->bind_param('iissiii', $fixCategory, $fixRoomTwo, $tag, $token, $fixParent, $admin, $id);
    $u->execute();
    $u->close();
    $s = $holder->prepare('SELECT * FROM `tblAssets` WHERE `assetID` = ?');
    $s->bind_param('i', $id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc() ?? [];
    $s->close();
    return $row;
};
/** The columns (except name and updatedAt) where $after differs from $expected. */
$differences = static function (array $expected, array $after): array {
    $out = [];
    foreach ($expected as $col => $value) {
        if ($col === 'name' || $col === 'updatedAt') {
            continue;
        }
        $a = $value === null ? null : (string) $value;
        $b = array_key_exists($col, $after) === false || $after[$col] === null ? null : (string) $after[$col];
        if ($a !== $b) {
            $out[$col] = ['should be' => $a, 'is' => $b];
        }
    }
    return $out;
};

// =============================================================================
// R. The race over HTTP: an API rename while somebody else changes every column
// =============================================================================
echo "\nSection R — a change made while the API request waits survives it\n";
$chair       = $makeRaceAsset(AU_PREFIX . ' chair');
$chairStart  = $fullRow($chair);
$chairToken  = $freshToken('/assets/edit?id=' . $chair);
$auditBefore = (int) (rows($db, 'SELECT COALESCE(MAX(`auditID`), 0) AS `m` FROM `tblAssetAudit` WHERE `assetID` = ?', 'i', [$chair])[0]['m'] ?? 0);
$holder      = $holdAsset($chair);
$chairExpected = [];
$race = $raceApi(['assetID' => $chair, 'name' => AU_PREFIX . ' chair renamed', 'csrf_token' => $chairToken], $holder, $chair,
    static function () use ($holder, $chair, $changeEverything, &$chairExpected): void {
        $chairExpected = $changeEverything($holder, $chair);
        $holder->commit();
        $holder->close();
    });
$chairRow = $fullRow($chair);
$unchanged = [];
foreach ($chairStart as $col => $value) {
    if (in_array($col, AU_NOT_CHANGED, true) === false
        && ($value === null ? null : (string) $value) === (($chairExpected[$col] ?? null) === null ? null : (string) $chairExpected[$col])) {
        $unchanged[] = $col;
    }
}
check('R0 the premise of the comparison: the other writer changed EVERY column of tblAssets except the six named in AU_NOT_CHANGED, and each of those six exists (asked of the schema, so a new column fails here)',
    count($chairStart) > 30 && $unchanged === [] && array_diff(AU_NOT_CHANGED, array_keys($chairStart)) === [],
    'columns the other writer left as they were: ' . json_encode($unchanged) . '; named but missing: ' . json_encode(array_values(array_diff(AU_NOT_CHANGED, array_keys($chairStart)))));
check('R1 the premise: the API request was seen WAITING on the lock this test holds on this asset row (it had already read the row)',
    $race['seen'] === true);
check('R2 the field the API WAS sent is written: the asset is renamed (HTTP 200)',
    $race['status'] === 200 && ($chairRow['name'] ?? '') === AU_PREFIX . ' chair renamed',
    'HTTP ' . $race['status'] . ' ' . mb_substr($race['body'], 0, 200));
$chairLost = $differences($chairExpected, $chairRow);
check('R3 EVERY column somebody else changed while the request waited SURVIVES it — room, category, kit, confidentiality, status, money, dates, texts and the rest — compared as the whole row',
    $chairExpected !== [] && $chairLost === [], 'put back or changed: ' . json_encode($chairLost));
check('R4 the issue\'s own example, named on its own: the asset moved to room two while the request waited is STILL in room two',
    (int) ($chairRow['locationID'] ?? 0) === $fixRoomTwo, 'locationID ' . var_export($chairRow['locationID'] ?? null, true) . ', room two is ' . $fixRoomTwo);
check('R5 and it is still marked confidential (a field whose loss would show it to people who should not see it)',
    (int) ($chairRow['isConfidential'] ?? 0) === 1, 'isConfidential ' . var_export($chairRow['isConfidential'] ?? null, true));
$raceAudit = rows($db, 'SELECT `changeSet` FROM `tblAssetAudit` WHERE `assetID` = ? AND `action` = \'update\' AND `auditID` > ? ORDER BY `auditID`', 'ii', [$chair, $auditBefore]);
$raceDiff  = json_decode((string) ($raceAudit[0]['changeSet'] ?? 'null'), true);
check('R6 the API call\'s audit record names ONLY the name as changed — it neither claims nor hides a change to anything the other writer changed',
    count($raceAudit) === 1 && is_array($raceDiff) === true && array_keys($raceDiff) === ['name'],
    json_encode($raceAudit));
// R7: the list of columns the race leaves alone is pinned WHOLE. Moving a
// column into it would let the race stop changing that column without R0
// noticing — and a column the race does not change is one R3 cannot see being
// put back. So such a move fails here and has to be a visible edit to this line.
check('R7 AU_NOT_CHANGED is exactly assetID, siteID, name, createdAt, updatedAt, isDeleted — a column moved into it could stop being raced without R0 noticing',
    AU_NOT_CHANGED === ['assetID', 'siteID', 'name', 'createdAt', 'updatedAt', 'isDeleted'], json_encode(AU_NOT_CHANGED));

// =============================================================================
// N. The other direction: somebody CLEARS every field while the request waits
// =============================================================================
// Round 3 of the check: R only ever SETS values on an asset that starts mostly
// empty, so a rule that falls back to the caller's stale value whenever the
// locked row's is empty — `$lockedRow[$col] ?? $fields[$col][0]`, the `??`
// idiom used all through updateAsset() — passed all 18 checks, while a field
// somebody had cleared on the edit page would be put back. Here the asset
// starts with EVERY column that may be empty filled in, the other writer
// empties all of them (asked of the schema, not listed by hand), and the row
// is compared whole afterwards.
echo "\nSection N — a field CLEARED while the API request waits stays cleared\n";
$nullable = array_values(array_filter(
    array_column(rows($db, "SELECT `COLUMN_NAME` AS `c` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblAssets' AND `IS_NULLABLE` = 'YES' ORDER BY `ORDINAL_POSITION`"), 'c'),
    static fn (string $c): bool => preg_match('/^[A-Za-z0-9_]+$/', $c) === 1
));
$cabinet = $makeRaceAsset(AU_PREFIX . ' cabinet');
$changeEverything($db, $cabinet); // fills every column, the empty-able ones included (autocommit)
$cabinetStart = $fullRow($cabinet);
$emptyAtStart = array_values(array_filter($nullable, static fn (string $c): bool => ($cabinetStart[$c] ?? null) === null));
check('N0 the premise: tblAssets has columns that may be empty (' . count($nullable) . '), and EVERY one of them is filled in on this asset before the race',
    count($nullable) >= 20 && $emptyAtStart === [], 'empty already: ' . json_encode($emptyAtStart));
$cabinetToken  = $freshToken('/assets/edit?id=' . $cabinet);
$cabinetAudit0 = (int) (rows($db, 'SELECT COALESCE(MAX(`auditID`), 0) AS `m` FROM `tblAssetAudit` WHERE `assetID` = ?', 'i', [$cabinet])[0]['m'] ?? 0);
$cabinetHolder = $holdAsset($cabinet);
$cabinetExpected = [];
$clearRace = $raceApi(['assetID' => $cabinet, 'name' => AU_PREFIX . ' cabinet renamed', 'csrf_token' => $cabinetToken], $cabinetHolder, $cabinet,
    static function () use ($cabinetHolder, $cabinet, $nullable, &$cabinetExpected): void {
        $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = NULL', $nullable));
        $u = $cabinetHolder->prepare('UPDATE `tblAssets` SET ' . $set . ' WHERE `assetID` = ?');
        $u->bind_param('i', $cabinet);
        $u->execute();
        $u->close();
        $s = $cabinetHolder->prepare('SELECT * FROM `tblAssets` WHERE `assetID` = ?');
        $s->bind_param('i', $cabinet);
        $s->execute();
        $cabinetExpected = $s->get_result()->fetch_assoc() ?? [];
        $s->close();
        $cabinetHolder->commit();
        $cabinetHolder->close();
    });
$cabinetRow = $fullRow($cabinet);
// array_key_exists, not `??`: `??` treats an emptied column as missing, which
// is the very confusion this section exists to catch (it made N1 red on a
// correct run when first written).
$stillFilled = array_values(array_filter($nullable,
    static fn (string $c): bool => array_key_exists($c, $cabinetExpected) === false || $cabinetExpected[$c] !== null));
check('N1 the premise: the API request was seen WAITING on this asset\'s lock, and the other writer really emptied every one of those columns',
    $clearRace['seen'] === true && $cabinetExpected !== [] && $stillFilled === [], 'not emptied: ' . json_encode($stillFilled));
check('N2 the field the API WAS sent is written: the asset is renamed (HTTP 200)',
    $clearRace['status'] === 200 && ($cabinetRow['name'] ?? '') === AU_PREFIX . ' cabinet renamed',
    'HTTP ' . $clearRace['status'] . ' ' . mb_substr($clearRace['body'], 0, 200));
$cabinetLost = $differences($cabinetExpected, $cabinetRow);
check('N3 every field somebody CLEARED while the request waited STAYS cleared — none is filled back in from the request\'s earlier read — compared as the whole row',
    $cabinetLost === [], 'filled back or changed: ' . json_encode($cabinetLost));
$clearAudit = rows($db, 'SELECT `changeSet` FROM `tblAssetAudit` WHERE `assetID` = ? AND `action` = \'update\' AND `auditID` > ? ORDER BY `auditID`', 'ii', [$cabinet, $cabinetAudit0]);
$clearDiff  = json_decode((string) ($clearAudit[0]['changeSet'] ?? 'null'), true);
check('N4 the API call\'s audit record names ONLY the name as changed',
    count($clearAudit) === 1 && is_array($clearDiff) === true && array_keys($clearDiff) === ['name'], json_encode($clearAudit));

// =============================================================================
// S. The re-read under the lock must be a LOCKING read (FOR UPDATE)
// =============================================================================
// Plant E of round 1: dropping FOR UPDATE from updateAsset()'s re-read under the
// lock turned NOTHING red, because today that read is the transaction's FIRST
// plain read and so takes its snapshot after the lock was granted. The round-2
// check showed the order is all that saves it: started WITH a consistent
// snapshot, a plain read sees the row as it was BEFORE the other writer
// committed, and the whole race fails. Currency::withParentLocked() is the one
// place that begins the transaction and it must not change for a test, so this
// section reaches the same state from OUTSIDE it: a separate PHP process loads
// the scratch copy's own start-up (the file every page loads), puts in its place
// a connection whose begin_transaction() always asks for a consistent snapshot
// (a subclass of mysqli; everything else is the portal's), and calls
// updateAsset() exactly as the API handler does — a read first, then a partial
// write naming only `name`. FOR UPDATE then decides the answer by itself.
echo "\nSection S — the row is re-read with a LOCKING read, whatever the transaction's snapshot\n";
$bench = $makeRaceAsset(AU_PREFIX . ' bench');
$worker = $child . $sep . 'snapshot-first-worker.php';
file_put_contents($worker, "<?php\n// Written by tools/assets-api-update-selftest.php for ONE run (#1001, section S).\n"
    . "declare(strict_types=1);\n"
    . "\$_SERVER['REQUEST_URI'] = '/'; \$_SERVER['HTTP_HOST'] = '127.0.0.1'; \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
    . 'require ' . var_export($child . $sep . 'web' . $sep . '_core' . $sep . 'bootstrap.php', true) . ";\n"
    . "final class SnapshotFirstMysqli extends mysqli\n{\n"
    . "    public function begin_transaction(int \$flags = 0, ?string \$name = null): bool\n    {\n"
    . "        return parent::begin_transaction(\$flags | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT, \$name);\n    }\n}\n"
    . '$conn = new SnapshotFirstMysqli(' . var_export($dbHost, true) . ', ' . var_export($dbUser, true) . ', ' . var_export($dbPass, true)
    . ', ' . var_export($dbName, true) . ', ' . var_export($dbPort, true) . ");\n"
    . "\$conn->set_charset('utf8mb4');\n"
    . "// The portal's own connection is strict (#593); this one is made the same.\n"
    . "\$mode = (string) (\$mysqli->query('SELECT @@SESSION.sql_mode AS `m`')->fetch_assoc()['m'] ?? '');\n"
    . "\$set = \$conn->prepare('SET SESSION sql_mode = ?'); \$set->bind_param('s', \$mode); \$set->execute(); \$set->close();\n"
    . "(new ReflectionProperty(\\Portal\\Core\\App::class, 'db'))->setValue(null, \$conn);\n"
    . "if (\\Portal\\Core\\App::db() !== \$conn) { echo 'NOT SWAPPED'; exit(3); }\n"
    . '$id = ' . $bench . ";\n"
    . "\$existing = \\Portal\\Core\\AssetRegister::get(\$id); // the handler's read, before the lock\n"
    . "if (\$existing === null) { echo 'NO ASSET'; exit(3); }\n"
    . "\$data = \$existing;\n"
    . "\$data['name'] = " . var_export(AU_PREFIX . ' bench renamed', true) . ";\n"
    . "\$data['currency'] = null;   // not sent, exactly as _coerce.php passes it\n"
    . "\$data['licenseKey'] = '';   // not sent: leave the stored key alone\n"
    . "echo \\Portal\\Core\\AssetRegister::updateAsset(\$id, \$data, " . AU_ADMIN . ", ['name']) === true ? 'UPDATED' : 'REFUSED';\n");
$benchHolder   = $holdAsset($bench);
$benchExpected = [];
$workerOut     = $child . $sep . 'snapshot-first-worker.out';
$workerProc    = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $worker],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $workerOut, 'w'], 2 => ['file', $workerOut, 'a']], $workerPipes, $child, $env);
$benchSeen = false;
for ($i = 0; $i < 100 && $benchSeen === false && is_resource($workerProc) === true && proc_get_status($workerProc)['running'] === true; $i++) {
    $benchSeen = $waitingOnRow('tblAssets', $bench, $benchHolder);
    if ($benchSeen === false) {
        usleep(100000);
    }
}
$benchExpected = $changeEverything($benchHolder, $bench);
$benchHolder->commit();
$benchHolder->close();
$workerExit = is_resource($workerProc) === true ? proc_close($workerProc) : -1;
$workerSaid = trim((string) @file_get_contents($workerOut));
$benchRow   = $fullRow($bench);
check('S1 the premise: the worker (the portal\'s start-up, with a connection that begins every transaction WITH a consistent snapshot) was seen WAITING on this asset\'s lock',
    $benchSeen === true, 'worker said: ' . mb_substr($workerSaid, 0, 300));
check('S2 updateAsset() renamed the asset from that worker',
    $workerExit === 0 && str_ends_with($workerSaid, 'UPDATED') === true && ($benchRow['name'] ?? '') === AU_PREFIX . ' bench renamed',
    'exit ' . $workerExit . ': ' . mb_substr($workerSaid, 0, 400));
$benchLost = $differences($benchExpected, $benchRow);
check('S3 even with the snapshot taken BEFORE the other writer committed, every column it changed SURVIVES — the re-read under the lock is a locking read (FOR UPDATE), which ignores the snapshot',
    $benchExpected !== [] && $benchLost === [], 'put back or changed: ' . json_encode($benchLost));

// =============================================================================
// P. A field sent is written; an explicit null clears; nothing else moves
// =============================================================================
echo "\nSection P — what is sent is written, and only that\n";
$desk = $makeAsset(AU_PREFIX . ' desk', 'EUR');
run($db, "UPDATE `tblAssets` SET `manufacturer` = 'Acme' WHERE `assetID` = ?", 'i', [$desk]);
$p1 = $apiPost(['assetID' => $desk, 'status' => 'retired', 'csrf_token' => $freshToken('/assets/edit?id=' . $desk)]);
$p1Row = $assetRow($desk);
check('P1 sending only the status writes the status and leaves the name, serial number, insured value, manufacturer and currency as they were',
    $p1['status'] === 200 && ($p1Row['status'] ?? '') === 'retired' && ($p1Row['name'] ?? '') === AU_PREFIX . ' desk'
    && ($p1Row['serialNumber'] ?? '') === 'SN-ORIGINAL' && (int) ($p1Row['insuredValuePence'] ?? 0) === 1000
    && ($p1Row['manufacturer'] ?? '') === 'Acme' && ($p1Row['currency'] ?? '') === 'EUR',
    'HTTP ' . $p1['status'] . ' ' . json_encode($p1Row) . ' ' . mb_substr($p1['body'], 0, 200));
$p2 = $apiPost(['assetID' => $desk, 'serialNumber' => null, 'csrf_token' => $freshToken('/assets/edit?id=' . $desk)]);
$p2Row = $assetRow($desk);
check('P2 sending the serial number as null CLEARS it (sent means written, even when the value is empty), and the manufacturer stays',
    $p2['status'] === 200 && array_key_exists('serialNumber', $p2Row) === true && $p2Row['serialNumber'] === null && ($p2Row['manufacturer'] ?? '') === 'Acme',
    'HTTP ' . $p2['status'] . ' ' . json_encode($p2Row));
$p3 = $apiPost(['assetID' => $desk, 'name' => '   ', 'csrf_token' => $freshToken('/assets/edit?id=' . $desk)]);
check('P3 a refusal still refuses: a blank name is a 400 and the name is unchanged',
    $p3['status'] === 400 && ($assetRow($desk)['name'] ?? '') === AU_PREFIX . ' desk', 'HTTP ' . $p3['status']);

// =============================================================================
// C. Currency keeps its own rules (#966, D-139)
// =============================================================================
echo "\nSection C — currency still goes through its own rules\n";
$lamp = $makeAsset(AU_PREFIX . ' lamp', 'EUR');
$c1 = $apiPost(['assetID' => $lamp, 'currency' => 'XYZ', 'csrf_token' => $freshToken('/assets/edit?id=' . $lamp)]);
check('C1 a currency the portal cannot use is a 400, and the asset is still EUR',
    $c1['status'] === 400 && ($assetRow($lamp)['currency'] ?? '') === 'EUR', 'HTTP ' . $c1['status'] . ' ' . mb_substr($c1['body'], 0, 200));
run($db, "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `title`, `costPence`, `currency`) VALUES (?, ?, 'Assets API selftest lamp repair', 300, 'EUR')", 'ii', [$site, $lamp]);
$c2 = $apiPost(['assetID' => $lamp, 'currency' => 'USD', 'status' => 'lost', 'csrf_token' => $freshToken('/assets/edit?id=' . $lamp)]);
$c2Row = $assetRow($lamp);
check('C2 a currency change the asset\'s records forbid (a repair in EUR) is a 409 with the reason, and NOTHING in the request is written (still EUR, still in-service)',
    $c2['status'] === 409 && str_contains($c2['body'], 'in another currency than USD') === true
    && ($c2Row['currency'] ?? '') === 'EUR' && ($c2Row['status'] ?? '') === 'in-service',
    'HTTP ' . $c2['status'] . ' ' . json_encode($c2Row) . ' ' . mb_substr($c2['body'], 0, 200));
$lantern = $makeAsset(AU_PREFIX . ' lantern', 'EUR');
$c3 = $apiPost(['assetID' => $lantern, 'currency' => 'usd', 'csrf_token' => $freshToken('/assets/edit?id=' . $lantern)]);
$c3Row = $assetRow($lantern);
check('C3 a permitted currency change is written (as USD), and nothing else moves',
    $c3['status'] === 200 && ($c3Row['currency'] ?? '') === 'USD' && ($c3Row['status'] ?? '') === 'in-service'
    && ($c3Row['serialNumber'] ?? '') === 'SN-ORIGINAL' && ($c3Row['name'] ?? '') === AU_PREFIX . ' lantern',
    'HTTP ' . $c3['status'] . ' ' . json_encode($c3Row) . ' ' . mb_substr($c3['body'], 0, 200));
$torch = $makeAsset(AU_PREFIX . ' torch', 'EUR');
$torchToken = $freshToken('/assets/edit?id=' . $torch);
$torchHolder = $holdAsset($torch);
$c4 = $raceApi(['assetID' => $torch, 'status' => 'in-repair', 'csrf_token' => $torchToken], $torchHolder, $torch,
    static function () use ($torchHolder, $torch): void {
        $u = $torchHolder->prepare("UPDATE `tblAssets` SET `currency` = 'USD' WHERE `assetID` = ?");
        $u->bind_param('i', $torch);
        $u->execute();
        $u->close();
        $torchHolder->commit();
        $torchHolder->close();
    });
$c4Row = $assetRow($torch);
check('C4 a request that did NOT send a currency, made while somebody changed it EUR -> USD, writes its status and keeps USD',
    $c4['seen'] === true && $c4['status'] === 200 && ($c4Row['status'] ?? '') === 'in-repair' && ($c4Row['currency'] ?? '') === 'USD',
    'seen ' . var_export($c4['seen'], true) . '; HTTP ' . $c4['status'] . ' ' . json_encode($c4Row));

// ── The server's own log: a PHP fault on any request above would be here ────
$serverErrors = is_file($child . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($child . $sep . 'php-errors.log')) : '';
check('Z1 the web server logged no PHP error or warning during the run',
    preg_match('/PHP (Fatal|Parse|Warning|Deprecated)/', $serverErrors) !== 1, mb_substr($serverErrors, 0, 400));

// ── The tidy-up, run now (and again, harmlessly, at shutdown), then measured ─
$removeAll();
$countsAfter = $tableCounts();
$leftBehind  = [];
foreach ($countsAfter + $countsBefore as $table => $unused) {
    if (($countsAfter[$table] ?? -1) !== ($countsBefore[$table] ?? -1)) {
        $leftBehind[$table] = ['before' => $countsBefore[$table] ?? null, 'after' => $countsAfter[$table] ?? null];
    }
}
check('Z2 the tidy-up removes every row this run made: each of the ' . count($countsBefore) . ' tables holds exactly as many rows as before the run',
    count($countsBefore) > 100 && $leftBehind === [], 'differs: ' . json_encode($leftBehind));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
