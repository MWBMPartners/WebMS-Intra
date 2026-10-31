<?php
// Path: tools/series-repattern-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test: what changing a series' pattern would do (#669) 🔁
 * -----------------------------------------------------------------------------
 * Checks `Portal\Core\SeriesRepattern`, the ONE place that decides which dates
 * of a repeating series a pattern change may move, add or remove.
 *
 * WHY THIS IS WORTH TESTING WITHOUT A DATABASE
 * -----------------------------------------------------------------------------
 * Because the thing most worth being sure of is a REFUSAL, and a refusal is
 * invisible in a happy-path test. The decision this class makes is "leave that
 * date alone", and the harm of getting it wrong is a hidden date that carried
 * somebody's attendance register. A test that only ever proves dates get moved
 * proves almost nothing.
 *
 * The class takes facts and returns a plan, so every case here is exact: there
 * is no clock to wait for and no row to set up. The page's own behaviour over
 * real HTTP is section M of `tools/event-repeat-pages-selftest.php`; this file
 * is the decision underneath it.
 *
 * It needs NO DATABASE and NO SERVER, which is also a test of that promise — if
 * somebody gives the class a query, this file stops working.
 *
 * Run it with:
 *     php tools/series-repattern-selftest.php
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/669
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR
    . '_core' . DIRECTORY_SEPARATOR . 'SeriesRepattern.php';

use Portal\Core\SeriesRepattern;

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $saw = ''): void
{
    global $passed, $failed;
    if ($ok === true) {
        $passed++;
        echo "  ok    $what\n";

        return;
    }
    $failed++;
    echo "  FAIL  $what" . ($saw !== '' ? " — saw: $saw" : '') . "\n";
}

/**
 * 🧱 One date of a series, as the page hands it over.
 *
 * `$patternStart`/`$patternEnd` take the word 'same' to mean "the pattern put
 * it exactly here and nobody has touched it", which is the ordinary case, so
 * each case below reads as the thing it is testing rather than as plumbing.
 */
function ev(
    int $id,
    string $start,
    ?string $end = null,
    ?string $patternStart = 'same',
    array $attached = [],
    ?string $patternEnd = 'same'
): array {
    return [
        'eventID'      => $id,
        'start'        => $start,
        'end'          => $end,
        'patternStart' => $patternStart === 'same' ? $start : $patternStart,
        'patternEnd'   => $patternEnd === 'same' ? $end : $patternEnd,
        'attachedTo'   => $attached,
    ];
}

/** 🧱 One date the new pattern wants. */
function want(string $start, ?string $end = null): array
{
    return ['start' => $start, 'end' => $end];
}

/** 🔎 The starts in one list of a plan, for comparing. */
function starts(array $rows, string $key = 'start'): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = (string) $row[$key];
    }
    sort($out);
    return $out;
}

$NOW = '2026-11-01 09:00:00';

echo "Series repattern self-test (#669)\n";
echo 'Running on PHP ' . PHP_VERSION . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "The clock every case is read against: $NOW\n\n";

// =============================================================================
// A. The four reasons a date is left exactly as it is
// =============================================================================
echo "A. Why a date is left alone\n";

// A1. Already happened. First, and before everything else, because a date in
//     the past is simply not this page's business whatever else is true of it.
check(
    'a date in the past is kept, reason "past"',
    SeriesRepattern::whyKept(ev(1, '2026-10-01 10:00:00'), $NOW, []) === SeriesRepattern::KEPT_PAST
);
check(
    'a date EXACTLY now counts as past, not future',
    SeriesRepattern::whyKept(ev(1, $NOW), $NOW, []) === SeriesRepattern::KEPT_PAST
);
// 🧠 One second after now is the future. The boundary is tested in both
//    directions on purpose: a check that only proves one side of a comparison
//    passes just as happily on `<` as on `<=`.
check(
    'one second after now is changeable',
    SeriesRepattern::whyKept(ev(1, '2026-11-01 09:00:01'), $NOW, []) === ''
);

