-- =============================================================================
-- Migration 028: Event RSVP / registration
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/88
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventRSVPs` (
    `rsvpID`    INT         NOT NULL AUTO_INCREMENT,
    `eventID`   INT         NOT NULL,
    `userID`    INT         NOT NULL,
    `siteID`    INT         NOT NULL DEFAULT 1,
    `response`  ENUM('going','maybe','not_going') NOT NULL DEFAULT 'going',
    `createdAt` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME    DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`rsvpID`),
    UNIQUE KEY `uq_event_user` (`eventID`, `userID`),
    KEY `idx_event_response` (`eventID`, `response`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event RSVP/registration responses';

-- 📋 tblEvents.capacity — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'capacity'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `capacity` INT DEFAULT NULL COMMENT ''Max attendees (NULL = unlimited)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Route for RSVP save handler
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/rsvp', 'calendar/rsvp.php', '1')
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
