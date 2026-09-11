-- =============================================================================
-- Migration 192: let a departed person's name actually be removed
-- =============================================================================
-- Thirteen columns change from "must always be filled in" to "may be empty".
-- No new tables, no new columns, no data moved.
--
-- -----------------------------------------------------------------------------
-- THE PROBLEM, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- When somebody asks to be forgotten, most records they merely CREATED are kept
-- and their name is removed from them. A venue, an invoice, an asset, an
-- invitation - these belong to the organisation. Deleting them because the
-- volunteer who typed them in has left would destroy the organisation's own
-- work, and in several cases its financial records.
--
-- So the intended behaviour is: keep the record, empty the column naming the
-- person.
--
-- Except that on these thirteen columns the database says the column must
-- always be filled in. Emptying one is refused outright.
--
-- That is not a tidy failure. It happens PART WAY THROUGH a request, after some
-- of the person's information has already been removed. They are left with some
-- of it gone, some of it still there, and no clear record of which was which. A
-- half-finished erasure is worse than one that never started.
--
-- -----------------------------------------------------------------------------
-- WHY "MUST ALWAYS BE FILLED IN" WAS NEVER RIGHT HERE
-- -----------------------------------------------------------------------------
-- It reads as a safety measure - every record should say who made it. But it
-- cannot hold. People leave, and the law gives them the right to have their
-- name removed. A rule that "every record must always name somebody" and a
-- right to "stop naming me" cannot both be satisfied. One of them has to give,
-- and it is not going to be the law.
--
-- Empty simply means "we no longer record who did this". Every one of these
-- columns already points at the user table through a link that allows empty, so
-- nothing about that relationship changes.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS DOES NOT DO
-- -----------------------------------------------------------------------------
-- It does not empty anything. Every existing record keeps the name it has. This
-- only makes it POSSIBLE to remove one when somebody asks.
--
-- The code protects itself either way: GdprEraser now asks the database which
-- columns it is allowed to empty and skips the rest, recording in the audit
-- trail exactly what it could not do. So an older database that has not had
-- this migration will no longer break - it will simply leave those links in
-- place and say so.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/479
-- =============================================================================

-- #############################################################################
-- ✏️  Thirteen columns, each guarded so a replay does nothing
-- #############################################################################
-- Written the portable way - look in information_schema first, then make the
-- change only if it is still needed - because MySQL 8 rejects the shorter
-- "IF EXISTS" form on ALTER that MariaDB accepts.
--
-- The guard tests whether the column is still marked as "must be filled in".
-- Once changed, every replay finds it already done and does nothing.

-- tblAssetEventAssignments.assignedByID - who assigned equipment to an event.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetEventAssignments'
      AND COLUMN_NAME  = 'assignedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetEventAssignments` MODIFY COLUMN `assignedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetIdentifiers.createdByID - who added a barcode or tag.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetIdentifiers'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetIdentifiers` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetStocktakes.startedByID - who started a stocktake.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetStocktakes'
      AND COLUMN_NAME  = 'startedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetStocktakes` MODIFY COLUMN `startedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetKioskTokens.createdByID - who registered a kiosk device.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetKioskTokens'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetKioskTokens` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssetMaintenance.createdByID - who logged a repair.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetMaintenance'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssetMaintenance` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblAssets.createdByID - who added an item to the asset register.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssets'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblAssets` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblInvitation.createdByID - who sent an invitation.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblInvitation'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblInvitation` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenueAgreements.createdByID - who recorded a hire agreement.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenueAgreements'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenueAgreements` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenueBookings.createdByID - who entered a booking.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenueBookings'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenueBookings` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenueBookingGroups.createdByID - who set up a repeating booking.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenueBookingGroups'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenueBookingGroups` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenueInvoicePayments.recordedByID - who recorded a payment.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenueInvoicePayments'
      AND COLUMN_NAME  = 'recordedByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenueInvoicePayments` MODIFY COLUMN `recordedByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenueInvoices.createdByID - who raised an invoice.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenueInvoices'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenueInvoices` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblVenues.createdByID - who added a venue.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblVenues'
      AND COLUMN_NAME  = 'createdByID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblVenues` MODIFY COLUMN `createdByID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblExpenseClaimApprovals.userID - who approved an expense claim.
--    Named like a subject but meaning an actor; see the written list's note.
SET @needs_change := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaimApprovals'
      AND COLUMN_NAME  = 'userID'
      AND IS_NULLABLE  = 'NO'
);
SET @sql := IF(@needs_change = 1,
    'ALTER TABLE `tblExpenseClaimApprovals` MODIFY COLUMN `userID` INT DEFAULT NULL COMMENT ''Empty once this person has asked to be forgotten. The record is kept; only the name is removed.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- Self-record (the installer replays every numbered migration after
-- full_schema.sql and ignores tblMigrations, so this has to be safe to run a
-- second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('192_removable_creator_links.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
