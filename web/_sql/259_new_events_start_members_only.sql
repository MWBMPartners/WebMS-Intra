-- =============================================================================
-- Migration 259: a new event starts members-only (D-079, #493)
-- =============================================================================
-- One change: the STARTING value of `tblEvents.isPublic` becomes 0 (and its
-- comment says so). No stored row is changed.
--
-- -----------------------------------------------------------------------------
-- WHY (the owner, 7 November 2026 — D-079)
-- -----------------------------------------------------------------------------
-- An event's "Public" box decides whether somebody who is not signed in sees
-- it on the portal's own pages (the event's page, the calendar a signed-out
-- visitor sees, the calendar feed people subscribe to) and is one of the three
-- things the public website needs (D-057, D-078). It started TICKED, so every
-- event was public unless somebody remembered to untick it. Now a new event
-- starts members-only until somebody ticks it. The owner's words: events
-- that already exist keep what they have — which is why this migration
-- changes the column's starting value and NOTHING ELSE. An UPDATE here would
-- take public events off the portal's signed-out pages without anybody
-- choosing that.
--
-- WHAT ELSE CARRIES THE RULE (the column's default is only one of them):
--   - the Manage Events form starts a NEW event with the box unticked;
--   - the events API treats a missing `isPublic` as false;
--   - the CSV import treats an empty `isPublic` cell as members-only;
--   - the public submission form and the outside-calendar importer already
--     wrote 0, and approving a suggestion now leaves the stored value as it
--     is — 0 unless somebody ticked "Public" on it first (D-092: Approve used
--     to set 1);
--   - a date a pattern change adds to a series copies the value of the
--     existing date it is made from (it joins an event, it is not a new one).
--
-- Safe to run twice: MODIFY COLUMN restates the whole column — its type, its
-- NOT NULL, the new default and the new comment — so running it again changes
-- nothing, and it is the same on MySQL 8.0, 8.4, 9.x and MariaDB. MODIFY rather
-- than `ALTER COLUMN … SET DEFAULT` so that an upgraded database carries the
-- same comment as a fresh install (round 1 of the check: SET DEFAULT left the
-- old comment behind). Changing the default changes no stored value.
-- -----------------------------------------------------------------------------

ALTER TABLE `tblEvents` MODIFY COLUMN `isPublic` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Visible on public calendar. A new event starts members-only (D-079, migration 259); existing events kept what they had.';

INSERT INTO `tblMigrations` (`filename`) VALUES ('259_new_events_start_members_only.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
