-- =============================================================================
-- Migration 211: an expense claim can be withdrawn (#587)
--
-- Path: web/_sql/211_expense_claim_withdrawn.sql
--
-- WHY THIS EXISTS
--   The Withdraw button on a claim's page (web/_apps/expenses/withdraw/save.php)
--   sets the claim's status to 'Withdrawn'. The column `tblExpenseClaims.status`
--   is a fixed list of choices, and 'Withdrawn' was never on it. So the button
--   has never worked:
--     - on a database server with strict checking (MySQL 8's default) the
--       server refused the value (error 1265) and the person saw "Error
--       withdrawing claim";
--     - on a server WITHOUT strict checking it was worse: the server stored
--       an EMPTY status and reported success, the page said "withdrawn", and
--       the claim then dropped off the approver's list and the dashboard, and
--       showed elsewhere with a blank status.
--
-- WHAT IT DOES
--   A. Adds 'Withdrawn' to the END of the column's list of choices.
--      At the end on purpose. The server stores each choice as its position
--      in the list, so a new one put anywhere else would change what every
--      later stored value means. Added at the end, every existing claim keeps
--      its status and no row is rewritten.
--   B. Turns an EMPTY status into 'Withdrawn' (owner's decision, Salem874,
--      12 October 2026). Nothing else in the portal can store an empty status:
--      a new claim is always 'Pending', an approver's decision is always
--      'Approved' or 'Rejected', a payment is always 'Reimbursed'. So an
--      empty status can only be a press of the Withdraw button on a server
--      without strict checking. On a server with strict checking there are
--      no such rows, and this statement changes nothing.
--
--      Such a row KEEPS its "last changed" time (`updatedAt`). That time is
--      already the moment the person pressed Withdraw, because the page
--      writes it in the same statement as the status. Letting it move to the
--      day of this upgrade would say the claim was withdrawn on a day it was
--      not. `updatedAt = updatedAt` is what stops the column's own "set to
--      now on every change" rule from moving it.
--
-- SAFE TO RUN TWICE
--   A asks the database whether 'Withdrawn' is already on the list, and does
--   nothing when it is. B finds no row the second time. The installer relies
--   on this: it replays every numbered migration after the install script.
--
-- WHAT IT DOES NOT DO
--   It does not touch a claim whose status is 'Pending', 'Approved',
--   'Rejected' or 'Reimbursed', or one with NO status at all (NULL, which the
--   column allows and nothing in the portal writes).
--   It deletes nothing. Receipts, line items, approvals and payments are not
--   read or changed.
--   It cannot tell WHO pressed Withdraw on a claim it repairs, and it sends
--   no e-mail about one.
--   Part A restates the column exactly as web/_sql/full_schema.sql declares
--   it (same character rules, same default of 'Pending', still allowed to
--   hold no value at all). On a database whose column had been changed by
--   hand to something else, this puts it back to that.
--   Nothing here has been run on MariaDB; no test in this repository covers it.
--
-- The structure change uses the information_schema + PREPARE form, because
-- MySQL 8 refuses MariaDB's `IF [NOT] EXISTS` on ALTER (error 1064). See
-- DEV_NOTES.md, "Portable DDL convention".
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/587
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- 💷  A. tblExpenseClaims.status — 'Withdrawn' joins the END of the list
-- #############################################################################
-- The question is asked of the column's own definition, so the answer is
-- right whichever way the database came into being. It counts 1 only when the
-- column is there AND 'Withdrawn' is not yet one of its choices.

SET @needs_withdrawn := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaims'
      AND COLUMN_NAME  = 'status'
      AND COLUMN_TYPE NOT LIKE '%''Withdrawn''%'
);

SET @sql := IF(@needs_withdrawn = 1,
    'ALTER TABLE `tblExpenseClaims` MODIFY COLUMN `status` ENUM(''Pending'',''Approved'',''Rejected'',''Reimbursed'',''Withdrawn'') COLLATE utf8mb4_general_ci DEFAULT ''Pending''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🧹  B. A claim left with an EMPTY status is a withdrawn claim
-- #############################################################################
-- This must come AFTER part A: before it, 'Withdrawn' is not a value the
-- column accepts, and this statement would either be refused or store an
-- empty status all over again. If part A fails, the file stops there and this
-- never runs.
--
-- `status` = '' matches only the empty value a server without strict checking
-- stores in place of a choice it does not know. It does not match NULL.

UPDATE `tblExpenseClaims`
   SET `status` = 'Withdrawn', `updatedAt` = `updatedAt`
 WHERE `status` = '';


-- #############################################################################
-- 📋  C. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('211_expense_claim_withdrawn.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
