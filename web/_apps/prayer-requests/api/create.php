<?php
// Path: _apps/prayer-requests/api/create.php
/**
 * Prayer Requests API — Create (logged-in path only; anonymous submission
 * uses the public form route at /prayer-requests/anonymous which has
 * captcha gating).
 *
 *   POST /api/prayer-requests/create
 *   {
 *     "subject":     "Travel mercies for the youth retreat",  (required)
 *     "body":        "…",                                       (required)
 *     "visibility":  "congregation",                            (optional: leadership|congregation)
 *     "isAnonymous": false                                      (optional)
 *   }
 *
 * @package   Portal\API\PrayerRequests
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

$subject = trim((string) ($body['subject'] ?? ''));
$text    = trim((string) ($body['body']    ?? ''));
if ($subject === '' || $text === '') {
    ApiResponse::error('subject and body are required', 400);
}
$visibility = (string) ($body['visibility'] ?? 'leadership');
if (in_array($visibility, ['leadership', 'congregation'], true) === false) {
    $visibility = 'leadership';
}
$isAnonymous = isset($body['isAnonymous']) === true && (bool) $body['isAnonymous'] === true ? 1 : 0;

$siteId      = Site::id();
$submitterId = (int) ($_SESSION['user_id'] ?? 0);
$submitterIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblPrayerRequests '
    . '(siteID, submitterID, submitterIP, subject, body, visibility, status, isAnonymous) '
    . 'VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_PRAYER_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('iissssi', $siteId, $submitterId, $submitterIp, $subject, $text, $visibility, $isAnonymous);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_PRAYER_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create prayer request', 500);
}

Logger::activity('ApiPrayerRequestCreate', 'API: created prayer request #' . $newId);

ApiResponse::success(['requestID' => $newId, 'status' => 'pending'], 201);
