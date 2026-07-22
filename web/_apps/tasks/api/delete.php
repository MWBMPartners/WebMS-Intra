<?php
// Path: _apps/tasks/api/delete.php
/**
 * Tasks API — Delete (hard delete)
 *
 *   POST /api/tasks/delete
 *   { "taskID": 42 }
 *
 *   Only the creator or an admin can delete a task.
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

$db = App::db();
$stmt = $db->prepare('SELECT createdByID FROM tblTasks WHERE taskID = ? AND siteID = ? LIMIT 1');
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
if ((int) $task['createdByID'] !== $callerId && App::isAdmin() === false) {
    ApiResponse::error('Only the creator or an admin can delete this task', 403);
}

$stmt = $db->prepare('DELETE FROM tblTasks WHERE taskID = ? AND siteID = ?');
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $id, $siteId);
$stmt->execute();
$stmt->close();

Logger::activity('ApiTaskDelete', 'API: deleted task #' . $id);

ApiResponse::success(['taskID' => $id, 'deleted' => true]);
