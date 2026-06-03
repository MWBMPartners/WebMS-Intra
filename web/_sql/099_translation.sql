-- =============================================================================
-- Migration 099: Translation (#278)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblContentTranslation` (
    `translationID`     INT          NOT NULL AUTO_INCREMENT,
    `sourceTable`       VARCHAR(64)  NOT NULL,
    `sourceID`          INT          NOT NULL,
    `sourceField`       VARCHAR(64)  NOT NULL DEFAULT 'body',
    `sourceLanguage`    VARCHAR(10)  NOT NULL,
    `targetLanguage`    VARCHAR(10)  NOT NULL,
    `translatedContent` MEDIUMTEXT   NOT NULL,
    `provider`          VARCHAR(30)  NOT NULL DEFAULT 'anthropic',
    `qualityScore`      TINYINT      DEFAULT NULL COMMENT '0-100 self-assessed confidence',
    `costPence`         INT          DEFAULT NULL,
    `translatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`translationID`),
    UNIQUE KEY `uq_ct_lookup` (`sourceTable`, `sourceID`, `sourceField`, `targetLanguage`),
    KEY `idx_ct_dated` (`translatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Per-user opt-in to auto-translate. Locale comes from existing
-- tblUsers.localeKey (the static UI locale), so we don't need a new
-- column there — just a YES/NO opt-in flag here.
CREATE TABLE IF NOT EXISTS `tblUserTranslationPref` (
    `userID`          INT        NOT NULL,
    `autoTranslate`   TINYINT(1) NOT NULL DEFAULT 0,
    `updatedAt`       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`userID`),
    CONSTRAINT `fk_utp_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/translation',      'admin/translation/index.php',  1),
    ('admin/translation/save', 'admin/translation/save.php',   1),
    ('api/translate',          'api/translate.php',            1),
    ('account/translation',    'account/translation.php',      1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'translation.enabled',          '0', '0', 0),
    (NULL, 'translation.displayName',      'Translation', 'Translation', 0),
    (NULL, 'translation.displayIcon',      'fa-solid fa-language', 'fa-solid fa-language', 0),
    (NULL, 'translation.provider',         'anthropic', 'anthropic', 0),
    (NULL, 'translation.monthCapPence',    '5000', '5000', 0),
    (NULL, 'translation.anthropic.apiKey', '', '', 1),
    (NULL, 'translation.anthropic.model',  'claude-haiku-4-5-20251001', 'claude-haiku-4-5-20251001', 0),
    (NULL, 'translation.openai.apiKey',    '', '', 1),
    (NULL, 'translation.openai.model',     'gpt-4o-mini', 'gpt-4o-mini', 0),
    (NULL, 'translation.google.apiKey',    '', '', 1),
    (NULL, 'translation.deepl.apiKey',     '', '', 1),
    (NULL, 'translation.libre.baseUrl',    'https://libretranslate.com', 'https://libretranslate.com', 0),
    (NULL, 'translation.libre.apiKey',     '', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
