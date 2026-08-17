<?php
// Path: _apps/assets/kit-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Kit Save (parent/child asset kits) 🧰💾 (#413, Phase 3 Pass 3)
 * -----------------------------------------------------------------------------
 * POST handler for item.php's "Kit / components" panel. One route, two
 * actions (`action` field — mirrors owners-save.php's/loan-action.php's own
 * single-route-multi-action house pattern rather than adding more tblRoutes
 * rows):
 *
 *   - `attach` — add an existing, eligible asset as a component of a parent
 *                kit asset via AssetRegister::attachToKit().
 *   - `detach` — remove an asset from whichever kit it currently belongs to
 *                via AssetRegister::detachFromKit().
 *
 * Every guard (both assets exist on this site, one-level-deep kit nesting,
 * "not already a component"/"not already a kit parent", no open loan on the
 * child, the race-safe WHERE-guarded UPDATE) lives entirely inside those two
 * AssetRegister methods — this controller's own job is limited to CSRF, the
 * manager gate, and coercing $_POST into the ids each method expects; it
 * does not duplicate any of that validation. Replaces the Phase 3 Pass 1
 * stub that shipped only the route + a "coming later" placeholder page.
 *
 * Gate: admin OR asset_manager role ONLY — same manager-only gate as
 * owners-save.php (managing kit membership is a register-management action,
 * not something a merely-responsible owner-party or lending/maintenance
 * authority needs — mirrors that file's own header rationale).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/413
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/loan-action.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. See file header
// for why this is deliberately NOT widened to isResponsibleFor().
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    $_SESSION['flash_msg']  = "You don't have permission to manage asset kits.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    // -------------------------------------------------------------------
    // ➕ Attach an eligible asset as a component of a parent kit asset.
    // -------------------------------------------------------------------
    case 'attach':
        $parentAssetId = (int) ($_POST['parentAssetID'] ?? 0);
        $childAssetId  = (int) ($_POST['childAssetID'] ?? 0);

        $result = AssetRegister::attachToKit($childAssetId, $parentAssetId, $userId);
        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // 🗑️ Detach a component asset from its current kit. $parentAssetID is
    // read too — purely for the redirect target below (item.php's kit
    // panel posts it as a hidden field), never trusted as authorisation:
    // detachFromKit() re-derives the child's ACTUAL current parent from
    // the row itself, it does not take our word for it.
    // -------------------------------------------------------------------
    case 'detach':
        $parentAssetId = (int) ($_POST['parentAssetID'] ?? 0);
        $childAssetId  = (int) ($_POST['childAssetID'] ?? 0);

        $result = AssetRegister::detachFromKit($childAssetId, $userId);
        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
        break;

    default:
        $parentAssetId = (int) ($_POST['parentAssetID'] ?? 0);
        $_SESSION['flash_msg']  = 'Unknown action.';
        $_SESSION['flash_type'] = 'danger';
        break;
}

// 🔙 Back to the parent kit's own item.php page — falls back to the
// register index if no valid parentAssetID was posted (e.g. a malformed
// request that never reached a real kit).
$redirect = $parentAssetId > 0 ? ('/assets/item?id=' . $parentAssetId) : '/assets';
header('Location: ' . $redirect);
exit();
