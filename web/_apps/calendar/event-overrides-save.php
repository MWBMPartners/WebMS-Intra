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
use Portal\Core\GeoLocation;
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

// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
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

    // 📍 #456 Chunk A — hand-entered only, NULL = inherit the parent
    // event's own coords/W3W. Invalid non-empty W3W is rejected here
    // (nothing else in this handler has side effects yet).
    $overrideCoords = GeoLocation::validateCoords($_POST['overrideGeoLat'] ?? null, $_POST['overrideGeoLng'] ?? null);
    $overrideW3WRaw = trim((string) ($_POST['overrideW3W'] ?? ''));
    $overrideW3W = null;
    if ($overrideW3WRaw !== '') {
        $overrideW3W = GeoLocation::validateW3W($overrideW3WRaw);
        if ($overrideW3W === null) {
            $_SESSION['flash_msg']  = t('location.w3w_invalid');
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $redirect, true, 302); exit();
        }
    }

    $cancelled = $mode === 'cancel' ? 1 : 0;
    $nameArg   = $name !== ''   ? $name   : null;
    $locArg    = $loc !== ''    ? $loc    : null;
    $notesArg  = $notes !== ''  ? $notes  : null;
    $startArg  = $startT !== '' ? $startT : null;
    $endArg    = $endT !== ''   ? $endT   : null;
    $overrideLatArg = $overrideCoords['lat'] ?? null;
    $overrideLngArg = $overrideCoords['lng'] ?? null;

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventOccurrenceOverrides '
        . '(eventID, occurrenceDate, isCancelled, overrideName, overrideStartTime, overrideEndTime, overrideLocation, '
        . 'overrideGeoLat, overrideGeoLng, overrideW3W, notes, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE isCancelled = VALUES(isCancelled), overrideName = VALUES(overrideName), '
        . '                       overrideStartTime = VALUES(overrideStartTime), overrideEndTime = VALUES(overrideEndTime), '
        . '                       overrideLocation = VALUES(overrideLocation), overrideGeoLat = VALUES(overrideGeoLat), '
        . '                       overrideGeoLng = VALUES(overrideGeoLng), overrideW3W = VALUES(overrideW3W), notes = VALUES(notes)'
    );
    // 📍 #456 Chunk A: 9 -> 12 placeholders/vars (+overrideGeoLat[d], +overrideGeoLng[d], +overrideW3W[s]).
    $stmt->bind_param(
        'isissssddssi',
        $eventId, $date, $cancelled, $nameArg, $startArg, $endArg, $locArg,
        $overrideLatArg, $overrideLngArg, $overrideW3W, $notesArg, $userId
    );
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
