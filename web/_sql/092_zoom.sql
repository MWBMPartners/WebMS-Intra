-- =============================================================================
-- Migration 092: Zoom integration (#274)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblZoomAccount` (
    `accountID`            INT          NOT NULL AUTO_INCREMENT,
    `siteID`               INT          NOT NULL DEFAULT 1,
    `userID`               INT          DEFAULT NULL COMMENT 'NULL = org-level account',
    `zoomUserId`           VARCHAR(100) NOT NULL,
    `zoomAccountEmail`     VARCHAR(255) DEFAULT NULL,
    `refreshTokenEnc`      TEXT         NOT NULL COMMENT 'Encrypted via libsodium',
    `accessTokenEnc`       TEXT         DEFAULT NULL,
    `accessTokenExpiresAt` DATETIME     DEFAULT NULL,
    `scopes`               VARCHAR(500) DEFAULT NULL,
    `createdAt`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`accountID`),
    UNIQUE KEY `uq_za_site_user` (`siteID`, `userID`),
    CONSTRAINT `fk_za_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_za_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblZoomMeeting` (
    `meetingID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `eventID`        INT          DEFAULT NULL,
    `accountID`      INT          NOT NULL,
    `zoomMeetingId`  VARCHAR(50)  NOT NULL,
    `joinUrl`        VARCHAR(500) NOT NULL,
    `startUrl`       VARCHAR(1000) DEFAULT NULL,
    `passcode`       VARCHAR(50)  DEFAULT NULL,
    `topic`          VARCHAR(255) DEFAULT NULL,
    `isRecurring`    TINYINT(1)   NOT NULL DEFAULT 0,
    `recordingUrl`   VARCHAR(500) DEFAULT NULL COMMENT 'Filled by webhook when recording ready',
    `createdByID`    INT          DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`meetingID`),
    KEY `idx_zm_event`   (`eventID`),
    KEY `idx_zm_zoom_id` (`zoomMeetingId`),
    CONSTRAINT `fk_zm_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_zm_account` FOREIGN KEY (`accountID`)   REFERENCES `tblZoomAccount`(`accountID`) ON DELETE CASCADE,
    CONSTRAINT `fk_zm_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/zoom',            'admin/integrations/zoom/index.php',      1),
    ('admin/integrations/zoom/connect',    'admin/integrations/zoom/connect.php',    1),
    ('admin/integrations/zoom/callback',   'admin/integrations/zoom/callback.php',   1),
    ('admin/integrations/zoom/disconnect', 'admin/integrations/zoom/disconnect.php', 1),
    ('admin/integrations/zoom/save',       'admin/integrations/zoom/save.php',       1),
    ('admin/integrations/zoom/webhook',    'admin/integrations/zoom/webhook.php',    0),
    ('account/integrations/zoom',          'account/integrations/zoom.php',          1),
    ('calendar/zoom-create',               'calendar/zoom-create.php',               1),
    ('calendar/zoom-remove',               'calendar/zoom-remove.php',               1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'zoom.enabled',         '0', '0', 0),
    (NULL, 'zoom.displayName',     'Zoom', 'Zoom', 0),
    (NULL, 'zoom.displayIcon',     'fa-solid fa-video', 'fa-solid fa-video', 0),
    (NULL, 'zoom.mode',            'org', 'org', 0),
    (NULL, 'zoom.clientID',        '', '', 0),
    (NULL, 'zoom.clientSecret',    '', '', 1),
    (NULL, 'zoom.webhookSecret',   '', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
