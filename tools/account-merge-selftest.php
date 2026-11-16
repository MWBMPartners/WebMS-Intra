<?php
// Path: tools/account-merge-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Merging two accounts, on a real database and over HTTP 🔀 (#1064; D-190, D-191)
 * -----------------------------------------------------------------------------
 *   SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=3306 php tools/account-merge-selftest.php
 *
 * Needs `web/_sql/full_schema.sql` loaded into a database whose name starts with
 * `selftest_`. It writes rows (made-up rows with foreign-key checks OFF in
 * sections D and F), changes one installation setting for section E and puts it
 * back, starts two worker processes (section G) and PHP's own web server against
 * a COPY of `web/` (section E).
 *
 *   A  The list against the LOADED database, and the clash rules against the list.
 *   B  The rule on hand-made facts: every refusal, in its order.
 *   C  The rule on real facts over two organisations, the true words for an
 *      administrator account and for single-organisation mode, standing and
 *      no-longer-standing records, and that "not visible" and "does not exist"
 *      issue the SAME number of statements.
 *   D  The preview: one made-up row per listed column; clashes; a real unknown
 *      clash refuses.
 *   F  Completion in-process: every column moved, kept or dropped as its
 *      treatment says; the stricter choice in BOTH orders; differing choices
 *      refuse; the tick; the duplicate closed; numbers-only record.
 *   G  Two processes complete the same request at once: exactly one merges.
 *   E  The pages over HTTP, with several organisations switched on AND off:
 *      permission, the boundary (byte-identical "not found" bodies), the
 *      person's two-sided proof and every edge of it, cancel, expiry.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

function refuse(string $why): never
{
    echo "REFUSED — {$why}.\n";
    exit(2);
}

$GLOBALS['am_pass'] = 0;
$GLOBALS['am_fail'] = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok === true) {
        $GLOBALS['am_pass']++;
        echo "  ✓ {$label}\n";
        return;
    }
    $GLOBALS['am_fail']++;
    echo "  ✗ {$label}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

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

$coreDir = $repo . $sep . 'web' . $sep . '_core' . $sep;
if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', rtrim($coreDir, $sep));
}
// The classes the merge reaches, one by one (never bootstrap.php). Mailer is
// deliberately NOT loaded: the merge's e-mails are sent after its commit inside
// a catch, so here they fail quietly and nothing is sent anywhere.
const AM_CLASSES = ['App', 'Site', 'Logger', 'Roles', 'InstallationSettings', 'Capabilities', 'ApiAuth', 'GdprEraser',
    'AccountGuard', 'WebPush', 'NotifyPrefs', 'AccountMerge'];
foreach (AM_CLASSES as $class) {
    require_once $coreDir . $class . '.php';
}

use Portal\Core\AccountGuard;
use Portal\Core\AccountMerge as M;
use Portal\Core\GdprEraser;
use Portal\Core\Roles;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    // Global on purpose: App::db() falls back to it.
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$mysqli->set_charset('utf8mb4');
$db = $mysqli;
\Portal\Core\Site::init($db, ['site' => ['timezoneKeptAt' => '']], 1);
if ((int) $db->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['n'] < 100) {
    refuse('the database holds too few tables — load web/_sql/full_schema.sql first');
}

/** @return list<array<string, mixed>> */
function q(mysqli $db, string $sql): array
{
    $out = [];
    $rs  = $db->query($sql);
    while (($row = $rs->fetch_assoc()) !== null) {
        $out[] = $row;
    }

    return $out;
}
function n(mysqli $db, string $sql): int
{
    return (int) (q($db, $sql)[0]['n'] ?? -1);
}

$listed = M::columns();

// =============================================================================
echo "A. The list against the loaded database\n";
// =============================================================================
$linked = [];
foreach (q($db, "SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c FROM `information_schema`.`KEY_COLUMN_USAGE` "
    . "WHERE `TABLE_SCHEMA` = DATABASE() AND `REFERENCED_TABLE_NAME` = 'tblUsers'") as $r) {
    $linked[$r['t'] . '.' . $r['c']] = true;
}
$shaped = [];
foreach (q($db, "SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `DATA_TYPE` IN ('int','bigint','mediumint','smallint','tinyint') AND `TABLE_NAME` <> 'tblUsers'") as $r) {
    $c = (string) $r['c'];
    if ($c === 'userID' || str_ends_with($c, 'UserID') || str_ends_with($c, 'ByID') || in_array($c, GdprEraser::LINK_COLUMNS, true) === true) {
        $shaped[$r['t'] . '.' . $c] = true;
    }
}
$accountColumns = array_keys($linked + $shaped);
check('A1 the loaded database has at least the 198 links to tblUsers this was built against', count($linked) >= 198, (string) count($linked));
$missing = array_values(array_filter($accountColumns, static fn (string $n): bool => isset($listed[$n]) === false && isset(M::NOT_AN_ACCOUNT[$n]) === false));
check('A2 every account column in the database is listed', $missing === [], implode(', ', $missing));
$existing = [];
foreach (q($db, 'SELECT CONCAT(`TABLE_NAME`, \'.\', `COLUMN_NAME`) AS n FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE()') as $r) {
    $existing[$r['n']] = true;
}
$stale = array_values(array_filter(array_keys($listed + M::NOT_AN_ACCOUNT), static fn (string $n): bool => isset($existing[$n]) === false));
check('A3 nothing listed is missing from the database', $stale === [], implode(', ', $stale));
$keys = M::uniqueKeysOf($db);
$movedInKey = [];
$clashTreated = [];
foreach ($listed as $name => $entry) {
    [$t, $c] = explode('.', $name);
    if (isset($keys[$t][$c]) === true) {
        $clashTreated[] = $name;
        if ($entry['treatment'] === M::MOVE) {
            $movedInKey[] = $name;
        }
    }
}
check('A4 no column inside a unique key is plain "move"', $movedInKey === [], implode(', ', $movedInKey));
check('A5 the generated attendance key is found through the column it is built from',
    in_array(['eventID', 'dayDate'], $keys['tblEventAttendance']['userID'] ?? [], true) === true, json_encode($keys['tblEventAttendance']['userID'] ?? null));
check('A6 a primary key holding the account number is found (tblUserOutsideServicePref)',
    ($keys['tblUserOutsideServicePref']['userID'] ?? null) === [['serviceKey']]);
$ruleProblems = [];
foreach ($listed as $name => $entry) {
    $needs = in_array($entry['treatment'], [M::STRICTER_ON_CLASH, M::SAME_OR_REFUSE], true);
    $has   = isset(M::CLASH_RULES[$name]);
    if ($needs !== $has) {
        $ruleProblems[] = $name . ($needs ? ' has no clash rule' : ' has a clash rule it does not use');
    }
    if ($entry['treatment'] === M::STRICTER_ON_CLASH && isset(M::CLASH_RULES[$name]['stricter']) === false) {
        $ruleProblems[] = $name . ' is "stricter" with no order';
    }
    if ($entry['treatment'] === M::SAME_OR_REFUSE && (M::CLASH_RULES[$name]['same'] ?? []) === []) {
        $ruleProblems[] = $name . ' is "same or refuse" with nothing compared';
    }
    if ($entry['treatment'] === M::REFUSE_IF_ANY && isset(M::REFUSE_WHEN[$name]) === false) {
        $ruleProblems[] = $name . ' has no standing condition';
    }
}
foreach (array_keys(M::CLASH_RULES + M::REFUSE_WHEN) as $name) {
    if (isset($listed[$name]) === false) {
        $ruleProblems[] = $name . ' has a rule but is not listed';
    }
}
check('A7 every clash rule and standing condition belongs to exactly the columns that use one', $ruleProblems === [], implode('; ', $ruleProblems));
// Finding 6: every clash-treated column (found from the DATABASE's keys) is
// classified as one of the clash treatments, never left to "keep kept" by default.
check('A8 every column a unique key covers has a stated clash treatment (' . count($clashTreated) . ' columns)', $clashTreated !== [] && $movedInKey === []);
check('A9 every word the pages use describes a column without its table name',
    str_contains(M::describe('tblPrayerRequests.submitterID'), 'tbl') === false
    && M::describe('tblEvents.createdByID') === 'Events (who created them)', M::describe('tblEvents.createdByID'));

