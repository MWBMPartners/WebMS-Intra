-- =============================================================================
-- Migration 220: the activity log and the consent log keep a fingerprint of
--                the browser session, not its identifier; and every two-step
--                backup code made before this is deleted (#622)
--
-- Path: web/_sql/220_activity_log_fingerprints.sql
--
-- WHY THIS EXISTS
--   Every activity record used to store the browser session's own identifier
--   and a copy of everything in the session. The identifier IS the sign-in
--   cookie: whoever could read the table, a backup or a snapshot could be
--   that person while the session lived. The copy held whatever any page had
--   put in the session, which is how every set of two-step backup codes came
--   to be in the log in readable text. The consent log stored the identifier
--   too. The code no longer writes either: see Portal\Core\Logger,
--   sessionFingerprint() and sessionFacts().
--
-- WHAT THIS FILE DOES (only things that are quick however large the log is)
--   A. Says, in the comment of each of three columns, what the column holds
--      now. No row is rewritten for that.
--   B. Turns every identifier in the CONSENT log into its fingerprint. That
--      log holds one row for each cookie decision, so it is small.
--   C. Writes the mark "every activity record has been gone through" where
--      the activity log is EMPTY, so that a new installation is not asked to
--      clean a log that never held anything old.
--   D. Deletes EVERY two-step backup code, ONCE (the owner's decision,
--      13 October 2026: "Yes, delete them"). Nobody was ever shown one, and
--      the log held all of them in readable text. People make a new set from
--      their Account page.
--   E. Records itself.
--
-- WHAT IT DOES NOT DO: CLEAN THE ACTIVITY LOG'S OWN RECORDS
--   Measured on 13 October 2026 on 500,000 made-up records (a table of about
--   1 GB, MySQL 8.0.36): as one statement that took 176 seconds. A web request
--   that long is cut off on most shared hosting, the database undoes it all,
--   and the upgrade would never finish. So the records are cleaned in small
--   pieces by Portal\Core\ActivityLogCleaner, which the Upgrade page calls
--   after this file and offers again until none are left. The reasons, and
--   what was tried and rejected, are at the top of that class.
--
-- THE FINGERPRINT
--   "sha256:" and then the SHA-256 of the identifier, in hexadecimal. It is
--   the same value Logger::fingerprintOf() makes, so a session that began
--   before the upgrade keeps ONE fingerprint across old and new records.
--   A colon can never be in a real identifier (the portal drops any session
--   cookie that is not letters, digits, comma or hyphen), so a value that
--   starts "sha256:" is a fingerprint and nothing else.
--
-- SAFE TO RUN TWICE, AND WHY EACH PART IS
--   A. Each comment is written only while the column does not carry exactly
--      that comment. The column's own type and character set are read from
--      the database and written back unchanged, so the statement can never
--      change the shape of a column, on any database, and never rebuilds the
--      table. A column that is not as expected (it may not be empty) is left
--      alone: a comment is not worth a rebuild.
--   B. Only a value that is not empty and does not already start "sha256:"
--      is changed. A second run would otherwise fingerprint the fingerprints.
--   C. Written only where there is no such row and no activity record.
--   D. RUNS ONLY WHILE THIS FILE IS NOT YET RECORDED AS RUN. That is the whole
--      of the "already done" test, and it matters more than it looks: a
--      second run, or a run next year, must never delete the codes people
--      have made since. The record is this file's own row in tblMigrations.
--      WHY THAT ROW and not a setting: a setting can be changed or removed on
--      the Settings page, and this row cannot be touched from any ordinary
--      page. It is not out of reach altogether, though, and that matters:
--      see "WHAT THIS CANNOT DO" below for the three ways the row can move
--      and what each one costs. And it travels with a backup: a WHOLE backup
--      taken BEFORE this upgrade and put back AFTER it brings back the old
--      codes and has no such row, so the upgrade runs this file again and the
--      codes go again. (A whole backup. Putting ONE table back on its own is
--      a different matter, and is in that list below.) D is the last step
--      before E on purpose: nothing else stands between the delete and the
--      record.
--
-- THE INSTALL SCRIPT, AND THE TRAP IT HAS TO AVOID (met on 13 October 2026,
-- #604)
--   web/_sql/full_schema.sql records every migration as run. It is not only
--   run on an empty database: the installer's "carry on with what is there"
--   choice runs it over an OLDER database and then runs every numbered file.
--   If it recorded this file there, step D would find its row, take the
--   database for already done, and leave every old backup code alive. So the
--   install script records this file ONLY IN A DATABASE THAT HOLDS NO BACKUP
--   CODE, where D has nothing to do. In an older database with codes it
--   records nothing, D does its work here, and E writes the row.
--   The five cases, each run by tools/activity-log-selftest.php and by
--   tools/e2e-migrations/run.sh:
--     a new installation      no code exists; the install script records this
--                             file; D deletes nothing
--     an upgrade              not recorded; D deletes every code; E records
--     "carry on", older db    not recorded by the install script; as an upgrade
--     a second run            recorded; D deletes nothing
--     an old backup put back  its record went with the backup; as an upgrade
--
-- WHAT THIS CANNOT DO
--   - Make an authenticator KEY safe. An old record written while a set-up
--     page was open holds the key in readable text. Deleting a row does not
--     help: backups made earlier still hold it. ActivityLogCleaner finds
--     those accounts and records each one; the person has to set two-step
--     sign-in up again.
--   - Clean a backup, a snapshot or any other copy made before it ran,
--     including the snapshot the Upgrade page takes just before running it.
--   - Tell a database whose backup codes were all made after the fix from
--     one whose codes are old. There is nothing in a code's row to tell them
--     apart, and this file does not try: the WHOLE of its "already done"
--     test is its own row in tblMigrations. So anything that takes that row
--     away makes this file delete every code again, and anything that puts
--     the row back makes it delete none. Three ways to that, and all three
--     are real:
--       * somebody removes the row BY HAND and runs this file again. Every
--         code anybody has made since is deleted.
--       * the Backup page's restore of ONE TABLE (Admin, Maintenance,
--         Backup) puts tblMigrations back from a backup taken before the
--         upgrade. The row goes with it, so the next press of the Upgrade
--         page deletes every code made since. Run on a real upgraded
--         database by the independent check of this package on 26 October
--         2026: one code made after the upgrade, then none.
--       * the same one-table restore of tblTotpBackupCodes, from a backup
--         taken before the upgrade, brings the OLD codes back ALIVE while
--         the row stays, so this file leaves them where they are. Those
--         codes were readable in the old log and are readable in every
--         older backup. Run the same day: two old codes back, and the next
--         run of this file left them.
--     WHAT IS BEING SAID HERE, AND WHAT IS NOT. This is what the row means,
--     not a list of what the Backup page allows: as these words are written
--     that page refuses none of those three tables in a one-table restore,
--     and whether it should refuse or warn is the owner's decision, raised
--     with #622 on 27 October 2026. These words hold whichever way that goes.
--     The hazard is older than this file and belongs to one-table restores
--     in general. It was written here as "only a hand edit can do that",
--     which was wrong: a supported button reaches the same place.
--   - Step B is ONE statement. Measured here: 19,600 rows in 3 seconds. A
--     consent log of hundreds of thousands of rows would take minutes; none
--     is expected, because a row is written only when somebody answers the
--     cookie question.
--   - Nothing here has been run on MariaDB; no test in this repository
--     covers it.
--
-- NOTE for editors: never put two hyphens together inside a quoted string in
-- this file. check_schema_seed_parity.py reads them as the start of a comment.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/622
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- 🏷️  A. Three columns say what they hold now
-- #############################################################################
-- Each block reads the column's type, character set and collation from the
-- database and writes them back as they are, with the new comment. @def stays
-- empty, and nothing is run, when the comment is already there or the column
-- is not the nullable text column it is expected to be.

-- A1. tblActivityLogs.sessionID
SET @def := (
    SELECT CONCAT(COLUMN_TYPE, ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblActivityLogs' AND COLUMN_NAME = 'sessionID'
       AND IS_NULLABLE = 'YES' AND CHARACTER_SET_NAME IS NOT NULL
       AND (COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL')
       AND COLUMN_COMMENT <> 'A FINGERPRINT of the browser session, never its identifier (which is the sign-in cookie): the text sha256: followed by the SHA-256 of the identifier in hexadecimal. The same for every record of one session. It cannot be turned back and is no use for signing in. Written only by Logger::sessionFingerprint() (#622). Empty when there was no session'
);
SET @sql := IF(@def IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE `tblActivityLogs` MODIFY COLUMN `sessionID` ', @def, ' NULL COMMENT ''A FINGERPRINT of the browser session, never its identifier (which is the sign-in cookie): the text sha256: followed by the SHA-256 of the identifier in hexadecimal. The same for every record of one session. It cannot be turned back and is no use for signing in. Written only by Logger::sessionFingerprint() (#622). Empty when there was no session''')
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A2. tblActivityLogs.sessionDataSnapshot
SET @def := (
    SELECT CONCAT(COLUMN_TYPE, ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblActivityLogs' AND COLUMN_NAME = 'sessionDataSnapshot'
       AND IS_NULLABLE = 'YES' AND CHARACTER_SET_NAME IS NOT NULL
       AND (COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL')
       AND COLUMN_COMMENT <> 'A short list of facts about the session, as JSON that starts with format facts-1: signed in or not, the account, the organisation chosen, an account waiting for its second step, an account at a kiosk. Whole numbers and true or false only. NEVER a copy of the session. Written only by Logger::sessionFacts() (#622). Empty when there was no session'
);
SET @sql := IF(@def IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE `tblActivityLogs` MODIFY COLUMN `sessionDataSnapshot` ', @def, ' NULL COMMENT ''A short list of facts about the session, as JSON that starts with format facts-1: signed in or not, the account, the organisation chosen, an account waiting for its second step, an account at a kiosk. Whole numbers and true or false only. NEVER a copy of the session. Written only by Logger::sessionFacts() (#622). Empty when there was no session''')
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A3. tblConsentLog.sessionID
SET @def := (
    SELECT CONCAT(COLUMN_TYPE, ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblConsentLog' AND COLUMN_NAME = 'sessionID'
       AND IS_NULLABLE = 'YES' AND CHARACTER_SET_NAME IS NOT NULL
       AND (COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL')
       AND COLUMN_COMMENT <> 'A FINGERPRINT of the browser session that made this decision, never its identifier: the text sha256: followed by the SHA-256 of the identifier in hexadecimal. Written only by Logger::sessionFingerprint() (#622). Empty when there was no session'
);
SET @sql := IF(@def IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE `tblConsentLog` MODIFY COLUMN `sessionID` ', @def, ' NULL COMMENT ''A FINGERPRINT of the browser session that made this decision, never its identifier: the text sha256: followed by the SHA-256 of the identifier in hexadecimal. Written only by Logger::sessionFingerprint() (#622). Empty when there was no session''')
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔏  B. The consent log: every identifier becomes its fingerprint
-- #############################################################################
-- Only a value that is not empty and not already a fingerprint.

UPDATE `tblConsentLog`
   SET `sessionID` = CONCAT('sha256:', SHA2(`sessionID`, 256))
 WHERE `sessionID` IS NOT NULL
   AND `sessionID` <> ''
   AND `sessionID` NOT LIKE 'sha256:%';


-- #############################################################################
-- 🧹  C. An empty activity log has nothing old in it
-- #############################################################################
-- Portal\Core\ActivityLogCleaner goes through the old records and keeps "how
-- far" in this setting; the word all means every record has been gone
-- through. Where the log is empty it is written here, so that the health page
-- of a new installation does not ask anybody to clean it. Where the log holds
-- ANY record nothing is written, and the cleaner starts at the first one. The
-- install script has the same statement, with the same test, for the same
-- reason as step D: it is also run over older databases.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
SELECT NULL, 'activityLog.cleanedThrough', 'all', '', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `tblActivityLogs` LIMIT 1)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- #############################################################################
-- 🔑  D. Every two-step backup code made before this: deleted, ONCE
-- #############################################################################
-- Only while this file is not yet recorded as run: see "SAFE TO RUN TWICE"
-- above. Keep this the last step before the record below.

DELETE FROM `tblTotpBackupCodes`
 WHERE NOT EXISTS (
           SELECT 1 FROM (
               SELECT `filename` FROM `tblMigrations` WHERE `filename` = '220_activity_log_fingerprints.sql'
           ) AS alreadyRun
       );


-- #############################################################################
-- 📝  E. Record this migration
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('220_activity_log_fingerprints.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
