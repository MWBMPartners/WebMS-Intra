-- =============================================================================
-- Migration 224: stop the same person being marked present twice for the same
--                event on the same day
-- =============================================================================
-- Path: _sql/224_event_attendance_person_uniqueness.sql
-- Description: Gives tblEventAttendance a rule against duplicates that actually
--              works. The existing one could never refuse anything, so the
--              "mark present" button added a second row every time it was
--              pressed.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- THE PROBLEM, IN PLAIN TERMS
-- ---------------------------
-- An attendance row names its person in one of two ways, and the table's own
-- column comments say so: `userID` holds the member's number, or it is left
-- empty and `walkinName` holds the name of somebody who simply turned up.
--
-- The old rule against duplicates covered the four columns
-- (eventID, userID, walkinName, dayDate). MySQL and MariaDB never treat one
-- empty value as equal to another in a unique key -- not even to itself -- so
-- the rule can only refuse a row when every one of its four columns is filled
-- in. **No row ever has all four filled in.** A member's row has no walk-in
-- name; a walk-in's row has no member number. So the rule refused NOTHING, for
-- any row, ever.
--
-- WHAT IT COST, and the limit of that, because the first version of this
-- header OVERSTATED it
-- ---------------------------------------------------------------------------
-- `_apps/calendar/event-attendance-mark.php` writes with `INSERT IGNORE` and
-- its comment said "insert (idempotent via unique key)". `INSERT IGNORE` leans
-- entirely on the rule above to throw the second copy away. With the rule
-- unable to fire, the second press of the button inserted a SECOND ROW instead
-- of being ignored. Measured on MySQL 8.0.36 before this migration was
-- written: two identical INSERT IGNOREs, two rows.
--
-- **No attendance figure the portal has ever SHOWN was wrong, and this header
-- first claimed otherwise.** The only page that displays these rows
-- (`_apps/calendar/event-attendance.php`) builds a map keyed by person and
-- day (`$attended['u:5:2026-10-29'] = true`) and lists walk-ins with
-- `SELECT DISTINCT walkinName`, so a second row sets the same entry and
-- changes nothing on screen. The two joins in `Discipleship.php` both feed
-- `INSERT IGNORE INTO tblPathwayProgress`, which has a unique key with no
-- empty part, so progress cannot be awarded twice either. Nothing in the
-- portal runs `COUNT(*)` over this table, and `ReportBuilder` cannot reach it.
--
-- So what the surplus row really costs is this: a duplicated
-- `markedByID`/`markedAt` trail saying two people marked the same person, a
-- table that disagrees with itself, and a figure that WOULD be wrong the
-- moment anything counts rows rather than distinct people. That last one is
-- the reason to fix it rather than rely on every future reader remembering to
-- de-duplicate.
--
-- That old comment is still the thing to learn from. It stated the guarantee
-- the schema had forgotten to implement, which is why nobody went looking.
--
-- WHY A WORKED-OUT COLUMN, AND WHY IT IS ALLOWED TO BE EMPTY
-- ----------------------------------------------------------
-- A unique key cannot be written as "one row per person, however the person is
-- named", so the database works that name out into a column of its own
-- (`attendeeRef`) and the key goes on THAT. `u:` and `w:` keep the two kinds
-- apart, so member number 12 can never collide with a walk-in called 12.
--
-- It is deliberately left EMPTY when the person is not known at all -- when
-- both `userID` and `walkinName` are empty -- and that is the point rather
-- than an oversight. `fk_attend_user` is ON DELETE SET NULL, and
-- `Portal\Core\GdprEraser` ANONYMISES these rows rather than deleting them
-- (its own reason says the aggregate attendance must survive), so an erased
-- member's row keeps the attendance but loses the name. Several erased members
-- at the same event on the same day therefore leave several rows that are
-- identical in every column.
--
-- A rule that collapsed those would delete the very counts erasure is there to
-- preserve. Because the key's last part is empty for exactly those rows, the
-- same "an empty value is never equal to another" behaviour that caused this
-- fault now does the right thing: identified people are held to one row each,
-- and anonymised rows are left alone. Proved both ways before this was written
-- -- a duplicate member was refused, and a fourth anonymised row was accepted.
--
-- VIRTUAL rather than STORED, for the same reason as migration 222:
-- `fk_attend_event` is a foreign key with ON DELETE CASCADE, and MySQL refuses
-- such a key on a table holding a STORED generated column (ERROR 1215).
-- VIRTUAL carries a unique key perfectly well and costs nothing to store.
--
-- TWO CONSEQUENCES WORTH STATING, both deliberate
-- -----------------------------------------------
-- 1. VARCHAR(140) is tied to `walkinName` being VARCHAR(120): 'w:' plus 120
--    characters is 122, and 140 leaves room. **Widening `walkinName` means
--    widening this column in the same change**, or a long name is truncated
--    and two different people could be made to collide.
-- 2. Walk-ins are now unique BY NAME for an event and day, and
--    `utf8mb4_general_ci` ignores capitals and trailing spaces -- so "Dave"
--    and "dave" are the same person here. Two different people of the same
--    name at one event can no longer both be recorded. That matches what the
--    grid always showed (it lists walk-ins with `SELECT DISTINCT walkinName`,
--    so the second was already invisible), and it is what the OLD key was
--    reaching for by including `walkinName` at all.
--
-- ONE THING THIS MAKES HARDER, said plainly: a WHOLE-DATABASE restore of a
-- snapshot taken BEFORE this migration will be REFUSED if that snapshot holds
-- duplicates, because `DbBackup::restoreAll()` stops on any rejected row. That
-- is the same limit migrations 187 and 222 carry and it is already written in
-- `DbBackup`'s own notes -- but it was rare for those and is the COMMON case
-- here, since every double-press left a duplicate. The way out: this table is
-- NOT on `DbBackup::WHOLE_DATABASE_ONLY`, so a ONE-TABLE restore of it works
-- and skips the duplicates it cannot place.
--
-- SAFE TO RUN TWICE: every step is guarded, and the clean-up is written so a
-- second run finds nothing left to remove.
-- =============================================================================

