-- =============================================================================
-- Migration 253: every scheduled job records that it ran (#775)
-- =============================================================================
-- One new table. No existing table is changed, no column is added or removed,
-- and NO ROW IS WRITTEN -- which is deliberate, and is the whole of D-071.
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG
-- -----------------------------------------------------------------------------
-- Fifteen addresses under `cron/` are called by whatever runs scheduled tasks
-- on a customer's hosting. Only ONE of them left any trace that it had run:
-- the mail queue, through `tblMailRouteState`.`jobLastRanAt`.
--
-- So a portal could silently stop
--
--   * sending event reminders and the morning summary;
--   * clearing out personal data past its retention period;
--   * taking off-site backups;
--   * sending text messages held for quiet hours;
--   * retrying payment messages that failed;
--
-- and nothing anywhere would show it. No error, no log line, no badge. The
-- jobs simply did not run, and the first sign was somebody asking why their
-- reminders had stopped.
--
-- It matters more than it looks because several of these jobs ARE the
-- mechanism behind a promise the portal makes to a member or a regulator. The
-- retention sweep is how personal data stops being kept. If it has not run for
-- six months, the portal is keeping records it told people it would delete,
-- and until now the only way to find that out was to query the tables by hand.
--
-- -----------------------------------------------------------------------------
-- WHY THE TABLE IS KEYED BY JOB AND NOT BY TOKEN
-- -----------------------------------------------------------------------------
-- `PortalCheck::JOB_TOKENS` lists the eleven tokens and reports a real problem
-- for any job whose token was never set. It could not be the spine of this,
-- because **a token is not a job**: `maintenance.cronToken` alone covers five
-- of them -- the mail queue, the retention sweep, the backup run, the backup
-- check and the health check. Keyed by token, "the backup has not run for a
-- month" would be indistinguishable from "the mail queue ran a minute ago".
--
-- The key is each job's own address under `cron/`, because that name is
-- already unique, already registered in `tblRoutes`, and already the thing an
-- administrator was given to put in their scheduler.
--
-- -----------------------------------------------------------------------------
-- WHY NO ROW IS WRITTEN HERE (D-071, the owner, 6 November 2026)
-- -----------------------------------------------------------------------------
-- On the day these files land, no job on any portal has ever written a record.
-- A job with no row is reported as "nothing is known yet" -- not as a warning,
-- and not by writing a run time so that nothing looks overdue.
--
-- Warning straight away would give every correctly-configured portal a page of
-- warnings the morning after it upgrades, about jobs that are running
-- perfectly well, with no way to clear them but to wait. That is a false alarm
-- by construction, affecting every customer at once, and **a check that cries
-- wolf gets switched off (#483)**.
--
-- Backfilling a run time was ruled out for a different and stronger reason: it
-- would write a record saying a job ran when nobody knows whether it did --
-- inventing evidence in the one place whose purpose is telling an
-- administrator what is actually true.
--
-- This weakens nothing. A portal whose jobs were never set up is still caught,
-- and caught better, by the existing token check: a job with no token has
-- never run and never will, and that IS knowable from the database.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- `CREATE TABLE IF NOT EXISTS` and an upsert of the record. `IF NOT EXISTS` on
-- a TABLE is plain MySQL and plain MariaDB -- it is `IF NOT EXISTS` on a
-- COLUMN or an INDEX that is MariaDB-only and must never appear here.
--
-- A SECOND RUN DOES NOT EMPTY THE TABLE and must not: by then real jobs have
-- recorded real runs, and dropping them would make a healthy portal read as
-- "nothing is known yet" again.
-- =============================================================================

-- #############################################################################
-- A. The table
-- #############################################################################
-- NO APOSTROPHES IN ANY COMMENT TEXT below. Each sits inside a quoted string,
-- so a word like "organisation's" would need doubling, and getting that wrong
-- gives ERROR 1064 on every installation. Migration 250 nearly shipped with it.

CREATE TABLE IF NOT EXISTS `tblScheduledJobRun` (
    `jobKey`        VARCHAR(40) NOT NULL COMMENT 'Which scheduled job the row is about, as its own address under cron/ -- the name already registered in tblRoutes and already given to whoever runs the scheduled tasks. Portal\\Core\\ScheduledJobs::JOBS is the one list',
    `startedAtUtc`  DATETIME    NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when this job last BEGAN, recorded only after its token was accepted. A request with the wrong token writes nothing, or anybody on the internet could make a dead job look alive',
    `finishedAtUtc` DATETIME        NULL DEFAULT NULL COMMENT 'A MOMENT IN UTC: when this job last finished its work. EMPTY means the newest run began and never reached the end, which is what distinguishes a job dying part-way from one that is not being called at all',
    `lastOutcome`   VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'How the last finished run ended, as one word: ok or refused. NEVER the words of a fault, which can quote the value that caused it. Empty while a run is in flight',
    PRIMARY KEY (`jobKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Whether each scheduled job has actually run (#775). Read by the Check this portal page.';

-- #############################################################################
-- B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('253_scheduled_jobs_record_their_runs.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
