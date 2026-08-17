<?php
// Path: _apps/assets/api/update.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Update Asset 📦✏️ (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * PATCH-style — only fields PRESENT in the JSON body are touched; anything
 * omitted keeps its current value. (Note this is a stronger guarantee than
 * `_apps/assets/save.php`'s own HTML form, which always re-posts every
 * field — see `_coerce.php`'s file header for exactly how that's made safe
 * on top of `AssetRegister::updateAsset()`, which was itself built for the
 * "always-full-post" model.)
 *
 *   POST /api/assets/update?id=N        (or {"assetID": N} in body)
 *   PUT/PATCH /api/v1/assets/{id}
 *   Content-Type: application/json
 *   {"status": "in-repair"}
 *
 * Updatable fields: every `AssetRegister::updateAsset()` field — see
 * `create.php`'s docblock for the shape (money fields are PENCE integers;
 * an invalid ENUM value 400s rather than silently defaulting).
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
use Portal\Core\AssetRegister;

require_once __DIR__ . DIRECTORY_SEPARATOR . '_coerce.php';

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('assets:write');

$assetId = (int) ($_GET['id'] ?? $body['assetID'] ?? 0);
if ($assetId <= 0) {
    ApiResponse::error('assetID is required', 400);
}

// 🔍 Site-scoped existence check — AssetRegister::get() already applies
// Site::id() internally (cross-site/missing ids both come back null → 404,
// so a probe against another tenant's asset never confirms it exists).
$existing = AssetRegister::get($assetId);
if ($existing === null) {
    ApiResponse::error('Asset not found', 404);
}

// 🧮 PATCH-safe field coercion — see file header + _coerce.php.
$data = assets_api_build_data($body, $existing);

$actorUserId = ApiAuth::actorUserId() ?? 0;
$ok = AssetRegister::updateAsset($assetId, $data, $actorUserId);
if ($ok === false) {
    // 🪞 Most likely cause: assetTagCode collides with another asset on
    // this site (uq_asset_tag) — see AssetRegister::updateAsset()'s own
    // comment. The existence check above already ruled out "not found".
    ApiResponse::error('Could not update the asset — the asset tag code may already be in use on this site', 409);
}

$updated = AssetRegister::get($assetId);
$asset   = $updated !== null
    ? ApiResponse::filterSensitive($updated, ['licenseKey', 'publicToken'])
    : ['assetID' => $assetId];

ApiResponse::success(['asset' => $asset], 200);
