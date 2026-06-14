<?php
// Path: _apps/api/push/unsubscribe.php
/**
 * -----------------------------------------------------------------------------
 * Web Push — Unsubscribe endpoint 🔕 (#322)
 * -----------------------------------------------------------------------------
 * POST { "endpoint": "..." }. Soft-deletes the matching tblPushSubscriptions
 * row (isActive=0) so the sender skips it on next dispatch but the row stays
 * around for resubscribe analytics.
 *
 * @package   Portal\Api\Push
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit();
}

$bodyRaw = file_get_contents('php://input') ?: '';
$payload = json_decode($bodyRaw, true);
if (is_array($payload) === false) {
    $payload = [];
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? ''));
if (Auth::verifyCsrf($csrf) === false) {
    Logger::activity('PushUnsubscribeRejected', 'Invalid CSRF on /api/push/unsubscribe');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

$endpoint = (string) ($payload['endpoint'] ?? '');
if ($endpoint === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit();
}

$stmt = $mysqli->prepare(
    'UPDATE tblPushSubscriptions SET isActive = 0 WHERE endpoint = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('s', $endpoint);
    $stmt->execute();
    $stmt->close();
}

Logger::activity('PushUnsubscribed', 'Endpoint=' . substr($endpoint, 0, 80));
echo json_encode(['ok' => true]);
