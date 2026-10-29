-- =============================================================================
-- Migration 228: backups a customer can keep — a schedule, a download and an
--                import
-- =============================================================================
-- Path: _sql/228_backup_download_import_schedule.sql
-- Description: Registers the three addresses that let a backup be taken on a
--              schedule, downloaded as one encrypted file, and brought back in;
--              and seeds the two settings that decide how often a scheduled
--              backup is taken and how many are kept. No table is created or
--              altered.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- WHAT WAS MISSING, AND WHY IT MATTERED
-- -----------------------------------------------------------------------------
-- `Portal\Core\DbBackup` could already take a snapshot, rehearse a restore,
-- restore one table or all of them, and tidy old ones away. The Backup page had
-- a button for each. `cron/backup-check` reported HOW OLD the newest snapshot
-- was and e-mailed when it got too old.
--
-- But nothing ever TOOK one unless somebody pressed the button, and nothing
-- could get a copy OFF the server. So the freshness alert existed to report a
-- staleness that nothing was preventing, and a customer whose hosting account
-- was lost would have lost every snapshot with it.
--
-- WHY THE DEFAULT IS DAILY RATHER THAN OFF
-- -----------------------------------------------------------------------------
-- A scheduled backup only happens if the customer points their hosting
-- company's scheduler at `/cron/backup-run`. Until they do, this setting
-- changes nothing at all. So "off" by default would mean a customer who did
-- wire it up got nothing, and would then be e-mailed by the freshness alert
-- about backups that were never going to be taken.
--
-- Daily, keeping seven, means wiring up the scheduler is the only step. Seven
-- daily snapshots of a small church database is a modest amount of disk; a
-- customer with a large database can lower it on the Backup page.
--
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Both statements are `INSERT … ON DUPLICATE KEY UPDATE`. The settings rows
-- match on `uq_setting_key_scope` (`settingKey`, `siteScope`), where
-- `siteScope` is `COALESCE(siteID, -1)` — added by migration 187 so that a NULL
-- siteID collapses to one value and DOES match, instead of piling up a new NULL
-- row on every replay.
--
-- ON REPLAY the settings rows update `defaultValue` ONLY, never `settingValue`.
-- A customer who has already chosen "weekly" keeps it; only the recorded default
-- is refreshed. Writing `settingValue` here would quietly undo their choice
-- every time the installer replays the migrations, which it does on every
-- upgrade.
-- =============================================================================

-- #############################################################################
-- ⏰ A. How often a backup is taken, and how many are kept
-- #############################################################################
-- Both are installation-wide (no siteID) and must stay so: one database serves
-- every organisation on the installation, so a backup is installation-wide by
-- nature. There is nothing for an organisation to choose here, which is why
-- these are NOT part of the limit-type settings that an organisation may lower
-- (see #668).
--
-- `frequency` is a word, not a number of hours, so that the page offers choices
-- rather than inviting somebody to type something that does not work.
-- `Portal\Cron` reads it in ONE place, `cron/backup-run.php`.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'backup.schedule.frequency', 'daily', 'daily', 0),
    (NULL, 'backup.schedule.keep',      '7',     '7',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 🔗 B. The three addresses
-- #############################################################################
-- The two staff addresses are protected (1): an administrator only, and both
-- refuse anybody who is not a GLOBAL administrator in their own handler,
-- because a backup holds every organisation's data.
--
-- `cron/backup-run` is UNPROTECTED (0), exactly like `cron/backup-check` and
-- for the same reason: a scheduler has a token and no session, so a protected
-- address would send it to the sign-in page, no backup would ever be taken, and
-- nothing would say so. The token is checked in the file itself, and an
-- installation that has not set one refuses every call.
--
-- No address ends in `.php`: this portal's `.htaccess` answers 404 for every
-- address that does, except the three pages in the web root.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/backup/download', 'admin/maintenance/backup-download.php', 1),
    ('admin/maintenance/backup/import',   'admin/maintenance/backup-import.php',   1),
    ('cron/backup-run',                   'cron/backup-run.php',                   0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('228_backup_download_import_schedule.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
