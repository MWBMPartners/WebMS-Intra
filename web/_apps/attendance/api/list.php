<?php
// Path: public_html/api/attendance/list.php
/**
 * -----------------------------------------------------------------------------
 * Attendance API — List Sessions
 * -----------------------------------------------------------------------------
 * Returns a paginated JSON list of attendance sessions for the current site.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/95
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\AttendanceAccess;
use Portal\Core\Site;

ApiAuth::requireRead('attendance:read');

$db     = App::db();
$siteId = Site::id();

// 🔒 #529 round 2 (D-109, and the Roles page's own words that notes are free
//    text and can name people). Any signed-in member may call this, and it
//    used to return every column of every session — the notes typed when
//    recording, who last changed it, and DELETED sessions too. For a
//    signed-in caller who may not see the attendance figures it now returns
//    only sessions that are not deleted, without `notes` and without
//    `updatedByID` (who corrected or deleted it, which no page shows a
//    member). `createdByID` STAYS: the list page shows the recorder's name to
//    every member, and under D-114 it is how a caller knows which sessions
//    are its own to delete. No headcount is returned to anybody here.
//
//    Administrators, holders of the permission, and API keys are unchanged:
//    a key is an integration an administrator made, and one syncing sessions
//    may rely on seeing deletions.
$limited = ApiAuth::source() === 'session'
    && AttendanceAccess::mayViewTotals($db, (int) (ApiAuth::actorUserId() ?? 0), $siteId, App::isAdmin(), App::isRootAdmin()) === false;
$notDeleted = $limited === true ? ' AND s.isDeleted = 0' : '';
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// 📊 Count
$cntStmt = $db->prepare('SELECT COUNT(*) AS total FROM tblAttendanceSessions s WHERE s.siteID = ?' . $notDeleted);
if ($cntStmt === false) {
    ApiResponse::error('Database error', 500);
}
$cntStmt->bind_param('i', $siteId);
$cntStmt->execute();
$totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
$cntStmt->close();
$totalPages = max(1, (int) ceil($totalItems / $limit));

// 📋 Fetch
$sessions = [];
$stmt = $db->prepare(
    'SELECT s.*, t.typeName FROM tblAttendanceSessions s '
    . 'LEFT JOIN tblAttendanceServiceTypes t ON t.serviceTypeID = s.serviceTypeID '
    . 'WHERE s.siteID = ?' . $notDeleted . ' ORDER BY s.sessionDate DESC LIMIT ? OFFSET ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $siteId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($limited === true) {
            unset($row['notes'], $row['updatedByID']);
        }
        $sessions[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'sessions' => $sessions,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
