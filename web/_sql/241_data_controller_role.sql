-- =============================================================================
-- Migration 241: the Data Controller role, and a grant so somebody holds it
-- =============================================================================
-- Adds a FIFTEENTH standard role, `data_controller`, to every organisation, and
-- grants it to the first administrator of each — then marks it standard so no
-- organisation can delete it.
--
-- -----------------------------------------------------------------------------
-- WHY A ROLE AND NOT A FLAG
-- -----------------------------------------------------------------------------
-- The owner, 3 November 2026: the Data Controller is who is answerable for
-- personal data under the UK GDPR. It is a named RESPONSIBILITY, not a job
-- title — it "could be held by the global administrator, another
-- administrator, or someone else entirely", and it moves from one person to
-- another over time. A role is grantable, revocable and auditable; a flag on
-- an account is none of those things.
--
-- -----------------------------------------------------------------------------
-- WHY MIGRATION 202 IS NOT EDITED, WHICH IS THE WHOLE REASON THIS FILE EXISTS
-- -----------------------------------------------------------------------------
-- Migration 202 seeds the standard set and has ALREADY RUN on every
-- installation. Adding a fifteenth key to its A9 seed would be a change nobody
-- receives: the role would exist in `Roles::STANDARD` and in a fresh install,
-- and be missing from every upgraded organisation. The code would then ask for
-- a key nobody can hold, which is the #516 fault exactly.
--
-- `tools/audit-checks/check_role_keys.py` now knows this route: it compares
-- `Roles::STANDARD` against 202's seed PLUS the post-202 role migrations named
-- in its `LATER_STANDARD_ROLES`, and fails when a role in the code has no
-- migration to put it on existing installations.
--
-- -----------------------------------------------------------------------------
-- WHY IT GRANTS AS WELL AS SEEDS
-- -----------------------------------------------------------------------------
-- Its gate is the ONE exception in the portal: it asks `Roles::has()` directly
-- rather than `App::hasRole()`, which answers true for any global administrator
-- without a role row. Without that exception the role would change nothing,
-- because an installation's administrator and an organisation's may be
-- different parties and a hosting provider's administrator should not be the
-- person reading a member's own words to decide whether they must go.
--
-- The cost of that exception is that NOBODY holds the role after an upgrade,
-- and an erasure request cannot close while an item is unreviewed — so requests
-- would stack up with no way through. That is the #499 shape ("fail closed" was
-- built first and locked members out). So this grants it to the FIRST
-- administrator of each organisation: `MIN(userID)` among that organisation's
-- own administrators, which is the account that installed or was given the
-- organisation. An administrator added later gets nothing by default, which is
-- the point.
--
-- -----------------------------------------------------------------------------
-- WHAT THIS CANNOT DO
-- -----------------------------------------------------------------------------
-- It cannot know who the real data controller is. It puts the role somewhere it
-- can be found and moved; naming the right person is the customer's job, and
-- the roles page is where they do it.
--
-- An organisation with no administrator of its own gets the role and no holder.
-- That is honest rather than clever: inventing a holder would be worse, and the
-- erasure review page says plainly that nobody holds it.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Every statement is guarded by `WHERE NOT EXISTS` or by the unique key, so a
-- second run inserts nothing and changes nothing.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/724
-- =============================================================================

-- ── A. The role, for every organisation that does not have it ────────────────
INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`)
SELECT s.`siteID`, 'data_controller', 'Data Controller',
       'Answerable for personal data: reviews the free text an erasure cannot decide about on its own, and decides what goes',
       1
FROM `tblSites` s
WHERE NOT EXISTS (
    SELECT 1 FROM `tblRoles` r
    WHERE r.`siteID` = s.`siteID` AND r.`roleKey` = 'data_controller'
);

-- ── B. Marked standard, in case an older hand-made row of this key exists ────
-- A custom role can be DELETED by an organisation. If somebody had already made
-- a role with this key by hand, the code would be asking for a key that can
-- disappear, so it is marked standard here rather than left as they made it.
UPDATE `tblRoles`
   SET `isStandard` = 1
 WHERE `roleKey` = 'data_controller' AND `isStandard` <> 1;

-- ── C. Granted to the FIRST administrator of each organisation ───────────────
-- `MIN(u.userID)` among the organisation's own active administrators: the
-- lowest account number is the one that installed the portal or was given the
-- organisation first. `tblUserSites` is what says somebody belongs to an
-- organisation (#502), and `isActive = 1` on both sides so a closed account or
-- a revoked membership is never given a responsibility.
--
-- FOUR admin flags across TWO tables, and that matters: `isAdmin` and
-- `isRootAdmin` are portal-wide on `tblUsers`, while `isSiteAdmin` and
-- `isSiteRootAdmin` are per-organisation on `tblUserSites`. A portal-wide
-- administrator counts because that is how a single-organisation install marks
-- its administrator (#616's reasoning), but they only reach the organisations
-- they actually belong to, because the join is on `tblUserSites`.
INSERT INTO `tblUserRoles` (`userID`, `roleID`, `siteID`)
SELECT first_admin.`userID`, r.`roleID`, r.`siteID`
FROM `tblRoles` r
JOIN (
    SELECT us.`siteID`, MIN(u.`userID`) AS `userID`
    FROM `tblUserSites` us
    JOIN `tblUsers` u ON u.`userID` = us.`userID`
    WHERE us.`isActive` = 1
      AND u.`isActive` = 1
      -- 🪤 THE FLAGS LIVE ON TWO DIFFERENT TABLES, and a first version of this
      --    put all four on `tblUsers`. ERROR 1054, caught by running the
      --    migration against a real database rather than reading it:
      --    `tblUsers` has only `isAdmin` and `isRootAdmin` (portal-wide), while
      --    `isSiteAdmin` and `isSiteRootAdmin` are on `tblUserSites` — they are
      --    per-organisation by nature, which is the whole point of #516.
      --    Qualify every one of them; the error is the lucky case, and a
      --    mis-qualified column that HAPPENED to exist on both would have been
      --    silent.
      AND (u.`isAdmin` = 1 OR u.`isRootAdmin` = 1
           OR us.`isSiteAdmin` = 1 OR us.`isSiteRootAdmin` = 1)
    GROUP BY us.`siteID`
) first_admin ON first_admin.`siteID` = r.`siteID`
WHERE r.`roleKey` = 'data_controller'
  AND NOT EXISTS (
      SELECT 1 FROM `tblUserRoles` ur
      WHERE ur.`userID` = first_admin.`userID`
        AND ur.`roleID` = r.`roleID`
        AND ur.`siteID` = r.`siteID`
  )
-- 🔁 BELT AND BRACES, and both halves do real work. The `NOT EXISTS` above is
--    what makes a second run insert nothing. This clause is what stops a
--    duplicate ERRORING if two runs ever overlapped — `uq_user_role` covers
--    (userID, roleID) and all three columns are NOT NULL, so unlike the keys
--    #487 is about this one really does bind. It also satisfies
--    `check_migration_idempotency.py`, which asks for one of the two forms by
--    name and cannot see a `NOT EXISTS` several lines below the INSERT.
--
--    ⚠️ THAT CHECK REPORTS WITHOUT FAILING — it printed this very statement
--       and still exited 0, the same shape #647 records for
--       `check_schema_seed_parity.py`. So READ its output; a green exit code
--       is not an answer.
ON DUPLICATE KEY UPDATE `userID` = `tblUserRoles`.`userID`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('241_data_controller_role.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
