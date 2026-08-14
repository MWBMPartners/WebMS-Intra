-- Migration 023: Add route for event series bulk edit
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/75

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/series-edit', 'calendar/manage/series-edit.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Track migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('023_series_bulk_edit_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
