-- =============================================================================
-- Migration 219: a text held for quiet hours can be sent afterwards (#610)
--
-- Path: web/_sql/219_held_texts.sql
--
-- WHY THIS EXISTS
--   A text message that is not urgent is not sent to a person who is inside
--   their Sabbath quiet hours. Until now the portal recorded such a text as
--   "queued", with the reason "quiet-hours", and nothing ever looked at the
--   row again: the text was dropped while its record said it was waiting.
--   From this change the scheduled job sends a held text once the person's
--   quiet hours have ended, or records it as not sent when it is no longer
--   worth sending. The code is web/_core/Sms.php (send() holds, sendHeld()
--   sends); the job is web/_apps/cron/mail-queue.php.
--
-- WHAT IT DOES
--   A. tblSmsMessage gains two columns and a key:
--      - `heldUseBy`: for a HELD text, the moment after which it is never
--        sent. A text that says "see you at ten tomorrow" is worse than no
--        text when it arrives the evening after. (An emergency notice is
--        never held at all: the owner's decision of 13 October 2026.)
--      - `claimedAt`: the moment a run of the job took a held text to hand
--        it to the text-message provider. A run takes a text by filling this
--        in, in ONE statement, so two runs at the same moment can never both
--        send it. A text is taken once and never taken again.
--      - `idx_sms_held`, so the job, which asks every five minutes, does not
--        read every text ever sent to find the few that are held.
--   B. Every text ALREADY recorded as held is recorded as not sent, in plain
--      words, and its "sent at" moment is emptied (it was never sent).
--      Those rows may be days or months old. None of them is ever sent: a
--      first run after the upgrade that sent them all would put months of
--      out-of-date texts on people's phones, at the customer's cost.
--      (The code would not send them either: sendHeld() never sends a held
--      text with no use-by moment. This step makes the record true at once,
--      without waiting for the job.)
--
-- TWO KINDS OF TIME, KEPT APART (the owner's rule of 23 September 2026)
--   `heldUseBy` and `claimedAt` are MOMENTS IN UTC, always written with
--   UTC_TIMESTAMP() by the code, and only ever compared with UTC_TIMESTAMP().
--   `createdAt` and `sentAt`, which this table has had since migration 095,
--   are written by the database server's OWN clock (CURRENT_TIMESTAMP and
--   NOW()), whatever zone that is in. They are left as they are: every row
--   written before this migration holds them that way, and nothing says
--   which zone each old row was written in. The daily limit goes on counting
--   a day on that same clock.
--
-- WHAT WAS TRIED AND REJECTED
--   - A new value of `status` for "being handed over". The claim is the
--     `claimedAt` column instead, so the list of status words stays as every
--     page and every older copy of the code knows it.
--   - Sending the old held rows "if they are recent". Nothing on an old row
--     says how long its text stayed true, so none is sent.
--
-- WHAT THIS CANNOT DO
--   Say what became of a text that was held before this migration: it was
--   never sent, and it is not sent now.
--
-- SAFE TO RUN TWICE
--   The two columns and the key are each added only when missing, in the
--   information_schema + PREPARE form. It has been run on MySQL 8.0.36 and
--   8.4.11. It has NOT been tried on MariaDB: it is written to this
--   project's convention for both, and a convention is not a proof.
--   (MariaDB's own "ADD COLUMN IF NOT EXISTS" is not used because MySQL
--   refuses it. This note used to say MariaDB accepts the form used here,
--   which nobody had run.)
--   Step B changes only a held row with NO use-by moment. The code never
--   writes such a row, so a second run, or a run on a database that already
--   holds texts held by the new code, changes nothing.
--
-- THE INSTALL SCRIPT
--   web/_sql/full_schema.sql holds the same two columns and the same key in
--   its own CREATE TABLE. A new database has no old held rows, so step B is
--   in this file only.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/610
-- =============================================================================


-- #############################################################################
-- 🗄️  A. tblSmsMessage: a use-by moment, a claim, and a key
-- #############################################################################

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSmsMessage' AND COLUMN_NAME = 'heldUseBy'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblSmsMessage` ADD COLUMN `heldUseBy` DATETIME DEFAULT NULL COMMENT ''A MOMENT IN UTC, written with UTC_TIMESTAMP(): a text HELD for quiet hours is never sent at or after it. Empty for a text that was never held, and for one held before migration 219, which is never sent. (createdAt and sentAt beside it are the database server''''s own clock, not UTC.)'' AFTER `createdAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSmsMessage' AND COLUMN_NAME = 'claimedAt'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblSmsMessage` ADD COLUMN `claimedAt` DATETIME DEFAULT NULL COMMENT ''A MOMENT IN UTC, written with UTC_TIMESTAMP(): when a run of the scheduled job took this HELD text to hand it to the provider. Filled in once, in one statement, so two runs cannot both send it. Empty for a text that was never held or never taken.'' AFTER `heldUseBy`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSmsMessage' AND INDEX_NAME = 'idx_sms_held'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblSmsMessage` ADD KEY `idx_sms_held` (`status`, `heldUseBy`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🧹  B. Texts held before this change: recorded as not sent, never sent
-- #############################################################################
-- The words are the same as Portal\Core\Sms::NOT_SENT_OLD, which is what the
-- job writes on any such row it finds later (one that came back with an old
-- backup, for example). tools/held-texts-selftest.php fails if the two differ.

UPDATE `tblSmsMessage`
   SET `status`   = 'failed',
       `errorMsg` = 'Not sent: quiet hours. It was held before the portal could send a held text afterwards, so it has no use-by moment and is never sent.',
       `sentAt`   = NULL
 WHERE `status` = 'queued'
   AND `errorMsg` = 'quiet-hours'
   AND `heldUseBy` IS NULL;


-- Record this migration.
INSERT INTO `tblMigrations` (`filename`) VALUES ('219_held_texts.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
