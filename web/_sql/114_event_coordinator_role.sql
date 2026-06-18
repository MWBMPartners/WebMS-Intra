-- =============================================================================
-- Migration 114: Event coordinator role (#341)
-- =============================================================================
-- Delegate single-event management to a non-admin user. They own ONE event
-- end-to-end — edit details, manage RSVPs, mark attendance, assign crews —
-- without site-wide admin privileges. Mirrors VBS Pro's "My Events" pattern
-- and is the foundation for #342, #345, #343, #344 below.
--
-- ACL approach: lightweight junction table `tblEventCoordinators`. Every
-- event-mutating endpoint that previously required `App::isAdmin()` now
-- additionally checks `Auth::isCoordinatorOf($eventID)`. Either grants edit
-- rights.
--
-- Not a generic ACL primitive — we deliberately keep this narrow. If we ever
-- need fine-grained per-resource permissions across more apps, a real RBAC
-- table will be its own design pass.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/341
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventCoordinators` (
    `coordinatorID` INT          NOT NULL AUTO_INCREMENT,
    `eventID`       INT          NOT NULL,
    `userID`        INT          NOT NULL,
    `grantedByID`   INT          DEFAULT NULL COMMENT 'Admin who granted (NULL = system / migration)',
    `grantedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revokedAt`     DATETIME     DEFAULT NULL COMMENT 'Soft-revoke for audit; non-NULL = inactive',
    -- v1 = full edit for the assigned event. Bitfield / JSON permissions
    -- would be the natural v1.1 extension if we ever want read-only
    -- coordinators (e.g. checked-in volunteers viewing the briefing only).
    `permissions`   VARCHAR(50)  NOT NULL DEFAULT 'full',
    PRIMARY KEY (`coordinatorID`),
    UNIQUE KEY `uq_ec_event_user` (`eventID`, `userID`),
    KEY `idx_ec_user_active` (`userID`, `revokedAt`),
    CONSTRAINT `fk_ec_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ec_user`  FOREIGN KEY (`userID`)  REFERENCES `tblUsers`(`userID`)  ON DELETE CASCADE,
    CONSTRAINT `fk_ec_grantor` FOREIGN KEY (`grantedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    -- Coordinator-facing dashboard ("My Events I coordinate").
    ('calendar/my-events',                 'calendar/my-events.php',                  1),
    -- Admin-facing per-event coordinator picker.
    ('admin/calendar/coordinators',        'admin/calendar/coordinators.php',         1),
    ('admin/calendar/coordinators/save',   'admin/calendar/coordinators-save.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
