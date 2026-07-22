<?php
// Path: _apps/tasks/api/create.php
/**
 * Tasks API — Create
 *
 *   POST /api/tasks/create
 *   Content-Type: application/json
 *   {
 *     "title":         "Order baptism certificates",  (required)
 *     "description":   "…",                            (optional)
 *     "assignedToID":  12,                              (optional; defaults to caller)
 *     "priority":      "high",                          (optional: low|normal|high|urgent)
 *     "dueDate":       "2026-07-01"                    (optional, YYYY-MM-DD)
 *   }
 *
 * @package   Portal\API\Tasks
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/157
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('tasks:write', sessionNeedsAdmin: false);

$title = trim((string) ($body['title'] ?? ''));
if ($title === '') {
    ApiResponse::error('title is required', 400);
}
$description = (string) ($body['description'] ?? '');

$priority = (string) ($body['priority'] ?? 'normal');
if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true) === false) {
    $priority = 'normal';
}

$callerId    = ApiAuth::actorUserId() ?? 0;
$assignedTo  = isset($body['assignedToID']) === true && (int) $body['assignedToID'] > 0
    ? (int) $body['assignedToID']
    : $callerId;

$dueDate = null;
if (isset($body['dueDate']) === true && trim((string) $body['dueDate']) !== '') {
    $ts = strtotime((string) $body['dueDate']);
    if ($ts === false) {
        ApiResponse::error('dueDate is not a valid date', 400);
    }
    $dueDate = date('Y-m-d', $ts);
}

$siteId = Site::id();

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblTasks '
    . '(siteID, title, description, assignedToID, createdByID, priority, status, dueDate) '
    . 'VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_TASK_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param(
    'issiiss',
    $siteId, $title, $description, $assignedTo, $callerId, $priority, $dueDate
);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_TASK_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create task', 500);
}

Logger::activity('ApiTaskCreate', 'API: created task #' . $newId . ' "' . $title . '"');

ApiResponse::success(['taskID' => $newId], 201);
