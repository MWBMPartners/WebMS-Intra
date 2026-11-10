-- =============================================================================
-- Migration 271: who sees an organisation's attendance totals is a PERMISSION
--                (#529; the owner, 10 November 2026 — D-106)
-- =============================================================================
-- What this changes:
--
--   attendance_coordinator   a standard role, "Attendance Coordinator", for
--                            every organisation — the role the new permission
--                            `attendance.reports` (Portal\Core\Capabilities)
--                            ships pointing at. This migration grants it to
--                            NOBODY: out of the box only an organisation's
--                            administrators see its attendance totals, which
--                            is what they saw before, because the setting this
--                            replaces was seeded "administrators only".
--   attend.reports.visibleTo DELETED, at every level (the installation's row
--                            and any organisation's own). It was a choice of
--                            three — administrators only, administrators and
--                            event coordinators, any member — and D-106
--                            replaces it: administrators, plus whoever the
--                            organisation points the permission at on Admin,
--                            Roles. "Any member" is ruled out by D-106, and a
--                            setting nothing reads any more would be a lie
--                            with a Save button (#671), so it goes.
--
-- WHAT THIS DOES TO AN ORGANISATION THAT HAD CHOSEN SOMETHING WIDER, said
-- plainly because it takes access away: an organisation whose own row said
-- "any member" or "administrators and event coordinators" loses that the
-- moment this runs, and from then on only its administrators see the totals
-- until somebody grants the new role or re-points the permission. That is the
-- decision (D-106 rules "every member" out, and "event coordinators" was never
-- a role a permission can point at — it meant anybody coordinating any
-- event). It cannot be carried over automatically for the same reason. No
-- customer installation exists yet, so today this costs nobody anything.
--
-- A ROLE OF THIS KEY MADE BY HAND: the permission matches a role by its KEY,
-- so a role an organisation had made itself called `attendance_coordinator`
-- before this upgrade opens the totals as soon as this runs. As in migration
-- 262, such a role is marked standard only where nobody holds it; one somebody
-- does hold is left exactly as it is.
--
-- SAFE TO RUN TWICE: the role is added only where it is missing, the UPDATE
-- changes nothing the second time, a DELETE of rows already gone deletes
-- nothing, and the migration's own row is written with ON DUPLICATE KEY UPDATE.
-- Every identifier is backticked. MySQL 8.0 and MariaDB alike: no IF EXISTS on
-- a column or index anywhere in this file.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/529
-- =============================================================================

INSERT INTO `tblRoles` (`siteID`, `roleKey`, `roleName`, `description`, `isStandard`)
SELECT s.`siteID`, 'attendance_coordinator', 'Attendance Coordinator',
       'Holds "See the attendance totals": opens the attendance reports page and the attendance spreadsheet download',
       1
FROM `tblSites` s
WHERE NOT EXISTS (
    SELECT 1 FROM `tblRoles` r
    WHERE r.`siteID` = s.`siteID` AND r.`roleKey` = 'attendance_coordinator'
);

UPDATE `tblRoles` r
   SET r.`isStandard` = 1
 WHERE r.`roleKey` = 'attendance_coordinator' AND r.`isStandard` <> 1
   AND NOT EXISTS (SELECT 1 FROM `tblUserRoles` ur WHERE ur.`roleID` = r.`roleID`);

DELETE FROM `tblSettings` WHERE `settingKey` = 'attend.reports.visibleTo';

INSERT INTO `tblMigrations` (`filename`) VALUES ('271_attendance_reports_permission.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
