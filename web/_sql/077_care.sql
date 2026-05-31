-- =============================================================================
-- Migration 077: Care Register app (#257)
-- =============================================================================
-- Confidential pastoral / wellbeing case register with visit log.
-- Access restricted to the 'care_team' role (assignable per user).
-- Notes are stored as TEXT; encryption is a future enhancement (sensitive
-- column handling already exists for tblSettings — the same pattern can
-- be extended to columns in a follow-up).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblCareCase` (
    `caseID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `personUserID`  INT          DEFAULT NULL COMMENT 'NULL when the person is not a portal user (free-text name)',
    `personName`    VARCHAR(255) DEFAULT NULL COMMENT 'Used when personUserID is NULL',
    `category`      ENUM('illness','hospital','bereavement','family','transition','other') NOT NULL DEFAULT 'other',
    `summary`       VARCHAR(500) NOT NULL,
    `status`        ENUM('active','resolved','long-term') NOT NULL DEFAULT 'active',
    `openedByID`    INT          DEFAULT NULL,
    `openedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closedAt`      DATETIME     DEFAULT NULL,
    PRIMARY KEY (`caseID`),
    KEY `idx_care_case_site_status` (`siteID`, `status`),
    KEY `idx_care_case_person` (`personUserID`),
    CONSTRAINT `fk_care_case_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_care_case_person` FOREIGN KEY (`personUserID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_care_case_opener` FOREIGN KEY (`openedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Pastoral care cases';

CREATE TABLE IF NOT EXISTS `tblCareVisit` (
    `visitID`       INT      NOT NULL AUTO_INCREMENT,
    `caseID`        INT      NOT NULL,
    `visitedByID`   INT      NOT NULL,
    `visitedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `kind`          ENUM('visit','call','message','prayer','other') NOT NULL DEFAULT 'visit',
    `notes`         TEXT     DEFAULT NULL,
    `followUpAt`    DATE     DEFAULT NULL,
    `followUpAssignedToID` INT DEFAULT NULL,
    `createdAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`visitID`),
    KEY `idx_care_visit_case` (`caseID`),
    KEY `idx_care_visit_visitor` (`visitedByID`),
    KEY `idx_care_visit_followup` (`followUpAt`, `followUpAssignedToID`),
    CONSTRAINT `fk_care_visit_case` FOREIGN KEY (`caseID`) REFERENCES `tblCareCase` (`caseID`) ON DELETE CASCADE,
    CONSTRAINT `fk_care_visit_visitor` FOREIGN KEY (`visitedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_care_visit_assignee` FOREIGN KEY (`followUpAssignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Visit / contact log per care case';

CREATE TABLE IF NOT EXISTS `tblCareAccessLog` (
    `accessID`   INT      NOT NULL AUTO_INCREMENT,
    `caseID`     INT      NOT NULL,
    `viewerID`   INT      NOT NULL,
    `viewedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`accessID`),
    KEY `idx_care_access_case` (`caseID`),
    KEY `idx_care_access_viewer` (`viewerID`),
    CONSTRAINT `fk_care_access_case` FOREIGN KEY (`caseID`) REFERENCES `tblCareCase` (`caseID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Append-only audit log of who read which case (#257)';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('care',           'care/index.php',     1),
    ('care/case',      'care/case.php',      1),
    ('care/case-save', 'care/case-save.php', 1),
    ('care/visit-save','care/visit-save.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'care.enabled',           '0', '0', 0),
    (NULL, 'care.redact_after_days', '90','90', 0),
    (NULL, 'care.displayName',       'Care Register', 'Care Register', 0),
    (NULL, 'care.displayIcon',       'fa-solid fa-hand-holding-heart', 'fa-solid fa-hand-holding-heart', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
