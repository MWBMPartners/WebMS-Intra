-- =============================================================================
-- Migration 030: File / Document Library
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/90
-- =============================================================================

-- 📁 Document categories (folders)
CREATE TABLE IF NOT EXISTS `tblDocCategories` (
    `categoryID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `categoryName` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`categoryID`),
    UNIQUE KEY `uq_doc_cat_name_site` (`categoryName`, `siteID`),
    KEY `idx_doc_cat_site` (`siteID`),
    CONSTRAINT `fk_doccat_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Document library categories / folders';

-- 📄 Documents table
CREATE TABLE IF NOT EXISTS `tblDocuments` (
    `documentID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `categoryID`   INT          DEFAULT NULL,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `fileName`     VARCHAR(255) NOT NULL COMMENT 'Original upload filename',
    `filePath`     VARCHAR(500) NOT NULL COMMENT 'Path relative to _uploads/documents/',
    `fileSize`     INT          NOT NULL DEFAULT 0 COMMENT 'Size in bytes',
    `mimeType`     VARCHAR(100) DEFAULT NULL,
    `isPublished`  TINYINT(1)   NOT NULL DEFAULT 1,
    `isDeleted`    TINYINT(1)   NOT NULL DEFAULT 0,
    `downloadCount` INT         NOT NULL DEFAULT 0,
    `uploadedByID` INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`documentID`),
    KEY `idx_doc_site` (`siteID`),
    KEY `idx_doc_category` (`categoryID`),
    KEY `idx_doc_published` (`siteID`, `isPublished`, `isDeleted`),
    CONSTRAINT `fk_doc_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_doc_category` FOREIGN KEY (`categoryID`) REFERENCES `tblDocCategories` (`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_doc_uploader` FOREIGN KEY (`uploadedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Document library files';

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents', 'documents/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/upload', 'documents/upload.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/download', 'documents/download.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/delete', 'documents/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/categories', 'documents/categories.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 App settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.displayName', 'Documents', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.displayIcon', 'fa-solid fa-folder-open', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.brandColor', '#6f42c1', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.maxFileSize', '10485760', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
