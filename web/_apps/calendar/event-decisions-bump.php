<?php
// _apps/calendar/event-decisions-bump.php (#315)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /calendar', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$eventId    = (int) ($_POST['eventID'] ?? 0);
$momentType = (string) ($_POST['momentType'] ?? '');
$delta      = (int) ($_POST['delta'] ?? 1);
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$siteId     = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}
$allowed = ['first-decision','rededication','baptism-request','membership-interest','prayer-request','other'];
if (in_array($momentType, $allowed, true) === false || ($delta !== 1 && $delta !== -1)) {
    http_response_code(400); exit('Invalid input');
}

$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$stmt = $mysqli->prepare(
    'INSERT INTO tblDecisionMoments (eventID, momentType, count, recordedByID) VALUES (?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE count = GREATEST(0, count + ?), recordedByID = VALUES(recordedByID)'
);
$initial = max(0, $delta);
$stmt->bind_param('isiii', $eventId, $momentType, $initial, $userId, $delta);
$stmt->execute();
$stmt->close();

Logger::activity('DecisionMoment', 'Event #' . $eventId . ' ' . $momentType . ' ' . ($delta > 0 ? '+1' : '-1'));

header('Location: /calendar/event/decisions?eventID=' . $eventId, true, 302);
exit();
