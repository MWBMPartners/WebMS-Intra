<?php
// Path: tools/assets-deleted-writes-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: nothing is written to an asset deleted while the write waited (#1047) 📦🗑️
 * -----------------------------------------------------------------------------
 * #1031 made AssetRegister::updateAsset() ask, UNDER the asset's lock, whether
 * the asset is still there. #1047 found the same gap in every other write that
 * changes one asset or something belonging to it: each asked self::get() (no
 * lock) and then wrote, so an asset soft-deleted in between was written anyway.
 * They now all ask AssetRegister::lockedLiveAsset() — the ONE locked question —
 * inside the transaction that writes.
 *
 * For EVERY such write (the list is CASES below) this test proves, against a
 * real database, with the portal's own start-up and the real method:
 *
 *   A  CONTROL. On a live asset the write lands. Without this, every refusal
 *      below could be a broken fixture rather than the guard.
 *   B  BEFORE. The asset is deleted first; the method refuses and nothing is
 *      written. (For most writes the method's own self::get() already refused
 *      this; for the loans, the identifier and owner changes and the repairs
 *      edit it did NOT, and B is the new guard's.)
 *   W  WHILE IT WAITED. This test holds the asset row's lock in a SECOND
 *      connection; the method runs in its own PHP process and is seen WAITING
 *      on that lock (asked of performance_schema, never slept on — W0); the
 *      second connection then deletes the asset and commits; the method must
 *      refuse and write nothing. Two real connections, as #1031's section D.
 *   P  PLANTED. The same race against a copy of the portal in which THAT
 *      method's lock no longer asks about deletion (the lock is still taken —
 *      the shape #1031 fixed). The write must then LAND, which proves W can
 *      fail: a W that passed on the planted copy would be guarding nothing.
 *
 * Plus a kit's component deleted while the kit is being checked out (the
 * component is skipped and gets no loan), and Z: the tidy-up leaves every
 * table exactly as it found it.
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - the pages. It drives AssetRegister's methods as the pages call them, in
 *     a process that loads the portal's own start-up, not over HTTP;
 *     tools/assets-api-update-selftest.php drives the API and the edit page for
 *     updateAsset() (#1031). What a page then SAYS is each page's own message
 *     for the method's ordinary failure.
 *   - the kit-component case has no B: kitChildren() already leaves a deleted
 *     component out, without any lock, so a "deleted before" check would pass
 *     whatever the new code did;
 *   - the writes judged NOT to need the refusal (the ones that END something —
 *     declining, cancelling and checking in a loan, releasing a seat, removing
 *     an identifier, owner, resource, repair or event booking, detaching a kit
 *     component — and the public scan and found-report records). Their reasons
 *     are written beside AssetRegister::loanAction() and in #1047;
 *   - MariaDB: performance_schema is OFF there by default, and this test
 *     REFUSES rather than guessing without it.
 *
 * Run it on a THROWAWAY database built from web/_sql/full_schema.sql:
 *     SELFTEST_DB_NAME=selftest_assetsdel [SELFTEST_DB_HOST=… SELFTEST_DB_PORT=…
 *     SELFTEST_DB_USER=… SELFTEST_DB_PASS=…] php tools/assets-deleted-writes-selftest.php
 * It needs proc_open, and performance_schema ON and readable, and REFUSES
 * rather than skipping without either.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1047
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up member every write is made by. Nothing else in tools/ uses 10047xx. */
const AD_USER = 1004701;
/** Every asset (and event and stocktake) this test makes is named with this. */
const AD_PREFIX = 'Assets deleted selftest';

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);
$pass = 0;
$fail = 0;
$state = ['dirs' => []];

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
if (function_exists('proc_open') === false) {
    refuse('this test needs proc_open(), which this PHP does not have');
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

/** One number out of one query. */
function one(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    return (int) (array_values(rows($db, $sql, $types, $params)[0] ?? [0])[0] ?? 0);
}

$tables = one($db, 'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()');
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}
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
// Two copies of web/: the portal as it is, and one to plant a fault into
// =============================================================================
$base = sys_get_temp_dir() . $sep . 'assets-deleted-writes-selftest-' . bin2hex(random_bytes(6));
$state['dirs'][] = $base;
register_shutdown_function(static function () use (&$state): void {
    foreach ($state['dirs'] as $dir) {
        if (is_file($dir . DIRECTORY_SEPARATOR . 'assets-deleted-writes-selftest.marker') === false) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
});
mkdir($base, 0700, true);
file_put_contents($base . $sep . 'assets-deleted-writes-selftest.marker', 'made by tools/assets-deleted-writes-selftest.php');

/** Copy web/ into $to/web, with this run's credentials. */
$copyWeb = static function (string $to) use ($repo, $sep, $dbHost, $dbUser, $dbPass, $dbName, $dbPort): void {
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
        $target = $to . $sep . 'web' . $relative;
        if ($entry->isDir() === true) {
            @mkdir($target, 0700, true);
            continue;
        }
        copy($entry->getPathname(), $target);
        $copied++;
    }
    if ($copied < 100) {
        refuse("the copy of web/ holds only {$copied} files");
    }
    @mkdir($to . $sep . 'web' . $sep . '_auth_keys', 0700, true);
    @mkdir($to . $sep . 'web' . $sep . '_uploads', 0700, true);
    file_put_contents(
        $to . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
        "<?php\n// Written by tools/assets-deleted-writes-selftest.php for ONE run.\nreturn "
        . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true)
        . ";\n"
    );
    file_put_contents($to . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
};
$liveDir  = $base . $sep . 'live';
$plantDir = $base . $sep . 'planted';
$copyWeb($liveDir);
$copyWeb($plantDir);
$liveRegister = (string) file_get_contents($liveDir . $sep . 'web' . $sep . '_core' . $sep . 'AssetRegister.php');

