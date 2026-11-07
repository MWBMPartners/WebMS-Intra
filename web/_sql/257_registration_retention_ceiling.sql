-- =============================================================================
-- Migration 257: an OPTIONAL ceiling on how long registrations are kept (#816)
-- =============================================================================
-- One setting and two addresses. No table or column changes.
--
--   events.registrationRetentionMaxDays   installation-wide only, '0' = NO
--                                         ceiling, which is how it ships
--   admin/settings/registrations          the page that sets it, and sets the
--   admin/settings/registrations/save     period (installation and organisation)
--
-- -----------------------------------------------------------------------------
-- WHY OPT-IN (the owner, 7 November 2026 — D-074, following D-032)
-- -----------------------------------------------------------------------------
-- `events.registrationRetentionDays` (an organisation's period, migration 191)
-- and `tblEvents.registrationRetentionDays` (an event's own) say how many days
-- after an event a registration's details — a child's name, date of birth,
-- allergies and medical notes, a parent's telephone number and e-mail address —
-- are kept. Nothing capped either. A registration period can be a legal
-- requirement, so a ceiling forced on from the start could make an
-- organisation delete details it must keep. So the ceiling exists, and is OFF:
-- seeded as '0', which `Portal\Core\RegistrationRetention` reads as "no
-- ceiling". Until a global administrator sets one, NOTHING CHANGES — not what
-- the nightly clear-out deletes, and not what any organisation may choose.
--
-- WHY THE PERIOD NOW HAS A PAGE. It was written only by the general settings
-- editor at `/settings`, which cannot judge a ceiling and cannot add an
-- organisation's own row (the installation holds the key). That editor now
-- refuses both keys; the new page writes them through RegistrationRetention.
--
-- -----------------------------------------------------------------------------
-- A WEB-ROOT FOLDER WOULD HIDE THESE ADDRESSES, AND DOES NOT
-- -----------------------------------------------------------------------------
-- There is no `admin` folder in `web/public_html/` (removed for #483), and
-- `tools/audit-checks/check_webroot_shadowing.py` checks every seeded address.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The setting's `ON DUPLICATE KEY UPDATE` touches only `defaultValue`, so a
-- second run — or a run after somebody has set a ceiling — never changes the
-- ceiling in force. The routes rewrite the same two rows with the same values.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/816
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'events.registrationRetentionMaxDays', '0', '0', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/registrations',      'admin/settings/registrations/index.php', 1),
    ('admin/settings/registrations/save', 'admin/settings/registrations/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('257_registration_retention_ceiling.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
