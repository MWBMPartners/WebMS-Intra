<?php
// Path: _apps/admin/calendar/event-orgs-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin/Coordinator — Event org picker POST handler (#332)
 * -----------------------------------------------------------------------------
 * action=add    : INSERT a tblEventOrgs row
 * action=remove : DELETE the row
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/332
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

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}

$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$redirect = '/admin/calendar/event-orgs?eventID=' . $eventId;

if ($action === 'add') {
    $orgName   = mb_substr(trim((string) ($_POST['orgName'] ?? '')), 0, 255);
    $orgUrl    = mb_substr(trim((string) ($_POST['orgUrl']  ?? '')), 0, 500);
    $isPrimary = (int) ($_POST['isPrimary'] ?? 1) === 1 ? 1 : 0;
    if ($orgName === '') { header('Location: ' . $redirect, true, 302); exit(); }
    $orgUrlArg = $orgUrl !== '' && filter_var($orgUrl, FILTER_VALIDATE_URL) !== false ? $orgUrl : null;

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventOrgs (eventID, orgName, orgUrl, isPrimary, sortOrder) '
        . 'VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sortOrder),0)+1 FROM (SELECT * FROM tblEventOrgs) o WHERE o.eventID = ?))'
    );
    $stmt->bind_param('issii', $eventId, $orgName, $orgUrlArg, $isPrimary, $eventId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventOrgAdded', 'Event #' . $eventId . ' org "' . $orgName . '" primary=' . $isPrimary);
} elseif ($action === 'remove') {
    $rowId = (int) ($_POST['eventOrgID'] ?? 0);
    if ($rowId <= 0) { header('Location: ' . $redirect, true, 302); exit(); }
    $stmt = $mysqli->prepare('DELETE FROM tblEventOrgs WHERE eventOrgID = ? AND eventID = ?');
    $stmt->bind_param('ii', $rowId, $eventId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventOrgRemoved', 'Org row #' . $rowId . ' (event #' . $eventId . ')');
}

header('Location: ' . $redirect, true, 302); exit();
