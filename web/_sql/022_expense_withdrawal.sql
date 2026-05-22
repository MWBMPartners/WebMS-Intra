-- =============================================================================
-- Migration: 022_expense_withdrawal.sql
-- Purpose:   Adds route for expense claim withdrawal handler (Issue #73).
--            Allows claimants to withdraw their own Pending claims.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- @see       https://github.com/MWBMPartners/WebMS-Intra/issues/73
-- =============================================================================

-- 📌 Expense claim withdrawal save handler (protected, not visible in nav)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/withdraw/save', 'expenses/withdraw/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('022_expense_withdrawal.sql');
