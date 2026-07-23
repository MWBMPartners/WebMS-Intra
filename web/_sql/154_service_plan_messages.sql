-- =============================================================================
-- Migration 154: Service-plan confidence-monitor messages (#300 v2)
-- =============================================================================
-- Closes the last open piece of #300: an operator → confidence-monitor
-- message channel. Issue #300 explicitly blessed a polling fallback ("fall
-- back to polling if it doesn't play nice with DreamHost") — this ships
-- plain JSON polling (4s cadence, matching the livechat-widget.js house
-- idiom), no SSE, no new dependencies.
--
-- Targets the run-sheet builder's `tblServicePlan` (PK `planID`, migration
-- 089) — NOT the unrelated worship slides system's `tblServicePlans` /
-- `tblServicePlanItems` / `tblServicePlanState` (migrations 137/138).
--
-- `isCleared` + `clearedAt` rather than DELETE: rows are retained as the
-- audit record of how the service ran; the monitor's poll query filters
-- `isCleared = 0`; "clear" is an UPDATE, so history survives.
--
-- New routes are plain `service-plans/*` page routes — NOT under `api/*`
-- (the ApiRouter routing trap in .claude/CLAUDE.md doesn't apply here; see
-- the existing `service-plans/live`, `service-plans/confidence`,
-- `service-plans/live-toggle` rows from migration 110 for the precedent).
-- No new tblSettings rows — the feature inherits the existing
-- `service_plans.enabled` app gate via route-prefix matching.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/300
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblServicePlanMessages` (
    `messageID`   INT          NOT NULL AUTO_INCREMENT,
    `planID`      INT          NOT NULL,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `body`        VARCHAR(255) NOT NULL COMMENT 'Operator message shown on the confidence monitor',
    `isCleared`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = dismissed by operator; monitor shows latest row with 0',
    `clearedAt`   DATETIME     DEFAULT NULL,
    `createdByID` INT          DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`messageID`),
    KEY `idx_spm_poll` (`planID`, `isCleared`, `messageID`),
    KEY `idx_spm_site` (`siteID`),
    CONSTRAINT `fk_spm_plan` FOREIGN KEY (`planID`)      REFERENCES `tblServicePlan`(`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_spm_site` FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_spm_user` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Operator → confidence-monitor message channel (#300 v2)';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('service-plans/live-message', 'service-plans/live-message.php', 1),
    ('service-plans/message-poll', 'service-plans/message-poll.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('154_service_plan_messages.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
