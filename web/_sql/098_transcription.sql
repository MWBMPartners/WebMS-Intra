-- =============================================================================
-- Migration 098: Transcription (#276)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblTranscript` (
    `transcriptID` INT          NOT NULL AUTO_INCREMENT,
    `recordingID`  INT          NOT NULL,
    `provider`     VARCHAR(30)  NOT NULL DEFAULT 'openai',
    `status`       ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
    `language`     VARCHAR(10)  DEFAULT NULL,
    `fullText`     MEDIUMTEXT   DEFAULT NULL,
    `jsonSegments` MEDIUMTEXT   DEFAULT NULL COMMENT 'JSON: [{start,end,text,speaker?}, …]',
    `durationSec`  INT          DEFAULT NULL,
    `costPence`    INT          DEFAULT NULL,
    `errorMsg`     VARCHAR(255) DEFAULT NULL,
    `queuedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `generatedAt`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`transcriptID`),
    UNIQUE KEY `uq_t_recording` (`recordingID`),
    KEY `idx_t_status` (`status`),
    CONSTRAINT `fk_t_recording` FOREIGN KEY (`recordingID`) REFERENCES `tblRecording`(`recordingID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblTranscriptSearch` (
    `searchID`     INT      NOT NULL AUTO_INCREMENT,
    `transcriptID` INT      NOT NULL,
    `recordingID`  INT      NOT NULL,
    `siteID`       INT      NOT NULL DEFAULT 1,
    `body`         MEDIUMTEXT NOT NULL,
    PRIMARY KEY (`searchID`),
    UNIQUE KEY `uq_ts_transcript` (`transcriptID`),
    KEY `idx_ts_site` (`siteID`),
    FULLTEXT KEY `ft_ts_body` (`body`),
    CONSTRAINT `fk_ts_transcript` FOREIGN KEY (`transcriptID`) REFERENCES `tblTranscript`(`transcriptID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/transcription',       'admin/transcription/index.php',  1),
    ('admin/transcription/save',  'admin/transcription/save.php',   1),
    ('admin/transcription/run',   'admin/transcription/run.php',    1),
    ('recordings/search',         'recordings/search.php',          1),
    ('recordings/transcript',     'recordings/transcript.php',      1),
    ('recordings/transcribe',     'recordings/transcribe.php',      1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'transcription.enabled',         '0', '0', 0),
    (NULL, 'transcription.displayName',     'Transcription', 'Transcription', 0),
    (NULL, 'transcription.displayIcon',     'fa-solid fa-closed-captioning', 'fa-solid fa-closed-captioning', 0),
    (NULL, 'transcription.provider',        'openai', 'openai', 0),
    (NULL, 'transcription.language',        'en', 'en', 0),
    (NULL, 'transcription.batchSize',       '5', '5', 0),
    (NULL, 'transcription.openai.apiKey',   '', '', 1),
    (NULL, 'transcription.openai.model',    'whisper-1', 'whisper-1', 0),
    (NULL, 'transcription.assemblyai.apiKey','', '', 1),
    (NULL, 'transcription.local.binPath',   '/usr/local/bin/whisper', '/usr/local/bin/whisper', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