// A2. Something is attached. Ahead of the origin tests, because real records
//     beat any judgement about where the date came from.
check(
    'a future date with attendance against it is kept, reason "attached"',
    SeriesRepattern::whyKept(
        ev(1, '2026-11-08 10:00:00', null, 'same', ['attendance has been marked']),
        $NOW,
        []
    ) === SeriesRepattern::KEPT_ATTACHED
);
check(
    'attachment beats a perfectly good recorded origin',
    SeriesRepattern::whyKept(
        ev(1, '2026-11-08 10:00:00', null, '2026-11-08 10:00:00', ['a room is booked']),
        $NOW,
        []
    ) === SeriesRepattern::KEPT_ATTACHED
);

// A3. Moved by hand — the recorded origin disagrees with where it now sits.
check(
    'a future date whose recorded origin differs from its start is kept, reason "by-hand"',
    SeriesRepattern::whyKept(
        ev(1, '2026-12-24 18:30:00', null, '2026-12-24 10:00:00'),
        $NOW,
        []
    ) === SeriesRepattern::KEPT_BY_HAND
);

// A4. No recorded origin AND no rule to fall back on.
check(
    'no recorded origin and no rule gives reason "unknown-origin"',
    SeriesRepattern::whyKept(ev(1, '2026-11-08 10:00:00', null, null), $NOW, [])
        === SeriesRepattern::KEPT_UNKNOWN
);

// =============================================================================
// B. Telling a hand-moved date from one the pattern put there
// =============================================================================
echo "\nB. How the origin of a date is decided\n";

$recorded = SeriesRepattern::followsPattern('2026-11-08 10:00:00', null, '2026-11-08 10:00:00', null, []);
check('a matching recorded origin says "follows", on basis "recorded"',
    $recorded['follows'] === true && $recorded['basis'] === 'recorded',
    var_export($recorded, true));

$movedAway = SeriesRepattern::followsPattern('2026-11-08 10:00:00', null, '2026-11-08 18:00:00', null, []);
check('a recorded origin that differs says "does not follow"', $movedAway['follows'] === false);

// 🧠 THE RECORDED ORIGIN WINS OVER THE RULE, and this is the case that proves
//    it. The date matches the old rule exactly, so a reader that preferred the
//    rule would call it untouched — but its own recorded origin says the
//    pattern put it somewhere else. That happens when somebody moves a date ON
//    TO another of the pattern's dates, and the honest answer is "a person
//    moved this", not "the pattern did".
$bothAvailable = SeriesRepattern::followsPattern(
    '2026-11-08 10:00:00',
    null,
    '2026-11-15 10:00:00',
    null,
    ['2026-11-08 10:00:00', '2026-11-15 10:00:00']
);
check('the recorded origin is preferred over the rule when both could answer',
    $bothAvailable['follows'] === false && $bothAvailable['basis'] === 'recorded',
    var_export($bothAvailable, true));

// 🧠 THE FALLBACK IS WHAT MAKES THIS WORK ON AN EXISTING INSTALLATION. Every
//    date created before migration 232 has no recorded origin, and without this
//    branch every one of them would read as "unknown" and a pattern change
//    would do nothing at all — which is #671's first lesson, that a fix which
//    only works on a fresh installation is a fix for the one case where the
//    fault is invisible.
$viaRule = SeriesRepattern::followsPattern(null, null, '2026-11-08 10:00:00', null, ['2026-11-08 10:00:00']);
check('an unrecorded date found in the rule follows it, on basis "old-rule"',
    $viaRule['follows'] === true && $viaRule['basis'] === 'old-rule',
    var_export($viaRule, true));

$notInRule = SeriesRepattern::followsPattern(null, null, '2026-11-09 10:00:00', null, ['2026-11-08 10:00:00']);
check('an unrecorded date NOT in the rule does not follow it',
    $notInRule['follows'] === false && $notInRule['basis'] === 'old-rule');

