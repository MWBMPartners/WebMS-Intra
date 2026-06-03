-- =============================================================================
-- Migration 104: Disaster-recovery in-portal landing (#250)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/disaster-recovery', 'help/disaster-recovery.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
