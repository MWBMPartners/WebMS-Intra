<?php
// Path: _apps/assets/kit-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Kit Save (parent/child asset kits) 🧰💾 (#413, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * Placeholder for the parent/child asset kit mutation handler — will accept
 * a POST attaching/detaching a component asset to/from a parent "kit" asset
 * via `tblAssets.parentAssetID` (already shipped in migration 159 — no new
 * schema needed for #413 itself), plus the kit-aware loan behaviour (loaning
 * the parent implicitly covers its components). This pass ships ONLY the
 * route + a minimal logged-in stub so check_route_targets.py stays green —
 * the real kit-attach/detach + kit-aware loan logic lands in a later
 * Phase 3 pass.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/413
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

$pageTitle   = 'Asset Kits';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Kits' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-boxes-stacked me-2"></i>Asset Kits</h1>
        <p class="text-secondary mb-0">Group component assets into a parent/child kit.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>This Asset Tracker feature arrives in a later Phase 3 update.
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
