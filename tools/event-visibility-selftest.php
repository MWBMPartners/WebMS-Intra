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
 * PART 10 OF #514 (viewers V17-V24, events E50-E52, calendar F6)
 * ---------------------------------------------------------------
 * Part 10 lets a "selected groups" list name a role (#516), a user group or
 * a department (#517). Until then the rule answered "nobody" for all three.
 * Checks R1 to R4, in a section of their own, prove with answers WRITTEN BY
 * HAND:
 *   R1 the right people are let in and nobody else — not a former member
 *      who still holds all three, a switched-off account that does, or
 *      somebody in a switched-off department;
 *   R2 ANOTHER ORGANISATION'S role, user group or department on a list
 *      matches nobody, even for a person who is a member of both
 *      organisations (V18, V23, V24). E35's three rows of those kinds
 *      pointed at the number 1 until part 10; they now point at
 *      organisation B's role, user group and department;
 *   R3 every change takes effect on the very next call;
 *   R4 the form that binds the viewer and the form that takes the viewer
 *      from a column agree, and a miscounted viewer is refused outright.
 * On 13 October 2026 fourteen faults were planted, one at a time, in a
 * scratch copy (never in the checkout), and each made this test fail on the
 * check meant to catch it: a kind left matching nobody (three), the member
 * test dropped (two), a holding judged by its OWN organisation instead of
 * the event's (three), the list row's organisation not tested (one), a
 * retired user group or a switched-off department still counting (two,
 * planted in UserGroups and Departments), and a viewer letter too few, too
 * many, or left as before part 10 (three).
 * ONE NUMBER, SIX KINDS (check R9; viewers V40-V45, events E60-E65)
 * ------------------------------------------------------------------
 * The independent check of part 10 (13 October 2026) then planted two
 * faults that the fourteen above had not thought of, and BOTH PASSED all
 * 179 checks this test then had: the test `am.kind = 'role'` taken out of
 * the rule (so a list row of any kind was read as a role number), and a
 * department row also read as a user group number. Nothing was wrong in the
 * rule. The fixture could not tell, because no two things in it shared a
 * number — and on a real installation they do: six tables count their rows
 * separately, and a fresh install already holds roles 1 to 14.
 * So organisation A now has a role, a user group, a department, a small
 * group and a leadership role that ALL carry one number, which is also one
 * person's account number, each with one person of its own; and six events
 * are each listed for ONE of those kinds. R9 requires each event to be seen
 * by its own kind's person and by none of the other five. That holds every
 * pair of kinds apart, both ways round.
 * The same day those two faults, and the same slip in every other branch
 * (eight in all: the kind test dropped from the role, user group,
 * department, small group and leadership role branches; a department row
 * read as a user group; a leadership role row read as a role; a small group
 * row read as a user group), were planted in a copy, and each failed R9.
 * The PERSON branch is held a little differently. It compares the row's
 * `userID` column, which the portal fills for a person's row only, so on
 * the portal's own rows dropping its kind test changes no answer. The role
 * row of E61 therefore carries V1's number in that column, by hand: with
 * the person branch's kind test dropped, V1 would be let in as if named.
 * That fault was planted too, and failed R9.
 *
 * WHAT R1 TO R4 CANNOT SHOW. A "cache" cannot be planted in a class that
 * only builds SQL text, so R3 shows that the rule reads live; it has no
 * planted fault of its own beyond the two on/off tests. And one fault was
 * planted expecting it to pass unnoticed, and it did: the membership test
 * taken out of `Roles::holdsSql()` alone. The rule's own member test
 * refuses a former member first, so nothing here can tell.
 * `tools/roles-selftest.php` compares that piece of SQL word for word, and
 * does fail on it (run the same day).
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

// Viewers. V0 is nobody; V1-V16 are the plan's; V17-V24 are part P10's
// (roles, user groups and departments); V30-V39 are the extra cases (see
// the header).
$viewers = ['V0' => 0];
for ($i = 1; $i <= 24; $i++) {
    $viewers['V' . $i] = 900100 + $i;
}
for ($i = 30; $i <= 39; $i++) {
    $viewers['V' . $i] = 900100 + $i;
}
// V40-V45: the "one number, six kinds" people (added after part P10's
// independent check, 13 October 2026; see SAME_NUMBER below).
for ($i = 40; $i <= 45; $i++) {
    $viewers['V' . $i] = 900100 + $i;
}

const F1 = 900201; // A, active, public
const F2 = 900202; // A, active, members
const F3 = 900203; // A, active, groups, list {G1, G2, V15, V16}
const F4 = 900204; // A, switched off, public
const FB = 900205; // B, active, members
const FB2 = 900206; // B, active, public (extra: only E31, an event of A, points at it)
const F5 = 900207;  // A, active, public, "Don't show via API" ticked (#514 part P7: only E48 points at it)
const F6 = 900208;  // A, active, groups (#514 part P10): its list names A's role, user group and department — and B's

const G1 = 900401; // small group of A, active
const G2 = 900402; // small group of A, switched off
const G3 = 900403; // small group of B, active (extra: put on A's F3 list, must match nobody)
const L1 = 900451; // leadership role "Elder" of A, active
const L2 = 900452; // leadership role of A, switched off (extra)
const CHOICE_E10 = 900500; // the single-date choice owning E10's list {L1}
const CHOICE_E35 = 900502; // extra: owns E35's list (a row of B naming V38; and organisation B's role, user group and department)
const CHOICE_E52 = 900507; // part P10: owns E52's list, an event of B, naming B's own role, user group and department
const RULE_E33 = 900610; // extra: owns E33's list {L1, L2}
// Part P10 (#514): the three kinds of grouping #516 and #517 built. Each
// organisation has one of each, so "the other organisation's" can be put on
// a list and must match nobody.
const RO_A = 900701; // role of A (tblRoles) — roles have no on/off switch
const RO_B = 900702; // role of B
const UG_A = 900711; // user group of A (tblGroups), in use
const UG_B = 900712; // user group of B, in use
const DP_A = 900721; // department of A (tblDepts), SWITCHED OFF in the fixture; check R3 switches it on and off
const DP_B = 900722; // department of B, in use
// 🔢 ONE NUMBER, SIX KINDS (added after part P10's independent check).
//    A list row says what it names in TWO parts: a kind and a number. Six
//    tables number their rows separately, so on a real installation the
//    same number means a role, a user group, a department, a small group, a
//    leadership role AND a person's account all at once (a fresh install
//    already holds roles 1 to 14; the first user group an organisation makes
//    is number 1 too). Until this was added every number in this fixture was
//    different, so a rule that read a row of one kind as another kind's
//    number passed every check here — the check planted exactly that and it
//    did. Now organisation A has one of EACH carrying this one number, with
//    a different person in each, and six events each listed for one kind
//    only (E60 to E65). It is V40's own account number.
const SAME_NUMBER = 900140;
// The six lists, one per kind, each naming SAME_NUMBER and nothing else.
const CHOICE_E60 = 900520; // person
const CHOICE_E61 = 900521; // role
const CHOICE_E62 = 900522; // user group
const CHOICE_E63 = 900523; // department
const CHOICE_E64 = 900524; // small group
const CHOICE_E65 = 900525; // leadership role

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
    'E50' => 900350, 'E51' => 900351, 'E52' => 900352, // part P10
    // one number, six kinds: each listed for ONE kind only, all naming SAME_NUMBER
    'E60' => 900360, 'E61' => 900361, 'E62' => 900362, 'E63' => 900363, 'E64' => 900364, 'E65' => 900365,
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
    // Part P10's holdings, then what they hold. The holdings would go with
    // the membership rows anyway; the departments would NOT go with their
    // organisation (that link has no "delete with it"), and leaving one
    // behind would stop the organisations being removed at all.
    run($db, 'DELETE FROM tblUserRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblUserGroups WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblUserDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblRoles WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblGroups WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
    run($db, 'DELETE FROM tblDepts WHERE siteID IN (?, ?)', 'ii', [ORG_A, ORG_B]);
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
        // part P10 (V22's ACCOUNT is switched off)
        'V17' => [1, 0, 0], 'V18' => [1, 0, 0], 'V19' => [1, 0, 0], 'V20' => [1, 0, 0],
        'V21' => [1, 0, 0], 'V22' => [0, 0, 0], 'V23' => [1, 0, 0], 'V24' => [1, 0, 0],
        // one number, six kinds (all plain, active members of A)
        'V40' => [1, 0, 0], 'V41' => [1, 0, 0], 'V42' => [1, 0, 0], 'V43' => [1, 0, 0], 'V44' => [1, 0, 0], 'V45' => [1, 0, 0],
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
        // part P10: V21's row in A is switched off (a FORMER member whose
        // holdings were never tidied away); V18, V23 and V24 are active
        // members of BOTH organisations
        ['V17', ORG_A, 1, 0, 0], ['V18', ORG_A, 1, 0, 0], ['V18', ORG_B, 1, 0, 0], ['V19', ORG_A, 1, 0, 0],
        ['V20', ORG_A, 1, 0, 0], ['V21', ORG_A, 0, 0, 0], ['V22', ORG_A, 1, 0, 0],
        ['V23', ORG_A, 1, 0, 0], ['V23', ORG_B, 1, 0, 0], ['V24', ORG_A, 1, 0, 0], ['V24', ORG_B, 1, 0, 0],
        // one number, six kinds
        ['V40', ORG_A, 1, 0, 0], ['V41', ORG_A, 1, 0, 0], ['V42', ORG_A, 1, 0, 0], ['V43', ORG_A, 1, 0, 0],
        ['V44', ORG_A, 1, 0, 0], ['V45', ORG_A, 1, 0, 0],
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

    // 🧩 Part P10 (#514): one role, one user group and one department in each
    //    organisation, then who holds what. ORDER MATTERS: the membership
    //    rows (above) and these three rows must exist BEFORE any holding,
    //    because every holding is tied to both by a two-column link — a
    //    holding of organisation B's role filed under organisation A cannot
    //    be written at all.
    //    A's department DP_A starts SWITCHED OFF; the others are in use.
    run($db, "INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (?, ?, 'selftest_p514_role', 'Selftest role R', 0), (?, ?, 'selftest_p514_role', 'Selftest role R of B', 0)", 'iiii', [RO_A, ORG_A, RO_B, ORG_B]);
    run($db, "INSERT INTO tblGroups (groupID, siteID, groupName, isActive) VALUES (?, ?, 'Selftest user group UG', 1), (?, ?, 'Selftest user group UG of B', 1)", 'iiii', [UG_A, ORG_A, UG_B, ORG_B]);
    run($db, "INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (?, ?, 'Selftest department D', 0), (?, ?, 'Selftest department D of B', 1)", 'iiii', [DP_A, ORG_A, DP_B, ORG_B]);
    // Who holds what: [viewer, organisation, role or null, user group or null, department or null]
    //   V17 holds A's role.            V19 is in A's user group.      V20 is in A's department (switched off).
    //   V21 (row in A switched off) and V22 (account switched off) each hold ALL THREE of A's.
    //   V18 holds B's role, V23 is in B's user group, V24 is in B's department — and nothing of A's.
    $holdings = [
        ['V17', ORG_A, RO_A, null, null], ['V19', ORG_A, null, UG_A, null], ['V20', ORG_A, null, null, DP_A],
        ['V21', ORG_A, RO_A, UG_A, DP_A], ['V22', ORG_A, RO_A, UG_A, DP_A],
        ['V18', ORG_B, RO_B, null, null], ['V23', ORG_B, null, UG_B, null], ['V24', ORG_B, null, null, DP_B],
    ];
    foreach ($holdings as [$name, $org, $role, $group, $dept]) {
        if ($role !== null) {
            run($db, 'INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?)', 'iii', [$viewers[$name], $role, $org]);
        }
        if ($group !== null) {
            run($db, 'INSERT INTO tblUserGroups (userID, groupID, siteID) VALUES (?, ?, ?)', 'iii', [$viewers[$name], $group, $org]);
        }
        if ($dept !== null) {
            run($db, 'INSERT INTO tblUserDepts (userID, deptID, siteID) VALUES (?, ?, ?)', 'iii', [$viewers[$name], $dept, $org]);
        }
    }

    // 🔢 One number, six kinds (see SAME_NUMBER). Organisation A gets a role,
    //    a user group, a department, a small group and a leadership role that
    //    ALL carry the number 900140 — which is also V40's account number —
    //    and each has exactly one person, who holds nothing else:
    //      V40 is the person with that account number   V41 holds the role
    //      V42 is in the user group                      V43 is in the department
    //      V44 is in the small group                     V45 holds the leadership role
    //    All five are switched on, so the ONLY thing that keeps, say, V41 away
    //    from an event listed for "department 900140" is the rule reading the
    //    row's kind.
    if ($viewers['V40'] !== SAME_NUMBER) {
        throw new LogicException('The fixture is wrong: SAME_NUMBER must be V40\'s own account number.');
    }
    run($db, "INSERT INTO tblRoles (roleID, siteID, roleKey, roleName, isStandard) VALUES (?, ?, 'selftest_p514_same', 'Selftest same-number role', 0)", 'ii', [SAME_NUMBER, ORG_A]);
    run($db, "INSERT INTO tblGroups (groupID, siteID, groupName, isActive) VALUES (?, ?, 'Selftest same-number user group', 1)", 'ii', [SAME_NUMBER, ORG_A]);
    run($db, "INSERT INTO tblDepts (deptID, siteID, deptName, isActive) VALUES (?, ?, 'Selftest same-number department', 1)", 'ii', [SAME_NUMBER, ORG_A]);
    run($db, "INSERT INTO tblSmallGroups (groupID, siteID, groupName, groupSlug, isActive) VALUES (?, ?, 'Selftest same-number small group', 'selftest-p514-same', 1)", 'ii', [SAME_NUMBER, ORG_A]);
    run($db, "INSERT INTO tblLeadershipRoles (roleID, siteID, roleName, roleSlug, isActive) VALUES (?, ?, 'Selftest same-number leadership role', 'selftest-p514-same', 1)", 'ii', [SAME_NUMBER, ORG_A]);
    run($db, 'INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?)', 'iii', [$viewers['V41'], SAME_NUMBER, ORG_A]);
    run($db, 'INSERT INTO tblUserGroups (userID, groupID, siteID) VALUES (?, ?, ?)', 'iii', [$viewers['V42'], SAME_NUMBER, ORG_A]);
    run($db, 'INSERT INTO tblUserDepts (userID, deptID, siteID) VALUES (?, ?, ?)', 'iii', [$viewers['V43'], SAME_NUMBER, ORG_A]);
    run($db, "INSERT INTO tblSmallGroupMembers (siteID, groupID, userID, status) VALUES (?, ?, ?, 'active')", 'iii', [ORG_A, SAME_NUMBER, $viewers['V44']]);
    run($db, "INSERT INTO tblLeadershipAssignments (siteID, roleID, userID, startDate, endDate, isActive) VALUES (?, ?, ?, '2020-01-01', NULL, 1)", 'iii', [ORG_A, SAME_NUMBER, $viewers['V45']]);

    // Calendars: [feedID, organisation, isActive, audienceLevel, apiOptOut]
    // `apiOptOut` is named on every row (#514 part P7): 0, "send to API keys",
    // everywhere except F5, whose box is ticked.
    $calendars = [[F1, ORG_A, 1, 'public', 0], [F2, ORG_A, 1, 'members', 0], [F3, ORG_A, 1, 'groups', 0], [F4, ORG_A, 0, 'public', 0],
        [FB, ORG_B, 1, 'members', 0], [FB2, ORG_B, 1, 'public', 0], [F5, ORG_A, 1, 'public', 1], [F6, ORG_A, 1, 'groups', 0]];
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
    // P10 switched on, each pointing at ORGANISATION B's role, user group
    // and department (so all three must match nobody, for good: check R2).
    // Until part P10 those three rows pointed at the number 1 and matched
    // nobody only because the rule answered "nobody" for the whole kind.
    // Part P10: F6's own list names A's role, user group and department AND
    // B's three (written in by hand: the form refuses another organisation's);
    // choice 900507, of organisation B, names B's own three (the control
    // that V18, V23 and V24 really do hold them, there).
    // [organisation, calendar, ownerType, ownerID, kind, refID, userID]
    $audience = [
        [ORG_A, F3, 'feed', F3, 'small_group', G1, null], [ORG_A, F3, 'feed', F3, 'small_group', G2, null],
        [ORG_A, F3, 'feed', F3, 'person', $viewers['V15'], $viewers['V15']], [ORG_A, F3, 'feed', F3, 'person', $viewers['V16'], $viewers['V16']],
        [ORG_A, F2, 'choice', CHOICE_E10, 'leadership_role', L1, null],
        [ORG_A, F3, 'feed', F3, 'small_group', G3, null],
        [ORG_A, F2, 'rule', RULE_E33, 'leadership_role', L1, null], [ORG_A, F2, 'rule', RULE_E33, 'leadership_role', L2, null],
        [ORG_B, F2, 'choice', CHOICE_E35, 'person', $viewers['V38'], $viewers['V38']],
        [ORG_A, F2, 'choice', CHOICE_E35, 'role', RO_B, null], [ORG_A, F2, 'choice', CHOICE_E35, 'user_group', UG_B, null],
        [ORG_A, F2, 'choice', CHOICE_E35, 'department', DP_B, null],
        // part P10
        [ORG_A, F6, 'feed', F6, 'role', RO_A, null], [ORG_A, F6, 'feed', F6, 'user_group', UG_A, null],
        [ORG_A, F6, 'feed', F6, 'department', DP_A, null],
        [ORG_A, F6, 'feed', F6, 'role', RO_B, null], [ORG_A, F6, 'feed', F6, 'user_group', UG_B, null],
        [ORG_A, F6, 'feed', F6, 'department', DP_B, null],
        [ORG_B, FB, 'choice', CHOICE_E52, 'role', RO_B, null], [ORG_B, FB, 'choice', CHOICE_E52, 'user_group', UG_B, null],
        [ORG_B, FB, 'choice', CHOICE_E52, 'department', DP_B, null],
        // one number, six kinds: six lists, each ONE row, all naming SAME_NUMBER.
        // The ROLE row also carries V1's account number in its `userID`
        // column, on purpose. The portal fills that column for a person's
        // row only; on a row of any other kind it means nothing, and the
        // rule must not read it. It is what lets R9 hold the PERSON branch's
        // own kind test: without that test, V1 would be let in as if named.
        [ORG_A, F2, 'choice', CHOICE_E60, 'person', SAME_NUMBER, SAME_NUMBER],
        [ORG_A, F2, 'choice', CHOICE_E61, 'role', SAME_NUMBER, $viewers['V1']],
        [ORG_A, F2, 'choice', CHOICE_E62, 'user_group', SAME_NUMBER, null],
        [ORG_A, F2, 'choice', CHOICE_E63, 'department', SAME_NUMBER, null],
        [ORG_A, F2, 'choice', CHOICE_E64, 'small_group', SAME_NUMBER, null],
        [ORG_A, F2, 'choice', CHOICE_E65, 'leadership_role', SAME_NUMBER, null],
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
        ['E35', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E35, 'date',     0, 0],  // list: V38 on a row of B; B's role, user group and department
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
        // #514 part P10: roles, user groups and departments on a list
        ['E50', ORG_A, F6,     1, 'groups',  'full',  0, 'feed',   F6,         'calendar', 0, 0],  // list: A's role, user group, (switched-off) department — and B's three
        ['E51', ORG_A, F6,     1, 'public',  'basic', 0, 'rule',   900614,     'rule',     0, 0],  // public at basic on F6: full detail only through F6's list
        ['E52', ORG_B, FB,     1, 'groups',  'full',  0, 'choice', CHOICE_E52, 'date',     0, 0],  // an event of B, list: B's own role, user group, department
        // one number, six kinds: each event's list is ONE row naming SAME_NUMBER
        ['E60', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E60, 'date',     0, 0],  // list: the PERSON whose account is that number (V40)
        ['E61', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E61, 'date',     0, 0],  // list: the ROLE with that number (V41 holds it)
        ['E62', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E62, 'date',     0, 0],  // list: the USER GROUP with that number (V42)
        ['E63', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E63, 'date',     0, 0],  // list: the DEPARTMENT with that number (V43)
        ['E64', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E64, 'date',     0, 0],  // list: the SMALL GROUP with that number (V44)
        ['E65', ORG_A, F2,     1, 'groups',  'full',  0, 'choice', CHOICE_E65, 'date',     0, 0],  // list: the LEADERSHIP ROLE with that number (V45)
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
            . ' startDateTime, endDateTime, timezone, status, isPublic, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType,'
            . ' importAudienceID, importSource, externalPrivate, importRecheckAt)'
            // The zone is named (#604: an event with none is refused by the database). 'UTC' is a test value.
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UTC', 'published', ?, ?, ?, ?, ?, ?, ?, ?, ?,"
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
        // part P10, on the plan's events (none of which is on a list of the
        // new kinds, so each is simply a member, a former member, a
        // switched-off account, or a member of both organisations)
        'V17' => $v1, 'V18' => $withB($v1), 'V19' => $v1, 'V20' => $v1, 'V21' => $v0, 'V22' => $v0,
        'V23' => $withB($v1), 'V24' => $withB($v1),
        // one number, six kinds: plain members of A on the plan's events
        'V40' => $v1, 'V41' => $v1, 'V42' => $v1, 'V43' => $v1, 'V44' => $v1, 'V45' => $v1,
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
        'V17' => 'member of A, holds A\'s role R', 'V18' => 'member of A and B, holds only B\'s role',
        'V19' => 'member of A, in A\'s user group UG', 'V20' => 'member of A, in A\'s department D, which is switched off',
        'V21' => 'holds all three of A\'s, row in A switched off', 'V22' => 'holds all three of A\'s, ACCOUNT switched off',
        'V23' => 'member of A and B, in only B\'s user group', 'V24' => 'member of A and B, in only B\'s department',
        'V40' => 'member of A whose account number is the shared number', 'V41' => 'member of A, holds the role with the shared number',
        'V42' => 'member of A, in the user group with the shared number', 'V43' => 'member of A, in the department with the shared number',
        'V44' => 'member of A, in the small group with the shared number', 'V45' => 'member of A, holds the leadership role with the shared number',
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
    check('E35 (V38 named only on a list row of organisation B; and organisation B\'s role, user group and department on A\'s list): no non-administrator sees it — R2, see the part 10 section for who it would be', $got === [], 'got ' . setText($got));
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
    echo "\n=== part 10 (#514): roles, user groups and departments on a list — checks R1 to R4 ===\n";
    // WHAT THIS SECTION IS FOR. Until part P10 the rule answered "nobody" for
    // a list entry that names a role, a user group or a department. It now
    // asks Roles, UserGroups and Departments who holds or is in them. A
    // mistake here shows somebody an event they must not see, so every
    // expected answer below is WRITTEN BY HAND, never worked out by the code
    // under test:
    //   E50 — "selected groups" on calendar F6, whose list names A's role R,
    //         A's user group UG, A's department D (switched off), and — put
    //         there by hand — organisation B's role, user group and department;
    //   E51 — public at basic detail on F6, so only F6's list gives full detail;
    //   E52 — an event of organisation B whose list names B's own three;
    //   E35 — an event of A whose list names only B's three (and V38 on a row
    //         of B). It is older than part P10: see the header.
    /** @return list<string> every viewer, in the fixture's order, who sees $event in $mode */
    $whoSees = static function (string $event, string $mode) use ($visible, $viewers): array {
        $who = [];
        foreach ($viewers as $name => $id) {
            if (in_array($event, $visible($mode, $id, null, true), true) === true) {
                $who[] = $name;
            }
        }

        return $who;
    };
    /** Does this one viewer see E50, signed in? Asked afresh every time (check R3 depends on that). */
    $seesE50 = static fn (string $name): bool => in_array('E50', $visible(EventVisibility::MODE_SESSION, $viewers[$name], null, true), true);

    // ---- R1: the three kinds let the right people in, and nobody else ----
    $e50Session = ['V6', 'V8', 'V17', 'V19', 'V31'];
    $got = $whoSees('E50', EventVisibility::MODE_SESSION);
    check('R1 E50 (list: A\'s role R, user group UG and switched-off department D), signed in: exactly V17 (holds R), V19 (in UG) and the administrators V6, V8 and V31 — not V20 (D is switched off), V21 (a former member holding all three), V22 (a switched-off account holding all three), V1 (a plain member) or V0 (nobody)',
        $got === $e50Session, 'got ' . setText($got));
    $got = $whoSees('E50', EventVisibility::MODE_TOKEN);
    check('R1 E50 in token mode (a personal feed, no administrator powers): exactly V17 and V19', $got === ['V17', 'V19'], 'got ' . setText($got));
    $leaks = [];
    foreach ([EventVisibility::MODE_ANONYMOUS, EventVisibility::MODE_WEBSITE, EventVisibility::MODE_KEY, EventVisibility::MODE_BULK_MEMBERS] as $mode) {
        // These modes always look as nobody, so the holder V17 is passed on
        // purpose: a mode that started trusting the viewer it is handed
        // would show it here.
        foreach (array_intersect($visible($mode, $viewers['V17'], null, true), ['E50', 'E52', 'E35']) as $e) {
            $leaks[] = "{$e} in {$mode}";
        }
    }
    check('R1 a signed-out visitor, the public website, an API key and the newsletter list never see E50, E52 or E35 (asked while handing each mode the holder V17)', $leaks === [], implode('; ', $leaks));
    $wantFull = ['V0' => 0, 'V1' => 0, 'V6' => 1, 'V17' => 1, 'V19' => 1, 'V20' => 0, 'V21' => 0, 'V22' => 0];
    $gotFull = [];
    foreach ($wantFull as $name => $unused) {
        $gotFull[$name] = $canSeeFull(EventVisibility::MODE_SESSION, $viewers[$name], $events['E51']);
    }
    check('R1 full detail of E51 (public, basic, on F6) comes only through F6\'s list: 1 for V17, V19 and the administrator V6; 0 for V0, V1, V20, V21 and V22',
        $gotFull === $wantFull, json_encode($gotFull));

    // ---- R2: another organisation's role, user group or department NEVER counts ----
    // V18, V23 and V24 are active members of BOTH organisations. Each holds
    // exactly one thing, in organisation B: the role, the user group, the
    // department. A's lists (E35's and F6's) name those three of B's. If the
    // rule tied a holding to the wrong organisation — the viewer's, or the
    // holding's own — they would see A's events. They must not.
    $crossOrg = ['V18', 'V23', 'V24'];
    $areMembersOfA = [];
    foreach ($crossOrg as $name) {
        $areMembersOfA[$name] = in_array('E4', $visible(EventVisibility::MODE_SESSION, $viewers[$name]), true); // E4 is a members-only event of A
    }
    check('R2 set-up: V18, V23 and V24 really are members of A (each sees A\'s members-only E4), so only the list can be keeping them out',
        $areMembersOfA === ['V18' => true, 'V23' => true, 'V24' => true], json_encode($areMembersOfA));
    $got = $whoSees('E52', EventVisibility::MODE_TOKEN);
    check('R2 control: E52, an event of organisation B listing B\'s own role, user group and department, is seen (token mode) by exactly V18, V23 and V24 — their holdings are real, there',
        $got === $crossOrg, 'got ' . setText($got));
    $wrongly = [];
    foreach ([EventVisibility::MODE_SESSION, EventVisibility::MODE_TOKEN, EventVisibility::MODE_INVITE] as $mode) {
        foreach (['E35', 'E50'] as $e) {
            foreach (array_intersect($whoSees($e, $mode), $crossOrg) as $name) {
                $wrongly[] = "{$name} sees {$e} in {$mode}";
            }
        }
    }
    check('R2 ANOTHER ORGANISATION\'S ROLE, USER GROUP OR DEPARTMENT NEVER COUNTS: V18 (holds B\'s role), V23 (in B\'s user group) and V24 (in B\'s department) see neither E35 nor E50, whose lists name those three of B\'s — in session, token and invite mode',
        $wrongly === [], implode('; ', $wrongly));
    $gotFull = [];
    foreach ($crossOrg as $name) {
        $gotFull[$name] = $canSeeFull(EventVisibility::MODE_SESSION, $viewers[$name], $events['E51']);
    }
    check('R2 …and they get title, date and time only on E51, whose calendar\'s list names B\'s three', $gotFull === ['V18' => 0, 'V23' => 0, 'V24' => 0], json_encode($gotFull));
    $got = array_values(array_diff($whoSees('E52', EventVisibility::MODE_SESSION), ['V7', 'V8']));
    check('R2 the other way round: A\'s holders (V17, V19, V20) do not see B\'s E52 — signed in, only V18, V23, V24 and B\'s administrators V7 and V8 do', $got === $crossOrg, 'got ' . setText($got));

    // ---- R3: every change takes effect on the very next call ----
    // Nothing is cached anywhere: the rule is SQL, read at the moment
    // somebody looks. Each step changes ONE row the way the portal's own
    // pages would, asks again at once, and then puts the row back, so the
    // fixture ends this section exactly as it began (the last check says so).
    $v17 = $viewers['V17'];
    $before = $seesE50('V17');
    run($db, 'DELETE FROM tblUserRoles WHERE userID = ? AND roleID = ?', 'ii', [$v17, RO_A]); // the role taken off V17
    $without = $seesE50('V17');
    run($db, 'INSERT INTO tblUserRoles (userID, roleID, siteID) VALUES (?, ?, ?)', 'iii', [$v17, RO_A, ORG_A]);
    check('R3 a role taken off a person: V17 sees E50, then on the very next call does not, then does again once it is given back',
        $before === true && $without === false && $seesE50('V17') === true, json_encode([$before, $without]));
    $before = $seesE50('V19');
    run($db, 'UPDATE tblGroups SET isActive = 0 WHERE groupID = ?', 'i', [UG_A]); // the user group retired
    $retired = $seesE50('V19');
    run($db, 'UPDATE tblGroups SET isActive = 1 WHERE groupID = ?', 'i', [UG_A]);
    check('R3 a user group retired: V19 sees E50, then on the very next call does not, then does again once it is reinstated',
        $before === true && $retired === false && $seesE50('V19') === true, json_encode([$before, $retired]));
    $before = $seesE50('V20');
    run($db, 'UPDATE tblDepts SET isActive = 1 WHERE deptID = ?', 'i', [DP_A]); // the department switched ON
    $switchedOn = $seesE50('V20');
    $stillOut = [$seesE50('V21'), $seesE50('V22')];
    check('R3 a department switched on: V20 does not see E50, then on the very next call does — while V21 (former member) and V22 (switched-off account), who are in that department too, still do not',
        $before === false && $switchedOn === true && $stillOut === [false, false], json_encode([$before, $switchedOn, $stillOut]));
    run($db, 'UPDATE tblUserSites SET isActive = 0 WHERE userID = ? AND siteID = ?', 'ii', [$viewers['V20'], ORG_A]); // V20's membership of A ended
    $ended = $seesE50('V20');
    run($db, 'UPDATE tblUserSites SET isActive = 1 WHERE userID = ? AND siteID = ?', 'ii', [$viewers['V20'], ORG_A]);
    check('R3 …then V20\'s membership of A switched off: refused again on the very next call (and let back in when it is switched on)',
        $ended === false && $seesE50('V20') === true, json_encode([$ended]));
    run($db, 'UPDATE tblDepts SET isActive = NULL WHERE deptID = ?', 'i', [DP_A]); // an EMPTY flag, which the column allows
    $emptyFlag = $seesE50('V20');
    run($db, 'UPDATE tblDepts SET isActive = 0 WHERE deptID = ?', 'i', [DP_A]); // back to how the fixture began
    check('R3 a department whose on/off flag is EMPTY counts as switched off: V20 does not see E50', $emptyFlag === false);
    $got = $whoSees('E50', EventVisibility::MODE_SESSION);
    check('R3 with every row put back, E50 is seen by exactly the R1 set again', $got === $e50Session, 'got ' . setText($got));

    // ---- R4: the viewer bound as a number, and the viewer taken from a column ----
    // The three new tests each hold the viewer once. With a bound viewer
    // that is one more value each; with a column viewer, none. A letter too
    // few or too many is refused outright (the planted faults in the next
    // section); here the two forms are run for real and must agree.
    $columnWho = static function (string $event, string $mode) use ($visibleByColumn, $viewers): array {
        $who = [];
        foreach ($viewers as $name => $id) {
            if (in_array($event, $visibleByColumn($mode, $id), true) === true) {
                $who[] = $name;
            }
        }

        return $who;
    };
    check('R4 the column form (viewer from r.userID) lets in exactly the same people as the bound form: E50 for V17 and V19, E52 for V18, V23 and V24 (token mode), and nobody but administrators for E35 (session mode)',
        $columnWho('E50', EventVisibility::MODE_TOKEN) === ['V17', 'V19'] && $columnWho('E52', EventVisibility::MODE_TOKEN) === $crossOrg
        && $columnWho('E35', EventVisibility::MODE_SESSION) === ['V6', 'V8', 'V31'],
        json_encode([$columnWho('E50', EventVisibility::MODE_TOKEN), $columnWho('E52', EventVisibility::MODE_TOKEN), $columnWho('E35', EventVisibility::MODE_SESSION)]));
    $fullByColumn = static function (string $mode, int $viewerId, int $eventId) use ($db, $today): int {
        $f = EventVisibility::fullDetailSelectForColumn('e', 'r.userID', $mode, $today);
        $rows = run($db, 'SELECT e.eventID, ' . $f['sql'] . ' FROM tblEvents e JOIN (SELECT ? AS userID) r WHERE e.eventID = ?', $f['types'] . 'ii', array_merge($f['params'], [$viewerId, $eventId]));

        return (int) ($rows[0]['canSeeFull'] ?? -1);
    };
    $gotFull = [];
    foreach (['V17', 'V19', 'V20', 'V18', 'V1'] as $name) {
        $gotFull[$name] = $fullByColumn(EventVisibility::MODE_TOKEN, $viewers[$name], $events['E51']);
    }
    check('R4 the column form of the detail answer agrees too: E51 is 1 for V17 and V19, 0 for V20, V18 and V1 (token mode)',
        $gotFull === ['V17' => 1, 'V19' => 1, 'V20' => 0, 'V18' => 0, 'V1' => 0], json_encode($gotFull));
    $bound  = EventVisibility::where('e', EventVisibility::MODE_TOKEN, 1, $today);
    $column = EventVisibility::whereForColumn('e', 'r.userID', EventVisibility::MODE_TOKEN, $today);
    check('R4 in token mode a bound viewer binds 9 whole numbers and 2 dates (three member tests, and the viewer once in each of the list\'s six kinds); a column viewer binds the 2 dates only',
        $bound['types'] === 'iiiiiissiii' && $column['types'] === 'ss' && count($bound['params']) === 11 && $column['params'] === [$today, $today],
        $bound['types'] . ' / ' . $column['types']);

    // -------------------------------------------------------------------------
    echo "\n=== one number, six kinds: a list row of one kind is never read as another (check R9) ===\n";
    // WHY THIS SECTION EXISTS. Part P10's independent check (13 October 2026)
    // took `am.kind = 'role'` out of the rule, so that a list row of ANY kind
    // was read as a role number, and this whole test still passed. It also
    // made the rule read a department row as a user group number: passed
    // again. Nothing was wrong in the rule; the fixture simply could not
    // tell, because no two things in it shared a number. On a real
    // installation they do: six tables count their rows separately.
    //
    // So six things of organisation A now carry ONE number (SAME_NUMBER), each
    // with one person of its own, and six events are each listed for ONE of
    // them. Each event must be seen by its own person and by none of the
    // other five. That covers every pair of kinds, both ways round — the two
    // easiest to confuse included, because they share a column name: a role
    // and a leadership role ("roleID"), a user group and a small group
    // ("groupID"). Every expected answer is written out by hand.
    $oneKind = [
        'E60' => ['a PERSON', 'V40', 'whose account number it is'],
        'E61' => ['a ROLE', 'V41', 'who holds that role; the row also carries V1\'s number in its userID column, which must count for nothing'],
        'E62' => ['a USER GROUP', 'V42', 'who is in that user group'],
        'E63' => ['a DEPARTMENT', 'V43', 'who is in that department'],
        'E64' => ['a SMALL GROUP', 'V44', 'who is in that small group'],
        'E65' => ['a LEADERSHIP ROLE', 'V45', 'who holds that leadership role'],
    ];
    // The set-up is checked, not assumed: a fixture in which the six did NOT
    // share the number, or one of them was switched off or empty, would make
    // every check below pass for the wrong reason.
    $sameNumber = SAME_NUMBER;
    $usersOf = static fn (string $sql, string $types, array $params): array => array_map(
        static fn (array $r): int => (int) $r['userID'], run($db, $sql, $types, $params)
    );
    $sharing = [
        'role'            => $usersOf('SELECT ur.userID FROM tblUserRoles ur JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID WHERE ur.roleID = ? AND ur.siteID = ?', 'ii', [$sameNumber, ORG_A]),
        'user group'      => $usersOf('SELECT tblUserGroups.userID FROM tblGroups g JOIN tblUserGroups ON tblUserGroups.groupID = g.groupID AND tblUserGroups.siteID = g.siteID WHERE g.groupID = ? AND g.siteID = ? AND g.isActive = 1', 'ii', [$sameNumber, ORG_A]),
        'department'      => $usersOf('SELECT ud.userID FROM tblDepts d JOIN tblUserDepts ud ON ud.deptID = d.deptID AND ud.siteID = d.siteID WHERE d.deptID = ? AND d.siteID = ? AND d.isActive = 1', 'ii', [$sameNumber, ORG_A]),
        'small group'     => $usersOf("SELECT tblSmallGroupMembers.userID FROM tblSmallGroupMembers JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.siteID = tblSmallGroupMembers.siteID AND sg.isActive = 1 WHERE tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.siteID = ? AND tblSmallGroupMembers.status = 'active'", 'ii', [$sameNumber, ORG_A]),
        'leadership role' => $usersOf('SELECT la.userID FROM tblLeadershipAssignments la JOIN tblLeadershipRoles lr ON lr.roleID = la.roleID AND lr.siteID = la.siteID AND lr.isActive = 1 WHERE la.roleID = ? AND la.siteID = ? AND la.isActive = 1', 'ii', [$sameNumber, ORG_A]),
        'account'         => $usersOf('SELECT u.userID FROM tblUsers u JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 WHERE u.userID = ? AND u.isActive = 1', 'ii', [ORG_A, $sameNumber]),
    ];
    check('R9 set-up: in organisation A a role, a user group, a department, a small group, a leadership role and an account ALL carry the number ' . $sameNumber . ', each is switched on, and each has one person of its own (V41, V42, V43, V44, V45; the account is V40)',
        $sharing === [
            'role' => [$viewers['V41']], 'user group' => [$viewers['V42']], 'department' => [$viewers['V43']],
            'small group' => [$viewers['V44']], 'leadership role' => [$viewers['V45']], 'account' => [$viewers['V40']],
        ], json_encode($sharing));
    foreach ($oneKind as $event => [$kindWords, $person, $because]) {
        $token   = $whoSees($event, EventVisibility::MODE_TOKEN);
        $session = array_values(array_diff($whoSees($event, EventVisibility::MODE_SESSION), $admins));
        $column  = $columnWho($event, EventVisibility::MODE_TOKEN);
        check("R9 {$event}, listed only for {$kindWords} numbered {$sameNumber}: {$person} ({$because}) sees it, and none of the five others whose own thing carries the same number — in token mode, signed in, and with the viewer taken from a column",
            $token === [$person] && $session === [$person] && $column === [$person],
            'token ' . setText($token) . ' session, administrators aside, ' . setText($session) . ' column ' . setText($column));
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
        // The audience piece's true letters are 'VVVTTVVV' since part P10.
        'the audience piece missing one of its two date letters'     => static function (array $p): array { $p['audience']['binds'] = 'VVVTVVV'; return $p; },
        'the audience piece with one viewer letter too FEW for the role, user group and department tests (part P10, check R4)'  => static function (array $p): array { $p['audience']['binds'] = 'VVVTTVV'; return $p; },
        'the audience piece with one viewer letter too MANY (part P10, check R4)' => static function (array $p): array { $p['audience']['binds'] = 'VVVTTVVVV'; return $p; },
        'the audience piece still carrying the letters it had before part P10' => static function (array $p): array { $p['audience']['binds'] = 'VVVTT'; return $p; },
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
