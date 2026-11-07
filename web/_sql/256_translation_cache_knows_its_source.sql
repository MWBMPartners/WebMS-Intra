-- =============================================================================
-- Migration 256: a cached translation knows which words it was made from (#799)
-- =============================================================================
-- One new column on `tblContentTranslation`. Nothing is dropped, nothing is
-- deleted, and no existing value is changed.
--
-- -----------------------------------------------------------------------------
-- WHAT IS WRONG, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- When a member presses Translate on an announcement, a project's description
-- or an update posted on a project, the translation is kept in
-- `tblContentTranslation` so the same words are not paid for twice. The row
-- was found by WHICH piece it was (table, row, field) and WHICH language, and
-- nothing else. Nothing ever cleared or refreshed it when the original was
-- edited, so once a piece had been translated into a language, every later
-- reader of that language saw the translation of the OLD words for ever:
-- after a corrected date, a moved event, a cancellation. The reader had no
-- way to tell. Measured on 7 November 2026 over real HTTP before this fix:
-- edit the announcement, press Translate again, and the answer was the old
-- words' translation with no request sent.
--
-- -----------------------------------------------------------------------------
-- THE FIX: A FINGERPRINT OF THE WORDS, NOT A DELETE IN EVERY EDITOR
-- -----------------------------------------------------------------------------
-- Each cached translation now records `sourceHash`, the SHA-256 of the exact
-- original words that were sent to be translated. `Portal\Core\Translation`
-- serves a cached row only while that fingerprint matches the words in the
-- source row NOW; otherwise the piece is translated afresh, and that request
-- is checked against both monthly limits and counted exactly like any other.
-- The old translation is then REPLACED in the same row, not kept beside it:
-- nothing would ever read it, and it is a translation of words the
-- organisation no longer says.
--
-- Deleting the cached rows whenever an original is saved was the alternative
-- and was rejected: every page and every API that can change those words
-- would have to remember to do it, and the first one that forgot would bring
-- the fault back with nothing to show it. The fingerprint needs no change in
-- any editor, because it is checked at the one place a translation is served.
--
-- -----------------------------------------------------------------------------
-- ROWS CACHED BEFORE THIS MIGRATION ARE TREATED AS STALE, ON PURPOSE
-- -----------------------------------------------------------------------------
-- They get no fingerprint. NULL never matches, so each is translated again
-- the first time anybody presses Translate on it in that language, and then
-- carries a fingerprint from then on.
--
-- Filling the column in here from each source row's CURRENT words was
-- considered and rejected. Nothing records which words an old row was made
-- from, so that would stamp every old translation as matching its original
-- whether or not the original had been edited since — which is exactly the
-- fault this migration exists to end, written into the data.
--
-- The cost, said plainly: each piece already translated is paid for and
-- counted ONCE more, on the first press after the upgrade. And if an
-- organisation has already used up its month, that press is refused where it
-- used to be answered from the cache, until the month turns or the limit is
-- raised. Both are the recoverable direction: a translation briefly not
-- available, rather than one that may be wrong and cannot be told apart.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` with ERROR 1064 (see .claude/rules/database.md).
-- The column goes AFTER `translatedContent` so an upgraded table has the same
-- column order as a fresh install from `full_schema.sql`.
--
-- Safe to run twice: the column is added only when it is absent, and the
-- record is an upsert.
-- =============================================================================

-- #############################################################################
-- 🔏 A. The column
-- #############################################################################

SET @hasHash := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblContentTranslation'
      AND `COLUMN_NAME`  = 'sourceHash'
);
-- ⚠️ NO APOSTROPHES IN THE COLUMN COMMENT BELOW, for the reason migration 250
--    gives: it sits inside a quoted string inside another quoted string that
--    PREPARE reads, so an apostrophe needs doubling at both levels and getting
--    it wrong is ERROR 1064 on every existing installation. It must also be
--    word for word the comment on the column in `full_schema.sql`, or an
--    upgraded database and a fresh install differ.
SET @sql := IF(@hasHash = 0,
    'ALTER TABLE `tblContentTranslation` ADD COLUMN `sourceHash` CHAR(64) DEFAULT NULL COMMENT ''SHA-256 of the exact original words this translation was made from (#799, migration 256). A cached row is served only while this matches the words in the source row now, so an edited original is translated afresh. NULL means the words are unknown (a row cached before migration 256), and such a row is never served.'' AFTER `translatedContent`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 📋 B. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('256_translation_cache_knows_its_source.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
