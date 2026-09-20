<?php
// Path: public_html/calendar/rsvp.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — RSVP Save Handler
 * -----------------------------------------------------------------------------
 * Handles event RSVP submissions (going, maybe, not_going, cancel).
 *
 * Waitlist promotion-on-cancel (#334 v1.1 follow-up): whenever this handler
 * frees a confirmed seat — a confirmed 'going' RSVP switching to
 * 'maybe'/'not_going', being cancelled outright, or reducing its
 * guestCount — it calls `Portal\Core\Events::promoteFromWaitlist()`
 * immediately after that write commits, so the earliest-waitlisted RSVP(s)
 * that now fit are auto-confirmed and emailed. See that method's own doc
 * for the full transactional/backoff contract; it NEVER throws, so a
 * promotion failure can never break this handler's own redirect.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/88
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/334
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Events;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ POST only. #512 — Site::url() gives the plain address outside path
// mode, so nothing changes for a portal that does not use it; in path
// mode it adds the organisation's own prefix, which a bare '/calendar'
// here used to drop, sending a path-mode visitor to organisation 1's
// calendar instead of their own.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('calendar'));
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar'));
    exit();
}

$eventId  = (int) ($_POST['eventID'] ?? 0);
$response = $_POST['response'] ?? '';
$slug     = trim($_POST['slug'] ?? '');
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();

// #512 — same reason as the two redirects above: Site::url() carries the
// organisation's own address prefix in path mode, which the old bare
// '/calendar' string here dropped.
$redirect = Site::url('calendar') . ($slug !== '' ? '/event?slug=' . urlencode($slug) : '');

// 🔍 Validate
$validResponses = ['going', 'maybe', 'not_going', 'cancel'];
if ($eventId <= 0 || in_array($response, $validResponses, true) === false) {
    $_SESSION['flash_msg']  = 'Invalid RSVP request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect);
    exit();
}

// 🔍 Verify the event exists, belongs to this site, and may be answered.
//
// 🛡️ Drafts (#503). The same rule as the event's own page (calendar/event.php).
//    Only an event whose status is published, cancelled or postponed can be
//    answered by people in general. A DRAFT only by somebody who can manage
//    events: App::isAdmin(), exactly the check every page under
//    calendar/manage/ makes. For everybody else the lookup finds no row, so the
//    request gets EXACTLY the same "Event not found." as a number that matches
//    no event, and trying numbers one by one does not reveal which drafts exist.
//
//    What was wrong before #503: this only checked that the event existed. A
//    member could RSVP to a draft, and so learn that it existed, by posting
//    eventID=1, 2, 3 and so on. Event numbers simply count upward.
//
// ⏱️ The same database work for every "not found" (Codex review, third round,
//    14 September 2026, brief-503b-r3.txt / codex-503b-r3.txt). App::isAdmin()
//    is asked HERE, before the lookup, for every request that gets this far,
//    and the draft rule is part of the lookup's WHERE clause as the bound
//    yes/no value $canManageFlag. So a refused draft, a deleted event and a
//    number that matches nothing all send the same statements, get the same
//    empty result, and take the same PHP lines to the same redirect and
//    message.
//
//    What was wrong in the first fix: App::isAdmin() was asked after the
//    lookup and only for a draft. Its first use in a request runs the account
//    query (App::user(), web/_core/App.php), and nothing earlier in this
//    handler asks for the account (Auth::requireLogin only looks at the
//    session). So a refused draft cost one more database round trip than a
//    missing number — measured (round 4, 16 September 2026): 17 logged
//    commands for a missing number against 20 for a refused draft (now 20 for
//    both). The same redirect and message either way, but measurably slower.
//
//    ⚠️ What this CANNOT promise: inside MySQL a number that matches a draft
//       row still costs reading that row before it is rejected, which a number
//       matching nothing does not. That is microseconds, not zero. Every
//       request that reaches the lookup now pays for the account query,
//       whatever the number; that depends only on who is asking.
//
//    Tried and rejected: asking App::isAdmin() up front but keeping the status
//    test in PHP after the lookup. The database work would match, but a
//    refused draft would still run PHP lines a missing number skips. Putting
//    the rule in the query makes both an empty result, the same approach as
//    the events detail API (events/api/detail.php) and the invitation page
//    (calendar/rsvp-by-link.php). A fixed or random delay was also rejected: it
//    slows everybody, and random noise can be averaged away.
//
//    Deleted events are refused by the query too (isDeleted = 0). Sign-in is
//    already required at the top (Auth::requireLogin), which covers the event
//    page's rule for events not marked public: members may answer those.
//    Answering a cancelled or postponed event is unchanged by this.
$canManageFlag = App::isAdmin() === true ? 1 : 0;

