-- =============================================================================
-- Migration 252: two columns say which KIND of value they hold (#777)
-- =============================================================================
-- Two column comments. No column is added, removed or retyped, and no row is
-- touched.
--
-- -----------------------------------------------------------------------------
-- WHY A COMMENT IS WORTH A MIGRATION
-- -----------------------------------------------------------------------------
-- `tblResourceBooking.startAt` and `endAt` hold a WALL-CLOCK reading local to
-- the organisation -- "this room, Tuesday, 19:00 to 21:00" -- not a point in
-- history. Nothing said so, and the whole of #777 is that the difference is
-- invisible unless somebody writes it down:
--
--   * the booking form used to turn the reading into a moment and back, which
--     STORED the wrong hour one morning a year (a room asked for 01:00-01:30
--     was saved as 02:00-02:30, measured);
--   * three pages that show a booking read it back as a moment, so once the
--     form was fixed the member was shown an hour LATER than their booking.
--
-- Both were written by people reading a column called `startAt` of type
-- `DATETIME` and reasonably assuming it was a moment. **The comment is the fix
-- for the next person**, and it is also what lets a check find these columns
-- without anybody keeping a list: `tools/audit-checks/
-- check_clock_time_not_a_moment.py` reads the schema for columns whose comment
-- says WALL-CLOCK, so a new one is covered the day it is declared.
--
-- #777's first question of every stored time is "is this a moment or a time on
-- a clock, and does the column say which?" For 361 of the 486 time-shaped
-- columns the answer is still no. These two are the first to answer it.
--
-- Safe to run twice: `MODIFY COLUMN` sets the comment to the same text, and the
-- record is an upsert. The column's type, nullability and default are restated
-- exactly as `full_schema.sql` declares them, because MySQL's `MODIFY COLUMN`
-- replaces the whole definition -- leaving any of them out would silently
-- change the column.
-- =============================================================================

-- #############################################################################
-- A. The two comments
-- #############################################################################
-- ⚠️ NO APOSTROPHES IN THE COMMENT TEXT. It sits inside a quoted string, and a
--    word like "organisation's" would need doubling; getting that wrong gives
--    ERROR 1064 on every installation. Migration 250 nearly shipped with it.

ALTER TABLE `tblResourceBooking`
    MODIFY COLUMN `startAt` DATETIME NOT NULL
        COMMENT 'WALL-CLOCK local to the organisation, not a moment. Never pass through strtotime() or a DateTime constructor (#777).',
    MODIFY COLUMN `endAt` DATETIME NOT NULL
        COMMENT 'WALL-CLOCK local to the organisation, not a moment. Never pass through strtotime() or a DateTime constructor (#777).';

-- #############################################################################
-- B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('252_wall_clock_columns_say_so.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
