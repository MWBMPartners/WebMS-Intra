<?php
// Path: _apps/giving/reconcile/index.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bank Reconciliation: Dashboard 📊
 * -----------------------------------------------------------------------------
 * Lists every bank-statement import for the active site (most recent first)
 * with a per-import matched/total badge, plus a site-wide "unmatched
 * credits" summary. Entry point for starting a new import and for deleting
 * a previous one (#299 sub-feature 3).
 *
 * Gate matches every other financial action in `giving`:
 * Portal\Core\Giving::canManage() (site admin OR the `treasurer` role).
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/299
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session + gate
Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📋 Import batches, most recent first — matched/ignored counts derived via
// one aggregate join rather than a stored counter (see migration 152 banner).
// -----------------------------------------------------------------------------
$imports = [];
$stmt = $db->prepare(
    'SELECT bi.importID, bi.filename, bi.bankKey, bi.currency, bi.rowCount, bi.skippedCount, bi.importedAt, '
    . '       u.fullName AS importerName, '
    . '       COALESCE(SUM(CASE WHEN bt.matchStatus = \'matched\' THEN 1 ELSE 0 END), 0) AS matchedCount, '
    . '       COALESCE(SUM(CASE WHEN bt.matchStatus = \'ignored\' THEN 1 ELSE 0 END), 0) AS ignoredCount '
    . 'FROM tblBankImports bi '
    . 'LEFT JOIN tblUsers u ON u.userID = bi.importedByID '
    . 'LEFT JOIN tblBankTxns bt ON bt.importID = bi.importID '
    . 'WHERE bi.siteID = ? '
    . 'GROUP BY bi.importID '
    . 'ORDER BY bi.importedAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $imports[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 📊 Site-wide unmatched summary
// -----------------------------------------------------------------------------
$unmatchedCount = 0;
$unmatchedPence = 0;
$unmatchedCurrency = (string) (App::settings('giving.currency') ?? 'GBP');
$stmt = $db->prepare(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(amountPence), 0) AS totalPence '
    . 'FROM tblBankTxns WHERE siteID = ? AND matchStatus = \'unmatched\''
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $sumRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($sumRow !== null) {
        $unmatchedCount = (int) $sumRow['cnt'];
        $unmatchedPence = (int) $sumRow['totalPence'];
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Bank Reconciliation';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Reconcile' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-scale-balanced me-2"></i>Bank Reconciliation</h1>
        <p class="text-secondary mb-0">Import a bank statement and match its credits against the gift log.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/giving/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Giving</a>
        <a href="/giving/reconcile/import" class="btn btn-primary btn-sm"><i class="fa-solid fa-file-csv me-1"></i>Import statement</a>
    </div>
</div>

<?php if ($unmatchedCount > 0): ?>
    <div class="alert alert-info">
        <strong><?php echo $unmatchedCount; ?></strong> unmatched credit<?php echo $unmatchedCount === 1 ? '' : 's'; ?> site-wide,
        totalling <strong><?php echo htmlspecialchars(Giving::formatAmount($unmatchedPence, $unmatchedCurrency), ENT_QUOTES, 'UTF-8'); ?></strong>.
    </div>
<?php elseif (count($imports) > 0): ?>
    <div class="alert alert-success">All imported credits are matched or ignored.</div>
<?php endif; ?>

<?php if (count($imports) === 0): ?>
    <div class="alert alert-light border">No bank statements imported yet. <a href="/giving/reconcile/import">Import one</a> to get started.</div>
<?php else: ?>
    <div class="card"><div class="card-body p-0">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-2">Imported</div>
                <div class="col-md-3">Filename</div>
                <div class="col-md-1">Bank</div>
                <div class="col-md-2">Lines</div>
                <div class="col-md-2">Matched</div>
                <div class="col-md-2 text-end">Actions</div>
            </div>
            <?php foreach ($imports as $imp):
                $rowCount     = (int) $imp['rowCount'];
                $matchedCount = (int) $imp['matchedCount'];
                $badgeClass   = ($rowCount > 0 && $matchedCount === $rowCount) ? 'success' : 'warning';
            ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Imported: </span>
                        <?php echo htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $imp['importedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <div class="small text-muted"><?php echo htmlspecialchars((string) ($imp['importerName'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Filename: </span>
                        <?php echo htmlspecialchars((string) $imp['filename'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-1">
                        <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $imp['bankKey'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Lines: </span>
                        <?php echo $rowCount; ?>
                        <?php if ((int) $imp['skippedCount'] > 0): ?>
                            <span class="text-muted small">+<?php echo (int) $imp['skippedCount']; ?> skipped</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $matchedCount; ?>/<?php echo $rowCount; ?> matched</span>
                        <?php if ((int) $imp['ignoredCount'] > 0): ?>
                            <span class="text-muted small">(<?php echo (int) $imp['ignoredCount']; ?> ignored)</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <a href="/giving/reconcile/view?id=<?php echo (int) $imp['importID']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-magnifying-glass me-1"></i>Open
                        </a>
                        <form method="post" action="/giving/reconcile/match" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete-import">
                            <input type="hidden" name="importID" value="<?php echo (int) $imp['importID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Delete this import and all its <?php echo $rowCount; ?> lines? Matches will be lost."
                                    data-confirm-destructive="true">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
