-- =============================================================================
-- Migration 213: what the SMTP mail route needs in the database (#592, part 2)
--
-- Path: web/_sql/213_smtp_mail_route.sql
--
-- WHY THIS EXISTS
--   Until now the portal could send e-mail only through Microsoft 365 or
--   Google. This package lets it send through an ordinary mailbox on a mail
--   server (SMTP), which is what most organisations on shared hosting have.
--   The code is in web/_core/MailRoutes.php and web/_core/Mailer.php; the
--   settings are typed at Admin, Integrations, Email.
--
-- WHAT IT DOES
--   A. Seeds six settings for the whole installation (not for one
--      organisation): the mail server's name, port, kind of encryption, the
--      mailbox's user name and password, and an hourly limit.
--      - The password row is seeded EMPTY and marked as a secret. The e-mail
--        page writes it, encrypted. The mail code uses a stored value only
--        when it is marked as a secret and decrypts.
--      - `mail.limit.perHour` is seeded empty, which means the portal applies
--        no limit of its own.
--      A second run may refresh the stored default. It never changes a value
--      somebody has saved, and never changes whether a row is marked secret.
--   B. Deletes two settings nothing reads: `email.provider` and `email.from`.
--      They were seeded by migration 067 for a page that was never built to
--      use them. The real names are `mail.provider` and
--      `mail.defaultFromAddress`. An address somebody typed into `email.from`
--      is lost; it never sent anything.
--   C. tblEmailLog gains two columns and a key:
--      - `sentAtUtc`: the moment of the attempt, in UTC. The hourly limit
--        counts on this. The older `sentAt` is written by the database
--        server's own clock, so on the night the clocks go back it repeats an
--        hour, and "the last hour" counted from it is wrong.
--      - `recipientCount`: how many addresses the message was for.
--      - `idx_emaillog_sent_utc`, so the count does not read the whole table.
--   D. Corrects the descriptions of two columns, `provider` and `httpCode`,
--      which named Microsoft 365 and Google only. Descriptions only: no
--      stored value changes.
--
-- SAFE TO RUN TWICE
--   A is "insert, or leave alone". B finds nothing the second time. Each
--   statement of C and D first asks the database whether its change is still
--   needed. The installer relies on this: it replays every numbered migration
--   after the install script.
--
-- WHAT IT DOES NOT DO
--   It does not choose SMTP: `mail.provider` is left exactly as it is.
--   Rows already in tblEmailLog get NO UTC moment (the column stays empty for
--   them), because the server's own clock cannot be turned back into UTC with
--   certainty. They are not counted by the hourly limit, so for the first
--   hour after this upgrade the limit can count too few.
--   Part D restates each column exactly as web/_sql/full_schema.sql declares
--   it. On a database whose column had been changed by hand, this puts it
--   back to that.
--   Nothing here has been run on MariaDB; no test in this repository covers it.
--
-- All structure changes use the information_schema + PREPARE form, because
-- MySQL 8 refuses MariaDB's `IF [NOT] EXISTS` on ALTER (error 1064). See
-- DEV_NOTES.md, "Portable DDL convention".
--
-- NOTE for editors: never put two hyphens together inside a quoted string in
-- this file. check_schema_seed_parity.py reads them as the start of a comment.
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/592
-- @package    Portal\Database
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================


-- #############################################################################
-- ⚙️  A. The six settings (installation-wide)
-- #############################################################################
-- The same shape migration 209 uses for its settings. Since migration 187 a
-- unique key covers installation-wide rows, so a second run cannot add a
-- duplicate.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'mail.smtp.host',     '',         '',         0),
    (NULL, 'mail.smtp.port',     '587',      '587',      0),
    (NULL, 'mail.smtp.security', 'starttls', 'starttls', 0),
    (NULL, 'mail.smtp.username', '',         '',         0),
    (NULL, 'mail.smtp.password', '',         '',         1),
    (NULL, 'mail.limit.perHour', '',         '',         0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🧹  B. Two settings nothing reads
-- #############################################################################

DELETE FROM `tblSettings` WHERE `settingKey` IN ('email.provider', 'email.from');


-- #############################################################################
-- 🗄️  C. tblEmailLog: a UTC moment, a recipient count, and a key
-- #############################################################################

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND COLUMN_NAME = 'sentAtUtc'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblEmailLog` ADD COLUMN `sentAtUtc` DATETIME DEFAULT NULL COMMENT ''A MOMENT IN UTC, written with UTC_TIMESTAMP(): when this attempt was recorded. The hourly limit counts on it. Empty for rows from before migration 213, which are not counted. (sentAt beside it is the database server''''s own clock, not UTC.)'' AFTER `sentAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND COLUMN_NAME = 'recipientCount'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblEmailLog` ADD COLUMN `recipientCount` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''How many addresses the message was for. The hourly limit adds these up'' AFTER `sentAtUtc`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND INDEX_NAME = 'idx_emaillog_sent_utc'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblEmailLog` ADD KEY `idx_emaillog_sent_utc` (`sentAtUtc`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- ✏️  D. Two column descriptions made true for SMTP
-- #############################################################################
-- Each counts 1 only when the column is there AND its description is not yet
-- the new one.
--
-- `provider` is restated WITHOUT naming its character rules, on purpose. The
-- column then takes the table's own (utf8mb4_general_ci, which is what the
-- table was created with here and in migration 176), exactly as on a fresh
-- install. Naming them was tried: MySQL 8.4 then records the column as having
-- rules of its own and describes it differently from a fresh install's,
-- although it behaves the same. MySQL 8.0 does not. Seen on 8.0.36 and 8.4.11.

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND COLUMN_NAME = 'provider'
      AND COLUMN_COMMENT <> 'The route the attempt was made through: ms365, ms365-shared, google or smtp'
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblEmailLog` MODIFY COLUMN `provider` VARCHAR(20) NOT NULL COMMENT ''The route the attempt was made through: ms365, ms365-shared, google or smtp''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND COLUMN_NAME = 'httpCode'
      AND COLUMN_COMMENT <> 'The number the mail service answered with, when it gave one: an HTTP status from Microsoft 365 or Google, an SMTP reply code from a mail server'
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblEmailLog` MODIFY COLUMN `httpCode` SMALLINT DEFAULT NULL COMMENT ''The number the mail service answered with, when it gave one: an HTTP status from Microsoft 365 or Google, an SMTP reply code from a mail server''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 📋  E. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('213_smtp_mail_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
