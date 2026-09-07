-- =============================================================================
-- Migration 185: API documentation page — self-hosted Swagger UI switch
-- =============================================================================
-- Adds ONE setting. No tables, no columns, no indexes.
--
-- WHAT THIS IS FOR, in plain terms:
--
-- The page at /api-docs shows the portal's REST API in a browsable form, so a
-- developer can read every endpoint and try one out without writing any code.
-- That page is built by a library called Swagger UI.
--
-- Until now Swagger UI was fetched from a public content delivery network (a
-- CDN — a set of servers on the public internet that host popular libraries).
-- A copy of the same files now ships inside the portal itself, under
-- /assets/vendor/swagger-ui/, so the page still works when the CDN cannot be
-- reached. That fallback happens automatically and needs no setting.
--
-- This setting covers a different case. On a network that BLOCKS the CDN
-- outright — some schools, some corporate networks — the browser still has to
-- try the CDN first, wait for the attempt to fail, and only then load the
-- local copy. During that wait the page looks broken. Turning this setting on
-- tells the page to skip the CDN entirely and go straight to the local files.
--
-- It is seeded OFF, so nothing changes for the majority of installs that can
-- reach the CDN and benefit from its caching. A site administrator turns it on
-- only if /api-docs is slow to appear.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra
-- =============================================================================

-- #############################################################################
-- ⚙️ A. Settings seed (1 key)
-- #############################################################################
-- Read by Portal\Core\Asset::swaggerUiLocalOnly(). The read is already
-- ?? default-guarded in PHP, so an install that somehow misses this row still
-- behaves exactly as before (CDN first, local copy as the fallback).

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.docs.local_assets_only', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 📋 B. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('185_api_docs_self_hosted.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
