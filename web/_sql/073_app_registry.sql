-- =============================================================================
-- Migration 073: App Registry route + organisation industry setting (#255)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/apps', 'admin/apps/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 🏢 Industry profile — filters the apps list. Valid values:
--    '', 'church', 'community', 'school', 'nonprofit', 'small-business',
--    'membership-org', 'mutual-aid', 'hr', 'events', 'training', 'media',
--    'broadcasting', 'coworking', 'podcasting'
-- Empty default = show all apps regardless of industry tag.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.industry', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
