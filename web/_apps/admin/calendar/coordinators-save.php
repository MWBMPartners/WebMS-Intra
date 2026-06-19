<?php
// Path: _apps/admin/calendar/coordinators-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Coordinator grant / revoke POST handler 👥 (#341)
 * -----------------------------------------------------------------------------
 * action=grant : looks up userID by email, INSERT … ON DUPLICATE KEY UPDATE
 *                to revive a previously-revoked coordinator if applicable.
 * action=revoke: stamps revokedAt = NOW() on the coordinator row.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/341
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/calendar/coordinators', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('EventCoordSaveRejected', 'Invalid CSRF on coordinators/save');
    http_response_code(400);
    exit('Bad request');
}

$eventId  = (int) ($_POST['eventID'] ?? 0);
$action   = (string) ($_POST['action'] ?? '');
$adminId  = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();
$redirect = '/admin/calendar/coordinators?eventID=' . $eventId;

// 🛡️ Confirm event exists in active site.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) {
    http_response_code(404);
    exit('Event not found');
}

if ($action === 'grant') {
    $email = trim((string) ($_POST['userEmail'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $_SESSION['flash_msg']  = 'Invalid email address.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect, true, 302);
        exit();
    }

    // 🔍 Resolve user by email.
    $stmt = $mysqli->prepare('SELECT userID FROM tblUsers WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        $_SESSION['flash_msg']  = 'No user found with that email. They must have a portal account first.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $redirect, true, 302);
        exit();
    }

    $targetUserId = (int) $row['userID'];

    // 💾 Upsert — if the user was previously revoked, clear revokedAt to revive.
    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventCoordinators (eventID, userID, grantedByID, grantedAt) '
        . 'VALUES (?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE revokedAt = NULL, grantedByID = VALUES(grantedByID), grantedAt = NOW()'
    );
    $stmt->bind_param('iii', $eventId, $targetUserId, $adminId);
    $stmt->execute();
    $stmt->close();

    Logger::activity('EventCoordGranted', 'Event #' . $eventId . ' → user ' . $targetUserId);
    $_SESSION['flash_msg']  = 'Coordinator granted.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'revoke') {
    $coordId = (int) ($_POST['coordinatorID'] ?? 0);
    if ($coordId <= 0) {
        header('Location: ' . $redirect, true, 302);
        exit();
    }
    $stmt = $mysqli->prepare(
        'UPDATE tblEventCoordinators SET revokedAt = NOW() WHERE coordinatorID = ? AND eventID = ?'
    );
    $stmt->bind_param('ii', $coordId, $eventId);
    $stmt->execute();
    $stmt->close();

    Logger::activity('EventCoordRevoked', 'Coordinator #' . $coordId . ' on event #' . $eventId);
    $_SESSION['flash_msg']  = 'Coordinator revoked.';
    $_SESSION['flash_type'] = 'info';
}

header('Location: ' . $redirect, true, 302);
exit();
