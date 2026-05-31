-- =============================================================================
-- Migration 062: Add /help/support route + support email setting (#226)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/support', 'help/support.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.support.email', 'portal-support@millrdsdacambridge.uk', 'portal-support@millrdsdacambridge.uk', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
