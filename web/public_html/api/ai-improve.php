<?php
// Path: public_html/api/ai-improve.php
/**
 * AJAX endpoint — improve user-authored text under a prompt kind.
 *
 *   POST kind=announcement|prayer-rewrite|newsletter-blurb|project-update
 *        text=<original>
 *
 * Returns JSON { suggestion } on success, or { error, message } when
 * blocked by cap / rate-limit / disabled / provider failure.
 *
 * @package   Portal\Api
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/277
 */

declare(strict_types=1);

use Portal\Core\AiAssistant;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'bad-request']);
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$kind   = (string) ($_POST['kind'] ?? 'announcement');
$text   = (string) ($_POST['text'] ?? '');

if (in_array($kind, AiAssistant::KINDS, true) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'bad-kind']);
    exit();
}
if (trim($text) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'empty-text']);
    exit();
}

$out = AiAssistant::improve($siteId, $userId, $kind, $text);

if ($out === null) {
    // Helper returns null for: disabled / cap exceeded / rate limited / provider failure.
    // Distinguish to give the caller something actionable.
    $settings = App::settings()['ai_assist'] ?? [];
    if ((string) ($settings['enabled'] ?? '0') !== '1') {
        $msg = 'AI Assist is disabled.';
    } elseif (AiAssistant::monthSpendPence($siteId) >= (int) ($settings['monthCapPence'] ?? 5000)) {
        $msg = 'Monthly AI budget reached — try again next month.';
    } elseif (AiAssistant::userDailyCount($siteId, $userId) >= (int) ($settings['userDailyCap'] ?? 20)) {
        $msg = 'Daily AI limit reached — try again tomorrow.';
    } else {
        $msg = 'AI provider unavailable — please try again.';
    }
    http_response_code(503);
    echo json_encode(['error' => 'unavailable', 'message' => $msg]);
    exit();
}

echo json_encode(['suggestion' => $out]);
exit();
