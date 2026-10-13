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
 * SIGNED-IN PEOPLE ONLY (owner, 13 October 2026: "require a sign-in, fix before
 * roll-out"). This used to accept anonymous visitors too (userID NULL, the
 * endpoint alone naming the device). No page offers a visitor the choice (the
 * only two pages that load push-subscribe.js, /live and /account/notifications,
 * both ask for a sign-in first), so such a device existed only if somebody called
 * this address by hand, which anybody could, from anywhere, with the form token
 * of any session. A device with no account also has no one who can switch the
 * notice off. Now a request that is not signed in is refused in plain words
 * (401) and stores nothing, BEFORE anything else is looked at. Devices that
 * were stored without an account earlier are not deleted, but WebPush::
 * sendToChannel() no longer addresses them: a person signs in and switches
 * notices on again from that device.
 *
 * (The unsubscribe address next to this one is unchanged: it works for anybody
 * who holds the device's own endpoint address, signed in or not, so that a
 * device with no account can still switch itself off.)
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
 * @version   1.2.0
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

// 🔑 SIGN-IN FIRST (see the header). Asked before the form token, so a request from nobody gets this plain answer
//    (the page's own script shows `error` to the person) and not "Invalid CSRF token". Nothing is stored, and
//    nothing is written to the activity log: a signed-out caller has no account to put on the line, and a scanner
//    calling this address by hand would only fill the log. WHAT THIS CANNOT DO: tell a signed-out person who is
//    really a visitor from a script; both get the same answer.
Auth::ensureSession();
if (Auth::check() === false) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to turn notifications on for this device.']);
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

// 🛡️ Rate limit — a POST that anyone signed in can make, per #322 §6.4.
//    RateLimiter::clientIp() is the repo's CF/X-Forwarded-For-aware IP
//    detector (CF-Connecting-IP > X-Forwarded-For > REMOTE_ADDR) — using the
//    raw REMOTE_ADDR here collapses every visitor behind the same Cloudflare
//    edge IP into one shared bucket.
$clientIp = RateLimiter::clientIp();
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

// 🛡️ Format/length validation (#322 F6 hardening) — p256dh must decode to a
//    65-byte uncompressed P-256 point (0x04 || X(32) || Y(32)) and auth to a
//    16-byte secret (RFC 8291 §3.2/§3.4). A malformed pair would otherwise
//    persist silently and only surface later as encryptPayload() failures
//    (8 failed sends before recordOutcome() prunes the row) — reject at
//    subscribe time instead. Inline base64url decode, tolerant of missing
//    padding, matching WebPush::b64urlDecode()'s own style.
$b64urlDecode = static function (string $str): string {
    $str = strtr($str, '-_', '+/');
    $pad = strlen($str) % 4;
    if ($pad > 0) {
        $str .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($str, true);
    return $out === false ? '' : $out;
};
$p256dhDecoded = $b64urlDecode($p256dh);
$authDecoded   = $b64urlDecode($auth);
if (strlen($p256dhDecoded) !== 65 || strlen($authDecoded) !== 16) {
    Logger::activity('PushSubscribeRejected', 'Malformed keys.p256dh/keys.auth length');
    http_response_code(400);
    echo json_encode(['error' => 'keys.p256dh must be a 65-byte uncompressed point and keys.auth a 16-byte secret']);
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
if ($userId <= 0) {
    // Signed in, by the check at the top, but with no account number: refuse rather than store a device with no account.
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to turn notifications on for this device.']);
    exit();
}
$userIdParam   = $userId;
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

Logger::activity('PushSubscribed', 'User=' . $userId . ', channels=' . implode(',', $channels));
// 🔄 Auth::verifyCsrf() rotated the session token above on successful
//    verification — hand the NEW token back so the client's next POST
//    (e.g. an immediate "disable" click) doesn't submit the now-stale one.
echo json_encode(['ok' => true, 'channels' => $channels, 'csrf_token' => Auth::csrfToken()]);
