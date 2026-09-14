<?php
// Path: _apps/tasks/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Tasks API — List
 * -----------------------------------------------------------------------------
 * Session caller: returns the signed-in member's own tasks (an admin may
 * widen this to one other member with ?userID=N). API key caller: returns
 * every task in the key's own site by default, or one member's tasks with
 * ?userID=N — a bearer key has no session user of its own to fall back to.
 *
 *   GET /api/tasks/list?status=open|completed&page=N&limit=N
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
use Portal\Core\Site;

ApiAuth::requireRead('tasks:read');

$db     = App::db();
$siteId = Site::id();
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$status = (string) ($_GET['status'] ?? 'open');
if (in_array($status, ['open', 'completed', 'all'], true) === false) {
    $status = 'open';
}

// 🔑 A bearer API key carries no session, so App::user() (session-only)
// always returned null for one — that used to leave $myId at 0 and filter
// every bearer request down to "assignedToID = 0", a column value no real
// task ever has. The column-name fix below made the query runnable again,
// which uncovered this: a valid tasks:read key stopped getting a crash and
// started getting an always-empty 200 instead — still wrong, just wrong
// quietly rather than loudly. ApiAuth::requireRead() above already resolved
// which mode this request used and, for bearer mode, already pinned
// Site::id() to the key's own site, so the site scope on every row below
// stays correct either way — only WHICH user's tasks to show needs to
// branch. This is the same ApiAuth::source() test delete.php and
// complete.php in this folder already use — they check for the session
// side ('session', so a key can act on any task in its site); this checks
// for the key side ('apikey') instead, for the same reason. Also mirrors
// the "a key sees the whole site by default, optionally narrowed by a
// filter" shape expenses/api/list.php already uses for its own ?all=true
// admin case.
$isBearer = ApiAuth::source() === 'apikey';
$myId     = $isBearer === true ? 0 : (int) (ApiAuth::actorUserId() ?? 0);

// 🛡️ Non-admins always see only their own tasks. A session admin can widen
// this to one other member via ?userID, same as before. A bearer key can
// do the same, or — with no ?userID given — see every task in its own
// (already site-pinned) site rather than a session-less "task 0" that can
// never match a real row.
$targetUser     = $myId;
$scopeToOneUser = true;
if (isset($_GET['userID']) === true && ($isBearer === true || App::isAdmin() === true)) {
    $targetUser = (int) $_GET['userID'];
} elseif ($isBearer === true) {
    $scopeToOneUser = false;
}

// WHAT WAS WRONG: this filtered on `assignedUserID`, which does not exist on
// tblTasks — the real column is `assignedToID` (see its CREATE TABLE in
// full_schema.sql; create.php and complete.php in this same folder already
// use the right name — delete.php never touches this column at all, it
// looks up createdByID instead). Under this app's strict mysqli reporting
// (set in bootstrap.php), the mismatched column threw the moment
// $db->prepare() ran, well before the `$stmt === false` check below could
// catch it — so every call to /api/tasks/list failed with a server error,
// for an administrator and a member alike, confirmed against a real
// MySQL 8.0.36 database on 13 September 2026.
//
// `isDeleted = 0` matches the filter the web page's own task list already
// applies (web/_apps/tasks/index.php) — nothing that writes to this app
// currently sets isDeleted = 1 (the API's own delete, delete.php, is a hard
// DELETE, not a soft one), so today this excludes zero rows. The column is
// filtered anyway so a future soft-delete feature does not silently start
// leaking deleted tasks through this endpoint just because this filter was
// never added.
$conditions = ['siteID = ?', 'isDeleted = 0'];
$types      = 'i';
$params     = [$siteId];
if ($scopeToOneUser === true) {
    $conditions[] = 'assignedToID = ?';
    $types       .= 'i';
    $params[]     = $targetUser;
}
if ($status === 'open') {
    // NOTE (pre-existing, not changed here): a task with status =
    // 'cancelled' also has completedAt IS NULL, so it is returned under
    // "open" rather than being excluded or given its own bucket — the
    // ?status values this endpoint accepts are only open/completed/all,
    // with no way to ask for cancelled or in_progress specifically. Left
    // as-is: choosing new API behaviour here is a product decision, not a
    // column-name fix.
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
