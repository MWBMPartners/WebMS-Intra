-- =============================================================================
-- Migration 118: Event volunteer job board (#344)
-- =============================================================================
-- Sibling to #343's crew builder. Coordinators define jobs (A.V. Tech 0/2,
-- Bible Leader 0/1, etc.) and assign volunteers. v1 forms-only; SortableJS
-- drag-and-drop is v1.1.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/344
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventJobs` (
    `jobID`           INT          NOT NULL AUTO_INCREMENT,
    `eventID`         INT          NOT NULL,
    `name`            VARCHAR(120) NOT NULL,
    `description`     TEXT         DEFAULT NULL,
    `capacityNeeded`  INT          NOT NULL DEFAULT 1,
    `sortOrder`       INT          NOT NULL DEFAULT 0,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`jobID`),
    KEY `idx_job_event_sort` (`eventID`, `sortOrder`),
    CONSTRAINT `fk_job_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblEventJobAssignments` (
    `assignmentID`  INT          NOT NULL AUTO_INCREMENT,
    `jobID`         INT          NOT NULL,
    `userID`        INT          DEFAULT NULL COMMENT 'NULL for external volunteers',
    `externalName`  VARCHAR(120) DEFAULT NULL,
    `assignedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`assignmentID`),
    KEY `idx_jobassign_job`  (`jobID`),
    KEY `idx_jobassign_user` (`userID`),
    CONSTRAINT `fk_jobassign_job`  FOREIGN KEY (`jobID`)  REFERENCES `tblEventJobs`(`jobID`)   ON DELETE CASCADE,
    CONSTRAINT `fk_jobassign_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/jobs',      'calendar/event-jobs.php',      1),
    ('calendar/event/jobs/save', 'calendar/event-jobs-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
