-- =============================================================================
-- Migration 261: the noticeboard's foyer slideshow (#493, package P2b; D-086)
-- =============================================================================
-- A screen in the building plays the organisation's posters on a loop. It is
-- opened by a SECRET LINK, with no sign-in (#493: "an auto-playing slideshow
-- for a foyer display, reached by a secret link or QR code with no login").
--
--   tblNoticeboardFoyer            one secret link per organisation; replacing
--                                  it stops the old address at once
--   noticeboard.foyer.permitted    INSTALLATION ONLY: what the installation
--                                  permits — 'public' (public posters only)
--                                  or 'either'
--   noticeboard.foyer.shows        what the slideshow shows — 'public' or
--                                  'all'; an organisation's own row chooses
--                                  within what is permitted
--   noticeboard/foyer              the slideshow (no sign-in; the link names
--                                  the organisation)
--   noticeboard/foyer/setup        the page that shows and replaces the link
--                                  and sets the choice
--
-- WHY TIERED (D-086, the owner, 7 November 2026): the installation's global
-- administrator sets what is permitted and an organisation's global
-- administrator chooses within it. "Public posters only" is the stricter
-- choice and the default, because anyone walking in can read the screen.
-- Public posters are decided ONLY by PublicWebsite::posterWhere().
--
-- NO PERSONAL DETAILS: the table holds an organisation, a random token and a
-- time; who made or replaced a link is in the activity log.
--
-- SAFE TO RUN TWICE: CREATE TABLE IF NOT EXISTS; the settings' ON DUPLICATE KEY
-- UPDATE touches defaultValue only, so a choice somebody made is never changed;
-- the routes rewrite the same rows.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/493
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblNoticeboardFoyer` (
    `siteID`    INT       NOT NULL COMMENT 'The ONE organisation this foyer link shows (#493 P2b). One link per organisation.',
    `token`     CHAR(48)  NOT NULL COMMENT 'The secret in the foyer slideshow''s address: 48 lower-case hex characters from random_bytes(24). Replacing it stops the old address at once. Checked only by Portal\\Core\\NoticeboardFoyer.',
    `createdAt` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'A moment on the database server''s clock: when this link was made or last replaced. Nothing compares it; who did it is in the activity log.',
    PRIMARY KEY (`siteID`),
    UNIQUE KEY `uq_foyer_token` (`token`),
    CONSTRAINT `fk_foyer_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The noticeboard foyer slideshow''s secret link, one per organisation (#493 P2b)';

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'noticeboard.foyer.permitted', 'public', 'public', 0),
    (NULL, 'noticeboard.foyer.shows',     'public', 'public', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('noticeboard/foyer',       'noticeboard/foyer.php',       0),
    ('noticeboard/foyer/setup', 'noticeboard/foyer-setup.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('261_noticeboard_foyer_slideshow.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
