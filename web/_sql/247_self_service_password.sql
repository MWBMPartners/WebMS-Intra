-- =============================================================================
-- Migration 247: a member can add a password to their own account (#731)
-- =============================================================================
-- One setting and two addresses. No new table, no new column, nothing dropped.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS IS FOR, IN PLAIN TERMS
-- -----------------------------------------------------------------------------
-- A member whose account signs in ONLY through Microsoft, Google or a passkey
-- has no password at all, and until now had no way to get one:
--
--   * `Auth::createUser()`, the first-time single-sign-on path, creates rows in
--     `tblUsers` and `tblUserSites` and NO `tblLocalAccounts` row;
--   * "Forgot password" joins `tblLocalAccounts`, so it finds nothing for such
--     an account and no reset link is ever sent;
--   * "Change password" changes a password that is already there.
--
-- And an administrator may NOT type a password for somebody else: the owner
-- decided that on 3 November 2026 (decision D-018, issue #586), because a
-- second way into an account must never be chosen by anyone but its owner.
--
-- So losing the Microsoft or Google account, or the only device holding the
-- passkey, locked that person out for good. The two addresses below are the
-- owner's route out of that: the account's OWNER adds the password, and only
-- after proving, by a method they really hold, that they are still the person
-- at the keyboard.
--
-- -----------------------------------------------------------------------------
-- THE SETTING, AND WHY IT IS SEEDED INSTALLATION-WIDE AND SWITCHED ON
-- -----------------------------------------------------------------------------
-- `auth.password.selfAdd` says whether this is offered at all, and **the
-- STRICTER LEVEL WINS** (decision D-026, which quotes D-013): one installation
-- may serve many organisations, and whoever administers the installation may be
-- a DIFFERENT PERSON from the administrators of the organisations on it — a
-- hosting provider and its customers is the ordinary case. `0` is the stricter
-- value, because allowing this creates a second way into an account. So it is
-- offered only where the installation AND the organisation both allow it, which
-- `Portal\Core\SelfServicePassword` works out by reading the merged settings and
-- the installation's own layer separately.
--
-- It is seeded with NO `siteID`, which means installation-wide, and with the
-- value `1` — SWITCHED ON. That is deliberate: this is a RECOVERY route, and
-- issue #499's "fail closed" was built first and locked members out. An
-- installation that does not want it anywhere changes this row to `0`, and no
-- organisation can then turn it back on.
--
-- ⚠️ BE EXACT ABOUT THE ORGANISATION HALF: IT CANNOT BE SET THROUGH ANY PAGE
--    TODAY. An earlier version of this comment said "an organisation that does
--    not want it adds its own row with `0`", and that is not reachable.
--    Measured: nothing under `web/` writes this key outside
--    `Portal\Core\SelfServicePassword`, and `_apps/settings/save.php` REFUSES
--    to add a per-organisation row for any key the installation already holds —
--    the four `mail.queue.*` numbers are the only exception, through
--    `$onlyOwnRowClashes`. So an organisation's "no" needs hand-written SQL or
--    a restored backup.
--
--    This is the shape #668 already recorded: "All 32 limit-shaped candidates
--    are seeded INSTALLATION-WIDE ... so almost none of them is overridable
--    whatever its name suggests." The RULE in the class is right and is
--    exercised by section G of the self-test, which inserts the organisation's
--    row with raw SQL — a state no administrator can produce through a page,
--    said plainly there too, so nobody reads it as a control that exists.
--    Giving organisations a real control is #755.
--
-- NOTE WHAT FOLLOWS FROM THE RULE, so that nobody reads it as a fault: until
-- this migration has run, the class reads NOTHING at the installation level and
-- therefore answers "not allowed". Files reach a shared host before anybody
-- presses "Run migrations", so there is a real window in which the page refuses
-- politely. That is the recoverable direction; resolving "not chosen" to the
-- built-in default would let the default cap the installation, which is exactly
-- what D-013 rules out.
--
-- -----------------------------------------------------------------------------
-- THINGS TO KNOW ABOUT THIS FILE
-- -----------------------------------------------------------------------------
-- * It is SAFE TO RUN TWICE. The installer replays `full_schema.sql` and then
--   every numbered migration, ignoring `tblMigrations`, so this really does run
--   again. Both inserts end `ON DUPLICATE KEY UPDATE` with a no-op on the
--   setting (a customer who has since switched it off must NOT have it switched
--   back on by a replay) and with the target restated on the addresses (so a
--   moved file is corrected).
--
-- * NO `IF NOT EXISTS` on anything but a table: MySQL 8 rejects the MariaDB-only
--   form on columns and indexes with ERROR 1064. There are no column or index
--   changes here at all, so nothing needs the `information_schema` guard.
--
-- * Every identifier is backticked, table and column names alike, which is this
--   project's standing rule — a reserved word used bare does not parse, and no
--   linter can see it because the SQL is a string (#718, #276).
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/731
-- =============================================================================

-- #############################################################################
-- ⚙️ A. The setting: may a member give their own account a password?
-- #############################################################################
-- `settingValue` is restated as itself on a duplicate, NOT overwritten with
-- '1'. A customer who has switched this off must stay switched off through
-- every upgrade; `defaultValue` is brought up to date so the settings page can
-- still say what the portal ships with.

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.selfAdd', '1', 0, '1')
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 🗺️ B. The two addresses
-- #############################################################################
-- Both are protected (`isProtected` = 1): only a signed-in person reaches them,
-- and each page then asks `SelfServicePassword` whether this particular person
-- may go on. Neither address ends in `.php` — `.htaccess` answers 404 for every
-- address that does, except the three pages that really live in the web root.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/add-password',      'auth/account/add-password.php',      1),
    ('account/add-password-save', 'auth/account/add-password-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

-- #############################################################################
-- 📋 C. Self-record (safe to run a second time, for the reason in the header)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('247_self_service_password.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
