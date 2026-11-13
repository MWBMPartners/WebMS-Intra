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

echo "H. A wall-clock DATE AND TIME, for a stored booking (#777 round 2)\n";
// 🚨 FIXING THE WRITE SIDE MADE THREE READERS WRONG, which is the lesson here.
//    `tblResourceBooking.startAt/endAt` hold wall-clock values. Once the
//    booking form stopped moving them on the way IN, the three pages that
//    show them back — My Bookings, the approvals queue and the resource page —
//    were still doing `date(…, strtotime($stored))`, so the member was shown
//    an hour LATER than their booking for one morning a year. Before the write
//    was fixed the two wrongs cancelled out and nothing looked odd.
//
//    **A fix on one side of a stored value makes the other side's fault
//    visible. Look at both sides before calling it done.**
foreach (['Europe/London', 'America/New_York', 'Australia/Sydney', 'UTC'] as $zone) {
    date_default_timezone_set($zone);
    check("H1 a booking stored at 01:30 reads back as 01:30 in {$zone}",
        I18n::formatClockDateTime('2027-03-28 01:30:00', 'H:i'), '01:30');
}
date_default_timezone_set('Europe/London');
check('H2 ...and the old way really did move it',
    date('H:i', (int) strtotime('2027-03-28 01:30:00')), '02:30');
check('H3 the day and month come out right', 
    I18n::formatClockDateTime('2027-03-28 01:30:00', 'D j M, H:i'), 'Sun 28 Mar, 01:30');
check('H4 an ordinary day is unchanged from the old wording',
    I18n::formatClockDateTime('2027-06-01 09:00:00', 'D j M, H:i'),
    date('D j M, H:i', (int) strtotime('2027-06-01 09:00:00')));
check('H5 nothing comes back as nothing', I18n::formatClockDateTime(null), '');
check('H6 a date that does not exist is handed back, not invented',
    I18n::formatClockDateTime('2027-02-30 10:00:00'), '2027-02-30 10:00:00');
check('H7 an hour out of range is handed back too',
    I18n::formatClockDateTime('2027-06-01 25:00:00'), '2027-06-01 25:00:00');
check('H8 something that is not a reading at all is handed back',
    I18n::formatClockDateTime('tomorrow'), 'tomorrow');

// ══════════════════════════════════════════════════════════════════════════
// SECTION I — THE THREE WRITE PATHS, over the hour that does not exist
// ══════════════════════════════════════════════════════════════════════════
// 🚨 WHY THIS SECTION HAD TO BE WRITTEN: ROUND 5's FIX WAS PINNED BY NOTHING.
//    Putting the old round trip back in `calendar/submit-save.php` --
//
//        $startUtc = date('Y-m-d H:i:s', (int) strtotime($startRaw));
//
//    -- left `php -l` clean, all 65 audit scripts GREEN and this test at 51 of
//    51. The one test that posts to that handler
//    (`tools/time-zones-selftest.php` W06) uses a fixture 24 days ahead at
//    19:00, so the old and new code agree on it exactly. **#549's trap: a test
//    written on an ordinary day passes whatever the code does.**
//
//    Three handlers and the CSV import store a wall-clock reading, and for
//    about an hour each kept its own copy of the rule -- which this
//    repository's own lesson says is not a test at all ("a test holding its
//    own copy of the logic it tests is not testing anything", #647). So the
//    rule MOVED: `I18n::wallClockFromInput()` and `I18n::inputCarriesOffset()`
//    are the ONE place for each, every handler calls them, and this section
//    calls THE REAL METHODS. Deleting the rule from a handler is a different
//    question, and the gate covers a column READ while #785 covers a WRITE.
echo "\n-- Section I: a reading on the way IN is stored as it stands --\n";

// 📅 2027-03-28 is a real transition in Europe/London, as 2027-03-14 is in
//    America/New_York. Both are fixed dates, so this does not rot.
$wasZone = date_default_timezone_get();
date_default_timezone_set('Europe/London');

check('I1 the skipped hour survives the way IN (London)',
    I18n::wallClockFromInput('2027-03-28T01:30'), '2027-03-28 01:30:00');
check('I2 and the OLD round trip really did move it, so I1 is not vacuous',
    date('Y-m-d H:i:s', (int) strtotime('2027-03-28T01:30')), '2027-03-28 02:30:00');
check('I3 01:00 and 01:30 stay DIFFERENT on the way in',
    I18n::wallClockFromInput('2027-03-28T01:00') === I18n::wallClockFromInput('2027-03-28T01:30'), false);
check('I4 and the old round trip made them different too — so I3 needs I5',
    date('Y-m-d H:i:s', (int) strtotime('2027-03-28T01:00'))
        === date('Y-m-d H:i:s', (int) strtotime('2027-03-28T01:30')), false);
check('I5 the old round trip moved BOTH of them, which is what I1 catches',
    date('Y-m-d H:i:s', (int) strtotime('2027-03-28T01:00')), '2027-03-28 02:00:00');
check('I6 an ordinary day is unchanged, so the rule has not simply broken',
    I18n::wallClockFromInput('2027-06-01T19:00'), '2027-06-01 19:00:00');
check('I7 and on that day the old round trip agrees — #549s trap, stated',
    date('Y-m-d H:i:s', (int) strtotime('2027-06-01T19:00')), '2027-06-01 19:00:00');
check('I8 seconds already present are kept', I18n::wallClockFromInput('2027-06-01T19:00:45'), '2027-06-01 19:00:45');
check('I9 an impossible date is refused, where strtotime rolled it forward',
    I18n::wallClockFromInput('2027-02-30T10:00'), null);
check('I10 and strtotime really did roll it, so I9 is not vacuous',
    date('Y-m-d H:i:s', (int) strtotime('2027-02-30T10:00')), '2027-03-02 10:00:00');
check('I11 an hour out of range is refused', I18n::wallClockFromInput('2027-06-01T25:00'), null);
check('I12 arbitrary English is refused', I18n::wallClockFromInput('tomorrow'), null);

// ── The shapes a real client sends, which round 7 found were REFUSED ──────
// 🚨 EACH OF THESE WAS A 400 ON A LIVE ENDPOINT, and the commit that caused it
//    said "every offset-bearing value behaves identically" and left the API
//    description promising "any date text PHP can read is accepted". Refusing
//    a standards-conforming client is wrong, not merely stricter.
check('I21 a LOWER-CASE t separator is accepted (RFC 3339 allows it)',
    I18n::wallClockFromInput('2027-06-01t19:00:00'), '2027-06-01 19:00:00');
check('I22 a LOWER-CASE z is read as an offset, not as a reading',
    I18n::inputCarriesOffset('2027-06-01T19:00:00z'), true);
check('I23 fractional seconds are DROPPED, not refused — toISOString() sends them',
    I18n::wallClockFromInput('2027-06-01T19:00:00.000'), '2027-06-01 19:00:00');
check('I24 fractional seconds with a Z are still an offset',
    I18n::inputCarriesOffset('2027-06-01T19:00:00.000Z'), true);

// ── I23a-I23e — THE FRACTION MUST BELONG TO THE SECONDS (#777 round 11) ──────
//
// I23 above proves a fraction on the SECONDS is dropped, which is what
// `toISOString()` sends and is right. It could not catch the opposite fault,
// and the gap mattered: round 10 found that the strip was unanchored, so a
// fraction on ANY component was removed. In ISO 8601 a fractional part belongs
// to the LOWEST component written, so `19:00.5` means 19:00:30 and `19.5`
// means 19:30 — and stripping it stored 19:00:00 in both cases, **silently
// losing up to 59 minutes on a live API and a public form where the old code
// had answered an honest 400.**
//
// Round 10 anchored the strip to follow `:MM:SS`. **NOTHING PINNED THAT**: the
// anchor could be removed with all 65 audit scripts and all 95 clock checks
// green, because nothing anywhere sends a decimal time — every value the
// HTTP test posts is built with `gmdate('Y-m-d\TH:i:s\Z', …)`, so it cannot
// reach this either. These five are that pin.
//
// Round 10's own observation is why they are here: every earlier round moved
// the code from "stores something wrong" to "refuses", and round 9 moved a
// refusal to "stores something wrong". That is the direction to check first on
// any shape accepted from here on, and a refusal is the correct answer because
// there is no reading to store — the value names a time the field cannot hold.
check('I23a a fraction on the MINUTES is refused, not truncated to the minute',
    I18n::wallClockFromInput('2027-06-01T19:00.5'), null);
check('I23b a fraction on the HOUR is refused too',
    I18n::wallClockFromInput('2027-06-01T19.5'), null);
check('I23c the same with a space separator, so the T is not what is doing the work',
    I18n::wallClockFromInput('2027-06-01 19:00.25'), null);
check('I23d ISO basic form takes no fractional part at all',
    I18n::wallClockFromInput('20270601T190000.000'), null);
check('I23e and a fraction on the minutes is refused on the OFFSET side as well',
    I18n::instantFromInput('2027-06-01T19:00.5Z', new DateTimeZone('Europe/London')), null);
check('I25 a date with NO time means midnight on it — the API has an isAllDay field',
    I18n::wallClockFromInput('2027-06-01'), '2027-06-01 00:00:00');
check('I26 and a bare date is still not read as carrying an offset',
    I18n::inputCarriesOffset('2027-06-01'), false);
check('I27 a date with no time inside the skipped hour is not a special case',
    I18n::wallClockFromInput('2028-03-12'), '2028-03-12 00:00:00');
// ⚠️ AND THE LOOSENING STOPS HERE, deliberately. These need `strtotime()` to
//    read them, which is the very thing that moves the hour, so they stay
//    refused and the API description now SAYS they are refused rather than
//    promising the opposite.
check('I28 loose English is still refused', I18n::wallClockFromInput('June 1 2027 10:00'), null);
check('I29 an unpadded month or day is still refused', I18n::wallClockFromInput('2027-6-1T10:00:00'), null);
check('I30 a day-first date is still refused', I18n::wallClockFromInput('01-06-2027 10:00'), null);

// ── The OFFSET half, which I28-I30 read as pinning and did NOT ────────────
// 🚨 I28-I30 above cover a value with NO offset. The handler asks
//    `inputCarriesOffset()` FIRST, so until round 8 anything ending in
//    something offset-shaped went straight to the constructor with no shape
//    check at all -- and the API description listed these as refused while
//    every one was accepted. The worst stored a date SIX MONTHS WRONG with
//    HTTP 200: a day-first slash date is read month-first by PHP.
$londonZone = new DateTimeZone('Europe/London');
check('I31 a day-first SLASH date with an offset is REFUSED, not read month-first',
    I18n::instantFromInput('01/06/2027 10:00 +0100', $londonZone), null);
check('I32 loose English with an offset is refused',
    I18n::instantFromInput('June 1 2027 10:00 +01:00', $londonZone), null);
check('I33 an unpadded month with an offset is refused',
    I18n::instantFromInput('2027-6-1T10:00:00+01:00', $londonZone), null);
check('I34 a bare Z means nothing, not NOW',
    I18n::instantFromInput('Z', $londonZone), null);
check('I35 a bare offset means nothing either',
    I18n::instantFromInput('-05', $londonZone), null);
