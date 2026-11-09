-- =============================================================================
-- Migration 258: "Show on the public website" for events (#493, package P1)
-- =============================================================================
-- Four things, all for the organisation's PUBLIC WEBSITE — the countdown widget
-- (`widget/countdown.json`) and the staff door's `sitemap.xml` today, and the
-- public door of #493's later packages:
--
--   A. three columns on `tblEvents`:
--        showOnWebsite  the switch (D-057, D-078). Starts at 0 on EVERY row.
--        websiteFrom    an optional first day it may show (D-078).
--        websiteUntil   an optional last day it may show (D-078).
--   B. the setting `public.events.copiedRule` — how an event COPIED IN from an
--      outside calendar reaches the website (D-080, D-081), installation-wide,
--      seeded as 'event' (one tick per event, the stricter of the two).
--   C. a standard role, `website_editor`, for every organisation — the role the
--      new permission `website.publish` (D-077) ships pointing at.
--   D. the address of the small form that sets the rule in B.
--
-- -----------------------------------------------------------------------------
-- WHY EVERY EXISTING EVENT STARTS OFF THE WEBSITE (the live exposure it closes)
-- -----------------------------------------------------------------------------
-- Until now `Portal\Core\EventVisibility`'s "website" mode let an organisation's
-- own event through on `isPublic = 1` alone, and `isPublic` defaults to 1. So
-- the countdown feed and the sitemap — both open to anybody, no sign-in — showed
-- EVERY published event unless somebody remembered to untick a box whose label
-- says "visible on calendar", not "on the internet". That is the default
-- exposure D-057 rejects ("nothing is public until somebody ticks it"). The rule
-- now also requires `showOnWebsite = 1`, and this migration adds the column at
-- 0, so after the upgrade NOTHING of the organisation's own is on the website
-- until a person with the new permission ticks it. No customer installation
-- exists yet (the owner, 1 November 2026), so no embed on a real website stops
-- showing an event; on any installation that did exist, that would be the
-- deliberate cost, and the failure mode is silence rather than exposure.
--
-- -----------------------------------------------------------------------------
-- WHY THE TWO DATES ARE `DATE`, AND ON WHICH CALENDAR (said in each COMMENT too)
-- -----------------------------------------------------------------------------
-- D-078: an item is on the website only while its switch is on AND today is
-- inside its dates, an empty date meaning "no limit on that side". These are
-- calendar DATES, not moments, and they are read on the ORGANISATION'S calendar
-- — the same "today" `EventVisibility::where()` already takes from its caller
-- (`date('Y-m-d')`, which start-up sets to `Site::timezone()`). Not the
-- event's own clock, on purpose: a publishing window is a decision about the
-- organisation's own website, the same window will apply to a noticeboard
-- poster (#493 P2), which has no clock of its own, and judging it per event
-- would make the rule's SQL depend on which zones the installation holds,
-- which `EventVisibility` refuses (its SQL text depends on the mode alone, the
-- #503 rule). Both ends are INCLUSIVE: "until 5 December" shows on the 5th.
--
-- -----------------------------------------------------------------------------
-- WHY 'event' IS THE DEFAULT FOR COPIED-IN EVENTS
-- -----------------------------------------------------------------------------
-- D-080 lets the installation (and, capped by it, D-081, each organisation)
-- choose between "one box per subscribed calendar" — the #514 behaviour, where
-- ticking the calendar's "Also show on the public website" box sends every
-- public event from it, future ones nobody has looked at included — and "one
-- tick per event", where only a choice made about THAT event (a date or series
-- choice on Admin, Outside calendars) can put it there. The decisions name no
-- default. 'event' is seeded because it is the stricter, matching D-086's "the
-- stricter is the default" for the other tiered public-website settings and
-- D-057's "the failure mode is silence". A value the portal cannot read counts
-- as 'event' too.
--
-- WHAT THE UPGRADE ITSELF DOES (round 8 of #493 P1's check): seeding 'event'
-- takes effect at once for every website reader, which asks the rule at READ
-- time — so a copied-in event whose yes came from its calendar's or a rule's
-- box leaves the website the moment this runs. One case waits: a date a choice
-- put on the website WITHOUT an approval (its calendar's box was ticked) stays
-- until its calendar is next re-worked, because only a re-work sends it to wait
-- for one; a refresh that finds the outside file unchanged does not, for up to
-- 20 hours. Saving the rule on Admin, Outside calendars re-works every calendar
-- at once. No customer installation exists yet (the owner, 1 November 2026).
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Each column is added only when `information_schema` says it is missing (no
-- MariaDB-only `ADD COLUMN IF NOT EXISTS`, `.claude/rules/database.md`). The
-- setting's `ON DUPLICATE KEY UPDATE` touches `defaultValue` only, so a second
-- run never changes a rule somebody has chosen. The role is inserted only where
-- an organisation lacks it. The route rewrites the same row.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/493
-- =============================================================================

-- ── A. The switch and the two dates ─────────────────────────────────────────
SET @hasShow := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblEvents'
      AND `COLUMN_NAME`  = 'showOnWebsite'
);
SET @sql := IF(@hasShow = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `showOnWebsite` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''The portal''''s own events only: 1 = a person allowed to publish (capability website.publish, D-077) ticked "Show on the public website" (D-057). The website also needs isPublic = 1 and today inside websiteFrom..websiteUntil (D-078). Starts at 0 on every row (#493, migration 258). Decides the website only through EventVisibility (web/_core/EventVisibility.php); written only after PublicWebsite::judgeEventFields() allows the change. Ignored on an event copied in from an outside calendar.'' AFTER `isPublic`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasFrom := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblEvents'
      AND `COLUMN_NAME`  = 'websiteFrom'
);
SET @sql := IF(@hasFrom = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `websiteFrom` DATE DEFAULT NULL COMMENT ''The first DAY the event may be on the public website (D-078), inclusive. A calendar date on the ORGANISATION''''s calendar - not a moment and not the event''''s own clock. NULL = no limit on that side. Narrows showOnWebsite, never widens it.'' AFTER `showOnWebsite`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasUntil := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblEvents'
      AND `COLUMN_NAME`  = 'websiteUntil'
);
SET @sql := IF(@hasUntil = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `websiteUntil` DATE DEFAULT NULL COMMENT ''The last DAY the event may be on the public website (D-078), inclusive. A calendar date on the ORGANISATION''''s calendar - not a moment and not the event''''s own clock. NULL = no limit on that side. Narrows showOnWebsite, never widens it.'' AFTER `websiteFrom`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── B. How copied-in events reach the website (D-080, D-081) ────────────────
-- Installation-wide only. An organisation's own row is written only by the
-- form at D, which refuses a looser choice than this one; the reader holds an
-- organisation to the stricter of the two in any case.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'public.events.copiedRule', 'event', 'event', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- ── C. The Website Editor role, for every organisation that lacks it ────────
-- Nobody is granted it: D-077 says that out of the box only an organisation's
-- ADMINISTRATORS may publish, and they may through the permission's own gate
-- (`Portal\Core\PublicWebsite::mayPublish()`), not through this role. The role
-- exists so an organisation can give the job to, say, its communications
-- person on the Roles page, and so the permission ships pointing at a role
-- that exists (`check_capability_registry.py`, rule D).
INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`)
SELECT s.`siteID`, 'website_editor', 'Website Editor',
       'Holds "Choose what appears on the public website"; until a page a non-administrator can open asks for it, this changes nothing',
       1
FROM `tblSites` s
WHERE NOT EXISTS (
    SELECT 1 FROM `tblRoles` r
    WHERE r.`siteID` = s.`siteID` AND r.`roleKey` = 'website_editor'
);

-- Marked standard, in case an older hand-made role of this key exists: a custom
-- role can be DELETED by an organisation, and the code asks for this key.
UPDATE `tblRoles`
   SET `isStandard` = 1
 WHERE `roleKey` = 'website_editor' AND `isStandard` <> 1;

-- ── D. The form that sets the copied-in rule ───────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/calendar/feeds/website-rule', 'admin/calendar/feeds-website-rule.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('258_events_show_on_website.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
