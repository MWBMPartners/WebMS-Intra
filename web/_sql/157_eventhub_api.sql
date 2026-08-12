-- =============================================================================
-- Migration 157: Event Team Hub REST API read endpoints (#387)
-- =============================================================================
-- SETTINGS SEEDS ONLY — no schema changes, no tblRoutes rows. Enables the two
-- new `_apps/calendar/api/{action}.php` read handlers shipped alongside this
-- migration (hub-resources.php, hub-videos.php) that expose the Event Team
-- Hub's `tblEventHubResources` / `tblEventHubVideos` tables (migration 155,
-- #386) to the projectBookIT Event Team Hub Phase 3 integration
-- (projectbookit#347) over the public REST API, gated behind the new
-- `eventhub:read` bearer-key scope (see `ApiKey::SCOPES`).
--
-- `api/*` paths are handled by `ApiRouter::dispatch()`, which resolves
-- `_apps/{appName}/api/{action}.php` directly and NEVER consults tblRoutes
-- (Router::handleSpecialRoutes intercepts `api/*` before tblRoutes is ever
-- looked at) — see .claude/CLAUDE.md → "ApiRouter routing trap". The ONLY
-- gate an `api/*` handler needs is the `api.{appName}.{action}.enabled`
-- settings flag seeded below; adding a `tblRoutes` row for either endpoint
-- would be dead configuration the router never reads, so this migration
-- deliberately adds none.
--
-- Replay/no-op proof: the only statements are two `tblSettings` rows guarded
-- by `ON DUPLICATE KEY UPDATE` and the standard idempotent tblMigrations
-- self-record — there are no ALTERs and no CREATEs, so a second run is a
-- pure no-op.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/387
-- =============================================================================

-- 📋 Enable flags for the two new read-only handlers (mirrors the
--    `(siteID, settingKey, settingValue, defaultValue, isSensitive)` idiom
--    used by every prior `api.*.enabled` seed, e.g. migration 147).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.calendar.hub-resources.enabled', 'true', 'true', 0),
    (NULL, 'api.calendar.hub-videos.enabled',    'true', 'true', 0)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('157_eventhub_api.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
