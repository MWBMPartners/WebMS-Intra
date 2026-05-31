-- =============================================================================
-- Migration 064: Backup freshness check + alerting (#142)
-- =============================================================================
-- Registers the /admin/maintenance/backup-check route and seeds the two
-- new settings governing the freshness threshold + alert recipients.
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/backup-check', 'admin/maintenance/backup-check.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.backups.max_age_hours',    '36', '36', 0),
    (NULL, 'portal.backups.alert_recipients', '',   '',   0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
