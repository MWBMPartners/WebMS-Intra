<?php
// Path: _apps/livechat/api/prompts.php
/**
 * -----------------------------------------------------------------------------
 * COP Live Chat — public active-prompts list (#317 Phase 2 / #313 Phase 2) 📣
 * -----------------------------------------------------------------------------
 * Routed via ApiRouter as api/livechat/prompts → this file.
 *
 * Query params: eventID (int, required).
 * Returns up to 5 active prompts for the event (not dismissed, not expired).
 * Cross-origin friendly so the viewer widget can poll from external embeds.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
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

$prompts = LivePrompt::activePromptsForEvent($mysqli, $siteId, $eventId);

ApiResponse::success([
    'prompts' => $prompts,
    'count'   => count($prompts),
]);
