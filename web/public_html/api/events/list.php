<?php
// Path: public_html/api/events/list.php
/**
 * -----------------------------------------------------------------------------
 * Events API — List Events
 * -----------------------------------------------------------------------------
 * Returns a paginated JSON list of published events for the current site.
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

// 📊 Count total
$cntStmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM tblEvents WHERE siteID = ? AND isDeleted = 0 AND status = \'published\''
);
if ($cntStmt === false) {
    ApiResponse::error('Database error', 500);
}
$cntStmt->bind_param('i', $siteId);
$cntStmt->execute();
$totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
$cntStmt->close();

$totalPages = max(1, (int) ceil($totalItems / $limit));

// 📋 Fetch events
$events = [];
$stmt = $db->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
    . 'e.timezone, e.isAllDay, e.locationName, e.status, e.isPublic, e.isFeatured, '
    . 'c.categoryName, t.typeName '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.status = \'published\' '
    . 'ORDER BY e.startDateTime DESC LIMIT ? OFFSET ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $siteId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'events' => $events,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
