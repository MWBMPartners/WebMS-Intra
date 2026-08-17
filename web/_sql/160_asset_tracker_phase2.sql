-- =============================================================================
-- Migration 160: Asset Tracker Phase 2 — schema foundation (#404 Pass 1)
-- =============================================================================
-- Schema-only foundation for Asset Tracker Phase 2. Ships three new tables,
-- four guarded ADD COLUMNs on the existing `tblAssets` (insurance fields),
-- the feature-gate/tunable settings the later Phase 2 passes will read, and
-- route seeds (with a lightweight stub handler for each — see
-- _apps/assets/{import,my,value-report,event-assign}.php and
-- _apps/cron/asset-reminders.php) so check_route_targets.py stays green
-- while the schema ships ahead of the full CRUD/reminder logic.
--
-- Design notes:
--   • New tables use plain `CREATE TABLE IF NOT EXISTS` — brand new tables
--     are already idempotent and need no information_schema guard (that
--     idiom is only required for ALTER/ADD COLUMN/ADD INDEX on EXISTING
--     tables — see DEV_NOTES.md → "Portable DDL convention").
--   • The four `tblAssets` ADD COLUMNs (insurance fields) DO need the
--     guard idiom — `tblAssets` already exists as of migration 159, and
--     MySQL 8 rejects MariaDB's `ADD COLUMN IF NOT EXISTS` with ERROR 1064.
--   • UNIQUE FK CONSTRAINT NAMES: migration 159's header records that
--     Phase 1 hit a `fk_ast_site` collision with the (unrelated)
--     attendance tables' own constraint names — every constraint name in
--     THIS file was checked against `full_schema.sql`'s existing
--     `CONSTRAINT \`fk_` inventory before being chosen. The prefixes
--     `fk_astev_` (tblAssetEventAssignments) and `fk_astscn_`
--     (tblAssetScanLog) were verified collision-free; `tblAssetReminderLog`
--     carries NO FK constraints at all (see its own table comment below),
--     so it needed no prefix reservation.
--   • `tblAssetReminderLog` deliberately has NO FK constraints — it
--     mirrors `tblAssetAudit`'s (migration 159 / #395) "log outlives its
--     referents" convention: a reminder that already fired must stay in
--     the single-shot-dedupe log (`uq_astrl_ref`) even after the
--     maintenance/warranty/insurance/loan row it was about, or the asset
--     itself, is later deleted — otherwise the #405 reminder sweep could
--     re-send a reminder for something that no longer technically exists
--     to join against, simply because its own log row vanished via
--     CASCADE.
--   • Dependency order (this file, top to bottom): tblAssetEventAssignments
--     (references tblAssets + tblEvents + tblUsers, all of which already
--     exist), tblAssetScanLog (tblAssets + tblUsers), tblAssetReminderLog
--     (no FKs — order doesn't matter, placed last for readability), THEN
--     the tblAssets ALTERs (order-independent — guarded ALTERs on an
--     already-existing table), THEN seeds.
--   • `tblEvents`' primary key is `eventID` (confirmed against
--     full_schema.sql before writing `fk_astev_event`).
--   • Follows the same replay/no-op discipline as migration 159: every
--     CREATE is IF NOT EXISTS, every ALTER is guarded, every seed INSERT
--     is ON DUPLICATE KEY UPDATE, and the final tblMigrations self-record
--     uses the same idiom as every other migration in this codebase.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/404
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📅 tblAssetEventAssignments — links an asset to a calendar event (e.g.
-- "the PA system is assigned to Sunday's service"), with an optional
-- assignment WINDOW distinct from the event's own start/end (an asset can
-- be signed out earlier for setup and returned later for teardown). One
-- row per (asset, event) pair — re-assigning the same asset to the same
-- event updates the existing row via `uq_astev_asset_event` rather than
-- duplicating it. (#404)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetEventAssignments` (
    `assignmentID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `assetID`        INT          NOT NULL,
    `eventID`        INT          NOT NULL,
    `assignedByID`   INT          NOT NULL,
    `assignedFrom`   DATETIME     DEFAULT NULL COMMENT 'NULL = defaults to the event''s own startDateTime',
    `assignedUntil`  DATETIME     DEFAULT NULL COMMENT 'NULL = defaults to the event''s own endDateTime',
    `notes`          VARCHAR(500) DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`assignmentID`),
    UNIQUE KEY `uq_astev_asset_event` (`assetID`, `eventID`),
    KEY `idx_astev_site` (`siteID`),
    KEY `idx_astev_event` (`eventID`),
    KEY `idx_astev_asset` (`assetID`),
    CONSTRAINT `fk_astev_site`  FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astev_asset` FOREIGN KEY (`assetID`)      REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astev_event` FOREIGN KEY (`eventID`)      REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astev_user`  FOREIGN KEY (`assignedByID`) REFERENCES `tblUsers`(`userID`)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 2 — asset-to-event assignments (#404)';


-- -----------------------------------------------------------------------------
-- 🔍 tblAssetScanLog — every public (lost-and-found `/a/{token}` page) OR
-- internal (logged-in manager re-scanning an asset's label) barcode/QR
-- scan, for the future scan-history/analytics view. Distinct from
-- `tblAssetAudit`'s existing 'token'/'scan' audit EVENT (migration 159) —
-- that one is the audit choke-point's lightweight event record; THIS
-- table is a purpose-built, denser log meant to be queried/aggregated on
-- its own (hence its own retention setting, `assets.scan_log_retention_days`,
-- separate from the audit trail's retention policy). (#404)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetScanLog` (
    `scanID`         INT      NOT NULL AUTO_INCREMENT,
    `siteID`         INT      NOT NULL DEFAULT 1,
    `assetID`        INT      NOT NULL,
    `scanContext`    ENUM('public','internal') NOT NULL DEFAULT 'public'
                     COMMENT 'public = the /a/{token} lost-and-found page; internal = a logged-in manager re-scanning a printed label',
    `actorUserID`    INT      DEFAULT NULL COMMENT 'NULL for anonymous public scans',
    `ipHash`         CHAR(64) DEFAULT NULL COMMENT 'Salted SHA-256 of the scanner''s IP — mirrors tblAssetAudit.ipHash/tblAssetFoundReports.ipHash (migration 159)',
    `userAgentHash`  CHAR(64) DEFAULT NULL COMMENT 'Salted SHA-256 of the scanner''s User-Agent header',
    `createdAt`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`scanID`),
    KEY `idx_astscn_asset_created` (`assetID`, `createdAt`),
    KEY `idx_astscn_site_created` (`siteID`, `createdAt`),
    KEY `idx_astscn_site_context` (`siteID`, `scanContext`, `createdAt`),
    CONSTRAINT `fk_astscn_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astscn_asset` FOREIGN KEY (`assetID`) REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astscn_actor` FOREIGN KEY (`actorUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 2 — barcode/QR scan log (#404)';


-- -----------------------------------------------------------------------------
-- ⏰ tblAssetReminderLog — single-shot dedupe log for the #405 reminder
-- sweep (maintenance due, warranty expiring, insurance renewal due, an
-- overdue loan). NO FK constraints anywhere on this table — mirrors
-- `tblAssetAudit` (migration 159 / #395): a reminder log row must outlive
-- the maintenance/asset/loan row it was ABOUT (see the file header's
-- design note above for why — CASCADE-deleting the log alongside its
-- referent would let the #405 sweep re-fire a reminder that already sent).
-- `uq_astrl_ref` is what makes a re-run of the sweep for the same due date
-- a no-op rather than a duplicate send. (#404)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetReminderLog` (
    `logID`           INT      NOT NULL AUTO_INCREMENT,
    `siteID`          INT      NOT NULL DEFAULT 1,
    `refType`         ENUM('maintenance','warranty','insurance','renewal','loan-overdue') NOT NULL
                      COMMENT 'Which due-date family this reminder was about',
    `refID`           INT      NOT NULL COMMENT 'No FK by design — see table comment. maintID/assetID(warranty|insurance|renewal)/loanID depending on refType',
    `assetID`         INT      NOT NULL COMMENT 'No FK by design — see table comment',
    `dueDate`         DATE     NOT NULL,
    `recipientCount`  INT      NOT NULL DEFAULT 0,
    `sentAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`logID`),
    UNIQUE KEY `uq_astrl_ref` (`refType`, `refID`, `dueDate`),
    KEY `idx_astrl_site` (`siteID`),
    KEY `idx_astrl_asset` (`assetID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 2 — reminder single-shot dedupe log, no FKs by design (#404)';


-- #############################################################################
-- 🧩 tblAssets — guarded ADD COLUMNs (insurance fields, #404)
-- #############################################################################
-- Portable DDL convention (DEV_NOTES.md) — MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` with ERROR 1064, so every column below goes
-- through the information_schema + PREPARE/EXECUTE guard idiom (house
-- examples: migrations 037, 112, 138). All four are added AFTER the
-- existing `valuationDate` column, in the order listed.
-- -----------------------------------------------------------------------------

-- ➕ tblAssets.insurerName — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssets'
      AND COLUMN_NAME  = 'insurerName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssets` ADD COLUMN `insurerName` VARCHAR(150) DEFAULT NULL COMMENT ''Insurance provider name (#404)'' AFTER `valuationDate`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblAssets.insurancePolicyNumber — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssets'
      AND COLUMN_NAME  = 'insurancePolicyNumber'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssets` ADD COLUMN `insurancePolicyNumber` VARCHAR(100) DEFAULT NULL COMMENT ''Insurance policy/reference number (#404)'' AFTER `insurerName`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblAssets.insuredValuePence — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssets'
      AND COLUMN_NAME  = 'insuredValuePence'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssets` ADD COLUMN `insuredValuePence` INT DEFAULT NULL COMMENT ''Integer minor units — house pence convention (#266) (#404)'' AFTER `insurancePolicyNumber`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblAssets.insuranceRenewalDate — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssets'
      AND COLUMN_NAME  = 'insuranceRenewalDate'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssets` ADD COLUMN `insuranceRenewalDate` DATE DEFAULT NULL COMMENT ''Next insurance renewal due date — feeds the #405 reminder sweep (#404)'' AFTER `insuredValuePence`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- -----------------------------------------------------------------------------
-- ⚙️ Feature gates + tunables. api.assets.{list,detail,create,update,delete}
-- are the REST resource flags the #323 Phase 2 v1 facade
-- (`ApiRouter::dispatchV1()`) gates on — seeded here ahead of the actual
-- handler files landing in a later pass, same "flag seeded now, handler
-- lands later" precedent as migration 159's `api.assets.qr.enabled`.
-- `assets.cron_token` is intentionally seeded EMPTY (`isSensitive=1`) —
-- an admin must set a real token before `cron/asset-reminders.php`'s gate
-- (see that file) accepts any request; an empty stored token always 403s.
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.assets.list.enabled',                  'true', 'true', 0),
    (NULL, 'api.assets.detail.enabled',                'true', 'true', 0),
    (NULL, 'api.assets.create.enabled',                'true', 'true', 0),
    (NULL, 'api.assets.update.enabled',                'true', 'true', 0),
    (NULL, 'api.assets.delete.enabled',                'true', 'true', 0),
    (NULL, 'assets.cron_token',                        '',     '',     1),
    (NULL, 'assets.reminders_enabled',                 '1',    '1',    0),
    (NULL, 'assets.reminder_lead_days_maintenance',    '7',    '7',    0),
    (NULL, 'assets.reminder_lead_days_warranty',       '30',   '30',   0),
    (NULL, 'assets.reminder_lead_days_insurance',      '30',   '30',   0),
    (NULL, 'assets.scan_log_retention_days',           '365',  '365',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- -----------------------------------------------------------------------------
-- 🗺️ Routes. Five new page routes, each resolving to a documented "coming
-- in a later Phase 2 pass" stub shipped in this same change (see
-- _apps/assets/{import,my,value-report,event-assign}.php and
-- _apps/cron/asset-reminders.php) — check_route_targets.py stays green.
-- `cron/asset-reminders` is UNPROTECTED (isProtected=0, token-gated
-- internally instead) — mirrors every other cron/* route in this codebase
-- (e.g. `cron/event-reminders`, `cron/discipleship-sweep`). NEVER seed
-- `api/...` routes here — ApiRouter ignores tblRoutes entirely for
-- `api/*` paths (see .claude/CLAUDE.md → "ApiRouter routing trap"); the
-- five `api.assets.*.enabled` flags above are the correct (and only)
-- gating mechanism for those.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('assets/import',       'assets/import.php',       1),
    ('assets/my',           'assets/my.php',            1),
    ('assets/value-report', 'assets/value-report.php',  1),
    ('assets/event-assign', 'assets/event-assign.php',  1),
    ('cron/asset-reminders','cron/asset-reminders.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('160_asset_tracker_phase2.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