check('an empty recorded origin is treated as unrecorded, not as a value',
    SeriesRepattern::followsPattern('', null, '2026-11-08 10:00:00', null, ['2026-11-08 10:00:00'])['basis'] === 'old-rule');
check('a recorded origin with spaces round it still matches',
    SeriesRepattern::followsPattern(' 2026-11-08 10:00:00 ', null, '2026-11-08 10:00:00', null, [])['follows'] === true);

// =============================================================================
// B2. The FINISH time is judged too — the fault the first version shipped with
// =============================================================================
echo "\nB2. A hand-changed finish time is a hand edit\n";

// 🧠 WHY THIS SECTION EXISTS. The first version of this class compared the
//    recorded origin with the START only, while its own docblock promised it
//    caught "a changed TIME". Measured by the independent check: somebody who
//    lengthened one future service from an hour to three had it silently
//    shortened again by the next pattern change, and the preview called that
//    date "re-timed" rather than leaving it alone. That is the owner's rule 2
//    broken — a hand edit quietly overwritten — and the #645 shape, a comment
//    stating the guarantee the code forgot to implement.
$endMoved = SeriesRepattern::followsPattern(
    '2026-11-08 10:00:00',
    '2026-11-08 11:00:00',
    '2026-11-08 10:00:00',
    '2026-11-08 13:00:00',
    []
);
check('B2a a date whose FINISH alone was changed does not follow the pattern',
    $endMoved['follows'] === false && $endMoved['basis'] === 'recorded',
    var_export($endMoved, true));

check('B2b and whyKept() reports it as moved by hand',
    SeriesRepattern::whyKept(
        ev(1, '2026-11-08 10:00:00', '2026-11-08 13:00:00', 'same', [], '2026-11-08 11:00:00'),
        $NOW,
        []
    ) === SeriesRepattern::KEPT_BY_HAND);

check('B2c a date where BOTH still match follows the pattern',
    SeriesRepattern::followsPattern(
        '2026-11-08 10:00:00',
        '2026-11-08 11:00:00',
        '2026-11-08 10:00:00',
        '2026-11-08 11:00:00',
        []
    )['follows'] === true);

// 🧠 "Not set" must be ONE value however it arrives. A column read back from
//    the database and a value built in PHP do not always agree about whether
//    nothing is null or '', and treating those as different would report every
//    ordinary date with no finish time as hand-edited — the loudest possible
//    false alarm, and the kind that gets a guard switched off.
foreach ([[null, null], ['', null], [null, ''], ['  ', '']] as $i => $pair) {
    check('B2d' . $i . ' two ways of saying "no finish time" agree',
        SeriesRepattern::followsPattern('2026-11-08 10:00:00', $pair[0], '2026-11-08 10:00:00', $pair[1], [])['follows'] === true);
}

// 🧠 WHAT THE FALLBACK CANNOT DO, pinned so nobody later believes it can. For
//    a date with no recorded origin the old rule answers about the START only,
//    so a pre-migration-232 date whose FINISH alone was changed by hand still
//    reads as following the pattern. That is a stated limit, not an oversight:
//    narrowing it would mean refusing every pre-232 date and the feature would
//    do nothing on an existing installation.
$preMigration = SeriesRepattern::followsPattern(
    null,
    null,
    '2026-11-08 10:00:00',
    '2026-11-08 13:00:00',
    ['2026-11-08 10:00:00']
);
check('B2e the old-rule fallback judges the start only, and says so by basis',
    $preMigration['follows'] === true && $preMigration['basis'] === 'old-rule',
    var_export($preMigration, true));

// =============================================================================
// C. The four things #669 asks for, each from the one pairing rule
// =============================================================================
echo "\nC. The four kinds of change\n";

