-- =============================================================================
-- Migration 174: Workflow Execution Engine + Generic Approvals Inbox (#443)
--
-- Path: web/_sql/174_workflow_engine.sql
-- Migration 034 shipped four workflow tables (tblWorkflows, tblWorkflowSteps,
-- tblWorkflowInstances, tblWorkflowActions) and an admin definition CRUD, but
-- no code anywhere started, advanced, completed, or timed out an instance.
-- This migration adds the four additive columns + one index + one enum
-- value those tables need to support the new `Portal\Core\Workflow` engine,
-- plus the seeds for the reference consumer (announcement publish approval)
-- and the new /approvals inbox app.
--
-- ALL FOUR EXISTING TABLES are reused as-is (additive columns only, no
-- redesign, no data migration needed on any real row since every column
-- added here is nullable/defaulted).
--
-- Every ALTER goes through the information_schema + PREPARE/EXECUTE guard
-- idiom exactly as web/_sql/112_events_calendar_easy_wins.sql / 138 / the
-- ENUM-widen precedent in 162_asset_kiosk_pin.sql (MySQL 8.0 rejects
-- MariaDB's `IF [NOT] EXISTS` on ALTER with ERROR 1064). Everything replays
-- as a no-op.
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in this
-- file — check_schema_seed_parity.py strips from any "--" to end-of-line,
-- even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/443
-- =============================================================================


-- #############################################################################
-- 🗄️ A. tblWorkflowInstances — four additive columns
-- #############################################################################

-- ➕ subjectLabel — inbox display snapshot; without it the inbox would need
-- per-tableName joins into arbitrary consumer tables.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWorkflowInstances'
      AND COLUMN_NAME  = 'subjectLabel'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblWorkflowInstances` ADD COLUMN `subjectLabel` VARCHAR(255) DEFAULT NULL COMMENT ''Display snapshot of the subject for the inbox (#443)'' AFTER `recordID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ contextJson — start() context (subject URL etc). TEXT, not JSON type
-- (matches house usage, e.g. tblNewsletterSegment.ruleJson).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWorkflowInstances'
      AND COLUMN_NAME  = 'contextJson'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblWorkflowInstances` ADD COLUMN `contextJson` TEXT DEFAULT NULL COMMENT ''JSON context recorded at start(), e.g. {url: ...} (#443)'' AFTER `subjectLabel`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ currentStepStartedAt — the timeout sweep needs "when did the current
-- step become active"; deriving it from MAX(actedAt) is wrong once
-- comments exist.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWorkflowInstances'
      AND COLUMN_NAME  = 'currentStepStartedAt'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblWorkflowInstances` ADD COLUMN `currentStepStartedAt` DATETIME DEFAULT NULL COMMENT ''When the current step became active — drives the timeout sweep (#443)'' AFTER `currentStep`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔁 Backfill — plain DML, replays as a 0-row no-op once every row has a value.
UPDATE `tblWorkflowInstances` SET `currentStepStartedAt` = `startedAt` WHERE `currentStepStartedAt` IS NULL;

-- ➕ outcome — the 034 status enum has no 'rejected'; widening a STATUS enum
-- in place is riskier than recording the terminal disposition orthogonally,
-- and completed-vs-outcome cleanly distinguishes "completed approved" from
-- "completed rejected" in history queries.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWorkflowInstances'
      AND COLUMN_NAME  = 'outcome'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblWorkflowInstances` ADD COLUMN `outcome` ENUM(''approved'',''rejected'',''cancelled'') DEFAULT NULL COMMENT ''Terminal disposition, set alongside status=completed|cancelled (#443)'' AFTER `status`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔍 B. tblWorkflowInstances — guarded composite index
-- #############################################################################

-- The inbox and the timeout sweep both filter `siteID = ? AND status IN
-- (...)`; the existing idx_wfi_status is status-only.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWorkflowInstances'
      AND INDEX_NAME   = 'idx_wfi_site_status'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblWorkflowInstances` ADD INDEX `idx_wfi_site_status` (`siteID`, `status`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🧾 C. tblWorkflowActions — add 'commented' to the action ENUM (guarded)
-- #############################################################################

-- Pure superset — existing values untouched. This is the only enum change
-- the engine needs: timeout auto-actions reuse 'approved'/'rejected' with
-- actedByID NULL, escalation uses the existing 'escalated', notification/
-- auto-step bookkeeping uses the existing 'skipped'. Only a human's
-- comment-only decision needs the new value.
SET @enum_has_commented := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblWorkflowActions'
      AND COLUMN_NAME  = 'action'
      AND COLUMN_TYPE LIKE '%''commented''%'
);
SET @sql := IF(@enum_has_commented = 0,
    'ALTER TABLE `tblWorkflowActions` MODIFY COLUMN `action` ENUM(''approved'',''rejected'',''escalated'',''skipped'',''commented'') NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 👤 D. Role seed — announcement_approver
-- #############################################################################

-- 🛡️ Seed the announcement_approver role if not already present (matches
-- the prayer_team precedent, migration 148). This is the assignee for the
-- seeded announcement_publish workflow's single step below.
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'announcement_approver', 'Announcement Approver'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'announcement_approver');


-- #############################################################################
-- 🔄 E. Reference-consumer definition + step — announcement_publish
-- #############################################################################

-- Definition header for siteID=1. uq_workflow_key_site makes this idempotent
-- via ON DUPLICATE KEY UPDATE (034 precedent for expense_approval).
INSERT INTO `tblWorkflows` (`siteID`, `workflowName`, `workflowKey`, `description`)
VALUES (1, 'Announcement Publish Approval', 'announcement_publish', 'Approve an announcement before it publishes to the whole site')
ON DUPLICATE KEY UPDATE `workflowName` = VALUES(`workflowName`);

-- tblWorkflowSteps has no unique key, so the seed uses the
-- INSERT...SELECT...WHERE NOT EXISTS idiom instead (idempotent by
-- construction — a second run finds stepOrder=1 already present and
-- inserts nothing). autoAction=NULL ⇒ the timeout escalates rather than
-- auto-deciding (#443 decision 2 — never auto-act unless a step opts in).
-- Other sites: admins clone this definition at /admin/workflows —
-- definitions are per-site by design, and the announcements.enabled flag
-- is also per-site (§ save.php's fail-open covers a site enabling the flag
-- before creating its own definition).
INSERT INTO `tblWorkflowSteps` (`workflowID`, `stepOrder`, `stepName`, `stepType`, `assigneeType`, `assigneeValue`, `autoAction`, `timeoutHours`)
    SELECT w.workflowID, 1, 'Approve publication', 'approval', 'role', 'announcement_approver', NULL, 72
    FROM `tblWorkflows` w
    WHERE NOT EXISTS (
        SELECT 1 FROM `tblWorkflowSteps` s
        WHERE s.workflowID = w.workflowID AND s.stepOrder = 1
    )
    AND w.workflowKey = 'announcement_publish' AND w.siteID = 1;


-- #############################################################################
-- ⚙️ F. Settings seed (8 keys)
-- #############################################################################

-- workflows.cron_token seeds empty + isSensitive=1 — cron/workflow-
-- timeouts.php is inert until an admin sets a real token, same fails-closed
-- pattern as every other cron endpoint. workflows.announcements.enabled
-- seeds 'false' (default OFF, per-site) — the manual publish path stays
-- byte-for-byte unchanged until a site opts in. approvals.enabled seeds
-- 'true' (nav-discoverable inbox; the consumer flag above stays the real
-- gate on whether anything ever flows into it).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'workflows.cron_token',             '',                   '',                   1),
    (NULL, 'workflows.enabled',                '1',                  '1',                  0),
    (NULL, 'workflows.notify_email',           '1',                  '1',                  0),
    (NULL, 'workflows.admin_override',         '1',                  '1',                  0),
    (NULL, 'workflows.announcements.enabled',  'false',              'false',              0),
    (NULL, 'approvals.enabled',                'true',               'true',               0),
    (NULL, 'approvals.displayName',            'Approvals',          'Approvals',          0),
    (NULL, 'approvals.displayIcon',            'fa-solid fa-stamp',  'fa-solid fa-stamp',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️ G. Routes seed (4)
-- #############################################################################

-- approvals/* isProtected=1 (session auth required); admin/workflows/step-
-- delete isProtected=1 (admin-gated inside the handler itself); cron/
-- workflow-timeouts isProtected=0 — public but token-gated, matching the
-- 171:104-106 precedent. No api/* routes anywhere in this feature.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('approvals',                     'approvals/index.php',              1),
    ('approvals/act',                 'approvals/act.php',                1),
    ('admin/workflows/step-delete',   'admin/workflows/step-delete.php',  1),
    ('cron/workflow-timeouts',        'cron/workflow-timeouts.php',       0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 H. Self-record
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('174_workflow_engine.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
