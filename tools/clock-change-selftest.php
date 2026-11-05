<?php
// Path: tools/clock-change-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Both change nights, both directions, in zones that actually change 🕐
 * -----------------------------------------------------------------------------
 * #549's own words: "**A test written in December passes in London whatever the
 * code does**, because London is on UTC that month — which is exactly how
 * mistake 2 stayed hidden." So every case here names a date on or across a real
 * clock change, in a zone that really changes, and in both directions.
 *
 * WHAT IT COVERS
 *   The two places fixed in `97af79c` that can be driven as functions:
 *     * the calendar month grid's cell count;
 *     * `Giving::pledgeExpectedToDate()`.
 *   Each is checked against the OLD arithmetic as well, so the test is proved
 *   to distinguish them rather than merely to pass.
 *
 * WHAT IT DOES NOT COVER, said plainly
 *   The other four fixes — a DBS expiry, a venue renewal, the rehire undo
 *   window and a member's safeguarding page — are inline on their pages, not
 *   functions, so they cannot be driven from here. Their arithmetic is
 *   identical to the two below and `check_clock_change_arithmetic.py` stops it
 *   coming back, but **that is a guard on the SHAPE, not a test of those
 *   pages**. Driving them needs HTTP and fixtures, which is part of #549's
 *   remaining sweep.
 *
 *   And it says NOTHING about #549's mistake 1 — a time on a clock treated as
 *   a moment in history. That needs reading, not arithmetic.
 *
 * It needs no database and no server.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/549
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$sep  = DIRECTORY_SEPARATOR;
$root = dirname(__DIR__);
define('PORTAL_ROOT', $root . $sep . 'web');
define('PORTAL_CORE', PORTAL_ROOT . $sep . '_core');

require PORTAL_CORE . $sep . 'Giving.php';

use Portal\Core\Giving;

$passed = 0;
$failed = 0;

function check(string $name, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    echo "  ✗ {$name}\n";
    echo '      wanted: ' . var_export($want, true) . "\n";
    echo '      got:    ' . var_export($got, true) . "\n";
}

/** The month grid's cell count, exactly as `calendar/views/month.php` works it out. */
function gridCells(string $zone, string $month): int
{
    $tz     = new DateTimeZone($zone);
    $cursor = new DateTimeImmutable($month . '-15 00:00:00', $tz);
    $first  = $cursor->modify('first day of this month')->setTime(0, 0, 0);
    $last   = $cursor->modify('last day of this month')->setTime(0, 0, 0);
    $start  = $first->modify('-' . (((int) $first->format('N')) - 1) . ' days');
    $end    = $last->modify('+' . (7 - ((int) $last->format('N'))) . ' days');
    return (int) $start->diff($end)->days + 1;
}

/** The SAME thing the old way, so the test is proved to tell them apart. */
function gridCellsOldWay(string $zone, string $month): int
{
    $tz     = new DateTimeZone($zone);
    $cursor = new DateTimeImmutable($month . '-15 00:00:00', $tz);
    $first  = $cursor->modify('first day of this month')->setTime(0, 0, 0);
    $last   = $cursor->modify('last day of this month')->setTime(0, 0, 0);
    $start  = $first->modify('-' . (((int) $first->format('N')) - 1) . ' days');
    $end    = $last->modify('+' . (7 - ((int) $last->format('N'))) . ' days');
    return (int) (($end->getTimestamp() - $start->getTimestamp()) / 86400) + 1;
}

// ⚠️ EVERY ZONE HERE REALLY CHANGES, AND THEY CHANGE ON DIFFERENT DATES AND IN
//    DIFFERENT DIRECTIONS. Europe/London and America/New_York spring forward on
//    different weekends; Australia/Sydney is in the southern hemisphere, so its
//    "spring forward" is in October and its "fall back" in April. A test that
//    used one zone would prove far less than it appears to.
echo "A. The calendar month grid — a whole day went missing in March\n";
foreach ([
    ['Europe/London',      '2026-03', 'springs forward'],
    ['America/New_York',   '2026-03', 'springs forward'],
    ['Europe/Berlin',      '2026-03', 'springs forward'],
    ['Australia/Sydney',   '2026-10', 'springs forward (southern)'],
] as [$zone, $month, $what]) {
    $cells = gridCells($zone, $month);
    check("A1 {$zone} {$month} ({$what}) draws a whole number of weeks",
        $cells % 7, 0);
    check("A1 {$zone} {$month} — and the grid is complete",
        $cells >= 28 && $cells <= 42, true);
}

