<?php
// Path: public_html/admin/workflows/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Workflow Save Handler
 * -----------------------------------------------------------------------------
 * Creates/updates workflows and adds steps (POST only, admin only).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/94
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
$workflowId = (int) ($_POST['workflowID'] ?? 0);
$name       = trim($_POST['workflowName'] ?? '');
$key        = trim($_POST['workflowKey'] ?? '');
$desc       = trim($_POST['description'] ?? '');

if ($name === '' || $key === '') {
    $_SESSION['flash_msg']  = 'Name and key are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/workflows?edit=' . $workflowId);
    exit();
}

if ($workflowId > 0) {
    // 📋 Update existing workflow
    $stmt = $mysqli->prepare(
        'UPDATE tblWorkflows SET workflowName = ?, workflowKey = ?, description = ? WHERE workflowID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('sssii', $name, $key, $desc, $workflowId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('WorkflowUpdated', 'Updated workflow: ' . $name, $userId);
} else {
    // 📋 Create new workflow
    $stmt = $mysqli->prepare(
        'INSERT INTO tblWorkflows (siteID, workflowName, workflowKey, description) VALUES (?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isss', $siteId, $name, $key, $desc);
        $stmt->execute();
        $workflowId = (int) $mysqli->insert_id;
        $stmt->close();
    }
    Logger::activity('WorkflowCreated', 'Created workflow: ' . $name, $userId);
}

// 📋 Add step if provided
$stepName = trim($_POST['stepName'] ?? '');
if ($stepName !== '' && $workflowId > 0) {
    $stepType      = $_POST['stepType'] ?? 'approval';
    $assigneeType  = $_POST['assigneeType'] ?? 'role';
    $assigneeValue = trim($_POST['assigneeValue'] ?? '') !== '' ? trim($_POST['assigneeValue'] ?? '') : null;
    $timeoutHours  = trim($_POST['timeoutHours'] ?? '') !== '' ? (int) $_POST['timeoutHours'] : null;

    // 📋 Determine next step order
    $maxStmt = $mysqli->prepare('SELECT MAX(stepOrder) AS mx FROM tblWorkflowSteps WHERE workflowID = ?');
    $nextOrder = 1;
    if ($maxStmt !== false) {
        $maxStmt->bind_param('i', $workflowId);
        $maxStmt->execute();
        $nextOrder = (int) ($maxStmt->get_result()->fetch_assoc()['mx'] ?? 0) + 1;
        $maxStmt->close();
    }

    $sStmt = $mysqli->prepare(
        'INSERT INTO tblWorkflowSteps (workflowID, stepOrder, stepName, stepType, assigneeType, assigneeValue, timeoutHours) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if ($sStmt !== false) {
        $sStmt->bind_param('iissssi', $workflowId, $nextOrder, $stepName, $stepType, $assigneeType, $assigneeValue, $timeoutHours);
        $sStmt->execute();
        $sStmt->close();
    }
    Logger::activity('WorkflowStepAdded', 'Added step "' . $stepName . '" to workflow: ' . $name, $userId);
}

$_SESSION['flash_msg']  = 'Workflow saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/workflows?edit=' . $workflowId);
exit();
