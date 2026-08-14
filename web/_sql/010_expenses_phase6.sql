-- =============================================================================
-- Migration: 010_expenses_phase6.sql
-- Purpose:   Phase 6 Expenses enhancements — multi-approver workflow, claim
--            detail page, email notification settings, PDF storage refinements.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 📊 Add approval threshold setting (claims above this need treasury sign-off too)
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.approvalThreshold', '500.00', 0, '500.00')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.requireTreasuryApproval', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.followUpDays', '7', 0, '7')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.emailNotifications', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- -----------------------------------------------------------------------------
-- 📝 Add stage column to tblExpenseClaimFiles for PDF versioning
-- Tracks which workflow stage each PDF was generated at.
-- Idempotent: uses information_schema to check before ALTER (portable across
-- MySQL 8.0 + MariaDB 10.x — see DEV_NOTES → Portable DDL).
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaimFiles'
      AND COLUMN_NAME  = 'stage'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblExpenseClaimFiles`
        ADD COLUMN `stage` VARCHAR(50) DEFAULT NULL
        COMMENT ''Workflow stage: pending, approved, not_approved, complete''
        AFTER `fileType`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 📝 Add approverNote to tblExpenseClaimApprovals for richer audit trail
-- (comments already exists; add explicit approver role context)
-- Idempotent: uses information_schema to check before ALTER.
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaimApprovals'
      AND COLUMN_NAME  = 'approverRole'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblExpenseClaimApprovals`
        ADD COLUMN `approverRole` VARCHAR(50) DEFAULT ''approver''
        COMMENT ''Role of approver: dept_lead, dept_approver, treasury, admin''
        AFTER `comments`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 📌 Add expense routes for claim detail/view and email template preview
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('expenses/view', 'expenses/view/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
