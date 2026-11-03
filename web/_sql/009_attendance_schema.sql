-- =============================================================================
-- Migration: 009_attendance_schema.sql
-- Purpose:   Creates tables for the Attendance Tracker app.
--            Tracks headcounts (not individual people) for events and services.
--            Includes service types with SDA church defaults, attendance sessions,
--            and count breakdowns by age group.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 🏷️ tblAttendanceServiceTypes — types of services/events to track attendance for
-- Seeded with SDA church defaults; admins can add more via UI.
-- Supports nested sub-types (e.g. Sabbath School > Children > Kindergarten).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceServiceTypes` (
    `serviceTypeID` INT          NOT NULL AUTO_INCREMENT,
    `parentID`      INT          DEFAULT NULL COMMENT 'FK to self for sub-types (NULL = top-level)',
    `typeName`      VARCHAR(150) NOT NULL,
    `typeSlug`      VARCHAR(100) NOT NULL COMMENT 'URL-safe slug for routing/API',
    `description`   VARCHAR(500) DEFAULT NULL COMMENT 'Optional description shown in UI',
    `sortOrder`     INT          NOT NULL DEFAULT 0,
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`serviceTypeID`),
    UNIQUE KEY `uq_att_type_slug` (`typeSlug`),
    KEY `idx_att_type_parent` (`parentID`),
    CONSTRAINT `fk_att_type_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblAttendanceServiceTypes` (`serviceTypeID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Service/event types for attendance tracking, with nested sub-types.';


-- -----------------------------------------------------------------------------
-- 📋 tblAttendanceSessions — a single attendance-recording session
-- Links to an event (optional) and a service type. Each row represents one
-- occasion where headcounts were recorded (e.g. "Sabbath School on 2026-03-07").
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceSessions` (
    `sessionID`     INT          NOT NULL AUTO_INCREMENT,
    `serviceTypeID` INT          NOT NULL COMMENT 'FK → tblAttendanceServiceTypes',
    `eventID`       INT          DEFAULT NULL COMMENT 'FK → tblEvents (NULL if standalone)',
    `sessionDate`   DATE         NOT NULL COMMENT 'Date of the service/event',
    `sessionTime`   TIME         DEFAULT NULL COMMENT 'Start time (optional)',
    `notes`         TEXT         DEFAULT NULL COMMENT 'Optional notes about this session',
    `isDeleted`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
    `createdByID`   INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `updatedByID`   INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`sessionID`),
    KEY `idx_att_sess_type`   (`serviceTypeID`),
    KEY `idx_att_sess_event`  (`eventID`),
    KEY `idx_att_sess_date`   (`sessionDate`),
    KEY `idx_att_sess_del`    (`isDeleted`),
    CONSTRAINT `fk_att_sess_type` FOREIGN KEY (`serviceTypeID`)
        REFERENCES `tblAttendanceServiceTypes` (`serviceTypeID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_att_sess_event` FOREIGN KEY (`eventID`)
        REFERENCES `tblEvents` (`eventID`) ON DELETE SET NULL,
    CONSTRAINT `fk_att_sess_creator` FOREIGN KEY (`createdByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_att_sess_updater` FOREIGN KEY (`updatedByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual attendance sessions — one row per service/event occasion.';


-- -----------------------------------------------------------------------------
-- 🔢 tblAttendanceCounts — headcount breakdowns within a session
-- Each session can have multiple count rows for different age groups/categories.
-- This allows recording "Adults: 45, Children: 12, Visitors: 5" etc.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceCounts` (
    `countID`     INT          NOT NULL AUTO_INCREMENT,
    `sessionID`   INT          NOT NULL COMMENT 'FK → tblAttendanceSessions',
    `groupLabel`  VARCHAR(100) NOT NULL COMMENT 'Age group or category label (e.g. Adults, Children, Visitors)',
    `headcount`   INT          NOT NULL DEFAULT 0 COMMENT 'Number of people counted',
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`countID`),
    KEY `idx_att_count_session` (`sessionID`),
    CONSTRAINT `fk_att_count_session` FOREIGN KEY (`sessionID`)
        REFERENCES `tblAttendanceSessions` (`sessionID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Headcount breakdowns per session — multiple groups per session.';


-- =============================================================================
-- 🌱 Seed default service types (SDA church context)
-- =============================================================================

