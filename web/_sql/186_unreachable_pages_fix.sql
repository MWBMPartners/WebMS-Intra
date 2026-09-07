-- =============================================================================
-- Migration 186: reach two pages that had become impossible to open
-- =============================================================================
-- No tables, no columns. Two route corrections and one tidy-up.
--
-- -----------------------------------------------------------------------------
-- 1. THE NOTIFICATION PREFERENCES PAGE
-- -----------------------------------------------------------------------------
-- There are two pages in this codebase both titled "Notification preferences":
--
--   web/_apps/auth/account/notifications.php   — the real one. Digest emails,
--       event reminders, expense updates, giving statements, task and rota
--       reminders, approval requests, Sabbath quiet hours, and the browser
--       push notification opt-in.
--
--   web/_apps/account/notifications.php        — a single checkbox for the
--       newsletter, added later by migration 093.
--
-- Migration 093 seeded the address `account/notifications` a second time,
-- pointing it at the newsletter page. Because a later seed overwrites an
-- earlier one, the real page lost its only way in and has been impossible to
-- open ever since. Every link in the product — the "Notification preferences"
-- button on the account page, and the push notification setup instructions in
-- the admin area — has been landing on the newsletter checkbox instead.
--
-- The practical loss: browser push notifications could only be switched on
-- from the livestream page, so nobody could subscribe to service reminders.
--
-- The newsletter checkbox has now been moved onto the real page, so there is
-- one place for all notification choices. The newsletter-only page is deleted
-- in the same change as this migration. This statement points the address back
-- where it belongs.
--
-- Its saving handler, `account/notifications/save`, was never re-pointed by
-- 093 and still works — it has been handling the form of a page nobody could
-- open. No change needed there.
--
-- -----------------------------------------------------------------------------
-- 2. THE LIVESTREAM CHANNELS AND SCHEDULE PAGE
-- -----------------------------------------------------------------------------
-- The same thing happened to `web/_apps/admin/livestream/index.php`, which is
-- where a site administrator sets up livestream channels and their schedule.
-- Migration 133 re-pointed the address `admin/livestream` at the new viewer
-- analytics dashboard, and nothing else ever pointed at the setup page.
--
-- That also took away the only button that sends the "we are live now"
-- notification to subscribers, which `web/_apps/cron/push-golive.php` names in
-- its own comments as the manual counterpart to its automatic version.
--
-- Rather than take the address back from the analytics dashboard — which is
-- now what people expect at `/admin/livestream`, and is linked to from four
-- other pages — the setup page gets its own address, and the analytics
-- dashboard gains a link to it.
--
-- -----------------------------------------------------------------------------
-- 3. FOUR SETTINGS THAT SWITCH ON ENDPOINTS THAT DO NOT EXIST
-- -----------------------------------------------------------------------------
-- Each of these turns on an API endpoint whose handler file was never written
-- (or was removed). They do nothing except mislead anyone reading the settings
-- list into thinking the endpoint exists:
--
--   api.expenses.stats.enabled          -> _apps/expenses/api/stats.php
--   api.expenses.attachments.enabled    -> _apps/expenses/api/attachments.php
--   api.expenses.update.enabled         -> _apps/expenses/api/update.php
--   api.expenses.update-status.enabled  -> _apps/expenses/api/update-status.php
--
-- Removing them changes no behaviour: a request to any of those addresses
-- already answers "not found", because the router looks for the file itself.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra
-- =============================================================================

-- #############################################################################
-- 🗺️ A. Route corrections
-- #############################################################################

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/notifications',      'auth/account/notifications.php', 1),
    ('admin/livestream/channels',  'admin/livestream/index.php',     1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 🧹 B. Remove the four settings that point at endpoints that do not exist
-- #############################################################################
-- Safe to run again: a second run simply deletes nothing.

DELETE FROM `tblSettings`
WHERE `settingKey` IN (
    'api.expenses.stats.enabled',
    'api.expenses.attachments.enabled',
    'api.expenses.update.enabled',
    'api.expenses.update-status.enabled'
);

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('186_unreachable_pages_fix.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
