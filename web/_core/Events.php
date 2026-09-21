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
}
