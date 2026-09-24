<?php
// Path: _apps/cron/import-feeds.php
/**
 * -----------------------------------------------------------------------------
 * Scheduled job — refresh the outside calendars that are due ⏰🗓️
 * -----------------------------------------------------------------------------
 * An organisation can subscribe the portal to an outside calendar (a Google,
 * Microsoft 365 or other published calendar file — issue #327). This page is
 * what a scheduled task asks for, every few minutes, to keep those copies up
 * to date. It does none of the work itself: it decides which calendars are
 * due and hands each one to `Portal\Core\FeedImporter::refresh()`.
 *
 * WHAT CHANGED FROM #327, AND WHY (this file was rewritten by #514 part P6)
 * ------------------------------------------------------------------------
 * The old version of this page did everything in one place: it fetched each
 * address with no checks at all, read the file with a fifty-line parser that
 * understood seven properties and no repeats, and wrote one row per event
 * with `isPublic = 1`. Three things were wrong with that, and all three are
 * fixed by the classes this file now calls:
 *
 *   * **It would fetch anything.** An address pointing at the hosting
 *     company's own internal network was fetched happily, which is how a
 *     portal is used to read things it should never see. `SafeFetch` now
 *     decides (part P4).
 *   * **It could not read a repeating event.** A weekly meeting arrived as
 *     ONE event, on its first date, for ever. `IcsReader` works out the real
 *     dates (part P5).
 *   * **Everything it copied in was public to the whole internet.** Who may
 *     see a copied-in event is now decided by `FeedResolver` and read by
 *     `EventVisibility` (parts P1 and P6).
 *
 * WHY THIS PAGE SETS ITS OWN TIME LIMIT
 * -------------------------------------
 * Like every scheduled job in this portal, this page is asked for over the
 * web — shared hosting gives no command line. So PHP's ordinary limit for a
 * web request applies, and that is usually thirty seconds. Going over it
 * ends the request with a fault nothing can catch, half way through whatever
 * was happening, exactly like running out of memory. One calendar alone is
 * allowed up to forty seconds, so the default would kill this job on its
 * first slow calendar, every time, with nothing in any log to say why.
 *
 * The limit is set ONCE, here, before the loop. It is deliberately not set
 * inside the loop: `set_time_limit()` RESTARTS the count, so calling it once
 * per calendar would mean PHP's limit never fired at all and a job with two
 * hundred calendars would run until the web server gave up. What stops this
 * job is its own budget, checked against the clock on every turn of the loop.
 *
 * WHAT THIS PAGE PRINTS, AND WHAT IT MUST NEVER PRINT
 * ---------------------------------------------------
 * One line per calendar, naming it by NUMBER only, then a summary line.
 * Never a calendar's name and never its address. Two reasons. Whatever a
 * scheduled task prints usually ends up in an e-mail to whoever set it up,
 * and in the hosting company's own logs. And some of these addresses are
 * secret links — anybody holding one can read the whole diary — so an
 * address printed here has been handed to everyone who can read that mail.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\FeedImporter;
use Portal\Core\Logger;
use Portal\Core\Settings;

// -----------------------------------------------------------------------------
// 🔑 Token gate (unchanged from #327). An empty stored token ALWAYS refuses,
//    so this address is inert until an administrator sets a real one. The
//    comparison is constant-time so that a guess cannot be narrowed down by
//    how long the refusal takes.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('feeds.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

// ⏱️ See "WHY THIS PAGE SETS ITS OWN TIME LIMIT" above. Three times the
//    job's own budget, because the budget decides when to stop STARTING
//    calendars and the last one started may still take its full forty
//    seconds after that.
@set_time_limit(FeedImporter::JOB_BUDGET_SECONDS * 3 + 30);

$jobStart = microtime(true);

// -----------------------------------------------------------------------------
// 📋 Which calendars are due
// -----------------------------------------------------------------------------
// One statement, asked once. `nextFetchAt` is a UTC moment, compared with the
// database's own UTC clock — never with PHP's, which can differ by seconds.
//
// A calendar that has never been refreshed has no moment at all, and is due
// straight away; ordering puts those first, so a newly added calendar does
// not wait behind a queue of old ones.
//
// $mysqli and $SETTINGS are the only two things a page under _apps/ inherits
// (Router.php). Nothing here reaches for anything else.
$due    = [];
$result = $mysqli->query(
    'SELECT feedID, siteID FROM tblExternalFeeds '
    . 'WHERE isActive = 1 AND (nextFetchAt IS NULL OR nextFetchAt <= UTC_TIMESTAMP()) '
    . "ORDER BY COALESCE(nextFetchAt, '1970-01-01'), feedID"
);
if ($result !== false) {
    while (($row = $result->fetch_assoc()) !== null) {
        $due[] = ['feedID' => (int) $row['feedID'], 'siteID' => (int) $row['siteID']];
    }
    $result->free();
}

// NOTE FOR PART P7 (#514 plan, section 1.8, "The job's recheck pass"). Once
// per-date choices and rules exist, this job must ALSO re-work-out who may
// see the events of any calendar holding a live row whose `importRecheckAt`
// has passed — that is what lets a choice with an end date start applying,
// and stop applying, on the right day. It is deliberately NOT built here:
// nothing in part P6 ever writes a moment into `importRecheckAt`, so the
// pass could not be tested, and a piece of code that has never once run is
// not cover — it only looks like cover. Until then the failure is still
// closed rather than open, because the visibility rule itself refuses an
// event whose stored answer has run out (part P1).

// -----------------------------------------------------------------------------
// 🔁 Refresh each one, while there is time
// -----------------------------------------------------------------------------
$counts = [
    'ok'         => 0,
    'partial'    => 0,
    'unchanged'  => 0,
    'failed'     => 0,
    'skipped'    => 0,
    'notStarted' => 0,
];

foreach ($due as $feed) {
    $feedId = $feed['feedID'];

    if ((microtime(true) - $jobStart) >= FeedImporter::JOB_BUDGET_SECONDS) {
        // Out of budget. The rest are named so that whoever reads this knows
        // they were not forgotten, and they stay due — the next run takes
        // them first, because their moment is still in the past.
        $counts['notStarted']++;
        echo 'feed ' . $feedId . ": not started\n";
        continue;
    }

    try {
        $answer  = FeedImporter::refresh($mysqli, $feedId, 'schedule', null);
        $outcome = (string) $answer['outcome'];
    } catch (\Throwable $problem) {
        // `refresh()` promises never to throw, and it goes to some trouble to
        // keep that promise. This is here for the case where it cannot — the
        // database connection itself giving way, for instance. One awkward
        // calendar must not stop the others from being refreshed.
        //
        // `errorPlatformForSite()` and not `errorPlatform()`: this job works
        // through the calendars of EVERY organisation in one request, and
        // `errorPlatform()` would stamp whichever organisation the request
        // happened to start in — writing one organisation's fault into
        // another's error log.
        //
        // TWO THINGS WERE WRONG HERE, and the first independent check of this
        // part (23 September 2026) proved both by making `refresh()` throw.
        //
        // The sixth argument used to be `null`. That argument is declared
        // `string $detail = ''`, and this file runs under
        // `declare(strict_types=1)`, so `null` raises a `TypeError` instead of
        // being turned into an empty string. The safety net crashed the very
        // first time anything used it: the job printed no summary line and
        // never attempted the remaining calendars — the exact opposite of the
        // promise three lines above. It is now the full path and line, the
        // same thing `Logger` puts in that column for a PHP error.
        //
        // And the write itself was not guarded. This `catch` exists for the
        // case where the database connection has given way — and the logger
        // writes to that same database, so the most likely reason to be here
        // is also a reason the logging will fail. An unguarded write turns one
        // awkward calendar into an abandoned job all over again. It now has
        // its own `try`, matching `FeedImporter::logProblem()`. If the log
        // cannot be written the calendar is still counted as failed and the
        // job still works through the rest, which is the whole point.
        try {
            Logger::errorPlatformForSite(
                $feed['siteID'],
                'FeedImport',
                'Error',
                'FeedRefreshThrew',
                'Refreshing calendar #' . $feedId . ' ended with ' . get_class($problem)
                . ' at ' . basename($problem->getFile()) . ':' . $problem->getLine() . '.',
                $problem->getFile() . ':' . $problem->getLine()
            );
        } catch (\Throwable $loggingProblem) {
            // Recording the problem is not worth causing another one.
        }
        $outcome = 'failed';
    }

    if (array_key_exists($outcome, $counts) === true) {
        $counts[$outcome]++;
    }
    echo 'feed ' . $feedId . ': ' . $outcome . "\n";
}

// -----------------------------------------------------------------------------
// 🧾 The summary line
// -----------------------------------------------------------------------------
echo 'done: due=' . count($due)
    . ' ok=' . $counts['ok']
    . ' partial=' . $counts['partial']
    . ' unchanged=' . $counts['unchanged']
    . ' failed=' . $counts['failed']
    . ' skipped=' . $counts['skipped']
    . ' notStarted=' . $counts['notStarted']
    . ' seconds=' . number_format(microtime(true) - $jobStart, 1) . "\n";
