<?php
// Path: public_html/expenses/view/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Claim Detail View 📄
 * -----------------------------------------------------------------------------
 * Displays full claim details including line items, uploaded evidence files,
 * approval history, payment records, and PDF download links.
 * Accessible to: the claimant, approvers for the dept, treasury, and admins.
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
use Portal\Core\Router;

// 📌 Page metadata
$pageTitle   = 'View Claim';
$pageSection = 'expenses';

// 🛡️ Auth check
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

$claimID = (int) ($_GET['id'] ?? 0);
if ($claimID <= 0) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// 📋 Fetch claim header with claimant and department info
// -----------------------------------------------------------------------------
$claim = null;
$stmt = $mysqli->prepare(
    'SELECT EC.*, U.fullName AS claimantName, U.emailAddress AS claimantEmail, D.deptName '
    . 'FROM tblExpenseClaims EC '
    . 'JOIN tblUsers U ON U.userID = EC.userID '
    . 'JOIN tblDepts D ON D.deptID = EC.deptID '
    . 'WHERE EC.claimID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $claim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($claim === null) {
    Router::renderError(404);
    return;
}

// 🛡️ Access check: claimant, dept approvers, treasury, or admin
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$isClaimant    = ((int) $claim['userID'] === $currentUserId);
$isAdmin       = App::isAdmin();
$hasApprover   = App::hasRole('Approver');
$hasTreasurer  = App::hasRole('Treasurer');

if ($isClaimant === false && $isAdmin === false && $hasApprover === false && $hasTreasurer === false) {
    Router::renderError(403);
    return;
}

$pageTitle   = 'Claim #' . $claimID . ' — ' . $claim['claimTitle'];
$breadcrumbs = ['Dashboard' => '/', 'Expenses' => '/expenses/submit', 'Claim #' . $claimID => ''];

