-- =============================================================================
-- Migration 120: Multiple primary organisers per event (#332)
-- =============================================================================
-- Replaces the single-string tblEvents.hostOrgName with a junction table
-- supporting multiple co-organisers, each marked isPrimary. Existing
-- hostOrgName + partnerOrgs columns stay populated for backward compat
-- (event.php prefers the junction when rows exist, falls back otherwise).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/332
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventOrgs` (
    `eventOrgID`  INT          NOT NULL AUTO_INCREMENT,
    `eventID`     INT          NOT NULL,
    `orgName`     VARCHAR(255) NOT NULL,
    `orgUrl`      VARCHAR(500) DEFAULT NULL,
    `isPrimary`   TINYINT(1)   NOT NULL DEFAULT 1
                  COMMENT '1 = primary co-organiser (shown prominently); 0 = partner',
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `addedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventOrgID`),
    KEY `idx_eorg_event_primary` (`eventID`, `isPrimary`, `sortOrder`),
    CONSTRAINT `fk_eorg_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/calendar/event-orgs',      'admin/calendar/event-orgs.php',      1),
    ('admin/calendar/event-orgs/save', 'admin/calendar/event-orgs-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
