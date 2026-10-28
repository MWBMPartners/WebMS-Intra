<?php
// Path: tools/ics-zone-sweep.php
/**
 * -----------------------------------------------------------------------------
 * Every time zone, every ambiguous clock reading — the sweep 🌍🕰️
 * -----------------------------------------------------------------------------
 * @package   WebMS-Intra (development tooling — never deployed; only web/ is)
 * @author    Salem874
 * @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
 * @version   1.0.0 (28 October 2026)
 *
 * WHY THIS EXISTS, AND WHY IT RUNS ON EVERY PULL REQUEST
 * -----------------------------------------------------
 * `Portal\Core\IcsReader::laterOfARepeatedReading()` decides what a clock
 * reading in the hour that happens TWICE means — the night a country's clocks
 * go back, when one reading names two moments. It must give the LATEST moment
 * carrying that reading, in every zone on Earth.
 *
 * That one function shipped as a NO-OP THREE TIMES (#653), and every time the
 * whole of `tools/ics-reader-selftest.php` passed:
 *
 *   1. It judged the reading in the ORGANISATION's zone, so a time stated in
 *      UTC — an instant, not a reading — was moved an hour. Caught by
 *      `tools/feed-importer-selftest.php` part G, which needs a database.
 *   2. It trusted `new DateTimeImmutable($text, $zone)` to give the later of
 *      the two. PHP gives standard time in Europe and Australasia but DAYLIGHT
 *      time — the EARLIER — across the Americas, so the fix did nothing at all
 *      there. Caught by an independent review.
 *   3. It built each candidate with `setTimestamp()`, which on a zone-carrying
 *      object re-resolves through the wall clock and hands back a DIFFERENT
 *      instant when the one asked for is inside a repeated hour. Caught by a
 *      sweep like this one.
 *
 * The self-test now pins the shapes that were each found broken once — an
 * ordinary summer-time change, a zone that resolves the other way, a
 * thirty-minute change, a permanent base-offset move, an ambiguous `DTSTART`.
 * **Those are five points in a very large space.** This file is the instrument
 * that searches the space, and it is what found fault 3.
 *
 * IT RUNS ON EVERY PULL REQUEST, at `--dense`, and the reason is worth stating
 * because the first version of this header argued the opposite. It said the
 * sweep was too slow to wire in — "tens of millions of comparisons, minutes".
 * **Measured: `--dense` covers 419 zones, 334,240 readings and 145,265
 * ambiguous ones in 1.4 seconds.** That guess was wrong by two orders of
 * magnitude, and it was about to leave the only instrument that catches two of
 * the three faults above sitting in a drawer — which is precisely what #646 was
 * about (ten checks and two tests that guarded faults already made, and ran
 * nowhere).
 *
 * **RUN IT `--dense`, NOT LIGHT.** Light sampling starts at 1900 and tries four
 * readings per transition, and it MISSES the pre-filter form of fault 3
 * entirely: the readings that expose it sit a whole DAY apart, at the 1867
 * Alaska, 1892 Samoa and 1899 Rarotonga moves across the date line, where the
 * clocks went back a full 86,400 seconds. Planting that fault was caught by
 * `--dense` and by nothing else at all — not by the light sweep, and not by any
 * of the 368 checks in `tools/ics-reader-selftest.php`.
 *
 * The one real cost of this file is that it reaches a PRIVATE method by
 * reflection, so it pins an implementation detail rather than behaviour. If
 * that method is RENAMED OR REMOVED, this REFUSES loudly rather than passing
 * quietly — the failure mode that matters. (An earlier draft said "or made
 * public" as well. That is not true and was measured: `method_exists()` is
 * true whatever the visibility, so making it public changes nothing here and
 * the sweep goes on working. Saying otherwise would have promised a tripwire
 * that does not exist.)
 *
 * WHAT IT CANNOT TELL YOU. It compares the helper against a reference built
 * from the same time-zone data, so it proves the rule is applied consistently
 * — not that the rule is the right one to have chosen. It also says nothing
 * about how a resolved start is USED: the length of an event is measured
 * elsewhere, and getting that wrong stored a one-hour New York event as two
 * (check I34k). A clean sweep here is necessary and not sufficient.
 *
 * USAGE
 *   php tools/ics-zone-sweep.php             one reading each side of every
 *                                            transition, 1900-2050 (seconds)
 *   php tools/ics-zone-sweep.php --dense     many readings per transition and
 *                                            1850-2100 (about 1.4 seconds —
 *                                            THIS is the one to run)
 *   php tools/ics-zone-sweep.php --zone=America/Metlakatla --dense
 *
 * It exits 1 on any mismatch, so it can be used as a gate by hand.
 */

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web'
    . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'WindowsTimeZones.php';
