-- =============================================================================
-- Migration 119: Event broadcast / bulk-email (#350)
-- =============================================================================
-- Coordinators (or admins) compose a single email + pick a segment of the
-- event's people — all RSVPs, a specific crew (#343), a specific job
-- (#344), or every volunteer. The portal sends via the existing Mailer.
--
-- v1: routes + a tiny audit row per send. No queue, no per-recipient
-- tracking — that's v1.1.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/350
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventBroadcasts` (
    `broadcastID`   INT          NOT NULL AUTO_INCREMENT,
    `eventID`       INT          NOT NULL,
    `sentByID`      INT          DEFAULT NULL,
    `segment`       VARCHAR(60)  NOT NULL COMMENT 'all-rsvps / all-volunteers / crew:<id> / job:<id>',
    `subject`       VARCHAR(255) NOT NULL,
    `body`          MEDIUMTEXT   NOT NULL,
    `recipientCount` INT         NOT NULL DEFAULT 0,
    `sentAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`broadcastID`),
    KEY `idx_broadcast_event` (`eventID`, `sentAt`),
    CONSTRAINT `fk_broadcast_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_broadcast_user`  FOREIGN KEY (`sentByID`) REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/broadcast',      'calendar/event-broadcast.php',      1),
    ('calendar/event/broadcast/send', 'calendar/event-broadcast-send.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
