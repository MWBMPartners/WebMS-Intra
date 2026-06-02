<?php
// Path: _core/Payments.php
/**
 * -----------------------------------------------------------------------------
 * Payment processor + provider abstraction 💳
 * -----------------------------------------------------------------------------
 * Pluggable payment processor:
 *
 *   payments.provider = stripe | paypal | gocardless
 *
 * The portal NEVER sees raw card details — all sensitive entry happens on
 * provider-hosted UI (Stripe Checkout, PayPal Smart Buttons, GoCardless Pro).
 * We only handle:
 *
 *   • createCheckoutSession — provider redirect URL we send the user to
 *   • verifyWebhook         — HMAC verification before any side effect
 *   • handleWebhook         — recording + side-effect dispatch
 *   • refund                — admin-triggered reversal
 *
 * On `payment_intent.succeeded` we walk tblPayment.purpose:
 *   - 'giving'  → insert tblGivingEntry (if Giving app installed).
 *   - 'pledge'  → mark tblProjectPledge fulfilled (Projects::fulfilPledge).
 *
 * Stripe is fully wired today. PayPal + GoCardless branches return
 * "not-implemented" so a misconfigured provider fails visibly rather than
 * silently routing through the wrong rail. Both are scoped as follow-up PRs
 * per the #268 issue brief.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Payments
{
    public const PURPOSES = ['giving','pledge','membership','other'];

    /**
     * Begin a checkout flow for the configured provider. Returns the
     * redirect URL the caller should send the user to, or null on failure.
     *
     * `purpose` + `purposeRef` tag the pending tblPayment row so the
     * webhook can fire the right side effect on success.
     */
    public static function startCheckout(
        int $siteId,
        ?int $userId,
        int $amountPence,
        string $currency,
        string $description,
        string $purpose,
        ?string $purposeRef = null
    ): ?string {
        if (in_array($purpose, self::PURPOSES, true) === false) {
            $purpose = 'other';
        }

        $settings = App::settings()['payments'] ?? [];
        $provider = (string) ($settings['provider'] ?? 'stripe');

        $idem = bin2hex(random_bytes(20));
        $providerRef = 'pending-' . $idem;

        // Insert pending row first so the webhook can update it idempotently.
        $db = App::db();
        $ins = $db->prepare(
            'INSERT INTO tblPayment (siteID, userID, provider, providerRef, idempotencyKey, '
            . 'amountPence, currency, status, purpose, purposeRef) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, ?)'
        );
        if ($ins === false) {
            return null;
        }
        $ins->bind_param('iisssisss', $siteId, $userId, $provider, $providerRef, $idem, $amountPence, $currency, $purpose, $purposeRef);
        $ins->execute();
        $paymentId = (int) $ins->insert_id;
        $ins->close();

        $url = null;
        $providerRefReal = null;
        if ($provider === 'stripe') {
            [$url, $providerRefReal] = self::stripeCreateCheckout($settings, $amountPence, $currency, $description, $idem, $paymentId);
        } else {
            $u = $db->prepare('UPDATE tblPayment SET status = "failed", errorMsg = ? WHERE paymentID = ?');
            if ($u !== false) {
                $err = $provider . '-not-implemented';
                $u->bind_param('si', $err, $paymentId);
                $u->execute();
                $u->close();
            }
            return null;
        }

        if ($providerRefReal !== null) {
            $u = $db->prepare('UPDATE tblPayment SET providerRef = ? WHERE paymentID = ?');
            if ($u !== false) {
                $u->bind_param('si', $providerRefReal, $paymentId);
                $u->execute();
                $u->close();
            }
        }
        return $url;
    }

    /**
     * Verify + record an incoming webhook. Returns true on accept, false
     * on signature mismatch. Always inserts a tblWebhookEvent row for
     * audit/replay before evaluating side effects.
     */
    public static function ingestWebhook(string $provider, string $rawBody, array $headers): bool
    {
        $db = App::db();
        $settings = App::settings()['payments'] ?? [];

        $verified = false;
        $eventType = '';
        $providerRef = null;
        $parsed = null;

        if ($provider === 'stripe') {
            $verified = self::stripeVerifySignature($settings, $rawBody, $headers);
            if ($verified === true) {
                $parsed = json_decode($rawBody, true);
                if (is_array($parsed) === true) {
                    $eventType   = (string) ($parsed['type'] ?? '');
                    $providerRef = (string) ($parsed['id'] ?? '');
                }
            }
        }
        // PayPal / GoCardless branches → follow-up PRs.

        $verifiedFlag = $verified === true ? 1 : 0;
        $ins = $db->prepare(
            'INSERT IGNORE INTO tblWebhookEvent (provider, eventType, providerRef, payload, verified, receivedAt) '
            . 'VALUES (?, ?, ?, ?, ?, NOW())'
        );
        if ($ins !== false) {
            $ins->bind_param('ssssi', $provider, $eventType, $providerRef, $rawBody, $verifiedFlag);
            $ins->execute();
            $ins->close();
        }

        if ($verified === false) {
            return false;
        }

        if ($provider === 'stripe' && $parsed !== null) {
            self::handleStripeEvent($parsed);
        }
        return true;
    }

    /**
     * Refund a succeeded payment. Returns true on provider accept.
     */
    public static function refund(int $paymentId, int $siteId): bool
    {
        $db = App::db();
        $row = null;
        $stmt = $db->prepare('SELECT provider, providerRef, status FROM tblPayment WHERE paymentID = ? AND siteID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $paymentId, $siteId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($row === null || (string) $row['status'] !== 'succeeded') {
            return false;
        }

        $settings = App::settings()['payments'] ?? [];
        $ok = false;
        if ((string) $row['provider'] === 'stripe') {
            $ok = self::stripeRefund($settings, (string) $row['providerRef']);
        }
        if ($ok === true) {
            $u = $db->prepare('UPDATE tblPayment SET status = "refunded" WHERE paymentID = ?');
            if ($u !== false) {
                $u->bind_param('i', $paymentId);
                $u->execute();
                $u->close();
            }
        }
        return $ok;
    }

    // -------------------------------------------------------------------------
    // 🔵 Stripe implementation
    // -------------------------------------------------------------------------

    /**
     * Create a Stripe Checkout Session. Stripe handles all PCI-scope card
     * entry on its own domain; we receive the success/cancel redirect.
     *
     * @link https://stripe.com/docs/api/checkout/sessions/create
     */
    private static function stripeCreateCheckout(array $settings, int $amountPence, string $currency, string $description, string $idem, int $paymentId): array
    {
        $key = (string) ($settings['stripe']['secret'] ?? '');
        if ($key === '') {
            return [null, null];
        }
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        $params = [
            'mode'                    => 'payment',
            'success_url'             => $base . '/payments/return?payment=' . $paymentId . '&result=ok',
            'cancel_url'              => $base . '/payments/return?payment=' . $paymentId . '&result=cancel',
            'client_reference_id'     => (string) $paymentId,
            'payment_intent_data[metadata][paymentID]' => (string) $paymentId,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]'     => strtolower($currency),
            'line_items[0][price_data][unit_amount]'  => (string) $amountPence,
            'line_items[0][price_data][product_data][name]' => $description,
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: ' . $idem,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return [null, null];
        }
        $body = json_decode((string) $resp, true);
        if (is_array($body) === false) {
            return [null, null];
        }
        return [(string) ($body['url'] ?? ''), (string) ($body['id'] ?? '')];
    }

    /**
     * Verify a Stripe webhook v1 signature. Header is `Stripe-Signature:
     * t=TS,v1=HEX,…`. Expected = HMAC_SHA256(secret, TS + '.' + body).
     * Constant-time compare; 5-minute timestamp window.
     *
     * @link https://stripe.com/docs/webhooks/signatures
     */
    private static function stripeVerifySignature(array $settings, string $body, array $headers): bool
    {
        $secret = (string) ($settings['stripe']['webhookSecret'] ?? '');
        $sig    = (string) ($headers['Stripe-Signature'] ?? $headers['HTTP_STRIPE_SIGNATURE'] ?? '');
        if ($secret === '' || $sig === '') {
            return false;
        }
        $ts = null;
        $v1 = null;
        foreach (explode(',', $sig) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $ts = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1 = $kv[1];
            }
        }
        if ($ts === null || $v1 === null || abs(time() - $ts) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
        return hash_equals($expected, $v1);
    }

    /**
     * Dispatch Stripe events. We only care about checkout.session.completed
     * and payment_intent.succeeded today; anything else just lands in
     * tblWebhookEvent for audit.
     */
    private static function handleStripeEvent(array $event): void
    {
        $type   = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? null;
        if (is_array($object) === false) {
            return;
        }

        if ($type === 'checkout.session.completed') {
            $paymentId = (int) ($object['client_reference_id'] ?? 0);
            $intentId  = (string) ($object['payment_intent'] ?? '');
            if ($paymentId > 0) {
                self::markPaymentSucceeded($paymentId, $intentId);
            }
        } elseif ($type === 'payment_intent.succeeded') {
            $paymentId = (int) ($object['metadata']['paymentID'] ?? 0);
            $intentId  = (string) ($object['id'] ?? '');
            if ($paymentId > 0) {
                self::markPaymentSucceeded($paymentId, $intentId);
            }
        } elseif ($type === 'charge.refunded') {
            $intentId = (string) ($object['payment_intent'] ?? '');
            if ($intentId !== '') {
                $db = App::db();
                $u = $db->prepare('UPDATE tblPayment SET status = "refunded" WHERE provider = "stripe" AND providerRef = ?');
                if ($u !== false) {
                    $u->bind_param('s', $intentId);
                    $u->execute();
                    $u->close();
                }
            }
        }
    }

    /**
     * Refund via Stripe by the payment_intent reference. The webhook
     * `charge.refunded` will arrive shortly after — that also updates
     * status, so this is just the kick.
     *
     * @link https://stripe.com/docs/api/refunds/create
     */
    private static function stripeRefund(array $settings, string $intentId): bool
    {
        $key = (string) ($settings['stripe']['secret'] ?? '');
        if ($key === '' || $intentId === '') {
            return false;
        }
        $ch = curl_init('https://api.stripe.com/v1/refunds');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['payment_intent' => $intentId]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    // -------------------------------------------------------------------------
    // 🔗 Side-effect dispatch
    // -------------------------------------------------------------------------

    /**
     * Mark a tblPayment row succeeded and fan out to whichever app the
     * `purpose` field targets. Idempotent — re-firing the webhook won't
     * double-book.
     */
    private static function markPaymentSucceeded(int $paymentId, string $providerRef): void
    {
        $db = App::db();
        $row = null;
        $stmt = $db->prepare('SELECT * FROM tblPayment WHERE paymentID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($row === null || (string) $row['status'] === 'succeeded') {
            return;
        }

        $u = $db->prepare(
            'UPDATE tblPayment SET status = "succeeded", providerRef = ?, occurredAt = NOW() WHERE paymentID = ?'
        );
        if ($u !== false) {
            $u->bind_param('si', $providerRef, $paymentId);
            $u->execute();
            $u->close();
        }

        $purpose = (string) $row['purpose'];
        $ref     = (string) ($row['purposeRef'] ?? '');
        $siteId  = (int) $row['siteID'];
        $userId  = $row['userID'] !== null ? (int) $row['userID'] : null;
        $amount  = (int) $row['amountPence'];
        $currency = (string) $row['currency'];

        if ($purpose === 'giving' && $userId !== null && $ref !== '') {
            // ref = givingCategoryID (string-encoded integer).
            $categoryId = (int) $ref;
            if ($categoryId > 0) {
                try {
                    $ins = $db->prepare(
                        'INSERT INTO tblGivingEntry (siteID, donorID, categoryID, amountPence, currency, donatedAt, method, reference, recordedByID) '
                        . 'VALUES (?, ?, ?, ?, ?, CURDATE(), "card", ?, ?)'
                    );
                    if ($ins !== false) {
                        $reference = 'payment:' . $paymentId;
                        $ins->bind_param('iiiissi', $siteId, $userId, $categoryId, $amount, $currency, $reference, $userId);
                        $ins->execute();
                        $ins->close();
                    }
                } catch (\Throwable $ignored) {
                    // Giving app not installed — leave payment recorded.
                }
            }
        } elseif ($purpose === 'pledge' && $ref !== '') {
            // ref = pledgeID (string-encoded integer).
            $pledgeId = (int) $ref;
            if ($pledgeId > 0) {
                try {
                    Projects::fulfilPledge($pledgeId, $siteId, null);
                } catch (\Throwable $ignored) {
                    // Projects app not installed — payment still recorded.
                }
            }
        }
    }
}
