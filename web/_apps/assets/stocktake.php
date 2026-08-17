<?php
// Path: _apps/assets/stocktake.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Stocktake Detail / Scan Screen 📋🔍 (#411, Phase 3 Pass 4)
 * -----------------------------------------------------------------------------
 * One `tblAssetStocktakes` run's detail screen. Shows the run header (label,
 * scope, status, who/when), the variance summary (`AssetRegister::
 * stocktakeVariance()` — pending/present/missing/moved/unexpected counts,
 * ALWAYS shown as colour + text label + icon together, never colour alone),
 * and the full item list (`AssetRegister::stocktakeItems()`), grouped by
 * verifyStatus.
 *
 * While the run is OPEN, also renders the scan-to-verify form (posts to
 * `assets/stocktake-save`, action=scan — feeds `AssetRegister::
 * recordStocktakeScan()`) and a "Close stocktake" action (action=close —
 * sweeps every still-pending item to 'missing'). Once CLOSED, this becomes a
 * read-only variance report — no scan form, no close button.
 *
 * Manager-gated (admin OR asset_manager role) — mirrors every other
 * mutating/manager-only Asset Tracker screen.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/411
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔐 Session + manager gate — mirrors every other mutating/manager-only
// Asset Tracker screen (orgs.php, categories.php, …): admin OR the
// asset_manager role.
Auth::ensureSession();
Auth::requireLogin();
$canManage = App::isAdmin() === true || App::hasRole('asset_manager') === true;
if ($canManage === false) {
    Router::renderError(403);
    return;
}

$siteId      = Site::id();
$stocktakeId = (int) ($_GET['id'] ?? 0);

$stocktake = $stocktakeId > 0 ? AssetRegister::getStocktake($stocktakeId, $siteId) : null;
if ($stocktake === null) {
    Router::renderError(404);
    return;
}

$isOpen = (string) $stocktake['status'] === 'open';

// 📊 Variance summary — always present for both open and closed runs (a
// closed run's summary IS the final variance report).
$variance = AssetRegister::stocktakeVariance($stocktakeId, $siteId);

// 📋 Item list — optional ?filter= narrows to one verifyStatus (mirrors
// index.php's own ?status= filter convention); invalid/absent values fall
// through to stocktakeItems()'s own "no filter" branch.
$filter = trim((string) ($_GET['filter'] ?? ''));
$filterValue = in_array($filter, AssetRegister::STOCKTAKE_VERIFY_STATUSES, true) === true ? $filter : null;
$items = AssetRegister::stocktakeItems($stocktakeId, $siteId, $filterValue);

// 📚 Reference data for the scan form's "found at" select — same
// listLocations() call edit.php's own location select reuses.
$locations = $isOpen === true ? AssetRegister::listLocations($siteId, true) : [];

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// 🎨 verifyStatus → {label, badge colour, icon} — colour-blind-safe: every
// badge below pairs the colour with BOTH a text label and an icon, never
// colour alone. Shared between the variance summary and the item list so
// the two can never visually drift apart.
$verifyMeta = [
    'pending'    => ['label' => 'Pending',    'badge' => 'secondary',      'icon' => 'fa-clock'],
    'present'    => ['label' => 'Present',    'badge' => 'success',        'icon' => 'fa-check'],
    'missing'    => ['label' => 'Missing',    'badge' => 'danger',         'icon' => 'fa-triangle-exclamation'],
    'moved'      => ['label' => 'Moved',      'badge' => 'warning text-dark', 'icon' => 'fa-right-left'],
    'unexpected' => ['label' => 'Unexpected', 'badge' => 'info',           'icon' => 'fa-circle-question'],
];

$scopeParts = [];
if ($stocktake['categoryName'] !== null) {
    $scopeParts[] = (string) $stocktake['categoryName'];
}
if ($stocktake['locationName'] !== null) {
    $scopeParts[] = (string) $stocktake['locationName'];
}
$scopeLabel = count($scopeParts) > 0 ? implode(' · ', $scopeParts) : 'Whole site register';