require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web'
    . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'IcsReader.php';

$dense   = in_array('--dense', $argv, true);
$oneZone = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--zone=') === true) {
        $oneZone = substr($argument, 7);
    }
}

// 🔍 The real method, reached by reflection. Testing a copy of the rule would
//    prove only that the copy agrees with itself — the mistake this project
//    has already made once, when a page's test held its own copy of the logic
//    it was testing (see DEV_NOTES, "The four money writers").
//
//    It REFUSES rather than passing if the method has gone. A sweep that
//    silently finds nothing to test is worse than no sweep: it reports success.
if (method_exists(Portal\Core\IcsReader::class, 'laterOfARepeatedReading') === false) {
    echo "REFUSED — Portal\\Core\\IcsReader::laterOfARepeatedReading() is not there any more.\n";
    echo "This sweep reaches it by reflection, so a rename leaves nothing to test. Point this\n";
    echo "file at whatever now decides an ambiguous clock reading, or delete it and say so in\n";
    echo "DEV_NOTES — do not leave it reporting success over nothing. NOTHING WAS CHECKED.\n";
    exit(1);
}
$method = new ReflectionMethod(Portal\Core\IcsReader::class, 'laterOfARepeatedReading');
$under  = static fn (DateTimeImmutable $moment, DateTimeZone $zone): DateTimeImmutable
    => $method->invoke(null, $moment, $zone);

$utc  = new DateTimeZone('UTC');
$from = (new DateTimeImmutable($dense === true ? '1850-01-01' : '1900-01-01', $utc))->getTimestamp();
$to   = (new DateTimeImmutable($dense === true ? '2100-01-01' : '2050-01-01', $utc))->getTimestamp();

// 🎯 Where to sample around each transition.
//
//    ONE READING PER TRANSITION IS NOT ENOUGH, and that is measured rather
//    than assumed: with a single reading a minute before each change, this
//    sweep reported 81 zones wrong for fault 3 and MISSED the pre-filter
//    version of the same mistake entirely, because the readings that expose
//    that one sit a whole DAY apart — the 1867 Alaska, 1892 Samoa and 1899
//    Rarotonga moves across the date line, where the clocks went back a full
//    86,400 seconds and a reading therefore repeats 24 hours later. Anything
//    that assumes a repeated reading is "an hour or so" wide is wrong about
//    those, which is exactly why they are sampled here.
$offsetsToTry = $dense === true
    ? [1, 60, 300, 900, 1800, 2700, 3600, 5400, 7200, 10800, 21600, 43200, 82800, 86400, 90000, 172800]
    : [60, 1800, 3600, 86400];

// A name PHP does not know would otherwise die with an uncaught exception part
// way through, which reads like a crash in the sweep rather than a typo.
if ($oneZone !== null) {
    try {
        new DateTimeZone($oneZone);
    } catch (Exception $e) {
        echo 'REFUSED — "' . $oneZone . '" is not a time-zone name this PHP knows. NOTHING WAS CHECKED.' . "\n";
        exit(1);
    }
}

$zones = $oneZone === null ? DateTimeZone::listIdentifiers() : [$oneZone];
$readings = 0;
$ambiguous = 0;
$wrong = [];

