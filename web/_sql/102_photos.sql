-- =============================================================================
-- Migration 102: Photo upload approval queue + tiered visibility (#236)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblPhotoAlbum` (
    `albumID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `name`        VARCHAR(255) NOT NULL,
    `slug`        VARCHAR(200) NOT NULL,
    `description` TEXT         DEFAULT NULL,
    `visibility`  ENUM('public','volunteers','staff','admin_only') NOT NULL DEFAULT 'staff',
    `coverPhotoID` INT         DEFAULT NULL,
    `createdByID` INT          DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`albumID`),
    UNIQUE KEY `uq_pa_site_slug` (`siteID`, `slug`),
    CONSTRAINT `fk_pa_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_pa_user` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblPhoto` (
    `photoID`           INT          NOT NULL AUTO_INCREMENT,
    `siteID`            INT          NOT NULL DEFAULT 1,
    `albumID`           INT          DEFAULT NULL,
    `uploadedByUserID`  INT          DEFAULT NULL,
    `filePath`          VARCHAR(255) NOT NULL COMMENT 'Relative to _uploads/photos/',
    `originalFilename`  VARCHAR(255) DEFAULT NULL,
    `mimeType`          VARCHAR(60)  DEFAULT NULL,
    `fileSize`          INT          DEFAULT NULL,
    `widthPx`           INT          DEFAULT NULL,
    `heightPx`          INT          DEFAULT NULL,
    `caption`           TEXT         DEFAULT NULL,
    `visibility`        ENUM('public','volunteers','staff','admin_only','inherit') NOT NULL DEFAULT 'inherit',
    `status`            ENUM('pending_approval','approved','rejected') NOT NULL DEFAULT 'pending_approval',
    `moderatedByID`     INT          DEFAULT NULL,
    `moderatedAt`       DATETIME     DEFAULT NULL,
    `rejectionReason`   VARCHAR(500) DEFAULT NULL,
    `takenAt`           DATETIME     DEFAULT NULL COMMENT 'From EXIF DateTimeOriginal',
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`photoID`),
    KEY `idx_ph_site_status` (`siteID`, `status`),
    KEY `idx_ph_album`       (`albumID`),
    KEY `idx_ph_uploader`    (`uploadedByUserID`),
    CONSTRAINT `fk_ph_site`     FOREIGN KEY (`siteID`)           REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_ph_album`    FOREIGN KEY (`albumID`)          REFERENCES `tblPhotoAlbum`(`albumID`) ON DELETE SET NULL,
    CONSTRAINT `fk_ph_uploader` FOREIGN KEY (`uploadedByUserID`) REFERENCES `tblUsers`(`userID`)       ON DELETE SET NULL,
    CONSTRAINT `fk_ph_moderator` FOREIGN KEY (`moderatedByID`)   REFERENCES `tblUsers`(`userID`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('photos',                'photos/index.php',          0),
    ('photos/album',          'photos/album.php',          0),
    ('photos/view',           'photos/view.php',           0),
    ('photos/serve',          'photos/serve.php',          0),
    ('photos/serve-raw',      'photos/serve-raw.php',      1),
    ('photos/upload',         'photos/upload.php',         1),
    ('admin/photos/queue',    'admin/photos/queue.php',    1),
    ('admin/photos/moderate', 'admin/photos/moderate.php', 1),
    ('admin/photos/albums',   'admin/photos/albums.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'photos.enabled',         '0', '0', 0),
    (NULL, 'photos.displayName',     'Photos', 'Photos', 0),
    (NULL, 'photos.displayIcon',     'fa-solid fa-images', 'fa-solid fa-images', 0),
    (NULL, 'photos.maxUploadMb',     '15', '15', 0),
    (NULL, 'photos.defaultVisibility','staff', 'staff', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
