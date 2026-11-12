-- =============================================================================
-- Migration 287: a scheduled job that dies on every run is reported (#817)
-- =============================================================================
-- What this changes:
--
--   A. `tblScheduledJobRun` gains `unfinishedSinceUtc`: when the OLDEST run in
--      the current unbroken streak of runs with no finish began.
--   B. `tblScheduledJobRun` gains `unfinishedRuns`: how many runs that streak
--      holds, the newest included.
--   C. A row whose newest run has no finish today is carried into a streak of
--      one, starting at that run's start — the only thing known about it.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- Each run's `ScheduledJobs::began()` cleared the finish and reset the start.
-- So a job that died part-way on EVERY run always had a fresh start and no
-- finish, and the Check this portal page said "part-way through … reload this
-- page in a few minutes" about it for ever, under an all-clear. With D-076
-- every allowance is at least three times the job's gap, so that applied to
-- every frequent job by construction.
--
-- With these two facts kept, `ScheduledJobs::state()` judges a job with no
-- finish by how long it has gone WITHOUT ANY RUN FINISHING, against the job's
-- own allowance (D-070's rule applied to finishes). A job called again while
-- its previous run is genuinely still going is not reported, because one of
-- the two soon finishes and ends the streak.
--
-- Until this has run, `ScheduledJobs` asks whether both columns are there
-- (`SHOW COLUMNS`) and, when they are not, records and reads exactly as it did
-- before #817 — files reach a server before anybody presses "Run migrations".
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` (see .claude/rules/database.md). SAFE TO RUN
-- TWICE: each column is added only when it is missing, and step C touches only
-- rows whose streak was never recorded (`unfinishedRuns` = 0 with no finish),
-- which a second run finds none of. Every identifier is backticked. Each
-- definition is word for word what `full_schema.sql` says, in the same place
-- (after `lastOutcome`), so an upgraded database and a fresh one agree.
-- =============================================================================

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblScheduledJobRun'
      AND `COLUMN_NAME`  = 'unfinishedSinceUtc'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblScheduledJobRun` ADD COLUMN `unfinishedSinceUtc` DATETIME NULL DEFAULT NULL COMMENT ''A MOMENT IN UTC (#817): when the OLDEST run in the current unbroken streak of runs with no finish began. EMPTY whenever the newest run has finished. began() never clears it while runs keep failing to finish, so a job that dies on EVERY run is judged by how long it has gone without finishing anything, not by its newest start'' AFTER `lastOutcome`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblScheduledJobRun'
      AND `COLUMN_NAME`  = 'unfinishedRuns'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblScheduledJobRun` ADD COLUMN `unfinishedRuns` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''How many runs in a row, the newest included, have begun and not recorded a finish (#817). 0 once a run finishes. Shown on the Check this portal page as the last N runs each started and never finished'' AFTER `unfinishedSinceUtc`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- C. A run already in flight (or already dead) when this runs: a streak of one,
--    from its own start. Nothing is invented — the start is the row's own.
UPDATE `tblScheduledJobRun`
   SET `unfinishedSinceUtc` = `startedAtUtc`, `unfinishedRuns` = 1
 WHERE `finishedAtUtc` IS NULL
   AND `unfinishedRuns` = 0;

INSERT INTO `tblMigrations` (`filename`) VALUES ('287_scheduled_jobs_count_unfinished_runs.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
