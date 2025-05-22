<?php
// Path: apps/expenses/approve/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses – Approval Dashboard ✔️
 * -----------------------------------------------------------------------------
 * Lists pending claims that the current user is authorised to approve.  Shows
 * summary info, status badges, and opens a modal to approve / decline each.
 * -----------------------------------------------------------------------------
 * Version: Phase-5 scaffold (UI only – action handler in approve/save.php).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;

Auth::requireLogin();

// TODO: derive approver list via Dept + tblUserDepts. For now, fetch all pending.
$claims = [];
$stmt = $mysqli->prepare('SELECT EC.claimID, EC.claimTitle, U.fullName, D.deptName, EC.totalAmount, EC.createdAt FROM tblExpenseClaims EC JOIN tblUsers U ON U.userID = EC.userID JOIN tblDepts D ON D.deptID = EC.deptID WHERE EC.status = "Pending" ORDER BY EC.createdAt DESC');
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $claims[] = $row; }
$stmt->close();

?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo ($SETTINGS['features']['darkModeEnabled'] ?? 'false') === 'true' ? 'dark' : 'light'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approve Expense Claims • <?php echo htmlspecialchars($SETTINGS['site']['name']); ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <script src="/assets/js/bootstrap.bundle.min.js" defer></script>
</head>
<body>
<div class="container py-4">
    <h1 class="mb-4">Pending Expense Approvals</h1>

    <?php if (empty($claims)): ?>
        <div class="alert alert-info">No claims awaiting your approval.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Claimant</th>
                    <th>Department</th>
                    <th class="text-end">Total £</th>
                    <th class="text-end">Submitted</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($claims as $c): ?>
                    <tr>
                        <td>#<?php echo $c['claimID']; ?></td>
                        <td><?php echo htmlspecialchars($c['claimTitle']); ?></td>
                        <td><?php echo htmlspecialchars($c['fullName']); ?></td>
                        <td><?php echo htmlspecialchars($c['deptName']); ?></td>
                        <td class="text-end">£<?php echo number_format($c['totalAmount'],2); ?></td>
                        <td class="text-end small text-secondary"><?php echo date('d M Y', strtotime($c['createdAt'])); ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#approveModal" data-claim='<?php echo json_encode($c, JSON_HEX_TAG); ?>'>Review</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="/expenses/approve/save.php">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
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

<script>
const modal = document.getElementById('approveModal');
modal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    const data = JSON.parse(btn.getAttribute('data-claim'));
    document.getElementById('claimID').value = data.claimID;
    document.getElementById('claimDetails').innerHTML = `
        <dl class="row">
            <dt class="col-sm-4">Title</dt><dd class="col-sm-8">${data.claimTitle}</dd>
            <dt class="col-sm-4">Claimant</dt><dd class="col-sm-8">${data.fullName}</dd>
            <dt class="col-sm-4">Department</dt><dd class="col-sm-8">${data.deptName}</dd>
            <dt class="col-sm-4">Total</dt><dd class="col-sm-8">£${parseFloat(data.totalAmount).toFixed(2)}</dd>
            <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8">${data.createdAt}</dd>
        </dl>`;
});
</script>
</body>
</html>