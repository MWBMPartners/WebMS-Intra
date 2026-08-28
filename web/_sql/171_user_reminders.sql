-- =============================================================================
-- Migration 171: User reminders sweep — task/rota/milestone-digest (#439)
--
-- Path: web/_sql/171_user_reminders.sql
-- New cron endpoint `cron/user-reminders` sweeps three previously-designed-
-- but-never-consumed reminder fields: `tblTasks.reminderDate`/`reminderSent`
-- (migration 036), `tblRotaSlot.reminderSentAt` + `rota.reminder_days_before`
-- (migration 074), and the milestones daily digest promised by
-- `milestones.digest_recipients` (migration 076). This file adds ONLY the
-- generic dedupe log the milestone-digest family needs (tasks/rota already
-- carry their own guard columns from earlier migrations — see the plan's
-- §2 "don't send twice" pattern catalogue) plus settings/route seeds.
--
-- ZERO ALTERs: `CREATE TABLE IF NOT EXISTS` (idempotent by construction) +
-- `INSERT … ON DUPLICATE KEY UPDATE` seeds. Replays as a no-op.
--
-- tblUserReminderLog mirrors tblAssetReminderLog / tblVenueReminderLog
-- exactly in shape and philosophy: NO FKs by design (the log must survive
-- deletion of whatever it reminded about), `(refType, refID, dueDate)` is
-- the dedupe key. `refType` is VARCHAR (not ENUM) so a future family
-- (dbs-expiry etc, deferred — see the plan §10 Q5) can reuse this same
-- table with zero DDL. Today's only writer is `milestone-digest`
-- (refID = siteID); tasks/rota use their own existing sent-flag columns
-- instead (see cron/user-reminders.php's own header for why).
--
-- FK/INDEX PREFIX RESERVATION: `usrrem`/`fk_usrrem` verified collision-free
-- against the full `CONSTRAINT \`fk_` and `` `idx_ ``/`` `uq_ `` inventory in
-- full_schema.sql (0 grep hits before this migration).
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in this
-- file — check_schema_seed_parity.py strips from any "--" to end-of-line,
-- even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/439
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLE
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 🔔 tblUserReminderLog — generic single-shot reminder dedupe log for the
-- "remind a person about their own upcoming item" family that doesn't
-- already carry a sent-flag column on its own entity. `(refType, refID,
-- dueDate)` is the dedupe key, so a re-scheduled date legitimately
-- re-reminds while a same-day re-run no-ops. (#439)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserReminderLog` (
    `logID`          INT      NOT NULL AUTO_INCREMENT,
    `siteID`         INT      NOT NULL COMMENT 'Attribution only — no FK by design, see table header',
    `refType`        VARCHAR(30) NOT NULL COMMENT 'Today: milestone-digest. Reserved for future single-shot families (e.g. dbs-expiry) so they need zero DDL to join',
    `refID`          INT      NOT NULL COMMENT 'No FK by design (log must survive row deletion). For milestone-digest: the siteID',
    `dueDate`        DATE     NOT NULL COMMENT 'Digest date — part of the dedupe key so each day re-fires',
    `recipientCount` INT      NOT NULL DEFAULT 0,
    `sentAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`logID`),
    UNIQUE KEY `uq_usrrem_ref` (`refType`, `refID`, `dueDate`),
    KEY `idx_usrrem_site` (`siteID`, `sentAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='User reminders — generic single-shot dedupe log, no FKs by design (#439)';


-- #############################################################################
-- ⚙️ SETTINGS SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- `user_reminders.cron_token` seeds empty + isSensitive=1 — the endpoint is
-- inert until an admin sets a real token at /admin/settings (same
-- empty-fails-closed pattern as `assets.cron_token` / `venues.cron_token`).
-- A dedicated global token (not reused from `reminders.cron_token`) keeps
-- per-endpoint rotation independent — the shipped six-of-six convention.
-- `tasks.reminder_lookback_days` is the first-activation backlog guard —
-- installs may have years of `reminderSent = 0` rows; anything whose
-- reminderDate is older than the lookback simply never matches, so
-- turning the sweep on for the first time never floods every overdue
-- reminder in one run. `rota.reminder_days_before` and
-- `milestones.digest_recipients` already exist (migrations 074/076) and
-- are deliberately NOT re-seeded here.
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'user_reminders.cron_token',       '', '',  1),
    (NULL, 'user_reminders.enabled',          '1', '1', 0),
    (NULL, 'tasks.reminders_enabled',         '1', '1', 0),
    (NULL, 'tasks.reminder_lookback_days',    '7', '7', 0),
    (NULL, 'rota.reminders_enabled',          '1', '1', 0),
    (NULL, 'milestones.digest_enabled',       '1', '1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️ ROUTES SEED
-- #############################################################################

-- -----------------------------------------------------------------------------
-- `cron/user-reminders` seeds isProtected=0 — public but token-gated,
-- exactly like `cron/asset-reminders` / `cron/venue-reminders`.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('cron/user-reminders', 'cron/user-reminders.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 SELF-RECORD
-- #############################################################################

-- -----------------------------------------------------------------------------
-- Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('171_user_reminders.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
