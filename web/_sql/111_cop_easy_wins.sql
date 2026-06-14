-- =============================================================================
-- Migration 111: COP easy-wins trio (#319 + #322 + #324)
-- =============================================================================
-- Three small extensions from the Church Online Platform competitive analysis.
-- All three opted into by the user. Schema + routes only — handler files ship
-- alongside this migration. Each is the MVP slice; polish + admin UI scoped
-- to v1.1 follow-up PRs (referenced inline).
--
-- 1️⃣  #319 — Embeddable countdown-to-next-service widget.
--     Pure read endpoint + static JS file; no schema needed. Routes added
--     for the JSON feed + the embed JS + the preview/demo page.
--
-- 2️⃣  #322 — Web Push notifications ("we're live now" + service reminders).
--     `tblPushSubscriptions` holds per-user (or anonymous) browser-push
--     credentials. POST /api/push/subscribe endpoint registers them.
--     Actual push sending requires VAPID keys in settings — operator setup
--     documented in DEV_NOTES. The "we're live" trigger is hooked into
--     `livestream/save` in v1.1.
--
-- 3️⃣  #324 — Outbound webhooks framework.
--     `tblWebhooks` (subscriptions) + `tblWebhookDeliveries` (audit + retry).
--     `WebhookDispatcher` core class ships in PR; admin CRUD UI at
--     `/admin/integrations/webhooks` lands in v1.1. One demo wiring on
--     `prayer-requests/save` proves the end-to-end emission path.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/319
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/322
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/324
-- =============================================================================

-- 1️⃣ Embeddable countdown widget --------------------------------------------

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- JSON feed: next upcoming event for the active site (public).
    ('widget/countdown.json', 'widget/countdown-json.php', 0),
    -- Demo + how-to-embed page (public).
    ('widget/countdown',      'widget/countdown-preview.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 2️⃣ Web Push subscriptions -------------------------------------------------

CREATE TABLE IF NOT EXISTS `tblPushSubscriptions` (
    `subID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `userID`       INT          DEFAULT NULL
                   COMMENT 'NULL = anonymous device subscription',
    `endpoint`     VARCHAR(500) NOT NULL
                   COMMENT 'Push service URL from PushSubscription.endpoint',
    `p256dhKey`    VARCHAR(255) NOT NULL
                   COMMENT 'Subscriber public key (base64url)',
    `authKey`      VARCHAR(255) NOT NULL
                   COMMENT 'Auth secret (base64url)',
    `userAgent`    VARCHAR(255) DEFAULT NULL,
    -- Per-channel opt-in. Channels: 'livestream' (we are live), 'reminders'
    -- (upcoming service in 1h), 'announcements'. JSON array of channel keys.
    `channels`     VARCHAR(500) NOT NULL DEFAULT '["livestream","reminders"]',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lastUsedAt`   DATETIME     DEFAULT NULL,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`subID`),
    UNIQUE KEY `uq_ps_endpoint` (`endpoint`(255)),
    KEY `idx_ps_user`   (`userID`),
    KEY `idx_ps_site`   (`siteID`, `isActive`),
    CONSTRAINT `fk_ps_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_ps_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('api/push/subscribe',   'api/push/subscribe.php',   0),
    ('api/push/unsubscribe', 'api/push/unsubscribe.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    -- VAPID public key (embedded in subscribe.js so browsers can subscribe).
    (NULL, 'push.vapidPublicKey',  '', '', 0),
    -- VAPID private key — sensitive; encrypted at rest via libsodium pattern.
    (NULL, 'push.vapidPrivateKey', '', '', 1),
    -- "mailto:" or "https://" contact required by RFC 8292 (push service uses
    -- it to reach the operator if the install starts misbehaving).
    (NULL, 'push.contact',         '', '', 0),
    -- Master toggle. Off until VAPID keys are set (v1.1 wiring).
    (NULL, 'push.enabled',         'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 3️⃣ Outbound webhooks framework -------------------------------------------

CREATE TABLE IF NOT EXISTS `tblWebhooks` (
    `webhookID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `name`          VARCHAR(100) NOT NULL,
    -- Comma-separated event keys the webhook subscribes to. Wildcard 'all'
    -- subscribes to every event. Pattern: 'app.action' (e.g.
    -- 'prayer-requests.created', 'expenses.approved', 'livestream.started').
    `eventTypes`    VARCHAR(500) NOT NULL DEFAULT 'all',
    `targetUrl`     VARCHAR(500) NOT NULL,
    -- HMAC-SHA256 signing secret. Receiver verifies via X-Webhook-Signature
    -- header (hex of HMAC over the request body).
    `signingSecret` VARCHAR(255) NOT NULL,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lastDeliveryAt` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`webhookID`),
    KEY `idx_wh_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_wh_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_wh_user` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblWebhookDeliveries` (
    `deliveryID`    BIGINT       NOT NULL AUTO_INCREMENT,
    `webhookID`     INT          NOT NULL,
    `eventType`     VARCHAR(100) NOT NULL,
    `payload`       MEDIUMTEXT   NOT NULL COMMENT 'JSON event payload as sent',
    `payloadHash`   CHAR(64)     DEFAULT NULL COMMENT 'sha256 of payload (for replay dedupe)',
    `status`        ENUM('pending','delivered','failed','dead') NOT NULL DEFAULT 'pending',
    `attemptCount`  INT          NOT NULL DEFAULT 0,
    `lastAttemptAt` DATETIME     DEFAULT NULL,
    `responseCode`  INT          DEFAULT NULL,
    `responseSnippet` VARCHAR(500) DEFAULT NULL COMMENT 'first 500 chars of response body for debug',
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`deliveryID`),
    KEY `idx_wd_webhook`  (`webhookID`, `status`),
    KEY `idx_wd_pending`  (`status`, `lastAttemptAt`),
    CONSTRAINT `fk_wd_webhook` FOREIGN KEY (`webhookID`) REFERENCES `tblWebhooks`(`webhookID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Admin CRUD ships in v1.1 — these routes are reserved.
    ('admin/integrations/webhooks',         'admin/integrations/webhooks/index.php',  1),
    ('admin/integrations/webhooks/save',    'admin/integrations/webhooks/save.php',   1),
    ('admin/integrations/webhooks/delete',  'admin/integrations/webhooks/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'webhooks.enabled', 'true', 'true', 0),
    -- Per-attempt timeout for outbound POST (seconds).
    (NULL, 'webhooks.timeout', '10',   '10',   0),
    -- Max retry attempts before marking 'dead'. Backoff is exponential.
    (NULL, 'webhooks.maxRetries', '5', '5', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
