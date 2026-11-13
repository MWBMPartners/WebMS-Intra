-- =============================================================================
-- Migration 290: the address look-up cache keeps no address text (#1037)
-- =============================================================================
-- What this changes:
--
--   A. Deletes every REVERSE row of `tblGeocodeCache`. A reverse row's answer
--      WAS an address (in `formatted`), and `Geocoder::reverse()` no longer
--      uses the cache at all, so without that column such a row is useless.
--      (No page has ever called reverse(); in practice there are none.)
--   B. Drops `tblGeocodeCache`.`queryText` — the address as somebody typed it.
--   C. Drops `tblGeocodeCache`.`formatted` — the provider's wording of it.
--   D. Adds `tblGeocodeCache`.`addressKind` ('home' or 'public', D-183), and
--      in the same step deletes every row the cache held before it: such a
--      row cannot say whether its address was somebody's home, and a home
--      row with a plain, unsalted key is exactly what D-183 rules out. The
--      delete runs ONLY when the column is being added, so a second run, or
--      the installer's replay over a fresh install, deletes nothing. The
--      cost is one fresh look-up per address.
--   E. Seeds `geo.cache.retentionDays` = '365', installation-wide: how long a
--      remembered answer may go unused before the nightly clear-out deletes
--      it (D-182). Each organisation may choose a shorter figure of its own;
--      `Portal\Core\GeocodeCacheRetention` is the one place that decides.
--      The usual settings-seed shape: it writes the built-in default and
--      NEVER overwrites a value somebody has chosen.
--
-- Steps A-C keep the forward rows; step D then removes every row there was,
-- because none of them can say whether it was somebody's home (D-183).
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- An address typed into the portal can be a member's home: a small group's
-- host (#809), or any address a member typed for their own record. The cache
-- kept that text, nothing ever removed a cache row, and nothing in a row names
-- a person, so an erasure request could never reach it — a home address
-- outlived the record it came from, for ever. The personal-data catalogue also
-- called the table "about places, not people", which was untrue.
--
-- Neither column was needed. A row is FOUND by `queryHash`, which is SHA-256
-- of the lower-cased address and country, and no caller used `formatted`.
-- So rather than teach an erasure to find addresses, the cache stops keeping
-- them (the second of the two remedies #1037 allows).
--
-- How long a row is kept at all is step E (the owner's decision, D-182).
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- The `information_schema` + `PREPARE`/`EXECUTE` guard drops each column only
-- while it is still there, the same shape as migration 235. MySQL 8.0 has no
-- `DROP COLUMN IF EXISTS` (that is MariaDB-only; see .claude/rules/database.md).
-- The DELETE in step A must run BEFORE step C on a database that still has
-- the column, and is harmless after it: it reads only `direction`. SAFE TO RUN
-- TWICE: a second run deletes nothing and both guards run `SELECT 1`.
-- The table exists on every database this can run on: migration 180 creates
-- it, and the install script holds it.
--
-- A snapshot taken before this migration still holds both columns; they are on
-- `DbBackup::REMOVED_COLUMNS`, so a restore discards that text rather than
-- being refused.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/1037
-- =============================================================================

-- 🧹 A. reverse rows
DELETE FROM `tblGeocodeCache` WHERE `direction` = 'reverse';

-- 🧹 B. tblGeocodeCache.queryText
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGeocodeCache'
      AND COLUMN_NAME  = 'queryText'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblGeocodeCache` DROP COLUMN `queryText`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧹 C. tblGeocodeCache.formatted
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGeocodeCache'
      AND COLUMN_NAME  = 'formatted'
);
SET @sql := IF(@col_exists = 1,
    'ALTER TABLE `tblGeocodeCache` DROP COLUMN `formatted`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔤 The key column's comment now says the address itself is not kept. The
--    definition is restated word for word apart from the comment; a second
--    run sets the same definition again and changes nothing.
ALTER TABLE `tblGeocodeCache`
    MODIFY COLUMN `queryHash` CHAR(64) NOT NULL COMMENT 'sha256(direction | lowercased query text). One-way: the address itself is not stored (#1037)';
ALTER TABLE `tblGeocodeCache`
    COMMENT = 'Geocoder result cache — an address geocodes once (Nominatim policy); keeps no address text (migrations 180, 290)';

-- 🏠 D. home or public place, and the rows that cannot say which
SET @kind_missing := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblGeocodeCache'
      AND COLUMN_NAME  = 'addressKind'
);
SET @sql := IF(@kind_missing = 1, 'DELETE FROM `tblGeocodeCache`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@kind_missing = 1,
    'ALTER TABLE `tblGeocodeCache` ADD COLUMN `addressKind` ENUM(''home'',''public'') NOT NULL DEFAULT ''home'' COMMENT ''home: somebody''''s own address, personal data, key mixed with the installation''''s secret; public: a venue, business or the organisation (D-183, migration 290)'' AFTER `provider`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🗺️ E. how long a remembered answer is kept
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'geo.cache.retentionDays', '365', '365', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('290_geocode_cache_keeps_no_address.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
