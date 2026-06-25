<?php
// Path: _apps/livechat/api/list.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — public message list / long-poll (#313 Phase 1) 💬
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/list. PUBLIC GET.
 *
 * Query params: eventID (int, required), sinceID (int, default 0).
 * Returns up to 100 approved messages for the event since sinceID,
 * ordered by messageID ASC. Cross-origin friendly.
 *
 * Status filter: 'approved' only — pending/hidden/flagged never surface
 * to the public list.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\Settings;
use Portal\Core\Site;

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { ApiResponse::error('GET required', 405); }

if ((string) Settings::get('chat.enabled', 'false') !== 'true') {
    ApiResponse::error('Chat is disabled', 403);
}

$siteId  = Site::id();
$eventId = (int) ($_GET['eventID'] ?? 0);
$sinceId = (int) ($_GET['sinceID'] ?? 0);

if ($eventId <= 0) {
    ApiResponse::error('eventID required', 400);
}

$stmt = $mysqli->prepare(
    'SELECT messageID, displayName, body, createdAt '
    . 'FROM tblLiveChatMessages '
    . 'WHERE siteID = ? AND eventID = ? AND status = "approved" AND messageID > ? '
    . 'ORDER BY messageID ASC LIMIT 100'
);
$stmt->bind_param('iii', $siteId, $eventId, $sinceId);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($r = $result->fetch_assoc()) {
    $messages[] = [
        'messageID'   => (int) $r['messageID'],
        'displayName' => (string) $r['displayName'],
        'body'        => (string) $r['body'],
        'createdAt'   => (string) $r['createdAt'],
    ];
}
$stmt->close();

$lastId = count($messages) > 0 ? (int) $messages[count($messages) - 1]['messageID'] : $sinceId;

ApiResponse::success([
    'messages' => $messages,
    'lastID'   => $lastId,
    'count'    => count($messages),
]);
