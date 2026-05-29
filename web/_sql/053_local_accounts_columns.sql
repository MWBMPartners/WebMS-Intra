-- =============================================================================
-- Migration 053: tblLocalAccounts — add missing isVerified + createdAt columns
-- =============================================================================
-- The runtime code (installer step 4 admin-create, /account/data-export GDPR
-- bundle) has referenced these columns since v0.1, but they were never added
-- to the table by any prior migration. Existing installs need this ALTER;
-- fresh installs pick them up from the updated CREATE TABLE in
-- full_schema.sql at the same time.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/198
-- =============================================================================

-- 🔑 Whether the local account's email was verified before activation.
--    Defaults to 0 so existing-row backfill matches the historical "pending"
--    state for accounts created before the columns existed; the runtime sets
--    it to 1 on admin-created and installer-created accounts.
ALTER TABLE `tblLocalAccounts`
    ADD COLUMN IF NOT EXISTS `isVerified` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Whether the email was verified before activation'
        AFTER `passwordHash`;

-- 📅 Local-account creation timestamp.
--    Defaults to CURRENT_TIMESTAMP for new inserts; for backfilling existing
--    rows we use the most recent `lastLogin` if present, else NOW(). This is
--    an approximation — the original creation time wasn't recorded.
ALTER TABLE `tblLocalAccounts`
    ADD COLUMN IF NOT EXISTS `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        AFTER `lastLogin`;

-- 🗂️ Backfill createdAt for existing rows: prefer lastLogin if it's older
--    than the new default, else leave as the freshly-defaulted NOW().
UPDATE `tblLocalAccounts`
SET    `createdAt` = `lastLogin`
WHERE  `lastLogin` IS NOT NULL
  AND  `createdAt` > `lastLogin`;
