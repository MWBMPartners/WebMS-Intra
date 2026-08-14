-- =============================================================================
-- Migration 158: Worship live-sync relocation + AppRegistry enablement +
--                 dead api/* tblRoutes cleanup + CF Stream test-connection
--                 route (#373 / #308 / #255 / #386)
-- =============================================================================
-- Four related fixes, bundled because the middle two (AppRegistry) would be
-- unsafe to ship without the app-enable seeds also in this file:
--
-- 1. WORSHIP LIVE-SYNC (#373 fold-in / #308 residual). The operator console
--    (`worship/present.php`) and public projector display
--    (`worship/display.php`) poll `/api/worship/state` + `/api/worship/advance`
--    every 500ms-1s. Those handlers used to sit at the unreachable legacy
--    path `_apps/api/worship-{state,advance}.php` — ApiRouter::dispatch()
--    resolves `api/{appName}/{action}` to `_apps/{appName}/api/{action}.php`
--    directly and NEVER consults tblRoutes (see .claude/CLAUDE.md →
--    "ApiRouter routing trap"), so both calls 404'd and the operator↔display
--    sync could never work. This session relocated the handlers verbatim to
--    `_apps/worship/api/{state,advance}.php` (precedent: migration 144 did
--    the identical relocation for `api/livestream/ping`). Seed the two
--    `api.worship.*.enabled` flags below — ApiRouter 403s a convention-path
--    handler with no enabled flag exactly like a missing one.
--
-- 2. APPREGISTRY ENTRIES (#255) for noticeboard/worship/salvation/kids —
--    added as `_core/apps/{slug}.php` this session so all four surface in
--    the /admin/apps marketplace toggle. ⚠️ TRAP: `AppRegistry::isEnabled()`
--    returns FALSE when the app's settingKey is missing, and
--    `Router::dispatch()` 403s a registered-but-disabled app's routes
--    (`AppRegistry.php:95-116`, `Router.php:113-117`). worship/salvation/kids
--    have NO enable flag today — they are always-on by virtue of NOT being
--    registered. Registering them without seeding `true` here would silently
--    403 three live apps. `noticeboard.enabled` is already seeded
--    (`full_schema.sql`, migration 145) — not duplicated here.
--
-- 3. DEAD api/* tblRoutes CLEANUP. `Router::handleSpecialRoutes()` hands
--    every `api/*` path straight to `ApiRouter::dispatch()` before tblRoutes
--    is ever queried — so no `api/*` routeKey row is EVER reachable
--    (.claude/CLAUDE.md → "ApiRouter routing trap"). 19 such rows had
--    accumulated across migrations 035(→056)/082/099/100/106/111/133/138 —
--    the two worship rows above, plus 9 more pointing at already-orphaned
--    `_apps/api/*` handlers (tours/translate/ai-assist/push — parked, not
--    relocated: no live caller for any of them, see discovery notes) and
--    the 10 announcements/tasks/prayer-requests/leadership rows from
--    migration 106 that duplicate their real (settings-gated, convention-
--    path) endpoints. Precedent: migration 056 did this exact cleanup for
--    an earlier batch of 5. `DELETE ... WHERE routeKey IN (...)` (rather
--    than a `LIKE 'api/%'` pattern) so `check_route_targets.py` /
--    `check_schema_seed_parity.py`'s static DELETE-tombstone parser — which
--    only recognises `= '...'` / `IN (...)`, not `LIKE` — can model the
--    removal and confirm full_schema.sql's parity. Does NOT touch any
--    `api.*.enabled` SETTINGS row — those gate the real convention-path
--    handlers and must stay.
--
-- 4. CLOUDFLARE STREAM "TEST CONNECTION" ROUTE (#386 fold-in, ENH 8). Admin
--    settings page gets a "Test connection" button calling
--    `CloudflareStream::testConnection()` — parity with the BookIT Phase 2
--    affordance. This is a plain `tblRoutes` page route (POST handler under
--    `/admin/integrations/cloudflare-stream/`), NOT an `api/*` path, so
--    (unlike the worship endpoints above) it DOES need a tblRoutes row —
--    mirrors the two sibling rows already seeded by migration 155.
--
-- Replay/no-op proof: three `tblSettings`/`tblRoutes` INSERTs guarded by
-- `ON DUPLICATE KEY UPDATE`, one `DELETE ... WHERE routeKey IN (...)` (a
-- second run deletes zero rows — DELETE is idempotent by construction), and
-- the standard idempotent tblMigrations self-record. No ALTERs, no CREATEs.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/373
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/255
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/386
-- =============================================================================

-- 📋 1. Enable flags for the relocated worship live-sync endpoints.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.worship.state.enabled',    'true', 'true', 0),
    (NULL, 'api.worship.advance.enabled',  'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 📋 2. AppRegistry enable flags for worship/salvation/kids — keeps all
--    three "on" now that they're registered (see trap note above).
--    noticeboard.enabled is already seeded (migration 145) — not repeated.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'worship.enabled',   'true', 'true', 0),
    (NULL, 'salvation.enabled', 'true', 'true', 0),
    (NULL, 'kids.enabled',      'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🗑️ 3. Dead api/* tblRoutes cleanup — 19 rows that Router::handleSpecialRoutes
--    guarantees can never match (see header). Idempotent: a second run
--    deletes zero rows.
DELETE FROM `tblRoutes` WHERE `routeKey` IN (
    'api/tours/active',
    'api/tours/complete',
    'api/translate',
    'api/ai-assist/improve',
    'api/announcements/create',
    'api/announcements/update',
    'api/announcements/delete',
    'api/tasks/create',
    'api/tasks/complete',
    'api/tasks/delete',
    'api/prayer-requests/create',
    'api/prayer-requests/moderate',
    'api/leadership/assign',
    'api/leadership/unassign',
    'api/push/subscribe',
    'api/push/unsubscribe',
    'api/livestream/ping',
    'api/worship/state',
    'api/worship/advance'
);

-- 📋 4. Cloudflare Stream "Test connection" page route.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/cloudflare-stream/test', 'admin/integrations/cloudflare-stream/test.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('158_worship_api_route_cleanup.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
