<?php
// Path: public_html/admin/integrations/zoom/webhook.php
/**
 * Zoom webhook receiver.
 *
 * Public route (no portal auth) — protected by HMAC signature verification
 * against the configured zoom.webhookSecret. Handles:
 *
 *   • endpoint.url_validation — answers the challenge so Zoom can verify
 *     ownership during webhook endpoint setup.
 *   • recording.completed     — when Recordings app is enabled and the
 *     meeting maps to a portal calendar event, records the share URL on
 *     the meeting row (no auto-import — admin reviews first).
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Zoom;

header('Content-Type: application/json');

$body            = (string) file_get_contents('php://input');
$signature       = (string) ($_SERVER['HTTP_X_ZM_SIGNATURE']         ?? '');
$timestamp       = (string) ($_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] ?? '');
$settings        = App::settings()['zoom'] ?? [];
$webhookSecret   = (string) ($settings['webhookSecret'] ?? '');

if ($webhookSecret === '') {
    http_response_code(503);
    echo json_encode(['error' => 'webhook secret not configured']);
    exit();
}

$payload = json_decode($body, true);
if (is_array($payload) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);
    exit();
}

// 🔐 Endpoint URL validation — Zoom posts this once when the webhook is set up.
//    We answer with HMAC-SHA256(plainToken) — no signature check on this call.
if (($payload['event'] ?? '') === 'endpoint.url_validation') {
    echo json_encode(Zoom::answerUrlValidation($webhookSecret, $payload['payload'] ?? []));
    exit();
}

if (Zoom::verifyWebhook($webhookSecret, $body, $signature, $timestamp) === false) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid signature']);
    exit();
}

$event = (string) ($payload['event'] ?? '');
$obj   = $payload['payload']['object'] ?? null;

if ($event === 'recording.completed' && is_array($obj) === true) {
    $zoomMeetingId = (string) ($obj['id'] ?? '');
    $shareUrl      = (string) ($obj['share_url'] ?? '');
    if ($zoomMeetingId !== '' && $shareUrl !== '') {
        $db = App::db();
        $stmt = $db->prepare('UPDATE tblZoomMeeting SET recordingUrl = ? WHERE zoomMeetingId = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ss', $shareUrl, $zoomMeetingId);
            $stmt->execute();
            $stmt->close();
        }
        Logger::activity('ZoomRecordingReady', 'Recording ready for meeting ' . $zoomMeetingId, 0);
    }
}

echo json_encode(['ok' => true]);
exit();
