-- =============================================================================
-- Migration 207: addresses for the outside-calendar administrator pages
--                (#514, part P8)
-- =============================================================================
-- Route rows only. No tables, no columns, no indexes, no settings, no links
-- between tables.
--
-- WHAT IT IS FOR
-- -----------------------------------------------------------------------------
-- #514 lets an organisation copy events in from somebody else's published
-- calendar. Part P8 builds the pages an administrator uses to manage those
-- calendars. Every page needs an address the portal answers on, and this file
-- adds them. The pages themselves live in web/_apps/admin/calendar/.
--
-- ONE FILE, GROWN CHUNK BY CHUNK (the #514 part 8 plan, challenge M1)
-- -----------------------------------------------------------------------------
-- Part P8 is built in four chunks and committed ONCE, at the end. This one
-- migration is created by the first chunk that brings a page and grows as
-- each later chunk adds its own pages, so every address arrives together with
-- the file it points at. Nothing is deployed and nothing is committed between
-- the chunks, so no real database ever holds a half-grown version of this
-- file. Each chunk adds a line below saying which addresses it brought.
--
--   Chunk P8-2 (the calendar pages):
--     admin/calendar/feeds/edit  -> admin/calendar/feeds-edit.php
--         the form that adds a new outside calendar (no ?id=) or changes
--         one (?id=N): its name, address, how often it is checked, its time
--         zone, its default category, who can see its events, and the
--         "Also show on the public website" and "Don't show via API" boxes.
--     admin/calendar/feeds/view  -> admin/calendar/feeds-view.php
--         one calendar's own page: its refresh history, its imported events
--         and who can see each one, and its category matching.
--
--   Chunk P8-3 (choices and rules):
--     admin/calendar/feeds/choice -> admin/calendar/feeds-choice.php
--         ?event=N&scope=date (one date) or &scope=series (every date of a
--         repeating event): choose who may see it, and how much of it, with
--         the dates it covers and whether each is waiting for approval or
--         was declined; or remove the choice.
--     admin/calendar/feeds/rule   -> admin/calendar/feeds-rule.php
--         ?feed=N (a new rule) or ?feed=N&rule=M (a saved one): its
--         conditions and exceptions, who may see the events it matches, a
--         preview of what it would do (which writes nothing), save, delete.
--
--   Chunk P8-4 (approvals, the preview and the guide):
--     admin/calendar/feeds/preview      -> admin/calendar/feeds-preview.php
--         ?approval=N or ?event=N: what the audience would see of a waiting
--         request or an imported date — at "Full details" only, the
--         description as text and the link as plain text, never clickable.
--     admin/calendar/feeds/approvals/act -> admin/calendar/feeds-approvals-act.php
--         POST only: approve or decline one waiting date, or several ticked
--         at once — an ADMIN-app address on purpose, so deciding still works
--         when an organisation switches the Approvals app off (the Router
--         then refuses every approvals… address).
--     help/calendar-imports             -> help/calendar-imports.php
--         the administrators' guide. NOT protected (isProtected = 0), like
--         every help page since migration 005: it holds nothing secret — the
--         scheduled refresh's key is named only as the setting that holds it.
--
-- The two existing addresses, admin/calendar/feeds (the list) and
-- admin/calendar/feeds/save (the buttons), came from migration 129 and are
-- not repeated here: their pages were rewritten, but the addresses and the
-- files they point at did not change.
--
-- WHO MAY REACH EACH ADDRESS
-- -----------------------------------------------------------------------------
-- All but the guide are seeded isProtected = 1, so a signed-out visitor is
-- sent to the sign-in page before the page runs. Being signed in is not enough: each page
-- then refuses (403) anybody who is not an administrator OF THIS
-- ORGANISATION — a site administrator of it, or a global administrator —
-- and answers "not found" for another organisation's calendar, event or
-- rule, exactly as for one that does not exist (the choice page looks up an
-- event, the rule page a rule of that calendar; corrected in fix round 1 of
-- chunk P8-3, which said "a calendar" only).
--
-- WHAT IT CANNOT DO
-- -----------------------------------------------------------------------------
-- It only makes the addresses exist. It decides nothing about who may use
-- them; the pages do that, every time, as described above.
--
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing new. The installer runs full_schema.sql and then replays every
-- numbered migration, whether or not it has run before. The insert meets the
-- unique key on routeKey and rewrites the same values. The duplicate-key
-- clause also rewrites isProtected (as migration 196 does), so a replay puts
-- right an address that was somehow left unprotected (challenge L6).
-- Plain INSERT … ON DUPLICATE KEY UPDATE behaves the same on MySQL 8.0 and on
-- MariaDB, so none of the information_schema guards the column changes need.
-- The same rows are repeated in full_schema.sql, in its last block.
--
-- WEB-ROOT SHADOWING
-- -----------------------------------------------------------------------------
-- web/public_html/ holds no folder or file called "admin" or "help", so nothing
-- there can answer these addresses before the portal does
-- (tools/audit-checks/check_webroot_shadowing.py checks this).
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
-- =============================================================================

-- #############################################################################
-- 🗓️ A. The page addresses (protected: signed-in administrators only — all
--        but the guide, which every visitor may read)
-- #############################################################################

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/calendar/feeds/edit',          'admin/calendar/feeds-edit.php',          1),
    ('admin/calendar/feeds/view',          'admin/calendar/feeds-view.php',          1),
    ('admin/calendar/feeds/choice',        'admin/calendar/feeds-choice.php',        1),
    ('admin/calendar/feeds/rule',          'admin/calendar/feeds-rule.php',          1),
    ('admin/calendar/feeds/preview',       'admin/calendar/feeds-preview.php',       1),
    ('admin/calendar/feeds/approvals/act', 'admin/calendar/feeds-approvals-act.php', 1),
    ('help/calendar-imports',              'help/calendar-imports.php',              0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);


-- #############################################################################
-- 📋 B. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('207_calendar_feed_admin_pages.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
