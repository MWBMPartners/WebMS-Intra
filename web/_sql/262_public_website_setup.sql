-- =============================================================================
-- Migration 262: Admin → Public website, and handing the job to named people
--                (#493, package P3; D-085, D-093, D-094, D-095)
-- =============================================================================
-- What this adds:
--
--   tblPublicHosts             the OPTIONAL public address for each
--                              organisation's public pages (D-095), for example
--                              events.ourchurch.org. At most one per
--                              organisation (the primary key) and NO TWO
--                              ORGANISATIONS MAY SHARE ONE (the unique key):
--                              two sharing one would show one organisation's
--                              content to the other's visitors. Package P3
--                              stores and checks it; package P4 makes the
--                              public door answer on it.
--   public.frame.permitted     INSTALLATION ONLY, 'yes' or 'no' (D-094):
--                              whether any outside website may show any
--                              organisation's public pages in a frame. Ships
--                              'yes'; 'no' wins over every organisation's list.
--   website_setup              a standard role, "Website Set-up", for every
--                              organisation — the role the new permission
--                              `website.setup` (D-085) ships pointing at.
--                              Granted to NOBODY: out of the box only a global
--                              administrator sets up the public website, and
--                              only a global administrator may give this role
--                              to anybody (D-093).
--   three addresses            the page, its save handler and its "Check my
--                              set-up" button, all needing a sign-in.
--
-- The two per-organisation settings (`public.website.address`, the main
-- website, and `public.frame.origins`, the websites allowed to frame the
-- pages) are NOT seeded: an organisation that has said nothing has no row,
-- and "no row" means "none". An installation-wide row of either would be
-- ignored, so none is written.
--
-- NO PERSONAL DETAILS: tblPublicHosts holds an organisation, a host name and a
-- moment. Who changed it is in the activity log.
--
-- SAFE TO RUN TWICE: CREATE TABLE IF NOT EXISTS; the setting's ON DUPLICATE
-- KEY UPDATE touches `defaultValue` only, so a choice somebody made is never
-- changed; the role is added only where it is missing; the routes rewrite the
-- same rows.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/493
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblPublicHosts` (
    `siteID`       INT          NOT NULL COMMENT 'The ONE organisation this public address belongs to (#493 P3, D-095). One address per organisation.',
    `hostName`     VARCHAR(253) NOT NULL COMMENT 'The public address for the organisation''s public pages: a host name only, lower case, letters a to z, digits, hyphens and dots (a name with other letters in its xn-- form). Checked by Portal\\Core\\PublicWebsiteSetup::checkPublicHost(), and again whenever it is read.',
    `changedAtUtc` DATETIME     NOT NULL COMMENT 'A moment in UTC, written with UTC_TIMESTAMP(): when this address was last set. Nothing compares it; who set it is in the activity log.',
    PRIMARY KEY (`siteID`),
    UNIQUE KEY `uq_public_host_name` (`hostName`),
    CONSTRAINT `fk_public_host_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Each organisation''s optional public address for its public pages; no two may share one (#493 P3, D-095)';

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'public.frame.permitted', 'yes', 'yes', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`)
SELECT s.`siteID`, 'website_setup', 'Website Set-up',
       'Holds "Set up the public website": records the main website and public address, and chooses which outside websites may show the public pages in a frame',
       1
FROM `tblSites` s
WHERE NOT EXISTS (
    SELECT 1 FROM `tblRoles` r
    WHERE r.`siteID` = s.`siteID` AND r.`roleKey` = 'website_setup'
);

-- Marked standard, in case an older hand-made role of this key exists: a custom
-- role can be DELETED by an organisation, and the code asks for this key.
UPDATE `tblRoles`
   SET `isStandard` = 1
 WHERE `roleKey` = 'website_setup' AND `isStandard` <> 1;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/public-website',       'admin/public-website/index.php', 1),
    ('admin/public-website/save',  'admin/public-website/save.php',  1),
    ('admin/public-website/check', 'admin/public-website/check.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('262_public_website_setup.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
