<?php
// Path: tools/memberships-selftest.php
/**
 * -----------------------------------------------------------------------------
 * User groups and departments self-test 👥🏢 (#517)
 * -----------------------------------------------------------------------------
 * Standalone, dependency-free (no database, no bootstrap, no network)
 * regression guard for the PURE parts of `Portal\Core\UserGroups` and
 * `Portal\Core\Departments` — the two classes that make user groups and
 * departments belong to one organisation each. See each class's own
 * docblock for the full "why".
 *
 * It requires the REAL class files rather than reimplementing their rules,
 * and calls only what needs nothing but its own arguments:
 *   - UserGroups::memberSql() / Departments::memberSql() — the exact SQL
 *     fragments #514's shared-calendar audience query will drop into a
 *     WHERE clause. Each checks its three identifier arguments FIRST, so
 *     even the "throws" assertions below need no database connection.
 *   - Departments::FLAGS — the five flags, checked against the five flag
 *     columns of `tblUserDepts`, with the two "recorded only" flags
 *     required to SAY that nothing acts on them yet (so the pages and the
 *     help page, which render these descriptions, never promise a feature
 *     that does not exist).
 *   - Departments::normaliseFlags() — the pure flag cleaner.
 *
 * WHAT THIS CANNOT PROVE: that the fragments give the right answers
 * against a real database, or that adding, removing, retiring and deleting
 * behave — that needs real tables, and is proved by the #517 build's
 * real-database proofs (5.1-5.14 of the plan). This file only proves the
 * pure half never regresses, as `tools/roles-selftest.php` does for #516.
 *
 * Usage:  php tools/memberships-selftest.php
 * Exit:   0 on success (all assertions pass), 1 on any failure.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (defined('PORTAL_CORE') === false) {
    define('PORTAL_CORE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core');
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'UserGroups.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'Departments.php';

use Portal\Core\Departments;
use Portal\Core\UserGroups;

$failures = 0;

function assertTrue(string $label, bool $cond): void
{
    global $failures;
    echo ($cond === true ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
    if ($cond === false) {
        $failures++;
    }
}

function expectThrows(string $label, callable $fn, string $expectedClass = \InvalidArgumentException::class): void
{
    global $failures;
    try {
        $fn();
        echo 'FAIL — ' . $label . ' (no exception thrown)' . "\n";
        $failures++;
    } catch (\Throwable $e) {
        if ($e instanceof $expectedClass) {
            echo 'PASS — ' . $label . ' -> ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        } else {
            echo 'FAIL — ' . $label . ' (wrong exception type: ' . get_class($e) . ': ' . $e->getMessage() . ')' . "\n";
            $failures++;
        }
    }
}

// -----------------------------------------------------------------------------
// 👥 UserGroups::memberSql() — the text is compared EXACTLY, because #514's
//    plan quotes it and its own proofs depend on this precise shape: the
//    group's organisation matched, the organisation membership active, and
//    the group not retired.
// -----------------------------------------------------------------------------
echo "=== UserGroups::memberSql() — the exact SQL fragment #514 will call ===\n";
$groupSql = 'EXISTS (SELECT 1 FROM tblGroups g17 JOIN tblUserGroups ug17 ON ug17.groupID = g17.groupID AND ug17.siteID = g17.siteID '
    . 'JOIN tblUserSites us17 ON us17.userID = ug17.userID AND us17.siteID = ug17.siteID AND us17.isActive = 1 '
    . 'WHERE ug17.userID = V AND ug17.groupID = am.refID AND ug17.siteID = E.siteID AND g17.isActive = 1)';
assertTrue(
    "memberSql('V', 'am.refID', 'E.siteID') matches the plan's exact text",
    UserGroups::memberSql('V', 'am.refID', 'E.siteID') === $groupSql
);
assertTrue(
    "memberSql('?', '?', '?') works (every argument may be the bind placeholder)",
    UserGroups::memberSql('?', '?', '?') === str_replace(
        ['= V ', '= am.refID ', '= E.siteID '],
        ['= ? ', '= ? ', '= ? '],
        $groupSql
    )
);
$actualGroupSql = UserGroups::memberSql('V', 'am.refID', 'E.siteID');
assertTrue('the group fragment tests the group is not retired', str_contains($actualGroupSql, 'g17.isActive = 1'));
assertTrue('the group fragment tests the organisation membership is active', str_contains($actualGroupSql, 'us17.isActive = 1'));

// -----------------------------------------------------------------------------
// 🏢 Departments::memberSql()
// -----------------------------------------------------------------------------
echo "\n=== Departments::memberSql() — the exact SQL fragment #514 will call ===\n";
$deptSql = 'EXISTS (SELECT 1 FROM tblDepts d17 JOIN tblUserDepts ud17 ON ud17.deptID = d17.deptID AND ud17.siteID = d17.siteID '
    . 'JOIN tblUserSites us17 ON us17.userID = ud17.userID AND us17.siteID = ud17.siteID AND us17.isActive = 1 '
    . 'WHERE ud17.userID = V AND ud17.deptID = am.refID AND ud17.siteID = E.siteID AND d17.isActive = 1)';
assertTrue(
    "memberSql('V', 'am.refID', 'E.siteID') matches the plan's exact text",
    Departments::memberSql('V', 'am.refID', 'E.siteID') === $deptSql
);
assertTrue(
    "memberSql('?', '?', '?') works (every argument may be the bind placeholder)",
    Departments::memberSql('?', '?', '?') === str_replace(
        ['= V ', '= am.refID ', '= E.siteID '],
        ['= ? ', '= ? ', '= ? '],
        $deptSql
    )
);
$actualDeptSql = Departments::memberSql('V', 'am.refID', 'E.siteID');
assertTrue('the department fragment tests the department is not retired', str_contains($actualDeptSql, 'd17.isActive = 1'));
assertTrue('the department fragment tests the organisation membership is active', str_contains($actualDeptSql, 'us17.isActive = 1'));

// -----------------------------------------------------------------------------
// 🧨 Hostile arguments — each of the three positions, in each class, must
//    throw BEFORE any SQL text exists (no database is loaded in this file
//    at all, so a throw here cannot have come from a query).
// -----------------------------------------------------------------------------
echo "\n=== Hostile arguments throw, in every position, in both classes ===\n";
$bad = ['V; DROP TABLE tblUsers', '1=1', ''];
$classes = [
    'UserGroups'  => static fn (string $a, string $b, string $c): string => UserGroups::memberSql($a, $b, $c),
    'Departments' => static fn (string $a, string $b, string $c): string => Departments::memberSql($a, $b, $c),
];
foreach ($classes as $className => $call) {
    foreach ($bad as $value) {
        foreach ([0, 1, 2] as $position) {
            $args = ['x', 'y', 'z'];
            $args[$position] = $value;
            expectThrows(
                "{$className}::memberSql() with '" . $value . "' in position " . ($position + 1) . ' throws',
                static function () use ($call, $args): void {
                    $call($args[0], $args[1], $args[2]);
                }
            );
        }
    }
}

// -----------------------------------------------------------------------------
// 🏷️ Departments::FLAGS — exactly the five flag columns of tblUserDepts
// -----------------------------------------------------------------------------
echo "\n=== Departments::FLAGS ===\n";
$schemaFlags = ['isDeptLead', 'isDeptAssistant', 'isDeptSecretary', 'isApprover', 'isMandatoryApprover'];
$flagKeys    = array_keys(Departments::FLAGS);
$sortedFlags = $flagKeys;
sort($sortedFlags);
$sortedSchema = $schemaFlags;
sort($sortedSchema);
assertTrue('FLAGS has exactly the five flag columns of tblUserDepts', $sortedFlags === $sortedSchema);
foreach (Departments::FLAGS as $key => $flag) {
    assertTrue("flag '{$key}' has a non-empty label", is_string($flag['label'] ?? null) && $flag['label'] !== '');
    assertTrue("flag '{$key}' has a non-empty description", is_string($flag['description'] ?? null) && $flag['description'] !== '');
}
foreach (['isDeptAssistant', 'isDeptSecretary'] as $recordedOnly) {
    assertTrue(
        "the '{$recordedOnly}' description says nothing in the portal acts on it yet",
        str_contains(Departments::FLAGS[$recordedOnly]['description'], 'nothing in the portal acts on it yet')
    );
}

// Cross-check against the real fresh-install script, so a sixth flag column
// added later without a FLAGS entry (or the reverse) fails here.
$schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_sql' . DIRECTORY_SEPARATOR . 'full_schema.sql';
$schemaText = (string) file_get_contents($schemaFile);
$start = strpos($schemaText, 'CREATE TABLE IF NOT EXISTS `tblUserDepts`');
$block = $start === false ? '' : substr($schemaText, $start, (int) strpos($schemaText, ') ENGINE=', $start) - $start);
preg_match_all('/`(is[A-Za-z]+)`\s+TINYINT\(1\)/', $block, $m);
$fromSchema = $m[1];
sort($fromSchema);
assertTrue(
    'the flag columns of tblUserDepts in full_schema.sql are exactly the keys of FLAGS (found: ' . implode(', ', $fromSchema) . ')',
    $fromSchema === $sortedFlags
);

// -----------------------------------------------------------------------------
// 🧹 Departments::normaliseFlags()
// -----------------------------------------------------------------------------
echo "\n=== Departments::normaliseFlags() ===\n";
$n = Departments::normaliseFlags(['isApprover' => '1', 'isDeptLead' => 1, 'isDeptSecretary' => true, 'isDeptAssistant' => 'on', 'bogus' => '1']);
assertTrue("'1', 1 and true are on", $n['isApprover'] === 1 && $n['isDeptLead'] === 1 && $n['isDeptSecretary'] === 1);
assertTrue("any other value ('on') is off — forms post value=\"1\"", $n['isDeptAssistant'] === 0);
assertTrue('a missing key is off', $n['isMandatoryApprover'] === 0);
assertTrue('a key that is not a flag is dropped', array_key_exists('bogus', $n) === false);
assertTrue('exactly the five keys come back', count($n) === 5);
assertTrue('NULL (a hand-edited row) is off', Departments::normaliseFlags(['isApprover' => null])['isApprover'] === 0);

echo "\n" . ($failures === 0 ? "ALL PASS ({$failures} failures)\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
