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
 * Plus: K a kit's component deleted while the kit is being checked out (the
 * component is skipped and gets no loan); C a deleted asset's loan and a
 * deleted kit's loan can still be checked in (ending a loan is never refused),
 * and a skipped component is named even when none went out; S the transaction-first rule, read
 * from the source; O the ONE lock order — two writes on the same two assets,
 * made to overlap the way that deadlocks if either takes them in its own
 * order (round 2 measured a kit's checkout deadlocking against a seat), each
 * proved to deadlock with the `sort()` planted out; L "kits are one level
 * deep" asked again under the locks (two attaches at once made a loop); H
 * the loans register over HTTP: Approve on a deleted asset's loan says what
 * to do instead, and Decline still works; Z the tidy-up leaves every table
 * exactly as it found it.
 *
 * WHAT IT CANNOT SHOW, said plainly:
 *   - the pages, except the loans register (section H). It drives
 *     AssetRegister's methods as the pages call them, in a process that loads
 *     the portal's own start-up;
 *     tools/assets-api-update-selftest.php drives the API and the edit page for
 *     updateAsset() (#1031). What a page then SAYS is each page's own message
 *     for the method's ordinary failure.
 *   - the kit-component case has no B: kitChildren() already leaves a deleted
 *     component out, without any lock, so a "deleted before" check would pass
 *     whatever the new code did;
 *   - the writes judged NOT to need the refusal (the ones that END something —
 *     declining, cancelling and checking in a loan, releasing a seat, removing
 *     an identifier, owner, resource, repair or event booking, detaching a kit
 *     component — and the public scan and found-report records). Each one's
 *     reason is written at the top of that method in AssetRegister.php
 *     ("NOT refused for a DELETED asset, on purpose"), the loans' beside
 *     loanAction();
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
foreach (['proc_open', 'curl_init'] as $needed) {
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
    private static function plantedLocksAnyway(\mysqli $db, array $assetIds, int $siteId, callable $work, array $alsoLockIfLive = []): mixed
    {
        $ids = array_values(array_unique(array_merge(array_map('intval', $assetIds), array_map('intval', $alsoLockIfLive))));
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

/**
 * 🌱 Plant a TEXT change into the planted copy (every other line as committed).
 * Answers how many places changed (0 means the plant did not apply).
 */
$plantText = static function (array $swaps) use ($liveRegister, $plantDir, $sep): int {
    $changed = 0;
    $text    = $liveRegister;
    foreach ($swaps as $from => $to) {
        $changed += substr_count($text, $from);
        $text = str_replace($from, $to, $text);
    }
    file_put_contents($plantDir . $sep . 'web' . $sep . '_core' . $sep . 'AssetRegister.php', $text);

    return $changed;
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
    'checkin'      => R::loanAction((int) $j['l'], $a, 'checkin', ['conditionIn' => 'fair'], $u),
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
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [AD_USER]);
    run($db, 'DELETE FROM `tblUserSites` WHERE `userID` = ?', 'i', [AD_USER]);
    run($db, 'DELETE FROM `tblUsers` WHERE `userID` = ?', 'i', [AD_USER]);
};
run($db, 'DELETE FROM `tblLocalAccounts` WHERE `userID` = ?', 'i', [AD_USER]);
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
// The check-in now locks the kit and its components up front too (round 2,
// one lock order) — and must still bring both back.
$r = $runJob(['op' => 'checkin', 'a' => $kit['a'], 'l' => $kit['l']], $liveDir);
check('K1b control: checking the kit back in returns the kit AND its component (the check-in locks them together, lowest first)',
    ($r['result']['ok'] ?? null) === true && $field('tblAssetLoans', 'status', 'loanID', $kit['l']) === 'returned'
    && one($db, "SELECT COUNT(*) FROM `tblAssetLoans` WHERE `assetID` = ? AND `status` = 'returned'", 'i', [$kit['c']]) === 1
    && $field('tblAssets', 'conditionState', 'assetID', $kit['c']) === 'fair', $short($r));
$kit    = $makeKit();
$holder = $holdAsset($kit['c']);
$r      = $runJob($kit, $liveDir, $holder, $kit['c']);
check('K2 the premise: the kit\'s checkout was seen WAITING on the COMPONENT\'s lock, and the component was deleted meanwhile',
    $r['seen'] === true && (int) $field('tblAssets', 'isDeleted', 'assetID', $kit['c']) === 1, $short($r));
check('K3 the kit itself is checked out and the deleted component is SKIPPED: no loan, and its status untouched',
    ($r['result']['ok'] ?? null) === true && $field('tblAssetLoans', 'status', 'loanID', $kit['l']) === 'active'
    && $count('tblAssetLoans', 'assetID', $kit['c']) === 0 && $field('tblAssets', 'status', 'assetID', $kit['c']) === 'in-service',
    $short($r));
$swapped = $plant('loanCheckout');
$kit     = $makeKit();
$holder  = $holdAsset($kit['c']);
$r       = $runJob($kit, $plantDir, $holder, $kit['c']);
check('K4 with the deletion question planted out of loanCheckout()\'s one lock call (which locks the kit AND its components), the same race hands the deleted component over — so K3 can fail',
    $swapped > 0 && $r['seen'] === true && $count('tblAssetLoans', 'assetID', $kit['c']) === 1,
    'plant swapped ' . $swapped . '; ' . $short($r));

// =============================================================================
// C. Ending a loan is NEVER refused for a deleted asset (#1047 round 3)
// =============================================================================
// Check-in locks the asset (and a kit's components) up front as "lock if live",
// so a deleted one is not refused. Round 3 of the check measured that, with the
// helper changed to refuse a gone asset, check-in IGNORED the helper's null and
// reported "Loan checked in" while both loans stayed active. It asks now; these
// checks make sure a deleted asset's loan and a deleted kit's loan really end.
echo "\nC — a deleted asset's loan, and a deleted kit's loan, can still be checked in\n";
$activeLoan = static fn (int $a, ?int $parentLoan = null): int => $insertId(
    "INSERT INTO `tblAssetLoans` (`siteID`, `assetID`, `direction`, `counterpartyType`, `counterpartyName`, `status`, `requestedByID`, `parentLoanID`, `dateOut`) VALUES (?, ?, 'out', 'other', 'Selftest borrower', 'active', ?, ?, NOW())",
    'iiii', [$site, $a, AD_USER, $parentLoan]);
$makeOut = static function () use ($asset, $lender, $activeLoan, $db): array {
    $a = $asset('checked out');
    $lender($a);
    run($db, "UPDATE `tblAssets` SET `status` = 'on-loan' WHERE `assetID` = ?", 'i', [$a]);
    return ['op' => 'checkin', 'a' => $a, 'l' => $activeLoan($a)];
};
$makeKitOut = static function () use ($asset, $lender, $activeLoan, $db): array {
    $kit  = $asset('kit out');
    $part = $asset('kit out part');
    run($db, 'UPDATE `tblAssets` SET `parentAssetID` = ? WHERE `assetID` = ?', 'ii', [$kit, $part]);
    $lender($kit);
    $loan = $activeLoan($kit);
    return ['op' => 'checkin', 'a' => $kit, 'l' => $loan, 'part' => $part, 'partLoan' => $activeLoan($part, $loan)];
};
$job = $makeOut();
$deleteAsset($db, $job['a']);
$r = $runJob($job, $liveDir);
check('C1 checking in the loan of a DELETED asset works: it answers success and the loan is returned',
    ($r['result']['ok'] ?? null) === true && $field('tblAssetLoans', 'status', 'loanID', $job['l']) === 'returned', $short($r));
$kitJob = $makeKitOut();
$deleteAsset($db, $kitJob['a']);
$r = $runJob($kitJob, $liveDir);
check('C2 checking in the loan of a DELETED kit works: the kit\'s loan AND its component\'s loan are both returned',
    ($r['result']['ok'] ?? null) === true && $field('tblAssetLoans', 'status', 'loanID', $kitJob['l']) === 'returned'
    && $field('tblAssetLoans', 'status', 'loanID', $kitJob['partLoan']) === 'returned', $short($r));
$changed = $plantText(["if (in_array(\$id, \$required, true) === false) {\n                        continue;\n                    }\n" => '']);
$kitJob = $makeKitOut();
$deleteAsset($db, $kitJob['a']);
$r = $runJob($kitJob, $plantDir);
check('C2P with the `continue` planted out (a gone asset then refuses), the same check-in is NOT done and does NOT claim it was: both loans stay active and the answer is a failure — so C2 can fail, and the helper\'s null is not ignored',
    $changed === 1 && ($r['result']['ok'] ?? null) === false
    && $field('tblAssetLoans', 'status', 'loanID', $kitJob['l']) === 'active' && $field('tblAssetLoans', 'status', 'loanID', $kitJob['partLoan']) === 'active',
    'plant changed ' . $changed . '; ' . $short($r));

/**
 * A skipped component is NAMED, with its reason, even when no component went
 * out (round 3). This is the case where the component was live when the
 * checkout read the kit's list and was deleted before the checkout got its
 * lock — the only way a "no longer in the register" component reaches the
 * message, since kitChildren() leaves one deleted earlier out of the list.
 * The component's own NAME is required, not just the reason: with the names
 * replaced by a fixed string the reason alone still matched (round 4).
 */
$kit    = $makeKit();
$cName  = (string) $field('tblAssets', 'name', 'assetID', $kit['c']);
$holder = $holdAsset($kit['c']);
$r      = $runJob($kit, $liveDir, $holder, $kit['c']); // the component is deleted while the checkout waits
check('C3 a kit whose ONLY component was deleted while it went out says so: the message names THAT component as no longer in the register',
    ($r['result']['ok'] ?? null) === true
    && str_contains((string) ($r['result']['msg'] ?? ''), $cName . ' (no longer in the register)') === true, $short($r));

/** C4: two components, one goes out and one is skipped — both sentences, each with the right count or name. */
$kit2   = $makeKit();
$second = $asset('kit second part');
run($db, 'UPDATE `tblAssets` SET `parentAssetID` = ? WHERE `assetID` = ?', 'ii', [$kit2['a'], $second]);
$sName  = (string) $field('tblAssets', 'name', 'assetID', $second);
$keptName = (string) $field('tblAssets', 'name', 'assetID', $kit2['c']);
$holder = $holdAsset($second);
$r      = $runJob($kit2, $liveDir, $holder, $second); // the SECOND component is deleted while the checkout waits
$msg    = (string) ($r['result']['msg'] ?? '');
check('C4 a kit with two components, one deleted while it went out: the message says 1 component went out AND names the skipped one; the other is on loan',
    ($r['result']['ok'] ?? null) === true && str_contains($msg, '1 kit component(s) checked out too.') === true
    && str_contains($msg, $sName . ' (no longer in the register)') === true && str_contains($msg, $keptName) === false
    && $count('tblAssetLoans', 'assetID', $kit2['c']) === 1 && $count('tblAssetLoans', 'assetID', $second) === 0, $short($r));

// =============================================================================
// O. ONE lock order: two writes on the same two assets never deadlock
// =============================================================================
// Round 2 of the check measured a NEW deadlock: a kit's checkout locked the
// kit, then its component; a seat linked from that component (a licence) to
// the kit locked them lowest number first — the other way round. Each held one
// and waited for the other; InnoDB killed one and the seat's save said "please
// try again". Every write that locks more than one asset now takes them
// through withLiveAssetsLocked(), lowest first. These cases make two such
// writes overlap exactly the way that deadlocks when either uses its own
// order: this test holds BOTH rows (one per connection), starts the two
// writes, lets go of the first row and then the second, and requires BOTH
// writes to succeed. Then the same with the `sort()` planted out: one of them
// must fail, which proves the case can see a missing order.
echo "\nO — two writes on the same two assets take them in ONE order, so they queue rather than deadlock\n";
$threadOf = static fn (mysqli $h): int => one($db, 'SELECT `THREAD_ID` FROM `performance_schema`.`threads` WHERE `PROCESSLIST_ID` = ?', 'i', [$h->thread_id]);
/** How many lock waits are blocked by these connections, on any row. */
$waitersOn = static function (array $holders) use ($db, $threadOf): int {
    $n = 0;
    foreach ($holders as $h) {
        $n += one($db, 'SELECT COUNT(*) FROM `performance_schema`.`data_lock_waits` WHERE `BLOCKING_THREAD_ID` = ?', 'i', [$threadOf($h)]);
    }
    return $n;
};
$pollUntil = static function (callable $condition, int $tenths): bool {
    for ($i = 0; $i < $tenths; $i++) {
        if ($condition() === true) {
            return true;
        }
        usleep(100000);
    }
    return $condition();
};
$startJob = static function (array $job, string $dir) use ($worker, $base, $env, $sep): array {
    $job['user'] = AD_USER;
    $out  = $base . $sep . 'worker-' . bin2hex(random_bytes(4)) . '.out';
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $worker,
        $dir . $sep . 'web' . $sep . '_core' . $sep . 'bootstrap.php', json_encode($job)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, $base, $env);
    return ['proc' => $proc, 'out' => $out];
};
$endJob = static function (array $started): array {
    $exit    = proc_close($started['proc']);
    $printed = (string) @file_get_contents($started['out']);
    $said    = trim((string) @file_get_contents($started['out'] . '.err'));
    @unlink($started['out']);
    @unlink($started['out'] . '.err');
    $pos = strrpos($printed, "\nRESULT:");
    return ['exit' => $exit, 'result' => $pos === false ? '(no result)' : json_decode(substr($printed, $pos + 8), true), 'said' => $said, 'seen' => true];
};
/**
 * Hold $first and $second (one connection each); start $job1 and $job2 and
 * wait until both are WAITING on those holders; let go of $first, give the
 * writes a moment to queue on $second, then let go of $second.
 */
$overlap = static function (array $job1, array $job2, int $first, int $second, string $dir)
    use ($holdAsset, $waitersOn, $pollUntil, $startJob, $endJob): array {
    $h1 = $holdAsset($first);
    $h2 = $holdAsset($second);
    $w1 = $startJob($job1, $dir);
    $pollUntil(static fn (): bool => $waitersOn([$h1, $h2]) >= 1, 100);
    $w2 = $startJob($job2, $dir);
    $seen = $pollUntil(static fn (): bool => $waitersOn([$h1, $h2]) >= 2, 100);
    $h1->commit();
    $h1->close();
    // With ONE order the second write is queued behind the first, not on $second,
    // so this gives up after three seconds; with two orders both queue on it.
    $pollUntil(static fn (): bool => $waitersOn([$h2]) >= 2, 30);
    $h2->commit();
    $h2->close();
    return ['seen' => $seen, 'one' => $endJob($w1), 'two' => $endJob($w2)];
};
$okOf = static fn (mixed $r): bool => is_array($r) === true && (($r['ok'] ?? null) === true || (int) ($r['id'] ?? 0) > 0);
$noSort = ["        sort(\$ids);\n        \$db->begin_transaction();" => "        \$db->begin_transaction();"];

/** O1: a component put into a kit, and a seat linked the other way between the same two. */
$makePair = static function () use ($asset): array {
    $device  = $asset('order device');                                 // the LOWER number
    $licence = $asset('order licence kit', ['assetKind' => 'digital']); // the higher
    return [
        ['op' => 'attach', 'c' => $device, 'p' => $licence],          // its own order: device, licence
        ['op' => 'seatDevice', 'a' => $licence, 'd' => $device],      // its own order: licence, device
        $device, $licence,
    ];
};
[$j1, $j2, $lo, $hi] = $makePair();
$o = $overlap($j1, $j2, $lo, $hi, $liveDir);
check('O1 attachToKit(device into licence) and assignSeat(licence to device), overlapping: BOTH succeed (they queue on one order)',
    $o['seen'] === true && $okOf($o['one']['result']) === true && $okOf($o['two']['result']) === true,
    'seen ' . var_export($o['seen'], true) . '; attach ' . $short($o['one']) . ' | seat ' . $short($o['two']));
$changed = $plantText($noSort);
[$j1, $j2, $lo, $hi] = $makePair();
$o = $overlap($j1, $j2, $lo, $hi, $plantDir);
check('O1P with the sort() planted out of withLiveAssetsLocked(), the same overlap DEADLOCKS: one of the two fails — so O1 can fail',
    $changed === 1 && $o['seen'] === true && ($okOf($o['one']['result']) === false || $okOf($o['two']['result']) === false),
    'plant changed ' . $changed . '; attach ' . $short($o['one']) . ' | seat ' . $short($o['two']));

/** O2: THE MEASURED CASE — kit #K holding licence #L (L lower), K checked out while a seat links L to K. */
$makeKitSeat = static function () use ($asset, $lender, $requested, $db): array {
    $licence = $asset('order kit licence', ['assetKind' => 'digital']); // the LOWER number, a component
    $kit     = $asset('order kit');
    run($db, 'UPDATE `tblAssets` SET `parentAssetID` = ? WHERE `assetID` = ?', 'ii', [$kit, $licence]);
    $lender($kit);
    $loan = $requested($kit);
    return [
        ['op' => 'checkout', 'a' => $kit, 'l' => $loan],                // round 1 locked: kit, then component
        ['op' => 'seatDevice', 'a' => $licence, 'd' => $kit],            // locks: licence (lower), kit
        $kit, $licence, $loan,
    ];
};
[$j1, $j2, $kitId, $licId, $loanId] = $makeKitSeat();
$o = $overlap($j1, $j2, $kitId, $licId, $liveDir);
check('O2 the measured deadlock: a kit checked out while a seat links its component licence to it, overlapping — BOTH succeed, and the component goes out with the kit',
    $o['seen'] === true && $okOf($o['one']['result']) === true && $okOf($o['two']['result']) === true
    && $field('tblAssetLoans', 'status', 'loanID', $loanId) === 'active' && $count('tblAssetLoans', 'assetID', $licId) === 1,
    'seen ' . var_export($o['seen'], true) . '; checkout ' . $short($o['one']) . ' | seat ' . $short($o['two']));
$changed = $plantText($noSort);
[$j1, $j2, $kitId, $licId, $loanId] = $makeKitSeat();
$o = $overlap($j1, $j2, $kitId, $licId, $plantDir);
check('O2P with the sort() planted out, the same overlap DEADLOCKS (the checkout then locks the kit before its component, as round 1 did) — so O2 can fail',
    $changed === 1 && $o['seen'] === true && ($okOf($o['one']['result']) === false || $okOf($o['two']['result']) === false),
    'plant changed ' . $changed . '; checkout ' . $short($o['one']) . ' | seat ' . $short($o['two']));

// =============================================================================
// L. Kits are one level deep — asked again UNDER the locks
// =============================================================================
// The round-2 check made a loop with two attaches at once: A into B and B into
// A each passed attachToKit()'s checks (made before any lock) and both landed.
// Now the checks are asked again under both rows' locks, so the second sees
// the first. This test holds BOTH rows in one connection, starts both attaches,
// waits until both are waiting, and lets go.
echo "\nL — two attaches at once cannot make a kit loop\n";
$holdBoth = static function (int $a, int $b) use ($dbHost, $dbUser, $dbPass, $dbName, $dbPort): mysqli {
    $h = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $h->begin_transaction();
    foreach ([$a, $b] as $id) {
        $st = $h->prepare('SELECT 1 FROM `tblAssets` WHERE `assetID` = ? FOR UPDATE');
        $st->bind_param('i', $id);
        $st->execute();
        $st->get_result()->fetch_all();
        $st->close();
    }
    return $h;
};
$loopRace = static function (string $dir) use ($asset, $holdBoth, $startJob, $endJob, $waitersOn, $pollUntil, $field): array {
    $a  = $asset('loop one');
    $b  = $asset('loop two');
    $h  = $holdBoth($a, $b);
    $w1 = $startJob(['op' => 'attach', 'c' => $a, 'p' => $b], $dir);
    $w2 = $startJob(['op' => 'attach', 'c' => $b, 'p' => $a], $dir);
    $seen = $pollUntil(static fn (): bool => $waitersOn([$h]) >= 2, 100);
    $h->commit();
    $h->close();
    $r1 = $endJob($w1);
    $r2 = $endJob($w2);
    return ['seen' => $seen, 'r1' => $r1, 'r2' => $r2,
        'loop' => (int) $field('tblAssets', 'parentAssetID', 'assetID', $a) === $b && (int) $field('tblAssets', 'parentAssetID', 'assetID', $b) === $a];
};
$l = $loopRace($liveDir);
check('L1 A-into-B and B-into-A at once: exactly ONE lands, the other is refused with the one-level-deep words, and there is no loop',
    $l['seen'] === true && $l['loop'] === false && ($okOf($l['r1']['result']) xor $okOf($l['r2']['result']))
    && str_contains(json_encode($l['r1']['result'], JSON_UNESCAPED_UNICODE) . json_encode($l['r2']['result'], JSON_UNESCAPED_UNICODE), 'one level deep') === true,
    'seen ' . var_export($l['seen'], true) . '; ' . $short($l['r1']) . ' | ' . $short($l['r2']));
$changed = $plantText([
    "if (\$locked[\$parentAssetId]['parentAssetID'] !== null) {" => 'if (false) {',
    'if ($hasKids === true) {' => 'if (false) {',
]);
$l = $loopRace($plantDir);
check('L1P with the under-lock checks planted out, the same two attaches make a LOOP — so L1 can fail',
    $changed === 2 && $l['seen'] === true && $l['loop'] === true,
    'plant changed ' . $changed . '; ' . $short($l['r1']) . ' | ' . $short($l['r2']));

// =============================================================================
// H. The loans register over HTTP: what Approve says on a deleted asset's loan
// =============================================================================
echo "\nH — the loans register, signed in, on a deleted asset's loan\n";
file_put_contents($base . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/assets-deleted-writes-selftest.php for ONE run. Imitates web/public_html/.htaccess.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/live/web/public_html');
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
$port = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 9147;
$probe = @fsockopen('127.0.0.1', $port, $errNo, $errText, 0.5);
if (is_resource($probe) === true) {
    fclose($probe);
    refuse("something already answers on port {$port}; choose another with SELFTEST_WEB_PORT");
}
@mkdir($base . $sep . 'sessions', 0700);
$jar = $base . $sep . 'cookies.txt';
$serverEnv = $env;
unset($serverEnv['PHP_CLI_SERVER_WORKERS']);
$state['server'] = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $base . $sep . 'sessions',
    '-d', 'error_log=' . $base . $sep . 'php-errors.log', '-d', 'log_errors=1', '-d', 'display_errors=0',
    '-S', '127.0.0.1:' . $port, '-t', $liveDir . $sep . 'web' . $sep . 'public_html', $base . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $base . $sep . 'server.log', 'a'], 2 => ['file', $base . $sep . 'server.log', 'a']],
    $serverPipes, $base, $serverEnv);
