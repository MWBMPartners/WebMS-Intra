<?php
// Path: public_html/events/api/delete.php
/**
 * -----------------------------------------------------------------------------
 * Events API — Delete (soft) Event
 * -----------------------------------------------------------------------------
 * Admin-only. Sets isDeleted = 1 + deletedAt = NOW() on the event row.
 *
 *   POST /api/events/delete?id=N   (or {"eventID": N})
 *
 * @package   Portal\API
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
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

$body = json_decode((string) file_get_contents('php://input'), true);
if (is_array($body) === false) {
    $body = [];
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (Auth::verifyCsrf($csrfHeader !== '' ? $csrfHeader : (string) ($body['csrf_token'] ?? '')) === false) {
    ApiResponse::error('CSRF check failed', 403);
}

$eventId = (int) ($_GET['id'] ?? $body['eventID'] ?? 0);
if ($eventId <= 0) {
    ApiResponse::error('eventID is required', 400);
}

$siteId = Site::id();
$db     = App::db();

$stmt = $db->prepare(
    'UPDATE tblEvents SET isDeleted = 1, deletedAt = NOW() '
    . 'WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_DELETE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $eventId, $siteId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EVENT_DELETE_FAIL', $db->error, '');
    ApiResponse::error('Failed to delete event', 500);
}
if ($affected === 0) {
    ApiResponse::error('Event not found or already deleted', 404);
}

Logger::activity('ApiEventDelete', 'API: soft-deleted event #' . $eventId);

ApiResponse::success(['eventID' => $eventId, 'deleted' => true], 200);
