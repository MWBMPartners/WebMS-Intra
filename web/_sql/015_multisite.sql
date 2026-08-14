-- Migration: 015_multisite.sql
-- Purpose:   Add multi-site support — tblSites, tblUserSites, siteID columns
--            on all data tables, multisite settings, and admin routes.
-- Issue:     #45

-- =============================================================================
-- SECTION 1: NEW TABLES
-- =============================================================================

-- 🌐 tblSites — site/division definitions
CREATE TABLE IF NOT EXISTS `tblSites` (
    `siteID`        INT          NOT NULL AUTO_INCREMENT,
    `siteName`      VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL
                    COMMENT 'Human-readable site name',
    `siteKey`       VARCHAR(50)  COLLATE utf8mb4_general_ci NOT NULL
                    COMMENT 'Machine-readable slug (e.g. cambridge, leeds)',
    `hostPattern`   VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL
                    COMMENT 'Hostname for subdomain detection (e.g. cambridge.portal.example.com)',
    `logoPath`      VARCHAR(500) COLLATE utf8mb4_general_ci DEFAULT '/assets/images/logo.svg'
                    COMMENT 'Path to site-specific logo image',
    `primaryColor`  VARCHAR(7)   COLLATE utf8mb4_general_ci DEFAULT '#0d6efd'
                    COMMENT 'Hex colour for site branding',
    `copyrightOrg`  VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL
                    COMMENT 'Copyright holder name for footer',
    `timezone`      VARCHAR(50)  COLLATE utf8mb4_general_ci DEFAULT 'UTC'
                    COMMENT 'Site-specific timezone identifier',
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`siteID`),
    UNIQUE KEY `uq_site_key` (`siteKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Multi-site definitions. Each row represents a portal site/division.';

-- 🔗 tblUserSites — user-to-site assignments with site-level role flags
CREATE TABLE IF NOT EXISTS `tblUserSites` (
    `userSiteID`      INT        NOT NULL AUTO_INCREMENT,
    `userID`          INT        NOT NULL COMMENT 'FK -> tblUsers.userID',
    `siteID`          INT        NOT NULL COMMENT 'FK -> tblSites.siteID',
    `isSiteAdmin`     TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Can manage users/settings/data for this site',
    `isSiteRootAdmin` TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Full control within this site, can assign site admins',
    `isActive`        TINYINT(1) NOT NULL DEFAULT 1,
    `joinedAt`        DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userSiteID`),
    UNIQUE KEY `uq_user_site` (`userID`, `siteID`),
    KEY `idx_us_site` (`siteID`),
    CONSTRAINT `fk_us_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_us_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Maps users to sites with per-site admin role flags (4-tier hierarchy).';


-- =============================================================================
-- SECTION 2: SEED DEFAULT SITE (backward compatibility)
-- =============================================================================

-- 🏠 Create default site (siteID=1) using existing settings values
INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`, `copyrightOrg`, `timezone`)
SELECT 1,
    COALESCE(
        (SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.name' LIMIT 1),
        'Portal'
    ),
    'default',
    COALESCE(
        (SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.copyrightOrg' LIMIT 1),
        'Organisation'
    ),
    COALESCE(
        (SELECT `settingValue` FROM `tblSettings` WHERE `settingKey` = 'site.timezone' LIMIT 1),
        'UTC'
    )
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `tblSites` WHERE `siteID` = 1);

-- 🔗 Assign ALL existing users to the default site, preserving admin flags
INSERT IGNORE INTO `tblUserSites` (`userID`, `siteID`, `isSiteAdmin`, `isSiteRootAdmin`)
SELECT u.`userID`, 1,
    CASE WHEN u.`isAdmin` = 1 THEN 1 ELSE 0 END,
    CASE WHEN u.`isRootAdmin` = 1 THEN 1 ELSE 0 END
FROM `tblUsers` u;


-- =============================================================================
-- SECTION 3: ADD siteID COLUMNS TO DATA TABLES
-- =============================================================================

-- 🏢 tblDepts
-- Idempotent: column+key+FK ship atomically, guarded on presence of the
-- siteID column (portable across MySQL 8.0 + MariaDB 10.x — see DEV_NOTES →
-- Portable DDL). Same guard block shape repeated per table below.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDepts'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblDepts`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `deptID`,
        ADD KEY `idx_depts_site` (`siteID`),
        ADD CONSTRAINT `fk_depts_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 💰 tblExpenseClaims
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblExpenseClaims'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblExpenseClaims`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `claimID`,
        ADD KEY `idx_claims_site` (`siteID`),
        ADD CONSTRAINT `fk_claims_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📅 tblEvents
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `eventID`,
        ADD KEY `idx_events_site` (`siteID`),
        ADD CONSTRAINT `fk_events_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📂 tblEventCategories
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventCategories`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `categoryID`,
        ADD KEY `idx_ecat_site` (`siteID`),
        ADD CONSTRAINT `fk_ecat_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🏷️ tblEventTypes
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventTypes'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventTypes`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `typeID`,
        ADD KEY `idx_etype_site` (`siteID`),
        ADD CONSTRAINT `fk_etype_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🎨 tblEventThemes
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventThemes'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventThemes`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `themeID`,
        ADD KEY `idx_etheme_site` (`siteID`),
        ADD CONSTRAINT `fk_etheme_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔄 tblEventSeries
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventSeries'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventSeries`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `seriesID`,
        ADD KEY `idx_eseries_site` (`siteID`),
        ADD CONSTRAINT `fk_eseries_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🏷️ tblAttendanceServiceTypes
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAttendanceServiceTypes'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAttendanceServiceTypes`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `serviceTypeID`,
        ADD KEY `idx_ast_site` (`siteID`),
        ADD CONSTRAINT `fk_ast_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 tblAttendanceSessions
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAttendanceSessions'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAttendanceSessions`
        ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `sessionID`,
        ADD KEY `idx_asess_site` (`siteID`),
        ADD CONSTRAINT `fk_asess_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📝 tblActivityLogs (nullable — pre-bootstrap logs may lack site context)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblActivityLogs'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblActivityLogs`
        ADD COLUMN `siteID` INT DEFAULT NULL AFTER `logID`,
        ADD KEY `idx_logs_site` (`siteID`),
        ADD CONSTRAINT `fk_logs_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🚨 tblErrors (nullable — pre-bootstrap errors may lack site context)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblErrors'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblErrors`
        ADD COLUMN `siteID` INT DEFAULT NULL AFTER `errorID`,
        ADD KEY `idx_errors_site` (`siteID`),
        ADD CONSTRAINT `fk_errors_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ⚙️ tblSettings (nullable — NULL = global default, specific siteID = override)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSettings'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSettings`
        ADD COLUMN `siteID` INT DEFAULT NULL AFTER `settingID`,
        ADD KEY `idx_settings_site` (`siteID`),
        ADD CONSTRAINT `fk_settings_site` FOREIGN KEY (`siteID`)
            REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔑 Update tblSettings unique key to allow per-site overrides
-- Must use a workaround: MySQL treats NULL as distinct in UNIQUE keys,
-- so (settingKey, NULL) and (settingKey, 1) are naturally unique.
-- Idempotent: drop the old single-column key only if present, then add the
-- new composite unique key only if missing (portable across MySQL 8.0 +
-- MariaDB 10.x — see DEV_NOTES → Portable DDL).
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSettings'
      AND INDEX_NAME   = 'settingKey'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblSettings` DROP INDEX `settingKey`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSettings'
      AND INDEX_NAME   = 'uq_setting_key_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblSettings` ADD UNIQUE KEY `uq_setting_key_site` (`settingKey`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔄 Backfill existing log/error rows with default siteID=1
UPDATE `tblActivityLogs` SET `siteID` = 1 WHERE `siteID` IS NULL;
UPDATE `tblErrors` SET `siteID` = 1 WHERE `siteID` IS NULL;


-- =============================================================================
-- SECTION 4: MULTISITE SETTINGS
-- =============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('multisite.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('multisite.detectionMode', 'session', 0, 'session')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- =============================================================================
-- SECTION 5: NEW ROUTES
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/sites', 'admin/sites/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/sites/save', 'admin/sites/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/sites/users', 'admin/sites/users.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('site/switch', 'site/switch.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
