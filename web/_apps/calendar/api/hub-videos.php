<?php
// Path: _apps/calendar/api/hub-videos.php
/**
 * -----------------------------------------------------------------------------
 * Calendar API — Event Team Hub Videos 🎥
 * -----------------------------------------------------------------------------
 * Returns the video grid (YouTube / Vimeo / Cloudflare Stream references)
 * from an event's Team Hub (`tblEventHubVideos`, migration 155/#386) for
 * consumption by external integrations — built for the projectBookIT Event
 * Team Hub Phase 3 integration (#387).
 *
 * Read-only, dual-mode auth (session or bearer `eventhub:read` scope) via
 * ApiAuth::requireRead(). Since #534 a signed-in person is answered only for
 * an event they may see (the shared rule, EventVisibility; see the lookup). Tenant-scoped: the requested event MUST belong to
 * the resolved site (Site::id() — already tenant-pinned to the bearer key's
 * own site by ApiAuth for bearer requests) or this 404s rather than leaking
 * another tenant's event/video rows.
 *
 * 🔒 NEVER emits a secret. Only `videoRef` (the public YouTube/Vimeo ID or
 * Cloudflare Stream UID a player embeds against) is returned — no Cloudflare
 * signing key, no signed playback token, and no `cfstream.*` setting value
 * ever appears in this response. `requiresSignedUrl` / `allowedOrigins` are
 * plain playback-policy metadata (whether/where a token would be required),
 * never the token or key material itself.
 *
 * Convention path — NOT registered in tblRoutes. `api/*` paths never consult
 * tblRoutes; Router::handleSpecialRoutes hands them straight to
 * ApiRouter::dispatch, which loads this file at `_apps/calendar/api/{action}.php`
 * once `api.calendar.hub-videos.enabled = 'true'` is seeded in tblSettings
 * (migration 157). See .claude/CLAUDE.md → "ApiRouter routing trap".
 *
 * GET /api/calendar/hub-videos?eventID={int}
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
//    touching tblEventHubVideos (a child table with no own siteID column,
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
//    signed-in member could learn that a draft existed, and list its Team Hub
//    videos, by trying eventID=1, 2, 3 and so on.
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
//       hub-resources.php (which also carries the full tried-and-rejected
//       list). If who may manage events ever changes, change all three.
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
//    videos by opening this address under that organisation's own
//    address and trying eventID=1, 2, 3 and so on.
//
//    WHAT THIS CANNOT DO: it applies the rule for who may see the EVENT. The
//    Team Hub PAGE (calendar/event-hub.php) is narrower: it lets in only
//    the event's own team (Auth::isEventTeamMember()). A member of the
//    organisation who is not on the team, and for a public event any
//    signed-in person, is refused by that page and still answered here.
//    That difference is older than #534 and is not changed by it; it is
//    written up as a follow-up rather than quietly narrowed in a change
//    that was about something else.
//
//    The fragment goes LAST, after the literal conditions, and its values
//    are bound after theirs (binding is by position). In key mode it binds
//    nothing; the three values before it mean bind_param() is never empty.
$ruleMode   = $apiKeyRow !== null ? EventVisibility::MODE_KEY : EventVisibility::MODE_SESSION;
$viewerId   = $ruleMode === EventVisibility::MODE_SESSION ? EventVisibility::sessionViewerId() : 0;
$visibility = EventVisibility::where('e', $ruleMode, $viewerId, date('Y-m-d'));

// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
// `externalFeedID IS NULL` stays written out here although the rule also knows about imported events: with it,
// the rule's "imported" half can never match, so an imported event's hub is never answered whatever its level.
$eventStmt = $db->prepare(
    'SELECT e.eventID FROM tblEvents e WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
    . "AND (e.status IN ('published', 'cancelled', 'postponed') OR ? = 1)" . $visibility['sql'] . ' LIMIT 1'
);
if ($eventStmt === false) {
    ApiResponse::error('Database error', 500);
}
$eventStmt->bind_param('iii' . $visibility['types'], $eventId, $siteId, $canManageFlag, ...$visibility['params']);
$eventStmt->execute();
$eventRow = $eventStmt->get_result()->fetch_assoc();
$eventStmt->close();

if ($eventRow === null) {
    ApiResponse::error('Event not found', 404);
}

// 📋 Fetch videos — ONLY non-secret columns. Never SELECT keyHash/signing/
//    token columns here even by `*` shorthand — this query names every
//    column explicitly so a future ALTER can't silently widen the response.
$videos = [];
$stmt = $db->prepare(
    'SELECT videoID, provider, videoRef, title, requiresSignedUrl, allowedOrigins, uploadStatus, sortOrder '
    . 'FROM tblEventHubVideos '
    . 'WHERE eventID = ? '
    . 'ORDER BY sortOrder, videoID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['requiresSignedUrl'] = (bool) $row['requiresSignedUrl'];
        $videos[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'eventID' => $eventId,
    'videos'  => $videos,
]);