// 🔓 #531 (20 September 2026): `capacity` is still read here, UNLOCKED, but
//    it is no longer what any capacity DECISION is made against — it is
//    read only so `$event['capacity']` and `$event['eventName']` exist for
//    the "not found" / draft checks above and for the activity-log lines
//    below. The real decision re-reads `capacity` a second time, `FOR
//    UPDATE`, inside the locked transaction further down — see that
//    transaction's own comment for why: an administrator can lower an
//    event's capacity at any moment, and the write has to use whichever
//    value holds at the instant it happens, not whatever this early,
//    unlocked read happened to see.
$evStmt = $mysqli->prepare(
    'SELECT eventID, eventName, capacity FROM tblEvents '
    . 'WHERE eventID = ? AND siteID = ? AND isDeleted = 0 '
    . "AND (status IN ('published', 'cancelled', 'postponed') OR ? = 1) LIMIT 1"
);
if ($evStmt === false) {
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect);
    exit();
}
$evStmt->bind_param('iii', $eventId, $siteId, $canManageFlag);
$evStmt->execute();
$event = $evStmt->get_result()->fetch_assoc();
$evStmt->close();

if ($event === null) {
    $_SESSION['flash_msg']  = 'Event not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect);
    exit();
}

// 📋 Handle cancel (delete RSVP)
if ($response === 'cancel') {
    // 🔒 #531 (20 September 2026): lock the event row FIRST, in its own
    //    transaction — the SAME row `Events::promoteFromWaitlist()` locks
    //    first, before it counts anything.
    //
    //    WHAT WAS WRONG BEFORE: this cancel path read the previous RSVP row
    //    with a plain, unlocked SELECT, then deleted it, with no transaction
    //    around either statement. A promotion for the SAME event, running in
    //    its own transaction because a DIFFERENT seat had just been freed,
    //    could run its whole sweep in the gap between this cancel's read and
    //    its delete. If that promotion happened to confirm THIS row (unlikely
    //    but not impossible — a cancelling 'going' row can itself still be
    //    'confirmed' right up until the DELETE runs), the DELETE then removed
    //    a row the promotion had just relied on, and the seat it freed was
    //    never counted by anybody afterwards — lost until some later,
    //    unrelated write happened to notice the gap.
    //
    //    THE FIX: the read, the delete, and (further down, on the ordinary
    //    answer path) the count-then-upsert all take the SAME event-row lock
    //    first, in their own transaction, before doing anything else. Two
    //    things can no longer run their "read the current state, then act on
    //    it" logic for the same event at the same time — whichever gets the
    //    lock first runs to completion (commits or rolls back) before the
    //    other is even allowed to start reading. Lock order is event row →
    //    RSVP row, matching the order `Events::promoteFromWaitlist()` already
    //    uses, so there is no cycle between the two.
    App::beginTransaction();
    try {
        $lockStmt = $mysqli->prepare('SELECT capacity FROM tblEvents WHERE eventID = ? AND siteID = ? LIMIT 1 FOR UPDATE');
        if ($lockStmt === false) {
            throw new \RuntimeException('prepare failed: ' . $mysqli->error);
        }
        $lockStmt->bind_param('ii', $eventId, $siteId);
        $lockStmt->execute();
        $locked = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if ($locked === null) {
            // The event was deleted by somebody else between the lookup
            // above and this lock — treat it the same as any other database
            // fault: roll back, say nothing was saved, change nothing.
            throw new \RuntimeException('event vanished between lookup and lock');
        }

        // 🔍 Was this a confirmed 'going' seat? Checked BEFORE the DELETE
        // below, now WITH the row locked, so a concurrent promotion cannot
        // change the answer out from under us (#334 v1.1 waitlist
        // promotion-on-cancel) — cancelling a 'maybe'/'not_going'/'waitlist'
        // row never frees capacity.
        $prevStmt = $mysqli->prepare(
            'SELECT response, status FROM tblEventRSVPs WHERE eventID = ? AND userID = ? LIMIT 1 FOR UPDATE'
        );
        if ($prevStmt === false) {
            throw new \RuntimeException('prepare failed: ' . $mysqli->error);
        }
        $prevStmt->bind_param('ii', $eventId, $userId);
        $prevStmt->execute();
        $prevRow = $prevStmt->get_result()->fetch_assoc();
        $prevStmt->close();
        $wasConfirmedGoing = $prevRow !== null
            && (string) $prevRow['response'] === 'going'
            && (string) $prevRow['status'] === 'confirmed';

        $delStmt = $mysqli->prepare('DELETE FROM tblEventRSVPs WHERE eventID = ? AND userID = ?');
        if ($delStmt === false) {
            throw new \RuntimeException('prepare failed: ' . $mysqli->error);
        }
        $delStmt->bind_param('ii', $eventId, $userId);
        $delStmt->execute();
        $delStmt->close();

        App::commit();
    } catch (\Throwable $e) {
        App::rollback();
        Logger::exception($e);
        $_SESSION['flash_msg']  = t('error.database');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect);
        exit();
    }

    Logger::activity('EventRSVPCancelled', 'Cancelled RSVP for: ' . $event['eventName'], $userId);

    // 🎟️ Slot-freeing write — promote the earliest-waitlisted RSVP(s) that
    // now fit. Called AFTER the transaction above has committed; NEVER
    // throws. It opens its OWN transaction (mysqli has no nested
    // transactions), which is why it has to run after this one has already
    // committed, not folded inside it.
    if ($wasConfirmedGoing === true) {
        Events::promoteFromWaitlist($eventId);
    }

    $_SESSION['flash_msg']  = 'RSVP cancelled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: ' . $redirect);
    exit();
}

