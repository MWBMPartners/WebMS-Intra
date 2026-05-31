-- =============================================================================
-- Migration 074: Rota / Duty Roster app (#256)
-- =============================================================================

-- 🎯 Role types — admin defines what "duties" exist (welcomer, AV, sound,
--    Sabbath school teacher, etc.). Industry-neutral schema.
CREATE TABLE IF NOT EXISTS `tblRotaRoleType` (
    `roleTypeID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `name`        VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `colorHex`    VARCHAR(7)   NOT NULL DEFAULT '#5e6ad2',
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`roleTypeID`),
    UNIQUE KEY `uq_rota_role_name_site` (`name`, `siteID`),
    KEY `idx_rota_role_site` (`siteID`),
    CONSTRAINT `fk_rota_role_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Duty/role type definitions for the rota app';

-- 🗓️ Slot — a specific (role × date) assignment
CREATE TABLE IF NOT EXISTS `tblRotaSlot` (
    `slotID`        INT      NOT NULL AUTO_INCREMENT,
    `siteID`        INT      NOT NULL DEFAULT 1,
    `roleTypeID`    INT      NOT NULL,
    `slotDate`      DATE     NOT NULL,
    `startTime`     TIME     DEFAULT NULL COMMENT 'NULL = all-day duty',
    `endTime`       TIME     DEFAULT NULL,
    `assignedToID`  INT      DEFAULT NULL COMMENT 'NULL = unfilled',
    `notes`         VARCHAR(500) DEFAULT NULL,
    `reminderSentAt` DATETIME DEFAULT NULL,
    `createdByID`   INT      DEFAULT NULL,
    `createdAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`slotID`),
    KEY `idx_rota_slot_site_date` (`siteID`, `slotDate`),
    KEY `idx_rota_slot_role` (`roleTypeID`),
    KEY `idx_rota_slot_assignee` (`assignedToID`),
    CONSTRAINT `fk_rota_slot_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_rota_slot_role` FOREIGN KEY (`roleTypeID`) REFERENCES `tblRotaRoleType` (`roleTypeID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rota_slot_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_rota_slot_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual duty assignments';

-- 🔄 Swap requests — member requests another member to cover their duty
CREATE TABLE IF NOT EXISTS `tblRotaSwapRequest` (
    `swapID`           INT      NOT NULL AUTO_INCREMENT,
    `slotID`           INT      NOT NULL,
    `requestedByID`    INT      NOT NULL,
    `targetUserID`     INT      DEFAULT NULL COMMENT 'NULL = open to any volunteer',
    `status`           ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
    `requestMessage`   VARCHAR(500) DEFAULT NULL,
    `responseMessage`  VARCHAR(500) DEFAULT NULL,
    `respondedAt`      DATETIME DEFAULT NULL,
    `createdAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`swapID`),
    KEY `idx_rota_swap_slot` (`slotID`),
    KEY `idx_rota_swap_requester` (`requestedByID`),
    KEY `idx_rota_swap_target` (`targetUserID`),
    CONSTRAINT `fk_rota_swap_slot` FOREIGN KEY (`slotID`) REFERENCES `tblRotaSlot` (`slotID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rota_swap_requester` FOREIGN KEY (`requestedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rota_swap_target` FOREIGN KEY (`targetUserID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Member-to-member duty swap requests';

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('rota',             'rota/index.php',        1),
    ('rota/manage',      'rota/manage.php',       1),
    ('rota/role-types',  'rota/role-types.php',   1),
    ('rota/slot-save',   'rota/slot-save.php',    1),
    ('rota/swap',        'rota/swap.php',         1),
    ('rota/swap-respond','rota/swap-respond.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ⚙️ Settings
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'rota.enabled',              '0', '0', 0),
    (NULL, 'rota.reminder_days_before', '3', '3', 0),
    (NULL, 'rota.allow_open_swap',      '1', '1', 0),
    (NULL, 'rota.displayName',          'Duty Roster', 'Duty Roster', 0),
    (NULL, 'rota.displayIcon',          'fa-solid fa-calendar-week', 'fa-solid fa-calendar-week', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
