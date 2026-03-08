-- =============================================================================
-- Migration 032: Two-Factor Authentication (TOTP)
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/92
-- =============================================================================

-- 📋 Add TOTP columns to tblUsers
ALTER TABLE `tblUsers`
    ADD COLUMN IF NOT EXISTS `totpSecret`  VARCHAR(64) DEFAULT NULL COMMENT 'Encrypted TOTP shared secret',
    ADD COLUMN IF NOT EXISTS `totpEnabled` TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'TOTP 2FA enabled flag';

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
