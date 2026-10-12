<?php
// Path: _apps/livechat/api/prompts.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — active-prompts list (#317 Phase 2 / #313 Phase 2) 📣
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/prompts → this file. A PUBLIC
 * event's prompts need no sign-in. An INTERNAL event is answered only for
 * a signed-in person who may see it, and only when the request was made by
 * one of the portal's own pages (LiveChat::eventOpenToViewer(), 12 October
 * 2026, #548). Anybody else gets the empty answer a made-up number gets.
 *
 * Query params: eventID (int, required).
 * Returns up to 5 active prompts for the event (not dismissed, not expired).
 * Sends Access-Control-Allow-Origin: *, which lets a page on another
 * address read the answer to a request that carries no cookie, so a public
 * event's prompts only. The portal's own chat box script does not poll from
 * another website: it asks the address of the page it is on.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\LiveChat;
use Portal\Core\LivePrompt;
use Portal\Core\Settings;
use Portal\Core\Site;

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('GET required', 405);
}

if ((string) Settings::get('chat.enabled', 'false') !== 'true') {
    ApiResponse::error('Chat is disabled', 403);
}

$siteId  = Site::id();
$eventId = (int) ($_GET['eventID'] ?? 0);
if ($eventId <= 0) {
    ApiResponse::error('eventID required', 400);
}

// 👁️ Only for an event this viewer may see: LiveChat::eventOpenToViewer() says
//    who that is and why. An event that is not open gets the very answer a
//    made-up number gets (no prompts), so the reply does not say whether the
//    event exists. This endpoint used to return an internal event's prompts
//    to anybody at all. The last argument (true) is "only inside the
//    portal": the sign-in counts only for a request made by one of the
//    portal's own pages.
$prompts = LiveChat::eventOpenToViewer($mysqli, $siteId, $eventId, true) === true
    ? LivePrompt::activePromptsForEvent($mysqli, $siteId, $eventId)
    : [];

ApiResponse::success([
    'prompts' => $prompts,
    'count'   => count($prompts),
]);
