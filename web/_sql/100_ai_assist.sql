-- =============================================================================
-- Migration 100: AI-assisted drafting (#277)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblAiPrompt` (
    `promptID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `kind`           VARCHAR(50)  NOT NULL,
    `promptTemplate` MEDIUMTEXT   NOT NULL,
    `isActive`       TINYINT(1)   NOT NULL DEFAULT 1,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`promptID`),
    UNIQUE KEY `uq_ap_site_kind` (`siteID`, `kind`),
    CONSTRAINT `fk_ap_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblAiUsage` (
    `usageID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `userID`       INT          DEFAULT NULL,
    `promptKind`   VARCHAR(50)  NOT NULL,
    `provider`     VARCHAR(30)  NOT NULL,
    `inputTokens`  INT          NOT NULL DEFAULT 0,
    `outputTokens` INT          NOT NULL DEFAULT 0,
    `costPence`    INT          NOT NULL DEFAULT 0,
    `inputSample`  MEDIUMTEXT   DEFAULT NULL COMMENT 'Truncated input for audit',
    `outputSample` MEDIUMTEXT   DEFAULT NULL,
    `occurredAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usageID`),
    KEY `idx_au_site_date` (`siteID`, `occurredAt`),
    KEY `idx_au_user`      (`userID`),
    CONSTRAINT `fk_au_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_au_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/ai-assist',         'admin/ai-assist/index.php',  1),
    ('admin/ai-assist/save',    'admin/ai-assist/save.php',   1),
    ('admin/ai-assist/prompt',  'admin/ai-assist/prompt.php', 1),
    ('api/ai-assist/improve',   'api/ai-improve.php',         1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'ai_assist.enabled',            '0', '0', 0),
    (NULL, 'ai_assist.displayName',        'AI Assist', 'AI Assist', 0),
    (NULL, 'ai_assist.displayIcon',        'fa-solid fa-wand-magic-sparkles', 'fa-solid fa-wand-magic-sparkles', 0),
    (NULL, 'ai_assist.provider',           'anthropic', 'anthropic', 0),
    (NULL, 'ai_assist.monthCapPence',      '5000', '5000', 0),
    (NULL, 'ai_assist.userDailyCap',       '20', '20', 0),
    (NULL, 'ai_assist.audience',           'congregation', 'congregation', 0),
    (NULL, 'ai_assist.anthropic.apiKey',   '', '', 1),
    (NULL, 'ai_assist.anthropic.model',    'claude-haiku-4-5-20251001', 'claude-haiku-4-5-20251001', 0),
    (NULL, 'ai_assist.openai.apiKey',      '', '', 1),
    (NULL, 'ai_assist.openai.model',       'gpt-4o-mini', 'gpt-4o-mini', 0),
    (NULL, 'ai_assist.local.baseUrl',      'http://localhost:11434', 'http://localhost:11434', 0),
    (NULL, 'ai_assist.local.model',        'llama3.2', 'llama3.2', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
