-- =============================================================================
-- Migration 305: a membership records when it was switched off (#934; D-211)
-- =============================================================================
-- What this changes: `tblUserSites` gains `leftAtUtc` (DATETIME, empty by
-- default), placed after `joinedAt`.
--
-- WHY. D-211: the records of somebody agreeing to the privacy notice, and of
-- their cookie choices, are deleted the privacy data-retention period after
-- they LEAVE an organisation. To count that period the portal has to know when
-- the membership was switched off. Offboarding now stamps the moment, and a
-- rehire empties it. Only `Portal\Core\Memberships` writes it.
--
-- THE COLUMN HOLDS A MOMENT IN UTC, not a reading of a wall clock. `joinedAt`
-- beside it is in the database session's zone, so the two do not share a clock
-- and the name says so.
--
-- EXISTING ROWS STAY EMPTY ON PURPOSE. A membership switched off before this
-- migration has no date, and none is invented: D-211 rules out a guess ("those
-- have none"). Dating them from `tblOffboarding.createdAt` was considered and
-- rejected for that reason. Nothing is backfilled. An empty date means the
-- clock has not started, so the clear-out never deletes because of it.
--
-- NO CHECK CONSTRAINT ("switched on means no date"): considered and rejected.
-- The reader requires `isActive = 0` already, and a constraint would add an
-- engine-dependent rule to the end-to-end harness for no extra safety.
--
-- HOW IT IS WRITTEN. `information_schema` + PREPARE, because MySQL 8 rejects
-- MariaDB's `ADD COLUMN IF NOT EXISTS` (.claude/rules/database.md). Safe to
-- run twice. The definition is word for word what `full_schema.sql` says.
-- =============================================================================

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblUserSites'
      AND `COLUMN_NAME`  = 'leftAtUtc'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblUserSites` ADD COLUMN `leftAtUtc` DATETIME NULL DEFAULT NULL COMMENT ''A MOMENT IN UTC: when this membership was last switched off (isActive set to 0). Written only through the Memberships class: offboarding stamps it, a rehire empties it. Empty on a switched-on membership, and on one switched off before migration 305 or put back from a backup taken before it: nobody knows when, and no date is guessed (D-211). The consent records of a member who has left are deleted the privacy data-retention period after it (D-166, #934)'' AFTER `joinedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('305_membership_leaving_date.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
