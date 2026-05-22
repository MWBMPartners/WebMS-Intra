-- =============================================================================
-- Migration 026: Notification preferences
-- =============================================================================
-- Adds notification preference columns to tblUsers and settings for digest.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/86
-- =============================================================================

-- 📋 Add notification preferences column to tblUsers (JSON)
ALTER TABLE `tblUsers`
    ADD COLUMN IF NOT EXISTS `notifyPrefs` JSON DEFAULT NULL
    COMMENT 'User notification preferences (JSON: {emailDigest, expenseUpdates, eventReminders})';

-- 📋 Digest settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('notifications.digestEnabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('notifications.digestDay', 'monday', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
