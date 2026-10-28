-- =============================================================================
-- Migration 223: a claimant can correct a claim nobody has decided on (#651)
--
-- Path: web/_sql/223_expenses_edit_routes.sql
--
-- WHY THIS EXISTS
--   Until now nothing in the portal could change an expense claim. A person
--   who mistyped an amount, picked the wrong department, or found a receipt
--   had not attached could only withdraw the claim and start again — and a
--   withdrawn claim is final (owner's decision, 12 October 2026), so every
--   line was re-typed.
--
--   The new pages are web/_apps/expenses/edit/index.php (the form) and
--   web/_apps/expenses/edit/save.php (the handler). The rule about WHEN a
--   claim may be changed lives in one place,
--   Portal\Core\ExpenseRules::whyNotEditable(): the claimant only, while the
--   claim is still Pending, and only while NOBODY has recorded a decision on
--   it. That last part is stricter than the status alone, because in a
--   department needing several approvals a claim stays Pending after the
--   first one — and editing it then would leave an approver's recorded
--   "Approved" sitting against numbers they never saw.
--
-- WHAT IT DOES
--   Adds two addresses to tblRoutes. Nothing else: no table changes, no
--   settings, no data touched. Both are protected, so a visitor who is not
--   signed in is sent to the sign-in page rather than reaching the handler.
--
-- SAFE TO RUN TWICE
--   Both inserts use ON DUPLICATE KEY UPDATE, and the self-record at the end
--   does the same. Running this a second time changes nothing.
--
-- MySQL 8.0 ∩ MariaDB
--   Plain INSERT ... ON DUPLICATE KEY UPDATE only. No MariaDB-only
--   `IF NOT EXISTS` on a column or an index, and no table is created.
--
--   These two rows are also in web/_sql/full_schema.sql, in the Expenses
--   block beside the other expenses addresses, and the two must agree.
-- =============================================================================

-- #############################################################################
-- 🔗  A. The two addresses
-- #############################################################################
-- isProtected is written again on a second run, matching the shape migration
-- 216 uses: an address that somehow ended up unprotected would let a request
-- reach the handler without a sign-in, and the handler's own refusal should
-- never be the only thing standing there.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('expenses/edit', 'expenses/edit/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('expenses/edit/save', 'expenses/edit/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);


-- #############################################################################
-- 📋  B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('223_expenses_edit_routes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
