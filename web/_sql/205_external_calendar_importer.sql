-- =============================================================================
-- Migration 205: the importer's own storage (#514, part P6)
-- =============================================================================
-- An organisation can subscribe the portal to an outside calendar (a Google,
-- Microsoft 365 or other published calendar file — issue #327). Part P4 of
-- #514 built the safe downloader, part P5 built the reader that turns a
-- calendar file into dates. THIS migration adds the places the importer
-- (part P6) keeps its answers, and it brings the old #327 rows forward so
-- they are not thrown away.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
--   A. `tblEvents` gains five columns, used ONLY on copied-in events, and
--      the keys that go with them. The most important is `externalUidHash`:
--      the identity of a copied-in event, as raw bytes rather than text.
--   B. The legacy rows written by #327 are brought forward, so a person who
--      has already said "I am going" to an imported event keeps that answer.
--   C. `tblExternalFeeds` gains eleven columns: when the next refresh is due,
--      who is refreshing it right now, and what happened last time.
--   D. Three new tables: the category words an outside calendar puts on an
--      event, the map from those words to the portal's own categories, and
--      the history of refreshes.
--   E. One setting: how many dates the portal will take from ONE calendar.
--   F. This migration's own record.
--
-- -----------------------------------------------------------------------------
-- WHY THE IDENTITY IS RAW BYTES AND NOT TEXT (it is load-bearing)
-- -----------------------------------------------------------------------------
-- Every copied-in event is recognised by the `UID` its own calendar gives it.
-- Storing that UID as TEXT and comparing on it looks obvious and is wrong
-- here. `tblEvents` compares text with the rule `utf8mb4_general_ci`, which
-- ignores capital letters — so `ABC@example.com` and `abc@example.com`, which
-- RFC 5545 says are two DIFFERENT events, would be treated as one. One would
-- silently overwrite the other on every refresh, and nothing anywhere would
-- say so. (This was leak-hunt finding 8 in the #514 plan.)
--
-- `BINARY(32)` is compared byte for byte, so two UIDs differing only in
-- capital letters are two different values, as they must be. It is the
-- SHA-256 of the EXACT bytes of the UID, so it is also a fixed 32 bytes
-- however long the UID is — a UID may legally be any length at all, and the
-- old `externalUid VARCHAR(255)` column simply cut anything longer off.
--
-- -----------------------------------------------------------------------------
-- WHY THE OLD ROWS NEED BRINGING FORWARD, AND WHAT B CAN AND CANNOT SAVE
-- -----------------------------------------------------------------------------
-- The #327 job matched an event by its `eventSlug` — a web-address-friendly
-- name built from the event's title. That has three consequences this
-- migration has to deal with:
--
--   * A one-off event that never changed its title has one row, and that row
--     can be brought forward: B works out what its identity WOULD be under
--     the new rule and writes it in, so the new importer recognises the row
--     it already has instead of making a second one. Everything attached to
--     that row — most importantly a member's "I am going" answer — survives.
--
--   * An event that was RENAMED left TWO rows behind, because the new title
--     made a new slug. B keeps only the newest (`MAX(eventID)`); the older
--     one keeps an empty identity and the importer's first complete refresh
--     removes it. Keeping both would mean showing the same event twice for
--     ever.
--
--   * A REPEATING event was stored by #327 as ONE row for the whole series,
--     because that job never worked out the individual dates. There is no
--     honest way to turn one row into the right set of dates, so it is left
--     with an empty identity and removed on the first complete refresh; the
--     importer then writes one row per date, properly. Any "I am going"
--     answer on that single row is lost. That is unavoidable: the answer was
--     never attached to a date in the first place, so there is no date to
--     move it to.
--
-- A UID of 255 characters or more is left alone (`CHAR_LENGTH < 255`),
-- because the old column cut long UIDs off and there is no way to tell a UID
-- that was exactly 255 characters from one that was cut down to 255. Working
-- out an identity from a cut UID would produce a WRONG identity, which is
-- worse than none: the importer would fail to recognise the event and could
-- attach the wrong row to it. Those rows keep an empty identity and are
-- removed on the first complete refresh, and the importer writes fresh ones.
--
-- The slug B writes is exactly what the importer itself would write for the
-- same event (`FeedImporter::refresh()`, step 6). The two formulas have to
-- agree or the importer would create a second row with a different address.
-- They are checked against each other in the part P6 proofs.
--
-- -----------------------------------------------------------------------------
-- WHY THE NEW TABLES WRITE THEIR COLLATION OUT (it is load-bearing)
-- -----------------------------------------------------------------------------
-- "Collation" is the rule a database uses to compare two pieces of text.
-- `tblEvents` uses `utf8mb4_general_ci`. A database created through a hosting
-- control panel on MySQL 8 defaults to a DIFFERENT rule,
-- `utf8mb4_0900_ai_ci`. A new table that does not name its own collation ends
-- up with that different rule — and then a query comparing a column of this
-- table with a column of `tblEvents` is refused outright: "ERROR 1267 Illegal
-- mix of collations". Neither the installer nor the migration test harness
-- would show it, because both create their database with
-- `utf8mb4_general_ci`. Migration 204's header explains this in full. So
-- every CREATE TABLE below names `utf8mb4_general_ci` itself. Do not remove
-- it.
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing, on a database that already has these columns and tables. The
-- installer runs full_schema.sql and then replays every numbered migration
-- whatever has already run, so every change here is guarded by a check of
-- `information_schema` (the MySQL 8.0-safe form; MariaDB-only `IF NOT EXISTS`
-- on columns and indexes is refused by MySQL with ERROR 1064).
--
-- The backfill in B runs ONLY in the run that adds `externalUidHash`. The
-- guard records "this column is missing" (`@needUidHash`) BEFORE its ALTER,
-- and the backfill runs only when that was true. So a replay can never
-- re-write an identity or an address the importer has since changed, and it
-- can never resurrect a row an administrator removed. (This is the same
-- shape migration 204 used for its own two backfills, and for the same
-- reason: leak-hunt finding 27.)
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION CANNOT DO
-- -----------------------------------------------------------------------------
-- It moves nothing and decides nothing by itself. Until the importer runs,
-- every new column is empty and every new table is empty, and the portal
-- behaves exactly as it did before. It has not been tested on MariaDB.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
-- =============================================================================


-- #############################################################################
-- 🅰️  A. tblEvents — the identity of a copied-in event, and what goes with it
-- #############################################################################

-- ➕ A1 `externalUidHash`. The guard's answer is kept in @needUidHash BEFORE
--    the ALTER, because the backfill in B must run only in the run that adds
--    this column (see "WHAT REPLAY DOES" in the header). Once the column
--    exists the same question answers "no", and the backfill is skipped for
--    good — which is exactly what a replay needs.
SET @needUidHash := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='externalUidHash');
SET @sql := IF(@needUidHash = 1, 'ALTER TABLE `tblEvents` ADD COLUMN `externalUidHash` BINARY(32) DEFAULT NULL COMMENT ''SHA-256 of the exact UID bytes; binary so capital letters never collide'' AFTER `externalPrivate`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A2 `externalRecurrenceKey`: which date of a repeating event this row is.
--    Empty for a one-off. For a timed repeat it is that date's ORIGINAL start
--    written as a UTC instant; for a whole-day repeat it is the date. It is
--    an identifier and never an event time, so the portal's rule that event
--    times are stored as wall-clock readings does not apply to it.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='externalRecurrenceKey');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `externalRecurrenceKey` VARCHAR(20) NOT NULL DEFAULT '''' COMMENT ''Empty for a one-off; the occurrence''''s original start as a UTC instant (YmdTHisZ) or all-day date (Ymd)'' AFTER `externalUidHash`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A3 `externalLastSeenAt`: a UTC moment, not an event time. It is the
--    moment of the last COMPLETE download that contained this event, and it
--    is what the removal rule reads. A download that was cut short never
--    writes it, so a partial download can never make an event look missing.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='externalLastSeenAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `externalLastSeenAt` DATETIME DEFAULT NULL COMMENT ''UTC moment of the last complete download that contained it'' AFTER `externalRecurrenceKey`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A4 `externalUrl`: the link the outside calendar gave for this event.
--    Kept apart from the portal's own `locationWebURL` so an import can never
--    overwrite something a person typed in.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='externalUrl');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `externalUrl` VARCHAR(500) DEFAULT NULL COMMENT ''The link the outside calendar gave for this event'' AFTER `externalLastSeenAt`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A5 `externalDuplicate`: the last download held this identity twice.
--    Such an event is left at the calendar's own setting and no per-event
--    choice or rule is applied to it, because there is no way to tell which
--    of the two the choice was made about.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='externalDuplicate');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD COLUMN `externalDuplicate` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the last download held this identity twice; choices and rules are ignored for it'' AFTER `externalUrl`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅱️  B. Bring the old #327 rows forward (runs once, see the header)
-- #############################################################################
-- Read the header before changing anything here. In short: one row per
-- (calendar, UID) — the newest — is given the identity and the address the
-- new importer would give it, so it is recognised rather than duplicated.
-- Everything else keeps an empty identity and is removed by the importer's
-- first COMPLETE download.
--
-- Two points about the statement itself:
--
--   * It joins a DERIVED TABLE (the bracketed SELECT named `k`) rather than
--     using a sub-query in the WHERE clause. MySQL refuses the second shape
--     with "ERROR 1093: You can't specify target table for update in FROM
--     clause"; a derived table is worked out first and is allowed. This was
--     run on MySQL 8.0.36 as part P6 proof 6 before it was written here.
--
--   * `SHA2(x, 256)` gives lower-case hexadecimal, which is what PHP's
--     `hash('sha256', …)` gives too, so the address this writes is the same
--     text the importer writes. `UNHEX()` turns that hexadecimal back into
--     the 32 raw bytes the column holds.
SET @sql := IF(@needUidHash = 1,
    'UPDATE tblEvents t JOIN (SELECT externalFeedID, externalUid, MAX(eventID) AS keepID FROM tblEvents
         WHERE externalFeedID IS NOT NULL AND isDeleted = 0 AND externalUid IS NOT NULL AND CHAR_LENGTH(externalUid) < 255
         GROUP BY externalFeedID, externalUid) k
       ON k.keepID = t.eventID
     SET t.externalUidHash = UNHEX(SHA2(t.externalUid, 256)),
         t.eventSlug = CONCAT(''imp-'', LEFT(SHA2(CONCAT(t.externalFeedID, ''|'', SHA2(t.externalUid, 256), ''|''), 256), 16))',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔑  C. tblEvents — the keys that make the identity work
