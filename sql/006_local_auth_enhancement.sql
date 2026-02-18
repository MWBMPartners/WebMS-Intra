-- =============================================================================
-- Migration 006: Local Authentication Enhancement
-- =============================================================================
-- Adds password-reset token table, password-policy settings, and routes for
-- the forgot-password, reset-password, and account-management pages.
--
-- @package   Portal\Database
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2025-2026 MWBM Partners Ltd (t/a MWservices)
-- @license   MIT
-- @version   0.2.0
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 🔑 tblPasswordResets — time-limited password-reset tokens
-- -----------------------------------------------------------------------------
-- The plaintext token is emailed to the user; only its SHA-256 hash is stored
-- here so a database breach never exposes valid tokens.
-- See: https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblPasswordResets` (
    `resetID`   INT          NOT NULL AUTO_INCREMENT,
    `userID`    INT          NOT NULL COMMENT 'FK → tblUsers.userID',
    `tokenHash` VARCHAR(255) NOT NULL COMMENT 'SHA-256 hash of the plaintext reset token',
    `expiresAt` DATETIME     NOT NULL COMMENT 'Token expiry timestamp',
    `usedAt`    DATETIME     DEFAULT NULL COMMENT 'NULL until the token is consumed',
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `createdIP` VARCHAR(100) DEFAULT NULL COMMENT 'IP address that requested the reset',
    PRIMARY KEY (`resetID`),
    KEY `idx_resets_user`    (`userID`),
    KEY `idx_resets_token`   (`tokenHash`),
    KEY `idx_resets_expires` (`expiresAt`),
    CONSTRAINT `fk_resets_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Time-limited password-reset tokens for local accounts.';


-- -----------------------------------------------------------------------------
-- ⚙️ Password-policy settings
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.minLength', '8', 0, '8')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireUppercase', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireNumber', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireSpecial', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.passwordReset.tokenExpiry', '60', 0, '60')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- -----------------------------------------------------------------------------
-- 🗺️ Routes for authentication and account-management pages
-- -----------------------------------------------------------------------------

-- Forgot-password form (public)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('forgot-password', 'auth/forgot-password/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Forgot-password save handler (public)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('forgot-password/save', 'auth/forgot-password/save.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Reset-password form (public, token in query string)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('reset-password', 'auth/reset-password/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Reset-password save handler (public)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('reset-password/save', 'auth/reset-password/save.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Account/profile page (protected)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account', 'auth/account/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Account save handler (protected)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/save', 'auth/account/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Password-change handler (protected)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/change-password', 'auth/account/change-password.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
