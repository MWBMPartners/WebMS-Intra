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
// Deleted events are left out for everybody by the lookups below
// (isDeleted = 0). Events not marked public were already limited to signed-in
// members and API keys by ApiAuth::requireRead at the top, which matches the
// event page: a member may read a published event that is not public.
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

$event = null;
if ($eventId > 0) {
    $stmt = $db->prepare(
        'SELECT e.*, c.categoryName, t.typeName, s.seriesName '
        . 'FROM tblEvents e '
        . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
        . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
        . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
        . 'WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 '
        . "AND (e.status IN ('published', 'cancelled', 'postponed') OR ? = 1) "
        . 'LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iii', $eventId, $siteId, $canManageFlag);
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
        . 'WHERE e.eventSlug = ? AND e.siteID = ? AND e.isDeleted = 0 '
        . "AND (e.status IN ('published', 'cancelled', 'postponed') OR ? = 1) "
        . 'LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('sii', $slug, $siteId, $canManageFlag);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
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
