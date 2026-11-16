-- =============================================================================
-- Migration 300: remember that a suggestion was cancelled while it waited (#1081; D-197)
-- =============================================================================
-- What this changes:
--
--   A. `tblEvents` gains `cancelledWhileWaiting`: 1 when a visitor's event
--      suggestion was cancelled while it was still waiting to be approved.
--   B. Every suggestion still waiting whose status is `cancelled` today is
--      marked, hidden ones included.
--
-- -----------------------------------------------------------------------------
-- WHY
-- -----------------------------------------------------------------------------
-- D-197 (the owner, 16 November 2026): approving a suggestion that was
-- cancelled earlier is allowed, but the moderator must see a warning and
-- confirm, because approving publishes it whatever its status.
--
-- Before #965 a waiting suggestion could be set to Cancelled; since #965 no
-- page can do that. But the evidence does not last on its own: an edit page may
-- still set such a suggestion back to Draft (#965 allows Draft), and bringing a
-- hidden one back makes it a draft and empties its reason (D-196). Nothing
-- records event status changes in the change history, so once the status has
-- moved the fact would be gone. This column holds it, and nothing clears it.
--
-- Step B can mark everything that will ever need marking: since #965 no new
-- waiting-and-cancelled row can come into being, so the rows that exist when
-- this runs are all there will be (apart from an older backup restored later,
-- which `HiddenSeriesDates::restore()` also marks when it brings one back).
--
-- -----------------------------------------------------------------------------
-- HOW IT IS WRITTEN
-- -----------------------------------------------------------------------------
-- `information_schema` + PREPARE, because MySQL 8 rejects MariaDB's
-- `ADD COLUMN IF NOT EXISTS` (see .claude/rules/database.md). SAFE TO RUN
-- TWICE: the column is added only when it is missing, and step B only ever sets
-- 0 to 1. Every identifier is backticked. The definition is word for word what
-- `full_schema.sql` says, in the same place (after `moderationNote`).
-- =============================================================================

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblEvents'
      AND `COLUMN_NAME`  = 'cancelledWhileWaiting'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblEvents` ADD COLUMN `cancelledWhileWaiting` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = this suggestion was cancelled while it was still waiting to be approved (before #965 refused that), so approving it needs a confirmation (D-197). Kept because the status and reason that showed it can later be lost (D-196 empties the reason). Set by migration 300 and HiddenSeriesDates::restore(); never cleared'' AFTER `moderationNote`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- B. Mark what is known today.
UPDATE `tblEvents`
   SET `cancelledWhileWaiting` = 1
 WHERE `submissionStatus` = 'pending'
   AND `status` = 'cancelled'
   AND `cancelledWhileWaiting` = 0;

INSERT INTO `tblMigrations` (`filename`) VALUES ('300_suggestion_cancelled_while_waiting.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
