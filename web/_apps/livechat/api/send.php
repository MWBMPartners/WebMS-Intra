<?php
// Path: _apps/livechat/api/send.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — public viewer chat send (#313 Phase 1) 💬
 * -----------------------------------------------------------------------------
 * PUBLIC, no-login, no-CSRF. Routed via ApiRouter as api/livechat/send →
 * _apps/livechat/api/send.php. Gated by:
 *   1. api.livechat.send.enabled = 'true' (ApiRouter checks)
 *   2. sessionToken MUST exist in tblLivestreamSessions for the active site
 *      (proves the client actually joined a livestream and isn't minting
 *      tokens client-side just to bypass the rate limit)
 *   3. First-message captcha: Captcha::verify on the FIRST send from a given
 *      sessionToken only; skipped on subsequent sends (Turnstile / reCAPTCHA /
 *      hCaptcha tokens are single-use)
 *   4. Sliding-window rate limit (per sessionToken AND per IP) — fail-closed
 *   5. Profanity stub auto-flags (does not reject)
 *   6. chat.autoApprove setting decides initial status (pending vs approved)
 *
 * Cross-origin friendly: Access-Control-Allow-Origin: * — embed page can live
 * on any tenant marketing site. NO CSRF (third-party cookies + SameSite=Lax
 * break the session check). sessionToken-exists gate is the "real visitor"
 * proof equivalent.
 *
 * @package   Portal\LiveChat
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\Captcha;
use Portal\Core\LiveChat;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

ApiResponse::setJsonHeaders();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { ApiResponse::error('POST required', 405); }

if ((string) Settings::get('chat.enabled', 'false') !== 'true') {
    ApiResponse::error('Chat is disabled', 403);
}

$rawBody = (string) file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (is_array($payload) === false) {
    $payload = $_POST;
}

$siteId       = Site::id();
$eventId      = (int) ($payload['eventID'] ?? 0);
$sessionToken = (string) ($payload['sessionToken'] ?? '');
$displayName  = (string) ($payload['displayName'] ?? '');
$body         = (string) ($payload['body'] ?? '');

if (LiveChat::isValidSessionToken($sessionToken) === false) {
    ApiResponse::error('Invalid sessionToken', 400);
}
if ($eventId <= 0) {
    ApiResponse::error('eventID required', 400);
}

// 🛡️ sessionToken-exists guard.
$stmt = $mysqli->prepare(
    'SELECT 1 FROM tblLivestreamSessions WHERE sessionToken = ? AND siteID = ? AND eventID = ? LIMIT 1'
);
if ($stmt === false) {
    Logger::errorPlatform('LiveChat', 'Warning', 'send-prepare-failed', 'sessionToken-exists query prepare failed', '');
    ApiResponse::error('Service unavailable', 503);
}
$stmt->bind_param('sii', $sessionToken, $siteId, $eventId);
$stmt->execute();
$sessionOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($sessionOk === false) {
    ApiResponse::error('Unknown session', 401);
}

// 🤖 Captcha — first send from this sessionToken only.
$stmt = $mysqli->prepare('SELECT 1 FROM tblLiveChatMessages WHERE sessionToken = ? LIMIT 1');
$stmt->bind_param('s', $sessionToken);
$stmt->execute();
$hasPriorMsg = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($hasPriorMsg === false
    && class_exists(Captcha::class) === true
    && Captcha::isConfigured() === true
    && Captcha::verify($payload) === false
) {
    Logger::activity('LiveChatCaptchaFailed', 'session=' . substr($sessionToken, 0, 8));
    ApiResponse::error('Captcha verification failed', 400);
}

// 🚦 Rate limit.
$ip = LiveChat::clientIp();
if (LiveChat::isRateLimited($mysqli, $sessionToken, $ip) === true) {
    ApiResponse::error('Rate limit exceeded', 429);
}

// 🛡️ Length-clamp + sanitise.
$displayName = LiveChat::normaliseDisplayName($displayName);
if ($displayName === '') {
    ApiResponse::error('displayName required', 400);
}
$maxBody = LiveChat::maxBodyChars();
$body    = mb_substr(trim($body), 0, $maxBody);
if ($body === '') {
    ApiResponse::error('body required', 400);
}

$status     = LiveChat::autoApprove() === true ? 'approved' : 'pending';
$flagReason = null;
if (LiveChat::containsProfanity($body) === true) {
    $status     = 'flagged';
    $flagReason = 'profanity-stub';
}

$stmt = $mysqli->prepare(
    'INSERT INTO tblLiveChatMessages '
    . '(siteID, eventID, sessionToken, displayName, body, senderIP, status, flagReason) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('LiveChat', 'Warning', 'send-insert-prepare-failed', '', '');
    ApiResponse::error('Service unavailable', 503);
}
$stmt->bind_param('iissssss', $siteId, $eventId, $sessionToken, $displayName, $body, $ip, $status, $flagReason);
$stmt->execute();
$messageId = (int) $stmt->insert_id;
$stmt->close();

LiveChat::recordSend($mysqli, $siteId, $sessionToken, $ip);
Logger::activity('LiveChatSent', 'msg=' . $messageId . ' status=' . $status);

ApiResponse::ok([
    'messageID' => $messageId,
    'status'    => $status,
]);
