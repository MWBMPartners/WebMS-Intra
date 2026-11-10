<?php
// Path: public_html/calendar/manage/import.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Bulk Event Import via CSV 📥
 * -----------------------------------------------------------------------------
 * Admin-only. Upload a CSV with one event per row, preview parsed rows
 * + validation errors, then run the import. Excel users should
 * "Save As → CSV (Comma delimited)" first.
 *
 * Required columns: eventName, startDateTime
 * Optional columns: endDateTime, isAllDay, locationName, description,
 *                   status (draft|published|cancelled|postponed), category,
 *                   type, isPublic, isFeatured
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\DbServer;
use Portal\Core\Events;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

$pageTitle   = 'Bulk Import Events';
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard'       => Site::url(''),
    'Calendar'        => Site::url('calendar'),
    'Manage Events'   => Site::url('calendar/manage'),
    'Bulk Import'     => '',
];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$preview  = [];
$results  = [];
$error    = '';
$imported = false;
// The spreadsheet row at which the database connection was lost, or 0 (#790).
$lostAtRow = 0;

/** Parse + validate CSV. Returns array{rows:list<array>, errors:list<string>} */
// 🕐 ONE PLACE PUTS A CELL INTO THE COLUMN'S SHAPE, used by the row CHECK and
//    by the row STORE (#777 round 9).
//
// ⚠️ IT USED TO BE DEFINED DOWN IN THE CONFIRM BLOCK, so the check compared
//    something ELSE. Round 8 made the check compare readings as text where
//    BOTH cells parsed and fall back to moments otherwise -- and the fallback
//    fires when EITHER cell is loose, so a clean ISO cell was dragged into a
//    moment comparison with its loose neighbour. Measured in Europe/London:
//
//      start "2026-03-29 01:30", end "29 March 2026 2:15am"
//          -> REFUSED "endDateTime is before startDateTime"
//          -> but the readings it would STORE are 01:30 and 02:15: a
//             legitimate 45-minute event, refused. The exact harm round 8's
//             own finding 3 describes, surviving in the looser half.
//
//      start "29 March 2026 2:15am", end "2026-03-29 01:30"
//          -> ACCEPTED, and stored 02:15 -> 01:30, an end before its start.
//
//    So the check compares THE VALUES THAT WILL ACTUALLY BE STORED. That is
//    the one comparison that cannot disagree with storage, in either format,
//    and it needs no branch at all -- which is also why the "kept FOR THOSE
//    ONLY" claim that used to sit here has gone rather than been reworded.
// ⚠️ THE OUTPUT TEST IS A WRAPPER, NOT A STEP, and putting it in step 3 was
//    the same mistake one level up — it guarded a PATH, not the value. A cell
//    like `0500-01-01 10:00` reaches step 2's strict reader and returns from
//    there, so a test at the bottom of step 3 never saw it. **Follow the
//    value to the thing that finally produces it** (round 11's rule): what
//    finally produces a stored reading is this closure's return, whichever
//    step reached it.
$fmtStore = static function (string $text, ?string &$why = null) use (&$fmtClockRaw): ?string {
    $out = $fmtClockRaw($text, $why);
    if ($out === null) {
        return null;
    }
    // ⚠️ TWO TESTS DOING TWO DIFFERENT JOBS, AND AN EARLIER COMMENT HERE RAN
    //    THEM TOGETHER AS ONE. It said a `DATETIME` "holds 1000-01-01 to
    //    9999-12-31 and NOTHING ELSE", which is MySQL's DOCUMENTED range and
    //    not what it enforces. Measured on MySQL 8.0.36 with
    //    `STRICT_TRANS_TABLES`, inserting each literal into a real `DATETIME`
    //    column:
    //
    //      '-0001-12-31 23:58:45'    ERROR 1292, Incorrect datetime value
    //      '10000-01-01 00:00:00'    ERROR 1292
    //      '33658-09-27 02:46:39'    ERROR 1292
    //      '1002026-06-01 00:00:00'  ERROR 1292
    //      '0001-01-01 00:00:00'     ACCEPTED, stored as given
    //      '0500-01-01 10:00:00'     ACCEPTED, stored as given, no warning
    //      '0999-12-31 23:59:59'     ACCEPTED, stored as given
    //
    //    So:
    //
    //    (1) THE SHAPE TEST IS THE ONE THAT PREVENTS THE ABORT. A year that is
    //        negative or longer than four digits is the literal MySQL REFUSES,
    //        and under this portal's reporting mode that throw escapes the
    //        insert loop — stopping the import part-way with earlier rows
    //        already written. Requiring exactly four year digits catches every
    //        one of them. (Since #790 the loop catches a throw and refuses
    //        that ONE row instead of stopping, so this no longer guards the
    //        whole import — it still decides that the row is refused at
    //        PREVIEW, with a reason a person can act on, rather than at the
    //        insert with the database's.)
    //
    //    (2) THE FLOOR TEST REFUSES WHAT THE DATABASE WOULD HAVE ACCEPTED, so
    //        it is this portal's own rule and is worded as one. A year from
    //        0001 to 0999 in a diary is a typing mistake — `202-06-01` for
    //        2026, or `0226` — and before this it was stored silently as year
    //        202 while the row reported "Imported as event #N". Refusing with
    //        a reason is better than storing a date nobody meant, and nothing
    //        a customer could plausibly have in a spreadsheet is affected
    //        (checked against two-digit years, both Excel epochs, written-out
    //        months and offsets, on a cell list that was not committed — so,
    //        by this file's own rule below, no count is given).
    //
    //    There is NO ceiling test, and that is deliberate rather than an
    //    omission: a five-digit year cannot get past the shape test to reach
    //    one. The clause that used to be here (`> '9999-12-31 23:59:59'`)
    //    could never fire — a lexicographic compare reads `10000-…` as LESS
    //    than `9999-…` — so it read as a third safeguard while doing nothing.
    //    Deleting it changed no behaviour, measured.
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $out) !== 1) {
        $why = 'is a date the database cannot store at all (the year must be four digits)';
        return null;
    }
    if ($out < '1000-01-01 00:00:00') {
        $why = 'has a year before 1000, which this portal does not accept — check for a typing mistake';
        return null;
    }
    $why = '';

    return $out;
};

