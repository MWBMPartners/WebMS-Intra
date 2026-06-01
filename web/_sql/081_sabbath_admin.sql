-- =============================================================================
-- Migration 081: Sabbath admin sub-page route (#251)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/sabbath', 'admin/settings/sabbath/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
