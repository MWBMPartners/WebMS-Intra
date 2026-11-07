-- =============================================================================
-- Migration 255: outside services — what a member's browser may tell another
-- company about them (#778; the owner's decisions D-058, D-072, D-073)
-- =============================================================================
-- Three settings rows, one new table, four addresses. Nothing dropped, nothing
-- existing changed.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS IS FOR, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- Until now every page's top bar ASKED for the member's picture from Gravatar,
-- at an address built from a scramble of their e-mail address. That is not
-- anonymous: Gravatar's business is matching the scramble to a person, so an
-- outside company could learn who was looking, from where, on what and when.
-- Be exact about how far it got: on every page that draws the top bar the
-- portal's own page rule said `img-src 'self' data:`, so the BROWSER REFUSED
-- the picture and the request never left the machine — except on
-- `/noticeboard`, whose rule is `img-src https:`, where it really was sent. A
-- photo left by a Google sign-in was in the same position, and the member
-- directory really did draw a map of a member's home from OpenStreetMap. None of
-- the three could be switched off.
--
-- The owner's rule (D-058): the answer is the STRICTEST of the installation,
-- EVERY organisation the member belongs to, and the member's own choice. An
-- organisation may tighten the installation, never loosen it; a member may
-- tighten again, never loosen. For a map of somebody else's home, BOTH the
-- member shown and the person looking must allow it (D-072).
-- `Portal\Core\OutsideServices` is the one place it is worked out.
--
-- -----------------------------------------------------------------------------
-- WHAT A MEMBER WILL NOTICE AFTER THIS UPGRADE
-- -----------------------------------------------------------------------------
-- All three ship 'off' — allowed, but off until a member switches it on for
-- themselves. So after this upgrade a member with no photo stored on the
-- portal's own server sees a plain silhouette where a Gravatar or Google
-- picture used to be asked for (D-073 accepts that; an upload is #798). The
-- browser refused that picture everywhere but `/noticeboard` anyway, so the
-- visible change is a silhouette instead of a broken picture. A directory
-- profile shows a member's location as text, without a map, until that member
-- has switched maps on. Maps can never be made on by default: for
-- `privacy.outside.memberMap` a level is `no` or `off` only (D-072).
--
-- Safe to run twice: each insert restates rather than overwrites, and the table
-- is created only if missing. InnoDB, and no MariaDB-only syntax.
-- =============================================================================

-- #############################################################################
-- ⚙️ A. The installation's three values
-- #############################################################################
-- `settingValue` is restated as itself on a duplicate, NOT forced to 'off': an
-- administrator who has already chosen must keep that choice through a replay.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'privacy.outside.gravatar',      'off', 'off', 0),
    (NULL, 'privacy.outside.providerPhoto', 'off', 'off', 0),
    (NULL, 'privacy.outside.memberMap',     'off', 'off', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 👤 B. A member's own choices
-- #############################################################################

CREATE TABLE IF NOT EXISTS `tblUserOutsideServicePref` (
    `userID`     INT                NOT NULL COMMENT 'The member whose choice this is',
    `serviceKey` VARCHAR(40)        NOT NULL COMMENT 'Which outside service: a key of OutsideServices::SERVICES (gravatar, providerPhoto, memberMap)',
    `choice`     ENUM('on', 'off')  NOT NULL COMMENT 'The member''s own choice. It can only tighten what their organisations allow: on is refused where they do not allow the service at all, and ignored by the reader if they stop allowing it later',
    `updatedAt`  DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'A moment, on the database server''s clock: when the choice was last changed',
    PRIMARY KEY (`userID`, `serviceKey`),
    CONSTRAINT `fk_uosp_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='A member''s own choice about each outside service (#778). Written only by Portal\\Core\\OutsideServices; also read by the member''s own data download (auth/account/data-export.php) and deleted by an erasure (GdprEraser).';

-- #############################################################################
-- 🗺️ C. The two pages
-- #############################################################################

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/outside-services',      'admin/settings/outside-services/index.php', 1),
    ('admin/settings/outside-services/save', 'admin/settings/outside-services/save.php',  1),
    ('account/outside-services',             'auth/account/outside-services.php',         1),
    ('account/outside-services-save',        'auth/account/outside-services-save.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋 D. Self-record
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('255_outside_services.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
