<?php
// Path: tools/anon-checkins-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Anonymous check-in figures — self-test 🚪 (#525)
 * -----------------------------------------------------------------------------
 * Standalone and dependency-free: no database, no network, no start-up code.
 * It loads the real `Portal\Core\AnonymousCheckins` class and exercises the two
 * things in it that are pure decisions rather than queries:
 *
 *   - who may see the figures, for every combination of the three settings
 *     choices, the page's own rule, being an administrator, being that event's
 *     coordinator, and whether the screen is about one event or the whole
 *     organisation;
 *   - the "probably unique senders" arithmetic, including what happens after a
 *     day's detail has been cleared and a late check-in arrives for it.
 *
 * WHY THESE TWO AND NOT THE QUERIES
 * ---------------------------------
 * The queries need a database, and the database proofs are run separately when
 * this package is built and checked. These two are where a mistake would be
 * silent: a wrong answer from either looks exactly like a right one, because
 * the output is a number or a yes/no with nothing to compare it against.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 * It proves the decision and the arithmetic. It does NOT prove that any screen
 * asks the question, that the organisation check in the SQL works, or that the
 * clear-out really empties anything. Those need a real database.
 *
 * Usage:  php tools/anon-checkins-selftest.php
 * Exit:   0 if every check passes, 1 if any fails.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/525
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// The class is loaded on its own. It has no constructor, touches no
// superglobal and connects to nothing, so nothing else has to be loaded with
// it — which is exactly why it was written that way.
require_once __DIR__ . '/../web/_core/AnonymousCheckins.php';

use Portal\Core\AnonymousCheckins;

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
echo "Anonymous check-in figures — self-test (#525)\n";
echo str_repeat('=', 78) . "\n\n";

// -----------------------------------------------------------------------------
// 1. Reading the setting: anything unrecognised must come back as the narrowest
// -----------------------------------------------------------------------------
echo "1. Turning whatever is stored into one of the three choices\n";

check(
    'nothing stored at all reads as "administrators only"',
    AnonymousCheckins::visibilityChoice(null) === 'admins'
);
check(
    'an empty value reads as "administrators only"',
    AnonymousCheckins::visibilityChoice('') === 'admins'
);
check(
    'a value with only spaces reads as "administrators only"',
    AnonymousCheckins::visibilityChoice('   ') === 'admins'
);
check(
    'the wrong capitals ("Admins") read as "administrators only", not as a match',
    AnonymousCheckins::visibilityChoice('Admins') === 'admins'
);
check(
    'nonsense reads as "administrators only"',
    AnonymousCheckins::visibilityChoice('nonsense') === 'admins'
);
check(
    'the dropped fourth choice ("coordinators") reads as "administrators only"',
    AnonymousCheckins::visibilityChoice('coordinators') === 'admins',
    'A value left behind by the four-choice draft must fail closed, not widen anything.'
);
check(
    'the old four-choice spelling "admins_and_coordinators" reads as "administrators only"',
    AnonymousCheckins::visibilityChoice('admins_and_coordinators') === 'admins'
);
check(
    'the old four-choice spelling "same_as_page" reads as "administrators only"',
    AnonymousCheckins::visibilityChoice('same_as_page') === 'admins'
);

foreach (['admins', 'admins_coordinators', 'page'] as $valid) {
    check(
        sprintf('the real choice "%s" comes back unchanged', $valid),
        AnonymousCheckins::visibilityChoice($valid) === $valid
    );
}

check(
    'surrounding spaces are ignored, so a value pasted with a space still works',
    AnonymousCheckins::visibilityChoice('  page  ') === 'page'
);
check(
    'there are exactly three choices and no more',
    count(AnonymousCheckins::VISIBILITY_CHOICES) === 3,
    'Found: ' . implode(', ', array_keys(AnonymousCheckins::VISIBILITY_CHOICES))
);

// -----------------------------------------------------------------------------
// 2. Who may see the figures — every row of the table, written out in full
// -----------------------------------------------------------------------------
echo "\n2. Who may see the figures\n";

/**
 * One expected row of the decision table.
 *
 * Every combination is written out by hand rather than worked out in a loop.
 * A loop that computed the expected answer would be a second copy of the code
 * being tested, and the two would agree even when both were wrong.
 *
 * Columns: choice, page's own rule let them in, administrator, coordinator of
 * this event, screen is about one event, expected answer, why.
 */
