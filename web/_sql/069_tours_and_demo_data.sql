-- =============================================================================
-- Migration 069: What's new tour engine + Demo data admin (#237 #242 #224)
-- =============================================================================

-- 🎯 tblTours — tour definitions
CREATE TABLE IF NOT EXISTS `tblTours` (
    `tourID`     INT          NOT NULL AUTO_INCREMENT,
    `tourKey`    VARCHAR(64)  NOT NULL,
    `version`    VARCHAR(20)  NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `steps`      TEXT         NOT NULL COMMENT 'JSON array of step objects: {selector, title, body}',
    `isActive`   TINYINT(1)   NOT NULL DEFAULT 1,
    `forRoles`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'CSV of roles; empty = everyone',
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tourID`),
    UNIQUE KEY `uq_tour_key_version` (`tourKey`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='What is new tour definitions';

-- 👤 tblUserTours — per-user completion
CREATE TABLE IF NOT EXISTS `tblUserTours` (
    `userTourID`   INT      NOT NULL AUTO_INCREMENT,
    `userID`       INT      NOT NULL,
    `tourID`       INT      NOT NULL,
    `completedAt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userTourID`),
    UNIQUE KEY `uq_user_tour` (`userID`, `tourID`),
    KEY `idx_user_tours_user` (`userID`),
    CONSTRAINT `fk_user_tour_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_tour_tour` FOREIGN KEY (`tourID`) REFERENCES `tblTours`(`tourID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Per-user tour completion';

-- 📜 Seed welcome tour
INSERT INTO `tblTours` (`tourKey`, `version`, `title`, `steps`, `isActive`, `forRoles`) VALUES
    ('welcome', '1.0.0', 'Welcome to your portal',
     '[
        {"selector":".portal-dashboard-hero-title","title":"This is your dashboard","body":"Everything starts from here. Pinned announcements + your apps."},
        {"selector":"#announcementsCard","title":"Announcements","body":"News from your congregation. Tap to read full posts."},
        {"selector":"#calendarCard","title":"Calendar","body":"Events, services, meetings. RSVP from inside an event."},
        {"selector":"#profileMenu","title":"Your profile","body":"Update your name, password, notification preferences from here."}
     ]', 1, '')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- 🧪 Demo mode + tours routes + settings
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/demo-data', 'admin/maintenance/demo-data.php', 1),
    ('admin/tours', 'admin/tours/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.demo_mode.enabled',   '0', '0', 0),
    (NULL, 'portal.tours.welcome_active','1', '1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
