-- =============================================================================
-- Migration 080: iCal feed support (#271)
-- =============================================================================

-- ➕ tblUsers.calendarToken — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'calendarToken'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `calendarToken` VARCHAR(64) DEFAULT NULL COMMENT ''SHA-256 hash of the user-personal iCal token (#271)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_user_calendar_token — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND INDEX_NAME   = 'idx_user_calendar_token'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblUsers` ADD INDEX `idx_user_calendar_token` (`calendarToken`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar.ics',           'calendar/feed.php',         0),
    ('account/calendar-feed',  'calendar/account-feed.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
