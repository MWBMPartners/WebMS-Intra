<?php
// Path: _apps/assets/api/delete.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Delete (soft) Asset 📦🗑️ (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * Soft-deletes an asset via `AssetRegister::softDeleteAsset()` — sets
 * `isDeleted = 1` only, NEVER a hard DELETE (every child row — resources/
 * owners/loans/maintenance/audit trail/… — keeps a valid `assetID` to point
 * back at; see that method's own docblock).
 *
 *   POST /api/assets/delete?id=N   (or {"assetID": N})
 *   DELETE /api/v1/assets/{id}
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

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('assets:write');

$assetId = (int) ($_GET['id'] ?? $body['assetID'] ?? 0);
if ($assetId <= 0) {
    ApiResponse::error('assetID is required', 400);
}

$actorUserId = ApiAuth::actorUserId() ?? 0;

// 🔒 softDeleteAsset() is itself Site::id()-scoped (never a cross-site
// delete) and returns false for "doesn't exist / already deleted / not on
// this site" — all three collapse to the same 404, exactly like the
// events/leadership/etc precedents, so a cross-tenant probe never confirms
// another site's asset exists.
$ok = AssetRegister::softDeleteAsset($assetId, $actorUserId);
if ($ok === false) {
    ApiResponse::error('Asset not found or already deleted', 404);
}

ApiResponse::success(['assetID' => $assetId, 'deleted' => true], 200);
