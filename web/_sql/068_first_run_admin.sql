-- =============================================================================
-- Migration 068: First-run dashboard + admin first-steps page (#222 #223)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/admin-first-steps', 'help/admin-first-steps.php', 1),
    ('admin/settings/dismiss-first-run', 'admin/settings/dismiss-first-run.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.first_run.dismissed',                    '0', '0', 0),
    (NULL, 'portal.first_run.steps.site_branding',          '0', '0', 0),
    (NULL, 'portal.first_run.steps.email_delivery',         '0', '0', 0),
    (NULL, 'portal.first_run.steps.test_backup',            '0', '0', 0),
    (NULL, 'portal.first_run.steps.retention_cron',         '0', '0', 0),
    (NULL, 'portal.first_run.steps.invite_users',           '0', '0', 0),
    (NULL, 'portal.first_run.steps.first_announcement',     '0', '0', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
