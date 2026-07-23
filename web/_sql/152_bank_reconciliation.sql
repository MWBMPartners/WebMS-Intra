-- =============================================================================
-- Migration 152: Giving — bank statement reconciliation (#299 sub-feature 3)
-- =============================================================================
-- #299 "Giving polish" bundles four sub-features: two-person offering count
-- (150, shipped), pledge campaigns (151, shipped), bank reconciliation (this
-- migration), and account-updater for recurring giving (sub-feature 4 —
-- still not started).
--
-- Two new tables, no ALTERs:
--   `tblBankImports` — one row per uploaded bank-statement CSV batch.
--   `tblBankTxns`    — one row per imported CREDIT line (money in only —
--                      debits are never stored) + its match state.
--
-- `matchedCount` is deliberately NOT stored on tblBankImports — it mutates on
-- every match/unmatch and is trivially derivable with one aggregate join;
-- storing it invites drift. `rowCount`/`skippedCount` ARE stored (immutable
-- snapshots of what the parse found at import time).
--
-- Deposit-vs-entry matching is modelled with TWO nullable FKs on
-- `tblBankTxns` — `matchedEntryID` (1:1 match to a single tblGivingEntry
-- row) and `matchedCountSessionID` (one bank credit = a whole offering-count
-- deposit, which `giving/count/close.php` writes as MULTIPLE tblGivingEntry
-- rows sharing `reference = 'Count #<id>'`). A many-to-many link table was
-- considered and rejected: the only real-world case of one bank line
-- covering several gift rows is a count-session deposit, which already has
-- a first-class aggregate row (tblCountSessions) — arbitrary partial splits
-- are out of scope (the treasurer uses ignore + note for those). Code-
-- enforced invariant: at most one of the two FKs is non-NULL, and only when
-- `matchStatus = 'matched'`.
--
-- Duplicate-import protection: `fileHash` (SHA-256 of the raw upload bytes)
-- + UNIQUE KEY uq_bi_site_hash (siteID, fileHash) — re-importing after
-- deleting the old import works fine (the row is gone); importing the same
-- file twice within the same site errors cleanly (the PHP layer pre-checks
-- and flashes before ever hitting the key; different sites may import the
-- same file since the key includes siteID).
--
-- ON DELETE rationale: deleting an import CASCADEs its lines (nothing to
-- reconcile without the batch). Deleting a matched gift entry or count
-- session must never be blocked by reconciliation state, so those two FKs
-- SET NULL — the resulting "matched but target gone" state is self-healed
-- by an UPDATE in view.php before it renders. Site FKs carry no ON DELETE
-- clause (house pattern, e.g. fk_cs_site on tblCountSessions).
--
-- Replay/no-op proof: both CREATEs are IF NOT EXISTS; all three INSERTs
-- carry ON DUPLICATE KEY UPDATE; there are no ALTERs — a second run is a
-- pure no-op, and check_mariadb_only_ddl.py has nothing to flag (no
-- IF [NOT] EXISTS on ALTER/INDEX DDL, so the information_schema + PREPARE
-- guard idiom is not needed here).
--
-- New routes (normal page routes, NOT api/* — see the "ApiRouter routing
-- trap" note in .claude/CLAUDE.md, so these DO need tblRoutes rows):
--   /giving/reconcile         — dashboard: imports list + summary
--   /giving/reconcile/import  — CSV upload → mapping → preview → persist
--   /giving/reconcile/view    — one import: matched/unmatched/ignored + gaps
--   /giving/reconcile/match   — POST-only: match-entry / match-session /
--                               unmatch / ignore / rematch / delete-import
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/299
-- =============================================================================

-- 📋 Statement import batches — one row per uploaded bank CSV.
CREATE TABLE IF NOT EXISTS `tblBankImports` (
    `importID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `filename`     VARCHAR(255) NOT NULL COMMENT 'Original client filename (basename only, display)',
    `bankKey`      VARCHAR(20)  NOT NULL DEFAULT 'generic' COMMENT 'Detected/chosen preset: lloyds|hsbc|barclays|monzo|starling|generic',
    `currency`     CHAR(3)      NOT NULL DEFAULT 'GBP' COMMENT 'From giving.currency at import time; matching is currency-scoped',
    `fileHash`     CHAR(64)     NOT NULL COMMENT 'SHA-256 of raw upload bytes — duplicate-import guard',
    `rowCount`     INT          NOT NULL DEFAULT 0 COMMENT 'Credit lines imported (immutable parse snapshot)',
    `skippedCount` INT          NOT NULL DEFAULT 0 COMMENT 'Debit/zero/blank lines skipped (immutable parse snapshot)',
    `importedByID` INT          DEFAULT NULL,
    `importedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`importID`),
    UNIQUE KEY `uq_bi_site_hash` (`siteID`, `fileHash`),
    KEY `idx_bi_site` (`siteID`),
    CONSTRAINT `fk_bi_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_bi_importer` FOREIGN KEY (`importedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Bank statement CSV import batches (#299 sub-feature 3)';

-- 📋 Individual bank CREDIT lines (money in only — debits are never stored).
CREATE TABLE IF NOT EXISTS `tblBankTxns` (
    `txnID`                 INT          NOT NULL AUTO_INCREMENT,
    `importID`              INT          NOT NULL,
    `siteID`                INT          NOT NULL DEFAULT 1,
    `txnDate`               DATE         NOT NULL,
    `amountPence`           INT          NOT NULL COMMENT 'Integer minor units, always > 0 (credits only) — house pence convention (#266)',
    `description`           VARCHAR(255) NOT NULL DEFAULT '',
    `reference`             VARCHAR(100) DEFAULT NULL,
    `matchStatus`           ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
    `matchedEntryID`        INT          DEFAULT NULL COMMENT '1:1 match to a single tblGivingEntry row; mutually exclusive with matchedCountSessionID',
    `matchedCountSessionID` INT          DEFAULT NULL COMMENT 'Deposit match — this credit is a closed offering-count deposit (multiple gift rows)',
    `matchNote`             VARCHAR(255) DEFAULT NULL COMMENT 'Treasurer note, mainly for ignored lines',
    `matchedByID`           INT          DEFAULT NULL,
    `matchedAt`             DATETIME     DEFAULT NULL,
    `createdAt`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`txnID`),
    KEY `idx_bt_import`      (`importID`),
    KEY `idx_bt_site_status` (`siteID`, `matchStatus`),
    KEY `idx_bt_site_date`   (`siteID`, `txnDate`),
    KEY `idx_bt_entry`       (`matchedEntryID`),
    KEY `idx_bt_csession`    (`matchedCountSessionID`),
    CONSTRAINT `fk_bt_import`   FOREIGN KEY (`importID`)              REFERENCES `tblBankImports`(`importID`)     ON DELETE CASCADE,
    CONSTRAINT `fk_bt_site`     FOREIGN KEY (`siteID`)                REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_bt_entry`    FOREIGN KEY (`matchedEntryID`)        REFERENCES `tblGivingEntry`(`entryID`)      ON DELETE SET NULL,
    CONSTRAINT `fk_bt_csession` FOREIGN KEY (`matchedCountSessionID`) REFERENCES `tblCountSessions`(`countSessionID`) ON DELETE SET NULL,
    CONSTRAINT `fk_bt_matcher`  FOREIGN KEY (`matchedByID`)           REFERENCES `tblUsers`(`userID`)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Imported bank statement credit lines + match state (#299 sub-feature 3)';

-- 📋 Page routes (NOT api/* — normal page routes, so they DO need tblRoutes
--    rows; see the "ApiRouter routing trap" note in .claude/CLAUDE.md).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('giving/reconcile',        'giving/reconcile/index.php',  1),
    ('giving/reconcile/import', 'giving/reconcile/import.php', 1),
    ('giving/reconcile/view',   'giving/reconcile/view.php',   1),
    ('giving/reconcile/match',  'giving/reconcile/match.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Settings — matching window (days a gift may precede its bank credit).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'giving.reconcile.toleranceDays', '5', '5', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('152_bank_reconciliation.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
