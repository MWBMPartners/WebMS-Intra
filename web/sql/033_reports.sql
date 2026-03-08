-- =============================================================================
-- Migration 033: Reporting / Analytics Dashboard
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/93
-- =============================================================================

-- 📋 Routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/reports', 'admin/reports/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/reports/data', 'admin/reports/data.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
