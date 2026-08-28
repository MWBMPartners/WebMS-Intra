-- =============================================================================
-- Migration 181: Location / Geocoordinates / What3Words — member PII (#456)
-- =============================================================================
-- Chunk B (PII + GDPR lockstep) of the location/geocoding feature. Adds
-- FOUR guarded columns to `tblUsers` ONLY — a member's own home
-- coordinates + what3words + their independent visibility tier. No other
-- table gains a location column in this migration: GiftAid/Salvation keep
-- their existing free-text `address`/`postcode` columns (structured-input
-- UI reuse only, see the PR description), and Kids/Care/Visitors are
-- explicitly excluded (safeguarding data, no consent mechanism for a map
-- pin).
--
-- Schema (both guarded -- see DEV_NOTES.md -> "Portable DDL convention
-- (MySQL 8.0 and MariaDB)"; MySQL 8 rejects MariaDB's ADD COLUMN IF NOT
-- EXISTS with ERROR 1064, so every ALTER below goes through the
-- information_schema + PREPARE/EXECUTE guard idiom, house examples:
-- migrations 037, 112, 138, 166, 180):
--   1. `tblUsers.latitude`         DECIMAL(10,7) NULL, AFTER displayAddress
--   2. `tblUsers.longitude`        DECIMAL(10,7) NULL, AFTER latitude
--   3. `tblUsers.what3words`       VARCHAR(100)  NULL, AFTER longitude
--   4. `tblUsers.visibilityCoords` ENUM('private','team','members','public')
--      NOT NULL DEFAULT 'private', AFTER visibilityAddress
--
-- Deliberately NO `geocodedAt`/`geocodeSource` pair here (unlike the
-- standard five-column block migration 180 added to
-- tblVenues/tblResource/tblAssetLocations) -- member addresses are NEVER
-- auto-geocoded. `geo.autoGeocode` (migration 180) does not apply to
-- tblUsers anywhere in this codebase; a member's coordinates are set only
-- by their OWN explicit "Look up coordinates" action (directory/me.php)
-- or hand entry, so provenance is always "the member themself" and a
-- provenance column would carry no information.
--
-- `visibilityCoords` is deliberately INDEPENDENT of the existing
-- `visibilityAddress` column -- consent to show address TEXT never implies
-- consent to show a map PIN (a pin is more precise and more automatable to
-- scrape). Both default 'private'. See directory/profile.php's `$can()`
-- gate for how the two are checked separately.
--
-- No new settings keys, no new routes -- this migration is exactly four
-- guarded ALTERs plus the self-record; `check_schema_seed_parity.py` has
-- nothing to diff beyond the (seedless) trailing full_schema.sql block.
--
-- GDPR lockstep (mandatory, shipped in the SAME PR as this migration --
-- never a PII column without its export/erasure wiring):
--   - `auth/account/data-export.php`     -- tblUsers `SELECT *` already
--     exports these four columns; a new `giftAidDeclarations` export
--     block closes a pre-existing, unrelated address-PII export gap
--     found during this feature's build.
--   - `auth/account/delete-confirm.php`  -- tblUsers anonymise UPDATE
--     extended to null displayAddress/displayPhone (pre-existing miss)
--     plus these four new columns (visibilityCoords reset to 'private').
--   - `Portal\Core\GdprEraser::catalogue()` -- tblUsers `nullCols` extended
--     with latitude/longitude/what3words.
--
-- Editor note: never a literal double-hyphen inside any SQL string in this
-- file (check_schema_seed_parity.py strips "--" as a line comment marker)
-- -- use an en dash instead, as this header already does throughout.
--
-- Replay-proof: every ALTER is information_schema-guarded and the
-- trailing tblMigrations self-record is idempotent (ON DUPLICATE KEY
-- UPDATE). Running this file twice against an up-to-date schema is a
-- full no-op.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/456
-- =============================================================================

-- #############################################################################
-- SCHEMA
-- #############################################################################

-- -----------------------------------------------------------------------------
-- tblUsers.latitude -- member home coordinates, PRIVATE by default
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers' AND COLUMN_NAME = 'latitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL COMMENT ''Member home coordinates — PRIVATE by default; see visibilityCoords (migration 181)'' AFTER `displayAddress`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- tblUsers.longitude
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers' AND COLUMN_NAME = 'longitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- tblUsers.what3words
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers' AND COLUMN_NAME = 'what3words'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `what3words` VARCHAR(100) DEFAULT NULL COMMENT ''what3words address'' AFTER `longitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- tblUsers.visibilityCoords -- INDEPENDENT of visibilityAddress; a member
-- sharing their address TEXT never implies consent to show a map PIN.
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUsers' AND COLUMN_NAME = 'visibilityCoords'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblUsers` ADD COLUMN `visibilityCoords` ENUM(''private'',''team'',''members'',''public'') NOT NULL DEFAULT ''private'' COMMENT ''Coordinate visibility — INDEPENDENT of visibilityAddress; consent for address text never implies consent for a map pin (migration 181)'' AFTER `visibilityAddress`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('181_location_user_pii.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
