-- =============================================================================
-- Migration 275: who entered a small group's meeting place, and whether they
--                agreed to a map of it (#809; the owner's decision D-120)
-- =============================================================================
-- Two new columns on `tblSmallGroups` and one link to `tblUsers`. No row is
-- deleted and no existing value is changed.
--
--   placeEnteredByID   who last entered or changed where the group meets
--   placeMapAgreed     1 = that person agreed a map of the place may be shown
--   fk_sg_place_enterer  placeEnteredByID -> tblUsers.userID, ON DELETE SET NULL
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
    'ALTER TABLE `tblSmallGroups` ADD COLUMN `placeEnteredByID` INT DEFAULT NULL COMMENT ''FK to tblUsers: who last entered or changed where the group meets (#809, migration 275). Their agreement decides whether a MAP of the place is shown (D-120). Empty for a group from before #809, or with no place'' AFTER `locationVisibility`',
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
    'ALTER TABLE `tblSmallGroups` ADD COLUMN `placeMapAgreed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the person in placeEnteredByID agreed that a map of this meeting place may be shown (D-120: treated as the host''''s home, on the terms of D-072). 0 = not agreed, the safe reading for every older group'' AFTER `placeEnteredByID`',
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

INSERT INTO `tblMigrations` (`filename`) VALUES ('275_group_meeting_place_map_agreement.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
