-- =============================================================================
-- Migration 151: Giving — pledge campaigns (#299 sub-feature 2)
-- =============================================================================
-- Adds pledge campaigns to the existing `giving` app (#266): a treasurer/admin
-- defines a campaign with a goal amount and (optional) date window; members
-- pledge a recurring (or one-off) amount; every gift that can be attributed
-- to a campaign/pledge is tracked so the campaign thermometer and each
-- pledger's on-schedule status can be computed.
--
-- Scope note: #299 bundles FOUR sub-features (offering counting, pledge
-- campaigns, bank reconciliation, account-updater). Migration 150 shipped
-- sub-feature 1 (offering counting). This migration covers ONLY sub-feature 2
-- (pledge campaigns) — bank reconciliation and account-updater remain
-- tracked-but-not-started.
--
-- New tables:
--   tblPledgeCampaigns — one row per campaign (name, goal, date window).
--   tblPledges         — one row per member per campaign (UNIQUE upsert —
--                        re-pledging updates the existing row rather than
--                        duplicating it; a cancelled pledge is re-opened by
--                        pledging again, not row-duplicated).
--
-- Auto-attribution mechanism: two nullable columns added to the EXISTING
-- `tblGivingEntry` table — `campaignID` (a gift explicitly or automatically
-- counted toward a campaign's goal) and `pledgeID` (additionally credits a
-- specific member's pledge for the on-schedule metric). A link table was
-- considered and rejected: nothing in this feature or the recording UX ever
-- splits one gift across multiple campaigns (the treasurer already records
-- split gifts as separate `tblGivingEntry` rows via categories), and columns
-- keep every thermometer/progress query a single-table indexed SUM instead of
-- a JOIN. The invariant `pledgeID IS NOT NULL ⇒ campaignID = that pledge's
-- campaignID` is enforced entirely in code — `Portal\Core\Giving::attributeGift()`
-- is the ONLY code path that ever produces the pair (see that method for the
-- full attribution rule: explicit treasurer choice, auto-match to the donor's
-- single open pledge, or decline to guess when a donor holds pledges to more
-- than one open campaign).
--
-- Hooked into BOTH manual gift-recording paths that write tblGivingEntry:
--   - `_apps/giving/entry-save.php` (the treasurer's manual entry form)
--   - `_apps/giving/count/close.php` (sub-feature 1's offering-count close —
--     named-envelope rows only, since those are the only ones with a real
--     donor to match a pledge against)
-- `_core/Projects.php` (one-off project-pledge fulfilment) and
-- `_core/Payments.php` (online card giving) also write `tblGivingEntry` but
-- are deliberately NOT hooked here — their rows simply leave the new nullable
-- columns NULL, which is safe and correct. Auto-attributing online/project
-- giving is a documented follow-up, not an oversight.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/299
-- =============================================================================

-- 📋 Pledge campaigns — goal thermometer + date window a campaign runs for.
CREATE TABLE IF NOT EXISTS `tblPledgeCampaigns` (
    `campaignID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`          INT          NOT NULL DEFAULT 1,
    `name`            VARCHAR(255) NOT NULL,
    `description`     TEXT         DEFAULT NULL,
    `goalAmountPence` INT          NOT NULL COMMENT 'Integer minor units — house pence convention (#266)',
    `currency`        CHAR(3)      NOT NULL DEFAULT 'GBP',
    `startDate`       DATE         NOT NULL,
    `endDate`         DATE         DEFAULT NULL COMMENT 'NULL = open-ended',
    `isActive`        TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`     INT          DEFAULT NULL,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`campaignID`),
    KEY `idx_plc_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_plc_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_plc_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Giving pledge campaigns (#299) — goal thermometer + pledge tracking';

-- 📋 Member pledges — one row per member per campaign; re-pledging (or
--    re-pledging after cancellation) upserts this same row via
--    `uq_pl_campaign_user`, so pledge history/progress math stays one row
--    per pledger, never duplicated.
CREATE TABLE IF NOT EXISTS `tblPledges` (
    `pledgeID`        INT        NOT NULL AUTO_INCREMENT,
    `siteID`          INT        NOT NULL DEFAULT 1,
    `campaignID`      INT        NOT NULL,
    `userID`          INT        NOT NULL COMMENT 'The pledger — always a logged-in member',
    `amountPence`     INT        NOT NULL COMMENT 'Per-instalment amount (weekly/monthly); total amount for one-off',
    `paymentSchedule` ENUM('one-off','weekly','monthly') NOT NULL DEFAULT 'monthly',
    `status`          ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
    `createdAt`       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pledgeID`),
    UNIQUE KEY `uq_pl_campaign_user` (`campaignID`, `userID`),
    KEY `idx_pl_site` (`siteID`),
    KEY `idx_pl_user_status` (`userID`, `status`),
    CONSTRAINT `fk_pl_site`     FOREIGN KEY (`siteID`)     REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_pl_campaign` FOREIGN KEY (`campaignID`) REFERENCES `tblPledgeCampaigns`(`campaignID`) ON DELETE CASCADE,
    CONSTRAINT `fk_pl_user`     FOREIGN KEY (`userID`)     REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Member pledges to campaigns (#299) — one row per member per campaign (UNIQUE upsert)';

-- -----------------------------------------------------------------------------
-- 🧩 tblGivingEntry auto-attribution columns — guarded per DEV_NOTES.md
-- "Portable DDL convention (MySQL 8.0 ∩ MariaDB)". MySQL 8 rejects MariaDB's
-- `IF [NOT] EXISTS` on ALTER with ERROR 1064, so every ALTER below goes
-- through the information_schema + PREPARE/EXECUTE guard idiom (house
-- examples: migrations 037, 112, 138).
-- -----------------------------------------------------------------------------

-- ➕ tblGivingEntry.campaignID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGivingEntry'
      AND COLUMN_NAME  = 'campaignID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblGivingEntry` ADD COLUMN `campaignID` INT DEFAULT NULL COMMENT ''Pledge campaign this gift counts toward (#299)'' AFTER `recordedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblGivingEntry.pledgeID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGivingEntry'
      AND COLUMN_NAME  = 'pledgeID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblGivingEntry` ADD COLUMN `pledgeID` INT DEFAULT NULL COMMENT ''Specific pledge this gift fulfils; implies campaignID (#299)'' AFTER `campaignID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_ge_campaign_date — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGivingEntry'
      AND INDEX_NAME   = 'idx_ge_campaign_date'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblGivingEntry` ADD INDEX `idx_ge_campaign_date` (`campaignID`, `donatedAt`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_ge_pledge — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGivingEntry'
      AND INDEX_NAME   = 'idx_ge_pledge'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblGivingEntry` ADD INDEX `idx_ge_pledge` (`pledgeID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_ge_campaign — guarded ADD CONSTRAINT
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblGivingEntry'
      AND CONSTRAINT_NAME   = 'fk_ge_campaign'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblGivingEntry` ADD CONSTRAINT `fk_ge_campaign` FOREIGN KEY (`campaignID`) REFERENCES `tblPledgeCampaigns`(`campaignID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_ge_pledge — guarded ADD CONSTRAINT
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblGivingEntry'
      AND CONSTRAINT_NAME   = 'fk_ge_pledge'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblGivingEntry` ADD CONSTRAINT `fk_ge_pledge` FOREIGN KEY (`pledgeID`) REFERENCES `tblPledges`(`pledgeID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Page routes (NOT api/* — these are normal page routes, so they DO need
--    tblRoutes rows; see the "ApiRouter routing trap" note in .claude/CLAUDE.md).
--    No new tblSettings rows: these reuse the already-seeded `giving.currency`
--    key and no api.*.enabled flags are needed (nothing here is under api/*).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('giving/campaigns',     'giving/campaigns.php',     1),
    ('giving/campaign',      'giving/campaign.php',      1),
    ('giving/campaign-save', 'giving/campaign-save.php', 1),
    ('giving/pledge-save',   'giving/pledge-save.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('151_giving_pledge_campaigns.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
