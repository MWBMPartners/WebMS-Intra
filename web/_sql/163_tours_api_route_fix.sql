-- =============================================================================
-- Migration 163: "What's new" tour API relocation + enable flags (gap fix D2)
-- =============================================================================
-- The tour-playback endpoints the globally-loaded `portal-tour.js`
-- (public_html/assets/js/portal-tour.js, injected on every authenticated page
-- via Asset::portalJs()) polls — `/api/tours/active` (auto-trigger check) and
-- `/api/tours/complete` (mark-done ping) — sat at the unreachable legacy path
-- `_apps/api/tours/{active,complete}.php`. ApiRouter::dispatch() resolves
-- `api/{appName}/{action}` straight to `_apps/{appName}/api/{action}.php` and
-- NEVER consults tblRoutes for api/* paths (see .claude/CLAUDE.md → "ApiRouter
-- routing trap"), so both calls 404'd on every page load and the welcome /
-- what's-new tours driven by `/admin/tours` could never reach a user. Same bug
-- class as the worship live-sync relocation in migration 158 (#373).
--
-- Fix (code, this same change): the handlers were moved verbatim to
-- `_apps/tours/api/{active,complete}.php` — the convention path — and the
-- dead `_apps/api/tours/` directory removed. This migration seeds the two
-- `api.tours.*.enabled` flags ApiRouter requires for a convention-path
-- handler (a missing flag 403s exactly like a missing file). The stray
-- `api/tours/active` / `api/tours/complete` tblRoutes rows seeded by
-- migration 082 were already dead weight and were removed by migration 158's
-- cleanup sweep — nothing to touch here on the tblRoutes side.
--
-- Both handlers already call `ApiResponse::success()` / `ApiResponse::error()`
-- (never the nonexistent `::ok()`), so no handler-side API-contract fix was
-- needed — just the relocation + the missing enable flags.
--
-- Replay/no-op proof: a single `tblSettings` INSERT guarded by
-- `ON DUPLICATE KEY UPDATE`, plus the standard idempotent tblMigrations
-- self-record. No ALTERs, no CREATEs, no DELETEs.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/253
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/373
-- =============================================================================

-- 📋 Enable flags for the relocated tour-playback endpoints.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.tours.active.enabled',   'true', 'true', 0),
    (NULL, 'api.tours.complete.enabled', 'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('163_tours_api_route_fix.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
