<?php
// Path: public_html/tasks/index.php
/**
 * -----------------------------------------------------------------------------
 * Tasks — Task List & Management
 * -----------------------------------------------------------------------------
 * Lists tasks for the current user with create/edit form. Supports recurring
 * tasks and reminders.
 *
 * @package   Portal\Tasks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/96
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\I18n;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 📌 Page metadata
$pageTitle   = 'Tasks';
$pageSection = 'tasks';
$breadcrumbs = ['Dashboard' => '/', 'Tasks' => ''];

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = App::isAdmin();

// 🔍 Filters
$filterStatus = trim($_GET['status'] ?? '');
$showAll      = isset($_GET['all']) === true && $isAdmin === true;

// 🔍 Editing mode?
$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
if ($editId !== null && $editId > 0) {
    $eStmt = $mysqli->prepare(
        'SELECT * FROM tblTasks WHERE taskID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
    );
    if ($eStmt !== false) {
        $eStmt->bind_param('ii', $editId, $siteId);
        $eStmt->execute();
        $editing = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();
    }
}

// 📋 Build task query
$where  = 'WHERE t.siteID = ? AND t.isDeleted = 0';
$params = [$siteId];
$types  = 'i';

if ($showAll === false) {
    $where  .= ' AND (t.assignedToID = ? OR t.createdByID = ?)';
    $params[] = $userId;
    $params[] = $userId;
    $types   .= 'ii';
}

if ($filterStatus !== '' && in_array($filterStatus, ['pending', 'in_progress', 'completed', 'cancelled'], true) === true) {
    $where  .= ' AND t.status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}

$tasks = [];
$stmt = $mysqli->prepare(
    'SELECT t.*, u.fullName AS assigneeName, c.fullName AS creatorName '
    . 'FROM tblTasks t '
    . 'LEFT JOIN tblUsers u ON u.userID = t.assignedToID '
    . 'LEFT JOIN tblUsers c ON c.userID = t.createdByID '
    . $where . ' ORDER BY t.status = \'completed\' ASC, t.priority = \'urgent\' DESC, '
    . 't.priority = \'high\' DESC, t.dueDate ASC, t.createdAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    $stmt->close();
}

// 📋 Fetch users for assignee dropdown (admin)
$users = [];
if ($isAdmin === true) {
    $uStmt = $mysqli->prepare(
        'SELECT u.userID, u.fullName FROM tblUsers u '
        . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'ORDER BY u.fullName'
    );
    if ($uStmt !== false) {
        $uStmt->bind_param('i', $siteId);
        $uStmt->execute();
        $uResult = $uStmt->get_result();
        while ($uRow = $uResult->fetch_assoc()) {
            $users[] = $uRow;
        }
        $uStmt->close();
    }
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ✅ Task Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-list-check me-2"></i>Tasks</h1>
    <a href="/tasks?edit=0" class="btn btn-primary">
        <i class="fa-solid fa-plus me-1"></i>New Task
    </a>
</div>

<!-- 🔍 Filters -->
<div class="d-flex gap-2 mb-4">
    <a href="/tasks" class="btn btn-sm <?php echo ($filterStatus === '' && $showAll === false ? 'btn-primary' : 'btn-outline-primary'); ?>">My Tasks</a>
    <?php foreach (['pending', 'in_progress', 'completed'] as $fs): ?>
        <a href="/tasks?status=<?php echo $fs; ?>" class="btn btn-sm <?php echo ($filterStatus === $fs ? 'btn-primary' : 'btn-outline-primary'); ?>">
            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $fs)), ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endforeach; ?>
    <?php if ($isAdmin === true): ?>
        <a href="/tasks?all=1" class="btn btn-sm <?php echo ($showAll === true ? 'btn-primary' : 'btn-outline-primary'); ?>">All Tasks</a>
    <?php endif; ?>
</div>

<?php if ($editId !== null): ?>
    <!-- 📝 Create / Edit Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo $editing !== null ? 'Edit Task' : 'New Task'; ?></h5>
        </div>
        <div class="card-body">
            <form method="post" action="/tasks/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="taskID" value="<?php echo (int) ($editing['taskID'] ?? 0); ?>">

                <div class="mb-3">
                    <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required maxlength="255"
                           value="<?php echo htmlspecialchars($editing['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($editing['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label for="priority" class="form-label">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo (($editing['priority'] ?? 'normal') === $p ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars(ucfirst($p), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="dueDate" class="form-label">Due Date</label>
                        <input type="date" class="form-control" id="dueDate" name="dueDate"
                               value="<?php echo htmlspecialchars($editing['dueDate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <?php if ($isAdmin === true): ?>
                        <div class="col-md-3">
                            <label for="assignedToID" class="form-label">Assign To</label>
                            <select class="form-select" id="assignedToID" name="assignedToID">
                                <option value="">Myself</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo (int) $u['userID']; ?>"
                                            <?php echo ((int) ($editing['assignedToID'] ?? 0) === (int) $u['userID'] ? 'selected' : ''); ?>>
                                        <?php echo htmlspecialchars($u['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label for="reminderDate" class="form-label">Reminder</label>
                        <input type="datetime-local" class="form-control" id="reminderDate" name="reminderDate"
                               value="<?php echo $editing !== null && $editing['reminderDate'] !== null ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($editing['reminderDate'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>
                </div>

                <!-- 🔄 Recurrence -->
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="isRecurring" name="isRecurring" value="1"
                                   <?php echo (($editing['isRecurring'] ?? '0') === '1' ? 'checked' : ''); ?>>
                            <label class="form-check-label" for="isRecurring">Recurring</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="recurrenceType" class="form-label">Frequency</label>
                        <select class="form-select" id="recurrenceType" name="recurrenceType">
                            <option value="">—</option>
                            <?php foreach (['daily', 'weekly', 'monthly', 'yearly'] as $rt): ?>
                                <option value="<?php echo $rt; ?>" <?php echo (($editing['recurrenceType'] ?? '') === $rt ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars(ucfirst($rt), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="recurrenceInterval" class="form-label">Every N</label>
                        <input type="number" class="form-control" id="recurrenceInterval" name="recurrenceInterval"
                               min="1" value="<?php echo (int) ($editing['recurrenceInterval'] ?? 1); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="recurrenceEndDate" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="recurrenceEndDate" name="recurrenceEndDate"
                               value="<?php echo htmlspecialchars($editing['recurrenceEndDate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i><?php echo $editing !== null ? 'Update' : 'Create'; ?>
                    </button>
                    <a href="/tasks" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- 📋 Task List -->
<?php if (count($tasks) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No tasks found.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Task</div>
            <div class="col-2">Priority</div>
            <div class="col-2">Due</div>
            <div class="col-2">Status</div>
            <div class="col-2 text-end">Actions</div>
        </div>
        <?php foreach ($tasks as $task): ?>
            <?php
            $prioColors  = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'secondary', 'low' => 'light'];
            $statusColors = ['pending' => 'secondary', 'in_progress' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];
            $pColor = $prioColors[$task['priority']] ?? 'secondary';
            $sColor = $statusColors[$task['status']] ?? 'secondary';
            $isOverdue = $task['dueDate'] !== null && $task['dueDate'] < date('Y-m-d') && $task['status'] !== 'completed' && $task['status'] !== 'cancelled';
            ?>
            <div class="portal-data-row <?php echo ($task['status'] === 'completed' ? 'opacity-50' : ''); ?>">
                <div class="col-4">
                    <?php if ((int) $task['isRecurring'] === 1): ?>
                        <i class="fa-solid fa-rotate text-info me-1" title="Recurring"></i>
                    <?php endif; ?>
                    <strong><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($task['assigneeName'] !== null): ?>
                        <br><small class="text-muted"><i class="fa-regular fa-user me-1"></i><?php echo htmlspecialchars($task['assigneeName'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-2">
                    <span class="badge bg-<?php echo $pColor; ?><?php echo ($task['priority'] === 'low' ? ' text-dark' : ''); ?>">
                        <?php echo htmlspecialchars(ucfirst($task['priority']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-2 small">
                    <?php if ($task['dueDate'] !== null): ?>
                        <span class="<?php echo ($isOverdue === true ? 'text-danger fw-bold' : ''); ?>">
                            <?php echo htmlspecialchars(I18n::formatDate($task['dueDate'], 'short'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php if ($isOverdue === true): ?>
                            <i class="fa-solid fa-exclamation-triangle text-danger ms-1" title="Overdue"></i>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
                <div class="col-2">
                    <span class="badge bg-<?php echo $sColor; ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $task['status'])), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-2 text-end">
                    <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                        <form method="post" action="/tasks/complete" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="taskID" value="<?php echo (int) $task['taskID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Complete">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="/tasks?edit=<?php echo (int) $task['taskID']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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
