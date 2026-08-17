<?php
// Path: _apps/assets/kiosks.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Kiosk Terminals (admin list) 🖥️ (#414, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * Placeholder for the manager-facing kiosk terminal admin screen — will list
 * every `tblAssetKioskTokens` row (registered terminals) for the active
 * site, with a "Register terminal" action leading into `assets/kiosk-save`.
 * This is the INTERNAL, session-gated admin screen — NOT the public kiosk
 * terminal itself (see `assets/kiosk`/`assets/kiosk-action`, both
 * unprotected). Schema (`tblAssetKioskTokens`, `tblAssetKioskPins`) shipped
 * in migration 161; this pass ships ONLY the route + a minimal logged-in
 * stub so check_route_targets.py stays green — the real terminal admin UI
 * lands in a later Phase 3 pass.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/414
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

$pageTitle   = 'Kiosk Terminals';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Kiosk Terminals' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-tablet-screen-button me-2"></i>Kiosk Terminals</h1>
        <p class="text-secondary mb-0">Register and manage shared check-in/out kiosk devices.</p>
    </div>
    <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>This Asset Tracker feature arrives in a later Phase 3 update.
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
