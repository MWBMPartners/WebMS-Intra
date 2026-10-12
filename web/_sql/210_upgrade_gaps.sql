-- =============================================================================
-- Migration 210: close the gaps between an upgraded database and a fresh install
--                (#553, #554, and the rows only the install script seeded)
--
-- Path: web/_sql/210_upgrade_gaps.sql
--
-- WHY THIS EXISTS
--   A customer's database comes into being one of two ways: the installer
--   builds it from web/_sql/full_schema.sql, or an older one is upgraded by
--   the numbered migrations. The two are meant to end up the same. Until 12
--   October 2026 no test ever upgraded an OLD database, so differences had
--   built up unseen. This file closes each one listed below. Migration 124 was
--   corrected in the same piece of work (#555); that one could not go here,
--   because the upgrade stopped at 124 and would never have reached this file.
--
--   Found and proved by phases 5 and 6 of tools/e2e-migrations/run.sh.
--
-- WHAT IT DOES, AND TO WHICH DATABASES
--   A. tblEventCategories: removes the old key `uq_category_slug` (#553).
--      Databases installed before 8 March 2026. With it in place, a second
--      organisation could not create a category whose short name the first
--      organisation already used.
--   B. tblAttendanceSessions: adds the link `fk_att_sess_event` (#554).
--      EVERY database built by the install script before 12 October 2026,
--      because until then that script never declared it (see B below). THIS IS
--      THE ONE PART THAT CHANGES STORED DATA.
--   C. Four addresses that only the install script seeded. The two podcast
--      addresses were missing on EVERY upgraded database, so the podcast feed
--      answered "page not found" there.
--   D. Three settings that only the install script seeded.
--   E. tblSites.primaryColor: the default for a NEW organisation given no
--      colour. Existing organisations keep the colour they have.
--   F. tblTrustedDevices: two keys renamed to the names a fresh install uses.
--      No change in behaviour.
--
-- SAFE TO RUN TWICE, AND SAFE IF IT STOPS HALF WAY
--   Every statement first asks the database whether its change is still
--   needed, one question per change, so a second run does nothing and a run
--   that was cut off picks up where it stopped. The installer relies on that:
--   it replays every numbered migration after the install script.
--
-- WHAT IT DOES NOT DO
--   It does not remove the leftover setting `attendancecount.enabled` found on
--   databases older than the main branch. Nothing reads it, and deleting a
--   customer's row to tidy up is not worth the risk.
--   It deletes no row anywhere. It changes no setting's value.
--   Nothing here has been run on MariaDB; no test in this repository covers it.
--
-- All structure changes use the information_schema + PREPARE form, because
-- MySQL 8 refuses MariaDB's `IF [NOT] EXISTS` on ALTER (error 1064). See
-- DEV_NOTES.md, "Portable DDL convention".
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/553
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/554
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/555
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- 📂  A. tblEventCategories — one short name per ORGANISATION, not per portal (#553)
-- #############################################################################
-- Migration 008 created the key as `uq_category_slug`. Migration 019, which
-- was meant to replace it with a per-organisation key, removed a key called
-- `uq_cat_slug` — a name that never existed — so the old key stayed. The four
-- other tables 019 touched were named correctly; this is the only one.
--
-- Order matters: the new key is made sure of FIRST and the old one removed
-- SECOND, so the table is never left with no rule at all, even if the file
-- stops between the two. Adding the new key cannot fail on existing rows
-- while the old key is in place: the old key is the stricter of the two, so
-- anything it allowed the new one allows. (A database with NEITHER key that
-- holds the same short name twice in one organisation is different: there the
-- first statement stops with error 1062. That is a loud stop with nothing
-- lost, and somebody has to choose which of the two rows keeps the name.)

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND INDEX_NAME   = 'uq_cat_slug_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventCategories` ADD UNIQUE KEY `uq_cat_slug_site` (`categorySlug`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND INDEX_NAME   = 'uq_category_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblEventCategories` DROP INDEX `uq_category_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 📋  B. tblAttendanceSessions — the link to the event it belongs to (#554)
-- #############################################################################
-- Migration 009 declared `fk_att_sess_event` (eventID → tblEvents.eventID,
-- emptied when the event is deleted). Until 12 October 2026 the install script
-- never did: it creates tblAttendanceSessions BEFORE tblEvents, and a link
-- written inside CREATE TABLE cannot name a table that does not exist yet. So
-- old databases had the link and every fresh install lacked it. This adds it
-- wherever it is missing.
--
-- The install script now adds it as well, at its very end, once both tables
-- exist, using the same three statements as below (the question, the UPDATE
-- and the ALTER). This comment used to say
-- that file "holds no stand-alone ALTER by design", so the link could only
-- arrive when the installer replayed this migration. That was untrue (the
-- file already held two, for migration 187), and it mattered: the install
-- script records this migration as already run, so a database built from that
-- file ALONE would never have been given the link by anything.
--
-- ⚠️ THE ONE STATEMENT IN THIS FILE THAT CHANGES STORED DATA is the UPDATE
-- below. Without the link, deleting an event left its attendance sessions
-- pointing at an event number that no longer exists, and the database will
-- not accept the link while such a row is there. So those pointers are
-- emptied first. What is lost is a pointer to nothing, which is exactly what
-- the link would have done at the moment the event was deleted. The session
-- itself, its date, its counts and its notes are untouched, and
-- `updatedAt = updatedAt` keeps its "last changed" time as it was, because no
-- person changed it.
--
-- Both statements run only while NO link from this column to tblEvents is
-- there, whatever it is called. The question used to be asked by name
-- (`fk_att_sess_event`), so a database that already had the link under another
-- name was given a second one beside it. No release of this project ever made
-- one under another name; a hand-made one is the case in mind. What that
-- means: a link found under another name is left exactly as it is, its name
-- and its "on delete" rule included, so such a database still differs from a
-- fresh install. Nothing here renames or changes a link.
--
-- If the UPDATE somehow missed a row, the ALTER stops with error 1452 rather
-- than carrying on: a loud stop is better than a link quietly not added.
--
-- If tblEvents itself is MISSING (a restore that got part-way), the UPDATE
-- stops at once with error 1146 and no session is changed. The install script
-- stops the same way on the same database, by a statement of its own placed
-- before it makes tblEvents; see the comment there for why it needs one and
-- this file does not.
--
-- ⚠️ WHAT THIS MEANS FOR A BACKUP TAKEN BEFORE THIS MIGRATION RAN. The upgrade
-- page takes a snapshot just before it runs the migrations. That snapshot, and
-- any older one, can still hold a session pointing at an event that is gone.
-- Once the link is in place the database refuses such a row (error 1452). So
-- whatever puts an older snapshot back MUST first empty those pointers, by the
-- same rule as the UPDATE below, or the restore is refused. That is the job of
-- the restore code (web/_core/DbBackup.php). This file cannot do it: a
-- migration cannot reach into a snapshot. Nothing here promises that the
-- restore code does so; read that file before relying on an older snapshot.

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA          = DATABASE()
      AND TABLE_NAME            = 'tblAttendanceSessions'
      AND COLUMN_NAME           = 'eventID'
      AND REFERENCED_TABLE_NAME = 'tblEvents'
);

SET @sql := IF(@fk_exists = 0,
    'UPDATE `tblAttendanceSessions` SET `eventID` = NULL, `updatedAt` = `updatedAt` WHERE `eventID` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `tblEvents` WHERE `tblEvents`.`eventID` = `tblAttendanceSessions`.`eventID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblAttendanceSessions` ADD CONSTRAINT `fk_att_sess_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔗  C. Four addresses that only the install script seeded
-- #############################################################################
-- Each was written into full_schema.sql and into no migration, so an upgraded
-- database never got it and the page answered "page not found":
--   recordings/podcast, recordings/podcast-media — missing on EVERY upgraded
--       database, including one upgraded from the main branch. Both are public
--       on purpose (isProtected = 0): a podcast program cannot sign in. The
--       pages check the feed's own secret token instead.
--   admin/release-notes, help/calendar — missing on databases older than the
--       main branch.
-- The statements are the install script's own, so the two cannot disagree.
-- None of the four ends in `.php`: the portal refuses any address that does.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('recordings/podcast',       'recordings/podcast.php',       0),
    ('recordings/podcast-media', 'recordings/podcast-media.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/release-notes', 'admin/release-notes/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/calendar', 'help/calendar.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- ⚙️  D. Three settings that only the install script seeded
-- #############################################################################
-- The Calendar app's name, icon and colour. They are portal-wide rows: the
-- organisation is left empty (NULL), which means "every organisation".
--
-- `settingKey = settingKey` means "if the row is already there, change
-- nothing". It must never be `settingValue = …`: that would put back the
-- default over a value an administrator chose, every time the installer
-- replays this file.
--
-- This relies on migration 187 having run. Before 187 the database could not
-- tell that a portal-wide row was already there, and an insert like this made
-- a second copy. Migrations run in order, so 187 always comes first.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES
    (NULL, 'calendar.displayName', 'Calendar',                  0, 'Calendar'),
    (NULL, 'calendar.displayIcon', 'fa-solid fa-calendar-days', 0, 'fa-solid fa-calendar-days'),
    (NULL, 'calendar.brandColor',  '#0d6efd',                   0, '#0d6efd')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- #############################################################################
-- 🎨  E. tblSites.primaryColor — the default for a new organisation
-- #############################################################################
-- Migration 015 set the default to blue (#0d6efd); the install script was
-- later changed to indigo (#5e6ad2) and no migration followed. This changes
-- ONLY what a new organisation gets when it is created with no colour given.
-- `ALTER COLUMN … SET DEFAULT` touches no existing row, so every organisation
-- keeps the colour it has.
--
-- The TRIM is for MariaDB, which reports a text default with its quote marks
-- still on. (Written from MariaDB's documentation, not tested.)

SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSites'
      AND COLUMN_NAME  = 'primaryColor'
      AND (COLUMN_DEFAULT IS NULL OR TRIM(BOTH '''' FROM COLUMN_DEFAULT) <> '#5e6ad2')
);
SET @sql := IF(@needs_change > 0,
    'ALTER TABLE `tblSites` ALTER COLUMN `primaryColor` SET DEFAULT ''#5e6ad2''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔐  F. tblTrustedDevices — two keys renamed to match a fresh install
-- #############################################################################
-- Migration 047 called them `uq_token_hash` and `idx_user_active`; the install
-- script calls them `uq_td_token_hash` and `idx_td_user_active`. They cover the
-- same columns and work the same either way. They are renamed so that the
-- migration test can demand that an upgraded database matches a fresh install
-- EXACTLY, with no list of allowed exceptions. A list of exceptions is where
-- the next real difference would hide.
--
-- RENAME INDEX changes the name only: the key is never dropped, so there is no
-- moment without the "one row per token" rule. Each rename happens only when
-- the old name is there AND the new one is not. Anything else is left alone.
-- (RENAME INDEX needs MariaDB 10.5.2 or newer; the portal already requires
-- 10.6.)

SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblTrustedDevices'
      AND INDEX_NAME   = 'uq_token_hash'
);
SET @new_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblTrustedDevices'
      AND INDEX_NAME   = 'uq_td_token_hash'
);
SET @sql := IF(@old_exists > 0 AND @new_exists = 0,
    'ALTER TABLE `tblTrustedDevices` RENAME INDEX `uq_token_hash` TO `uq_td_token_hash`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblTrustedDevices'
      AND INDEX_NAME   = 'idx_user_active'
);
SET @new_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblTrustedDevices'
      AND INDEX_NAME   = 'idx_td_user_active'
);
SET @sql := IF(@old_exists > 0 AND @new_exists = 0,
    'ALTER TABLE `tblTrustedDevices` RENAME INDEX `idx_user_active` TO `idx_td_user_active`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 📋  G. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('210_upgrade_gaps.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
