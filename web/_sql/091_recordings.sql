-- =============================================================================
-- Migration 091: Recordings library (#264)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblRecording` (
    `recordingID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`          INT          NOT NULL DEFAULT 1,
    `title`           VARCHAR(255) NOT NULL,
    `presenterID`    INT          DEFAULT NULL,
    `presenterText`   VARCHAR(255) DEFAULT NULL,
    `recordedAt`      DATE         DEFAULT NULL,
    `durationSeconds` INT          DEFAULT NULL,
    `kind`            ENUM('sermon','teaching','music','event','other') NOT NULL DEFAULT 'sermon',
    `scripture`       VARCHAR(255) DEFAULT NULL,
    `topics`          VARCHAR(500) DEFAULT NULL COMMENT 'CSV of tags',
    `summary`         TEXT         DEFAULT NULL,
    `filePath`        VARCHAR(255) DEFAULT NULL COMMENT 'Stored under _uploads/recordings/',
    `fileSize`        BIGINT       DEFAULT NULL,
    `mimeType`        VARCHAR(100) DEFAULT NULL,
    `externalUrl`     VARCHAR(500) DEFAULT NULL,
    `thumbnailPath`   VARCHAR(255) DEFAULT NULL,
    `isPublished`     TINYINT(1)   NOT NULL DEFAULT 1,
    `uploadedByID`    INT          DEFAULT NULL,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`recordingID`),
    KEY `idx_rec_site_date` (`siteID`, `recordedAt`),
    KEY `idx_rec_kind`      (`kind`),
    CONSTRAINT `fk_rec_site`      FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_rec_presenter` FOREIGN KEY (`presenterID`)  REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_rec_uploader`  FOREIGN KEY (`uploadedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblRecordingTopic` (
    `topicID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`     INT          NOT NULL DEFAULT 1,
    `topic`      VARCHAR(100) NOT NULL,
    `useCount`   INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`topicID`),
    UNIQUE KEY `uq_rt_site_topic` (`siteID`, `topic`),
    CONSTRAINT `fk_rt_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblRecordingPlay` (
    `playID`      INT      NOT NULL AUTO_INCREMENT,
    `recordingID` INT      NOT NULL,
    `userID`      INT      DEFAULT NULL,
    `playedAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ipHash`      CHAR(64) DEFAULT NULL COMMENT 'sha256 of remote IP for anon dedupe',
    PRIMARY KEY (`playID`),
    KEY `idx_rp_recording` (`recordingID`, `playedAt`),
    CONSTRAINT `fk_rp_recording` FOREIGN KEY (`recordingID`) REFERENCES `tblRecording`(`recordingID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_user`      FOREIGN KEY (`userID`)      REFERENCES `tblUsers`(`userID`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('recordings',         'recordings/index.php',   1),
    ('recordings/view',    'recordings/view.php',    1),
    ('recordings/manage',  'recordings/manage.php',  1),
    ('recordings/upload',  'recordings/upload.php',  1),
    ('recordings/save',    'recordings/save.php',    1),
    ('recordings/delete',  'recordings/delete.php',  1),
    ('recordings/stream',  'recordings/stream.php',  1),
    ('recordings.rss',     'recordings/feed.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'recordings.enabled',        '0', '0', 0),
    (NULL, 'recordings.displayName',    'Recordings', 'Recordings', 0),
    (NULL, 'recordings.displayIcon',    'fa-solid fa-microphone-lines', 'fa-solid fa-microphone-lines', 0),
    (NULL, 'recordings.max_upload_mb',  '200', '200', 0),
    (NULL, 'recordings.podcast_author', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