// C1. Shift every future date half an hour later. Every date pairs with itself
//     by DAY and is re-timed; nothing is added or removed.
$events = [
    ev(1, '2026-10-25 10:00:00', '2026-10-25 11:00:00'),  // past — untouched
    ev(2, '2026-11-08 10:00:00', '2026-11-08 11:00:00'),
    ev(3, '2026-11-15 10:00:00', '2026-11-15 11:00:00'),
];
$plan = SeriesRepattern::plan(
    $events,
    [want('2026-11-08 10:30:00', '2026-11-08 11:30:00'), want('2026-11-15 10:30:00', '2026-11-15 11:30:00')],
    $NOW,
    []
);
check('C1 a time shift re-times both future dates', count($plan['move']) === 2, (string) count($plan['move']));
check('C1 it adds nothing and removes nothing',
    $plan['add'] === [] && $plan['remove'] === [],
    count($plan['add']) . ' added, ' . count($plan['remove']) . ' removed');
check('C1 the past date is kept, not re-timed',
    count($plan['keep']) === 1 && $plan['keep'][0]['why'] === SeriesRepattern::KEPT_PAST);
check('C1 the new end time comes through, not just the start',
    ($plan['move'][0]['toEnd'] ?? '') === '2026-11-08 11:30:00', (string) ($plan['move'][0]['toEnd'] ?? 'null'));

// 🧠 A date whose END alone changes is still a change. Checking only the start
//    would silently refuse to shorten or lengthen a series' meetings.
$endOnly = SeriesRepattern::plan(
    [ev(2, '2026-11-08 10:00:00', '2026-11-08 11:00:00')],
    [want('2026-11-08 10:00:00', '2026-11-08 12:00:00')],
    $NOW,
    []
);
check('C1b a date whose END alone changes is re-timed', count($endOnly['move']) === 1);

// C2. Weekly to fortnightly: half the days are no longer wanted.
$weekly = [
    ev(2, '2026-11-08 10:00:00'),
    ev(3, '2026-11-15 10:00:00'),
    ev(4, '2026-11-22 10:00:00'),
    ev(5, '2026-11-29 10:00:00'),
];
$plan = SeriesRepattern::plan(
    $weekly,
    [want('2026-11-08 10:00:00'), want('2026-11-22 10:00:00')],
    $NOW,
    []
);
check('C2 fortnightly removes the two dates it no longer wants',
    starts($plan['remove']) === ['2026-11-15 10:00:00', '2026-11-29 10:00:00'],
    implode(', ', starts($plan['remove'])));
check('C2 the two it keeps are not reported as changes', $plan['move'] === [] && $plan['add'] === []);

// C3. Extending a run that stopped at the ceiling.
$plan = SeriesRepattern::plan(
    [ev(2, '2026-11-08 10:00:00'), ev(3, '2026-11-15 10:00:00')],
    [want('2026-11-08 10:00:00'), want('2026-11-15 10:00:00'), want('2026-11-22 10:00:00'), want('2026-11-29 10:00:00')],
    $NOW,
    []
);
check('C3 extending adds only the dates that are new',
    starts($plan['add']) === ['2026-11-22 10:00:00', '2026-11-29 10:00:00'],
    implode(', ', starts($plan['add'])));
check('C3 extending removes nothing and re-times nothing', $plan['remove'] === [] && $plan['move'] === []);

// C4. Cutting the rest of a run short.
$plan = SeriesRepattern::plan($weekly, [want('2026-11-08 10:00:00')], $NOW, []);
check('C4 shortening removes the tail', count($plan['remove']) === 3, (string) count($plan['remove']));

// =============================================================================
// D. The refusals, which are the point of the whole class
// =============================================================================
echo "\nD. A date with records against it is never moved or removed\n";

// D1. The headline case: a date the new pattern drops, carrying attendance.
//     It must land in `keep`, NOT in `remove`.
$plan = SeriesRepattern::plan(
    [
        ev(2, '2026-11-08 10:00:00'),
        ev(3, '2026-11-15 10:00:00', null, 'same', ['attendance has been marked']),
    ],
    [want('2026-11-08 10:00:00')],
    $NOW,
    []
);
check('D1 a dropped date with attendance is KEPT, not removed',
    $plan['remove'] === [] && count($plan['keep']) === 1
    && $plan['keep'][0]['why'] === SeriesRepattern::KEPT_ATTACHED,
    count($plan['remove']) . ' removed');
