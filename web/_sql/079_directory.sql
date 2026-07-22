-- =============================================================================
-- Migration 079: Member Directory app (#261)
-- =============================================================================
-- Extends tblUsers with directory-profile columns. Per-field visibility
-- enums let each user opt in field-by-field. Defaults are conservative:
-- name + role visible to members, contact details private.
-- =============================================================================

-- ➕ tblUsers.displayBio — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'displayBio'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `displayBio` TEXT DEFAULT NULL COMMENT ''Markdown bio (#261)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.displayPhoto — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'displayPhoto'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `displayPhoto` VARCHAR(500) DEFAULT NULL COMMENT ''Path under _uploads/ to profile photo''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.displayPhone — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'displayPhone'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `displayPhone` VARCHAR(50) DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.displayAddress — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'displayAddress'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `displayAddress` VARCHAR(500) DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityName — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityName` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''members''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityRoles — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityRoles'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityRoles` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''members''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityEmail — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityEmail'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityEmail` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''private''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityPhone — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityPhone'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityPhone` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''private''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityAddress — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityAddress'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityAddress` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''private''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityBio — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityBio'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityBio` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''members''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.visibilityPhoto — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'visibilityPhoto'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityPhoto` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''private''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('directory',             'directory/index.php',   1),
    ('directory/profile',     'directory/profile.php', 1),
    ('directory/my-settings', 'directory/me.php',      1),
    ('directory/save',        'directory/save.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'directory.enabled',     '0', '0', 0),
    (NULL, 'directory.displayName', 'Member Directory', 'Member Directory', 0),
    (NULL, 'directory.displayIcon', 'fa-solid fa-address-book', 'fa-solid fa-address-book', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
