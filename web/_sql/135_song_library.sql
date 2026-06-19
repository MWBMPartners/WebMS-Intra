-- Migration 135: Song library + CCLI (#309)
-- Worship song catalogue with CCLI reference numbers, copyright,
-- default key/tempo, and full lyrics.

CREATE TABLE IF NOT EXISTS `tblSongs` (
    `songID`         INT NOT NULL AUTO_INCREMENT,
    `siteID`         INT NOT NULL,
    `title`          VARCHAR(255) NOT NULL,
    `author`         VARCHAR(255) DEFAULT NULL,
    `ccliNumber`     VARCHAR(40)  DEFAULT NULL,
    `copyrightLine`  VARCHAR(500) DEFAULT NULL COMMENT 'e.g. © 1995 Kingsway Thankyou Music',
    `defaultKey`     VARCHAR(10)  DEFAULT NULL,
    `defaultTempo`   VARCHAR(20)  DEFAULT NULL COMMENT 'BPM or descriptor',
    `lyrics`         MEDIUMTEXT   DEFAULT NULL,
    `tags`           VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated themes',
    `isActive`       TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`    INT DEFAULT NULL,
    `createdAt`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`songID`),
    KEY `idx_song_site_title` (`siteID`, `title`),
    KEY `idx_song_ccli`       (`ccliNumber`),
    FULLTEXT KEY `ft_song_search` (`title`, `author`, `lyrics`),
    CONSTRAINT `fk_song_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_song_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isEncrypted`) VALUES
    ('worship.ccli_account_number', '', 0)
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('worship/songs',         'worship/songs.php',      1),
    ('worship/song',          'worship/song.php',       1),
    ('worship/songs/save',    'worship/songs-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
