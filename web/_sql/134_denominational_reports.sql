-- Migration 134: Denominational reporting templates (#305)
-- Adds the route only — reports themselves are hardcoded query templates
-- in the page (no new tables; reads existing tblAttendance, tblSalvationCards,
-- tblEvents, tblUsers).

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/reports/denominational', 'admin/reports/denominational.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
