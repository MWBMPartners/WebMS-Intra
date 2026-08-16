<?php
// Path: _apps/assets/loans.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Loan Register 🔄
 * -----------------------------------------------------------------------------
 * Site-wide list of every asset loan (both directions, every status) via
 * AssetRegister::listLoans(). Any logged-in user may VIEW this register —
 * per-row management actions (approve/decline/checkout/checkin/cancel) are
 * gated inside each row, exactly like item.php's Loans panel: an action
 * form only renders for a viewer who actually holds the authority for it
 * (AssetRegister::canApproveLoan() for approve/decline/checkout, that OR
 * the original requester for checkin/cancel) — AssetRegister::loanAction()
 * re-enforces every one of those gates server-side regardless of what this
 * page chose to render, so a tampered POST against a hidden action can
 * never succeed.
 *
 * Confidential-asset visibility mirrors index.php's own register exactly:
 * listLoans()'s $includeConfidential param is passed $canManage (never a
 * per-row isResponsibleFor() check) — a non-manager sees NO loans for a
 * confidential asset here, even one they are individually a responsible
 * owner-party for; they can still see and act on that specific asset's
 * loans from item.php's Loans panel, which DOES apply the finer-grained
 * per-asset privileged check.
 *
 * Filters (GET querystring, all optional): assetID, direction (out|in),
 * status (one of AssetRegister::LOAN_STATUSES), overdueOnly (checkbox).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/398
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$canManage = App::isAdmin() === true || App::hasRole('asset_manager') === true;

