-- =============================================================================
-- Migration 225: switch on the apps that are finished, and remove a setting
--                that told every member something untrue
-- =============================================================================
-- Path: _sql/225_default_apps_on.sql
-- Description: Fifteen app switches were seeded off while those apps were still
--              only planned, and nobody turned them on when the apps shipped.
--              This brings the defaults into line with what the portal can
--              actually do. It also removes `notifications.deliveryReady`,
--              which gated nothing and put a false banner in front of every
--              member.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- WHY THE APPS WERE OFF, which is the whole point
-- -----------------------------------------------------------------------------
-- The seeds for these fifteen sit under a comment in the install script reading
-- "Future app toggles (disabled by default)". That was true when it was
-- written. Each of those apps has since been built, and the switch was never
-- changed, so the default reflected a roadmap rather than the product.
--
-- WHAT THAT COST. The main menu and the dashboard cards are both built from
-- these switches: `_core/templates/nav.php` draws one entry per app whose
-- `<app>.enabled` is 'true' or '1', and leaves out any whose address does not
-- exist. So a brand-new installation showed NO Events/Calendar, no Attendance,
-- no Directory, no Venues, no Leadership, no Recordings, no Rota, no Photos and
-- no Projects. Every one of those pages WORKED if the address was typed by
-- hand — only 18 pages in the whole portal refuse to run when their app is
-- switched off — so nothing was broken. But a customer signing in for the first
-- time would reasonably have concluded the portal did not have those features.
--
-- THE FIFTEEN, AND WHY THESE FIFTEEN
-- -----------------------------------------------------------------------------
-- Each was checked, before this was written, for two things: that its menu
-- address really exists (otherwise switching it on changes nothing, because
-- nav.php drops an entry whose address is unregistered), and that its landing
-- page does not refuse an ordinary member at the door (otherwise the menu would
-- offer every member a page that bounces them).
--
--   calendar, attendance, directory, leadership, venues, resources, photos,
--   projects, rota, recordings, invites, visitors, praise, milestones,
--   discipleship
--
-- TWO THAT WERE DELIBERATELY LEFT OFF, and the reason matters:
--   * `care` — pastoral care. Its landing page requires the `care_team` role,
--     and nav.php has NO role filter, so switching it on would put a "Care"
--     entry in every member's menu that most of them cannot open. Leave it to
--     an administrator who knows who is on the care team.
--   * `preachingplan` — there is no app definition file for it, so nav.php
--     would drop the entry anyway. The Preaching Plan is part of the Events
--     app and is reached through the Calendar manage pages.
--
-- Everything needing a paid account or an external service stays off
-- (payments, giving, SMS, newsletter, push, chat, Cloudflare Stream,
-- what3words, monitoring), as does anything still unfinished (AI Assist,
-- translation, transcription, Zoom, reading plans) and `multisite`.
--
-- EACH KEY KEEPS ITS OWN VOCABULARY, which is why there are two statements.
-- Some of these settings were seeded 'false'/'true' and others '0'/'1'.
-- `AppRegistry::isEnabled()` and nav.php accept either, and the Apps screen
-- writes '1'. Rewriting a key from 'false' to '1' would have been tidier to
-- look at and riskier: anything comparing that value as text would change
-- behaviour for reasons nothing to do with this change. So each key is moved to
-- the "on" spelling of the pair it already used.
--
-- `settingValue` = `defaultValue` IS THE GUARD, and here is what it can and
-- cannot do. It means "this value has never been changed from what it was
-- seeded as", so an installation where somebody has already made a choice
-- through Admin → Apps is left exactly alone. WHAT IT CANNOT DO: tell a
-- deliberate "off" from an untouched "off", because both leave the value equal
-- to the default. An installation that deliberately switched one of these
-- fifteen off, and did so by setting it back to the seeded value, will find it
-- on again after this upgrade. That is accepted because this lands before the
-- first usable release, and because the switch could only be reached through a
-- screen listing apps that were absent from the menu. An administrator can
-- switch any of them off again, and that choice WILL then survive, because the
-- value will no longer equal the default.
--
-- Only the installation-wide row (`siteID IS NULL`) is touched. A per-site
-- override is somebody's deliberate decision for that organisation.
--
-- SAFE TO RUN TWICE: both statements are UPDATEs whose second run writes the
-- same values, and the DELETE finds nothing left to remove.
-- =============================================================================

-- #############################################################################
-- 🔛 A. The apps seeded with the words 'false' / 'true'
-- #############################################################################

UPDATE `tblSettings`
   SET `settingValue` = 'true',
       `defaultValue` = 'true'
 WHERE `siteID` IS NULL
   AND `settingKey` IN (
        'calendar.enabled',
        'attendance.enabled',
        'leadership.enabled',
        'discipleship.enabled'
   )
   AND `settingValue` = `defaultValue`;

-- #############################################################################
-- 🔛 B. The apps seeded with the digits '0' / '1'
-- #############################################################################

UPDATE `tblSettings`
   SET `settingValue` = '1',
       `defaultValue` = '1'
 WHERE `siteID` IS NULL
   AND `settingKey` IN (
        'directory.enabled',
        'venues.enabled',
        'resources.enabled',
        'photos.enabled',
        'projects.enabled',
        'rota.enabled',
        'recordings.enabled',
        'invites.enabled',
        'visitors.enabled',
        'praise.enabled',
        'milestones.enabled'
   )
   AND `settingValue` = `defaultValue`;

-- #############################################################################
-- 🗑️ C. The setting that told every member something untrue
-- #############################################################################
-- `notifications.deliveryReady` was seeded 'false' and read in exactly ONE
-- place: `_apps/auth/account/notifications.php`, to decide whether to show a
-- banner reading "Notifications are not yet being sent on this site. Your
-- preferences below are saved and will take effect once delivery is enabled."
--
-- Nothing that sends anything ever consulted it. The mail queue, the reminder
-- jobs and `Mailer` do not read it, so on every fresh installation each member
-- was told their preferences would not take effect while in fact they did. The
-- banner also printed the raw setting name at the member, which the plain
-- English rule rules out.
--
-- Both the banner and the setting are removed. EVERY row goes, not only the
-- installation-wide one: nothing reads the key any more, so a per-site
-- override would be a row that can never have an effect.
--
-- Nothing else has to change for this to be safe: the page no longer mentions
-- the key, and `check_settings_keys.py` only objects to a setting READ without
-- a seed, never to a seed with no reader.

DELETE FROM `tblSettings`
 WHERE `settingKey` = 'notifications.deliveryReady';

-- #############################################################################
-- 📋 D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('225_default_apps_on.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
