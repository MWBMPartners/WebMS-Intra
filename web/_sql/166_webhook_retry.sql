-- =============================================================================
-- Migration 166: Outbound webhook async retry cron (#324 v1.1 follow-up)
-- =============================================================================
-- Ships the cron-driven async retry worker WebhookDispatcher.php's own class
-- header flagged as a v1.1 follow-up ("Async retry worker (cron-driven,
-- exponential backoff, dead-letter)"): a delivery that failed its initial
-- synchronous POST (WebhookDispatcher::emit()) now gets picked back up by
-- `cron/webhook-retry.php` -> WebhookDispatcher::retryDue() until it either
-- succeeds, or exhausts MAX_ATTEMPTS (6, PHP-side constant) and is
-- dead-lettered (status = 'dead').
--
-- Schema (both guarded -- tblWebhookDeliveries already exists as of
-- migration 111):
--   1. `nextRetryAt` DATETIME NULL -- the earliest time retryDue() may
--      re-attempt a 'failed' row. NULL means "never attempted a retry yet
--      OR terminal (delivered/dead)" -- retryDue()'s own WHERE clause reads
--      `nextRetryAt IS NULL OR nextRetryAt <= NOW()`.
--   2. `idx_wd_retry` (status, nextRetryAt) -- keeps that scan sargable; the
--      existing `idx_wd_pending` (status, lastAttemptAt) doesn't cover the
--      new column.
--
-- Portable DDL (DEV_NOTES.md -> "Portable DDL convention (MySQL 8.0 and
-- MariaDB)"): MySQL 8 rejects MariaDB's `ADD COLUMN`/`ADD INDEX IF NOT
-- EXISTS` with ERROR 1064, so both go through the information_schema +
-- PREPARE/EXECUTE guard idiom (house examples: migrations 037, 112, 138,
-- 160).
--
-- Cron token: `webhooks.cron_token` is seeded EMPTY (isSensitive = 1) --
-- same "an admin must set a real token before the endpoint accepts any
-- request" convention as `assets.cron_token` (migration 160) /
-- `reminders.cron_token` / `discipleship.cron_token`; an empty stored token
-- ALWAYS 403s (see cron/webhook-retry.php). `cron/webhook-retry` is seeded
-- `isProtected = 0` -- public route, token-gated internally -- mirrors
-- every other cron/* route (cron/event-reminders, cron/asset-reminders,
-- cron/discipleship-sweep).
--
-- Replay/no-op proof: two guarded ALTERs (information_schema-checked), two
-- seed INSERTs (both ON DUPLICATE KEY UPDATE), and the standard idempotent
-- tblMigrations self-record. No bare DDL, no unguarded INSERTs.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/324
-- =============================================================================

-- #############################################################################
-- SCHEMA
-- #############################################################################

-- ➕ tblWebhookDeliveries.nextRetryAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWebhookDeliveries'
      AND COLUMN_NAME  = 'nextRetryAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblWebhookDeliveries` ADD COLUMN `nextRetryAt` DATETIME DEFAULT NULL COMMENT ''Earliest time the async retry worker may re-attempt this delivery (exponential backoff, migration 166, #324 v1.1)'' AFTER `lastAttemptAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_wd_retry — guarded ADD INDEX (WebhookDispatcher::retryDue()'s own scan)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWebhookDeliveries'
      AND INDEX_NAME   = 'idx_wd_retry'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblWebhookDeliveries` ADD INDEX `idx_wd_retry` (`status`, `nextRetryAt`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- ⚙️ Cron token — seeded empty; the endpoint 403s until an admin sets a
-- real value via /admin/settings (isSensitive = 1, encrypted at rest).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'webhooks.cron_token', '', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🗺️ Route — public (token-gated internally), mirrors every other cron/* route.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('cron/webhook-retry', 'cron/webhook-retry.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('166_webhook_retry.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
