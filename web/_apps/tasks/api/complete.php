<?php
// Path: _apps/tasks/api/complete.php
/**
 * Tasks API — Mark complete
 *
 *   POST /api/tasks/complete
 *   { "taskID": 42 }
 *
 *   Only the task assignee or an admin can complete it.
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

$id = (int) ($body['taskID'] ?? 0);
if ($id <= 0) {
    ApiResponse::error('taskID is required', 400);
}

$siteId   = Site::id();
$callerId = ApiAuth::actorUserId() ?? 0;
$isAdmin  = App::isAdmin();

$db = App::db();
$stmt = $db->prepare('SELECT assignedToID, status FROM tblTasks WHERE taskID = ? AND siteID = ? LIMIT 1');
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $id, $siteId);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($task === null) {
    ApiResponse::error('Task not found', 404);
}
if ((int) $task['assignedToID'] !== $callerId && $isAdmin === false) {
    ApiResponse::error('Only the assignee or an admin can complete this task', 403);
}
if ((string) $task['status'] === 'completed') {
    ApiResponse::error('Task is already completed', 409);
}

$stmt = $db->prepare('UPDATE tblTasks SET status = "completed", completedAt = NOW() WHERE taskID = ? AND siteID = ?');
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $id, $siteId);
$stmt->execute();
$stmt->close();

Logger::activity('ApiTaskComplete', 'API: completed task #' . $id);

ApiResponse::success(['taskID' => $id, 'status' => 'completed']);
