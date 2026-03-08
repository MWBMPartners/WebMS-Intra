-- =============================================================================
-- Migration 034: Configurable Workflow Engine
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/94
-- =============================================================================

-- 📋 Workflow definitions
CREATE TABLE IF NOT EXISTS `tblWorkflows` (
    `workflowID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `workflowName` VARCHAR(100) NOT NULL,
    `workflowKey`  VARCHAR(50)  NOT NULL COMMENT 'Machine-readable key (e.g. expense_approval)',
    `description`  VARCHAR(255) DEFAULT NULL,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`workflowID`),
    UNIQUE KEY `uq_workflow_key_site` (`workflowKey`, `siteID`),
    KEY `idx_workflow_site` (`siteID`),
    CONSTRAINT `fk_workflow_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Configurable workflow definitions';

-- 📋 Workflow steps (ordered stages)
CREATE TABLE IF NOT EXISTS `tblWorkflowSteps` (
    `stepID`       INT          NOT NULL AUTO_INCREMENT,
    `workflowID`   INT          NOT NULL,
    `stepOrder`    INT          NOT NULL DEFAULT 1,
    `stepName`     VARCHAR(100) NOT NULL,
    `stepType`     ENUM('approval','review','notification','auto') NOT NULL DEFAULT 'approval',
    `assigneeType` ENUM('role','user','group') NOT NULL DEFAULT 'role',
    `assigneeValue` VARCHAR(100) DEFAULT NULL COMMENT 'Role name, userID, or groupID',
    `autoAction`   ENUM('approve','reject','escalate') DEFAULT NULL COMMENT 'For auto steps',
    `timeoutHours` INT          DEFAULT NULL COMMENT 'Auto-escalate after N hours',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`stepID`),
    KEY `idx_wfstep_workflow` (`workflowID`),
    CONSTRAINT `fk_wfstep_workflow` FOREIGN KEY (`workflowID`) REFERENCES `tblWorkflows` (`workflowID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Ordered steps within a workflow';

-- 📋 Workflow instances (running workflows tied to a record)
CREATE TABLE IF NOT EXISTS `tblWorkflowInstances` (
    `instanceID`   INT          NOT NULL AUTO_INCREMENT,
    `workflowID`   INT          NOT NULL,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `tableName`    VARCHAR(100) NOT NULL COMMENT 'Source table (e.g. tblExpenseClaims)',
    `recordID`     INT          NOT NULL COMMENT 'PK of the source record',
    `currentStep`  INT          NOT NULL DEFAULT 1,
    `status`       ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `startedByID`  INT          DEFAULT NULL,
    `startedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completedAt`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`instanceID`),
    KEY `idx_wfi_workflow` (`workflowID`),
    KEY `idx_wfi_record` (`tableName`, `recordID`),
    KEY `idx_wfi_status` (`status`),
    CONSTRAINT `fk_wfi_workflow` FOREIGN KEY (`workflowID`) REFERENCES `tblWorkflows` (`workflowID`),
    CONSTRAINT `fk_wfi_starter` FOREIGN KEY (`startedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Running workflow instances linked to source records';

-- 📋 Workflow action log (step completions)
CREATE TABLE IF NOT EXISTS `tblWorkflowActions` (
    `actionID`    INT          NOT NULL AUTO_INCREMENT,
    `instanceID`  INT          NOT NULL,
    `stepID`      INT          NOT NULL,
    `action`      ENUM('approved','rejected','escalated','skipped') NOT NULL,
    `comment`     TEXT         DEFAULT NULL,
    `actedByID`   INT          DEFAULT NULL,
    `actedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`actionID`),
    KEY `idx_wfa_instance` (`instanceID`),
    CONSTRAINT `fk_wfa_instance` FOREIGN KEY (`instanceID`) REFERENCES `tblWorkflowInstances` (`instanceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_wfa_step` FOREIGN KEY (`stepID`) REFERENCES `tblWorkflowSteps` (`stepID`),
    CONSTRAINT `fk_wfa_actor` FOREIGN KEY (`actedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Action log for workflow step completions';

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/workflows', 'admin/workflows/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/workflows/save', 'admin/workflows/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Seed default expense approval workflow
INSERT INTO `tblWorkflows` (`siteID`, `workflowName`, `workflowKey`, `description`)
VALUES (1, 'Expense Approval', 'expense_approval', 'Default expense claim approval workflow')
ON DUPLICATE KEY UPDATE `workflowName` = VALUES(`workflowName`);
