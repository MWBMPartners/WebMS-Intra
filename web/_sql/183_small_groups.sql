-- =============================================================================
-- Migration 183: Small Groups app (#150)
-- =============================================================================
-- New installable app: define groups/classes (Sabbath School classes, home
-- groups, Bible studies), assign members with roles (leader / co-leader /
-- member), optional self-service join requests, per-meeting roll with an
-- ADDITIVE headcount push into the existing Attendance app via the group's
-- linked tblAttendanceServiceTypes row. Four new tables, zero ALTERs to any
-- existing table (no guard blocks needed; CREATE TABLE IF NOT EXISTS is
-- standard MySQL 8). Location columns follow the #456 shared contract
-- (identical shapes to migration 180's tblVenues block) so the shared
-- location-input/location-display partials bind with no name overrides.
--
-- Editor note: never a literal double hyphen inside any SQL string in this
-- file (check_schema_seed_parity.py strips "--" as a line comment marker);
-- use an en dash instead.
--
-- Replay-proof: CREATE TABLE IF NOT EXISTS throughout; all seeds are
-- ON DUPLICATE KEY UPDATE / WHERE NOT EXISTS; the tblMigrations self-record
-- is idempotent. Running this file twice against an up-to-date schema is a
-- full no-op.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/150
-- =============================================================================

-- #############################################################################
-- SCHEMA
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 👥 tblSmallGroups — one row per group/class. serviceTypeID is the
-- attendance tie-in (#150: "tied to existing tblAttendanceServiceTypes");
-- location columns are the #456 standard block; locationVisibility defaults
-- 'members' because a group often meets in a member's HOME (default-safe,
-- no public tier exists).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSmallGroups` (
    `groupID`            INT           NOT NULL AUTO_INCREMENT,
    `siteID`             INT           NOT NULL DEFAULT 1 COMMENT 'FK to tblSites.siteID',
    `groupName`          VARCHAR(150)  NOT NULL,
    `groupSlug`          VARCHAR(100)  NOT NULL COMMENT 'URL-safe slug, unique per site (attendance typeSlug precedent)',
    `groupType`          VARCHAR(50)   NOT NULL DEFAULT 'small-group' COMMENT 'Free vocabulary: sabbath-school, small-group, bible-study, ministry-team, other (datalist in UI)',
    `description`        VARCHAR(1000) DEFAULT NULL,
    `serviceTypeID`      INT           DEFAULT NULL COMMENT 'FK to tblAttendanceServiceTypes — attendance tie-in (#150); NULL = no attendance link',
    `meetingDay`         TINYINT       DEFAULT NULL COMMENT 'ISO-8601: 1=Monday … 7=Sunday; NULL = varies',
    `meetingTime`        TIME          DEFAULT NULL,
    `meetingFrequency`   ENUM('weekly','fortnightly','monthly','adhoc') NOT NULL DEFAULT 'weekly',
    `meetingNotes`       VARCHAR(500)  DEFAULT NULL COMMENT 'Free text, e.g. term time only, 2nd Tuesday of the month',
    `addressLine1`       VARCHAR(255)  DEFAULT NULL,
    `addressLine2`       VARCHAR(255)  DEFAULT NULL,
    `city`               VARCHAR(100)  DEFAULT NULL,
    `region`             VARCHAR(100)  DEFAULT NULL COMMENT 'County / state / province',
    `postcode`           VARCHAR(20)   DEFAULT NULL,
    `countryCode`        CHAR(2)       NOT NULL DEFAULT 'GB' COMMENT 'ISO 3166-1 alpha-2',
    `latitude`           DECIMAL(10,7) DEFAULT NULL,
    `longitude`          DECIMAL(10,7) DEFAULT NULL,
    `what3words`         VARCHAR(100)  DEFAULT NULL COMMENT 'what3words address',
    `geocodedAt`         DATETIME      DEFAULT NULL COMMENT 'When lat/lng last set by the Geocoder (migration 180 pattern)',
    `geocodeSource`      VARCHAR(20)   DEFAULT NULL COMMENT 'google, nominatim, manual or w3w',
    `locationVisibility` ENUM('leaders','members','site') NOT NULL DEFAULT 'members' COMMENT 'Who may see the meeting address/pin — groups often meet in a member''s home; no public tier exists',
    `resourcesURL`       VARCHAR(500)  DEFAULT NULL COMMENT 'Lesson/study resources link (Documents category or external quarterly) — lightweight v1 of #150 lesson resources',
    `isOpenEnrolment`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = members may request to join from the groups directory',
    `capacity`           INT           DEFAULT NULL COMMENT 'Optional member cap; NULL = unlimited',
    `isActive`           TINYINT(1)    NOT NULL DEFAULT 1,
    `sortOrder`          INT           NOT NULL DEFAULT 0,
    `createdByID`        INT           DEFAULT NULL COMMENT 'FK to tblUsers',
    `createdAt`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`groupID`),
    UNIQUE KEY `uq_sg_slug_site` (`groupSlug`, `siteID`),
    KEY `idx_sg_site_active` (`siteID`, `isActive`),
    KEY `idx_sg_servicetype` (`serviceTypeID`),
    CONSTRAINT `fk_sg_site`        FOREIGN KEY (`siteID`)        REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sg_servicetype` FOREIGN KEY (`serviceTypeID`) REFERENCES `tblAttendanceServiceTypes`(`serviceTypeID`) ON DELETE SET NULL,
    CONSTRAINT `fk_sg_creator`     FOREIGN KEY (`createdByID`)   REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Small Groups — groups/classes register; groupID is the stable scope anchor for #304 messaging and #321 watch rooms (#150)';


-- -----------------------------------------------------------------------------
-- 🧑‍🤝‍🧑 tblSmallGroupMembers — membership join. ONE row per (group, user);
-- status='active' is the canonical membership predicate (#304/#321 must
-- never grant scope to pending/ended). siteID is denormalised from the
-- GROUP row by the PHP choke-point, never from POST (tblVenueRooms
-- precedent) — cross-site membership is structurally impossible because
-- the choke-point also requires an active tblUserSites row for that site.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSmallGroupMembers` (
    `membershipID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1 COMMENT 'Denormalised from tblSmallGroups.siteID by the save choke-point',
    `groupID`      INT          NOT NULL COMMENT 'FK to tblSmallGroups',
    `userID`       INT          NOT NULL COMMENT 'FK to tblUsers — portal users only in v1 (no external/minor rows)',
    `memberRole`   ENUM('leader','co-leader','member') NOT NULL DEFAULT 'member',
    `status`       ENUM('active','pending','ended') NOT NULL DEFAULT 'active' COMMENT 'pending = join request awaiting approval; ended = left/removed (row retained)',
    `joinedAt`     DATETIME     DEFAULT NULL COMMENT 'When the membership became active (NULL while pending)',
    `endedAt`      DATETIME     DEFAULT NULL,
    `requestNote`  VARCHAR(500) DEFAULT NULL COMMENT 'Optional message on a self-service join request',
    `addedByID`    INT          DEFAULT NULL COMMENT 'FK to tblUsers — who assigned; NULL for a self-service request',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`membershipID`),
    UNIQUE KEY `uq_sgm_group_user` (`groupID`, `userID`),
    KEY `idx_sgm_user_status` (`userID`, `status`),
    KEY `idx_sgm_site` (`siteID`),
    CONSTRAINT `fk_sgm_site`    FOREIGN KEY (`siteID`)    REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sgm_group`   FOREIGN KEY (`groupID`)   REFERENCES `tblSmallGroups`(`groupID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sgm_user`    FOREIGN KEY (`userID`)    REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sgm_adder`   FOREIGN KEY (`addedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Small Groups — member-to-group assignment with role + lifecycle status (#150)';


-- -----------------------------------------------------------------------------
-- 📆 tblSmallGroupMeetings — one roll per group per date (UNIQUE), with the
-- ADDITIVE bridge to the Attendance app: attendanceSessionID links the
-- pushed headcount session (SET NULL if that session is ever deleted).
-- visitorCount counts non-member guests WITHOUT naming them (privacy-safe).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSmallGroupMeetings` (
    `meetingID`           INT          NOT NULL AUTO_INCREMENT,
    `siteID`              INT          NOT NULL DEFAULT 1 COMMENT 'Denormalised from tblSmallGroups.siteID by the save choke-point',
    `groupID`             INT          NOT NULL COMMENT 'FK to tblSmallGroups',
    `meetingDate`         DATE         NOT NULL,
    `meetingTime`         TIME         DEFAULT NULL,
    `topic`               VARCHAR(255) DEFAULT NULL COMMENT 'Lesson/study title for this meeting',
    `notes`               TEXT         DEFAULT NULL,
    `visitorCount`        INT          NOT NULL DEFAULT 0 COMMENT 'Non-member guests, counted not named',
    `attendanceSessionID` INT          DEFAULT NULL COMMENT 'FK to tblAttendanceSessions — set when the headcount was pushed into the Attendance app',
    `recordedByID`        INT          DEFAULT NULL COMMENT 'FK to tblUsers',
    `createdAt`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`meetingID`),
    UNIQUE KEY `uq_sgmt_group_date` (`groupID`, `meetingDate`),
    KEY `idx_sgmt_site_date` (`siteID`, `meetingDate`),
    KEY `idx_sgmt_attsess` (`attendanceSessionID`),
    CONSTRAINT `fk_sgmt_site`     FOREIGN KEY (`siteID`)              REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sgmt_group`    FOREIGN KEY (`groupID`)             REFERENCES `tblSmallGroups`(`groupID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sgmt_attsess`  FOREIGN KEY (`attendanceSessionID`) REFERENCES `tblAttendanceSessions`(`sessionID`) ON DELETE SET NULL,
    CONSTRAINT `fk_sgmt_recorder` FOREIGN KEY (`recordedByID`)        REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Small Groups — meetings/rolls; additive link into the untouched Attendance app (#150)';


-- -----------------------------------------------------------------------------
-- ✅ tblSmallGroupMeetingAttendance — presence rows (a row = present;
-- absent = no row), mirroring tblEventAttendance (migration 116). userID is
-- nullable ONLY so GDPR anonymise and ON DELETE SET NULL work; the insert
-- path always writes a real userID. MySQL allows multiple NULLs under the
-- UNIQUE key, so anonymised rows keep the headcount intact.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSmallGroupMeetingAttendance` (
    `attendanceID` INT      NOT NULL AUTO_INCREMENT,
    `meetingID`    INT      NOT NULL COMMENT 'FK to tblSmallGroupMeetings',
    `userID`       INT      DEFAULT NULL COMMENT 'FK to tblUsers — NULL only after GDPR anonymise/user delete',
    `markedByID`   INT      DEFAULT NULL COMMENT 'FK to tblUsers — who took the roll',
    `markedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attendanceID`),
    UNIQUE KEY `uq_sgma_meeting_user` (`meetingID`, `userID`),
    KEY `idx_sgma_user` (`userID`),
    CONSTRAINT `fk_sgma_meeting` FOREIGN KEY (`meetingID`)  REFERENCES `tblSmallGroupMeetings`(`meetingID`) ON DELETE CASCADE,
    CONSTRAINT `fk_sgma_user`    FOREIGN KEY (`userID`)     REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_sgma_marker`  FOREIGN KEY (`markedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Small Groups — per-person meeting roll, presence-row model (#150)';


-- #############################################################################
-- ⚙️ SETTINGS SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- small-groups.enabled seeds '0' (opt-in marketplace app — venues/resources
-- precedent; the #255 lesson: this flag row MUST be seeded or registering
-- the app at /admin/apps silently 403s it forever). The hyphenated key
-- segment matches the route base so the nav/dashboard dynamic link resolves
-- to /small-groups (prayer-requests precedent). displayName/Icon/brandColor
-- feed nav.php + dashboard card fallbacks. NO api.* flags: v1 ships no
-- ApiRouter endpoints (nothing under _apps/small-groups/api/), and nothing
-- api-related is registered in tblRoutes either.
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'small-groups.enabled',           '0',                          '0',                          0),
    (NULL, 'small-groups.displayName',       'Small Groups',               'Small Groups',               0),
    (NULL, 'small-groups.displayIcon',       'fa-solid fa-people-group',   'fa-solid fa-people-group',   0),
    (NULL, 'small-groups.brandColor',        '#0f766e',                    '#0f766e',                    0),
    (NULL, 'small-groups.push_attendance',   '1',                          '1',                          0),
    (NULL, 'small-groups.open_enrolment',    '1',                          '1',                          0),
    (NULL, 'small-groups.directory_visible', '1',                          '1',                          0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🏷️ ROLE SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Small Groups Coordinator — a non-admin who manages ALL groups at the site
-- (venue_manager / asset_manager WHERE NOT EXISTS idiom; tblRoles has no
-- meaningful column for ON DUPLICATE KEY UPDATE to touch).
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'groups_coordinator', 'Small Groups Coordinator'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'groups_coordinator');


-- #############################################################################
-- 🗺️ ROUTES SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 13 rows. Every targetFile ships as a REAL handler in the same PR (no
-- stubs). help/small-groups seeds isProtected=0 (help/venues precedent).
-- NOTHING api-related is registered here: Router never consults tblRoutes
-- for api/* paths (the #372 dead-route lesson) and v1 ships no API.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('small-groups',              'small-groups/index.php',        1),
    ('small-groups/group',        'small-groups/group.php',        1),
    ('small-groups/mine',         'small-groups/mine.php',         1),
    ('small-groups/manage',       'small-groups/manage.php',       1),
    ('small-groups/save',         'small-groups/save.php',         1),
    ('small-groups/members',      'small-groups/members.php',      1),
    ('small-groups/member-save',  'small-groups/member-save.php',  1),
    ('small-groups/join',         'small-groups/join.php',         1),
    ('small-groups/meeting',      'small-groups/meeting.php',      1),
    ('small-groups/meeting-save', 'small-groups/meeting-save.php', 1),
    ('small-groups/report',       'small-groups/report.php',       1),
    ('small-groups/export',       'small-groups/export.php',       1),
    ('help/small-groups',         'help/small-groups.php',         0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 SELF-RECORD
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('183_small_groups.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
