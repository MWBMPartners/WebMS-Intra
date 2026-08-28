-- =============================================================================
-- Migration 178: Hymnal lookup + public Order of Service (gap #128 residual)
--
-- Path: web/_sql/178_hymnal_lookup_public_oos.sql
-- Re-scoped #128 ("Order of Service planner with iHymns integration") — the
-- build-ready plan's verdict is "largely already covered" by service-plans
-- (#262/#300) + the Worship Presentation Engine (#308/#355): item CRUD,
-- reorder, presenters, durations, notes, templates, event linking, and the
-- song library/CCLI log all already ship. This migration is ONLY the
-- genuine residual: (1) a local hymnal metadata index + lookup, (2) an
-- optional default-OFF generic remote ("iHymns") HTTPS client, (3) a
-- congregation-facing public Order of Service view, (4) a nullable songID
-- glue column so a picked hymn/song is stored canonically. Additive
-- throughout — NO third service-plan data model, NO new app.
--
-- Migration number note: the plan's own draft called this "177", but 177 is
-- reserved for the in-flight #322 webpush renumber (176 = in-flight shared-
-- mailbox work) — re-scanned web/_sql/ + sibling branches at build time
-- (alpha tops out at 175_upce_barcode.sql; 176/177 do not exist on disk on
-- any branch this session could see, but the reservation instruction
-- stands), so this migration is 178.
--
-- ── R1: Local hymnal index (Tier 1 — the primary ship) ──────────────────────
-- `tblHymnals` (one hymn book per site, e.g. "SDAH", "Mission Praise") +
-- `tblHymnalEntries` (one hymn's METADATA in one hymnal — number, title,
-- optional tune/meter/author/CCLI/copyright — NEVER lyrics; lyrics stay
-- hand-entered into tblSongs.lyrics under the church's own CCLI licence,
-- same as today). Multi-hymnal by design. FULLTEXT search on
-- title/firstLine/author/tuneName.
--
-- ── R2: Remote lookup (Tier 2 — generic HTTPS client, default OFF) ──────────
-- `tblHymnLookupCache` — a 24h server-side cache of remote search results,
-- keyed on (siteID, SHA-256(normalised query)), so a repeat search never
-- re-hits the remote host even while the feature is on. The remote client
-- itself (`Portal\Core\Hymnal::searchRemote()`) is PHP-only — no schema
-- object beyond this cache table — and is default-off (`hymns.remote.
-- enabled = 'false'`) until an owner supplies + confirms a host (the
-- issue's "written permission from iHymns" acceptance criterion).
--
-- ── R3: Public Order of Service (`/os/{token}`) ─────────────────────────────
-- `tblServicePlan` gains `publicToken` (mirrors `tblAssets.publicToken` —
-- `159_asset_tracker.sql:157,167` — bin2hex(random_bytes(16))) and
-- `isPublicShared`. Both default such that EVERY existing plan today is
-- unshared (off at the plan level) — combined with the global
-- `service_plans.public_share.enabled` site-level kill-switch (also default
-- OFF), the public view is off-by-default at BOTH levels per the plan's
-- decided-default OPEN QUESTIONS. The `/os/{token}` route itself is a
-- Router special-route block (cloned from `/a/{token}`), deliberately NOT a
-- tblRoutes row — see Router.php. `service-plans/share.php` (route seeded
-- below) is the CSRF'd enable/disable/rotate handler.
--
-- ── R4 (glue): tblServicePlanItem.songID ────────────────────────────────────
-- Nullable FK -> tblSongs(songID) ON DELETE SET NULL. A picked hymn/song is
-- (by default) auto-promoted into tblSongs and linked here — one canonical
-- song entity shared with the worship app's CCLI log/"last sung" — while
-- free-text `title` remains the universal, always-working fallback (every
-- existing row has songID NULL and renders byte-identically).
--
-- tblSongs also gains `hymnalCode`/`hymnNumber`/`tuneName` so a promoted
-- hymn keeps its hymnal identity (surfaced in worship/song.php + songs.php).
--
-- ALL DDL below goes through the information_schema + PREPARE/EXECUTE guard
-- idiom (MySQL 8 rejects MariaDB's `IF [NOT] EXISTS` on ALTER with ERROR
-- 1064 — DEV_NOTES.md "Portable DDL convention"; house precedents:
-- migrations 037, 112, 138, 151, 173). Replays as a no-op on an up-to-date
-- schema; zero data migration.
--
-- New routes (all isProtected=1 — ordinary session-gated pages; NOT api/*,
-- so tblRoutes IS how these are gated, unlike hymn-search below):
--   admin/hymns       -> admin/hymns.php
--   admin/hymns/save  -> admin/hymns-save.php
--   service-plans/share -> service-plans/share.php
-- Deliberately NOT seeded: `/os/{token}` (Router special route, bypasses
-- tblRoutes entirely — same mid-migration-safety rationale as `/a/{token}`)
-- and NO api/* row for hymn-search (ApiRouter never consults tblRoutes for
-- api/* paths — CLAUDE.md "ApiRouter routing trap"; the settings flag below
-- is how that endpoint is gated).
--
-- New settings (tblSettings, siteID NULL = global default):
--   hymns.remote.enabled   = 'false' (default OFF — the issue's permission gate)
--   hymns.remote.host      = ''      (single-host allowlist — SSRF hardening)
--   hymns.remote.baseUrl   = ''      (must be https:// on the allowlisted host)
--   hymns.remote.apiKey    = ''      (isSensitive=1 — encrypted at rest, never logged)
--   hymns.remote.cacheTtl  = '86400' (seconds — 24h)
--   service_plans.public_share.enabled = 'false' (per-site opt-in for /os/)
--   api.service-plans.hymn-search.enabled = 'true' (ApiRouter gating flag —
--     the endpoint itself still requires a logged-in session; this flag only
--     controls whether ApiRouter will dispatch to it at all)
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/128
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 📖 tblHymnals — one hymn book known to a site (multi-hymnal by design)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblHymnals` (
    `hymnalID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`     INT          NOT NULL,
    `code`       VARCHAR(20)  NOT NULL COMMENT 'Short code, e.g. CH, SDAH, MP',
    `name`       VARCHAR(120) NOT NULL COMMENT 'Human-readable hymnal name',
    `publisher`  VARCHAR(255) DEFAULT NULL,
    `isActive`   TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`hymnalID`),
    UNIQUE KEY `uq_hymnal_site_code` (`siteID`, `code`),
    KEY `idx_hymnal_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_hymnal_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Gap #128 residual — hymn books known to a site (metadata index, no lyrics)';

-- -----------------------------------------------------------------------------
-- 📖 tblHymnalEntries — one hymn's METADATA in one hymnal. NEVER lyrics.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblHymnalEntries` (
    `entryID`        INT          NOT NULL AUTO_INCREMENT,
    `hymnalID`       INT          NOT NULL,
    `number`         VARCHAR(20)  NOT NULL COMMENT 'Supports non-numeric suffixes, e.g. "256a"',
    `numberSort`     INT          NOT NULL DEFAULT 0 COMMENT 'Numeric prefix of number, for ordering',
    `title`          VARCHAR(255) NOT NULL,
    `firstLine`      VARCHAR(255) DEFAULT NULL,
    `author`         VARCHAR(255) DEFAULT NULL,
    `tuneName`       VARCHAR(120) DEFAULT NULL,
    `meter`          VARCHAR(40)  DEFAULT NULL,
    `ccliNumber`     VARCHAR(40)  DEFAULT NULL,
    `copyrightLine`  VARCHAR(500) DEFAULT NULL,
    `sourceRef`      VARCHAR(255) DEFAULT NULL COMMENT 'External id/URL when sourced from a remote provider (e.g. iHymns)',
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`entryID`),
    UNIQUE KEY `uq_hymnalentry` (`hymnalID`, `number`),
    KEY `idx_hymnalentry_sort` (`hymnalID`, `numberSort`),
    FULLTEXT KEY `ft_hymnal_search` (`title`, `firstLine`, `author`, `tuneName`),
    CONSTRAINT `fk_hymnalentry_hymnal` FOREIGN KEY (`hymnalID`) REFERENCES `tblHymnals`(`hymnalID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Gap #128 residual — hymn metadata only (number/title/tune/author/CCLI). No lyrics.';

-- -----------------------------------------------------------------------------
-- ⏱️ tblHymnLookupCache — short-TTL remote-result cache (site-scoped)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblHymnLookupCache` (
    `cacheID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL,
    `queryHash`    CHAR(64)     NOT NULL COMMENT 'SHA-256 of the normalised query+hymnal',
    `resultsJson`  MEDIUMTEXT   NOT NULL,
    `fetchedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`cacheID`),
    UNIQUE KEY `uq_hymncache` (`siteID`, `queryHash`),
    KEY `idx_hymncache_fetched` (`fetchedAt`),
    CONSTRAINT `fk_hymncache_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Gap #128 residual — 24h cache of Tier-2 remote hymn-search results, never load-bearing';

-- -----------------------------------------------------------------------------
-- ➕ tblSongs.hymnalCode / hymnNumber / tuneName — guarded ADD COLUMN
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'hymnalCode'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSongs` ADD COLUMN `hymnalCode` VARCHAR(20) DEFAULT NULL COMMENT ''Hymnal code this song was promoted from (gap #128, migration 178)'' AFTER `tags`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'hymnNumber'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSongs` ADD COLUMN `hymnNumber` VARCHAR(20) DEFAULT NULL COMMENT ''Hymn number within hymnalCode (gap #128, migration 178)'' AFTER `hymnalCode`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'tuneName'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSongs` ADD COLUMN `tuneName` VARCHAR(120) DEFAULT NULL COMMENT ''Tune name, carried over from a promoted hymnal entry (gap #128, migration 178)'' AFTER `hymnNumber`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- ➕ tblServicePlanItem.songID — guarded ADD COLUMN + KEY + FK (glue, R4)
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServicePlanItem' AND COLUMN_NAME = 'songID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlanItem` ADD COLUMN `songID` INT DEFAULT NULL COMMENT ''Optional canonical song reference -> tblSongs.songID (gap #128, migration 178)'' AFTER `title`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServicePlanItem' AND INDEX_NAME = 'idx_spi_song'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblServicePlanItem` ADD KEY `idx_spi_song` (`songID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServicePlanItem'
      AND CONSTRAINT_NAME = 'fk_spi_song' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblServicePlanItem` ADD CONSTRAINT `fk_spi_song` FOREIGN KEY (`songID`) REFERENCES `tblSongs`(`songID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- ➕ tblServicePlan.publicToken / isPublicShared — guarded ADD COLUMN + KEY (R3)
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServicePlan' AND COLUMN_NAME = 'publicToken'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlan` ADD COLUMN `publicToken` CHAR(32) DEFAULT NULL COMMENT ''32-hex public share token, bin2hex(random_bytes(16)) — mirrors tblAssets.publicToken (gap #128, migration 178)'' AFTER `status`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServicePlan' AND COLUMN_NAME = 'isPublicShared'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlan` ADD COLUMN `isPublicShared` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Plan-level opt-in for the /os/{token} public view — OFF by default (gap #128, migration 178)'' AFTER `publicToken`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblServicePlan' AND INDEX_NAME = 'uq_sp_public_token'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblServicePlan` ADD UNIQUE KEY `uq_sp_public_token` (`publicToken`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 🗺️ Route seeds — ordinary session-gated pages (NOT api/*, so tblRoutes IS
-- how these are gated). /os/{token} is deliberately NOT seeded — see header.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/hymns',         'admin/hymns.php',         1),
    ('admin/hymns/save',    'admin/hymns-save.php',    1),
    ('service-plans/share', 'service-plans/share.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- ⚙️ Settings seeds — global defaults (siteID NULL). Remote lookup + public
-- sharing both OFF by default; the ApiRouter gating flag is 'true' (the
-- endpoint still requires a live session — see hymn-search.php).
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'hymns.remote.enabled',                   'false', 'false', 0),
    (NULL, 'hymns.remote.host',                      '',      '',      0),
    (NULL, 'hymns.remote.baseUrl',                   '',      '',      0),
    (NULL, 'hymns.remote.apiKey',                    '',      '',      1),
    (NULL, 'hymns.remote.cacheTtl',                  '86400', '86400', 0),
    (NULL, 'service_plans.public_share.enabled',     'false', 'false', 0),
    (NULL, 'api.service-plans.hymn-search.enabled',  'true',  'true',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('178_hymnal_lookup_public_oos.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
