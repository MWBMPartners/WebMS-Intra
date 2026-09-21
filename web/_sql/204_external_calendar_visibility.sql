-- =============================================================================
-- Migration 204: who may see an event copied in from an outside calendar (#514, part P1)
-- =============================================================================
-- An organisation can subscribe the portal to an outside calendar (a Google,
-- Microsoft 365 or other published calendar file — issue #327). A scheduled
-- job downloads the file and copies each event into `tblEvents`, marked with
-- the calendar it came from (`externalFeedID`).
--
-- Until now every copied event was treated as public: anyone at all could
-- see it, with every detail. Issue #514 lets an administrator decide, per
-- calendar and later per event, who may see these events and how much of
-- each one they see. This migration is the first step of that work. It adds
-- the places where those answers are STORED. It does not yet let anybody
-- change them.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
--   A. `tblEvents` gains nine columns, used ONLY on copied-in events (rows
--      where `externalFeedID` is set). The portal's own events keep using
--      the existing `isPublic` column exactly as before, and every one of
--      the several pages that write `isPublic` stays correct. Two columns
--      that disagreed silently would have been worse than one.
--        importLevel        who may see it: public, members, groups, hidden
--        importDetail       full, or "title, date and time only" (basic) for
--                           people outside the calendar's own audience
--        importWebsite      may it also go to the organisation's public
--                           website feeds
--        importAudienceType / importAudienceID
--                           which list of people the "groups" level uses
--        importSource / importSourceID
--                           why the answer is what it is, for administrators
--        importRecheckAt    a moment (in UTC) after which the stored answer
--                           is no longer trusted; see below
--        externalPrivate    1 when the outside calendar marked the event
--                           private or confidential
--      Plus two indexes: one for "this calendar's events at this level", one
--      for "which stored answers have run out".
--   B. `tblExternalFeeds` (the calendars) gains `audienceLevel` (the
--      calendar's own setting: public, members or groups) and
--      `websiteOptIn` (also show on the public website).
--   C. A new table, `tblExternalAudienceMembers`: the lists of people,
--      small groups and leadership roles allowed to see a "groups"-level
--      calendar, or (in a later part) a single date or a rule.
--   D. This migration's own record.
--
-- -----------------------------------------------------------------------------
-- NOTHING CHANGES FOR ANYBODY YET
-- -----------------------------------------------------------------------------
-- Every EXISTING copied-in event is marked public, full detail, not on the
-- website, owned by its own calendar (A's backfill). Every EXISTING calendar
-- is marked public (B's backfill), because every existing calendar has been
-- public in effect since #327: the job wrote `isPublic = 1` on every row it
-- copied. The job and the "add a calendar" form are changed in the same
-- commit to keep writing exactly those values, until later parts of #514
-- let an administrator choose.
--
-- The COLUMN defaults are the narrow ones on purpose: a copied-in event
-- written by anything that forgets to set `importLevel` is hidden
-- (administrators only), and a calendar written by anything that forgets
-- `audienceLevel` is members-only. A mistake in a later writer therefore
-- fails closed, never open.
--
-- -----------------------------------------------------------------------------
-- WHY `importRecheckAt` EXISTS
-- -----------------------------------------------------------------------------
-- Some answers will only hold between two dates (a later part adds choices
-- and rules with a start and an end). If the scheduled job stops running,
-- an answer that should have ended would otherwise stay in force for ever.
-- So the stored answer carries its own expiry: after that moment, only
-- administrators see the event until the answer is worked out again. That
-- failure is closed, never open. It is a moment in UTC compared with
-- `UTC_TIMESTAMP()`, not an event time, so the wall-clock rule for event
-- times (DEV_NOTES.md) does not apply to it.
--
-- -----------------------------------------------------------------------------
-- WHY THE NEW TABLE'S COLLATION IS WRITTEN OUT (it is load-bearing)
-- -----------------------------------------------------------------------------
-- "Collation" is the rule a database uses to compare two pieces of text.
-- `tblEvents` uses `utf8mb4_general_ci`. A database created through a
-- hosting control panel on MySQL 8 defaults to a DIFFERENT rule,
-- `utf8mb4_0900_ai_ci`. A new table that does not name its own collation
-- ends up with that different rule: it takes the database's default, or,
-- if it names only its character set (`DEFAULT CHARSET=utf8mb4`), that
-- character set's own default, which on MySQL 8 is `utf8mb4_0900_ai_ci`
-- whatever the database says (measured on 8.0.36, 21 September 2026). The
-- visibility rule compares `tblExternalAudienceMembers.ownerType` with
-- `tblEvents.importAudienceType`. With two different collations on the two
-- sides, MySQL refuses to run the statement at all: "ERROR 1267 Illegal mix
-- of collations". That was proven on MySQL 8.0.36 while planning #514, and
-- again while building it. Neither the installer nor the migration test
-- harness would show it: they create their database with
-- `utf8mb4_general_ci`, and neither runs the visibility rule. So the CREATE
-- TABLE below names `utf8mb4_general_ci` itself, and
-- `tools/event-visibility-selftest.php` refuses to pass if the table ends
-- up with any other collation.
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing, on a database that already has these columns and this table. The
-- installer runs full_schema.sql and then replays every numbered migration
-- whatever has already run, so every change here is guarded by a check of
-- `information_schema` (the MySQL 8.0-safe form; MariaDB-only `IF NOT
-- EXISTS` on columns and indexes is refused by MySQL with ERROR 1064).
--
-- The two backfills run ONLY in the run that adds their column. Each guard
-- records "this column is missing" (`@needImport`, `@needAudience`) BEFORE
-- its ALTER, and the backfill runs only when that was true. So a replay can
-- never widen an event an administrator has since narrowed: an imported
-- event set to "hidden" stays hidden however many times this file runs.
-- (That was leak-hunt finding 27 in the #514 plan; a plain UPDATE here
-- would have re-published every narrowed event on each upgrade.)
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION CANNOT DO
-- -----------------------------------------------------------------------------
-- It decides nothing about who sees what. The rule that reads these
-- columns is `Portal\Core\EventVisibility` (added in the same commit), and
-- the pages begin to use it in the next part of #514. Until then the pages
-- behave exactly as before. `importAudienceID` has no foreign key, because
-- it points at one of three different tables depending on
-- `importAudienceType` (a calendar, a single-date choice or a rule; the last
-- two tables arrive in a later part). Like everything else here, it has not
-- been tested on MariaDB.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
-- =============================================================================


-- #############################################################################
-- 🅰️  A. tblEvents — nine columns used only on copied-in events
-- #############################################################################

-- ➕ A1 `importLevel`. The guard's answer is kept in @needImport BEFORE the
--    ALTER, because the backfill in A11 must run only in the run that adds
--    this column (see "WHAT REPLAY DOES" in the header). Once the column
--    exists, the same question would answer "no" and the backfill would
--    be skipped for good — which is exactly what a replay needs.
SET @needImport := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importLevel');
SET @sql := IF(@needImport = 1, 'ALTER TABLE `tblEvents` ADD COLUMN `importLevel` ENUM(''public'',''members'',''groups'',''hidden'') NOT NULL DEFAULT ''hidden'' COMMENT ''Imported events only: who may see it, as worked out by FeedResolver. Ignored when externalFeedID IS NULL.'' AFTER `externalUid`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A2 `importDetail`: full, or "title, date and time only".
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importDetail');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importDetail` ENUM(''full'',''basic'') NOT NULL DEFAULT ''basic'' COMMENT ''Imported events only: detail for viewers outside the calendar''''s own audience.'' AFTER `importLevel`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A3 `importWebsite`: may it go to the public website feeds (owner decision D4).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importWebsite');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importWebsite` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Imported events only: 1 = may go to the organisation''''s public website feeds (D4).'' AFTER `importDetail`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A4 `importAudienceType`: whose list the "groups" level uses.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importAudienceType');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importAudienceType` ENUM(''feed'',''choice'',''rule'') DEFAULT NULL COMMENT ''Owner of the audience list used at the groups level.'' AFTER `importWebsite`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A5 `importAudienceID`. No foreign key, deliberately: it points at one of
--    three different tables, chosen by importAudienceType.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importAudienceID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importAudienceID` INT DEFAULT NULL COMMENT ''feedID, choiceID or ruleID matching importAudienceType. No foreign key: it points at one of three tables.'' AFTER `importAudienceType`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A6 `importSource`: why importLevel is what it is (shown to administrators).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importSource');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importSource` ENUM(''calendar'',''date'',''series'',''rule'',''waiting'',''private'',''conflict'',''duplicate'') DEFAULT NULL COMMENT ''Why importLevel is what it is; shown to administrators.'' AFTER `importAudienceID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A7 `importSourceID`: the number of the choice or rule named by importSource.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importSourceID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importSourceID` INT DEFAULT NULL AFTER `importSource`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A8 `importRecheckAt`: a UTC moment, not an event time (see the header).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importRecheckAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `importRecheckAt` DATETIME DEFAULT NULL COMMENT ''UTC moment after which the stored answer is not trusted; only administrators see the event until it is worked out again.'' AFTER `importSourceID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A9 `externalPrivate`: the outside calendar marked it private.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='externalPrivate');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `externalPrivate` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the outside calendar marked it private or confidential (any CLASS other than PUBLIC).'' AFTER `importRecheckAt`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔑 A10 Two indexes, each declared by name so this file and full_schema.sql
--    agree object for object.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND INDEX_NAME='idx_event_import');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD KEY `idx_event_import` (`externalFeedID`,`importLevel`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND INDEX_NAME='idx_event_import_recheck');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD KEY `idx_event_import_recheck` (`importRecheckAt`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧭 A11 The backfill: every EXISTING copied-in event keeps behaving exactly
--    as today — public, full detail, owned by its own calendar — and stays
--    OFF the public website feeds. That last point is the #514 plan's own
--    stated interpretation, not an owner decision: the plan lists "existing
--    calendars stay Public, with the website box unticked, until an
--    administrator changes them" under "Interpretations stated, not
--    questions" (plan section 3). It follows owner decision D4, under which
--    nothing reaches the public website unless an administrator ticks the
--    box. It runs after the last ALTER above because it writes columns
--    A2-A6 add, and ONLY when @needImport said A1 was adding importLevel in
--    this very run.
SET @sql := IF(@needImport = 1, 'UPDATE `tblEvents` SET `importLevel` = ''public'', `importDetail` = ''full'', `importWebsite` = 0, `importAudienceType` = ''feed'', `importAudienceID` = `externalFeedID`, `importSource` = ''calendar'' WHERE `externalFeedID` IS NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅱️  B. tblExternalFeeds — the calendar's own setting
-- #############################################################################

-- ➕ B1 `audienceLevel` (owner decision D7). Default "members", the narrow
--    one, for the fail-closed reason in the header; B3 marks every existing
--    calendar public, which is what they have always been in effect.
SET @needAudience := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='audienceLevel');
SET @sql := IF(@needAudience = 1, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `audienceLevel` ENUM(''public'',''members'',''groups'') NOT NULL DEFAULT ''members'' COMMENT ''The calendar''''s own setting (D7).'' AFTER `categoryID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ B2 `websiteOptIn` (owner decision D4): off unless an administrator ticks it.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='websiteOptIn');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `websiteOptIn` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''D4: also show on the public website; only meaningful at public.'' AFTER `audienceLevel`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧭 B3 The backfill: every existing calendar is public, because the import
--    job has always written `isPublic = 1` on everything it copied
--    (`cron/import-feeds.php`). Only in the run that adds the column, for
--    the same replay reason as A11: a calendar an administrator later
--    narrows must never be widened again by an upgrade.
SET @sql := IF(@needAudience = 1, 'UPDATE `tblExternalFeeds` SET `audienceLevel` = ''public''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅲  C. tblExternalAudienceMembers — who is on a "groups" list
-- #############################################################################
-- One list per owner: a calendar (`feed`), a single-date or series choice
-- (`choice`) or a rule (`rule`); the last two arrive in a later part. Each
-- row names one person, one small group, one leadership role, or (in a
-- later part, once they are switched on) one role, user group or
-- department. Every reference is to something of the SAME organisation,
-- which the visibility rule checks again inside every query.
--
--   feedID  is ALWAYS the calendar the list belongs to, even for a choice
--           or a rule, so deleting the calendar removes every list it owns
--           (fk_extaud_feed). `ownerID` alone could not do that, because it
--           points at one of three tables.
--   userID  is set on "person" rows only. It carries the foreign key that
--           removes the row when the account itself is deleted, and it is
--           what the erasure and data-download pages look for.
--
-- COLLATE utf8mb4_general_ci is written out on purpose — see "WHY THE NEW
-- TABLE'S COLLATION IS WRITTEN OUT" in the header. Do not remove it.
CREATE TABLE IF NOT EXISTS `tblExternalAudienceMembers` (
    `audienceMemberID` INT NOT NULL AUTO_INCREMENT,
    `siteID`      INT NOT NULL COMMENT 'The organisation the list belongs to.',
    `feedID`      INT NOT NULL COMMENT 'Always the calendar the list belongs to, so deleting the calendar removes every list.',
    `ownerType`   ENUM('feed','choice','rule') NOT NULL COMMENT 'What owns this list: the calendar itself, a single-date or series choice, or a rule.',
    `ownerID`     INT NOT NULL COMMENT 'feedID, choiceID or ruleID matching ownerType. No foreign key: it points at one of three tables.',
    `kind`        ENUM('person','small_group','leadership_role','role','user_group','department') NOT NULL COMMENT 'What refID names.',
    `refID`       INT NOT NULL COMMENT 'Group, role or department number; for person the user number again.',
    `userID`      INT DEFAULT NULL COMMENT 'person rows only; foreign key for erasure',
    `createdByID` INT DEFAULT NULL COMMENT 'Who added this entry; emptied, never cascaded, if that account is removed.',
    `createdAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`audienceMemberID`),
    UNIQUE KEY `uq_extaud_owner_member` (`ownerType`,`ownerID`,`kind`,`refID`),
    KEY `idx_extaud_feed` (`feedID`),
    KEY `idx_extaud_user` (`userID`),
    CONSTRAINT `fk_extaud_feed`    FOREIGN KEY (`feedID`)      REFERENCES `tblExternalFeeds`(`feedID`) ON DELETE CASCADE,
    CONSTRAINT `fk_extaud_user`    FOREIGN KEY (`userID`)      REFERENCES `tblUsers`(`userID`)         ON DELETE CASCADE,
    CONSTRAINT `fk_extaud_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Who may see a groups-level outside calendar, single-date choice or rule (#514). Membership is tested live in the visibility rule.';


-- #############################################################################
-- 📋  D. Self-record
-- #############################################################################
-- The installer replays every numbered migration after full_schema.sql and
-- ignores tblMigrations, so this has to be safe to run a second time.
INSERT INTO `tblMigrations` (`filename`) VALUES ('204_external_calendar_visibility.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
