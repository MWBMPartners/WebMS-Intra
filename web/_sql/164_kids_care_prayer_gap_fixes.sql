-- =============================================================================
-- Migration 164: Data-governance / safeguarding gap fixes (C1-C4)
-- =============================================================================
-- Four unrelated gap fixes bundled into one migration because only ONE of
-- them (C1) needs a schema/seed change:
--
--   C1 — Kids check-in/out terminal had NO role gate. Any logged-in member
--        could view every child's name/allergies/medical flags and process
--        check-outs. Fixed in PHP (_apps/kids/{checkin,checkin-do,checkout}.php
--        now gate on `App::isAdmin() === false && App::hasRole('kids_team')
--        === false`, mirroring care/index.php's gate exactly) — but the
--        `kids_team` role didn't exist in `tblRoles` yet, so there was
--        nothing for an admin to grant at /admin/users. THIS migration adds
--        the seed row. WHERE NOT EXISTS idiom (matches migrations 148
--        prayer_team / 159 asset_manager) since tblRoles has no meaningful
--        column for ON DUPLICATE KEY UPDATE to touch.
--
--   C2 — Parent self-service edit/deactivate for Kids profiles
--        (_apps/kids/profiles.php + profiles-save.php). Uses only EXISTING
--        columns (isActive, medicalNotes — both already on tblKidProfiles
--        since migration 136) — no schema change needed.
--
--   C3 — Pastoral Care records were missing from
--        Portal\Core\GdprEraser::catalogue() (tblCareCase/tblCareAccessLog
--        added; tblCareVisit deliberately left uncatalogued — see the
--        detailed WHY comment in GdprEraser.php itself). Pure PHP change,
--        no schema change needed.
--
--   C4 — Praise posts (kind='praise') were leaking into Prayer Requests
--        screens/API because those queries never filtered on `kind`. The
--        `kind` column + `idx_pr_kind_site_status` index already exist
--        (migration 075 / #260) — no schema change needed, this was a
--        WHERE-clause-only fix in _apps/prayer-requests/*.
--
-- Portable DDL (DEV_NOTES.md -> "Portable DDL convention (MySQL 8.0 ∩
-- MariaDB)"): the only DDL-adjacent statement below is the tblRoles seed,
-- which uses the same WHERE-NOT-EXISTS guard as every other role seed in
-- this codebase — a strict no-op replay on an up-to-date schema. Production
-- is MySQL 8.0 — no MariaDB-only `IF [NOT] EXISTS` on ALTER anywhere here
-- (not that this migration touches any existing table's structure at all).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/298
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/257
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/260
-- =============================================================================


-- #############################################################################
-- 🏷️ Role — Kids Team (C1). Grants access to the check-in/check-out
-- terminal + the staff-facing parts of Kids ministry once assigned at
-- /admin/users (the role-checkbox list there simply SELECTs * FROM
-- tblRoles, so seeding this row is sufficient for it to appear there —
-- there is no separate /admin/roles CRUD page in this codebase; care_team
-- (#257) and prayer_team (#311 / migration 148) both work the same way).
-- #############################################################################
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'kids_team', 'Kids Team'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'kids_team');


-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('164_kids_care_prayer_gap_fixes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
