-- =============================================================================
-- Migration 199: every account belongs to an organisation (#533)
-- =============================================================================
-- Two addresses and a one-off data fix. No ALTER, no new table.
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- Before commit e1d0a34 (#518), the members page's "Add User" wrote a new row
-- into `tblUsers` and NOTHING into `tblUserSites` at all — so every account
-- created before that fix belongs to no organisation whatsoever. "Remove from
-- site" (`admin/sites/users.php:91`) can also delete a membership row outright,
-- which is the one path that still makes a row-less account on purpose today.
--
-- Nothing about that used to matter much, because nothing strict depended on
-- the row existing. Then the calendar feed (`calendar/feed.php`, commit
-- `110e47d`) started requiring an ACTIVE membership row before it would answer
-- with anybody's calendar. A row-less account's subscription now answers
-- "Invalid token." where it used to work — a real regression for anybody who
-- had it working before that commit shipped, discovered and confirmed on 20
-- September 2026.
--
-- Worse, three OTHER pages disagreed with the feed about what a row-less
-- account should be allowed to do: the check-in page (`anon-checkin.php`), its
-- save handler, and the waitlist promotion (`Events.php`) each carried their
-- own "single-organisation installations only, allow it anyway" exception, so
-- the very same row-less account could check in and be promoted off a waiting
-- list while its own calendar subscription was refused. That disagreement is
-- fixed in the same pull request as this migration: those three pages have
-- dropped their exceptions and are now as strict as the feed always was — see
-- their own comments.
--
-- -----------------------------------------------------------------------------
-- THE FIX — AND WHY IT GUESSES NOTHING
-- -----------------------------------------------------------------------------
-- The owner's decision (20 September 2026): create the missing membership
-- rows, but only where doing so is not a guess.
--
--   A. SINGLE-ORGANISATION PORTAL. The portal-wide `multisite.enabled` row is
--      absent, or is not the text 'true' — the exact same reading
--      `AccountGuard::isSingleOrganisation()` and `Site::preDetect()` already
--      use. On a portal like that, `Site::id()` always answers 1, and site 1
--      is the only organisation the portal has ever recognised. Placing a
--      row-less account there is not a guess; it is the only organisation
--      that exists.
--
--   B. MULTI-ORGANISATION WORKING SWITCHED ON, BUT THE PORTAL HOLDS EXACTLY
--      ONE ORGANISATION ROW IN `tblSites` — active or not. One organisation is
--      not a guess either: it is, again, the only one that exists. A
--      SWITCHED-OFF organisation still counts as "one" here on purpose — an
--      inactive `tblSites` row is still an organisation somebody may once have
--      belonged to, and treating it as though it were not there would risk
--      placing someone into the wrong home the moment a second, active
--      organisation is later added. Two rows, even with one switched off, IS a
--      guess, so nothing is placed automatically in that case.
--
-- Everywhere else — genuinely more than one organisation to choose from —
-- NOTHING IS GUESSED. Those accounts are left exactly as they are: no
-- membership row, and therefore still refused everywhere the row is required.
-- They are listed instead, for a global administrator to place by hand, at
-- the new `/admin/users/unplaced` page these two route rows register.
--
-- An OFFBOARDED account — one whose only `tblUserSites` row has
-- `isActive = 0` — is deliberately left untouched by both A and B. It
-- genuinely belongs nowhere right now; that is what offboarding means. The
-- `NOT EXISTS (SELECT 1 FROM tblUserSites ...)` test below only ever matches
-- an account with NO row at all, so an offboarded account is never touched.
--
-- `INSERT IGNORE` makes both statements safe to run twice, as every migration
-- must be: by the time a second run happens, every account this migration can
-- place already has a row, so the `NOT EXISTS` test excludes it and nothing is
-- inserted.
--
-- WHY THIS LIVES IN A MIGRATION, NOT IN `full_schema.sql` ITSELF
-- -----------------------------------------------------------------------------
-- A database freshly built from `full_schema.sql` has no `tblUsers` rows yet —
-- there is nobody to place. That file's own fold-in block for this migration
-- (search it for "from 199_membership_backfill.sql") carries only the two
-- route rows and the migration's own mark, with a comment explaining why the
-- data statements themselves are not repeated there: the installer replays
-- every numbered migration after `full_schema.sql` in order, migration 015
-- (which creates the very first account) runs before this one, and that
-- account is placed the ordinary way when it is created — so replaying 199
-- against a fresh install finds nothing left to do and inserts nothing.
--
-- -----------------------------------------------------------------------------
-- THE ORDERING TRAP, STATED PLAINLY
-- -----------------------------------------------------------------------------
-- Code without this migration leaves row-less members on a single-organisation
-- portal unable to check in to an internal event or be promoted off a waiting
-- list, from the moment the code change deploys, until this migration actually
-- runs. Their calendar feed is ALREADY broken for the same reason (that is
-- what #533 was opened to fix), the admin dashboard already shows a pending
-- migration the whole time, and the version-drift maintenance gate
-- (`Maintenance::isActive()`) forces the upgrade to run once the portal
-- version is bumped — so the window is expected to be short, but it is not
-- zero, and this paragraph exists so nobody is surprised by it.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/533
-- =============================================================================

-- #############################################################################
-- 🅰️  A. Single-organisation portal — place everyone who has no row at all
-- #############################################################################

INSERT IGNORE INTO `tblUserSites` (`userID`, `siteID`, `isActive`)
SELECT u.`userID`, 1, 1
FROM `tblUsers` u
WHERE NOT EXISTS (SELECT 1 FROM `tblUserSites` us WHERE us.`userID` = u.`userID`)
  AND NOT EXISTS (SELECT 1 FROM `tblSettings` s
                   WHERE s.`settingKey` = 'multisite.enabled' AND s.`siteID` IS NULL AND s.`settingValue` = 'true')
  AND EXISTS (SELECT 1 FROM `tblSites` st WHERE st.`siteID` = 1);


-- #############################################################################
-- 🅱️  B. Multi-organisation working is ON, but exactly one organisation row
--        exists (active or not) — still not a guess, so still placed
-- #############################################################################

INSERT IGNORE INTO `tblUserSites` (`userID`, `siteID`, `isActive`)
SELECT u.`userID`, (SELECT st.`siteID` FROM `tblSites` st LIMIT 1), 1
FROM `tblUsers` u
WHERE NOT EXISTS (SELECT 1 FROM `tblUserSites` us WHERE us.`userID` = u.`userID`)
  AND EXISTS (SELECT 1 FROM `tblSettings` s
               WHERE s.`settingKey` = 'multisite.enabled' AND s.`siteID` IS NULL AND s.`settingValue` = 'true')
  AND (SELECT COUNT(*) FROM `tblSites` st2) = 1;


-- #############################################################################
-- 🔗  C. The two addresses for the "place them by hand" page — a global
--        administrator only (see admin/users/unplaced.php's own gate)
-- #############################################################################

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/users/unplaced',      'admin/users/unplaced.php',      1),
    ('admin/users/unplaced/save', 'admin/users/unplaced-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋  D. Self-record (the installer replays every numbered migration after
--        full_schema.sql and ignores tblMigrations, so this INSERT has to be
--        safe to run a second time)
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('199_membership_backfill.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
