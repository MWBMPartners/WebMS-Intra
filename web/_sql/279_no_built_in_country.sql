-- =============================================================================
-- Migration 279: no country is built in (#942, D-133)
-- =============================================================================
-- What this changes:
--
--   tblSmallGroups.countryCode       its DEFAULT 'GB' becomes '' (empty, meaning
--                                    "no country known"), and its comment says
--                                    so. Nothing already stored changes.
--   org.address.countryCode          its DEFAULT VALUE (the `defaultValue`
--                                    column of tblSettings, the value the
--                                    general Settings page offers to "reset
--                                    to") becomes '' where it still holds 'GB'.
--                                    The value IN FORCE (`settingValue`) is NOT
--                                    touched.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- WebMS-Intra is a product used by customers anywhere. The owner decided on
-- 11 November 2026 (D-133) that nothing defaults to Great Britain: the
-- installer ASKS for the installation's country (suggesting one from the
-- installing person's own browser time zone, with nothing looked up or sent
-- anywhere), and each organisation may choose its own, starting from the
-- installation's. The same fault as a time zone typed into the database
-- (#782, migration 273) and a place typed into it (#935, migration 274).
--
-- Until now a small group with no country typed was stored as 'GB' — by this
-- column's default, by the save handler, and by the shared address form, which
-- filled an empty country box with 'GB' before anybody saw it. The code no
-- longer does any of that (small-groups/save.php, _core/partials/
-- location-input.php, GeoLocation::normaliseAddress()); this makes the
-- database agree, so a future writer that names no country stores none rather
-- than a British one.
--
-- WHY AN EMPTY DEFAULT AND NOT NO DEFAULT. Migration 274 gave a venue's country
-- NO default, because a venue with no country is refused. A small group with
-- no country is allowed (a group can meet online, or in a place nobody has
-- typed in), and several self-tests insert groups naming only their name; with
-- no default, MySQL's strict mode would refuse those rows. Empty is not a
-- country, so it is not a built-in one.
--
-- -----------------------------------------------------------------------------
-- WHAT IT DELIBERATELY DOES NOT DO: change any value that is already stored
-- -----------------------------------------------------------------------------
-- A group holding 'GB' cannot be told from a group in Britain, and an
-- installation or organisation whose country says 'GB' cannot be told from
-- one in Britain that left it so on purpose: the organisation page has always
-- written that setting. So every stored value stays, which is the reasoning,
-- and the treatment, of migrations 273 and 274. (There is no customer
-- installation yet, the owner said on 2 November 2026; this is about doing it
-- the way a real upgrade would need.) The `defaultValue` IS changed, because it
-- is not a choice: it is what "reset to default" would put back.
--
-- Migration 180 now seeds the setting empty too, so the installer's replay of
-- 180 no longer puts 'GB' into `defaultValue`. A database that ran the OLD 180
-- has 'GB' there, which section B takes off.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `MODIFY COLUMN IF EXISTS` with ERROR 1064 (see .claude/rules/database.md).
-- The change is made only while it is still needed, so the file is SAFE TO
-- RUN TWICE. MODIFY needs the whole definition, so it is written out in full
-- and must be word for word what `full_schema.sql` says, or an upgraded
-- database and a fresh install differ.
--
-- "Still needed" is judged by the column's COMMENT, not its default, on
-- purpose: MySQL reports a text default as GB in information_schema while
-- MariaDB reports it as 'GB' with the quotes, so a test on COLUMN_DEFAULT would
-- have to know both spellings. The comment changes in the same statement and
-- reads the same on both.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/942
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 🌍 A. tblSmallGroups.countryCode: an empty default, meaning no country known
-- #############################################################################

SET @needs := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA`   = DATABASE()
      AND `TABLE_NAME`     = 'tblSmallGroups'
      AND `COLUMN_NAME`    = 'countryCode'
      AND `COLUMN_COMMENT` <> 'ISO 3166-1 alpha-2; empty = no country known'
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblSmallGroups` MODIFY COLUMN `countryCode` CHAR(2) NOT NULL DEFAULT '''' COMMENT ''ISO 3166-1 alpha-2; empty = no country known''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🏢 B. org.address.countryCode: the value to "reset to" is empty
-- #############################################################################
-- Only the installation-wide row, and only while it still holds the old
-- default. `settingValue` is left alone on purpose (see the header).

UPDATE `tblSettings`
   SET `defaultValue` = ''
 WHERE `settingKey`   = 'org.address.countryCode'
   AND `siteID` IS NULL
   AND `defaultValue` = 'GB';

-- #############################################################################
-- 📝 C. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('279_no_built_in_country.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
