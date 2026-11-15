-- =============================================================================
-- Migration 292: a claim's generated PDF can be opened (#954)
--
-- Path: web/_sql/292_expense_claim_pdf_route.sql
--
-- WHY THIS EXISTS
--   The claim page listed the claim's own generated PDF by name with no link,
--   and nothing served it (`_uploads/pdfs`, outside the web root). #867 closed
--   the same gap for receipts (migration 278); this closes it for the PDF.
--
--   The new page is web/_apps/expenses/view/claim-pdf.php. Who may open it is
--   exactly who may open the claim, decided in ONE place,
--   Portal\Core\ExpenseRules (reachToSee() and mayViewClaim()); a PDF somebody
--   may not open gets the same answer as one that does not exist.
--
-- WHAT IT DOES
--   Adds one protected address to tblRoutes. Nothing else.
--
-- SAFE TO RUN TWICE
--   ON DUPLICATE KEY UPDATE on both statements.
--
-- MySQL 8.0 ∩ MariaDB
--   Plain INSERT ... ON DUPLICATE KEY UPDATE only.
--
--   This row is also in web/_sql/full_schema.sql, in the Expenses block
--   beside the other expenses addresses, and the two must agree.
-- =============================================================================

-- #############################################################################
-- 🔗  A. The address
-- #############################################################################
-- isProtected is written again on a second run, as migration 278 does.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('expenses/claim-pdf', 'expenses/view/claim-pdf.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);


-- #############################################################################
-- 📋  B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('292_expense_claim_pdf_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
