<?php
// Path: _apps/push/api/unsubscribe.php
/**
 * -----------------------------------------------------------------------------
 * Web Push — Unsubscribe endpoint 🔕 (#322)
 * -----------------------------------------------------------------------------
 * POST { "endpoint": "..." }. Soft-deletes the matching tblPushSubscriptions
 * row (isActive=0) so the sender skips it on next dispatch but the row stays
 * around for resubscribe analytics.
 *
 * RELOCATED (#322 discovery) from `_apps/api/push/unsubscribe.php` — see
 * subscribe.php's header comment for the ApiRouter routing-trap rationale.
 * Gated by `api.push.unsubscribe.enabled` (migration 175).
 *
 * Endpoint-knowledge is treated as proof-of-possession — the endpoint URL
 * is a high-entropy, effectively-unguessable capability URL minted by the
 * push service itself, so keying the DELETE off it (rather than requiring
 * a session) is intentional and lets an anonymous device unsubscribe
 * itself without ever having had a userID.
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

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

$bodyRaw = file_get_contents('php://input') ?: '';
$payload = json_decode($bodyRaw, true);
if (is_array($payload) === false) {
    $payload = [];
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? ''));
if (Auth::verifyCsrf($csrf) === false) {
    Logger::activity('PushUnsubscribeRejected', 'Invalid CSRF on /api/push/unsubscribe');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

// 🛡️ Rate limit — public POST, reachable by anonymous visitors, per #322 §6.4.
//    RateLimiter::clientIp() is the repo's CF/X-Forwarded-For-aware IP
//    detector (CF-Connecting-IP > X-Forwarded-For > REMOTE_ADDR) — using the
//    raw REMOTE_ADDR here collapses every visitor behind the same Cloudflare
//    edge IP into one shared bucket.
$clientIp = RateLimiter::clientIp();
$rlBucket = 'pushsub:' . $clientIp;
if (RateLimiter::tooMany($rlBucket, 30, 3600) === true) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Try again later.']);
    exit();
}
RateLimiter::recordHit($rlBucket, 3600);

$endpoint = (string) ($payload['endpoint'] ?? '');
if ($endpoint === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit();
}

$stmt = $mysqli->prepare(
    'UPDATE tblPushSubscriptions SET isActive = 0 WHERE endpoint = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('s', $endpoint);
    $stmt->execute();
    $stmt->close();
}

Logger::activity('PushUnsubscribed', 'Endpoint=' . substr($endpoint, 0, 80));
// 🔄 Auth::verifyCsrf() rotated the session token above on successful
//    verification — hand the NEW token back so the client's next POST
//    (e.g. an immediate "re-enable" click) doesn't submit the now-stale one.
echo json_encode(['ok' => true, 'csrf_token' => Auth::csrfToken()]);
