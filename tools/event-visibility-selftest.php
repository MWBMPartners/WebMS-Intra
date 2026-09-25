<?php
// Path: tools/event-visibility-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Event visibility self-test, against a real database 👁️🧪 (#514, part P1)
 * -----------------------------------------------------------------------------
 * Proves that `Portal\Core\EventVisibility` — the one written-down rule for
 * who may see an event — gives the right answer for every kind of viewer,
 * in every mode, on a real MySQL database built the installer's way.
 *
 * WHY IT NEEDS A REAL DATABASE
 * ----------------------------
 * The rule is SQL. A rule that reads well but names a column wrongly, or
 * binds its values in the wrong order, only shows itself when a database
 * runs it. So this file inserts a small made-up world (two organisations;
 * the #514 plan's own fixture for part P1 of seventeen viewers, five outside
 * calendars and fifteen events; plus the extra cases below), asks the rule
 * who sees what, and compares with the answers written down in the plan.
 * Every mode's SQL is also prepared against the real tables, which is what
 * checks the rule's own column names (`check_sql_columns.py` cannot read
 * SQL built in pieces).
 *
 * THE EXTRA CASES (viewers V30-V39, events E30-E45, calendar FB2)
 * ---------------------------------------------------------------
 * P1's independent check (21 September 2026) planted nine realistic faults
 * in the rule, one at a time. With only the plan's fixture, this test
 * caught one of them. The other eight passed silently, because no viewer or
 * event in the plan's fixture tells the right answer from the wrong one:
 *   1. a calendar of ANOTHER organisation counting (xf.siteID)      — E31
 *   2. a list row of another organisation counting (am.siteID)      — V38, E35
 *   3. a switched-off leadership role counting (lr.isActive)        — V34, E33
 *   4. a switched-off leadership assignment counting (la.isActive)  — V35
 *   5. a term that starts in the future counting (la.startDate)     — V36
 *   6. isSiteRootAdmin not counting as a site administrator         — V31
 *   7. a site administrator whose ACCOUNT is off counting (vb.isActive) — V33
 *   8. a private-marked event chosen at full detail shown at basic  — E36
 *   9. the website box not tested (already caught by the plan's cases)
 * The extra cases are modelled on the checker's own differential test
 * (viewers V30-V39, events E30-E43). On 21 September 2026 each of the nine
 * faults was planted, one at a time, in a scratch copy of
 * web/_core/EventVisibility.php, and each made this test fail (exit 1).
 * That proves these nine faults, as planted, are caught. It cannot promise
 * that every other way of getting the rule wrong is caught too. E44-E48
 * and calendar F5 cover API keys: exactly what a signed-out visitor sees,
 * minus anything opted out of the API (owner, 24 September 2026; until then
 * a key needed the website box and got full detail only on a Public
 * calendar — owner answer 3 of 17 September, replaced).
 *
 * SAFETY
 * ------
 * It writes to the database it is given, so it REFUSES to run unless the
 * database's name starts with `selftest_`. Every row it makes has a number
 * of 900000 or more, and it removes them again at the end (unless told to
 * keep them). It never reads or writes anything under web/_auth_keys: the
 * connection comes only from these variables:
 *
 *   SELFTEST_DB_HOST (default 127.0.0.1)   SELFTEST_DB_PORT (default 3306)
 *   SELFTEST_DB_USER (default root)        SELFTEST_DB_PASS (default empty)
 *   SELFTEST_DB_NAME (required; must start with selftest_)
 *
 * It exits 1 (a failure, not a pass) when it refuses, because a test that
 * did not run has proved nothing.
 *
 * THE COLLATION CHECK
 * -------------------
 * It prints the database's default collation and the collation of
 * `tblExternalAudienceMembers`, and refuses to print its final PASS unless
 * that table is `utf8mb4_general_ci`. On a database created through a
 * hosting panel (default `utf8mb4_0900_ai_ci`), a table that lost its
 * COLLATE clause makes the rule fail with "Illegal mix of collations".
 * The same happens on a `general_ci` database (the installer's and the
 * test harness's) when only the COLLATE clause is lost: a table that
 * still names `DEFAULT CHARSET=utf8mb4` then takes that character set's
 * own default, `utf8mb4_0900_ai_ci` (measured on MySQL 8.0.36, 21
 * September 2026) — and this check names the cause instead of leaving a
 * bare error. WHAT IT CANNOT CATCH: a table that lost BOTH its character
 * set and its collation takes the database's default, which on a
 * `general_ci` database is the right answer there, so the check passes;
 * only a panel-collation database (as the #514 proofs use) exposes that.
 *
 * WHAT THIS CANNOT PROVE
 * ----------------------
 * That the portal's pages use the rule — nothing calls it until part P2 of
 * #514, whose own proofs cover the pages. That it works on MariaDB (not
 * tested). Anything about real Google or Microsoft 365 calendars.
 *
 * Usage:
 *   SELFTEST_DB_NAME=selftest_p514 php tools/event-visibility-selftest.php
 *   ... --keep      leave the fixture in place afterwards (for part P2's page tests)
 *   ... --cleanup   only remove a fixture left by --keep, then stop
 * Exit:   0 when every check passes, 1 otherwise — including when the
 *         database cannot be reached, a table is missing, or the fixture
 *         cannot be removed afterwards (each prints a FAIL line).
 *
 * The database this is given must already hold the portal's tables: load
 * web/_sql/full_schema.sql into it first (nothing else is needed — measured
 * 25 September 2026). .github/workflows/calendar-selftests.yml does exactly
 * that and runs this test on every pull request.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside the checks below would otherwise end PHP with
//    exit code 255 and a bare stack trace: a database that cannot be
//    reached, a missing table, or the rule file itself failing to load. The
//    header promises 0 or 1, so each becomes a plain FAIL line and exit 1.
//    This is registered BEFORE the rule file is loaded, because a syntax
//    error in EventVisibility.php is thrown by the require below (a
//    ParseError). The first version registered it afterwards, so that case
//    still ended with 255 (found by P1's second check, 21 September 2026).
//    What this cannot catch: a syntax error in THIS file, or running out of
//    memory; PHP stops before any handler can run. The clean-up at the end
//    catches its own errors for the same reason (see there).
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'EventVisibility.php';

use Portal\Core\EventVisibility;

// =============================================================================
// 🛑 Refuse anything but a throwaway database
// =============================================================================

$keep    = in_array('--keep', $argv, true);
$cleanup = in_array('--cleanup', $argv, true);

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows, so it only ever runs on a throwaway database. Nothing was checked.\n";
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$db->set_charset('utf8mb4');

// =============================================================================
// 🛠️ Small helpers
// =============================================================================

$failures = 0;
$checks   = 0;

/** Record one check. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok === true) {
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $failures++;
    echo 'FAIL — ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
}

/**
 * Run one statement with bound values; return every row for a SELECT.
 *
 * @param list<int|string> $params
 *
 * @return list<array<string, mixed>>
 */
function run(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** @param list<string> $set */
function setText(array $set): string
{
    return '{' . implode(',', $set) . '}';
}

// =============================================================================
// 🗺️ The fixture's numbers (all 900000 or more)
// =============================================================================

const ORG_A = 900001;
const ORG_B = 900002;

// Viewers. V0 is nobody; V17-V20 are kept free for part P10; V30-V39 are
// the extra cases (see the header).
$viewers = ['V0' => 0];
for ($i = 1; $i <= 16; $i++) {
    $viewers['V' . $i] = 900100 + $i;
}
for ($i = 30; $i <= 39; $i++) {
    $viewers['V' . $i] = 900100 + $i;
}

const F1 = 900201; // A, active, public
const F2 = 900202; // A, active, members
const F3 = 900203; // A, active, groups, list {G1, G2, V15, V16}
const F4 = 900204; // A, switched off, public
const FB = 900205; // B, active, members
const FB2 = 900206; // B, active, public (extra: only E31, an event of A, points at it)
const F5 = 900207;  // A, active, public, "Don't show via API" ticked (#514 part P7: only E48 points at it)

const G1 = 900401; // small group of A, active
const G2 = 900402; // small group of A, switched off
const G3 = 900403; // small group of B, active (extra: put on A's F3 list, must match nobody)
const L1 = 900451; // leadership role "Elder" of A, active
const L2 = 900452; // leadership role of A, switched off (extra)
const CHOICE_E10 = 900500; // the single-date choice owning E10's list {L1}
const CHOICE_E35 = 900502; // extra: owns E35's list (a row of B naming V38; role, user group, department rows)
const RULE_E33 = 900610; // extra: owns E33's list {L1, L2}

// The plan's fifteen events are numbered 900301-900321 and the extra cases
// 900330-900345, so "the plan's events only" is simply eventID <= 900329.
$events = [
    'E1' => 900301, 'E2' => 900302, 'E3' => 900303, 'E4' => 900304, 'E5' => 900305,
    'E6' => 900306, 'E7' => 900307, 'E8' => 900308, 'E9' => 900309, 'E10' => 900310,
    'E11' => 900311, 'E12' => 900312, 'E13' => 900313, 'E20' => 900320, 'E21' => 900321,
    'E30' => 900330, 'E31' => 900331, 'E32' => 900332, 'E33' => 900333, 'E35' => 900335,
    'E36' => 900336, 'E37' => 900337, 'E38' => 900338, 'E40' => 900340, 'E41' => 900341,
    'E42' => 900342, 'E43' => 900343, 'E44' => 900344, 'E45' => 900345,
    'E46' => 900346, 'E47' => 900347, 'E48' => 900348,
];
const PLAN_LAST_EVENT = 900329;
$eventName = array_flip($events);

$today     = date('Y-m-d');
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$tomorrow  = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');

/** Remove every fixture row, children first. Safe to run when there is none. */
function removeFixture(mysqli $db): void
{
    run($db, 'DELETE FROM tblExternalAudienceMembers WHERE feedID BETWEEN 900000 AND 999999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    // tblEventRSVPs has no foreign key to tblEvents (#514 plan 0.1), so rows a
    // page test added for these events would otherwise be left behind.
    run($db, 'DELETE FROM tblEventRSVPs WHERE eventID BETWEEN 900300 AND 900399');
    run($db, 'DELETE FROM tblEvents WHERE eventID BETWEEN 900300 AND 900399 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblExternalFeeds WHERE feedID BETWEEN 900000 AND 999999 OR siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblLeadershipAssignments WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblLeadershipRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblSmallGroupMembers WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblSmallGroups WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblUserSites WHERE siteID IN (?, ?) OR userID BETWEEN 900100 AND 900199', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblUsers WHERE userID BETWEEN 900100 AND 900199');
    run($db, 'DELETE FROM tblSites WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
}

// =============================================================================
// 🔤 Collation first (printed whatever else happens)
// =============================================================================

$dbCollation = (string) (run($db, 'SELECT DEFAULT_COLLATION_NAME AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')[0]['c'] ?? '');
$tableRows = run($db, "SELECT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalAudienceMembers'");
$tableCollation = (string) ($tableRows[0]['c'] ?? '(table missing)');
echo "Database default collation:              {$dbCollation}\n";
echo "tblExternalAudienceMembers collation:    {$tableCollation}\n\n";
$collationOk = ($tableCollation === 'utf8mb4_general_ci');

if ($cleanup === true) {
    try {
        removeFixture($db);
    } catch (Throwable $e) {
        echo 'FAIL — the fixture could not be removed: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(1);
    }
    echo "Fixture removed (--cleanup). Nothing else was checked.\n";
    exit(0);
}

try {
    // =========================================================================
    // 🏗️ The fixture (#514 plan, part P1)
    // =========================================================================
    removeFixture($db); // a leftover from an earlier --keep run

    run($db, "INSERT INTO tblSites (siteID, siteName, siteKey) VALUES (?, 'Selftest organisation A', 'selftest-p514-a'), (?, 'Selftest organisation B', 'selftest-p514-b')", 'ii', [ORG_A, ORG_B]);

    // Accounts: [isActive, isAdmin (old portal-wide flag), isRootAdmin]
    $accounts = [
        'V1' => [1, 0, 0], 'V2' => [1, 0, 0], 'V3' => [1, 0, 0], 'V4' => [1, 0, 0],
        'V5' => [0, 0, 0], 'V6' => [1, 0, 0], 'V7' => [1, 0, 0], 'V8' => [1, 0, 1],
        'V9' => [1, 1, 0], 'V10' => [1, 0, 0], 'V11' => [1, 0, 0], 'V12' => [1, 0, 0],
        'V13' => [1, 0, 0], 'V14' => [1, 0, 0], 'V15' => [1, 0, 0], 'V16' => [1, 0, 0],
        // extra cases
        'V30' => [1, 0, 0], 'V31' => [1, 0, 0], 'V32' => [0, 0, 1], 'V33' => [0, 0, 0], 'V34' => [1, 0, 0],
        'V35' => [1, 0, 0], 'V36' => [1, 0, 0], 'V37' => [1, 0, 0], 'V38' => [1, 0, 0], 'V39' => [1, 1, 0],
    ];
    foreach ($accounts as $name => [$active, $oldAdmin, $root]) {
        run(
            $db,
            'INSERT INTO tblUsers (userID, fullName, emailAddress, isActive, isAdmin, isRootAdmin) VALUES (?, ?, ?, ?, ?, ?)',
            'issiii',
            [$viewers[$name], 'Selftest ' . $name, strtolower($name) . '.p514@selftest.invalid', $active, $oldAdmin, $root]
        );
    }

    // Memberships: [viewer, organisation, isActive, isSiteAdmin, isSiteRootAdmin]
    $memberships = [
        ['V1', ORG_A, 1, 0, 0], ['V2', ORG_B, 1, 0, 0], ['V4', ORG_A, 0, 0, 0], ['V5', ORG_A, 1, 0, 0],
        ['V6', ORG_A, 1, 1, 0], ['V7', ORG_B, 1, 1, 0], ['V9', ORG_A, 1, 0, 0], ['V10', ORG_A, 1, 0, 0],
        ['V11', ORG_A, 1, 0, 0], ['V12', ORG_A, 1, 0, 0], ['V13', ORG_A, 1, 0, 0], ['V14', ORG_A, 1, 0, 0],
        ['V15', ORG_A, 1, 0, 0], ['V16', ORG_A, 0, 0, 0],
        // extra cases (V32 and V39 have no membership rows at all)
        ['V30', ORG_A, 0, 1, 0], ['V31', ORG_A, 1, 0, 1], ['V33', ORG_A, 1, 1, 0], ['V34', ORG_A, 1, 0, 0],
        ['V35', ORG_A, 1, 0, 0], ['V36', ORG_A, 1, 0, 0], ['V37', ORG_A, 1, 0, 0], ['V37', ORG_B, 1, 0, 0],
        ['V38', ORG_A, 1, 0, 0], ['V38', ORG_B, 1, 0, 0],
    ];
    foreach ($memberships as [$name, $org, $active, $siteAdmin, $siteRoot]) {
        run(
            $db,
            'INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, ?, ?, ?)',
            'iiiii',
            [$viewers[$name], $org, $active, $siteAdmin, $siteRoot]
        );
    }

    run($db, "INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'Selftest G1', 'selftest-p514-g1', 1), (?, ?, 'Selftest G2', 'selftest-p514-g2', 0), (?, ?, 'Selftest G3', 'selftest-p514-g3', 1)", 'iiiiii', [G1, ORG_A, G2, ORG_A, G3, ORG_B]);
    run($db, "INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, status) VALUES (?, ?, ?, 'active'), (?, ?, ?, 'ended'), (?, ?, ?, 'active'), (?, ?, ?, 'active')", 'iiiiiiiiiiii', [
        ORG_A, G1, $viewers['V10'], ORG_A, G1, $viewers['V11'], ORG_A, G2, $viewers['V12'], ORG_B, G3, $viewers['V37'],
    ]);
    run($db, "INSERT INTO tblLeadershipRoles (roleID, siteID, roleName, roleSlug, isActive) VALUES (?, ?, 'Elder', 'selftest-p514-elder', 1), (?, ?, 'Retired role', 'selftest-p514-retired', 0)", 'iiii', [L1, ORG_A, L2, ORG_A]);
    // Assignments: V13 current Elder; V14 Elder until yesterday; extra cases:
    // V34 on the switched-off role L2; V35 an Elder assignment that is itself
    // switched off; V36 an Elder term that starts tomorrow.
    run(
        $db,
        'INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive) VALUES'
        . " (?, ?, ?, '2020-01-01', NULL, 1), (?, ?, ?, '2020-01-01', ?, 1), (?, ?, ?, '2020-01-01', NULL, 1),"
        . " (?, ?, ?, '2020-01-01', NULL, 0), (?, ?, ?, ?, NULL, 1)",
        'iiiiiisiiiiiiiiis',
        [
            ORG_A, L1, $viewers['V13'], ORG_A, L1, $viewers['V14'], $yesterday, ORG_A, L2, $viewers['V34'],
            ORG_A, L1, $viewers['V35'], ORG_A, L1, $viewers['V36'], $tomorrow,
        ]
    );

    // Calendars: [feedID, organisation, isActive, audienceLevel, apiOptOut]
    // `apiOptOut` is named on every row (#514 part P7): 0, "send to API keys",
    // everywhere except F5, whose box is ticked.
    $calendars = [[F1, ORG_A, 1, 'public', 0], [F2, ORG_A, 1, 'members', 0], [F3, ORG_A, 1, 'groups', 0], [F4, ORG_A, 0, 'public', 0],
        [FB, ORG_B, 1, 'members', 0], [FB2, ORG_B, 1, 'public', 0], [F5, ORG_A, 1, 'public', 1]];
    foreach ($calendars as [$feed, $org, $active, $level, $feedApi]) {
        run(
            $db,
            'INSERT INTO tblExternalFeeds (feedID, siteID, name, url, isActive, audienceLevel, apiOptOut) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'iissisi',
            [$feed, $org, 'Selftest calendar ' . $feed, 'https://example.invalid/' . $feed . '.ics', $active, $level, $feedApi]
        );
    }

    // Lists: F3's own {G1, G2, person V15, person V16}; choice 900500's {L1}.
    // Extra cases: G3 (a group of B) on F3's list, which must match nobody;
    // rule 900610's {L1, L2 switched off}; choice 900502's rows — one naming
    // V38 but carrying organisation B, and one each of the three kinds part
    // P10 switches on (all of which must match nobody until then).
    // [organisation, calendar, ownerType, ownerID, kind, refID, userID]
    $audience = [
        [ORG_A, F3, 'feed', F3, 'small_group', G1, null], [ORG_A, F3, 'feed', F3, 'small_group', G2, null],
        [ORG_A, F3, 'feed', F3, 'person', $viewers['V15'], $viewers['V15']], [ORG_A, F3, 'feed', F3, 'person', $viewers['V16'], $viewers['V16']],
        [ORG_A, F2, 'choice', CHOICE_E10, 'leadership_role', L1, null],
        [ORG_A, F3, 'feed', F3, 'small_group', G3, null],
        [ORG_A, F2, 'rule', RULE_E33, 'leadership_role', L1, null], [ORG_A, F2, 'rule', RULE_E33, 'leadership_role', L2, null],
        [ORG_B, F2, 'choice', CHOICE_E35, 'person', $viewers['V38'], $viewers['V38']],
        [ORG_A, F2, 'choice', CHOICE_E35, 'role', 1, null], [ORG_A, F2, 'choice', CHOICE_E35, 'user_group', 1, null],
        [ORG_A, F2, 'choice', CHOICE_E35, 'department', 1, null],
    ];
    foreach ($audience as [$org, $feed, $ownerType, $ownerId, $kind, $refId, $userId]) {
        run(
            $db,
            'INSERT INTO tblExternalAudienceMembers (siteID, feedID, ownerType, ownerID, kind, refID, userID) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'iisisii',
            [$org, $feed, $ownerType, $ownerId, $kind, $refId, $userId]
        );
    }

    // Events: [name, organisation, calendar or null, isPublic, level, detail, website, audienceType, audienceID, source, private, recheck]
    // recheck: 0 = none, -1 = ran out an hour ago, 1 = runs out in an hour.
    $start = (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d') . ' 10:00:00';
    $end   = (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d') . ' 11:00:00';
    $eventRows = [
        ['E1',  ORG_A, null,   1, 'hidden',  'basic', 0, null,     null,       null,       0, 0],
        ['E2',  ORG_A, null,   0, 'hidden',  'basic', 0, null,     null,       null,       0, 0],
        ['E3',  ORG_A, F1,     0, 'public',  'full',  0, 'feed',   F1,         'calendar', 0, 0],  // isPublic 0: what the importer writes (B5 vii)
        ['E4',  ORG_A, F2,     1, 'members', 'full',  0, 'feed',   F2,         'calendar', 0, 0],
        ['E5',  ORG_A, F3,     1, 'groups',  'full',  0, 'feed',   F3,         'calendar', 0, 0],
        ['E6',  ORG_A, F2,     1, 'public',  'basic', 0, 'rule',   900600,     'rule',     0, 0],
        ['E7',  ORG_A, F2,     1, 'hidden',  'full',  0, null,     null,       'rule',     0, 0],
        ['E8',  ORG_A, F4,     1, 'public',  'full',  0, 'feed',   F4,         'calendar', 0, 0],
        ['E9',  ORG_A, F1,     1, 'public',  'full',  0, 'feed',   F1,         'calendar', 0, -1],
        ['E10', ORG_A, F2,     1, 'groups',  'basic', 0, 'choice', CHOICE_E10, 'date',     0, 0],
        ['E11', ORG_A, 999999, 1, 'public',  'full',  0, 'feed',   999999,     'calendar', 0, 0],
        ['E12', ORG_A, F1,     1, 'public',  'full',  1, 'feed',   F1,         'calendar', 0, 0],
        ['E13', ORG_A, F1,     1, 'public',  'basic', 0, 'choice', 900501,     'date',     1, 0],
        ['E20', ORG_B, null,   0, 'hidden',  'basic', 0, null,     null,       null,       0, 0],
        ['E21', ORG_B, FB,     1, 'members', 'full',  0, 'feed',   FB,         'calendar', 0, 0],
        // extra cases (see the header)
        ['E30', ORG_A, F3,     1, 'groups',  'full',  0, 'feed',   F2,         'calendar', 0, 0],  // audience points at F2's list, which is empty
        ['E31', ORG_A, FB2,    1, 'public',  'full',  1, 'feed',   FB2,        'calendar', 0, 0],  // A's event on B's public calendar
        ['E32', ORG_A, F1,     1, 'public',  'full',  1, 'feed',   F1,         'calendar', 0, 1],  // stored answer runs out in an hour: still trusted
        ['E33', ORG_A, F2,     1, 'groups',  'full',  0, 'rule',   RULE_E33,   'rule',     0, 0],  // list {L1, L2 switched off}
        ['E35', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E35, 'date',     0, 0],  // list: V38 on a row of B; P10's kinds
        ['E36', ORG_A, F1,     1, 'public',  'full',  1, 'choice', 900503,     'date',     1, 0],  // private-marked, chosen public + website at FULL detail
        ['E37', ORG_A, F3,     1, 'public',  'basic', 0, 'rule',   900611,     'rule',     0, 0],  // public at basic, on a groups calendar
        ['E38', ORG_A, F2,     1, 'public',  'basic', 0, 'rule',   900612,     'rule',     1, 0],  // private-marked, public at basic, members calendar
        ['E40', ORG_A, F1,     1, 'public',  'full',  1, 'feed',   F1,         'calendar', 0, -1], // website ticked, but the stored answer ran out
        ['E41', ORG_A, F1,     1, 'members', 'full',  1, 'feed',   F1,         'calendar', 0, 0],  // website ticked, but members level
        ['E42', ORG_A, F3,     1, 'hidden',  'full',  0, null,     null,       'private',  1, 0],  // hidden on a groups calendar
        ['E43', ORG_B, FB,     1, 'public',  'basic', 0, 'rule',   900613,     'rule',     0, 0],  // B, public at basic, members calendar
        ['E44', ORG_A, F2,     1, 'public',  'full',  1, 'choice', 900504,     'date',     0, 0],  // key corner: Members calendar, public + website, full
        ['E45', ORG_A, F1,     1, 'public',  'full',  1, 'choice', 900505,     'date',     0, 0],  // E44's twin on a Public calendar
        // #514 part P7: the "Don't show via API" answer (see $apiOptOut below)
        ['E46', ORG_A, F1,     0, 'public',  'full',  0, 'feed',   F1,         'calendar', 0, 0],  // public, full, opted out of the API
        ['E47', ORG_A, F1,     0, 'public',  'full',  1, 'feed',   F1,         'calendar', 0, 0],  // public + website ticked, opted out of the API
        ['E48', ORG_A, F5,     0, 'public',  'full',  0, 'feed',   F5,         'calendar', 0, 0],  // stored answer 0, but its calendar's box is ticked
    ];
    // `importApiOptOut` is named on EVERY fixture event, 0 unless listed here.
    // The column defaults to 1 (the resolver's narrow default, migration 206),
    // so leaving it out would opt every event out of the API and every key
    // check below would fail for the wrong reason.
    $apiOptOut = ['E46' => 1, 'E47' => 1];
    foreach ($eventRows as [$name, $org, $feed, $isPublic, $level, $detail, $website, $audType, $audId, $source, $private, $recheck]) {
        $id = $events[$name];
        run(
            $db,
            'INSERT INTO tblEvents (eventID, siteID, externalFeedID, externalUid, eventName, eventSlug, description, locationName,'
            . ' startDateTime, endDateTime, status, isPublic, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType,'
            . ' importAudienceID, importSource, externalPrivate, importRecheckAt)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?, ?, ?, ?, ?, ?, ?, ?, ?,"
            . ' CASE ? WHEN -1 THEN UTC_TIMESTAMP() - INTERVAL 1 HOUR WHEN 1 THEN UTC_TIMESTAMP() + INTERVAL 1 HOUR ELSE NULL END)',
            'iiisssssssissiisisii',
            [
                $id, $org, $feed, $feed === null ? null : 'selftest-' . strtolower($name) . '@p514.invalid',
                'Selftest ' . $name, 'selftest-p514-' . strtolower($name),
                'Fixture description ' . $name, 'Fixture location ' . $name, $start, $end,
                $isPublic, $level, $detail, $website, $apiOptOut[$name] ?? 0, $audType, $audId, $source, $private, $recheck,
            ]
        );
    }
    echo 'Fixture inserted: 2 organisations, ' . count($accounts) . ' accounts (and V0, nobody), ' . count($calendars)
        . ' calendars, ' . count($eventRows) . " events.\n\n";

    // =========================================================================
    // 🔍 Asking the rule
    // =========================================================================

    /**
     * Which fixture events a viewer sees in a mode. The plan's events only,
     * unless $withExtras asks for the extra cases too.
     *
     * @return list<string>
     */
    $visible = static function (string $mode, int $viewerId, ?int $site = null, bool $withExtras = false) use ($db, $today, $eventName): array {
        $w = EventVisibility::where('e', $mode, $viewerId, $today);
        $sql = 'SELECT e.eventID FROM tblEvents e WHERE e.eventID BETWEEN 900300 AND ' . ($withExtras === true ? 900399 : PLAN_LAST_EVENT)
            . ($site !== null ? ' AND e.siteID = ?' : '') . $w['sql'] . ' ORDER BY e.eventID';
        $types = ($site !== null ? 'i' : '') . $w['types'];
        $params = $site !== null ? array_merge([$site], $w['params']) : $w['params'];
        $out = [];
        foreach (run($db, $sql, $types, $params) as $row) {
            $out[] = $eventName[(int) $row['eventID']];
        }

        return $out;
    };

    /**
     * The same, with the viewer taken from a column of the query (every
     * fixture event, extra cases included).
     *
     * @return list<string>
     */
    $visibleByColumn = static function (string $mode, int $viewerId) use ($db, $today, $eventName): array {
        $w = EventVisibility::whereForColumn('e', 'r.userID', $mode, $today);
        $sql = 'SELECT e.eventID FROM tblEvents e JOIN (SELECT ? AS userID) r'
            . ' WHERE e.eventID BETWEEN 900300 AND 900399' . $w['sql'] . ' ORDER BY e.eventID';
        $out = [];
        foreach (run($db, $sql, 'i' . $w['types'], array_merge([$viewerId], $w['params'])) as $row) {
            $out[] = $eventName[(int) $row['eventID']];
        }

        return $out;
    };

    /** The canSeeFull value for one event and viewer. */
    $canSeeFull = static function (string $mode, int $viewerId, int $eventId) use ($db, $today): int {
        $f = EventVisibility::fullDetailSelect('e', $mode, $viewerId, $today);
        $rows = run($db, 'SELECT e.eventID, ' . $f['sql'] . ' FROM tblEvents e WHERE e.eventID = ?', $f['types'] . 'i', array_merge($f['params'], [$eventId]));

        return (int) ($rows[0]['canSeeFull'] ?? -1);
    };

    // The expected sets, exactly as the #514 plan's P1 proof 1 writes them.
    $v0 = ['E1', 'E3', 'E6', 'E12', 'E13'];
    $v1 = ['E1', 'E2', 'E3', 'E4', 'E6', 'E12', 'E13'];
    $v6 = ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E9', 'E10', 'E12', 'E13'];
    $withB = static fn (array $set): array => array_merge($set, ['E20', 'E21']);
    $plus = static function (array $set, string ...$more) use ($events): array {
        $all = array_merge($set, $more);
        usort($all, static fn (string $a, string $b): int => $events[$a] <=> $events[$b]);

        return $all;
    };
    $expectedSession = [
        'V0' => $v0, 'V1' => $v1, 'V2' => $withB($v0), 'V3' => $v0, 'V4' => $v0, 'V5' => $v0,
        'V6' => $v6, 'V7' => $withB($v0), 'V8' => $withB($v6), 'V9' => $v1, 'V10' => $plus($v1, 'E5'),
        'V11' => $v1, 'V12' => $v1, 'V13' => $plus($v1, 'E10'), 'V14' => $v1, 'V15' => $plus($v1, 'E5'),
        'V16' => $v0,
        // extra cases, on the plan's events
        'V30' => $v0, 'V31' => $v6, 'V32' => $v0, 'V33' => $v0, 'V34' => $v1, 'V35' => $v1, 'V36' => $v1,
        'V37' => $withB($v1), 'V38' => $withB($v1), 'V39' => $v0,
    ];
    $viewerNotes = [
        'V0' => 'nobody', 'V1' => 'member of A', 'V2' => 'member of B only', 'V3' => 'active account, NO membership row',
        'V4' => 'row in A switched off', 'V5' => 'account switched off, row in A active', 'V6' => 'site administrator of A',
        'V7' => 'site administrator of B', 'V8' => 'global administrator, no rows', 'V9' => 'old isAdmin flag + plain member of A',
        'V10' => 'member of A, active in small group G1', 'V11' => 'member of A, G1 membership ended',
        'V12' => 'member of A, in switched-off group G2', 'V13' => 'member of A, current Elder',
        'V14' => 'member of A, Elder term ended yesterday', 'V15' => 'member of A, named on F3',
        'V16' => 'named on F3, row in A switched off',
        'V30' => 'site-administrator flag on A, but the row is switched off', 'V31' => 'isSiteRootAdmin of A (not isSiteAdmin)',
        'V32' => 'global administrator whose account is switched off', 'V33' => 'site administrator of A whose ACCOUNT is switched off',
        'V34' => 'member of A, assigned to the switched-off role L2', 'V35' => 'member of A, Elder assignment switched off',
        'V36' => 'member of A, Elder term starts tomorrow', 'V37' => 'member of A and B, in G3 (a group of B on A\'s F3 list)',
        'V38' => 'member of A and B, named on a list row of B', 'V39' => 'old isAdmin flag only, no membership rows',
    ];

    // -------------------------------------------------------------------------
    echo "=== session mode, several organisations: who sees what ===\n";
    $sessionSeen = [];
    foreach ($viewers as $name => $id) {
        $got = $visible(EventVisibility::MODE_SESSION, $id);
        $sessionSeen[$name] = $got;
        check("{$name} ({$viewerNotes[$name]}) sees " . setText($expectedSession[$name]), $got === $expectedSession[$name], 'got ' . setText($got));
    }

    echo "\n=== E8 (paused calendar), E11 (no calendar row) and E31 (another organisation's calendar) are visible to nobody, in any mode ===\n";
    $leaked = [];
    foreach ([EventVisibility::MODE_SESSION, EventVisibility::MODE_ANONYMOUS, EventVisibility::MODE_WEBSITE, EventVisibility::MODE_TOKEN,
        EventVisibility::MODE_INVITE, EventVisibility::MODE_KEY, EventVisibility::MODE_BULK_MEMBERS] as $mode) {
        foreach ($viewers as $name => $id) {
            foreach (array_intersect($visible($mode, $id, null, true), ['E8', 'E11', 'E31']) as $e) {
                $leaked[] = "{$e} to {$name} in {$mode}";
            }
        }
    }
    check('E8, E11 and E31 appear for no viewer in any of the seven modes', $leaked === [], implode('; ', $leaked));

    // -------------------------------------------------------------------------
    echo "\n=== extra cases: lists, leadership terms and stored answers (session mode) ===\n";
    $extraSeen = [];
    foreach ($viewers as $name => $id) {
        $extraSeen[$name] = $visible(EventVisibility::MODE_SESSION, $id, null, true);
    }
    /** @return list<string> the viewers who see $event, leaving out $skip */
    $seersOf = static function (string $event, array $skip = []) use ($extraSeen): array {
        $who = [];
        foreach ($extraSeen as $name => $set) {
            if (in_array($event, $set, true) === true && in_array($name, $skip, true) === false) {
                $who[] = $name;
            }
        }

        return $who;
    };
    $admins = ['V6', 'V8', 'V31']; // the administrators of A in session mode (V30, V32 and V33 are not)
    $got = $seersOf('E33', $admins);
    check('E33 (list {Elder, switched-off role L2}): among non-administrators only V13 sees it — not V34 (switched-off role), V35 (switched-off assignment) or V36 (term starts tomorrow)', $got === ['V13'], 'got ' . setText($got));
    $got = $seersOf('E35', $admins);
    check('E35 (V38 named only on a list row of organisation B; role, user group and department rows): no non-administrator sees it', $got === [], 'got ' . setText($got));
    $got = $seersOf('E30', $admins);
    check('E30 (its audience points at another owner\'s list): no non-administrator sees it', $got === [], 'got ' . setText($got));
    $got = $seersOf('E42');
    check('E42 (hidden): exactly the administrators V6, V8 and V31', $got === $admins, 'got ' . setText($got));
    check('V37 (in G3, a group of B, which is on A\'s F3 list) does not see E5', in_array('E5', $extraSeen['V37'], true) === false);
    check('E32 (stored answer runs out in an hour): still visible to V0', in_array('E32', $extraSeen['V0'], true) === true);
    $website = $visible(EventVisibility::MODE_WEBSITE, 0, ORG_A, true);
    check('website: E32 yes; E40 (ticked, answer ran out) no; E41 (ticked, members level) no',
        in_array('E32', $website, true) === true && in_array('E40', $website, true) === false && in_array('E41', $website, true) === false,
        'got ' . setText($website));
    $keySet = $visible(EventVisibility::MODE_KEY, 0, ORG_A, true);
    check('key: receives E44, E45, E37 and E38 (public; the website box no longer matters to keys); not E41 (members level)',
        in_array('E44', $keySet, true) === true && in_array('E45', $keySet, true) === true && in_array('E41', $keySet, true) === false
        && in_array('E37', $keySet, true) === true && in_array('E38', $keySet, true) === true,
        'got ' . setText($keySet));

    // -------------------------------------------------------------------------
    echo "\n=== strict membership on every installation (step 7) ===\n";
    check('V3 (active account, no membership row) sees exactly the V0 set', $sessionSeen['V3'] === $v0, 'got ' . setText($sessionSeen['V3']));
    $params = array_map(static fn (ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(EventVisibility::class, 'where'))->getParameters());
    check('where() has exactly four parameters: alias, mode, viewerId, today', $params === ['alias', 'mode', 'viewerId', 'today'], implode(', ', $params));
    $mentions = [];
    foreach ([EventVisibility::MODE_SESSION, EventVisibility::MODE_ANONYMOUS, EventVisibility::MODE_WEBSITE, EventVisibility::MODE_TOKEN,
        EventVisibility::MODE_INVITE, EventVisibility::MODE_KEY, EventVisibility::MODE_BULK_MEMBERS] as $mode) {
        $text = EventVisibility::where('e', $mode, 1, $today)['sql'] . EventVisibility::fullDetailSelect('e', $mode, 1, $today)['sql'];
        if (stripos($text, 'multisite') !== false) {
            $mentions[] = $mode;
        }
    }
    check('no generated SQL, in any mode, contains the text "multisite"', $mentions === [], implode(', ', $mentions));

    // -------------------------------------------------------------------------
    echo "\n=== named people follow their membership (D18; replaces the old proof 5) ===\n";
    $v15 = $viewers['V15'];
    check('V15, row in A present and active, sees the V1 set plus E5', $visible(EventVisibility::MODE_SESSION, $v15) === $plus($v1, 'E5'));
    run($db, 'DELETE FROM tblUserSites WHERE userID = ? AND siteID = ?', 'ii', [$v15, ORG_A]); // what "Remove user from site" does
    $after = $visible(EventVisibility::MODE_SESSION, $v15);
    $stillNamed = (int) run($db, "SELECT COUNT(*) AS n FROM tblExternalAudienceMembers WHERE kind = 'person' AND userID = ?", 'i', [$v15])[0]['n'];
    check('after DELETE of V15\'s row for A, the very next call gives V15 the V0 set', $after === $v0, 'got ' . setText($after));
    check('... although V15\'s audience row on F3 still exists', $stillNamed === 1, "rows naming V15: {$stillNamed}");
    run($db, 'INSERT INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1)', 'ii', [$v15, ORG_A]);
    $restored = $visible(EventVisibility::MODE_SESSION, $v15);
    check('control: re-inserting the row restores the V1 set plus E5', $restored === $plus($v1, 'E5'), 'got ' . setText($restored));

    // -------------------------------------------------------------------------
    echo "\n=== token mode: never administrator powers (leak-hunt finding 24) ===\n";
    $got = $visible(EventVisibility::MODE_TOKEN, $viewers['V6']);
    check('token: V6 (site administrator of A) sees the V1 set', $got === $v1, 'got ' . setText($got));
    $got = $visible(EventVisibility::MODE_TOKEN, $viewers['V8']);
    check('token: V8 (global administrator, no rows) sees the V0 set', $got === $v0, 'got ' . setText($got));
    $mismatch = [];
    foreach ([EventVisibility::MODE_TOKEN, EventVisibility::MODE_SESSION, EventVisibility::MODE_INVITE] as $mode) {
        foreach ($viewers as $name => $id) {
            if ($visibleByColumn($mode, $id) !== $visible($mode, $id, null, true)) {
                $mismatch[] = "{$name} in {$mode}";
            }
        }
    }
    check('the column form (viewer from r.userID) gives the same set as the bound form, for every viewer and every fixture event in token, session and invite modes', $mismatch === [], implode('; ', $mismatch));

    // -------------------------------------------------------------------------
    echo "\n=== the other modes, counting organisation A's events only ===\n";
    $got = $visible(EventVisibility::MODE_KEY, 0, ORG_A);
    // The signed-out visitor's set {E1,E3,E6,E12,E13} plus E2, the
    // organisation's OWN members-only event (keys are not restricted on the
    // organisation's own events, #127 / #511). E3, E6 and E13 are public
    // imported events WITHOUT the website box (owner, 24 September 2026).
    check('key: {E1,E2,E3,E6,E12,E13}', $got === ['E1', 'E2', 'E3', 'E6', 'E12', 'E13'], 'got ' . setText($got));
    $got = $visible(EventVisibility::MODE_WEBSITE, 0, ORG_A);
    check('website: {E1,E12}', $got === ['E1', 'E12'], 'got ' . setText($got));
    $got = $visible(EventVisibility::MODE_INVITE, 0, ORG_A);
    check('invite as V0: {E1,E2,E3,E6,E12,E13}', $got === ['E1', 'E2', 'E3', 'E6', 'E12', 'E13'], 'got ' . setText($got));
    $got = $visible(EventVisibility::MODE_BULK_MEMBERS, 0, ORG_A);
    check('bulkMembers: {E1,E2,E3,E4,E6,E12,E13}', $got === ['E1', 'E2', 'E3', 'E4', 'E6', 'E12', 'E13'], 'got ' . setText($got));
    $got = $visible(EventVisibility::MODE_WEBSITE, $viewers['V6'], ORG_A);
    check('website ignores a viewer passed by mistake: V6 gets the same {E1,E12}', $got === ['E1', 'E12'], 'got ' . setText($got));

    // -------------------------------------------------------------------------
    echo "\n=== canSeeFull (session mode) ===\n";
    foreach ([['E6', 'V0', 0], ['E6', 'V1', 1], ['E6', 'V2', 0], ['E6', 'V6', 1], ['E6', 'V8', 1], ['E6', 'V9', 1],
        ['E13', 'V0', 0], ['E13', 'V1', 0], ['E13', 'V6', 1], ['E10', 'V13', 1]] as [$e, $v, $want]) {
        $got = $canSeeFull(EventVisibility::MODE_SESSION, $viewers[$v], $events[$e]);
        check("{$e} for {$v}: {$want}", $got === $want, "got {$got}");
    }
    $notFull = [];
    foreach ($sessionSeen as $name => $set) {
        foreach (array_intersect($set, ['E3', 'E12']) as $e) {
            if ($canSeeFull(EventVisibility::MODE_SESSION, $viewers[$name], $events[$e]) !== 1) {
                $notFull[] = "{$e} for {$name}";
            }
        }
    }
    check('E3 and E12: 1 for everyone who sees them', $notFull === [], implode('; ', $notFull));
    foreach ([['E36', 'V0', 1], ['E37', 'V10', 1], ['E37', 'V1', 0], ['E37', 'V15', 1], ['E38', 'V1', 0], ['E38', 'V6', 1],
        ['E43', 'V2', 1], ['E43', 'V1', 0], ['E44', 'V1', 1]] as [$e, $v, $want]) {
        $got = $canSeeFull(EventVisibility::MODE_SESSION, $viewers[$v], $events[$e]);
        check("extra: {$e} for {$v}: {$want}", $got === $want, "got {$got}");
    }
    $got = $canSeeFull(EventVisibility::MODE_TOKEN, $viewers['V6'], $events['E38']);
    check('extra, token mode: E38 for V6 (site administrator of A) is 0 — no administrator powers', $got === 0, "got {$got}");

    // -------------------------------------------------------------------------
    echo "\n=== key mode: exactly the detail a signed-out visitor gets (owner, 24 September 2026) ===\n";
    foreach ([
        ['E44', 1, 'Members calendar, public + website, marked full detail'],
        ['E45', 1, 'the same event on a Public calendar'],
        ['E12', 1, 'Public calendar, full detail'],
        ['E6', 0, 'Members calendar, basic detail'],
        ['E13', 0, 'private-marked at basic detail, on a Public calendar (never more than a signed-out visitor)'],
        ['E36', 1, 'private-marked, chosen at full detail, on a Public calendar'],
        ['E31', 0, 'an event of A on organisation B\'s Public calendar'],
        ['E1', 1, 'the organisation\'s own event'],
        ['E3', 1, 'Public calendar, full detail, website box clear'],
        ['E37', 0, 'public at basic detail, on a Selected-groups calendar'],
        ['E38', 0, 'private-marked, public at basic detail'],
        ['E43', 0, 'organisation B, basic detail, Members calendar'],
        ['E46', 0, 'opted out of the API'],
    ] as [$e, $want, $what]) {
        $got = $canSeeFull(EventVisibility::MODE_KEY, 0, $events[$e]);
        check("key: {$e} ({$what}) is {$want}", $got === $want, "got {$got}");
    }
    $wider = [];
    $widerAnon = [];
    foreach ($events as $name => $id) {
        if ($canSeeFull(EventVisibility::MODE_KEY, 0, $id) > $canSeeFull(EventVisibility::MODE_SESSION, 0, $id)) {
            $wider[] = $name;
        }
        if ($canSeeFull(EventVisibility::MODE_KEY, 0, $id) > $canSeeFull(EventVisibility::MODE_ANONYMOUS, 0, $id)) {
            $widerAnon[] = $name;
        }
    }
    check('key: never full detail where a signed-out visitor gets title, date and time only (all ' . count($events) . ' events)', $wider === [], 'wider on ' . setText($wider));
    check('key: never full detail where anonymous mode gives title, date and time only (all ' . count($events) . ' events)', $widerAnon === [], 'wider on ' . setText($widerAnon));

    // -------------------------------------------------------------------------
    echo "\n=== API keys see exactly the signed-out visitor's imported events, minus the opted-out ones (#514 part P7, plan B4/B5) ===\n";
    /** The imported events (with the extra cases) a mode returns, for one organisation. */
    $importedIn = static function (string $mode, int $site) use ($visible, $db): array {
        $out = [];
        foreach ($visible($mode, 0, $site, true) as $name) {
            $row = run($db, 'SELECT externalFeedID FROM tblEvents WHERE eventID = ?', 'i', [$GLOBALS['events'][$name]]);
            if ($row !== [] && $row[0]['externalFeedID'] !== null) {
                $out[] = $name;
            }
        }

        return $out;
    };
    foreach ([ORG_A => 'A', ORG_B => 'B'] as $site => $label) {
        $keyRows  = $importedIn(EventVisibility::MODE_KEY, $site);
        $anonRows = $importedIn(EventVisibility::MODE_ANONYMOUS, $site);
        check("(i) organisation {$label}: every imported event a key receives, a signed-out visitor sees too", array_diff($keyRows, $anonRows) === [],
            'key only: ' . setText(array_values(array_diff($keyRows, $anonRows))));
        check("(ii) organisation {$label}: the key's imported events are exactly the visitor's minus E46, E47 and E48 (the opted-out ones)",
            array_values($keyRows) === array_values(array_diff($anonRows, ['E46', 'E47', 'E48'])),
            'key ' . setText($keyRows) . ' anonymous ' . setText($anonRows));
        $unequal = [];
        foreach ($keyRows as $name) {
            if ($canSeeFull(EventVisibility::MODE_KEY, 0, $events[$name]) !== $canSeeFull(EventVisibility::MODE_ANONYMOUS, 0, $events[$name])) {
                $unequal[] = $name;
            }
        }
        check("organisation {$label}: on every imported event a key receives, its detail EQUALS the visitor's (plan B4, measured)", $unequal === [], 'differs on ' . setText($unequal));
    }
    $anonA = $visible(EventVisibility::MODE_ANONYMOUS, 0, ORG_A, true);
    $keyA  = $visible(EventVisibility::MODE_KEY, 0, ORG_A, true);
    $webA  = $visible(EventVisibility::MODE_WEBSITE, 0, ORG_A, true);
    check('(iii) E46 (public, full, opted out of the API): a signed-out visitor yes, a key no',
        in_array('E46', $anonA, true) === true && in_array('E46', $keyA, true) === false);
    check('(iv) E47 (public, website ticked, opted out of the API): website mode yes — the API box leaves the website alone — a key no',
        in_array('E47', $webA, true) === true && in_array('E47', $keyA, true) === false);
    check('(v) E48 (stored answer 0, but its calendar F5 is "Don\'t show via API"): a signed-out visitor yes, a key no (the calendar is tested live)',
        in_array('E48', $anonA, true) === true && in_array('E48', $keyA, true) === false);
    $kw = EventVisibility::where('e', EventVisibility::MODE_KEY, 0, $today);
    $kf = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_KEY, 0, $today);
    check('(vi) key mode binds nothing, in the WHERE or the detail answer (so both API handlers\' comments stay true)',
        $kw['types'] === '' && $kf['types'] === '' && $kw['params'] === [] && $kf['params'] === []);

    // (vii) isPublic says what it MEANS for an imported event (challenge
    // finding 4). E3's stored isPublic is 0 in this fixture — what the
    // importer writes — and the rule never reads isPublic on an imported row.
    $isPublicOf = static function (string $mode, int $viewerId, string $event) use ($db, $today, $events): int {
        $p = EventVisibility::isPublicSelect('e', $mode);
        $w = EventVisibility::where('e', $mode, $viewerId, $today);
        $rows = run($db, 'SELECT ' . $p['sql'] . ' FROM tblEvents e WHERE e.eventID = ?' . $w['sql'], $p['types'] . 'i' . $w['types'],
            array_merge($p['params'], [$events[$event]], $w['params']));

        return (int) ($rows[0]['isPublic'] ?? -1);
    };
    check('(vii) isPublic in key mode: E3 (imported, stored 0) → 1; E1 (own, public) → 1; E2 (own, members-only) → 0, passed through',
        $isPublicOf(EventVisibility::MODE_KEY, 0, 'E3') === 1 && $isPublicOf(EventVisibility::MODE_KEY, 0, 'E1') === 1
        && $isPublicOf(EventVisibility::MODE_KEY, 0, 'E2') === 0,
        'E3=' . $isPublicOf(EventVisibility::MODE_KEY, 0, 'E3') . ' E1=' . $isPublicOf(EventVisibility::MODE_KEY, 0, 'E1') . ' E2=' . $isPublicOf(EventVisibility::MODE_KEY, 0, 'E2'));
    check('(vii) isPublic in session mode: for V1, E3 → 1 and E4 (members level) → 0; for V6, E9 (stale stored answer) → 0',
        $isPublicOf(EventVisibility::MODE_SESSION, $viewers['V1'], 'E3') === 1 && $isPublicOf(EventVisibility::MODE_SESSION, $viewers['V1'], 'E4') === 0
        && $isPublicOf(EventVisibility::MODE_SESSION, $viewers['V6'], 'E9') === 0);
    $pk = EventVisibility::isPublicSelect('e', EventVisibility::MODE_KEY);
    $ps = EventVisibility::isPublicSelect('e', EventVisibility::MODE_SESSION);
    check('(vii) isPublicSelect() binds nothing in any mode, and its text depends only on the mode',
        $pk['types'] === '' && $ps['types'] === '' && $pk['params'] === [] && $ps['params'] === []
        && $ps['sql'] === EventVisibility::isPublicSelect('e', EventVisibility::MODE_ANONYMOUS)['sql'] && $pk['sql'] !== $ps['sql']);

    // -------------------------------------------------------------------------
    echo "\n=== every mode prepares against the real schema, and its text never depends on the viewer or the event ===\n";
    $allModes = [EventVisibility::MODE_SESSION, EventVisibility::MODE_ANONYMOUS, EventVisibility::MODE_WEBSITE, EventVisibility::MODE_TOKEN,
        EventVisibility::MODE_INVITE, EventVisibility::MODE_KEY, EventVisibility::MODE_BULK_MEMBERS];
    foreach ($allModes as $mode) {
        $texts = [];
        foreach ([0, $viewers['V1'], $viewers['V6'], $viewers['V8']] as $id) {
            $texts[] = EventVisibility::where('e', $mode, $id, $today)['sql'] . '|' . EventVisibility::fullDetailSelect('e', $mode, $id, $today)['sql'];
        }
        check("{$mode}: the SQL text is byte-identical for viewers 0, V1, V6 and V8", count(array_unique($texts)) === 1);

        // One prepared statement, three event numbers bound in turn: one the
        // viewer may see, one refused, one that does not exist. The text is
        // fixed before any event number is known.
        $w = EventVisibility::where('e', $mode, $viewers['V1'], $today);
        $f = EventVisibility::fullDetailSelect('e', $mode, $viewers['V1'], $today);
        $stmt = $db->prepare('SELECT e.eventID, ' . $f['sql'] . ' FROM tblEvents e WHERE e.eventID = ?' . $w['sql']);
        $counts = [];
        foreach ([$events['E1'], $events['E7'], 999999] as $eventId) {
            $bind = array_merge($f['params'], [$eventId], $w['params']);
            $stmt->bind_param($f['types'] . 'i' . $w['types'], ...$bind);
            $stmt->execute();
            $counts[] = $stmt->get_result()->num_rows;
        }
        $stmt->close();
        check("{$mode}: one prepared statement answers a visible event, a refused one and a missing one (rows " . implode('/', $counts) . ')', $counts === [1, 0, 0]);
    }
    foreach ([EventVisibility::MODE_SESSION, EventVisibility::MODE_TOKEN, EventVisibility::MODE_INVITE] as $mode) {
        $w = EventVisibility::whereForColumn('e', 'r.userID', $mode, $today);
        $f = EventVisibility::fullDetailSelectForColumn('e', 'r.userID', $mode, $today);
        $rows = run(
            $db,
            'SELECT e.eventID, ' . $f['sql'] . ' FROM tblEvents e JOIN (SELECT ? AS userID) r WHERE e.eventID = ?' . $w['sql'],
            $f['types'] . 'ii' . $w['types'],
            array_merge($f['params'], [$viewers['V1'], $events['E6']], $w['params'])
        );
        check("{$mode}: the column forms prepare and run (E6 for V1 is visible, canSeeFull 1)", count($rows) === 1 && (int) $rows[0]['canSeeFull'] === 1);
    }

    // -------------------------------------------------------------------------
    echo "\n=== the placeholder count is enforced (a fault planted through reflection) ===\n";
    $add = new ReflectionMethod(EventVisibility::class, 'add');
    foreach ([['a = ?', '', []], ['a = ?', 'i', []], ['a = ?', 'ii', [1, 2]], ['a = 1', 'i', [1]]] as [$piece, $types, $values]) {
        try {
            $add->invoke(null, $piece, $types, $values);
            check("add('{$piece}', '{$types}', " . count($values) . ' value(s)) throws LogicException', false, 'no exception');
        } catch (LogicException $e) {
            check("add('{$piece}', '{$types}', " . count($values) . ' value(s)) throws LogicException', true);
        }
    }
    $piecesProp = new ReflectionProperty(EventVisibility::class, 'pieces');
    $original = $piecesProp->getValue();
    $plants = [
        'the administrator piece given one viewer letter too many' => static function (array $p): array { $p['admin']['binds'] = 'VVV'; return $p; },
        'the membership piece given an extra ? in its SQL'          => static function (array $p): array { $p['member']['sql'] .= ' AND 1 = ?'; return $p; },
        'the audience piece missing one of its two date letters'     => static function (array $p): array { $p['audience']['binds'] = 'VVVT'; return $p; },
    ];
    foreach ($plants as $label => $plant) {
        $piecesProp->setValue(null, $plant($original));
        try {
            EventVisibility::where('e', EventVisibility::MODE_SESSION, 1, $today);
            check("where() refuses with LogicException: {$label}", false, 'no exception');
        } catch (LogicException $e) {
            check("where() refuses with LogicException: {$label}", true);
        } finally {
            $piecesProp->setValue(null, $original);
        }
    }
    $w = EventVisibility::where('e', EventVisibility::MODE_SESSION, 1, $today);
    check('with the pieces put back, where() works again', substr_count($w['sql'], '?') === strlen($w['types']));

    // -------------------------------------------------------------------------
    echo "\n=== bad arguments are refused before any SQL exists ===\n";
    $refusals = [
        'an unknown mode'                               => static fn () => EventVisibility::where('e', 'everyone', 0, $today),
        'an upper-case alias'                           => static fn () => EventVisibility::where('E', EventVisibility::MODE_SESSION, 0, $today),
        "the rule's own inner alias 'am' as the alias"  => static fn () => EventVisibility::where('am', EventVisibility::MODE_SESSION, 0, $today),
        "a viewer column on the rule's own alias 'vm'"  => static fn () => EventVisibility::whereForColumn('e', 'vm.userID', EventVisibility::MODE_TOKEN, $today),
        'a viewer column that is an expression'         => static fn () => EventVisibility::whereForColumn('e', 'r.userID OR 1', EventVisibility::MODE_TOKEN, $today),
        'a viewer column in key mode (always nobody)'   => static fn () => EventVisibility::whereForColumn('e', 'r.userID', EventVisibility::MODE_KEY, $today),
        'a date that does not exist'                    => static fn () => EventVisibility::where('e', EventVisibility::MODE_SESSION, 0, '2026-02-30'),
        'a canSeeFull name with a space'                => static fn () => EventVisibility::fullDetailSelect('e', EventVisibility::MODE_SESSION, 0, $today, 'x y'),
    ];
    foreach ($refusals as $label => $call) {
        try {
            $call();
            check("refused: {$label}", false, 'no exception');
        } catch (InvalidArgumentException $e) {
            check("refused: {$label}", true);
        }
    }

    // -------------------------------------------------------------------------
    echo "\n=== redact() ===\n";
    $row = ['eventName' => 'Title', 'startDateTime' => '2026-10-01 10:00:00', 'description' => 'd', 'locationName' => 'l', 'heroImage' => 'h'];
    $cut = EventVisibility::redact($row, false);
    check('at basic detail every present detail column is null and the title and time stay',
        $cut['description'] === null && $cut['locationName'] === null && $cut['heroImage'] === null
        && $cut['eventName'] === 'Title' && $cut['startDateTime'] === '2026-10-01 10:00:00' && $cut['detailsLimited'] === true);
    check('a detail column the caller never selected is not added', array_key_exists('locationAddress', $cut) === false);
    $full = EventVisibility::redact($row, true);
    check('at full detail nothing changes except detailsLimited = false', $full === $row + ['detailsLimited' => false]);

    // -------------------------------------------------------------------------
    echo "\n=== isAdminOfSite(): the #514 administrator gate ===\n";
    foreach ([['V6', ORG_A, true], ['V6', ORG_B, false], ['V7', ORG_B, true], ['V7', ORG_A, false], ['V8', ORG_A, true],
        ['V8', ORG_B, true], ['V9', ORG_A, false], ['V1', ORG_A, false], ['V0', ORG_A, false],
        ['V31', ORG_A, true], ['V30', ORG_A, false], ['V32', ORG_A, false], ['V33', ORG_A, false], ['V39', ORG_A, false]] as [$v, $org, $want]) {
        $label = $org === ORG_A ? 'A' : 'B';
        check("{$v} is " . ($want === true ? '' : 'NOT ') . "an administrator of {$label}", EventVisibility::isAdminOfSite($db, $viewers[$v], $org) === $want);
    }
} catch (Throwable $e) {
    check('the self-test ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' at line ' . $e->getLine());
} finally {
    if ($keep === true) {
        echo "\nFixture LEFT in place (--keep). Remove it with --cleanup.\n";
    } else {
        // 🧹 The clean-up must never throw from here. It used to be called
        //    bare, and on a database where a table is missing (one built
        //    without migration 204, for example) it threw inside this
        //    `finally`, so PHP ended with exit code 255 and a stack trace
        //    instead of the promised 1 (found by P1's independent check,
        //    21 September 2026). Now a failed clean-up is one more FAIL line,
        //    and the summary below still runs.
        try {
            removeFixture($db);
            echo "\nFixture removed.\n";
        } catch (Throwable $e) {
            echo "\n";
            check('the fixture was removed afterwards', false, get_class($e) . ': ' . $e->getMessage());
        }
    }
}

echo "\n";
if ($tableRows === []) {
    echo "FAIL — tblExternalAudienceMembers does not exist: migration 204 has not been run on this database.\n";
    exit(1);
}
if ($collationOk === false) {
    echo "FAIL — tblExternalAudienceMembers is {$tableCollation}, not utf8mb4_general_ci; migration 204 or full_schema.sql lost its COLLATE clause.\n";
    exit(1);
}
if ($failures > 0) {
    echo "FAIL — {$failures} of {$checks} checks failed.\n";
    exit(1);
}
echo "PASS — all {$checks} checks passed.\n";
exit(0);
