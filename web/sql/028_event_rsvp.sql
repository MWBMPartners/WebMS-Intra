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

-- 📋 Add optional capacity column to tblEvents
ALTER TABLE `tblEvents`
    ADD COLUMN IF NOT EXISTS `capacity` INT DEFAULT NULL
    COMMENT 'Max attendees (NULL = unlimited)';

-- 📋 Route for RSVP save handler
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/rsvp', 'calendar/rsvp.php', '1')
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
