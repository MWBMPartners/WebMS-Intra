-- =============================================================================
-- 050 — Notification preferences UI gating 📬
-- =============================================================================
-- The notifyPrefs column on tblUsers + the digest settings landed in
-- migration 026. This migration adds the "actually wired up for sending"
-- gate so admins can build out delivery later without the UI implying
-- emails are already going out.
--
--   notifications.deliveryReady  (default 'false')
--     When false, the new /account/notifications UI shows a banner
--     explaining that preferences are saved but emails aren't being
--     sent yet. Flip to 'true' once Mailer is configured and the
--     digest cron is running.
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'notifications.deliveryReady', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Account-level notification preferences page
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/notifications',      'auth/account/notifications.php',      1),
    ('account/notifications/save', 'auth/account/notifications-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
