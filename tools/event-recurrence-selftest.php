<?php
// Path: tools/event-recurrence-selftest.php
/**
 * -----------------------------------------------------------------------------
 * The dates a repeating event produces 🔁
 * -----------------------------------------------------------------------------
 * Checks `Portal\Core\EventRecurrence` on its own. NEEDS NO DATABASE and no web
 * server: the class is arithmetic over a wall-clock reading, which is why it was
 * written that way.
 *
 * WHAT IT IS REALLY FOR. The portal could not create a repeating event at all
 * until #569, and the hard part is not producing a list of dates — it is
 * producing the RIGHT dates on the two nights a year the clocks change, and in
 * the months that have no 31st. Those are the cases this project has been
 * caught by before: five rounds of checking walked past an overnight event that
 * ended an hour late in October, because it was correctness rather than a
 * crash, and because every test had been written in December in London, where
 * nothing changes.
 *
 * So the clock-change checks here are deliberately in a zone that really
 * changes, on the real nights, in BOTH directions, and one of them is in a zone
 * that changes on a different date from Europe's so that a London-shaped
 * assumption cannot pass.
 *
 * Usage:  php tools/event-recurrence-selftest.php
 * Exit:   0 only when every check ran and passed; 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
    . DIRECTORY_SEPARATOR . 'EventRecurrence.php';

use Portal\Core\EventRecurrence;

$passed = 0;
$failed = 0;

function ok(string $id, string $words): void
{
    global $passed;
    $passed++;
    echo "PASS — {$id} {$words}\n";
}

function bad(string $id, string $words, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "FAIL — {$id} {$words}\n";
    if ($detail !== '') {
        echo "         {$detail}\n";
    }
}

/** @param array<string,mixed> $over */
function rule(array $over = []): array
{
    return $over + [
        'frequency'      => 'weekly',
        'intervalVal'    => 1,
        'dayOfWeek'      => null,
        'dayOfMonth'     => null,
        'weekOfMonth'    => null,
        'monthOfYear'    => null,
        'startDate'      => null,
        'endDate'        => null,
        'maxOccurrences' => null,
    ];
}

/** The start readings only, for comparing against an expected list. */
function starts(array $result): array
{
    return array_map(static fn (array $d): string => $d['start'], $result['dates']);
}

function expect(string $id, string $words, array $got, array $want): void
{
    if ($got === $want) {
        ok($id, $words);
        return;
    }
    bad($id, $words, 'got  ' . json_encode($got) . "\n         want " . json_encode($want));
}

echo "=== A. The ordinary patterns ===\n";

$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2026-11-01 10:00:00', '2026-11-01 11:30:00', 'Europe/London');
expect('A01', 'weekly, three times, keeps the time of day', starts($r), [
    '2026-11-01 10:00:00', '2026-11-08 10:00:00', '2026-11-15 10:00:00',
]);
if (($r['dates'][1]['end'] ?? '') === '2026-11-08 11:30:00') {
    ok('A02', 'and each one ends at the same time of day');
} else {
    bad('A02', 'the end should be 11:30 on each date', json_encode($r['dates'][1] ?? []));
}

$r = EventRecurrence::datesFor(rule(['frequency' => 'fortnightly', 'maxOccurrences' => 3]), '2026-11-01 10:00:00', null, 'Europe/London');
expect('A03', 'fortnightly steps two weeks', starts($r), [
    '2026-11-01 10:00:00', '2026-11-15 10:00:00', '2026-11-29 10:00:00',
]);

// 2026-11-04 is a Wednesday. Ticking Monday and Wednesday must not produce the
// Monday that has already gone.
$r = EventRecurrence::datesFor(rule(['dayOfWeek' => '1,3', 'maxOccurrences' => 4]), '2026-11-04 19:00:00', null, 'Europe/London');
expect('A04', 'two weekdays a week, and never a day before the first occurrence', starts($r), [
    '2026-11-04 19:00:00', '2026-11-09 19:00:00', '2026-11-11 19:00:00', '2026-11-16 19:00:00',
]);

$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'dayOfMonth' => 15, 'maxOccurrences' => 3]), '2026-11-15 09:00:00', null, 'Europe/London');
expect('A05', 'monthly on the 15th', starts($r), [
    '2026-11-15 09:00:00', '2026-12-15 09:00:00', '2027-01-15 09:00:00',
]);

// The second Tuesday of each month. 2026-11-10 is one.
$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'weekOfMonth' => 2, 'dayOfWeek' => '2', 'maxOccurrences' => 3]), '2026-11-10 19:30:00', null, 'Europe/London');
expect('A06', 'the second Tuesday of each month', starts($r), [
    '2026-11-10 19:30:00', '2026-12-08 19:30:00', '2027-01-12 19:30:00',
]);

