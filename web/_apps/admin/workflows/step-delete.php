<?php
// Path: public_html/admin/workflows/step-delete.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Workflow Step Delete Handler 🗑️ (#443)
 * -----------------------------------------------------------------------------
 * Deletes a single step from a workflow definition (POST only, admin only,
 * CSRF'd). A mistyped step previously bricked a definition forever — the
 * admin CRUD only ever appended at MAX(stepOrder)+1 with no way to remove one.
 *
 * Guards:
 *   - Site ownership: the step's parent workflow must belong to Site::id()
 *     (mirrors the save.php:88-106 probe).
 *   - Refuses when the parent workflow has any active instance — deleting a
 *     step out from under a running instance would strand it mid-flight.
 *   - tblWorkflowActions.stepID is a RESTRICT FK (no ON DELETE clause) — a
 *     step with recorded history throws \mysqli_sql_exception under this
 *     app's strict mysqli reporting; caught and turned into a friendly
 *     refusal rather than a 500.
 *
 * Step reorder is deliberately out of v1 scope — delete + re-add reaches the
 * same place (steps are ordered by stepOrder, and the admin CRUD's own
 * MAX(stepOrder)+1 append still works after a deletion).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/workflows');
    exit();
}

Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = t('error.access_denied_inline');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /dashboard');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/workflows');
    exit();
}

$siteId     = Site::id();
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$stepId     = (int) ($_POST['stepID'] ?? 0);
$workflowId = (int) ($_POST['workflowID'] ?? 0);

if ($stepId <= 0 || $workflowId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid step.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/workflows');
    exit();
}

// 🛡️ Site-ownership probe — mirrors save.php:88-106. Also confirms the step
// really belongs to the named workflow (an IDOR-style mismatch is refused).
$owns = false;
$stmt = $mysqli->prepare(
    'SELECT 1 FROM tblWorkflowSteps s JOIN tblWorkflows w ON w.workflowID = s.workflowID '
    . 'WHERE s.stepID = ? AND s.workflowID = ? AND w.siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $stepId, $workflowId, $siteId);
    $stmt->execute();
    $owns = ($stmt->get_result()->fetch_row() !== null);
    $stmt->close();
}

if ($owns === false) {
    $_SESSION['flash_msg']  = 'Step not found for this site.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/workflows?edit=' . $workflowId);
    exit();
}

// 🛡️ Refuse when the parent workflow has any active instance — deleting a
// step out from under a running instance would strand it mid-flight.
$activeCount = 0;
$stmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM tblWorkflowInstances WHERE workflowID = ? AND status IN ('pending','in_progress')"
);
if ($stmt !== false) {
    $stmt->bind_param('i', $workflowId);
    $stmt->execute();
    $activeCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

if ($activeCount > 0) {
    $_SESSION['flash_msg']  = 'Cannot delete a step while this workflow has ' . $activeCount . ' active instance(s). Wait for them to complete first.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/workflows?edit=' . $workflowId);
    exit();
}

try {
    $stmt = $mysqli->prepare('DELETE FROM tblWorkflowSteps WHERE stepID = ? AND workflowID = ?');
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare step delete: ' . $mysqli->error);
    }
    $stmt->bind_param('ii', $stepId, $workflowId);
    $stmt->execute();
    $stmt->close();

    Logger::activity('WorkflowStepDeleted', 'Deleted step #' . $stepId . ' from workflow #' . $workflowId, $userId);
    $_SESSION['flash_msg']  = 'Step deleted.';
    $_SESSION['flash_type'] = 'success';
} catch (\mysqli_sql_exception $e) {
    // 🛡️ tblWorkflowActions.stepID is RESTRICT — this step has recorded
    // decision history and can't be removed without destroying an audit
    // trail. Refuse cleanly rather than 500.
    Logger::activity('WorkflowStepDeleteBlocked', 'Step #' . $stepId . ' has recorded history — refused', $userId);
    $_SESSION['flash_msg']  = 'This step has recorded decision history and cannot be deleted.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /admin/workflows?edit=' . $workflowId);
exit();
