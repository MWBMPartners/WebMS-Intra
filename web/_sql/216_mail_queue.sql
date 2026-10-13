-- =============================================================================
-- Migration 216: the mail queue (#592, part 3)
--
-- Path: web/_sql/216_mail_queue.sql
--
-- WHY THIS EXISTS
--   Until now a message that could not be sent at once was not kept: the mail
--   server was down, its certificate failed the check, or the hourly limit
--   was reached, and the message was gone with only a line in the send log.
--   This package keeps such a message, tries it again, and never sends it
--   twice, never sends it late, and never loses it in silence. The code is in
--   web/_core/MailQueue.php, web/_core/Mailer.php and web/_core/MailRoutes.php;
--   the scheduled job that works through the queue is web/_apps/cron/mail-queue.php.
--
-- WHAT IT DOES
--   A. Creates three tables:
--      - tblMailQueue: one row for each message that is waiting. Its two body
--        columns are stored ENCRYPTED with the installation's key (a waiting
--        password-reset message is the only place a working reset link is
--        ever stored). A message that is sent is deleted from here at once;
--        the send log (tblEmailLog) is the lasting record.
--      - tblMailQueueFiles: one row for each file attached to a waiting
--        message. The file itself is a copy under _uploads/mail-queue/.
--      - tblMailRouteState: what is known to be wrong with a mail route (a
--        certificate that failed its check, a sign-in the mail server
--        refused) and when the scheduled job last ran. One row today, for the
--        installation's one route.
--   B. tblEmailLog.status gains three choices at the END of its list:
--      queued, held and expired. No stored value changes.
--      tblEventBroadcasts gains `sentAtUtc`: the moment, in UTC, that a
--      broadcast was recorded. A broadcast is now recorded BEFORE its first
--      message goes, and a second press of Send within ten minutes is
--      recognised by that moment. The older `sentAt` is the database
--      server's own clock, which repeats an hour on the night the clocks go
--      back, so "ten minutes ago" counted from it would be wrong that night.
--   C. Seeds six settings for the whole installation:
--      - mail.queue.retryHours (24), mail.queue.maxAgeHours (48),
--        mail.queue.keepFailedDays (7), mail.queue.holdDays (7): how long a
--        message is tried for, the age past which nothing is ever sent, and
--        how long a failed or a held message is kept. An organisation may
--        have a row of its own for each, which the queue then uses for that
--        organisation's mail.
--      - mail.smtp.certCheck (on) and mail.smtp.certCheckOffAt (empty), for
--        the page that package M4 adds. NOTHING READS THESE TWO YET: the mail
--        server's certificate is always checked, whatever they hold.
--   D. Adds the address of the scheduled job, cron/mail-queue. Not protected
--      by sign-in: the job checks the maintenance token itself, like every
--      other scheduled job.
--
-- SAFE TO RUN TWICE
--   A is CREATE TABLE IF NOT EXISTS. B first asks the database whether the
--   change is still needed. C is "insert, or leave alone": a second run may
--   refresh a stored default and never changes a value somebody has saved.
--   D writes the same address again. The installer relies on this: it replays
--   every numbered migration after the install script.
--
-- WHAT IT DOES NOT DO
--   It does not add the scheduled job to the hosting company's scheduler:
--   an administrator does that (see DEV_NOTES.md). Until then, mail that
--   cannot be sent at once waits, and the health page says so.
--   It sends nothing and moves no existing row.
--   Part B restates tblEmailLog.status exactly as web/_sql/full_schema.sql
--   declares it. On a database whose column had been changed by hand, this
--   puts it back to that.
--   Broadcasts already recorded get NO UTC moment (the column stays empty for
--   them): the server's own clock cannot be turned back into UTC with
--   certainty. They are never taken for a second press.
--   Nothing here has been run on MariaDB; no test in this repository covers it.
--
-- The structure changes in B use the information_schema + PREPARE form,
-- because MySQL 8 refuses MariaDB's `IF [NOT] EXISTS` on ALTER (error 1064).
-- See DEV_NOTES.md, "Portable DDL convention".
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
-- 🗄️  A. The three tables
-- #############################################################################
-- Every DATETIME below is a MOMENT IN UTC, written with UTC_TIMESTAMP() and
-- never with NOW(): the database server's own clock repeats an hour on the
-- night the clocks go back, and a retry worked out from it would be an hour
-- wrong. Each column's description says so, because nothing in a DATETIME
-- itself does.

