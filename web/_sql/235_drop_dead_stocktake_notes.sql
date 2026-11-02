-- =============================================================================
-- Migration 235: drop the two stocktake note columns nothing ever wrote (#708)
-- =============================================================================
-- Two dropped columns, no new table, no data moved.
--
--   `tblAssetStocktakes`.`notes`      TEXT
--   `tblAssetStocktakeItems`.`notes`  VARCHAR(500)
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- Both columns arrived with the stocktake tables in commit 4764c06 ("Asset
-- Tracker — Phase 3", #411-#415/#418). NOTHING has ever written to either, on
-- any version of this repository. Two commands establish it:
--
--   # Every statement ever written against either table, any path, any
--   # quoting style. Returns FIVE; not one names `notes`.
--   git log --all -p | grep "^+" | grep -i tblAssetStocktake \
--     | grep -Ei "insert|update|replace|load data" \
--     | sed 's/^+[[:space:]]*//' | sort -u
--
--   # The first command prints only the line holding the table name, and SQL
--   # here is built by joining strings, so a SET or ON DUPLICATE KEY UPDATE
--   # clause on a later line is invisible to it. Ask after the COLUMN instead.
--   # Every line it returns belongs to tblAssetOrgs, an asset assignment, a
--   # kit loan or a licence seat — never a stocktake table.
--   git log --all -p -- web/_core/AssetRegister.php \
--     | grep "^+" | grep -w "notes" | sed 's/^+[[:space:]]*//' | sort -u
--
-- Command 2 is filtered to ONE file, and the reason is that command 1's five
-- statements all live in it: every stocktake INSERT and UPDATE in this
-- repository's history is in `web/_core/AssetRegister.php`. Check that before
-- trusting the pair, because command 1 strips filenames from its output:
--   git log --all --name-only -S"tblAssetStocktake" --format="" -- | sort -u
--
-- The second command is NOT optional. Earlier drafts of this header published
-- narrower evidence twice, and justified leaving the gap open with a claim
-- about MySQL that is false (`ON DUPLICATE KEY UPDATE` assigns to ANY column of
-- the table, measured on 8.0.36). Those drafts, and the searches that look like
-- proof and are not, are written up in DEV_NOTES.md, "When the honest write-up
-- is 'nothing writes this'" — they are lessons about evidence rather than facts
-- about these two columns, and they do not belong here.
--
-- Nothing CONSUMED them either, which is the careful word and not a flourish:
-- they really were READ. Three of the four stocktake SELECTs that return a row
-- to a caller use `st.*` / `i.*`, so both columns were fetched on every call.
-- Only two files receive such a row (`stocktakes.php` and
-- `stocktake.php`); neither mentions "note", neither walks a row's keys
-- generically, and the two values were thrown away. No screen offers a box to
-- type one into.
--
-- -----------------------------------------------------------------------------
-- WHY DROP THEM RATHER THAN LEAVE THEM
-- -----------------------------------------------------------------------------
-- The reason migration 201 gave for the check-in browser description: a column
-- that exists reads as a column meant to be used, so the next person to touch
-- these screens would reasonably start filling one in — and an untyped
-- free-text box on an audit record is where personal information lands without
-- anybody deciding that it should.
--
-- Not hypothetical here. Both columns had an entry in
-- `web/_core/personal-data-catalogue.php` from the day that file was created
-- (`7a0f203`), and #635 (`17dce1b`) then had to write each one a paragraph on
-- why a note nobody can type is kept rather than emptied on an erasure
-- request. (An earlier draft of this header credited #706 for both. Measured
-- wrong: `git show e9934e7 -- web/_core/personal-data-catalogue.php` does not
-- mention either table. A wrong issue number is worse than none, which is why
-- the rule is to cite numbers and hashes at all.) Two such
-- paragraphs in the file a data subject's request is answered from make the
-- erasure coverage read WIDER than it is. Dropping the columns deletes the
-- question instead of answering it.
--
-- It also gives a check for free: `tools/audit-checks/check_sql_columns.py`
-- builds its column map from `full_schema.sql`'s CREATE TABLE blocks plus every
-- migration's ADD COLUMN, so once the columns are gone from both, a future
-- INSERT or UPDATE naming them is a finding rather than something somebody must
-- remember. Said that narrowly on purpose: that check's own blind-spot list
-- rules out a clause built at run time or reached through a join or an alias,
-- which is the shape these very queries use, so it would NOT catch a wildcard
-- SELECT or a name assembled from pieces.
--
-- If a stocktake ever genuinely needs a note, that is a feature with a screen,
-- a length limit, an erasure decision and a retention period — not a spare
-- column somebody finds lying about.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS CANNOT DO
-- -----------------------------------------------------------------------------
-- No data THE PORTAL wrote can be lost, because there is none. That is a claim
-- about this repository's history, NOT about any particular database: a note
-- typed in by hand in a database client would be lost, and a drop cannot be
-- undone.
--
-- No CUSTOMER's data is at risk, because there is no live installation of this
-- portal yet (the owner, 2 November 2026). A developer's or a test database is
-- the only place such a note could be, and that HAS NOT BEEN CHECKED — it
-- cannot be, from the repository.
--
-- Both columns are also on `DbBackup::REMOVED_COLUMNS`, so a snapshot taken
-- before this migration can still be restored into a database that has run it.
-- Without that entry such a restore is REFUSED with "Unknown column", which is
-- the deliberate behaviour for a column nobody has thought about.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The `information_schema` + `PREPARE`/`EXECUTE` guard below drops each column
-- only while it is still there, the same shape migrations 194 and 201 use.
-- MySQL 8.0 has no `DROP COLUMN IF EXISTS` — that is MariaDB-only, and this
-- schema is written to the MySQL 8.0 dialect — so the guard is how this is
-- done. On a database that has already run this migration both guards find
-- nothing and run a harmless `SELECT 1`, which is required because the
-- installer replays every numbered migration after `full_schema.sql` whether
-- or not it has run before.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/708
-- =============================================================================

-- 🧹 tblAssetStocktakes.notes
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetStocktakes'
      AND COLUMN_NAME  = 'notes'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblAssetStocktakes` DROP COLUMN `notes`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧹 tblAssetStocktakeItems.notes
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetStocktakeItems'
      AND COLUMN_NAME  = 'notes'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblAssetStocktakeItems` DROP COLUMN `notes`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('235_drop_dead_stocktake_notes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
