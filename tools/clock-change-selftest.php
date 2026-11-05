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
 *   Section G covers #777's first fix: `I18n::formatClockTime()`, the one place
 *   a time on a CLOCK is put into words — proved identical to the old
 *   `date('g:i A', strtotime(…))` on all 1,440 minutes of a normal day, and
 *   different only inside the hour that does not exist, where the old code was
 *   an hour out.
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
require PORTAL_CORE . $sep . 'I18n.php';

use Portal\Core\Giving;
use Portal\Core\I18n;

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

echo "G. A time on a CLOCK is put into words without becoming a moment (#777)\n";
// 🚨 THE FAULT THIS REPLACES WAS LIVE IN FOUR PAGES. `date('g:i A',
//    strtotime($clock))` resolves a bare clock reading against TODAY in PHP's
//    default zone — and on the morning the clocks go forward the reading does
//    not exist, so PHP moves it an hour. Measured in Europe/London on a stored
//    `01:30`: 1:30 AM on 28 March, **2:30 AM on 29 March**, 1:30 AM on
//    25 October. Two different stored times, `01:00` and `01:30`, even came
//    out as the same words that day.
check('G1 an ordinary evening time reads as it always did',
    I18n::formatClockTime('19:30:00'), '7:30 PM');
check('G2 ...with or without the seconds', I18n::formatClockTime('19:30'), '7:30 PM');
check('G3 midnight is 12 AM, not 0 AM', I18n::formatClockTime('00:00:00'), '12:00 AM');
check('G4 noon is 12 PM, not 0 PM', I18n::formatClockTime('12:00:00'), '12:00 PM');
check('G5 the minute keeps its leading zero', I18n::formatClockTime('09:05'), '9:05 AM');

// ⚠️ THE ONE THAT MATTERS, AND IT MUST BE THE SAME IN EVERY ZONE AND ON EVERY
//    DAY, because the whole point is that no date or zone is involved.
foreach (['Europe/London', 'America/New_York', 'Australia/Sydney', 'UTC'] as $zone) {
    date_default_timezone_set($zone);
    check("G6 01:30 reads as 1:30 AM in {$zone}, change night or not",
        I18n::formatClockTime('01:30:00'), '1:30 AM');
}

// And it must refuse to invent a time rather than show a confident wrong one.
date_default_timezone_set('Europe/London');
check('G7 nothing comes back as nothing', I18n::formatClockTime(null), '');
check('G8 an empty string too', I18n::formatClockTime(''), '');
check('G9 something that is not a clock reading is handed back unchanged',
    I18n::formatClockTime('not a time'), 'not a time');
check('G10 ...and so is an impossible one, rather than being wrapped round',
    I18n::formatClockTime('25:61'), '25:61');

// 🧪 PROVED AGAINST THE OLD CODE, which is what makes this a fix rather than a
//    rewrite: identical on every minute of a day with no clock change, so the
//    other 364 days look exactly as they did.
date_default_timezone_set('Europe/London');
$differences = 0;
for ($hour = 0; $hour < 24; $hour++) {
    for ($minute = 0; $minute < 60; $minute++) {
        $clock = sprintf('%02d:%02d:00', $hour, $minute);
        $wasShown = date('g:i A', (int) strtotime('2026-05-15 ' . $clock));
        if (I18n::formatClockTime($clock) !== $wasShown) {
            $differences += 1;
        }
    }
}
check('G11 all 1,440 minutes of a normal day read exactly as they used to',
    $differences, 0);

// 🚨 BE EXACT ABOUT WHAT SECTION G CANNOT PROVE, because planting it taught me
//    this and it is the most useful thing here. With `formatClockTime()`'s
//    arithmetic replaced by the old `date('g:i A', strtotime($text))`, **this
//    whole section stayed at 40 of 40.** Of course it did: the two agree on
//    every ordinary day, and this test runs on an ordinary day. They differ
//    only inside the hour that does not exist, one morning a year, and PHP
//    offers no way to tell a script that today is that morning.
//
//    That is #549's own recorded trap — "a test written in December passes in
//    London whatever the code does" — and it means **no behavioural test can
//    guard this one function.** So its BODY is pinned instead, by
//    `tools/audit-checks/check_clock_time_not_a_moment.py`, which fails if it
//    ever contains `strtotime`, `date(` or `DateTime` again. Planted, that goes
//    red where this section does not.
//
//    What section G really proves is the arithmetic itself — midnight, noon,
//    the leading zero, the refusals, and that 364 days look exactly as they
//    did. That is worth having, and it is not the same as proving the fault is
//    gone.

// ...and different ONLY inside the skipped hour, where the old code was wrong.
$oldWay = date('g:i A', (int) strtotime('2026-03-29 01:30:00'));
check('G12 the old way really did move 01:30 on the change morning',
    $oldWay, '2:30 AM');
check('G13 ...and the new way does not', I18n::formatClockTime('01:30:00'), '1:30 AM');

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "Sections A-F cover the ARITHMETIC of #549's mistake 3. Section G covers\n";
echo "ONE instance of mistake 1 — a time on a clock treated as a moment — the\n";
echo "four display sites #777 fixed. The rest of mistake 1 still needs reading:\n";
echo "a reading pulled into a variable and converted several lines later is\n";
echo "invisible to both this and check_clock_time_not_a_moment.py.\n";
exit($failed === 0 ? 0 : 1);
