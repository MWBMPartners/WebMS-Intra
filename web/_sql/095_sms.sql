-- =============================================================================
-- Migration 095: SMS notifications (#272)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblSmsMessage` (
    `messageID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `recipientUserID`  INT          DEFAULT NULL,
    `recipientNumber`  VARCHAR(20)  NOT NULL,
    `body`             VARCHAR(800) NOT NULL,
    `category`         VARCHAR(50)  NOT NULL DEFAULT 'general',
    `status`           ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
    `provider`         VARCHAR(30)  NOT NULL DEFAULT 'twilio',
    `providerRef`      VARCHAR(100) DEFAULT NULL,
    `costPence`        INT          DEFAULT NULL,
    `errorMsg`         VARCHAR(255) DEFAULT NULL,
    `sentAt`           DATETIME     DEFAULT NULL,
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`messageID`),
    KEY `idx_sms_site_date` (`siteID`, `createdAt`),
    KEY `idx_sms_user`      (`recipientUserID`),
    CONSTRAINT `fk_sms_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sms_user` FOREIGN KEY (`recipientUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblUserSmsPreference` (
    `preferenceID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`              INT          NOT NULL DEFAULT 1,
    `userID`              INT          NOT NULL,
    `phoneNumber`         VARCHAR(20)  NOT NULL,
    `isVerified`          TINYINT(1)   NOT NULL DEFAULT 0,
    `verificationCode`    VARCHAR(10)  DEFAULT NULL,
    `verificationExpires` DATETIME     DEFAULT NULL,
    `categories`          VARCHAR(255) NOT NULL DEFAULT 'critical_alerts' COMMENT 'CSV: critical_alerts,rota_changes,emergency_comms,newsletter_digest',
    `updatedAt`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`preferenceID`),
    UNIQUE KEY `uq_sp_site_user` (`siteID`, `userID`),
    CONSTRAINT `fk_sp_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sp_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/sms',          'admin/sms/index.php',  1),
    ('admin/sms/save',     'admin/sms/save.php',   1),
    ('admin/sms/send',     'admin/sms/send.php',   1),
    ('account/sms',        'account/sms.php',      1),
    ('account/sms/verify', 'account/sms-verify.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'sms.enabled',          '0', '0', 0),
    (NULL, 'sms.displayName',      'SMS', 'SMS', 0),
    (NULL, 'sms.displayIcon',      'fa-solid fa-comment-sms', 'fa-solid fa-comment-sms', 0),
    (NULL, 'sms.provider',         'twilio', 'twilio', 0),
    (NULL, 'sms.dailyCap',         '100', '100', 0),
    (NULL, 'sms.fromNumber',       '', '', 0),
    (NULL, 'sms.twilio.sid',       '', '', 0),
    (NULL, 'sms.twilio.token',     '', '', 1),
    (NULL, 'sms.messagebird.apiKey','', '', 1),
    (NULL, 'sms.aws.accessKey',    '', '', 0),
    (NULL, 'sms.aws.secret',       '', '', 1),
    (NULL, 'sms.aws.region',       'eu-west-1', 'eu-west-1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
