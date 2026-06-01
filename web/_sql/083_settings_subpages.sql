-- =============================================================================
-- Migration 083: Settings group sub-page routes (#252)
-- =============================================================================
-- Five friendly admin sub-pages for the grouped portal.* setting families,
-- all served by one definition-driven controller (admin/settings/group.php),
-- which derives the group from the matched route path.
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/alerts',      'admin/settings/group.php', 1),
    ('admin/settings/backups',     'admin/settings/group.php', 1),
    ('admin/settings/headers',     'admin/settings/group.php', 1),
    ('admin/settings/upgrade',     'admin/settings/group.php', 1),
    ('admin/settings/maintenance', 'admin/settings/group.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
