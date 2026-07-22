-- =============================================================================
-- Migration 026: Notification preferences
-- =============================================================================
-- Adds notification preference columns to tblUsers and settings for digest.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/86
-- =============================================================================

-- 📋 tblUsers.notifyPrefs — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'notifyPrefs'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `notifyPrefs` JSON DEFAULT NULL COMMENT ''User notification preferences (JSON: {emailDigest, expenseUpdates, eventReminders})''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Digest settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('notifications.digestEnabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('notifications.digestDay', 'monday', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