$pageTitle   = 'Stocktake — ' . (string) $stocktake['label'];
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Stocktakes' => '/assets/stocktakes', (string) $stocktake['label'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="mb-1">
            <i class="fa-solid fa-clipboard-check me-2"></i><?php echo htmlspecialchars((string) $stocktake['label'], ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($isOpen === true): ?>
                <span class="badge bg-success ms-2"><i class="fa-solid fa-magnifying-glass me-1"></i>Open</span>
            <?php else: ?>
                <span class="badge bg-secondary ms-2"><i class="fa-solid fa-lock me-1"></i>Closed</span>
            <?php endif; ?>
        </h1>
        <p class="text-secondary mb-0">
            Scope: <?php echo htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8'); ?>
            &middot; Started by <?php echo htmlspecialchars($stocktake['startedByName'] !== null ? (string) $stocktake['startedByName'] : 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
            on <?php echo htmlspecialchars(date('d M Y H:i', strtotime((string) $stocktake['startedAt'])), ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($isOpen === false && $stocktake['closedAt'] !== null): ?>
                &middot; Closed by <?php echo htmlspecialchars($stocktake['closedByName'] !== null ? (string) $stocktake['closedByName'] : 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                on <?php echo htmlspecialchars(date('d M Y H:i', strtotime((string) $stocktake['closedAt'])), ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="/assets/stocktakes" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Stocktakes</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<!-- 📊 Variance summary — colour-blind-safe badges (colour + text + icon
     together, never colour alone — see $verifyMeta above). -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($verifyMeta as $vs => $meta): ?>
        <a href="/assets/stocktake?id=<?php echo $stocktakeId; ?>&filter=<?php echo urlencode($vs); ?>"
           class="badge bg-<?php echo htmlspecialchars($meta['badge'], ENT_QUOTES, 'UTF-8'); ?> text-decoration-none fs-6 p-2 <?php echo $filterValue === $vs ? 'border border-3 border-dark' : ''; ?>">
            <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
            <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo (int) ($variance[$vs] ?? 0); ?>
        </a>
    <?php endforeach; ?>
    <?php if ($filterValue !== null): ?>
        <a href="/assets/stocktake?id=<?php echo $stocktakeId; ?>" class="badge bg-light text-dark border text-decoration-none fs-6 p-2">
            <i class="fa-solid fa-xmark me-1"></i>Clear filter
        </a>
    <?php endif; ?>
</div>

<?php if ($isOpen === true): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5"><i class="fa-solid fa-barcode me-2"></i>Scan an asset</h2>
            <p class="text-muted small mb-2">Matches an asset tag, serial number, public QR/label token, or any recorded GS1/RFID identifier.</p>
            <form method="post" action="/assets/stocktake-save" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="scan">
                <input type="hidden" name="stocktakeID" value="<?php echo $stocktakeId; ?>">
                <div class="col-md-6">
                    <label class="form-label small" for="code">Scan code</label>
                    <input type="text" class="form-control" id="code" name="code" required autofocus autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="foundLocationID">Found at (if different from recorded location)</label>
                    <select class="form-select" id="foundLocationID" name="foundLocationID">
                        <option value="">&mdash; not specified &mdash;</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo (int) $loc['locationID']; ?>">
                                <?php echo htmlspecialchars((string) $loc['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-check me-1"></i>Record</button>
                </div>
            </form>
        </div>
    </div>

    <form method="post" action="/assets/stocktake-save" class="mb-4"
          data-confirm="Close this stocktake? Every asset still pending will be marked missing — this can't be undone."
          data-confirm-destructive="true">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="close">
        <input type="hidden" name="stocktakeID" value="<?php echo $stocktakeId; ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-lock me-1"></i>Close stocktake</button>
    </form>
<?php endif; ?>

<h2 class="h5 mb-3">Items <?php echo $filterValue !== null ? '(' . htmlspecialchars($verifyMeta[$filterValue]['label'], ENT_QUOTES, 'UTF-8') . ')' : ''; ?></h2>
<?php if (count($items) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No items to show<?php echo $filterValue !== null ? ' for this filter.' : ' — this run had nothing in scope.'; ?>
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-5">Asset</div>
            <div class="col-2">Recorded location</div>
            <div class="col-2">Found at</div>
            <div class="col-3">Scanned</div>
        </div>
        <?php $lastStatus = null; ?>
        <?php foreach ($items as $item): ?>
            <?php
            $vs   = (string) $item['verifyStatus'];
            $meta = $verifyMeta[$vs] ?? ['label' => ucfirst($vs), 'badge' => 'secondary', 'icon' => 'fa-circle'];
            ?>
            <?php if ($vs !== $lastStatus): $lastStatus = $vs; ?>
                <div class="portal-data-row bg-body-tertiary">
                    <div class="col-12">
                        <span class="badge bg-<?php echo htmlspecialchars($meta['badge'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                            <?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            <div class="portal-data-row align-items-center">
                <div class="col-5">
                    <a href="/assets/item?id=<?php echo (int) $item['assetID']; ?>" class="text-decoration-none">
                        <?php echo htmlspecialchars((string) $item['assetName'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php if ((int) ($item['isConfidential'] ?? 0) === 1): ?>
                        <span class="badge bg-secondary ms-1" title="Confidential asset"><i class="fa-solid fa-lock"></i></span>
                    <?php endif; ?>
                    <?php if ($item['assetTagCode'] !== null): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $item['assetTagCode'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-2 small text-muted">
                    <?php echo $item['recordedLocationName'] !== null ? htmlspecialchars((string) $item['recordedLocationName'], ENT_QUOTES, 'UTF-8') : '&mdash;'; ?>
                </div>
                <div class="col-2 small text-muted">
                    <?php echo ($vs === 'moved' || $vs === 'unexpected') && $item['foundLocationName'] !== null
                        ? htmlspecialchars((string) $item['foundLocationName'], ENT_QUOTES, 'UTF-8')
                        : '&mdash;'; ?>
                </div>
                <div class="col-3 small text-muted">
                    <?php if ($item['scannedByName'] !== null && $item['scannedAt'] !== null): ?>
                        <?php echo htmlspecialchars((string) $item['scannedByName'], ENT_QUOTES, 'UTF-8'); ?>
                        <br><?php echo htmlspecialchars(date('d M H:i', strtotime((string) $item['scannedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
