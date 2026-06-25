<?php
// Path: _apps/livechat/api/moderate.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — admin/moderator approve/hide/flag (#313 Phase 1) 💬
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/moderate. Logged-in admin OR user
 * with the 'stream_moderator' role (App::hasRole). CSRF required (same-
 * origin admin call).
 *
 * Request body (JSON or form-encoded):
 *   messageID — int, required
 *   action    — 'approve' | 'hide' | 'flag', required
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ApiResponse::error('POST required', 405); }

ApiResponse::requireAuth();
Auth::ensureSession();

if (App::isAdmin() === false && App::hasRole('stream_moderator') === false) {
    ApiResponse::error('Admin or stream_moderator role required', 403);
}

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (is_array($payload) === false) { $payload = $_POST; }

$csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfBody   = (string) ($payload['csrf_token'] ?? '');
if (Auth::verifyCsrf($csrfHeader !== '' ? $csrfHeader : $csrfBody) === false) {
    ApiResponse::error('CSRF check failed', 403);
}

$messageId = (int) ($payload['messageID'] ?? 0);
$action    = (string) ($payload['action'] ?? '');
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);

$map = ['approve' => 'approved', 'hide' => 'hidden', 'flag' => 'flagged'];
if (isset($map[$action]) === false || $messageId <= 0) {
    ApiResponse::error('Invalid input', 400);
}
$newStatus = $map[$action];

// 🛡️ Cross-site guard — message must belong to active site.
$stmt = $mysqli->prepare(
    'UPDATE tblLiveChatMessages '
    . 'SET status = ?, moderatedByID = ?, moderatedAt = NOW() '
    . 'WHERE messageID = ? AND siteID = ?'
);
$stmt->bind_param('siii', $newStatus, $userId, $messageId, $siteId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    ApiResponse::error('Message not found', 404);
}

Logger::activity('LiveChatModerated', 'msg=' . $messageId . ' → ' . $newStatus);

ApiResponse::success([
    'messageID' => $messageId,
    'status'    => $newStatus,
]);
