<?php
// Path: _apps/assets/api/update.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Update Asset 📦✏️ (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * PATCH-style — only fields PRESENT in the JSON body are touched; anything
 * omitted keeps its current value. (Note this is a stronger guarantee than
 * `_apps/assets/save.php`'s own HTML form, which always re-posts every
 * field.) "Current" means the value in the row at the moment of the write,
 * read UNDER the asset's lock (#1001): this handler tells
 * `AssetRegister::updateAsset()` which fields it was sent, and that method
 * writes every other column back as the locked row holds it — so a change
 * somebody else made after this handler's own read survives. Until #1001
 * the omitted fields were filled from that earlier read and written as if
 * posted, which silently put such a change back.
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

// 🧮 PATCH-safe field coercion — see file header + _coerce.php. It also
// fills every field the body left OUT from $existing, read above, BEFORE the
// asset's lock; those filled-in values are NOT what gets written (#1001,
// next comment) — they only keep $data's shape the one updateAsset() expects.
$data = assets_api_build_data($body, $existing);

// 🧩 Which fields this request was actually SENT (#1001). Handed to
// updateAsset() so it takes ONLY these from $data and writes every other
// column back as the row holds it UNDER THE LOCK. Without this, a change
// somebody else made between the read above and the lock (the edit page
// moving the asset, a stocktake setting its status) was silently put back
// by an API call that never mentioned that field. A key the body sent that
// is not an asset field (`assetID`, `csrf_token`) is not in $data and so
// drops out here.
$sentFields = array_keys(array_intersect_key($data, $body));

$actorUserId = ApiAuth::actorUserId() ?? 0;
$ok = AssetRegister::updateAsset($assetId, $data, $actorUserId, $sentFields);
if ($ok === false) {
    // 🗑️ Deleted while this request waited for the asset's lock (#1031):
    //    updateAsset() asks again under the lock and writes nothing to a
    //    deleted asset. Asked FIRST, because a deleted asset is the reason
    //    that makes every other one beside the point. The answer is the SAME
    //    404 and the same words as the existence check above gives any asset
    //    that is not there, so a caller learns nothing more from the race than
    //    from asking a moment later. (There is no un-delete, so finding it gone
    //    now means it was gone at the write.)
    if (AssetRegister::get($assetId) === null) {
        ApiResponse::error('Asset not found', 404);
    }
    // 💷 A refusal UNDER the asset's lock (a repair recorded in another
    //    currency a moment ago, #966 round 6) is said as such, not blamed on
    //    the tag code.
    if (isset($data['currency']) === true) {
        $currencyRefusal = AssetRegister::whyCurrencyCannotChange($assetId, (string) $data['currency']);
        if ($currencyRefusal !== '') {
            ApiResponse::error($currencyRefusal, 409);
        }
    }
    // 🪞 Most likely cause: assetTagCode collides with another asset on
    // this site (uq_asset_tag) — see AssetRegister::updateAsset()'s own
    // comment. "Not found" was ruled out just above (and before the lock).
    ApiResponse::error('Could not update the asset — the asset tag code may already be in use on this site', 409);
}

$updated = AssetRegister::get($assetId);
$asset   = $updated !== null
    ? ApiResponse::filterSensitive($updated, ['licenseKey', 'publicToken'])
    : ['assetID' => $assetId];

ApiResponse::success(['asset' => $asset], 200);
