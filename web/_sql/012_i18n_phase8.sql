-- =============================================================================
-- Migration 012: Internationalisation (i18n) — Phase 8
-- =============================================================================
-- Adds locale column to tblUsers, i18n settings, and updates routes.
--
-- @package   Portal\SQL
-- @version   0.7.0
-- @date      2026-03-07
-- =============================================================================

-- 🌐 Add locale column to tblUsers for per-user language preference
-- Idempotent: uses information_schema to check before ALTER (portable across
-- MySQL 8.0 + MariaDB 10.x — see DEV_NOTES → Portable DDL).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'locale'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `locale` VARCHAR(10) DEFAULT ''en'' AFTER `avatarPath`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🌐 i18n settings
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
    ('i18n.defaultLocale',  'en',   0),
    ('i18n.enabled',        'true', 0)
ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- 📋 Track this migration
INSERT INTO tblMigrations (filename)
VALUES ('012_i18n_phase8.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
