<?php
// Path: _apps/admin/calendar/registrations-act.php
/**
 * -----------------------------------------------------------------------------
 * Admin/Coordinator — Registration moderation POST (#347)
 * -----------------------------------------------------------------------------
 * action=approve|reject|waitlist → updates tblEventRegistrations.status,
 * stamps reviewedByID + reviewedAt. Fires
 * calendar.event.registration.<status> webhook.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/347
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$regId   = (int) ($_POST['registrationID'] ?? 0);
$eventId = (int) ($_POST['eventID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');
$reviewerId = (int) ($_SESSION['user_id'] ?? 0);

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}

$actionMap = ['approve' => 'approved', 'reject' => 'rejected', 'waitlist' => 'waitlisted'];
if (isset($actionMap[$action]) === false) {
    header('Location: /admin/calendar/registrations?eventID=' . $eventId, true, 302); exit();
}
$newStatus = $actionMap[$action];

// 🛡️ Confirm registration belongs to this event.
$stmt = $mysqli->prepare('SELECT registrationID FROM tblEventRegistrations WHERE registrationID = ? AND eventID = ?');
$stmt->bind_param('ii', $regId, $eventId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Registration not found'); }

$stmt = $mysqli->prepare(
    'UPDATE tblEventRegistrations SET status = ?, reviewedByID = ?, reviewedAt = NOW() WHERE registrationID = ?'
);
$stmt->bind_param('sii', $newStatus, $reviewerId, $regId);
$stmt->execute();
$stmt->close();

Logger::activity('EventRegistrationModerated', 'Reg #' . $regId . ' → ' . $newStatus);

if (class_exists('\\Portal\\Core\\WebhookDispatcher') === true) {
    \Portal\Core\WebhookDispatcher::emit(
        'calendar.event.registration.' . $newStatus,
        ['eventID' => $eventId, 'registrationID' => $regId, 'reviewerID' => $reviewerId]
    );
}

$_SESSION['flash_msg']  = ucfirst($action) . 'd.';
$_SESSION['flash_type'] = $newStatus === 'approved' ? 'success' : ($newStatus === 'rejected' ? 'danger' : 'info');
header('Location: /admin/calendar/registrations?eventID=' . $eventId . '&status=pending', true, 302);
exit();
