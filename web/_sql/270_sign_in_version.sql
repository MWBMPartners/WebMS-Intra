-- =============================================================================
-- Migration 270: a password reset, change or administrator's new password
--                signs the person out everywhere else (#598 item 1, D-104,
--                D-108), and the sign-in failure count gets the indexes it
--                reads (#598 item 11)
-- =============================================================================
-- What this adds:
--
--   tblUsers.signInVersion      a whole number, 0 to begin with. Every
--                               password reset, change, or new password set by
--                               an administrator adds a RANDOM amount to it
--                               (so a number never comes back after a
--                               restore; BIGINT for the room). Each browser
--                               session carries the number it signed in under,
--                               and one carrying ANY OTHER number is ended on
--                               its next request. Every existing account
--                               starts at 0 and every existing session counts
--                               as 0, so nobody notices anything until their
--                               password next changes.
--
--   tblActivityLogs indexes     `idx_logs_type_ip_time` (activityType,
--                               visitorIP, timestamp) and `idx_logs_type_time`
--                               (activityType, timestamp).
--
-- -----------------------------------------------------------------------------
-- WHY A NUMBER ON THE ACCOUNT, AND NOT "DELETE THE OTHER SESSIONS"
-- -----------------------------------------------------------------------------
-- A browser session is a PHP session FILE on the web server, and the portal
-- keeps no list of which files belong to which person, so there is nothing to
-- find and delete. Instead each session remembers the number it signed in
-- under, and `Auth::endSessionOfInactiveAccount()`, which already reads this
-- account's row once for every signed-in request, ends a session carrying a
-- different one. No extra query per request. The browser that MADE the change is
-- given the new number once the change commits, so it is the one that
-- stays signed in. Any other number ends a session, a HIGHER one included
-- (a whole-database restore puts the account's number back; session files
-- are not restored).
--
-- WHY A NUMBER AND NOT A TIME. The first version of this migration added a
-- "sign-ins valid from" moment instead. The independent check broke it over
-- real HTTP: a sign-in with the OLD password reads the stored password, spends
-- about 175 ms checking it, and only then marks its session — so if the change
-- committed inside those 175 ms the mark came out AFTER the moment and the
-- session was never ended. The sign-in reads this number in the SAME statement
-- as the password, and a change writes both in one transaction, so a sign-in
-- that read the old password always carries the old number. (No installation
-- had the earlier column: this migration had not been released.)
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
-- 🔐 A. tblUsers.signInVersion
-- #############################################################################

SET @hasCol := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblUsers'
      AND `COLUMN_NAME`  = 'signInVersion'
);
-- ⚠️ NO APOSTROPHES IN THE COLUMN COMMENT BELOW, for the reason migration 250
--    gives: it sits inside a quoted string inside another quoted string that
--    PREPARE reads. It must also be word for word the comment on the column
--    in `full_schema.sql`, or an upgraded database and a fresh install differ.
SET @sql := IF(@hasCol = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `signInVersion` BIGINT NOT NULL DEFAULT 0 COMMENT ''Goes up by a RANDOM amount every time the password is reset, changed or set by an administrator (#598, D-104, D-108), so a number never comes back after a restore. A browser session carrying any other number is ended on its next request. Written only by Auth::endOtherSessionsOf().'' AFTER `createdAt`',
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
-- Rows written before the upgrade hold the server's LOCAL time. On a server
-- BEHIND UTC they look old and fall out of every window at once. On a server
-- AHEAD of UTC they look like the future, and RateLimiter ignores a hit in the
-- future, so they count for nothing until UTC catches up with them — and then
-- they count again, for one window, before they age out. So an upgrade can
-- revive an old count for one window; it can never make a block longer than
-- that window.

ALTER TABLE `tblApiRateLimits` MODIFY COLUMN `hitAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'A moment in UTC. Written by RateLimiter::recordHit() as UTC_TIMESTAMP() and compared only with UTC_TIMESTAMP() (#598, migration 270), so a server clock change cannot stretch or shorten a window. The default is never used by the portal.';

-- #############################################################################
-- 📋 D. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('270_sign_in_version.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
