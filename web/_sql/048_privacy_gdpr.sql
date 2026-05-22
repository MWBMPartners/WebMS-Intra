-- =============================================================================
-- 048 — Privacy / GDPR helpers 🇪🇺
-- =============================================================================
-- Closes #47 — minimum-viable compliance scaffold:
--
--   • tblConsentLog       — records each user's cookie / privacy consent
--                            decision so we have an audit trail.
--   • Settings: privacy.* — admin-editable retention policy text,
--                            contact email, controller name, etc.
--   • Routes              — /privacy, /privacy/policy, /account/data-export,
--                            /account/delete
-- =============================================================================

-- 📝 Consent log: one row per user per decision (insert-only history)
CREATE TABLE IF NOT EXISTS `tblConsentLog` (
    `consentID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL,
    `userID`      INT          DEFAULT NULL COMMENT 'NULL for anonymous (cookie-only) visitors',
    `sessionID`   VARCHAR(255) DEFAULT NULL COMMENT 'PHP session ID — joins anon consent to a later login',
    `consentType` ENUM('cookies','privacy_policy','marketing','analytics') NOT NULL,
    `decision`    ENUM('accept','reject','withdraw') NOT NULL,
    `policyHash`  CHAR(64)     DEFAULT NULL COMMENT 'SHA-256 of the policy text at decision time',
    `ipAddress`   VARCHAR(45)  DEFAULT NULL,
    `userAgent`   VARCHAR(255) DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`consentID`),
    KEY `idx_consent_user`    (`userID`),
    KEY `idx_consent_session` (`sessionID`),
    KEY `idx_consent_type`    (`siteID`, `consentType`),
    CONSTRAINT `fk_consent_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_consent_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail of cookie / privacy policy consent decisions.';

-- ⚙️ Admin-editable privacy settings
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'privacy.controllerName',     '',  '',  0),
    (NULL, 'privacy.contactEmail',       '',  '',  0),
    (NULL, 'privacy.policyURL',          '',  '',  0),
    (NULL, 'privacy.dataRetentionDays',  '730', '730', 0),
    (NULL, 'privacy.cookieBannerEnabled','true','true',0),
    (NULL, 'privacy.allowAccountDelete', 'true','true',0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🛣️ Routes (account/* files live under public_html/auth/account/)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('privacy',                   'privacy/index.php',              0),
    ('privacy/consent',           'privacy/consent.php',            0),
    ('account/data-export',       'auth/account/data-export.php',   1),
    ('account/delete',            'auth/account/delete.php',        1),
    ('account/delete/confirm',    'auth/account/delete-confirm.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
