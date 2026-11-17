-- =============================================================================
-- Migration 306: reminders.cron_token and feeds.cron_token become secret (#1154)
--
-- Path: web/_sql/306_two_job_tokens_secret.sql
--
-- WHY. The other nine job tokens are marked secret (isSensitive = 1), so the
-- Settings page never shows them again once saved. These two were seeded with
-- the mark at 0 (migrations 122 and 129; migration 214 left them alone for
-- that reason), so they were stored as plain text and shown in full to every
-- administrator, while INSTALL.md says a saved token is never shown again.
--
-- WHAT IT DOES. Sets the mark to 1 on both names, for the installation row and
-- any organisation's own row. It encrypts nothing and changes no value: SQL
-- cannot, the key is in a file only PHP reads. A marked row that still holds
-- plain text goes on being used exactly as stored (SecretSettings::open());
-- it is stored encrypted the next time somebody saves it on the Settings page.
-- The cron pages read it through Settings::get(), unchanged.
--
-- SAFE TO RUN TWICE. It only changes rows whose mark is 0. Nothing is inserted
-- except this migration's own record, which is idempotent.
--
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/1154
-- =============================================================================

UPDATE `tblSettings`
SET `isSensitive` = 1
WHERE `isSensitive` = 0
  AND `settingKey` IN ('reminders.cron_token', 'feeds.cron_token');

INSERT INTO `tblMigrations` (`filename`) VALUES ('306_two_job_tokens_secret.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
