-- =============================================================================
-- Migration 249: the "Check this portal" page gets an address (#477)
-- =============================================================================
-- One route row. No new table, no new column, nothing dropped.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS IS FOR, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- Almost every fault the September 2026 audit found was invisible from inside
-- the product. The notification preferences page could not be opened for
-- months; six help guides existed that nobody could reach; six menu links led
-- to "page not found"; every fresh install carried 479 duplicate settings rows
-- and reported itself as version 0.1.0.
--
-- **Not one of those produced an error, a warning or a log entry.** They were
-- found by a person reading the code against what it claims to do. The new page
-- runs the equivalent checks against THIS installation's own database and says
-- in plain English what it found.
--
-- -----------------------------------------------------------------------------
-- WHY A ROUTE ROW IS WHAT MAKES IT EXIST
-- -----------------------------------------------------------------------------
-- Every app page lives under `web/_apps/`, outside the web root (#159), and is
-- reached only through the router — which resolves an address by looking it up
-- in `tblRoutes`. Without this row the file is on disk and unreachable, which
-- is precisely one of the states the page itself reports on.
--
-- `isProtected` is 1: the page is for a signed-in administrator or somebody
-- holding the `portal.check` capability, and the page checks that itself as
-- well. Two gates on purpose — the router's, and the page's own — because a
-- page reached some other way must still refuse.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION DOES NOT DO
-- -----------------------------------------------------------------------------
-- It does not grant anybody the capability, it does not add a menu link, and it
-- changes nothing the checks look at. The page is READ-ONLY by design: it
-- reports and never fixes, because fixing from a page like this invites a
-- different class of accident.
--
-- Safe to run twice: the one statement is an upsert, and so is its own record.
-- =============================================================================

-- #############################################################################
-- 🗺️ A. The address
-- #############################################################################
-- `targetFile` is restated as itself on a duplicate rather than forced, so an
-- installation that has deliberately pointed this address elsewhere keeps its
-- choice. `isProtected` IS set, because a page that stopped being protected
-- would be the one change here worth overriding.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/check', 'admin/check/index.php', 1)
ON DUPLICATE KEY UPDATE `isProtected` = VALUES(`isProtected`);

-- #############################################################################
-- 📋 B. Self-record (safe to run a second time, for the reason in the header)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('249_portal_check_page.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
