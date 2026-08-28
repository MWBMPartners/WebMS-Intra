<?php
// Path: _apps/push/api/subscribe.php
/**
 * -----------------------------------------------------------------------------
 * Web Push — Subscribe endpoint 🔔 (#322)
 * -----------------------------------------------------------------------------
 * POST endpoint. Accepts a browser PushSubscription JSON and stores its
 * credentials in tblPushSubscriptions so Portal\Core\WebPush can later
 * deliver "we're live now" / service-reminder notifications.
 *
 * RELOCATED (#322 discovery) from `_apps/api/push/subscribe.php` — the
 * ApiRouter convention resolves `api/push/subscribe` to
 * `_apps/push/api/subscribe.php`, NOT `_apps/api/push/subscribe.php`, so the
 * original location was unreachable dead code (same class of bug as
 * migration 144's livestream/ping relocation and the #372 worship
 * state/advance relocation). Gated by `api.push.subscribe.enabled`
 * (migration 175) — without that flag ApiRouter 403s even at this path.
 *
 * Accepts logged-in users (userID populated) AND anonymous visitors
 * (userID NULL — endpoint string uniquely identifies the device).
 *
 * Request body (JSON):
 *   {
 *     "endpoint":   "https://fcm.googleapis.com/fcm/send/...",
 *     "keys": { "p256dh": "...", "auth": "..." },
 *     "channels":   ["livestream", "reminders"]   (optional)
 *   }
 *
 * @package   Portal\Apps\Push
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;
use Portal\Core\Site;
use Portal\Core\WebPush;

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

// 🛡️ Rate limit — public POST, reachable by anonymous visitors, per #322 §6.4.
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rlBucket = 'pushsub:' . $clientIp;
if (RateLimiter::tooMany($rlBucket, 30, 3600) === true) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many subscribe attempts. Try again later.']);
    exit();
}
RateLimiter::recordHit($rlBucket, 3600);

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

// 🛡️ SSRF — validate the endpoint at SUBSCRIBE time too (WebPush::send()
//    re-validates at SEND time; a subscriber controls this string entirely,
//    so both gates share the exact same rule set — see WebPush::validateEndpoint()).
if (WebPush::validateEndpoint($endpoint) === false) {
    Logger::activity('PushSubscribeRejected', 'Endpoint failed validation: ' . substr($endpoint, 0, 60));
    http_response_code(400);
    echo json_encode(['error' => 'Endpoint is not a recognised push service']);
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
