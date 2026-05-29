-- =============================================================================
-- Migration 055: Fix admin/upgrade route targetFile
-- =============================================================================
-- The original migration `025_install_upgrade_route.sql` registered:
--     admin/upgrade  ->  ../install/upgrade.php
-- That path uses `..` to escape `public_html/` (which the Router doesn't
-- allow) AND references the old directory name `install/` (renamed to
-- `_install/`). Clicking the link 404s.
--
-- The upgrade handler still lives at `web/_install/upgrade.php` (correctly,
-- outside the web root). A new proxy file at
-- `web/public_html/admin/upgrade.php` `require`s it; this migration
-- repoints the route at the proxy.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/202
-- =============================================================================

UPDATE `tblRoutes`
SET    `targetFile` = 'admin/upgrade.php'
WHERE  `routeKey` = 'admin/upgrade';
