-- =============================================================================
-- Migration 093: Newsletter (#269)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblNewsletter` (
    `newsletterID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `title`         VARCHAR(255) NOT NULL,
    `slug`          VARCHAR(200) DEFAULT NULL,
    `subject`       VARCHAR(255) DEFAULT NULL,
    `segmentID`     INT          DEFAULT NULL,
    `status`        ENUM('draft','scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
    `scheduledFor`  DATETIME     DEFAULT NULL,
    `sentAt`        DATETIME     DEFAULT NULL,
    `sentCount`     INT          NOT NULL DEFAULT 0,
    `createdByID`   INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`newsletterID`),
    KEY `idx_nl_site_status` (`siteID`, `status`),
    CONSTRAINT `fk_nl_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_nl_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblNewsletterContent` (
    `contentID`     INT          NOT NULL AUTO_INCREMENT,
    `newsletterID`  INT          NOT NULL,
    `blockType`     ENUM('text','image','heading','divider','cta','announcements','events','prayers','sermon') NOT NULL DEFAULT 'text',
    `position`      INT          NOT NULL DEFAULT 0,
    `payload`       TEXT         DEFAULT NULL COMMENT 'JSON: type-specific config (text, url, count, etc.)',
    PRIMARY KEY (`contentID`),
    KEY `idx_nlc_newsletter_pos` (`newsletterID`, `position`),
    CONSTRAINT `fk_nlc_newsletter` FOREIGN KEY (`newsletterID`) REFERENCES `tblNewsletter`(`newsletterID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblNewsletterSegment` (
    `segmentID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`     INT          NOT NULL DEFAULT 1,
    `name`       VARCHAR(255) NOT NULL,
    `ruleJson`   TEXT         DEFAULT NULL COMMENT 'JSON: e.g. {"roles":["volunteer"]} or {"all":true}',
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`segmentID`),
    KEY `idx_ns_site` (`siteID`),
    CONSTRAINT `fk_ns_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblNewsletterRecipient` (
    `recipientID`   INT      NOT NULL AUTO_INCREMENT,
    `newsletterID`  INT      NOT NULL,
    `userID`        INT      NOT NULL,
    `emailAddress`  VARCHAR(255) NOT NULL,
    `unsubToken`    CHAR(40) NOT NULL,
    `deliveredAt`   DATETIME DEFAULT NULL,
    `openedAt`      DATETIME DEFAULT NULL,
    `clickedAt`     DATETIME DEFAULT NULL,
    `errorMsg`      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`recipientID`),
    UNIQUE KEY `uq_nlr_token` (`unsubToken`),
    KEY `idx_nlr_newsletter` (`newsletterID`),
    KEY `idx_nlr_user` (`userID`),
    CONSTRAINT `fk_nlr_newsletter` FOREIGN KEY (`newsletterID`) REFERENCES `tblNewsletter`(`newsletterID`) ON DELETE CASCADE,
    CONSTRAINT `fk_nlr_user`       FOREIGN KEY (`userID`)       REFERENCES `tblUsers`(`userID`)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblNewsletterSubscription` (
    `subscriptionID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `userID`         INT          NOT NULL,
    `optedIn`        TINYINT(1)   NOT NULL DEFAULT 1,
    `unsubToken`     CHAR(40)     NOT NULL,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`subscriptionID`),
    UNIQUE KEY `uq_nls_site_user` (`siteID`, `userID`),
    UNIQUE KEY `uq_nls_token`     (`unsubToken`),
    CONSTRAINT `fk_nls_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_nls_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('newsletter',                'newsletter/index.php',             1),
    ('newsletter/new',            'newsletter/edit.php',              1),
    ('newsletter/edit',           'newsletter/edit.php',              1),
    ('newsletter/save',           'newsletter/save.php',              1),
    ('newsletter/block-save',     'newsletter/block-save.php',        1),
    ('newsletter/block-delete',   'newsletter/block-delete.php',      1),
    ('newsletter/preview',        'newsletter/preview.php',           1),
    ('newsletter/recipients',     'newsletter/recipients.php',        1),
    ('newsletter/send',           'newsletter/send.php',              1),
    ('newsletter/segments',       'newsletter/segments.php',          1),
    ('newsletter/segments/save',  'newsletter/segments-save.php',     1),
    ('newsletter/track/open',     'newsletter/track-open.php',        0),
    ('newsletter/track/click',    'newsletter/track-click.php',       0),
    ('account/notifications',     'account/notifications.php',        1),
    ('unsubscribe',               'newsletter/unsubscribe.php',       0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'newsletter.enabled',         '0', '0', 0),
    (NULL, 'newsletter.displayName',     'Newsletter', 'Newsletter', 0),
    (NULL, 'newsletter.displayIcon',     'fa-solid fa-envelope-open-text', 'fa-solid fa-envelope-open-text', 0),
    (NULL, 'newsletter.provider',        'internal', 'internal', 0),
    (NULL, 'newsletter.fromName',        '', '', 0),
    (NULL, 'newsletter.fromAddress',     '', '', 0),
    (NULL, 'newsletter.trackOpens',      '0', '0', 0),
    (NULL, 'newsletter.trackClicks',     '0', '0', 0),
    (NULL, 'newsletter.batchPerHour',    '100', '100', 0),
    (NULL, 'newsletter.mailermatt.apiKey',  '', '', 1),
    (NULL, 'newsletter.mailermatt.baseUrl', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
