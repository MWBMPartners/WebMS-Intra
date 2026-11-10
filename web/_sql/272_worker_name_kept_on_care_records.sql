-- =============================================================================
-- Migration 272: the name of the worker on a pastoral, safeguarding or
--                children's check-in record is KEPT when that worker is
--                erased (#703; D-016, D-110, D-111, D-112, D-113)
-- =============================================================================
-- Five new columns, one comment made true, and a one-off copy of names into
-- the new columns for records that already exist. No row is deleted, no link
-- between tables is touched, and no account is changed.
--
--   tblCareVisit.visitedByName       who made a pastoral visit         (D-016)
--   tblDbsChecks.recordedByName      who recorded a DBS check          (D-016)
--   tblCareCase.openedByName         who opened a pastoral care case   (D-110)
--   tblKidCheckins.checkedInByName   who checked a child in            (D-111)
--   tblKidCheckins.checkedOutByName  who checked a child out           (D-111)
--
-- Each is VARCHAR(255), may be empty, and sits straight after the account
-- number column it names.
--
-- -----------------------------------------------------------------------------
-- WHY (the owner's decisions)
-- -----------------------------------------------------------------------------
-- D-016 (3 November 2026): when a member of staff or a volunteer asks to be
-- erased, the portal KEEPS the name of who made a pastoral visit or recorded a
-- safeguarding check, because a safeguarding record that cannot say who carried
-- it out is worse for the people it protects. It "rules out unlinking the
-- worker". D-110 and D-111 (10 November 2026) extend that to who opened a
-- pastoral care case and who checked a child in or out. D-112: the name stays
-- even on a visit the worker made on their OWN case. D-113: the account NUMBER
-- stays too, and the portal says plainly that through these records an erased
-- worker can be re-identified on their other records.
--
-- Until this change the code did the opposite: migration 208 made
-- `visitedByID` emptiable and `GdprEraser` emptied it; the DBS check's
-- `recordedByID`, the case's `openedByID` and the two check-in helpers were
-- emptied too.
--
-- -----------------------------------------------------------------------------
-- WHY A NAME COLUMN, AND NOT JUST "STOP EMPTYING THE NUMBER"
-- -----------------------------------------------------------------------------
-- Keeping the number alone would keep NOTHING anybody can read. An erasure
-- empties the person's own account row: its name becomes "[Deleted User]".
-- A kept number would point at that emptied account, which is exactly the
-- "worst of both" migration 208's own notes describe. So the name itself has
-- to be stored on the record.
--
-- It is written WHEN THE RECORD IS MADE (by `care/visit-save.php`,
-- `care/case-save.php`, `admin/safeguarding/dbs-save.php`,
-- `kids/checkin-do.php` and `kids/checkout.php`, from the account's own
-- `fullName` in the same statement), not copied in at the moment of erasure.
-- Copying it at erasure would make the name depend on one step of the erasure
-- running before another and succeeding: a run that failed at that step and
-- emptied the account anyway would lose the name for good.
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
-- address. All three must hold, as they do there.
--
-- A worker erased BEFORE this change cannot be given their name back: the old
-- erasure removed the number as well, so nothing on the record says who it
-- was. Those records keep showing "Somebody no longer recorded". Only a backup
-- taken before that erasure could say.
--
-- The name copied is the account's name TODAY. Somebody who has changed their
-- name since an old record gets today's name on it; a record made from now on
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
-- column (MariaDB-only), every identifier backticked, and no apostrophe inside
-- the nested comment strings (each would need escaping twice).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/703
-- =============================================================================

-- ── tblCareVisit.visitedByName (D-016) ──────────────────────────────────────
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

-- ── tblDbsChecks.recordedByName (D-016) ─────────────────────────────────────
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

-- ── tblCareCase.openedByName (D-110) ────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblCareCase'
      AND COLUMN_NAME  = 'openedByName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblCareCase` ADD COLUMN `openedByName` VARCHAR(255) DEFAULT NULL COMMENT ''The name of whoever opened the case, written when it is opened. KEPT when that person is erased, by decision D-110 of the owner (#703, migration 272)'' AFTER `openedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `tblCareCase` `c`
  JOIN `tblUsers` `u` ON `u`.`userID` = `c`.`openedByID`
   SET `c`.`openedByName` = `u`.`fullName`
 WHERE `c`.`openedByName` IS NULL
   AND NOT (`u`.`isActive` = 0
            AND `u`.`fullName` = '[Deleted User]'
            AND `u`.`emailAddress` LIKE 'deleted-%@example.invalid');

-- ── tblKidCheckins.checkedInByName and checkedOutByName (D-111) ─────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblKidCheckins'
      AND COLUMN_NAME  = 'checkedInByName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblKidCheckins` ADD COLUMN `checkedInByName` VARCHAR(255) DEFAULT NULL COMMENT ''The name of the helper who checked the child in, written at check-in. KEPT when that helper is erased, by decision D-111 of the owner (#703, migration 272)'' AFTER `checkedInByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `tblKidCheckins` `k`
  JOIN `tblUsers` `u` ON `u`.`userID` = `k`.`checkedInByID`
   SET `k`.`checkedInByName` = `u`.`fullName`
 WHERE `k`.`checkedInByName` IS NULL
   AND NOT (`u`.`isActive` = 0
            AND `u`.`fullName` = '[Deleted User]'
            AND `u`.`emailAddress` LIKE 'deleted-%@example.invalid');

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblKidCheckins'
      AND COLUMN_NAME  = 'checkedOutByName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblKidCheckins` ADD COLUMN `checkedOutByName` VARCHAR(255) DEFAULT NULL COMMENT ''The name of the helper who checked the child out, written at check-out. KEPT when that helper is erased, by decision D-111 of the owner (#703, migration 272)'' AFTER `checkedOutByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `tblKidCheckins` `k`
  JOIN `tblUsers` `u` ON `u`.`userID` = `k`.`checkedOutByID`
   SET `k`.`checkedOutByName` = `u`.`fullName`
 WHERE `k`.`checkedOutByName` IS NULL
   AND NOT (`u`.`isActive` = 0
            AND `u`.`fullName` = '[Deleted User]'
            AND `u`.`emailAddress` LIKE 'deleted-%@example.invalid');

INSERT INTO `tblMigrations` (`filename`) VALUES ('272_worker_name_kept_on_care_records.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
