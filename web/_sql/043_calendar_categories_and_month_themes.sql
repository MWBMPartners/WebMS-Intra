-- =============================================================================
-- 043 — Calendar categories: colour + display style, plus per-month themes
-- =============================================================================
-- Two related additions to the calendar app (follow-up to #136 / PR #137):
--
--   1. `tblEventCategories.color` + `tblEventCategories.displayStyle`
--      Categories can now carry a colour (hex) and choose how that colour
--      renders in the year planner:
--        - 'background' (default) → tinted cell behind the event text
--        - 'text'                 → coloured text on default background
--      This lets sites style Bank Holidays / notable days as coloured text
--      while reserving the tinted-band look for organisational scopes
--      (Area, Conference, Union, etc.).
--
--   2. `tblCalendarMonthThemes` (new table)
--      Per-year-per-month "strap line" / theme text that appears under
--      the month name on the year-planner view (e.g. "~Healthy connections~"
--      for February). One row per (site, year, month).
-- =============================================================================

-- 🎨 Categories: add colour + display style ----------------------------------
-- ➕ tblEventCategories.color — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND COLUMN_NAME  = 'color'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventCategories` ADD COLUMN `color` VARCHAR(9) DEFAULT NULL COMMENT ''Hex colour (#RRGGBB or #RRGGBBAA) used by the year planner / month grid'' AFTER `sortOrder`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEventCategories.displayStyle — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEventCategories'
      AND COLUMN_NAME  = 'displayStyle'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventCategories` ADD COLUMN `displayStyle` ENUM(''background'',''text'') NOT NULL DEFAULT ''background'' COMMENT ''How the colour renders in the year planner: background tint vs. text colour'' AFTER `color`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🗓️ Per-month theme / strap line --------------------------------------------
CREATE TABLE IF NOT EXISTS `tblCalendarMonthThemes` (
    `themeID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`     INT          NOT NULL COMMENT 'FK → tblSites',
    `year`       SMALLINT     NOT NULL COMMENT 'e.g. 2026',
    `month`      TINYINT      NOT NULL COMMENT '1..12',
    `themeText`  VARCHAR(255) NOT NULL COMMENT 'Strap-line shown under the month name on /calendar?view=year',
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`themeID`),
    UNIQUE KEY `uq_cmt_site_year_month` (`siteID`, `year`, `month`),
    KEY `idx_cmt_site_year` (`siteID`, `year`),
    CONSTRAINT `fk_cmt_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `chk_cmt_month` CHECK (`month` BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-year-per-month strap-line shown on the calendar year planner.';

-- 🛣️ Route for the new admin page --------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/month-themes', 'calendar/manage/month-themes.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
