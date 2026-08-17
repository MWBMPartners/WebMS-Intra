<?php
// Path: _apps/assets/identifier-verify.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Verify Identifier (GS1 Digital Link / GEPIR) 🔗✅ (#415, Phase 3 Pass 6 — FINAL)
 * -----------------------------------------------------------------------------
 * POST handler for the item.php Identifiers panel's per-row "Verify"
 * button. Replaces the Pass-1 placeholder (which shipped only the route
 * + a manager-gated stub so `check_route_targets.py` stayed green while
 * the schema landed ahead of the logic — see migration 161's header).
 *
 * Delegates every bit of real work to `AssetRegister::verifyIdentifier()`
 * (local GS1 mod-10 check-digit ALWAYS, then an optional GEPIR lookup
 * when `assets.gepir_verify_enabled`/`assets.gepir_endpoint` are
 * configured — see that method's own doc for the full two-layer design)
 * — this controller's own job is limited to CSRF, the manager gate, and
 * turning the returned `$result['msg']` into a flash + redirect, exactly
 * the same shape as `identifiers-save.php`'s own single-route-multi-
 * outcome controller.
 *
 * Gate: admin OR asset_manager role ONLY — same manager-only gate as
 * `identifiers-save.php`/`owners-save.php` (deliberately NOT widened to
 * `isResponsibleFor()`; curating/verifying identifiers is a manager
 * action, same rationale as `identifiers-save.php`'s own header).
 *
 * No HTML rendered — this file only ever redirects.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/415
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// identifiers-save.php/owners-save.php/save.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. See file
// header for why this is deliberately NOT widened to isResponsibleFor().
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$userId       = (int) ($_SESSION['user_id'] ?? 0);
$identifierId = (int) ($_POST['identifierID'] ?? 0);
$assetId      = (int) ($_POST['assetID'] ?? 0);

// 🔒 Site-scope the asset up front, same convention as
// identifiers-save.php — AssetRegister::get() is itself site-scoped via
// Site::id(), so an assetID belonging to another tenant resolves to null
// here and the redirect target below stays a safe generic listing.
// (verifyIdentifier() ALSO re-checks identifierID+assetID+siteID itself
// before touching a row — this is defence in depth, not the only guard.)
$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null || $identifierId <= 0) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$result = AssetRegister::verifyIdentifier($identifierId, $assetId, $userId);

$_SESSION['flash_msg']  = (string) $result['msg'];
$_SESSION['flash_type'] = $result['ok'] === true
    ? ($result['verified'] === true ? 'success' : 'warning')
    : 'danger';

header('Location: /assets/item?id=' . $assetId);
exit();