foreach ($zones as $name) {
    $zone  = new DateTimeZone($name);
    $steps = $zone->getTransitions($from, $to);
    if (is_array($steps) === false) {
        continue;
    }

    // Every offset this zone has ever used in the span. The reference tries
    // them all, so it is complete by construction rather than by cleverness.
    $offsets = [];
    foreach ($steps as $step) {
        $offsets[(int) $step['offset']] = true;
    }
    $offsets = array_keys($offsets);

    for ($i = 1, $n = count($steps); $i < $n; $i++) {
        // Only a clock going BACK makes a reading repeat. A forward change
        // leaves a gap, which is a different question (see `generateStarts()`
        // and check C13), and sampling it here would only waste time.
        if ((int) $steps[$i - 1]['offset'] <= (int) $steps[$i]['offset']) {
            continue;
        }

        foreach ($offsetsToTry as $back) {
            $instant = (int) $steps[$i]['ts'] - $back;
            $local   = (new DateTimeImmutable('@' . $instant))->setTimezone($zone);
            $text    = $local->format('Y-m-d H:i:s');
            $asUtc   = (new DateTimeImmutable($text, $utc))->getTimestamp();
            $readings++;

            // The reference: the latest instant anywhere that carries this
            // reading. Built from `@<seconds>` and never with
            // `setTimestamp()`, which is the very trap being hunted.
            $want = null;
            foreach ($offsets as $offset) {
                $candidate = $asUtc - $offset;
                if ((new DateTimeImmutable('@' . $candidate))->setTimezone($zone)->format('Y-m-d H:i:s') === $text
                    && ($want === null || $candidate > $want)) {
                    $want = $candidate;
                }
            }
            if ($want === null || $want === $instant) {
                // Either nothing matched (cannot happen for a real moment) or
                // this instant is already the latest, so there is nothing to
                // choose and nothing to get wrong.
                continue;
            }
            $ambiguous++;

            $got = $under($local, $zone)->getTimestamp();
            if ($got !== $want) {
                $wrong[$name][] = $text . ' — chose ' . gmdate('Y-m-d H:i:s', $got)
                    . 'Z, should be ' . gmdate('Y-m-d H:i:s', $want) . 'Z (out by '
                    . ($want - $got) . 's; the change here was '
                    . ((int) $steps[$i - 1]['offset'] - (int) $steps[$i]['offset']) . 's, isdst '
                    . var_export($steps[$i - 1]['isdst'], true) . ' to ' . var_export($steps[$i]['isdst'], true) . ')';
            }
        }
    }
}

echo 'Sweep of ' . count($zones) . ' zone(s), '
    . gmdate('Y', $from) . ' to ' . gmdate('Y', $to)
    . ($dense === true ? ', DENSE sampling' : ', light sampling')
    . ' — PHP ' . PHP_VERSION . ', time-zone data ' . timezone_version_get() . "\n";
echo '  readings tried : ' . number_format($readings) . "\n";
echo '  ambiguous      : ' . number_format($ambiguous) . "\n";
echo '  zones wrong    : ' . count($wrong) . "\n";

// 🚧 A FLOOR ON THE WORK DONE, because "nothing went wrong" and "nothing was
//    tried" print the same word otherwise.
//
//    This file's own header calls a sweep that reports success over nothing
//    "worse than no sweep", and then did exactly that: planting an empty offset
//    list in the reference above gave `ambiguous: 0`, no mismatches, PASS and
//    exit 0. The rename tripwire covers only one of the two ways to get there.
//
//    Today's data yields 145,265 ambiguous readings at `--dense` and 42,877
//    light. A floor of 2,000 is far below both and far above anything a
//    crippled sampling or reference would produce, so it survives time-zone
//    data changing under it without ever being the thing that fails.
$floor = $oneZone === null ? 2000 : 1;
if ($ambiguous < $floor) {
    echo "\nREFUSED — only " . $ambiguous . " ambiguous reading(s) were found; this run needed at least "
        . $floor . ".\n";
    if ($oneZone !== null) {
        echo "A single zone with no backward clock change has no ambiguous reading to test, so there\n";
        echo "was nothing to prove here. Pick a zone that puts its clocks back, or drop --zone.\n";
    } else {
        echo "Something stopped this searching rather than something being right: suspect the sampling\n";
        echo "offsets, the year span, or the reference's list of offsets.\n";
    }
    echo "NOTHING WAS PROVED.\n";
    exit(1);
}

if ($wrong === []) {
    echo "\nPASS — every ambiguous reading resolved to the latest moment carrying it.\n";
    echo "(This proves the rule is applied consistently. It does NOT prove the rule is\n";
    echo " the right one, and it says nothing about how a resolved start is then used.)\n";
    exit(0);
}

echo "\nFAIL — the resolution is wrong in these zones:\n";
foreach ($wrong as $name => $list) {
    echo '  ' . $name . ' (' . count($list) . ")\n";
    foreach (array_slice($list, 0, 3) as $line) {
        echo '      ' . $line . "\n";
    }
}
echo "\nRead the 'isdst' pair on each line before deciding what the fault is: a change\n";
echo "with standard time on BOTH sides is a country moving its clocks for good, and\n";
echo "those are not the only shape that has gone wrong here.\n";
exit(1);
