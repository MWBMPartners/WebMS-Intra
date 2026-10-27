-- =============================================================================
-- Path: _sql/221_activity_log_account_column.sql
-- Migration 221 — every activity record of a signed-in person names them in
-- its OWN column, so that an erasure can reach it (#626)
--
-- @package   Portal\Sql
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @version   1.0.0
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/626
-- =============================================================================
--
-- WHAT WAS WRONG
--   `Logger::activity()` takes the account number as an OPTIONAL argument, and
--   roughly half its callers under web/ left it out — including the line that
--   records an ordinary password sign-in, which runs AFTER the session already
--   holds the account. Those records went in with an EMPTY `userID` column
--   while the session details beside them said who the person was.
--
--   An erasure empties a column by matching on one (`WHERE userID = ?`), so it
--   never reached those records: the address the request came from, the
--   browser's name, the request's headers, the session's fingerprint and the
--   details naming the person all survived being erased. Two more things were
--   wrong for the same reason: a person's own data download and the "filter by
--   person" list on Admin, Activity both read that column, so a person's own
--   sign-ins were missing from both.
--
--   The code no longer does it: Portal\Core\Logger::activity() fills the
--   column from the session when the caller names nobody.
--
-- WHAT THIS FILE DOES, AND WHAT IT DELIBERATELY DOES NOT
--   It does NOT go through the records itself. Filling them in needs each
--   record's session details read and judged one at a time, and the #622 work
--   measured one statement over 500,000 activity records taking 175 seconds —
--   long enough for a shared host to cut it off and the database to undo it,
--   with the upgrade possibly never finishing.
--
--   So it does ONE quick thing: it asks Portal\Core\ActivityLogCleaner to go
--   through the log again, by putting its "how far" mark back to the start.
--   That cleaner already walks the log in pieces inside a time budget, from a
--   button on the Upgrade page, and it gained the linking step in the same
--   change. The portal is NOT closed for it and the upgrade gate is untouched:
--   this is tidying that happens afterwards, at an administrator's pace.
--
-- WHY THE MARK IS PUT BACK RATHER THAN A SECOND MARK ADDED
--   Because the cleaner's existing step BLANKS every old whole-session copy,
--   and that copy is the only place a record written before #622 says whose
--   session it was. A separate pass running later would always arrive to find
--   the evidence gone. Linking has to happen in the same piece, before the
--   blanking — so there is one walk, one mark, and one button, not two.
--
-- SAFE TO RUN TWICE
--   The reset happens only while this file is not yet recorded in
--   tblMigrations (the same shape as migration 220 step D). Run again, it is
--   recorded, so nothing is reset and an administrator who has since finished
--   the cleaning is not sent back to the beginning.
--
-- THE TEST IS "IS THERE ANYTHING TO LINK", NOT "IS THE LOG EMPTY"
--   A record needs linking when it names nobody and still holds session
--   details. The install script below uses the SAME test for the same reason
--   migration 220 does: it is also loaded over older databases, and recording
--   this file where there IS something to link would stop the reset for ever.
-- =============================================================================


-- #############################################################################
-- ⏪  A. Ask the cleaner to go through the log again
-- #############################################################################
-- Only while this file is not yet recorded, and only where a record really
-- needs it. The row is made if it is not there (an installation older than
-- #622 may have none).

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
SELECT NULL, 'activityLog.cleanedThrough', '0', '', 0
  FROM DUAL
 WHERE EXISTS (
           SELECT 1 FROM `tblActivityLogs`
            WHERE `userID` IS NULL
              AND `sessionDataSnapshot` IS NOT NULL
              AND `sessionDataSnapshot` <> ''
            LIMIT 1
       )
   AND NOT EXISTS (
           SELECT 1 FROM (
               SELECT `filename` FROM `tblMigrations` WHERE `filename` = '221_activity_log_account_column.sql'
           ) AS alreadyRun
       )
ON DUPLICATE KEY UPDATE `settingValue` = '0';


-- #############################################################################
-- 📝  B. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('221_activity_log_account_column.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
