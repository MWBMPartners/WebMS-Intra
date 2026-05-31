-- =============================================================================
-- Migration 066: System Health page route (#228)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/health', 'admin/maintenance/health.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
