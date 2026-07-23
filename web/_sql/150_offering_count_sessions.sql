-- =============================================================================
-- Migration 150: Giving — two-person offering count session (#299 sub-feature 1)
-- =============================================================================
-- Adds the offering-counting workflow described in #299 ("Giving polish"):
-- two counters independently enter cash / cheque / envelope totals for a
-- service date, the system flags a discrepancy if the two independent
-- counts disagree, and closing a session (once the totals are agreed) writes
-- the actual gift log (tblGivingEntry — see below) in one transaction.
--
-- Scope note: #299 bundles FOUR sub-features (offering counting, pledge
-- campaigns, bank reconciliation, account-updater). This migration covers
-- ONLY sub-feature 1 (offering counting) — the other three are out of scope
-- here and land as separate migrations/PRs if/when they're picked up.
--
-- Naming note: #299's issue body sketches the write target as `tblGiftEntries`,
-- but the giving app actually shipped (PR #266, migration 094) as
-- `tblGivingEntry` (singular "Entry", stores amounts in PENCE via
-- `amountPence` — see `Portal\Core\Giving`). This migration writes to the
-- REAL table, `tblGivingEntry`.
--
--   App routes (page routes — NOT api/*, see the "ApiRouter routing trap"
--   note in .claude/CLAUDE.md):
--     GET  /giving/count            → giving/count/index.php   (list + start new session)
--     GET  /giving/count/session    → giving/count/session.php (detail: counter entry, discrepancy, envelopes, close)
--     POST /giving/count/save       → giving/count/save.php    (create session / counter totals / admin resolve / named envelopes)
--     POST /giving/count/close      → giving/count/close.php   (writes the gift log, transactional)
--
--   Gate: same as every other financial action in `giving` —
--   `Portal\Core\Giving::canManage()` (site admin OR the seeded `treasurer`
--   role, migration 017). Resolving a 'discrepancy' additionally requires
--   `App::isAdmin()` — the counters themselves can re-enter to match, but
--   only an admin can force an agreed total over a live mismatch.
--
--   State machine (`tblCountSessions.status`):
--     open        → session created, neither counter has submitted yet.
--     counting    → at least one counter has submitted; OR both have
--                   submitted and AGREE (agreed totals auto-set, ready to
--                   close); OR an admin has resolved a discrepancy (agreed
--                   totals set directly).
--     discrepancy → both counters submitted and at least one of
--                   cash/cheque/envelope differs. Blocks /giving/count/close
--                   until resolved (admin override, or a counter re-enters
--                   matching totals).
--     closed      → agreed totals written to tblGivingEntry, closedByID/At
--                   stamped. Terminal.
--
--   Named envelopes (`tblCountEnvelopes`): the numbered/named giving-envelope
--   breakdown of the agreed `envelopeTotal`, entered once per session (not
--   duplicated per-counter — only the aggregate cash/cheque/envelope totals
--   are independently double-keyed). On close, SUM(tblCountEnvelopes.amount)
--   must equal the agreed envelopeTotal exactly, or the close is rejected.
--
--   On close, the gift log is written as: one tblGivingEntry row per named
--   envelope (attributed to giverID/giverName) + one aggregate "loose cash"
--   row for any cashTotal not covered by named cash envelopes + one
--   aggregate "loose cheque" row for any chequeTotal not covered by named
--   cheque envelopes — so the sum of everything written always reconciles
--   exactly to cashTotal + chequeTotal + envelopeTotal (a "balanced" gift
--   log, not just the single loose-cash line the issue body sketched).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/299
-- =============================================================================

-- 📋 Count sessions — one per service date, the two independent counts +
--    the agreed (post-reconciliation) totals actually written to the gift log.
CREATE TABLE IF NOT EXISTS `tblCountSessions` (
    `countSessionID` INT            NOT NULL AUTO_INCREMENT,
    `siteID`         INT            NOT NULL DEFAULT 1,
    `serviceDate`    DATE           NOT NULL,
    `categoryID`     INT            NOT NULL COMMENT 'tblGivingCategory this session posts its gift log to on close',
    `counter1ID`     INT            DEFAULT NULL COMMENT 'tblUsers — first counter (nullable until assigned)',
    `counter2ID`     INT            DEFAULT NULL COMMENT 'tblUsers — second counter (nullable until assigned)',
    `cashTotal1`     DECIMAL(10,2)  DEFAULT NULL COMMENT 'Counter 1''s independent cash count',
    `chequeTotal1`   DECIMAL(10,2)  DEFAULT NULL COMMENT 'Counter 1''s independent cheque count',
    `envelopeTotal1` DECIMAL(10,2)  DEFAULT NULL COMMENT 'Counter 1''s independent envelope count',
    `cashTotal2`     DECIMAL(10,2)  DEFAULT NULL COMMENT 'Counter 2''s independent cash count',
    `chequeTotal2`   DECIMAL(10,2)  DEFAULT NULL COMMENT 'Counter 2''s independent cheque count',
    `envelopeTotal2` DECIMAL(10,2)  DEFAULT NULL COMMENT 'Counter 2''s independent envelope count',
    `cashTotal`      DECIMAL(10,2)  DEFAULT NULL COMMENT 'Agreed cash total — set when counts match or an admin resolves; written to the gift log on close',
    `chequeTotal`    DECIMAL(10,2)  DEFAULT NULL COMMENT 'Agreed cheque total',
    `envelopeTotal`  DECIMAL(10,2)  DEFAULT NULL COMMENT 'Agreed envelope total — must equal SUM(tblCountEnvelopes.amount) before close',
    `status`         ENUM('open','counting','discrepancy','closed') NOT NULL DEFAULT 'open',
    `notes`          TEXT           DEFAULT NULL,
    `createdByID`    INT            DEFAULT NULL,
    `createdAt`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closedByID`     INT            DEFAULT NULL,
    `closedAt`       DATETIME       DEFAULT NULL,
    PRIMARY KEY (`countSessionID`),
    KEY `idx_cs_site`      (`siteID`),
    KEY `idx_cs_site_date` (`siteID`, `serviceDate`),
    KEY `idx_cs_status`    (`status`),
    CONSTRAINT `fk_cs_site`      FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_cs_category`  FOREIGN KEY (`categoryID`)  REFERENCES `tblGivingCategory`(`categoryID`),
    CONSTRAINT `fk_cs_counter1`  FOREIGN KEY (`counter1ID`)  REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_cs_counter2`  FOREIGN KEY (`counter2ID`)  REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_cs_creator`   FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_cs_closer`    FOREIGN KEY (`closedByID`)  REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Two-person offering count sessions (#299 sub-feature 1)';

-- 📋 Named envelope / giver breakdown of a session's agreed envelopeTotal —
--    written out as individually-attributed tblGivingEntry rows on close.
CREATE TABLE IF NOT EXISTS `tblCountEnvelopes` (
    `envelopeID`     INT            NOT NULL AUTO_INCREMENT,
    `countSessionID` INT            NOT NULL,
    `giverID`        INT            DEFAULT NULL COMMENT 'tblUsers — matched member, nullable (unmatched envelopes use giverName)',
    `giverName`      VARCHAR(255)   DEFAULT NULL COMMENT 'Free-text name when the envelope name doesn''t match a member',
    `amount`         DECIMAL(10,2)  NOT NULL,
    `method`         ENUM('cash','cheque') NOT NULL DEFAULT 'cash' COMMENT 'What the named envelope contained',
    `createdAt`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`envelopeID`),
    KEY `idx_ce_session` (`countSessionID`),
    CONSTRAINT `fk_ce_session` FOREIGN KEY (`countSessionID`) REFERENCES `tblCountSessions`(`countSessionID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ce_giver`   FOREIGN KEY (`giverID`)        REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Named giving-envelope breakdown for a count session (#299 sub-feature 1)';

-- 📋 Page routes (NOT api/* — these are normal page routes, so they DO need
--    tblRoutes rows; see the "ApiRouter routing trap" note in .claude/CLAUDE.md).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('giving/count',         'giving/count/index.php',   1),
    ('giving/count/session', 'giving/count/session.php', 1),
    ('giving/count/save',    'giving/count/save.php',    1),
    ('giving/count/close',   'giving/count/close.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Settings — whether a session requires BOTH counters to submit before
--    it can be agreed/closed. Defaults 'true' (the two-person workflow this
--    sub-feature exists to provide); a site can opt out to 'false' for a
--    single-counter workflow where the first slot submitted is auto-agreed.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'giving.countRequiresTwoCounters', 'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('150_offering_count_sessions.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