$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'weekOfMonth' => -1, 'dayOfWeek' => '0', 'maxOccurrences' => 3]), '2026-11-29 18:00:00', null, 'Europe/London');
expect('A07', 'the LAST Sunday of each month', starts($r), [
    '2026-11-29 18:00:00', '2026-12-27 18:00:00', '2027-01-31 18:00:00',
]);

$r = EventRecurrence::datesFor(rule(['frequency' => 'quarterly', 'dayOfMonth' => 5, 'maxOccurrences' => 3]), '2026-11-05 14:00:00', null, 'Europe/London');
expect('A08', 'quarterly steps three months', starts($r), [
    '2026-11-05 14:00:00', '2027-02-05 14:00:00', '2027-05-05 14:00:00',
]);

$r = EventRecurrence::datesFor(rule(['frequency' => 'yearly', 'monthOfYear' => 12, 'dayOfMonth' => 25, 'maxOccurrences' => 3]), '2026-12-25 11:00:00', null, 'Europe/London');
expect('A09', 'yearly on the same date', starts($r), [
    '2026-12-25 11:00:00', '2027-12-25 11:00:00', '2028-12-25 11:00:00',
]);

echo "\n=== B. A day a month does not have is SKIPPED, never moved ===\n";

// From November: December has a 31st, January has one, February does not.
$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'dayOfMonth' => 31, 'endDate' => '2027-04-30']), '2026-12-31 12:00:00', null, 'Europe/London');
expect('B01', 'the 31st: no date in February, April or any short month', starts($r), [
    '2026-12-31 12:00:00', '2027-01-31 12:00:00', '2027-03-31 12:00:00',
]);

// 29 February, and A REAL LIMIT WORTH KNOWING. The next leap day is four
// years away, and the DEFAULT horizon is three years, so a
// yearly series on 29 February produces exactly ONE date and says it was cut
// short. That is the caps working as designed, not a bug — but it does mean
// this one pattern cannot be set up in a single go, and the person has to
// extend it later. Asserted here so nobody "fixes" it by clamping 29 February
// to the 28th, which would put the event on a day nobody chose.
$r = EventRecurrence::datesFor(rule(['frequency' => 'yearly', 'monthOfYear' => 2, 'dayOfMonth' => 29, 'endDate' => '2040-12-31']), '2028-02-29 10:00:00', null, 'Europe/London');
expect('B02', '29 February: no date in a non-leap year, and never the 28th instead', starts($r), [
    '2028-02-29 10:00:00',
]);
if ($r['capped'] === true && $r['why'] !== '') {
    ok('B02b', 'and the person is told the series was cut short, because the next leap day is beyond the limit');
} else {
    bad('B02b', 'a leap-day series that reaches only one date must say it was cut short', json_encode($r));
}

// The fifth Monday. November 2026 has 5 Mondays (2,9,16,23,30); December 2026
// has 4; and so on.
$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'weekOfMonth' => 5, 'dayOfWeek' => '1', 'endDate' => '2027-04-30']), '2026-11-30 20:00:00', null, 'Europe/London');
$fifth = starts($r);
if ($fifth === ['2026-11-30 20:00:00', '2027-03-29 20:00:00']) {
    ok('B03', 'the fifth Monday: only in a month that has one (and never the fourth instead)');
} else {
    bad('B03', 'the fifth Monday should skip months with four', json_encode($fifth));
}

echo "\n=== C. The clocks changing, in zones that really change ===\n";

// Europe/London: clocks go BACK on 2026-10-25, FORWARD on 2027-03-28.
// A 10:00 service must stay 10:00 either side. This is the whole point of
// storing a wall-clock reading.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2026-10-18 10:00:00', '2026-10-18 11:00:00', 'Europe/London');
expect('C01', 'London, clocks going BACK: 10:00 stays 10:00', starts($r), [
    '2026-10-18 10:00:00', '2026-10-25 10:00:00', '2026-11-01 10:00:00',
]);
$endsOk = ($r['dates'][1]['end'] ?? '') === '2026-10-25 11:00:00';
if ($endsOk === true) {
    ok('C02', 'and the end stays 11:00, so the service is still an hour on the clock');
} else {
    bad('C02', 'the end should stay 11:00 across the change', json_encode($r['dates'][1] ?? []));
}

$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2027-03-21 10:00:00', '2027-03-21 11:00:00', 'Europe/London');
expect('C03', 'London, clocks going FORWARD: 10:00 stays 10:00', starts($r), [
    '2027-03-21 10:00:00', '2027-03-28 10:00:00', '2027-04-04 10:00:00',
]);

// A zone that changes on a DIFFERENT date from Europe, so a London-shaped
// assumption cannot pass. America/New_York goes forward on 2027-03-14.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2027-03-07 09:30:00', '2027-03-07 10:30:00', 'America/New_York');
expect('C04', 'New York, which changes on a different date: 09:30 stays 09:30', starts($r), [
    '2027-03-07 09:30:00', '2027-03-14 09:30:00', '2027-03-21 09:30:00',
]);

