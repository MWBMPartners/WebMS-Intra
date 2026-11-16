-- =============================================================================
-- Migration 301: exchange rates (#993, package A; D-173, D-192)
-- =============================================================================
-- What this adds:
--
--   A. tblExchangeRate        the latest daily rates from the European Central
--                             Bank, one row per currency
--   B. tblExchangeRateFetch   one row: when the fetch last ran and why it failed
--   C. tblExchangeRateEntered rates an administrator typed, the fallback
--   D. tblSettings            exchangeRates.fetch, installation-wide, '1'
--   E. tblRoutes              admin/exchange-rates and admin/exchange-rates/save
--
-- WHY
-- D-173 records an outside company's charge in that company's own currency and
-- converts only to compare with a spending limit. D-192 says where the rate
-- comes from: fetched daily, with an administrator's typed rate as the
-- fallback, and spending refused when there is neither. This migration holds
-- only the rates; nothing here changes a limit (package B does that).
--
-- No column is called exactly `currency`: tools/currency-selftest.php (B4)
-- would then insist the table is one of an organisation's own money tables.
--
-- SAFE TO RUN TWICE
-- CREATE TABLE IF NOT EXISTS, and every INSERT is ON DUPLICATE KEY UPDATE.
-- Every identifier is backticked. Each statement is word for word what
-- full_schema.sql says, and the two must agree.
-- MySQL 8.0 and MariaDB: nothing here needs a version-specific form.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/993
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
-- @license    All Rights Reserved
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblExchangeRate` (
    `rateCurrency` CHAR(3)        NOT NULL COMMENT 'The currency, as its three capital letters, for example USD',
    `perEuro`      DECIMAL(20,10) NOT NULL COMMENT 'How much of this currency one euro buys, exactly as the European Central Bank wrote it (kept as text on the way in, never as a floating-point number)',
    `rateDate`     DATE           NOT NULL COMMENT 'The day the European Central Bank published this rate: a calendar date, not a moment',
    `fetchedAtUtc` DATETIME       NOT NULL COMMENT 'The moment the portal stored it, in UTC (UTC_TIMESTAMP())',
    PRIMARY KEY (`rateCurrency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The latest daily exchange rates from the European Central Bank, one row per currency (#993, D-192). Written only by Portal\\Core\\ExchangeRates.';

CREATE TABLE IF NOT EXISTS `tblExchangeRateFetch` (
    `fetchID`        TINYINT     NOT NULL COMMENT 'Always 1: there is one row, holding the state of the daily fetch',
    `lastAttemptUtc` DATETIME             DEFAULT NULL COMMENT 'The moment the portal last tried to fetch the rates, in UTC, whether or not it worked',
    `lastSuccessUtc` DATETIME             DEFAULT NULL COMMENT 'The moment the portal last fetched the rates successfully, in UTC',
    `lastProblem`    VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'One fixed word saying why the last attempt failed (http, capped, unreadable, no-xml, refused), or empty when it worked. Never the reply itself',
    PRIMARY KEY (`fetchID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The state of the daily exchange-rate fetch: one row (#993).';
INSERT INTO `tblExchangeRateFetch` (`fetchID`, `lastAttemptUtc`, `lastSuccessUtc`, `lastProblem`) VALUES (1, NULL, NULL, '')
ON DUPLICATE KEY UPDATE `fetchID` = `fetchID`;

CREATE TABLE IF NOT EXISTS `tblExchangeRateEntered` (
    `enteredID`    INT            NOT NULL AUTO_INCREMENT,
    `siteID`       INT                     DEFAULT NULL COMMENT 'The organisation that typed this rate, or empty for the installation. No foreign key on purpose: a removed organisation\'s rows are cleared by nothing today, which is harmless',
    `siteScope`    INT AS (COALESCE(`siteID`, -1)) VIRTUAL COMMENT 'Worked out by the database: the organisation number, or -1 for the installation, so the unique key below binds installation rows too (#487). VIRTUAL, not STORED, as tblSettings.siteScope',
    `fromCurrency` CHAR(3)        NOT NULL COMMENT 'The currency being converted from',
    `toCurrency`   CHAR(3)        NOT NULL COMMENT 'The currency being converted to: 1 of the from-currency is this many of the to-currency',
    `rate`         DECIMAL(20,10) NOT NULL COMMENT 'The rate an administrator typed, as exact digits',
    `asOf`         DATE           NOT NULL COMMENT 'The day the administrator says the rate is from: a calendar date',
    `enteredAtUtc` DATETIME       NOT NULL COMMENT 'The moment it was saved, in UTC',
    PRIMARY KEY (`enteredID`),
    UNIQUE KEY `uq_er_pair` (`siteScope`, `fromCurrency`, `toCurrency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Exchange rates typed by an administrator, used when the fetched ones are missing or stale (#993, D-192). Holds no personal data: who typed one is in the activity log.';

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'exchangeRates.fetch', '1', '1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/exchange-rates',      'admin/exchange-rates/index.php', 1),
    ('admin/exchange-rates/save', 'admin/exchange-rates/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('301_exchange_rates.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
