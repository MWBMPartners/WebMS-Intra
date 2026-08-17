-- =============================================================================
-- 162_asset_kiosk_pin.sql — Asset Tracker Phase 3 Pass 5 (#414) foundation
-- -----------------------------------------------------------------------------
-- The kiosk TABLES (tblAssetKioskTokens, tblAssetKioskPins) already shipped in
-- migration 161; this migration adds only the two things Pass 5's kiosk logic
-- needs on TOP of them:
--
--   1. `tblAssetAudit.actorType` gains a `'kiosk'` value. A public kiosk
--      terminal has NO portal login session, so a kiosk check-in/out is
--      audited to the RESOLVED user (the person whose PIN unlocked the
--      action) with a DISTINCT actorType — an auditor can then filter
--      actorType='kiosk' to see every action taken on a shared, unattended
--      device, separately from a real logged-in 'user' action. Fulfils #414
--      acceptance criterion "Every kiosk action audited to the resolved
--      user". AssetRegister::audit() gains an explicit actor-id override
--      (Pass 5) so it can populate actorUserID for actorType='kiosk' without
--      a login session.
--
--   2. A new `assets/kiosk-pin` route — the self-service page where a
--      logged-in member SETS/CHANGES/CLEARS their own kiosk PIN
--      (`tblAssetKioskPins`). isProtected=1 (a normal session-gated internal
--      page — unlike the PUBLIC `assets/kiosk`/`assets/kiosk-action`
--      terminal routes seeded isProtected=0 in migration 161).
--
-- Portable DDL (DEV_NOTES.md → "Portable DDL convention (MySQL 8.0 ∩
-- MariaDB)"): the ENUM change goes through the information_schema +
-- PREPARE/EXECUTE guard idiom (house examples: migrations 037, 112, 138) so a
-- replay on an already-migrated schema is a genuine no-op — MODIFY COLUMN
-- itself is not rejected on replay, but guarding on COLUMN_TYPE keeps this
-- migration a strict no-op the same way every other ALTER in this codebase is.
-- The route seed uses ON DUPLICATE KEY UPDATE, idempotent by construction.
--
-- Production is MySQL 8.0 — no MariaDB-only `IF [NOT] EXISTS` on ALTER
-- anywhere below (that is ERROR 1064 on MySQL 8).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/392
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/414
-- =============================================================================


-- #############################################################################
-- 🧾 tblAssetAudit — add 'kiosk' to the actorType ENUM (guarded)
-- #############################################################################
-- Only MODIFY when the ENUM does not already carry 'kiosk', so a replay on an
-- up-to-date schema is a strict no-op.
SET @enum_has_kiosk := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssetAudit'
      AND COLUMN_NAME  = 'actorType'
      AND COLUMN_TYPE LIKE '%kiosk%'
);
SET @sql := IF(@enum_has_kiosk = 0,
    'ALTER TABLE `tblAssetAudit` MODIFY COLUMN `actorType` ENUM(''user'',''system'',''public'',''kiosk'') NOT NULL DEFAULT ''user''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🗺️ Route — self-service kiosk-PIN management page (session-gated)
-- #############################################################################
-- isProtected=1: a normal logged-in member manages THEIR OWN kiosk PIN here.
-- This is NOT the public terminal (assets/kiosk, assets/kiosk-action — those
-- were seeded isProtected=0 in migration 161).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('assets/kiosk-pin', 'assets/kiosk-pin.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('162_asset_kiosk_pin.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