// Southern hemisphere, where the change runs the other way round in the year.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2027-04-01 19:00:00', null, 'Australia/Sydney');
expect('C05', 'Sydney, where the clocks change in April: 19:00 stays 19:00', starts($r), [
    '2027-04-01 19:00:00', '2027-04-08 19:00:00', '2027-04-15 19:00:00',
]);

echo "\n=== C2. The hour that does not exist ===\n";

// London goes forward at 01:00 on 2027-03-28, so 01:30 does not exist that
// night. A weekly 01:30 must not stay at 01:30 on that date.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2027-03-21 01:30:00', null, 'Europe/London');
$list = starts($r);
$middle = $list[1] ?? '';
if ($middle !== '2027-03-28 01:30:00' && str_starts_with($middle, '2027-03-28') === true) {
    ok('C06', 'a 01:30 repeat on the night 01:30 does not exist is moved forward, same date: ' . $middle);
} elseif ($middle === '2027-03-28 01:30:00') {
    bad('C06', 'a reading inside the missing hour was left as it was', json_encode($list));
} else {
    bad('C06', 'the occurrence on the change date is missing altogether', json_encode($list));
}
if (count($list) === 3) {
    ok('C07', 'and it still counts as one occurrence (three asked for, three given)');
} else {
    bad('C07', 'the missing hour must not cost an occurrence', json_encode($list));
}

echo "\n=== D. An overnight event keeps its shape ===\n";

$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2026-11-06 22:00:00', '2026-11-07 01:00:00', 'Europe/London');
$second = $r['dates'][1] ?? [];
if (($second['start'] ?? '') === '2026-11-13 22:00:00' && ($second['end'] ?? '') === '2026-11-14 01:00:00') {
    ok('D01', 'an event running past midnight still ends the next morning');
} else {
    bad('D01', 'the overnight shape was lost', json_encode($second));
}

echo "\n=== E. Where it stops ===\n";

$r = EventRecurrence::datesFor(rule(['endDate' => '2026-11-22']), '2026-11-01 10:00:00', null, 'Europe/London');
expect('E01', 'an end date stops it, and includes that day', starts($r), [
    '2026-11-01 10:00:00', '2026-11-08 10:00:00', '2026-11-15 10:00:00', '2026-11-22 10:00:00',
]);

// ⚠️ THIS CHECK USED TO ASSERT THE OPPOSITE, and it was wrong — found by
//    the independent check, which measured what the person actually read:
//
//      "2 more date(s) were added. Only the first 2 dates were made.
//       Extend the series later to add more."
//
//    Nothing was cut short. They asked for 2 and got 2. `capped` means "one of
//    OUR ceilings stopped the work", which is what `datesFor()`'s own header
//    says, and a message telling somebody their series was shortened when it
//    was not is exactly the kind of untrue wording this project treats as a
//    real fault. Two checks pinned it, so the wrong behaviour had a guard.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2026-11-01 10:00:00', null, 'Europe/London');
if (count($r['dates']) === 2 && $r['capped'] === false && $r['why'] === '') {
    ok('E02', 'a count of 2 gives exactly 2 and does NOT claim the series was cut short');
} else {
    bad('E02', 'a count of 2 should give 2 and report nothing cut short', json_encode($r));
}

// No end and no count. BOTH ceilings exist and the YEARS one bites first for a
// weekly rule: three years is 157 Sundays, well under the 200-date limit. What
// matters is that the caller is TOLD, because otherwise a diary that stops in
// 2029 looks complete.
$r = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London');
$weeks = count($r['dates']);
if ($weeks === 157 && $r['capped'] === true && $r['why'] !== '') {
    ok('E03', 'weekly with no end stops at the ' . EventRecurrence::DEFAULT_MAX_YEARS . '-year ceiling (157 dates) and says so');
} else {
    bad('E03', 'an endless weekly rule must stop at the years ceiling and say so', $weeks . ' date(s), capped=' . var_export($r['capped'], true) . ', why=' . json_encode($r['why']));
}

// And the DATE limit bites when the years ceiling does not get there first:
// twice a week for three years is about 313 dates.
$r = EventRecurrence::datesFor(rule(['dayOfWeek' => '0,3']), '2026-11-01 10:00:00', null, 'Europe/London');
if (count($r['dates']) === EventRecurrence::DEFAULT_MAX_OCCURRENCES && $r['capped'] === true) {
    ok('E03b', 'twice a week with no end stops at the limit of ' . EventRecurrence::DEFAULT_MAX_OCCURRENCES . ' dates');
} else {
    bad('E03b', 'the date limit should stop a twice-weekly endless rule', count($r['dates']) . ' date(s), capped=' . var_export($r['capped'], true));
}

