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
use Portal\Core\Router;
use Portal\Core\Site;

// Every address below is built by Router::url(), so that when organisations
// are told apart by the first part of the address the person lands back in
// their own organisation and not the default one.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . Router::url('admin'), true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$regId   = (int) ($_POST['registrationID'] ?? 0);
$eventId = (int) ($_POST['eventID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');
$reviewerId = (int) ($_SESSION['user_id'] ?? 0);
$siteId  = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}

// 🛡️ The event must belong to the organisation whose address this is.
//
//    WHAT WAS WRONG (12 October 2026): nothing tied the posted event number to
//    this organisation. The rights test above is about the PERSON (an
//    administrator here, or a coordinator of that event); it says nothing
//    about WHERE the event lives. So an administrator of organisation B, on
//    B's own address, could post the numbers of organisation A's event and
//    one of its registrations, and approve or reject it.
//
//    This is the same look-up the list page beside this file uses
//    (registrations.php): this organisation's event, not deleted, not
//    imported. No row gets the very answer a missing registration gets, so
//    the reply does not tell an outsider whether the event exists elsewhere.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$eventHere = $stmt->get_result()->fetch_assoc() !== null;
$stmt->close();
if ($eventHere === false) { http_response_code(404); exit('Registration not found'); }

$actionMap = ['approve' => 'approved', 'reject' => 'rejected', 'waitlist' => 'waitlisted'];
if (isset($actionMap[$action]) === false) {
    header('Location: ' . Router::url('admin/calendar/registrations') . '?eventID=' . $eventId, true, 302); exit();
}
$newStatus = $actionMap[$action];

// 🛡️ Confirm the registration belongs to this event, and the event to this
//    organisation. The organisation is asked for again here and in the UPDATE
//    below on purpose: the write itself then cannot cross organisations even
//    if a later edit removes the test above.
$stmt = $mysqli->prepare(
    'SELECT r.registrationID FROM tblEventRegistrations r '
    . 'JOIN tblEvents e ON e.eventID = r.eventID AND e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
    . 'WHERE r.registrationID = ? AND r.eventID = ?'
);
$stmt->bind_param('iii', $siteId, $regId, $eventId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Registration not found'); }

$stmt = $mysqli->prepare(
    'UPDATE tblEventRegistrations r '
    . 'JOIN tblEvents e ON e.eventID = r.eventID AND e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
    . 'SET r.status = ?, r.reviewedByID = ?, r.reviewedAt = NOW() '
    . 'WHERE r.registrationID = ? AND r.eventID = ?'
);
$stmt->bind_param('isiii', $siteId, $newStatus, $reviewerId, $regId, $eventId);
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
header('Location: ' . Router::url('admin/calendar/registrations') . '?eventID=' . $eventId . '&status=pending', true, 302);
exit();
