-- =============================================================================
-- Migration 246: a registration records who it is FOR, not only who typed it
-- =============================================================================
-- One column and one index on `tblEventRegistrations`, plus a one-time copy of
-- what is already there. No new tables, no settings.
--
-- -----------------------------------------------------------------------------
-- THE PROBLEM, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- A registration holds a child's name, date of birth, allergies and medical
-- notes, with a parent's telephone number and e-mail address beside them. When
-- somebody asks to be forgotten, the portal DELETES the registrations it finds
-- for them, rather than merely removing a name — and that is right, because the
-- whole row is about a person.
--
-- The trouble is how it found them. Migration 191 added `submittedByUserID`,
-- which records whoever was signed in when the form was sent, and nothing ever
-- tested that person's relationship to the person being registered. A
-- volunteer may perfectly well enter a registration at a welcome desk, over the
-- telephone, or from a paper form. So erasing that volunteer DELETED the
-- child's registration — and that child's family had asked for nothing.
--
-- For a parent registering their own child it was exactly right. The two cases
-- were indistinguishable because only one of them was being recorded.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS CHANGES
-- -----------------------------------------------------------------------------
-- `registeredForUserID` records whose record the registration IS. From now on
-- the erasure routine deletes a registration found through THIS column, and
-- merely detaches the submitter from one found through `submittedByUserID`.
-- It is the shape already used for a pastoral case (`tblCareCase.personUserID`,
-- issue #709): the subject of a record and the people who worked on it are
-- different columns, because they are different people.
--
-- Empty is normal and allowed, for two separate reasons. Anybody may register
-- somebody who has no account at all — that is the point of a public form, and
-- it must stay possible. And where a signed-in person says the registration is
-- for somebody else, there may be no account to record: the person registered
-- is named by `fullName` and often has none. An empty value is the SAFE
-- direction — the row stays, and the time limit
-- (`tblEvents.registrationRetentionDays`, migration 191) reaches it anyway.
--
-- -----------------------------------------------------------------------------
-- WHAT HAPPENS TO REGISTRATIONS ALREADY IN THE DATABASE
-- -----------------------------------------------------------------------------
-- The person who typed one in is treated as the person it is for. The owner
-- decided that on 3 November 2026, and the reason is that it is true of anyone
-- who registered themselves, which is most of them. Where a volunteer typed one
-- for a child, the child's name is still on the row; only the "who it is for"
-- link is wrong, and nothing acts on that link except an erasure request.
--
-- REJECTED, so that nobody re-opens it: matching the typed name against the
-- accounts in the portal. A wrong match attaches one person's record to another
-- person's account, and that is worse than not knowing.
--
-- So behaviour for existing rows is UNCHANGED by this migration: both columns
-- hold the same number, so an erasure deletes the same rows it deleted before.
-- What changes is that from now on the two facts CAN differ, and the public
-- registration form asks.
--
-- -----------------------------------------------------------------------------
-- THINGS TO KNOW ABOUT THIS FILE
-- -----------------------------------------------------------------------------
-- * NO FOREIGN KEY, deliberately, matching `submittedByUserID`. Adding one
--   would make this migration REFUSE (error 1452) on any database where
--   `submittedByUserID` already holds the number of an account since deleted —
--   which it can, because that column has no foreign key either, and the copy
--   below puts its value in the new column. A dangling number here costs
--   nothing: an erasure matches on the account being erased, which exists.
--
-- * THE COPY RUNS ONLY WHEN THE COLUMN IS BEING CREATED, and that is what makes
--   this file safe to replay. A plain "copy where the new column is empty"
--   would look idempotent and is not: once the form starts recording "this is
--   for somebody else" as an empty value, a second run would fill it in from
--   the submitter and put the original fault back, one row at a time. The
--   installer replays every numbered migration over `full_schema.sql` and
--   ignores `tblMigrations`, so this really does run again.
--
-- * The AFTER clause is not decoration. A new database built from
--   `full_schema.sql` places the column in a particular position; without
--   AFTER, an upgraded database would have the same columns in a different
--   order, and comparing a customer's database against the reference would then
--   show differences that are not differences.
--
-- * Written the portable way (check `information_schema`, then run the change
--   only if it is needed) because MySQL 8 rejects the shorter
--   `IF NOT EXISTS` form on `ALTER TABLE` that MariaDB accepts.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/492
-- =============================================================================

-- #############################################################################
-- ➕ A. Who the registration is for
-- #############################################################################

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRegistrations'
      AND COLUMN_NAME  = 'registeredForUserID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventRegistrations` ADD COLUMN `registeredForUserID` INT DEFAULT NULL COMMENT ''The account of the person this registration is FOR, where they have one and it is known. Not the same as submittedByUserID: a volunteer may enter a registration for somebody else. Empty is normal and allowed (#492 part 3).'' AFTER `submittedByUserID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📋 B. The one-time copy of what is already recorded
-- #############################################################################
-- Guarded by @col_exists as it was read in part A, BEFORE the column was added,
-- so this runs exactly once: on the replay the column is already there and
-- @col_exists is 1, whatever the rows look like by then. See the note above for
-- why "where the new column is empty" would have been the wrong guard.

SET @sql := IF(@col_exists = 0,
    'UPDATE `tblEventRegistrations` SET `registeredForUserID` = `submittedByUserID` WHERE `submittedByUserID` IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🔎 C. An index, so an erasure request can find a person's registrations
--        quickly rather than reading the whole table
-- #############################################################################

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRegistrations'
      AND INDEX_NAME   = 'idx_registration_registered_for'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventRegistrations` ADD KEY `idx_registration_registered_for` (`registeredForUserID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📋 D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('246_registration_subject.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
