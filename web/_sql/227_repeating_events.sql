-- =============================================================================
-- Migration 227: repeating events — the two ceilings, and the page that sets
--                them
-- =============================================================================
-- Path: _sql/227_repeating_events.sql
-- Description: Seeds the two settings that decide how big a repeating series
--              may be (`calendar.repeat.maxOccurrences` and
--              `calendar.repeat.maxYears`) and registers the admin page that
--              changes them. No table is created or altered: the series and
--              rule tables this feature writes have existed since the original
--              schema — nothing had ever written to them.
-- Package: WebMS Intra
-- Author: MWBM Partners Ltd (t/a MWservices)
-- Copyright: All Rights Reserved
-- Version: 1.4.0
--
-- WHY THERE IS A CEILING AT ALL
-- -----------------------------------------------------------------------------
-- A repeat rule with no end date and no "repeat N times" is endless, and every
-- date it produces becomes a real row in `tblEvents`. A series is created
-- inside ONE web request, on shared hosting, under whatever time limit the host
-- sets. So something has to stop it.
--
-- WHY IT IS A SETTING RATHER THAN A NUMBER IN THE CODE
-- -----------------------------------------------------------------------------
-- The first version of `Portal\Core\EventRecurrence` hard-coded 200 dates and a
-- three-year horizon. Those are sensible numbers for a church with one Sunday
-- service and wrong for a school with a daily registration period — and the
-- portal is a product, not one customer's installation. The owner asked on
-- 29 October 2026 for the ceiling to be settable per organisation and per
-- installation, and these are the two rows that make that possible.
--
-- Both are seeded with NULL siteID, which is the installation-wide default
-- every organisation inherits. An organisation that wants its own writes a row
-- with its own siteID; the settings loader in `bootstrap.php` reads the
-- installation-wide rows first and lets a site row overwrite them, so
-- "per organisation and per installation" needs no further machinery.
--
-- THE VALUES ARE TEXT, like every other row in this table. `EventRecurrence::
-- limitsFrom()` is the one place that reads them, and it falls back to its own
-- defaults for anything unusable — including 0, which would otherwise make the
-- repeat controls appear to work and silently produce nothing.
--
-- A CEILING ON THE CEILING. The class refuses to honour more than 1,000 dates
-- or 10 years however these rows are edited. That is not seeded here because it
-- is not a customer's choice; see the `HARD_MAX_*` constants and the reasons
-- written beside them.
--
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Both statements are `INSERT … ON DUPLICATE KEY UPDATE`. The settings rows
-- match on `uq_setting_key_scope` (`settingKey`, `siteScope`), where
-- `siteScope` is `COALESCE(siteID, -1)` — added by migration 187 precisely so
-- that a NULL siteID collapses to one value and DOES match, instead of piling
-- up a new NULL row on every replay.
--
-- ON REPLAY the settings rows update `defaultValue` ONLY, never
-- `settingValue`. A customer who has already chosen 52 dates keeps 52; only the
-- recorded default is refreshed. Writing `settingValue` here would quietly undo
-- their choice every time the installer replays the migrations, which it does
-- on every upgrade.
-- =============================================================================

-- #############################################################################
-- ⚙️ A. How big a repeating series may be
-- #############################################################################

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'calendar.repeat.maxOccurrences', '200', '200', 0),
    (NULL, 'calendar.repeat.maxYears',       '3',   '3',   0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- #############################################################################
-- 🔗 B. The page that changes them
-- #############################################################################
-- Both are protected (1): an administrator only. The save handler also refuses
-- an installation-wide change to anybody who is not a GLOBAL administrator,
-- because that row is the one every other organisation inherits.
--
-- The addresses carry no `.php`, which is not a style preference here: this
-- portal's `.htaccess` answers 404 for every address ending in `.php` except
-- the three pages that genuinely live in the web root.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/repeats',      'admin/settings/repeats/index.php', 1),
    ('admin/settings/repeats/save', 'admin/settings/repeats/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋 C. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this has to be safe to
--        run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('227_repeating_events.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