-- #############################################################################

-- 🗑️ C1 The #327 key on (calendar, UID as text) is dropped. It is replaced by
--    C2, and leaving it would mean the database kept a second index of the
--    same events for nothing. It is dropped AFTER B, because B reads
--    `externalUid`.
--
--    Worth knowing, because it looks like a mistake and is not: migration
--    129 ADDS this key, also guarded. So a run that replays EVERY numbered
--    migration — which is what the installer does, and only the installer —
--    adds it in 129 and drops it again here, every time. The alternative was
--    to leave it in `full_schema.sql`, and that is worse: the fresh-install
--    file and the end of the migration chain would then disagree about
--    whether the key exists, which is exactly what the parity check and the
--    end-to-end migration harness exist to catch. The ordinary upgrade path
--    (Admin → Upgrade) runs only the migrations that have not run yet, so it
--    does this once and never again.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND INDEX_NAME='idx_event_external');
SET @sql := IF(@c>0, 'ALTER TABLE `tblEvents` DROP INDEX `idx_event_external`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔐 C2 The identity itself, and it is UNIQUE. Two rows can never claim to be
--    the same date of the same event of the same calendar — the database
--    refuses it, so a fault in the importer becomes a caught error rather
--    than a quietly duplicated event. A row with an empty (NULL) identity is
--    exempt, because MySQL treats NULLs in a unique key as all different;
--    that is what lets the old #327 rows sit here until they are removed.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND INDEX_NAME='uq_event_external_identity');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD UNIQUE KEY `uq_event_external_identity` (`externalFeedID`,`externalUidHash`,`externalRecurrenceKey`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔎 C3 "Which of this calendar's events did the last download not contain?"
--    — the question the removal rule asks, once per refresh.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND INDEX_NAME='idx_event_external_seen');
SET @sql := IF(@c=0, 'ALTER TABLE `tblEvents` ADD KEY `idx_event_external_seen` (`externalFeedID`,`externalLastSeenAt`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅳  D. tblExternalFeeds — when to refresh, who is refreshing, what happened
-- #############################################################################

-- ➕ D1 `timezone`: the zone to read a time written without one. Empty means
--    the organisation's own zone. It is also the zone the portal stores this
--    calendar's times in, so an administrator can keep a partner diary in the
--    partner's own zone if that is what their people expect to read.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='timezone');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `timezone` VARCHAR(64) DEFAULT NULL COMMENT ''Zone for times with no zone; empty = the organisation''''s'' AFTER `fetchEveryMins`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D2 `nextFetchAt`: a UTC moment, not an event time. The scheduled job
--    picks up calendars whose moment has passed. It is moved on the instant a
--    refresh CLAIMS a calendar, not when the refresh finishes — so a request
--    that is killed half way through does not leave the same calendar first
--    in the queue for ever while the others wait.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='nextFetchAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `nextFetchAt` DATETIME DEFAULT NULL COMMENT ''UTC moment the next scheduled refresh is due'' AFTER `lastFetchedAt`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D3 `refreshLeaseUntil`: a UTC moment. While it is in the future, one
--    refresh owns this calendar and no other will start. It has an END rather
--    than being a simple "busy" flag on purpose: a flag set by a request that
--    is then killed stays set for ever, and the calendar never refreshes
--    again. An end means the worst case is a wait, not a stoppage.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='refreshLeaseUntil');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `refreshLeaseUntil` DATETIME DEFAULT NULL COMMENT ''UTC; a refresh in progress owns the calendar until then'' AFTER `nextFetchAt`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D4 `consecutiveFailures`: how many refreshes in a row have failed. Each
--    failure doubles the wait before the next try, up to a day, so a calendar
--    whose server is down is not hammered.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='consecutiveFailures');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `consecutiveFailures` INT NOT NULL DEFAULT 0 COMMENT ''Refreshes that have failed in a row; each one doubles the wait before the next try'' AFTER `refreshLeaseUntil`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D5 `lastFetchOk`: did the last refresh work? Empty means it has never
--    been tried.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='lastFetchOk');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `lastFetchOk` TINYINT(1) DEFAULT NULL COMMENT ''1 = the last refresh worked, 0 = it did not, empty = never tried'' AFTER `consecutiveFailures`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D6 `lastFetchMessage`: what to tell an administrator, in plain English.
--    It NEVER contains the calendar's web address. An address in a message
--    shown on a page is an address that ends up in a screenshot, a support
--    e-mail or a browser's history, and some of these addresses are secret
--    links that let anybody who has them read the whole diary.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='lastFetchMessage');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `lastFetchMessage` VARCHAR(500) DEFAULT NULL COMMENT ''Plain English, never the address'' AFTER `lastFetchStatus`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D7 `lastContentHash`: a fingerprint of the file that was downloaded last
--    time. When the same file comes back, and the last complete refresh was
--    recent, there is nothing to do — which saves a busy portal from working
--    through thousands of unchanged dates several times a day.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='lastContentHash');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `lastContentHash` CHAR(64) DEFAULT NULL COMMENT ''SHA-256 of the file downloaded last time'' AFTER `lastFetchMessage`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D8 `lastCompleteAt`: a UTC moment. The last time a download was read
--    right through AND written away without a problem. "Skip the work because
--    the file has not changed" is only safe when this is recent — otherwise a
--    refresh that failed half way through would be skipped for ever on the
--    strength of its own fingerprint.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='lastCompleteAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `lastCompleteAt` DATETIME DEFAULT NULL COMMENT ''UTC moment of the last complete, successfully processed download'' AFTER `lastContentHash`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D9 `lastRunCapped`: the last download had more dates in it than the
--    portal takes from one calendar, so what was read is not all of it. While
--    this is 1, nothing is ever removed on the strength of "the calendar no
--    longer has it", because the calendar may well still have it.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='lastRunCapped');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `lastRunCapped` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the last download held more dates than the portal takes from one calendar'' AFTER `lastCompleteAt`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ D10/D11 Who last changed this calendar's settings, and when.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='updatedByID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `updatedByID` INT DEFAULT NULL COMMENT ''Who last changed this calendar''''s settings; emptied, never cascaded, if that account is removed'' AFTER `createdByID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='updatedAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `updatedAt` DATETIME DEFAULT NULL COMMENT ''When the settings were last changed'' AFTER `createdAt`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 D12 The link from `updatedByID` to the account, emptied rather than
--    cascaded: deleting somebody's account must not delete a calendar they
--    once edited.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND CONSTRAINT_NAME='fk_feed_updater');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD CONSTRAINT `fk_feed_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔎 D13 "Which calendars are due a refresh?" — the one question the
--    scheduled job asks, every time it runs.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND INDEX_NAME='idx_feed_due');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD KEY `idx_feed_due` (`isActive`,`nextFetchAt`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅴  E. Three new tables
-- #############################################################################

-- 🏷️ E1 The category words the outside calendar puts on an event.
--    Stored in lower case, because the portal matches them without regard to
--    capital letters and storing one spelling means the match cannot depend
--    on how the calendar happened to write it. The row belongs to the event
--    and goes when the event goes.
CREATE TABLE IF NOT EXISTS `tblExternalEventTags` (
    `tagID`   INT NOT NULL AUTO_INCREMENT,
    `eventID` INT NOT NULL COMMENT 'The copied-in event this word was on.',
    `tag`     VARCHAR(100) NOT NULL COMMENT 'One category word from the outside calendar, in lower case.',
    PRIMARY KEY (`tagID`),
    UNIQUE KEY `uq_exttag_event_tag` (`eventID`,`tag`),
    CONSTRAINT `fk_exttag_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Category words an outside calendar puts on an event (#514). Replaced wholesale on every refresh.';

-- 🗺️ E2 The map from one of those words to one of the portal's own event
--    categories. An empty `categoryID` means "this word is known about but
--    maps to nothing", which is different from "this word has never been
--    seen" — an administrator can leave a word deliberately unmapped.
CREATE TABLE IF NOT EXISTS `tblExternalCategoryMap` (
    `mapID`             INT NOT NULL AUTO_INCREMENT,
    `siteID`            INT NOT NULL COMMENT 'The organisation the map belongs to.',
    `feedID`            INT NOT NULL COMMENT 'The calendar the map belongs to.',
    `externalCategory`  VARCHAR(100) NOT NULL COMMENT 'The word as it comes from the outside calendar.',
    `categoryID`        INT DEFAULT NULL COMMENT 'The portal category it maps to; empty = deliberately unmapped.',
    PRIMARY KEY (`mapID`),
    UNIQUE KEY `uq_extcatmap_feed_word` (`feedID`,`externalCategory`),
    KEY `idx_extcatmap_site` (`siteID`),
    KEY `idx_extcatmap_category` (`categoryID`),
    CONSTRAINT `fk_extcatmap_feed`     FOREIGN KEY (`feedID`)     REFERENCES `tblExternalFeeds`(`feedID`)     ON DELETE CASCADE,
    CONSTRAINT `fk_extcatmap_category` FOREIGN KEY (`categoryID`) REFERENCES `tblEventCategories`(`categoryID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Maps an outside calendar''s own category words to the portal''s event categories (#514).';

-- 📜 E3 The history of refreshes: one row per attempt, kept for the newest
--    fifty of each calendar. This is how an administrator finds out that a
--    calendar has quietly been failing for a fortnight — before this, the
--    only record was a single "last status" line that the next attempt
--    overwrote.
--
--    `startedAt` and `finishedAt` are UTC moments, not event times.
--    `message` is plain English and never contains the address, for the
--    reason given beside `lastFetchMessage` above.
CREATE TABLE IF NOT EXISTS `tblExternalFeedRuns` (
    `runID`            INT NOT NULL AUTO_INCREMENT,
    `feedID`           INT NOT NULL,
    `siteID`           INT NOT NULL COMMENT 'Copied from the calendar so a run can be found without a join.',
    `startedAt`        DATETIME NOT NULL COMMENT 'UTC moment the attempt began.',
    `finishedAt`       DATETIME NULL COMMENT 'UTC moment the attempt ended; empty if it never did.',
    `outcome`          ENUM('ok','unchanged','failed','partial') NOT NULL,
    `httpStatus`       INT NULL COMMENT 'What the other server answered, when it answered at all.',
    `message`          VARCHAR(500) NULL COMMENT 'Plain English, never the address.',
    `bytes`            INT NOT NULL DEFAULT 0,
    `eventsSeen`       INT NOT NULL DEFAULT 0,
    `rowsAdded`        INT NOT NULL DEFAULT 0,
    `rowsUpdated`      INT NOT NULL DEFAULT 0,
    `rowsRemoved`      INT NOT NULL DEFAULT 0,
    `rowsSkipped`      INT NOT NULL DEFAULT 0,
    `awaitingApproval` INT NOT NULL DEFAULT 0 COMMENT 'Events left waiting for an administrator to agree to them (part P7).',
    `triggeredBy`      ENUM('schedule','manual') NOT NULL,
    `triggeredByID`    INT NULL COMMENT 'Who pressed Refresh; empty for the scheduled job.',
    PRIMARY KEY (`runID`),
    KEY `idx_extrun_feed_started` (`feedID`,`startedAt`),
    CONSTRAINT `fk_extrun_feed` FOREIGN KEY (`feedID`)        REFERENCES `tblExternalFeeds`(`feedID`) ON DELETE CASCADE,
    CONSTRAINT `fk_extrun_user` FOREIGN KEY (`triggeredByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='One row per refresh attempt of an outside calendar (#514). Only the newest fifty of each calendar are kept.';


-- #############################################################################
-- ⚙️  F. One setting: how many dates to take from ONE calendar
-- #############################################################################
-- Left EMPTY on purpose, and empty means "the portal's own number".
--
-- That number lives in exactly one place — `IcsReader::MAX_EVENTS_PER_FEED`
-- in `web/_core/IcsReader.php`, which is 2,000 as this is written. Writing
-- 2,000 here as well would put the same number in two files that nothing
-- keeps in step: change the constant and this row would quietly go on
-- claiming the old number is the default, on every existing installation.
--
-- An administrator may type a number in (through the ordinary settings
-- editor). The reader refuses anything outside 1 to
-- `IcsReader::MAX_EVENTS_PER_FEED_CEILING` (10,000), says so in the
-- calendar's own warnings, and uses the portal's number instead — so a typing
-- mistake is visible rather than silently obeyed.
--
-- WHAT RAISING IT COSTS, measured while building part P5: at the top of the
-- range one calendar took 52 MB of memory and 0.22 seconds, against 14 MB and
-- 0.04 seconds at the default. On shared hosting, with several calendars
-- refreshing in one request, that is worth an administrator knowing before
-- they change it.
--
-- Portal-wide by default (`siteID` NULL). An organisation may still override
-- its own copy through the settings editor; the importer reads it with
-- `App::settingForSite()`, which prefers the organisation's own row.
--
-- `ON DUPLICATE KEY UPDATE defaultValue` and never `settingValue`: writing
-- `settingValue` would reset a number an administrator has since chosen,
-- every single time the installer replays. That is the settings-table rule
-- migration 187 established.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'feeds.maxEventsPerFeed', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 📋  G. Self-record
-- #############################################################################
-- The installer replays every numbered migration after full_schema.sql and
-- ignores tblMigrations, so this has to be safe to run a second time.
INSERT INTO `tblMigrations` (`filename`) VALUES ('205_external_calendar_importer.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
