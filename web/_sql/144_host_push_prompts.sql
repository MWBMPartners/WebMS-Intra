-- =============================================================================
-- Migration 144: Host push prompts + livestream ping route fix
--                Phase 2 of #317 (Host Console) + #313 (Live Chat)
-- =============================================================================
-- New tblLivePrompts table backs the host-side "push prompt" overlay surface
-- the viewer chat widget polls every 8s. Single table serves both #317
-- (host composer card) and #313 (viewer overlay).
--
-- ALSO: seeds api.livestream.ping.enabled = 'true' for the new
-- _apps/livestream/api/ping.php endpoint. The existing handler at
-- _apps/api/livestream-ping.php is UNREACHABLE via ApiRouter (which dispatches
-- api/* paths by segment to _apps/{appName}/api/{action}.php) — that's why
-- the host console "Watching now" tile reads 0 today. The new handler is at
-- the correct ApiRouter convention path and accepts the same `token` field
-- the original handler reads, so the existing /admin/livestream/dashboard
-- snippet keeps working without change.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblLivePrompts` (
    `promptID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL,
    `eventID`      INT          NOT NULL,
    `promptType`   ENUM('decision-call','prayer-request','give-now','announcement') NOT NULL,
    `title`        VARCHAR(120) NOT NULL,
    `body`         VARCHAR(500) DEFAULT NULL,
    `ctaLabel`     VARCHAR(60)  DEFAULT NULL,
    `ctaUrl`       VARCHAR(500) DEFAULT NULL COMMENT 'Server-validated http/https or root-relative; NEVER javascript: data: etc.',
    `publishedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiresAt`    DATETIME     NOT NULL COMMENT 'Hard expiry; default 5min from publishedAt',
    `createdByID`  INT          DEFAULT NULL,
    `dismissedAt`  DATETIME     DEFAULT NULL COMMENT 'Coordinator can manually dismiss before expiry',
    PRIMARY KEY (`promptID`),
    KEY `idx_prompt_event_active` (`eventID`, `expiresAt`, `dismissedAt`),
    KEY `idx_prompt_site`         (`siteID`, `publishedAt`),
    CONSTRAINT `fk_prompt_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`)  ON DELETE CASCADE,
    CONSTRAINT `fk_prompt_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_prompt_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 🎛️ ApiRouter gates every api/* endpoint on api.{appName}.{action}.enabled.
-- Seed the four endpoints introduced by Phase 2:
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES
    ('api.livechat.prompts.enabled',        'true', 0, 'true'),
    ('api.livechat.prompt-publish.enabled', 'true', 0, 'true'),
    ('api.livestream.ping.enabled',         'true', 0, 'true'),
    ('chat.promptDefaultExpirySecs',        '300',  0, '300')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);
