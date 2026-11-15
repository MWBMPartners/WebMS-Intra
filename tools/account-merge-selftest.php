<?php
// Path: tools/account-merge-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The account merge's preview and refusals, on a real database 🔀 (#1064)
 * -----------------------------------------------------------------------------
 *   SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=3306 php tools/account-merge-selftest.php
 *
 * Needs `web/_sql/full_schema.sql` loaded into a database whose name starts
 * with `selftest_`. It writes rows (with foreign-key checks switched off for the
 * made-up rows of section D) and starts PHP's own web server against a COPY of
 * `web/` for section E.
 *
 *   A  The list against the LOADED database: every column the database links to
 *      tblUsers, and every whole-number column whose name says it holds an
 *      account number, is listed — and nothing listed is missing. A column inside
 *      a unique key is never plain "move". This is the half the text-only hard
 *      gate (check_account_merge_columns.py) cannot prove.
 *   B  The rule on hand-made facts: every refusal is reachable, and the order
 *      that stops an account-existence probe holds.
 *   C  The rule on REAL facts, over two organisations: the organisation
 *      boundary, the global administrator, the permission in every
 *      organisation, erased / closed / administrator / held accounts.
 *   D  The preview, one made-up row per listed column: every column that names
 *      the duplicate is counted exactly once, clashes are found where a unique
 *      key says so, and a money clash refuses.
 *   E  The page over HTTP: who is refused, that a refusal shows no name, that a
 *      preview shows counts, that the Users page offers the link only to those
 *      the page admits, and that the activity record holds numbers only.
 *
 * What it CANNOT do: prove a merge moves anything — the move is not built (it
 * waits on two owner questions on #1064). It also runs one web-server worker,
 * so no concurrency is exercised, which the preview does not need.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
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
foreach (['App', 'Site', 'Logger', 'Roles', 'InstallationSettings', 'Capabilities', 'ApiAuth', 'GdprEraser', 'AccountGuard', 'AccountMerge'] as $class) {
    require_once $coreDir . $class . '.php';
}

use Portal\Core\AccountGuard;
use Portal\Core\AccountMerge as M;
use Portal\Core\Capabilities;
use Portal\Core\GdprEraser;
use Portal\Core\Roles;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    // Global on purpose: App::db() falls back to it, and AccountGuard::facts() asks App::db().
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    refuse("could not reach {$dbName} on {$dbHost}:{$dbPort}");
}
$mysqli->set_charset('utf8mb4');
$db = $mysqli;
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
check('A1 the loaded database has the 196 links to tblUsers this was built against (a shrink would hide tables)', count($linked) >= 196, (string) count($linked));
$missing = array_values(array_filter($accountColumns, static fn (string $n): bool => isset($listed[$n]) === false && isset(M::NOT_AN_ACCOUNT[$n]) === false));
check('A2 every account column in the database is listed in AccountMerge', $missing === [], implode(', ', $missing));
$existing = [];
foreach (q($db, 'SELECT CONCAT(`TABLE_NAME`, \'.\', `COLUMN_NAME`) AS n FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE()') as $r) {
    $existing[$r['n']] = true;
}
$stale = array_values(array_filter(array_keys($listed + M::NOT_AN_ACCOUNT), static fn (string $n): bool => isset($existing[$n]) === false));
check('A3 nothing listed is missing from the database', $stale === [], implode(', ', $stale));
$keys       = M::uniqueKeysOf($db);
$movedInKey = [];
foreach ($listed as $name => $entry) {
    [$t, $c] = explode('.', $name);
    if ($entry['treatment'] === M::MOVE && isset($keys[$t][$c]) === true) {
        $movedInKey[] = $name;
    }
}
check('A4 no column inside a unique key is plain "move" (it would clash or fail when moved)', $movedInKey === [], implode(', ', $movedInKey));
check('A5 the generated attendance key is found through the column it is built from',
    isset($keys['tblEventAttendance']['userID']) === true
    && in_array(['eventID', 'dayDate'], $keys['tblEventAttendance']['userID'], true) === true,
    json_encode($keys['tblEventAttendance']['userID'] ?? null));
