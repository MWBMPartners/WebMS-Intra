-- =============================================================================
-- Migration 272: the name of who made a pastoral visit or recorded a
--                safeguarding check is KEPT when that person is erased (#703)
-- =============================================================================
-- Two new columns, one comment made true, and a one-off copy of names into the
-- new columns for records that already exist. No row is deleted, no link
-- between tables is touched, and no account is changed.
--
--   tblCareVisit.visitedByName    VARCHAR(255)  who made a pastoral visit
--   tblDbsChecks.recordedByName   VARCHAR(255)  who recorded a DBS check
--
-- -----------------------------------------------------------------------------
-- WHY (the owner's decision D-016, 3 November 2026)
-- -----------------------------------------------------------------------------
-- When a member of staff or a volunteer asks to be erased, the portal KEEPS the
-- name of who made a pastoral visit or recorded a safeguarding check. The owner
-- chose that over removing it because a safeguarding record that cannot say who
-- carried it out is worse for the people it protects. Recorded in
-- `docs/decisions/016-several-privacy-decisions.md` and on issue #703; it rules
-- out "unlinking the worker".
--
-- Until this change the code did the opposite: migration 208 made
-- `visitedByID` emptiable and `GdprEraser` emptied it, and the DBS check's
-- `recordedByID` was emptied by the derived instruction for "who handled it".
--
-- -----------------------------------------------------------------------------
-- WHY A NAME COLUMN, AND NOT JUST "STOP EMPTYING THE NUMBER"
-- -----------------------------------------------------------------------------
-- Keeping `visitedByID` alone would keep NOTHING anybody can read. An erasure
-- empties the person's own account row: its name becomes "[Deleted User]".
-- A kept number would point at that emptied account, which is exactly the
-- "worst of both" migration 208's own notes describe. So the name itself has
-- to be stored on the record.
--
-- It is written WHEN THE RECORD IS MADE (by `care/visit-save.php` and
-- `admin/safeguarding/dbs-save.php`, from the account's own `fullName` in the
-- same statement), not copied in at the moment of erasure. Copying it at
-- erasure would make the name depend on one step of the erasure running before
-- another and succeeding: a run that failed at that step and emptied the
-- account anyway would lose the name for good, and nothing could get it back.
-- Written at the start, it is simply part of the record.
--
-- -----------------------------------------------------------------------------
-- THE ONE-OFF COPY, AND WHAT IT CANNOT DO
-- -----------------------------------------------------------------------------
-- For records made before this change, the name is copied from the account the
-- record still points at - only where the column is still empty, and NEVER from
-- an account an erasure has already emptied (that would write
-- "[Deleted User]" into the record as though it were somebody's name). The test
-- for "already emptied" is the same three facts `GdprEraser::emptiedAccountSql()`
-- uses: switched off, named "[Deleted User]", and given the stand-in e-mail
-- address.
--
-- A worker erased BEFORE this change cannot be given their name back: the old
-- erasure removed the number as well, so nothing on the record says who it
-- was. Those records keep showing "Somebody no longer recorded". Only a backup
-- taken before that erasure could say.
--
-- The name copied is the account's name TODAY. Somebody who has changed their
-- name since an old visit gets today's name on it; a record made from now on
-- keeps the name the account had when the record was made.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Each column is added only when it is missing. The copy fills only empty
-- names, so a second run changes nothing. The comment change rewrites the same
-- definition the column already has, with a true comment.
--
-- Written to work on both MySQL 8.0 and MariaDB: no `IF NOT EXISTS` on a
-- column (MariaDB-only), every identifier backticked.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/703
-- =============================================================================

-- ── tblCareVisit.visitedByName ──────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblCareVisit'
      AND COLUMN_NAME  = 'visitedByName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblCareVisit` ADD COLUMN `visitedByName` VARCHAR(255) DEFAULT NULL COMMENT ''The name of whoever made the visit, written when the visit is recorded. KEPT when that person is erased, by decision D-016 of the owner (#703, migration 272): a pastoral record must still say who made the visit'' AFTER `visitedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The comment on `visitedByID` said the number is emptied when the visitor is
-- forgotten. That stopped being true with this change, so it is rewritten.
-- The definition is otherwise exactly migration 208's: INT, may be empty.
ALTER TABLE `tblCareVisit` MODIFY COLUMN `visitedByID` INT DEFAULT NULL COMMENT 'Who made the visit. KEPT when that person is erased (D-016, #703, migration 272); their name is on visitedByName, because their own account is emptied. Empty only on a visit whose visitor was erased before that change.';

UPDATE `tblCareVisit` `v`
  JOIN `tblUsers` `u` ON `u`.`userID` = `v`.`visitedByID`
   SET `v`.`visitedByName` = `u`.`fullName`
 WHERE `v`.`visitedByName` IS NULL
   AND NOT (`u`.`isActive` = 0
            AND `u`.`fullName` = '[Deleted User]'
            AND `u`.`emailAddress` LIKE 'deleted-%@example.invalid');

-- ── tblDbsChecks.recordedByName ─────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDbsChecks'
      AND COLUMN_NAME  = 'recordedByName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblDbsChecks` ADD COLUMN `recordedByName` VARCHAR(255) DEFAULT NULL COMMENT ''The name of whoever recorded the check, written when it is recorded. KEPT when that person is erased, by decision D-016 of the owner (#703, migration 272)'' AFTER `recordedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `tblDbsChecks` `d`
  JOIN `tblUsers` `u` ON `u`.`userID` = `d`.`recordedByID`
   SET `d`.`recordedByName` = `u`.`fullName`
 WHERE `d`.`recordedByName` IS NULL
   AND NOT (`u`.`isActive` = 0
            AND `u`.`fullName` = '[Deleted User]'
            AND `u`.`emailAddress` LIKE 'deleted-%@example.invalid');

INSERT INTO `tblMigrations` (`filename`) VALUES ('272_worker_name_kept_on_care_records.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