check('I36 an impossible date is refused on the OFFSET side too, not rolled forward',
    I18n::instantFromInput('2027-02-30T10:00:00Z', $londonZone), null);
// And the genuine instants must still convert, or the fix breaks every
// integration that sends one.
check('I37 a real instant with Z converts onto the given clock',
    I18n::instantFromInput('2027-06-01T10:00:00Z', $londonZone), '2027-06-01 11:00:00');
// ── ISO 8601 BASIC form, which is this portal's OWN calendar format ──────
// 🚨 ROUND 8 REFUSED THESE, and the commit claimed "every genuine instant
//    still converts" — which was true only of the four forms it happened to
//    test. `Ical.php`, `calendar/export.php` and `IcsReader.php` all write and
//    read `Ymd\THis`, so a script reading this portal's own `.ics` feed and
//    pushing the events back through the API worked and then got a 400.
check('I38a ISO basic form with Z converts',
    I18n::instantFromInput('20270601T100000Z', $londonZone), '2027-06-01 11:00:00');
check('I38b ISO basic form with an offset converts',
    I18n::instantFromInput('20270601T100000+0200', $londonZone), '2027-06-01 09:00:00');
check('I38c ISO basic form without seconds converts',
    I18n::instantFromInput('20270601T1000Z', $londonZone), '2027-06-01 11:00:00');
check('I38d an hour with no minutes converts',
    I18n::instantFromInput('2027-06-01T10Z', $londonZone), '2027-06-01 11:00:00');
check('I38e a basic-form reading with NO offset is a reading, stored as it stands',
    I18n::wallClockFromInput('20280312T023000'), '2028-03-12 02:30:00');
check('I38f but a BARE eight-digit number is NOT normalised — it is as likely to be something else',
    I18n::wallClockFromInput('20280312'), null);

check('I38 lower-case z, fractional seconds and a two-digit offset all convert',
    [
        I18n::instantFromInput('2027-06-01T10:00:00z', $londonZone),
        I18n::instantFromInput('2027-06-01T10:00:00.000Z', $londonZone),
        I18n::instantFromInput('2027-06-01T10:00:00-05', $londonZone),
        I18n::instantFromInput('2027-06-01T10:00:00+0530', $londonZone),
    ],
    ['2027-06-01 11:00:00', '2027-06-01 11:00:00', '2027-06-01 16:00:00', '2027-06-01 05:30:00']);

// 🌎 THE OTHER DIRECTION OF #653's LESSON: a zone that resolves the other way.
//    London was the only zone in the fixtures that found #653 three times, so
//    one American transition is pinned here as well.
date_default_timezone_set('America/New_York');
check('I13 the skipped hour survives the way in (New York)',
    I18n::wallClockFromInput('2027-03-14T02:30'), '2027-03-14 02:30:00');
check('I14 and the old round trip moved it there too',
    date('Y-m-d H:i:s', (int) strtotime('2027-03-14T02:30')), '2027-03-14 03:30:00');

// 🕰️ THE EVENTS API's extra rule: an OFFSET in the value IS a zone, so such a
//    value really is an instant and must still be converted. Getting this
//    wrong in the safe-looking direction would refuse every integration in
//    another country.
check('I15 no offset means a reading', I18n::inputCarriesOffset('2027-03-28T01:30:00'), false);
check('I16 a trailing Z is an offset', I18n::inputCarriesOffset('2027-03-28T01:30:00Z'), true);
check('I17 +hh:mm is an offset', I18n::inputCarriesOffset('2027-03-28T01:30:00+02:00'), true);
check('I18 +hhmm is an offset', I18n::inputCarriesOffset('2027-03-28T01:30:00-0500'), true);
check('I19 the DATE\'s own hyphens are not read as an offset',
    I18n::inputCarriesOffset('2027-03-28T01:30:00'), false);
check('I20 a bare date is not an offset', I18n::inputCarriesOffset('2027-03-28'), false);

// ─────────────────────────────────────────────────────────────────────────────
// K — THE SPREADSHEET IMPORT'S OWN READER, AS A MATRIX (#777 round 14)
//
// WHY THIS EXISTS, which matters more than what it checks. The closure that
// decides what a CSV cell stores has been rewritten three times, and TWO of
// those rewrites broke formats that had always imported:
//
//   round 12  refused every ISO value carrying an offset -- `toISOString()`
//             output, the commonest machine-written date there is.
//   round 13  refused RFC 2822 (an e-mail `Date:` header, an RSS `pubDate`)
//             and three unpadded or basic ISO forms, and its impossible-date
//             rule refused a legal ISO ordinal date.
//
// Each time the fault was found by an independent checker building a MATRIX of
// formats, and each time it had been missed by me probing the cases I happened
// to think of. **A list of cases you chose yourself cannot tell you about the
// class you forgot.** So the matrix is committed, and every future change to
// that closure is measured against it rather than against somebody's memory.
//
// It needs no database and no web server: the closure is lifted out of the
// page source and run directly, which is also why it is here rather than in
// the HTTP test.
//
// ⚠️ WHAT COVERS THIS READER, AND WHAT DOES NOT — stated once, here, because
//    three attempts at this sentence have been wrong. It is NOT true that
//    "nothing POSTS a CSV": `tools/time-zones-selftest.php` posts SIX to
//    `/calendar/manage/import` (W07, W07b-W07f). Round 19 said it had
//    corrected this and corrected a different sentence instead.
//
//    Nor is every cell those six send "a clean ISO value", which was round
//    19's replacement claim: W07e and W07f send `24 September 2028 3:15am`,
//    a loose spreadsheet form that reaches the step-3 fallback.
//
//    **The accurate narrowing: none of the six sends an impossible date, a
//    decimal fraction or an offset** — so they cover the round trip and not
//    the rules this section is about. That is #787.
echo "\n";
$importSrc = (string) file_get_contents(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'calendar' . DIRECTORY_SEPARATOR . 'manage'
    . DIRECTORY_SEPARATOR . 'import.php'
);
// ⚠️ LIFT BOTH, AND TEST THE WRAPPER. `$fmtStore` is what decides a cell's
//    stored value; `$fmtClockRaw` is the reader it wraps. Lifting only the
//    reader would have left the range test this section exists to cover
//    completely untested while every check stayed green — the exact shape
//    this package keeps recording. The two patterns are separate so a
//    rename of either fails K00 loudly rather than silently testing less.
$grabRaw   = [];
$grabStore = [];
$foundBoth = preg_match('/\$fmtClockRaw = static function.*?\n\};/s', $importSrc, $grabRaw) === 1
    && preg_match('/\$fmtStore = static function.*?\n\};/s', $importSrc, $grabStore) === 1;
// ⚠️ EXACTLY ONE DEFINITION OF EACH, AND NO REASSIGNMENT. Round 22 defeated
//    the whole output test with ONE added line and with a dead duplicate, and
//    everything stayed green:
//
//      (a) `$fmtStore = $fmtClockRaw;` anywhere below the definitions. PHP
//          runs the LAST assignment, so the page then used the bare reader
//          and `-0001-12-31 23:58:45` came back again — the whole round-14
//          harm restored, 110 of 110 passing and 66 audit scripts green.
//
//      (b) A dead CORRECT copy above a live permissive one. `preg_match`
//          takes the FIRST match, so this section lifted and tested the
//          copy that does not run.
//
//    Both are shapes this package has already closed elsewhere — "the start
//    anchor must appear exactly ONCE" (#485 round 2, #763) and round 10's
//    text pin "defeated by a REASSIGNMENT". **This was the fifth round
//    running to find a headline fix pinned by nothing**, so the pin is on the
//    NUMBER of definitions and on there being no later write. **That closes
//    only the plain `=` spelling, and round 23 proved it:** a destructuring
//    assignment, `list()`, a variable variable, `extract()` or a reference
//    each switched the guard off with this section at 112 of 112. The
//    behavioural fix — drive the real import with out-of-range cells — is
//    #796.
$storeWrites = preg_match_all('/\$fmtStore\s*=(?!=)/', $importSrc);
$rawWrites   = preg_match_all('/\$fmtClockRaw\s*=(?!=)/', $importSrc);
check(
    'K00a `$fmtStore` is written by a plain `=` exactly ONCE (other spellings are NOT seen — #796)',
    $storeWrites,
    1
);
check(
    'K00b and `$fmtClockRaw` likewise',
    $rawWrites,
    1
);

