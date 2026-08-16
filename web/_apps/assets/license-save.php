<?php
// Path: _apps/assets/license-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Link a Licence Seat 🎟️
 * -----------------------------------------------------------------------------
 * POST handler for item.php's "Licence & seats" panel add-form — links
 * (assigns) a seat on a digital licence asset to a device asset, a portal
 * user, or a free-text device name, via AssetRegister::assignSeat().
 *
 * Gate: admin OR asset_manager role ONLY (manager-only, deliberately NOT
 * widened to isResponsibleFor()/isMaintenanceAuthority/isLendingAuthority —
 * see AssetRegister::assignSeat()'s class-header doc, point 7: licence
 * management is treated as a manager action, mirroring owners-save.php's
 * own manager-only gate for the analogous reason — this mirrors the
 * highest-stakes of this app's existing owner/loan/maintenance authority
 * gates rather than the narrowest one).
 *
 * Every validation rule (the licence asset must exist on-site AND be
 * assetKind='digital', the exactly-one-target rule across deviceAssetID/
 * userID/deviceName, FK existence/site-scope for a device-asset or user
 * target, seat-count enforcement) lives entirely inside
 * AssetRegister::assignSeat() — this controller's own job is limited to
 * CSRF, the manager gate, and coercing $_POST into the shape that method
 * expects; it does NOT duplicate that validation. Any warnings
 * assignSeat() returns (seat over-allocation, or a hard block when
 * `assets.license_seat_block` is enabled) are flashed to the user
 * alongside the outcome.
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

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/maintenance-save.php/loan-save.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. See file header
// for why this is deliberately NOT widened to a responsible/authority
// owner-party.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$userId          = (int) ($_SESSION['user_id'] ?? 0);
$licenseAssetId  = (int) ($_POST['licenseAssetID'] ?? 0);

// 🔒 Site-scope + digital-kind pre-check up front, purely for a friendlier
// early redirect — AssetRegister::assignSeat() re-checks BOTH of these
// itself regardless (see that method's own doc), so this is not the only
// thing standing between a tampered POST and rejection.
$licenseAsset = $licenseAssetId > 0 ? AssetRegister::get($licenseAssetId) : null;
if ($licenseAsset === null) {
    $_SESSION['flash_msg']  = 'Licence asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🔀 Only the ONE target field matching the posted targetType is ever read
// by assignSeat() — see that method's own re-validation of the
// exactly-one-target rule; every other candidate field posted here is
// simply ignored rather than trusted (mirrors owners-save.php's
// partyType-matched-field convention for addOwner()).
$targetType = (string) ($_POST['targetType'] ?? '');
$data = [
    'deviceAssetID' => $targetType === 'device' ? (int) ($_POST['deviceAssetID'] ?? 0) : 0,
    'userID'        => $targetType === 'user'   ? (int) ($_POST['userID'] ?? 0)        : 0,
    'deviceName'    => $targetType === 'other'  ? (string) ($_POST['deviceName'] ?? '') : '',
    'seatLabel'     => (string) ($_POST['seatLabel'] ?? ''),
    'notes'         => (string) ($_POST['notes'] ?? ''),
];

$result = AssetRegister::assignSeat($licenseAssetId, $data, $userId);

if ($result['id'] > 0) {
    // ✅ Saved — a seat-over-allocation warning (non-blocking) may still
    // be present alongside the success; surface both rather than hiding
    // the warning behind a plain "success" message.
    $_SESSION['flash_msg']  = 'Seat linked.' . (count($result['warnings']) > 0 ? ' ' . implode(' ', $result['warnings']) : '');
    $_SESSION['flash_type'] = count($result['warnings']) > 0 ? 'warning' : 'success';
} else {
    // ❌ Rejected — validation failure OR a hard block (seat_block=1).
    $_SESSION['flash_msg']  = count($result['warnings']) > 0
        ? implode(' ', $result['warnings'])
        : 'Could not link that seat — check the target selection (exactly one is required) and try again.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /assets/item?id=' . $licenseAssetId);
exit();