$fmtClockRaw = static function (string $text, ?string &$why = null): ?string {
    $trimmed = trim($text);
    $why = 'cannot be read';

    // ⚠️ READ THIS BEFORE ADDING ANY RULE HERE. This closure has been
    //    rewritten three times and TWO of those rewrites broke formats that
    //    had always imported (#777 rounds 12, 13 and 14). The cause was the
    //    same both times and it is a shape, not a slip:
    //
    //      **a strict rule written for ISO values leaked into the permissive
    //      path, and refused things only the permissive path ever handled.**
    //
    //    So steps 1 and 2 below are ATTEMPTS, not GATES. Each one takes the
    //    value if it recognises it and otherwise FALLS THROUGH. Nothing in
    //    this closure may refuse a value because of the SHAPE it is written
    //    in; the only refusals are for a value that is genuinely wrong.

    // ── 1. AN OFFSET IN THE VALUE MEANS IT IS AN INSTANT ────────────────────
    //
    // A value carrying `+02:00`, `-0500` or a trailing `Z` is a moment, so it
    // is converted onto the organisation's clock. `Site::timezone()` is the
    // ONE place that answers that zone, and it is the same value `$importZone`
    // holds further down.
    //
    // 🚨 IT FALLS THROUGH ON REFUSAL, AND THAT IS ROUND 14's REPAIR. Round 13
    //    returned null here, and `instantFromInput()` requires the date half
    //    to be strict zero-padded ISO -- so every offset-bearing cell whose
    //    date was written any other way was refused outright, and the
    //    spreadsheet fallback below was never reached for it. Measured, these
    //    had all imported correctly before and became "startDateTime not
    //    parseable":
    //
    //      Mon, 01 Jun 2026 10:00:00 +0100   (RFC 2822 -- an e-mail Date:
    //                                         header, an RSS pubDate)
    //      Tue, 3 Mar 2026 19:00 +0100
    //      1 June 2026 10:00 +01:00
    //      Jun 1 2026 10:00 +0100
    //
    //    The first is PHP's own `DATE_RFC2822`. Worse, the SAME format was
    //    accepted or refused depending on how its zone was spelled, because
    //    `GMT` does not match `inputCarriesOffset()` while `+0000` does.
    if (I18n::inputCarriesOffset($trimmed) === true) {
        $zoneName = Site::timezone();
        try {
            $instant = I18n::instantFromInput($trimmed, new \DateTimeZone($zoneName));
        } catch (\Throwable $e) {
            // An unreadable stored zone is not this cell's fault. Fall
            // through rather than blaming the row.
            $instant = null;
        }
        if ($instant !== null) {
            return $instant;
        }
    }

    // ── 2. A READING, AND ONE PLACE DECIDES WHAT ONE IS ─────────────────────
    //
    // 📌 `I18n::wallClockFromInput()` is that place. Nothing here second
    //    guesses it, and a refusal from it is not final -- see step 3.
    $clock = I18n::wallClockFromInput($trimmed);
    if ($clock !== null) {
        return $clock;
    }

    // ⚠️ NO CELL COUNT IS WRITTEN IN THIS FILE, AND THAT IS DELIBERATE.
    //    Three were — "2,160", "6,480" and "64,224" — in the very commit that
    //    withdrew "89 zone spellings", and then "91", for being
    //    unreproducible. Each is a property of a cell list nobody committed,
    //    so nobody can reproduce one, and a figure nobody can reproduce reads
    //    as evidence.
    //
    //    **Round 22 then found that removing them had put FOUR MORE in their
    //    place** — including one in this very block — so the rule is written
    //    here plainly rather than argued: the committed matrix is section K
    //    of `tools/clock-change-selftest.php`, its two counts are pinned
    //    there as K04/K04b, and they are read from the file that fails on
    //    them.
    //
    //    **It also found that removing the number had SPLICED a sentence in
    //    half**, leaving a bolded fragment with no subject and eleven lines
    //    wedged between "so" and its own continuation. This project's rule,
    //    recorded in round 19 about prose: **rewrite the sentence, do not
    //    splice it.** Written down here because this is the third time in
    //    #777 that an edit between two markers damaged working text.

    // ── 3. THE SPREADSHEET FALLBACK ─────────────────────────────────────────
    //
    // A spreadsheet holds whatever somebody typed -- "1/3/26 7pm", "March 3
    // 2026", "Tue, 3 Mar 2026 19:00" -- and refusing all of it would turn
    // imports that work today into failures. Normalising the file's own
    // formats is #777 part two's to settle.
    //
    // 🚨 THE RAW TEXT, NEVER A `str_replace('T', ' ')` OF IT. An earlier
    //    version replaced EVERY capital `T`, so `"Tue,"` became `" ue,"`
    //    (refused) and `"UTC"` became `"U C"`, which `strtotime()` read as
    //    half past TEN -- eight hours out, a value the old code could not
    //    produce. Excel's long date format starts with a weekday name, so the
    //    commonest spreadsheet in the world stopped importing, under a comment
    //    promising nothing had changed.

    // 🚨 ASK WHETHER PHP CAN READ THE CELL AT ALL, FIRST. Until round 20 the
    //    fraction rule above ran before anything consulted `strtotime()`, so
    //    **cells PHP refuses outright were told their fraction was
    //    ambiguous** — including the plausible European shorthand
    //    `1.6. 10:00`, which got "writes the hour with a single digit after a
    //    decimal point" although `strtotime()` cannot read it at all.
    //
    //    That is the same defect as the "not parseable" message round 17
    //    removed, in the other direction: the administrator is given a reason
    //    that is not the reason. `$why` is already 'cannot be read' here, so
    //    returning now says the true thing.
    //
    //    (Round 21 noted that `$why` was ALSO left saying 'cannot be read' on a
    //    SUCCESSFUL read through steps 1 and 2, harmless only because nothing
    //    looks at it when the return is not null. `$fmtStore` now sets it empty
    //    on every success, so that is no longer a caveat anybody has to hold in
    //    their head — measured on all three steps.)
    $moment = strtotime($trimmed);
    if ($moment === false) {
        return null;
    }

    // (a) A FRACTION PHP WILL READ AS A DIFFERENT TIME FROM THE ONE MEANT.
    //
    // 🚨 THE PREMISE WAS WRONG FOR FOUR ROUNDS, AND MEASURING PHP IS WHAT
    //    SETTLED IT. The comment used to say `10.5` means 10:30 and
    //    `strtotime()` reads it as five past — true — and then generalised
    //    that to any fraction. **`strtotime()` does not read a decimal hour
    //    at all: it reads the dot as a MINUTE SEPARATOR**, and the proof is
    //    that it REFUSES minutes above 59:
    //
    //      10.5  -> 10:05     10.59 -> 10:59
    //      10.30 -> 10:30     10.60 -> FALSE      10.75 -> FALSE
    //
    //    So for TWO digits PHP's reading matches what somebody writing a
    //    spreadsheet INTENDS: `19.30` is half past seven to PHP and to
    //    whoever typed it. (It does NOT match the decimal-hour reading —
    //    that would be 19:18 — see the measurement below.)
    //
    //    **ROUND 15 first refused the `HH.MM` readings written with an ISO
    //    date, and round 12 the `T`-form before it; round 16 pushed the
    //    refusal into every** 
    //    date spelling, so `Tue, 3 Mar 2026 19.30`, **Excel's long date
    //    format**, stopped importing where all five earlier versions stored it
    //    correctly. That is the harm the note below this closure warns about,
    //    in the round written to repair an over-narrowing.
    //
    //    It was also internally inconsistent: `2026-06-01 19.30` was refused
    //    while `2026-06-01 19.30:00` stored 19:30:00, and the administrator's
    //    message ("the time it means is not the time it would be read as") was
    //    untrue of every one of them.
    //
    // 🧮 SO THE RULE IS ONE DIGIT, NON-ZERO, ON THE HOUR OR THE MINUTE.
    //    `10.5` could be five past ten or half past ten, and nothing in the
    //    file says which — BOTH are plausible things somebody meant, which is
    //    what makes that cell ambiguous rather than merely non-standard.
    //
    // ⚠️ AND THE REASON FOR STORING TWO DIGITS IS NOT THAT THE TWO READINGS
    //    AGREE. An earlier version of this paragraph said "Two digits agree"
    //    and called one digit "the only case where the two readings genuinely
    //    differ", and round 18 measured both false. Over all 100 two-digit
    //    fractions, 60 are parseable and the two readings agree in just TWO
    //    of them (`.00`, and `.01` only through rounding):
    //
    //      10.30  PHP 10:30   decimal hour 10:18   differ by 12 minutes
    //      10.50  PHP 10:50   decimal hour 10:30   differ by 20
    //      10.25  PHP 10:25   decimal hour 10:15
    //
    //    **The honest ground is intent, not arithmetic**, and it is a product
    //    judgement rather than a measurement: somebody typing `19.30` into a
    //    spreadsheet means half past seven, and PHP's reading is the one that
    //    matches that. The one-digit case is refused precisely because intent
    //    does NOT settle it there — `10.5` reads naturally as either.
    //
    //    Presenting that judgement as a measurement was the fault: a reader
    //    who applied the stated rule literally ("refuse where the readings
    //    differ") would refuse all 58, **which is round 16's fault
    //    restored**.
    //
    //    Three digits or more PHP refuses anyway, and `.0` means the same
    //    either way — both measured and both still true. A COMMA decimal
    //    separator, which ISO 8601 also permits, is not reached by this rule
    //    at all: `strtotime('10,5')` is false, so such a cell is refused as
    //    "cannot be read" in this and every earlier version.
    //
    //    AND IT NEEDS NO LIST OF ZONE SPELLINGS, which is what the previous
    //    version got wrong a second way: its trailing set allowed letters,
    //    `/` and `_` but not digits, `+` or `-`, so any zone whose NAME holds
    //    one of those — `GMT+1`, `UTC+1`, `Etc/GMT+1`,
    //    `America/Port-au-Prince`, `Asia/Ust-Nera` — fell through and stored
    //    the wrong hour, under a comment saying a fraction "followed only by a
    //    zone or an offset" was refused.
    //
    // ⚠️ NO COUNT IS GIVEN, AND THAT IS THE POINT. This said "89 zone
    //    spellings", which round 19 could not reproduce — and nor could I,
    //    because **the number is a property of the list you sweep, not of the
    //    code.** Measured against round 16's own committed closure: 4 of
    //    PHP's 419 identifiers, 34 of the 598 that include the
    //    backward-compatible names, and a different figure again once a
    //    `GMT±N`/`UTC±N`/`Etc/GMT±N` family is generated alongside them —
    //    **round 20 could not reproduce my 91 either**, which is the point
    //    made twice over. I took 89 from a report without
    //    re-deriving it, which is this project's own rule broken — **and a
    //    figure nobody can reproduce is worse than none, because it reads as
    //    evidence.** What is reproducible, and is what matters: the live rule
    //    leaks NONE of any of those sets, because nothing trails it. A date-spelling list had been replaced by a zone-spelling
    //    list. **An allow-list written from the spellings you were handed is
    //    not a rule.** Nothing trails the pattern now, so nothing can be
    //    missing from it.
    //
    //    The lookbehind and lookahead keep a dotted date out: in `31.12.2026`
    //    and `1.6.2026` the digits are preceded or followed by more date.
    //    BE CLEAR WHICH MEASUREMENT IS WHICH, because the previous version
    //    of this paragraph was not and round 17 caught it: it said "43 cases
    //    are measured both ways in section K's own matrix", and section K
    //    holds no such set — its cells are each measured ONE way, store or
    //    refuse. Two different things:
    //
    //      • A DEVELOPMENT SWEEP, run while writing this and not committed:
    //        1,881 cases — all 1,440 `HH.MM` readings, the long-date forms,
    //        every one of 428 zone spellings after a one-digit fraction, and
    //        nine legitimate values. It is what established the rule.
    //      • SECTION K of `clock-change-selftest.php`, which IS committed and
    //        runs every time: cells that must store and cells that must be
    //        refused, each measured one way, with both counts pinned so the
    //        lists cannot shrink unnoticed.
    //
    //        ⚠️ THE NUMBERS ARE DELIBERATELY NOT WRITTEN HERE. This paragraph
    //           said "47 cells that must store and 48 that must be refused",
    //           and round 18 raised the pins without touching it — in the one
    //           paragraph whose subject is that the counts are pinned, and in
    //           three other homes as well. **Read them from K04/K04b in that
    //           file**, which is the only place that cannot go stale, because
    //           it is the place the test fails on.
    // 🚨 A FRACTION ON THE SECONDS IS NEVER AMBIGUOUS, so it is exempt —
    //    and getting that wrong cost two findings at once (round 20).
    //
    //    Both readings of `10:00:5.5` store the SAME value: PHP reads 10:00:05
    //    and drops the fraction, and the ISO reading of 5.5 seconds is
    //    truncated to the same thing, because a DATETIME column holds no
    //    fractions. **There is no competing reading, so there is nothing to
    //    refuse.**
    //
    //    Round 19 instead invented a THIRD refusal sentence for it, whose two
    //    alternatives — "fractions of a second or part of a second" — are the
    //    same thing, so it asserted an ambiguity that does not exist.
    //
    //    And the exemption was written as ONE SPELLING, `:\d{2}:\d{2}\.\d`,
    //    which demands a zero-padded minute AND second. Measured, that refused
    //    cells round 15 stored, all of them a seconds fraction with an
    //    unpadded component: `Tue, 3 Mar 2026 10:00:5.5`,
    //    `1 June 2026 10:0:00.5`, `2026-06-01 9:05:5.5`. All six exemption
    //    cells in section K used `10:00:00.X` — **one value of the
    //    component-width dimension**, the same mistake as the three earlier
    //    bypasses.
    //
    //    With the exemption widened the rule really is what the paragraph
    //    above says: one digit, non-zero, **on the hour or the minute**. That
    //    sentence had been untrue in three places since round 19 added the
    //    third component.
    if (preg_match('/:\d{1,2}:\d{1,2}\.\d/', $trimmed) !== 1
        && preg_match('/(?<![\d.])(\d{1,2}(?::\d{1,2})?)\.[1-9](?!\d|\.\d)/', $trimmed, $which) === 1) {
        // 🗣️ WHICH COMPONENT, because the two ambiguities are different and a
        //    single sentence was untrue of one of them. The old wording said
        //    "minutes or part of an hour" for BOTH, and for the minute case
        //    the choice is seconds against part of a MINUTE:
        //
        //      10.5     PHP 10:05:00    decimal hour   10:30:00
        //      10:00.5  PHP 10:00:05    decimal minute 10:00:30
        //
        //    Round 16's finding 3 was "the message was untrue of every one of
        //    them"; saying it of one component while the rule covers two is
        //    that shape one level down.
        // ⚠️ THE COMPONENT IS COUNTED, NOT GUESSED FROM A GROUP THAT MIGHT
        //    NOT HAVE MATCHED. Round 18 used an optional `(:\d{2})?` and made
        //    the HOUR sentence the default for everything it missed — and it
        //    requires exactly two minute digits, so a one-digit minute or a
        //    fraction on the seconds fell into it and was explained as an
        //    hour that has no decimal point at all. Measured, all read by
        //    PHP and all given the hour message: `10:0.5` (10:00:05),
        //    `10:00:5.5` (10:00:05), `10:00:0.5`, `9:05:5.5`.
        //
        //    Round 18's own finding was that one sentence covered two
        //    components; this is the same thing one level on — it closed the
        //    spelling it was handed and left the catch-all. **Counting the
        //    colons has no default**, so there is nothing left to fall into.
        // Two components, and only two, because a seconds fraction is exempt
        // above. No `min()` clamp: the pattern can match at most one colon.
        $why = substr_count($which[1], ':') === 1
            ? 'writes the minute with a single digit after a decimal point, which could '
                . 'mean either seconds or part of a minute'
            : 'writes the hour with a single digit after a decimal point, which could '
                . 'mean either minutes past the hour or part of an hour';
        return null;
    }

    // (a2) 🚨 A CELL THAT DOES NOT SAY WHICH DAY IT MEANS (#797). `strtotime()`
    //      fills in whatever a cell leaves out from THE MOMENT THE IMPORT RUNS,
    //      so a cell with no full date was stored as a real event on a date
    //      nobody typed, and the row reported success. Measured on 6 November
    //      2026, before this test:
    //
    //        2026            -> 2026-11-06 20:26:00   (read as a TIME, 20:26 today)
    //        1999            -> 1999-11-06 19:32:35   (today's date and clock, in 1999)
    //        10:00           -> today at 10:00
    //        tomorrow 10:00  -> the day after the import
    //        Monday          -> the next Monday after the import
    //
    //      Found by #777's round-23 check, outside its scope; it predates every
    //      round of #777 that touched this closure.
    //
    //      🧮 ONE RULE, NOT A LIST OF SPELLINGS (the lesson of rounds 12-15
    //      above): read the cell against TWO different reference moments and
    //      refuse it when the answers differ — because then the value came
    //      from the moment of import, not from the cell. IN UTC the two
    //      moments differ in every field (2001 against 2010, February against
    //      November, the 3rd against the 12th, Saturday against Friday,
    //      04:05:06 against 13:14:15).
    //
    //      ⚠️ NOT IN EVERY ZONE, and the first version of this comment said
    //         otherwise. The page reads them in the organisation's zone, and
    //         in 118 of PHP's 419 zones (PHP 8.5.12, tzdata 2026.5:
    //         compare `format('N')` of the two moments in each zone of
    //         `DateTimeZone::listIdentifiers()`) both land on the SAME local
    //         weekday — America/Bogota, America/Anchorage, Pacific/Pago_Pago
    //         and Pacific/Kiritimati among them. That does not open a gap,
    //         which #797's independent check measured over a generated set
    //         that was not committed (so no count is given): a cell that
    //         takes its weekday from "now" takes a DATE from it too, and the
    //         dates still differ by nine years. An `@`-timestamp or a full
    //      date with a relative step (`9999-12-31 +1 day`) does NOT depend on
    //      now, so it falls through to the range test in `$fmtStore`, which is
    //      what decides those.
    //
    //      ⚠️ A DATE WITH NO YEAR IS NOW REFUSED, AND THAT IS A DELIBERATE
    //         CHANGE. `1 June 10:00` used to be stored in the year the import
    //         happened to run — so an administrator importing next January's
    //         services in December stored them eleven months in the PAST.
    //         Refusing asks for one more word and cannot store a date nobody
    //         meant; storing could. It also retires the leap-year time bomb
    //         round 21 found (`Feb 29 10:00` stored or refused depending on
    //         the year the test ran): such a cell is now refused every year,
    //         for the reason that is true every year.
    //
    //      ORDER: before #797, (b) judged a year-less cell against the year
    //      `strtotime()` ASSUMED, so this had to come first. Since #797 (b)
    //      requires a year the cell itself gave and assumes none, so the order
    //      no longer changes any answer — measured by #797's round-2 check,
    //      which moved this below (b) and got identical output. It stays
    //      first because it is the cheaper question and the broader one.
    $baseA = 981173106;    // 2001-02-03 04:05:06 UTC, a Saturday
    $baseB = 1289567655;   // 2010-11-12 13:14:15 UTC, a Friday
    if (strtotime($trimmed, $baseA) !== strtotime($trimmed, $baseB)) {
        // 🗣️ WORDED FOR WHAT PHP DID, NOT FOR WHAT THE CELL LACKS. The first
        //    version said the cell "does not give a full date", which is
        //    untrue of `01.06.26`: that IS a full date, typed with a two-digit
        //    year, and PHP reads it as the TIME 01:06:26 (it stored the import
        //    day at 01:06:26 before this rule). The same goes for an Excel
        //    serial number with a decimal part (`46174.5`). What is true of
        //    every cell reaching here is that it could not be read as a
        //    complete date — and the example tells the administrator the fix.
        $why = 'could not be read as a complete date: what came out depended on the day '
            . 'of the import. Give the day, the month and a four-digit year, for example '
            . '2026-06-01 10:00';
        return null;
    }

    // (b) AN IMPOSSIBLE CALENDAR DAY, IN ANY SPELLING. `strtotime()` rolls one
    //     forward silently, so `2026-02-31 10:00` was stored as 2026-03-03
    //     10:00:00 -- three days out -- and the row reported `ok => true`,
    //     "Imported as event #N", while the preview screen printed the cell
    //     raw so nothing warned anybody.
    //
    //     Round 12 guarded it with `checkdate()` on a zero-padded ISO prefix,
    //     which is a spelling list wearing a rule's clothes: `2026-2-31`,
    //     `02/31/2026`, `2/31/2026`, `Feb 31 2026` and `31 February 2026` all
    //     still rolled. Asking what PHP PARSED covers every spelling.
    //
    // 🚨 BUT `date_parse()` IS A DIFFERENT PARSER FROM `strtotime()`, AND
    //    ROUND 13 MISSED THAT. It does not understand an ISO ordinal date:
    //    `2026-152` comes back as day 152 of month 1 with "The parsed date was
    //    invalid", while `strtotime()` reads it correctly as 1 June. Refusing
    //    on any warning therefore refused a value the fallback handles -- a
    //    check crying wolf (#483) inside the rule written to stop a check
    //    crying wolf.
    //
    //    So the test is the HARM, stated exactly: a day somebody could have
    //    typed (1 to 31) in a month that does not have it. Day 152 is not
    //    such a day, so an ordinary parser disagreement falls through.
    //
    // 🚨 ROUND 14's VERSION OF THIS LET AN IMPOSSIBLE DATE THROUGH IN ELEVEN
    //    SPELLINGS, under a comment saying "IN ANY SPELLING". It required the
    //    day to be 1-31, the month 1-12 and the year to be a number, so every
    //    cell whose broken component was a ZERO, or which had no year at all,
    //    fell straight to `strtotime()` and was rolled silently. Measured,
    //    round 13 refused every one of these and round 14 stored them:
    //
    //      2026-06-00 10:00   -> 31 May 2026        (day zero)
    //      Feb 0 2026 10:00   -> 31 January 2026    (day zero, word month)
    //      2026-00-10 10:00   -> 10 December 2025   (month zero)
    //      Feb 31 10:00       -> 3 March 2026       (no year at all)
    //      0000-00-00 10:00   -> -0001-11-30        (the MySQL zero date)
    //
    //    The last is the worst: `-0001-11-30` is out of range for a DATETIME,
    //    and with the portal's strict mysqli reporting `execute()` THROWS. The
    //    insert loop had no try/catch then, so the whole import stopped
    //    part-way with earlier rows already written, no per-row report and no
    //    activity record — where round 13 had refused the cell politely at
    //    preview time. (#790 has since given the loop one: a throw now
    //    refuses that one row and the rest carry on.) A CSV exported from a legacy MySQL dump contains
    //    exactly that value.
    //
    // 🧮 SO THE RANGE TEST IS WHAT IT SHOULD ALWAYS HAVE BEEN: anything a
    //    calendar day or month COULD be, including zero, with the year the
    //    cell itself gives. (This said "with the year taken from
    //    `strtotime()`'s own assumption when the cell gives none" until #797:
    //    a cell with no year now never reaches here, and the code below
    //    requires one.)
    //
    //    The upper bounds are what still lets an ISO ordinal date through:
    //    `date_parse('2026-152')` answers day 152, which no month has a
    //    152nd of, so it is a PARSER DISAGREEMENT rather than an impossible
    //    date — `strtotime()` reads it correctly as 1 June. A day above 31 or
    //    a month above 12 therefore falls through on purpose.
    $parsed = date_parse($trimmed);
    if (count($parsed['errors']) > 0) {
        return null;
    }
    // ⚠️ THE YEAR IS REQUIRED HERE NOW. This used to fall back to
    //    `(int) date('Y')` for a cell with no year; since (a2) refuses every
    //    such cell before it gets here, that fallback could never run, and a
    //    clause that cannot run is not kept dressed as a safeguard.
    if (is_int($parsed['year']) === true
        && is_int($parsed['month']) === true
        && is_int($parsed['day']) === true
        && $parsed['day'] <= 31
        && $parsed['month'] <= 12
        && checkdate($parsed['month'], $parsed['day'], $parsed['year']) === false) {
        $why = 'is a date that does not exist';
        return null;
    }

    // (c) 🚨 AND FINALLY, TEST THE VALUE THIS ACTUALLY RETURNS — not the
    //        spelling it came from. Everything above asks about the INPUT, and
    //        that is how rounds 12, 13, 14 and 15 each closed a spelling list
    //        and left the class open. This asks the only question that decides
    //        whether the row can be stored at all.
    //
    //        A `DATETIME` column is DOCUMENTED to hold 1000-01-01 00:00:00 to
    //        9999-12-31 23:59:59 — but measured on MySQL 8.0.36 in strict
    //        mode it REFUSES only a negative or five-digit year and ACCEPTS
    //        0001-0999 without a warning (round 22). So the shape test is
    //        what stops the insert throwing, and the year-1000 floor is this
    //        portal's own rule. (This sentence said "and NOTHING ELSE" until
    //        round 23 found it here after round 22 had corrected it
    //        elsewhere.) `date()` will cheerfully format a
    //        moment outside that range, and `strtotime()` reaches such moments
    //        from ordinary-looking cells. Measured on the live closure before
    //        this test existed:
    //
    //          @-62167219200                  ->  -0001-12-31 23:58:45
    //          @999999999999                  ->  33658-09-27 02:46:39
    //          9999-12-31 +1 day              ->  10000-01-01 00:00:00
    //          2026-06-01 +1000000 years      ->  1002026-06-01 00:00:00
    //          9999-12-31 next year           ->  10000-12-31 00:00:00
    //
    //        `-0001-12-31 …` is the SAME SHAPE round 14 wrote up as the worst
    //        thing in this package (`-0001-11-30`, from a MySQL zero date).
    //        Under the portal's strict mysqli reporting `execute()` THROWS on
    //        such a literal, and the insert loop had no try/catch of its own
    //        then — so the import stopped part-way **with earlier rows already
    //        written**, no per-row report, and the activity record never
    //        reached. (#790 has since given the loop one, so a throw now
    //        refuses only its own row; the shape test still matters, because
    //        it refuses the row at preview with a reason a person can act on.) An administrator sees a preview saying every row is fine
    //        and then an import that half happened, with no list of which half.
    //
    //        Round 14 closed `0000-00-00`, the one spelling it was handed.
    //        This repository's own rule from round 11 is "follow the value to
    //        the thing that finally produces it": ONE test on the returned
    //        string covers every spelling at once, including the ones nobody
    //        has thought of.
    //
    //        It is deliberately NOT a date-range check on `$parsed`: the
    //        reachable cases are relative expressions and `@`-timestamps,
    //        where `date_parse()` reports no year at all. The returned string
    //        is the one place every path meets.
    //        The test itself is the WRAPPER above (`$fmtStore`), because every
    //        step of this closure can return a value and a test here would
    //        only have covered this one. Measured: `0500-01-01 10:00` returns
    //        from step 2 and a test placed here never saw it.

    // (d) 🕐 THE HOUR THAT DOES NOT EXIST (#785). This step builds a MOMENT
    //        with `strtotime()` and formats it back, and on the morning the
    //        clocks go forward the time a cell names may not exist in this
    //        zone, so PHP MOVES it. Measured on this closure before this test
    //        existed:
    //
    //          Europe/London     Sun, 29 Mar 2026 01:30   ->  02:30 stored
    //          Europe/London     03/29/2026 01:30         ->  02:30 stored
    //          America/New_York  Sun, 8 Mar 2026 2:30am   ->  03:30 stored
    //          America/Nuuk      28 March 2026 11:30pm    ->  29 March 00:30
    //
    //        -- the last one a whole DAY late -- while the SAME reading
    //        written `2026-03-29 01:30` goes through step 2 and is stored as
    //        typed. So the stored hour depended on how the spreadsheet
    //        happened to spell the date, and the comment at the INSERT below
    //        ("puts the text into the column's shape without resolving
    //        anything") was untrue of this step. Found by
    //        `tools/audit-checks/check_wall_clock_writes_not_moments.py`, the
    //        first run it ever had.
    //
    //        The test asks whether the round trip CHANGED the clock time the
    //        cell wrote. `date_parse()` reads the parts as written and resolves
    //        nothing, so where it reports no zone and no relative hours or
    //        minutes, a difference can only be the move. A cell naming a ZONE
    //        (`... GMT`) is converted on purpose and is not asked; nor is a
    //        relative one (`... +1 hour`), whose difference is arithmetic, not
    //        a move -- each is left exactly as it was.
    //
    //        It REFUSES rather than storing the time as written, deliberately:
    //        a refused row is visible on the preview and can be corrected,
    //        while a guessed one is stored for ever. (Storing the written time
    //        would match step 2, but this step's date can itself have moved,
    //        as the Nuuk line shows, so there is no written value here that is
    //        safe to assemble.) Driven by section K of
    //        tools/clock-change-selftest.php.
    $relative = $parsed['relative'] ?? [];
    if ($parsed['is_localtime'] === false
        && is_int($parsed['hour']) === true
        && is_int($parsed['minute']) === true
        && (int) ($relative['hour'] ?? 0) === 0
        && (int) ($relative['minute'] ?? 0) === 0
        && (int) ($relative['second'] ?? 0) === 0
        && date('G:i', $moment) !== sprintf('%d:%02d', $parsed['hour'], $parsed['minute'])) {
        $why = 'names a time that does not exist on that day in this time zone, because '
            . 'the clocks go forward then. It was refused rather than stored at a time '
            . 'nobody typed';
        return null;
    }

    $why = '';
    return date('Y-m-d H:i:s', $moment);
};

