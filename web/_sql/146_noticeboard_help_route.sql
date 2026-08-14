-- =============================================================================
-- Migration 146: Add /help/noticeboard route (#362)
-- =============================================================================
-- Help Centre guide for the Community Noticeboard app (poster wall, #360 /
-- migration 145). isProtected matches the other general-app help topics
-- (help/calendar, help/prayer-requests) — reachable without forcing a login
-- redirect, not admin-gated like help/support or help/disaster-recovery.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/362
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/noticeboard', 'help/noticeboard.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
