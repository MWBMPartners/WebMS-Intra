-- =============================================================================
-- Migration 054: tblEvents — add missing deletedAt column
-- =============================================================================
-- web/public_html/events/api/delete.php sets `deletedAt = NOW()` alongside
-- `isDeleted = 1` in its soft-delete UPDATE, but the column was never added
-- to tblEvents. Every admin attempt to delete an event hit
-- "Unknown column 'deletedAt' in 'field list'".
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/201
-- =============================================================================

-- ➕ tblEvents.deletedAt — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'deletedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `deletedAt` DATETIME DEFAULT NULL COMMENT ''Timestamp of soft-delete (set when isDeleted flips to 1)'' AFTER `isDeleted`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