// Monthly with no end: the years ceiling is what bites, not the count.
$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'dayOfMonth' => 1]), '2026-11-01 10:00:00', null, 'Europe/London');
$last = end($r['dates'])['start'] ?? '';
if (count($r['dates']) === (12 * EventRecurrence::DEFAULT_MAX_YEARS + 1) && str_starts_with($last, '2029-11') === true) {
    ok('E04', 'a monthly rule with no end reaches exactly ' . EventRecurrence::DEFAULT_MAX_YEARS . ' years and no further (' . $last . ')');
} else {
    bad('E04', 'the years ceiling should stop a monthly rule', count($r['dates']) . ' date(s), last ' . $last);
}

echo "\n=== F. What it refuses, and says so ===\n";

$r = EventRecurrence::datesFor(rule(['frequency' => 'custom']), '2026-11-01 10:00:00', null, 'Europe/London');
if ($r['dates'] === [] && $r['why'] !== '') {
    ok('F01', 'a "custom" pattern makes no dates and gives a reason');
} else {
    bad('F01', 'custom should refuse with a reason', json_encode($r));
}

$r = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Not/AZone');
if ($r['dates'] === [] && $r['why'] !== '') {
    ok('F02', 'an unknown time zone makes no dates and gives a reason');
} else {
    bad('F02', 'an unknown zone should refuse with a reason', json_encode($r));
}

$r = EventRecurrence::datesFor(rule(), 'not a date', null, 'Europe/London');
if ($r['dates'] === [] && $r['why'] !== '') {
    ok('F03', 'an unreadable start makes no dates and gives a reason');
} else {
    bad('F03', 'an unreadable start should refuse with a reason', json_encode($r));
}

$r = EventRecurrence::datesFor(rule(['intervalVal' => 0, 'maxOccurrences' => 2]), '2026-11-01 10:00:00', null, 'Europe/London');
if (count($r['dates']) === 2 && starts($r) === ['2026-11-01 10:00:00', '2026-11-08 10:00:00']) {
    ok('F04', 'an interval of 0 is treated as 1 rather than looping for ever');
} else {
    bad('F04', 'an interval of 0 must not loop', json_encode($r));
}

echo "\n=== G. Both ceilings are settings, not constants ===\n";

// -----------------------------------------------------------------------------
// WHY THIS SECTION EXISTS
// -----------------------------------------------------------------------------
// The first version of this class hard-coded 200 dates and three years. Those
// are reasonable numbers and they were still MY numbers imposed on every
// customer: a school with a daily registration period and a church with one
// Sunday service do not want the same ceiling. They are now settings, and the
// point of the checks below is that the setting really CHANGES the dates
// produced — not merely that it is stored somewhere and read back.
//
// Everything here runs with no database: limitsFrom() takes the settings
// ARRAY, which is what keeps this class free of a connection.

// G01 — nothing chosen gives the documented defaults.
$lim = EventRecurrence::limitsFrom([]);
if ($lim === ['occurrences' => EventRecurrence::DEFAULT_MAX_OCCURRENCES, 'years' => EventRecurrence::DEFAULT_MAX_YEARS]) {
    ok('G01', 'an installation that has chosen nothing gets the defaults');
} else {
    bad('G01', 'an empty settings array should give the defaults', json_encode($lim));
}

// G02 — ordinary stored values. Settings are always TEXT, so these are strings.
$lim = EventRecurrence::limitsFrom(['calendar' => ['repeat' => ['maxOccurrences' => '52', 'maxYears' => '5']]]);
if ($lim === ['occurrences' => 52, 'years' => 5]) {
    ok('G02', 'stored text values are read as whole numbers');
} else {
    bad('G02', 'a stored "52"/"5" should read as 52/5', json_encode($lim));
}

// G03 — above the hard maximum is clamped, not refused. See limitsFrom()'s own
//       note on why this differs from what the save page does.
$lim = EventRecurrence::limitsFrom(['calendar' => ['repeat' => ['maxOccurrences' => '999999', 'maxYears' => '80']]]);
if ($lim === ['occurrences' => EventRecurrence::HARD_MAX_OCCURRENCES, 'years' => EventRecurrence::HARD_MAX_YEARS]) {
    ok('G03', 'a value past the hard maximum is clamped to it');
} else {
    bad('G03', 'a huge stored value should clamp to the hard maximum', json_encode($lim));
}

