-- =============================================================================
-- Migration 117: Event crew / group builder (#343)
-- =============================================================================
-- For VBS-style multi-day events, coordinators build "crews" (Blue / Green /
-- Orange / Red) and assign participants + leaders to each. v1 ships
-- forms-only (add member / remove member). SortableJS drag-and-drop is a
-- v1.1 polish layer — the data model below supports it natively.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/343
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventCrews` (
    `crewID`            INT          NOT NULL AUTO_INCREMENT,
    `eventID`           INT          NOT NULL,
    `name`              VARCHAR(80)  NOT NULL,
    `color`             VARCHAR(20)  NOT NULL DEFAULT '#5e6ad2'
                        COMMENT 'CSS colour for crew chip rendering',
    `gradesAccepted`    VARCHAR(100) DEFAULT NULL
                        COMMENT 'Comma-separated grade list (e.g. "P,K,1,2,3") — purely advisory in v1',
    `sortOrder`         INT          NOT NULL DEFAULT 0,
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`crewID`),
    KEY `idx_crew_event_sort` (`eventID`, `sortOrder`),
    CONSTRAINT `fk_crew_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblEventCrewMembers` (
    `membershipID`  INT          NOT NULL AUTO_INCREMENT,
    `crewID`        INT          NOT NULL,
    `userID`        INT          DEFAULT NULL
                    COMMENT 'NULL for non-portal-user external participants',
    `externalName`  VARCHAR(120) DEFAULT NULL,
    `role`          ENUM('participant','leader') NOT NULL DEFAULT 'participant',
    `sortOrder`     INT          NOT NULL DEFAULT 0,
    `addedAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`membershipID`),
    KEY `idx_crewmember_crew` (`crewID`, `role`, `sortOrder`),
    KEY `idx_crewmember_user` (`userID`),
    CONSTRAINT `fk_crewmember_crew` FOREIGN KEY (`crewID`) REFERENCES `tblEventCrews`(`crewID`) ON DELETE CASCADE,
    CONSTRAINT `fk_crewmember_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/crews',      'calendar/event-crews.php',      1),
    ('calendar/event/crews/save', 'calendar/event-crews-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
