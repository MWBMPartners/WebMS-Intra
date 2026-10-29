-- =============================================================================
-- Migration 226: offboarding can actually erase somebody's personal details
-- =============================================================================
-- Path: _sql/226_offboarding_erasure.sql
-- Description: Adds the word the offboarding form now sends ('erase') to
--              tblOffboarding.dataDisposition, and the setting that decides
--              WHEN an erasure asked for at offboarding is carried out.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- WHAT WAS WRONG, because it is worth saying plainly
-- -----------------------------------------------------------------------------
-- The offboarding form offered three choices for a leaver's personal details:
-- Retain, Anonymise and Delete. `_apps/offboarding/do.php` wrote the chosen
-- word into the audit row and DID NOTHING ELSE. No erasure ever happened. The
-- administrator was told the offboarding had succeeded, and the person's name,
-- address and telephone number stayed exactly where they were.
--
-- The code explained itself with a note saying the erasure engine would ship
-- later. It had already shipped: `Portal\Core\GdprEraser` is what the Admin →
-- Erasure pages have used for some time. The note was true when written and
-- nobody went back to it — the same shape as the attendance comment in
-- migration 224 that promised a guarantee the schema never implemented.
--
-- TWO CHOICES, NOT THREE
-- -----------------------------------------------------------------------------
-- The form now offers "Keep their records" and "Erase their personal details".
-- There are two because the portal performs ONE kind of erasure: GdprEraser
-- deliberately ANONYMISES wherever deleting would destroy figures that have to
-- survive — attendance and giving keep the record and lose the name — and
-- deletes outright only where that is safe. "Anonymise" and "Delete" could
-- therefore never have behaved differently, and offering both was offering a
-- distinction that did not exist.
--
-- A genuine delete-everything mode is #663 and was NOT built: it would make
-- past attendance and giving figures wrong, which is the very thing the
-- anonymising design exists to prevent.
--
-- WHY THE OLD WORDS ARE KEPT IN THE COLUMN
-- The ENUM gains 'erase' and KEEPS 'anonymise' and 'delete'. Existing audit
-- rows must still read as they were written — an audit trail that silently
-- changes its own wording is worse than one with old words in it. The code
-- treats all three as "erase" when it reads them, which is honest: under the
-- old code none of them did anything, so there is no behaviour to preserve.
--
-- WHEN THE ERASURE RUNS, and why it is a setting
-- -----------------------------------------------------------------------------
-- `offboarding.erasureMode`:
--   'on_request'        The request is filed and waits on Admin → Erasure requests
--                       for somebody to carry out. THE DEFAULT. Nothing has to be
--                       scheduled for it to be safe, and nothing irreversible
--                       happens on its own.
--   'after_undo_window' The daily housekeeping job (`/cron/retention-sweep`)
--                       carries it out once `offboarding.undo_window_days`
--                       (7 by default) has passed.
--
-- Both file a real request through GdprEraser, so there is ONE audited erasure
-- path with its hash chain, rather than a second one written into offboarding
-- that would have to agree with the first about 35 tables for ever.
--
-- NEITHER MODE ERASES AT THE MOMENT OF OFFBOARDING, on purpose. Offboarding can
-- be undone by `_apps/offboarding/rehire.php` within the undo window, and
-- erasing somebody's details destroys exactly what a rehire puts back. The
-- owner chose these two modes on 29 October 2026 and asked for the immediate
-- option to be tracked separately (#663) rather than built now.
--
-- A WARNING FOR 'after_undo_window': it depends on the hosting company's
-- scheduler calling /cron/retention-sweep. If that is not set up, the job never
-- runs and the erasure never happens — the request simply waits, visible on
-- Admin → Erasure requests, which is the same place 'on_request' leaves it. So the
-- failure is a delay that can be seen, never a silent loss. The portal cannot
-- schedule itself: there is no command line on shared hosting.
--
-- SAFE TO RUN TWICE: the column change is guarded by reading the column's own
-- type first, and the setting is inserted with ON DUPLICATE KEY UPDATE.
-- =============================================================================

-- #############################################################################
-- 🏗️ A. The word the form now sends
-- #############################################################################
-- Guarded by looking at the column's declared type, not by MariaDB-only
-- `IF EXISTS` on MODIFY COLUMN, which MySQL 8 refuses with ERROR 1064.
-- LOCATE is used rather than LIKE so the comparison needs no escaping.

SET @has_erase := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblOffboarding'
       AND COLUMN_NAME  = 'dataDisposition'
       AND LOCATE('''erase''', COLUMN_TYPE) > 0
);
-- CONCAT(), never `||`. In MySQL `||` is LOGICAL OR unless the server happens
-- to run with PIPES_AS_CONCAT, so a first draft of this built the number 1 and
-- then tried to PREPARE it. It would have failed loudly rather than quietly,
-- but only on a server nobody had run it on yet.
SET @sql := IF(@has_erase = 0,
    CONCAT(
        'ALTER TABLE `tblOffboarding` MODIFY COLUMN `dataDisposition` ',
        'ENUM(''retain'',''anonymise'',''delete'',''erase'') NOT NULL DEFAULT ''retain'''
    ),
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- ⚙️ B. When an erasure asked for at offboarding is carried out
-- #############################################################################

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'offboarding.erasureMode', 'on_request', 'on_request', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('226_offboarding_erasure.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
