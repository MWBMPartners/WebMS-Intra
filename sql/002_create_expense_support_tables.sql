-- =============================================================================
-- Migration: 002_create_expense_support_tables.sql
-- Purpose:   Creates tblExpenseClaimApprovals and tblExpenseClaimPayments.
--            Previously these were created inline via CREATE TABLE IF NOT EXISTS
--            inside save.php handlers - this migration ensures they exist properly
--            as part of the schema rather than being created at runtime.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Approval decisions by authorisers
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaimApprovals` (
    `approvalID` INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique approval record identifier',
    `claimID`    INT          NOT NULL                COMMENT 'FK to tblExpenseClaims.claimID',
    `userID`     INT          NOT NULL                COMMENT 'FK to tblUsers.userID - the approver',
    `decision`   ENUM('Approved','Rejected') NOT NULL COMMENT 'Approver decision for this claim',
    `comments`   TEXT         DEFAULT NULL             COMMENT 'Optional comments from the approver',
    `decidedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the decision was made',
    PRIMARY KEY (`approvalID`),
    KEY `idx_approvals_claim` (`claimID`),
    KEY `idx_approvals_user`  (`userID`),
    CONSTRAINT `fk_approvals_claim` FOREIGN KEY (`claimID`) REFERENCES `tblExpenseClaims` (`claimID`) ON DELETE CASCADE,
    CONSTRAINT `fk_approvals_user`  FOREIGN KEY (`userID`)  REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Records each approver decision for expense claims (supports multi-approver workflow).';

-- ---------------------------------------------------------------------------
-- Payment/reimbursement references from Treasury
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaimPayments` (
    `payID`        INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique payment record identifier',
    `claimID`      INT          NOT NULL                COMMENT 'FK to tblExpenseClaims.claimID',
    `payReference` VARCHAR(255) NOT NULL                COMMENT 'Internal payment reference (bank ref, cheque number etc)',
    `payMethod`    VARCHAR(100) DEFAULT NULL             COMMENT 'Payment method (Bank Transfer, Cheque, PayPal etc)',
    `payAmount`    DECIMAL(10,2) DEFAULT NULL            COMMENT 'Amount paid (may differ from claim total in partial payments)',
    `paidByID`     INT          DEFAULT NULL             COMMENT 'FK to tblUsers.userID - treasury user who processed payment',
    `addedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the payment record was created',
    PRIMARY KEY (`payID`),
    KEY `idx_payments_claim` (`claimID`),
    CONSTRAINT `fk_payments_claim` FOREIGN KEY (`claimID`) REFERENCES `tblExpenseClaims` (`claimID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Records payment/reimbursement references against approved expense claims.';