// G04 — every unusable shape falls back to the default, and 0 is one of them:
//       a ceiling of none would make the repeat controls silently do nothing.
$unusable = ['', '0', '-4', 'twelve', '3.5', ' ', '1e9', null, [], true];
$G04 = true;
$G04detail = '';
foreach ($unusable as $bad_value) {
    $lim = EventRecurrence::limitsFrom(['calendar' => ['repeat' => ['maxOccurrences' => $bad_value, 'maxYears' => $bad_value]]]);
    if ($lim !== ['occurrences' => EventRecurrence::DEFAULT_MAX_OCCURRENCES, 'years' => EventRecurrence::DEFAULT_MAX_YEARS]) {
        $G04      = false;
        $G04detail = var_export($bad_value, true) . ' gave ' . json_encode($lim);
        break;
    }
}
if ($G04 === true) {
    ok('G04', 'empty, zero, negative, fractional and non-numeric values all fall back to the default');
} else {
    bad('G04', 'an unusable stored value must fall back to the default', $G04detail);
}

// G05 — the settings array may be any shape at all, including one where
//       'repeat' is not an array. A page must not fall over because of it.
$lim = EventRecurrence::limitsFrom(['calendar' => ['repeat' => 'nonsense']]);
if ($lim === EventRecurrence::defaultLimits()) {
    ok('G05', 'a settings branch that is not an array falls back to the defaults');
} else {
    bad('G05', 'a non-array settings branch should not break the reader', json_encode($lim));
}

// G06 — THE CHECK THAT MATTERS MOST. The file header says a yearly 29 February
//       series gives exactly ONE date under the default three-year horizon.
//       Raising the horizon has to actually fix that, or the setting is
//       decoration. 2028 and 2032 are both leap years; five years from 2028
//       reaches 2033, so two dates is the right answer.
$r = EventRecurrence::datesFor(
    rule(['frequency' => 'yearly', 'monthOfYear' => 2, 'dayOfMonth' => 29, 'endDate' => '2040-12-31']),
    '2028-02-29 10:00:00',
    null,
    'Europe/London',
    ['years' => 5]
);
if (starts($r) === ['2028-02-29 10:00:00', '2032-02-29 10:00:00']) {
    ok('G06', 'raising the horizon to five years really does reach the next leap day');
} else {
    bad('G06', 'a five-year horizon should give 2028 and 2032', json_encode(starts($r)));
}

// G07 — and lowering the number of dates really does cut the series short.
$r = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London', ['occurrences' => 4]);
if (count($r['dates']) === 4 && $r['capped'] === true && $r['why'] !== '') {
    ok('G07', 'a lowered date ceiling stops the series and says so');
} else {
    bad('G07', 'a ceiling of 4 should give 4 dates and report capped', json_encode($r));
}

// G08 — a person filling in the form must not be able to ask for MORE than the
//       installation allows. "Repeat 500 times" against a ceiling of 10 gives
//       10, not 500. The rule can only ask for fewer.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 500]), '2026-11-01 10:00:00', null, 'Europe/London', ['occurrences' => 10]);
if (count($r['dates']) === 10 && $r['capped'] === true) {
    ok('G08', 'a rule cannot ask for more dates than the ceiling allows');
} else {
    bad('G08', 'a count of 500 against a ceiling of 10 must give 10', count($r['dates']) . ' date(s)');
}

// G08b — it can still ask for FEWER, and then the series ended exactly where
//        its organiser said it should, so nothing was cut short and nothing is
//        said. This check also used to assert the opposite; see E02.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), '2026-11-01 10:00:00', null, 'Europe/London', ['occurrences' => 50]);
if (count($r['dates']) === 3 && $r['capped'] === false && $r['why'] === '') {
    ok('G08b', 'a smaller count wins and is not reported as the series being cut short');
} else {
    bad('G08b', 'a count of 3 under a ceiling of 50 should give 3 and say nothing', json_encode($r));
}

// G08c — and the ceiling biting IS still reported, so correcting the above has
//        not simply switched the message off.
$r = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London', ['occurrences' => 4]);
if (count($r['dates']) === 4 && $r['capped'] === true && str_contains($r['why'], 'first 4 dates') === true) {
    ok('G08c', 'the ceiling biting is still reported as the series being cut short');
} else {
    bad('G08c', 'a ceiling of 4 with no count should report cut short', json_encode($r));
}

// G09 — the sentence shown to the person has to name the number of years in
//       force, not the constant. A message saying "the next 3 years" on an
//       installation set to 7 is exactly the kind of untrue wording this
//       project treats as a real fault.
$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'dayOfMonth' => 1]), '2026-11-01 10:00:00', null, 'Europe/London', ['years' => 7]);
if (str_contains($r['why'], 'next 7 years') === true && str_contains($r['why'], ' 3 ') === false) {
    ok('G09', 'the "cut short" sentence names the configured number of years');
} else {
    bad('G09', 'the sentence should say 7 years', json_encode($r['why']));
}

// G09b — and it says "year" rather than "years" when there is one. Small, but
//        the alternative is shipping "the next 1 years" to a real customer.
$r = EventRecurrence::datesFor(rule(['frequency' => 'monthly', 'dayOfMonth' => 1]), '2026-11-01 10:00:00', null, 'Europe/London', ['years' => 1]);
if (str_contains($r['why'], 'next 1 year.') === true) {
    ok('G09b', 'a one-year horizon reads "year", not "years"');
} else {
    bad('G09b', 'a horizon of 1 should read "the next 1 year."', json_encode($r['why']));
}

