-- Migration: 014_admin_integrations_route.sql
-- Purpose:   Adds route for the Admin Integration Diagnostics page.
-- Issue:     #46

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/integrations', 'admin/integrations/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
