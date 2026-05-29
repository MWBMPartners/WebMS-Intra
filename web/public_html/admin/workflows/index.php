<?php
// Path: public_html/admin/workflows/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Workflow Management
 * -----------------------------------------------------------------------------
 * Lists and manages configurable workflows and their steps. Admin only.
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
use Portal\Core\I18n;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = t('error.access_denied_inline');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /dashboard');
    exit();
}

$siteId = Site::id();

// 🔍 Editing mode?
$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
$editSteps = [];

if ($editId !== null && $editId > 0) {
    $eStmt = $mysqli->prepare('SELECT * FROM tblWorkflows WHERE workflowID = ? AND siteID = ? LIMIT 1');
    if ($eStmt !== false) {
        $eStmt->bind_param('ii', $editId, $siteId);
        $eStmt->execute();
        $editing = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();
    }

    if ($editing !== null) {
        $sStmt = $mysqli->prepare(
            'SELECT * FROM tblWorkflowSteps WHERE workflowID = ? ORDER BY stepOrder'
        );
        if ($sStmt !== false) {
            $sStmt->bind_param('i', $editId);
            $sStmt->execute();
            $sResult = $sStmt->get_result();
            while ($sRow = $sResult->fetch_assoc()) {
                $editSteps[] = $sRow;
            }
            $sStmt->close();
        }
    }
}

// 📋 Fetch all workflows
$workflows = [];
$wStmt = $mysqli->prepare(
    'SELECT w.*, (SELECT COUNT(*) FROM tblWorkflowSteps s WHERE s.workflowID = w.workflowID) AS stepCount, '
    . '(SELECT COUNT(*) FROM tblWorkflowInstances i WHERE i.workflowID = w.workflowID AND i.status IN (\'pending\',\'in_progress\')) AS activeInstances '
    . 'FROM tblWorkflows w WHERE w.siteID = ? ORDER BY w.workflowName'
);
if ($wStmt !== false) {
    $wStmt->bind_param('i', $siteId);
    $wStmt->execute();
    $wResult = $wStmt->get_result();
    while ($wRow = $wResult->fetch_assoc()) {
        $workflows[] = $wRow;
    }
    $wStmt->close();
}

// 📌 Page metadata
$pageTitle   = 'Workflow Management';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Workflows' => ''];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ⚙️ Workflow Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-diagram-project me-2"></i>Workflows</h1>
    <a href="/admin/workflows?edit=0" class="btn btn-primary">
        <i class="fa-solid fa-plus me-1"></i>New Workflow
    </a>
</div>

<?php if ($editId !== null): ?>
    <!-- 📝 Create / Edit Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo $editing !== null ? 'Edit' : 'New'; ?> Workflow</h5>
        </div>
        <div class="card-body">
            <form method="post" action="/admin/workflows/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="workflowID" value="<?php echo (int) ($editing['workflowID'] ?? 0); ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="workflowName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="workflowName" name="workflowName" required maxlength="100"
                               value="<?php echo htmlspecialchars($editing['workflowName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="workflowKey" class="form-label">Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="workflowKey" name="workflowKey" required maxlength="50"
                               pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
                               value="<?php echo htmlspecialchars($editing['workflowKey'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-5">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="description" name="description" maxlength="255"
                               value="<?php echo htmlspecialchars($editing['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <?php if ($editing !== null && count($editSteps) > 0): ?>
                    <h6 class="mt-4 mb-3">Steps</h6>
                    <div class="portal-data-list mb-3">
                        <div class="portal-data-header">
                            <div class="col-1">Order</div>
                            <div class="col-3">Name</div>
                            <div class="col-2">Type</div>
                            <div class="col-2">Assignee Type</div>
                            <div class="col-2">Assignee</div>
                            <div class="col-2">Timeout</div>
                        </div>
                        <?php foreach ($editSteps as $step): ?>
                            <div class="portal-data-row">
                                <div class="col-1"><?php echo (int) $step['stepOrder']; ?></div>
                                <div class="col-3"><?php echo htmlspecialchars($step['stepName'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="col-2"><span class="badge bg-secondary"><?php echo htmlspecialchars($step['stepType'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                                <div class="col-2"><?php echo htmlspecialchars($step['assigneeType'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="col-2"><?php echo htmlspecialchars($step['assigneeValue'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="col-2"><?php echo $step['timeoutHours'] !== null ? (int) $step['timeoutHours'] . 'h' : '—'; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h6 class="mt-4 mb-3">Add Step</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="stepName" placeholder="Step name" maxlength="100">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="stepType">
                            <option value="approval">Approval</option>
                            <option value="review">Review</option>
                            <option value="notification">Notification</option>
                            <option value="auto">Auto</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="assigneeType">
                            <option value="role">Role</option>
                            <option value="user">User</option>
                            <option value="group">Group</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="assigneeValue" placeholder="Assignee value" maxlength="100">
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control" name="timeoutHours" placeholder="Timeout (h)" min="1">
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save
                    </button>
                    <a href="/admin/workflows" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- 📋 Workflow List -->
<?php if (count($workflows) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No workflows defined yet.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-3">Workflow</div>
            <div class="col-2">Key</div>
            <div class="col-3">Description</div>
            <div class="col-1">Steps</div>
            <div class="col-1">Active</div>
            <div class="col-2 text-end">Actions</div>
        </div>
        <?php foreach ($workflows as $wf): ?>
            <div class="portal-data-row">
                <div class="col-3"><strong><?php echo htmlspecialchars($wf['workflowName'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="col-2"><code><?php echo htmlspecialchars($wf['workflowKey'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div class="col-3 small text-muted"><?php echo htmlspecialchars($wf['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-1"><span class="badge bg-secondary"><?php echo (int) $wf['stepCount']; ?></span></div>
                <div class="col-1">
                    <?php if ((int) $wf['activeInstances'] > 0): ?>
                        <span class="badge bg-primary"><?php echo (int) $wf['activeInstances']; ?> running</span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
                <div class="col-2 text-end">
                    <a href="/admin/workflows?edit=<?php echo (int) $wf['workflowID']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
