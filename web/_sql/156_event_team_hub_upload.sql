-- =============================================================================
-- Migration 156: Event Team Hub — Phase 1.5 Cloudflare Stream direct upload (#386)
-- =============================================================================
-- ROUTES ONLY — no schema changes. `tblEventHubVideos` already ships with
-- its FINAL shape in migration 155 (`uploadStatus`, `errorDetail`,
-- `uploadedAt`, `lastCheckedAt`, `allowedOrigins` columns all present since
-- day one), and every `cfstream.*` setting (including `apiToken`,
-- `maxUploadDurationSeconds`, `uploadMintPerHour`,
-- `defaultRequireSignedUrls`, `allowedOrigins`) was already seeded in 155's
-- settings block. This migration seeds only the 3 page routes whose handler
-- files ship in this same commit — 155 deliberately left them out because
-- their target files didn't exist yet (`check_route_targets.py` would have
-- failed on a missing target).
--
-- Plain `calendar/event/hub/*` page routes — NOT under `api/*` — so no
-- `api.*.enabled` seed is needed; ApiRouter never engages for these paths
-- (see .claude/CLAUDE.md → "ApiRouter routing trap").
--
-- Replay/no-op proof: the route INSERT carries ON DUPLICATE KEY UPDATE, the
-- tblMigrations self-record carries ON DUPLICATE KEY UPDATE, and there are
-- no ALTERs at all — a second run is a pure no-op.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/386
-- =============================================================================

-- 📋 Page routes for the direct-upload mint, status-poll, and per-video
--    Stream-settings handlers. All three are gated at runtime behind
--    `Portal\Core\CloudflareStream::isConfigured()` — with no API token
--    configured, calling any of them 503s without an actual admin-facing
--    change of behaviour (the hub UI simply never renders the links/forms
--    that would post to them).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/hub/upload-url',    'calendar/event-hub-upload-url.php',    1),
    ('calendar/event/hub/video-status',  'calendar/event-hub-video-status.php',  1),
    ('calendar/event/hub/video-settings','calendar/event-hub-video-settings.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('156_event_team_hub_upload.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
