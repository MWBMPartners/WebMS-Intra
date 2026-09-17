-- =============================================================================
-- Migration 197: a rate limit on the public check-in page (#519)
-- =============================================================================
-- Two settings only. No tables, no columns, no indexes, no addresses.
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- `/attend/save` (the public "no login" check-in a visitor reaches by
-- scanning a QR code or typing a kiosk address) had no rate limit at all.
-- Fixing #519's real fault — the page showing INTERNAL events to anyone —
-- also meant giving this page the same kind of protection every other
-- public form in the portal already has (the prayer-request page, the
-- lost-property report, and so on): a limit on how many submissions one
-- internet connection may make against one event in a short window.
--
-- -----------------------------------------------------------------------------
-- WHY THE DEFAULT IS 500, NOT SOMETHING SMALLER
-- -----------------------------------------------------------------------------
-- A whole congregation on a venue's own wifi is ONE internet connection, and
-- therefore shares ONE bucket. A tighter number (60 was tried and rejected
-- while planning this fix) would refuse the 61st genuine person walking in
-- the door mid-service. 500 in 300 seconds still makes an automated flood
-- slow and noticeable (a flood tested against the unprotected page ran at
-- about 31 a second), while leaving room for a very busy door. The owner
-- chose 500 on 17 September 2026, after the planning step proposed 300.
-- Setting `attend.rateLimit.max` to '0' switches the limit off entirely —
-- that is a deliberate escape hatch, not an oversight, for the rare venue
-- where even 500 is genuinely too tight.
--
-- This is ONE setting for the whole installation. Making it settable per
-- event, per venue and per organisation, with the most specific winning, is
-- issue #526.
--
-- WHAT THIS CANNOT DO: it cannot stop somebody spreading check-ins across
-- many different events, or across many different internet addresses, and
-- it cannot tell one genuine person at a venue from another when they share
-- that venue's own connection. It makes bulk junk slow, not impossible.
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing, on a database that already has these rows. The installer runs
-- full_schema.sql and then replays every numbered migration regardless of
-- which have already run, so both inserts below carry
-- `ON DUPLICATE KEY UPDATE defaultValue = ...` (never settingValue — writing
-- settingValue would silently reset a number an administrator has since
-- chosen, every single time the installer replays; this is the same
-- settings-table rule migration 187 established).
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- =============================================================================

-- #############################################################################
-- ⚙️  A. The two settings
-- #############################################################################
-- Portal-wide by default (siteID NULL), same as every other tblSettings row
-- seeded this way — an organisation may still override its own copy through
-- the ordinary settings editor, which is why the PHP side reads this with a
-- `??` fallback rather than assuming the row is always present (see
-- anon-checkin-save.php's own comment on that choice). This statement is
-- repeated word for word in full_schema.sql — every database change has to
-- reach both the installer and the upgrade path (check_schema_seed_parity.py
-- enforces this).

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'attend.rateLimit.max',           '500', '500', 0),
    (NULL, 'attend.rateLimit.windowSeconds', '300', '300', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 📋 B. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('197_attend_rate_limit.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
