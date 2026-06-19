<?php
// Path: _apps/calendar/event-overrides-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Override save/remove POST (#333)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /calendar', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$eventId = (int) ($_POST['eventID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}

$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$redirect = '/calendar/event/overrides?eventID=' . $eventId;

if ($action === 'add') {
    $date    = trim((string) ($_POST['occurrenceDate'] ?? ''));
    $mode    = (string) ($_POST['mode'] ?? 'override');
    $name    = mb_substr(trim((string) ($_POST['overrideName'] ?? '')), 0, 255);
    $startT  = trim((string) ($_POST['overrideStartTime'] ?? ''));
    $endT    = trim((string) ($_POST['overrideEndTime'] ?? ''));
    $loc     = mb_substr(trim((string) ($_POST['overrideLocation'] ?? '')), 0, 255);
    $notes   = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $_SESSION['flash_msg']  = 'Invalid date.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect, true, 302); exit();
    }
    if ($startT !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startT) !== 1) { $startT = ''; }
    if ($endT   !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endT)   !== 1) { $endT   = ''; }

    $cancelled = $mode === 'cancel' ? 1 : 0;
    $nameArg   = $name !== ''   ? $name   : null;
    $locArg    = $loc !== ''    ? $loc    : null;
    $notesArg  = $notes !== ''  ? $notes  : null;
    $startArg  = $startT !== '' ? $startT : null;
    $endArg    = $endT !== ''   ? $endT   : null;

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventOccurrenceOverrides '
        . '(eventID, occurrenceDate, isCancelled, overrideName, overrideStartTime, overrideEndTime, overrideLocation, notes, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE isCancelled = VALUES(isCancelled), overrideName = VALUES(overrideName), '
        . '                       overrideStartTime = VALUES(overrideStartTime), overrideEndTime = VALUES(overrideEndTime), '
        . '                       overrideLocation = VALUES(overrideLocation), notes = VALUES(notes)'
    );
    $stmt->bind_param('isissssi', $eventId, $date, $cancelled, $nameArg, $startArg, $endArg, $locArg, $notesArg, $userId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventOccurrenceOverride', 'Event #' . $eventId . ' date=' . $date . ' cancelled=' . $cancelled);
} elseif ($action === 'remove') {
    $rowId = (int) ($_POST['overrideID'] ?? 0);
    if ($rowId > 0) {
        $stmt = $mysqli->prepare('DELETE FROM tblEventOccurrenceOverrides WHERE overrideID = ? AND eventID = ?');
        $stmt->bind_param('ii', $rowId, $eventId);
        $stmt->execute();
        $stmt->close();
        Logger::activity('EventOccurrenceOverrideRemoved', 'Override #' . $rowId);
    }
}

header('Location: ' . $redirect, true, 302); exit();
