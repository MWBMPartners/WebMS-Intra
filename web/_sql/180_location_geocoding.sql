-- =============================================================================
-- Migration 180: Location / Geocoordinates / What3Words platform layer (#456)
-- =============================================================================
-- Chunk A (foundation, non-PII, interactive map) of the location/geocoding
-- feature. No PII table is touched here — tblUsers/directory/GiftAid/
-- Salvation land in a separate later migration (Chunk B). Every setting
-- introduced here defaults the feature OFF (w3w.enabled='false',
-- geo.autoGeocode='false') so an upgrade is a functional no-op: no new
-- network I/O fires until an admin opts in.
--
-- Schema (all guarded -- see DEV_NOTES.md -> "Portable DDL convention
-- (MySQL 8.0 and MariaDB)"; MySQL 8 rejects MariaDB's ADD COLUMN/ADD INDEX
-- IF NOT EXISTS with ERROR 1064, so every ALTER below goes through the
-- information_schema + PREPARE/EXECUTE guard idiom, house examples:
-- migrations 037, 112, 138, 166):
--   1. tblVenues gains the standard five-column location block (latitude,
--      longitude, what3words, geocodedAt, geocodeSource).
--   2. tblResource gains the same five columns.
--   3. tblAssetLocations gains the same five columns.
--   4. tblEventOccurrenceOverrides gains overrideGeoLat/overrideGeoLng/
--      overrideW3W (NULL = inherit the parent event's coordinates/W3W;
--      no geocode-provenance pair on purpose -- override rows are
--      hand-entered only, never auto-geocoded).
--   5. NEW tblGeocodeCache -- global (no siteID; an address geocodes
--      identically for every tenant), plain CREATE TABLE IF NOT EXISTS
--      (standard MySQL, always safe to re-run).
-- No tblEvents change -- it already carries the full location model
-- (locationGeoLat/locationGeoLng/locationW3W/...) as of the original
-- schema; the events REST API instead gains a canonical `location` JSON
-- object emitted via GeoLocation::toLocationObject() (contract §2/§7),
-- mapping the historical column names onto the wire shape shared with
-- ProjectBookIT/ProjectEPass.
--
-- Cross-repo CONTRACT: this feature's column shapes, canonical what3words
-- form (bare lowercase word.word.word, no leading slashes), and JSON wire
-- object are a DATA-FORMAT CONTRACT shared with ProjectBookIT/ProjectEPass
-- -- there is NO runtime dependency on either repo; every geocode/W3W call
-- this portal makes is entirely self-contained (see GeoLocation.php /
-- What3Words.php / Geocoder.php docblocks).
--
-- Settings seeded: w3w.enabled/apiKey, geo.google.apiKey/autoGeocode,
-- geo.nominatim.lastCallAt (operational throttle state, stored as a
-- setting so the Nominatim <=1 rps policy survives across requests), nine
-- org.* keys for the site-HQ address admin page. Both API keys are seeded
-- EMPTY with isSensitive=1 (166's webhooks.cron_token precedent -- never a
-- seeded secret value).
--
-- Routes seeded: three what3words integration admin pages, three geocoding
-- integration admin pages, two organisation-address admin pages, and two
-- session-authed AJAX proxy endpoints (geo/w3w-suggest, geo/lookup) --
-- deliberately NOT under api/* (Router never consults tblRoutes for api/*
-- paths -- see CLAUDE.md's ApiRouter routing trap), so these ARE real
-- tblRoutes rows, all isProtected=1 (login required).
--
-- Editor note: never a literal double-hyphen inside any SQL string in this
-- file (check_schema_seed_parity.py strips "--" as a line comment marker)
-- -- use an en dash instead, as several comments above already do.
--
-- Replay-proof: every ALTER is information_schema-guarded, the new TABLE
-- uses IF NOT EXISTS, both seed INSERTs use ON DUPLICATE KEY UPDATE, and
-- the trailing tblMigrations self-record is idempotent. Running this file
-- twice against an up-to-date schema is a full no-op.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/456
-- =============================================================================

-- #############################################################################
-- SCHEMA
-- #############################################################################

-- -----------------------------------------------------------------------------
-- tblVenues -- standard five-column location block, AFTER countryCode
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'latitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblVenues` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL AFTER `countryCode`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'longitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblVenues` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'what3words'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblVenues` ADD COLUMN `what3words` VARCHAR(100) DEFAULT NULL COMMENT ''what3words address'' AFTER `longitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'geocodedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblVenues` ADD COLUMN `geocodedAt` DATETIME DEFAULT NULL COMMENT ''When lat/lng last set by the Geocoder (migration 180)'' AFTER `what3words`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblVenues' AND COLUMN_NAME = 'geocodeSource'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblVenues` ADD COLUMN `geocodeSource` VARCHAR(20) DEFAULT NULL COMMENT ''google, nominatim, manual or w3w'' AFTER `geocodedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- tblResource -- same five columns, AFTER location
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblResource' AND COLUMN_NAME = 'latitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblResource` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL AFTER `location`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblResource' AND COLUMN_NAME = 'longitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblResource` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblResource' AND COLUMN_NAME = 'what3words'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblResource` ADD COLUMN `what3words` VARCHAR(100) DEFAULT NULL COMMENT ''what3words address'' AFTER `longitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblResource' AND COLUMN_NAME = 'geocodedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblResource` ADD COLUMN `geocodedAt` DATETIME DEFAULT NULL COMMENT ''When lat/lng last set by the Geocoder (migration 180)'' AFTER `what3words`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblResource' AND COLUMN_NAME = 'geocodeSource'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblResource` ADD COLUMN `geocodeSource` VARCHAR(20) DEFAULT NULL COMMENT ''google, nominatim, manual or w3w'' AFTER `geocodedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- tblAssetLocations -- same five columns, AFTER details
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAssetLocations' AND COLUMN_NAME = 'latitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetLocations` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL AFTER `details`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAssetLocations' AND COLUMN_NAME = 'longitude'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetLocations` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAssetLocations' AND COLUMN_NAME = 'what3words'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetLocations` ADD COLUMN `what3words` VARCHAR(100) DEFAULT NULL COMMENT ''what3words address'' AFTER `longitude`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAssetLocations' AND COLUMN_NAME = 'geocodedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetLocations` ADD COLUMN `geocodedAt` DATETIME DEFAULT NULL COMMENT ''When lat/lng last set by the Geocoder (migration 180)'' AFTER `what3words`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblAssetLocations' AND COLUMN_NAME = 'geocodeSource'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAssetLocations` ADD COLUMN `geocodeSource` VARCHAR(20) DEFAULT NULL COMMENT ''google, nominatim, manual or w3w'' AFTER `geocodedAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- tblEventOccurrenceOverrides -- hand-entered override coords/W3W only
-- (NULL = inherit the parent event's own coords/W3W). No geocode-provenance
-- pair -- overrides are never auto-geocoded.
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEventOccurrenceOverrides' AND COLUMN_NAME = 'overrideGeoLat'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventOccurrenceOverrides` ADD COLUMN `overrideGeoLat` DECIMAL(10,7) DEFAULT NULL COMMENT ''NULL = inherit parent event coords'' AFTER `overrideLocation`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEventOccurrenceOverrides' AND COLUMN_NAME = 'overrideGeoLng'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventOccurrenceOverrides` ADD COLUMN `overrideGeoLng` DECIMAL(10,7) DEFAULT NULL COMMENT ''NULL = inherit parent event coords'' AFTER `overrideGeoLat`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEventOccurrenceOverrides' AND COLUMN_NAME = 'overrideW3W'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEventOccurrenceOverrides` ADD COLUMN `overrideW3W` VARCHAR(100) DEFAULT NULL COMMENT ''what3words override — NULL = inherit'' AFTER `overrideGeoLng`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- NEW tblGeocodeCache -- global geocode result cache (no siteID; an
-- address geocodes identically for every tenant). Plain CREATE TABLE IF
-- NOT EXISTS is standard MySQL, always safe to re-run.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblGeocodeCache` (
    `cacheID`     INT           NOT NULL AUTO_INCREMENT,
    `queryHash`   CHAR(64)      NOT NULL COMMENT 'sha256(direction | lowercased query text)',
    `direction`   ENUM('forward','reverse') NOT NULL DEFAULT 'forward',
    `queryText`   VARCHAR(500)  NOT NULL,
    `latitude`    DECIMAL(10,7) DEFAULT NULL,
    `longitude`   DECIMAL(10,7) DEFAULT NULL,
    `formatted`   VARCHAR(500)  DEFAULT NULL COMMENT 'Provider display/formatted address',
    `provider`    VARCHAR(20)   NOT NULL COMMENT 'google or nominatim',
    `hitCount`    INT           NOT NULL DEFAULT 1,
    `createdAt`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lastUsedAt`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`cacheID`),
    UNIQUE KEY `uq_geoc_hash` (`queryHash`),
    KEY `idx_geoc_lastused` (`lastUsedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Geocoder result cache — an address geocodes once (Nominatim policy) (migration 180)';

-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- ⚙️ Settings -- w3w.apiKey / geo.google.apiKey seeded EMPTY with
-- isSensitive=1 (166's webhooks.cron_token precedent -- never a seeded
-- secret value). w3w.enabled and geo.autoGeocode both default 'false' --
-- no network I/O appears on upgrade. geo.nominatim.lastCallAt is
-- operational throttle state stored as a setting. The nine org.* keys
-- back the /admin/settings/organisation site-HQ address page.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'w3w.enabled',              'false', 'false', 0),
    (NULL, 'w3w.apiKey',               '',      '',      1),
    (NULL, 'geo.google.apiKey',        '',      '',      1),
    (NULL, 'geo.autoGeocode',          'false', 'false', 0),
    (NULL, 'geo.nominatim.lastCallAt', '',      '',      0),
    (NULL, 'org.address.line1',        '',      '',      0),
    (NULL, 'org.address.line2',        '',      '',      0),
    (NULL, 'org.address.city',         '',      '',      0),
    (NULL, 'org.address.region',       '',      '',      0),
    (NULL, 'org.address.postcode',     '',      '',      0),
    (NULL, 'org.address.countryCode',  'GB',    'GB',    0),
    (NULL, 'org.latitude',             '',      '',      0),
    (NULL, 'org.longitude',            '',      '',      0),
    (NULL, 'org.what3words',           '',      '',      0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🗺️ Routes -- all isProtected=1 (login required). NO api/* rows -- Router
-- never consults tblRoutes for api/* paths (see CLAUDE.md's ApiRouter
-- routing trap) and none of these are ApiRouter convention-path endpoints.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/what3words',      'admin/integrations/what3words/index.php', 1),
    ('admin/integrations/what3words/save', 'admin/integrations/what3words/save.php',  1),
    ('admin/integrations/what3words/test', 'admin/integrations/what3words/test.php',  1),
    ('admin/integrations/geocoding',       'admin/integrations/geocoding/index.php',  1),
    ('admin/integrations/geocoding/save',  'admin/integrations/geocoding/save.php',   1),
    ('admin/integrations/geocoding/test',  'admin/integrations/geocoding/test.php',   1),
    ('admin/settings/organisation',        'admin/settings/organisation/index.php',   1),
    ('admin/settings/organisation/save',   'admin/settings/organisation/save.php',    1),
    ('geo/w3w-suggest',                    'geo/w3w-suggest.php',                     1),
    ('geo/lookup',                         'geo/lookup.php',                          1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('180_location_geocoding.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
