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
 * ApiAuth::requireRead(). Tenant-scoped: the requested event MUST belong to
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

// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$eventStmt = $db->prepare(
    'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL '
    . "AND (status IN ('published', 'cancelled', 'postponed') OR ? = 1) LIMIT 1"
);
if ($eventStmt === false) {
    ApiResponse::error('Database error', 500);
}
$eventStmt->bind_param('iii', $eventId, $siteId, $canManageFlag);
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
