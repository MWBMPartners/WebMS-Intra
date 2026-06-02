-- =============================================================================
-- Migration 090: Livestream app (#273)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblLivestreamChannel` (
    `channelID`          INT          NOT NULL AUTO_INCREMENT,
    `siteID`             INT          NOT NULL DEFAULT 1,
    `name`               VARCHAR(255) NOT NULL,
    `platform`           ENUM('youtube','youtube-live','vimeo','twitch','facebook','custom') NOT NULL DEFAULT 'youtube',
    `channelOrVideoId`   VARCHAR(100) DEFAULT NULL,
    `embedHtmlOverride`  TEXT         DEFAULT NULL COMMENT 'Used when platform=custom',
    `isPrimary`          TINYINT(1)   NOT NULL DEFAULT 0,
    `createdAt`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`channelID`),
    KEY `idx_lsc_site` (`siteID`),
    CONSTRAINT `fk_lsc_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblLivestreamSchedule` (
    `scheduleID` INT          NOT NULL AUTO_INCREMENT,
    `channelID`  INT          NOT NULL,
    `dayOfWeek`  TINYINT      NOT NULL COMMENT '0=Sun, 6=Sat',
    `startTime`  TIME         NOT NULL,
    `endTime`    TIME         NOT NULL,
    `timezone`   VARCHAR(50)  NOT NULL DEFAULT 'Europe/London',
    `isActive`   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`scheduleID`),
    KEY `idx_lss_channel_dow` (`channelID`, `dayOfWeek`),
    CONSTRAINT `fk_lss_channel` FOREIGN KEY (`channelID`) REFERENCES `tblLivestreamChannel`(`channelID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('live',                'live/index.php',          1),
    ('admin/livestream',    'admin/livestream/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'livestream.enabled',     '0', '0', 0),
    (NULL, 'livestream.displayName', 'Livestream', 'Livestream', 0),
    (NULL, 'livestream.displayIcon', 'fa-solid fa-tower-broadcast', 'fa-solid fa-tower-broadcast', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
