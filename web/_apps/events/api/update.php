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
 *                   isPublic, isFeatured, and (#493 P1) showOnWebsite,
 *                   websiteFrom, websiteUntil.
 *
 * A categoryID or typeID must be this organisation's own (or null for none);
 * anything else is refused with 422 and the same words whether the number is
 * another organisation's or nobody's (#885).
 *
 * THE PUBLIC WEBSITE FIELDS are judged by `PublicWebsite::judgeEventFields()`,
 * the same judgement as the Manage Events form, against what is STORED: a
 * value equal to the stored one is not a change and needs no permission; a
 * real change needs `PublicWebsite::mayPublish()`, which an API key never has
 * (a key is not a person — the #485 precedent), and is refused with 403 and
 * recorded. A body carrying ONLY these fields, all unchanged, is accepted as
 * "nothing to update" rather than an error, because it changed nothing.
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
use Portal\Core\EventCancelReason;
use Portal\Core\Events;
use Portal\Core\GeoLocation;
use Portal\Core\Logger;
use Portal\Core\PublicWebsite;
use Portal\Core\Site;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

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
// 🌐 #493 P1: the website fields and the name are read here too, so "did it
//    CHANGE" (#687) is judged against the row being edited.
$check = $db->prepare(
    'SELECT `eventID`, `eventName`, `timezone`, `showOnWebsite`, `websiteFrom`, `websiteUntil` '
    . 'FROM `tblEvents` WHERE `eventID` = ? AND `siteID` = ? AND `isDeleted` = 0 AND externalFeedID IS NULL LIMIT 1'
);
if ($check === false) {
    ApiResponse::error('Database error', 500);
}
$check->bind_param('ii', $eventId, $siteId);
$check->execute();
$found = $check->get_result()->fetch_assoc();
$check->close();
if ($found === null) {
    ApiResponse::error('Event not found', 404);
}
// 🕰️ The clock this event's times are a reading of (#604): its own zone, or
//    the organisation's when the stored one cannot be opened.
$eventZone = Site::zoneFor((string) $found['timezone']);

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
// ⚠️ Exactly `tblEvents.status`'s ENUM. This list used to offer `archived`,
//    which the column has never had, and omit `postponed`, which it has — so
//    the 400 message told a developer that `archived` was valid and then the
//    UPDATE failed with a 500 when they used it (#676). Checked against the
//    column by tools/audit-checks/check_event_status_matches_column.py.
$validStatuses = ['draft', 'published', 'cancelled', 'postponed'];

$set    = [];
$types  = '';
$params = [];

$newClock = [];
foreach ($columnMap as $col => $type) {
    if (array_key_exists($col, $body) === false) {
        continue;
    }
    $value = $body[$col];

    if ($col === 'status' && in_array((string) $value, $validStatuses, true) === false) {
        ApiResponse::error("status must be one of: " . implode(', ', $validStatuses), 400);
    }
    if (in_array($col, ['startDateTime', 'endDateTime'], true) === true && $value !== null && $value !== '') {
        // 🕐 A READING WITH NO OFFSET IS STORED AS IT STANDS. GIVING IT THE
        //    RIGHT ZONE DOES NOT SAVE IT (#777).
        //
        //    These two columns hold a WALL-CLOCK reading. The code here built
        //    a moment from every value, even one with no offset:
        //
        //        $value = (new \DateTimeImmutable((string) $value, $eventZone))
        //                 ->setTimezone($eventZone)->format('Y-m-d H:i:s');
        //
        //    The zone was the RIGHT one -- the event's own -- and it still
        //    moved the hour, because on the morning the clocks go forward the
        //    hour the reading names does not EXIST in that zone. Measured:
        //    `2026-03-29T01:30` in Europe/London came out `02:30`, and
        //    `2026-03-08T02:30` in America/New_York came out `03:30`.
        //
        //    ⚠️ "WE PASSED THE CORRECT ZONE" IS NOT A DEFENCE. Building a
        //       moment at all is what moves it. An OFFSET in the value is a
        //       zone written into the value itself, so such a value really IS
        //       an instant and is still converted onto the event's clock --
        //       which is what the old comment promised and what an
        //       integration in another country needs.
        //
        //    `create.php` carries the same pair of helpers and the same
        //    reasoning; the two handlers must agree about one column.
        // 📌 Both rules live in `I18n`, the ONE place for each; `create.php`
        //    and this handler must agree about one column.
        if (I18n::inputCarriesOffset((string) $value) === false) {
            $clock = I18n::wallClockFromInput((string) $value);
            if ($clock === null) {
                ApiResponse::error("$col is not a valid timestamp", 400);
            }
            $value = $clock;
        } else {
            // 📌 Same ONE place, same shape rule — see `create.php`.
            $converted = I18n::instantFromInput((string) $value, $eventZone);
            if ($converted === null) {
                ApiResponse::error("$col is not a valid timestamp", 400);
            }
            $value = $converted;
        }
    }
    if (in_array($col, ['isAllDay', 'isPublic', 'isFeatured'], true) === true) {
        $value = (bool) $value === true ? 1 : 0;
    }
    if (in_array($col, ['categoryID', 'typeID'], true) === true) {
        // 🏷️ This organisation's own, or 422 (#885) — see create.php and
        //    `Events::whyCategoryOrTypeRefused()`, the ONE place that decides.
        //    `(int)` of whatever was sent used to go straight into the UPDATE:
        //    a number nobody has was a bare 500, another organisation's was
        //    stored. Only a field that ARRIVED is judged (this loop skips the
        //    rest), so an event already carrying a category is not re-judged
        //    by a request that does not touch it.
        $value = Events::postedIdOrNone($value);
        if ($value === false) {
            ApiResponse::error($col . ' must be a whole number, or null for none. Nothing was changed.', 422);
        }
        $taxonomyRefusal = Events::whyCategoryOrTypeRefused(
            $db,
            $siteId,
            $col === 'categoryID' ? $value : null,
            $col === 'typeID' ? $value : null
        );
        if ($taxonomyRefusal !== null) {
            ApiResponse::error($taxonomyRefusal . ' Nothing was changed.', 422);
        }
    }

    $set[]    = $col . ' = ?';
    $types   .= $type;
    $params[] = $value;
    if ($col === 'startDateTime' || $col === 'endDateTime') {
        $newClock[$col] = $value;
    }
}

// ⏱️ AN END BEFORE ITS START IS REFUSED HERE TOO (#786, #777 round 10).
//    This handler had NO comparison at all -- not a weakened one, none -- while
//    `api-spec.json` documents its body as "Subset of EventCreate fields +
//    eventID" and `EventCreate.endDateTime` promises "it must read as a date
//    AND be no earlier than `startDateTime`, or the request is refused with
//    400". So the published contract said one thing and the endpoint did
//    another, and `create.php` DOES enforce it: **two halves of one documented
//    rule disagreeing.**
//
//    It is three lines now and was not before: until #777 these columns held a
//    mixture of readings and converted moments, so comparing them meant
//    deciding what they were first. Both sides are readings on the event's own
//    clock by this point, and `Y-m-d H:i:s` sorts the way a clock does
//    (#435's fix), so this is a TEXT compare and builds no moment.
//
//    ⚠️ ONE SIDE MAY BE ABSENT, which is why the stored counterpart is read.
//       A request that changes only the start must still be refused if it
//       would move it past the existing end.
//
//    📌 "BEFORE", NOT "BEFORE OR EQUAL": `calendar/manage/save.php` records
//       that an equal start and end is allowed -- "a moment in a diary is a
//       reasonable thing to record" -- and `EventCreate`'s own description
//       says "equal start and end is accepted". Three places must agree.
if (isset($newClock) === true && count($newClock) > 0) {
    $haveStart = $newClock['startDateTime'] ?? null;
    $haveEnd   = array_key_exists('endDateTime', $newClock) === true ? $newClock['endDateTime'] : null;
    if ($haveStart === null || ($haveEnd === null && array_key_exists('endDateTime', $newClock) === false)) {
        $stored = $db->prepare(
            // 📌 THE SAME GUARD AS THE FETCH AT THE TOP OF THIS FILE,
            //    including `externalFeedID IS NULL`: an imported event is not
            //    this endpoint's to read or change (#514), and
            //    `check_event_visibility.py` is right to insist on it even
            //    though the caller has already been authorised above.
            'SELECT startDateTime, endDateTime FROM tblEvents '
            . 'WHERE eventID = ? AND siteID = ? AND isDeleted = 0 '
            . 'AND externalFeedID IS NULL LIMIT 1'
        );
        if ($stored !== false) {
            $stored->bind_param('ii', $eventId, $siteId);
            $stored->execute();
            $wasRow = $stored->get_result()->fetch_assoc();
            $stored->close();
            if (is_array($wasRow) === true) {
                if ($haveStart === null) {
                    $haveStart = $wasRow['startDateTime'] === null ? null : (string) $wasRow['startDateTime'];
                }
                if (array_key_exists('endDateTime', $newClock) === false) {
                    $haveEnd = $wasRow['endDateTime'] === null ? null : (string) $wasRow['endDateTime'];
                }
            }
        }
    }
    if ($haveStart !== null && $haveEnd !== null && $haveEnd < $haveStart) {
        ApiResponse::error('endDateTime must be no earlier than startDateTime', 400);
    }
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

// 🌐 THE PUBLIC WEBSITE (#493 P1). Only keys that ARRIVED are judged (#677).
$websiteArrived = [];
foreach (PublicWebsite::EVENT_FIELDS as $websiteField) {
    if (array_key_exists($websiteField, $body) === true) {
        $websiteArrived[$websiteField] = $body[$websiteField];
    }
}
$websiteActor  = ApiAuth::actorUserId();
$websiteJudged = PublicWebsite::judgeEventFields(
    $websiteArrived,
    [
        'showOnWebsite' => (int) $found['showOnWebsite'],
        'websiteFrom'   => $found['websiteFrom'] === null ? null : (string) $found['websiteFrom'],
        'websiteUntil'  => $found['websiteUntil'] === null ? null : (string) $found['websiteUntil'],
    ],
    PublicWebsite::mayPublish($db, (int) ($websiteActor ?? 0), $siteId)
);
if ($websiteJudged['ok'] === false) {
    if ($websiteJudged['kind'] === 'forbidden') {
        PublicWebsite::recordRefusal($eventId, (string) $found['eventName'], $websiteJudged, $websiteActor,
            ApiAuth::source() === 'apikey' ? 'the events API, with an API key' : 'the events API');
        ApiResponse::error(
            ApiAuth::source() === 'apikey'
                ? 'An API key cannot change an event\'s "Show on the public website" switch or its website dates: a key is not a person and cannot hold that permission. Nothing was updated.'
                : $websiteJudged['reason'],
            403
        );
    }
    ApiResponse::error($websiteJudged['reason'], 422);
}
if ($websiteJudged['changed'] === true) {
    // Only a CHANGE is written, and the judgement allows one only for somebody
    // who may publish; otherwise these three columns are not in the SET at all.
    $set[]    = '`showOnWebsite` = ?';
    $set[]    = '`websiteFrom` = ?';
    $set[]    = '`websiteUntil` = ?';
    $types   .= 'iss';
    $params[] = $websiteJudged['values']['showOnWebsite'];
    $params[] = $websiteJudged['values']['websiteFrom'];
    $params[] = $websiteJudged['values']['websiteUntil'];
}

// Website fields sent, all equal to what is stored, and nothing else: nothing
// changed, which is not the caller's mistake, so it answers exactly as a
// successful update does (the same shape) without touching the row — not even
// `updatedAt`, which would claim an edit that never happened.
$nothingToWrite = count($set) === 0 && $websiteArrived !== [];
if (count($set) === 0 && $nothingToWrite === false) {
    ApiResponse::error('No updatable fields in request body', 400);
}

if ($nothingToWrite === false) {
    $set[]    = 'updatedAt = NOW()';
    $types   .= 'ii';
    $params[] = $eventId;
    $params[] = $siteId;

    // Imported events are read-only (#514 D5); the existence check above already refuses one, so this
    // statement is unreachable for an imported row — the condition is repeated here so the statement
    // still carries its own marker for tools/audit-checks/check_event_visibility.py.
    //
    // ♻️ `isDeleted = 0` on the write itself too (#1000, round 3 of #998's
    //    check): the existence check at the top of this file reads without a
    //    lock, so a date hidden after it would otherwise have its status
    //    changed here while `EventCancelReason::write()` — which never touches
    //    a hidden date — left its reason alone, putting the two out of step on
    //    a date that comes back with both (D-146, D-147).
    $sql = 'UPDATE tblEvents SET ' . implode(', ', $set)
         . ' WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL LIMIT 1';
    // 🔒 ONE TRANSACTION for the row and its reason (#998, round 2 of its
    //    check): `EventCancelReason::write()` must run inside its caller's
    //    transaction, or its locking read is decoration (#697) and the status
    //    and the reason could be seen apart. It is opened BEFORE any locking
    //    read. Every early answer below ROLLS BACK FIRST, because
    //    `ApiResponse::error()` ends the request without unwinding a `try`,
    //    and `Logger` writes on this same connection — a log line written
    //    inside an open transaction would be rolled back with it (#694).
    $db->begin_transaction();
    try {
        // 🔒 THE SECOND ASK, UNDER THE LOCK (round 3 of #998's check). The
        //    first ask, at the top of this file, is a plain read: it decides
        //    the 404 cheaply and supplies what the checks above need, but the
        //    date can be hidden — by the Delete button, a pattern change or a
        //    second API call — between it and this write. Read again here,
        //    `FOR UPDATE`, inside the transaction (#697: a lock outside one is
        //    decoration), and answer exactly as the top does for a hidden or
        //    missing event: 404, nothing written.
        $live = $db->prepare(
            'SELECT `isDeleted` FROM `tblEvents` WHERE `eventID` = ? AND `siteID` = ? '
            . 'AND `externalFeedID` IS NULL FOR UPDATE'
        );
        $live->bind_param('ii', $eventId, $siteId);
        $live->execute();
        $liveRow = $live->get_result()->fetch_assoc();
        $live->close();
        if ($liveRow === null || (int) $liveRow['isDeleted'] === 1) {
            $db->rollback();
            ApiResponse::error('Event not found', 404);
        }

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $db->rollback();
            Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_UPDATE_PREP', $db->error, '');
            ApiResponse::error('Database error', 500);
        }
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok === false) {
            $db->rollback();
            Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_UPDATE_FAIL', $db->error, '');
            ApiResponse::error('Failed to update event', 500);
        }

        // ✍️ #998: a status that no longer carries a reason loses the reason.
        //    This endpoint takes no reason of its own (the published
        //    description has no such field, and adding one is #1002), so it
        //    only ever EMPTIES one: through the one writer,
        //    `EventCancelReason::write()`, with "no reason sent", which keeps a
        //    reason while the status still carries one and removes it
        //    otherwise. Without this, a date the API set back to published
        //    kept its old reason, which would reappear — out of date — the
        //    next time anybody cancelled it. Asked only when a status was
        //    sent, so an update of the name alone touches nothing else.
        if (array_key_exists('status', $body) === true) {
            EventCancelReason::write($db, $siteId, [$eventId], false, null);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }

    Logger::activity('ApiEventUpdate', 'API: updated event #' . $eventId);
    PublicWebsite::recordChange($eventId, (string) $found['eventName'], $websiteJudged['changes'], $websiteActor, 'the events API');
}

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
