-- Migration 131: Decision Moments tracker (#315)
-- Quick-tap counter to log spiritual decisions during a service. Per-event,
-- per-moment-type increment buttons.

CREATE TABLE IF NOT EXISTS `tblDecisionMoments` (
    `momentID`     INT NOT NULL AUTO_INCREMENT,
    `eventID`      INT NOT NULL,
    `momentType`   ENUM('first-decision','rededication','baptism-request','membership-interest','prayer-request','other') NOT NULL,
    `count`        INT NOT NULL DEFAULT 0,
    `notes`        VARCHAR(500) DEFAULT NULL,
    `recordedByID` INT DEFAULT NULL,
    `updatedAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`momentID`),
    UNIQUE KEY `uq_moment_event_type` (`eventID`, `momentType`),
    CONSTRAINT `fk_moment_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_moment_user`  FOREIGN KEY (`recordedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/decisions',       'calendar/event-decisions.php',       1),
    ('calendar/event/decisions/bump',  'calendar/event-decisions-bump.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
