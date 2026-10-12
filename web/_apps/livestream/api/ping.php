<?php
// Path: _apps/livestream/api/ping.php
/**
 * -----------------------------------------------------------------------------
 * Livestream — ApiRouter-routed session ping (#318 fix / #317 Phase 2)
 * -----------------------------------------------------------------------------
 * Routed at api/livestream/ping → this file. No form token is asked for,
 * and a ping for a PUBLIC event, or for no event, needs no sign-in.
 *
 * This is the only viewer ping. An older copy, _apps/api/livestream-ping.php,
 * was deleted on 12 October 2026: no address could ever reach it, because
 * Router::handleSpecialRoutes hands every 'api/' address to ApiRouter, which
 * loads only _apps/{app}/api/{action}.php. Migration 133 registered an
 * address for it and migration 158 removed that row again.
 *
 * Field name: this handler reads BOTH `token` (what the older embed snippet
 * sends) AND `sessionToken` (what the chat box sends), so a snippet written
 * under #318 keeps working with no change.
 *
 * Which events it accepts (12 October 2026, #548): when a ping names an
 * event, the event must be one the viewer may see
 * (LiveChat::eventOpenToViewer()). For an INTERNAL event that means a
 * signed-in person who may see it, and a request made by one of the
 * portal's own pages; a page on any other address is treated as nobody.
 * A ping that names no event is still recorded, as a viewer of no
 * particular event.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/318
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\LiveChat;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo '{"error":"method"}'; exit(); }

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (is_array($payload) === false) { http_response_code(400); echo '{"error":"json"}'; exit(); }

// 🔑 Token comes in as `sessionToken` from the new chat widget OR `token` from
//    the older embed snippet — accept either so we don't break existing pages.
$token   = (string) ($payload['sessionToken'] ?? $payload['token'] ?? '');
$eventId = (int) ($payload['eventID'] ?? 0);
$leaving = ($payload['leaving'] ?? false) === true;

if (preg_match('/^[a-f0-9]{32,64}$/', $token) !== 1) {
    http_response_code(400); echo '{"error":"token"}'; exit();
}

$siteId = Site::id();
$ua     = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

if ($leaving === true) {
    $stmt = $mysqli->prepare('UPDATE tblLivestreamSessions SET leftAt = NOW() WHERE sessionToken = ? AND leftAt IS NULL');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
    echo '{"ok":true}'; exit();
}

// 👁️ A ping that names an event is recorded only for an event this viewer may
//    see: LiveChat::eventOpenToViewer() says who that is and why.
//
//    WHAT WAS WRONG (#548): the event number was stored unchecked. A viewer
//    session for ANY number could be started by anybody, and the chat's
//    "send" endpoint then accepted messages for that event because the
//    session existed. An internal event, a draft and another organisation's
//    event could all be posted into from outside.
//
//    Internal, draft, another organisation's and made-up numbers all get the
//    same answer and write nothing, so the reply does not say which it was.
//    A ping with NO event number is not refused: it records a viewer of no
//    particular event, as before, and chat cannot be sent on such a session
//    (the "send" endpoint needs an event). The "leaving" ping above is not
//    asked either: it only closes the caller's own session row.
//
//    The last argument (true) is "only inside the portal": the sign-in counts
//    only for a request made by one of the portal's own pages.
if ($eventId > 0 && LiveChat::eventOpenToViewer($mysqli, $siteId, $eventId, true) === false) {
    http_response_code(404); echo '{"error":"event"}'; exit();
}

// Upsert — first ping creates the session row, subsequent bumps lastPingAt.
$stmt = $mysqli->prepare(
    'INSERT INTO tblLivestreamSessions (siteID, eventID, sessionToken, userAgent) VALUES (?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE lastPingAt = NOW()'
);
$eventIdArg = $eventId > 0 ? $eventId : null;
$stmt->bind_param('iiss', $siteId, $eventIdArg, $token, $ua);
$stmt->execute();
$stmt->close();

echo '{"ok":true}';
