-- =============================================================================
-- Migration 106: REST API write-side CRUD endpoints (#157)
-- =============================================================================
-- Registers route targetFiles so check_route_targets.py confirms each is
-- reachable. ApiRouter::dispatch() actually routes by URL pattern
-- (`api/{appName}/{action}`) and resolves to {appName}/api/{action}.php,
-- so the targetFile values here are informational — the audit still
-- needs them.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Announcements
    ('api/announcements/create', 'announcements/api/create.php', 1),
    ('api/announcements/update', 'announcements/api/update.php', 1),
    ('api/announcements/delete', 'announcements/api/delete.php', 1),
    -- Tasks
    ('api/tasks/create',         'tasks/api/create.php',         1),
    ('api/tasks/complete',       'tasks/api/complete.php',       1),
    ('api/tasks/delete',         'tasks/api/delete.php',         1),
    -- Prayer Requests
    ('api/prayer-requests/create',   'prayer-requests/api/create.php',   1),
    ('api/prayer-requests/moderate', 'prayer-requests/api/moderate.php', 1),
    -- Leadership
    ('api/leadership/assign',   'leadership/api/assign.php',   1),
    ('api/leadership/unassign', 'leadership/api/unassign.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    -- Mirror the existing per-action enabled-flag convention from v1.0.
    -- Defaults to enabled — admins can turn individual endpoints off via
    -- /admin/settings if they don't want the API surface exposed.
    (NULL, 'api.announcements.create.enabled',     'true', 'true', 0),
    (NULL, 'api.announcements.update.enabled',     'true', 'true', 0),
    (NULL, 'api.announcements.delete.enabled',     'true', 'true', 0),
    (NULL, 'api.tasks.create.enabled',             'true', 'true', 0),
    (NULL, 'api.tasks.complete.enabled',           'true', 'true', 0),
    (NULL, 'api.tasks.delete.enabled',             'true', 'true', 0),
    (NULL, 'api.prayer-requests.create.enabled',   'true', 'true', 0),
    (NULL, 'api.prayer-requests.moderate.enabled', 'true', 'true', 0),
    (NULL, 'api.leadership.assign.enabled',        'true', 'true', 0),
    (NULL, 'api.leadership.unassign.enabled',      'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
