-- =============================================================================
-- 047 — 2FA trusted-device cookie 🔐
-- =============================================================================
-- After a successful 2FA challenge, users can opt to mark the current
-- browser/device as "trusted" — they won't be re-challenged on that
-- device for the configured trust window (default 30 days).
--
-- The cookie holds a random opaque token. We store only its SHA-256 HASH
-- in tblTrustedDevices, so a DB breach never exposes valid tokens (same
-- pattern as tblPasswordResets).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblTrustedDevices` (
    `deviceID`    INT          NOT NULL AUTO_INCREMENT,
    `userID`      INT          NOT NULL,
    `tokenHash`   CHAR(64)     NOT NULL COMMENT 'SHA-256 of the cookie token',
    `label`       VARCHAR(255) DEFAULT NULL COMMENT 'User-agent snippet for the user-facing list',
    `createdIP`   VARCHAR(45)  DEFAULT NULL,
    `lastSeenAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `expiresAt`   DATETIME     NOT NULL,
    `revokedAt`   DATETIME     DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`deviceID`),
    UNIQUE KEY `uq_token_hash` (`tokenHash`),
    KEY `idx_user_active` (`userID`, `revokedAt`, `expiresAt`),
    CONSTRAINT `fk_td_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Trusted devices that bypass the 2FA challenge for a configured window.';

-- Setting controlling the trust window (default 30 days)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.twoFactor.trustedDeviceDays', '30', '30', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
