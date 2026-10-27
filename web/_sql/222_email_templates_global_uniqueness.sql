-- =============================================================================
-- Migration 222: stop the installer adding another copy of every portal-wide
--                email template each time it runs
-- =============================================================================
-- Path: _sql/222_email_templates_global_uniqueness.sql
-- Description: Gives tblEmailTemplates the same protection migration 187 gave
--              tblSettings, because it has exactly the same fault.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- THE PROBLEM, IN PLAIN TERMS
-- ---------------------------
-- A template that applies to the whole portal is stored with its site left
-- empty (`siteID IS NULL`); one belonging to a single organisation stores that
-- organisation's number.
--
-- The table's existing rule for "no two rows may be the same" covers the pair
-- (siteID, templateKey). That works for an organisation's own template. It does
-- NOT work for a portal-wide one, because MySQL never treats one empty value as
-- equal to another — not even to itself. So two rows that both say
-- (empty, 'auth.passwordReset') are not duplicates as far as that rule is
-- concerned.
--
-- Migration 051 seeds the three portal-wide templates and ends with
-- `ON DUPLICATE KEY UPDATE`, so it LOOKS safe to run twice. It is not: the
-- "already there" half can never fire for a row whose siteID is empty. The
-- installer replays every numbered migration after full_schema.sql, so every
-- pass over an existing database adds another three rows — 3, then 6, then 9.
-- Measured on MySQL 8.0.36 and 8.4.11 when #594 was written.
--
-- This is the same trap, in the same shape, that migration 187 fixed for
-- settings. Migration 015's own comment noted that MySQL treats NULL as
-- distinct in unique keys, and that it is what makes per-site overrides
-- possible; nobody followed the thought through to the portal-wide rows, twice.
--
-- WHAT IT COSTS TODAY
-- -------------------
-- `_apps/admin/email-templates/edit.php` reads one template with
-- `WHERE templateKey = ? AND (siteID IS NULL OR siteID = ?)` and no ORDER BY.
-- With duplicates present, WHICH copy an administrator sees and edits is
-- whatever the database happens to return first. Nothing is lost, but an edit
-- can appear not to have saved, because the next read may find a different
-- copy. It also breaks this project's rule that a migration must be safe to
-- run twice.
--
-- WHY A GENERATED COLUMN, AND WHY VIRTUAL
-- ---------------------------------------
-- Exactly as migration 187: a unique key cannot be placed on "siteID, treating
-- every empty value as the same", so the database works out that value into a
-- column of its own and the key goes on THAT. It is VIRTUAL rather than STORED
-- because `fk_template_site` is a foreign key with ON DELETE CASCADE, and MySQL
-- refuses such a key on a table with a STORED generated column (ERROR 1215).
-- VIRTUAL has no such restriction, carries a unique key perfectly well on
-- MySQL 8.0, and costs nothing to store.
--
-- SAFE TO RUN TWICE: every step below is guarded, and the de-duplication is
-- written so that a second run finds nothing left to remove.
-- =============================================================================

-- #############################################################################
-- 🧹 A. Remove the surplus copies, keeping exactly one per template key
-- #############################################################################
-- The winner is the most recently updated row, with the highest templateID to
-- break a tie. Comparing the pair as a group gives a strict order with exactly
-- one winner per key, so this can never delete every copy.
--
-- WHY "NEWEST" IS SAFE HERE, AND WHY MIGRATION 187 CHOSE DIFFERENTLY.
-- 187 refused "newest wins" for settings, because a replay re-seeds a setting
-- and the fresh copy would be newer than an administrator's edit, so the edit
-- would be the one deleted. It therefore asked "does this row differ from its
-- own default?" first.
--
-- That cannot happen to these rows, because **nothing in the portal ever
-- writes a portal-wide e-mail template.** `_apps/admin/email-templates/save.php`
-- takes its site number from `Site::id()`, which is always a real
-- organisation, and both its save and its revert name that number — the page
-- writes and removes SITE OVERRIDES only, and says so ("Template saved as site
-- override"). The portal-wide rows are therefore always migration 051's seed
-- text, so the copies being compared here are identical but for their
-- timestamps, and keeping the newest keeps the CURRENT release's wording if a
-- later release changed it.
--
-- THE ONE EXPOSURE, said plainly rather than left for somebody to find: if a
-- customer has edited a portal-wide row by hand in the database, and a replay
-- of 051 has since inserted a fresh seed copy, this keeps the seed and removes
-- the hand-made wording. Nothing in the product can produce that state, and an
-- earlier version of this comment claimed the winner was "the row an
-- administrator most likely edited", which was simply not true of these rows.
-- If a later change ever lets the portal write a portal-wide template, this
-- de-duplication must gain 187's "differs from its default" test first.
--
-- Written as a plain self-join, not a sub-query: MySQL refuses a sub-query that
-- reads the same table it is deleting from. Rows belonging to an organisation
-- are untouched — the existing key already protects those correctly.

DELETE `loser`
  FROM `tblEmailTemplates` AS `loser`
 INNER JOIN `tblEmailTemplates` AS `winner`
    ON `winner`.`templateKey` = `loser`.`templateKey`
   AND `winner`.`siteID` IS NULL
   AND (
            COALESCE(`winner`.`updatedAt`, '1970-01-01 00:00:00'),
            `winner`.`templateID`
       ) > (
            COALESCE(`loser`.`updatedAt`, '1970-01-01 00:00:00'),
            `loser`.`templateID`
       )
 WHERE `loser`.`siteID` IS NULL;

-- #############################################################################
-- 🏗️ B. The worked-out column, then the rule that uses it
-- #############################################################################

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEmailTemplates'
       AND COLUMN_NAME  = 'siteScope'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEmailTemplates` ADD COLUMN `siteScope` INT AS (COALESCE(`siteID`, -1)) VIRTUAL COMMENT ''Worked out by the database: the organisation number, or -1 when the template applies to every organisation. Exists so the unique key below can cover portal-wide templates, which siteID alone cannot because MySQL never treats one empty value as equal to another.''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblEmailTemplates'
       AND INDEX_NAME   = 'uq_template_key_scope'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEmailTemplates` ADD UNIQUE KEY `uq_template_key_scope` (`templateKey`, `siteScope`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The older key is deliberately LEFT IN PLACE. It is still correct for an
-- organisation's own template, `fk_template_site` needs an index on siteID to
-- stand on, and dropping it would be a change with nothing to gain.

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('222_email_templates_global_uniqueness.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
