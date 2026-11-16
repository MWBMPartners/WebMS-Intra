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
    'AccountGuard', 'WebPush', 'NotifyPrefs', 'Auth', 'MergeRefused', 'AccountMerge'];
// 🖼️ The photo checks (section I) need a portal folder of their own: the
//    eraser's photo rule deletes only an image inside PORTAL_ROOT/_uploads.
$photoRoot = sys_get_temp_dir() . $sep . 'account-merge-photos-' . bin2hex(random_bytes(5));
mkdir($photoRoot . $sep . '_uploads' . $sep . 'avatars', 0700, true);
if (defined('PORTAL_ROOT') === false) {
    define('PORTAL_ROOT', $photoRoot);
}
foreach (AM_CLASSES as $class) {
    require_once $coreDir . $class . '.php';
}

use Portal\Core\AccountGuard;
use Portal\Core\AccountMerge as M;
use Portal\Core\GdprEraser;
use Portal\Core\Roles;

// 🔑 A session in this process, for section F's checks of who set a password.
//    Started HERE, before any output, sending no headers, saved in the photo
//    scratch folder; empty (nobody signed in) everywhere it is not set on purpose.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($photoRoot);
session_start();
$_SESSION = [];

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

/**
 * D-200: nobody can agree until 7 days after the notice. A test cannot wait a
 * week, so a request whose wait is not itself under test has its not-before
 * moment moved into the past here, in the database, and nowhere else. The wait
 * itself is tested (F40, E35) on requests this is NOT used on.
 */
function am_open(mysqli $db, int $requestId): int
{
    if ($requestId > 0) {
        $db->query("UPDATE `tblAccountMergeRequest` SET `notBeforeUtc` = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE `requestID` = {$requestId}");
    }

    return $requestId;
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
// 📜 D-199 APPLIED CONSISTENTLY (round 5, finding 7). Every column recording who
//    made, changed, approved, decided, recorded or was SENT something stays as
//    written; only live state of the person moves. Pinned by NAME SHAPE and by
//    an explicit list, so moving one "who did it" column back is red: a check
//    derived from the list itself would agree with any list.
$movedActorShaped = array_values(array_filter(M::BY_TREATMENT[M::MOVE],
    static fn (string $n): bool => preg_match('/By(?:User)?ID$/', explode('.', $n)[1]) === 1));
$ownership = ['tblAssetLoans.requestedByID', 'tblAttendanceSessions.createdByID', 'tblPhoto.uploadedByUserID',
    'tblRecordingNote.createdByID', 'tblReportDefinitions.createdByID', 'tblResourceBooking.bookedByID',
    'tblRotaSwapRequest.requestedByID', 'tblTasks.createdByID'];
sort($ownership);
sort($movedActorShaped);
$namedByTheCheck = ['tblWorkflowActions.actedByID', 'tblExpenseClaimApprovals.userID', 'tblExternalEventApprovals.decidedByID',
    'tblAssetValueHistory.recordedByID', 'tblSmsMessage.recipientUserID'];
check('A10 D-199 applied consistently: no "who did it" column moves except the eight that mean ownership of a live record, '
    . 'and the five the check named stay as written',
    $movedActorShaped === $ownership && array_diff($namedByTheCheck, M::BY_TREATMENT[M::KEEP]) === [],
    json_encode(array_values(array_diff($movedActorShaped, $ownership))) . ' ' . json_encode(array_values(array_diff($namedByTheCheck, M::BY_TREATMENT[M::KEEP]))));
// 🗂️ The ten indexes the merge's locking reads need (round 5; measured: without
//    the one on RSVPs, an unrelated person's RSVP waited for the whole merge).
$wantIdx = ['tblEventRSVPs.idx_rsvp_user', 'tblEvents.idx_event_moderated_by', 'tblEvents.idx_event_status_changed_by',
    'tblEvents.idx_event_submitted_by', 'tblKidCheckins.idx_kc_checked_in_by', 'tblKidCheckins.idx_kc_checked_out_by',
    'tblExpenseClaimPayments.idx_payments_paid_by', 'tblWorkflowSteps.idx_wfstep_assignee',
    'tblExternalAudienceMembers.idx_extaud_kind_ref', 'tblErasureRequest.idx_er_user'];
$haveIdx = array_map(static fn (array $r): string => $r['t'] . '.' . $r['i'], q($db, "SELECT DISTINCT `TABLE_NAME` AS t, `INDEX_NAME` AS i "
    . "FROM `information_schema`.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE()"));
check('A11 the ten indexes the merge\'s locking reads need are in the install script', array_diff($wantIdx, $haveIdx) === [],
    implode(', ', array_diff($wantIdx, $haveIdx)));

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
    M::R_NEEDS_GLOBAL_SINGLE, M::R_IS_YOU, M::R_ADMINISTRATOR, M::R_CLOSED, M::R_REFUSE_IF_ANY, M::R_CLASH, M::R_CHOICES_DIFFER, M::R_UNKNOWN_CLASH,
    M::R_SIGN_IN_CHANGED, M::R_CHANGED_DURING];
// The person never reads the administrator's sentence about permissions.
check('B17b every refusal has PERSON words that never mention a permission',
    array_filter($codes, static fn (string $c): bool => str_contains(M::personRefusalWords($c), 'permission') === true) === []);
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
// (On a column that still MOVES and has no foreign key: `paidByID` stays since
// round 5, and a unique key on a column a merge never writes cannot clash.)
// (Paired with the row's own number, so the made-up rows, several of which name
// the same third account, cannot stop the key being created; the rule reads
// "inside ANY unique key".)
$db->query('ALTER TABLE `tblEventRegistrations` ADD UNIQUE KEY `uq_am_selftest` (`registeredForUserID`, `registrationID`)');
$unknown = M::preview($db, $pk, $pd);
$db->query('ALTER TABLE `tblEventRegistrations` DROP INDEX `uq_am_selftest`');
check('D6 a unique key the list does not know about refuses the merge (finding 4)',
    $unknown['refusal'] === M::R_UNKNOWN_CLASH && in_array('tblEventRegistrations.registeredForUserID', $unknown['unknown'], true), json_encode($unknown['unknown']));
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
    am_open($db, $started['requestId']);
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
$db->query("UPDATE `tblAuditTrail` SET `oldValue` = '{\"emailAddress\":\"fhist-{$tag}@selftest.invalid\"}', `newValue` = 'x' "
    . "WHERE `tableName` = 'tblUsers' AND `recordID` = {$fd}");
$versionBefore = n($db, "SELECT `signInVersion` AS n FROM `tblUsers` WHERE `userID` = {$fd}");
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
check('F5b every session of the duplicate ends: its sign-in version changed, through its one writer (#598)',
    (int) $closedRow['signInVersion'] !== $versionBefore, $closedRow['signInVersion'] . ' vs ' . $versionBefore);
// D-199: the merge NEVER touches the change history, values included; the
// erasure of the kept account empties them later (section I proves that).
check('F5c the change history about the duplicate\'s own account is left EXACTLY as written (D-199)',
    n($db, "SELECT COUNT(*) AS n FROM `tblAuditTrail` WHERE `tableName` = 'tblUsers' AND `recordID` = {$fd} AND `oldValue` LIKE '%fhist-{$tag}@%'") >= 1);
check('F5d ...and so is every history row: "who did it" still names the duplicate (D-199, D-116)',
    n($db, "SELECT COUNT(*) AS n FROM `tblAuditTrail` WHERE `userID` = {$fd}") >= 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = {$fd}") >= 1);
$record = q($db, "SELECT `activityDescription` AS d, `userID` AS u FROM `tblActivityLogs` WHERE `activityType` = 'AccountsMerged' ORDER BY `logID` DESC LIMIT 1");
$movedRecord = (string) (q($db, "SELECT `activityDescription` AS d FROM `tblActivityLogs` WHERE `activityType` = 'AccountsMergedRecordsMoved' "
    . "AND `userID` = {$fk} ORDER BY `logID` DESC LIMIT 1")[0]['d'] ?? '');
check('F6b a second NEW entry names what MOVED, as fixed words and counts, never a kept history column (round 6: a move is logged, not silent)',
    str_starts_with($movedRecord, 'Moved from account ' . $fd . ' to account ' . $fk . ' by merge request ')
    && str_contains($movedRecord, M::describe('tblPhoto.uploadedByUserID') . ' (1)')
    && str_contains($movedRecord, M::describe('tblAssetLoans.requestedByID') . ' (1)')
    && str_contains($movedRecord, M::describe('tblActivityLogs.userID')) === false, $movedRecord);
check('F6 a NEW entry says the earlier actions are now the kept account: two numbers and fixed words, under the kept account (D-199)',
    preg_match('/^The person on the earlier actions of account ' . $fd . ' is now account ' . $fk . ': the two accounts were merged \(request \d+, ways of signing in brought across\)$/', (string) ($record[0]['d'] ?? '')) === 1
    && (int) ($record[0]['u'] ?? 0) === $fk,
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
$req = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $pk2, $pd2)['requestId']);
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

// ⚖️ EVERY REFUSAL IS ASKED AGAIN AT COMPLETION. Deleting that re-check left the
//    previous version of this file green (the adversarial check's P15): each
//    of these happens BETWEEN the two agreements and must stop the merge.
foreach ([
    'the duplicate is made an organisation administrator' => ["UPDATE `tblUserSites` SET `isSiteAdmin` = 1 WHERE `userID` = %D%", M::R_ADMINISTRATOR],
    'the duplicate is switched off'                       => ["UPDATE `tblUsers` SET `isActive` = 0 WHERE `userID` = %D%", M::R_CLOSED],
    'an erasure request is filed for the duplicate'      => ["INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteA}, %D%, 'e@selftest.invalid', '2027-01-01', 'pending_review')", M::R_REFUSE_IF_ANY],
    'somebody else changes the duplicate\'s way in'      => ["UPDATE `tblUsers` SET `signInChangedByOtherAtUtc` = UTC_TIMESTAMP() WHERE `userID` = %D%", M::R_SIGN_IN_CHANGED],
    'somebody else changes the kept account\'s way in'   => ["UPDATE `tblUsers` SET `signInChangedByOtherAtUtc` = UTC_TIMESTAMP() WHERE `userID` = %K%", M::R_SIGN_IN_CHANGED],
] as $what => [$sql, $want]) {
    $lk = $user('LateKept' . md5($what)); $member($lk, $siteA);
    $ld = $user('LateDup' . md5($what));  $member($ld, $siteA);
    $lreq   = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $lk, $ld)['requestId']);
    $ltoken = M::agree($db, $lreq, $ld, true, '', 0, false)['token'];
    $db->query(str_replace(['%D%', '%K%'], [(string) $ld, (string) $lk], $sql));
    $late = M::agree($db, $lreq, $lk, true, $ltoken, $ld, false)['outcome'];
    check("F33 at completion: {$what} → refused, nothing merged", $late === $want
        && n($db, "SELECT `fullName` = 'Merged account' AS n FROM `tblUsers` WHERE `userID` = {$ld}") === 0, $late);
}
// The first agreement asks about the ACTING account's own way in.
$wk = $user('WayKept'); $member($wk, $siteA);
$wd = $user('WayDup');  $member($wd, $siteA);
$wreq = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $wk, $wd)['requestId']);
$db->query("UPDATE `tblUsers` SET `signInChangedByOtherAtUtc` = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE `userID` = {$wd}");
check('F34 an account whose way in somebody else changed 30 days BEFORE the request cannot agree', M::agree($db, $wreq, $wd, true, '', 0, false)['outcome'] === M::R_SIGN_IN_CHANGED);
$db->query("UPDATE `tblUsers` SET `signInChangedByOtherAtUtc` = UTC_TIMESTAMP() - INTERVAL 91 DAY WHERE `userID` = {$wd}");
check('F35 ...but one changed more than ' . M::SIGN_IN_CHANGE_DAYS . ' days before the request can', M::agree($db, $wreq, $wd, true, '', 0, false)['outcome'] === 'agreed_first');
// The mark itself, written by the ONE password writer.
$markOf = static fn (int $id): bool => n($db, "SELECT `signInChangedByOtherAtUtc` IS NOT NULL AS n FROM `tblUsers` WHERE `userID` = {$id}") === 1;
$mk = $user('MarkTarget');
\Portal\Core\Auth::setPassword($db, $mk, 'Mk' . bin2hex(random_bytes(6)) . '!9x', 'am-mark-' . $tag);
check('F36 a password set with nobody signed in (reset link, installer) is NOT marked', $markOf($mk) === false);
// (The session was started at the top, before any output, empty until now.)
$_SESSION['user_id'] = $mk;
\Portal\Core\Auth::setPassword($db, $mk, 'Mk' . bin2hex(random_bytes(6)) . '!9y');
check('F37 a password the account sets for ITSELF is not marked', $markOf($mk) === false);
$_SESSION['user_id'] = $adminA;
\Portal\Core\Auth::setPassword($db, $mk, 'Mk' . bin2hex(random_bytes(6)) . '!9z');
check('F38 a password set by somebody ELSE is marked (D-190: an administrator cannot then agree for them)', $markOf($mk) === true);
$_SESSION['user_id'] = $mk;
\Portal\Core\Auth::setPassword($db, $mk, 'Mk' . bin2hex(random_bytes(6)) . '!9w');
check('F39 ...and the account changing it again afterwards does NOT clear the mark (no laundering)', $markOf($mk) === true);
$_SESSION = [];

