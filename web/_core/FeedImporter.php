<?php
// Path: _core/FeedImporter.php
/**
 * -----------------------------------------------------------------------------
 * Copying somebody else's calendar into the portal, safely 🗓️⬇️
 * -----------------------------------------------------------------------------
 * An organisation can subscribe the portal to an outside calendar — a Google,
 * Microsoft 365 or other published calendar file. This class is what does the
 * subscribing: it downloads the file, reads it, and brings the portal's own
 * copy of those events into line with what the file says.
 *
 * It is the ONE place allowed to write a copied-in event. Everything else in
 * the portal treats those events as read-only (#514 decision D5), and there
 * is an automatic check that keeps it so
 * (`tools/audit-checks/check_event_visibility.py`).
 *
 * The work is split across four classes, each with one job:
 *
 *   `SafeFetch`    — may this address be fetched, and fetch it (part P4).
 *   `IcsReader`    — turn a calendar file into a list of dates (part P5). It
 *                    opens no connection and reads no setting, which is what
 *                    lets it be tested on its own.
 *   `FeedImporter` — THIS CLASS. Decide when to refresh, write the rows, and
 *                    decide what to remove.
 *   `FeedResolver` — work out who may see each of those events.
 *
 * =============================================================================
 * THE ONE THING TO UNDERSTAND BEFORE CHANGING ANYTHING HERE
 * =============================================================================
 * This is the part that DELETES. Every refresh has to answer "which of the
 * events I already have are no longer in this calendar?", and anything it
 * gets wrong there removes real events from a customer's diary. Nobody finds
 * out until somebody misses a service.
 *
 * So the rule, without exception: **an incomplete download never removes
 * anything.** A download can be incomplete in several different ways, and
 * they do not all look alike:
 *
 *   * The connection dropped, or the file was bigger than the limit, so only
 *     part of it arrived. `IcsReader::parse()` says `complete = false`.
 *   * The whole file arrived, but it holds more dates than the portal takes
 *     from one calendar, so the reading stopped part way.
 *     `IcsReader::expand()` says `capped = true`.
 *   * One event listed more skipped dates than the reader keeps, so dates
 *     went missing from INSIDE that event — and they can sit anywhere in the
 *     period. This also comes back as `capped = true`, and with NO reliable
 *     end point.
 *
 * That last one is the one that catches people out, and it is worth spelling
 * out because it was measured, not imagined. A repeating event whose list of
 * cancelled dates is cut short suddenly contributes a handful of dates where
 * the last refresh saw hundreds. Everything looks normal. If this class
 * treated "not in the download" as "no longer in the calendar", that single
 * refresh would quietly remove hundreds of real events.
 *
 * Which is why `capped` is read FIRST, before anything else in the reader's
 * answer, and why a capped read with no end point removes NOTHING AT ALL —
 * not even the rows the plan's own removal statement would otherwise take.
 * See `removalEndPoint()` below, where that decision lives, and why it is
 * slightly stricter than the #514 plan's wording.
 *
 * =============================================================================
 * THE OTHER THINGS THAT ARE NOT OBVIOUS
 * =============================================================================
 *
 * **Only one refresh of a calendar at a time, and it has an END.** The first
 * thing a refresh does is claim the calendar, by writing a moment into
 * `refreshLeaseUntil`. While that moment is in the future, no other refresh
 * of the same calendar will start. It is an END rather than a plain "busy"
 * flag on purpose: a flag set by a request that is then killed — and a web
 * request can be killed at any moment, by a time limit, a memory limit or
 * the server being restarted — stays set for ever, and the calendar never
 * refreshes again. An end means the worst case is a ten-minute wait.
 *
 * **The next refresh is scheduled when this one STARTS, not when it ends.**
 * Otherwise a calendar that kills the request half way through would still
 * be first in the queue on the next run, and the same calendar would be
 * tried over and over while every other calendar waited behind it.
 *
 * **Times.** Two different kinds of time live in this class and they must
 * never be mixed up:
 *   * A MOMENT: `nextFetchAt`, `refreshLeaseUntil`, `externalLastSeenAt`,
 *     `startedAt`. These are UTC instants, compared with `UTC_TIMESTAMP()`.
 *     They are read FROM THE DATABASE, not from PHP, so that the portal and
 *     the database cannot disagree about when "now" is.
 *   * A TIME ON A CLOCK: `startDateTime`, `endDateTime`. These are the
 *     wall-clock reading a person would see, in the organisation's own zone,
 *     and they are compared as text with other wall-clock readings. Never
 *     converted, never turned into an instant. (`DEV_NOTES.md`, "Wall-clock
 *     times compare as text".)
 *
 * **An event's end may legitimately sort BEFORE its start.** On the night the
 * clocks go back, 01:30 and then 01:15 is forty-five real minutes, and no
 * wall-clock reading can say so. This class stores what the reader gives it
 * and does not "repair" that. A tidy-up that swapped them, or refused the
 * event, would look obviously right and be wrong twice a year.
 *
 * **An end can legitimately be as late as 9999-12-31 23:59:59**, because the
 * reader brings anything later back to there — that is where a database
 * `DATETIME` stops. A start can be that late too. Both are checked here
 * rather than trusted.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use DateTimeImmutable;
use DateTimeZone;

final class FeedImporter
{
    // =========================================================================
    // 📏 The limits, all in one place
    // =========================================================================

    /** Most bytes of calendar file to download. Anything bigger is refused. */
    public const MAX_BYTES = 5242880;

    /** Most seconds to wait for the other server on one hop. */
    public const FETCH_TIMEOUT_SECONDS = 15;

    /** Most seconds one calendar may take, downloading and reading together. */
    public const PER_FEED_SECONDS = 40;

    /** Most seconds the scheduled job spends before it stops starting more. */
    public const JOB_BUDGET_SECONDS = 50;

    /** How far back the portal keeps a calendar's events. */
    public const DAYS_BACK = 30;

    /** How far ahead the portal keeps a calendar's events. */
    public const MONTHS_AHEAD = 12;

    /** How long one refresh owns a calendar before another may take over. */
    public const LEASE_MINUTES = 10;

    /** The longest wait a run of failures can push the next try out to. */
    public const MAX_BACKOFF_MINUTES = 1440;

    /** How many refresh attempts are remembered for each calendar. */
    public const RUNS_KEPT = 50;

    /**
     * How long "the file has not changed, so there is nothing to do" may be
     * trusted before the work is done again anyway.
     *
     * Less than a day on purpose. A calendar that never changes would
     * otherwise never be re-read at all, and the window the portal keeps
     * (30 days back, 12 months ahead) MOVES every day — so yesterday's
     * answer stops being today's answer even when the file is byte for byte
     * identical.
     */
    public const UNCHANGED_SKIP_HOURS = 20;

    /** How soon after a refresh an administrator may ask for another by hand. */
    public const MANUAL_MIN_SECONDS = 60;

    /**
     * The shortest gap between scheduled refreshes of one calendar.
     *
     * The "add a calendar" form already refuses anything under fifteen
     * minutes, but a number written straight into the database would slip
     * past that, so it is enforced here as well. Somebody else's server
     * should not be asked for the same file every thirty seconds.
     */
    private const MIN_FETCH_MINUTES = 15;

    /**
     * What the portal tells the other server it would like.
     *
     * Calendar first, then plain text (plenty of servers send a calendar as
     * `text/plain`), then anything at all rather than refusing outright.
     */
    private const ACCEPT_HEADER = 'text/calendar, text/plain;q=0.5, */*;q=0.1';

    /** The database's own idea of a moment, written this way everywhere. */
    private const SQL_MOMENT = 'Y-m-d H:i:s';

    // =========================================================================
    // 🔄 refresh() — the whole job for one calendar
    // =========================================================================

    /**
     * Refresh one calendar: download it, read it, write the events.
     *
     * NEVER throws. Whatever goes wrong, it comes back as an outcome and a
     * sentence an administrator can read, and the calendar is left in a state
     * the next refresh can pick up from. A scheduled job that stopped on the
     * first awkward calendar would silently stop refreshing all the others.
     *
     * @param \mysqli  $db        The connection.
     * @param int      $feedId    The calendar.
     * @param string   $trigger   'schedule' or 'manual'. Anything else is
     *                            treated as 'schedule', which is the cautious
     *                            reading: a manual refresh skips the "is it
     *                            due yet?" test, so an unrecognised word must
     *                            never be allowed to mean "manual".
     * @param int|null $byUserId  Who pressed Refresh, or null for the job.
     *
     * @return array{outcome:string, message:string, newPending:int}
     *         `outcome` is one of `ok`, `partial`, `unchanged`, `failed` or
     *         `skipped`. `skipped` means nothing was attempted at all — the
     *         calendar was not due, or somebody else was already refreshing
     *         it — and is the only outcome that writes no history row.
     */
    public static function refresh(\mysqli $db, int $feedId, string $trigger, ?int $byUserId): array
    {
        $started = microtime(true);
        $trigger = ($trigger === 'manual') ? 'manual' : 'schedule';

        // ⏱️ This work is allowed to take up to PER_FEED_SECONDS, and PHP's
        //    usual limit for a web request is thirty seconds. Going over it
        //    ends the request with a fatal that nothing can catch — in the
        //    middle of a transaction, which the database then rolls back, but
        //    with no history row and no message to say what happened.
        //
        //    Only raised when it is genuinely too low, and never lowered.
        //    That matters: `set_time_limit()` RESTARTS the count, so calling
        //    it once per calendar inside a loop would mean PHP's limit never
        //    fired at all. The scheduled job sets a bigger limit for the whole
        //    job before it starts, so this line does nothing there. A limit of
        //    zero means "no limit", which is what a command line gives.
        $currentLimit = (int) ini_get('max_execution_time');
        $needed       = self::PER_FEED_SECONDS + 20;
        if ($currentLimit !== 0 && $currentLimit < $needed) {
            @set_time_limit($needed);
        }

        $feed = self::feedRow($db, $feedId);
        if ($feed === null) {
            return self::answer('skipped', 'That calendar no longer exists.');
        }
        $siteId = (int) $feed['siteID'];

        // 🖐️ A manual refresh too soon after the last attempt is refused.
        //    Without this, holding down a Refresh button would ask somebody
        //    else's server for the same file as fast as the browser could
        //    send requests — which is how a portal gets itself blocked.
        if ($trigger === 'manual' && self::refreshedVeryRecently($db, $feedId) === true) {
            return self::answer(
                'skipped',
                'This calendar was refreshed a moment ago. Please wait a minute before trying again.'
            );
        }

        if (self::claim($db, $feedId, $trigger) === false) {
            return self::answer(
                'skipped',
                'This calendar is not due to be refreshed yet, is switched off, or is being refreshed right now.'
            );
        }

        // 🕐 "Now", as the DATABASE sees it. Everything this refresh writes
        //    and compares uses this one value, so no part of it can disagree
        //    with another about when it happened. Taken from the database and
        //    not from PHP because the two clocks can differ by seconds, and
        //    the removal rule compares a value PHP writes against a value the
        //    database wrote — a few seconds the wrong way round there would
        //    mean an event the refresh had just seen looking unseen.
        $runStart = self::databaseNowUtc($db);

        try {
            return self::afterClaim($db, $feed, $trigger, $byUserId, $started, $runStart);
        } catch (\Throwable $problem) {
            // 🧯 Anything at all. Nothing gets out of this method.
            self::rollBackQuietly($db);

            // The stored message names the KIND of fault and nothing else.
            // An exception's own message can carry a piece of the calendar
            // file, a column value or the address — and this message is
            // stored, shown on a page and read by whoever is helping.
            $message = 'The refresh could not be finished (' . self::shortClassName($problem)
                . '). No events were changed.';
            try {
                self::recordFailure($db, $feed, $runStart, $message, null, 0, $trigger, $byUserId);
            } catch (\Throwable $ignored) {
                // Recording the failure failed too. There is nothing sensible
                // left to try, and letting this out would stop a scheduled job
                // that still has other calendars to do. The lease is released
                // below on its own, so the calendar is not stuck either way.
                self::releaseLeaseQuietly($db, $feedId);
            }

            // The full detail goes to the platform error log, which only
            // administrators see. The file name and line number cannot carry
            // anything from the calendar, so they are safe to record and they
            // are what makes the fault findable.
            self::logProblem($siteId, $feedId, $problem);

            return ['outcome' => 'failed', 'message' => $message, 'newPending' => 0];
        }
    }

    /**
     * Everything after the calendar has been claimed.
     *
     * Split out so that `refresh()` above can wrap the whole of it in one
     * place and be sure nothing escapes. Every failure inside here records
     * its own history row and leaves the calendar ready for the next attempt.
     *
     * @param array<string,mixed> $feed
     *
     * @return array{outcome:string, message:string, newPending:int}
     */
    private static function afterClaim(
        \mysqli $db,
        array $feed,
        string $trigger,
        ?int $byUserId,
        float $started,
        string $runStart
    ): array {
        $feedId   = (int) $feed['feedID'];
        $siteId   = (int) $feed['siteID'];
        $deadline = $started + self::PER_FEED_SECONDS;

        // ---------------------------------------------------------------------
        // 2️⃣ Download it
        // ---------------------------------------------------------------------
        $url   = (string) $feed['url'];
        $check = SafeFetch::check($url);
        if ($check['ok'] !== true) {
            $refusal = ($check['message'] !== '') ? $check['message'] : SafeFetch::REFUSED_MESSAGE;

            return self::recordFailure($db, $feed, $runStart, $refusal, null, 0, $trigger, $byUserId);
        }

        $got = SafeFetch::get($url, [
            'maxBytes'       => self::MAX_BYTES,
            'timeoutSeconds' => self::FETCH_TIMEOUT_SECONDS,
            'accept'         => self::ACCEPT_HEADER,
            'deadline'       => $deadline,
        ]);
        $httpStatus = ((int) $got['status'] > 0) ? (int) $got['status'] : null;
        if ($got['ok'] !== true) {
            return self::recordFailure(
                $db,
                $feed,
                $runStart,
                (string) $got['message'],
                $httpStatus,
                (int) $got['bytes'],
                $trigger,
                $byUserId
            );
        }

        $body        = (string) $got['body'];
        $bytes       = (int) $got['bytes'];
        $contentHash = hash('sha256', $body);

        // ---------------------------------------------------------------------
        // 3️⃣ The very same file as last time?
        // ---------------------------------------------------------------------
        // Only trusted while the last successful refresh is recent — see
        // UNCHANGED_SKIP_HOURS for why the window a calendar is kept for
        // moving every day makes "identical file" and "nothing to do"
        // different things.
        if (self::nothingHasChanged($db, $feed, $contentHash) === true) {
            return self::recordUnchanged($db, $feed, $runStart, $httpStatus, $bytes, $trigger, $byUserId);
        }

        // ---------------------------------------------------------------------
        // 4️⃣ Read it
        // ---------------------------------------------------------------------
        $zone        = self::zoneFor($db, $feed);
        $todayLocal  = (new DateTimeImmutable('now', $zone))->setTime(0, 0, 0);
        [$windowStart, $windowEnd] = self::windowFor($todayLocal);

        try {
            $parsed = IcsReader::parse($body, $deadline);
            if ($parsed['complete'] !== true) {
                // The file was cut short, or held more than the reader takes
                // from one file. Whatever arrived may be perfectly good, but
                // it is not the whole calendar, so nothing may be removed on
                // the strength of it — and the simplest way to guarantee that
                // is to stop here without touching a single event.
                return self::recordFailure(
                    $db,
                    $feed,
                    $runStart,
                    'The download was incomplete. Earlier events were kept.',
                    $httpStatus,
                    $bytes,
                    $trigger,
                    $byUserId
                );
            }

            $expanded = IcsReader::expand(
                $parsed,
                $zone,
                $zone,
                $windowStart,
                $windowEnd,
                $deadline,
                self::perFeedLimit($siteId)
            );
        } catch (\RuntimeException $tooLong) {
            $message = ($tooLong->getMessage() === 'time budget')
                ? 'Processing this calendar took too long, so it was stopped. Earlier events were kept.'
                : 'The calendar file could not be read. Earlier events were kept.';

            return self::recordFailure($db, $feed, $runStart, $message, $httpStatus, $bytes, $trigger, $byUserId);
        }

        $occurrences = $expanded['occurrences'];
        $warnings    = array_merge($parsed['warnings'], $expanded['warnings']);

        // 🚦 `capped` is read FIRST, before the end point, before the dates,
        //    before anything. See this class's own header for why: a capped
        //    read with no end point covers nothing reliably, and treating it
        //    as a complete picture of the calendar is how real events get
        //    removed.
        $capped      = ($expanded['capped'] === true);

        // `removalEndPoint()` hands back BOTH how far the download may be
        // trusted and whether that far point is itself trustworthy. The two
        // travel together because separating them is what let an earlier
        // version delete a real event: the end point of a capped read is only
        // "the last date the reader managed to keep", so anything else
        // starting at that same moment may have been cut off rather than
        // taken out of the calendar. See `removalEndPoint()` for the measured
        // case and what the fix costs.
        $removal          = self::removalEndPoint($capped, $expanded['effectiveWindowEnd'], $windowEnd);
        $removalEnd       = $removal['point'];
        $removalEndExact  = $removal['exact'];

        // ---------------------------------------------------------------------
        // 5️⃣ From here on, one transaction, holding the calendar's own lock
        // ---------------------------------------------------------------------
        $db->begin_transaction();

        $locked = self::lockFeed($db, $feedId);
        if ($locked === null) {
            // The calendar was deleted while this refresh was downloading.
            // Nothing is written, and NO history row either: the history
            // belongs to the calendar and the calendar is gone, so writing one
            // would be refused by the database anyway.
            $db->rollback();

            return self::answer('failed', 'The calendar was switched off or deleted during the refresh.');
        }
        if ((int) $locked['isActive'] !== 1) {
            $db->rollback();

            return self::recordFailure(
                $db,
                $feed,
                $runStart,
                'The calendar was switched off or deleted during the refresh.',
                $httpStatus,
                $bytes,
                $trigger,
                $byUserId
            );
        }

        // ---------------------------------------------------------------------
        // 6️⃣ Write the events
        // ---------------------------------------------------------------------
        $counts = self::writeOccurrences($db, $siteId, $feedId, $occurrences, $zone, $runStart);

        // ---------------------------------------------------------------------
        // 7️⃣ Remove what the calendar no longer has — carefully
        // ---------------------------------------------------------------------
        $rowsRemoved = 0;
        $emptyKept   = 0;
        if (count($occurrences) === 0) {
            // An empty calendar is a real thing — a diary with nothing in it
            // this year. It is also exactly what a broken export looks like.
            // There is no way to tell them apart from the file, so the safe
            // reading is taken: nothing is removed, and the administrator is
            // told plainly how many events were kept so they can decide.
            $emptyKept = self::liveEventCount($db, $feedId);
        } elseif ($removalEnd !== null) {
            $rowsRemoved = self::removeMissing(
                $db,
                $feedId,
                $windowStart->format(self::SQL_MOMENT),
                $runStart,
                $removalEnd,
                $removalEndExact
            );
        }

        // ---------------------------------------------------------------------
        // 8️⃣ Work out who may see each event
        // ---------------------------------------------------------------------
        $resolved = FeedResolver::resolveFeed(
            $db,
            $feedId,
            new DateTimeImmutable($runStart, new DateTimeZone('UTC'))
        );

        // ---------------------------------------------------------------------
        // 9️⃣ Record what happened, and commit
        // ---------------------------------------------------------------------
        $outcome = ($capped === true) ? 'partial' : 'ok';
        if (count($occurrences) === 0) {
            $base = 'The calendar is empty; ' . $emptyKept . ' earlier events were kept.';
        } else {
            $base = 'Refreshed: ' . count($occurrences) . ' events ('
                . $counts['added'] . ' added, ' . $rowsRemoved . ' removed).';
            if ($capped === true) {
                // The sentence has to match what the refresh actually did. It
                // used to say "so nothing was removed" for EVERY capped run,
                // and that was untrue half the time: a capped read that has an
                // end point DOES remove rows before that point, so the
                // administrator could be shown "(0 added, 1 removed). Nothing
                // was removed." in one breath. Now there are two sentences,
                // one for each of the two things that really happen.
                //
                // WHY THE SECOND SENTENCE SAYS "checked against it" AND NOT
                // "nothing later was removed", which was the first wording
                // tried and is very slightly untrue. The removal step also
                // takes a row left behind by the OLD #327 job that has no
                // identity at all, whatever its date, because such a row can
                // never be matched to anything in any calendar. That is rare
                // — it can only be a leftover migration 205 could not carry
                // forward — and it is not worth a second sentence in a
                // message the column cuts at 500 characters. But a promise
                // the code does not keep is worse than no promise, so the
                // sentence says which events were compared with the calendar
                // and claims nothing beyond that.
                //
                // AND WHY IT SAYS "BEFORE" RATHER THAN "UP TO", which is one
                // word and was changed on 23 September 2026 after a second
                // round of checking. On a capped read the comparison really is
                // "strictly before" (see `removeMissing()`), so an event
                // starting AT the moment named was NOT checked — and most
                // people read "up to five o'clock" as including five o'clock.
                // This is the second time this one sentence has been reworded
                // for being subtly untrue, which is why it is worth a note.
                if ($removalEnd === null) {
                    $base .= ' Not all of this calendar was read, so nothing was removed.';
                } else {
                    $base .= ' Not all of this calendar was read, so only events before '
                        . self::plainMoment($removalEnd)
                        . ' were checked against it.';
                }
            }
        }
        $message = self::composeMessage($base, $warnings);

        self::stampSuccess($db, $feedId, $message, count($occurrences), $contentHash, $capped);
        self::recordRun($db, [
            'feedID'           => $feedId,
            'siteID'           => $siteId,
            'startedAt'        => $runStart,
            'outcome'          => $outcome,
            'httpStatus'       => $httpStatus,
            'message'          => $message,
            'bytes'            => $bytes,
            'eventsSeen'       => count($occurrences),
            'rowsAdded'        => $counts['added'],
            'rowsUpdated'      => $counts['updated'],
            'rowsRemoved'      => $rowsRemoved,
            'rowsSkipped'      => $counts['skipped'],
            // How many dates of this calendar are waiting for an administrator
            // AFTER this run worked the answers out — every one, not only the
            // new ones (#514 plan A6). A run that changed nothing, or failed,
            // works nothing out and writes 0 (`recordUnchanged()`,
            // `recordFailure()`), so a page showing "waiting" must count the
            // approval rows themselves rather than read the last run.
            'awaitingApproval' => $resolved['pendingTotal'],
            'triggeredBy'      => $trigger,
            'triggeredByID'    => $byUserId,
        ]);
        self::pruneRuns($db, $feedId);

        $db->commit();

        return ['outcome' => $outcome, 'message' => $message, 'newPending' => $resolved['newPending']];
    }

    // =========================================================================
    // 🗑️ deleteFeed() — remove a calendar and everything it brought in
    // =========================================================================

    /**
     * Delete a calendar, its events, and what hangs off them.
     *
     * All in one transaction, holding the calendar's own row lock, so a
     * refresh cannot be half way through writing events while this runs.
     *
     * Two things are deleted by name rather than left to the database:
     *
     *   * The answers people gave ("I am going") live in `tblEventRSVPs`,
     *     which has no foreign key to `tblEvents` — so nothing would remove
     *     them on its own, and they would be left pointing at event numbers
     *     that no longer exist. They are deleted first, while the events they
     *     point at are still there to join to.
     *   * The neutral series rows the importer made. They are recognised by
     *     the `imp-` address the importer gives them AND by being one of this
     *     calendar's own events' series, so a series somebody made by hand can
     *     never be caught by this even if they happened to name it that way.
     *
     * @return bool True when a calendar of that organisation was deleted.
     */
    public static function deleteFeed(\mysqli $db, int $feedId, int $siteId): bool
    {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'SELECT feedID FROM tblExternalFeeds WHERE feedID = ? AND siteID = ? FOR UPDATE'
            );
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($found === null) {
                $db->rollback();

                return false;
            }

            // Which series rows this calendar's events belong to, noted before
            // the events go — afterwards there would be nothing left to ask.
            $seriesIds = [];
            $stmt      = $db->prepare(
                'SELECT DISTINCT seriesID FROM tblEvents WHERE externalFeedID = ? AND siteID = ? AND seriesID IS NOT NULL'
            );
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($row = $result->fetch_assoc()) !== null) {
                $seriesIds[] = (int) $row['seriesID'];
            }
            $stmt->close();

            $stmt = $db->prepare(
                'DELETE r FROM tblEventRSVPs r JOIN tblEvents e ON e.eventID = r.eventID '
                . 'WHERE e.externalFeedID = ? AND e.siteID = ?'
            );
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare('DELETE FROM tblEvents WHERE externalFeedID = ? AND siteID = ?');
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $stmt->close();

            if ($seriesIds !== []) {
                // Built with one `?` for each number, all of them whole numbers
                // this method read out of the database itself a moment ago.
                $marks = implode(',', array_fill(0, count($seriesIds), '?'));
                $stmt  = $db->prepare(
                    'DELETE FROM tblEventSeries WHERE seriesID IN (' . $marks . ') '
                    . "AND siteID = ? AND seriesSlug LIKE 'imp-%'"
                );
                $values = $seriesIds;
                $values[] = $siteId;
                $stmt->bind_param(str_repeat('i', count($values)), ...$values);
                $stmt->execute();
                $stmt->close();
            }

            // The calendar itself. Its category map, its audience lists and
            // its history all have foreign keys that cascade, so they go with
            // it without being named here.
            $stmt = $db->prepare('DELETE FROM tblExternalFeeds WHERE feedID = ? AND siteID = ?');
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $stmt->close();

            $db->commit();

            return true;
        } catch (\Throwable $problem) {
            self::rollBackQuietly($db);
            self::logProblem($siteId, $feedId, $problem);

            return false;
        }
    }

    // =========================================================================
    // ⏸️ setActive() — pause or resume a calendar
    // =========================================================================

    /**
     * Switch a calendar on or off.
     *
     * Pausing hides every one of its events at once, and resuming brings them
     * all back, WITHOUT a refresh. That is because the visibility rule tests
     * whether the calendar is switched on as it goes, rather than copying a
     * flag onto each event — so there is nothing to rewrite, and nothing that
     * can be left half rewritten (#514 decision D3).
     *
     * Resuming also makes the calendar due straight away, so the next
     * scheduled run picks it up rather than waiting out whatever gap was left
     * over from before it was paused.
     *
     * @return bool True when a calendar of that organisation was changed.
     */
    public static function setActive(\mysqli $db, int $feedId, int $siteId, bool $on): bool
    {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'SELECT feedID FROM tblExternalFeeds WHERE feedID = ? AND siteID = ? FOR UPDATE'
            );
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($found === null) {
                $db->rollback();

                return false;
            }

            if ($on === true) {
                $stmt = $db->prepare(
                    'UPDATE tblExternalFeeds SET isActive = 1, nextFetchAt = UTC_TIMESTAMP() '
                    . 'WHERE feedID = ? AND siteID = ?'
                );
            } else {
                // The lease is cleared as well. A calendar switched off part
                // way through a refresh would otherwise stay "being refreshed"
                // for up to ten minutes after it was switched back on.
                $stmt = $db->prepare(
                    'UPDATE tblExternalFeeds SET isActive = 0, refreshLeaseUntil = NULL '
                    . 'WHERE feedID = ? AND siteID = ?'
                );
            }
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $stmt->close();

            $db->commit();

            return true;
        } catch (\Throwable $problem) {
            self::rollBackQuietly($db);
            self::logProblem($siteId, $feedId, $problem);

            return false;
        }
    }

    // =========================================================================
    // ⏰ recheckDue() — the scheduled job's recheck pass (#514 part P7)
    // =========================================================================

    /**
     * Work out again who may see the events of every switched-on calendar
     * that holds a live event whose stored answer has RUN OUT
     * (`importRecheckAt` has passed).
     *
     * WHY THIS EXISTS. A choice or a rule can apply between two dates. Its
     * start and end are written onto each event it could affect, as
     * `importRecheckAt`; past that moment the visibility rule stops trusting
     * the stored answer and only administrators see the event (closed, never
     * open). This pass is what works the answer out again, so a window opens
     * and closes on the right day — even for a calendar whose download then
     * fails, because a failed refresh never reaches the resolver.
     *
     * HOW. One statement finds the calendars (`recheckQueue()`, the oldest
     * expired answer first). Each is then worked out in its OWN transaction,
     * holding the calendar's own lock, exactly as a refresh does, with the
     * database's clock (`FeedResolver::databaseNowUtc()`). A calendar that
     * has gone, or was switched off meanwhile, is skipped: its events are
     * hidden from everyone anyway, and resuming it makes it due for a
     * refresh straight away.
     *
     * NEVER throws — the same promise as `refresh()`, for the same reason: a
     * scheduled job that stopped on the first awkward calendar would silently
     * stop the others. A calendar that fails is rolled back, logged as the
     * RECHECK's own problem (`FeedRecheckFailed`, "Re-checking who may see
     * calendar #N"), counted, and the pass carries on.
     *
     * WHAT IT CANNOT DO: it stops STARTING calendars at `$deadline`. Any left
     * are counted as not started; their answers stay expired, so their events
     * stay administrators-only until the next run a few minutes later. That
     * is the closed direction, and it is why the job gives this pass at most
     * half of its time: a night when many windows end at midnight must not
     * use up the time the refreshes need.
     *
     * @param \mysqli $db       The connection.
     * @param float   $deadline A `microtime(true)` moment after which no
     *                          further calendar is started.
     *
     * @return array{due:int, reworked:int, problems:int, notStarted:int, newPending:int}
     */
    public static function recheckDue(\mysqli $db, float $deadline): array
    {
        $counts = ['due' => 0, 'reworked' => 0, 'problems' => 0, 'notStarted' => 0, 'newPending' => 0];

        try {
            $queue = self::recheckQueue($db);
        } catch (\Throwable $problem) {
            // Not even the list could be read. Nothing was changed, and the
            // expired answers stay expired, which is the closed direction.
            $counts['problems']++;
            try {
                Logger::errorPlatformForSite(
                    null,
                    'FeedImport',
                    'Error',
                    'FeedRecheckFailed',
                    'Re-checking who may see imported events could not start: ' . get_class($problem)
                    . ' at ' . basename($problem->getFile()) . ':' . $problem->getLine() . '.',
                    $problem->getFile() . ':' . $problem->getLine()
                );
            } catch (\Throwable $ignored) {
                // Recording the problem is not worth causing another one.
            }

            return $counts;
        }

        $counts['due'] = count($queue);
        foreach ($queue as $position => $item) {
            if (microtime(true) >= $deadline) {
                $counts['notStarted'] = count($queue) - $position;
                break;
            }
            $feedId = (int) $item['feedID'];
            $siteId = (int) $item['siteID'];
            try {
                $db->begin_transaction();
                $locked = self::lockFeed($db, $feedId);
                if ($locked === null || (int) $locked['isActive'] !== 1) {
                    $db->rollback();
                    continue;
                }
                $resolved = FeedResolver::resolveFeed($db, $feedId, FeedResolver::databaseNowUtc($db));
                $db->commit();
                $counts['reworked']++;
                $counts['newPending'] += (int) $resolved['newPending'];
            } catch (\Throwable $problem) {
                self::rollBackQuietly($db);
                $counts['problems']++;
                self::logProblem($siteId, $feedId, $problem, 'FeedRecheckFailed', 'Re-checking who may see');
            }
        }

        return $counts;
    }

    /**
     * The calendars whose stored answers have run out, the one whose answer
     * ran out FIRST at the top.
     *
     * Oldest expired first, not by calendar number (challenge finding 13):
     * with the half-budget cap, numbering order could hold back the same
     * high-numbered calendars run after run. The calendar must still exist,
     * belong to the event's own organisation and be switched on — the same
     * three things the visibility rule tests — so the pass never works on a
     * row the rule would refuse anyway. `idx_event_import_recheck` (migration
     * 204) serves the search. A statement of its own so the self-test can
     * check its order.
     *
     * @return list<array{feedID:int, siteID:int, oldestExpired:string}>
     */
    private static function recheckQueue(\mysqli $db): array
    {
        $queue  = [];
        $result = $db->query(
            'SELECT e.externalFeedID AS feedID, f.siteID, MIN(e.importRecheckAt) AS oldestExpired '
            . 'FROM tblEvents e '
            . 'JOIN tblExternalFeeds f ON f.feedID = e.externalFeedID AND f.siteID = e.siteID AND f.isActive = 1 '
            . 'WHERE e.isDeleted = 0 AND e.importRecheckAt IS NOT NULL AND e.importRecheckAt <= UTC_TIMESTAMP() '
            . 'GROUP BY e.externalFeedID, f.siteID '
            . 'ORDER BY oldestExpired, e.externalFeedID'
        );
        if ($result === false || $result === true) {
            throw new \RuntimeException('FeedImporter: the recheck list could not be read.');
        }
        while (($row = $result->fetch_assoc()) !== null) {
            $queue[] = [
                'feedID'        => (int) $row['feedID'],
                'siteID'        => (int) $row['siteID'],
                'oldestExpired' => (string) $row['oldestExpired'],
            ];
        }
        $result->free();

        return $queue;
    }

    // =========================================================================
    // 🧱 Claiming, timing and settings
    // =========================================================================

    /**
     * The calendar's own row, without a lock.
     *
     * @return array<string,mixed>|null
     */
    private static function feedRow(\mysqli $db, int $feedId): ?array
    {
        $stmt = $db->prepare(
            'SELECT feedID, siteID, url, fetchEveryMins, timezone, isActive, '
            . 'consecutiveFailures, lastContentHash, lastCompleteAt '
            . 'FROM tblExternalFeeds WHERE feedID = ? LIMIT 1'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : $row;
    }

    /**
     * The calendar's row, locked for the rest of this transaction.
     *
     * Every writer takes this same lock first — refresh, pause, resume,
     * delete — so no two of them can ever be inside the calendar at once.
     *
     * @return array<string,mixed>|null
     */
    private static function lockFeed(\mysqli $db, int $feedId): ?array
    {
        $stmt = $db->prepare(
            'SELECT feedID, siteID, isActive, fetchEveryMins FROM tblExternalFeeds WHERE feedID = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : $row;
    }

    /**
     * Claim the calendar for this refresh.
     *
     * One statement does three things that have to happen together, or two
     * runs started at the same moment would both think they had it:
     *   * refuse unless the calendar is switched on;
     *   * refuse unless any previous claim has run out;
     *   * refuse unless the calendar is due — except for a manual refresh,
     *     which is somebody deliberately asking.
     *
     * `affected_rows === 1` is what says it worked. It is a safe test here,
     * and it is worth saying why, because MySQL reports 0 for a row that
     * matched but whose values did not change: the claim always writes a NEW
     * end time, and the condition it matched on insists the OLD end time is
     * already in the past. A value that is ten minutes in the future cannot
     * also be in the past, so a matched row is always a changed row.
     */
    private static function claim(\mysqli $db, int $feedId, string $trigger): bool
    {
        $stmt = $db->prepare(
            'UPDATE tblExternalFeeds '
            . 'SET refreshLeaseUntil = UTC_TIMESTAMP() + INTERVAL ? MINUTE, '
            . '    nextFetchAt = UTC_TIMESTAMP() + INTERVAL GREATEST(fetchEveryMins, ?) MINUTE '
            . 'WHERE feedID = ? AND isActive = 1 '
            . '  AND (refreshLeaseUntil IS NULL OR refreshLeaseUntil < UTC_TIMESTAMP()) '
            . "  AND (? = 'manual' OR nextFetchAt IS NULL OR nextFetchAt <= UTC_TIMESTAMP())"
        );
        $lease    = self::LEASE_MINUTES;
        $minEvery = self::MIN_FETCH_MINUTES;
        $stmt->bind_param('iiis', $lease, $minEvery, $feedId, $trigger);
        $stmt->execute();
        $claimed = ($stmt->affected_rows === 1);
        $stmt->close();

        return $claimed;
    }

    /** Was this calendar attempted within the last MANUAL_MIN_SECONDS? */
    private static function refreshedVeryRecently(\mysqli $db, int $feedId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM tblExternalFeedRuns WHERE feedID = ? '
            . 'AND startedAt > (UTC_TIMESTAMP() - INTERVAL ? SECOND) LIMIT 1'
        );
        $seconds = self::MANUAL_MIN_SECONDS;
        $stmt->bind_param('ii', $feedId, $seconds);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found !== null;
    }

    /** The database's own "now", in UTC, as `Y-m-d H:i:s`. */
    private static function databaseNowUtc(\mysqli $db): string
    {
        $result = $db->query('SELECT UTC_TIMESTAMP() AS nowUtc');
        $row    = ($result === false) ? null : $result->fetch_assoc();

        return (string) ($row['nowUtc'] ?? gmdate(self::SQL_MOMENT));
    }

    /**
     * Is this the same file as last time, recently enough to skip the work?
     *
     * @param array<string,mixed> $feed
     */
    private static function nothingHasChanged(\mysqli $db, array $feed, string $contentHash): bool
    {
        $lastHash = (string) ($feed['lastContentHash'] ?? '');
        if ($lastHash === '' || hash_equals($lastHash, $contentHash) === false) {
            return false;
        }
        $lastComplete = $feed['lastCompleteAt'] ?? null;
        if ($lastComplete === null || $lastComplete === '') {
            // The file matches a fingerprint left by a refresh that never
            // finished. Skipping on the strength of that would leave the
            // calendar half imported for ever.
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1 WHERE CAST(? AS DATETIME) > (UTC_TIMESTAMP() - INTERVAL ? HOUR)'
        );
        $hours = self::UNCHANGED_SKIP_HOURS;
        $last  = (string) $lastComplete;
        $stmt->bind_param('si', $last, $hours);
        $stmt->execute();
        $recent = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $recent !== null;
    }

    /**
     * The zone this calendar's times are stored in, and read in.
     *
     *   1. The calendar's own zone, when an administrator has set one AND the
     *      system recognises it — a partner diary kept in another country is
     *      easier to read in that country's own time.
     *   2. UTC, when one is set but NOT recognised. A mistyped zone must not
     *      stop a calendar importing at all — and it must not quietly move
     *      the calendar's events into the organisation's zone either, which
     *      would shift where every one of them lands; so the plain,
     *      predictable answer, exactly as before part P7.
     *   3. The organisation's own zone when the calendar has none, through
     *      `FeedResolver::organisationZone()` (#514 part P7) — the SAME
     *      helper date windows are counted in, so the zone events are stored
     *      in and the zone windows are counted in can never be two different
     *      ideas.
     *
     * @param array<string,mixed> $feed
     */
    private static function zoneFor(\mysqli $db, array $feed): DateTimeZone
    {
        unset($db);

        $named = trim((string) ($feed['timezone'] ?? ''));
        if ($named === '') {
            return FeedResolver::organisationZone((int) $feed['siteID']);
        }
        try {
            return new DateTimeZone($named);
        } catch (\Throwable $unknown) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * The period of a calendar the portal keeps: thirty days back, twelve
     * months on.
     *
     * DAY AND MONTH ARITHMETIC, NEVER A NUMBER OF SECONDS. Thirty days is
     * thirty CALENDAR days. On the two nights a year the clocks change, one
     * of those days is twenty-three hours long and one is twenty-five, so
     * 30 × 86,400 seconds lands an hour out and quietly shifts the whole
     * period — including the point before which events are removed. PHP's
     * `modify('-30 days')` counts days on the clock, which is what is wanted.
     *
     * `setTime()` is applied AFTER the day arithmetic, not only before it.
     * In a few zones the clocks go forward AT midnight (Chile and Lebanon
     * have both done this), so the target day has no midnight at all and PHP
     * lands on 01:00. Asking for the start of the day again settles on the
     * earliest moment that day really has, rather than leaving an hour of it
     * outside the period.
     *
     * What this CANNOT do: twelve months from 29 February is 1 March, because
     * there is no 29 February in most years. That is PHP's month arithmetic
     * and it is the ordinary answer; the period is a day short once every
     * four years and nothing depends on its exact end.
     *
     * Its own method so a test can ask for the period on any day of the year,
     * including both clock-change nights in both directions, without having
     * to change the machine's clock.
     *
     * @param DateTimeImmutable $todayLocal The start of today, in the zone
     *                                      this calendar is stored in.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function windowFor(DateTimeImmutable $todayLocal): array
    {
        return [
            $todayLocal->modify('-' . self::DAYS_BACK . ' days')->setTime(0, 0, 0),
            $todayLocal->modify('+' . self::MONTHS_AHEAD . ' months')->setTime(23, 59, 59),
        ];
    }

    /**
     * How many dates this organisation takes from ONE calendar.
     *
     * Null means "whatever the reader's own number is". The number is NOT
     * repeated here: it lives once, in `IcsReader::MAX_EVENTS_PER_FEED`, and
     * an empty setting means that one. The reader also refuses a number
     * outside what it can do and says so in the calendar's warnings, so a
     * typing mistake is surfaced to the administrator rather than checked for
     * a second time here — two places deciding what a bad number means is how
     * they come to disagree.
     */
    private static function perFeedLimit(int $siteId): ?int
    {
        $raw = App::settingForSite('feeds.maxEventsPerFeed', $siteId);
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return (int) $raw;
    }

    // =========================================================================
    // ✍️ Writing the events
    // =========================================================================

    /**
     * Write every date the reader produced, and the words on it.
     *
     * @param list<array<string,mixed>> $occurrences
     *
     * @return array{added:int, updated:int, skipped:int}
     */
    private static function writeOccurrences(
        \mysqli $db,
        int $siteId,
        int $feedId,
        array $occurrences,
        DateTimeZone $zone,
        string $runStart
    ): array {
        if ($occurrences === []) {
            return ['added' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $existing    = self::existingRows($db, $feedId);
        $zoneName    = $zone->getName();
        $seriesCache = [];
        $added       = 0;
        $updated     = 0;
        $skipped     = 0;

        $update = $db->prepare(
            'UPDATE tblEvents SET eventName = ?, description = ?, startDateTime = ?, endDateTime = ?, '
            . 'timezone = ?, eventTimezone = ?, isAllDay = ?, locationName = ?, externalUrl = ?, '
            . 'status = ?, externalPrivate = ?, externalDuplicate = ?, externalLastSeenAt = ?, '
            . 'externalUid = ?, seriesID = ?, isPublic = 0, isDeleted = 0, deletedAt = NULL '
            . 'WHERE eventID = ?'
        );
        $insert = $db->prepare(
            'INSERT INTO tblEvents (siteID, externalFeedID, externalUidHash, externalRecurrenceKey, '
            . 'eventSlug, eventName, description, startDateTime, endDateTime, timezone, eventTimezone, '
            . 'isAllDay, locationName, externalUrl, status, externalPrivate, externalDuplicate, '
            . 'externalLastSeenAt, externalUid, seriesID, isPublic, isDeleted, importLevel, '
            . 'importDetail, importApiOptOut, registrationEnabled) '
            . "VALUES (?, ?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'hidden', 'basic', 1, 0)"
        );
        // 🔒 A brand-new row is written NARROW — hidden, title-only, and not
        //    for API keys (`importApiOptOut = 1`, #514 part P7) — and
        //    `FeedResolver` writes the real answer before this transaction
        //    commits. Named explicitly rather than left to the column
        //    defaults (which are the same), so the narrow start does not
        //    depend on a default somebody might one day change.
        $clearTags = $db->prepare('DELETE FROM tblExternalEventTags WHERE eventID = ?');
        $addTag    = $db->prepare('INSERT INTO tblExternalEventTags (eventID, tag) VALUES (?, ?)');

        foreach ($occurrences as $occurrence) {
            $uidHex = strtolower(bin2hex((string) $occurrence['uidHash']));
            $key    = (string) $occurrence['recurrenceKey'];

            $name        = self::forColumn((string) $occurrence['title'], 255);
            $description = self::forColumn((string) $occurrence['description'], 5000);
            $location    = self::forColumn((string) $occurrence['location'], 255);
            $url         = ($occurrence['url'] === null) ? null : self::forColumn((string) $occurrence['url'], 500);
            $uid         = self::forColumn((string) $occurrence['uid'], 255);

            // 🕰️ The start and the end are wall-clock readings in the zone
            //    above, exactly as the reader wrote them. They are checked
            //    rather than trusted, because a database `DATETIME` stops at
            //    the end of the year 9999 and a calendar file may legally
            //    claim a date past it. The reader already brings an END back
            //    to that point; a START it cannot, so it is checked here. An
            //    unusable one is skipped rather than guessed at.
            $start = (string) $occurrence['start'];
            $end   = (string) $occurrence['end'];
            if (self::isStorableMoment($start) === false || self::isStorableMoment($end) === false) {
                $skipped++;
                continue;
            }

            // An end that sorts BEFORE its start is NOT repaired and NOT
            // refused. On the night the clocks go back, 01:30 followed by
            // 01:15 is forty-five real minutes; wall-clock text cannot say so.
            // Anything that "fixed" this would be wrong twice a year.

            $isAllDay  = ((bool) $occurrence['isAllDay'] === true) ? 1 : 0;
            $private   = ((bool) $occurrence['private'] === true) ? 1 : 0;
            $duplicate = ((bool) $occurrence['duplicate'] === true) ? 1 : 0;
            $status    = ((bool) $occurrence['cancelled'] === true) ? 'cancelled' : 'published';

            $seriesId = null;
            if ((bool) $occurrence['isSeries'] === true) {
                $seriesId = self::seriesFor($db, $siteId, $feedId, $uidHex, $seriesCache);
            }

            $identity = $uidHex . '|' . $key;
            $eventId  = $existing[$identity] ?? null;

            if ($eventId !== null) {
                // Left out of this statement on purpose: `eventSlug`, so an
                // event that is renamed at the source keeps the web address
                // people have already shared; every `import*` column, which
                // belongs to `FeedResolver`; and `categoryID`, which the
                // resolver also owns.
                $update->bind_param(
                    'ssssssisssiissii',
                    $name,
                    $description,
                    $start,
                    $end,
                    $zoneName,
                    $zoneName,
                    $isAllDay,
                    $location,
                    $url,
                    $status,
                    $private,
                    $duplicate,
                    $runStart,
                    $uid,
                    $seriesId,
                    $eventId
                );
                $update->execute();
                $updated++;

                $clearTags->bind_param('i', $eventId);
                $clearTags->execute();
            } else {
                $slug = self::eventSlug($feedId, $uidHex, $key);
                $insert->bind_param(
                    'iisssssssssisssiissi',
                    $siteId,
                    $feedId,
                    $uidHex,
                    $key,
                    $slug,
                    $name,
                    $description,
                    $start,
                    $end,
                    $zoneName,
                    $zoneName,
                    $isAllDay,
                    $location,
                    $url,
                    $status,
                    $private,
                    $duplicate,
                    $runStart,
                    $uid,
                    $seriesId
                );
                try {
                    $insert->execute();
                    $eventId = (int) $db->insert_id;
                    $added++;
                } catch (\mysqli_sql_exception $clash) {
                    // 1062 is "that would be a second row with the same
                    // identity or the same web address". Only that one is
                    // swallowed: a deadlock or a lock that timed out (1213,
                    // 1205) has ALREADY rolled the whole transaction back, so
                    // carrying on after one would write the rest of the
                    // calendar into nothing.
                    if ((int) $clash->getCode() !== 1062) {
                        throw $clash;
                    }
                    $skipped++;
                    continue;
                }
            }

            // 🏷️ The category words. `tagsFor()` has already dropped exact
            //    repeats, but the database's idea of "the same word" is wider
            //    than PHP's and the gap between the two used to end the whole
            //    refresh. See the note below the loop.
            $tags = self::tagsFor($occurrence);
            foreach ($tags as $tag) {
                $addTag->bind_param('is', $eventId, $tag);
                try {
                    $addTag->execute();
                } catch (\mysqli_sql_exception $clash) {
                    // 1062 is "this event already has that word". Only that
                    // one is swallowed; anything else still ends the refresh,
                    // for the same reason as the event insert above (a
                    // deadlock or a lock that timed out has already rolled the
                    // transaction back, so carrying on would write the rest of
                    // the calendar into nothing).
                    //
                    // WHY THIS CAN HAPPEN AT ALL, which is not obvious and
                    // was measured by the first independent check of this
                    // part on 23 September 2026. `tagsFor()` compares words
                    // byte for byte. `tblExternalEventTags` compares them
                    // under `utf8mb4_general_ci`, where MySQL treats accented
                    // and plain letters as the same letter — `'café'` and
                    // `'cafe'` are equal, as are `'a'` and `'A'`. So one
                    // event carrying `CATEGORIES:Café,Cafe` gives two words
                    // PHP calls different and the database calls the same,
                    // and the second insert broke a unique key.
                    //
                    // The cost of not catching it was out of all proportion
                    // to the cause: the whole refresh ended with "The refresh
                    // could not be finished", NOT ONE of the calendar's ten
                    // events was imported, the failure count went up, the
                    // wait before the next try doubled — and it happened
                    // again on every refresh for as long as that one event
                    // stayed in the calendar.
                    //
                    // `INSERT IGNORE` would also have worked and was not
                    // used: it turns EVERY error on that statement into a
                    // warning, including a value too long for the column and
                    // a broken link to the event, so a real fault would
                    // disappear as quietly as this harmless one.
                    if ((int) $clash->getCode() !== 1062) {
                        throw $clash;
                    }
                }
            }
        }

        $update->close();
        $insert->close();
        $clearTags->close();
        $addTag->close();

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Every row this calendar already has, keyed by its identity.
     *
     * Soft-deleted rows are INCLUDED on purpose. An event that came back
     * after being removed then gets its own row back — the same event number,
     * with every answer anybody gave still attached to it — rather than a new
     * one with everything lost.
     *
     * A row whose identity is empty cannot be keyed and is left out. Those
     * are the rows the old #327 job left behind that migration 205 could not
     * bring forward; the removal step takes them.
     *
     * @return array<string,int>
     */
    private static function existingRows(\mysqli $db, int $feedId): array
    {
        $map  = [];
        $stmt = $db->prepare(
            'SELECT eventID, LOWER(HEX(externalUidHash)) AS uidHex, externalRecurrenceKey '
            . 'FROM tblEvents WHERE externalFeedID = ? AND externalUidHash IS NOT NULL'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $map[(string) $row['uidHex'] . '|' . (string) $row['externalRecurrenceKey']] = (int) $row['eventID'];
        }
        $stmt->close();

        return $map;
    }

    /**
     * The series row a repeating copied-in event belongs to, making it if
     * this is the first time it has been seen.
     *
     * Named neutrally, never after the outside calendar's own wording. A
     * series name is printed on administrator pages that do not check who may
     * see an imported event, so the outside title would leak from an event
     * that may be hidden.
     *
     * It is looked up by its address and organisation first, because that is
     * the pair the database itself insists is unique — asking the same
     * question the database asks means two refreshes racing each other end
     * with one row, not a duplicate-key error.
     *
     * @param array<string,int> $cache Filled in as we go, so one series is
     *                                 looked up once per refresh however many
     *                                 dates it has.
     */
    private static function seriesFor(
        \mysqli $db,
        int $siteId,
        int $feedId,
        string $uidHex,
        array &$cache
    ): ?int {
        if (isset($cache[$uidHex]) === true) {
            return $cache[$uidHex];
        }

        $slug = 'imp-' . substr(hash('sha256', $feedId . '|s|' . $uidHex), 0, 16);

        $stmt = $db->prepare('SELECT seriesID FROM tblEventSeries WHERE seriesSlug = ? AND siteID = ? LIMIT 1');
        $stmt->bind_param('si', $slug, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            $cache[$uidHex] = (int) $row['seriesID'];

            return $cache[$uidHex];
        }

        $name = FeedResolver::IMPORTED_SERIES_NAME;
        $stmt = $db->prepare(
            'INSERT INTO tblEventSeries (siteID, seriesName, seriesSlug, isActive) VALUES (?, ?, ?, 1)'
        );
        $stmt->bind_param('iss', $siteId, $name, $slug);
        try {
            $stmt->execute();
            $cache[$uidHex] = (int) $db->insert_id;
        } catch (\mysqli_sql_exception $clash) {
            if ((int) $clash->getCode() !== 1062) {
                $stmt->close();

                throw $clash;
            }
            // Somebody else made it between the look-up and the insert. Read
            // theirs rather than failing; the point was to end with one row.
            $stmt->close();
            $stmt = $db->prepare('SELECT seriesID FROM tblEventSeries WHERE seriesSlug = ? AND siteID = ? LIMIT 1');
            $stmt->bind_param('si', $slug, $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $cache[$uidHex] = ($row === null ? null : (int) $row['seriesID']);
        }
        $stmt->close();

        return $cache[$uidHex];
    }

    /**
     * The web address of one copied-in event.
     *
     * The SAME formula migration 205 uses to bring the old #327 rows forward.
     * If the two ever differ, the importer makes a SECOND row for an event it
     * already has, and the person who said "I am going" to the old one is
     * looking at a different event. Change one and you must change the other.
     *
     * Built from the identity (the SHA-256 of the whole UID) rather than from
     * the event's own title. Two reasons. A title can change, and an address
     * that changed with it would break every link anybody had shared. And an
     * outside title put into an address would leak the wording of an event
     * that may be hidden, because an address is not secret.
     */
    private static function eventSlug(int $feedId, string $uidHex, string $recurrenceKey): string
    {
        return 'imp-' . substr(hash('sha256', $feedId . '|' . $uidHex . '|' . $recurrenceKey), 0, 16);
    }

    /**
     * The category words on one date, ready to store.
     *
     * Lower-cased and de-duplicated, because the portal matches them without
     * regard to capital letters — and because the table refuses two rows with
     * the same word for the same event, so a calendar sending both "Youth"
     * and "youth" would otherwise cause a duplicate-key error.
     *
     * @param array<string,mixed> $occurrence
     *
     * @return list<string>
     */
    private static function tagsFor(array $occurrence): array
    {
        $out = [];
        foreach ((array) ($occurrence['categories'] ?? []) as $raw) {
            $tag = mb_strtolower(trim((string) $raw), 'UTF-8');
            if ($tag === '') {
                continue;
            }
            $tag = self::forColumn($tag, 100);
            if ($tag === '' || in_array($tag, $out, true) === true) {
                continue;
            }
            $out[] = $tag;
        }

        return $out;
    }

    /**
     * Text made safe to store: valid UTF-8, and no longer than the column.
     *
     * MySQL refuses bytes that are not valid UTF-8 with "Incorrect string
     * value", and one such value would end the whole refresh. Nothing about
     * an event's identity depends on this — that is the SHA-256 of the exact
     * bytes, taken by the reader before anything is shortened — so anything
     * unreadable is dropped here rather than risking the write.
     */
    private static function forColumn(string $value, int $maxChars): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = (string) preg_replace('/[^\x00-\x7F]/', '', $value);
        }
        if (mb_strlen($value, 'UTF-8') > $maxChars) {
            $value = mb_substr($value, 0, $maxChars, 'UTF-8');
        }

        return $value;
    }

    /**
     * Can this wall-clock reading be stored in a `DATETIME` column?
     *
     * A `DATETIME` runs from the year 1000 to the end of 9999. The reader
     * refuses a START outside years 1 to 9999 and brings an END back to the
     * last moment of 9999, so the awkward cases are already rare — but "rare"
     * is not "impossible", and a value MySQL refuses would end the refresh
     * for every other event in the file as well. Checked, not trusted.
     */
    private static function isStorableMoment(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }
        $year = (int) $parts[1];

        return ($year >= 1000 && $year <= 9999);
    }

    // =========================================================================
    // 🗑️ Removing what the calendar no longer has
    // =========================================================================

    /**
     * How far through the period this download may be trusted — and whether
     * that far point is itself trustworthy or only a rough marker.
     *
     * THIS IS THE MOST IMPORTANT DECISION IN THE CLASS. Read the header.
     *
     * Three cases:
     *   * Not capped. The reader read the whole period, so the end of the
     *     period is the honest end point, and it is EXACT: an event starting
     *     at that very moment really was covered by the download.
     *   * Capped, WITH an end point. The reader is saying "everything up to
     *     here was read properly, and I stopped after it". Events before that
     *     point may be judged; the point itself may NOT — see below.
     *   * Capped with NO end point. The reader's own notes define this as
     *     "covers nothing reliably" — it happens when a limit stopped the
     *     work while the events were still in file order, or when one event's
     *     list of skipped dates was cut short and the missing dates could be
     *     anywhere. Nothing is removed at all.
     *
     * That last case is STRICTER than the #514 plan's wording, and
     * deliberately so. The plan's removal statement has three parts, and only
     * the third is bounded by the end point; the other two (a row the old
     * #327 job left with no identity, and a row that has fallen out of the
     * back of the period) would still fire. Both are soft deletions and both
     * are probably right — but "probably right" is not the standard for the
     * one statement in #514 that removes a customer's events, and skipping
     * them costs nothing except that a few stale rows survive until a refresh
     * that CAN be trusted comes along.
     *
     * -------------------------------------------------------------------
     * WHY THE SECOND CASE IS NOW MARKED "NOT EXACT", AND WHAT WENT WRONG
     * BEFORE. Found by the first independent check of this part, 23 September
     * 2026, and it deleted a real event.
     * -------------------------------------------------------------------
     * When the per-calendar limit cuts the list, `IcsReader::expand()` reports
     * the end point as **the start of the last date it kept**. So the very
     * last date the reader kept sits exactly ON the end point — and so does
     * anything else in the calendar starting at that same moment, which the
     * limit may well have cut off.
     *
     * That is not a rare shape. Every whole-day event on one date has the same
     * start reading (`00:00:00`), and any two events at the same clock time
     * share theirs: a parish with three services at ten o'clock, a school with
     * four all-day term markers. Sorting puts them next to each other and the
     * cut lands in the middle of them.
     *
     * The measured fault: a calendar of four whole-day events (one on 5
     * October, three on 10 October) with the limit set to three. The reader
     * kept three of the four and reported the end point as
     * `2026-10-10 00:00:00`. The importer then judged every stored row at or
     * before that moment, could not tell "the limit cut this one off" from
     * "the calendar no longer has this one", and soft-deleted an event that
     * was still in the calendar — while the message it stored for the
     * administrator said nothing had been removed. It did not come back,
     * because the same file is treated as unchanged for twenty hours and is
     * then cut at the same place again.
     *
     * The fix is the `exact` flag this method now returns. On a capped read
     * the end point is a rough marker, not a proven boundary, so the removal
     * step compares with `<` and a row sitting exactly on it is left alone.
     *
     * THE OTHER SHAPE OF FIX, AND WHY IT WAS NOT TAKEN. The reader could
     * instead report the end point as strictly before the first date it did
     * NOT keep. Three reasons against. It means changing `IcsReader`, which
     * belongs to an earlier part and has been through nine rounds of
     * independent checking. It would only half fix the fault: the per-calendar
     * slice does know the first date it dropped, but the OTHER source of an
     * end point — a single repeating event that ran past its own limit —
     * reports only the last date it kept and never records the first one it
     * did not, so that half would still delete. And expressing "strictly
     * before" as a stored value means subtracting a second from a wall-clock
     * reading, which is arithmetic this project has already been bitten by
     * (wall-clock text is compared as text here precisely so that the two
     * nights a year when the clocks change do not break it). Comparing with
     * `<` says "strictly before" without touching the value at all.
     *
     * WHAT CHANGED THE DAY AFTER, and it changes the middle reason above
     * rather than the conclusion. A second round of independent checking
     * showed that the other source — a repeating event cut short by its own
     * limit — did not merely fail to record the first date it dropped: the
     * point it DID report was sometimes later than dates it had dropped, so
     * believing it deleted real events. `IcsReader` was changed on
     * 23 September 2026 so that a read in which any series was cut short
     * reports no end point at all, which this method already handles (nothing
     * is removed). **So the only source of an end point left is the
     * per-calendar slice**, and `<` is still exactly right for it: the slice
     * sorts on start and then on title, so two events at the SAME moment can
     * be split by the cut, which is the measured fault described above.
     *
     * WHAT THE CHOSEN FIX COSTS, stated plainly. An event that really HAS
     * been taken out of the outside calendar, and whose start happens to be
     * exactly the end point of a capped read, stays visible in the portal
     * until a refresh that reads the whole period comes along. On a calendar
     * that is permanently over the limit that may be never. That is the safe
     * direction — showing an event that has been cancelled is a smaller harm
     * than hiding one that is going ahead — and it is no longer silent: the
     * administrator's message now says up to which moment removal was
     * checked.
     *
     * @return array{point:?string, exact:bool} `point` is null when nothing
     *         may be removed at all. `exact` says whether a row starting at
     *         `point` was itself covered by the download.
     */
    private static function removalEndPoint(bool $capped, ?string $effectiveEnd, DateTimeImmutable $windowEnd): array
    {
        if ($capped === false) {
            return ['point' => $windowEnd->format(self::SQL_MOMENT), 'exact' => true];
        }

        if ($effectiveEnd === null || $effectiveEnd === '') {
            return ['point' => null, 'exact' => false];
        }

        // The two are returned together on purpose. Handing back a bare
        // string let the one caller there was use it without knowing where it
        // came from, and that is exactly how the deletion above happened.
        return ['point' => $effectiveEnd, 'exact' => false];
    }

    /**
     * Mark as removed every event of this calendar the download did not have.
     *
     * Soft deletion, never a real one. A removed event keeps its number, so
     * if it comes back — a date put back into the outside calendar, an
     * administrator undoing a mistake — every answer anybody gave to it is
     * still there. A real delete could not be undone.
     *
     * Three kinds of row are taken, and each is one part of the condition:
     *   * one with no identity, which the new importer can never match (what
     *     the old #327 job left behind, and migration 205 could not carry
     *     forward);
     *   * one that starts before the period the portal keeps, which has
     *     simply fallen out of the back of the window;
     *   * one this download did not contain, but only up to the point the
     *     download can be trusted to.
     *
     * WHY THE THIRD ONE IS SOMETIMES `<` AND SOMETIMES `<=`. `$trustedToIsExact`
     * comes straight from `removalEndPoint()` and says whether a row starting
     * at the very moment of the end point was covered by the download.
     *   * A complete read: yes. The end point is the end of the period the
     *     portal keeps, the download covered all of it, so a row sitting on
     *     that moment and not in the download really is gone — `<=`.
     *   * A capped read: NO. The end point is only "the last date the reader
     *     managed to keep", and anything else starting at that same moment
     *     may have been cut off rather than removed. Judging it would delete
     *     a real event, which is exactly what happened before this flag
     *     existed (see `removalEndPoint()` for the measured case) — `<`.
     *
     * The times are compared like with like: `startDateTime` is a wall-clock
     * reading and is compared with two other wall-clock readings in the same
     * zone; `externalLastSeenAt` is a UTC moment and is compared with the UTC
     * moment this refresh began.
     *
     * WHY IT IS `externalLastSeenAt < runStart` AND NOT `<=`, which looks like
     * a detail and is not. Step 6 has just stamped `runStart` onto every event
     * this download DID contain. With `<=`, every one of those would match
     * this condition and the refresh would delete the whole calendar it had
     * just written. `<` is what separates "seen in this download" from "last
     * seen in an earlier one".
     *
     * WHAT THAT CANNOT DO, measured while proving this part. A `DATETIME`
     * holds whole seconds, so two SUCCESSFUL refreshes of the same calendar
     * inside one second would leave the second one unable to tell its own
     * stamps from the first one's, and it would remove nothing. The portal
     * cannot reach that state: a refresh by hand is refused within
     * MANUAL_MIN_SECONDS (60) of the last attempt, a scheduled one is refused
     * until `nextFetchAt` has passed (at least MIN_FETCH_MINUTES away), and
     * the lease stops two running at once. It was reached by a test that
     * drove `refresh()` directly, twice, in the same second — and even then
     * the result was that NOTHING was removed, which is the safe direction.
     * A database clock stepped backwards has the same shape and the same safe
     * answer.
     */
    private static function removeMissing(
        \mysqli $db,
        int $feedId,
        string $windowStart,
        string $runStart,
        string $trustedTo,
        bool $trustedToIsExact
    ): int {
        // Only ever one of two fixed pieces of text, chosen by a boolean this
        // class works out itself. Nothing from the calendar, and nothing an
        // administrator types, reaches the statement — every value is still
        // bound.
        $endComparison = ($trustedToIsExact === true) ? '<=' : '<';

        $stmt = $db->prepare(
            'UPDATE tblEvents SET isDeleted = 1, deletedAt = NOW() '
            . 'WHERE externalFeedID = ? AND isDeleted = 0 AND ('
            . '     externalUidHash IS NULL'
            . '  OR startDateTime < ?'
            . '  OR ((externalLastSeenAt IS NULL OR externalLastSeenAt < ?) AND startDateTime '
            . $endComparison . ' ?))'
        );
        $stmt->bind_param('isss', $feedId, $windowStart, $runStart, $trustedTo);
        $stmt->execute();
        $removed = $stmt->affected_rows;
        $stmt->close();

        return max(0, $removed);
    }

    /** How many of this calendar's events are live right now. */
    private static function liveEventCount(\mysqli $db, int $feedId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0');
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['n'] ?? 0);
    }

    // =========================================================================
    // 📝 Recording what happened
    // =========================================================================

    /**
     * Record a failed attempt and leave the calendar ready for the next one.
     *
     * NOTHING about the events is touched. That is the whole point: a refresh
     * that could not read the calendar knows nothing about which events are
     * still in it.
     *
     * The wait before the next try doubles with each failure in a row, up to
     * a day. A calendar whose server is down would otherwise be asked for
     * every few minutes, for ever, and on a portal with several such
     * calendars that is most of what the scheduled job would do.
     *
     * @param array<string,mixed> $feed
     *
     * @return array{outcome:string, message:string, newPending:int}
     */
    private static function recordFailure(
        \mysqli $db,
        array $feed,
        string $runStart,
        string $message,
        ?int $httpStatus,
        int $bytes,
        string $trigger,
        ?int $byUserId
    ): array {
        $feedId   = (int) $feed['feedID'];
        $siteId   = (int) $feed['siteID'];
        $failures = ((int) ($feed['consecutiveFailures'] ?? 0)) + 1;
        $every    = max(self::MIN_FETCH_MINUTES, (int) ($feed['fetchEveryMins'] ?? self::MIN_FETCH_MINUTES));

        // Doubling, worked out in PHP rather than in SQL so that a long run of
        // failures cannot overflow: 2 to the power of a large number is a
        // number no column could hold, and the cap has to be applied to the
        // result, not hoped for.
        $backoff = $every * (2 ** min($failures, 16));
        $backoff = (int) max(1, min($backoff, self::MAX_BACKOFF_MINUTES));

        $short = self::forColumn($message, 255);
        $stmt  = $db->prepare(
            'UPDATE tblExternalFeeds SET lastFetchedAt = NOW(), lastFetchStatus = ?, lastFetchOk = 0, '
            . 'lastFetchMessage = ?, consecutiveFailures = ?, '
            . 'nextFetchAt = UTC_TIMESTAMP() + INTERVAL ? MINUTE, refreshLeaseUntil = NULL '
            . 'WHERE feedID = ?'
        );
        $stmt->bind_param('ssiii', $short, $message, $failures, $backoff, $feedId);
        $stmt->execute();
        $stmt->close();

        self::recordRun($db, [
            'feedID'           => $feedId,
            'siteID'           => $siteId,
            'startedAt'        => $runStart,
            'outcome'          => 'failed',
            'httpStatus'       => $httpStatus,
            'message'          => $message,
            'bytes'            => $bytes,
            'eventsSeen'       => 0,
            'rowsAdded'        => 0,
            'rowsUpdated'      => 0,
            'rowsRemoved'      => 0,
            'rowsSkipped'      => 0,
            'awaitingApproval' => 0,
            'triggeredBy'      => $trigger,
            'triggeredByID'    => $byUserId,
        ]);
        self::pruneRuns($db, $feedId);

        return self::answer('failed', $message);
    }

    /**
     * Record an attempt that found the file unchanged.
     *
     * @param array<string,mixed> $feed
     *
     * @return array{outcome:string, message:string, newPending:int}
     */
    private static function recordUnchanged(
        \mysqli $db,
        array $feed,
        string $runStart,
        ?int $httpStatus,
        int $bytes,
        string $trigger,
        ?int $byUserId
    ): array {
        $feedId  = (int) $feed['feedID'];
        $message = 'This calendar has not changed since the last refresh, so nothing needed doing.';

        // `lastCompleteAt` is deliberately NOT moved on. It marks the last
        // time the work was really done, and it is what decides how long this
        // shortcut may keep being taken — moving it would let a calendar be
        // skipped for ever on the strength of its own unchanged fingerprint.
        $short = self::forColumn($message, 255);
        $stmt  = $db->prepare(
            'UPDATE tblExternalFeeds SET lastFetchedAt = NOW(), lastFetchStatus = ?, lastFetchOk = 1, '
            . 'lastFetchMessage = ?, consecutiveFailures = 0, '
            . 'nextFetchAt = UTC_TIMESTAMP() + INTERVAL GREATEST(fetchEveryMins, ?) MINUTE, '
            . 'refreshLeaseUntil = NULL WHERE feedID = ?'
        );
        $minEvery = self::MIN_FETCH_MINUTES;
        $stmt->bind_param('ssii', $short, $message, $minEvery, $feedId);
        $stmt->execute();
        $stmt->close();

        self::recordRun($db, [
            'feedID'           => $feedId,
            'siteID'           => (int) $feed['siteID'],
            'startedAt'        => $runStart,
            'outcome'          => 'unchanged',
            'httpStatus'       => $httpStatus,
            'message'          => $message,
            'bytes'            => $bytes,
            'eventsSeen'       => 0,
            'rowsAdded'        => 0,
            'rowsUpdated'      => 0,
            'rowsRemoved'      => 0,
            'rowsSkipped'      => 0,
            'awaitingApproval' => 0,
            'triggeredBy'      => $trigger,
            'triggeredByID'    => $byUserId,
        ]);
        self::pruneRuns($db, $feedId);

        return self::answer('unchanged', $message);
    }

    /**
     * Mark a refresh that worked.
     *
     * `lastCompleteAt` is moved on for a capped refresh too. Its only job is
     * to bound how long the "the file has not changed" shortcut is trusted,
     * and that is just as true of a capped download as of a whole one — the
     * events that WERE read were read and written properly either way.
     * `lastRunCapped` is what records that it was not the whole calendar.
     */
    private static function stampSuccess(
        \mysqli $db,
        int $feedId,
        string $message,
        int $eventsSeen,
        string $contentHash,
        bool $capped
    ): void {
        $short   = self::forColumn($message, 255);
        $wasCapped = ($capped === true) ? 1 : 0;
        $minEvery  = self::MIN_FETCH_MINUTES;
        $stmt = $db->prepare(
            'UPDATE tblExternalFeeds SET lastFetchedAt = NOW(), lastFetchStatus = ?, lastImportCount = ?, '
            . 'lastFetchOk = 1, lastFetchMessage = ?, consecutiveFailures = 0, lastContentHash = ?, '
            . 'lastCompleteAt = UTC_TIMESTAMP(), lastRunCapped = ?, '
            . 'nextFetchAt = UTC_TIMESTAMP() + INTERVAL GREATEST(fetchEveryMins, ?) MINUTE, '
            . 'refreshLeaseUntil = NULL WHERE feedID = ?'
        );
        $stmt->bind_param('sissiii', $short, $eventsSeen, $message, $contentHash, $wasCapped, $minEvery, $feedId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Write one row of the refresh history.
     *
     * @param array<string,mixed> $run
     */
    private static function recordRun(\mysqli $db, array $run): void
    {
        $stmt = $db->prepare(
            'INSERT INTO tblExternalFeedRuns (feedID, siteID, startedAt, finishedAt, outcome, httpStatus, '
            . 'message, bytes, eventsSeen, rowsAdded, rowsUpdated, rowsRemoved, rowsSkipped, '
            . 'awaitingApproval, triggeredBy, triggeredByID) '
            . 'VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $feedId     = (int) $run['feedID'];
        $siteId     = (int) $run['siteID'];
        $startedAt  = (string) $run['startedAt'];
        $outcome    = (string) $run['outcome'];
        $httpStatus = $run['httpStatus'];
        $message    = self::forColumn((string) $run['message'], 500);
        $bytes      = (int) $run['bytes'];
        $seen       = (int) $run['eventsSeen'];
        $added      = (int) $run['rowsAdded'];
        $changed    = (int) $run['rowsUpdated'];
        $removed    = (int) $run['rowsRemoved'];
        $skipped    = (int) $run['rowsSkipped'];
        $waiting    = (int) $run['awaitingApproval'];
        $trigger    = (string) $run['triggeredBy'];
        $byUserId   = $run['triggeredByID'];
        $stmt->bind_param(
            'iissisiiiiiiisi',
            $feedId,
            $siteId,
            $startedAt,
            $outcome,
            $httpStatus,
            $message,
            $bytes,
            $seen,
            $added,
            $changed,
            $removed,
            $skipped,
            $waiting,
            $trigger,
            $byUserId
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Keep only the newest RUNS_KEPT attempts of one calendar.
     *
     * Done in two steps — find the cut-off, then delete below it — rather
     * than with a sub-query. MySQL refuses a DELETE whose sub-query reads the
     * table being deleted from ("ERROR 1093"), and the wrapping that gets
     * round that makes the database build a temporary copy of the list. Two
     * plain statements on an index are clearer and cheaper.
     *
     * The newest is the highest `runID`, because that column counts upward
     * and never resets.
     */
    private static function pruneRuns(\mysqli $db, int $feedId): void
    {
        $keep = self::RUNS_KEPT;
        $stmt = $db->prepare(
            'SELECT runID FROM tblExternalFeedRuns WHERE feedID = ? ORDER BY runID DESC LIMIT 1 OFFSET ?'
        );
        $offset = $keep - 1;
        $stmt->bind_param('ii', $feedId, $offset);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return;
        }

        $oldest = (int) $row['runID'];
        $stmt   = $db->prepare('DELETE FROM tblExternalFeedRuns WHERE feedID = ? AND runID < ?');
        $stmt->bind_param('ii', $feedId, $oldest);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================================
    // 🧰 Small shared pieces
    // =========================================================================

    /**
     * One sentence for the administrator: what happened, then anything the
     * reader wanted to say about the file.
     *
     * The warnings are the ONLY place a limit an administrator has set is
     * reported back to them. If they raise the "how many dates from one
     * calendar" setting past what the portal can do, the reader says so in a
     * warning and uses its own number instead — and that has to reach the
     * page, or the setting would look as though it had been obeyed.
     *
     * Cut to 500 characters because that is the column. Cutting at a whole
     * warning rather than mid-sentence is worth the few extra lines: half a
     * sentence reads like a fault in the portal.
     *
     * @param list<string> $warnings
     */
    private static function composeMessage(string $base, array $warnings): string
    {
        $text = $base;
        foreach ($warnings as $warning) {
            $candidate = $text . ' ' . $warning;
            if (mb_strlen($candidate, 'UTF-8') > 500) {
                break;
            }
            $text = $candidate;
        }

        return self::forColumn($text, 500);
    }

    /**
     * A stored moment written out the way a person reads it.
     *
     * `2026-10-10 00:00:00` becomes `10 October 2026 at 00:00`. Used in the
     * message shown to an administrator when a download was cut short, so
     * they can see exactly how far the refresh was able to check.
     *
     * The time of day is kept, and deliberately. It is not decoration: on a
     * cut-short download the removal step leaves alone anything starting at
     * that exact moment as well as anything after it, so an administrator
     * looking at two ten o'clock services needs the "10:00" to understand why
     * one was checked and the other was not.
     *
     * WHY THE UTC ZONE IS SAFE HERE, since a zone in this class usually is
     * not. Nothing is being converted. The value is a wall-clock reading in
     * the organisation's own zone, and it is read and written back in the
     * SAME zone, so the digits cannot move. UTC is named only because PHP
     * insists on some zone, and it is the one zone that never skips or
     * repeats an hour — so a reading on a clock-change night cannot be
     * quietly shifted on the way through. Passing the organisation's zone
     * instead would risk exactly that.
     *
     * Anything that does not look like a stored moment is handed back
     * unchanged rather than guessed at.
     */
    private static function plainMoment(string $sqlMoment): string
    {
        $when = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $sqlMoment,
            new DateTimeZone('UTC')
        );
        if ($when === false) {
            return $sqlMoment;
        }

        return $when->format('j F Y') . ' at ' . $when->format('H:i');
    }

    /** @return array{outcome:string, message:string, newPending:int} */
    private static function answer(string $outcome, string $message): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'newPending' => 0];
    }

    /**
     * Roll back if a transaction is open, and never make a bad situation
     * worse by throwing while tidying up.
     */
    private static function rollBackQuietly(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
            // The connection itself has probably gone. There is nothing left
            // to roll back to, and the database rolls an abandoned
            // transaction back on its own when the connection closes.
        }
    }

    /** Let go of the calendar, whatever else has gone wrong. */
    private static function releaseLeaseQuietly(\mysqli $db, int $feedId): void
    {
        try {
            $stmt = $db->prepare('UPDATE tblExternalFeeds SET refreshLeaseUntil = NULL WHERE feedID = ?');
            $stmt->bind_param('i', $feedId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $ignored) {
            // The lease runs out on its own after LEASE_MINUTES, so the worst
            // this costs is a wait. That is exactly why it has an end.
        }
    }

    /** The class of a problem, without its namespace. */
    private static function shortClassName(\Throwable $problem): string
    {
        $parts = explode('\\', get_class($problem));

        return (string) end($parts);
    }

    /**
     * Put a problem in the platform error log, which only administrators see.
     *
     * `errorPlatformForSite()` and not `errorPlatform()`, because the
     * scheduled job works through the calendars of EVERY organisation in one
     * request. `errorPlatform()` stamps whatever organisation the request
     * happened to start in, which for a scheduled job is simply the first
     * one — so the second organisation's fault would be written into the
     * first organisation's log, where an administrator who cannot explain it
     * sees it, and the administrator who needs it never does.
     *
     * The file name and line number are recorded because they cannot carry
     * anything from the calendar and they are what makes a fault findable.
     * The exception's own message is NOT, because it can.
     *
     * THE SIXTH ARGUMENT USED TO BE `null`, AND THAT MADE THIS METHOD DO
     * NOTHING AT ALL. Found by the first independent check of this part,
     * 23 September 2026. `Logger::errorPlatformForSite()` declares that
     * argument as `string $detail = ''`, and every file here runs under
     * `declare(strict_types=1)`, so `null` is not quietly turned into an
     * empty string — it raises a `TypeError` before the logger is even
     * entered. The `catch` a few lines below then swallowed it, exactly as it
     * is meant to swallow a database that has gone away. The result was a
     * refresh that told the administrator "The refresh could not be finished"
     * and pointed them at an error log which was, and always would be, empty.
     *
     * It is now the full path and line, which is what `Logger`'s own handler
     * for a PHP error puts in the same column (`Logger.php`, the
     * `errorPlatform('PHP', …, $file . ':' . $line)` call). The title carries
     * just the file name; the detail carries the folder as well, which is
     * what tells two files of the same name apart.
     *
     * `$code` and `$doing` (added by #514 part P7) let the recheck pass log
     * as itself — `FeedRecheckFailed`, "Re-checking who may see calendar #N"
     * — instead of reporting a recheck as a failed refresh. Their defaults
     * are exactly what every other caller has always written.
     */
    private static function logProblem(
        int $siteId,
        int $feedId,
        \Throwable $problem,
        string $code = 'FeedRefreshFailed',
        string $doing = 'Refreshing'
    ): void {
        try {
            Logger::errorPlatformForSite(
                $siteId,
                'FeedImport',
                'Error',
                $code,
                $doing . ' calendar #' . $feedId . ' ended with ' . get_class($problem)
                . ' at ' . basename($problem->getFile()) . ':' . $problem->getLine() . '.',
                $problem->getFile() . ':' . $problem->getLine()
            );
        } catch (\Throwable $ignored) {
            // Recording the problem is not worth causing another one.
        }
    }
}
