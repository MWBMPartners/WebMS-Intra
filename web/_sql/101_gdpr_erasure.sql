-- =============================================================================
-- Migration 101: GDPR erasure workflow + anonymisation engine (#235)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblErasureRequest` (
    `requestID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `userID`           INT          DEFAULT NULL COMMENT 'NULL after user row is anonymised / deleted',
    `subjectEmail`     VARCHAR(255) NOT NULL COMMENT 'Snapshot at request time',
    `subjectName`      VARCHAR(255) DEFAULT NULL,
    `confirmToken`     CHAR(64)     DEFAULT NULL COMMENT 'Email-confirmation token; cleared after confirm',
    `status`           ENUM('pending_confirmation','pending_review','processing','completed','cancelled','failed') NOT NULL DEFAULT 'pending_confirmation',
    `requestedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmedAt`      DATETIME     DEFAULT NULL,
    `dueBy`            DATETIME     NOT NULL COMMENT '1-month GDPR SLA',
    `processedAt`      DATETIME     DEFAULT NULL,
    `processedByID`    INT          DEFAULT NULL,
    `reasonRetained`   TEXT         DEFAULT NULL COMMENT 'Per-category retention rationale',
    `notes`            TEXT         DEFAULT NULL,
    PRIMARY KEY (`requestID`),
    KEY `idx_er_status` (`status`),
    KEY `idx_er_due`    (`dueBy`),
    CONSTRAINT `fk_er_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Sealed audit trail: tamper-evidence via per-row HMAC chain (each row's
-- `chainHash` = SHA-256(prev row's chainHash || this row payload)).
-- An admin who edits a row can't fix the chain without the secret key.
CREATE TABLE IF NOT EXISTS `tblErasureAudit` (
    `auditID`     INT          NOT NULL AUTO_INCREMENT,
    `requestID`   INT          NOT NULL,
    `action`      VARCHAR(50)  NOT NULL COMMENT 'delete / anonymise / retain',
    `tableName`   VARCHAR(64)  NOT NULL,
    `recordKey`   VARCHAR(255) DEFAULT NULL COMMENT 'Primary-key snapshot',
    `details`     TEXT         DEFAULT NULL,
    `chainHash`   CHAR(64)     NOT NULL,
    `loggedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`auditID`),
    KEY `idx_ea_request` (`requestID`),
    CONSTRAINT `fk_ea_request` FOREIGN KEY (`requestID`) REFERENCES `tblErasureRequest`(`requestID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/my-data',           'account/my-data.php',           1),
    ('account/erasure-request',   'account/erasure-request.php',   1),
    ('account/erasure-confirm',   'account/erasure-confirm.php',   0),
    ('admin/erasure-requests',    'admin/erasure/index.php',       1),
    ('admin/erasure-requests/process', 'admin/erasure/process.php', 1),
    ('admin/erasure-requests/report',  'admin/erasure/report.php',  1),
    ('privacy/policy',            'privacy/policy.php',            0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'privacy.allowAccountDelete', 'true', 'true', 0),
    (NULL, 'privacy.erasureContact',     '', '', 0),
    (NULL, 'privacy.financialRetentionYears', '6', '6', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
