<?php
// Path: public_html/calendar/rsvp.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — RSVP Save Handler
 * -----------------------------------------------------------------------------
 * Handles event RSVP submissions (going, maybe, not_going, cancel).
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/88
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /calendar');
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar');
    exit();
}

$eventId  = (int) ($_POST['eventID'] ?? 0);
$response = $_POST['response'] ?? '';
$slug     = trim($_POST['slug'] ?? '');
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();

$redirect = '/calendar' . ($slug !== '' ? '/event?slug=' . urlencode($slug) : '');

// 🔍 Validate
$validResponses = ['going', 'maybe', 'not_going', 'cancel'];
if ($eventId <= 0 || in_array($response, $validResponses, true) === false) {
    $_SESSION['flash_msg']  = 'Invalid RSVP request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect);
    exit();
}

// 🔍 Verify event exists and belongs to site
$evStmt = $mysqli->prepare(
    'SELECT eventID, eventName, capacity FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($evStmt === false) {
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect);
    exit();
}
$evStmt->bind_param('ii', $eventId, $siteId);
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
    $delStmt = $mysqli->prepare('DELETE FROM tblEventRSVPs WHERE eventID = ? AND userID = ?');
    if ($delStmt !== false) {
        $delStmt->bind_param('ii', $eventId, $userId);
        $delStmt->execute();
        $delStmt->close();
    }
    Logger::activity('EventRSVPCancelled', 'Cancelled RSVP for: ' . $event['eventName'], $userId);
    $_SESSION['flash_msg']  = 'RSVP cancelled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: ' . $redirect);
    exit();
}

// 🤝 Guest +N count (#334) — how many guests is the responder bringing?
//     Clamped to a reasonable cap so a typo doesn't flood the waitlist.
$guestCount = max(0, min(20, (int) ($_POST['guestCount'] ?? 0)));

// 🔍 Check capacity + decide confirmed vs waitlist (#334).
$rsvpStatus = 'confirmed';
$waitlistFlash = '';
if ($event['capacity'] !== null && $response === 'going') {
    // Count confirmed seats already taken (excluding this user's existing row).
    $capStmt = $mysqli->prepare(
        'SELECT COALESCE(SUM(1 + guestCount), 0) AS seats '
        . 'FROM tblEventRSVPs '
        . 'WHERE eventID = ? AND response = "going" AND status = "confirmed" AND userID != ?'
    );
    $seatsTaken = 0;
    if ($capStmt !== false) {
        $capStmt->bind_param('ii', $eventId, $userId);
        $capStmt->execute();
        $seatsTaken = (int) ($capStmt->get_result()->fetch_assoc()['seats'] ?? 0);
        $capStmt->close();
    }
    $seatsWanted = 1 + $guestCount;
    $cap         = (int) $event['capacity'];
    if ($seatsTaken + $seatsWanted > $cap) {
        // 📋 Auto-waitlist instead of rejecting outright — user gets a slot when
        //     someone above drops out. Chronological promotion happens in v1.1
        //     (settings hook + cron will sweep waitlistedAt order).
        $rsvpStatus = 'waitlist';
        $waitlistFlash = ' Capacity reached — you are on the waitlist.';
    }
}

// 📋 Upsert RSVP — bumps to confirmed/waitlist as appropriate.
$stmt = $mysqli->prepare(
    'INSERT INTO tblEventRSVPs (eventID, userID, siteID, response, guestCount, status, waitlistedAt) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ' . ($rsvpStatus === 'waitlist' ? 'NOW()' : 'NULL') . ') '
    . 'ON DUPLICATE KEY UPDATE response = VALUES(response), guestCount = VALUES(guestCount), '
    . '                         status = VALUES(status), waitlistedAt = VALUES(waitlistedAt), updatedAt = NOW()'
);
if ($stmt !== false) {
    $stmt->bind_param('iiisis', $eventId, $userId, $siteId, $response, $guestCount, $rsvpStatus);
    $stmt->execute();
    $stmt->close();
}

$labels = ['going' => 'Going', 'maybe' => 'Maybe', 'not_going' => 'Not going'];
Logger::activity('EventRSVP', 'RSVP ' . ($labels[$response] ?? $response) . ' (+' . $guestCount . ', ' . $rsvpStatus . ') for: ' . $event['eventName'], $userId);

$_SESSION['flash_msg']  = 'RSVP saved — ' . ($labels[$response] ?? $response) . '.' . $waitlistFlash;
$_SESSION['flash_type'] = $rsvpStatus === 'waitlist' ? 'warning' : 'success';
header('Location: ' . $redirect);
exit();
