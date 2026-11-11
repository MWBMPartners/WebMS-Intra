-- =============================================================================
-- Migration 282: no currency is built in (#966, D-133)
-- =============================================================================
-- What this changes:
--
--   A. the nine `currency` columns   (tblPledgeCampaigns, tblGivingEntry,
--                                    tblProject, tblPayment, tblBankImports,
--                                    tblAssets, tblVenueAgreements,
--                                    tblVenueBookings, tblVenueInvoices) lose
--                                    their DEFAULT 'GBP'. Nothing stored changes.
--   B. org.currency                  the ONE currency setting, installation row
--                                    and per organisation. Made empty when
--                                    missing; given the old installation's
--                                    currency when that is one clear answer.
--   C. giving.currency, projects.currency, payments.currency, venues.currency
--                                    deleted: org.currency replaces all four.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- WebMS-Intra is a product used by customers anywhere. D-133 (the owner,
-- 11 November 2026): nothing is built in as Great Britain, and values that
-- follow from a country — currency named — are pre-selected from the chosen
-- country and stay changeable. Until now the four per-app settings were seeded
-- 'GBP' for every customer, and nine money columns defaulted to 'GBP', so a
-- writer that forgot the currency stored pounds: every new project was stored
-- as GBP whatever `projects.currency` said (`projects/manage-save.php` never
-- named the column; measured on 219cb616).
--
-- A: NO DEFAULT, NOT AN EMPTY ONE. Unlike a small group's country (migration
-- 279), a money record with the wrong currency is wrong money, so a writer
-- that forgets the currency must FAIL, loudly, under MySQL's strict mode,
-- rather than store anything at all. Empty is still storable on purpose:
-- it is what a writer names when the organisation has no currency chosen, and
-- such an amount is shown as a plain number.
--
-- B and C: ONE CURRENCY PER ORGANISATION. The four settings could disagree
-- inside one organisation (a project in its own currency, its pledge paid in
-- payments.currency, the gift that payment made labelled with
-- giving.currency), and none could be set per organisation. See the header of
-- web/_core/Currency.php.
--
-- -----------------------------------------------------------------------------
-- WHAT IT DELIBERATELY KEEPS: a currency already in force
-- -----------------------------------------------------------------------------
-- An installation whose four settings say GBP cannot be told from one in
-- Britain that left them so on purpose, and the payments page and the venues
-- page have always written two of them. So, as migrations 273, 274 and 279 do
-- for a time zone, a place and a country, the value in force is KEPT: when the
-- installation-wide rows of the four old settings that hold anything all hold
-- the SAME currency, and it is one the portal can use (counted in hundredths:
-- the list below is word for word Portal\Core\Currency::choices()), that
-- becomes the installation's org.currency (only when org.currency is still
-- empty). A code NOT on that list — a yen, or something typed by hand into
-- the general Settings editor — is NOT carried: the four old keys are deleted
-- all the same, the installation has no currency, and Organisation settings
-- asks for one. Amounts already stored keep the currency stored on them
-- (migration 283), so nothing is relabelled either way. When they DISAGREE, nothing is carried
-- over: picking one would be a guess about money, and the organisation page
-- then asks. (There is no customer installation yet, the owner said on
-- 2 November 2026, D-135; this is written the way a real upgrade would need.)
-- The end-to-end harness names the result: an upgraded database holds GBP
-- where a fresh install holds nothing.
--
-- An ORGANISATION's own `venues.currency` row (the venues settings page wrote
-- one per organisation) is NOT carried into that organisation's org.currency:
-- the organisation's gifts, claims and projects were in the installation's
-- currency at the same time, so a venues-only currency is a disagreement, and
-- picking it would relabel the rest. The venue records already made keep the
-- currency stored on each of them.
--
-- The old seeds are also taken out of migrations 094, 096, 097 and 170,
-- because the installer replays every migration on top of the install script,
-- and a seed left there would put GBP back on a fresh install.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `MODIFY COLUMN IF EXISTS` (see .claude/rules/database.md). Each MODIFY runs
-- only while the column's COMMENT is not yet the new one, so the file is SAFE
-- TO RUN TWICE; the comment is the marker rather than the default, because
-- MySQL and MariaDB spell a text default differently in information_schema
-- (migration 279's reason). The definition is word for word what
-- `full_schema.sql` says. B and C are safe twice by construction.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/966
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 💷 A. The nine currency columns: no default
-- #############################################################################

SET @cur_comment := 'ISO 4217 code: the organisation''s currency when the row was made (#966). No default: every writer names it. Empty = none chosen';
SET @cur_def := CONCAT('CHAR(3) NOT NULL COMMENT ', QUOTE(@cur_comment));

SET @t := 'tblPledgeCampaigns';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblGivingEntry';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblProject';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblPayment';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblBankImports';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblAssets';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblVenueAgreements';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblVenueBookings';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'tblVenueInvoices';
SET @needs := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = @t AND `COLUMN_NAME` = 'currency' AND `COLUMN_COMMENT` <> @cur_comment);
SET @sql := IF(@needs = 1, CONCAT('ALTER TABLE `', @t, '` MODIFY COLUMN `currency` ', @cur_def), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 🏢 B. org.currency: the installation's row, keeping a currency in force
-- #############################################################################
-- The row first, empty, exactly as full_schema.sql seeds it.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
VALUES (NULL, 'org.currency', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Then the old installation's ONE currency, only when every old setting that
-- holds anything holds the same three letters, and only into an empty row.
-- The derived table is read in full before the UPDATE writes (MySQL refuses
-- an UPDATE that reads its own table directly: ERROR 1093).
UPDATE `tblSettings` AS `dest`
  JOIN (
        SELECT MIN(UPPER(TRIM(`settingValue`))) AS `code`
          FROM `tblSettings`
         WHERE `siteID` IS NULL
           AND `settingKey` IN ('giving.currency', 'projects.currency', 'payments.currency', 'venues.currency')
           AND TRIM(`settingValue`) <> ''
        HAVING COUNT(DISTINCT UPPER(TRIM(`settingValue`))) = 1
       ) AS `old` ON `old`.`code` IN ('AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BMD', 'BND',
        'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS',
        'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR', 'JMD', 'KES',
        'KGS', 'KHR', 'KPW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT',
        'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
        'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY',
        'TTD', 'TWD', 'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'VES', 'WST', 'XCD', 'XCG', 'YER', 'ZAR', 'ZMW',
        'ZWG')
   SET `dest`.`settingValue` = `old`.`code`
 WHERE `dest`.`settingKey` = 'org.currency'
   AND `dest`.`siteID` IS NULL
   AND `dest`.`settingValue` = '';

-- #############################################################################
-- 🗑️ C. The four per-app settings go: org.currency replaces them
-- #############################################################################
-- Every row, at every level. Nothing reads them after #966, and a setting
-- nothing reads is "a lie with a Save button" in the general Settings editor
-- (#671's lesson).

DELETE FROM `tblSettings`
 WHERE `settingKey` IN ('giving.currency', 'projects.currency', 'payments.currency', 'venues.currency');

-- #############################################################################
-- 📝 D. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('282_no_built_in_currency.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
