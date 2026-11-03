<?php
// Path: _apps/assets/delete.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Delete Asset 📦
 * -----------------------------------------------------------------------------
 * POST handler — soft-deletes a tblAssets row via
 * Portal\Core\AssetRegister::softDeleteAsset() (sets isDeleted = 1; never a
 * hard DELETE, so child rows — resources/owners/loans/audit history — keep
 * a valid assetID to point back at).
 *
 * Manager-gated (an administrator, or a role this organisation has pointed "Manage the asset register" at — #727, `asset_manager` as shipped), CSRF-checked, and the
 * trigger on item.php uses `data-confirm data-confirm-destructive="true"`
 * (Portal.Confirm — see web/public_html/assets/js/portal-confirm.js) rather
 * than a native confirm().
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — an administrator, or whoever this organisation has
//    pointed "Manage the asset register" at (#727). `Capabilities` is the
//    ONE place that decides; it ships pointing at the `asset_manager`
//    role, so this answers exactly what `App::hasRole('asset_manager')`
//    answered before.
if (App::isAdmin() !== true && App::can(Capabilities::ASSETS_MANAGE) !== true) {
    http_response_code(403);
    exit('Forbidden');
}

// 🔐 CSRF FIRST — before any side-effect.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('assets'));
    exit();
}

$assetId = (int) ($_POST['assetID'] ?? 0);
$userId  = (int) ($_SESSION['user_id'] ?? 0);

if ($assetId > 0) {
    $ok = AssetRegister::softDeleteAsset($assetId, $userId);
    $_SESSION['flash_msg']  = $ok === true ? 'Asset deleted.' : 'Could not delete asset — it may already be deleted.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
} else {
    $_SESSION['flash_msg']  = 'No asset specified.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: ' . Site::url('assets'));
exit();
