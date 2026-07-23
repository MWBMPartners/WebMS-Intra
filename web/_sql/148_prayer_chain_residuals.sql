-- =============================================================================
-- Migration 148: Prayer chain — assignment residuals (#311)
-- =============================================================================
-- Completes the #311 partner-assignment workflow left MVP by migration 110:
--
-- 1️⃣ Private partner notes — `partnerNote` + `partnerLastPrayedAt` on
--    tblPrayerRequests. ACL (enforced in PHP, not SQL): only the CURRENTLY
--    assigned partner or an admin may read/write `partnerNote`. A single
--    column (not a history table) is deliberate — this mirrors the existing
--    single-value `testimony` column on the same table, and moderate.php
--    clears both columns whenever a request is reassigned to a DIFFERENT
--    partner, so a note never leaks to a partner it wasn't written for.
--
-- 2️⃣ `prayer_team` role — seeds the role that api/moderate.php ALREADY
--    checks via App::hasRole('prayer_team') (see that file's comments) but
--    that no prior migration ever created. Also the "eligible partner"
--    signal for manual-assign dropdowns + auto-assign round-robin: any
--    active site member holding this role. Managed via the existing
--    generic role-assignment UI at /admin/users — no new admin screen
--    needed (mirrors the `stream_moderator` seed in migration 143).
--
-- 3️⃣ Settings — `prayer-requests.autoAssign` (opt-in round-robin
--    auto-assign on submission) + `prayer-requests.notifyOnAssign` (email
--    + SMS ping to the assigned partner; admin off-switch).
--
-- 4️⃣ Route for the new self-service "mark prayed for" / "save my note"
--    POST handler at /account/my-prayer-list/save.
--
-- 📐 Design discipline: every ALTER TABLE uses the information_schema +
--    PREPARE/EXECUTE guard idiom (MySQL 8.0 ∩ MariaDB — see 037/070/112/138),
--    NOT MariaDB-only `ADD COLUMN IF NOT EXISTS`. All settings/routes use
--    ON DUPLICATE KEY UPDATE; the role seed uses the WHERE NOT EXISTS
--    idiom already shipped in 143_cop_live_chat.sql.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/311
-- =============================================================================

-- 1️⃣ Private partner notes ---------------------------------------------------

-- ➕ tblPrayerRequests.partnerNote — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND COLUMN_NAME  = 'partnerNote'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD COLUMN `partnerNote` TEXT DEFAULT NULL COMMENT ''Private note from/to the assigned prayer partner (#311) — ONLY the assignee or an admin may read/write this (enforced in PHP)'' AFTER `assignedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblPrayerRequests.partnerLastPrayedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND COLUMN_NAME  = 'partnerLastPrayedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD COLUMN `partnerLastPrayedAt` DATETIME DEFAULT NULL COMMENT ''When the assigned partner last tapped "mark prayed for" on My Prayer List (#311)'' AFTER `partnerNote`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2️⃣ `prayer_team` role -------------------------------------------------------

-- 🛡️ Seed the prayer_team role if not already present. App::hasRole
-- (web/_core/App.php) + _apps/prayer-requests/api/moderate.php both
-- already reference this exact roleKey string.
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'prayer_team', 'Prayer Team'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'prayer_team');

-- 3️⃣ Settings -----------------------------------------------------------------

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'prayer-requests.autoAssign',    'false', 'false', 0),
    (NULL, 'prayer-requests.notifyOnAssign','true',  'true',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 4️⃣ Route for the self-service prayed-for / note-save handler ---------------

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/my-prayer-list/save', 'account/my-prayer-list-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('148_prayer_chain_residuals.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
