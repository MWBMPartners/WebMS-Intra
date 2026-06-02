-- =============================================================================
-- Migration 084: Reading Plans app (#265)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblReadingPlan` (
    `planID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `slug`         VARCHAR(100) NOT NULL,
    `name`         VARCHAR(255) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `kind`         ENUM('bible','book','curriculum','custom') NOT NULL DEFAULT 'bible',
    `totalDays`    INT          NOT NULL DEFAULT 365,
    `isPublic`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`planID`),
    UNIQUE KEY `uq_rp_slug_site` (`slug`, `siteID`),
    KEY `idx_rp_site_kind` (`siteID`, `kind`),
    CONSTRAINT `fk_rp_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_rp_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblReadingPlanDay` (
    `dayID`     INT          NOT NULL AUTO_INCREMENT,
    `planID`    INT          NOT NULL,
    `dayNumber` INT          NOT NULL,
    `label`     VARCHAR(255) NOT NULL,
    `content`   TEXT         DEFAULT NULL COMMENT 'Optional commentary / passage (markdown)',
    PRIMARY KEY (`dayID`),
    UNIQUE KEY `uq_rpd_plan_day` (`planID`, `dayNumber`),
    CONSTRAINT `fk_rpd_plan` FOREIGN KEY (`planID`) REFERENCES `tblReadingPlan` (`planID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblReadingPlanEnrollment` (
    `enrollmentID` INT      NOT NULL AUTO_INCREMENT,
    `planID`       INT      NOT NULL,
    `userID`       INT      NOT NULL,
    `startedAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completedAt`  DATETIME DEFAULT NULL,
    `currentDay`   INT      NOT NULL DEFAULT 1,
    PRIMARY KEY (`enrollmentID`),
    UNIQUE KEY `uq_rpe_plan_user` (`planID`, `userID`),
    KEY `idx_rpe_user` (`userID`),
    CONSTRAINT `fk_rpe_plan` FOREIGN KEY (`planID`) REFERENCES `tblReadingPlan` (`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rpe_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblReadingPlanProgress` (
    `progressID`   INT      NOT NULL AUTO_INCREMENT,
    `enrollmentID` INT      NOT NULL,
    `dayNumber`    INT      NOT NULL,
    `completedAt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`progressID`),
    UNIQUE KEY `uq_rpp_enrollment_day` (`enrollmentID`, `dayNumber`),
    CONSTRAINT `fk_rpp_enrollment` FOREIGN KEY (`enrollmentID`) REFERENCES `tblReadingPlanEnrollment` (`enrollmentID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 📖 Seed two pre-defined plans (Bible-in-a-Year + chronological).
INSERT INTO `tblReadingPlan` (`siteID`, `slug`, `name`, `description`, `kind`, `totalDays`, `isPublic`) VALUES
    (1, 'bible-in-a-year',           'Bible in a Year',           'Read the whole Bible over 365 days, roughly 3-4 chapters per day.', 'bible', 365, 1),
    (1, 'bible-chronological',       'Bible Chronologically',     'Read the Bible in the order events occurred — Old Testament narrative then prophets, then Gospels in parallel.', 'bible', 365, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('reading-plans',          'reading-plans/index.php',  1),
    ('reading-plans/my',       'reading-plans/my.php',     1),
    ('reading-plans/plan',     'reading-plans/plan.php',   1),
    ('reading-plans/enroll',   'reading-plans/enroll.php', 1),
    ('reading-plans/check',    'reading-plans/check.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ⚙️ Settings
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'reading_plans.enabled',          '0', '0', 0),
    (NULL, 'reading_plans.daily_reminder',   '1', '1', 0),
    (NULL, 'reading_plans.displayName',      'Reading Plans', 'Reading Plans', 0),
    (NULL, 'reading_plans.displayIcon',      'fa-solid fa-book-open', 'fa-solid fa-book-open', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