/**
 * 🌱 Plant the fault into ONE method of the planted copy: its lock is still
 * taken, but no longer asks whether the asset is deleted — the shape #1031
 * fixed. Every other method stays exactly as committed. Answers how many
 * calls were swapped (0 means the plant did not apply, which is reported).
 */
$plant = static function (string $method) use ($liveRegister, $plantDir, $sep): int {
    $start = strpos($liveRegister, 'function ' . $method . '(');
    if ($start === false) {
        return 0;
    }
    $end = strpos($liveRegister, "\n    }\n", $start);
    if ($end === false) {
        return 0;
    }
    $body    = substr($liveRegister, $start, $end - $start);
    $swapped = 0;
    $body = str_replace(
        ['self::withLiveAssetLocked(', 'self::withLiveAssetsLocked(', 'self::lockedLiveAsset('],
        ['self::plantedLockAnyway(', 'self::plantedLocksAnyway(', 'self::plantedLockedRow('],
        $body,
        $swapped
    );
    $planted = substr($liveRegister, 0, $start) . $body . substr($liveRegister, $end);
    $helpers = <<<'PLANT'

    // 🌱 Planted by tools/assets-deleted-writes-selftest.php (#1047): the lock
    //    WITHOUT the deletion question. Exists only in the test's planted copy.
    private static function plantedLockedRow(\mysqli $db, int $assetId, int $siteId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM `tblAssets` WHERE `assetID` = ? AND `siteID` = ? FOR UPDATE');
        $stmt->bind_param('ii', $assetId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) === true ? $row : null;
    }
    private static function plantedLocksAnyway(\mysqli $db, array $assetIds, int $siteId, callable $work): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $assetIds)));
        sort($ids);
        $db->begin_transaction();
        try {
            $rows = [];
            foreach ($ids as $id) {
                $row = self::plantedLockedRow($db, $id, $siteId);
                if ($row === null) {
                    $db->rollback();
                    return null;
                }
                $rows[$id] = $row;
            }
            $result = $work($rows);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
    private static function plantedLockAnyway(\mysqli $db, int $assetId, int $siteId, callable $work): mixed
    {
        return self::plantedLocksAnyway($db, [$assetId], $siteId, static fn (array $rows): mixed => $work($rows[$assetId]));
    }
}
PLANT;
    $close = strrpos($planted, "\n}");
    $planted = substr($planted, 0, $close) . $helpers . "\n";
    file_put_contents($plantDir . $sep . 'web' . $sep . '_core' . $sep . 'AssetRegister.php', $planted);

    return $swapped;
};

