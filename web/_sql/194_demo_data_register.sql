-- =============================================================================
-- Migration 194: the list of rows the Demo Data page created (#498)
-- =============================================================================
-- One new table. No change to any existing table, and no settings or
-- addresses. (The guarded steps after the CREATE TABLE only ever change this
-- same new table, and only on a development database that ran an earlier
-- draft of this file; see "UPGRADING AN EARLIER DRAFT" below.)
--
-- WHY IT EXISTS
-- -----------------------------------------------------------------------------
-- Admin → Maintenance → Demo Data puts a few made-up people and announcements
-- into the portal for training, and later takes them out again. Until
-- 13 September 2026 it recognised "demo" rows by NUMBER: the demo accounts
-- were given the fixed numbers 9000 to 9004, and Wipe ran
--     DELETE FROM tblUsers WHERE userID >= 9000
-- (and the same for announcements, events, expense claims and tasks), in every
-- organisation at once.
--
-- Account numbers are handed out by the database, one after another, so a real
-- installation's own accounts reach 9000 as it grows. From then on Wipe would
-- have deleted real people, and everything the database removes along with
-- them. Loading was dangerous too: it used ON DUPLICATE KEY UPDATE, so real
-- accounts that already had the numbers 9000 to 9004 would have been
-- overwritten with the demo people's details.
--
-- Now the page lets the database choose every number, and at the moment it
-- creates each row it writes that row into this table. Wipe only ever
-- considers the rows named here, and deletes one only while it is still
-- exactly what was loaded. It never deletes by a range of numbers.
--
-- WHAT EACH COLUMN IS FOR
-- -----------------------------------------------------------------------------
--   tableName    which table the demo row was created in. Only the tables the
--                page itself writes to ever appear here; the page refuses to
--                wipe if it finds any other name.
--   rowID        the identity number the database gave that row.
--   siteID       the organisation the row belonged to when it was created,
--                read back from the row itself. NULL for a table that has no
--                organisation column (tblUsers: a person is not owned by one
--                organisation). Wipe leaves a row in place if it now belongs
--                to a different organisation.
--   fingerprintColumns
--                the names of the columns the fingerprint covers, in order,
--                separated by commas. That is EVERY column the row had when it
--                was created, invisible columns included, except a column the
--                database changes by itself (today only the automatic "last
--                changed" time on announcements). The list is stored, rather
--                than worked out again at wipe time, so that a column added by
--                a later upgrade does not make every demo row look changed.
--                Wipe checks such a new column separately and deletes the row
--                only while it is still empty. An entry upgraded from an
--                earlier draft holds a fixed mark here instead of a list; see
--                "UPGRADING AN EARLIER DRAFT" below.
--   fingerprint  a SHA-256 hash, as 64 hex characters, of the table name, the
--                row number and the value of every column named in
--                fingerprintColumns, taken straight after the row was created.
--                Wipe leaves the row in place if the fingerprint no longer
--                matches, and shows it to the administrator. How the hashed
--                text is built is documented in
--                web/_apps/admin/maintenance/demo-data.php.
--   createdAt    when the demo data was loaded, shown on the page.
--
-- WHY A FINGERPRINT, AND NOT ONE RECORDED VALUE
-- -----------------------------------------------------------------------------
-- An earlier draft of this table held one "check value" per row (the email
-- address, the announcement's web address, or a person's number) and Wipe
-- deleted any row still holding it. That deleted real content. The
-- announcement API replaces a title and body without changing the web
-- address, so a demo announcement rewritten into a real notice still matched.
-- And a real announcement given a demo row's old number and the same web
-- address, even in a different organisation, matched too. A random part in
-- the value does not help, because a value can be copied. The fingerprint
-- covers the content, the organisation and the creation time together, so an
-- edit or a replacement no longer matches. It is a hash rather than a copy of
-- the values so the list holds no names, addresses or announcement text.
--
-- A second draft fingerprinted only the columns the page itself filled in.
-- That still deleted real information: a demo person later given a home
-- address or a biography, in columns the page left empty, still matched, and
-- Wipe deleted the person with it (found by Codex, confirmed on MySQL 8.0.36,
-- 14 September 2026). So the fingerprint now covers every column, and
-- fingerprintColumns was added to say which ones.
--
-- UPGRADING AN EARLIER DRAFT
-- -----------------------------------------------------------------------------
-- This file was changed in place before it was ever released, so no released
-- installation can have an earlier shape. A development database that ran an
-- earlier draft can, and CREATE TABLE IF NOT EXISTS never alters a table that
-- already exists. The shapes that existed:
--   draft 1  registerID, tableName, rowID, checkValue, createdAt
--   draft 2  registerID, tableName, rowID, siteID, fingerprint, createdAt
--            (fingerprint covered only the columns the page filled in)
--   now      registerID, tableName, rowID, siteID, fingerprintColumns,
--            fingerprint, createdAt
-- On such a table the current page failed: Wipe and the personal data export
-- both name fingerprintColumns, and Load could not insert into draft 1's
-- checkValue, which has no default.
--
-- An earlier version of this comment said to drop the table and run this file
-- again. That was wrong: dropping the list also forgets which rows are demo
-- rows, so Wipe could never remove them and nothing would show they were
-- still there (found by Codex, 14 September 2026).
--
-- So the steps after the CREATE TABLE bring any earlier shape up to date and
-- KEEP every entry:
--   1. add siteID if it is missing (draft 1). It stays NULL for old entries:
--      the organisation at the time of loading was never recorded, and
--      reading it from the row now would be a guess;
--   2. add fingerprintColumns if it is missing (drafts 1 and 2);
--   3. add fingerprint if it is missing (draft 1);
--   4. mark every entry that has no complete fingerprint. Adding a NOT NULL
--      column to a table that already has rows fills it with an empty text
--      for those rows, and the page itself never writes an empty text into
--      either column, so "empty" identifies exactly the old entries. Their
--      fingerprintColumns becomes 'earlier-draft:no-complete-fingerprint'
--      (the same text as demo_data_incomplete_marker() in demo-data.php) and
--      their fingerprint becomes 'earlier-draft:none', which can never equal
--      a SHA-256 hash (that is always 64 lowercase hex characters). Wipe
--      never deletes the row of a marked entry: it is left in place and
--      listed with the reason. Draft 2's own partial fingerprint is replaced
--      too, on purpose: fingerprinting only some columns is the very design
--      that deleted real information, so nothing may ever match against it;
--   5. give the fingerprint column its current description (draft 2's said
--      "recorded columns");
--   6. remove draft 1's checkValue. It is NOT NULL with no default, so while
--      it exists every Load fails; the page no longer reads it; and it holds
--      the demo people's email addresses, which neither the personal data
--      export nor erasure knows about.
-- Matching on "empty" also means a run of this file that stopped between
-- step 2 and step 4 is finished properly by the next run. A list entry
-- somebody had emptied by hand is marked the same way; Wipe would have
-- refused over it anyway, and neither way deletes anything.
--
-- Admin → Upgrade only runs migrations not yet recorded in tblMigrations, so a
-- development database that already recorded an earlier draft of this file
-- needs it run again by hand: from the Migrations page's single-file run, or
-- through the installer, which replays every numbered migration.
--
-- WHY A LIST, AND NOT A MARKER COLUMN OR A SEPARATE DEMO ORGANISATION
-- -----------------------------------------------------------------------------
--   * A marker column ("isDemo") on each table would mean changing the shape of
--     tblUsers, tblUserSites and tblAnnouncements, and every future table demo
--     content is added to. It would also be a value any other page could copy
--     or set by mistake.
--   * A separate demo organisation does not isolate anything here. People are
--     not owned by an organisation (tblUserSites only links them), so deleting
--     the organisation would not remove them. Worse, anything real added to
--     that organisation later would be deleted with it. It would also appear
--     in the organisation switcher and could have its own public addresses.
--   * A list touches no existing table, and it can only ever name rows this
--     page created, because the page writes to it in the same transaction as
--     the row itself.
--
-- NO FOREIGN KEYS, ON PURPOSE
-- -----------------------------------------------------------------------------
-- rowID points into three different tables, so no single foreign key can
-- describe it. A foreign key with ON DELETE CASCADE would also be wrong in
-- principle: if somebody removed a demo row by hand, the list entry would
-- silently disappear with it. The page handles a row that has already gone by
-- itself: it is crossed off. siteID has no foreign key for the same reason: a
-- link to tblSites would either block deleting an organisation or silently
-- drop list entries when one is deleted.
--
-- PORTABILITY AND REPLAYING
-- -----------------------------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS is standard MySQL and MariaDB syntax. The upgrade
-- steps change columns, so they use the information_schema + PREPARE/EXECUTE
-- guard idiom from DEV_NOTES.md ("Portable DDL convention", as in migrations
-- 037, 112 and 138), never "ADD COLUMN IF NOT EXISTS", which MariaDB accepts
-- but MySQL 8 rejects. The ascii character set used for the fingerprint
-- columns exists in both. fingerprintColumns is TEXT NOT NULL with no default:
-- MySQL 8.0 does not accept a plain default value on a TEXT column, and none
-- is needed because the page always fills it in (column names can be long and
-- many, which is why it is not a short VARCHAR).
-- Running this file again on an up-to-date database changes nothing: every
-- guard finds its step already done, and step 4 finds no empty entry. That
-- matters because the installer replays every numbered migration after
-- full_schema.sql.
--
-- The same table is in full_schema.sql, so a brand-new installation and an
-- upgraded one end up identical. The upgrade steps are only here: a brand-new
-- installation creates the table in its current shape, so there is nothing
-- for them to upgrade, and the installer runs this file afterwards anyway.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblDemoDataRegister` (
    `registerID`  INT         NOT NULL AUTO_INCREMENT,
    `tableName`   VARCHAR(64) NOT NULL COMMENT 'Which table the demo row was created in (only tables the Demo Data page writes to)',
    `rowID`       INT         NOT NULL COMMENT 'The identity number the database gave the demo row',
    `siteID`      INT         DEFAULT NULL COMMENT 'Organisation the demo row belonged to when created; NULL where the table has no organisation column',
    `fingerprintColumns` TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'The columns the fingerprint covers, in order, separated by commas: every column the row had when created except ones the database changes by itself',
    `fingerprint` CHAR(64)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 (hex) of the table name, row number and every column named in fingerprintColumns when created; Wipe leaves the row in place if it no longer matches',
    `createdAt`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the demo data was loaded',
    PRIMARY KEY (`registerID`),
    UNIQUE KEY `uq_demo_register_row` (`tableName`, `rowID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Every row the Demo Data page created, so Wipe removes exactly those (#498)';

-- -----------------------------------------------------------------------------
-- Upgrade steps for an earlier draft (see "UPGRADING AN EARLIER DRAFT" above).
-- Each asks information_schema whether it is needed and otherwise runs a
-- harmless SELECT 1. The column definitions and descriptions are copied
-- exactly from the CREATE TABLE above.
-- -----------------------------------------------------------------------------

-- 1. ➕ siteID (missing from draft 1)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDemoDataRegister'
      AND COLUMN_NAME  = 'siteID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblDemoDataRegister` ADD COLUMN `siteID` INT DEFAULT NULL COMMENT ''Organisation the demo row belonged to when created; NULL where the table has no organisation column'' AFTER `rowID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. ➕ fingerprintColumns (missing from drafts 1 and 2). Rows already in the
--    table get an empty text, which step 4 then marks.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDemoDataRegister'
      AND COLUMN_NAME  = 'fingerprintColumns'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblDemoDataRegister` ADD COLUMN `fingerprintColumns` TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT ''The columns the fingerprint covers, in order, separated by commas: every column the row had when created except ones the database changes by itself'' AFTER `siteID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. ➕ fingerprint (missing from draft 1). Rows already in the table get an
--    empty text, which step 4 then marks.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDemoDataRegister'
      AND COLUMN_NAME  = 'fingerprint'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblDemoDataRegister` ADD COLUMN `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT ''SHA-256 (hex) of the table name, row number and every column named in fingerprintColumns when created; Wipe leaves the row in place if it no longer matches'' AFTER `fingerprintColumns`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. 🏷️ Mark every entry without a complete fingerprint, so Wipe keeps its
--    row. The page never writes an empty text into either column, so on an
--    up-to-date table this matches nothing.
UPDATE `tblDemoDataRegister`
SET `fingerprintColumns` = 'earlier-draft:no-complete-fingerprint',
    `fingerprint`        = 'earlier-draft:none'
WHERE `fingerprintColumns` = '' OR `fingerprint` = '';

-- 5. 📝 The fingerprint column's current description (draft 2 had older
--    wording). Compared as text, so a table that already has it is left alone.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA   = DATABASE()
      AND TABLE_NAME     = 'tblDemoDataRegister'
      AND COLUMN_NAME    = 'fingerprint'
      AND COLUMN_COMMENT <> 'SHA-256 (hex) of the table name, row number and every column named in fingerprintColumns when created; Wipe leaves the row in place if it no longer matches'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblDemoDataRegister` MODIFY COLUMN `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT ''SHA-256 (hex) of the table name, row number and every column named in fingerprintColumns when created; Wipe leaves the row in place if it no longer matches'' AFTER `fingerprintColumns`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. ➖ draft 1's checkValue (see step 6 above for why it goes)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDemoDataRegister'
      AND COLUMN_NAME  = 'checkValue'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblDemoDataRegister` DROP COLUMN `checkValue`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('194_demo_data_register.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
