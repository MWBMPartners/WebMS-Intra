-- =============================================================================
-- Migration: 017_leadership.sql
-- Purpose:   Creates tables for the Leadership app.
--            Manages leadership roles, role assignments (to portal users or
--            external people), and term tracking with historical records.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- @see       https://github.com/MWBMPartners/WebMS-Intra/issues/38
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 🏷️ tblLeadershipRoles — types of leadership positions
-- E.g. Pastor, Elder, Deacon, Deaconess, Treasurer, Clerk, etc.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLeadershipRoles` (
    `roleID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `roleName`    VARCHAR(150) NOT NULL,
    `roleSlug`    VARCHAR(100) NOT NULL COMMENT 'URL-safe slug',
    `description` VARCHAR(500) DEFAULT NULL,
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`roleID`),
    UNIQUE KEY `uq_lr_slug_site` (`roleSlug`, `siteID`),
    KEY `idx_lr_site` (`siteID`),
    CONSTRAINT `fk_lr_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Leadership role definitions (e.g. Pastor, Elder, Deacon).';


-- -----------------------------------------------------------------------------
-- 👥 tblLeadershipAssignments — person-to-role assignments with term dates
-- Supports both portal users (userID FK) and external/non-user people
-- (personName + personEmail). endDate NULL = currently serving.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLeadershipAssignments` (
    `assignmentID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `roleID`       INT          NOT NULL COMMENT 'FK → tblLeadershipRoles',
    `userID`       INT          DEFAULT NULL COMMENT 'FK → tblUsers (NULL if external person)',
    `personName`   VARCHAR(255) DEFAULT NULL COMMENT 'Name if not a portal user',
    `personEmail`  VARCHAR(255) DEFAULT NULL COMMENT 'Email if not a portal user',
    `startDate`    DATE         DEFAULT NULL COMMENT 'Term start date',
    `endDate`      DATE         DEFAULT NULL COMMENT 'Term end date (NULL = current)',
    `notes`        TEXT         DEFAULT NULL,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `updatedByID`  INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`assignmentID`),
    KEY `idx_la_site` (`siteID`),
    KEY `idx_la_role` (`roleID`),
    KEY `idx_la_user` (`userID`),
    KEY `idx_la_active` (`isActive`, `endDate`),
    CONSTRAINT `fk_la_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_la_role` FOREIGN KEY (`roleID`)
        REFERENCES `tblLeadershipRoles` (`roleID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_la_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_la_creator` FOREIGN KEY (`createdByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_la_updater` FOREIGN KEY (`updatedByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Leadership role assignments — who holds what role, with term tracking.';


-- =============================================================================
-- 🌱 Seed default leadership roles (SDA church context)
-- =============================================================================
INSERT INTO `tblLeadershipRoles` (`roleName`, `roleSlug`, `description`, `sortOrder`) VALUES
    ('Pastor',             'pastor',              'Senior or associate pastor',                              1),
    ('Head Elder',         'head-elder',           'Head elder / first elder',                                2),
    ('Elder',              'elder',                'Church elder',                                            3),
    ('Head Deacon',        'head-deacon',          'Head deacon',                                             4),
    ('Deacon',             'deacon',               'Church deacon',                                           5),
    ('Head Deaconess',     'head-deaconess',       'Head deaconess',                                          6),
    ('Deaconess',          'deaconess',            'Church deaconess',                                        7),
    ('Church Clerk',       'church-clerk',         'Church clerk — records and minutes',                      8),
    ('Treasurer',          'treasurer',            'Church treasurer — financial oversight',                   9),
    ('Sabbath School Superintendent', 'ss-superintendent', 'Sabbath School superintendent',                  10),
    ('Personal Ministries Leader',    'personal-ministries', 'Personal ministries / outreach leader',         11),
    ('Youth Leader',       'youth-leader',         'Adventist Youth (AY) leader',                            12),
    ('Communications Secretary', 'communications', 'Communications secretary',                               13),
    ('Health Ministries Leader', 'health-ministries', 'Health ministries leader',                             14),
    ('Music Director',     'music-director',       'Music ministry director / coordinator',                  15),
    ('Pathfinder Director','pathfinder-director',  'Pathfinder club director',                               16),
    ('Community Services Leader', 'community-services', 'Community services / ADRA liaison',                 17)
ON DUPLICATE KEY UPDATE `roleName` = VALUES(`roleName`);


-- =============================================================================
-- 📌 Leadership routes
-- =============================================================================
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership', 'leadership/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership/assign', 'leadership/assign.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership/save', 'leadership/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership/delete', 'leadership/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership/history', 'leadership/history.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership/manage', 'leadership/manage/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('leadership/manage/save', 'leadership/manage/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- =============================================================================
-- ⚙️ Enable leadership app + add display metadata
-- =============================================================================
UPDATE `tblSettings` SET `settingValue` = 'true'
    WHERE `settingKey` = 'leadership.enabled';

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.displayName', 'Leadership', 0, 'Leadership')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.displayIcon', 'fa-solid fa-crown', 0, 'fa-solid fa-crown')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.brandColor', '#d4af37', 0, '#d4af37')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
