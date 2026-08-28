-- =============================================================================
-- Migration 179: Per-event venue/room links + room-aware coverage (#436)
--
-- Path: web/_sql/179_event_venue_link.sql
-- Additive follow-up to the shipped Venue Bookings app (#429, migration 170)
-- and the wall-clock fix (#435). Adds two OPTIONAL, nullable columns to
-- tblEvents so a calendar event can declare which hired external venue (and,
-- optionally, which room within it) it is actually held at — today's
-- `venues.check` picker (Surface A) is advisory-only and persists nothing.
--
-- New columns (both on tblEvents):
--   `venueID` INT NULL — optional link to tblVenues.venueID. NULL = no
--   venue link (the state of every existing event — zero data migration,
--   no backfill from tblVenueBookings.eventID per the plan's Open Question
--   7: a hire hosting an event isn't necessarily "the event's venue").
--   `roomID`  INT NULL — optional link to tblVenueRooms.roomID, meaningful
--   only alongside a venueID. NULL = whole venue (mirrors
--   tblVenueBookings.roomID's own "NULL = the whole venue" semantics,
--   170_venue_bookings.sql:325).
--
-- Both FKs `ON DELETE SET NULL` — deleting the venue or room silently
-- unlinks the event rather than blocking the delete or cascading. The
-- room-belongs-to-venue invariant (a stale roomID surviving a VENUE change,
-- as opposed to a room deletion) is enforced at the PHP write choke-point
-- (calendar/manage/save.php) — this migration only ships the schema.
--
-- Core changes riding on these columns (Portal\Core\Venues.php):
--   `classifyEventCoverage(array $event, int $venueId, ?int $roomId = null)`
--   gains the optional trailing `$roomId` — both existing call sites
--   (venues/api/check.php, calendar/manage/save.php) omit it and get
--   BIT-FOR-BIT today's output. When a room is supplied, per-day booking
--   rows are filtered to `roomID IS NULL OR roomID = $roomId` (a
--   whole-venue booking still covers every room) before the existing
--   day-cascade runs unmodified, and a new `COVERAGE_ROOM_NOT_COVERED`
--   verdict fires when the room itself has no cover but the venue has a
--   confirmed bookable hire for a DIFFERENT room that day. This is exactly
--   the rule the class header at Venues.php pre-planned ("rule (b) must
--   tighten to room-aware coverage … if events ever gain room placement").
--
-- ALL DDL below goes through the information_schema + PREPARE/EXECUTE guard
-- idiom (MySQL 8 rejects MariaDB's `IF [NOT] EXISTS` on ALTER with ERROR
-- 1064 — see DEV_NOTES.md "Portable DDL convention"; house precedents:
-- migrations 037, 112, 138, 173). Zero data migration, zero backfill sweep
-- — ONLY the schema objects themselves. Replays as a no-op.
--
-- full_schema.sql fold note: tblEvents (line ~742) is created ~6,000 lines
-- BEFORE tblVenues/tblVenueRooms (~6815/6849), and full_schema.sql contains
-- zero ALTER statements. The two COLUMNS + their plain KEY indexes fold
-- inline into tblEvents' CREATE (no cross-table dependency); the two FKs
-- CANNOT fold there (forward reference to tables that don't exist yet ⇒
-- errno 1824) so they are added ONLY by this migration's guarded blocks on
-- replay — the installer replays every numbered migration after
-- full_schema.sql (CLAUDE.md "SQL dialect trap"), so a fresh install ends
-- up with the same two FKs an upgraded install gets. See the matching
-- comments at both the tblEvents fold and the tblVenues section header in
-- full_schema.sql.
--
-- No new routes (both endpoints — venues/api/check.php, calendar/manage/
-- save.php — already exist and are flag-gated: 170_venue_bookings.sql:586
-- for the former, the admin session gate for the latter), no new settings
-- keys, no tblRoutes rows (the #372 dead-route lesson: never register
-- api/* in tblRoutes).
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/436
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================

-- ➕ tblEvents.venueID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'venueID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `venueID` INT DEFAULT NULL COMMENT ''Optional link to the hired external venue hosting this event — tblVenues.venueID; NULL = none (#436)'' AFTER `externalUid`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ tblEvents.roomID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND COLUMN_NAME  = 'roomID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `roomID` INT DEFAULT NULL COMMENT ''Optional room within venueID — tblVenueRooms.roomID; NULL = whole venue (#436)'' AFTER `venueID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 idx_event_venue — guarded ADD KEY
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND INDEX_NAME   = 'idx_event_venue'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEvents` ADD KEY `idx_event_venue` (`venueID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 idx_event_room — guarded ADD KEY
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblEvents'
      AND INDEX_NAME   = 'idx_event_room'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblEvents` ADD KEY `idx_event_room` (`roomID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_event_venue — guarded ADD CONSTRAINT (ON DELETE SET NULL — deleting
-- the venue silently unlinks every event that pointed at it).
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblEvents'
      AND CONSTRAINT_NAME   = 'fk_event_venue'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblEvents` ADD CONSTRAINT `fk_event_venue` FOREIGN KEY (`venueID`) REFERENCES `tblVenues`(`venueID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_event_room — guarded ADD CONSTRAINT (ON DELETE SET NULL — deleting
-- the room silently unlinks; the venue link itself is untouched).
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblEvents'
      AND CONSTRAINT_NAME   = 'fk_event_room'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblEvents` ADD CONSTRAINT `fk_event_room` FOREIGN KEY (`roomID`) REFERENCES `tblVenueRooms`(`roomID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('179_event_venue_link.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