// ⏳ D-200: nobody can agree until 7 days after the notice; cancelling is open all along.
$tk = $user('SoonKept'); $member($tk, $siteA);
$td = $user('SoonDup');  $member($td, $siteA);
$treq = M::start($db, $adminA, false, false, $siteA, false, $tk, $td)['requestId'];
check('F40 the first agreement within 7 days of the notice is refused, and nothing is recorded',
    M::agree($db, $treq, $td, true, '', 0, false)['outcome'] === M::R_TOO_SOON
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `requestID` = {$treq} AND `confirmedAtUtc` IS NOT NULL") === 0);
check('F41 the wait is exactly ' . M::WAIT_DAYS . ' days from the notice, and the request outlasts it',
    n($db, "SELECT TIMESTAMPDIFF(DAY, `requestedAtUtc`, `notBeforeUtc`) AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$treq}") === M::WAIT_DAYS
    && n($db, "SELECT `expiresAtUtc` > `notBeforeUtc` AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$treq}") === 1);
check('F42 ...but either account can cancel it straight away', M::cancel($db, $treq, $td, false, 0) === 'cancelled');
check('F43 the person\'s words say how long the wait and the bar last, and that a new request is needed after a bar',
    str_contains(M::personRefusalWords(M::R_TOO_SOON), '7 days') && str_contains(M::personRefusalWords(M::R_SIGN_IN_CHANGED), '90 days')
    && str_contains(M::personRefusalWords(M::R_SIGN_IN_CHANGED), 'new request'));

// ⏳ THE "OR AT ANY TIME SINCE" HALF OF THE 90-DAY BAR, which is D-200's main
//    defence: an administrator changing an account's address DURING the wait.
//    F33 could not tell it from "before the request", because am_open() starts
//    the request in the same second the mark is set (round 4's check planted
//    "only changes before the request count" and F33 stayed green). Here the
//    request is TEN days old and the change comes after it.
/** Make request $rid $days days old, with its wait already over. */
$aged = static function (int $rid, int $days) use ($db): void {
    $db->query("UPDATE `tblAccountMergeRequest` SET `requestedAtUtc` = UTC_TIMESTAMP() - INTERVAL {$days} DAY, "
        . '`notBeforeUtc` = UTC_TIMESTAMP() - INTERVAL ' . ($days - M::WAIT_DAYS) . " DAY WHERE `requestID` = {$rid}");
};
$sk = $user('SinceKept'); $member($sk, $siteA);
$sd = $user('SinceDup');  $member($sd, $siteA);
$sreq = M::start($db, $adminA, false, false, $siteA, false, $sk, $sd)['requestId'];
$aged($sreq, 10);
$db->query("UPDATE `tblUsers` SET `signInChangedByOtherAtUtc` = UTC_TIMESTAMP() - INTERVAL 5 DAY WHERE `userID` = {$sd}");
check('F33b an account whose way in somebody else changed DURING the wait (request 10 days old, change 5 days ago) cannot agree',
    M::agree($db, $sreq, $sd, true, '', 0, false)['outcome'] === M::R_SIGN_IN_CHANGED);
$s2k = $user('SinceKept2'); $member($s2k, $siteA);
$s2d = $user('SinceDup2');  $member($s2d, $siteA);
$s2req = M::start($db, $adminA, false, false, $siteA, false, $s2k, $s2d)['requestId'];
$aged($s2req, 10);
$s2token = M::agree($db, $s2req, $s2d, true, '', 0, false)['token'];
$db->query("UPDATE `tblUsers` SET `signInChangedByOtherAtUtc` = UTC_TIMESTAMP() WHERE `userID` = {$s2d}");
$s2out = M::agree($db, $s2req, $s2k, true, $s2token, $s2d, false)['outcome'];
check('F33c ...and a change to the OTHER account after it agreed, ten days into the request, stops the completion; nothing merged',
    $s2out === M::R_SIGN_IN_CHANGED && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$s2d}") === 1, $s2out);

// ⏳ THE WAIT IS ASKED AGAIN AT COMPLETION (the check's P01). The first
//    agreement already needs it over, so only moving the date between the two
//    agreements reaches the second ask.
$ck = $user('WaitKept'); $member($ck, $siteA);
$cd = $user('WaitDup');  $member($cd, $siteA);
$creq   = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $ck, $cd)['requestId']);
$ctoken = M::agree($db, $creq, $cd, true, '', 0, false)['token'];
$db->query("UPDATE `tblAccountMergeRequest` SET `notBeforeUtc` = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE `requestID` = {$creq}");
$cout = M::agree($db, $creq, $ck, true, $ctoken, $cd, false)['outcome'];
check('F44 the wait is asked again at completion: refused, nothing merged', $cout === M::R_TOO_SOON
    && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$cd}") === 1, $cout);