$decisionRows = [
    // ── The page's own rule said no. Nothing may change that. ──────────────
    ['admins',              false, true,  true,  true,  false, 'the page itself refused them'],
    ['admins',              false, false, false, false, false, 'the page itself refused them'],
    ['admins_coordinators', false, true,  true,  true,  false, 'the page itself refused them'],
    ['admins_coordinators', false, false, true,  true,  false, 'the page itself refused them'],
    ['page',                false, true,  true,  true,  false, 'the page itself refused them'],
    ['page',                false, false, false, true,  false, 'the page itself refused them'],
    ['page',                false, true,  false, false, false, 'the page itself refused them'],
    ['admins',              false, true,  false, false, false, 'the page itself refused them'],

    // ── One event's screen ────────────────────────────────────────────────
    ['admins',              true,  true,  false, true,  true,  'administrators always see the figures'],
    ['admins',              true,  true,  true,  true,  true,  'administrators always see the figures'],
    ['admins',              true,  false, true,  true,  false, 'a coordinator is not included by this choice'],
    ['admins',              true,  false, false, true,  false, 'nobody else is included by this choice'],

    ['admins_coordinators', true,  true,  false, true,  true,  'an administrator who does not run the event still sees it'],
    ['admins_coordinators', true,  true,  true,  true,  true,  'administrator and coordinator'],
    ['admins_coordinators', true,  false, true,  true,  true,  'this event\'s coordinator is included by this choice'],
    ['admins_coordinators', true,  false, false, true,  false, 'somebody who is neither is not'],

    ['page',                true,  true,  false, true,  true,  'the page let them in, and that is the whole test'],
    ['page',                true,  true,  true,  true,  true,  'the page let them in'],
    ['page',                true,  false, true,  true,  true,  'the page let them in'],
    ['page',                true,  false, false, true,  true,  'the page let them in'],

    // ── The whole organisation's screen: there is no event to coordinate ──
    ['admins',              true,  true,  false, false, true,  'administrators always see the figures'],
    ['admins',              true,  false, false, false, false, 'nobody else is included by this choice'],

    ['admins_coordinators', true,  true,  false, false, true,  'administrators always see the figures'],
    ['admins_coordinators', true,  false, true,  false, false, 'there is no single event here, so "this event\'s coordinator" cannot be tested'],
    ['admins_coordinators', true,  false, false, false, false, 'somebody who is neither is not included'],

    ['page',                true,  true,  false, false, true,  'the page let them in'],
    ['page',                true,  false, false, false, true,  'the page let them in'],
    ['page',                true,  false, true,  false, true,  'the page let them in'],

    // ── A value that should never be stored, on both kinds of screen ──────
    ['nonsense',            true,  false, true,  true,  false, 'an unrecognised value falls back to administrators only'],
    ['nonsense',            true,  true,  false, true,  true,  'an unrecognised value still lets an administrator see it'],
    ['nonsense',            true,  false, false, false, false, 'an unrecognised value falls back to administrators only'],
];

foreach ($decisionRows as $row) {
    [$choice, $gate, $isAdmin, $isCoord, $eventScoped, $expected, $why] = $row;

    $actual = AnonymousCheckins::mayView($choice, $gate, $isAdmin, $isCoord, $eventScoped);

    check(
        sprintf(
            '%-19s | page gate %-5s | admin %-5s | coordinator %-5s | %-16s => %-5s (%s)',
            $choice,
            $gate === true ? 'yes' : 'no',
            $isAdmin === true ? 'yes' : 'no',
            $isCoord === true ? 'yes' : 'no',
            $eventScoped === true ? 'one event' : 'whole organisation',
            $expected === true ? 'shown' : 'hidden',
            $why
        ),
        $actual === $expected,
        sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
    );
}

// An administrator must never be refused, whatever is stored. Checked
// separately as a blanket statement, because it is the owner's decision of
// 18 September 2026 and deserves to fail loudly and by name if it is ever
// undone.
$adminAlways = true;
foreach (['admins', 'admins_coordinators', 'page', 'nonsense', ''] as $anyChoice) {
    foreach ([true, false] as $eventScoped) {
        foreach ([true, false] as $isCoord) {
            if (AnonymousCheckins::mayView($anyChoice, true, true, $isCoord, $eventScoped) !== true) {
                $adminAlways = false;
            }
        }
    }
}
check(
    'an administrator who passed the page\'s own rule is NEVER refused, whatever is stored',
    $adminAlways === true,
    'This is the owner\'s decision of 18 September 2026 (answer B1).'
);

// -----------------------------------------------------------------------------
// 3. The "probably unique senders" arithmetic
// -----------------------------------------------------------------------------
echo "\n3. Working out the \"probably unique senders\" figure\n";

