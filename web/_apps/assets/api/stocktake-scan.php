<?php
// Path: _apps/assets/api/stocktake-scan.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Stocktake Scan 📋🔍 (#411, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * Placeholder for the scan-to-verify API endpoint the #411 stocktake UI will
 * call from a handheld/mobile scanner mid-run — will accept a POST
 * recording one `tblAssetStocktakeItems` scan result (present/moved/
 * unexpected, plus an optional `foundLocationID`) against an open
 * `tblAssetStocktakes` run. Gated by `api.assets.stocktake-scan.enabled`
 * (seeded 'true' in migration 161 — same "flag seeded now, handler lands
 * later" precedent as migration 159's `api.assets.qr.enabled`) via
 * `ApiRouter::resolveEnabledFlag()`; reachable ONLY at the ApiRouter
 * convention path `_apps/assets/api/stocktake-scan.php` — this action is
 * deliberately NOT registered in `tblRoutes` (see .claude/CLAUDE.md →
 * "ApiRouter routing trap").
 *
 * This pass ships ONLY the auth gate + a 501 "not yet implemented"
 * response — no scan is recorded, no row is read or written. The real
 * scan-recording logic lands in a later Phase 3 pass.
 *
 * @package   Portal\API\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/411
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;

// 🔐 Same write-auth gate every other Asset Tracker write endpoint uses
// (create.php/update.php/delete.php) — bearer `assets:write` scope OR an
// admin session + CSRF. Terminates 401/403/429 on failure before this
// stub's own 501 response is ever reached, so an unauthenticated/
// unauthorised caller learns nothing beyond "not authorised" — never
// "not yet implemented".
ApiAuth::requireWrite('assets:write');

// 🚧 Not implemented this pass — see file header. `ApiResponse::error()`
// (NOT `::ok()` — see .claude/CLAUDE.md → "ApiRouter routing trap" /
// "Adjacent gotcha") terminates the request with a 501 JSON error body.
ApiResponse::error('Not yet implemented', 501);