// ── The worker: the portal's own start-up, then ONE AssetRegister call ──────
$worker = $base . $sep . 'worker.php';
file_put_contents($worker, <<<'WORKER'
<?php
// Written by tools/assets-deleted-writes-selftest.php for ONE run (#1047).
declare(strict_types=1);
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['HTTP_HOST'] = '127.0.0.1'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $argv[1];
use Portal\Core\AssetRegister as R;
$j = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
$u = (int) $j['user'];
$a = (int) ($j['a'] ?? 0);
$result = match ($j['op']) {
    'token'        => R::regeneratePublicToken($a, $u),
    'terms'        => R::updateOwnershipTerms($a, 'Terms written by the selftest', $u),
    'identAdd'     => R::addIdentifier($a, ['typeCode' => 'OTHER', 'value' => 'V-' . bin2hex(random_bytes(4))], $u),
    'identPrimary' => R::setPrimaryIdentifier((int) $j['i'], $a, $u),
    'identVerify'  => R::verifyIdentifier((int) $j['i'], $a, $u),
    'resource'     => R::addResource($a, ['resourceType' => 'manual', 'title' => 'Selftest manual', 'linkUrl' => 'https://example.invalid/manual'], $u),
    'owner'        => R::addOwner($a, ['partyType' => 'user', 'userID' => $u, 'roleKind' => 'custodian'], $u),
    'authority'    => R::setOwnerAuthority((int) $j['o'], $a, 'isMaintenanceAuthority', true, $u),
    'loanReq'      => R::createLoanRequest($a, ['direction' => 'out', 'counterpartyType' => 'other', 'counterpartyName' => 'Selftest borrower'], $u),
    'approve'      => R::loanAction((int) $j['l'], $a, 'approve', [], $u),
    'checkout'     => R::loanAction((int) $j['l'], $a, 'checkout', [], $u),
    'attach'       => R::attachToKit((int) $j['c'], (int) $j['p'], $u),
    'maintAdd'     => R::addMaintenance($a, ['maintType' => 'service', 'title' => 'Selftest service', 'status' => 'completed'], $u),
    'maintEdit'    => R::updateMaintenance((int) $j['m'], $a, ['maintType' => 'repair', 'title' => 'Changed by selftest', 'status' => 'completed'], $u),
    'seat'         => R::assignSeat($a, ['deviceName' => 'Selftest laptop'], $u),
    'seatDevice'   => R::assignSeat($a, ['deviceAssetID' => (int) $j['d']], $u),
    'event'        => R::assignToEvent($a, ['eventID' => (int) $j['e']], $u),
    'stocktake'    => R::recordStocktakeScan((int) $j['s'], (string) $j['code'], null, $u),
    'kiosk'        => R::kioskCheckout($a, $u, 0),
    'persist'      => R::persistCurrentValues(\Portal\Core\Site::id()),
    'snapshot'     => (new ReflectionMethod(R::class, 'recordValueSnapshot'))->invoke(null, \Portal\Core\Site::id(), $a, date('Y-m-d'), 4321, 'manual', 'manual', null),
    default        => 'UNKNOWN OP',
};
echo "\nRESULT:" . json_encode($result);
WORKER);

$env = getenv();
$env['PORTAL_ENV'] = 'prod';

$site = \Portal\Core\Site::preDetect($db);
if (\Portal\Core\Site::detectionFailed() === true) {
    refuse('the portal could not say which organisation it serves (Site::preDetect() failed)');
}

// =============================================================================
// Rows this run makes: everything above these watermarks, removed at the end
// =============================================================================
const AD_TABLES = [
    // Children before parents, so no link refuses the delete.
    'tblAssetStocktakeItems'     => 'itemID',
    'tblAssetStocktakes'         => 'stocktakeID',
    'tblAssetValueHistory'       => 'valueID',
    'tblAssetEventAssignments'   => 'assignmentID',
    'tblAssetLicenseAssignments' => 'assignmentID',
    'tblAssetMaintenance'        => 'maintID',
    'tblAssetOwners'             => 'ownerID',
    'tblAssetResources'          => 'resourceID',
    'tblAssetIdentifiers'        => 'identifierID',
    'tblAssetLoans'              => 'loanID',
    'tblAssetAudit'              => 'auditID',
    'tblAuditTrail'              => 'auditID',
    'tblActivityLogs'            => 'logID',
    'tblEvents'                  => 'eventID',
    'tblAssets'                  => 'assetID',
];
$watermarks = [];
foreach (AD_TABLES as $table => $key) {
    $watermarks[$table] = one($db, 'SELECT COALESCE(MAX(`' . $key . '`), 0) FROM `' . $table . '`');
}
$removeAll = static function () use ($db, $watermarks): void {
    run($db, 'UPDATE `tblAssets` SET `parentAssetID` = NULL WHERE `assetID` > ?', 'i', [$watermarks['tblAssets']]);
    run($db, 'UPDATE `tblAssetLoans` SET `parentLoanID` = NULL WHERE `loanID` > ?', 'i', [$watermarks['tblAssetLoans']]);
    foreach (AD_TABLES as $table => $key) {
        run($db, 'DELETE FROM `' . $table . '` WHERE `' . $key . '` > ?', 'i', [$watermarks[$table]]);
    }
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [AD_USER]);
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [AD_USER]);
};
run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [AD_USER]);
run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [AD_USER]);
$tableCounts = static function () use ($db): array {
    $out = [];
    foreach (rows($db, "SELECT `TABLE_NAME` AS `t` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE' ORDER BY `TABLE_NAME`") as $r) {
        $table = (string) $r['t'];
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) {
            $out[$table] = one($db, 'SELECT COUNT(*) FROM `' . $table . '`');
        }
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

// ── The member every write is made by (on this organisation) ────────────────
run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `createdAt`) VALUES (?, ?, ?, 1, NOW())',
    'iss', [AD_USER, 'Selftest deleted assets', 'assets-deleted@selftest.invalid']);
run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$site, AD_USER]);