// 🤝 Guest +N count (#334) — how many guests is the responder bringing?
//     Clamped to a reasonable cap so a typo doesn't flood the waitlist.
//     Read before the transaction opens: it comes from the POST body, not
//     the database, so there is nothing here a lock could protect.
$guestCount = max(0, min(20, (int) ($_POST['guestCount'] ?? 0)));

// 🔒 #531 (20 September 2026): everything from the snapshot read to the
//    upsert now runs inside ONE transaction, behind the SAME event-row lock
//    `Events::promoteFromWaitlist()` and the cancel branch above both take
//    first. See the cancel branch's own comment for the full account of
//    what was wrong before (an ordinary "going" answer counted seats with a
//    plain, unlocked read, so it could count — and then fill — a seat a
//    concurrent promotion had ALSO just counted and was about to fill,
//    overbooking the event by exactly one). Locking here closes that the
//    same way: whichever of "this answer" and "a promotion for this event"
//    gets the lock first runs to completion before the other is even
//    allowed to start counting.
$prevRow    = null;
$rsvpStatus = 'confirmed';
$waitlistFlash = '';

App::beginTransaction();
try {
    // 🔒 The same row Events::promoteFromWaitlist() locks first, and the
    //    cancel branch above now also locks. Re-reading `capacity` HERE,
    //    under the lock, rather than trusting the value the visibility
    //    lookup above already fetched, matters: an administrator can lower
    //    an event's capacity at any moment, and the decision below has to
    //    use whichever value holds at the instant this row is written, not
    //    whatever it was when the page was first requested.
    $lockStmt = $mysqli->prepare('SELECT capacity FROM tblEvents WHERE eventID = ? AND siteID = ? LIMIT 1 FOR UPDATE');
    if ($lockStmt === false) {
        throw new \RuntimeException('prepare failed: ' . $mysqli->error);
    }
    $lockStmt->bind_param('ii', $eventId, $siteId);
    $lockStmt->execute();
    $locked = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();
    if ($locked === null) {
        throw new \RuntimeException('event vanished between lookup and lock');
    }
    $capacityNow = $locked['capacity']; // null = unlimited

    // 🔍 Snapshot this user's EXISTING RSVP (if any), now FOR UPDATE, before
    // the upsert below overwrites it — needed to tell whether this write
    // frees a confirmed seat (#334 v1.1 waitlist promotion-on-cancel). This
    // used to be a plain, unlocked SELECT (:185-194 on the pre-#531 file) —
    // see the transaction's own opening comment for why that was wrong.
    $prevStmt = $mysqli->prepare(
        'SELECT response, status, guestCount FROM tblEventRSVPs WHERE eventID = ? AND userID = ? LIMIT 1 FOR UPDATE'
    );
    if ($prevStmt === false) {
        throw new \RuntimeException('prepare failed: ' . $mysqli->error);
    }
    $prevStmt->bind_param('ii', $eventId, $userId);
    $prevStmt->execute();
    $prevRow = $prevStmt->get_result()->fetch_assoc();
    $prevStmt->close();

    // 🔍 Check capacity + decide confirmed vs waitlist (#334), against the
    // capacity value just re-read under the lock, not the pre-lock one.
    if ($capacityNow !== null && $response === 'going') {
        // Count confirmed seats already taken (excluding this user's own
        // existing row), FOR UPDATE — the same lock
        // Events::promoteFromWaitlist() takes on this same aggregate, so the
        // two can never both count the same free seat any more.
        $capStmt = $mysqli->prepare(
            'SELECT COALESCE(SUM(1 + guestCount), 0) AS seats '
            . 'FROM tblEventRSVPs '
            . 'WHERE eventID = ? AND siteID = ? AND response = "going" AND status = "confirmed" AND userID != ? '
            . 'FOR UPDATE'
        );
        if ($capStmt === false) {
            throw new \RuntimeException('prepare failed: ' . $mysqli->error);
        }
        $capStmt->bind_param('iii', $eventId, $siteId, $userId);
        $capStmt->execute();
        $seatsTaken = (int) ($capStmt->get_result()->fetch_assoc()['seats'] ?? 0);
        $capStmt->close();

        $seatsWanted = 1 + $guestCount;
        $cap         = (int) $capacityNow;
        if ($seatsTaken + $seatsWanted > $cap) {
            // 📋 Auto-waitlist instead of rejecting outright — user gets a slot
            //     when someone above drops out. Chronological
            //     (waitlistedAt-ordered) promotion is event-driven — see
            //     Events::promoteFromWaitlist() below and the file header
            //     note (#334 v1.1).
            $rsvpStatus = 'waitlist';
            $waitlistFlash = ' Capacity reached — you are on the waitlist.';
        }
    }

    // 📋 Upsert RSVP — bumps to confirmed/waitlist as appropriate. Unchanged
    //    statement text from before #531; only WHERE it runs (now inside
    //    this transaction, under the lock above) has changed.
    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, guestCount, status, waitlistedAt) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ' . ($rsvpStatus === 'waitlist' ? 'NOW()' : 'NULL') . ') '
        . 'ON DUPLICATE KEY UPDATE response = VALUES(response), guestCount = VALUES(guestCount), '
        . '                         status = VALUES(status), waitlistedAt = VALUES(waitlistedAt), updatedAt = NOW()'
    );
    if ($stmt === false) {
        throw new \RuntimeException('prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param('iiisis', $eventId, $userId, $siteId, $response, $guestCount, $rsvpStatus);
    $stmt->execute();
    $stmt->close();

    App::commit();
} catch (\Throwable $e) {
    App::rollback();
    Logger::exception($e);
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect);
    exit();
}

