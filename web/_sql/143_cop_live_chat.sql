-- =============================================================================
-- Migration 143: COP Online Engagement — Phase 1 viewer live chat (#313)
-- =============================================================================
-- Single new table `tblLiveChatMessages` (siteID + eventID + sessionToken-keyed
-- public messages with admin moderation pipeline) + rate-limit ledger.
--
-- ROUTING NOTE: api/* paths bypass tblRoutes and dispatch via ApiRouter's
-- segment convention (api/{appName}/{action} → _apps/{appName}/api/{action}.php).
-- So we ONLY register the admin route in tblRoutes, and gate the api endpoints
-- via api.livechat.<action>.enabled settings (ApiRouter checks these).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblLiveChatMessages` (
    `messageID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL,
    `eventID`        INT          NOT NULL,
    `sessionToken`   VARCHAR(64)  NOT NULL COMMENT 'Matches tblLivestreamSessions.sessionToken; anonymous identity',
    `displayName`    VARCHAR(40)  NOT NULL,
    `body`           VARCHAR(500) NOT NULL,
    `senderIP`       VARCHAR(45)  DEFAULT NULL COMMENT 'IPv4 or IPv6 — kept for moderation audit only',
    `status`         ENUM('pending','approved','hidden','flagged') NOT NULL DEFAULT 'pending',
    `flagReason`     VARCHAR(120) DEFAULT NULL COMMENT 'profanity-stub, low-rep, etc.',
    `moderatedByID`  INT          DEFAULT NULL,
    `moderatedAt`    DATETIME     DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`messageID`),
    KEY `idx_chat_event_status`  (`eventID`, `status`, `createdAt`),
    KEY `idx_chat_token`         (`sessionToken`),
    KEY `idx_chat_site`          (`siteID`, `status`, `createdAt`),
    CONSTRAINT `fk_chat_site`      FOREIGN KEY (`siteID`)        REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_event`     FOREIGN KEY (`eventID`)       REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_moderator` FOREIGN KEY (`moderatedByID`) REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblLiveRateLimits` (
    `limitID`       INT          NOT NULL AUTO_INCREMENT,
    `sessionToken`  VARCHAR(64)  DEFAULT NULL,
    `senderIP`      VARCHAR(45)  DEFAULT NULL,
    `eventType`     VARCHAR(40)  NOT NULL COMMENT 'e.g. chat.send',
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`limitID`),
    KEY `idx_ratelimit_token` (`sessionToken`, `eventType`, `createdAt`),
    KEY `idx_ratelimit_ip`    (`senderIP`, `eventType`, `createdAt`),
    KEY `idx_ratelimit_prune` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 🛡️ Seed the stream_moderator role if not already present. App::hasRole
-- (web/_core/App.php:295) queries tblRoles.roleKey for this exact string.
INSERT INTO `tblRoles` (`roleKey`, `name`, `description`)
    SELECT 'stream_moderator', 'Stream Moderator', 'Approves / hides / flags chat messages on the live engagement surface'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'stream_moderator');

-- 🎛️ Feature gates + tunables. ApiRouter gates api/* endpoints on
-- api.{appName}.{action}.enabled = 'true' (ApiRouter.php:66), so seed those
-- explicitly. The chat.* settings drive LiveChat helper behaviour.
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`) VALUES
    ('chat.enabled',          'false', 0, 'false'),
    ('chat.autoApprove',      'false', 0, 'false'),
    ('chat.maxBodyChars',     '500',   0, '500'),
    ('chat.maxMsgsPerWindow', '5',     0, '5'),
    ('chat.windowSeconds',    '60',    0, '60'),
    ('chat.profanityList',    '',      0, ''),
    ('api.livechat.send.enabled',     'true', 0, 'true'),
    ('api.livechat.list.enabled',     'true', 0, 'true'),
    ('api.livechat.moderate.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

-- 🗂️ Admin moderation queue route — non-api, goes through Router/tblRoutes.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/live/chat', 'admin/live/chat.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
