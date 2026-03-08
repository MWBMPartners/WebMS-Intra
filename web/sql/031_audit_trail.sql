-- =============================================================================
-- Migration 031: Audit trail — before/after change tracking
-- =============================================================================
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/91
-- =============================================================================

-- 📋 Detailed audit trail with before/after snapshots
CREATE TABLE IF NOT EXISTS `tblAuditTrail` (
    `auditID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          DEFAULT NULL,
    `userID`       INT          DEFAULT NULL,
    `tableName`    VARCHAR(100) NOT NULL COMMENT 'Affected database table',
    `recordID`     INT          NOT NULL COMMENT 'Primary key of affected record',
    `action`       ENUM('create','update','delete') NOT NULL,
    `fieldName`    VARCHAR(100) DEFAULT NULL COMMENT 'Specific field changed (NULL = whole record)',
    `oldValue`     TEXT         DEFAULT NULL COMMENT 'Previous value (JSON for complex types)',
    `newValue`     TEXT         DEFAULT NULL COMMENT 'New value (JSON for complex types)',
    `changeSet`    JSON         DEFAULT NULL COMMENT 'Full diff: {field: {old, new}} for multi-field changes',
    `ipAddress`    VARCHAR(45)  DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`auditID`),
    KEY `idx_audit_table_record` (`tableName`, `recordID`),
    KEY `idx_audit_user` (`userID`),
    KEY `idx_audit_site` (`siteID`),
    KEY `idx_audit_date` (`createdAt`),
    KEY `idx_audit_action` (`action`, `tableName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Detailed audit trail with before/after change tracking';

-- 📋 Route for audit trail viewer
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/audit', 'admin/audit/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
