-- =============================================================================
-- 041 — Password policy hardening 🔐
-- =============================================================================
-- - Bumps default `auth.password.minLength` from 8 to 12 to align with OWASP
--   ASVS L1 guidance (existing values are preserved by ON DUPLICATE KEY).
-- - Adds two new policy settings:
--     auth.password.maxLength       — defence against pathological inputs
--                                     (bcrypt truncates at 72 bytes; 128 leaves
--                                     headroom for emoji-rich passphrases).
--     auth.password.requireLowercase — was previously implicit in the validator
--                                     (bug: tied to requireUppercase flag).
--
-- See: web/core/Auth.php → validatePassword() / passwordPolicy()
-- =============================================================================

-- 🔄 Raise the default minimum length to 12 (default-value column only; existing
-- siteValues are preserved).
UPDATE `tblSettings`
   SET `defaultValue` = '12'
 WHERE `settingKey` = 'auth.password.minLength'
   AND `defaultValue` = '8';

-- 🆕 Add the new settings (NULL siteID = global default)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.password.maxLength',        '128',  '128',  0),
    (NULL, 'auth.password.requireLowercase', 'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
