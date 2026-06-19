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
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/347
-- =============================================================================

ALTER TABLE `tblEvents`
    ADD COLUMN IF NOT EXISTS `registrationEnabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `capacityCount`,
    ADD COLUMN IF NOT EXISTS `registrationOpensAt`  DATETIME DEFAULT NULL AFTER `registrationEnabled`,
    ADD COLUMN IF NOT EXISTS `registrationClosesAt` DATETIME DEFAULT NULL AFTER `registrationOpensAt`;

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
