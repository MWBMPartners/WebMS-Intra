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

            // 🛡️ Codex catch-up B1 (20 September 2026): read OUTSIDE the
            //    transaction/lock below — AccountGuard::isSingleOrganisation()
            //    caches its answer for the whole request, so this either
            //    answers from that cache or is the one query that fills
            //    it; keeping it out of the locked section below keeps the
            //    lock as short as possible. Same rule anon-checkin-save.php
            //    already uses for its own membership test.
            $singleOrg = AccountGuard::isSingleOrganisation() === true ? 1 : 0;

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
            $stmt = $db->prepare(
                'SELECT capacity, siteID, eventName FROM tblEvents '
                . "WHERE eventID = ? AND isDeleted = 0 AND status IN ('published', 'postponed') "
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
            //    FIX: require an active account AND the SAME membership
            //    rule anon-checkin-save.php uses for a viewer (word for
            //    word, so #514 can later replace both call sites with one
            //    shared method) — an active membership row for the
            //    event's own organisation, OR, on a single-organisation
            //    installation only, an account with no switched-OFF
            //    membership row at all (the compatibility exception
            //    AccountGuard/anon-checkin.php already use, for accounts
            //    that predate #518 and never got a membership row in the
            //    first place). WHAT THIS CANNOT DO: it cannot tell a
            //    member removed before #518 (row deleted outright) from
            //    an account that never had a row — both look the same to
            //    this query, which is the same limit AccountGuard itself
            //    already accepts. A person excluded by this rule is left
            //    on the waitlist with no email; offboarding cancelling a
            //    leaver's own RSVPs outright is a separate tidy-up, not
            //    done here. ON LOCKING: this `FOR UPDATE` now also locks
            //    the `tblUserSites` rows it examines, in the order tblUsers
            //    then tblUserSites — the same order `offboarding/do.php`
            //    writes them in, and that transaction never touches
            //    `tblEventRSVPs`, so this adds no new deadlock ordering
            //    between the two.
            $stmt = $db->prepare(
                'SELECT r.rsvpID, r.userID, r.guestCount, u.emailAddress AS email, u.fullName '
                . 'FROM tblEventRSVPs r '
                . 'JOIN tblUsers u ON u.userID = r.userID '
                . 'WHERE r.eventID = ? AND r.siteID = ? AND r.response = "going" AND r.status = "waitlist" '
                . '  AND u.isActive = 1 '
                . '  AND ( EXISTS (SELECT 1 FROM tblUserSites ms '
                . '                 WHERE ms.userID = u.userID AND ms.siteID = r.siteID AND ms.isActive = 1) '
                . '        OR ( ? = 1 AND NOT EXISTS (SELECT 1 FROM tblUserSites mx '
                . '                                     WHERE mx.userID = u.userID AND mx.siteID = r.siteID '
                . '                                       AND mx.isActive = 0) ) ) '
                . 'ORDER BY r.waitlistedAt ASC, r.createdAt ASC '
                . 'FOR UPDATE'
            );
            if ($stmt !== false) {
                $stmt->bind_param('iii', $eventId, $siteId, $singleOrg);
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
