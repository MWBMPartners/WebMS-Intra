-- =============================================================================
-- 052 — Bulk importers for events + leadership 📥
-- =============================================================================
-- Routes for the new admin importers. Each importer is a single
-- two-step PHP page (preview → confirm) that follows the same pattern
-- as /admin/users/import.
--
-- CSV format only — Excel users save-as CSV first. Vendoring
-- PhpSpreadsheet for native .xlsx support is ~5 MB and would slow
-- the deploy noticeably; the trade-off favours CSV-only for v1.0.
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/import',    'calendar/manage/import.php',    1),
    ('leadership/manage/import',  'leadership/manage/import.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
