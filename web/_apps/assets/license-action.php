<?php
// Path: _apps/assets/license-action.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Release a Licence Seat 🎟️
 * -----------------------------------------------------------------------------
 * POST handler for item.php's "Licence & seats" panel — releases (unlinks)
 * an active seat assignment via AssetRegister::releaseSeat(). One route,
 * one action today (mirrors loan-action.php's shape, minus the multi-verb
 * dispatch — releasing is the only state transition a seat assignment has
 * once linked).
 *
 * Gate: admin OR asset_manager role ONLY — same manager-only gate as
 * license-save.php (see that file's header for the rationale). The IDOR
 * guard (the assignment must belong to BOTH $assignmentID AND
 * $licenseAssetID on this site) AND the race-safe status='active' state
 * guard both live entirely inside AssetRegister::releaseSeat() — this
 * controller passes the raw posted ids straight through without
 * pre-checking them, exactly like loan-action.php does for loanID/assetID.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/400
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. See
// license-save.php's header for the rationale.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$userId         = (int) ($_SESSION['user_id'] ?? 0);
$licenseAssetId = (int) ($_POST['licenseAssetID'] ?? 0);
$assignmentId   = (int) ($_POST['assignmentID'] ?? 0);

if ($licenseAssetId <= 0 || $assignmentId <= 0) {
    $_SESSION['flash_msg']  = 'Missing seat assignment reference.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$ok = AssetRegister::releaseSeat($assignmentId, $licenseAssetId, $userId);

$_SESSION['flash_msg']  = $ok === true
    ? 'Seat released.'
    : 'Could not release that seat — it may already be released or no longer belong to this asset.';
$_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';

header('Location: /assets/item?id=' . $licenseAssetId);
exit();
