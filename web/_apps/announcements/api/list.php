<?php
// Path: public_html/api/announcements/list.php
/**
 * -----------------------------------------------------------------------------
 * Announcements API — List Announcements
 * -----------------------------------------------------------------------------
 * Returns a paginated JSON list of published announcements.
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
$now    = date('Y-m-d H:i:s');

$cntStmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM tblAnnouncements '
    . 'WHERE siteID = ? AND isPublished = 1 AND isDeleted = 0 '
    . 'AND (publishAt IS NULL OR publishAt <= ?) '
    . 'AND (expiresAt IS NULL OR expiresAt > ?)'
);
if ($cntStmt === false) {
    ApiResponse::error('Database error', 500);
}
$cntStmt->bind_param('iss', $siteId, $now, $now);
$cntStmt->execute();
$totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
$cntStmt->close();
$totalPages = max(1, (int) ceil($totalItems / $limit));

$announcements = [];
$stmt = $db->prepare(
    'SELECT a.announcementID, a.title, a.slug, a.body, a.priority, a.isPinned, '
    . 'a.createdAt, u.fullName AS authorName '
    . 'FROM tblAnnouncements a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.createdByID '
    . 'WHERE a.siteID = ? AND a.isPublished = 1 AND a.isDeleted = 0 '
    . 'AND (a.publishAt IS NULL OR a.publishAt <= ?) '
    . 'AND (a.expiresAt IS NULL OR a.expiresAt > ?) '
    . 'ORDER BY a.isPinned DESC, a.createdAt DESC LIMIT ? OFFSET ?'
);
if ($stmt !== false) {
    $stmt->bind_param('issii', $siteId, $now, $now, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'announcements' => $announcements,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
