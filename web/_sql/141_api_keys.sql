-- =============================================================================
-- Migration 141: API key infrastructure — Phase 1 of #323
-- =============================================================================
-- Stores bearer-token API keys for the public REST API. Plaintext tokens are
-- returned EXACTLY ONCE at mint time and never persisted — the DB holds only
-- their sha256 hash. keyPrefix (first ~6 chars + 'wbms_' prefix) is plain so
-- the admin UI can identify keys at a glance ("wbms_3f2c…").
--
-- Phase 1 ships the infrastructure ONLY: schema, admin CRUD, ApiKey class,
-- and ApiResponse::requireApiKey() helper. No /api/v1/* router refactor and
-- no write endpoints are wired in this PR — those are Phase 2+.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/323
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblApiKeys` (
    `keyID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL,
    `name`         VARCHAR(120) NOT NULL COMMENT 'Human-readable label shown in the admin UI',
    `keyHash`      CHAR(64)     NOT NULL COMMENT 'sha256(plaintext) — plaintext is never stored',
    `keyPrefix`    VARCHAR(12)  NOT NULL COMMENT 'Visible prefix for admin identification (e.g. wbms_3f2c)',
    `scopes`       VARCHAR(500) DEFAULT NULL COMMENT 'CSV of scope strings — Phase 2 enforces, Phase 1 stores',
    `expiresAt`    DATETIME     DEFAULT NULL COMMENT 'Optional hard expiry; NULL = no expiry',
    `lastUsedAt`   DATETIME     DEFAULT NULL,
    `lastUsedIP`   VARCHAR(45)  DEFAULT NULL COMMENT 'IPv4 (15 chars) or IPv6 (45 chars max)',
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revokedAt`    DATETIME     DEFAULT NULL,
    `revokedByID`  INT          DEFAULT NULL,
    PRIMARY KEY (`keyID`),
    UNIQUE KEY `uq_apikey_hash`     (`keyHash`),
    KEY        `idx_apikey_site`    (`siteID`, `isActive`),
    KEY        `idx_apikey_expires` (`expiresAt`),
    CONSTRAINT `fk_apikey_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_apikey_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_apikey_revoker` FOREIGN KEY (`revokedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/api-keys',        'admin/integrations/api-keys.php',         1),
    ('admin/integrations/api-keys/save',   'admin/integrations/api-keys-save.php',    1),
    ('admin/integrations/api-keys/revoke', 'admin/integrations/api-keys-revoke.php',  1),
    ('admin/integrations/api-keys/rotate', 'admin/integrations/api-keys-rotate.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
