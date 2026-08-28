-- =============================================================================
-- 175_upce_barcode.sql — UPC-E barcode symbology for the Asset Tracker (#423)
-- -----------------------------------------------------------------------------
-- Follows the #422 EAN-8 precedent: `Portal\Core\Barcode` gains a `upce`
-- symbology (`Barcode::encodeUpce()`/`upceModules()` — zero-suppressed UPC,
-- 6 data digits + number system 0/1 + check digit, expanded to/compressed
-- from a full 12-digit UPC-A per the GS1 spec) and `AssetRegister` wires it
-- into `LABEL_SYMBOLOGIES`/`BARCODE_SYMBOLOGIES`/`GS1_IDENTIFIER_TYPE_CODES`
-- exactly like every other GS1-family symbology already there.
--
-- This migration widens ONE enum column so the new value can actually be
-- SAVED:
--
--   `tblAssets.labelSymbology` — was `ENUM('qr','code128','ean13','upca',
--   'itf14','qr+code128')`. Two things land here, not just 'upce':
--
--     1. `'upce'` — the actual point of #423.
--     2. `'ean8'` — a pre-existing gap from #422 discovered while widening
--        this same column for #423. #422 taught `Portal\Core\Barcode` and
--        `AssetRegister::LABEL_SYMBOLOGIES`/`BARCODE_SYMBOLOGIES`/
--        `GS1_IDENTIFIER_TYPE_CODES` about `'ean8'` (and it was already
--        listed as a valid PHP-side choice on `edit.php`'s dropdown/
--        `save.php`'s validation allow-list), but no migration ever added
--        `'ean8'` to THIS column's actual ENUM — so under MySQL 8's default
--        strict SQL mode, saving an asset with `labelSymbology='ean8'`
--        would fail its own INSERT/UPDATE outright (an invalid ENUM value
--        is a hard error in strict mode, not a silent truncation). Fixed
--        here in the SAME statement, since it is the identical column and
--        the identical class of fix `'upce'` itself needs.
--
-- `tblAssetIdentifierTypes` already carries a seeded `UPC-E` row
-- (migration 159, retail-barcode category, checkDigitScheme='gs1-mod10')
-- — that pre-dates this feature and is left untouched; it backs the
-- separate, independent "record an arbitrary GS1 identifier on an asset"
-- feature (`AssetRegister::validateIdentifier()`), not the barcode-
-- symbology path this migration completes. NOTE for a future reader: that
-- seeded 'gs1-mod10' scheme runs a PLAIN mod-10 check over whatever raw
-- value a manager types into that generic field, which is NOT how a real
-- UPC-E check digit works (see `Barcode::encodeUpce()`'s doc — the check
-- digit is derived from the value EXPANDED to a full 12-digit UPC-A, not
-- from the 8-digit compressed form directly), so that generic identifier-
-- entry field can produce a harmless-but-technically-inaccurate "check
-- digit does not match" warning for a genuine UPC-E value. This is
-- non-blocking by design (`validateIdentifier()`'s own doc: "NEVER blocks
-- a save") and pre-dates #423, so it is documented here rather than
-- changed — `Barcode::generate('upce', ...)`, which IS what actually
-- prints a label, performs the fully correct expansion-based check.
--
-- Portable DDL (DEV_NOTES.md → "Portable DDL convention (MySQL 8.0 ∩
-- MariaDB)"): the ENUM widen goes through the information_schema +
-- PREPARE/EXECUTE guard idiom exactly like the `162_asset_kiosk_pin.sql`
-- ENUM-widen precedent, so a replay on an already-migrated schema is a
-- genuine no-op (MODIFY COLUMN to a fixed target is itself re-runnable —
-- the guard here only avoids a needless table rebuild on every replay).
--
-- Production is MySQL 8.0 — no MariaDB-only `IF [NOT] EXISTS` on ALTER
-- anywhere below (that is ERROR 1064 on MySQL 8).
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in
-- this file — check_schema_seed_parity.py strips from any "--" to
-- end-of-line, even inside quotes. Use an em/en dash instead.
--
-- @see https://www.gs1.org/standards/barcodes/ean-upc GS1 EAN/UPC spec
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/423
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/422
-- =============================================================================


-- #############################################################################
-- 🟧 tblAssets.labelSymbology — widen ENUM to add 'ean8' + 'upce' (guarded)
-- #############################################################################

-- Only MODIFY when the ENUM does not already carry BOTH new values, so a
-- replay on an up-to-date schema is a strict no-op.
SET @enum_has_both := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAssets'
      AND COLUMN_NAME  = 'labelSymbology'
      AND COLUMN_TYPE LIKE '%ean8%'
      AND COLUMN_TYPE LIKE '%upce%'
);
SET @sql := IF(@enum_has_both = 0,
    'ALTER TABLE `tblAssets` MODIFY COLUMN `labelSymbology` ENUM(''qr'',''code128'',''ean13'',''ean8'',''upca'',''upce'',''itf14'',''qr+code128'') NOT NULL DEFAULT ''qr'' COMMENT ''Preferred symbology for this asset''''s printed label (labels sub-issue) — widened for ean8/upce, migration 175 (#423)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('175_upce_barcode.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
