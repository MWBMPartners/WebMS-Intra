-- =============================================================================
-- Migration 286: an erasure request for an e-mail address (#964, D-140; #1009, D-176)
-- =============================================================================
-- What this changes:
--
--   A. `tblErasureRequest` gains `subjectKind`: whose erasure a request is.
--      'account' (the default, and every request that exists already) is a
--      person with an account, found through `userID`. 'visitor_address' is an
--      e-mail address, filed by an administrator who has checked, outside the
--      portal, that the request comes from the person who uses it, to reach the
--      event suggestions made without signing in and (since #1009, D-176) the
--      prayer requests sent through the anonymous form with that address. The
--      address may ALSO belong to an account - a member who used the anonymous
--      form while signed in has one - and nothing checks; what is found through
--      an account is left to that member's own request. The column's comment
--      was changed for #1009 in this file rather than by a new migration: no
--      installation exists yet (D-135), and only the comment changed, not the
--      column.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- A visitor who suggests an event gives a name and an e-mail address
-- (`tblEvents.submitterName`, `submitterEmail`), and the approval e-mail D-134
-- sends leaves the address in the send log. An erasure finds a person through
-- their account, and a visitor has none, so nothing could reach any of it. The
-- owner, 11 November 2026 (D-140): erase on request only, by e-mail address,
-- and clear the matching sent-mail and mail-queue rows with it.
--
-- WHY A COLUMN AND NOT "userID IS NULL". `userID` is already emptied on every
-- COMPLETED request (GdprEraser::closeRequest()), so an empty account number
-- cannot say which kind a request was; and an older request whose account had
-- gone would be read as a visitor's and run against the address on it, which
-- may well be a member's. The kind has to be stated, not inferred.
--
-- WHY 'account' AS THE DEFAULT. Every request already on file, and every one a
-- member files (GdprEraser::fileRequest() names no kind), is an account's. A
-- whole-database backup taken before this migration has no such column, and a
-- restore inserts only the columns a snapshot holds, so the default is also
-- what such a restore gives every row - which is right.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` (see .claude/rules/database.md). SAFE TO RUN TWICE:
-- the column is added only when it is missing. Every identifier is backticked.
-- The definition is word for word what `full_schema.sql` says, in the same
-- place (after `notes`), so an upgraded database and a fresh one agree.
-- =============================================================================

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblErasureRequest'
      AND `COLUMN_NAME`  = 'subjectKind'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblErasureRequest` ADD COLUMN `subjectKind` ENUM(''account'',''visitor_address'') NOT NULL DEFAULT ''account'' COMMENT ''Whose erasure this is. account: a person with an account, found through userID (every request filed before #964, and every request a member files). visitor_address: an e-mail address, filed by an administrator to reach the event suggestions and anonymous-form prayer requests sent with it, whether or not the address also belongs to an account (#964, D-140; #1009, D-176); it reaches only this organisation''''s event suggestions, anonymous-form prayer requests and approval e-mails for that address, and nothing found through an account'' AFTER `notes`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('286_erasure_by_visitor_address.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
