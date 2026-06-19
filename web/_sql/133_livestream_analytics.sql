-- Migration 133: Livestream analytics (#318)
-- Embedded video player pings the portal; portal records concurrent viewers,
-- peak, total unique sessions, geo (when consented). Public endpoint
-- accepts the ping; admin dashboard reads aggregates.

CREATE TABLE IF NOT EXISTS `tblLivestreamSessions` (
    `sessionID`    INT NOT NULL AUTO_INCREMENT,
    `siteID`       INT NOT NULL,
    `eventID`      INT DEFAULT NULL,
    `sessionToken` CHAR(64) NOT NULL COMMENT 'Random token from the embed player; client-issued',
    `joinedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lastPingAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `leftAt`       DATETIME DEFAULT NULL,
    `userAgent`    VARCHAR(255) DEFAULT NULL,
    `ipCountry`    CHAR(2) DEFAULT NULL,
    PRIMARY KEY (`sessionID`),
    UNIQUE KEY `uq_session_token` (`sessionToken`),
    KEY `idx_session_event`      (`eventID`, `joinedAt`),
    KEY `idx_session_lastping`   (`lastPingAt`),
    CONSTRAINT `fk_lss_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`)   ON DELETE CASCADE,
    CONSTRAINT `fk_lss_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('api/livestream/ping',     'api/livestream-ping.php',          0),
    ('admin/livestream',        'admin/livestream/dashboard.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
