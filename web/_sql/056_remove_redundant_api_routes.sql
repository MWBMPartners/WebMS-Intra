-- =============================================================================
-- Migration 056: Remove redundant api/* routes from tblRoutes
-- =============================================================================
-- Migration 035 (api_expansion) inserted 5 routes following the pattern
-- `api/{app}/{action}`. The current routing model (per migration 049 and
-- the ApiRouter class) hard-codes a special case in Router::dispatch():
--
--     if (str_starts_with($path, 'api/')) { ApiRouter::dispatch($path); }
--
-- ApiRouter then resolves `{app}/api/{action}.php`, NOT the path stored
-- in tblRoutes.targetFile. The 5 rows are unreachable — pure dead config
-- that confuses anyone reading the routes table.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/204
-- =============================================================================

DELETE FROM `tblRoutes` WHERE `routeKey` IN (
    'api/announcements/list',
    'api/attendance/list',
    'api/events/detail',
    'api/events/list',
    'api/users/list'
);
