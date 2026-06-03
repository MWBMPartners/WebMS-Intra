-- =============================================================================
-- Migration 105: External error monitoring (#143)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/monitoring',      'admin/integrations/monitoring/index.php', 1),
    ('admin/integrations/monitoring/save', 'admin/integrations/monitoring/save.php',  1),
    ('admin/integrations/monitoring/test', 'admin/integrations/monitoring/test.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'monitoring.enabled',     '0', '0', 0),
    (NULL, 'monitoring.sentryDsn',   '',  '',  1),
    (NULL, 'monitoring.environment', '',  '',  0),
    (NULL, 'monitoring.sampleRate',  '1.0', '1.0', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
