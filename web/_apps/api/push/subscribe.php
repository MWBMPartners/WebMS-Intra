<?php
// Path: _apps/api/push/subscribe.php
/**
 * -----------------------------------------------------------------------------
 * Web Push — Subscribe endpoint 🔔 (#322)
 * -----------------------------------------------------------------------------
 * POST endpoint. Accepts a browser PushSubscription JSON and stores its
 * credentials in tblPushSubscriptions so the portal's notification sender
 * can later deliver "we're live now" / service reminders.
 *
 * Accepts logged-in users (userID populated) AND anonymous visitors
 * (userID NULL — endpoint string uniquely identifies the device).
 *
 * v1 scope: store the subscription. The actual sender + per-channel
 * preferences UI ship in v1.1 — see DEV_NOTES "Web Push setup".
 *
 * Request body (JSON):
 *   {
 *     "endpoint":   "https://fcm.googleapis.com/fcm/send/...",
 *     "keys": { "p256dh": "...", "auth": "..." },
 *     "channels":   ["livestream", "reminders"]   (optional)
 *   }
 *
 * @package   Portal\Api\Push
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

// 🛡️ CSRF — accept either the header (preferred for fetch) or the body field.
$csrfFromHeader = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$bodyRaw        = file_get_contents('php://input') ?: '';
$payload        = json_decode($bodyRaw, true);
if (is_array($payload) === false) {
    $payload = [];
}
$csrfFromBody = (string) ($payload['csrf_token'] ?? '');
$csrf         = $csrfFromHeader !== '' ? $csrfFromHeader : $csrfFromBody;
if (Auth::verifyCsrf($csrf) === false) {
    Logger::activity('PushSubscribeRejected', 'Invalid CSRF on /api/push/subscribe');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

// 🛡️ Master toggle.
if ((App::settings('push.enabled') ?? 'false') !== 'true') {
    http_response_code(503);
    echo json_encode(['error' => 'Push notifications are not enabled on this install']);
    exit();
}

$endpoint = (string) ($payload['endpoint'] ?? '');
$keys     = (array)  ($payload['keys']     ?? []);
$p256dh   = (string) ($keys['p256dh'] ?? '');
$auth     = (string) ($keys['auth']   ?? '');
$channels = (array)  ($payload['channels'] ?? ['livestream', 'reminders']);

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint, keys.p256dh, or keys.auth']);
    exit();
}

// 🧹 Whitelist channels — anything not on this list is silently dropped.
$validChannels = ['livestream', 'reminders', 'announcements'];
$channels = array_values(array_intersect($validChannels, $channels));
if (count($channels) === 0) {
    $channels = ['livestream', 'reminders'];
}

$userAgent     = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$siteId        = Site::id();
$userId        = (int) ($_SESSION['user_id'] ?? 0);
$userIdParam   = $userId > 0 ? $userId : null;
$channelsJson  = json_encode($channels);

// 💾 Upsert by endpoint (the device identifier per browser+install).
//    If the same browser re-subscribes, we update the keys + channels.
$stmt = $mysqli->prepare(
    'INSERT INTO tblPushSubscriptions (siteID, userID, endpoint, p256dhKey, authKey, userAgent, channels) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE userID = VALUES(userID), p256dhKey = VALUES(p256dhKey), '
    . '                         authKey = VALUES(authKey), userAgent = VALUES(userAgent), '
    . '                         channels = VALUES(channels), isActive = 1'
);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit();
}
$stmt->bind_param('iisssss', $siteId, $userIdParam, $endpoint, $p256dh, $auth, $userAgent, $channelsJson);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::activity('PushSubscribeFailed', 'Insert failed for endpoint ' . substr($endpoint, 0, 80));
    http_response_code(500);
    echo json_encode(['error' => 'Insert failed']);
    exit();
}

Logger::activity('PushSubscribed', 'User=' . ($userId > 0 ? $userId : 'anon') . ', channels=' . implode(',', $channels));
echo json_encode(['ok' => true, 'channels' => $channels]);