// =============================================================================
echo "G. Two processes complete the same request at once\n";
// =============================================================================
$gk = $user('RaceKept'); $member($gk, $siteA);
$gd = $user('RaceDup');  $member($gd, $siteA);
$greq   = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $gk, $gd)['requestId']);
$gtoken = M::agree($db, $greq, $gd, true, '', 0, false)['token'];
$scratch = sys_get_temp_dir() . $sep . 'account-merge-race-' . bin2hex(random_bytes(5));
mkdir($scratch, 0700);
$worker = $scratch . $sep . 'worker.php';
file_put_contents($worker, '<?php
declare(strict_types=1);
[, $core, $host, $user, $pass, $name, $port, $req, $k, $d, $token, $go, $root] = $argv;
define("PORTAL_CORE", $core);
define("PORTAL_ROOT", $root);
foreach (' . var_export(AM_CLASSES, true) . ' as $c) { require_once $core . "/" . $c . ".php"; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli($host, $user, $pass, $name, (int) $port);
\\Portal\\Core\\Site::init($mysqli, ["site" => ["timezoneKeptAt" => ""]], 1);
for ($i = 0; $i < 400 && is_file($go) === false; $i++) { usleep(10000); }
echo \\Portal\\Core\\AccountMerge::agree($mysqli, (int) $req, (int) $k, true, $token, (int) $d, false)["outcome"];
');
$go = $scratch . $sep . 'go';
/** Start one worker that completes request $r as $k (duplicate $d) once $go appears. @return array{0: resource, 1: array} */
$spawn = static function (int $r, int $k, int $d, string $token) use ($worker, $coreDir, $sep, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, $go, $photoRoot): array {
    $proc = proc_open([PHP_BINARY, $worker, rtrim($coreDir, $sep), $dbHost, $dbUser, $dbPass, $dbName, (string) $dbPort,
        (string) $r, (string) $k, (string) $d, $token, $go, $photoRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    return [$proc, $pipes];
};
$finish = static function (array $w): string {
    $out = trim((string) stream_get_contents($w[1][1])) . trim((string) stream_get_contents($w[1][2]));
    proc_close($w[0]);

    return $out;
};
$workers = [$spawn($greq, $gk, $gd, $gtoken), $spawn($greq, $gk, $gd, $gtoken)];
usleep(300000);
touch($go);
$outs = [$finish($workers[0]), $finish($workers[1])];
@unlink($go);
sort($outs);
check('G1 two processes completing at once: exactly one merges, the other is told it is done', $outs === ['completed', 'done_already'], json_encode($outs));
check('G2 ...and the duplicate\'s rows moved once', n($db, "SELECT COUNT(*) AS n FROM `tblUserSites` WHERE `userID` = {$gk}") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblUserSites` WHERE `userID` = {$gd}") === 0);

// =============================================================================
echo "H. An erasure request filed for the duplicate WHILE the merge runs (finding 3)\n";
// =============================================================================
// ⚠️ The measured fault: the merge read "nothing stands in the way" from a
//    snapshot, a request was filed for the duplicate meanwhile (no foreign key,
//    so the account lock does not hold it back), and the merge's UPDATE moved
//    it to the kept account — running it would then erase the whole merged
//    person. A trigger makes the merge pause at an early MOVE (a milestone of
//    the duplicate's: moved columns come first, before every table that can
//    clash), and this process writes during the pause, on its own connection,
//    committed.
//    Round 5: the pause used to be on an announcement's author, which D-199 now
//    KEEPS, so the trigger would never have fired and every race below would
//    have passed for the wrong reason (the worker finishing before the write).
//    So each race now ASSERTS the worker is inside the pause before it writes:
//    a sleep is a hope, the server's own process list is a premise (#735).
/** Make the next merge of $dup pause for 4 seconds at an early move. */
$pauseOn = static function (int $dup) use ($db): void {
    $db->query("INSERT INTO `tblUserMilestone` (`userID`, `kind`, `monthDay`) VALUES ({$dup}, 'other', '01-01')");
    $db->query('DROP TRIGGER IF EXISTS `am_selftest_pause`');
    $db->query("CREATE TRIGGER `am_selftest_pause` BEFORE UPDATE ON `tblUserMilestone` FOR EACH ROW BEGIN IF OLD.`userID` = {$dup} AND NEW.`userID` <> {$dup} THEN DO SLEEP(4); END IF; END");
};
/** Wait until a merge is asleep inside the pause; false if none is within 3 seconds. */
$awaitPause = static function () use ($db): bool {
    for ($i = 0; $i < 60; $i++) {
        // Measured: while a trigger sleeps, the process list shows the TRIGGER's
        // statement (`DO SLEEP(4)`) in INFO, not the UPDATE that fired it.
        if (n($db, "SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE `STATE` = 'User sleep' AND `INFO` = 'DO SLEEP(4)'") > 0) {
            return true;
        }
        usleep(50000);
    }

    return false;
};
$hk = $user('MidKept'); $member($hk, $siteA);
$hd = $user('MidDup');  $member($hd, $siteA);
$hreq   = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $hk, $hd)['requestId']);
$htoken = M::agree($db, $hreq, $hd, true, '', 0, false)['token'];
$pauseOn($hd);
$mid = $spawn($hreq, $hk, $hd, $htoken);
touch($go);
check('H0 premise: the worker is asleep INSIDE the merge before the erasure request is filed', $awaitPause() === true);
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteA}, {$hd}, 'mid@selftest.invalid', '2027-01-01', 'pending_review')");
$midOut = $finish($mid);
$db->query('DROP TRIGGER IF EXISTS `am_selftest_pause`');
@unlink($go);
check('H1 the merge REFUSES when an erasure request appears for the duplicate part-way through', $midOut === M::R_REFUSE_IF_ANY, $midOut);
check('H2 ...the request still names the DUPLICATE, never the kept account',
    n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `subjectEmail` = 'mid@selftest.invalid' AND `userID` = {$hd}") === 1);
check('H3 ...and nothing was merged', n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$hd}") === 1
    && n($db, "SELECT `status` = 'waiting' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$hreq}") === 1);

// ⚖️ FINDING D of the third check, measured there: both accounts replied
//    "going" to one event (so the preview saw "the same"); while the merge was
//    paused a second connection changed the DUPLICATE's reply to "not going"
//    and committed (RSVPs have no foreign key, so the account lock does not hold
//    that back); the merge then deleted the duplicate's reply as "the same" and
//    completed. The "they differ" test is now INSIDE the delete, on locked rows.
$rk2 = $user('RaceRsvpKept'); $member($rk2, $siteA);
$rd2 = $user('RaceRsvpDup');  $member($rd2, $siteA);
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query("INSERT INTO `tblEventRSVPs` (`eventID`, `userID`, `response`) VALUES (8301, {$rk2}, 'going'), (8301, {$rd2}, 'going')");
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$rreq   = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $rk2, $rd2)['requestId']);
$rtoken = M::agree($db, $rreq, $rd2, true, '', 0, false)['token'];
$pauseOn($rd2);
$rmid = $spawn($rreq, $rk2, $rd2, $rtoken);
touch($go);
check('H0b premise: the worker is asleep INSIDE the merge before the other account\'s reply changes', $awaitPause() === true);
$db->query("UPDATE `tblEventRSVPs` SET `response` = 'not_going' WHERE `userID` = {$rd2} AND `eventID` = 8301");
$rOut = $finish($rmid);
$db->query('DROP TRIGGER IF EXISTS `am_selftest_pause`');
@unlink($go);
check('H4 a reply changed part-way through so the two now DIFFER refuses the merge', $rOut === M::R_CHOICES_DIFFER, $rOut);
check('H5 ...and BOTH replies are still there, as each account left them',
    n($db, "SELECT COUNT(*) AS n FROM `tblEventRSVPs` WHERE `eventID` = 8301 AND ((`userID` = {$rk2} AND `response` = 'going') OR (`userID` = {$rd2} AND `response` = 'not_going'))") === 2);
// 💷 A money record is never deleted on a clash, and a clash is a refusal,
//    never an error. The kept account's pledge appears WHILE the merge is
//    paused, so the preview saw no clash and only the guard inside the merge can
//    stop the duplicate's pledge being thrown away.
//    Why an UPDATE and not an INSERT: a new pledge naming the kept account is
//    checked against that account's row, which the merge holds locked, so it
//    simply WAITS until the merge has finished (measured: tried as an INSERT,
//    it deadlocked against the merge and was the one rolled back). Moving the
//    kept account's existing pledge onto another campaign touches no account
//    row, so it really does land in the middle of the merge.
$mk2 = $user('MoneyKept'); $member($mk2, $siteA);
$md2 = $user('MoneyDup');  $member($md2, $siteA);
$campaign = $insert('tblPledgeCampaigns', ['siteID' => $siteA, 'createdByID' => $adminA], $adminA);
$campaignId = (int) $db->insert_id;
$insert('tblPledgeCampaigns', ['siteID' => $siteA, 'createdByID' => $adminA], $adminA);
$otherCampaignId = (int) $db->insert_id;
$db->query("INSERT INTO `tblPledges` (`siteID`, `campaignID`, `userID`, `amountPence`, `currency`) VALUES ({$siteA}, {$campaignId}, {$md2}, 700, 'GBP'), ({$siteA}, {$otherCampaignId}, {$mk2}, 500, 'GBP')");
$mreq   = am_open($db, M::start($db, $adminA, false, false, $siteA, false, $mk2, $md2)['requestId']);
$mtoken = M::agree($db, $mreq, $md2, true, '', 0, false)['token'];
$pauseOn($md2);
$mmid = $spawn($mreq, $mk2, $md2, $mtoken);
touch($go);
check('H0c premise: the worker is asleep INSIDE the merge before the other account\'s pledge moves', $awaitPause() === true);
$db->query("UPDATE `tblPledges` SET `campaignID` = {$campaignId} WHERE `userID` = {$mk2} AND `campaignID` = {$otherCampaignId}");
$moneyOut = $finish($mmid);
$db->query('DROP TRIGGER IF EXISTS `am_selftest_pause`');
@unlink($go);
check('H6 a pledge on BOTH accounts for one campaign, appearing part-way through, refuses (a refusal, not an error) and deletes neither',
    $campaign !== null && $moneyOut === M::R_CLASH
    && n($db, "SELECT COUNT(*) AS n FROM `tblPledges` WHERE `campaignID` = {$campaignId} AND `userID` IN ({$mk2}, {$md2})") === 2, $moneyOut);

// =============================================================================
echo "I. Photos, and a real erasure after a merge (findings 4 and 5)\n";
// =============================================================================
$file = static function (string $name) use ($photoRoot, $sep): string {
    file_put_contents($photoRoot . $sep . '_uploads' . $sep . 'avatars' . $sep . $name, "\x89PNG made up");

    return 'avatars/' . $name;
};
// The kept account has no photo: the duplicate's MOVES to it.
$ik = $user('PhotoKept'); $member($ik, $siteA);
$id = $user('PhotoDup');  $member($id, $siteA);
$p1 = $file('dup-' . $tag . '.png');
$db->query("UPDATE `tblUsers` SET `avatarPath` = '{$p1}' WHERE `userID` = {$id}");
check('I1 the merge completes', $merge($ik, $id, false) === 'completed');
check('I2 the kept account had no photo, so the duplicate\'s photo is now the kept account\'s, file and all',
    (q($db, "SELECT `avatarPath` FROM `tblUsers` WHERE `userID` = {$ik}")[0]['avatarPath'] ?? '') === $p1
    && is_file($photoRoot . $sep . '_uploads' . $sep . $p1));
// The kept account has its own: the duplicate's FILE is deleted.
$jk = $user('PhotoKept2'); $member($jk, $siteA);
$jd = $user('PhotoDup2');  $member($jd, $siteA);
$p2 = $file('kept-' . $tag . '.png');
$p3 = $file('dup2-' . $tag . '.png');
$db->query("UPDATE `tblUsers` SET `avatarPath` = '{$p2}' WHERE `userID` = {$jk}");
$db->query("UPDATE `tblUsers` SET `avatarPath` = '{$p3}' WHERE `userID` = {$jd}");
check('I3 the merge completes', $merge($jk, $jd, false) === 'completed');
check('I4 the kept account keeps its own photo, and the duplicate\'s FILE is deleted (no orphan nothing could find)',
    is_file($photoRoot . $sep . '_uploads' . $sep . $p2) && is_file($photoRoot . $sep . '_uploads' . $sep . $p3) === false);

// A REAL erasure of the kept account after a merge reaches what was the duplicate's.
spl_autoload_register(static function (string $class) use ($coreDir): void {
    if (str_starts_with($class, 'Portal\\Core\\') === true && is_file($coreDir . substr($class, 12) . '.php') === true) {
        require_once $coreDir . substr($class, 12) . '.php';
    }
});
$ek = $user('EraseKept'); $member($ek, $siteA);
$ed = $user('EraseDup');  $member($ed, $siteA);
$oldAddress = 'old-' . $tag . '@selftest.invalid';
$oldName    = 'Erase Dup Name ' . $tag;
$oldPhone   = '07700 9' . substr((string) hexdec($tag), 0, 5);
// The duplicate's OWN details before the merge, and mail sent and waiting for it.
$db->query("UPDATE `tblUsers` SET `emailAddress` = '{$oldAddress}', `fullName` = '{$oldName}', `phoneNumber` = '{$oldPhone}' WHERE `userID` = {$ed}");
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->query("INSERT INTO `tblNewsletterRecipient` (`newsletterID`, `userID`, `emailAddress`, `unsubToken`) VALUES (9101, {$ed}, '{$oldAddress}', '" . bin2hex(random_bytes(20)) . "')");
$insert('tblEmailLog', ['toRecipients' => $oldAddress, 'errorDetail' => '', 'errorCode' => ''], $third);
$insert('tblMailQueue', ['toRecipients' => $oldAddress, 'stage' => 'waiting', 'holdReason' => '', 'lastError' => ''], $third);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$db->query("INSERT INTO `tblActivityLogs` (`siteID`, `userID`, `activityType`, `activityDescription`) VALUES ({$siteA}, {$ed}, 'Selftest', 'made up')");
$db->query("INSERT INTO `tblAuditTrail` (`userID`, `action`, `tableName`, `recordID`, `oldValue`) VALUES ({$ed}, 'update', 'tblUsers', {$ed}, '{\"emailAddress\":\"{$oldAddress}\",\"fullName\":\"{$oldName}\"}')");
// 📧 Round 5, findings 1 and 2: the address EACH account held before somebody
//    else changed it (D-200), and mail sent to each — the merge's start notice
//    goes there. Neither was reachable by any erasure before.
$earlyDup  = 'early-dup-' . $tag . '@selftest.invalid';
$earlyKept = 'early-kept-' . $tag . '@selftest.invalid';
$db->query("UPDATE `tblUsers` SET `earlierEmails` = JSON_ARRAY('{$earlyDup}') WHERE `userID` = {$ed}");
$db->query("UPDATE `tblUsers` SET `earlierEmails` = JSON_ARRAY('{$earlyKept}') WHERE `userID` = {$ek}");
$db->query('SET FOREIGN_KEY_CHECKS = 0');
foreach ([$earlyDup, $earlyKept] as $early) {
    $insert('tblEmailLog', ['toRecipients' => $early, 'errorDetail' => '', 'errorCode' => ''], $third);
    $insert('tblMailQueue', ['toRecipients' => $early, 'stage' => 'waiting', 'holdReason' => '', 'lastError' => ''], $third);
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');
// 🧾 Round 5, finding 3: a giving statement PDF named by the DUPLICATE's number
//    (it holds the donor's name, home address and gifts). The merge cannot
//    rename it; the erasure must find it by following the merge.
$stmtDir = $photoRoot . $sep . '_uploads' . $sep . 'giving' . $sep . 'statements' . $sep . $siteA;
@mkdir($stmtDir, 0700, true);
$dupStatement = $stmtDir . $sep . 'statement-' . $ed . '-2026-01-01_2026-12-31.pdf';
file_put_contents($dupStatement, '%PDF-1.4 made up');
// Round 6, finding 5: an erasure request the duplicate HANDLED as an
// administrator (somebody else's, long completed). An ordinary erasure takes
// the handler's number off it; a merged one left the duplicate's there.
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`, `processedByID`) "
    . "VALUES ({$siteA}, {$third}, 'handled-{$tag}@selftest.invalid', '2027-01-01', 'completed', {$ed})");
$heldBefore = array_sum(array_map(static fn (array $r): int => $r['table'] === 'tblActivityLogs' ? (int) $r['rows'] : 0, GdprEraser::inventory($ek)));
check('I5 the merge completes', $merge($ek, $ed, false) === 'completed');
check('I5c the merge empties the duplicate\'s earlier address from its account row, and keeps it on the merge record for an erasure to search by',
    n($db, "SELECT `earlierEmails` IS NULL AS n FROM `tblUsers` WHERE `userID` = {$ed}") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `userID` = {$ed} AND JSON_CONTAINS(`formerEarlierEmails`, JSON_QUOTE('{$earlyDup}'))") === 1);
$heldAfter = array_sum(array_map(static fn (array $r): int => $r['table'] === 'tblActivityLogs' ? (int) $r['rows'] : 0, GdprEraser::inventory($ek)));
// The EXACT sum, never "went up": the merge writes its own new entry under the
// kept account, so "went up" passed with inventory() ignoring the merge
// (planted, measured). The duplicate holds at least the made-up row above, so
// the two answers differ.
$heldBoth = n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` IN ({$ek}, {$ed})");
$heldDup  = n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = {$ed}");
check('I5b "what we hold about you" for the kept account counts the duplicate\'s history too, exactly (D-199)',
    $heldDup > 0 && $heldAfter === $heldBoth, "{$heldBefore} before; {$heldAfter} after; {$heldBoth} under both numbers, {$heldDup} under the duplicate's");
$erasure = null;
$erasureProblem = '';
try {
    $filed   = GdprEraser::fileRequest($siteA, $ek, true);
    $erasure = GdprEraser::execute((int) ($filed['requestId'] ?? $filed['requestID'] ?? 0), $ek, $adminA);
} catch (Throwable $e) {
    $erasureProblem = get_class($e) . ': ' . $e->getMessage();
}
check('I6 a real erasure of the kept account runs', $erasure !== null, $erasureProblem . json_encode($filed ?? null));
check('I7 ...and the duplicate\'s old e-mail address is nowhere it was: the newsletter send record and the change history',
    n($db, "SELECT COUNT(*) AS n FROM `tblNewsletterRecipient` WHERE `emailAddress` = '{$oldAddress}'") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblAuditTrail` WHERE `oldValue` LIKE '%{$oldAddress}%' OR `newValue` LIKE '%{$oldAddress}%'") === 0);
check('I8 ...and no activity record names the duplicate', n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = {$ed}") === 0);
// THE WHOLE DATABASE: every text column, searched for the duplicate's old
// address, name and phone number. Nothing of the duplicate may remain anywhere
// an erasure of the kept account would leave it (finding B of the third check:
// the send log and waiting mail found people by their CURRENT address only).
$textColumns = q($db, "SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() "
    . "AND `DATA_TYPE` IN ('char','varchar','tinytext','text','mediumtext','longtext','json') AND `EXTRA` NOT LIKE '%GENERATED%'");
$stillThere = [];
foreach ($textColumns as $tc) {
    foreach (['address' => $oldAddress, 'name' => $oldName, 'phone' => $oldPhone, 'earlier address' => $earlyDup,
        'kept account\'s earlier address' => $earlyKept] as $what => $needle) {
        $found = n($db, "SELECT COUNT(*) AS n FROM `{$tc['t']}` WHERE CAST(`{$tc['c']}` AS CHAR) LIKE '%" . $db->real_escape_string($needle) . "%'");
        if ($found > 0) {
            $stillThere[] = "{$tc['t']}.{$tc['c']} ({$what})";
        }
    }
}
check('I9 a search of EVERY text column (' . count($textColumns) . ') finds none of the duplicate\'s old address, name or phone number, '
    . 'nor the address either account held before somebody else changed it',
    $stillThere === [], implode(', ', $stillThere));
check('I10 the duplicate\'s giving statement PDF is deleted by the erasure of the account it was merged into', is_file($dupStatement) === false);
check('I10c ...and no erasure request still names the duplicate as the administrator who handled it (as for the person\'s own number)',
    n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `processedByID` = {$ed}") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `subjectEmail` = 'handled-{$tag}@selftest.invalid'") === 1);
check('I10b ...and that first run of the erasure had no failed step', ($erasure['failed'] ?? -1) === 0, json_encode($erasure));

// 🗂️ Round 5, finding 5: a care case in the merged person's history used to
//    fail the erasure's first run, because the duplicate's pass repeated the
//    care-visit step after tblCareCase had already been changed, and #709's
//    guard rightly refused it. One case on each side, and on both.
foreach (['the kept account only' => [true, false], 'the duplicate only' => [false, true], 'both' => [true, true]] as $which => [$onKept, $onDup]) {
    $ck2 = $user('CareKept' . md5($which)); $member($ck2, $siteA);
    $cd2 = $user('CareDup' . md5($which));  $member($cd2, $siteA);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_filter([$onKept ? $ck2 : 0, $onDup ? $cd2 : 0]) as $about) {
        $case = $insert('tblCareCase', ['siteID' => $siteA, 'personUserID' => $about, 'openedByID' => $third], $third);
        $caseId = (int) $db->insert_id;
        $insert('tblCareVisit', ['caseID' => $caseId, 'visitedByID' => $third, 'followUpAssignedToID' => null, 'notes' => 'care note ' . $tag], $third);
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $cm = $merge($ck2, $cd2, false);
    $run = null;
    try {
        $cf  = GdprEraser::fileRequest($siteA, $ck2, true);
        $run = GdprEraser::execute((int) $cf['requestID'], $ck2, $adminA);
    } catch (Throwable $e) {
        $run = ['problem' => $e->getMessage()];
    }
    check("I11 [care case on {$which}] the merge completes and the erasure's FIRST run finishes with no failed step",
        $cm === 'completed' && ($run['failed'] ?? -1) === 0, $cm . ' ' . json_encode($run));
}

// 🗒️ Round 6, finding 1: the erasure REVIEW follows the merge. Lines of free
//    text kept under the duplicate's number (D-199) must be offered to the
//    reviewer exactly as the same lines under the person's own number are, or
//    the request closes over words nobody saw (#724). The same rows, one set
//    for an ordinary person and one for a merged duplicate, must give the
//    same review lines.
$fillReview = static function (int $who) use ($db, $insert, $siteA, $tag): int {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $made = 0;
    foreach (\Portal\Core\ErasureReview::markedColumns() as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            if ($insert((string) $table, ['siteID' => $siteA, (string) $column => 'review words ' . $tag], $who) !== null) {
                $made++;
            }
        }
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    return $made;
};
$rp = $user('ReviewPlain'); $member($rp, $siteA);
$madePlain = $fillReview($rp);
$reqPlain  = (int) GdprEraser::fileRequest($siteA, $rp, true)['requestID'];
$plain     = \Portal\Core\ErasureReview::itemsFor($db, $reqPlain, $rp)['counts']['total'];
$rk = $user('ReviewKept'); $member($rk, $siteA);
$rd = $user('ReviewDup');  $member($rd, $siteA);
$madeDup = $fillReview($rd);
$rm      = $merge($rk, $rd, false);
$reqKept = (int) GdprEraser::fileRequest($siteA, $rk, true)['requestID'];
$merged  = \Portal\Core\ErasureReview::itemsFor($db, $reqKept, $rk)['counts']['total'];
check('I12 premise: the same review rows were written for an ordinary person and for a duplicate, and the merge completed',
    $madePlain > 0 && $madePlain === $madeDup && $rm === 'completed', "{$madePlain} / {$madeDup} / {$rm}");
check('I12b the erasure review offers the SAME number of lines for the merged person as for the ordinary one',
    $plain > 0 && $merged === $plain, "ordinary {$plain}, merged {$merged}");
check('I12c ...and the request cannot close until they are decided (both asks read the same count)',
    \Portal\Core\ErasureReview::whyNotReadyToClose($db, $reqKept, $rk) !== '');
// Round 7, finding 3: a line under the merged-in number can really be DECIDED.
// Planted, `oneItem()` asking about the person's own number only left every
// check green, because nothing had ever decided such a line.
$keptCols   = array_flip(M::BY_TREATMENT[M::KEEP]);
$reviewList = \Portal\Core\ErasureReview::itemsFor($db, $reqKept, $rk)['items'];
$dupOnly    = count(array_filter($reviewList, static function (array $it) use ($keptCols): bool {
    foreach ((array) $it['matchedBy'] as $col) {
        if (isset($keptCols[$it['table'] . '.' . $col]) === false) {
            return false;
        }
    }

    return $it['matchedBy'] !== [];
}));
$decideErr = [];
foreach ($reviewList as $it) {
    $why = \Portal\Core\ErasureReview::record($db, $reqKept, $rk, (string) $it['table'], (string) $it['column'], (int) $it['rowKey'],
        \Portal\Core\ErasureReview::DECISION_KEEP, $adminA);
    if ($why !== '') {
        $decideErr[] = $it['table'] . '.' . $it['column'] . ': ' . $why;
    }
}
check('I12d premise: some of those lines are reached ONLY through the merged-in number (kept history)', $dupOnly > 0, (string) $dupOnly);
check('I12e every line, those under the merged-in number included, can be DECIDED, and the request may then close',
    $decideErr === [] && \Portal\Core\ErasureReview::whyNotReadyToClose($db, $reqKept, $rk) === '', implode(' | ', array_slice($decideErr, 0, 3)));

// 🔗 Round 6, finding 7: a CHAIN of merges. A into B, then B into C. Filing for A
//    is refused naming C; C's erasure reaches what was left under A and B.
$ca = $user('ChainA'); $member($ca, $siteA);
$cb = $user('ChainB'); $member($cb, $siteA);
$cc = $user('ChainC'); $member($cc, $siteA);
$db->query("INSERT INTO `tblActivityLogs` (`siteID`, `userID`, `activityType`, `activityDescription`) VALUES "
    . "({$siteA}, {$ca}, 'Selftest', 'chain a'), ({$siteA}, {$cb}, 'Selftest', 'chain b')");
$chain1 = $merge($cb, $ca, false);
$chain2 = $merge($cc, $cb, false);
check('I13 a chain of merges completes: A into B, then B into C', $chain1 === 'completed' && $chain2 === 'completed', "{$chain1} {$chain2}");
check('I13b A\'s records now belong to C, two merges on, and B\'s too', M::survivorOf($db, $ca) === $cc && M::survivorOf($db, $cb) === $cc,
    json_encode([M::survivorOf($db, $ca), M::survivorOf($db, $cb)]));
$chainProblem = '';
try {
    GdprEraser::fileRequest($siteA, $ca, true);
} catch (RuntimeException $e) {
    $chainProblem = $e->getMessage();
}
check('I13c filing an erasure for A is refused, naming C, the end of the chain', str_contains($chainProblem, 'merged into account #' . $cc . ','), $chainProblem);
$followedChain = M::mergedInto($db, $cc);
sort($followedChain);
$expectChain = [$ca, $cb];
sort($expectChain);
check('I13d C follows both A and B', $followedChain === $expectChain, json_encode($followedChain));
$chainReq = (int) GdprEraser::fileRequest($siteA, $cc, true)['requestID'];
$chainRun = GdprEraser::execute($chainReq, $cc, $adminA);
check('I13e erasing C reaches the history left under A AND under B', ($chainRun['failed'] ?? -1) === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` IN ({$ca}, {$cb})") === 0, json_encode($chainRun));

// 📧 ROUND 7, FINDING 1: an earlier address ANOTHER account holds NOW is never
//    searched by an erasure (a merge notice still goes there: D-200, I14d
//    below). Measured by the check: A
//    gave up office@, B took it, and erasing A destroyed B's waiting mail and
//    B's send-log line. People here are NEVER merged: it reached everybody.
/** Change an account's address the way both pages do: the replaced one is remembered. */
$move = static function (int $who, string $to) use ($db): void {
    $st = $db->prepare('UPDATE `tblUsers` SET ' . M::rememberReplacedAddressSql() . '`emailAddress` = ? WHERE `userID` = ?');
    $st->bind_param('ssi', $to, $to, $who);
    $st->execute();
    $st->close();
};
$ta = $user('TakenA'); $member($ta, $siteA);
$tb = $user('TakenB'); $member($tb, $siteA);
$office = 'office-' . $tag . '@selftest.invalid';
$goneTo = 'gone-' . $tag . '@selftest.invalid';
$move($ta, $goneTo);
$move($ta, $office);
$move($ta, 'own-' . $tag . '@selftest.invalid');
$move($tb, $office);
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$insert('tblMailQueue', ['toRecipients' => $office, 'stage' => 'waiting', 'holdReason' => '', 'lastError' => ''], $third);
$insert('tblEmailLog', ['toRecipients' => $office, 'errorDetail' => '', 'errorCode' => ''], $third);
$insert('tblEmailLog', ['toRecipients' => $goneTo, 'errorDetail' => '', 'errorCode' => ''], $third);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$taReq = (int) GdprEraser::fileRequest($siteA, $ta, true)['requestID'];
$taRun = GdprEraser::execute($taReq, $ta, $adminA);
check('I14 an earlier address SOMEBODY ELSE holds now is not touched by an erasure: their waiting mail and send-log line stay',
    ($taRun['failed'] ?? -1) === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblMailQueue` WHERE `toRecipients` = '{$office}'") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` = '{$office}'") === 1, json_encode($taRun));
check('I14b ...while an earlier address NOBODY holds is erased', n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%{$goneTo}%'") === 0);
// Round 8: the request still closes ("none failed"), so the CLOSING line itself
// must say an address was not searched, or it reads as everything reached.
check('I14f ...and the closing line of the report says one address was NOT searched, never just "none failed"',
    n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$taReq} AND `action` = 'completed' "
        . "AND `details` LIKE '%none failed.%1 address(es) this person held before are another account''s now, so mail sent to them was NOT searched%'") === 1);
// Round 10: the same closing line then said the emptied addresses were ones
// "the mail steps searched by", though the list it empties holds the address
// it had just said was NOT searched. The sealed record must say only what is
// true of every one of them.
check('I14h ...and that closing line never says the emptied addresses were all searched by',
    n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$taReq} AND `action` = 'completed' "
        . "AND `details` LIKE '%The record of the addresses held before by this account and any merged into it is emptied%' "
        . "AND `details` NOT LIKE '%searched by, are emptied%'") === 1);
