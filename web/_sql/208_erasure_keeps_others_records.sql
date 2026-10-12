-- =============================================================================
-- Migration 208: an erasure keeps other people's records
--                (roll-out package A2a)
-- =============================================================================
-- Nine columns change from "must always be filled in" to "may be empty", and
-- one new column is added. No table is added, no data is changed, no link
-- between tables is touched.
--
-- -----------------------------------------------------------------------------
-- WHY THE NINE COLUMNS
-- -----------------------------------------------------------------------------
-- When somebody asks to be forgotten, a record they merely WORKED ON is kept
-- and their account number is taken off it. That is the owner's decision of
-- 11 October 2026 for every "who did it" column in the portal (Salem874).
--
-- On these nine the database said the column must always be filled in, so the
-- number could not be removed:
--
--   tblVisitorContact.contactedByID        who wrote a follow-up note about a visitor
--   tblAssetResources.uploadedByID         who attached a file to a piece of equipment
--   tblVenueAgreementFiles.uploadedByID    who attached a file to a hire agreement
--   tblCareVisit.visitedByID               who made a pastoral visit
--   tblAssetLicenseAssignments.linkedByID  who gave somebody a seat on a licence
--   tblAssetLoans.requestedByID            who asked for a piece of equipment to be lent
--   tblOffboarding.offboardedByID          who processed somebody else's leaving
--   tblResourceBooking.bookedByID          who booked a room or a piece of equipment
--   tblRotaSwapRequest.requestedByID       who asked to swap a rota slot
--
-- Empty simply means "we no longer record who did this". The note, the file,
-- the visit, the loan, the booking and the rest all stay.
--
-- The first four were in this file from the start. The last five were added
-- on 12 October 2026, before the file was ever released, on the owner's
-- decision (Salem874): they were the five "who did it" columns an erasure
-- still could not clear, and only because the database forbade it.
--
-- The pastoral visit is the one that was argued about. Until now its number
-- was left in place, pointing at an account that had itself been emptied. So
-- it named nobody, and yet was still there: the worst of both. Whether the
-- NAME of whoever made a visit should be kept on safeguarding grounds is a
-- separate question that has NOT been decided; this change does not decide it.
--
-- -----------------------------------------------------------------------------
-- WHY THE LINKS TO tblUsers ARE LEFT ALONE
-- -----------------------------------------------------------------------------
-- Eight of the nine have a link to the account table that says "refuse to
-- delete an account while a row still points at it" (ON DELETE RESTRICT); the
-- ninth, the rota swap request, says "delete the request with the account"
-- (ON DELETE CASCADE). Either kind allows an empty value, and either only ever
-- acts when an account ROW is deleted, which an erasure never does (it empties
-- the account and keeps the row). Dropping and re-adding a link is more that
-- can go wrong, for no gain.
--
-- -----------------------------------------------------------------------------
-- WHY THE NEW COLUMN: tblErasureRequest.claimedAt
-- -----------------------------------------------------------------------------
-- An administrator presses Execute and the request moves to "processing". If
-- the web host then kills the page part way through, the request stays at
-- "processing" for ever: the portal will only run a request that is at
-- "pending review", and until now nothing could put it back.
--
-- To put one back safely the portal has to tell a run that has STOPPED from
-- one that is still GOING. `claimedAt` is the moment the run was started, and
-- the run refreshes it as it works. A request that has shown no sign of life
-- for a quarter of an hour can be put back.
--
-- IT IS A MOMENT IN UTC, NOT A TIME ON THE SERVER'S CLOCK. It is written with
-- UTC_TIMESTAMP() and only ever compared with UTC_TIMESTAMP(). A time on the
-- server's own clock would be an hour wrong on the two nights a year the
-- clocks change, and on the spring night that would let a request be put back
-- while its run was still going.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS DOES NOT DO
-- -----------------------------------------------------------------------------
-- It empties nothing. Every existing record keeps the number it has; this only
-- makes it POSSIBLE to remove one when somebody asks. `claimedAt` starts empty
-- on every existing request, and an empty value counts as "no run is going".
--
-- Safe to run twice: each change looks first to see whether it is still needed.
-- Written the portable way (look in information_schema, then PREPARE the
-- change), because MySQL 8 rejects the shorter "IF EXISTS" form that MariaDB
-- accepts.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- =============================================================================

-- #############################################################################
-- ✏️  A. Nine columns, each guarded so a replay does nothing
-- #############################################################################
-- The guard tests whether the column is still marked "must be filled in". Once
-- changed, every replay finds it already done and does nothing.

-- tblVisitorContact.contactedByID - who wrote a follow-up note about a visitor.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVisitorContact'
      AND COLUMN_NAME  = 'contactedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVisitorContact` MODIFY COLUMN `contactedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetResources.uploadedByID - who attached a file to a piece of equipment.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetResources'
      AND COLUMN_NAME  = 'uploadedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetResources` MODIFY COLUMN `uploadedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenueAgreementFiles.uploadedByID - who attached a file to a hire agreement.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenueAgreementFiles'
      AND COLUMN_NAME  = 'uploadedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenueAgreementFiles` MODIFY COLUMN `uploadedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblCareVisit.visitedByID - who made a pastoral visit.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblCareVisit'
      AND COLUMN_NAME  = 'visitedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblCareVisit` MODIFY COLUMN `visitedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetLicenseAssignments.linkedByID - who gave somebody a seat on a licence.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetLicenseAssignments'
      AND COLUMN_NAME  = 'linkedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetLicenseAssignments` MODIFY COLUMN `linkedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetLoans.requestedByID - who asked for a piece of equipment to be lent.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetLoans'
      AND COLUMN_NAME  = 'requestedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetLoans` MODIFY COLUMN `requestedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblOffboarding.offboardedByID - who processed somebody else's leaving.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblOffboarding'
      AND COLUMN_NAME  = 'offboardedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblOffboarding` MODIFY COLUMN `offboardedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblResourceBooking.bookedByID - who booked a room or a piece of equipment.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblResourceBooking'
      AND COLUMN_NAME  = 'bookedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblResourceBooking` MODIFY COLUMN `bookedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblRotaSwapRequest.requestedByID - who asked to swap a rota slot.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblRotaSwapRequest'
      AND COLUMN_NAME  = 'requestedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblRotaSwapRequest` MODIFY COLUMN `requestedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- ➕ B. tblErasureRequest.claimedAt, guarded on whether the column exists
-- #############################################################################

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblErasureRequest'
      AND COLUMN_NAME  = 'claimedAt'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblErasureRequest` ADD COLUMN `claimedAt` DATETIME DEFAULT NULL COMMENT ''A MOMENT IN UTC, not a time on the server clock: when a run of this request was started, refreshed while the run is still going. Written and compared with UTC_TIMESTAMP() only. Empty means no run is going.'' AFTER `processedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('208_erasure_keeps_others_records.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
