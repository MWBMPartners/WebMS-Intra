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
 * Failed deliveries are left in `failed` state with attemptCount + a
 * computed `nextRetryAt`, and `cron/webhook-retry.php` (migration 166,
 * #324 v1.1) sweeps them via `retryDue()` below — exponential backoff
 * (base 60s × 2^attempts, capped ~6h), dead-lettered ('dead' status) once
 * `attemptCount` reaches `MAX_ATTEMPTS`. See DEV_NOTES "Webhooks setup" for
 * the cron entry.
 *
 * Signature scheme:
 *   X-Webhook-Event:     <eventType>
 *   X-Webhook-Delivery:  <tblWebhookDeliveries.deliveryID>
 *   X-Webhook-Signature: sha256=<hmac_sha256(body, signingSecret)>
 *
 * Receivers verify by recomputing the HMAC over the raw request body
 * using the signing secret they configured at webhook creation time.
 *
 * `emit()`'s initial synchronous POST and `retryDue()`'s later re-attempts
 * both funnel through the SAME private `attemptDelivery()` — one signing +
 * cURL + result-recording implementation, no duplicated retry-vs-first-try
 * logic to drift apart (#324 v1.1 review note).
 *
 * WHERE A DELIVERY MAY GO, AND WHAT IS KEPT OF IT (#615)
 *   An administrator types the target address, and until #615 it was posted
 *   to with plain curl: nothing refused an address inside the hosting
 *   company's network or on this machine (a database port, an internal
 *   admin panel, the "metadata" address cloud servers hand their own
 *   passwords out from), nothing pinned the connection to the address that
 *   was checked (because nothing was checked), and the first 500 characters
 *   of whatever answered were stored on the delivery row. Every delivery now
 *   goes through `SafeFetch::post()`, the ONE place an address a person
 *   typed is sent to: it checks the address AT THE MOMENT OF SENDING (a
 *   name that has been re-pointed since it was saved is refused now), pins
 *   the connection to the checked addresses, ignores proxies, follows no
 *   redirect, caps the time and the reply, and never sets CURLOPT_ENCODING
 *   (#716).
 *
 *   WHICH PORT NUMBERS: 443 and 80 unless the installation's global
 *   administrator has allowed any port on a public address
 *   (`WebhookPorts`, the owner's decision D-102). A webhook whose address
 *   is WRITTEN in a way that is not allowed (a port, a user name or
 *   password inside it — which curl used to send as a basic sign-in) is
 *   recorded `not-allowed`; one that LEADS somewhere private is `refused`.
 *
 *   `responseSnippet` NO LONGER HOLDS THE OTHER SERVER'S REPLY. It holds one
 *   word from `SafeFetch::POST_REASONS` when a delivery did not succeed, and
 *   NULL when it did. The reply was shown on no page, so it bought nothing,
 *   and it was the one way a request aimed somewhere it should not go could
 *   be READ back — the reply of a server somebody chose is exactly what a
 *   request-forgery attempt wants, and what is in it is decided by whoever
 *   runs that server, personal details included. curl's own error text went
 *   there too and named the host ("Failed to connect to <name> port 80").
 *   Rows written before #615 may still hold a reply until the nightly
 *   clear-out empties it (#713); the admin page shows only the known words,
 *   never the stored text.
 *
 *   THE ADDRESS IS NEVER WRITTEN DOWN by this class: not on the delivery
 *   row, not in PHP's error log (an exception is logged by its CLASS, not
 *   its message, because a message can quote what it was handed), and
 *   `SafeFetch::post()` records a refusal with no part of the address.
 *
 * v1.2 follow-ups (intentionally NOT in this PR):
 *   • Admin CRUD UI at /admin/integrations/webhooks (routes reserved in 111).
 *   • Replay-from-UI button on a single delivery.
 *   • Per-event payload schema / OpenAPI annotations.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/324
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class WebhookDispatcher
{
    /**
     * The longest one delivery may take, in seconds, whatever
     * `webhooks.timeout` says (#615). `emit()` sends SYNCHRONOUSLY, inside the
     * request that caused the event, so a receiver that holds the connection
     * open holds that person's page open with it; before #615 the setting had
     * no ceiling at all. The default (10) is well inside it.
     */
    private const MAX_TIMEOUT_SECONDS = 30;

    /**
     * The most of a receiver's reply that is read before the connection is
     * cut. The reply is thrown away (see the class header), so this only
     * stops a hostile receiver streaming for ever.
     */
    private const MAX_REPLY_BYTES = 65536;

    /**
     * How long one delivery may take, from the `webhooks.timeout` setting: a
     * whole number of seconds from 1 to `MAX_TIMEOUT_SECONDS`. Missing means
     * the built-in 10; anything that is not a positive number becomes 1
     * rather than "no limit" (a 0 handed to curl would be no limit at all).
     *
     * Public so the self-test can pin the arithmetic directly: the ceiling
     * cannot be watched from outside without waiting half a minute.
     */
    public static function timeoutSeconds(mixed $setting): int
    {
        $seconds = $setting === null ? 10 : (int) (is_scalar($setting) === true ? $setting : 0);
        return max(1, min(self::MAX_TIMEOUT_SECONDS, $seconds));
    }

    // 🔁 Retry ceiling shared by attemptDelivery()'s dead-letter check and
    // retryDue()'s own selection filter (attemptCount < MAX_ATTEMPTS) — one
    // constant so the two can never drift apart (#324 v1.1).
    private const MAX_ATTEMPTS = 6;

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
                // 🆕 Freshly INSERTed row always starts at attemptCount = 0 —
                // no extra SELECT needed before the very first attempt.
                self::attemptDelivery($db, $deliveryId, $webhookId, (string) $row['targetUrl'], (string) $row['signingSecret'], $eventType, $body, 0);
                $deliveryCount++;
            }
        }
        $stmt->close();

        return $deliveryCount;
    }

    /**
     * Async retry sweep — called by `cron/webhook-retry.php` (migration 166,
     * #324 v1.1). Selects up to 100 due retries (oldest-first), re-delivers
     * each through the SAME `attemptDelivery()` the initial `emit()` uses,
     * and lets that method's own status/backoff logic advance each row to
     * 'delivered', back to 'failed' with a new `nextRetryAt`, or to the
     * terminal 'dead' state once `attemptCount` reaches `MAX_ATTEMPTS`.
     *
     * NEVER throws — any failure (bad connection, a single row's own
     * exception) is caught, logged via `error_log()`, and folded into the
     * returned summary rather than propagated. Safe to call unconditionally
     * from a token-gated cron endpoint.
     *
     * @return array{checked: int, delivered: int, failed: int, dead: int}
     */
    public static function retryDue(): array
    {
        $summary = ['checked' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0];

        try {
            $db = App::db();
            if (!$db instanceof mysqli) {
                return $summary;
            }

            // 🔍 status='failed' (never 'pending'/'delivered'/'dead') AND
            // still under the attempt ceiling AND either never scheduled or
            // its backoff window has elapsed. Oldest createdAt first so a
            // backlog drains in the order it accrued. idx_wd_retry
            // (status, nextRetryAt) — migration 166 — keeps this sargable.
            $maxAttempts = self::MAX_ATTEMPTS;
            $stmt = $db->prepare(
                'SELECT d.deliveryID, d.webhookID, d.eventType, d.payload, d.attemptCount, '
                . '       w.targetUrl, w.signingSecret '
                . 'FROM tblWebhookDeliveries d '
                . 'JOIN tblWebhooks w ON w.webhookID = d.webhookID '
                . "WHERE d.status = 'failed' AND d.attemptCount < ? "
                . '  AND (d.nextRetryAt IS NULL OR d.nextRetryAt <= NOW()) '
                . 'ORDER BY d.createdAt ASC '
                . 'LIMIT 100'
            );
            if ($stmt === false) {
                return $summary;
            }
            $stmt->bind_param('i', $maxAttempts);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while (($row = $result->fetch_assoc()) !== null) {
                $rows[] = $row;
            }
            $stmt->close();

            foreach ($rows as $row) {
                $summary['checked']++;
                $status = self::attemptDelivery(
                    $db,
                    (int) $row['deliveryID'],
                    (int) $row['webhookID'],
                    (string) $row['targetUrl'],
                    (string) $row['signingSecret'],
                    (string) $row['eventType'],
                    (string) $row['payload'],
                    (int) $row['attemptCount']
                );
                if (isset($summary[$status]) === true) {
                    $summary[$status]++;
                }
            }
        } catch (\Throwable $e) {
            // The CLASS, never the message: a message can quote what it was
            // handed, and what this method handles includes target addresses
            // (#615).
            error_log('WebhookDispatcher::retryDue() failed (' . get_class($e) . ', code ' . (string) $e->getCode() . ')');
        }

        return $summary;
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
     * SHARED sign + send + record-result implementation — used by BOTH
     * `emit()`'s initial synchronous attempt and `retryDue()`'s later
     * re-attempts (#324 v1.1 review note: one implementation, not two that
     * could silently drift apart). Signs the body, POSTs it, then advances
     * the delivery row to its next state:
     *
     *   • 2xx response         → 'delivered', nextRetryAt cleared (NULL).
     *   • non-2xx, attempts
     *     reaching MAX_ATTEMPTS → 'dead' (terminal), nextRetryAt cleared.
     *   • non-2xx, attempts
     *     still under the cap  → 'failed', nextRetryAt = NOW() + backoff
     *                             (base 60s × 2^attempts, capped at 21600s
     *                             / 6h). Computed in SQL via
     *                             `DATE_ADD(NOW(), INTERVAL ? SECOND)`
     *                             rather than PHP's own clock, so a skewed
     *                             app-server clock can never desync
     *                             `nextRetryAt` from the DB's own `NOW()`
     *                             that `retryDue()`'s WHERE clause compares
     *                             it against.
     *
     * The send is `SafeFetch::post()` (#615): a refused address is recorded
     * exactly like any other failure — the same backoff, the same
     * dead-lettering — with `responseCode` 0 and the word `refused`.
     *
     * Never throws — any send/DB failure is caught, logged, and reported
     * back as a 'failed' outcome so the caller's own loop is never broken
     * by one bad delivery.
     *
     * @param int $currentAttemptCount attemptCount BEFORE this attempt (0
     *        for a brand-new delivery straight out of recordDelivery()).
     *
     * @return string The delivery's resulting status: 'delivered'|'failed'|'dead'.
     */
    private static function attemptDelivery(
        mysqli $db,
        int $deliveryId,
        int $webhookId,
        string $targetUrl,
        string $signingSecret,
        string $eventType,
        string $body,
        int $currentAttemptCount
    ): string {
        try {
            $signature = 'sha256=' . hash_hmac('sha256', $body, $signingSecret);
            $timeout   = self::timeoutSeconds(App::settings('webhooks.timeout'));

            // 📤 Sent through SafeFetch (#615): checked NOW, pinned to the
            //    checked addresses, no proxy, no redirect, capped. The
            //    signing headers and the body are exactly what they always
            //    were, so a receiver notices nothing.
            $sent = SafeFetch::post(
                $targetUrl,
                $body,
                [
                    'Content-Type: application/json',
                    'User-Agent: WebMS-Intra-Webhook/1.0',
                    'X-Webhook-Event: ' . $eventType,
                    'X-Webhook-Delivery: ' . $deliveryId,
                    'X-Webhook-Signature: ' . $signature,
                ],
                [
                    'timeoutSeconds'        => $timeout,
                    'connectTimeoutSeconds' => min(5, $timeout),
                    'maxBytes'              => self::MAX_REPLY_BYTES,
                    // 🚪 D-102: other port numbers only when the
                    //    INSTALLATION allows them (never an organisation's row).
                    'anyPort'               => WebhookPorts::allowsAnyPort(),
                ]
            );
            $code = (int) $sent['status'];

            $delivered       = $sent['ok'] === true;
            $newAttemptCount = $currentAttemptCount + 1;

            if ($delivered === true) {
                $status = 'delivered';
            } elseif ($newAttemptCount >= self::MAX_ATTEMPTS) {
                // 💀 Dead-letter — MAX_ATTEMPTS reached without a 2xx.
                $status = 'dead';
            } else {
                $status = 'failed';
            }

            // 🏷️ One word, never the reply and never the address (#615): see
            //    the class header. NULL once delivered — there is nothing to
            //    say, and the nightly clear-out (#713) then has nothing to do.
            $reason = $delivered === true ? null : (string) $sent['reason'];

            // ⏳ Exponential backoff — only 'failed' rows ever get a future
            // nextRetryAt; 'delivered'/'dead' both clear it (NULL) since
            // neither is ever retried again.
            $nextRetrySql   = 'NULL';
            $backoffSeconds = 0;
            if ($status === 'failed') {
                $backoffSeconds = min(21600, 60 * (2 ** $newAttemptCount));
                $nextRetrySql   = 'DATE_ADD(NOW(), INTERVAL ? SECOND)';
            }

            $stmt = $db->prepare(
                'UPDATE tblWebhookDeliveries '
                . 'SET status = ?, responseCode = ?, responseSnippet = ?, attemptCount = ?, lastAttemptAt = NOW(), nextRetryAt = ' . $nextRetrySql . ' '
                . 'WHERE deliveryID = ?'
            );
            if ($stmt !== false) {
                if ($status === 'failed') {
                    $stmt->bind_param('sisiii', $status, $code, $reason, $newAttemptCount, $backoffSeconds, $deliveryId);
                } else {
                    $stmt->bind_param('sisii', $status, $code, $reason, $newAttemptCount, $deliveryId);
                }
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

            return $status;
        } catch (\Throwable $e) {
            // The CLASS, never the message (#615): see retryDue().
            error_log('WebhookDispatcher::attemptDelivery() failed for delivery #' . $deliveryId . ' (' . get_class($e) . ', code ' . (string) $e->getCode() . ')');
            return 'failed';
        }
    }
}
