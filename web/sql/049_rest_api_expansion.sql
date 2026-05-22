-- =============================================================================
-- 049 — REST API expansion 🛰️
-- =============================================================================
-- New endpoints landed at the correct ApiRouter-conformant paths:
--
--   {appName}/api/{action}.php   ← what ApiRouter::dispatch() expects.
--
-- ApiRouter gates each endpoint behind a per-endpoint enabled flag in
-- tblSettings (api.{appName}.{action}.enabled). Defaults set here.
--
-- Existing endpoints relocated from the wrong path (api/{appName}/{action}.php)
-- to the correct location — same URLs, same handlers, just at the path
-- the router actually looks at.
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    -- Existing (relocated only)
    (NULL, 'api.announcements.list.enabled',   'true', 'true', 0),
    (NULL, 'api.attendance.list.enabled',      'true', 'true', 0),
    (NULL, 'api.events.list.enabled',          'true', 'true', 0),
    (NULL, 'api.events.detail.enabled',        'true', 'true', 0),
    (NULL, 'api.users.list.enabled',           'true', 'true', 0),
    -- New (events write-side)
    (NULL, 'api.events.create.enabled',        'true', 'true', 0),
    (NULL, 'api.events.update.enabled',        'true', 'true', 0),
    (NULL, 'api.events.delete.enabled',        'true', 'true', 0),
    -- New (list endpoints for previously API-less modules)
    (NULL, 'api.leadership.list.enabled',       'true', 'true', 0),
    (NULL, 'api.tasks.list.enabled',            'true', 'true', 0),
    (NULL, 'api.prayer-requests.list.enabled',  'true', 'true', 0),
    (NULL, 'api.documents.list.enabled',        'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