// 📋 Filters — kept simple, mirrors index.php's own querystring convention.
$assetIdFilter = (int) ($_GET['assetID'] ?? 0);
$directionFilter = (string) ($_GET['direction'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$overdueOnlyFilter = isset($_GET['overdueOnly']) === true && (string) $_GET['overdueOnly'] === '1';

$filters = [];
if ($assetIdFilter > 0) {
    $filters['assetID'] = $assetIdFilter;
}
if (in_array($directionFilter, AssetRegister::LOAN_DIRECTIONS, true) === true) {
    $filters['direction'] = $directionFilter;
}
if (in_array($statusFilter, AssetRegister::LOAN_STATUSES, true) === true) {
    $filters['status'] = $statusFilter;
}
if ($overdueOnlyFilter === true) {
    $filters['overdueOnly'] = true;
}

$loans = AssetRegister::listLoans($siteId, $filters, $canManage);

$csrf = Auth::csrfToken();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$statusBadge = [
    'requested' => 'warning',
    'approved'  => 'info',
    'declined'  => 'secondary',
    'active'    => 'primary',
    'returned'  => 'success',
    'cancelled' => 'secondary',
];
$directionLabel = ['out' => 'Lending out', 'in' => 'Borrowing in'];
$directionIcon  = ['out' => 'fa-arrow-right', 'in' => 'fa-arrow-left'];

$pageTitle   = 'Loan Register';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Loan Register' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-1"><i class="fa-solid fa-right-left me-2"></i>Loan register</h1>
<p class="text-secondary mb-4">Outbound and inbound asset loans across the whole site.</p>

<!-- 🔍 Filters -->
<form method="get" action="/assets/loans" class="row g-2 mb-4 align-items-end">
    <?php if ($assetIdFilter > 0): ?>
        <input type="hidden" name="assetID" value="<?php echo $assetIdFilter; ?>">
    <?php endif; ?>
    <div class="col-auto">
        <label class="form-label small" for="direction">Direction</label>
        <select class="form-select form-select-sm" id="direction" name="direction">
            <option value="">All</option>
            <option value="out"<?php echo $directionFilter === 'out' ? ' selected' : ''; ?>>Lending out</option>
            <option value="in"<?php echo $directionFilter === 'in' ? ' selected' : ''; ?>>Borrowing in</option>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status">
            <option value="">All</option>
            <?php foreach (AssetRegister::LOAN_STATUSES as $s): ?>
                <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $statusFilter === $s ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars(ucwords($s), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="overdueOnly" name="overdueOnly" value="1"<?php echo $overdueOnlyFilter === true ? ' checked' : ''; ?>>
            <label class="form-check-label" for="overdueOnly">Overdue only</label>
        </div>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
        <a href="/assets/loans" class="btn btn-outline-secondary btn-sm">Clear</a>
    </div>
</form>

<?php if (count($loans) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No loans match these filters.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($loans as $loan): ?>
            <?php
            $loanAssetId    = (int) $loan['assetID'];
            $status         = (string) $loan['status'];
            $direction      = (string) $loan['direction'];
            $isOverdue      = (bool) $loan['isOverdue'];
            $canApproveThis = AssetRegister::canApproveLoan($loanAssetId, $userId);
            $isRequesterThis = (int) $loan['requestedByID'] === $userId;
            ?>
            <div class="portal-data-row align-items-start <?php echo $isOverdue === true ? 'bg-danger-subtle' : ''; ?>">
                <div class="col-12 col-md-3">
                    <i class="fa-solid <?php echo htmlspecialchars($directionIcon[$direction] ?? 'fa-right-left', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                    <a href="/assets/item?id=<?php echo $loanAssetId; ?>" class="text-decoration-none">
                        <strong><?php echo htmlspecialchars((string) $loan['assetName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </a>
                    <br><small class="text-muted"><?php echo htmlspecialchars($directionLabel[$direction] ?? $direction, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-6 col-md-3">
                    <?php echo htmlspecialchars((string) $loan['counterpartyDisplayName'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($loan['counterpartyContact'] !== null && (string) $loan['counterpartyContact'] !== ''): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $loan['counterpartyContact'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-3 col-md-2">
                    <span class="badge bg-<?php echo htmlspecialchars($statusBadge[$status] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-3 col-md-2">
                    <?php if ($loan['dueDate'] !== null): ?>
                        <?php echo htmlspecialchars((string) $loan['dueDate'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($isOverdue === true): ?>
                            <br><span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2 text-md-end">
                    <?php if ($status === 'requested' && $canApproveThis === true): ?>
                        <form method="post" action="/assets/loan-action" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="assetID" value="<?php echo $loanAssetId; ?>">
                            <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                            <input type="hidden" name="returnTo" value="loans">
                            <button type="submit" class="btn btn-sm btn-success mb-1" title="Approve">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </form>
                        <form method="post" action="/assets/loan-action" class="d-inline"
                              data-confirm="Decline this loan request?" data-confirm-destructive="true">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="decline">
                            <input type="hidden" name="assetID" value="<?php echo $loanAssetId; ?>">
                            <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                            <input type="hidden" name="returnTo" value="loans">
                            <button type="submit" class="btn btn-sm btn-outline-danger mb-1" title="Decline">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($status === 'approved' && $canApproveThis === true): ?>
                        <form method="post" action="/assets/loan-action" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="checkout">
                            <input type="hidden" name="assetID" value="<?php echo $loanAssetId; ?>">
                            <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                            <input type="hidden" name="returnTo" value="loans">
                            <button type="submit" class="btn btn-sm btn-primary mb-1" title="Check out">
                                <i class="fa-solid fa-dolly me-1"></i>Check out
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($status === 'active' && ($canApproveThis === true || $isRequesterThis === true)): ?>
                        <details class="d-inline-block mb-1">
                            <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-dolly-flatbed me-1"></i>Check in</summary>
                            <form method="post" action="/assets/loan-action" class="mt-2 text-start">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="checkin">
                                <input type="hidden" name="assetID" value="<?php echo $loanAssetId; ?>">
                                <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                                <input type="hidden" name="returnTo" value="loans">
                                <label class="form-label small">Condition on return <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm mb-1" name="conditionIn" required>
                                    <option value="">Select…</option>
                                    <?php foreach (AssetRegister::CONDITION_STATES as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords($c), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary">Confirm check-in</button>
                            </form>
                        </details>
                    <?php endif; ?>
                    <?php if (in_array($status, ['requested', 'approved'], true) === true && ($canApproveThis === true || $isRequesterThis === true)): ?>
                        <form method="post" action="/assets/loan-action" class="d-inline"
                              data-confirm="Cancel this loan?" data-confirm-destructive="true">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="assetID" value="<?php echo $loanAssetId; ?>">
                            <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                            <input type="hidden" name="returnTo" value="loans">
                            <button type="submit" class="btn btn-sm btn-outline-secondary mb-1" title="Cancel">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="mt-3"><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