check('A6 a primary key that includes the account number is found too (tblUserOutsideServicePref)',
    ($keys['tblUserOutsideServicePref']['userID'] ?? null) === [['serviceKey']], json_encode($keys['tblUserOutsideServicePref']['userID'] ?? null));
$noKey = [];
foreach ($listed as $name => $entry) {
    [$t, $c] = explode('.', $name);
    if (in_array($entry['treatment'], [M::KEEP_KEPT_ON_CLASH, M::REFUSE_ON_CLASH], true) === true && isset($keys[$t][$c]) === false) {
        $noKey[] = $name;
    }
}
echo '  · for information: clash treatments on columns with no unique key today (harmless, they simply move): '
    . ($noKey === [] ? 'none' : implode(', ', $noKey)) . "\n";

// =============================================================================
echo "B. The rule on hand-made facts\n";
// =============================================================================
$ok = ['guard' => AccountGuard::ALLOW, 'isActive' => true, 'adminAnywhere' => false, 'heldElsewhere' => 0];
$facts = static fn (array $over = [], array $k = [], array $d = []): array => array_replace([
    'mayMergeHere' => true, 'mayMergeEverywhere' => true, 'actorId' => 1, 'keptId' => 10, 'duplicateId' => 20,
    'accounts' => [10 => array_replace($ok, $k), 20 => array_replace($ok, $d)],
], $over);
$cases = [
    ['B1 all clear', $facts(), ''],
    ['B2 no permission here', $facts(['mayMergeHere' => false]), M::R_NO_PERMISSION],
    ['B3 the same account twice', $facts(['duplicateId' => 10]), M::R_SAME_ACCOUNT],
    ['B4 an account that does not exist', $facts(['accounts' => [10 => $ok]]), M::R_NOT_FOUND],
    ['B5 an account this administrator cannot see', $facts([], [], ['guard' => AccountGuard::NOT_FOUND]), M::R_NOT_FOUND],
    ['B6 THE PROBE: cannot see it AND lacks the permission elsewhere still says only "not found"',
        $facts(['mayMergeEverywhere' => false], [], ['guard' => AccountGuard::NOT_FOUND]), M::R_NOT_FOUND],
    ['B7 erased', $facts([], ['guard' => AccountGuard::ERASED]), M::R_ERASED],
    ['B8 erased comes before "needs a global administrator"', $facts([], ['guard' => AccountGuard::ERASED], ['guard' => AccountGuard::GLOBAL_ONLY]), M::R_ERASED],
    ['B9 belongs elsewhere: needs a global administrator', $facts([], [], ['guard' => AccountGuard::GLOBAL_ONLY]), M::R_NEEDS_GLOBAL],
    ['B10 lacks the permission in another organisation', $facts(['mayMergeEverywhere' => false]), M::R_NO_PERMISSION],
    ['B11 the actor is the kept account', $facts(['actorId' => 10]), M::R_IS_YOU],
    ['B12 the actor is the duplicate', $facts(['actorId' => 20]), M::R_IS_YOU],
    ['B13 an administrator account', $facts([], [], ['adminAnywhere' => true]), M::R_ADMINISTRATOR],
    ['B14 a closed account', $facts([], ['isActive' => false]), M::R_CLOSED],
    ['B15 an erasure request, offboarding record or held rows', $facts([], [], ['heldElsewhere' => 1]), M::R_REFUSE_IF_ANY],
    ['B16 a missing permission fact is a refusal, never a pass', array_diff_key($facts(), ['mayMergeEverywhere' => 1]), M::R_NO_PERMISSION],
];
foreach ($cases as [$label, $f, $want]) {
    $got = M::whyNotAllowed($f);
    check($label, $got === $want, "got '{$got}', wanted '{$want}'");
}
$wordsOk = true;
foreach ([M::R_NO_PERMISSION, M::R_SAME_ACCOUNT, M::R_NOT_FOUND, M::R_ERASED, M::R_NEEDS_GLOBAL, M::R_IS_YOU,
    M::R_ADMINISTRATOR, M::R_CLOSED, M::R_REFUSE_IF_ANY, M::R_CLASH, M::R_UNKNOWN_CLASH] as $code) {
    $wordsOk = $wordsOk && strlen(M::refusalWords($code)) > 20;
}
check('B17 every refusal has words', $wordsOk);
$threw = false;
try {
    M::refusalWords('no_such');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('B18 an unknown refusal code throws rather than printing nothing', $threw);

// =============================================================================
echo "C. The rule on real facts, over two organisations\n";
// =============================================================================
$tag = bin2hex(random_bytes(3));
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

$adminA = $user('AdminA');        $member($adminA, $siteA, 1, 1); $grantDc($adminA, $siteA);
$adminNoCap = $user('AdminNoCap'); $member($adminNoCap, $siteA, 1, 1);
$capNoAdmin = $user('CapNoAdmin'); $member($capNoAdmin, $siteA); $grantDc($capNoAdmin, $siteA);
$lapsedAdmin = $user('Lapsed');   $member($lapsedAdmin, $siteA, 0, 1); $grantDc($lapsedAdmin, $siteA);
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
$withRequest = $user('WithRequest'); $member($withRequest, $siteA);
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteA}, {$withRequest}, 'r@selftest.invalid', '2027-01-01', 'pending_review')");
$withCancelled = $user('WithCancelled'); $member($withCancelled, $siteA);
$db->query("INSERT INTO `tblErasureRequest` (`siteID`, `userID`, `subjectEmail`, `dueBy`, `status`) VALUES ({$siteA}, {$withCancelled}, 'c@selftest.invalid', '2027-01-01', 'cancelled')");
$withOffboarding = $user('WithOffboarding'); $member($withOffboarding, $siteA);
$db->query("INSERT INTO `tblOffboarding` (`userID`, `offboardedByID`, `effectiveDate`) VALUES ({$withOffboarding}, {$adminA}, '2026-01-01')");

