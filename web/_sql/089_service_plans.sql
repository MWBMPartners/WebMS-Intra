-- =============================================================================
-- Migration 089: Service Plan Builder app (#262)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblServicePlan` (
    `planID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `eventID`       INT          DEFAULT NULL COMMENT 'Optional FK → tblEvents',
    `title`         VARCHAR(255) NOT NULL,
    `serviceDate`   DATE         NOT NULL,
    `status`        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `preparedByID`  INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`planID`),
    KEY `idx_sp_site_date` (`siteID`, `serviceDate`),
    KEY `idx_sp_event` (`eventID`),
    CONSTRAINT `fk_sp_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sp_event`    FOREIGN KEY (`eventID`)      REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL,
    CONSTRAINT `fk_sp_prepared` FOREIGN KEY (`preparedByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblServicePlanItem` (
    `itemID`        INT          NOT NULL AUTO_INCREMENT,
    `planID`        INT          NOT NULL,
    `sectionType`   ENUM('greeting','song','prayer','scripture','sermon','offering','communion','special_music','announcement','reading','other') NOT NULL DEFAULT 'other',
    `position`      INT          NOT NULL DEFAULT 0,
    `title`         VARCHAR(255) DEFAULT NULL COMMENT 'e.g. song number/name, scripture ref, sermon title',
    `presenterID`   INT          DEFAULT NULL COMMENT 'FK → tblUsers for who is leading this item',
    `presenterText` VARCHAR(255) DEFAULT NULL COMMENT 'When presenter is not a portal user',
    `durationMin`   INT          DEFAULT NULL,
    `notes`         TEXT         DEFAULT NULL COMMENT 'Markdown — AV cues, additional context',
    PRIMARY KEY (`itemID`),
    KEY `idx_spi_plan_position` (`planID`, `position`),
    CONSTRAINT `fk_spi_plan`      FOREIGN KEY (`planID`)      REFERENCES `tblServicePlan`(`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_spi_presenter` FOREIGN KEY (`presenterID`) REFERENCES `tblUsers`(`userID`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('service-plans',          'service-plans/index.php',     1),
    ('service-plans/new',      'service-plans/new.php',       1),
    ('service-plans/edit',     'service-plans/edit.php',      1),
    ('service-plans/save',     'service-plans/save.php',      1),
    ('service-plans/print',    'service-plans/print.php',     1),
    ('service-plans/item-save','service-plans/item-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'service_plans.enabled',     '0', '0', 0),
    (NULL, 'service_plans.displayName', 'Service Plans', 'Service Plans', 0),
    (NULL, 'service_plans.displayIcon', 'fa-solid fa-list-ol', 'fa-solid fa-list-ol', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
