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
//    timing/shape difference — both "no such event" and "event, wrong site"
//    return the identical 404.
$eventStmt = $db->prepare(
    'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($eventStmt === false) {
    ApiResponse::error('Database error', 500);
}
$eventStmt->bind_param('ii', $eventId, $siteId);
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