check('D1 the reason given is the record itself, in plain words',
    ($plan['keep'][0]['words'] ?? '') === 'attendance has been marked',
    (string) ($plan['keep'][0]['words'] ?? ''));

// D2. And a date the new pattern WANTS to re-time, carrying records, is also
//     left alone — moving it would leave its register on the wrong day.
$plan = SeriesRepattern::plan(
    [ev(3, '2026-11-15 10:00:00', null, 'same', ['a room is booked'])],
    [want('2026-11-15 14:00:00')],
    $NOW,
    []
);
check('D2 a date with a booking is not re-timed either', $plan['move'] === []);
check('D2 and it is reported rather than passed over silently', count($plan['keep']) === 1);

// D3. A hand-moved date the pattern would re-time: the hand edit survives.
$plan = SeriesRepattern::plan(
    [ev(4, '2026-12-24 18:30:00', null, '2026-12-24 10:00:00')],
    [want('2026-12-24 10:30:00')],
    $NOW,
    []
);
check('D3 a hand-moved date is not dragged back onto the pattern', $plan['move'] === []);
check('D3 and the reason says a person moved it',
    ($plan['keep'][0]['why'] ?? '') === SeriesRepattern::KEPT_BY_HAND);

// D4. Several records on one date are all named, so the preview says what is
//     really there rather than "something".
$plan = SeriesRepattern::plan(
    [ev(5, '2026-11-15 10:00:00', null, 'same', ['attendance has been marked', 'a room is booked'])],
    [],
    $NOW,
    []
);
check('D4 every kind of record attached is named',
    ($plan['keep'][0]['words'] ?? '') === 'attendance has been marked; a room is booked',
    (string) ($plan['keep'][0]['words'] ?? ''));

// D5. The defensive wording, for a caller that gives the reason and not the
//     detail. It must say something TRUE rather than invent a record.
check('D5 "attached" with no detail says so without naming a record',
    SeriesRepattern::reasonWords(SeriesRepattern::KEPT_ATTACHED) === 'something is attached to it');

// =============================================================================
// E. Two dates on one day, and the back-fill refusal
// =============================================================================
echo "\nE. The two cases where there is no honest answer\n";

// E1. Two dates on the same day. Pairing the first and removing the rest would
//     delete a date somebody deliberately added, so neither is touched.
$plan = SeriesRepattern::plan(
    [ev(6, '2026-11-08 10:00:00'), ev(7, '2026-11-08 18:00:00')],
    [want('2026-11-08 10:30:00')],
    $NOW,
    []
);
check('E1 two dates on one day are both kept',
    count($plan['keep']) === 2 && $plan['move'] === [] && $plan['remove'] === [],
    count($plan['keep']) . ' kept, ' . count($plan['move']) . ' moved, ' . count($plan['remove']) . ' removed');
check('E1 the day is reported so the preview can say which it was',
    $plan['sameDay'] === ['2026-11-08'], implode(',', $plan['sameDay']));
// 🧠 ITS OWN REASON, not KEPT_BY_HAND. The independent check found a PAST or
//    ATTACHED date sharing a day being reported as "moved by hand" — untrue of
//    it — and then offered an "edit this one by hand" link, which makes no
//    sense for a date that has already happened.
check('E1b the reason is same-day, not "moved by hand"',
    $plan['keep'][0]['why'] === SeriesRepattern::KEPT_SAME_DAY
    && $plan['keep'][1]['why'] === SeriesRepattern::KEPT_SAME_DAY,
    (string) $plan['keep'][0]['why']);
check('E1c and it has words of its own',
    SeriesRepattern::reasonWords(SeriesRepattern::KEPT_SAME_DAY) !== ''
    && SeriesRepattern::reasonWords(SeriesRepattern::KEPT_SAME_DAY)
       !== SeriesRepattern::reasonWords(SeriesRepattern::KEPT_BY_HAND));
