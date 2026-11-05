-- =============================================================================
-- Migration 248: content translation gets a way in (#485, #761)
-- =============================================================================
-- One settings row. No new table, no new column, nothing dropped.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS IS FOR, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- Automatic translation of what the organisation has published — an
-- announcement, a project's description, an update posted against a project —
-- was built and then never connected to anything. `Portal\Core\Translation`
-- was complete, `/admin/translation` took five providers' keys and a monthly
-- spend cap, `/account/translation` saved a member's opt-in, and **nothing a
-- user could reach called any of it** (#485). The owner decided on 4 November
-- 2026 to finish it (decision D-049).
--
-- The handler now lives at `_apps/translation/api/content.php` and answers at
-- the address `api/translation/content`. `ApiRouter` refuses any `api/…`
-- address whose `api.{app}.{action}.enabled` row is missing, so this row is
-- what makes it reachable at all.
--
-- -----------------------------------------------------------------------------
-- WHY IT SHIPS 'true' WHEN THE FEATURE ITSELF SHIPS OFF
-- -----------------------------------------------------------------------------
-- Not a contradiction, and worth saying because it looks like one.
-- `translation.enabled` is seeded `'0'` and is the switch that decides whether
-- anything is translated; the handler asks it on every request, through
-- `Portal\Core\ContentTranslation::whyNotOffered()`. This row only says
-- "the address exists", in the same way every other endpoint's row does. An
-- administrator who switches translation on should not then have to find a
-- second switch in a different place to make it work.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION DOES NOT DO
-- -----------------------------------------------------------------------------
-- It does not switch translation on, it does not opt any member in, and it does
-- not change the monthly spend cap. Every press of the control spends the
-- customer's money with an outside company and sends a reading to them, so all
-- three of those remain decisions somebody takes deliberately.
--
-- Safe to run twice: the one insert restates the existing value rather than
-- overwriting it.
-- =============================================================================

-- #############################################################################
-- 🗺️ A. The address switch
-- #############################################################################
-- `settingValue` is restated as itself on a duplicate, NOT forced to 'true'. An
-- administrator who has switched this endpoint off must stay switched off
-- through every upgrade; `defaultValue` is brought up to date so the settings
-- page can still say what the portal ships with.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
VALUES (NULL, 'api.translation.content.enabled', 'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 📋 B. Self-record (safe to run a second time, for the reason in the header)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('248_translation_reachable.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
