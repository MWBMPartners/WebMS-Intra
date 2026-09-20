<?php
// Path: tools/attendance-access-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Who may see the attendance reports page — self-test 📊 (#529)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free: no database, no network, no start-up code.
 * It loads the real `Portal\Core\AttendanceAccess` class and exercises the two
 * things in it that are pure decisions rather than queries:
 *
 *   - turning whatever is stored into one of the three choices (choice());
 *   - who may see the reports, for every combination of choice, membership,
 *     being an administrator, and coordinating any of the organisation's
 *     events (mayView()) — all 25 rows of the table written out by hand.
 *
 * WHY THESE TWO AND NOT THE DATABASE-READING METHODS
 * ----------------------------------------------------
 * `viewerIsMember()` and `coordinatesAnyEvent()` need a real connection, and
 * are proved against one separately when this package is built. These two
 * are where a mistake would be silent: a wrong answer from either looks
 * exactly like a right one, because the output is a plain yes/no with
 * nothing on the page to compare it against.
 *
 * ITS OWN CONTROL, so this file cannot quietly stop testing anything: run it
 * once as written (expected: all pass), then edit ONE expected value in the
 * decision table below to something wrong, run again (expected: exactly one
 * failure, naming that row), then put the correct value back. Both runs are
 * recorded in the build report, not just this file's own pass/fail count.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * It proves the decision. It does NOT prove that `report.php` (or any other
 * page) actually calls it, that the membership query is right, or that the
 * coordinator query excludes a revoked coordinator. Those need a real
 * database — see the settled plan's §6.6 proof.
 *
 * Usage:  php tools/attendance-access-selftest.php
 * Exit:   0 if every check passes, 1 if any fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/529
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// The class is loaded on its own. It has no constructor, touches no
// superglobal and connects to nothing until one of its database-reading
// methods is actually called — so `choice()` and `mayView()` need nothing
// else loaded alongside it, exactly like AnonymousCheckins.
require_once __DIR__ . '/../web/_core/AttendanceAccess.php';

use Portal\Core\AttendanceAccess;

$failures = 0;
$passes   = 0;

/**
 * ✅ Report one check.
 *
 * @param string $what   What is being checked, in plain words.
 * @param bool   $isOk   Whether it passed.
 * @param string $detail Shown only on failure.
 *
 * @return void
 */
function check(string $what, bool $isOk, string $detail = ''): void
{
    global $failures, $passes;
    if ($isOk === true) {
        $passes++;
        printf("  PASS  %s\n", $what);
        return;
    }
    $failures++;
    printf("  FAIL  %s\n", $what);
    if ($detail !== '') {
        printf("          %s\n", $detail);
    }
}

echo "\n";
echo str_repeat('=', 78) . "\n";
echo "Who may see the attendance reports page — self-test (#529)\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------------
// 1. Reading the setting: anything unrecognised must come back as the narrowest
// -----------------------------------------------------------------------------
echo "1. Turning whatever is stored into one of the three choices\n";

check('nothing stored at all reads as "administrators only"', AttendanceAccess::choice(null) === 'admins');
check('an empty value reads as "administrators only"', AttendanceAccess::choice('') === 'admins');
check('a value with only spaces reads as "administrators only"', AttendanceAccess::choice('   ') === 'admins');
check(
    'the wrong capitals ("Members") read as "administrators only", not as a match',
    AttendanceAccess::choice('Members') === 'admins'
);
check('nonsense reads as "administrators only"', AttendanceAccess::choice('nonsense') === 'admins');
check(
    'the leftover value "everyone" (a plausible typo for "members") reads as "administrators only"',
    AttendanceAccess::choice('everyone') === 'admins',
    'A hand-edited stored value must fail closed, not widen anything.'
);

foreach (['admins', 'admins_coordinators', 'members'] as $valid) {
    check(
        sprintf('the real choice "%s" comes back unchanged', $valid),
        AttendanceAccess::choice($valid) === $valid
    );
}

check(
    'surrounding spaces are ignored, so a value pasted with a space still works',
    AttendanceAccess::choice('  members  ') === 'members'
);
check(
    'there are exactly three choices and no more',
    count(AttendanceAccess::VISIBILITY_CHOICES) === 3,
    'Found: ' . implode(', ', array_keys(AttendanceAccess::VISIBILITY_CHOICES))
);

// -----------------------------------------------------------------------------
// 2. Who may see the reports — all 25 rows of the table, written out in full
// -----------------------------------------------------------------------------
echo "\n2. Who may see the reports\n";

/**
 * One expected row of the decision table.
 *
 * Written out by hand, not computed in a loop — a loop that worked out the
 * expected answer would be a second copy of the code being tested, and the
 * two would agree even when both were wrong.
 *
 * Columns: choice, is a member (or global administrator), is an
 * administrator, coordinates any of this organisation's events, expected
 * answer, why.
 */
