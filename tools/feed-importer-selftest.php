<?php
// Path: tools/feed-importer-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for the outside-calendar importer 🗓️🧪 (#514, part P6)
 * -----------------------------------------------------------------------------
 * `Portal\Core\FeedImporter` is the code that copies somebody else's published
 * calendar into this portal — and, crucially, **the code that decides which of
 * a customer's events get marked as removed.** Getting that wrong does not look
 * like a bug. It looks like an empty Sunday.
 *
 * Almost every mistake here is silent. A download that was cut short looks
 * exactly like a calendar somebody emptied. A repeating event that ran past a
 * limit looks exactly like a repeating event that ended. In both cases the
 * portal would quietly hide real events, the administrator would see a cheerful
 * "Refreshed" message, and nobody would find out until a member turned up to a
 * service that was not in the diary — or did not turn up to one that was.
 *
 * So this script builds a small made-up world in a throwaway database, serves
 * hand-written calendar files from a test server on this machine, runs REAL
 * refreshes through the real class, and checks exactly what ended up in the
 * database and exactly what the administrator was told.
 *
 * WHY IT NEEDS A DATABASE AND A SERVER, AND CANNOT BE A PLAIN SELF-TEST
 * --------------------------------------------------------------------
 * The calendar reader has one of those (`tools/ics-reader-selftest.php`, which
 * needs nothing but PHP) because reading a file is pure work with no outside
 * world in it. The importer is the opposite: what it does is decide which rows
 * to write and which rows to mark as removed, over a real connection, after a
 * real download that may fail in a dozen ways. None of that can be shown
 * without both.
 *
 * It therefore **REFUSES to run** rather than skipping quietly, because a test
 * that did not run has proved nothing — and a skipped check must never be read
 * as a pass.
 *
 * HOW TO GIVE IT WHAT IT NEEDS
 * ----------------------------
 * 1. A throwaway database whose name starts with `selftest_`, built from
 *    `web/_sql/full_schema.sql`. For example, with MySQL 8 in a container:
 *
 *        docker run -d --name p6db -e MYSQL_ROOT_PASSWORD=secret -p 3307:3306 \
 *          mysql:8.0.36 --character-set-server=utf8mb4 \
 *          --collation-server=utf8mb4_general_ci
 *        docker exec -i p6db mysql -uroot -psecret -e \
 *          "CREATE DATABASE selftest_p6feeds CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
 *        docker exec -i p6db mysql -uroot -psecret selftest_p6feeds < web/_sql/full_schema.sql
 *
 *    **It EMPTIES the tables it uses** (events, series, calendars, run history,
 *    tags, sign-ups, sites, users, settings). Give it a database of its own —
 *    in particular, never the one `tools/event-visibility-selftest.php --keep`
 *    has left a fixture in, because this would wipe it.
 *
 * 2. A test calendar server. This script writes the calendar files itself, so
 *    all you do is serve the folder it wrote them into:
 *
 *        SELFTEST_FEED_DIR=/tmp/p6cal php tools/feed-importer-selftest.php --write-calendars
 *        PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:9055 /tmp/p6cal/server.php &
 *
 *    `PHP_CLI_SERVER_WORKERS=6` IS NOT OPTIONAL and it is not tidiness. Several
 *    checks deliberately cut a download off half way. A single-process built-in
 *    server sits holding that dead connection and answers nothing else — one
 *    run of this set wedged for sixty-two minutes before this was understood.
 *    Six workers let the others carry on.
 *
 *    When you stop the server, **kill it by PORT, not by name**: its worker
 *    processes outlive the parent and carry only `server.php` on their command
 *    line, so a search by folder name misses them. For example,
 *    `lsof -ti :9055 | xargs kill`.
 *
 * 3. Then run it:
 *
 *        SELFTEST_DB_NAME=selftest_p6feeds SELFTEST_DB_PORT=3307 \
 *        SELFTEST_DB_PASS=secret SELFTEST_FEED_DIR=/tmp/p6cal \
 *        SELFTEST_FEED_PORT=9055 php tools/feed-importer-selftest.php
 *
 * The settings it reads, and nothing else — it never reads or writes anything
 * under `web/_auth_keys`, and it never loads `bootstrap.php`:
 *
 *   SELFTEST_DB_HOST   (default 127.0.0.1)   SELFTEST_DB_PORT (default 3306)
 *   SELFTEST_DB_USER   (default root)        SELFTEST_DB_PASS (default empty)
 *   SELFTEST_DB_NAME   (required; must start with selftest_)
 *   SELFTEST_FEED_DIR  (required; a scratch folder it writes calendar files to)
 *   SELFTEST_FEED_PORT (required; the port the test server listens on)
 *
 * THE NAME `calendar.test`
 * ------------------------
 * The portal refuses to fetch anything on a private address — that guard is
 * the whole of `Portal\Core\SafeFetch`, and `tools/safefetch-selftest.php`
 * checks that nothing under `web/` ever switches it off. So this script pins
 * the made-up name `calendar.test` to 127.0.0.1 and the port you gave, using
 * the test-only hook `SafeFetch::$testResolverOverride`. That hook is set HERE,
 * in a tool, never in the portal.
 *
 * EVERY DATE IS WORKED OUT FROM TODAY — AND CHECK 0 REFUSES IF ONE IS NOT
 * ----------------------------------------------------------------------
 * The portal keeps a calendar from thirty days before today to twelve months
 * after it (`FeedImporter::windowFor()`), and that period moves forward every
 * day. A date written into a calendar file therefore stops being read once
 * the period has moved past it — and every check built on that date then
 * fails on correct code, or, worse, passes on faulty code, for a reason that
 * has nothing to do with either.
 *
 * So every date in every calendar below is an offset from ONE reading of
 * today (`fi_today()`), every expected value is worked out from the same
 * offsets (`fi_datePlan()`, `fi_day()`), and the two clock-change nights of
 * part G are looked up in PHP's own time-zone data rather than written down.
 * Check 0 then reads every date back OUT OF THE FILES this script wrote and
 * refuses to run anything else if one of them is outside the period — so a
 * fixed date added in future is named the first time it goes stale, instead
 * of turning into a pile of unrelated failures.
 *
 * WHAT WAS WRONG HERE UNTIL 24 September 2026. Parts A to I were written with
 * fixed dates in October 2026 (and one in March 2027). Part J had already been
 * moved onto dates worked out from today for exactly this reason; A to I had
 * not. The fourth independent check measured it by running the whole test as
 * if it were a later day: from 1 November 2026 the test failed on CORRECT code
 * on every run (4 failures, and 51 from 1 December) — and from 1 December its
 * list of failures against round 1's original deletion fault (a cut-short
 * read comparing "at or before", which deletes a live event) was identical,
 * line for line, to its list against correct code. Nothing else guards that
 * fault. See `.claude-work/resume/p514-p6--verify-r4.md`, finding 1.
 *
 * The few dates still written as fixed text are fixed ON PURPOSE, and each
 * says why beside it: the long-ago filler of part D4 (it must sit before the
 * period, and check 0 confirms it does), the dates handed straight to a
 * method that never reads the clock (parts D and I), and part J0's dates,
 * which are handed to `fi_nextClocksBack()` explicitly so they cannot drift.
 *
 * WHAT IT CHECKS
 *   0. Every date this script writes into a calendar sits inside the period
 *      the portal keeps. If one does not, nothing else is run.
 *   A. An ordinary import: one-off, repeating and whole-day events; the
 *      identity, address, time zone and series row each one gets.
 *   B. Failures never delete: a refused address, a redirect to a private one,
 *      an error status, a file that is not a calendar, a file cut off half
 *      way, a file over the size limit, and an empty calendar.
 *   C. Removal, both directions: an event taken out of the calendar IS marked
 *      as removed; an event that comes back keeps its number and its sign-ups;
 *      a rename keeps its identity; two identifiers differing only in capital
 *      letters stay apart.
 *   D. **The removal boundary — the heart of this file.** A download that was
 *      cut short must never be treated as a complete picture. Every shape that
 *      has ever been measured getting this wrong is here, with a control
 *      beside it showing removal still happens when it should.
 *   E. Pausing, resuming and deleting a calendar.
 *   F. Categories, tags, and an event the outside calendar marked private.
 *   G. The two nights a year the clocks change, in both directions — the
 *      first of each inside the period, found in PHP's time-zone data.
 *   H. The per-calendar ceiling an administrator may set.
 *   I. The four faults a first independent check found, each in both
 *      directions (the error log that was never written, one awkward calendar
 *      killing the whole job, two category words the database treats as equal,
 *      and a message that contradicted its own numbers).
 *   J. The fault a SECOND independent check found: a repeating event cut short
 *      by its own limit reported an end point that was not true, and the
 *      importer deleted real events on the strength of it. Four shapes.
 *   K. The scheduled job: it survives a calendar that throws, prints its
 *      summary, and never prints a calendar's name or address.
 *   L. Part 7 of #514, the proofs that need a REAL refresh or the real job
 *      (#514 part P7 plan, E17-E20 and E30): a failed refresh changes no
 *      choice, rule or approval; a removed event's waiting row is withdrawn
 *      (by ANY caller that works answers out); the history row records every
 *      date still waiting; the job's recheck pass works out answers that have
 *      run out, oldest first, and logs a failure as its own. Part L runs on
 *      the REAL clock only, by design: a real refresh needs the database
 *      clock to MOVE (see `fi_refresh()`), and `SET timestamp` freezes it.
 *      Every moment part L writes comes from ONE reading of the database's
 *      clock (`fl_dbNowUtc()`). The resolver's own proofs, which need no
 *      moving clock, are in `tools/feed-resolver-selftest.php`.
 *   M. (Not proven: a real Google or Microsoft 365 export — see below.)
 *
 * WHAT THIS CANNOT PROVE
 *   - **It has never seen a real Google or Microsoft 365 calendar.** There are
 *     no captured exports in this repository (the owner's decision of
 *     21 September 2026), so the acceptance criterion "works against a real
 *     Google or Microsoft 365 calendar" is NOT proven here or anywhere else.
 *     The calendar files below are hand-written to match what those systems are
 *     documented to produce. That is not the same thing.
 *   - It fetches over plain HTTP from a server on this machine, so it says
 *     nothing about an https fetch against a real certificate.
 *   - It has only been run against MySQL 8.0. MariaDB is untested.
 *   - Two things are deliberately left to other proofs, because they need a
 *     second running portal rather than a database and a calendar server: the
 *     admin pages at `/admin/calendar/feeds`, and the one-off replay of
 *     migration 205 over a database built before part P6 existed.
 *   - It cannot promise that every possible way of getting removal wrong is
 *     caught. It catches the ways that have actually been measured, each of
 *     which was found by somebody attacking the code rather than by reasoning
 *     about it.
 *
 * Exit: 0 when every check passed. 1 otherwise — including when it refuses to
 *       run, when the database cannot be reached, and when the calendar server
 *       does not answer.
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

// 🧯 Anything thrown outside the checks below would otherwise end PHP with
//    exit code 255 and a bare stack trace — a database that cannot be reached,
//    a missing table, or one of the class files failing to load. The header
//    promises 0 or 1, so each becomes a plain FAIL line and exit 1. Registered
//    BEFORE the class files are loaded, because a syntax error in one of them
//    is thrown by the `require` below.
//    What this cannot catch: a syntax error in THIS file, or running out of
//    memory. PHP stops before any handler can run.
set_exception_handler(static function (Throwable $e): void {
    echo 'FAIL — the self-test stopped early: ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

// The classes, loaded one by one rather than through `bootstrap.php`. That is
// deliberate: `bootstrap.php` reads the real `web/_auth_keys/` and would
// connect this test to whatever database the developer's own portal happens to
// be pointed at.
//
// The list is longer than the importer's own `use` lines, because two of these
// are reached indirectly and a missing one is NOT a loud failure — it comes
// back as a refresh that "could not be finished", with every check below
// failing for a reason that has nothing to do with the code. Both were found
// that way while this file was being written:
//   * `Site` — `SafeFetch` asks it for the product name, to put in the header
//     that says who is asking for the calendar.
//   * `ErrorMonitor` — `Logger` offers every error to it. Without the class
//     that attempt fails, is caught, and writes a puzzling line to PHP's log
//     in the middle of the checks. With it, it is the no-op it should be,
//     because no error monitor is configured.
$coreDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR;
foreach ([
    'WindowsTimeZones.php',
    'IcsReader.php',
    'App.php',
    'Settings.php',
    'Site.php',
    'ErrorMonitor.php',
    'Logger.php',
    'SafeFetch.php',
    'EventVisibility.php',
    'FeedResolver.php',
    'FeedImporter.php',
] as $classFile) {
    require_once $coreDir . $classFile;
}

use Portal\Core\EventVisibility;
use Portal\Core\FeedImporter;
use Portal\Core\SafeFetch;

// =============================================================================
// 🧾 The made-up world
// =============================================================================
// Every number is 900000 or more so that nothing here can ever collide with a
// real row, in the unlikely event this is pointed at something it should not
// be. The refusal below is the real protection; this is the belt to its braces.
const SITE_A    = 900001;   // the organisation everything belongs to
const VIEWER_0  = 0;        // nobody — not signed in
const VIEWER_1  = 900011;   // an ordinary member
const VIEWER_6  = 900016;   // an administrator of this organisation
const VIEWER_8  = 900018;   // an administrator of the whole portal

// The made-up organisation's own time zone. Everything imported is stored in
// it, the period the portal keeps is counted in it, and so "today" is read in
// it too (see fi_today()). One name, so those three can never disagree. It
// has to be a zone that changes its clocks, or part G and part J4 would prove
// nothing about the two nights a year that matter.
const SITE_ZONE = 'Europe/London';

// =============================================================================
// 🛑 Refuse anything but a throwaway database and a working test server
// =============================================================================

$writeOnly = in_array('--write-calendars', $argv, true);

$feedDir = (string) getenv('SELFTEST_FEED_DIR');
if ($feedDir === '') {
    echo "REFUSED — set SELFTEST_FEED_DIR to a scratch folder this test may write calendar files into.\n";
    echo "Nothing was checked.\n";
    exit(1);
}
$feedDir = rtrim($feedDir, DIRECTORY_SEPARATOR);

// -----------------------------------------------------------------------------
// 📝 The calendar files, written from here so the recipe can never be lost
// -----------------------------------------------------------------------------
// This used to be a separate scratch script, and one calendar it needed was
// missing from it. Three different people rebuilt that file by hand, and each
// time the most important control in the set could not run at all — it asked
// for a file the server answered 404 for, so the check failed for a reason that
// had nothing to do with the code. Everything is written here now.
/**
 * Wrap event blocks in a calendar file.
 */
function fi_wrap(string $body): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WebMS Intra//P6 self-test//EN\r\n" . $body . "END:VCALENDAR\r\n";
}

/**
 * One event block.
 *
 * @param list<string> $extra Whole extra lines, such as a repeat rule.
 */
function fi_event(string $uid, string $summary, string $start, string $end, array $extra = []): string
{
    $out = "BEGIN:VEVENT\r\nUID:{$uid}\r\nSUMMARY:{$summary}\r\nDTSTART{$start}\r\n";
    if ($end !== '') {
        $out .= "DTEND{$end}\r\n";
    }
    foreach ($extra as $line) {
        $out .= $line . "\r\n";
    }

    return $out . "END:VEVENT\r\n";
}

/** One whole-day event. */
function fi_allDay(string $uid, string $summary, string $day): string
{
    return fi_event($uid, $summary, ';VALUE=DATE:' . $day, '');
}

/** One timed event written as exact moments in UTC. */
function fi_timed(string $uid, string $summary, string $start, string $end, array $extra = []): string
{
    return fi_event($uid, $summary, ':' . $start, ':' . $end, $extra);
}

/**
 * Today, as the portal counts it: midnight at the start of today in the
 * organisation's own zone. THE ONLY PLACE THIS FILE READS THE CALENDAR.
 *
 * `FeedImporter::refresh()` works out the period it keeps from exactly this —
 * `(new DateTimeImmutable('now', $zone))->setTime(0, 0, 0)`, in the
 * organisation's zone — so every date below agrees with the portal about
 * which day it is. Until 24 September 2026 "today" was read in four separate
 * places, three of them in UTC and one in London, and UTC and London give
 * different dates for an hour every summer night.
 *
 * READ ONCE, then remembered. A run takes about a minute and can cross
 * midnight. If "today" were read again part way through, the calendars
 * written at the start and the answers expected at the end would be counted
 * from two different days.
 *
 * What this cannot do: the portal reads its own clock afresh on every
 * refresh, so a run that crosses midnight sees the period move on by a day
 * part way through. That is why check 0 insists on a day to spare at the
 * START of the period — the end only ever moves further away.
 */
function fi_today(): DateTimeImmutable
{
    static $today = null;
    if ($today === null) {
        $today = (new DateTimeImmutable('now', new DateTimeZone(SITE_ZONE)))->setTime(0, 0, 0);
    }

    return $today;
}

/**
 * The period the portal keeps today, asked of the portal itself.
 *
 * `windowFor()` is private, so it is reached the same way the checks below
 * reach `removalEndPoint()`. Asking the portal, rather than working the
 * period out a second time here, means this file cannot hold a slightly
 * different idea of it.
 *
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
 */
function fi_window(): array
{
    return (new ReflectionMethod(FeedImporter::class, 'windowFor'))->invoke(null, fi_today());
}

/**
 * The first moment, from `$from` to `$to`, that the clocks go BACK
 * (`$goingBack` true) or FORWARD (false) in the organisation's zone, or null.
 *
 * Found in PHP's own time-zone data, never written down, so it is the real
 * night in whatever year the test happens to run. Part G uses it for both
 * directions and `fi_nextClocksBack()` (part J) for the clocks going back;
 * this was that function's loop until 24 September 2026, moved here so both
 * parts find a clock change in exactly the same way rather than in two
 * copies that could drift apart.
 *
 * ============================================================================
 * WHAT WAS WRONG IN THIS LOOP UNTIL 24 September 2026, AND WHY IT WAS SERIOUS
 * ============================================================================
 * The comment on it used to claim that `DateTimeZone::getTransitions()`'s
 * first returned entry "describes the span the range STARTS in rather than a
 * change, so its timestamp can be before the range; that one is skipped by
 * the comparison." **That is not what PHP does** — measured directly on PHP
 * 8.5.10: entry 0's timestamp is always set to the START of the requested
 * range, never to anything earlier, so a plain `$span['ts'] >= $from`
 * comparison never skips it.
 *
 * It was skipped in practice only by accident, because `$today` used to
 * always be the real day the test happened to run on, and that is usually
 * inside British Summer Time — so entry 0's `isdst` was `true`, and the OTHER
 * half of the old guard (`$span['isdst'] === false`) rejected it for an
 * unrelated reason. From about ten days before the clocks go back until about
 * ten days before they go forward — measured at 154 days out of the year —
 * `$today` lands in a stretch where the range instead STARTS in standard
 * time, entry 0 carries `isdst === false` too, and the old guard wrongly
 * accepted it as if it were a real transition. The function silently
 * returned `$today + 10 days` — AN ORDINARY DAY WITH NO CLOCK CHANGE IN IT —
 * and because that day still has an offset either side of it (just not a
 * different one), part J4 below went on to build a "the clocks go back"
 * check around a moment that was not one, and every line in it printed PASS
 * — including when run against the exact pre-fix `FeedImporter.php` this
 * whole test exists to catch — for the wrong reason, five months of the
 * year. Full measurement, including the winter run that proves it:
 * `.claude-work/resume/p514-p6--verify-r3.md`, finding 1.
 *
 * THE FIX. Entry 0 is never a transition — it is `getTransitions()`'s own
 * description of the state the zone is ALREADY in at the start of the
 * range — so it is skipped outright, the same way `web/_core/Ical.php`'s
 * `vtimezoneBlock()` already does at its own lines 151–152 (`$prev =
 * $transitions[0];`, then a loop starting at `$i = 1`). A LATER entry only
 * counts as "the clocks going back" when it is ALSO the far side of a
 * transition away from summer time — `isdst` was `true` immediately before
 * it — which closes the other way this could still go wrong: two
 * standard-time entries in a row would otherwise both look like a change.
 * "The clocks going forward" is the mirror image: summer time now, and NOT
 * summer time immediately before.
 *
 * Neither guard is trusted on its own word. Part J0 checks the answers for
 * the clocks going back on fixed days, and parts G and J4 each measure the
 * moment they are handed — the UTC offset a minute either side of it — and
 * refuse loudly if it is not the change they asked for.
 */
function fi_clockChange(DateTimeImmutable $from, DateTimeImmutable $to, bool $goingBack): ?DateTimeImmutable
{
    $utc   = new DateTimeZone('UTC');
    $zone  = new DateTimeZone(SITE_ZONE);
    $spans = $zone->getTransitions($from->getTimestamp(), $to->getTimestamp());
    if ($spans === false) {
        return null;
    }

    // $wasSummerBefore records whether the span BEFORE the one currently
    // being looked at was daylight-saving time. Entry 0 never counts as the
    // change itself (see the doc block above) — it only sets up what
    // "before" means for judging entry 1, which is why the loop below treats
    // index 0 specially instead of testing it the same way as the rest.
    $wasSummerBefore = false;
    foreach ($spans as $i => $span) {
        if ($i === 0) {
            $wasSummerBefore = $span['isdst'] === true;
            continue;
        }
        $isSummer = $span['isdst'] === true;
        $wanted   = ($goingBack === true)
            ? ($isSummer === false && $wasSummerBefore === true)
            : ($isSummer === true && $wasSummerBefore === false);
        if ($wanted === true && $span['ts'] >= $from->getTimestamp() && $span['ts'] <= $to->getTimestamp()) {
            return (new DateTimeImmutable('@' . $span['ts']))->setTimezone($utc);
        }
        $wasSummerBefore = $isSummer;
    }

    return null;
}

/**
 * The dates parts A to I are built on — worked out from today, and nothing else.
 *
 * `day0` stands where 1 October 2026 used to. Every date in A to I is now
 * "day N", N days after it, with EXACTLY the spacing the fixed dates had, so
 * every ordering the checks rely on is unchanged: the "aardvark" events still
 * come before the tied ones, the ten events still run on ten days in a row,
 * the added dates of D4 still start five days before the rest. It sits
 * fourteen days ahead of today. That leaves six weeks of room at the start of
 * the period, and — since the longest thing built on it, D4's 300 added
 * dates, ends on day 299 — about fifty days of room at the end.
 *
 * `back` and `forward` are the two nights part G is built around: the FIRST
 * moment the clocks go back, and the first they go forward, inside the
 * stretch of the period with room for what G builds on each night. The
 * stretch searched is the whole period less exactly that room:
 *   * back — G's weekly service starts the Sunday BEFORE the change and runs
 *     until two Sundays after it, so the night must be at least eight days
 *     after the start of the period (seven for the week before, and the one
 *     check 0 keeps spare for a run that crosses midnight) and at least
 *     fourteen days before its end;
 *   * forward — only that night and its own whole day are built, so two days
 *     of room at each end is plenty.
 * The night may be in the past: the portal keeps thirty days behind today,
 * and whether an event has already happened makes no difference to how it
 * is stored. `back` is also part J4's night on the few weeks a year
 * `fi_nextClocksBack()` has none — see `fi_j4ClocksBack()`.
 *
 * NEITHER CAN COME BACK NULL while the zone keeps changing its clocks. The
 * stretches searched are at least 373 and 391 days long, and one
 * clocks-back night follows the last by at most 371 days (the last Sunday of
 * October falls anywhere from the 25th to the 31st); the same is true of
 * clocks-forward. That is measured, not only reasoned — every day of ten
 * years, in this task's fix report. If the time-zone data ever stops
 * changing the clocks, part G refuses loudly rather than skipping.
 *
 * Remembered after the first call, for the same reason as `fi_today()`.
 *
 * @return array{day0: DateTimeImmutable, back: ?DateTimeImmutable, forward: ?DateTimeImmutable}
 */
function fi_datePlan(): array
{
    static $plan = null;
    if ($plan === null) {
        [$start, $end] = fi_window();
        $plan = [
            'day0'    => fi_today()->modify('+14 days'),
            'back'    => fi_clockChange($start->modify('+8 days'), $end->modify('-14 days'), true),
            'forward' => fi_clockChange($start->modify('+2 days'), $end->modify('-2 days'), false),
        ];
    }

    return $plan;
}

/** Day N of parts A to I's made-up diary: midnight, N days after `day0`, in the organisation's zone. */
function fi_day(int $n): DateTimeImmutable
{
    return fi_datePlan()['day0']->modify('+' . $n . ' days');
}

/** A clock reading on day N, written the way a calendar file with a time zone writes one. */
function fi_local(int $day, string $clock): string
{
    return ';TZID=' . SITE_ZONE . ':' . fi_day($day)->format('Ymd') . 'T' . $clock;
}

/** An exact moment on day N, written in UTC — the "Z" form — the way a calendar file writes one. */
function fi_utc(int $day, string $clock): string
{
    return fi_day($day)->format('Ymd') . 'T' . $clock . 'Z';
}

/**
 * The dates the ONE very long repeating event of part J contributes, and the
 * few moments its checks need.
 *
 * WHY THESE ARE WORKED OUT FROM TODAY RATHER THAN WRITTEN DOWN. The portal
 * only keeps about thirteen months of a calendar — roughly a month behind and
 * a year ahead — and that period moves with the calendar. A fixture with fixed
 * dates in it drifts out of the period, the reader then has far fewer dates
 * than the limit, nothing is cut short, and every check in part J passes for
 * the wrong reason while appearing to run. That happened on the first run of
 * this file and is exactly the shape of silent uselessness these checks exist
 * to prevent, so the dates are built fresh every time.
 *
 * TWO DATES A DAY, not one. A repeat rule cannot reach four hundred dates
 * inside thirteen months at all — a daily rule gives at most about 396 — which
 * is why a real calendar only ever reaches this limit by stating its dates one
 * by one, and why this fixture does the same.
 *
 * @return array{first:DateTimeImmutable, dates:list<DateTimeImmutable>}
 */
function fi_seriesPlan(): array
{
    $utc   = new DateTimeZone('UTC');
    // Today's date as the portal counts it (see fi_today()), read as UTC
    // midnight so the dates below are exactly what they always were.
    $first = (new DateTimeImmutable(fi_today()->format('Y-m-d'), $utc))->modify('+7 days')->setTime(7, 0, 0);
    $dates = [];
    for ($day = 0; $day < 210; $day++) {
        $dates[] = $first->modify('+' . $day . ' days');
        $dates[] = $first->modify('+' . $day . ' days')->setTime(9, 0, 0);
    }

    return ['first' => $first, 'dates' => $dates];
}

/**
 * The next moment the clocks go BACK in London that this test can use, or null.
 *
 * It has to sit inside the period the portal keeps, with room on both sides:
 * at least ten days ahead (so the thirty-odd hours of dates before it are
 * inside the period too) and at least four days before the period ends. There
 * is one such moment a year, so — MEASURED over six years with `$today` made
 * a parameter, not guessed — there is a stretch of roughly two to three weeks
 * every autumn where none fits, and this function correctly returns null
 * then. In that stretch part J4 now uses part G's night instead (see
 * `fi_j4ClocksBack()`); until 24 September 2026 it said SKIPPED, rather than
 * pretending. The same shape is proved without a database, on FIXED dates
 * that cannot drift with the calendar, in `tools/ics-reader-selftest.php`,
 * part I33d.
 *
 * The period actually reaches thirty days BEHIND today, so "ten days ahead"
 * is more room than those dates need, and it is what makes the stretch with
 * no answer exist at all. The range is kept exactly as it is on purpose: part
 * J0 proves this function's answers on precisely this range, stretch
 * included, and four rounds of checking passed J4 on it. J4 simply no longer
 * stops in the stretch — `fi_j4ClocksBack()` falls back to part G's night.
 *
 * `$today` DEFAULTS TO TODAY (`fi_today()`), so every existing caller keeps
 * working unchanged, but a test can also hand in any day it likes — which is
 * exactly what the checks beside `fi_heading('J. ...')` below now do, so this
 * function's behaviour can be proved on both clock-change nights and in the
 * null stretch on any day it is run, not only when the real calendar happens
 * to be in the right season.
 *
 * HOW IT FINDS THE NIGHT is `fi_clockChange()`, which part G shares. The
 * fault the third independent check found — an ordinary day handed back as
 * if it were a clock change, 154 days a year — was in that loop, and the
 * full account of it, and of the fix, now sits beside it there.
 */
function fi_nextClocksBack(?DateTimeImmutable $today = null): ?DateTimeImmutable
{
    $utc   = new DateTimeZone('UTC');
    $today = $today ?? new DateTimeImmutable(fi_today()->format('Y-m-d'), $utc);

    return fi_clockChange($today->modify('+10 days'), $today->modify('+12 months')->modify('-4 days'), true);
}

/**
 * The clocks-back night part J4 is built around — the calendar writer and the
 * check both ask this, so the two can never be built around different nights.
 *
 * `fi_nextClocksBack()` first. On the days it has an answer, J4 is exactly
 * what four rounds of checking passed. On the two to three weeks each autumn
 * it has none (the stretch its own doc block and part J0 describe), part G's
 * night instead: the first clocks-back night inside the period, found by
 * `fi_datePlan()`. In that stretch it is this year's night, somewhere from
 * about ten days ahead to about ten days behind today. Behind is fine: the
 * period keeps thirty days behind today, J4's dates run from about two and a
 * half days before the night to four days after it, and G's night is always
 * at least eight days after the start of the period and fourteen before its
 * end. Check 0 confirms every one of those dates on every run anyway.
 *
 * WHY, added 24 September 2026. J4 used to say SKIPPED in that stretch. It is
 * one of the eight checks that tell the pre-fix importer from the fixed one,
 * so for those weeks every year the test could catch only seven of them —
 * measured on 31 October and 1 November 2026 by round 4's fix. Part G's
 * search, built in that same fix, always has a night that fits.
 *
 * Null only when the time-zone data has no clocks-back night anywhere in the
 * period — the zone has stopped changing its clocks — and part G then
 * refuses loudly for the same reason.
 */
function fi_j4ClocksBack(): ?DateTimeImmutable
{
    return fi_nextClocksBack() ?? fi_datePlan()['back'];
}

/**
 * Write every calendar file, and the little server that hands them out.
 *
 * Every date is worked out from today — see `fi_datePlan()` for parts A to I
 * and G, and `fi_seriesPlan()` / `fi_nextClocksBack()` for part J. Check 0
 * reads them all back out of these files before anything else runs.
 *
 * @return array{files: list<string>, outsideOnPurpose: array<string, array<string, true>>}
 *         The file names written, and — keyed by FILE NAME, then by date
 *         value — the few date values written to sit OUTSIDE the period on
 *         purpose in that one file (check 0 insists they really do). Keyed
 *         by file as well as value so the same old-looking date text turning
 *         up in a DIFFERENT file is judged normally rather than waved
 *         through: a fix-5 change, 24 September 2026, closing a gap the
 *         round-5 check proved end to end (planting one of this file's own
 *         filler values, unmodified, into an unrelated calendar let it past
 *         check 0 because the old map was keyed by value alone).
 */
function fi_writeCalendars(string $dir): array
{
    if (is_dir($dir) === false && mkdir($dir, 0o777, true) === false && is_dir($dir) === false) {
        throw new RuntimeException('could not create ' . $dir);
    }
    $utc   = new DateTimeZone('UTC');
    $files = [];
    $outsideOnPurpose = [];
    $put   = static function (string $name, string $body) use ($dir, &$files): void {
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $body);
        $files[] = $name;
    };

    // Parts A to I: every date is "day N" of a made-up diary that starts two
    // weeks from today (`fi_day()`). The numbers are the old fixed October
    // 2026 dates less one — day 0 stands where 1 October 2026 used to — so
    // the spacing between any two events is exactly what it always was.

    // --- an ordinary calendar: a one-off, a weekly series and a whole day ----
    $put('tzid-london.ics', fi_wrap(
        fi_event('oneoff@p6.test', 'Elders meeting', fi_local(5, '190000'), fi_local(5, '203000'))
        . fi_event('weekly@p6.test', 'Prayer meeting', fi_local(6, '193000'), fi_local(6, '203000'), ['RRULE:FREQ=WEEKLY;COUNT=6'])
        . fi_event('allday@p6.test', 'Harvest day', ';VALUE=DATE:' . fi_day(9)->format('Ymd'), ';VALUE=DATE:' . fi_day(10)->format('Ymd'))
    ));

    // --- ten events, and the same ten with one taken out, and one renamed ----
    $ten = '';
    $nine = '';
    for ($i = 1; $i <= 10; $i++) {
        $block = fi_event("ev{$i}@p6.test", "Event number {$i}", fi_local(4 + $i, '100000'), fi_local(4 + $i, '110000'));
        $ten  .= $block;
        if ($i !== 4) {
            $nine .= $block;
        }
    }
    $put('ten.ics', fi_wrap($ten));
    $put('nine.ics', fi_wrap($nine));
    $put('ten-renamed.ics', str_replace("SUMMARY:Event number 1\r\n", "SUMMARY:Event number one, renamed\r\n", fi_wrap($ten)));
    // Part L (E19): the SAME ten events, but a different file — one extra
    // calendar-level line that changes no event. A different file is read
    // again in full (the "nothing has changed" shortcut compares the whole
    // file), so the answers are worked out again with nothing new to wait for.
    $put('ten-comment.ics', str_replace("PRODID:-//WebMS Intra//P6 self-test//EN\r\n",
        "PRODID:-//WebMS Intra//P6 self-test//EN\r\nX-WR-CALDESC:A comment that changes no event\r\n", fi_wrap($ten)));

    // --- broken files a refresh has to survive ------------------------------
    $put('truncated-no-end.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WebMS Intra//P6 self-test//EN\r\n"
        . fi_event('ev1@p6.test', 'Event number 1', fi_local(5, '100000'), fi_local(5, '110000'))
        . "BEGIN:VEVENT\r\nUID:cut@p6.test\r\nSUMMARY:This event never fini");
    $put('empty.ics', fi_wrap(''));
    $put('not-a-calendar.ics', "<html><body>Sign in to see this calendar.</body></html>\n");

    // --- two identifiers that differ only in capital letters ----------------
    $put('case-uids.ics', fi_wrap(
        fi_event('Case@p6.test', 'Capital C', fi_local(11, '090000'), fi_local(11, '100000'))
        . fi_event('case@p6.test', 'Small c', fi_local(11, '110000'), fi_local(11, '120000'))
    ));

    // --- an event the outside calendar marked private -----------------------
    $put('class-private.ics', fi_wrap(
        fi_event('priv@p6.test', 'Confidential meeting', fi_local(12, '140000'), fi_local(12, '150000'), ['CLASS:PRIVATE'])
        . fi_event('open@p6.test', 'Open meeting', fi_local(12, '160000'), fi_local(12, '170000'))
    ));

    // --- categories ----------------------------------------------------------
    $put('categories.ics', fi_wrap(
        fi_event('cat1@p6.test', 'Street team', fi_local(13, '100000'), fi_local(13, '110000'), ['CATEGORIES:Outreach'])
        . fi_event('cat2@p6.test', 'Games night', fi_local(13, '180000'), fi_local(13, '190000'), ['CATEGORIES:Youth'])
        . fi_event('cat3@p6.test', 'Both words', fi_local(14, '100000'), fi_local(14, '110000'), ['CATEGORIES:Youth,Outreach'])
    ));

    // Two category words MySQL treats as the SAME word under this portal's own
    // collation: 'café' and 'cafe'. One event carrying both used to fail the
    // whole calendar — all ten events — on every single refresh.
    $cafe = '';
    for ($i = 1; $i <= 10; $i++) {
        $hour  = str_pad((string) (8 + $i), 2, '0', STR_PAD_LEFT);
        $next  = str_pad((string) (9 + $i), 2, '0', STR_PAD_LEFT);
        $cafe .= fi_timed('cafe' . $i . '@p6.test', 'Cafe event ' . $i, fi_utc(12, $hour . '0000'), fi_utc(12, $next . '0000'),
            [$i === 4 ? 'CATEGORIES:Café,Cafe' : 'CATEGORIES:Outreach']);
    }
    $put('cafe.ics', fi_wrap($cafe));

    // --- the two nights a year the clocks change -----------------------------
    // The REAL nights, the first of each inside the period, found in PHP's
    // time-zone data by `fi_datePlan()`. Until 24 September 2026 they were
    // written down as 25 October 2026 and 28 March 2027, and the first of
    // those falls out of the period at the end of November 2026.
    //
    // Written as exact UTC moments on purpose. "01:30 on the night the clocks
    // go back" as a LOCAL London reading is ambiguous — that clock reading
    // happens twice — and PHP resolves it to the second one. Writing half an
    // hour BEFORE the change as a UTC moment pins it to the first, which is
    // the case that matters: 01:30 British Summer Time plus 45 real minutes
    // is 01:15 Greenwich Mean Time, so the END reads EARLIER than the START
    // on a clock, and that is correct and must not be "fixed".
    //
    // If either night could not be found — which cannot happen while the
    // zone changes its clocks; see `fi_datePlan()` — the file is not written
    // at all, and part G refuses loudly rather than testing something else.
    // (Named `$nights`, not `$plan`: part J below uses `$plan` for its own dates.)
    $nights = fi_datePlan();
    if ($nights['back'] !== null && $nights['forward'] !== null) {
        $siteZone  = new DateTimeZone(SITE_ZONE);
        $utcText   = static fn (DateTimeImmutable $moment): string => $moment->format('Ymd\THis\Z');
        $backDay   = $nights['back']->setTimezone($siteZone);
        $fwdDay    = $nights['forward']->setTimezone($siteZone);
        // The weekly service starts one week BEFORE the clocks go back, so its
        // four weeks straddle the change: one before it, three after.
        $weekBefore = ';TZID=' . SITE_ZONE . ':' . $backDay->modify('-7 days')->format('Ymd');
        $put('clock-change.ics', fi_wrap(
            fi_timed('backwards-end@p6.test', 'Clocks back night',
                $utcText($nights['back']->modify('-30 minutes')), $utcText($nights['back']->modify('+15 minutes')))
            . fi_timed('forwards-gap@p6.test', 'Clocks forward night',
                $utcText($nights['forward']->modify('-30 minutes')), $utcText($nights['forward']->modify('+15 minutes')))
            . fi_event('back-allday@p6.test', 'Whole day, clocks back',
                ';VALUE=DATE:' . $backDay->format('Ymd'), ';VALUE=DATE:' . $backDay->modify('+1 day')->format('Ymd'))
            . fi_event('fwd-allday@p6.test', 'Whole day, clocks forward',
                ';VALUE=DATE:' . $fwdDay->format('Ymd'), ';VALUE=DATE:' . $fwdDay->modify('+1 day')->format('Ymd'))
            . fi_event('weekly-across@p6.test', 'Sunday service', $weekBefore . 'T110000', $weekBefore . 'T123000',
                ['RRULE:FREQ=WEEKLY;COUNT=4'])
        ));
    }

    // --- a long list of added dates, whole and cut short ---------------------
    // One event carrying a list of extra dates. Read whole, every date comes
    // back. When the list is too long the reader cuts it, loses dates from
    // INSIDE the list — they can sit anywhere in the period — and reports "not
    // all of it, and I cannot tell you how far I got". A refresh that treated
    // that as a complete picture would remove every date that went missing.
    //
    // The "old" dates, and the event's own first date, are FIXED ON PURPOSE,
    // in 2010: they are filler that has to sit BEFORE the period, so that
    // they use up the reader's allowance of listed dates without adding a
    // single event, and a date sixteen years back always will. They are
    // recorded in `$outsideOnPurpose`, and check 0 confirms on every run that
    // they really are outside — if one ever came inside, D4's counts would
    // change for a reason nothing would explain. The "new" dates start on
    // day 0 and are worked out from today like everything else.
    //
    // Keyed by the FILE NAME this closure is about to write, then by value —
    // not by value alone. A fix-5 change, 24 September 2026: the round-5
    // check proved that keying by value alone let one of these very filler
    // values, planted unmodified into a completely unrelated calendar, be
    // waved through by check 0 as if it belonged there too.
    $rdate = static function (string $fileName, int $oldCount, int $newCount) use ($utc, &$outsideOnPurpose): string {
        $longAgo = new DateTimeImmutable('2010-01-01 09:00:00', $utc);
        $newFrom = new DateTimeImmutable(fi_day(0)->format('Y-m-d') . ' 09:00:00', $utc);
        $all     = [];
        for ($i = 0; $i < $oldCount; $i++) {
            $all[] = $longAgo->modify('+' . $i . ' days')->format('Ymd\THis\Z');
            $outsideOnPurpose[$fileName][$all[$i]] = true;
        }
        for ($i = 0; $i < $newCount; $i++) {
            $all[] = $newFrom->modify('+' . $i . ' days')->format('Ymd\THis\Z');
        }
        $outsideOnPurpose[$fileName]['20100101T090000Z'] = true;
        $outsideOnPurpose[$fileName]['20100101T100000Z'] = true;
        $body = "BEGIN:VEVENT\r\nUID:rd@p6.test\r\nSUMMARY:Added dates\r\nDTSTART:20100101T090000Z\r\nDTEND:20100101T100000Z\r\n";
        foreach (array_chunk($all, 50) as $chunk) {
            $body .= 'RDATE:' . implode(',', $chunk) . "\r\n";
        }

        return $body . "END:VEVENT\r\n";
    };
    $put('rdate-full.ics', fi_wrap($rdate('rdate-full.ics', 0, 300)));
    $put('rdate-cut.ics', fi_wrap($rdate('rdate-cut.ics', 3900, 300)));
    // THE CONTROL THE OLD SET COULD NOT RUN. The same first hundred dates, in
    // a calendar short enough to be read right through, so the other two
    // hundred really ARE removed. Without it, "the cut calendar removed
    // nothing" would pass equally well on an importer that never removed
    // anything at all.
    $put('rdate-first100.ics', fi_wrap($rdate('rdate-first100.ics', 0, 100)));

    // --- events sharing one start, which is where the cut lands --------------
    // Every whole-day event on one date has the same start reading (00:00:00),
    // and so do any two events at the same clock time — three services at ten,
    // four all-day term markers. Sorting puts them next to each other, and a
    // limit can land in the middle of them.
    $tieDay = fi_day(9)->format('Ymd');
    $put('tie-3.ics', fi_wrap(
        fi_allDay('tie1@p6.test', 'Tie 1', $tieDay)
        . fi_allDay('tie2@p6.test', 'Tie 2', $tieDay)
        . fi_allDay('tie3@p6.test', 'Tie 3', $tieDay)
    ));
    $put('tie-4.ics', fi_wrap(
        fi_allDay('tie0@p6.test', 'Aardvark day', fi_day(4)->format('Ymd'))
        . fi_allDay('tie1@p6.test', 'Tie 1', $tieDay)
        . fi_allDay('tie2@p6.test', 'Tie 2', $tieDay)
        . fi_allDay('tie3@p6.test', 'Tie 3', $tieDay)
    ));
    $put('tie-timed-3.ics', fi_wrap(
        fi_timed('tt1@p6.test', 'Service 1', fi_utc(10, '100000'), fi_utc(10, '110000'))
        . fi_timed('tt2@p6.test', 'Service 2', fi_utc(10, '100000'), fi_utc(10, '110000'))
        . fi_timed('tt3@p6.test', 'Service 3', fi_utc(10, '100000'), fi_utc(10, '110000'))
    ));
    $put('tie-timed-4.ics', fi_wrap(
        fi_timed('tt0@p6.test', 'Aardvark service', fi_utc(5, '100000'), fi_utc(5, '110000'))
        . fi_timed('tt1@p6.test', 'Service 1', fi_utc(10, '100000'), fi_utc(10, '110000'))
        . fi_timed('tt2@p6.test', 'Service 2', fi_utc(10, '100000'), fi_utc(10, '110000'))
        . fi_timed('tt3@p6.test', 'Service 3', fi_utc(10, '100000'), fi_utc(10, '110000'))
    ));

    // --- six events at six different times, and the same six with one gone ---
    $six  = '';
    $five = '';
    foreach ([['s1', 'Slot 1', '09'], ['s2', 'Slot 2', '10'], ['s3', 'Slot 3', '11'],
        ['s4', 'Slot 4', '12'], ['s5', 'Slot 5', '13'], ['s6', 'Slot 6', '14']] as [$id, $name, $hour]) {
        $block = fi_timed($id . '@p6.test', $name, fi_utc(11, $hour . '0000'), fi_utc(11, $hour . '3000'));
        $six  .= $block;
        if ($id !== 's2') {
            $five .= $block;
        }
    }
    $put('seq-6.ics', fi_wrap($six));
    $put('seq-5.ics', fi_wrap($five));

    // --- ONE repeating event with more dates than a single event may have ----
    // This is the shape a second independent check used to prove that the end
    // point a cut series reported was not true. See `fi_seriesPlan()` for why
    // the dates are worked out from today and why there are two a day.
    $plan  = fi_seriesPlan();
    $build = static function (array $dates, string $override = '', string $extraEvents = ''): string {
        $body = "BEGIN:VEVENT\r\nUID:bigseries@p6.test\r\nSUMMARY:A long list of dates\r\n"
            . 'DTSTART:' . $dates[0]->format('Ymd\THis\Z') . "\r\n"
            . 'DTEND:' . $dates[0]->modify('+1 hour')->format('Ymd\THis\Z') . "\r\n";
        $rest = [];
        foreach (array_slice($dates, 1) as $date) {
            $rest[] = $date->format('Ymd\THis\Z');
        }
        foreach (array_chunk($rest, 50) as $chunk) {
            $body .= 'RDATE:' . implode(',', $chunk) . "\r\n";
        }

        return fi_wrap($body . "END:VEVENT\r\n" . $override . $extraEvents);
    };
    $all420 = $plan['dates'];
    // 390 dates — THE LAST 390, not the first. That is the whole trick, and it
    // is what makes these checks test anything at all. The portal reads this
    // one first and stores every date. The 420-date version then adds THIRTY
    // EARLIER dates, which pushes the last twenty past the limit — so those
    // twenty are dates the portal already holds, and a refresh that trusted a
    // wrong end point marked all twenty as removed while they were still in
    // the calendar. Built from the FIRST 390 instead, nothing would have been
    // stored at those dates and there would have been nothing to delete: the
    // checks would all have passed against the faulty code. (They did, on the
    // first attempt at this file.)
    $last390 = array_slice($all420, 30);
    $put('series-390.ics', $build($last390));
    // The same 420, with the 400th date MOVED two months LATER by a
    // changed-date block. The reader used to report that moved date as
    // "everything before this moment was read", which is after the twenty
    // dates it dropped — so the importer deleted all twenty.
    $movedLateTarget = $all420[419]->modify('+60 days')->setTime(9, 0, 0);
    $movedLate = "BEGIN:VEVENT\r\nUID:bigseries@p6.test\r\n"
        . 'RECURRENCE-ID:' . $all420[399]->format('Ymd\THis\Z') . "\r\n"
        . 'DTSTART:' . $movedLateTarget->format('Ymd\THis\Z') . "\r\n"
        . 'DTEND:' . $movedLateTarget->modify('+1 hour')->format('Ymd\THis\Z') . "\r\n"
        . "SUMMARY:Moved two months later\r\nEND:VEVENT\r\n";
    $put('series-420-moved-late.ics', $build($all420, $movedLate));
    // The FIRST date the limit drops, moved to three days BEFORE the series
    // even begins. It has to be in BOTH versions: the short one stores it at
    // that early moment, and in the long one the loop finds its changed-date
    // block, marks it used, and only THEN notices it is over the limit and
    // stops — so the date is neither kept nor handed back on its own, sits
    // before any honest end point, and was deleted.
    $movedEarlyTarget = $plan['first']->modify('-3 days')->setTime(12, 0, 0);
    $movedEarly = "BEGIN:VEVENT\r\nUID:bigseries@p6.test\r\n"
        . 'RECURRENCE-ID:' . $all420[400]->format('Ymd\THis\Z') . "\r\n"
        . 'DTSTART:' . $movedEarlyTarget->format('Ymd\THis\Z') . "\r\n"
        . 'DTEND:' . $movedEarlyTarget->modify('+1 hour')->format('Ymd\THis\Z') . "\r\n"
        . "SUMMARY:Moved to before the series began\r\nEND:VEVENT\r\n";
    $put('series-390-moved-early.ics', $build($last390, $movedEarly));
    $put('series-420-moved-early.ics', $build($all420, $movedEarly));
    // The same 420 with one date genuinely TAKEN OUT of the file — the control
    // that shows what the safe answer costs on this shape.
    $put('series-420-one-gone.ics', $build(array_values(array_filter(
        $all420,
        static fn (DateTimeImmutable $d): bool => $d != $all420[40]
    ))));
    // Both limits at once: the long series AND ten one-off events placed after
    // it, so the whole-calendar slice cuts LATER than the series did.
    $lateOnes = '';
    for ($i = 1; $i <= 10; $i++) {
        $when      = $all420[419]->modify('+' . (9 + $i) . ' days')->setTime(10, 0, 0);
        $lateOnes .= fi_timed('lateone' . $i . '@p6.test', 'Late one-off ' . $i,
            $when->format('Ymd\THis\Z'), $when->modify('+1 hour')->format('Ymd\THis\Z'));
    }
    $put('series-390-plus-late.ics', $build($last390, '', $lateOnes));
    $put('series-420-moved-late-plus-late.ics', $build($all420, $movedLate, $lateOnes));

    // The clock-change shape of the same fault, with NO changed date anywhere.
    // On the night the clocks go back, 00:30 UTC reads 01:30 on a London clock
    // (still British Summer Time) and 01:15 UTC reads 01:15 (Greenwich Mean
    // Time) — a LATER moment but an EARLIER clock reading. The removal step
    // compares clock readings, because clock readings are what it stores.
    //
    // The short version is read first, right through, so BOTH of those are
    // stored. The long version then adds 299 earlier dates, which pushes the
    // 01:15 one past the limit — and it used to be deleted although it was
    // still in the calendar.
    $clocksBack = fi_j4ClocksBack();
    if ($clocksBack !== null) {
        $tail = [
            $clocksBack->modify('-30 minutes'),   // reads 01:30, British Summer Time
            $clocksBack->modify('+15 minutes'),   // reads 01:15, Greenwich Mean Time
            $clocksBack->modify('+1 day'),
            $clocksBack->modify('+2 days'),
            $clocksBack->modify('+3 days'),
            $clocksBack->modify('+4 days'),
        ];
        // The filler dates are every five minutes, but they all sit at least a
        // WHOLE DAY before the change. That is not decoration: the hour when
        // the clocks go back happens twice, so a five-minute grid running
        // through it would put two dates on the same clock reading — and then
        // "the row reading 01:15 was not removed" could not say which row it
        // meant. Keeping the fillers on the days before leaves exactly two
        // dates in that hour, which is the shape being tested.
        $fillers = [];
        for ($i = 0; $i < 399; $i++) {
            $fillers[] = $clocksBack->modify('-' . ((24 * 60) + 35 + ($i * 5)) . ' minutes');
        }
        $fillers = array_reverse($fillers);   // earliest first
        $dstLong  = array_merge($fillers, $tail);
        $dstShort = array_merge(array_slice($fillers, -100), $tail);
        $put('series-dst.ics', $build($dstLong));
        $put('series-dst-short.ics', $build($dstShort));
    }

    // --- the little server ---------------------------------------------------
    // It hands out one calendar of its own, the one too big to accept. Its date
    // is never read — the portal refuses the file for its size before reading
    // a line of it — but it is worked out from today like every other date,
    // so that check 0 can hold every date this script writes to one rule.
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'server.php', fi_serverSource(fi_utc(5, '100000')));
    $files[] = 'server.php';

    return ['files' => $files, 'outsideOnPurpose' => $outsideOnPurpose];
}

