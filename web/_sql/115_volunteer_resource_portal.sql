-- =============================================================================
-- Migration 115: Volunteer resource portal (#342)
-- =============================================================================
-- The user-explicit differentiator vs VBS Pro. A logged-in volunteer hits
-- /my-volunteering and sees, for every upcoming event they are on:
--   • Their role on the team (from tblEventPeople OR tblEventCoordinators)
--   • Other team members + their roles
--   • Event-scoped documents (tblDocuments.eventID, shipped in #351)
--   • Briefing notes (allergies / contact info for the people they look
--     after, when their role suggests they need to know)
--
-- v1 is PURE READ-SIDE composition — no new tables. Just the route + the
-- handler that joins tblEventPeople / tblEventCoordinators / tblDocuments /
-- tblEvents on the active user. The notes/briefing data already lives on
-- tblEventPeople.notes (added migration TBD if not already there) — for
-- v1 we surface what's in tblEvents.description as the briefing fallback.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/342
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/my-volunteering', 'account/my-volunteering.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