$wasConfirmedGoing = $prevRow !== null
    && (string) $prevRow['response'] === 'going'
    && (string) $prevRow['status'] === 'confirmed';
$prevGuestCount = $prevRow !== null ? (int) $prevRow['guestCount'] : 0;

// 🎟️ Slot-freeing write — the user was a confirmed 'going' seat before
// this upsert AND either stopped being one (now 'maybe'/'not_going', or
// bumped back to 'waitlist') OR kept their seat but reduced guestCount.
// Promote the earliest-waitlisted RSVP(s) that now fit. Called AFTER the
// transaction above has committed; NEVER throws (#334 v1.1). It opens its
// OWN transaction (mysqli has no nested transactions), which is why it has
// to run after this one, not folded inside it.
if ($wasConfirmedGoing === true) {
    $stillConfirmedGoing = ($response === 'going' && $rsvpStatus === 'confirmed');
    $guestCountReduced   = $stillConfirmedGoing === true && $guestCount < $prevGuestCount;
    if ($stillConfirmedGoing === false || $guestCountReduced === true) {
        Events::promoteFromWaitlist($eventId);
    }
}

$labels = ['going' => 'Going', 'maybe' => 'Maybe', 'not_going' => 'Not going'];
Logger::activity('EventRSVP', 'RSVP ' . ($labels[$response] ?? $response) . ' (+' . $guestCount . ', ' . $rsvpStatus . ') for: ' . $event['eventName'], $userId);

$_SESSION['flash_msg']  = 'RSVP saved — ' . ($labels[$response] ?? $response) . '.' . $waitlistFlash;
$_SESSION['flash_type'] = $rsvpStatus === 'waitlist' ? 'warning' : 'success';
header('Location: ' . $redirect);
exit();
