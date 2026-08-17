<?php
// Path: _apps/assets/stocktakes.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Stocktakes (list + start) 📋 (#411, Phase 3 Pass 4)
 * -----------------------------------------------------------------------------
 * Lists every `tblAssetStocktakes` run (open + closed) for the active site
 * via `AssetRegister::listStocktakes()`, and offers a "Start a stocktake"
 * form (optionally scoped to a location and/or category) that posts to
 * `assets/stocktake-save` (action=start) — see that file + `AssetRegister::
 * startStocktake()` for the pre-populate/audit logic. Each run links through
 * to `assets/stocktake?id=` for the scan-to-verify screen / variance report.
 *
 * Manager-gated (admin OR asset_manager role) — mirrors every other
 * mutating/manager-only Asset Tracker screen (categories.php, orgs.php, …).
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

$siteId = Site::id();

// 📋 Every run for this site, newest-started first — listStocktakes()
// itself carries starter/closer names, scope location/category names, and
// a total item count per run.
$stocktakes = AssetRegister::listStocktakes($siteId);

// 📚 Reference data for the "start a stocktake" scope selects — same
// listCategories()/listLocations() calls edit.php's own selects reuse.
$categories = AssetRegister::listCategories($siteId, true);
$locations  = AssetRegister::listLocations($siteId, true);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$statusBadge = [
    'open'   => 'success',
    'closed' => 'secondary',
];
$statusIcon = [
    'open'   => 'fa-magnifying-glass',
    'closed' => 'fa-lock',
];

$pageTitle   = 'Stocktakes';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Stocktakes' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-clipboard-check me-2"></i>Stocktakes</h1>
        <p class="text-secondary mb-0">Scan-to-verify audit runs across your asset register.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-play me-2"></i>Start a stocktake</h2>
        <p class="text-muted small">Leave location/category unset to cover the whole site register.</p>
        <form method="post" action="/assets/stocktake-save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="start">
            <div class="col-md-4">
                <label class="form-label small" for="label">Label</label>
                <input type="text" class="form-control" id="label" name="label" required maxlength="150"
                       placeholder="e.g. Q3 2026 audit">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="locationID">Location (optional)</label>
                <select class="form-select" id="locationID" name="locationID">
                    <option value="">Whole site</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo (int) $loc['locationID']; ?>">
                            <?php echo htmlspecialchars((string) $loc['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="categoryID">Category (optional)</label>
                <select class="form-select" id="categoryID" name="categoryID">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int) $cat['categoryID']; ?>">
                            <?php echo htmlspecialchars((string) $cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa-solid fa-play me-1"></i>Start
                </button>
            </div>
        </form>
    </div>
</div>

<h2 class="h5 mb-3">Stocktake runs</h2>
<?php if (count($stocktakes) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No stocktakes have been started yet.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Label</div>
            <div class="col-3">Scope</div>
            <div class="col-2">Status</div>
            <div class="col-2">Started</div>
            <div class="col-1 text-end">&nbsp;</div>
        </div>
        <?php foreach ($stocktakes as $st): ?>
            <?php
            $status = (string) $st['status'];
            $scopeParts = [];
            if ($st['categoryName'] !== null) {
                $scopeParts[] = (string) $st['categoryName'];
            }
            if ($st['locationName'] !== null) {
                $scopeParts[] = (string) $st['locationName'];
            }
            $scope = count($scopeParts) > 0 ? implode(' · ', $scopeParts) : 'Whole site';
            ?>
            <div class="portal-data-row align-items-center">
                <div class="col-4">
                    <a href="/assets/stocktake?id=<?php echo (int) $st['stocktakeID']; ?>" class="text-decoration-none">
                        <strong><?php echo htmlspecialchars((string) $st['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </a>
                    <br><small class="text-muted"><?php echo (int) $st['itemCount']; ?> asset(s) expected</small>
                </div>
                <div class="col-3 small text-muted"><?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-2">
                    <span class="badge bg-<?php echo htmlspecialchars($statusBadge[$status] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="fa-solid <?php echo htmlspecialchars($statusIcon[$status] ?? 'fa-circle', ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                        <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-2 small text-muted">
                    <?php echo htmlspecialchars($st['startedByName'] !== null ? (string) $st['startedByName'] : 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                    <br><?php echo htmlspecialchars(date('d M Y H:i', strtotime((string) $st['startedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-1 text-end">
                    <a href="/assets/stocktake?id=<?php echo (int) $st['stocktakeID']; ?>" class="btn btn-sm btn-outline-primary" title="View">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