// G10 — a partial array takes the default for the key it leaves out, so a
//       caller interested in only one of the two cannot accidentally reset
//       the other to nothing.
$r = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London', ['years' => 1]);
$withDefaults = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London');
if (count($r['dates']) === 53 && count($withDefaults['dates']) === 157) {
    ok('G10', 'a partial limits array changes only the key it names');
} else {
    bad('G10', 'one year should give 53 Sundays while the default gives 157', count($r['dates']) . ' vs ' . count($withDefaults['dates']));
}

// G11 — and passing nothing is identical to passing the defaults, so the new
//       parameter cannot have changed what every existing caller gets.
$a = EventRecurrence::datesFor(rule(['dayOfWeek' => '0,3']), '2026-11-01 10:00:00', null, 'Europe/London');
$b = EventRecurrence::datesFor(rule(['dayOfWeek' => '0,3']), '2026-11-01 10:00:00', null, 'Europe/London', EventRecurrence::defaultLimits());
if ($a === $b) {
    ok('G11', 'no limits at all is the same as the defaults');
} else {
    bad('G11', 'omitting the limits must equal passing the defaults', 'they differ');
}

echo "\n=== H. The installation has the last word over an organisation ===\n";

// -----------------------------------------------------------------------------
// THE RULE, set by the owner on 29 October 2026
// -----------------------------------------------------------------------------
// One installation may serve many organisations. For almost every setting an
// organisation's own choice overrides the installation's, and $SETTINGS has
// already merged them that way. A LIMIT is the exception: the installation's
// value takes precedence, so an organisation may hold itself to a LOWER
// ceiling but can never raise its own above what the installation allows.
//
// The first version of this got it the ordinary way round — the organisation's
// row simply won — which on a shared install let one organisation help itself
// to a 1,000-date series on a host that had capped it at 200.
//
// Two layers go in: the merged settings (what this organisation effectively
// asked for) and the installation-wide layer on its own.

/** A settings layer holding just the two repeat keys. */
$layer = static function (?int $dates, ?int $years): array {
    $repeat = [];
    if ($dates !== null) {
        $repeat['maxOccurrences'] = (string) $dates;
    }
    if ($years !== null) {
        $repeat['maxYears'] = (string) $years;
    }

    return ['calendar' => ['repeat' => $repeat]];
};

// H01 — an organisation asking for MORE than the installation allows gets the
//       installation's number. This is the whole point.
$lim = EventRecurrence::limitsFrom($layer(500, 9), $layer(50, 2));
if ($lim === ['occurrences' => 50, 'years' => 2]) {
    ok('H01', 'an organisation cannot raise its ceiling above the installation\'s');
} else {
    bad('H01', 'the installation\'s 50/2 must win over the organisation\'s 500/9', json_encode($lim));
}

// H02 — but it CAN hold itself to less, which is what keeps the organisation
//       level worth having at all.
$lim = EventRecurrence::limitsFrom($layer(10, 1), $layer(200, 5));
if ($lim === ['occurrences' => 10, 'years' => 1]) {
    ok('H02', 'an organisation may hold itself to a lower ceiling than the installation');
} else {
    bad('H02', 'the organisation\'s smaller 10/1 should apply', json_encode($lim));
}

// H03 — the two are judged SEPARATELY, so an organisation can be under on one
//       and over on the other. A single "whose row wins" test would get this
//       wrong in one direction or the other.
$lim = EventRecurrence::limitsFrom($layer(10, 9), $layer(200, 3));
if ($lim === ['occurrences' => 10, 'years' => 3]) {
    ok('H03', 'each of the two is capped on its own');
} else {
    bad('H03', 'expected the organisation\'s 10 dates and the installation\'s 3 years', json_encode($lim));
}

// H04 — an organisation that has chosen nothing follows the installation. In a
//       real request $SETTINGS already holds the installation's value in that
//       case, so both layers agree; this check pins that the answer is the
//       installation's number either way.
$lim = EventRecurrence::limitsFrom($layer(null, null), $layer(75, 4));
if ($lim === ['occurrences' => 75, 'years' => 4]) {
    ok('H04', 'an organisation that has chosen nothing gets the installation\'s numbers');
} else {
    bad('H04', 'with nothing chosen the installation\'s 75/4 should apply', json_encode($lim));
}

