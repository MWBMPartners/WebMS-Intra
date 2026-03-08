-- =============================================================================
-- Migration 027: User import route
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/87
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/users/import', 'admin/users/import.php', '1')
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
