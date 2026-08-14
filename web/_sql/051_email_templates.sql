-- =============================================================================
-- 051 — Email template store + admin editor 📨
-- =============================================================================
-- Email bodies are currently hard-coded in PHP (forgot-password, expense
-- notifications, etc.). Moving them into a DB-backed store lets admins
-- tweak wording without code changes — important when each site wants
-- to brand its own voice.
--
-- Templates use Mustache-style {{token}} placeholders. The render helper
-- in core/Mailer.php (to be added separately) does HTML-escape-first
-- substitution to prevent template injection.
--
-- Per-site override: a template row with siteID = NULL is the GLOBAL
-- DEFAULT; a row with the same templateKey and a specific siteID
-- overrides for that site.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEmailTemplates` (
    `templateID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          DEFAULT NULL COMMENT 'NULL = global default; specific siteID overrides',
    `templateKey`   VARCHAR(100) NOT NULL COMMENT 'Stable identifier, e.g. "auth.passwordReset"',
    `subject`       VARCHAR(255) NOT NULL,
    `bodyHtml`      MEDIUMTEXT   NOT NULL COMMENT 'Mustache-style {{token}} placeholders',
    `description`   VARCHAR(500) DEFAULT NULL COMMENT 'Free-text hint for admins (e.g. "Sent when a user clicks Forgot Password")',
    `availableTokens` TEXT       DEFAULT NULL COMMENT 'Comma-separated list of {{tokens}} that the caller injects (documented for admins)',
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`templateID`),
    UNIQUE KEY `uq_template_site_key` (`siteID`, `templateKey`),
    KEY `idx_template_key` (`templateKey`),
    CONSTRAINT `fk_template_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Editable email templates. Mustache-style {{token}} substitution at render time.';

-- 🌱 Seed a few global default templates (siteID = NULL).
--    The Mailer is expected to look up by templateKey, fall back to the
--    hardcoded version if no row exists, and let admins override via UI.
INSERT INTO `tblEmailTemplates`
    (siteID, templateKey, subject, bodyHtml, description, availableTokens) VALUES
    (NULL, 'auth.passwordReset',
     'Password Reset Request – {{siteName}}',
     '<h2 style="color:#5e6ad2;">Password Reset Request</h2>'
     '<p>Hi {{userName}},</p>'
     '<p>We received a request to reset your password for your {{siteName}} account.</p>'
     '<p style="margin:24px 0;">'
     '<a href="{{resetLink}}" style="background:#198754;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;">Reset My Password</a>'
     '</p>'
     '<p>Or copy and paste this link into your browser:</p>'
     '<p style="word-break:break-all;color:#6c757d;font-size:0.875rem;">{{resetLink}}</p>'
     '<p style="color:#6c757d;font-size:0.875rem;">This link expires in {{expiryMinutes}} minutes. If you did not request this, you can safely ignore this email.</p>'
     '<hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0;">'
     '<p style="color:#999;font-size:0.75rem;">{{siteName}}</p>',
     'Sent when a user clicks "Forgot password" and the reset token is generated.',
     'siteName, userName, resetLink, expiryMinutes'),

    (NULL, 'expenses.statusUpdate',
     'Expense claim {{claimRef}} — {{statusLabel}}',
     '<h2 style="color:#5e6ad2;">Expense claim update</h2>'
     '<p>Hi {{userName}},</p>'
     '<p>Your expense claim <strong>{{claimRef}}</strong> ({{claimDescription}}) has been <strong>{{statusLabel}}</strong>.</p>'
     '<p>{{decisionNote}}</p>'
     '<p style="margin:24px 0;">'
     '<a href="{{claimLink}}" style="background:#5e6ad2;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;">View claim</a>'
     '</p>'
     '<hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0;">'
     '<p style="color:#999;font-size:0.75rem;">{{siteName}}</p>',
     'Sent to the claim submitter when an approver or treasury changes the status.',
     'siteName, userName, claimRef, claimDescription, statusLabel, decisionNote, claimLink'),

    (NULL, 'expenses.approverNudge',
     'Expense claim {{claimRef}} awaiting your approval',
     '<h2 style="color:#5e6ad2;">A claim is awaiting your approval</h2>'
     '<p>Hi {{approverName}},</p>'
     '<p>{{submitterName}} has submitted an expense claim that needs your sign-off:</p>'
     '<ul>'
     '<li><strong>Reference:</strong> {{claimRef}}</li>'
     '<li><strong>Description:</strong> {{claimDescription}}</li>'
     '<li><strong>Amount:</strong> {{claimAmount}}</li>'
     '</ul>'
     '<p style="margin:24px 0;">'
     '<a href="{{claimLink}}" style="background:#5e6ad2;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;">Review claim</a>'
     '</p>'
     '<hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0;">'
     '<p style="color:#999;font-size:0.75rem;">{{siteName}}</p>',
     'Sent to an approver when a claim is routed to them.',
     'siteName, approverName, submitterName, claimRef, claimDescription, claimAmount, claimLink')
ON DUPLICATE KEY UPDATE `bodyHtml` = `bodyHtml`;

-- 🛣️ Admin routes
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/email-templates',         'admin/email-templates/index.php',  1),
    ('admin/email-templates/edit',    'admin/email-templates/edit.php',   1),
    ('admin/email-templates/save',    'admin/email-templates/save.php',   1),
    ('admin/email-templates/preview', 'admin/email-templates/preview.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