// H05 — and an installation that has chosen nothing still caps at the built-in
//       default, so a lone organisation row cannot exceed it either.
$lim = EventRecurrence::limitsFrom($layer(900, 9), $layer(null, null));
if ($lim === ['occurrences' => EventRecurrence::DEFAULT_MAX_OCCURRENCES, 'years' => EventRecurrence::DEFAULT_MAX_YEARS]) {
    ok('H05', 'with no installation row the built-in default is still the cap');
} else {
    bad('H05', 'an organisation asking 900/9 against no installation row should get the defaults', json_encode($lim));
}

// H06 — the hard maximum still beats both. A global administrator editing the
//       database by hand cannot lift it.
$lim = EventRecurrence::limitsFrom($layer(99999, 99), $layer(99999, 99));
if ($lim === ['occurrences' => EventRecurrence::HARD_MAX_OCCURRENCES, 'years' => EventRecurrence::HARD_MAX_YEARS]) {
    ok('H06', 'the hard maximum caps the installation as well as the organisation');
} else {
    bad('H06', 'both layers past the hard maximum should clamp to it', json_encode($lim));
}

// H07 — what the installation allows, on its own, for the page to show and the
//       save handler to refuse against.
$allows = EventRecurrence::installationAllows($layer(60, 4));
if ($allows === ['occurrences' => 60, 'years' => 4]) {
    ok('H07', 'the installation\'s own allowance can be read on its own');
} else {
    bad('H07', 'installationAllows should report 60/4', json_encode($allows));
}

// H08 — and the dates really change: the same endless weekly rule under the
//       same organisation setting gives different answers depending only on
//       what the installation allows. Arithmetic, not just bookkeeping.
$org   = $layer(null, 5);
$tight = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London', EventRecurrence::limitsFrom($org, $layer(null, 1)));
$loose = EventRecurrence::datesFor(rule(), '2026-11-01 10:00:00', null, 'Europe/London', EventRecurrence::limitsFrom($org, $layer(null, 5)));
if (count($tight['dates']) === 53 && count($loose['dates']) === 200) {
    ok('H08', 'the installation\'s horizon really changes the dates made (53 against 200)');
} else {
    bad('H08', 'a 1-year installation cap should give 53 dates where a 5-year one gives 200', count($tight['dates']) . ' and ' . count($loose['dates']));
}

echo "\n=== I. A date pushed out of the hour that does not exist keeps its length ===\n";

// -----------------------------------------------------------------------------
// WHY THIS SECTION EXISTS
// -----------------------------------------------------------------------------
// The documents promised "a date landing in the hour that does not exist moves
// forward and keeps its length". It did not. The START was moved and the END
// was rebuilt from the first occurrence's time of day, so the occurrence came
// out with NO LENGTH — or, when the event was shorter than the gap, ENDING
// BEFORE IT STARTED.
//
// Nothing noticed because every existing check of the gap passed `null` for the
// end (C06/C07), so the one combination that breaks — a start inside the gap
// AND an end — was never tried. Found by the independent check, measured.
//
// The second cause was subtler and worth keeping: the pattern walkers built
// each candidate with `createFromFormat()`, which QUIETLY resolves 01:30 to
// 02:30, and then stored the resolved reading. So the one function whose job
// was to notice the gap could never see it. The walkers now store the reading
// the pattern ASKED for.

// I01 — an hour-long event starting inside the gap. London goes forward on
//       28 March 2027.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2027-03-21 01:30:00', '2027-03-21 02:30:00', 'Europe/London');
$got = array_map(static fn (array $d): string => $d['start'] . ' -> ' . (string) $d['end'], $r['dates']);
expect('I01', 'the gap date moves forward and stays one hour long', $got, [
    '2027-03-21 01:30:00 -> 2027-03-21 02:30:00',
    '2027-03-28 02:30:00 -> 2027-03-28 03:30:00',
]);

// I02 — and in a zone that changes on a DIFFERENT date and resolves the other
//       way round. New York goes forward on 14 March 2027. Testing only London
//       is how this project has shipped a no-op fix three times before.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2027-03-07 02:30:00', '2027-03-07 03:30:00', 'America/New_York');
$got = array_map(static fn (array $d): string => $d['start'] . ' -> ' . (string) $d['end'], $r['dates']);
expect('I02', 'the same holds in New York, on its own change date', $got, [
    '2027-03-07 02:30:00 -> 2027-03-07 03:30:00',
    '2027-03-14 03:30:00 -> 2027-03-14 04:30:00',
]);

// I03 — an event SHORTER than the gap. This is the one that produced an end
//       before its start, which no other part of the portal expects.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2026-03-22 01:30:00', '2026-03-22 02:00:00', 'Europe/London');
$second = $r['dates'][1] ?? ['start' => '', 'end' => ''];
if ($second['start'] === '2026-03-29 02:30:00' && $second['end'] === '2026-03-29 03:00:00') {
    ok('I03', 'a half-hour event in the gap stays half an hour, and does not end before it starts');
} else {
    bad('I03', 'expected 02:30 -> 03:00', json_encode($second));
}