/**
 * The source of the test calendar server, written into the scratch folder.
 *
 * Fix 6 (24 September 2026): this used to take the scratch folder as a
 * parameter and write that literal path into the generated file TWICE — once
 * in this file's own "Start it with" doc comment, once in the `$dir =`
 * assignment below. Check 0 reads server.php as text looking for dates, and
 * this project names its own scratch folders with an eight-digit date in
 * them (e.g. `p514-p6-built-20260924-fixes5`) — exactly the shape the belt in
 * `fi_datesOutsideThePeriod()` looks for. A folder named that way added
 * "date-looking" text nothing had actually judged, the two counts disagreed,
 * and the whole run was refused on correct code, with a message pointing at
 * the wrong cause (found by the round-6 independent check). Now server.php
 * works out its own folder at RUNTIME with `__DIR__` instead, so nothing
 * about the machine or folder it happens to run in is ever written into a
 * file check 0 reads.
 *
 * @param string $bigStart The start written into the over-sized calendar it hands out.
 */
function fi_serverSource(string $bigStart): string
{
    return <<<PHPSRC
<?php
/**
 * The test calendar server for tools/feed-importer-selftest.php.
 *
 * WRITTEN BY THAT SELF-TEST. Do not edit it here; edit `fi_serverSource()`.
 * It is a scratch file in a scratch folder and is overwritten on every run.
 *
 * Start it with SIX WORKERS or several checks will wedge it, from the folder
 * this file was written into:
 *     PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:<port> server.php
 *
 * It hands out the calendar files beside it, plus a few deliberately awkward
 * answers: an error status, a slow answer, a redirect to a private address,
 * and a file bigger than the portal will accept.
 */
declare(strict_types=1);

// Worked out at runtime, from wherever this file actually is — never written
// in by the script that generated it. Before fix 6 this was the literal
// scratch-folder path baked in as a string, and check 0 reads this very file
// looking for dates: a folder name with eight digits in a row (this project's
// own folders are named exactly that way) was miscounted as extra date-like
// text and refused a correct run. __DIR__ carries no such risk because it is
// never written to disk anywhere check 0 looks.
\$dir  = __DIR__;
\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (\$path === '/status/500') {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "server error\\n";
    return true;
}

if (\$path === '/redirect-local') {
    // A redirect to an address on this very machine. The portal must refuse to
    // follow it, and must never ask for it.
    http_response_code(302);
    header('Location: http://127.0.0.1:1/f/ten.ics');
    return true;
}

if (\$path === '/big.ics') {
    // Larger than the portal will accept.
    header('Content-Type: text/calendar');
    echo "BEGIN:VCALENDAR\\r\\nVERSION:2.0\\r\\nPRODID:-//p6//EN\\r\\n";
    \$block = "BEGIN:VEVENT\\r\\nUID:big" . str_repeat('x', 60) . "@p6.test\\r\\nSUMMARY:"
        . str_repeat('y', 200) . "\\r\\nDTSTART:{$bigStart}\\r\\nEND:VEVENT\\r\\n";
    for (\$i = 0; \$i < 20000; \$i++) {
        echo \$block;
    }
    echo "END:VCALENDAR\\r\\n";
    return true;
}

if (preg_match('#^/slow/([a-z0-9._-]+)\$#i', \$path, \$m) === 1) {
    usleep((int) (((float) (\$_GET['s'] ?? 5)) * 1000000));
    \$file = \$dir . '/' . \$m[1];
    if (is_readable(\$file) === false) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: text/calendar');
    readfile(\$file);
    return true;
}

if (preg_match('#^/f/([a-z0-9._-]+)\$#i', \$path, \$m) === 1) {
    \$file = \$dir . '/' . \$m[1];
    if (is_readable(\$file) === false) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "not found\\n";
        return true;
    }
    header('Content-Type: text/calendar; charset=utf-8');
    readfile(\$file);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain');
echo "not found\\n";
return true;
PHPSRC;
}

