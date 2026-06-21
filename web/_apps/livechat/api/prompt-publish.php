<?php
// Path: _apps/livechat/api/prompt-publish.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — host composer push prompt (#317 Phase 2 / #313 Phase 2) 📣
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/prompt-publish → this file. ApiRouter
 * allows hyphens in action names (regex ^[a-z0-9\-]+$ per ApiRouter.php:48).
 *
 * Admin OR Auth::isCoordinatorOf(eventID). Same-origin (admin tab); CSRF on
 * POST. Composer card on the host console event dashboard is the primary
 * caller; pasting curl with the bearer-token-or-session would also work.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\LivePrompt;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

ApiResponse::requireAuth();

if ((string) Settings::get('chat.enabled', 'false') !== 'true') {
    ApiResponse::error('Chat is disabled', 403);
}

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (is_array($payload) === false) {
    $payload = $_POST;
}

// 🛡️ CSRF — same-origin admin/coordinator surface; accept token from
//    X-CSRF-TOKEN header OR JSON body.
$csrfHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfBody   = (string) ($payload['csrf_token'] ?? '');
if (Auth::verifyCsrf($csrfHeader !== '' ? $csrfHeader : $csrfBody) === false) {
    ApiResponse::error('CSRF check failed', 403);
}

$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$eventId = (int) ($payload['eventID'] ?? 0);
$type    = (string) ($payload['promptType'] ?? '');
$title   = (string) ($payload['title'] ?? '');
$body    = (string) ($payload['body'] ?? '');
$ctaLab  = (string) ($payload['ctaLabel'] ?? '');
$ctaUrl  = (string) ($payload['ctaUrl'] ?? '');
$expSec  = isset($payload['expirySeconds']) === true ? (int) $payload['expirySeconds'] : null;

if ($eventId <= 0) {
    ApiResponse::error('eventID required', 400);
}

// 🛡️ Role gate.
if (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false) {
    Logger::activity('LivePromptForbidden', 'event=' . $eventId . ' user=' . $userId, $userId);
    ApiResponse::error('Admin or event coordinator required', 403);
}

// 🛡️ Confirm event belongs to active site BEFORE write.
$stmt = $mysqli->prepare('SELECT 1 FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) {
    ApiResponse::error('Event not found', 404);
}

try {
    $row = LivePrompt::publish(
        $mysqli, $siteId, $eventId, $type, $title, $body, $ctaLab, $ctaUrl, $userId, $expSec
    );
} catch (\InvalidArgumentException $e) {
    ApiResponse::error($e->getMessage(), 400);
} catch (\RuntimeException $e) {
    Logger::errorPlatform('LivePrompt', 'Warning', 'publish-failed', $e->getMessage(), '');
    ApiResponse::error('Service unavailable', 503);
}

ApiResponse::success([
    'prompt' => $row,
]);