if ($foundBoth === false) {
    check('K00 the import closure was found, so section K tests the live code', false, true);
} else {
    check('K00 the import closure was found, so section K tests the live code', true, true);
    date_default_timezone_set('Europe/London');

    // ⚠️ THE CLOSURE USES `I18n::` AND `Site::` BY THEIR SHORT NAMES, and an
    //    `eval()` does not inherit `import.php`'s `use` lines, so both are
    //    aliased into the global namespace here.
    //
    //    `Site::timezone()` is STOOD IN FOR, and that is a stated limit of
    //    section K rather than a shortcut: the real one needs the whole portal
    //    and a database. So section K proves what the closure DOES with a
    //    zone, and proves nothing about the zone being the right one. That
    //    second half is `$importZone` at the bottom of `import.php`, and the
    //    only thing covering it is the comment beside it -- noted in #787.
    if (class_exists('I18n', false) === false) {
        class_alias(\Portal\Core\I18n::class, 'I18n');
    }
    if (class_exists('Site', false) === false) {
        eval('class Site { public static function timezone(): string { return "Europe/London"; } }');
    }
    // ⚠️ STEP (d) OF THE READER CALLS `\Portal\Core\EventRecurrence::
    //    readingIsReal()` -- the one test of whether a reading exists in a
    //    zone (#785) -- and it is reached by ordinary matrix cells, not only
    //    by K07's, so the class is loaded BEFORE the closure runs at all. The
    //    portal itself autoloads it; an eval here does not. An earlier draft
    //    loaded it only inside K07, AFTER the matrix above had already run
    //    the closure, and the whole test stopped with "class not found" at the
    //    first ordinary cell -- so it is loaded here, before anything runs.
    require_once PORTAL_CORE . $sep . 'EventRecurrence.php';
    // 📅 And the WRAPPER asks `\Portal\Core\EventDateRange` whether a stored
    //    reading falls in the range an event may have (#1045, D-181), for the
    //    same reason: loaded before the closure runs.
    require_once PORTAL_CORE . $sep . 'EventDateRange.php';
    // The reader is defined FIRST: the wrapper binds it with `use (&...)`.
    eval($grabRaw[0]);
    eval($grabStore[0]);

    // MUST STORE. Every one of these imported correctly at some point in this
    // package's history, and each row names the round that broke it, so a
    // regression is reported with its own precedent.
    $mustStore = [
        // ordinary spreadsheet output -- the reason a loose fallback exists
        ['2026-06-01 10:00',              '2026-06-01 10:00:00', ''],
        ['2026-06-01 10:00 PM',           '2026-06-01 22:00:00', ''],
        ['2026-06-01 7pm',                '2026-06-01 19:00:00', ''],
        ['2026-06-01  10:00',             '2026-06-01 10:00:00', ''],
        ['2026-6-1 10:00',                '2026-06-01 10:00:00', ''],
        ['March 3 2026 19:00',            '2026-03-03 19:00:00', ''],
        ['Tue, 3 Mar 2026 19:00',         '2026-03-03 19:00:00', ''],
        ['2026-06-01',                    '2026-06-01 00:00:00', ''],
        // ISO readings
        ['2026-06-01T10:00:00',           '2026-06-01 10:00:00', ''],
        ['20260601T100000',               '2026-06-01 10:00:00', ''],
        // offset-bearing: these ARE instants and must be CONVERTED
        ['2026-06-01T10:00:00Z',          '2026-06-01 11:00:00', 'round 12 refused it'],
        ['2026-06-01T10:00:00.000Z',      '2026-06-01 11:00:00', 'round 12 refused it'],
        ['2026-06-01T10:00:00+01:00',     '2026-06-01 10:00:00', 'round 12 refused it'],
        ['2026-06-01T10:00:00+0100',      '2026-06-01 10:00:00', 'round 12 refused it'],
        ['2026-06-01T10:00:00z',          '2026-06-01 11:00:00', 'round 12 refused it'],
        ['20260601T100000Z',              '2026-06-01 11:00:00', 'NEVER refused by any version — kept because it is this portal\'s own .ics shape'],
        // offset-bearing with a NON-ISO date spelling
        ['Mon, 01 Jun 2026 10:00:00 +0100', '2026-06-01 10:00:00', 'round 13 refused it (RFC 2822)'],
        ['Thu, 01 Jan 2026 10:00:00 +0100', '2026-01-01 09:00:00', 'round 13 refused it'],
        ['Tue, 3 Mar 2026 19:00 +0100',     '2026-03-03 18:00:00', 'round 13 refused it'],
        ['1 June 2026 10:00 +01:00',        '2026-06-01 10:00:00', 'round 13 refused it'],
        ['Jun 1 2026 10:00 +0100',          '2026-06-01 10:00:00', 'round 13 refused it'],
        ['Mon, 01 Jun 2026 10:00:00 GMT',   '2026-06-01 11:00:00', ''],
        // unpadded and basic ISO forms neither strict reader handles
        ['2026-6-1T10:00',                '2026-06-01 10:00:00', 'round 13 refused it'],
        ['2026-6-1T10:00:00',             '2026-06-01 10:00:00', 'round 13 refused it'],
        ['20260601T10',                   '2026-06-01 10:00:00', 'round 13 refused it'],
        ['2026-152 10:00',                '2026-06-01 10:00:00', 'round 13 refused it (ISO ordinal)'],
        ['2026-152',                      '2026-06-01 00:00:00', 'round 13 refused it (ISO ordinal, no time)'],
        ['2026-W23-1 10:00',              '2026-06-01 10:00:00', 'ISO week date'],
        // ⚠️ LEGITIMATE FRACTIONAL SECONDS. Round 15 listed only the first two
        //    of these, under a comment saying step 2 takes them "so the
        //    fraction rule below must never reach them" — and measured
        //    exactly those two. **Step 2 strips a fraction only at the END of
        //    the string and needs a zero-padded date, so none of the rest
        //    reaches it**, and round 15's rule refused all of them: 189
        //    machine formats that had imported in EVERY previous version
        //    including the original. A database or log export with
        //    microseconds and a zone name is an ordinary CSV cell.
        ['2026-06-01T10:00:00.000',       '2026-06-01 10:00:00', ''],
        ['2026-06-01 10:00:00.000',       '2026-06-01 10:00:00', ''],
        ['2026-06-01 10:00:00.000 UTC',   '2026-06-01 11:00:00', 'round 15 refused it'],
        ['2026-06-01 10:00:00.123456 UTC', '2026-06-01 11:00:00', 'round 15 refused it'],
        ['2026-6-1 10:00:00.000',         '2026-06-01 10:00:00', 'round 15 refused it'],
        ['2026-6-1 10:00:00.000+01:00',   '2026-06-01 10:00:00', 'round 13 refused it first, round 15 again'],
        ['2028-02-29 10:00:00.123456 GMT', '2028-02-29 10:00:00', 'round 15 refused it'],
        // ⚠️ A TWO-DIGIT `HH.MM` TIME — the ordinary way a British spreadsheet
        //    writes one, and NOT ONE OF THESE WAS HERE for four rounds. That
        //    is why round 16 could refuse all 1,440 of them and stay green:
        //    every fraction case in this list used ONE digit, where PHP's
        //    reading and the writer's really do differ. **The generalisation
        //    from one digit to `\d+` was tested by nothing.**
        //
        //    `strtotime()` reads the dot as a MINUTE SEPARATOR, proved by its
        //    refusing minutes above 59 (`10.60` and `10.75` answer false), so
        //    for two digits it agrees with whoever typed the cell.
        // ⚠️ THE ROUND NAMED HERE IS THE PRECEDENT, so it has to be the right
        //    one. These said "round 16" and round 18 measured otherwise by
        //    running every committed closure over the same cells:
        //
        //      2026-06-01T19.30        first refused by ROUND 12
        //      2026-06-01 19.30        first refused by ROUND 15
        //      2026-6-1 19.30          first refused by ROUND 15
        //      Tue, 3 Mar 2026 19.30   first refused by ROUND 16
        //
        //    So round 16's contribution was widening an existing refusal to
        //    the other date spellings, not introducing it. "All five earlier
        //    versions stored it" is true of the Excel form alone.
        ['2026-06-01 19.30',              '2026-06-01 19:30:00', 'round 15 refused it'],
        ['2026-06-01 00.01',              '2026-06-01 00:01:00', 'round 15 refused it'],
        ['2026-06-01 23.59',              '2026-06-01 23:59:00', 'round 15 refused it'],
        ['2026-6-1 19.30',                '2026-06-01 19:30:00', 'round 15 refused it'],
        ['2026-06-01T19.30',              '2026-06-01 19:30:00', 'round 12 refused it — the oldest of these'],
        ['Tue, 3 Mar 2026 19.30',         '2026-03-03 19:30:00', "round 16 refused it — Excel's long date format"],
        ['March 3 2026 19.30',            '2026-03-03 19:30:00', 'round 16 refused it'],
        ['1 June 2026 19.30',             '2026-06-01 19:30:00', 'round 16 refused it'],
        // ⚠️ F4: A ONE-DIGIT FRACTIONAL SECOND. Every fractional-second row
        //    here used three or six digits, and step 2 handles the
        //    zero-padded-date form on its own — so the `:SS.` exemption only
        //    bites on an unpadded date or something trailing, which is
        //    exactly what was missing. Round 18 deleted that whole clause and
        //    got 65 audit scripts and 107 clock checks green while these six
        //    were refused: the "189 machine formats" class, back again.
        // ⚠️ THE COMPONENT WIDTH, which every one of these missed. All six used
        //    `10:00:00.X`, so the exemption's single spelling
        //    (`:\d{2}:\d{2}\.\d`, demanding a padded minute AND second) was
        //    never tested — and it refused cells round 15 stored. A
        //    seconds fraction is never ambiguous: both readings store the same
        //    value, because a DATETIME holds no fraction.
        ['2026-06-01 10:00:5.5',          '2026-06-01 10:00:05', 'unpadded second — round 19 refused it'],
        ['Tue, 3 Mar 2026 10:00:5.5',     '2026-03-03 10:00:05', 'unpadded second — round 19 refused it'],
        ['1 June 2026 10:0:00.5',         '2026-06-01 10:00:00', 'unpadded minute — round 19 refused it'],
        ['2026-06-01 9:05:5.5',           '2026-06-01 09:05:05', 'unpadded hour and second — round 19 refused it'],
        ['2026-6-1 10:00:00.5',           '2026-06-01 10:00:00', 'the :SS. exemption, one digit'],
        ['2026-06-01 10:00:00.5 UTC',     '2026-06-01 11:00:00', 'the :SS. exemption, one digit'],
        ['2026-06-01 10:00:00.9 GMT',     '2026-06-01 11:00:00', 'the :SS. exemption, one digit'],
        ['2026-6-1 10:00:00.5+01:00',     '2026-06-01 10:00:00', 'the :SS. exemption, one digit'],
        ['2026-06-01 10:00:00.5 Europe/London', '2026-06-01 10:00:00', 'the :SS. exemption, one digit'],
        ['2028-02-29 10:00:00.3 GMT',     '2028-02-29 10:00:00', 'the :SS. exemption, one digit'],
        ['2026-06-01 10.0',               '2026-06-01 10:00:00', 'a zero fraction means the same either way'],
        ['2026-06-01 19.30:00',           '2026-06-01 19:30:00', 'round 16 stored this while refusing 19.30 — inconsistent'],
        // dotted European dates, which must not be mistaken for a fraction
        ['01.06.2026 10:00',              '2026-06-01 10:00:00', ''],
        ['31.12.2026',                    '2026-12-31 00:00:00', ''],
        ['1.6.2026 10:00',                '2026-06-01 10:00:00', ''],
        // a real leap day, so the impossible-date rule cannot just refuse 29 February
        ['2028-02-29 10:00',              '2028-02-29 10:00:00', ''],
        // ⚠️ #797's rule must NOT refuse a full date carrying a relative step:
        //    the answer is the same whenever the import runs, so it is not
        //    "read against the day of the import".
        ['2026-06-01 +1 day',             '2026-06-02 00:00:00', 'a full date with a relative step does not depend on now'],
        // ⚠️ #797 deliberately does NOT refuse a month and year with no day:
        //    PHP always supplies the 1st, whenever the import runs. Pinned so
        //    a rule refusing too much goes red (round 2 found nothing did).
        //    (A `'2026-06-01'` "date alone" entry was added here too and was
        //    a DUPLICATE of one already in the list — round 17's shape —
        //    so it is gone and the count is 62, not 61 + 2.)
        ['Jan 2026',                      '2026-01-01 00:00:00', 'no day: PHP supplies the 1st, not taken from now'],
        ['June 2026 10:00',               '2026-06-01 10:00:00', 'no day: PHP supplies the 1st, not taken from now'],
    ];
    $storeBad = [];
    foreach ($mustStore as [$cell, $want, $hist]) {
        $got = $fmtStore($cell);
        if ($got !== $want) {
            $storeBad[] = $cell . ' -> ' . var_export($got, true) . ' (wanted ' . $want
                . ($hist === '' ? '' : '; ' . $hist) . ')';
        }
    }
    check('K01 all ' . count($mustStore) . ' formats that must import still do',
        $storeBad === [] ? 'all import' : implode(' | ', $storeBad), 'all import');

    // MUST REFUSE. A date that cannot exist, in every spelling, and the one
    // fractional shape PHP would misread.
    $mustRefuse = [
        // a day that month does not have
        '2026-02-31 10:00', '2026-2-31 10:00', '2026-6-31 10:00', '2026-4-31 09:00',
        '02/31/2026 10:00', '2/31/2026 10:00', 'Feb 31 2026 10:00',
        '31 February 2026 10:00', '29 Feb 2026',
        // ⚠️ ROUND 14 STORED EVERY ONE OF THESE, under a comment saying its
        //    rule covered "any spelling". The broken component is a ZERO or
        //    the year is missing, which its day-1-to-31 test stepped over.
        //    `0000-00-00` is the MySQL zero date, so it is what a CSV exported
        //    from a legacy dump contains — and it rolled to `-0001-11-30`,
        //    which a DATETIME column REFUSES, aborting the whole import
        //    part-way with earlier rows already written.
        '2026-06-00 10:00', '2026-6-0 10:00', '06/00/2026 10:00',
        'Feb 0 2026 10:00', '0 June 2026 10:00',
        '2026-00-10 10:00', '2026-0-10 10:00', '00/10/2026 10:00',
        'Feb 31 10:00',
        // ⚠️ `Feb 29 10:00` USED TO BE HERE AND WAS A TIME BOMB. It is the one
        //    year-less, year-DEPENDENT cell: impossible in 2026, and a real
        //    date in 2028. Listed as a refusal it would have turned this test
        //    RED for the whole of 2028, 2032, 2036 … on correct code — the
        //    cry-wolf direction (#483), in the package whose own written
        //    lesson is "a test written in December passes in London whatever
        //    the code does". Since #797 a year-less cell is refused EVERY
        //    year, for the reason that is true every year, and K06 below pins
        //    that — so it could come back here, and is kept in K06 instead
        //    because K06 also pins the reason.
        '0000-00-00 10:00', '0000-00-00 00:00:00',
        // ⚠️ CELLS WITH NO FULL DATE (#797). Each used to be stored at a date
        //    taken from the moment of import — `2026` as TODAY at 20:26,
        //    `1999` as today's date in 1999, `10:00` as today, `Monday` as the
        //    next Monday. They cover each part a cell can leave out: the whole
        //    date, the year, the day, and a relative word.
        '2026', '1999', '1462', '10:00', '10:00:00', '23:59', 'noon',
        'tomorrow 10:00', 'Monday', 'next Monday 10:00', 'today', 'now',
        '+1 day', '1 June 10:00', '15 January 10:00', 'June 1',
        // A dotted date with a two-digit year: PHP reads it as a TIME
        // (01:06:26), so it was stored on the import day. And an Excel serial
        // number with a decimal part, which was stored in the year 4617.
        '01.06.26', '13.06.26', '1.6.26', '46174.5',
        // NOT here: `Jan 2026` and `June 2026 10:00`. They have no DAY, but
        //    PHP always supplies the 1st — the same answer whenever the import
        //    runs — so they are not "read against the day of the import" and
        //    this rule rightly lets them through. Whether a cell with no day
        //    should be refused anyway is a separate question, raised on #797.
        // ⚠️ OUTSIDE THE DOCUMENTED `DATETIME` RANGE (1000-01-01 to
        //    9999-12-31). MySQL REFUSES the negative and five-digit years
        //    below and ACCEPTS 0001-0999 without a warning (measured, round
        //    22), so the sub-1000 cells are refused by the portal's own floor,
        //    not by the database.
        //    These are the cells that made the insert THROW part-way through
        //    an import with earlier rows already written — the shape round 14
        //    recorded as the worst thing in this package (`-0001-11-30` from a
        //    MySQL zero date), still reachable until the output test. Every
        //    one measured on the live closure before it existed.
        '@-62167219200', '@-99999999999', '@999999999999',
        '9999-12-31 +1 day', '9999-12-31 23:59:59 +1 second',
        '2026-06-01 +1000000 years', 'Tue, 3 Mar 2026 +1000000 years',
        '9999-12-31 next year',
        //    And a FOUR-DIGIT year below the floor. `0500-01-01 10:00`
        //    reaches the closure through step 2's strict reader and returns
        //    from there, which is how a test placed at the bottom of step 3
        //    missed it, and why the output test is a WRAPPER over every path
        //    rather than a step.
        //
        // ⚠️ AN EARLIER VERSION SAID SUCH A YEAR REACHES THE CLOSURE "NOT VIA
        //    STEP 3", AND THAT IS FALSE. Step 3 produces one too — measured,
        //    `2026-06-01 -1027 years` comes out of the fallback as
        //    `0999-06-01 00:00:00`, and `date('Y')` zero-pads years 1 to 999
        //    to four digits. The DESIGN is unaffected (step 2 really does
        //    bypass a step-3 test, which `0500-01-01 10:00` proves on its
        //    own), but the reason as written sent the next reader to the wrong
        //    place. The step-3 route to the floor is exercised by the second
        //    cell below, which did not exist until round 22 pointed this out:
        //    before it, every floor cell arrived via step 2, so the claim was
        //    self-confirming.
        '0500-01-01 10:00', '0001-01-01 00:00:00',
        '2026-06-01 -1027 years',
        // a fraction on the wrong component: ISO says 10.5 means 10:30, and
        // strtotime answers 10:05
        '2026-06-01T10.5', '2026-06-01T10:00.5',
        // ⚠️ AND THE SAME WITH AN UNPADDED DATE OR A SPACE SEPARATOR, which
        //    round 14 stored: it tightened the date half of its own pattern
        //    from `\d{1,2}` to `\d{2}` without saying so, and the space form
        //    stored 10:05 in every version including the original.
        '2026-6-1T10.5', '2026-06-1T10.5', '2026-6-01T10.5', '2026-6-1T10:00.5',
        '2026-6-1T10.5Z',
        '2026-06-01 10.5', '2026-6-1 10.5', '2026-06-01 23.9', '2026-06-01 10:00.5',
        // ⚠️ AND IN EVERY OTHER DATE SPELLING. Rounds 12 to 15 each matched a
        //    DATE pattern here and each was defeated by a date spelling —
        //    round 15 still stored all of these as five past the hour, and two
        //    of them (`2026-152`, `2026-W23-1`) it ADDED to the supported
        //    list in the same commit. The rule says nothing about the date
        //    now, so no date spelling can defeat it.
        '1 June 2026 10.5', 'June 1 2026 10.5', 'Mon, 01 Jun 2026 10.5',
        '01/06/2026 10.5', '2026/06/01 10.5', '2026-152 10.5', '2026-W23-1 10.5',
        '2026-152 10:00.5', '2026-06-01 10.5 UTC', '2026-06 10.5',
        // ⚠️ AND WHATEVER TRAILS IT. Round 16's rule had a trailing set that
        //    allowed letters, `/` and `_` but NOT digits, `+` or `-`, so 89
        //    zone spellings fell through and stored the wrong hour — under a
        //    comment saying a fraction "followed only by a zone or an offset"
        //    was refused. A date-spelling list had been replaced by a
        //    zone-spelling list. Nothing trails the pattern now.
        // ⚠️ F3: A SINGLE-DIGIT HOUR. Every one-digit-fraction cell here used a
        //    TWO-digit hour, so narrowing the rule's `\d{1,2}` to `\d{2}` —
        //    ONE CHARACTER, and a named already-committed fault of round 14's
        //    — left 65 audit scripts and 107 clock checks green while
        //    `2026-06-01 9.5` stored 09:05 and `Tue, 3 Mar 2026 9.5` stored
        //    09:05. Up to 25 minutes wrong, with `ok => true`.
        // ⚠️ AND ALL NINE DIGITS, not three. The list used only `.3`, `.5` and
        //    `.9`, so narrowing the rule's `[1-9]` to `[359]` — ONE CHARACTER
        //    — left 107 of 107 clock checks and 65 audit scripts green while
        //    the other six stored again: `10.1` as 10:01, and worst `10.8` as
        //    10:08 against a decimal reading of 10:48, **forty minutes wrong
        //    with `ok => true`**. That is the THIRD way section K has been
        //    shown to pass on broken code, and all three were the same
        //    mistake: a list that covers one value of a dimension the rule
        //    ranges over. **Cover the dimension, not an example of it.**
        '2026-06-01 10.1', '2026-06-01 10.2', '2026-06-01 10.3', '2026-06-01 10.4',
        '2026-06-01 10.6', '2026-06-01 10.7', '2026-06-01 10.8',
        'Tue, 3 Mar 2026 10.1', '2026-06-01 10:00.1', '2026-06-01 10:00.8',
        // ⚠️ A ONE-DIGIT MINUTE. Every 1-colon cell used a two-digit minute,
        //    so nothing distinguished `:\d{2}` from `:\d{1,2}` — reverting
        //    that one atom restored round 18's fault (the HOUR sentence shown
        //    for a minute fraction) with 107/107 green.
        '2026-06-01 10:0.5', '2026-06-01 9:0.5', 'Tue, 3 Mar 2026 10:0.5',
        '2026-06-01 9.5', '2026-06-01 8.5', '2026-06-01 1.5', '2026-06-01 9.3',
        '1 June 2026 9.5', 'Tue, 3 Mar 2026 9.5', '01.06.2026 9.5',
        '2026-06-01 9:00.5',
        '2026-06-01 10.5 GMT+1', '2026-06-01 10.5 UTC+1',
        '2026-06-01 10.5 Etc/GMT+1', '2026-06-01 10.5 America/Port-au-Prince',
        '2026-06-01 10.5 Asia/Ust-Nera', '2026-06-01 10.5.',
        'rubbish',
    ];
    $refuseBad = [];
    foreach ($mustRefuse as $cell) {
        $got = $fmtStore($cell);
        if ($got !== null) {
            $refuseBad[] = $cell . ' -> ' . $got;
        }
    }
    check('K02 all ' . count($mustRefuse) . ' cells this portal refuses really are refused',
        $refuseBad === [] ? 'all refused' : implode(' | ', $refuseBad), 'all refused');

    // K04 — THE LISTS MAY NOT SHRINK UNNOTICED. Both are iterated and both
    //       labels are computed with `count()`, so **deleting rows changes the
    //       printed number and fails nothing** — measured by round 17, which
    //       removed ten of K02's entries and still got 104 of 104. That is the
    //       shape this repository fixed once before with an explicit
    //       `array_keys(...) === [...]` pin (#697, #709).
    //
    //       These two numbers are the pin. Raise them in the same edit that
    //       adds a case, and never lower them without saying why in the
    //       commit — a smaller list is a smaller guard, and section K is the
    //       only thing guarding the fraction and impossible-date rules (see
    //       the note at the head of this section for what else covers the
    //       reader, and what does not).
    check('K04 the must-store list has not shrunk', count($mustStore), 62);
    check('K04b the must-refuse list has not shrunk', count($mustRefuse), 100);

    // ── K06. A year-less cell is refused EVERY year, for a reason true every year ──
    // ⚠️ THIS USED TO ASSERT THE OPPOSITE, and that was #797's fault being
    //    pinned as correct. `Feb 29 10:00` has no year, so `strtotime()`
    //    supplied the year the import ran in: stored in a leap year, refused
    //    in any other, and every year-less cell landed in whichever year
    //    somebody happened to press Import — next January's services,
    //    imported in December, eleven months in the past. Round 21 had made
    //    the test year-proof by asserting BOTH directions against the real
    //    year, which was right about the time bomb and pinned the fault.
    //
    //    Now one answer holds in every year, so there is no branch on the
    //    year and nothing here can go stale.
    $febWhy = null;
    $feb    = $fmtStore('Feb 29 10:00', $febWhy);
    check('K06 a year-less "Feb 29" is refused, whatever year this runs in', $feb, null);
    check('K06b and refused because it gives no full date, not because of any year', $febWhy,
        'could not be read as a complete date: what came out depended on the day of the import. Give the day, the month and a four-digit year, for example 2026-06-01 10:00');

    // K05 — WHICH REFUSAL IT IS. The closure reports a reason through its
    //       second argument, and the import shows it to the administrator.
    //
    // ⚠️ PINNED BY NOTHING UNTIL NOW. Round 17 reverted the whole of that fix
    //    — the `&$why` parameter, all four assignments and both call sites,
    //    back to "not parseable" — and got 65 audit scripts green, 104 clock
    //    checks green with K01, K02 and K03 all ticked, and 75 recurrence
    //    checks. **The fourth round running to find a headline fix
    //    unguarded.** Section K called the closure with ONE argument and
    //    never looked at the reason.
    //
    //    It matters because "not parseable" was untrue of every value these
    //    rules refuse: `strtotime('2026-06-01 10.5')` and
    //    `strtotime('0000-00-00 10:00')` both answer real moments. The
    //    administrator was told their file could not be read when the portal
    //    had decided not to accept it, which hides the one thing that would
    //    let them fix the row.
    //
    //    (While #797 was being written — before anything was committed — a
    //    rewrite of K06 sliced between two markers and deleted this comment,
    //    the edit style this project has already recorded as deleting
    //    working text. Restored from
    //    `git show 8047e04:tools/clock-change-selftest.php`.)
    $reasonFor = [
        '2026-02-31 10:00'  => 'is a date that does not exist',
        '0000-00-00 10:00'  => 'is a date that does not exist',
        // ⚠️ NOT "does not exist" ANY MORE (#797). Feb 31 is impossible in
        //    every year, so that message was true — but (a2) now refuses every
        //    cell with no year BEFORE the impossible-date test, so this one
        //    gets (a2)'s reason. The administrator adds a year and is then told
        //    the date does not exist: two round trips where one would do,
        //    recorded on #796 rather than reordered here.
        'Feb 31 10:00'      => 'could not be read as a complete date: what came out depended on the day of the import. Give the day, the month and a four-digit year, for example 2026-06-01 10:00',
        '01.06.26'          => 'could not be read as a complete date: what came out depended on the day of the import. Give the day, the month and a four-digit year, for example 2026-06-01 10:00',
        '2026'              => 'could not be read as a complete date: what came out depended on the day of the import. Give the day, the month and a four-digit year, for example 2026-06-01 10:00',
        '10:00'             => 'could not be read as a complete date: what came out depended on the day of the import. Give the day, the month and a four-digit year, for example 2026-06-01 10:00',
        'tomorrow 10:00'    => 'could not be read as a complete date: what came out depended on the day of the import. Give the day, the month and a four-digit year, for example 2026-06-01 10:00',
        // ⚠️ BOTH COMPONENTS, because the old single sentence was untrue of
        //    the minute case: there the choice is seconds against part of a
        //    MINUTE, not minutes against part of an hour. K05 pinned only
        //    hour examples, so that wording was pinned by nothing.
        '2026-06-01 10.5'   => 'writes the hour with a single digit after a decimal point, which could '
                             . 'mean either minutes past the hour or part of an hour',
        '1 June 2026 10.5'  => 'writes the hour with a single digit after a decimal point, which could '
                             . 'mean either minutes past the hour or part of an hour',
        '2026-06-01 9.5'    => 'writes the hour with a single digit after a decimal point, which could '
                             . 'mean either minutes past the hour or part of an hour',
        '2026-06-01 10:00.5' => 'writes the minute with a single digit after a decimal point, which could '
                             . 'mean either seconds or part of a minute',
        // a ONE-digit minute, which is what the `:\d{1,2}` atom is for
        '2026-06-01 10:0.5' => 'writes the minute with a single digit after a decimal point, which could '
                             . 'mean either seconds or part of a minute',
        // and a cell PHP cannot read at all must say so, not blame a fraction
        '1.6. 10:00'        => 'cannot be read',
        '2026-152 10:00.5'  => 'writes the minute with a single digit after a decimal point, which could '
                             . 'mean either seconds or part of a minute',
        'rubbish'           => 'cannot be read',
        'not a date at all' => 'cannot be read',
    ];
    $reasonsWrong = [];
    foreach ($reasonFor as $cell => $wantReason) {
        // ⚠️ `(string)` IS LOAD-BEARING: PHP makes the key `'2026'` an INT,
        //    and under strict_types the closure then throws a TypeError
        //    (the #669 trap, met again when #797 added a bare-year cell).
        $cell      = (string) $cell;
        $gotReason = null;
        $stored    = $fmtStore($cell, $gotReason);
        if ($stored !== null) {
            $reasonsWrong[] = $cell . ' was stored (' . $stored . ') rather than refused';
            continue;
        }
        if ($gotReason !== $wantReason) {
            $reasonsWrong[] = $cell . ' gave "' . (string) $gotReason . '"';
        }
    }
    check('K05 each refusal says WHICH refusal it is, not "not parseable"',
        $reasonsWrong === [] ? 'all correct' : implode(' | ', $reasonsWrong), 'all correct');

    // ── K08. THE RANGE AN EVENT MAY HAVE (#1045, D-181) ──
    //    1 January 1900 to the end of the later of 2200 and this year plus
    //    fifty. The far year is WORKED OUT here, never written down, so these
    //    stay right when this year plus fifty passes 2200. Kept out of K01 and
    //    K02 on purpose: those lists' lengths are pinned (K04, K04b) and a
    //    computed year cannot sit in a fixed list. The HTTP half — the row
    //    really refused at preview and not imported — is section F of
    //    `tools/event-date-range-selftest.php`.
    $kLast  = max(2200, (int) gmdate('Y') + 50);
    $kWhy   = null;
    $k1899  = $fmtStore('1899-12-31 10:00', $kWhy);
    check('K08 31 December 1899 is refused, with the range as its reason',
        [$k1899, str_contains((string) $kWhy, 'between 1 January 1900 and 31 December ' . $kLast)], [null, true]);
    check('K08b 1 January 1900 at midnight is stored', $fmtStore('1900-01-01 00:00'), '1900-01-01 00:00:00');
    check('K08c the last second of the last allowed year is stored', $fmtStore($kLast . '-12-31 23:59:59'), $kLast . '-12-31 23:59:59');
    check('K08d the first moment of the year after is refused', $fmtStore(($kLast + 1) . '-01-01 00:00'), null);
    check('K08e year 9999 is refused', $fmtStore('9999-12-31 10:00'), null);

    // ── K07. THE HOUR THAT DOES NOT EXIST, through the loose fallback (#785) ──
    //
    // Step 3 of the reader builds a moment with `strtotime()` and formats it
    // back. Until #785 a loose spreadsheet cell in the skipped hour was STORED
    // moved -- `Sun, 29 Mar 2026 01:30` as 02:30 in London, and in Nuuk
    // `28 March 2026 11:30pm` as 00:30 on the NEXT DAY -- while the same
    // reading written `2026-03-29 01:30` was stored as typed. Found by
    // `check_wall_clock_writes_not_moments.py` on its first run; nothing in
    // section K had a cell in the skipped hour, so nothing here could see it.
    //
    // Step (d) now stores the reading the cell WROTE, which it gets by asking
    // PHP to read the same cell again in UTC, where nothing moves. Two earlier
    // versions were wrong and each is planted against these checks:
    //   * the first REFUSED such a cell -- a refusal by spelling (round 14's
    //     rule) -- so K07b asserts the STORED VALUE, and a refusal fails it
    //     exactly as a moved hour does;
    //   * the second rebuilt the reading from `date_parse()`'s FIELDS and
    //     stored the wrong DATE for `+1 weekday`, `first/last day of` and an
    //     ordinal date with `+1 month` -- K07e holds those classes OUTSIDE the
    //     gap to the original reader's answers, and K07b has them INSIDE it.
    //
    // PREMISE FIRST, as section I does: each cell must really name a time PHP
    // moves in that zone, or the checks below prove nothing.
    $gapCells = [
        ['Europe/London',    'Sun, 29 Mar 2026 01:30', '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    '03/29/2026 01:30',       '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    '2026-088 01:30',         '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    '2026-W13-7 01:30',       '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['America/New_York', 'Sun, 8 Mar 2026 2:30am', '2026-03-08 03:30', '2026-03-08 02:30:00'],
        ['America/Nuuk',     '28 March 2026 11:30pm',  '2026-03-29 00:30', '2026-03-28 23:30:00'],
        // Relative DATE rules whose result lands in the skipped hour. The
        // second was still stored moved by round 1's field rebuild.
        ['Europe/London',    '2026-03-28 01:30 +1 day',     '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    'sat 2026-03-28 01:30 +1 day', '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    '2027-059 01:30 +1 month',     '2027-03-28 02:30', '2027-03-28 01:30:00'],
        // Cairo's clocks go forward at midnight on Friday 30 April 2027, the
        // last day of the month.
        ['Africa/Cairo',     '2027-04-01 00:30 last day of', '2027-04-30 01:30', '2027-04-30 00:30:00'],
        // Beirut's go forward at midnight too: `tomorrow` with a DATE is
        // allowed (a bare `tomorrow` is refused by #797's rule).
        ['Asia/Beirut',      '2026-03-28 tomorrow 00:30',   '2026-03-29 01:30', '2026-03-29 00:30:00'],
        // SECONDS, so a UTC read that dropped them goes red here and not only
        // on the gate's text pin (the independent check's round 2).
        ['Europe/London',    'Sun, 29 Mar 2026 01:30:45',   '2026-03-29 02:30', '2026-03-29 01:30:45'],
        // DELIBERATE: net-zero arithmetic is no arithmetic. `date_parse()`
        // reports a relative time of zero, so these name the 01:30 they write
        // and are stored as it, exactly like `2026-03-29 01:30`.
        ['Europe/London',    '2026-03-29 01:30 +0 hours',       '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    '2026-03-29 01:30 +1 hour -1 hour', '2026-03-29 02:30', '2026-03-29 01:30:00'],
        // ⚠️ THREE CELLS THE INDEPENDENT CHECK'S ROUND 3 FOUND UNPINNED: each
        //    of these step-(d) faults put the moved hour back with section K
        //    at 184 of 184 --
        //      d5, the FRACTION left out of the canonical cell;
        //      d7, the WEEKDAY left out of the canonical cell;
        //      d1, the `weekdays` rule left out of the canonical cell.
        //    Each cell below needs that part of the canonical cell to match.
        ['Europe/London',    '03/29/2026 01:30:07.25',       '2026-03-29 02:30', '2026-03-29 01:30:07'],
        ['Europe/London',    'sunday 2026-03-28 01:30',      '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Europe/London',    'Sun, 28 Mar 2026 01:30',       '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Africa/Cairo',     '2027-04-29 00:30 +1 weekday',  '2027-04-30 01:30', '2027-04-30 00:30:00'],
        // ⚠️ AND TWO MORE THE INDEPENDENT CHECK'S ROUND 4 FOUND UNPINNED, with
        //    the same shape: the year rule and `first day of` each removed
        //    from the canonical cell left section K green. And a fraction
        //    `date_parse()` truncates to six digits, which an earlier comment
        //    wrongly said would fall back.
        ['Europe/London',    '2025-03-29 01:30 +1 year',     '2026-03-29 02:30', '2026-03-29 01:30:00'],
        ['Africa/Cairo',     'first day of next month 2014-07-15 00:30', '2014-08-01 01:30', '2014-08-01 00:30:00'],
        ['Europe/London',    '03/29/2026 01:30:00.9999995',  '2026-03-29 02:30', '2026-03-29 01:30:00'],
    ];
    $premiseWrong = [];
    $gapWrong     = [];
    foreach ($gapCells as [$gapZone, $gapCell, $movedTo, $written]) {
        date_default_timezone_set($gapZone);
        $moment = strtotime($gapCell);
        if ($moment === false || date('Y-m-d H:i', $moment) !== $movedTo) {
            $premiseWrong[] = $gapZone . ' ' . $gapCell;
        }
        $gapWhy = null;
        $gapOut = $fmtStore($gapCell, $gapWhy);
        if ($gapOut !== $written) {
            $gapWrong[] = $gapZone . ' ' . $gapCell . ' gave '
                . ($gapOut ?? ('REFUSED "' . (string) $gapWhy . '"')) . ', wanted ' . $written;
        }
    }
    date_default_timezone_set('Europe/London');
    check('K07 premise: PHP really moves each of these cells in its zone',
        $premiseWrong === [] ? 'all moved' : implode(' | ', $premiseWrong), 'all moved');
    check('K07b each STORES the reading it wrote -- not moved, and not refused',
        $gapWrong === [] ? 'all as written' : implode(' | ', $gapWrong), 'all as written');
    // Over-reach, the other direction: a time just after the gap, the hour
    // that happens TWICE, the ISO spelling of the gap reading, a cell whose
    // named weekday DISAGREES with its date (PHP moves it to the next Monday on
    // every day of the year, and step (d) must not make the gap night the one
    // exception), and `24:00` -- each stores exactly what it did before #785.
    //
    // ⚠️ AND A RELATIVE TIME ACROSS THE CHANGE, which is the over-reach that
    //    can actually happen now. Because step (d) reads the cell in UTC, and
    //    UTC agrees with the zone on every reading that exists, widening it to
    //    every hour changes nothing (measured -- so this cannot be caught
    //    there). What CAN go wrong is losing the "no relative time" gate:
    //    `2026-03-29 00:30 +1 hour` is an exact hour after 00:30, which is
    //    02:30 that morning, while PHP's UTC reading of it is 01:30, in the
    //    gap -- so without the gate it would be stored an hour EARLY.
    $keepWrong = [];
    foreach ([
        ['Sun, 29 Mar 2026 02:30',        '2026-03-29 02:30:00'],
        ['Sun, 25 Oct 2026 01:30',        '2026-10-25 01:30:00'],
        ['2026-03-29 01:30',              '2026-03-29 01:30:00'],
        ['Mon, 29 Mar 2026 01:30',        '2026-03-30 01:30:00'],
        ['2026-03-29 24:00',              '2026-03-30 00:00:00'],
        ['Mon, 01 Jun 2026 10:00:00 GMT', '2026-06-01 11:00:00'],
        ['2026-03-29 00:30 +1 hour',      '2026-03-29 02:30:00'],
        ['2026-03-29 00:45 +45 minutes',  '2026-03-29 02:30:00'],
        ['2026-03-28 01:30 +24 hours',    '2026-03-29 02:30:00'],
        // A MIXED zero -- an hour `date_parse()` reports, cancelled by msec it
        // does not -- is refused by step (d)'s first test and keeps the old
        // answer (round 3 stored it as written; round 4 does not).
        ['2026-03-29 01:30 +1 hour -3600000 msec',      '2026-03-29 02:30:00'],
        // A misreported date rule cancelled by REPORTED time arithmetic: the
        // canonical cell is fooled, the first test is not. Round 3, which had
        // dropped that test as "redundant", stored both at 01:30.
        ['next sunday 2026-03-29 01:30 -168 hours',     '2026-03-29 02:30:00'],
        ['last sunday of april 2026 01:30 -672 hours',  '2026-03-29 02:30:00'],
        // msec and usec on an ordinary day: exact time, nothing to move.
        ['2026-03-28 10:00 +3600000 msec',       '2026-03-28 11:00:00'],
        ['2026-03-28 10:00 +3600000000 usec',    '2026-03-28 11:00:00'],
        ['2026-03-28 10:00 +1800000 ms',         '2026-03-28 10:30:00'],
    ] as [$keepCell, $keepWant]) {
        $keepGot = $fmtStore($keepCell);
        if ($keepGot !== $keepWant) {
            $keepWrong[] = $keepCell . ' gave ' . ($keepGot ?? 'REFUSED');
        }
    }
    check('K07c nothing outside the skipped hour changes, and REPORTED time arithmetic keeps its old answer',
        $keepWrong === [] ? 'all stored' : implode(' | ', $keepWrong), 'all stored');
    // A cell IN the skipped hour that names its own zone or offset is an
    // INSTANT, and must still be converted onto the organisation's clock --
    // step (d) is for readings, never for instants.
    $instantWrong = [];
    foreach ([
        ['29 March 2026 1:30am +01:00',   '2026-03-29 00:30:00'],
        ['Sun, 29 Mar 2026 01:30:00 GMT', '2026-03-29 02:30:00'],
        ['2026-03-29T01:30:00Z',          '2026-03-29 02:30:00'],
    ] as [$instantCell, $instantWant]) {
        $instantGot = $fmtStore($instantCell);
        if ($instantGot !== $instantWant) {
            $instantWrong[] = $instantCell . ' gave ' . ($instantGot ?? 'REFUSED');
        }
    }
    check('K07d a cell in the skipped hour WITH a zone or offset is still converted as an instant',
        $instantWrong === [] ? 'all converted' : implode(' | ', $instantWrong), 'all converted');
    // K07e — THE THREE CLASSES ROUND 1 GOT WRONG, outside the gap, each held
    //        to the ORIGINAL reader's answer (measured with `git show
    //        e8a709c1^:web/_apps/calendar/manage/import.php`). Round 1 stored
    //        the first three as 2026-03-29, each with no refusal; the weird
    //        `last day of` after a weekday name (it jumps a month) is PHP's
    //        own rule and is kept, not judged.
    $classWrong = [];
    foreach ([
        ['2026-03-29 01:30 +1 weekday',        '2026-03-30 01:30:00'],
        ['Sun, 29 Mar 2026 01:30 last day of',  '2026-04-30 01:30:00'],
        ['Sun, 29 Mar 2026 01:30 first day of', '2026-03-01 01:30:00'],
        ['2026-057 01:30 +1 month',             '2026-03-26 01:30:00'],
        ['2026-02-26 01:30 +1 weekday',         '2026-02-27 01:30:00'],
        ['Thu, 26 Feb 2026 01:30 last day of',  '2026-03-31 01:30:00'],
    ] as [$classCell, $classWant]) {
        $classGot = $fmtStore($classCell);
        if ($classGot !== $classWant) {
            $classWrong[] = $classCell . ' gave ' . ($classGot ?? 'REFUSED') . ', wanted ' . $classWant;
        }
    }
    check('K07e +1 weekday, first/last day of and ordinal +1 month match the original reader',
        $classWrong === [] ? 'all as before' : implode(' | ', $classWrong), 'all as before');

    // K07f — TIME ARITHMETIC `date_parse()` CANNOT SEE. msec, ms,
    //        milliseconds, usec and microseconds are applied by PHP and
    //        reported nowhere in `date_parse()`'s `relative` array, so round 2
    //        of #785 read each of these as a plain reading and stored it an
    //        hour EARLY (the independent check found 2,444 such cells). An
    //        exact hour after 00:30 that morning is 02:30 on the clock, so the
    //        right answer is the line's old one. `+86400000 msec` is exactly a
    //        day, so it leaves the time of day unchanged: a test comparing
    //        only the clock time cannot catch it, which is why step (d)
    //        compares whole readings.
    //
    // PREMISE FIRST: each cell's own UTC reading must fall IN the skipped
    // hour, or a reader treating it as a reading would store it unchanged and
    // this check could not fail.
    $exactCells = [
        ['2026-03-29 00:30 +3600000 msec',          '2026-03-29 02:30:00'],
        ['2026-03-29 00:30 +3600000 ms',            '2026-03-29 02:30:00'],
        ['2026-03-29 00:30 +3600000 milliseconds',  '2026-03-29 02:30:00'],
        ['2026-03-29 00:30 +3600000000 usec',       '2026-03-29 02:30:00'],
        ['2026-03-29 00:30 +3600000000 microseconds', '2026-03-29 02:30:00'],
        ['2026-03-29 00:59 +60000001 usec',         '2026-03-29 02:00:00'],
        ['2026-03-28 01:30 +86400000 msec',         '2026-03-29 02:30:00'],
        ['2026-03-28 00:30 +1 day +3600000 msec',   '2026-03-29 02:30:00'],
    ];
    $exactPremise = [];
    $exactWrong   = [];
    foreach ($exactCells as [$exactCell, $exactWant]) {
        $exactRead = (new \DateTimeImmutable($exactCell, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        if (\Portal\Core\EventRecurrence::readingIsReal($exactRead, 'Europe/London') === true) {
            $exactPremise[] = $exactCell;
        }
        $exactGot = $fmtStore($exactCell);
        if ($exactGot !== $exactWant) {
            $exactWrong[] = $exactCell . ' gave ' . ($exactGot ?? 'REFUSED') . ', wanted ' . $exactWant;
        }
    }
    check('K07f premise: each exact-time cell, read in UTC, falls in the skipped hour',
        $exactPremise === [] ? 'all in the gap' : implode(' | ', $exactPremise), 'all in the gap');
    check('K07f2 msec/usec time arithmetic keeps its EXACT answer, not stored an hour early',
        $exactWrong === [] ? 'all exact' : implode(' | ', $exactWrong), 'all exact');

    // K07g — NO PHP WARNING FROM STEP (d). For `<weekday> N weeks ago` with
    //        ONE `ago`, `date_parse()` reports a relative weekday of -1
    //        (monday) to -7 (sunday); with `ago ago` it reports the weekday's
    //        own number, +1 to +6, and +7 only for sunday (measured -- earlier
    //        versions of this comment said "-7, -6 or -1" and then "+7 for
    //        `ago ago`"). So only the one-`ago` cells and the sunday `ago ago`
    //        cell fall outside 0-6. Round 4 looked such a value up in the
    //        day-name list:
    //        "Undefined array key -7", logged to tblErrors for every such row
    //        and visible on dev and beta (the independent check's round 4).
    //        Such a cell keeps the old answer now. The warning is caught by a
    //        handler here because the value alone cannot show it: PHP carries
    //        on after the warning. The `monday` cell (-1), the `tuesday` cell
    //        (-2) and the sunday `ago ago` cell (+7) test the guard's two
    //        bounds behaviourally -- loosen either bound, even by one step,
    //        and a cell warns. (Without the monday cell, loosening the lower
    //        bound to `>= -1` left this test green and only the gate's text
    //        pin caught it.)
    $warned = [];
    $weeksWrong = [];
    set_error_handler(static function (int $no, string $msg) use (&$warned): bool {
        $warned[] = $msg;
        return true;
    });
    foreach ([
        ['sunday 1 week ago 2026-04-05 01:30',                   '2026-03-29 02:30:00'],
        ['saturday 2 weeks ago 2026-04-12 01:30 +86400000 msec', '2026-03-29 02:30:00'],
        ['monday 1 week ago 2026-04-06 01:30',                   '2026-03-23 01:30:00'],
        ['tuesday 1 week ago 2026-03-31 01:30',                  '2026-03-17 01:30:00'],
        ['sunday 1 week ago ago 2026-03-22 01:30',               '2026-04-05 01:30:00'],
    ] as [$weeksCell, $weeksWant]) {
        $weeksGot = $fmtStore($weeksCell);
        if ($weeksGot !== $weeksWant) {
            $weeksWrong[] = $weeksCell . ' gave ' . ($weeksGot ?? 'REFUSED') . ', wanted ' . $weeksWant;
        }
    }
    restore_error_handler();
    check('K07g a weekday date_parse() reports outside 0-6 raises no PHP warning',
        $warned === [] ? 'no warning' : implode(' | ', array_unique($warned)), 'no warning');
    check('K07g2 and such a cell keeps the old answer',
        $weeksWrong === [] ? 'as before' : implode(' | ', $weeksWrong), 'as before');

    // K03 — THE PREMISE, so K02 cannot pass by refusing everything. If the
    //       closure simply returned null the checks above would both be
    //       satisfied in one direction and K01 would catch it -- but state it
    //       explicitly, because a reader should not have to work that out.
    check('K03 the closure really does store something, so K02 is not vacuous',
        $fmtStore('2026-06-01 10:00'), '2026-06-01 10:00:00');

    date_default_timezone_set($wasZone);
}

date_default_timezone_set($wasZone);

// ══════════════════════════════════════════════════════════════════════════
// SECTION L — #777 PART TWO: AN EVENT'S STORED READING, ON THE WAY OUT
// ══════════════════════════════════════════════════════════════════════════
// 🚨 #549's TRAP, STATED FIRST: every check here would pass on the OLD code on
//    an ordinary day, because the old `date(…, strtotime($stored))` and the new
//    code agree on every day except the one where the hour does not exist. So
//    every case below is a FIXED reading inside a FIXED skipped hour, in a zone
//    that really skips it; the premise (the hour really is missing) is asserted
//    BEFORE anything relies on it; and the OLD behaviour is shown beside each,
//    so no check can pass by the old code being right that day too.
//
//    Twenty-four pages put `tblEvents.startDateTime`/`endDateTime` through
//    `strtotime()` or a zone-less DateTime. They were converted to FOUR
//    methods of `I18n`, each driven here directly; the hard gate
//    `check_clock_time_not_a_moment.py` is what stops a PAGE going back to
//    the old call. Between them: a page that goes back to `strtotime()` or a
//    zone-less DateTime fails the gate, and a method that reverts fails here.
//
//    ⚠️ NOT EVERY PAGE FAULT. A page that keeps the new methods but passes them
//       the WRONG ZONE — the organisation's or PHP's instead of the event's —
//       is invisible to both, because the gate cannot see which zone a call
//       is given. #777 part two's check planted three such reverts (the
//       countdown feed, the list view's past/today, the day columns) with
//       everything green; that is #805. An earlier version of this sentence
//       said "a fault is caught on either side", which was untrue.
//
//      formatClockDateTime()  -- words (the ONE place, #784)
//      wallClockReading()     -- an object for the calendar grids to walk
//      wallClockNow()         -- "has it begun?" on the EVENT's clock
//      wallClockInstant()     -- a real instant, for a countdown or a feed
echo "\n-- Section L: an event's stored reading, on the way OUT (#777 part two, #784) --\n";
require_once PORTAL_CORE . $sep . 'FeedAdmin.php';

$wasZoneL = date_default_timezone_get();

/** Is this reading really missing from that zone's clock? (PHP moves it.) */
$readingIsMissing = static function (string $reading, string $zone): bool {
    $moment = new DateTimeImmutable($reading, new DateTimeZone($zone));

    return $moment->format('Y-m-d H:i:s') !== $reading;
};

// The three skipped hours the brief names, and the one that crosses midnight.
$skipped = [
    'Europe/London'    => '2027-03-28 01:30:00',
    'America/New_York' => '2027-03-14 02:30:00',
    'Pacific/Auckland' => '2028-09-24 02:30:00',
    // Greenland goes forward at 23:00, so a reading of 23:30 that evening
    // lands on the NEXT DAY -- the case that put an event in the wrong cell
    // of the month grid, not merely an hour low.
    'America/Nuuk'     => '2027-03-27 23:30:00',
];

foreach ($skipped as $zone => $reading) {
    // L00 -- THE PREMISE. Without it every check below is vacuous.
    check("L00 premise: {$reading} really does not exist in {$zone}", $readingIsMissing($reading, $zone), true);

    // Pages run with PHP's default zone set to the organisation's, by
    // bootstrap.php; this is the organisation whose clock skips the hour.
    date_default_timezone_set($zone);
    $hm = substr($reading, 11, 5);

    check("L01 words: the reading comes out as stored in {$zone}",
        I18n::formatClockDateTime($reading, 'Y-m-d H:i'), substr($reading, 0, 16));
    check("L02 ...and the OLD date(strtotime()) really moved it, so L01 is not vacuous",
        date('Y-m-d H:i', (int) strtotime($reading)) !== substr($reading, 0, 16), true);

    $object = I18n::wallClockReading($reading);
    check("L03 the grid's object keeps the reading in {$zone}",
        $object !== null ? $object->format('Y-m-d H:i') : 'null', substr($reading, 0, 16));
    check("L04 ...and the OLD new DateTimeImmutable() really moved it",
        (new DateTimeImmutable($reading))->format('Y-m-d H:i') !== substr($reading, 0, 16), true);
    check("L05 the grid's object stays on the reading's own DAY in {$zone}",
        $object !== null ? $object->format('Y-m-d') : 'null', substr($reading, 0, 10));
    check("L06 walking it one day on keeps the same clock time ({$hm})",
        $object !== null ? $object->modify('+1 day')->format('H:i') : 'null', $hm);
}

// L07 -- THE DAY, NOT JUST THE HOUR. In Nuuk the old reading crossed midnight,
//        which is what put an event in the WRONG CELL of the month grid and
//        what made `formatDate()` -- handed the time as well as the date --
//        print the next day on the event page and the list. Both pages now
//        hand it the DATE alone, and a date with no time has no hour to move.
date_default_timezone_set('America/Nuuk');
check('L07 OLD: the Nuuk reading came out on the NEXT DAY',
    date('Y-m-d', (int) strtotime('2027-03-27 23:30:00')), '2027-03-28');
check('L08 NEW: the date alone, as event.php and list.php now pass it to formatDate(), stays put',
    date('Y-m-d', (int) strtotime((string) I18n::wallClockReading('2027-03-27 23:30:00')?->format('Y-m-d'))), '2027-03-27');

// ── The calendar grids compare readings with readings, never with PHP's zone ──
// `_day_columns.php` used to bucket an event into a day column by comparing
// it with the column's midnight in PHP's DEFAULT zone. With the event now an
// object built in UTC, a column built in Auckland would be compared across
// two clocks (thirteen hours apart), so the columns are readings too.
date_default_timezone_set('Pacific/Auckland');
$col0 = I18n::wallClockReading('2028-09-24 00:00:00');
$col1 = I18n::wallClockReading('2028-09-24 23:59:59');
$ev   = I18n::wallClockReading('2028-09-24 02:30:00');
check('L09 a reading inside the skipped hour falls inside its own day column',
    $ev !== null && $col0 !== null && $col1 !== null && $ev >= $col0 && $ev <= $col1, true);
// L10 -- WHY THE COLUMNS HAD TO BECOME READINGS TOO, shown rather than said.
//    An evening reading compared with a column built on PHP's (Auckland's)
//    clock falls OUTSIDE its own day, because the two objects are thirteen
//    hours apart. (A first version of this check used 02:30, which happens to
//    land inside either way -- a check that cannot fail; 20:00 cannot.)
$evening = I18n::wallClockReading('2028-09-24 20:00:00');
check('L10 an evening reading falls inside its own day column, read against reading-columns',
    $evening !== null && $evening >= $col0 && $evening <= $col1, true);
check('L10b ...and against a column built on PHP\'s zone (the shape avoided) it would NOT',
    $evening !== null && $evening >= new DateTimeImmutable('2028-09-24 00:00:00') && $evening <= new DateTimeImmutable('2028-09-24 23:59:59'), false);
check('L11 the day column draws it at 02:30, not 03:30',
    $ev !== null ? ((int) $ev->format('H')) * 60 + (int) $ev->format('i') : -1, 150);

// ── "Has it begun?" is asked on the EVENT's clock ────────────────────────────
// `views/list.php` greyed an event out as past with `new DateTime($start) <
// new DateTime()`, both on PHP's default zone -- the ORGANISATION's. An event
// kept on another clock was judged by the wrong one. The organisation here is
// in Auckland and the event in New York: between sixteen and eighteen hours
// apart all year, so a two-hour margin cannot be tipped by the season.
date_default_timezone_set('Pacific/Auckland');
$nyZone   = new DateTimeZone('America/New_York');
$nyNow    = I18n::wallClockNow($nyZone);
$inTwoHrs = (new DateTimeImmutable('now', $nyZone))->modify('+2 hours')->format('Y-m-d H:i:s');
$twoAgo   = (new DateTimeImmutable('now', $nyZone))->modify('-2 hours')->format('Y-m-d H:i:s');
check('L12 premise: wallClockNow() reads the New York clock, not PHP\'s (Auckland)',
    $nyNow !== date('Y-m-d H:i:s') && abs(strtotime($nyNow . ' UTC') - strtotime(date('Y-m-d H:i:s') . ' UTC')) >= 15 * 3600, true);
check('L13 a New York event two hours away is NOT past, on its own clock',
    strcmp($inTwoHrs, I18n::wallClockNow($nyZone)) < 0, false);
check('L14 ...and the OLD comparison, on Auckland\'s clock, called it past',
    new DateTime($inTwoHrs) < new DateTime(), true);
check('L15 a New York event two hours ago IS past, on its own clock (no over-reach)',
    strcmp($twoAgo, I18n::wallClockNow($nyZone)) < 0, true);

// ── An INSTANT, on the event's own clock ─────────────────────────────────────
// The public countdown feed and the public landing page published
// `date('c', strtotime($start))`, on the ORGANISATION's clock.
check('L16 an instant is taken on the EVENT\'s clock, with its offset',
    I18n::wallClockInstant('2027-06-01 19:00:00', $nyZone)?->format('c'), '2027-06-01T19:00:00-04:00');
check('L17 ...and the OLD way stamped it with Auckland\'s offset (hours out)',
    date('c', (int) strtotime('2027-06-01 19:00:00')), '2027-06-01T19:00:00+12:00');
check('L18 the skipped hour resolves forward by the gap (RFC 5545 3.3.5): 02:30 New York is 07:30 UTC',
    I18n::wallClockInstant('2027-03-14 02:30:00', $nyZone)?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'), '2027-03-14 07:30');
check('L19 a reading that is not real gives NO instant, not a confident wrong one',
    I18n::wallClockInstant('2027-02-31 10:00:00', $nyZone), null);

// ── Zoom is told the event's clock by name, so it must get the reading as written ──
date_default_timezone_set('Europe/London');
check('L20 zoom-create.php\'s start_time is the reading itself',
    I18n::wallClockReading('2027-03-28 01:30:00')?->format('Y-m-d\TH:i:s'), '2027-03-28T01:30:00');
check('L21 ...where the OLD round trip sent the meeting an hour late',
    date('Y-m-d\TH:i:s', (int) strtotime('2027-03-28 01:30:00')), '2027-03-28T02:30:00');
$zStart = I18n::wallClockInstant('2027-03-28 00:30:00', new DateTimeZone('Europe/London'));
$zEnd   = I18n::wallClockInstant('2027-03-28 02:30:00', new DateTimeZone('Europe/London'));
check('L22 a meeting\'s LENGTH across the skipped hour is the real one (00:30-02:30 lasts one hour)',
    $zStart !== null && $zEnd !== null ? intdiv($zEnd->getTimestamp() - $zStart->getTimestamp(), 60) : -1, 60);

// ── #784: ONE place for the words, and showWallClock() is a caller of it ─────
check('L23 showWallClock() on the change morning keeps the reading and names a different zone',
    \Portal\Core\FeedAdmin::showWallClock('2027-03-28 01:30:00', 'America/New_York', false, new DateTimeZone('Europe/London')),
    'Sun 28 Mar 2027, 01:30 (America/New_York)');
check('L24 ...an all-day date reads "(all day)"',
    \Portal\Core\FeedAdmin::showWallClock('2027-03-28 00:00:00', null, true, new DateTimeZone('Europe/London')), 'Sun 28 Mar 2027 (all day)');
check('L25 ...and text that is not a reading is handed back, as before',
    \Portal\Core\FeedAdmin::showWallClock('not a time', null, false, new DateTimeZone('UTC')), 'not a time');
check('L26 an impossible date is refused by BOTH, not rolled into March',
    \Portal\Core\FeedAdmin::showWallClock('2027-02-31 10:00:00', null, false, new DateTimeZone('UTC')) . ' | ' . I18n::formatClockDateTime('2027-02-31 10:00:00'),
    '2027-02-31 10:00:00 | 2027-02-31 10:00:00');

// =============================================================================
// Section M -- #806: WHICH DAYS an event is on, decided by its readings
// =============================================================================
// `I18n::wallClockDaySpan()` / `wallClockDays()` are the ONE place the month
// grid, the year grid and the attendance register ask which calendar days an
// event covers. Two faults, each shown against the OLD code beside the new so
// neither check can pass vacuously.
echo "\n-- Section M: which days an event is on (#806) --\n";

// M00 -- THE PREMISE: in Nuuk 23:30 on 27 March 2027 does not exist, and PHP
//        moves it onto the 28th. Without this, M01-M02 prove nothing.
check('M00 premise: 2027-03-27 23:30 does not exist in America/Nuuk and PHP moves it to the NEXT DAY',
    (new DateTimeImmutable('2027-03-27 23:30:00', new DateTimeZone('America/Nuuk')))->format('Y-m-d'), '2027-03-28');
// M01 -- the attendance register's OLD day: a moment in the event's zone, then
//        midnight. It offered the 28th, a day the event was not on.
check('M01 OLD: the attendance register\'s moment-then-midnight listed the Nuuk event on the 28th',
    (new DateTimeImmutable('2027-03-27 23:30:00', new DateTimeZone('America/Nuuk')))->setTime(0, 0)->format('Y-m-d'), '2027-03-28');
// M02 -- NEW: the day comes off the reading, whatever PHP's zone is.
date_default_timezone_set('America/Nuuk');
check('M02 NEW: the Nuuk event is on the 27th and only the 27th',
    I18n::wallClockDays('2027-03-27 23:30:00', '2027-03-27 23:55:00'), ['2027-03-27']);
check('M03 ...and its span says so too (the register\'s "last day" badge reads this)',
    I18n::wallClockDaySpan('2027-03-27 23:30:00', '2027-03-28 00:45:00'), ['first' => '2027-03-27', 'last' => '2027-03-28']);

// M04 -- THE LAST DAY of a multi-day event that ends EARLIER in the day than
//        it began. The grids walked from the start READING while `<=` the end
//        READING, so 06-03 10:00 was "after" 06-03 09:00 and the 3rd was lost.
//        The OLD walk is run here exactly as the month view had it.
$oldWalk = static function (string $s, string $e): array {
    $start = I18n::wallClockReading($s);
    $end   = I18n::wallClockReading($e);
    $out   = [];
    for ($cur = $start; $cur <= $end; $cur = $cur->modify('+1 day')) {
        $out[] = $cur->format('Y-m-d');
    }

    return $out;
};
check('M04 OLD: the grids\' walk left out the last day of 06-01 10:00 to 06-03 09:00',
    $oldWalk('2026-06-01 10:00:00', '2026-06-03 09:00:00'), ['2026-06-01', '2026-06-02']);
check('M05 NEW: all three days',
    I18n::wallClockDays('2026-06-01 10:00:00', '2026-06-03 09:00:00'), ['2026-06-01', '2026-06-02', '2026-06-03']);

// M06-M07 -- AN END OF EXACTLY MIDNIGHT. Walking by date alone would put an
//        evening event from 19:00 to midnight on the next day too, which the
//        old walk happened never to do. Both sides pinned, so neither a
//        "simplification" that drops the rule nor one that over-applies it
//        passes.
check('M06 an evening event ending at 00:00 is on ONE day, not two',
    I18n::wallClockDays('2026-06-01 19:00:00', '2026-06-02 00:00:00'), ['2026-06-01']);
check('M07 ...but one ending at 00:01 really is on the second day as well',
    I18n::wallClockDays('2026-06-01 19:00:00', '2026-06-02 00:01:00'), ['2026-06-01', '2026-06-02']);
check('M08 an all-day import (ends 23:59:59 on its last day) keeps all three days',
    I18n::wallClockDays('2026-12-24 00:00:00', '2026-12-26 23:59:59'), ['2026-12-24', '2026-12-25', '2026-12-26']);
check('M09 an end before the start gives the start day alone, as the register always did',
    I18n::wallClockDays('2026-06-03 10:00:00', '2026-06-01 09:00:00'), ['2026-06-03']);
check('M10 no end gives the start day alone',
    I18n::wallClockDays('2026-06-03 10:00:00', null), ['2026-06-03']);

// M11-M13 -- THE WINDOW AND THE CAP. The year view passes its own year, the
//        month view a week either side of its month, the register a cap of
//        14. An event ending in 9999 must cost the window, not millennia.
check('M11 the window clips both ends',
    I18n::wallClockDays('2026-12-30 10:00:00', '2027-01-02 09:00:00', '2026-12-31', '2027-01-01'), ['2026-12-31', '2027-01-01']);
// (First version counted the days only: 2020 is a leap year too, so with the
// window's start ignored the walk gave 2020's 366 days and still passed.)
$m12 = I18n::wallClockDays('2020-01-01 00:00:00', '9999-12-31 23:59:59', '2028-01-01', '2028-12-31');
check('M12 an event ending in 9999 costs only the year asked for: 2028-01-01 to 2028-12-31, 366 days',
    [$m12[0] ?? null, $m12[count($m12) - 1] ?? null, count($m12)], ['2028-01-01', '2028-12-31', 366]);
check('M13 the cap stops the list (the register shows at most 14 columns)',
    count(I18n::wallClockDays('2026-06-01 10:00:00', '2026-07-01 09:00:00', null, null, 14)), 14);
check('M14 the days step across Nuuk\'s spring change night one date at a time (a 23-hour day is still one day)',
    I18n::wallClockDays('2027-03-27 23:30:00', '2027-03-29 01:30:00'), ['2027-03-27', '2027-03-28', '2027-03-29']);
check('M14b ...and across Nuuk\'s autumn one (a 25-hour day, 2027-10-30 into the 31st)',
    I18n::wallClockDays('2027-10-29 22:00:00', '2027-10-31 01:00:00'), ['2027-10-29', '2027-10-30', '2027-10-31']);
check('M15 a start that is not a reading gives no days and no span',
    [I18n::wallClockDays('2027-02-31 10:00:00', null), I18n::wallClockDaySpan('not a time', null)], [[], null]);

// ---- N: a stored reading moved on to its next occurrence (#1041) ----
// I18n::wallClockMovedOn() is what a recurring task's next reminder comes
// from. PHP's default zone is London's here on purpose: the method must not
// read it, so a 01:30 moved onto London's spring-forward morning stays 01:30.
date_default_timezone_set('Europe/London');
check('N01 it refuses (null) an unknown unit, a count of 0 or below, and text that is not a reading',
    [
        I18n::wallClockMovedOn('2027-03-01 09:00:00', 'hours', 1),
        I18n::wallClockMovedOn('2027-03-01 09:00:00', 'days', 0),
        I18n::wallClockMovedOn('2027-03-01 09:00:00', 'days', -1),
        I18n::wallClockMovedOn('2027-02-31 09:00:00', 'days', 1),
        I18n::wallClockMovedOn(null, 'days', 1),
    ],
    [null, null, null, null, null]);
check('N02 onto the morning the clocks go forward in London, each unit keeps 01:30 (default zone London)',
    [
        I18n::wallClockMovedOn('2027-03-27 01:30:00', 'days', 1),
        I18n::wallClockMovedOn('2027-03-21 01:30:00', 'weeks', 1),
        I18n::wallClockMovedOn('2027-02-28 01:30:00', 'months', 1),
        I18n::wallClockMovedOn('2026-03-28 01:30:00', 'years', 1),
        I18n::wallClockMovedOn('2027-03-14 01:30:00', 'weeks', 2),
    ],
    array_fill(0, 5, '2027-03-28 01:30:00'));
check('N03 a month-end rolls over as modify() does, as its docblock says: 31 Jan + 1 month is 3 March, 2 March in a leap year, and 29 Feb + 1 year is 1 March',
    [
        I18n::wallClockMovedOn('2027-01-31 09:00:00', 'months', 1),
        I18n::wallClockMovedOn('2028-01-31 09:00:00', 'months', 1),
        I18n::wallClockMovedOn('2028-02-29 09:00:00', 'years', 1),
    ],
    ['2027-03-03 09:00:00', '2028-03-02 09:00:00', '2029-03-01 09:00:00']);

date_default_timezone_set($wasZoneL);

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "Sections A-F cover the ARITHMETIC of #549's mistake 3. G and H cover TWO\n";
echo "instances of mistake 1 — a time on a clock treated as a moment — the four\n";
echo "display sites and the three booking readers #777 fixed. The rest of\n";
echo "mistake 1 still needs reading: a reading pulled into a variable and\n";
echo "converted several lines later, or a call split over lines, is invisible to\n";
echo "both this and check_clock_time_not_a_moment.py, which says so too.\n";
echo "Section L drives the four I18n methods #777 part two converted twenty-four\n";
echo "event pages to; whether each PAGE still calls them is the gate's question,\n";
echo "and the HTTP half is part X of tools/time-zones-selftest.php.\n";
echo "Section M drives the one place the grids and the attendance register ask\n";
echo "which DAYS an event is on (#806); whether each page asks it is again the\n";
echo "HTTP half's question.\n";
exit($failed === 0 ? 0 : 1);