/**
 * Undo RFC 5545 line folding (§3.1) before check 0 looks at a file's text.
 *
 * A long content line MAY be split by inserting a line break followed by
 * exactly one space or tab, and a reader must join it straight back before
 * reading anything on it, because the fold can land in the middle of a
 * value — including a date. Real Google and Microsoft 365 exports fold their
 * lines; nothing this test writes folds a line today, but check 0 exists to
 * guard what OTHER code writes in FUTURE, which is exactly when a fold would
 * turn up (see the doc comment on `fi_datesOutsideThePeriod()` below).
 *
 * This mirrors `Portal\Core\IcsReader::parse()`'s own unfolding rule on
 * purpose, not a rule invented for this check: `\r\n`, lone `\r` and lone
 * `\n` all count as a line ending, and a continuation line is joined by
 * dropping its line ending AND its one leading space or tab — never by
 * inserting a space of our own. If this check unfolded differently from the
 * portal's real reader, a folded date could read one way here and another
 * way in the portal, and this check would be judging something the portal
 * never actually sees.
 *
 * Missing this let a fold placed just BEFORE a comma in an RDATE list drop
 * the second date silently instead of being read and judged — found by the
 * round-5 independent check, 24 September 2026.
 */
function fi_unfoldIcsText(string $text): string
{
    $text  = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = [];
    foreach (explode("\n", $text) as $rawLine) {
        if ($rawLine !== '' && ($rawLine[0] === ' ' || $rawLine[0] === "\t") && $lines !== []) {
            $lines[count($lines) - 1] .= substr($rawLine, 1);
            continue;
        }
        $lines[] = $rawLine;
    }

    return implode("\n", $lines);
}

/**
 * Every date written into the calendar files, read back OUT OF THE FILES and
 * held against the period the portal keeps. This is check 0.
 *
 * WHY FROM THE FILES, rather than from a list the writer keeps as it goes. A
 * list holds only the dates somebody remembered to put on it, and the fault
 * this exists for was a set of dates nobody was thinking about. Reading the
 * files back means a fixed date added to ANY calendar in future is caught,
 * by name, the first time this runs after it has fallen out of the period —
 * PROVIDED it is written in a shape this reader already understands (see
 * "what this still cannot do", below).
 *
 * What it reads, after first UNFOLDING each file's text exactly as the
 * portal's own reader does (`fi_unfoldIcsText()` above — this matters
 * because a real export folds long lines and a fold can split a date in
 * two): every value of DTSTART, DTEND, RDATE, EXDATE and RECURRENCE-ID in
 * every file this script wrote — the test server's own source too, because
 * it hands out a calendar of its own — plus the LAST date of every repeat
 * rule. It works that out itself for the one shape of rule this file uses
 * (weekly, a fixed number of times) and refuses any other shape rather than
 * guessing, so a new kind of rule has to be taught to it before it is
 * trusted. A value that is a PERIOD (RFC 5545 §3.3.9 — it has a "/" in it,
 * e.g. `RDATE;VALUE=PERIOD:...Z/PT1H`) is refused the same way, rather than
 * read: this function does not understand a period's second half, and
 * guessing at it would be worse than saying so. A file that holds an event
 * but yields no date at all is refused too: that would mean this reader, not
 * the calendar, is broken, and "no dates found" must never read as "no
 * dates outside".
 *
 * THE BELT: after reading a file, it counts every date-looking piece of text
 * in it a SECOND, completely different way — a plain pattern that looks for
 * eight digits (optionally followed by a time and "Z"), ignoring which
 * property it sits under, plus one for every `RRULE:` — and refuses if that
 * count disagrees with how many values were actually read and judged above.
 * The two counts are worked out by different code reading the same
 * (unfolded) text, so a shape the property-reading logic above does not
 * understand yet — one nobody has thought of — shows up as a mismatch and
 * is refused loudly, rather than a date silently going unchecked. A ten-year
 * scan of the committed writer found the two counts agree on every single
 * day (kept as evidence; see the round-5 and fix-5 reports).
 *
 * `$outsideOnPurpose` holds the few values written to sit BEFORE the period
 * (part D4's long-ago filler) — KEYED BY FILE NAME, THEN BY VALUE, not by
 * value alone. A value here excuses only that one value IN THAT ONE FILE.
 * Keying it by value alone used to let a stray old-looking date in ANY OTHER
 * file be waved through too, just because the digits happened to match one
 * of D4's filler dates — found and proven end to end by the round-5 check
 * (planting `DTSTART:20150601T090000Z`, one of D4's own filler values, in a
 * calendar that has nothing to do with D4; it passed check 0 and the run
 * then failed four checks on correct code for a reason check 0 exists to
 * name).
 *
 * THE RULE, and the day to spare: a date may sit anywhere from one day AFTER
 * the start of the period to its very end. The start moves forward at
 * midnight, and a run can cross midnight (see `fi_today()`); the end only
 * moves further away, so it needs no room.
 *
 * What this STILL cannot do, even with the belt above: it does not expand a
 * repeat rule date by date, so it knows nothing of a date the rule would
 * skip; it does not read the rows the checks write straight into the
 * database (part D3's, on purpose at the very end of the period); it treats
 * a whole-day end date as a moment, which is stricter than the portal is,
 * never looser; it does not read either end of a PERIOD value — it only
 * notices one is there and refuses; and it reads `server.php` as PHP SOURCE,
 * not as what the running server actually SENDS, so it cannot see a date
 * that only exists once the server has run — for example one split across
 * an escaped line break inside a PHP string (rather than a real line ending),
 * or one worked out at request time with `gmdate(...)`. Found by the round-6
 * independent check, on purpose left unbuilt: it cannot matter TODAY, because
 * the one calendar the test server hands out of its own is refused for its
 * size before the portal ever reads a line of it. If the server is ever
 * changed to hand out a second calendar of its own, this gap would need
 * closing by having check 0 read what the server actually SENDS over HTTP,
 * not its PHP source.
 *
 * @param list<string>                       $files
 * @param array<string, array<string, true>> $outsideOnPurpose Keyed by file
 *        name, then by the date value written into that file — e.g.
 *        `$outsideOnPurpose['rdate-cut.ics']['20100101T090000Z']`.
 *
 * @return array{checked: int, problems: list<string>}
 */
