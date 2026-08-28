<?php
// Path: public_html/events/api/create.php
/**
 * -----------------------------------------------------------------------------
 * Events API — Create Event
 * -----------------------------------------------------------------------------
 * Admin-only. Accepts JSON body and creates a new event for the current site.
 *
 *   POST /api/events/create
 *   Content-Type: application/json
 *   {
 *     "eventName":      "Worship Service",      (required)
 *     "startDateTime":  "2026-06-01T10:00:00",  (required, ISO 8601)
 *     "endDateTime":    "2026-06-01T11:30:00",  (optional)
 *     "isAllDay":       false,                   (optional, default false)
 *     "locationName":   "Main Hall",            (optional)
 *     "categoryID":     7,                       (optional)
 *     "typeID":         3,                       (optional)
 *     "status":         "published",             (optional, default "draft")
 *     "isPublic":       true,                    (optional, default true)
 *     "description":    "Order of service…"     (optional)
 *   }
 *
 * @package   Portal\API
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\GeoLocation;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('events:write');

// 📥 Required fields
$eventName     = trim((string) ($body['eventName']     ?? ''));
$startDateTime = trim((string) ($body['startDateTime'] ?? ''));

if ($eventName === '' || $startDateTime === '') {
    ApiResponse::error('eventName and startDateTime are required', 400);
}

// 📅 Validate datetimes
$start = strtotime($startDateTime);
if ($start === false) {
    ApiResponse::error('startDateTime is not a valid timestamp', 400);
}
$endDateTime = isset($body['endDateTime']) === true && $body['endDateTime'] !== ''
    ? trim((string) $body['endDateTime'])
    : null;
$endTs = null;
if ($endDateTime !== null) {
    $endTs = strtotime($endDateTime);
    if ($endTs === false || $endTs < $start) {
        ApiResponse::error('endDateTime must be a valid timestamp and ≥ startDateTime', 400);
    }
}

// 📥 Optional fields
$isAllDay     = isset($body['isAllDay']) === true && (bool) $body['isAllDay'] === true ? 1 : 0;
$isPublic     = isset($body['isPublic']) === false || (bool) $body['isPublic'] === true ? 1 : 0;
$isFeatured   = isset($body['isFeatured']) === true && (bool) $body['isFeatured'] === true ? 1 : 0;
$locationName = trim((string) ($body['locationName'] ?? ''));
$status       = (string) ($body['status'] ?? 'draft');
if (in_array($status, ['draft', 'published', 'cancelled', 'archived'], true) === false) {
    $status = 'draft';
}
$categoryId = isset($body['categoryID']) === true && $body['categoryID'] !== null && $body['categoryID'] !== ''
    ? (int) $body['categoryID'] : null;
$typeId     = isset($body['typeID']) === true && $body['typeID'] !== null && $body['typeID'] !== ''
    ? (int) $body['typeID'] : null;
$description = (string) ($body['description'] ?? '');

// 📍 #456 Chunk A — optional location fields, additive to the create
// contract. Coords validated pair-or-null; W3W regex-validated (422-style
// error on an invalid non-empty value — never silently dropped).
$locationAddress = isset($body['locationAddress']) === true ? mb_substr((string) $body['locationAddress'], 0, 65000) : null;
$locationWebURL  = isset($body['locationWebURL']) === true ? mb_substr((string) $body['locationWebURL'], 0, 500) : null;
$locationPhone   = isset($body['locationPhone']) === true ? mb_substr((string) $body['locationPhone'], 0, 50) : null;
$locationEmail   = isset($body['locationEmail']) === true ? mb_substr((string) $body['locationEmail'], 0, 255) : null;

$geoCoords = GeoLocation::validateCoords($body['locationGeoLat'] ?? null, $body['locationGeoLng'] ?? null);
if (($body['locationGeoLat'] ?? null) !== null && ($body['locationGeoLng'] ?? null) !== null && $geoCoords === null) {
    ApiResponse::error('locationGeoLat/locationGeoLng must be a valid coordinate pair', 422);
}
$locationGeoLat = $geoCoords['lat'] ?? null;
$locationGeoLng = $geoCoords['lng'] ?? null;

$locationW3W = null;
$w3wRaw = trim((string) ($body['locationW3W'] ?? ''));
if ($w3wRaw !== '') {
    $locationW3W = GeoLocation::validateW3W($w3wRaw);
    if ($locationW3W === null) {
        ApiResponse::error('locationW3W is not a valid what3words address (word.word.word)', 422);
    }
}

// 🐢 Slug from name (server-side; truncated to 100 chars to fit the column)
$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $eventName), '-'));
$slug = substr($slug !== '' ? $slug : 'event-' . bin2hex(random_bytes(4)), 0, 100);

$siteId    = Site::id();
$startSql  = date('Y-m-d H:i:s', $start);
$endSql    = $endTs !== null ? date('Y-m-d H:i:s', $endTs) : null;
$creatorId = ApiAuth::actorUserId();

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblEvents '
    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, '
    . 'isAllDay, locationName, status, isPublic, isFeatured, categoryID, typeID, createdByID, '
    . 'locationAddress, locationWebURL, locationPhone, locationEmail, locationGeoLat, locationGeoLng, locationW3W) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
// 📍 #456 Chunk A: 14 -> 21 placeholders/vars (+locationAddress[s], +locationWebURL[s],
// +locationPhone[s], +locationEmail[s], +locationGeoLat[d], +locationGeoLng[d], +locationW3W[s]).
$stmt->bind_param(
    'isssssisssiiiissssdds',
    $siteId, $eventName, $slug, $description, $startSql, $endSql,
    $isAllDay, $locationName, $status, $isPublic, $isFeatured,
    $categoryId, $typeId, $creatorId,
    $locationAddress, $locationWebURL, $locationPhone, $locationEmail, $locationGeoLat, $locationGeoLng, $locationW3W
);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create event', 500);
}

Logger::activity('ApiEventCreate', 'API: created event #' . $newId . ' "' . $eventName . '"');

// 📍 #456 Chunk A — canonical `location` object (cross-repo contract §2),
// additive alongside the legacy locationName field for backward-compat.
$locationObject = GeoLocation::toLocationObject(
    [
        'name' => $locationName, 'addressLine1' => $locationAddress,
        'latitude' => $locationGeoLat, 'longitude' => $locationGeoLng, 'what3words' => $locationW3W,
    ],
    ['line1' => 'addressLine1']
);

ApiResponse::success([
    'eventID'      => $newId,
    'eventSlug'    => $slug,
    'locationName' => $locationName,
    'location'     => $locationObject,
], 201);
