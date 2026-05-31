-- =============================================================================
-- Migration 067: Critical-error alerts + Email admin UI (#229 #230)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/email', 'admin/integrations/email.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.alerts.recipients',       '',                '',                0),
    (NULL, 'portal.alerts.severities',       'Critical,Fatal',  'Critical,Fatal',  0),
    (NULL, 'portal.alerts.cooldown_minutes', '30',              '30',              0),
    (NULL, 'email.provider',                 'smtp',            'smtp',            0),
    (NULL, 'email.from',                     '',                '',                0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
