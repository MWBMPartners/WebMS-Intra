-- =============================================================================
-- Migration 254: translation counts the NUMBER of requests as well as money (#762)
-- =============================================================================
-- One new table, one new setting, one backfill. Nothing is dropped.
--
-- -----------------------------------------------------------------------------
-- WHAT IS WRONG, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- The monthly translation budget compared what each translation company
-- REPORTS spending. LibreTranslate reports nothing, so on that route the budget
-- could never stop anything: an organisation on a limit of one pound could
-- translate without end, and the LibreTranslate address ships pointing at a
-- hosted service that charges. The owner chose (D-059) to count the number of
-- requests as well, a second limit that works whatever a company reports.
--
-- -----------------------------------------------------------------------------
-- WHY A TABLE OF ITS OWN, AND NOT A COUNT OF THE CACHE
-- -----------------------------------------------------------------------------
-- `tblContentTranslation` is a CACHE: one row per piece, field and language,
-- rewritten in place. It can say how many pieces were translated, never how
-- many requests were made. Two presses at the same moment pay twice and leave
-- one row; a request that fails leaves no row though it may have been billed;
-- and nothing deletes or refreshes a cached row when its source is edited, so
-- the day that is fixed a count of rows would quietly fall behind the bill.
-- So one row here per organisation per month, counted at the moment a request
-- is made. `Portal\Core\Translation` is the ONE place it is written or read.
--
-- The money limit reads this table too from now on, so the two limits are
-- counted at the same moment, in the same row, for the same month.
--
-- -----------------------------------------------------------------------------
-- THE BACKFILL, AND WHY IT TAKES THE LARGER FIGURE
-- -----------------------------------------------------------------------------
-- Without it, the month this migration runs would start again from nothing,
-- and an organisation that had already spent its budget could spend it again.
-- The figures come from the cache rows that exist, not from a guess: each row
-- is at least one request that succeeded, and the pence are exactly what the
-- old monthly sum added up. They are a LOWER bound for the number of requests,
-- for the reasons above, and that is said rather than hidden.
--
-- Rows with no organisation are left out, as migration 250 decided: they
-- cannot be attributed without guessing, and a guess moves money between two
-- parties. The month is read from `translatedAt`, which was written in the
-- database session zone, while this table counts months in UTC; at a month
-- boundary a row may land in the neighbouring month. Accepted.
--
-- Safe to run twice: on a second run each figure becomes the LARGER of what is
-- there and what the cache says, so requests counted since the first run are
-- never lost and nothing is counted twice.
--
-- NO FOREIGN KEY, deliberately, for the reason migration 250 gives: what an
-- installation was charged should outlive the organisation that caused it.
-- =============================================================================

-- #############################################################################
-- 🧾 A. The record
-- #############################################################################

CREATE TABLE IF NOT EXISTS `tblTranslationUsage` (
    `siteID`        INT  NOT NULL COMMENT 'The organisation whose members asked for these translations. Never empty: a key with an empty part does not hold (#487), and a row nobody owns would count against nobody',
    `monthStartUtc` DATE NOT NULL COMMENT 'The first day of the calendar month, IN UTC, that these requests were made in',
    `calls`         INT  NOT NULL DEFAULT 0 COMMENT 'How many requests were sent to the translation company this month, counted just BEFORE each is sent, whether or not an answer then came back (#762). A request never sent, because no key is set or the provider is unknown, is not counted',
    `costPence`     INT  NOT NULL DEFAULT 0 COMMENT 'What the translation company reported those requests cost, in pence. LibreTranslate reports nothing, so on that route this stays 0 and only `calls` can limit use',
    PRIMARY KEY (`siteID`, `monthStartUtc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Translation requests and reported spend, per organisation per month (#762). Read and written only by Portal\\Core\\Translation.';

-- #############################################################################
-- 🔢 B. The setting
-- #############################################################################
-- 1,000 requests a month per organisation; why that figure is written beside
-- `Translation::DEFAULT_CALL_CAP`. 0 means no limit. Installation-wide, like
-- every other `translation.*` key, and capped for an organisation by
-- `SettingLimit` (D-013).

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
VALUES (NULL, 'translation.monthCallCap', '1000', '1000', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 🔁 C. The backfill
-- #############################################################################
-- The grouped figures are worked out in a derived table under names of their
-- own, because `siteID` and `costPence` exist in BOTH tables and a bare name in
-- the update clause of an INSERT ... SELECT can be ambiguous.

INSERT INTO `tblTranslationUsage` (`siteID`, `monthStartUtc`, `calls`, `costPence`)
SELECT `b`.`bSite`, `b`.`bMonth`, `b`.`bCalls`, `b`.`bPence`
  FROM (
        SELECT `ct`.`siteID`                                 AS `bSite`,
               DATE_FORMAT(`ct`.`translatedAt`, '%Y-%m-01')  AS `bMonth`,
               COUNT(*)                                      AS `bCalls`,
               COALESCE(SUM(`ct`.`costPence`), 0)            AS `bPence`
          FROM `tblContentTranslation` `ct`
         WHERE `ct`.`siteID` IS NOT NULL
         GROUP BY `ct`.`siteID`, DATE_FORMAT(`ct`.`translatedAt`, '%Y-%m-01')
       ) `b`
ON DUPLICATE KEY UPDATE
    `calls`     = GREATEST(`tblTranslationUsage`.`calls`,     VALUES(`calls`)),
    `costPence` = GREATEST(`tblTranslationUsage`.`costPence`, VALUES(`costPence`));

-- #############################################################################
-- 📋 D. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('254_translation_counts_requests.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