-- #############################################################################
-- 🧹 A. Remove the surplus copies, keeping the FIRST row for each person
-- #############################################################################
-- The winner is the lowest attendanceID, which is the earliest mark -- the
-- moment somebody was actually recorded present. Keeping the earliest also
-- keeps `markedByID` pointing at the coordinator who really did it.
--
-- THE `WHERE` IS LOAD-BEARING. It restricts the whole statement to rows whose
-- person is KNOWN. Without it this would collapse every anonymised row
-- (`userID` and `walkinName` both NULL) down to one and permanently reduce the
-- recorded attendance of every event an erased member ever attended. There is
-- no way to tell two erased people apart, so those rows must be left exactly
-- as they are.
--
-- "Both NULL" is meant exactly. A row holding an EMPTY NAME rather than no
-- name (`walkinName = ''`) works out to `w:`, which is not NULL, so such rows
-- ARE de-duplicated against each other. The page cannot write one -- it
-- trims the name and refuses an empty one -- so this needs hand-written or
-- legacy data, and collapsing it is the right answer anyway.
--
-- Written as a plain self-join, not a sub-query: MySQL refuses a sub-query that
-- reads the same table it is deleting from.

DELETE `loser`
  FROM `tblEventAttendance` AS `loser`
 INNER JOIN `tblEventAttendance` AS `winner`
    ON  `winner`.`eventID` = `loser`.`eventID`
   AND  `winner`.`dayDate` = `loser`.`dayDate`
   AND  COALESCE(CONCAT('u:', `winner`.`userID`), CONCAT('w:', `winner`.`walkinName`))
      = COALESCE(CONCAT('u:', `loser`.`userID`),  CONCAT('w:', `loser`.`walkinName`))
   AND  `winner`.`attendanceID` < `loser`.`attendanceID`
 WHERE COALESCE(CONCAT('u:', `loser`.`userID`), CONCAT('w:', `loser`.`walkinName`)) IS NOT NULL;

-- #############################################################################
-- 🏗️ B. The worked-out column, then the rule that uses it
-- #############################################################################

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEventAttendance'
       AND COLUMN_NAME  = 'attendeeRef'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventAttendance` ADD COLUMN `attendeeRef` VARCHAR(140) AS (COALESCE(CONCAT(''u:'', `userID`), CONCAT(''w:'', `walkinName`))) VIRTUAL COMMENT ''Worked out by the database: u: and the member number, or w: and the walk-in name. Left empty when the person is not known at all, so anonymised rows are never treated as duplicates of one another.''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEventAttendance'
       AND INDEX_NAME   = 'uq_attendance_person'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEventAttendance` ADD UNIQUE KEY `uq_attendance_person` (`eventID`, `dayDate`, `attendeeRef`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🗑️ C. Remove the old rule, because its NAME is the trap
-- #############################################################################
-- `uq_attendance_unique` refuses nothing and never could. Migration 222 chose
-- to LEAVE its equivalent in place, and that was right there: the old key still
-- worked for an organisation's own template and a foreign key needed it to
-- stand on. Neither applies here. This one is a rule in name only, and leaving
-- a key called "unique" that enforces nothing is what persuaded everybody the
-- duplicates were impossible.
--
-- Both foreign keys keep an index to stand on without it: `fk_attend_event`
-- has `idx_attendance_event_day` (eventID first), `fk_attend_user` has
-- `idx_attendance_user`, and `fk_attend_marker` has its own. Checked by
-- dropping it on a real MySQL 8.0.36 database before this was written -- the
-- server accepted it and no foreign key complained.

SET @old_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEventAttendance'
       AND INDEX_NAME   = 'uq_attendance_unique'
);
SET @sql := IF(@old_idx > 0,
    'ALTER TABLE `tblEventAttendance` DROP INDEX `uq_attendance_unique`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📋 D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('224_event_attendance_person_uniqueness.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
