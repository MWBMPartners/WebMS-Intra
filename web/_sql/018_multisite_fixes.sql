-- Migration 018: Multi-site schema fixes
-- Adds missing siteID to tblRecurrenceRules
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/59

-- -----------------------------------------------------------------------------
-- 🔁 Add siteID to tblRecurrenceRules (missed in migration 015) — guarded
--    (portable: MySQL 8.0 + MariaDB 10.x). Column + key + FK ship atomically
--    in one ALTER, so the column-existence check is a sound proxy for all three.
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblRecurrenceRules'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblRecurrenceRules`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 COMMENT ''FK -> tblSites'' AFTER `ruleID`,
        ADD KEY `idx_recur_site` (`siteID`),
        ADD CONSTRAINT `fk_recur_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('018_multisite_fixes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
