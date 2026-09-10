-- Migration 021: Add configurable display date/time format settings
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/69

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES
    ('display.dateFormat',     'j M Y',       0, NULL),
    ('display.timeFormat',     'H:i',         0, NULL),
    ('display.dateTimeFormat', 'j M Y H:i',   0, NULL)
-- Do nothing if the setting already exists. This used to overwrite the value,
-- which was harmless only because portal-wide settings could never actually
-- collide (see migration 187). Now that they can, a replay would reset a date
-- format an administrator had chosen — so it must leave an existing row alone,
-- exactly like every other seed in this project.
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('021_display_format_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
