-- =============================================================================
-- Migration 025: Add upgrade route
-- =============================================================================
-- Adds route for the web-based upgrade handler so it's accessible through
-- the normal routing system.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/84
-- =============================================================================

-- 📌 Route for upgrade page (admin only, handled outside normal routing)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/upgrade', '../install/upgrade.php', '1')
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
