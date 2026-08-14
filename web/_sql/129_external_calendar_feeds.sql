-- =============================================================================
-- Migration 129: External calendar feed aggregator (#327)
-- =============================================================================
-- Subscribes the portal to external ICS / iCal feeds (denominational
-- bulletin boards, partner orgs, public holidays). Cron fetches each
-- feed at its configured cadence; parsed VEVENTs are upserted into
-- tblEvents with externalFeedID + externalUid set so re-imports update
-- rather than duplicate.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/327
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblExternalFeeds` (
    `feedID`          INT          NOT NULL AUTO_INCREMENT,
    `siteID`          INT          NOT NULL,
    `name`            VARCHAR(120) NOT NULL,
    `url`             VARCHAR(2000) NOT NULL,
    `fetchEveryMins`  INT          NOT NULL DEFAULT 360 COMMENT 'How often to refetch (default 6h)',
    `categoryID`      INT          DEFAULT NULL COMMENT 'Auto-assign imported events to this category',
    `isActive`        TINYINT(1)   NOT NULL DEFAULT 1,
    `lastFetchedAt`   DATETIME     DEFAULT NULL,
    `lastFetchStatus` VARCHAR(255) DEFAULT NULL,
    `lastImportCount` INT          NOT NULL DEFAULT 0,
    `createdByID`     INT          DEFAULT NULL,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`feedID`),
    KEY `idx_feed_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_feed_site`    FOREIGN KEY (`siteID`)     REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_feed_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ➕ tblEvents.externalFeedID — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'externalFeedID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `externalFeedID` INT DEFAULT NULL AFTER `siteID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.externalUid — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'externalUid'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `externalUid` VARCHAR(255) DEFAULT NULL AFTER `externalFeedID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_event_external — guarded ADD INDEX (portable: MySQL 8.0 + MariaDB 10.x)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND INDEX_NAME   = 'idx_event_external'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEvents` ADD INDEX `idx_event_external` (`externalFeedID`, `externalUid`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`) VALUES
    ('feeds.cron_token', '', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/calendar/feeds',      'admin/calendar/feeds.php',      1),
    ('admin/calendar/feeds/save', 'admin/calendar/feeds-save.php', 1),
    ('cron/import-feeds',         'cron/import-feeds.php',         0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
