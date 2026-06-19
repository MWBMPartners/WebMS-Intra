-- Migration 132: Salvation / decision card tracker (#316)
-- Public-facing card form (someone who's made a decision wants follow-up)
-- + admin moderation + assignment to follow-up coordinator.

CREATE TABLE IF NOT EXISTS `tblSalvationCards` (
    `cardID`         INT NOT NULL AUTO_INCREMENT,
    `siteID`         INT NOT NULL,
    `eventID`        INT DEFAULT NULL COMMENT 'Optional event context',
    `fullName`       VARCHAR(120) NOT NULL,
    `email`          VARCHAR(255) DEFAULT NULL,
    `phone`          VARCHAR(40)  DEFAULT NULL,
    `address`        VARCHAR(500) DEFAULT NULL,
    `decision`       ENUM('first-time','rededication','baptism','membership','bible-study','prayer','other') NOT NULL DEFAULT 'first-time',
    `prayerRequest`  VARCHAR(1000) DEFAULT NULL,
    `status`         ENUM('new','assigned','contacted','complete','archived') NOT NULL DEFAULT 'new',
    `assignedToID`   INT DEFAULT NULL,
    `notes`          VARCHAR(2000) DEFAULT NULL COMMENT 'Internal follow-up notes',
    `createdAt`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cardID`),
    KEY `idx_card_site_status` (`siteID`, `status`, `createdAt`),
    KEY `idx_card_assigned`    (`assignedToID`),
    CONSTRAINT `fk_card_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_card_event`    FOREIGN KEY (`eventID`)      REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL,
    CONSTRAINT `fk_card_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('decision-card',           'salvation/card.php',       0),
    ('decision-card/save',      'salvation/card-save.php',  0),
    ('admin/decision-cards',    'admin/salvation/cards.php',     1),
    ('admin/decision-cards/act','admin/salvation/cards-act.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
