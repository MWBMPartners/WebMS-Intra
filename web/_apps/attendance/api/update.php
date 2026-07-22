<?php
// Path: _apps/attendance/api/update.php
/**
 * -----------------------------------------------------------------------------
 * Attendance API — Update Session 📋
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR admin session) endpoint that patches an
 * existing attendance session. Only fields present in the body are touched.
 * When `counts` is present, ALL existing headcount rows for the session are
 * replaced (delete + re-insert) inside the same transaction.
 *
 *   PUT/PATCH /api/v1/attendance/{id}
 *   (or POST /api/attendance/update?id=N — legacy alias, {"sessionID": N} in body)
 *
 * Updatable fields: sessionDate, sessionTime, serviceTypeID, eventID, notes, counts.
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

$sessionId = (int) ($_GET['id'] ?? $body['sessionID'] ?? 0);
if ($sessionId <= 0) {
    ApiResponse::error('sessionID is required', 400);
}

$db     = App::db();
$siteId = Site::id();

// 🔍 Fetch the existing (non-deleted) row for this site — 404 otherwise
$fetch = $db->prepare(
    'SELECT sessionID, siteID, serviceTypeID, eventID, sessionDate, sessionTime, notes, isDeleted '
    . 'FROM tblAttendanceSessions WHERE sessionID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($fetch === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_UPDATE_FETCH_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$fetch->bind_param('ii', $sessionId, $siteId);
$fetch->execute();
$old = $fetch->get_result()->fetch_assoc();
$fetch->close();
if ($old === null) {
    ApiResponse::error('Attendance session not found', 404);
}

// -----------------------------------------------------------------------------
// 🛠️ Validate + collect provided scalar fields
// -----------------------------------------------------------------------------
$new = $old;
$set    = [];
$types  = '';
$params = [];

if (array_key_exists('serviceTypeID', $body) === true) {
    $serviceTypeId = (int) $body['serviceTypeID'];
    $check = $db->prepare(
        'SELECT serviceTypeID FROM tblAttendanceServiceTypes WHERE serviceTypeID = ? AND siteID = ? LIMIT 1'
    );
    if ($check === false) {
        ApiResponse::error('Database error', 500);
    }
    $check->bind_param('ii', $serviceTypeId, $siteId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc() !== null;
    $check->close();
    if ($exists === false) {
        ApiResponse::error('serviceTypeID does not exist for this site', 400);
    }
    $set[]    = 'serviceTypeID = ?';
    $types   .= 'i';
    $params[] = $serviceTypeId;
    $new['serviceTypeID'] = $serviceTypeId;
}

if (array_key_exists('eventID', $body) === true) {
    $eventId = ($body['eventID'] === null || $body['eventID'] === '') ? null : (int) $body['eventID'];
    if ($eventId !== null) {
        $evCheck = $db->prepare(
            'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
        );
        if ($evCheck === false) {
            ApiResponse::error('Database error', 500);
        }
        $evCheck->bind_param('ii', $eventId, $siteId);
        $evCheck->execute();
        $evExists = $evCheck->get_result()->fetch_assoc() !== null;
        $evCheck->close();
        if ($evExists === false) {
            ApiResponse::error('eventID does not exist for this site', 400);
        }
    }
    $set[]    = 'eventID = ?';
    $types   .= 'i';
    $params[] = $eventId;
    $new['eventID'] = $eventId;
}

if (array_key_exists('sessionDate', $body) === true) {
    $sessionDateRaw = trim((string) $body['sessionDate']);
    $sessionDateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $sessionDateRaw);
    if ($sessionDateObj === false || $sessionDateObj->format('Y-m-d') !== $sessionDateRaw) {
        ApiResponse::error('sessionDate must be a valid Y-m-d date', 400);
    }
    $set[]    = 'sessionDate = ?';
    $types   .= 's';
    $params[] = $sessionDateObj->format('Y-m-d');
    $new['sessionDate'] = $sessionDateObj->format('Y-m-d');
}

if (array_key_exists('sessionTime', $body) === true) {
    $sessionTime = null;
    if ($body['sessionTime'] !== null && trim((string) $body['sessionTime']) !== '') {
        $timeRaw = trim((string) $body['sessionTime']);
        $timeObj = \DateTimeImmutable::createFromFormat('H:i', $timeRaw);
        if ($timeObj === false || $timeObj->format('H:i') !== $timeRaw) {
            ApiResponse::error('sessionTime must be a valid H:i time', 400);
        }
        $sessionTime = $timeObj->format('H:i:s');
    }
    $set[]    = 'sessionTime = ?';
    $types   .= 's';
    $params[] = $sessionTime;
    $new['sessionTime'] = $sessionTime;
}

if (array_key_exists('notes', $body) === true) {
    $notes = $body['notes'] === null ? null : trim((string) $body['notes']);
    if ($notes === '') {
        $notes = null;
    }
    $set[]    = 'notes = ?';
    $types   .= 's';
    $params[] = $notes;
    $new['notes'] = $notes;
}

// 📊 Optional replace-all counts payload
$hasCounts = array_key_exists('counts', $body) === true && is_array($body['counts']) === true;
$counts    = [];
if ($hasCounts === true) {
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

if (count($set) === 0 && $hasCounts === false) {
    ApiResponse::error('No updatable fields in request body', 400);
}

$actorId = ApiAuth::actorUserId() ?? 0;

// -----------------------------------------------------------------------------
// 💾 Transaction: scalar update + optional counts replace-all
// -----------------------------------------------------------------------------
App::beginTransaction();
try {
    if (count($set) > 0) {
        $set[]    = 'updatedByID = ?';
        $types   .= 'i';
        $params[] = $actorId;

        $sql = 'UPDATE tblAttendanceSessions SET ' . implode(', ', $set)
             . ' WHERE sessionID = ? AND siteID = ?';
        $types   .= 'ii';
        $params[] = $sessionId;
        $params[] = $siteId;

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare session update: ' . $db->error);
        }
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute() === false) {
            throw new \RuntimeException('Failed to update session: ' . $stmt->error);
        }
        $stmt->close();
    }

    if ($hasCounts === true) {
        $delStmt = $db->prepare('DELETE FROM tblAttendanceCounts WHERE sessionID = ?');
        if ($delStmt === false) {
            throw new \RuntimeException('Failed to prepare count delete: ' . $db->error);
        }
        $delStmt->bind_param('i', $sessionId);
        if ($delStmt->execute() === false) {
            throw new \RuntimeException('Failed to clear existing counts: ' . $delStmt->error);
        }
        $delStmt->close();

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
        $new['countsCount'] = count($counts);
    }

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_UPDATE_FAIL', $ex->getMessage(), '');
    ApiResponse::error('Database error', 500);
}

Logger::audit('tblAttendanceSessions', $sessionId, 'update', $old, $new);
Logger::activity('ApiAttendanceUpdate', 'API: updated attendance session #' . $sessionId);

ApiResponse::success(['sessionID' => $sessionId], 200);