// ...and a close that skipped nothing (I13e's) says nothing of the kind.
check('I14g ...while a close that skipped nothing does not say it',
    n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$chainReq} AND `action` = 'completed'") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$chainReq} AND `details` LIKE '%was NOT searched%'") === 0);
// The merged duplicate's former address, taken by somebody once the merge freed it.
$fk2 = $user('FormerKept'); $member($fk2, $siteA);
$fd2 = $user('FormerDup');  $member($fd2, $siteA);
$fdAddr = 'formerdup-' . $tag . '@selftest.invalid';
$fdMerged = $merge($fk2, $fd2, false);
$fe = $user('FormerTaker');
$db->query("UPDATE `tblUsers` SET `emailAddress` = '{$fdAddr}' WHERE `userID` = {$fe}");
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$insert('tblEmailLog', ['toRecipients' => $fdAddr, 'subject' => 'Taker mail ' . $tag, 'errorDetail' => '', 'errorCode' => ''], $third);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
GdprEraser::execute((int) GdprEraser::fileRequest($siteA, $fk2, true)['requestID'], $fk2, $adminA);
check('I14c ...and the same for a merged duplicate\'s former address that somebody took after the merge freed it',
    // (The merge's own notices to that address stay too, by the same rule:
    // from the log alone they cannot be told from the new holder's mail.)
    $fdMerged === 'completed'
    && n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` = '{$fdAddr}' AND `subject` = 'Taker mail {$tag}'") === 1);
// Capitals: an address is the same address whatever its capitals, as the
// address column's own collation says. A gave up `Case-…` with capitals, B now
// holds it in small letters; B's mail stays.
$ca1 = $user('CaseA'); $member($ca1, $siteA);
$cb1 = $user('CaseB'); $member($cb1, $siteA);
$caseOld = 'case-' . $tag . '@selftest.invalid';
$move($ca1, 'Case-' . $tag . '@selftest.invalid');
$move($ca1, 'case-own-' . $tag . '@selftest.invalid');
$move($cb1, $caseOld);
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$insert('tblEmailLog', ['toRecipients' => $caseOld, 'subject' => 'Case mail ' . $tag, 'errorDetail' => '', 'errorCode' => ''], $third);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$caRun = GdprEraser::execute((int) GdprEraser::fileRequest($siteA, $ca1, true)['requestID'], $ca1, $adminA);
check('I14e ...and the same when the two spellings differ only in capitals',
    ($caRun['failed'] ?? -1) === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` = '{$caseOld}' AND `subject` = 'Case mail {$tag}'") === 1,
    json_encode($caRun));
// The start notice goes to EVERY earlier address, including one another account
// holds now (D-200, the owner's decision). Round 7 left those out without
// asking; round 8 put it back, and this pins it.
$na = $user('NoticeA'); $member($na, $siteA);
$nb = $user('NoticeB'); $member($nb, $siteA);
$nt = $user('NoticeTaker');
$naOld = 'noticea-' . $tag . '@selftest.invalid';
$move($na, 'notice-own-' . $tag . '@selftest.invalid');
$db->query("UPDATE `tblUsers` SET `emailAddress` = '{$naOld}' WHERE `userID` = {$nt}");
$naBefore = n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%{$naOld}%'");
M::start($db, $adminA, false, false, $siteA, false, $nb, $na);
check('I14d a merge\'s start notice IS sent to an earlier address another account holds now (D-200), and to the current one',
    n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%{$naOld}%'") > $naBefore
    && n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%notice-own-{$tag}@%'") >= 1);

// 🚩 ROUND 7, FINDING 2 (erasure half): a merged-in account found REOPENED is a
//    FAILED step naming it, never a silent skip.
$rk4 = $user('ReopenKept'); $member($rk4, $siteA);
$rd4 = $user('ReopenDup');  $member($rd4, $siteA);
$db->query("INSERT INTO `tblActivityLogs` (`siteID`, `userID`, `activityType`, `activityDescription`) VALUES ({$siteA}, {$rd4}, 'Selftest', 'reopen')");
$reopenMerged = $merge($rk4, $rd4, false);
$db->query("UPDATE `tblUsers` SET `isActive` = 1, `emailAddress` = 'reopened-{$tag}@selftest.invalid' WHERE `userID` = {$rd4}");
$r4req = (int) GdprEraser::fileRequest($siteA, $rk4, true)['requestID'];
$r4    = GdprEraser::execute($r4req, $rk4, $adminA);
check('I15 a merged-in account found reopened FAILS the erasure, naming it, and nothing under its number is erased',
    $reopenMerged === 'completed' && ($r4['failed'] ?? 0) >= 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$r4req} AND `action` = 'failed' "
        . "AND `details` LIKE '%#{$rd4} were merged into this one, but have been changed since%'") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = {$rd4} AND `activityType` = 'Selftest'") === 1, json_encode($r4));

// 🔁 Polish: the earlier-address list holds each address ONCE, however often the
//    account goes back and forth.
$rr = $user('RepeatAddr');
$move($rr, 'rep-b-' . $tag . '@selftest.invalid');
$move($rr, 'repeataddr-' . $tag . '@selftest.invalid');
$move($rr, 'rep-b-' . $tag . '@selftest.invalid');
check('I16 the earlier-address list holds each address once, however often an account goes back and forth',
    n($db, "SELECT JSON_LENGTH(`earlierEmails`) AS n FROM `tblUsers` WHERE `userID` = {$rr}") === 2);

