<?php
// Path: _apps/livechat/api/send.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — viewer chat send (#313 Phase 1) 💬
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/send → _apps/livechat/api/send.php.
 * No form token is asked for. A PUBLIC event's chat needs no sign-in. An
 * INTERNAL event is answered only for a signed-in person who may see it,
 * and only when the request was made by one of the portal's own pages
 * (gate 2). Gated by:
 *   1. api.livechat.send.enabled = 'true' (ApiRouter checks)
 *   2. the event must be one this viewer may see:
 *      LiveChat::eventOpenToViewer(), asked on every send (12 October 2026,
 *      #548). It, not a form token, is what stops a page somewhere else
 *      posting through a signed-in member's browser: for such a request
 *      the sign-in does not count.
 *   3. sessionToken MUST exist in tblLivestreamSessions for the active site
 *      and this event (proves the client actually joined a livestream and
 *      isn't minting tokens client-side just to bypass the rate limit)
 *   4. First-message captcha: Captcha::verify on the FIRST send from a given
 *      sessionToken only; skipped on subsequent sends (Turnstile / reCAPTCHA /
 *      hCaptcha tokens are single-use)
 *   5. Sliding-window rate limit (per sessionToken AND per IP) — fail-closed
 *   6. Profanity stub auto-flags (does not reject)
 *   7. chat.autoApprove setting decides initial status (pending vs approved)
 *
 * Access-Control-Allow-Origin: * is sent, so a page on another address may
 * read the answer to a request that carries NO cookie (a public event's
 * chat, from a program or a page written to call the portal). Browsers
 * refuse to show such a page the answer to a request that did carry one.
 * WHAT THAT DOES NOT MEAN: the portal's own chat box script does not work
 * on another website; it asks the address of the page it is on (see
 * web/public_html/assets/js/livechat-widget.js).
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

// 👁️ Only for an event this viewer may see: LiveChat::eventOpenToViewer() says
//    who that is and why. Asked BEFORE the viewer-session test below, and on
//    every send, on purpose: a viewer-session row made before 12 October 2026
//    (when the ping stored any event number it was sent, #548), or an event
//    made internal after its viewers arrived, must not keep the door open.
//    The refusal is the one an unknown session gets, so it does not say
//    whether the event exists.
//
//    The last argument (true) is "only inside the portal": for an internal
//    event the sign-in counts only when the browser says one of the portal's
//    own pages made this request. A page on another address of the same site
//    used to be able to post here through a signed-in member's browser.
if (LiveChat::eventOpenToViewer($mysqli, $siteId, $eventId, true) === false) {
    ApiResponse::error('Unknown session', 401);
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

ApiResponse::success([
    'messageID' => $messageId,
    'status'    => $status,
]);
