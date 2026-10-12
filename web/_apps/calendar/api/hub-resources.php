<?php
// Path: _apps/calendar/api/hub-resources.php
/**
 * -----------------------------------------------------------------------------
 * Calendar API — Event Team Hub Resources 🔗
 * -----------------------------------------------------------------------------
 * Returns the Resources list (links + notes, grouped into free-text sections)
 * from an event's Team Hub (`tblEventHubResources`, migration 155/#386) for
 * consumption by external integrations — built for the projectBookIT Event
 * Team Hub Phase 3 integration (#387).
 *
 * Read-only, dual-mode auth (session or bearer `eventhub:read` scope) via
 * ApiAuth::requireRead(). Since #534 a signed-in person is answered only for
 * an event they may see (the shared rule, EventVisibility; see the lookup),
 * and since 12 October 2026 only when they are on that event's own team, as
 * on the Team Hub page. An API key is not asked the team question. Tenant-scoped: the requested event MUST belong to
 * the resolved site (Site::id() — already tenant-pinned to the bearer key's
 * own site by ApiAuth for bearer requests) or this 404s rather than leaking
 * another tenant's event/resource rows.
 *
 * Convention path — NOT registered in tblRoutes. `api/*` paths never consult
 * tblRoutes; Router::handleSpecialRoutes hands them straight to
 * ApiRouter::dispatch, which loads this file at `_apps/calendar/api/{action}.php`
 * once `api.calendar.hub-resources.enabled = 'true'` is seeded in tblSettings
 * (migration 157). See .claude/CLAUDE.md → "ApiRouter routing trap".
 *
 * GET /api/calendar/hub-resources?eventID={int}
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/387
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiKey;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\Site;

ApiAuth::requireRead('eventhub:read');

$db      = App::db();
$siteId  = Site::id();
$eventId = (int) ($_GET['eventID'] ?? 0);

if ($eventId <= 0) {
    ApiResponse::error('Provide eventID parameter', 400);
}

// 🏢 Tenant guard — confirm the event belongs to the resolved site BEFORE
//    touching tblEventHubResources (a child table with no own siteID column,
//    see migration 155). Never leak another tenant's event via a 404 vs 200
//    timing/shape difference — both "no such event", "event, wrong site" and
//    "a draft this caller may not see" return the identical 404.
//
// 🛡️ Drafts (#503). The same rule as the event's own page (calendar/event.php)
//    and the events detail API (events/api/detail.php, which explains the
//    choices, the timing reasoning and the rejected alternatives in full).
//    Only an event whose status is published, cancelled or postponed is
//    answered for people in general. A DRAFT only for somebody who can manage
//    events: App::isAdmin() for a signed-in session, or an API key holding
//    events:write (a key with only eventhub:read cannot manage events, so it is
//    treated like a member; for a key request the session is ignored).
//    Everybody else gets EXACTLY the same "Event not found" as a number that
//    matches no event.
//
//    The rights are decided BEFORE the lookup, for every request that gets
//    this far, and the draft rule is inside the lookup's WHERE clause as the
//    bound yes/no value $canManageFlag. So a refused draft, a deleted event and
//    a number that matches nothing all run the same statements and come back
//    as the same empty result.
//
//    What was wrong before #503: the lookup had no condition on status, so any
//    signed-in member could learn that a draft existed, and read its Team Hub
//    resources, by trying eventID=1, 2, 3 and so on.
//    What was wrong in the first fix (Codex review, third round, 14 September
//    2026, brief-503b-r3.txt / codex-503b-r3.txt): the rights were read only
//    when the lookup had found an event, so for a signed-in member
//    App::isAdmin() ran its account query for a draft but not for a missing
//    number. Same reply, one more database round trip — measured (round 4,
//    16 September 2026): 14 logged commands for a missing number against 17
//    for a refused draft (now 17 for both).
//
//    ⚠️ Cannot promise: inside MySQL a number matching a draft row still costs
//       reading that row before it is rejected (microseconds). A signed-in
//       session always pays for the account query now, whatever the number. An
//       API key's rights come from the key row ApiRouter already read, so they
//       cost no query at all.
//
//    ⚠️ The same few lines are repeated in events/api/detail.php and
//       hub-videos.php (which also carries the full tried-and-rejected list).
//       If who may manage events ever changes, change all three.
$apiKeyRow       = ApiAuth::bearerKeyRow();
$canManageEvents = $apiKeyRow !== null
    ? ApiKey::hasScope($apiKeyRow, 'events:write')
    : App::isAdmin();
$canManageFlag   = $canManageEvents === true ? 1 : 0;

// 👁️ WHO may read the event at all (#534, 11 October 2026). The one shared
//    rule, EventVisibility::where(), is now part of the lookup below, used
//    exactly as events/api/detail.php uses it:
//      - a signed-in person ("session" mode) is answered only for an event
//        the calendar would show them: a public one, or an internal one of
//        an organisation they are an active member of or administer;
//      - an API key ("key" mode) is answered for the organisation's own
//        events as before (#127 and #511 track that separately); for a key
//        the session, if there is one, is ignored.
//    A refused event comes back as no row: the same "Event not found" as a
//    missing one, from the same statement, so the answer does not reveal
//    that the event exists.
//
//    WHAT WAS WRONG: the lookup tested the event's number, organisation,
//    "not deleted" and status, and nothing about the VIEWER. ApiAuth::
//    requireRead() at the top asks only "is somebody signed in?". So any
//    signed-in account on the whole installation, a member of a different
//    organisation included, could read an internal event's Team Hub
//    links and notes by opening this address under that organisation's own
//    address and trying eventID=1, 2, 3 and so on.
//
//    WHAT THIS CANNOT DO: it is the rule for who may see the EVENT, and
//    that is wider than who may see its Team Hub. The team rule is the next
//    block, $onTeamFlag; both must say yes.
//
//    The fragment goes LAST, after the literal conditions, and its values
//    are bound after theirs (binding is by position). In key mode it binds
//    nothing; the four values before it mean bind_param() is never empty.
$ruleMode   = $apiKeyRow !== null ? EventVisibility::MODE_KEY : EventVisibility::MODE_SESSION;
$viewerId   = $ruleMode === EventVisibility::MODE_SESSION ? EventVisibility::sessionViewerId() : 0;
$visibility = EventVisibility::where('e', $ruleMode, $viewerId, date('Y-m-d'));

// 👥 ONLY THE EVENT'S OWN TEAM, for a signed-in person (12 October 2026). The
//    same test as the Team Hub page (calendar/event-hub.php):
//    Auth::isEventTeamMember(), which says yes to an administrator, a
//    coordinator of the event, a crew member, somebody with a job on it and
//    somebody named on the event as one of its people (host, speaker,
//    musician, organiser and so on).
//
//    WHAT WAS WRONG: this endpoint was wider than the page. A member of the
//    organisation who is not on the team, and for a public event any
//    signed-in account at all, was refused by the page and answered here
//    with the hub's links and notes.
//
//    Like the draft rule, it is worked out BEFORE the look-up, for every
//    request, and bound into the look-up as a yes/no value. So somebody who
//    is not on the team, a deleted event and a made-up number all run the
//    same statements and get the same "Event not found".
//
//    An API key is not a person on a team: a key holding eventhub:read is the
//    organisation's own program and still reads the hub of any event of its
//    own organisation (the owner's decision, 12 October 2026). For a key the
//    session, if there is one, is ignored, as everywhere else in this file.
//
//    WHAT THIS CANNOT DO: it does not make the answer and the page agree in
//    every detail. The page asks the team question only; this asks the team
//    question AND the event rule above, so somebody on the team of an
//    internal event who is no longer a member of the organisation is let in
//    by the page and refused here.
$onTeamFlag = ($apiKeyRow !== null || Auth::isEventTeamMember($eventId) === true) ? 1 : 0;

// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
// `externalFeedID IS NULL` stays written out here although the rule also knows about imported events: with it,
// the rule's "imported" half can never match, so an imported event's hub is never answered whatever its level.
$eventStmt = $db->prepare(
    'SELECT e.eventID FROM tblEvents e WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
    . "AND (e.status IN ('published', 'cancelled', 'postponed') OR ? = 1) AND ? = 1" . $visibility['sql'] . ' LIMIT 1'
);
if ($eventStmt === false) {
    ApiResponse::error('Database error', 500);
}
$eventStmt->bind_param('iiii' . $visibility['types'], $eventId, $siteId, $canManageFlag, $onTeamFlag, ...$visibility['params']);
$eventStmt->execute();
$eventRow = $eventStmt->get_result()->fetch_assoc();
$eventStmt->close();

if ($eventRow === null) {
    ApiResponse::error('Event not found', 404);
}

// 📋 Fetch resources — grouped-by-section ordering matches the Team Hub UI.
$resources = [];
$stmt = $db->prepare(
    'SELECT resourceID, section, resourceType, title, url, body, sortOrder '
    . 'FROM tblEventHubResources '
    . 'WHERE eventID = ? '
    . 'ORDER BY section, sortOrder, resourceID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $resources[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'eventID'   => $eventId,
    'resources' => $resources,
]);
