-- =============================================================================
-- Migration 276: a public address is unique only once it is APPROVED (#895)
-- =============================================================================
-- Path: web/_sql/276_public_address_unique_on_approval.sql
-- Package: WebMS-Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: (c) MWBM Partners Ltd. All Rights Reserved.
-- Version: see web/_core/version.php
--
-- What this changes, on tblPublicHosts (made by migration 262, approval
-- columns from 263):
--
--   approvedHost            NEW. Worked out by the database: the host name
--                           while the address is APPROVED, and empty (NULL)
--                           while it is only requested.
--   uq_public_host_approved NEW unique key on approvedHost. Two APPROVED rows
--                           can never hold one name.
--   idx_public_host_name    NEW ordinary key on hostName, so finding every
--                           organisation that asked for one name stays quick.
--   uq_public_host_name     REMOVED. It made a name unique whether or not it
--                           was approved.
--
-- -----------------------------------------------------------------------------
-- WHY (the owner's decision D-121, 11 November 2026)
-- -----------------------------------------------------------------------------
-- An organisation's public address does nothing until the installation's
-- global administrator approves it (D-101). But the old key made the name
-- unique the moment it was REQUESTED, so organisation B could type
-- organisation A's real website address first, and A could then not enter its
-- own. D-121: any number of organisations may request the same address while
-- it waits; the installation's administrator sees that the requests clash and
-- approves only one; the others are then told the address is taken.
-- Uniqueness is enforced at the moment of approval, so nobody can block
-- another by asking first.
--
-- -----------------------------------------------------------------------------
-- WHY A WORKED-OUT COLUMN, AND WHY ITS EMPTINESS IS THE POINT
-- -----------------------------------------------------------------------------
-- In MySQL and MariaDB a unique key stands aside for any row where a part of
-- it is empty (#487: an empty value is not equal even to itself). That is
-- usually a trap; here it is exactly the rule wanted. `approvedHost` is empty
-- for every row that is only requested, so the key ignores those — any number
-- may share a name — and is a real rule for approved rows, where it holds the
-- name. The database itself therefore refuses a second approval of one name,
-- whatever the code does: `PublicWebsiteSetup::approvePublicHost()` locks the
-- clashing rows inside a transaction and asks first, so the refusal reaches a
-- person in words, and this key is what still holds if that ask is ever wrong.
--
-- VIRTUAL, because nothing needs it stored: it is worked out from two
-- columns of the same row, and the unique key keeps its own copy of the
-- value. (STORED would also have worked here — measured on MySQL 8.0.36,
-- where the table was accepted and a cascading delete worked, and on MariaDB
-- 11.4.13 by #895's independent check. MySQL's ERROR 1215 about STORED
-- columns, migration 187's reason, applies only when a cascading foreign key
-- sits on a column the worked-out one is made FROM, and neither `hostName`
-- nor `approvedAtUtc` carries one. An earlier version of this note gave that error as the
-- reason; it was untrue.) A unique key on a VIRTUAL column is supported by
-- InnoDB on MySQL 8.0, 8.4 and MariaDB.
-- The worked-out column takes the table's own collation, so two spellings that
-- differ only in capitals still clash, as they did under the old key.
--
-- -----------------------------------------------------------------------------
-- WHY THE OLD KEY CAN BE DROPPED SAFELY
-- -----------------------------------------------------------------------------
-- Until this runs every name is held by at most one row, so at most one row
-- per name can be approved, and the new unique key cannot fail on the data
-- already there. The ordinary key on hostName is added BEFORE the old unique
-- key is removed, so lookups by name never lose their index.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` / `DROP INDEX IF EXISTS` with ERROR 1064 (see
-- .claude/rules/database.md). Each step runs only while it is still needed,
-- so the file is SAFE TO RUN TWICE, and it is a no-op on a fresh install,
-- whose `full_schema.sql` already has the final shape. The column definition
-- must be word for word what `full_schema.sql` says, or an upgraded database
-- and a fresh install differ. No apostrophes in the column comment: it sits
-- inside a quoted string inside another quoted string (the table comment in
-- step 5 has one, and is quoted four deep for that reason).
--
-- NO PERSONAL DETAILS: the new column is a host name an organisation chose.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/895
-- =============================================================================

-- 1. The worked-out column, added only when missing.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts' AND COLUMN_NAME = 'approvedHost'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblPublicHosts` ADD COLUMN `approvedHost` VARCHAR(253) AS (IF(`approvedAtUtc` IS NULL, NULL, `hostName`)) VIRTUAL COMMENT ''Worked out by the database (#895, D-121): the host name while the address is APPROVED, and empty while it is only requested. Exists so uq_public_host_approved can make an approved name unique while letting any number of organisations request one. VIRTUAL: it is worked out from two columns of the same row and never needs storing; the unique key keeps its own copy.'' AFTER `approvedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. The unique key on it, added only when missing.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts' AND INDEX_NAME = 'uq_public_host_approved'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblPublicHosts` ADD UNIQUE KEY `uq_public_host_approved` (`approvedHost`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. The ordinary key on the requested name, added only when missing — and
--    BEFORE step 4, so the name is never without an index.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts' AND INDEX_NAME = 'idx_public_host_name'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblPublicHosts` ADD KEY `idx_public_host_name` (`hostName`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. The old key, removed only while it is there.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts' AND INDEX_NAME = 'uq_public_host_name'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblPublicHosts` DROP INDEX `uq_public_host_name`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. The table's own description said "no two may share one"; it now says when.
SET @sql := IF((
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublicHosts'
      AND TABLE_COMMENT = 'Each organisation''s optional public address for its public pages; no two may share one (#493 P3, D-095)'
) > 0,
    'ALTER TABLE `tblPublicHosts` COMMENT = ''Each organisation''''s optional public address for its public pages; several may request one name, only one may be approved for it (#493 P3, D-095, D-121)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('276_public_address_unique_on_approval.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
