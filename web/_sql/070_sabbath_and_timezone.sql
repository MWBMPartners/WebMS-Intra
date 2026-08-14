-- =============================================================================
-- Migration 070: Sabbath quiet hours + dynamic timezone (#231 #238)
-- =============================================================================

-- 🕯️ Sabbath per-user override
-- ➕ tblUsers.sabbathHonour — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'sabbathHonour'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `sabbathHonour` ENUM(''inherit'',''on'',''off'') NOT NULL DEFAULT ''inherit'' COMMENT ''Per-user Sabbath quiet-hours preference (#231)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🌍 Per-event timezone (Calendar events; column may or may not already exist
--    depending on whether the table has been customised on this install).
-- ➕ tblEvents.eventTimezone — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'eventTimezone'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `eventTimezone` VARCHAR(64) NOT NULL DEFAULT ''Europe/London'' COMMENT ''IANA timezone for event display (#238)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🌍 Per-calendar timezone (when calendars exist as a separate table — if not,
--    the event-level field covers the use case fine)
-- Note: tblCalendars may not exist on every install; no guarded ALTER is
-- emitted here for it — only the event-level `eventTimezone` column above
-- is added by this migration.

-- Sabbath settings
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.sabbath.enabled',              '0',              '0',              0),
    (NULL, 'portal.sabbath.method',               'fixed',          'fixed',          0),
    (NULL, 'portal.sabbath.timezone',             'Europe/London',  'Europe/London',  0),
    (NULL, 'portal.sabbath.location_lat',         '52.205',         '52.205',         0),
    (NULL, 'portal.sabbath.location_lng',         '0.119',          '0.119',          0),
    (NULL, 'portal.sabbath.start_offset_minutes', '0',              '0',              0),
    (NULL, 'portal.sabbath.end_offset_minutes',   '0',              '0',              0),
    (NULL, 'portal.sabbath.bypass_critical',      '1',              '1',              0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
