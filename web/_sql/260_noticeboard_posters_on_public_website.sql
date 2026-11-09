-- =============================================================================
-- Migration 260: noticeboard posters on the public website (#493, package P2a)
-- =============================================================================
-- Three columns on `tblNoticeboardPosters`, as events got in migration 258:
--
--   showOnWebsite   the switch (D-057): nothing is public until a person ticks it
--   websiteFrom     the first day it may show (D-078), inclusive, optional
--   websiteUntil    the last day it may show (D-078), inclusive, optional
--
-- and the address of the page that sets them, `noticeboard/website`.
--
-- -----------------------------------------------------------------------------
-- WHY THE BOARD'S OWN EDITOR CANNOT SET THEM
-- -----------------------------------------------------------------------------
-- The board is a generated bundle built from `dc-runtime`, which is not in this
-- repository (#623), so it cannot gain a control. Its save (`api/noticeboard/
-- save`) rewrites the whole board, but its UPDATE names only the columns it is
-- given, so these three SURVIVE every editor save, and a poster it creates
-- takes the defaults below: off the website. The one exception is deliberate:
-- when the editor DELETES a poster it also clears the switch, so a poster
-- brought back (an editor tab opened before the deletion saving its id) is
-- off until somebody ticks it again. A page of its own sets them, open to
-- whoever holds `website.publish` (D-077).
--
-- -----------------------------------------------------------------------------
-- WHAT THE UPGRADE DOES
-- -----------------------------------------------------------------------------
-- Every existing poster starts OFF the website (D-057: the default is private,
-- so the failure mode is silence). Nothing on the board itself changes.
--
-- SAFE TO RUN TWICE: each column is added only when `information_schema` says
-- it is missing (no MariaDB-only `ADD COLUMN IF NOT EXISTS`); the route
-- rewrites the same row.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/493
-- =============================================================================

SET @has := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblNoticeboardPosters'
      AND `COLUMN_NAME`  = 'showOnWebsite'
);
SET @sql := IF(@has = 0,
    'ALTER TABLE `tblNoticeboardPosters` ADD COLUMN `showOnWebsite` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''The poster is on the organisation''''s PUBLIC WEBSITE only while this is 1 AND today is inside websiteFrom..websiteUntil (D-057, D-078). Starts at 0 on every poster (#493 P2, migration 260); set to 1 only on Noticeboard, Public website, by a person holding website.publish (D-077), judged by PublicWebsite. The board''''s own editor never sets it to 1; it clears it when it deletes a poster, so one brought back is off. Decided only by PublicWebsite::posterWhere().'' AFTER `sortOrder`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblNoticeboardPosters'
      AND `COLUMN_NAME`  = 'websiteFrom'
);
SET @sql := IF(@has = 0,
    'ALTER TABLE `tblNoticeboardPosters` ADD COLUMN `websiteFrom` DATE DEFAULT NULL COMMENT ''The first DAY the poster may be on the public website (D-078), inclusive. A calendar date on the ORGANISATION''''s calendar. NULL = no limit on that side. Narrows showOnWebsite, never widens it.'' AFTER `showOnWebsite`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblNoticeboardPosters'
      AND `COLUMN_NAME`  = 'websiteUntil'
);
SET @sql := IF(@has = 0,
    'ALTER TABLE `tblNoticeboardPosters` ADD COLUMN `websiteUntil` DATE DEFAULT NULL COMMENT ''The last DAY the poster may be on the public website (D-078), inclusive. A calendar date on the ORGANISATION''''s calendar. NULL = no limit on that side. Narrows showOnWebsite, never widens it.'' AFTER `websiteFrom`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The Website Editor role's description said giving the permission "changes
-- nothing" for a non-administrator; this page makes that untrue. Rewritten only
-- where it is still the old sentence, so an organisation's own wording stays.
UPDATE `tblRoles`
   SET `description` = 'Holds "Choose what appears on the public website": can choose which noticeboard posters are on the public website, and when'
 WHERE `roleKey` = 'website_editor'
   AND `description` = 'Holds "Choose what appears on the public website"; until a page a non-administrator can open asks for it, this changes nothing';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('noticeboard/website', 'noticeboard/website.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('260_noticeboard_posters_on_public_website.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
