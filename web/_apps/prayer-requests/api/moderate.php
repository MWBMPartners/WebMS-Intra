<?php
// Path: _apps/prayer-requests/api/moderate.php
/**
 * Prayer Requests API — Moderation (admin / prayer-team only)
 *
 *   POST /api/prayer-requests/moderate
 *   {
 *     "requestID": 42,
 *     "status":    "active",                (one of: active|answered|archived)
 *     "testimony": "Praise — got the job!"  (optional; appended when status = answered)
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

if (App::isAdmin() === false && App::hasRole('prayer_team') === false) {
    ApiResponse::error('Forbidden — moderation requires admin or prayer_team role', 403);
}

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

$id     = (int) ($body['requestID'] ?? 0);
$status = (string) ($body['status'] ?? '');
if ($id <= 0) {
    ApiResponse::error('requestID is required', 400);
}
if (in_array($status, ['active', 'answered', 'archived'], true) === false) {
    ApiResponse::error('status must be one of active|answered|archived', 400);
}
$testimony = isset($body['testimony']) === true ? trim((string) $body['testimony']) : '';

$siteId      = Site::id();
$moderatorId = (int) ($_SESSION['user_id'] ?? 0);

$db = App::db();
if ($status === 'answered') {
    $stmt = $db->prepare(
        'UPDATE tblPrayerRequests SET status = ?, testimony = ?, answeredAt = NOW(), '
        . 'moderatorID = ?, moderatedAt = NOW() '
        . 'WHERE requestID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        ApiResponse::error('Database error', 500);
    }
    $stmt->bind_param('ssiii', $status, $testimony, $moderatorId, $id, $siteId);
} else {
    $stmt = $db->prepare(
        'UPDATE tblPrayerRequests SET status = ?, moderatorID = ?, moderatedAt = NOW() '
        . 'WHERE requestID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        ApiResponse::error('Database error', 500);
    }
    $stmt->bind_param('siii', $status, $moderatorId, $id, $siteId);
}
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
if ($ok === false) {
    ApiResponse::error('Failed to moderate request', 500);
}
if ($affected === 0) {
    ApiResponse::error('Prayer request not found', 404);
}

Logger::activity('ApiPrayerRequestModerate', 'API: moderated prayer request #' . $id . ' → ' . $status);

ApiResponse::success(['requestID' => $id, 'status' => $status]);
