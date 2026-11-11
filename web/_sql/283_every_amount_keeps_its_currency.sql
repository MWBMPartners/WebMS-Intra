-- =============================================================================
-- Migration 283: every amount keeps its own currency (#966, D-139)
-- =============================================================================
-- What this changes:
--
--   A. A `currency` column beside the money of FOURTEEN tables that kept only a
--      number: an expense claim, its lines and its payments; a member's pledge
--      to a campaign; a pledge to a project; a collection count and its named
--      envelopes; a giving statement's record; a bank statement line; an
--      asset's repair and its valuation history; a room's hire rate; a venue
--      invoice's lines and its payments. An expense line also gains
--      `quotedCurrency` and `quotedLineTotal`: what the line was QUOTED in, kept
--      beside the CONVERTED amount (`lineTotal`, in `currency`), which is what
--      is approved and reimbursed.
--   B. Rows that belong to an organisation directly take that organisation's
--      currency as it is NOW.
--   C. Rows that belong to a parent take the PARENT's own currency (a pledge
--      its campaign's, a repair its asset's, an invoice line its invoice's).
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- The owner, 11 November 2026 (D-139): "We need a currency entry for every
-- record, especially as whilst we may work on one currency (say GBP) some
-- expenses may be quoted in another (USD) and claims will need provide evidence
-- in the originally quoted currency and the converted (forex) domestic currency
-- (GBP) for record and audit accuracy". Until now these amounts meant "in
-- whatever currency the organisation uses", so changing an organisation's
-- currency would have relabelled every one of them, and every total added
-- different currencies together. With a currency on each row, a currency can
-- change (D-133, D-139) and old records keep theirs.
--
-- WHY DEFAULT '' AND NOT NO DEFAULT (round 1 gave the nine older currency
-- columns NO default). A whole-database backup taken before this migration has
-- none of these columns, and a restore inserts only the columns a snapshot
-- holds: with no default, every such restore would fail on these fourteen
-- tables. '' means "not recorded"; the money pages show such an amount as a
-- plain number, and Organisation settings offers to label an organisation's
-- unlabelled amounts with a currency an administrator chooses — never guessed.
-- Every writer in the portal names the currency.
--
-- WHAT "THE ORGANISATION'S CURRENCY NOW" MEANS: its own org.currency row when
-- that holds a currency the portal can use (counted in hundredths), else the
-- installation's, else nothing. The list of usable codes is written out below,
-- word for word Portal\Core\Currency::choices(); a stored code that is not on it
-- (a yen, or a typing mistake) is never copied onto money.
--
-- An expense line's quoted currency becomes its claim's currency and its quoted
-- total its line total: until D-139 every line was quoted in the claim's own
-- money, so that is what each one was.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE for the columns, because MySQL 8 rejects
-- MariaDB's `ADD COLUMN IF NOT EXISTS` (see .claude/rules/database.md). Each
-- fill touches only rows still holding '' (or a missing quoted total), so the
-- file is SAFE TO RUN TWICE. Every identifier is backticked. The definitions are
-- word for word what `full_schema.sql` says. Tested on MySQL 8.0.36 by the
-- end-to-end harness; the SQL keeps to the MySQL 8.0 ∩ MariaDB convention.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/966
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 💷 A. The new columns (each added only while it is missing: safe to run twice)
-- #############################################################################

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaims' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblExpenseClaims` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `totalAmount`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaimItems' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblExpenseClaimItems` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `lineTotal`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaimItems' AND `COLUMN_NAME` = 'quotedCurrency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblExpenseClaimItems` ADD COLUMN `quotedCurrency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''The currency the line was QUOTED in (D-139): unitCost and quotedLineTotal are in it. lineTotal is the converted amount, in `currency`'' AFTER `currency`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaimItems' AND `COLUMN_NAME` = 'quotedLineTotal');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblExpenseClaimItems` ADD COLUMN `quotedLineTotal` DECIMAL(10,2) DEFAULT NULL COMMENT ''quantity x unitCost, in quotedCurrency (D-139). Kept for audit beside the converted lineTotal, which is what is approved and reimbursed'' AFTER `quotedCurrency`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblExpenseClaimPayments' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblExpenseClaimPayments` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `payAmount`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblResource' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblResource` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `hourlyRatePence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblPledges' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblPledges` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `amountPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblGivingStatementLog' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblGivingStatementLog` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `giftAidPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblProjectPledge' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblProjectPledge` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `amountPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblCountSessions' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblCountSessions` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `envelopeTotal`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblCountEnvelopes' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblCountEnvelopes` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `amount`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblBankTxns' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblBankTxns` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `amountPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblAssetMaintenance' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblAssetMaintenance` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `costPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblAssetValueHistory' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblAssetValueHistory` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `currentValuePence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblVenueInvoiceLines' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblVenueInvoiceLines` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `amountPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'tblVenueInvoicePayments' AND `COLUMN_NAME` = 'currency');
SET @sql := IF(@needs = 0, 'ALTER TABLE `tblVenueInvoicePayments` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT '''' COMMENT ''ISO 4217 code of this row''''s money (D-139, #966). Empty = not recorded (an older row): an administrator labels such rows on Organisation settings'' AFTER `amountPence`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🏢 B. Rows that belong to an organisation directly: its currency now
-- #############################################################################
-- The organisation's own org.currency row if it holds a currency the portal can
-- use, else the installation's, else nothing (the row stays '' and an
-- administrator labels it on Organisation settings). The derived table `c` is
-- read in full first, so no statement reads the table it updates (ERROR 1093).

