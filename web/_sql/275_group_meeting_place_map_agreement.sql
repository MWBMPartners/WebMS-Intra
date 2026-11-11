-- =============================================================================
-- Migration 275: who entered a small group's meeting place, whether they
--                agreed to a map of it, and the homes a group takes turns
--                meeting in (#809; the owner's decisions D-120 and D-123)
-- =============================================================================
-- No row is deleted and no existing value is changed.
--
--   tblSmallGroups.placeEnteredByID   who last typed in or changed the USUAL place
--   tblSmallGroups.hostUserID         the member whose home it is (D-124)
--   tblSmallGroups.placeMapAgreed     1 = THAT host agreed to a map of it
--   fk_sg_place_enterer, fk_sg_host   -> tblUsers, SET NULL
--   tblSmallGroupPlaces (new)         the hosts' homes a group rotates between,
--                                     each with its OWN named host (hostUserID)
--                                     and that host's agreement
--   tblSmallGroupMeetings.placeID     which of those a meeting was held in;
--                                     empty = the group's usual place
--   fk_sgmt_place                     placeID -> tblSmallGroupPlaces, SET NULL
--   tblRoutes                         small-groups/place, small-groups/place-save
--
-- D-124 (11 November 2026, superseding D-120 and D-122 in part): the host is a
-- MEMBER NAMED for each place, not whoever typed it in. Only that member can
-- agree to a map of it, and erasing them removes it. A leader who types in a
-- member's address names that member and cannot agree on their behalf. So the
-- agreement means "the named host agreed", and placeEnteredByID is only a
-- record of who typed it.
--
-- D-123 (11 November 2026): a group may meet in a different host's home from
-- one meeting to the next, and each place belongs to its own host: its map
-- needs THAT host's agreement, and one host's agreement never stands for
-- another's home. So the agreement lives on each place's own row, never on the
-- group. The usual place stays on tblSmallGroups, so a group that meets in one
-- place works exactly as before.
--
-- -----------------------------------------------------------------------------
-- WHY (the owner's decision D-120, 11 November 2026)
-- -----------------------------------------------------------------------------
-- A small group's page drew an OpenStreetMap map of where the group meets.
-- Drawing it tells OpenStreetMap, an outside company, where that place is,
-- together with the internet address of whoever is looking, and a small group
-- very often meets in somebody's home. Nothing asked anybody (#809).
--
-- The owner decided the map is to be treated as the HOST'S HOME, on the same
-- terms as a member's own home map (D-072): shown only where whoever entered
-- the meeting place has agreed. Until now nothing recorded who entered it --
-- `createdByID` says who made the GROUP, and a leader can change the place
-- later -- so these two columns are the smallest honest record of it. The page
-- that decides is `Portal\Core\OutsideServices::allowsMeetingPlaceMap()`; the
-- rule for what a save records is `Portal\Core\SmallGroups::placeAgreementAfterSave()`.
--
-- -----------------------------------------------------------------------------
-- EXISTING GROUPS: NOBODY, AND NOT AGREED -- ON PURPOSE
-- -----------------------------------------------------------------------------
-- Every group that exists before this migration gets NO person recorded and
-- NO agreement, so its page shows the written address and no map. That is the
-- safe reading: nobody agreed. It is NOT filled from `createdByID`: the person
-- who made a group did not necessarily enter where it meets now, and an
-- agreement nobody gave cannot be inferred from who pressed "create". A leader
-- who opens the group's edit form and ticks the new box becomes the person
-- recorded, with their agreement.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Each column and the link are added only when missing. Written for both MySQL
-- 8.0 and MariaDB: no `IF NOT EXISTS` on a column (MariaDB-only), and every
-- identifier backticked.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/809
-- =============================================================================

-- ── tblSmallGroups.placeEnteredByID ─────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSmallGroups'
      AND COLUMN_NAME  = 'placeEnteredByID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSmallGroups` ADD COLUMN `placeEnteredByID` INT DEFAULT NULL COMMENT ''FK to tblUsers: who last typed in or changed where the group usually meets (#809, migration 275). A record of who did it; it decides nothing (D-124)'' AFTER `locationVisibility`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── tblSmallGroups.placeMapAgreed ───────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSmallGroups'
      AND COLUMN_NAME  = 'placeMapAgreed'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSmallGroups` ADD COLUMN `placeMapAgreed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the host in hostUserID agreed that a map of this meeting place may be shown (D-120, D-124). 0 = not agreed, the safe reading for every older group'' AFTER `placeEnteredByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── fk_sg_place_enterer ─────────────────────────────────────────────────────
-- ON DELETE SET NULL, as `fk_sg_creator` beside it: a deleted account leaves
-- nobody recorded, which the page reads as "not agreed".
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'tblSmallGroups'
      AND CONSTRAINT_NAME = 'fk_sg_place_enterer'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblSmallGroups` ADD CONSTRAINT `fk_sg_place_enterer` FOREIGN KEY (`placeEnteredByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── tblSmallGroups.hostUserID (D-124) ───────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSmallGroups'
      AND COLUMN_NAME  = 'hostUserID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSmallGroups` ADD COLUMN `hostUserID` INT DEFAULT NULL COMMENT ''FK to tblUsers: the member whose home the usual meeting place is (D-124). Only that member can agree to a map of it. Empty = no host named, so no map'' AFTER `placeEnteredByID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'tblSmallGroups'
      AND CONSTRAINT_NAME = 'fk_sg_host'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblSmallGroups` ADD CONSTRAINT `fk_sg_host` FOREIGN KEY (`hostUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── tblSmallGroupPlaces (D-123) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tblSmallGroupPlaces` (
    `placeID`          INT           NOT NULL AUTO_INCREMENT,
    `siteID`           INT           NOT NULL COMMENT 'Copied from tblSmallGroups.siteID by the save handler, never taken from a form',
    `groupID`          INT           NOT NULL COMMENT 'FK to tblSmallGroups: the group that meets here',
    `placeLabel`       VARCHAR(100)  DEFAULT NULL COMMENT 'A short name to choose the place by, typed by whoever entered it (for example a family name). Optional',
    `addressLine1`     VARCHAR(255)  DEFAULT NULL,
    `addressLine2`     VARCHAR(255)  DEFAULT NULL,
    `city`             VARCHAR(100)  DEFAULT NULL,
    `region`           VARCHAR(100)  DEFAULT NULL COMMENT 'County / state / province',
    `postcode`         VARCHAR(20)   DEFAULT NULL,
    `countryCode`      CHAR(2)       DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2. No default: a place is never assumed to be in any one country (#935)',
    `latitude`         DECIMAL(10,7) DEFAULT NULL,
    `longitude`        DECIMAL(10,7) DEFAULT NULL,
    `what3words`       VARCHAR(100)  DEFAULT NULL COMMENT 'what3words address',
    `hostUserID`       INT           DEFAULT NULL COMMENT 'FK to tblUsers: the member whose home this is (D-124). Only that member can agree to a map of it, and erasing them removes it (D-122). Empty = no host, so no map',
    `placeEnteredByID` INT           DEFAULT NULL COMMENT 'FK to tblUsers: who last typed in or changed this home (#809, migration 275). A record of who did it; it decides nothing (D-124)',
    `placeMapAgreed`   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = the host in hostUserID agreed that a map of this home may be shown (D-120, D-124). 0 = not agreed',
    `isActive`         TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = removed from the group''s list. A removed place keeps its row so a past meeting can say it met somewhere no longer recorded, but its address, who entered it and the agreement are emptied',
    `createdAt`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`placeID`),
    KEY `idx_sgp_group_active` (`groupID`, `isActive`),
    KEY `idx_sgp_site` (`siteID`),
    KEY `idx_sgp_host` (`hostUserID`),
    CONSTRAINT `fk_sgp_site`    FOREIGN KEY (`siteID`)           REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sgp_group`   FOREIGN KEY (`groupID`)          REFERENCES `tblSmallGroups`(`groupID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sgp_enterer` FOREIGN KEY (`placeEnteredByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_sgp_host`    FOREIGN KEY (`hostUserID`)       REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Small Groups — the homes a group takes turns meeting in, each its own host''s (#809, D-123)';

-- ── tblSmallGroupMeetings.placeID and its link ──────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSmallGroupMeetings'
      AND COLUMN_NAME  = 'placeID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblSmallGroupMeetings` ADD COLUMN `placeID` INT DEFAULT NULL COMMENT ''FK to tblSmallGroupPlaces: where this meeting was held, when it was one of the hosts'''' homes the group takes turns in (D-123). Empty = the group''''s usual place (#809, migration 275)'' AFTER `meetingTime`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblSmallGroupMeetings'
      AND INDEX_NAME   = 'idx_sgmt_place'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblSmallGroupMeetings` ADD KEY `idx_sgmt_place` (`placeID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'tblSmallGroupMeetings'
      AND CONSTRAINT_NAME = 'fk_sgmt_place'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblSmallGroupMeetings` ADD CONSTRAINT `fk_sgmt_place` FOREIGN KEY (`placeID`) REFERENCES `tblSmallGroupPlaces`(`placeID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── the two addresses that add, change and remove a place ───────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('small-groups/place',        'small-groups/place.php',        1),
    ('small-groups/place-save',   'small-groups/place-save.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('275_group_meeting_place_map_agreement.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
