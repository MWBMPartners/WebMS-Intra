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
     * page (/account/my-volunteering). Up to 30, soonest first by the REAL
     * moment each starts, whatever clock each is on (#1025).
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
        // 🕰️ "Since a day ago" is a deliberate day of grace (somebody looks
        //    up what they helped with yesterday), and since #1025 it is a day
        //    on EACH EVENT'S OWN CLOCK: Events::nowOnItsOwnClock(), less one
        //    day. It used to be `DATE_SUB(NOW(), INTERVAL 1 DAY)` -- the
        //    DATABASE SERVER's clock in its own session zone -- so an event on
        //    a clock far behind the database's (Pago Pago, UTC-11) dropped off
        //    up to eleven hours inside its day of grace, and one far ahead
        //    (Kiritimati, UTC+14) stayed up to fourteen hours past it. The
        //    loose bound (two days back on UTC) is only for the
        //    `startDateTime` index: no clock reads more than a day and a half
        //    from UTC, so it never cuts a row the exact bound keeps.
        //
        // ⏱️ ORDER: by the REAL moment each event starts
        //    (Events::startAsUtcMoment(), #1025), then the reading and the
        //    event's number. It was `ORDER BY e.startDateTime`, a reading on
        //    each event's own clock, so events on different clocks came out
        //    in the wrong order -- and with `LIMIT 30` the sooner of two could
        //    be the one cut off. Worked out row by row inside SQL, so the
        //    LIMIT keeps exactly the thirty soonest.
        $nowOnClock = self::nowOnItsOwnClock($db, 'e', $siteId);
        $moment     = self::startAsUtcMoment($db, 'e', $siteId);
        $stmt = $db->prepare(
            'SELECT `e`.`eventID`, `e`.`eventName`, `e`.`eventSlug`, `e`.`startDateTime`, `e`.`endDateTime`, '
            . '       `e`.`locationName`, `e`.`status`, `e`.`description`, '
            . '       GROUP_CONCAT(DISTINCT `ep`.`role` ORDER BY `ep`.`role` SEPARATOR ", ") AS `myRoles`, '
            . '       MAX(CASE WHEN `ec`.`coordinatorID` IS NOT NULL THEN 1 ELSE 0 END) AS `amCoordinator` '
            . 'FROM `tblEvents` `e` '
            . 'LEFT JOIN `tblEventPeople` `ep` '
            . '       ON `ep`.`eventID` = `e`.`eventID` AND `ep`.`userID` = ? '
            . 'LEFT JOIN `tblEventCoordinators` `ec` '
            . '       ON `ec`.`eventID` = `e`.`eventID` AND `ec`.`userID` = ? AND `ec`.`revokedAt` IS NULL '
            // Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
            . 'WHERE `e`.`siteID` = ? AND `e`.`isDeleted` = 0 AND `e`.`externalFeedID` IS NULL '
            . '  AND `e`.`startDateTime` >= ? '
            . '  AND `e`.`startDateTime` >= DATE_SUB(' . $nowOnClock['sql'] . ', INTERVAL 1 DAY) '
            . '  AND (`ep`.`eventPersonID` IS NOT NULL OR `ec`.`coordinatorID` IS NOT NULL) '
            . 'GROUP BY `e`.`eventID` '
            . 'ORDER BY ' . $moment['sql'] . ' ASC, `e`.`startDateTime` ASC, `e`.`eventID` ASC LIMIT 30'
        );
        if ($stmt === false) {
            return [];
        }

        $loose = self::looseUtcBound('-2 days');
        $stmt->bind_param(
            'iiis' . $nowOnClock['types'] . $moment['types'],
            $userId,
            $userId,
            $siteId,
            $loose,
            ...$nowOnClock['params'],
            ...$moment['params']
        );
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
     * The `ELSE` is reached by two kinds of row: one whose zone was written
     * in the instant between the two statements, and -- since round 3 of
     * #1024's check -- one whose zone text `zonesInUse()` LEFT OUT because
     * more than `ZONE_TEXT_LIMIT` distinct texts exist (texts of events that
     * are not deleted come first, so in practice only texts of deleted
     * events, or of an organisation with over five hundred zones, are left
     * out). For a page it reads the organisation's clock, wrong by that
     * row's offset from the organisation's. A zone text PHP cannot open is
     * NOT an `ELSE` case while it is in the list: it reads the
     * organisation's clock exactly as every page that SHOWS the event does
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
     * The `ELSE` (a zone written between the two statements, or a zone text
     * `zonesInUse()` left out at its `ZONE_TEXT_LIMIT`) reads
     * `Etc/GMT+12`, the lowest offset there is -- so such a row is only ever
     * deleted LATE -- and a zone text PHP cannot
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
     * with `$elseReading` for a zone written after the list was read AND for
     * a zone text `zonesInUse()` left out at its `ZONE_TEXT_LIMIT`.
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

        $zones = self::zonesInUse($db, $siteId);

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
     * The zone texts the events of one organisation (or, with null, of every
     * organisation) are stored with, BYTE FOR BYTE (see the 🔤 note on
     * `nowOnItsOwnClock()`): the ONE read behind every per-zone builder in
     * this class, so they cannot disagree about which zones exist. A handful
     * of rows, whatever the number of events. Public since #898, because the
     * public door needs the zones' offsets to know how wide a window of
     * readings can hold the soonest real moments (`PublicDoor::events()`).
     *
     * A failed read THROWS under this portal's strict reporting; it never
     * comes back empty (an empty list means "no events at all").
     *
     * 🧮 AT MOST `ZONE_TEXT_LIMIT` TEXTS (round 3 of #1024's check). Every
     *    text costs at least one bound value in the statement built from it,
     *    and 16,000 distinct texts on DELETED events made the calendar list
     *    and My volunteering answer 500 (too many placeholders). So the texts
     *    of events that are not deleted come first, then the most used, and
     *    the list stops at the limit. A row whose text is left out reaches
     *    the builder's `ELSE`: the organisation's own clock for a page, and
     *    `Etc/GMT+12` (the latest) for a deletion deadline. No real
     *    organisation is near the limit; PHP knows about six hundred zones.
     *    `soonestByRealStart()` widens its proof window when the limit was
     *    reached, because a text left out might have the highest offset.
     *
     * @param bool $liveOnly Only events that are not deleted (the statements
     *                       that list events, which never show a deleted one).
     *
     * @return list<string>
     */
    public static function zonesInUse(mysqli $db, ?int $siteId, bool $liveOnly = false): array
    {
        $zones = [];
        $where = [];
        if ($siteId !== null) {
            $where[] = '`siteID` = ?';
        }
        if ($liveOnly === true) {
            $where[] = '`isDeleted` = 0';
        }
        $stmt = $db->prepare(
            'SELECT CAST(`timezone` AS BINARY) AS `zone` '
            . 'FROM `tblEvents`' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' '
            . 'GROUP BY CAST(`timezone` AS BINARY) '
            . 'ORDER BY SUM(`isDeleted` = 0) DESC, COUNT(*) DESC, `zone` '
            . 'LIMIT ' . self::ZONE_TEXT_LIMIT
        );
        // A backstop only: under this portal's strict reporting a failed
        // prepare THROWS (see the ⚠️ note on nowOnItsOwnClock()), so this is
        // never false here.
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

        return $zones;
    }

    /**
     * 🕰️ What each event's OWN clock read at ONE given moment, as a SQL
     *    expression -- `nowOnItsOwnClock()` with the moment passed in rather
     *    than read from the clock (#882, #898).
     *
     * The public door passes `time()`. A test passes a moment on one of the
     * two nights a year the clocks change, because the real "now" cannot be
     * chosen and the cases that matter are on particular dates (the same
     * reason `deadlineReadingFor()` takes its moment). Built by the SAME
     * builder as `nowOnItsOwnClock()`, so the two cannot disagree about a
     * zone; the reading is made from the instant in UTC, which has no
     * transitions, then shown on the zone's clock.
     *
     * @return array{sql: string, types: string, params: list<string>} As `nowOnItsOwnClock()`.
     */
    public static function readingOnItsOwnClockAt(mysqli $db, string $alias, int $siteId, int $atUtc): array
    {
        $reading = static fn (\DateTimeZone $zone): string
            => (new \DateTimeImmutable('@' . $atUtc))->setTimezone($zone)->format('Y-m-d H:i:s');

        return self::perZoneReading($db, $alias, $siteId, $reading, $reading(new \DateTimeZone(Site::timezone($siteId))));
    }

    /**
     * ⏱️ "This event STARTS at or after the instant `$atUtc`", judged on each
     *    event's own clock exactly as the instant the portal publishes for it
     *    (`I18n::wallClockInstant()`, the public door's `startsAt`) -- as a
     *    SQL condition, with no `CONVERT_TZ()` (#882).
     *
     * WHY IT IS NEEDED. A stored start is a READING on its event's clock
     * (`tblEvents.timezone`), not a moment. "Started less than three hours
     * ago" is a question about real hours: three EXACT hours, which across a
     * night the clocks change is not "the reading plus three". Comparing the
     * reading with a reading moved by three hours would be an hour out on
     * those two nights a year -- the house rule ("Times always respect the
     * time zone") says exact hours are seconds, never a wall-clock offset.
     *
     * HOW, WITHOUT CONVERTING EACH ROW. For each zone in use, PHP works out
     * the SET of readings whose instant is at or after `$atUtc`
     * (`readingsAtOrAfter()`): a handful of ranges of readings, almost always
     * ONE ("from this reading on"), two or three only when a clock change
     * falls near the moment. The condition is then
     *
     *     CASE CAST(`e`.`timezone` AS BINARY)
     *          WHEN ? THEN (`e`.`startDateTime` >= ? [AND ... < ?] OR ...)
     *          ... ELSE (the same for the organisation's clock) END = 1
     *
     * with every value bound, exact row by row inside SQL, so `LIMIT` stays
     * right. The zones are read with `zonesInUse()`, and the `ELSE` -- a zone
     * written between that read and this statement, or a zone text left out
     * at `ZONE_TEXT_LIMIT` -- reads the organisation's clock, as `nowOnItsOwnClock()` does; a zone text PHP
     * cannot open reads it too, through `Site::zoneFor()`, as every page
     * that shows the event does. With no events at all there are no zones,
     * and a `CASE` with no `WHEN` is not SQL (#804's trap), so the
     * organisation's test is returned on its own.
     *
     * Like `nowOnItsOwnClock()`, it differs row by row, so it cannot use the
     * `startDateTime` index; a caller wanting one adds a loose bound beside it.
     *
     * @return array{sql: string, types: string, params: list<string>} A
     *         condition to put after AND; bind `params` where `sql` appears.
     */
    public static function startsAtOrAfter(mysqli $db, string $alias, int $siteId, int $atUtc): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Events::startsAtOrAfter(): the alias must be a plain SQL name.');
        }
        $column = '`' . $alias . '`.`startDateTime`';
        $test   = static function (\DateTimeZone $zone) use ($column, $atUtc): array {
            $parts  = [];
            $params = [];
            foreach (self::readingsAtOrAfter($zone, $atUtc) as [$from, $until]) {
                if ($until === null) {
                    $parts[]  = $column . ' >= CAST(? AS DATETIME)';
                    $params[] = $from;
                } else {
                    $parts[]  = '(' . $column . ' >= CAST(? AS DATETIME) AND ' . $column . ' < CAST(? AS DATETIME))';
                    $params[] = $from;
                    $params[] = $until;
                }
            }

            // Never empty in practice (a reading far enough ahead is always
            // after any instant), but an empty OR is not SQL, and "nothing"
            // is the honest answer for an empty set.
            return ['sql' => $parts === [] ? '0 = 1' : '(' . implode(' OR ', $parts) . ')', 'params' => $params];
        };

        $own   = $test(new \DateTimeZone(Site::timezone($siteId)));
        $zones = self::zonesInUse($db, $siteId);
        if ($zones === []) {
            return ['sql' => $own['sql'], 'types' => str_repeat('s', count($own['params'])), 'params' => $own['params']];
        }

        $sql    = '(CASE CAST(`' . $alias . '`.`timezone` AS BINARY)';
        $params = [];
        foreach ($zones as $zone) {
            $one      = $test(Site::zoneFor($zone, $siteId));
            $sql     .= ' WHEN ? THEN ' . $one['sql'];
            $params[] = $zone;
            $params   = array_merge($params, $one['params']);
        }
        $sql   .= ' ELSE ' . $own['sql'] . ' END) = 1';
        $params = array_merge($params, $own['params']);

        return ['sql' => $sql, 'types' => str_repeat('s', count($params)), 'params' => $params];
    }

    /**
     * The stored READINGS, on `$zone`'s clock, whose instant is at or after
     * `$atUtc` -- as a list of ranges `[from, until)` of `Y-m-d H:i:s` text,
     * the last with `until` null ("and every reading after it"). Public and
     * given the moment, so a test can drive it on the nights a clock changes;
     * `startsAtOrAfter()` is its one caller in the portal.
     *
     * "The instant" of a reading is `I18n::wallClockInstant()`'s, ASKED, never
     * re-derived, so this agrees with the `startsAt` the public door prints:
     * a reading inside the hour a clock skips is moved FORWARD by the gap (RFC
     * 5545's rule), and a reading in the hour a clock repeats is PHP's own
     * pick. That is also why the answer can be MORE than one range: a skipped
     * reading such as 01:30 is the instant of 02:30, so in the hour after a
     * clock goes forward 01:30 can count as "after" while 02:10 does not.
     * Treating the readings as one ordered line would be wrong there.
     *
     * HOW. Read as "seconds, as if the reading were UTC", a reading r has the
     * instant r - o, where o (the offset PHP uses for it) changes only near a
     * clock change. The zone's changes in a window round the moment give the
     * readings where o may change; between two such readings o is constant,
     * which is CHECKED (o is asked at both ends of every piece, and a piece
     * whose ends differ is split in half until they agree), not assumed. In a
     * piece with offset o, "instant >= X" is "r >= X + o".
     *
     * Outside the window the answer is certain: a reading more than two days
     * below "X plus the lowest offset in the window" is before X, and one more
     * than two days above "X plus the highest" is after it, because no zone's
     * offsets, over its whole history, are two days apart: measured over
     * every zone PHP knows, the widest is Pacific/Apia's 25.5 hours (from its
     * local mean time to its summer time after it jumped the date line in
     * 2011); a bare offset such as `+05:00` never changes at all. So the
     * window covers every reading whose answer could depend on which offset
     * applies.
     *
     * @return list<array{0:string, 1:?string}>
     */
    public static function readingsAtOrAfter(\DateTimeZone $zone, int $atUtc): array
    {
        $offsetOf = static function (int $r) use ($zone): int {
            $instant = I18n::wallClockInstant(gmdate('Y-m-d H:i:s', $r), $zone);
            if ($instant === null) {
                throw new \RuntimeException('Events::readingsAtOrAfter(): a reading near the moment could not be placed on its clock.');
            }

            return $r - $instant->getTimestamp();
        };

        $offsets = [(int) $zone->getOffset(new \DateTimeImmutable('@' . $atUtc))];
        $points  = [];
        $states  = $zone->getTransitions($atUtc - 4 * 86400, $atUtc + 4 * 86400);
        if (is_array($states) === true) {
            $before = null;
            foreach ($states as $i => $state) {
                $offsets[] = (int) $state['offset'];
                // The first entry is the state at the window's start, not a
                // change. Each later one is a change at `ts` from `$before`
                // to its offset: the readings `ts + before` and `ts + after`
                // are where the rule for a reading can differ.
                if ($i > 0 && $before !== null) {
                    $points[] = (int) $state['ts'] + $before;
                    $points[] = (int) $state['ts'] + (int) $state['offset'];
                }
                $before = (int) $state['offset'];
            }
        }
        $low    = $atUtc + min($offsets) - 2 * 86400;
        $high   = $atUtc + max($offsets) + 2 * 86400;
        $points = array_values(array_filter($points, static fn (int $p): bool => $p > $low && $p < $high));
        $points[] = $low;
        $points[] = $high;
        sort($points);
        $points = array_values(array_unique($points));

        $ranges = [];
        $piece  = static function (int $from, int $until) use (&$piece, &$ranges, $offsetOf, $atUtc): void {
            if ($until <= $from) {
                return;
            }
            $offset = $offsetOf($from);
            if ($until - $from > 1 && $offsetOf($until - 1) !== $offset) {
                $middle = intdiv($from + $until, 2);
                $piece($from, $middle);
                $piece($middle, $until);
                return;
            }
            $start = max($from, $atUtc + $offset);
            if ($start < $until) {
                $ranges[] = [$start, $until];
            }
        };
        for ($i = 0; $i + 1 < count($points); $i++) {
            $piece($points[$i], $points[$i + 1]);
        }
        // Everything from the window's top up is after the moment.
        $ranges[] = [$high, null];

        // Join touching ranges, so the usual answer is ONE open range.
        $joined = [];
        foreach ($ranges as [$from, $until]) {
            $last = count($joined) - 1;
            if ($last >= 0 && $joined[$last][1] === $from) {
                $joined[$last][1] = $until;
                continue;
            }
            $joined[] = [$from, $until];
        }

        return array_map(
            static fn (array $r): array => [gmdate('Y-m-d H:i:s', $r[0]), $r[1] === null ? null : gmdate('Y-m-d H:i:s', $r[1])],
            $joined
        );
    }

    /**
     * ⏱️ The soonest `$limit` events by their REAL moment, never by their
     *    readings — the ONE place a list that picks "the next N events" across
     *    clocks decides its order (#898 for the public door; #811 for the
     *    rest).
     *
     * WHY. A stored start is a READING on its event's own clock, so
     * `ORDER BY startDateTime` compares readings from DIFFERENT clocks:
     * Auckland 20:00 happens before New York 10:00 on the same date but sorts
     * after it. With a `LIMIT` the sooner event can be the one cut off, and
     * without one the list is simply out of order. A real moment in SQL needs
     * `CONVERT_TZ()`, which shared hosting rarely supports, so the order is
     * decided here, over a window of readings PROVED wide enough:
     *
     *   1. read the first `$limit` rows in READING order;
     *   2. if there were fewer, they are every row: done;
     *   3. otherwise let T be the latest real moment among them. At least
     *      `$limit` rows start at or before T, so every row of the soonest
     *      `$limit` starts at or before T too, and its reading is at most T
     *      plus its clock's offset. So read again every row whose reading is
     *      at most T plus the HIGHEST offset any zone in use has ever had
     *      (`maxOffsetOfZones()`); nothing in the answer can be outside it;
     *   4. order those by the real moment (`I18n::wallClockInstant()`, the
     *      instant the public door prints as `startsAt`), then by reading and
     *      the event's number, and keep the first `$limit`.
     *
     * The second read happens only when there are at least `$limit` rows, and
     * it is bounded by time (about a day past T), not by the calendar's size.
     * A row whose reading cannot be placed on a clock sorts LAST.
     *
     * WHAT THE CALLER GIVES. `$read(?string $upTo, ?int $max)` runs the
     * caller's own statement — with all its own conditions — adding
     * `AND startDateTime <= CAST(? AS DATETIME)` when `$upTo` is not null and
     * `LIMIT ?` when `$max` is not null, ordered by `startDateTime` then
     * `eventID`, and returns the rows. Each row must carry `startDateTime`,
     * `timezone` and `eventID`. Keeping the statement in the caller is what
     * lets a reader that must hold every database read itself (the public
     * door, `check_public_door_reads.py`) use this too.
     *
     * WHAT IT CANNOT DO: page through a list with OFFSET. The window needs the
     * whole head of the list; a paged list (the calendar's list view) still
     * orders by reading within and across pages.
     *
     * @param mysqli   $db     For the zones in use (`zonesInUse()`).
     * @param int|null $siteId One organisation's zones and fallback clock, or
     *                         null for every organisation's zones and the
     *                         request's organisation's clock.
     * @param int      $limit  How many to return (none for less than 1).
     * @param callable(?string, ?int): list<array<string,mixed>> $read
     *
     * @return list<array<string,mixed>> The rows, soonest real moment first.
     */
    public static function soonestByRealStart(mysqli $db, ?int $siteId, int $limit, callable $read): array
    {
        if ($limit < 1) {
            return [];
        }
        $rows = $read(null, $limit);
        if (count($rows) >= $limit) {
            $latest = null;
            foreach ($rows as $row) {
                $m = self::realStartOf($row, $siteId);
                if ($m !== null && ($latest === null || $m > $latest)) {
                    $latest = $m;
                }
            }
            if ($latest !== null) {
                $zones = self::zonesInUse($db, $siteId);
                // When zonesInUse() stopped at its limit, a text it left out
                // might carry the highest offset, so the proof uses two days
                // -- more than any clock has ever been ahead of UTC.
                // ⚠️ NO TEST CAN TELL THIS BRANCH FROM THE ORDINARY ONE. It
                //    is taken whenever the limit is reached (deleted texts
                //    count), but it only changes an answer when a LEFT-OUT
                //    text is ahead of every kept one on a row the caller
                //    lists -- over five hundred live zones -- and no fixture
                //    here builds that, so removing it would leave every test
                //    green.
                $ahead = count($zones) >= self::ZONE_TEXT_LIMIT ? 2 * 86400 : self::maxOffsetOfZones($zones, $siteId);
                $upTo  = gmdate('Y-m-d H:i:s', $latest + $ahead);
                $rows = $read($upTo, null);
            }
        }

        return array_slice(self::sortByRealStart($rows, $siteId), 0, $limit);
    }

    /**
     * Rows ordered by their REAL start, soonest first; ties (and rows with
     * the same moment) by reading, then by the event's number; a row whose
     * reading cannot be placed on a clock goes last. For a list already
     * complete (no `LIMIT` cutting it), so only the order is wrong.
     *
     * @param list<array<string,mixed>> $rows Each with startDateTime, timezone and eventID.
     *
     * @return list<array<string,mixed>>
     */
    public static function sortByRealStart(array $rows, ?int $siteId): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[] = [self::realStartOf($row, $siteId), $row];
        }
        usort($keyed, static function (array $a, array $b): int {
            if ($a[0] !== $b[0]) {
                if ($a[0] === null) {
                    return 1;
                }
                if ($b[0] === null) {
                    return -1;
                }

                return $a[0] <=> $b[0];
            }

            return [(string) $a[1]['startDateTime'], (int) $a[1]['eventID']] <=> [(string) $b[1]['startDateTime'], (int) $b[1]['eventID']];
        });

        return array_map(static fn (array $pair): array => $pair[1], $keyed);
    }

    /**
     * The real moment (UNIX seconds) a row's start reading stands for on its
     * own clock — `I18n::wallClockInstant()`, the instant the public door
     * prints — or null when it cannot be placed. A zone text PHP cannot open
     * reads the organisation's clock (`Site::zoneFor()`), as every page that
     * shows the event does.
     *
     * @param array<string,mixed> $row With startDateTime and timezone.
     */
    public static function realStartOf(array $row, ?int $siteId): ?int
    {
        $instant = I18n::wallClockInstant((string) ($row['startDateTime'] ?? ''), Site::zoneFor((string) ($row['timezone'] ?? ''), $siteId));

        return $instant === null ? null : $instant->getTimestamp();
    }

    /**
     * The HIGHEST offset from UTC, in seconds, that any of these zones (or
     * the organisation's own clock, for a zone PHP cannot open) has EVER had,
     * and never less than 0. Moved here from `PublicDoor` (#811) so the door
     * and every other "next N" list share it.
     *
     * @param list<string> $zoneTexts As `zonesInUse()` returns them.
     */
    public static function maxOffsetOfZones(array $zoneTexts, ?int $siteId): int
    {
        $max = 0;
        foreach (array_merge($zoneTexts, ['']) as $text) {
            $zone   = Site::zoneFor($text, $siteId);
            $states = $zone->getTransitions();
            $max    = max($max, (int) $zone->getOffset(new \DateTimeImmutable('now')));
            if (is_array($states) === true) {
                foreach ($states as $state) {
                    $max = max($max, (int) $state['offset']);
                }
            }
        }

        return $max;
    }

    /**
     * The most distinct zone texts `zonesInUse()` hands back (round 3 of
     * #1024's check); see its 🧮 note.
     */
    public const ZONE_TEXT_LIMIT = 500;

    /**
     * The first and last reading `startAsUtcMoment()` ever works out an
     * offset for. A reading outside is given the offset at the nearer end.
     * Round 2 of #1024's check: one stored date of 9999-12-31 or 0001-01-01
     * -- a draft from the public submission form, an imported or API event,
     * even a deleted one -- took the two-day margin past the years
     * `I18n::wallClockReading()` can read, and the list view and My
     * volunteering threw for the WHOLE organisation. Nothing real happens
     * outside these years, and the end's offset costs such a row at most
     * its own place in the order.
     */
    private const MOMENT_FIRST_READING = '1900-01-01 00:00:00';
    private const MOMENT_LAST_READING  = '2200-12-31 23:59:59';

    /**
     * The most clock changes, over every zone together, that one
     * `startAsUtcMoment()` expression may carry. Each costs one bound value
     * and about seventy characters; round 2 of #1024's check built a
     * 95,567-placeholder, 5.5 MB statement from one event in 9990 with four
     * zones in use, and MySQL refused it. Past this, the window is narrowed
     * round "now" (see `momentWindow()`), so the statement stays bounded.
     */
    private const MOMENT_CHANGE_BUDGET = 2000;

    /**
     * ⏱️ Each event's START as a real moment, in UTC, as an SQL expression
     *    worked out ROW BY ROW inside the database -- the ONE place a query
     *    that PAGES or LIMITS a list of events across clocks gets its order
     *    (#1024, #1025).
     *
     * WHY IT IS NEEDED. `startDateTime` is a READING on its event's own
     * clock (`tblEvents.timezone`, #777), so `ORDER BY startDateTime`
     * compares readings from DIFFERENT clocks: Auckland 20:00 happens about
     * twelve hours before New York 10:00 on the same date, yet sorts after
     * it. `soonestByRealStart()` puts that right for a "next N" list, but it
     * cannot page with OFFSET (it needs the whole head of the list), and the
     * calendar's list view pages. A real moment in SQL would normally be
     * `CONVERT_TZ()` with the zone's name, which needs the database's
     * time-zone tables; shared hosting rarely loads them, and then every
     * value is NULL. So, as `nowOnItsOwnClock()` does for "now", PHP does the
     * zone arithmetic and binds the answers.
     *
     * HOW. A zone's offset from UTC is the same for long stretches of
     * readings and changes only around a clock change. So, for each zone in
     * use, PHP works out the stretches of readings, and the offset in each,
     * over a WINDOW of readings (`momentWindow()`), and the expression is
     *
     *     DATE_SUB(start, INTERVAL (CASE WHEN start < ? THEN o1
     *                                    WHEN start < ? THEN o2 ... ELSE oN END) SECOND)
     *
     * inside a `CASE` on the zone, compared BYTE FOR BYTE as in
     * `nowOnItsOwnClock()` (see its 🔤 note). The offset of a stretch is
     * ASKED of `I18n::wallClockInstant()` -- the instant the public door
     * prints and `realStartOf()` uses -- never re-derived, and it is CHECKED
     * at both ends of every stretch (a stretch whose ends disagree is split
     * in half until they agree), as `readingsAtOrAfter()` does. So a reading
     * in the hour a clock skips is the instant after the gap (RFC 5545's
     * rule) and one in the hour a clock repeats is PHP's own pick: the same
     * moment `sortByRealStart()` would give it.
     *
     * THE WINDOW is the span of start readings of the organisation's events
     * that are not deleted, with two days of margin, held inside 1900-2200,
     * and narrowed by `momentWindow()` if the zones in use would change their
     * clocks more than `MOMENT_CHANGE_BUDGET` times in it. A reading outside
     * the window -- a far-off date, or a row written between the read of the
     * span and the statement -- is given the offset its zone has at the
     * nearer END of the window. How far out that puts such a row is the
     * difference between that offset and the one really in force at the
     * row's own date: an hour for an ordinary summer-time zone, but for a
     * zone whose base offset has changed it is that whole change -- round 3
     * of #1024's check measured a 1990 Pacific/Kiritimati event a full DAY
     * out once the window had narrowed, because Kiritimati moved its clocks
     * by a day in 1994. So a row outside the window can be misplaced among
     * rows up to about a day either side of it. It is never dropped, nothing
     * throws, and the statement stays bounded.
     *
     * NEVER NULL. The reading is held inside 1000-01-03 .. 9999-12-29 before
     * the offset is taken off (round 3: `DATE_SUB('9999-12-31 23:59:59',
     * INTERVAL -28800 SECOND)` for a Los Angeles event is past the last date
     * MySQL holds, answers NULL with warning 1441, and NULL sorts FIRST -- so
     * the list showed that event first and My volunteering gave it one of its
     * thirty places, dropping a real duty). Readings beyond those dates sort
     * by the held value and then by the reading itself.
     *
     * ZONES. Only the texts of events that are not deleted, at most
     * `ZONE_TEXT_LIMIT` of them (`zonesInUse()`); a row whose text is not
     * among them -- or was written after the list was read -- reaches the
     * zone `CASE`'s `ELSE`, the organisation's own clock, and a zone text PHP
     * cannot open reads it too (`Site::zoneFor()`), as every page that shows
     * the event does.
     *
     * The result is a DATETIME in UTC. Order by it, then by `startDateTime`,
     * then by the event's number, which is `sortByRealStart()`'s tie rule.
     * Like `nowOnItsOwnClock()`, it differs row by row, so it cannot use the
     * `startDateTime` index: the database sorts the rows the WHERE kept.
     *
     * A failed read of the zones or the span THROWS under this portal's
     * strict reporting (see the ⚠️ note on `nowOnItsOwnClock()`).
     *
     * @param mysqli   $db     The connection the expression will be used on.
     * @param string   $alias  The table alias for `tblEvents` in that statement.
     * @param int|null $siteId One organisation's zones, span and fallback
     *                         clock, or null for every organisation's.
     *
     * @return array{sql: string, types: string, params: list<string>} Bind
     *         `params` (all text) where `sql` appears.
     */
    public static function startAsUtcMoment(mysqli $db, string $alias, ?int $siteId = null): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Events::startAsUtcMoment(): the alias must be a plain SQL name.');
        }
        $column = '`' . $alias . '`.`startDateTime`';
        $zones  = self::zonesInUse($db, $siteId, true);
        $ownZone = new \DateTimeZone(Site::timezone($siteId));
        $clocks  = [$ownZone];
        foreach ($zones as $zone) {
            $clocks[] = Site::zoneFor($zone, $siteId);
        }
        [$from, $until] = self::momentWindow(self::readingSpan($db, $siteId), $clocks, time());

        $own = self::utcMomentOnOneClock($ownZone, $column, $from, $until);
        if ($zones === []) {
            // No events: a CASE with no WHEN is not SQL (#804's trap).
            return ['sql' => $own['sql'], 'types' => str_repeat('s', count($own['params'])), 'params' => $own['params']];
        }

        $sql    = 'CAST(CASE CAST(`' . $alias . '`.`timezone` AS BINARY)';
        $params = [];
        foreach ($zones as $zone) {
            $one      = self::utcMomentOnOneClock(Site::zoneFor($zone, $siteId), $column, $from, $until);
            $sql     .= ' WHEN ? THEN ' . $one['sql'];
            $params[] = $zone;
            $params   = array_merge($params, $one['params']);
        }
        $sql   .= ' ELSE ' . $own['sql'] . ' END AS DATETIME)';
        $params = array_merge($params, $own['params']);

        return ['sql' => $sql, 'types' => str_repeat('s', count($params)), 'params' => $params];
    }

    /**
     * The window of readings, as "seconds, as if the reading were UTC",
     * that `startAsUtcMoment()` works offsets out over. Exactly:
     *
     *   1. the span with two days of margin each side, held inside
     *      `MOMENT_FIRST_READING` .. `MOMENT_LAST_READING`;
     *   2. then, while the clocks (all of them together, counted with two
     *      days more each side) change more than `MOMENT_CHANGE_BUDGET` times
     *      in it AND it is wider than 732 days, it is HALVED: the new window
     *      is half as wide, centred on `$nowUtc` where that fits inside the
     *      old window, and otherwise pushed against the old window's end
     *      nearer to now. So when now is outside the span the window stays
     *      at the span's end nearer to now, not round now.
     *
     * It stops at the first width of 732 days or less, whatever the count:
     * that is the floor, and the budget can be exceeded there only by very
     * many zones. Public so a test can drive the narrowing with a moment of
     * its choice.
     *
     * @param array{0:int, 1:int}  $span   From `readingSpan()`.
     * @param list<\DateTimeZone> $clocks Every clock the expression will carry.
     *
     * @return array{0:int, 1:int} `[from, until]`, with from <= until.
     */
    public static function momentWindow(array $span, array $clocks, int $nowUtc): array
    {
        $first = (int) I18n::wallClockReading(self::MOMENT_FIRST_READING)?->getTimestamp();
        $last  = (int) I18n::wallClockReading(self::MOMENT_LAST_READING)?->getTimestamp();
        $hold  = static fn (int $r): int => max($first, min($last, $r));
        $from  = $hold($span[0] - 2 * 86400);
        $until = $hold($span[1] + 2 * 86400);
        if ($from > $until) {
            $from = $until;
        }

        $changes = static function (int $a, int $b) use ($clocks): int {
            $n = 0;
            foreach ($clocks as $clock) {
                $states = $clock->getTransitions($a - 2 * 86400, $b + 2 * 86400);
                $n     += is_array($states) === true ? max(0, count($states) - 1) : 0;
            }

            return $n;
        };
        $centre = $hold($nowUtc);
        while ($until - $from > 2 * 366 * 86400 && $changes($from, $until) > self::MOMENT_CHANGE_BUDGET) {
            $width = intdiv($until - $from, 2);
            $start = max($from, min($until - $width, $centre - intdiv($width, 2)));
            $from  = $start;
            $until = $start + $width;
        }

        return [$from, $until];
    }

    /**
     * The lowest and highest start READING (as "seconds, as if the reading
     * were UTC") of one organisation's events that are NOT deleted, or of
     * every organisation's; the current moment for both when there are none.
     * Deleted rows are left out because no statement using the expression
     * lists them (round 2 of #1024's check). Each reading is held inside
     * 1900-2200 IN SQL before MIN and MAX are taken (round 3), so a stored
     * value nothing can read -- a zero date from an old backup, year 0 --
     * counts as the nearer end of those years rather than collapsing the
     * whole window to "now".
     *
     * @return array{0:int, 1:int}
     */
    private static function readingSpan(mysqli $db, ?int $siteId): array
    {
        $stmt = $siteId === null
            ? $db->prepare(
                'SELECT MIN(' . self::heldReadingSql('`startDateTime`') . ') AS `low`, MAX(' . self::heldReadingSql('`startDateTime`') . ') AS `high` '
                . 'FROM `tblEvents` WHERE `isDeleted` = 0'
            )
            : $db->prepare(
                'SELECT MIN(' . self::heldReadingSql('`startDateTime`') . ') AS `low`, MAX(' . self::heldReadingSql('`startDateTime`') . ') AS `high` '
                . 'FROM `tblEvents` WHERE `isDeleted` = 0 AND `siteID` = ?'
            );
        $row = null;
        // A backstop only: under strict reporting a failed prepare THROWS.
        if ($stmt !== false) {
            if ($siteId !== null) {
                $stmt->bind_param('i', $siteId);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        // wallClockReading() is built in UTC, which has no transitions, so
        // its timestamp is the reading "as if it were UTC" -- not a moment.
        $asSeconds = static function (mixed $reading): ?int {
            $parsed = I18n::wallClockReading(is_string($reading) === true ? $reading : null);

            return $parsed === null ? null : $parsed->getTimestamp();
        };
        $low  = $asSeconds($row['low'] ?? null);
        $high = $asSeconds($row['high'] ?? null);
        if ($low === null || $high === null) {
            $now = (int) I18n::wallClockReading(gmdate('Y-m-d H:i:s'))?->getTimestamp();

            return [$now, $now];
        }

        return [$low, $high];
    }

    /**
     * `$column` held inside `MOMENT_FIRST_READING` .. `MOMENT_LAST_READING`,
     * as SQL (constants of this class, never anything a person typed).
     */
    private static function heldReadingSql(string $column): string
    {
        return 'LEAST(GREATEST(' . $column . ", CAST('" . self::MOMENT_FIRST_READING . "' AS DATETIME)), CAST('"
            . self::MOMENT_LAST_READING . "' AS DATETIME))";
    }

    /**
     * The `DATE_SUB(...)` half of `startAsUtcMoment()` for ONE clock: the
     * stretches of readings in `[$from, $until]` (a window from
     * `momentWindow()`) with the offset in each, as an SQL expression over
     * `$column`. The first stretch also covers every reading below the
     * window and the last every reading above it.
     *
     * @return array{sql: string, params: list<string>}
     */
    private static function utcMomentOnOneClock(\DateTimeZone $zone, string $column, int $from, int $until): array
    {
        $offsetOf = static function (int $r) use ($zone): int {
            $instant = I18n::wallClockInstant(gmdate('Y-m-d H:i:s', $r), $zone);
            if ($instant === null) {
                // Inside 1900-2200 every reading can be placed; a backstop.
                return (int) $zone->getOffset(new \DateTimeImmutable('@' . $r));
            }

            return $r - $instant->getTimestamp();
        };

        // Where the offset may change: a clock change at the instant `ts`
        // from offset b to offset a is the readings `ts + b` and `ts + a`.
        // The changes are looked for two days wider than the readings,
        // because a reading and its instant are never more than about 26
        // hours apart.
        $points = [$from, $until];
        $states = $zone->getTransitions($from - 2 * 86400, $until + 2 * 86400);
        if (is_array($states) === true) {
            $before = null;
            foreach ($states as $i => $state) {
                if ($i > 0 && $before !== null) {
                    $points[] = (int) $state['ts'] + $before;
                    $points[] = (int) $state['ts'] + (int) $state['offset'];
                }
                $before = (int) $state['offset'];
            }
        }
        $points = array_values(array_unique(array_filter($points, static fn (int $p): bool => $p >= $from && $p <= $until)));
        sort($points);

        // Stretches ending at a reading (exclusive), each with one offset,
        // CHECKED at both ends; neighbours with the same offset are joined.
        $stretches = [];
        $piece = static function (int $a, int $b) use (&$piece, &$stretches, $offsetOf): void {
            if ($b <= $a) {
                return;
            }
            $offset = $offsetOf($a);
            if ($b - $a > 1 && $offsetOf($b - 1) !== $offset) {
                $middle = intdiv($a + $b, 2);
                $piece($a, $middle);
                $piece($middle, $b);
                return;
            }
            $last = count($stretches) - 1;
            if ($last >= 0 && $stretches[$last][1] === $offset) {
                $stretches[$last][0] = $b;
                return;
            }
            $stretches[] = [$b, $offset];
        };
        for ($i = 0; $i + 1 < count($points); $i++) {
            $piece($points[$i], $points[$i + 1]);
        }
        if ($stretches === []) {
            $stretches[] = [$until, $offsetOf($from)];
        }

        // The offsets are integers PHP worked out, so they are written into
        // the SQL as numbers; the readings where a stretch ends are bound.
        //
        // NEVER NULL (round 3): the offset is taken off a reading held inside
        // 1000-01-03 .. 9999-12-29, so no offset (none reaches 26 hours) can
        // carry it past the dates MySQL holds. Unheld, 9999-12-31 23:59:59
        // in Los Angeles became NULL, and NULL sorts first.
        $held = 'LEAST(GREATEST(' . $column . ", CAST('1000-01-03 00:00:00' AS DATETIME)), CAST('9999-12-29 00:00:00' AS DATETIME))";
        if (count($stretches) === 1) {
            return ['sql' => 'DATE_SUB(' . $held . ', INTERVAL ' . (int) $stretches[0][1] . ' SECOND)', 'params' => []];
        }
        $sql    = 'DATE_SUB(' . $held . ', INTERVAL (CASE';
        $params = [];
        $lastAt = count($stretches) - 1;
        foreach ($stretches as $at => [$end, $offset]) {
            if ($at === $lastAt) {
                $sql .= ' ELSE ' . (int) $offset;
                break;
            }
            $sql     .= ' WHEN ' . $column . ' < CAST(? AS DATETIME) THEN ' . (int) $offset;
            $params[] = gmdate('Y-m-d H:i:s', $end);
        }
        $sql .= ' END) SECOND)';

        return ['sql' => $sql, 'params' => $params];
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
     *    ask again, a bounded number of times. `saveUnderFreeSlug()` below is
     *    that loop for every writer except the calendar file import, which
     *    keeps its own because it also records whether a row was SENT (#877).
     *
     * 🚫 `$refused`: ADDRESSES THE DATABASE HAS ALREADY REFUSED THIS WRITER
     *    (#883). Never offered again, even when the question calls them free.
     *    It matters only INSIDE A TRANSACTION, and there it is the whole
     *    difference: under REPEATABLE READ the question reads the
     *    transaction's own snapshot, which cannot see another writer's row
     *    committed after the snapshot was taken, so it would call the refused
     *    address free again and every retry would send the same address and
     *    be refused the same way. The series pattern page writes inside one
     *    transaction; its real race in `tools/event-address-selftest.php`
     *    goes red without this list. Outside a transaction each question sees
     *    the latest committed rows and the list changes nothing.
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
     * @param string        $stem    What `slugStem()` gave.
     * @param array<string>   $refused Addresses already refused to this
     *                                 writer; never offered again (#883).
     *
     * @return string An address no event in the organisation held when asked.
     */
    public static function freeSlug(mysqli $db, int $siteId, string $stem, array $refused = []): string
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
                if (in_array($candidate, $refused, true) === true) {
                    continue;
                }
                $probe->bind_param('is', $siteId, $candidate);
                $probe->execute();
                if ($probe->get_result()->fetch_row() === null) {
                    return $candidate;
                }
            }
        } finally {
            // A guard, not a measured fault: on MySQL with the connection
            // KILLed, `execute()` threw and `close()` then answered true
            // (measured by the third independent check on #877). The try
            // stays so that IF a close ever throws, it cannot replace the
            // fault that got us here — the import words a lost connection by
            // that fault's code.
            try {
                $probe->close();
            } catch (\Throwable $ignored) {
                // Nothing to do: the statement goes with the request.
            }
        }

        return $stem . '-' . bin2hex(random_bytes(4));
    }

    /**
     * How many times `saveUnderFreeSlug()` sends an event before it gives up
     * because the address it was given was taken each time between asking
     * and saving. The calendar file import's own loop uses the same number.
     */
    public const SLUG_SAVE_TRIES = 3;

    /**
     * Whether a refusal from the database is the unique key on an event's web
     * address (`uq_event_site_slug`) — "another event took this address" —
     * rather than anything else (#883).
     *
     * The ONE place that question is answered for `saveUnderFreeSlug()`'s
     * callers, so each of them can tell an administrator the true reason when
     * the tries run out. Both engines name the key in the message: MySQL as
     * `tblEvents.uq_event_site_slug`, MariaDB as `uq_event_site_slug`, and the
     * test is "contains", which matches both. Any OTHER duplicate key, and any
     * other refusal (a trigger, a broken link to a category), answers false,
     * so it is never retried and never reworded as a taken address.
     */
    public static function slugWasTaken(\Throwable $refusal): bool
    {
        return $refusal instanceof \mysqli_sql_exception
            && (int) $refusal->getCode() === 1062
            && str_contains($refusal->getMessage(), 'uq_event_site_slug') === true;
    }

    /**
     * Save a new event under the first free web address, asking again if
     * another writer takes it between the question and the save (#883).
     *
     * WHY ONE PLACE. Before #883 four places made an event's address, each
     * with its own code: the events API (from the name alone, so a second
     * event with any name already used — a deleted event's included — was
     * refused as a bare 500, which `api-spec.json` even said), the Manage
     * Events form (`-1`, `-2` … with no upper bound and no retry), the series
     * pattern page (its own probe, `-2` … `-50`, no retry) and the public
     * suggestion form (four random characters, never asked of the database).
     * The calendar file import already used `slugStem()` and `freeSlug()`.
     * Copies drift; that is how the import came to differ from the form.
     *
     * HOW. Ask `freeSlug()` for an address; hand it to `$save`, which binds it
     * and SENDS the INSERT; if the database refuses it because the address was
     * taken in between (`slugWasTaken()`), remember that address and ask
     * again, `SLUG_SAVE_TRIES` times in all. The remembered addresses are why
     * this also works inside a transaction — see `freeSlug()`'s `$refused`.
     * Any other refusal is thrown straight back, unchanged, on the first try;
     * so is the address refusal once the tries run out, and the caller words
     * it (`slugWasTaken()` tells it which it has).
     *
     * `$save` MUST THROW when the database refuses. Under the portal's strict
     * reporting `execute()` does; a caller turns an `execute()` that answers
     * false into a `mysqli_sql_exception` itself, so that changing the
     * reporting mode one day cannot open a second, untested path (the
     * calendar file import's precedent).
     *
     * NOT USED BY THE CALENDAR FILE IMPORT, deliberately: its loop also
     * records whether each attempt's INSERT was SENT, which decides what it
     * tells the administrator when the connection is lost (#877, D-099), and
     * its tests pin that loop's text. It asks `slugStem()` and `freeSlug()`
     * like everybody else, with the same number of tries.
     *
     * @param mysqli                  $db     The connection the event is written on.
     * @param int                     $siteId The organisation the event belongs to.
     * @param string                  $stem   What `slugStem()` gave.
     * @param callable(string): void  $save   Binds the address it is given and
     *                                        sends the INSERT; throws on refusal.
     *
     * @return string The address the event was saved under.
     *
     * @throws \mysqli_sql_exception The database's refusal: an address
     *                               refusal only after every try was taken.
     */
    public static function saveUnderFreeSlug(mysqli $db, int $siteId, string $stem, callable $save): string
    {
        $refused = [];
        for ($try = 1; ; $try++) {
            $slug = self::freeSlug($db, $siteId, $stem, $refused);
            try {
                $save($slug);

                return $slug;
            } catch (\mysqli_sql_exception $refusal) {
                if ($try < self::SLUG_SAVE_TRIES && self::slugWasTaken($refusal) === true) {
                    $refused[] = $slug;
                    continue;
                }
                throw $refusal;
            }
        }
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
            // A guard, not a measured fault: on MySQL with the connection
            // KILLed, `execute()` threw and `close()` then answered true
            // (measured by the third independent check on #877). The try
            // stays so that IF a close ever throws, it cannot replace the
            // fault that got us here — the import words a lost connection by
            // that fault's code.
            try {
                $ask->close();
            } catch (\Throwable $ignored) {
                // Nothing to do: the statement goes with the request.
            }
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

    /**
     * 🏷️ Why an event's category or event type cannot be stored, or null when
     *    both may be — the ONE place a writer of `tblEvents.categoryID` /
     *    `typeID` asks (#885).
     *
     * WHAT WAS WRONG. The events API (create and update), the Manage Events
     * form, a series' bulk edit and the public suggestion form each bound a
     * POSTED number straight into the INSERT or UPDATE. The database's link
     * only asks whether SOME row has that number, so:
     *
     *   - a number no row has was refused by the database, and the refusal
     *     escaped as a bare HTTP 500 (the API) or a "not saved" message with
     *     the reason in the error log (the form);
     *   - a number belonging to ANOTHER ORGANISATION's category was ACCEPTED,
     *     linking this organisation's event to a category it cannot see or
     *     manage — and the two different answers told a caller which numbers
     *     exist elsewhere in the installation.
     *
     * NOW a number counts only when it is THIS organisation's, and "another
     * organisation's" and "nobody's" get the SAME sentence, so the answer says
     * nothing about other organisations (the existence-leak rule). A category
     * or type switched off (`isActive = 0`) is still this organisation's and
     * is accepted: an event may already carry one, and refusing it would
     * refuse a save that changes nothing. The CSV import already looks
     * categories and types up by NAME within the organisation, so it never
     * holds a number from outside and does not ask this.
     *
     * Null means "none chosen" and is always allowed. The caller decides how a
     * posted value becomes a number; this judges the number.
     *
     * @param mysqli   $db         The connection.
     * @param int      $siteId     The organisation the event belongs to.
     * @param int|null $categoryId The category to store, or null for none.
     * @param int|null $typeId     The event type to store, or null for none.
     *
     * @return string|null A plain sentence that also names the field
     *                     (`categoryID` or `typeID`) for an API caller, and says
     *                     what to do, without "nothing was saved" (the caller
     *                     knows which verb is true).
     */
    public static function whyCategoryOrTypeRefused(mysqli $db, int $siteId, ?int $categoryId, ?int $typeId): ?string
    {
        $belongs = static function (string $sql, int $id) use ($db, $siteId): bool {
            $ask = $db->prepare($sql);
            if ($ask === false) {
                // Only reachable if the reporting mode ever stops throwing.
                throw new \RuntimeException('the question "is this category or type this organisation\'s" could not be prepared');
            }
            try {
                $ask->bind_param('ii', $id, $siteId);
                $ask->execute();
                $row = $ask->get_result()->fetch_row();
            } finally {
                $ask->close();
            }

            return $row !== null;
        };

        if ($categoryId !== null
            && $belongs('SELECT 1 FROM `tblEventCategories` WHERE `categoryID` = ? AND `siteID` = ? LIMIT 1', $categoryId) === false) {
            return 'The category given (categoryID) is not one of this organisation\'s event categories. Choose one of its own, or none.';
        }
        if ($typeId !== null
            && $belongs('SELECT 1 FROM `tblEventTypes` WHERE `typeID` = ? AND `siteID` = ? LIMIT 1', $typeId) === false) {
            return 'The event type given (typeID) is not one of this organisation\'s event types. Choose one of its own, or none.';
        }

        return null;
    }

    /**
     * 🔢 A posted category or type number, read strictly (#885): null for
     *    "none" (missing, null, empty text or 0), a whole number for a whole
     *    number, and FALSE for anything else — `"abc"`, `1.5`, `true`, a list.
     *
     * `(int)` used to decide this, so `"abc"` became 0 and `[7]` became 1:
     * the second stored a category nobody asked for. A caller refuses false
     * with its own words rather than guessing.
     */
    public static function postedIdOrNone(mixed $raw): int|false|null
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        if (is_int($raw) === true && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) === true && preg_match('/\A[1-9][0-9]{0,9}\z/', $raw) === 1 && (int) $raw <= 2147483647) {
            return (int) $raw;
        }

        return false;
    }
}
