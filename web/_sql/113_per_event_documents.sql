-- =============================================================================
-- Migration 113: Per-event document library link (#351)
-- =============================================================================
-- Adds an optional eventID column to tblDocuments so a document can be
-- scoped to a single event. The /calendar/event page then renders a
-- "Documents" section listing tblDocuments WHERE eventID = ? AND
-- isPublished = 1 AND isDeleted = 0.
--
-- Distinct from tblEventMaterials (which is a simpler per-event attachment
-- table with no category / no soft-delete / no isPublished workflow).
-- Documents that need the full library UX (categorisation, controlled
-- visibility, download counter) live here; quick attachments stay in
-- tblEventMaterials.
--
-- The eventID FK is ON DELETE SET NULL so deleting an event does NOT cascade
-- to delete its documents — the doc just becomes site-scoped again. This
-- prevents accidental data loss when an admin trims old events.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/351
-- =============================================================================

-- ➕ tblDocuments.eventID — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDocuments'
      AND COLUMN_NAME  = 'eventID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblDocuments` ADD COLUMN `eventID` INT DEFAULT NULL COMMENT ''Optional FK → tblEvents — scope this document to a single event (#351)'' AFTER `categoryID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_doc_event — guarded ADD CONSTRAINT (was bare; broke installer full_schema-then-replay on both engines)
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblDocuments'
      AND CONSTRAINT_NAME   = 'fk_doc_event'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblDocuments` ADD CONSTRAINT `fk_doc_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔍 idx_doc_event_pub — guarded ADD INDEX
-- Index for the public event-page query: WHERE eventID = ? AND isPublished = 1 AND isDeleted = 0.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblDocuments'
      AND INDEX_NAME   = 'idx_doc_event_pub'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblDocuments` ADD INDEX `idx_doc_event_pub` (`eventID`, `isPublished`, `isDeleted`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
