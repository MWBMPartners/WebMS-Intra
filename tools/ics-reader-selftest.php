<?php
// Path: tools/ics-reader-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for the calendar reader 📅🔎 (#514, part P5)
 * -----------------------------------------------------------------------------
 * `Portal\Core\IcsReader` turns somebody else's calendar file into dates this
 * portal can store. Almost every mistake in a job like that is silent: the
 * import works, and the events are simply on the wrong day, or an hour out for
 * half the year, or a date the organiser deleted is still showing. Nobody
 * checks a calendar they did not create, so nobody finds out.
 *
 * So this script reads a set of hand-written calendar files kept in
 * `tools/fixtures/ics/` and checks the exact dates that come out of each one:
 * the day, the time, how long it lasts, whether it repeats, whether it is
 * private, whether it was cancelled, and what warnings were recorded.
 *
 * It needs no database, no network and no `intl` extension, so it can be run
 * anywhere:
 *
 *     php tools/ics-reader-selftest.php
 *
 * It does need an ordinary allowance of memory. Some of the checks in part I
 * deliberately build very large calendars, so the script itself peaks at about
 * 69.5 MB and takes about ten seconds (both measured on PHP 8.5.10 while this
 * was last changed — the peak was 36 MB before the extra memory checks were
 * added); PHP's usual 128 MB is ample, and each big calendar is let go as soon
 * as its checks are done so the peaks do not pile up. The two checks whose
 * calendars would be FATAL without the guard they test (I10 and I12) are run
 * in a separate process on purpose, so that a regression prints FAIL here
 * instead of killing this script and taking every later check with it.
 *
 * LETTING THE BIG CALENDARS GO IS NOT TIDINESS, IT IS LOAD-BEARING. The reader
 * stops reading when the process is past half of what PHP allows it — 64 MB of
 * the usual 128 MB — and it stops QUIETLY, with `complete = false` and no
 * dates. A check written here that leaves two hundred thousand dates lying
 * about therefore does not fail: it makes every LATER check read an empty
 * calendar and draw the wrong conclusion from it. That happened while the
 * checks in I27 to I31 were being written, and it is why the big calendars in
 * I28 are read in a child process and why several of those checks look at
 * `complete` as well as at the dates.
 *
 * It exits 0 when every check passed, and 1 when any check failed.
 *
 * WHAT IT RUNS
 *   A. Times and zones: UTC, an ordinary zone name, a Windows name from
 *      Microsoft 365, Thunderbird's prefixed form, a time with no zone at
 *      all, and a weekly series in New York across two different clock
 *      changes.
 *   B. Whole days: one day, several days (where the end is exclusive), a
 *      length given as DURATION, whole days in British Summer Time (when
 *      London is an hour ahead of UTC, so a day that has been converted moves
 *      to the day before), and an end written before the start.
 *   C. Repeats: weekly on three weekdays with an end date, the last Friday of
 *      the month, the last weekday of the month, a yearly count, a count on a
 *      series that began BEFORE the window (so the count has to cover the
 *      dates that were never kept), dates added with RDATE, twenty years of a
 *      weekly series, an end date written exactly on the last date the way
 *      Google writes it, "every second Thursday" and "every third month",
 *      a Monday/Wednesday/Friday series created on a Wednesday, a fortnightly
 *      rule whose answer depends on which day the week starts on, a yearly
 *      rule that names no month, and a rule that lists the same day twice.
 *   D. Skipped and changed dates: EXDATE on several lines, EXDATE as one
 *      comma-separated line (Microsoft 365's shape), EXDATE written in a
 *      Windows zone, a changed date written in UTC for a series written in a
 *      named zone, a changed date of a WHOLE-DAY series (Google writes those
 *      with no time at all), "this date and every later one", and three
 *      changed dates that do not line up with any date their series produced.
 *   E. Cancelled: the whole series, a cancelled series whose changed date says
 *      it is going ahead, and one date of an otherwise live series.
 *   F. Private: PRIVATE, CONFIDENTIAL, an unknown value, a folded lower-case
 *      value, one date of an otherwise public series, a private series whose
 *      changed date says PUBLIC (which must stay private), and PUBLIC or
 *      nothing at all (which are NOT private).
 *   G. Untrusted text: an HTML description, invisible direction-changing
 *      characters, folded lines, a byte-order mark, and categories that repeat
 *      in different capitals.
 *   H. Identity: an event with no UID, and the same event sent twice.
 *   I. Limits: a rule this portal does not work out, "every 0 days", a series
 *      from the year 1 asking for a million dates, one series with more dates
 *      than the limit (and the exact edge of that limit, from both sides), a
 *      calendar with more dates than the limit, the time budget, the exact
 *      edge of the limit on how many events one file may hold, the exact edge
 *      of the limit on how many LINES are read (with and without a line ending
 *      at the end of the file), and the guards against running out of memory.
 *      Running out of memory is a fatal error that cannot be caught, so each
 *      guard is checked against the shape of file that really did kill the
 *      process before it existed: too many events, too many dates to work out,
 *      a file of nothing but line endings, hundreds of never-ending series
 *      that all share ONE identifier, one event carrying a huge list of added
 *      dates, a repeat rule listing far more values than it could possibly
 *      use, and a whole separate PHP process given only 32 MB and a calendar
 *      far too big for it, which must finish normally rather than dying.
 *
 *      Part I15 is a family of its own: SIX places where a single over-long
 *      LINE used to be turned into an array before anything had counted how
 *      many pieces it would make — a property line of nothing but semicolons,
 *      a repeat rule of nothing but semicolons, an EXDATE, an RDATE and a
 *      CATEGORIES line of nothing but commas, and a time-zone name of nothing
 *      but slashes — plus the list of WARNINGS, which the file decides the
 *      length of too. Every one of them is a file no bigger than the
 *      5,242,880 bytes the fetcher allows, and every one of them used to kill
 *      the process outright.
 *
 *      Each of those has a CONTROL beside it showing an ordinary calendar is
 *      untouched by the same limit. Without the controls every limit here
 *      could be set to 1 and all of the checks would still pass.
 *
 *      Parts I27 to I32 are what a FIFTH round of independent checking found,
 *      and they are about two things rather than memory: a promise this reader
 *      makes to the importer and used to break, and a wrong time on a real
 *      page. I27 — a skipped or added date list that was cut short must throw
 *      away the "read reliably up to here" point, because the importer deletes
 *      stored dates at or before it and the dates a cut list loses can sit
 *      anywhere. I28 — a warning must name the allowance that really ran out,
 *      this event's or the whole calendar's, including the awkward case where
 *      a budget is used up EXACTLY at an event boundary, and the tie where an
 *      event's own room and the calendar's run out on the SAME line (once for
 *      each of the two lists, because the code that decides a tie is written
 *      out twice). I29 — an event that
 *      spans a clock change, in both directions, written with an explicit end
 *      time, an exact length and a nominal one. I30 — the per-feed ceiling a
 *      customer may now change, its refusal of anything absurd, and the
 *      gathering limit that has to follow it. I31 — a repeating event whose
 *      list of removed dates was cut short has its repeat pattern thrown away,
 *      rather than bringing back services somebody had cancelled, keeping only
 *      the dates the file states one by one and putting every one of those
 *      through the ordinary checks (a SIXTH round found that last part was
 *      claimed and not done). I32 — a warning is only ever raised about a date
 *      the answer actually contains.
 *
 *      Part I33 is what a NINTH round found, and it is the same promise I27
 *      is about. A repeating event cut short by the 400-dates-per-event limit
 *      used to report the last date it kept as "everything before this moment
 *      was read", and that is not true: a changed date MOVES an occurrence, so
 *      the dates it kept are not the earliest ones it had, and on the night
 *      the clocks go back the order of moments and the order of clock readings
 *      disagree even without one. Four shapes are checked — the last date kept
 *      moved later, the first date dropped moved earlier, both limits reached
 *      at once, and the clock-change night — with three controls: the ordinary
 *      cut still gives the earliest 400 dates; it still reports no end point
 *      (the deliberate cost, written down so nobody reads it as an accident);
 *      and the same calendar read over a period it does not overflow is
 *      untouched. The guard against over-correcting to "never report an end
 *      point" is I27's control, which gets one from the per-calendar slice.
 *   J. Attachments: counted, and no attachment data anywhere in the answer.
 *   K. Other things a real calendar file does, checked with calendars written
 *      inside this script rather than kept as files: a VTIMEZONE block and an
 *      alarm inside an event (neither may change the event), parameters in
 *      quotation marks holding a colon or a semicolon, a web address that is
 *      not a web address, a made-up zone name, an end before the start, an
 *      event with no title, two UIDs that differ only in their capitals,
 *      three shapes of repeat rule no fixture file uses (a numbered weekday
 *      counted across a year, a daily rule narrowed by weekday and month, and
 *      "the last day of the month"), the worked example RFC 5545 itself prints
 *      for which day the week starts on, two yearly rules that have no one
 *      reading and so are refused rather than guessed at, a monthly series
 *      starting on the 31st (which must skip the months that have no 31st
 *      rather than moving the date), an end date written as a plain date on a
 *      timed series, both ends of the window being inclusive, `\N` in capitals
 *      as an escape for a new line, and a field that is not valid UTF-8 (where
 *      only the bad bytes are lost — and the Latin-1 case this still cannot do
 *      anything with is checked too, so the limit is recorded, not assumed).
 *   L. The Windows zone list: the names Microsoft writes come out as ordinary
 *      names, the committed list still matches this machine (L5, where this
 *      machine's ICU version allows a fair comparison), and every value this
 *      machine's ICU can recognise is still the CURRENT spelling of its zone,
 *      not an old one (L6, #557).
 *   M. Real exports from Google and Microsoft 365 — SKIPPED, see below.
 *   N. Housekeeping: every fixture file is used by a check, and no PHP warning
 *      was raised.
 *
 * WHAT IT CANNOT PROVE
 *   - **It has never seen a real Google or Microsoft 365 calendar.** There are
 *     no test calendars (the owner's decision of 21 September 2026), so part M
 *     prints SKIPPED and the acceptance criterion "works against a real Google
 *     or Microsoft 365 calendar" is NOT proven by this script or by anything
 *     else in this repository. The fixture files are hand-written to match
 *     what those systems are documented to write; that is not the same thing.
 *   - It proves nothing about storing the dates, showing them, or who may see
 *     them. That is the importer (part P6) and the visibility rule (part P1).
 *   - It cannot show that every possible repeat rule is right. It checks the
 *     rules this portal supports, on the fixtures listed above. A rule the
 *     reader does not support is meant to give one date and a warning, and
 *     that is checked — but "this rule would have been read correctly" is not
 *     something any finite set of files can show.
 *   - Part L's L5 check (the whole list matches what this machine's ICU
 *     would generate) needs the `intl` extension, `IntlTimeZone::getIanaID()`
 *     (PHP 8.4 or newer built with ICU 74 or newer — the generator's
 *     `--check` needs it too, see #557), AND a machine whose ICU version is
 *     the exact one the list was generated from (named in
 *     `WindowsTimeZones.php`'s own header comment). Missing any of those
 *     three, L5 prints SKIPPED, and a list that has drifted from ICU would
 *     NOT be caught by L5 on that machine.
 *   - Part L's L6 check (#557 — every value is the CURRENT spelling, not an
 *     old one) needs `IntlTimeZone::getIanaID()` too; without it, L6 is
 *     SKIPPED. On a machine whose own PHP still accepts the OLD spellings
 *     (this Mac is one such machine — its `date` extension was built with
 *     its OWN bundled zone data, not the operating system's; `php -i` shows
 *     `Timezone Database => internal`, and that internal copy still carries
 *     the old spellings), an old spelling that crept back into the list
 *     would then be caught by NEITHER L4
 *     (which only asks whether PHP accepts the name, and this Mac does) NOR
 *     L6 (skipped) — only a machine with `getIanaID()`, or a server built
 *     without the optional `tzdata-legacy` package, would catch it. L6 also
 *     cannot judge a value that is NEWER than this machine's own ICU
 *     tables know (ICU gives no answer for it at all); that value is
 *     reported as unchecked in its own SKIPPED line, named, rather than
 *     counted as a pass.
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

use Portal\Core\IcsReader;
use Portal\Core\WindowsTimeZones;

require __DIR__ . '/../web/_core/WindowsTimeZones.php';
require __DIR__ . '/../web/_core/IcsReader.php';

// -----------------------------------------------------------------------------
// 🧮 Counting and printing
// -----------------------------------------------------------------------------
$GLOBALS['st_pass'] = 0;
$GLOBALS['st_fail'] = 0;
$GLOBALS['st_skip'] = 0;
$GLOBALS['st_php_warnings'] = [];
/** @var array<string,bool> Which fixture files a check actually used (checked in part N). */
$GLOBALS['st_used_fixtures'] = [];

function st_check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok === true) {
        $GLOBALS['st_pass']++;
        echo 'PASS — ' . $label . "\n";
        return;
    }
    $GLOBALS['st_fail']++;
    echo 'FAIL — ' . $label . ($detail !== '' ? "\n        " . $detail : '') . "\n";
}

function st_skip(string $label, string $why): void
{
    $GLOBALS['st_skip']++;
    echo 'SKIPPED — ' . $label . ' (' . $why . ")\n";
}

/**
 * Compare two lists of dates and say exactly where they differ.
 *
 * @param list<string> $expected
 * @param list<string> $actual
 */
function st_lines(string $label, array $expected, array $actual): void
{
    if ($expected === $actual) {
        st_check($label, true);
        return;
    }
    $detail = "expected " . count($expected) . " date(s):\n          "
        . implode("\n          ", $expected) . "\n        got " . count($actual) . ":\n          "
        . implode("\n          ", $actual);
    st_check($label, false, $detail);
}

// Any PHP warning or deprecation raised while testing is itself a fault: a
// reader that fills the host's error log on every refresh is not finished.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    $GLOBALS['st_php_warnings'][] = $str . ' at ' . basename($file) . ':' . $line;
    return true;
});

// -----------------------------------------------------------------------------
// ⚙️ The fixed conditions every fixture is read under
// -----------------------------------------------------------------------------
$orgZone      = new DateTimeZone('Europe/London');
$windowStart  = new DateTimeImmutable('2026-09-01 00:00:00', $orgZone);
$windowEnd    = new DateTimeImmutable('2027-09-01 00:00:00', $orgZone);
$fixtureDir   = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'ics';

/**
 * Read one fixture file and give back what both steps produced.
 *
 * @return array{parse:array, expand:array, seconds:float}
 */
function st_read(string $name, ?DateTimeZone $floatingZone = null): array
{
    global $fixtureDir, $orgZone, $windowStart, $windowEnd;

    $GLOBALS['st_used_fixtures'][$name] = true;
    $path = $fixtureDir . DIRECTORY_SEPARATOR . $name;
    $body = file_get_contents($path);
    if ($body === false) {
        echo 'FAIL — could not read the fixture ' . $name . "\n";
        $GLOBALS['st_fail']++;
        return ['parse' => ['complete' => false, 'events' => [], 'warnings' => []], 'expand' => [
            'occurrences' => [], 'capped' => false, 'effectiveWindowEnd' => null,
            'duplicates' => 0, 'skippedNoUid' => 0, 'attachments' => 0, 'warnings' => [],
        ], 'seconds' => 0.0];
    }

    $startedAt = microtime(true);
    $parsed    = IcsReader::parse($body, microtime(true) + 30.0);
    $expanded  = IcsReader::expand(
        $parsed,
        $orgZone,
        $floatingZone ?? $orgZone,
        $windowStart,
        $windowEnd,
        microtime(true) + 30.0
    );

    return ['parse' => $parsed, 'expand' => $expanded, 'seconds' => microtime(true) - $startedAt];
}

/**
 * One date as one line of text, so a whole list can be compared at once and a
 * difference reads plainly.
 *
 * @param  list<array<string,mixed>> $occurrences
 * @return list<string>
 */
function st_lines_of(array $occurrences): array
{
    $out = [];
    foreach ($occurrences as $o) {
        $out[] = sprintf(
            '%s|%s|%s|key=%s|%s|priv=%s|canc=%s|dup=%s|%s',
            (string) $o['start'],
            (string) $o['end'],
            $o['isAllDay'] === true ? 'allday' : 'timed',
            (string) $o['recurrenceKey'],
            $o['isSeries'] === true ? 'series' : 'one-off',
            $o['private'] === true ? 'yes' : 'no',
            $o['cancelled'] === true ? 'yes' : 'no',
            $o['duplicate'] === true ? 'yes' : 'no',
            (string) $o['title']
        );
    }

    return $out;
}

/**
 * Read a calendar in a SEPARATE PHP process and report what happened.
 *
 * Why this exists at all: running out of memory in PHP is a fatal error. It
 * cannot be caught, and it ends the whole process — so a check that wants to
 * show "this no longer runs out of memory" cannot do it inside a script that
 * has to carry on afterwards. If the guard being checked is ever removed, the
 * child dies and this script prints an ordinary FAIL, instead of dying itself
 * and taking every later check down with it unreported.
 *
 * The child is given its own memory limit, and the window can be widened
 * because some of these shapes only misbehave over a longer period.
 *
 * What it cannot do: it says nothing about WHY a child failed beyond the text
 * the child printed. Read that text; it is handed back for exactly that.
 *
 * The two budgets are how the deadline is tested. They are separate because
 * the two steps take separate deadlines, and a fault in one of them can only
 * be seen by giving the OTHER one enough time to finish: a file read with an
 * already-expired deadline never reaches the step that works out the dates.
 *
 * The child catches whatever is thrown and prints it, rather than dying, so a
 * check can look at WHICH exception came back and how long it took. It also
 * prints the seconds taken, because "it gave up" and "it gave up in time" are
 * different claims and this round is about the second one.
 *
 * @param  string   $body            The calendar file.
 * @param  string   $memoryLimit     A value for PHP's `memory_limit`, e.g. '32M'.
 * @param  string   $windowEnd       The end of the window, as 'Y-m-d H:i:s'.
 * @param  float    $parseBudget     Seconds the reading step is given.
 * @param  float    $expandBudget    Seconds the date-working step is given; the same as the reading step when not given.
 * @param  int|null $maxDatesPerFeed The per-feed ceiling to hand `expand()`, or null to leave the argument out
 *                                   altogether — which is not the same test, because "left out" is the case every
 *                                   earlier round measured.
 * @return array{status:int, text:string, leftOver:bool}
 */
function st_in_child(
    string $body,
    string $memoryLimit,
    string $windowEnd,
    float $parseBudget = 60.0,
    ?float $expandBudget = null,
    ?int $maxDatesPerFeed = null
): array {
    if ($expandBudget === null) {
        $expandBudget = $parseBudget;
    }
    // The budgets and the ceiling go into the name as well as the body, or two
    // checks that differ ONLY in how long they allow, or in the ceiling they
    // ask for, would write to the same two temporary files and could read each
    // other's.
    $stem    = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'webms-ics-child-' . getmypid() . '-'
        . substr(
            hash(
                'sha256',
                $body . $memoryLimit . $parseBudget . $expandBudget . var_export($maxDatesPerFeed, true)
            ),
            0,
            8
        );
    $icsPath = $stem . '.ics';
    $phpPath = $stem . '.php';

    file_put_contents($icsPath, $body);
    file_put_contents($phpPath, '<?php' . "\n"
        // BOTH classes are loaded, not just the reader. The reader asks
        // `WindowsTimeZones` about any zone name PHP does not recognise, and
        // there is no autoloader in a bare child process — so a calendar with
        // an unknown zone used to end the child with "Class not found", which
        // reads exactly like the fatal error these checks are looking for.
        . 'require ' . var_export(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
            . DIRECTORY_SEPARATOR . 'WindowsTimeZones.php',
            true
        ) . ';' . "\n"
        . 'require ' . var_export(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core'
            . DIRECTORY_SEPARATOR . 'IcsReader.php',
            true
        ) . ';' . "\n"
        . '$zone = new DateTimeZone("Europe/London");' . "\n"
        . '$started = microtime(true);' . "\n"
        . '$parsed = ["events" => [], "complete" => null, "warnings" => []];' . "\n"
        . '$expanded = ["occurrences" => [], "capped" => null, "warnings" => []];' . "\n"
        . '$thrown = "";' . "\n"
        // When `expand()` began, so a check can time `expand()` on its own.
        // That is ALL of `expand()`, including its first loop, not only the
        // walk through a series' dates. `secs` below also includes reading
        // the file, which
        // has its own, much longer budget; on a busy machine that reading time
        // alone can use up a tight margin (25 September 2026: I22b measured
        // 0.703 s against a 0.7 s limit with seven copies running at once).
        // Printed as `expandtime`, not `…secs`, so the `secs=` pattern other
        // checks look for can never match it by mistake.
        . '$expandStarted = null;' . "\n"
        . 'try {' . "\n"
        . '$parsed = Portal\Core\IcsReader::parse((string) file_get_contents($argv[1]), microtime(true) + '
        . var_export($parseBudget, true) . ');' . "\n"
        . '$expandStarted = microtime(true);' . "\n"
        . '$expanded = Portal\Core\IcsReader::expand($parsed, $zone, $zone,'
        . ' new DateTimeImmutable("2026-09-01 00:00:00", $zone),'
        . ' new DateTimeImmutable(' . var_export($windowEnd, true) . ', $zone), microtime(true) + '
        . var_export($expandBudget, true)
        // The ceiling is written into the child's call only when one was
        // asked for. Passing `null` explicitly and leaving the argument out
        // are the same thing to the reader, but writing it out either way
        // would hide the case where the argument is genuinely absent.
        . ($maxDatesPerFeed === null ? '' : ', ' . var_export($maxDatesPerFeed, true)) . ');' . "\n"
        . '} catch (Throwable $e) { $thrown = get_class($e) . ": " . $e->getMessage(); }' . "\n"
        . 'echo "CHILD-FINISHED events=", count($parsed["events"]),'
        . ' " complete=", var_export($parsed["complete"], true),'
        . ' " dates=", count($expanded["occurrences"]),'
        . ' " capped=", var_export($expanded["capped"], true),'
        . ' " thrown=", ($thrown === "" ? "none" : $thrown),'
        . ' " secs=", sprintf("%.3f", microtime(true) - $started),'
        . ' " expandtime=", ($expandStarted === null ? "none" : sprintf("%.3f", microtime(true) - $expandStarted)),'
        . ' " warnings=", count($expanded["warnings"]) + count($parsed["warnings"]),'
        // The warnings themselves, so a check can look at what the child was
        // told rather than only at how many things it said. Bounded by
        // IcsReader::MAX_WARNINGS, so this cannot itself grow without limit.
        . ' " text=", str_replace("\n", " ", implode(" ~~ ",'
        . ' array_merge($parsed["warnings"], $expanded["warnings"]))), "\n";' . "\n");

    $output = [];
    $status = 1;
    exec(
        escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . escapeshellarg($memoryLimit) . ' '
        . escapeshellarg($phpPath) . ' ' . escapeshellarg($icsPath) . ' 2>&1',
        $output,
        $status
    );

    @unlink($icsPath);
    @unlink($phpPath);

    return [
        'status'   => $status,
        'text'     => implode(' ', $output),
        'leftOver' => (is_file($icsPath) === true || is_file($phpPath) === true),
    ];
}

/** Does any warning contain this text? */
function st_has_warning(array $warnings, string $needle): bool
{
    foreach ($warnings as $warning) {
        if (str_contains((string) $warning, $needle) === true) {
            return true;
        }
    }

    return false;
}

echo "Self-test for the calendar reader (IcsReader) and the Windows zone list\n";
echo "Organisation zone Europe/London; window 2026-09-01 00:00:00 to 2027-09-01 00:00:00.\n\n";

// =============================================================================
// A. Times and zones
// =============================================================================
echo "A. Times and zones\n";

