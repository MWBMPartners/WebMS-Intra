-- =============================================================================
-- 046 — Audit-log retention settings 🧹
-- =============================================================================
-- Seeds the settings consumed by /admin/maintenance/retention.
--
--   audit.retentionDays      Days to retain tblActivityLogs rows  (default 365)
--   errors.retentionDays     Days to retain tblErrors rows        (default 365)
--   maintenance.cronToken    Required for cron-mode execution      (default '')
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'audit.retentionDays',   '365', '365', 0),
    (NULL, 'errors.retentionDays',  '365', '365', 0),
    (NULL, 'maintenance.cronToken', '',    '',    1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Route for the retention admin page
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/retention', 'admin/maintenance/retention.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
