<?php
// Path: _apps/calendar/event-crews-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Crew builder POST handler (#343)
 * -----------------------------------------------------------------------------
 * action=addCrew      : INSERT a new crew row
 * action=addMember    : INSERT a crew member (externalName + role)
 * action=removeMember : DELETE the membership row
 *
 * Admin OR per-event coordinator (#341).
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/343
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
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
$action  = (string) ($_POST['action'] ?? '');
$siteId  = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}

// 🛡️ Cross-site guard.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$redirect = '/calendar/event/crews?eventID=' . $eventId;

if ($action === 'addCrew') {
    $name           = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
    $color          = (string) ($_POST['color'] ?? '#5e6ad2');
    $gradesAccepted = mb_substr(trim((string) ($_POST['gradesAccepted'] ?? '')), 0, 100);
    if ($name === '' || preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) !== 1) {
        $_SESSION['flash_msg'] = 'Invalid crew name or colour.'; $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect, true, 302); exit();
    }
    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventCrews (eventID, name, color, gradesAccepted, sortOrder) '
        . 'VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sortOrder),0)+1 FROM (SELECT * FROM tblEventCrews) c2 WHERE c2.eventID = ?))'
    );
    $gradesArg = $gradesAccepted !== '' ? $gradesAccepted : null;
    $stmt->bind_param('isssi', $eventId, $name, $color, $gradesArg, $eventId);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();
    Logger::activity('EventCrewCreated', 'Event #' . $eventId . ' crew "' . $name . '" (#' . $newId . ')');
} elseif ($action === 'addMember') {
    $crewId   = (int) ($_POST['crewID'] ?? 0);
    $extName  = mb_substr(trim((string) ($_POST['externalName'] ?? '')), 0, 120);
    $role     = ((string) ($_POST['role'] ?? 'participant')) === 'leader' ? 'leader' : 'participant';
    if ($crewId <= 0 || $extName === '') {
        header('Location: ' . $redirect, true, 302); exit();
    }
    // 🛡️ Confirm crew belongs to this event.
    $stmt = $mysqli->prepare('SELECT crewID FROM tblEventCrews WHERE crewID = ? AND eventID = ?');
    $stmt->bind_param('ii', $crewId, $eventId);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ok === false) { http_response_code(404); exit('Crew not found'); }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventCrewMembers (crewID, externalName, role) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('iss', $crewId, $extName, $role);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventCrewMemberAdded', 'Crew #' . $crewId . ' + "' . $extName . '" as ' . $role);
} elseif ($action === 'removeMember') {
    $memberId = (int) ($_POST['membershipID'] ?? 0);
    if ($memberId <= 0) { header('Location: ' . $redirect, true, 302); exit(); }
    // 🛡️ Confirm membership belongs to a crew in this event.
    $stmt = $mysqli->prepare(
        'DELETE m FROM tblEventCrewMembers m '
        . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
        . 'WHERE m.membershipID = ? AND c.eventID = ?'
    );
    $stmt->bind_param('ii', $memberId, $eventId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventCrewMemberRemoved', 'Membership #' . $memberId . ' (event #' . $eventId . ')');
}

header('Location: ' . $redirect, true, 302);
exit();
