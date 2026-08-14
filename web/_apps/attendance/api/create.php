<?php
// Path: _apps/attendance/api/create.php
/**
 * -----------------------------------------------------------------------------
 * Attendance API — Create Session 📋
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR admin session) endpoint that records a new
 * attendance-taking session, optionally with per-group headcount breakdowns.
 *
 *   POST /api/v1/attendance   (or POST /api/attendance/create legacy alias)
 *   Content-Type: application/json
 *   {
 *     "serviceTypeID": 3,                        (required, FK tblAttendanceServiceTypes)
 *     "sessionDate":   "2026-07-19",              (required, Y-m-d)
 *     "sessionTime":   "10:00",                    (optional, H:i)
 *     "eventID":       42,                          (optional, FK tblEvents)
 *     "notes":         "Communion Sunday",          (optional)
 *     "counts": [                                   (optional)
 *       { "groupLabel": "Adults",   "headcount": 120, "sortOrder": 1 },
 *       { "groupLabel": "Children", "headcount": 18,  "sortOrder": 2 }
 *     ]
 *   }
 *
 * @package   Portal\API\Attendance
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('attendance:write', sessionNeedsAdmin: true);

$db     = App::db();
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📥 Required fields
// -----------------------------------------------------------------------------
$serviceTypeId = (int) ($body['serviceTypeID'] ?? 0);
if ($serviceTypeId <= 0) {
    ApiResponse::error('serviceTypeID is required', 400);
}

$sessionDateRaw = trim((string) ($body['sessionDate'] ?? ''));
if ($sessionDateRaw === '') {
    ApiResponse::error('sessionDate is required', 400);
}
$sessionDateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $sessionDateRaw);
if ($sessionDateObj === false || $sessionDateObj->format('Y-m-d') !== $sessionDateRaw) {
    ApiResponse::error('sessionDate must be a valid Y-m-d date', 400);
}
$sessionDate = $sessionDateObj->format('Y-m-d');

// 🔍 Verify serviceTypeID exists for this site
$check = $db->prepare(
    'SELECT serviceTypeID FROM tblAttendanceServiceTypes WHERE serviceTypeID = ? AND siteID = ? LIMIT 1'
);
if ($check === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_CREATE_TYPE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$check->bind_param('ii', $serviceTypeId, $siteId);
$check->execute();
$typeExists = $check->get_result()->fetch_assoc() !== null;
$check->close();
if ($typeExists === false) {
    ApiResponse::error('serviceTypeID does not exist for this site', 400);
}

// -----------------------------------------------------------------------------
// 📥 Optional fields
// -----------------------------------------------------------------------------
$sessionTime = null;
if (isset($body['sessionTime']) === true && trim((string) $body['sessionTime']) !== '') {
    $timeRaw = trim((string) $body['sessionTime']);
    $timeObj = \DateTimeImmutable::createFromFormat('H:i', $timeRaw);
    if ($timeObj === false || $timeObj->format('H:i') !== $timeRaw) {
        ApiResponse::error('sessionTime must be a valid H:i time', 400);
    }
    $sessionTime = $timeObj->format('H:i:s');
}

$eventId = null;
if (isset($body['eventID']) === true && $body['eventID'] !== null && $body['eventID'] !== '') {
    $eventId = (int) $body['eventID'];
    $evCheck = $db->prepare(
        'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
    );
    if ($evCheck === false) {
        Logger::errorPlatform('MySQL', 'Error', 'API_ATT_CREATE_EVENT_PREP', $db->error, '');
        ApiResponse::error('Database error', 500);
    }
    $evCheck->bind_param('ii', $eventId, $siteId);
    $evCheck->execute();
    $eventExists = $evCheck->get_result()->fetch_assoc() !== null;
    $evCheck->close();
    if ($eventExists === false) {
        ApiResponse::error('eventID does not exist for this site', 400);
    }
}

$notes = isset($body['notes']) === true ? trim((string) $body['notes']) : null;
if ($notes === '') {
    $notes = null;
}

// 📊 Optional headcount breakdowns
$counts = [];
if (isset($body['counts']) === true && is_array($body['counts']) === true) {
    foreach ($body['counts'] as $idx => $countRow) {
        if (is_array($countRow) === false) {
            ApiResponse::error('Each counts entry must be an object', 400);
        }
        $groupLabel = trim((string) ($countRow['groupLabel'] ?? ''));
        if ($groupLabel === '' || mb_strlen($groupLabel) > 100) {
            ApiResponse::error('counts[' . $idx . '].groupLabel is required and must be ≤100 characters', 400);
        }
        $headcount = (int) ($countRow['headcount'] ?? -1);
        if ($headcount < 0) {
            ApiResponse::error('counts[' . $idx . '].headcount must be an integer ≥ 0', 400);
        }
        $sortOrder = isset($countRow['sortOrder']) === true ? (int) $countRow['sortOrder'] : (int) $idx;
        $counts[] = [
            'groupLabel' => $groupLabel,
            'headcount'  => $headcount,
            'sortOrder'  => $sortOrder,
        ];
    }
}

$creatorId = ApiAuth::actorUserId();

// -----------------------------------------------------------------------------
// 💾 Transaction: session header + count rows
// -----------------------------------------------------------------------------
App::beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO tblAttendanceSessions '
        . '(siteID, serviceTypeID, eventID, sessionDate, sessionTime, notes, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare session insert: ' . $db->error);
    }
    $stmt->bind_param(
        'iiisssi',
        $siteId, $serviceTypeId, $eventId, $sessionDate, $sessionTime, $notes, $creatorId
    );
    if ($stmt->execute() === false) {
        throw new \RuntimeException('Failed to insert session: ' . $stmt->error);
    }
    $sessionId = (int) $stmt->insert_id;
    $stmt->close();

    if (count($counts) > 0) {
        $countStmt = $db->prepare(
            'INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount, sortOrder) '
            . 'VALUES (?, ?, ?, ?)'
        );
        if ($countStmt === false) {
            throw new \RuntimeException('Failed to prepare count insert: ' . $db->error);
        }
        foreach ($counts as $c) {
            $countStmt->bind_param(
                'isii',
                $sessionId, $c['groupLabel'], $c['headcount'], $c['sortOrder']
            );
            if ($countStmt->execute() === false) {
                throw new \RuntimeException('Failed to insert count row: ' . $countStmt->error);
            }
        }
        $countStmt->close();
    }

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_CREATE_FAIL', $ex->getMessage(), '');
    ApiResponse::error('Database error', 500);
}

// 📓 Audit + activity breadcrumb
Logger::audit('tblAttendanceSessions', $sessionId, 'create', null, [
    'siteID'        => $siteId,
    'serviceTypeID' => $serviceTypeId,
    'eventID'       => $eventId,
    'sessionDate'   => $sessionDate,
    'sessionTime'   => $sessionTime,
    'notes'         => $notes,
    'createdByID'   => $creatorId,
    'countsCount'   => count($counts),
]);
Logger::activity('ApiAttendanceCreate', 'API: created attendance session #' . $sessionId);

ApiResponse::success([
    'sessionID' => $sessionId,
    'counts'    => count($counts),
], 201);
