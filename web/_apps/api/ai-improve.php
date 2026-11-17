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

// 🔄 The check above consumed the token and issued a replacement. Every answer
//    below therefore carries it, so a page posting more than once without
//    reloading is not left holding a spent one (#634). The refusal ABOVE
//    carries nothing on purpose: a failed check rotates nothing.
$freshCsrf = Auth::csrfToken();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$kind   = (string) ($_POST['kind'] ?? 'announcement');
$text   = (string) ($_POST['text'] ?? '');

if (in_array($kind, AiAssistant::KINDS, true) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'bad-kind', 'csrfToken' => $freshCsrf]);
    exit();
}
if (trim($text) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'empty-text', 'csrfToken' => $freshCsrf]);
    exit();
}

$out = AiAssistant::improve($siteId, $userId, $kind, $text);

if ($out === null) {
    // Helper returns null for: disabled / cap exceeded / rate limited / provider failure.
    // It SAYS which (#993, the rule #762 set for translation): re-deriving the
    // reason here from a fresh read could not tell "the provider failed on the
    // call that used the last of the month" from "the month was already used".
    $msg = match (AiAssistant::lastRefusal()) {
        'off'        => 'AI Assist is disabled.',
        'money'      => 'Monthly AI budget reached — try again next month.',
        'no-budget'  => 'AI Assist cannot be used: its monthly budget is set to nothing. An administrator sets the budget under Admin, AI Assist.',
        'no-rate'    => 'AI Assist cannot be used right now: there is no exchange rate to check this month\'s spending limit. An administrator can add one under Admin, Exchange rates.',
        'no-currency' => 'AI Assist cannot be used right now: no currency has been chosen to check this month\'s spending limit in. An administrator can choose one under Admin, Settings, Organisation.',
        'unreadable' => 'AI Assist is not available just now, because the portal could not check this month\'s spending. Nothing was sent.',
        'daily'      => 'Daily AI limit reached — try again tomorrow.',
        default      => 'AI provider unavailable — please try again.',
    };
    http_response_code(503);
    echo json_encode(['error' => 'unavailable', 'message' => $msg, 'csrfToken' => $freshCsrf]);
    exit();
}

echo json_encode(['suggestion' => $out, 'csrfToken' => $freshCsrf]);
exit();
