-- =============================================================================
-- Migration 278: an expense receipt can be opened (#867)
--
-- Path: web/_sql/278_expense_receipt_route.sql
--
-- WHY THIS EXISTS
--   The claim page listed each receipt's label, kind and size with no link,
--   and nothing served the receipts folder (`_uploads/expenses`, outside the
--   web root on purpose). So an approver deciding a claim, and a treasurer
--   paying one, could not look at the evidence they were deciding or paying
--   against.
--
--   The new page is web/_apps/expenses/view/receipt.php. Who may open a
--   receipt is exactly who may open the claim, decided in ONE place,
--   Portal\Core\ExpenseRules (reachToSee() and mayViewClaim()); a receipt
--   somebody may not open gets the same answer as one that does not exist.
--
-- WHAT IT DOES
--   Adds one address to tblRoutes. Nothing else: no table changes, no
--   settings, no data touched. It is protected, so a visitor who is not
--   signed in is sent to the sign-in page rather than reaching the page.
--
-- SAFE TO RUN TWICE
--   The insert uses ON DUPLICATE KEY UPDATE, and so does the self-record at
--   the end. Running this a second time changes nothing.
--
-- MySQL 8.0 ∩ MariaDB
--   Plain INSERT ... ON DUPLICATE KEY UPDATE only. No MariaDB-only
--   `IF NOT EXISTS` on a column or an index, and no table is created.
--
--   This row is also in web/_sql/full_schema.sql, in the Expenses block
--   beside the other expenses addresses, and the two must agree.
-- =============================================================================

-- #############################################################################
-- 🔗  A. The address
-- #############################################################################
-- isProtected is written again on a second run, matching migration 223: an
-- address that somehow ended up unprotected would let a request reach the page
-- without a sign-in, and the page's own refusal should never be the only thing
-- standing there.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('expenses/receipt', 'expenses/view/receipt.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);


-- #############################################################################
-- 📋  B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('278_expense_receipt_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
