-- =============================================================================
-- Migration 229: transcription a customer can actually reach — and that cannot
--                bill the same recording twice
-- =============================================================================
-- Path: _sql/229_transcription_reachable.sql
-- Description: Adds `tblTranscript.startedAt` so a job that has been handed to
--              an outside company can be told apart from one that is merely
--              waiting, and seeds the monthly spending cap for transcription.
--              No table is created and nothing is removed.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- WHAT WAS WRONG, AND WHY IT MATTERED
-- -----------------------------------------------------------------------------
-- Transcription looked finished. `Portal\Core\Transcription` could send a
-- recording to OpenAI Whisper, AssemblyAI or a local whisper build, store the
-- text, and index it for `/recordings/search`. The handler that queues a
-- recording (`recordings/transcribe`) existed, its address was registered, and
-- it worked. The page that shows a transcript existed too.
--
-- NOTHING ANYWHERE POSTED TO THAT HANDLER. There was no button, on any page, so
-- no recording could ever be queued, so no transcript was ever made, so
-- `/recordings/search` searched an empty table for ever. A customer reading the
-- feature list would have found an app switch, a provider chooser, somewhere to
-- type an API key, and no way to transcribe anything.
--
-- WHY THIS MIGRATION EXISTS AT ALL — THE DOUBLE BILL
-- -----------------------------------------------------------------------------
-- Adding the button is a change to a page and needs no migration. This column
-- does, and the reason is money.
--
-- A transcript row goes `queued` → `processing` → `completed`. The run that
-- flips it to `processing` then makes a single HTTP call that can last ten
-- minutes (OpenAI) or twenty-five (AssemblyAI). `Transcription::queue()` used
-- `ON DUPLICATE KEY UPDATE … status = "queued"`, so pressing the new button
-- again while that call was in flight put the row back to `queued`, a second
-- run picked it up, and the SAME audio was sent to the provider TWICE — two
-- bills, two writes of the same text, and nothing anywhere to say it had
-- happened.
--
-- Refusing to re-queue a row that is `processing` fixes that, and on its own
-- introduces a worse fault: this portal runs on shared hosting, where a long
-- request is killed by the host without warning. A row killed mid-call stays
-- `processing` for ever, and a flat refusal would wedge that recording
-- permanently with no way back.
--
-- So the refusal has to be "processing, and started recently", which means
-- knowing WHEN it started. `queuedAt` cannot answer that: it records when
-- somebody asked, not when the provider was called, and a job can sit in the
-- queue for days. Hence a column.
--
-- WHICH CLOCK THIS COLUMN IS ON, said plainly
-- -----------------------------------------------------------------------------
-- The server's own clock, matching `queuedAt` and `generatedAt`, which this
-- table has always used (`NOW()`, not `UTC_TIMESTAMP()`). That is deliberate
-- consistency, not an endorsement: the mail queue stores UTC and is the better
-- pattern. Changing half of one table's columns to UTC would make the
-- comparison below wrong, so it is left alone and written down in DEV_NOTES
-- instead.
--
-- It is only ever compared against `NOW()` on that same clock, so the
-- comparison is correct whatever zone the server keeps. On the two nights a
-- year when the clocks change, a difference measured in local time can be out
-- by an hour: a job stuck at the wrong moment is released after 60 or 180
-- minutes instead of 120. That is harmless for a timeout whose only job is to
-- free a wedged row, and it is said here rather than pretended away.
--
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- The column is added only when `information_schema` says it is absent —
-- MySQL 8.0 has no `ADD COLUMN IF NOT EXISTS` (that is MariaDB only, and this
-- project's SQL has to load on both). The setting is
-- `INSERT … ON DUPLICATE KEY UPDATE` on `defaultValue` ONLY, so a customer who
-- has already chosen a cap keeps it when the installer replays the migrations.
-- =============================================================================

-- #############################################################################
-- ⏱️ A. When the provider was actually called
-- #############################################################################
-- NULL means "never handed to anybody": a row that is queued, or one written
-- before this migration ran. A `processing` row with NULL here is therefore
-- treated as STUCK and may be queued again — the alternative would wedge every
-- row that was mid-flight at upgrade time, with no way for an administrator to
-- free it.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tblTranscript'
       AND COLUMN_NAME  = 'startedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblTranscript` ADD COLUMN `startedAt` DATETIME DEFAULT NULL COMMENT ''When this recording was handed to the transcription company, on the SERVER''''S OWN CLOCK to match queuedAt and generatedAt. NULL means it never was. Compared only against NOW() on the same clock, to tell a job that is genuinely running from one the host killed mid-call.'' AFTER `queuedAt`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- #############################################################################
-- 💳 B. The monthly spending cap
-- #############################################################################
-- Transcription spends real money with an outside company, and the API key is
-- installation-wide: ONE key, seeded with no siteID, pays for every
-- organisation on the installation. Until now the only button that could spend
-- it was unreachable, so there was nothing to cap. Adding a reachable button
-- without a cap would let any organisation's administrator spend the
-- installation owner's money without limit.
--
-- THIS IS A LIMIT, so it follows the rule the owner set on 29 October 2026
-- (#668) rather than the ordinary settings rule: the installation's value CAPS
-- an organisation's. An organisation may hold itself to LESS than the
-- installation allows and can never give itself more. The two administrators
-- may be different people — a hosting provider and its customer — which is why
-- this is a boundary between parties rather than one person's convenience.
-- `Transcription::capFrom()` is the one place that works it out, in the same
-- shape as `EventRecurrence::limitsFrom()`.
--
-- £50 a month is roughly 100 hours of Whisper at list price — far more than a
-- church recording one service a week will use, and low enough that a mistake
-- costs pounds rather than hundreds. A customer who wants more raises it on
-- the installation's own Transcription page.
--
-- Installation-wide (no siteID) on purpose: an organisation's row, when it
-- chooses one, is written by that page and read only through `capFrom()`.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'transcription.monthCapPence', '5000', '5000', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('229_transcription_reachable.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
