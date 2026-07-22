-- =============================================================================
-- Migration 138: Worship Engine Phase 2 — live present + display + state
-- =============================================================================
-- Adds the live-presentation surfaces on top of #308 Phase 1:
--   • tblServicePlans gains a displayToken (64-char hex) so the projector
--     can load the display URL without logging in. Minted lazily on first
--     present-mode load.
--   • tblServicePlanState — one row per plan, upserts as the operator
--     advances. Holds the currentItemID, isBlank, isBlack, updatedByID,
--     updatedAt. The display polls /api/worship/state every 500ms.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
-- =============================================================================

-- ➕ tblServicePlans.displayToken — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblServicePlans'
      AND COLUMN_NAME  = 'displayToken'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlans` ADD COLUMN `displayToken` CHAR(64) DEFAULT NULL COMMENT ''64-char hex token for the projector display URL — minted lazily''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique index added separately (some MySQL builds reject IF NOT EXISTS on UNIQUE)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblServicePlans'
      AND INDEX_NAME = 'uq_plan_display_token'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblServicePlans` ADD UNIQUE KEY `uq_plan_display_token` (`displayToken`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `tblServicePlanState` (
    `planID`            INT          NOT NULL,
    `currentItemID`     INT          DEFAULT NULL,
    `isBlank`           TINYINT(1)   NOT NULL DEFAULT 0
                        COMMENT 'Logo / brand background instead of slide',
    `isBlack`           TINYINT(1)   NOT NULL DEFAULT 0
                        COMMENT 'Solid black — between songs / silence',
    `updatedByID`       INT          DEFAULT NULL,
    `updatedAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`planID`),
    KEY `idx_state_updated`   (`updatedAt`),
    CONSTRAINT `fk_state_plan`    FOREIGN KEY (`planID`)      REFERENCES `tblServicePlans`(`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_state_user`    FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers`(`userID`)        ON DELETE SET NULL,
    CONSTRAINT `fk_state_item`    FOREIGN KEY (`currentItemID`) REFERENCES `tblServicePlanItems`(`itemID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('worship/present',         'worship/present.php',         1),
    ('worship/display',         'worship/display.php',         0),
    ('api/worship/state',       'api/worship-state.php',       0),
    ('api/worship/advance',     'api/worship-advance.php',     1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
