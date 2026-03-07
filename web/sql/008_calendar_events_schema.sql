-- =============================================================================
-- Migration: 008_calendar_events_schema.sql
-- Purpose:   Creates all tables for the Calendar / Events / Preaching Plan app.
--            Covers events, series, types, categories, themes, locations,
--            people/roles, links, materials, and recurrence rules.
-- Author:    MWBM Partners Ltd (t/a MWservices)
-- Copyright: 2025-present MWBM Partners Ltd
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 📂 tblEventCategories — top-level and sub-categories for events
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventCategories` (
    `categoryID`   INT          NOT NULL AUTO_INCREMENT,
    `parentID`     INT          DEFAULT NULL COMMENT 'NULL = top-level; FK to self for sub-categories',
    `categoryName` VARCHAR(150) NOT NULL,
    `categorySlug` VARCHAR(100) NOT NULL COMMENT 'URL-safe slug',
    `sortOrder`    INT          NOT NULL DEFAULT 0,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`categoryID`),
    UNIQUE KEY `uq_category_slug` (`categorySlug`),
    KEY `idx_cat_parent` (`parentID`),
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblEventCategories` (`categoryID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event categories with nested sub-categories.';


-- -----------------------------------------------------------------------------
-- 🏷️ tblEventTypes — event types with optional sub-types
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventTypes` (
    `typeID`   INT          NOT NULL AUTO_INCREMENT,
    `parentID` INT          DEFAULT NULL COMMENT 'NULL = top-level; FK to self for sub-types',
    `typeName` VARCHAR(150) NOT NULL,
    `typeSlug` VARCHAR(100) NOT NULL,
    `sortOrder` INT         NOT NULL DEFAULT 0,
    `isActive` TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`typeID`),
    UNIQUE KEY `uq_type_slug` (`typeSlug`),
    KEY `idx_type_parent` (`parentID`),
    CONSTRAINT `fk_type_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblEventTypes` (`typeID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event types with nested sub-types (e.g. Worship Service > Sabbath School).';


-- -----------------------------------------------------------------------------
-- 🎨 tblEventThemes — reusable themes/tags for events
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventThemes` (
    `themeID`   INT          NOT NULL AUTO_INCREMENT,
    `themeName` VARCHAR(150) NOT NULL,
    `themeSlug` VARCHAR(100) NOT NULL,
    `color`     VARCHAR(7)   DEFAULT NULL COMMENT 'Hex color code for calendar display',
    `isActive`  TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`themeID`),
    UNIQUE KEY `uq_theme_slug` (`themeSlug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Reusable themes/tags that can be assigned to events.';


-- -----------------------------------------------------------------------------
-- 🔄 tblEventSeries — named series of events (nestable)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventSeries` (
    `seriesID`    INT          NOT NULL AUTO_INCREMENT,
    `parentID`    INT          DEFAULT NULL COMMENT 'FK to self for nested series',
    `seriesName`  VARCHAR(255) NOT NULL,
    `seriesSlug`  VARCHAR(150) NOT NULL,
    `description` TEXT         DEFAULT NULL,
    `heroImage`   VARCHAR(500) DEFAULT NULL COMMENT 'Path in _uploads/calendar/',
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`seriesID`),
    UNIQUE KEY `uq_series_slug` (`seriesSlug`),
    KEY `idx_series_parent` (`parentID`),
    CONSTRAINT `fk_series_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblEventSeries` (`seriesID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Named event series (can be nested). Events link to a series.';


-- -----------------------------------------------------------------------------
-- 🔁 tblRecurrenceRules — recurrence patterns for event series
-- Weekly, Monthly, Quarterly, Yearly with flexible nth-day rules.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblRecurrenceRules` (
    `ruleID`       INT          NOT NULL AUTO_INCREMENT,
    `seriesID`     INT          NOT NULL COMMENT 'FK → tblEventSeries',
    `frequency`    ENUM('weekly','fortnightly','monthly','quarterly','yearly','custom')
                   NOT NULL DEFAULT 'weekly',
    `intervalVal`  INT          NOT NULL DEFAULT 1 COMMENT 'e.g. every 2 weeks',
    `dayOfWeek`    VARCHAR(20)  DEFAULT NULL COMMENT 'CSV of days: 0=Sun..6=Sat (for weekly/fortnightly)',
    `dayOfMonth`   INT          DEFAULT NULL COMMENT 'Day of month (1-31) for monthly/yearly',
    `weekOfMonth`  INT          DEFAULT NULL COMMENT 'Nth week (1-5, -1=last) for monthly patterns',
    `monthOfYear`  INT          DEFAULT NULL COMMENT 'Month (1-12) for yearly patterns',
    `startDate`    DATE         NOT NULL COMMENT 'When recurrence begins',
    `endDate`      DATE         DEFAULT NULL COMMENT 'When recurrence ends (NULL=no end)',
    `maxOccurrences` INT        DEFAULT NULL COMMENT 'Max number of occurrences (NULL=unlimited)',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ruleID`),
    KEY `idx_recur_series` (`seriesID`),
    CONSTRAINT `fk_recur_series` FOREIGN KEY (`seriesID`)
        REFERENCES `tblEventSeries` (`seriesID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Recurrence rules for event series — generates individual event dates.';


-- -----------------------------------------------------------------------------
-- 📅 tblEvents — individual event instances
-- Each row is a single scheduled occurrence (standalone or part of a series).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEvents` (
    `eventID`       INT          NOT NULL AUTO_INCREMENT,
    `seriesID`      INT          DEFAULT NULL COMMENT 'FK → tblEventSeries (NULL if standalone)',
    `categoryID`    INT          DEFAULT NULL COMMENT 'FK → tblEventCategories',
    `typeID`        INT          DEFAULT NULL COMMENT 'FK → tblEventTypes',
    `eventName`     VARCHAR(255) NOT NULL,
    `eventSlug`     VARCHAR(200) NOT NULL COMMENT 'URL-safe slug for direct linking',
    `description`   TEXT         DEFAULT NULL,
    `startDateTime` DATETIME     NOT NULL COMMENT 'Event start (stored in UTC)',
    `endDateTime`   DATETIME     DEFAULT NULL COMMENT 'Event end (stored in UTC)',
    `timezone`      VARCHAR(50)  NOT NULL DEFAULT 'Europe/London',
    `isAllDay`      TINYINT(1)   NOT NULL DEFAULT 0,

    -- 📍 Location fields (can override series location)
    `locationName`    VARCHAR(255) DEFAULT NULL,
    `locationAddress` TEXT         DEFAULT NULL,
    `locationWebURL`  VARCHAR(500) DEFAULT NULL,
    `locationGeoLat`  DECIMAL(10,7) DEFAULT NULL,
    `locationGeoLng`  DECIMAL(10,7) DEFAULT NULL,
    `locationW3W`     VARCHAR(100)  DEFAULT NULL COMMENT 'what3words address',
    `locationPhone`   VARCHAR(50)   DEFAULT NULL,
    `locationEmail`   VARCHAR(255)  DEFAULT NULL,

    -- 🏢 Organisation fields
    `hostOrgName`    VARCHAR(255)  DEFAULT NULL COMMENT 'Organisation hosting the event',
    `partnerOrgs`    TEXT          DEFAULT NULL COMMENT 'JSON array of partner org names',

    -- 🖼️ Images (paths relative to _uploads/calendar/)
    `heroImage`      VARCHAR(500)  DEFAULT NULL,
    `posterImage`    VARCHAR(500)  DEFAULT NULL,
    `profileImage`   VARCHAR(500)  DEFAULT NULL,

    -- 📊 Status and visibility
    `status`       ENUM('draft','published','cancelled','postponed') NOT NULL DEFAULT 'draft',
    `isPublic`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Visible on public calendar',
    `isFeatured`   TINYINT(1)   NOT NULL DEFAULT 0,
    `isDeleted`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',

    -- 🔢 Metadata
    `createdByID`  INT           DEFAULT NULL COMMENT 'FK → tblUsers',
    `updatedByID`  INT           DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`eventID`),
    UNIQUE KEY `uq_event_slug` (`eventSlug`),
    KEY `idx_event_series`   (`seriesID`),
    KEY `idx_event_category` (`categoryID`),
    KEY `idx_event_type`     (`typeID`),
    KEY `idx_event_start`    (`startDateTime`),
    KEY `idx_event_status`   (`status`),
    KEY `idx_event_deleted`  (`isDeleted`),
    KEY `idx_event_public`   (`isPublic`, `status`, `isDeleted`),
    CONSTRAINT `fk_event_series`   FOREIGN KEY (`seriesID`)   REFERENCES `tblEventSeries` (`seriesID`)     ON DELETE SET NULL,
    CONSTRAINT `fk_event_category` FOREIGN KEY (`categoryID`) REFERENCES `tblEventCategories` (`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_event_type`     FOREIGN KEY (`typeID`)     REFERENCES `tblEventTypes` (`typeID`)       ON DELETE SET NULL,
    CONSTRAINT `fk_event_creator`  FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`)           ON DELETE SET NULL,
    CONSTRAINT `fk_event_updater`  FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers` (`userID`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual event instances — standalone or part of a series.';


-- -----------------------------------------------------------------------------
-- 🏷️ tblEventThemeMap — many-to-many: events ↔ themes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventThemeMap` (
    `mapID`    INT NOT NULL AUTO_INCREMENT,
    `eventID`  INT NOT NULL,
    `themeID`  INT NOT NULL,
    PRIMARY KEY (`mapID`),
    UNIQUE KEY `uq_event_theme` (`eventID`, `themeID`),
    CONSTRAINT `fk_etheme_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_etheme_theme` FOREIGN KEY (`themeID`) REFERENCES `tblEventThemes` (`themeID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Many-to-many mapping of events to themes.';


-- -----------------------------------------------------------------------------
-- 👤 tblEventPeople — people associated with an event (host, speaker, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventPeople` (
    `eventPersonID` INT          NOT NULL AUTO_INCREMENT,
    `eventID`       INT          NOT NULL,
    `userID`        INT          DEFAULT NULL COMMENT 'FK → tblUsers (NULL if external person)',
    `externalName`  VARCHAR(255) DEFAULT NULL COMMENT 'Name if person is not a portal user',
    `role`          VARCHAR(100) NOT NULL DEFAULT 'host' COMMENT 'host, speaker, musician, organiser, etc.',
    `isPrimary`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Primary person for this role',
    `sortOrder`     INT          NOT NULL DEFAULT 0,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventPersonID`),
    KEY `idx_epeople_event` (`eventID`),
    KEY `idx_epeople_user`  (`userID`),
    CONSTRAINT `fk_epeople_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_epeople_user`  FOREIGN KEY (`userID`)  REFERENCES `tblUsers` (`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='People assigned to events with roles (host, speaker, musician, etc.).';


-- -----------------------------------------------------------------------------
-- 🔗 tblEventLinks — URLs associated with an event
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventLinks` (
    `linkID`    INT          NOT NULL AUTO_INCREMENT,
    `eventID`   INT          NOT NULL,
    `linkType`  VARCHAR(50)  NOT NULL DEFAULT 'website' COMMENT 'website, rsvp, social, booking, livestream, etc.',
    `linkURL`   VARCHAR(2048) NOT NULL,
    `linkLabel` VARCHAR(255)  DEFAULT NULL COMMENT 'Display label for the link',
    `sortOrder` INT          NOT NULL DEFAULT 0,
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`linkID`),
    KEY `idx_elinks_event` (`eventID`),
    CONSTRAINT `fk_elinks_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='URLs related to an event — social media, booking pages, livestream links, etc.';


-- -----------------------------------------------------------------------------
-- 📎 tblEventMaterials — downloadable documents for an event
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventMaterials` (
    `materialID`   INT          NOT NULL AUTO_INCREMENT,
    `eventID`      INT          NOT NULL,
    `materialType` VARCHAR(50)  NOT NULL DEFAULT 'document' COMMENT 'document, notes, slides, audio, video',
    `fileName`     VARCHAR(255) NOT NULL COMMENT 'Original filename',
    `filePath`     VARCHAR(500) NOT NULL COMMENT 'Path relative to _uploads/calendar/materials/',
    `fileSize`     INT          DEFAULT NULL COMMENT 'File size in bytes',
    `mimeType`     VARCHAR(100) DEFAULT NULL,
    `sortOrder`    INT          NOT NULL DEFAULT 0,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`materialID`),
    KEY `idx_ematerials_event` (`eventID`),
    CONSTRAINT `fk_ematerials_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Downloadable materials/documents attached to events.';


-- =============================================================================
-- 🌱 Seed default event types (for church context)
-- =============================================================================
INSERT INTO `tblEventTypes` (`typeName`, `typeSlug`, `sortOrder`) VALUES
    ('Worship Service', 'worship-service', 1),
    ('Prayer Meeting', 'prayer-meeting', 2),
    ('Bible Study', 'bible-study', 3),
    ('Social Event', 'social-event', 4),
    ('Community Outreach', 'community-outreach', 5),
    ('Conference', 'conference', 6),
    ('Workshop', 'workshop', 7),
    ('Meeting', 'meeting', 8),
    ('Other', 'other', 99)
ON DUPLICATE KEY UPDATE `typeName` = VALUES(`typeName`);

-- Sub-types for Worship Service
INSERT INTO `tblEventTypes` (`parentID`, `typeName`, `typeSlug`, `sortOrder`) VALUES
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Sabbath School', 'sabbath-school', 1),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Divine Service', 'divine-service', 2),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Family Worship', 'family-worship', 3),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Afternoon Service', 'afternoon-service', 4),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Vespers', 'vespers', 5)
ON DUPLICATE KEY UPDATE `typeName` = VALUES(`typeName`);

-- Default categories
INSERT INTO `tblEventCategories` (`categoryName`, `categorySlug`, `sortOrder`) VALUES
    ('Church Service', 'church-service', 1),
    ('Community', 'community', 2),
    ('Youth', 'youth', 3),
    ('Children', 'children', 4),
    ('Music', 'music', 5),
    ('Education', 'education', 6),
    ('Administration', 'administration', 7),
    ('Special Event', 'special-event', 8)
ON DUPLICATE KEY UPDATE `categoryName` = VALUES(`categoryName`);


-- =============================================================================
-- 📌 Calendar routes
-- =============================================================================
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar', 'calendar/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/event', 'calendar/event.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage', 'calendar/manage/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/save', 'calendar/manage/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/delete', 'calendar/manage/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/series', 'calendar/manage/series.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/types', 'calendar/manage/types.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/export', 'calendar/export.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- =============================================================================
-- ⚙️ Enable calendar app in settings
-- =============================================================================
UPDATE `tblSettings` SET `settingValue` = 'true' WHERE `settingKey` = 'calendar.enabled';
UPDATE `tblSettings` SET `settingValue` = 'fa-solid fa-calendar-days' WHERE `settingKey` = 'calendar.displayIcon';
