-- =============================================================================
-- Path: _sql/233_restore_held_rows.sql
-- Migration 233 — keep the rows a restore cannot put back, instead of losing
-- them in silence (#694)
--
-- @package   Portal\Sql
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @version   1.0.0
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/694
-- =============================================================================
--
-- WHAT GOES WRONG WITHOUT THIS
--   A snapshot written before 27 October 2026 (the OLD form: one .json per
--   table, no `format` in the manifest) does not say that it covers the whole
--   database. `DbBackup::restoreAll()` therefore deliberately HOLDS BACK the
--   three tables on `DbBackup::WHOLE_DATABASE_ONLY` — `tblMigrations`,
--   `tblSettings` and `tblTotpBackupCodes` — because each records what has
--   already happened, and putting one back without the rest loses data. That is
--   right, and the restore says so.
--
--   But the restore DOES put `tblSites` and `tblUsers` back, and it does that by
--   replacing their rows. Two of the held-back tables are their children with
--   `ON DELETE CASCADE`:
--
--     tblSettings.siteID         -> tblSites.siteID          ON DELETE CASCADE
--     tblTotpBackupCodes.userID  -> tblUsers.userID          ON DELETE CASCADE
--
--   So the cascade deletes those rows and nothing puts them back. Measured on
--   MySQL 8.0.36: one per-organisation settings row before the restore, none
--   after, and the restore reported success.
--
--   (`tblMigrations` has no foreign key at all, so it can never go this way.
--   And a ONE-TABLE restore cannot cause this either: `restoreTable()` refuses
--   any table another table points at, and says to restore the whole snapshot
--   instead. This is a whole-database restore from an old-form snapshot only.)
--
--   What it costs a customer, since migration 231 (#677) gave every
--   organisation its own settings rows, the commonest being its postal address,
--   map position, what3words address and support e-mail — that last then
--   silently falls back to the installation's. But the cascade takes EVERY row
--   with a `siteID`, whatever its key, so an organisation's own retention
--   periods and mail-queue limits go with them. For `tblTotpBackupCodes`: every
--   person's single-use sign-in codes, INCLUDING the record of which ones have
--   already been spent — `auth/2fa/verify.php` marks a used code `isUsed = 1`
--   and keeps the row, and that record is exactly what WHOLE_DATABASE_ONLY
--   exists to protect.
--
-- WHAT THIS ADDS, AND WHAT THE OWNER DECIDED
--   `tblRestoreHeldRows` — the rows a restore is about to lose, kept so an
--   administrator can put them back or discard them deliberately.
--
--   Offered three choices on 31 October 2026, the owner chose to KEEP them in a
--   holding table (over naming them only, or writing them to a download), and
--   chose that they stay UNTIL AN ADMINISTRATOR CLEARS THEM — no timer. The
--   reason for the second: these rows exist while somebody is mid-recovery, and
--   a clock that deleted them part-way through would be the same silent loss
--   this issue is about, one step further on. What stops them being forgotten is
--   the Backup page naming them, not an expiry.
--
--   Both kinds are held, including the backup codes. That was NOT the
--   recommendation — a holding table is a second copy of authentication
--   material outside the table erasure protects — so the erasure half is not
--   optional: `GdprEraser::catalogue()` clears this table by `userID`, and it
--   must never be added to the pinned "left behind on purpose" list in
--   `tools/gdpr-coverage-selftest.php`.
--
-- WHY THERE ARE NO FOREIGN KEYS ON `siteID` AND `userID`
--   Deliberate, and the whole point. A row lands here precisely BECAUSE the
--   parent row it pointed at has just been deleted by a cascade. A foreign key
--   would refuse the insert, or a later cascade would delete the very rows this
--   table exists to keep.
--
-- WHY `rowJson` AND NOT ONE COLUMN PER FIELD
--   Two tables with different shapes, and more could join
--   `WHOLE_DATABASE_ONLY` later. The row is kept as it was read, so putting it
--   back writes exactly what was there. `DbBackup` holds an allow-list of the
--   tables it will accept here, so the contents are never a surprise: without
--   it, a table joining `WHOLE_DATABASE_ONLY` in future would start depositing
--   rows of unreviewed content into a table whose privacy handling was thought
--   through for two known shapes.
--
-- SAFE TO RUN TWICE: `CREATE TABLE IF NOT EXISTS` on a table is standard in
-- MySQL 8 (it is `IF NOT EXISTS` on a COLUMN or an INDEX that MySQL refuses and
-- only MariaDB allows — see .claude/rules/database.md).
-- =============================================================================

-- #############################################################################
-- 🗄️ A. The holding table
-- #############################################################################

CREATE TABLE IF NOT EXISTS `tblRestoreHeldRows` (
    `heldID`       INT          NOT NULL AUTO_INCREMENT,
    `tableName`    VARCHAR(64)  NOT NULL COMMENT 'The table the row came out of. Only the names on DbBackup''s own allow-list are ever written here',
    `siteID`       INT          DEFAULT NULL COMMENT 'The organisation the row belonged to, copied out of the row so the Backup page can say whose it was. NO foreign key on purpose: the row is here because that organisation''s row has just been deleted',
    `userID`       INT          DEFAULT NULL COMMENT 'The person the row belonged to, copied out of the row so GdprEraser can reach it (#694). NO foreign key, for the same reason as siteID',
    `rowJson`      JSON         DEFAULT NULL COMMENT 'The whole row as it was read, so putting it back writes exactly what was there. EMPTIED the moment the row is put back (#694): a marked row is never offered again and nothing deletes one, so keeping the copy would leave a hash of somebody''s sign-in code, or a stored setting value, here for ever and in every later snapshot. What is kept is the RECORD that it was held and returned - every other column',
    `snapshotName` VARCHAR(255) NOT NULL COMMENT 'Which snapshot was being restored when this row was taken',
    `heldAtUtc`    DATETIME     NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the restore took this row',
    `putBackAtUtc` DATETIME     DEFAULT NULL COMMENT 'A MOMENT IN UTC: when an administrator put this row back. NULL means it is still waiting. A row is never deleted by a clock - the owner decided on 31 October 2026 that a WAITING row waits until somebody puts it back or discards it. Once it is back only this record remains, with rowJson emptied',
    PRIMARY KEY (`heldID`),
    KEY `idx_heldrows_user` (`userID`),
    KEY `idx_heldrows_site` (`siteID`),
    KEY `idx_heldrows_waiting` (`tableName`, `putBackAtUtc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Rows a whole-database restore was about to lose to a cascade, kept so somebody can put them back or discard them (#694)';

-- #############################################################################
-- 📋 B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('233_restore_held_rows.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
