<?php
// Path: public_html/api/events/detail.php
/**
 * -----------------------------------------------------------------------------
 * Events API — Event Detail
 * -----------------------------------------------------------------------------
 * Returns full detail for a single event by ID or slug.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/95
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiKey;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\EventVisibility;
use Portal\Core\GeoLocation;
use Portal\Core\Site;

ApiAuth::requireRead('events:read');

$db     = App::db();
$siteId = Site::id();
$eventId = (int) ($_GET['id'] ?? 0);
$slug    = trim($_GET['slug'] ?? '');

if ($eventId <= 0 && $slug === '') {
    ApiResponse::error('Provide id or slug parameter', 400);
}

// 🛡️ Who may read this event (#503). The same rule as the event's own page
//    (calendar/event.php).
//
// 1. Only an event whose status is published, cancelled or postponed is shown
//    to people in general. Cancelled and postponed stay visible on purpose:
//    somebody who already knows about the event needs to learn it is off.
//
// 2. A DRAFT is shown only to somebody who can manage events. Everybody else
//    gets EXACTLY the same "Event not found" as an id or slug that matches no
//    event, so the reply does not reveal that the draft exists.
//
// "Can manage events" depends on how the request signed in, because this API
// has two ways in and each is checked differently:
//   - Signed in to the portal (a session): App::isAdmin(). That is exactly the
//     check every page under calendar/manage/ makes, and the same check this
//     API's own create, update and delete endpoints make for a session
//     (ApiAuth::requireWrite calls ApiResponse::requireAdmin).
//   - An API key: the key must hold the events:write scope. That scope already
//     lets a key create an event (a draft, unless it says otherwise), change
//     its status and delete it, so reading a draft gives it nothing new. A key
//     with only events:read is treated like a member who cannot manage events.
//     For a key request the session is deliberately ignored: otherwise a
//     browser signed in as an administrator could lend its rights to a
//     read-only key sent from that same browser.
//
// Deleted events are left out for everybody by the lookup below
// (isDeleted = 0).
//
// 3. WHO may read the event at all — #514 part P2 (21 September 2026). Until
//    then an event not marked public was open to ANY signed-in session (a
//    member of another organisation included) and to any key, because the only
//    test was ApiAuth::requireRead at the top. Now the one shared rule,
//    EventVisibility::where(), is part of the lookup below:
//      - a session sees what the calendar would show that person ("session"
//        mode): public events, members-only events of an organisation they are
//        an active member of (or administer), imported events by their own
//        level;
//      - a key ("key" mode) sees the organisation's OWN events exactly as
//        before (#127 and #511 track that separately), and an imported event
//        only when it is public AND ticked for the public website, with full
//        details only when its calendar itself is public (owner answer 3).
//    A refused event comes back as no row — the same "Event not found" as a
//    missing one, for the same statements.
//
// 4. HOW MUCH of it (#514 part P2). canSeeFull (1 = full details, 0 = title,
//    date and time only) is selected beside the row; at 0 the detail columns
//    are emptied (EventVisibility::redact()) before the reply is built, and
//    the reply says so with `detailsLimited`. `imported` says whether the
//    event came from an outside calendar. No `external*` or `import*` column
//    is ever returned: `e.*` is replaced by an explicit list for exactly that
//    reason (a new #514 column would otherwise leak through `e.*` the moment
//    it was added).
//
// What was wrong before #503: there was no condition on status at all. Any
// signed-in member, or any key with events:read, could read a draft's full
// row, description included, by trying id=1, id=2 and so on. Event numbers
// count upward, so that needs no guessing.
//
// ⏱️ The same database work for every "not found" (Codex review, third round,
//    14 September 2026, brief-503b-r3.txt / codex-503b-r3.txt).
//    The caller's right to manage events is decided HERE, before the lookup,
//    for every request that gets this far, whatever the id or slug turns out
//    to be. The draft rule is then part of the lookup itself: the WHERE clause
//    keeps a draft only when the bound value $canManageFlag is 1. So a draft
//    this caller may not read comes back from the database as an empty result,
//    exactly like an id that matches nothing or a deleted event, and all of
//    them run the same PHP lines to the same 404.
//
//    What was wrong in the first fix: the rights were read AFTER the lookup,
//    and only when it had found an event. App::isAdmin() runs the account query
//    (App::user(), web/_core/App.php) the first time it is asked in a request,
//    and nothing earlier on this path asks. So for a signed-in member a draft
//    cost one more database round trip than a number that matched nothing —
//    measured (round 4, 16 September 2026): 14 logged database commands for a
//    missing number against 17 for a refused draft (now 17 for both). The
//    replies were identical, but the extra work took measurably longer, which
//    is enough for a patient person to find out which numbers are drafts.
//
//    ⚠️ What this CANNOT promise: the time is identical only as far as the
//       database connection and PHP are concerned. Inside MySQL, a number that
//       matches a draft row still costs reading that row before it is rejected,
//       which a number matching nothing does not. That is microseconds, well
//       below the noise of a web request, but it is not zero. Separately, a
//       signed-in session now always pays for the account query, even for a
//       number that matches nothing; that depends only on who is asking, which
//       the asker already knows. An API key's rights come from the key row that
//       ApiRouter has already read, so they cost no query at all.
//
//    Tried and rejected:
//      - Reading the rights up front but keeping the status test in PHP after
//        the lookup. The database work would match, but a refused draft would
//        still run PHP lines that a missing number skips. With the rule in the
//        query both are simply an empty result, the same approach as the
//        invitation page (calendar/rsvp-by-link.php).
//      - Copying rsvp-by-link's tblUsers/tblUserSites joins into this query.
//        Unnecessary here: this lookup is already limited to Site::id(), so
//        App::isAdmin() is exactly "can manage events in this organisation" —
//        no cross-organisation invitation case to account for.
//      - Two different statement texts depending on the caller (no status
//        clause at all for an administrator). Not a leak — the text depends on
//        the caller, not the event — but one text with a bound flag is simpler.
//      - Adding a fixed or random delay before "not found". It slows every
//        caller, and random noise can be averaged away with enough requests,
//        so it hides nothing reliably.
//      - Selecting the rights columns in the lookup and deciding in PHP
//        afterwards. Same objection as the first rejected option above.
//
// ⚠️ The same few lines are repeated in calendar/api/hub-resources.php and
//    hub-videos.php. If who may manage events ever changes, change all three.
$apiKeyRow       = ApiAuth::bearerKeyRow();
$canManageEvents = $apiKeyRow !== null
    ? ApiKey::hasScope($apiKeyRow, 'events:write')
    : App::isAdmin();
$canManageFlag   = $canManageEvents === true ? 1 : 0;

// 👁️ #514 part P2 — the rule's mode follows the same test as $canManageEvents
//    above: a request carrying a key is "key" mode, and its session (if any)
//    is ignored. Key mode binds no values in either fragment; the statement
//    below always binds the id or slug, so bind_param() is never empty.
$ruleMode   = $apiKeyRow !== null ? EventVisibility::MODE_KEY : EventVisibility::MODE_SESSION;
$viewerId   = $ruleMode === EventVisibility::MODE_SESSION ? EventVisibility::sessionViewerId() : 0;
$today      = date('Y-m-d');
$visibility = EventVisibility::where('e', $ruleMode, $viewerId, $today);
$fullDetail = EventVisibility::fullDetailSelect('e', $ruleMode, $viewerId, $today, 'canSeeFull');

// 📋 ONE lookup for both ways in (id or slug). Until #514 part P2 there were
//    two statements, one per key, each selecting `e.*`.
//
//    The column list is every tblEvents column (web/_sql/full_schema.sql,
//    CREATE TABLE tblEvents) EXCEPT externalFeedID, externalUid and the #514
//    import*/external* columns, which must never leave the portal through this
//    API. It is written out instead of `e.*` so that a column added to
//    tblEvents later is NOT returned until somebody decides it should be.
//    `(e.externalFeedID IS NOT NULL) AS imported` gives the yes/no the reply
//    needs without selecting the calendar number itself.
//
//    The two `%s` marks are filled by sprintf(): the canSeeFull expression
//    (SQL built by EventVisibility itself) and the key column, taken from the
//    fixed pair below — never from anything the caller sent. Why sprintf()
//    and not joining with `.`: tools/audit-checks/check_sql_columns.py does
//    not recognise a statement at all when PHP code sits between SELECT and
//    FROM, so joining would hide this whole column list from it (measured
//    while building #514 part P2). Written as one literal, the list is
//    checked against the schema on every pull request.
//
//    The statement text depends only on whether an id or a slug was asked for
//    and on the caller's mode — never on the event — so a refused event and a
//    missing one still send the same statement. The rule's fragment goes
//    after the literal conditions (the draft rule included); canSeeFull's
//    values are bound first because the SELECT list comes before the WHERE.
[$keyColumn, $keyType, $keyValue] = $eventId > 0
    ? ['e.eventID', 'i', $eventId]
    : ['e.eventSlug', 's', $slug];
