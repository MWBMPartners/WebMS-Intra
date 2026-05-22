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

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Site;

ApiResponse::requireAuth();

$db     = App::db();
$siteId = Site::id();
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// 📊 Count
$cntStmt = $db->prepare('SELECT COUNT(*) AS total FROM tblAttendanceSessions WHERE siteID = ?');
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
    . 'LEFT JOIN tblAttendanceServiceTypes t ON t.typeID = s.typeID '
    . 'WHERE s.siteID = ? ORDER BY s.sessionDate DESC LIMIT ? OFFSET ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $siteId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
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
