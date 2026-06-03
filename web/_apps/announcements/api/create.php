<?php
// Path: _apps/announcements/api/create.php
/**
 * Announcements API — Create
 *
 *   POST /api/announcements/create
 *   Content-Type: application/json
 *   {
 *     "title":        "Sabbath service cancelled this week",  (required)
 *     "body":         "Boiler failure — see updates …",        (required)
 *     "priority":     "important",                              (optional: normal|important|urgent)
 *     "isPinned":     true,                                     (optional)
 *     "publishAt":    "2026-06-07T08:00:00",                   (optional ISO 8601; NULL = immediate)
 *     "expiresAt":    "2026-06-14T00:00:00",                   (optional; NULL = no expiry)
 *     "isPublished":  true                                       (optional, default true)
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

$title = trim((string) ($body['title'] ?? ''));
$text  = trim((string) ($body['body']  ?? ''));
if ($title === '' || $text === '') {
    ApiResponse::error('title and body are required', 400);
}
$priority = (string) ($body['priority'] ?? 'normal');
if (in_array($priority, ['normal', 'important', 'urgent'], true) === false) {
    $priority = 'normal';
}
$isPinned    = isset($body['isPinned'])    === true && (bool) $body['isPinned']    === true ? 1 : 0;
$isPublished = isset($body['isPublished']) === false || (bool) $body['isPublished'] === true ? 1 : 0;

$publishAt = null;
if (isset($body['publishAt']) === true && trim((string) $body['publishAt']) !== '') {
    $ts = strtotime((string) $body['publishAt']);
    if ($ts === false) {
        ApiResponse::error('publishAt is not a valid timestamp', 400);
    }
    $publishAt = date('Y-m-d H:i:s', $ts);
}
$expiresAt = null;
if (isset($body['expiresAt']) === true && trim((string) $body['expiresAt']) !== '') {
    $ts = strtotime((string) $body['expiresAt']);
    if ($ts === false) {
        ApiResponse::error('expiresAt is not a valid timestamp', 400);
    }
    $expiresAt = date('Y-m-d H:i:s', $ts);
}

$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
$slug = substr($slug !== '' ? $slug : 'announcement-' . bin2hex(random_bytes(4)), 0, 200);

$siteId    = Site::id();
$creatorId = (int) ($_SESSION['user_id'] ?? 0);

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblAnnouncements '
    . '(siteID, title, slug, body, priority, isPinned, publishAt, expiresAt, isPublished, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param(
    'issssisiii',
    $siteId, $title, $slug, $text, $priority, $isPinned,
    $publishAt, $expiresAt, $isPublished, $creatorId
);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create announcement', 500);
}

Logger::activity('ApiAnnouncementCreate', 'API: created announcement #' . $newId . ' "' . $title . '"');

ApiResponse::success([
    'announcementID' => $newId,
    'slug'           => $slug,
], 201);
