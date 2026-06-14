<?php
// Path: _core/WebhookDispatcher.php
/**
 * -----------------------------------------------------------------------------
 * WebhookDispatcher — outbound webhook framework 🪝 (#324)
 * -----------------------------------------------------------------------------
 * Call `WebhookDispatcher::emit($eventType, $payload)` from anywhere a
 * notable event happens (prayer-request created, expense approved,
 * livestream started, etc.). Resolves every active webhook subscribed to
 * the event, INSERTs a delivery row, and POSTs the signed payload.
 *
 * Failed deliveries are left in `pending` / `failed` state with attemptCount
 * so a cron-driven retry can pick them up — see DEV_NOTES "Webhooks setup"
 * for the cron entry. v1 ships best-effort synchronous delivery; the retry
 * worker is a v1.1 follow-up (filed separately).
 *
 * Signature scheme:
 *   X-Webhook-Event:     <eventType>
 *   X-Webhook-Delivery:  <tblWebhookDeliveries.deliveryID>
 *   X-Webhook-Signature: sha256=<hmac_sha256(body, signingSecret)>
 *
 * Receivers verify by recomputing the HMAC over the raw request body
 * using the signing secret they configured at webhook creation time.
 *
 * v1.1 follow-ups (intentionally NOT in this PR):
 *   • Async retry worker (cron-driven, exponential backoff, dead-letter).
 *   • Admin CRUD UI at /admin/integrations/webhooks (routes reserved in 111).
 *   • Replay-from-UI button on a single delivery.
 *   • Per-event payload schema / OpenAPI annotations.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/324
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class WebhookDispatcher
{
    /**
     * Emit an event to every active webhook subscribed to it on the active site.
     *
     * Non-fatal: if anything goes wrong, the dispatcher logs + returns false.
     * Never throws — call sites are typically inside successful business flows
     * and the webhook is observability, not the source of truth.
     *
     * @param string $eventType  Pattern: 'app.action' (e.g. 'prayer-requests.created').
     * @param array  $payload    Will be JSON-encoded into the request body.
     *
     * @return int Number of webhooks the event was queued to (0 if none matched).
     */
    public static function emit(string $eventType, array $payload): int
    {
        if ((App::settings('webhooks.enabled') ?? 'true') !== 'true') {
            return 0;
        }

        $db = App::db();
        if (!$db instanceof mysqli) {
            return 0;
        }

        $siteId = Site::id();

        // 🔍 Find webhooks subscribed to this event (or to 'all').
        //    `FIND_IN_SET` over a comma-joined list lets us match without
        //    introducing a junction table for v1.
        $stmt = $db->prepare(
            'SELECT webhookID, targetUrl, signingSecret FROM tblWebhooks '
            . 'WHERE siteID = ? AND isActive = 1 '
            . '  AND (eventTypes = "all" OR FIND_IN_SET(?, eventTypes) > 0 OR FIND_IN_SET("all", eventTypes) > 0)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('is', $siteId, $eventType);
        $stmt->execute();
        $result = $stmt->get_result();

        $deliveryCount = 0;
        // 🌐 Augment payload with stable metadata so receivers can dedupe + audit.
        $envelope = [
            'event'      => $eventType,
            'siteID'     => $siteId,
            'occurredAt' => date('c'),
            'data'       => $payload,
        ];
        $body = (string) json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $bodyHash = hash('sha256', $body);

        while (($row = $result->fetch_assoc()) !== null) {
            $webhookId = (int) $row['webhookID'];
            $deliveryId = self::recordDelivery($db, $webhookId, $eventType, $body, $bodyHash);
            if ($deliveryId > 0) {
                self::deliverNow($db, $deliveryId, $webhookId, (string) $row['targetUrl'], (string) $row['signingSecret'], $eventType, $body);
                $deliveryCount++;
            }
        }
        $stmt->close();

        return $deliveryCount;
    }

    /**
     * INSERT the delivery row in 'pending' state and return its ID.
     */
    private static function recordDelivery(
        mysqli $db,
        int $webhookId,
        string $eventType,
        string $body,
        string $bodyHash
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO tblWebhookDeliveries (webhookID, eventType, payload, payloadHash, status, attemptCount) '
            . 'VALUES (?, ?, ?, ?, "pending", 0)'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('isss', $webhookId, $eventType, $body, $bodyHash);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Best-effort synchronous POST. Updates the delivery row's status +
     * responseCode + attemptCount. Never throws.
     */
    private static function deliverNow(
        mysqli $db,
        int $deliveryId,
        int $webhookId,
        string $targetUrl,
        string $signingSecret,
        string $eventType,
        string $body
    ): void {
        $signature = 'sha256=' . hash_hmac('sha256', $body, $signingSecret);
        $timeout   = (int) (App::settings('webhooks.timeout') ?? '10');

        $code  = 0;
        $resp  = '';
        $error = '';

        $ch = curl_init($targetUrl);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'User-Agent: WebMS-Intra-Webhook/1.0',
                    'X-Webhook-Event: ' . $eventType,
                    'X-Webhook-Delivery: ' . $deliveryId,
                    'X-Webhook-Signature: ' . $signature,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(1, $timeout),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $resp = is_string($body) ? mb_substr($body, 0, 500) : '';
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code === 0) {
                $error = curl_error($ch);
            }
            curl_close($ch);
        }

        // 📥 Update the delivery row. 2xx counts as 'delivered'; anything else
        //    is 'failed' and a future retry worker will pick it up.
        $status = ($code >= 200 && $code < 300) ? 'delivered' : 'failed';

        $stmt = $db->prepare(
            'UPDATE tblWebhookDeliveries '
            . 'SET status = ?, responseCode = ?, responseSnippet = ?, attemptCount = attemptCount + 1, lastAttemptAt = NOW() '
            . 'WHERE deliveryID = ?'
        );
        if ($stmt !== false) {
            $errorOrResp = $error !== '' ? mb_substr('curl: ' . $error, 0, 500) : $resp;
            $stmt->bind_param('sisi', $status, $code, $errorOrResp, $deliveryId);
            $stmt->execute();
            $stmt->close();
        }

        // 📝 Touch lastDeliveryAt on the parent webhook.
        $stmt = $db->prepare('UPDATE tblWebhooks SET lastDeliveryAt = NOW() WHERE webhookID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $webhookId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