$ask = static function (int $actor, bool $global, bool $root, int $k, int $d) use ($db, $siteA): string {
    return M::whyNotAllowed(M::factsFor($db, $actor, $global, $root, $siteA, false, $k, $d));
};
$realCases = [
    ['C1 an administrator of A with the permission, two members of A', [$adminA, false, false, $kept, $dup], ''],
    ['C2 an administrator of A WITHOUT the permission', [$adminNoCap, false, false, $kept, $dup], M::R_NO_PERMISSION],
    ['C3 the permission WITHOUT being an administrator', [$capNoAdmin, false, false, $kept, $dup], M::R_NO_PERMISSION],
    ['C4 an administrator whose membership is switched off', [$lapsedAdmin, false, false, $kept, $dup], M::R_NO_PERMISSION],
    ['C5 THE HOSTING PROVIDER: a global administrator with no role here is refused', [$rootNoRole, true, true, $kept, $dup], M::R_NO_PERMISSION],
    ['C6 a global administrator given the role here may', [$rootA, true, true, $kept, $dup], ''],
    ['C7 an account only in organisation B looks missing to A\'s administrator', [$adminA, false, false, $kept, $bOnly], M::R_NOT_FOUND],
    ['C8 an account in A and B needs a global administrator', [$adminA, false, false, $kept, $dupAB], M::R_NEEDS_GLOBAL],
    ['C9 a global administrator with the role in A only cannot merge an account also in B', [$rootA, true, true, $kept, $dupAB], M::R_NO_PERMISSION],
    ['C10 ...and with the role in both organisations can', [$rootAB, true, true, $kept, $dupAB], ''],
    ['C11 an erased account', [$rootA, true, true, $kept, $erased], M::R_ERASED],
    ['C12 a closed account', [$adminA, false, false, $closed, $dup], M::R_CLOSED],
    ['C13 an organisation administrator\'s account', [$adminA, false, false, $kept, $siteAdminT], M::R_ADMINISTRATOR],
    ['C14 a portal-wide administrator\'s account, asked by a global administrator', [$rootA, true, true, $kept, $portalAdminT], M::R_ADMINISTRATOR],
    ['C15 ...and asked by an organisation administrator (AccountGuard protects it first)', [$adminA, false, false, $kept, $portalAdminT], M::R_NEEDS_GLOBAL],
    ['C16 an open erasure request', [$adminA, false, false, $kept, $withRequest], M::R_REFUSE_IF_ANY],
    ['C17 a CANCELLED erasure request is over, and refuses nothing', [$adminA, false, false, $kept, $withCancelled], ''],
    ['C18 an offboarding record', [$adminA, false, false, $withOffboarding, $dup], M::R_REFUSE_IF_ANY],
    ['C19 the actor\'s own account', [$rootA, true, true, $rootA, $dup], M::R_IS_YOU],
    ['C20 an actor who is neither an administrator nor anybody', [0, false, false, $kept, $dup], M::R_NO_PERMISSION],
];
foreach ($realCases as [$label, $args, $want]) {
    $got = $ask(...$args);
    check($label, $got === $want, "got '{$got}', wanted '{$want}'");
}
// C19: "is your own account" is asked BEFORE "is an administrator's", so a
// global administrator naming their own account is told it is theirs. (The
// first version of this check expected the opposite order and was wrong.)

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
$family = 1000;
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
$insert = static function (string $table, array $fixed) use ($db, &$columnsOf, &$family, $value, $listed, $third): ?array {
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
            $row[$name] = $third;
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
        default                            => [$c => $who],
    };
};
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$madeRows = [];
$couldNotMake = [];
foreach (array_keys($listed) as $name) {
    [$t] = explode('.', $name);
    $row = $insert($t, $fixedFor($name, $pd));
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
check('D2 THE CORE: every listed column that names the duplicate is counted, exactly once', $wrongCount === [], implode(', ', $wrongCount));
check('D3 ...under the treatment the list gives it',
    array_filter($preview['lines'], static fn (array $l): bool => $l['treatment'] !== $listed[$l['table'] . '.' . $l['column']]['treatment']) === []);
check('D4 with no clashes, nothing refuses', $preview['refusal'] === '' && array_sum(array_column($preview['lines'], 'clashes')) === 0, $preview['refusal']);
$notCounted = [];
foreach ($accountColumns as $name) {
    if (isset(M::NOT_AN_ACCOUNT[$name]) === true) {
        continue;
    }
    [$t, $c] = explode('.', $name);
    $n = (int) $db->query("SELECT COUNT(*) AS n FROM `{$t}` WHERE `{$c}` = {$pd}")->fetch_assoc()['n'];
    if ($n !== ($byName[$name]['rows'] ?? 0)) {
        $notCounted[] = "{$name}: {$n} in the table, " . ($byName[$name]['rows'] ?? 0) . ' in the preview';
    }
}
check('D5 NOTHING ESCAPES: every row naming the duplicate in any account column of the database is in the preview', $notCounted === [], implode('; ', $notCounted));

// Now a clash for every column a unique key covers: the kept account holds the
// same one-per-person row.
$clashMade = [];
foreach ($madeRows as $name => $row) {
    [$t, $c] = explode('.', $name);
    if (isset($keys[$t][$c]) === false || in_array($listed[$name]['treatment'], [M::KEEP_KEPT_ON_CLASH, M::REFUSE_ON_CLASH], true) === false) {
        continue;
    }
    $fixed = $fixedFor($name, $pk);
    foreach ($keys[$t][$c] as $others) {
        foreach ($others as $o) {
            $fixed[$o] = $row[$o];
        }
    }
    if ($insert($t, $fixed) !== null) {
        $clashMade[] = $name;
    } else {
        check("D6 a clashing row could be made for {$name}", false);
    }
}
$preview2 = M::preview($db, $pk, $pd);
$clashes  = [];
foreach ($preview2['lines'] as $line) {
    $clashes[$line['table'] . '.' . $line['column']] = $line['clashes'];
}
$missedClash = array_values(array_filter($clashMade, static fn (string $n): bool => ($clashes[$n] ?? 0) !== 1));
check('D7 every clash is found, once, for each column a unique key covers (' . count($clashMade) . ' columns)', $clashMade !== [] && $missedClash === [], implode(', ', $missedClash));
check('D8 the duplicate\'s own rows are still counted once (a clash row names the KEPT account)',
    array_filter($preview2['lines'], static fn (array $l): bool => $l['rows'] !== 1) === []);
check('D9 a clash on a money record refuses the merge', $preview2['refusal'] === M::R_CLASH, $preview2['refusal']);
// Take the money clashes away: the rest are clashes a merge resolves, and refuse nothing.
foreach (M::BY_TREATMENT[M::REFUSE_ON_CLASH] as $name) {
    [$t, $c] = explode('.', $name);
    $db->query("DELETE FROM `{$t}` WHERE `{$c}` = {$pk}");
}
$preview3 = M::preview($db, $pk, $pd);
check('D10 clashes the merge can resolve (memberships, answers) refuse nothing', $preview3['refusal'] === '', $preview3['refusal']);
// A unique key does not bind a row with an empty part (#487), so neither may the count.
$db->query("DELETE FROM `tblEventRSVPs` WHERE `userID` IN ({$pk}, {$pd})");
$db->query("INSERT INTO `tblEventRSVPs` (`eventID`, `userID`, `response`) VALUES (7001, {$pd}, 'going'), (7001, {$pk}, 'going'), (7002, {$pd}, 'going'), (7003, {$pk}, 'going')");
$rsvp = array_values(array_filter(M::preview($db, $pk, $pd)['lines'], static fn (array $l): bool => $l['table'] === 'tblEventRSVPs'));
check('D11 a clash is counted only where the other key columns really match', ($rsvp[0]['rows'] ?? 0) === 2 && ($rsvp[0]['clashes'] ?? 0) === 1, json_encode($rsvp));
$db->query('SET FOREIGN_KEY_CHECKS = 1');

// =============================================================================
echo "E. The page over HTTP\n";
// =============================================================================
$siteOne = (int) ($db->query('SELECT MIN(`siteID`) AS s FROM `tblSites`')->fetch_assoc()['s']);
$pass = 'Am' . bin2hex(random_bytes(8)) . '!9x';
$signer = static function (string $name, int $siteAdmin, bool $withRole) use ($db, $user, $member, $grantDc, $siteOne, $pass, $tag): string {
    $id = $user($name);
    $member($id, $siteOne, 1, $siteAdmin);
    if ($withRole === true) {
        $grantDc($id, $siteOne);
    }
    $login = 'am-' . strtolower($name) . '-' . $tag;
    $hash  = password_hash($pass, PASSWORD_DEFAULT);
    $stmt  = $db->prepare('INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)');
    $stmt->bind_param('iss', $id, $login, $hash);
    $stmt->execute();

    return $login;
};
$loginDc    = $signer('PageDc', 1, true);
$loginNoCap = $signer('PageNoCap', 1, false);
$pageKept = $user('PageKept'); $member($pageKept, $siteOne);
$pageDup  = $user('PageDup', ['fullName' => 'Hidden Dup Name ' . $tag]); $member($pageDup, $siteOne);
$db->query("INSERT INTO `tblPrayerRequests` (`siteID`, `submitterID`, `subject`, `body`) VALUES ({$siteOne}, {$pageDup}, 'Secret subject {$tag}', 'Secret body {$tag}')");
$pageAdminT = $user('PageAdminT', ['fullName' => 'Admin Target Name ' . $tag]); $member($pageAdminT, $siteOne, 1, 1);

$child = sys_get_temp_dir() . $sep . 'account-merge-' . bin2hex(random_bytes(6));
$state = ['proc' => null, 'child' => $child];
register_shutdown_function(static function () use (&$state): void {
    if (is_resource($state['proc']) === true) {
        proc_terminate($state['proc']);
        proc_close($state['proc']);
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
// The router imitates web/public_html/.htaccess, as the other page tests do.
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
$jar = $child . $sep . 'cookies.txt';
$http = static function (string $method, string $path, array $post = []) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($h);

    return ['status' => (int) curl_getinfo($h, CURLINFO_HTTP_CODE), 'body' => (string) $body];
};
for ($i = 0; $i < 80 && $http('GET', '/login')['status'] === 0; $i++) {
    usleep(250000);
}
$signIn = static function (string $login) use ($http, $jar, $pass): bool {
    @unlink($jar);
    $page = $http('GET', '/login');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m) !== 1) {
        return false;
    }
    $in = $http('POST', '/login', ['csrf_token' => $m[1], 'username' => $login, 'password' => $pass]);

    return $in['status'] === 302 || $in['status'] === 303;
};
$mergeUrl = static fn (int $k, int $d): string => '/admin/users/merge?keep=' . $k . '&duplicate=' . $d;

check('E1 the administrator without the permission signs in', $signIn($loginNoCap));
check('E2 ...and is refused the page (403)', $http('GET', '/admin/users/merge')['status'] === 403);
$users = $http('GET', '/admin/users');
check('E3 ...and the Users page offers no link to it', $users['status'] === 200 && str_contains($users['body'], 'admin/users/merge') === false);

check('E4 the administrator with the permission signs in', $signIn($loginDc));
$users = $http('GET', '/admin/users');
check('E5 the Users page offers the link', str_contains($users['body'], '/admin/users/merge'));
$empty = $http('GET', '/admin/users/merge');
check('E6 the page opens and finishes drawing', $empty['status'] === 200 && str_contains($empty['body'], '</html>'));
$page = $http('GET', $mergeUrl($pageKept, $pageDup));
check('E7 a preview draws, to the end', $page['status'] === 200 && str_contains($page['body'], '</html>'));
check('E8 ...it counts the prayer request under "move"', preg_match('#data-merge-treatment="move".*?tblPrayerRequests\.submitterID</code></div>\s*<div[^>]*>1</div>#s', $page['body']) === 1);
check('E9 ...and NEVER shows what a record says', str_contains($page['body'], 'Secret subject ' . $tag) === false && str_contains($page['body'], 'Secret body ' . $tag) === false);
check('E10 ...it names both accounts so the right two are merged', str_contains($page['body'], 'Hidden Dup Name ' . $tag));
check('E11 ...and says the merge itself is not available yet', str_contains($page['body'], 'The merge itself is not available yet'));
check('E12 ...and offers no button that would change anything', preg_match('/<form[^>]*method="post"/i', $page['body']) === 0 || str_contains($page['body'], 'admin/users/merge/') === false);
$refused = $http('GET', $mergeUrl($pageKept, $pageAdminT));
check('E13 a refused preview shows the refusal', str_contains($refused['body'], 'data-merge-refusal="' . M::R_ADMINISTRATOR . '"'));
check('E14 ...and shows NO name of the refused account', str_contains($refused['body'], 'Admin Target Name ' . $tag) === false);
$odd = $http('GET', '/admin/users/merge?keep[]=1&duplicate=abc');
check('E15 a nonsense address draws the empty form, not an error', $odd['status'] === 200 && str_contains($odd['body'], '</html>') && str_contains($odd['body'], 'data-merge-refusal') === false);
$log = q($db, "SELECT `activityType` AS t, `activityDescription` AS d FROM `tblActivityLogs` WHERE `activityType` LIKE 'AccountMerge%' ORDER BY `logID`");
check('E16 every look and every refusal is recorded', count($log) >= 2, (string) count($log));
$named = array_filter($log, static fn (array $r): bool => preg_match('/^(Previewed merging account \d+ into account \d+( \(refused: [a-z_]+\))?|Merging account \d+ into account \d+ was refused: [a-z_]+)$/', (string) $r['d']) !== 1);
check('E17 ...in numbers and fixed words only (no name, no e-mail address)', $named === [], json_encode(array_values($named)));

echo "\n" . $GLOBALS['am_pass'] . ' passed, ' . $GLOBALS['am_fail'] . " failed\n";
exit($GLOBALS['am_fail'] === 0 ? 0 : 1);
