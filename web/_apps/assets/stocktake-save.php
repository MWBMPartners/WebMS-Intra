<?php
// Path: _apps/assets/stocktake-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Stocktake Save (start/scan/close) 📋💾 (#411, Phase 3 Pass 4)
 * -----------------------------------------------------------------------------
 * POST-only, self-posting dispatch handler for the stocktake lifecycle — one
 * route, three `action` values (mirrors owners-save.php's/resource-save.php's
 * own single-route-multi-action house pattern rather than adding separate
 * `tblRoutes` rows per verb):
 *
 *   - `start` — open a new run via `AssetRegister::startStocktake()`
 *               (label + optional location/category scope).
 *   - `scan`  — record one scan-to-verify result via `AssetRegister::
 *               recordStocktakeScan()` (stocktakeID + code + optional
 *               foundLocationID).
 *   - `close` — close a run via `AssetRegister::closeStocktake()`
 *               (sweeps every still-pending item to 'missing').
 *
 * Every branch hands its raw posted values straight to an AssetRegister
 * method that re-validates them itself (label length, site-scoped FK
 * existence, the open/closed race-safe guard) — this controller's own job
 * is limited to CSRF, the manager gate, coercing $_POST into the shape each
 * method expects, and setting a flash message before redirecting back. No
 * HTML is ever rendered here.
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

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the asset_manager role only. Mirrors every
// other mutating Asset Tracker handler (save.php, categories.php, …).
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    Router::renderError(403);
    return;
}

// 🔐 CSRF FIRST — before any side-effect, per house convention.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets/stocktakes');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    // -------------------------------------------------------------------
    // ▶️ Open a new run.
    // -------------------------------------------------------------------
    case 'start':
        $label = trim((string) ($_POST['label'] ?? ''));

        $locationId = (int) ($_POST['locationID'] ?? 0);
        $locationId = $locationId > 0 ? $locationId : null;

        $categoryId = (int) ($_POST['categoryID'] ?? 0);
        $categoryId = $categoryId > 0 ? $categoryId : null;

        $result = AssetRegister::startStocktake($label, $locationId, $categoryId, $userId);

        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';

        header('Location: ' . ($result['ok'] === true
            ? '/assets/stocktake?id=' . (int) $result['stocktakeId']
            : '/assets/stocktakes'));
        exit();

    // -------------------------------------------------------------------
    // 🔍 Record one scan.
    // -------------------------------------------------------------------
    case 'scan':
        $stocktakeId = (int) ($_POST['stocktakeID'] ?? 0);
        $code        = trim((string) ($_POST['code'] ?? ''));

        $foundLocationId = (int) ($_POST['foundLocationID'] ?? 0);
        $foundLocationId = $foundLocationId > 0 ? $foundLocationId : null;

        $result = AssetRegister::recordStocktakeScan($stocktakeId, $code, $foundLocationId, $userId);

        if ($result['ok'] === true) {
            $_SESSION['flash_msg'] = 'Scanned "' . (string) $result['assetName'] . '" — '
                . ucfirst((string) $result['verifyStatus']) . '.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg']  = $result['msg'];
            $_SESSION['flash_type'] = 'danger';
        }

        header('Location: /assets/stocktake?id=' . $stocktakeId);
        exit();

    // -------------------------------------------------------------------
    // 🔒 Close the run.
    // -------------------------------------------------------------------
    case 'close':
        $stocktakeId = (int) ($_POST['stocktakeID'] ?? 0);

        $result = AssetRegister::closeStocktake($stocktakeId, $userId);

        $_SESSION['flash_msg']  = $result['msg'];
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';

        header('Location: /assets/stocktake?id=' . $stocktakeId);
        exit();

    // -------------------------------------------------------------------
    // ❓ Unknown action — never happens from this app's own forms, but
    //    fail closed rather than falling through to a no-op 200.
    // -------------------------------------------------------------------
    default:
        $_SESSION['flash_msg']  = 'Unknown stocktake action.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/stocktakes');
        exit();
}
