-- =============================================================================
-- Migration 110: Easy-wins bundle from competitive gap analysis
-- =============================================================================
-- Three small extensions to already-shipped apps, surfaced by the competitive
-- feature analysis vs Planning Center + WorshipTools. Each is the MVP slice
-- of a larger "for consideration" issue.
--
-- 1️⃣  #311 — Prayer chain: partner assignment workflow.
--     Adds `assignedToUserID` + `assignedAt` to tblPrayerRequests so a
--     moderator can assign a request to a specific prayer partner. The
--     partner sees it via the new /account/my-prayer-list page.
--
-- 2️⃣  #300 — Service-flow live timer.
--     Adds `startedAt` + `closedAt` to tblServicePlan so the booth knows
--     when the actual service began + ended. New /service-plans/<id>/live
--     view drives a master clock + per-item progress. /service-plans/<id>/
--     confidence is the speaker's full-screen monitor.
--
-- 3️⃣  #301 — Sermon notes on Recordings.
--     New tblRecordingNote table holding optional Markdown notes attached
--     to a recording. PDF + fill-in-the-blanks formats are deferred to a
--     v2 follow-up — v1 covers Markdown which is the highest-value format.
--
-- 📐 Design discipline:
--   • All ALTER TABLE statements use `IF NOT EXISTS` so re-running on a DB
--     that already has them is a no-op.
--   • All settings + routes use ON DUPLICATE KEY UPDATE for idempotency.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/300
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/301
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/311
-- =============================================================================

-- 1️⃣ Prayer chain partner assignment ---------------------------------------

-- ➕ tblPrayerRequests.assignedToUserID — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND COLUMN_NAME  = 'assignedToUserID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD COLUMN `assignedToUserID` INT DEFAULT NULL COMMENT ''FK → tblUsers — the prayer partner assigned to this request (#311)'' AFTER `submitterID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblPrayerRequests.assignedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND COLUMN_NAME  = 'assignedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD COLUMN `assignedAt` DATETIME DEFAULT NULL COMMENT ''When the request was assigned to its current partner'' AFTER `assignedToUserID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_pr_assigned — guarded ADD CONSTRAINT (was bare; broke installer full_schema-then-replay on both engines)
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblPrayerRequests'
      AND CONSTRAINT_NAME   = 'fk_pr_assigned'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD CONSTRAINT `fk_pr_assigned` FOREIGN KEY (`assignedToUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_pr_assigned — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND INDEX_NAME   = 'idx_pr_assigned'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD INDEX `idx_pr_assigned` (`assignedToUserID`, `status`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/my-prayer-list', 'account/my-prayer-list.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 2️⃣ Service-flow live timer -----------------------------------------------

-- ➕ tblServicePlan.startedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblServicePlan'
      AND COLUMN_NAME  = 'startedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlan` ADD COLUMN `startedAt` DATETIME DEFAULT NULL COMMENT ''When the live runtime was started by an operator (#300)'' AFTER `preparedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblServicePlan.closedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblServicePlan'
      AND COLUMN_NAME  = 'closedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlan` ADD COLUMN `closedAt` DATETIME DEFAULT NULL COMMENT ''When the live runtime was closed (the service ended)'' AFTER `startedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('service-plans/live',        'service-plans/live.php',        1),
    ('service-plans/confidence',  'service-plans/confidence.php',  1),
    ('service-plans/live-toggle', 'service-plans/live-toggle.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 3️⃣ Sermon notes on Recordings --------------------------------------------

CREATE TABLE IF NOT EXISTS `tblRecordingNote` (
    `noteID`        INT          NOT NULL AUTO_INCREMENT,
    `recordingID`   INT          NOT NULL,
    `format`        ENUM('markdown','pdf','fill_in_blanks') NOT NULL DEFAULT 'markdown',
    `body`          MEDIUMTEXT   DEFAULT NULL
                    COMMENT 'Markdown source (when format=markdown)',
    `documentPath`  VARCHAR(255) DEFAULT NULL
                    COMMENT 'Path under _uploads/recording-notes/ (when format=pdf, v2)',
    `publishedAt`   DATETIME     DEFAULT NULL
                    COMMENT 'NULL = unpublished draft (only the author + admins see it)',
    `createdByID`   INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`noteID`),
    KEY `idx_rn_recording` (`recordingID`),
    CONSTRAINT `fk_rn_recording` FOREIGN KEY (`recordingID`) REFERENCES `tblRecording`(`recordingID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rn_user`      FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('recordings/notes-edit', 'recordings/notes-edit.php', 1),
    ('recordings/notes-save', 'recordings/notes-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
