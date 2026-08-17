<?php
// Path: _apps/assets/identifier-verify.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Identifier Verify (GS1 Digital Link / GEPIR) 🔗✅ (#415, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * Placeholder for the manager-facing GEPIR (Global Electronic Party
 * Information Registry) verify action — will accept a POST that looks up
 * one `tblAssetIdentifiers` row's GS1 key against the external GEPIR
 * registry (gated by `assets.gepir_verify_enabled`/`assets.gepir_endpoint`,
 * both seeded — the setting off by default — in migration 161) and caches
 * the outcome in the `verifiedAt`/`verifyNote` columns that same migration
 * added. This pass ships ONLY the route + a minimal logged-in stub so
 * check_route_targets.py stays green — the real GS1 Digital Link resolver +
 * GEPIR lookup logic lands in a later Phase 3 pass.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/415
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

$pageTitle   = 'Verify Identifier';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Verify Identifier' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-magnifying-glass-arrow-right me-2"></i>Verify Identifier</h1>
        <p class="text-secondary mb-0">Look up a GS1 identifier against the GEPIR registry.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>This Asset Tracker feature arrives in a later Phase 3 update.
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
