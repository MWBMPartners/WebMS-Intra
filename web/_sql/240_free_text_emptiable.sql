-- =============================================================================
-- Migration 240: three free-text columns an erasure must be able to empty (#715)
-- =============================================================================
-- Three columns relaxed from NOT NULL to nullable. No data changed.
--
--   tblPrayerRequests.subject   VARCHAR(255)
--   tblPrayerRequests.body      TEXT
--   tblCareCase.summary         VARCHAR(500)
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- The owner decided on 3 November 2026 that a person's own words about
-- themselves are emptied on an erasure request (#715): a prayer request's
-- subject and body, and a pastoral case's summary, which is a third party's
-- description of the erased person's circumstances and sat beside visit notes
-- that #709 already empties.
--
-- A NOT NULL COLUMN CANNOT BE EMPTIED, AND IT FAILS QUIETLY. `GdprEraser`
-- records a line saying the column could not be emptied and carries on, so the
-- request can still be marked completed with that person's words in place. As
-- `tools/audit-checks/check_erasure_columns_nullable.py` puts it: "a person told
-- their data was erased, with some of it still there, is the worst outcome this
-- part of the portal has."
--
-- That check is a hard gate and it caught this before the instruction shipped,
-- which is the whole reason it exists. It is the same gate migration 208 was
-- written for, when four "who did it" columns had the same problem.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS CANNOT DO
-- -----------------------------------------------------------------------------
-- It relaxes a constraint; it empties nothing. Existing rows keep their text
-- until an erasure request reaches them.
--
-- THE WRITERS ARE FINE; TWO READERS WERE NOT, AND THE INDEPENDENT CHECK FOUND
-- THEM. My first version of this paragraph said "the three writers … all supply
-- a value, and every reader prints the column through `htmlspecialchars((string)
-- …)`". Both halves were wrong, and the second was wrong in the direction that
-- breaks a page.
--
-- There are FIVE writers, not three, and all five do name the column:
--     grep -rn "INSERT INTO tblPrayerRequests\|INSERT INTO tblCareCase" web/_apps web/_core
--   _apps/prayer-requests/save.php, _apps/prayer-requests/anonymous-save.php,
--   _apps/prayer-requests/api/create.php, _apps/praise/new.php (a praise report
--   is a row in the same table), and _apps/care/case-save.php.
--
-- But `_apps/prayer-requests/index.php` printed `$req['subject']` with NO cast,
-- twice. Under `declare(strict_types=1)` that is
-- `TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string,
-- null given`, and the congregation feed is NOT filtered by submitter -- an
-- erased person's congregation-visible request stays in the list -- so the page
-- would really have met a NULL. The failure is the quiet kind: the page throws
-- mid-render, the error handler prints the footer, and the answer is an HTTP 200
-- ending in an ordinary `</html>` with the feed missing. `php -l` cannot see it.
-- Both lines now go through one `$requestSubject()` helper, which also gives a
-- neutral "Prayer request" rather than a blank heading. `view.php` does the same
-- for its page title. Every other reader of the three columns -- `care/index.php`,
-- `care/case.php`, `prayer-requests/view.php`, `index.php`'s own `body` -- already
-- cast, which is what made the two omissions look deliberate.
--
-- Nothing else depended on NOT NULL: no index, key or foreign key covers the
-- three columns, and no SQL anywhere orders, groups or compares on them
-- (`moderate.php`'s dynamic `SET` never writes `subject` or `body`). A row whose
-- text has been emptied reads as a request or a case with no words, which is
-- exactly what it now is.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The `information_schema` guard runs each change only while the column is
-- still NOT NULL, the shape migrations 194, 201, 235, 236 and 238 use. MySQL 8.0
-- has no `IF` for a column change. On a database that has already run this, each
-- guard finds the column already nullable and runs a harmless `SELECT 1`.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/715
-- =============================================================================

SET @needs := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPrayerRequests'
      AND COLUMN_NAME = 'subject' AND IS_NULLABLE = 'NO'
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblPrayerRequests` MODIFY COLUMN `subject` VARCHAR(255) DEFAULT NULL COMMENT ''Short title for the request. EMPTIABLE on purpose (#715, migration 240): an erasure empties what the submitter said about themselves, and a NOT NULL column cannot be emptied - the attempt fails quietly and the request can still close with their words in place''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPrayerRequests'
      AND COLUMN_NAME = 'body' AND IS_NULLABLE = 'NO'
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblPrayerRequests` MODIFY COLUMN `body` TEXT DEFAULT NULL COMMENT ''Full text of the prayer request. EMPTIABLE for the same reason as subject above (#715, migration 240)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCareCase'
      AND COLUMN_NAME = 'summary' AND IS_NULLABLE = 'NO'
);
SET @sql := IF(@needs = 1,
    'ALTER TABLE `tblCareCase` MODIFY COLUMN `summary` VARCHAR(500) DEFAULT NULL COMMENT ''What the case is about. EMPTIABLE on purpose (#715, migration 240): an erasure empties it, as it already empties the visit notes, and a NOT NULL column cannot be emptied''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('240_free_text_emptiable.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
