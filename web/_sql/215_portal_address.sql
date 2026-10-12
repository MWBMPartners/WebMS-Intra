-- =============================================================================
-- Migration 215: the portal's own web address is a setting, and our own
--                support address leaves every database (#500)
--
-- Path: web/_sql/215_portal_address.sql
--
-- WHY THIS EXISTS
--   WebMS-Intra is a product that many customers install, each at their own
--   address. Two things in the database did not fit that.
--
--   1. There was nowhere to say what the portal's address IS. Every link the
--      portal put in an e-mail took its host name from whatever request was
--      running, which a visitor chooses. A password-reset request sent with a
--      made-up host name mailed a working reset link pointing at that host.
--      The code that now decides (web/_core/PortalAddress.php) reads one
--      installation-wide setting, `site.url`. This file makes sure the row
--      is there.
--   2. Migration 062 seeded the support e-mail address of the product's first
--      customer into every installation, as both the value and its default.
--      The Help page showed it, and the footer of every templated e-mail
--      linked to it. Another customer's members were being told to write to
--      somebody else's mailbox.
--
-- WHAT IT DOES
--   A. Adds the installation-wide row `site.url`, EMPTY. Empty means "not
--      set": links then use the address of the request, and the admin
--      dashboard asks for the address to be set (Admin, Settings,
--      Organisation). A row that is already there is left exactly as it is,
--      value included: the duplicate-key clause writes nothing.
--   B. Blanks the first customer's support address wherever the
--      installation-wide `portal.support.email` row still holds it, in the
--      value and in the stored default, each judged separately. An address
--      an administrator typed is never touched. The Help page then says that
--      no support address has been set, and says where to set one.
--   C. Records itself.
--
-- HOW B KNOWS THE ADDRESS WITHOUT SPELLING IT
--   It compares a fingerprint (SHA-256, of the text in small letters with the
--   spaces at either end taken off). So this file does not hold the address
--   it removes, and tools/audit-checks/check_no_hardcoded_domains.py needs no
--   exception for it.
--
--   REJECTED: "blank the row where the value still equals its default".
--   That was the first plan, on the reasoning that only a seed ever writes
--   the default. For the portal's own pages that reasoning holds: none of
--   them has ever stored a default for this setting (the general settings
--   editor changes the value and leaves the default alone, and adds a new
--   row with no default at all). SQL written by hand is another matter. A
--   database set up or repaired that way can hold the customer's OWN
--   address in both columns, this migration cannot tell how a row got
--   there, and that rule would have blanked it. The fingerprint cannot: it
--   matches one address only.
--   (This note first blamed the grouped settings editor,
--   web/_apps/admin/settings/group.php. That was untrue: it has no field
--   for this setting. Corrected after the independent check of 12 October
--   2026. No statement below was changed.)
--
-- SAFE TO RUN TWICE
--   A is "insert, or leave alone": since migration 187 a unique key covers
--   installation-wide rows, so a second run cannot add a second row. B finds
--   nothing the second time, because the text it looks for has gone. C is
--   "insert, or leave alone". The installer relies on this: it replays every
--   numbered migration after the install script.
--
-- WHAT IT DOES NOT DO
--   - It does not guess the portal's address. Nothing in a database says
--     what address people type; the installer or an administrator does.
--   - It does not touch an ORGANISATION's own `site.url` or
--     `portal.support.email` row, if somebody has added one. No seed ever
--     wrote one. The address code ignores an organisation's `site.url`.
--   - It does not put a support address back. Our own installation's row is
--     blanked like anybody's and has to be typed again on the card.
--   - It changes no table's shape.
--   - Nothing here has been run on MariaDB; no test in this repository
--     covers it. SHA2() exists in both.
--
-- NOTE for editors: never put two hyphens together inside a quoted string in
-- this file. check_schema_seed_parity.py reads them as the start of a comment.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/500
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- 🌐  A. The portal's own address: one installation-wide row, empty
-- #############################################################################
-- The clause after ON DUPLICATE KEY is deliberately one that writes nothing.
-- The usual `defaultValue = VALUES(defaultValue)` would be harmless here, but
-- this row holds an address an administrator chose, and a seed must never be
-- able to change any part of it.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'site.url', '', '', 0)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- #############################################################################
-- 🧹  B. The first customer's support address, wherever it still sits
-- #############################################################################
-- Two statements, because the value and the default are judged separately:
-- an administrator may have changed the value and left our address as the
-- default, and that default must go too.

UPDATE `tblSettings`
   SET `settingValue` = ''
 WHERE `settingKey` = 'portal.support.email'
   AND `siteID` IS NULL
   AND SHA2(LOWER(TRIM(`settingValue`)), 256) = '97bc51f3dcb8f1f6950134f9a47cd95da4c4bee8ec6f14c9c1680f167375e087';

UPDATE `tblSettings`
   SET `defaultValue` = ''
 WHERE `settingKey` = 'portal.support.email'
   AND `siteID` IS NULL
   AND SHA2(LOWER(TRIM(`defaultValue`)), 256) = '97bc51f3dcb8f1f6950134f9a47cd95da4c4bee8ec6f14c9c1680f167375e087';


-- #############################################################################
-- 📝  C. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('215_portal_address.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
