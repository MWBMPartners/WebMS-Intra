-- =============================================================================
-- Migration 250: a translation's cost belongs to an organisation (#765)
-- =============================================================================
-- One new column, one backfill, one index. Nothing is dropped.
--
-- -----------------------------------------------------------------------------
-- WHAT IS WRONG, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- An organisation can hold itself to a smaller monthly translation budget than
-- the installation allows — that is deliberate, and it is how every limit here
-- works (D-013). The SPENDING those budgets are compared against could not be
-- told apart: `tblContentTranslation` had no organisation column, so the sum
-- covered the whole installation.
--
-- On an installation serving several organisations, **one organisation's
-- spending counted against every organisation's budget.** A busy church could
-- exhaust a small one's allowance without either of them doing anything wrong,
-- and the small one would simply find translation had stopped working with no
-- explanation it could act on.
--
-- -----------------------------------------------------------------------------
-- THE BACKFILL, AND WHY SOME ROWS KEEP NO ORGANISATION
-- -----------------------------------------------------------------------------
-- A row records which TABLE and which ROW it translated, so the organisation
-- can be looked up through the thing itself. The three tables that can be
-- translated (`ContentTranslation::SOURCES`) are announcements, projects and
-- project updates, and each knows its own organisation.
--
-- **A row whose source has since been deleted keeps NULL, deliberately.** It
-- cannot be attributed to anybody without guessing, and a guess here moves real
-- money between two parties' budgets. A NULL row counts against nobody, which
-- under-states history slightly and is the only answer that cannot be wrong.
-- The alternative — attributing every orphan to organisation 1 — would charge a
-- named organisation for spending that may not be theirs.
--
-- -----------------------------------------------------------------------------
-- WHY THERE IS NO FOREIGN KEY
-- -----------------------------------------------------------------------------
-- Deliberate. A cascade from `tblSites` would delete the spending record when
-- an organisation is removed, and the record of what an installation was
-- charged should outlive the organisation that caused it. The column is read
-- only for summing, never for joining to a live site row.
--
-- Safe to run twice: the column is added only when absent, the backfill only
-- touches rows that are still NULL, and the record is an upsert.
-- =============================================================================

-- #############################################################################
-- 🏢 A. The column
-- #############################################################################
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` with ERROR 1064 (see .claude/rules/database.md).

SET @hasSite := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblContentTranslation'
      AND `COLUMN_NAME`  = 'siteID'
);
-- ⚠️ NO APOSTROPHES IN THIS COMMENT, AND THAT IS NOT A STYLE CHOICE. The text
--    sits inside a quoted string, inside another quoted string that PREPARE
--    reads. A word like "organisation's" needs doubling at BOTH levels, and
--    getting it wrong gives ERROR 1064 — measured on MySQL 8.0.36, where this
--    migration would have failed on every existing installation. The longer
--    explanation lives in the header above and on the column in
--    `full_schema.sql`, where there is only one level of quoting.
SET @sql := IF(@hasSite = 0,
    'ALTER TABLE `tblContentTranslation` ADD COLUMN `siteID` INT DEFAULT NULL COMMENT ''Which organisation this content belonged to, so one monthly budget can be told apart from another (#765). NULL means the source row has gone, so it cannot be attributed without guessing. See migration 250.''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📇 B. The index the monthly sum reads
-- #############################################################################
-- The sum is "this organisation, this month", so both columns together.

SET @hasIdx := (
    SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblContentTranslation'
      AND `INDEX_NAME`   = 'idx_ct_site_dated'
);
SET @sql := IF(@hasIdx = 0,
    'ALTER TABLE `tblContentTranslation` ADD KEY `idx_ct_site_dated` (`siteID`, `translatedAt`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🔁 C. The backfill, through each source table's own organisation
-- #############################################################################
-- Only rows still NULL, so a second run changes nothing.

UPDATE `tblContentTranslation` `ct`
  JOIN `tblAnnouncements` `a` ON `a`.`announcementID` = `ct`.`sourceID`
   SET `ct`.`siteID` = `a`.`siteID`
 WHERE `ct`.`siteID` IS NULL AND `ct`.`sourceTable` = 'tblAnnouncements';

UPDATE `tblContentTranslation` `ct`
  JOIN `tblProject` `p` ON `p`.`projectID` = `ct`.`sourceID`
   SET `ct`.`siteID` = `p`.`siteID`
 WHERE `ct`.`siteID` IS NULL AND `ct`.`sourceTable` = 'tblProject';

UPDATE `tblContentTranslation` `ct`
  JOIN `tblProjectUpdate` `u` ON `u`.`updateID` = `ct`.`sourceID`
  JOIN `tblProject` `p2` ON `p2`.`projectID` = `u`.`projectID`
   SET `ct`.`siteID` = `p2`.`siteID`
 WHERE `ct`.`siteID` IS NULL AND `ct`.`sourceTable` = 'tblProjectUpdate';

-- #############################################################################
-- 📋 D. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('250_translation_spend_per_organisation.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