// 🧹 ROUND 8, FINDING 1 (the backstop): details written onto a CLOSED account
//    after its merge (a writer that did not re-check under the lock: here, the
//    write the directory page made before round 8, by SQL) are emptied by an
//    erasure of the account it was merged into. The walk skips that row, so
//    before round 8 nothing ever reached them.
$wk = $user('WrittenKept'); $member($wk, $siteA);
$wd = $user('WrittenDup');  $member($wd, $siteA);
$wMerged = $merge($wk, $wd, false);
$db->query("UPDATE `tblUsers` SET `fullName` = 'Real Name', `phoneNumber` = '07700 900222', `displayPhone` = '07700 900999', "
    . "`displayAddress` = '1 Secret Street', `displayBio` = 'my own bio', `latitude` = 52.2, `longitude` = 0.12, "
    . "`what3words` = 'filled.count.soap', `locale` = 'cy', `calendarToken` = 'tok{$tag}', `totpSecret` = 'SECRET{$tag}', "
    . "`notifyPrefs` = '{}', `earlierEmails` = '[\"writtendup-old-{$tag}@selftest.invalid\"]', `isAdmin` = 1, `totpEnabled` = 1 "
    . "WHERE `userID` = {$wd}");
// ...and a photo, so the row holds a photo AND other details (round 10: such a
// row was counted twice in the step's line).
$wPhoto = $file('written-' . $tag . '.png');
$db->query("UPDATE `tblUsers` SET `displayPhoto` = '{$wPhoto}' WHERE `userID` = {$wd}");
$wReq = (int) GdprEraser::fileRequest($siteA, $wk, true)['requestID'];
$wRun = GdprEraser::execute($wReq, $wk, $adminA);
// Every column the account's own erasure instruction empties, read from the
// catalogue the eraser reads, except the e-mail column (the merged marker).
$accountEntry = null;
foreach (GdprEraser::catalogue() as $entry) {
    if ($entry['table'] === 'tblUsers' && $entry['userCol'] === 'userID' && $entry['action'] === 'anonymise') {
        $accountEntry = $entry;
    }
}
$stillHeld = [];
foreach (array_diff((array) ($accountEntry['nullCols'] ?? []), ['emailAddress']) as $col) {
    if (n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$wd} AND `{$col}` IS NOT NULL") !== 0) {
        $stillHeld[] = $col;
    }
}
check('I17 premise: the merge completed and the account instruction names the columns to empty',
    $wMerged === 'completed' && count((array) ($accountEntry['nullCols'] ?? [])) > 5);
check('I17b an erasure of the account it went into empties every one of those columns on the closed account',
    ($wRun['failed'] ?? -1) === 0 && $stillHeld === [], json_encode($stillHeld) . ' ' . json_encode($wRun));
check('I17c ...and leaves it closed as a merged account: switched off, no administrator, the merged marker, the merged name',
    n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$wd} AND `isActive` = 0 AND `isAdmin` = 0 AND `totpEnabled` = 0 "
        . "AND `fullName` = '" . M::CLOSED_NAME . "' AND `emailAddress` = CONCAT('merged-', `userID`, '@merged.invalid')") === 1
    && in_array($wd, M::mergedInto($db, $wk), true));
check('I17d ...the photo file is deleted, and the step\'s line counts that ONE account once, not once per kind of detail',
    is_file($photoRoot . $sep . '_uploads' . $sep . $wPhoto) === false
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$wReq} AND `tableName` = 'tblUsers' "
        . "AND `action` = 'anonymise' AND `details` LIKE '%closed account(s) merged into this one are emptied (1 row(s))%'") === 1);

// 🔁 ROUND 9 (the independent check's probe, made a test): a run that FAILS
//    part-way must leave every address it searches by for the next run. Before
//    round 9 the walk emptied the person's earlier-address list and the merge
//    step emptied a merged-in account's recorded addresses whether or not the
//    searches had succeeded, so run 2 never searched by them and closed as "none
//    failed" with the mail still in the send log. Both kinds of address, each
//    with a send-log line the first run cannot change (a trigger refuses it) and
//    the second can; each list also holds one address another account holds now.
$rP  = $user('RetryPerson'); $member($rP, $siteA);
$rO  = $user('RetryOther');  $member($rO, $siteA);
$rK  = $user('RetryKept');   $member($rK, $siteA);
$rD  = $user('RetryDup');    $member($rD, $siteA);
$rO2 = $user('RetryOther2'); $member($rO2, $siteA);
$freeA   = 'retry-free-' . $tag . '@selftest.invalid';
$takenA  = 'retry-taken-' . $tag . '@selftest.invalid';
$takenA2 = 'retry-taken2-' . $tag . '@selftest.invalid';
$rdOwn   = 'retrydup-' . $tag . '@selftest.invalid';
$rMerged = $merge($rK, $rD, false);
$db->query("UPDATE `tblUsers` SET `emailAddress` = '{$takenA}' WHERE `userID` = {$rO}");
$db->query("UPDATE `tblUsers` SET `emailAddress` = '{$takenA2}' WHERE `userID` = {$rO2}");
$db->query("UPDATE `tblUsers` SET `earlierEmails` = JSON_ARRAY('{$freeA}', '{$takenA}') WHERE `userID` = {$rP}");
$db->query("UPDATE `tblAccountMergeSide` SET `formerEarlierEmails` = JSON_ARRAY('{$takenA2}') WHERE `userID` = {$rD}");
$db->query('SET FOREIGN_KEY_CHECKS = 0');
foreach ([$freeA, $rdOwn] as $a) {
    $insert('tblEmailLog', ['toRecipients' => $a, 'subject' => 'Retry mail ' . $tag, 'errorDetail' => '', 'errorCode' => ''], $third);
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$retryLines = static fn (string $a): int => n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%{$a}%' AND `subject` = 'Retry mail {$tag}'");
$failOnce = "CREATE TRIGGER `am_selftest_retry` BEFORE UPDATE ON `tblEmailLog` FOR EACH ROW BEGIN IF OLD.`toRecipients` LIKE '%{$freeA}%' "
    . "OR OLD.`toRecipients` LIKE '%{$rdOwn}%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'transient'; END IF; END";
$retryBefore = $retryLines($freeA) === 1 && $retryLines($rdOwn) === 1;
$retried = [];
foreach (['person' => $rP, 'merged' => $rK] as $kind => $who) {
    $db->query($failOnce);
    $rid = (int) GdprEraser::fileRequest($siteA, $who, true)['requestID'];
    $run1 = GdprEraser::execute($rid, $who, $adminA);
    $db->query('DROP TRIGGER IF EXISTS `am_selftest_retry`');
    $run2 = GdprEraser::execute($rid, $who, $adminA);
    $retried[$kind] = ['rid' => $rid, 'run1' => $run1, 'run2' => $run2];
}
check('I18 premise: the merge completed, both lines exist, and run 1 FAILED on each (the send log refused to change)',
    $rMerged === 'completed' && $retryBefore === true
    && ($retried['person']['run1']['failed'] ?? 0) >= 1 && ($retried['merged']['run1']['failed'] ?? 0) >= 1, json_encode($retried));
check('I18b ...run 2 searched by the person\'s own earlier address again, and the send-log line is gone',
    ($retried['person']['run2']['failed'] ?? -1) === 0 && $retryLines($freeA) === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `requestID` = {$retried['person']['rid']} AND `status` = 'completed'") === 1,
    json_encode($retried['person']['run2']));
check('I18c ...and by the merged-in account\'s recorded address again, and that line is gone too',
    ($retried['merged']['run2']['failed'] ?? -1) === 0 && $retryLines($rdOwn) === 0, json_encode($retried['merged']['run2']));
check('I18d ...and each run-2 closing line still says one address was NOT searched (another account holds it now)',
    n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` IN ({$retried['person']['rid']}, {$retried['merged']['rid']}) "
        . "AND `action` = 'completed' AND `details` LIKE '%1 address(es) this person held before are another account''s now%'") === 2);
check('I18e ...and only at that close are the remembered addresses emptied: the list, the merged-in account\'s addresses',
    n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$rP} AND `earlierEmails` IS NULL") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `userID` = {$rD} AND `formerEmail` IS NULL AND `formerEarlierEmails` IS NULL") === 1);

// 🔁 ROUND 10 (the independent check's S1): the account's OWN address changed
//    AFTER the request was filed. Run 1 searches by the new address and fails;
//    the walk then replaces that address with the made-up one. Run 2 reads the
//    request's (older) address and the earlier-address list, so unless the
//    replaced address is kept somewhere a later run reads, run 2 never searched
//    by it and closed as "none failed" with its send-log line still there.
//    Round 11: it is kept ON THE REQUEST (`replacedEmail`), never appended to
//    the account's list, which could be too full to take it (I22).
//    ROUND 11 (the check's S1d): between the two runs the OLD address, the one
//    the request was filed with, passes to another account (Zed). Run 2 takes
//    that address from the request and used to search it, erasing Zed's line.
$cp    = $user('ChangedAfterFiling'); $member($cp, $siteA);
$cpOld = q($db, "SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = {$cp}")[0]['emailAddress'];
$cpReq = (int) GdprEraser::fileRequest($siteA, $cp, true)['requestID'];
$cpNew = 'changed-after-' . $tag . '@selftest.invalid';
$move($cp, $cpNew);
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$insert('tblEmailLog', ['toRecipients' => $cpNew, 'subject' => 'Changed mail ' . $tag, 'errorDetail' => '', 'errorCode' => ''], $third);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$cpLines = static fn (): int => n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%{$cpNew}%' AND `subject` = 'Changed mail {$tag}'");
$cpBefore = $cpLines();
$cpList   = q($db, "SELECT `earlierEmails` FROM `tblUsers` WHERE `userID` = {$cp}")[0]['earlierEmails'];
$db->query("CREATE TRIGGER `am_selftest_retry` BEFORE UPDATE ON `tblEmailLog` FOR EACH ROW BEGIN IF OLD.`toRecipients` LIKE '%{$cpNew}%' "
    . "THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'transient'; END IF; END");
$cpRun1 = GdprEraser::execute($cpReq, $cp, $adminA);
$db->query('DROP TRIGGER IF EXISTS `am_selftest_retry`');
$cpAfter1 = q($db, "SELECT `r`.`subjectEmail`, `r`.`replacedEmail`, `u`.`earlierEmails` FROM `tblErasureRequest` `r` "
    . "JOIN `tblUsers` `u` ON `u`.`userID` = {$cp} WHERE `r`.`requestID` = {$cpReq}")[0] ?? [];
// Zed takes the old address now, and mail is sent to Zed at it.
$zed = $user('ZedTaker'); $member($zed, $siteA);
$db->query("UPDATE `tblUsers` SET `emailAddress` = '{$cpOld}' WHERE `userID` = {$zed}");
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$insert('tblEmailLog', ['toRecipients' => $cpOld, 'subject' => 'Zed mail ' . $tag, 'errorDetail' => '', 'errorCode' => ''], $third);
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$cpRun2 = GdprEraser::execute($cpReq, $cp, $adminA);
check('I20 premise: the request was filed under the old address, the line exists, and run 1 FAILED',
    $cpBefore === 1 && ($cpRun1['failed'] ?? 0) >= 1 && ($cpAfter1['subjectEmail'] ?? '') === $cpOld, json_encode($cpRun1));
check('I20a ...run 1 kept the address it replaced ON THE REQUEST, and left the account\'s earlier-address list exactly as it was',
    ($cpAfter1['replacedEmail'] ?? '') === $cpNew && ($cpAfter1['earlierEmails'] ?? null) === $cpList, json_encode($cpAfter1));
check('I20b ...run 2 searched by the address the account held when run 1 replaced it: the line is gone and the request closed',
    ($cpRun2['failed'] ?? -1) === 0 && $cpLines() === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `requestID` = {$cpReq} AND `status` = 'completed'") === 1,
    json_encode($cpRun2));
check('I20c ...and at that close the list and the request\'s kept address are both emptied',
    n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$cp} AND `earlierEmails` IS NULL") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `requestID` = {$cpReq} AND `replacedEmail` IS NULL") === 1);
check('I20d ...run 2 did NOT search the request\'s own address once another account held it: Zed\'s line stays, and the closing line counts it as not searched',
    n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` = '{$cpOld}' AND `subject` = 'Zed mail {$tag}'") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$cpReq} AND `action` = 'completed' "
        . "AND `details` LIKE '%1 address(es) this person held before are another account''s now%'") === 1);

// 🧾 ROUND 11: the shared address-list fragment on a stored value that is not
//    a list of addresses. The column is plain text, so it can hold anything:
//    not JSON at all, or JSON that is not a list. JSON_CONTAINS() on text that
//    is not JSON is an ERROR, which stopped the erasure outright (round 10).
//    Each must be read as no addresses, and a change must still store the
//    replaced address and nothing else readable.
$junkOk = [];
foreach (['plain text, not JSON', '{}', '"x"', '[1,2]'] as $i => $junk) {
    $jw = $user('JunkList' . $i);
    $jwOld = q($db, "SELECT `emailAddress` FROM `tblUsers` WHERE `userID` = {$jw}")[0]['emailAddress'];
    $st = $db->prepare('UPDATE `tblUsers` SET `earlierEmails` = ? WHERE `userID` = ?');
    $st->bind_param('si', $junk, $jw);
    $st->execute();
    $st->close();
    $before = M::addressList($junk);
    try {
        $move($jw, 'junk-new-' . $i . '-' . $tag . '@selftest.invalid');
        $stored = q($db, "SELECT `earlierEmails` FROM `tblUsers` WHERE `userID` = {$jw}")[0]['earlierEmails'];
        $junkOk[$junk] = $before === [] && M::addressList($stored) === [$jwOld];
    } catch (Throwable $e) {
        $junkOk[$junk] = 'threw: ' . $e->getMessage();
    }
}
check('I21 a stored list that is not JSON, or JSON that is not a list, reads as no addresses, and an address change through the shared fragment stores the replaced address',
    array_filter($junkOk, static fn ($ok): bool => $ok !== true) === [], json_encode($junkOk));