// =============================================================================
// Fixtures
// =============================================================================
$n = 0;
/** A fresh, live, in-service asset. */
$asset = static function (string $label, array $extra = []) use ($db, $site, &$n): int {
    $n++;
    $cols = ['siteID' => $site, 'name' => AD_PREFIX . ' ' . $label . ' ' . $n, 'status' => 'in-service', 'currency' => 'GBP',
        'publicToken' => bin2hex(random_bytes(16)), 'assetTagCode' => 'AD-' . bin2hex(random_bytes(5))] + $extra;
    $names = array_keys($cols);
    $types = '';
    foreach ($cols as $v) {
        $types .= is_int($v) === true ? 'i' : 's';
    }
    run($db, 'INSERT INTO `tblAssets` (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
        $types, array_values($cols));
    return (int) $db->insert_id;
};
$insertId = static function (string $sql, string $types, array $params) use ($db): int {
    run($db, $sql, $types, $params);
    return (int) $db->insert_id;
};
$identifier = static fn (int $a): int => $insertId(
    "INSERT INTO `tblAssetIdentifiers` (`siteID`, `assetID`, `typeCode`, `value`, `isPrimary`, `isVerified`) VALUES (?, ?, 'OTHER', ?, 0, 0)",
    'iis', [$site, $a, 'ID-' . bin2hex(random_bytes(4))]);
$lender = static fn (int $a): int => $insertId(
    "INSERT INTO `tblAssetOwners` (`siteID`, `assetID`, `partyType`, `userID`, `roleKind`, `isLendingAuthority`, `isMaintenanceAuthority`) VALUES (?, ?, 'user', ?, 'custodian', 1, 0)",
    'iii', [$site, $a, AD_USER]);
$requested = static fn (int $a): int => $insertId(
    "INSERT INTO `tblAssetLoans` (`siteID`, `assetID`, `direction`, `counterpartyType`, `counterpartyName`, `status`, `requestedByID`) VALUES (?, ?, 'out', 'other', 'Selftest borrower', 'requested', ?)",
    'iii', [$site, $a, AD_USER]);
$repair = static fn (int $a): int => $insertId(
    "INSERT INTO `tblAssetMaintenance` (`siteID`, `assetID`, `maintType`, `title`, `status`, `currency`) VALUES (?, ?, 'service', 'Selftest original title', 'completed', 'GBP')",
    'ii', [$site, $a]);
$event = static fn (): int => $insertId(
    "INSERT INTO `tblEvents` (`siteID`, `eventName`, `eventSlug`, `startDateTime`, `timezone`, `status`) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), 'Europe/London', 'published')",
    'iss', [$site, AD_PREFIX . ' event', 'ad-selftest-' . bin2hex(random_bytes(5))]);
$stocktake = static fn (): int => $insertId(
    "INSERT INTO `tblAssetStocktakes` (`siteID`, `label`, `status`, `startedByID`) VALUES (?, ?, 'open', ?)",
    'isi', [$site, AD_PREFIX . ' stocktake', AD_USER]);
$field = static fn (string $table, string $col, string $key, int $id): mixed
    => rows($db, 'SELECT `' . $col . '` AS `v` FROM `' . $table . '` WHERE `' . $key . '` = ?', 'i', [$id])[0]['v'] ?? null;
$count = static fn (string $table, string $col, int $id): int
    => one($db, 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $col . '` = ?', 'i', [$id]);

/**
 * THE CASES — every write judged to need the refusal (#1047). Each has:
 *   make    builds a fresh world; answers the job for the worker ('a' etc.)
 *           plus 'del', the asset this case deletes and races on;
 *   landed  did the write reach the database? (asked of the database)
 *   refused did the method SAY it refused? (asked of its answer)
 *   method  the AssetRegister method whose lock the plant reaches.
 */
