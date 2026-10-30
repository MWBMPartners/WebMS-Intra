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
 *     "timezone":       "Pacific/Auckland",     (optional: the zone the two
 *                                                times are a clock reading
 *                                                in; the organisation's own
 *                                                when left out)
 *     "isAllDay":       false,                   (optional, default false)
 *     "locationName":   "Main Hall",            (optional)
 *     "categoryID":     7,                       (optional)
 *     "typeID":         3,                       (optional)
 *     "status":         "published",             (optional, default "draft")
 *     "isPublic":       true,                    (optional, default true)
 *     "description":    "Order of service…"     (optional)
 *   }
 *
 * WHICH TIME ZONE (#604). An event's start and end are stored as a time on
 * a clock, with the clock's zone beside them in `tblEvents.timezone`. This
 * handler used to store neither rightly: it left the zone to the column's
 * default, "Europe/London", for every customer everywhere, and it wrote the
 * clock time in whatever zone PHP happened to be in for the request. Now the
 * zone is the one given in the body, or the organisation's own
 * (Site::timezone()), and the times are written on THAT clock. A time given
 * with its own offset ("2026-06-01T10:00:00+12:00") is a moment, and is
 * turned into the reading of the event's clock at that moment. A zone that
 * is not a name the portal knows is refused; it is never replaced by a guess.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
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

// 🕰️ The zone the event's times are a clock reading in: the one given, or
//    the organisation's own. See "WHICH TIME ZONE" at the top of this file.
$zoneGiven = trim((string) ($body['timezone'] ?? ''));
if ($zoneGiven !== '' && Site::isZoneName($zoneGiven) === false) {
    ApiResponse::error(
        'timezone is not a time zone name the portal knows. Give a full name, spelled exactly, such as "'
        . Site::timezone() . '", or leave timezone out to use the organisation\'s own. Nothing was created.',
        422
    );
}
$zoneName = $zoneGiven !== '' ? $zoneGiven : Site::timezone();
$zone     = new \DateTimeZone($zoneName);

// 📅 Validate datetimes. Each is read on the event's own clock unless it
//    carries an offset of its own, and then put ONTO the event's clock.
//    (This used strtotime() and date(), which read and wrote on PHP's clock:
//    the organisation the address belongs to, not the one the key is for.)
try {
    $startAt = (new \DateTimeImmutable($startDateTime, $zone))->setTimezone($zone);
} catch (\Throwable $e) {
    ApiResponse::error('startDateTime is not a valid timestamp', 400);
}
$endDateTime = isset($body['endDateTime']) === true && $body['endDateTime'] !== ''
    ? trim((string) $body['endDateTime'])
    : null;
$endAt = null;
if ($endDateTime !== null) {
    try {
        $endAt = (new \DateTimeImmutable($endDateTime, $zone))->setTimezone($zone);
    } catch (\Throwable $e) {
        $endAt = null;
    }
    if ($endAt === null || $endAt->getTimestamp() < $startAt->getTimestamp()) {
        ApiResponse::error('endDateTime must be a valid timestamp and ≥ startDateTime', 400);
    }
}

// 📥 Optional fields
$isAllDay     = isset($body['isAllDay']) === true && (bool) $body['isAllDay'] === true ? 1 : 0;
$isPublic     = isset($body['isPublic']) === false || (bool) $body['isPublic'] === true ? 1 : 0;
$isFeatured   = isset($body['isFeatured']) === true && (bool) $body['isFeatured'] === true ? 1 : 0;
$locationName = trim((string) ($body['locationName'] ?? ''));
$status       = (string) ($body['status'] ?? 'draft');
// ⚠️ These four are exactly `tblEvents.status`'s ENUM, and they must stay that
//    way. The list used to read `archived`, which the column has NEVER had, and
//    `postponed`, which it HAS, was missing. Since #593 made every connection
//    strict, sending `archived` got as far as the INSERT and MySQL refused it
//    with "Data truncated for column 'status'" — a 500 to the caller. Before
//    #593 it was worse: the column stored the EMPTY STRING, and such an event
//    matches no filter the portal writes, so it vanished from every screen
//    while still sitting in the table (#676; migration 230 repairs those).
//    `tools/audit-checks/check_event_status_matches_column.py` now compares
//    this list with the column and fails if they drift apart.
if (in_array($status, ['draft', 'published', 'cancelled', 'postponed'], true) === false) {
    // Note this does NOT refuse — an unrecognised word quietly becomes a
    // draft, which is long-standing behaviour and is what the API description
    // says. `update.php` answers 400 instead; the two differ on purpose only
    // in so far as nobody has decided to make them agree.
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
$startSql  = $startAt->format('Y-m-d H:i:s');
$endSql    = $endAt !== null ? $endAt->format('Y-m-d H:i:s') : null;
$creatorId = ApiAuth::actorUserId();

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblEvents '
    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, timezone, '
    . 'isAllDay, locationName, status, isPublic, isFeatured, categoryID, typeID, createdByID, '
    . 'locationAddress, locationWebURL, locationPhone, locationEmail, locationGeoLat, locationGeoLng, locationW3W) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
// 📍 #456 Chunk A: 14 -> 21 placeholders/vars (+locationAddress[s], +locationWebURL[s],
// +locationPhone[s], +locationEmail[s], +locationGeoLat[d], +locationGeoLng[d], +locationW3W[s]).
// 🕰️ #604: 21 -> 22 (+timezone[s], after endDateTime).
$stmt->bind_param(
    'issssssisssiiiissssdds',
    $siteId, $eventName, $slug, $description, $startSql, $endSql, $zoneName,
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
