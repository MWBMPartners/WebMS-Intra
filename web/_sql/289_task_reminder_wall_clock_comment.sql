-- =============================================================================
-- Migration 289: a task's reminder time says it is a WALL-CLOCK reading (#1041)
-- =============================================================================
-- What this changes:
--
--   A. The COMMENT on `tblTasks`.`reminderDate`, and nothing else. The
--      column's type, its empty default and the key it is part of
--      (`idx_task_reminder`) stay exactly as they are; no row is touched.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- The old comment said only "When to send reminder", which does not say WHOSE
-- clock. The time is typed by a person on a `datetime-local` box, so it is a
-- reading on the ORGANISATION's clock (`Site::timezone()`), not a UTC moment.
-- The scheduled user reminders job compared it with the database server's
-- NOW() -- a different clock -- so an organisation far from the database's
-- clock had its task reminders sent hours early or late (#1041). The job now
-- compares it, as text, with the organisation's own reading of "now".
--
-- Saying WALL-CLOCK in the comment is also what puts the column under
-- `tools/audit-checks/check_clock_time_not_a_moment.py`, which fails when a
-- page turns such a reading into a moment (#777), and its pinned list.
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `MODIFY COLUMN` with the column's whole definition restated word for word
-- as `full_schema.sql` holds it, apart from the comment. SAFE TO RUN TWICE: a
-- second run sets the same definition again and changes nothing. No
-- `IF EXISTS` on the column (MySQL 8 rejects MariaDB's form; see
-- .claude/rules/database.md). The table exists on every database this can run
-- on: migration 036 gave it the column, and the install script holds both.
-- =============================================================================

ALTER TABLE `tblTasks`
    MODIFY COLUMN `reminderDate` DATETIME DEFAULT NULL COMMENT 'When to send the reminder: a WALL-CLOCK reading on the organisation''s own clock (Site::timezone()), as typed on the form -- NOT a UTC moment. Compared as text with I18n::wallClockNow() (#1041)';

INSERT INTO `tblMigrations` (`filename`) VALUES ('289_task_reminder_wall_clock_comment.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
