-- =============================================================================
-- Migration: 004_seed_routes.sql
-- Purpose:   Seeds tblRoutes with initial route definitions mapping clean URL
--            paths to their corresponding app PHP files. Used by core/Router.php.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================

-- 📌 Dashboard (portal home)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('dashboard', 'dashboard/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Login page (public, not protected)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('login', 'auth/login/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Expenses - Submit claim
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/submit', 'expenses/submit/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Expenses - Submit save handler
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/submit/save', 'expenses/submit/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Expenses - Approval dashboard
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/approve', 'expenses/approve/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Expenses - Approval save handler
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/approve/save', 'expenses/approve/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Expenses - Treasury / Reimbursement dashboard
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/treasury', 'expenses/treasury/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Expenses - Treasury save handler
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/treasury/save', 'expenses/treasury/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Settings admin UI
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('settings', 'settings/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Settings save handler
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('settings/save', 'settings/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📌 Migrations admin page (Global Admin only)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/migrations', 'admin/migrations/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
