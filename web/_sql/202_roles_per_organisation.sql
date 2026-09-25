-- =============================================================================
-- Migration 202: roles belong to one organisation each (#516)
-- =============================================================================
-- Before this migration, `tblRoles` was one portal-wide list (treasurer,
-- expense approver, care team, and so on) and NOTHING in the code could ever
-- put a row into `tblUserRoles` — the members page SHOWED a person's roles,
-- the help page DESCRIBED where they live, but no button, form or script
-- anywhere could give one. 61 files (66 call sites) in the code ask
-- "does this person hold role X" (`App::hasRole()`); every one of them only
-- ever answered yes for a global administrator, because a global
-- administrator is given every role automatically and nobody else could be
-- given anything at all.
--
-- The owner's decision (21 September 2026): EVERY organisation gets its own
-- copy of the roles list, starting from the same standard set this portal
-- already ships (treasurer, expense approver, care team, kids team, prayer
-- team, asset manager, venue manager, announcement approver, small groups
-- coordinator, stream moderator, staff, volunteer, visitor coordinator,
-- event coordinator — fourteen in all). An organisation may rename any of
-- them to suit its own language, and may add roles of its own. Holding a
-- role is per organisation too: treasurer of Organisation A is not
-- treasurer of Organisation B.
--
-- -----------------------------------------------------------------------------
-- THE KEY/LABEL SPLIT — WHY RENAMING A ROLE CANNOT BREAK THE 66 CHECKS
-- -----------------------------------------------------------------------------
-- Every role row has always carried two names: `roleKey` (a short, fixed,
-- lower-case word such as 'treasurer' — what the CODE asks for) and
-- `roleName` (the label a person sees, e.g. "Treasurer", "Finance Officer").
-- This migration does not change that split; it only makes the KEY unique
-- PER ORGANISATION instead of portal-wide. An organisation may change
-- `roleName` freely (Admin → Roles, 2026-09-21 build) — that only changes
-- what people see. `roleKey` never changes after a role is created, standard
-- or not, because it is what `App::hasRole('treasurer')` and every workflow
-- step, newsletter segment and report gate compares against. Renaming the
-- LABEL of organisation A's `treasurer` role to "Finance Officer" changes
-- nothing about whether `App::hasRole('treasurer')` still answers correctly
-- for that organisation — the key is untouched.
--
-- -----------------------------------------------------------------------------
-- WHY THE SEVEN OLDER ROWS KEEP THEIR NUMBERS — ORGANISATION 1'S ROLES
-- -----------------------------------------------------------------------------
-- Seven earlier migrations (143, 148, 159, 164, 170, 174, 183) and six seed
-- blocks in `full_schema.sql` each insert one role by `(roleKey, roleName)`
-- only, with no organisation in mind, because there was no such column when
-- they were written. Every one of those INSERTs is replayed on a fresh
-- install AFTER this column exists (the installer runs `full_schema.sql`
-- then every numbered migration in order), so the new `siteID` column
-- defaults every one of them to organisation 1 — the portal's own first
-- organisation (`full_schema.sql:1420`), never a customer's own address.
-- `admin/roles/save.php` and `Roles::seedStandardSet()` always set `siteID`
-- explicitly when creating a NEW role, so nothing in code ever relies on
-- that default for anything written after this migration runs; it exists
-- purely so the seven rows that already exist land somewhere sensible.
-- `roleID` values 1-7 therefore keep the exact meaning they always had —
-- organisation 1's copies of those seven roles — which matters because
-- `tblUserRoles` rows already pointing at those numbers are carried forward
-- below without needing to be renumbered.
--
-- -----------------------------------------------------------------------------
-- WHAT ELSE THIS MIGRATION DOES: HOLDINGS, THE PEN, AND WHY BOTH ARE NEEDED
-- -----------------------------------------------------------------------------
-- `tblUserRoles` (who holds what) gains its own `siteID`. Whichever
-- organisation a hand-edited holding is carried into is worked out the same
-- careful way #533 (migration 199) worked out which organisation a row-less
-- account belongs to — automatically ONLY where doing so is not a guess (a
-- single-organisation portal, or a portal-wide multi-organisation switch
-- that is on but only one organisation row actually exists), and NEVER
-- guessed otherwise. Rows nobody can safely place land in a new pen table,
-- `tblUserRolesUnplaced`, for a global administrator to place by hand at
-- `/admin/users/roles-unplaced` (the exact pattern `/admin/users/unplaced`
-- already proved for row-less accounts).
--
-- There should be no existing rows in `tblUserRoles` at all on a real
-- installation — this migration exists for the RARE case where somebody
-- hand-edited the database before this feature shipped. On every ordinary
-- installation this whole section (steps B2-B5) finds nothing to do.
--
-- -----------------------------------------------------------------------------
-- THE ORDERING TRAP, STATED PLAINLY (the 199 precedent)
-- -----------------------------------------------------------------------------
-- On a portal with SEVERAL organisations, a hand-inserted role holding loses
-- its organisation the moment this migration runs and is not usable again
-- until a global administrator places it from the pen page. This is expected
-- to affect nobody on a real installation (there is nothing to hand-edit
-- until this feature exists), but the window is not zero, and this paragraph
-- exists so nobody is surprised by it — exactly as migration 199's own
-- header says for the equivalent case with accounts.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION CANNOT DO
-- -----------------------------------------------------------------------------
-- It cannot guess which organisation a hand-inserted holding belongs to on a
-- portal with several organisations — that judgement is left to a human, the
-- same reasoning #533 already established. It cannot undo the standard-set
-- seed: an organisation that deletes an organisation-added role can always
-- get it back by re-adding it, but a STANDARD role can never be deleted (see
-- `admin/roles/save.php`), so there is nothing here to "undo" for those. It
-- does not touch `tblRoles.roleID` numbering for any EXISTING row — only new
-- rows for organisations 2 and up get new numbers.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/516
--
-- -----------------------------------------------------------------------------
-- CHANGED IN PLACE, 24 September 2026 (#552) — idx_roles_id_site
-- -----------------------------------------------------------------------------
-- Step A6 below used to add `idx_roles_id_site` as an ordinary (non-unique)
-- KEY. MySQL 8.4 refuses to create a foreign key unless it points at a
-- PRIMARY or UNIQUE key on exactly its own columns, and B8's
-- `fk_user_role_role_site` points at this very index. This codebase's house
-- convention folds every migration's DDL back into `full_schema.sql` too
-- (see DEV_NOTES.md → "`full_schema.sql` fold pattern"), so the
-- SAME bad foreign key also sat directly inside
-- `full_schema.sql` — it is a FRESH INSTALL on MySQL 8.4 that failed FIRST,
-- straight away, inside `full_schema.sql` itself, before this migration
-- ever ran at all. It is the UPGRADE path that fails inside THIS migration:
-- an upgrade never runs `full_schema.sql` — it replays the numbered
-- migrations in order against whatever a real database already has — so on
-- an 8.4 upgrade the failure happens here, at B8, and the Migrator stops:
-- nothing after it in the queue can ever run. This is changed IN PLACE, not
-- fixed by a later migration, because migrations 188-206 have never been
-- released (there is no earlier copy of this file for a real customer to
-- have already run) and because a later migration would be too late anyway
-- for the UPGRADE path: it stops at the first failing migration, so a fix
-- sitting in 207 or later would never be reached. A6b below now handles
-- every state a database on which THIS MIGRATION ACTUALLY RUNS can be in —
-- see that block's own comment for the detail, and for the one state it
-- cannot repair.
-- =============================================================================


-- #############################################################################
-- 🅰️  A. tblRoles — the three new columns, the per-organisation unique key,
--        and the fourteen-role standard set for every organisation
-- #############################################################################

-- ➕ A1 siteID — NOT NULL DEFAULT 1. The default exists ONLY so the seven
--    older seed blocks above (which never set siteID at all) land safely on
--    a fresh install; every write in THIS build sets siteID explicitly.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND COLUMN_NAME='siteID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblRoles` ADD COLUMN `siteID` INT NOT NULL DEFAULT 1 AFTER `roleID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A2 isStandard — marks the fourteen roles every organisation starts
--    with. A standard role can be renamed but never deleted (admin/roles/save.php).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND COLUMN_NAME='isStandard');
SET @sql := IF(@c=0, 'ALTER TABLE `tblRoles` ADD COLUMN `isStandard` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ➕ A3 description — one sentence a person sees under the role's label,
--    shown on the members page's Roles modal and at Admin → Roles.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND COLUMN_NAME='description');
SET @sql := IF(@c=0, 'ALTER TABLE `tblRoles` ADD COLUMN `description` VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔡 A4 A hand-added key such as 'Treasurer' becomes 'treasurer', so PHP-side
--    string comparisons (Roles::has() normalises the key it is asked about,
--    but the STORED key must already be lower-case for `IN (...)` lists,
--    settings values and workflow assignee values to match it) agree with
--    the database's own case-insensitive collation. Safe to run twice: the
--    second run finds nothing left with a different case than its own
--    lower-cased form, so `BINARY roleKey <> LOWER(roleKey)` matches
--    nothing. Cannot collide with an existing lower-case row: the OLD
--    portal-wide unique key on `roleKey` was already case-insensitive
--    (utf8mb4_general_ci), so 'Treasurer' and 'treasurer' never coexisted
--    as two separate rows before this migration ran.
UPDATE `tblRoles` SET `roleKey` = LOWER(`roleKey`) WHERE BINARY `roleKey` <> LOWER(`roleKey`);

-- 🛟 A5 The missing-organisation-1 belt: if organisation 1 has somehow been
--    removed by hand, point every existing `tblRoles` row at the LOWEST
--    organisation number that still exists, so the foreign key added at A8
--    cannot fail. WHAT THIS CANNOT DO: an entirely empty `tblSites` table
--    (impossible on a working installation — there is always at least the
--    portal's own first organisation) would leave nothing for the subquery
--    to return, and A8 below would then fail loudly rather than silently —
--    which is the right failure, because there would be nowhere safe to
--    put these rows at all.
UPDATE `tblRoles` r
SET r.`siteID` = (SELECT MIN(s.`siteID`) FROM `tblSites` s)
WHERE NOT EXISTS (SELECT 1 FROM `tblSites` s2 WHERE s2.`siteID` = r.`siteID`);

-- 🔑 A6 The new per-organisation unique key, replacing the old portal-wide
--    one, plus the index the composite foreign key on tblUserRoles (step B8)
--    needs on THIS side of the relationship — InnoDB requires the
--    REFERENCED columns of a composite foreign key to lead an index, and
--    `roleID` alone (the primary key) does not cover `siteID` too.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND INDEX_NAME='uq_roles_site_key');
SET @sql := IF(@c=0, 'ALTER TABLE `tblRoles` ADD UNIQUE KEY `uq_roles_site_key` (`siteID`,`roleKey`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND INDEX_NAME='roleKey');
SET @sql := IF(@c>0, 'ALTER TABLE `tblRoles` DROP INDEX `roleKey`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔒 A6b idx_roles_id_site must be UNIQUE, not merely present, since
--    24 September 2026 (#552). MySQL 8.4 refuses to create a foreign key
--    unless it points at a PRIMARY or UNIQUE key covering exactly the
--    columns it names, and B8 below adds fk_user_role_role_site pointing
--    at exactly this index (roleID, siteID) — on 8.4 that fails with
--    ERROR 6125 unless the index is unique. An EARLIER COPY of this
--    migration existed on this unreleased branch before #552 was found —
--    deploys come only from the three release branches, `main`, `beta` and
--    `alpha` (a manual run of `deploy.yml` could target another branch, but
--    every one of the 111 runs on record has used one of those three), so
--    no released or deployed database has ever run the earlier copy — a
--    database on which this migration ACTUALLY RUNS (this attempt or an
--    earlier one) can be in any of three states at the moment this block
--    executes, and it handles all three:
--      1. the index does not exist yet -> create it UNIQUE straight away.
--      2. it exists and is already unique -> nothing to do. This is what
--         a fresh install sees, because full_schema.sql now creates it
--         unique and every migration is replayed on top of that. (This
--         branch trusts the NAME `idx_roles_id_site` alone — if an index
--         of that name were ever made unique by hand on the WRONG columns,
--         this guard would still do nothing, and B8 below would then fail
--         loudly with ERROR 6125 rather than silently accepting it.)
--      3. it exists but is an ordinary (non-unique) key -> only possible
--         from an EARLIER, pre-#552 copy of this migration. THIS (fixed)
--         copy's own A6b, right below this comment, never creates the
--         index in any shape but unique — so it cannot be THIS copy that
--         left it non-unique. The earlier copy could have run to COMPLETION on
--         MySQL 8.0 or on MariaDB (neither ever enforced the new MySQL 8.4
--         rule, so nothing there stopped it finishing with a plain index).
--         Or it could have reached this same point and then failed later,
--         at its own B8, with this fixed file only now running afterwards.
--         A hand re-run of that earlier copy lands in the exact same
--         state — it is not a third, separate cause. Whichever of these it
--         is, replace the index with a UNIQUE key of the same name in ONE
--         statement, so the table is never left with no index at all for
--         that instant. This cannot fail on data already sitting in the
--         table: `roleID` is this table's own PRIMARY KEY, so no two rows
--         can ever share one, and adding `siteID` after it changes nothing
--         about which pairs already exist.
--    WHAT THIS CANNOT DO: repair a database on which an earlier, pre-#552
--    copy of this migration already ran to COMPLETION with the old,
--    non-unique index — whether that copy ran on MySQL 8.0 or MariaDB
--    (neither ever enforced the new rule) or was re-run by hand; either way
--    it is the same end state. The Migrator never re-runs a migration it
--    has already recorded as done, so this block would never execute again
--    on such a database, and the index would stay non-unique. That state can
--    only arise on an unreleased development or test database — see
--    DEV_NOTES.md, #552; the repair is to run this fixed migration by
--    hand (it is safe to re-run), or reinstall.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND INDEX_NAME='idx_roles_id_site' AND SEQ_IN_INDEX=1);
SET @nonUnique := (SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND INDEX_NAME='idx_roles_id_site' AND SEQ_IN_INDEX=1);
SET @sql := IF(@c=0,
    'ALTER TABLE `tblRoles` ADD UNIQUE KEY `idx_roles_id_site` (`roleID`,`siteID`)',
    IF(@nonUnique=0,
        'SELECT 1',
        'ALTER TABLE `tblRoles` DROP INDEX `idx_roles_id_site`, ADD UNIQUE KEY `idx_roles_id_site` (`roleID`,`siteID`)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 A7/A8 Every role belongs to a real organisation; deleting an
--    organisation deletes its roles (and, by the cascade added to
--    tblUserRoles below, everybody's holdings of them) along with it.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblRoles' AND CONSTRAINT_NAME='fk_roles_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblRoles` ADD CONSTRAINT `fk_roles_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🌱 A9 The standard set — for EVERY organisation that exists, active or
--    not (a switched-off organisation may be switched back on later, and
--    #515 already refuses to switch one back on while it holds a clashing
--    address key, so there is no harm in it holding a role list too).
--    Labels use Title Case throughout, matching the seven labels that
--    already exist on organisation 1 (Prayer Team, Kids Team, Asset
--    Manager, Venue Manager, Announcement Approver, Small Groups
--    Coordinator, Stream Moderator) — sentence-case labels here would give
--    organisation 1's older rows one style and every other organisation a
--    different one for the exact same role, for no reason. Keys and
--    descriptions are the same text as `Roles::STANDARD` in
--    `web/_core/Roles.php` — `tools/audit-checks/check_role_keys.py`
--    compares the two lists and fails if they ever drift apart.
INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`)
SELECT s.`siteID`, k.`roleKey`, k.`roleName`, k.`description`, 1
FROM `tblSites` s
CROSS JOIN (
          SELECT 'treasurer' AS roleKey, 'Treasurer' AS roleName, 'Records giving, sees every expense claim and pays approved ones' AS description
UNION ALL SELECT 'approver', 'Expense Approver', 'Approves or rejects expense claims'
UNION ALL SELECT 'care_team', 'Care Team', 'Opens the confidential pastoral care register'
UNION ALL SELECT 'kids_team', 'Kids Team', 'Runs children''s check-in and check-out'
UNION ALL SELECT 'prayer_team', 'Prayer Team', 'Moderates prayer requests and can be assigned them'
UNION ALL SELECT 'asset_manager', 'Asset Manager', 'Manages the asset register'
UNION ALL SELECT 'venue_manager', 'Venue Manager', 'Manages venue bookings'
UNION ALL SELECT 'announcement_approver', 'Announcement Approver', 'Approves announcements before they publish'
UNION ALL SELECT 'groups_coordinator', 'Small Groups Coordinator', 'Manages every small group'
UNION ALL SELECT 'stream_moderator', 'Stream Moderator', 'Moderates livestream chat'
UNION ALL SELECT 'staff', 'Staff', 'Sees photos shared with staff'
UNION ALL SELECT 'volunteer', 'Volunteer', 'Sees photos shared with volunteers'
UNION ALL SELECT 'visitor_coordinator', 'Visitor Coordinator', 'Can be assigned first-time visitors to follow up'
UNION ALL SELECT 'event_coordinator', 'Event Coordinator', 'An audience for newsletters, workflows, reminders and shared calendars. Coordinating a particular event is set on that event, not here.'
) k
WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` r WHERE r.`siteID` = s.`siteID` AND r.`roleKey` = k.`roleKey`);

-- ✏️ Mark the standard rows AND fill in a missing description (an older
--    seed inserted key+label only, with no description column to fill at
--    the time). Existing LABELS are never touched here — a customer may
--    already have renamed one, and this migration must not silently
--    overwrite that choice. A key an organisation added by hand that a
--    LATER release happens to add to the standard set would be adopted as
--    standard by that release's own copy of this UPDATE — intended, not a
--    bug: it means "this key is now part of the set every organisation
--    gets", which is exactly what should happen to a role that used to be
--    a one-off custom addition and has since become a built-in one.
--    COALESCE keeps whatever description is already there, so this is
--    safe to run twice.
UPDATE `tblRoles` r
JOIN (
          SELECT 'treasurer' AS roleKey, 'Records giving, sees every expense claim and pays approved ones' AS description
UNION ALL SELECT 'approver', 'Approves or rejects expense claims'
UNION ALL SELECT 'care_team', 'Opens the confidential pastoral care register'
UNION ALL SELECT 'kids_team', 'Runs children''s check-in and check-out'
UNION ALL SELECT 'prayer_team', 'Moderates prayer requests and can be assigned them'
UNION ALL SELECT 'asset_manager', 'Manages the asset register'
UNION ALL SELECT 'venue_manager', 'Manages venue bookings'
UNION ALL SELECT 'announcement_approver', 'Approves announcements before they publish'
UNION ALL SELECT 'groups_coordinator', 'Manages every small group'
UNION ALL SELECT 'stream_moderator', 'Moderates livestream chat'
UNION ALL SELECT 'staff', 'Sees photos shared with staff'
UNION ALL SELECT 'volunteer', 'Sees photos shared with volunteers'
UNION ALL SELECT 'visitor_coordinator', 'Can be assigned first-time visitors to follow up'
UNION ALL SELECT 'event_coordinator', 'An audience for newsletters, workflows, reminders and shared calendars. Coordinating a particular event is set on that event, not here.'
) k ON k.roleKey = r.`roleKey`
SET r.`isStandard` = 1, r.`description` = COALESCE(r.`description`, k.description)
WHERE r.`isStandard` = 0 OR r.`description` IS NULL;


-- #############################################################################
-- 🅱️  B. tblUserRoles — who holds what, per organisation; the pen for
--        anything that cannot be placed automatically
-- #############################################################################

-- ➕ B1 The three new columns. siteID starts NULLABLE on purpose — an
--    existing hand-inserted holding needs somewhere to sit while B2-B6
--    below work out where it belongs, before it is finally made NOT NULL.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND COLUMN_NAME='siteID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD COLUMN `siteID` INT NULL AFTER `roleID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND COLUMN_NAME='grantedAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD COLUMN `grantedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND COLUMN_NAME='grantedByID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD COLUMN `grantedByID` INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧹 B2 Duplicates removed FIRST, keeping the lowest id — so neither the
--    carry-over below nor the pen ever sees two copies of the same
--    holding. `tblUserRoles` had no uniqueness rule at all before this
--    migration, so a hand-edited database could genuinely hold two rows
--    for the same (userID, roleID). Safe to run twice: the second run
--    finds nothing left to delete.
DELETE d FROM `tblUserRoles` d JOIN `tblUserRoles` k ON k.`userID` = d.`userID` AND k.`roleID` = d.`roleID` AND k.`userRoleID` < d.`userRoleID`;

-- 🅰️ B3 Carry-over A — single-organisation portal (the portal-wide
--    `multisite.enabled` row is absent or not the text 'true', the exact
--    reading `AccountGuard::isSingleOrganisation()` and migration 199 both
--    already use): every existing holding moves to its role's own
--    organisation, PROVIDED a membership row for that (user, organisation)
--    pair actually exists. An ENDED membership still counts here (a
--    holding for somebody who has since been offboarded is carried
--    forward harmlessly — `Roles::has()` requires an ACTIVE membership
--    before it ever answers yes, so an offboarded person's carried holding
--    grants them nothing until they are re-added).
UPDATE `tblUserRoles` ur
JOIN `tblRoles` r ON r.`roleID` = ur.`roleID`
JOIN `tblUserSites` us ON us.`userID` = ur.`userID` AND us.`siteID` = r.`siteID`
SET ur.`siteID` = r.`siteID`
WHERE ur.`siteID` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `tblSettings` s WHERE s.`settingKey` = 'multisite.enabled' AND s.`siteID` IS NULL AND s.`settingValue` = 'true');

-- 🅱️ B4 Carry-over B — multi-organisation working is switched ON, but the
--    portal holds EXACTLY ONE organisation row (active or not) — still not
--    a guess, for the same reason migration 199 treats it that way: one
--    organisation IS the only one that exists.
UPDATE `tblUserRoles` ur
JOIN `tblRoles` r ON r.`roleID` = ur.`roleID`
JOIN `tblUserSites` us ON us.`userID` = ur.`userID` AND us.`siteID` = r.`siteID`
SET ur.`siteID` = r.`siteID`
WHERE ur.`siteID` IS NULL
  AND EXISTS (SELECT 1 FROM `tblSettings` s WHERE s.`settingKey` = 'multisite.enabled' AND s.`siteID` IS NULL AND s.`settingValue` = 'true')
  AND (SELECT COUNT(*) FROM `tblSites`) = 1;

-- 🖊️ B5 The pen. Everywhere else — genuinely more than one organisation to
--    choose from, or no membership row for the role's own organisation at
--    all — NOTHING IS GUESSED. Each such holding is copied into
--    `tblUserRolesUnplaced` for a global administrator to place by hand at
--    `/admin/users/roles-unplaced`, then removed from `tblUserRoles`
--    (which is about to become NOT NULL on siteID at B6, so nothing
--    without an organisation can be left in it). `INSERT IGNORE` (backed
--    by the `uq_unplaced_original` unique key) rather than an `AND NOT
--    EXISTS` guard is deliberate and PROVEN safe to re-run: an `AND NOT
--    EXISTS` version of this exact statement tripped
--    `check_migration_idempotency.py` during this feature's own planning,
--    because the checker cannot see that the row it would re-insert is
--    identical to the one already there — `INSERT IGNORE` sidesteps that
--    without needing to.
CREATE TABLE IF NOT EXISTS `tblUserRolesUnplaced` (
    `unplacedID`         INT NOT NULL AUTO_INCREMENT,
    `originalUserRoleID` INT NOT NULL,
    `userID`             INT NOT NULL,
    `roleKey`            VARCHAR(50)  COLLATE utf8mb4_general_ci NOT NULL,
    `roleName`           VARCHAR(100) COLLATE utf8mb4_general_ci NOT NULL,
    `createdAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`unplacedID`),
    UNIQUE KEY `uq_unplaced_original` (`originalUserRoleID`),
    KEY `idx_unplaced_user` (`userID`),
    CONSTRAINT `fk_unplaced_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO `tblUserRolesUnplaced` (`originalUserRoleID`, `userID`, `roleKey`, `roleName`)
SELECT ur.`userRoleID`, ur.`userID`, r.`roleKey`, r.`roleName`
FROM `tblUserRoles` ur JOIN `tblRoles` r ON r.`roleID` = ur.`roleID`
WHERE ur.`siteID` IS NULL;
DELETE FROM `tblUserRoles` WHERE `siteID` IS NULL;

-- 🔒 B6 NULL never means anything from here on — every row has a real
--    organisation.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND COLUMN_NAME='siteID' AND IS_NULLABLE='YES');
SET @sql := IF(@c>0, 'ALTER TABLE `tblUserRoles` MODIFY COLUMN `siteID` INT NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔑 B7 Every index declared explicitly, so no foreign key below has to
--    invent one of its own and this file and `full_schema.sql` agree
--    object for object (the standing "every database change goes in two
--    places" rule).
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND INDEX_NAME='uq_user_role');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD UNIQUE KEY `uq_user_role` (`userID`,`roleID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND INDEX_NAME='idx_user_role_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD KEY `idx_user_role_site` (`userID`,`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND INDEX_NAME='idx_user_role_role_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD KEY `idx_user_role_role_site` (`roleID`,`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND INDEX_NAME='idx_user_role_org');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD KEY `idx_user_role_org` (`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND INDEX_NAME='idx_user_role_granter');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD KEY `idx_user_role_granter` (`grantedByID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔗 B8 The composite foreign keys that make an invalid holding impossible
--    at the DATABASE level, not just in application code: a holding for
--    somebody who is not a member of that organisation, or naming another
--    organisation's role, is refused outright. Deleting a membership row
--    cascades to remove only THAT organisation's holdings for the person
--    (see offboarding/do.php and admin/sites/users.php's own comments).
--    The two ORIGINAL single-column constraints on (userID) and (roleID)
--    from when this table was first created are left in place —
--    redundant now that the composite ones exist, but harmless, and
--    removing them buys nothing worth the extra guarded DDL.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND CONSTRAINT_NAME='fk_user_role_membership' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD CONSTRAINT `fk_user_role_membership` FOREIGN KEY (`userID`,`siteID`) REFERENCES `tblUserSites`(`userID`,`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND CONSTRAINT_NAME='fk_user_role_role_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD CONSTRAINT `fk_user_role_role_site` FOREIGN KEY (`roleID`,`siteID`) REFERENCES `tblRoles`(`roleID`,`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND CONSTRAINT_NAME='fk_user_role_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD CONSTRAINT `fk_user_role_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserRoles' AND CONSTRAINT_NAME='fk_user_role_granter' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserRoles` ADD CONSTRAINT `fk_user_role_granter` FOREIGN KEY (`grantedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔗  C. The five new addresses, and the self-record
-- #############################################################################
-- The web root has no `admin` folder (`web/public_html/` holds only the
-- front controller, static assets and the three real entry-point PHP
-- files), so none of these five addresses can ever be shadowed by a real
-- file or folder — see `.claude/CLAUDE.md`'s "Web-root shadowing trap".

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/roles',                     'admin/roles/index.php',                1),
    ('admin/roles/save',                'admin/roles/save.php',                 1),
    ('admin/users/roles/save',          'admin/users/roles-save.php',           1),
    ('admin/users/roles-unplaced',      'admin/users/roles-unplaced.php',       1),
    ('admin/users/roles-unplaced/save', 'admin/users/roles-unplaced-save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- #############################################################################
-- 📋  D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to
--        be safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('202_roles_per_organisation.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
