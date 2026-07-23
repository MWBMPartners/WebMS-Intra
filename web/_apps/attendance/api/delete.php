<?php
// Path: _apps/attendance/api/delete.php
/**
 * -----------------------------------------------------------------------------
 * Attendance API — Delete (soft) Session 📋
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR admin session) endpoint that soft-deletes an
 * attendance session (sets isDeleted = 1). Headcount rows are left in place
 * (they cascade-delete only if the parent row is ever hard-deleted).
 *
 *   DELETE /api/v1/attendance/{id}
 *   (or POST /api/attendance/delete?id=N — legacy alias, {"sessionID": N} in body)
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
$actorId = ApiAuth::actorUserId();

// 🔍 Fetch the row first so the audit trail retains full oldData
$fetch = $db->prepare(
    'SELECT sessionID, siteID, serviceTypeID, eventID, sessionDate, sessionTime, notes '
    . 'FROM tblAttendanceSessions WHERE sessionID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($fetch === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_DELETE_FETCH_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$fetch->bind_param('ii', $sessionId, $siteId);
$fetch->execute();
$old = $fetch->get_result()->fetch_assoc();
$fetch->close();
if ($old === null) {
    ApiResponse::error('Attendance session not found', 404);
}

$stmt = $db->prepare(
    'UPDATE tblAttendanceSessions SET isDeleted = 1, updatedByID = ? '
    . 'WHERE sessionID = ? AND siteID = ? AND isDeleted = 0'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_DELETE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('iii', $actorId, $sessionId, $siteId);
$ok       = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ATT_DELETE_FAIL', $db->error, '');
    ApiResponse::error('Failed to delete attendance session', 500);
}
if ($affected === 0) {
    ApiResponse::error('Attendance session not found or already deleted', 404);
}

Logger::audit('tblAttendanceSessions', $sessionId, 'delete', $old, null);
Logger::activity('ApiAttendanceDelete', 'API: soft-deleted attendance session #' . $sessionId);

ApiResponse::success(['sessionID' => $sessionId, 'deleted' => true], 200);