check('E1 nothing is added for that day either', $plan['add'] === []);

// E2. A pattern date in the PAST is never back-filled. Inventing last month's
//     meeting would create a date nobody could ever mark attendance against.
$plan = SeriesRepattern::plan(
    [ev(2, '2026-11-08 10:00:00')],
    [want('2026-10-04 10:00:00'), want('2026-11-08 10:00:00')],
    $NOW,
    []
);
check('E2 a pattern date in the past is not added', $plan['add'] === [], (string) count($plan['add']));

// =============================================================================
// F. An unchanged date is never reported as a change
// =============================================================================
echo "\nF. Nothing to do really means nothing to do\n";

$same = SeriesRepattern::plan(
    [ev(2, '2026-11-08 10:00:00', '2026-11-08 11:00:00')],
    [want('2026-11-08 10:00:00', '2026-11-08 11:00:00')],
    $NOW,
    []
);
check('F1 an identical pattern produces no move', $same['move'] === []);
check('F1 and the plan reads as empty', SeriesRepattern::planIsEmpty($same) === true);
check('F2 a plan with one move does NOT read as empty',
    SeriesRepattern::planIsEmpty($endOnly) === false);
// 🧠 A plan whose only content is `keep` is still empty, because `keep` is
//    something REPORTED rather than something done. If this were wrong the
//    page would offer a "Make these changes" button that changed nothing.
$keepOnly = SeriesRepattern::plan(
    [ev(1, '2026-10-01 10:00:00')],
    [],
    $NOW,
    []
);
check('F3 a plan holding only kept dates reads as empty',
    $keepOnly['keep'] !== [] && SeriesRepattern::planIsEmpty($keepOnly) === true);

// =============================================================================
// G. Whether the series' rule row may survive
// =============================================================================
echo "\nG. The rule the calendar feed publishes\n";

// 🧠 `calendar/export.php` collapses a series with a rule into ONE repeating
//    entry and skips every other event of that series. So a rule that
//    over-promises does not merely look untidy: it sends subscribers dates the
//    diary does not hold AND hides the ones it does.
check('G1 a rule survives when the series holds exactly the pattern\'s dates',
    SeriesRepattern::ruleCanStillBeTrue(
        ['2026-11-08 10:00:00', '2026-11-15 10:00:00'],
        ['2026-11-08 10:00:00', '2026-11-15 10:00:00']
    ) === true);
check('G2 order does not matter, because the database returns rows in its own',
    SeriesRepattern::ruleCanStillBeTrue(
        ['2026-11-15 10:00:00', '2026-11-08 10:00:00'],
        ['2026-11-08 10:00:00', '2026-11-15 10:00:00']
    ) === true);
check('G3 a rule cannot survive an EXTRA date in the diary',
    SeriesRepattern::ruleCanStillBeTrue(
        ['2026-10-04 10:00:00', '2026-11-08 10:00:00'],
        ['2026-11-08 10:00:00']
    ) === false);
check('G4 a rule cannot survive a MISSING date either',
    SeriesRepattern::ruleCanStillBeTrue(
        ['2026-11-08 10:00:00'],
        ['2026-11-08 10:00:00', '2026-11-15 10:00:00']
    ) === false);
// 🧠 The realistic case, and the one that matters: a series with ANY past date
//    can never carry a rule after a repattern, because the new pattern starts
//    in the future. This is why the page says, in plain words, that
//    subscribers will see each date on its own.
check('G5 a series with a past date can never keep a rule',
    SeriesRepattern::ruleCanStillBeTrue(
        ['2026-10-25 10:00:00', '2026-11-08 10:00:00'],
        ['2026-11-08 10:00:00']
    ) === false);

// =============================================================================
// H. The list of tables, and the one thing it must never be
// =============================================================================
echo "\nH. The list of what can be attached to a date\n";

