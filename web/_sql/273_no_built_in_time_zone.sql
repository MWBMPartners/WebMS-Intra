-- =============================================================================
-- Migration 273: no time zone is built in (#782)
-- =============================================================================
-- What this changes:
--
--   tblLivestreamSchedule.timezone   loses its DEFAULT 'Europe/London' and
--                                    gains a comment saying what it holds.
--   tblVenues.timezone               loses its DEFAULT 'Europe/London'. Its
--                                    comment is left exactly as migration 217
--                                    wrote it (see "WHY THE VENUE COMMENT IS
--                                    NOT CHANGED" below).
--   portal.sabbath.timezone          its DEFAULT VALUE (the `defaultValue`
--                                    column of tblSettings) becomes '' where
--                                    it still holds 'Europe/London'. The value
--                                    IN FORCE (`settingValue`) is NOT touched.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- WebMS-Intra is a product used by customers anywhere, and a zone typed into
-- the database is the same fault as a web address typed into the code (#500).
-- Each of these three said "London" for every customer who had not noticed:
-- a livestream slot's "next: Saturday 11:00", a venue's bookings and the
-- Sabbath quiet window were all worked out on London's clock for a church in
-- Sydney. The code now names the zone itself, and takes the ORGANISATION's
-- own (Site::timezone(), #604) when nobody chose one, exactly as #604 did for
-- tblEvents.timezone, which has had no default since migration 217.
--
-- -----------------------------------------------------------------------------
-- WHAT IT DELIBERATELY DOES NOT DO: change any value that is already stored
-- -----------------------------------------------------------------------------
-- A row holding "Europe/London" cannot be told from a choice of London. Unlike
-- tblUsers.locale (#763, D-060), which nothing had ever been able to write,
-- both of these columns and the Sabbath setting HAVE always been written by
-- their own pages — `git log -S"INSERT INTO tblLivestreamSchedule" -- web/`
-- answers 4e57997 (2 June 2026), and `git log -S"INSERT INTO tblVenues" --
-- web/` answers 37bf418 (28 August 2026); both writers named a zone in every
-- INSERT from the first commit, so the column DEFAULT itself was never what a
-- portal page stored. A London that is there came from the page, and may have
-- been chosen. Emptying it would move such an organisation's times by hours
-- if it really is in London's zone and simply shares its name, so it stays.
-- Removing the DEFAULT changes nothing for any existing row: it only makes
-- the database refuse a FUTURE writer that names no zone.
--
-- The Sabbath setting's `defaultValue` is changed because it is not a choice:
-- it is the value the general Settings page offers to "reset to". The setting
-- itself keeps whatever it holds.
--
-- -----------------------------------------------------------------------------
-- WHY THE VENUE COMMENT IS NOT CHANGED
-- -----------------------------------------------------------------------------
-- Migration 217 section C rewrites tblVenues.timezone — WITH the London
-- default — whenever the column comment differs from its own text. The
-- installer replays every migration after the install script, in order, so a
-- new comment here would make 217 put the default back on every replay and
-- this file take it off again. Same end state, but a needless round trip, and
-- a trap for anyone who ever replays 217 alone. Keeping 217's comment makes
-- 217 a no-op on a database this file has already changed.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `MODIFY COLUMN IF EXISTS` with ERROR 1064 (see .claude/rules/database.md).
-- Each change is made only while it is still needed, so the file is SAFE TO
-- RUN TWICE. MODIFY needs the whole definition, so each is written out in full
-- and must be word for word what `full_schema.sql` says, or an upgraded
-- database and a fresh install differ. NO APOSTROPHES in either column
-- comment: each sits inside a quoted string inside another quoted string.
--
-- A NOT NULL column with no default reads as COLUMN_DEFAULT IS NULL in
-- information_schema on both MySQL and MariaDB, which is the test used below.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/782
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 🎥 A. tblLivestreamSchedule.timezone: no default, and a comment
-- #############################################################################

SET @needs := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblLivestreamSchedule'
      AND `COLUMN_NAME`  = 'timezone'
      AND (`COLUMN_DEFAULT` IS NOT NULL
           OR `COLUMN_COMMENT` <> 'The clock dayOfWeek, startTime and endTime are read on: an IANA zone name such as Pacific/Auckland. No default, on purpose (#782): every writer names the zone, and the schedule form takes the zone of the organisation when none is given. Turned into a clock only by Livestream::zoneOf(); the admin page also shows the stored text as it is.')
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblLivestreamSchedule` MODIFY COLUMN `timezone` VARCHAR(50) NOT NULL COMMENT ''The clock dayOfWeek, startTime and endTime are read on: an IANA zone name such as Pacific/Auckland. No default, on purpose (#782): every writer names the zone, and the schedule form takes the zone of the organisation when none is given. Turned into a clock only by Livestream::zoneOf(); the admin page also shows the stored text as it is.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🏛️ B. tblVenues.timezone: no default (comment left as migration 217 wrote it)
-- #############################################################################

SET @needs := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblVenues'
      AND `COLUMN_NAME`  = 'timezone'
      AND `COLUMN_DEFAULT` IS NOT NULL
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblVenues` MODIFY COLUMN `timezone` VARCHAR(64) NOT NULL COMMENT ''IANA timezone — booking dates/times are wall-clock LOCAL to the venue (§8.4); the same idea as tblEvents.timezone''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🕯️ C. portal.sabbath.timezone: the value to "reset to" is empty
-- #############################################################################
-- Only the installation-wide row, and only while it still holds the old
-- default. `settingValue` is left alone on purpose (see the header).

UPDATE `tblSettings`
   SET `defaultValue` = ''
 WHERE `settingKey`   = 'portal.sabbath.timezone'
   AND `siteID` IS NULL
   AND `defaultValue` = 'Europe/London';

-- #############################################################################
-- 📝 D. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('273_no_built_in_time_zone.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
