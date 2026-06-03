-- =============================================================================
-- Migration 107: Offline queue user-inspector route (#233)
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/offline-queue', 'account/offline-queue.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
