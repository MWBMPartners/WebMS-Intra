<?php
// Path: public_html/expenses/approve/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Approval Dashboard ✔️
 * -----------------------------------------------------------------------------
 * Lists pending claims that the current user is authorised to approve. Shows
 * summary info, status badges, and opens a modal to approve / decline each.
 * Supports multi-approver workflow with dept-based filtering.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 📌 Page metadata for the template system
$pageTitle   = 'Approve Expense Claims';
$pageSection = 'expenses';
$breadcrumbs = ['Dashboard' => '/', 'Expenses' => '/expenses/approve', 'Approve' => ''];

// 📋 Flash message
$flashMsg  = $_SESSION['admin_flash_msg']  ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'info';
unset($_SESSION['admin_flash_msg'], $_SESSION['admin_flash_type']);

// 📋 Fetch pending claims — admins see all, approvers see their dept claims
$claims = [];
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if (App::isAdmin() === true) {
    // 🛡️ Admins see all pending claims
    $stmt = $mysqli->prepare(
        'SELECT EC.claimID, EC.claimTitle, U.fullName, D.deptName, EC.totalAmount, EC.createdAt '
        . 'FROM tblExpenseClaims EC '
        . 'JOIN tblUsers U ON U.userID = EC.userID '
        . 'JOIN tblDepts D ON D.deptID = EC.deptID '
        . "WHERE EC.status = 'Pending' "
        . 'ORDER BY EC.createdAt DESC'
    );
} else {
    // 📋 Approvers see claims from their assigned departments
    $stmt = $mysqli->prepare(
        'SELECT DISTINCT EC.claimID, EC.claimTitle, U.fullName, D.deptName, EC.totalAmount, EC.createdAt '
        . 'FROM tblExpenseClaims EC '
        . 'JOIN tblUsers U ON U.userID = EC.userID '
        . 'JOIN tblDepts D ON D.deptID = EC.deptID '
        . 'JOIN tblUserDepts UD ON UD.deptID = EC.deptID AND UD.userID = ? '
        . "WHERE EC.status = 'Pending' "
        . 'AND (UD.isApprover = 1 OR UD.isDeptLead = 1 OR UD.isMandatoryApprover = 1) '
        . 'ORDER BY EC.createdAt DESC'
    );
}

if ($stmt !== false) {
    if (App::isAdmin() === false) {
        $stmt->bind_param('i', $currentUserId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $claims[] = $row;
    }
    $stmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- ✔️ Pending Expense Approvals -->
<h1 class="mb-4"><i class="fa-solid fa-check-double me-2"></i>Pending Expense Approvals</h1>

<?php if (empty($claims) === true): ?>
    <div class="alert alert-info">No claims awaiting your approval.</div>
<?php else: ?>

    <!-- 📋 Responsive data list (replaces <table>) -->
    <div class="portal-data-list">
        <!-- 🏷️ Header row (visible on md+ screens) -->
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-1">ID</div>
            <div class="col-md-3">Title</div>
            <div class="col-md-2">Claimant</div>
            <div class="col-md-2">Department</div>
            <div class="col-md-1 text-end">Total &pound;</div>
            <div class="col-md-2 text-end">Submitted</div>
            <div class="col-md-1 text-end"></div>
        </div>

        <?php foreach ($claims as $c): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-1">
                    <span class="d-md-none fw-semibold">ID: </span>#<?php echo (int) $c['claimID']; ?>
                </div>
                <div class="col-12 col-md-3">
                    <span class="d-md-none fw-semibold">Title: </span><?php echo htmlspecialchars($c['claimTitle'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Claimant: </span><?php echo htmlspecialchars($c['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Dept: </span><?php echo htmlspecialchars($c['deptName'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-1 text-md-end">
                    <span class="d-md-none fw-semibold">Total: </span>&pound;<?php echo number_format((float) $c['totalAmount'], 2); ?>
                </div>
                <div class="col-12 col-md-2 text-md-end small text-secondary">
                    <span class="d-md-none fw-semibold">Submitted: </span><?php echo date('d M Y', strtotime($c['createdAt'])); ?>
                </div>
                <div class="col-12 col-md-1 text-md-end mt-2 mt-md-0">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#approveModal"
                            data-claim='<?php echo htmlspecialchars(json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8'); ?>'>
                        Review
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<!-- 📝 Approval Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="/expenses/approve/save.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="claimID" id="claimID">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Claim</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="claimDetails"></div>
                    <div class="mb-3">
                        <label class="form-label">Decision</label>
                        <select class="form-select" name="decision" required>
                            <option value="Approved">Approve</option>
                            <option value="Rejected">Reject</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comments (optional)</label>
                        <textarea class="form-control" name="comments" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Modal population script -->
<script>
const modal = document.getElementById('approveModal');
modal.addEventListener('show.bs.modal', ev => {
    const btn  = ev.relatedTarget;
    const data = JSON.parse(btn.getAttribute('data-claim'));
    document.getElementById('claimID').value = data.claimID;
    const container = document.getElementById('claimDetails');
    container.textContent = '';
    const dl = document.createElement('dl');
    dl.className = 'row';
    const fields = [
        ['Title', data.claimTitle],
        ['Claimant', data.fullName],
        ['Department', data.deptName],
        ['Total', '\u00a3' + parseFloat(data.totalAmount).toFixed(2)],
        ['Submitted', data.createdAt]
    ];
    fields.forEach(([label, value]) => {
        const dt = document.createElement('dt');
        dt.className = 'col-sm-4';
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.className = 'col-sm-8';
        dd.textContent = value;
        dl.appendChild(dt);
        dl.appendChild(dd);
    });
    container.appendChild(dl);
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
