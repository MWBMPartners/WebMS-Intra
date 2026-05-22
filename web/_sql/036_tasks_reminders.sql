-- =============================================================================
-- Migration 036: Recurring Task / Reminder System
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/96
-- =============================================================================

-- 📋 Tasks table
CREATE TABLE IF NOT EXISTS `tblTasks` (
    `taskID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `assignedToID` INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdByID`  INT          DEFAULT NULL,
    `priority`     ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `status`       ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `dueDate`      DATE         DEFAULT NULL,
    `completedAt`  DATETIME     DEFAULT NULL,

    -- 🔄 Recurrence fields
    `isRecurring`     TINYINT(1)   NOT NULL DEFAULT 0,
    `recurrenceType`  ENUM('daily','weekly','monthly','yearly') DEFAULT NULL,
    `recurrenceInterval` INT      DEFAULT 1 COMMENT 'Every N days/weeks/months/years',
    `recurrenceEndDate`  DATE     DEFAULT NULL COMMENT 'Stop recurring after this date',
    `parentTaskID`    INT         DEFAULT NULL COMMENT 'FK → tblTasks (parent recurring task)',

    -- 🔔 Reminder fields
    `reminderDate`    DATETIME   DEFAULT NULL COMMENT 'When to send reminder',
    `reminderSent`    TINYINT(1) NOT NULL DEFAULT 0,

    `isDeleted`    TINYINT(1)   NOT NULL DEFAULT 0,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`taskID`),
    KEY `idx_task_site` (`siteID`),
    KEY `idx_task_assignee` (`assignedToID`),
    KEY `idx_task_status` (`siteID`, `status`, `isDeleted`),
    KEY `idx_task_due` (`dueDate`, `status`),
    KEY `idx_task_reminder` (`reminderDate`, `reminderSent`),
    KEY `idx_task_parent` (`parentTaskID`),
    CONSTRAINT `fk_task_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_task_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_task_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_task_parent` FOREIGN KEY (`parentTaskID`) REFERENCES `tblTasks` (`taskID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Recurring tasks and reminders';

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('tasks', 'tasks/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('tasks/save', 'tasks/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('tasks/complete', 'tasks/complete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 App settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.displayName', 'Tasks', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.displayIcon', 'fa-solid fa-list-check', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.brandColor', '#20c997', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
