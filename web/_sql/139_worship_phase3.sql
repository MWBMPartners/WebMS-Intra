-- =============================================================================
-- Migration 139: Worship Engine Phase 3 polish (#308)
-- =============================================================================
--   • tblServicePlanState gains currentSlideIndex — used when an item is a
--     SONG and the lyrics are auto-split into multiple verses. The operator
--     advances within the song first, then onto the next item.
--   • tblCcliUsage NEW — append-only log of every song-slide that goes
--     live. UK churches need this for CCLI reporting. Admin export view at
--     /admin/reports/ccli aggregates plays per quarter.
--   • Settings: worship.song_verse_separator — regex used to split lyrics
--     into verses. Default \\n\\s*\\n (one or more blank lines).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
-- =============================================================================

-- ➕ tblServicePlanState.currentSlideIndex — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblServicePlanState'
      AND COLUMN_NAME  = 'currentSlideIndex'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlanState` ADD COLUMN `currentSlideIndex` INT NOT NULL DEFAULT 0 COMMENT ''For multi-verse song items: 0-based verse index within the current item''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `tblCcliUsage` (
    `usageID`     INT NOT NULL AUTO_INCREMENT,
    `siteID`      INT NOT NULL,
    `songID`      INT NOT NULL,
    `planID`      INT DEFAULT NULL,
    `itemID`      INT DEFAULT NULL,
    `playedAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `operatorID`  INT DEFAULT NULL,
    PRIMARY KEY (`usageID`),
    KEY `idx_ccli_site_played` (`siteID`, `playedAt`),
    KEY `idx_ccli_song`        (`songID`, `playedAt`),
    CONSTRAINT `fk_ccli_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ccli_song` FOREIGN KEY (`songID`) REFERENCES `tblSongs`(`songID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ccli_plan` FOREIGN KEY (`planID`) REFERENCES `tblServicePlans`(`planID`) ON DELETE SET NULL,
    CONSTRAINT `fk_ccli_item` FOREIGN KEY (`itemID`) REFERENCES `tblServicePlanItems`(`itemID`) ON DELETE SET NULL,
    CONSTRAINT `fk_ccli_user` FOREIGN KEY (`operatorID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`) VALUES
    ('worship.song_verse_separator', '/\\n\\s*\\n/', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('worship/plan/reorder',  'worship/plan-reorder.php',  1),
    ('admin/reports/ccli',    'admin/reports/ccli.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
