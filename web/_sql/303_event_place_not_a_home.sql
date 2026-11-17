-- =============================================================================
-- Migration 303: an event remembers that its place is not anybody's home (#1088)
-- =============================================================================
-- What this changes: `tblEvents` gains `locationNotAHome` (TINYINT, default 0),
-- placed after `locationEmail`.
--
-- WHY. The event form asks "This is someone's home" or "This is a public
-- place", but only used the answer to choose how the remembered geocoder
-- answer was kept (D-183). It was not stored, so the event page could not tell
-- the two apart and sent every viewer's browser to OpenStreetMap for map
-- squares around the place, a home included. With the answer stored,
-- `OutsideServices::allowsEventPlaceMap()` shows a map only for a place marked
-- not anybody's home (D-128), and only if the map settings allow (D-058).
-- An event names no host, so a home can never be agreed to by its person
-- (D-120): no map.
--
-- EXISTING ROWS become 0 = "a home or not said", the safe direction (D-183).
--
-- HOW IT IS WRITTEN. `information_schema` + PREPARE, because MySQL 8 rejects
-- MariaDB's `ADD COLUMN IF NOT EXISTS` (.claude/rules/database.md). Safe to
-- run twice. The definition is word for word what `full_schema.sql` says.
-- =============================================================================

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblEvents'
      AND `COLUMN_NAME`  = 'locationNotAHome'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `locationNotAHome` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = whoever set the place said it is a public place, not anybody''''s home (D-128, D-183), so a map of it may be shown if the map settings allow; 0 = a home, or not said: the safe reading, no outside map (D-120). Set by calendar/manage/save.php; never by the events API or imports (migration 303)'' AFTER `locationEmail`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('303_event_place_not_a_home.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