check('H1 the list is not empty', SeriesRepattern::ATTACHMENTS !== []);
// 🧠 'no rule' is a THIRD value: the schema has no foreign key there at all.
//    Both tables carrying it were MISSING from this list until 31 October 2026,
//    because the list was built from the schema's foreign keys and these two
//    have none.
//
//    WHAT IT DOES AND DOES NOT MEAN, measured on MySQL 8.0.36 rather than
//    reasoned about: the repattern page SOFT-deletes, and no ON DELETE rule
//    fires on an UPDATE, so all three values behave IDENTICALLY there — every
//    attached row survives, pointing at a date nobody can see. The rule only
//    decides what a later real DELETE does, and only then is 'no rule' the
//    worst. An earlier version of this comment called it "the most dangerous
//    value" and gave the soft-delete consequence as the reason, which welded
//    two different scenarios together.
check('H2 every entry has words and an ON DELETE rule',
    count(array_filter(
        SeriesRepattern::ATTACHMENTS,
        static fn(array $m): bool => ($m['words'] ?? '') !== ''
            && in_array($m['onDelete'] ?? '', ['cascade', 'set null', 'no rule'], true)
    )) === count(SeriesRepattern::ATTACHMENTS));
check('H3 every key is a table name, never a column or a phrase',
    count(array_filter(
        array_keys(SeriesRepattern::ATTACHMENTS),
        static fn(string $t): bool => preg_match('/^tbl[A-Za-z0-9_]+$/', $t) === 1
    )) === count(SeriesRepattern::ATTACHMENTS));
// 🧠 The tables everybody would think of first. Named one at a time rather than
//    counted, because a count is exactly what a deliberate change to this list
//    invalidates — the #662 lesson. Whether the list is COMPLETE is
//    `tools/audit-checks/check_series_attachments_complete.py`, which compares
//    it against the schema and cannot be satisfied by a number.
foreach ([
    'tblEventAttendance',
    'tblAttendanceSessions',
    'tblEventRegistrations',
    'tblEventRSVPInvites',
    'tblVenueBookings',
    'tblKidCheckins',
    'tblAnonymousCheckins',
    // 🧠 THE TWO WITH NO FOREIGN KEY, named here one at a time because they are
    //    the ones a list built from the schema's keys silently misses — which is
    //    exactly what happened. `tblEventRSVPs` holds every member's
    //    going/maybe/no answer and its `eventID` is NOT NULL.
    'tblEventRSVPs',
    'tblZoomMeeting',
] as $mustBeThere) {
    check("H4 $mustBeThere is in the list", isset(SeriesRepattern::ATTACHMENTS[$mustBeThere]) === true);
}

// =============================================================================
// I. The class really has no dependencies
// =============================================================================
echo "\nI. It decides, and nothing more\n";

$source = (string) file_get_contents(
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR
    . '_core' . DIRECTORY_SEPARATOR . 'SeriesRepattern.php'
);
// 🧠 Comments stripped FIRST. Without that, this section passes on a commented
//    reference and fails on a comment that merely mentions SQL — both wrong, in
//    opposite directions.
$bare = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

check('I1 it runs no query', preg_match('/\b(prepare|query|execute|bind_param)\s*\(/i', $bare) !== 1);
check('I2 it opens no connection', strpos($bare, 'mysqli') === false);
check('I3 it reads no settings', strpos($bare, 'App::') === false && strpos($bare, 'InstallationSettings') === false);
check('I4 it writes no log', strpos($bare, 'Logger::') === false);
// 🧠 NO CLOCK OF ITS OWN. The caller passes the moment in, which is what makes
//    every case in this file exact — and what stops the class reading the
//    server's zone instead of the event's.
check('I5 it asks the clock nothing', preg_match('/\b(time|date|now)\s*\(/i', $bare) !== 1);

// =============================================================================
echo "\n";
echo $failed === 0
    ? "ALL $passed CHECKS PASSED\n"
    : ($passed + $failed) . " checks, $failed FAILED\n";

exit($failed === 0 ? 0 : 1);
