-- =============================================================================
-- Migration 173: Service-Plans ↔ Worship additive bridge (gap #6, #442)
--
-- Path: web/_sql/173_worship_runsheet_link.sql
-- Two independent "service plan" data models exist and neither knows the
-- other: the run-sheet builder (migration 089, #262/#300 — tblServicePlan
-- SINGULAR + tblServicePlanItem) and the worship presentation engine
-- (migration 137, #308/#355 — tblServicePlans PLURAL + tblServicePlanItems).
-- Migration 154's header calls the two "unrelated"; this migration adds ONE
-- optional, additive, nullable cross-reference column so a worship plan can
-- OPTIONALLY declare which run-sheet it presents — nothing is merged, no
-- data migration, no destructive change to either model, no field sync.
--
-- New column (on the WORSHIP side, tblServicePlans — plural):
--   `runSheetPlanID` INT NULL — optional 1:1 link to the run-sheet
--   (tblServicePlan.planID) this plan presents. NULL = unpaired (the state
--   of every existing row today — zero data migration).
--   UNIQUE KEY uq_plans_runsheet — enforces at most one worship plan per
--   run-sheet (MySQL allows unlimited NULLs in a unique index).
--   FK fk_plans_runsheet ... ON DELETE SET NULL — deleting a run-sheet
--   silently unpairs; nothing cascades into the worship model.
--
-- Why the worship side and not the reverse: tblServicePlan (the run-sheet,
-- migration 089) is created far EARLIER in full_schema.sql than
-- tblServicePlans (migration 137), so the FK folds inline into the child's
-- CREATE per the file's stated "FK ordering respected" guarantee — the
-- reverse direction would need an out-of-order ALTER with zero precedent in
-- this codebase (every later column in full_schema.sql is folded inline,
-- never a standalone ALTER). Pairing therefore only ever mutates a
-- tblServicePlans row, so that app's existing stricter write-ACL
-- (admin-or-coordinator, driven by tblServicePlans.eventID) governs
-- pair/unpair for free — the ACL-free run-sheet model is never written by
-- the bridge except one guarded, NULL-only, one-directional convenience
-- (see Portal\Core\ServicePlanLink::pair() — the run-sheet's dormant
-- eventID column, write-dead since migration 089, is backfilled from the
-- worship plan's eventID ONLY when the run-sheet's eventID is currently
-- NULL; never the reverse, since tblServicePlans.eventID is ACL-bearing).
--
-- ALL DDL below goes through the information_schema + PREPARE/EXECUTE guard
-- idiom (MySQL 8 rejects MariaDB's `IF [NOT] EXISTS` on ALTER with ERROR
-- 1064 — see DEV_NOTES.md "Portable DDL convention"; house precedents:
-- migrations 110, 138, 151). Zero data migration, zero backfill sweep —
-- ONLY the schema objects themselves. Replays as a no-op.
--
-- New route: worship/plan/link -> worship/plan-link.php (protected) — the
-- CSRF'd pair/unpair POST handler (Portal\Core\ServicePlanLink is the
-- mechanism; the handler owns the write-ACL policy). No api/* surface, no
-- new settings keys (gating rides on the existing worship.enabled /
-- service_plans.enabled via AppRegistry::isEnabled()).
--
-- @link       https://github.com/MWBMPartners/WebMS-Intra/issues/442
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================

-- ➕ tblServicePlans.runSheetPlanID — guarded ADD COLUMN
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblServicePlans'
      AND COLUMN_NAME  = 'runSheetPlanID'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblServicePlans` ADD COLUMN `runSheetPlanID` INT DEFAULT NULL COMMENT ''Optional 1:1 link to the programme run-sheet this plan presents — tblServicePlan.planID (gap #6 bridge, migration 173)'' AFTER `eventID`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 uq_plans_runsheet — guarded ADD UNIQUE KEY (at most one worship plan
-- per run-sheet; NULLs exempt, so every unpaired row today is unaffected).
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblServicePlans'
      AND INDEX_NAME   = 'uq_plans_runsheet'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblServicePlans` ADD UNIQUE KEY `uq_plans_runsheet` (`runSheetPlanID`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 fk_plans_runsheet — guarded ADD CONSTRAINT (ON DELETE SET NULL — a
-- deleted run-sheet silently unpairs; nothing cascades into either model).
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblServicePlans'
      AND CONSTRAINT_NAME   = 'fk_plans_runsheet'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblServicePlans` ADD CONSTRAINT `fk_plans_runsheet` FOREIGN KEY (`runSheetPlanID`) REFERENCES `tblServicePlan`(`planID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🗺️ Route seed — the CSRF'd pair/unpair POST handler. Not api/*, so this
-- IS how it's gated (ApiRouter never consults tblRoutes for api/* paths;
-- this is an ordinary protected page route).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('worship/plan/link', 'worship/plan-link.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
INSERT INTO `tblMigrations` (`filename`) VALUES ('173_worship_runsheet_link.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
