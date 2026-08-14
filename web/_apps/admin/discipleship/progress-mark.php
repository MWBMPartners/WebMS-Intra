<?php
// Path: _apps/admin/discipleship/progress-mark.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship step mark-complete / unmark POST 📖 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * POST /admin/discipleship/progress/mark
 *
 * Two actions on the same handler:
 *   • complete — INSERT … ON DUPLICATE KEY UPDATE (revokedAt = NULL,
 *                revokedByID = NULL, source = 'manual', markedByID = ?,
 *                notes = ?). Re-marking a REVOKED row revives the SAME
 *                row via UNIQUE(stepID, userID) — it never duplicates,
 *                and any prior auto-completion `autoRef` evidence is left
 *                untouched (only the source/marker/notes update).
 *   • revoke   — UPDATE SET revokedAt = NOW(), revokedByID = ?. Never a
 *                DELETE — see migration 153 header comment: a deleted row
 *                would let the auto-sweep silently resurrect a step a
 *                coordinator deliberately unmarked. "Complete" everywhere
 *                means `revokedAt IS NULL`.
 *
 * Both actions re-verify the step belongs to a pathway of the active site
 * AND that the target user holds an enrolment for that pathway before
 * touching any row, then call `Discipleship::refreshEnrolmentStatuses()`
 * so the enrolment's completed/active status reflects the new state
 * immediately (not just on the next lazy sweep).
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
use Portal\Core\Discipleship;
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
$stepId      = (int) ($_POST['stepID'] ?? 0);
$targetId    = (int) ($_POST['userID'] ?? 0);
$notes       = trim((string) ($_POST['notes'] ?? ''));
if (strlen($notes) > 500) { $notes = substr($notes, 0, 500); }
$notesVal = $notes === '' ? null : $notes;

$redirect = '/admin/discipleship/progress/member?pathway=' . $pathwayId . '&user=' . $targetId;

if ($pathwayId <= 0 || $stepId <= 0 || $targetId <= 0 || in_array($action, ['complete', 'revoke'], true) === false) {
    http_response_code(400);
    exit('Invalid request');
}

// 🛡️ The step must belong to a pathway of the active site, AND the target
// user must hold an enrolment for that pathway — otherwise a forged
// stepID/userID pair could write into another site's or another member's
// progress.
$stmt = $db->prepare(
    'SELECT s.stepID FROM tblPathwaySteps s '
    . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID AND p.pathwayID = ? AND p.siteID = ? '
    . 'JOIN tblPathwayEnrolments en ON en.pathwayID = p.pathwayID AND en.userID = ? '
    . 'WHERE s.stepID = ?'
);
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('iiii', $pathwayId, $siteId, $targetId, $stepId);
$stmt->execute();
$contextOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($contextOk === false) {
    http_response_code(404);
    exit('Step or enrolment not found');
}

if ($action === 'complete') {
    $stmt = $db->prepare(
        'INSERT INTO tblPathwayProgress (siteID, stepID, userID, source, markedByID, notes) '
        . 'VALUES (?, ?, ?, \'manual\', ?, ?) '
        . 'ON DUPLICATE KEY UPDATE revokedAt = NULL, revokedByID = NULL, '
        . '  source = \'manual\', markedByID = VALUES(markedByID), notes = VALUES(notes), '
        . '  completedAt = NOW()'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('iiiis', $siteId, $stepId, $targetId, $adminUserId, $notesVal);
    $stmt->execute();
    $stmt->close();

    Logger::activity(
        'DiscipleshipStepMarked',
        'pathwayID=' . $pathwayId . ' stepID=' . $stepId . ' userID=' . $targetId,
        $adminUserId
    );

    $_SESSION['flash_msg']  = 'Step marked complete.';
    $_SESSION['flash_type'] = 'success';
} else {
    // 🚪 revoke — set revokedAt/revokedByID, never DELETE.
    $stmt = $db->prepare(
        'UPDATE tblPathwayProgress SET revokedAt = NOW(), revokedByID = ? '
        . 'WHERE stepID = ? AND userID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('iiii', $adminUserId, $stepId, $targetId, $siteId);
    $stmt->execute();
    $stmt->close();

    Logger::activity(
        'DiscipleshipStepRevoked',
        'pathwayID=' . $pathwayId . ' stepID=' . $stepId . ' userID=' . $targetId,
        $adminUserId
    );

    $_SESSION['flash_msg']  = 'Step unmarked.';
    $_SESSION['flash_type'] = 'warning';
}

// 🔄 Enrolment status reflects the new progress state immediately.
Discipleship::refreshEnrolmentStatuses($siteId, $pathwayId);

header('Location: ' . $redirect, true, 302);
exit();
