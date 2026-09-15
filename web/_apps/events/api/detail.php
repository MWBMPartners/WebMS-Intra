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

$event = null;
if ($eventId > 0) {
    $stmt = $db->prepare(
        'SELECT e.*, c.categoryName, t.typeName, s.seriesName '
        . 'FROM tblEvents e '
        . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
        . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
        . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
        . 'WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} else {
    $stmt = $db->prepare(
        'SELECT e.*, c.categoryName, t.typeName, s.seriesName '
        . 'FROM tblEvents e '
        . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
        . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
        . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
        . 'WHERE e.eventSlug = ? AND e.siteID = ? AND e.isDeleted = 0 LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('si', $slug, $siteId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
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
//    event (the row is dropped and the same 404 below answers), so the reply
//    does not reveal that the draft exists.
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
// Deleted events were already left out for everybody by the queries above
// (isDeleted = 0). Events not marked public were already limited to signed-in
// members and API keys by ApiAuth::requireRead at the top, which matches the
// event page: a member may read a published event that is not public.
//
// What was wrong before: there was no condition on status at all. Any signed-in
// member, or any key with events:read, could read a draft's full row,
// description included, by trying id=1, id=2 and so on. Event numbers count
// upward, so that needs no guessing.
//
// ⚠️ The same few lines are repeated in calendar/api/hub-resources.php and
//    hub-videos.php. If who may manage events ever changes, change all three.
if ($event !== null) {
    $isVisibleStatus = in_array((string) ($event['status'] ?? ''), ['published', 'cancelled', 'postponed'], true) === true;
    $apiKeyRow       = ApiAuth::bearerKeyRow();
    $canManageEvents = $apiKeyRow !== null
        ? ApiKey::hasScope($apiKeyRow, 'events:write')
        : App::isAdmin();
    if ($isVisibleStatus === false && $canManageEvents === false) {
        $event = null;
    }
}

if ($event === null) {
    ApiResponse::error('Event not found', 404);
}

// 📍 #456 Chunk A — canonical `location` object (cross-repo contract §2),
// additive alongside the existing legacy location* columns already
// present in $event via `e.*` (backward-compatible — nothing removed).
$event['location'] = GeoLocation::toLocationObject(
    [
        'name' => $event['locationName'], 'addressLine1' => $event['locationAddress'],
        'latitude' => $event['locationGeoLat'], 'longitude' => $event['locationGeoLng'],
        'what3words' => $event['locationW3W'],
    ],
    ['line1' => 'addressLine1']
);

ApiResponse::success(['event' => $event]);