// I04 — an OVERNIGHT event whose start is in the gap keeps its shape too: the
//       end is a day on, and moving the start must not lose that.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2027-03-21 01:30:00', '2027-03-22 09:00:00', 'Europe/London');
$second = $r['dates'][1] ?? ['start' => '', 'end' => ''];
if ($second['start'] === '2027-03-28 02:30:00' && $second['end'] === '2027-03-29 10:00:00') {
    ok('I04', 'an overnight event in the gap keeps both its day offset and its length');
} else {
    bad('I04', 'expected 2027-03-28 02:30 -> 2027-03-29 10:00', json_encode($second));
}

// I06 — the OTHER gap case, which is a different fault: the start is fine and
//       only the END lands in the missing hour. The row used to keep 01:30, a
//       reading that does not exist.
//
//       WHAT IS AND IS NOT WRONG WITH THAT, stated carefully because the first
//       version of this comment got it backwards both ways: the instant and
//       the length were always right (01:30 resolved to 02:30 BST, 60 minutes
//       after the 00:30 start, exactly as 02:30 does now), and the occurrence
//       is NOT really longer than its siblings — it only looks an hour later
//       on the clock. The fault was the fictional TEXT, which matters because
//       wall-clock times in this portal compare as text.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2027-03-21 00:30:00', '2027-03-21 01:30:00', 'Europe/London');
$got = array_map(static fn (array $d): string => $d['start'] . ' -> ' . (string) $d['end'], $r['dates']);
expect('I06', 'an end in the missing hour is stored as a reading that exists', $got, [
    '2027-03-21 00:30:00 -> 2027-03-21 01:30:00',
    '2027-03-28 00:30:00 -> 2027-03-28 02:30:00',
]);

// I07 — the same for an overnight event whose end falls in the next morning's
//       gap. The day offset must survive as well as the time.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2027-03-20 23:00:00', '2027-03-21 01:30:00', 'Europe/London');
$second = $r['dates'][1] ?? ['start' => '', 'end' => ''];
if ($second['start'] === '2027-03-27 23:00:00' && $second['end'] === '2027-03-28 02:30:00') {
    ok('I07', 'an overnight event ending in the missing hour keeps a real reading, a day on');
} else {
    bad('I07', 'expected 2027-03-27 23:00 -> 2027-03-28 02:30', json_encode($second));
}

// I08 — EVERY reading this class produces must be a real one. A fictional
//       reading is the shape of both gap faults, so this asks the question
//       directly rather than date by date: read each back in the event's zone
//       and require it to come out unchanged.
$zone      = new DateTimeZone('Europe/London');
$fictional = [];
$I08seen   = 0;   // readings actually examined: this check looks for the ABSENCE
                  // of a fictional one, so it would pass happily on no readings
                  // at all. An added fixture with a bad zone name, or a
                  // 'custom' frequency, would do exactly that.
foreach ([
    ['2027-03-21 00:30:00', '2027-03-21 01:30:00'],
    ['2027-03-21 01:30:00', '2027-03-21 02:30:00'],
    ['2027-03-20 23:00:00', '2027-03-21 01:30:00'],
    ['2027-03-21 01:15:00', '2027-03-21 01:45:00'],
] as [$from, $to]) {
    $r = EventRecurrence::datesFor(rule(['maxOccurrences' => 3]), $from, $to, 'Europe/London');
    foreach ($r['dates'] as $d) {
        foreach ([$d['start'], (string) $d['end']] as $reading) {
            $I08seen++;
            $back = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $reading, $zone);
            if ($back === false || $back->format('Y-m-d H:i:s') !== $reading) {
                $fictional[] = $reading;
            }
        }
    }
}
if ($I08seen < 20) {
    bad('I08', 'the fixtures produced almost no readings, so this check proves nothing', $I08seen . ' reading(s) examined');
} elseif ($fictional === []) {
    ok('I08', 'all ' . $I08seen . ' starts and ends it produces are readings that really exist in the zone');
} else {
    bad('I08', 'these readings do not exist in Europe/London', implode(', ', array_unique($fictional)));
}

// I05 — the ORDINARY case is untouched: a date nowhere near a gap must not be
//       shifted by any of this. When a check exists to prove something
//       ordinary still works, the fixture has to actually be ordinary.
$r = EventRecurrence::datesFor(rule(['maxOccurrences' => 2]), '2027-06-06 10:00:00', '2027-06-06 11:30:00', 'Europe/London');
$got = array_map(static fn (array $d): string => $d['start'] . ' -> ' . (string) $d['end'], $r['dates']);
expect('I05', 'a date far from any clock change is not moved at all', $got, [
    '2027-06-06 10:00:00 -> 2027-06-06 11:30:00',
    '2027-06-13 10:00:00 -> 2027-06-13 11:30:00',
]);

echo "\n" . ($passed + $failed) . " checks: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