-- Top-level service types
INSERT INTO `tblAttendanceServiceTypes` (`typeName`, `typeSlug`, `description`, `sortOrder`) VALUES
    ('Sabbath School',    'sabbath-school',    'Weekly Sabbath School classes',                1),
    ('Family Worship',    'family-worship',    'Main family worship / divine service',         2),
    ('Afternoon Service', 'afternoon-service', 'Afternoon worship or fellowship',              3),
    ('Prayer Meeting',    'prayer-meeting',    'Midweek prayer meeting',                       4),
    ('Bible Study',       'bible-study',       'Bible study group or class',                   5),
    ('Youth Programme',   'youth-programme',   'Youth ministry events',                        6),
    ('Special Event',     'special-event',     'Conferences, retreats, and special occasions', 7),
    ('Other',             'other',             'Any other service or event type',              99)
ON DUPLICATE KEY UPDATE `typeName` = VALUES(`typeName`);

-- Sub-types for Sabbath School (children's divisions)
INSERT INTO `tblAttendanceServiceTypes` (`parentID`, `typeName`, `typeSlug`, `description`, `sortOrder`) VALUES
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Babies',                   'ss-babies',           'Sabbath School — Babies division',                     1),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Beginner',                 'ss-beginner',         'Sabbath School — Beginner division (ages 4-5)',        2),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Kindergarten',             'ss-kindergarten',     'Sabbath School — Kindergarten division (ages 6-7)',    3),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Primary',                  'ss-primary',          'Sabbath School — Primary division (ages 8-9)',         4),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'PowerPoints',              'ss-powerpoints',      'Sabbath School — PowerPoints division (ages 10-12)',   5),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'RealTime Faith',           'ss-realtime-faith',   'Sabbath School — RealTime Faith (ages 13-14)',        6),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Cornerstone Connections',  'ss-cornerstone',      'Sabbath School — Cornerstone Connections (ages 15-18)', 7),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Youth / Young Adult',      'ss-youth',            'Sabbath School — Youth and Young Adult class',        8),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Adult',                    'ss-adult',            'Sabbath School — Adult class',                        9),
    ((SELECT t.serviceTypeID FROM (SELECT serviceTypeID FROM `tblAttendanceServiceTypes` WHERE typeSlug = 'sabbath-school') t),
        'Baptismal Class',          'ss-baptismal',        'Sabbath School — Baptismal / Bible study class',      10)
ON DUPLICATE KEY UPDATE `typeName` = VALUES(`typeName`);


-- =============================================================================
-- 📌 Attendance routes
-- =============================================================================
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance', 'attendance/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/record', 'attendance/record.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/record/save', 'attendance/record/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/record/delete', 'attendance/record/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/manage', 'attendance/manage/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/manage/save', 'attendance/manage/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/report', 'attendance/report.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- =============================================================================
-- ⚙️ Enable attendance app in settings + add display metadata
-- =============================================================================
-- 🛑 THIS USED TO BE A PLAIN UPDATE, AND A REPLAY SWITCHED THE APP BACK ON
--    OVER AN ADMINISTRATOR'S CHOICE (#481, 3 November 2026). It read:
--
--      UPDATE `tblSettings` SET `settingValue` = 'true'
--          WHERE `settingKey` = 'attendance.enabled';
--
--    The installation wizard replays every numbered migration on top of an
--    existing database, so an administrator who had deliberately switched
--    Attendance off found it on again after an upgrade retry, with nothing
--    saying why. There was no condition on the statement at all, so no
--    uniqueness rule was ever standing between it and the row.
--
--    It is now the same guarded seed idiom as the two settings directly below
--    it — which were written correctly in this very file, three lines apart —
--    and as #466 used for migrations 012 and 021. Nothing is lost: 'true' is
--    what `full_schema.sql` seeds and what migration 225 corrected the stored
--    default to. `siteID` is not named because that column does not exist on
--    `tblSettings` until migration 015. Nothing can put back a value an
--    earlier replay has already overwritten; see migration 008's note.
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.displayName', 'Attendance', 0, 'Attendance')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.displayIcon', 'fa-solid fa-clipboard-list', 0, 'fa-solid fa-clipboard-list')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.brandColor', '#6f42c1', 0, '#6f42c1')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
