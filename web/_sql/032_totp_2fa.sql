-- =============================================================================
-- Migration 032: Two-Factor Authentication (TOTP)
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/92
-- =============================================================================

-- 📋 Add TOTP columns to tblUsers
-- ➕ tblUsers.totpSecret — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'totpSecret'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `totpSecret` VARCHAR(64) DEFAULT NULL COMMENT ''Encrypted TOTP shared secret''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblUsers.totpEnabled — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'totpEnabled'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `totpEnabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''TOTP 2FA enabled flag''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Backup codes table
CREATE TABLE IF NOT EXISTS `tblTotpBackupCodes` (
    `codeID`    INT          NOT NULL AUTO_INCREMENT,
    `userID`    INT          NOT NULL,
    `codeHash`  VARCHAR(255) NOT NULL COMMENT 'Hashed backup code',
    `isUsed`    TINYINT(1)   NOT NULL DEFAULT 0,
    `usedAt`    DATETIME     DEFAULT NULL,
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codeID`),
    KEY `idx_backup_user` (`userID`),
    CONSTRAINT `fk_backup_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='TOTP backup/recovery codes';

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('auth/2fa/verify', 'auth/2fa/verify.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('auth/2fa/setup', 'auth/2fa/setup.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('auth/2fa/disable', 'auth/2fa/disable.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('auth.totpEnabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('auth.totpIssuer', 'WebMS Portal', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
