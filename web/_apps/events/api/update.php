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
$check = $db->prepare(
    'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
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
    'eventName'     => 's',
    'description'   => 's',
    'startDateTime' => 's',
    'endDateTime'   => 's',
    'isAllDay'      => 'i',
    'locationName'  => 's',
    'categoryID'    => 'i',
    'typeID'        => 'i',
    'status'        => 's',
    'isPublic'      => 'i',
    'isFeatured'    => 'i',
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

if (count($set) === 0) {
    ApiResponse::error('No updatable fields in request body', 400);
}

$set[]    = 'updatedAt = NOW()';
$types   .= 'ii';
$params[] = $eventId;
$params[] = $siteId;

$sql = 'UPDATE tblEvents SET ' . implode(', ', $set)
     . ' WHERE eventID = ? AND siteID = ? LIMIT 1';
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

ApiResponse::success(['eventID' => $eventId], 200);
