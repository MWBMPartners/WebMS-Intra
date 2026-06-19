-- =============================================================================
-- Migration 125: DBS safeguarding tracking (#310)
-- =============================================================================
-- UK Disclosure and Barring Service checks for adults working with children
-- or vulnerable adults. Tracks: holder, check type, reference number, issue
-- date, expiry. Optional gate: when safeguarding.dbs_required_for_coordinators
-- is "1", Auth::isCoordinatorOf returns false for users without a valid
-- (non-expired, non-revoked) DBS row.
--
-- Reference numbers + sensitive fields are stored in plain DB columns (no
-- encryption in v1) — the table itself is admin-only and access goes through
-- the admin UI which logs every view. Encryption at rest is v1.1 work.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/310
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblDbsChecks` (
    `dbsCheckID`     INT          NOT NULL AUTO_INCREMENT,
    `userID`         INT          NOT NULL,
    `dbsType`        ENUM('basic','standard','enhanced','enhanced-barred') NOT NULL,
    `referenceNumber` VARCHAR(60) DEFAULT NULL COMMENT 'DBS certificate reference',
    `issuedDate`     DATE         NOT NULL,
    `expiresAt`      DATE         NOT NULL COMMENT 'Most orgs renew every 3 years',
    `status`         ENUM('valid','expired','revoked') NOT NULL DEFAULT 'valid',
    `recordedByID`   INT          DEFAULT NULL,
    `recordedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`          VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`dbsCheckID`),
    KEY `idx_dbs_user_status`   (`userID`, `status`, `expiresAt`),
    KEY `idx_dbs_expiring_soon` (`expiresAt`, `status`),
    CONSTRAINT `fk_dbs_user`     FOREIGN KEY (`userID`)       REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_dbs_recorder` FOREIGN KEY (`recordedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isEncrypted`) VALUES
    ('safeguarding.dbs_required_for_coordinators', '0', 0),
    ('safeguarding.dbs_renewal_warning_days',      '90', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/safeguarding/dbs',      'admin/safeguarding/dbs.php',      1),
    ('admin/safeguarding/dbs/save', 'admin/safeguarding/dbs-save.php', 1),
    ('account/safeguarding',        'account/safeguarding.php',        1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
