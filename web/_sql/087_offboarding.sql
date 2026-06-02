-- =============================================================================
-- Migration 087: Offboarding app (#240)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblOffboarding` (
    `offboardingID`  INT          NOT NULL AUTO_INCREMENT,
    `userID`         INT          NOT NULL,
    `effectiveDate`  DATE         NOT NULL,
    `reason`         VARCHAR(500) DEFAULT NULL,
    `dataDisposition` ENUM('retain','anonymise','delete') NOT NULL DEFAULT 'retain',
    `offboardedByID` INT          NOT NULL,
    `offboardedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `rehiredAt`      DATETIME     DEFAULT NULL,
    `rehiredByID`    INT          DEFAULT NULL,
    `stepsLog`       JSON         DEFAULT NULL COMMENT 'Per-step success/failure outcomes',
    PRIMARY KEY (`offboardingID`),
    KEY `idx_offboard_user` (`userID`),
    CONSTRAINT `fk_offboard_user`         FOREIGN KEY (`userID`)         REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_offboard_by`           FOREIGN KEY (`offboardedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_offboard_rehired_by`   FOREIGN KEY (`rehiredByID`)    REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Audit trail of user offboarding actions (#240)';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('offboarding',         'offboarding/index.php',     1),
    ('offboarding/user',    'offboarding/user.php',      1),
    ('offboarding/do',      'offboarding/do.php',        1),
    ('offboarding/rehire',  'offboarding/rehire.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'offboarding.enabled',          '0',  '0',  0),
    (NULL, 'offboarding.undo_window_days', '7',  '7',  0),
    (NULL, 'offboarding.displayName',      'Offboarding', 'Offboarding', 0),
    (NULL, 'offboarding.displayIcon',      'fa-solid fa-door-open', 'fa-solid fa-door-open', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
