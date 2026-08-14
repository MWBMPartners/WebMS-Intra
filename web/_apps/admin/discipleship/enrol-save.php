<?php
// Path: _apps/admin/discipleship/enrol-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship enrol / withdraw POST 📖 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * POST /admin/discipleship/enrol
 *
 * Two actions on the same handler:
 *   • enrol    — INSERT … ON DUPLICATE KEY UPDATE status = 'active'. The
 *                UNIQUE(pathwayID, userID) key means re-enrolling a
 *                withdrawn member reactivates the SAME row (never a
 *                duplicate) — history (enrolledAt) is preserved.
 *   • withdraw — UPDATE status = 'withdrawn'. Progress rows are left
 *                untouched (historical record); the roster still lists a
 *                withdrawn member so their prior progress remains visible.
 *
 * Every write is cross-site guarded via a JOIN to `tblPathways.siteID`.
 *
 * @package   Portal\App\Admin\Discipleship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/discipleship/progress', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
    http_response_code(400);
    exit('Bad request');
}

$enabled = (string) Settings::get('discipleship.enabled', 'false');
if ($enabled !== '1' && $enabled !== 'true') {
    $_SESSION['flash_msg']  = 'Discipleship app is disabled.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/discipleship/pathways', true, 302);
    exit();
}

$db          = App::db();
$siteId      = Site::id();
$adminUserId = (int) ($_SESSION['user_id'] ?? 0);
$action      = (string) ($_POST['action'] ?? '');
$pathwayId   = (int) ($_POST['pathwayID'] ?? 0);
$targetId    = (int) ($_POST['userID'] ?? 0);

$redirect = '/admin/discipleship/progress/pathway?id=' . $pathwayId;

if ($pathwayId <= 0 || $targetId <= 0 || in_array($action, ['enrol', 'withdraw'], true) === false) {
    http_response_code(400);
    exit('Invalid request');
}

// 🛡️ Cross-site guard — the pathway must belong to the active site.
$stmt = $db->prepare('SELECT pathwayID FROM tblPathways WHERE pathwayID = ? AND siteID = ?');
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('ii', $pathwayId, $siteId);
$stmt->execute();
$pathwayOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($pathwayOk === false) {
    http_response_code(404);
    exit('Pathway not found');
}

if ($action === 'enrol') {
    // 🛡️ Target must be an active member of this site.
    $stmt = $db->prepare(
        'SELECT u.userID FROM tblUsers u '
        . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.userID = ? AND u.isActive = 1'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('ii', $siteId, $targetId);
    $stmt->execute();
    $memberOk = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($memberOk === false) {
        $_SESSION['flash_msg']  = 'That member is not an active member of this site.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect, true, 302);
        exit();
    }

    // 🆕 INSERT … ON DUPLICATE KEY UPDATE — re-enrolling a withdrawn
    // member reactivates the SAME row via UNIQUE(pathwayID, userID).
    $stmt = $db->prepare(
        'INSERT INTO tblPathwayEnrolments (siteID, pathwayID, userID, status, enrolledByID) '
        . 'VALUES (?, ?, ?, \'active\', ?) '
        . 'ON DUPLICATE KEY UPDATE status = \'active\', enrolledByID = VALUES(enrolledByID), completedAt = NULL'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('iiii', $siteId, $pathwayId, $targetId, $adminUserId);
    $stmt->execute();
    $stmt->close();

    Logger::activity(
        'DiscipleshipEnrolled',
        'pathwayID=' . $pathwayId . ' userID=' . $targetId,
        $adminUserId
    );

    $_SESSION['flash_msg']  = 'Member enrolled.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $redirect, true, 302);
    exit();
}

// 🛡️ withdraw — re-verify the enrolment exists at THIS site (the pathway
// check above already confirmed pathwayID belongs to siteID, so this just
// confirms the enrolment row itself exists) before the plain UPDATE.
$stmt = $db->prepare('SELECT enrolmentID FROM tblPathwayEnrolments WHERE pathwayID = ? AND userID = ? AND siteID = ?');
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('iii', $pathwayId, $targetId, $siteId);
$stmt->execute();
$enrolmentOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($enrolmentOk === false) {
    http_response_code(404);
    exit('Enrolment not found');
}

$stmt = $db->prepare('UPDATE tblPathwayEnrolments SET status = \'withdrawn\' WHERE pathwayID = ? AND userID = ? AND siteID = ?');
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('iii', $pathwayId, $targetId, $siteId);
$stmt->execute();
$stmt->close();

Logger::activity(
    'DiscipleshipWithdrawn',
    'pathwayID=' . $pathwayId . ' userID=' . $targetId,
    $adminUserId
);

$_SESSION['flash_msg']  = 'Member withdrawn.';
$_SESSION['flash_type'] = 'warning';
header('Location: ' . $redirect, true, 302);
exit();
