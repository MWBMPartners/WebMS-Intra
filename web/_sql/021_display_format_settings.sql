-- Migration 021: Add configurable display date/time format settings
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/69

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES
    ('display.dateFormat',     'j M Y',       0, NULL),
    ('display.timeFormat',     'H:i',         0, NULL),
    ('display.dateTimeFormat', 'j M Y H:i',   0, NULL)
ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`);

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('021_display_format_settings.sql');
