-- =============================================================================
-- Migration 096: Project fundraising pages (#267)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblProject` (
    `projectID`         INT          NOT NULL AUTO_INCREMENT,
    `siteID`            INT          NOT NULL DEFAULT 1,
    `slug`              VARCHAR(200) NOT NULL,
    `title`             VARCHAR(255) NOT NULL,
    `description`       TEXT         DEFAULT NULL,
    `targetAmountPence` INT          NOT NULL,
    `currency`          CHAR(3)      NOT NULL DEFAULT 'GBP',
    `startedAt`         DATE         DEFAULT NULL,
    `endsAt`            DATE         DEFAULT NULL,
    `status`            ENUM('planning','active','funded','completed','cancelled') NOT NULL DEFAULT 'planning',
    `coverImagePath`    VARCHAR(255) DEFAULT NULL,
    `isPublic`          TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`       INT          DEFAULT NULL,
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`projectID`),
    UNIQUE KEY `uq_p_site_slug` (`siteID`, `slug`),
    KEY `idx_p_status` (`status`),
    CONSTRAINT `fk_p_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_p_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblProjectPledge` (
    `pledgeID`     INT          NOT NULL AUTO_INCREMENT,
    `projectID`    INT          NOT NULL,
    `donorID`      INT          DEFAULT NULL,
    `donorName`    VARCHAR(255) DEFAULT NULL,
    `donorEmail`   VARCHAR(255) DEFAULT NULL,
    `amountPence`  INT          NOT NULL,
    `pledgedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fulfilledAt`  DATETIME     DEFAULT NULL,
    `givingEntryID` INT         DEFAULT NULL COMMENT 'Link to tblGivingEntry when fulfilled',
    `isAnonymous`  TINYINT(1)   NOT NULL DEFAULT 0,
    `message`      VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`pledgeID`),
    KEY `idx_pp_project` (`projectID`),
    KEY `idx_pp_donor`   (`donorID`),
    CONSTRAINT `fk_pp_project` FOREIGN KEY (`projectID`) REFERENCES `tblProject`(`projectID`) ON DELETE CASCADE,
    CONSTRAINT `fk_pp_donor`   FOREIGN KEY (`donorID`)   REFERENCES `tblUsers`(`userID`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblProjectUpdate` (
    `updateID`   INT      NOT NULL AUTO_INCREMENT,
    `projectID`  INT      NOT NULL,
    `postedByID` INT      DEFAULT NULL,
    `postedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `content`    TEXT     NOT NULL,
    PRIMARY KEY (`updateID`),
    KEY `idx_pu_project_date` (`projectID`, `postedAt`),
    CONSTRAINT `fk_pu_project` FOREIGN KEY (`projectID`)  REFERENCES `tblProject`(`projectID`) ON DELETE CASCADE,
    CONSTRAINT `fk_pu_poster`  FOREIGN KEY (`postedByID`) REFERENCES `tblUsers`(`userID`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('projects',              'projects/index.php',         0),
    ('projects/view',         'projects/view.php',          0),
    ('projects/pledge',       'projects/pledge.php',        0),
    ('projects/manage',       'projects/manage.php',        1),
    ('projects/manage-save',  'projects/manage-save.php',   1),
    ('projects/update-post',  'projects/update-post.php',   1),
    ('projects/fulfil',       'projects/fulfil.php',        1),
    ('projects/my-pledges',   'projects/my-pledges.php',    1),
    ('projects/contributors', 'projects/contributors.php',  0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'projects.enabled',     '0', '0', 0),
    (NULL, 'projects.displayName', 'Projects', 'Projects', 0),
    (NULL, 'projects.displayIcon', 'fa-solid fa-bullseye', 'fa-solid fa-bullseye', 0),
    (NULL, 'projects.currency',    'GBP', 'GBP', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
