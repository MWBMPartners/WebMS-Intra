-- =============================================================================
-- Migration 054: tblEvents — add missing deletedAt column
-- =============================================================================
-- web/public_html/events/api/delete.php sets `deletedAt = NOW()` alongside
-- `isDeleted = 1` in its soft-delete UPDATE, but the column was never added
-- to tblEvents. Every admin attempt to delete an event hit
-- "Unknown column 'deletedAt' in 'field list'".
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/201
-- =============================================================================

ALTER TABLE `tblEvents`
    ADD COLUMN IF NOT EXISTS `deletedAt` DATETIME DEFAULT NULL
        COMMENT 'Timestamp of soft-delete (set when isDeleted flips to 1)'
        AFTER `isDeleted`;