$CASES = [
    'regeneratePublicToken' => [
        'make' => static function () use ($asset, $field): array {
            $a = $asset('token');
            return ['op' => 'token', 'a' => $a, 'del' => $a, 'was' => $field('tblAssets', 'publicToken', 'assetID', $a)];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssets', 'publicToken', 'assetID', $j['a']) !== $j['was'],
        'refused' => static fn (mixed $r): bool => $r === null,
        'method'  => 'regeneratePublicToken',
    ],
    'updateOwnershipTerms' => [
        'make' => static function () use ($asset): array {
            $a = $asset('terms');
            return ['op' => 'terms', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssets', 'ownershipTerms', 'assetID', $j['a']) === 'Terms written by the selftest',
        'refused' => static fn (mixed $r): bool => $r === false,
        'method'  => 'updateOwnershipTerms',
    ],
    'addIdentifier' => [
        'make' => static function () use ($asset): array {
            $a = $asset('identifier');
            return ['op' => 'identAdd', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetIdentifiers', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => (int) ($r['id'] ?? -1) === 0,
        'method'  => 'addIdentifier',
    ],
    'setPrimaryIdentifier' => [
        'make' => static function () use ($asset, $identifier): array {
            $a = $asset('primary');
            return ['op' => 'identPrimary', 'a' => $a, 'i' => $identifier($a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => (int) $field('tblAssetIdentifiers', 'isPrimary', 'identifierID', $j['i']) === 1,
        'refused' => static fn (mixed $r): bool => $r === false,
        'method'  => 'setPrimaryIdentifier',
    ],
    'verifyIdentifier' => [
        'make' => static function () use ($asset, $identifier): array {
            $a = $asset('verify');
            return ['op' => 'identVerify', 'a' => $a, 'i' => $identifier($a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssetIdentifiers', 'verifiedAt', 'identifierID', $j['i']) !== null,
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'applyVerifyResult',
    ],
    'addResource' => [
        'make' => static function () use ($asset): array {
            $a = $asset('resource');
            return ['op' => 'resource', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetResources', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => $r === 0,
        'method'  => 'addResource',
    ],
    'addOwner' => [
        'make' => static function () use ($asset): array {
            $a = $asset('owner');
            return ['op' => 'owner', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetOwners', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => $r === 0,
        'method'  => 'addOwner',
    ],
    'setOwnerAuthority' => [
        'make' => static function () use ($asset, $lender): array {
            $a = $asset('authority');
            return ['op' => 'authority', 'a' => $a, 'o' => $lender($a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => (int) $field('tblAssetOwners', 'isMaintenanceAuthority', 'ownerID', $j['o']) === 1,
        'refused' => static fn (mixed $r): bool => $r === false,
        'method'  => 'setOwnerAuthority',
    ],
    'createLoanRequest' => [
        'make' => static function () use ($asset): array {
            $a = $asset('loan request');
            return ['op' => 'loanReq', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetLoans', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => $r === 0,
        'method'  => 'createLoanRequest',
    ],
    'loanAction approve' => [
        'make' => static function () use ($asset, $lender, $requested): array {
            $a = $asset('approve');
            $lender($a);
            return ['op' => 'approve', 'a' => $a, 'l' => $requested($a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssetLoans', 'status', 'loanID', $j['l']) === 'approved',
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'loanApprove',
    ],
    'loanAction checkout' => [
        'make' => static function () use ($asset, $lender, $requested): array {
            $a = $asset('checkout');
            $lender($a);
            return ['op' => 'checkout', 'a' => $a, 'l' => $requested($a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssetLoans', 'status', 'loanID', $j['l']) === 'active'
            || $field('tblAssets', 'status', 'assetID', $j['a']) === 'on-loan',
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'loanCheckout',
    ],
    'attachToKit (the component deleted)' => [
        'make' => static function () use ($asset): array {
            $c = $asset('component');
            $p = $asset('kit');
            return ['op' => 'attach', 'c' => $c, 'p' => $p, 'del' => $c];
        },
        'landed'  => static fn (array $j): bool => (int) $field('tblAssets', 'parentAssetID', 'assetID', $j['c']) === $j['p'],
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'attachToKit',
    ],
    'attachToKit (the kit deleted)' => [
        'make' => static function () use ($asset): array {
            $c = $asset('component');
            $p = $asset('kit');
            return ['op' => 'attach', 'c' => $c, 'p' => $p, 'del' => $p];
        },
        'landed'  => static fn (array $j): bool => (int) $field('tblAssets', 'parentAssetID', 'assetID', $j['c']) === $j['p'],
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'attachToKit',
    ],
    'addMaintenance' => [
        'make' => static function () use ($asset): array {
            $a = $asset('repair add');
            return ['op' => 'maintAdd', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetMaintenance', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => $r === 0,
        'method'  => 'addMaintenance',
    ],
    'updateMaintenance' => [
        'make' => static function () use ($asset, $repair): array {
            $a = $asset('repair edit');
            return ['op' => 'maintEdit', 'a' => $a, 'm' => $repair($a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssetMaintenance', 'title', 'maintID', $j['m']) === 'Changed by selftest',
        'refused' => static fn (mixed $r): bool => $r === false,
        'method'  => 'updateMaintenance',
    ],
    'assignSeat (the licence deleted)' => [
        'make' => static function () use ($asset): array {
            $a = $asset('licence', ['assetKind' => 'digital']);
            return ['op' => 'seat', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetLicenseAssignments', 'licenseAssetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => (int) ($r['id'] ?? -1) === 0,
        'method'  => 'assignSeat',
    ],
    'assignSeat (the device deleted)' => [
        'make' => static function () use ($asset): array {
            $a = $asset('licence', ['assetKind' => 'digital']);
            $d = $asset('device');
            return ['op' => 'seatDevice', 'a' => $a, 'd' => $d, 'del' => $d];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetLicenseAssignments', 'deviceAssetID', $j['d']) > 0,
        'refused' => static fn (mixed $r): bool => (int) ($r['id'] ?? -1) === 0,
        'method'  => 'assignSeat',
    ],
    'assignToEvent' => [
        'make' => static function () use ($asset, $event): array {
            $a = $asset('event');
            return ['op' => 'event', 'a' => $a, 'e' => $event(), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetEventAssignments', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => (int) ($r['id'] ?? -1) === 0,
        'method'  => 'assignToEvent',
    ],
    'recordStocktakeScan' => [
        'make' => static function () use ($asset, $stocktake, $field): array {
            $a = $asset('stocktake');
            return ['op' => 'stocktake', 'a' => $a, 's' => $stocktake(), 'code' => (string) $field('tblAssets', 'assetTagCode', 'assetID', $a), 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetStocktakeItems', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'recordStocktakeScan',
    ],
    'kioskCheckout' => [
        'make' => static function () use ($asset): array {
            $a = $asset('kiosk');
            return ['op' => 'kiosk', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetLoans', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => ($r['ok'] ?? null) === false,
        'method'  => 'kioskCheckout',
    ],
    'persistCurrentValues' => [
        'make' => static function () use ($asset): array {
            $a = $asset('valuation', ['depreciationMethod' => 'straight-line', 'purchaseCostPence' => 120000,
                'usefulLifeMonths' => 36, 'purchaseDate' => '2025-01-01', 'salvageValuePence' => 0]);
            return ['op' => 'persist', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $field('tblAssets', 'valuationDate', 'assetID', $j['a']) !== null
            || $count('tblAssetValueHistory', 'assetID', $j['a']) > 0,
        // It answers a count for the whole register, not a yes or no for this asset.
        'refused' => static fn (mixed $r): bool => is_int($r) === true,
        'method'  => 'persistCurrentValues',
    ],
    'recordValueSnapshot' => [
        'make' => static function () use ($asset): array {
            $a = $asset('snapshot');
            return ['op' => 'snapshot', 'a' => $a, 'del' => $a];
        },
        'landed'  => static fn (array $j): bool => $count('tblAssetValueHistory', 'assetID', $j['a']) > 0,
        'refused' => static fn (mixed $r): bool => $r === false,
        'method'  => 'recordValueSnapshot',
    ],
];

// =============================================================================
// The race machinery (#1031's section D, with the method in its own process)
// =============================================================================
$waitingOnRow = static function (string $table, int $id, mysqli $holder) use ($db): bool {
    $holderThread = one($db, 'SELECT `THREAD_ID` FROM `performance_schema`.`threads` WHERE `PROCESSLIST_ID` = ?', 'i', [$holder->thread_id]);
    if ($holderThread === 0) {
        return false;
    }
    return one($db, 'SELECT COUNT(*) FROM `performance_schema`.`data_lock_waits` `w` '
        . 'JOIN `performance_schema`.`data_locks` `l` ON `l`.`ENGINE_LOCK_ID` = `w`.`BLOCKING_ENGINE_LOCK_ID` '
        . "WHERE `l`.`OBJECT_SCHEMA` = DATABASE() AND `l`.`OBJECT_NAME` = ? AND `l`.`INDEX_NAME` = 'PRIMARY' AND `l`.`LOCK_DATA` = ? "
        . 'AND `w`.`BLOCKING_THREAD_ID` = ?', 'ssi', [$table, (string) $id, $holderThread]) > 0;
};
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
$deleteAsset = static function (mysqli $conn, int $id): void {
    // Exactly what AssetRegister::softDeleteAsset() writes.
    $st = $conn->prepare('UPDATE `tblAssets` SET `isDeleted` = 1 WHERE `assetID` = ? AND `isDeleted` = 0');
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
};
/**
 * Run the job in a worker against $dir's copy of the portal. With $holder,
 * wait until the worker is seen WAITING on $raceId's lock, delete it through
 * $holder and commit, then wait for the worker.
 *
 * @return array{seen: bool, exit: int, result: mixed, said: string}
 */
$runJob = static function (array $job, string $dir, ?mysqli $holder = null, int $raceId = 0)
    use ($worker, $base, $env, $sep, $waitingOnRow, $deleteAsset): array {
    $job['user'] = AD_USER;
    $out  = $base . $sep . 'worker-' . bin2hex(random_bytes(4)) . '.out';
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $worker,
        $dir . $sep . 'web' . $sep . '_core' . $sep . 'bootstrap.php', json_encode($job)],
        // stdout and stderr to SEPARATE files: two handles on one file each
        // keep their own position, so the method's error_log() lines and the
        // answer overwrote each other (measured: the answer read as null).
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, $base, $env);
    $seen = false;
    if ($holder !== null) {
        for ($i = 0; $i < 150 && $seen === false && proc_get_status($proc)['running'] === true; $i++) {
            $seen = $waitingOnRow('tblAssets', $raceId, $holder);
            if ($seen === false) {
                usleep(100000);
            }
        }
        $deleteAsset($holder, $raceId);
        $holder->commit();
        $holder->close();
    }
    $exit = proc_close($proc);
    $printed = (string) @file_get_contents($out);
    $said    = trim((string) @file_get_contents($out . '.err'));
    @unlink($out);
    @unlink($out . '.err');
    $pos = strrpos($printed, "\nRESULT:");
    $result = $pos === false ? '(no result)' : json_decode(substr($printed, $pos + 8), true);
    $said = trim(($pos === false ? $printed : substr($printed, 0, $pos)) . "\n" . $said);

    return ['seen' => $seen, 'exit' => $exit, 'result' => $result, 'said' => $said];
};
$short = static fn (array $r): string => 'exit ' . $r['exit'] . '; answer ' . json_encode($r['result'])
    . ($r['said'] !== '' ? '; said ' . mb_substr($r['said'], 0, 300) : '');

echo "Nothing is written to an asset deleted while the write waited (#1047)\n";

foreach ($CASES as $name => $case) {
    echo "\n{$name}\n";

    // A — control: a live asset, the write lands.
    $job = ($case['make'])();
    $r   = $runJob($job, $liveDir);
    check("{$name} A: on a live asset the write lands (the fixture really reaches the write)",
        // (persistCurrentValues() answers a count for the whole register, never
        //  a refusal for one asset, so only what landed is asked of it.)
        $r['exit'] === 0 && ($name === 'persistCurrentValues' || ($case['refused'])($r['result']) === false) && ($case['landed'])($job) === true,
        $short($r));

    // B — deleted before: refused, nothing written.
    $job = ($case['make'])();
    $deleteAsset($db, (int) $job['del']);
    $r = $runJob($job, $liveDir);
    check("{$name} B: an asset deleted BEFORE the call is refused and nothing is written",
        $r['exit'] === 0 && ($case['refused'])($r['result']) === true && ($case['landed'])($job) === false, $short($r));

    // W — deleted while the call waited for the asset's lock.
    $job    = ($case['make'])();
    $holder = $holdAsset((int) $job['del']);
    $r      = $runJob($job, $liveDir, $holder, (int) $job['del']);
    check("{$name} W0 the premise: the call was seen WAITING on the asset's lock, and the asset was really deleted meanwhile",
        $r['seen'] === true && (int) $field('tblAssets', 'isDeleted', 'assetID', (int) $job['del']) === 1, $short($r));
    check("{$name} W: an asset deleted WHILE the call waited for its lock is refused and nothing is written",
        $r['exit'] === 0 && ($case['refused'])($r['result']) === true && ($case['landed'])($job) === false, $short($r));

    // P — the same race with this method's deletion question planted out.
    $swapped = $plant($case['method']);
    $job     = ($case['make'])();
    $holder  = $holdAsset((int) $job['del']);
    $r       = $runJob($job, $plantDir, $holder, (int) $job['del']);
    check("{$name} P: with the deletion question planted out of {$case['method']}() (lock still taken), the same race WRITES — so W can fail",
        $swapped > 0 && $r['seen'] === true && ($case['landed'])($job) === true,
        'plant swapped ' . $swapped . ' call(s); seen ' . var_export($r['seen'], true) . '; ' . $short($r));
}

// =============================================================================
// K. A kit component deleted while the kit is being checked out (#413, #1047)
// =============================================================================
echo "\nA kit's component deleted while the kit is checked out\n";
$makeKit = static function () use ($asset, $lender, $requested, $db): array {
    $p = $asset('kit for loan');
    $c = $asset('kit part');
    run($db, 'UPDATE `tblAssets` SET `parentAssetID` = ? WHERE `assetID` = ?', 'ii', [$p, $c]);
    $lender($p);
    return ['op' => 'checkout', 'a' => $p, 'l' => $requested($p), 'c' => $c];
};
$kit = $makeKit();
$r   = $runJob($kit, $liveDir);
check('K1 control: checking out a live kit also checks out its live component',
    ($r['result']['ok'] ?? null) === true && $count('tblAssetLoans', 'assetID', $kit['c']) === 1, $short($r));
$kit    = $makeKit();
$holder = $holdAsset($kit['c']);
$r      = $runJob($kit, $liveDir, $holder, $kit['c']);
check('K2 the premise: the kit\'s checkout was seen WAITING on the COMPONENT\'s lock, and the component was deleted meanwhile',
    $r['seen'] === true && (int) $field('tblAssets', 'isDeleted', 'assetID', $kit['c']) === 1, $short($r));
check('K3 the kit itself is checked out and the deleted component is SKIPPED: no loan, and its status untouched',
    ($r['result']['ok'] ?? null) === true && $field('tblAssetLoans', 'status', 'loanID', $kit['l']) === 'active'
    && $count('tblAssetLoans', 'assetID', $kit['c']) === 0 && $field('tblAssets', 'status', 'assetID', $kit['c']) === 'in-service',
    $short($r));
$swapped = $plant('cascadeKitCheckout');
$kit     = $makeKit();
$holder  = $holdAsset($kit['c']);
$r       = $runJob($kit, $plantDir, $holder, $kit['c']);
check('K4 with the deletion question planted out of cascadeKitCheckout(), the same race hands the deleted component over — so K3 can fail',
    $swapped > 0 && $r['seen'] === true && $count('tblAssetLoans', 'assetID', $kit['c']) === 1,
    'plant swapped ' . $swapped . '; ' . $short($r));

// =============================================================================
// S. What no race can show: the lock is taken INSIDE a transaction
// =============================================================================
// A race cannot tell "the locked read and the write are in one transaction"
// from "the read locked, committed, and the write ran afterwards": in both, the
// worker waits, wakes after the deletion is committed and reads it. Only a
// deletion landing BETWEEN that read and the write would differ, and nothing
// outside the process can put one there. So it is read from the source, with
// PHP's tokeniser (comments do not count): the one helper that opens a
// transaction must open it before it asks; and every OTHER place that asks
// directly must be one already inside a transaction that locked the row.
echo "\nS — the question is asked inside a transaction (read from the source)\n";
$codeOf = static function (string $php): string {
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t) === true && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            continue;
        }
        $out .= is_array($t) === true ? $t[1] : $t;
    }
    return $out;
};
$code = $codeOf($liveRegister);
/** The code of one method, from its `function` line to its closing brace. */
$methodCode = static function (string $name) use ($code): string {
    $start = strpos($code, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($code, "\n    }\n", $start);
    return $end === false ? '' : substr($code, $start, $end - $start);
};
$helper = $methodCode('withLiveAssetsLocked');
$begin  = strpos($helper, '->begin_transaction()');
$ask    = strpos($helper, 'self::lockedLiveAsset(');
check('S1 withLiveAssetsLocked() begins the transaction BEFORE it asks lockedLiveAsset() (a lock outside a transaction is decoration)',
    $begin !== false && $ask !== false && $begin < $ask, 'begin at ' . var_export($begin, true) . ', ask at ' . var_export($ask, true));
preg_match_all('/function (\w+)\(/', $code, $fns, PREG_OFFSET_CAPTURE);
$askers = [];
foreach ($fns[1] as $i => [$fnName, $offset]) {
    $next = $fns[1][$i + 1][1] ?? strlen($code);
    if (str_contains(substr($code, $offset, $next - $offset), 'self::lockedLiveAsset(') === true) {
        $askers[] = $fnName;
    }
}
sort($askers);
$expected = ['addMaintenance', 'cascadeKitCheckout', 'recordValueSnapshot', 'updateAsset', 'withLiveAssetsLocked'];
check('S2 exactly these ask lockedLiveAsset() directly: the helper, the three writes inside Currency::withParentLocked(), and the kit cascade (inside loanCheckout()\'s helper transaction) — a new direct caller must be judged and added here',
    $askers === $expected, 'found: ' . json_encode($askers));
$insideParentLock = true;
foreach (['updateAsset', 'addMaintenance', 'recordValueSnapshot'] as $fnName) {
    $body = $methodCode($fnName);
    $lock = strpos($body, 'Currency::withParentLocked(');
    $q    = strpos($body, 'self::lockedLiveAsset(');
    if ($lock === false || $q === false || $lock > $q) {
        $insideParentLock = false;
    }
}
check('S3 those three ask only inside Currency::withParentLocked(), which begins the transaction and locks the row first',
    $insideParentLock === true);
preg_match_all('/self::cascadeKitCheckout\(/', $code, $cascadeCalls, PREG_OFFSET_CAPTURE);
$checkoutBody = $methodCode('loanCheckout');
check('S4 cascadeKitCheckout() is called from ONE place, inside loanCheckout()\'s withLiveAssetLocked() transaction',
    count($cascadeCalls[0]) === 1 && str_contains($checkoutBody, 'self::cascadeKitCheckout(') === true
    && strpos($checkoutBody, 'self::withLiveAssetLocked(') !== false
    && strpos($checkoutBody, 'self::withLiveAssetLocked(') < strpos($checkoutBody, 'self::cascadeKitCheckout('),
    count($cascadeCalls[0]) . ' call(s)');

// =============================================================================
// Z. The tidy-up, measured
// =============================================================================
$removeAll();
$countsAfter = $tableCounts();
$leftBehind  = [];
foreach ($countsAfter + $countsBefore as $table => $unused) {
    if (($countsAfter[$table] ?? -1) !== ($countsBefore[$table] ?? -1)) {
        $leftBehind[$table] = ['before' => $countsBefore[$table] ?? null, 'after' => $countsAfter[$table] ?? null];
    }
}
check('Z1 the tidy-up removes every row this run made: each of the ' . count($countsBefore) . ' tables holds exactly as many rows as before the run',
    count($countsBefore) > 100 && $leftBehind === [], 'differs: ' . json_encode($leftBehind));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
