-- =============================================================================
-- Migration 212: which organisation (#502) — one address per organisation, and
--                three new pages
--
-- Path: web/_sql/212_which_organisation.sql
--
-- WHY THIS EXISTS
--   One installation can serve several organisations. One of the three ways
--   of telling them apart gives each organisation a web address of its own,
--   kept in tblSites.hostPattern and compared with the host name of every
--   request. Until now that column was stored exactly as an administrator
--   typed it, and nothing stopped two organisations being given the same
--   address. With one address on two organisations, which of them answered
--   was whichever row the database happened to return first.
--
--   The same piece of work adds three pages: the chooser a person sees after
--   signing in when they belong to several organisations, and the set-up page
--   (and its save handler) where a global administrator chooses how
--   organisations are told apart.
--
-- WHAT IT DOES
--   A. Tidies stored addresses: spaces at either end removed, capitals made
--      small, and an empty text turned into "no address" (NULL). The portal
--      now compares in small letters, so an address stored with a capital
--      would otherwise stop matching.
--   B. Adds the rule "no two organisations share an address" (the unique key
--      `uq_site_host`), BUT ONLY when no two organisations share one today.
--   C. Seeds the three addresses.
--   D. Records itself.
--
-- WHAT IT DOES NOT DO
--   - It does not choose between two organisations that already share an
--     address. It leaves both rows exactly as they are and adds no key. The
--     set-up page then names the two and refuses the "own address each" way
--     until an administrator has changed one of them. Deleting or blanking
--     one to make the key fit would be this file deciding which organisation
--     loses its address.
--   - Once such a clash has been put right by hand, the key is still missing
--     on that database: this file has run and is not run again. The portal
--     does not depend on the key. The save handler for an organisation
--     (web/_apps/admin/sites/save.php) refuses a shared address itself.
--   - It does not repair an address that is not a plain host name (one stored
--     with "https://" or a path). No request can ever match such a value; the
--     set-up page says which organisation has one.
--   - It adds no setting and changes no setting's value.
--   - Nothing here has been run on MariaDB; no test in this repository covers
--     it.
--
-- SAFE TO RUN TWICE, AND SAFE IF IT STOPS HALF WAY
--   A changes only rows that are not already tidy, so a second run changes
--   none. B asks first whether the key is there. C leaves an address that is
--   already seeded as it is. The installer relies on this: it replays every
--   numbered migration after the install script.
--
-- A unique key allows any number of rows with NO address (NULL) in MySQL and
-- in MariaDB, so organisations without an address of their own are unaffected.
--
-- The structure change uses the information_schema + PREPARE form, because
-- MySQL 8 refuses MariaDB's `IF [NOT] EXISTS` on ALTER (error 1064). See
-- DEV_NOTES.md, "Portable DDL convention".
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/502
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- 🧹  A. Tidy the stored addresses
-- #############################################################################
-- The two sides are compared as raw bytes (CAST … AS BINARY). The column's own
-- way of comparing text ignores capitals and spaces at the end, so without the
-- cast "North.Example.org" would look "already tidy" and never be changed.
-- `hostPattern = ''` catches the empty text and text made only of spaces.

UPDATE `tblSites`
SET `hostPattern` = NULLIF(LOWER(TRIM(`hostPattern`)), '')
WHERE `hostPattern` IS NOT NULL
  AND (`hostPattern` = ''
       OR CAST(`hostPattern` AS BINARY) <> CAST(LOWER(TRIM(`hostPattern`)) AS BINARY));


-- #############################################################################
-- 🔑  B. No two organisations share an address
-- #############################################################################
-- Two questions, then one statement that runs only when the key is missing
-- AND no address is held twice. The count of shared addresses groups them the
-- way the key itself would compare them (capitals ignored), so "the key would
-- fit" and "nothing is shared" are the same test.

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSites'
      AND INDEX_NAME   = 'uq_site_host'
);
SET @shared_addresses := (
    SELECT COUNT(*) FROM (
        SELECT `hostPattern` FROM `tblSites`
        WHERE `hostPattern` IS NOT NULL
        GROUP BY `hostPattern`
        HAVING COUNT(*) > 1
    ) AS `shared`
);
SET @sql := IF(@idx_exists = 0 AND @shared_addresses = 0,
    'ALTER TABLE `tblSites` ADD UNIQUE KEY `uq_site_host` (`hostPattern`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🧭  C. Three addresses
-- #############################################################################
--   site/choose             the chooser. Signed-in people only.
--   admin/sites/setup       "How organisations are told apart". The page
--                           itself lets in global administrators only.
--   admin/sites/setup/save  its save handler.
-- The statement is the install script's own, so the two cannot disagree. None
-- of the three ends in `.php`: the portal refuses any address that does.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('site/choose',            'site/choose.php',            1),
    ('admin/sites/setup',      'admin/sites/setup.php',      1),
    ('admin/sites/setup/save', 'admin/sites/setup-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋  D. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('212_which_organisation.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
