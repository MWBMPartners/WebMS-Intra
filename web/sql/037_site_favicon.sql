-- =============================================================================
-- 037 — Add faviconPath column to tblSites
-- =============================================================================
-- Part of the UI refresh PR 2 (#111). Site admins can now set a per-site
-- favicon URL; header.php picks it up via Site::branding('favicon') and
-- renders <link rel="icon"> with it. Falls back to the SVG default when null.
--
-- Idempotent: uses INFORMATION_SCHEMA to check before ALTER.
-- =============================================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblSites'
      AND COLUMN_NAME = 'faviconPath'
);

SET @stmt := IF(
    @col_exists = 0,
    'ALTER TABLE `tblSites`
        ADD COLUMN `faviconPath` VARCHAR(500)
        COLLATE utf8mb4_general_ci
        DEFAULT NULL
        COMMENT ''Path or URL to per-site favicon; NULL falls back to /assets/images/favicon.ico''
        AFTER `logoPath`',
    'SELECT ''column faviconPath already exists; skipping'' AS info'
);

PREPARE addCol FROM @stmt;
EXECUTE addCol;
DEALLOCATE PREPARE addCol;
