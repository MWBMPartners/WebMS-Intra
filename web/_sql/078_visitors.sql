-- =============================================================================
-- Migration 078: Visitor Tracking app (#258)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblVisitor` (
    `visitorID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `fullName`         VARCHAR(255) NOT NULL,
    `email`            VARCHAR(255) DEFAULT NULL,
    `phone`            VARCHAR(50)  DEFAULT NULL,
    `firstVisitedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source`           ENUM('in-person','public-form','referral','website','other') NOT NULL DEFAULT 'in-person',
    `assignedToID`     INT          DEFAULT NULL,
    `status`           ENUM('new','in-touch','converted','lost') NOT NULL DEFAULT 'new',
    `notes`            TEXT         DEFAULT NULL,
    `convertedUserID`  INT          DEFAULT NULL COMMENT 'FK → tblUsers once they sign up',
    `createdByID`      INT          DEFAULT NULL,
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`visitorID`),
    KEY `idx_visitor_site_status` (`siteID`, `status`),
    KEY `idx_visitor_assignee` (`assignedToID`),
    CONSTRAINT `fk_visitor_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_visitor_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_visitor_converted` FOREIGN KEY (`convertedUserID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_visitor_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblVisitorContact` (
    `contactID`     INT      NOT NULL AUTO_INCREMENT,
    `visitorID`     INT      NOT NULL,
    `contactedByID` INT      NOT NULL,
    `contactedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `method`        ENUM('visit','call','email','text','other') NOT NULL DEFAULT 'call',
    `summary`       TEXT     DEFAULT NULL,
    `nextContactAt` DATE     DEFAULT NULL,
    PRIMARY KEY (`contactID`),
    KEY `idx_visitor_contact_visitor` (`visitorID`),
    KEY `idx_visitor_contact_next` (`nextContactAt`),
    CONSTRAINT `fk_visitor_contact_visitor` FOREIGN KEY (`visitorID`) REFERENCES `tblVisitor` (`visitorID`) ON DELETE CASCADE,
    CONSTRAINT `fk_visitor_contact_by` FOREIGN KEY (`contactedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('visitors',              'visitors/index.php',         1),
    ('visitors/new',          'visitors/new.php',           1),
    ('visitors/save',         'visitors/save.php',          1),
    ('visitors/profile',      'visitors/profile.php',       1),
    ('visitors/contact-save', 'visitors/contact-save.php',  1),
    ('visitors/my-follow-ups','visitors/my-follow-ups.php', 1),
    ('visit',                 'visitors/public-form.php',   0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'visitors.enabled',                   '0', '0', 0),
    (NULL, 'visitors.coordinator_role',          'visitor_coordinator', 'visitor_coordinator', 0),
    (NULL, 'visitors.followup_initial_days',     '7',  '7',  0),
    (NULL, 'visitors.followup_followup_days',    '30', '30', 0),
    (NULL, 'visitors.followup_final_days',       '90', '90', 0),
    (NULL, 'visitors.public_capture_enabled',    '0',  '0',  0),
    (NULL, 'visitors.displayName',               'Visitor Tracking', 'Visitor Tracking', 0),
    (NULL, 'visitors.displayIcon',               'fa-solid fa-user-plus', 'fa-solid fa-user-plus', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