// -----------------------------------------------------------------------------
// 📋 Fetch line items
// -----------------------------------------------------------------------------
$items = [];
$stmt = $mysqli->prepare(
    'SELECT itemName, description, quantity, unitCost, lineTotal, purchaseDate, supplier '
    . 'FROM tblExpenseClaimItems WHERE claimID = ? ORDER BY itemID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 📎 Fetch uploaded evidence files
// -----------------------------------------------------------------------------
$files = [];
$stmt = $mysqli->prepare(
    'SELECT fileID, originalFilename, storedFilename, fileSize, fileType, stage, uploadedAt '
    . 'FROM tblExpenseClaimFiles WHERE claimID = ? ORDER BY uploadedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $files[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// ✅ Fetch approval history
// -----------------------------------------------------------------------------
$approvals = [];
$stmt = $mysqli->prepare(
    'SELECT A.decision, A.comments, A.approverRole, A.decidedAt, U.fullName AS approverName '
    . 'FROM tblExpenseClaimApprovals A '
    . 'JOIN tblUsers U ON U.userID = A.userID '
    . 'WHERE A.claimID = ? ORDER BY A.decidedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $approvals[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 💳 Fetch payment records
// -----------------------------------------------------------------------------
$payments = [];
$stmt = $mysqli->prepare(
    'SELECT P.payReference, P.payMethod, P.payAmount, P.addedAt, U.fullName AS paidByName '
    . 'FROM tblExpenseClaimPayments P '
    . 'LEFT JOIN tblUsers U ON U.userID = P.paidByID '
    . 'WHERE P.claimID = ? ORDER BY P.addedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $payments[] = $r;
    }
    $stmt->close();
}

// 📊 Status badge mapping
$statusClass = match ($claim['status']) {
    'Pending'    => 'warning',
    'Approved'   => 'info',
    'Rejected'   => 'danger',
    'Reimbursed' => 'success',
    default      => 'secondary',
};

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📄 Claim Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">
            <i class="fa-solid fa-file-invoice me-2"></i>Claim #<?php echo (int) $claim['claimID']; ?>
        </h1>
        <p class="text-muted mb-0"><?php echo htmlspecialchars($claim['claimTitle'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <span class="badge bg-<?php echo $statusClass; ?> fs-5">
        <?php echo htmlspecialchars($claim['status'], ENT_QUOTES, 'UTF-8'); ?>
    </span>
</div>

<!-- 📊 Claim Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Total Amount</small>
                <h3 class="mb-0">&pound;<?php echo number_format((float) $claim['totalAmount'], 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Claimant</small>
                <strong><?php echo htmlspecialchars($claim['claimantName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Department</small>
                <strong><?php echo htmlspecialchars($claim['deptName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Submitted</small>
                <strong><?php echo htmlspecialchars(date('j M Y', strtotime($claim['createdAt'])), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- 📦 Line Items -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-list me-1"></i>Line Items</h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-4">Description</div>
                <div class="col-md-2 text-center">Qty</div>
                <div class="col-md-2 text-end">Unit &pound;</div>
                <div class="col-md-2 text-end">Line &pound;</div>
                <div class="col-md-2">Supplier</div>
            </div>

            <?php foreach ($items as $item): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4">
                        <span class="d-md-none fw-semibold">Description: </span>
                        <?php echo htmlspecialchars($item['itemName'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-center">
                        <span class="d-md-none fw-semibold">Qty: </span>
                        <?php echo (int) $item['quantity']; ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="d-md-none fw-semibold">Unit: </span>
                        &pound;<?php echo number_format((float) $item['unitCost'], 2); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="d-md-none fw-semibold">Line: </span>
                        <strong>&pound;<?php echo number_format((float) $item['lineTotal'], 2); ?></strong>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Supplier: </span>
                        <?php echo htmlspecialchars($item['supplier'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="portal-data-row fw-bold">
                <div class="col-12 col-md-8 text-md-end">Total</div>
                <div class="col-12 col-md-2 text-md-end">&pound;<?php echo number_format((float) $claim['totalAmount'], 2); ?></div>
                <div class="col-md-2"></div>
            </div>
        </div>
    </div>
</div>

<!-- 📎 Evidence Files -->
<?php if (count($files) > 0): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-paperclip me-1"></i>Evidence &amp; Documents</h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-5">Filename</div>
                <div class="col-md-2">Type</div>
                <div class="col-md-2">Size</div>
                <div class="col-md-3">Uploaded</div>
            </div>

            <?php foreach ($files as $f): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-5">
                        <span class="d-md-none fw-semibold">File: </span>
                        <i class="fa-solid fa-file me-1"></i>
                        <?php echo htmlspecialchars($f['originalFilename'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($f['stage'] !== null && $f['stage'] !== ''): ?>
                            <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars(ucfirst($f['stage']), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Type: </span>
                        <small><?php echo htmlspecialchars($f['fileType'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Size: </span>
                        <small><?php echo $f['fileSize'] !== null ? number_format((int) $f['fileSize'] / 1024, 1) . ' KB' : '—'; ?></small>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Uploaded: </span>
                        <small><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($f['uploadedAt'])), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ✅ Approval History -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-check-double me-1"></i>Approval History</h5></div>
    <div class="card-body">
        <?php if (count($approvals) === 0): ?>
            <p class="text-muted mb-0">
                <?php echo $claim['status'] === 'Pending' ? 'Awaiting approval.' : 'No approval records.'; ?>
            </p>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-3">Approver</div>
                    <div class="col-md-2">Role</div>
                    <div class="col-md-2">Decision</div>
                    <div class="col-md-2">Date</div>
                    <div class="col-md-3">Comments</div>
                </div>

                <?php foreach ($approvals as $a): ?>
                    <?php
                    $decClass = $a['decision'] === 'Approved' ? 'success' : 'danger';
                    ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-3">
                            <span class="d-md-none fw-semibold">Approver: </span>
                            <strong><?php echo htmlspecialchars($a['approverName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none fw-semibold">Role: </span>
                            <small><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $a['approverRole'] ?? 'approver')), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none fw-semibold">Decision: </span>
                            <span class="badge bg-<?php echo $decClass; ?>"><?php echo htmlspecialchars($a['decision'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none fw-semibold">Date: </span>
                            <small><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($a['decidedAt'])), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-12 col-md-3">
                            <span class="d-md-none fw-semibold">Comments: </span>
                            <small><?php echo htmlspecialchars($a['comments'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 💳 Payment Records -->
<?php if (count($payments) > 0): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-credit-card me-1"></i>Payment Records</h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-3">Reference</div>
                <div class="col-md-2">Method</div>
                <div class="col-md-2 text-end">Amount</div>
                <div class="col-md-2">Paid By</div>
                <div class="col-md-3">Date</div>
            </div>

            <?php foreach ($payments as $p): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Ref: </span>
                        <strong><?php echo htmlspecialchars($p['payReference'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Method: </span>
                        <?php echo htmlspecialchars($p['payMethod'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="d-md-none fw-semibold">Amount: </span>
                        <?php echo $p['payAmount'] !== null ? '&pound;' . number_format((float) $p['payAmount'], 2) : '—'; ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Paid By: </span>
                        <?php echo htmlspecialchars($p['paidByName'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Date: </span>
                        <small><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($p['addedAt'])), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 📄 PDF Download -->
<?php if ($claim['fileName'] !== null && $claim['fileName'] !== ''): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-file-pdf me-1"></i>Generated PDF</h5></div>
    <div class="card-body">
        <p class="mb-0">
            <i class="fa-solid fa-download me-1"></i>
            <strong><?php echo htmlspecialchars($claim['fileName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <small class="text-muted ms-2">(Last generated for status: <?php echo htmlspecialchars($claim['status'], ENT_QUOTES, 'UTF-8'); ?>)</small>
        </p>
    </div>
</div>
<?php endif; ?>

<!-- 🔗 Action Buttons -->
<div class="d-flex gap-2 mb-4">
    <a href="/expenses/submit" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Submit
    </a>
    <?php if ($claim['status'] === 'Pending' && ($hasApprover === true || $isAdmin === true)): ?>
        <a href="/expenses/approve" class="btn btn-outline-primary">
            <i class="fa-solid fa-check me-1"></i> Go to Approvals
        </a>
    <?php endif; ?>
    <?php if ($claim['status'] === 'Approved' && ($hasTreasurer === true || $isAdmin === true)): ?>
        <a href="/expenses/treasury" class="btn btn-outline-success">
            <i class="fa-solid fa-sterling-sign me-1"></i> Go to Treasury
        </a>
    <?php endif; ?>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
