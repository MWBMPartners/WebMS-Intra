-- =============================================================================
-- Migration 203: user groups and departments belong to one organisation each (#517)
-- =============================================================================
-- Two kinds of grouping have existed in the database for a long time, and
-- until this build nobody could use either of them:
--
--   * USER GROUPS (`tblGroups`, members in `tblUserGroups`) — committees and
--     working groups. A workflow approval step can name a group, and an asset
--     can be owned by a group.
--   * DEPARTMENTS (`tblDepts`, members in `tblUserDepts`) — what an expense
--     claim is charged to. A member of a department can carry five flags:
--     lead, assistant, secretary, approver and required approver. Expense
--     approval already depends on the lead, approver and required-approver
--     flags.
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- 1. Nothing could create a group or a department, or add a person to one.
--    No page, no form, no script, and no seed ever wrote a row into any of
--    the four tables. So department-based expense approval, workflow
--    approval by group and asset ownership by group only ever worked if
--    somebody edited the database by hand. The installer seeds no department
--    and no group (checked: nothing in `web/_sql/` inserts into either).
-- 2. `tblGroups` had no organisation column at all. A group made in one
--    organisation would have been visible, and usable as an approver or an
--    asset owner, in EVERY organisation on the portal. Measured before this
--    build on a copy with two organisations: a workflow step in
--    organisation B naming a hand-made group was offered to a person who is
--    not even a member of organisation B, and a member of B could approve it
--    through a group that belonged to no organisation at all.
-- 3. Neither membership table said which organisation a membership belongs
--    to, and neither had any rule against the same person being added twice.
--
-- The owner's decision (21 September 2026): both kinds belong to ONE
-- organisation each, are managed by that organisation's administrators, and
-- stay two different things. Groups are committees and working groups;
-- departments carry the flags expense approval depends on.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
--   A. `tblGroups` gains `siteID` (the organisation) and `isActive` (an
--      on/off switch, so a group can be retired without losing its history).
--   B. `tblUserGroups` gains `siteID`, `addedAt` and `addedByID`, a rule
--      against duplicates, and two "composite" foreign keys — rules the
--      database itself enforces, each tying TWO columns together at once:
--      the membership must match the person's own membership of that
--      organisation (`tblUserSites`), AND the group must belong to that same
--      organisation. So a group membership for somebody who is not a member
--      of the organisation, or naming another organisation's group, is
--      refused by the database even if the page code were wrong.
--   C. `tblDepts` already had `siteID` (since migration 015). It gains only
--      the index the composite foreign key in D needs.
--   D. `tblUserDepts` gets the same treatment as `tblUserGroups` in B. The
--      five flags stay exactly where they are.
--   E. Ten new addresses (the group and department pages, and the page for
--      rows that could not be placed), and this migration's own record.
--
-- -----------------------------------------------------------------------------
-- HOW EXISTING ROWS ARE CARRIED OVER (the #533 / #516 way: never a guess)
-- -----------------------------------------------------------------------------
-- There should be no rows in any of the four tables on a real installation,
-- because nothing could write them. This section exists for the rare
-- database somebody edited by hand.
--
-- GROUPS. A group only gets an organisation automatically where that is not
-- a guess: on a single-organisation portal (the portal-wide
-- `multisite.enabled` row is absent or not the text 'true' — the exact
-- reading migration 199 and `AccountGuard::isSingleOrganisation()` use), or
-- where multi-organisation working is switched on but exactly one
-- organisation row exists. Anywhere else the group is copied into
-- `tblGroupsUnplaced` and removed from `tblGroups`, for a global
-- administrator to place by hand at `/admin/users/memberships-unplaced`.
-- A group membership follows its group, PROVIDED the person has a
-- membership row (active or ended) in that organisation; otherwise it is
-- copied into `tblUserGroupsUnplaced` instead.
--
-- DEPARTMENTS carry over WITHOUT the single-organisation condition, and this
-- is deliberate. A department's `siteID` has been real data since migration
-- 015: the expense claim form's department list, the claim's save handler
-- and the API all already treat a department as living in that
-- organisation. Following it is therefore not a guess. The ONLY thing that
-- parks a department membership is the person having no membership row
-- (active or ended) in the department's own organisation — then it is copied
-- into `tblUserDeptsUnplaced`.
--
-- A parked group keeps its NUMBER in the pen, and gets that same number back
-- when it is placed. That matters because a workflow step names a group by
-- typing its number as text (`tblWorkflowSteps.assigneeValue`); a new number
-- would silently break the step. Proven while planning: after the group row
-- is removed, re-inserting it with its original number succeeds, and the
-- next brand-new group still gets a fresh number, never a parked one.
--
-- -----------------------------------------------------------------------------
-- THE ORDERING TRAP, STATED PLAINLY (the 199 and 202 precedent)
-- -----------------------------------------------------------------------------
-- On a portal with SEVERAL organisations, a hand-inserted group and all of
-- its memberships stop working the moment this migration runs, and stay
-- parked until a global administrator places them. While the group is
-- parked it grants nothing: not a workflow step, not an asset.
--
-- A parked group's asset ownership rows (`tblAssetOwners`) are REMOVED, not
-- parked, by the foreign key `fk_asto_group` that migration 159 created with
-- "delete these too" behaviour. After placement they must be added again
-- from the asset's own page. Considered and rejected: parking those rows as
-- well. No real installation can have a hand-made group (nothing could ever
-- create one), and an extra pen table would exist only for that case.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS MIGRATION CANNOT DO
-- -----------------------------------------------------------------------------
-- It cannot guess which organisation a hand-made group belongs to on a
-- portal with several organisations; that judgement is left to a person. It
-- cannot place a membership for somebody who has no membership row in the
-- organisation at all. It does not make `tblDepts.isActive` required (NOT
-- NULL): a NULL there, from a hand edit, already means "switched off"
-- everywhere the column is read, and making the column required would force
-- this migration to decide what a NULL was meant to be. It keeps the two
-- original single-column foreign keys on each membership table (redundant
-- now, but harmless — removing them buys nothing). It has not been tested
-- on MariaDB, like everything else here.
--
-- Safe to run twice: every structural change is guarded by a check of
-- `information_schema` (the MySQL 8.0-safe form; MariaDB-only
-- `IF NOT EXISTS` on columns and indexes is refused by MySQL), and every
-- data statement finds nothing left to do on a second run. The installer
-- replays every numbered migration after `full_schema.sql`, so this matters.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/517
--
-- -----------------------------------------------------------------------------
-- CHANGED IN PLACE, 24 September 2026 (#552) — idx_groups_id_site and
-- idx_depts_id_site
-- -----------------------------------------------------------------------------
-- Both of these used to be added as an ordinary (non-unique) KEY. MySQL 8.4
-- refuses to create a foreign key unless it points at a PRIMARY or UNIQUE
-- key on exactly its own columns, and this same migration's own B8 and D8
-- steps add composite foreign keys pointing at exactly these two indexes.
-- This codebase's house convention folds every migration's DDL back into
-- `full_schema.sql` too (see DEV_NOTES.md → "`full_schema.sql` fold
-- pattern"), so both bad foreign keys also sat directly inside
-- `full_schema.sql` — it is a FRESH INSTALL on MySQL 8.4 that failed FIRST,
-- straight away, inside `full_schema.sql` itself, before this migration
-- ever ran at all. It is the UPGRADE path that fails inside THIS migration:
-- an upgrade never runs `full_schema.sql` — it replays the numbered
-- migrations in order against whatever a real database already has — so on
-- an 8.4 upgrade the failure happens here, at B8 (the groups link) — never
-- at D8, which this file's own statement order never reaches on a failed
-- run; see the comment beside C, below, for why. The Migrator then stops:
-- nothing after it in the queue can ever run. This is changed IN PLACE, not
-- fixed by a later migration, because migrations 188-206 have never been
-- released (there is no earlier copy of this file for a real customer to
-- have already run) and because a later migration would be too late anyway
-- for the UPGRADE path: it stops at the first failing migration, so a fix
-- sitting in 207 or later would never be reached. Both blocks now handle
-- every state a database on which THIS MIGRATION ACTUALLY RUNS can be in —
-- see the comments beside A7b and C, below, for the detail, and for the one
-- state neither block can repair.
-- =============================================================================


-- #############################################################################
-- 🅰️  A. tblGroups gains an organisation and an on/off switch
-- #############################################################################

-- ➕ A1/A2 The two new columns. `siteID` starts NULLABLE on purpose: an
--    existing hand-made group needs somewhere to sit while A3-A5 work out
--    where it belongs, before A6 finally makes the column required (the
--    same shape migration 202 used for tblUserRoles). `isActive` is
--    required from the start and defaults to 1 (switched on), because every
--    existing group was, in effect, switched on.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND COLUMN_NAME='siteID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblGroups` ADD COLUMN `siteID` INT NULL AFTER `groupID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND COLUMN_NAME='isActive');
SET @sql := IF(@c=0, 'ALTER TABLE `tblGroups` ADD COLUMN `isActive` TINYINT(1) NOT NULL DEFAULT 1 AFTER `description`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🅰️ A3 Carry-over A — single-organisation portal: every group goes to
--    organisation 1, the only one such a portal has ever recognised (the
--    exact reading migration 199 uses). The `EXISTS` on organisation 1 is a
--    belt: if organisation 1 has been removed by hand, nothing is placed
--    here and the group is parked instead, rather than pointing at an
--    organisation that does not exist.
UPDATE `tblGroups` g SET g.`siteID` = 1
WHERE g.`siteID` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `tblSettings` s WHERE s.`settingKey` = 'multisite.enabled' AND s.`siteID` IS NULL AND s.`settingValue` = 'true')
  AND EXISTS (SELECT 1 FROM `tblSites` st WHERE st.`siteID` = 1);
-- 🅱️ A4 Carry-over B — multi-organisation working is switched on, but the
--    portal holds EXACTLY ONE organisation row (active or not). One
--    organisation is not a guess: it is the only one that exists.
UPDATE `tblGroups` g SET g.`siteID` = (SELECT st.`siteID` FROM `tblSites` st LIMIT 1)
WHERE g.`siteID` IS NULL
  AND EXISTS (SELECT 1 FROM `tblSettings` s WHERE s.`settingKey` = 'multisite.enabled' AND s.`siteID` IS NULL AND s.`settingValue` = 'true')
  AND (SELECT COUNT(*) FROM `tblSites` st2) = 1;


-- #############################################################################
-- 🅱️  B. tblUserGroups — the organisation on every group membership
-- #############################################################################

-- ➕ B1 The three new columns. `siteID` starts NULLABLE for the same reason
--    as A1; B6 makes it required once every row has one or has been parked.
--    `addedByID` records who added the person, for the audit trail; it is
--    emptied (not the membership removed) if that person's own account is
--    later erased — see `GdprEraser::catalogue()`.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND COLUMN_NAME='siteID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD COLUMN `siteID` INT NULL AFTER `groupID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND COLUMN_NAME='addedAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD COLUMN `addedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND COLUMN_NAME='addedByID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD COLUMN `addedByID` INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧹 B2 Duplicates removed FIRST, keeping the lowest id, so neither the
--    carry-over nor the pen ever sees two copies of the same membership.
--    The table had no rule against duplicates before this migration. A group
--    membership carries no flags, so nothing is lost by the removal. Safe to
--    run twice: the second run finds nothing left to delete.
DELETE d FROM `tblUserGroups` d JOIN `tblUserGroups` k ON k.`userID` = d.`userID` AND k.`groupID` = d.`groupID` AND k.`userGroupID` < d.`userGroupID`;

-- 🧭 B3 Carry-over: a membership follows its group's organisation (set in
--    A3/A4), PROVIDED the person has a membership row there. An ENDED
--    membership row still counts: the carried group membership grants
--    nothing while the organisation membership is ended (every reader
--    requires it to be active), and comes back to life if they are re-added.
--    A group still without an organisation (a multi-organisation portal)
--    places none of its memberships here; they go to the pen in B4.
UPDATE `tblUserGroups` ug
JOIN `tblGroups` g ON g.`groupID` = ug.`groupID`
JOIN `tblUserSites` us ON us.`userID` = ug.`userID` AND us.`siteID` = g.`siteID`
SET ug.`siteID` = g.`siteID`
WHERE ug.`siteID` IS NULL AND g.`siteID` IS NOT NULL;

-- 🖊️ B4 The pen for group memberships that could not be placed: either the
--    group itself is parked (A5, next), or the person has no membership row
--    in the group's organisation. Nothing is guessed; a global administrator
--    places or discards each row at `/admin/users/memberships-unplaced`.
--    The group's NAME is copied too, because a parked group's own row is
--    about to be removed from `tblGroups` in A5 and the pen page still
--    needs something readable to show.
--
--    WHY `INSERT IGNORE` HERE, BACKED BY THE UNIQUE KEY ON THE ORIGINAL ID
--    (the migration 202 B5 reason): an `AND NOT EXISTS` version of the same
--    statement tripped `check_migration_idempotency.py`, which cannot see
--    that the row it would re-insert is the one already there. The unique
--    key makes a second run a no-op. (In PHP the opposite rule holds — see
--    `UserGroups::addMember()`: there a refusal by a foreign key must be
--    SEEN, and `INSERT IGNORE` would swallow it.)
--
--    Then the parked rows are removed from `tblUserGroups`, because B6 is
--    about to make `siteID` required there.
CREATE TABLE IF NOT EXISTS `tblUserGroupsUnplaced` (
    `unplacedID`          INT NOT NULL AUTO_INCREMENT,
    `originalUserGroupID` INT NOT NULL,
    `userID`              INT NOT NULL,
    `groupID`             INT NOT NULL,
    `groupName`           VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `createdAt`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`unplacedID`),
    UNIQUE KEY `uq_ug_unplaced_original` (`originalUserGroupID`),
    KEY `idx_ug_unplaced_user` (`userID`),
    KEY `idx_ug_unplaced_group` (`groupID`),
    CONSTRAINT `fk_ug_unplaced_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO `tblUserGroupsUnplaced` (`originalUserGroupID`, `userID`, `groupID`, `groupName`)
SELECT ug.`userGroupID`, ug.`userID`, ug.`groupID`, g.`groupName`
FROM `tblUserGroups` ug JOIN `tblGroups` g ON g.`groupID` = ug.`groupID`
WHERE ug.`siteID` IS NULL;
DELETE FROM `tblUserGroups` WHERE `siteID` IS NULL;

-- 🖊️ A5 The pen for GROUPS that could not be placed (a portal with several
--    organisations). The group's number, name and description are kept in
--    `tblGroupsUnplaced`; placing it later re-creates the group WITH ITS
--    ORIGINAL NUMBER (see the header for why the number matters). The group
--    row is then removed from `tblGroups`, because A6 is about to make
--    `siteID` required. This deliberately runs AFTER B4, so the member rows
--    have already been copied (with the group's name) before the group row
--    goes. Removing the group row also removes its `tblAssetOwners` rows,
--    through migration 159's `fk_asto_group` — stated in the header.
--    `INSERT IGNORE` for the same reason as B4.
CREATE TABLE IF NOT EXISTS `tblGroupsUnplaced` (
    `unplacedID`      INT NOT NULL AUTO_INCREMENT,
    `originalGroupID` INT NOT NULL,
    `groupName`       VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `description`     TEXT COLLATE utf8mb4_general_ci,
    `createdAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`unplacedID`),
    UNIQUE KEY `uq_g_unplaced_original` (`originalGroupID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO `tblGroupsUnplaced` (`originalGroupID`, `groupName`, `description`)
SELECT g.`groupID`, g.`groupName`, g.`description` FROM `tblGroups` g WHERE g.`siteID` IS NULL;
DELETE FROM `tblGroups` WHERE `siteID` IS NULL;

-- 🔒 A6 From here on every group belongs to a real organisation; NULL never
--    means "all organisations" or anything else.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND COLUMN_NAME='siteID' AND IS_NULLABLE='YES');
SET @sql := IF(@c>0, 'ALTER TABLE `tblGroups` MODIFY COLUMN `siteID` INT NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔑 A7 Indexes, each declared by name so this file and `full_schema.sql`
--    agree object for object. `idx_groups_id_site` is not optional: InnoDB
--    requires the columns a composite foreign key POINTS AT to lead an
--    index, and B8's `fk_user_group_group_site` points at
--    (groupID, siteID). Proven while planning: without it, adding that
--    foreign key fails with "ERROR 1822 Missing index for constraint".
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND INDEX_NAME='idx_groups_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblGroups` ADD KEY `idx_groups_site` (`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔒 A7b idx_groups_id_site must be UNIQUE, not merely present, since
--    24 September 2026 (#552) — see this file's header for why it is
--    changed in place. An EARLIER COPY of this migration existed on this
--    unreleased branch before #552 was found — deploys come only from the
--    three release branches, `main`, `beta` and `alpha` (a manual run of
--    `deploy.yml` could target another branch, but every one of the 111
--    runs on record has used one of those three), so no released or
--    deployed database has ever run the earlier copy. A database on which
--    this migration ACTUALLY RUNS (this attempt or an earlier one) can be
--    in any of three states here. (1) Index missing -> create it UNIQUE.
--    (2) Already unique -> nothing to do. This is what a fresh install
--    sees, because full_schema.sql already made it unique — this branch
--    trusts the NAME `idx_groups_id_site` alone; if an index of that name
--    were ever made unique by hand on the WRONG columns, this guard would
--    still do nothing, and B8 further below would then fail loudly with
--    ERROR 6125 rather than silently accepting it. (3) Exists but
--    ordinary -> replace it with a UNIQUE key of the same name in ONE
--    statement. State 3 is only possible from an EARLIER, pre-#552 copy of
--    this file — it could have got as far as creating this index (here, in
--    A7) on an 8.4 run and then failed at the foreign key in B8 (A8, B6
--    and B7 come between this block and B8, so it is not the very next
--    statement, but it is still the FIRST place an 8.4 run of the old file
--    can fail); or it could have run all the way to COMPLETION, whether on
--    MySQL 8.0 or MariaDB (neither ever enforced the new MySQL 8.4 rule)
--    or by somebody re-running it by hand — a hand re-run lands in the
--    same state as completion, not a third, separate cause. Cannot fail on
--    data already in the table: `groupID` is this table's own PRIMARY KEY,
--    so no two rows can ever share one.
--    WHAT THIS CANNOT DO: repair a database on which an earlier, pre-#552
--    copy of this migration already ran to COMPLETION with the old,
--    non-unique index (the completion branch of state 3, above, not the
--    8.4-partial-failure branch, which this fixed copy's own re-run does
--    repair) — the Migrator never re-runs a migration it has already
--    recorded as done. That state can only arise on an unreleased
--    development or test database — see DEV_NOTES.md, #552; the repair is
--    to run this fixed migration by hand (it is safe to re-run), or
--    reinstall.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND INDEX_NAME='idx_groups_id_site' AND SEQ_IN_INDEX=1);
SET @nonUnique := (SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND INDEX_NAME='idx_groups_id_site' AND SEQ_IN_INDEX=1);
SET @sql := IF(@c=0,
    'ALTER TABLE `tblGroups` ADD UNIQUE KEY `idx_groups_id_site` (`groupID`,`siteID`)',
    IF(@nonUnique=0,
        'SELECT 1',
        'ALTER TABLE `tblGroups` DROP INDEX `idx_groups_id_site`, ADD UNIQUE KEY `idx_groups_id_site` (`groupID`,`siteID`)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔗 A8 Every group belongs to a real organisation; removing an
--    organisation removes its groups (and, through B8, their memberships).
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblGroups' AND CONSTRAINT_NAME='fk_groups_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblGroups` ADD CONSTRAINT `fk_groups_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🔒 B6 Every group membership now names its organisation.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND COLUMN_NAME='siteID' AND IS_NULLABLE='YES');
SET @sql := IF(@c>0, 'ALTER TABLE `tblUserGroups` MODIFY COLUMN `siteID` INT NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔑 B7 Indexes, named. `uq_user_group` is the new rule against the same
--    person being in the same group twice. The two (…, siteID) keys serve
--    the composite foreign keys in B8 and the per-organisation lookups.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND INDEX_NAME='uq_user_group');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD UNIQUE KEY `uq_user_group` (`userID`,`groupID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND INDEX_NAME='idx_user_group_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD KEY `idx_user_group_site` (`userID`,`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND INDEX_NAME='idx_user_group_group_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD KEY `idx_user_group_group_site` (`groupID`,`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND INDEX_NAME='idx_user_group_org');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD KEY `idx_user_group_org` (`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND INDEX_NAME='idx_user_group_adder');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD KEY `idx_user_group_adder` (`addedByID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔗 B8 The composite foreign keys that make a wrong membership impossible
--    at the database level, not just in page code:
--      fk_user_group_membership — (userID, siteID) must be a real
--        membership row in `tblUserSites`; deleting that membership row
--        ("remove from this organisation") removes ONLY that
--        organisation's group memberships for the person.
--      fk_user_group_group_site — (groupID, siteID) must be a group of
--        THAT SAME organisation; another organisation's group is refused.
--      fk_user_group_site — the organisation itself must exist.
--      fk_user_group_adder — who added it; emptied, never cascaded, if
--        that account is removed.
--    Proven while planning: a non-member and another organisation's group
--    are both refused with error 1452, and a duplicate with error 1062.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND CONSTRAINT_NAME='fk_user_group_membership' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD CONSTRAINT `fk_user_group_membership` FOREIGN KEY (`userID`,`siteID`) REFERENCES `tblUserSites`(`userID`,`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND CONSTRAINT_NAME='fk_user_group_group_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD CONSTRAINT `fk_user_group_group_site` FOREIGN KEY (`groupID`,`siteID`) REFERENCES `tblGroups`(`groupID`,`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND CONSTRAINT_NAME='fk_user_group_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD CONSTRAINT `fk_user_group_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserGroups' AND CONSTRAINT_NAME='fk_user_group_adder' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserGroups` ADD CONSTRAINT `fk_user_group_adder` FOREIGN KEY (`addedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅲  C. tblDepts — only the index the composite key in D8 needs
-- #############################################################################
-- `tblDepts` already has `siteID` and `isActive`. InnoDB needs
-- (deptID, siteID) to lead an index before D8's `fk_user_dept_dept_site`
-- can point at it (proven: without it, "ERROR 1822 Missing index").
-- 🔒 It must be UNIQUE, not merely present, since 24 September 2026
--    (#552) — see this file's header for why it is changed in place. An
--    EARLIER COPY of this migration existed on this unreleased branch
--    before #552 was found — deploys come only from the three release
--    branches, `main`, `beta` and `alpha` (a manual run of `deploy.yml`
--    could target another branch, but every one of the 111 runs on record
--    has used one of those three), so no released or deployed database has
--    ever run the earlier copy. A database on which this migration
--    ACTUALLY RUNS (this attempt or an earlier one) can be in any of three
--    states here. (1) Index missing -> create it UNIQUE. (2) Already
--    unique -> nothing to do. This is what a fresh install sees, because
--    full_schema.sql already made it unique — this branch trusts the NAME
--    `idx_depts_id_site` alone; if an index of that name were ever made
--    unique by hand on the WRONG columns, this guard would still do
--    nothing, and D8 below would then fail loudly with ERROR 6125 rather
--    than silently accepting it. (3) Exists but ordinary -> replace it
--    with a UNIQUE key of the same name in ONE statement. Unlike A7b
--    above, state 3 here is NEVER possible from an 8.4 run stopping
--    partway through THIS block: an 8.4 run of the old file always fails
--    earlier than this, at B8's foreign key on tblUserGroups, before this
--    block is ever reached, so `idx_depts_id_site` would not exist at all
--    after such a failure — that is state 1, not state 3. State 3 here can
--    only be reached from an EARLIER, pre-#552 copy of this file running
--    all the way to COMPLETION, whether on MySQL 8.0 or MariaDB (neither
--    ever enforced the new MySQL 8.4 rule) or by somebody re-running it by
--    hand — a hand re-run lands in the same state as completion, not a
--    separate cause. Cannot fail on data already in the table: `deptID` is
--    this table's own PRIMARY KEY, so no two rows can ever share one.
--    WHAT THIS CANNOT DO: repair a database on which an earlier, pre-#552
--    copy of this migration already ran to COMPLETION with the old,
--    non-unique index (state 3, above — this one has no 8.4-partial-
--    failure branch to distinguish it from, unlike A7b's) — the Migrator
--    never re-runs a migration it has already recorded as done. That state
--    can only arise on an unreleased development or test database — see
--    DEV_NOTES.md, #552; the repair is to run this fixed migration by hand
--    (it is safe to re-run), or reinstall.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblDepts' AND INDEX_NAME='idx_depts_id_site' AND SEQ_IN_INDEX=1);
SET @nonUnique := (SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblDepts' AND INDEX_NAME='idx_depts_id_site' AND SEQ_IN_INDEX=1);
SET @sql := IF(@c=0,
    'ALTER TABLE `tblDepts` ADD UNIQUE KEY `idx_depts_id_site` (`deptID`,`siteID`)',
    IF(@nonUnique=0,
        'SELECT 1',
        'ALTER TABLE `tblDepts` DROP INDEX `idx_depts_id_site`, ADD UNIQUE KEY `idx_depts_id_site` (`deptID`,`siteID`)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🅳  D. tblUserDepts — the organisation on every department membership;
--        the five flags kept exactly where they are
-- #############################################################################

-- ➕ D1 The three new columns (the same reasoning as B1).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND COLUMN_NAME='siteID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD COLUMN `siteID` INT NULL AFTER `deptID`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND COLUMN_NAME='addedAt');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD COLUMN `addedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND COLUMN_NAME='addedByID');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD COLUMN `addedByID` INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 🧹 D2 Duplicates. Unlike a group membership, a department membership
--    carries FLAGS, and two hand-inserted copies of the same membership can
--    carry different ones (one says "approver", the other "required
--    approver"). Simply deleting the later copy would lose a flag, and with
--    it somebody's right, or duty, to approve expense claims. So every flag
--    on a duplicate is folded into the kept (lowest-id) row FIRST —
--    "set if either copy has it set" (`GREATEST` of the two, with a NULL
--    counted as 0) — and only then are the duplicates removed. Proven while
--    planning: (person 104, Music, approver) plus (person 104, Music,
--    required approver) became one row carrying both flags. Safe to run
--    twice: after the first run no duplicates are left for either
--    statement to find.
UPDATE `tblUserDepts` k
JOIN `tblUserDepts` d ON d.`userID` = k.`userID` AND d.`deptID` = k.`deptID` AND d.`userDeptID` > k.`userDeptID`
SET k.`isDeptLead`          = GREATEST(COALESCE(k.`isDeptLead`, 0),          COALESCE(d.`isDeptLead`, 0)),
    k.`isDeptAssistant`     = GREATEST(COALESCE(k.`isDeptAssistant`, 0),     COALESCE(d.`isDeptAssistant`, 0)),
    k.`isDeptSecretary`     = GREATEST(COALESCE(k.`isDeptSecretary`, 0),     COALESCE(d.`isDeptSecretary`, 0)),
    k.`isApprover`          = GREATEST(COALESCE(k.`isApprover`, 0),          COALESCE(d.`isApprover`, 0)),
    k.`isMandatoryApprover` = GREATEST(COALESCE(k.`isMandatoryApprover`, 0), COALESCE(d.`isMandatoryApprover`, 0));
DELETE d FROM `tblUserDepts` d JOIN `tblUserDepts` k ON k.`userID` = d.`userID` AND k.`deptID` = d.`deptID` AND k.`userDeptID` < d.`userDeptID`;

-- 🧭 D3 Carry-over: a department membership follows the department's own
--    organisation — real data since migration 015, so NOT a guess, and no
--    single-organisation condition is needed (see the header) — PROVIDED
--    the person has a membership row (active or ended) there.
UPDATE `tblUserDepts` ud
JOIN `tblDepts` d ON d.`deptID` = ud.`deptID`
JOIN `tblUserSites` us ON us.`userID` = ud.`userID` AND us.`siteID` = d.`siteID`
SET ud.`siteID` = d.`siteID`
WHERE ud.`siteID` IS NULL;

-- 🖊️ D4 The pen for department memberships that could not be placed (the
--    person has no membership row in the department's organisation). The
--    five flags are copied with it, a NULL counted as 0, so placing the row
--    later restores exactly what it said. `INSERT IGNORE` for the B4
--    reason. Then the parked rows are removed so D6 can make `siteID`
--    required.
CREATE TABLE IF NOT EXISTS `tblUserDeptsUnplaced` (
    `unplacedID`          INT NOT NULL AUTO_INCREMENT,
    `originalUserDeptID`  INT NOT NULL,
    `userID`              INT NOT NULL,
    `deptID`              INT NOT NULL,
    `isDeptLead`          TINYINT(1) NOT NULL DEFAULT 0,
    `isDeptAssistant`     TINYINT(1) NOT NULL DEFAULT 0,
    `isDeptSecretary`     TINYINT(1) NOT NULL DEFAULT 0,
    `isApprover`          TINYINT(1) NOT NULL DEFAULT 0,
    `isMandatoryApprover` TINYINT(1) NOT NULL DEFAULT 0,
    `createdAt`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`unplacedID`),
    UNIQUE KEY `uq_ud_unplaced_original` (`originalUserDeptID`),
    KEY `idx_ud_unplaced_user` (`userID`),
    KEY `idx_ud_unplaced_dept` (`deptID`),
    CONSTRAINT `fk_ud_unplaced_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ud_unplaced_dept` FOREIGN KEY (`deptID`) REFERENCES `tblDepts`(`deptID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO `tblUserDeptsUnplaced` (`originalUserDeptID`, `userID`, `deptID`, `isDeptLead`, `isDeptAssistant`, `isDeptSecretary`, `isApprover`, `isMandatoryApprover`)
SELECT ud.`userDeptID`, ud.`userID`, ud.`deptID`, COALESCE(ud.`isDeptLead`,0), COALESCE(ud.`isDeptAssistant`,0), COALESCE(ud.`isDeptSecretary`,0), COALESCE(ud.`isApprover`,0), COALESCE(ud.`isMandatoryApprover`,0)
FROM `tblUserDepts` ud WHERE ud.`siteID` IS NULL;
DELETE FROM `tblUserDepts` WHERE `siteID` IS NULL;

-- 🔒 D6 Every department membership now names its organisation.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND COLUMN_NAME='siteID' AND IS_NULLABLE='YES');
SET @sql := IF(@c>0, 'ALTER TABLE `tblUserDepts` MODIFY COLUMN `siteID` INT NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔑 D7 Indexes, named (the same set as B7).
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND INDEX_NAME='uq_user_dept');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD UNIQUE KEY `uq_user_dept` (`userID`,`deptID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND INDEX_NAME='idx_user_dept_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD KEY `idx_user_dept_site` (`userID`,`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND INDEX_NAME='idx_user_dept_dept_site');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD KEY `idx_user_dept_dept_site` (`deptID`,`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND INDEX_NAME='idx_user_dept_org');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD KEY `idx_user_dept_org` (`siteID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND INDEX_NAME='idx_user_dept_adder');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD KEY `idx_user_dept_adder` (`addedByID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 🔗 D8 The composite foreign keys (the same four rules as B8, for
--    departments): a department membership for a non-member, or naming
--    another organisation's department, is refused by the database itself;
--    removing a person from ONE organisation removes only that
--    organisation's department memberships.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND CONSTRAINT_NAME='fk_user_dept_membership' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD CONSTRAINT `fk_user_dept_membership` FOREIGN KEY (`userID`,`siteID`) REFERENCES `tblUserSites`(`userID`,`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND CONSTRAINT_NAME='fk_user_dept_dept_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD CONSTRAINT `fk_user_dept_dept_site` FOREIGN KEY (`deptID`,`siteID`) REFERENCES `tblDepts`(`deptID`,`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND CONSTRAINT_NAME='fk_user_dept_site' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD CONSTRAINT `fk_user_dept_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tblUserDepts' AND CONSTRAINT_NAME='fk_user_dept_adder' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @sql := IF(@c=0, 'ALTER TABLE `tblUserDepts` ADD CONSTRAINT `fk_user_dept_adder` FOREIGN KEY (`addedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- 🔗  E. The ten new addresses, and the self-record
-- #############################################################################
-- The web root has no `admin` folder (`web/public_html/` holds only the
-- front controller, static assets and the three real entry-point files), so
-- none of these addresses can be hidden by a real file or folder of the
-- same name — see `.claude/CLAUDE.md`, "Web-root shadowing trap". Every one
-- is protected (sign-in required); each page then applies its own
-- administrator gate.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/groups',                         'admin/groups/index.php',                    1),
    ('admin/groups/save',                    'admin/groups/save.php',                     1),
    ('admin/groups/members',                 'admin/groups/members.php',                  1),
    ('admin/groups/members/save',            'admin/groups/members-save.php',             1),
    ('admin/departments',                    'admin/departments/index.php',               1),
    ('admin/departments/save',               'admin/departments/save.php',                1),
    ('admin/departments/members',            'admin/departments/members.php',             1),
    ('admin/departments/members/save',       'admin/departments/members-save.php',        1),
    ('admin/users/memberships-unplaced',     'admin/users/memberships-unplaced.php',      1),
    ('admin/users/memberships-unplaced/save','admin/users/memberships-unplaced-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Self-record. The installer replays every numbered migration after
--    `full_schema.sql` and ignores `tblMigrations`, so this has to be safe
--    to run a second time.
INSERT INTO `tblMigrations` (`filename`) VALUES ('203_groups_departments_per_organisation.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
