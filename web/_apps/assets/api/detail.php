<?php
// Path: _apps/assets/api/detail.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Asset Detail 📦🔍 (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * Returns full detail for a single asset by id.
 *
 *   GET /api/assets/detail?id=N
 *   GET /api/v1/assets/{id}
 *
 * Site-scoped via `AssetRegister::get()` (never a cross-tenant read — a
 * valid id from another site 404s exactly like a nonexistent one, so a
 * probe can never confirm another tenant's asset exists). A CONFIDENTIAL
 * asset is visible only to a logged-in SESSION admin/asset_manager or a
 * responsible owner-party (the SAME gate item.php applies) — never to a
 * bearer key, never to an ordinary logged-in user — and returns a uniform
 * 404 (not 403) so it's not an existence oracle. See `list.php`'s header
 * for why the API must stay no wider than the UI here.
 *
 * @package   Portal\API\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/406
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\AssetRegister;

ApiAuth::requireRead('assets:read');

$assetId = (int) ($_GET['id'] ?? 0);
if ($assetId <= 0) {
    ApiResponse::error('id is required', 400);
}

$asset = AssetRegister::get($assetId);
if ($asset === null) {
    ApiResponse::error('Asset not found', 404);
}

// 🔒 Confidential-asset gate — visible only to a logged-in SESSION admin/
// asset_manager or a responsible owner-party (never a bearer key). Uniform
// 404 (not 403) keeps it from being an existence oracle, matching item.php.
$privileged = ApiAuth::isBearer() === false
    && (App::isAdmin() === true
        || App::hasRole('asset_manager') === true
        || AssetRegister::isResponsibleFor($assetId) === true);
if ((int) ($asset['isConfidential'] ?? 0) === 1 && $privileged === false) {
    ApiResponse::error('Asset not found', 404);
}

// 🔒 licenseKey/publicToken NEVER leave this API — see file header.
ApiResponse::success(['asset' => ApiResponse::filterSensitive($asset, ['licenseKey', 'publicToken'])]);
