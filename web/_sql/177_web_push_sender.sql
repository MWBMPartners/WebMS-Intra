-- =============================================================================
-- Migration 177: Web Push sender — VAPID + RFC 8291, live/reminder channels (#322)
--
-- Path: web/_sql/177_web_push_sender.sql
-- Migration 111 (`111_cop_easy_wins.sql`) shipped `tblPushSubscriptions` +
-- the four `push.vapid*`/`push.contact`/`push.enabled` settings, plus the
-- subscribe/unsubscribe handlers — but at `_apps/api/push/{subscribe,
-- unsubscribe}.php`, a path ApiRouter never resolves (it maps
-- `api/{appName}/{action}` to `_apps/{appName}/api/{action}.php`), and with
-- no `api.push.*.enabled` flags seeded even if the path had been right.
-- Both were unreachable dead code with zero sender anywhere. This migration
-- is schema/seed support for: (1) the relocated handlers at
-- `_apps/push/api/{subscribe,unsubscribe}.php` (#322 discovery, same class
-- of fix as migration 144's livestream/ping relocation), (2) the new
-- `Portal\Core\WebPush` sender class, (3) the "we're live now" + service-
-- reminder channel wiring, (4) `/admin/integrations/push` config.
--
-- ZERO new tables. Three additive guarded ALTERs on the already-live
-- `tblPushSubscriptions` (dead-subscription pruning bookkeeping — RFC 8030
-- §7.3), settings seeds, and route seeds. `push.vapidPublicKey` /
-- `push.vapidPrivateKey` / `push.contact` / `push.enabled` already exist
-- (migration 111) and are deliberately NOT re-seeded here — the feature
-- stays INERT (WebPush::isConfigured() === false) until an owner visits
-- `/admin/integrations/push` and generates or pastes a key pair.
--
-- Auto-go-live dedupe reuses `tblUserReminderLog` (migration 171's
-- documented reserved purpose — `refType`/`refID`/`dueDate` with zero new
-- DDL); the service-reminder push shares `tblEventReminderLog`'s existing
-- single-shot claim (migration 037/`cron/event-reminders.php` — push goes
-- out iff the email batch for that window goes out).
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in
-- this file — check_schema_seed_parity.py strips from any "--" to
-- end-of-line, even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/322
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLE — tblPushSubscriptions additive columns (dead-subscription pruning)
-- #############################################################################

-- ➕ tblPushSubscriptions.failCount — consecutive transient-failure counter
-- (RFC 8030 §7.3). Guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPushSubscriptions'
      AND COLUMN_NAME  = 'failCount'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPushSubscriptions` ADD COLUMN `failCount` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Consecutive transient (429/5xx/timeout) failures; >= 8 deactivates the subscription'' AFTER `isActive`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblPushSubscriptions.lastFailureAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPushSubscriptions'
      AND COLUMN_NAME  = 'lastFailureAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPushSubscriptions` ADD COLUMN `lastFailureAt` DATETIME DEFAULT NULL COMMENT ''Timestamp of the most recent transient send failure'' AFTER `failCount`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblPushSubscriptions.lastHttpStatus — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPushSubscriptions'
      AND COLUMN_NAME  = 'lastHttpStatus'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPushSubscriptions` ADD COLUMN `lastHttpStatus` SMALLINT DEFAULT NULL COMMENT ''Last push-service HTTP response code (201/404/410/429/5xx) — diagnostics on the admin page'' AFTER `lastFailureAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- ⚙️ SETTINGS SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- `api.push.{subscribe,unsubscribe}.enabled` — the ApiRouter gating flags the
-- relocated handlers need (without these, ApiRouter 403s even at the
-- correct `_apps/push/api/*.php` path). `push.ttl.*` are RFC 8030 §5.2 TTL
-- defaults (a stale go-live push older than 15 min is pointless). Auto-
-- notify toggles default OFF — the manual admin button is the primary
-- go-live trigger; auto-detect and the anonymous "starting soon" broadcast
-- are opt-in escalations. `push.cron_token` follows the six-of-six
-- empty-fails-closed convention (171:73-77) — a DEDICATED token for
-- `cron/push-golive.php`, independent of `reminders.cron_token` /
-- `user_reminders.cron_token`, so per-endpoint rotation stays independent.
-- `push.endpointHostAllowlist` is the SSRF suffix allowlist
-- (WebPush::validateEndpoint()) — admin-editable so a new browser vendor's
-- push-service host never requires a code release.
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.push.subscribe.enabled',   'true',  'true',  0),
    (NULL, 'api.push.unsubscribe.enabled', 'true',  'true',  0),
    (NULL, 'push.ttl.golive',              '900',   '900',   0),
    (NULL, 'push.ttl.reminder',            '3600',  '3600',  0),
    (NULL, 'push.golive.auto',             'false', 'false', 0),
    (NULL, 'push.reminders.broadcast',     'false', 'false', 0),
    (NULL, 'push.cron_token',              '',      '',      1),
    (NULL, 'push.endpointHostAllowlist',
           'fcm.googleapis.com,push.services.mozilla.com,push.apple.com,notify.windows.com,windows.com,pushsvc.mozilla.com',
           'fcm.googleapis.com,push.services.mozilla.com,push.apple.com,notify.windows.com,windows.com,pushsvc.mozilla.com',
           0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️ ROUTES SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- `admin/integrations/push*` — protected (admin session required, checked
-- again inside each handler). `cron/push-golive` — isProtected=0 (public
-- but token-gated internally, same pattern as `cron/event-reminders` /
-- `cron/user-reminders`). No `api/push/*` rows — the ApiRouter routing
-- trap: Router never consults tblRoutes for `api/*` paths, the settings
-- flags above are the only gate those need.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/push',      'admin/integrations/push/index.php', 1),
    ('admin/integrations/push/save', 'admin/integrations/push/save.php',  1),
    ('admin/integrations/push/test', 'admin/integrations/push/test.php',  1),
    ('cron/push-golive',             'cron/push-golive.php',              0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 SELF-RECORD
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('177_web_push_sender.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
