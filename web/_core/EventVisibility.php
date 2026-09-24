<?php
// Path: _core/EventVisibility.php
/**
 * -----------------------------------------------------------------------------
 * Who may see an event — the one written-down rule 👁️ (#514)
 * -----------------------------------------------------------------------------
 * This class answers one question, for every page, feed and scheduled job
 * that shows events: "may this person see this event, and in how much
 * detail?" It does not run the queries itself. It hands back a piece of
 * SQL (with its values to bind) that the caller adds to its OWN query, so
 * the answer is worked out by the database, row by row, inside the very
 * statement that fetches the events.
 *
 * WHY ONE RULE, WRITTEN ONCE
 * --------------------------
 * Before #514 each page wrote its own test — usually `isPublic = 1`, and
 * sometimes "is somebody signed in". Several pages got it wrong in
 * different ways (a member of one organisation reading another's internal
 * events, #534; a signed-out visitor downloading an internal series, #544).
 * #514 also adds events copied in from outside calendars, with four levels
 * (public, members, selected groups, hidden) and a detail level. Written
 * out by hand on twenty pages, one slip anywhere would be a leak. So the
 * rule lives here, once, and the pages use it (from part P2 of #514
 * onwards; in this part nothing calls it yet except the self-test).
 *
 * THE RULE, IN WORDS
 * ------------------
 * The portal's OWN events (externalFeedID IS NULL) have two levels, read
 * from the existing `isPublic` column: public, or members of the event's
 * own organisation. A second column for them was considered and rejected:
 * seven pages write `isPublic` today — calendar/manage/save.php,
 * calendar/submit-save.php, calendar/manage/import.php,
 * calendar/manage/series-edit.php, admin/calendar/moderate.php,
 * events/api/create.php and events/api/update.php — and so does the import
 * job, cron/import-feeds.php (found with grep on 21 September 2026; an
 * earlier version of this comment said "five", which was too low). Two
 * columns that disagreed silently would be worse than one.
 *
 * Events COPIED IN from an outside calendar carry `importLevel` (public,
 * members, groups or hidden), written only by the part of #514 that works
 * the answer out. They are shown only while their calendar still exists
 * AND is switched on — tested live in every query, so pausing a calendar
 * hides every one of its events at once, and a copy of that flag that
 * could go stale was rejected. The stored answer also carries an expiry
 * (`importRecheckAt`, a UTC moment); past it, only administrators see the
 * event until the answer is worked out again. That fails closed.
 *
 * "Member" is ONE test, the same everywhere: an active account with an
 * ACTIVE membership row for the event's own organisation. No row means no,
 * on a portal with one organisation or several. An earlier draft treated
 * "no row" as "member" on a single-organisation portal; the owner removed
 * that exception from the whole portal on 20 September 2026 (#533), and
 * this class must not bring it back.
 *
 * "Administrator" means a global administrator (`isRootAdmin`) or an active
 * site administrator of the EVENT'S organisation, with an active account.
 * The older portal-wide `isAdmin` flag counts for nothing here: it says
 * nothing about which organisation somebody belongs to.
 *
 * MODES
 * -----
 * The caller names a MODE, which says who is looking and which parts of
 * the rule apply (the #514 plan, section 1.2). The SQL text depends ONLY
 * on the mode and the alias — never on the event, the viewer or how many
 * organisations the portal has. So a refused event and a missing one cost
 * the database exactly the same statements (the #503 lesson: a difference
 * in work is itself a way to tell them apart).
 *
 * API KEYS ("key" mode) — the owner's decision of 24 September 2026. A key
 * is treated as a member of the public, never as a member of the
 * organisation. For an event COPIED IN from an outside calendar it gets
 * exactly what a signed-out visitor gets, in the same detail — unless the
 * calendar, or a choice or rule covering the event, has "Don't show via
 * API" ticked (the calendar's box is tested live; the rest arrive through
 * the stored `importApiOptOut`). The "Also show on the public website" box
 * no longer matters to keys: it controls only the countdown widget and the
 * sitemap ("website" mode). The organisation's OWN events are a different
 * matter and are unchanged: a key receives every published one, members-only
 * ones included, as before #514 (tracked in #127 and #511). Until
 * 24 September 2026 a key received an imported event only when it was
 * public AND ticked for the website, with full detail only on a Public
 * calendar (owner answer 3 of 17 September); the owner replaced that.
 *
 * HOW THE NUMBER OF PLACEHOLDERS IS KEPT RIGHT
 * -------------------------------------------
 * Every piece of SQL is built through `add()`, which refuses (with a
 * LogicException) unless the number of `?` marks, the length of the types
 * string and the number of values all agree. No audit script can do this
 * for us: `check_bind_param_arity.py` compares only a types string written
 * out literally with the arguments beside it, never counts the `?` marks,
 * and skips a types string built in a variable, which is exactly how the
 * callers of this class will bind. Without `add()` a miscount would only
 * show up as a wrong answer — or a leak — at run time.
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------
 * - It cannot pull back a copy a browser, a calendar app or a search engine
 *   already took before an event was narrowed.
 * - It does not stop a caller from forgetting to use it. That is what the
 *   audit check added in part P3 of #514 is for.
 * - It reads nothing itself: no session, no organisation, no settings —
 *   except `sessionViewerId()`, which exists only so pages do not each
 *   write their own "who is signed in" line. Every other method takes
 *   every input as an argument, so the self-test can load this file with
 *   no start-up code at all.
 * - A row at the "groups" level makes the database do slightly more work
 *   than a public row in the same statement. It is not a separate query;
 *   the difference in time is accepted (#514 plan, section 1.3).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class EventVisibility
{
    // =========================================================================
    // 🎛️ Modes — the only switches the rule accepts
    // =========================================================================

    /** A page or API call by a signed-in person, or by nobody. */
    public const MODE_SESSION = 'session';
    /** A page that never uses a session (`/e/{slug}`, the "all upcoming" download). */
    public const MODE_ANONYMOUS = 'anonymous';
    /** Feeds meant for the organisation's public website (countdown, sitemap). */
    public const MODE_WEBSITE = 'website';
    /** A personal calendar feed, reminder emails and push: the holder, never administrator powers. */
    public const MODE_TOKEN = 'token';
    /** The invitation link page: the organisation's own events are not restricted there (owner decision, 14 September 2026). */
    public const MODE_INVITE = 'invite';
    /** API key requests: a key is not a person. */
    public const MODE_KEY = 'key';
    /** The newsletter's "upcoming events" block, sent to active members. */
    public const MODE_BULK_MEMBERS = 'bulkMembers';

    /**
     * What each mode switches on.
     *
     *   admin   — whether the two administrator branches apply. Off in
     *             "token" mode on purpose (#514 leak-hunt finding 24): a
     *             personal feed or a reminder email must never carry the
     *             administrator's hidden events out of the portal.
     *   viewer  — 'person': the viewer is a real account (or 0 for nobody).
     *             'nobody': the viewer is always 0, whatever the caller
     *             passes, so a caller that passes a session user into a
     *             public-website mode by mistake gets the narrower answer,
     *             never a wider one.
     */
    private const MODES = [
        self::MODE_SESSION      => ['admin' => true,  'viewer' => 'person'],
        self::MODE_ANONYMOUS    => ['admin' => false, 'viewer' => 'nobody'],
        self::MODE_WEBSITE      => ['admin' => false, 'viewer' => 'nobody'],
        self::MODE_TOKEN        => ['admin' => false, 'viewer' => 'person'],
        self::MODE_INVITE       => ['admin' => true,  'viewer' => 'person'],
        self::MODE_KEY          => ['admin' => false, 'viewer' => 'nobody'],
        self::MODE_BULK_MEMBERS => ['admin' => false, 'viewer' => 'nobody'],
    ];

    /**
     * Columns emptied when a viewer may see only "title, date and time"
     * (#514 plan, section 1.4). The category, the series label and the
     * times stay: see the plan's owner question 8.
     */
    public const REDACTED_COLUMNS = ['description', 'locationName', 'locationAddress', 'locationWebURL', 'locationGeoLat',
        'locationGeoLng', 'locationW3W', 'locationPhone', 'locationEmail', 'hostOrgName', 'partnerOrgs', 'heroImage',
        'posterImage', 'profileImage', 'externalUrl', 'venueID', 'roomID'];

    /**
     * Short table names used INSIDE the rule's own sub-queries, plus the
     * ones inside the fragments `Roles`, `UserGroups` and `Departments`
     * hand back (which part P10 of #514 will put inside the rule).
     *
     * A caller's event alias, or the table part of a viewer column, must
     * never be one of these. If it were, a reference such as `am.siteID`
     * meant for the caller's table would silently bind to the rule's own
     * inner table instead, and a test like `vm.userID = vm.userID` would
     * always be true. Nothing would fail; the rule would just quietly let
     * the wrong people in. So these are refused outright.
     */
    private const RESERVED_ALIASES = ['va', 'vs', 'vb', 'vm', 'ms', 'am', 'sg', 'la', 'lr', 'xf', 'xd',
        'ur16', 'us16', 'g17', 'ug17', 'us17', 'd17', 'ud17'];

    /**
     * The repeated pieces of the rule, each with the ORDER of the values it
     * binds, written separately from its SQL text.
     *
     * In the SQL: {E} is the event alias, {V} the viewer (a `?` bound as a
     * whole number, or a column name), {T} today's date (a `?` bound as
     * text), {OT}/{OI} whose audience list to read.
     * In `binds`: one letter per bound value, in the order the `?` marks
     * appear — V for the viewer, T for today. A viewer given as a column
     * binds nothing, so its V letters add no value.
     *
     * The letters are written by hand, NOT counted from the SQL, on
     * purpose: `add()` then compares the two, so adding a `{V}` to the SQL
     * without adding a letter here fails loudly instead of binding the
     * wrong values.
     *
     * Why a private STATIC property and not a constant: only so that
     * `tools/event-visibility-selftest.php` can plant a wrong letter count
     * through reflection and prove that `where()` really does refuse it.
     * Nothing in this class ever writes to it.
     *
     * The role, user_group and department branches are `(0 = 1)`, matching
     * nobody, until part P10 of #514 puts the fragments from #516 and #517
     * there (`Roles::holdsSql()`, `UserGroups::memberSql()`,
     * `Departments::memberSql()`), each adding one V.
     *
     * @var array<string, array{sql: string, binds: string}>
     */
    private static array $pieces = [
        // 🛡️ ADMIN(V): a global administrator, or an active site administrator
        //    of the event's own organisation; the account itself must be active.
        'admin' => [
            'sql' => '( EXISTS (SELECT 1 FROM tblUsers va WHERE va.userID = {V} AND va.isActive = 1 AND va.isRootAdmin = 1)'
                . ' OR EXISTS (SELECT 1 FROM tblUserSites vs JOIN tblUsers vb ON vb.userID = vs.userID AND vb.isActive = 1'
                . ' WHERE vs.userID = {V} AND vs.siteID = {E}.siteID AND vs.isActive = 1'
                . ' AND (vs.isSiteAdmin = 1 OR vs.isSiteRootAdmin = 1)) )',
            'binds' => 'VV',
        ],
        // 👥 MEMBER(V): an active account with an ACTIVE membership row for the
        //    event's own organisation. The same test, in meaning, as
        //    calendar/anon-checkin.php, calendar/event-register.php and
        //    Events::promoteFromWaitlist() use — not the same text: those
        //    bind the organisation as a value, this correlates it on the
        //    event's own column.
        'member' => [
            'sql' => 'EXISTS (SELECT 1 FROM tblUsers vm WHERE vm.userID = {V} AND vm.isActive = 1'
                . ' AND EXISTS (SELECT 1 FROM tblUserSites ms WHERE ms.userID = vm.userID AND ms.siteID = {E}.siteID AND ms.isActive = 1))',
            'binds' => 'V',
        ],
        // 📋 IN_AUDIENCE(V, OT, OI): the viewer is on the owner's list —
        //    named, or through a small group or a current leadership role of
        //    the SAME organisation. Every reference is checked against the
        //    event's organisation here as well as when the list was saved,
        //    and a group or role that has been switched off or deleted
        //    matches nobody (fails closed).
        //
        //    tblSmallGroupMembers is written out in full, with no short name,
        //    on purpose. The #514 plan wrote `FROM tblSmallGroupMembers sgm`;
        //    tools/audit-checks/check_sql_columns.py reads the "Group" inside
        //    that name as the start of a GROUP BY and WRONGLY REPORTS an
        //    unknown table "tblSmallGroup" (seen on this file while building
        //    part P1; the same trap and the same way round it as
        //    SmallGroups::listGroups()). The meaning is unchanged.
        'audience' => [
            'sql' => 'EXISTS (SELECT 1 FROM tblExternalAudienceMembers am'
                . ' WHERE am.ownerType = {OT} AND am.ownerID = {OI} AND am.siteID = {E}.siteID AND ('
                . ' (am.kind = \'person\' AND am.userID = {V})'
                . ' OR (am.kind = \'small_group\' AND EXISTS (SELECT 1 FROM tblSmallGroupMembers'
                . ' JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.siteID = {E}.siteID AND sg.isActive = 1'
                . ' WHERE tblSmallGroupMembers.groupID = am.refID AND tblSmallGroupMembers.userID = {V}'
                . ' AND tblSmallGroupMembers.siteID = {E}.siteID AND tblSmallGroupMembers.status = \'active\'))'
                . ' OR (am.kind = \'leadership_role\' AND EXISTS (SELECT 1 FROM tblLeadershipAssignments la'
                . ' JOIN tblLeadershipRoles lr ON lr.roleID = la.roleID AND lr.siteID = {E}.siteID AND lr.isActive = 1'
                . ' WHERE la.roleID = am.refID AND la.userID = {V} AND la.siteID = {E}.siteID AND la.isActive = 1'
                . ' AND (la.startDate IS NULL OR la.startDate <= {T}) AND (la.endDate IS NULL OR la.endDate >= {T})))'
                . ' OR (am.kind = \'role\' AND (0 = 1))'
                . ' OR (am.kind = \'user_group\' AND (0 = 1))'
                . ' OR (am.kind = \'department\' AND (0 = 1))'
                . ' ))',
            'binds' => 'VVVTT',
        ],
    ];

    // =========================================================================
    // 🔎 Public builders
    // =========================================================================

    /**
     * The condition to add to a query's WHERE, with the viewer bound as a
     * number. The SQL begins with " AND ", so it goes straight after the
     * caller's own conditions. (A caller that joins its conditions with
     * implode(' AND ', …) must drop that leading " AND " first.)
     *
     * Add it LAST, after the caller's own literal conditions, and append
     * its `types` and `params` to the caller's in the same position: binding
     * is by position. `tools/audit-checks/check_sql_columns.py` reads only
     * literal SQL text, so the caller's own column names stay first where
     * it can read them; this fragment's column names are checked by the
     * self-test, which prepares every mode against the real schema.
     *
     * In "key" and "bulkMembers" modes the fragment binds NO values: `types`
     * is '' and `params` is []. PHP refuses `bind_param('')` with a
     * ValueError, so a caller whose whole statement ends up with no values
     * must skip `bind_param()` rather than call it with an empty string.
     *
     * @param string $alias    The caller's short name for tblEvents, e.g. 'e'.
     * @param string $mode     One of the MODE_* constants.
     * @param int    $viewerId The account number, or 0 for nobody. Ignored
     *                         (0 is used) in the modes whose viewer is
     *                         always nobody.
     * @param string $today    Today's date as Y-m-d, from PHP date() (used
     *                         for leadership terms; the same "today" as
     *                         leadership/index.php).
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     *
     * @throws \InvalidArgumentException For an unknown mode, a bad alias or a bad date.
     */
    public static function where(string $alias, string $mode, int $viewerId, string $today): array
    {
        $spec = self::modeSpec($mode);
        $viewer = self::boundViewer($spec['viewer'] === 'person' ? $viewerId : 0);

        return self::buildWhere(self::checkAlias($alias), $mode, $viewer, self::checkToday($today));
    }

    /**
     * The same condition as `where()`, but with the viewer taken from a
     * column of the caller's own query — for example `r.userID` in a
     * reminder job that checks every recipient in one statement.
     *
     * Only the modes whose viewer is a real person accept a column
     * (session, token, invite). The others always look as nobody, so a
     * column there would be a caller's mistake, and is refused.
     *
     * @param string $alias        The caller's short name for tblEvents.
     * @param string $viewerColumn A plain `alias.column` such as 'r.userID'.
     * @param string $mode         One of the MODE_* constants.
     * @param string $today        Today's date as Y-m-d.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     *
     * @throws \InvalidArgumentException For an unknown mode, a mode without a
     *                                   person viewer, or a bad alias, column or date.
     */
    public static function whereForColumn(string $alias, string $viewerColumn, string $mode, string $today): array
    {
        self::requirePersonMode($mode);

        return self::buildWhere(self::checkAlias($alias), $mode, self::columnViewer($viewerColumn), self::checkToday($today));
    }

    /**
     * A column to SELECT beside each row: 1 when the viewer may see the
     * event's full details, 0 when only its title, date and time. Returned
     * as `CASE … END AS <name>` with no leading comma.
     *
     * Its `?` marks sit in the SELECT list, which comes BEFORE the WHERE in
     * the statement text, so its values must be bound before the WHERE's.
     *
     * The value comes back from the database as the number 1 or 0 (never
     * true/false, and never the text '1'): test it with
     * `(int) $row['canSeeFull'] === 1` before passing it to `redact()`.
     *
     * In "key" mode it binds NO values (`types` '' and `params` []), and
     * key mode's `where()` binds none either. PHP refuses `bind_param('')`,
     * so skip `bind_param()` when the whole statement has no values.
     *
     * @param string $alias    The caller's short name for tblEvents.
     * @param string $mode     One of the MODE_* constants.
     * @param int    $viewerId The account number, or 0.
     * @param string $today    Today's date as Y-m-d.
     * @param string $as       The column name to give it.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     *
     * @throws \InvalidArgumentException For an unknown mode or bad arguments.
     */
    public static function fullDetailSelect(string $alias, string $mode, int $viewerId, string $today, string $as = 'canSeeFull'): array
    {
        $spec = self::modeSpec($mode);
        $viewer = self::boundViewer($spec['viewer'] === 'person' ? $viewerId : 0);

        return self::buildFullDetail(self::checkAlias($alias), $mode, $viewer, self::checkToday($today), self::checkAs($as));
    }

    /**
     * `fullDetailSelect()` with the viewer taken from a column (see
     * `whereForColumn()` for which modes accept one).
     *
     * @param string $alias        The caller's short name for tblEvents.
     * @param string $viewerColumn A plain `alias.column` such as 'r.userID'.
     * @param string $mode         One of the MODE_* constants.
     * @param string $today        Today's date as Y-m-d.
     * @param string $as           The column name to give it.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     *
     * @throws \InvalidArgumentException For an unknown mode or bad arguments.
     */
    public static function fullDetailSelectForColumn(string $alias, string $viewerColumn, string $mode, string $today, string $as = 'canSeeFull'): array
    {
        self::requirePersonMode($mode);

        return self::buildFullDetail(self::checkAlias($alias), $mode, self::columnViewer($viewerColumn), self::checkToday($today), self::checkAs($as));
    }

    /**
     * What `isPublic` MEANS for each row, to SELECT in place of the raw
     * column wherever an event's `isPublic` is handed out (the events API).
     * Returned as `CASE … END AS <name>` with no leading comma, like
     * `fullDetailSelect()`.
     *
     * WHY THE RAW COLUMN WILL NOT DO (#514 part P7, challenge finding 4). On
     * the portal's own events `isPublic` is the event's own setting, and it
     * passes through unchanged in every mode. On an event COPIED IN from an
     * outside calendar the column means nothing: the importer writes 0 on
     * every row (who may see it is `importLevel`), while the old #327 job
     * wrote 1. Handing the raw column out would turn every imported event's
     * `isPublic` from 1 to 0 on the first refresh after an upgrade, and a
     * website filtering on `isPublic = 1` — a natural filter, because a key
     * also receives the organisation's own members-only events, which carry
     * 0 — would silently lose every imported event. So, for an imported row:
     *   - "key" mode: always 1. Every imported row a key receives is one a
     *     signed-out visitor can see (plan B4), so 1 is the truth;
     *   - every other mode: 1 exactly when a signed-out visitor could see it
     *     NOW — public, and its stored answer not run out. The live calendar
     *     test is not repeated, because every imported row any mode returns
     *     has already passed it in the WHERE.
     *
     * Rejected: keeping the stored value and telling integrators not to
     * filter on it. Every existing integration's `isPublic` would change on
     * upgrade — the surprise the owner asked to avoid.
     *
     * It binds nothing in any mode, and its text depends on the mode alone,
     * so a refused event and a missing one still cost the same statements
     * (the #503 rule).
     *
     * @param string $alias The caller's short name for tblEvents.
     * @param string $mode  One of the MODE_* constants.
     * @param string $as    The column name to give it.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     *
     * @throws \InvalidArgumentException For an unknown mode, a bad alias or a bad name.
     */
    public static function isPublicSelect(string $alias, string $mode, string $as = 'isPublic'): array
    {
        self::modeSpec($mode);
        $e  = self::checkAlias($alias);
        $as = self::checkAs($as);

        if ($mode === self::MODE_KEY) {
            return self::seq(['CASE WHEN ', $e, '.externalFeedID IS NULL THEN ', $e, '.isPublic ELSE 1 END AS ', $as]);
        }

        return self::seq([
            'CASE WHEN ', $e, '.externalFeedID IS NULL THEN ', $e, '.isPublic',
            ' WHEN ', $e, ".importLevel = 'public' AND (", $e, '.importRecheckAt IS NULL OR ', $e,
            '.importRecheckAt > UTC_TIMESTAMP()) THEN 1',
            ' ELSE 0 END AS ', $as,
        ]);
    }

    /**
     * Empty every detail column a "title, date and time only" viewer may
     * not see, and say whether that happened.
     *
     * Sets each key of REDACTED_COLUMNS that is PRESENT in the row to null
     * (a key the caller never selected is not added), and always adds
     * `detailsLimited` (true when cut, false otherwise).
     *
     * WHAT THIS CANNOT DO: it only empties columns it knows by name. A
     * caller that shows related material (people, links, documents,
     * images) must also skip those queries itself when the viewer may not
     * see full details (#514 plan, section 1.4).
     *
     * @param array<string, mixed> $row        One event row.
     * @param bool                 $canSeeFull From the canSeeFull column.
     *
     * @return array<string, mixed>
     */
    public static function redact(array $row, bool $canSeeFull): array
    {
        if ($canSeeFull === false) {
            foreach (self::REDACTED_COLUMNS as $column) {
                if (array_key_exists($column, $row) === true) {
                    $row[$column] = null;
                }
            }
        }
        $row['detailsLimited'] = ($canSeeFull === false);

        return $row;
    }

    /**
     * The #514 administrator gate for a given organisation: a global
     * administrator, or an active site administrator of THAT organisation,
     * with an active account. The same meaning as the ADMIN branch of the
     * rule, for pages that need a yes or no before they start.
     *
     * The older portal-wide `isAdmin` flag counts for nothing here, on
     * purpose (#514 leak-hunt finding 3): it says nothing about which
     * organisation somebody belongs to. That is why this is not simply
     * `App::isAdmin()`.
     *
     * @param \mysqli $db     The connection.
     * @param int     $userId The account number (0 answers no).
     * @param int     $siteId The organisation.
     *
     * @return bool
     */
    public static function isAdminOfSite(\mysqli $db, int $userId, int $siteId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM tblUsers u WHERE u.userID = ? AND u.isActive = 1 AND (u.isRootAdmin = 1 OR EXISTS'
            . ' (SELECT 1 FROM tblUserSites s WHERE s.userID = u.userID AND s.siteID = ? AND s.isActive = 1'
            . ' AND (s.isSiteAdmin = 1 OR s.isSiteRootAdmin = 1)))'
        );
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found !== null;
    }

    /**
     * The signed-in account number, or 0 for nobody. The ONLY method in
     * this class that reads anything outside its own arguments; it exists so
     * each page does not write its own copy of this line. Nothing else here
     * reads the session, the organisation or the settings.
     *
     * @return int
     */
    public static function sessionViewerId(): int
    {
        return Auth::check() === true ? (int) $_SESSION['user_id'] : 0;
    }

    // =========================================================================
    // 🧱 Building the rule
    // =========================================================================

    /**
     * WHERE: AND ( MANUAL_OK(V) OR IMPORTED_OK(V) ), per mode (#514 plan,
     * section 1.3). Every value is bound in the order its `?` appears.
     *
     * @param string                                                  $e      Checked alias.
     * @param string                                                  $mode   Checked mode.
     * @param array{expr: string, type: string, params: list<int>}   $viewer The viewer.
     * @param string                                                  $today  Checked date.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     */
    private static function buildWhere(string $e, string $mode, array $viewer, string $today): array
    {
        $admin = self::admin($e, $mode, $viewer);
        $member = self::piece('member', $e, $viewer, $today);

        // 🏠 The portal's own events. "invite", "key" and "bulkMembers" do not
        //    restrict them at all: the invitation page's owner decision of
        //    14 September 2026, today's API key behaviour (#127, #511), and
        //    the newsletter going only to active members.
        if (in_array($mode, [self::MODE_INVITE, self::MODE_KEY, self::MODE_BULK_MEMBERS], true) === true) {
            $manual = self::seq(['( ', $e, '.externalFeedID IS NULL )']);
        } else {
            $manual = self::seq(['( ', $e, '.externalFeedID IS NULL AND ( ', $e, '.isPublic = 1 OR ', $admin, ' OR ', $member, ' ) )']);
        }

        // ⏳ The stored answer has not run out. UTC_TIMESTAMP(), because
        //    importRecheckAt is a moment in UTC, not an event time.
        $fresh = $e . '.importRecheckAt IS NULL OR ' . $e . '.importRecheckAt > UTC_TIMESTAMP()';

        if ($mode === self::MODE_WEBSITE) {
            // 🌐 The public website (countdown widget, sitemap): only events
            //    marked public AND ticked for the website (owner decision D4).
            //    The "Don't show via API" box plays no part here: it is about
            //    API keys only, so an event can be on the website and not in
            //    the API, or the other way round.
            $level = self::seq(['( (', $fresh, ') AND ', $e, ".importLevel = 'public' AND ", $e, '.importWebsite = 1 )']);
        } elseif ($mode === self::MODE_KEY) {
            // 🔑 API keys (owner, 24 September 2026): exactly the signed-out
            //    visitor's imported events — public, stored answer still fresh
            //    — minus anything opted out of the API. The visitor's branch
            //    below also lets a MEMBER see members and groups events; a key
            //    is never a member, so those branches are left out rather than
            //    bound with the viewer 0 (they could never be true, and would
            //    turn this no-values mode into one that binds seven). The
            //    website box is NOT tested: it now controls the website only.
            //    `importApiOptOut` carries the choices' and rules' boxes; the
            //    calendar's own box is ALSO tested live, below.
            $level = self::seq(['( (', $fresh, ') AND ', $e, ".importLevel = 'public' AND ", $e, '.importApiOptOut = 0 )']);
        } elseif ($mode === self::MODE_BULK_MEMBERS) {
            // 📰 The newsletter goes to active members: public and members
            //    events only, never selected groups or hidden.
            $level = self::seq(['( (', $fresh, ') AND ', $e, ".importLevel IN ('public','members') )"]);
        } else {
            $audience = self::piece('audience', $e, $viewer, $today, [
                '{OT}' => $e . '.importAudienceType',
                '{OI}' => $e . '.importAudienceID',
            ]);
            $level = self::seq([
                '( ', $admin, ' OR ( (', $fresh, ') AND ( ',
                $e, ".importLevel = 'public'",
                ' OR (', $e, ".importLevel = 'members' AND ", $member, ')',
                ' OR (', $e, ".importLevel = 'groups' AND ", $member, ' AND ', $audience, ')',
                ' ) ) )',
            ]);
        }

        // 🔄 A copied-in event is shown only while its calendar still exists,
        //    belongs to the same organisation, and is switched on. Tested
        //    live, so pausing a calendar hides all its events at once, and a
        //    calendar row that no longer exists hides them from everybody.
        //
        //    In key mode the calendar's "Don't show via API" box is tested
        //    here too, live, as well as through the stored answer — belt and
        //    braces, the same reasoning as pausing: ticking the box stops keys
        //    in the very next statement, even for a row whose stored answer
        //    was written before it was ticked, and even if some later writer
        //    changes the box without working the answers out again. The two
        //    are ANDed, so the answer is the narrower. The text still depends
        //    only on the mode (the #503 rule).
        $apiTest = ($mode === self::MODE_KEY) ? ' AND xf.apiOptOut = 0' : '';
        $imported = self::seq([
            '( ', $e, '.externalFeedID IS NOT NULL',
            ' AND EXISTS (SELECT 1 FROM tblExternalFeeds xf WHERE xf.feedID = ', $e, '.externalFeedID AND xf.siteID = ', $e, '.siteID AND xf.isActive = 1', $apiTest, ')',
            ' AND ', $level, ' )',
        ]);

        return self::seq([' AND ( ', $manual, ' OR ', $imported, ' )']);
    }

    /**
     * canSeeFull (#514 plan, section 1.3). A private-marked event skips the
     * calendar-audience shortcut: only an administrator, or a choice that
     * set full detail on purpose, shows it in full (section 1.6). API keys
     * get the signed-out visitor's answer, written out so it binds nothing
     * (owner, 24 September 2026; see inside).
     *
     * @param string                                                $e      Checked alias.
     * @param string                                                $mode   Checked mode.
     * @param array{expr: string, type: string, params: list<int>} $viewer The viewer.
     * @param string                                                $today  Checked date.
     * @param string                                                $as     Checked column name.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     */
    private static function buildFullDetail(string $e, string $mode, array $viewer, string $today, string $as): array
    {
        if ($mode === self::MODE_KEY) {
            // 🔑 API keys: EXACTLY the detail a signed-out visitor gets (the
            //    owner's decision of 24 September 2026), never more.
            //
            //    Lines 4 to 7 below are the general CASE further down, as it
            //    works out for a signed-out visitor: the administrator line is
            //    "(0 = 1)" in that mode, and the members and groups branches
            //    need MEMBER(nobody), which no real row satisfies — so only
            //    the "public" branch is kept. Written out rather than calling
            //    the general CASE with the viewer 0, because that would bind
            //    six values here for branches that can never be true for a
            //    key, and this mode binds none (both API handlers rely on
            //    that only in comments).
            //
            //    Lines 2 and 3 can only ever answer 0, and keep this answer
            //    safe ON ITS OWN for a row the WHERE would refuse anyway: an
            //    event opted out of the API (line 2, new on 24 September), and
            //    an event whose calendar belongs to another organisation (line
            //    3, `xd.siteID = <event>.siteID`, from part P1's check). For
            //    any row, then, a key's answer is never above a visitor's; on
            //    every row a key actually RECEIVES, it is equal (plan B4).
            //
            //    WHAT THIS REPLACED, so nobody puts it back: until
            //    24 September 2026 a key needed the website box ticked (in the
            //    WHERE) and got full detail only on a PUBLIC calendar (owner
            //    answer 3 of 17 September). The owner replaced that with
            //    "exactly what a signed-out visitor sees", plus the "Don't show
            //    via API" box. Part P1's own correction still stands inside
            //    the new rule: a private-marked event at basic detail on a
            //    Public calendar stays title-only (line 5), because a visitor
            //    to the organisation's own website gets no more.
            return self::seq([
                'CASE',
                ' WHEN ', $e, '.externalFeedID IS NULL THEN 1',
                ' WHEN ', $e, '.importApiOptOut = 1 THEN 0',
                ' WHEN NOT EXISTS (SELECT 1 FROM tblExternalFeeds xd WHERE xd.feedID = ', $e, '.externalFeedID',
                ' AND xd.siteID = ', $e, '.siteID) THEN 0',
                ' WHEN ', $e, ".importDetail = 'full' THEN 1",
                ' WHEN ', $e, '.externalPrivate = 1 THEN 0',
                ' WHEN EXISTS (SELECT 1 FROM tblExternalFeeds xd WHERE xd.feedID = ', $e, '.externalFeedID',
                ' AND xd.siteID = ', $e, ".siteID AND xd.audienceLevel = 'public') THEN 1",
                ' ELSE 0 END AS ', $as,
            ]);
        }

        $admin = self::admin($e, $mode, $viewer);
        $member = self::piece('member', $e, $viewer, $today);
        $audience = self::piece('audience', $e, $viewer, $today, ['{OT}' => "'feed'", '{OI}' => 'xd.feedID']);

        return self::seq([
            'CASE',
            ' WHEN ', $e, '.externalFeedID IS NULL THEN 1',
            ' WHEN ', $admin, ' THEN 1',
            ' WHEN ', $e, ".importDetail = 'full' THEN 1",
            ' WHEN ', $e, '.externalPrivate = 1 THEN 0',
            ' WHEN EXISTS (SELECT 1 FROM tblExternalFeeds xd WHERE xd.feedID = ', $e, '.externalFeedID AND (',
            " xd.audienceLevel = 'public'",
            " OR (xd.audienceLevel = 'members' AND ", $member, ')',
            " OR (xd.audienceLevel = 'groups' AND ", $member, ' AND ', $audience, ') )) THEN 1',
            ' ELSE 0 END AS ', $as,
        ]);
    }

    /**
     * ADMIN(V), or `(0 = 1)` with nothing bound in the modes that switch the
     * administrator branches off.
     *
     * @param string                                                $e      Checked alias.
     * @param string                                                $mode   Checked mode.
     * @param array{expr: string, type: string, params: list<int>} $viewer The viewer.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     */
    private static function admin(string $e, string $mode, array $viewer): array
    {
        if (self::MODES[$mode]['admin'] === false) {
            return self::add('(0 = 1)', '', []);
        }

        return self::piece('admin', $e, $viewer, '');
    }

    /**
     * Fill in one of the repeated pieces and work out its bound values from
     * its hand-written `binds` letters, then let `add()` check the two agree.
     *
     * @param string                                                $name   Key of $pieces.
     * @param string                                                $e      Checked alias.
     * @param array{expr: string, type: string, params: list<int>} $viewer The viewer.
     * @param string                                                $today  Checked date (unused when the piece has no T).
     * @param array<string, string>                                 $extra  Further {…} replacements.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     */
    private static function piece(string $name, string $e, array $viewer, string $today, array $extra = []): array
    {
        $template = self::$pieces[$name];
        $sql = strtr($template['sql'], ['{E}' => $e, '{V}' => $viewer['expr'], '{T}' => '?'] + $extra);

        $types = '';
        $params = [];
        foreach (str_split($template['binds']) as $letter) {
            if ($letter === 'V') {
                $types .= $viewer['type'];
                foreach ($viewer['params'] as $value) {
                    $params[] = $value;
                }
            } elseif ($letter === 'T') {
                $types .= 's';
                $params[] = $today;
            } else {
                throw new \LogicException("EventVisibility: unknown bind letter '{$letter}' in piece '{$name}'.");
            }
        }

        return self::add($sql, $types, $params);
    }

    /**
     * Join literal SQL text and already-built pieces, in order, and check
     * the whole again. A literal holding a `?` of its own would make the
     * count disagree, so it is caught here too.
     *
     * @param list<string|array{sql: string, types: string, params: list<int|string>}> $parts
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     */
    private static function seq(array $parts): array
    {
        $sql = '';
        $types = '';
        $params = [];
        foreach ($parts as $part) {
            if (is_string($part) === true) {
                $sql .= $part;
                continue;
            }
            $sql .= $part['sql'];
            $types .= $part['types'];
            foreach ($part['params'] as $value) {
                $params[] = $value;
            }
        }

        return self::add($sql, $types, $params);
    }

    /**
     * The one place a piece of SQL is accepted: the number of `?` marks, the
     * length of the types string and the number of values must all agree.
     *
     * @param string           $piece  SQL text.
     * @param string           $types  One mysqli type letter per `?`.
     * @param list<int|string> $params One value per `?`, in order.
     *
     * @return array{sql: string, types: string, params: list<int|string>}
     *
     * @throws \LogicException When they do not agree.
     */
    private static function add(string $piece, string $types, array $params): array
    {
        $marks = substr_count($piece, '?');
        if ($marks !== strlen($types) || strlen($types) !== count($params)) {
            throw new \LogicException(
                'EventVisibility: ' . $marks . ' placeholder(s), ' . strlen($types) . ' type letter(s) and '
                . count($params) . ' value(s) do not agree.'
            );
        }

        return ['sql' => $piece, 'types' => $types, 'params' => array_values($params)];
    }

    // =========================================================================
    // 🧪 Checking the caller's arguments
    // =========================================================================

    /**
     * @param string $mode
     *
     * @return array{admin: bool, viewer: string}
     *
     * @throws \InvalidArgumentException For an unknown mode.
     */
    private static function modeSpec(string $mode): array
    {
        if (array_key_exists($mode, self::MODES) === false) {
            throw new \InvalidArgumentException('EventVisibility: unknown mode: ' . $mode);
        }

        return self::MODES[$mode];
    }

    /**
     * @param string $mode
     *
     * @throws \InvalidArgumentException When the mode's viewer is always nobody.
     */
    private static function requirePersonMode(string $mode): void
    {
        if (self::modeSpec($mode)['viewer'] !== 'person') {
            throw new \InvalidArgumentException(
                'EventVisibility: mode ' . $mode . ' always looks as nobody, so it takes no viewer column.'
            );
        }
    }

    /**
     * The event alias: lower-case letters and digits, starting with a
     * letter, and not one of the rule's own inner names.
     *
     * @param string $alias
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private static function checkAlias(string $alias): string
    {
        if (preg_match('/^[a-z][a-z0-9]*$/', $alias) !== 1 || in_array($alias, self::RESERVED_ALIASES, true) === true) {
            throw new \InvalidArgumentException('EventVisibility: unusable event alias: ' . $alias);
        }

        return $alias;
    }

    /**
     * A viewer column: a plain `alias.column`, whose alias is not one of the
     * rule's own inner names (see RESERVED_ALIASES for why that matters).
     *
     * @param string $column
     *
     * @return array{expr: string, type: string, params: list<int>}
     *
     * @throws \InvalidArgumentException
     */
    private static function columnViewer(string $column): array
    {
        if (preg_match('/^([a-z][a-z0-9]*)\.[A-Za-z]+$/', $column, $m) !== 1
            || in_array($m[1], self::RESERVED_ALIASES, true) === true) {
            throw new \InvalidArgumentException('EventVisibility: unusable viewer column: ' . $column);
        }

        return ['expr' => $column, 'type' => '', 'params' => []];
    }

    /**
     * A viewer bound as a whole number.
     *
     * @param int $viewerId
     *
     * @return array{expr: string, type: string, params: list<int>}
     */
    private static function boundViewer(int $viewerId): array
    {
        return ['expr' => '?', 'type' => 'i', 'params' => [$viewerId]];
    }

    /**
     * Today's date as Y-m-d, and a real date.
     *
     * @param string $today
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private static function checkToday(string $today): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $today, $m) !== 1
            || checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === false) {
            throw new \InvalidArgumentException('EventVisibility: today must be a real date written Y-m-d, got: ' . $today);
        }

        return $today;
    }

    /**
     * The name for the canSeeFull column.
     *
     * @param string $as
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private static function checkAs(string $as): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $as) !== 1) {
            throw new \InvalidArgumentException('EventVisibility: unusable column name: ' . $as);
        }

        return $as;
    }
}
