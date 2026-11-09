-- =============================================================================
-- Migration 264: the old countdown widget's addresses are removed
--                (#493, package P4b; D-084, D-056)
-- =============================================================================
-- WHAT THIS REMOVES, and why
--
--   widget/countdown.json   -> widget/countdown-json.php
--   widget/countdown        -> widget/countdown-preview.php
--
-- These were the countdown widget's feed and its "how to embed" page (#319,
-- migration 111). Both were PUBLIC addresses, open without signing in. #493's
-- public door replaces them: an organisation's website now pastes in the
-- door's own script (`/w/{name}/door.js`), whose "next" mode is the countdown
-- (D-084), and which answers only while that organisation has switched its
-- door ON, only for items somebody ticked, and only through the door's one
-- gate. The owner decided on 7 November 2026 that the old widget folder may be
-- deleted with no copy kept alongside the door (D-084, "Rules out: keeping the
-- old script alongside the new door"). No customer installation exists, so no
-- website's countdown breaks.
--
-- The files those two addresses pointed at, the static script
-- `web/public_html/widget/countdown.js` and the folder it sat in, are deleted
-- in the same package.
--
--   widget                  -> calendar/widget.php
--
-- Removed already by migration 189, and removed again here, matched on BOTH
-- the address and its target exactly as 189 does. Until this package a real
-- folder called `widget` in the web root hid this address from the portal;
-- with the folder gone the address would reach the router, and the page it
-- named has no sign-in check of its own. That page is deleted in this package
-- too, so even a row put back by hand now finds no file (the router answers
-- "page not found"). This DELETE is the second line, for a database restored
-- from a backup taken before migration 189 ran.
--
-- Three DELETEs, one per address, each written exactly as 189 writes its own
-- (`routeKey` first, then `targetFile`): that is the shape
-- tools/audit-checks/check_schema_seed_parity.py reads as "this seed was
-- removed on purpose". One DELETE joined with OR was tried first and that
-- check then reported both countdown rows as missing from the install script.
--
-- Each DELETE is matched on BOTH the address and where it points, like 189's:
-- if a future version of the portal ever gives one of these addresses a new,
-- deliberate meaning, replaying this migration must not delete it.
--
-- SAFE TO RUN AGAIN: a second run deletes nothing. The installer replays every
-- numbered migration after loading full_schema.sql; migration 111 puts the two
-- countdown rows back during that replay, and this migration, which runs after
-- it, takes them away again, so a fresh install and an upgrade end the same.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/493
-- =============================================================================

DELETE FROM `tblRoutes`
WHERE `routeKey` = 'widget/countdown.json'
  AND `targetFile` = 'widget/countdown-json.php';

DELETE FROM `tblRoutes`
WHERE `routeKey` = 'widget/countdown'
  AND `targetFile` = 'widget/countdown-preview.php';

DELETE FROM `tblRoutes`
WHERE `routeKey` = 'widget'
  AND `targetFile` = 'calendar/widget.php';

INSERT INTO `tblMigrations` (`filename`) VALUES ('264_old_widget_removed.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
