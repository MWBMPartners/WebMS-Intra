-- =============================================================================
-- 040 — Multi-provider Captcha support 🤖
-- =============================================================================
-- Adds:
--   • hCaptcha as a third supported provider (site + secret keys)
--   • reCAPTCHA v3 score / action settings
--   • auth.captcha.priority — ordered, comma-separated provider list
--     (admin-configurable via /admin/captcha drag-and-drop)
--
-- Default priority places Cloudflare Turnstile first per platform policy.
-- =============================================================================

-- 🌐 Provider priority (admin-configurable, drag-and-drop UI at /admin/captcha)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.captcha.priority', 'turnstile,recaptcha,hcaptcha', 'turnstile,recaptcha,hcaptcha', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- ☁️ hCaptcha provider keys
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.hcaptcha.siteKey',   '', '', 1),
    (NULL, 'auth.hcaptcha.secretKey', '', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🎯 reCAPTCHA v3-only settings (ignored when version=v2)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.recaptcha.v3.action',    'submit', 'submit', 0),
    (NULL, 'auth.recaptcha.v3.threshold', '0.5',    '0.5',    0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🛠️ Routes for the new admin captcha-config page
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/captcha',      'admin/captcha/index.php', 1),
    ('admin/captcha/save', 'admin/captcha/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
