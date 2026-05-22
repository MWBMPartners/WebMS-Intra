-- =============================================================================
-- Migration: 011_auth_phase7.sql
-- Purpose:   Phase 7 Auth enhancements — Google OAuth, WebAuthn/PassKeys,
--            account linking (Issues #32, #33, #34).
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 🔗 tblLinkedAccounts — tracks external identity provider links per user
-- Each row maps a userID to a provider (ms365, google) and provider-specific ID.
-- Enables multi-provider login: a single tblUsers row can have MS365 + Google.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLinkedAccounts` (
    `linkID`       INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique link record identifier',
    `userID`       INT          NOT NULL                COMMENT 'FK to tblUsers.userID',
    `provider`     VARCHAR(50)  NOT NULL                COMMENT 'Identity provider: ms365, google, local',
    `providerSub`  VARCHAR(255) NOT NULL                COMMENT 'Provider-specific unique subject/ID',
    `providerEmail` VARCHAR(255) DEFAULT NULL           COMMENT 'Email address from the provider (for display)',
    `linkedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this link was created',
    PRIMARY KEY (`linkID`),
    UNIQUE KEY `uq_provider_sub` (`provider`, `providerSub`),
    KEY `idx_user` (`userID`),
    CONSTRAINT `tblLinkedAccounts_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Maps users to external identity providers for SSO login.';


-- -----------------------------------------------------------------------------
-- 🔐 tblWebAuthnCredentials — stores registered WebAuthn/PassKey credentials
-- Each row represents one hardware key or platform authenticator registered
-- by a user. The credentialID and publicKey are stored as base64url.
-- See: https://www.w3.org/TR/webauthn-2/
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWebAuthnCredentials` (
    `credID`        INT            NOT NULL AUTO_INCREMENT COMMENT 'Internal DB identifier',
    `userID`        INT            NOT NULL                COMMENT 'FK to tblUsers.userID',
    `credentialID`  TEXT           NOT NULL                COMMENT 'Base64url-encoded credential ID from authenticator',
    `publicKey`     TEXT           NOT NULL                COMMENT 'Base64url-encoded COSE public key',
    `signCount`     INT UNSIGNED   NOT NULL DEFAULT 0      COMMENT 'Signature counter for clone detection',
    `friendlyName`  VARCHAR(100)   DEFAULT NULL            COMMENT 'User-chosen label (e.g. "YubiKey 5C")',
    `aaguid`        VARCHAR(36)    DEFAULT NULL            COMMENT 'Authenticator Attestation GUID (identifies key model)',
    `transports`    VARCHAR(255)   DEFAULT NULL            COMMENT 'Comma-separated transport hints: usb,nfc,ble,internal',
    `createdAt`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When credential was registered',
    `lastUsedAt`    DATETIME       DEFAULT NULL            COMMENT 'Last successful authentication with this key',
    PRIMARY KEY (`credID`),
    KEY `idx_user` (`userID`),
    CONSTRAINT `tblWebAuthnCredentials_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='WebAuthn/PassKey credentials for passwordless authentication.';


-- -----------------------------------------------------------------------------
-- 📌 Add Google OAuth redirect URI setting (if not already present)
-- The auth.google.* settings already exist in full_schema.sql; this ensures
-- they exist on databases that were set up before Phase 7.
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.clientID', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.clientSecret', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.redirectURI', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🌐 Google Workspace HD (hosted domain) restriction — leave blank for any Google account
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.hostedDomain', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🔐 WebAuthn RP (Relying Party) settings
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.webauthn.rpName', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.webauthn.rpID', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- -----------------------------------------------------------------------------
-- 📌 Add routes for account linking and WebAuthn endpoints
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/linked-accounts', 'auth/account/linked-accounts.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/unlink', 'auth/account/unlink.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/webauthn', 'auth/account/webauthn.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/webauthn/delete', 'auth/account/webauthn-delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
