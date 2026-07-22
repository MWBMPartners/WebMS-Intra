<?php
// Path: _apps/leadership/api/unassign.php
/**
 * Leadership API — End an assignment (sets endDate = today, isActive = 0).
 *
 *   POST /api/leadership/unassign
 *   {
 *     "assignmentID": 42,
 *     "endDate":      "2026-07-31"   (optional; defaults to today)
 *   }
 *
 * @package   Portal\API\Leadership
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/157
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('leadership:write');

$id = (int) ($body['assignmentID'] ?? 0);
if ($id <= 0) {
    ApiResponse::error('assignmentID is required', 400);
}

$endDate = date('Y-m-d');
if (isset($body['endDate']) === true && trim((string) $body['endDate']) !== '') {
    $ts = strtotime((string) $body['endDate']);
    if ($ts === false) {
        ApiResponse::error('endDate is not a valid date', 400);
    }
    $endDate = date('Y-m-d', $ts);
}

$siteId    = Site::id();
$updaterId = ApiAuth::actorUserId() ?? 0;

$db = App::db();
$stmt = $db->prepare(
    'UPDATE tblLeadershipAssignments SET endDate = ?, isActive = 0, updatedByID = ? '
    . 'WHERE assignmentID = ? AND siteID = ?'
);
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('siii', $endDate, $updaterId, $id, $siteId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
if ($ok === false) {
    ApiResponse::error('Failed to unassign', 500);
}
if ($affected === 0) {
    ApiResponse::error('Assignment not found', 404);
}

Logger::activity('ApiLeadershipUnassign', 'API: ended assignment #' . $id . ' on ' . $endDate);

ApiResponse::success(['assignmentID' => $id, 'endDate' => $endDate]);
