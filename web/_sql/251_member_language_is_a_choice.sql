-- =============================================================================
-- Migration 251: an empty language means "never chose one" (#763, D-060)
-- =============================================================================
-- One column default changed, and the rows still holding the old default
-- emptied. Nothing is dropped and no row is deleted.
--
-- -----------------------------------------------------------------------------
-- WHY, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- A member's saved interface language was applied nowhere. The portal asked
-- "what language does this person want?" during start-up, before their session
-- existed, so the two answers that live in the session -- their own saved
-- choice and the language switcher's -- were skipped on every single request.
-- #763 moves the question to after the session starts, which makes
-- `tblUsers.locale` start deciding what language a signed-in person sees.
--
-- **And every row held 'en', because that is what this column's DEFAULT put
-- there -- not because anybody chose English.** Nothing under `web/` has ever
-- been able to write it: the only writer is `I18n::switchLocale()`, which
-- stores nothing unless a session is active, and its one caller has sat in
-- `bootstrap.php` -- before the session starts -- since the framework landed.
-- Established with `git log -S"switchLocale" -- web/`, which answers two
-- commits: fe2b3fb (7 March 2026) and bd1ef12.
--
-- So obeying the column as it stood would have pinned **every signed-in
-- member to English**: somebody whose browser asks for French, and who gets a
-- French portal today, would have started getting an English one, with
-- nothing on any page explaining why. (A draft of this header added "and no
-- control anywhere to change it", which is FALSE -- there is a language menu
-- in the top bar of every page. It has never worked, for the same reason the
-- column was never written, and #763 makes it work.)
-- THIRTEEN languages are matched from the browser's request on every
-- installation, so that is a visible regression rather than a theoretical one.
-- (A draft said twelve. Count them from the code, never from a document:
-- `I18n::$locales` holds en, cy, fr, de, es, pt, ar, he, fa, ur, zh, ja, ko.)
--
-- -----------------------------------------------------------------------------
-- WHAT THIS DISCARDS, SAID PLAINLY
-- -----------------------------------------------------------------------------
-- If an account somehow did hold a genuine choice of English, that choice is
-- forgotten and the browser decides again. The command above is how "no route
-- could have made such a choice" was established rather than assumed.
--
-- Safe to run twice, with one qualifier that matters. On MySQL the second run
-- is a true no-op: the default is already NULL so the guard skips the ALTER,
-- the emptying matches nothing, and the record is an upsert. On MariaDB the
-- ALTER re-runs every time, because its `COLUMN_DEFAULT` is an expression
-- rather than a value and so is never SQL NULL -- the end state is identical
-- (measured, twice, on mariadb:11.4), it simply rebuilds the table again. The
-- reasoning is beside section A.
--
-- ⚠️ AND A RESTORE FROM A SNAPSHOT TAKEN BEFORE THIS MIGRATION PUTS 'en' BACK
--    IN EVERY ROW, with this migration already recorded as run, so it will not
--    run again -- and the portal then reads that 'en' as a deliberate choice
--    of English for everybody. That is the state D-060 exists to prevent,
--    re-created by an ordinary recovery. There is no automatic cure: this
--    migration cannot distinguish a restored default from a real choice any
--    better the second time than the first. It is recorded on #763 rather than
--    guessed at, because emptying a column nobody asked us to empty is the
--    worse mistake.
-- =============================================================================

-- #############################################################################
-- A. The column default comes FIRST, and the order was wrong the other way
-- #############################################################################
-- ⚠️ THE DEFAULT IS CHANGED BEFORE THE ROWS ARE EMPTIED, AND THE FIRST VERSION
--    OF THIS MIGRATION HAD IT THE OTHER WAY ROUND WITH A COMMENT ARGUING FOR
--    IT. That comment claimed the rows had to go first because "a row inserted
--    BEFORE the default changed would arrive as 'en' and be emptied by
--    nothing". **It is the reverse.** Walk both orders:
--
--      rows then default -- between the two statements the default is STILL
--      'en', so a row inserted in that window arrives 'en' and nothing empties
--      it. From then on it reads as a deliberate choice of English, which is
--      the one state this migration exists to prevent.
--
--      default then rows -- a row inserted in the window arrives empty, which
--      is the right answer, and a row inserted before the default changed
--      arrives 'en' and IS emptied, because the emptying runs last.
--
--    Every row ends up right in this order and only this order. The window is
--    small (the portal is closed for an upgrade, and the only things that
--    insert into `tblUsers` are an administrator creating an account, the CSV
--    import and an accepted invitation), so the hole was unlikely -- but an
--    inverted explanation is worse than the hole, because it stops the next
--    reader looking.
--
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `MODIFY COLUMN IF EXISTS` with ERROR 1064 (see .claude/rules/database.md).
--
-- ⚠️ NO APOSTROPHES IN THE COMMENT TEXT BELOW, AND THAT IS NOT A STYLE
--    CHOICE. It sits inside a quoted string, inside another quoted string that
--    PREPARE reads, so a word like "members" with an apostrophe needs doubling
--    at BOTH levels -- and getting it wrong gives ERROR 1064 on every
--    installation. Migration 250 nearly shipped with exactly that fault.
--
-- ⚠️ AND THE GUARD IS "IS THERE A DEFAULT AT ALL", NOT "IS IT STILL 'en'".
--    A first version of this header claimed the latter. It cannot be the
--    latter, measured on MariaDB 11.4: MariaDB reports `COLUMN_DEFAULT` as a
--    literal EXPRESSION -- `'en'` with the quotes, and the four-character
--    string `NULL` once it is nullable -- where MySQL reports `en` and a true
--    SQL NULL. So `= 'en'` would match on neither server reliably, and
--    `IS NOT NULL` is true for ever on MariaDB.
--
--    What that costs, said plainly: **on MariaDB this statement is NOT a
--    no-op on a second run.** It re-applies the same MODIFY COLUMN, which
--    rebuilds `tblUsers`. The END STATE is identical either way (measured on
--    mariadb:11.4, run twice: same rows, same default), so replaying is safe
--    -- it is just not free, and the migration harness replays every
--    migration. On MySQL the second run really is a no-op.

SET @localeDefault := (
    SELECT `COLUMN_DEFAULT` FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblUsers'
      AND `COLUMN_NAME`  = 'locale'
);
SET @sql := IF(@localeDefault IS NOT NULL,
    'ALTER TABLE `tblUsers` MODIFY COLUMN `locale` VARCHAR(10) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT ''The language this member chose. NULL means they have not chosen, so Accept-Language decides (#763).''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- B. The rows: empty the ones still holding the old default
-- #############################################################################
-- Deliberately narrow: only rows that still hold exactly 'en'. A row holding
-- anything else was put there by hand or by a restore, and this leaves it
-- alone. It runs LAST so that it also catches anything inserted while section
-- A was running -- see the reasoning above.

UPDATE `tblUsers` SET `locale` = NULL WHERE `locale` = 'en';

-- #############################################################################
-- C. Self-record (safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('251_member_language_is_a_choice.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
