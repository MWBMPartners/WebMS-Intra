<?php
// Path: tools/giving-retention-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Giving-records self-test: the dates and the suggestions 🧾🧪 (#574)
 * -----------------------------------------------------------------------------
 * `Portal\Core\GivingRetention` decides until when a donor's name is kept on
 * an organisation's giving records after the donor has asked to be erased, and
 * whether it may be cleared today. Getting that wrong in one direction
 * destroys tax evidence for good; in the other it keeps a name too long.
 * Neither would ever show up as an error. This file checks the arithmetic and
 * the suggestions, with no database.
 *
 * WHAT IT PROVES
 * --------------
 *  1. THE SUGGESTION. A figure found is suggested as it is; a figure below the
 *     portal's minimum is raised to the minimum and says so; a country with no
 *     figure, a country not in the file, an organisation with no country set,
 *     and a data file that cannot be read each give the flagged placeholder -
 *     never a real-looking figure. A row that breaks a rule counts as "nothing
 *     found" for that country only. And over the REAL data file: no suggestion
 *     anywhere is below the minimum, and every missing figure is a placeholder.
 *  2. "KEEP UNTIL". Counted from the end of the financial year of the last
 *     gift: a gift on the year-end day, the day before and the day after, for
 *     three different year ends; a gift on 29 February; no year end set. And
 *     the fallback can never give an EARLIER date than any real year end
 *     would, for every day of the year.
 *  3. "TODAY" where the organisation is: two organisations on opposite sides
 *     of the date line at the same instant; BOTH clock-change nights of this
 *     year, in zones that change in both hemispheres; and a zone that is not
 *     one, which must never answer a date ahead of any real place.
 *  4. THE GUARD. Nothing is "due" while a hold is on, before the number of
 *     years is confirmed, on the keep-until date itself, or a year early; and
 *     it IS due the day after. Checked at the last second of the keep-until
 *     date and the first second of the next day, on a 23-hour day and on a
 *     25-hour day.
 *  5. THE WORDS never say that a figure is what the law requires.
 *
 * DATES ARE ANCHORED TO TODAY, on purpose. Every date here is worked out from
 * the year this test is run in, so it cannot quietly stop testing anything
 * when a fixed year goes by (a test of another feature did exactly that on
 * 1 December 2026). The clock-change nights are found by asking PHP when the
 * zone's offset changes, not typed in.
 *
 * WHAT IT CANNOT PROVE
 * --------------------
 * - Anything that needs a database: reading an organisation's settings, the
 *   erasure step, and the scheduled clearing are tested by
 *   tools/gdpr-eraser-selftest.php.
 * - That any figure in the data file is right. Each is a researcher's reading.
 * - It asks this machine's copy of the time-zone rules. A government that
 *   changes its clock rules after that copy was made is not covered.
 *
 * Usage:  php tools/giving-retention-selftest.php
 * Exit:   0 only when exactly EXPECTED_CHECKS checks ran, none failed, and the
 *         last line was reached. 1 otherwise.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/574
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🧯 Anything thrown outside a check becomes a plain FAIL line and exit 1, not
//    a stack trace that might happen to contain the word PASS.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

// 🔢 How many checks this file runs — EXACTLY. A check that never ran cannot
//    fail, so the count is part of the result.
const EXPECTED_CHECKS = 54;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'GivingRetention.php';

use Portal\Core\GivingRetention as G;

// The data-file reader writes to the error log when a file or a row is bad,
// which this test does on purpose many times. Keep that out of the output.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

