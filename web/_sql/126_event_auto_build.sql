-- =============================================================================
-- Migration 126: Auto-build crews + auto-assign jobs (#349)
-- =============================================================================
-- Adds POST routes for two algorithmic distribution operations:
--   • /calendar/event/crews/auto-build   — distributes approved
--     registrations (#347 tblEventRegistrations) across existing crews
--     (#343 tblEventCrews), balancing by grade where data is available.
--   • /calendar/event/jobs/auto-assign   — fills under-capacity jobs
--     (#344 tblEventJobs) from the unassigned-crew-leader pool.
--
-- Pure POST handlers — no new schema. Composes on #343, #344, #347.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/349
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/crews/auto-build', 'calendar/event-crews-auto-build.php', 1),
    ('calendar/event/jobs/auto-assign', 'calendar/event-jobs-auto-assign.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
