<?php
// Path: _apps/assets/stocktake-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Stocktake Save (open/close) 📋💾 (#411, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * Placeholder for the stocktake open/close mutation handler — will accept a
 * POST to open a new `tblAssetStocktakes` run (optionally scoped to a
 * location/category, pre-populating `tblAssetStocktakeItems` from the
 * matching asset set) or close an existing one. This pass ships ONLY the
 * route + a minimal logged-in stub so check_route_targets.py stays green —
 * the real open/close logic lands in a later Phase 3 pass.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/411
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

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

$pageTitle   = 'Stocktake';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Stocktakes' => '/assets/stocktakes', 'Save' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-clipboard-check me-2"></i>Stocktake</h1>
        <p class="text-secondary mb-0">Open or close a stocktake run.</p>
    </div>
    <a href="/assets/stocktakes" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Stocktakes</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>This Asset Tracker feature arrives in a later Phase 3 update.
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
