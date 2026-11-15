-- =============================================================================
-- Migration 294: may a Microsoft or Google sign-in make a NEW account? (#1042)
-- =============================================================================
-- What this adds:
--
--   A. The installation-wide setting `auth.sso.createAccounts`, '1' (allow).
--   B. The address `admin/integrations/sso-accounts-save`, where the
--      Integrations page saves the choice.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- When a Microsoft or Google sign-in finds no linked account and no account
-- with its e-mail address, the portal makes a new, empty account. After a
-- backup is restored, a member who linked Microsoft and changed their address
-- after the backup looks exactly the same, and gets a second, empty account.
-- The owner chose a setting (D-185): default allow, so nothing changes until
-- somebody chooses; an organisation may refuse, and the stricter level wins.
-- `Portal\Core\SsoNewAccounts` is the one place that reads it, and a MISSING
-- row also counts as allow, so this migration not having run yet changes
-- nothing either.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The setting is restated as itself rather than overwritten, so an
-- installation that has chosen '0' keeps '0'; the address is restated to its
-- own target. The install script holds both, so on a fresh install every
-- statement here is a no-op.
-- =============================================================================

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.sso.createAccounts', '1', 0, '1')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/sso-accounts-save', 'admin/integrations/sso-accounts-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('294_sso_new_accounts_setting.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
