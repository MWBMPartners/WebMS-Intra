-- =============================================================================
-- Migration 206: choices, rules and approvals for events copied in from an
-- outside calendar, and the "Don't show via API" box (#514, part P7)
-- =============================================================================
-- An organisation can subscribe the portal to an outside calendar (a Google,
-- Microsoft 365 or other published calendar file — issue #327). Parts P1 to P6
-- of #514 decided, for each calendar as a whole, who may see its events. This
-- migration adds the places an administrator's FINER decisions are kept:
--
--   * A CHOICE is an administrator deciding who sees ONE date of an event
--     (`scope = date`), or EVERY date of a repeating event (`scope =
--     series`), optionally only between two dates. It is keyed by the outside
--     calendar's own identity for the event (the SHA-256 of its UID, plus
--     which date), never by the portal's event number. That is on purpose: a
--     choice has to survive the portal's row being removed and brought back.
--     An event deleted and re-created at the source gets a new UID, and then
--     no choice matches it (#514 decision D10).
--   * A RULE is an administrator deciding who sees every event of one
--     calendar that matches a description — "category is Outreach AND the
--     title contains Open, EXCEPT when the title contains planning" (D12b) —
--     optionally only between two dates.
--   * An APPROVAL row is one date waiting for, or having had, an
--     administrator's agreement to be shown MORE widely than the calendar's
--     own setting (D11). It records what was asked for (`requestHash`), what
--     the event looked like when it was asked (`contentHash` and a snapshot),
--     and the decision.
--
-- WHY A WIDENING WAITS FOR APPROVAL. A choice or a rule that shows an event to
-- MORE people than the calendar's own setting could put something private in
-- front of the whole internet, and outside calendars change without warning.
-- So the widening waits until an administrator has seen the event and agreed
-- — and if the event's title or details then change at the source, it waits
-- again (D14). Narrowing never waits: it cannot expose anything.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
--   A. `tblExternalFeeds.apiOptOut` — the calendar's "Don't show via API" box.
--   B. `tblEvents.importApiOptOut` — the stored answer to "may an API key
--      receive this copied-in event?", and its one-time backfill.
--   C. Four new tables, in this order (rules before their conditions,
--      because of the foreign key between them):
--        tblExternalEventChoices   the single-date and series choices
--        tblExternalFeedRules      the rules
--        tblExternalRuleConditions the conditions each rule tests
--        tblExternalEventApprovals the approval rows
--   E. `tblEvents.externalDuplicate`'s comment, brought into line with
--      full_schema.sql's corrected wording (round-2 check gap 5) — a
--      comment-only change, on an upgraded database only, never a plain
--      install (see section E's own header for why this could not simply
--      go in migration 205 instead).
--   D. This migration's own record.
--
-- -----------------------------------------------------------------------------
-- THE "DON'T SHOW VIA API" BOX — the owner's decision of 24 September 2026
-- -----------------------------------------------------------------------------
-- An API key (a website or another system reading the portal's events API)
-- now receives an event copied in from an outside calendar EXACTLY when a
-- signed-out visitor to the portal could see it, and in the same detail —
-- UNLESS the calendar, or a choice or rule that applies to the event, has
-- "Don't show via API" ticked. The box is unticked by default.
--
-- The two boxes combine in opposite ways, and on purpose:
--   * "Also show on the public website" widens, so EVERY matching source has
--     to tick it before it counts;
--   * "Don't show via API" narrows, so ONE applying source ticking it is
--     enough, and nothing can switch it back on.
-- Because the API box can only narrow, it never needs approval and it plays
-- no part in the approval fingerprints.
--
-- The rule, as `FeedResolver` applies it (#514 part P7 plan, B2): an event's
-- `importApiOptOut` is 1 when ANY of these says "don't show via API", and 0
-- otherwise —
--   1. the calendar's own box, always, whatever decided who may see it;
--   2. the single-date choice for that date, while it is inside its dates;
--   3. the series choice for that event, while it is inside its dates — even
--      when a single-date choice decided who may see it;
--   4. every rule that is switched on, inside its dates and matching the
--      event — even when a choice decided who may see it, even for an event
--      the outside calendar marked private or listed twice, even while the
--      rule's widening waits for approval or was declined, and even when
--      rules conflict.
-- A rule switched off, a choice or rule outside its dates, a rule that
-- matches nothing, and a choice for another event do not count: none of them
-- applies to the event at all.
--
-- -----------------------------------------------------------------------------
-- WHY `importApiOptOut` DEFAULTS TO 1, WHILE THE THREE BOXES DEFAULT TO 0
-- -----------------------------------------------------------------------------
-- The boxes on a calendar, a choice and a rule are what an administrator
-- chooses, and the owner chose "unticked" as the default. The event column is
-- different: it is `FeedResolver`'s stored ANSWER, and every stored-answer
-- column migration 204 added defaults to the narrow value on purpose (see its
-- header, "The COLUMN defaults are the narrow ones on purpose"). So a future
-- writer that forgets the column hides an event from API keys rather than
-- sending it. The importer names it explicitly on every new row, and the
-- resolver writes the real answer in the same transaction.
--
-- THE ONE-TIME BACKFILL THAT THIS MAKES NECESSARY. On an upgraded database the
-- column would arrive holding 1 on every existing copied-in event, and API
-- keys would lose every one of them until each calendar was next worked out —
-- up to twenty hours for a calendar whose file has not changed, because an
-- unchanged refresh does not work anything out. So section B records whether
-- the column is missing (`@needApiOptOut`) BEFORE adding it, and only in that
-- same run sets it to 0 on every copied-in event. 0 is exactly what the
-- resolver would write: before this migration no choice or rule exists, and
-- every calendar's new box is unticked.
--
-- -----------------------------------------------------------------------------
-- WHY THE NEW TABLES' COLLATION IS WRITTEN OUT (it is load-bearing)
-- -----------------------------------------------------------------------------
-- "Collation" is the rule a database uses to compare two pieces of text. A
-- database created through a hosting control panel on MySQL 8 defaults to
-- `utf8mb4_0900_ai_ci`, while `tblEvents` uses `utf8mb4_general_ci`. A table
-- that does not name its own collation takes the other one, and then a query
-- comparing text across the two tables is refused outright ("ERROR 1267
-- Illegal mix of collations"). Neither the installer nor the migration test
-- harness would show it, because both create their database with
-- `utf8mb4_general_ci`. Migration 204's header, "WHY THE NEW TABLE'S
-- COLLATION IS WRITTEN OUT", explains it in full. Every CREATE TABLE below
-- names `utf8mb4_general_ci` itself. Do not remove it.
--
-- -----------------------------------------------------------------------------
-- EVERY MOMENT IS UTC, AND NO COLUMN HERE DEFAULTS TO CURRENT_TIMESTAMP
-- -----------------------------------------------------------------------------
-- Three kinds of time live in these tables and they are kept apart; each
-- column's comment says which it is:
--   * a MOMENT (a point in history, the same everywhere): `createdAt`,
--     `updatedAt`, `decidedAt`. Always UTC.
--   * a TIME ON A CLOCK: the approval snapshot's `snapStart` and `snapEnd`,
--     copied from the event, with the zone they are written in beside them
--     (`snapTimezone`). Never compared with a moment.
--   * a CALENDAR DATE: a choice's or a rule's `fromDate` and `toDate`, a day
--     on the ORGANISATION's calendar, turned into moments only by
--     `FeedResolver` using the organisation's own time zone.
-- `CURRENT_TIMESTAMP` is NOT used as a default, deliberately. Nothing in this
-- portal sets the database session's time zone, so `CURRENT_TIMESTAMP` is the
-- database SERVER's own local time — not UTC. Decisions are compared by time
-- ("the newest decision on a repeating event is the one later dates follow"),
-- and a table holding a mix of server time and UTC would get that comparison
-- wrong by the server's offset. So every writer sets these columns itself, in
-- UTC: `UTC_TIMESTAMP()` in SQL, or the database's own clock read once and
-- passed in (`FeedResolver::databaseNowUtc()`).
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing, on a database that already has these columns and tables. The
-- installer runs full_schema.sql and then replays every numbered migration
-- whatever has already run, so every column is guarded by a check of
-- `information_schema` (the MySQL 8.0-safe form; MariaDB-only `IF NOT EXISTS`
-- on columns is refused by MySQL with ERROR 1064), and every table is
-- `CREATE TABLE IF NOT EXISTS`.
--
-- The backfill in B runs ONLY in the run that adds the column. A replay finds
-- the column already there and skips it, so it can never undo a "Don't show
-- via API" answer the resolver has since written (the same shape as
-- migrations 204 and 205, and for the same reason: #514 leak-hunt finding 27).
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION CANNOT DO
-- -----------------------------------------------------------------------------
-- It decides nothing about who sees what. `Portal\Core\FeedResolver` reads
-- these tables and writes its answers onto each event; `Portal\Core\
-- EventVisibility` reads those answers. The pages that let an administrator
-- make choices and rules, and approve or decline, arrive in part P8 of #514
-- (migration 207); until then only SQL can put a row in these tables. It has
-- been tested on MySQL 8.0 only. MariaDB is untested.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
-- =============================================================================


-- #############################################################################
-- 🅰️  A. tblExternalFeeds — the calendar's "Don't show via API" box
-- #############################################################################
-- Default 0, unticked, the owner's default. No backfill: every existing
-- calendar keeps sending its events to API keys exactly as a signed-out
-- visitor would see them, which is the owner's rule.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblExternalFeeds' AND COLUMN_NAME='apiOptOut');
SET @sql := IF(@c=0, 'ALTER TABLE `tblExternalFeeds` ADD COLUMN `apiOptOut` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = API keys never receive this calendar''''s events ("don''''t show via API"). 0 (the default, owner 24 September 2026) = keys receive exactly what a signed-out visitor sees. Any source opting out wins.'' AFTER `websiteOptIn`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅱️  B. tblEvents.importApiOptOut — the stored answer, and its backfill
-- #############################################################################

-- ➕ B1 The guard's answer is kept in @needApiOptOut BEFORE the ALTER, because
--    the backfill in B2 must run only in the run that adds this column (see
--    "WHAT REPLAY DOES" in the header). Once the column exists the same
--    question answers "no", and the backfill is skipped for good.
SET @needApiOptOut := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblEvents' AND COLUMN_NAME='importApiOptOut');
SET @sql := IF(@needApiOptOut = 1, 'ALTER TABLE `tblEvents` ADD COLUMN `importApiOptOut` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''Imported events only: 1 = API keys never receive it. Written only by FeedResolver: 1 when the calendar or ANY choice or rule that applies to the event opts out. Default 1 so a writer that forgets it fails closed; ignored when externalFeedID IS NULL.'' AFTER `importWebsite`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧭 B2 The backfill: every EXISTING copied-in event may go on reaching API
--    keys, exactly as the resolver would decide today (no choice or rule
--    exists yet, and every calendar's box is unticked). Only in the run that
--    added the column, so a replay can never undo a later opt-out.
SET @sql := IF(@needApiOptOut = 1, 'UPDATE `tblEvents` SET `importApiOptOut` = 0 WHERE `externalFeedID` IS NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅲  C. Four new tables
-- #############################################################################
-- COLLATE utf8mb4_general_ci is written out on every one of them on purpose —
-- see "WHY THE NEW TABLES' COLLATION IS WRITTEN OUT" in the header.

-- 🎯 C1 Single-date and series choices.
--    The UNIQUE key means one choice per date, and one per repeating event,
--    per calendar. A series choice ALWAYS stores an empty
--    `externalRecurrenceKey`; a series row carrying a key is ignored by the
--    resolver, so a hand edit cannot make a "series" choice quietly act as a
--    single-date one.
CREATE TABLE IF NOT EXISTS `tblExternalEventChoices` (
    `choiceID`              INT NOT NULL AUTO_INCREMENT COMMENT 'The choice''s own number.',
    `siteID`                INT NOT NULL COMMENT 'The organisation; always the calendar''s own (checked when saved and when read).',
    `feedID`                INT NOT NULL COMMENT 'The calendar the event comes from.',
    `scope`                 ENUM('date','series') NOT NULL COMMENT 'date = this one date; series = every date of the repeating event.',
    `externalUidHash`       BINARY(32) NOT NULL COMMENT 'The event''s identity (SHA-256 of its UID), never an eventID: a choice must survive the row being removed and brought back.',
    `externalRecurrenceKey` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Which date, for scope=date. Always empty for scope=series; a series row with a key is ignored.',
    `audienceLevel`         ENUM('public','members','groups','hidden') NOT NULL COMMENT 'Who may see it while the choice applies.',
    `detailLevel`           ENUM('basic','full') NOT NULL DEFAULT 'basic' COMMENT 'basic = title, date and time only, for people outside the calendar''s own audience.',
    `websiteOptIn`          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = also on the public website feeds; only meaningful at public.',
    `apiOptOut`             TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = API keys never receive the dates this choice covers, whatever the calendar says. It can only narrow: a 0 here never overrides a 1 on the calendar or a rule.',
    `fromDate`              DATE NULL COMMENT 'First day it applies, on the organisation''s clock. A calendar date, not a moment; empty = no start.',
    `toDate`                DATE NULL COMMENT 'Last day it applies, inclusive, on the organisation''s clock; empty = no end.',
    `overridesPrivateMark`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = an administrator ticked the warning and chose to show an event the outside calendar marked private.',
    `note`                  VARCHAR(255) NULL COMMENT 'The administrator''s own note. Free text: may name somebody; see the personal-data catalogue.',
    `createdByID`           INT NULL COMMENT 'Who made the choice; emptied, never cascaded, if that account is removed.',
    `createdAt`             DATETIME NOT NULL COMMENT 'UTC moment; always written by the writer (UTC_TIMESTAMP()). No default on purpose: see the header.',
    `updatedByID`           INT NULL COMMENT 'Who last changed it; emptied, never cascaded, if that account is removed.',
    `updatedAt`             DATETIME NULL COMMENT 'UTC moment of the last change.',
    PRIMARY KEY (`choiceID`),
    UNIQUE KEY `uq_extchoice_identity` (`feedID`,`scope`,`externalUidHash`,`externalRecurrenceKey`),
    KEY `idx_extchoice_site` (`siteID`),
    CONSTRAINT `fk_extchoice_feed`    FOREIGN KEY (`feedID`)      REFERENCES `tblExternalFeeds`(`feedID`) ON DELETE CASCADE,
    CONSTRAINT `fk_extchoice_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL,
    CONSTRAINT `fk_extchoice_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='An administrator''s choice of who sees one date, or every date, of an event copied in from an outside calendar (#514).';

-- 📏 C2 Rules. A rule with `isActive = 0` does nothing at all — its API box
--    included — and a rule is only ever read by the resolver for its own
--    calendar and organisation.
CREATE TABLE IF NOT EXISTS `tblExternalFeedRules` (
    `ruleID`        INT NOT NULL AUTO_INCREMENT COMMENT 'The rule''s own number.',
    `siteID`        INT NOT NULL COMMENT 'The organisation; always the calendar''s own (checked when saved and when read).',
    `feedID`        INT NOT NULL COMMENT 'The calendar whose events the rule looks at.',
    `name`          VARCHAR(120) NOT NULL COMMENT 'The administrator''s own label.',
    `isActive`      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = switched off: the rule does nothing at all, the API box included.',
    `audienceLevel` ENUM('public','members','groups','hidden') NOT NULL COMMENT 'Who may see a matching event. Several matching rules combine to the narrowest.',
    `detailLevel`   ENUM('basic','full') NOT NULL DEFAULT 'basic' COMMENT 'basic = title, date and time only; basic wins if any matching rule says so.',
    `websiteOptIn`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = also on the public website feeds. Counts only if EVERY matching rule ticks it.',
    `apiOptOut`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = API keys never receive the events this rule matches, whatever the calendar says. It can only narrow: a 0 here never overrides a 1 on the calendar, a choice or another rule.',
    `fromDate`      DATE NULL COMMENT 'First day it applies, on the organisation''s clock. A calendar date, not a moment; empty = no start.',
    `toDate`        DATE NULL COMMENT 'Last day it applies, inclusive, on the organisation''s clock; empty = no end.',
    `createdByID`   INT NULL COMMENT 'Who made the rule; emptied, never cascaded, if that account is removed.',
    `createdAt`     DATETIME NOT NULL COMMENT 'UTC moment; always written by the writer (UTC_TIMESTAMP()). No default on purpose: see the header.',
    `updatedByID`   INT NULL COMMENT 'Who last changed it; emptied, never cascaded, if that account is removed.',
    `updatedAt`     DATETIME NULL COMMENT 'UTC moment of the last change.',
    PRIMARY KEY (`ruleID`),
    KEY `idx_extrule_feed_active` (`feedID`,`isActive`),
    KEY `idx_extrule_site` (`siteID`),
    CONSTRAINT `fk_extrule_feed`    FOREIGN KEY (`feedID`)      REFERENCES `tblExternalFeeds`(`feedID`) ON DELETE CASCADE,
    CONSTRAINT `fk_extrule_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL,
    CONSTRAINT `fk_extrule_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='An administrator''s rule: who sees every event of one outside calendar that matches a description (#514).';

-- 🔎 C3 The conditions a rule tests. An event matches a rule when EVERY
--    ordinary condition matches and NO exception does. A rule with no
--    ordinary condition matches nothing at all (a rule that matched every
--    event would really be the calendar's own setting).
--
--    The columns are `matchField` and `matchValue`, not `field` and `value`:
--    VALUE is a keyword in MySQL 8, and the audit script that checks column
--    names already mis-reads keywords hidden inside names.
CREATE TABLE IF NOT EXISTS `tblExternalRuleConditions` (
    `conditionID` INT NOT NULL AUTO_INCREMENT COMMENT 'The condition''s own number.',
    `ruleID`      INT NOT NULL COMMENT 'The rule this condition belongs to.',
    `isException` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = an event matching this is left OUT of the rule.',
    `matchField`  ENUM('category','title','location') NOT NULL COMMENT 'category = one of the event''s own category words; location = locationName.',
    `matchType`   ENUM('equals','contains','word') NOT NULL COMMENT 'Category conditions always compare whole words (equals), whatever this says.',
    `matchValue`  VARCHAR(255) NOT NULL COMMENT 'Compared after lower-casing, removing invisible formatting characters and squeezing spaces.',
    PRIMARY KEY (`conditionID`),
    KEY `idx_extcond_rule` (`ruleID`),
    CONSTRAINT `fk_extcond_rule` FOREIGN KEY (`ruleID`) REFERENCES `tblExternalFeedRules`(`ruleID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='The conditions an outside-calendar rule tests (#514). Owned by the rule, deleted with it.';

-- ✅ C4 Approval rows: one per date waiting for, or having had, an
--    administrator's agreement to a widening.
--
--    `requestHash` fingerprints WHAT was asked for (origin, level, detail,
--    website box, list); `contentHash` fingerprints what viewers would SEE,
--    with dates and times left out on purpose (D14: a moved date is not a
--    reason to ask again). A change to either makes a new request.
--
--    `requestedAudienceSummary` holds COUNTS only ("Selected groups: 2 small
--    groups, 3 named people"), never names. A label built from the list would
--    carry the names of the people on it, and "delete my data" could never
--    find a name inside a label. The names are shown live from
--    tblExternalAudienceMembers instead, which erasure does reach. A changed
--    list changes `requestHash`, so a pending row always describes the list
--    as it stands.
--
--    `originID` has no foreign key: it points at one of two tables (a rule or
--    a choice), and the row is kept as history after the rule or choice is
--    deleted.
--
--    `snapStart` and `snapEnd` are TIMES ON A CLOCK, copied from the event,
--    in the zone named by `snapTimezone`. They are not UTC moments and must
--    never be compared with one.
CREATE TABLE IF NOT EXISTS `tblExternalEventApprovals` (
    `approvalID`   INT NOT NULL AUTO_INCREMENT COMMENT 'The approval row''s own number.',
    `siteID`       INT NOT NULL COMMENT 'The organisation; always the calendar''s own.',
    `feedID`       INT NOT NULL COMMENT 'The calendar the event comes from.',
    `eventID`      INT NOT NULL COMMENT 'The date waiting for, or having had, a decision.',
    `origin`       ENUM('rule','choice') NOT NULL COMMENT 'What asked for the widening.',
    `originID`     INT NOT NULL COMMENT 'ruleID or choiceID. No foreign key: it points at one of two tables, and the row is kept as history after the rule or choice is deleted.',
    `requestHash`  CHAR(64) NOT NULL COMMENT 'Fingerprint of WHAT was asked for (origin, level, detail, website box, list). A change makes a new request.',
    `contentHash`  CHAR(64) NOT NULL COMMENT 'Fingerprint of what viewers would SEE, dates and times left out on purpose (D14).',
    `status`       ENUM('pending','approved','declined','superseded','withdrawn') NOT NULL COMMENT 'pending = waiting; superseded = replaced by a newer request or content; withdrawn = nothing asks for it any more.',
    `reason`       ENUM('new_match','content_changed','address_changed','choice','series_match') NOT NULL COMMENT 'Why the row exists. series_match = it followed a decision on another date of the same repeating event with the same request and content.',
    `requestedLevel`   ENUM('public','members','groups','hidden') NOT NULL COMMENT 'The level asked for.',
    `requestedDetail`  ENUM('basic','full') NOT NULL COMMENT 'The detail asked for.',
    `requestedWebsite` TINYINT(1) NOT NULL COMMENT '1 = the website box was asked for too.',
    `requestedAudienceSummary` VARCHAR(255) NOT NULL COMMENT 'Counts only, never names (see the table note).',
    `snapTitle`    VARCHAR(255) NOT NULL COMMENT 'The event''s title when the request was last looked at.',
    `snapStart`    DATETIME NOT NULL COMMENT 'Wall-clock time copied from startDateTime, in snapTimezone. NOT a UTC moment.',
    `snapEnd`      DATETIME NULL COMMENT 'Wall-clock time copied from endDateTime, in snapTimezone. NOT a UTC moment.',
    `snapTimezone` VARCHAR(64) NOT NULL COMMENT 'The zone snapStart and snapEnd are written in (the event''s timezone).',
    `snapIsAllDay` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a whole-day event.',
    `snapCategoryID`  INT NULL COMMENT 'The portal category the event had.',
    `snapDescription` TEXT NULL COMMENT 'Filled only when requestedDetail = full.',
    `snapLocation` VARCHAR(255) NULL COMMENT 'Filled only when requestedDetail = full.',
    `snapUrl`      VARCHAR(500) NULL COMMENT 'Filled only when requestedDetail = full.',
    `decidedByID`  INT NULL COMMENT 'Who approved or declined; emptied, never cascaded, if that account is removed.',
    `decidedAt`    DATETIME NULL COMMENT 'UTC moment; the newest decision on a repeating event is the one later dates follow.',
    `decisionNote` VARCHAR(500) NULL COMMENT 'The decider''s own note, or the portal''s explanation (for example which date this followed).',
    `createdAt`    DATETIME NOT NULL COMMENT 'UTC moment; always written by the writer. No default on purpose: see the header.',
    `updatedAt`    DATETIME NULL COMMENT 'UTC moment of the last change.',
    PRIMARY KEY (`approvalID`),
    KEY `idx_extappr_site_status` (`siteID`,`status`),
    KEY `idx_extappr_event_status` (`eventID`,`status`),
    KEY `idx_extappr_feed_status` (`feedID`,`status`),
    KEY `idx_extappr_request` (`feedID`,`requestHash`,`contentHash`,`status`),
    CONSTRAINT `fk_extappr_feed`    FOREIGN KEY (`feedID`)      REFERENCES `tblExternalFeeds`(`feedID`) ON DELETE CASCADE,
    CONSTRAINT `fk_extappr_event`   FOREIGN KEY (`eventID`)     REFERENCES `tblEvents`(`eventID`)       ON DELETE CASCADE,
    CONSTRAINT `fk_extappr_decider` FOREIGN KEY (`decidedByID`) REFERENCES `tblUsers`(`userID`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='One date of an outside-calendar event waiting for, or having had, an administrator''s agreement to be shown more widely (#514).';


-- #############################################################################
-- 🅴  E. tblEvents.externalDuplicate — bring its description into line
-- #############################################################################
-- Round-2 check gap 5 (24 September 2026). Migration 205 added this column
-- with the comment "1 = the last download held this identity twice; choices
-- and rules are ignored for it". FIX B (round-1 check, also 24 September
-- 2026) changed that behaviour: a NARROWING choice or rule still applies to
-- a duplicate; only a WIDENING one is ignored (see FeedResolver.php plan()
-- step 6). full_schema.sql's own copy of the column was corrected to say so
-- at the same time, but migration 205 itself is committed and is left
-- alone — the owner's standing rule is never to edit a committed migration.
--
-- That correction on its own leaves the two install paths disagreeing. A
-- FRESH install reads full_schema.sql and gets the corrected wording
-- straight away. An UPGRADED database only ever replays 205, and 205's own
-- guard adds the column ONLY WHEN IT IS MISSING (see its header) — so once
-- the column exists a replay of 205 never touches its comment again, and an
-- upgraded installation was left carrying the now-untrue wording for ever.
--
-- This statement re-states the column, changing NOTHING about it except the
-- comment — the same TINYINT(1), the same NOT NULL, the same DEFAULT 0, the
-- same position (AFTER externalUrl) — and only when the comment stored on
-- THIS database does not already match the corrected wording, so a replay
-- of this migration is a genuine no-op on every run after the first (the
-- same guard idiom as migrations 037, 112, 138 and 165, just comparing
-- COLUMN_COMMENT instead of a length or an existence count).
SET @extDupComment := (
    SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'externalDuplicate'
);
SET @sql := IF(
    @extDupComment IS NOT NULL AND @extDupComment <> '1 = the last download held this identity twice; a narrowing choice or rule still applies to it, a widening one is ignored and never gets an approval row (round-1 check FIX B, 24 September 2026; the column was first added by migration 205; this description was corrected by migration 206; see FeedResolver.php plan() step 6)',
    'ALTER TABLE `tblEvents` MODIFY COLUMN `externalDuplicate` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the last download held this identity twice; a narrowing choice or rule still applies to it, a widening one is ignored and never gets an approval row (round-1 check FIX B, 24 September 2026; the column was first added by migration 205; this description was corrected by migration 206; see FeedResolver.php plan() step 6)'' AFTER `externalUrl`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 📋  D. Self-record
-- #############################################################################
-- The installer replays every numbered migration after full_schema.sql and
-- ignores tblMigrations, so this has to be safe to run a second time.
INSERT INTO `tblMigrations` (`filename`) VALUES ('206_external_calendar_choices_rules_approvals.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
