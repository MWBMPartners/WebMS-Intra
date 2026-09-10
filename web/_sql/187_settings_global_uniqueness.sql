-- =============================================================================
-- Migration 187: make "save this setting" actually save, instead of adding
--                another copy every time
-- =============================================================================
--
-- THE PROBLEM, IN PLAIN TERMS
-- ---------------------------
-- A setting that applies to the whole portal is stored with its site left
-- empty (`siteID IS NULL`). A setting that applies to one site only stores
-- that site's number.
--
-- The table's rule for "no two rows may be the same" covers the pair
-- (settingKey, siteID). That works for a per-site setting. It does NOT work
-- for a portal-wide one, because in MySQL an empty value is never considered
-- equal to another empty value — not even to itself. So two rows that both
-- say ('site.name', empty) are not duplicates as far as that rule is
-- concerned.
--
-- The whole codebase saves settings with "insert this, or update it if it is
-- already there". For a portal-wide setting the "already there" half never
-- fires. Every save adds another row.
--
-- The project already half-knew this. Migration 015, which introduced the
-- rule, says in its own comment: "MySQL treats NULL as distinct in UNIQUE
-- keys". It noted that as the reason per-site overrides are possible. Nobody
-- followed the thought through to what it does to portal-wide settings.
--
-- WHERE IT SHOWS UP
-- -----------------
--   * web/_apps/admin/settings/group.php — the main settings editor. Every
--     save of any group adds rows rather than changing them.
--   * web/_apps/admin/apps/index.php — the Apps on/off switch. Every toggle
--     adds a row.
--   * The installer. It loads full_schema.sql and then replays all 186
--     numbered migrations on top. 469 of the 565 portal-wide settings are
--     seeded in both places, so a brand-new install would begin with about
--     479 surplus rows. (That figure is counted from the seed files, not
--     measured on a real database.)
--
-- WHY IT HAS NOT BEEN OBVIOUS
-- ---------------------------
-- Which copy actually takes effect depends on the order the rows come back
-- when bootstrap.php reads them, and it writes each one over the last. In
-- practice the database returns them oldest-first, so the newest copy wins —
-- which is usually the right answer, by luck rather than design. Nothing in
-- SQL guarantees that order.
--
-- WHAT IT HAS ACTUALLY COST US — three real consequences, all verified
-- --------------------------------------------------------------------
--
--   1. PASSWORDS. Migration 041 raised the minimum password length from 8 to
--      12 characters, to line up with OWASP guidance. It changed only the
--      "default" column, deliberately, so as not to overwrite a length an
--      administrator had chosen. But migration 006 seeds the working value as
--      8 — and because that seed adds a row instead of updating one, it lands
--      AFTER full_schema.sql's 12 and wins. So every fresh install has been
--      requiring 8 characters while the code's own fallback, the help page at
--      /help/admin, and migration 041 all say 12.
--
--   2. THE VERSION NUMBER. App::init() prefers the `portal.version` setting
--      over web/_core/version.php whenever the setting has a value. Migration
--      003 seeds it as '0.1.0', which lands after full_schema.sql's '0.8.1'
--      and wins. So a fresh install reports itself as version 0.1.0, even
--      though version.php is described everywhere as the single source of
--      truth. The cleanest fix is to stop storing the version as a setting at
--      all, which this migration does.
--
--   3. THE EXPENSES DELETE ENDPOINT. Seeded 'false' in one place and 'true'
--      in two others, so which one applies is down to row order.
--
-- WHAT THIS MIGRATION DOES
-- ------------------------
--   A. Removes the surplus copies, keeping ONE of each. Which one is chosen
--      carefully — see section B below. It is NOT simply "the newest": that
--      would have deleted settings an administrator had actually saved.
--   B. Stops storing the portal version as a setting, so version.php is once
--      again the only place the version comes from.
--
--      The other two settings named above — the minimum password length and
--      the expenses delete endpoint — are deliberately NOT changed on an
--      existing database. A leftover seed and a deliberate choice cannot be
--      told apart from the data, and quietly overriding somebody's real
--      decision would be worse than leaving a stale one. Both are corrected
--      at the seed instead, so a NEW install lands on the right value.
--   C. Adds a small derived column, `siteScope`, that is the site number for
--      a per-site setting and -1 for a portal-wide one, and puts the "no two
--      rows may be the same" rule on (settingKey, siteScope) instead. -1 can
--      never collide with a real site, because site numbers are assigned
--      automatically and always count upward from 1.
--
--      From then on "insert this, or update it if it is already there" works
--      for portal-wide settings exactly as it always has for per-site ones.
--      No application code has to change.
--
--   The original (settingKey, siteID) rule is left in place. It is harmless,
--   it still catches per-site duplicates, and removing it would be churn for
--   no benefit.
--
-- SAFE TO RUN TWICE
-- -----------------
-- The clean-up deletes nothing on a second run. The corrections are written
-- so they match no rows once applied. Both structural changes are wrapped in
-- the project's standard "only if it is not already there" guard, because the
-- shorter MariaDB-only spelling is a syntax error on MySQL 8.0.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra
-- =============================================================================

