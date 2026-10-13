-- =============================================================================
-- Migration 218: who has been told that imported events are waiting (#514, part 9)
--
-- Path: web/_sql/218_external_approval_notices.sql
--
-- WHY THIS EXISTS
--   An event copied in from an outside calendar can wait for an
--   administrator's approval before it is shown more widely. Until this part
--   nobody was TOLD that anything was waiting: an administrator found out
--   only by opening the list. Part 9 tells them, on the dashboard, in one
--   e-mail a day, and in a push (web/_core/ImportNotifier.php).
--
--   "One e-mail a day" and "one push when something newer is waiting" both
--   need a memory: who was told, about which organisation, and when. This
--   table is that memory, and nothing else.
--
-- WHAT IT DOES
--   Creates ONE table, tblExternalApprovalNotices: one row for each
--   administrator in each organisation they have been told about. So it can
--   never hold more rows than (administrators x organisations), and needs no
--   tidying job.
--
--   The scheduled job takes a person's turn by CHANGING their row in one
--   statement ("set the day to today, where the day is before today"). Only
--   one run can make that change, so two runs at the same moment can never
--   both send.
--
-- WHAT WAS TRIED AND REJECTED
--   Keeping "already e-mailed today" in tblUserReminderLog, as the first plan
--   said. That table's key is (refType, refID, dueDate): the person's number
--   would have sat in a column called `refID`, with no link to tblUsers. A
--   request to be forgotten, the copy of "my data" and the backup's order of
--   restoring all find a person by a column that IS a link, so all three
--   would have missed it. Here the column is `userID`, with a real link.
--
-- TWO KINDS OF TIME, KEPT APART (the owner's rule of 23 September 2026)
--   `emailLocalDate` is a DATE ON THE ORGANISATION'S OWN CALENDAR: "the last
--   day this person's daily e-mail was claimed", in the organisation's own
--   time zone. It is NOT a UTC date. It is only ever compared with today's
--   date on that same calendar ("is the stored day before today?"), so the
--   two nights a year when a day is 23 or 25 hours long cannot move it.
--   `emailAt` and `pushAt` are MOMENTS IN UTC, always written with
--   UTC_TIMESTAMP() by the code. Nothing is compared with them: they are
--   kept so that a person's copy of their own data can say when.
--
-- SAFE TO RUN TWICE
--   CREATE TABLE IF NOT EXISTS, and the usual "insert, or leave alone" line
--   at the end. The installer relies on this: it replays every numbered
--   migration after the install script.
--
-- WHAT IT DOES NOT DO
--   It adds no setting, no address and no scheduled job. The pass that uses
--   this table runs inside the job that already refreshes outside calendars
--   (cron/import-feeds). Until that job is running nobody is e-mailed or
--   pushed; the dashboard card needs no job.
--
-- NOTES ON THE SQL
--   `COLLATE utf8mb4_general_ci` is written out, as in migrations 204 to 206:
--   on a database created with no collation of its own, MySQL 8 would
--   otherwise give the table utf8mb4_0900_ai_ci, and a join with an older
--   table then fails ("illegal mix of collations").
--   Both links point at a primary key, so MySQL 8.4's rule about links to a
--   key that is not unique (error 6125, #552) is met.
--   Plain MySQL 8.0 and 8.4 syntax, which MariaDB also reads. MariaDB itself
--   is NOT tested here.
--
-- The install script (full_schema.sql) holds the same table, straight after
-- tblExternalEventApprovals. The two must agree. TWO things compare them:
--   - check N19 of tools/import-notifier-selftest.php lets this file make the
--     table and requires it to be, word for word, the install script's;
--   - the migration test (tools/e2e-migrations/run.sh) requires an upgraded
--     database to equal a fresh install.
-- (This comment used to name tools/audit-checks/check_schema_seed_parity.py.
-- That check compares recorded migration names, settings and addresses; it
-- never reads a table's columns, and passed with the two tables made
-- different. Found by the independent check, 13 October 2026.)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblExternalApprovalNotices` (
    `noticeID`  INT NOT NULL AUTO_INCREMENT COMMENT 'The row''s own number.',
    `siteID`    INT NOT NULL COMMENT 'The organisation whose waiting events the person was told about.',
    `userID`    INT NOT NULL COMMENT 'The administrator who was told. The row is about this person and goes with them.',
    `emailLocalDate` DATE NULL COMMENT 'The day of the last daily e-mail claimed for this person: a date on the ORGANISATION''S OWN calendar (its time zone when claimed). NOT a UTC date. Compared only with today''s date on that same calendar.',
    `emailOutcome`   ENUM('claimed','sent','kept','failed') NULL COMMENT 'claimed = a run took the day and had not finished; sent = a mail service accepted it; kept = it is in the mail queue, which sends it later or lets it lapse at its use-by moment (what became of it is in the mail queue and the send log, not here); failed = it did not go and is not kept. One try a day, on purpose.',
    `emailAt`   DATETIME NULL COMMENT 'UTC moment of that claim; always written with UTC_TIMESTAMP(), never a default.',
    `pushUpToApprovalID` INT NOT NULL DEFAULT 0 COMMENT 'The highest waiting approval number this person has been sent a push about. A push goes only for a higher one.',
    `pushAt`    DATETIME NULL COMMENT 'UTC moment of the last push claim; always written with UTC_TIMESTAMP().',
    PRIMARY KEY (`noticeID`),
    UNIQUE KEY `uq_extnotice_site_user` (`siteID`,`userID`),
    KEY `idx_extnotice_user` (`userID`),
    CONSTRAINT `fk_extnotice_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_extnotice_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Who has been told that imported events are waiting for approval, and when: one row for each administrator in each organisation (#514 part 9).';

-- Record this migration.
INSERT INTO `tblMigrations` (`filename`) VALUES ('218_external_approval_notices.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
