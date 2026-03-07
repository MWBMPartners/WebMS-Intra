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
ALTER TABLE tblUsers
    ADD COLUMN locale VARCHAR(10) DEFAULT 'en' AFTER avatarPath;

-- 🌐 i18n settings
INSERT INTO tblSettings (settingKey, settingValue, isSensitive) VALUES
    ('i18n.defaultLocale',  'en',   0),
    ('i18n.enabled',        'true', 0)
ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue);

-- 📋 Track this migration
INSERT INTO tblMigrations (migrationFile, description)
VALUES ('012_i18n_phase8.sql', 'Phase 8: i18n framework — locale column, settings');
