<?php
// Path: _apps/announcements/api/delete.php
/**
 * Announcements API — Delete (soft, sets isDeleted = 1)
 *
 *   POST /api/announcements/delete
 *   Content-Type: application/json
 *   { "announcementID": 42 }
 *
 * @package   Portal\API\Announcements
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/157
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('announcements:write');

$id = (int) ($body['announcementID'] ?? 0);
if ($id <= 0) {
    ApiResponse::error('announcementID is required', 400);
}

$siteId    = Site::id();
$updaterId = ApiAuth::actorUserId() ?? 0;

$db = App::db();
$stmt = $db->prepare('UPDATE tblAnnouncements SET isDeleted = 1, isPublished = 0, updatedByID = ? WHERE announcementID = ? AND siteID = ?');
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_DELETE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('iii', $updaterId, $id, $siteId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
if ($ok === false) {
    ApiResponse::error('Failed to delete announcement', 500);
}
if ($affected === 0) {
    ApiResponse::error('Announcement not found', 404);
}

Logger::activity('ApiAnnouncementDelete', 'API: soft-deleted announcement #' . $id);

ApiResponse::success(['announcementID' => $id, 'deleted' => true]);