register_shutdown_function(static function () use (&$state): void {
    if (isset($state['server']) === true && is_resource($state['server']) === true) {
        proc_terminate($state['server']);
        proc_close($state['server']);
    }
});
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
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
if ($pollUntil(static fn (): bool => $http('GET', '/login')['status'] > 0, 200) === false) {
    refuse('the test server never answered');
}
// The member is made an administrator for this section (they hold lending
// authority anyway): the loans register draws its buttons for them.
run($db, 'UPDATE `tblUsers` SET `isAdmin` = 1, `isRootAdmin` = 1 WHERE `userID` = ?', 'i', [AD_USER]);
$password = 'Ad' . bin2hex(random_bytes(8)) . '!9x';
run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
    'iss', [AD_USER, 'selftest-assets-deleted', password_hash($password, PASSWORD_DEFAULT)]);
$in = $http('POST', '/login', ['csrf_token' => $tokenOn($http('GET', '/login')['body']), 'username' => 'selftest-assets-deleted', 'password' => $password]);
check('H0 the premise: signing in answers a redirect', $in['status'] === 302 || $in['status'] === 303, 'HTTP ' . $in['status']);
$press = static function (int $assetId, int $loanId, string $action) use ($http, $tokenOn): string {
    $token = $tokenOn($http('GET', '/assets/loans')['body']);
    $http('POST', '/assets/loan-action', ['csrf_token' => $token, 'assetID' => $assetId, 'loanID' => $loanId,
        'action' => $action, 'returnTo' => 'loans', 'declineReason' => '']);
    return html_entity_decode($http('GET', '/assets/loans')['body'], ENT_QUOTES, 'UTF-8');
};
$words = 'This asset has been deleted, so the loan cannot be approved or checked out. Decline or cancel it instead.';
foreach (['approve' => 'H1', 'checkout' => 'H2'] as $action => $tag) {
    $a = $asset('http ' . $action);
    $lender($a);
    $loan = $requested($a);
    $deleteAsset($db, $a);
    $page = $press($a, $loan, $action);
    check("{$tag} pressing " . ucfirst($action) . ' on a deleted asset\'s loan in the loans register SAYS it has been deleted and to decline or cancel it instead, and the loan is still requested',
        str_contains($page, $words) === true && $field('tblAssetLoans', 'status', 'loanID', $loan) === 'requested',
        'message shown: ' . var_export(str_contains($page, $words), true) . '; loan ' . var_export($field('tblAssetLoans', 'status', 'loanID', $loan), true));
}
$a = $asset('http decline');
$lender($a);
$loan = $requested($a);
$deleteAsset($db, $a);
$press($a, $loan, 'decline');
check('H3 and the advice is true: Decline on that deleted asset\'s loan works (the loan is declined, so it leaves the open list)',
    $field('tblAssetLoans', 'status', 'loanID', $loan) === 'declined', 'loan ' . var_export($field('tblAssetLoans', 'status', 'loanID', $loan), true));
$serverErrors = is_file($base . $sep . 'php-errors.log') === true ? trim((string) file_get_contents($base . $sep . 'php-errors.log')) : '';
check('H4 the web server logged no PHP error or warning', preg_match('/PHP (Fatal|Parse|Warning|Deprecated)/', $serverErrors) !== 1, mb_substr($serverErrors, 0, 400));

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
$expected = ['addMaintenance', 'recordValueSnapshot', 'updateAsset', 'withLiveAssetsLocked'];
check('S2 exactly these ask lockedLiveAsset() directly: the helper and the three single-asset writes inside Currency::withParentLocked() — a new direct caller must be judged and added here (a second asset locked any other way is a second lock order, section O)',
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
check('S4 cascadeKitCheckout() is called from ONE place, inside loanCheckout()\'s withLiveAssetsLocked() call, which locked the kit and its components together',
    count($cascadeCalls[0]) === 1 && str_contains($checkoutBody, 'self::cascadeKitCheckout(') === true
    && strpos($checkoutBody, 'self::withLiveAssetsLocked(') !== false
    && strpos($checkoutBody, 'self::withLiveAssetsLocked(') < strpos($checkoutBody, 'self::cascadeKitCheckout('),
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
