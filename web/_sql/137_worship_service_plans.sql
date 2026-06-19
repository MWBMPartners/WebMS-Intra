-- =============================================================================
-- Migration 137: Worship Service Plans — Phase 1 of #308 Worship Engine
-- =============================================================================
-- A service plan is an ORDERED list of slide items the worship operator runs
-- through during a service. Three item types in v1:
--   • song   — references tblSongs (#309); slide body comes from the song's
--              lyrics column at render time, so editing the song updates
--              every plan that references it.
--   • text   — free-form announcement / welcome / dismissal slide.
--   • verse  — free-form Bible reference + body (book/chapter/verse in
--              slideTitle, passage in slideBody). v1 is hand-entered; v1.1
--              wires a Bible API.
--
-- Optionally bound to an event (eventID) so a coordinator running their own
-- event (#341 Auth::isCoordinatorOf gate) can build the plan without admin.
-- A plan with eventID = NULL is a free-floating template / re-usable
-- service template (admin-managed).
--
-- Phase 2 will add tblServicePlanState (live operator pointer + display
-- mirror) — schema deliberately kept out of v1 so Phase 1 is a self-
-- contained CRUD ship.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblServicePlans` (
    `planID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL,
    `eventID`       INT          DEFAULT NULL COMMENT 'Optional event binding; NULL = re-usable template',
    `name`          VARCHAR(120) NOT NULL,
    `notes`         VARCHAR(1000) DEFAULT NULL COMMENT 'Operator-only context notes shown on the editor',
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`   INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`planID`),
    KEY `idx_plan_site_active` (`siteID`, `isActive`, `updatedAt`),
    KEY `idx_plan_event`       (`eventID`),
    CONSTRAINT `fk_plan_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_plan_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL,
    CONSTRAINT `fk_plan_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblServicePlanItems` (
    `itemID`        INT          NOT NULL AUTO_INCREMENT,
    `planID`        INT          NOT NULL,
    `sortOrder`     INT          NOT NULL DEFAULT 0,
    `itemType`      ENUM('song','text','verse') NOT NULL DEFAULT 'text',
    `songID`        INT          DEFAULT NULL COMMENT 'Required when itemType=song; references tblSongs (#309)',
    `slideTitle`    VARCHAR(255) DEFAULT NULL COMMENT 'For verse: the reference (e.g. "John 3:16"); for text: optional heading',
    `slideBody`     MEDIUMTEXT   DEFAULT NULL COMMENT 'For text/verse: the rendered text. Songs read from tblSongs.lyrics at render time.',
    `slideNotes`    VARCHAR(500) DEFAULT NULL COMMENT 'Operator-only notes; NEVER projected on the display',
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`itemID`),
    KEY `idx_planitem_plan_sort` (`planID`, `sortOrder`),
    KEY `idx_planitem_song`      (`songID`),
    CONSTRAINT `fk_planitem_plan` FOREIGN KEY (`planID`) REFERENCES `tblServicePlans`(`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_planitem_song` FOREIGN KEY (`songID`) REFERENCES `tblSongs`(`songID`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('worship/plans',      'worship/plans.php',      1),
    ('worship/plan',       'worship/plan.php',       1),
    ('worship/plan/save',  'worship/plan-save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
