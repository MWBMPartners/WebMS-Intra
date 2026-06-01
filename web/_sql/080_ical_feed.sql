-- =============================================================================
-- Migration 080: iCal feed support (#271)
-- =============================================================================

ALTER TABLE `tblUsers`
    ADD COLUMN IF NOT EXISTS `calendarToken` VARCHAR(64) DEFAULT NULL
        COMMENT 'SHA-256 hash of the user-personal iCal token (#271)';

ALTER TABLE `tblUsers`
    ADD INDEX IF NOT EXISTS `idx_user_calendar_token` (`calendarToken`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar.ics',           'calendar/feed.php',         0),
    ('account/calendar-feed',  'calendar/account-feed.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
