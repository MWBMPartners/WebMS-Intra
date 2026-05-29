-- =============================================================================
-- Migration 057: Remove dead account/linked-accounts route
-- =============================================================================
-- The route was registered with `targetFile = 'auth/account/linked-accounts.php'`
-- but the file was never created — clicking it 404s. The page may be built
-- in future; when it is, add the route back via a new migration with the
-- correct targetFile.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/205
-- =============================================================================

DELETE FROM `tblRoutes` WHERE `routeKey` = 'account/linked-accounts';
