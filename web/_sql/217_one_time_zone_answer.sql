-- =============================================================================
-- Migration 217: one answer to "which time zone?", for an event and for an
--                organisation (#604)
--
-- Path: web/_sql/217_one_time_zone_answer.sql
--
-- WHY THIS EXISTS
--   The portal is a diary. An hour wrong is a congregation at a locked
--   building. Two facts were each kept in two places that could differ.
--
--   1. AN EVENT'S ZONE. `tblEvents` had two zone columns. `timezone` is what
--      the event form writes and what the event page, the calendar feed and
--      the export read. `eventTimezone` (migration 070) was written only by
--      the calendar importer, which wrote the same value into both. For every
--      event made in the portal it held its default, "Europe/London",
--      whatever zone the event was in, and the events API handed that out.
--   2. AN ORGANISATION'S ZONE. `tblSites.timezone` (set at Admin,
--      Organisations) and the `site.timezone` setting. Start-up read only the
--      setting, so most pages followed it. The mail lines on the admin
--      dashboard and the health page, the rota reminder and giving retention
--      read the row. Run on 13 October 2026 on the unchanged portal: in 9 of
--      13 combinations of the two values, one installation gave two answers.
--
-- WHAT IT DOES
--   A. Events. Drops `eventTimezone`. Then gives `timezone` a comment saying
--      what it is, and takes away its default ("Europe/London"): every
--      writer now names the zone, and one that forgets is refused by the
--      database instead of quietly storing London.
--      NOTHING IS COPIED OUT OF THE OLD COLUMN FIRST, and that is on purpose.
--      Only the calendar importer ever wrote it, always with the same value
--      it wrote into `timezone`, so it never held anything `timezone` lacked.
--      (This file used to copy it into `timezone` wherever `timezone` was
--      empty. For an event saved with its zone box emptied, the old column
--      held only its default, so that marked the event "Europe/London" for
--      every customer everywhere: the very guess this package removes. Left
--      empty, such an event is listed for an administrator and read on its
--      organisation's clock meanwhile. Found by the independent check,
--      13 October 2026.)
--   B. Organisations. Writes into each organisation's row THE ZONE ITS PAGES
--      WERE SHOWING, which is what start-up read: the organisation's own
--      `site.timezone` setting row when it has one (an empty one means UTC),
--      else the installation's, else UTC. So no ordinary page moves. Then it
--      writes a mark, the installation-wide setting `site.timezoneKeptAt`,
--      and removes every `site.timezone` row. From the mark on,
--      Portal\Core\Site::timezone() reads the organisation's row and nothing
--      else.
--   C. Corrects one comment that named the removed column (tblVenues).
--   D. Records itself.
--
-- WHAT AN INSTALLATION SEES (the owner's rule: nothing people see may move by
-- an hour because of this upgrade)
--   - Every ordinary page shows times in the zone it showed them in before.
--   - Where the two places DIFFERED, what used to follow the organisation's
--     row now follows the pages' zone: the mail lines on the admin dashboard,
--     the health page and the e-mail page (each names its zone beside the
--     moment), the moment a rota reminder stops being worth sending, giving
--     retention's "today", and the zone listed at Admin, Organisations. An
--     administrator's choice there is replaced by the zone the portal was
--     really showing. Decided by the coordinator on 13 October 2026.
--   - The text is copied EXACTLY, because a database cannot ask PHP whether a
--     text is a zone name. Site::timezone() applies start-up's own test when
--     it reads: text that is not an exact zone name answers UTC, as it
--     always did for pages, and is listed for an administrator.
--
-- SAFE TO RUN TWICE, AND WHY EACH PART IS
--   A. The drop asks information_schema whether the column is still there,
--      in a prepared statement (the MariaDB-only "IF EXISTS" on a column is
--      not used). The change to `timezone` runs only while the column is not
--      yet exactly as wanted.
--   B. RUNS ONLY WHILE THE MARK IS ABSENT. That matters more than it looks.
--      After the first run the `site.timezone` rows are gone, so a second
--      run that did the work again would find "no setting" and write UTC
--      into every organisation. The mark is written BEFORE the old rows are
--      removed, so a run cut short between the two leaves a database the
--      next run finishes correctly.
--      WHY THE MARK IS A SETTING and not "the old column is still there": a
--      backup taken before this migration, put back onto a database that has
--      run it, brings back the old `site.timezone` rows and the organisations'
--      old zones, and has no mark. The upgrade then runs this file again and
--      B must do its work on those rows. The mark travels with the settings
--      it is about; a column does not.
--   The settings-table trap: a row for the whole installation has nothing in
--   its organisation column. Migration 187's key (`uq_setting_key_scope`)
--   makes two such rows impossible, so the "insert, or update" for the mark
--   cannot add a second one.
--
-- WHAT IT DOES NOT DO
--   - It does not check that any stored zone is a real one. Events and
--     organisations holding text that is not a zone are found and listed by
--     the portal (Calendar, Manage Events; Admin, Organisations; the health
--     page) and are never changed here.
--   - It does not change any event's `timezone`, whatever it holds and
--     whatever `eventTimezone` held. Where the two differed, `timezone` was
--     the one people were shown. That includes the many events of an
--     organisation outside London's zone that are marked "Europe/London"
--     because the old form filled its box with that: nothing here can tell
--     those from events truly in London. Calendar, Manage Events lists them
--     for an administrator, with one button to put them right.
--   - It does not change a venue's or a livestream schedule's zone. Those
--     still default to "Europe/London" in the table, which is older than
--     this file and not part of #604.
--   - Nothing here has been run on MariaDB; no test in this repository
--     covers it.
--
-- NOTE for editors: never put two hyphens together inside a quoted string in
-- this file. check_schema_seed_parity.py reads them as the start of a comment.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/604
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- 📅  A. An event's zone lives in ONE column, `tblEvents`.`timezone`
-- #############################################################################