$r = st_read('basic-utc.ics');
st_check('A1 the file was read as a whole calendar', $r['parse']['complete'] === true);
st_lines(
    'A1 basic-utc.ics: 18:00 UTC on 15 October 2026 is stored as 19:00 London (British Summer Time), key empty',
    ['2026-10-15 19:00:00|2026-10-15 20:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Basic UTC event'],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('tzid-london.ics');
st_lines(
    'A2 tzid-london.ics: an ordinary zone name is used as it stands',
    ['2026-11-10 19:00:00|2026-11-10 20:30:00|timed|key=|one-off|priv=no|canc=no|dup=no|London evening'],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'A2 the place was kept',
    ($r['expand']['occurrences'][0]['location'] ?? '') === 'Church hall',
    var_export($r['expand']['occurrences'][0]['location'] ?? null, true)
);
st_check('A2 no warning was recorded', $r['expand']['warnings'] === [], implode(' | ', $r['expand']['warnings']));

$r = st_read('tzid-newyork-dst.ics');
st_lines(
    'A3 tzid-newyork-dst.ics: 19:00 New York on three Thursdays and four Tuesdays, across both clock changes',
    [
        '2026-10-07 00:00:00|2026-10-07 01:00:00|timed|key=20261006T230000Z|series|priv=no|canc=no|dup=no'
        . '|New York Tuesday, stopping at a moment',
        '2026-10-14 00:00:00|2026-10-14 01:00:00|timed|key=20261013T230000Z|series|priv=no|canc=no|dup=no'
        . '|New York Tuesday, stopping at a moment',
        '2026-10-21 00:00:00|2026-10-21 01:00:00|timed|key=20261020T230000Z|series|priv=no|canc=no|dup=no'
        . '|New York Tuesday, stopping at a moment',
        '2026-10-23 00:00:00|2026-10-23 01:00:00|timed|key=20261022T230000Z|series|priv=no|canc=no|dup=no|New York weekly',
        '2026-10-27 23:00:00|2026-10-28 00:00:00|timed|key=20261027T230000Z|series|priv=no|canc=no|dup=no'
        . '|New York Tuesday, stopping at a moment',
        '2026-10-29 23:00:00|2026-10-30 00:00:00|timed|key=20261029T230000Z|series|priv=no|canc=no|dup=no|New York weekly',
        '2026-11-06 00:00:00|2026-11-06 01:00:00|timed|key=20261106T000000Z|series|priv=no|canc=no|dup=no|New York weekly',
    ],
    st_lines_of($r['expand']['occurrences'])
);
// A3 the Tuesday series on its own, because in a seven-line list a fault worth
// one date is easy to miss.
//
// `UNTIL` states a MOMENT IN TIME, not a reading on a clock (RFC 5545
// §3.3.10), so it has to be compared as a moment. The last Tuesday this series
// offers is 3 November 2026 at 19:00 in New York. The United States put its
// clocks back on 1 November, so New York is five hours behind UTC by then and
// that reading is 00:00 on the 4th — one second past this `UNTIL`. The series
// therefore stops with four dates, the last of them 27 October.
//
// Written out as plain text the two sort the other way round: "20261103190000"
// comes before "20261103235959". A reader comparing the text would keep a
// fifth date the calendar's own rule excludes.
//
// That is not an imagined fault. It was planted in the reader during the
// seventh round of independent checking and the WHOLE self-test stayed green,
// because every other `UNTIL` fixture here is either a plain date or a moment
// on a series whose offset from UTC happens to be zero at the boundary — where
// the two ways of comparing agree and the mistake is invisible. The dates in
// the fixture are the check: move these Tuesdays to a week when New York is
// still four hours behind and this proves nothing at all.
$untilDates = array_values(array_filter(
    $r['expand']['occurrences'],
    static fn (array $o): bool => $o['title'] === 'New York Tuesday, stopping at a moment'
));
st_check(
    'A3 UNTIL is compared as a moment, so the Tuesday series stops at four dates and not five',
    count($untilDates) === 4
    && (string) $untilDates[3]['start'] === '2026-10-27 23:00:00',
    count($untilDates) . ' dates: ' . implode(', ', array_map(
        static fn (array $o): string => (string) $o['start'],
        $untilDates
    ))
);
st_check(
    'A3 and no warning was needed to get any of them right',
    $r['expand']['warnings'] === [] && $r['parse']['warnings'] === [],
    implode(' | ', array_merge($r['parse']['warnings'], $r['expand']['warnings']))
);
unset($untilDates);

$r = st_read('windows-tz-outlook.ics');
st_lines(
    'A4 windows-tz-outlook.ics: "GMT Standard Time" is London and "Eastern Standard Time" is New York',
    [
        '2026-10-06 19:00:00|2026-10-06 20:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Outlook London',
        '2026-10-07 14:00:00|2026-10-07 15:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Outlook New York',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'A4 no "unknown time zone" warning for a Microsoft calendar',
    st_has_warning($r['expand']['warnings'], 'Unknown time zone') === false,
    implode(' | ', $r['expand']['warnings'])
);

$r = st_read('mozilla-prefix-tz.ics');
st_lines(
    'A5 mozilla-prefix-tz.ics: Thunderbird\'s prefixed zone name is recognised',
    ['2026-11-18 18:00:00|2026-11-18 19:30:00|timed|key=|one-off|priv=no|canc=no|dup=no|Thunderbird event'],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'A5 no "unknown time zone" warning',
    st_has_warning($r['expand']['warnings'], 'Unknown time zone') === false,
    implode(' | ', $r['expand']['warnings'])
);

// The same file read twice, to show the "no zone given" case really does use
// the zone the caller supplies for such times, and is not quietly read as UTC
// or as the organisation's zone.
$r = st_read('floating.ics');
st_lines(
    'A6 floating.ics read with London as the calendar zone: 09:00 stays 09:00',
    ['2026-11-19 09:00:00|2026-11-19 10:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Floating time'],
    st_lines_of($r['expand']['occurrences'])
);
$r = st_read('floating.ics', new DateTimeZone('America/New_York'));
st_lines(
    'A6 control: the same file read with New York as the calendar zone: 09:00 there is 14:00 London',
    ['2026-11-19 14:00:00|2026-11-19 15:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Floating time'],
    st_lines_of($r['expand']['occurrences'])
);

// =============================================================================
// B. Whole days and lengths
// =============================================================================
echo "\nB. Whole days and lengths\n";

$r = st_read('allday-single.ics');
st_lines(
    'B1 allday-single.ics: one whole day runs from midnight to 23:59:59',
    ['2026-12-25 00:00:00|2026-12-25 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no|Christmas Day'],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('allday-multi.ics');
st_lines(
    'B2 allday-multi.ics: 24 to 27 December means the 24th, 25th and 26th (the end is exclusive)',
    ['2026-12-24 00:00:00|2026-12-26 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no|Christmas break'],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('duration.ics');
st_lines(
    'B3 duration.ics: DURATION gives the length for a timed event and for whole days',
    [
        '2026-12-01 10:00:00|2026-12-01 11:30:00|timed|key=|one-off|priv=no|canc=no|dup=no|Timed with duration',
        '2026-12-05 00:00:00|2026-12-07 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no|Whole days with duration',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// B4: whole days in BRITISH SUMMER TIME. Every other whole-day fixture here is
// in December, when London and UTC are the same time, so a whole-day date that
// had been pushed through a time-zone conversion would come out looking
// perfectly right. In June it does not: midnight on the 15th in London is
// 23:00 on the 14th in UTC, so the day would move to the day before - for half
// the year only, which is the hardest kind of fault to notice.
$r = st_read('allday-summer-time.ics');
st_lines(
    'B4 allday-summer-time.ics: whole days in June stay on their own day, and the identifier for each repeat is the plain date',
    [
        '2027-06-07 00:00:00|2027-06-07 23:59:59|allday|key=20270607|series|priv=no|canc=no|dup=no|Summer holiday club',
        '2027-06-14 00:00:00|2027-06-14 23:59:59|allday|key=20270614|series|priv=no|canc=no|dup=no|Summer holiday club',
        '2027-06-15 00:00:00|2027-06-15 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no|Summer fete',
        '2027-06-21 00:00:00|2027-06-21 23:59:59|allday|key=20270621|series|priv=no|canc=no|dup=no|Summer holiday club',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// B5: a whole-day event whose end is BEFORE its start. The gap between two
// dates is always counted as a positive number, so this used to come out as a
// three-day event on the 27th, 28th and 29th - two days that appear nowhere in
// the file. The timed version of the same mistake is checked in part K, and
// both must give the same answer: the event covers its start and no more.
$r = st_read('allday-end-before-start.ics');
st_lines(
    'B5 allday-end-before-start.ics: an end before the start covers the start day only, never three days',
    ['2026-12-27 00:00:00|2026-12-27 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no|End before start'],
    st_lines_of($r['expand']['occurrences'])
);

// B6: whole days across the two nights a year when the clocks change.
//
// A whole day is not always 24 hours long. In London the clocks go BACK at
// 02:00 on Sunday 25 October 2026, which makes that day 25 hours, and FORWARD
// at 01:00 on Sunday 28 March 2027, which makes that day 23. Two separate
// pieces of arithmetic decide a whole-day event, and each goes wrong in its
// own way if it counts seconds instead of days:
//
//   - HOW MANY DAYS the event covers. Done properly it is the count of
//     calendar days between the start date and the end date. Counted as
//     "seconds divided by 86,400" it comes out one short whenever the run
//     crosses the 23-hour night: 27 to 29 March is 47 real hours, which
//     divides into one whole day and not two. A two-day event became a
//     one-day event, and a four-day one became three.
//   - WHICH DAY IT ENDS ON. Done properly it is the start moved on by that
//     many days ON THE CLOCK. Counted as "the start plus N times 86,400
//     seconds" it lands an hour EARLY whenever the run crosses the 25-hour
//     night, which puts it back on the day before: a two-day event starting
//     on Sunday 25 October came back as the 25th on its own.
//
// Neither is an imagined fault. Both were planted in the reader during the
// seventh round of independent checking, and the whole self-test stayed green
// while each one gave a plainly wrong answer on a real calendar.
//
// THE DATES IN THIS FIXTURE ARE THE CHECK. Every other whole-day fixture here
// sits in December, June or mid-October, where no clock change happens, both
// ways of counting agree, and a broken reader looks perfectly right. Moving
// these events to a quieter month would leave every line below passing and
// stop them proving anything at all.
//
// Both nights are here because the two mistakes bite on different ones — the
// length on the short March night, the end day on the long October one. A
// single-day event cannot stand in for either: one day across the March night
// is 23 hours, which divides to zero, and the reader's own "never less than
// one day" floor quietly rescues it.
//
// THE NEXT TWO EVENTS ARE ABOUT THE REPEAT ENGINE, not about the length. That
// engine walks from one date to the next, and a step of "a day" taken as
// 86,400 seconds lands an hour short on the 25-hour night. For a whole-day
// event an hour short is enough to fall back onto the day it started from, and
// the date is then lost as a duplicate, silently:
//
//   - the DAILY series loses 27 October, so four days come back as three;
//   - the Saturday series loses Saturday 31 October and offers 8 November
//     instead. It sets WKST=SU — the week starts on Sunday, which American
//     calendars commonly do — because that is what puts the clock change
//     INSIDE the week rather than at its far end. With the usual Monday start
//     the change falls on the last day of the week at midnight, before the
//     clocks actually go back, and the mistake never shows.
//
// Both of those were found the same way as the first two: planted in the
// reader, self-test still green, wrong answer on a real calendar.
//
// AND THE LAST TWO EVENTS ARE ABOUT THE MARCH NIGHT, and about the other end
// of that same engine: the step it takes BACKWARDS, before it starts looking,
// to the first day of the week the event begins in. Both are a Saturday
// whole-day club starting on Saturday 3 April 2027, with the week starting on
// Sunday. That is WKST=SU, an ordinary and lawful shape in its own right, so
// this is not a contrived calendar. It is also believed to be what Google
// writes for anyone whose week starts on Sunday — but that is knowledge
// brought to this project, NOT something measured here, because no real
// Google export exists in this repository (see the two SKIPPED checks and
// acceptance criterion 3). Nothing in the reader depends on the belief.
// From a Saturday to the Sunday before is six days back, which lands on Sunday
// 28 March — the 23-hour day. Taken as six blocks of 86,400 seconds the step
// lands an HOUR EARLY, at eleven at night on Saturday the 27th, which for a
// whole-day event is the day before. So the seven-day week the rule then
// searches begins a day early and — this is the half that matters — ENDS a day
// early too, pushing that week's Saturday out of it.
//
// WHY INTERVAL=2 IS THE SHAPE THAT SHOWS IT. The Saturday pushed out of the
// early week is only lost if nothing picks it up. Every fortnight, the next
// week searched starts a FORTNIGHT later, so nothing does: every date after
// the first comes back a week early, on the weeks the series is meant to skip
// (3 April, then 10 and 24 April instead of 17 April and 1 May). Every week,
// the pushed-out Saturday turns up in the very next week searched and the
// dates look right — which is why the weekly twin is here as well, and why its
// COUNT is the point: the start date is then offered twice, counts twice
// against COUNT=3, and the duplicate is quietly dropped, so three dates come
// back as two.
//
// The March night is the one that matters here and October will not do. Going
// back across the 25-hour night lands an hour LATE, which is still the same
// day, so the mistake cannot show. A Monday week start will not do either:
// from a Saturday that step never crosses the change at all. Nor will a timed
// event at a sensible hour: an hour early only crosses midnight from midnight.
//
// This one was planted in the seventh round of independent checking, and the
// round then decided it could not produce a wrong answer and deliberately left
// it unguarded, reasoning that a week starting a day early still holds each
// weekday once. That is true of the days INSIDE one week and says nothing
// about the day pushed off the end of it. The eighth round built the calendar
// above and the reasoning fell over. Do not simplify this pair away. Move
// either event to a quieter month, change WKST, or give them a time of day,
// and BOTH checks below go on passing whatever the reader does. (Dropping the
// INTERVAL=2 event alone is less bad: the weekly check does catch this fault
// on its own, by losing a date to double-counting against COUNT. It is still
// the fortnightly one that shows what actually goes wrong, so keep both. This
// sentence used to say all four changes blinded both checks, which the ninth
// round of checking measured and found untrue.)
//
// Six events start on 25 October, and two more start on 3 April 2027. Where
// several events start at the same moment the answer is put in order of title,
// so the order below is settled and not an accident: on 25 October, "Daily
// holiday club…", "Half term, three…", "Half term, two…", "Saturday club…",
// "The twenty-five-hour day…", "Weekly two-day break…"; on 3 April and again
// on 17 April, "Fortnightly Saturday…" before "Weekly Saturday…".
//
// Every expected line was worked out by hand from the two clock-change dates
// and checked against plain PHP date arithmetic, NOT against the reader.
$r = st_read('allday-clock-change.ics');
st_lines(
    'B6 allday-clock-change.ics: whole days keep their length and their last day across both clock changes',
    [
        '2026-10-24 00:00:00|2026-10-24 23:59:59|allday|key=20261024|series|priv=no|canc=no|dup=no'
        . '|Daily holiday club, four days from the 24th',
        '2026-10-24 00:00:00|2026-10-25 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|Weekend across the night the clocks go back',
        '2026-10-25 00:00:00|2026-10-25 23:59:59|allday|key=20261025|series|priv=no|canc=no|dup=no'
        . '|Daily holiday club, four days from the 24th',
        '2026-10-25 00:00:00|2026-10-27 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|Half term, three days from the 25th',
        '2026-10-25 00:00:00|2026-10-26 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|Half term, two days from the 25th',
        '2026-10-25 00:00:00|2026-10-25 23:59:59|allday|key=20261025|series|priv=no|canc=no|dup=no'
        . '|Saturday club, in a week that starts on Sunday',
        '2026-10-25 00:00:00|2026-10-25 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|The twenty-five-hour day on its own',
        '2026-10-25 00:00:00|2026-10-26 23:59:59|allday|key=20261025|series|priv=no|canc=no|dup=no'
        . '|Weekly two-day break from the 25th',
        '2026-10-26 00:00:00|2026-10-26 23:59:59|allday|key=20261026|series|priv=no|canc=no|dup=no'
        . '|Daily holiday club, four days from the 24th',
        '2026-10-27 00:00:00|2026-10-27 23:59:59|allday|key=20261027|series|priv=no|canc=no|dup=no'
        . '|Daily holiday club, four days from the 24th',
        '2026-10-31 00:00:00|2026-10-31 23:59:59|allday|key=20261031|series|priv=no|canc=no|dup=no'
        . '|Saturday club, in a week that starts on Sunday',
        '2026-11-01 00:00:00|2026-11-01 23:59:59|allday|key=20261101|series|priv=no|canc=no|dup=no'
        . '|Saturday club, in a week that starts on Sunday',
        '2026-11-01 00:00:00|2026-11-02 23:59:59|allday|key=20261101|series|priv=no|canc=no|dup=no'
        . '|Weekly two-day break from the 25th',
        '2026-11-07 00:00:00|2026-11-07 23:59:59|allday|key=20261107|series|priv=no|canc=no|dup=no'
        . '|Saturday club, in a week that starts on Sunday',
        '2026-11-08 00:00:00|2026-11-09 23:59:59|allday|key=20261108|series|priv=no|canc=no|dup=no'
        . '|Weekly two-day break from the 25th',
        '2027-03-26 00:00:00|2027-03-29 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|Spring works, four days',
        '2027-03-27 00:00:00|2027-03-28 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|Spring works, two days',
        '2027-03-28 00:00:00|2027-03-28 23:59:59|allday|key=|one-off|priv=no|canc=no|dup=no'
        . '|The twenty-three-hour day on its own',
        '2027-04-03 00:00:00|2027-04-03 23:59:59|allday|key=20270403|series|priv=no|canc=no|dup=no'
        . '|Fortnightly Saturday youth club, week starting Sunday',
        '2027-04-03 00:00:00|2027-04-03 23:59:59|allday|key=20270403|series|priv=no|canc=no|dup=no'
        . '|Weekly Saturday youth club, week starting Sunday',
        '2027-04-10 00:00:00|2027-04-10 23:59:59|allday|key=20270410|series|priv=no|canc=no|dup=no'
        . '|Weekly Saturday youth club, week starting Sunday',
        '2027-04-17 00:00:00|2027-04-17 23:59:59|allday|key=20270417|series|priv=no|canc=no|dup=no'
        . '|Fortnightly Saturday youth club, week starting Sunday',
        '2027-04-17 00:00:00|2027-04-17 23:59:59|allday|key=20270417|series|priv=no|canc=no|dup=no'
        . '|Weekly Saturday youth club, week starting Sunday',
        '2027-05-01 00:00:00|2027-05-01 23:59:59|allday|key=20270501|series|priv=no|canc=no|dup=no'
        . '|Fortnightly Saturday youth club, week starting Sunday',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'B6 and not one warning was needed to get any of them right',
    $r['expand']['warnings'] === [] && $r['parse']['warnings'] === [],
    implode(' | ', array_merge($r['parse']['warnings'], $r['expand']['warnings']))
);
// The same two points again, one at a time, so that a failure says which of
// the two pieces of arithmetic went wrong instead of showing a ten-line list.
$byTitle = [];
foreach ($r['expand']['occurrences'] as $o) {
    $byTitle[(string) $o['title']][] = (string) $o['start'] . ' to ' . (string) $o['end'];
}
st_check(
    'B6 the length is a count of CALENDAR DAYS: 27 to 29 March is two days, which 47 real hours divided by 86,400 is not',
    ($byTitle['Spring works, two days'][0] ?? '') === '2027-03-27 00:00:00 to 2027-03-28 23:59:59'
    && ($byTitle['Spring works, four days'][0] ?? '') === '2027-03-26 00:00:00 to 2027-03-29 23:59:59',
    'two days: ' . ($byTitle['Spring works, two days'][0] ?? 'missing')
    . ' | four days: ' . ($byTitle['Spring works, four days'][0] ?? 'missing')
);
st_check(
    'B6 the last day is reached BY THE CLOCK: a run starting on the 25-hour day ends on the 26th, not back on the 25th',
    ($byTitle['Half term, two days from the 25th'][0] ?? '') === '2026-10-25 00:00:00 to 2026-10-26 23:59:59'
    && ($byTitle['Half term, three days from the 25th'][0] ?? '') === '2026-10-25 00:00:00 to 2026-10-27 23:59:59'
    && ($byTitle['Weekly two-day break from the 25th'][0] ?? '') === '2026-10-25 00:00:00 to 2026-10-26 23:59:59',
    'two days: ' . ($byTitle['Half term, two days from the 25th'][0] ?? 'missing')
    . ' | three days: ' . ($byTitle['Half term, three days from the 25th'][0] ?? 'missing')
    . ' | first date of the weekly series: ' . ($byTitle['Weekly two-day break from the 25th'][0] ?? 'missing')
);
st_check(
    'B6 and the later dates of that weekly series, which cross no clock change, are unchanged by any of this',
    ($byTitle['Weekly two-day break from the 25th'][1] ?? '') === '2026-11-01 00:00:00 to 2026-11-02 23:59:59'
    && ($byTitle['Weekly two-day break from the 25th'][2] ?? '') === '2026-11-08 00:00:00 to 2026-11-09 23:59:59',
    implode(' | ', $byTitle['Weekly two-day break from the 25th'] ?? ['missing'])
);
st_check(
    'B6 the repeat engine steps a DAY at a time, not 86,400 seconds: four daily whole days are four, and 27 October is one of them',
    count($byTitle['Daily holiday club, four days from the 24th'] ?? []) === 4
    && ($byTitle['Daily holiday club, four days from the 24th'][3] ?? '') === '2026-10-27 00:00:00 to 2026-10-27 23:59:59',
    implode(' | ', $byTitle['Daily holiday club, four days from the 24th'] ?? ['missing'])
);
st_check(
    'B6 and a weekly whole-day series whose week starts on SUNDAY still finds Saturday 31 October',
    count($byTitle['Saturday club, in a week that starts on Sunday'] ?? []) === 4
    && ($byTitle['Saturday club, in a week that starts on Sunday'][1] ?? '') === '2026-10-31 00:00:00 to 2026-10-31 23:59:59'
    && ($byTitle['Saturday club, in a week that starts on Sunday'][3] ?? '') === '2026-11-07 00:00:00 to 2026-11-07 23:59:59',
    implode(' | ', $byTitle['Saturday club, in a week that starts on Sunday'] ?? ['missing'])
);
// The step BACKWARDS to the start of the week, on the March night. A week that
// starts a day early also ends a day early, so it loses its own Saturday; at
// INTERVAL=2 nothing picks that Saturday up, because the next week searched is
// a fortnight away. Counting seconds gives 3, 10 and 24 April — the right dates
// for no series at all, and the wrong weeks for this one.
st_check(
    'B6 the step back to the start of the week is taken ON THE CALENDAR: a fortnightly Saturday series from 3 April 2027 '
    . 'keeps to its own weeks',
    count($byTitle['Fortnightly Saturday youth club, week starting Sunday'] ?? []) === 3
    && ($byTitle['Fortnightly Saturday youth club, week starting Sunday'][0] ?? '')
        === '2027-04-03 00:00:00 to 2027-04-03 23:59:59'
    && ($byTitle['Fortnightly Saturday youth club, week starting Sunday'][1] ?? '')
        === '2027-04-17 00:00:00 to 2027-04-17 23:59:59'
    && ($byTitle['Fortnightly Saturday youth club, week starting Sunday'][2] ?? '')
        === '2027-05-01 00:00:00 to 2027-05-01 23:59:59',
    implode(' | ', $byTitle['Fortnightly Saturday youth club, week starting Sunday'] ?? ['missing'])
);
// The same step, every week instead of every fortnight. Here the pushed-out
// Saturday is the start date itself, offered a second time by the following
// week: it counts twice against COUNT=3 and the duplicate is dropped later, so
// the series comes back one date short with nothing said.
st_check(
    'B6 and the same step every week gives THREE dates, not two: the start date is not counted twice against COUNT',
    count($byTitle['Weekly Saturday youth club, week starting Sunday'] ?? []) === 3
    && ($byTitle['Weekly Saturday youth club, week starting Sunday'][0] ?? '')
        === '2027-04-03 00:00:00 to 2027-04-03 23:59:59'
    && ($byTitle['Weekly Saturday youth club, week starting Sunday'][1] ?? '')
        === '2027-04-10 00:00:00 to 2027-04-10 23:59:59'
    && ($byTitle['Weekly Saturday youth club, week starting Sunday'][2] ?? '')
        === '2027-04-17 00:00:00 to 2027-04-17 23:59:59',
    implode(' | ', $byTitle['Weekly Saturday youth club, week starting Sunday'] ?? ['missing'])
);
unset($byTitle);

// =============================================================================
// C. Repeats
// =============================================================================
echo "\nC. Repeats\n";

$r = st_read('weekly-byday-until.ics');
st_lines(
    'C1 weekly-byday-until.ics: Mondays, Wednesdays and Fridays until 30 September, including the first week',
    [
        '2026-09-07 10:00:00|2026-09-07 11:00:00|timed|key=20260907T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-09 10:00:00|2026-09-09 11:00:00|timed|key=20260909T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-11 10:00:00|2026-09-11 11:00:00|timed|key=20260911T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-14 10:00:00|2026-09-14 11:00:00|timed|key=20260914T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-16 10:00:00|2026-09-16 11:00:00|timed|key=20260916T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-18 10:00:00|2026-09-18 11:00:00|timed|key=20260918T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-21 10:00:00|2026-09-21 11:00:00|timed|key=20260921T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-23 10:00:00|2026-09-23 11:00:00|timed|key=20260923T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-25 10:00:00|2026-09-25 11:00:00|timed|key=20260925T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-28 10:00:00|2026-09-28 11:00:00|timed|key=20260928T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
        '2026-09-30 10:00:00|2026-09-30 11:00:00|timed|key=20260930T090000Z|series|priv=no|canc=no|dup=no|Morning prayer',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('monthly-last-friday.ics');
st_lines(
    'C2 monthly-last-friday.ics: the last Friday of each month, eleven of them in the window',
    [
        '2026-10-30 19:00:00|2026-10-30 20:30:00|timed|key=20261030T190000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2026-11-27 19:00:00|2026-11-27 20:30:00|timed|key=20261127T190000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2026-12-25 19:00:00|2026-12-25 20:30:00|timed|key=20261225T190000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-01-29 19:00:00|2027-01-29 20:30:00|timed|key=20270129T190000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-02-26 19:00:00|2027-02-26 20:30:00|timed|key=20270226T190000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-03-26 19:00:00|2027-03-26 20:30:00|timed|key=20270326T190000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-04-30 19:00:00|2027-04-30 20:30:00|timed|key=20270430T180000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-05-28 19:00:00|2027-05-28 20:30:00|timed|key=20270528T180000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-06-25 19:00:00|2027-06-25 20:30:00|timed|key=20270625T180000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-07-30 19:00:00|2027-07-30 20:30:00|timed|key=20270730T180000Z|series|priv=no|canc=no|dup=no|Last Friday social',
        '2027-08-27 19:00:00|2027-08-27 20:30:00|timed|key=20270827T180000Z|series|priv=no|canc=no|dup=no|Last Friday social',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('monthly-bysetpos.ics');
st_lines(
    'C3 monthly-bysetpos.ics: the last weekday of each month (BYSETPOS picks from the whole month)',
    [
        '2026-09-30 17:00:00|2026-09-30 18:00:00|timed|key=20260930T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2026-10-30 17:00:00|2026-10-30 18:00:00|timed|key=20261030T170000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2026-11-30 17:00:00|2026-11-30 18:00:00|timed|key=20261130T170000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2026-12-31 17:00:00|2026-12-31 18:00:00|timed|key=20261231T170000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-01-29 17:00:00|2027-01-29 18:00:00|timed|key=20270129T170000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-02-26 17:00:00|2027-02-26 18:00:00|timed|key=20270226T170000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-03-31 17:00:00|2027-03-31 18:00:00|timed|key=20270331T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-04-30 17:00:00|2027-04-30 18:00:00|timed|key=20270430T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-05-31 17:00:00|2027-05-31 18:00:00|timed|key=20270531T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-06-30 17:00:00|2027-06-30 18:00:00|timed|key=20270630T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-07-30 17:00:00|2027-07-30 18:00:00|timed|key=20270730T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
        '2027-08-31 17:00:00|2027-08-31 18:00:00|timed|key=20270831T160000Z|series|priv=no|canc=no|dup=no|Month-end returns',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// C4: COUNT is read and obeyed. This check used to claim more than it showed.
// Its label, and the fixture's own note, said the fourth date "does NOT appear
// although it is inside the window" — but the window ends on 1 September 2027
// and the fourth date is 5 November 2027, so it is outside the window and
// would have been left out whether COUNT was obeyed or not. Both have been
// corrected, and C4b below is the check that really does show COUNT stopping a
// series.
$r = st_read('yearly-count.ics');
st_lines(
    'C4 yearly-count.ics: COUNT=3 from 2024 gives its last date in 2026, the only one inside the window',
    ['2026-11-05 19:00:00|2026-11-05 20:30:00|timed|key=20261105T190000Z|series|priv=no|canc=no|dup=no|Annual meeting'],
    st_lines_of($r['expand']['occurrences'])
);

// C4b: COUNT counts every date the rule produces, not only the ones inside the
// window. A series that began before the period being looked at is the ordinary
// case — a weekly meeting set up last year and asked to run forty times. Count
// only the dates kept, and the series runs on for as long again, showing dates
// that are not in the calendar at all.
$r = st_read('daily-count-before-window.ics');
$countLines = st_lines_of($r['expand']['occurrences']);
st_check(
    'C4b daily-count-before-window.ics: COUNT=40 from 1 August leaves nine dates inside the window, not forty',
    count($countLines) === 9,
    'got ' . count($countLines)
);
st_check(
    'C4b and they are 1 to 9 September 2026, the END of the forty',
    ($countLines[0] ?? '') === '2026-09-01 10:00:00|2026-09-01 11:00:00|timed|key=20260901T090000Z|series|priv=no|canc=no|dup=no|Daily for forty days'
    && ($countLines[8] ?? '') === '2026-09-09 10:00:00|2026-09-09 11:00:00|timed|key=20260909T090000Z|series|priv=no|canc=no|dup=no|Daily for forty days',
    ($countLines[0] ?? '(none)') . ' … ' . ($countLines[8] ?? '(none)')
);
st_check('C4b and nothing is reported as cut short, because the rule stopped itself', $r['expand']['capped'] === false);

$r = st_read('rdate.ics');
st_lines(
    'C5 rdate.ics: dates added with RDATE, including two on one line, with no repeat rule at all',
    [
        '2026-10-07 19:00:00|2026-10-07 20:00:00|timed|key=20261007T180000Z|series|priv=no|canc=no|dup=no|Added dates',
        '2026-10-14 19:00:00|2026-10-14 20:00:00|timed|key=20261014T180000Z|series|priv=no|canc=no|dup=no|Added dates',
        '2026-10-21 19:00:00|2026-10-21 20:00:00|timed|key=20261021T180000Z|series|priv=no|canc=no|dup=no|Added dates',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('huge-series.ics');
$lines = st_lines_of($r['expand']['occurrences']);
st_check('C6 huge-series.ics: twenty years of a weekly series gives 53 dates inside the window', count($lines) === 53, 'got ' . count($lines));
st_check(
    'C6 huge-series.ics: the first and last dates in the window are right',
    ($lines[0] ?? '') === '2026-09-01 19:00:00|2026-09-01 20:00:00|timed|key=20260901T180000Z|series|priv=no|canc=no|dup=no|Tuesday prayer'
    && ($lines[52] ?? '') === '2027-08-31 19:00:00|2027-08-31 20:00:00|timed|key=20270831T180000Z|series|priv=no|canc=no|dup=no|Tuesday prayer',
    ($lines[0] ?? '(none)') . ' … ' . ($lines[52] ?? '(none)')
);
st_check('C6 huge-series.ics: 53 is well under the limit, so it is NOT reported as cut short', $r['expand']['capped'] === false);
st_check('C6 huge-series.ics: and no end-of-reading point is reported', $r['expand']['effectiveWindowEnd'] === null, var_export($r['expand']['effectiveWindowEnd'], true));

// C7: UNTIL is INCLUSIVE. Google writes UNTIL as the last date's own start
// time, so a reader that treats it as "up to but not including" drops the last
// date of every ended Google series - silently, and only on series that have
// finished, which nobody looks at. The clock goes back on 25 October 2026, so
// this also shows UNTIL being compared as a moment rather than as text: the
// first three dates are 18:00 in UTC and the last is 19:00.
$r = st_read('weekly-until-google-style.ics');
st_lines(
    'C7 weekly-until-google-style.ics: UNTIL equal to the last date keeps that date (four Tuesdays, the last on 27 October)',
    [
        '2026-10-06 19:00:00|2026-10-06 20:00:00|timed|key=20261006T180000Z|series|priv=no|canc=no|dup=no|Tuesday study',
        '2026-10-13 19:00:00|2026-10-13 20:00:00|timed|key=20261013T180000Z|series|priv=no|canc=no|dup=no|Tuesday study',
        '2026-10-20 19:00:00|2026-10-20 20:00:00|timed|key=20261020T180000Z|series|priv=no|canc=no|dup=no|Tuesday study',
        '2026-10-27 19:00:00|2026-10-27 20:00:00|timed|key=20261027T190000Z|series|priv=no|canc=no|dup=no|Tuesday study',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// C8: INTERVAL. "Every second Thursday" and "every third month". A reader that
// ignored INTERVAL would produce twice or three times as many dates, every one
// of them looking perfectly ordinary on the page.
$r = st_read('interval-fortnightly.ics');
st_lines(
    'C8 interval-fortnightly.ics: every SECOND Thursday and every THIRD month, not every Thursday and every month',
    [
        '2026-09-03 18:00:00|2026-09-03 19:00:00|timed|key=20260903T170000Z|series|priv=no|canc=no|dup=no|Fortnightly rota',
        '2026-09-10 12:00:00|2026-09-10 13:00:00|timed|key=20260910T110000Z|series|priv=no|canc=no|dup=no|Quarterly review',
        '2026-09-17 18:00:00|2026-09-17 19:00:00|timed|key=20260917T170000Z|series|priv=no|canc=no|dup=no|Fortnightly rota',
        '2026-10-01 18:00:00|2026-10-01 19:00:00|timed|key=20261001T170000Z|series|priv=no|canc=no|dup=no|Fortnightly rota',
        '2026-10-15 18:00:00|2026-10-15 19:00:00|timed|key=20261015T170000Z|series|priv=no|canc=no|dup=no|Fortnightly rota',
        '2026-10-29 18:00:00|2026-10-29 19:00:00|timed|key=20261029T180000Z|series|priv=no|canc=no|dup=no|Fortnightly rota',
        '2026-12-10 12:00:00|2026-12-10 13:00:00|timed|key=20261210T120000Z|series|priv=no|canc=no|dup=no|Quarterly review',
        '2027-03-10 12:00:00|2027-03-10 13:00:00|timed|key=20270310T120000Z|series|priv=no|canc=no|dup=no|Quarterly review',
        '2027-06-10 12:00:00|2027-06-10 13:00:00|timed|key=20270610T110000Z|series|priv=no|canc=no|dup=no|Quarterly review',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// C9: a Monday/Wednesday/Friday series created on a WEDNESDAY. A weekly rule is
// worked out a whole week at a time, so the Monday before the series began is
// offered by the first week and has to be thrown away. Keep it and the series
// gains a date it never had, two days before it started.
$r = st_read('weekly-start-midweek.ics');
st_lines(
    'C9 weekly-start-midweek.ics: a Mon/Wed/Fri series starting on a Wednesday does not gain the Monday before it began',
    [
        '2026-09-09 10:00:00|2026-09-09 11:00:00|timed|key=20260909T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
        '2026-09-11 10:00:00|2026-09-11 11:00:00|timed|key=20260911T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
        '2026-09-14 10:00:00|2026-09-14 11:00:00|timed|key=20260914T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
        '2026-09-16 10:00:00|2026-09-16 11:00:00|timed|key=20260916T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
        '2026-09-18 10:00:00|2026-09-18 11:00:00|timed|key=20260918T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
        '2026-09-21 10:00:00|2026-09-21 11:00:00|timed|key=20260921T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
        '2026-09-23 10:00:00|2026-09-23 11:00:00|timed|key=20260923T090000Z|series|priv=no|canc=no|dup=no|Midweek prayer',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// C10: WKST - which day the week starts on. It only changes the answer when a
// rule repeats every second week or less often AND names days on both sides of
// the week's start, which is exactly this fixture. Ignore WKST here and the
// dates move, with nothing to say they have.
$r = st_read('weekly-wkst-sunday.ics');
st_lines(
    'C10 weekly-wkst-sunday.ics: with the week starting on Sunday, a fortnightly Sunday/Tuesday rule gives 8, 20, 22 September and 4, 6, 18 October',
    [
        '2026-09-08 19:00:00|2026-09-08 20:00:00|timed|key=20260908T180000Z|series|priv=no|canc=no|dup=no|Fortnightly pair',
        '2026-09-20 19:00:00|2026-09-20 20:00:00|timed|key=20260920T180000Z|series|priv=no|canc=no|dup=no|Fortnightly pair',
        '2026-09-22 19:00:00|2026-09-22 20:00:00|timed|key=20260922T180000Z|series|priv=no|canc=no|dup=no|Fortnightly pair',
        '2026-10-04 19:00:00|2026-10-04 20:00:00|timed|key=20261004T180000Z|series|priv=no|canc=no|dup=no|Fortnightly pair',
        '2026-10-06 19:00:00|2026-10-06 20:00:00|timed|key=20261006T180000Z|series|priv=no|canc=no|dup=no|Fortnightly pair',
        '2026-10-18 19:00:00|2026-10-18 20:00:00|timed|key=20261018T180000Z|series|priv=no|canc=no|dup=no|Fortnightly pair',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// C11: a yearly rule that names no month. RFC 5545 counts its days across the
// whole year: "every Sunday" is every Sunday of the year, and "the 1st" is the
// first of every month. Both used to be read as "in the month the series
// started in", giving 4 dates and 1 date with no warning at all. The lists are
// too long to write out, so the count and the ends are checked instead.
$r = st_read('yearly-no-month.ics');
$sundays = [];
$firsts  = [];
foreach ($r['expand']['occurrences'] as $o) {
    if (str_contains((string) $o['title'], 'Sunday') === true) {
        $sundays[] = (string) $o['start'];
        continue;
    }
    $firsts[] = (string) $o['start'];
}
st_check(
    'C11 yearly-no-month.ics: FREQ=YEARLY;BYDAY=SU gives every Sunday of the year (52 in this window), not just the ones in September',
    count($sundays) === 52,
    'got ' . count($sundays)
);
st_check(
    'C11 and the first and last of them are right',
    ($sundays[0] ?? '') === '2026-09-06 10:00:00' && ($sundays[count($sundays) - 1] ?? '') === '2027-08-29 10:00:00',
    ($sundays[0] ?? '(none)') . ' … ' . ($sundays[count($sundays) - 1] ?? '(none)')
);
st_check(
    'C11 FREQ=YEARLY;BYMONTHDAY=1 gives the first of every month (12), not just one',
    count($firsts) === 12,
    'got ' . count($firsts)
);
st_check(
    'C11 and the first of them is the series\' own start, the last the first of August',
    ($firsts[0] ?? '') === '2026-09-06 14:00:00' && ($firsts[count($firsts) - 1] ?? '') === '2027-08-01 14:00:00',
    ($firsts[0] ?? '(none)') . ' … ' . ($firsts[count($firsts) - 1] ?? '(none)')
);
st_check('C11 and neither of them records a warning', $r['expand']['warnings'] === [], implode(' | ', $r['expand']['warnings']));

// C12: a repeat rule that lists the same value twice. `BYMONTHDAY=1,1` means
// exactly what `BYMONTHDAY=1` means, but the repeat was being COUNTED: the
// first of the month was offered twice in every month, so a COUNT of 3 ran out
// after two months and the third date never appeared. Nobody would ever have
// looked at the rule to find out why, because the rule itself reads perfectly
// normally. Every BY… list now has its repeats dropped before it is used.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:repeat-in-list@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T080000\r\nDTEND;TZID=Europe/London:20260901T090000\r\n"
    . "RRULE:FREQ=MONTHLY;BYMONTHDAY=1,1;COUNT=3\r\nSUMMARY:Repeated day of the month\r\n"
    . "END:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'C12 a rule listing the same day of the month twice still gives all three of its COUNT dates',
    [
        '2026-09-01 08:00:00|2026-09-01 09:00:00|timed|key=20260901T070000Z|series|priv=no|canc=no|dup=no|Repeated day of the month',
        '2026-10-01 08:00:00|2026-10-01 09:00:00|timed|key=20261001T070000Z|series|priv=no|canc=no|dup=no|Repeated day of the month',
        '2026-11-01 08:00:00|2026-11-01 09:00:00|timed|key=20261101T080000Z|series|priv=no|canc=no|dup=no|Repeated day of the month',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check('C12 and the same date is not reported as sent twice', $r['expand']['duplicates'] === 0, 'got ' . $r['expand']['duplicates']);

// =============================================================================
// D. Skipped and changed dates
// =============================================================================
echo "\nD. Skipped and changed dates\n";

$r = st_read('exdate-multi-lines.ics');
st_lines(
    'D1 exdate-multi-lines.ics: five Tuesdays with two skipped on separate EXDATE lines leaves three',
    [
        '2026-09-08 19:00:00|2026-09-08 20:00:00|timed|key=20260908T180000Z|series|priv=no|canc=no|dup=no|Tuesday study',
        '2026-09-29 19:00:00|2026-09-29 20:00:00|timed|key=20260929T180000Z|series|priv=no|canc=no|dup=no|Tuesday study',
        '2026-10-06 19:00:00|2026-10-06 20:00:00|timed|key=20261006T180000Z|series|priv=no|canc=no|dup=no|Tuesday study',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('exdate-windows-tz.ics');
st_lines(
    'D2 exdate-windows-tz.ics: the date skipped with a Windows zone name is gone, and the two left carry New York times',
    [
        '2026-10-07 00:00:00|2026-10-07 01:00:00|timed|key=20261006T230000Z|series|priv=no|canc=no|dup=no|New York study',
        '2026-10-21 00:00:00|2026-10-21 01:00:00|timed|key=20261020T230000Z|series|priv=no|canc=no|dup=no|New York study',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'D2 control: the Windows zone name really was recognised (no warning, and the times are not London readings)',
    st_has_warning($r['expand']['warnings'], 'Unknown time zone') === false,
    implode(' | ', $r['expand']['warnings'])
);

$r = st_read('recurrence-id-utc-for-tzid-series.ics');
st_lines(
    'D3 recurrence-id-utc-for-tzid-series.ics: a changed date written in UTC replaces its slot — three dates, not four',
    [
        '2026-10-06 19:00:00|2026-10-06 20:00:00|timed|key=20261006T180000Z|series|priv=no|canc=no|dup=no|Tuesday practice',
        '2026-10-13 20:00:00|2026-10-13 21:30:00|timed|key=20261013T180000Z|series|priv=no|canc=no|dup=no|Tuesday practice (moved)',
        '2026-10-20 19:00:00|2026-10-20 20:00:00|timed|key=20261020T180000Z|series|priv=no|canc=no|dup=no|Tuesday practice',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'D3 the changed date keeps the ORIGINAL slot as its identity (19:00, not the new 20:00)',
    ($r['expand']['occurrences'][1]['recurrenceKey'] ?? '') === '20261013T180000Z',
    var_export($r['expand']['occurrences'][1]['recurrenceKey'] ?? null, true)
);

$r = st_read('recurrence-id-thisandfuture.ics');
st_lines(
    'D4 recurrence-id-thisandfuture.ics: "this date and every later one" is applied to that one date only',
    [
        '2026-11-05 18:00:00|2026-11-05 19:00:00|timed|key=20261105T180000Z|series|priv=no|canc=no|dup=no|Thursday class',
        '2026-11-12 18:30:00|2026-11-12 19:30:00|timed|key=20261112T180000Z|series|priv=no|canc=no|dup=no|Thursday class (new time)',
        '2026-11-19 18:00:00|2026-11-19 19:00:00|timed|key=20261119T180000Z|series|priv=no|canc=no|dup=no|Thursday class',
        '2026-11-26 18:00:00|2026-11-26 19:00:00|timed|key=20261126T180000Z|series|priv=no|canc=no|dup=no|Thursday class',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'D4 and the difference is reported as a warning rather than passed over in silence',
    st_has_warning($r['expand']['warnings'], 'applied it to that one date only') === true,
    implode(' | ', $r['expand']['warnings'])
);

$r = st_read('recurrence-id-orphan.ics');
st_lines(
    'D5 recurrence-id-orphan.ics: a changed date sent on its own is kept; one for a slot the rule never made is added; '
    . 'one for a date the series REMOVED stays out',
    [
        '2026-12-01 19:30:00|2026-12-01 20:30:00|timed|key=20261201T190000Z|series|priv=no|canc=no|dup=no|Orphan changed date',
        '2026-12-08 19:00:00|2026-12-08 20:00:00|timed|key=20261208T190000Z|series|priv=no|canc=no|dup=no|Tuesday group',
        '2026-12-09 19:00:00|2026-12-09 20:00:00|timed|key=20261209T190000Z|series|priv=no|canc=no|dup=no|Tuesday group (extra Wednesday)',
        '2026-12-14 19:00:00|2026-12-14 20:00:00|timed|key=20261214T190000Z|series|priv=no|canc=no|dup=no|Monday group',
        '2026-12-15 19:00:00|2026-12-15 20:00:00|timed|key=20261215T190000Z|series|priv=no|canc=no|dup=no|Tuesday group',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'D5 and reading it does not stop with an error (it did, before the mutation run found it)',
    count($r['expand']['occurrences']) === 5,
    'got ' . count($r['expand']['occurrences'])
);

// D6: skipped dates written as one comma-separated line. Microsoft 365 writes
// them that way; Google writes one EXDATE line each, and every other fixture
// here uses Google's shape. A reader that stopped splitting on the commas
// would put deleted dates back into every Microsoft calendar and say nothing.
// (RDATE's twin of this is already covered by C5.)
$r = st_read('exdate-one-line-m365.ics');
st_lines(
    'D6 exdate-one-line-m365.ics: two dates skipped on ONE comma-separated line, as Microsoft 365 writes them',
    [
        '2026-10-06 19:00:00|2026-10-06 20:00:00|timed|key=20261006T180000Z|series|priv=no|canc=no|dup=no|Tuesday meeting',
        '2026-10-20 19:00:00|2026-10-20 20:00:00|timed|key=20261020T180000Z|series|priv=no|canc=no|dup=no|Tuesday meeting',
        '2026-11-03 19:00:00|2026-11-03 20:00:00|timed|key=20261103T190000Z|series|priv=no|canc=no|dup=no|Tuesday meeting',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// D7: a changed date of a WHOLE-DAY series. Google writes it as
// RECURRENCE-ID;VALUE=DATE, with no time at all, and it has to be matched
// against the whole-day slot it replaces. Matched as if it were a time, it
// matches nothing: the old date stays AND the new one is added beside it, so
// the calendar shows a whole-day event twice on two different days. Every
// other changed-date fixture here is a timed one.
$r = st_read('allday-recurrence-id.ics');
st_lines(
    'D7 allday-recurrence-id.ics: a whole-day changed date replaces its own slot — three days, not four',
    [
        '2026-10-12 00:00:00|2026-10-12 23:59:59|allday|key=20261012|series|priv=no|canc=no|dup=no|Whole-day series',
        '2026-10-20 00:00:00|2026-10-20 23:59:59|allday|key=20261019|series|priv=no|canc=no|dup=no|Whole-day series (moved to the Tuesday)',
        '2026-10-26 00:00:00|2026-10-26 23:59:59|allday|key=20261026|series|priv=no|canc=no|dup=no|Whole-day series',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'D7 and 19 October, the day it was moved FROM, is not there as well',
    str_contains(implode(' ', st_lines_of($r['expand']['occurrences'])), '2026-10-19 00:00:00') === false,
    implode(' | ', st_lines_of($r['expand']['occurrences']))
);

// =============================================================================
// E. Cancelled
// =============================================================================
echo "\nE. Cancelled\n";

$r = st_read('cancelled-series.ics');
st_lines(
    'E1 cancelled-series.ics: a cancelled series marks every one of its dates cancelled',
    [
        '2026-11-03 19:00:00|2026-11-03 20:00:00|timed|key=20261103T190000Z|series|priv=no|canc=yes|dup=no|Cancelled series',
        '2026-11-10 19:00:00|2026-11-10 20:00:00|timed|key=20261110T190000Z|series|priv=no|canc=yes|dup=no|Cancelled series',
        '2026-11-17 19:00:00|2026-11-17 20:00:00|timed|key=20261117T190000Z|series|priv=no|canc=yes|dup=no|Cancelled series',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// E1b: a cancelled series cancels its CHANGED dates too, even when the changed
// date says STATUS:CONFIRMED. cancelled-series.ics has no changed date in it,
// so it cannot show this: the series could stop reaching its changed dates and
// E1 would still pass. A rearranged date of a cancelled series that came back
// as going ahead would put a cancelled meeting on the calendar.
$r = st_read('cancelled-series-with-override.ics');
st_lines(
    'E1b cancelled-series-with-override.ics: a cancelled series also cancels the date that was moved',
    [
        '2026-12-01 19:00:00|2026-12-01 20:00:00|timed|key=20261201T190000Z|series|priv=no|canc=yes|dup=no|Cancelled series with a changed date',
        '2026-12-08 20:00:00|2026-12-08 21:00:00|timed|key=20261208T190000Z|series|priv=no|canc=yes|dup=no|Cancelled series with a changed date (moved)',
        '2026-12-15 19:00:00|2026-12-15 20:00:00|timed|key=20261215T190000Z|series|priv=no|canc=yes|dup=no|Cancelled series with a changed date',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('cancelled-occurrence.ics');
st_lines(
    'E2 cancelled-occurrence.ics: cancelling one date leaves the others alone',
    [
        '2026-11-04 19:00:00|2026-11-04 20:00:00|timed|key=20261104T190000Z|series|priv=no|canc=no|dup=no|Midweek meeting',
        '2026-11-11 19:00:00|2026-11-11 20:00:00|timed|key=20261111T190000Z|series|priv=no|canc=yes|dup=no|Midweek meeting',
        '2026-11-18 19:00:00|2026-11-18 20:00:00|timed|key=20261118T190000Z|series|priv=no|canc=no|dup=no|Midweek meeting',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// =============================================================================
// F. Private
// =============================================================================
echo "\nF. Private\n";

$r = st_read('class-private.ics');
st_lines(
    'F1 class-private.ics: PRIVATE is private; PUBLIC and no CLASS line at all are not',
    [
        '2026-11-13 14:00:00|2026-11-13 15:00:00|timed|key=|one-off|priv=yes|canc=no|dup=no|Private appointment',
        '2026-11-13 16:00:00|2026-11-13 17:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Public appointment',
        '2026-11-13 18:00:00|2026-11-13 19:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|No class line',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('class-lowercase-folded.ics');
st_lines(
    'F2 class-lowercase-folded.ics: a lower-case name and a value split across two lines still read as private',
    ['2026-11-14 11:00:00|2026-11-14 12:00:00|timed|key=|one-off|priv=yes|canc=no|dup=no|Pastoral visit'],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('class-unknown-value.ics');
st_lines(
    'F3 class-unknown-value.ics: CONFIDENTIAL and an unknown value are both private (RFC 5545 asks for the safest reading)',
    [
        '2026-11-16 09:00:00|2026-11-16 10:00:00|timed|key=|one-off|priv=yes|canc=no|dup=no|Unknown class value',
        '2026-11-16 11:00:00|2026-11-16 12:00:00|timed|key=|one-off|priv=yes|canc=no|dup=no|Confidential',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// F5 first, because it is the direction that leaks. The class promises that a
// private series stays private on every one of its dates, INCLUDING a changed
// one that forgot to say so — and a changed date written by the calendar owner
// may well carry CLASS:PUBLIC. Only the opposite direction was checked (F4
// below), so the promise could have stopped being kept and nothing here would
// have noticed. A private pastoral series with one visit rearranged would then
// show that visit, with its title, to everybody.
$r = st_read('class-series-private-override-public.ics');
st_lines(
    'F5 class-series-private-override-public.ics: a PRIVATE series keeps its changed date private, even though that date says PUBLIC',
    [
        '2026-11-03 19:00:00|2026-11-03 20:00:00|timed|key=20261103T190000Z|series|priv=yes|canc=no|dup=no|Private series',
        '2026-11-10 20:00:00|2026-11-10 21:00:00|timed|key=20261110T190000Z|series|priv=yes|canc=no|dup=no|Private series (moved, and wrongly marked public)',
        '2026-11-17 19:00:00|2026-11-17 20:00:00|timed|key=20261117T190000Z|series|priv=yes|canc=no|dup=no|Private series',
    ],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('class-override-only.ics');
st_lines(
    'F4 class-override-only.ics: one date of a public series marked private affects only that date',
    [
        '2026-11-17 19:00:00|2026-11-17 20:00:00|timed|key=20261117T190000Z|series|priv=no|canc=no|dup=no|Open evening',
        '2026-11-24 19:00:00|2026-11-24 20:00:00|timed|key=20261124T190000Z|series|priv=yes|canc=no|dup=no|Open evening',
        '2026-12-01 19:00:00|2026-12-01 20:00:00|timed|key=20261201T190000Z|series|priv=no|canc=no|dup=no|Open evening',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// =============================================================================
// G. Untrusted text
// =============================================================================
echo "\nG. Untrusted text\n";

$r = st_read('html-description-google.ics');
$description = (string) ($r['expand']['occurrences'][0]['description'] ?? '');
st_check('G1 html-description-google.ics: nothing with an angle bracket is left', str_contains($description, '<') === false, var_export($description, true));
st_check('G1 the "&amp;" was decoded to a plain ampersand', str_contains($description, 'Tea & coffee') === true, var_export($description, true));
st_check('G1 the list items became separate lines', $description === "Tea & coffee from 6pm\nBring a friend\nRaffle", var_export($description, true));
st_check('G1 the script\'s own code was removed, not just its tags', str_contains($description, 'alert(') === false, var_export($description, true));

$r = st_read('direction-marks.ics');
$title    = (string) ($r['expand']['occurrences'][0]['title'] ?? '');
$location = (string) ($r['expand']['occurrences'][0]['location'] ?? '');
st_check('G2 direction-marks.ics: the right-to-left override character is gone from the title', str_contains($title, "\u{202E}") === false, bin2hex($title));
st_check('G2 the title reads as the plain characters that were left', $title === 'Invoicegpj.exe', var_export($title, true));
st_check('G2 an invisible character inside the place name is gone too', $location === 'HallA', var_export($location, true));

$r = st_read('folded-crlf.ics');
st_check(
    'G3 folded-crlf.ics: lines split three ways, including inside a word and with a tab, are joined back',
    ($r['expand']['occurrences'][0]['title'] ?? '') === 'Harvest thanksgiving service and shared meal',
    var_export($r['expand']['occurrences'][0]['title'] ?? null, true)
);

$r = st_read('bom-lf.ics');
st_check('G4 bom-lf.ics: a byte-order mark and line-feed endings are read normally', $r['parse']['complete'] === true);
st_lines(
    'G4 bom-lf.ics: and the event comes out',
    ['2026-11-21 15:00:00|2026-11-21 16:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Byte-order mark'],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('categories-multi-line-case.ics');
st_check(
    'G5 categories-multi-line-case.ics: categories add up across lines, an escaped comma is kept, and capitals do not make a second one',
    ($r['expand']['occurrences'][0]['categories'] ?? []) === ['Youth', 'Music', 'Tea, coffee'],
    json_encode($r['expand']['occurrences'][0]['categories'] ?? null)
);

// =============================================================================
// H. Identity
// =============================================================================
echo "\nH. Identity\n";

$r = st_read('no-uid.ics');
st_check('H1 no-uid.ics: the event with no UID was counted as skipped', $r['expand']['skippedNoUid'] === 1, 'got ' . $r['expand']['skippedNoUid']);
st_lines(
    'H1 no-uid.ics: and only the event that has one comes out',
    ['2026-11-22 17:00:00|2026-11-22 18:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Has identity'],
    st_lines_of($r['expand']['occurrences'])
);

$r = st_read('duplicate-uid.ics');
st_check('H2 duplicate-uid.ics: the repeat was counted', $r['expand']['duplicates'] === 1, 'got ' . $r['expand']['duplicates']);
st_lines(
    'H2 duplicate-uid.ics: the first copy is kept, once, and marked as a repeat',
    ['2026-11-23 12:00:00|2026-11-23 13:00:00|timed|key=|one-off|priv=no|canc=no|dup=yes|First copy'],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'H2 the identity is the SHA-256 of the UID bytes, 32 bytes long',
    strlen((string) ($r['expand']['occurrences'][0]['uidHash'] ?? '')) === 32
    && ($r['expand']['occurrences'][0]['uidHash'] ?? '') === hash('sha256', 'duplicate-1@fixtures.webms.test', true),
    bin2hex((string) ($r['expand']['occurrences'][0]['uidHash'] ?? ''))
);

// =============================================================================
// I. Limits — the calendar must never be able to ask for unbounded work
// =============================================================================
echo "\nI. Limits\n";

$r = st_read('unsupported-hourly.ics');
st_lines(
    'I1 unsupported-hourly.ics: a rule this portal does not work out gives the first date only',
    ['2026-11-24 12:00:00|2026-11-24 13:00:00|timed|key=20261124T120000Z|series|priv=no|canc=no|dup=no|Every hour'],
    st_lines_of($r['expand']['occurrences'])
);
st_check('I1 and says so in a warning', st_has_warning($r['expand']['warnings'], 'does not work out') === true, implode(' | ', $r['expand']['warnings']));

$r = st_read('interval-zero.ics');
st_lines(
    'I2 interval-zero.ics: "every 0 days" gives the first date only instead of repeating for ever',
    ['2026-11-25 12:00:00|2026-11-25 13:00:00|timed|key=20261125T120000Z|series|priv=no|canc=no|dup=no|Interval zero'],
    st_lines_of($r['expand']['occurrences'])
);
st_check('I2 and says so in a warning', st_has_warning($r['expand']['warnings'], 'interval below 1') === true, implode(' | ', $r['expand']['warnings']));
st_check('I2 and finishes in under a second', $r['seconds'] < 1.0, sprintf('%.3f seconds', $r['seconds']));

$r = st_read('year0001-daily-count.ics');
st_check('I3 year0001-daily-count.ics: nothing is returned, because the one date it gives is centuries before the window', $r['expand']['occurrences'] === [], 'got ' . count($r['expand']['occurrences']));
st_check('I3 and says so in a warning', st_has_warning($r['expand']['warnings'], 'more than this portal works out') === true, implode(' | ', $r['expand']['warnings']));
st_check('I3 and finishes in under a second', $r['seconds'] < 1.0, sprintf('%.3f seconds', $r['seconds']));

// I4: one series with more dates than the limit for a single series. The window
// has to be wider than the fixed one for this to happen at all, so this check
// says so plainly rather than pretending it uses the same conditions.
$dailyIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:series-cap-1@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T080000\r\nDTEND;TZID=Europe/London:20260901T090000\r\n"
    . "RRULE:FREQ=DAILY\r\nSUMMARY:Every day for ever\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$parsedDaily   = IcsReader::parse($dailyIcs, microtime(true) + 30.0);
$expandedDaily = IcsReader::expand(
    $parsedDaily,
    $orgZone,
    $orgZone,
    $windowStart,
    new DateTimeImmutable('2029-09-01 00:00:00', $orgZone),
    microtime(true) + 30.0
);
st_check(
    'I4 a never-ending daily series over a three-year window stops at ' . IcsReader::MAX_OCCURRENCES_PER_SERIES . ' dates',
    count($expandedDaily['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES,
    'got ' . count($expandedDaily['occurrences'])
);
st_check('I4 and is reported as cut short', $expandedDaily['capped'] === true);
// THIS CHECK USED TO ASSERT THE OPPOSITE and was changed on 23 September 2026.
// It said "the end of what was read is the last date kept, so the importer
// knows not to delete beyond it". A ninth round of checking measured that
// value deleting real events, so it is gone. See I33 below, which is where the
// whole story and the deliberate cost are written down.
st_check(
    'I4 and reports NO "read reliably up to here" point, because a cut series has none (see I33)',
    $expandedDaily['effectiveWindowEnd'] === null,
    var_export($expandedDaily['effectiveWindowEnd'], true)
);

// I4b: the edge of that same limit, from both sides. A series with EXACTLY the
// limit's worth of dates has had nothing left out, so saying "the later ones
// were left out" would be untrue - and an administrator who reads that goes
// looking for dates that were never missing. One more date, and the warning is
// earned.
$onTheLine = static function (int $count) use ($orgZone, $windowStart): array {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:cap-edge-" . $count . "@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T080000\r\nDTEND;TZID=Europe/London:20260901T090000\r\n"
        . 'RRULE:FREQ=DAILY;COUNT=' . $count . "\r\nSUMMARY:Daily for a while\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $parsed = IcsReader::parse($ics, microtime(true) + 30.0);

    return IcsReader::expand(
        $parsed,
        $orgZone,
        $orgZone,
        $windowStart,
        new DateTimeImmutable('2029-09-01 00:00:00', $orgZone),
        microtime(true) + 30.0
    );
};
$exactly = $onTheLine(IcsReader::MAX_OCCURRENCES_PER_SERIES);
st_check(
    'I4b a series with exactly ' . IcsReader::MAX_OCCURRENCES_PER_SERIES . ' dates gives all of them',
    count($exactly['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES,
    'got ' . count($exactly['occurrences'])
);
st_check('I4b and is NOT reported as cut short, because nothing was cut', $exactly['capped'] === false);
st_check('I4b and says nothing about later dates being left out', $exactly['warnings'] === [], implode(' | ', $exactly['warnings']));
$oneMore = $onTheLine(IcsReader::MAX_OCCURRENCES_PER_SERIES + 1);
st_check(
    'I4b one date more, and ' . IcsReader::MAX_OCCURRENCES_PER_SERIES . ' are kept',
    count($oneMore['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES,
    'got ' . count($oneMore['occurrences'])
);
st_check('I4b and THAT is reported as cut short', $oneMore['capped'] === true);
st_check(
    'I4b with the warning that says so',
    st_has_warning($oneMore['warnings'], 'so the later ones were left out') === true,
    implode(' | ', $oneMore['warnings'])
);

// I5: a calendar with more dates than the whole-calendar limit. 2500 one-off
// events are written to a throwaway file (not committed: it is 300KB of
// nothing but repetition) and read back, so the whole path from file to dates
// is exercised.
$manyPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'webms-ics-many-events-'
    . getmypid() . '.ics';
$manyBody = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WebMS Intra//Test fixture//EN\r\n";
$manyFirst = new DateTimeImmutable('2026-09-02 00:00:00', new DateTimeZone('UTC'));
for ($i = 0; $i < 2500; $i++) {
    $moment    = $manyFirst->modify('+' . $i . ' hours');
    $manyBody .= "BEGIN:VEVENT\r\nUID:many-" . $i . "@fixtures.webms.test\r\n"
        . 'DTSTART:' . $moment->format('Ymd\THis\Z') . "\r\n"
        . 'DTEND:' . $moment->modify('+30 minutes')->format('Ymd\THis\Z') . "\r\n"
        . 'SUMMARY:Event ' . $i . "\r\nEND:VEVENT\r\n";
}
$manyBody .= "END:VCALENDAR\r\n";
file_put_contents($manyPath, $manyBody);
$manyParsed   = IcsReader::parse((string) file_get_contents($manyPath), microtime(true) + 30.0);
$manyExpanded = IcsReader::expand($manyParsed, $orgZone, $orgZone, $windowStart, $windowEnd, microtime(true) + 30.0);
@unlink($manyPath);

st_check(
    'I5 a calendar of 2500 one-off events gives ' . IcsReader::MAX_EVENTS_PER_FEED . ' dates',
    count($manyExpanded['occurrences']) === IcsReader::MAX_EVENTS_PER_FEED,
    'got ' . count($manyExpanded['occurrences'])
);
st_check('I5 and is reported as cut short', $manyExpanded['capped'] === true);
// The expected moment is worked out here with plain date arithmetic, NOT with
// the reader, so a reader that got its own sums wrong cannot agree with itself.
$expectedCutOff = $manyFirst->modify('+' . (IcsReader::MAX_EVENTS_PER_FEED - 1) . ' hours')
    ->setTimezone($orgZone)->format('Y-m-d H:i:s');
st_check(
    'I5 and the end of what was read is the ' . IcsReader::MAX_EVENTS_PER_FEED . 'th date (' . $expectedCutOff . ')',
    $manyExpanded['effectiveWindowEnd'] === $expectedCutOff,
    var_export($manyExpanded['effectiveWindowEnd'], true)
);
st_check('I5 the throwaway file was removed afterwards', is_file($manyPath) === false, $manyPath);
// These checks build deliberately large calendars, and this script has to go on
// working afterwards. Letting each one go as soon as its checks are done keeps
// the whole run well inside an ordinary memory limit - and the reader being
// tested here stops early when memory runs short, so a script that quietly held
// on to everything it had built could make its own later checks fail.
unset($manyBody, $manyParsed, $manyExpanded);

// I6: the time budget. A deadline already in the past must stop the work with
// a clear exception, not carry on regardless.
$budgetThrew = false;
try {
    IcsReader::expand($parsedDaily, $orgZone, $orgZone, $windowStart, $windowEnd, microtime(true) - 1.0);
} catch (RuntimeException $e) {
    $budgetThrew = ($e->getMessage() === 'time budget');
}
st_check('I6 a deadline that has already passed stops the work with RuntimeException(\'time budget\')', $budgetThrew === true);

$r = st_read('truncated-no-end.ics');
st_check('I7 truncated-no-end.ics: a file cut off half way is reported as NOT complete', $r['parse']['complete'] === false);
st_check(
    'I7 what did arrive is still handed back, so a refresh can decide for itself',
    count($r['expand']['occurrences']) === 1,
    'got ' . count($r['expand']['occurrences'])
);

// I8: the limit on how many events are HELD from one file. Running out of
// memory in PHP is a fatal error - it cannot be caught, so an importer
// refreshing several calendars would stop dead and leave the rest of them
// unrefreshed, with nothing in the log to say why. This is the first of the
// three guards against that.
$blockIcs   = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
$blockFirst = new DateTimeImmutable('2026-09-02 00:00:00', new DateTimeZone('UTC'));
for ($i = 0; $i < IcsReader::MAX_EVENT_BLOCKS_PER_FILE + 500; $i++) {
    $moment    = $blockFirst->modify('+' . $i . ' hours');
    $blockIcs .= "BEGIN:VEVENT\r\nUID:block-" . $i . "@fixtures.webms.test\r\n"
        . 'DTSTART:' . $moment->format('Ymd\THis\Z') . "\r\nSUMMARY:Event " . $i . "\r\nEND:VEVENT\r\n";
}
$blockIcs .= "END:VCALENDAR\r\n";
$blockParsed = IcsReader::parse($blockIcs, microtime(true) + 30.0);
st_check(
    'I8 a file holding more than ' . IcsReader::MAX_EVENT_BLOCKS_PER_FILE . ' events stops at that many',
    count($blockParsed['events']) === IcsReader::MAX_EVENT_BLOCKS_PER_FILE,
    'got ' . count($blockParsed['events'])
);
st_check(
    'I8 and says so, in words that name what happened',
    st_has_warning($blockParsed['warnings'], 'more events than the portal reads from one file') === true,
    implode(' | ', $blockParsed['warnings'])
);
st_check(
    'I8 and is NOT reported as a whole calendar, so an importer will not delete anything for being missing from it',
    $blockParsed['complete'] === false
);
st_check(
    'I8 and does NOT also claim the file failed to begin and end properly, which it did',
    st_has_warning($blockParsed['warnings'], 'did not begin and end as a whole calendar') === false,
    implode(' | ', $blockParsed['warnings'])
);
unset($blockIcs, $blockParsed);

// I9: the limit on how many dates are GATHERED before the list is cut to size.
// A 95 KB file of 500 never-ending daily series asks for 200,000 dates to be
// built and sorted so that 2,000 can be kept, and that alone used up more than
// PHP's usual memory limit - a fatal error, from a file small enough to arrive
// in a second.
//
// This one is read in THIS process, unlike I10 and I12, because it also checks
// the exact words of the warning and that no end-of-reading point is reported,
// and a separate process can only hand back counts. What that costs, measured:
// if BOTH gathering guards were ever removed this script would die here with
// "Allowed memory size ... exhausted" and an exit code of 255, instead of
// printing FAIL — so the run still fails by its exit code, but the checks after
// this one would not be reached and the reason would be much harder to see.
//
// WHERE THAT EXIT CODE IS ACTUALLY READ, said plainly because this comment once
// claimed "the exit code is what CI reads, so a regression is never missed" when
// nothing in `.github/` ran this script — it was a manual gate, run by hand
// before a commit. Since 25 September 2026,
// `.github/workflows/calendar-selftests.yml` runs it on every pull request to
// alpha, beta or main (owner's decision of 23 September 2026), and a non-zero
// exit turns that check red. It is still worth running by hand before a
// commit, because a pull request is the last moment to find out, not the
// first.
$manySeriesIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 500; $i++) {
    $manySeriesIcs .= "BEGIN:VEVENT\r\nUID:endless-" . $i . "@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T080000\r\nDTEND;TZID=Europe/London:20260901T090000\r\n"
        . "RRULE:FREQ=DAILY\r\nSUMMARY:Never-ending " . $i . "\r\nEND:VEVENT\r\n";
}
$manySeriesIcs .= "END:VCALENDAR\r\n";
$manySeriesStarted  = microtime(true);
$manySeriesParsed   = IcsReader::parse($manySeriesIcs, microtime(true) + 30.0);
$manySeriesExpanded = IcsReader::expand(
    $manySeriesParsed,
    $orgZone,
    $orgZone,
    $windowStart,
    new DateTimeImmutable('2029-09-01 00:00:00', $orgZone),
    microtime(true) + 30.0
);
$manySeriesSeconds = microtime(true) - $manySeriesStarted;
st_check(
    'I9 500 never-ending daily series give ' . IcsReader::MAX_EVENTS_PER_FEED . ' dates, not 200,000',
    count($manySeriesExpanded['occurrences']) === IcsReader::MAX_EVENTS_PER_FEED,
    'got ' . count($manySeriesExpanded['occurrences'])
);
st_check('I9 and the answer is reported as cut short', $manySeriesExpanded['capped'] === true);
st_check(
    'I9 and says the gathering itself was stopped',
    st_has_warning($manySeriesExpanded['warnings'], 'far more dates in the period') === true,
    implode(' | ', $manySeriesExpanded['warnings'])
);
st_check(
    'I9 and reports NO end-of-reading point, because the dates were still in file order when it stopped',
    $manySeriesExpanded['effectiveWindowEnd'] === null,
    var_export($manySeriesExpanded['effectiveWindowEnd'], true)
);
st_check('I9 and it all happens in under five seconds', $manySeriesSeconds < 5.0, sprintf('%.3f seconds', $manySeriesSeconds));
unset($manySeriesIcs, $manySeriesParsed, $manySeriesExpanded);

// I10: the third guard, watching the memory this process has actually used.
// It can only be shown in a SEPARATE PHP process, because the point of it is
// what happens when memory runs short and there is no way to ask for that
// inside a process that must carry on afterwards. A child is given a tight
// 32 MB and a calendar far too big for it. Before these guards existed the
// child died with "Allowed memory size ... exhausted" and an exit code of 255;
// what must happen now is an ordinary answer and an exit code of 0.
$childBody = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 20000; $i++) {
    $moment     = $blockFirst->modify('+' . $i . ' hours');
    $childBody .= "BEGIN:VEVENT\r\nUID:memory-" . $i . "@fixtures.webms.test\r\n"
        . 'DTSTART:' . $moment->format('Ymd\THis\Z') . "\r\n"
        . 'DTEND:' . $moment->modify('+30 minutes')->format('Ymd\THis\Z') . "\r\n"
        . "SUMMARY:Event " . $i . " with a title long enough to be realistic\r\nEND:VEVENT\r\n";
}
$childBody .= "END:VCALENDAR\r\n";
$child = st_in_child($childBody, '32M', '2029-09-01 00:00:00');
unset($childBody);
st_check(
    'I10 a 20,000-event calendar read in a process allowed only 32 MB finishes normally (exit code 0)',
    $child['status'] === 0,
    'exit code ' . $child['status'] . ': ' . $child['text']
);
st_check(
    'I10 and it really did the work rather than being stopped some other way',
    str_contains($child['text'], 'CHILD-FINISHED') === true,
    $child['text']
);
st_check(
    'I10 and nothing about memory was written to the output',
    str_contains($child['text'], 'Allowed memory size') === false,
    $child['text']
);
st_check('I10 the two throwaway files were removed afterwards', $child['leftOver'] === false);

// I8b: the EXACT edge of the event limit, from both sides. A file holding
// exactly the limit's worth of events has had nothing left out, so saying "the
// rest were left out" would be untrue and an administrator who read it would go
// looking for events that were never missing. One event more, and the warning
// is earned. (The same mistake was found and fixed at the other limit, the one
// on dates in a single series — see I4b.)
$blockEdge = static function (int $count): array {
    $ics   = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    $first = new DateTimeImmutable('2026-09-02 00:00:00', new DateTimeZone('UTC'));
    for ($i = 0; $i < $count; $i++) {
        $moment = $first->modify('+' . $i . ' minutes');
        $ics   .= "BEGIN:VEVENT\r\nUID:block-edge-" . $i . "@fixtures.webms.test\r\n"
            . 'DTSTART:' . $moment->format('Ymd\THis\Z') . "\r\nSUMMARY:Event " . $i . "\r\nEND:VEVENT\r\n";
    }
    $ics .= "END:VCALENDAR\r\n";

    return IcsReader::parse($ics, microtime(true) + 30.0);
};
$edgeExact = $blockEdge(IcsReader::MAX_EVENT_BLOCKS_PER_FILE);
st_check(
    'I8b a file holding exactly ' . IcsReader::MAX_EVENT_BLOCKS_PER_FILE . ' events keeps all of them',
    count($edgeExact['events']) === IcsReader::MAX_EVENT_BLOCKS_PER_FILE,
    'got ' . count($edgeExact['events'])
);
st_check('I8b and is reported as a WHOLE calendar, because nothing was left out', $edgeExact['complete'] === true);
st_check('I8b and says nothing about a rest that was left out', $edgeExact['warnings'] === [], implode(' | ', $edgeExact['warnings']));
$edgeOneMore = $blockEdge(IcsReader::MAX_EVENT_BLOCKS_PER_FILE + 1);
st_check(
    'I8b one event more, and ' . IcsReader::MAX_EVENT_BLOCKS_PER_FILE . ' are kept',
    count($edgeOneMore['events']) === IcsReader::MAX_EVENT_BLOCKS_PER_FILE,
    'got ' . count($edgeOneMore['events'])
);
st_check('I8b and THAT is not a whole calendar', $edgeOneMore['complete'] === false);
st_check(
    'I8b with the warning that says so',
    st_has_warning($edgeOneMore['warnings'], 'more events than the portal reads from one file') === true,
    implode(' | ', $edgeOneMore['warnings'])
);
unset($edgeExact, $edgeOneMore);

// I11: the limit on how many LINES are read. A calendar file is turned into a
// list of lines before anything else looks at it, and a list costs memory per
// ENTRY as well as per character — so a file of nothing but line endings is
// tiny on disk and enormous in memory. Four megabytes of them made PHP ask the
// operating system for 128 MB in a single go and die with a fatal error, before
// any other check in the reader had run even once.
$newlineBody   = str_repeat("\n", IcsReader::MAX_LINES_PER_FILE + 5000);
$newlineParsed = IcsReader::parse($newlineBody, microtime(true) + 30.0);
st_check(
    'I11 a file of nothing but line endings is stopped by the line limit rather than filling memory',
    st_has_warning($newlineParsed['warnings'], 'more lines than the portal reads from one file') === true,
    implode(' | ', $newlineParsed['warnings'])
);
st_check('I11 and it gives no events and is NOT reported as a whole calendar', $newlineParsed['events'] === [] && $newlineParsed['complete'] === false);
// The control matters as much as the check: an ordinary calendar must not be
// touched by this limit, or every real feed would come back cut short.
$shortParsed = IcsReader::parse(str_repeat("\n", 5000)
    . "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:short@fixtures.webms.test\r\n"
    . "DTSTART:20261004T120000Z\r\nSUMMARY:Short\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", microtime(true) + 30.0);
st_check(
    'I11 control: a calendar of a few thousand lines is untouched by that limit',
    count($shortParsed['events']) === 1 && $shortParsed['complete'] === true && $shortParsed['warnings'] === [],
    implode(' | ', $shortParsed['warnings'])
);
unset($newlineBody, $newlineParsed, $shortParsed);

// I12: many never-ending series that all share ONE UID. This is the shape that
// showed the date-gathering guard was being checked in the wrong place: it was
// checked once for each UID, and a calendar chooses its own UIDs, so giving
// every series the same one meant it was never checked between the first series
// and the last. A 31 KB file of exactly this shape used up every byte PHP
// allows — a fatal error, which cannot be caught, from a file that arrives in
// well under a second.
$sameUidIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 250; $i++) {
    $sameUidIcs .= "BEGIN:VEVENT\r\nUID:one-uid-for-all@fixtures.webms.test\r\n"
        . 'DTSTART;TZID=Europe/London:2026090' . (($i % 9) + 1) . "T080000\r\n"
        . 'DTEND;TZID=Europe/London:2026090' . (($i % 9) + 1) . "T090000\r\n"
        . "RRULE:FREQ=DAILY\r\nSUMMARY:Never-ending " . $i . "\r\nEND:VEVENT\r\n";
}
$sameUidIcs .= "END:VCALENDAR\r\n";
// Read in a SEPARATE process, for the same reason I10 is: without the guard
// this file exhausts memory, which is a fatal error. Run here, it would kill
// this script outright — so a regression would not print FAIL, it would take
// every check after this one down with it, unreported.
$sameUidStarted = microtime(true);
$child          = st_in_child($sameUidIcs, '128M', '2029-09-01 00:00:00');
$sameUidSeconds = microtime(true) - $sameUidStarted;
st_check(
    'I12 250 never-ending series that all share ONE UID are read without running out of memory (exit code 0)',
    $child['status'] === 0,
    'exit code ' . $child['status'] . ': ' . $child['text']
);
st_check(
    'I12 and the answer is cut short rather than complete — at most ' . IcsReader::MAX_EVENTS_PER_FEED . ' dates, and capped',
    preg_match('/dates=(\d+) capped=true/', $child['text'], $sameUidMatch) === 1
    && (int) $sameUidMatch[1] <= IcsReader::MAX_EVENTS_PER_FEED,
    $child['text']
);
st_check(
    'I12 and nothing about memory was written to the output',
    str_contains($child['text'], 'Allowed memory size') === false,
    $child['text']
);
st_check('I12 and it all happens in under fifteen seconds', $sameUidSeconds < 15.0, sprintf('%.3f seconds', $sameUidSeconds));
unset($sameUidIcs);

// I13: ONE event carrying a very long list of added dates (RDATE). The limit on
// how many dates one event may contribute used to live inside the repeat-rule
// worker only, and dates added by hand never go through it — so a single event
// with a hundred thousand RDATE lines produced a hundred thousand dates and
// killed the process, from a 1.6 MB file. Two things are checked: the list
// itself stops being read, and the dates the event contributes stop at the
// per-event limit.
$rdateIcs  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:rdate-flood@fixtures.webms.test\r\n"
    . "DTSTART:20260902T000000Z\r\nDTEND:20260902T003000Z\r\nSUMMARY:Very many added dates\r\n";
$rdateBase = new DateTimeImmutable('2026-09-02 00:00:00', new DateTimeZone('UTC'));
for ($i = 0; $i < IcsReader::MAX_DATE_LIST_PER_EVENT * 3; $i++) {
    $rdateIcs .= 'RDATE:' . $rdateBase->modify('+' . ($i * 5) . ' minutes')->format('Ymd\THis\Z') . "\r\n";
}
$rdateIcs .= "END:VEVENT\r\nEND:VCALENDAR\r\n";
$rdateParsed   = IcsReader::parse($rdateIcs, microtime(true) + 30.0);
$rdateExpanded = IcsReader::expand($rdateParsed, $orgZone, $orgZone, $windowStart, $windowEnd, microtime(true) + 30.0);
st_check(
    'I13 one event with ' . (IcsReader::MAX_DATE_LIST_PER_EVENT * 3) . ' added dates contributes at most '
    . IcsReader::MAX_OCCURRENCES_PER_SERIES . ' of them',
    count($rdateExpanded['occurrences']) <= IcsReader::MAX_OCCURRENCES_PER_SERIES,
    'got ' . count($rdateExpanded['occurrences'])
);
st_check('I13 and the answer is reported as cut short', $rdateExpanded['capped'] === true);
st_check(
    'I13 and says the list of added dates was itself cut short',
    st_has_warning($rdateExpanded['warnings'], 'more skipped or added dates than the portal reads') === true,
    implode(' | ', $rdateExpanded['warnings'])
);
// The control again: an ordinary handful of added dates must not trip either
// limit. C5 checks the dates themselves; this checks that no warning appears.
$rdateSmall = st_read('rdate.ics');
st_check(
    'I13 control: an ordinary RDATE list is not reported as cut short and raises no warning',
    $rdateSmall['expand']['capped'] === false && $rdateSmall['expand']['warnings'] === [],
    implode(' | ', $rdateSmall['expand']['warnings'])
);
unset($rdateIcs, $rdateParsed, $rdateExpanded);

// I14: a repeat rule whose BY… list is longer than it could possibly be. There
// are only twelve months, sixty-two days of the month counting from both ends,
// and 7 × 107 ways to name a weekday with a number in front of it — so a longer
// list is repetition, and repetition is what makes it dangerous:
// `BYDAY=MO,MO,MO,…` three hundred thousand times is a 900 KB file that used up
// all the memory PHP allows inside the rule reader itself, before a single date
// had been worked out. The list is measured before it is split apart, so
// refusing it costs nothing.
foreach ([
    'BYMONTH'    => 'FREQ=YEARLY;BYMONTH=' . implode(',', array_fill(0, 200, '1')),
    'BYMONTHDAY' => 'FREQ=MONTHLY;BYMONTHDAY=' . implode(',', array_fill(0, 200, '1')),
    'BYDAY'      => 'FREQ=MONTHLY;BYDAY=' . implode(',', array_fill(0, 2000, 'MO')),
    'BYSETPOS'   => 'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=' . implode(',', array_fill(0, 2000, '1')),
] as $partName => $longRule) {
    $r = st_inline(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:long-list@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260907T080000\r\nDTEND;TZID=Europe/London:20260907T090000\r\n"
        . 'RRULE:' . $longRule . "\r\nSUMMARY:Very long list\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    st_lines(
        'I14 a ' . $partName . ' list longer than it could possibly be gives the first date only',
        ['2026-09-07 08:00:00|2026-09-07 09:00:00|timed|key=20260907T070000Z|series|priv=no|canc=no|dup=no|Very long list'],
        st_lines_of($r['expand']['occurrences'])
    );
    st_check(
        'I14 ' . $partName . ': and says so in a warning, naming the part',
        st_has_warning($r['expand']['warnings'], 'gives ' . $partName . ' more values than it can possibly use') === true,
        implode(' | ', $r['expand']['warnings'])
    );
}
// The control: a list as long as it is ALLOWED to be is still read normally.
// Without this, the limits could be set to 1 and every one of the checks above
// would still pass.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:full-list@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260907T080000\r\nDTEND;TZID=Europe/London:20260907T090000\r\n"
    . 'RRULE:FREQ=YEARLY;BYMONTH=' . implode(',', range(1, 12)) . ";COUNT=12\r\n"
    . "SUMMARY:Every month named\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I14 control: all twelve months named is read normally — twelve dates, no warning',
    count($r['expand']['occurrences']) === 12 && $r['expand']['warnings'] === [],
    'got ' . count($r['expand']['occurrences']) . ' date(s); ' . implode(' | ', $r['expand']['warnings'])
);

// I15: the six places a single over-long LINE used to be turned into an array
// before anything had counted how many pieces it would make.
//
// Every one of these is one line inside a file no bigger than the 5,242,880
// bytes the fetcher allows, and every one of them used to end the whole
// process with "Allowed memory size ... exhausted" — a fatal error PHP cannot
// catch, so an importer refreshing several calendars stopped dead and left
// every calendar after it unrefreshed with nothing in the log.
//
// They are read in a SEPARATE process for exactly that reason: without the
// guard being checked, the child dies and this script prints an ordinary FAIL
// instead of dying itself and taking every later check down unreported.
//
// Each has a CONTROL beside it. Without the controls, every limit here could
// be set to 1 and all of these checks would still pass.
//
// `$eventHead` stops PART WAY THROUGH a line on purpose: the separators go in
// between it and `$eventTail`, so the enormous run of commas or semicolons is
// inside ONE line rather than after the end of the file.
$eventHead = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:oversize@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T090000\r\nDTEND;TZID=Europe/London:20260901T100000\r\n"
    . "SUMMARY:One enormous line\r\n";
$eventTail = "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
/** As many copies of $unit as fit, so the whole file is at or under the fetcher's 5,242,880-byte limit. */
$fillTo = static function (string $before, string $unit, string $after): string {
    $room = 5242880 - strlen($before) - strlen($after);

    return $before . str_repeat($unit, intdiv($room, strlen($unit))) . $after;
};

// I15a: a property line whose name-and-settings part is nothing but
// semicolons. This one is in parse(), where no per-event limit applies and the
// memory watch runs only every five hundred lines — so a file of a handful of
// such lines went straight past every guard there was.
$child = st_in_child(
    $fillTo("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nX", ';', ":1\r\nEND:VCALENDAR\r\n"),
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I15a a 5 MB property line of nothing but semicolons finishes normally (exit code 0)',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I15a and the line is left out with a warning that says so',
    str_contains($child['text'], 'carried more settings than the portal reads from one line') === true,
    substr($child['text'], 0, 300)
);
st_check(
    'I15a and the read is NOT reported as a whole calendar, because a line was dropped',
    str_contains($child['text'], 'complete=false') === true,
    substr($child['text'], 0, 200)
);
// I15a control: a line carrying as many settings as it is ALLOWED to carry is
// read completely normally.
$manySettings = 'DTSTART;VALUE=DATE-TIME;TZID=Europe/London'
    . str_repeat(';X-SOMETHING=1', IcsReader::MAX_PARAMS_PER_PROPERTY - 2) . ':20260901T090000';
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:settings@fixtures.webms.test\r\n"
    . $manySettings . "\r\nSUMMARY:Many settings\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I15a control: a line with exactly ' . IcsReader::MAX_PARAMS_PER_PROPERTY
    . ' settings is read normally — one date, whole calendar, no warning',
    count($r['expand']['occurrences']) === 1 && $r['parse']['complete'] === true
    && $r['expand']['warnings'] === [] && $r['parse']['warnings'] === [],
    'got ' . count($r['expand']['occurrences']) . ' date(s); complete='
    . var_export($r['parse']['complete'], true) . '; '
    . implode(' | ', array_merge($r['parse']['warnings'], $r['expand']['warnings']))
);

// I15b: a repeat rule made of millions of parts. The per-part limits checked
// in I14 count the commas INSIDE one BY… value and never see the semicolons
// BETWEEN the parts, so this went straight past them.
$child = st_in_child($fillTo($eventHead . 'RRULE:FREQ=DAILY', ';', $eventTail), '128M', '2027-09-01 00:00:00');
st_check(
    'I15b a 5 MB repeat rule of nothing but semicolons finishes normally (exit code 0)',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I15b and the series is imported as its first date only, with a warning that says why',
    str_contains($child['text'], 'dates=1') === true
    && str_contains($child['text'], 'made of more parts than this portal works out') === true,
    substr($child['text'], 0, 300)
);
// I15b control: a rule using many LAWFUL parts at once is still worked out.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:fullrule@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260907T080000\r\nDTEND;TZID=Europe/London:20260907T090000\r\n"
    . "RRULE:FREQ=MONTHLY;INTERVAL=1;COUNT=12;BYDAY=1MO,3MO;BYMONTH=1,2,3,4,5,6,7,8,9,10,11,12;"
    . "BYSETPOS=1;WKST=SU\r\nSUMMARY:A rule of many parts\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I15b control: a lawful rule of eight parts is worked out normally, with no warning',
    count($r['expand']['occurrences']) === 12 && $r['expand']['warnings'] === [],
    'got ' . count($r['expand']['occurrences']) . ' date(s); ' . implode(' | ', $r['expand']['warnings'])
);

// I15c, I15d, I15e: the comma splitter itself, reached through each of the
// three properties that carry a comma-separated list. It used to build one
// piece of text per comma and hand the whole lot back; its callers then
// counted as they walked the result, by which time the memory was gone.
foreach ([
    'I15c EXDATE'     => ['EXDATE:', 'skipped or added dates', true],
    'I15d RDATE'      => ['RDATE:', 'skipped or added dates', true],
    'I15e CATEGORIES' => ['CATEGORIES:', 'more categories than the portal reads', false],
] as $label => $case) {
    [$property, $needle, $expectCapped] = $case;
    $child = st_in_child($fillTo($eventHead . $property, ',', $eventTail), '128M', '2027-09-01 00:00:00');
    st_check(
        $label . ': 5 MB of nothing but commas finishes normally (exit code 0)',
        $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
        'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
    );
    st_check(
        $label . ': and says in a warning that the later entries were left out',
        str_contains($child['text'], $needle) === true,
        substr($child['text'], 0, 300)
    );
    if ($expectCapped === true) {
        // A cut list of skipped or added dates can bring a deleted date back,
        // so the answer must be marked as not the whole picture. A cut list of
        // CATEGORIES cannot, and deliberately is not: `capped` means "dates
        // may be missing, do not delete anything", which would be untrue here.
        st_check(
            $label . ': and the answer is marked as not the whole picture',
            str_contains($child['text'], 'capped=true') === true,
            substr($child['text'], 0, 200)
        );
    }
}
// I15c/d control, and the EXACT edge of the limit from both sides — the same
// off-by-one that was found at the event limit (I8b) and the per-series date
// limit (I4b). A list holding exactly the allowance has had nothing left out,
// so saying "the later ones were left out" would send an administrator looking
// for dates that were never missing.
$exdateEdge = static function (int $count): array {
    $first = new DateTimeImmutable('2020-01-01 09:00:00', new DateTimeZone('Europe/London'));
    $dates = [];
    for ($i = 0; $i < $count; $i++) {
        $dates[] = $first->modify('+' . $i . ' days')->format('Ymd\THis');
    }

    return st_inline(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:exdate-edge@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T090000\r\nDTEND;TZID=Europe/London:20260901T100000\r\n"
        . "RRULE:FREQ=DAILY;COUNT=5\r\nEXDATE;TZID=Europe/London:" . implode(',', $dates) . "\r\n"
        . "SUMMARY:Long but lawful\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
};
$edgeExactly = $exdateEdge(IcsReader::MAX_DATE_LIST_PER_EVENT);
st_check(
    'I15c control: exactly ' . IcsReader::MAX_DATE_LIST_PER_EVENT
    . ' skipped dates are all read, and nothing is reported as left out',
    $edgeExactly['expand']['capped'] === false && $edgeExactly['expand']['warnings'] === [],
    'capped=' . var_export($edgeExactly['expand']['capped'], true) . '; '
    . implode(' | ', $edgeExactly['expand']['warnings'])
);
$edgeOneMore = $exdateEdge(IcsReader::MAX_DATE_LIST_PER_EVENT + 1);
st_check(
    'I15c and one skipped date more IS reported as cut short, with the warning that says so',
    $edgeOneMore['expand']['capped'] === true
    && st_has_warning($edgeOneMore['expand']['warnings'], 'more skipped or added dates than the portal reads') === true,
    'capped=' . var_export($edgeOneMore['expand']['capped'], true) . '; '
    . implode(' | ', $edgeOneMore['expand']['warnings'])
);
unset($edgeExactly, $edgeOneMore);
// The same limit, reached by REPEATING one date rather than by listing many
// different ones. This is the shape the limit used to miss completely: it
// counted the skipped dates KEPT, and repeats collapse into one, so a list of
// the same date five million times never reached it.
$repeated = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:exdate-repeat@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T090000\r\nDTEND;TZID=Europe/London:20260901T100000\r\n"
    . "RRULE:FREQ=DAILY;COUNT=5\r\nEXDATE;TZID=Europe/London:"
    . implode(',', array_fill(0, IcsReader::MAX_DATE_LIST_PER_EVENT + 1, '20200101T090000')) . "\r\n"
    . "SUMMARY:The same skipped date over and over\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I15c the same skipped date repeated past the limit is cut short too, not read to the end',
    $repeated['expand']['capped'] === true
    && st_has_warning($repeated['expand']['warnings'], 'more skipped or added dates than the portal reads') === true,
    'capped=' . var_export($repeated['expand']['capped'], true) . '; '
    . implode(' | ', $repeated['expand']['warnings'])
);
unset($repeated);
// The allowance is for the EVENT, not for each line of it. A calendar may
// spread its skipped dates over as many EXDATE lines as it likes, so the count
// has to carry across them — otherwise four lines of a thousand dates each
// would read four thousand and a fifth line would read a thousand more.
$spreadOver = static function (int $lines, int $perLine): array {
    $first = new DateTimeImmutable('2020-01-01 09:00:00', new DateTimeZone('Europe/London'));
    $ics   = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:exdate-spread@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T090000\r\nDTEND;TZID=Europe/London:20260901T100000\r\n"
        . "RRULE:FREQ=DAILY;COUNT=5\r\n";
    $made = 0;
    for ($line = 0; $line < $lines; $line++) {
        $dates = [];
        for ($i = 0; $i < $perLine; $i++) {
            $dates[] = $first->modify('+' . $made . ' days')->format('Ymd\THis');
            $made++;
        }
        $ics .= 'EXDATE;TZID=Europe/London:' . implode(',', $dates) . "\r\n";
    }

    return st_inline($ics . "SUMMARY:Spread over lines\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
};
$spreadExactly = $spreadOver(4, intdiv(IcsReader::MAX_DATE_LIST_PER_EVENT, 4));
st_check(
    'I15c control: ' . IcsReader::MAX_DATE_LIST_PER_EVENT . ' skipped dates spread over four lines '
    . 'are all read, and nothing is reported as left out',
    $spreadExactly['expand']['capped'] === false && $spreadExactly['expand']['warnings'] === [],
    'capped=' . var_export($spreadExactly['expand']['capped'], true) . '; '
    . implode(' | ', $spreadExactly['expand']['warnings'])
);
$spreadOneMore = $spreadOver(5, intdiv(IcsReader::MAX_DATE_LIST_PER_EVENT, 4));
st_check(
    'I15c and a fifth line IS reported as cut short, because the allowance is for the event',
    $spreadOneMore['expand']['capped'] === true
    && st_has_warning($spreadOneMore['expand']['warnings'], 'more skipped or added dates than the portal reads') === true,
    'capped=' . var_export($spreadOneMore['expand']['capped'], true) . '; '
    . implode(' | ', $spreadOneMore['expand']['warnings'])
);
unset($spreadExactly, $spreadOneMore);
// I15e control: an ordinary run of categories is untouched by the new limit.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:cats@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T090000\r\nCATEGORIES:"
    . implode(',', array_map(static fn (int $i): string => 'Category ' . $i, range(1, 30)))
    . "\r\nSUMMARY:Thirty categories\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I15e control: thirty categories are read with no warning, and the first '
    . IcsReader::MAX_CATEGORIES_PER_EVENT . ' are kept',
    $r['expand']['warnings'] === []
    && count($r['expand']['occurrences'][0]['categories']) === IcsReader::MAX_CATEGORIES_PER_EVENT,
    'kept ' . count($r['expand']['occurrences'][0]['categories']) . '; '
    . implode(' | ', $r['expand']['warnings'])
);

// I15f: a time-zone name made of millions of slash-separated parts. An
// unrecognised name containing slashes is split on them so that the longest
// ending which IS a real zone can be tried, and that split ran before anything
// counted the slashes. Behind the memory cost sat a second one: the search
// tried every possible ending in turn, which is work proportional to the
// SQUARE of the number of parts.
$child = st_in_child(
    $fillTo(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:zone@fixtures.webms.test\r\nDTSTART;TZID=a",
        '/b',
        ":20260901T090000\r\nSUMMARY:Enormous zone name\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    ),
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I15f a 5 MB time-zone name finishes normally (exit code 0)',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I15f and the calendar\'s own zone is used, with a warning that does not quote the name',
    str_contains($child['text'], 'far longer than any real one') === true
    && str_contains($child['text'], 'a/b/b/b') === false,
    substr($child['text'], 0, 300)
);
// I15f control: the longest shapes a REAL calendar writes still work. Without
// this, the length could be set to 1 and the check above would still pass.
foreach ([
    'Europe/London'                                  => 'an ordinary name',
    'America/Argentina/Buenos_Aires'                  => 'a three-part name',
    '/mozilla.org/20050126_1/Europe/London'           => 'the form Thunderbird writes',
] as $zoneName => $describedAs) {
    $r = st_inline(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:zone-ok@fixtures.webms.test\r\n"
        . 'DTSTART;TZID=' . $zoneName . ":20260901T090000\r\nSUMMARY:Known zone\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    st_check(
        'I15f control: ' . $describedAs . ' (' . $zoneName . ') is still recognised, with no warning',
        $r['expand']['warnings'] === [],
        implode(' | ', $r['expand']['warnings'])
    );
}

// I15g: the list of WARNINGS is itself something the file decides the length
// of, and it hid the longest, because nothing about it looks like reading a
// calendar. An unknown zone is recorded once for every skipped date in a
// series, so one event with a made-up zone name and five and a quarter million
// skipped dates built 327,680 copies of ONE sentence and reached 114 MB of the
// 128 MB PHP usually allows. There WAS a de-duplication — at the very end of
// expand(), long after the memory had been taken.
//
// Two checks, because the fault has two halves. First, in this process:
// repeats must be dropped as they arrive, so the count stays tiny.
$floodWarnings = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:warnflood@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T090000\r\nDTEND;TZID=Europe/London:20260901T100000\r\n"
    . "RRULE:FREQ=DAILY;COUNT=3\r\nEXDATE;TZID=Made/Up/Zone/Nowhere:"
    . implode(',', array_fill(0, 3000, '20260901T090000')) . "\r\n"
    . "SUMMARY:One made-up zone, three thousand times\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
// Exactly one, and the number is deliberately exact rather than "not many".
// Three thousand is under the limit on how many skipped dates are read, so
// nothing else has anything to say about this calendar. Written as "at most
// MAX_WARNINGS" the check would still pass with the de-duplication removed,
// because the cap alone would hold it at a hundred — a hundred copies of one
// sentence, which is the fault this is here to catch.
st_check(
    'I15g three thousand identical warnings are recorded ONCE, not three thousand times',
    count($floodWarnings['expand']['warnings']) === 1,
    'got ' . count($floodWarnings['expand']['warnings']) . ' warning(s): '
    . implode(' | ', array_slice($floodWarnings['expand']['warnings'], 0, 3))
);
st_check(
    'I15g and the one that was kept still says what was wrong',
    st_has_warning($floodWarnings['expand']['warnings'], 'Unknown time zone') === true,
    implode(' | ', $floodWarnings['expand']['warnings'])
);
unset($floodWarnings);
// Second, in a child given far less memory than the old code needed: 5 MB of
// the same shape must finish inside 48 MB. Before the de-duplication moved to
// where the warning is recorded, this peaked at 114 MB.
$child = st_in_child(
    $fillTo(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:warnflood@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T090000\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
        . 'EXDATE;TZID=Made/Up/' . str_repeat('Zone_', 18) . 'End:',
        '20260901T090000,',
        "20260901T090000\r\nSUMMARY:Flood\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    ),
    '48M',
    '2027-09-01 00:00:00'
);
st_check(
    'I15g 5 MB of the same shape finishes in a process allowed only 48 MB (exit code 0)',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
// I15g control: DIFFERENT warnings are still all recorded, up to the limit,
// and the last line says the rest were left out. Without this the
// de-duplication could throw away everything and the checks above would pass.
$manyZones = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < IcsReader::MAX_WARNINGS + 50; $i++) {
    $manyZones .= "BEGIN:VEVENT\r\nUID:zone-$i@fixtures.webms.test\r\n"
        . 'DTSTART;TZID=Made/Up/Number' . $i . ":20260901T090000\r\nSUMMARY:Zone $i\r\nEND:VEVENT\r\n";
}
$manyZones .= "END:VCALENDAR\r\n";
$r = st_inline($manyZones);
st_check(
    'I15g control: ' . (IcsReader::MAX_WARNINGS + 50) . ' DIFFERENT warnings stop at '
    . IcsReader::MAX_WARNINGS . ', and are not all thrown away',
    count($r['expand']['warnings']) === IcsReader::MAX_WARNINGS,
    'got ' . count($r['expand']['warnings']) . ' warning(s)'
);
st_check(
    'I15g and the last line says the rest were left out, rather than looking like the last fault',
    st_has_warning($r['expand']['warnings'], 'more warnings than the portal records') === true,
    implode(' | ', array_slice($r['expand']['warnings'], -2))
);
unset($manyZones, $r);

// I16: the EXACT edge of the limit on how many lines are read, from both
// sides, with and without a line ending at the end of the file.
//
// A file that ends with a line ending has nothing after it, and that nothing
// used to be counted as a line: a file of exactly MAX_LINES_PER_FILE real
// lines was reported as cut short and the administrator was told "the rest
// were left out" when there was no rest. Without a final line ending the same
// file behaved correctly, which is what made it hard to see. This is the third
// place the same off-by-one had to be corrected — see I8b and I4b.
//
// Read in a SEPARATE process, and this is not a detail. Run here, these
// calendars trip the MEMORY guard instead of the line limit — not because
// anything is wrong with them, but because this script has already used most
// of the half of PHP's allowance that reading is permitted. Every check below
// then "passed" while measuring the wrong thing entirely, which is exactly the
// kind of check that is worse than none.
foreach ([
    IcsReader::MAX_LINES_PER_FILE - 1 => false,
    IcsReader::MAX_LINES_PER_FILE     => false,
    IcsReader::MAX_LINES_PER_FILE + 1 => true,
] as $lineCount => $shouldWarn) {
    $withEnding    = str_repeat("X:1\n", $lineCount);
    $withoutEnding = rtrim($withEnding, "\n");
    foreach ([
        'ending with a line ending'      => $withEnding,
        'with no line ending at the end' => $withoutEnding,
    ] as $how => $body) {
        $child = st_in_child($body, '128M', '2027-09-01 00:00:00');
        st_check(
            'I16 ' . $lineCount . ' lines, ' . $how . ': '
            . ($shouldWarn === true ? 'IS' : 'is NOT') . ' reported as having more lines than are read',
            $child['status'] === 0
            && str_contains($child['text'], 'more lines than the portal reads') === $shouldWarn,
            'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 250)
        );
        st_check(
            'I16 ' . $lineCount . ' lines, ' . $how . ': and nothing was stopped for memory instead',
            str_contains($child['text'], 'too large for the portal to hold all at once') === false,
            substr($child['text'], 0, 250)
        );
    }
    unset($withEnding, $withoutEnding, $child);
}

// -----------------------------------------------------------------------------
// I17 to I26 — THE SUM, THE CLOCK, AND FOUR GUARDS THAT HAD NO CHECK AT ALL
//
// A fourth round of independent checking found that every check above tests a
// limit on ONE thing — one line, one event — and nothing tested the SUM. It
// then killed the reader with two files inside the fetcher's 5 MB limit in
// which EVERY event stayed honestly inside every per-event limit. It also
// found that the time limit was counted in steps rather than in time, that two
// loops never looked at it at all, and that four guards could be deleted
// outright without a single check here noticing.
//
// The shapes that are FATAL without their guard are read in a SEPARATE
// process, for the same reason as I15: without the guard, the child dies and
// this script prints an ordinary FAIL instead of dying itself.
// -----------------------------------------------------------------------------

/** N events, each carrying `$pieces` dates on one line of `$property`, all far outside the window. */
$manyEventsWithList = static function (int $events, int $pieces, string $property): string {
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    for ($e = 0; $e < $events; $e++) {
        $dates = [];
        for ($i = 0; $i < $pieces; $i++) {
            // Spread over centuries so that no two collapse into one key and
            // none of them lands inside the window, which would bring other
            // limits into the answer and muddy what is being tested.
            $dates[] = sprintf('%04d%02d%02d', 1500 + intdiv(($i * 7) + $e, 300), 1 + (($i + $e) % 12), 1 + (($i + $e) % 28));
        }
        $out .= "BEGIN:VEVENT\r\nUID:sum-" . $e . "@fixtures.webms.test\r\n"
            . "DTSTART;VALUE=DATE:20260902\r\nSUMMARY:Sum " . $e . "\r\n"
            . $property . ';VALUE=DATE:' . implode(',', $dates) . "\r\nEND:VEVENT\r\n";
    }

    return $out . "END:VCALENDAR\r\n";
};

// I17: ADDED dates (RDATE) kept across every event.
//
// This is the shape that killed the reader. Ninety-nine events, each listing
// exactly MAX_DATE_LIST_PER_EVENT added dates — the per-event limit to the
// letter, not one over it — is a 3.57 MB file, and every one of those 9-byte
// pieces becomes a date object of about 388 bytes that is then held for the
// whole of expand(). That is 1.55 MB per event with nothing counting the
// total: "Allowed memory size ... exhausted", which cannot be caught.
$child = st_in_child(
    $manyEventsWithList(99, IcsReader::MAX_DATE_LIST_PER_EVENT, 'RDATE'),
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I17 99 events each with the full allowance of added dates finish normally (exit code 0)',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I17 and a warning says the calendar AS A WHOLE listed more than is held',
    str_contains($child['text'], 'events between them list more added dates') === true,
    substr($child['text'], 0, 400)
);
// This label used to say "…not that one event did" and then only checked that
// the file-wide warning was present, so it passed while BOTH warnings were
// raised — and one of them was untrue. Not one of these nine events is over
// the per-event limit: each lists EXACTLY the allowance. An administrator told
// "an event in this calendar lists more than the portal reads" would go
// looking for an event that does not exist.
st_check(
    'I17 and NOT the per-event one, because every one of these nine events is inside its own allowance',
    str_contains($child['text'], 'An event in this calendar lists more skipped or added dates') === false,
    substr($child['text'], 0, 400)
);
st_check(
    'I17 and the answer is marked capped, so nothing is deleted for being missing from it',
    str_contains($child['text'], 'capped=true') === true,
    substr($child['text'], 0, 200)
);
// I17 control: exactly the file-wide allowance, and not one piece more, is
// read with no file-wide warning at all. Without this control the limit could
// be set to 1 and the three checks above would still pass.
$r = st_inline($manyEventsWithList(30, intdiv(IcsReader::MAX_RETAINED_ADDED_DATES, 30), 'RDATE'));
st_check(
    'I17 control: exactly ' . IcsReader::MAX_RETAINED_ADDED_DATES
    . ' added dates across the file raise no file-wide warning',
    st_has_warning($r['expand']['warnings'], 'events between them list more added dates') === false,
    implode(' | ', $r['expand']['warnings'])
);
$r = st_inline($manyEventsWithList(30, intdiv(IcsReader::MAX_RETAINED_ADDED_DATES, 30) + 1, 'RDATE'));
st_check(
    'I17 control: one added date more than that IS reported',
    st_has_warning($r['expand']['warnings'], 'events between them list more added dates') === true,
    implode(' | ', $r['expand']['warnings'])
);

// I18: SKIPPED dates (EXDATE) kept across every event. Cheaper per entry than
// an added date (52 bytes against 388), which is exactly why its allowance is
// a different number rather than a shared one.
$perEvent = intdiv(IcsReader::MAX_RETAINED_SKIPPED_DATES, 50);
$r = st_inline($manyEventsWithList(50, $perEvent, 'EXDATE'));
st_check(
    'I18 control: exactly ' . IcsReader::MAX_RETAINED_SKIPPED_DATES
    . ' skipped dates across the file raise no file-wide warning',
    st_has_warning($r['expand']['warnings'], 'events between them list more added dates') === false,
    implode(' | ', $r['expand']['warnings'])
);
$r = st_inline($manyEventsWithList(50, $perEvent + 1, 'EXDATE'));
st_check(
    'I18 one skipped date more than that IS reported, and the answer is capped',
    st_has_warning($r['expand']['warnings'], 'events between them list more added dates') === true
    && $r['expand']['capped'] === true,
    'capped=' . var_export($r['expand']['capped'], true) . '; ' . implode(' | ', $r['expand']['warnings'])
);
unset($perEvent);

// I19: repeat-rule BY... values kept across every event.
//
// Seven hundred events, each naming the 749 DIFFERENT weekday values that
// BYDAY can possibly hold — every one of them lawful, and each value a small
// array of about 357 bytes — is a 2.86 MB file that used every byte PHP
// allows. The start is deliberately after the end of the window, so the rule
// is read and kept but produces no dates: this check is about what is HELD,
// not about what is worked out.
$bydayValues = static function (int $howMany): string {
    $out  = [];
    $days = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    foreach ($days as $day) {
        $out[] = $day;
    }
    for ($ordinal = 1; $ordinal <= 53 && count($out) < $howMany; $ordinal++) {
        foreach ($days as $day) {
            $out[] = $ordinal . $day;
        }
    }
    for ($ordinal = -53; $ordinal <= -1 && count($out) < $howMany; $ordinal++) {
        foreach ($days as $day) {
            $out[] = $ordinal . $day;
        }
    }

    return implode(',', array_slice($out, 0, $howMany));
};
$manyRules = static function (int $events, int $valuesEach) use ($bydayValues): string {
    $list = $bydayValues($valuesEach);
    $out  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    for ($e = 0; $e < $events; $e++) {
        $out .= "BEGIN:VEVENT\r\nUID:rules-" . $e . "@fixtures.webms.test\r\n"
            . "DTSTART;TZID=Europe/London:20281002T090000\r\nSUMMARY:Rules " . $e . "\r\n"
            . 'RRULE:FREQ=WEEKLY;BYDAY=' . $list . "\r\nEND:VEVENT\r\n";
    }

    return $out . "END:VCALENDAR\r\n";
};
$child = st_in_child($manyRules(700, 749), '128M', '2027-09-01 00:00:00');
st_check(
    'I19 700 events each naming all 749 different BYDAY values finish normally (exit code 0)',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I19 and the later rules are refused with a warning naming the file-wide allowance',
    str_contains($child['text'], 'repeat rules together list more values than the portal holds') === true,
    substr($child['text'], 0, 400)
);
// I19 control: exactly the allowance, spread over a hundred events, is kept in
// full and no rule is refused.
$r = st_inline($manyRules(100, intdiv(IcsReader::MAX_RETAINED_RULE_VALUES, 100)));
st_check(
    'I19 control: exactly ' . IcsReader::MAX_RETAINED_RULE_VALUES
    . ' repeat-rule values across the file are all kept',
    st_has_warning($r['expand']['warnings'], 'repeat rules together list more values') === false,
    implode(' | ', $r['expand']['warnings'])
);
$r = st_inline($manyRules(101, intdiv(IcsReader::MAX_RETAINED_RULE_VALUES, 100)));
st_check(
    'I19 control: one eventful rule more than that IS refused',
    st_has_warning($r['expand']['warnings'], 'repeat rules together list more values') === true,
    implode(' | ', $r['expand']['warnings'])
);
// And the answer must be CAPPED, which is a separate claim from the warning
// and is the one the importer acts on. Until a fifth round of checking, one
// line — the one that turns a file-wide cut into `capped` — could be deleted
// and every check here still passed, because the skipped/added-date paths set
// `capped` by another route and hid it. The repeat-rule budget has no such
// other route: with that line gone this calendar came back `capped = false`,
// and the importer would then have deleted every date these series used to
// contribute, for looking like dates that had gone from the feed.
st_check(
    'I19 control: and the answer is marked capped, so nothing is deleted on the strength of it',
    $r['expand']['capped'] === true,
    'capped=' . var_export($r['expand']['capped'], true)
);

// I20: the memory watch in the FIRST loop of expand().
//
// This guard had no check of any kind: it could be deleted outright and every
// one of the 227 checks still passed. It is also the guard that used to run
// only when the event number divided by a hundred, with a comment claiming a
// hundred events was a small enough step — while a hundred events were
// measured growing by up to 155 MB against a margin of 25.6 MB.
//
// Six thousand events, each with a modest list of skipped dates and a start far
// outside the window, so no date is ever worked out and the growth is all in
// the first loop.
//
// Read at 96 MB rather than PHP's usual 128 MB, and the number is chosen from
// measurement rather than picked. At 128 MB the SECOND loop's own guard catches
// this file first, so deleting the first loop's guard changes nothing anybody
// can see — which is how it came to have no check at all. At 96 MB the first
// loop is the one that decides: with its guard the read finishes with a warning
// at a peak of 77.8 MB, and with the guard deleted the same file dies with
// "Allowed memory size of 100663296 bytes exhausted". The reading step still
// finishes normally at this limit (it is allowed half, and uses about 40 MB),
// so this is not secretly a test of the reading step's guard instead.
$firstLoopIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 6000; $i++) {
    $dates = [];
    for ($k = 0; $k < 60; $k++) {
        $dates[] = sprintf('%04d%02d%02d', 1800 + intdiv($k + $i, 300), 1 + (($k + $i) % 12), 1 + (($k + $i) % 28));
    }
    $firstLoopIcs .= "BEGIN:VEVENT\r\nUID:firstloop-" . $i . "@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:18000102T090000\r\nSUMMARY:First loop " . $i . "\r\n"
        . 'EXDATE;VALUE=DATE:' . implode(',', $dates) . "\r\nEND:VEVENT\r\n";
}
$firstLoopIcs .= "END:VCALENDAR\r\n";
unset($dates);
$child = st_in_child($firstLoopIcs, '96M', '2027-09-01 00:00:00');
st_check(
    'I20 six thousand list-carrying events finish normally rather than running out of memory',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I20 and it says it stopped because memory was running short',
    str_contains($child['text'], 'needed more memory than the portal allows') === true,
    substr($child['text'], 0, 400)
);
st_check(
    'I20 and the reading step still finished, so this is the date-working step\'s guard being tested',
    str_contains($child['text'], 'complete=true') === true,
    substr($child['text'], 0, 200)
);

// I21: the LENGTH half of the time-zone name guard, as opposed to the SEGMENT
// half. I15f's five-megabyte name is nothing but slashes, so only the segment
// half was ever exercised and the length half — the one that stops a
// megabytes-long name being matched, looked up and printed into a warning —
// could be deleted with nothing noticing. This name has NO slashes at all.
$child = st_in_child(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:longzone@fixtures.webms.test\r\n"
    . 'DTSTART;TZID=' . str_repeat('A', 4000000) . ":20260902T090000\r\n"
    . "SUMMARY:Long zone name\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I21 a four-million-character time-zone name with no slashes at all finishes normally',
    $child['status'] === 0 && str_contains($child['text'], 'Allowed memory size') === false,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 200)
);
st_check(
    'I21 and it is refused for being too long, without the name being quoted back',
    str_contains($child['text'], 'far longer than any real one') === true
    && str_contains($child['text'], 'AAAAAAAAAA') === false,
    substr($child['text'], 0, 300)
);
// I21 control: a name of exactly the length allowed, with no slashes, is still
// looked up normally — so the limit cannot be quietly lowered to nothing.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:okzone@fixtures.webms.test\r\n"
    . 'DTSTART;TZID=' . str_repeat('A', 200) . ":20260902T090000\r\n"
    . "SUMMARY:Unknown but short\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I21 control: a 200-character name is read as an ordinary unknown zone, quoted back in the warning',
    st_has_warning($r['expand']['warnings'], 'Unknown time zone "AAAA') === true
    && st_has_warning($r['expand']['warnings'], 'far longer than any real one') === false,
    implode(' | ', $r['expand']['warnings'])
);

// I22: the time limit, which was counted in STEPS rather than in time, and was
// not looked at in two loops at all.
//
// (a) The repeat-rule worker. A rule inside every limit — every month of the
// year, all 749 different weekday values, starting in the year 1 — costs about
// a quarter of a second for ONE step, because each value has to be tried
// against every day of every month. Checked every two hundredth step, a FIVE-
// second budget came back after 47.78 seconds. That matters because the
// importer runs at a web-served address, where an overrun of that size ends as
// PHP's own "maximum execution time exceeded" — a fatal error nothing can
// catch. The test allows generous room for a slower machine and still fails
// by a mile if the two-hundred-step version ever comes back.
$slowRule = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:slow@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:00010101T090000\r\nSUMMARY:Slow rule\r\n"
    . 'RRULE:FREQ=YEARLY;BYMONTH=1,2,3,4,5,6,7,8,9,10,11,12;BYDAY=' . $bydayValues(749)
    . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$child = st_in_child($slowRule, '128M', '2027-09-01 00:00:00', 60.0, 2.0);
st_check(
    'I22a a repeat rule too slow to finish gives up with "time budget" rather than grinding on',
    str_contains($child['text'], 'time budget') === true,
    substr($child['text'], 0, 300)
);
$seconds = 0.0;
if (preg_match('/secs=([\d.]+)/', $child['text'], $m) === 1) {
    $seconds = (float) $m[1];
}
st_check(
    'I22a and it gives up within a small multiple of its two-second budget, not forty-eight seconds later',
    $seconds > 0.0 && $seconds < 12.0,
    'took ' . $seconds . ' s on a 2 s budget: ' . substr($child['text'], 0, 200)
);
unset($slowRule, $seconds, $m);

// (b) The loop that turns one event's dates into occurrences. It had no
// deadline check at all, and it is not cheap: the list of changed dates is
// walked in full for every date the series offers. Twenty events sharing one
// UID, each with four thousand added dates, and nearly six thousand changed
// dates on the same UID, finished NORMALLY after 2.85 seconds on a one-second
// budget — no exception, nothing said. There is deliberately no repeat rule
// here, because the repeat-rule worker is the one loop that DID check the
// clock and would have masked this.
$scanIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 20; $i++) {
    $dates = [];
    for ($k = 0; $k < IcsReader::MAX_DATE_LIST_PER_EVENT; $k++) {
        $dates[] = sprintf('%04d%02d%02d', 1500 + intdiv($k + $i, 300), 1 + (($k + $i) % 12), 1 + (($k + $i) % 28));
    }
    $scanIcs .= "BEGIN:VEVENT\r\nUID:scan@fixtures.webms.test\r\nDTSTART;VALUE=DATE:20260902\r\n"
        . "SUMMARY:Scan " . $i . "\r\n" . 'RDATE;VALUE=DATE:' . implode(',', $dates) . "\r\nEND:VEVENT\r\n";
}
for ($i = 0; $i < 5000; $i++) {
    $scanIcs .= "BEGIN:VEVENT\r\nUID:scan@fixtures.webms.test\r\nRECURRENCE-ID;VALUE=DATE:"
        . sprintf('%04d%02d%02d', 1200 + intdiv($i, 300), 1 + ($i % 12), 1 + ($i % 28))
        . "\r\nDTSTART;VALUE=DATE:20260903\r\nSUMMARY:Changed " . $i . "\r\nEND:VEVENT\r\n";
}
$scanIcs .= "END:VCALENDAR\r\n";
// The measurement that matters here is the TIME, not whether an exception came
// back at all. When this was written, removing this loop's check made the
// work finish only after the whole scan: 0.97 seconds on a budget of 0.20,
// against 0.24 seconds with the check.
//
// KNOWN GAP (found 25 September 2026, issue #558). At this 0.2-second
// budget, the FIRST loop of expand() (reading the events, which I22c guards)
// can use up the whole budget before this walk starts, depending on how
// fast the machine is. When it does, this check never reaches the walk it is
// named for, and it passes even with the walk's own deadline check removed.
// When the walk IS reached, removing that check makes it run seconds late
// (2.5 to 15.5 seconds measured on 25 September 2026, depending on the
// budget and on how busy the machine was) and this check fails. So do NOT remove the
// deadline check in IcsReader::occurrencesForMaster() because this check
// still passes without it. #558 is to make this check reach the walk on
// any machine.
$child = st_in_child($scanIcs, '128M', '2027-09-01 00:00:00', 60.0, 0.2);
st_check(
    'I22b walking a long list of changed dates gives up when its time is up, instead of finishing late',
    str_contains($child['text'], 'time budget') === true,
    substr($child['text'], 0, 300)
);
// Only `expand()` is timed here (`expandtime`), not reading the file as
// well. That includes expand()'s first loop, so a pass here does NOT prove
// the walk through a series' dates stopped on time (see the KNOWN GAP note
// above). Reading has its own 60-second budget and is not what this check is
// about; counting it made the check fail on a busy machine for a reason
// unrelated to the fault it guards (0.703 s against 0.7 s on 25 September
// 2026, seven copies running at once). The limit is unchanged. A walk that
// ignores every deadline took 3.7 to 6.9 seconds when measured the same day,
// and fails it; see the KNOWN GAP note above for what this check does NOT
// reliably cover.
$seconds = 0.0;
if (preg_match('/expandtime=([\d.]+)/', $child['text'], $m) === 1) {
    $seconds = (float) $m[1];
}
st_check(
    'I22b and it gives up DURING the scan, not after finishing the whole of it',
    $seconds > 0.0 && $seconds < 0.7,
    'expand() took ' . $seconds . ' s on a 0.2 s budget: ' . substr($child['text'], 0, 200)
);
unset($scanIcs, $dates, $seconds, $m);

// (c) The FIRST loop of expand(), which reads every event before any date is
// worked out. It had no deadline check either: six thousand list-carrying
// events took ten times a short budget and finished normally. The reading step
// is given plenty of time here, because the fault is in the step AFTER it and
// a file that never finishes being read would never reach it.
$child = st_in_child($firstLoopIcs, '96M', '2027-09-01 00:00:00', 60.0, 0.05);
st_check(
    'I22c reading six thousand events gives up when the date-working step is out of time',
    str_contains($child['text'], 'time budget') === true,
    substr($child['text'], 0, 300)
);
unset($firstLoopIcs);

// (d) Both deadline checks inside parse(). Every check above gave parse() as
// much time as it wanted, so the two checks inside it could be deleted with
// nothing noticing.
//
// There are two of them — one in the walk that finds the line endings, one in
// the loop that reads the lines into properties — and they cannot be told
// apart from outside, because the second loop never sees a line the first one
// did not see first. So this check covers the pair: delete either one and the
// other still answers; delete both and this fails. That is said here rather
// than left for somebody to work out, because a check that looks like two
// checks and is really one is the kind of thing this round exists to catch.
$ordinaryIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:clock@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260902T090000\r\nDTEND;TZID=Europe/London:20260902T100000\r\n"
    . "SUMMARY:Ordinary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$threw = '';
try {
    IcsReader::parse($ordinaryIcs, microtime(true) - 1.0);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
st_check(
    'I22d reading a file whose time is ALREADY up stops at once with "time budget"',
    $threw === 'time budget',
    $threw === '' ? 'it finished normally instead' : $threw
);
$threw = '';
try {
    IcsReader::parse($ordinaryIcs, microtime(true) + 30.0);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
st_check(
    'I22d control: the same file with time to spare is read normally',
    $threw === '',
    $threw
);
unset($ordinaryIcs, $threw);

// I23: splitList() saying a list was "cut" when the only thing left unread was
// a trailing comma. The promise in its notes — that a list ending in one comma
// and nothing else is NOT reported as cut — had no check, so it could have
// been lost and every calendar ending a list with a comma would have been
// marked "dates may be missing" for no reason.
//
// The trailing comma only matters at the exact edge: a list of exactly the
// allowance, and then a comma. Below the allowance nothing is ever cut.
$exactList = [];
for ($i = 0; $i < IcsReader::MAX_DATE_LIST_PER_EVENT; $i++) {
    $exactList[] = sprintf('%04d%02d%02d', 1500 + intdiv($i, 300), 1 + ($i % 12), 1 + ($i % 28));
}
$edgeIcs = static function (string $after) use ($exactList): string {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:edge@fixtures.webms.test\r\n"
        . "DTSTART;VALUE=DATE:20260902\r\nSUMMARY:Edge\r\n"
        . 'EXDATE;VALUE=DATE:' . implode(',', $exactList) . $after . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
};
$r = st_inline($edgeIcs(''));
st_check(
    'I23 exactly ' . IcsReader::MAX_DATE_LIST_PER_EVENT . ' skipped dates are NOT reported as cut short',
    st_has_warning($r['expand']['warnings'], 'more skipped or added dates') === false,
    implode(' | ', $r['expand']['warnings'])
);
$r = st_inline($edgeIcs(','));
st_check(
    'I23 the same list with one trailing comma is still NOT reported as cut short',
    st_has_warning($r['expand']['warnings'], 'more skipped or added dates') === false,
    implode(' | ', $r['expand']['warnings'])
);
$r = st_inline($edgeIcs(',,'));
st_check(
    'I23 but a trailing comma with anything after it IS reported, because text really was left unread',
    st_has_warning($r['expand']['warnings'], 'more skipped or added dates') === true,
    implode(' | ', $r['expand']['warnings'])
);
$r = st_inline($edgeIcs(',20270101'));
st_check(
    'I23 and one real date past the allowance IS reported',
    st_has_warning($r['expand']['warnings'], 'more skipped or added dates') === true,
    implode(' | ', $r['expand']['warnings'])
);
unset($exactList, $edgeIcs);

// I24: an INTERVAL far beyond anything real. PHP turns a twenty-digit number
// into the largest whole number it can hold rather than refusing it, and the
// arithmetic that moves to the next period then tips into floating point. A
// weekly rule threw DateMalformedStringException and a monthly one threw
// TypeError — neither of them the "time budget" the caller is told to expect,
// and this class promises a warning and never a failed import.
foreach (['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'] as $frequency) {
    $threw = '';
    $r     = ['expand' => ['occurrences' => [], 'warnings' => []]];
    try {
        $r = st_inline(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:interval@fixtures.webms.test\r\n"
            . "DTSTART;TZID=Europe/London:20260902T090000\r\nDTEND;TZID=Europe/London:20260902T100000\r\n"
            . 'RRULE:FREQ=' . $frequency . ";INTERVAL=99999999999999999999\r\n"
            . "SUMMARY:Absurd interval\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
        );
    } catch (Throwable $e) {
        $threw = get_class($e) . ': ' . $e->getMessage();
    }
    st_check(
        'I24 ' . $frequency . ' with a twenty-digit interval gives one date and a warning, and throws nothing',
        $threw === '' && count($r['expand']['occurrences']) === 1
        && st_has_warning($r['expand']['warnings'], 'further apart than this portal works out') === true,
        ($threw !== '' ? 'threw ' . $threw : 'got ' . count($r['expand']['occurrences']) . ' date(s): '
            . implode(' | ', $r['expand']['warnings']))
    );
}
// I24 control: an interval a real calendar might use is worked out normally.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:fortnight@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260905T090000\r\nDTEND;TZID=Europe/London:20260905T100000\r\n"
    . "RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=SA;COUNT=4\r\nSUMMARY:Fortnightly\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I24 control: an ordinary fortnightly rule still gives four dates, a fortnight apart, with no warning',
    count($r['expand']['occurrences']) === 4 && $r['expand']['warnings'] === []
    && $r['expand']['occurrences'][1]['start'] === '2026-09-19 09:00:00',
    implode(', ', st_lines_of($r['expand']['occurrences'])) . ' | ' . implode(' | ', $r['expand']['warnings'])
);

// I25: a COUNT of zero or less. It used to be read as "no count at all", so
// COUNT=-5 on a daily rule quietly produced every date in the window with
// nothing said — where this class's own policy for a rule it cannot honour is
// the first date and a warning.
foreach ([-5, 0] as $count) {
    $r = st_inline(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:count@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260902T090000\r\nDTEND;TZID=Europe/London:20260902T100000\r\n"
        . 'RRULE:FREQ=DAILY;COUNT=' . $count . "\r\nSUMMARY:Bad count\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    st_check(
        'I25 COUNT=' . $count . ' gives the first date only, with a warning saying why',
        count($r['expand']['occurrences']) === 1
        && st_has_warning($r['expand']['warnings'], 'not a number of dates this portal can work out') === true,
        'got ' . count($r['expand']['occurrences']) . ' date(s): ' . implode(' | ', $r['expand']['warnings'])
    );
}
// I25 control: COUNT=1 is a real thing to write and must still work.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:count1@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260902T090000\r\nDTEND;TZID=Europe/London:20260902T100000\r\n"
    . "RRULE:FREQ=DAILY;COUNT=1\r\nSUMMARY:Just once\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I25 control: COUNT=1 gives one date with no warning at all',
    count($r['expand']['occurrences']) === 1 && $r['expand']['warnings'] === [],
    implode(' | ', $r['expand']['warnings'])
);

// I26: an end no database can hold. The importer writes these into a MySQL
// DATETIME column, which stops at the end of the year 9999. RFC 5545 puts no
// limit on how many digits a DURATION may have, so P999999999999W gave an end
// in the year 19,165,351,075; and an end of 99991231T235959Z read in London
// came out one second past the end of 9999.
//
// The second shape here USED to be `DTEND:99991231T235959Z`. It no longer
// needs bringing back, and that is a real improvement rather than a check
// being weakened: read in London that is 23:59:59 on 31 December 9999, which a
// database can hold perfectly well. It only ever came out a second too late
// because the length was worked out with `diff()` and applied as a wall-clock
// offset — the same fault that made an event spanning a clock change end an
// hour out (see I29). The shape below keeps a real test of the clamp for an
// explicit end: an end at the last moment of 9999 in NEW YORK is five hours
// later in London, which really is past what can be stored.
foreach ([
    'a duration of a trillion weeks'      => "DURATION:P999999999999W\r\n",
    'an end at the end of 9999 in New York' => "DTEND;TZID=America/New_York:99991231T235959\r\n",
] as $what => $line) {
    $r = st_inline(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:faraway@fixtures.webms.test\r\n"
        . "DTSTART:20260902T090000Z\r\n" . $line
        . "SUMMARY:Far away end\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    st_check(
        'I26 ' . $what . ' is brought back to an end a database can hold',
        count($r['expand']['occurrences']) === 1
        && $r['expand']['occurrences'][0]['end'] === '9999-12-31 23:59:59'
        && st_has_warning($r['expand']['warnings'], 'further ahead than dates can be stored') === true,
        (count($r['expand']['occurrences']) === 1 ? 'end=' . $r['expand']['occurrences'][0]['end'] : 'no date')
        . ' | ' . implode(' | ', $r['expand']['warnings'])
    );
}
// I26 control: an ordinary end is untouched and says nothing.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:normalend@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260902T090000\r\nDTEND;TZID=Europe/London:20260902T103000\r\n"
    . "SUMMARY:Normal end\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I26 control: an ordinary hour-and-a-half meeting keeps its real end, with no warning',
    count($r['expand']['occurrences']) === 1
    && $r['expand']['occurrences'][0]['end'] === '2026-09-02 10:30:00'
    && $r['expand']['warnings'] === [],
    (count($r['expand']['occurrences']) === 1 ? 'end=' . $r['expand']['occurrences'][0]['end'] : 'no date')
    . ' | ' . implode(' | ', $r['expand']['warnings'])
);
// I26 second control: an end the file states at the very last moment of 9999,
// in UTC, is kept EXACTLY and says nothing. This is the case the first version
// of the clamp reported as too far ahead, because the old arithmetic pushed it
// one second past the end of 9999 all by itself.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:endof9999@fixtures.webms.test\r\n"
    . "DTSTART:20260902T090000Z\r\nDTEND:99991231T235959Z\r\n"
    . "SUMMARY:Ends at the very end of 9999\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I26 control: an end of 9999-12-31 23:59:59 in UTC is kept exactly, with nothing brought back',
    count($r['expand']['occurrences']) === 1
    && $r['expand']['occurrences'][0]['end'] === '9999-12-31 23:59:59'
    && $r['expand']['warnings'] === [],
    (count($r['expand']['occurrences']) === 1 ? 'end=' . $r['expand']['occurrences'][0]['end'] : 'no date')
    . ' | ' . implode(' | ', $r['expand']['warnings'])
);

// -----------------------------------------------------------------------------
// I27 to I31 — WHAT A FIFTH ROUND OF INDEPENDENT CHECKING FOUND
//
// The fourth round was about memory and the fifth could not break any of it.
// These five are about two other things: a promise this class makes to the
// importer and breaks, and a wrong time on a real customer's page.
// -----------------------------------------------------------------------------

// I27: a cut skipped-date or added-date list must throw away the "read
// reliably up to here" point.
//
// Why this matters more than it looks. `effectiveWindowEnd` is the one thing
// the importer uses to decide what to tidy away: it deletes every stored date
// at or before that point which this read did not produce. Two other stops
// already set it to nothing, with the reason written beside them — "an
// importer that believed it would delete real events". A cut list is exactly
// the same situation and did not do it.
//
// The calendar: one daily series from 2025, which caps at 400 dates and leaves
// an end of 4 February 2027; and one event carrying 4,500 added dates, 500 of
// them in 2026 — every one of those 500 BEFORE that end, and none of them in
// the answer. The first refresh to cross the limit would have deleted all 500.
$cutEndIcs  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:cutend-series@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20250101T090000\r\nDTEND;TZID=Europe/London:20250101T100000\r\n"
    . "SUMMARY:Daily since 2025\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT\r\n";
$cutEndList = [];
$cutEndDay  = new DateTimeImmutable('2014-01-01', new DateTimeZone('UTC'));
for ($i = 0; $i < IcsReader::MAX_DATE_LIST_PER_EVENT; $i++) {
    $cutEndList[] = $cutEndDay->modify('+' . $i . ' days')->format('Ymd') . 'T100000Z';
}
$cutEndDay = new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC'));
for ($i = 0; $i < 500; $i++) {
    $cutEndList[] = $cutEndDay->modify('+' . $i . ' days')->format('Ymd') . 'T100000Z';
}
$cutEndIcs .= "BEGIN:VEVENT\r\nUID:cutend-added@fixtures.webms.test\r\n"
    . "DTSTART:20200101T100000Z\r\nDTEND:20200101T110000Z\r\nSUMMARY:Four and a half thousand added dates\r\n"
    . 'RDATE:' . implode(',', $cutEndList) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
// Read over a WIDER window than the rest of this script uses, because the
// point of the check is a series that runs past the 400-date limit, and 400
// daily dates do not fit in the one-year window everything else uses. The
// window is the fifteen months the round-five check measured, so this is the
// same measurement.
$cutEndRead = static function (string $body) use ($orgZone): array {
    return IcsReader::expand(
        IcsReader::parse($body, microtime(true) + 60.0),
        $orgZone,
        $orgZone,
        new DateTimeImmutable('2026-01-01 00:00:00', $orgZone),
        new DateTimeImmutable('2027-02-28 00:00:00', $orgZone),
        microtime(true) + 60.0
    );
};
$cutEnd = $cutEndRead($cutEndIcs);
st_check(
    'I27 a cut added-date list leaves NO "read reliably up to here" point, so nothing is deleted on the strength of it',
    $cutEnd['capped'] === true && $cutEnd['effectiveWindowEnd'] === null,
    'capped=' . var_export($cutEnd['capped'], true)
    . ' effectiveWindowEnd=' . var_export($cutEnd['effectiveWindowEnd'], true)
);
st_check(
    'I27 and the answer really is missing dates that sit before where that point used to be',
    count($cutEnd['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES,
    'got ' . count($cutEnd['occurrences']) . ' date(s)'
);
// I27 control: a capped answer that still DOES report its end point, so this
// fix cannot be "always report nothing" — which would stop the importer ever
// tidying anything away, and the check above would still pass.
//
// THIS CONTROL WAS REWRITTEN ON 23 SEPTEMBER 2026 and the reason matters. It
// used to use a daily series cut short by the 400-dates-per-event limit, and
// assert an end point of 4 February 2027. A ninth round of checking showed
// that value is not trustworthy at all (see I33), so a cut series now reports
// nothing and this control had to move to the one source that IS trustworthy:
// the per-calendar slice, which keeps the first `MAX_EVENTS_PER_FEED` dates
// AFTER sorting them by exactly the reading the importer compares against.
// The control is still doing its job — it still fails if somebody makes the
// answer "never report an end point".
$plainCapIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
$plainCapDay = new DateTimeImmutable('2026-01-02 09:00:00', $orgZone);
for ($i = 0; $i < IcsReader::MAX_EVENTS_PER_FEED + 50; $i++) {
    $moment       = $plainCapDay->modify('+' . $i . ' hours');
    $plainCapIcs .= "BEGIN:VEVENT\r\nUID:plaincap-" . $i . "@fixtures.webms.test\r\n"
        . 'DTSTART;TZID=Europe/London:' . $moment->format('Ymd\THis') . "\r\n"
        . 'DTEND;TZID=Europe/London:' . $moment->modify('+30 minutes')->format('Ymd\THis') . "\r\n"
        . 'SUMMARY:One-off ' . $i . "\r\nEND:VEVENT\r\n";
}
$plainCapIcs .= "END:VCALENDAR\r\n";
// Worked out with plain date arithmetic rather than by asking the reader, so a
// reader that got its own sums wrong cannot agree with itself.
$plainCapExpected = $plainCapDay->modify('+' . (IcsReader::MAX_EVENTS_PER_FEED - 1) . ' hours')
    ->format('Y-m-d H:i:s');
$plainCap = $cutEndRead($plainCapIcs);
st_check(
    'I27 control: a capped answer with no cut list and no cut SERIES still reports the point it was '
    . 'read reliably up to (' . $plainCapExpected . ')',
    $plainCap['capped'] === true && $plainCap['effectiveWindowEnd'] === $plainCapExpected,
    'capped=' . var_export($plainCap['capped'], true)
    . ' effectiveWindowEnd=' . var_export($plainCap['effectiveWindowEnd'], true)
);
unset($cutEndIcs, $cutEndList, $cutEndDay, $cutEndRead, $cutEnd, $plainCap, $plainCapIcs, $plainCapDay, $plainCapExpected);

// I28: the exact-boundary path, where a file-wide budget is used up PRECISELY
// at an event boundary and the next event's list is not read at all.
//
// Both halves of this were uncovered. The line that records the cut in that
// branch could be deleted and nothing noticed, because the answer is still
// marked capped by another route — so the calendar came back capped with NO
// warning of any kind, which is the worst of both: an administrator sees
// nothing to act on and the importer stops tidying up for ever.
//
// Eleven events of 3,000 added dates: ten use exactly the file-wide allowance
// of 30,000, and the eleventh is refused before a single piece of its list is
// read. The same shape for skipped dates uses 51 events of 4,000.
$boundaryIcs = static function (int $events, int $pieces, string $property): string {
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    for ($e = 0; $e < $events; $e++) {
        $dates = [];
        for ($i = 0; $i < $pieces; $i++) {
            $dates[] = sprintf('%04d%02d%02d', 1500 + intdiv(($i * 7) + $e, 300), 1 + (($i + $e) % 12), 1 + (($i + $e) % 28));
        }
        $out .= "BEGIN:VEVENT\r\nUID:boundary-" . $e . "@fixtures.webms.test\r\n"
            . "DTSTART;VALUE=DATE:20260902\r\nSUMMARY:Boundary " . $e . "\r\n"
            . $property . ';VALUE=DATE:' . implode(',', $dates) . "\r\nEND:VEVENT\r\n";
    }

    return $out . "END:VCALENDAR\r\n";
};
//
// Both of these are read in a SEPARATE process. Not because they are fatal —
// they are not — but because the skipped-date one holds two hundred thousand
// dates at once, and leaving that in THIS process pushed it past the half of
// PHP's allowance the reading step is given, so every later check in this
// script silently came back with an empty calendar. That happened while these
// checks were being written, and it is exactly the shape the reader's own
// memory guard is meant to have: it did not crash, it quietly stopped. Reading
// in a child keeps the peaks from piling up, which is what the note at the top
// of this script says every big calendar here must do.
foreach ([
    'added'   => ['RDATE', IcsReader::MAX_RETAINED_ADDED_DATES, 3000],
    'skipped' => ['EXDATE', IcsReader::MAX_RETAINED_SKIPPED_DATES, IcsReader::MAX_DATE_LIST_PER_EVENT],
] as $kind => [$property, $budget, $per]) {
    $child = st_in_child($boundaryIcs(intdiv($budget, $per) + 1, $per, $property), '128M', '2027-09-01 00:00:00');
    st_check(
        'I28 ' . $kind . ' dates: a budget used up exactly at an event boundary still says so',
        $child['status'] === 0
        && str_contains($child['text'], 'events between them list more added dates') === true
        && str_contains($child['text'], 'capped=true') === true,
        'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 400)
    );
    st_check(
        'I28 ' . $kind . ' dates: and it does NOT blame one event, whose own allowance was never reached',
        str_contains($child['text'], 'An event in this calendar lists more skipped or added dates') === false,
        substr($child['text'], 0, 400)
    );
}
// I28 control: one event genuinely over its OWN allowance is blamed on the
// event, and says nothing about the calendar as a whole.
$r = st_inline($boundaryIcs(1, IcsReader::MAX_DATE_LIST_PER_EVENT + 1, 'RDATE'));
st_check(
    'I28 control: one event over its own allowance gives the per-event warning and no file-wide one',
    st_has_warning($r['expand']['warnings'], 'An event in this calendar lists more skipped or added dates') === true
    && st_has_warning($r['expand']['warnings'], 'events between them list more added dates') === false,
    implode(' | ', $r['expand']['warnings'])
);
// I28 control: both at once, and both are said, because both are then true.
// In a child for the same reason as above — forty thousand added dates is
// 15 MB that this process would go on holding.
$child = st_in_child(
    str_replace("END:VCALENDAR\r\n", '', $boundaryIcs(1, IcsReader::MAX_DATE_LIST_PER_EVENT + 1, 'RDATE'))
    . str_replace("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", '', $boundaryIcs(9, IcsReader::MAX_DATE_LIST_PER_EVENT, 'RDATE')),
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I28 control: a calendar that breaks BOTH allowances is told both things',
    $child['status'] === 0
    && str_contains($child['text'], 'An event in this calendar lists more skipped or added dates') === true
    && str_contains($child['text'], 'events between them list more added dates') === true,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 400)
);

// I28: a GENUINE TIE — both allowances running out on the SAME line of the
// SAME event, which is the one case the control above never reaches.
//
// This is here because a sixth round of independent checking proved the gap.
// The control above gets both warnings out of two DIFFERENT events: one event
// over its own allowance, nine more between them over the calendar's. That
// leaves the tie untested, and the code that handles a tie is two `<=` tests
// which can be changed to `<` without a single check noticing. A tie is not a
// curiosity — it is what happens whenever a calendar's remaining room and an
// event's remaining room reach zero together, and on a tie both statements
// are true, so both must be said.
//
// Reaching it exactly: the calendar may hold 30,000 added dates in total and
// any one event may list 4,000. Six events of 4,000 and one of 2,000 use
// 26,000, which leaves the calendar 4,000 — the same as a fresh event's own
// allowance. The eighth event then offers 4,001 on ONE line, so the split
// stops with both allowances at zero at the same moment.
//
// It has to be one LINE of 4,001, not 4,001 lines. A list spread over many
// lines is refused before the next line is read, which is a different branch
// with its own `< 1` tests; the tie tests live in the branch that stops PART
// WAY THROUGH a single line.
//
// In a child process for the reason given above: 30,000 added dates is about
// 11 MB this script would otherwise go on holding, and a script short of
// memory makes later checks read empty calendars.
$perEvent = IcsReader::MAX_DATE_LIST_PER_EVENT;
$wholeFew = intdiv(IcsReader::MAX_RETAINED_ADDED_DATES - $perEvent, $perEvent);
$child    = st_in_child(
    str_replace("END:VCALENDAR\r\n", '', $boundaryIcs($wholeFew, $perEvent, 'RDATE'))
    . str_replace(
        ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", "END:VCALENDAR\r\n"],
        '',
        $boundaryIcs(
            1,
            IcsReader::MAX_RETAINED_ADDED_DATES - $perEvent - ($wholeFew * $perEvent),
            'RDATE'
        )
    )
    . str_replace("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", '', $boundaryIcs(1, $perEvent + 1, 'RDATE')),
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I28 a genuine TIE — both allowances running out on the same line — is told BOTH things, not one of them',
    $child['status'] === 0
    && str_contains($child['text'], 'An event in this calendar lists more skipped or added dates') === true
    && str_contains($child['text'], 'events between them list more added dates') === true,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 500)
);
// I28: the SAME tie, in the OTHER list.
//
// The tie is decided by two `<=` tests, and there are TWO copies of them —
// one for the SKIPPED-date list (EXDATE) and one for the ADDED-date list
// (RDATE). The check above only ever reaches the added-date copy. That is not
// a guess: turning `<=` into `<` on the per-event test in the SKIPPED-date
// copy was planted deliberately, and the whole self-test still came back 308
// passed, 0 failed. A guard with no check behind it is not a guard, so the
// same tie is exercised here on the other side.
//
// Reaching it exactly: the calendar may hold 200,000 skipped dates in all, and
// any one event may list 4,000. Forty-nine events of 4,000 use 196,000, which
// leaves the calendar 4,000 — exactly what a fresh event's own allowance is.
// The fiftieth event then offers 4,001 on ONE line, so the split stops with
// both allowances reaching zero in the same moment.
//
// The last event is given its own UID. Without that it would repeat the first
// event's, the reader would treat the two blocks as one event sent twice, and
// this check would be measuring something other than the tie.
//
// In a child process for the same reason as the checks above: 200,000 skipped
// dates is about 1.8 MB of calendar text plus a large array of keys, and this
// script would otherwise go on holding it for every later check.
$perEvent   = IcsReader::MAX_DATE_LIST_PER_EVENT;
$fullEvents = intdiv(IcsReader::MAX_RETAINED_SKIPPED_DATES, $perEvent) - 1;
$child      = st_in_child(
    str_replace("END:VCALENDAR\r\n", '', $boundaryIcs($fullEvents, $perEvent, 'EXDATE'))
    . str_replace(
        ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", 'UID:boundary-0@'],
        ['', 'UID:boundary-tie@'],
        $boundaryIcs(1, $perEvent + 1, 'EXDATE')
    ),
    '128M',
    '2027-09-01 00:00:00'
);
st_check(
    'I28 the same genuine TIE in the SKIPPED-date list is told BOTH things as well',
    $child['status'] === 0
    && str_contains($child['text'], 'An event in this calendar lists more skipped or added dates') === true
    && str_contains($child['text'], 'events between them list more added dates') === true,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 500)
);
unset($boundaryIcs, $perEvent, $wholeFew, $fullEvents);

// I29: an event that spans a clock change.
//
// This was wrong from P5's first build and five rounds of checking walked past
// it, because it is a correctness fault rather than a safety one: nothing
// crashes, nothing warns, the time on the page is simply an hour out. It
// happens twice a year to any organisation with an overnight event — a vigil,
// a night shelter, a youth sleepover.
//
// The expected times below were worked out by hand from the two clock changes
// inside this script's window (clocks back 25 October 2026, forward 28 March
// 2027) and checked against plain PHP date arithmetic, NOT against the reader.
//
// Three readings are involved and RFC 5545 separates them:
//   - an explicit DTEND states an END MOMENT, so that moment is used;
//   - a DURATION of hours is an EXACT length (§3.3.6), five real hours;
//   - a DURATION of a day is a NOMINAL length, "the same clock reading
//     tomorrow" — so on the night the clocks go back it is 25 real hours.
$r = st_read('dst-clock-change.ics');
st_lines(
    'I29 dst-clock-change.ics: every end across both clock changes, in both directions',
    [
        '2026-10-17 23:00:00|2026-10-18 04:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|An ordinary night, no clock change',
        '2026-10-24 23:00:00|2026-10-25 03:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|Five hours exactly, clocks back',
        '2026-10-24 23:00:00|2026-10-25 04:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|Night shelter, clocks back',
        '2026-10-24 23:00:00|2026-10-25 23:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|One whole day, clocks back',
        '2026-10-24 23:00:00|2026-10-25 04:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|Vigil written in UTC, clocks back',
        '2027-03-27 23:00:00|2027-03-28 05:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|Five hours exactly, clocks forward',
        '2027-03-27 23:00:00|2027-03-28 04:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|Night shelter, clocks forward',
        '2027-03-27 23:00:00|2027-03-28 23:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|One whole day, clocks forward',
        '2027-03-27 23:00:00|2027-03-28 04:00:00|timed|key=|one-off|priv=no|canc=no|dup=no'
        . '|Vigil written in UTC, clocks forward',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'I29 and not one warning was needed to get any of them right',
    $r['expand']['warnings'] === [] && $r['parse']['warnings'] === [],
    implode(' | ', array_merge($r['parse']['warnings'], $r['expand']['warnings']))
);
// I29 the same shape as a REPEATING event, which is where the two readings a
// calendar program might take genuinely differ. RFC 5545 §3.8.5.3 says a
// length stated with DTEND is "the same exact duration" for every date of the
// series, so the instance on the night the clocks go back ends at 03:00 and
// not 04:00. That is the reading implemented, it is written down beside the
// code, and this check is what stops it being changed by accident.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:dst-series@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261017T230000\r\nDTEND;TZID=Europe/London:20261018T040000\r\n"
    . "RRULE:FREQ=WEEKLY;BYDAY=SA;COUNT=3\r\nSUMMARY:Weekly night shelter\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'I29 a weekly overnight series keeps the same EXACT length on the night the clocks go back (RFC 5545 §3.8.5.3)',
    [
        '2026-10-17 23:00:00|2026-10-18 04:00:00|timed|key=20261017T220000Z|series|priv=no|canc=no|dup=no'
        . '|Weekly night shelter',
        '2026-10-24 23:00:00|2026-10-25 03:00:00|timed|key=20261024T220000Z|series|priv=no|canc=no|dup=no'
        . '|Weekly night shelter',
        '2026-10-31 23:00:00|2026-11-01 04:00:00|timed|key=20261031T230000Z|series|priv=no|canc=no|dup=no'
        . '|Weekly night shelter',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// I30: the per-feed ceiling, which the owner decided on 23 September 2026
// should be something a customer can change. The reader still reads no
// setting; the number arrives as an argument, and part P6 is what reads the
// customer's setting and passes it.
//
// Ten daily series over one year offer 3,650 dates — more than the default
// ceiling and fewer than the highest one allowed, so every answer below is
// decided by the ceiling and by nothing else.
//
// Ten, and not a hundred: each gathered date costs about 912 bytes, so a
// hundred series would hold tens of megabytes in THIS process and leave the
// later checks reading empty calendars (see the note in I28). Each answer here
// is therefore reduced to a small summary and the dates themselves let go
// straight away.
$ceilingIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 10; $i++) {
    $ceilingIcs .= "BEGIN:VEVENT\r\nUID:ceiling-" . $i . "@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T" . sprintf('%02d', 6 + $i) . "0000\r\n"
        . "SUMMARY:Series " . $i . "\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT\r\n";
}
$ceilingIcs .= "END:VCALENDAR\r\n";
$ceilingParsed  = IcsReader::parse($ceilingIcs, microtime(true) + 60.0);
/** Read the same calendar with one ceiling, and keep only a small summary of the answer. */
$ceilingRead = static function (?int $limit, bool $keepLines = false) use ($ceilingParsed, $orgZone, $windowStart, $windowEnd): array {
    $answer = IcsReader::expand(
        $ceilingParsed,
        $orgZone,
        $orgZone,
        $windowStart,
        $windowEnd,
        microtime(true) + 60.0,
        $limit
    );

    return [
        'dates'    => count($answer['occurrences']),
        'capped'   => $answer['capped'],
        'warnings' => $answer['warnings'],
        'lines'    => $keepLines === true ? st_lines_of($answer['occurrences']) : [],
    ];
};
$byDefault = $ceilingRead(null, true);
st_check(
    'I30 leaving the ceiling out gives exactly the ' . IcsReader::MAX_EVENTS_PER_FEED . ' dates it always did',
    $byDefault['dates'] === IcsReader::MAX_EVENTS_PER_FEED && $byDefault['capped'] === true,
    'got ' . $byDefault['dates'] . ', capped=' . var_export($byDefault['capped'], true)
);
$sameAgain = $ceilingRead(IcsReader::MAX_EVENTS_PER_FEED, true);
st_check(
    'I30 and asking for the default by name gives the identical answer, date for date',
    $sameAgain['lines'] === $byDefault['lines'] && $sameAgain['warnings'] === $byDefault['warnings'],
    'got ' . $sameAgain['dates'] . ' date(s)'
);
unset($byDefault, $sameAgain);
$raised = $ceilingRead(3000);
st_check(
    'I30 a customer who raises the ceiling to 3,000 really does get 3,000 dates, not 2,000',
    $raised['dates'] === 3000 && $raised['capped'] === true,
    'got ' . $raised['dates'] . ', capped=' . var_export($raised['capped'], true)
);
st_check(
    'I30 and the warning quotes the raised number, not the one written in the code',
    st_has_warning($raised['warnings'], 'the portal imports (3000)') === true,
    implode(' | ', $raised['warnings'])
);
unset($raised);
foreach ([0, -1, IcsReader::MAX_EVENTS_PER_FEED_CEILING + 1, 1000000] as $silly) {
    $refused = $ceilingRead($silly);
    st_check(
        'I30 a ceiling of ' . $silly . ' is refused, not obeyed: the usual number is used and a warning says so',
        $refused['dates'] === IcsReader::MAX_EVENTS_PER_FEED
        && st_has_warning($refused['warnings'], 'which is outside what it can do') === true,
        'got ' . $refused['dates'] . ' date(s): ' . implode(' | ', $refused['warnings'])
    );
    unset($refused);
}
// I30 control: the very top of the range is accepted and used. Without this
// the range could be quietly narrowed to nothing and every check above would
// still pass. This calendar holds fewer dates than that ceiling, so all 3,650
// come back and the answer is not capped at all.
$atTheTop = $ceilingRead(IcsReader::MAX_EVENTS_PER_FEED_CEILING);
st_check(
    'I30 control: the highest ceiling allowed (' . IcsReader::MAX_EVENTS_PER_FEED_CEILING
    . ') is accepted, and this calendar then comes back whole and NOT capped',
    $atTheTop['dates'] === 3650 && $atTheTop['capped'] === false
    && st_has_warning($atTheTop['warnings'], 'which is outside what it can do') === false,
    'got ' . $atTheTop['dates'] . ', capped=' . var_export($atTheTop['capped'], true)
    . '; ' . implode(' | ', $atTheTop['warnings'])
);
unset($ceilingIcs, $ceilingParsed, $ceilingRead, $atTheTop);
// I30: and the gathering limit has to follow the ceiling, or raising it does
// nothing useful. Dates are gathered before the list is cut to size, three for
// every one that may be kept; if that stayed at the default's three thousand
// while the ceiling went up, a customer who raised it would get an answer that
// ALSO says "this calendar has far more dates than the portal imports, so it
// stopped after the first 6000 it worked out" — which is a second, invisible
// ceiling they cannot change.
//
// Twenty-five daily series is 9,125 dates, more than the 6,000 the default
// gathers. Read in a child, because nine thousand gathered dates is about
// eight megabytes and this script has to go on working afterwards.
$couplingIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
for ($i = 0; $i < 25; $i++) {
    $couplingIcs .= "BEGIN:VEVENT\r\nUID:coupling-" . $i . "@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260901T" . sprintf('%02d', 0 + $i % 24) . "0500\r\n"
        . "SUMMARY:Coupling " . $i . "\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT\r\n";
}
$couplingIcs .= "END:VCALENDAR\r\n";
$child = st_in_child($couplingIcs, '256M', '2027-09-01 00:00:00', 60.0, 60.0, 5000);
st_check(
    'I30 a raised ceiling also raises how many dates are gathered, so 5,000 really come back',
    $child['status'] === 0 && str_contains($child['text'], 'dates=5000') === true,
    'exit code ' . $child['status'] . ': ' . substr($child['text'], 0, 300)
);
st_check(
    'I30 and the answer does NOT also complain about a gathering limit the customer cannot change',
    str_contains($child['text'], 'it stopped after the first') === false,
    substr($child['text'], 0, 400)
);
unset($couplingIcs);

// I31: a repeating event whose SKIPPED-date list was cut short has its repeat
// rule thrown away, keeping only the dates the file states one by one — its
// own first date and any RDATE — and every one of those still goes through
// the ordinary checks.
//
// A cut skipped-date list does not lose dates: it BRINGS BACK dates somebody
// deliberately removed. For a church that is the worst shape there is — a
// cancelled service shown on the website as going ahead. `capped` does not
// help, because it tells the importer not to delete and says nothing about
// what it inserts.
//
// The wording above used to read "is imported as its first date only". That
// was true of the code when it was written and stopped being true when a
// sixth round of checking found the refusal was not running its one date
// through the ordinary checks at all. The checks that prove the new wording
// are further down this part, under "what a SIXTH round found".
//
// The calendar: a daily series running since 1985 with every weekend removed,
// which is 4,382 skipped dates — over the per-event allowance of 4,000. Read
// over 2026 it used to give 365 dates instead of 261, so 104 removed weekends
// came back.
$weekendIcs = static function (int $fromYear): string {
    $zone = new DateTimeZone('Europe/London');
    $out  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
        . 'UID:weekends-' . $fromYear . "@fixtures.webms.test\r\n"
        . 'DTSTART;TZID=Europe/London:' . $fromYear . "0101T090000\r\n"
        . 'DTEND;TZID=Europe/London:' . $fromYear . "0101T100000\r\n"
        . "SUMMARY:Weekday prayers\r\nRRULE:FREQ=DAILY\r\n";
    $day  = new DateTimeImmutable($fromYear . '-01-01', $zone);
    $stop = new DateTimeImmutable('2027-09-02', $zone);
    while ($day < $stop) {
        if ((int) $day->format('N') >= 6) {
            $out .= 'EXDATE;TZID=Europe/London:' . $day->format('Ymd') . "T090000\r\n";
        }
        $day = $day->modify('+1 day');
    }

    return $out . "END:VEVENT\r\nEND:VCALENDAR\r\n";
};
$r = st_inline($weekendIcs(1985));
// `complete` is checked as well as the dates, and deliberately. An empty
// answer can mean "the fix worked" or "this script had so little memory left
// that the reading step stopped at the first event" — and the second of those
// once made three of these checks pass or fail for reasons that had nothing to
// do with what they were testing.
st_check(
    'I31 the calendar was read as a whole, so what follows is about the fix and not about memory',
    $r['parse']['complete'] === true,
    'complete=' . var_export($r['parse']['complete'], true) . ' events=' . count($r['parse']['events'])
);
st_check(
    'I31 a series whose removed-date list was cut short brings NO removed date back: it contributes nothing here',
    count($r['expand']['occurrences']) === 0,
    'got ' . count($r['expand']['occurrences']) . ' date(s)'
);
st_check(
    'I31 and it says plainly that it could not tell which dates had been cancelled',
    st_has_warning($r['expand']['warnings'], 'could not tell which of its dates had been cancelled') === true
    && $r['expand']['capped'] === true,
    'capped=' . var_export($r['expand']['capped'], true) . '; ' . implode(' | ', $r['expand']['warnings'])
);
// Why "nothing here" rather than "one date": the one date kept is the series'
// OWN first date, 1 January 1985, and this script's window starts in September
// 2026. That is the same answer a repeat rule this class cannot work out gives
// today, so the two behave alike. Read over a window that does contain 1985,
// the same file gives exactly that one date and nothing else.
$oldWindow = IcsReader::expand(
    IcsReader::parse($weekendIcs(1985), microtime(true) + 60.0),
    $orgZone,
    $orgZone,
    new DateTimeImmutable('1984-01-01 00:00:00', $orgZone),
    new DateTimeImmutable('1986-01-01 00:00:00', $orgZone),
    microtime(true) + 60.0
);
st_check(
    'I31 read over a window that DOES hold its first date, that one date is what comes back',
    count($oldWindow['occurrences']) === 1
    && ($oldWindow['occurrences'][0]['start'] ?? '') === '1985-01-01 09:00:00',
    'got ' . count($oldWindow['occurrences']) . ' date(s): '
    . implode(', ', st_lines_of($oldWindow['occurrences']))
);
// I31 control: the same series since 2010 carries 1,774 removed dates, well
// inside the allowance. It must be read in full, with the weekends still
// removed and not one warning raised — otherwise this fix would be refusing
// ordinary calendars.
$r = st_inline($weekendIcs(2010));
$weekdayCount = 0;
$day          = new DateTimeImmutable('2026-09-01 00:00:00', $orgZone);
while ($day < new DateTimeImmutable('2027-09-01 00:00:00', $orgZone)) {
    if ((int) $day->format('N') <= 5) {
        $weekdayCount++;
    }
    $day = $day->modify('+1 day');
}
st_check(
    'I31 control: the same series since 2010 (1,774 removed dates) is read in full, with no warning at all',
    $r['parse']['complete'] === true && $r['expand']['warnings'] === [] && $r['expand']['capped'] === false,
    'complete=' . var_export($r['parse']['complete'], true)
    . ' capped=' . var_export($r['expand']['capped'], true) . '; ' . implode(' | ', $r['expand']['warnings'])
);
st_check(
    'I31 control: and every weekend really is still removed — ' . $weekdayCount
    . ' weekdays in the window, no Saturday or Sunday among them',
    count($r['expand']['occurrences']) === $weekdayCount
    && (static function (array $occurrences): bool {
        foreach ($occurrences as $occurrence) {
            $weekday = (int) (new DateTimeImmutable((string) $occurrence['start']))->format('N');
            if ($weekday >= 6) {
                return false;
            }
        }

        return true;
    })($r['expand']['occurrences']) === true,
    'got ' . count($r['expand']['occurrences']) . ' date(s)'
);
unset($weekendIcs, $oldWindow, $weekdayCount, $day);

// I31 continued — WHAT A SIXTH ROUND OF INDEPENDENT CHECKING FOUND.
//
// The checks above proved the refusal happens. They did NOT prove the refused
// series is treated the same way a repeat rule this reader cannot work out is
// treated, and the class header and the comment beside the code both said it
// was. It was not. The refusal handed the series' first date straight back
// instead of running it through the ordinary loop, so:
//
//  - a first date the calendar itself had CANCELLED came back as a real event
//    — the exact thing the refusal was built to stop, done to the one date it
//    kept;
//  - a first date MOVED by a change block came back at its original time and
//    under its original title, and the importer was told the calendar sends
//    the same event twice, which was untrue;
//  - dates the calendar ADDED one by one disappeared.
//
// Every check below is written as a COMPARISON against a twin file that
// reaches the same "the rule is not usable" state through an unsupported
// repeat rule (BYHOUR) instead of a cut list. That is deliberate: an expected
// list typed in by hand would have to be kept in step by whoever changes the
// reader next, and a claim of "the same treatment" is only worth anything if
// something really compares the two.
//
// These read inline rather than in a child process: 4,001 skipped dates is
// about 210 KB of keys, nothing like the two hundred thousand in I28.
$fillerExdates = static function (int $count): string {
    // Real, readable skipped dates that cancel nothing this script will see:
    // one a day from 1 January 2015, years before the series starts. Their
    // only job is to be numerous enough to use up the 4,000 allowed.
    $out = '';
    $day = new DateTimeImmutable('2015-01-01 09:00:00', new DateTimeZone('Europe/London'));
    for ($i = 0; $i < $count; $i++) {
        $out .= 'EXDATE;TZID=Europe/London:' . $day->modify('+' . $i . ' days')->format('Ymd') . "T090000\r\n";
    }

    return $out;
};
// A daily series starting 09:00 on 5 October 2026, inside this script's
// window. `$rule` decides which of the two refusals is reached; `$exdates`
// decides whether the skipped-date list is cut; `$extra` and `$after` add
// RDATE lines and a change block.
$cutSeriesIcs = static function (string $rule, string $exdates, string $extra = '', string $after = ''): string {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\nUID:cut-series@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20261005T090000\r\n"
        . "DTEND;TZID=Europe/London:20261005T100000\r\n"
        . "SUMMARY:Original morning\r\n"
        . 'RRULE:' . $rule . "\r\n"
        . $exdates . $extra
        . "END:VEVENT\r\n" . $after . "END:VCALENDAR\r\n";
};
$cancelsFirst = "EXDATE;TZID=Europe/London:20261005T090000\r\n";
$movesFirst   = "BEGIN:VEVENT\r\nUID:cut-series@fixtures.webms.test\r\n"
    . "RECURRENCE-ID;TZID=Europe/London:20261005T090000\r\n"
    . "DTSTART;TZID=Europe/London:20261005T140000\r\n"
    . "DTEND;TZID=Europe/London:20261005T150000\r\n"
    . "SUMMARY:Changed to the afternoon\r\nEND:VEVENT\r\n";
$addsTwo      = "RDATE;TZID=Europe/London:20261110T090000\r\nRDATE;TZID=Europe/London:20261111T090000\r\n";

// Shape 1: the calendar's own first skipped date cancels the series' first
// date. 4,000 filler lines follow it, so the list is cut — but the
// cancellation itself was the FIRST piece read and is certainly in hand.
$cut  = st_inline($cutSeriesIcs('FREQ=DAILY', $cancelsFirst . $fillerExdates(4000)));
$twin = st_inline($cutSeriesIcs('FREQ=DAILY;BYHOUR=9', $cancelsFirst));
st_check(
    'I31 a first date the calendar CANCELLED does not come back when the removed-date list was cut',
    count($cut['expand']['occurrences']) === 0,
    'got ' . count($cut['expand']['occurrences']) . ' date(s): '
    . implode(', ', st_lines_of($cut['expand']['occurrences']))
);
st_lines(
    'I31 and that is exactly what the same series gives through a repeat rule this reader cannot work out',
    st_lines_of($twin['expand']['occurrences']),
    st_lines_of($cut['expand']['occurrences'])
);

// Shape 2: a change block moves the first date to the afternoon. The cut
// version must show the CHANGED date, once, and must not report a duplicate.
// `st_lines_of()` carries the title and the duplicate flag, so this check
// fails on any of the three ways the old code got it wrong.
$cut  = st_inline($cutSeriesIcs('FREQ=DAILY', $fillerExdates(4001), '', $movesFirst));
$twin = st_inline($cutSeriesIcs('FREQ=DAILY;BYHOUR=9', $fillerExdates(3), '', $movesFirst));
st_lines(
    'I31 a first date MOVED by a change block is shown at its new time, not its old one, and not as a duplicate',
    st_lines_of($twin['expand']['occurrences']),
    st_lines_of($cut['expand']['occurrences'])
);
st_check(
    'I31 and the importer is not told the calendar sent the same event twice',
    count($cut['expand']['occurrences']) === 1
    && ($cut['expand']['occurrences'][0]['start'] ?? '') === '2026-10-05 14:00:00'
    && ($cut['expand']['occurrences'][0]['duplicate'] ?? true) === false
    && $cut['expand']['duplicates'] === 0,
    'duplicates=' . $cut['expand']['duplicates'] . '; '
    . implode(', ', st_lines_of($cut['expand']['occurrences']))
);

// Shape 3: dates the calendar adds one by one. Keeping them is a JUDGEMENT,
// taken on 23 September 2026 and written out in full beside the code: an
// added date is stated by the calendar's owner exactly as the first date is,
// and it is usually the replacement for something cancelled. Both files must
// give three dates — the first date and the two added ones — which also
// proves shape 1 above is not simply "the refusal returns nothing".
$cut  = st_inline($cutSeriesIcs('FREQ=DAILY', $fillerExdates(4001), $addsTwo));
$twin = st_inline($cutSeriesIcs('FREQ=DAILY;BYHOUR=9', $fillerExdates(3), $addsTwo));
st_lines(
    'I31 dates the calendar ADDS one by one survive a cut removed-date list, as they do an unusable rule',
    st_lines_of($twin['expand']['occurrences']),
    st_lines_of($cut['expand']['occurrences'])
);
st_check(
    'I31 and that really is three dates — the first one and the two added — not an empty list matching an empty list',
    count($cut['expand']['occurrences']) === 3
    && st_lines_of($cut['expand']['occurrences'])[0] !== ''
    && $cut['expand']['capped'] === true,
    'capped=' . var_export($cut['expand']['capped'], true) . '; '
    . implode(', ', st_lines_of($cut['expand']['occurrences']))
);
// And the warning must describe what really happened. It used to end "Only
// its first date was imported", which stopped being true the moment added
// dates were kept — the kind of sentence this whole round is about.
st_check(
    'I31 the warning says the repeat pattern was dropped and the one-by-one dates kept, which is what happens',
    st_has_warning(
        $cut['expand']['warnings'],
        'It left out the dates that come from the repeat pattern, and imported only the dates the calendar '
        . 'lists one by one.'
    ) === true,
    implode(' | ', $cut['expand']['warnings'])
);
unset($fillerExdates, $cutSeriesIcs, $cancelsFirst, $movesFirst, $addsTwo, $cut, $twin);

// I32: a warning must only ever be about a date the answer actually contains.
//
// A one-off used to be BUILT and then tested against the window, in that
// order. Building a date is where the "this ends after the year 9999" warning
// is raised, so a calendar holding one absurd event in the year 2099 — which
// the answer then throws away, because it is nowhere near the period asked for
// — still told the administrator that an event had had its end brought back.
// There is nothing in the list for them to look at, so there is nothing they
// can do about it, and a warning nobody can act on teaches people to ignore
// the warnings that matter.
//
// This check exists because the guard it tests had NONE. It was planted (the
// two lines put back in the old order) and the self-test still passed, which
// is the whole reason the planting is done at all.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:faraway-outside@fixtures.webms.test\r\n"
    . "DTSTART:20990101T090000Z\r\nDURATION:P999999999999W\r\n"
    . "SUMMARY:Absurd end, and nowhere near the period asked for\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:ordinary-inside@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261002T090000\r\nDTEND;TZID=Europe/London:20261002T100000\r\n"
    . "SUMMARY:An ordinary meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I32 an event outside the period asked for raises no warning about itself, because it is not in the answer',
    count($r['expand']['occurrences']) === 1
    && st_has_warning($r['expand']['warnings'], 'further ahead than dates can be stored') === false,
    'got ' . count($r['expand']['occurrences']) . ' date(s): ' . implode(' | ', $r['expand']['warnings'])
);
// I32 control: the SAME absurd end, on an event that IS inside the period, is
// still reported — otherwise this could be "fixed" by never warning at all.
// (I26 checks the end itself; this checks that the warning did not go away.)
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:faraway-inside@fixtures.webms.test\r\n"
    . "DTSTART:20261002T090000Z\r\nDURATION:P999999999999W\r\n"
    . "SUMMARY:Absurd end, inside the period\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'I32 control: the same absurd end on an event that IS in the answer is still reported',
    count($r['expand']['occurrences']) === 1
    && st_has_warning($r['expand']['warnings'], 'further ahead than dates can be stored') === true,
    'got ' . count($r['expand']['occurrences']) . ' date(s): ' . implode(' | ', $r['expand']['warnings'])
);

// -----------------------------------------------------------------------------
// I33 — WHAT A NINTH ROUND OF INDEPENDENT CHECKING FOUND: A CUT SERIES HAS NO
//       HONEST "EVERYTHING BEFORE THIS MOMENT WAS SEEN" POINT
// -----------------------------------------------------------------------------
//
// WHY THIS BLOCK EXISTS. `effectiveWindowEnd` is the one number the importer
// uses to decide what to mark as removed: it takes every stored date before
// that moment which the download did not contain. A repeating event that ran
// past the 400-dates-per-event limit used to report the last date it managed
// to keep, and that number is not trustworthy, for two separate reasons:
//
//   1. A CHANGED DATE MOVES AN OCCURRENCE. The loop walks the dates the repeat
//      pattern produces, in their original order, but what it KEEPS is the
//      moved date. So the set it ends up with is not "the earliest 400": it
//      can hold a date in December while the date it dropped sits in November.
//   2. ON THE NIGHT THE CLOCKS GO BACK, the order of moments and the order of
//      clock readings disagree. 01:30 British Summer Time happens BEFORE
//      01:15 Greenwich Mean Time, but reads as later on a clock — and the
//      importer compares clock readings, because that is what it stores.
//      This one needs no changed date at all.
//
// Either way the honest answer is that there is no such moment, so the whole
// read now reports none. Measured before the fix: twenty events still in a
// customer's calendar marked as removed in one refresh.
//
// WHAT THAT COSTS, and it is a real cost: a calendar holding one repeating
// event with more than 400 dates inside the period never sheds events through
// that refresh. An event genuinely taken out of it stays visible until a
// refresh that reads the whole period, which on such a calendar may be never.
// It is the safe direction — showing an event that has been cancelled is a
// smaller harm than hiding one that is going ahead — and it is the same trade
// already made for the three other ways of stopping early.
//
// THE GUARD AGAINST OVER-CORRECTING is the I27 control above: a calendar cut
// only by the per-calendar slice still reports its end point, so this fix
// cannot become "never report anything".

// A reader with a window wide enough to hold well over 400 daily dates. The
// fixed window the rest of this script uses is one year, which cannot show a
// 400-date cut at all.
$i33Read = static function (string $body, ?int $feedLimit = null, string $from = '2026-09-01', string $to = '2028-01-01') use ($orgZone): array {
    return IcsReader::expand(
        IcsReader::parse($body, microtime(true) + 60.0),
        $orgZone,
        $orgZone,
        new DateTimeImmutable($from . ' 00:00:00', $orgZone),
        new DateTimeImmutable($to . ' 00:00:00', $orgZone),
        microtime(true) + 60.0,
        $feedLimit
    );
};
// One identifier carrying 420 dates stated one by one. A repeat rule cannot
// reach 400 dates in the period the importer keeps (a daily rule gives at most
// 396), so a list of added dates is the shape that really reaches this limit.
$i33Series = static function (string $overrideBlock = '', string $extraEvents = ''): string {
    $body  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:i33-series@fixtures.webms.test\r\n"
        . "DTSTART:20261001T070000Z\r\nDTEND:20261001T080000Z\r\nSUMMARY:Four hundred and twenty dates\r\n";
    $first = new DateTimeImmutable('2026-10-01 07:00:00', new DateTimeZone('UTC'));
    $added = [];
    for ($i = 1; $i < 420; $i++) {
        $added[] = $first->modify('+' . $i . ' days')->format('Ymd\THis\Z');
    }
    $body .= 'RDATE:' . implode(',', $added) . "\r\nEND:VEVENT\r\n" . $overrideBlock . $extraEvents . "END:VCALENDAR\r\n";

    return $body;
};
$i33Starts = static function (array $expanded): array {
    return array_column($expanded['occurrences'], 'start');
};

// I33a: the 400th date kept is MOVED LATER by a changed-date block, so the old
// end point landed after twenty dates the limit had dropped — and every one of
// those twenty was still in the calendar.
$i33a = $i33Read($i33Series(
    "BEGIN:VEVENT\r\nUID:i33-series@fixtures.webms.test\r\nRECURRENCE-ID:20271104T070000Z\r\n"
    . "DTSTART:20271231T090000Z\r\nDTEND:20271231T100000Z\r\nSUMMARY:Moved to New Year's Eve\r\nEND:VEVENT\r\n"
));
$i33aStarts = $i33Starts($i33a);
st_check(
    'I33a a 420-date series is cut to ' . IcsReader::MAX_OCCURRENCES_PER_SERIES . ' dates and reported as cut short',
    count($i33a['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES && $i33a['capped'] === true,
    'got ' . count($i33a['occurrences']) . ' date(s), capped=' . var_export($i33a['capped'], true)
);
st_check(
    'I33a and the dates it kept really are NOT the earliest ones: 31 December 2027 is in the answer while '
    . '5 November 2027 is missing',
    in_array('2027-12-31 09:00:00', $i33aStarts, true) === true
    && in_array('2027-11-05 07:00:00', $i33aStarts, true) === false,
    'last kept: ' . (string) ($i33aStarts[count($i33aStarts) - 1] ?? '(none)')
);
st_check(
    'I33a so it reports NO "read reliably up to here" point — it used to report 2027-12-31 09:00:00, '
    . 'and an importer that believed it deleted twenty events that were still in the calendar',
    $i33a['effectiveWindowEnd'] === null,
    var_export($i33a['effectiveWindowEnd'], true)
);

// I33b: the FIRST date the limit dropped is the one a changed-date block moved
// EARLIER. This is the shape that rules out the smaller-looking fix ("report
// the ORIGINAL start of the last date kept"): the moved date is neither kept
// nor handed back on its own, so it sits before any honest end point and would
// still be deleted. The loop finds its changed-date block, marks the block as
// used, and only THEN notices it is over the limit and stops.
$i33b = $i33Read($i33Series(
    "BEGIN:VEVENT\r\nUID:i33-series@fixtures.webms.test\r\nRECURRENCE-ID:20271105T070000Z\r\n"
    . "DTSTART:20260915T120000Z\r\nDTEND:20260915T130000Z\r\nSUMMARY:Moved a year earlier\r\nEND:VEVENT\r\n"
));
$i33bStarts = $i33Starts($i33b);
st_check(
    'I33b a date the limit dropped, moved earlier by a changed-date block, is in NEITHER list: '
    . 'not kept, and not handed back on its own',
    $i33b['capped'] === true
    && count($i33b['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES
    && in_array('2026-09-15 13:00:00', $i33bStarts, true) === false,
    'got ' . count($i33b['occurrences']) . ' date(s); first is ' . (string) ($i33bStarts[0] ?? '(none)')
);
st_check(
    'I33b so it too reports NO end point — it used to report 2027-11-04 07:00:00, which is a year AFTER '
    . 'the missing date',
    $i33b['effectiveWindowEnd'] === null,
    var_export($i33b['effectiveWindowEnd'], true)
);

// I33c: both limits at once, with the per-calendar slice cutting LATER than the
// series did. The end point is the EARLIEST of its sources, so without this the
// read would simply fall back to the slice's point — which is later still, and
// deletes the same twenty dates.
$i33cExtra = '';
for ($i = 1; $i <= 10; $i++) {
    $i33cExtra .= "BEGIN:VEVENT\r\nUID:i33-late-" . $i . "@fixtures.webms.test\r\n"
        . 'DTSTART:2027120' . ($i % 10) . "T100000Z\r\nDTEND:2027120" . ($i % 10) . "T110000Z\r\n"
        . 'SUMMARY:Late one-off ' . $i . "\r\nEND:VEVENT\r\n";
}
$i33c = $i33Read(
    $i33Series(
        "BEGIN:VEVENT\r\nUID:i33-series@fixtures.webms.test\r\nRECURRENCE-ID:20271104T070000Z\r\n"
        . "DTSTART:20271231T090000Z\r\nDTEND:20271231T100000Z\r\nSUMMARY:Moved to New Year's Eve\r\nEND:VEVENT\r\n",
        $i33cExtra
    ),
    405
);
st_check(
    'I33c with BOTH limits reached — the series cut at 400 and the whole calendar sliced at 405 — the answer '
    . 'is 405 dates and is reported as cut short',
    count($i33c['occurrences']) === 405 && $i33c['capped'] === true,
    'got ' . count($i33c['occurrences']) . ' date(s), capped=' . var_export($i33c['capped'], true)
);
st_check(
    'I33c and it reports NO end point: the cut series throws the point away for the WHOLE read, so it cannot '
    . 'fall back to the slice\'s later point',
    $i33c['effectiveWindowEnd'] === null,
    var_export($i33c['effectiveWindowEnd'], true)
);

// I33d: the night the clocks go back, with NO changed date anywhere. 25 October
// 2026, 02:00 British Summer Time becomes 01:00 Greenwich Mean Time. The 400th
// date kept is 00:30 UTC, which reads as 01:30 on a clock in London; the first
// date dropped is 01:15 UTC, which reads as 01:15 — a LATER moment but an
// EARLIER clock reading, and clock readings are what the importer compares.
$i33dFirst = new DateTimeImmutable('2025-09-21 10:00:00', new DateTimeZone('UTC'));
$i33dDates = [];
for ($i = 1; $i < 399; $i++) {
    $i33dDates[] = $i33dFirst->modify('+' . $i . ' days')->format('Ymd\THis\Z');
}
$i33dDates[] = '20261025T003000Z';   // 400th by moment — 01:30 British Summer Time
$i33dDates[] = '20261025T011500Z';   // 401st by moment — 01:15 Greenwich Mean Time
foreach (['20261026T100000Z', '20261027T100000Z', '20261028T100000Z', '20261029T100000Z'] as $tail) {
    $i33dDates[] = $tail;
}
$i33d = $i33Read(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:i33-dst@fixtures.webms.test\r\n"
    . "DTSTART:20250921T100000Z\r\nDTEND:20250921T110000Z\r\nSUMMARY:Across the clock change\r\n"
    . 'RDATE:' . implode(',', $i33dDates) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    null,
    '2025-09-01'
);
$i33dStarts = $i33Starts($i33d);
st_check(
    'I33d on the night the clocks go back, the last date kept reads 01:30 and the first date dropped reads '
    . '01:15 — so the kept dates are not even in clock order',
    $i33d['capped'] === true
    && count($i33d['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES
    && in_array('2026-10-25 01:30:00', $i33dStarts, true) === true
    && in_array('2026-10-25 01:15:00', $i33dStarts, true) === false,
    'got ' . count($i33d['occurrences']) . ' date(s), capped=' . var_export($i33d['capped'], true)
);
st_check(
    'I33d and it reports NO end point — it used to report 2026-10-25 01:30:00, which would have deleted the '
    . '01:15 event with no changed date involved at all',
    $i33d['effectiveWindowEnd'] === null,
    var_export($i33d['effectiveWindowEnd'], true)
);

// I33 CONTROL 1: the fix must not have broken the expansion itself. The same
// 420-date series with no changed date anywhere still gives the EARLIEST 400
// dates, in order, and says so.
$i33plain      = $i33Read($i33Series());
$i33plainStarts = $i33Starts($i33plain);
st_check(
    'I33 control: the same series with no changed date gives the earliest 400 dates, first 2026-10-01 and '
    . 'last 2027-11-04, and warns that the later ones were left out',
    count($i33plain['occurrences']) === IcsReader::MAX_OCCURRENCES_PER_SERIES
    && ($i33plainStarts[0] ?? '') === '2026-10-01 08:00:00'
    && ($i33plainStarts[IcsReader::MAX_OCCURRENCES_PER_SERIES - 1] ?? '') === '2027-11-04 07:00:00'
    && st_has_warning($i33plain['warnings'], 'more dates in this period than the portal imports') === true,
    'first=' . (string) ($i33plainStarts[0] ?? '(none)')
    . ' last=' . (string) ($i33plainStarts[count($i33plainStarts) - 1] ?? '(none)')
);
// I33 CONTROL 2: and the cost is real and deliberate — even in that plainest
// case, where the last date kept IS the earliest 400th, no end point is
// reported. Written as its own check so that nobody reads the cost as an
// accident. The guard against this becoming "never report anything" is the
// I27 control above, which still gets a point from the per-calendar slice.
st_check(
    'I33 control: and reports no end point even so — the deliberate cost, which is that such a calendar '
    . 'sheds no removed events until a refresh reads the whole period',
    $i33plain['effectiveWindowEnd'] === null,
    var_export($i33plain['effectiveWindowEnd'], true)
);
// I33 CONTROL 3: a read of the SAME calendar over a window small enough that
// nothing is cut is untouched — complete, not capped, and the moved date is
// shown at its new time. Without this the fix could be "always say capped".
$i33small = $i33Read(
    $i33Series(
        "BEGIN:VEVENT\r\nUID:i33-series@fixtures.webms.test\r\nRECURRENCE-ID:20261105T070000Z\r\n"
        . "DTSTART:20261106T150000Z\r\nDTEND:20261106T160000Z\r\nSUMMARY:Moved to the next afternoon\r\nEND:VEVENT\r\n"
    ),
    null,
    '2026-10-01',
    '2026-11-10'
);
$i33smallStarts = $i33Starts($i33small);
st_check(
    'I33 control: the same calendar read over a period it does not overflow is complete, not cut short, '
    . 'and shows the moved date at its new time',
    $i33small['capped'] === false
    && $i33small['effectiveWindowEnd'] === null
    && in_array('2026-11-06 15:00:00', $i33smallStarts, true) === true
    && in_array('2026-11-05 07:00:00', $i33smallStarts, true) === false,
    'capped=' . var_export($i33small['capped'], true) . ' dates=' . count($i33smallStarts)
);

unset(
    $i33Read, $i33Series, $i33Starts, $i33a, $i33aStarts, $i33b, $i33bStarts, $i33c, $i33cExtra,
    $i33d, $i33dFirst, $i33dDates, $i33dStarts, $i33plain, $i33plainStarts, $i33small, $i33smallStarts
);

unset($bydayValues, $manyRules, $manyEventsWithList, $r, $child);

// =============================================================================
// J. Attachments
// =============================================================================
echo "\nJ. Attachments\n";

$r = st_read('attachments.ics');
st_check('J1 attachments.ics: both attachments were counted', $r['expand']['attachments'] === 2, 'got ' . $r['expand']['attachments']);
$wholeAnswer = var_export($r['expand'], true);
st_check(
    'J1 and no attachment address or attachment data appears anywhere in the answer',
    str_contains($wholeAnswer, 'SECRETATTACHMENTMARKER') === false
    && str_contains($wholeAnswer, 'U0VDUkVUQVRUQUNITUVO') === false,
    'the marker was found in the answer'
);

// =============================================================================
// K. Other things a real calendar file does
// =============================================================================
// These use calendars written here rather than kept as files, because each one
// is a single small point and a file per point would make the fixture folder
// harder to read than the checks themselves.
echo "\nK. Other things a real calendar file does\n";

/** Read a calendar written inside this script, under the same conditions as the fixtures. */
function st_inline(string $body): array
{
    global $orgZone, $windowStart, $windowEnd;

    $parsed = IcsReader::parse($body, microtime(true) + 30.0);

    return [
        'parse'  => $parsed,
        'expand' => IcsReader::expand($parsed, $orgZone, $orgZone, $windowStart, $windowEnd, microtime(true) + 30.0),
    ];
}

// K1: a VTIMEZONE block (which contains DTSTART lines of its own) and an alarm
// inside the event (which has its own SUMMARY and DESCRIPTION). Neither may
// touch the event. Read wrongly, every event in a calendar would be renamed
// "Reminder" or dated 1981.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VTIMEZONE\r\nTZID:Europe/London\r\n"
    . "BEGIN:DAYLIGHT\r\nDTSTART:19810329T010000\r\nTZOFFSETFROM:+0000\r\nTZOFFSETTO:+0100\r\nTZNAME:BST\r\nEND:DAYLIGHT\r\n"
    . "BEGIN:STANDARD\r\nDTSTART:19961027T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0000\r\nTZNAME:GMT\r\nEND:STANDARD\r\n"
    . "END:VTIMEZONE\r\n"
    . "BEGIN:VEVENT\r\nUID:alarm-1@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261210T190000\r\nDTEND;TZID=Europe/London:20261210T200000\r\n"
    . "SUMMARY:Real title\r\nDESCRIPTION:Real description\r\n"
    . "X-ALT-DESC;FMTTYPE=text/html:<b>HTML twin</b>\r\n"
    . "BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nSUMMARY:Alarm summary\r\nEND:VALARM\r\n"
    . "END:VEVENT\r\n"
    // A second event with NO end time, whose alarm carries a DURATION and an
    // attachment of its own. If the alarm's properties were ever read as the
    // event's, this event would come out five minutes long and the calendar
    // would report an attachment it does not have. The first event above
    // cannot show that on its own: it has its own title and description
    // already, and the first value of each wins.
    . "BEGIN:VEVENT\r\nUID:alarm-2@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261210T210000\r\nSUMMARY:No end, alarm has a length\r\n"
    . "BEGIN:VALARM\r\nTRIGGER:-PT10M\r\nACTION:AUDIO\r\nDURATION:PT5M\r\nREPEAT:2\r\n"
    . "ATTACH;VALUE=URI:Basso\r\nEND:VALARM\r\n"
    . "END:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K1 a VTIMEZONE block and an alarm inside the event change neither the event nor its date',
    [
        '2026-12-10 19:00:00|2026-12-10 20:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Real title',
        '2026-12-10 21:00:00|2026-12-10 21:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|No end, alarm has a length',
    ],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'K1 the alarm\'s own attachment is not counted as the event\'s',
    $r['expand']['attachments'] === 0,
    'got ' . $r['expand']['attachments']
);
st_check(
    'K1 the description is the event\'s own, not the alarm\'s, and the HTML twin (X-ALT-DESC) is ignored',
    ($r['expand']['occurrences'][0]['description'] ?? '') === 'Real description',
    var_export($r['expand']['occurrences'][0]['description'] ?? null, true)
);

// K2: parameters in quotation marks that hold a colon, a semicolon and a comma.
// Splitting such a line on its first colon cuts it in the wrong place, and the
// event's date and title come out as nonsense.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:quoted-1@fixtures.webms.test\r\n"
    . "DTSTART;TZID=\"Europe/London\";X-NOTE=\"a:b;c,d\":20261211T190000\r\n"
    . "DTEND;TZID=\"Europe/London\":20261211T200000\r\n"
    . "SUMMARY;ALTREP=\"https://example.org:8443/x;y\":Quoted parameters\r\n"
    . "END:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K2 a parameter in quotation marks holding a colon, a semicolon and a comma does not break the line',
    ['2026-12-11 19:00:00|2026-12-11 20:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Quoted parameters'],
    st_lines_of($r['expand']['occurrences'])
);

// K3: a web address is kept only when it really is a web address.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:url-ok@fixtures.webms.test\r\nDTSTART:20261212T120000Z\r\n"
    . "URL:https://example.org/page\r\nSUMMARY:Good link\r\nEND:VEVENT\r\n"
    // Both of these pass PHP's own URL test — that was measured, not assumed —
    // so it is the http/https rule, and nothing else, that has to refuse them.
    // The first draft used "javascript:alert(1)", which PHP's URL test rejects
    // by itself, so the check passed even with the http/https rule removed.
    . "BEGIN:VEVENT\r\nUID:url-bad@fixtures.webms.test\r\nDTSTART:20261212T130000Z\r\n"
    . "URL:javascript://example.org/%0aalert(1)\r\nSUMMARY:Bad link\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:url-ftp@fixtures.webms.test\r\nDTSTART:20261212T140000Z\r\n"
    . "URL:ftp://example.org/file\r\nSUMMARY:Not a web address\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'K3 an ordinary https address is kept',
    ($r['expand']['occurrences'][0]['url'] ?? null) === 'https://example.org/page',
    var_export($r['expand']['occurrences'][0]['url'] ?? null, true)
);
// Written the long way on purpose: `($x['url'] ?? 'something') === null` can
// never be true, because the ?? only steps in when the value IS null. The
// first draft of this check was written that way and failed on correct code.
$badLink = $r['expand']['occurrences'][1] ?? null;
st_check(
    'K3 a "javascript:" address is thrown away, because one printed into a link would run',
    $badLink !== null && $badLink['url'] === null,
    $badLink === null ? 'the second event is missing' : var_export($badLink['url'], true)
);
$ftpLink = $r['expand']['occurrences'][2] ?? null;
st_check(
    'K3 an "ftp:" address is thrown away too: only http and https are kept',
    $ftpLink !== null && $ftpLink['url'] === null,
    $ftpLink === null ? 'the third event is missing' : var_export($ftpLink['url'], true)
);

// K4: a zone name nobody knows. The event must still come out, read in the
// calendar's own zone, and the difference must be said out loud.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:badzone@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Customized Time Zone:20261213T190000\r\n"
    . "DTEND;TZID=Customized Time Zone:20261213T200000\r\n"
    . "SUMMARY:Made-up zone\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K4 a made-up zone name falls back to the calendar\'s own zone rather than being guessed at',
    ['2026-12-13 19:00:00|2026-12-13 20:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Made-up zone'],
    st_lines_of($r['expand']['occurrences'])
);
st_check(
    'K4 and a warning says so',
    st_has_warning($r['expand']['warnings'], 'Unknown time zone') === true,
    implode(' | ', $r['expand']['warnings'])
);
st_check(
    'K4 the same warning is said once, not once for the start and once for the end',
    count($r['expand']['warnings']) === 1,
    implode(' | ', $r['expand']['warnings'])
);

// K5: an end before the start, and no end at all. Neither may produce an event
// that finishes before it begins.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:badend@fixtures.webms.test\r\nDTSTART:20261214T120000Z\r\nDTEND:20261214T110000Z\r\n"
    . "SUMMARY:End before start\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:noend@fixtures.webms.test\r\nDTSTART:20261214T150000Z\r\n"
    . "SUMMARY:No end\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K5 an end before the start, and no end at all, both become "ends when it starts"',
    [
        '2026-12-14 12:00:00|2026-12-14 12:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|End before start',
        '2026-12-14 15:00:00|2026-12-14 15:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|No end',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// K6: an event with no title at all must not appear nameless on a page.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:notitle@fixtures.webms.test\r\n"
    . "DTSTART:20261215T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'K6 an event with no title is shown as "(Untitled)" rather than as nothing',
    ($r['expand']['occurrences'][0]['title'] ?? '') === '(Untitled)',
    var_export($r['expand']['occurrences'][0]['title'] ?? null, true)
);

// K7: two UIDs that differ only in their capitals are two different events.
// The identity is the hash of the exact bytes for this reason; comparing them
// as text in the database would have made them one (#514 leak-hunt finding 8).
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:AbC@fixtures.webms.test\r\nDTSTART:20261216T120000Z\r\nSUMMARY:Upper\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:abc@fixtures.webms.test\r\nDTSTART:20261216T120000Z\r\nSUMMARY:Lower\r\nEND:VEVENT\r\n"
    . "END:VCALENDAR\r\n"
);
st_check('K7 two UIDs differing only in capitals stay two events', count($r['expand']['occurrences']) === 2, 'got ' . count($r['expand']['occurrences']));
st_check('K7 and neither is counted as a repeat of the other', $r['expand']['duplicates'] === 0, 'got ' . $r['expand']['duplicates']);

// K8, K9 and K10 cover three shapes of repeat rule that no fixture file uses.
// They were added because each one is a piece of code nothing else runs, and a
// piece of code nothing runs is a piece of code nobody has checked.

// K8: a numbered weekday counted across a whole YEAR — "the second Sunday in
// the year" — which is what RFC 5545 means by BYDAY with an ordinal when no
// month is given.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:yearly-2su@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260111T150000\r\nDTEND;TZID=Europe/London:20260111T160000\r\n"
    . "RRULE:FREQ=YEARLY;BYDAY=2SU;COUNT=3\r\nSUMMARY:Second Sunday of the year\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K8 "the second Sunday in the year" gives 10 January 2027 (11 January 2026 and 9 January 2028 are outside the window)',
    ['2027-01-10 15:00:00|2027-01-10 16:00:00|timed|key=20270110T150000Z|series|priv=no|canc=no|dup=no|Second Sunday of the year'],
    st_lines_of($r['expand']['occurrences'])
);

// K9: a daily rule narrowed by BYDAY and BYMONTH — "every weekday in
// December". Daily rules with BY… parts are read by a different piece of code
// from weekly or monthly ones.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:daily-narrowed@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261201T080000\r\nDTEND;TZID=Europe/London:20261201T083000\r\n"
    . "RRULE:FREQ=DAILY;BYMONTH=12;BYDAY=MO,TU,WE,TH,FR;UNTIL=20261209T235959Z\r\n"
    . "SUMMARY:Advent weekday prayers\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K9 a daily rule narrowed to weekdays in December skips the weekend of 5 and 6 December',
    [
        '2026-12-01 08:00:00|2026-12-01 08:30:00|timed|key=20261201T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
        '2026-12-02 08:00:00|2026-12-02 08:30:00|timed|key=20261202T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
        '2026-12-03 08:00:00|2026-12-03 08:30:00|timed|key=20261203T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
        '2026-12-04 08:00:00|2026-12-04 08:30:00|timed|key=20261204T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
        '2026-12-07 08:00:00|2026-12-07 08:30:00|timed|key=20261207T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
        '2026-12-08 08:00:00|2026-12-08 08:30:00|timed|key=20261208T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
        '2026-12-09 08:00:00|2026-12-09 08:30:00|timed|key=20261209T080000Z|series|priv=no|canc=no|dup=no|Advent weekday prayers',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// K10: BYMONTHDAY, including a negative number, which counts back from the end
// of the month. -1 is the last day, whether the month has 28, 30 or 31 days.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:bymonthday@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261231T120000\r\nDTEND;TZID=Europe/London:20261231T130000\r\n"
    . "RRULE:FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=3\r\nSUMMARY:Last day of the month\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K10 BYMONTHDAY=-1 is the last day of each month, whatever its length (December 31, January 31, February 28)',
    [
        '2026-12-31 12:00:00|2026-12-31 13:00:00|timed|key=20261231T120000Z|series|priv=no|canc=no|dup=no|Last day of the month',
        '2027-01-31 12:00:00|2027-01-31 13:00:00|timed|key=20270131T120000Z|series|priv=no|canc=no|dup=no|Last day of the month',
        '2027-02-28 12:00:00|2027-02-28 13:00:00|timed|key=20270228T120000Z|series|priv=no|canc=no|dup=no|Last day of the month',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// K11: the worked example RFC 5545 prints for WKST, in section 3.3.10, with the
// standard's own dates. The same rule is read twice, changing nothing but which
// day the week starts on, and the two answers must be the two the standard
// gives: 5, 10, 19 and 24 August 1997 with the week starting on Monday, and
// 5, 17, 19 and 31 August with it starting on Sunday. Checking against the
// standard's own example matters here because this is the one rule part where
// "it looks about right" is no test at all - both answers look equally
// reasonable. The times read 14:00 because 09:00 in New York in August is
// 14:00 in London, and the window is moved back to 1997 for this check alone.
$rfcWkst = static function (string $weekStart) use ($orgZone): array {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:rfc-wkst-" . $weekStart . "@fixtures.webms.test\r\n"
        . "DTSTART;TZID=America/New_York:19970805T090000\r\nDTEND;TZID=America/New_York:19970805T100000\r\n"
        . 'RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=' . $weekStart . "\r\n"
        . 'SUMMARY:RFC example ' . $weekStart . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $parsed = IcsReader::parse($ics, microtime(true) + 30.0);

    return IcsReader::expand(
        $parsed,
        $orgZone,
        $orgZone,
        new DateTimeImmutable('1997-08-01 00:00:00', $orgZone),
        new DateTimeImmutable('1997-09-30 00:00:00', $orgZone),
        microtime(true) + 30.0
    );
};
st_lines(
    'K11 RFC 5545 section 3.3.10, week starting Monday: 5, 10, 19 and 24 August 1997',
    [
        '1997-08-05 14:00:00|1997-08-05 15:00:00|timed|key=19970805T130000Z|series|priv=no|canc=no|dup=no|RFC example MO',
        '1997-08-10 14:00:00|1997-08-10 15:00:00|timed|key=19970810T130000Z|series|priv=no|canc=no|dup=no|RFC example MO',
        '1997-08-19 14:00:00|1997-08-19 15:00:00|timed|key=19970819T130000Z|series|priv=no|canc=no|dup=no|RFC example MO',
        '1997-08-24 14:00:00|1997-08-24 15:00:00|timed|key=19970824T130000Z|series|priv=no|canc=no|dup=no|RFC example MO',
    ],
    st_lines_of($rfcWkst('MO')['occurrences'])
);
st_lines(
    'K11 the same rule with the week starting Sunday: 5, 17, 19 and 31 August 1997',
    [
        '1997-08-05 14:00:00|1997-08-05 15:00:00|timed|key=19970805T130000Z|series|priv=no|canc=no|dup=no|RFC example SU',
        '1997-08-17 14:00:00|1997-08-17 15:00:00|timed|key=19970817T130000Z|series|priv=no|canc=no|dup=no|RFC example SU',
        '1997-08-19 14:00:00|1997-08-19 15:00:00|timed|key=19970819T130000Z|series|priv=no|canc=no|dup=no|RFC example SU',
        '1997-08-31 14:00:00|1997-08-31 15:00:00|timed|key=19970831T130000Z|series|priv=no|canc=no|dup=no|RFC example SU',
    ],
    st_lines_of($rfcWkst('SU')['occurrences'])
);

// K12: the yearly rules this class refuses rather than guesses at. A numbered
// weekday is counted across the whole year, a plain one within each month, and
// a day of the month within each month - so putting a numbered weekday
// alongside either of the others, with no month named, has no single reading.
// The answer must be the series' first date and a warning, which is what every
// other unsupported rule gets. The shapes on their own are supported and are
// checked by fixture (C11) and by K8.
foreach (['FREQ=YEARLY;BYDAY=2SU,FR;COUNT=10', 'FREQ=YEARLY;BYDAY=2SU;BYMONTHDAY=3;COUNT=10'] as $mixedRule) {
    $r = st_inline(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:yearly-mixed@fixtures.webms.test\r\n"
        . "DTSTART;TZID=Europe/London:20260906T100000\r\nDTEND;TZID=Europe/London:20260906T110000\r\n"
        . 'RRULE:' . $mixedRule . "\r\nSUMMARY:Mixed yearly rule\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    );
    st_lines(
        'K12 ' . $mixedRule . ': the first date only',
        ['2026-09-06 10:00:00|2026-09-06 11:00:00|timed|key=20260906T090000Z|series|priv=no|canc=no|dup=no|Mixed yearly rule'],
        st_lines_of($r['expand']['occurrences'])
    );
    st_check(
        'K12 ' . $mixedRule . ': and a warning saying it was not worked out',
        st_has_warning($r['expand']['warnings'], 'mixes a numbered weekday') === true,
        implode(' | ', $r['expand']['warnings'])
    );
}

// K13: a monthly series that starts on the 31st. A month with no 31st gives no
// date at all — it is NOT moved to the 30th, the 28th, or forward into the next
// month. Every fixture that repeats monthly picks its day with BYDAY ("the last
// Friday"), so the plain same-day-of-the-month path was never exercised from a
// day that does not exist in every month. Clamped to the end of the month
// instead, a monthly payment reminder set for the 31st would appear on
// 28 February and 30 April as well, which the calendar never said.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:monthly-31st@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261031T090000\r\nDTEND;TZID=Europe/London:20261031T100000\r\n"
    . "RRULE:FREQ=MONTHLY;COUNT=5\r\nSUMMARY:On the 31st\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K13 a monthly series starting on the 31st skips the months that have no 31st, rather than moving the date',
    [
        '2026-10-31 09:00:00|2026-10-31 10:00:00|timed|key=20261031T090000Z|series|priv=no|canc=no|dup=no|On the 31st',
        '2026-12-31 09:00:00|2026-12-31 10:00:00|timed|key=20261231T090000Z|series|priv=no|canc=no|dup=no|On the 31st',
        '2027-01-31 09:00:00|2027-01-31 10:00:00|timed|key=20270131T090000Z|series|priv=no|canc=no|dup=no|On the 31st',
        '2027-03-31 09:00:00|2027-03-31 10:00:00|timed|key=20270331T080000Z|series|priv=no|canc=no|dup=no|On the 31st',
        '2027-05-31 09:00:00|2027-05-31 10:00:00|timed|key=20270531T080000Z|series|priv=no|canc=no|dup=no|On the 31st',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// K14: a timed series whose UNTIL is written as a plain date, with no time.
// RFC 5545 allows it, and it has to mean the whole of that day: read as
// midnight at the start of the day, the last date of the series is dropped.
// No fixture has a date-only UNTIL on a timed series.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:until-date-only@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20261001T190000\r\nDTEND;TZID=Europe/London:20261001T200000\r\n"
    . "RRULE:FREQ=WEEKLY;UNTIL=20261029\r\nSUMMARY:Ends on a plain date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
$untilLines = st_lines_of($r['expand']['occurrences']);
st_check(
    'K14 a date-only UNTIL covers the whole of that day, so the 29 October date is kept (five dates, not four)',
    count($untilLines) === 5,
    'got ' . count($untilLines) . ': ' . implode(' | ', $untilLines)
);
st_check(
    'K14 and the last of them really is 29 October',
    str_starts_with($untilLines[count($untilLines) - 1] ?? '', '2026-10-29 19:00:00') === true,
    $untilLines[count($untilLines) - 1] ?? '(none)'
);

// K15: the two ends of the window. The plan says the window END is INCLUSIVE —
// an event starting at the exact moment the window ends is inside it. Made
// exclusive, a refresh would drop the event on the boundary every time and
// nobody would see a pattern in it.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\nUID:window-end@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20270901T000000\r\nDTEND;TZID=Europe/London:20270901T010000\r\n"
    . "SUMMARY:Exactly on the end of the window\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:window-start@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20260901T000000\r\nDTEND;TZID=Europe/London:20260901T010000\r\n"
    . "SUMMARY:Exactly on the start of the window\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:window-just-past@fixtures.webms.test\r\n"
    . "DTSTART;TZID=Europe/London:20270901T000001\r\nDTEND;TZID=Europe/London:20270901T010001\r\n"
    . "SUMMARY:One second past the end\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_lines(
    'K15 both ends of the window are inclusive, and one second past the end is out',
    [
        '2026-09-01 00:00:00|2026-09-01 01:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Exactly on the start of the window',
        '2027-09-01 00:00:00|2027-09-01 01:00:00|timed|key=|one-off|priv=no|canc=no|dup=no|Exactly on the end of the window',
    ],
    st_lines_of($r['expand']['occurrences'])
);

// K16: `\N` in capitals is an escape for a new line, exactly as `\n` is
// (RFC 5545 section 3.3.11 gives both). Microsoft writes the capital form.
// Left alone, a description arrives with a stray capital N stuck between two
// sentences.
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:escape-capital-n@fixtures.webms.test\r\n"
    . "DTSTART:20261002T120000Z\r\nDTEND:20261002T130000Z\r\nSUMMARY:Capital escape\r\n"
    . "DESCRIPTION:Line one\\NLine two\\nLine three\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'K16 both \\n and \\N are read as a new line, so the description has three lines and no stray letters',
    ($r['expand']['occurrences'][0]['description'] ?? '') === "Line one\nLine two\nLine three",
    var_export($r['expand']['occurrences'][0]['description'] ?? null, true)
);

// K17: a field that is not valid UTF-8 keeps the characters that ARE valid and
// loses only the bad bytes. This was a comment that promised more than the code
// gave — the code threw away every byte above 127 the moment one of them was
// wrong, so `Zoë café` lost both its accents over one stray byte somewhere else
// in the line. The code was brought up to the comment, and nothing checked it
// until now. A Latin-1 file is the case this still cannot do anything with,
// and that is checked too, so the limit is recorded rather than assumed.
$strayByte = "\xFF";
$r = st_inline(
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:bad-bytes@fixtures.webms.test\r\n"
    . "DTSTART:20261003T120000Z\r\nDTEND:20261003T130000Z\r\n"
    . "SUMMARY:Zo\xC3\xAB caf\xC3\xA9 " . $strayByte . " end\r\n"
    // A Latin-1 e-acute (one byte, 0xE9) is not valid UTF-8 at all.
    . "LOCATION:Caf\xE9\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
);
st_check(
    'K17 one stray byte costs that byte only — the accented letters beside it survive',
    ($r['expand']['occurrences'][0]['title'] ?? '') === "Zoë café end",
    var_export($r['expand']['occurrences'][0]['title'] ?? null, true)
);
st_check(
    'K17 and what it CANNOT do is recorded: a Latin-1 field loses its accented letter, because no byte of it is valid UTF-8',
    ($r['expand']['occurrences'][0]['location'] ?? '') === 'Caf',
    var_export($r['expand']['occurrences'][0]['location'] ?? null, true)
);
st_check(
    'K17 and everything that comes out is valid UTF-8, so no later page can be broken by it',
    preg_match('//u', (string) ($r['expand']['occurrences'][0]['title'] ?? '')) === 1
    && preg_match('//u', (string) ($r['expand']['occurrences'][0]['location'] ?? '')) === 1
);

// =============================================================================
// L. The Windows zone list
// =============================================================================
echo "\nL. The Windows zone list\n";

st_check('L1 "GMT Standard Time" is Europe/London', WindowsTimeZones::toIana('GMT Standard Time') === 'Europe/London', var_export(WindowsTimeZones::toIana('GMT Standard Time'), true));
st_check('L1 "Eastern Standard Time" is America/New_York', WindowsTimeZones::toIana('Eastern Standard Time') === 'America/New_York', var_export(WindowsTimeZones::toIana('Eastern Standard Time'), true));
st_check('L2 capital letters do not matter', WindowsTimeZones::toIana('gmt standard time') === 'Europe/London', var_export(WindowsTimeZones::toIana('gmt standard time'), true));
st_check('L2 surrounding spaces do not matter', WindowsTimeZones::toIana('  GMT Standard Time  ') === 'Europe/London');
st_check('L3 a name nobody knows gives null, and is never guessed at', WindowsTimeZones::toIana('Customized Time Zone') === null, var_export(WindowsTimeZones::toIana('Customized Time Zone'), true));
st_check('L3 an empty name gives null', WindowsTimeZones::toIana('') === null);
st_check('L3 an ordinary name is NOT accepted here (pass those straight to DateTimeZone)', WindowsTimeZones::toIana('Europe/London') === null, var_export(WindowsTimeZones::toIana('Europe/London'), true));
st_check('L4 the list is a real list, not an empty one left behind by a failed run', count(WindowsTimeZones::MAP) > 100, 'it holds ' . count(WindowsTimeZones::MAP) . ' names');
$everyValueIsAZone = true;
foreach (WindowsTimeZones::MAP as $windowsName => $ianaName) {
    try {
        new DateTimeZone($ianaName);
    } catch (Exception $e) {
        $everyValueIsAZone = false;
        echo '        ' . $windowsName . ' => ' . $ianaName . " is not a zone PHP knows\n";
    }
}
st_check('L4 every name in the list is one this PHP understands', $everyValueIsAZone === true);

// L5 runs the generator's own --check, but ONLY when this machine's ICU is
// the SAME version the committed list was generated from. `--check` proves
// "the list still matches THIS machine's ICU" — and that only means "the
// list is still correct" when the two ICU versions are one and the same. A
// different ICU version can legitimately disagree with the committed list
// for reasons that are not a fault here at all (a country changed its rules,
// or ICU renamed the default zone for a Windows name) — see
// generate-windows-timezones.php's own "WHAT THIS SCRIPT CANNOT DO". Running
// `--check` against a DIFFERENT ICU version and reporting FAIL would be
// dishonest: it would read as "the list is wrong" when the truth is "this
// machine cannot judge that list at all".
//
// FOUND round 1 of the workflow-package independent check (25 September
// 2026): this check used to run --check on ANY machine with the intl
// extension, whatever ICU version it carried. GitHub's own runner has
// ICU 74.2, not the 78.3 the committed list was generated from, so this
// check FAILED there on entirely correct code — exactly the dishonest
// result described above. Fixed by reading the ICU version the list was
// generated from out of `WindowsTimeZones.php`'s own header comment ("Generated
// on ... from ICU version X.Y"), rather than writing "78.3" a second time in
// this file where it could quietly drift out of step with the real header,
// and comparing it with `INTL_ICU_VERSION` (the version this machine's intl
// extension actually has). A mismatch is SKIPPED, with both versions named,
// never a silent pass and never an unfair FAIL. It needs the intl extension
// at all; without it nothing can be compared, so it prints SKIPPED and the
// list is NOT checked against ICU on this machine either.
if (extension_loaded('intl') === true && method_exists('IntlTimeZone', 'getIanaID') === false) {
    // #557 round 1 (LOW-2): the generator's `--check` now applies the same
    // #557 rename (`IntlTimeZone::getIanaID()`) in BOTH modes — see
    // generate-windows-timezones.php's own pre-loop refusal. On a machine
    // without that method (PHP 8.3 or older) the generator refuses outright
    // rather than running `--check` at all, so calling it here would come
    // back FAIL every time, even when the committed list is perfectly
    // correct — purely because THIS machine cannot run the comparison, not
    // because the list is wrong. That is the exact same dishonest shape the
    // ICU-version-mismatch branch below exists to avoid, so it gets the
    // same answer: SKIPPED, with the reason named, checked BEFORE the ICU
    // version comparison so a version match can never mask it.
    //
    // FOUND round 1 of #557's independent check (25 September 2026):
    // simulated on Ubuntu 24.04 / PHP 8.3.33 / ICU 74.2, with the recorded
    // ICU version forced to match this machine's (so the version-match
    // branch below would otherwise have run) — this used to FAIL, printing
    // the generator's own refusal message as if it were a real mismatch.
    st_skip(
        'L5 the committed list against this machine\'s ICU',
        'the generator needs IntlTimeZone::getIanaID() (needs PHP 8.4 or newer built with ICU 74 or newer) to run --check at all, and this machine\'s PHP does not have it'
    );
} elseif (extension_loaded('intl') === true) {
    $windowsTimeZonesFile   = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web'
        . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'WindowsTimeZones.php';
    $windowsTimeZonesHeader = (string) file_get_contents($windowsTimeZonesFile);
    $recordedIcuVersion     = null;
    if (preg_match('/Generated on .+ from ICU version ([0-9]+(?:\.[0-9]+)*)/', $windowsTimeZonesHeader, $icuMatch) === 1) {
        $recordedIcuVersion = $icuMatch[1];
    }

    if ($recordedIcuVersion === null) {
        // The header no longer says what ICU version the list was generated
        // from. That is a fault in the generated file's own header, not in
        // this machine's ICU, so it is reported as a real failure rather
        // than skipped — a missing version number must never look like "the
        // versions matched".
        st_check(
            'L5 the committed list still matches what this machine\'s ICU would generate',
            false,
            'could not find "Generated on ... from ICU version X.Y" in ' . $windowsTimeZonesFile . '\'s header comment'
        );
    } elseif ($recordedIcuVersion !== INTL_ICU_VERSION) {
        st_skip(
            'L5 the committed list against this machine\'s ICU',
            'the list was generated from ICU ' . $recordedIcuVersion . ', this machine has ICU '
                . INTL_ICU_VERSION . ' — comparing different ICU versions would not be a fair test'
        );
    } else {
        $generator = __DIR__ . DIRECTORY_SEPARATOR . 'generate-windows-timezones.php';
        $command   = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($generator) . ' --check 2>&1';
        $output    = [];
        $status    = 1;
        exec($command, $output, $status);
        st_check(
            'L5 the committed list still matches what this machine\'s ICU would generate',
            $status === 0,
            implode("\n        ", $output)
        );
    }
} else {
    st_skip('L5 the committed list against this machine\'s ICU', 'the intl extension is not installed here, so there is nothing to compare with');
}

// L6: #557 — every value in the list must be the CURRENT spelling of its
// zone, not merely a name this machine's PHP happens to still accept.
// L4 above could NOT have caught issue #557 on its own: `new
// DateTimeZone('Asia/Calcutta')` succeeds perfectly well on a machine
// whose own PHP was built with a zone database that still carries the old
// names (this Mac is one — its `date` extension uses its OWN bundled copy,
// not the operating system's; `php -i` shows `Timezone Database =>
// internal`), so L4 passed the whole time the committed list held all
// seven old spellings — the fault only showed up on a server built
// without the optional `tzdata-legacy` package. This check asks ICU the
// same question the generator itself now asks before writing anything:
// `IntlTimeZone::getIanaID($value) === $value`. It needs the intl
// extension with `getIanaID()` (needs PHP 8.4 or newer built with ICU 74
// or newer); without it there is nothing to ask, so this is SKIPPED,
// never PASSED — a machine
// that cannot check a thing must never be reported as having checked it
// and found it fine. This stops an old name creeping back in if the
// generator is ever run without the #557 conversion, or the committed
// file is ever hand-edited despite the warning in its own header.
if (extension_loaded('intl') === true && method_exists('IntlTimeZone', 'getIanaID') === true) {
    // #557 round 1 (LOW-1): a value ICU gives NO answer for at all is not
    // the same thing as a value ICU says is OLD. `getIanaID()` returning
    // false/empty means this machine's ICU has never heard of the zone —
    // which happens when the committed list already holds a name NEWER
    // than this machine's own ICU tables know, exactly as much a
    // "this machine cannot judge it" case as the ICU-version mismatch L5
    // exists to handle above. FAILING L6 for that would be dishonest in
    // the same way: it would read as "the list is wrong" when the truth
    // is "this machine cannot check THIS ONE value". So those values are
    // set aside as UNCHECKED and reported in their own SKIPPED line,
    // never folded into a pass and never treated as a fail. L6 only FAILS
    // when ICU gives a DIFFERENT, non-empty name — a real, checkable
    // disagreement.
    //
    // FOUND round 1 of #557's independent check (25 September 2026):
    // simulated by planting `Magallanes Standard Time => America/Coyhaique`
    // (a real zone, added to IANA after some ICU builds) into a scratch
    // copy of the list and running on Ubuntu 24.04 / PHP 8.4.26 / ICU
    // 74.2, whose SYSTEM zone data (tzdata) accepts the zone (so L4
    // passed) even though its OWN ICU tables have no `getIanaID()` answer
    // for it at all — this used to FAIL L6 on entirely correct code.
    // getIanaID() is an ICU lookup table, not a tzdata one, so it is ICU's
    // version, not the exact tzdata release, that decides this.
    $staleNames  = [];
    $uncheckable = [];
    foreach (WindowsTimeZones::MAP as $windowsName => $ianaName) {
        $currentName = IntlTimeZone::getIanaID($ianaName);
        if (is_string($currentName) === false || $currentName === '') {
            $uncheckable[] = $windowsName . ' => ' . $ianaName;
            continue;
        }
        if ($currentName !== $ianaName) {
            $staleNames[] = $windowsName . ' => ' . $ianaName . ' is not current; ICU\'s current name is ' . $currentName;
        }
    }
    foreach ($staleNames as $staleLine) {
        echo '        ' . $staleLine . "\n";
    }
    // #557 round 2 (LOW-3): when ICU could not answer for a SINGLE value in
    // the whole list, nothing was actually checked — $staleNames stays
    // empty not because every value is confirmed current, but because the
    // loop above never had anything it could compare. Reporting that as
    // PASS would say "checked and found fine" about a run that checked
    // nothing at all, exactly the dishonest shape this whole check exists
    // to avoid elsewhere (see the ICU-version-mismatch note on L5, above).
    // So this one case is reported as SKIPPED, with every value named,
    // instead of the usual PASS/FAIL plus a separate "some left unchecked"
    // line.
    $totalMapValues = count(WindowsTimeZones::MAP);
    if ($uncheckable !== [] && count($uncheckable) === $totalMapValues) {
        st_skip(
            'L6 every value in the list is the CURRENT name ICU would give it today (#557)',
            'this machine\'s ICU (version ' . INTL_ICU_VERSION . ') could not answer for any of the '
                . $totalMapValues . ' values in the list, so nothing was actually checked: '
                . implode(', ', $uncheckable)
        );
    } else {
        st_check('L6 every value in the list is the CURRENT name ICU would give it today (#557)', $staleNames === []);
        if ($uncheckable !== []) {
            st_skip(
                'L6 ' . count($uncheckable) . ' value(s) this machine\'s ICU (version ' . INTL_ICU_VERSION . ') could not check at all',
                'ICU gave no answer, so left unchecked rather than counted as a pass or a fail: ' . implode(', ', $uncheckable)
            );
        }
    }
} else {
    st_skip(
        'L6 every value in the list is the CURRENT name ICU would give it today (#557)',
        'needs the intl extension with IntlTimeZone::getIanaID() (needs PHP 8.4 or newer built with ICU 74 or newer), which this machine does not have'
    );
}

// =============================================================================
// M. Real exports from Google and Microsoft 365
// =============================================================================
echo "\nM. Real exports from Google and Microsoft 365\n";

/**
 * What a real export must produce, if one is ever captured (plan P5, proof 14).
 *
 * The calendar holds a weekly event "Test weekly", every Tuesday 19:00-20:00
 * UK time from 6 October 2026, with 13 October deleted, 20 October moved to
 * 20:00 and renamed, and 27 October cancelled; plus a one-off private event.
 * The United Kingdom's clocks change on 25 October, inside that run.
 */
$realExports = [
    'real-google-test.ics' => 'Google Calendar',
    'real-m365-test.ics'   => 'Microsoft 365',
];
foreach ($realExports as $exportName => $product) {
    $exportPath = $fixtureDir . DIRECTORY_SEPARATOR . $exportName;
    if (is_file($exportPath) === false) {
        st_skip(
            'M ' . $product . ' (' . $exportName . ')',
            'SKIPPED: real export not captured (owner question 5). Acceptance criterion "works against a real '
            . 'Google or Microsoft 365 calendar" is NOT proven'
        );
        continue;
    }

    $GLOBALS['st_used_fixtures'][$exportName] = true;
    $realParsed   = IcsReader::parse((string) file_get_contents($exportPath), microtime(true) + 30.0);
    $realExpanded = IcsReader::expand($realParsed, $orgZone, $orgZone, $windowStart, $windowEnd, microtime(true) + 30.0);

    $weekly = [];
    foreach ($realExpanded['occurrences'] as $occurrence) {
        if (str_starts_with((string) $occurrence['title'], 'Test weekly') === true) {
            $weekly[] = $occurrence;
        }
    }

    // 27 October may come back either as nothing at all or as one cancelled
    // date; both are correct, because the two products cancel a single date in
    // different ways.
    $live = [];
    foreach ($weekly as $occurrence) {
        if ($occurrence['cancelled'] === false) {
            $live[] = $occurrence;
        }
    }
    st_lines(
        'M ' . $product . ': the three dates that should still be showing',
        [
            '2026-10-06 19:00:00|key=20261006T180000Z|Test weekly',
            '2026-10-20 20:00:00|key=20261020T180000Z|Test weekly (moved)',
            '2026-11-03 19:00:00|key=20261103T190000Z|Test weekly',
        ],
        array_map(static function (array $o): string {
            return $o['start'] . '|key=' . $o['recurrenceKey'] . '|' . $o['title'];
        }, $live)
    );
    $thirteenth = false;
    foreach ($weekly as $occurrence) {
        if (str_starts_with((string) $occurrence['start'], '2026-10-13') === true) {
            $thirteenth = true;
        }
    }
    st_check('M ' . $product . ': the deleted date (13 October) is not there at all', $thirteenth === false);

    $privateOnes = [];
    foreach ($realExpanded['occurrences'] as $occurrence) {
        if (str_starts_with((string) $occurrence['title'], 'Test private') === true) {
            $privateOnes[] = $occurrence;
        }
    }
    st_check(
        'M ' . $product . ': the private event is either left out of the export or marked private',
        $privateOnes === [] || ($privateOnes[0]['private'] === true),
        'found ' . count($privateOnes) . ' and the first is '
        . (($privateOnes[0]['private'] ?? false) === true ? 'private' : 'NOT private')
    );
    st_check(
        'M ' . $product . ': no "unknown time zone" warning',
        st_has_warning($realExpanded['warnings'], 'Unknown time zone') === false,
        implode(' | ', $realExpanded['warnings'])
    );
}

// =============================================================================
// N. Housekeeping
// =============================================================================
echo "\nN. Housekeeping\n";

// Every fixture file must be used by a check. Without this, somebody can add a
// fixture, believe it is being checked, and be wrong — the quietest kind of
// gap there is.
$onDisk = glob($fixtureDir . DIRECTORY_SEPARATOR . '*.ics');
$onDisk = $onDisk === false ? [] : $onDisk;
$unused = [];
foreach ($onDisk as $path) {
    $name = basename($path);
    if (isset($GLOBALS['st_used_fixtures'][$name]) === false) {
        $unused[] = $name;
    }
}
st_check(
    'N1 every one of the ' . count($onDisk) . ' fixture files is used by a check above',
    $unused === [],
    'not used: ' . implode(', ', $unused)
);

st_check(
    'N2 no PHP warning or deprecation was raised while testing',
    $GLOBALS['st_php_warnings'] === [],
    implode('; ', $GLOBALS['st_php_warnings'])
);

// =============================================================================
// Summary
// =============================================================================
echo sprintf("\n%d passed, %d failed, %d skipped.\n", $GLOBALS['st_pass'], $GLOBALS['st_fail'], $GLOBALS['st_skip']);
if ($GLOBALS['st_skip'] > 0) {
    echo "A skipped check is NOT a pass: read the reason printed beside it.\n";
}
exit($GLOBALS['st_fail'] === 0 ? 0 : 1);
