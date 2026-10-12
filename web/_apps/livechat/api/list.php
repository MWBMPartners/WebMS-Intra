<?php
// Path: _apps/livechat/api/list.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — message list / long-poll (#313 Phase 1) 💬
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/list. GET. A PUBLIC event's messages
 * need no sign-in. An INTERNAL event is answered only for a signed-in
 * person who may see it, and only when the request was made by one of the
 * portal's own pages (LiveChat::eventOpenToViewer(), 12 October 2026,
 * #548). Anybody else gets the empty answer a made-up event number gets.
 *
 * Query params: eventID (int, required), sinceID (int, default 0).
 * Returns up to 100 approved messages for the event since sinceID,
 * ordered by messageID ASC. Sends Access-Control-Allow-Origin: *, which
 * lets a page on another address read the answer to a request that carries
 * no cookie, so a public event's messages only.
 *
 * Status filter: 'approved' only — pending/hidden/flagged never surface
 * in this list.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\LiveChat;
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

// 👁️ Only for an event this viewer may see: LiveChat::eventOpenToViewer() says
//    who that is and why. An event that is not open gets the very answer a
//    made-up number gets (no messages, the caller's own sinceID back), so the
//    reply does not say whether the event exists. This endpoint used to
//    return an internal event's approved messages to anybody at all.
//    The last argument (true) is "only inside the portal": the sign-in counts
//    only for a request made by one of the portal's own pages.
$messages = [];
if (LiveChat::eventOpenToViewer($mysqli, $siteId, $eventId, true) === true) {
    $stmt = $mysqli->prepare(
        'SELECT messageID, displayName, body, createdAt '
        . 'FROM tblLiveChatMessages '
        . 'WHERE siteID = ? AND eventID = ? AND status = "approved" AND messageID > ? '
        . 'ORDER BY messageID ASC LIMIT 100'
    );
    $stmt->bind_param('iii', $siteId, $eventId, $sinceId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $messages[] = [
            'messageID'   => (int) $r['messageID'],
            'displayName' => (string) $r['displayName'],
            'body'        => (string) $r['body'],
            'createdAt'   => (string) $r['createdAt'],
        ];
    }
    $stmt->close();
}

$lastId = count($messages) > 0 ? (int) $messages[count($messages) - 1]['messageID'] : $sinceId;

ApiResponse::success([
    'messages' => $messages,
    'lastID'   => $lastId,
    'count'    => count($messages),
]);
