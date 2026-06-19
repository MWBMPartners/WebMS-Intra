-- Migration 136: Kid check-in / out (#298)
-- Children's ministry safeguarding: parents register their children with
-- allergies / photo consent / authorised pickup names; staff use a terminal
-- to check kids in and out; matched badge codes prevent unauthorised pickup.

CREATE TABLE IF NOT EXISTS `tblKidProfiles` (
    `childID`                INT NOT NULL AUTO_INCREMENT,
    `siteID`                 INT NOT NULL,
    `parentUserID`           INT DEFAULT NULL,
    `fullName`               VARCHAR(120) NOT NULL,
    `dateOfBirth`            DATE DEFAULT NULL,
    `allergies`              VARCHAR(500) DEFAULT NULL,
    `medicalNotes`           VARCHAR(1000) DEFAULT NULL,
    `photoConsent`           TINYINT(1) NOT NULL DEFAULT 0,
    `pickupAuthorisedNames`  VARCHAR(500) DEFAULT NULL COMMENT 'Comma-separated names allowed to collect',
    `isActive`               TINYINT(1) NOT NULL DEFAULT 1,
    `createdAt`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`childID`),
    KEY `idx_kid_site_active` (`siteID`, `isActive`),
    KEY `idx_kid_parent`      (`parentUserID`),
    CONSTRAINT `fk_kid_site`   FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_kid_parent` FOREIGN KEY (`parentUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblKidCheckins` (
    `checkinID`         INT NOT NULL AUTO_INCREMENT,
    `childID`           INT NOT NULL,
    `eventID`           INT DEFAULT NULL,
    `badgeCode`         CHAR(6) NOT NULL COMMENT 'Numeric code shown on parent + child badge',
    `checkedInAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checkedInByID`     INT DEFAULT NULL,
    `checkedOutAt`      DATETIME DEFAULT NULL,
    `checkedOutByID`    INT DEFAULT NULL,
    `pickupName`        VARCHAR(120) DEFAULT NULL,
    PRIMARY KEY (`checkinID`),
    KEY `idx_kc_child`     (`childID`, `checkedInAt`),
    KEY `idx_kc_open`      (`checkedOutAt`),
    CONSTRAINT `fk_kc_child` FOREIGN KEY (`childID`) REFERENCES `tblKidProfiles`(`childID`) ON DELETE CASCADE,
    CONSTRAINT `fk_kc_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('kids/profiles',       'kids/profiles.php',       1),
    ('kids/profiles/save',  'kids/profiles-save.php',  1),
    ('kids/checkin',        'kids/checkin.php',        1),
    ('kids/checkin/do',     'kids/checkin-do.php',     1),
    ('kids/checkout',       'kids/checkout.php',       1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
