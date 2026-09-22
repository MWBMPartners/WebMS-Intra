<?php
// Path: public_html/events/api/update.php
/**
 * -----------------------------------------------------------------------------
 * Events API — Update Event
 * -----------------------------------------------------------------------------
 * Admin-only. PATCH-style — only fields present in the body are touched.
 *
 *   POST /api/events/update?id=N    (or {"eventID": N} in body)
 *
 * Updatable fields: eventName, description, startDateTime, endDateTime,
 *                   isAllDay, locationName, categoryID, typeID, status,
 *                   isPublic, isFeatured.
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

$eventId = (int) ($_GET['id'] ?? $body['eventID'] ?? 0);
if ($eventId <= 0) {
    ApiResponse::error('eventID is required', 400);
}

$siteId = Site::id();
$db     = App::db();

// 🔍 Verify the event exists + belongs to this site
// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$check = $db->prepare(
    'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL LIMIT 1'
);
if ($check === false) {
    ApiResponse::error('Database error', 500);
}
$check->bind_param('ii', $eventId, $siteId);
$check->execute();
$exists = $check->get_result()->fetch_assoc() !== null;
$check->close();
if ($exists === false) {
    ApiResponse::error('Event not found', 404);
}

// 🛠️ Build dynamic UPDATE — only includes columns the caller provided
$columnMap = [
    'eventName'       => 's',
    'description'     => 's',
    'startDateTime'   => 's',
    'endDateTime'     => 's',
    'isAllDay'        => 'i',
    'locationName'    => 's',
    'categoryID'      => 'i',
    'typeID'          => 'i',
    'status'          => 's',
    'isPublic'        => 'i',
    'isFeatured'      => 'i',
    // 📍 #456 Chunk A — additive location passthrough fields.
    'locationAddress' => 's',
    'locationWebURL'  => 's',
    'locationPhone'   => 's',
    'locationEmail'   => 's',
];
$validStatuses = ['draft', 'published', 'cancelled', 'archived'];

$set    = [];
$types  = '';
$params = [];

foreach ($columnMap as $col => $type) {
    if (array_key_exists($col, $body) === false) {
        continue;
    }
    $value = $body[$col];

    if ($col === 'status' && in_array((string) $value, $validStatuses, true) === false) {
        ApiResponse::error("status must be one of: " . implode(', ', $validStatuses), 400);
    }
    if (in_array($col, ['startDateTime', 'endDateTime'], true) === true && $value !== null && $value !== '') {
        $ts = strtotime((string) $value);
        if ($ts === false) {
            ApiResponse::error("$col is not a valid timestamp", 400);
        }
        $value = date('Y-m-d H:i:s', $ts);
    }
    if (in_array($col, ['isAllDay', 'isPublic', 'isFeatured'], true) === true) {
        $value = (bool) $value === true ? 1 : 0;
    }
    if (in_array($col, ['categoryID', 'typeID'], true) === true) {
        $value = $value === null || $value === '' ? null : (int) $value;
    }

    $set[]    = $col . ' = ?';
    $types   .= $type;
    $params[] = $value;
}

// 📍 #456 Chunk A — locationGeoLat/locationGeoLng are a validated PAIR
// (all-or-nothing), so they're handled outside the generic per-column
// loop above. Either key present triggers validation of both.
if (array_key_exists('locationGeoLat', $body) === true || array_key_exists('locationGeoLng', $body) === true) {
    $geoCoords = GeoLocation::validateCoords($body['locationGeoLat'] ?? null, $body['locationGeoLng'] ?? null);
    if ($geoCoords === null && ($body['locationGeoLat'] ?? null) !== null && ($body['locationGeoLng'] ?? null) !== null) {
        ApiResponse::error('locationGeoLat/locationGeoLng must be a valid coordinate pair', 422);
    }
    $set[]    = 'locationGeoLat = ?';
    $set[]    = 'locationGeoLng = ?';
    $types   .= 'dd';
    $params[] = $geoCoords['lat'] ?? null;
    $params[] = $geoCoords['lng'] ?? null;
}

// 📍 #456 Chunk A — locationW3W is regex-validated; an invalid non-empty
// value is a 422, never silently dropped or stored malformed.
if (array_key_exists('locationW3W', $body) === true) {
    $w3wRaw = trim((string) ($body['locationW3W'] ?? ''));
    $w3wVal = null;
    if ($w3wRaw !== '') {
        $w3wVal = GeoLocation::validateW3W($w3wRaw);
        if ($w3wVal === null) {
            ApiResponse::error('locationW3W is not a valid what3words address (word.word.word)', 422);
        }
    }
    $set[]    = 'locationW3W = ?';
    $types   .= 's';
    $params[] = $w3wVal;
}

if (count($set) === 0) {
    ApiResponse::error('No updatable fields in request body', 400);
}

$set[]    = 'updatedAt = NOW()';
$types   .= 'ii';
$params[] = $eventId;
$params[] = $siteId;

// Imported events are read-only (#514 D5); the existence check above already refuses one, so this
// statement is unreachable for an imported row — the condition is repeated here so the statement
// still carries its own marker for tools/audit-checks/check_event_visibility.py.
$sql = 'UPDATE tblEvents SET ' . implode(', ', $set)
     . ' WHERE eventID = ? AND siteID = ? AND externalFeedID IS NULL LIMIT 1';
$stmt = $db->prepare($sql);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_UPDATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_UPDATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to update event', 500);
}

Logger::activity('ApiEventUpdate', 'API: updated event #' . $eventId);

// 📍 #456 Chunk A — re-fetch and emit the canonical `location` object
// (cross-repo contract §2) reflecting the post-update row, additive
// alongside eventID.
$locationObject = null;
// Imported events are read-only (#514 D5); the update above can never touch one, so this re-fetch cannot
// either — the condition is repeated here so this line also carries its own marker for the checker.
$locRow = $db->prepare('SELECT locationName, locationAddress, locationGeoLat, locationGeoLng, locationW3W FROM tblEvents WHERE eventID = ? AND siteID = ? AND externalFeedID IS NULL LIMIT 1');
if ($locRow !== false) {
    $locRow->bind_param('ii', $eventId, $siteId);
    $locRow->execute();
    $freshRow = $locRow->get_result()->fetch_assoc();
    $locRow->close();
    if ($freshRow !== null) {
        $locationObject = GeoLocation::toLocationObject(
            [
                'name' => $freshRow['locationName'], 'addressLine1' => $freshRow['locationAddress'],
                'latitude' => $freshRow['locationGeoLat'], 'longitude' => $freshRow['locationGeoLng'],
                'what3words' => $freshRow['locationW3W'],
            ],
            ['line1' => 'addressLine1']
        );
    }
}

ApiResponse::success(['eventID' => $eventId, 'location' => $locationObject], 200);
