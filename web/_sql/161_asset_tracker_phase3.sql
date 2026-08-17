-- =============================================================================
-- Migration 161: Asset Tracker Phase 3 — schema foundation (#392 Phase 3 Pass 1)
-- =============================================================================
-- Schema-only foundation for Asset Tracker Phase 3. Ships five new tables
-- (stocktake/audit-mode #411, depreciation value-history #412, kiosk
-- check-in/out #414), two guarded ADD COLUMNs on the existing
-- `tblAssetIdentifiers` (GS1 Digital Link / GEPIR verify cache #415), the
-- feature-gate/tunable settings the later Phase 3 passes will read, and
-- route seeds (with a lightweight stub handler for each — see
-- _apps/assets/{stocktakes,stocktake,stocktake-save,kit-save,kiosks,
-- kiosk-save,kiosk,kiosk-action,identifier-verify}.php) so
-- check_route_targets.py stays green while the schema ships ahead of the
-- full stocktake/kiosk/kit/GEPIR logic. NO feature logic lands in this
-- pass — every handler below is a documented "coming in a later Phase 3
-- pass" placeholder; #413's parent/child kit feature itself needs no new
-- table (tblAssets.parentAssetID already exists from migration 159) —
-- only its `assets/kit-save` route + stub ship here.
--
-- Design notes:
--   • New tables use plain `CREATE TABLE IF NOT EXISTS` — brand new tables
--     are already idempotent and need no information_schema guard (that
--     idiom is only required for ALTER/ADD COLUMN/ADD INDEX on EXISTING
--     tables — see DEV_NOTES.md → "Portable DDL convention").
--   • The two `tblAssetIdentifiers` ADD COLUMNs (verifiedAt/verifyNote) DO
--     need the guard idiom — `tblAssetIdentifiers` already exists as of
--     migration 159, and MySQL 8 rejects MariaDB's
--     `ADD COLUMN IF NOT EXISTS` with ERROR 1064.
--   • UNIQUE FK CONSTRAINT NAMES: migrations 159/160's headers record the
--     Phase 1 `fk_ast_site` collision with the (unrelated) attendance
--     tables' own constraint names — every constraint name in THIS file
--     was checked against `full_schema.sql`'s existing `CONSTRAINT \`fk_`
--     inventory before being chosen. The prefixes `fk_astst_`
--     (tblAssetStocktakes), `fk_aststi_` (tblAssetStocktakeItems),
--     `fk_astvh_` (tblAssetValueHistory), `fk_astkt_`
--     (tblAssetKioskTokens) and `fk_astkp_` (tblAssetKioskPins) were all
--     verified collision-free (the only near-miss anywhere in the schema
--     is the unrelated `fk_ast_site` on the Attendance types table).
--   • Dependency order (this file, top to bottom): tblAssetStocktakes
--     (references tblSites + tblAssetLocations + tblAssetCategories +
--     tblUsers, all of which already exist) is created FIRST because
--     tblAssetStocktakeItems references IT via `fk_aststi_stocktake`;
--     tblAssetStocktakeItems (tblAssetStocktakes + tblSites + tblAssets +
--     tblUsers + tblAssetLocations) second; tblAssetValueHistory
--     (tblSites + tblAssets + tblUsers, independent of the stocktake
--     tables) third; tblAssetKioskTokens (tblSites + tblUsers) fourth;
--     tblAssetKioskPins (tblSites + tblUsers) fifth — THEN the
--     tblAssetIdentifiers ALTERs (guarded, order-independent on an
--     already-existing table), THEN seeds.
--   • KIOSK SECURITY (#414 lands the full token+PIN gate in a later
--     pass): `assets/kiosk` and `assets/kiosk-action` are seeded
--     `isProtected = 0` (PUBLIC routes) because a kiosk terminal is, by
--     definition, an unauthenticated shared device in a physical location
--     — the SAME "public by design, gated some other way" shape as
--     `assets/found-save` (migration 159) and `cron/asset-reminders`
--     (migration 160). `assets.kiosk_enabled` seeds `'false'` in THIS
--     migration, and this pass's stub handlers render a plain "not
--     enabled" page/404 while it stays false — no kiosk token, PIN hash,
--     asset data, or any other sensitive value is ever reachable through
--     either route until #414 lands the real token+PIN gate. See each
--     stub's own header for the exact behaviour.
--   • `tblAssetKioskPins.pinHash` follows the SAME "never plaintext"
--     convention as `tblAssets.licenseKey`/`tblLocalAccounts.passwordHash`
--     — a hash only, populated by the #414 pass, never read back.
--   • `api.assets.stocktake-scan.enabled` is seeded here ahead of its
--     handler's real logic landing (same "flag seeded now, handler lands
--     later" precedent as migration 159's `api.assets.qr.enabled` and
--     migration 160's five `api.assets.{list,detail,create,update,delete}.
--     enabled` flags) — this pass's `_apps/assets/api/stocktake-scan.php`
--     is a minimal `ApiAuth::requireWrite()`-gated 501 stub (see that
--     file's own header). NEVER seed `api/...` rows in `tblRoutes` — see
--     .claude/CLAUDE.md → "ApiRouter routing trap"; the flag above is the
--     correct (and only) gating mechanism for that endpoint.
--   • Follows the same replay/no-op discipline as migrations 159/160:
--     every CREATE is IF NOT EXISTS, every ALTER is guarded, every seed
--     INSERT is ON DUPLICATE KEY UPDATE, and the final tblMigrations
--     self-record uses the same idiom as every other migration in this
--     codebase.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/392
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/411
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/412
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/413
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/414
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/415
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📋 tblAssetStocktakes — a scan-to-verify stocktake/audit run, optionally
-- scoped to one location and/or one category (NULL = the whole site's
-- register). Opened by a manager, worked through via
-- `tblAssetStocktakeItems`, then closed. (#411)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetStocktakes` (
    `stocktakeID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `label`         VARCHAR(150) NOT NULL,
    `status`        ENUM('open','closed') NOT NULL DEFAULT 'open',
    `locationID`    INT          DEFAULT NULL COMMENT 'NULL = not scoped to a single location',
    `categoryID`    INT          DEFAULT NULL COMMENT 'NULL = not scoped to a single category',
    `startedByID`   INT          NOT NULL,
    `startedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closedByID`    INT          DEFAULT NULL,
    `closedAt`      DATETIME     DEFAULT NULL,
    `notes`         TEXT         DEFAULT NULL,
    PRIMARY KEY (`stocktakeID`),
    KEY `idx_astst_site_status` (`siteID`, `status`),
    CONSTRAINT `fk_astst_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astst_location` FOREIGN KEY (`locationID`)   REFERENCES `tblAssetLocations`(`locationID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astst_category` FOREIGN KEY (`categoryID`)   REFERENCES `tblAssetCategories`(`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astst_starter`  FOREIGN KEY (`startedByID`)  REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_astst_closer`   FOREIGN KEY (`closedByID`)   REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 3 — stocktake/audit-mode runs (#411)';


-- -----------------------------------------------------------------------------
-- ✅ tblAssetStocktakeItems — one row per asset EXPECTED in a stocktake run
-- (pre-populated from the stocktake's location/category scope at open
-- time — the actual pre-populate/scan logic lands in a later Phase 3
-- pass, not this one). `verifyStatus` tracks the scan outcome:
-- pending (not yet scanned) / present (scanned, in the expected place) /
-- missing (never scanned this run) / moved (scanned, but at a DIFFERENT
-- location — `foundLocationID` records where) / unexpected (scanned
-- during this run but wasn't in the original expected set). One row per
-- (stocktake, asset) pair — `uq_aststi_stocktake_asset` makes a re-scan
-- of the same asset within the same run an UPDATE, never a duplicate row.
-- (#411)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetStocktakeItems` (
    `itemID`          INT          NOT NULL AUTO_INCREMENT,
    `stocktakeID`     INT          NOT NULL,
    `siteID`          INT          NOT NULL DEFAULT 1,
    `assetID`         INT          NOT NULL,
    `verifyStatus`    ENUM('pending','present','missing','moved','unexpected') NOT NULL DEFAULT 'pending',
    `scannedByID`     INT          DEFAULT NULL,
    `scannedAt`       DATETIME     DEFAULT NULL,
    `foundLocationID` INT          DEFAULT NULL COMMENT 'Where the asset was actually scanned, when different from its recorded locationID (verifyStatus=moved)',
    `notes`           VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`itemID`),
    UNIQUE KEY `uq_aststi_stocktake_asset` (`stocktakeID`, `assetID`),
    KEY `idx_aststi_status` (`stocktakeID`, `verifyStatus`),
    KEY `idx_aststi_site` (`siteID`),
    CONSTRAINT `fk_aststi_stocktake` FOREIGN KEY (`stocktakeID`)     REFERENCES `tblAssetStocktakes`(`stocktakeID`) ON DELETE CASCADE,
    CONSTRAINT `fk_aststi_site`      FOREIGN KEY (`siteID`)          REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_aststi_asset`     FOREIGN KEY (`assetID`)         REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_aststi_scanner`   FOREIGN KEY (`scannedByID`)     REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_aststi_foundloc`  FOREIGN KEY (`foundLocationID`) REFERENCES `tblAssetLocations`(`locationID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 3 — per-asset stocktake scan results (#411)';


-- -----------------------------------------------------------------------------
-- 📈 tblAssetValueHistory — one row per (asset, date) computed/recorded book
-- value, feeding the #412 depreciation-trend view. `source` distinguishes
-- an automatic cron-computed value from a manager's manual valuation
-- entry; `method` records which depreciation method produced it (mirrors
-- `tblAssets.depreciationMethod`'s own ENUM, plus 'manual' for a
-- non-computed manager entry). `uq_astvh_asset_date` makes a same-day
-- re-run of the #412 cron an UPDATE-by-replace (via ON DUPLICATE KEY
-- UPDATE in that later pass's own INSERT), never a duplicate row. (#412)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetValueHistory` (
    `valueID`            INT      NOT NULL AUTO_INCREMENT,
    `siteID`             INT      NOT NULL DEFAULT 1,
    `assetID`            INT      NOT NULL,
    `valueDate`          DATE     NOT NULL,
    `currentValuePence`  INT      NOT NULL COMMENT 'Integer minor units — house pence convention (#266)',
    `method`             ENUM('straight-line','reducing-balance','manual') NOT NULL,
    `source`             ENUM('cron','manual') NOT NULL DEFAULT 'cron',
    `recordedByID`       INT      DEFAULT NULL COMMENT 'NULL for a cron-computed row; set for a manual valuation entry',
    `createdAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`valueID`),
    UNIQUE KEY `uq_astvh_asset_date` (`assetID`, `valueDate`),
    KEY `idx_astvh_site_date` (`siteID`, `valueDate`),
    CONSTRAINT `fk_astvh_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astvh_asset`    FOREIGN KEY (`assetID`)      REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astvh_recorder` FOREIGN KEY (`recordedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 3 — depreciation/valuation history (#412)';


-- -----------------------------------------------------------------------------
-- 🖥️ tblAssetKioskTokens — one row per registered kiosk TERMINAL (a shared,
-- unauthenticated device in a physical location, e.g. a tablet by the
-- equipment cupboard). `token` is the long-lived per-device credential
-- (opaque, 32 hex chars — mirrors `tblAssets.publicToken`'s own shape);
-- `isActive` lets an admin revoke a lost/decommissioned kiosk without
-- deleting its history. See file-header KIOSK SECURITY note — the real
-- token+PIN authentication flow lands in the #414 pass, not this one.
-- (#414)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetKioskTokens` (
    `tokenID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `label`        VARCHAR(150) NOT NULL COMMENT 'Human-readable name for the physical terminal, e.g. "AV cupboard tablet"',
    `token`        CHAR(32)     NOT NULL COMMENT '32-char hex per-device credential — never displayed again after creation (#414)',
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          NOT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lastSeenAt`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`tokenID`),
    UNIQUE KEY `uq_astkt_token` (`token`),
    KEY `idx_astkt_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_astkt_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astkt_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 3 — registered kiosk terminals (#414)';


-- -----------------------------------------------------------------------------
-- 🔢 tblAssetKioskPins — one optional short PIN per (site, user) letting a
-- member identify themselves at a shared kiosk terminal without a full
-- login (the terminal itself already authenticated via
-- `tblAssetKioskTokens`; the PIN identifies WHICH person is checking an
-- asset in/out on it). `pinHash` is a hash only — mirrors
-- `tblAssets.licenseKey`/`tblLocalAccounts.passwordHash`'s own
-- "never plaintext" convention — never read back once set. (#414)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetKioskPins` (
    `pinID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `userID`      INT          NOT NULL,
    `pinHash`     VARCHAR(255) NOT NULL COMMENT 'Hashed PIN — never plaintext, see table comment',
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lastUsedAt`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`pinID`),
    UNIQUE KEY `uq_astkp_site_user` (`siteID`, `userID`),
    CONSTRAINT `fk_astkp_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astkp_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker Phase 3 — per-user kiosk check-in/out PINs (#414)';


-- #############################################################################
-- 🧩 tblAssetIdentifiers — guarded ADD COLUMNs (GEPIR verify cache, #415)
-- #############################################################################
-- Portable DDL convention (DEV_NOTES.md) — MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` with ERROR 1064, so every column below goes
-- through the information_schema + PREPARE/EXECUTE guard idiom (house
-- examples: migrations 037, 112, 138). Both are added AFTER the existing
-- `isVerified` column — they cache the OUTCOME of the #415 GEPIR
-- (Global Electronic Party Information Registry) lookup for a GS1
-- identifier, so a manager isn't re-querying the external registry on
-- every page view.
-- -----------------------------------------------------------------------------

-- ➕ tblAssetIdentifiers.verifiedAt — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetIdentifiers'
      AND COLUMN_NAME  = 'verifiedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetIdentifiers` ADD COLUMN `verifiedAt` DATETIME DEFAULT NULL COMMENT ''Timestamp of the last #415 GEPIR verify-cache lookup for this identifier'' AFTER `isVerified`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblAssetIdentifiers.verifyNote — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetIdentifiers'
      AND COLUMN_NAME  = 'verifyNote'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetIdentifiers` ADD COLUMN `verifyNote` VARCHAR(255) DEFAULT NULL COMMENT ''Human-readable result of the last #415 GEPIR verify-cache lookup (e.g. registrant name, or a not-found/error note)'' AFTER `verifiedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- -----------------------------------------------------------------------------
-- ⚙️ Feature gates + tunables. `assets.kiosk_enabled` deliberately seeds
-- `'false'` — see file-header KIOSK SECURITY note; an admin must
-- explicitly opt in before either public kiosk route (`assets/kiosk`,
-- `assets/kiosk-action`) does anything beyond render a plain "not
-- enabled" page. `api.assets.stocktake-scan.enabled` gates the #411
-- stocktake-scan API endpoint the SAME way every other `api.assets.*`
-- flag gates its own handler (ApiRouter::resolveEnabledFlag(), NOT a
-- tblRoutes row — see .claude/CLAUDE.md → "ApiRouter routing trap").
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'assets.kiosk_enabled',                'false', 'false', 0),
    (NULL, 'assets.kiosk_idle_timeout_seconds',   '90',    '90',    0),
    (NULL, 'assets.kiosk_auto_checkout',          'true',  'true',  0),
    (NULL, 'assets.digital_link_enabled',         'true',  'true',  0),
    (NULL, 'assets.gepir_verify_enabled',         'false', 'false', 0),
    (NULL, 'assets.gepir_endpoint',               '',      '',      0),
    (NULL, 'api.assets.stocktake-scan.enabled',   'true',  'true',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- -----------------------------------------------------------------------------
-- 🗺️ Routes. Nine new page routes, each resolving to a documented "coming
-- in a later Phase 3 pass" stub shipped in this same change (see
-- _apps/assets/{stocktakes,stocktake,stocktake-save,kit-save,kiosks,
-- kiosk-save,kiosk,kiosk-action,identifier-verify}.php) — check_route_
-- targets.py stays green. `assets/kiosk` and `assets/kiosk-action` are
-- UNPROTECTED (isProtected=0) — see file-header KIOSK SECURITY note;
-- every other route below is a normal manager-gated internal page
-- (isProtected=1). NEVER seed `api/...` routes here — ApiRouter ignores
-- tblRoutes entirely for `api/*` paths (see .claude/CLAUDE.md →
-- "ApiRouter routing trap"); the `api.assets.stocktake-scan.enabled` flag
-- above is the correct (and only) gating mechanism for that endpoint.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('assets/stocktakes',        'assets/stocktakes.php',        1),
    ('assets/stocktake',         'assets/stocktake.php',         1),
    ('assets/stocktake-save',    'assets/stocktake-save.php',    1),
    ('assets/kit-save',          'assets/kit-save.php',          1),
    ('assets/kiosks',            'assets/kiosks.php',            1),
    ('assets/kiosk-save',        'assets/kiosk-save.php',        1),
    ('assets/kiosk',             'assets/kiosk.php',             0),
    ('assets/kiosk-action',      'assets/kiosk-action.php',      0),
    ('assets/identifier-verify', 'assets/identifier-verify.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('161_asset_tracker_phase3.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
