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
 * Stripe AND PayPal (Orders v2) are fully wired. GoCardless still returns
 * "not-implemented" so a misconfigured provider fails visibly rather than
 * silently routing through the wrong rail; scoped as a follow-up PR per the
 * #268 issue brief.
 *
 * ★ S1 — the single security-critical control ★
 * `markPaymentSucceeded()` is the ONE choke point that flips a row to
 * `succeeded` and fans out into Giving/Projects. Every PayPal call site MUST
 * pass the amount + currency it actually observed from PayPal (capture
 * response / GET-order reconcile / verified webhook `resource.amount`) —
 * a mismatch against the pending row's own amountPence/currency marks the
 * row `failed` and NEVER fans out (see the method's own docblock). Stripe
 * call sites keep passing null observed values unchanged (gap-item Q4 —
 * upgrading Stripe to the same gate is a follow-up, see the `// follow-up:`
 * comment in handleStripeEvent()).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

class Payments
{
    public const PURPOSES = ['giving','pledge','membership','other'];

    /**
     * Per-request PayPal OAuth token cache — a single webhook-ingest
     * request can need a token twice (verify-webhook-signature, then
     * capture), and this avoids the second POST. Keyed on
     * sha256(mode.clientId) so a settings change mid-request can never
     * serve a stale token. NOT persisted across requests (shared hosting,
     * no reliable APCu) — one token POST per request is acceptable.
     *
     * @var array{key: string, token: string}|null
     */
    private static ?array $ppTokenCache = null;

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
        } elseif ($provider === 'paypal') {
            [$url, $providerRefReal] = self::paypalCreateCheckout($settings, $amountPence, $currency, $description, $idem, $paymentId);
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
        } elseif ($provider === 'paypal') {
            // 🛡️ S2 — verification via PayPal's own verify-webhook-signature
            // API (not local cert verification). Fails closed on ANY
            // missing input (headers/webhookId/creds/token) — see the
            // method's own docblock.
            $verified = self::paypalVerifyWebhook($settings, $rawBody, $headers);
            if ($verified === true) {
                $parsed = json_decode($rawBody, true);
                if (is_array($parsed) === true) {
                    $eventType   = (string) ($parsed['event_type'] ?? '');
                    $providerRef = (string) ($parsed['id'] ?? ''); // WH-… event id
                }
            }
        }
        // GoCardless branch → follow-up PR.

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
        } elseif ($provider === 'paypal' && $parsed !== null) {
            self::handlePayPalEvent($parsed);
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
        } elseif ((string) $row['provider'] === 'paypal') {
            // 🧾 Invariant: a PayPal row only ever reaches `succeeded` via
            // markPaymentSucceeded($paymentId, $captureId, …) — providerRef
            // is therefore guaranteed to be a CAPTURE id here, never an
            // order id (§5 of the build plan).
            $ok = self::paypalRefund($settings, (string) $row['providerRef']);
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

    /**
     * Provider-dispatched return-page hook, called by return.php BEFORE it
     * renders. PayPal `intent=CAPTURE` orders are NOT charged at approval —
     * an explicit capture call is required, and the return page is the
     * primary (fastest) place to make it; the CHECKOUT.ORDER.APPROVED
     * webhook is the backstop for a payer who approves then never returns.
     *
     * 🛡️ Re-checks the siteID + userID scoping itself — NEVER trusts the
     * caller's own SELECT — so this can only ever act on the calling user's
     * own payment (S9). Stripe rows are untouched (still webhook-
     * authoritative) — zero behaviour change for Stripe.
     *
     * @return string|null The payment's CURRENT status after this call
     *                      ('succeeded'|'pending'|'failed'|'refunded'), or
     *                      null when no matching row exists.
     */
    public static function finalizeReturn(int $paymentId, int $siteId, int $userId, string $result): ?string
    {
        $db = App::db();
        $row = null;
        $stmt = $db->prepare('SELECT * FROM tblPayment WHERE paymentID = ? AND siteID = ? AND userID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('iii', $paymentId, $siteId, $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($row === null) {
            return null;
        }
        if ((string) $row['provider'] !== 'paypal') {
            return (string) $row['status'];
        }

        // 🚫 Cancel: leave the row pending — the payer can retry the same
        // approve link, and the webhook backstop / manual reconciliation
        // otherwise closes it out. Never mark failed on a mere cancel.
        if ((string) $row['status'] === 'pending' && $result === 'ok') {
            $settings = App::settings()['payments'] ?? [];
            self::paypalCaptureOrder($settings, $row);

            $stmt = $db->prepare('SELECT status FROM tblPayment WHERE paymentID = ? AND siteID = ? AND userID = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('iii', $paymentId, $siteId, $userId);
                $stmt->execute();
                $fresh = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($fresh !== null) {
                    return (string) $fresh['status'];
                }
            }
        }
        return (string) $row['status'];
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
                // follow-up: $object carries amount_total/currency — passing
                // them through as observed values would extend the S1
                // integrity gate to Stripe (gap-item Q4). Scoped out of this
                // PR to keep the Stripe diff zero; behaviour unchanged.
                self::markPaymentSucceeded($paymentId, $intentId);
            }
        } elseif ($type === 'payment_intent.succeeded') {
            $paymentId = (int) ($object['metadata']['paymentID'] ?? 0);
            $intentId  = (string) ($object['id'] ?? '');
            if ($paymentId > 0) {
                // follow-up: see the note above — $object['amount']/
                // ['currency'] could feed the same integrity gate later.
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
    // 🟡 PayPal implementation (Orders v2)
    // -------------------------------------------------------------------------
    // Raw cURL, no SDK, 15s timeouts — same shape as the Stripe block above.
    // PayPal `intent=CAPTURE` orders are NOT charged at approval; capture()
    // is an explicit second call, triggered from three converging paths that
    // all funnel through paypalHandleCaptureResult() → markPaymentSucceeded()
    // — see §2/§3/§4 of the build plan (issue #268) for the full flow.

    /**
     * Strict minor-units parser for PayPal's decimal-string money amounts
     * (e.g. "12.34"). Companion formatter is the sprintf('%d.%02d', …) used
     * in paypalCreateCheckout(). Returns null on ANY unexpected shape
     * ("10.5", "1,000.00", scientific notation, zero-decimal values) — the
     * caller treats null as a hard integrity failure, never a "skip the
     * check" loophole. Never `(float)` casts, never locale-dependent parsing.
     */
    private static function paypalMoneyToPence(string $value): ?int
    {
        if (preg_match('/^([0-9]+)\.([0-9]{2})$/', $value, $m) !== 1) {
            return null;
        }
        return ((int) $m[1]) * 100 + (int) $m[2];
    }

    /**
     * Base API URL for the configured mode. ONLY these two hard-coded
     * constants exist — the mode string is never concatenated into a URL —
     * so a misconfigured/garbage mode value fails SAFE to sandbox, never
     * live money (S1.1 / S8).
     */
    private static function paypalBase(array $settings): string
    {
        $mode = (string) ($settings['paypal']['mode'] ?? 'sandbox');
        return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * OAuth2 client-credentials token. Returns null on ANY failure (missing
     * clientId/secret, non-2xx, non-JSON, missing access_token) — callers
     * treat null as a hard failure (create → [null,null]; webhook verify →
     * false → HTTP 401 → PayPal retries).
     *
     * 🔒 NEVER logs the token, clientId, secret, or raw response body — no
     * error_log/Logger/errorMsg call anywhere in this method may include any
     * of them. Credentials travel via CURLOPT_USERPWD (Basic auth), never in
     * a URL or a log line (S5).
     *
     * @link https://developer.paypal.com/api/rest/authentication/
     */
    private static function paypalAccessToken(array $settings): ?string
    {
        $clientId = (string) ($settings['paypal']['clientId'] ?? '');
        $secret   = (string) ($settings['paypal']['secret'] ?? '');
        if ($clientId === '' || $secret === '') {
            return null;
        }

        $mode = (string) ($settings['paypal']['mode'] ?? 'sandbox');
        $key  = hash('sha256', $mode . $clientId);
        if (self::$ppTokenCache !== null && self::$ppTokenCache['key'] === $key) {
            return self::$ppTokenCache['token'];
        }

        $ch = curl_init(self::paypalBase($settings) . '/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'client_credentials']));
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $secret);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null; // 🔒 never log $resp — may echo credential context
        }
        $body = json_decode((string) $resp, true);
        if (is_array($body) === false || isset($body['access_token']) === false) {
            return null;
        }

        $token = (string) $body['access_token'];
        self::$ppTokenCache = ['key' => $key, 'token' => $token];
        return $token;
    }

    /**
     * Create a PayPal Orders v2 order (intent=CAPTURE). Exact analogue of
     * stripeCreateCheckout() — returns [?approveUrl, ?orderId].
     *
     * @link https://developer.paypal.com/docs/api/orders/v2/#orders_create
     */
    private static function paypalCreateCheckout(array $settings, int $amountPence, string $currency, string $description, string $idem, int $paymentId): array
    {
        $token = self::paypalAccessToken($settings);
        if ($token === null) {
            return [null, null];
        }

        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        // PayPal appends its own &token=…&PayerID=… to these — return.php
        // never reads them; the order to act on always comes from the DB
        // row's own providerRef (S3).
        $returnUrl = $base . '/payments/return?payment=' . $paymentId . '&result=ok';
        $cancelUrl = $base . '/payments/return?payment=' . $paymentId . '&result=cancel';

        // 💷 Integer math only — never float. GBP/EUR/USD (the only three
        // the settings whitelist allows) are all 2-decimal currencies; a
        // zero-decimal currency (e.g. JPY) would need a distinct minor-units
        // map if ever whitelisted.
        $value = sprintf('%d.%02d', intdiv($amountPence, 100), $amountPence % 100);

        // 🧹 Control-char strip + PayPal's own 127-char field limit —
        // belt-and-braces on top of checkout.php's server-built description
        // (S11); this method has no way to know the caller sanitised it.
        $cleanDescription = preg_replace('/[\x00-\x1F\x7F]/', '', $description) ?? '';
        $cleanDescription = mb_substr($cleanDescription, 0, 127);
        $brand = mb_substr(Site::productName(), 0, 127);

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'default',
                'custom_id'    => (string) $paymentId, // server-set, immutable to the payer (S3)
                'invoice_id'   => 'wmsintra-' . $paymentId, // PayPal-side duplicate protection (S4)
                'description'  => $cleanDescription,
                'amount'       => [
                    'currency_code' => strtoupper($currency),
                    'value'         => $value,
                ],
            ]],
            'application_context' => [
                'return_url'          => $returnUrl,
                'cancel_url'          => $cancelUrl,
                'user_action'         => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name'          => $brand,
            ],
        ];

        $ch = curl_init(self::paypalBase($settings) . '/v2/checkout/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'PayPal-Request-Id: ' . $idem, // create-idempotency, mirrors Stripe's Idempotency-Key
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return [null, null];
        }
        $decoded = json_decode((string) $resp, true);
        if (is_array($decoded) === false) {
            return [null, null];
        }

        $orderId = (string) ($decoded['id'] ?? '');
        $links   = is_array($decoded['links'] ?? null) ? $decoded['links'] : [];
        $approve = null;
        foreach ($links as $link) {
            if (is_array($link) === true && (string) ($link['rel'] ?? '') === 'approve') {
                $approve = (string) ($link['href'] ?? '');
                break;
            }
        }
        if ($orderId === '' || $approve === null || $approve === '') {
            return [null, null];
        }
        return [$approve, $orderId];
    }

    /**
     * GET an existing order — used by the 422 ORDER_ALREADY_CAPTURED
     * reconcile path to find the COMPLETED capture without re-capturing.
     *
     * @link https://developer.paypal.com/docs/api/orders/v2/#orders_get
     */
    private static function paypalFetchOrder(array $settings, string $orderId): ?array
    {
        $token = self::paypalAccessToken($settings);
        // 🛡️ S8 belt-and-braces — orderId is our own stored providerRef,
        // but it's guarded before interpolation into a URL path regardless.
        if ($token === null || $orderId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $orderId) !== 1) {
            return null;
        }

        $ch = curl_init(self::paypalBase($settings) . '/v2/checkout/orders/' . rawurlencode($orderId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $resp, true);
        return is_array($decoded) === true ? $decoded : null;
    }

    /**
     * Capture a PayPal order (shared by the return path and the
     * CHECKOUT.ORDER.APPROVED webhook backstop). `$row` is the freshly-
     * loaded PENDING tblPayment row (provider='paypal', providerRef =
     * order id). Never throws — every branch either advances the row or
     * leaves it `pending` for a later attempt.
     *
     * @link https://developer.paypal.com/docs/api/orders/v2/#orders_capture
     */
    private static function paypalCaptureOrder(array $settings, array $row): void
    {
        $paymentId = (int) $row['paymentID'];
        $orderId   = (string) $row['providerRef'];
        $idem      = (string) $row['idempotencyKey'];

        $token = self::paypalAccessToken($settings);
        if ($token === null) {
            return; // row stays pending — retried by the webhook backstop
        }
        // 🛡️ S8 belt-and-braces — see paypalFetchOrder()'s note.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $orderId) !== 1) {
            return;
        }

        $ch = curl_init(self::paypalBase($settings) . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            // capture idempotency — a double-submit (double-click, race
            // with the webhook backstop) returns the ORIGINAL response,
            // never a double charge (S4).
            'PayPal-Request-Id: cap-' . $idem,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = $resp !== false ? json_decode((string) $resp, true) : null;

        $db = App::db();

        if ($code >= 200 && $code < 300 && is_array($decoded) === true) {
            self::paypalHandleCaptureResult($db, $paymentId, $decoded);
            return;
        }

        $issue = '';
        if (is_array($decoded) === true) {
            $details = is_array($decoded['details'] ?? null) ? $decoded['details'] : [];
            if (isset($details[0]) === true && is_array($details[0]) === true) {
                $issue = (string) ($details[0]['issue'] ?? '');
            }
        }

        // 🔁 S4 — 422 ORDER_ALREADY_CAPTURED is the idempotent race (webhook
        // captured first, or a double return-click): reconcile via GET
        // order rather than treating it as an error.
        if ($code === 422 && $issue === 'ORDER_ALREADY_CAPTURED') {
            $order = self::paypalFetchOrder($settings, $orderId);
            if ($order !== null) {
                self::paypalHandleCaptureResult($db, $paymentId, $order);
            }
            return;
        }

        // ❌ Any other non-2xx (INSTRUMENT_DECLINED, ORDER_NOT_APPROVED, …):
        // leave status pending, record ONLY the coarse issue code — never
        // the response body (S12).
        $u = $db->prepare('UPDATE tblPayment SET errorMsg = ? WHERE paymentID = ? AND status = "pending"');
        if ($u !== false) {
            $err = substr('paypal-capture-' . ($issue !== '' ? $issue : (string) $code), 0, 255);
            $u->bind_param('si', $err, $paymentId);
            $u->execute();
            $u->close();
        }
    }

    /**
     * Shared tail of BOTH capture success paths (direct capture response,
     * and the 422-reconcile GET-order response) — both share the exact same
     * `purchase_units[0].payments.captures[0]` shape. Drills to the capture
     * object, cross-checks its echoed custom_id against $paymentId (S3),
     * then hands observed amount+currency to markPaymentSucceeded() — the
     * S1 integrity gate lives there, not here.
     */
    private static function paypalHandleCaptureResult(mysqli $db, int $paymentId, array $orderLike): void
    {
        $units    = is_array($orderLike['purchase_units'] ?? null) ? $orderLike['purchase_units'] : [];
        $unit     = is_array($units[0] ?? null) ? $units[0] : [];
        $payments = is_array($unit['payments'] ?? null) ? $unit['payments'] : [];
        $captures = is_array($payments['captures'] ?? null) ? $payments['captures'] : [];
        $capture  = is_array($captures[0] ?? null) ? $captures[0] : null;
        if ($capture === null) {
            return;
        }

        $status   = (string) ($capture['status'] ?? '');
        $customId = (string) ($capture['custom_id'] ?? ($unit['custom_id'] ?? ''));

        // 🛡️ S3 — the capture's own echoed custom_id must match the
        // payment row we're acting on. A mismatch means PayPal answered
        // about a DIFFERENT order than the one we asked to capture — never
        // fan out on it.
        if ($customId !== (string) $paymentId) {
            $u = $db->prepare('UPDATE tblPayment SET status = "failed", errorMsg = "paypal-customid-mismatch" WHERE paymentID = ? AND status = "pending"');
            if ($u !== false) {
                $u->bind_param('i', $paymentId);
                $u->execute();
                $u->close();
            }
            Logger::activity('PaymentIntegrityFail', 'Payment #' . $paymentId . ' PayPal capture custom_id mismatch', null);
            return;
        }

        // ⏳ S14 — eCheck/delayed captures: leave pending, never succeeded.
        // PAYMENT.CAPTURE.COMPLETED finishes it later.
        if ($status === 'PENDING') {
            $u = $db->prepare('UPDATE tblPayment SET errorMsg = "paypal-capture-pending" WHERE paymentID = ? AND status = "pending"');
            if ($u !== false) {
                $u->bind_param('i', $paymentId);
                $u->execute();
                $u->close();
            }
            return;
        }
        if ($status !== 'COMPLETED') {
            return;
        }

        $captureId  = (string) ($capture['id'] ?? '');
        $amountArr  = is_array($capture['amount'] ?? null) ? $capture['amount'] : [];
        $obsCurrency = (string) ($amountArr['currency_code'] ?? '');
        $obsPence    = self::paypalMoneyToPence((string) ($amountArr['value'] ?? ''));

        // 🛡️ S1 — an unparseable/missing amount is treated as an integrity
        // failure, NEVER as "pass null and let it through". This is what
        // guarantees every real call into markPaymentSucceeded() below
        // carries non-null observed values.
        if ($captureId === '' || $obsPence === null || $obsCurrency === '') {
            $u = $db->prepare('UPDATE tblPayment SET status = "failed", errorMsg = "paypal-amount-unparseable" WHERE paymentID = ? AND status = "pending"');
            if ($u !== false) {
                $u->bind_param('i', $paymentId);
                $u->execute();
                $u->close();
            }
            Logger::activity('PaymentIntegrityFail', 'Payment #' . $paymentId . ' PayPal capture amount unparseable', null);
            return;
        }

        self::markPaymentSucceeded($paymentId, $captureId, $obsPence, $obsCurrency);

        // 💰 Optional fee capture (gap-item Q8) — best-effort only; drop
        // silently on any shape surprise. Never blocks the success path.
        $fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? null;
        if (is_string($fee) === true) {
            $feePence = self::paypalMoneyToPence($fee);
            if ($feePence !== null) {
                $u = $db->prepare('UPDATE tblPayment SET feePence = ? WHERE paymentID = ? AND status = "succeeded"');
                if ($u !== false) {
                    $u->bind_param('ii', $feePence, $paymentId);
                    $u->execute();
                    $u->close();
                }
            }
        }
    }

    /**
     * Verify a PayPal webhook via the verify-webhook-signature API (NOT
     * local cert verification — the attacker-influencable `cert_url`
     * header is forwarded to PayPal AS DATA, never fetched by us — S8).
     * Fails closed on ANY missing input. Returns true ONLY on HTTP 2xx AND
     * `verification_status === 'SUCCESS'` (strict compare).
     *
     * @link https://docs.paypal.ai/reference/api/rest/verify-webhook-signature/verify-webhook-signature
     */
    private static function paypalVerifyWebhook(array $settings, string $rawBody, array $headers): bool
    {
        $h = static fn (string $name, string $rawKey): string => (string) ($headers[$name] ?? $headers[$rawKey] ?? '');

        $transmissionId   = $h('Paypal-Transmission-Id', 'HTTP_PAYPAL_TRANSMISSION_ID');
        $transmissionTime = $h('Paypal-Transmission-Time', 'HTTP_PAYPAL_TRANSMISSION_TIME');
        $transmissionSig  = $h('Paypal-Transmission-Sig', 'HTTP_PAYPAL_TRANSMISSION_SIG');
        $certUrl          = $h('Paypal-Cert-Url', 'HTTP_PAYPAL_CERT_URL');
        $authAlgo         = $h('Paypal-Auth-Algo', 'HTTP_PAYPAL_AUTH_ALGO');
        $webhookId        = (string) ($settings['paypal']['webhookId'] ?? '');

        if ($transmissionId === '' || $transmissionTime === '' || $transmissionSig === ''
            || $certUrl === '' || $authAlgo === '' || $webhookId === ''
        ) {
            return false;
        }

        $eventBody = json_decode($rawBody, true);
        if (is_array($eventBody) === false) {
            return false;
        }

        $token = self::paypalAccessToken($settings);
        if ($token === null) {
            return false;
        }

        $verifyBody = [
            'transmission_id'   => $transmissionId,
            'transmission_time' => $transmissionTime,
            'cert_url'          => $certUrl, // forwarded as DATA — never fetched by us (S8)
            'auth_algo'         => $authAlgo,
            'transmission_sig'  => $transmissionSig,
            'webhook_id'        => $webhookId,
            'webhook_event'     => $eventBody,
        ];

        $ch = curl_init(self::paypalBase($settings) . '/v1/notifications/verify-webhook-signature');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verifyBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return false;
        }
        $decoded = json_decode((string) $resp, true);
        if (is_array($decoded) === false) {
            return false;
        }
        return (string) ($decoded['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * Dispatch VERIFIED PayPal webhook events. Only reachable through
     * ingestWebhook()'s verified branch — $event is trustworthy, but the
     * S1 amount assertion still runs unconditionally inside
     * markPaymentSucceeded() regardless.
     */
    private static function handlePayPalEvent(array $event): void
    {
        $type     = (string) ($event['event_type'] ?? '');
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

        if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            $paymentId = (int) ($resource['custom_id'] ?? 0);
            if ($paymentId <= 0) {
                return; // not ours
            }
            $db = App::db();
            $row = null;
            $stmt = $db->prepare('SELECT * FROM tblPayment WHERE paymentID = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('i', $paymentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            if ($row === null || (string) $row['provider'] !== 'paypal') {
                return;
            }

            $captureId = (string) ($resource['id'] ?? '');
            $rowRef    = (string) $row['providerRef'];
            $related   = $resource['supplementary_data']['related_ids'] ?? null;
            $orderId   = (is_array($related) === true && isset($related['order_id']) === true)
                ? (string) $related['order_id']
                : null;

            // 🛡️ S3 — providerRef-binding check. Accept only when this
            // event is demonstrably ABOUT the row we're looking at; a
            // verified-but-unrelated capture on the same merchant account
            // must never be bound to an arbitrary paymentId.
            $bound = false;
            if ($orderId !== null && $rowRef === $orderId) {
                $bound = true; // normal pending row, keyed by order id
            } elseif ($rowRef === $captureId) {
                $bound = true; // replay after the return path already succeeded
            } elseif ($orderId === null && (string) $row['status'] === 'pending' && str_starts_with($rowRef, 'pending-') === false) {
                $bound = true; // order_id absent from payload, row already has a real providerRef
            }
            if ($bound === false) {
                Logger::activity('PaymentIntegrityFail', 'Payment #' . $paymentId . ' PayPal webhook providerRef binding mismatch', null);
                return;
            }

            if ((string) ($resource['status'] ?? '') !== 'COMPLETED') {
                return;
            }

            $amountArr   = is_array($resource['amount'] ?? null) ? $resource['amount'] : [];
            $obsCurrency = (string) ($amountArr['currency_code'] ?? '');
            $obsPence    = self::paypalMoneyToPence((string) ($amountArr['value'] ?? ''));
            if ($captureId === '' || $obsPence === null || $obsCurrency === '') {
                $u = $db->prepare('UPDATE tblPayment SET status = "failed", errorMsg = "paypal-amount-unparseable" WHERE paymentID = ? AND status = "pending"');
                if ($u !== false) {
                    $u->bind_param('i', $paymentId);
                    $u->execute();
                    $u->close();
                }
                Logger::activity('PaymentIntegrityFail', 'Payment #' . $paymentId . ' PayPal webhook amount unparseable', null);
                return;
            }
            self::markPaymentSucceeded($paymentId, $captureId, $obsPence, $obsCurrency);
        } elseif ($type === 'CHECKOUT.ORDER.APPROVED') {
            // 🔁 Backstop — payer approved but never returned (closed the
            // tab). Delegates to the same integrity-checked capture path.
            $orderId = (string) ($resource['id'] ?? '');
            if ($orderId === '') {
                return;
            }
            $db = App::db();
            $row = null;
            $stmt = $db->prepare('SELECT * FROM tblPayment WHERE provider = "paypal" AND providerRef = ? LIMIT 1');
            if ($stmt !== false) {
                $stmt->bind_param('s', $orderId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            if ($row !== null && (string) $row['status'] === 'pending') {
                $settings = App::settings()['payments'] ?? [];
                self::paypalCaptureOrder($settings, $row);
            }
        } elseif ($type === 'PAYMENT.CAPTURE.REFUNDED') {
            // 🔁 Best-effort — covers refunds initiated from the PayPal
            // dashboard directly, mirroring Stripe's charge.refunded
            // handling. No fan-out reversal (same as Stripe today).
            $links = is_array($resource['links'] ?? null) ? $resource['links'] : [];
            $captureId = null;
            foreach ($links as $link) {
                if (is_array($link) === true && (string) ($link['rel'] ?? '') === 'up') {
                    $href = (string) ($link['href'] ?? '');
                    if (preg_match('#/v2/payments/captures/([A-Za-z0-9]+)#', $href, $m) === 1) {
                        $captureId = $m[1];
                    }
                    break;
                }
            }
            if ($captureId !== null) {
                $db = App::db();
                $u = $db->prepare('UPDATE tblPayment SET status = "refunded" WHERE provider = "paypal" AND providerRef = ? AND status = "succeeded"');
                if ($u !== false) {
                    $u->bind_param('s', $captureId);
                    $u->execute();
                    $u->close();
                }
            }
        }
        // Everything else: no-op (already recorded in tblWebhookEvent for audit).
    }

    /**
     * Refund via PayPal by the CAPTURE id (never an order id — see the
     * invariant documented at refund()'s PayPal branch).
     *
     * @link https://developer.paypal.com/docs/api/payments/v2/#captures_refund
     */
    private static function paypalRefund(array $settings, string $captureId): bool
    {
        $token = self::paypalAccessToken($settings);
        if ($token === null || $captureId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $captureId) !== 1) {
            return false;
        }
        $ch = curl_init(self::paypalBase($settings) . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'PayPal-Request-Id: rf-' . $captureId, // refund idempotency
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
     * ★ S1 — THE single security-critical choke point ★
     *
     * Mark a tblPayment row succeeded and fan out to whichever app the
     * `purpose` field targets. Idempotent — re-firing the webhook won't
     * double-book.
     *
     * `$observedAmountPence`/`$observedCurrency` are the amount+currency the
     * CALLER actually observed the provider capture/report (never trusted
     * blindly — parsed via a strict minor-units parser at the call site).
     * When non-null they are asserted EXACTLY against the pending row's own
     * amountPence/currency before anything else happens: a mismatch marks
     * the row `failed` and returns WITHOUT fanning out — no
     * tblGivingEntry insert, no fulfilPledge() call, ever, on a mismatch.
     * This is what stops "capture £1, book £500" (underpayment / currency
     * swap booked as full payment).
     *
     * EVERY PayPal call site MUST pass non-null observed values — a null
     * from a PayPal path is a defect (see the paypalHandleCaptureResult()/
     * handlePayPalEvent() docblocks, which never call this with null).
     * Stripe call sites keep passing null (unchanged behaviour — gap-item
     * Q4, see the `// follow-up:` comments in handleStripeEvent()).
     *
     * The final status transition is a single atomic
     * `UPDATE … WHERE status = "pending"` gated on `affected_rows === 1`,
     * closing the TOCTOU between the return-path capture and the webhook
     * backstop (both can race to call this for the same row; only one
     * fans out). A row already `failed` by the integrity gate can never be
     * resurrected to `succeeded` by a later call — it stays failed until a
     * human reconciles.
     */
    private static function markPaymentSucceeded(
        int $paymentId,
        string $providerRef,
        ?int $observedAmountPence = null,
        ?string $observedCurrency = null
    ): void {
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

        // ⛔ INTEGRITY GATE (S1) — never succeed, never fan out, on a
        // mismatch. The `AND status = "pending"` predicate means a mismatch
        // replay can never downgrade an already-succeeded row.
        if ($observedAmountPence !== null) {
            $rowAmount   = (int) $row['amountPence'];
            $rowCurrency = strtoupper((string) $row['currency']);
            $obsCurrency = strtoupper((string) $observedCurrency);
            if ($observedAmountPence !== $rowAmount || $obsCurrency !== $rowCurrency) {
                $errMsg = substr(
                    'amount-mismatch exp:' . $rowAmount . $rowCurrency . ' got:' . $observedAmountPence . $obsCurrency,
                    0,
                    255
                );
                $u = $db->prepare('UPDATE tblPayment SET status = "failed", errorMsg = ? WHERE paymentID = ? AND status = "pending"');
                if ($u !== false) {
                    $u->bind_param('si', $errMsg, $paymentId);
                    $u->execute();
                    $u->close();
                }
                Logger::activity(
                    'PaymentIntegrityFail',
                    'Payment #' . $paymentId . ' amount/currency mismatch — no fan-out',
                    $row['userID'] !== null ? (int) $row['userID'] : null
                );
                return;
            }
        }

        // ⚛️ ATOMIC TRANSITION — replaces the old unconditional UPDATE.
        // Closes the TOCTOU between the return-path capture and the
        // webhook backstop (and incidentally between Stripe's two success
        // events too): only the FIRST caller to win this WHERE clause fans
        // out; every other racing caller bails immediately below.
        $affected = 0;
        $u = $db->prepare('UPDATE tblPayment SET status = "succeeded", providerRef = ?, occurredAt = NOW() WHERE paymentID = ? AND status = "pending"');
        if ($u !== false) {
            $u->bind_param('si', $providerRef, $paymentId);
            $u->execute();
            $affected = $u->affected_rows;
            $u->close();
        }
        if ($affected !== 1) {
            return; // another path already won — no double fan-out (S4)
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
                    // 🎯 Auto-attribution (#299 follow-up) — online card giving
                    // has no explicit campaign selector, so Auto (0) is the
                    // only mode here; see Giving::attributeGift() for the
                    // full rule. $userId is the paying donor; 0/unknown maps
                    // to null so attributeGift never mistakes it for a real
                    // donor row.
                    $donorForAttr = $userId > 0 ? $userId : null;
                    $giftDate     = date('Y-m-d');
                    $attr         = Giving::attributeGift($siteId, $donorForAttr, $giftDate, 0);
                    $campBind     = $attr['campaignID'];
                    $pledgeBind   = $attr['pledgeID'];

                    $ins = $db->prepare(
                        'INSERT INTO tblGivingEntry (siteID, donorID, categoryID, amountPence, currency, donatedAt, method, reference, recordedByID, campaignID, pledgeID) '
                        . 'VALUES (?, ?, ?, ?, ?, CURDATE(), "card", ?, ?, ?, ?)'
                    );
                    if ($ins !== false) {
                        $reference = 'payment:' . $paymentId;
                        $ins->bind_param('iiiissiii', $siteId, $userId, $categoryId, $amount, $currency, $reference, $userId, $campBind, $pledgeBind);
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
