-- =============================================================================
-- Migration 145: Community Noticeboard — poster wall (#360)
-- =============================================================================
-- Mirrors the conventions in 029_announcements.sql. Distinct from the
-- announcements app: visual poster wall (Canva embeds, media, weekday
-- recurrence, colour/aspect/serif styling, QR share).
--
--   App route:   /noticeboard            (page, protected)
--   API:         /api/noticeboard/list   (GET  — any logged-in user)
--                /api/noticeboard/save   (POST — site admins only)
--                /api/noticeboard/qr     (GET  — QR PNG/SVG via Portal\Core\Qr)
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/360
-- =============================================================================

-- 📋 Posters table
CREATE TABLE IF NOT EXISTS `tblNoticeboardPosters` (
    `posterID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `title`       VARCHAR(255) NOT NULL,
    `kicker`      VARCHAR(120) NOT NULL DEFAULT '',
    `category`    VARCHAR(40)  NOT NULL DEFAULT 'Other',
    `scheduleType` ENUM('once','weekly') NOT NULL DEFAULT 'once',
    `eventDate`   DATE         DEFAULT NULL COMMENT 'For scheduleType=once',
    `weekday`     TINYINT      DEFAULT NULL COMMENT '0=Sun … 6=Sat, for scheduleType=weekly',
    `eventTime`   TIME         DEFAULT NULL,
    `location`    VARCHAR(255) NOT NULL DEFAULT '',
    `link`        VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Official event page (opened on second tap)',
    `mediaType`   ENUM('text','image','video','canva') NOT NULL DEFAULT 'text',
    `mediaUrl`    VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Image/video URL (mediaType image|video)',
    `canvaUrl`    VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Canva embed URL (mediaType canva)',
    `thumbUrl`    VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'Optional board thumbnail for canva posters',
    `colorIndex`  TINYINT      NOT NULL DEFAULT 0,
    `aspect`      VARCHAR(12)  NOT NULL DEFAULT '4/5',
    `useSerif`    TINYINT(1)   NOT NULL DEFAULT 0,
    `sortOrder`   INT          NOT NULL DEFAULT 0 COMMENT 'Manual override; board otherwise sorts chronologically',
    `isDeleted`   TINYINT(1)   NOT NULL DEFAULT 0,
    `createdByID` INT          DEFAULT NULL,
    `updatedByID` INT          DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`posterID`),
    KEY `idx_poster_site` (`siteID`, `isDeleted`),
    KEY `idx_poster_date` (`siteID`, `isDeleted`, `eventDate`),
    CONSTRAINT `fk_poster_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_poster_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_poster_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Noticeboard (pinboard) posters';

-- 📋 Routes (page + API handlers). API files live at _apps/noticeboard/api/*.php
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('noticeboard', 'noticeboard/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 App settings (auto-discovered by nav & dashboard)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('noticeboard.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('noticeboard.displayName', 'Noticeboard', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('noticeboard.displayIcon', 'fa-solid fa-thumbtack', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('noticeboard.brandColor', '#caa063', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📋 Enable the API endpoints (ApiRouter checks api.{app}.{action}.enabled)
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('api.noticeboard.list.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('api.noticeboard.save.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('api.noticeboard.qr.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
