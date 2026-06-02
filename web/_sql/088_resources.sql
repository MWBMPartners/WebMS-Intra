-- =============================================================================
-- Migration 088: Resource booking app (#263)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblResource` (
    `resourceID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`            INT          NOT NULL DEFAULT 1,
    `name`              VARCHAR(255) NOT NULL,
    `description`       TEXT         DEFAULT NULL,
    `category`          ENUM('room','equipment','vehicle','other') NOT NULL DEFAULT 'room',
    `capacity`          INT          DEFAULT NULL COMMENT 'Optional max-occupant capacity',
    `location`          VARCHAR(255) DEFAULT NULL,
    `requiresApproval`  TINYINT(1)   NOT NULL DEFAULT 0,
    `hourlyRatePence`   INT          DEFAULT NULL COMMENT 'For paid hire — NULL = free',
    `bufferMinutes`     INT          NOT NULL DEFAULT 0 COMMENT 'Gap required between consecutive bookings',
    `isActive`          TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`resourceID`),
    KEY `idx_resource_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_resource_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblResourceBooking` (
    `bookingID`     INT          NOT NULL AUTO_INCREMENT,
    `resourceID`    INT          NOT NULL,
    `bookedByID`    INT          NOT NULL,
    `startAt`       DATETIME     NOT NULL,
    `endAt`         DATETIME     NOT NULL,
    `purpose`       VARCHAR(255) DEFAULT NULL,
    `status`        ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
    `approvedByID`  INT          DEFAULT NULL,
    `approvedAt`    DATETIME     DEFAULT NULL,
    `declineReason` VARCHAR(500) DEFAULT NULL,
    `notes`         TEXT         DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`bookingID`),
    KEY `idx_booking_resource_start` (`resourceID`, `startAt`),
    KEY `idx_booking_status` (`status`, `startAt`),
    KEY `idx_booking_booker` (`bookedByID`),
    CONSTRAINT `fk_booking_resource`     FOREIGN KEY (`resourceID`)   REFERENCES `tblResource`(`resourceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_booking_booker`       FOREIGN KEY (`bookedByID`)   REFERENCES `tblUsers`(`userID`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_booking_approver`     FOREIGN KEY (`approvedByID`) REFERENCES `tblUsers`(`userID`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('resources',             'resources/index.php',         1),
    ('resources/resource',    'resources/resource.php',      1),
    ('resources/book',        'resources/book.php',          1),
    ('resources/my-bookings', 'resources/my-bookings.php',   1),
    ('resources/approvals',   'resources/approvals.php',     1),
    ('resources/action',      'resources/action.php',        1),
    ('resources/manage',      'resources/manage.php',        1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'resources.enabled',         '0', '0', 0),
    (NULL, 'resources.default_buffer',  '15','15', 0),
    (NULL, 'resources.lookahead_days',  '90','90', 0),
    (NULL, 'resources.displayName',     'Resource Booking', 'Resource Booking', 0),
    (NULL, 'resources.displayIcon',     'fa-solid fa-building', 'fa-solid fa-building', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
