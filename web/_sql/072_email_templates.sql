-- =============================================================================
-- Migration 072: Email template preview route (#243)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/email-templates', 'admin/integrations/email-templates.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
