-- =============================================================================
-- 039 — Prayer Requests app
-- =============================================================================
-- Per-site prayer request submission, moderation, and tracking. Supports:
--   • Logged-in submissions (linked to user)
--   • Anonymous submissions via a public route (CAPTCHA + rate-limited)
--   • Per-request visibility (leadership-only or congregation feed)
--   • Status lifecycle: pending → active → answered (+ optional testimony) → archived
-- =============================================================================

-- 🙏 tblPrayerRequests — submitted prayer requests, per-site scoped
CREATE TABLE IF NOT EXISTS `tblPrayerRequests` (
    `requestID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL
                     COMMENT 'FK → tblSites — the site the request was submitted to',
    `submitterID`    INT          DEFAULT NULL
                     COMMENT 'FK → tblUsers — NULL for anonymous public submissions',
    `submitterName`  VARCHAR(100) DEFAULT NULL
                     COMMENT 'Optional display name supplied by an anonymous submitter',
    `submitterEmail` VARCHAR(255) DEFAULT NULL
                     COMMENT 'Optional contact email supplied by an anonymous submitter (for follow-up)',
    `submitterIP`    VARCHAR(45)  DEFAULT NULL
                     COMMENT 'IPv4 or IPv6 of the submitter — for spam analysis only',
    `subject`        VARCHAR(255) NOT NULL
                     COMMENT 'Short title for the request',
    `body`           TEXT         NOT NULL
                     COMMENT 'Full text of the prayer request',
    `visibility`     ENUM('leadership','congregation') NOT NULL DEFAULT 'leadership'
                     COMMENT 'Who can see the request once published. Submitter chooses; moderator may override',
    `status`         ENUM('pending','active','answered','archived') NOT NULL DEFAULT 'pending'
                     COMMENT 'Lifecycle: pending=awaiting moderation, active=published, answered=closed with optional testimony, archived=hidden',
    `isAnonymous`    TINYINT(1)   NOT NULL DEFAULT 0
                     COMMENT '1 = display "Anonymous" as the submitter in any visible view',
    `testimony`      TEXT         DEFAULT NULL
                     COMMENT 'Optional praise / testimony note attached when status moves to answered',
    `answeredAt`     DATETIME     DEFAULT NULL
                     COMMENT 'When the request was marked answered',
    `moderatorID`    INT          DEFAULT NULL
                     COMMENT 'FK → tblUsers — last admin who moderated this request',
    `moderatedAt`    DATETIME     DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`requestID`),
    KEY `idx_site_status` (`siteID`, `status`),
    KEY `idx_submitter`   (`submitterID`),
    CONSTRAINT `fk_pr_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pr_submitter` FOREIGN KEY (`submitterID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_pr_moderator` FOREIGN KEY (`moderatorID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Prayer requests submitted by members or anonymously via the public route.';

-- =============================================================================
-- Seed default settings
-- =============================================================================
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'prayerRequests.enabled',              'true',  'true',  0),
    (NULL, 'prayerRequests.allowAnonymous',       'true',  'true',  0),
    (NULL, 'prayerRequests.allowCongregationFeed','true',  'true',  0),
    (NULL, 'prayerRequests.requireModeration',    'true',  'true',  0),
    (NULL, 'prayerRequests.allowTestimony',       'true',  'true',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- =============================================================================
-- Seed routes (mostly protected; one public anonymous route)
-- =============================================================================
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('prayer-requests',                'prayer-requests/index.php',           1),
    ('prayer-requests/submit',         'prayer-requests/submit.php',          1),
    ('prayer-requests/save',           'prayer-requests/save.php',            1),
    ('prayer-requests/view',           'prayer-requests/view.php',            1),
    ('prayer-requests/manage',         'prayer-requests/manage.php',          1),
    ('prayer-requests/moderate',       'prayer-requests/moderate.php',        1),
    ('prayer-requests/anonymous',      'prayer-requests/anonymous.php',       0),
    ('prayer-requests/anonymous/save', 'prayer-requests/anonymous-save.php',  0),
    ('help/prayer-requests',           'help/prayer-requests.php',            0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
