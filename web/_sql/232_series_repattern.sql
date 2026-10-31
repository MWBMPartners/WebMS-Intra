-- =============================================================================
-- Path: _sql/232_series_repattern.sql
-- Migration 232 — remember where the pattern put each date, and add the page
-- that changes a repeating series' pattern (#669)
--
-- @package   Portal\Sql
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @version   1.0.0
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/669
-- =============================================================================
--
-- WHAT WAS MISSING
--   Repeating events have shipped since #569, but on CREATION only. Changing a
--   series afterwards — moving every future date half an hour later, going
--   weekly to fortnightly, extending a run that stopped at the ceiling — had no
--   page, and was left out deliberately rather than half-built, because every
--   one of those changes adds and removes real event rows.
--
--   The question that blocked it was: how do you tell a date that is still
--   where the pattern put it from one somebody has MOVED BY HAND? If the
--   Christmas service was shifted to a different evening, re-generating the run
--   must not quietly undo that.
--
--   `tblEvents.updatedAt` cannot answer it. It carries
--   `ON UPDATE CURRENT_TIMESTAMP`, so the existing bulk-edit page
--   (`calendar/manage/series-edit`) already moves it on rows nobody edited by
--   hand — it would report a whole series as hand-edited after one bulk status
--   change.
--
-- WHAT THIS ADDS
--   TWO columns, not one. `tblEvents.patternStart` and `tblEvents.patternEnd` —
--   the moment and the finishing time the generator produced for this date. A
--   move writes both, so both are hand-editable and both must be compared:
--   comparing the start alone let a service lengthened by hand be silently
--   shortened again. Section A below adds them in that order.
--   "Edited by hand" is then WORKED OUT, by comparing it with the date's
--   current start, rather than remembered in a flag somebody has to set. The
--   same shape `externalRecurrenceKey` already uses for imported events, and
--   for the same reason: a comparison cannot be forgotten by a new writer, and
--   a flag can.
--
--   Plus the one address the new page answers at.
--
-- WHY IT IS NOT BACKFILLED, AND WHY THAT COSTS NOTHING
--   There is no honest value to backfill. Setting `patternStart = startDateTime`
--   for every existing series date would CLAIM that every one of them is
--   untouched, hand-moved dates included — and the first pattern change would
--   then move exactly the dates this column exists to protect. That is the
--   dangerous direction, so it is not done.
--
--   Leaving it empty would normally make the feature useless on every existing
--   installation, which is #671's first lesson ("a fix that only works on a
--   fresh installation is a fix for the one case where the fault is
--   invisible"). It does not here, because `SeriesRepattern::followsPattern()`
--   falls back to re-generating the series' CURRENT rule from
--   `tblRecurrenceRules` and asking whether the date is one of its dates. An
--   empty `patternStart` therefore means "ask the rule", not "give up", and a
--   date is only ever left alone for want of an origin when there is no rule
--   row either.
--
--   NULL is the right "not known" value for the same reason it is everywhere
--   else in this schema: a DATETIME has no spare value that means "no answer",
--   and 0000-00-00 is refused outright by the strict connection
--   `DbServer::connect()` makes.
--
-- WHAT IT DELIBERATELY DOES NOT DO
--   It adds no index. The column is only ever read for the events of ONE
--   series, which `idx_event_series` already narrows to a handful of rows, so
--   an index on it would be paid for on every event write and used by nothing.
--
-- SAFE TO RUN TWICE
--   The column is added only when `information_schema` says it is absent —
--   MySQL 8.0 has no `ADD COLUMN IF NOT EXISTS` (that is MariaDB only, and this
--   project's SQL has to load on both). The route insert and the self-record
--   both use `ON DUPLICATE KEY UPDATE`.
-- =============================================================================

-- #############################################################################
-- 🔁 A. Where the pattern put this date
-- #############################################################################

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEvents'
       AND COLUMN_NAME  = 'patternStart'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `patternStart` DATETIME DEFAULT NULL COMMENT ''The moment a repeat pattern produced this date, as a reading of the event''''s own clock in its own timezone - NEVER a UTC moment. Equal to startDateTime means nobody has moved it; different means somebody has. NULL means the origin is not recorded (created before migration 232, or not made by a pattern at all), and SeriesRepattern then falls back to the series rule. Written only by the calendar save handler and the series repattern page.'' AFTER `seriesID`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🕰️ AND THE FINISH TIME THE PATTERN PRODUCED, which is not a second thought.
--    A move writes BOTH the start and the finish, so both are hand-editable and
--    both have to be compared. Recording only the start was a real fault: a
--    service lengthened by hand from one hour to three was silently shortened
--    again by the next pattern change, and the preview called that date
--    "re-timed" rather than leaving it alone. Found by the independent check on
--    31 October 2026 (#669) before any of this was released.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEvents'
       AND COLUMN_NAME  = 'patternEnd'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `patternEnd` DATETIME DEFAULT NULL COMMENT ''The finish time a repeat pattern produced for this date, beside patternStart and read only with it. A move writes both, so both must still match for the date to count as untouched - comparing the start alone let a hand-lengthened service be silently shortened again (found 31 October 2026). NULL means no finish time, or an origin that is not recorded.'' AFTER `patternStart`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🗺️ B. The address the new page answers at
-- #############################################################################
-- Protected, like every other page under `calendar/manage`: a visitor who is
-- not signed in is sent to the sign-in page rather than reaching the handler,
-- and the page itself then refuses anybody who is not an administrator.
--
-- The page handles its OWN form posts, exactly as `calendar/manage/series-edit`
-- does, so there is no separate save address. That is deliberate: the preview
-- and the change have to be worked out by the same code from the same facts, or
-- the person could confirm one thing and have another happen.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/series-repattern', 'calendar/manage/series-repattern.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

-- #############################################################################
-- 📋 C. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('232_series_repattern.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
