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
// the HTTP test. The behavioural gap that remains -- nothing POSTS a CSV --
// is #787.
echo "\n";
$importSrc = (string) file_get_contents(
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
    . DIRECTORY_SEPARATOR . 'calendar' . DIRECTORY_SEPARATOR . 'manage'
    . DIRECTORY_SEPARATOR . 'import.php'
);
if (preg_match('/\$fmtClock = static function.*?\n\};/s', $importSrc, $grab) !== 1) {
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
    eval($grab[0]);

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
        ['20260601T100000Z',              '2026-06-01 11:00:00', 'round 12 refused it'],
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
        ['2026-6-1 10:00:00.000+01:00',   '2026-06-01 10:00:00', 'round 15 refused it'],
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
        ['2026-06-01 19.30',              '2026-06-01 19:30:00', 'round 16 refused it'],
        ['2026-06-01 00.01',              '2026-06-01 00:01:00', 'round 16 refused it'],
        ['2026-06-01 23.59',              '2026-06-01 23:59:00', 'round 16 refused it'],
        ['Tue, 3 Mar 2026 19.30',         '2026-03-03 19:30:00', "round 16 refused it — Excel's long date format"],
        ['March 3 2026 19.30',            '2026-03-03 19:30:00', 'round 16 refused it'],
        ['1 June 2026 19.30',             '2026-06-01 19:30:00', 'round 16 refused it'],
        ['2026-06-01 10.0',               '2026-06-01 10:00:00', 'a zero fraction means the same either way'],
        ['2026-06-01 19.30:00',           '2026-06-01 19:30:00', 'round 16 stored this while refusing 19.30 — inconsistent'],
        // dotted European dates, which must not be mistaken for a fraction
        ['01.06.2026 10:00',              '2026-06-01 10:00:00', ''],
        ['31.12.2026',                    '2026-12-31 00:00:00', ''],
        ['1.6.2026 10:00',                '2026-06-01 10:00:00', ''],
        // a real leap day, so the impossible-date rule cannot just refuse 29 February
        ['2028-02-29 10:00',              '2028-02-29 10:00:00', ''],
    ];
    $storeBad = [];
    foreach ($mustStore as [$cell, $want, $hist]) {
        $got = $fmtClock($cell);
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
        '0000-00-00 10:00', '0000-00-00 00:00:00',
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
        '2026-06-01 10.5 GMT+1', '2026-06-01 10.5 UTC+1',
        '2026-06-01 10.5 Etc/GMT+1', '2026-06-01 10.5 America/Port-au-Prince',
        '2026-06-01 10.5 Asia/Ust-Nera', '2026-06-01 10.5.',
        'rubbish',
    ];
    $refuseBad = [];
    foreach ($mustRefuse as $cell) {
        $got = $fmtClock($cell);
        if ($got !== null) {
            $refuseBad[] = $cell . ' -> ' . $got;
        }
    }
    check('K02 all ' . count($mustRefuse) . ' impossible or misreadable cells are refused',
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
    //       ONLY thing guarding the import reader.
    check('K04 the must-store list has not shrunk', count($mustStore), 47);
    check('K04b the must-refuse list has not shrunk', count($mustRefuse), 48);

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
    $reasonFor = [
        '2026-02-31 10:00'  => 'is a date that does not exist',
        '0000-00-00 10:00'  => 'is a date that does not exist',
        'Feb 31 10:00'      => 'is a date that does not exist',
        '2026-06-01 10.5'   => 'writes the time with a single digit after a decimal point, which could '
                             . 'mean either minutes or part of an hour',
        '1 June 2026 10.5'  => 'writes the time with a single digit after a decimal point, which could '
                             . 'mean either minutes or part of an hour',
        'rubbish'           => 'cannot be read',
        'not a date at all' => 'cannot be read',
    ];
    $reasonsWrong = [];
    foreach ($reasonFor as $cell => $wantReason) {
        $gotReason = null;
        $stored    = $fmtClock($cell, $gotReason);
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

    // K03 — THE PREMISE, so K02 cannot pass by refusing everything. If the
    //       closure simply returned null the checks above would both be
    //       satisfied in one direction and K01 would catch it -- but state it
    //       explicitly, because a reader should not have to work that out.
    check('K03 the closure really does store something, so K02 is not vacuous',
        $fmtClock('2026-06-01 10:00'), '2026-06-01 10:00:00');

    date_default_timezone_set($wasZone);
}

date_default_timezone_set($wasZone);

echo "\n";
echo ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
echo "Sections A-F cover the ARITHMETIC of #549's mistake 3. G and H cover TWO\n";
echo "instances of mistake 1 — a time on a clock treated as a moment — the four\n";
echo "display sites and the three booking readers #777 fixed. The rest of\n";
echo "mistake 1 still needs reading: a reading pulled into a variable and\n";
echo "converted several lines later, or a call split over lines, is invisible to\n";
echo "both this and check_clock_time_not_a_moment.py, which says so too.\n";
exit($failed === 0 ? 0 : 1);
