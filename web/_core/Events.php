<?php
// Path: _core/Events.php
/**
 * -----------------------------------------------------------------------------
 * Events — calendar/RSVP domain logic 📅 (#334 v1.1 follow-up)
 * -----------------------------------------------------------------------------
 * New core class for calendar/event business logic that doesn't belong
 * inline in an app controller. First (and currently only) resident:
 * `promoteFromWaitlist()` — the waitlist auto-promotion #334's own rsvp.php
 * doc comment reserved as a v1.1 follow-up ("Chronological promotion
 * happens in v1.1 — settings hook + cron will sweep waitlistedAt order").
 * This ships the promotion itself as an event-driven call (no cron needed):
 * every place `web/_apps/calendar/rsvp.php` frees a confirmed seat calls
 * `Events::promoteFromWaitlist()` immediately afterwards.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/334
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class Events
{
    /**
     * Promote as many earliest-waitlisted RSVPs as now fit into an event's
     * freed-up capacity. Call this immediately after ANY write that could
     * free a confirmed seat — a confirmed RSVP switching to 'not_going',
     * a confirmed row being cancelled/deleted, or a confirmed guestCount
     * being reduced.
     *
     * TRANSACTIONAL: the event row, the confirmed-seats aggregate, and the
     * waitlist candidate rows are all read with `FOR UPDATE` inside one
     * `App::beginTransaction()`/`commit()`/`rollback()` unit, so two
     * concurrent slot-freeing writes for the SAME event can never both
     * compute the same "remaining capacity" and double-promote past it.
     *
     * Candidates are tried earliest-waitlisted-first (`waitlistedAt ASC,
     * createdAt ASC`); a party whose `1 + guestCount` doesn't fit the seats
     * currently remaining is skipped (left on the waitlist) rather than
     * halting the sweep — a smaller party further down the list may still
     * fit the same freed-up capacity.
     *
     * Confirmation emails are sent AFTER the transaction commits (same
     * Mailer pattern as `event-register-save.php`'s own confirmation
     * email) — a mail failure is caught + logged per-recipient and can
     * NEVER roll back a promotion that has already committed.
     *
     * NEVER throws — any failure (bad connection, a mid-transaction
     * exception) is caught, logged via `error_log()`, the transaction
     * rolled back, and 0 returned.
     *
     * SINCE #531 (20 September 2026): `web/_apps/calendar/rsvp.php` takes
     * the SAME event-row lock this method takes, in its own transaction,
     * before it counts seats for an ordinary "going" answer. Two different
     * things fill a seat — an ordinary answer and this promotion — and
     * before that fix both could count the same free seat and both fill it,
     * overbooking the event by one. Now whichever of the two gets the event
     * row's lock first runs to completion (commits or rolls back) before
     * the other is even allowed to count, so they can no longer race.
     *
     * @param int $eventId
     *
     * @return int Number of RSVPs promoted from 'waitlist' to 'confirmed'.
     */
    public static function promoteFromWaitlist(int $eventId): int
    {
        if ($eventId <= 0) {
            return 0;
        }

        $promoted = 0;
        $toEmail  = [];
        $eventName = '';

        try {
            $db = App::db();
            if (!$db instanceof mysqli) {
                return 0;
            }

            // 🛡️ #533 (20 September 2026): there used to be a read here of
            //    AccountGuard::isSingleOrganisation(), feeding a single-
            //    organisation exception in the candidate query below. That
            //    exception is REMOVED — see the query's own comment for why
            //    — so nothing needs reading before the transaction opens any
            //    more.
            App::beginTransaction();

            // 🔒 Lock the event row first — serialises two concurrent
            // slot-freeing writes for the same event against each other.
            //
            // 🛡️ Codex catch-up B1: WHAT WAS WRONG — this lookup tested
            //    only "not deleted", with no status test at all, so a
            //    CANCELLED event, or one returned to DRAFT after being
            //    published, still ran the whole promotion below and sent
            //    "you are now confirmed for <event name>" emails for an
            //    event that will not happen. THE FIX: only a PUBLISHED or
            //    POSTPONED event promotes anybody. Postponed stays IN —
            //    rsvp.php lets people answer and waitlist for a postponed
            //    event, and the event page keeps showing it ("check back
            //    for the new date"); promotion only ever runs on a
            //    slot-freeing write, and nothing re-runs it when an event
            //    is later re-published, so excluding postponed here would
            //    mean a seat freed DURING a postponement is never filled.
            //    Cancelled is excluded even though rsvp.php also lets
            //    people answer a cancelled event, because confirming a
            //    seat at an event that will not happen at all is exactly
            //    the fault this fix closes.
            //
            // 👁️ #514 part P2: `externalFeedID IS NULL` — the portal's OWN
            //    events only. Events copied in from an outside calendar have
            //    no capacity and so no waiting list; this line keeps it that
            //    way even if a capacity were ever set on one, so a promotion
            //    can never email "you are now confirmed" about an imported
            //    event whatever its level. This line is also the marker the
            //    #514 visibility check (check_event_visibility.py, part P3)
            //    looks for: the lookup below reads tblEvents by number
            //    without EventVisibility::where(), on purpose. Its only
            //    caller (21 September 2026) is calendar/rsvp.php, after that
            //    handler's own lookup has run the rule for the person freeing
            //    the seat; the people this method promotes are checked for an
            //    active membership of the event's organisation by the
            //    candidate query further down.
            $stmt = $db->prepare(
                'SELECT capacity, siteID, eventName FROM tblEvents '
                . 'WHERE eventID = ? AND isDeleted = 0 AND externalFeedID IS NULL '
                . "AND status IN ('published', 'postponed') "
                . 'LIMIT 1 FOR UPDATE'
            );
            if ($stmt === false) {
                App::rollback();
                return 0;
            }
            $stmt->bind_param('i', $eventId);
            $stmt->execute();
            $event = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // 🚫 Unknown/deleted event, or an unlimited-capacity event —
            // rsvp.php only ever sets status='waitlist' when a real
            // capacity exists, so there is nothing to promote either way.
            if ($event === null || $event['capacity'] === null) {
                App::commit();
                return 0;
            }

            $capacity  = (int) $event['capacity'];
            $siteId    = (int) $event['siteID'];
            $eventName = (string) $event['eventName'];

            // 🔍 Seats already confirmed. The caller is expected to have
            // already committed its own status/guestCount change BEFORE
            // calling this method, so this reflects the true post-write
            // occupancy.
            $stmt = $db->prepare(
                'SELECT COALESCE(SUM(1 + guestCount), 0) AS seats FROM tblEventRSVPs '
                . 'WHERE eventID = ? AND siteID = ? AND response = "going" AND status = "confirmed" '
                . 'FOR UPDATE'
            );
            $confirmedSeats = 0;
            if ($stmt !== false) {
                $stmt->bind_param('ii', $eventId, $siteId);
                $stmt->execute();
                $confirmedSeats = (int) ($stmt->get_result()->fetch_assoc()['seats'] ?? 0);
                $stmt->close();
            }

            $remaining = $capacity - $confirmedSeats;
            if ($remaining <= 0) {
                App::commit();
                return 0;
            }

            // 📋 Every waitlisted RSVP, earliest-queued first — fetched
            // into an array up front so promoting one row's status mid-loop
            // can't disturb the cursor for the rest.
            $candidates = [];
            // ⚠️ WHAT WAS WRONG HERE, AND WHY IT MATTERED (#520)
            // `tblUsers` has never had a column called `email` — the real
            // name is `emailAddress`. This SELECT threw ERROR 1054 "Unknown
            // column 'u.email'" on every single call, because the portal
            // runs mysqli in strict mode (bootstrap.php). The whole method
            // is wrapped in a try/catch that rolls back and logs one line to
            // the PHP error log (see the catch below) and returns 0 — so
            // waitlist promotion has been COMPLETELY DEAD since it shipped:
            // nobody was ever moved up, nobody was ever emailed, and nothing
            // on screen ever said so; the caller (rsvp.php) just shows its
            // ordinary "RSVP cancelled" flash regardless. `AS email` is kept
            // on purpose so `$c['email']` further down (the confirmation
            // email step) needs no change at all.
            //
            // 🛡️ Codex catch-up B1 (continued): WHAT WAS ALSO WRONG — this
            //    query tested neither that the account was still active
            //    nor that the person still belonged to the event's own
            //    organisation. `offboarding/do.php` switches both of
            //    those off when someone leaves but does not touch their
            //    RSVP row, so a departed person could still be promoted
            //    off the waitlist and emailed "you are now confirmed" for
            //    an event they no longer have any right to attend. THE
            //    FIX (as first shipped): require an active account AND an
            //    active membership row for the event's own organisation, OR,
            //    on a single-organisation installation only, an account with
            //    no switched-OFF membership row at all — a compatibility
            //    exception for accounts that predated #518 and had never
            //    been given a membership row in the first place.
            //
            // 🛡️ #533 (20 September 2026): THAT COMPATIBILITY EXCEPTION IS
            //    NOW REMOVED. Migration 199 backfills a membership row for
            //    every such account (see its own header for the two cases —
            //    automatic on a single-organisation portal, listed for a
            //    global administrator to place on a multi-organisation
            //    one), so after the upgrade has run, an account with no
            //    membership row on a single-organisation portal is one a
            //    global administrator deliberately removed by hand
            //    ("Remove from site"), not one the old portal simply never
            //    got round to placing. Refusing to promote that account is
            //    now the INTENDED answer, the same answer the check-in page
            //    and the calendar feed already gave it — see
            //    `calendar/feed.php`'s own comment for how the three now
            //    agree. WHAT THIS MEANS BEFORE THE UPGRADE HAS RUN: a
            //    row-less account on a single-organisation portal stops
            //    being promoted the moment this code deploys, even before
            //    migration 199 runs — the ordering trap the migration's own
            //    header spells out.
            //
            //    ON LOCKING: this `FOR UPDATE` also locks the `tblUserSites`
            //    row it examines, in the order tblUsers then tblUserSites —
            //    the same order `offboarding/do.php` writes them in, and
            //    that transaction never touches `tblEventRSVPs`, so this
            //    adds no new deadlock ordering between the two.
            //
            // 🔒 #531 (20 September 2026): since this method already locks
            //    the event row (above) before counting confirmed seats,
            //    `calendar/rsvp.php` now takes THE SAME event-row lock, in
            //    its own transaction, before IT counts — see that file's
            //    comment. So this promotion and an ordinary "going" answer
            //    can no longer both count the same free seat: whichever
            //    transaction gets the lock first runs to completion, and the
            //    other counts only after it has committed.
            $stmt = $db->prepare(
                'SELECT r.rsvpID, r.userID, r.guestCount, u.emailAddress AS email, u.fullName '
                . 'FROM tblEventRSVPs r '
                . 'JOIN tblUsers u ON u.userID = r.userID '
                . 'WHERE r.eventID = ? AND r.siteID = ? AND r.response = "going" AND r.status = "waitlist" '
                . '  AND u.isActive = 1 '
                . '  AND EXISTS (SELECT 1 FROM tblUserSites ms '
                . '               WHERE ms.userID = u.userID AND ms.siteID = r.siteID AND ms.isActive = 1) '
                . 'ORDER BY r.waitlistedAt ASC, r.createdAt ASC '
                . 'FOR UPDATE'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $eventId, $siteId);
                $stmt->execute();
                $result = $stmt->get_result();
                while (($row = $result->fetch_assoc()) !== null) {
                    $candidates[] = $row;
                }
                $stmt->close();
            }

            foreach ($candidates as $c) {
                if ($remaining <= 0) {
                    break;
                }
                $seatsNeeded = 1 + (int) $c['guestCount'];
                if ($seatsNeeded > $remaining) {
                    // Doesn't fit the capacity remaining right now — leave
                    // waitlisted and keep checking smaller parties further
                    // down the list.
                    continue;
                }

                $rsvpId = (int) $c['rsvpID'];
                $upd = $db->prepare(
                    'UPDATE tblEventRSVPs SET status = "confirmed", waitlistedAt = NULL, updatedAt = NOW() '
                    . 'WHERE rsvpID = ? AND siteID = ? AND status = "waitlist"'
                );
                if ($upd === false) {
                    continue;
                }
                $upd->bind_param('ii', $rsvpId, $siteId);
                $upd->execute();
                $affected = $upd->affected_rows;
                $upd->close();

                if ($affected > 0) {
                    $remaining -= $seatsNeeded;
                    $promoted++;
                    $toEmail[] = $c;
                }
            }

            App::commit();
        } catch (\Throwable $e) {
            App::rollback();
            error_log('Events::promoteFromWaitlist() failed for event #' . $eventId . ': ' . $e->getMessage());
            return 0;
        }

        if ($promoted > 0) {
            Logger::activity(
                'EventRSVPWaitlistPromoted',
                $promoted . ' waitlisted RSVP(s) promoted for: ' . $eventName
            );
        }

        // 📧 Confirmation email to each newly-promoted user — same Mailer
        // pattern as event-register-save.php's own confirmation email.
        // The transaction above has already committed, so a mail failure
        // here can NEVER roll back a promotion — caught + logged per
        // recipient, never allowed to interrupt the rest of the batch.
        foreach ($toEmail as $c) {
            $email = (string) ($c['email'] ?? '');
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            try {
                $subject = "You're off the waitlist — " . $eventName;
                $body = '<p>Hi ' . htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>Good news — a spot has opened up and you are now <strong>confirmed</strong> for '
                    . '<strong>' . htmlspecialchars($eventName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                    . '<p>We look forward to seeing you there!</p>';
                Mailer::send($email, $subject, $body);
            } catch (\Throwable $e) {
                error_log('Events::promoteFromWaitlist() confirmation email failed for user #' . (int) $c['userID'] . ': ' . $e->getMessage());
            }
        }

        return $promoted;
    }

    /**
     * The upcoming events one person is involved with, for the "My volunteering"
     * page (/account/my-volunteering). Up to 30, soonest first.
     *
     * A person is "involved" in an event two ways: they are listed on its team
     * (tblEventPeople), or they coordinate it (tblEventCoordinators, not revoked).
     * Either is enough; an event matching both still comes back once.
     *
     * WHY THIS LIVES HERE AND NOT INSIDE THE PAGE (#547): the query used to sit
     * in the page, so no test could run it without a browser, and it carried a
     * fault nobody saw. It grouped by event but also picked a column that was
     * neither grouped nor wrapped in a function (the "is a coordinator" flag).
     * MySQL 8 refuses that by default (its ONLY_FULL_GROUP_BY mode, error 1055),
     * so the page crashed for everyone. `MAX(...)` below fixes it: an event has
     * at most one coordinator row per person, so MAX gives the same answer the
     * bare column did. Tried and rejected: adding the column to GROUP BY (it
     * would split one event into several rows) and switching the strict mode
     * off (the host decides that, not this code).
     *
     * `externalFeedID IS NULL` keeps copied-in calendar events out: they are
     * read-only (#514 D5), and this makes one exactly as "not found" as a
     * missing event. check_event_visibility.py looks for that condition.
     *
     * Each row holds: eventID, eventName, eventSlug, startDateTime, endDateTime,
     * locationName, status, description, myRoles (the person's roles, comma
     * separated, or empty) and amCoordinator (1 or 0).
     *
     * @param mysqli $db     The portal's database connection (passed in because
     *                       a page cannot rely on any global but $mysqli).
     * @param int    $userId The person.
     * @param int    $siteId The organisation (site) whose events to list.
     *
     * @return list<array<string, mixed>> Empty when there is nothing, or when
     *                                    the query could not be prepared.
     */
    public static function volunteeringFor(mysqli $db, int $userId, int $siteId): array
    {
        // 🕰️ #804, LEFT ON PURPOSE: "since a day ago" below is on the
        //    DATABASE SERVER's clock, not the event's. It is a deliberate day
        //    of grace (somebody looks up what they helped with yesterday), and
        //    the bound is the DATABASE's session clock, not UTC, and clocks span 26 hours
        //    (UTC-12 to UTC+14). So it never drops an event still to come only while the
        //    database clock is at most 12 hours ahead of UTC — true for a server on UTC or
        //    Europe, but a server on UTC+13 (Tonga, Samoa) would drop an `Etc/GMT+12` event
        //    in its last hour (#804's check measured it). The harm is small and rare; this
        //    used to claim it could never happen, which was untrue. Otherwise
        //    only how much of yesterday shows varies. Nothing here says
        //    "upcoming" or "past" per row -- a page that needs that asks
        //    nowOnItsOwnClock().
        $stmt = $db->prepare(
            'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
            . '       e.locationName, e.status, e.description, '
            . '       GROUP_CONCAT(DISTINCT ep.role ORDER BY ep.role SEPARATOR ", ") AS myRoles, '
            . '       MAX(CASE WHEN ec.coordinatorID IS NOT NULL THEN 1 ELSE 0 END) AS amCoordinator '
            . 'FROM tblEvents e '
            . 'LEFT JOIN tblEventPeople ep '
            . '       ON ep.eventID = e.eventID AND ep.userID = ? '
            . 'LEFT JOIN tblEventCoordinators ec '
            . '       ON ec.eventID = e.eventID AND ec.userID = ? AND ec.revokedAt IS NULL '
            // Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
            . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
            . '  AND e.startDateTime >= DATE_SUB(NOW(), INTERVAL 1 DAY) '
            . '  AND (ep.eventPersonID IS NOT NULL OR ec.coordinatorID IS NOT NULL) '
            . 'GROUP BY e.eventID '
            . 'ORDER BY e.startDateTime ASC LIMIT 30'
        );
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('iii', $userId, $userId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * 🕰️ "Now", ON EACH EVENT'S OWN CLOCK, as an SQL expression -- the ONE
     *    place a query asks whether an event is still to come, under way or
     *    today (#804).
     *
     * `tblEvents.startDateTime` and `endDateTime` hold a reading of the
     * EVENT's clock (`tblEvents.timezone`). Thirteen statements compared them
     * with `NOW()`, `CURDATE()` or `UTC_TIMESTAMP()` -- the DATABASE SERVER's
     * clock, in a session zone that is `SYSTEM` by default, so whatever the
     * database host happens to be set to. With an Auckland organisation on a
     * database in UTC, the public countdown feed went on publishing as "next"
     * an event that had begun up to thirteen hours earlier.
     *
     * WHY AN EXPRESSION AND NOT ONE BOUND "NOW". Each event carries its own
     * zone, so no single value is right for every row (#783 could bind one
     * for a resource page only because a resource belongs to one
     * organisation). A database cannot convert a zone NAME reliably either:
     * `CONVERT_TZ()` with a name needs the time-zone tables loaded, which
     * shared hosting usually does not do, and then answers NULL.
     *
     * So the zones actually in use are read first (a handful, whatever the
     * number of events -- `withUnknownZone()` below does the same), PHP works
     * out what each one's clock reads now, and the answer is
     *
     *     CAST(CASE CAST(`e`.`timezone` AS BINARY) WHEN ? THEN ? ... ELSE ? END AS DATETIME)
     *
     * with every value bound. That is EXACT, row by row, inside SQL -- so a
     * `LIMIT`, an `OFFSET` and a `COUNT(*)` all stay right, which "fetch a
     * loose superset and judge each row in PHP" (#783's My Bookings shape)
     * cannot promise once a page counts or pages its rows.
     *
     * 🔤 THE ZONE IS COMPARED BYTE FOR BYTE, `CAST(... AS BINARY)`, both in
     *    the list and in the `CASE` (#810). The column's own collation,
     *    `utf8mb4_general_ci`, ignores capitals AND ACCENTS, so a hand-written
     *    `Europe/Zürich` (which PHP cannot open, so it reads the organisation's
     *    clock) and the real `Europe/Zurich` are one value to it: `DISTINCT`
     *    could hand back only the bad spelling, and its `WHEN` would then
     *    catch every real Zurich row too and put it on the wrong clock. No
     *    form here can store such a text today (`Site::isZoneName()` guards
     *    the forms and the API), but a row written before #604, by hand or
     *    restored from a backup can. Exact comparison costs nothing.
     *    Why `BINARY` and not `COLLATE utf8mb4_bin`, which was the first
     *    version: `utf8mb4_bin` is a PAD SPACE collation on both engines, so
     *    `'Europe/London '` equals `'Europe/London'` under it and "exact" was
     *    untrue. It made no difference to any row -- `Site::zoneFor()` trims
     *    the text either way, so both spellings reach London -- but a binary
     *    comparison is what the word means, so each spelling now gets its own
     *    `WHEN`, and each still reaches its zone through that trim.
     *
     * The `ELSE` is reached by exactly one kind of row: one whose zone was
     * written in the instant between the two statements (every zone text
     * the statement can meet is otherwise in the list, because the column is
     * NOT NULL). For a page it reads the organisation's clock, wrong only by
     * that row's offset from the organisation's. A zone text PHP cannot open
     * is NOT an `ELSE` case: it is in the list, and reads the organisation's
     * clock exactly as every page that SHOWS the event does
     * (`Site::zoneFor()`).
     *
     * ⚠️ A FAILED READ OF THE ZONE LIST THROWS; it does not come back empty.
     *    `bootstrap.php` sets `MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR`,
     *    and with both flags `prepare()` and `execute()` throw rather than
     *    answer false, so the exception leaves the caller and its statement
     *    never runs. The `=== false` test below is a backstop for a caller
     *    without strict reporting, not a path this portal takes.
     *
     * ⚠️ THE EXPRESSION CANNOT USE THE `startDateTime` INDEX, because it
     *    differs row by row. A caller that wants the index adds a loose bound
     *    beside it -- `looseUtcBound('-1 day')`: no clock on Earth reads more
     *    than twelve hours behind UTC or fourteen ahead, so a reading still to
     *    come anywhere is after "UTC now, less a day".
     *
     * ⚠️ IN THE HOUR THE CLOCKS GO BACK a clock reads 01:00-01:59 twice; this
     *    answers by what it reads at the moment of asking
     *    (`I18n::wallClockNow()`), which is what a person in the room would say.
     *
     * A statement that DELETES on an event's time does not use this: see
     * `nowForADeletionDeadline()` below, which can only ever be late.
     *
     * @param mysqli   $db     The connection the expression will be used on.
     * @param string   $alias  The table alias for `tblEvents` in that statement
     *                         (letters, digits, underscore -- it is written
     *                         into SQL, so anything else is refused).
     * @param int|null $siteId One organisation: read only its events' zones,
     *                         and fall back to its clock. Null: every
     *                         organisation's zones, falling back to the clock
     *                         of the organisation this request is for.
     *
     * @return array{sql: string, types: string, params: list<string>} Bind
     *         `params` (all text) at the point `sql` appears -- and once more
     *         for each further time the caller writes `sql` into a statement.
     */
    public static function nowOnItsOwnClock(mysqli $db, string $alias, ?int $siteId = null): array
    {
        return self::perZoneReading(
            $db,
            $alias,
            $siteId,
            static fn (\DateTimeZone $zone): string => I18n::wallClockNow($zone),
            I18n::wallClockNow(new \DateTimeZone(Site::timezone($siteId)))
        );
    }

    /**
     * 🧹 "Now" for a DELETION DEADLINE on an event's time -- a reading that
     *    can only ever be LATE (#810). The nightly clear-out of event
     *    registrations asks this, and nothing else should.
     *
     * A registration (a name, a child's medical notes, a parent's telephone
     * number) is deleted n days after its event ends. Deleting it early
     * breaks the period the portal promised and cannot be undone; deleting
     * it a little late harms nobody. So where `nowOnItsOwnClock()` answers
     * what the event's clock reads now, this answers a reading no later than
     * that, chosen so the deadline is never earlier than EITHER
     *
     *   - n days on the event's own clock ("the same time n days later", the
     *     house rule for adding days), or
     *   - n × 24 real hours after the event ended.
     *
     * The two differ across a night the clocks change: across a spring-forward
     * night n days on the clock is an hour (two, in Antarctica/Troll) shorter
     * than n × 24 hours, and across a date-line jump it is up to a day shorter
     * (Pacific/Apia skipped 30 December 2011). For a deletion, the later of
     * the two is the safe one.
     *
     * HOW, WITHOUT CONVERTING EACH ROW. For each zone this binds
     *
     *     UTC now + the LOWEST offset that zone has had at any moment in the
     *     last `$lookBackDays` + 2 days
     *
     * written as a reading. Call that m, and the zone's offset when the event
     * ended o. A row is deleted when `reading < m - n days`, and DATE_SUB on
     * m is exact 24-hour days because m stands for a clock with no changes.
     *   - The clock-day deadline: m is never later than the zone's clock
     *     now (its offset now is one of those it has had), so passing
     *     "m - n days" means "the clock now - n days" has been passed too.
     *   - The real-hours deadline: the event ended at the moment
     *     `reading - o`. While that moment is inside the window, o is one of
     *     the offsets m's minimum was taken over, so o >= that minimum, and
     *     `reading < m - n days` gives `reading - o < UTC now - n x 24h`.
     *     An event that ended BEFORE the window ended more than n x 24h
     *     ago already, because the window reaches back the caller's LONGEST
     *     period -- which is why the caller must pass that longest period and
     *     not a typical one.
     *   - THE EXTRA DAYS ARE LOAD-BEARING: ONE IS NEEDED AND THE SECOND IS
     *     MARGIN (#810's round-3 check: one extra day gave no early deletion
     *     in any zone, none gave 151,225 cases). A reading in the
     *     hour the clocks skip (London's 01:30 on the morning they go
     *     forward) has two meanings, and the LATER one uses the offset that
     *     was in force just BEFORE the change -- an offset that ended before
     *     the moment that meaning names. For such an event ending near the
     *     start of the window, that offset is outside a window of exactly n
     *     days, and leaving it out deletes up to two hours early (measured in
     *     about two hundred zones). One day reaches past it; two are kept
     *     on purpose, because the price of the second is only lateness.
     *   - THE LOWEST OFFSET IS TAKEN OVER EVERY STATE IN THE WINDOW, not its
     *     two ends: a window from one summer to the next has summer at both
     *     ends and a whole winter in the middle.
     * THE COST IS LATENESS, NEVER EARLINESS, and it is larger than it looks.
     * A row waits past the later deadline by the difference between its
     * zone's offset when the event ended and the zone's LOWEST offset
     * anywhere in the window -- an hour for an ordinary summer-time zone, and
     * about TWO hours for both Antarctica/Troll (whose change is two hours)
     * and America/Nuuk (which moved its clocks for good in 2023): 2 h 0 m 1 s
     * measured for each. Where a zone jumped a whole day (Pacific/Apia, 2011)
     * it can be a day. And the window is the ORGANISATION's
     * longest period, not the event's: one event kept for ten years puts
     * every event of that organisation on a ten-year window.
     *
     * The `ELSE` (a zone written between the two statements) reads
     * `Etc/GMT+12`, the lowest offset there is, and a zone text PHP cannot
     * open is judged on the organisation's zone, the clock it is shown on
     * everywhere (`Site::zoneFor()`). Two more read `Etc/GMT+12`, both on
     * purpose, rather than being worked out:
     *   - a zone PHP opens but has NO list of changes for -- an abbreviation
     *     (`EST`, `BST`) or a bare offset (`+05:00`), for which
     *     `getTransitions()` answers false. Such texts reach the table only by
     *     hand or from an old backup; `Etc/GMT+12` makes them very late (up
     *     to seventeen hours for `+05:00`), which is the safe way to be wrong;
     *   - a look-back over 36,500 days (a hundred years). That limit is this
     *     code's own choice, not PHP's: a figure that size is a typing
     *     mistake, and the answer for it should not depend on how far back a
     *     zone's history happens to go.
     * A failed read throws, as above.
     *
     * @param mysqli $db           The connection the expression will be used on.
     * @param string $alias        The table alias for `tblEvents`.
     * @param int    $siteId       The one organisation whose events the
     *                             statement can meet. Never every
     *                             organisation's: a deletion is per
     *                             organisation, and a scheduled job has no
     *                             "current" one to fall back to.
     * @param int    $lookBackDays The LONGEST period, in days, that any row the
     *                             statement can meet is kept for.
     *
     * @return array{sql: string, types: string, params: list<string>} As
     *         `nowOnItsOwnClock()`.
     */
    public static function nowForADeletionDeadline(mysqli $db, string $alias, int $siteId, int $lookBackDays): array
    {
        $nowUtc  = time();
        $reading = static fn (\DateTimeZone $zone): string => self::deadlineReadingFor($zone, $lookBackDays, $nowUtc);

        return self::perZoneReading($db, $alias, $siteId, $reading, $reading(new \DateTimeZone('Etc/GMT+12')));
    }

    /**
     * The per-zone half of `nowForADeletionDeadline()`: the reading m for ONE
     * zone at ONE moment. Public, and given the moment rather than reading the
     * clock, so a test can drive the real rule at a fixed moment -- the cases
     * that matter (a skipped hour at the window's start, a winter inside a
     * summer-to-summer window) are on particular dates, and the real "now"
     * cannot be chosen. `nowForADeletionDeadline()` passes `time()`.
     *
     * Written in UTC because UTC has no transitions, so the answer is exactly
     * "UTC at `$nowUtc`, moved by the lowest offset", as a reading.
     *
     * @param \DateTimeZone $zone         The zone.
     * @param int           $lookBackDays The caller's LONGEST period, in days.
     * @param int           $nowUtc       The moment, as a Unix timestamp.
     */
    public static function deadlineReadingFor(\DateTimeZone $zone, int $lookBackDays, int $nowUtc): string
    {
        // Etc/GMT+12's offset: the lowest there is. Kept for a zone with no
        // list of changes and for a look-back past the 36,500-day limit.
        $offset = -12 * 3600;
        if ($lookBackDays >= 0 && $lookBackDays <= 36500) {
            $states = $zone->getTransitions($nowUtc - ($lookBackDays + 2) * 86400, $nowUtc);
            if (is_array($states) === true && $states !== []) {
                // EVERY state in the window, not its two ends (see above).
                $offset = min(array_map(static fn (array $s): int => (int) $s['offset'], $states));
            }
        }

        return (new \DateTimeImmutable('@' . ($nowUtc + $offset)))->format('Y-m-d H:i:s');
    }

    /**
     * The one builder behind both methods above: reads the zones in use,
     * BYTE FOR BYTE (see the 🔤 note), and binds `$readingFor` each one's reading,
     * with `$elseReading` for a zone written after the list was read.
     *
     * @param callable(\DateTimeZone): string $readingFor
     *
     * @return array{sql: string, types: string, params: list<string>}
     */
    private static function perZoneReading(
        mysqli $db,
        string $alias,
        ?int $siteId,
        callable $readingFor,
        string $elseReading
    ): array {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Events::nowOnItsOwnClock(): the alias must be a plain SQL name.');
        }

        $zones = [];
        $stmt  = $siteId === null
            ? $db->prepare('SELECT DISTINCT CAST(`timezone` AS BINARY) AS `zone` FROM `tblEvents`')
            : $db->prepare('SELECT DISTINCT CAST(`timezone` AS BINARY) AS `zone` FROM `tblEvents` WHERE `siteID` = ?');
        // A backstop only: under this portal's strict reporting a failed
        // prepare THROWS (see the ⚠️ note above), so this is never false here.
        if ($stmt !== false) {
            if ($siteId !== null) {
                $stmt->bind_param('i', $siteId);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $zones[] = (string) ($row['zone'] ?? '');
            }
            $stmt->close();
        }

        if ($zones === []) {
            // No events at all: `CASE` with no `WHEN` is not SQL, and the
            // first version of this method built exactly that, so every page
            // asking it on an installation with no events yet -- the
            // dashboard first -- answered with an error. With no events
            // there is no row for any reading to be wrong about. (A FAILED
            // read does not reach here; it throws.)
            return ['sql' => 'CAST(? AS DATETIME)', 'types' => 's', 'params' => [$elseReading]];
        }

        $sql    = 'CAST(CASE CAST(`' . $alias . '`.`timezone` AS BINARY)';
        $params = [];
        foreach ($zones as $zone) {
            $sql     .= ' WHEN ? THEN ?';
            $params[] = $zone;
            // Site::zoneFor() is the one place a stored zone text becomes a
            // clock, falling back to the organisation's for text PHP cannot
            // open -- exactly as every page that SHOWS the event does.
            $params[] = $readingFor(Site::zoneFor($zone, $siteId));
        }
        $sql     .= ' ELSE ? END AS DATETIME)';
        $params[] = $elseReading;

        return ['sql' => $sql, 'types' => str_repeat('s', count($params)), 'params' => $params];
    }

    /**
     * UTC "now" moved by `$shift` (a `modify()` phrase such as `'-1 day'`), as
     * `Y-m-d H:i:s` text -- ONLY for a LOOSE bound beside
     * `nowOnItsOwnClock()`, so a query can still use the `startDateTime`
     * index. It is not any event's clock and must never decide anything alone.
     */
    public static function looseUtcBound(string $shift): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($shift)->format('Y-m-d H:i:s');
    }

    /**
     * 🕰️ Events stored with a time zone PHP cannot open (#604), for an
     * administrator to put right: how many there are, and the first few.
     *
     * WHY THERE ARE ANY. Until #604 the event form's zone box was free text
     * and was stored exactly as typed, so "London" or "GMT+1" could be
     * saved. Such an event's times cannot be placed on any clock, so every
     * reader falls back to the organisation's own zone (Site::zoneFor()).
     * That may not be what whoever typed it meant, and only a person can
     * say, so these are FOUND AND LISTED, never changed here.
     *
     * An old name PHP still opens ("US/Eastern") is not listed: such an
     * event is shown on the clock it names, as it always was.
     *
     * HOW. A database cannot say whether PHP knows a zone, so the different
     * zone texts in use are read first (a handful, whatever the number of
     * events), each is tried in PHP, and only then are the events holding a
     * failed one fetched. Deleted events are left out, and so are events
     * copied in from an outside calendar (`externalFeedID IS NULL` in every
     * statement): the importer only ever stores a zone PHP has just opened,
     * and nobody could put such an event right from the portal anyway.
     *
     * @param int|null $siteId One organisation, or null for every organisation.
     * @param int      $limit  How many events to return at most; the count is of all of them.
     *
     * @return array{count:int, events:list<array{eventID:int, siteID:int, eventName:string, startDateTime:string, timezone:string}>}
     */
    public static function withUnknownZone(mysqli $db, ?int $siteId = null, int $limit = 25): array
    {
        $none = ['count' => 0, 'events' => []];

        $stmt = $siteId === null
            ? $db->prepare('SELECT DISTINCT timezone FROM tblEvents WHERE isDeleted = 0 AND externalFeedID IS NULL')
            : $db->prepare('SELECT DISTINCT timezone FROM tblEvents WHERE isDeleted = 0 AND externalFeedID IS NULL AND siteID = ?');
        if ($stmt === false) {
            return $none;
        }
        if ($siteId !== null) {
            $stmt->bind_param('i', $siteId);
        }
        $stmt->execute();
        $unknown = [];
        $result  = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $zone = (string) ($row['timezone'] ?? '');
            if (Site::phpCanOpenZone($zone) === false) {
                $unknown[] = $zone;
            }
        }
        $stmt->close();
        if ($unknown === []) {
            return $none;
        }

        $marks  = implode(', ', array_fill(0, count($unknown), '?'));
        $types  = str_repeat('s', count($unknown));
        $values = $unknown;
        $scope  = '';
        if ($siteId !== null) {
            $scope    = ' AND siteID = ?';
            $types   .= 'i';
            $values[] = $siteId;
        }

        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM tblEvents WHERE isDeleted = 0 AND externalFeedID IS NULL AND timezone IN (' . $marks . ')' . $scope);
        if ($stmt === false) {
            return $none;
        }
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        $limit = max(1, $limit);
        $stmt  = $db->prepare(
            'SELECT eventID, siteID, eventName, startDateTime, timezone FROM tblEvents '
            . 'WHERE isDeleted = 0 AND externalFeedID IS NULL AND timezone IN (' . $marks . ')' . $scope . ' ORDER BY startDateTime DESC, eventID DESC LIMIT ?'
        );
        if ($stmt === false) {
            return ['count' => $count, 'events' => []];
        }
        $types   .= 'i';
        $values[] = $limit;
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $events = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $events[] = [
                'eventID'       => (int) $row['eventID'],
                'siteID'        => (int) $row['siteID'],
                'eventName'     => (string) $row['eventName'],
                'startDateTime' => (string) $row['startDateTime'],
                'timezone'      => (string) $row['timezone'],
            ];
        }
        $stmt->close();

        return ['count' => $count, 'events' => $events];
    }

    /**
     * The zone the OLD event form filled its box with, for every
     * organisation. It is looked for here, and never written by anything.
     */
    private const OLD_FORM_ZONE = 'Europe/London';

    /**
     * 🕰️ This organisation's events, made BEFORE its time-zone upgrade, that
     * are still marked "Europe/London" although the organisation's own clock
     * tells a different time (#604).
     *
     * WHY THERE ARE ANY. Before #604 the event form filled its zone box with
     * "Europe/London" for every organisation, and the file import, the
     * events API and members' suggestions stored it whatever the
     * organisation. Nobody outside London's zone noticed, because the old
     * reminder job ignored an event's zone. Now reminders, the calendar feed
     * and the download go by the event's own zone, so such an event goes out
     * on London's clock: in the independent check's run, a Los Angeles
     * organisation's 19:00 event was reminded about at 09:53.
     * The portal cannot tell these from events truly in London, so it does
     * NOT guess: migration 217 leaves them alone, and this FINDS them for an
     * administrator, who can put them right in one go
     * (moveLondonMarkedToOrganisationZone()) or one by one.
     *
     * WHICH EVENTS: the portal's own (not copied in from an outside
     * calendar, whose zone the importer chose), not deleted, stored zone
     * "Europe/London" AS THE DATABASE COMPARES TEXT, made before the moment
     * migration 217's mark was written, AND whose start is a different
     * moment on London's clock than on the organisation's.
     * "As the database compares text" is not letter for letter: the column's
     * comparison takes no notice of capitals or of spaces after the name, so
     * "europe/london" and "Europe/London " (typed by hand into the old
     * free-text box) are found and changed too. That is meant: the portal
     * reads both on London's clock, so they have the same fault as the rest.
     * "GB", an emptied box and every other spelling are left alone. (This
     * comment used to say "exactly", which was not exact: found by the
     * second check, 13 October 2026.) That last test is why an organisation in
     * Dublin or Lisbon is never bothered: their clocks agree with London's.
     * Nothing is found for an organisation in London's own zone, or one whose
     * stored zone is not a zone name (there is no clock to move them to).
     *
     * WHAT IT CANNOT DO: tell an event truly in London from one merely
     * marked so; the notice says to leave such an event alone. And "before
     * the upgrade" is judged on the database's own clock, which both moments
     * were written by.
     *
     * @param int $limit How many COMING events to list at most; the counts are of all of them.
     *
     * @return array{count:int, coming:int, zone:string, ids:list<int>, events:list<array{eventID:int, eventName:string, startDateTime:string}>}
     */
    public static function markedLondonBeforeUpgrade(mysqli $db, int $siteId, int $limit = 25): array
    {
        $found = ['count' => 0, 'coming' => 0, 'zone' => '', 'ids' => [], 'events' => []];
        $facts = Site::timezoneFacts($siteId);
        if ($facts['inRow'] === false || $facts['known'] === false || $facts['zone'] === self::OLD_FORM_ZONE) {
            return $found;
        }
        $stmt = $db->prepare(
            'SELECT e.eventID, e.eventName, e.startDateTime FROM tblEvents e '
            . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL AND e.timezone = ? '
            . '  AND e.createdAt < (SELECT m.updatedAt FROM tblSettings m WHERE m.settingKey = ? AND m.siteID IS NULL LIMIT 1) '
            . 'ORDER BY e.startDateTime ASC, e.eventID ASC'
        );
        if ($stmt === false) {
            return $found;
        }
        $oldZone = self::OLD_FORM_ZONE;
        $markKey = Site::ZONE_MARK_KEY;
        $stmt->bind_param('iss', $siteId, $oldZone, $markKey);
        $stmt->execute();
        $london = new \DateTimeZone($oldZone);
        $own    = new \DateTimeZone($facts['zone']);
        // "Still to come" is judged as clock times are compared here: as text, on the organisation's own clock.
        $nowClock = (new \DateTimeImmutable('now', $own))->format('Y-m-d H:i:s');
        $result   = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $start = (string) $row['startDateTime'];
            try {
                $same = (new \DateTimeImmutable($start, $london))->getTimestamp() === (new \DateTimeImmutable($start, $own))->getTimestamp();
            } catch (\Throwable $e) {
                continue; // a start that cannot be read is no clock time to compare
            }
            if ($same === true) {
                continue;
            }
            $found['ids'][] = (int) $row['eventID'];
            if ($start >= $nowClock) {
                $found['coming']++;
                if (count($found['events']) < max(1, $limit)) {
                    $found['events'][] = ['eventID' => (int) $row['eventID'], 'eventName' => (string) $row['eventName'], 'startDateTime' => $start];
                }
            }
        }
        $stmt->close();
        $found['count'] = count($found['ids']);
        $found['zone']  = $facts['zone'];

        return $found;
    }

    /**
     * 🕰️ Set every event markedLondonBeforeUpgrade() finds, past and coming,
     * to the organisation's own zone. The Manage Events button. All of them
     * or none: one transaction.
     *
     * IT CHANGES THE ZONE ONLY, never a stored clock time: 19:00 marked
     * "Europe/London" becomes 19:00 on the organisation's clock, which is
     * what whoever typed 19:00 there meant. The caller has already checked
     * who is asking and the form token, and writes the activity log's line.
     *
     * @return int How many events were changed.
     *
     * @throws \Throwable When the database refuses; nothing is changed then.
     */
    public static function moveLondonMarkedToOrganisationZone(mysqli $db, int $siteId): int
    {
        $found = self::markedLondonBeforeUpgrade($db, $siteId, 1);
        if ($found['ids'] === []) {
            return 0;
        }
        $oldZone = self::OLD_FORM_ZONE;
        $changed = 0;
        $db->begin_transaction();
        try {
            foreach (array_chunk($found['ids'], 500) as $ids) {
                // The zone it is leaving is in the statement too, so an event edited meanwhile is left as its editor set it.
                // (Compared as the finder compares it: without regard to capitals or a space after the name.)
                $stmt = $db->prepare(
                    'UPDATE tblEvents SET timezone = ? WHERE siteID = ? AND externalFeedID IS NULL AND timezone = ? '
                    . 'AND eventID IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
                );
                $stmt->bind_param('sis' . str_repeat('i', count($ids)), $found['zone'], $siteId, $oldZone, ...$ids);
                $stmt->execute();
                $changed += $stmt->affected_rows;
                $stmt->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return $changed;
    }

    // =========================================================================
    // 🔤 AN EVENT'S WEB ADDRESS (#877)
    // =========================================================================

    /**
     * How many numbered addresses `freeSlug()` asks about before it gives up
     * on numbers and adds a short random ending instead.
     */
    public const SLUG_NUMBERED_TRIES = 50;

    /**
     * The address an event would like to have: its name made address-safe,
     * then the DATE it starts on — `sunday-service-2026-06-07`.
     *
     * The date is what lets the same name exist on many days. Before #877 the
     * calendar file import made the address from the NAME ALONE, so a file
     * holding the same service every week asked for one address over and
     * over, and the database refused every row after the first
     * (`uq_event_site_slug` allows one address per organisation). The Manage
     * Events form already added the date; this is the same shape, so an
     * imported event and a typed one get addresses that look alike.
     *
     * 🕐 THE DATE IS SLICED OUT OF THE TEXT, NEVER RESOLVED (#777). The start
     *    is a reading on the event's own clock. Passing it through
     *    `strtotime()` would put the address on the NEXT day for a reading in
     *    the hour the clocks skip, in the zones that go forward at 23:00
     *    (`calendar/manage/save.php` records that measurement). Anything that
     *    is not a digit becomes a single hyphen, so even text nobody checked
     *    cannot put a `/` or a quote into an address.
     *
     * The name part is cut to 100 characters, as the import always cut it,
     * which leaves room for the date and a numbered ending inside the
     * column's 200. A name with nothing address-safe in it (all punctuation,
     * or a script with no Latin letters) becomes `event`.
     *
     * @param string $eventName    The event's name as it will be stored.
     * @param string $startReading The start as stored (`YYYY-MM-DD HH:MM:SS`).
     *
     * @return string The address the event would like, before anybody has
     *                asked whether it is free.
     */
    public static function slugStem(string $eventName, string $startReading): string
    {
        $name = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $eventName), '-'));
        $name = rtrim(substr($name !== '' ? $name : 'event', 0, 100), '-');
        $date = trim((string) preg_replace('/[^0-9]+/', '-', substr($startReading, 0, 10)), '-');

        return $date !== '' ? $name . '-' . $date : $name;
    }

    /**
     * The first address, starting from `$stem`, that no event in this
     * organisation holds: `$stem`, then `$stem-2`, `$stem-3` … up to
     * `SLUG_NUMBERED_TRIES`, then `$stem-` and eight random hex characters.
     *
     * 🔑 ASKED OF THE DATABASE, NEVER WORKED OUT FROM A POSITION. The unique
     *    key `uq_event_site_slug (siteID, eventSlug)` covers DELETED events
     *    too — a deleted event keeps its address for ever — so a deleted
     *    "Sunday Service" on the same day still holds its address, and an
     *    address built from "this is the second row of the file" would
     *    collide with it (the series pattern page met exactly that, #669).
     *
     * 🛡️ NO `isDeleted` AND NO `externalFeedID IS NULL` IN THE QUESTION, and
     *    adding either would be a bug. The question is only "would the
     *    database refuse this address", and the key does not care whether the
     *    row is deleted or was copied in from an outside calendar. Narrowing
     *    it would report an address as free that the database is about to
     *    refuse. It shows no event to anybody and returns no detail of one —
     *    only whether a row exists — so it needs neither
     *    `EventVisibility::where()` nor the imported-event refusal.
     *
     * ⚠️ WHAT THIS CANNOT PROMISE. Between this answer and the INSERT,
     *    somebody else (a second import, the Manage Events form) can take the
     *    same address. The unique key still refuses the second writer, so no
     *    address is ever stored twice; the CALLER must catch that refusal and
     *    ask again, a bounded number of times. The calendar file import does.
     *
     * Bounded on purpose: a loop that could run for ever against a unique key
     * is worse than a refusal. Fifty events with the same name on the same
     * day in one organisation is not a real calendar, so the random ending is
     * a last resort; it is not asked about, and if it ever collided the
     * caller's retry would ask again.
     *
     * The question THROWS on a database fault (the portal's strict
     * reporting), exactly as the INSERT after it would; the caller decides
     * what a throw means for its row.
     *
     * @param mysqli $db     The connection the event will be written on.
     * @param int    $siteId The organisation the event belongs to.
     * @param string $stem   What `slugStem()` gave.
     *
     * @return string An address no event in the organisation held when asked.
     */
    public static function freeSlug(mysqli $db, int $siteId, string $stem): string
    {
        $probe = $db->prepare(
            'SELECT `eventID` FROM `tblEvents` WHERE `siteID` = ? AND `eventSlug` = ? LIMIT 1'
        );
        if ($probe === false) {
            // Only reachable if the reporting mode ever stops throwing.
            throw new \RuntimeException('the question "is this web address free" could not be prepared');
        }
        try {
            for ($try = 1; $try <= self::SLUG_NUMBERED_TRIES; $try++) {
                $candidate = $try === 1 ? $stem : $stem . '-' . $try;
                $probe->bind_param('is', $siteId, $candidate);
                $probe->execute();
                if ($probe->get_result()->fetch_row() === null) {
                    return $candidate;
                }
            }
        } finally {
            $probe->close();
        }

        return $stem . '-' . bin2hex(random_bytes(4));
    }

    /**
     * The event already in this organisation's calendar that a calendar file
     * row would repeat — the SAME NAME and the SAME START, date and time — or
     * null when there is none (D-100, #877).
     *
     * WHY THIS EXISTS. Before #877 the import made an event's web address
     * from its name alone, so importing the same file twice was refused row
     * by row on the address — a safety net nobody designed. #877 gave every
     * row its own address, which removed it: re-uploading a file (the very
     * thing D-099 tells a manager to do after fixing one bad row) quietly
     * made a second copy of every event. The owner chose to skip such a row
     * unless the manager ticks "import these anyway" (D-100).
     *
     * WHAT COUNTS AS "ALREADY IN THE CALENDAR", each choice deliberate:
     *   - THE NAME, LETTER FOR LETTER. Compared as bytes, so "Sunday Service"
     *     and "SUNDAY SERVICE" are different events. A re-uploaded file
     *     carries exactly the bytes it carried the first time, so the exact
     *     test catches every re-upload; a looser one would also skip rows
     *     nobody meant to repeat, and a wrongly skipped row is the harder
     *     mistake to notice.
     *   - THE START, EXACTLY AS STORED. `$startReading` must be what the
     *     import stores (`YYYY-MM-DD HH:MM:SS`, the wall-clock reading), and it
     *     is compared as that reading: nothing is turned into a moment (#777).
     *   - A DELETED EVENT DOES NOT COUNT. The manager deleted it; importing it
     *     again is a way of putting it back, and refusing would leave no way
     *     but the tick to do that.
     *   - AN EVENT COPIED IN FROM AN OUTSIDE CALENDAR DOES COUNT — but only
     *     one belonging to THIS organisation (every row here is), because it
     *     is in this organisation's calendar, and a manager importing the
     *     same service from a spreadsheet would otherwise show members two of
     *     it. Another organisation's events never count: the question is
     *     scoped by `siteID`.
     *
     * WHAT IT CANNOT PROMISE. It is a question, not a lock: an event added by
     * somebody else between this answer and the import's INSERT is not seen.
     * The import asks at CONFIRM time, not only at preview, which closes the
     * window that matters (minutes between the two pages), not the
     * millisecond one.
     *
     * 🛡️ No `EventVisibility::where()` and no `externalFeedID IS NULL` — the
     *    question is "is it in this organisation's calendar at all", asked
     *    for a manager of that calendar, and narrowing it would let a
     *    copied-in event be imported twice. It returns the event's number,
     *    address and start, nothing more, and is listed on
     *    `check_event_visibility.py`'s LINE_ALLOWLIST with that reason.
     *
     * @param mysqli $db           The connection.
     * @param int    $siteId       The organisation importing.
     * @param string $eventName    The row's name, as it would be stored.
     * @param string $startReading The row's start, as it would be stored.
     *
     * @return array{eventID:int, eventSlug:string, startDateTime:string}|null
     */
    public static function alreadyInCalendar(mysqli $db, int $siteId, string $eventName, string $startReading): ?array
    {
        $ask = $db->prepare(
            'SELECT `eventID`, `eventSlug`, `startDateTime` FROM `tblEvents` WHERE `siteID` = ? AND `isDeleted` = 0 AND `startDateTime` = ? AND CAST(`eventName` AS BINARY) = CAST(? AS BINARY) ORDER BY `eventID` LIMIT 1'
        );
        if ($ask === false) {
            // Only reachable if the reporting mode ever stops throwing.
            throw new \RuntimeException('the question "is this event already in the calendar" could not be prepared');
        }
        try {
            $ask->bind_param('iss', $siteId, $startReading, $eventName);
            $ask->execute();
            $row = $ask->get_result()->fetch_assoc();
        } finally {
            $ask->close();
        }
        if ($row === null) {
            return null;
        }

        return [
            'eventID'       => (int) $row['eventID'],
            'eventSlug'     => (string) $row['eventSlug'],
            'startDateTime' => (string) $row['startDateTime'],
        ];
    }
}
