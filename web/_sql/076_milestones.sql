-- =============================================================================
-- Migration 076: Milestones app (#259)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblUserMilestone` (
    `milestoneID`  INT          NOT NULL AUTO_INCREMENT,
    `userID`       INT          NOT NULL,
    `kind`         ENUM('birthday','anniversary','baptism','joining','wedding','other') NOT NULL DEFAULT 'other',
    `label`        VARCHAR(100) DEFAULT NULL COMMENT 'For kind=other / custom — e.g. "Started volunteering"',
    `monthDay`     CHAR(5)      NOT NULL COMMENT 'MM-DD — year is the originating year if known',
    `originYear`   INT          DEFAULT NULL COMMENT 'Birth year, wedding year, etc — optional',
    `privacy`      ENUM('private','team','members','public') NOT NULL DEFAULT 'team'
                   COMMENT 'private=only me+admins, team=role/team-mates, members=any logged-in, public=anyone',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`milestoneID`),
    KEY `idx_milestone_user` (`userID`),
    KEY `idx_milestone_md`   (`monthDay`),
    CONSTRAINT `fk_milestone_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Recurring milestones (birthdays, anniversaries) per user';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('milestones',      'milestones/index.php',  1),
    ('milestones/me',   'milestones/me.php',     1),
    ('milestones/save', 'milestones/save.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'milestones.enabled',           '0', '0', 0),
    (NULL, 'milestones.digest_recipients', '',  '',  0),
    (NULL, 'milestones.displayName',       'Milestones', 'Milestones', 0),
    (NULL, 'milestones.displayIcon',       'fa-solid fa-cake-candles', 'fa-solid fa-cake-candles', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
