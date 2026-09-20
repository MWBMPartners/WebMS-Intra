-- =============================================================================
-- Migration 201: drop the anonymous check-in browser description (#530)
-- =============================================================================
-- One dropped column. No new table.
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- Every anonymous check-in (`anon-checkin-save.php`) has, since the table was
-- first created, recorded the visitor's browser description
-- (`$_SERVER['HTTP_USER_AGENT']`, up to 255 characters) into a `userAgent`
-- column alongside the scrambled sender address. Nothing anywhere in the
-- portal has ever READ that column — not the attendance report, not the
-- admin screens, not the #525 "anonymous check-ins at the door" panel that
-- was built specifically to surface figures from this table. It was written
-- for no purpose, on every check-in, whoever the visitor was.
--
-- That matters because "why do you hold this?" is the first question any
-- data-protection review asks, and there has never been an honest answer for
-- this column beyond "it was easy to capture at the time". Personal-data
-- rules do not treat "we might use it later" as a licence to collect it now.
--
-- -----------------------------------------------------------------------------
-- WHY DROP RATHER THAN JUST STOP WRITING TO IT
-- -----------------------------------------------------------------------------
-- Three options were weighed (#530's own text): stop recording it, keep it
-- for a written-down purpose, or keep a much shorter form (for example just
-- "phone" / "tablet" / "computer"). The last two were rejected — "keep for a
-- stated purpose" would be a purpose invented after the fact to justify
-- something already being done, not a real reason; and a short form needs a
-- guessing classifier, a new column and a new screen to show it on, for a
-- product question (how did people check in — own phone, kiosk, QR code)
-- that the EXISTING `source` column already answers exactly, with no personal
-- detail involved at all.
--
-- Dropping the column, rather than merely leaving it in place unused, matters
-- for a second reason beyond tidiness: an empty column with no purpose is an
-- invitation for the next person who touches this file to start filling it
-- in again, on the reasonable-looking assumption that a column that exists
-- must be there to be used. Removing it here means
-- `tools/audit-checks/check_sql_columns.py` will catch any future reference
-- to it automatically, because that check builds its column map from
-- `full_schema.sql`'s CREATE TABLE blocks plus every migration's ADD COLUMN
-- — once the column is gone from both, a stray reference is a finding, not
-- something that has to be remembered by a person.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS CANNOT DO
-- -----------------------------------------------------------------------------
-- It cannot make rows written before this migration and after it perfectly
-- comparable in every respect that ever mattered, because nothing that
-- counted a check-in ever read the dropped column in the first place —
-- `AnonymousCheckins::summaryForEvent()` and every figure it feeds are built
-- from `ipHash` and the plain counts alone, both before this migration and
-- after it, so nothing a person can see changes.
--
-- It DOES change one narrow internal detail: a row with no scrambled sender
-- address (only possible when the web server itself handed PHP an empty
-- `REMOTE_ADDR`, which is rare) that had ONLY a browser description used to
-- be included in `countDetailToClear()`'s / `clearOldDetail()`'s "still
-- holds detail" figure. After this migration such a row holds no detail of
-- any kind, so it is not included. The visible TOTAL a person sees is
-- unaffected either way; the only effect is that a day made up entirely of
-- such rows could, after a clear-out that no longer has anything to clear
-- for it, be marked as "including late arrivals" slightly differently than
-- it would have been under the old two-column rule. This is a pre-existing
-- edge case in an already-approximate figure, not a new fault introduced
-- here — see `AnonymousCheckins.php`'s own "WHAT THE FIGURES CANNOT TELL
-- YOU" section.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The `information_schema` + `PREPARE`/`EXECUTE` guard below only drops the
-- column when it is still there, exactly like migration 194's own step 6
-- (`checkValue`). On a database that already had this migration applied, the
-- column is already gone, so the guard finds nothing to do and runs a
-- harmless `SELECT 1` instead — required because the installer replays every
-- numbered migration after `full_schema.sql`, whether or not it has already
-- run.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/530
-- =============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAnonymousCheckins'
      AND COLUMN_NAME  = 'userAgent'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblAnonymousCheckins` DROP COLUMN `userAgent`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('201_drop_checkin_browser_description.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