// 📏 ROUND 11 (the check's S4): an account whose earlier-address list is LONGER
//    than a TEXT column can hold (65,535 bytes). Round 10's erasure appended to
//    the list, so with the column nearly full the erasure's account step failed
//    "Data too long" on every run and the account could never be erased. The
//    column is MEDIUMTEXT now, and the erasure never appends to it.
$lw   = $user('LongList'); $member($lw, $siteA);
$long = [];
for ($i = 0; $i < 2400; $i++) {
    $long[] = sprintf('long-%04d-%s@selftest.invalid', $i, $tag);
}
$longJson = json_encode($long);
// A column too small for the list REFUSES it (strict mode), which is the
// fault this pins; caught so it is a red check, not a crash.
try {
    $st = $db->prepare('UPDATE `tblUsers` SET `earlierEmails` = ? WHERE `userID` = ?');
    $st->bind_param('si', $longJson, $lw);
    $st->execute();
    $st->close();
    $move($lw, 'long-now-' . $tag . '@selftest.invalid');
} catch (Throwable $e) {
    echo '    (the list could not be stored: ' . $e->getMessage() . ")\n";
}
$lwLen   = n($db, "SELECT LENGTH(`earlierEmails`) AS n FROM `tblUsers` WHERE `userID` = {$lw}");
$lwCount = count(M::addressList(q($db, "SELECT `earlierEmails` FROM `tblUsers` WHERE `userID` = {$lw}")[0]['earlierEmails'] ?? null));
$lwReq = (int) GdprEraser::fileRequest($siteA, $lw, true)['requestID'];
$lwRun = GdprEraser::execute($lwReq, $lw, $adminA);
check('I22 premise: the list is longer than a TEXT column can hold, and an address change still stored onto it',
    $lwLen > 65535 && $lwCount === 2401, $lwLen . ' bytes, ' . $lwCount . ' addresses');
check('I22b ...and the erasure of that account runs with no failed step, closes, and empties the list',
    ($lwRun['failed'] ?? -1) === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureRequest` WHERE `requestID` = {$lwReq} AND `status` = 'completed'") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$lw} AND `earlierEmails` IS NULL") === 1,
    json_encode($lwRun));

// 🖼️ ROUND 9 (polish): a photo written onto a closed account goes FILE FIRST.
//    Its value names a file the eraser will not delete (not an image in the
//    uploads folder), so the step fails; the column must still name it, so the
//    next run can find it. With the file dealt with, the next run empties it.
$pk = $user('PhotoRetryKept'); $member($pk, $siteA);
$pd = $user('PhotoRetryDup');  $member($pd, $siteA);
$pMerged = $merge($pk, $pd, false);
$stray = 'stray-' . $tag . '.txt';
file_put_contents($photoRoot . $sep . $stray, 'not an image');
$db->query("UPDATE `tblUsers` SET `avatarPath` = '{$stray}' WHERE `userID` = {$pd}");
$pReq  = (int) GdprEraser::fileRequest($siteA, $pk, true)['requestID'];
$pRun1 = GdprEraser::execute($pReq, $pk, $adminA);
$pKept = n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$pd} AND `avatarPath` = '{$stray}'");
@unlink($photoRoot . $sep . $stray);
$pRun2 = GdprEraser::execute($pReq, $pk, $adminA);
check('I19 a photo file on a closed account that cannot be dealt with FAILS the run and leaves the column naming it for the next run',
    $pMerged === 'completed' && ($pRun1['failed'] ?? 0) >= 1 && $pKept === 1, json_encode($pRun1));
check('I19b ...and once the file is gone, the next run empties it and closes',
    ($pRun2['failed'] ?? -1) === 0 && n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$pd} AND `avatarPath` IS NULL") === 1,
    json_encode($pRun2));

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
// A plain-http test server cannot receive https. A request carrying this
// header is treated as https, the way a proxy that ends the encryption would
// report it, so the cookie rules for https can be measured (test-only).
if (($_SERVER['HTTP_X_SELFTEST_HTTPS'] ?? '') === 'on') { $_SERVER['HTTPS'] = 'on'; }
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
$http = static function (string $method, string $path, array $post = [], array $headers = []) use ($port, &$jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string) curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);

    return ['status' => $code, 'body' => substr($raw, $size), 'head' => substr($raw, 0, $size)];
};
/** The Set-Cookie header for one cookie name, or ''. */
$setCookie = static function (string $head, string $name): string {
    return preg_match('/^Set-Cookie:\s*' . preg_quote($name, '/') . '=[^\r\n]*/mi', $head, $m) === 1 ? $m[0] : '';
};
for ($i = 0; $i < 80 && $http('GET', '/login')['status'] === 0; $i++) {
    usleep(250000);
}
// A page with no form still carries the token in its <meta name="csrf-token">.
$tokenOn = static fn (string $html): string => preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1
    ? $m[1] : (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', $html, $n) === 1 ? $n[1] : '');
/** Sign in through the real form; $fresh=false keeps the browser's other cookies (the merge token). */
$signIn = static function (string $username) use ($http, $tokenOn, &$pass): bool {
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
    $costsShown = static function (string $body): bool {
        foreach (M::HISTORY_COSTS as $cost) {
            if (str_contains($body, htmlspecialchars($cost, ENT_QUOTES, 'UTF-8')) === false) {
                return false;
            }
        }

        return str_contains($body, 'data-merge-costs');
    };
    check("E4b [{$mode}] ...and says what stays under the old number and the three things that follow from it (D-199)", $costsShown($page['body']) === true);
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

// 🙈 FINDING 1: the duplicate is a "victim" holding every sensitive kind of
//    record. Until BOTH accounts have agreed in one browser, the other side
//    must learn nothing of them — the attack was persuading an administrator
//    that a victim's account was "my old duplicate", then reading its counts.
$db->query('SET FOREIGN_KEY_CHECKS = 0');
foreach (['tblCareCase' => 'personUserID', 'tblDbsChecks' => 'userID', 'tblKidProfiles' => 'parentUserID', 'tblGiftAidDeclaration' => 'donorID'] as $kindTable => $kindColumn) {
    $insert($kindTable, [$kindColumn => $eDup], $third);
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');
$sensitive = ['Care case', 'DBS (criminal record)', 'Children&#039;s profiles', 'Gift aid declaration', 'Prayer requests'];
$revealed  = static fn (string $body): array => array_values(array_filter($sensitive, static fn (string $k): bool => str_contains($body, $k) === true));
$jar = $jarB;
@unlink($jar);
$signIn($loginKept);
$keptEarly = $http('GET', '/account/merge?request=' . $rid);
check('E14b the KEPT side, freshly signed in, before anybody agreed: NOTHING about the duplicate\'s records',
    $keptEarly['status'] === 200 && $revealed($keptEarly['body']) === [], json_encode($revealed($keptEarly['body'])));
// Finding F: the kept account's own records are under ONE heading true for it.
check('E14c ...its own records are headed as STAYING, never "would move" or "deleted with the duplicate"',
    str_contains($keptEarly['body'], htmlspecialchars(M::KEPT_SIDE_WORDS[0], ENT_QUOTES, 'UTF-8'))
    && str_contains($keptEarly['body'], 'Would move to the account that stays') === false
    && str_contains($keptEarly['body'], 'deleted with the duplicate') === false);
// ⏳ D-200: within 7 days of the notice, the page says when it opens and refuses.
check('E35 within the 7 days the page says from when it can be agreed to, and offers no agreement',
    str_contains($keptEarly['body'], 'data-merge-state="too-soon"') && preg_match('/from <strong>\d{1,2} [A-Z][a-z]+ \d{4}, \d{2}:\d{2}<\/strong>/', $keptEarly['body']) === 1
    && str_contains($keptEarly['body'], 'data-merge-state="first"') === false);
$http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($keptEarly['body']), 'requestID' => (string) $rid, 'action' => 'agree']);
check('E35b ...and an agreement posted anyway is refused, recording nothing',
    n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `requestID` = {$rid} AND `confirmedAtUtc` IS NOT NULL") === 0);
am_open($db, $rid);
$jar = $jarA;

check('E15 the duplicate signs in', $signIn($loginDup));
$first = $http('GET', '/account/merge?request=' . $rid);
check('E16 ...sees the first step, the two NUMBERS, and none of the other account\'s details',
    str_contains($first['body'], 'data-merge-state="first"') && str_contains($first['body'], 'account ' . $eKept)
    && str_contains($first['body'], 'Kept Name ' . $tag) === false && str_contains($first['body'], 'Secret body ' . $tag) === false);
check('E17 ...and is told plainly it cannot be undone', str_contains($first['body'], 'This cannot be undone'));
check('E17b the duplicate, freshly signed in, sees counts of ITS OWN records',
    count($revealed($first['body'])) === count($sensitive), json_encode($revealed($first['body'])));
check('E17c ...but nothing that compares them with the other account', str_contains($first['body'], 'The other account has one too') === false);
check('E17d ...under headings true for the duplicate: what moves, what is deleted with it',
    str_contains($first['body'], 'Would move to the account that stays')
    && str_contains($first['body'], htmlspecialchars(M::KEPT_SIDE_WORDS[0], ENT_QUOTES, 'UTF-8')) === false);
$agree1 = $http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($first['body']), 'requestID' => (string) $rid, 'action' => 'agree']);
check('E18 agreeing for the duplicate is recorded and the browser is given its token',
    ($agree1['status'] === 302 || $agree1['status'] === 303) && str_contains((string) file_get_contents($jar), M::BROWSER_COOKIE)
    && n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `requestID` = {$rid} AND `userID` = {$eDup} AND `confirmedAtUtc` IS NOT NULL") === 1);
// 🍪 Finding E: the cookie's protections, read from the real Set-Cookie header.
$plainCookie = $setCookie($agree1['head'], M::BROWSER_COOKIE);
check('E18b over http the token cookie is HttpOnly, SameSite=Strict, Path=/, with no Domain',
    $plainCookie !== '' && stripos($plainCookie, 'HttpOnly') !== false && stripos($plainCookie, 'SameSite=Strict') !== false
    && stripos($plainCookie, 'path=/') !== false && stripos($plainCookie, 'domain=') === false, $plainCookie);
$spent = $http('GET', '/account/merge?request=' . $rid);
check('E19 the proof was spent: the same sign-in cannot agree again', str_contains($spent['body'], 'data-merge-state="needs-proof"'));

// The kept account in a DIFFERENT browser (no token) restarts, it does not complete.
$jar = $jarB;
@unlink($jar);
$signIn($loginKept);
$other = $http('GET', '/account/merge?request=' . $rid);
check('E20 the other account in a browser WITHOUT the token is offered only the first step', str_contains($other['body'], 'data-merge-state="first"'));
check('E20b ...and, although the duplicate HAS agreed, still sees nothing about the duplicate\'s records',
    $revealed($other['body']) === [], json_encode($revealed($other['body'])));
$jar = $jarA;

// Back in the first browser: sign out, sign in as the kept account, finish.
check('E21 the kept account signs in, in the SAME browser', $signIn($loginKept));
$final = $http('GET', '/account/merge?request=' . $rid);
check('E22 ...and is offered the final step, with the tick and the typed number', str_contains($final['body'], 'data-merge-state="final"')
    && str_contains($final['body'], 'name="moveSignIns"') && str_contains($final['body'], 'name="typedDuplicate"'));
check('E22b ...where, both sides proved, the merge\'s own counts are shown', count($revealed($final['body'])) === count($sensitive));
check('E22f ...and the history heading says it is left exactly as written (true since D-199)',
    str_contains($final['body'], 'History, left exactly as it is') && str_contains($final['body'], 'are never rewritten'));
check('E22g ...and the PERSON is shown the same three costs, and is told truthfully what moves (never "everything")',
    // D-201: what moves is listed and logged; the activity log and change
    // history stay and are never rewritten.
    $costsShown($final['body']) === true && str_contains($final['body'], 'are never rewritten; they are still counted')
    && str_contains($final['body'], 'loans you asked for, photos you')
    && str_contains($final['body'], 'everything that belongs to account') === false);
check('E22c ...and the number to type is NOT printed beside the box', str_contains($final['body'], 'type the number of the account that will be closed</label>'));
check('E22d the Account page offers "Merge my accounts" while the request is open (finding 6)', str_contains($http('GET', '/account')['body'], 'data-merge-link'));
// The made-up "sensitive" rows have done their job; they point at parent rows
// that do not exist, which the real merge's foreign-key checks would refuse.
foreach (['tblCareCase' => 'personUserID', 'tblDbsChecks' => 'userID', 'tblKidProfiles' => 'parentUserID', 'tblGiftAidDeclaration' => 'donorID'] as $kindTable => $kindColumn) {
    $db->query("DELETE FROM `{$kindTable}` WHERE `{$kindColumn}` = {$eDup}");
}
$forged = $http('POST', '/account/merge/save', ['requestID' => (string) $rid, 'action' => 'agree', 'typedDuplicate' => (string) $eDup]);
check('E22e a post with NO form token is refused (400) and merges nothing (the check\'s P17)',
    $forged['status'] === 400 && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$eDup}") === 1);
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
// D-199: the kept account's data copy includes what is under the duplicate's number.
$copy = json_decode($http('GET', '/account/data-export')['body'], true);
$dupSide = array_filter((array) ($copy['data']['accountMerges'] ?? []), static fn ($r): bool => is_array($r) && (int) ($r['userID'] ?? 0) === $eDup && ($r['side'] ?? '') === 'duplicate');
check('E25b the kept account\'s data copy includes records under the DUPLICATE\'s number (D-199)', $dupSide !== [],
    is_array($copy) ? 'accountMerges: ' . json_encode($copy['data']['accountMerges'] ?? null) : 'not JSON');
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
check('E27b once merged, the Account page no longer offers it', str_contains($http('GET', '/account')['body'], 'data-merge-link') === false);

// 🔑 FINDING 2: an administrator who resets BOTH passwords cannot then sign in
//    as each and "agree" for the person (D-190). Driven through the REAL
//    administrator's page, then the real sign-in and the real agree.
$ak = $user('ResetKept'); $member($ak, $siteOne); $loginAk = $login($ak, 'ResetKept');
$ad = $user('ResetDup');  $member($ad, $siteOne); $loginAd = $login($ad, 'ResetDup');
$signIn($loginDc);
$page = $http('GET', $mergeUrl($ak, $ad));
check('E30 an administrator post with NO form token cannot start a request (400)',
    $http('POST', '/admin/users/merge/start', ['keep' => (string) $ak, 'duplicate' => (string) $ad])['status'] === 400);
$http('POST', '/admin/users/merge/start', ['csrf_token' => $tokenOn($page['body']), 'keep' => (string) $ak, 'duplicate' => (string) $ad]);
$arid = am_open($db, (int) q($db, "SELECT MAX(`requestID`) AS r FROM `tblAccountMergeRequest`")[0]['r']);
check('E31 ...nor cancel one (400)', $http('POST', '/admin/users/merge/cancel', ['requestID' => (string) $arid])['status'] === 400
    && n($db, "SELECT `status` = 'waiting' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$arid}") === 1);
foreach ([[$ak, 'ResetKept'], [$ad, 'ResetDup']] as [$who, $name]) {
    $users = $http('GET', '/admin/users');
    $http('POST', '/admin/users/save', ['csrf_token' => $tokenOn($users['body']), 'action' => 'update', 'userID' => (string) $who,
        'fullName' => 'Merge ' . $name, 'emailAddress' => strtolower($name) . '-' . $tag . '@selftest.invalid', 'phoneNumber' => '',
        'isActive' => '1', 'password' => $pass . 'R']);
}
check('E32 the administrator\'s password reset of both accounts is recorded as somebody else choosing their way in',
    n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` IN ({$ak}, {$ad}) AND `signInChangedByOtherAtUtc` IS NOT NULL") === 2);
$passBefore = $pass;
$pass .= 'R';
$signIn($loginAd);
$try1 = $http('GET', '/account/merge?request=' . $arid);
$http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($try1['body']), 'requestID' => (string) $arid, 'action' => 'agree']);
$signIn($loginAk);
$try2 = $http('GET', '/account/merge?request=' . $arid);
$http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($try2['body']), 'requestID' => (string) $arid, 'action' => 'agree', 'typedDuplicate' => (string) $ad]);
$pass = $passBefore;
check('E33 THE ATTACK: signing in with the passwords the administrator set and agreeing on BOTH sides is refused',
    n($db, "SELECT COUNT(*) AS n FROM `tblAccountMergeSide` WHERE `requestID` = {$arid} AND `confirmedAtUtc` IS NOT NULL") === 0
    && n($db, "SELECT `status` = 'waiting' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$arid}") === 1
    && n($db, "SELECT `isActive` AS n FROM `tblUsers` WHERE `userID` = {$ad}") === 1);
