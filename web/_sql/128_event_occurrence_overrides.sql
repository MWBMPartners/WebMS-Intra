-- =============================================================================
-- Migration 128: Per-occurrence overrides for recurring events (#333)
-- =============================================================================
-- For a recurring event series, an admin can override a single occurrence:
--   • Cancel just that one date
--   • Rename / re-time / re-locate just that one date
--   • Add notes shown only for that occurrence
--
-- The render layer at /calendar reads tblEventOccurrenceOverrides whenever
-- it expands a recurring series and applies the matching row per date.
-- v1 ships the data model + admin UI; integrating into the calendar grid
-- expansion is v1.1.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/333
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblEventOccurrenceOverrides` (
    `overrideID`        INT          NOT NULL AUTO_INCREMENT,
    `eventID`           INT          NOT NULL,
    `occurrenceDate`    DATE         NOT NULL COMMENT 'Original scheduled date being overridden',
    `isCancelled`       TINYINT(1)   NOT NULL DEFAULT 0,
    `overrideName`      VARCHAR(255) DEFAULT NULL COMMENT 'NULL = inherit series name',
    `overrideStartTime` TIME         DEFAULT NULL COMMENT 'NULL = inherit series time',
    `overrideEndTime`   TIME         DEFAULT NULL,
    `overrideLocation`  VARCHAR(255) DEFAULT NULL,
    `notes`             VARCHAR(1000) DEFAULT NULL,
    `createdByID`       INT          DEFAULT NULL,
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`overrideID`),
    UNIQUE KEY `uq_override_event_date` (`eventID`, `occurrenceDate`),
    CONSTRAINT `fk_override_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_override_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event/overrides',      'calendar/event-overrides.php',      1),
    ('calendar/event/overrides/save', 'calendar/event-overrides-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
