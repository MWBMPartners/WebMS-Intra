<?php
// Path: public_html/payments/webhook.php
/**
 * Payments — webhook receiver. Public route (no portal auth) — gated
 * exclusively by provider HMAC signature verification.
 *
 *   /payments/webhook?provider=stripe
 *
 * @package   Portal\Payments
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\Payments;

header('Content-Type: application/json');

$provider = (string) ($_GET['provider'] ?? 'stripe');
if (in_array($provider, ['stripe','paypal','gocardless'], true) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown provider']);
    exit();
}

$body = (string) file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $headers[$k] = $v;
        $name = str_replace('_', '-', ucwords(strtolower(substr($k, 5)), '_'));
        $headers[$name] = $v;
    }
}

if (Payments::ingestWebhook($provider, $body, $headers) === false) {
    http_response_code(401);
    echo json_encode(['error' => 'signature']);
    exit();
}

echo json_encode(['ok' => true]);
exit();
