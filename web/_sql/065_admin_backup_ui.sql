-- =============================================================================
-- Migration 065: Admin backup UI route (#227)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/backup', 'admin/maintenance/backup.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