// =============================================================================
echo "B. The rule on hand-made facts\n";
// =============================================================================
$ok = ['guard' => AccountGuard::ALLOW, 'guardReason' => '', 'isActive' => true, 'adminAnywhere' => false, 'heldElsewhere' => 0];
$facts = static fn (array $over = [], array $k = [], array $d = []): array => array_replace([
    'mayMergeHere' => true, 'mayMergeEverywhere' => true, 'actorId' => 1, 'keptId' => 10, 'duplicateId' => 20, 'single' => false,
    'accounts' => [10 => array_replace($ok, $k), 20 => array_replace($ok, $d)],
], $over);
$global = ['guard' => AccountGuard::GLOBAL_ONLY];
foreach ([
    ['B1 all clear', $facts(), ''],
    ['B2 no permission here', $facts(['mayMergeHere' => false]), M::R_NO_PERMISSION],
    ['B3 the same account twice', $facts(['duplicateId' => 10]), M::R_SAME_ACCOUNT],
    ['B4 an account that does not exist', $facts(['accounts' => [10 => $ok]]), M::R_NOT_FOUND],
    ['B5 an account this administrator cannot see', $facts([], [], ['guard' => AccountGuard::NOT_FOUND]), M::R_NOT_FOUND],
    ['B6 THE PROBE: unseen AND no permission elsewhere still says only "not found"',
        $facts(['mayMergeEverywhere' => false], [], ['guard' => AccountGuard::NOT_FOUND]), M::R_NOT_FOUND],
    ['B7 erased', $facts([], ['guard' => AccountGuard::ERASED]), M::R_ERASED],
    ['B8 erased comes before "needs a global administrator"', $facts([], ['guard' => AccountGuard::ERASED], $global + ['guardReason' => 'other_org']), M::R_ERASED],
    ['B9 belongs to another organisation', $facts([], [], $global + ['guardReason' => 'other_org']), M::R_NEEDS_GLOBAL],
    ['B9b ...in single-organisation mode, words that do not mention another organisation', $facts(['single' => true], [], $global + ['guardReason' => 'other_org']), M::R_NEEDS_GLOBAL_SINGLE],
    ['B9c a portal-wide administrator account is NOT "another organisation" (finding 2)', $facts([], [], $global + ['guardReason' => 'portal_admin']), M::R_WHOLE_PORTAL_ADMIN],
    ['B9d nor is a global administrator account', $facts([], [], $global + ['guardReason' => 'global_admin']), M::R_WHOLE_PORTAL_ADMIN],
    ['B10 no permission in another organisation', $facts(['mayMergeEverywhere' => false]), M::R_NO_PERMISSION],
    ['B11 the actor is the kept account', $facts(['actorId' => 10]), M::R_IS_YOU],
    ['B12 the actor is the duplicate', $facts(['actorId' => 20]), M::R_IS_YOU],
    ['B13 an organisation administrator account', $facts([], [], ['adminAnywhere' => true]), M::R_ADMINISTRATOR],
    ['B14 a closed account', $facts([], ['isActive' => false]), M::R_CLOSED],
    ['B15 standing records', $facts([], [], ['heldElsewhere' => 1]), M::R_REFUSE_IF_ANY],
    ['B16 a missing permission fact refuses', array_diff_key($facts(), ['mayMergeEverywhere' => 1]), M::R_NO_PERMISSION],
] as [$label, $f, $want]) {
    $got = M::whyNotAllowed($f);
    check($label, $got === $want, "got '{$got}', wanted '{$want}'");
}
$codes = [M::R_NO_PERMISSION, M::R_SAME_ACCOUNT, M::R_NOT_FOUND, M::R_ERASED, M::R_WHOLE_PORTAL_ADMIN, M::R_NEEDS_GLOBAL,
    M::R_NEEDS_GLOBAL_SINGLE, M::R_IS_YOU, M::R_ADMINISTRATOR, M::R_CLOSED, M::R_REFUSE_IF_ANY, M::R_CLASH, M::R_CHOICES_DIFFER, M::R_UNKNOWN_CLASH];