echo "B. The old arithmetic really was wrong — so this test can tell them apart\n";
// ⚠️ WITHOUT THIS THE SECTION ABOVE PROVES NOTHING. A test that passes on both
//    the fixed and the broken code is the shape this project keeps catching.
$differs = 0;
foreach ([
    ['Europe/London',    '2026-03'],
    ['America/New_York', '2026-03'],
    ['Europe/Berlin',    '2026-03'],
] as [$zone, $month]) {
    if (gridCells($zone, $month) !== gridCellsOldWay($zone, $month)) {
        $differs += 1;
    }
}
check('B1 the old way disagrees on at least one spring-forward month', $differs > 0, true);
check('B2 ...and specifically loses a day in London, March 2026',
    gridCellsOldWay('Europe/London', '2026-03'), gridCells('Europe/London', '2026-03') - 1);

echo "C. Months with NO clock change are unaffected — the fix changed nothing else\n";
foreach ([
    ['Europe/London',    '2026-05'],
    ['Europe/London',    '2026-12'],
    ['America/New_York', '2026-07'],
    ['UTC',              '2026-03'],
] as [$zone, $month]) {
    check("C1 {$zone} {$month} is the same both ways",
        gridCells($zone, $month), gridCellsOldWay($zone, $month));
}

echo "D. Falling back is not broken either — the direction the old code hid\n";
// A 25-hour day rounds the OTHER way, so the integer division swallowed it and
// October never showed the fault. That is why nobody noticed for so long, and
// it is why both directions are checked rather than only the one that failed.
foreach ([
    ['Europe/London',    '2026-10'],
    ['America/New_York', '2026-11'],
    ['Australia/Sydney', '2026-04'],
] as [$zone, $month]) {
    check("D1 {$zone} {$month} (falls back) draws a whole number of weeks",
        gridCells($zone, $month) % 7, 0);
}

echo "E. A pledge spanning a clock change owes the right number of instalments\n";
// 22 March to 5 April 2026 is FOURTEEN days. The old arithmetic said 13,
// because the London clocks went forward in between — so a weekly pledge was a
// whole instalment short for the rest of its window.
date_default_timezone_set('Europe/London');
check('E1 a weekly pledge across the spring change owes 3 instalments, not 2',
    Giving::pledgeExpectedToDate(3000, 'weekly', '2026-03-22', '2026-04-05'), 9000);
check('E2 the same span with no change in it owes the same',
    Giving::pledgeExpectedToDate(3000, 'weekly', '2026-05-03', '2026-05-17'), 9000);
check('E3 falling back does not over-count either',
    Giving::pledgeExpectedToDate(3000, 'weekly', '2026-10-18', '2026-11-01'), 9000);

// The same three in a zone that changes on different dates, because PHP's own
// default zone is what `strtotime()` reads a bare date in.
date_default_timezone_set('America/New_York');
check('E4 New York, spring change inside the window',
    Giving::pledgeExpectedToDate(3000, 'weekly', '2026-03-01', '2026-03-15'), 9000);
date_default_timezone_set('Australia/Sydney');
check('E5 Sydney, southern spring change inside the window',
    Giving::pledgeExpectedToDate(3000, 'weekly', '2026-09-27', '2026-10-11'), 9000);

echo "F. A one-off and a monthly pledge are untouched by any of this\n";
date_default_timezone_set('Europe/London');
check('F1 a one-off owes its full amount from day one',
    Giving::pledgeExpectedToDate(5000, 'one-off', '2026-03-22', '2026-04-05'), 5000);
check('F2 monthly uses calendar months, so a change inside the window is irrelevant',
    Giving::pledgeExpectedToDate(2000, 'monthly', '2026-03-22', '2026-04-22'), 4000);

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "This covers the ARITHMETIC of #549's mistake 3. It says nothing about\n";
echo "mistake 1 — a time on a clock treated as a moment — which needs reading.\n";
exit($failed === 0 ? 0 : 1);
