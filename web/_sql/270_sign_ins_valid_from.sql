-- =============================================================================
-- Migration 270: a password reset or change signs the person out everywhere
--                else (#598 item 1, D-104), and the sign-in failure count
--                gets the indexes it reads (#598 item 11)
-- =============================================================================
-- What this adds:
--
--   tblUsers.signInsValidFrom   a MOMENT IN UTC. Every browser session that
--                               began BEFORE it is ended on its next request.
--                               NULL means "never set", which ends nothing,
--                               so every existing account behaves exactly as
--                               it did before this migration until its owner
--                               next resets or changes their password.
--
--   tblActivityLogs indexes     `idx_logs_type_ip_time` (activityType,
--                               visitorIP, timestamp) and `idx_logs_type_time`
--                               (activityType, timestamp).
--
-- -----------------------------------------------------------------------------
-- WHY A MOMENT ON THE ACCOUNT, AND NOT "DELETE THE OTHER SESSIONS"
-- -----------------------------------------------------------------------------
-- A browser session is a PHP session FILE on the web server, and the portal
-- keeps no list of which files belong to which person, so there is nothing to
-- find and delete. Instead each session remembers the moment its sign-in
-- began (`Auth::beginSessionFor()` writes it), and `Auth::
-- endSessionOfInactiveAccount()`, which already reads this account's row once
-- for every signed-in request, ends a session whose moment is older than this
-- one. No extra query per request. The browser that MADE the change is given
-- the same moment as the account, so it is the one that stays signed in.
--
-- WHY DATETIME(6). Two sign-ins in the same second as a password change must
-- still be told apart from it: the browser that made the change keeps
-- exactly the stored moment, and a session begun a few microseconds earlier
-- on another browser must not survive by sharing the second. Both MySQL 8.0
-- and MariaDB store six digits of fraction.
--
-- WHY THE MOMENT IS WRITTEN BY PHP, NOT UTC_TIMESTAMP(). The same value is
-- written into the account AND into the browser's own session, and a session
-- is compared with it later. Taking both from one clock (PHP's, in UTC) means
-- a web server and a database server whose clocks differ by a second cannot
-- sign out the browser that made the change.
--
-- -----------------------------------------------------------------------------
-- THE INDEXES (#598 item 11)
-- -----------------------------------------------------------------------------
-- `RateLimiter` counts failed sign-ins per address and per name by reading
-- this log, on every sign-in try, and the health page counts reset requests
-- that could not be sent. None of those reads had an index: measured on
-- MySQL 8.0.36 with 50,000 rows, the per-address count scanned all 50,000
-- (`type: ALL`). The first index serves the per-address count and the time
-- left; the second serves the per-name count and the health page's count.
--
-- ON A LARGE LOG, ADDING AN INDEX TAKES A WHILE. InnoDB builds it without
-- stopping writes (an in-place change), but the upgrade page waits until it
-- is done.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` with ERROR 1064 (see .claude/rules/database.md).
-- The column goes AFTER `createdAt` so an upgraded table has the same column
-- order as a fresh install from `full_schema.sql`.
--
-- SAFE TO RUN TWICE: each change is made only when it is absent, and the
-- record is an upsert.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/598
-- =============================================================================

-- #############################################################################
-- 🔐 A. tblUsers.signInsValidFrom
-- #############################################################################

SET @hasCol := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblUsers'
      AND `COLUMN_NAME`  = 'signInsValidFrom'
);
-- ⚠️ NO APOSTROPHES IN THE COLUMN COMMENT BELOW, for the reason migration 250
--    gives: it sits inside a quoted string inside another quoted string that
--    PREPARE reads. It must also be word for word the comment on the column
--    in `full_schema.sql`, or an upgraded database and a fresh install differ.
SET @sql := IF(@hasCol = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `signInsValidFrom` DATETIME(6) DEFAULT NULL COMMENT ''A moment in UTC. Every browser session of this person that began its sign-in before it is ended on its next request (#598, D-104). Set by Auth::endOtherSessionsOf() when the password is reset or changed. NULL ends nothing.'' AFTER `createdAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📇 B. The two indexes on tblActivityLogs
-- #############################################################################

SET @hasIdx := (
    SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblActivityLogs'
      AND `INDEX_NAME`   = 'idx_logs_type_ip_time'
);
SET @sql := IF(@hasIdx = 0,
    'ALTER TABLE `tblActivityLogs` ADD KEY `idx_logs_type_ip_time` (`activityType`, `visitorIP`, `timestamp`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasIdx := (
    SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblActivityLogs'
      AND `INDEX_NAME`   = 'idx_logs_type_time'
);
SET @sql := IF(@hasIdx = 0,
    'ALTER TABLE `tblActivityLogs` ADD KEY `idx_logs_type_time` (`activityType`, `timestamp`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🕐 C. tblApiRateLimits.hitAt says it is UTC (#598 item 7)
-- #############################################################################
-- `RateLimiter` now writes this column as UTC_TIMESTAMP() and compares it only
-- with UTC_TIMESTAMP(), so a server whose clock changes twice a year can no
-- longer stretch a 15-minute block to 76 minutes or shrink it to 3. Only the
-- COMMENT changes here (#777: a time column says which kind of time it holds);
-- the type and default are as before. MODIFY is safe to run twice.
-- Rows written before the upgrade hold local time; RateLimiter ignores any
-- that look like the future, so the worst an upgrade does is start each count
-- again from nothing.

ALTER TABLE `tblApiRateLimits` MODIFY COLUMN `hitAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'A moment in UTC. Written by RateLimiter::recordHit() as UTC_TIMESTAMP() and compared only with UTC_TIMESTAMP() (#598, migration 270), so a server clock change cannot stretch or shorten a window. The default is never used by the portal.';

-- #############################################################################
-- 📋 D. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('270_sign_ins_valid_from.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
