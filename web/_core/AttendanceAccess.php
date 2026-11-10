<?php
// Path: _core/AttendanceAccess.php
/**
 * -----------------------------------------------------------------------------
 * Who may see an organisation's attendance figures 📊 (#529, D-106; #917, D-109)
 * -----------------------------------------------------------------------------
 * THE RULE (the owner, 10 November 2026 — D-106)
 * -------------------------------------------------------------------------
 * An organisation's attendance totals — headcounts by month, by service type
 * and by headcount group; totals, never a named person — are seen by:
 *
 *   1. the organisation's ADMINISTRATORS, always; and
 *   2. whoever the organisation points the permission `attendance.reports`
 *      at on Admin, Roles (`Portal\Core\Capabilities`, D-017). It ships
 *      pointing at the standard role "Attendance Coordinator", which nobody
 *      holds until somebody grants it (migration 271).
 *
 * Nobody else. An ordinary member of the organisation, and an account that
 * belongs to ANOTHER organisation, are each shown the portal's ordinary "page
 * not found" — the same answer as an address that does not exist — because
 * neither has any business learning the page is there.
 *
 * `mayViewTotals()` is the ONE place that decides it. The reports page, the
 * attendance spreadsheet download and the attendance page's own "this month"
 * total and "View Reports" card all ask it, so they cannot disagree.
 *
 * AND EACH SESSION'S OWN HEADCOUNT FOLLOWS THE SAME RULE (the owner,
 * 10 November 2026 — D-109, closing #917). The totals are only sums of the
 * sessions, so a member who could read every session's count could rebuild
 * every total by hand; D-109 rules that out. The SAME method therefore decides
 * who sees a session's count anywhere: the Headcount column of the list on
 * `/attendance`, and the form that edits an EXISTING session
 * (`/attendance/record?edit=N` and its `update` action in `record/save.php`).
 * One rule, not a sibling, because "may see the totals" and "may see the
 * figures the totals are made of" cannot sensibly differ.
 *
 * WHO MAY CORRECT A SESSION, AND WHY IT IS NOT "THE PERSON WHO RECORDED IT".
 * Correcting a session means seeing its stored counts, so only administrators
 * and holders of the permission edit an existing session. A member who
 * records attendance still records a new session, and sees only the counts
 * they are typing (and the total of what they just saved, in the message
 * afterwards) — D-109's own words. "The recorder may correct their own
 * session" was considered and not chosen: D-109 says a member sees the count
 * of the session being entered and not past sessions' figures, a session
 * recorded last week is a past session, and its stored figure may have been
 * corrected since by somebody else, so it would show figures the member did not
 * type. A member who mistyped can still DELETE the session (any member may, as
 * before — deleting shows no figure) and record it again, or ask an
 * administrator.
 *
 * WHAT THIS REPLACED, AND WHY IT IS NOT A SETTING ANY MORE
 * -------------------------------------------------------------------------
 * Until 10 November 2026 this class read a three-way setting,
 * `attend.reports.visibleTo` — administrators only (the default),
 * administrators and anybody coordinating any event, or any member. D-106
 * rules out "any member", and D-017 says a new gate is a PERMISSION an
 * organisation points at a role, never a choice hard-wired in code. Migration
 * 271 deletes the setting; a setting nothing reads would be a lie with a Save
 * button (#671). The "coordinates any event" test went with it: it was not a
 * role, so a permission cannot point at it.
 *
 * THE DOWNLOAD IS NO LONGER ADMINISTRATORS-ONLY, ON PURPOSE
 * -------------------------------------------------------------------------
 * `attendance/export.php` used to refuse everybody but an administrator,
 * borrowing the reasoning the owner gave on 18 September 2026 for the
 * ANONYMOUS check-in spreadsheet. D-106 names "its CSV export" explicitly, so
 * the attendance spreadsheet follows this rule now. The anonymous check-in
 * spreadsheet (`export-anonymous.php`) does NOT: it names events, and the
 * owner's own decision about it stands — administrators only.
 *
 * NOT A MEMBER AT ALL: REFUSED BEFORE ANY ROLE IS ASKED
 * -------------------------------------------------------------------------
 * On an installation with several organisations `SiteGate` already refuses a
 * signed-in person who is not a member, on every address, before any page
 * runs (#511). This class asks again, by itself, because it must not depend
 * on a gate that does not run on a single-organisation installation, and
 * because a role held in organisation B must never open organisation A's
 * totals: `Capabilities::allows()` is asked about THIS organisation only.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * -------------------------------------------------------------------------
 * It does not decide who may RECORD a new head count or delete a session —
 * any active member may, and an administrator (the owner's decision). Those
 * pages (`attendance/record.php`, `record/save.php`, `record/delete.php`)
 * call `viewerIsMember()` below to shut outsiders out, and ask
 * `mayViewTotals()` only where an existing session's figures are shown or
 * overwritten.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/529
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class AttendanceAccess
{
    /**
     * The rule itself, with no database: may somebody with these three facts
     * see the attendance totals?
     *
     * Public so `tools/attendance-access-selftest.php` can prove every
     * combination without a connection. `mayViewTotals()` works the facts out
     * and asks THIS, so the two cannot disagree.
     *
     * Checked in this order, first match wins:
     *   1. not a member of the organisation (and not a global administrator) →
     *      false, whatever else is true. A role held elsewhere opens nothing
     *      here.
     *   2. an administrator → true.
     *   3. otherwise → whether they hold the permission `attendance.reports`.
     *
     * @param bool $isMember        viewerIsMember() for this organisation.
     * @param bool $isAdmin         App::isAdmin() for this organisation.
     * @param bool $holdsPermission Capabilities::allows() for ATTENDANCE_REPORTS.
     *
     * @return bool
     */
    public static function decide(bool $isMember, bool $isAdmin, bool $holdsPermission): bool
    {
        if ($isMember === false) {
            return false;
        }
        if ($isAdmin === true) {
            return true;
        }

        return $holdsPermission === true;
    }

    /**
     * May this person see this organisation's attendance figures — the
     * totals (D-106) AND any one session's own headcount (D-109)? The ONE
     * question every page that shows either asks, and the one that decides
     * who may edit an existing session.
     *
     * Read LIVE from the database, so a role taken away, or the permission
     * re-pointed, takes effect on the very next request.
     *
     * @param mysqli $db            An open connection.
     * @param int    $userId        The viewer; 0 (nobody signed in) is always false.
     * @param int    $siteId        The organisation the totals belong to.
     * @param bool   $isAdmin       App::isAdmin(), asked by the caller because
     *                              it reads the signed-in account's own flags.
     * @param bool   $isGlobalAdmin App::isRootAdmin(), handed to
     *                              Capabilities::allows() exactly as App::can()
     *                              hands it.
     *
     * @return bool
     */
    public static function mayViewTotals(mysqli $db, int $userId, int $siteId, bool $isAdmin, bool $isGlobalAdmin): bool
    {
        $isMember = self::viewerIsMember($db, $userId, $siteId);

        // The permission is asked only when the answer depends on it: a
        // non-member is refused and an administrator let in without it.
        $holds = ($isMember === true && $isAdmin === false)
            ? Capabilities::allows($db, $userId, $siteId, Capabilities::ATTENDANCE_REPORTS, $isGlobalAdmin)
            : false;

        return self::decide($isMember, $isAdmin, $holds);
    }

    /**
     * Is this person a member of the organisation being viewed?
     *
     * True for an active account holding the portal-wide `isRootAdmin` flag
     * (a global administrator), or for an active account with an ACTIVE
     * `tblUserSites` row for this organisation. Also used by the three pages
     * that record a head count, to shut outsiders out.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The viewer. 0 (nobody signed in) always returns false.
     * @param int    $siteId The organisation being viewed.
     *
     * @return bool
     */
    public static function viewerIsMember(mysqli $db, int $userId, int $siteId): bool
    {
        if ($userId <= 0 || $siteId <= 0) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM `tblUsers` u '
            . 'WHERE u.`userID` = ? AND u.`isActive` = 1 '
            . '  AND ( u.`isRootAdmin` = 1 '
            . '        OR EXISTS (SELECT 1 FROM `tblUserSites` ms '
            . '                    WHERE ms.`userID` = u.`userID` AND ms.`siteID` = ? AND ms.`isActive` = 1) '
            . '      ) LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $found;
    }
}
