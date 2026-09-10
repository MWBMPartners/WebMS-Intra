-- =============================================================================
-- Migration 188: the Server Information page
-- =============================================================================
-- Two new addresses. No tables, no columns, no settings.
--
-- WHAT THIS IS FOR, in plain terms:
--
-- Almost every install of this portal sits on shared hosting, where the person
-- running it has no command line and cannot look at the server directly. When
-- something needs diagnosing — a hosting company asking which database version
-- you are on, or a puzzling limit on how large a file you can upload — the
-- answers were scattered across three different admin screens, and some of
-- them were not shown anywhere at all.
--
-- The new Server Information page gathers them into one place: the PHP version,
-- the database product and version, the settings of the connection between
-- them, which optional parts of PHP your hosting installed, and the limits your
-- hosting has set.
--
-- It also answers a question the product previously could not answer about
-- itself: is the database version we are running on still supported? MySQL 8.0
-- reached the end of its support life in April 2026 and no longer receives
-- security fixes. The version was already displayed in several places, but
-- nothing anywhere said what it meant. Now it does — and the installation
-- wizard uses exactly the same judgement, so the two always agree.
--
--   admin/system-info          The page itself. Any administrator may open it.
--                              Nothing on it is secret: versions, limits, and
--                              which extensions are installed are the things an
--                              administrator needs to be able to read out to a
--                              support desk. The database password is not on
--                              the page at all — every connection fact is asked
--                              of the live connection rather than read from the
--                              credentials file, so the password is never even
--                              loaded into that page.
--
--   admin/system-info/phpinfo  PHP's own full report about itself. Restricted
--                              further, to umbrella administrators only, and
--                              enforced inside the page rather than here.
--                              That report describes the whole SERVER rather
--                              than one organisation on it, so on an install
--                              shared by several organisations an administrator
--                              of one of them should not necessarily see it. On
--                              an ordinary single-organisation install the owner
--                              is the umbrella administrator, so nothing is lost.
--
-- A note on why isProtected = 1 on both rows: that flag only means "you must be
-- signed in". It says nothing about which KIND of signed-in person you are.
-- The administrator check, and the stricter umbrella-administrator check on the
-- PHP report, are both made by the pages themselves — see
-- web/_apps/admin/system-info/index.php and .../phpinfo.php. Do not read these
-- two rows as the whole of the access control, because they are not.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/475
-- =============================================================================

-- #############################################################################
-- 🗺️ A. Route seeds (2 rows)
-- #############################################################################
-- Safe to run again. The installer replays every numbered migration after
-- full_schema.sql and ignores tblMigrations, so a second run has to be a
-- no-op. Re-pointing targetFile is the right thing on a replay: if the file
-- ever moves, the replay corrects the address rather than leaving a dead one.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/system-info',         'admin/system-info/index.php',   1),
    ('admin/system-info/phpinfo', 'admin/system-info/phpinfo.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋 B. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('188_server_information_page.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