check('B17 every refusal has words', array_filter($codes, static fn (string $c): bool => strlen(M::refusalWords($c)) < 20) === []);
check('B18 the single-organisation words never mention "another organisation"', str_contains(M::refusalWords(M::R_NEEDS_GLOBAL_SINGLE), 'another organisation') === false);
$threw = false;
try {
    M::refusalWords('no_such');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('B19 an unknown refusal code throws', $threw);

// =============================================================================
echo "C. The rule on real facts, over two organisations\n";
// =============================================================================
$tag  = bin2hex(random_bytes(3));
$site = static function (string $name) use ($db, $tag): int {
    $db->query("INSERT INTO `tblSites` (`siteName`, `siteKey`) VALUES ('{$name}', 'am-{$tag}-" . strtolower($name) . "')");
    $id = (int) $db->insert_id;
    Roles::seedStandardSet($db, $id);

    return $id;
};
$siteA = $site('A');
$siteB = $site('B');
$user = static function (string $name, array $flags = []) use ($db, $tag): int {
    $email  = strtolower($name) . '-' . $tag . '@selftest.invalid';
    $active = $flags['isActive'] ?? 1;
    $admin  = $flags['isAdmin'] ?? 0;
    $root   = $flags['isRootAdmin'] ?? 0;
    $full   = $flags['fullName'] ?? ('Merge ' . $name);
    $stmt   = $db->prepare('INSERT INTO `tblUsers` (`fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('ssiii', $full, $email, $active, $admin, $root);
    $stmt->execute();

    return (int) $db->insert_id;
};
$member = static function (int $userId, int $siteId, int $active = 1, int $siteAdmin = 0) use ($db): void {
    $db->query("INSERT INTO `tblUserSites` (`userID`, `siteID`, `isActive`, `isSiteAdmin`) VALUES ({$userId}, {$siteId}, {$active}, {$siteAdmin})");
};
$grantDc = static function (int $userId, int $siteId) use ($db): void {
    $role = (int) ($db->query("SELECT `roleID` FROM `tblRoles` WHERE `siteID` = {$siteId} AND `roleKey` = 'data_controller'")->fetch_assoc()['roleID'] ?? 0);
    $db->query("INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`) VALUES ({$userId}, {$role}, {$siteId})");
};

$adminA = $user('AdminA'); $member($adminA, $siteA, 1, 1); $grantDc($adminA, $siteA);
$adminNoCap = $user('AdminNoCap'); $member($adminNoCap, $siteA, 1, 1);
$capNoAdmin = $user('CapNoAdmin'); $member($capNoAdmin, $siteA); $grantDc($capNoAdmin, $siteA);
$lapsedAdmin = $user('Lapsed'); $member($lapsedAdmin, $siteA, 0, 1); $grantDc($lapsedAdmin, $siteA);
$rootNoRole = $user('RootNoRole', ['isRootAdmin' => 1]); $member($rootNoRole, $siteA);
$rootA = $user('RootA', ['isRootAdmin' => 1]); $member($rootA, $siteA); $grantDc($rootA, $siteA);
$rootAB = $user('RootAB', ['isRootAdmin' => 1]); $member($rootAB, $siteA); $member($rootAB, $siteB);
$grantDc($rootAB, $siteA); $grantDc($rootAB, $siteB);
$kept = $user('Kept'); $member($kept, $siteA);
$dup  = $user('Dup');  $member($dup, $siteA);
$dupAB = $user('DupAB'); $member($dupAB, $siteA); $member($dupAB, $siteB);
$bOnly = $user('BOnly'); $member($bOnly, $siteB);
$closed = $user('Closed', ['isActive' => 0]); $member($closed, $siteA);
$erased = $user('Erased', ['isActive' => 0, 'fullName' => GdprEraser::TOMBSTONE_NAME]);
$db->query("UPDATE `tblUsers` SET `emailAddress` = '" . str_replace('{rid}', 'am' . $tag, GdprEraser::TOMBSTONE_EMAIL) . "' WHERE `userID` = {$erased}");
$siteAdminT = $user('SiteAdminT'); $member($siteAdminT, $siteA, 1, 1);
$portalAdminT = $user('PortalAdminT', ['isAdmin' => 1]); $member($portalAdminT, $siteA);
$rootT = $user('RootT', ['isRootAdmin' => 1]); $member($rootT, $siteA);
$withRequest = $user('WithRequest'); $member($withRequest, $siteA);
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteA}, {$withRequest}, 'r@selftest.invalid', '2027-01-01', 'pending_review')");
$withCancelled = $user('WithCancelled'); $member($withCancelled, $siteA);
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteA}, {$withCancelled}, 'c@selftest.invalid', '2027-01-01', 'cancelled')");
$withOffboarding = $user('WithOffboarding'); $member($withOffboarding, $siteA);
$db->query("INSERT INTO `tblOffboarding` (`userID`, `offboardedByID`, `effectiveDate`) VALUES ({$withOffboarding}, {$adminA}, '2026-01-01')");
$rehired = $user('Rehired'); $member($rehired, $siteA);
$db->query("INSERT INTO `tblOffboarding` (`userID`, `offboardedByID`, `effectiveDate`, `rehiredAt`, `rehiredByID`) VALUES ({$rehired}, {$adminA}, '2026-01-01', '2026-01-03 10:00:00', {$adminA})");
$heldWaiting = $user('HeldWaiting'); $member($heldWaiting, $siteA);
$db->query("INSERT INTO `tblRestoreHeldRows` (`tableName`, `siteID`, `userID`, `rowJson`, `snapshotName`, `heldAtUtc`) VALUES ('tblSettings', {$siteA}, {$heldWaiting}, '{}', 'x', UTC_TIMESTAMP())");
$heldBack = $user('HeldBack'); $member($heldBack, $siteA);
$db->query("INSERT INTO `tblRestoreHeldRows` (`tableName`, `siteID`, `userID`, `rowJson`, `snapshotName`, `heldAtUtc`, `putBackAtUtc`) VALUES ('tblSettings', {$siteA}, {$heldBack}, NULL, 'x', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$demo = $user('DemoPerson'); $member($demo, $siteA);
$db->query("INSERT INTO `tblDemoDataRegister` (`tableName`, `rowID`, `siteID`, `fingerprintColumns`, `fingerprint`) VALUES ('tblUsers', {$demo}, {$siteA}, 'fullName', 'x')");

$ask = static function (int $actor, bool $global, bool $root, int $k, int $d, bool $single = false) use ($db, $siteA): string {
    return M::whyNotAllowed(M::factsFor($db, $actor, $global, $root, $siteA, $single, $k, $d));
};
foreach ([
    ['C1 an administrator of A with the permission, two members of A', [$adminA, false, false, $kept, $dup], ''],
    ['C2 an administrator WITHOUT the permission', [$adminNoCap, false, false, $kept, $dup], M::R_NO_PERMISSION],
    ['C3 the permission WITHOUT being an administrator', [$capNoAdmin, false, false, $kept, $dup], M::R_NO_PERMISSION],
    ['C4 an administrator whose membership is switched off', [$lapsedAdmin, false, false, $kept, $dup], M::R_NO_PERMISSION],
    ['C5 THE HOSTING PROVIDER (D-191): a global administrator with no role here is refused', [$rootNoRole, true, true, $kept, $dup], M::R_NO_PERMISSION],
    ['C6 a global administrator given the role here may', [$rootA, true, true, $kept, $dup], ''],
    ['C7 an account only in B looks missing to A\'s administrator', [$adminA, false, false, $kept, $bOnly], M::R_NOT_FOUND],
    ['C8 an account in A and B needs a global administrator', [$adminA, false, false, $kept, $dupAB], M::R_NEEDS_GLOBAL],
    ['C8b ...and in single-organisation mode the words are the single-mode ones', [$adminA, false, false, $kept, $dupAB, true], M::R_NEEDS_GLOBAL_SINGLE],
    ['C9 a global administrator with the role in A only cannot merge an account also in B', [$rootA, true, true, $kept, $dupAB], M::R_NO_PERMISSION],
    ['C10 ...with the role in both organisations can', [$rootAB, true, true, $kept, $dupAB], ''],
    ['C11 an erased account', [$rootA, true, true, $kept, $erased], M::R_ERASED],
    ['C12 a closed account', [$adminA, false, false, $closed, $dup], M::R_CLOSED],
    ['C13 an organisation administrator\'s account', [$adminA, false, false, $kept, $siteAdminT], M::R_ADMINISTRATOR],
    ['C14 a portal-wide administrator\'s account, asked by a global administrator', [$rootA, true, true, $kept, $portalAdminT], M::R_ADMINISTRATOR],
    ['C15 ...asked by an organisation administrator: the TRUE words (finding 2)', [$adminA, false, false, $kept, $portalAdminT], M::R_WHOLE_PORTAL_ADMIN],
    ['C15b a global administrator\'s account in this organisation only, asked by an organisation administrator', [$adminA, false, false, $kept, $rootT], M::R_WHOLE_PORTAL_ADMIN],
    ['C16 an open erasure request', [$adminA, false, false, $kept, $withRequest], M::R_REFUSE_IF_ANY],
    ['C17 a CANCELLED erasure request refuses nothing', [$adminA, false, false, $kept, $withCancelled], ''],
    ['C18 an offboarding not undone', [$adminA, false, false, $withOffboarding, $dup], M::R_REFUSE_IF_ANY],
    ['C18b an offboarding UNDONE by a rehire refuses nothing (finding 5)', [$adminA, false, false, $rehired, $dup], ''],
    ['C18c a held row still waiting', [$adminA, false, false, $kept, $heldWaiting], M::R_REFUSE_IF_ANY],
    ['C18d a held row already put back refuses nothing (finding 5)', [$adminA, false, false, $kept, $heldBack], ''],
    ['C18e a Demo Data person as the duplicate (finding 1)', [$adminA, false, false, $kept, $demo], M::R_REFUSE_IF_ANY],
    ['C18f ...and as the account that stays', [$adminA, false, false, $demo, $dup], M::R_REFUSE_IF_ANY],
    ['C19 the actor\'s own account', [$rootA, true, true, $rootA, $dup], M::R_IS_YOU],
    ['C20 nobody acting', [0, false, false, $kept, $dup], M::R_NO_PERMISSION],
] as [$label, $args, $want]) {
    $got = $ask(...$args);
    check($label, $got === $want, "got '{$got}', wanted '{$want}'");
}
// Finding 7: the same statements whether the number exists elsewhere or nowhere.
$questions = static fn (): int => (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
$countFor  = static function (int $other) use ($db, $adminA, $siteA, $kept, $questions): int {
    $before = $questions();
    M::factsFor($db, $adminA, false, false, $siteA, false, $kept, $other);

    return $questions() - $before;
};
$elsewhere = $countFor($bOnly);
$nowhere   = $countFor(2000000000);
$erasedN   = $countFor($erased);
check('C21 "exists only in another organisation", "erased there" and "does not exist" issue the SAME number of statements (finding 7)',
    $elsewhere === $nowhere && $erasedN === $nowhere, "elsewhere {$elsewhere}, erased {$erasedN}, nowhere {$nowhere}");

// =============================================================================
echo "D. The preview: one made-up row per listed column\n";
// =============================================================================
$pk = $user('PreviewKept');
$pd = $user('PreviewDup');
$third = $user('PreviewThird');
$columnsOf = [];
foreach (q($db, 'SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c, `DATA_TYPE` AS dt, `COLUMN_TYPE` AS ct, `IS_NULLABLE` AS n, '
    . '`EXTRA` AS x, `CHARACTER_MAXIMUM_LENGTH` AS len FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() '
    . 'ORDER BY `TABLE_NAME`, `ORDINAL_POSITION`') as $r) {
    $columnsOf[$r['t']][] = $r;
}
// ⚠️ A RANDOM START: a fixed one made a second run on the same database collide.
$family = random_int(1000000, 900000000);
$value = static function (array $col, int $fam): mixed {
    switch ((string) $col['dt']) {
        case 'int': case 'bigint': case 'mediumint': return $fam;
        case 'smallint': return ($fam % 30000) + 1;
        case 'tinyint': case 'bit': return 1;
        case 'decimal': case 'float': case 'double': return 1;
        case 'year': return 2026;
        case 'enum': case 'set':
            preg_match("/^(?:enum|set)\\('((?:[^']|'')*)'/", (string) $col['ct'], $m);
            return str_replace("''", "'", $m[1]);
        case 'json': return '{}';
        case 'date': return '2026-01-15';
        case 'datetime': case 'timestamp': return '2026-01-15 10:00:00';
        case 'time': return '10:00:00';
        case 'binary': case 'varbinary': return random_bytes(max(1, min(16, (int) ($col['len'] ?? 16))));
        case 'tinyblob': case 'blob': case 'mediumblob': case 'longblob': return 'x';
        default:
            return substr(strrev((string) $fam) . '-am', 0, max(1, min(40, (int) ($col['len'] ?? 40))));
    }
};
$insert = static function (string $table, array $fixed, int $other) use ($db, &$columnsOf, &$family, $value, $listed): ?array {
    $family++;
    $row = [];
    foreach ($columnsOf[$table] as $col) {
        $name = (string) $col['c'];
        if (stripos((string) $col['x'], 'GENERATED') !== false || stripos((string) $col['x'], 'auto_increment') !== false) {
            continue;
        }
        if (array_key_exists($name, $fixed) === true) {
            $row[$name] = $fixed[$name];
        } elseif (isset($listed[$table . '.' . $name]) === true && $listed[$table . '.' . $name]['when'] === '') {
            $row[$name] = $other;
        } else {
            $row[$name] = $value($col, $family);
        }
    }
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')';
    try {
        $stmt  = $db->prepare($sql);
        $types = '';
        foreach ($row as $v) {
            $types .= is_int($v) === true ? 'i' : 's';
        }
        $vals = array_values($row);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        return null;
    }

    return $row;
};
$fixedFor = static function (string $name, int $who): array {
    [, $c] = explode('.', $name);
    return match ($name) {
        'tblWorkflowSteps.assigneeValue'   => ['assigneeType' => 'user', 'assigneeValue' => (string) $who],
        'tblExternalAudienceMembers.refID' => ['kind' => 'person', 'refID' => $who],
        'tblAuditTrail.recordID'           => ['tableName' => 'tblUsers', 'recordID' => $who],
        'tblDemoDataRegister.rowID'        => ['tableName' => 'tblUsers', 'rowID' => $who],
        default                            => [$c => $who],
    };
};
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$madeRows = [];
$couldNotMake = [];
foreach (array_keys($listed) as $name) {
    [$t] = explode('.', $name);
    $row = $insert($t, $fixedFor($name, $pd), $third);
    if ($row === null) {
        $couldNotMake[] = $name;
    } else {
        $madeRows[$name] = $row;
    }
}
check('D1 a made-up row naming the duplicate went into every listed column', $couldNotMake === [], implode(', ', $couldNotMake));
$preview = M::preview($db, $pk, $pd);
$byName = [];
foreach ($preview['lines'] as $line) {
    $byName[$line['table'] . '.' . $line['column']] = $line;
}
$wrongCount = [];
foreach (array_keys($madeRows) as $name) {
    if (($byName[$name]['rows'] ?? 0) !== 1) {
        $wrongCount[] = $name . '=' . ($byName[$name]['rows'] ?? 0);
    }
}
check('D2 every listed column naming the duplicate is counted, exactly once', $wrongCount === [], implode(', ', $wrongCount));
check('D3 ...under the treatment the list gives it, in plain words',
    array_filter($preview['lines'], static fn (array $l): bool => $l['treatment'] !== $listed[$l['table'] . '.' . $l['column']]['treatment']
        || str_contains((string) $l['words'], 'tbl') === true) === []);
check('D4 with no clashes, nothing refuses', $preview['refusal'] === '' && array_sum(array_column($preview['lines'], 'clashes')) === 0, $preview['refusal']);
$notCounted = [];
foreach ($accountColumns as $name) {
    if (isset(M::NOT_AN_ACCOUNT[$name]) === true) {
        continue;
    }
    [$t, $c] = explode('.', $name);
    $count = n($db, "SELECT COUNT(*) AS n FROM `{$t}` WHERE `{$c}` = {$pd}");
    if ($count !== ($byName[$name]['rows'] ?? 0)) {
        $notCounted[] = "{$name}: {$count} in the table, " . ($byName[$name]['rows'] ?? 0) . ' in the preview';
    }
}
check('D5 NOTHING ESCAPES: every row naming the duplicate in any account column is in the preview', $notCounted === [], implode('; ', $notCounted));
// Finding 4: a REAL unknown clash. A unique key the list does not know about,
// on a column the list calls plain "move", must refuse every merge.
$db->query('ALTER TABLE `tblExpenseClaimPayments` ADD UNIQUE KEY `uq_am_selftest` (`paidByID`)');
$unknown = M::preview($db, $pk, $pd);
$db->query('ALTER TABLE `tblExpenseClaimPayments` DROP INDEX `uq_am_selftest`');
check('D6 a unique key the list does not know about refuses the merge (finding 4)',
    $unknown['refusal'] === M::R_UNKNOWN_CLASH && in_array('tblExpenseClaimPayments.paidByID', $unknown['unknown'], true), json_encode($unknown['unknown']));
$clashMade = [];
foreach ($madeRows as $name => $row) {
    [$t, $c] = explode('.', $name);
    if (isset($keys[$t][$c]) === false || in_array($listed[$name]['treatment'], [M::KEEP_KEPT_ON_CLASH, M::STRICTER_ON_CLASH, M::SAME_OR_REFUSE, M::REFUSE_ON_CLASH], true) === false) {
        continue;
    }
    $fixed = $fixedFor($name, $pk);
    foreach ($keys[$t][$c] as $others) {
        foreach ($others as $o) {
            $fixed[$o] = $row[$o];
        }
    }
    if ($insert($t, $fixed, $third) !== null) {
        $clashMade[] = $name;
    } else {
        check("D7 a clashing row could be made for {$name}", false);
    }
}
$preview2 = M::preview($db, $pk, $pd);
$clashes = [];
foreach ($preview2['lines'] as $line) {
    $clashes[$line['table'] . '.' . $line['column']] = $line['clashes'];
}
$missedClash = array_values(array_filter($clashMade, static fn (string $n): bool => ($clashes[$n] ?? 0) !== 1));
check('D8 every clash is found, once (' . count($clashMade) . ' columns)', $clashMade !== [] && $missedClash === [], implode(', ', $missedClash));
check('D9 a clash on a money record refuses', $preview2['refusal'] === M::R_CLASH, $preview2['refusal']);
foreach (M::BY_TREATMENT[M::REFUSE_ON_CLASH] as $name) {
    [$t, $c] = explode('.', $name);
    $db->query("DELETE FROM `{$t}` WHERE `{$c}` = {$pk}");
}
$preview3 = M::preview($db, $pk, $pd);
check('D10 with money clashes gone, rows that differ refuse as "choices differ"', $preview3['refusal'] === M::R_CHOICES_DIFFER, $preview3['refusal']);
// A polymorphic column's other rows are neither counted nor a clash.
$before = array_values(array_filter($preview3['lines'], static fn (array $l): bool => $l['table'] . '.' . $l['column'] === 'tblExternalAudienceMembers.refID'));
$owner  = random_int(1000000, 9000000);
$insert('tblExternalAudienceMembers', ['kind' => 'role', 'refID' => $pd, 'ownerType' => 'feed', 'ownerID' => $owner], $third);
$insert('tblExternalAudienceMembers', ['kind' => 'role', 'refID' => $pk, 'ownerType' => 'feed', 'ownerID' => $owner], $third);
$after = array_values(array_filter(M::preview($db, $pk, $pd)['lines'], static fn (array $l): bool => $l['table'] . '.' . $l['column'] === 'tblExternalAudienceMembers.refID'));
check('D11 rows where a polymorphic column holds a role, not a person, are neither counted nor a clash', $before !== [] && $after === $before, json_encode([$before, $after]));
$db->query('SET FOREIGN_KEY_CHECKS = 1');

// =============================================================================
echo "F. Completion, in-process\n";
// =============================================================================
/** Start a request as adminA, agree from the duplicate, then from the kept account. */
$merge = static function (int $k, int $d, bool $tick) use ($db, $adminA, $siteA): string {
    $started = M::start($db, $adminA, false, false, $siteA, false, $k, $d);
    if ($started['outcome'] !== 'started') {
        return 'start:' . $started['outcome'];
    }
    $first = M::agree($db, $started['requestId'], $d, true, '', 0, false);
    if ($first['outcome'] !== 'agreed_first') {
        return 'first:' . $first['outcome'];
    }

    return M::agree($db, $started['requestId'], $k, true, $first['token'], $d, $tick)['outcome'];
};
$fk = $user('FullKept'); $member($fk, $siteA);
$fd = $user('FullDup');  $member($fd, $siteA);
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$fullRows = [];
foreach (array_keys($listed) as $name) {
    [$t] = explode('.', $name);
    if ($name === 'tblDemoDataRegister.rowID' || $name === 'tblUserSites.userID') {
        continue; // a Demo Data person refuses (section C); the membership is the real one above
    }
    $fixed = $fixedFor($name, $fd);
    if ($name === 'tblErasureRequest.userID') {
        $fixed['status'] = 'cancelled';
    }
    if ($insert($t, $fixed, $third) !== null) {
        $fullRows[] = $name;
    }
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$db->query("UPDATE `tblUsers` SET `phoneNumber` = '01234 567890', `notifyPrefs` = NULL WHERE `userID` = {$fd}");
// ⚠️ FOREIGN-KEY CHECKS OFF FOR THIS ONE MERGE, said plainly: the made-up rows
//    point at organisations and parent rows that do not exist, and InnoDB
//    re-checks those links when a row is updated. Every OTHER merge in this file
//    runs with the checks on, over real rows.
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$outcome = $merge($fk, $fd, true);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
check('F1 a merge agreed from both accounts completes', $outcome === 'completed', $outcome);
$left = [];
foreach ($fullRows as $name) {
    [$t, $c] = explode('.', $name);
    $entry = $listed[$name];
    $where = "`{$c}` = " . ($entry['when'] === '' ? $fd : "'{$fd}'") . ($entry['when'] === '' ? '' : ' AND ' . str_replace('{t}', "`{$t}`", $entry['when']));
    $countD = n($db, "SELECT COUNT(*) AS n FROM `{$t}` WHERE {$where}");
    // History keeps AT LEAST the made-up row (the merge's own side row names
    // the duplicate too); everything else must name it no more.
    $isKeep = $entry['treatment'] === M::KEEP;
    if (($isKeep === true && $countD < 1) || ($isKeep === false && $countD !== 0)) {
        $left[] = "{$name} ({$entry['treatment']}): {$countD} left";
    }
}
check('F2 THE CORE: no row names the duplicate any more, except history (D-116)', $left === [], implode('; ', $left));
$arrived = [];
foreach ($fullRows as $name) {
    [$t, $c] = explode('.', $name);
    $entry = $listed[$name];
    if (in_array($entry['treatment'], [M::KEEP, M::SIGN_IN_DROPPED], true) === true) {
        continue;
    }
    $where = "`{$c}` = " . ($entry['when'] === '' ? $fk : "'{$fk}'") . ($entry['when'] === '' ? '' : ' AND ' . str_replace('{t}', "`{$t}`", $entry['when']));
    if (n($db, "SELECT COUNT(*) AS n FROM `{$t}` WHERE {$where}") < 1) {
        $arrived[] = $name;
    }
}
check('F3 ...and every moved row now names the kept account (ticked: sign-ins included)', $arrived === [], implode(', ', $arrived));
check('F4 the duplicate\'s password, two-step codes and kiosk PIN are deleted, never moved',
    n($db, "SELECT COUNT(*) AS n FROM `tblLocalAccounts` WHERE `userID` IN ({$fk}, {$fd})") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblTotpBackupCodes` WHERE `userID` IN ({$fk}, {$fd})") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblAssetKioskPins` WHERE `userID` IN ({$fk}, {$fd})") === 0);
$closedRow = q($db, "SELECT `isActive`, `fullName`, `emailAddress`, `phoneNumber`, `signInVersion` FROM `tblUsers` WHERE `userID` = {$fd}")[0];
check('F5 the duplicate is closed, not deleted, and its own details are emptied',
    (int) $closedRow['isActive'] === 0 && $closedRow['fullName'] === 'Merged account'
    && $closedRow['emailAddress'] === "merged-{$fd}@merged.invalid" && $closedRow['phoneNumber'] === null, json_encode($closedRow));
$record = q($db, "SELECT `activityDescription` AS d FROM `tblActivityLogs` WHERE `activityType` = 'AccountsMerged' ORDER BY `logID` DESC LIMIT 1");
check('F6 the record holds two numbers and fixed words only',
    preg_match('/^Account ' . $fd . ' was merged into account ' . $fk . ' \(request \d+, ways of signing in brought across\)$/', (string) ($record[0]['d'] ?? '')) === 1,
    (string) ($record[0]['d'] ?? ''));
$again = M::agree($db, (int) q($db, "SELECT MAX(`requestID`) AS r FROM `tblAccountMergeRequest`")[0]['r'], $fk, true, 'x', $fd, true);
check('F7 a second press finds it done and changes nothing', $again['outcome'] === 'done_already', $again['outcome']);

// Not ticked: the duplicate's Microsoft/Google link and passkey are deleted.
$nk = $user('NoTickKept'); $member($nk, $siteA);
$nd = $user('NoTickDup');  $member($nd, $siteA);
$db->query("INSERT INTO `tblLinkedAccounts` (`userID`, `provider`, `providerSub`, `providerEmail`) VALUES ({$nd}, 'Google', 'sub-{$tag}', 'g@selftest.invalid')");
$db->query("INSERT INTO `tblWebAuthnCredentials` (`userID`, `credentialID`, `publicKey`, `signCount`) VALUES ({$nd}, 'cred-{$tag}', 'pk', 0)");
check('F8 without the tick the merge completes', $merge($nk, $nd, false) === 'completed');
check('F9 ...and the duplicate\'s Microsoft/Google link and passkey are deleted, not moved',
    n($db, "SELECT COUNT(*) AS n FROM `tblLinkedAccounts` WHERE `userID` IN ({$nk}, {$nd})") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblWebAuthnCredentials` WHERE `userID` IN ({$nk}, {$nd})") === 0);

// Strictest wins, in BOTH orders (finding 6; D-058, D-073).
foreach (['kept says no' => true, 'duplicate says no' => false] as $order => $keptSaysNo) {
    $sk = $user('StrictKept' . (int) $keptSaysNo); $member($sk, $siteA);
    $sd = $user('StrictDup' . (int) $keptSaysNo);  $member($sd, $siteA);
    [$kOpt, $dOpt] = $keptSaysNo ? [0, 1] : [1, 0];
    [$kCh, $dCh]   = $keptSaysNo ? ['off', 'on'] : ['on', 'off'];
    $db->query("INSERT INTO `tblNewsletterSubscription` (`siteID`, `userID`, `optedIn`, `unsubToken`) VALUES ({$siteA}, {$sk}, {$kOpt}, '" . bin2hex(random_bytes(20)) . "'), ({$siteA}, {$sd}, {$dOpt}, '" . bin2hex(random_bytes(20)) . "')");
    $db->query("INSERT INTO `tblUserOutsideServicePref` (`userID`, `serviceKey`, `choice`) VALUES ({$sk}, 'gravatar', '{$kCh}'), ({$sd}, 'gravatar', '{$dCh}')");
    $db->query("INSERT INTO `tblUserTranslationPref` (`userID`, `autoTranslate`) VALUES ({$sk}, {$kOpt}), ({$sd}, {$dOpt})");
    $db->query("UPDATE `tblUsers` SET `notifyPrefs` = '" . ($keptSaysNo ? '{"eventReminders":false}' : '{}') . "' WHERE `userID` = {$sk}");
    $db->query("UPDATE `tblUsers` SET `notifyPrefs` = '" . ($keptSaysNo ? '{}' : '{"eventReminders":false}') . "' WHERE `userID` = {$sd}");
    check("F10 ({$order}) the merge completes", $merge($sk, $sd, false) === 'completed');
    check("F11 ({$order}) the newsletter unsubscribe survives", n($db, "SELECT `optedIn` AS n FROM `tblNewsletterSubscription` WHERE `userID` = {$sk}") === 0
        && n($db, "SELECT COUNT(*) AS n FROM `tblNewsletterSubscription` WHERE `userID` IN ({$sk}, {$sd})") === 1);
    check("F12 ({$order}) the outside-service \"off\" survives", (q($db, "SELECT `choice` FROM `tblUserOutsideServicePref` WHERE `userID` = {$sk}")[0]['choice'] ?? '') === 'off');
    check("F13 ({$order}) the translation consent \"no\" survives", n($db, "SELECT `autoTranslate` AS n FROM `tblUserTranslationPref` WHERE `userID` = {$sk}") === 0);
    $prefs = (string) (q($db, "SELECT `notifyPrefs` FROM `tblUsers` WHERE `userID` = {$sk}")[0]['notifyPrefs'] ?? '');
    check("F14 ({$order}) the notification \"no\" survives", \Portal\Core\WebPush::notifyPrefAllows($prefs, 'eventReminders') === false, $prefs);
}

// Differing choices refuse, and change nothing; agreeing ones merge.
$rk = $user('RsvpKept'); $member($rk, $siteA);
$rd = $user('RsvpDup');  $member($rd, $siteA);
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query("INSERT INTO `tblEventRSVPs` (`eventID`, `userID`, `response`) VALUES (8101, {$rk}, 'going'), (8101, {$rd}, 'not_going')");
$db->query("INSERT INTO `tblSmallGroupMembers` (`siteID`, `groupID`, `userID`, `memberRole`, `status`) VALUES ({$siteA}, 8201, {$rk}, 'member', 'active'), ({$siteA}, 8201, {$rd}, 'leader', 'active')");
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$refused = $merge($rk, $rd, false);
check('F15 two different replies to one event refuse the merge', $refused === 'start:' . M::R_CHOICES_DIFFER, $refused);
check('F16 ...and nothing changed', n($db, "SELECT COUNT(*) AS n FROM `tblEventRSVPs` WHERE `userID` = {$rd}") === 1
    && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$rd}") === 1);
$db->query("UPDATE `tblEventRSVPs` SET `response` = 'going' WHERE `userID` = {$rd}");
$still = $merge($rk, $rd, false);
check('F17 a different role in the same group still refuses (the wider is never granted by a merge)', $still === 'start:' . M::R_CHOICES_DIFFER, $still);
$db->query("UPDATE `tblSmallGroupMembers` SET `memberRole` = 'member' WHERE `userID` = {$rd}");
check('F18 once they agree, the merge completes', $merge($rk, $rd, false) === 'completed');
check('F19 ...leaving ONE reply and ONE membership, on the kept account',
    n($db, "SELECT COUNT(*) AS n FROM `tblEventRSVPs` WHERE `userID` IN ({$rk}, {$rd}) AND `eventID` = 8101") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblSmallGroupMembers` WHERE `userID` = {$rk} AND `groupID` = 8201") === 1);

// The two-sided proof, at the class level (the pages are section E).
$pk2 = $user('ProofKept'); $member($pk2, $siteA);
$pd2 = $user('ProofDup');  $member($pd2, $siteA);
$req = M::start($db, $adminA, false, false, $siteA, false, $pk2, $pd2)['requestId'];
check('F20 a second open request for the same pair is not made', M::start($db, $adminA, false, false, $siteA, false, $pd2, $pk2)['outcome'] === 'already_waiting');
check('F21 no fresh proof: refused', M::agree($db, $req, $pd2, false, '', 0, false)['outcome'] === 'needs_proof');
check('F22 somebody who is neither account: "not found"', M::agree($db, $req, $kept, true, '', 0, false)['outcome'] === M::R_NOT_FOUND);
$t1 = M::agree($db, $req, $pd2, true, '', 0, false);
$t2 = M::agree($db, $req, $pd2, true, $t1['token'], $pd2, false);
check('F23 the SAME account agreeing twice never completes (it restarts)', $t2['outcome'] === 'agreed_first'
    && n($db, "SELECT `status` = 'waiting' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$req}") === 1);
check('F24 the other account WITHOUT the browser token restarts instead of completing',
    M::agree($db, $req, $pk2, true, '', $pd2, false)['outcome'] === 'agreed_first'
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `requestID` = {$req} AND `confirmedAtUtc` IS NOT NULL") === 1);
$t3 = M::agree($db, $req, $pd2, true, '', 0, false);
$db->query("UPDATE `tblAccountMergeSide` SET `confirmedAtUtc` = UTC_TIMESTAMP() - INTERVAL 31 MINUTE WHERE `requestID` = {$req} AND `userID` = {$pd2}");
check('F25 the other account after 30 minutes restarts', M::agree($db, $req, $pk2, true, $t3['token'], $pd2, false)['outcome'] === 'agreed_first');
$t4 = M::agree($db, $req, $pd2, true, '', 0, false);
$db->query("UPDATE `tblAccountMergeSide` SET `confirmedAtUtc` = UTC_TIMESTAMP() + INTERVAL 5 MINUTE WHERE `requestID` = {$req} AND `userID` = {$pd2}");
check('F26 an agreement dated in the future is not trusted', M::agree($db, $req, $pk2, true, $t4['token'], $pd2, false)['outcome'] === 'agreed_first');
$t5 = M::agree($db, $req, $pd2, true, '', 0, false);
check('F27 the wrong number typed: refused, nothing merged', M::agree($db, $req, $pk2, true, $t5['token'], $pk2, false)['outcome'] === 'typed_wrong'
    && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$pd2}") === 1);
$db->query("UPDATE `tblAccountMergeRequest` SET `expiresAtUtc` = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE `requestID` = {$req}");
check('F28 an expired request cannot be agreed to', M::agree($db, $req, $pk2, true, $t5['token'], $pd2, false)['outcome'] === 'expired');
$db->query("UPDATE `tblAccountMergeRequest` SET `expiresAtUtc` = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE `requestID` = {$req}");
check('F29 a person who is not one of the two cannot cancel it', M::cancel($db, $req, $kept, false, 0) === 'not_found');
check('F30 an administrator of ANOTHER organisation cannot cancel it', M::cancel($db, $req, $adminA, true, $siteB) === 'not_found');
check('F31 either of the two accounts can cancel it', M::cancel($db, $req, $pd2, false, 0) === 'cancelled');
check('F32 a cancelled request cannot be agreed to', M::agree($db, $req, $pk2, true, $t5['token'], $pd2, false)['outcome'] === 'cancelled');

// =============================================================================
echo "G. Two processes complete the same request at once\n";
// =============================================================================
$gk = $user('RaceKept'); $member($gk, $siteA);
$gd = $user('RaceDup');  $member($gd, $siteA);
$greq   = M::start($db, $adminA, false, false, $siteA, false, $gk, $gd)['requestId'];
$gtoken = M::agree($db, $greq, $gd, true, '', 0, false)['token'];
$scratch = sys_get_temp_dir() . $sep . 'account-merge-race-' . bin2hex(random_bytes(5));
mkdir($scratch, 0700);
$worker = $scratch . $sep . 'worker.php';
file_put_contents($worker, '<?php
declare(strict_types=1);
[, $core, $host, $user, $pass, $name, $port, $req, $k, $d, $token, $go] = $argv;
define("PORTAL_CORE", $core);
foreach (' . var_export(AM_CLASSES, true) . ' as $c) { require_once $core . "/" . $c . ".php"; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($host, $user, $pass, $name, (int) $port);
\\Portal\\Core\\Site::init($mysqli, ["site" => ["timezoneKeptAt" => ""]], 1);
for ($i = 0; $i < 400 && is_file($go) === false; $i++) { usleep(10000); }
echo \\Portal\\Core\\AccountMerge::agree($mysqli, (int) $req, (int) $k, true, $token, (int) $d, false)["outcome"];
');
$go = $scratch . $sep . 'go';
$procs = [];
foreach ([1, 2] as $i) {
    $procs[$i] = proc_open([PHP_BINARY, $worker, rtrim($coreDir, $sep), $dbHost, $dbUser, $dbPass, $dbName, (string) $dbPort,
        (string) $greq, (string) $gk, (string) $gd, $gtoken, $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
usleep(300000);
touch($go);
$outs = [];
foreach ([1, 2] as $i) {
    $outs[] = trim((string) stream_get_contents($pipes[$i][1])) . trim((string) stream_get_contents($pipes[$i][2]));
    proc_close($procs[$i]);
}
@unlink($go);
@unlink($worker);
@rmdir($scratch);
sort($outs);
check('G1 two processes completing at once: exactly one merges, the other is told it is done', $outs === ['completed', 'done_already'], json_encode($outs));
check('G2 ...and the duplicate\'s rows moved once', n($db, "SELECT COUNT(*) AS n FROM `tblUserSites` WHERE `userID` = {$gk}") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblUserSites` WHERE `userID` = {$gd}") === 0);

// =============================================================================
echo "E. The pages over HTTP\n";
// =============================================================================
$siteOne = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s']);
$pass    = 'Am' . bin2hex(random_bytes(8)) . '!9x';
$login   = static function (int $id, string $name) use ($db, $pass, $tag): string {
    $username = 'am-' . strtolower($name) . '-' . $tag;
    $hash     = password_hash($pass, PASSWORD_DEFAULT);
    $stmt     = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $stmt->bind_param('iss', $id, $username, $hash);
    $stmt->execute();

    return $username;
};
$eAdmin  = $user('PageDc');    $member($eAdmin, $siteOne, 1, 1); $grantDc($eAdmin, $siteOne); $loginDc = $login($eAdmin, 'PageDc');
$eNoCap  = $user('PageNoCap'); $member($eNoCap, $siteOne, 1, 1); $loginNoCap = $login($eNoCap, 'PageNoCap');
$eKept   = $user('PageKept', ['fullName' => 'Kept Name ' . $tag]); $member($eKept, $siteOne); $loginKept = $login($eKept, 'PageKept');
$eDup    = $user('PageDup', ['fullName' => 'Dup Name ' . $tag]);   $member($eDup, $siteOne);  $loginDup = $login($eDup, 'PageDup');
$eStrange = $user('PageStranger'); $member($eStrange, $siteOne); $loginStranger = $login($eStrange, 'PageStranger');
$eBOnly  = $user('PageBOnly'); $member($eBOnly, $siteB);
$eErased = $user('PageErased', ['isActive' => 0, 'fullName' => GdprEraser::TOMBSTONE_NAME]);
$db->query("UPDATE `tblUsers` SET `emailAddress` = '" . str_replace('{rid}', 'pe' . $tag, GdprEraser::TOMBSTONE_EMAIL) . "' WHERE `userID` = {$eErased}");
$db->query("INSERT INTO `tblPrayerRequests` (`siteID`, `submitterID`, `subject`, `body`) VALUES ({$siteOne}, {$eDup}, 'Secret subject {$tag}', 'Secret body {$tag}')");
$eAdminT = $user('PageAdminT', ['fullName' => 'Admin Target Name ' . $tag]); $member($eAdminT, $siteOne, 1, 1);

$multi = q($db, "SELECT `settingValue` FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` = 'multisite.enabled'");
$setMulti = static function (string $value) use ($db, $multi): void {
    if ($multi === []) {
        $db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, 'multisite.enabled', '{$value}', 'false', 0)");
    } else {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '{$value}' WHERE `siteID` IS NULL AND `settingKey` = 'multisite.enabled'");
    }
};

$child = sys_get_temp_dir() . $sep . 'account-merge-' . bin2hex(random_bytes(6));
$state = ['proc' => null, 'child' => $child];
register_shutdown_function(static function () use (&$state, $db, $multi): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
    }
    // Put the installation's setting back, whatever happened.
    if ($multi === []) {
        $db->query("DELETE FROM `tblSettings` WHERE `siteID` IS NULL AND `settingKey` = 'multisite.enabled'");
    } else {
        $db->query("UPDATE `tblSettings` SET `settingValue` = '" . $db->real_escape_string((string) $multi[0]['settingValue']) . "' WHERE `siteID` IS NULL AND `settingKey` = 'multisite.enabled'");
    }
    if (is_dir($state['child']) === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state['child'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($state['child']);
    }
});
mkdir($child, 0700, true);
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($walk as $entry) {
    $to = $child . $sep . 'web' . substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    $entry->isDir() === true ? @mkdir($to, 0700, true) : copy($entry->getPathname(), $to);
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php', "<?php\nreturn "
    . var_export(['db_host' => $dbHost, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'db_name' => $dbName, 'db_port' => $dbPort], true) . ";\n");
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME'] = substr($target, strlen($root));
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER);
$port = 8960 + random_int(0, 30);
$env  = getenv();
unset($env['PHP_CLI_SERVER_WORKERS']);
$env['PORTAL_ENV'] = 'prod';
$state['proc'] = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $child . $sep . 'sessions', '-d', 'display_errors=0',
    '-d', 'log_errors=1', '-d', 'error_log=' . $child . $sep . 'php-errors.log', '-S', '127.0.0.1:' . $port,
    '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $child . $sep . 'server.log', 'a'], 2 => ['file', $child . $sep . 'server.log', 'a']],
    $pipes, $child, $env);
$jarA = $child . $sep . 'jar-a.txt';
$jarB = $child . $sep . 'jar-b.txt';
$jar  = $jarA;
$http = static function (string $method, string $path, array $post = []) use ($port, &$jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    curl_close($h);

    return ['status' => $code, 'body' => (string) $body];
};
for ($i = 0; $i < 80 && $http('GET', '/login')['status'] === 0; $i++) {
    usleep(250000);
}
// A page with no form still carries the token in its <meta name="csrf-token">.
$tokenOn = static fn (string $html): string => preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1
    ? $m[1] : (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $n) === 1 ? $n[1] : '');
/** Sign in through the real form; $fresh=false keeps the browser's other cookies (the merge token). */
$signIn = static function (string $username) use ($http, $tokenOn, $pass): bool {
    $http('GET', '/logout');
    $page = $http('GET', '/login');
    $in   = $http('POST', '/login', ['csrf_token' => $tokenOn($page['body']), 'username' => $username, 'password' => $pass]);

    return $in['status'] === 302 || $in['status'] === 303;
};
$plain = static fn (string $body): string => (string) preg_replace(['/nonce="[^"]*"/', '/value="\d*"/', '/name="csrf_token" value="[^"]*"/', '/content="[0-9a-f]{64}"/'], ['nonce=""', 'value=""', '', ''], $body);
$mergeUrl = static fn (int $k, int $d): string => '/admin/users/merge?keep=' . $k . '&duplicate=' . $d;

foreach (['several organisations' => 'true', 'one organisation' => 'false'] as $mode => $flag) {
    $setMulti($flag);
    $jar = $jarA;
    @unlink($jar);
    check("E1 [{$mode}] the administrator WITHOUT the permission is refused the page (403)", $signIn($loginNoCap) && $http('GET', '/admin/users/merge')['status'] === 403);
    $users = $http('GET', '/admin/users');
    check("E2 [{$mode}] ...and the Users page offers no link to it", str_contains($users['body'], 'admin/users/merge') === false);
    $token = $tokenOn($users['body']);
    $requestsBefore = n($db, 'SELECT COUNT(*) AS n FROM `tblAccountMergeRequest`');
    check("E2b [{$mode}] ...and asking the person is refused (403), making no request",
        $http('POST', '/admin/users/merge/start', ['csrf_token' => $token, 'keep' => (string) $eKept, 'duplicate' => (string) $eDup])['status'] === 403
        && n($db, 'SELECT COUNT(*) AS n FROM `tblAccountMergeRequest`') === $requestsBefore);
    check("E2c [{$mode}] ...and cancelling a request is refused (403)",
        $http('POST', '/admin/users/merge/cancel', ['csrf_token' => $token, 'requestID' => '1'])['status'] === 403);
    check("E3 [{$mode}] the administrator with the permission is let in, and offered the link", $signIn($loginDc) && str_contains($http('GET', '/admin/users')['body'], '/admin/users/merge'));
    $page = $http('GET', $mergeUrl($eKept, $eDup));
    check("E4 [{$mode}] a preview draws to the end", $page['status'] === 200 && str_contains($page['body'], '</html>'));
    check("E5 [{$mode}] ...counts the prayer request in plain words, never what it says",
        preg_match('#Prayer requests \(the person&\#039;s own\)</div>\s*<div[^>]*data-merge-rows>1</div>#', $page['body']) === 1
        && str_contains($page['body'], 'Secret subject ' . $tag) === false && str_contains($page['body'], 'tblPrayerRequests') === false);
    check("E6 [{$mode}] ...offers to ASK the person, and no form that merges", str_contains($page['body'], 'data-merge-start')
        && preg_match('#<form[^>]*action="[^"]*account/merge/save"#', $page['body']) === 0);
    $refused = $http('GET', $mergeUrl($eKept, $eAdminT));
    check("E7 [{$mode}] a refused preview names nobody", str_contains($refused['body'], 'data-merge-refusal="' . M::R_ADMINISTRATOR . '"')
        && str_contains($refused['body'], 'Admin Target Name ' . $tag) === false);
    $nowhere  = $http('GET', $mergeUrl($eKept, 2000000000));
    $elsewhere = $http('GET', $mergeUrl($eKept, $eBOnly));
    $erasedP  = $http('GET', $mergeUrl($eKept, $eErased));
    if ($flag === 'true') {
        check('E8 [several organisations] "does not exist", "only in another organisation" and "erased there" are BYTE-IDENTICAL (finding 3)',
            $plain($nowhere['body']) === $plain($elsewhere['body']) && $plain($nowhere['body']) === $plain($erasedP['body'])
            && str_contains($nowhere['body'], 'data-merge-refusal="' . M::R_NOT_FOUND . '"'));
    } else {
        check('E8 [one organisation] an account with membership records elsewhere gets the single-mode words, which are true',
            str_contains($elsewhere['body'], 'data-merge-refusal="' . M::R_NEEDS_GLOBAL_SINGLE . '"')
            && str_contains($elsewhere['body'], 'another organisation') === false);
        check('E8b [one organisation] an erased account is called erased (true: there is one organisation)', str_contains($erasedP['body'], 'data-merge-refusal="' . M::R_ERASED . '"'));
    }
    $odd = $http('GET', '/admin/users/merge?keep[]=1&duplicate=abc');
    check("E9 [{$mode}] a nonsense address draws the empty form, not an error", $odd['status'] === 200 && str_contains($odd['body'], 'data-merge-refusal') === false);
}
$setMulti('true');

// The person's flow.
$jar = $jarA;
@unlink($jar);
$signIn($loginDc);
$page  = $http('GET', $mergeUrl($eKept, $eDup));
$start = $http('POST', '/admin/users/merge/start', ['csrf_token' => $tokenOn($page['body']), 'keep' => (string) $eKept, 'duplicate' => (string) $eDup]);
$rid   = (int) (q($db, "SELECT MAX(r.`requestID`) AS r FROM `tblAccountMergeRequest` r JOIN `tblAccountMergeSide` s ON s.`requestID` = r.`requestID` AND s.`userID` = {$eDup}")[0]['r'] ?? 0);
check('E10 asking the person records ONE waiting request and redirects', ($start['status'] === 302 || $start['status'] === 303) && $rid > 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$rid} AND `status` = 'waiting'") === 1);
check('E11 nothing was merged by the administrator', n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$eDup}") === 1);
$page  = $http('GET', $mergeUrl($eKept, $eDup));
$again = $http('POST', '/admin/users/merge/start', ['csrf_token' => $tokenOn($page['body']), 'keep' => (string) $eKept, 'duplicate' => (string) $eDup]);
check('E12 asking again makes no second request', n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `userID` = {$eDup}") === 1);
check('E13 the administrator cannot agree for the person: their account is neither side (404)', $http('GET', '/account/merge?request=' . $rid)['status'] === 404);

$signIn($loginStranger);
$notYours = $http('GET', '/account/merge?request=' . $rid);
$noSuch   = $http('GET', '/account/merge?request=2000000000');
check('E14 the person-side page: "not yours" and "no such request" are BYTE-IDENTICAL 404s',
    $notYours['status'] === 404 && $noSuch['status'] === 404 && $plain($notYours['body']) === $plain($noSuch['body']));

check('E15 the duplicate signs in', $signIn($loginDup));
$first = $http('GET', '/account/merge?request=' . $rid);
check('E16 ...sees the first step, the two NUMBERS, and none of the other account\'s details',
    str_contains($first['body'], 'data-merge-state="first"') && str_contains($first['body'], 'account ' . $eKept)
    && str_contains($first['body'], 'Kept Name ' . $tag) === false && str_contains($first['body'], 'Secret body ' . $tag) === false);
check('E17 ...and is told plainly it cannot be undone', str_contains($first['body'], 'This cannot be undone'));
$agree1 = $http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($first['body']), 'requestID' => (string) $rid, 'action' => 'agree']);
check('E18 agreeing for the duplicate is recorded and the browser is given its token',
    ($agree1['status'] === 302 || $agree1['status'] === 303) && str_contains((string) file_get_contents($jar), M::BROWSER_COOKIE)
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `requestID` = {$rid} AND `userID` = {$eDup} AND `confirmedAtUtc` IS NOT NULL") === 1);
$spent = $http('GET', '/account/merge?request=' . $rid);
check('E19 the proof was spent: the same sign-in cannot agree again', str_contains($spent['body'], 'data-merge-state="needs-proof"'));

// The kept account in a DIFFERENT browser (no token) restarts, it does not complete.
$jar = $jarB;
@unlink($jar);
$signIn($loginKept);
$other = $http('GET', '/account/merge?request=' . $rid);
check('E20 the other account in a browser WITHOUT the token is offered only the first step', str_contains($other['body'], 'data-merge-state="first"'));
$jar = $jarA;

// Back in the first browser: sign out, sign in as the kept account, finish.
check('E21 the kept account signs in, in the SAME browser', $signIn($loginKept));
$final = $http('GET', '/account/merge?request=' . $rid);
check('E22 ...and is offered the final step, with the tick and the typed number', str_contains($final['body'], 'data-merge-state="final"')
    && str_contains($final['body'], 'name="moveSignIns"') && str_contains($final['body'], 'name="typedDuplicate"'));
$wrong = $http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($final['body']), 'requestID' => (string) $rid, 'action' => 'agree', 'typedDuplicate' => (string) $eKept]);
check('E23 the wrong number typed merges nothing', n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$eDup}") === 1);
$final = $http('GET', '/account/merge?request=' . $rid);
$done  = $http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($final['body']), 'requestID' => (string) $rid, 'action' => 'agree', 'typedDuplicate' => (string) $eDup]);
check('E24 the right number typed MERGES', n($db, "SELECT `status` = 'completed' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$rid}") === 1
    && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$eDup}") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblPrayerRequests` WHERE `submitterID` = {$eKept}") === 1);
$page2 = $http('GET', '/account/merge?request=' . $rid);
$twice = $http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($page2['body']), 'requestID' => (string) $rid, 'action' => 'agree', 'typedDuplicate' => (string) $eDup]);
check('E25 a second press is told it is already done, with no error', ($twice['status'] === 302 || $twice['status'] === 303)
    && str_contains($http('GET', '/account/merge?request=' . $rid)['body'], 'data-merge-state="closed"'));
