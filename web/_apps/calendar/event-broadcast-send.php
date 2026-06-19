<?php
// Path: _apps/calendar/event-broadcast-send.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event broadcast send handler (#350)
 * -----------------------------------------------------------------------------
 * POST. Resolves the segment to a recipient userID list, fetches emails,
 * sends via the existing Mailer. Records one tblEventBroadcasts row for
 * audit.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/350
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /calendar', true, 302); exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$eventId = (int) ($_POST['eventID'] ?? 0);
$segment = (string) ($_POST['segment'] ?? '');
$subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 255);
$body    = trim((string) ($_POST['body'] ?? ''));
$senderId = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}
if ($subject === '' || $body === '') {
    $_SESSION['flash_msg']  = 'Subject and message are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/event/broadcast?eventID=' . $eventId, true, 302);
    exit();
}

// 🛡️ Confirm event belongs to this site.
$stmt = $mysqli->prepare('SELECT eventID, eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found'); }

// 📋 Resolve segment → SELECT statement returning DISTINCT (email, fullName).
$query = null;
$params = [];
$types  = '';

if ($segment === 'all-rsvps') {
    $query = 'SELECT DISTINCT u.email, u.fullName FROM tblEventRSVPs r '
           . 'JOIN tblUsers u ON u.userID = r.userID '
           . 'WHERE r.eventID = ? AND r.response = "going" AND r.status = "confirmed" '
           . '  AND u.email IS NOT NULL AND u.email != ""';
    $params = [$eventId]; $types = 'i';
} elseif ($segment === 'all-volunteers') {
    $query = 'SELECT DISTINCT u.email, u.fullName FROM tblEventCrewMembers m '
           . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
           . 'JOIN tblUsers u ON u.userID = m.userID '
           . 'WHERE c.eventID = ? AND u.email IS NOT NULL AND u.email != "" '
           . 'UNION '
           . 'SELECT DISTINCT u.email, u.fullName FROM tblEventJobAssignments a '
           . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
           . 'JOIN tblUsers u ON u.userID = a.userID '
           . 'WHERE j.eventID = ? AND u.email IS NOT NULL AND u.email != ""';
    $params = [$eventId, $eventId]; $types = 'ii';
} elseif (preg_match('/^crew:(\d+)$/', $segment, $m) === 1) {
    $crewId = (int) $m[1];
    $query = 'SELECT DISTINCT u.email, u.fullName FROM tblEventCrewMembers cm '
           . 'JOIN tblEventCrews c ON c.crewID = cm.crewID '
           . 'JOIN tblUsers u ON u.userID = cm.userID '
           . 'WHERE c.eventID = ? AND cm.crewID = ? AND u.email IS NOT NULL AND u.email != ""';
    $params = [$eventId, $crewId]; $types = 'ii';
} elseif (preg_match('/^job:(\d+)$/', $segment, $m) === 1) {
    $jobId = (int) $m[1];
    $query = 'SELECT DISTINCT u.email, u.fullName FROM tblEventJobAssignments a '
           . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
           . 'JOIN tblUsers u ON u.userID = a.userID '
           . 'WHERE j.eventID = ? AND a.jobID = ? AND u.email IS NOT NULL AND u.email != ""';
    $params = [$eventId, $jobId]; $types = 'ii';
}

if ($query === null) {
    $_SESSION['flash_msg']  = 'Invalid segment.'; $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/event/broadcast?eventID=' . $eventId, true, 302); exit();
}

$recipients = [];
$stmt = $mysqli->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $recipients[] = ['email' => (string) $r['email'], 'name' => (string) $r['fullName']];
}
$stmt->close();

// 📧 Send. v1 sends individually (one Mailer::send per recipient) — fine
//     for the ≤200 recipients typical of a single church/VBS broadcast.
//     v1.1 batches via BCC + a real queue for larger blasts.
$bodyHtml = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
$prefix = '<p>' . htmlspecialchars('Re: ' . $event['eventName'], ENT_QUOTES, 'UTF-8') . '</p>';
$bodyHtml = $prefix . $bodyHtml;

$sent = 0;
foreach ($recipients as $rcp) {
    if (filter_var($rcp['email'], FILTER_VALIDATE_EMAIL) === false) {
        continue;
    }
    if (class_exists(Mailer::class) === true && method_exists(Mailer::class, 'send') === true) {
        $ok = Mailer::send($rcp['email'], $subject, $bodyHtml);
        if ($ok === true) { $sent++; }
    }
}

// 📋 Audit row.
$stmt = $mysqli->prepare(
    'INSERT INTO tblEventBroadcasts (eventID, sentByID, segment, subject, body, recipientCount) '
    . 'VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('iisssi', $eventId, $senderId, $segment, $subject, $body, $sent);
$stmt->execute();
$stmt->close();

Logger::activity('EventBroadcastSent', 'Event #' . $eventId . ' segment=' . $segment . ' recipients=' . $sent);

$_SESSION['flash_msg']  = 'Sent to ' . $sent . ' recipient' . ($sent === 1 ? '' : 's') . '.';
$_SESSION['flash_type'] = 'success';
header('Location: /calendar/event/broadcast?eventID=' . $eventId, true, 302);
exit();