// Changing somebody else's e-mail address (where their reset link would go) is recorded the same way.
$ex = $user('EmailTarget'); $member($ex, $siteOne);
$signIn($loginDc);
$users = $http('GET', '/admin/users');
$http('POST', '/admin/users/save', ['csrf_token' => $tokenOn($users['body']), 'action' => 'update', 'userID' => (string) $ex,
    'fullName' => 'Merge EmailTarget', 'emailAddress' => 'moved-' . $tag . '@selftest.invalid', 'phoneNumber' => '', 'isActive' => '1']);
check('E34 an administrator changing somebody else\'s e-mail address is recorded too',
    n($db, "SELECT `signInChangedByOtherAtUtc` IS NOT NULL AS n FROM `tblUsers` WHERE `userID` = {$ex}") === 1
    && n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$ex} AND `emailAddress` = 'moved-{$tag}@selftest.invalid'") === 1);
check('E34b ...and the address it held BEFORE is kept, for a merge notice (D-200)',
    n($db, "SELECT JSON_CONTAINS(`earlierEmails`, JSON_QUOTE('emailtarget-{$tag}@selftest.invalid')) AS n FROM `tblUsers` WHERE `userID` = {$ex}") === 1);
// D-200: a merge's start notice goes to that earlier address too.
$ex2 = $user('EmailPartner'); $member($ex2, $siteOne);
$page = $http('GET', $mergeUrl($ex2, $ex));
$http('POST', '/admin/users/merge/start', ['csrf_token' => $tokenOn($page['body']), 'keep' => (string) $ex2, 'duplicate' => (string) $ex]);
check('E36 the start notice was sent to the address the account held before an administrator changed it',
    n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%emailtarget-{$tag}@selftest.invalid%'") >= 1);
// 📧 ROUND 6, THE CHECK'S EXACT SEQUENCE: an administrator fixes a typo
//    (a0 -> a1), the person moves to a mailbox of their own (a1 -> a2, on their
//    own account page), then a hostile administrator replaces it (a2 ->
//    attacker). The old single "address before the first change by somebody
//    else" kept only a0, so the notice went to the attacker and a dead address
//    and NEVER to a2, the one the person really uses.
$sq = $user('SeqTarget'); $member($sq, $siteOne); $loginSq = $login($sq, 'SeqTarget');
$a1 = 'seq-a1-' . $tag . '@selftest.invalid';
$a2 = 'seq-a2-' . $tag . '@selftest.invalid';
$at = 'seq-attacker-' . $tag . '@selftest.invalid';
$adminChange = static function (string $to) use ($http, $tokenOn, $sq): void {
    $http('POST', '/admin/users/save', ['csrf_token' => $tokenOn($http('GET', '/admin/users')['body']), 'action' => 'update',
        'userID' => (string) $sq, 'fullName' => 'Merge SeqTarget', 'emailAddress' => $to, 'phoneNumber' => '', 'isActive' => '1']);
};
$signIn($loginDc);
$adminChange($a1);
$signIn($loginSq);
$http('POST', '/account/save', ['csrf_token' => $tokenOn($http('GET', '/account')['body']), 'action' => 'update_profile',
    'fullName' => 'Merge SeqTarget', 'emailAddress' => $a2, 'phoneNumber' => '']);
$selfChanged = n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$sq} AND `emailAddress` = '{$a2}'") === 1;
$signIn($loginDc);
$adminChange($at);
$sqPartner = $user('SeqPartner'); $member($sqPartner, $siteOne);
$page = $http('GET', $mergeUrl($sqPartner, $sq));
$http('POST', '/admin/users/merge/start', ['csrf_token' => $tokenOn($page['body']), 'keep' => (string) $sqPartner, 'duplicate' => (string) $sq]);
$told = static fn (string $address): bool => n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%{$address}%'") >= 1;
check('E36b premise: each step of the sequence really happened (the person changed it themselves, then the attacker\'s address is current)',
    $selfChanged === true && n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$sq} AND `emailAddress` = '{$at}'") === 1);
check('E36c the start notice reaches the address the person set THEMSELVES (a2), and every other address the account held',
    $told($a2) === true && $told($a1) === true && $told('seqtarget-' . $tag . '@selftest.invalid') === true);

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
am_open($db, $crid);
// 🍪 Finding E, the https half. A plain-http server cannot speak https, so the
//    test router treats a request carrying `X-Selftest-Https: on` as https (as a
//    proxy ending the encryption would report it) and the REAL handler decides
//    the cookie from that. The browser-side rule that a `__Host-` cookie needs
//    Secure, Path=/ and no Domain is checked by reading the header it was sent.
$page = $http('GET', '/account/merge?request=' . $crid);
$httpsAgree = $http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($page['body']), 'requestID' => (string) $crid, 'action' => 'agree'],
    ['X-Selftest-Https: on']);
$hostCookie = $setCookie($httpsAgree['head'], '__Host-' . M::BROWSER_COOKIE);
check('E37 over https the token cookie is __Host-prefixed, Secure, HttpOnly, SameSite=Strict, Path=/, with no Domain',
    $hostCookie !== '' && stripos($hostCookie, 'secure') !== false && stripos($hostCookie, 'HttpOnly') !== false
    && stripos($hostCookie, 'SameSite=Strict') !== false && stripos($hostCookie, 'path=/') !== false && stripos($hostCookie, 'domain=') === false,
    $hostCookie);
$page = $http('GET', '/account/merge?request=' . $crid);
$http('POST', '/account/merge/save', ['csrf_token' => $tokenOn($page['body']), 'requestID' => (string) $crid, 'action' => 'cancel']);
check('E29 the person can cancel it', n($db, "SELECT `status` = 'cancelled' AS n FROM `tblAccountMergeRequest` WHERE `requestID` = {$crid}") === 1);

// =============================================================================
echo "J. A merged-away account can never be changed or used again (round 5, finding 4)\n";
// =============================================================================
// ⚠️ The measured fault: a global administrator (or, with one organisation, an
//    organisation administrator) switched a merged-away account back on as a
//    new person; the merge then treated that person's records as the first
//    person's — counted in their "what we hold about you", handed over in their
//    data copy, and erased with them.
// (`AccountGuard` through a short local name: no `use` line halfway down a file.)
$G = '\\Portal\\Core\\AccountGuard';

$facts = ['userID' => 1, 'isAdmin' => 0, 'isRootAdmin' => 0, 'thisOrgActive' => 1, 'otherOrgRows' => 0, 'isErased' => 0, 'isMerged' => 1];
$wrongReach = [];
foreach ([true, false] as $global) {
    foreach ([true, false] as $single) {
        foreach ([$G::REACH_ACCOUNT, $G::REACH_THIS_ORG, $G::REACH_PORTAL, $G::REACH_REHIRE] as $reach) {
            if ($G::decide($global, $facts, $reach, $single)['verdict'] !== $G::MERGED) {
                $wrongReach[] = ($global ? 'global' : 'organisation') . '/' . ($single ? 'one' : 'several') . '/' . $reach;
            }
        }
    }
}
check('J1 every reach but viewing is refused as MERGED, for a global administrator and in either organisation mode',
    $wrongReach === [] && $G::decide(true, $facts, $G::REACH_VIEW, false)['verdict'] === $G::ALLOW, implode(', ', $wrongReach));
$realFacts = $G::facts([$eDup, $eKept], $siteOne);
check('J2 the fact is read from the merge record: the HTTP merge\'s duplicate is merged away, the account it went into is not',
    (int) ($realFacts[$eDup]['isMerged'] ?? 0) === 1 && (int) ($realFacts[$eKept]['isMerged'] ?? 1) === 0, json_encode($realFacts));
check('J2b ...and the verdict has its own true words, never the erased ones',
    str_contains($G::message($G::MERGED, $G::REACH_ACCOUNT), 'merged into another account')
    && str_contains($G::message($G::MERGED, $G::REACH_ACCOUNT), 'erased') === false);
$dupRow = static fn (): array => q($db, "SELECT `isActive`, `fullName`, `emailAddress` FROM `tblUsers` WHERE `userID` = {$eDup}")[0];
$closedBefore = $dupRow();
$reopen = ['action' => 'update', 'userID' => (string) $eDup, 'fullName' => 'Pat Newperson', 'emailAddress' => 'pat-' . $tag . '@selftest.invalid',
    'phoneNumber' => '', 'isActive' => '1'];
// One organisation: the organisation administrator CAN reach the account (no
// "another organisation" to hide it), which is the mode the check measured.
$setMulti('false');
check('J3a premise: the organisation administrator is signed in', $signIn($loginDc) === true);
$http('POST', '/admin/users/save', ['csrf_token' => $tokenOn($http('GET', '/admin/users')['body'])] + $reopen);
check('J3 [one organisation] an organisation administrator cannot switch the merged-away account back on as a new person',
    $dupRow() === $closedBefore, json_encode($dupRow()));
$gAdmin = $user('PageGlobal', ['isRootAdmin' => 1, 'isAdmin' => 1]); $member($gAdmin, $siteOne, 1, 1); $loginG = $login($gAdmin, 'PageGlobal');
$setMulti('true');
// A premise, so J4 to J7 cannot pass because the requests never got in: the
// global administrator really is signed in and really reaches the pages.
$gSignedIn = $signIn($loginG);
// With several organisations a global administrator chooses one first, as a
// person does (`/site/choose`, then `/site/switch`).
$http('POST', '/site/switch', ['csrf_token' => $tokenOn($http('GET', '/site/choose')['body']), 'site_id' => (string) $siteOne]);
$gSites    = $http('GET', '/admin/sites/users?site=' . $siteOne);
check('J4a premise: the global administrator is signed in and reaches the organisation members page',
    $gSignedIn === true && $gSites['status'] === 200 && str_contains($gSites['body'], 'name="csrf_token"'), $gSites['status'] . ' signed in: ' . var_export($gSignedIn, true) . ' ' . (preg_match('/^Location: *(.*)$/mi', (string) $gSites['head'], $gl) === 1 ? $gl[1] : ''));
$http('POST', '/admin/users/save', ['csrf_token' => $tokenOn($http('GET', '/admin/users')['body'])] + $reopen);
check('J4 [several organisations] nor can a GLOBAL administrator', $dupRow() === $closedBefore, json_encode($dupRow()));
$http('POST', '/admin/sites/users?site=' . $siteOne, ['csrf_token' => $tokenOn($http('GET', '/admin/sites/users?site=' . $siteOne)['body']),
    'action' => 'add', 'userID' => (string) $eDup]);