$decisionRows = [
    // ── Not a member at all: refused whatever the choice says ──────────────
    ['admins',              false, false, false, false, 'not a member — refused whatever the choice says'],
    ['admins',              false, true,  false, false, 'not a member — even an "administrator" flag with no membership row here is refused'],
    ['admins_coordinators', false, false, true,  false, 'not a member — refused even though they coordinate an event elsewhere'],
    ['members',             false, false, false, false, 'not a member — the widest choice still requires membership'],
    ['nonsense',            false, true,  false, false, 'not a member — refused before the choice is even normalised'],

    // ── admins ───────────────────────────────────────────────────────────
    ['admins', true, true,  false, true,  'administrators always see the figures'],
    ['admins', true, true,  true,  true,  'administrators always see the figures, coordinator or not'],
    ['admins', true, false, true,  false, 'a coordinator is not included by "administrators only"'],
    ['admins', true, false, false, false, 'an ordinary member is not included by "administrators only"'],

    // ── admins_coordinators ─────────────────────────────────────────────
    ['admins_coordinators', true, true,  false, true,  'an administrator who coordinates nothing still sees it'],
    ['admins_coordinators', true, true,  true,  true,  'administrator and coordinator both'],
    ['admins_coordinators', true, false, true,  true,  'a coordinator of one of this organisation\'s events is included'],
    ['admins_coordinators', true, false, false, false, 'a member who is neither an administrator nor a coordinator is not'],

    // ── members ──────────────────────────────────────────────────────────
    ['members', true, true,  false, true, 'administrators see it too'],
    ['members', true, true,  true,  true, 'administrators see it too, coordinator or not'],
    ['members', true, false, true,  true, 'any member sees it — coordinator or not makes no difference'],
    ['members', true, false, false, true, 'any member sees it'],

    // ── An unrecognised stored value, for a member this time ────────────
    ['nonsense', true, false, true,  false, 'an unrecognised value falls back to administrators only'],
    ['nonsense', true, true,  false, true,  'an unrecognised value still lets an administrator see it'],
    ['nonsense', true, false, false, false, 'an unrecognised value falls back to administrators only'],

    // ── The empty string and a value with only spaces, both as members ──
    ['',    true, false, false, false, 'an empty stored value falls back to administrators only'],
    ['',    true, true,  false, true,  'an empty stored value still lets an administrator see it'],
    ['   ', true, false, true,  false, 'spaces-only falls back to administrators only, coordinator or not'],

    // ── The exact wrong capitalisation of a real choice ──────────────────
    ['Members',             true, false, false, false, 'wrong capitals do not match "members" — falls back to administrators only'],
    ['Admins_Coordinators', true, false, true,  false, 'wrong capitals do not match "admins_coordinators" either'],
];

foreach ($decisionRows as $row) {
    [$choice, $isMember, $isAdmin, $coord, $expected, $why] = $row;

    $actual = AttendanceAccess::mayView($choice, $isMember, $isAdmin, $coord);

    check(
        sprintf(
            '%-20s | member %-5s | admin %-5s | coordinator %-5s => %-6s (%s)',
            $choice === '' ? '(empty)' : $choice,
            $isMember === true ? 'yes' : 'no',
            $isAdmin === true ? 'yes' : 'no',
            $coord === true ? 'yes' : 'no',
            $expected === true ? 'shown' : 'hidden',
            $why
        ),
        $actual === $expected,
        sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
    );
}

check(
    'the table above has all 25 rows it claims to',
    count($decisionRows) === 25,
    'Found ' . count($decisionRows) . ' rows — update this count if a row is deliberately added or removed.'
);

// A non-member must NEVER be shown the reports, whatever is stored and
// whatever else is true about them — checked separately as a blanket
// statement, because it is the very first rule #529 exists to enforce and
// deserves to fail loudly and by name if it is ever undone.
$nonMemberAlwaysRefused = true;
foreach (['admins', 'admins_coordinators', 'members', 'nonsense', ''] as $anyChoice) {
    foreach ([true, false] as $isAdmin) {
        foreach ([true, false] as $coord) {
            if (AttendanceAccess::mayView($anyChoice, false, $isAdmin, $coord) !== false) {
                $nonMemberAlwaysRefused = false;
            }
        }
    }
}
check(
    'a non-member is NEVER shown the reports, whatever is stored and whatever else is true about them',
    $nonMemberAlwaysRefused === true,
    'This is #529\'s first rule — the setting may only narrow who AMONG an organisation\'s own '
        . 'members sees the figures, never open the page to a stranger.'
);

// -----------------------------------------------------------------------------
// 3. The setting key matches what the migration seeds
// -----------------------------------------------------------------------------
echo "\n3. The setting key written into the class\n";

check(
    'the setting key is the one migration 200 seeds',
    AttendanceAccess::VISIBILITY_KEY === 'attend.reports.visibleTo',
    'If this drifts from the migration, every screen silently falls back to the narrowest choice.'
);
check(
    'the key sits in the same attend. family as #525\'s own setting, on purpose',
    str_starts_with(AttendanceAccess::VISIBILITY_KEY, 'attend.'),
    'So the two settings sit together in the editor and a future #526 resolver can extend both alike.'
);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nSomething about who may see the attendance reports page has changed and is\n";
    echo "now wrong. Check web/_core/AttendanceAccess.php before releasing anything.\n";
    exit(1);
}

echo "\nAll good. The decision behaves as written down.\n";
exit(0);
