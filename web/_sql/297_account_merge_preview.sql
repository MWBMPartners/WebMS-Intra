-- =============================================================================
-- Migration 297: the preview page for merging two accounts (#1064, D-185)
--
-- Path: web/_sql/297_account_merge_preview.sql
--
-- WHY THIS EXISTS
--   The owner decided on 13 November 2026 (D-185) that duplicate accounts must
--   be mergeable, for cleanup. This adds the address of the first part: Admin,
--   Users, Merge two accounts (web/_apps/admin/users/merge.php), which counts
--   what a merge would move and CHANGES NOTHING. The merge itself waits on two
--   owner questions recorded on #1064.
--
-- WHAT IT DOES
--   Adds one protected address to tblRoutes. Nothing else. The permission the
--   page asks, `accounts.merge`, lives in code (Portal\Core\Capabilities) and
--   needs no row until an organisation points it at a different role.
--
-- SAFE TO RUN TWICE
--   ON DUPLICATE KEY UPDATE on both statements.
--
-- MySQL 8.0 ∩ MariaDB
--   Plain INSERT ... ON DUPLICATE KEY UPDATE only.
--
--   The same row is in web/_sql/full_schema.sql, in the block of migration
--   records near its end, and the two must agree.
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/users/merge', 'admin/users/merge.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('297_account_merge_preview.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