-- #############################################################################
-- 🛡️ A. Refuse to run if the database is not in a shape we can safely fix
-- #############################################################################
-- Both checks stop the migration BEFORE anything is deleted, with a message
-- that says what to do. Failing early and loudly is much better than failing
-- half way through, after rows have already gone.

-- HOW THESE CHECKS STOP THE MIGRATION
--
-- The obvious way to raise a clear error is `SIGNAL SQLSTATE '45000'`. It
-- cannot be used here. These files are run through the prepared-statement
-- route, and MySQL 8.0 answers SIGNAL there with "ERROR 1295: This command is
-- not supported in the prepared statement protocol yet" (confirmed on
-- 8.0.36). So instead each check, when it fails, selects from a table whose
-- NAME is the message. The error reads:
--
--   Table 'yourdb.MIGRATION_187_STOPPED__...' doesn't exist
--
-- which stops the file and says what is wrong and what to do.
--
-- Belt and braces: the deletion further down ALSO carries the same conditions,
-- so even if this file were run in a way that carried on past an error,
-- nothing would be deleted while the database is in a shape we cannot safely
-- fix.

-- A1. A site numbered 0 or below would collide with the -1 marker used below
--     for "applies to every site". Site numbers are handed out automatically
--     and count upward from 1, so this should never happen — but a database
--     that was imported, or edited by hand, could contain one.
SET @badSites := (SELECT COUNT(*) FROM `tblSites` WHERE `siteID` <= 0);
SET @sql := IF(@badSites > 0,
    'SELECT 1 FROM `MIGRATION_187_STOPPED__A_SITE_IS_NUMBERED_ZERO_OR_BELOW__RENUMBER_IT_THEN_RUN_AGAIN`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A2. Duplicate PER-SITE rows should be impossible, because the original
--     uniqueness rule already covers them. If they exist, that rule has been
--     dropped or altered on this database, and the clean-up below (which only
--     handles portal-wide rows) would not be enough — the new rule would then
--     fail to be added, after the deletions had already happened.
SET @dupSite := (
    SELECT COUNT(*) FROM (
        SELECT 1 FROM `tblSettings`
         WHERE `siteID` IS NOT NULL
         GROUP BY `settingKey`, `siteID`
        HAVING COUNT(*) > 1
    ) AS `d`
);
SET @sql := IF(@dupSite > 0,
    'SELECT 1 FROM `MIGRATION_187_STOPPED__PER_SITE_SETTINGS_ARE_DUPLICATED__RESTORE_uq_setting_key_site_THEN_RUN_AGAIN`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🧹 B. Remove the surplus copies of every portal-wide setting
-- #############################################################################
-- WHICH COPY TO KEEP — this is the part that has to be got right, because the
-- copies that are dropped are gone for good.
--
-- The obvious rule, "keep the newest row", is WRONG. Several handlers find the
-- row to change with an unordered `SELECT ... LIMIT 1` and then update that
-- one — `web/_apps/payments/save.php:38` is the clearest example, and the
-- CAPTCHA, SMS, translation and integration screens do the same. The database
-- is free to hand back any of the copies, and in practice hands back the
-- OLDEST. So an administrator's saved payment credentials can be sitting on an
-- old row while an untouched seed sits on a newer one. Keeping the newest
-- would throw the real setting away.
--
-- So the winner is chosen by three things, in order:
--
--   1. Does it look edited?  A row whose value differs from its own default
--      has been changed by somebody. That beats a row still sitting on its
--      default, no matter which is newer.
--
--      The comparison is deliberately made byte for byte (CAST ... AS BINARY).
--      These columns are stored in a collation that treats "Example" and
--      "EXAMPLE" as the same text, so a plain comparison would decide that an
--      administrator who only changed the capitalisation had not changed
--      anything — and their row could then be the one thrown away.
--   2. Failing that, which was written most recently (`updatedAt`).
--   3. Failing that, the higher row number — a last resort that can never tie,
--      because row numbers are unique.
--
-- Comparing the three as a group gives a strict order with exactly one winner
-- per setting, so this can never delete every copy.
--
-- Written as a plain self-join, not a sub-query: MySQL refuses a sub-query
-- that reads the same table it is deleting from. Per-site rows are untouched.

DELETE `loser`
  FROM `tblSettings` AS `loser`
 INNER JOIN `tblSettings` AS `winner`
    ON `winner`.`settingKey` = `loser`.`settingKey`
   AND `winner`.`siteID` IS NULL
   AND (
            ((CAST(`winner`.`settingValue` AS BINARY) <=> CAST(`winner`.`defaultValue` AS BINARY)) = 0),
            COALESCE(`winner`.`updatedAt`, '1970-01-01 00:00:00'),
            `winner`.`settingID`
       ) > (
            ((CAST(`loser`.`settingValue` AS BINARY) <=> CAST(`loser`.`defaultValue` AS BINARY)) = 0),
            COALESCE(`loser`.`updatedAt`, '1970-01-01 00:00:00'),
            `loser`.`settingID`
       )
 WHERE `loser`.`siteID` IS NULL
   -- Refuse to delete anything if the checks at the top of this file found a
   -- problem. They already stop the file; this is the second lock on the door.
   AND NOT EXISTS (SELECT 1 FROM `tblSites` WHERE `siteID` <= 0)
   AND NOT EXISTS (
        SELECT 1 FROM (
            SELECT 1 FROM `tblSettings`
             WHERE `siteID` IS NOT NULL
             GROUP BY `settingKey`, `siteID`
            HAVING COUNT(*) > 1
        ) AS `perSiteDupes`
   );

-- #############################################################################
-- 🗑️ C. Stop storing the portal version as a setting
-- #############################################################################
--     App::init() used to prefer this setting over web/_core/version.php
--     whenever it had a value, so a stale copy here silently overrode the file
--     that every document calls the single source of truth. Migration 003
--     seeds it as '0.1.0', which is what won on a fresh install — so the
--     portal reported itself as version 0.1.0.
--
--     App.php no longer reads it at all, so the row has no purpose and can
--     only mislead. Nothing else in web/_core or web/_apps reads this key.
--
--     NOTE: the two other settings this migration originally "corrected" —
--     the minimum password length and the expenses delete endpoint — are
--     deliberately NOT changed here any more. There is no reliable way to tell
--     an untouched seed from a value an administrator chose on purpose, and
--     changing a chosen value is worse than leaving a stale one. Both are
--     instead fixed at the source, in the seeds, so a fresh install lands on
--     the right value without touching any existing database. See the note in
--     full_schema.sql next to api.expenses.delete.enabled.

DELETE FROM `tblSettings` WHERE `settingKey` = 'portal.version';

-- #############################################################################
-- 🔑 D. Make the uniqueness rule work for portal-wide settings
-- #############################################################################

-- D1. The derived column. Its value is worked out by the database and cannot
--     be written directly: the site number for a per-site setting, -1 for a
--     portal-wide one.
--
--     It is VIRTUAL rather than STORED, and that matters. MySQL refuses a foreign key with ON DELETE CASCADE on a column that a
--     STORED derived column is built from — and tblSettings.siteID has exactly
--     that cascade, so a site's overrides disappear with the site. The first
--     attempt at this used STORED and full_schema.sql would not load at all:
--     "ERROR 1215: Cannot add foreign key constraint". VIRTUAL has no such
--     restriction, can still carry a unique key on MySQL 8.0, and costs
--     nothing to store. Proved against MySQL 8.0.36: all five behaviours
--     (no duplicate global rows, saves that update, per-site overrides
--     alongside the global row, overrides that update, and the cascade on
--     site deletion) verified working.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblSettings'
       AND COLUMN_NAME  = 'siteScope'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSettings` ADD COLUMN `siteScope` INT AS (COALESCE(`siteID`, -1)) VIRTUAL COMMENT ''Worked out by the database: the site number, or -1 when the setting applies to every site. Exists so the unique key below can cover portal-wide settings, which siteID alone cannot because MySQL never treats one empty value as equal to another.''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- D2. The rule itself. From here on, saving a portal-wide setting updates the
--     row that is already there instead of adding another one.

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblSettings'
       AND INDEX_NAME   = 'uq_setting_key_scope'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblSettings` ADD UNIQUE KEY `uq_setting_key_scope` (`settingKey`, `siteScope`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📋 E. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('187_settings_global_uniqueness.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
