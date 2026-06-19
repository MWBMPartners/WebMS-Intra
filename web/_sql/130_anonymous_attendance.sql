-- Migration 130: Anonymous attendance check-in (#314)
-- Public self check-in for an event — no login. Used at the door
-- (kiosk mode) or via a phone QR scan at the start of the service.

CREATE TABLE IF NOT EXISTS `tblAnonymousCheckins` (
    `checkinID`     INT NOT NULL AUTO_INCREMENT,
    `eventID`       INT NOT NULL,
    `headcount`     INT NOT NULL DEFAULT 1 COMMENT 'How many people checking in together',
    `source`        ENUM('self','kiosk','qr') NOT NULL DEFAULT 'self',
    `userAgent`     VARCHAR(255) DEFAULT NULL,
    `ipHash`        CHAR(64) DEFAULT NULL COMMENT 'SHA-256 of IP for soft-dedup, NOT raw IP',
    `checkedInAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`checkinID`),
    KEY `idx_checkin_event` (`eventID`, `checkedInAt`),
    CONSTRAINT `fk_anoncheckin_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attend',          'calendar/anon-checkin.php',       0),
    ('attend/save',     'calendar/anon-checkin-save.php',  0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
