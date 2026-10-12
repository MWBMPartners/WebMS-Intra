-- =============================================================================
-- Migration 124: Per-event registration with VBS-relevant fields (#347)
-- =============================================================================
-- MVP ships a HARD-CODED set of VBS-relevant fields (the 80/20 of what
-- VBS Pro Premium asks for). v1.1 turns this into a true dynamic form
-- builder backed by tblEventRegistrationFields. The data model below
-- keeps each field as a real column so reporting / CSV export / queries
-- are trivial; the form-builder layer in v1.1 will add a sidecar
-- tblEventRegistrationExtras JSON column for arbitrary fields.
--
-- CHANGED AFTER RELEASE, on 12 October 2026 (#555). Read this before "tidying".
--   What was wrong: the first block below places `registrationEnabled` AFTER
--   `capacityCount`. No migration has ever added `capacityCount`: it was
--   written straight into the install script on 25 June 2026. So on any
--   database installed before that date the upgrade stopped right here with
--   "ERROR 1054: Unknown column 'capacityCount' in 'tblEvents'", and none of
--   the migrations after this one ever ran.
--   The correction: one new block, first, that adds `capacityCount` when it is
--   missing.
--   Why this file was changed instead of adding a new migration: the upgrade
--   stops at THIS file, so a later migration would never be reached.
--   Why that is safe: on a database where the old version of this file ran,
--   `capacityCount` was already there (the old version cannot succeed
--   without it), so the new block does nothing. The Migrator tells
--   migrations apart by file name only and keeps no fingerprint of their
--   contents (web/_core/Migrator.php), so nothing objects to the change. A
--   database that stopped here never recorded this file, so it runs the
--   corrected one on its next upgrade.
--   Proved by phase 6 of tools/e2e-migrations/run.sh, which upgrades a
--   database built from the 6 March 2026 install script.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/347
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/555
-- =============================================================================

-- ➕ tblEvents.capacityCount — added only when it is missing (#555, see the
-- header). The definition is the install script's own. No AFTER on purpose: no
-- earlier column is guaranteed to be there on an old database. So on such a
-- database the column sits at the end of the table and not where a fresh
-- install has it; the migration test does not compare the order of columns.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'capacityCount'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `capacityCount` INT DEFAULT NULL COMMENT ''NULL = unlimited''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.registrationEnabled — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'registrationEnabled'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `registrationEnabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `capacityCount`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.registrationOpensAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'registrationOpensAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `registrationOpensAt` DATETIME DEFAULT NULL AFTER `registrationEnabled`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.registrationClosesAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'registrationClosesAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `registrationClosesAt` DATETIME DEFAULT NULL AFTER `registrationOpensAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `tblEventRegistrations` (
    `registrationID`         INT          NOT NULL AUTO_INCREMENT,
    `eventID`                INT          NOT NULL,
    -- Participant
    `fullName`               VARCHAR(120) NOT NULL,
    `dateOfBirth`            DATE         DEFAULT NULL,
    `grade`                  VARCHAR(10)  DEFAULT NULL COMMENT 'P / K / 1 / 2 / ... / 12 / Y13',
    `gender`                 ENUM('male','female','other','prefer-not') DEFAULT NULL,
    `shirtSize`              ENUM('YS','YM','YL','XS','S','M','L','XL','XXL') DEFAULT NULL,
    -- Health / safety
    `allergies`              VARCHAR(500) DEFAULT NULL,
    `medicalNotes`           VARCHAR(1000) DEFAULT NULL,
    -- Parent / guardian (where applicable)
    `parentName`             VARCHAR(120) DEFAULT NULL,
    `parentPhone`            VARCHAR(40)  DEFAULT NULL,
    `parentEmail`            VARCHAR(255) DEFAULT NULL,
    -- Consent + emergency
    `photoConsent`           TINYINT(1)   NOT NULL DEFAULT 0,
    `emergencyContactName`   VARCHAR(120) DEFAULT NULL,
    `emergencyContactPhone`  VARCHAR(40)  DEFAULT NULL,
    -- Lifecycle
    `status`                 ENUM('pending','approved','rejected','waitlisted') NOT NULL DEFAULT 'pending',
    `notes`                  VARCHAR(500) DEFAULT NULL COMMENT 'Internal moderation notes',
    `source`                 VARCHAR(40)  NOT NULL DEFAULT 'public-form',
    `createdAt`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewedByID`           INT          DEFAULT NULL,
    `reviewedAt`             DATETIME     DEFAULT NULL,
    PRIMARY KEY (`registrationID`),
    KEY `idx_reg_event_status` (`eventID`, `status`, `createdAt`),
    KEY `idx_reg_parent_email` (`parentEmail`),
    CONSTRAINT `fk_reg_event`    FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_reg_reviewer` FOREIGN KEY (`reviewedByID`) REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Public registration form (no login).
    ('calendar/event/register',          'calendar/event-register.php',          0),
    ('calendar/event/register/save',     'calendar/event-register-save.php',     0),
    -- Coordinator/admin moderation list.
    ('admin/calendar/registrations',     'admin/calendar/registrations.php',     1),
    ('admin/calendar/registrations/act', 'admin/calendar/registrations-act.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
