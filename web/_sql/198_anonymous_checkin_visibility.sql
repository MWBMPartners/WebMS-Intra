-- =============================================================================
-- Migration 198: show the anonymous check-in counts to somebody (#525)
-- =============================================================================
-- One new table, two settings, four addresses. No ALTER of any kind, which is
-- also the simplest way to stay clear of the MariaDB-only `IF NOT EXISTS` on
-- ADD COLUMN / ADD INDEX that MySQL 8 rejects outright with error 1064.
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- A visitor can check in to an event without signing in, by scanning a QR code
-- or pressing a button on a kiosk at the door. Every one of those presses has
-- been written to `tblAnonymousCheckins` since September 2025.
--
-- Nothing read it. Not one screen, not one report, not one download. An
-- organisation could run a kiosk at the door for a year and never see a single
-- number out of it. The handler that writes the rows even carries a note saying
-- so. That is issue #525.
--
-- -----------------------------------------------------------------------------
-- WHY WHO-MAY-SEE-IT IS A SETTING AND NOT A FIXED RULE
-- -----------------------------------------------------------------------------
-- Organisations differ. Some want the door figures kept to administrators;
-- others want whoever is running the event to see how many people came. So the
-- owner asked for a choice rather than a rule, seeded for the whole
-- installation and settable by each organisation.
--
-- THREE choices, and only three:
--
--   admins               Administrators only. The default, and the narrowest.
--   admins_coordinators  Administrators, plus the coordinators of that event.
--   page                 Whoever the page's own sign-in rule already lets in.
--
-- A fourth choice, "the event's coordinators only", was planned and dropped by
-- the owner on 18 September 2026. Administrators are now always included
-- whatever the setting says, which would have made that fourth choice behave
-- exactly like the second. A choice that changes nothing is worse than no
-- choice at all, because somebody will pick it believing it does something.
--
-- Anything else that ever ends up stored in this setting — a typo, an empty
-- value, a leftover from the four-choice draft — is read as `admins`, the
-- narrowest. The reading happens in ONE place, `AnonymousCheckins::
-- visibilityChoice()`, so it cannot be got right in one screen and wrong in
-- another.
--
-- The key is named `attend.anonCounts.visibleTo` rather than something shorter
-- so that it does NOT have to be renamed when issue #526 adds venue and event
-- levels to the same idea, with the most specific level winning. #526 replaces
-- one small function, `AnonymousCheckins::readVisibilityChoice()`, and every
-- screen follows.
--
-- -----------------------------------------------------------------------------
-- WHY THERE IS A TABLE OF STORED PER-DAY FIGURES
-- -----------------------------------------------------------------------------
-- One of the figures shown is "probably unique senders" — how many different
-- internet connections the check-ins came from. It is worked out from the
-- scrambled sender address stored on each row.
--
-- That address is personal information, so it is emptied out after 90 days by
-- default (the second setting below). Empty it and the figure silently
-- changes: with nothing to compare, every row becomes its own sender, so an
-- event's number would drift upwards the moment its detail aged out. Nobody
-- would notice, because the number would still look like a number.
--
-- So the figure is written down BEFORE the detail behind it goes, and read back
-- afterwards. `tblAnonymousCheckinDays` is where it is written down. It holds
-- counts and dates only — nothing about any person — which is why the written
-- list of where personal information lives records it as "not personal", with
-- the reason.
--
-- `rowsCounted` looks redundant and is not. It is how a later reader tells a row
-- whose detail was CLEARED from one that arrived afterwards. It counts every row
-- the clear-out emptied at that moment — exactly the same rows the stored figure
-- was worked out from, which is what makes the arithmetic work.
--
-- A draft of this got that wrong in a way that was easy to miss, so it is
-- written down here: it counted only the rows that had a scrambled address. A
-- row with a browser description and no scramble then dropped out of the count,
-- and afterwards looked exactly like a check-in that had arrived late — so a
-- perfectly ordinary day was marked "includes late arrivals". The total was
-- right; only the warning was wrong. Two database proofs caught it.
--
-- `hasLateArrivals` is the owner's decision of 18 September 2026. If a check-in
-- lands for a day whose figure has already been stored — a kiosk syncing late,
-- say — it cannot be folded in exactly, because the information used to spot a
-- repeat has gone. It is added on top, which may count one visitor twice, and
-- that day is then MARKED so every screen can say plainly that the figure
-- includes late arrivals and is less exact. The alternative considered and
-- rejected was to add them on top silently.
--
-- No `siteID` column, deliberately, for the same reason `tblAnonymousCheckins`
-- has none: the event is the only link to an organisation, and a second copy of
-- that link is a second thing that could disagree with the first. Every read
-- joins `tblEvents`.
--
-- -----------------------------------------------------------------------------
-- WHY 0 DAYS MEANS KEEP FOR EVER
-- -----------------------------------------------------------------------------
-- `attend.detailRetentionDays` = 0 switches the clear-out off for an
-- organisation entirely. It is a deliberate escape hatch for somewhere that has
-- a reason to keep the detail, not an oversight. A negative number is treated
-- the same as 0: it is a typing mistake, and for a clear-out, doing nothing is
-- the safe direction.
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAYING THIS DOES
-- -----------------------------------------------------------------------------
-- Nothing. The installer runs full_schema.sql and then replays every numbered
-- migration regardless of which have already run, so:
--   - the table uses CREATE TABLE IF NOT EXISTS (standard MySQL, not the
--     MariaDB-only kind);
--   - the settings write `defaultValue`, never `settingValue` — writing
--     `settingValue` would silently reset a choice an administrator had since
--     made, every single time the installer replayed. That is the settings-table
--     rule migration 187 established and 197 restates;
--   - the addresses update only `targetFile`.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 📅  A. The stored per-day figures
-- #############################################################################

CREATE TABLE IF NOT EXISTS `tblAnonymousCheckinDays` (
    `dayID`           INT         NOT NULL AUTO_INCREMENT,
    `eventID`         INT         NOT NULL COMMENT 'FK → tblEvents.eventID — the only link to an organisation',
    `checkinDay`      DATE        NOT NULL COMMENT 'The calendar day these check-ins happened on',
    `uniqueSenders`   INT         NOT NULL DEFAULT 0
                      COMMENT 'The senders figure for that day, worked out while the scrambled addresses still existed: how many different internet connections, plus one for each check-in that had no scrambled address at all. Cannot be worked out again afterwards, which is the whole reason this row exists.',
    `rowsCounted`     INT         NOT NULL DEFAULT 0
                      COMMENT 'How many check-in rows had their detail cleared at that moment. Lets a later reader tell a row whose detail was cleared from one that arrived afterwards: rows of that day beyond this number must be later arrivals.',
    `hasLateArrivals` TINYINT(1)  NOT NULL DEFAULT 0
                      COMMENT '1 when check-ins arrived for this day AFTER its figure was stored, so the figure may count one visitor twice and every screen must say so.',
    `storedAt`        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`dayID`),
    UNIQUE KEY `uq_anon_day` (`eventID`, `checkinDay`),
    CONSTRAINT `fk_anon_day_event` FOREIGN KEY (`eventID`)
        REFERENCES `tblEvents`(`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='One stored figure per event per day, written just before the detail behind it is cleared (#525)';


-- #############################################################################
-- ⚙️  B. The two settings
-- #############################################################################
-- Portal-wide by default (siteID NULL). An organisation gets its own row from
-- /admin/settings/attendance, which writes a real (non-NULL) siteID — the
-- ordinary /settings editor cannot do it, because its duplicate check matches
-- the portal-wide row and refuses.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'attend.anonCounts.visibleTo', 'admins', 'admins', 0),
    (NULL, 'attend.detailRetentionDays',  '90',     '90',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🔗  C. The four addresses
-- #############################################################################
-- None of them ends in `.php`. The portal's own .htaccess answers "page not
-- found" for any address that does, so a link written that way would simply
-- not work — and it would also tell a stranger what the site is built with.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('attendance/export/anonymous',             'attendance/export-anonymous.php',         1),
    ('calendar/event/attendance/anonymous/add', 'calendar/event-attendance-anon-add.php',  1),
    ('admin/settings/attendance',               'admin/settings/attendance/index.php',     1),
    ('admin/settings/attendance/save',          'admin/settings/attendance/save.php',      1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋  D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('198_anonymous_checkin_visibility.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
