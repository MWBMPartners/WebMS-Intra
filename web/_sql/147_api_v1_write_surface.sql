-- =============================================================================
-- Migration 147: REST API v1 write surface — schema + primitives (#323 Phase 2 B1)
-- =============================================================================
-- Bundle B1 ships ONLY the schema + primitives needed for the v1 write surface:
-- audit-trail attribution for API-key-authenticated changes, a rotation grace
-- window on tblApiKeys, a sliding-window rate-limit hit log, and the settings
-- flags the write endpoints (and RateLimiter/ApiKey) will read. No ApiAuth
-- class, no dispatch, no handlers, and no /api/v1/* endpoints ship in this
-- migration — those land in later B-bundles of #323 Phase 2.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/323
-- =============================================================================

-- (a) tblAuditTrail.apiKeyID — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAuditTrail'
      AND COLUMN_NAME  = 'apiKeyID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAuditTrail` ADD COLUMN `apiKeyID` INT DEFAULT NULL COMMENT ''tblApiKeys.keyID when the change arrived via bearer API key (#323 Phase 2)'' AFTER `userID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (b) tblAuditTrail.source — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAuditTrail'
      AND COLUMN_NAME  = 'source'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblAuditTrail` ADD COLUMN `source` ENUM(''session'',''apikey'') NOT NULL DEFAULT ''session'' COMMENT ''Auth channel that made the change (#323 Phase 2)'' AFTER `apiKeyID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (c) idx_audit_apikey — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblAuditTrail'
      AND INDEX_NAME   = 'idx_audit_apikey'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblAuditTrail` ADD INDEX `idx_audit_apikey` (`apiKeyID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (d) tblApiKeys.rotatedToID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblApiKeys'
      AND COLUMN_NAME  = 'rotatedToID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblApiKeys` ADD COLUMN `rotatedToID` INT DEFAULT NULL COMMENT ''keyID of the replacement key minted by rotate(); old key stays live until expiresAt grace cutoff (#323 Phase 2)'' AFTER `revokedByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (e) tblApiRateLimits — sliding-window API hit log
CREATE TABLE IF NOT EXISTS `tblApiRateLimits` (
    `hitID`   BIGINT       NOT NULL AUTO_INCREMENT,
    `bucket`  VARCHAR(120) NOT NULL COMMENT 'Limiter bucket key, e.g. apikey:42',
    `hitAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`hitID`),
    KEY `idx_ratelimit_bucket` (`bucket`, `hitAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Sliding-window API hit log — pruned opportunistically by RateLimiter (#323 Phase 2)';

-- (f) Settings seeds — write-surface enable flags + rate-limit + rotation tuning
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.attendance.create.enabled',    'true',    'true',    0),
    (NULL, 'api.attendance.update.enabled',    'true',    'true',    0),
    (NULL, 'api.attendance.delete.enabled',    'true',    'true',    0),
    (NULL, 'api.documents.create.enabled',     'true',    'true',    0),
    (NULL, 'api.documents.update.enabled',     'true',    'true',    0),
    (NULL, 'api.documents.delete.enabled',     'true',    'true',    0),
    (NULL, 'api.expenses.create.enabled',      'true',    'true',    0),
    (NULL, 'api.expenses.update.enabled',      'true',    'true',    0),
    (NULL, 'api.expenses.delete.enabled',      'true',    'true',    0),
    (NULL, 'api.users.create.enabled',         'false',   'false',   0),
    (NULL, 'api.users.update.enabled',         'false',   'false',   0),
    (NULL, 'api.rateLimit.perKey.maxRequests', '300',     '300',     0),
    (NULL, 'api.rateLimit.perKey.windowMinutes','5',      '5',       0),
    (NULL, 'api.keys.rotationGraceHours',      '24',      '24',      0),
    (NULL, 'documents.api.maxUploadBytes',     '10485760','10485760',0)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- (g) NO tblRoutes rows are added by this migration — api/* paths never
--     register in tblRoutes (Router::handleSpecialRoutes hands them off to
--     ApiRouter::dispatch before tblRoutes is ever consulted — see the
--     "ApiRouter routing trap" in .claude/CLAUDE.md). Admin key CRUD reuses
--     the existing admin/integrations/api-keys* routes seeded by migration
--     141_api_keys.sql — no new routes are needed for this bundle.

-- (h) Self-record this migration
INSERT INTO `tblMigrations` (`filename`) VALUES ('147_api_v1_write_surface.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
