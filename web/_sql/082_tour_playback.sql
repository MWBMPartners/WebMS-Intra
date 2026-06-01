-- =============================================================================
-- Migration 082: Tour playback API routes (#253)
-- =============================================================================
-- Completes the tour engine scaffolded in #237. Registers the two read/write
-- API endpoints the playback JS calls.
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('api/tours/active',   'api/tours/active.php',   1),
    ('api/tours/complete', 'api/tours/complete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