-- A1. Remove the old column. From here on anything that still reads it fails
--     loudly, which is the point: a quiet wrong zone is what this replaces.
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'eventTimezone'
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblEvents` DROP COLUMN `eventTimezone`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A2. Say what the column that stays IS, and take away its default. Counts 1
--     only while the column still has a default or does not yet carry exactly
--     this comment.
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'timezone'
      AND (COLUMN_DEFAULT IS NOT NULL
           OR COLUMN_COMMENT <> 'The zone startDateTime and endDateTime are a clock reading in: an IANA name such as Pacific/Auckland. The ONE column an event keeps its zone in (#604). No default, on purpose: every writer names the zone, and takes the zone of the organisation when none is given.')
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblEvents` MODIFY COLUMN `timezone` VARCHAR(50) NOT NULL COMMENT ''The zone startDateTime and endDateTime are a clock reading in: an IANA name such as Pacific/Auckland. The ONE column an event keeps its zone in (#604). No default, on purpose: every writer names the zone, and takes the zone of the organisation when none is given.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🏛️  B. An organisation's zone lives in ONE place, `tblSites`.`timezone`
-- #############################################################################

-- B1. Write into each organisation's row what its pages were showing. Only
--     while the mark is absent (see "SAFE TO RUN TWICE" above).
--     `own` is the organisation's own setting row and `inst` the
--     installation's. Migration 187's key allows at most one of each, so
--     every organisation is matched exactly once. A row that is THERE but
--     empty counts, and means UTC: that is how start-up read it (an
--     organisation's empty row hid the installation's value).
--     LEFT(…, 50) is the width of the column written to. A setting longer
--     than that was never a zone name and answered UTC; its first 50
--     characters are not one either.
UPDATE `tblSites` s
  LEFT JOIN `tblSettings` own
         ON own.`settingKey` = 'site.timezone' AND own.`siteID` = s.`siteID`
  LEFT JOIN `tblSettings` inst
         ON inst.`settingKey` = 'site.timezone' AND inst.`siteID` IS NULL
   SET s.`timezone` = CASE
           WHEN own.`settingID` IS NOT NULL
               THEN IF(own.`settingValue` IS NULL OR own.`settingValue` = '', 'UTC', LEFT(own.`settingValue`, 50))
           WHEN inst.`settingID` IS NOT NULL
               THEN IF(inst.`settingValue` IS NULL OR inst.`settingValue` = '', 'UTC', LEFT(inst.`settingValue`, 50))
           ELSE 'UTC'
       END
 WHERE NOT EXISTS (
           SELECT 1 FROM (
               SELECT m.`settingID` FROM `tblSettings` m
                WHERE m.`settingKey` = 'site.timezoneKeptAt' AND m.`siteID` IS NULL AND m.`settingValue` = 'organisation'
           ) AS alreadyDone
       );

-- B2. The mark. Site::timezone() reads the organisation's row only once this
--     is here. A row somebody has changed is put back to the right value:
--     this file has just made it true.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'site.timezoneKeptAt', 'organisation', 'organisation', 0)
ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `defaultValue` = VALUES(`defaultValue`);

-- B3. The old setting, every organisation's row of it and the installation's.
--     Nothing reads it any more, and a row left behind would show a zone on
--     the Settings page that the portal is not using.
DELETE FROM `tblSettings` WHERE `settingKey` = 'site.timezone';

-- B4. Say what the column IS. Counts 1 only while the comment is not exactly this.
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSites' AND COLUMN_NAME = 'timezone'
      AND COLUMN_COMMENT <> 'The time zone of this organisation: an IANA name. The ONE place it is kept (#604), set at Admin, Organisations. Read only through Site::timezone(), which answers UTC for text that is not a zone name.'
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblSites` MODIFY COLUMN `timezone` VARCHAR(50) COLLATE utf8mb4_general_ci DEFAULT ''UTC'' COMMENT ''The time zone of this organisation: an IANA name. The ONE place it is kept (#604), set at Admin, Organisations. Read only through Site::timezone(), which answers UTC for text that is not a zone name.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🧹  C. A comment that named the removed column
-- #############################################################################
-- `tblVenues`.`timezone` said it "mirrors tblEvents.eventTimezone style".
-- Only the comment changes: the type and the default are written out again
-- exactly as they were, because MODIFY needs the whole definition.

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'timezone'
      AND COLUMN_COMMENT <> 'IANA timezone — booking dates/times are wall-clock LOCAL to the venue (§8.4); the same idea as tblEvents.timezone'
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblVenues` MODIFY COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT ''Europe/London'' COMMENT ''IANA timezone — booking dates/times are wall-clock LOCAL to the venue (§8.4); the same idea as tblEvents.timezone''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 📝  D. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('217_one_time_zone_answer.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
