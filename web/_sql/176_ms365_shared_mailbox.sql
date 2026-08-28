-- =============================================================================
-- Migration 176: MS365 Graph email via a shared mailbox (#234)
--
-- Path: web/_sql/176_ms365_shared_mailbox.sql
-- The portal already sends every email app-only through Microsoft Graph
-- as a shared mailbox: Mailer::sendViaGraph() acquires a client-credentials
-- token and POSTs to /users/{mail.defaultFromAddress}/sendMail. What #234
-- actually still needed was formalising and hardening that path: an
-- explicit, admin-configured shared-mailbox identity separate from the
-- general from-address, an explicit `from` (with display name) in the
-- Graph payload, 401/429/403/404 error handling with an optional Google
-- fallback, and a tblEmailLog audit trail (also closes #230's dependency).
--
-- Chosen model: app-only (application permission Mail.Send) via
-- POST /users/{sharedMailbox}/sendMail — reuses Mailer::accessToken()
-- verbatim, introduces ZERO new secrets. The issue body's delegated
-- Mail.Send.Shared refresh-token model is explicitly deferred (see
-- .claude/plans/gap-items/234-shared-mailbox-plan.md §2/§13 Q1) — it would
-- add an OAuth authorize/refresh surface, a new encrypted secret
-- lifecycle, and a licensed "delegate" user whose password/MFA/
-- conditional-access events would silently kill portal mail.
--
-- Everything ships inert: `mail.ms365.sharedMailbox` seeds empty, so
-- byte-for-byte today's Mailer::sendViaGraph() behaviour is unchanged
-- until an admin sets a value at /admin/integrations (empty-key-off — no
-- separate enable toggle, so it's impossible to half-configure).
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in
-- this file — check_schema_seed_parity.py strips from any "--" to
-- end-of-line, even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/234
-- =============================================================================


-- #############################################################################
-- 🗄️ A. tblEmailLog — new table (also closes the #230 audit-trail dependency)
-- #############################################################################

-- Per-send audit row for BOTH mail providers (Mailer::logSend(), called
-- from Mailer::sendViaGraph() and MailerGoogle::send()). Standard MySQL
-- (CREATE TABLE IF NOT EXISTS is portable — no guard idiom needed).
CREATE TABLE IF NOT EXISTS `tblEmailLog` (
    `emailLogID`      INT           NOT NULL AUTO_INCREMENT COMMENT 'Unique send-log record identifier',
    `siteID`          INT           DEFAULT NULL COMMENT 'Site::id() at send time; NULL when sent outside site context (e.g. cron)',
    `provider`        VARCHAR(20)   NOT NULL COMMENT 'ms365, ms365-shared, or google',
    `fromAddress`     VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'Effective sender address at send time',
    `toRecipients`    TEXT          NOT NULL COMMENT 'Comma-joined recipient address list',
    `subject`         VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'Truncated subject line',
    `attachmentCount` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of files actually attached',
    `status`          ENUM('sent','failed') NOT NULL,
    `httpCode`        SMALLINT      DEFAULT NULL COMMENT 'Provider HTTP response code, when one was received',
    `errorCode`       VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Provider error code, e.g. Graph ErrorAccessDenied',
    `errorDetail`     VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'Truncated error message. Never token or secret material',
    `sentAt`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`emailLogID`),
    KEY `idx_emaillog_site_sent` (`siteID`, `sentAt`),
    CONSTRAINT `fk_emaillog_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Per-send audit trail for outbound email (#234, #230). See _core/Mailer.php::logSend().';


-- #############################################################################
-- ⚙️ B. Settings seed (5 keys, none sensitive — no new isSensitive=1 rows)
-- #############################################################################

-- mail.ms365.sharedMailbox seeds empty = feature OFF (today's direct-send
-- behaviour, unchanged). saveToSentItems defaults 'true' (an office shared
-- mailbox wants the audit copy). fallbackProvider defaults '' = fail loud
-- (a silent failover changes the visible sending identity and can break
-- DMARC alignment, so an admin must opt in explicitly). log.retentionDays
-- defaults 90.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'mail.ms365.sharedMailbox',     '',     '',     0),
    (NULL, 'mail.ms365.sharedMailboxName', '',     '',     0),
    (NULL, 'mail.ms365.saveToSentItems',   'true', 'true', 0),
    (NULL, 'mail.fallbackProvider',        '',     '',     0),
    (NULL, 'mail.log.retentionDays',       '90',   '90',   0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️ C. Route seed (1) — admin save handler
-- #############################################################################

-- isProtected=1: Auth::ensureSession() + Auth::requireLogin() + admin gate
-- are enforced INSIDE the handler itself (cloudflare-stream/save.php
-- precedent), matching the house convention for admin POST handlers.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/ms365-mail-save', 'admin/integrations/ms365-mail-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 D. Self-record
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('176_ms365_shared_mailbox.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
