-- =============================================================================
-- Migration 029: Announcements / Noticeboard
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/89
-- =============================================================================

-- 📋 Announcements table
CREATE TABLE IF NOT EXISTS `tblAnnouncements` (
    `announcementID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `title`          VARCHAR(255) NOT NULL,
    `slug`           VARCHAR(200) NOT NULL,
    `body`           TEXT         NOT NULL,
    `priority`       ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
    `isPinned`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Pinned to top of list and dashboard',
    `publishAt`      DATETIME     DEFAULT NULL COMMENT 'Scheduled publish time (NULL = immediate)',
    `expiresAt`      DATETIME     DEFAULT NULL COMMENT 'Auto-hide after this date (NULL = never)',
    `isPublished`    TINYINT(1)   NOT NULL DEFAULT 0,
    `isDeleted`      TINYINT(1)   NOT NULL DEFAULT 0,
    `createdByID`    INT          DEFAULT NULL,
    `updatedByID`    INT          DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcementID`),
    UNIQUE KEY `uq_announcement_slug` (`slug`, `siteID`),
    KEY `idx_announcement_site` (`siteID`),
    KEY `idx_announcement_published` (`siteID`, `isPublished`, `isDeleted`, `publishAt`),
    KEY `idx_announcement_pinned` (`siteID`, `isPinned`, `isPublished`, `isDeleted`),
    CONSTRAINT `fk_announcement_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_announcement_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_announcement_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Site announcements / noticeboard posts';

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements', 'announcements/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/view', 'announcements/view.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/manage', 'announcements/manage.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/save', 'announcements/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/delete', 'announcements/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 App settings (auto-discovered by nav & dashboard)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.displayName', 'Announcements', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.displayIcon', 'fa-solid fa-bullhorn', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.brandColor', '#fd7e14', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
