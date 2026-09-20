<?php
// Path: _core/AttendanceAccess.php
/**
 * -----------------------------------------------------------------------------
 * Who may see the attendance reports page 📊 (#529)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG BEFORE
 * -------------------------------------------------------------------------
 * `attendance/report.php` asked only `Auth::check()` — is somebody signed
 * in — before showing the organisation's whole attendance history: monthly
 * headcount totals, year-over-year figures, and (if that setting allowed it)
 * the anonymous check-in totals too. It never asked which organisation that
 * signed-in account belonged to, so ANY signed-in account on the WHOLE
 * installation could read ANY organisation's totals, simply by opening that
 * organisation's site and visiting this address. Confirmed on a real
 * database: a signed-in member of organisation B could read organisation
 * A's attendance figures in full.
 *
 * THE FIX, AND WHY IT IS A SETTING
 * -------------------------------------------------------------------------
 * The owner decided to restrict the page (20 September 2026), with the same
 * kind of small, plain, per-organisation choice #525 already used for the
 * anonymous check-in counts — a whole-number decision, not a spectrum,
 * seeded once for the installation and overridable per organisation.
 * Organisations differ: some want attendance figures kept to
 * administrators, some want the people who actually run events to see how
 * their own numbers are trending, and a few may be happy for the whole
 * congregation to see them.
 *
 * THREE choices, and only three — the same shape as #525's
 * `attend.anonCounts.visibleTo`, deliberately, so the two sit together in
 * the settings editor and so #526 (a future four-level settings resolver:
 * installation, organisation, venue, event) can extend both through one
 * small change each, rather than inventing a second vocabulary:
 *
 *   admins               Administrators only. The default, and the
 *                         narrowest — see "WHY `admins` IS THE DEFAULT"
 *                         below.
 *   admins_coordinators  Administrators, plus anyone who currently
 *                         coordinates one of this organisation's events.
 *   members              Any member of this organisation.
 *
 * WHY `admins` IS THE DEFAULT
 * -------------------------------------------------------------------------
 * The owner chose to restrict access, so starting at the narrowest choice is
 * the safe direction — nobody's access is accidentally widened by an
 * upgrade they did not ask for. It also matches #525's own default, and
 * nothing in the existing help pages ever promised ordinary members the
 * attendance totals, so narrowing here breaks no documented promise.
 * Widening it is then a deliberate act by an administrator who knows their
 * own organisation, at `/admin/settings/attendance`.
 *
 * NOT A MEMBER AT ALL: REFUSED BEFORE THE SETTING IS EVEN READ
 * -------------------------------------------------------------------------
 * Whatever the setting says, somebody who is not a member of this
 * organisation (and not a global administrator) is refused — the setting
 * can only decide who AMONG this organisation's own people sees the
 * figures, never open the page to a stranger. `mayView()`'s very first
 * rule enforces this, and `report.php` refuses that case with a 404
 * ("looks exactly like a page that does not exist", the #503 discipline)
 * rather than 403, because a non-member has no business learning the page
 * exists at all. A refused MEMBER (the setting excludes them) gets 403
 * instead — they may know the page exists, they simply may not open it.
 *
 * THE COORDINATOR TEST DELIBERATELY DOES NOT APPLY THE DBS GATE
 * -------------------------------------------------------------------------
 * `Auth::isCoordinatorOf()` (used elsewhere) decides whether a coordinator
 * may EDIT an event, and that decision is deliberately tied to their
 * safeguarding (DBS) check being current — editing an event is an action
 * that touches the event itself. `coordinatesAnyEvent()` below answers a
 * different question: may this person see the organisation's ATTENDANCE
 * TOTALS. A lapsed DBS check has nothing to say about whether somebody may
 * see a headcount, so this class does NOT apply that gate. WHAT THIS MEANS
 * IN PRACTICE, STATED PLAINLY: this cannot tell a coordinator of an event
 * happening next week from a coordinator of one that happened, and ended,
 * two years ago — any non-deleted event they coordinate counts. That is
 * accepted as the cost of a simple, cheap check; narrowing it further is
 * not something this issue asked for.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * -------------------------------------------------------------------------
 * It never reads `$_SESSION`, `$_GET`, `$_POST` or the currently open
 * organisation — every input arrives as a parameter, exactly like
 * `AnonymousCheckins`, so `choice()` and `mayView()` can be, and are,
 * proved by `tools/attendance-access-selftest.php` with no database
 * connection at all. It does not decide who may see the SESSIONS list on
 * `/attendance` (that is `attendance/index.php`'s own, narrower change —
 * see that file) or who may RECORD or DELETE a headcount — those stay
 * `Auth::check()` only, tracked separately (see the #529 closing comment,
 * "bigger than it looks", §9.1), because they are entangled with a
 * different question: who may CHANGE the record, not who may READ a
 * summary of it.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/529
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class AttendanceAccess
{
    /** The setting key — one setting, one reader, shared by the organisation
     *  form and the installation-wide form at /admin/settings/attendance. */
    public const VISIBILITY_KEY = 'attend.reports.visibleTo';

    /** The narrowest choice, and the one anything unrecognised falls back to. */
    public const VISIBLE_ADMINS = 'admins';

    /** Administrators, plus anyone who currently coordinates one of this
     *  organisation's events (see the class docblock for what that cannot
     *  tell apart). */
    public const VISIBLE_ADMINS_COORDINATORS = 'admins_coordinators';

    /** Any member of this organisation — the widest choice. */
    public const VISIBLE_MEMBERS = 'members';

    /**
     * The three choices, in the order they are offered on the settings screen.
     *
     * @var array<string, string> Stored value => the label a person reads.
     */
    public const VISIBILITY_CHOICES = [
        self::VISIBLE_ADMINS              => 'Administrators only',
        self::VISIBLE_ADMINS_COORDINATORS => 'Administrators, and anyone who coordinates one of this organisation\'s events',
        self::VISIBLE_MEMBERS             => 'Any member of this organisation',
    ];

    /**
     * Turn whatever is stored into one of the three choices.
     *
     * Anything unrecognised — a typo, an empty value, a missing row, a
     * hand-edited value that has never been one of the three — comes back
     * as `admins`, the narrowest. Failing closed matters here: the
     * alternative is a mistyped setting quietly showing attendance figures
     * to more people than intended.
     *
     * @param string|null $raw Exactly what was stored, or null if nothing was.
     *
     * @return string One of the keys of VISIBILITY_CHOICES.
     */
    public static function choice(?string $raw): string
    {
        if ($raw === null) {
            return self::VISIBLE_ADMINS;
        }

        $trimmed = trim($raw);
        if (array_key_exists($trimmed, self::VISIBILITY_CHOICES) === true) {
            return $trimmed;
        }

        return self::VISIBLE_ADMINS;
    }

    /**
     * Read the choice for one organisation.
     *
     * 📌 THIS IS THE ONE FUNCTION #526 REPLACES (alongside
     *    `AnonymousCheckins::readVisibilityChoice()`). Issue #526 makes
     *    this setting settable at four levels — installation, organisation,
     *    venue and event — with the most specific one winning. When that
     *    lands, this method grows the extra lookups and every screen
     *    follows, because every screen reads the choice through here and
     *    nowhere else.
     *
     * Today there are two levels only: this organisation's own row if it
     * has one, otherwise the installation-wide row — exactly what
     * `App::settingForSite()` already does.
     *
     * @param int $siteId The organisation to read the setting for.
     *
     * @return string One of the keys of VISIBILITY_CHOICES.
     */
    public static function readChoice(int $siteId): string
    {
        return self::choice(App::settingForSite(self::VISIBILITY_KEY, $siteId));
    }

    /**
     * The whole decision about whether one person may see the attendance
     * reports.
     *
     * Rules, checked in this order, first match wins:
     *
     * 1. Not a member of this organisation at all (and not an
     *    administrator) → false, WHATEVER the setting says. The setting
     *    only ever decides who AMONG this organisation's own people sees
     *    the figures; it can never open the page to a stranger.
     * 2. An administrator → true, always. The narrowest setting
     *    (`admins`) still has to mean SOMETHING can see the page.
     * 3. `members` → true for any member.
     * 4. `admins_coordinators` → true only if this member currently
     *    coordinates at least one of this organisation's events.
     * 5. `admins` and anything unrecognised → false for a non-administrator.
     *
     * @param string $choice               One of the keys of VISIBILITY_CHOICES
     *                                      (or anything — normalised inside).
     * @param bool   $isMember              Is this person a member of the
     *                                      organisation being viewed (or a
     *                                      global administrator)? See
     *                                      viewerIsMember().
     * @param bool   $isAdmin               Is this person an administrator
     *                                      (App::isAdmin())?
     * @param bool   $coordinatesAnyEvent   Does this person currently
     *                                      coordinate at least one of this
     *                                      organisation's events? See
     *                                      coordinatesAnyEvent(). Pass false
     *                                      when the choice is not
     *                                      admins_coordinators — the caller
     *                                      need not run that query at all in
     *                                      that case (see report.php).
     *
     * @return bool True if the attendance reports may be shown.
     */
    public static function mayView(
        string $choice,
        bool $isMember,
        bool $isAdmin,
        bool $coordinatesAnyEvent
    ): bool {
        // Rule 1 — not a member of this organisation at all: refused
        // whatever the setting says. viewerIsMember() already answers true
        // for a global administrator, so this does not need a separate
        // global-administrator branch of its own.
        if ($isMember === false) {
            return false;
        }

        // Rule 2 — administrators always see the figures, whatever the
        // setting says (the narrowest choice still has to mean something).
        if ($isAdmin === true) {
            return true;
        }

        $normalised = self::choice($choice);

        if ($normalised === self::VISIBLE_MEMBERS) {
            return true;
        }

        if ($normalised === self::VISIBLE_ADMINS_COORDINATORS) {
            return $coordinatesAnyEvent === true;
        }

        // self::VISIBLE_ADMINS, and anything unrecognised.
        return false;
    }

    /**
     * Is this person a member of the organisation being viewed, for the
     * purposes of `mayView()`'s first rule?
     *
     * True for an active account holding the portal-wide `isRootAdmin`
     * flag (a global administrator may always open any organisation's
     * reports), or for an active account with an ACTIVE `tblUserSites` row
     * for this organisation. The same predicate `event-register.php` and
     * `anon-checkin.php` use for their own "may this viewer see this
     * organisation's internal event" test — written once here, for this
     * page's own question, rather than shared, because #514 is where the
     * portal's several near-identical copies of this predicate are due to
     * be collapsed into one method; duplicating it here rather than
     * reaching into a calendar-app file keeps this class's own dependencies
     * to `_core` only.
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
            'SELECT 1 FROM tblUsers u '
            . 'WHERE u.userID = ? AND u.isActive = 1 '
            . '  AND ( u.isRootAdmin = 1 '
            . '        OR EXISTS (SELECT 1 FROM tblUserSites ms '
            . '                    WHERE ms.userID = u.userID AND ms.siteID = ? AND ms.isActive = 1) '
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

    /**
     * Does this person currently coordinate at least one of this
     * organisation's events?
     *
     * Deliberately does NOT apply the DBS gate `Auth::isCoordinatorOf()`
     * applies elsewhere — see the class docblock, "THE COORDINATOR TEST
     * DELIBERATELY DOES NOT APPLY THE DBS GATE", for why, and for what this
     * cannot tell apart (a coordinator of an event years in the past counts
     * the same as one coordinating an event next week, as long as the event
     * itself has not been deleted).
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The viewer.
     * @param int    $siteId The organisation.
     *
     * @return bool
     */
    public static function coordinatesAnyEvent(mysqli $db, int $userId, int $siteId): bool
    {
        if ($userId <= 0 || $siteId <= 0) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM tblEventCoordinators c '
            . 'JOIN tblEvents e ON e.eventID = c.eventID '
            . 'WHERE c.userID = ? AND c.revokedAt IS NULL AND e.siteID = ? AND e.isDeleted = 0 LIMIT 1'
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
