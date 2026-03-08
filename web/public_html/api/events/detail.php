<?php
// Path: public_html/api/events/detail.php
/**
 * -----------------------------------------------------------------------------
 * Events API — Event Detail
 * -----------------------------------------------------------------------------
 * Returns full detail for a single event by ID or slug.
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
$eventId = (int) ($_GET['id'] ?? 0);
$slug    = trim($_GET['slug'] ?? '');

if ($eventId <= 0 && $slug === '') {
    ApiResponse::error('Provide id or slug parameter', 400);
}

$event = null;
if ($eventId > 0) {
    $stmt = $db->prepare(
        'SELECT e.*, c.categoryName, t.typeName, s.seriesName '
        . 'FROM tblEvents e '
        . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
        . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
        . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
        . 'WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} else {
    $stmt = $db->prepare(
        'SELECT e.*, c.categoryName, t.typeName, s.seriesName '
        . 'FROM tblEvents e '
        . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
        . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
        . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
        . 'WHERE e.eventSlug = ? AND e.siteID = ? AND e.isDeleted = 0 LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('si', $slug, $siteId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($event === null) {
    ApiResponse::error('Event not found', 404);
}

ApiResponse::success(['event' => $event]);
