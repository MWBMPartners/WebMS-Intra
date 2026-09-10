-- =============================================================================
-- Migration 190: a real sitemap, and a robots.txt that follows the setting
-- =============================================================================
-- Two new addresses. No tables, no columns, no settings.
--
-- -----------------------------------------------------------------------------
-- WHY THERE IS NOW A SITEMAP
-- -----------------------------------------------------------------------------
-- A sitemap is a plain list of the pages on a website that its owner would like
-- a search engine to look at, with a note of when each last changed. Search
-- engines otherwise find pages only by following links, so anything not linked
-- from somewhere obvious can go unnoticed indefinitely.
--
-- This portal had none at all.
--
-- The important part is what it does NOT list. This is a private portal holding
-- members' names, addresses, giving records, pastoral notes and children's
-- details. A sitemap listing the wrong page would be an active invitation to
-- come and read it. So the new page names a short, deliberate list of genuinely
-- public pages, one at a time, rather than filtering a list of everything that
-- happens to be reachable without signing in.
--
-- That distinction matters more than it sounds. Of the 562 addresses this
-- portal answers, 80 need no sign-in - and most of those still have no business
-- in a sitemap: the addresses that receive submitted forms, the scheduled jobs
-- that run in the background, tracking images inside newsletters, the addresses
-- that serve photographs as raw bytes, and the sign-in page itself.
--
-- -----------------------------------------------------------------------------
-- AND WHY robots.txt HAD TO STOP BEING A PLAIN FILE
-- -----------------------------------------------------------------------------
-- This one was a genuine fault, not just a missing feature.
--
-- `robots.txt` tells crawlers what they may look at. It was a plain text file
-- saying "no crawler may look at anything here", which is the right default for
-- a private portal.
--
-- But there is a setting, `site.allowIndexing`, that an administrator can turn
-- on to publish their site to search engines. Turning it on changed the
-- instructions written into each page - and could not change the plain file,
-- because a plain file cannot know about a setting.
--
-- So a site that opted in said two opposite things at once. Every page said
-- "please index me"; robots.txt said "do not come in at all". Crawlers read
-- robots.txt first and obey it, so THE OPT-IN DID NOT WORK. An administrator
-- could switch indexing on, wait weeks, never appear anywhere, and have nothing
-- at all to explain why.
--
-- robots.txt is now generated from the same settings the pages use, so the two
-- can no longer disagree.
--
-- -----------------------------------------------------------------------------
-- THE STATIC FILE HAD TO GO, AND THIS IS WHY
-- -----------------------------------------------------------------------------
-- `web/public_html/robots.txt` is DELETED in the same change as this migration.
-- That is not tidying up; the new address would not work otherwise.
--
-- The web server answers for anything that really exists on disk and never
-- hands the request to this portal. So while a real `robots.txt` file sat
-- there, the new address would never once have been reached, and the old
-- unchanging file would have gone on being served exactly as before. The
-- symptom would have been "I turned indexing on and nothing happened" - the
-- very fault being fixed.
--
-- There is an automatic check for that whole class of mistake:
-- `tools/audit-checks/check_webroot_shadowing.py`.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://www.sitemaps.org/protocol.html
-- @link      https://www.robotstxt.org/robotstxt.html
-- =============================================================================

-- #############################################################################
-- 🗺️ A. The two new addresses
-- #############################################################################
-- Both are PUBLIC (isProtected = 0). They have to be: a crawler cannot sign in.
-- Neither reveals anything private. The sitemap answers "not found" entirely
-- while the site is unpublished, and robots.txt is meant to be read by anybody.
--
-- Safe to run again. The installer replays full_schema.sql and then every
-- numbered migration, ignoring which have already run, so re-pointing the target
-- file on a second run is correct: if either file is ever moved, a replay
-- corrects the address instead of leaving a dead one.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('sitemap.xml', 'sitemap.php', 0),
    ('robots.txt',  'robots.php',  0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋 B. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('190_sitemap_and_dynamic_robots.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
