-- =============================================================================
-- Migration 302: spending with outside companies, recorded in each company's
-- own currency (#993, package B; D-173, D-192, D-194)
-- =============================================================================
-- What this changes:
--
--   A. tblProviderSpend     NEW. One row per organisation, month, service and
--                           currency: what was charged this month, in
--                           millionths of that currency. It is the ONE place
--                           the monthly figure for AI, translation,
--                           transcription and texts is read from, and an
--                           erasure does not touch it (it names nobody).
--   B. tblAiUsage, tblSmsMessage, tblTranscript, tblContentTranslation
--                           gain costCurrency and costMillionths and LOSE
--                           costPence.
--   C. tblTranslationUsage  loses costPence (it now counts requests only).
--
-- WHY
-- costPence was in no named currency, and the formulas that filled it were
-- 100 to 1,000 times too large (Anthropic 100x, OpenAI 1,000x). D-173: a
-- charge is recorded in the currency the company charges in. The old figures
-- are DROPPED, not converted: they were not in any currency and were wrong by
-- orders of magnitude, and no customer installation exists yet (the owner,
-- 2 November 2026), so nothing real is lost. Each dropped column is on
-- DbBackup::REMOVED_COLUMNS so an older snapshot still restores.
--
-- SAFE TO RUN TWICE
-- CREATE TABLE IF NOT EXISTS; every column is added or dropped only when
-- information_schema says it is needed (MySQL 8.0 has no ADD COLUMN IF NOT
-- EXISTS). Every identifier is backticked. MySQL 8.0 and MariaDB.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/993
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblProviderSpend` (
    `siteID`         INT         NOT NULL COMMENT 'The organisation whose members caused the charge. No foreign key on purpose: what an installation was charged outlives the organisation that caused it (as tblTranslationUsage)',
    `monthStartUtc`  DATE        NOT NULL COMMENT 'The first day of the calendar month, IN UTC, the charge falls in: a calendar date, not a moment',
    `service`        VARCHAR(20) NOT NULL COMMENT 'Which kind of outside company: ai, translation, transcription or sms',
    `costCurrency`   CHAR(3)     NOT NULL COMMENT 'The currency the outside company charges in, as three capital letters, for example USD. Never the organisation''s own currency: two currencies are never added together (D-173)',
    `costMillionths` BIGINT      NOT NULL DEFAULT 0 COMMENT 'What was charged this month, in millionths of one unit of costCurrency (1,000,000 = one whole dollar, pound or euro). Whole numbers only, never a floating-point number',
    PRIMARY KEY (`siteID`, `monthStartUtc`, `service`, `costCurrency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Spending with outside companies, per organisation, month, service and currency (#993, D-173). Holds no personal data and is not emptied by an erasure. Read and written only by Portal\\Core\\ProviderSpend.';

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblSmsMessage' AND `COLUMN_NAME` = 'costCurrency'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblSmsMessage` ADD COLUMN `costCurrency` CHAR(3) DEFAULT NULL COMMENT ''The currency this charge was made in: the outside company''''s own currency as three capital letters, for example USD (D-173). Empty together with the amount when no cost was recorded'' AFTER `providerRef`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblSmsMessage' AND `COLUMN_NAME` = 'costMillionths'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblSmsMessage` ADD COLUMN `costMillionths` BIGINT DEFAULT NULL COMMENT ''What the outside company charged, in millionths of one unit of the currency in costCurrency (1,000,000 = one whole dollar, pound or euro), worked out by Portal\\Core\\ProviderSpend and never a floating-point number. Empty means no cost was recorded (a free local route, or a row from before migration 302)'' AFTER `costCurrency`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblTranscript' AND `COLUMN_NAME` = 'costCurrency'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblTranscript` ADD COLUMN `costCurrency` CHAR(3) DEFAULT NULL COMMENT ''The currency this charge was made in: the outside company''''s own currency as three capital letters, for example USD (D-173). Empty together with the amount when no cost was recorded'' AFTER `durationSec`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblTranscript' AND `COLUMN_NAME` = 'costMillionths'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblTranscript` ADD COLUMN `costMillionths` BIGINT DEFAULT NULL COMMENT ''What the outside company charged, in millionths of one unit of the currency in costCurrency (1,000,000 = one whole dollar, pound or euro), worked out by Portal\\Core\\ProviderSpend and never a floating-point number. Empty means no cost was recorded (a free local route, or a row from before migration 302)'' AFTER `costCurrency`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblContentTranslation' AND `COLUMN_NAME` = 'costCurrency'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblContentTranslation` ADD COLUMN `costCurrency` CHAR(3) DEFAULT NULL COMMENT ''The currency this charge was made in: the outside company''''s own currency as three capital letters, for example USD (D-173). Empty together with the amount when no cost was recorded'' AFTER `qualityScore`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblContentTranslation' AND `COLUMN_NAME` = 'costMillionths'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblContentTranslation` ADD COLUMN `costMillionths` BIGINT DEFAULT NULL COMMENT ''What the outside company charged, in millionths of one unit of the currency in costCurrency (1,000,000 = one whole dollar, pound or euro), worked out by Portal\\Core\\ProviderSpend and never a floating-point number. Empty means no cost was recorded (a free local route, or a row from before migration 302)'' AFTER `costCurrency`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblAiUsage' AND `COLUMN_NAME` = 'costCurrency'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblAiUsage` ADD COLUMN `costCurrency` CHAR(3) DEFAULT NULL COMMENT ''The currency this charge was made in: the outside company''''s own currency as three capital letters, for example USD (D-173). Empty together with the amount when no cost was recorded'' AFTER `outputTokens`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblAiUsage' AND `COLUMN_NAME` = 'costMillionths'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblAiUsage` ADD COLUMN `costMillionths` BIGINT DEFAULT NULL COMMENT ''What the outside company charged, in millionths of one unit of the currency in costCurrency (1,000,000 = one whole dollar, pound or euro), worked out by Portal\\Core\\ProviderSpend and never a floating-point number. Empty means no cost was recorded (a free local route, or a row from before migration 302)'' AFTER `costCurrency`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblSmsMessage' AND `COLUMN_NAME` = 'costPence'
);
SET @sql := IF(@c = 1, 'ALTER TABLE `tblSmsMessage` DROP COLUMN `costPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblTranscript' AND `COLUMN_NAME` = 'costPence'
);
SET @sql := IF(@c = 1, 'ALTER TABLE `tblTranscript` DROP COLUMN `costPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblContentTranslation' AND `COLUMN_NAME` = 'costPence'
);
SET @sql := IF(@c = 1, 'ALTER TABLE `tblContentTranslation` DROP COLUMN `costPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblAiUsage' AND `COLUMN_NAME` = 'costPence'
);
SET @sql := IF(@c = 1, 'ALTER TABLE `tblAiUsage` DROP COLUMN `costPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblTranslationUsage' AND `COLUMN_NAME` = 'costPence'
);
SET @sql := IF(@c = 1, 'ALTER TABLE `tblTranslationUsage` DROP COLUMN `costPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `tblTranslationUsage`
    COMMENT='Translation requests per organisation per month (#762). The money side moved to tblProviderSpend (#993, migration 302). Read and written only by Portal\\Core\\Translation.';

INSERT INTO `tblMigrations` (`filename`) VALUES ('302_provider_spend_in_its_own_currency.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
