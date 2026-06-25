<?php
// Path: _apps/livestream/api/ping.php
/**
 * -----------------------------------------------------------------------------
 * Livestream — ApiRouter-routed session ping (#318 fix / #317 Phase 2)
 * -----------------------------------------------------------------------------
 * PUBLIC, no-login, no-CSRF. Routed at api/livestream/ping → this file.
 *
 * Replaces / supersedes the unreachable handler at _apps/api/livestream-ping.php
 * which can never fire today: Router::handleSpecialRoutes intercepts every
 * 'api/' path and hands it to ApiRouter, which dispatches by segment
 * ({appName}/{action}) — there's no path lookup against tblRoutes. The old
 * handler's tblRoutes entry registered in migration 133 is functionally dead.
 *
 * Field name: this handler reads BOTH `token` (matches the old handler) AND
 * `sessionToken` (matches what the new livechat widget sends) so existing
 * snippet authored under #318 keeps working with no change.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/318
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

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
