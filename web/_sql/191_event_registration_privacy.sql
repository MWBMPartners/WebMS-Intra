-- =============================================================================
-- Migration 191: children's registration details can now be found and removed
-- =============================================================================
-- Two columns and two settings. No new tables.
--
-- -----------------------------------------------------------------------------
-- THE PROBLEM, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- `tblEventRegistrations` is where somebody signs a child up for an event. It
-- holds the child's name, date of birth, allergies and medical notes, together
-- with a parent's name, telephone number and email address.
--
-- It holds no link to anybody's account at all.
--
-- That matters because of how a "delete everything you hold about me" request
-- actually works: the portal looks through its tables for rows belonging to
-- that person's account. A table with no such link cannot be searched that way.
-- So this table - by some distance the most sensitive in the whole portal -
-- was the one table an erasure request could never reach. Not because it had
-- been forgotten, but because there was nothing to match on.
--
-- Adding the table to the erasure list would not have fixed it. There was
-- nothing to look for.
--
-- -----------------------------------------------------------------------------
-- TWO CHANGES, BECAUSE ONE IS NOT ENOUGH
-- -----------------------------------------------------------------------------
--
-- 1. WHO SUBMITTED IT (`submittedByUserID`)
--
--    When somebody signs a child up while signed in, their account is now
--    recorded against the registration. An erasure request can then find it.
--
--    Deliberately allowed to be empty, and that is not an oversight. Anybody
--    can sign a child up without an account - which is the point of a public
--    registration form, and should stay possible. A parent must not have to
--    create an account to bring their child to a holiday club.
--
--    So this closes the gap for registrations made by people the portal knows,
--    and change 2 below covers the rest.
--
-- 2. A TIME LIMIT (`registrationRetentionDays`, plus a portal-wide default)
--
--    Nobody should have to ASK for a child's medical details to be removed
--    three years after a holiday club. Keeping them at all only makes sense
--    while the event is being run and for a short while afterwards - for a
--    late question, an insurance query, a follow-up.
--
--    After that they are simply deleted, whether anybody asks or not. This is
--    the change that covers registrations from people without an account,
--    which is most of them.
--
--    Two levels, because events genuinely differ:
--      * `events.registrationRetentionDays` - the portal-wide default, 90 days.
--      * `tblEvents.registrationRetentionDays` - for one event, when that event
--        needs something different. A residential trip may need longer for
--        insurance; a one-afternoon event may want far less.
--    The event's own number wins where it is set. Where it is empty, the
--    portal-wide one applies.
--
--    Zero means "keep indefinitely". It exists for the rare event that
--    genuinely needs it, and requires somebody to set it deliberately - it is
--    never the default, because "we kept a child's medical notes for ever"
--    should never be something that happens by accident.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS DOES NOT DO
-- -----------------------------------------------------------------------------
-- It does not touch registrations already in the database. Those have no
-- account recorded against them, because nothing was recording it at the time,
-- and there is no honest way to work out afterwards who submitted them. The
-- time limit will reach them in the ordinary way.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/479
-- =============================================================================

-- #############################################################################
-- ➕ A. Who submitted the registration, when the portal knew them
-- #############################################################################
-- Guarded so a replay does nothing. Written the portable way (checking
-- information_schema, then running the change only if needed) because MySQL 8
-- rejects the shorter "IF NOT EXISTS" form on ALTER that MariaDB accepts.
--
-- The AFTER clauses are not decoration. The installer builds a new database
-- from full_schema.sql, which places these columns in a particular position,
-- and then replays every numbered migration over it. Without AFTER, an
-- upgraded database would end up with the same columns in a different order
-- from a freshly installed one. Nothing breaks today, but comparing a
-- customer's database against the reference then shows differences that are
-- not really differences, which wastes time exactly when somebody is trying to
-- work out what is wrong. Both anchor columns (`source`, `registrationClosesAt`)
-- have existed since long before this migration, so they are always present.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRegistrations'
      AND COLUMN_NAME  = 'submittedByUserID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventRegistrations` ADD COLUMN `submittedByUserID` INT DEFAULT NULL COMMENT ''The account of whoever submitted this, when they were signed in. Empty is normal and allowed: anybody may register a child without an account.'' AFTER `source`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- An index, so an erasure request can find a person's registrations quickly
-- rather than reading the whole table.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventRegistrations'
      AND INDEX_NAME   = 'idx_registration_submitted_by'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventRegistrations` ADD KEY `idx_registration_submitted_by` (`submittedByUserID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- ➕ B. How long one event keeps its registrations
-- #############################################################################
-- Empty means "use the portal-wide setting". A number here wins over it.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'registrationRetentionDays'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `registrationRetentionDays` INT DEFAULT NULL COMMENT ''Days after this event ends before its registrations are deleted. Empty uses the portal-wide setting. 0 means keep indefinitely and must be set deliberately.'' AFTER `registrationClosesAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- ⚙️ C. The portal-wide default, and the switch that runs the clear-out
-- #############################################################################
-- 90 days: long enough for a late question, an insurance query or a follow-up,
-- short enough that a child's medical details are not sitting around for years.
--
-- The clear-out is seeded ON. A time limit nobody runs is a document, not a
-- protection - and this is the change that covers registrations made by people
-- without an account, which is most of them.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'events.registrationRetentionDays', '90',   '90',   0),
    (NULL, 'events.registrationRetentionRun',  'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 📋 D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('191_event_registration_privacy.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