function fi_datesOutsideThePeriod(
    string $dir,
    array $files,
    array $outsideOnPurpose,
    DateTimeImmutable $start,
    DateTimeImmutable $end
): array {
    $zone      = new DateTimeZone(SITE_ZONE);
    $startText = $start->format('Y-m-d H:i:s');
    $earliest  = $start->modify('+1 day')->format('Y-m-d H:i:s');
    $latest    = $end->format('Y-m-d H:i:s');
    $checked   = 0;
    $problems  = [];

    // One written value, in the zone it was written in, or null when it
    // cannot be read. A value with neither "Z" nor a zone of its own is a
    // "floating" reading, which the portal reads in the organisation's zone.
    $asWritten = static function (string $value, string $params) use ($zone): ?DateTimeImmutable {
        if (preg_match('/^\d{8}$/', $value) === 1) {
            $moment = DateTimeImmutable::createFromFormat('!Ymd', $value, $zone);
        } elseif (preg_match('/^\d{8}T\d{6}Z$/', $value) === 1) {
            $moment = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        } elseif (preg_match('/^\d{8}T\d{6}$/', $value) === 1) {
            // Case-INSENSITIVE ("/i"): RFC 5545 §3.2 says a parameter NAME is
            // not case-sensitive, and the portal's own reader upper-cases
            // every one it reads (`IcsReader.php`, the `strtoupper()` on the
            // parameter name inside its property-line splitter). Before this
            // fix, `DTSTART;tzid=America/New_York:...` fell through to
            // SITE_ZONE here while the portal read it in New York — found by
            // the round-6 independent check, measured at a few hours' drift,
            // which only ever matters within hours of the far end of the
            // kept period. Checked at the same time whether any OTHER
            // parameter check 0 reads by name has the same fault: no —
            // `VALUE=` (e.g. `VALUE=DATE`) is never read by name here. A
            // whole-day value is told apart from a timed one purely by its
            // own digit shape (`^\d{8}$` above vs. `^\d{8}T\d{6}$` here), and
            // a PERIOD is told apart by the "/" in the value itself, so
            // there is nothing for a VALUE= case difference to change.
            $named = (preg_match('/;TZID=([^;:]+)/i', $params, $tz) === 1) ? $tz[1] : SITE_ZONE;
            try {
                $moment = DateTimeImmutable::createFromFormat('Ymd\THis', $value, new DateTimeZone($named));
            } catch (Exception $unknownZone) {
                // A zone name PHP does not know. Reported against the file
                // as a date that could not be read, rather than stopping the
                // whole run with a message that names nothing.
                return null;
            }
        } else {
            return null;
        }

        return ($moment === false) ? null : $moment;
    };
    // `$purposeHere` is THIS FILE's slice of `$outsideOnPurpose` — passed in
    // by the caller below rather than closed over, because it changes for
    // every file (see the file-name-then-value keying explained above).
    $judge = static function (string $where, string $value, ?DateTimeImmutable $moment, array $purposeHere) use (
        $zone, $startText, $earliest, $latest, &$problems
    ): void {
        if ($moment === null) {
            $problems[] = $where . ': "' . $value . '" could not be read as a date';

            return;
        }
        $reads = $moment->setTimezone($zone)->format('Y-m-d H:i:s');
        if (isset($purposeHere[$value]) === true) {
            if ($reads >= $startText) {
                $problems[] = $where . ': ' . $value . ' is meant to sit BEFORE the period, but reads ' . $reads
                    . ', which is inside it';
            }

            return;
        }
        if ($reads < $earliest || $reads > $latest) {
            $problems[] = $where . ': ' . $value . ' reads ' . $reads . ' in ' . SITE_ZONE
                . ', outside ' . $earliest . ' to ' . $latest;
        }
    };

    foreach ($files as $name) {
        // Unfolded FIRST, so every scan below — the property reader, the
        // RRULE-block splitter and the independent belt count — all read
        // exactly the same joined-back-together text the portal itself
        // would read, rather than three different views of a folded one.
        $text        = fi_unfoldIcsText((string) file_get_contents($dir . DIRECTORY_SEPARATOR . $name));
        $inFile      = 0;
        $purposeHere = $outsideOnPurpose[$name] ?? [];

        // Every value of every date property. The value class was widened
        // from `[0-9TZ,]+` to `[0-9A-Z,\/]+` to STOP LOSING a value after a
        // "/" instead of silently truncating there (a PERIOD is refused
        // below, once it has actually been captured, rather than being cut
        // short and never even counted). It still stops at the FIRST
        // character outside that set, which is still a real line ending —
        // and, deliberately unchanged, is still a backslash: the test
        // server's own source embeds an ICS value followed by an ESCAPED
        // "\r\n" (two literal characters, not a real line break) inside a
        // PHP string, and the backslash has to keep stopping the match
        // there or it would run on into unrelated PHP source.
        preg_match_all('/(DTSTART|DTEND|RDATE|EXDATE|RECURRENCE-ID)((?:;[^:;\r\n]+)*):([0-9A-Z,\/]+)/', $text, $found, PREG_SET_ORDER);
        foreach ($found as $match) {
            foreach (explode(',', $match[3]) as $value) {
                $checked++;
                $inFile++;
                if (str_contains($value, '/') === true) {
                    // A PERIOD (RFC 5545 §3.3.9: "start/end" or
                    // "start/duration"). Refused by name rather than read,
                    // because this function does not understand a period's
                    // second half — and, until this fix, the old value
                    // class stopped at the "/" and never even counted what
                    // came after it, which is how a planted 2015 date got
                    // straight past check 0 (round-5 check).
                    $problems[] = $name . ' ' . $match[1] . ': "' . $value . '" is a PERIOD value (it has a "/") '
                        . '— fi_datesOutsideThePeriod() does not read one; teach it this shape before trusting it';
                    continue;
                }
                $judge($name . ' ' . $match[1], $value, $asWritten($value, $match[2]), $purposeHere);
            }
        }

        // The last date of every repeat rule, one event block at a time.
        foreach (array_slice(explode('BEGIN:VEVENT', $text), 1) as $block) {
            if (preg_match('/RRULE:([^\r\n]*)/', $block, $rule) !== 1) {
                continue;
            }
            $checked++;
            $inFile++;
            $first = (preg_match('/DTSTART((?:;[^:;\r\n]+)*):([0-9TZ]+)/', $block, $dt) === 1) ? $asWritten($dt[2], $dt[1]) : null;
            if (preg_match('/^FREQ=WEEKLY;COUNT=([1-9]\d{0,3})$/', $rule[1], $count) !== 1 || $first === null) {
                $problems[] = $name . ': cannot work out the last date of RRULE:' . $rule[1]
                    . ' — teach fi_datesOutsideThePeriod() this shape before trusting it';
                continue;
            }
            // Weeks are counted on the calendar, in the zone the event was
            // written in, so the last date keeps its clock time across a
            // clock change exactly as the portal's own reader does.
            $last = $first->modify('+' . (7 * ((int) $count[1] - 1)) . ' days');
            $judge($name . ' last date of RRULE:' . $rule[1], $last->format('Ymd\THis'), $last, $purposeHere);
        }

        // THE BELT (see the doc comment above): an independent count of
        // every date-looking piece of text in this UNFOLDED file, worked
        // out by a pattern that knows nothing of property names and ignores
        // line breaks, plus one for every `RRULE:`. If it disagrees with
        // how many values were actually read and judged above, some shape
        // this function does not understand yet is sitting in the file —
        // and it refuses rather than silently reading fewer dates than the
        // file actually holds.
        preg_match_all('/(?<![0-9])(\d{8})(T\d{6}Z?)?(?![0-9])/', $text, $tokens);
        $independentCount = count($tokens[0]) + preg_match_all('/RRULE:/', $text);
        if ($independentCount !== $inFile) {
            $problems[] = $name . ': an independent count found ' . $independentCount . ' date-looking piece(s) '
                . 'of text, but only ' . $inFile . ' were read and judged above — teach '
                . 'fi_datesOutsideThePeriod() this file\'s shape before trusting it';
        }

        if ($inFile === 0 && str_contains($text, 'BEGIN:VEVENT') === true) {
            $problems[] = $name . ': holds an event but no date was found in it, so this check is not reading it';
        }
    }

    return ['checked' => $checked, 'problems' => $problems];
}

$written = fi_writeCalendars($feedDir);

if ($writeOnly === true) {
    echo 'Wrote ' . count($written['files']) . ' files to ' . $feedDir . ":\n";
    foreach ($written['files'] as $name) {
        printf("  %-38s %8d bytes\n", $name, (int) filesize($feedDir . DIRECTORY_SEPARATOR . $name));
    }
    echo "\nNow start the test server, with SIX WORKERS (this is not optional — see the\n";
    echo "header of this file; one run wedged for sixty-two minutes without it):\n\n";
    echo '    PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:9055 ' . $feedDir . "/server.php &\n\n";
    echo "Then run this script again with SELFTEST_DB_NAME and SELFTEST_FEED_PORT set.\n";
    exit(0);
}

$feedPort = (int) getenv('SELFTEST_FEED_PORT');
if ($feedPort <= 0 || $feedPort > 65535) {
    echo "REFUSED — set SELFTEST_FEED_PORT to the port the test calendar server is listening on.\n";
    echo "Start one with:  PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:9055 {$feedDir}/server.php &\n";
    echo "Nothing was checked.\n";
    exit(1);
}

$dbName = (string) getenv('SELFTEST_DB_NAME');
if (str_starts_with($dbName, 'selftest_') === false) {
    echo "REFUSED — set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}').\n";
    echo "This test writes rows AND empties tables, so it only ever runs on a throwaway database.\n";
    echo "Nothing was checked.\n";
    exit(1);
}

// 🧪 TEST ONLY, and it lives HERE — in a tool, outside `web/` — for the same
//    reason the calendar reader's own self-test does it this way. The running
//    portal must never set this, and `tools/safefetch-selftest.php` checks that
//    nothing under `web/` does. Without it the made-up name `calendar.test` is
//    looked up for real, is not found, and every address is rightly refused —
//    so not one check below could run.
SafeFetch::$testResolverOverride = ['calendar.test' => ['ips' => ['127.0.0.1'], 'port' => $feedPort]];

// Is the server really there? A refusal now, with the command to start it, is
// far kinder than forty checks failing for a reason that is nothing to do with
// the code.
$probe = @file_get_contents(
    'http://127.0.0.1:' . $feedPort . '/f/ten.ics',
    false,
    stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])
);
if (is_string($probe) === false || str_contains($probe, 'BEGIN:VCALENDAR') === false) {
    echo "REFUSED — no test calendar server answering on 127.0.0.1:{$feedPort}.\n";
    echo "Start one with:  PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:{$feedPort} {$feedDir}/server.php &\n";
    echo "Nothing was checked.\n";
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// `$mysqli` is a global on purpose: it is what `App::db()` falls back to, and
// what the portal's own pages inherit. Nothing here hides that.
$mysqli = new mysqli(
    getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
    getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
    getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
    $dbName,
    getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
);
$mysqli->set_charset('utf8mb4');
$SETTINGS = ['feeds' => ['cron_token' => 'p6-selftest-token']];

// =============================================================================
// 🛠️ Small helpers
// =============================================================================

$GLOBALS['fi_pass'] = 0;
$GLOBALS['fi_fail'] = 0;
$GLOBALS['fi_skip'] = 0;

/** Record one check. */
function fi_ok(string $label, bool $passed, string $detail = ''): void
{
    if ($passed === true) {
        $GLOBALS['fi_pass']++;
        echo 'PASS — ' . $label . "\n";

        return;
    }
    $GLOBALS['fi_fail']++;
    echo 'FAIL — ' . $label . ($detail === '' ? '' : "\n        " . $detail) . "\n";
}

/** Record one check that could not be run. A skipped check is NOT a pass. */
function fi_skipped(string $label, string $why): void
{
    $GLOBALS['fi_skip']++;
    echo 'SKIPPED — ' . $label . "\n        " . $why . "\n";
}

function fi_heading(string $text): void
{
    echo "\n=== " . $text . " ===\n";
}

/**
 * Run one statement with bound values and give back every row.
 *
 * @param  list<mixed> $params
 * @return list<array<string,mixed>>
 */
function fi_q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = ($result === false) ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** The first row, or null. */
function fi_one(string $sql, string $types = '', array $params = []): ?array
{
    $rows = fi_q($sql, $types, $params);

    return $rows === [] ? null : $rows[0];
}

/** Empty everything this test uses, and put the made-up organisation back. */
function fi_reset(): void
{
    global $mysqli;
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'tblExternalFeedRuns', 'tblExternalEventTags', 'tblExternalCategoryMap',
        'tblExternalAudienceMembers', 'tblEventRSVPs', 'tblEvents', 'tblEventSeries',
        'tblExternalFeeds', 'tblEventCategories', 'tblUserSites', 'tblUsers', 'tblSites',
        // #514 part P7's four tables, emptied with the rest so no choice, rule
        // or approval row is left pointing at a calendar that has gone.
        'tblExternalEventApprovals', 'tblExternalRuleConditions', 'tblExternalFeedRules', 'tblExternalEventChoices',
    ] as $table) {
        $mysqli->query('TRUNCATE TABLE ' . $table);
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');

    // Organisation 1 exists only so the portal's own error log — which stamps
    // whichever organisation a request began in — has somewhere to write.
    $mysqli->query('INSERT INTO tblSites (siteID, siteKey, siteName, isActive) VALUES '
        . "(1, 'default', 'Default', 1), (" . SITE_A . ", 'orga', 'Organisation A', 1)");
    $mysqli->query('INSERT INTO tblUsers (userID, emailAddress, fullName, isActive, isAdmin, isRootAdmin) VALUES'
        . ' (' . VIEWER_1 . ", 'v1@p6.test', 'Vee One',   1, 0, 0),"
        . ' (' . VIEWER_6 . ", 'v6@p6.test', 'Vee Six',   1, 0, 0),"
        . ' (' . VIEWER_8 . ", 'v8@p6.test', 'Vee Eight', 1, 0, 1)");
    $mysqli->query('INSERT INTO tblUserSites (userID, siteID, isActive, isSiteAdmin, isSiteRootAdmin) VALUES'
        . ' (' . VIEWER_1 . ', ' . SITE_A . ', 1, 0, 0),'
        . ' (' . VIEWER_6 . ', ' . SITE_A . ', 1, 1, 0)');
    $mysqli->query('INSERT INTO tblEventCategories (categoryID, siteID, categoryName, categorySlug) VALUES'
        . ' (7, ' . SITE_A . ", 'Outreach', 'outreach'), (9, " . SITE_A . ", 'General', 'general')");
    // The organisation's own time zone. Everything imported is stored in it.
    $mysqli->query("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'feeds.maxEventsPerFeed')");
    $mysqli->query('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
        . "VALUES (NULL, 'site.timezone', '" . SITE_ZONE . "', 'UTC', 0)");
}

/** Add a calendar the way the admin page does, and give back its number. */
function fi_addFeed(string $name, string $url, ?int $categoryId = null): int
{
    global $mysqli;
    $stmt = $mysqli->prepare(
        'INSERT INTO tblExternalFeeds (siteID, name, url, fetchEveryMins, createdByID, audienceLevel, categoryID, nextFetchAt) '
        . "VALUES (?, ?, ?, ?, ?, 'public', ?, UTC_TIMESTAMP())"
    );
    $site = SITE_A;
    $mins = 360;
    $by   = VIEWER_6;
    $stmt->bind_param('issiii', $site, $name, $url, $mins, $by, $categoryId);
    $stmt->execute();
    $id = (int) $mysqli->insert_id;
    $stmt->close();

    return $id;
}

/** Make a calendar due again straight away. */
function fi_makeDue(int $feedId): void
{
    global $mysqli;
    $mysqli->query('UPDATE tblExternalFeeds SET nextFetchAt = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, '
        . 'refreshLeaseUntil = NULL WHERE feedID = ' . $feedId);
}

/**
 * Refresh the way the scheduled job would, a second later so the stamps differ.
 *
 * THE WAIT IS LOAD-BEARING, not politeness. `externalLastSeenAt` holds whole
 * seconds, so two refreshes inside one second cannot tell their own stamps
 * apart and nothing is ever removed. (That is the safe direction, and there is
 * a check for it below — but it would make every removal check here pass for
 * the wrong reason.)
 */
function fi_refresh(int $feedId, ?string $newUrl = null, bool $forceReRead = false): array
{
    global $mysqli;
    if ($newUrl !== null) {
        $stmt = $mysqli->prepare('UPDATE tblExternalFeeds SET url = ? WHERE feedID = ?');
        $stmt->bind_param('si', $newUrl, $feedId);
        $stmt->execute();
        $stmt->close();
    }
    if ($forceReRead === true) {
        // Reading the SAME file twice meets the "nothing has changed" shortcut,
        // and then the removal step never runs at all. Clearing the stored
        // fingerprint makes it a real second read.
        $mysqli->query('UPDATE tblExternalFeeds SET lastContentHash = NULL WHERE feedID = ' . $feedId);
    }
    sleep(1);
    fi_makeDue($feedId);

    return FeedImporter::refresh($mysqli, $feedId, 'schedule', null);
}

/** A test calendar's address. */
function fi_url(string $path): string
{
    return 'http://calendar.test' . $path;
}

/** Every live row of one calendar. */
function fi_live(int $feedId): array
{
    return fi_q(
        'SELECT eventID, eventName, eventSlug, startDateTime, endDateTime, timezone, eventTimezone, isAllDay, '
        . 'isPublic, isDeleted, status, importLevel, importDetail, importSource, categoryID, externalPrivate, '
        . 'externalRecurrenceKey, seriesID, LOWER(HEX(externalUidHash)) AS uidHex, externalUid, externalLastSeenAt '
        . 'FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0 ORDER BY startDateTime, eventName',
        'i',
        [$feedId]
    );
}

/** How many rows of one calendar are live. */
function fi_liveCount(int $feedId): int
{
    return (int) fi_one('SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0', 'i', [$feedId])['n'];
}

/** The names of one calendar's live rows. */
function fi_liveNames(int $feedId): array
{
    return array_column(fi_live($feedId), 'eventName');
}

/** The names of one calendar's rows that have been marked as removed. */
function fi_removedNames(int $feedId): array
{
    return array_column(
        fi_q('SELECT eventName FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 1 ORDER BY startDateTime', 'i', [$feedId]),
        'eventName'
    );
}

/** The start times of one calendar's rows that have been marked as removed. */
function fi_removedStarts(int $feedId): array
{
    return array_column(
        fi_q('SELECT startDateTime FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 1 ORDER BY startDateTime', 'i', [$feedId]),
        'startDateTime'
    );
}

/** The most recent history row for one calendar. */
function fi_lastRun(int $feedId): ?array
{
    return fi_one('SELECT * FROM tblExternalFeedRuns WHERE feedID = ? ORDER BY runID DESC LIMIT 1', 'i', [$feedId]);
}

/** Put the per-calendar ceiling to a value; an empty string means "the usual number". */
function fi_setLimit(string $value): void
{
    global $mysqli;
    $mysqli->query("DELETE FROM tblSettings WHERE settingKey = 'feeds.maxEventsPerFeed'");
    if ($value === '') {
        return;
    }
    $stmt = $mysqli->prepare('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
        . "VALUES (NULL, 'feeds.maxEventsPerFeed', ?, '', 0)");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $stmt->close();
}

/** What the calendar grid shows this viewer, by event name. */
function fi_grid(int $viewerId): array
{
    global $mysqli;
    $vis  = EventVisibility::where('e', EventVisibility::MODE_SESSION, $viewerId, fi_today()->format('Y-m-d'));
    $sql  = 'SELECT e.eventName FROM tblEvents e WHERE e.isDeleted = 0 AND e.status <> \'draft\' AND e.siteID = ? '
        . $vis['sql'] . " AND (e.externalFeedID IS NULL OR e.importLevel <> 'hidden') ORDER BY e.startDateTime, e.eventName";
    $stmt = $mysqli->prepare($sql);
    $site = SITE_A;
    $stmt->bind_param('i' . $vis['types'], $site, ...$vis['params']);
    $stmt->execute();
    $names = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'eventName');
    $stmt->close();

    return $names;
}

/**
 * Print the totals and end the run with the exit code the header promises.
 * Used at the very end, and by check 0 when it refuses to go any further.
 */
function fi_finish(): never
{
    echo "\n";
    echo $GLOBALS['fi_pass'] . ' passed, ' . $GLOBALS['fi_fail'] . ' failed, ' . $GLOBALS['fi_skip'] . " skipped.\n";
    echo "A skipped check is NOT a pass: read the reason printed beside it.\n";
    if ($GLOBALS['fi_fail'] > 0) {
        echo 'FAIL — ' . $GLOBALS['fi_fail'] . " check(s) failed.\n";
        exit(1);
    }
    echo "PASS — every check that ran passed.\n";
    exit(0);
}

/**
 * Run the REAL scheduled job in a separate process, the way the router would:
 * with only `$mysqli` and `$SETTINGS` in scope.
 *
 * A SEPARATE PROCESS is necessary, not tidiness. The job answers a bad token
 * with `exit`, and it is allowed to die on a fault — both of which would take
 * this script with them if it were included here.
 */
function fi_runJob(string $token = 'p6-selftest-token'): string
{
    global $feedDir, $feedPort, $dbName;
    $runner = $feedDir . DIRECTORY_SEPARATOR . 'job-runner.php';
    $config = [
        'core'  => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR,
        'job'   => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_apps'
            . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'import-feeds.php',
        'host'  => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        'user'  => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        'pass'  => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        'name'  => $dbName,
        'port'  => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
        'feedPort' => $feedPort,
    ];
    file_put_contents($runner, "<?php\n"
        . "// Written by tools/feed-importer-selftest.php. Scratch only; overwritten on every run.\n"
        . "declare(strict_types=1);\n"
        . '$c = ' . var_export($config, true) . ";\n"
        . "foreach (['WindowsTimeZones.php','IcsReader.php','App.php','Settings.php','Site.php','ErrorMonitor.php',"
        . "'Logger.php','SafeFetch.php','EventVisibility.php','FeedResolver.php','FeedImporter.php'] as \$f)"
        . " { require_once \$c['core'] . \$f; }\n"
        . "\\Portal\\Core\\SafeFetch::\$testResolverOverride = ['calendar.test' => ['ips' => ['127.0.0.1'], 'port' => \$c['feedPort']]];\n"
        . "mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);\n"
        . "\$mysqli = new mysqli(\$c['host'], \$c['user'], \$c['pass'], \$c['name'], \$c['port']);\n"
        . "\$mysqli->set_charset('utf8mb4');\n"
        . "\$SETTINGS = ['feeds' => ['cron_token' => 'p6-selftest-token']];\n"
        . "\$_GET['key'] = (string) (\$argv[1] ?? '');\n"
        . "\$run = static function () use (&\$mysqli, &\$SETTINGS): void { require \$GLOBALS['c']['job']; };\n"
        . "\$GLOBALS['c'] = \$c;\n"
        . "\$run();\n");

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($token) . ' 2>&1');
}


