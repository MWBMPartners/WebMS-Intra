-- =============================================================================
-- Migration: 003_add_missing_settings.sql
-- Purpose:   Adds missing settings entries to tblSettings that are expected
--            by the application code but were not present in the initial seed.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================

-- 📌 expenses.enabled - required by dashboard to show Expenses app card
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 portal.version - current portal version for display and API responses
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.version', '0.1.0', 0, '0.1.0')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 portal.alphaAccessRoles - comma-separated roleKey values for alpha access
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.alphaAccessRoles', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 portal.betaAccessRoles - comma-separated roleKey values for beta access
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.betaAccessRoles', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 auth.rateLimit.maxAttempts - max failed login attempts before lockout
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.rateLimit.maxAttempts', '5', 0, '5')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 auth.rateLimit.windowMinutes - time window for rate limit counting
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.rateLimit.windowMinutes', '15', 0, '15')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 auth.allowGoogleLogin - future Google Workspace login toggle
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.allowGoogleLogin', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 auth.google.clientID - future Google OAuth client ID
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.clientID', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 auth.google.clientSecret - future Google OAuth client secret
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.clientSecret', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 auth.google.redirectURI - future Google OAuth redirect URI
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.redirectURI', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 site.copyrightOrg - Organisation name for copyright footer
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.copyrightOrg', 'MWBM Partners Ltd', 0, 'Organisation Name')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 📌 site.copyrightStartYear - Year copyright began
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.copyrightStartYear', '2025', 0, '2025')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
