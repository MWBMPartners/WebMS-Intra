<?php
// Path: _apps/announcements/api/update.php
/**
 * Announcements API — Update (partial)
 *
 *   POST /api/announcements/update
 *   Content-Type: application/json
 *   {
 *     "announcementID": 42,                     (required)
 *     "title":          "…",                     (optional)
 *     "body":           "…",                     (optional)
 *     "priority":       "important",             (optional)
 *     "isPinned":       false,                   (optional)
 *     "publishAt":      "2026-06-07T08:00:00", (optional)
 *     "expiresAt":      null,                    (optional)
 *     "isPublished":    true                     (optional)
 *   }
 *
 * @package   Portal\API\Announcements
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/157
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}
ApiResponse::requireAuth();
ApiResponse::requireAdmin();
Auth::ensureSession();

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$rawBody    = (string) file_get_contents('php://input');
$body       = json_decode($rawBody, true);
if (is_array($body) === false) {
    $body = [];
}
$csrfBody = (string) ($body['csrf_token'] ?? '');
if (Auth::verifyCsrf($csrfHeader !== '' ? $csrfHeader : $csrfBody) === false) {
    ApiResponse::error('CSRF check failed', 403);
}

$id = (int) ($body['announcementID'] ?? 0);
if ($id <= 0) {
    ApiResponse::error('announcementID is required', 400);
}

$siteId    = Site::id();
$updaterId = (int) ($_SESSION['user_id'] ?? 0);

$db = App::db();
$stmt = $db->prepare('SELECT * FROM tblAnnouncements WHERE announcementID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $id, $siteId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($existing === null) {
    ApiResponse::error('Announcement not found', 404);
}

// Build the SET clause from supplied fields only — partial update.
$updates = [];
$types   = '';
$params  = [];

if (array_key_exists('title', $body) === true) {
    $title = trim((string) $body['title']);
    if ($title === '') {
        ApiResponse::error('title cannot be empty', 400);
    }
    $updates[] = 'title = ?';
    $types    .= 's';
    $params[]  = $title;
}
if (array_key_exists('body', $body) === true) {
    $text = trim((string) $body['body']);
    if ($text === '') {
        ApiResponse::error('body cannot be empty', 400);
    }
    $updates[] = 'body = ?';
    $types    .= 's';
    $params[]  = $text;
}
if (array_key_exists('priority', $body) === true) {
    $priority = (string) $body['priority'];
    if (in_array($priority, ['normal', 'important', 'urgent'], true) === false) {
        ApiResponse::error('priority must be one of normal|important|urgent', 400);
    }
    $updates[] = 'priority = ?';
    $types    .= 's';
    $params[]  = $priority;
}
if (array_key_exists('isPinned', $body) === true) {
    $updates[] = 'isPinned = ?';
    $types    .= 'i';
    $params[]  = (bool) $body['isPinned'] === true ? 1 : 0;
}
if (array_key_exists('isPublished', $body) === true) {
    $updates[] = 'isPublished = ?';
    $types    .= 'i';
    $params[]  = (bool) $body['isPublished'] === true ? 1 : 0;
}
if (array_key_exists('publishAt', $body) === true) {
    if ($body['publishAt'] === null || trim((string) $body['publishAt']) === '') {
        $updates[] = 'publishAt = NULL';
    } else {
        $ts = strtotime((string) $body['publishAt']);
        if ($ts === false) {
            ApiResponse::error('publishAt is not a valid timestamp', 400);
        }
        $updates[] = 'publishAt = ?';
        $types    .= 's';
        $params[]  = date('Y-m-d H:i:s', $ts);
    }
}
if (array_key_exists('expiresAt', $body) === true) {
    if ($body['expiresAt'] === null || trim((string) $body['expiresAt']) === '') {
        $updates[] = 'expiresAt = NULL';
    } else {
        $ts = strtotime((string) $body['expiresAt']);
        if ($ts === false) {
            ApiResponse::error('expiresAt is not a valid timestamp', 400);
        }
        $updates[] = 'expiresAt = ?';
        $types    .= 's';
        $params[]  = date('Y-m-d H:i:s', $ts);
    }
}

if ($updates === []) {
    ApiResponse::error('No updatable fields supplied', 400);
}

$updates[] = 'updatedByID = ?';
$types    .= 'i';
$params[]  = $updaterId;

$sql = 'UPDATE tblAnnouncements SET ' . implode(', ', $updates) . ' WHERE announcementID = ? AND siteID = ?';
$types  .= 'ii';
$params[] = $id;
$params[] = $siteId;

$stmt = $db->prepare($sql);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_UPDATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_UPDATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to update announcement', 500);
}

Logger::activity('ApiAnnouncementUpdate', 'API: updated announcement #' . $id);

ApiResponse::success(['announcementID' => $id, 'updated' => true]);
