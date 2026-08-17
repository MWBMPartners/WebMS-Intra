<?php
// Path: _apps/assets/api/create.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Create Asset 📦➕ (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * Creates a new physical or digital asset for the current site.
 *
 *   POST /api/assets/create
 *   POST /api/v1/assets
 *   Content-Type: application/json
 *   {
 *     "name":              "Conference room projector",  (required, ≤255)
 *     "assetKind":         "physical",                     (optional, default "physical")
 *     "categoryID":        3,                               (optional — site-scoped FK, silently
 *                                                             dropped if it doesn't resolve)
 *     "locationID":        7,                               (optional — same FK rule)
 *     "manufacturer":      "Epson",                         (optional)
 *     "model":             "EB-2250U",                      (optional)
 *     "serialNumber":      "X1J0123456",                    (optional)
 *     "assetTagCode":      "AST-0042",                      (optional — UNIQUE per site)
 *     "conditionState":    "good",                          (optional, default "good")
 *     "status":            "in-service",                    (optional, default "in-service")
 *     "purchaseDate":      "2024-09-01",                    (optional, Y-m-d)
 *     "purchaseCostPence": 89999,                            (optional — PENCE, not pounds)
 *     "currency":          "GBP",                            (optional, default "GBP")
 *     "isConfidential":    false,                            (optional, default false)
 *     "publicPageEnabled": true,                             (optional, default true)
 *     "labelSymbology":    "qr"                              (optional, default "qr")
 *   }
 *   …plus every other AssetRegister::createAsset() field — see
 *   `_apps/assets/api/_coerce.php`'s `assets_api_build_data()` for the full
 *   list and this endpoint's TWO deliberate departures from the HTML
 *   `save.php` form it mirrors (pence-not-pounds money fields; hard 400 on
 *   an invalid ENUM rather than a silent default).
 *
 * Every field is validated/coerced EXACTLY like `_apps/assets/save.php`
 * (ENUM allow-lists, site-scoped FK existence checks, the licenseKey
 * plaintext→encrypt-on-write path via `AssetRegister::createAsset()`) — see
 * `_coerce.php`'s file header for the shared choke-point this and
 * `update.php` both call through, so the two can never silently drift.
 *
 * Confidential assets and the encrypted `licenseKey`/`publicToken` columns
 * are writable here exactly as they are from the HTML form — the response
 * never echoes them back (`ApiResponse::filterSensitive()`).
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

// 🔗 Shared field coercion — plain require_once, NOT a tblRoutes entry (leading
//    underscore keeps it out of ApiRouter's action-name pattern — see that
//    file's own header).
require_once __DIR__ . DIRECTORY_SEPARATOR . '_coerce.php';

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('assets:write');

// 🧮 Full field coercion (existing=null ⇒ create semantics) — terminates
// with a 400 ApiResponse::error() on any hard validation failure.
$data = assets_api_build_data($body, null);

$actorUserId = ApiAuth::actorUserId() ?? 0;
$newId = AssetRegister::createAsset($data, $actorUserId);
if ($newId <= 0) {
    // 🪞 Most likely cause: assetTagCode collides with another asset on
    // this site (uq_asset_tag) — see AssetRegister::createAsset()'s own
    // comment. Not distinguishable from a generic DB failure without a
    // second lookup, so 409 covers the common case without a false-positive
    // "your request was malformed" 400.
    ApiResponse::error('Could not create the asset — the asset tag code may already be in use on this site', 409);
}

// 📤 Re-fetch via the SAME site-scoped, non-deleted read path every other
// handler uses, then strip the two fields that must NEVER leave this API.
$created = AssetRegister::get($newId);
$asset   = $created !== null
    ? ApiResponse::filterSensitive($created, ['licenseKey', 'publicToken'])
    : ['assetID' => $newId];

ApiResponse::success(['asset' => $asset], 201);
