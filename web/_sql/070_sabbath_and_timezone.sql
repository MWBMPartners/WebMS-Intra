-- =============================================================================
-- Migration 070: Sabbath quiet hours + dynamic timezone (#231 #238)
-- =============================================================================

-- 🕯️ Sabbath per-user override
ALTER TABLE `tblUsers`
    ADD COLUMN IF NOT EXISTS `sabbathHonour` ENUM('inherit','on','off') NOT NULL DEFAULT 'inherit'
        COMMENT 'Per-user Sabbath quiet-hours preference (#231)';

-- 🌍 Per-event timezone (Calendar events; column may or may not already exist
--    depending on whether the table has been customised on this install).
ALTER TABLE `tblEvents`
    ADD COLUMN IF NOT EXISTS `eventTimezone` VARCHAR(64) NOT NULL DEFAULT 'Europe/London'
        COMMENT 'IANA timezone for event display (#238)';

-- 🌍 Per-calendar timezone (when calendars exist as a separate table — if not,
--    the event-level field covers the use case fine)
-- Note: tblCalendars may not exist on every install; the ALTER will fail safely
-- under MySQL's IF NOT EXISTS semantics if the column already present, or be
-- skipped on schemas where tblCalendars is absent (will surface as a non-fatal
-- migration warning).

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
