<?php
// Path: _apps/assets/api/stocktake-scan.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Stocktake Scan 📋🔍 (#411, Phase 3 Pass 4)
 * -----------------------------------------------------------------------------
 * Records one scan-to-verify result against an OPEN `tblAssetStocktakes` run
 * from a handheld/mobile scanner mid-run, via `AssetRegister::
 * recordStocktakeScan()` — the SAME method `_apps/assets/stocktake-save.php`
 * (action=scan) calls for the HTML scan screen, so the two can never drift.
 *
 *   POST /api/assets/stocktake-scan
 *   Content-Type: application/json
 *   {
 *     "stocktakeID":     42,               (required)
 *     "code":            "AST-0042",       (required — matched against
 *                                            assetTagCode/serialNumber/
 *                                            publicToken/identifier value,
 *                                            see findByScanCode())
 *     "foundLocationID": 7                 (optional — site-scoped FK,
 *                                            silently dropped if it doesn't
 *                                            resolve)
 *   }
 *
 * Gated by `api.assets.stocktake-scan.enabled` (seeded 'true' in migration
 * 161) via `ApiRouter::resolveEnabledFlag()`; reachable ONLY at the
 * ApiRouter convention path `_apps/assets/api/stocktake-scan.php` — this
 * action is deliberately NOT registered in `tblRoutes` (see .claude/
 * CLAUDE.md → "ApiRouter routing trap").
 *
 * 🔒 CONFIDENTIAL ASSETS are kept NO WIDER than the HTML scan screen: when
 * the resolved asset is `isConfidential` AND the caller authenticated with
 * a bearer key (`ApiAuth::isBearer() === true` — a privileged session never
 * redacts, since `requireWrite()` below already required it to be an admin
 * session, same "the session already saw the real name on the manager
 * screen" reasoning as `list.php`/`detail.php`'s own confidential gates),
 * the asset's name is replaced with a fixed placeholder and its id is
 * omitted from the response — the presence itself is still recorded
 * (verifyStatus is always reported; the scan was written to
 * `tblAssetStocktakeItems` regardless), only the IDENTITY of a confidential
 * asset never reaches a bearer-authenticated integration.
 *
 * @package   Portal\API\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/411
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\AssetRegister;

// 🔐 Same write-auth gate every other Asset Tracker write endpoint uses
// (create.php/update.php/delete.php) — bearer `assets:write` scope OR an
// admin session + CSRF. Terminates 401/403/429 on failure. Returns the
// decoded JSON body.
ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('assets:write');

// 🧮 Read stocktakeID/code/foundLocationID from the JSON body first (the
// house convention for every other Asset Tracker write endpoint — see
// create.php/update.php), falling back to $_POST so a plain form-encoded
// caller (e.g. a simple scanner app that never sets Content-Type: json)
// still works.
$stocktakeId = (int) ($body['stocktakeID'] ?? $_POST['stocktakeID'] ?? 0);
$code        = trim((string) ($body['code'] ?? $_POST['code'] ?? ''));

$foundLocationRaw = $body['foundLocationID'] ?? $_POST['foundLocationID'] ?? null;
$foundLocationId  = ($foundLocationRaw !== null && $foundLocationRaw !== '') ? (int) $foundLocationRaw : null;
if ($foundLocationId !== null && $foundLocationId <= 0) {
    $foundLocationId = null;
}

if ($stocktakeId <= 0 || $code === '') {
    ApiResponse::error('stocktakeID and code are required', 400);
}

$actorUserId = ApiAuth::actorUserId() ?? 0;
$result = AssetRegister::recordStocktakeScan($stocktakeId, $code, $foundLocationId, $actorUserId);

if ($result['ok'] !== true) {
    // 🪞 recordStocktakeScan() returns a single friendly $msg for every
    // failure mode (run not found/already closed, code not recognised) —
    // all of them map to 404 here, since neither is a malformed-request
    // (400) situation and the caller has no separate id to disambiguate.
    ApiResponse::error((string) $result['msg'], 404);
}

// -----------------------------------------------------------------------
// 🔒 Confidential-asset redaction — see file header. Only a BEARER caller
// is ever redacted; a session caller already had to be an ADMIN to reach
// requireWrite()'s own gate above (its default $sessionNeedsAdmin=true —
// same as every other Asset Tracker write endpoint), so it sees the real
// name exactly like the HTML stocktake.php screen does.
// -----------------------------------------------------------------------
$isConfidential = (bool) ($result['isConfidential'] ?? false);
$redact         = $isConfidential === true && ApiAuth::isBearer() === true;

$payload = ['verifyStatus' => $result['verifyStatus']];
if ($redact === true) {
    $payload['assetName'] = '(confidential asset)';
} else {
    $payload['assetID']   = $result['assetID'];
    $payload['assetName'] = $result['assetName'];
}

ApiResponse::success($payload);
