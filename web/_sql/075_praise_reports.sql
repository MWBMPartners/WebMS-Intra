-- =============================================================================
-- Migration 075: Praise Reports app (#260)
-- =============================================================================
-- Extends tblPrayerRequests with a `kind` column. Praise reports use the
-- same schema, lifecycle, and moderation flow — they're philosophically the
-- counterpart to prayer requests, structurally identical.
-- =============================================================================

-- ➕ tblPrayerRequests.kind — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND COLUMN_NAME  = 'kind'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD COLUMN `kind` ENUM(''request'',''praise'',''testimony'') NOT NULL DEFAULT ''request'' COMMENT ''request=prayer ask, praise=answered/gratitude, testimony=longer-form (#260)'' AFTER `body`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_pr_kind_site_status — guarded ADD KEY (index for the praise listing query)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblPrayerRequests'
      AND INDEX_NAME   = 'idx_pr_kind_site_status'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblPrayerRequests` ADD KEY `idx_pr_kind_site_status` (`siteID`, `kind`, `status`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('praise',     'praise/index.php', 1),
    ('praise/new', 'praise/new.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ⚙️ Settings
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'praise.enabled',     '0',              '0',              0),
    (NULL, 'praise.displayName', 'Praise Reports', 'Praise Reports', 0),
    (NULL, 'praise.displayIcon', 'fa-solid fa-hands-clapping', 'fa-solid fa-hands-clapping', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
