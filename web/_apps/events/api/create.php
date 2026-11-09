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
 *     "showOnWebsite":  false,                   (optional, default false — #493)
 *     "websiteFrom":    "2026-05-25",            (optional, YYYY-MM-DD or null)
 *     "websiteUntil":   "2026-06-01"             (optional, YYYY-MM-DD or null)
 *   }
 *
 * THE PUBLIC WEBSITE (#493 P1; D-057, D-077, D-078). The last three are judged
 * by `PublicWebsite::judgeEventFields()`, the same judgement as the Manage
 * Events form. A new event is OFF the website unless the caller may publish
 * and asks for it. An API KEY may not: a key is not a person and cannot hold
 * the permission (the #485 precedent), so a key asking to switch it on, or to
 * set a date, is refused with 403 and the refusal recorded; a key sending the
 * default (`false`, no dates) is not a change and is accepted.
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
use Portal\Core\PublicWebsite;
use Portal\Core\Site;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

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
// 🕐 A READING WITH NO OFFSET IS NOT A MOMENT, AND GIVING IT THE RIGHT ZONE
//    DOES NOT SAVE IT (#777). This is the part that is easy to get wrong, so
//    it is spelled out.
//
//    `tblEvents.startDateTime` holds a WALL-CLOCK reading. The old code built
//    a moment from every value, even one with no offset in it:
//
//        $startAt = (new \DateTimeImmutable($startDateTime, $zone))->setTimezone($zone);
//
//    That looks safe because the zone is the RIGHT one -- the event's own. It
//    is not. On the morning the clocks go forward the hour the reading names
//    does not EXIST in that zone, so PHP moves it, and the moved value is what
//    `format('Y-m-d H:i:s')` writes at line 162. Measured:
//
//        London,   2026-03-29T01:30 in Europe/London     -> 2026-03-29 02:30:00
//        New York, 2026-03-08T02:30 in America/New_York  -> 2026-03-08 03:30:00
//
//    ⚠️ SO "WE PASSED THE CORRECT ZONE" IS NOT A DEFENCE. Building a moment AT
//       ALL is what moves it. #777's rule cuts the other way here: a reading
//       becomes a moment when something gives it a date AND A ZONE, and for a
//       value that is going to be STORED as a wall-clock reading that is
//       precisely what must not happen.
//
//    But an offset IS a zone, written into the value itself, so a value
//    carrying one (`...+02:00`, or a trailing `Z`) really is an instant and
//    must be converted onto the event's clock -- which is what the comment
//    above promises and what an integration in another country needs. That
//    distinction is the whole fix:
//
//      * no offset -> a reading on the event's own clock; store the text.
//      * an offset -> an instant; convert it onto the event's clock, as before.
// 📌 Both rules live in `I18n` -- the ONE place for each -- because this
//    handler, `update.php`, `calendar/submit-save.php` and the CSV import all
//    had their own copy for about an hour (#768's shape, caught early).
// 📌 ONE READING EACH, AND NO `DateTimeImmutable` LEFT IN THIS FILE.
//    `I18n::instantFromInput()` is the ONE place an offset-bearing value
//    becomes a stored reading, and it requires the SAME SHAPE as the other
//    half. Without that, anything ending in something offset-shaped went
//    straight to the constructor: `01/06/2026 10:00 +0100` stored 6 JANUARY
//    for 1 June, and a bare `Z` created an event at the current moment
//    (#777 round 8).
//
// ⚠️ `$startAt` AND `$endAt` ARE GONE ON PURPOSE. Round 8's fix made both of
//    them always null, so the `$startAt->format(...)` arms below became dead
//    code -- harmless while the guard in front of them held, and a fatal error
//    under `strict_types` the moment anybody simplified that guard. Two
//    variables for one value is how that trap gets set; there is now one.
$readingFor = static function (string $raw, \DateTimeZone $zone): ?string {
    return I18n::inputCarriesOffset($raw) === false
        ? I18n::wallClockFromInput($raw)
        : I18n::instantFromInput($raw, $zone);
};

$startSql = $readingFor($startDateTime, $zone);
if ($startSql === null) {
    ApiResponse::error('startDateTime is not a valid timestamp', 400);
}

$endDateTime = isset($body['endDateTime']) === true && $body['endDateTime'] !== ''
    ? trim((string) $body['endDateTime'])
    : null;
$endSql = null;
if ($endDateTime !== null) {
    $endSql = $readingFor($endDateTime, $zone);
    // ⏱️ Two readings on the SAME clock compare correctly as TEXT (#435's own
    //    fix); `Y-m-d H:i:s` sorts the way the clock does. Both sides are
    //    readings on the event's clock by this point -- an offset-bearing value
    //    has already been put onto it -- so there is nothing left to convert.
    if ($endSql === null || $endSql < $startSql) {
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

// 🌐 The public-website fields (#493 P1). Only keys that ARRIVED are judged
//    (#677); a new event's starting point is OFF with no dates, so sending
//    exactly that is not a change and needs no permission. The person is the
//    signed-in account in session mode and NOBODY for an API key.
$websiteArrived = [];
foreach (PublicWebsite::EVENT_FIELDS as $websiteField) {
    if (array_key_exists($websiteField, $body) === true) {
        $websiteArrived[$websiteField] = $body[$websiteField];
    }
}
$websiteActor  = ApiAuth::actorUserId();
$websiteJudged = PublicWebsite::judgeEventFields(
    $websiteArrived,
    PublicWebsite::NEW_EVENT_VALUES,
    PublicWebsite::mayPublish(App::db(), (int) ($websiteActor ?? 0), Site::id())
);
if ($websiteJudged['ok'] === false) {
    if ($websiteJudged['kind'] === 'forbidden') {
        PublicWebsite::recordRefusal(null, $eventName, $websiteJudged, $websiteActor,
            ApiAuth::source() === 'apikey' ? 'the events API, with an API key' : 'the events API');
        ApiResponse::error(
            ApiAuth::source() === 'apikey'
                ? 'An API key cannot change an event\'s "Show on the public website" switch or its website dates: a key is not a person and cannot hold that permission. Leave showOnWebsite, websiteFrom and websiteUntil out. Nothing was created.'
                : $websiteJudged['reason'],
            403
        );
    }
    ApiResponse::error($websiteJudged['reason'], 422);
}
$websiteValues = $websiteJudged['values'];

// 🐢 Slug from name (server-side; truncated to 100 chars to fit the column)
$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $eventName), '-'));
$slug = substr($slug !== '' ? $slug : 'event-' . bin2hex(random_bytes(4)), 0, 100);

$siteId    = Site::id();
// 🕐 `$startSql` and `$endSql` were settled above: a reading with no offset is
//    stored EXACTLY as it was sent (#777), and only a value that carried an
//    offset went through a moment, because that one really was an instant.
$creatorId = ApiAuth::actorUserId();

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblEvents '
    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, timezone, '
    . 'isAllDay, locationName, status, isPublic, isFeatured, categoryID, typeID, createdByID, '
    . 'locationAddress, locationWebURL, locationPhone, locationEmail, locationGeoLat, locationGeoLng, locationW3W, '
    . '`showOnWebsite`, `websiteFrom`, `websiteUntil`) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
// 📍 #456 Chunk A: 14 -> 21 placeholders/vars (+locationAddress[s], +locationWebURL[s],
// +locationPhone[s], +locationEmail[s], +locationGeoLat[d], +locationGeoLng[d], +locationW3W[s]).
// 🕰️ #604: 21 -> 22 (+timezone[s], after endDateTime).
// 🌐 #493 P1: 22 -> 25 (+showOnWebsite[i], +websiteFrom[s], +websiteUntil[s]).
$stmt->bind_param(
    'issssssisssiiiissssddsiss',
    $siteId, $eventName, $slug, $description, $startSql, $endSql, $zoneName,
    $isAllDay, $locationName, $status, $isPublic, $isFeatured,
    $categoryId, $typeId, $creatorId,
    $locationAddress, $locationWebURL, $locationPhone, $locationEmail, $locationGeoLat, $locationGeoLng, $locationW3W,
    $websiteValues['showOnWebsite'], $websiteValues['websiteFrom'], $websiteValues['websiteUntil']
);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create event', 500);
}

Logger::activity('ApiEventCreate', 'API: created event #' . $newId . ' "' . $eventName . '"');
PublicWebsite::recordChange($newId, $eventName, $websiteJudged['changes'], $websiteActor, 'the events API, a new event');

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
