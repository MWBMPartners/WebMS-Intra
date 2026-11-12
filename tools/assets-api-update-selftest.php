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
 *      changes three OTHER fields (a text, an enum and a money figure) and
 *      commits. The rename must land AND all three other changes must survive.
 *      The audit record of the API call must name only what it changed.
 *   P  a field that IS sent is written, an explicit null clears it, and
 *      nothing else moves;
 *   C  currency still follows its own rules (#966, D-139): an unusable code is
 *      a 400, a change the asset's records forbid is a 409 with the reason and
 *      the asset untouched, a permitted change is written, and a currency
 *      changed by somebody else while the request waited survives a request
 *      that did not send one.
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - the edit page (`/assets/save`, a FULL write) is not raced here: it posts
 *     every field by design, so the last person to press Save wins, as it
 *     always has. tools/currency-selftest.php drives that page;
 *   - two API requests at once: the harness's web server runs one worker
 *     (`PHP_CLI_SERVER_WORKERS` is unset), so the "other writer" is this
 *     test's own connection, which is the same thing to the database;
 *   - MariaDB: performance_schema is OFF there by default, and this test
 *     REFUSES rather than guessing without it.
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
        run($db, 'DELETE FROM `tblAssets` WHERE `assetID` = ?', 'i', [$id]);
    }
    // Signing in and each API call write activity records naming the
    // administrator; they hold a link to the account, so they go first.
    run($db, 'DELETE FROM `tblActivityLogs` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [AU_ADMIN]);
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [AU_ADMIN]);
};
$removeAll();
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
// R. The race: an API rename while somebody else changes three other fields
// =============================================================================
echo "\nSection R — a change made while the API request waits survives it\n";
$chair = $makeAsset(AU_PREFIX . ' chair', 'EUR');
$chairToken = $freshToken('/assets/edit?id=' . $chair);
$auditBefore = (int) (rows($db, 'SELECT COALESCE(MAX(`auditID`), 0) AS `m` FROM `tblAssetAudit` WHERE `assetID` = ?', 'i', [$chair])[0]['m'] ?? 0);
$holder = $holdAsset($chair);
$race = $raceApi(['assetID' => $chair, 'name' => AU_PREFIX . ' chair renamed', 'csrf_token' => $chairToken], $holder, $chair,
    static function () use ($holder, $chair): void {
        // Somebody else: a stocktake sets the status, the edit page fixes the
        // serial number and raises the insured value. Text, enum and money.
        $u = $holder->prepare("UPDATE `tblAssets` SET `serialNumber` = 'SN-CHANGED-MEANWHILE', `status` = 'in-repair', `insuredValuePence` = 4321 WHERE `assetID` = ?");
        $u->bind_param('i', $chair);
        $u->execute();
        $u->close();
        $holder->commit();
        $holder->close();
    });
$chairRow = $assetRow($chair);
check('R1 the premise: the API request was seen WAITING on the lock this test holds on this asset row (it had already read the row)',
    $race['seen'] === true);
check('R2 the field the API WAS sent is written: the asset is renamed (HTTP 200)',
    $race['status'] === 200 && ($chairRow['name'] ?? '') === AU_PREFIX . ' chair renamed',
    'HTTP ' . $race['status'] . ' ' . json_encode($chairRow) . ' ' . mb_substr($race['body'], 0, 200));
check('R3 the serial number somebody else changed while the request waited SURVIVES (not put back to SN-ORIGINAL)',
    ($chairRow['serialNumber'] ?? '') === 'SN-CHANGED-MEANWHILE', json_encode($chairRow));
check('R4 the status somebody else changed while the request waited SURVIVES (still in-repair, not in-service)',
    ($chairRow['status'] ?? '') === 'in-repair', json_encode($chairRow));
check('R5 the insured value somebody else changed while the request waited SURVIVES (4321, not 1000)',
    (int) ($chairRow['insuredValuePence'] ?? 0) === 4321, json_encode($chairRow));
$raceAudit = rows($db, 'SELECT `changeSet` FROM `tblAssetAudit` WHERE `assetID` = ? AND `action` = \'update\' AND `auditID` > ? ORDER BY `auditID`', 'ii', [$chair, $auditBefore]);
$raceDiff  = json_decode((string) ($raceAudit[0]['changeSet'] ?? 'null'), true);
check('R6 the API call\'s audit record names ONLY the name as changed — it does not claim (or hide) a change to the serial number, status or insured value',
    count($raceAudit) === 1 && is_array($raceDiff) === true && array_keys($raceDiff) === ['name'],
    json_encode($raceAudit));

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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
