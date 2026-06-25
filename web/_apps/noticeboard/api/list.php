<?php
// Path: _apps/noticeboard/api/list.php
/**
 * GET /api/noticeboard/list
 * Returns all (non-deleted) posters for the current site as the JSON array the
 * board expects. Any authenticated user may read.
 *
 * @package   Portal\Apps\Noticeboard
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ApiResponse;
use Portal\Core\Site;

Auth::ensureSession();
ApiResponse::requireAuth();
ApiResponse::requireEnabled('api.noticeboard.list.enabled');

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    'SELECT posterID, title, kicker, category, scheduleType, eventDate, weekday, '
    . 'eventTime, location, link, mediaType, mediaUrl, canvaUrl, thumbUrl, '
    . 'colorIndex, aspect, useSerif '
    . 'FROM tblNoticeboardPosters WHERE siteID = ? AND isDeleted = 0 '
    . 'ORDER BY sortOrder ASC, eventDate ASC, posterID ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        // Map DB columns → the field names the client component reads.
        $rows[] = [
            'id'        => 'p' . (int) $row['posterID'],
            'title'     => $row['title'],
            'kicker'    => $row['kicker'],
            'category'  => $row['category'],
            'schedule'  => $row['scheduleType'],
            'date'      => $row['eventDate'] ?? '',
            'weekday'   => $row['weekday'] !== null ? (int) $row['weekday'] : null,
            'time'      => $row['eventTime'] !== null ? substr((string) $row['eventTime'], 0, 5) : '',
            'location'  => $row['location'],
            'link'      => $row['link'],
            'mediaType' => $row['mediaType'] === 'text' ? 'image' : $row['mediaType'],
            'image'     => $row['mediaUrl'],
            'canva'     => $row['canvaUrl'],
            'thumb'     => $row['thumbUrl'],
            'colorIndex' => (int) $row['colorIndex'],
            'aspect'    => $row['aspect'],
            'serif'     => (int) $row['useSerif'] === 1,
        ];
    }
    $stmt->close();
}

ApiResponse::success($rows);
