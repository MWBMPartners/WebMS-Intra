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

ALTER TABLE `tblDocuments`
    ADD COLUMN IF NOT EXISTS `eventID` INT DEFAULT NULL
        COMMENT 'Optional FK → tblEvents — scope this document to a single event (#351)' AFTER `categoryID`;

ALTER TABLE `tblDocuments`
    ADD CONSTRAINT `fk_doc_event`
        FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL;

-- Index for the public event-page query: WHERE eventID = ? AND isPublished = 1 AND isDeleted = 0.
CREATE INDEX IF NOT EXISTS `idx_doc_event_pub` ON `tblDocuments`(`eventID`, `isPublished`, `isDeleted`);