CREATE TABLE IF NOT EXISTS `tblMailQueue` (
    `queueID`        INT           NOT NULL AUTO_INCREMENT COMMENT 'Unique number of the waiting message',
    `siteID`         INT           DEFAULT NULL COMMENT 'The organisation the message is for; empty when it was written outside any organisation. The four keeping-and-retrying settings are read for this organisation',
    `priority`       TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1 urgent, 5 normal, 9 bulk. The lowest number goes first',
    `status`         ENUM('queued','sending','held','failed','expired') NOT NULL DEFAULT 'queued' COMMENT 'queued: waiting to be tried. sending: a run of the job has taken it. held: waiting for a person, or for a fault with the mail route to be put right. failed: given up on, kept for a while so it can be looked at. expired: its use-by moment passed before it could go. A message that is sent is deleted',
    `stage`          VARCHAR(20)   NOT NULL DEFAULT '' COMMENT 'While status is sending: claimed, then handing-over from just before the message is given to the mail server. Empty otherwise',
    `holdReason`     VARCHAR(40)   NOT NULL DEFAULT '' COMMENT 'Why a held message is held, as one word: certificate, interrupted, unknown-if-sent or restored. Empty when it is not held',
    `lastStage`      VARCHAR(20)   NOT NULL DEFAULT '' COMMENT 'The step the last attempt stopped at, in the mail library''s word (CONNECT, AUTH, BODY and so on)',
    `lastError`      VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'What happened at the last attempt, in words. Can hold an e-mail address the mail server named. Never any part of the message body',
    `attempts`       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'How many times the message has been given to a mail route. Counted BEFORE each attempt, so a message that stops the job is still counted',
    `nextAttemptAt`  DATETIME      NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): the message is not tried before this',
    `expiresAt`      DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC: the message is never sent at or after this. Empty when it has no use-by moment. For a password reset it is the moment the link itself stops working',
    `createdAt`      DATETIME      NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the message was given to the portal to send. Nothing older than the longest-wait setting is ever sent',
    `statusAt`       DATETIME      NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the row took its present status. How long a failed or held message is kept is counted from this',
    `claimedAt`      DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when a run of the job took the row. Empty when no run has it',
    `claimToken`     CHAR(32)      NOT NULL DEFAULT '' COMMENT 'The random mark of the run that has taken the row. Empty when no run has it',
    `toRecipients`   TEXT          NOT NULL COMMENT 'Comma-joined recipient address list, as tblEmailLog holds it',
    `recipientCount` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'How many addresses the message is for. The hourly limit counts these',
    `attachmentCount` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'How many files are attached. A message whose stored files do not come to exactly this number is failed, never sent without one',
    `subject`        VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'The subject line. Not encrypted: it is what the send log already keeps',
    `messageID`      VARCHAR(330)  NOT NULL DEFAULT '' COMMENT 'The message''s own identifier, fixed when the message is kept and the same on every attempt, so that a mail system given a second copy can drop it. Empty only when no from address was set at that moment',
    `bodyHtml`       MEDIUMTEXT    DEFAULT NULL COMMENT 'The HTML version, ENCRYPTED with the installation''s key. Emptied when the message expires',
    `bodyText`       MEDIUMTEXT    DEFAULT NULL COMMENT 'The plain-text version, ENCRYPTED with the installation''s key. Emptied when the message expires',
    `repeatSafe`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 when a second copy does no harm (a reset link, an alert). Such a message is tried again after an interrupted attempt; any other is held for a person',
    `origin`         VARCHAR(60)   NOT NULL DEFAULT '' COMMENT 'Which record of the portal is waiting to hear what became of this message, as a kind and a number (newsletter:12, statement:7, reset:5). When the message goes or fails, that record is corrected; a new password reset removes the older waiting ones by it. Empty for most mail. Never an address or a name; for a password reset the number is the account number of the person it is for, and the row is deleted with that person',
    PRIMARY KEY (`queueID`),
    KEY `idx_mailqueue_claim` (`status`, `priority`, `createdAt`),
    KEY `idx_mailqueue_token` (`claimToken`),
    KEY `idx_mailqueue_site` (`siteID`),
    CONSTRAINT `fk_mailqueue_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Mail that could not be sent at once and is waiting (#592). See _core/MailQueue.php.';

CREATE TABLE IF NOT EXISTS `tblMailQueueFiles` (
    `queueFileID`  INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique number of the attached file',
    `queueID`      INT          NOT NULL COMMENT 'The waiting message the file belongs to',
    `storedName`   CHAR(32)     NOT NULL COMMENT 'The random name of the folder under _uploads/mail-queue/ that holds the copy',
    `realName`     VARCHAR(255) NOT NULL COMMENT 'The file''s own name, which the recipient sees. The copy is stored under this name inside that folder',
    `contentType`  VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'What kind of file it is, such as application/pdf. Empty when not known',
    `sizeBytes`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'The size of the copy in bytes. A copy of another size is not sent',
    PRIMARY KEY (`queueFileID`),
    UNIQUE KEY `uq_mailqueuefiles_stored` (`storedName`),
    KEY `idx_mailqueuefiles_queue` (`queueID`),
    CONSTRAINT `fk_mailqueuefiles_queue` FOREIGN KEY (`queueID`)
        REFERENCES `tblMailQueue` (`queueID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Files attached to waiting mail (#592). The files are copies under _uploads/mail-queue/.';

CREATE TABLE IF NOT EXISTS `tblMailRouteState` (
    `routeKey`           VARCHAR(40)   NOT NULL COMMENT 'Which mail route the row is about. Today always installation: the one route every organisation shares',
    `problem`            VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'What is wrong with the route, as one word: certificate or sign-in-refused. Empty when nothing is known to be wrong',
    `problemWords`       VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'The problem in plain words, for an administrator. Never a password',
    `problemSince`       DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the problem was first seen',
    `problemSeenAt`      DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the problem was last seen',
    `settingsMark`       CHAR(64)      NOT NULL DEFAULT '' COMMENT 'A fingerprint of the mail settings as they were when the problem was recorded. When the settings change, the problem is no longer assumed',
    `tries`              SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'How many times in a row the mail server has refused the sign-in. The wait before the next try grows with it',
    `nextTryAt`          DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC: after a refused sign-in, the job does not sign in again before this',
    `certReasons`        VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'Why the certificate failed, as the mail library''s words joined by commas (expired, wrong-name and so on)',
    `certNames`          VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'The server names the certificate is for, joined by commas',
    `certSubject`        VARCHAR(300)  NOT NULL DEFAULT '' COMMENT 'Who the certificate says it belongs to',
    `certIssuer`         VARCHAR(300)  NOT NULL DEFAULT '' COMMENT 'Who issued the certificate',
    `certValidFrom`      DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC: the certificate is not valid before this',
    `certValidTo`        DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC: the certificate is not valid after this',
    `certSha256`         CHAR(64)      NOT NULL DEFAULT '' COMMENT 'The certificate''s SHA-256 fingerprint, in hexadecimal. Empty when the certificate could not be read',
    `encryptionFailures` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'How many attempts in a row have failed while switching encryption on, without the certificate being blamed',
    `jobLastRanAt`       DATETIME      DEFAULT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the hosting company''s scheduler last called the job''s own address (cron/mail-queue) with the right token. Written by that page only: a run of the queue made by any other page does not count. Empty when the scheduler never has',
    PRIMARY KEY (`routeKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='What is known to be wrong with a mail route, and when the mail queue job last ran (#592). See _core/MailRoutes.php.';


-- #############################################################################
-- ✏️  B. Two existing tables: three more choices for tblEmailLog.status (at
--     the END of its list), and a UTC moment for tblEventBroadcasts
-- #############################################################################
-- At the end, because MySQL stores a choice as its place in the list: a new
-- choice put anywhere else would change what every stored row means. Added at
-- the end, no row is rewritten.
-- Counts 1 only while the column is there and is not yet exactly this.

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEmailLog' AND COLUMN_NAME = 'status'
      AND (COLUMN_TYPE <> 'enum(''sent'',''failed'',''queued'',''held'',''expired'')'
           OR COLUMN_COMMENT <> 'sent: a mail service accepted it. failed: it did not go and is not being tried again. queued: kept, and will be tried again. held: kept, waiting for a person or for the mail route to be put right. expired: its use-by moment passed before it could go')
);
SET @sql := IF(@c = 1,
    'ALTER TABLE `tblEmailLog` MODIFY COLUMN `status` ENUM(''sent'',''failed'',''queued'',''held'',''expired'') NOT NULL COMMENT ''sent: a mail service accepted it. failed: it did not go and is not being tried again. queued: kept, and will be tried again. held: kept, waiting for a person or for the mail route to be put right. expired: its use-by moment passed before it could go''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tblEventBroadcasts.sentAtUtc. Counts 0 only while the column is missing.
SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEventBroadcasts' AND COLUMN_NAME = 'sentAtUtc'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblEventBroadcasts` ADD COLUMN `sentAtUtc` DATETIME DEFAULT NULL COMMENT ''A MOMENT IN UTC, written with UTC_TIMESTAMP(): when the broadcast was recorded, which is BEFORE its first message goes. A second press of Send within ten minutes is recognised by it. Empty for rows from before migration 216. (sentAt beside it is the database server''''s own clock, not UTC.)'' AFTER `sentAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- ⚙️  C. The six settings (installation-wide)
-- #############################################################################
-- The same shape migration 213 uses. Since migration 187 a unique key covers
-- installation-wide rows, so a second run cannot add a duplicate.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'mail.queue.retryHours',     '24', '24', 0),
    (NULL, 'mail.queue.maxAgeHours',    '48', '48', 0),
    (NULL, 'mail.queue.keepFailedDays', '7',  '7',  0),
    (NULL, 'mail.queue.holdDays',       '7',  '7',  0),
    (NULL, 'mail.smtp.certCheck',       'on', 'on', 0),
    (NULL, 'mail.smtp.certCheckOffAt',  '',   '',   0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️  D. The scheduled job's address
-- #############################################################################
-- The shape migration 196 uses for the other scheduled jobs. isProtected is
-- written again on a second run, because a job address that ended up
-- protected would be sent to the sign-in page and stop in silence.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('cron/mail-queue', 'cron/mail-queue.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);


-- #############################################################################
-- 📋  E. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('216_mail_queue.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
