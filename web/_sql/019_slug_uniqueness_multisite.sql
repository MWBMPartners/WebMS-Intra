-- Migration 019: Update slug uniqueness constraints for multisite
-- Changes global UNIQUE(slug) to composite UNIQUE(slug, siteID)
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/60

-- -----------------------------------------------------------------------------
-- 📂 tblEventCategories — categorySlug unique per site
-- -----------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND INDEX_NAME   = 'uq_cat_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblEventCategories` DROP INDEX `uq_cat_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND INDEX_NAME   = 'uq_cat_slug_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventCategories` ADD UNIQUE KEY `uq_cat_slug_site` (`categorySlug`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 📋 tblEventTypes — typeSlug unique per site
-- -----------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventTypes'
      AND INDEX_NAME   = 'uq_type_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblEventTypes` DROP INDEX `uq_type_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventTypes'
      AND INDEX_NAME   = 'uq_type_slug_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventTypes` ADD UNIQUE KEY `uq_type_slug_site` (`typeSlug`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 🎨 tblEventThemes — themeSlug unique per site
-- -----------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventThemes'
      AND INDEX_NAME   = 'uq_theme_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblEventThemes` DROP INDEX `uq_theme_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventThemes'
      AND INDEX_NAME   = 'uq_theme_slug_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventThemes` ADD UNIQUE KEY `uq_theme_slug_site` (`themeSlug`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 🔗 tblEventSeries — seriesSlug unique per site
-- -----------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventSeries'
      AND INDEX_NAME   = 'uq_series_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblEventSeries` DROP INDEX `uq_series_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventSeries'
      AND INDEX_NAME   = 'uq_series_slug_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventSeries` ADD UNIQUE KEY `uq_series_slug_site` (`seriesSlug`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- ⛪ tblAttendanceServiceTypes — typeSlug unique per site
-- -----------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAttendanceServiceTypes'
      AND INDEX_NAME   = 'uq_att_type_slug'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblAttendanceServiceTypes` DROP INDEX `uq_att_type_slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAttendanceServiceTypes'
      AND INDEX_NAME   = 'uq_att_type_slug_site'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblAttendanceServiceTypes` ADD UNIQUE KEY `uq_att_type_slug_site` (`typeSlug`, `siteID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('019_slug_uniqueness_multisite.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
