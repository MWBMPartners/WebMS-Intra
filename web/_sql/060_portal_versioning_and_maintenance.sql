-- =============================================================================
-- Migration 060: portal version tracking + maintenance-mode settings
-- =============================================================================
-- Adds three tblSettings keys used by the install/upgrade flow:
--
--   • portal.installed_version    — string. Set by the installer on step 5
--     completion and by Migrator::runAll() / /admin/upgrade on success.
--     The front-controller maintenance gate compares this to the
--     PORTAL_VERSION constant from _core/version.php.
--
--   • portal.maintenance.active   — '0'/'1'. Flipped to '1' by /admin/upgrade
--     while it's running, and by the installer's drop-and-rebuild path.
--     When '1', non-allow-listed routes render the maintenance page to
--     non-admin users.
--
--   • portal.maintenance.message  — optional custom message shown on the
--     maintenance page. Falls back to a default if empty.
--
-- All three exist as INSERT … ON DUPLICATE KEY UPDATE — idempotent and
-- safe to re-run.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/220
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.installed_version',     '',  '',  0),
    (NULL, 'portal.maintenance.active',    '0', '0', 0),
    (NULL, 'portal.maintenance.message',   '',  '',  0),
    (NULL, 'portal.upgrade.backup.enabled',           '1',  '1',  0),
    (NULL, 'portal.upgrade.backup.keep_last_n',       '10', '10', 0),
    (NULL, 'portal.upgrade.fresh_required_below',     '',   '',   0),
    (NULL, 'portal.upgrade.require_hostname_confirm', '1',  '1',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