// =============================================================================
// 🛠️ Part L's helpers (#514 part P7)
// =============================================================================

/**
 * "Now" for part L: ONE reading of the DATABASE's clock, remembered. Part L
 * runs on the real clock only (see the header), and every moment it writes
 * is an offset from this — never from PHP's clock and never from
 * `fi_today()`, which counts days, not moments.
 */
function fl_dbNowUtc(): DateTimeImmutable
{
    static $now = null;
    if ($now === null) {
        $now = new DateTimeImmutable((string) fi_one('SELECT UTC_TIMESTAMP() AS t')['t'], new DateTimeZone('UTC'));
    }

    return $now;
}

/**
 * Work out one calendar's answers the way every caller must (plan C10): a
 * transaction, the calendar's lock, the database's clock, commit. Used after
 * an administrator's decision, as part P8's handler will.
 */
function fl_resolve(int $feedId): array
{
    global $mysqli;
    $mysqli->begin_transaction();
    try {
        fi_q('SELECT feedID FROM tblExternalFeeds WHERE feedID = ? FOR UPDATE', 'i', [$feedId]);
        $result = Portal\Core\FeedResolver::resolveFeed($mysqli, $feedId, Portal\Core\FeedResolver::databaseNowUtc($mysqli));
        $mysqli->commit();

        return $result;
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }
}

