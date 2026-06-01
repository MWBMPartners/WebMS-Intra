-- =============================================================================
-- Migration 086: Invite onboarding app (#239)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblInvitation` (
    `invitationID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `email`         VARCHAR(255) NOT NULL,
    `tokenHash`     CHAR(64)     NOT NULL COMMENT 'SHA-256 of single-use plaintext token',
    `intendedRole`  VARCHAR(64)  DEFAULT NULL COMMENT 'Role to grant on acceptance (e.g. volunteer/admin)',
    `welcomeMessage` TEXT        DEFAULT NULL COMMENT 'Optional message rendered in the invite email',
    `expiresAt`     DATETIME     NOT NULL,
    `acceptedAt`    DATETIME     DEFAULT NULL,
    `acceptedByID`  INT          DEFAULT NULL COMMENT 'FK → tblUsers once accepted',
    `revokedAt`     DATETIME     DEFAULT NULL,
    `revokedByID`   INT          DEFAULT NULL,
    `createdByID`   INT          NOT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`invitationID`),
    UNIQUE KEY `uq_invite_token` (`tokenHash`),
    KEY `idx_invite_site_email` (`siteID`, `email`),
    KEY `idx_invite_status` (`acceptedAt`, `revokedAt`, `expiresAt`),
    CONSTRAINT `fk_invite_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_invite_accepted_user` FOREIGN KEY (`acceptedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_invite_revoked_user`  FOREIGN KEY (`revokedByID`)  REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_invite_creator`       FOREIGN KEY (`createdByID`)  REFERENCES `tblUsers` (`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Single-use invite tokens for new-member onboarding';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('invites',         'invites/index.php',        1),
    ('invites/new',     'invites/new.php',          1),
    ('invites/save',    'invites/save.php',         1),
    ('invites/revoke',  'invites/revoke.php',       1),
    ('auth/invite',     'invites/accept.php',       0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'invites.enabled',           '0',  '0',  0),
    (NULL, 'invites.default_expiry_days','7',  '7',  0),
    (NULL, 'invites.default_role',      'user','user',0),
    (NULL, 'invites.displayName',       'Invitations', 'Invitations', 0),
    (NULL, 'invites.displayIcon',       'fa-solid fa-envelope-open-text', 'fa-solid fa-envelope-open-text', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
