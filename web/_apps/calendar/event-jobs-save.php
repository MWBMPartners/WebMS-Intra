<?php
// Path: _apps/calendar/event-jobs-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Job board POST handler (#344)
 * -----------------------------------------------------------------------------
 * action=addJob    : INSERT a job
 * action=assign    : INSERT an assignment (externalName)
 * action=unassign  : DELETE an assignment row
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/344
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

$redirect = '/calendar/event/jobs?eventID=' . $eventId;

if ($action === 'addJob') {
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $cap  = max(1, min(50, (int) ($_POST['capacityNeeded'] ?? 1)));
    $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
    if ($name === '') { header('Location: ' . $redirect, true, 302); exit(); }
    $descArg = $desc !== '' ? $desc : null;
    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventJobs (eventID, name, description, capacityNeeded, sortOrder) '
        . 'VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sortOrder),0)+1 FROM (SELECT * FROM tblEventJobs) j2 WHERE j2.eventID = ?))'
    );
    $stmt->bind_param('issii', $eventId, $name, $descArg, $cap, $eventId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventJobCreated', 'Event #' . $eventId . ' job "' . $name . '" (need ' . $cap . ')');
} elseif ($action === 'assign') {
    $jobId  = (int) ($_POST['jobID'] ?? 0);
    $extNm  = mb_substr(trim((string) ($_POST['externalName'] ?? '')), 0, 120);
    if ($jobId <= 0 || $extNm === '') { header('Location: ' . $redirect, true, 302); exit(); }
    $stmt = $mysqli->prepare('SELECT jobID FROM tblEventJobs WHERE jobID = ? AND eventID = ?');
    $stmt->bind_param('ii', $jobId, $eventId);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ok === false) { http_response_code(404); exit('Job not found'); }
    $stmt = $mysqli->prepare('INSERT INTO tblEventJobAssignments (jobID, externalName) VALUES (?, ?)');
    $stmt->bind_param('is', $jobId, $extNm);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventJobAssigned', 'Job #' . $jobId . ' → ' . $extNm);
} elseif ($action === 'unassign') {
    $assignmentId = (int) ($_POST['assignmentID'] ?? 0);
    if ($assignmentId <= 0) { header('Location: ' . $redirect, true, 302); exit(); }
    $stmt = $mysqli->prepare(
        'DELETE a FROM tblEventJobAssignments a '
        . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
        . 'WHERE a.assignmentID = ? AND j.eventID = ?'
    );
    $stmt->bind_param('ii', $assignmentId, $eventId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventJobUnassigned', 'Assignment #' . $assignmentId . ' (event #' . $eventId . ')');
}

header('Location: ' . $redirect, true, 302);
exit();
