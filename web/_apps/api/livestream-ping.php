<?php
// _apps/api/livestream-ping.php — Public livestream session ping (#318)
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

$token   = (string) ($payload['token'] ?? '');
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

// Upsert: first ping creates the row, subsequent ones bump lastPingAt.
$stmt = $mysqli->prepare(
    'INSERT INTO tblLivestreamSessions (siteID, eventID, sessionToken, userAgent) VALUES (?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE lastPingAt = NOW()'
);
$eventIdArg = $eventId > 0 ? $eventId : null;
$stmt->bind_param('iiss', $siteId, $eventIdArg, $token, $ua);
$stmt->execute();
$stmt->close();

echo '{"ok":true}';
