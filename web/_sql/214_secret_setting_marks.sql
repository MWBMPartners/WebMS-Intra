-- =============================================================================
-- Migration 214: secret settings get their mark back (#597, #497)
--
-- Path: web/_sql/214_secret_setting_marks.sql
--
-- WHY THIS EXISTS
--   A setting is a SECRET when its `isSensitive` column is 1 (the "mark"). A
--   secret is stored encrypted and is never shown on the Settings page.
--
--   Until this release, editing a secret in the Settings page's edit box
--   stored it as PLAIN TEXT and set its mark to 0 (#597). From then on the
--   page showed it, in full, to every administrator, including an
--   administrator of only one organisation. The page is fixed in the same
--   release, but it goes by the mark, and on those rows the mark is gone.
--
-- WHAT IT DOES
--   A. Puts the mark back on every setting this portal has ever seeded as a
--      secret, wherever it is 0. The list is the 56 names the install script
--      seeded with the mark on 12 October 2026, and `mail.smtp.password`,
--      which migration 213 adds in the same release (on a database without
--      it, that name simply matches nothing). It covers a row for the whole
--      installation and any organisation's own row of the same name.
--   B. Takes the mark OFF `auth.ms365.tenantOnly`. It is a yes/no, seeded as
--      the plain text `true`, and was marked by mistake. Nothing reads it.
--      Left marked, every installation would show one "set, but not
--      protected" warning for ever, which teaches people to ignore it.
--   C. Records itself.
--
-- WHAT IT DOES NOT DO
--   - It does NOT encrypt anything, and it changes no value. SQL cannot: the
--     key is in a file only PHP reads. A row marked here still holds plain
--     text until somebody saves it again on the Settings page, which says
--     which rows those are (by name, never by value).
--   - It does not stop any of them working. The portal uses a marked row
--     that it cannot decrypt exactly as it is stored (see
--     web/_core/SecretSettings.php), so a plain value goes on being used.
--   - It leaves `recordings.podcast_token` alone, on purpose. That one is
--     not seeded; each organisation's is made the first time it is needed.
--     Recordings::podcastToken() makes a NEW token when it finds a marked row
--     it cannot decrypt, and a new token changes the feed address every
--     subscriber uses. An organisation's token that was edited in the old
--     edit box therefore stays as it is.
--   - It cannot find a setting an administrator invented, added as a secret
--     and later edited in the old edit box. The mark was the only record
--     that it was a secret.
--   - It does not touch `feeds.cron_token` or `reminders.cron_token`. They
--     are seeded WITHOUT the mark, so #597 never changed them.
--   - Nothing here has been run on MariaDB; no test in this repository
--     covers it.
--
-- SAFE TO RUN TWICE
--   A only changes rows whose mark is 0, and B only one whose mark is 1, so a
--   second run changes nothing. No row is added: there is no INSERT into
--   tblSettings here at all. The installer relies on this: it replays every
--   numbered migration after the install script.
--
-- WHY B LOOKS AT THE VALUE
--   If somebody has stored something other than a plain yes/no in that row,
--   it is left marked and hidden. Taking the mark off would put whatever it
--   holds on the Settings page.
--
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/597
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/497
-- =============================================================================

-- A. The mark goes back on.
UPDATE `tblSettings`
SET `isSensitive` = 1
WHERE `isSensitive` = 0
  AND `settingKey` IN (
    'ai_assist.anthropic.apiKey', 'ai_assist.openai.apiKey', 'assets.cron_token',
    'auth.google.clientID', 'auth.google.clientSecret', 'auth.google.redirectURI',
    'auth.hcaptcha.secretKey', 'auth.hcaptcha.siteKey', 'auth.ms365.appwide.clientID',
    'auth.ms365.appwide.clientSecret', 'auth.ms365.appwide.redirectURI', 'auth.ms365.defaultFrom',
    'auth.ms365.enduser.clientID', 'auth.ms365.enduser.clientSecret',
    'auth.ms365.enduser.redirectURI', 'auth.ms365.tenantID', 'auth.recaptcha.secretKey',
    'auth.recaptcha.siteKey', 'auth.turnstile.secretKey', 'auth.turnstile.siteKey',
    'cfstream.apiToken', 'cfstream.signingKeyPem', 'discipleship.cron_token', 'geo.google.apiKey',
    'giving.cron_token', 'hymns.remote.apiKey', 'mail.google.serviceAccountKeyFile',
    'mail.smtp.password', 'maintenance.cronToken', 'monitoring.sentryDsn', 'newsletter.mailermatt.apiKey',
    'payments.gocardless.token', 'payments.gocardless.webhookSecret', 'payments.paypal.clientId',
    'payments.paypal.secret', 'payments.stripe.secret', 'payments.stripe.webhookSecret',
    'portal.qr.cuercode.api_key', 'push.cron_token', 'push.vapidPrivateKey', 'sms.aws.secret',
    'sms.messagebird.apiKey', 'sms.twilio.token', 'transcription.assemblyai.apiKey',
    'transcription.openai.apiKey', 'translation.anthropic.apiKey', 'translation.deepl.apiKey',
    'translation.google.apiKey', 'translation.libre.apiKey', 'translation.openai.apiKey',
    'user_reminders.cron_token', 'venues.cron_token', 'w3w.apiKey', 'webhooks.cron_token',
    'workflows.cron_token', 'zoom.clientSecret', 'zoom.webhookSecret'
  );

-- B. `auth.ms365.tenantOnly` is a yes/no, not a secret.
UPDATE `tblSettings`
SET `isSensitive` = 0
WHERE `settingKey` = 'auth.ms365.tenantOnly'
  AND `isSensitive` = 1
  AND (`settingValue` IS NULL OR `settingValue` IN ('', 'true', 'false', '1', '0'));

-- C. Record this migration (safe to run twice).
INSERT INTO `tblMigrations` (`filename`) VALUES ('214_secret_setting_marks.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