// ⚠️ Checked in the database, NOT by trying to sign in: a failed sign-in counts
//    towards the five-per-15-minutes limit on this address, so the fifth run of
//    this test on one machine locked every later sign-in and turned unrelated
//    checks red (found while planting faults).
check('E26 the duplicate has no password left and is switched off, so it cannot sign in',
    n($db, "SELECT COUNT(*) AS n FROM `tblLocalAccounts` WHERE `userID` = {$eDup}") === 0
    && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$eDup}") === 0);
$log = q($db, "SELECT `activityDescription` AS d FROM `tblActivityLogs` WHERE `activityType` LIKE 'AccountMerge%' OR `activityType` = 'AccountsMerged'");
$named = array_filter($log, static fn (array $r): bool => preg_match('/[A-Za-z]@|Name ' . $tag . '/', (string) $r['d']) === 1);
check('E27 every activity record of this feature holds no name and no e-mail address', $log !== [] && $named === [], json_encode(array_values($named)));

// Cancel and expiry, over HTTP.
$ck = $user('CancelKept'); $member($ck, $siteOne); $loginCk = $login($ck, 'CancelKept');
$cd = $user('CancelDup');  $member($cd, $siteOne);
$signIn($loginDc);
$page = $http('GET', $mergeUrl($ck, $cd));
$http('POST', '/admin/users/merge/start', ['csrf_token' => $tokenOn($page['body']), 'keep' => (string) $ck, 'duplicate' => (string) $cd]);
$crid = (int) q($db, "SELECT MAX(`requestID`) AS r FROM `tblAccountMergeRequest`")[0]['r'];
$db->query("UPDATE `tblAccountMergeRequest` SET `expiresAtUtc` = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE `requestID` = {$crid}");
$signIn($loginCk);
check('E28 an expired request is shown as no longer open', str_contains($http('GET', '/account/merge?request=' . $crid)['body'], 'data-merge-state="closed"'));
$db->query("UPDATE `tblAccountMergeRequest` SET `expiresAtUtc` = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE `requestID` = {$crid}");
$page = $http('GET', '/account/merge?request=' . $crid);
$http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($page['body']), 'requestID' => (string) $crid, 'action' => 'cancel']);
check('E29 the person can cancel it', n($db, "SELECT `status` = 'cancelled' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$crid}") === 1);

echo "\n" . $GLOBALS['am_pass'] . ' passed, ' . $GLOBALS['am_fail'] . " failed\n";
exit($GLOBALS['am_fail'] === 0 ? 0 : 1);
