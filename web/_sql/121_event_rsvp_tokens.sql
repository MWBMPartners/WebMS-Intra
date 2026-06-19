-- =============================================================================
-- Migration 121: Anonymous email-link RSVP (#335)
-- =============================================================================
-- For events where attendees aren't portal users (parents of VBS kids,
-- community guests, etc.) — coordinator generates a per-email token,
-- emails the invitation, recipient clicks the link, gets a 3-button RSVP
-- landing page WITHOUT logging in.
--
-- Tokens are 64-char hex (32 bytes of random_bytes), single-use,
-- 60-day default expiry. The recorded response also lands in
-- tblEventRSVPs as an anonymous row so headcount + roster + the bulk
-- email broadcaster (#350) can see them.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/335
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventRSVPInvites` (
    `inviteID`     INT          NOT NULL AUTO_INCREMENT,
    `eventID`      INT          NOT NULL,
    `email`        VARCHAR(255) NOT NULL,
    `displayName`  VARCHAR(120) DEFAULT NULL,
    `token`        VARCHAR(64)  NOT NULL,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiresAt`    DATETIME     NOT NULL,
    `usedAt`       DATETIME     DEFAULT NULL,
    `response`     ENUM('going','maybe','declined') DEFAULT NULL,
    PRIMARY KEY (`inviteID`),
    UNIQUE KEY `uq_invite_token` (`token`),
    UNIQUE KEY `uq_invite_event_email` (`eventID`, `email`),
    KEY `idx_invite_event` (`eventID`),
    CONSTRAINT `fk_rsvpinvite_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rsvpinvite_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Public (no login). Token is the secret; the route itself is unprotected.
    ('calendar/rsvp-by-link', 'calendar/rsvp-by-link.php', 0),
    -- Coordinator UI to generate + send invites.
    ('calendar/event/invites',      'calendar/event-invites.php',      1),
    ('calendar/event/invites/send', 'calendar/event-invites-send.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