check(
    'nothing stored, four different connections, no rows missing a scramble => 4',
    AnonymousCheckins::sendersForDay(null, null, 4, 0) === 4
);
check(
    'nothing stored, four connections plus two rows that never had a scramble => 6',
    AnonymousCheckins::sendersForDay(null, null, 4, 2) === 6,
    'A row with no scramble counts as its own sender, which over-counts rather than under-counts.'
);
check(
    'nothing stored and nothing at all => 0',
    AnonymousCheckins::sendersForDay(null, null, 0, 0) === 0
);
check(
    'a stored figure of 7 from 9 rows, all nine now cleared, nothing new => 7',
    AnonymousCheckins::sendersForDay(7, 9, 0, 9) === 7,
    'This is the whole point: the figure must not change when the detail goes.'
);
check(
    'the same day plus two late check-ins whose detail is still there => 9',
    AnonymousCheckins::sendersForDay(7, 9, 2, 9) === 9
);
check(
    'the same day plus one late check-in that never had a scramble => 8',
    AnonymousCheckins::sendersForDay(7, 9, 0, 10) === 8,
    'Ten rows without a scramble, nine of them accounted for by the stored figure, so one is new.'
);
check(
    'a stored figure claiming more rows than exist clamps at 0 extra, never negative',
    AnonymousCheckins::sendersForDay(7, 20, 0, 9) === 7
);
check(
    'a negative stored figure cannot drag the answer below zero',
    AnonymousCheckins::sendersForDay(-5, 9, 0, 9) === 0
);

// -----------------------------------------------------------------------------
// 4. Marking a day that gained check-ins after its figure was stored
// -----------------------------------------------------------------------------
echo "\n4. Marking a day whose figure includes late arrivals\n";

check(
    'a day with no stored figure is never marked',
    AnonymousCheckins::lateArrivalsOnDay(false, false, null, 5, 2) === false,
    'Its figure is worked out live and is as good as it will ever be.'
);
check(
    'a stored day with nothing added since is not marked',
    AnonymousCheckins::lateArrivalsOnDay(true, false, 9, 0, 9) === false
);
check(
    'a stored day with a late check-in still holding its detail IS marked',
    AnonymousCheckins::lateArrivalsOnDay(true, false, 9, 1, 9) === true,
    'Caught before the clear-out next runs, from the live rows themselves.'
);
check(
    'a stored day with a late check-in that never had a scramble IS marked',
    AnonymousCheckins::lateArrivalsOnDay(true, false, 9, 0, 10) === true
);
check(
    'a stored day already flagged stays marked even with nothing live left',
    AnonymousCheckins::lateArrivalsOnDay(true, true, 12, 0, 12) === true,
    'Caught after the clear-out has folded the late arrivals in and removed the evidence.'
);
check(
    'a stored day whose flag is on is marked even if the numbers look tidy',
    AnonymousCheckins::lateArrivalsOnDay(true, true, 9, 0, 9) === true
);

// -----------------------------------------------------------------------------
// 5. Reading the number of days of detail to keep
// -----------------------------------------------------------------------------
echo "\n5. The retention default written into the class\n";

check(
    'the default number of days is 90',
    AnonymousCheckins::DEFAULT_RETENTION_DAYS === 90
);
check(
    'the setting keys are the ones the migration seeds',
    AnonymousCheckins::VISIBILITY_KEY === 'attend.anonCounts.visibleTo'
    && AnonymousCheckins::RETENTION_KEY === 'attend.detailRetentionDays',
    'If these drift from the migration, every screen silently falls back to the narrowest choice.'
);

// -----------------------------------------------------------------------------
// 6. Nothing here may ever return the scrambled address, and the old
//    browser-description column is not spoken of by name any more
// -----------------------------------------------------------------------------
echo "\n6. The remaining piece of personal detail never leaves the class\n";

$source = (string) file_get_contents(__DIR__ . '/../web/_core/AnonymousCheckins.php');

// Migration 201 (#530) dropped the browser-description column entirely — it
// is not merely unused, it does not exist. This used to be two separate
// assertions (one checking no method SELECTs it as a value, one checking the
// SET/IS NOT NULL shape it was allowed to appear in) because the column was
// still there to be careful about. Now there is nothing to be careful about:
// the class should never mention that column's name at all, by any route —
// a plain substring check catches every one of the old assertions' cases at
// once, and catches a re-introduction that neither of them would have (for
// example, a comment or a variable name reusing it).
check(
    'the class never mentions the browser-description column, which migration 201 removed (#530)',
    strpos($source, 'userAgent') === false,
    'Found "userAgent" in AnonymousCheckins.php — that column no longer exists (#530).'
);
check(
    'no method selects the scrambled address as a value',
    strpos($source, 'ipHash AS') === false
    && preg_match('/SELECT\s+tblAnonymousCheckins\.ipHash/i', $source) !== 1,
    'Found something that reads like a plain select of ipHash.'
);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d passed, %d failed\n", $passes, $failures);

if ($failures > 0) {
    echo "\nSomething about who may see the anonymous check-in figures, or about how\n";
    echo "the \"probably unique senders\" figure is worked out, has changed and is now\n";
    echo "wrong. Check web/_core/AnonymousCheckins.php before releasing anything.\n";
    exit(1);
}

echo "\nAll good. The decision and the arithmetic both behave as written down.\n";
exit(0);