check('J5 ...nor put it into an organisation', n($db, "SELECT COUNT(*) AS n FROM `tblUserSites` WHERE `userID` = {$eDup}") === 0);
$http('POST', '/admin/users/unplaced/save', ['csrf_token' => $tokenOn($http('GET', '/admin/users')['body']),
    'userID' => (string) $eDup, 'siteID' => (string) $siteOne]);
check('J6 ...nor place it as an account that belongs to no organisation', n($db, "SELECT COUNT(*) AS n FROM `tblUserSites` WHERE `userID` = {$eDup}") === 0);
// The offboarding app is switched off in a fresh install; switched on for this
// organisation here, so the post really reaches the handler's guard (measured:
// without it the router answered 403 and J7 passed without testing anything,
// which J8's exact count is what caught), and put back afterwards.
$offWas = q($db, "SELECT `settingValue` FROM `tblSettings` WHERE `siteID` = {$siteOne} AND `settingKey` = 'offboarding.enabled'");
$db->query("INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES ({$siteOne}, 'offboarding.enabled', 'true', 'false', 0) "
    . "ON DUPLICATE KEY UPDATE `settingValue` = 'true'");
$offb = $http('POST', '/offboarding/do', ['csrf_token' => $tokenOn($http('GET', '/admin/users')['body']), 'userID' => (string) $eDup,
    'reason' => 'selftest', 'dataDisposition' => 'retain', 'effectiveDate' => gmdate('Y-m-d')]);
if ($offWas === []) {
    $db->query("DELETE FROM `tblSettings` WHERE `siteID` = {$siteOne} AND `settingKey` = 'offboarding.enabled'");
} else {
    $db->query("UPDATE `tblSettings` SET `settingValue` = '" . $db->real_escape_string((string) $offWas[0]['settingValue']) . "' WHERE `siteID` = {$siteOne} AND `settingKey` = 'offboarding.enabled'");
}
check('J7 ...nor offboard it (which would also file an erasure request for it)',
    n($db, "SELECT COUNT(*) AS n FROM `tblOffboarding` WHERE `userID` = {$eDup}") === 0 && $dupRow() === $closedBefore,
    $offb['status'] . ' ' . (preg_match('/^Location: *(.*)$/mi', (string) $offb['head'], $ol) === 1 ? $ol[1] : '') . ' ' . substr(strip_tags($offb['body']), 0, 120));
$refusalsLogged = n($db, "SELECT COUNT(*) AS n FROM `tblErrors` WHERE `errorCode` = 'ACCOUNT_CHANGE_REFUSED' "
    . "AND `errorDetail` LIKE '%merged into another account%' AND `errorDetail` LIKE '%Target account number: {$eDup}\n%'");
check('J8 each of the five attempts (J3 to J7) is recorded as a refusal, with the reason, where only a global administrator reads it',
    $refusalsLogged === 5, (string) $refusalsLogged);
// Nothing can sign in to it or be matched to it.
check('J9 nothing can sign in as it or match it by address: switched off, no password, and an address on a name no mail service issues',
    (int) $closedBefore['isActive'] === 0 && str_ends_with((string) $closedBefore['emailAddress'], '@merged.invalid')
    && n($db, "SELECT COUNT(*) AS n FROM `tblLocalAccounts` WHERE `userID` = {$eDup}") === 0
    && n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `emailAddress` = 'pagedup-{$tag}@selftest.invalid'") === 0);
// A SECOND LINE: if one were ever found open (only hand-written SQL can do it
// now), nothing follows it any more.
$db->query("UPDATE `tblUsers` SET `isActive` = 1, `emailAddress` = 'pat-{$tag}@selftest.invalid' WHERE `userID` = {$eDup}");
$followedWhileOpen = M::mergedInto($db, $eKept);
$db->query("UPDATE `tblUsers` SET `isActive` = 0, `emailAddress` = CONCAT('merged-', `userID`, '@merged.invalid') WHERE `userID` = {$eDup}");
check('J10 a merged-away account found open is NOT followed (so nothing of a new person reaches the survivor\'s data copy, My data or erasure); closed again, it is',
    in_array($eDup, $followedWhileOpen, true) === false && in_array($eDup, M::mergedInto($db, $eKept), true) === true, json_encode($followedWhileOpen));
// An erasure request naming the merged-away number itself.
check('J11 the merged-away account\'s records belong to the account it went into', M::survivorOf($db, $eDup) === $eKept && M::survivorOf($db, $eKept) === null);
$fileProblem = '';
try {
    GdprEraser::fileRequest($siteOne, $eDup, true);
} catch (RuntimeException $e) {
    $fileProblem = $e->getMessage();
}
check('J12 an erasure request cannot be filed for the merged-away number, and the sentence names the account to use',
    str_contains($fileProblem, 'merged into account #' . $eKept), $fileProblem);
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteOne}, {$eDup}, 'byhand-{$tag}@selftest.invalid', '2027-01-01', 'pending_review')");
$handReq = (int) $db->insert_id;
$historyBefore = n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = {$eDup}");
$handRun = GdprEraser::execute($handReq, $eDup, $adminA);
check('J13 a request written by hand for it is REFUSED before anything changes, naming the account to use',
    ($handRun['failed'] ?? 0) === 1 && ($handRun['changed'] ?? true) === false
    && n($db, "SELECT COUNT(*) AS n FROM `tblActivityLogs` WHERE `userID` = {$eDup}") === $historyBefore
    && n($db, "SELECT COUNT(*) AS n FROM `tblErasureAudit` WHERE `requestID` = {$handReq} AND `details` LIKE '%merged into account #{$eKept}%'") === 1,
    json_encode($handRun));
$db->query("DELETE FROM `tblErasureRequest` WHERE `requestID` = {$handReq}");
// 📮 Round 6, finding 3: a workflow STARTED as the duplicate, finished after
//    the merge. The starter stays as written (D-199), so the outcome e-mail
//    goes to the account it was merged into, not to nobody.
$wfk = $user('WfKept'); $member($wfk, $siteA);
$wfd = $user('WfDup');  $member($wfd, $siteA);
$wfMerged = $merge($wfk, $wfd, false);
$notify = new ReflectionMethod(\Portal\Core\Workflow::class, 'notifyOutcome');
$notify->invoke(null, ['siteID' => $siteA, 'startedByID' => $wfd, 'subjectLabel' => 'Hall booking ' . $tag, 'workflowName' => 'Bookings'], 'approved');
check('J15 a workflow started as the duplicate tells the account it was merged into its outcome (the starter stays as written)',
    $wfMerged === 'completed'
    // The OUTCOME e-mail itself, by its subject: the merge's own notices also
    // go to this address, so "any mail to it" passed with the fix removed
    // (planted, measured: round 6's T09 stayed green until this said which).
    && n($db, "SELECT COUNT(*) AS n FROM `tblEmailLog` WHERE `toRecipients` LIKE '%wfkept-{$tag}@selftest.invalid%' "
        . "AND `subject` LIKE 'Bookings%Approved%'") === 1, $wfMerged);
// 🔒 ROUND 7, FINDING 2: a save of the duplicate on the administrator's page,
//    begun WHILE the merge is completing. Its AccountGuard checks run before
//    the merge commits and pass; its UPDATE then waits on the merge's lock. It
//    must be refused under that lock, not write over the closed account.
$sk3 = $user('SaveRaceKept'); $member($sk3, $siteOne);
$sd3 = $user('SaveRaceDup');  $member($sd3, $siteOne);
$srq  = am_open($db, M::start($db, $eAdmin, false, false, $siteOne, false, $sk3, $sd3)['requestId']);
$stok = M::agree($db, $srq, $sd3, true, '', 0, false)['token'];
$pauseOn($sd3);
$smid = $spawn($srq, $sk3, $sd3, $stok);
touch($go);
check('J16 premise: the merge is asleep inside its transaction, holding the duplicate\'s row', $awaitPause() === true);
$http('POST', '/admin/users/save', ['csrf_token' => $tokenOn($http('GET', '/admin/users')['body']), 'action' => 'update',
    'userID' => (string) $sd3, 'fullName' => 'Reopened Person', 'emailAddress' => 'reopened-race-' . $tag . '@selftest.invalid',
    'phoneNumber' => '', 'isActive' => '1']);
$sOut = $finish($smid);
$db->query('DROP TRIGGER IF EXISTS `am_selftest_pause`');
@unlink($go);
check('J16b ...and the save is REFUSED under the lock: the merge stands and the account stays exactly as the merge closed it',
    $sOut === 'completed'
    && n($db, "SELECT COUNT(*) AS n FROM `tblUsers` WHERE `userID` = {$sd3} AND `isActive` = 0 AND `emailAddress` = CONCAT('merged-', `userID`, '@merged.invalid')") === 1
    && in_array($sd3, M::mergedInto($db, $sk3), true), $sOut);
// 🔒 ROUND 8, FINDING 1: the duplicate's OWN session, saving its own details
//    while the merge completes (the independent check's probe, made a test).
//    Signed in as the duplicate in a second cookie jar, the form token read
//    BEFORE the merge starts, then posted while the merge sleeps holding the
//    row. Each save must be refused under the lock and write nothing.
$raceOwn = static function (string $label, string $path, array $fields, string $getPage) use ($db, $user, $member, $login, $siteOne, $eAdmin, $pauseOn, $spawn, $awaitPause, $finish, $go, $http, $tokenOn, $signIn, &$jar, $jarA, $jarB): array {
    $k  = $user('Own' . $label . 'Kept'); $member($k, $siteOne);
    $d  = $user('Own' . $label . 'Dup');  $member($d, $siteOne);
    $lg = $login($d, 'Own' . $label . 'Dup');
    $jar = $jarB;
    @unlink($jar);
    $in  = $signIn($lg);
    $tok = $tokenOn($http('GET', $getPage)['body']);
    $rq  = am_open($db, M::start($db, $eAdmin, false, false, $siteOne, false, $k, $d)['requestId']);
    $t   = M::agree($db, $rq, $d, true, '', 0, false)['token'];
    $pauseOn($d);
    $mid = $spawn($rq, $k, $d, $t);
    touch($go);
    $paused = $awaitPause();
    $fields['csrf_token'] = $tok;
    $resp = $http('POST', $path, $fields);
    $out  = $finish($mid);
    $db->query('DROP TRIGGER IF EXISTS `am_selftest_pause`');
    @unlink($go);
    $jar = $jarA;

    return ['premise' => $in === true && $tok !== '' && $paused === true, 'out' => $out, 'resp' => $resp, 'kept' => $k, 'dup' => $d,
        'row' => q($db, "SELECT `isActive`, `fullName`, `emailAddress`, `phoneNumber`, `displayPhone`, `displayAddress`, `displayBio`, "
            . "`latitude`, `longitude`, `what3words` FROM `tblUsers` WHERE `userID` = {$d}")[0] ?? []];
};
$closedAsMerged = static fn (array $row, int $d): bool => (int) ($row['isActive'] ?? 1) === 0
    && ($row['fullName'] ?? '') === M::CLOSED_NAME && ($row['emailAddress'] ?? '') === 'merged-' . $d . '@merged.invalid'
    && $row['phoneNumber'] === null && $row['displayPhone'] === null && $row['displayAddress'] === null
    && $row['displayBio'] === null && $row['latitude'] === null && $row['longitude'] === null && $row['what3words'] === null;
$dirRace = $raceOwn('Dir', '/directory/save', ['displayPhone' => '07700 900999', 'displayAddress' => '1 Secret Street',
    'displayBio' => 'my own bio', 'latitude' => '52.2', 'longitude' => '0.12'], '/directory/my-settings');
check('J17 premise: the duplicate is signed in on its own, holds the directory form\'s token, and the merge is asleep holding its row',
    $dirRace['premise'] === true, json_encode($dirRace['premise']));
check('J17b ...and its directory save is REFUSED under the lock: the merge stands and nothing of the person is written onto the closed account',
    $dirRace['out'] === 'completed' && $closedAsMerged($dirRace['row'], $dirRace['dup']), $dirRace['out'] . ' ' . json_encode($dirRace['row']));
$acctRace = $raceOwn('Acct', '/account/save', ['action' => 'update_profile', 'fullName' => 'Real Name Again',
    'emailAddress' => 'realagain-' . $tag . '@selftest.invalid', 'phoneNumber' => '07700 900111'], '/account');
check('J18 premise: the same, for the person\'s own account page', $acctRace['premise'] === true, json_encode($acctRace['premise']));
check('J18b ...and that save is REFUSED too, saying the account was merged, with the account exactly as the merge closed it',
    $acctRace['out'] === 'completed' && $closedAsMerged($acctRace['row'], $acctRace['dup'])
    && preg_match('/^Location: *[^\r\n]*error=merged/mi', (string) $acctRace['resp']['head']) === 1,
    $acctRace['out'] . ' ' . json_encode($acctRace['row']));
// Offered for ANOTHER merge, it is refused with its own words, never "switch
// it back on first" (it never can be) nor "needs a global administrator".
$j14 = $user('J14Kept'); $member($j14, $siteA);
$j14Out = M::start($db, $rootA, true, true, $siteA, false, $j14, $eDup)['outcome'];
check('J14 a merged-away account offered for another merge is refused as already merged away', $j14Out === M::R_MERGED_AWAY, $j14Out);

// The race worker's scratch folder (section J drives one more race).
@unlink($worker);
@unlink($go);
@rmdir($scratch);

// The photo scratch folder (made by this run, random name, under the system temp folder).
$tidy = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($photoRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($tidy as $entry) {
    $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($photoRoot);

echo "\n" . $GLOBALS['am_pass'] . ' passed, ' . $GLOBALS['am_fail'] . " failed\n";
exit($GLOBALS['am_fail'] === 0 ? 0 : 1);