$parseCsv = static function (string $tmpFile) use ($siteId, $mysqli, $fmtStore): array {
    $handle = fopen($tmpFile, 'r');
    if ($handle === false) {
        return ['rows' => [], 'errors' => ['Could not open uploaded file.']];
    }
    // The separator, the quote and the escape character are each written out,
    // as the values PHP has always used here. Left to its default, PHP 8.4 and
    // later log a notice for every file read, because that default is going to
    // change; written out, the file is read exactly as before on every version.
    $header = fgetcsv($handle, null, ',', '"', '\\');
    if ($header === false || count($header) < 2) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must have at least 2 columns including eventName + startDateTime.']];
    }
    // 🧹 Normalise headers — strip BOM, lowercase, trim
    $header = array_map(static function ($h): string {
        return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h)));
    }, $header);

    $colMap = array_flip($header);
    if (isset($colMap['eventname']) === false || isset($colMap['startdatetime']) === false) {
        fclose($handle);
        return ['rows' => [], 'errors' => ['CSV must contain eventName and startDateTime columns. Found: ' . implode(', ', $header)]];
    }

    // 🗂️ Existing category + type lookup (case-insensitive on name)
    $catMap = [];
    $stmt = $mysqli->prepare('SELECT categoryID, LOWER(categoryName) AS n FROM tblEventCategories WHERE siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $catMap[$r['n']] = (int) $r['categoryID'];
        }
        $stmt->close();
    }
    $typeMap = [];
    $stmt = $mysqli->prepare('SELECT typeID, LOWER(typeName) AS n FROM tblEventTypes WHERE siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();


        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $typeMap[$r['n']] = (int) $r['typeID'];
        }
        $stmt->close();
    }

    $rows   = [];
    $errors = [];
    $rowNum = 1;  // header is row 1
    while (($data = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        $rowNum++;
        if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
            continue;  // empty row
        }

        $get = static function (string $key) use ($data, $colMap): string {
            $idx = $colMap[$key] ?? null;
            if ($idx === null || array_key_exists($idx, $data) === false) {
                return '';
            }
            return trim((string) $data[$idx]);
        };

        $name      = $get('eventname');
        $start     = $get('startdatetime');
        $end       = $get('enddatetime');
        $allDay    = strtolower($get('isallday'));
        $location  = $get('locationname');
        $desc      = $get('description');
        $status    = strtolower($get('status'));
        $catName   = strtolower($get('category'));
        $typeName  = strtolower($get('type'));
        $isPubStr  = strtolower($get('ispublic'));
        $isFeatStr = strtolower($get('isfeatured'));

        $rowErr = [];
        if ($name === '')  { $rowErr[] = 'eventName required'; }
        if ($start === '') { $rowErr[] = 'startDateTime required'; }
        // 🕐 THE CHECK COMPARES WHAT WILL ACTUALLY BE STORED (#777 round 9).
        //    `$fmtStore()` is the one thing that decides a cell's stored value,
        //    so asking IT both questions is the only comparison that cannot
        //    disagree with storage. See its definition above for the two
        //    measured faults that got us here.
        //
        //    `Y-m-d H:i:s` sorts the way a clock does, which is #435's own fix,
        //    so once both cells are in that shape the comparison is text and
        //    nothing is resolved into a moment.
        //    ⚠️ ROUND 9 WROTE THE PARAGRAPH ABOVE AND LEFT `strtotime()` ON
        //       THE LINE BELOW IT, where it stayed until round 11 (#777). So
        //       the comment promising one decider sat directly on top of two,
        //       which is worse than no comment: the next reader checks the
        //       claim, not the code. Measured, the two really did disagree in
        //       both directions --
        //
        //         `2026-06-01 10`    strtotime REFUSES, `$fmtStore` stores it
        //                            as 10:00:00. So that text was refused in
        //                            the START column as "not parseable",
        //                            which was untrue, while the identical
        //                            text in the END column was accepted.
        //         `2026-02-31 10:00` strtotime ACCEPTS it, rolling an
        //                            impossible date three days forward.
        //
        //       ⚠️ THE SECOND EXAMPLE'S CONCLUSION WAS WRONG WHEN I WROTE IT,
        //          and round 12 measured it. I said it refused
        //          `2026-02-31` and the row "was then refused further down
        //          under a different message". It did NOT refuse it: the
        //          fallback rolled the date forward too, so the row was
        //          IMPORTED as a success with the start three days out. The
        //          claim is exactly the kind that stops the next reader
        //          looking, in the comment written to record a fault of the
        //          same shape. `$fmtStore` refuses it NOW -- see the two
        //          narrowings at the end of that closure.
        //
        //       ⚠️ A SENTENCE HERE SAID "Nothing was ever stored wrongly,
        //          because the store step guards both cells" -- FOUR LINES
        //          BELOW the admission, in this same block, that a row WAS
        //          imported with its start three days out. Round 12 wrote the
        //          correction and left the sentence it falsifies standing.
        //          The false half was the reassuring one, and it was the
        //          first general claim a reader reached -- exactly what the
        //          lines above warn about. Removed rather than reworded.
        //
        //       What was wrong was WHICH rows were refused, what they were
        //       told, and -- for an impossible date -- what got stored.
        $startWhy    = null;
        $startStored = $start !== '' ? $fmtStore($start, $startWhy) : null;
        if ($start !== '' && $startStored === null) {
            $rowErr[] = 'startDateTime ' . (string) $startWhy;
        }
        if ($end !== '') {
            $endWhy    = null;
            $endStored = $fmtStore($end, $endWhy);
            if ($endStored === null) {
                $rowErr[] = 'endDateTime ' . (string) $endWhy;
            } elseif ($startStored !== null && $endStored < $startStored) {
                $rowErr[] = 'endDateTime is before startDateTime';
            }
        }

        // ⚠️ Exactly `tblEvents.status`'s ENUM — see #676. `archived` was
        //    accepted here and the column has never had it, so a CSV row
        //    carrying it passed validation and then failed on the insert.
        if ($status !== '' && in_array($status, ['draft', 'published', 'cancelled', 'postponed'], true) === false) {
            $rowErr[] = 'status must be draft|published|cancelled|postponed';
        }
        $catId  = $catName !== ''  ? ($catMap[$catName]  ?? null) : null;
        $typeId = $typeName !== '' ? ($typeMap[$typeName] ?? null) : null;
        if ($catName !== ''  && $catId  === null) { $rowErr[] = 'category not found: ' . $catName; }
        if ($typeName !== '' && $typeId === null) { $rowErr[] = 'type not found: ' . $typeName; }

        $rows[] = [
            'rowNum'        => $rowNum,
            'eventName'     => $name,
            'startDateTime' => $start,
            'endDateTime'   => $end !== '' ? $end : null,
            'isAllDay'      => in_array($allDay,    ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'locationName'  => $location,
            'description'   => $desc,
            'status'        => $status !== '' ? $status : 'draft',
            'categoryID'    => $catId,
            'typeID'        => $typeId,
            // D-079: an empty cell means members-only, as for every new event; only a yes makes it public.
            'isPublic'      => in_array($isPubStr,  ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'isFeatured'    => in_array($isFeatStr, ['1', 'true', 'yes', 'y'], true) ? 1 : 0,
            'errors'        => $rowErr,
            // 🧹 `_startTs` and `_endTs` were carried here and `unset()` on
            //    confirm without ever being read -- `grep -n "_startTs"` found
            //    only the write and the unset. They were the only readers of
            //    `$endTs`, which #777 round 9 removed when the row check
            //    stopped comparing moments, so keeping them would have left an
            //    undefined variable in a session payload.
        ];
    }
    fclose($handle);
    return ['rows' => $rows, 'errors' => $errors];
};

// -----------------------------------------------------------------------------
// 🚦 Handle POST
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $error = 'Invalid or expired form token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'preview' && isset($_FILES['csv_file']) === true) {
            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'File too large (2 MB max).';
            } else {
                $parsed = $parseCsv($file['tmp_name']);
                $preview = $parsed['rows'];
                if (count($parsed['errors']) > 0) {
                    $error = implode('; ', $parsed['errors']);
                }

                // 📌 ALREADY IN THE CALENDAR? (D-100, #877). Each row with no
                //    error is asked about, so the preview can say which rows
                //    the import will leave out unless "Import these anyway" is
                //    ticked. This mark is for the PERSON only: the confirm
                //    step asks the database again and never reads it, because
                //    the calendar can change in the minutes between the two
                //    pages. The start compared is `$fmtStore`'s output — what
                //    the import will store — never a moment (#777).
                //
                //    A fault asking is not a reason to refuse the preview:
                //    the row is simply not marked, and the confirm step asks
                //    again (and reports a fault there, row by row).
                //
                // 🔁 A ROW THE FILE ITSELF REPEATS (same name, capitals
                //    included, and same start as an EARLIER row) is marked
                //    too, as "repeats row N". Confirm leaves it out unless the
                //    tick is set, exactly as it does a row already in the
                //    calendar. Before this, the preview called such a row
                //    "Ready" while Confirm then skipped it — because its first
                //    copy had just been saved — so the two pages disagreed
                //    (found by the second independent check). The key is
                //    built the same way on both pages: the stored start, then
                //    the name's exact bytes.
                $firstRowFor = [];
                foreach ($preview as $i => $r) {
                    $preview[$i]['alreadyThere'] = null;
                    $preview[$i]['repeatsRow']   = 0;
                    if (count($r['errors']) > 0) {
                        continue;
                    }
                    $storedStart = $fmtStore((string) $r['startDateTime']);
                    if ($storedStart === null) {
                        continue;
                    }
                    $repeatKey = $storedStart . "\0" . (string) $r['eventName'];
                    if (isset($firstRowFor[$repeatKey]) === true) {
                        $preview[$i]['repeatsRow'] = $firstRowFor[$repeatKey];
                        continue;
                    }
                    $firstRowFor[$repeatKey] = (int) $r['rowNum'];
                    try {
                        $preview[$i]['alreadyThere'] = Events::alreadyInCalendar(
                            $mysqli,
                            $siteId,
                            (string) $r['eventName'],
                            $storedStart
                        );
                    } catch (\Throwable $notAsked) {
                        $preview[$i]['alreadyThere'] = null;
                    }
                }

                // 🧊 Stash parsed rows in the session so the confirm step
                //    doesn't need a second upload. Trim to JSON-safe shape.
                $_SESSION['eventsImportPreview'] = array_map(static function ($r) {
                    // (`_startTs`/`_endTs` are gone -- see the parse loop.)
                    return $r;
                }, $preview);
            }
        }

        if ($action === 'confirm') {
            $rows = $_SESSION['eventsImportPreview'] ?? [];
            if (count($rows) === 0) {
                $error = 'No preview data — re-upload the file.';
            } else {
                // 🕰️ THE ZONE (#604, reworded by #777 round 7). Each row's
                //    start and end are stored as the reading they already are,
                //    and `Site::timezone()` is the zone recorded beside them
                //    because **the spreadsheet belongs to this organisation**
                //    — somebody here typed those times on this
                //    organisation's clock.
                //
                // ⚠️ THAT REASON USED TO BE "they are read with strtotime()
                //    and written with date(), both on PHP's clock, which
                //    start-up sets to this organisation's zone". That
                //    mechanism was REMOVED in round 6 for the plain shapes,
                //    and the comment was left saying it — in the very commit
                //    whose finding 2 quoted this comment as part of the
                //    fault. The conclusion was right throughout; the stated
                //    reason now sends the next reader to a mechanism that is
                //    only the fallback. WHAT WAS WRONG: this
                //    statement named no zone at all, so every imported event
                //    took the column's default, "Europe/London", wherever the
                //    organisation was. WHAT IT CANNOT DO: take a zone per row.
                //    The file has no column for one.
                $importZone = Site::timezone();
                // 🌐 #493 P1: the public-website columns are NOT listed, so
                //    every imported row is OFF the website with no dates: a
                //    spreadsheet cannot put events in front of the internet,
                //    and a column the file happens to carry is never read.
                //
                // ⚠️ `prepare()` THROWS under the portal's strict reporting
                //    (`MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR` — it takes
                //    BOTH), so the `=== false` test that used to follow it
                //    could never be reached. It is caught here instead: nothing
                //    has been written yet, so "nothing was imported" is true,
                //    and the file's rows stay in the session so Confirm can be
                //    pressed again once the fault is put right (#790).
                $stmt = false;
                try {
                    $stmt = $mysqli->prepare(
                        'INSERT INTO `tblEvents` '
                        . '(`siteID`, `eventName`, `eventSlug`, `description`, `startDateTime`, `endDateTime`, `timezone`, '
                        . '`isAllDay`, `locationName`, `status`, `isPublic`, `isFeatured`, `categoryID`, `typeID`, `createdByID`) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                } catch (\Throwable $prepareFault) {
                    $stmt = false;
                    Logger::errorPlatform(
                        'MySQL',
                        'Error',
                        'CALENDAR_IMPORT_PREPARE',
                        'A calendar file import could not start: the database refused to prepare the insert',
                        $prepareFault->getMessage()
                    );
                }
                if ($stmt === false) {
                    $error = t('error.db_import_prepare') . ' Nothing was imported. Press Confirm to try again.';
                    $preview = $rows;
                } else {
                    $okCount     = 0;
                    $notCount    = 0;
                    // 📌 Rows left out because the calendar already holds the
                    //    same event (D-100), counted apart from refusals: the
                    //    row was fine, the import chose not to repeat it.
                    $presentCount = 0;
                    // 🔁 Rows left out because they repeat an earlier row of
                    //    the same file (D-100), and the first row of the file
                    //    for each name and start.
                    $repeatCount  = 0;
                    $firstRowSeen = [];
                    // ☑️ "Import these anyway" (D-100). An unticked box is not
                    //    sent at all, and NOT SENT MEANS NO here — the safe
                    //    default the owner chose — so this asks for the exact
                    //    value the box sends rather than whether a field
                    //    arrived.
                    $importExisting = (($_POST['importExisting'] ?? '') === '1');
                    // 🔌 Set when the CONNECTION is lost part-way. From then on
                    //    nothing more is sent: every later row would fail the
                    //    same way, and saying so once, truthfully, is better
                    //    than a list of identical failures.
                    $lostAtRow   = 0;
                    $unknownCount = 0;
                    // 📋 What goes to Admin → Errors is gathered here and
                    //    written ONCE, after the loop. One record per refused
                    //    ROW would put a spreadsheet's worth of lines on that
                    //    page every time a file of refused rows is imported
                    //    again — and, where an installation has chosen to be
                    //    e-mailed about "Error" records, a message per row.
                    $rowLines     = [];
                    $serverLines  = [];
                    $faultLines   = [];
                    $lostLine     = '';
                    $lostCode     = 0;

                    // 🔌 WHICH CODES MEAN "THE CONNECTION WENT", not "the row
                    //    was refused". A connection that goes WHILE a row is
                    //    being saved leaves that row's outcome unknown, so the
                    //    two must never be confused — in either direction.
                    //
                    // ⚠️ THE FIRST VERSION SAID "2000 AND ABOVE ARE THE
                    //    CLIENT's CODES", and that was untrue. Only 2000-2999
                    //    are the client library's. MySQL's own refusals carry
                    //    3000 and up (3819 is a CHECK rule a row breaks, 3100 a
                    //    replication hook refusing it) and MariaDB's 4000 and
                    //    up. Measured by the independent check with a trigger
                    //    raising 3819: the row was reported "Not known: the
                    //    connection to the database was lost", the valid row
                    //    after it was never tried — while the activity record,
                    //    written over that same connection, WAS written,
                    //    proving the connection had never gone.
                    //
                    //    So: 2000-2999 (2006 "gone away" and 2013 "lost during
                    //    query" are the ones that happen), plus the one SERVER
                    //    code on each engine that means the server ended the
                    //    connection — MySQL's 4031 (disconnected for being idle
                    //    too long) and MariaDB's 1927 (the connection was
                    //    killed). Each is asked only of its own engine,
                    //    because the two number their codes independently and
                    //    a number one uses may mean something else to the
                    //    other. Which engine this is comes from
                    //    `DbServer::classify()`, the ONE place that answers
                    //    that question; `server_info` is what the connection
                    //    already holds, so asking sends nothing.
                    $onMariaDb   = DbServer::classify((string) $mysqli->server_info)['family'] === 'mariadb';
                    $lostTheLine = static function (int $code) use ($onMariaDb): bool {
                        if ($code >= 2000 && $code <= 2999) {
                            return true;
                        }
                        return $onMariaDb === true ? $code === 1927 : $code === 4031;
                    };

                    // 🧾 WHICH REFUSALS ARE ABOUT THE ROW, and which are about
                    //    the DATABASE. The two go to different people.
                    //
                    //    A refusal about the ROW is the file's problem, and the
                    //    organisation administrator looking at the results list
                    //    can fix it: a duplicate web address (1062), a value
                    //    too long (1406), out of range (1264), cut short
                    //    (1265), of the wrong kind (1366) or not a real date or
                    //    time (1292), an empty required value (1048), a
                    //    category or type that is not there (1452), or a CHECK
                    //    rule the row breaks (MySQL 3819, MariaDB 4025). Each of
                    //    those is decided by the row's own values.
                    //
                    //    1644 IS DIFFERENT AND IS ON THIS SIDE BY CHOICE. It is
                    //    ANY trigger's or stored procedure's refusal, whatever
                    //    the reason: a SIGNAL with SQLSTATE '45000', 'HY000' or
                    //    '22000' and no MYSQL_ERRNO raises 1644 every time
                    //    (measured by the third independent check). The portal
                    //    ships no trigger on `tblEvents`, so a 1644 here comes
                    //    only from code the people running the installation
                    //    added. It is kept as "Notification" because that
                    //    trigger's own message is shown to the administrator on
                    //    the results list, which is the trigger's way of
                    //    explaining itself. An installation whose trigger
                    //    signals a condition about the SERVER rather than the
                    //    row should give that SIGNAL its own MYSQL_ERRNO, and it
                    //    will then be recorded as "Error" like any other.
                    //
                    //    EVERY OTHER refusal is about the database itself — a
                    //    full table or disk (1114, 1021), a database set to
                    //    read-only (1290, 1836), a lock wait or deadlock
                    //    (1205, 1213), a replication hook (3100) — and the
                    //    person looking at the list cannot fix it. The
                    //    installation's administrator, who may be a different
                    //    party, can. So those are recorded as "Error", the
                    //    level an installation can choose to be e-mailed about.
                    //
                    // ⚠️ THE FIRST VERSION RECORDED EVERY REFUSAL AS
                    //    "Notification" under a comment calling every refused
                    //    row "the file's problem". For a full disk or a
                    //    read-only database that was untrue, and an
                    //    installation that had asked to be e-mailed about
                    //    "Error" stopped hearing about exactly the faults only
                    //    it could fix. Found by the second independent check.
                    //
                    //    An unknown code falls on the "Error" side on purpose:
                    //    wrongly telling the installation's administrator is a
                    //    nuisance, wrongly not telling them hides a fault.
                    //    3819 and 4025 are asked only of their own engine, for
                    //    the reason given above for 4031 and 1927.
                    $describesTheRow = static function (int $code) use ($onMariaDb): bool {
                        if (in_array($code, [1048, 1062, 1264, 1265, 1292, 1366, 1406, 1452, 1644], true) === true) {
                            return true;
                        }
                        return $onMariaDb === true ? $code === 4025 : $code === 3819;
                    };
                    foreach ($rows as $r) {
                        if (count($r['errors'] ?? []) > 0) {
                            $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => implode('; ', $r['errors'])];
                            $notCount++;
                            continue;
                        }
                        if ($lostAtRow > 0) {
                            $results[] = [
                                'ok'  => false,
                                'row' => $r['rowNum'],
                                'msg' => 'Not imported: the connection to the database was lost at row ' . $lostAtRow
                                    . ', so this row was never sent. Nothing from it was saved.',
                            ];
                            $notCount++;
                            continue;
                        }

                        // 🧱 ONE ROW, ONE OUTCOME (#790). Everything that can
                        //    throw for this row is inside this `try`, so a
                        //    throw ends THIS ROW — reported, with its reason —
                        //    and never the import.
                        //
                        //    WHAT WAS WRONG. There was no `try` here at all.
                        //    Under strict reporting `execute()` throws rather
                        //    than answering false, so ONE refused row stopped
                        //    the whole run: the rows before it were already
                        //    saved and stayed saved, the rows after it were
                        //    never tried, the administrator got an error page
                        //    instead of this list, and the activity record
                        //    below was never written. The `'DB error: '`
                        //    message beside `execute()` could never be shown.
                        //    Measured before the change, with the database
                        //    refusing the middle row of a three-row file: an
                        //    HTTP 500, row 2 saved, row 4 never tried, no
                        //    activity record — and because the rows stayed in
                        //    the session, pressing Confirm again tried row 2 a
                        //    SECOND time and was refused on its web address.
                        //
                        //    #777 round 21 closed the one cause anybody had
                        //    measured (a date a DATETIME cannot hold). This
                        //    closes the SHAPE: any cause — a column too long,
                        //    a duplicate web address, a server setting —
                        //    now refuses one row, visibly.
                        //
                        //    WHY EACH ROW ON ITS OWN, NOT ALL OR NOTHING. The
                        //    page already promised a list of which rows were
                        //    imported and which were skipped, and a long file
                        //    failing whole on one bad row is worse for somebody
                        //    importing a year of events. The owner chose this
                        //    over all-or-nothing: D-099.
                        //
                        //    A single INSERT is saved whole or not at all, so a
                        //    row refused by the database leaves nothing behind.
                        //    The one case where that cannot be promised is a
                        //    connection lost WHILE the row was being saved:
                        //    the server may have saved it before the
                        //    connection went, and this page cannot ask. That
                        //    case says so instead of guessing.
                        $savedAs = 0;
                        $refusal = '';
                        $unknown = false;
                        $slug    = '';
                        // 📤 Whether the row's INSERT was ever SENT. Two
                        //    questions go to the database before it (is the
                        //    event already there, is the address free), and a
                        //    connection lost during either of THOSE cannot have
                        //    saved anything — so "whether it was saved is not
                        //    known" would be untrue there (found by the second
                        //    independent check on #877). Set immediately before
                        //    each `execute()` of the INSERT.
                        $insertSent = false;
                        try {
                            // 🕐 STORE THE READING, DO NOT ROUND-TRIP IT (#777).
                            //    `tblEvents.startDateTime` holds a WALL-CLOCK
                            //    reading. This was
                            //    `date('Y-m-d H:i:s', strtotime($r['startDateTime']))`
                            //    -- a moment built and formatted back -- so on the
                            //    morning the clocks go forward a row reading 01:30
                            //    was imported as 02:30, permanently, and the
                            //    comment above described that round trip as the
                            //    design. Measured in Europe/London: `2026-03-29
                            //    01:30` came out `2026-03-29 02:30`.
                            //
                            //    A spreadsheet column has no offset in it, so
                            //    every value here is a reading on the clock named
                            //    by `$importZone` -- which is exactly why building
                            //    a moment was never needed. `$fmtStore()` puts the
                            //    text into the column's shape; its strict steps
                            //    resolve nothing, and its loose spreadsheet step,
                            //    which does build a moment, REFUSES a cell whose
                            //    time that moment moved (#785 -- until then this
                            //    sentence said "without resolving anything" and a
                            //    loose cell in the skipped hour was stored an hour
                            //    out). A row whose value cannot be read at all was
                            //    already refused upstream.
                            // 📌 `$fmtStore` is defined ONCE near the top of this file, so
                            //    the row CHECK and the row STORE cannot disagree (#777 round 9).

                            $startSql = $fmtStore((string) $r['startDateTime']);
                            $hasEnd   = $r['endDateTime'] !== null && $r['endDateTime'] !== '';
                            $endSql   = $hasEnd === true ? $fmtStore((string) $r['endDateTime']) : null;
                            if ($startSql === null) {
                                $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => 'startDateTime could not be read'];
                                $notCount++;
                                continue;
                            }
                            // 🚨 AN END TIME THAT CANNOT BE READ REFUSES THE ROW.
                            //    It used to be impossible to reach: the row check
                            //    upstream guaranteed `strtotime($end) !== false`.
                            //    The first version of this closure could answer
                            //    null for a value that check had passed, and only
                            //    the START was guarded -- so the row imported with
                            //    `ok => true` and the message "Imported as event
                            //    #N", **with no end time at all**, bound as SQL
                            //    NULL. Of the three harms that version caused, this
                            //    was the one nobody could see afterwards: the
                            //    preview screen prints the cell raw, so the
                            //    administrator reviewing the file sees exactly what
                            //    they typed and nothing warns them.
                            //
                            //    A refused row is visible and recoverable. A
                            //    silently emptied column is neither.
                            if ($hasEnd === true && $endSql === null) {
                                $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => 'endDateTime could not be read'];
                                $notCount++;
                                continue;
                            }

                            $name    = (string) $r['eventName'];
                            $desc    = (string) $r['description'];
                            $allDay  = (int) $r['isAllDay'];
                            $loc     = (string) $r['locationName'];
                            $status  = (string) $r['status'];
                            $isPub   = (int) $r['isPublic'];
                            $isFeat  = (int) $r['isFeatured'];
                            $catId   = $r['categoryID'] !== null ? (int) $r['categoryID'] : null;
                            $typeId  = $r['typeID']     !== null ? (int) $r['typeID']     : null;

                            // 📌 ALREADY IN THE CALENDAR? ASKED NOW, AT CONFIRM
                            //    (D-100, #877). The preview's mark is never
                            //    read here: an event added between the preview
                            //    and this press would otherwise be imported a
                            //    second time. `Events::alreadyInCalendar()` says
                            //    what counts (same name letter for letter, same
                            //    stored start, this organisation, not deleted,
                            //    copied-in events included) and why.
                            //
                            //    WHY THIS EXISTS AT ALL. Until #877 a second
                            //    import of the same file was refused row by row
                            //    because the web address was the name alone — a
                            //    safety net by accident, which #877 removed by
                            //    giving every row its own address. Without this,
                            //    re-uploading a file after fixing one bad row
                            //    (what D-099 tells a manager to do) silently
                            //    doubled every event. The owner chose: skip such
                            //    a row unless "Import these anyway" is ticked.
                            //
                            //    A row the FILE repeats (same name, same stored
                            //    start as an earlier row) never reaches this
                            //    question: the repeat test just below runs
                            //    first and labels it "Repeats an earlier row",
                            //    whatever happened to its first copy. With the
                            //    tick set, neither test runs, so the calendar
                            //    is not asked at all.
                            // 🔁 A repeat of an EARLIER ROW OF THIS FILE (same
                            //    stored start, same name byte for byte) is
                            //    left out unless the tick is set — the same
                            //    rule, worked out the same way, as the
                            //    preview's "repeats row N" mark, so the two
                            //    pages agree. Asked BEFORE the database, so the
                            //    row is described as what it is (a repeat in
                            //    the file) even when its first copy was saved
                            //    a moment ago, and even when that first copy
                            //    was refused.
                            $repeatKey = $startSql . "\0" . $name;
                            $repeatOf  = $firstRowSeen[$repeatKey] ?? 0;
                            if ($repeatOf === 0) {
                                $firstRowSeen[$repeatKey] = (int) $r['rowNum'];
                            } elseif ($importExisting === false) {
                                $results[] = [
                                    'ok'     => false,
                                    'repeat' => true,
                                    'row'    => $r['rowNum'],
                                    // ✂️ ONE WORDING, WHATEVER HAPPENED TO ROW N.
                                    //    It states only what is certain and
                                    //    points at row N's own line. Three
                                    //    rounds of the independent check each
                                    //    found a sentence GUESSING what had
                                    //    happened to row N wrong for some
                                    //    reason row N could have (imported;
                                    //    refused for a fault in the row or in
                                    //    the database; given up on after three
                                    //    address tries; already in the
                                    //    calendar), so the guess is gone rather
                                    //    than refined a fourth time.
                                    'msg'    => 'Repeats row ' . $repeatOf . ' of this file (the same name, capitals '
                                        . 'included, and the same start date and time), so it was left out. Nothing '
                                        . 'from it was saved. Row ' . $repeatOf . '\'s own line above says what '
                                        . 'happened to it. To import this row as a separate event anyway, import a '
                                        . 'file holding only this row and tick "Import these anyway".',
                                ];
                                $repeatCount++;
                                continue;
                            }

                            if ($importExisting === false) {
                                $there = Events::alreadyInCalendar($mysqli, $siteId, $name, $startSql);
                                if ($there !== null) {
                                    $results[] = [
                                        'ok'      => false,
                                        'present' => true,
                                        'row'     => $r['rowNum'],
                                        'eventID' => $there['eventID'],
                                        'slug'    => $there['eventSlug'],
                                        'msg'     => 'Already in the calendar: event #' . $there['eventID']
                                            . ' has this name and starts at the same date and time ('
                                            . substr($there['startDateTime'], 0, 16) . '), so this row was not '
                                            . 'imported again. Nothing from it was saved. To import it as well, '
                                            . 'import a file holding only this row and tick "Import these anyway". '
                                            . '(Importing this whole file again with the tick would also repeat '
                                            . 'every row it has just imported.)',
                                    ];
                                    $presentCount++;
                                    continue;
                                }
                            }

                            // 🔤 THE WEB ADDRESS: NAME, DATE, AND A FREE ONE
                            //    ASKED OF THE DATABASE (#877).
                            //
                            //    WHAT WAS WRONG. The address was made from the
                            //    NAME ALONE, and the database allows one address
                            //    per organisation (`uq_event_site_slug`). So a
                            //    file holding the same service every week — the
                            //    commonest file anybody imports — asked for one
                            //    address on every row, and every row after the
                            //    first was refused. Before #790 that refusal
                            //    stopped the import with an error page; after
                            //    #790 the rows were listed as not imported, with
                            //    advice to rename every week's service. A name an
                            //    existing event already had was refused too,
                            //    INCLUDING a deleted event's, because the key
                            //    covers deleted rows.
                            //
                            //    NOW the address is the name plus the date, as
                            //    the Manage Events form makes it, and
                            //    `Events::freeSlug()` asks the database for the
                            //    first one free — `-2`, `-3` … when two events
                            //    share a name AND a day, or a deleted event
                            //    still holds it. The ONE place both are decided
                            //    is `Portal\Core\Events`.
                            //
                            // 🏁 AND IF SOMEBODY TAKES IT FIRST. Between asking
                            //    and saving, another import or the Manage Events
                            //    form can take the same address. The key still
                            //    refuses the second writer — nothing is stored
                            //    twice — and that refusal is caught HERE and
                            //    the address asked for again, `$slugTries`
                            //    times at most, so the row is saved under the
                            //    next free address rather than refused. Every
                            //    other refusal goes straight to the `catch`
                            //    below, unchanged. Bounded, because a loop that
                            //    could run for ever against a unique key is
                            //    worse than a refusal.
                            $slugStem  = Events::slugStem($name, $startSql);
                            $slugTries = 3;
                            for ($slugTry = 1; ; $slugTry++) {
                                // 📤 Reset at the top of EVERY attempt: an
                                //    earlier attempt that was sent and REFUSED
                                //    (its address taken) saved nothing, so if
                                //    this attempt's address question then loses
                                //    the connection, nothing has been sent that
                                //    could have been saved. Left set, that case
                                //    read "Not known" — found by the third
                                //    independent check, measured on both engines.
                                $insertSent = false;
                                $slug = Events::freeSlug($mysqli, $siteId, $slugStem);
                                $stmt->bind_param(
                                    'issssssisssiiii',
                                    $siteId, $name, $slug, $desc, $startSql, $endSql, $importZone,
                                    $allDay, $loc, $status, $isPub, $isFeat,
                                    $catId, $typeId, $userId
                                );
                                try {
                                    // 🧷 `execute()` answering false is turned
                                    //    into the SAME refusal as a throw. Under
                                    //    today's strict reporting it never
                                    //    answers false; the line is here so that
                                    //    changing the reporting mode one day
                                    //    cannot bring back a second, untested
                                    //    path — the old `'DB error: '` branch
                                    //    was exactly that, dead beside the line
                                    //    that mattered.
                                    $insertSent = true;
                                    if ($stmt->execute() !== true) {
                                        throw new \mysqli_sql_exception((string) $stmt->error, (int) $stmt->errno);
                                    }
                                    break;
                                } catch (\mysqli_sql_exception $taken) {
                                    if ($slugTry < $slugTries
                                        && (int) $taken->getCode() === 1062
                                        && str_contains($taken->getMessage(), 'uq_event_site_slug') === true
                                    ) {
                                        continue;
                                    }
                                    throw $taken;
                                }
                            }
                            $savedAs = (int) $stmt->insert_id;
                        } catch (\mysqli_sql_exception $refused) {
                            $code = (int) $refused->getCode();
                            // 🔌 A lost connection, not a refusal: the server
                            //    may already have saved the row, so nothing is
                            //    promised. Which codes count is decided ONCE,
                            //    in `$lostTheLine` above.
                            if ($lostTheLine($code) === true) {
                                $lostAtRow = (int) $r['rowNum'];
                                $lostCode  = $code;
                                $lostLine  = $refused->getMessage();
                                // Not known ONLY when the INSERT itself was
                                // sent; lost while asking a question first
                                // means nothing was sent, so nothing was saved.
                                $unknown   = $insertSent;
                                $refusal   = $insertSent === true
                                    ? 'Not known: the connection to the database was lost while this row was being '
                                        . 'saved (error ' . $code . '), so this page cannot tell whether it was saved. '
                                        . 'Check Manage Events before importing this row again.'
                                    : 'Not imported: the connection to the database was lost before this row was sent '
                                        . '(error ' . $code . '), so nothing from it was saved. Import it again once '
                                        . 'the database is back.';
                            } elseif ($code === 1062 && str_contains($refused->getMessage(), 'uq_event_site_slug') === true) {
                                // 🔤 Reached only when the address was taken
                                //    EVERY time it was asked for (`$slugTries`
                                //    above): the database said it was free, and
                                //    then another event took it before this row
                                //    could be saved, again and again. That means
                                //    something else is adding events with the
                                //    same name on the same day at the same
                                //    moment — another import, most likely.
                                //
                                // ⚠️ THE OLD SENTENCE IS GONE ON PURPOSE (#877).
                                //    It said another event "already has the web
                                //    address made from this name" and told the
                                //    administrator to rename the event. The
                                //    address is no longer made from the name
                                //    alone and a taken one is no longer
                                //    refused, so neither half is true any more;
                                //    renaming would not even be needed — the
                                //    same row imported again gets the next free
                                //    address.
                                $refusal = 'Not imported: every web address this page tried for this event ("' . $slug
                                    . '" was the last) was taken by another event at the moment the row was being saved '
                                    . '— most likely another import of the same events running at the same time. '
                                    . 'Nothing from this row was saved. Check Manage Events, then import this row '
                                    . 'again if it is not there.';
                            } elseif ($describesTheRow($code) === true) {
                                $refusal = 'Not imported: the database refused this row (error ' . $code . ': '
                                    . $refused->getMessage() . '). Nothing from this row was saved.';
                            } else {
                                $refusal = 'Not imported: the database refused this row (error ' . $code . ': '
                                    . $refused->getMessage() . '). Nothing from this row was saved. This is a '
                                    . 'problem with the database, not with the row, and it has been recorded for '
                                    . 'whoever looks after the portal.';
                            }
                            // A lost connection has its own record below,
                            // whether or not the INSERT was sent.
                            if ($lostTheLine($code) === false) {
                                $line = 'row ' . (int) $r['rowNum'] . ' (error ' . $code . '): ' . $refused->getMessage();
                                if ($describesTheRow($code) === true) {
                                    $rowLines[] = $line;
                                } else {
                                    $serverLines[] = $line;
                                }
                            }
                        } catch (\Throwable $fault) {
                            // 🧯 Anything else: a fault in THIS PAGE's own code
                            //    while getting the row ready. The insert is the
                            //    last thing in the `try` and only a database
                            //    refusal stops it part-way, so anything caught
                            //    here happened before the row was sent.
                            $refusal = 'Not imported: this row could not be got ready for saving because of a fault in '
                                . 'the portal, which has been recorded for whoever looks after it. Nothing from this '
                                . 'row was saved.';
                            $faultLines[] = 'row ' . (int) $r['rowNum'] . ': ' . get_class($fault) . ': '
                                . $fault->getMessage() . ' (' . $fault->getFile() . ':' . $fault->getLine() . ')';
                        }

                        if ($savedAs > 0) {
                            $results[] = ['ok' => true, 'row' => $r['rowNum'], 'msg' => 'Imported as event #' . $savedAs];
                            $okCount++;
                        } else {
                            $results[] = ['ok' => false, 'row' => $r['rowNum'], 'msg' => $refusal, 'unknown' => $unknown];
                            // A row whose outcome is not known is counted on
                            // its own: calling it "not imported" in the
                            // activity record would claim what the page
                            // cannot know.
                            if ($unknown === true) {
                                $unknownCount++;
                            } else {
                                $notCount++;
                            }
                        }
                    }
                    // 🧹 Closing a statement on a connection that has gone can
                    //    throw too, and must not take the results list with it.
                    try {
                        $stmt->close();
                    } catch (\Throwable $ignored) {
                        // Nothing to do: the statement goes with the request.
                    }
                    $imported = true;
                    // 📝 BOTH counts (#790). "N events created" alone read the
                    //    same for a clean import and for one that refused
                    //    half the file. `Logger::activity()` never throws; if
                    //    the connection has gone it cannot write either, and
                    //    the results list below says so. Rows left out as
                    //    already in the calendar (D-100) are a THIRD count:
                    //    they were not refused, and folding them into "not
                    //    imported" would make a harmless re-upload read like
                    //    a failing one.
                    Logger::activity(
                        'EventsImported',
                        'Bulk import: ' . $okCount . ' events created, ' . $presentCount . ' already in the calendar, '
                        . $repeatCount . ' repeated in the file, ' . $notCount . ' rows not imported'
                        . ($unknownCount > 0 ? ', ' . $unknownCount . ' row whose outcome is not known' : '')
                    );

                    // 📋 ONE record per kind, per import (see above), each
                    //    worded for what actually happened.
                    //
                    //    A refusal about the ROW (`$describesTheRow`) is the
                    //    file's problem, shown in full on the page the
                    //    administrator is looking at, so it is a
                    //    "Notification" — the lowest level, which the alert
                    //    e-mail leaves out unless an installation has asked
                    //    for it (`portal.alerts.severities`, by default
                    //    "Critical,Fatal").
                    //
                    //    A refusal about the DATABASE, a fault in the page and
                    //    a lost connection are the portal's problems, so they
                    //    are "Error". The titles carry no count, so the alert's
                    //    cool-down (keyed on platform, code and title) treats
                    //    repeated imports as the same thing — EXCEPT that the
                    //    singular title ("A row …") and the plural ("Rows …")
                    //    are two different keys, so one import refusing one row
                    //    and the next refusing two can send two alerts in one
                    //    cool-down window. The lost connection's record says the
                    //    connection was lost — never "refused" — and it
                    //    usually lands only in PHP's own log, because the
                    //    database it would be written to is the one that went.
                    if (count($rowLines) > 0) {
                        Logger::errorPlatform(
                            'MySQL',
                            'Notification',
                            'CALENDAR_IMPORT_ROWS_REFUSED',
                            count($rowLines) === 1
                                ? 'A row of a calendar file import was refused by the database'
                                : 'Rows of a calendar file import were refused by the database',
                            count($rowLines) . " row(s) refused:\n" . implode("\n", $rowLines)
                        );
                    }
                    if (count($serverLines) > 0) {
                        Logger::errorPlatform(
                            'MySQL',
                            'Error',
                            'CALENDAR_IMPORT_DATABASE_REFUSED',
                            count($serverLines) === 1
                                ? 'The database refused a row of a calendar file import for a reason of its own'
                                : 'The database refused rows of a calendar file import for a reason of its own',
                            count($serverLines) . " row(s) refused by the database itself, not because of the "
                                . "row:\n" . implode("\n", $serverLines)
                        );
                    }
                    if (count($faultLines) > 0) {
                        Logger::errorPlatform(
                            'PHP',
                            'Error',
                            'CALENDAR_IMPORT_ROW_FAULT',
                            count($faultLines) === 1
                                ? 'A row of a calendar file import could not be got ready for saving'
                                : 'Rows of a calendar file import could not be got ready for saving',
                            implode("\n", $faultLines)
                        );
                    }
                    if ($lostAtRow > 0) {
                        Logger::errorPlatform(
                            'MySQL',
                            'Error',
                            (string) $lostCode,
                            'The database connection was lost during a calendar file import',
                            'at row ' . $lostAtRow . ': ' . $lostLine
                        );
                    }
                    // The rows leave the session whatever happened: every row
                    // has an outcome above, and pressing Confirm again would
                    // otherwise try the IMPORTED rows a second time.
                    unset($_SESSION['eventsImportPreview']);
                }
            }
        }
    }
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-csv me-2"></i>Bulk Import Events</h1>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Manage
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($imported === true): ?>
    <?php if ($lostAtRow > 0): ?>
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            The connection to the database was lost at row <?php echo (int) $lostAtRow; ?>, so the import stopped
            there. The rows marked OK below were saved; the rows after it were not sent. This import may also be
            missing from the activity log, because that is written to the same database.
        </div>
    <?php endif; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Import results</h2></div>
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Row</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-8">Detail</div>
                </div>
                <?php foreach ($results as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo (int) $r['row']; ?></div>
                        <div class="col-12 col-md-2">
                            <?php if ($r['ok'] === true): ?>
                                <span class="badge bg-success">OK</span>
                            <?php elseif (($r['unknown'] ?? false) === true): ?>
                                <span class="badge bg-warning text-dark">Not known</span>
                            <?php elseif (($r['present'] ?? false) === true): ?>
                                <span class="badge bg-secondary">Already in the calendar</span>
                            <?php elseif (($r['repeat'] ?? false) === true): ?>
                                <span class="badge bg-secondary">Repeats an earlier row</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Skipped</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-8 small">
                            <?php echo htmlspecialchars((string) $r['msg'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (($r['present'] ?? false) === true): ?>
                                <a href="<?php echo htmlspecialchars(Site::url('calendar/event') . '?slug=' . rawurlencode((string) $r['slug']), ENT_QUOTES, 'UTF-8'); ?>">See the event</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary">Import another file</a>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Back to Manage Events</a>

<?php elseif (count($preview) > 0): ?>
    <div class="alert alert-info">
        <strong><?php echo count($preview); ?></strong> row(s) parsed. Review and confirm to import.
    </div>
    <div class="card shadow-sm mb-3">
        <div class="card-body p-0">
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-1">Row</div>
                    <div class="col-md-3">Name</div>
                    <div class="col-md-2">Start</div>
                    <div class="col-md-2">End</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-2">Validation</div>
                </div>
                <?php foreach ($preview as $r): ?>
                    <div class="portal-data-row">
                        <div class="col-6 col-md-1"><?php echo (int) $r['rowNum']; ?></div>
                        <div class="col-6 col-md-3"><?php echo htmlspecialchars((string) $r['eventName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['startDateTime'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) ($r['endDateTime'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-6 col-md-2 small"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 small">
                            <?php if (count($r['errors']) === 0 && ($r['alreadyThere'] ?? null) !== null): ?>
                                <?php $there = $r['alreadyThere']; ?>
                                <span class="badge bg-secondary">Already in the calendar</span>
                                <a href="<?php echo htmlspecialchars(Site::url('calendar/event') . '?slug=' . rawurlencode((string) $there['eventSlug']), ENT_QUOTES, 'UTF-8'); ?>">event #<?php echo (int) $there['eventID']; ?>, <?php echo htmlspecialchars(substr((string) $there['startDateTime'], 0, 16), ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php elseif (count($r['errors']) === 0 && (int) ($r['repeatsRow'] ?? 0) > 0): ?>
                                <span class="badge bg-secondary">Repeats an earlier row</span>
                                row <?php echo (int) $r['repeatsRow']; ?> of this file
                            <?php elseif (count($r['errors']) === 0): ?>
                                <span class="badge bg-success">Ready</span>
                            <?php else: ?>
                                <span class="badge bg-danger" title="<?php echo htmlspecialchars(implode('; ', $r['errors']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo count($r['errors']); ?> error(s)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
    // 📌 D-100: how many rows the import will leave out as already in the
    //    calendar. A count for the person only — the confirm step asks the
    //    database again.
    $alreadyCount = count(array_filter(
        $preview,
        static fn (array $row): bool => count($row['errors']) === 0 && ($row['alreadyThere'] ?? null) !== null
    ));
    $repeatRowsCount = count(array_filter(
        $preview,
        static fn (array $row): bool => count($row['errors']) === 0 && (int) ($row['repeatsRow'] ?? 0) > 0
    ));
    ?>
    <?php if ($alreadyCount > 0 || $repeatRowsCount > 0): ?>
        <div class="alert alert-warning">
            <i class="fa-solid fa-calendar-check me-1"></i>
            <?php if ($alreadyCount > 0): ?>
                <strong><?php echo (int) $alreadyCount; ?></strong> row(s) match an event already in the calendar.
            <?php endif; ?>
            <?php if ($repeatRowsCount > 0): ?>
                <strong><?php echo (int) $repeatRowsCount; ?></strong> row(s) repeat an earlier row of this file.
            <?php endif; ?>
            "The same" means the same name, capitals included, and the same start date and time. These rows will
            NOT be imported unless you tick <em>Import these anyway</em> below.
        </div>
    <?php endif; ?>
    <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="confirm">
        <?php
        // ☑️ D-100: ONE tick, off unless somebody ticks it. A PHP comment, not
        //    an HTML one, so the note is not sent to the browser. The rows it
        //    covers are asked about again on Confirm.
        ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="importExisting" value="1" id="importExisting">
            <label class="form-check-label" for="importExisting">
                Import these anyway: also import rows that match an event already in the calendar, or an
                earlier row of this file (the same name, capitals included, and the same start date and time).
                Each gets its own web address.
            </label>
        </div>
        <button type="submit" class="btn btn-success">
            <i class="fa-solid fa-upload me-1"></i> Confirm &amp; import valid rows
        </button>
        <a href="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
    </form>

<?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5">CSV format</h2>
            <p class="small">Required columns: <code>eventName</code>, <code>startDateTime</code>. Optional: <code>endDateTime</code>, <code>isAllDay</code>, <code>locationName</code>, <code>description</code>, <code>status</code> (draft|published|cancelled|postponed), <code>category</code>, <code>type</code>, <code>isPublic</code> (yes, y, true or 1 to make it public; empty or anything else means members-only), <code>isFeatured</code>. First row must be the header.</p>
            <p class="small">
                The same event can appear on many dates; each gets its own web address. A row with the same name
                (capitals included) and the same start date and time as an event already in the calendar, or as an
                earlier row of the file, is left out, so uploading a corrected file again adds only what is missing.
                The preview offers a tick to import such rows anyway.
            </p>
            <p class="small text-muted mb-0">
                Excel users: open your file in Excel, choose <em>File → Save As → CSV (Comma delimited) (.csv)</em>.
            </p>
        </div>
    </div>

    <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/import'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="preview">
        <div class="mb-3">
            <label for="csv_file" class="form-label">CSV file (max 2 MB)</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-upload me-1"></i> Upload &amp; preview
        </button>
    </form>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