$pass = 0;
$fail = 0;
function ok(string $label, bool $passed, string $detail = ''): void
{
    global $pass, $fail;
    if ($passed === true) {
        $pass++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $fail++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

// 📁 Made-up data files, in a folder of this run's own, removed at the end.
$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'giving-retention-selftest-' . getmypid() . '-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700);
$madeFiles = [];
register_shutdown_function(static function () use ($scratch, &$madeFiles): void {
    foreach ($madeFiles as $file) {
        if (is_file($file) === true) {
            @unlink($file);
        }
    }
    @rmdir($scratch);
});
$n = 0;
$file = static function (mixed $content) use ($scratch, &$madeFiles, &$n): string {
    $path = $scratch . DIRECTORY_SEPARATOR . 'data-' . (++$n) . '.json';
    file_put_contents($path, is_string($content) === true ? $content : json_encode($content));
    $madeFiles[] = $path;

    return $path;
};
$source = [['title' => 'A made-up source', 'url' => 'https://selftest.invalid/law']];
$row = static fn (?int $years, string $strength, array $more = []): array => $more + [
    'name' => 'Madeupland', 'yearsFound' => $years, 'sourceStrength' => $strength, 'needsAdvice' => false,
    'erasureException' => 'written', 'sources' => $years === null ? [] : $source, 'note' => 'A made-up note.',
    'report' => 1, 'checkedOn' => '2026-10-11',
];
$fixture = static fn (array $countries, array $top = []): array => $top + [
    'schemaVersion' => 1, 'updatedOn' => '2026-10-12', 'placeholderYears' => 10, 'minimumSuggestedYears' => 6, 'countries' => $countries,
];

// =============================================================================
echo "== 1. What the portal suggests\n";
// =============================================================================
$good = $file($fixture([
    'AA' => $row(7, 'read'),
    'BB' => $row(3, 'secondary', ['needsAdvice' => true]),
    'CC' => $row(null, 'not-found', ['needsAdvice' => true]),
    'DD' => $row(6, 'summary'),
    'EE' => $row(5, 'read'),
]));

$aa = G::suggestionFor('AA', $good);
ok('a figure that was found is suggested as it is, and says it was found',
    $aa['years'] === 7 && $aa['kind'] === 'found' && $aa['yearsFound'] === 7 && $aa['strength'] === 'read' && $aa['why'] === '', json_encode($aa));
$dd = G::suggestionFor('DD', $good);
ok('a figure exactly at the minimum is "found", not "raised"', $dd['years'] === 6 && $dd['kind'] === 'found', json_encode($dd));
$bb = G::suggestionFor('BB', $good);
ok('A SUGGESTION IS NEVER UNDER THE MINIMUM: a figure of 3 is raised to 6, says "raised", and still shows the 3 that was found',
    $bb['years'] === 6 && $bb['kind'] === 'raised' && $bb['yearsFound'] === 3 && $bb['minimum'] === 6, json_encode($bb));
$ee = G::suggestionFor('EE', $good);
ok('...and so is a well-read 5', $ee['years'] === 6 && $ee['kind'] === 'raised' && $ee['yearsFound'] === 5, json_encode($ee));
$cc = G::suggestionFor('CC', $good);
ok('a country with NO FIGURE gets the placeholder, flagged as a placeholder with the reason "no figure"',
    $cc['years'] === 10 && $cc['kind'] === 'placeholder' && $cc['why'] === 'no-figure' && $cc['yearsFound'] === null, json_encode($cc));
$zz = G::suggestionFor('ZZ', $good);
ok('AN UNKNOWN COUNTRY gets the flagged 10, never a real-looking figure',
    $zz['years'] === 10 && $zz['kind'] === 'placeholder' && $zz['why'] === 'country-not-in-file' && $zz['yearsFound'] === null
    && $zz['sources'] === [], json_encode($zz));
$none = G::suggestionFor('', $good);
ok('an organisation with NO COUNTRY SET gets the flagged 10, with the reason "country not set"',
    $none['years'] === 10 && $none['kind'] === 'placeholder' && $none['why'] === 'country-not-set' && $none['countryName'] === '', json_encode($none));
$odd = [G::suggestionFor('GBR', $good), G::suggestionFor('1A', $good), G::suggestionFor('A', $good)];
ok('anything that is not two letters counts as "no country set", never as a guess',
    count(array_filter($odd, static fn (array $s): bool => $s['why'] === 'country-not-set' && $s['years'] === 10)) === 3, json_encode(array_column($odd, 'why')));
ok('a code written in small letters, or with spaces round it, is still found',
    G::suggestionFor(' aa ', $good)['years'] === 7 && G::suggestionFor('aa', $good)['kind'] === 'found');

$missing = $scratch . DIRECTORY_SEPARATOR . 'not-there.json';
$gone    = G::suggestionFor('AA', $missing);
ok('A DATA FILE THAT IS NOT THERE gives everybody the flagged 10, even a country that had a figure',
    $gone['years'] === 10 && $gone['kind'] === 'placeholder' && $gone['why'] === 'file-unreadable' && G::data($missing)['readable'] === false,
    json_encode($gone));
$broken = [
    'cut off half way'                 => $file(substr((string) json_encode($fixture(['AA' => $row(7, 'read')])), 0, 120)),
    'a different shape version'        => $file($fixture(['AA' => $row(7, 'read')], ['schemaVersion' => 2])),
    'a minimum above the placeholder'  => $file($fixture(['AA' => $row(7, 'read')], ['minimumSuggestedYears' => 12])),
    'a placeholder of 3'               => $file($fixture(['AA' => $row(7, 'read')], ['placeholderYears' => 3])),
    'a placeholder written as text'    => $file($fixture(['AA' => $row(7, 'read')], ['placeholderYears' => '10'])),
    'no countries at all'              => $file(['schemaVersion' => 1, 'placeholderYears' => 10, 'minimumSuggestedYears' => 6]),
];
$notRefused = [];
foreach ($broken as $what => $path) {
    $s = G::suggestionFor('AA', $path);
    if ($s['years'] !== 10 || $s['kind'] !== 'placeholder' || $s['why'] !== 'file-unreadable') {
        $notRefused[] = $what . ' gave ' . json_encode([$s['years'], $s['kind'], $s['why']]);
    }
}
ok('a data file that is broken in any of ' . count($broken) . ' ways gives everybody the flagged 10, and never crashes',
    $notRefused === [], implode('; ', $notRefused));

$badRows = $file($fixture([
    'AA' => $row(7, 'read'),
    'BA' => $row(7, 'not-found'),                                     // a figure that says it was not found
    'BC' => $row(null, 'read'),                                       // no figure that says it was read
    'BD' => $row(7, 'read', ['sources' => []]),                       // a figure with no source
    'BE' => $row(7, 'read', ['sources' => [['title' => 'x', 'url' => 'http://selftest.invalid/']]]), // not https
    'BF' => $row(0, 'read'),                                          // nought years
    'BG' => $row(7, 'read', ['yearsFound' => '7']),                   // years written as text
    'BH' => $row(7, 'certain'),                                       // a strength that is not one of the four
    'BI' => $row(7, 'read', ['needsAdvice' => 'no']),
    'BJ' => 'six years',                                              // not a row at all
    'BK' => $row(99, 'read'),                                         // more than fifty years
]));
$wrongly = [];
foreach (['BA', 'BC', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BK'] as $code) {
    $s = G::suggestionFor($code, $badRows);
    if ($s['years'] !== 10 || $s['kind'] !== 'placeholder' || $s['why'] !== 'no-figure') {
        $wrongly[] = $code . ' gave ' . json_encode([$s['years'], $s['kind'], $s['why']]);
    }
}
ok('a ROW that breaks a rule (10 different ways) counts as "nothing found" for that country: the flagged 10, never its figure',
    $wrongly === [], implode('; ', $wrongly));
ok('...and the good row beside them is still used', G::suggestionFor('AA', $badRows)['years'] === 7);

// The REAL data file.
$real = G::data();
ok('the real data file can be read, and holds at least 200 countries',
    $real['readable'] === true && count($real['countries']) >= 200, count($real['countries']) . ' countries');
$tooLow = [];
$dressedUp = [];
foreach (array_keys($real['countries']) as $code) {
    $s = G::suggestionFor((string) $code);
    if ($s['years'] < $real['minimumSuggestedYears']) {
        $tooLow[] = $code . '=' . $s['years'];
    }
    if (($s['yearsFound'] === null) !== ($s['kind'] === 'placeholder') || ($s['kind'] === 'placeholder' && $s['years'] !== $real['placeholderYears'])) {
        $dressedUp[] = (string) $code;
    }
}
ok('over the real file, NO country is suggested fewer years than the minimum (' . $real['minimumSuggestedYears'] . ')', $tooLow === [], implode(', ', $tooLow));
ok('over the real file, a country with no figure always gets the placeholder (' . $real['placeholderYears'] . '), and only such a country does',
    $dressedUp === [], implode(', ', $dressedUp));
$unknown = 'QQ';
ok('over the real file, a country code that is not in it (' . $unknown . ') gets the flagged placeholder',
    isset($real['countries'][$unknown]) === false && G::suggestionFor($unknown)['kind'] === 'placeholder'
    && G::suggestionFor($unknown)['years'] === $real['placeholderYears']);
ok('the minimum is 6 and the placeholder is 10, as the owner decided, and both come from the data file',
    $real['minimumSuggestedYears'] === 6 && $real['placeholderYears'] === 10);

// =============================================================================
echo "\n== 2. \"Keep until\": counted from the end of the financial year of the last gift\n";
// =============================================================================
// Every date is built from the year this test runs in.
$y    = (int) gmdate('Y') - 3;
$date = static fn (int $year, int $month, int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day);

$boundaryProblems = [];
foreach ([[12, 31], [3, 31], [4, 5]] as [$m, $d]) {
    $yearEnd  = sprintf('%02d-%02d', $m, $d);
    $on       = $date($y, $m, $d);
    $before   = (new DateTimeImmutable($on, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
    $after    = (new DateTimeImmutable($on, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    $expected = [
        'on the year-end day'  => [$on, $date($y, $m, $d), $date($y + 6, $m, $d)],
        'the day before'       => [$before, $date($y, $m, $d), $date($y + 6, $m, $d)],
        'the day after'        => [$after, $date($y + 1, $m, $d), $date($y + 7, $m, $d)],
    ];
    foreach ($expected as $what => [$gift, $from, $until]) {
        $got = G::keepUntil($gift, $yearEnd, 6);
        if ($got['countedFrom'] !== $from || $got['keepUntil'] !== $until || $got['usedFallback'] !== false) {
            $boundaryProblems[] = 'year end ' . $yearEnd . ', gift ' . $what . ' (' . $gift . '): ' . json_encode($got) . ', expected ' . $from . ' then ' . $until;
        }
    }
}
ok('AT THE FINANCIAL-YEAR BOUNDARY: a gift on the year-end day and the day before count from that year end; a gift the day after counts '
    . 'from the NEXT one, a whole year later (year ends 31 December, 31 March and 5 April)', $boundaryProblems === [], implode('; ', $boundaryProblems));

$example = G::keepUntil($date($y, 5, 12), '04-05', 6);
ok('the worked example on the settings page: a last gift on 12 May, a year ending 5 April, 6 years: kept until 5 April seven years on',
    $example['countedFrom'] === $date($y + 1, 4, 5) && $example['keepUntil'] === $date($y + 7, 4, 5), json_encode($example));

$fromGift = G::keepUntil($date($y, 5, 12), '', 6);
ok('with NO year end set, it counts from one full year after the last gift, and says it used the fallback',
    $fromGift['countedFrom'] === $date($y + 1, 5, 12) && $fromGift['keepUntil'] === $date($y + 7, 5, 12) && $fromGift['usedFallback'] === true,
    json_encode($fromGift));

// The newest leap year that is not in the future.
$leap = (int) gmdate('Y');
while (checkdate(2, 29, $leap) === false) {
    $leap--;
}
$feb29 = $date($leap, 2, 29);
$a = G::keepUntil($feb29, '', 6);
$b = G::keepUntil($feb29, '03-31', 6);
$c = G::keepUntil($feb29, '02-28', 6);
ok('a last gift on 29 February: with no year end it counts from 1 March of the next year (the later day, never the earlier); '
    . 'with a year end it is an ordinary day of the year',
    $a['countedFrom'] === $date($leap + 1, 3, 1) && $a['keepUntil'] === $date($leap + 7, 3, 1)
    && $b['countedFrom'] === $date($leap, 3, 31) && $b['keepUntil'] === $date($leap + 6, 3, 31)
    && $c['countedFrom'] === $date($leap + 1, 2, 28) && $c['keepUntil'] === $date($leap + 7, 2, 28),
    json_encode([$a, $b, $c]));

// THE FALLBACK CAN NEVER CLEAR TOO EARLY. For gifts spread over two years
// (a leap year among them) and EVERY possible year end, the date with no year
// end set is never before the date with one.
$tooEarly = 0;
$tried    = 0;
$notAfterGift = 0;
$start    = new DateTimeImmutable($date($leap - 1, 1, 1), new DateTimeZone('UTC'));
for ($i = 0; $i < 730; $i += 7) {
    $gift     = $start->modify('+' . $i . ' days')->format('Y-m-d');
    $fallback = G::keepUntil($gift, '', 6)['keepUntil'];
    for ($m = 1; $m <= 12; $m++) {
        for ($d = 1; $d <= 31; $d++) {
            $yearEnd = sprintf('%02d-%02d', $m, $d);
            if (G::yearEndFromText($yearEnd) === '') {
                continue;
            }
            $tried++;
            $with = G::keepUntil($gift, $yearEnd, 6);
            if ($fallback < $with['keepUntil']) {
                $tooEarly++;
            }
            if ($with['countedFrom'] < $gift) {
                $notAfterGift++;
            }
        }
    }
}
ok('THE FALLBACK NEVER CLEARS EARLY: with no year end set, the date is never before the date ANY real year end would give ('
    . $tried . ' combinations of gift date and year end)', $tried > 30000 && $tooEarly === 0, $tooEarly . ' were earlier');
ok('...and a year end is never counted from before the gift itself', $notAfterGift === 0, (string) $notAfterGift);

$oneYear = G::keepUntil($date($y, 6, 30), '06-30', 1);
$fifty   = G::keepUntil($date($y, 6, 30), '06-30', 50);
ok('one year and fifty years are both worked out as whole calendar years',
    $oneYear['keepUntil'] === $date($y + 1, 6, 30) && $fifty['keepUntil'] === $date($y + 50, 6, 30), json_encode([$oneYear, $fifty]));

$refused = 0;
foreach ([['not-a-date', '', 6], [$date($y, 2, 30), '', 6], [$date($y, 5, 1), '', 0], [$date($y, 5, 1), '', 51],
    [$date($y, 5, 1), '02-29', 6], [$date($y, 5, 1), '13-01', 6], [$date($y, 5, 1), '4-5', 6], ['', '', 6]] as [$g, $ye, $yrs]) {
    try {
        G::keepUntil($g, $ye, $yrs);
    } catch (InvalidArgumentException $e) {
        $refused++;
    }
}
ok('a date that is not a date, a number of years outside 1 to 50, and a year end that is not a day of the year are all REFUSED, not guessed at',
    $refused === 8, $refused . ' of 8 refused');
ok('29 February cannot be a financial year end; 28 February can; 31 April cannot',
    G::yearEndFromText('02-29') === '' && G::yearEndFromText('02-28') === '02-28' && G::yearEndFromText('04-31') === ''
    && G::yearEndFromText('12-31') === '12-31' && G::yearEndFromText('') === '');
ok('a number of years typed by somebody: 1 to 50 is taken, anything else is not',
    G::yearsFromText('1') === 1 && G::yearsFromText('50') === 50 && G::yearsFromText('6') === 6 && G::yearsFromText('0') === null
    && G::yearsFromText('51') === null && G::yearsFromText('') === null && G::yearsFromText('6.5') === null
    && G::yearsFromText('-6') === null && G::yearsFromText('six') === null && G::yearsFromText('006') === null);

// =============================================================================
echo "\n== 3. \"Today\", where the organisation is\n";
// =============================================================================
$utc = new DateTimeZone('UTC');
// One instant, mid-morning UTC: already tomorrow in Auckland, still the same
// day in Los Angeles. An hour later it is tomorrow in Auckland still, and
// Los Angeles has not moved.
$noon = new DateTimeImmutable($date((int) gmdate('Y'), 6, 15) . ' 11:30:00', $utc);
$late = new DateTimeImmutable($date((int) gmdate('Y'), 6, 15) . ' 12:30:00', $utc);
$early = new DateTimeImmutable($date((int) gmdate('Y'), 6, 15) . ' 06:30:00', $utc);
ok('AT THE SAME INSTANT, an organisation in Auckland and one in Los Angeles are on different dates, each given its own',
    G::today('Pacific/Auckland', $noon) === $date((int) gmdate('Y'), 6, 15) && G::today('Pacific/Auckland', $late) === $date((int) gmdate('Y'), 6, 16)
    && G::today('America/Los_Angeles', $late) === $date((int) gmdate('Y'), 6, 15)
    && G::today('America/Los_Angeles', $early) === $date((int) gmdate('Y'), 6, 14) && G::today('UTC', $early) === $date((int) gmdate('Y'), 6, 15),
    json_encode([G::today('Pacific/Auckland', $noon), G::today('Pacific/Auckland', $late), G::today('America/Los_Angeles', $late), G::today('America/Los_Angeles', $early)]));

/**
 * The two nights this year on which a zone's clocks change, found by asking
 * PHP when the zone's offset from UTC changes. Not typed in.
 *
 * @return array<int, array{at: DateTimeImmutable, forward: bool}>
 */
function clockChanges(string $zone): array
{
    $tz    = new DateTimeZone($zone);
    $year  = (int) gmdate('Y');
    $out   = [];
    foreach ($tz->getTransitions(gmmktime(0, 0, 0, 1, 1, $year), gmmktime(0, 0, 0, 12, 31, $year)) as $i => $t) {
        if ($i === 0) {
            continue; // the state at the start of the year, not a change
        }
        $out[] = ['at' => (new DateTimeImmutable('@' . $t['ts']))->setTimezone(new DateTimeZone('UTC')), 'forward' => (bool) $t['isdst']];
    }

    return $out;
}

$zones          = ['Europe/London', 'America/New_York', 'Pacific/Auckland', 'Australia/Sydney'];
$changeNights   = 0;
$dayProblems    = [];
$guardProblems  = [];
$lengths        = [];
foreach ($zones as $zone) {
    $tz = new DateTimeZone($zone);
    foreach (clockChanges($zone) as $change) {
        $changeNights++;
        // The calendar day the change falls on, where the organisation is.
        $day      = $change['at']->setTimezone($tz)->format('Y-m-d');
        $dayStart = new DateTimeImmutable($day . ' 00:00:00', $tz);
        $nextDay  = $dayStart->modify('+1 day');
        $nextDate = $nextDay->format('Y-m-d');
        $prevDate = $dayStart->modify('-1 day')->format('Y-m-d');
        $seconds  = $nextDay->getTimestamp() - $dayStart->getTimestamp();
        $lengths[] = (int) round($seconds / 3600);

        // "Today" is right at the first and last second of that day, and one
        // second either side of it - on a day that is 23 or 25 hours long.
        $moments = [
            [$dayStart->modify('-1 second'), $prevDate],
            [$dayStart, $day],
            [$change['at']->modify('-1 second'), $day],
            [$change['at'], $day],
            [$nextDay->modify('-1 second'), $day],
            [$nextDay, $nextDate],
        ];
        foreach ($moments as [$moment, $want]) {
            $got = G::today($zone, $moment->setTimezone($utc));
            if ($got !== $want) {
                $dayProblems[] = $zone . ' at ' . $moment->setTimezone($utc)->format('Y-m-d H:i:s') . ' UTC: ' . $got . ', expected ' . $want;
            }
        }

        // THE GUARD across that night. A record kept until the day of the
        // change is NOT due at that day's last second, and IS due one second
        // later; and one kept until the day before is due from the first
        // second of the change day, not an hour either side of it.
        $rule = ['timezone' => $zone, 'yearEnd' => substr($day, 5), 'years' => 6, 'hold' => false, 'confirmed' => true];
        $gift = sprintf('%04d', (int) substr($day, 0, 4) - 6) . substr($day, 4);
        $last  = G::stateFor($rule, $gift, G::today($zone, $nextDay->modify('-1 second')->setTimezone($utc)));
        $first = G::stateFor($rule, $gift, G::today($zone, $nextDay->setTimezone($utc)));
        if ($last['keepUntil'] !== $day || $last['state'] !== 'not-due' || $first['state'] !== 'due') {
            $guardProblems[] = $zone . ' ' . $day . ': at the last second ' . $last['state'] . ' (kept until ' . $last['keepUntil'] . '), one second later ' . $first['state'];
        }
        // An hour that does not exist (spring) or happens twice (autumn)
        // sits inside this day; a rule that added 24 hours to find "the next
        // day" would be an hour out here.
        $naive = G::today($zone, $dayStart->setTimezone($utc)->modify('+86400 seconds'));
        if ($seconds !== 86400 && $naive === $nextDate && $seconds > 86400) {
            $guardProblems[] = $zone . ' ' . $day . ': the day is ' . $seconds . ' seconds long, yet 86,400 seconds after its start is already the next date';
        }
    }
}
sort($lengths);
ok('BOTH CLOCK-CHANGE NIGHTS of this year were found in ' . count($zones) . ' zones that change, north and south of the equator ('
    . $changeNights . ' nights), and each such day really is 23 or 25 hours long',
    $changeNights === count($zones) * 2 && array_values(array_unique($lengths)) === [23, 25], $changeNights . ' nights; day lengths in hours: ' . json_encode($lengths));
ok('"today" is right at the first and last second of each of those days, and one second either side', $dayProblems === [], implode('; ', array_slice($dayProblems, 0, 6)));
ok('THE GUARD ON A CLOCK-CHANGE NIGHT: a record kept until that day is not due at its last second and is due one second later, in every one of those zones',
    $guardProblems === [], implode('; ', array_slice($guardProblems, 0, 6)));

$anywhere = [];
foreach (['Pacific/Kiritimati', 'Pacific/Auckland', 'Asia/Tokyo', 'UTC', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Pacific/Pago_Pago'] as $zone) {
    foreach ([$early, $noon, $late, new DateTimeImmutable($date((int) gmdate('Y'), 12, 31) . ' 23:59:59', $utc)] as $moment) {
        foreach (['', 'nonsense', 'Europe/Atlantis', 'GMT+25', '+14:30', '+25:00', 'Etc/GMT-15'] as $bad) {
            if (G::today($bad, $moment) > G::today($zone, $moment)) {
                $anywhere[] = '"' . $bad . '" answered ' . G::today($bad, $moment) . ' while ' . $zone . ' is on ' . G::today($zone, $moment);
            }
        }
    }
}
ok('a time zone that is empty or is not one never answers a date AHEAD of any real place, so a bad zone can only clear late, never early',
    $anywhere === [], implode('; ', array_slice($anywhere, 0, 4)));
ok('with no moment given, "today" is today', in_array(G::today('UTC'), [gmdate('Y-m-d'), gmdate('Y-m-d', time() - 2)], true) === true);

// =============================================================================
echo "\n== 4. The guard: when may a kept name be cleared?\n";
// =============================================================================
$rule = ['timezone' => 'UTC', 'yearEnd' => '03-31', 'years' => 6, 'hold' => false, 'confirmed' => true];
$gift = $date($y - 6, 3, 31);                 // on the year-end day, so: kept until 31 March of $y
$until = $date($y, 3, 31);
$dayAfter = $date($y, 4, 1);
$longAfter = $date($y + 2, 1, 1);

$on = G::stateFor($rule, $gift, $until);
ok('on the keep-until date itself the record is still kept', $on['keepUntil'] === $until && $on['state'] === 'not-due', json_encode($on));
ok('the day after, it is due', G::stateFor($rule, $gift, $dayAfter)['state'] === 'due');
ok('A YEAR EARLY it is not due', G::stateFor($rule, $gift, $date($y - 1, 4, 1))['state'] === 'not-due'
    && G::stateFor($rule, $gift, $date($y - 1, 3, 31))['state'] === 'not-due');
ok('NOT CONFIRMED: nothing is due, however long ago the date passed',
    G::stateFor(['confirmed' => false] + $rule, $gift, $longAfter)['state'] === 'not-confirmed'
    && G::stateFor(['confirmed' => 'true'] + $rule, $gift, $longAfter)['state'] === 'not-confirmed'
    && G::stateFor(['confirmed' => 1] + $rule, $gift, $longAfter)['state'] === 'not-confirmed',
    'only the value true itself counts as confirmed');
ok('ON HOLD: nothing is due, even confirmed and long past its date',
    G::stateFor(['hold' => true] + $rule, $gift, $longAfter)['state'] === 'on-hold'
    && G::stateFor(['hold' => 'yes'] + $rule, $gift, $longAfter)['state'] === 'on-hold'
    && G::stateFor(['hold' => 1] + $rule, $gift, $longAfter)['state'] === 'on-hold',
    'anything but false itself counts as a hold');
ok('a hold is reported before "not confirmed", and both before the date',
    G::stateFor(['hold' => true, 'confirmed' => false] + $rule, $gift, $longAfter)['state'] === 'on-hold');

$giftNextYear = $date($y - 6, 4, 1);          // the day after the year end: a whole year later
$late1 = G::stateFor($rule, $gift, $dayAfter);
$late2 = G::stateFor($rule, $giftNextYear, $dayAfter);
ok('AT THE FINANCIAL-YEAR BOUNDARY: two gifts one day apart, either side of the year end, are due a year apart',
    $late1['state'] === 'due' && $late2['state'] === 'not-due' && $late2['keepUntil'] === $date($y + 1, 3, 31)
    && G::stateFor($rule, $giftNextYear, $date($y + 1, 4, 1))['state'] === 'due', json_encode([$late1, $late2]));

$noYearEnd = ['yearEnd' => ''] + $rule;
$fb = G::stateFor($noYearEnd, $date($y - 7, 6, 10), $date($y, 6, 10));
ok('with no year end set: kept through one year plus the years after the gift, due the day after, and it says the fallback was used',
    $fb['keepUntil'] === $date($y, 6, 10) && $fb['state'] === 'not-due' && $fb['usedFallback'] === true
    && G::stateFor($noYearEnd, $date($y - 7, 6, 10), $date($y, 6, 11))['state'] === 'due', json_encode($fb));

$unreadable = [
    G::stateFor(['years' => 0] + $rule, $gift, $longAfter)['state'],
    G::stateFor(['years' => 51] + $rule, $gift, $longAfter)['state'],
    G::stateFor(['yearEnd' => '02-29'] + $rule, $gift, $longAfter)['state'],
    G::stateFor($rule, '0000-00-00', $longAfter)['state'],
    G::stateFor($rule, 'yesterday', $longAfter)['state'],
];
ok('when the date CANNOT be worked out (nought years, a year end that is not a day, a last-gift date that is not a date) nothing is due',
    array_values(array_unique($unreadable)) === ['unreadable'], json_encode($unreadable));
ok('a "today" that is not a date clears nothing either',
    G::stateFor($rule, $gift, 'tomorrow')['state'] === 'not-due' && G::stateFor($rule, $gift, '')['state'] === 'not-due');

// "due" needs ALL of: no hold, confirmed, the date passed. Every other
// combination of the three must not be due.
$combos = 0;
$wrong  = [];
foreach ([false, true] as $hold) {
    foreach ([false, true] as $confirmed) {
        foreach ([$date($y - 1, 4, 1), $until, $dayAfter] as $today) {
            $combos++;
            $state  = G::stateFor(['hold' => $hold, 'confirmed' => $confirmed] + $rule, $gift, $today)['state'];
            $should = $hold === false && $confirmed === true && $today === $dayAfter;
            if (($state === 'due') !== $should) {
                $wrong[] = json_encode([$hold, $confirmed, $today, $state]);
            }
        }
    }
}
ok('of the ' . $combos . ' combinations of hold, confirmed and date, exactly one is "due": no hold, confirmed, and the date passed',
    $combos === 12 && $wrong === [], implode('; ', $wrong));

// =============================================================================
echo "\n== 5. Which gifts, and the words\n";
// =============================================================================
$receipted = G::keptGiftSql(G::SCOPE_RECEIPTED, 'e');
ok('"every gift" keeps every gift; "tax gifts only" keeps a gift covered by a declaration or by a statement, at the same organisation, for the same donor',
    G::keptGiftSql(G::SCOPE_ALL) === '(1 = 1)'
    && str_contains($receipted, 'tblGiftAidDeclaration') && str_contains($receipted, 'tblGivingStatementLog')
    && substr_count($receipted, '.siteID = e.siteID') === 2 && substr_count($receipted, '.donorID = e.donorID') === 2
    && str_contains($receipted, 'validFrom <= e.donatedAt') && str_contains($receipted, 'validTo IS NULL OR')
    && str_contains($receipted, 'fromDate <= e.donatedAt') && str_contains($receipted, 'toDate >= e.donatedAt'), $receipted);
ok('a declaration counts whatever its status: the test does not look at the status at all', str_contains($receipted, 'status') === false);
ok('anything that is not "every gift" is treated as the narrower rule, never the wider one',
    G::keptGiftSql('everything') === $receipted && G::keptGiftSql('') === $receipted);

$base = ['years' => 6, 'yearsOwn' => null, 'confirmed' => false, 'confirmedOn' => ''];
$sentences = [
    'confirmed'   => G::figureSentence(['confirmed' => true, 'confirmedOn' => $date($y, 11, 3), 'yearsOwn' => 6, 'suggestion' => $aa] + $base),
    'own'         => G::figureSentence(['yearsOwn' => 4, 'years' => 4, 'suggestion' => $aa] + $base),
    'found'       => G::figureSentence(['years' => 7, 'suggestion' => $aa] + $base),
    'raised'      => G::figureSentence(['suggestion' => $bb] + $base),
    'no country'  => G::figureSentence(['years' => 10, 'suggestion' => $none] + $base),
    'no figure'   => G::figureSentence(['years' => 10, 'suggestion' => $cc] + $base),
    'not in file' => G::figureSentence(['years' => 10, 'suggestion' => $zz] + $base),
    'no file'     => G::figureSentence(['years' => 10, 'suggestion' => $gone] + $base),
];
ok('a confirmed figure says who confirmed it and when, in words',
    str_contains($sentences['confirmed'], '6 years was confirmed by this organisation with its adviser on 3 November ' . $y), $sentences['confirmed']);
$unconfirmed = array_diff_key($sentences, ['confirmed' => true]);
ok('EVERY unconfirmed figure says, in capitals, that it has not been confirmed and that nothing will be cleared until it is',
    count(array_filter($unconfirmed, static fn (string $s): bool => str_contains($s, 'NOT YET CONFIRMED') && str_contains($s, 'nothing will be cleared until it does'))) === 7,
    json_encode($unconfirmed));
ok('a suggestion is called a suggestion and "not legal advice"; a placeholder is called "a cautious placeholder, not a finding", with the reason',
    str_contains($sentences['found'], 'is the portal\'s suggestion for Madeupland') && str_contains($sentences['found'], 'not legal advice')
    && str_contains($sentences['found'], 'we read the official text')
    && str_contains($sentences['raised'], 'the portal\'s minimum suggestion; the figure found for Madeupland was 3')
    && str_contains($sentences['no country'], 'cautious placeholder, not a finding: this organisation has not said which country it is in')
    && str_contains($sentences['no figure'], 'cautious placeholder, not a finding: no figure was found for Madeupland')
    && str_contains($sentences['not in file'], 'cautious placeholder, not a finding: the portal holds no figure for ZZ')
    && str_contains($sentences['no file'], 'cautious placeholder, not a finding: the portal\'s list of country figures could not be read')
    && str_contains($sentences['own'], '4 years is the figure this organisation has set'), json_encode($sentences));
// THE WORDS MUST NEVER READ AS IF THE PORTAL KNOWS THE LAW.
$claims = [];
foreach (array_merge($sentences, G::STRENGTH_WORDS, G::SCOPES) as $what => $sentence) {
    if (preg_match('/\b(the law requires|required by law|legally required|you must keep|must be kept for|is the legal|the legal period|by law you)\b/i', $sentence) === 1) {
        $claims[] = $what . ': ' . $sentence;
    }
}
ok('no sentence this class writes says that a figure is what the law requires', $claims === [], implode('; ', $claims));
ok('dates and year ends are written in words',
    G::dateInWords($date($y, 4, 5)) === '5 April ' . $y && G::dateInWords('not a date') === 'not a date'
    && G::yearEndInWords('04-05') === '5 April' && G::yearEndInWords('12-31') === '31 December' && G::yearEndInWords('') === ''
    && count(G::monthNames()) === 12);
ok('the country list for the settings page is every country in the file, by name',
    count(G::countryChoices()) === count($real['countries']) && array_values(G::countryChoices($good)) === array_fill(0, 5, 'Madeupland'));

// -----------------------------------------------------------------------------
$ran = $pass + $fail;
echo "\n" . $pass . ' passed, ' . $fail . ' failed, ' . $ran . ' ran of ' . EXPECTED_CHECKS . " expected.\n";
if ($ran !== EXPECTED_CHECKS) {
    echo 'FAIL — ' . $ran . ' checks ran, but this file expects exactly ' . EXPECTED_CHECKS . ".\n";
    exit(1);
}
if ($fail > 0) {
    echo 'FAIL — ' . $fail . " check(s) failed.\n";
    exit(1);
}
echo 'PASS — all ' . $pass . " checks passed, and the self-test reached its end.\n";
exit(0);
