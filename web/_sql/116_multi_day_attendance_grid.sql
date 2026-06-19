-- =============================================================================
-- Migration 116: Multi-day attendance grid (#345)
-- =============================================================================
-- VBS-style per-event multi-day check-off: for a 3-5 day event, coordinators
-- see a grid where each row is a participant and each column is one day of
-- the event. Click the cell to mark attended. Walk-in enrol button adds an
-- attendee on the spot.
--
-- Distinct from the existing tblAttendance (service-oriented headcounts).
-- This is event-scoped + per-day + per-participant.
--
-- Participant list = tblEventRSVPs.status='confirmed' for the event. New
-- walk-in additions create the tblEventRSVPs row server-side.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/345
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventAttendance` (
    `attendanceID` INT      NOT NULL AUTO_INCREMENT,
    `eventID`      INT      NOT NULL,
    `userID`       INT      DEFAULT NULL COMMENT 'NULL for walk-in (anonymous) attendees',
    `walkinName`   VARCHAR(120) DEFAULT NULL COMMENT 'Display name when userID IS NULL',
    `dayDate`      DATE     NOT NULL COMMENT 'Which day of the event this check-in represents',
    `markedByID`   INT      DEFAULT NULL COMMENT 'Coordinator / admin who clicked the cell',
    `markedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`        VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`attendanceID`),
    UNIQUE KEY `uq_attendance_unique` (`eventID`, `userID`, `walkinName`, `dayDate`),
    KEY `idx_attendance_event_day` (`eventID`, `dayDate`),
    KEY `idx_attendance_user`      (`userID`),
    CONSTRAINT `fk_attend_event`  FOREIGN KEY (`eventID`)    REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_attend_user`   FOREIGN KEY (`userID`)     REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL,
    CONSTRAINT `fk_attend_marker` FOREIGN KEY (`markedByID`) REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Grid view (coordinator OR admin).
    ('calendar/event/attendance',       'calendar/event-attendance.php',       1),
    -- POST: toggle attendance cell.
    ('calendar/event/attendance/mark',  'calendar/event-attendance-mark.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