$event = null;
$stmt = $db->prepare(sprintf(
    'SELECT e.eventID, e.siteID, e.seriesID, e.categoryID, e.typeID, e.eventName, e.eventSlug, e.description, '
    . 'e.startDateTime, e.endDateTime, e.timezone, e.eventTimezone, e.isAllDay, '
    . 'e.locationName, e.locationAddress, e.locationWebURL, e.locationGeoLat, e.locationGeoLng, e.locationW3W, '
    . 'e.locationPhone, e.locationEmail, e.hostOrgName, e.partnerOrgs, e.heroImage, e.posterImage, e.profileImage, '
    . 'e.status, e.isPublic, e.isFeatured, e.isDeleted, e.deletedAt, e.capacity, '
    . 'e.createdByID, e.updatedByID, e.createdAt, e.updatedAt, '
    . 'e.submissionStatus, e.submittedByID, e.submitterName, e.submitterEmail, e.submittedAt, '
    . 'e.moderatedByID, e.moderatedAt, e.moderationNote, e.cancelReason, e.statusChangedByID, e.statusChangedAt, '
    . 'e.capacityCount, e.registrationEnabled, e.registrationOpensAt, e.registrationClosesAt, '
    . 'e.registrationRetentionDays, e.venueID, e.roomID, '
    . '(e.externalFeedID IS NOT NULL) AS imported, %s, '
    . 'c.categoryName, t.typeName, s.seriesName '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
    . 'WHERE %s = ? AND e.siteID = ? AND e.isDeleted = 0 '
    . "AND (e.status IN ('published', 'cancelled', 'postponed') OR ? = 1)",
    $fullDetail['sql'],
    $keyColumn
) . $visibility['sql'] . ' LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param(
        $fullDetail['types'] . $keyType . 'ii' . $visibility['types'],
        ...array_merge($fullDetail['params'], [$keyValue, $siteId, $canManageFlag], $visibility['params'])
    );
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($event === null) {
    ApiResponse::error('Event not found', 404);
}

// 👁️ #514 part P2: cut what this caller may not see BEFORE the location object
//    below is built from it. Both values come back as the number 1 or 0.
$event = EventVisibility::redact($event, (int) $event['canSeeFull'] === 1);
$event['imported'] = (int) $event['imported'] === 1;
unset($event['canSeeFull']);

// 📍 #456 Chunk A — canonical `location` object (cross-repo contract §2),
// additive alongside the existing legacy location* columns already
// present in $event (selected by name above since #514 part P2; they used to
// come through `e.*`) — backward-compatible, nothing removed.
$event['location'] = GeoLocation::toLocationObject(
    [
        'name' => $event['locationName'], 'addressLine1' => $event['locationAddress'],
        'latitude' => $event['locationGeoLat'], 'longitude' => $event['locationGeoLng'],
        'what3words' => $event['locationW3W'],
    ],
    ['line1' => 'addressLine1']
);

ApiResponse::success(['event' => $event]);
