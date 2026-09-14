-- =============================================================================
-- Migration 196: three scheduled jobs get addresses of their own, and a stray
--                page-fragment address is removed
-- =============================================================================
-- Route rows only. No tables, no columns, no indexes, no settings.
--
-- -----------------------------------------------------------------------------
-- 1. THREE NEW JOB ADDRESSES — SEEDED AS NOT PROTECTED, ON PURPOSE (#497)
-- -----------------------------------------------------------------------------
-- The Router is meant to send a signed-out visitor to the sign-in page for any
-- address seeded with isProtected = 1. It never did: it compared the value
-- with the TEXT '1', but the route is read through a prepared statement, which
-- returns the NUMBER 1, so the comparison was always false. Pages with no
-- sign-in check of their own (the expenses treasury queue among them) were open
-- to anybody. The same change as this migration fixes the Router.
--
-- Three scheduled jobs had been relying on that fault without knowing it. Each
-- was a "?cron=1&token=…" mode of a staff page whose address is protected:
--   /admin/maintenance/retention     (the audit-log and registration clear-out)
--   /admin/maintenance/health        (the full health checks, as JSON)
--   /admin/maintenance/backup-check  (the backup freshness alert email)
-- With the Router fixed, a scheduler arriving with a token and no session would
-- be redirected to the sign-in page. curl does not count a redirect as a
-- failure, so each job would simply have stopped, with no error anywhere.
--
-- So each job now has its own address, following the convention every other
-- scheduled job already uses (cron/event-reminders, cron/user-reminders,
-- cron/workflow-timeouts, …): a cron/... address seeded with isProtected = 0,
-- where the page checks its own token. The token, the setting it is compared
-- with (maintenance.cronToken) and the responses are unchanged; only the
-- address is new. The old "?cron=1" modes have been removed from the staff
-- pages, which stay protected.
--
-- Unlike most route seeds, the duplicate-key clause also resets isProtected.
-- A job address that somehow ended up protected would fail silently, in exactly
-- the way described above, so a replay puts it back to 0.
--
-- -----------------------------------------------------------------------------
-- 2. THE ADDRESS calendar/views/photo IS REMOVED (#501, item 3)
-- -----------------------------------------------------------------------------
-- web/_apps/calendar/views/photo.php was written as a piece of the calendar
-- page, not a page: it expects the calendar page to have fetched the events
-- already. Migration 112 also seeded it as an address of its own, and opened
-- directly it stops with "Undefined variable $events" and then a fatal error,
-- so the visitor gets a server error. Nothing links to that address.
--
-- Nothing includes the file today either. calendar/index.php only loads a view
-- whose name is in its $validViews list, and 'photo' is not on that list, so
-- ?view=photo quietly shows the default view instead. The file is left alone
-- here because it belongs to the calendar app: whoever looks after that app
-- should either add 'photo' to the list or delete the file. Removing the
-- address is right either way, because the file was never meant to be opened
-- on its own.
--
-- The delete is matched on BOTH the address and the file it points at, so that
-- replaying this migration can never remove a genuine page that some later
-- version gives the same address.
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing. The installer runs full_schema.sql and then replays every numbered
-- migration, whether or not it has run before. The inserts meet the unique key
-- on routeKey and only rewrite the same values; the delete finds nothing left
-- to delete. Plain INSERT … ON DUPLICATE KEY UPDATE and DELETE, so it behaves
-- the same on MySQL 8.0 and MariaDB. Both parts are repeated in full_schema.sql.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/501
-- =============================================================================

-- #############################################################################
-- 🕒 A. The three job addresses (not protected: each checks its own token)
-- #############################################################################

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('cron/retention-sweep', 'cron/retention-sweep.php', 0),
    ('cron/health',          'cron/health.php',          0),
    ('cron/backup-check',    'cron/backup-check.php',    0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);


-- #############################################################################
-- 🧹 B. Remove the stray page-fragment address
-- #############################################################################

DELETE FROM `tblRoutes`
WHERE `routeKey` = 'calendar/views/photo'
  AND `targetFile` = 'calendar/views/photo.php';


-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('196_scheduled_job_addresses.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
