<?php
// Path: public_html/tasks/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Tasks API — List
 * -----------------------------------------------------------------------------
 * Returns the current user's open tasks. Admins can filter by ?userID=N.
 *
 *   GET /api/tasks/list?status=open|completed&page=N&limit=N
 *
 * @package   Portal\API
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Site;

ApiResponse::requireAuth();

$db     = App::db();
$siteId = Site::id();
$me     = App::user();
$myId   = (int) ($me['userID'] ?? 0);
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$status = (string) ($_GET['status'] ?? 'open');
if (in_array($status, ['open', 'completed', 'all'], true) === false) {
    $status = 'open';
}

// 🛡️ Non-admins always see only their own tasks
$targetUser = $myId;
if (App::isAdmin() === true && isset($_GET['userID']) === true) {
    $targetUser = (int) $_GET['userID'];
}

$conditions = ['siteID = ?', 'assignedUserID = ?'];
$types      = 'ii';
$params     = [$siteId, $targetUser];
if ($status === 'open') {
    $conditions[] = 'completedAt IS NULL';
} elseif ($status === 'completed') {
    $conditions[] = 'completedAt IS NOT NULL';
}

// Column names match the schema: `dueDate` (not `dueAt`) — an earlier
// draft referenced `dueAt` which doesn't exist on tblTasks (#218 deep
// audit found via check_sql_columns.py SELECT extension).
$sql = 'SELECT taskID, title, description, dueDate, completedAt, createdAt '
     . 'FROM tblTasks WHERE ' . implode(' AND ', $conditions) . ' '
     . 'ORDER BY (completedAt IS NULL) DESC, dueDate ASC, createdAt DESC '
     . 'LIMIT ? OFFSET ?';
$types   .= 'ii';
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ApiResponse::success([
    'count' => count($rows),
    'page'  => $page,
    'limit' => $limit,
    'items' => $rows,
]);