UPDATE `tblExpenseClaims` AS `t`
   SET `t`.`currency` = COALESCE((SELECT `c`.`code` FROM (
            SELECT UPPER(TRIM(`s`.`settingValue`)) AS `code`, `s`.`siteID` AS `siteID`
              FROM `tblSettings` `s` WHERE `s`.`settingKey` = 'org.currency'
        ) AS `c`
        WHERE (`c`.`siteID` = `t`.`siteID` OR `c`.`siteID` IS NULL)
          AND `c`.`code` IN ('AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BMD', 'BND',
        'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES',
        'KGS', 'KHR', 'KPW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT',
        'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
        'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY',
        'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'VES', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW',
        'ZWG')
        ORDER BY `c`.`siteID` IS NULL
        LIMIT 1), '')
 WHERE `t`.`currency` = '';

UPDATE `tblResource` AS `t`
   SET `t`.`currency` = COALESCE((SELECT `c`.`code` FROM (
            SELECT UPPER(TRIM(`s`.`settingValue`)) AS `code`, `s`.`siteID` AS `siteID`
              FROM `tblSettings` `s` WHERE `s`.`settingKey` = 'org.currency'
        ) AS `c`
        WHERE (`c`.`siteID` = `t`.`siteID` OR `c`.`siteID` IS NULL)
          AND `c`.`code` IN ('AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BMD', 'BND',
        'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES',
        'KGS', 'KHR', 'KPW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT',
        'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
        'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY',
        'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'VES', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW',
        'ZWG')
        ORDER BY `c`.`siteID` IS NULL
        LIMIT 1), '')
 WHERE `t`.`currency` = '';

UPDATE `tblGivingStatementLog` AS `t`
   SET `t`.`currency` = COALESCE((SELECT `c`.`code` FROM (
            SELECT UPPER(TRIM(`s`.`settingValue`)) AS `code`, `s`.`siteID` AS `siteID`
              FROM `tblSettings` `s` WHERE `s`.`settingKey` = 'org.currency'
        ) AS `c`
        WHERE (`c`.`siteID` = `t`.`siteID` OR `c`.`siteID` IS NULL)
          AND `c`.`code` IN ('AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BMD', 'BND',
        'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES',
        'KGS', 'KHR', 'KPW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT',
        'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
        'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY',
        'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'VES', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW',
        'ZWG')
        ORDER BY `c`.`siteID` IS NULL
        LIMIT 1), '')
 WHERE `t`.`currency` = '';

UPDATE `tblCountSessions` AS `t`
   SET `t`.`currency` = COALESCE((SELECT `c`.`code` FROM (
            SELECT UPPER(TRIM(`s`.`settingValue`)) AS `code`, `s`.`siteID` AS `siteID`
              FROM `tblSettings` `s` WHERE `s`.`settingKey` = 'org.currency'
        ) AS `c`
        WHERE (`c`.`siteID` = `t`.`siteID` OR `c`.`siteID` IS NULL)
          AND `c`.`code` IN ('AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BMD', 'BND',
        'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES',
        'KGS', 'KHR', 'KPW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT',
        'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
        'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY',
        'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'VES', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW',
        'ZWG')
        ORDER BY `c`.`siteID` IS NULL
        LIMIT 1), '')
 WHERE `t`.`currency` = '';

-- #############################################################################
-- 🔗 C. Rows that belong to a parent: the parent's own currency
-- #############################################################################

UPDATE `tblExpenseClaimItems` AS `k` JOIN `tblExpenseClaims` AS `p` ON `p`.`claimID` = `k`.`claimID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblExpenseClaimPayments` AS `k` JOIN `tblExpenseClaims` AS `p` ON `p`.`claimID` = `k`.`claimID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblPledges` AS `k` JOIN `tblPledgeCampaigns` AS `p` ON `p`.`campaignID` = `k`.`campaignID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblProjectPledge` AS `k` JOIN `tblProject` AS `p` ON `p`.`projectID` = `k`.`projectID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblCountEnvelopes` AS `k` JOIN `tblCountSessions` AS `p` ON `p`.`countSessionID` = `k`.`countSessionID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblBankTxns` AS `k` JOIN `tblBankImports` AS `p` ON `p`.`importID` = `k`.`importID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblAssetMaintenance` AS `k` JOIN `tblAssets` AS `p` ON `p`.`assetID` = `k`.`assetID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblAssetValueHistory` AS `k` JOIN `tblAssets` AS `p` ON `p`.`assetID` = `k`.`assetID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblVenueInvoiceLines` AS `k` JOIN `tblVenueInvoices` AS `p` ON `p`.`invoiceID` = `k`.`invoiceID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

UPDATE `tblVenueInvoicePayments` AS `k` JOIN `tblVenueInvoices` AS `p` ON `p`.`invoiceID` = `k`.`invoiceID`
   SET `k`.`currency` = `p`.`currency`
 WHERE `k`.`currency` = '';

-- An expense line was always quoted in the claim's own currency until D-139:
-- the quoted currency and the quoted total are the line's own figures.
UPDATE `tblExpenseClaimItems`
   SET `quotedCurrency` = `currency`
 WHERE `quotedCurrency` = '' AND `currency` <> '';
UPDATE `tblExpenseClaimItems`
   SET `quotedLineTotal` = `lineTotal`
 WHERE `quotedLineTotal` IS NULL AND `lineTotal` IS NOT NULL;

-- #############################################################################
-- 📝 D. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('283_every_amount_keeps_its_currency.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
