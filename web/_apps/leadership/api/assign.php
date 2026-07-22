<?php
// Path: _apps/leadership/api/assign.php
/**
 * Leadership API — Assign a role to a user OR external person.
 *
 *   POST /api/leadership/assign
 *   {
 *     "roleID":      4,                       (required)
 *     "userID":      12,                      (optional — internal portal user)
 *     "personName":  "Jane Smith",            (required when userID is omitted)
 *     "personEmail": "jane@example.com",      (optional, for external)
 *     "startDate":   "2026-07-01",            (optional)
 *     "endDate":     "2027-06-30",            (optional)
 *     "notes":       "Term covers …"          (optional)
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

$roleId = (int) ($body['roleID'] ?? 0);
if ($roleId <= 0) {
    ApiResponse::error('roleID is required', 400);
}
$userId = isset($body['userID']) === true && (int) $body['userID'] > 0
    ? (int) $body['userID']
    : null;
$personName  = trim((string) ($body['personName']  ?? ''));
$personEmail = trim((string) ($body['personEmail'] ?? ''));
if ($userId === null && $personName === '') {
    ApiResponse::error('Either userID or personName must be supplied', 400);
}

$startDate = null;
if (isset($body['startDate']) === true && trim((string) $body['startDate']) !== '') {
    $ts = strtotime((string) $body['startDate']);
    if ($ts === false) {
        ApiResponse::error('startDate is not a valid date', 400);
    }
    $startDate = date('Y-m-d', $ts);
}
$endDate = null;
if (isset($body['endDate']) === true && trim((string) $body['endDate']) !== '') {
    $ts = strtotime((string) $body['endDate']);
    if ($ts === false) {
        ApiResponse::error('endDate is not a valid date', 400);
    }
    $endDate = date('Y-m-d', $ts);
}
$notes = (string) ($body['notes'] ?? '');

$siteId    = Site::id();
$creatorId = ApiAuth::actorUserId();

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblLeadershipAssignments '
    . '(siteID, roleID, userID, personName, personEmail, startDate, endDate, notes, isActive, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_LEADERSHIP_ASSIGN_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param(
    'iiisssssi',
    $siteId, $roleId, $userId, $personName, $personEmail, $startDate, $endDate, $notes, $creatorId
);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_LEADERSHIP_ASSIGN_FAIL', $db->error, '');
    ApiResponse::error('Failed to assign role', 500);
}

Logger::activity('ApiLeadershipAssign', 'API: assignment #' . $newId . ' roleID=' . $roleId);

ApiResponse::success(['assignmentID' => $newId], 201);