/** Approve or decline one waiting row the way part P8's handler will. */
function fl_decide(int $approvalId, string $decision): bool
{
    global $mysqli;
    $row = fi_one('SELECT requestHash, contentHash FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [$approvalId]);

    return Portal\Core\FeedResolver::decideApproval($mysqli, $approvalId, SITE_A, $decision, VIEWER_6, 'part L',
        (string) $row['requestHash'], (string) $row['contentHash']);
}

/** Add a rule with one condition to a calendar. */
function fl_rule(int $feedId, string $level, string $field, string $type, string $value): int
{
    fi_q('INSERT INTO tblExternalFeedRules (siteID, feedID, name, isActive, audienceLevel, detailLevel, createdAt) '
        . "VALUES (?, ?, 'Part L rule', 1, ?, 'basic', UTC_TIMESTAMP())", 'iis', [SITE_A, $feedId, $level]);
    $ruleId = (int) fi_one('SELECT MAX(ruleID) AS id FROM tblExternalFeedRules WHERE feedID = ?', 'i', [$feedId])['id'];
    fi_q('INSERT INTO tblExternalRuleConditions (ruleID, isException, matchField, matchType, matchValue) VALUES (?, 0, ?, ?, ?)',
        'isss', [$ruleId, $field, $type, $value]);

    return $ruleId;
}

/** One live event of a calendar, by its name. */
function fl_event(int $feedId, string $name): ?array
{
    return fi_one('SELECT eventID, importLevel, importSource, importRecheckAt, isDeleted FROM tblEvents WHERE externalFeedID = ? AND eventName = ?',
        'is', [$feedId, $name]);
}

/** The approval rows of one event, oldest first. */
function fl_appr(int $eventId): array
{
    return fi_q('SELECT approvalID, status, reason FROM tblExternalEventApprovals WHERE eventID = ? ORDER BY approvalID', 'i', [$eventId]);
}

/**
 * A fingerprint of every column of every row of the six #514 tables that
 * belong to one calendar: its events' `import*` columns, its approval rows,
 * choices, rules, rule conditions and "who may see it" lists.
 */
function fl_fingerprint(int $feedId): string
{
    $parts = [
        fi_q('SELECT eventID, importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, '
            . 'importSource, importSourceID, importRecheckAt, categoryID FROM tblEvents WHERE externalFeedID = ? ORDER BY eventID', 'i', [$feedId]),
        fi_q('SELECT * FROM tblExternalEventApprovals WHERE feedID = ? ORDER BY approvalID', 'i', [$feedId]),
        fi_q('SELECT choiceID, scope, HEX(externalUidHash) AS h, externalRecurrenceKey, audienceLevel, detailLevel, websiteOptIn, apiOptOut, '
            . 'fromDate, toDate, overridesPrivateMark, createdAt, updatedAt FROM tblExternalEventChoices WHERE feedID = ? ORDER BY choiceID', 'i', [$feedId]),
        fi_q('SELECT * FROM tblExternalFeedRules WHERE feedID = ? ORDER BY ruleID', 'i', [$feedId]),
        fi_q('SELECT c.* FROM tblExternalRuleConditions c JOIN tblExternalFeedRules r ON r.ruleID = c.ruleID WHERE r.feedID = ? ORDER BY c.conditionID', 'i', [$feedId]),
        fi_q('SELECT * FROM tblExternalAudienceMembers WHERE feedID = ? ORDER BY audienceMemberID', 'i', [$feedId]),
    ];

    return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
}

// =============================================================================
// The checks
// =============================================================================

echo "#514 part P6 — importer self-test — " . date('c') . "\n";
echo 'PHP ' . PHP_VERSION . ', database ' . ($mysqli->query('SELECT VERSION() AS v')->fetch_assoc()['v'] ?? '?')
    . ', calendars from 127.0.0.1:' . $feedPort . "\n";

$london = new DateTimeZone(SITE_ZONE);
[$windowStart, $windowEnd] = fi_window();
echo 'today, as the portal counts it: ' . fi_today()->format('Y-m-d') . ' in ' . SITE_ZONE . "\n";
echo 'the period the portal keeps: ' . $windowStart->format('Y-m-d H:i:s') . ' to ' . $windowEnd->format('Y-m-d H:i:s') . "\n";
echo 'parts A to I are built on day 0 = ' . fi_day(0)->format('Y-m-d') . ', fourteen days from today' . "\n";

// -----------------------------------------------------------------------------
fi_heading('0. Every date this test writes sits inside the period the portal keeps');
// -----------------------------------------------------------------------------
// Before anything is written to the database. If a calendar holds a date outside
// the period, the checks below would pass or fail for reasons that have
// nothing to do with the code — which is exactly how parts A to I went stale
// before 24 September 2026 (see the header). So this REFUSES: it fails, says
// which file and which date, and runs nothing else.
$datesCheck = fi_datesOutsideThePeriod(
    $feedDir,
    $written['files'],
    $written['outsideOnPurpose'],
    $windowStart,
    $windowEnd
);
$shown = array_slice($datesCheck['problems'], 0, 25);
fi_ok(
    'every date this script wrote into its ' . count($written['files']) . ' files sits inside the period the portal '
    . 'keeps, with a day to spare at its start (' . $datesCheck['checked'] . ' dates read back out of the files)',
    $datesCheck['checked'] > 0 && $datesCheck['problems'] === [],
    implode("\n        ", $shown)
    . (count($datesCheck['problems']) > count($shown) ? "\n        ...and " . (count($datesCheck['problems']) - count($shown)) . ' more' : '')
);
if ($datesCheck['checked'] === 0 || $datesCheck['problems'] !== []) {
    echo "REFUSED — check 0 could not confirm that every date this test writes is inside the period the portal\n";
    echo "keeps (the dates it found outside, or could not read, are listed above), so every check below could pass\n";
    echo "or fail for a reason that has nothing to do with the code. None of them was run.\n";
    echo "Work any date out from today (fi_datePlan(), fi_day()) instead of writing it down.\n";
    fi_finish();
}

try {
    // -------------------------------------------------------------------------
    fi_heading('A. An ordinary import');
    // -------------------------------------------------------------------------
    fi_reset();
    fi_ok(
        'a webcal:// address is stored as https://',
        SafeFetch::normaliseUrl('webcal://calendar.test/f/tzid-london.ics') === 'https://calendar.test/f/tzid-london.ics',
        var_export(SafeFetch::normaliseUrl('webcal://calendar.test/f/tzid-london.ics'), true)
    );

    $feed = fi_addFeed('London calendar', fi_url('/f/tzid-london.ics'));
    $r    = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    fi_ok('the first refresh comes back ok', $r['outcome'] === 'ok', json_encode($r));

    $rows = fi_live($feed);
    fi_ok('eight dates arrived: one one-off, six weekly and one whole day', count($rows) === 8, (string) count($rows));

    $shapes = ['public' => true, 'full' => true, 'calendar' => true, 'zone' => true, 'slug' => true, 'notPublic' => true];
    foreach ($rows as $row) {
        $shapes['public']    = $shapes['public'] && $row['importLevel'] === 'public';
        $shapes['full']      = $shapes['full'] && $row['importDetail'] === 'full';
        $shapes['calendar']  = $shapes['calendar'] && $row['importSource'] === 'calendar';
        $shapes['zone']      = $shapes['zone'] && $row['timezone'] === SITE_ZONE && $row['eventTimezone'] === SITE_ZONE;
        $shapes['slug']      = $shapes['slug'] && str_starts_with((string) $row['eventSlug'], 'imp-');
        $shapes['notPublic'] = $shapes['notPublic'] && (int) $row['isPublic'] === 0;
    }
    fi_ok('every row is marked public-audience, full detail, from a calendar, in the right zone, with an imp- address, '
        . 'and NOT isPublic (which means "open to the whole internet")', $shapes === array_fill_keys(array_keys($shapes), true), json_encode($shapes));
    fi_ok('all eight show on the grid to somebody not signed in', count(fi_grid(VIEWER_0)) === 8, implode(' | ', fi_grid(VIEWER_0)));

    $series = fi_q('SELECT seriesID, seriesName, seriesSlug FROM tblEventSeries WHERE siteID = ?', 'i', [SITE_A]);
    fi_ok('the weekly event got ONE series row, named neutrally rather than after the calendar',
        count($series) === 1 && $series[0]['seriesName'] === 'Repeating imported event'
        && str_starts_with((string) $series[0]['seriesSlug'], 'imp-'), json_encode($series));

    // Its own date is day 9 of the made-up diary — the same day the calendar
    // file was written with (see fi_writeCalendars()), not a date written down.
    $harvestDay = fi_day(9)->format('Y-m-d');
    $allDayRow  = array_values(array_filter($rows, static fn (array $x): bool => $x['eventName'] === 'Harvest day'));
    fi_ok('the whole-day event runs 00:00:00 to 23:59:59 on its own date and is marked as a whole day',
        count($allDayRow) === 1 && $allDayRow[0]['startDateTime'] === $harvestDay . ' 00:00:00'
        && $allDayRow[0]['endDateTime'] === $harvestDay . ' 23:59:59' && (int) $allDayRow[0]['isAllDay'] === 1,
        'expected ' . $harvestDay . '; got ' . json_encode($allDayRow));

    // -------------------------------------------------------------------------
    fi_heading('B. A failure never deletes');
    // -------------------------------------------------------------------------
    foreach (['http://127.0.0.1/x.ics', 'http://printer.local/x', 'http://[::1]/x.ics'] as $bad) {
        $checked = SafeFetch::check($bad);
        fi_ok('refused before anything is fetched, with the one message that gives nothing away: ' . $bad,
            $checked['ok'] === false && $checked['message'] === SafeFetch::REFUSED_MESSAGE, json_encode($checked));
    }

    fi_reset();
    $feed   = fi_addFeed('Ten events', fi_url('/f/ten.ics'));
    $r      = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    $before = array_column(fi_live($feed), 'eventID');
    fi_ok('ten events import to start with', $r['outcome'] === 'ok' && count($before) === 10, json_encode($r));

    $failures = [
        'a redirect to an address on this machine' => '/redirect-local',
        'an error from the other server'           => '/status/500',
        'an address that is not there'             => '/f/missing.ics',
        'a file that is not a calendar at all'     => '/f/not-a-calendar.ics',
        'a file cut off half way through'          => '/f/truncated-no-end.ics',
        'a file bigger than the portal accepts'    => '/big.ics',
    ];
    foreach ($failures as $label => $path) {
        $r     = fi_refresh($feed, fi_url($path), true);
        $after = array_column(fi_live($feed), 'eventID');
        fi_ok('after ' . $label . ': every one of the ten events is untouched',
            $before === $after && count($after) === 10,
            $r['outcome'] . ' / ' . $r['message'] . ' / ' . count($after) . ' live');
    }

    $r     = fi_refresh($feed, fi_url('/f/empty.ics'), true);
    $after = fi_live($feed);
    fi_ok('an EMPTY calendar is a different thing from a failure: it removes nothing but says so plainly',
        count($after) === 10 && str_contains($r['message'], 'earlier events were kept'), $r['outcome'] . ' / ' . $r['message']);

    // -------------------------------------------------------------------------
    fi_heading('C. Removal, and an event that comes back');
    // -------------------------------------------------------------------------
    fi_reset();
    $feed = fi_addFeed('Ten then nine', fi_url('/f/ten.ics'));
    FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    $four = fi_one('SELECT eventID FROM tblEvents WHERE externalFeedID = ? AND eventName = ?', 'is', [$feed, 'Event number 4']);
    $mysqli->query('INSERT INTO tblEventRSVPs (eventID, userID, siteID, response) VALUES ('
        . (int) $four['eventID'] . ', ' . VIEWER_1 . ', ' . SITE_A . ", 'going')");

    $r = fi_refresh($feed, fi_url('/f/nine.ics'));
    fi_ok('an event taken out of the calendar is marked as removed, and only that one',
        fi_removedNames($feed) === ['Event number 4'] && (int) fi_lastRun($feed)['rowsRemoved'] === 1,
        json_encode(fi_removedNames($feed)));
    fi_ok('...it is hidden from the grid', in_array('Event number 4', fi_grid(VIEWER_0), true) === false);
    fi_ok('...but it is NOT really deleted, and the sign-up is still there',
        count(fi_q('SELECT rsvpID FROM tblEventRSVPs WHERE eventID = ?', 'i', [(int) $four['eventID']])) === 1);

    $r = fi_refresh($feed, fi_url('/f/ten.ics'));
    $backAgain = fi_one('SELECT eventID, isDeleted FROM tblEvents WHERE externalFeedID = ? AND eventName = ?', 'is', [$feed, 'Event number 4']);
    fi_ok('an event put BACK into the calendar comes back with the same number, so its sign-ups survive',
        $backAgain !== null && (int) $backAgain['eventID'] === (int) $four['eventID'] && (int) $backAgain['isDeleted'] === 0,
        json_encode($backAgain));

    $r = fi_refresh($feed, fi_url('/f/ten-renamed.ics'));
    fi_ok('a renamed event keeps its identity: it is updated, not removed and added',
        (int) fi_lastRun($feed)['rowsRemoved'] === 0 && (int) fi_lastRun($feed)['rowsAdded'] === 0
        && in_array('Event number one, renamed', fi_liveNames($feed), true) === true,
        json_encode(fi_lastRun($feed)));

    fi_reset();
    $feed = fi_addFeed('Capitals', fi_url('/f/case-uids.ics'));
    FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    fi_ok('two identifiers differing only in capital letters stay two separate events',
        count(fi_live($feed)) === 2 && count(array_unique(array_column(fi_live($feed), 'uidHex'))) === 2,
        json_encode(array_column(fi_live($feed), 'eventName')));

    // -------------------------------------------------------------------------
    fi_heading('D. THE REMOVAL BOUNDARY — what a cut-short download may be trusted for');
    // -------------------------------------------------------------------------
    // This is the rule the whole design rests on, and it is the one this part
    // exists to get right. Read the method directly first, so the two
    // directions cannot be confused by anything else a refresh does.
    // (The moment written below is handed straight to the method, which never
    // reads the clock or the period's start, so it cannot go stale.)
    $endPoint = new ReflectionMethod(FeedImporter::class, 'removalEndPoint');
    $endText  = $windowEnd->format('Y-m-d H:i:s');
    fi_ok('a complete read trusts the end of the period, and trusts it EXACTLY',
        $endPoint->invoke(null, false, null, $windowEnd) === ['point' => $endText, 'exact' => true],
        json_encode($endPoint->invoke(null, false, null, $windowEnd)));
    fi_ok('a cut-short read WITH an end point trusts it, but not exactly — a row sitting on that very moment '
        . 'may have been cut off rather than removed',
        $endPoint->invoke(null, true, '2026-12-01 10:00:00', $windowEnd) === ['point' => '2026-12-01 10:00:00', 'exact' => false],
        json_encode($endPoint->invoke(null, true, '2026-12-01 10:00:00', $windowEnd)));
    fi_ok('a cut-short read with NO end point trusts nothing at all, so nothing may be removed',
        $endPoint->invoke(null, true, null, $windowEnd) === ['point' => null, 'exact' => false],
        json_encode($endPoint->invoke(null, true, null, $windowEnd)));
    fi_ok('an empty end point is treated the same as none',
        $endPoint->invoke(null, true, '', $windowEnd) === ['point' => null, 'exact' => false],
        json_encode($endPoint->invoke(null, true, '', $windowEnd)));

    // D1 — events sharing one start, which is exactly where a cut lands.
    fi_reset();
    fi_setLimit('3');
    $feed = fi_addFeed('Whole-day tie', fi_url('/f/tie-3.ics'));
    $r    = fi_refresh($feed);
    fi_ok('three whole-day events on one date import completely', $r['outcome'] === 'ok' && fi_liveCount($feed) === 3, json_encode($r));
    $r = fi_refresh($feed, fi_url('/f/tie-4.ics'));
    fi_ok('with the ceiling at three and a fourth event added, the read is recorded as cut short',
        $r['outcome'] === 'partial', json_encode($r));
    fi_ok('THE FAULT: an event still in the calendar, sharing its start with the cut-off point, is NOT removed',
        fi_removedNames($feed) === [] && fi_liveCount($feed) === 4 && (int) fi_lastRun($feed)['rowsRemoved'] === 0,
        'removed: ' . json_encode(fi_removedNames($feed)) . ' — each of these is still in the calendar file');

    $feed = fi_addFeed('Three services at ten', fi_url('/f/tie-timed-3.ics'));
    fi_refresh($feed);
    fi_refresh($feed, fi_url('/f/tie-timed-4.ics'));
    fi_ok('the same with three services at the same clock time, not a shared date',
        fi_removedNames($feed) === [] && fi_liveCount($feed) === 4, json_encode(fi_removedNames($feed)));

    // D2 — the other direction: removal must still happen when it should.
    fi_setLimit('');
    $feed = fi_addFeed('Six slots', fi_url('/f/seq-6.ics'));
    $r    = fi_refresh($feed);
    fi_ok('six events at six different times import completely', $r['outcome'] === 'ok' && fi_liveCount($feed) === 6, json_encode($r));
    fi_setLimit('3');
    fi_refresh($feed, fi_url('/f/seq-5.ics'));
    $namesNow = fi_liveNames($feed);
    fi_ok('OTHER DIRECTION: a cut-short read still removes an event that really has gone, when it is BEFORE the cut-off',
        in_array('Slot 2', $namesNow, true) === false && (int) fi_lastRun($feed)['rowsRemoved'] === 1, json_encode($namesNow));
    fi_ok('...and the events AFTER the cut-off are left alone, as they always were',
        in_array('Slot 5', $namesNow, true) === true && in_array('Slot 6', $namesNow, true) === true, json_encode($namesNow));
    fi_setLimit('');

    // D3 — the uncapped path must still use "at or before".
    $feed = fi_addFeed('Edge of the period', fi_url('/f/seq-6.ics'));
    fi_refresh($feed);
    $mysqli->query('INSERT INTO tblEvents (siteID, externalFeedID, externalUidHash, externalRecurrenceKey, eventSlug, '
        . 'eventName, startDateTime, endDateTime, status, isPublic, isDeleted, importLevel, importDetail, externalLastSeenAt) '
        . 'VALUES (' . SITE_A . ', ' . $feed . ", UNHEX('" . str_repeat('ab', 32) . "'), '', 'fi-edge-row', "
        . "'Sitting on the very end', '" . $endText . "', '" . $endText . "', 'published', 0, 0, 'hidden', 'basic', "
        . "'2020-01-01 00:00:00')");
    $r    = fi_refresh($feed, null, true);
    $edge = fi_one("SELECT isDeleted FROM tblEvents WHERE eventSlug = 'fi-edge-row'");
    fi_ok('OTHER DIRECTION, a complete read: a row starting exactly at the end of the period IS still removed',
        $r['outcome'] === 'ok' && $edge !== null && (int) $edge['isDeleted'] === 1, json_encode([$r['outcome'], $edge]));

    // D4 — a cut list of added dates: capped, with no end point at all.
    fi_reset();
    $feed = fi_addFeed('Long list of dates', fi_url('/f/rdate-full.ics'));
    $r    = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    fi_ok('the whole list gives 300 dates and the read is complete', $r['outcome'] === 'ok' && fi_liveCount($feed) === 300,
        fi_liveCount($feed) . ' / ' . json_encode($r));
    $r = fi_refresh($feed, fi_url('/f/rdate-cut.ics'), true);
    fi_ok('the same event with a list too long to read is reported as cut short, and contributed only 100 dates',
        $r['outcome'] === 'partial' && (int) fi_lastRun($feed)['eventsSeen'] === 100, json_encode(fi_lastRun($feed)));
    fi_ok('THE RULE: not one of the 300 stored dates was removed, because the read cannot say how far it got',
        fi_liveCount($feed) === 300 && (int) fi_lastRun($feed)['rowsRemoved'] === 0, fi_liveCount($feed) . ' still live');
    fi_ok('...and the administrator is told exactly that', str_contains($r['message'], 'nothing was removed'), $r['message']);
    // The control the old proof set could not run, because the file it needed
    // was never written down. Without it the check above would pass just as
    // well on an importer that never removed anything.
    $r = fi_refresh($feed, fi_url('/f/rdate-first100.ics'), true);
    fi_ok('CONTROL: the SAME 100 dates, in a calendar that was read right through, DO remove the other 200',
        $r['outcome'] === 'ok' && fi_liveCount($feed) === 100 && (int) fi_lastRun($feed)['rowsRemoved'] === 200,
        $r['outcome'] . ' / ' . fi_liveCount($feed) . ' live / ' . json_encode(fi_lastRun($feed)));

    // D5 — two refreshes inside one second: nothing is removed, which is safe.
    //
    // THIS USED TO RELY ON LUCK, not a pin. The importer reads "now" from the
    // DATABASE (`FeedImporter::databaseNowUtc()`), not from PHP, so two
    // refreshes only land in the same whole second if nothing between them —
    // two SQL round trips and a real HTTP fetch of `nine.ics` — happens to
    // cross a second boundary. Measured (#514 part 6, fix round 4,
    // `.claude-work/resume/p514-p6--fixes4.md`, `notDone`): it tripped at
    // random in 2 of 48 runs once three copies of this whole test shared one
    // machine, which is exactly the load this test now runs under as a
    // pull-request check. A check that fails at random gets ignored — which
    // is worse than not having it.
    //
    // THE FIX: `SET timestamp = <a fixed Unix second>` on THIS connection.
    // Proved against MySQL 8.0.36, not assumed (full transcripts under
    // `.claude-work/resume/p514-p6-built-20260924-fixes4b/`): it pins every
    // "now"-family read on this session — UTC_TIMESTAMP(), NOW(),
    // CURRENT_TIMESTAMP(), CURDATE(), CURTIME() — which reaches all three
    // places `refresh()` reads the clock on this path: the run stamp
    // (`databaseNowUtc()`, :846), the lease (`claim()`, :811-818) and the
    // manual "too soon" rate limit (`refreshedVeryRecently()`, :832-835).
    // Proved with a pin an hour away from real "now": each of those three
    // read back the PINNED value, not the real one — if any of them had
    // silently kept reading the real clock, its answer would have been wrong
    // by close to an hour, not merely late by a few seconds. It FREEZES the
    // clock rather than offsetting it — a real `SLEEP()` between two reads on
    // the same pinned connection changes nothing — so pinning to ONE second
    // makes the two refreshes' run stamps IDENTICAL, not merely likely to
    // match: "same second" becomes certain instead of probable.
    //
    // Wrapped in try/finally so `SET timestamp = DEFAULT` always runs, even
    // if a check between the two refreshes throws, so no LATER check in this
    // file ever inherits a frozen clock. Proved directly (a script cannot
    // make `FeedImporter::refresh()` itself throw on demand, so the identical
    // try/finally shape was proved instead, against a real connection, with a
    // real PHP exception thrown between the pin and the restore — see the
    // evidence folder above). D5c below proves the restore really happened
    // here too, not just in the separate proof.
    fi_reset();
    $feed         = fi_addFeed('Twice in a second', fi_url('/f/ten.ics'));
    $pinnedSecond = time();
    $mysqli->query('SET timestamp = ' . $pinnedSecond);
    try {
        $r1 = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
        fi_makeDue($feed);
        $mysqli->query('UPDATE tblExternalFeeds SET lastContentHash = NULL WHERE feedID = ' . $feed);
        // The SAME rate-limit workaround this check always used, proved
        // unaffected by the pin: `fi_makeDue()` and this UPDATE both read and
        // write the now-pinned clock too (same connection), so "push the
        // first run's stamp back an hour" still defeats
        // `refreshedVeryRecently()` exactly as it did before pinning existed
        // — D5a below asserts both refreshes actually ran, rather than
        // assuming a refusal here would still coincidentally look like "0
        // removed".
        $mysqli->query('UPDATE tblExternalFeedRuns SET startedAt = startedAt - INTERVAL 1 HOUR WHERE feedID = ' . $feed);
        $mysqli->query("UPDATE tblExternalFeeds SET url = '" . fi_url('/f/nine.ics') . "' WHERE feedID = " . $feed);
        $r2 = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    } finally {
        $mysqli->query('SET timestamp = DEFAULT');
    }
    fi_ok('D5a — pinning the clock changed nothing else about the refreshes: neither was refused by the lease '
        . 'or the manual "too soon" rate limit',
        $r1['outcome'] === 'ok' && $r2['outcome'] === 'ok',
        json_encode(['first' => $r1['outcome'], 'second' => $r2['outcome']]));
    fi_ok('D5b — two refreshes pinned to the SAME second cannot tell their own stamps apart, so they remove '
        . 'nothing — which is the safe direction, not a silent failure',
        fi_liveCount($feed) === 10 && (int) fi_lastRun($feed)['rowsRemoved'] === 0, json_encode(fi_lastRun($feed)));

    // D5c — the pin is always put back: proved by showing the clock is
    // genuinely MOVING again, not merely "different from the old pin" (a
    // second pin could satisfy that by accident). A frozen clock could never
    // give two different answers a real second apart; a real one always does.
    $before = fi_one('SELECT UTC_TIMESTAMP() AS t')['t'];
    sleep(1);
    $after = fi_one('SELECT UTC_TIMESTAMP() AS t')['t'];
    fi_ok('D5c — the database clock is unfrozen again afterwards: two reads a real second apart disagree',
        $before !== $after, 'before=' . $before . ' after=' . $after);

    // D5-CONTROL — the same two refreshes, pinned to two DIFFERENT seconds,
    // must still remove the event missing from nine.ics. Without this, D5
    // above would pass just as well on an importer that never removes
    // anything at all — proved, not assumed: a scratch copy of
    // `FeedImporter.php` with removal switched off passes D5 unchanged but
    // FAILS this control (see the evidence folder above for the run).
    fi_reset();
    $feed = fi_addFeed('Twice in different seconds', fi_url('/f/ten.ics'));
    $pin1 = time();
    // A DIFFERENT second, FORWARDS. Moving backwards would not prove removal
    // works: the comment above `removeMissing()` already records that a
    // database clock stepped BACKWARDS has the same "nothing removed" shape
    // as the same-second case, so the control must move the pin forwards to
    // be a genuine test of the opposite case from D5.
    $pin2 = $pin1 + 2;
    $mysqli->query('SET timestamp = ' . $pin1);
    try {
        $r1 = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
        fi_makeDue($feed);
        $mysqli->query('UPDATE tblExternalFeeds SET lastContentHash = NULL WHERE feedID = ' . $feed);
        $mysqli->query('UPDATE tblExternalFeedRuns SET startedAt = startedAt - INTERVAL 1 HOUR WHERE feedID = ' . $feed);
        $mysqli->query("UPDATE tblExternalFeeds SET url = '" . fi_url('/f/nine.ics') . "' WHERE feedID = " . $feed);
        $mysqli->query('SET timestamp = ' . $pin2);
        $r2 = FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    } finally {
        $mysqli->query('SET timestamp = DEFAULT');
    }
    fi_ok('D5-CONTROL a — both refreshes actually ran', $r1['outcome'] === 'ok' && $r2['outcome'] === 'ok',
        json_encode(['first' => $r1['outcome'], 'second' => $r2['outcome']]));
    fi_ok('D5-CONTROL b — the same two refreshes, pinned to two DIFFERENT seconds, DO remove the event missing '
        . 'from nine.ics: D5 above is a real, narrow limitation, not an importer that never removes anything',
        fi_liveCount($feed) === 9 && (int) fi_lastRun($feed)['rowsRemoved'] === 1
        && in_array('Event number 4', fi_removedNames($feed), true),
        json_encode(fi_lastRun($feed)) . ' removed: ' . json_encode(fi_removedNames($feed)));

    // -------------------------------------------------------------------------
    fi_heading('E. Pausing, resuming and deleting a calendar');
    // -------------------------------------------------------------------------
    fi_reset();
    $feed = fi_addFeed('Pause me', fi_url('/f/ten.ics'));
    FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    fi_ok('ten events on the grid before pausing', count(fi_grid(VIEWER_0)) === 10, (string) count(fi_grid(VIEWER_0)));

    FeedImporter::setActive($mysqli, $feed, SITE_A, false);
    $seen = [count(fi_grid(VIEWER_0)), count(fi_grid(VIEWER_1)), count(fi_grid(VIEWER_6)), count(fi_grid(VIEWER_8))];
    fi_ok('paused: the grid is empty for everybody, including both kinds of administrator',
        $seen === [0, 0, 0, 0], json_encode($seen));
    fi_ok('paused: the rows are still there — nothing was rewritten, so nothing has to be fetched again',
        fi_liveCount($feed) === 10, (string) fi_liveCount($feed));
    FeedImporter::setActive($mysqli, $feed, SITE_A, true);
    fi_ok('resumed: all ten are back WITHOUT a refresh', count(fi_grid(VIEWER_0)) === 10, (string) count(fi_grid(VIEWER_0)));

    $anEvent = (int) fi_live($feed)[0]['eventID'];
    $mysqli->query('INSERT INTO tblEventRSVPs (eventID, userID, siteID, response) VALUES ('
        . $anEvent . ', ' . VIEWER_1 . ', ' . SITE_A . ", 'going')");
    $mysqli->query('INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES ('
        . SITE_A . ', ' . $feed . ", 'outreach', 7)");
    $otherFeed = fi_addFeed('Another calendar', fi_url('/f/tzid-london.ics'));
    FeedImporter::refresh($mysqli, $otherFeed, 'manual', VIEWER_6);

    fi_ok('deleteFeed() says it deleted it', FeedImporter::deleteFeed($mysqli, $feed, SITE_A) === true);
    $left = [
        'events' => count(fi_q('SELECT eventID FROM tblEvents WHERE externalFeedID = ?', 'i', [$feed])),
        'rsvps'  => count(fi_q('SELECT rsvpID FROM tblEventRSVPs WHERE eventID = ?', 'i', [$anEvent])),
        'runs'   => count(fi_q('SELECT runID FROM tblExternalFeedRuns WHERE feedID = ?', 'i', [$feed])),
        'map'    => count(fi_q('SELECT mapID FROM tblExternalCategoryMap WHERE feedID = ?', 'i', [$feed])),
        'feed'   => count(fi_q('SELECT feedID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feed])),
    ];
    fi_ok('nothing of that calendar is left behind', array_sum($left) === 0, json_encode($left));
    fi_ok('CONTROL: the OTHER calendar is untouched', fi_liveCount($otherFeed) === 8, (string) fi_liveCount($otherFeed));
    $stranger = fi_addFeed('Not yours', fi_url('/f/ten.ics'));
    fi_ok('deleteFeed() refuses a calendar belonging to a different organisation',
        FeedImporter::deleteFeed($mysqli, $stranger, SITE_A + 1) === false
        && count(fi_q('SELECT feedID FROM tblExternalFeeds WHERE feedID = ?', 'i', [$stranger])) === 1);
    fi_ok('setActive() refuses one too',
        FeedImporter::setActive($mysqli, $stranger, SITE_A + 1, false) === false
        && (int) fi_one('SELECT isActive FROM tblExternalFeeds WHERE feedID = ?', 'i', [$stranger])['isActive'] === 1);

    // -------------------------------------------------------------------------
    fi_heading('F. Categories, tags, and an event marked private');
    // -------------------------------------------------------------------------
    fi_reset();
    $feed = fi_addFeed('Categories', fi_url('/f/categories.ics'), 9);
    $mysqli->query('INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES ('
        . SITE_A . ', ' . $feed . ", 'outreach', 7)");
    FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    $byName = [];
    foreach (fi_live($feed) as $row) {
        $byName[$row['eventName']] = $row['categoryID'] === null ? null : (int) $row['categoryID'];
    }
    fi_ok('an event tagged Outreach takes the category that word is mapped to', ($byName['Street team'] ?? 'missing') === 7, json_encode($byName));
    fi_ok('an event with no mapped word falls back to the calendar\'s own category', ($byName['Games night'] ?? 'missing') === 9, json_encode($byName));
    fi_ok('an event with two words takes the first MAPPED one in alphabetical order', ($byName['Both words'] ?? 'missing') === 7, json_encode($byName));
    $tagList = array_column(fi_q('SELECT t.tag FROM tblExternalEventTags t JOIN tblEvents e ON e.eventID = t.eventID '
        . 'WHERE e.externalFeedID = ? ORDER BY t.tag', 'i', [$feed]), 'tag');
    fi_ok('the words are stored in lower case', $tagList === ['outreach', 'outreach', 'youth', 'youth'], json_encode($tagList));

    fi_reset();
    $feed = fi_addFeed('Private events', fi_url('/f/class-private.ics'));
    FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
    $priv = fi_one('SELECT externalPrivate, importLevel, importDetail FROM tblEvents WHERE externalFeedID = ? AND eventName = ?',
        'is', [$feed, 'Confidential meeting']);
    $open = fi_one('SELECT externalPrivate FROM tblEvents WHERE externalFeedID = ? AND eventName = ?',
        'is', [$feed, 'Open meeting']);
    fi_ok('an event the outside calendar marked private is stored as private and kept off the public grid',
        $priv !== null && (int) $priv['externalPrivate'] === 1
        && in_array('Confidential meeting', fi_grid(VIEWER_0), true) === false, json_encode($priv));
    fi_ok('CONTROL: the ordinary event beside it is NOT marked private, and IS on the grid',
        $open !== null && (int) $open['externalPrivate'] === 0
        && in_array('Open meeting', fi_grid(VIEWER_0), true) === true, json_encode($open));

    // -------------------------------------------------------------------------
    fi_heading('G. The two nights a year the clocks change');
    // -------------------------------------------------------------------------
    // The REAL nights — the first moment inside the period the clocks go back,
    // and the first they go forward — found in PHP's time-zone data by
    // fi_datePlan(). Until 24 September 2026 they were written down as
    // 25 October 2026 and 28 March 2027, and this whole part stopped working
    // once the first of those fell out of the period.
    //
    // Every expected clock reading below is worked out by plain arithmetic —
    // the exact moment, plus the UTC offset measured on that side of the
    // change — rather than by asking PHP to convert the moment the way the
    // importer does, so the answer being checked and the answer expected do
    // not come from the same calculation.
    fi_reset();
    $plan = fi_datePlan();
    $back = $plan['back'];
    $fwd  = $plan['forward'];
    // The UTC offset in force a given number of seconds away from a moment.
    $offsetAt = static function (DateTimeImmutable $moment, int $seconds) use ($london): int {
        return (new DateTimeImmutable('@' . ($moment->getTimestamp() + $seconds)))->setTimezone($london)->getOffset();
    };
    if ($back === null || $fwd === null
        || $offsetAt($back, -60) <= $offsetAt($back, 60)
        || $offsetAt($fwd, -60) >= $offsetAt($fwd, 60)) {
        // THE BELT, the same kind J4 has, and deliberately written again here
        // rather than shared, so that it does not trust fi_clockChange() to be
        // right: it MEASURES each moment it was handed. The clocks going back
        // means a GREATER offset a minute before than a minute after — the day
        // gains an hour — and the clocks going forward means the opposite.
        // Anything else refuses loudly instead of quietly testing an ordinary
        // night. Part G must test BOTH nights, so it never skips.
        fi_ok(
            'G REFUSED: the clock-change nights to test were not both found as real changes in ' . SITE_ZONE
            . ' — nothing else in part G was run, rather than testing an ordinary night instead',
            false,
            'clocks back: ' . ($back === null ? 'none found' : $back->format('Y-m-d H:i:s') . ' UTC')
            . '; clocks forward: ' . ($fwd === null ? 'none found' : $fwd->format('Y-m-d H:i:s') . ' UTC')
            . '. See fi_datePlan() and fi_clockChange().'
        );
    } else {
        // A clock reading worked out by plain arithmetic: the moment, moved by
        // the offset that was in force on that side of the change.
        $readingAt = static function (DateTimeImmutable $moment, int $offset): string {
            return (new DateTimeImmutable('@' . ($moment->getTimestamp() + $offset)))->format('Y-m-d H:i:s');
        };
        $backStart = $readingAt($back->modify('-30 minutes'), $offsetAt($back, -60));
        $backEnd   = $readingAt($back->modify('+15 minutes'), $offsetAt($back, 60));
        $fwdStart  = $readingAt($fwd->modify('-30 minutes'), $offsetAt($fwd, -60));
        $fwdEnd    = $readingAt($fwd->modify('+15 minutes'), $offsetAt($fwd, 60));
        $backDay   = $back->setTimezone($london)->format('Y-m-d');
        $fwdDay    = $fwd->setTimezone($london)->format('Y-m-d');
        echo 'the clocks go back at ' . $back->setTimezone($london)->format('Y-m-d H:i:s') . ' and forward at '
            . $fwd->setTimezone($london)->format('Y-m-d H:i:s') . ' local — the first of each inside the period' . "\n";

        $feed = fi_addFeed('Clock changes', fi_url('/f/clock-change.ics'));
        FeedImporter::refresh($mysqli, $feed, 'manual', VIEWER_6);
        $byName = [];
        foreach (fi_live($feed) as $row) {
            $byName[$row['eventName']] = $row['startDateTime'] . ' → ' . $row['endDateTime'];
        }
        // The labels print only the clock times, never the date, so the same
        // check reads the same on every day this file is run.
        fi_ok('the night the clocks go BACK: ' . substr($backStart, 11, 5) . ' plus 45 real minutes reads '
            . substr($backEnd, 11, 5) . ', so the end reads EARLIER than the start and that is correct',
            $backEnd < $backStart && ($byName['Clocks back night'] ?? '') === $backStart . ' → ' . $backEnd,
            'expected ' . $backStart . ' → ' . $backEnd . '; got ' . json_encode($byName['Clocks back night'] ?? null));
        fi_ok('the night the clocks go FORWARD: ' . substr($fwdStart, 11, 5) . ' plus 45 real minutes reads '
            . substr($fwdEnd, 11, 5),
            ($byName['Clocks forward night'] ?? '') === $fwdStart . ' → ' . $fwdEnd,
            'expected ' . $fwdStart . ' → ' . $fwdEnd . '; got ' . json_encode($byName['Clocks forward night'] ?? null));
        fi_ok('a whole day on each of those dates is still a whole day, 00:00:00 to 23:59:59',
            ($byName['Whole day, clocks back'] ?? '') === $backDay . ' 00:00:00 → ' . $backDay . ' 23:59:59'
            && ($byName['Whole day, clocks forward'] ?? '') === $fwdDay . ' 00:00:00 → ' . $fwdDay . ' 23:59:59',
            'expected ' . $backDay . ' and ' . $fwdDay . '; got ' . json_encode($byName));
        // The service starts one week BEFORE the clocks go back, so its first
        // week is on one side of the change and the other three on the other.
        // Weeks are counted on the calendar (nominal days), never in seconds.
        $expectedSundays = [];
        for ($week = -1; $week <= 2; $week++) {
            $expectedSundays[] = (new DateTimeImmutable($backDay, $london))->modify(($week * 7) . ' days')->format('Y-m-d')
                . ' 11:00:00';
        }
        $sundays = array_values(array_filter(fi_live($feed), static fn (array $x): bool => $x['eventName'] === 'Sunday service'));
        $sundayTimes = array_column($sundays, 'startDateTime');
        fi_ok('a weekly service across the clock change stays at the same clock time every week',
            $sundayTimes === $expectedSundays,
            'expected ' . json_encode($expectedSundays) . '; got ' . json_encode($sundayTimes));
    }

    // -------------------------------------------------------------------------
    fi_heading('H. The ceiling an administrator may set');
    // -------------------------------------------------------------------------
    fi_reset();
    $limitFor = new ReflectionMethod(FeedImporter::class, 'perFeedLimit');
    fi_setLimit('');
    fi_ok('no setting means "the reader\'s own number", passed through as nothing at all',
        $limitFor->invoke(null, SITE_A) === null, var_export($limitFor->invoke(null, SITE_A), true));
    fi_setLimit('5');
    fi_ok('a number typed in is passed through as that number', $limitFor->invoke(null, SITE_A) === 5);
    $feed = fi_addFeed('Ceiling', fi_url('/f/tzid-london.ics'));
    $r    = fi_refresh($feed);
    fi_ok('with the ceiling at five, five dates arrive and the run is recorded as cut short',
        fi_liveCount($feed) === 5 && $r['outcome'] === 'partial', fi_liveCount($feed) . ' / ' . json_encode($r));
    fi_ok('...and lastRunCapped is recorded against the calendar',
        (int) fi_one('SELECT lastRunCapped FROM tblExternalFeeds WHERE feedID = ?', 'i', [$feed])['lastRunCapped'] === 1);
    fi_setLimit('99999');
    $r = fi_refresh($feed, null, true);
    fi_ok('a ceiling outside what the portal can do is reported to the administrator, not half-obeyed',
        str_contains($r['message'], '99999') && str_contains($r['message'], 'outside what it can do'), $r['message']);
    fi_ok('...and all eight dates arrive, because the reader used its usual number',
        fi_liveCount($feed) === 8, (string) fi_liveCount($feed));
    fi_setLimit('');

    // -------------------------------------------------------------------------
    fi_heading('I. The four faults a FIRST independent check found (23 September 2026)');
    // -------------------------------------------------------------------------
    // I1 — the error log the code promises but never wrote. Passing null where
    // a string was required raised a TypeError, which the catch swallowed, so
    // tblErrors stayed empty although the message told the administrator to
    // look there.
    fi_reset();
    $mysqli->query('DELETE FROM tblErrors');
    $feed = fi_addFeed('Log me', fi_url('/f/ten.ics'));
    $mysqli->query('RENAME TABLE tblExternalEventTags TO tblExternalEventTagsHidden');
    $r = fi_refresh($feed);
    $mysqli->query('RENAME TABLE tblExternalEventTagsHidden TO tblExternalEventTags');
    $logged = fi_q("SELECT errorCode, errorDetail FROM tblErrors WHERE errorCode = 'FeedRefreshFailed'");
    fi_ok('a refresh that ended in a fault really is written to the error log',
        $r['outcome'] === 'failed' && count($logged) === 1, json_encode([$r, $logged]));
    fi_ok('...and the row carries the file and line, which is what makes it findable',
        count($logged) === 1 && str_contains((string) $logged[0]['errorDetail'], 'FeedImporter.php:'), json_encode($logged));

    // I2 — THE CONTROL THAT KEEPS I1 HONEST, and it matters more since the fix.
    // The job's own logging call is now wrapped in a `try` (rightly — the catch
    // around it exists for the database giving way, and the logger writes to
    // that same database). The cost is that a wrong argument to the logger is
    // now silent at RUNTIME, which is the very shape that hid the original
    // fault. This check is the only thing standing in for the noise that was
    // lost: it proves the old argument still raises the error it always raised.
    // If it ever stops throwing, the two checks above stop proving anything.
    $stillThrows = false;
    try {
        /** @psalm-suppress InvalidArgument — deliberately wrong, to show the fault was real. */
        Portal\Core\Logger::errorPlatformForSite(SITE_A, 'FeedImport', 'Error', 'Probe', 'probe', null);
    } catch (TypeError $expected) {
        $stillThrows = true;
    }
    fi_ok('CONTROL: passing nothing where the error log requires a piece of text still raises a TypeError, '
        . 'so the two checks above are not passing for free', $stillThrows === true);
    $mysqli->query('DELETE FROM tblErrors');

    // I3 — two category words the database treats as the same word. MySQL's
    // utf8mb4_general_ci says 'café' and 'cafe' are equal, so one event
    // carrying both broke a unique key and lost ALL TEN of the calendar's
    // events — every refresh, for ever, with nothing logged to explain it.
    fi_reset();
    $sameWord = (int) fi_one("SELECT ('café' = 'cafe' COLLATE utf8mb4_general_ci) AS same")['same'];
    fi_ok('this database really does treat \'café\' and \'cafe\' as the same word, so the check below means something',
        $sameWord === 1, (string) $sameWord);
    $feed = fi_addFeed('Cafe calendar', fi_url('/f/cafe.ics'));
    $r    = fi_refresh($feed);
    fi_ok('all ten events import: one awkward pair of words no longer loses the whole calendar',
        $r['outcome'] === 'ok' && fi_liveCount($feed) === 10, json_encode($r));
    $awkward = fi_one('SELECT COUNT(*) AS n FROM tblExternalEventTags t JOIN tblEvents e ON e.eventID = t.eventID '
        . 'WHERE e.externalFeedID = ? AND e.eventName = ?', 'is', [$feed, 'Cafe event 4']);
    fi_ok('...the awkward event keeps the one word the database can hold', (int) $awkward['n'] === 1, json_encode($awkward));
    $r2 = fi_refresh($feed, null, true);
    fi_ok('...and it is still right on the next refresh (the old fault came back every single time)',
        in_array($r2['outcome'], ['ok', 'unchanged'], true) === true && fi_liveCount($feed) === 10, json_encode($r2));

    // I3 control — only a DUPLICATE is forgiven. Any other fault on that same
    // statement must still stop the refresh, or the catch would be hiding real
    // problems. The word column is shrunk so an ordinary word will not fit.
    fi_reset();
    $feed = fi_addFeed('Tag fault', fi_url('/f/cafe.ics'));
    $mysqli->query('ALTER TABLE tblExternalEventTags MODIFY tag VARCHAR(2) NOT NULL');
    $r3 = fi_refresh($feed);
    $mysqli->query('ALTER TABLE tblExternalEventTags MODIFY tag VARCHAR(100) NOT NULL');
    fi_ok('CONTROL: a fault on the same statement that is NOT a duplicate still stops the refresh loudly',
        $r3['outcome'] === 'failed' && fi_liveCount($feed) === 0, json_encode($r3));

    // I4 — the message must match what the run really did.
    fi_reset();
    fi_setLimit('3');
    $feed = fi_addFeed('Message, cut with a point', fi_url('/f/tie-3.ics'));
    fi_refresh($feed);
    $rd1 = fi_refresh($feed, fi_url('/f/tie-4.ics'));
    fi_ok('a cut-short run with a point says how far removal was checked',
        str_contains($rd1['message'], 'only events before ') === true
        && str_contains($rd1['message'], 'were checked against it') === true, $rd1['message']);
    fi_ok('...and no longer claims "nothing was removed" while its own numbers may say otherwise',
        str_contains($rd1['message'], 'nothing was removed') === false, $rd1['message']);
    // "BEFORE", not "up to". On a cut-short read the comparison really is
    // strictly before, so an event starting AT the moment named was not
    // checked — and most people read "up to five o'clock" as including it.
    fi_ok('...and it says "before", which is exactly true, rather than "up to", which is not',
        str_contains($rd1['message'], 'only events up to ') === false, $rd1['message']);
    fi_setLimit('');

    $feed = fi_addFeed('Message, cut with no point', fi_url('/f/rdate-cut.ics'));
    $rd2  = fi_refresh($feed);
    fi_ok('a cut-short run with no point still says plainly that nothing was removed',
        $rd2['outcome'] === 'partial' && str_contains($rd2['message'], 'nothing was removed') === true, $rd2['message']);
    fi_ok('...and does NOT invent a moment it never checked up to',
        str_contains($rd2['message'], 'were checked against it') === false, $rd2['message']);

    $feed = fi_addFeed('Message, complete', fi_url('/f/ten.ics'));
    $rd3  = fi_refresh($feed);
    fi_ok('a complete read says neither sentence, because neither applies',
        $rd3['outcome'] === 'ok' && str_contains($rd3['message'], 'nothing was removed') === false
        && str_contains($rd3['message'], 'were checked against it') === false, $rd3['message']);

    // A fixed moment on purpose: this only turns a stored moment into words,
    // and never compares it with today, so it cannot go stale.
    $plainMoment = new ReflectionMethod(FeedImporter::class, 'plainMoment');
    fi_ok('a stored moment is written out the way a person reads it',
        $plainMoment->invoke(null, '2026-10-10 00:00:00') === '10 October 2026 at 00:00',
        (string) $plainMoment->invoke(null, '2026-10-10 00:00:00'));
    fi_ok('...and anything that is not a stored moment is handed back untouched, never guessed at',
        $plainMoment->invoke(null, 'not a moment') === 'not a moment');

    // -------------------------------------------------------------------------
    fi_heading('J. The fault a SECOND independent check found: a cut series reported an end point that was not true');
    // -------------------------------------------------------------------------
    // A repeating event with more dates in the period than one event may
    // contribute used to report the last date it KEPT as "everything before
    // this moment was read". That is not true, for two separate reasons, and
    // the importer deleted real events on the strength of it.
    //
    // The fix is in the reader (`IcsReader::expand()`): when any series was cut
    // short, the WHOLE read reports no end point, which the importer already
    // treats as "remove nothing". The cost is stated in J5 below.
    //
    // -------------------------------------------------------------------------
    // J0 — fi_nextClocksBack() ITSELF, proved directly on fixed dates rather
    // than trusted. This is what round 3 found broken: for 154 days of the
    // year the old version handed J4 below a moment that read as a clock
    // change but was not one, and J4 then printed PASS against faulty code
    // for an unrelated reason. `$today` is now a parameter for exactly this
    // — these checks do not need to wait for the season to come round, and
    // they run in every season this file is run in, not just some of them.
    //
    // "A real clocks-back moment" is defined the same way twice in this
    // file, once here and once inside J4 below, DELIBERATELY NOT SHARED: the
    // whole point is that J4's own copy is a second, independent belt in
    // case fi_nextClocksBack() breaks again in a way this file's own helper
    // would not catch.
    //
    // The dates below are not arbitrary. They were found by asking the FIXED
    // (corrected) function, day by day, exactly where its answer changes
    // around the 2026 clock change — see this task's fix report for the
    // scan. Using real dates from real PHP tzdata, rather than reasoning
    // about the calendar by hand, is what "prove it" means in the brief this
    // fix was written against.
    $reallyAChangeBack = static function (?DateTimeImmutable $m) use ($london): bool {
        if ($m === null) {
            return false;
        }
        $before = (new DateTimeImmutable('@' . ($m->getTimestamp() - 60)))->setTimezone($london)->getOffset();
        $after  = (new DateTimeImmutable('@' . ($m->getTimestamp() + 60)))->setTimezone($london)->getOffset();

        return $before > $after;   // the clocks fall back, so the day gains an hour
    };
    $utcZone = new DateTimeZone('UTC');

    $deepWinter = fi_nextClocksBack(new DateTimeImmutable('2026-01-15', $utcZone));
    fi_ok('J0 deep winter (15 January, nowhere near either edge): a real clocks-back moment comes back',
        $reallyAChangeBack($deepWinter) === true,
        $deepWinter === null ? 'null' : $deepWinter->format('Y-m-d H:i:s') . ' UTC');

    $lastCaptured = fi_nextClocksBack(new DateTimeImmutable('2026-10-15', $utcZone));
    fi_ok('J0 the day before the null fortnight starts (15 October) still reaches a real clocks-back moment',
        $reallyAChangeBack($lastCaptured) === true,
        $lastCaptured === null ? 'null' : $lastCaptured->format('Y-m-d H:i:s') . ' UTC');

    $firstMissed = fi_nextClocksBack(new DateTimeImmutable('2026-10-16', $utcZone));
    fi_ok('J0 THE NULL FORTNIGHT, first day (16 October): the range now starts after this year\'s clock change and '
        . 'ends before next year\'s, so this correctly returns null rather than an ordinary day standing in for one',
        $firstMissed === null,
        $firstMissed === null ? 'null, as required' : 'wrongly returned ' . $firstMissed->format('Y-m-d H:i:s') . ' UTC');

    $lastMissed = fi_nextClocksBack(new DateTimeImmutable('2026-11-04', $utcZone));
    fi_ok('J0 THE NULL FORTNIGHT, last day (4 November): still null',
        $lastMissed === null,
        $lastMissed === null ? 'null, as required' : 'wrongly returned ' . $lastMissed->format('Y-m-d H:i:s') . ' UTC');

    $firstRecaptured = fi_nextClocksBack(new DateTimeImmutable('2026-11-05', $utcZone));
    fi_ok('J0 the day after the null fortnight ends (5 November) reaches a real clocks-back moment again — next '
        . 'year\'s, since this year\'s has fallen out of the range behind it',
        $reallyAChangeBack($firstRecaptured) === true,
        $firstRecaptured === null ? 'null' : $firstRecaptured->format('Y-m-d H:i:s') . ' UTC');

    // Each shape is read twice: first a version short enough to be read right
    // through, so every date is stored; then the longer one, which is cut.
    fi_reset();
    $plan      = fi_seriesPlan();
    $dropped   = array_slice($plan['dates'], 400);          // the twenty the limit leaves out
    $localOf   = static function (DateTimeImmutable $moment) use ($london): string {
        return $moment->setTimezone($london)->format('Y-m-d H:i:s');
    };
    $movedLate = $localOf($plan['dates'][419]->modify('+60 days')->setTime(9, 0, 0));
    echo 'the shorter version of the long repeating event runs ' . $localOf($plan['dates'][30]) . ' to '
        . $localOf($plan['dates'][419]) . '; the longer one adds thirty dates back to '
        . $localOf($plan['dates'][0]) . ', which pushes the twenty from ' . $localOf($dropped[0])
        . ' past the limit' . "\n";

    // J1 — the 400th date kept is MOVED LATER by a changed-date block, so the
    // old end point landed two months after twenty dates the limit dropped.
    $feed = fi_addFeed('Series, last kept moved later', fi_url('/f/series-390.ics'));
    $r    = fi_refresh($feed);
    fi_ok('J1 a 390-date repeating event is read right through: 390 dates, complete',
        $r['outcome'] === 'ok' && fi_liveCount($feed) === 390, fi_liveCount($feed) . ' / ' . json_encode($r));
    $r = fi_refresh($feed, fi_url('/f/series-420-moved-late.ics'));
    fi_ok('J1 the longer version is reported as cut short', $r['outcome'] === 'partial', json_encode($r));
    fi_ok('J1 THE FAULT: the twenty dates the limit dropped — every one of them still in the calendar — are NOT removed',
        fi_removedStarts($feed) === [] && (int) fi_lastRun($feed)['rowsRemoved'] === 0,
        'marked as removed: ' . json_encode(fi_removedStarts($feed)));
    fi_ok('J1 and the administrator is told nothing was removed, rather than being given a moment that is not true',
        str_contains($r['message'], 'nothing was removed'), $r['message']);
    fi_ok('J1 CONTROL: the moved date really is live at its new time, so this is the shape it claims to be',
        (int) fi_one('SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND startDateTime = ? AND isDeleted = 0',
            'is', [$feed, $movedLate])['n'] === 1, 'looking for ' . $movedLate);

    // J2 — the FIRST date the limit drops is moved to BEFORE the series even
    // begins. This is the shape that rules out the smaller-looking fix
    // ("report the ORIGINAL start of the last date kept"): that date is
    // neither kept nor handed back on its own, so it sits before any honest
    // end point and would be deleted anyway.
    $movedEarly = $localOf($plan['first']->modify('-3 days')->setTime(12, 0, 0));
    $feed = fi_addFeed('Series, first dropped moved earlier', fi_url('/f/series-390-moved-early.ics'));
    $r    = fi_refresh($feed);
    fi_ok('J2 the shorter version is read right through, and the moved date is stored at ' . $movedEarly,
        $r['outcome'] === 'ok'
        && (int) fi_one('SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND startDateTime = ? AND isDeleted = 0',
            'is', [$feed, $movedEarly])['n'] === 1, json_encode($r));
    $r = fi_refresh($feed, fi_url('/f/series-420-moved-early.ics'));
    fi_ok('J2 THE FAULT: that date is not removed either, although it now sits before everything else in the calendar',
        $r['outcome'] === 'partial' && fi_removedStarts($feed) === [] && (int) fi_lastRun($feed)['rowsRemoved'] === 0,
        'marked as removed: ' . json_encode(fi_removedStarts($feed)));

    // J3 — both limits at once, with the whole-calendar slice cutting LATER
    // than the series did. The end point is the EARLIEST of its sources, so
    // without throwing it away for the whole read this would simply fall back
    // to the slice's point — which is later still, and deletes the same dates.
    fi_setLimit('');
    $feed = fi_addFeed('Both limits', fi_url('/f/series-390-plus-late.ics'));
    $r    = fi_refresh($feed);
    fi_ok('J3 390 series dates plus ten later one-offs are read right through: 400 dates',
        $r['outcome'] === 'ok' && fi_liveCount($feed) === 400, fi_liveCount($feed) . ' / ' . json_encode($r));
    fi_setLimit('405');
    $r = fi_refresh($feed, fi_url('/f/series-420-moved-late-plus-late.ics'));
    fi_ok('J3 THE FAULT: with BOTH limits reached, the whole read still reports no end point and removes nothing',
        $r['outcome'] === 'partial' && fi_removedStarts($feed) === []
        && (int) fi_lastRun($feed)['rowsRemoved'] === 0 && str_contains($r['message'], 'nothing was removed'),
        'marked as removed: ' . json_encode(fi_removedStarts($feed)) . ' / ' . $r['message']);
    fi_setLimit('');

    // J4 — the clock-change night, with NO changed date anywhere. The 400th
    // date kept reads 01:30 (British Summer Time) and the first one dropped
    // reads 01:15 (Greenwich Mean Time) — a later moment but an earlier clock
    // reading, and clock readings are what the removal step compares.
    $clocksBack = fi_j4ClocksBack();
    if ($clocksBack === null) {
        fi_skipped(
            'J4 the same fault on the night the clocks go back, end to end',
            'there is no clocks-back night anywhere in the period the portal keeps, so the time-zone data has '
            . 'stopped changing the clocks in ' . SITE_ZONE . ' — part G refuses loudly for the same reason. The '
            . 'same shape is proved without a database or a server, on fixed dates that cannot drift, in '
            . 'tools/ics-reader-selftest.php, part I33d.'
        );
    } elseif ((new DateTimeImmutable('@' . ($clocksBack->getTimestamp() - 60)))->setTimezone($london)->getOffset()
        <= (new DateTimeImmutable('@' . ($clocksBack->getTimestamp() + 60)))->setTimezone($london)->getOffset()) {
        // THE BELT, added 24 September 2026 alongside the fi_nextClocksBack()
        // fix. This whole block used to print three cheerful PASS lines for
        // 154 days of the year against a moment that was NOT a clock change
        // at all — see the doc block on fi_nextClocksBack() for the measured
        // fault, and J0 above for the direct proof it is fixed. THIS check is
        // the second, independent line of defence: it does not trust that
        // fi_nextClocksBack() is still correct, it MEASURES the moment it was
        // actually handed. A real "clocks go back" moment always has a
        // GREATER UTC offset a minute before it than a minute after it — the
        // day gains an hour — so anything else refuses loudly here instead of
        // quietly building a test around a moment that proves nothing.
        fi_ok(
            'J4 REFUSED: fi_j4ClocksBack() returned ' . $clocksBack->format('Y-m-d H:i:s') . ' UTC, which is '
            . 'NOT really a moment the clocks go back in London — the rest of J4 is skipped rather than silently '
            . 'passing on a night that never happened',
            false,
            'this must never fire; if it does, fi_nextClocksBack() has regressed the same way it was found broken '
            . 'in round 3 of #514 part 6 — see the doc block above it'
        );
    } else {
        $halfPast = $localOf($clocksBack->modify('-30 minutes'));
        $quarter  = $localOf($clocksBack->modify('+15 minutes'));
        echo 'the clocks go back at ' . $localOf($clocksBack) . ' local; the last date kept reads ' . $halfPast
            . ' and the first one dropped reads ' . $quarter
            . (fi_nextClocksBack() === null ? ' (part G\'s night: fi_nextClocksBack() has none today)' : '') . "\n";
        $feed = fi_addFeed('Series across the clock change', fi_url('/f/series-dst-short.ics'));
        $r    = fi_refresh($feed);
        fi_ok('J4 the shorter version is read right through, so BOTH of those dates are stored',
            $r['outcome'] === 'ok' && fi_liveCount($feed) === 106
            && (int) fi_one('SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND startDateTime = ? AND isDeleted = 0',
                'is', [$feed, $quarter])['n'] === 1,
            fi_liveCount($feed) . ' / ' . json_encode($r));
        $r = fi_refresh($feed, fi_url('/f/series-dst.ics'));
        fi_ok('J4 THE FAULT: the ' . $quarter . ' event is NOT removed, although it reads earlier on a clock than '
            . 'the last date the limit kept (' . $halfPast . ')',
            $r['outcome'] === 'partial' && fi_removedStarts($feed) === [] && (int) fi_lastRun($feed)['rowsRemoved'] === 0,
            'marked as removed: ' . json_encode(fi_removedStarts($feed)));
        fi_ok('J4 CONTROL: and the ' . $halfPast . ' date that WAS kept is live, so this is the shape it claims to be',
            (int) fi_one('SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND startDateTime = ? AND isDeleted = 0',
                'is', [$feed, $halfPast])['n'] === 1);
    }

    // J5 — THE OTHER DIRECTION, in two parts. Without these, "a cut series
    // removes nothing" would pass just as well on an importer that never
    // removed anything at all.
    $feed = fi_addFeed('Series, one date genuinely gone', fi_url('/f/series-390.ics'));
    fi_refresh($feed);
    $r = fi_refresh($feed, fi_url('/f/series-420-one-gone.ics'));
    fi_ok('J5 THE COST, stated as a check rather than a comment: a date genuinely TAKEN OUT of a calendar whose '
        . 'repeating event is over the limit is NOT removed either, and stays visible until a refresh can read the '
        . 'whole period',
        $r['outcome'] === 'partial' && fi_removedStarts($feed) === [],
        'marked as removed: ' . json_encode(fi_removedStarts($feed)));
    $r = fi_refresh($feed, fi_url('/f/series-390.ics'), true);
    fi_ok('J5 CONTROL: and when a refresh CAN read the whole period, removal happens exactly as it always did',
        $r['outcome'] === 'ok' && fi_liveCount($feed) === 390 && (int) fi_lastRun($feed)['rowsRemoved'] > 0,
        fi_liveCount($feed) . ' live, ' . fi_lastRun($feed)['rowsRemoved'] . ' removed');

    // -------------------------------------------------------------------------
    fi_heading('K. The scheduled job');
    // -------------------------------------------------------------------------
    fi_reset();
    $mysqli->query('DELETE FROM tblErrors');
    $mysqli->query("DELETE FROM tblSettings WHERE settingKey = 'feeds.cron_token'");
    $mysqli->query('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
        . "VALUES (NULL, 'feeds.cron_token', 'p6-selftest-token', '', 0)");

    $jobFeeds = [];
    for ($i = 1; $i <= 4; $i++) {
        $jobFeeds[] = fi_addFeed('A calendar whose name must never be printed ' . $i, fi_url('/f/ten.ics'));
    }
    $jobOut = fi_runJob();
    fi_ok('the job refreshes every calendar that is due and prints its summary line',
        substr_count($jobOut, ': ok') === 4 && str_contains($jobOut, 'done: due=4'), trim($jobOut));
    fi_ok('the job NEVER prints a calendar\'s name or its address — both go in e-mails and hosting logs, and some '
        . 'of those addresses are secret links',
        str_contains($jobOut, 'must never be printed') === false && str_contains($jobOut, 'calendar.test') === false
        && str_contains($jobOut, '://') === false, trim($jobOut));
    fi_ok('CONTROL: a wrong token is refused and nothing is refreshed',
        str_contains(fi_runJob('the-wrong-token'), 'Forbidden'), trim(fi_runJob('the-wrong-token')));

    // The safety net itself. `refresh()` promises never to throw; this is what
    // happens when it cannot keep that promise. Before the fix the job died on
    // the first such calendar, printed no summary and abandoned the rest — the
    // exact opposite of the promise written beside it.
    $mysqli->query('DELETE FROM tblErrors');
    foreach ($jobFeeds as $feedId) {
        fi_makeDue($feedId);
    }
    $mysqli->query('ALTER TABLE tblExternalFeeds RENAME COLUMN lastContentHash TO lastContentHashHidden');
    $jobOut = fi_runJob();
    $mysqli->query('ALTER TABLE tblExternalFeeds RENAME COLUMN lastContentHashHidden TO lastContentHash');
    $threw = fi_q("SELECT errorCode, siteID FROM tblErrors WHERE errorCode = 'FeedRefreshThrew'");
    fi_ok('the job survives a calendar whose refresh throws: it does not die on the first one',
        substr_count($jobOut, ': failed') === 4, 'expected 4 failed lines; got: ' . trim($jobOut));
    fi_ok('...it still prints its summary line', str_contains($jobOut, 'done: due='), trim($jobOut));
    fi_ok('...there is no uncaught fault anywhere in what it printed',
        stripos($jobOut, 'TypeError') === false && stripos($jobOut, 'Fatal error') === false
        && stripos($jobOut, 'Uncaught') === false, trim($jobOut));
    fi_ok('...and every one is in the error log, against its OWN organisation',
        count($threw) === 4, json_encode($threw));
    $mysqli->query('DELETE FROM tblErrors');

    // -------------------------------------------------------------------------
    fi_heading('L. Part 7: choices, rules and approvals through a REAL refresh and the REAL job (real clock only)');
    // -------------------------------------------------------------------------
    echo 'part L runs on the real clock; its one reading of the database clock: ' . fl_dbNowUtc()->format('Y-m-d H:i:s') . " UTC\n";

    // E17 — a failed refresh changes nothing: rules, a choice, a waiting row
    // and an approved row present, then the download answers 500.
    fi_reset();
    $feed = fi_addFeed('Part L, failure', fi_url('/f/ten.ics'));
    $mysqli->query("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = " . $feed);
    $waitRule = fl_rule($feed, 'public', 'title', 'equals', 'Event number 1');
    fl_rule($feed, 'public', 'title', 'equals', 'Event number 3');
    fi_q('INSERT INTO tblExternalEventChoices (siteID, feedID, scope, externalUidHash, externalRecurrenceKey, audienceLevel, detailLevel, '
        . "createdByID, createdAt) VALUES (?, ?, 'series', UNHEX(SHA2('ev5@p6.test', 256)), '', 'hidden', 'basic', ?, UTC_TIMESTAMP())",
        'iii', [SITE_A, $feed, VIEWER_6]);
    fi_refresh($feed);
    $three = fl_event($feed, 'Event number 3');
    fl_decide((int) fl_appr((int) $three['eventID'])[0]['approvalID'], 'approved');
    fl_resolve($feed);
    $setUp = fl_event($feed, 'Event number 1')['importSource'] === 'waiting' && fl_event($feed, 'Event number 3')['importLevel'] === 'public'
        && fl_event($feed, 'Event number 5')['importLevel'] === 'hidden';
    fi_ok('E17 set-up: a waiting row (Event number 1), an approved one (Event number 3) and a choice (Event number 5) are in place', $setUp,
        json_encode([fl_event($feed, 'Event number 1'), fl_event($feed, 'Event number 3'), fl_event($feed, 'Event number 5')]));
    // An administrator edits a rule, and nothing has worked the answers out
    // since. A failed refresh must NOT be the thing that does: it knows
    // nothing about the calendar, so it touches nothing (acceptance 13).
    fi_q("UPDATE tblExternalFeedRules SET audienceLevel = 'hidden' WHERE ruleID = ?", 'i', [$waitRule]);
    $before = fl_fingerprint($feed);
    $r = fi_refresh($feed, fi_url('/status/500'));
    fi_ok('E17 — a refresh whose download answers 500 fails, and changes NOTHING in the six #514 tables of that calendar (the edited rule is not applied by it)',
        $r['outcome'] === 'failed' && fl_fingerprint($feed) === $before && fl_event($feed, 'Event number 1')['importSource'] === 'waiting', json_encode($r));
    $r = fi_refresh($feed, fi_url('/f/ten.ics'), true);
    fi_ok('E17 KEEP-WORKING: a later good download DOES work the answers out — the rule edited meanwhile takes effect (Event number 1 hidden, source rule)',
        $r['outcome'] === 'ok' && fl_event($feed, 'Event number 1')['importLevel'] === 'hidden' && fl_event($feed, 'Event number 1')['importSource'] === 'rule',
        json_encode(fl_event($feed, 'Event number 1')));

    // E18 — a removed event's waiting row is withdrawn; when it comes back
    // it is worked out again.
    fi_reset();
    $feed = fi_addFeed('Part L, removal', fi_url('/f/ten.ics'));
    $mysqli->query("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = " . $feed);
    fl_rule($feed, 'public', 'title', 'equals', 'Event number 4');
    fi_refresh($feed);
    $four = (int) fl_event($feed, 'Event number 4')['eventID'];
    fi_ok('E18 set-up: Event number 4 is waiting', (fl_appr($four)[0]['status'] ?? '') === 'pending', json_encode(fl_appr($four)));
    fi_refresh($feed, fi_url('/f/nine.ics'), true);
    fi_ok('E18 — after the download without it, its waiting row is withdrawn',
        (int) fi_one('SELECT isDeleted FROM tblEvents WHERE eventID = ?', 'i', [$four])['isDeleted'] === 1
        && array_column(fl_appr($four), 'status') === ['withdrawn'], json_encode(fl_appr($four)));
    fi_refresh($feed, fi_url('/f/ten.ics'), true);
    fi_ok('E18 — when it comes back it is worked out again: a new waiting row, new_match',
        array_column(fl_appr($four), 'status') === ['withdrawn', 'pending'] && fl_appr($four)[1]['reason'] === 'new_match', json_encode(fl_appr($four)));
    fl_decide((int) fl_appr($four)[1]['approvalID'], 'approved');
    fl_resolve($feed);
    fi_refresh($feed, fi_url('/f/nine.ics'), true);
    fi_ok('E18 KEEP-WORKING: an APPROVED row of a removed date stays approved (it still counts as a decision on its repeating event)',
        array_column(fl_appr($four), 'status') === ['withdrawn', 'approved'], json_encode(fl_appr($four)));
    fi_refresh($feed, fi_url('/f/ten.ics'), true);
    fi_ok('E18 — ...and when that date comes back, its approval applies again (public, no new waiting row)',
        fl_event($feed, 'Event number 4')['importLevel'] === 'public' && count(fl_appr($four)) === 2, json_encode(fl_appr($four)));
    // A waiting row left behind on an event removed some OTHER way (a
    // removal before part 7, or a hand edit) is withdrawn by whichever
    // caller works the answers out next — here the job's recheck pass, not
    // the importer's own removal step.
    fi_q("UPDATE tblExternalFeedRules SET audienceLevel = 'public', detailLevel = 'full' WHERE feedID = ?", 'i', [$feed]);
    fl_resolve($feed);
    $pendingNow = array_values(array_filter(fl_appr($four), static fn (array $a): bool => $a['status'] === 'pending'));
    fi_q('UPDATE tblEvents SET isDeleted = 1 WHERE eventID = ?', 'i', [$four]);
    $expired = fl_dbNowUtc()->modify('-1 hour')->format('Y-m-d H:i:s');
    fi_q('UPDATE tblEvents SET importRecheckAt = ? WHERE externalFeedID = ? AND eventName = ?', 'sis', [$expired, $feed, 'Event number 1']);
    FeedImporter::recheckDue($mysqli, microtime(true) + 20);
    fi_ok('E18 — a waiting row of an event removed some other way is withdrawn by the next caller that works answers out (here the recheck pass)',
        count($pendingNow) === 1 && fi_one('SELECT status FROM tblExternalEventApprovals WHERE approvalID = ?', 'i', [(int) $pendingNow[0]['approvalID']])['status'] === 'withdrawn',
        json_encode(fl_appr($four)));

    // E19 — the run row records how many are waiting, not only the new ones.
    fi_reset();
    $feed = fi_addFeed('Part L, waiting count', fi_url('/f/ten.ics'));
    $mysqli->query("UPDATE tblExternalFeeds SET audienceLevel = 'members' WHERE feedID = " . $feed);
    foreach (['Event number 1', 'Event number 2', 'Event number 3'] as $title) {
        fl_rule($feed, 'public', 'title', 'equals', $title);
    }
    $r1 = fi_refresh($feed);
    $run1 = fi_lastRun($feed);
    fi_ok('E19 KEEP-WORKING: the first run — three dates waiting: awaitingApproval 3, and refresh() says 3 are new',
        (int) $run1['awaitingApproval'] === 3 && $r1['newPending'] === 3, json_encode([$r1, $run1['awaitingApproval']]));
    $r2 = fi_refresh($feed, fi_url('/f/ten-comment.ics'));
    $run2 = fi_lastRun($feed);
    fi_ok('E19 — a changed file with no change to any event: the run row still says 3 waiting, and refresh() says 0 are new',
        $r2['outcome'] === 'ok' && (int) $run2['awaitingApproval'] === 3 && $r2['newPending'] === 0 && (int) $run2['runID'] !== (int) $run1['runID'],
        json_encode([$r2, $run2['awaitingApproval']]));

    // E20 — the job's recheck pass.
    fi_reset();
    $mysqli->query("DELETE FROM tblSettings WHERE settingKey = 'feeds.cron_token'");
    $mysqli->query('INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
        . "VALUES (NULL, 'feeds.cron_token', 'p6-selftest-token', '', 0)");
    $due = fi_addFeed('A calendar whose name must never be printed, rechecked', fi_url('/f/ten.ics'));
    $paused = fi_addFeed('A paused calendar whose name must never be printed', fi_url('/f/ten.ics'));
    fi_refresh($due);
    fi_refresh($paused);
    $expired = fl_dbNowUtc()->modify('-1 hour')->format('Y-m-d H:i:s');
    $tomorrow = fl_dbNowUtc()->modify('+1 day')->format('Y-m-d H:i:s');
    fi_q("UPDATE tblEvents SET importRecheckAt = ? WHERE externalFeedID IN (?, ?) AND eventName = 'Event number 2'", 'sii', [$expired, $due, $paused]);
    fi_q('UPDATE tblExternalFeeds SET nextFetchAt = ? WHERE feedID IN (?, ?)', 'sii', [$tomorrow, $due, $paused]);
    fi_q('UPDATE tblExternalFeeds SET isActive = 0 WHERE feedID = ?', 'i', [$paused]);
    fi_ok('E20 set-up: the expired row is shown to administrators only', in_array('Event number 2', fi_grid(VIEWER_1), true) === false, implode(' | ', fi_grid(VIEWER_1)));
    $jobOut = fi_runJob();
    $lines  = explode("\n", trim($jobOut));
    fi_ok('E20 — the job prints ONE recheck line, first, with the counts: due=1 reworked=1 problems=0 notStarted=0 (the paused calendar is not counted)',
        ($lines[0] ?? '') === 'recheck: due=1 reworked=1 problems=0 notStarted=0' && substr_count($jobOut, 'recheck:') === 1, trim($jobOut));
    fi_ok('E20 — the expired row\'s answer is worked out again, and a member sees it again',
        fl_event($due, 'Event number 2')['importRecheckAt'] === null && in_array('Event number 2', fi_grid(VIEWER_1), true) === true,
        json_encode(fl_event($due, 'Event number 2')));
    fi_ok('E20 KEEP-WORKING: the paused calendar\'s expired row is NOT reworked (its answer stays expired)',
        fl_event($paused, 'Event number 2')['importRecheckAt'] === $expired, json_encode(fl_event($paused, 'Event number 2')));
    fi_ok('E20 — the output names no calendar and holds no address; the recheck line holds neither ": ok" nor ": failed"',
        str_contains($jobOut, 'must never be printed') === false && str_contains($jobOut, '://') === false
        && str_contains($lines[0] ?? '', ': ok') === false && str_contains($lines[0] ?? '', ': failed') === false, trim($jobOut));

    // E30 — the recheck pass's order, and its own log line.
    fi_reset();
    $mysqli->query('DELETE FROM tblErrors');
    $low  = fi_addFeed('A lower-numbered calendar', fi_url('/f/ten.ics'));
    $high = fi_addFeed('A higher-numbered calendar', fi_url('/f/ten.ics'));
    fi_refresh($low);
    fi_refresh($high);
    fi_q("UPDATE tblEvents SET importRecheckAt = ? WHERE externalFeedID = ? AND eventName = 'Event number 2'",
        'si', [fl_dbNowUtc()->modify('-1 hour')->format('Y-m-d H:i:s'), $low]);
    fi_q("UPDATE tblEvents SET importRecheckAt = ? WHERE externalFeedID = ? AND eventName = 'Event number 2'",
        'si', [fl_dbNowUtc()->modify('-2 hours')->format('Y-m-d H:i:s'), $high]);
    $queue = (new ReflectionMethod(FeedImporter::class, 'recheckQueue'))->invoke(null, $mysqli);
    fi_ok('E30 — the recheck list puts the calendar whose answer ran out FIRST at the top, whatever its number (the higher-numbered one here)',
        array_column($queue, 'feedID') === [$high, $low], json_encode($queue));
    // A fault forced into ONE calendar's rework only. Renaming a table (the
    // way part I1 forces a refresh fault) would break BOTH calendars' rework
    // inside the one call, so a trigger that refuses an update to the higher
    // calendar's events is used instead; it is dropped straight afterwards.
    $mysqli->query('DROP TRIGGER IF EXISTS trg_p7_selftest_fault');
    $mysqli->query('CREATE TRIGGER trg_p7_selftest_fault BEFORE UPDATE ON tblEvents FOR EACH ROW BEGIN '
        . 'IF NEW.externalFeedID = ' . (int) $high . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted by the part 7 self-test'; END IF; END");
    try {
        $counts = FeedImporter::recheckDue($mysqli, microtime(true) + 20);
    } finally {
        $mysqli->query('DROP TRIGGER IF EXISTS trg_p7_selftest_fault');
    }
    $logged = fi_q("SELECT errorCode, errorTitle, errorDetail FROM tblErrors WHERE errorCode IN ('FeedRecheckFailed', 'FeedRefreshFailed')");
    fi_ok('E30 — one calendar failing does not stop the other: problems=1, reworked=1',
        $counts['problems'] === 1 && $counts['reworked'] === 1 && $counts['due'] === 2, json_encode($counts));
    fi_ok('E30 — the failure is logged as the RECHECK\'s own: code FeedRecheckFailed, "Re-checking who may see calendar #' . $high . '", with the file and line',
        count($logged) === 1 && $logged[0]['errorCode'] === 'FeedRecheckFailed'
        && str_starts_with((string) $logged[0]['errorTitle'], 'Re-checking who may see calendar #' . $high . ' ')
        && preg_match('/\.php:\d+$/', (string) $logged[0]['errorDetail']) === 1, json_encode($logged));
    fi_ok('E30 — ...and names no calendar and holds no address',
        count($logged) === 1 && str_contains(json_encode($logged), 'calendar.test') === false && str_contains(json_encode($logged), 'higher-numbered') === false,
        json_encode($logged));
    // KEEP-WORKING: the two new parameters default to exactly what every
    // other caller always wrote, so a failed REFRESH is still logged as one
    // (the part I1 fault, forced again here).
    $mysqli->query('DELETE FROM tblErrors');
    $mysqli->query('RENAME TABLE tblExternalEventTags TO tblExternalEventTagsHidden');
    try {
        $r = fi_refresh($low, null, true);
    } finally {
        $mysqli->query('RENAME TABLE tblExternalEventTagsHidden TO tblExternalEventTags');
    }
    $refreshLog = fi_q("SELECT errorCode, errorTitle FROM tblErrors WHERE errorCode IN ('FeedRecheckFailed', 'FeedRefreshFailed')");
    fi_ok('E30 KEEP-WORKING: a failed REFRESH is still logged as FeedRefreshFailed, "Refreshing calendar #' . $low . '" (the defaults are unchanged)',
        $r['outcome'] === 'failed' && count($refreshLog) === 1 && $refreshLog[0]['errorCode'] === 'FeedRefreshFailed'
        && str_starts_with((string) $refreshLog[0]['errorTitle'], 'Refreshing calendar #' . $low . ' '), json_encode($refreshLog));
    $mysqli->query('DELETE FROM tblErrors');

    // -------------------------------------------------------------------------
    fi_heading('M. A real Google or Microsoft 365 export');
    // -------------------------------------------------------------------------
    fi_skipped(
        'the importer against a genuine Google or Microsoft 365 export',
        'no captured export exists in this repository (the owner\'s decision of 21 September 2026), so the '
        . 'acceptance criterion "works against a real Google or Microsoft 365 calendar" is NOT proven by this '
        . 'script or by anything else here. The calendar files above are hand-written to match what those '
        . 'systems are documented to produce, which is not the same thing.'
    );
} catch (Throwable $e) {
    fi_ok('the self-test ran to the end', false,
        get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

// =============================================================================
// 🧹 Tidy up and report
// =============================================================================
// The clean-up must never throw from here: on a database where a table is
// missing it would end PHP with 255 and a stack trace instead of the promised
// 1. A failed clean-up is one more FAIL line, and the summary below still runs.
try {
    $mysqli->query('ALTER TABLE tblExternalEventTags MODIFY tag VARCHAR(100) NOT NULL');
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'tblExternalFeedRuns', 'tblExternalEventTags', 'tblExternalCategoryMap',
        'tblExternalAudienceMembers', 'tblEventRSVPs', 'tblEvents', 'tblEventSeries',
        'tblExternalFeeds', 'tblEventCategories', 'tblUserSites', 'tblUsers', 'tblSites',
        // #514 part P7's four tables, emptied with the rest so no choice, rule
        // or approval row is left pointing at a calendar that has gone.
        'tblExternalEventApprovals', 'tblExternalRuleConditions', 'tblExternalFeedRules', 'tblExternalEventChoices',
    ] as $table) {
        $mysqli->query('TRUNCATE TABLE ' . $table);
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
    $mysqli->query("DELETE FROM tblSettings WHERE settingKey IN ('site.timezone', 'feeds.maxEventsPerFeed', 'feeds.cron_token')");
    $mysqli->query('DELETE FROM tblErrors');
    echo "\nThe made-up world was removed.\n";
} catch (Throwable $e) {
    echo "\n";
    fi_ok('the made-up world was removed afterwards', false, get_class($e) . ': ' . $e->getMessage());
}

fi_finish();
