-- =============================================================================
-- Migration 274: no place is built in (#935)
-- =============================================================================
-- What this changes:
--
--   tblVenues.countryCode            loses its DEFAULT 'GB'. Its comment is
--                                    unchanged.
--   portal.sabbath.location_lat      their DEFAULT VALUE (the `defaultValue`
--   portal.sabbath.location_lng      column of tblSettings) becomes '' where it
--                                    still holds Cambridge's coordinate. The
--                                    value IN FORCE (`settingValue`) is NOT
--                                    touched.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- WebMS-Intra is a product used by customers anywhere, and a place typed into
-- the database is the same fault as a time zone typed into it (#782) or a web
-- address typed into the code (#500). A new venue was British unless somebody
-- changed it, and the Sabbath quiet window's sunset was Cambridge's for a
-- church anywhere that chose "sunset". The code now names the country itself
-- (the organisation's own when the box is left empty, and a refusal when there
-- is none: Venues::countryToStore()), and the Sabbath place is empty, meaning
-- each organisation's own map position, with the fixed times when none is
-- known (Sabbath::place()).
--
-- -----------------------------------------------------------------------------
-- WHAT IT DELIBERATELY DOES NOT DO: change any value that is already stored
-- -----------------------------------------------------------------------------
-- A venue holding 'GB' cannot be told from a venue in Britain, and a Sabbath
-- place holding Cambridge's coordinates cannot be told from a church in
-- Cambridge that typed them. Both have always been written by their own pages
-- (`git log -S"INSERT INTO tblVenues" -- web/` answers 37bf418, whose INSERT
-- named `countryCode` from the first commit; the Sabbath page has written the
-- two coordinates since it was built), so a value there may be a choice, and it
-- stays — the reasoning, and the treatment, of migration 273. Removing the
-- DEFAULT changes nothing for any existing row: it only makes the database
-- refuse a FUTURE writer that names no country.
--
-- The Sabbath settings' `defaultValue` is changed because it is not a choice:
-- it is the value the general Settings page offers to "reset to".
--
-- Migration 070 seeds the Sabbath rows with `ON DUPLICATE KEY UPDATE
-- defaultValue = VALUES(defaultValue)`, so the installer's replay of 070 puts
-- Cambridge back into `defaultValue` on every replay, and this file, replayed
-- after it, takes it off again. Same end state; 273 has the same round trip
-- for the zone.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `MODIFY COLUMN IF EXISTS` with ERROR 1064 (see .claude/rules/database.md).
-- The change is made only while it is still needed, so the file is SAFE TO
-- RUN TWICE. MODIFY needs the whole definition, so it is written out in full
-- and must be word for word what `full_schema.sql` says, or an upgraded
-- database and a fresh install differ. No apostrophes in the column comment:
-- it sits inside a quoted string inside another quoted string.
--
-- A NOT NULL column with no default reads as COLUMN_DEFAULT IS NULL in
-- information_schema on both MySQL and MariaDB, which is the test used below.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/935
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 🌍 A. tblVenues.countryCode: no default
-- #############################################################################

SET @needs := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblVenues'
      AND `COLUMN_NAME`  = 'countryCode'
      AND `COLUMN_DEFAULT` IS NOT NULL
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblVenues` MODIFY COLUMN `countryCode` CHAR(2) NOT NULL COMMENT ''ISO 3166-1 alpha-2''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📍 B. portal.sabbath.location_lat / location_lng: the value to "reset to" is empty
-- #############################################################################
-- Only the installation-wide rows, and only while they still hold the old
-- default. `settingValue` is left alone on purpose (see the header).

UPDATE `tblSettings`
   SET `defaultValue` = ''
 WHERE `settingKey`   = 'portal.sabbath.location_lat'
   AND `siteID` IS NULL
   AND `defaultValue` = '52.205';

UPDATE `tblSettings`
   SET `defaultValue` = ''
 WHERE `settingKey`   = 'portal.sabbath.location_lng'
   AND `siteID` IS NULL
   AND `defaultValue` = '0.119';

-- #############################################################################
-- 📝 C. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('274_no_built_in_place.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
