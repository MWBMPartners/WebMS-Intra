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
}
