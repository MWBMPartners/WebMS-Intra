-- =============================================================================
-- Migration 094: Giving / contributions log (#266)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblGivingCategory` (
    `categoryID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `name`        VARCHAR(255) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `defaultFund` VARCHAR(100) DEFAULT NULL COMMENT 'Optional accounting code',
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`categoryID`),
    KEY `idx_gc_site` (`siteID`),
    CONSTRAINT `fk_gc_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblGivingEntry` (
    `entryID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `donorID`      INT          DEFAULT NULL COMMENT 'FK → tblUsers; NULL for anonymous',
    `donorName`    VARCHAR(255) DEFAULT NULL COMMENT 'Free-text name used when donorID is NULL',
    `categoryID`   INT          NOT NULL,
    `amountPence`  INT          NOT NULL COMMENT 'Stored in minor units to avoid float drift',
    `currency`     CHAR(3)      NOT NULL DEFAULT 'GBP',
    `donatedAt`    DATE         NOT NULL,
    `method`       ENUM('cash','cheque','bank-transfer','card','standing-order','other') NOT NULL DEFAULT 'cash',
    `reference`    VARCHAR(100) DEFAULT NULL,
    `notes`        TEXT         DEFAULT NULL,
    `recordedByID` INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`entryID`),
    KEY `idx_ge_site_date`     (`siteID`, `donatedAt`),
    KEY `idx_ge_donor_date`    (`donorID`, `donatedAt`),
    KEY `idx_ge_category_date` (`categoryID`, `donatedAt`),
    CONSTRAINT `fk_ge_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_ge_donor`    FOREIGN KEY (`donorID`)      REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL,
    CONSTRAINT `fk_ge_category` FOREIGN KEY (`categoryID`)   REFERENCES `tblGivingCategory`(`categoryID`),
    CONSTRAINT `fk_ge_recorder` FOREIGN KEY (`recordedByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblGiftAidDeclaration` (
    `declarationID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `donorID`       INT          NOT NULL,
    `status`        ENUM('active','lapsed','withdrawn') NOT NULL DEFAULT 'active',
    `validFrom`     DATE         NOT NULL,
    `validTo`       DATE         DEFAULT NULL,
    `address`       VARCHAR(500) DEFAULT NULL COMMENT 'Donor address at time of declaration (HMRC requirement)',
    `postcode`      VARCHAR(20)  DEFAULT NULL,
    `acceptedAt`    DATETIME     DEFAULT NULL COMMENT 'Digital acceptance timestamp',
    `acceptedIP`    VARCHAR(45)  DEFAULT NULL,
    `signaturePath` VARCHAR(255) DEFAULT NULL COMMENT 'Path under _uploads/giving/ for uploaded signature image',
    `notes`         TEXT         DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`declarationID`),
    KEY `idx_gad_site_donor` (`siteID`, `donorID`),
    CONSTRAINT `fk_gad_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_gad_donor` FOREIGN KEY (`donorID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('giving',              'giving/index.php',         1),
    ('giving/manage',       'giving/manage.php',        1),
    ('giving/entry-save',   'giving/entry-save.php',    1),
    ('giving/entry-delete', 'giving/entry-delete.php',  1),
    ('giving/categories',   'giving/categories.php',    1),
    ('giving/cat-save',     'giving/cat-save.php',      1),
    ('giving/gift-aid',     'giving/gift-aid.php',      1),
    ('giving/gad-save',     'giving/gad-save.php',      1),
    ('giving/my-statement', 'giving/my-statement.php',  1),
    ('giving/reports',      'giving/reports.php',       1),
    ('giving/hmrc-export',  'giving/hmrc-export.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'giving.enabled',     '0', '0', 0),
    (NULL, 'giving.displayName', 'Giving', 'Giving', 0),
    (NULL, 'giving.displayIcon', 'fa-solid fa-hand-holding-dollar', 'fa-solid fa-hand-holding-dollar', 0),
    (NULL, 'giving.currency',    'GBP', 'GBP', 0),
    (NULL, 'giving.charityName', '', '', 0),
    (NULL, 'giving.charityNumber','', '', 0),
    (NULL, 'giving.hmrcRef',     '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
