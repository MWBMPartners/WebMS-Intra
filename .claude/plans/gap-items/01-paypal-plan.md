# Gap item #1 — PayPal payment adapter + user-facing checkout UI

> **Status:** PLAN (no code written). Target: a Sonnet build agent implements this in one
> pass; an adversarial security review then clears it before the PR leaves draft.
>
> **Scope:** (a) wire the `paypal` branch of `Portal\Core\Payments` (Orders v2,
> capture-on-return + webhook backstop, verified webhooks, refunds), (b) build the
> missing user-facing checkout UI (Give online page + pledge Pay-now), (c) admin
> config for PayPal, (d) one migration. This handles real money — every decision
> below is security-first.

---

## 0. Ground truth (verified in-repo, 2026-08-27)

| Fact | Where |
| --- | --- |
| `Payments::startCheckout()` inserts a pending `tblPayment` row (providerRef = `pending-{idem}`, idem = 40-hex `random_bytes(20)`), then dispatches on `payments.provider`; only `stripe` is wired — every other provider marks the row `failed` / `{provider}-not-implemented` and returns null | `web/_core/Payments.php:49-107` |
| `Payments::ingestWebhook()` verifies signature FIRST, then `INSERT IGNORE` into `tblWebhookEvent` (audit row written whether or not verified — `verified` flag 0/1), returns false (→ HTTP 401) on bad signature, dispatches events only when verified | `web/_core/Payments.php:114-155` |
| `Payments::refund()` loads the row scoped to `siteID`, requires `status === 'succeeded'`, dispatches on `provider`, flips status to `refunded` on provider accept | `web/_core/Payments.php:160-189` |
| `Payments::markPaymentSucceeded(int $paymentId, string $providerRef)` is **private, provider-agnostic**: re-reads the row, returns if already `succeeded`, updates `status='succeeded', providerRef=?, occurredAt=NOW()`, then fans out — `purpose='giving'` → `tblGivingEntry` insert (+ `Giving::attributeGift`), `purpose='pledge'` → `Projects::fulfilPledge($pledgeId, $siteId, null)` | `web/_core/Payments.php:355-428` |
| Stripe shape to mirror: `stripeCreateCheckout($settings, $amountPence, $currency, $description, $idem, $paymentId): array{?string,?string}` (returns `[url, providerRef]`), `stripeVerifySignature($settings, $body, $headers): bool`, `handleStripeEvent(array $event): void`, `stripeRefund($settings, $ref): bool` — all `private static`, raw cURL, `CURLOPT_TIMEOUT 15`, no SDK | `web/_core/Payments.php:201-344` |
| `checkout.php`: POST-only, CSRF-checked, requires login, `payments.enabled === '1'` gate, `sanitizeReturnTo()` open-redirect guard, pounds→pence at `(int) round(((float)$clean) * 100)`, min 100 pence, then 303-redirects to the provider URL. **`purpose`/`purposeRef`/`description` are taken from POST unvalidated** (see §6.3 — must be hardened) | `web/_apps/payments/checkout.php` |
| `return.php`: GET landing, requires login, loads the payment **scoped to `siteID` + `userID`** (own-payment only), purely cosmetic today ("authoritative status change happens via webhook") | `web/_apps/payments/return.php` |
| `webhook.php`: public route (`isProtected=0`), `?provider=` whitelisted to `stripe|paypal|gocardless` (paypal already accepted), passes raw body + a header map containing BOTH `HTTP_PAYPAL_TRANSMISSION_ID`-style and normalized `Paypal-Transmission-Id`-style keys (via `ucwords(strtolower(...), '_')` + `_`→`-`) | `web/_apps/payments/webhook.php:26-34` |
| `tblPayment`: `paymentID, siteID, userID, provider ENUM('stripe','paypal','gocardless'), providerRef VARCHAR(100), idempotencyKey VARCHAR(80), amountPence INT, feePence, currency CHAR(3), status ENUM('pending','succeeded','failed','refunded'), purpose ENUM('giving','pledge','membership','other'), purposeRef VARCHAR(100), isRecurring, errorMsg VARCHAR(255), occurredAt, createdAt` + `UNIQUE (provider, providerRef)` | `web/_sql/full_schema.sql:4085-4109` |
| `tblWebhookEvent`: `provider VARCHAR(30), eventType VARCHAR(100), providerRef VARCHAR(100), payload MEDIUMTEXT, verified TINYINT, handledAt, errorMsg, receivedAt` + `UNIQUE (provider, providerRef)` → replayed provider event IDs dedupe via `INSERT IGNORE` | `web/_sql/full_schema.sql:4111-4124` |
| Settings already seeded (global, `siteID NULL`): `payments.enabled('0')`, `payments.provider('stripe')`, `payments.test_mode('1')`, `payments.currency('GBP')`, `payments.stripe.publishable`, `payments.stripe.secret`(sens), `payments.stripe.webhookSecret`(sens), **`payments.paypal.clientId`(isSensitive=0)**, **`payments.paypal.secret`(isSensitive=1)**, `payments.gocardless.*` | `web/_sql/full_schema.sql:4138-4151`, migration `097_payments.sql` |
| **NOT yet seeded:** `payments.paypal.webhookId`, `payments.paypal.mode` → migration needed (§8) |
| Sensitive settings are libsodium-encrypted at rest; bootstrap decrypts rows where `isSensitive='1'`. **`decrypt_setting()` on a PLAINTEXT value returns `''`** — flipping `isSensitive` on a row holding plaintext silently blanks it (§8 hazard) | `web/_core/bootstrap.php:171-240, 352-381` |
| Admin config UI = `web/_apps/payments/index.php` (PayPal column exists, badged "follow-up": Client ID text input `pp_client`, Secret password `pp_secret` keep-if-blank) + `web/_apps/payments/save.php` (upserts `payments.paypal.clientId` plain, `payments.paypal.secret` sensitive) | verified |
| **The checkout UI gap is CONFIRMED:** `grep -rn 'payments/checkout'` finds ZERO callers — no page anywhere posts to `/payments/checkout`. Even Stripe is currently unreachable by end users. `giving/index.php` has no give button; `projects/my-pledges.php` has no pay button (its query doesn't even select `pledgeID`) | verified |
| `Projects::fulfilPledge(int $pledgeId, int $siteId, ?int $treasurerId, ?int $givingCategoryId = null)` is idempotent (returns false when `fulfilledAt` already set) and marks the pledge fulfilled **regardless of the amount actually paid** (§6.3 hardening) | `web/_core/Projects.php:111-` |
| Giving categories: `tblGivingCategory(categoryID, siteID, name, isActive, …)`; pledges: `tblProjectPledge(pledgeID, projectID, donorID, amountPence, fulfilledAt, …)` joined to `tblProject(currency, title, slug, siteID)` | full_schema.sql:3788, 4014 |
| Routes: `payments/{index,save,refund,checkout,return}` protected, `payments/webhook` public — already seeded. `giving/give` does NOT exist → migration route row + `check_route_targets.py` requires the handler file to land in the same PR | full_schema.sql:4126-4132, 3900-3910 |
| Migration files currently end at `165_widen_totp_secret.sql`. House idioms: settings seeds `INSERT … ON DUPLICATE KEY UPDATE settingKey = settingKey`; route seeds `ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)`; idempotent `tblMigrations` self-record; **no MariaDB-only DDL**; migrations must replay as no-ops | `web/_sql/157_eventhub_api.sql` is the model |

**PayPal REST facts** (verify-webhook-signature request fields confirmed via PayPal docs/OpenAPI — see Sources at the end):
- OAuth2: `POST {base}/v1/oauth2/token`, HTTP Basic `clientId:secret`, body `grant_type=client_credentials` → `{access_token, expires_in}`.
- Orders v2 create: `POST {base}/v2/checkout/orders` → `{id, status:'CREATED', links:[{rel:'approve', href}, …]}`.
- Capture: `POST {base}/v2/checkout/orders/{id}/capture` (empty JSON body) → `{status:'COMPLETED', purchase_units:[{payments:{captures:[{id, status, amount:{currency_code, value}, custom_id, seller_receivable_breakdown?}]}}]}`. Already-captured → HTTP 422 with `details[0].issue === 'ORDER_ALREADY_CAPTURED'`.
- Get order: `GET {base}/v2/checkout/orders/{id}` (same capture objects nested).
- Webhook verify: `POST {base}/v1/notifications/verify-webhook-signature` with `{transmission_id, transmission_time, cert_url, auth_algo, transmission_sig, webhook_id, webhook_event}` → `{verification_status: 'SUCCESS'|'FAILURE'}` (Bearer-authenticated).
- Refund: `POST {base}/v2/payments/captures/{captureId}/refund` (body `{}` = full refund) → HTTP 201.
- Bases: sandbox `https://api-m.sandbox.paypal.com`, live `https://api-m.paypal.com`. Idempotency on POSTs via `PayPal-Request-Id` header.
- Webhook events to subscribe: `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED`.

---

## 1. PayPal REST flow — `Payments.php` additions (Orders v2)

All new methods are `private static` on `Portal\Core\Payments`, mirroring the Stripe
block's shape (raw cURL, 15 s timeout, no SDK, no Composer). Add a
`// 🟡 PayPal implementation` section header after the Stripe block.

### 1.1 Base-URL selection — `paypalBase(array $settings): string`

```
$mode = (string) ($settings['paypal']['mode'] ?? 'sandbox');
return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
```
- Only these two hard-coded constants. **The mode string never becomes part of a URL**;
  anything other than exactly `'live'` → sandbox (fail-safe: misconfig hits sandbox, not live money).
- `payments.test_mode` stays what it is today (an admin-UI badge). `payments.paypal.mode`
  is the sole authority for the PayPal base URL. Admin UI shows a consistency warning
  when `mode=live` while `test_mode=1` (§7).

### 1.2 OAuth token — `paypalAccessToken(array $settings): ?string`

- `POST {base}/v1/oauth2/token`, `CURLOPT_USERPWD` = `{clientId}:{secret}`
  (`CURLOPT_HTTPAUTH, CURLAUTH_BASIC`), body `grant_type=client_credentials`,
  `Content-Type: application/x-www-form-urlencoded`, `Accept: application/json`, timeout 15.
- Return `access_token` string, or **null** on any failure (missing clientId/secret,
  non-2xx, non-JSON). Callers treat null as hard failure (create → `[null,null]`;
  webhook verify → `false` → HTTP 401 → PayPal retries).
- **Per-request cache**: `private static ?array $ppTokenCache = null;` holding
  `['key' => sha256(mode.clientId), 'token' => …]`; return the cached token when the
  key matches (a webhook ingest calls token twice otherwise: verify + capture).
  No cross-request persistence (shared hosting, no reliable APCu) — one token POST
  per request is acceptable.
- **NEVER log** the token, the clientId, the secret, or the raw token response. No
  `error_log`, no `Logger::*`, no `errorMsg` writes may include any of these. The
  only permissible failure trace is a coarse constant like `'paypal-oauth-failed'`.

### 1.3 Create order — `paypalCreateCheckout(array $settings, int $amountPence, string $currency, string $description, string $idem, int $paymentId): array`

Returns `[?string approveUrl, ?string orderId]` — exact analogue of `stripeCreateCheckout`.

- Guard: `paypalAccessToken()` non-null, else `[null, null]`.
- Build return/cancel URLs exactly like the Stripe branch (same `$scheme://$host` derivation):
  - `return_url = {base}/payments/return?payment={paymentId}&result=ok`
  - `cancel_url = {base}/payments/return?payment={paymentId}&result=cancel`
  (PayPal appends `&token={orderId}&PayerID=…` itself; `return.php` must ignore those — §2.)
- `POST {base}/v2/checkout/orders`, headers:
  `Authorization: Bearer {token}`, `Content-Type: application/json`,
  `PayPal-Request-Id: {$idem}` (create-idempotency, mirrors Stripe's `Idempotency-Key`).
- JSON body:
  ```json
  {
    "intent": "CAPTURE",
    "purchase_units": [{
      "reference_id": "default",
      "custom_id": "<paymentId as string>",
      "invoice_id": "wmsintra-<paymentId>",
      "description": "<description, truncated to 127 chars, control chars stripped>",
      "amount": { "currency_code": "<GBP|EUR|USD>", "value": "<pence→'12.34'>" }
    }],
    "application_context": {
      "return_url": "<return_url>",
      "cancel_url": "<cancel_url>",
      "user_action": "PAY_NOW",
      "shipping_preference": "NO_SHIPPING",
      "brand_name": "<Site::productName() or site name, truncated 127>"
    }
  }
  ```
  - `custom_id` is the payment-row pointer the webhook uses; it is set server-side and
    immutable to the payer. `invoice_id` gives PayPal-side duplicate protection
    (`DUPLICATE_INVOICE_ID`) as a second idempotency belt.
  - `application_context` is the classic Orders-v2 field; PayPal's newer
    `payment_source.paypal.experience_context` is equivalent — `application_context`
    is chosen for simplicity and continued support. (Open question Q6.)
- Money formatting — **integer math only, never float**:
  `value = sprintf('%d.%02d', intdiv($amountPence, 100), $amountPence % 100)`.
  GBP/EUR/USD are all 2-decimal currencies (the only three the settings whitelist allows);
  add a comment noting zero-decimal currencies (JPY) would need a map if ever whitelisted.
- Parse response: require 2xx + `id` non-empty + a `links[]` entry with `rel === 'approve'`;
  return `[approveHref, orderId]`. Anything else → `[null, null]` (checkout.php then
  flashes "provider may not be configured" — no provider response bodies leak to the user).
- `startCheckout()` change: insert
  ```php
  } elseif ($provider === 'paypal') {
      [$url, $providerRefReal] = self::paypalCreateCheckout($settings, $amountPence, $currency, $description, $idem, $paymentId);
  } else {
  ```
  between the stripe branch and the not-implemented else. On success the existing
  post-dispatch UPDATE stores the **order id** as `providerRef` (lifecycle:
  `pending-{idem}` → order id → capture id on success; the `UNIQUE(provider,providerRef)`
  key holds at every stage).

---

## 2. Capture — return path (primary) + webhook (backstop)

PayPal `intent=CAPTURE` orders are **not charged at approval** — an explicit
capture call is required after the payer approves. Two capture paths, both
funnelled through the same helper and the same integrity choke point (§4):

### 2.1 `Payments::finalizeReturn(int $paymentId, int $siteId, int $userId, string $result): ?string` (new, public)

Provider-dispatched hook called by `return.php` **before** it renders. Returns the
payment's effective status string for display (`'succeeded' | 'pending' | 'failed' | 'refunded' | null`).

- Load the row `WHERE paymentID = ? AND siteID = ? AND userID = ?` (same own-payment
  scoping return.php already enforces; `finalizeReturn` re-checks it — never trust the caller).
  Row absent → null.
- If `provider !== 'paypal'` → return current status unchanged (Stripe stays
  webhook-authoritative; zero behaviour change for Stripe).
- If `provider === 'paypal' && status === 'pending' && $result === 'ok'`
  → `self::paypalCaptureOrder($settings, $row)` (below) → re-read + return status.
- If `result === 'cancel'` → leave the row `pending` (the webhook backstop or manual
  reconciliation closes it; do NOT mark failed — the payer can retry the same approve link).

**`return.php` change** (small): after the existing scoped SELECT, when a row was
found, call `Payments::finalizeReturn(...)` and re-select (or use the returned status)
so the "Thank you" page reflects reality; show "Payment is still being confirmed —
you'll receive a receipt once it completes" when status is still `pending` after an
`ok` return (capture failed transiently; webhook will land it). The order id / GET
`token` / `PayerID` query params PayPal appends are **never read** — the order to
capture always comes from the DB row's `providerRef`.

### 2.2 `paypalCaptureOrder(array $settings, array $row): void` (private; shared by return path and webhook backstop)

`$row` is the freshly-loaded pending tblPayment row (`provider='paypal'`,
`providerRef` = order id).

1. Token; null → return (row stays pending).
2. `POST {base}/v2/checkout/orders/{providerRef}/capture` — headers
   `Authorization: Bearer`, `Content-Type: application/json`,
   `PayPal-Request-Id: cap-{$row['idempotencyKey']}` (capture idempotency: a
   double-submit of the same capture returns the original response, not a
   double charge), body `{}`.
3. **2xx**: require top-level `status === 'COMPLETED'`; drill to
   `purchase_units[0].payments.captures[0]`; require `captures[0].status === 'COMPLETED'`
   (a `PENDING` capture — e.g. eCheck — means: leave row `pending`, store
   `errorMsg='paypal-capture-pending'`, let `PAYMENT.CAPTURE.COMPLETED` finish it later).
   Extract `captureId = captures[0].id`, `observed = captures[0].amount`
   (`currency_code`, `value`), and `custom_id`.
   - Cross-check `custom_id === (string) $row['paymentID']` — mismatch → mark failed
     `errorMsg='paypal-customid-mismatch'`, log via `Logger::activity('PaymentIntegrityFail', …)`, return.
   - → `self::markPaymentSucceeded($paymentId, $captureId, $observedPence, $observedCurrency)`
     (§4 does the amount assertion inside).
   - Optional nicety: `feePence` from `seller_receivable_breakdown.paypal_fee.value`
     (same strict parser) → `UPDATE tblPayment SET feePence = ?` after success. Low
     risk, feeds the admin reconciliation card; include if trivial.
4. **HTTP 422 + `details[0].issue === 'ORDER_ALREADY_CAPTURED'`** — the idempotent
   race (webhook captured first, or a double return-click): treat as
   success-shaped, reconcile: `GET {base}/v2/checkout/orders/{orderId}`, find the
   COMPLETED capture under `purchase_units[0].payments.captures[]`, run the
   **identical** custom_id + amount checks, then `markPaymentSucceeded(...)` (which
   no-ops atomically if the webhook already won — §4).
5. **Any other non-2xx** (e.g. `INSTRUMENT_DECLINED`, `ORDER_NOT_APPROVED`): leave
   status `pending`, `UPDATE errorMsg = 'paypal-capture-<issue-code-or-httpcode>'`
   truncated ≤255 — the **issue code only**, never the response body (payer PII,
   debug_id fine to include). No exception, no fan-out.

### 2.3 Webhook backstop — see §3's `handlePayPalEvent`

`CHECKOUT.ORDER.APPROVED` (payer approved but never returned — closed the tab)
triggers the same `paypalCaptureOrder()` against the still-pending row, and
`PAYMENT.CAPTURE.COMPLETED` (arrives after any capture, whoever initiated it)
lands the success if the return path hasn't. All three paths converge on the single
atomic transition in §4, so ordering/racing between them is safe by construction.

---

## 3. Webhook verification — `paypalVerifyWebhook()` + `handlePayPalEvent()`

### 3.1 `paypalVerifyWebhook(array $settings, string $rawBody, array $headers): bool`

Verification via PayPal's **verify-webhook-signature API** (NOT local cert
verification — see SSRF note in §9): the five transmission headers + our configured
webhook id + the event are POSTed back to PayPal, which answers SUCCESS/FAILURE.

- Header extraction — `webhook.php` provides both raw and normalized keys; read:
  ```
  $h = fn(string $n, string $raw) => (string) ($headers[$n] ?? $headers[$raw] ?? '');
  transmission_id   = $h('Paypal-Transmission-Id',   'HTTP_PAYPAL_TRANSMISSION_ID')
  transmission_time = $h('Paypal-Transmission-Time', 'HTTP_PAYPAL_TRANSMISSION_TIME')
  transmission_sig  = $h('Paypal-Transmission-Sig',  'HTTP_PAYPAL_TRANSMISSION_SIG')
  cert_url          = $h('Paypal-Cert-Url',          'HTTP_PAYPAL_CERT_URL')
  auth_algo         = $h('Paypal-Auth-Algo',         'HTTP_PAYPAL_AUTH_ALGO')
  ```
  (Normalization check: `ucwords(strtolower('PAYPAL_TRANSMISSION_ID'), '_')` →
  `Paypal_Transmission_Id` → `Paypal-Transmission-Id`. Exact casing matters — the
  builder must use these literal strings.)
- Fail-fast `return false` when ANY of: the five headers empty, `webhookId`
  setting empty, clientId/secret empty, `json_decode($rawBody)` not an array, or
  token fetch null. **Verification fails closed in every branch.**
- `POST {base}/v1/notifications/verify-webhook-signature`, Bearer token, JSON body:
  ```json
  { "transmission_id": …, "transmission_time": …, "cert_url": …, "auth_algo": …,
    "transmission_sig": …, "webhook_id": "<payments.paypal.webhookId setting>",
    "webhook_event": <the json_decoded raw body, re-encoded as part of this envelope> }
  ```
- Return `true` ONLY when HTTP 2xx **and** `verification_status === 'SUCCESS'`
  (strict `===` string compare). Everything else false. The `cert_url` header is
  attacker-influencable but is **passed to PayPal as data, never fetched by us** —
  PayPal rejects certs that aren't its own.

### 3.2 `ingestWebhook()` change

Add after the stripe branch (replacing the `// PayPal / GoCardless branches` comment):
```php
} elseif ($provider === 'paypal') {
    $verified = self::paypalVerifyWebhook($settings, $rawBody, $headers);
    if ($verified === true) {
        $parsed = json_decode($rawBody, true);
        if (is_array($parsed) === true) {
            $eventType   = (string) ($parsed['event_type'] ?? '');
            $providerRef = (string) ($parsed['id'] ?? '');   // WH-… event id
        }
    }
}
```
and, after the audit insert, mirror the stripe dispatch:
`if ($provider === 'paypal' && $parsed !== null) { self::handlePayPalEvent($parsed); }`.
The existing flow is preserved exactly: audit row ALWAYS recorded (verified flag 0/1),
unverified → `return false` → webhook.php answers 401 (PayPal retries with backoff),
`UNIQUE(provider, providerRef)` on the `WH-…` event id dedupes replays at the audit
layer while §4's atomic transition dedupes at the money layer.

### 3.3 `handlePayPalEvent(array $event): void`

`$event` is **verified** at this point (only reachable through the verified branch).

- `PAYMENT.CAPTURE.COMPLETED` — `resource` is a capture object:
  `id` (capture id), `status`, `amount{currency_code,value}`, `custom_id`,
  `supplementary_data.related_ids.order_id` (may be absent on some accounts).
  1. `paymentId = (int) ($resource['custom_id'] ?? 0)`; `<= 0` → return (not ours).
  2. Load the row; require `provider === 'paypal'`. Cross-check **providerRef binding**:
     accept only when `row.providerRef === order_id` (normal pending row) OR
     `row.providerRef === capture id` (return path already succeeded — replay) OR
     (`order_id` absent from the payload AND `row.status === 'pending'` AND
     `str_starts_with(row.providerRef, 'pending-') === false`) — this stops a
     verified-but-unrelated capture (different order on the same merchant account)
     from being bound to an arbitrary `paymentId`. Mismatch → log
     `PaymentIntegrityFail`, do nothing else.
  3. Require `resource['status'] === 'COMPLETED'`.
  4. `markPaymentSucceeded($paymentId, $captureId, $observedPence, $observedCurrency)`
     with amounts parsed from `resource['amount']` by the strict parser (§4). The
     payload is trustworthy **because** verification succeeded — but the amount
     assertion still runs unconditionally.
- `CHECKOUT.ORDER.APPROVED` — `resource` is an order: `id` = order id,
  `purchase_units[0].custom_id` = paymentId. Load row by
  `provider='paypal' AND providerRef = <order id>` (keyed by providerRef, NOT by
  custom_id, to keep the binding tight); if found and `status === 'pending'` →
  `paypalCaptureOrder($settings, $row)` (backstop capture, §2.2 — full integrity
  path included). Not found / not pending → ignore.
- `PAYMENT.CAPTURE.REFUNDED` — best-effort (covers refunds initiated from the
  PayPal dashboard, mirroring Stripe's `charge.refunded` handling): extract the
  capture id from `resource.links[]` entry with `rel === 'up'` whose href matches
  `#/v2/payments/captures/([A-Za-z0-9]+)#`; then
  `UPDATE tblPayment SET status='refunded' WHERE provider='paypal' AND providerRef=? AND status='succeeded'`.
  No fan-out reversal (same as Stripe today — Giving/pledge reversal is out of scope, noted in FEATURES).
- Everything else: no-op (already recorded in tblWebhookEvent for audit).

---

## 4. ★ THE critical security control — amount/currency integrity ★

**Threat:** any path that marks a payment `succeeded` from a provider signal must
prove the money PayPal actually captured equals the money the pending row was
created for. Without it: capture a £1 order → mark a £500 payment succeeded →
`tblGivingEntry`/`fulfilPledge` fan-out books £500. (custom_id is server-set and the
webhook is verified, so the residual risk is PayPal-side partial captures, currency
surprises, parsing bugs, and any future code path that forgets the check — which is
why the assertion is enforced at the single choke point, not at call sites' discretion.)

### 4.1 Enforcement point — inside `markPaymentSucceeded` (the one choke point)

Change the signature (still `private static`):

```php
private static function markPaymentSucceeded(
    int $paymentId,
    string $providerRef,
    ?int $observedAmountPence = null,
    ?string $observedCurrency = null
): void
```

New body order:
1. Load row; `null` → return. `status === 'succeeded'` → return (unchanged guard).
2. **INTEGRITY GATE** — when `$observedAmountPence !== null`:
   ```php
   if ($observedAmountPence !== (int) $row['amountPence']
       || strtoupper((string) $observedCurrency) !== strtoupper((string) $row['currency'])
   ) {
       // ⛔ Amount/currency mismatch — NEVER succeed, NEVER fan out.
       UPDATE tblPayment SET status='failed',
              errorMsg = 'amount-mismatch exp:{amountPence}{currency} got:{observed}{cur}' (≤255)
        WHERE paymentID = ? AND status = 'pending';
       Logger::activity('PaymentIntegrityFail', 'Payment #… amount mismatch', null);
       return;
   }
   ```
   (The conditional `AND status='pending'` means a mismatch replay can never
   downgrade an already-succeeded row.)
3. **ATOMIC TRANSITION** (replaces the current unconditional UPDATE — closes the
   TOCTOU between the two PayPal success paths, and incidentally between Stripe's
   two success events):
   ```php
   UPDATE tblPayment SET status='succeeded', providerRef=?, occurredAt=NOW()
    WHERE paymentID = ? AND status = 'pending';
   if ($u->affected_rows !== 1) { return; }   // another path won — no double fan-out
   ```
   Behaviour note for the changelog: `failed → succeeded` resurrection (possible
   under the old `status !== 'succeeded'` guard) is now impossible — intended: a row
   failed by the integrity gate must stay failed until a human reconciles.
4. Fan-out exactly as today (giving insert / fulfilPledge), unchanged.

### 4.2 Who passes observed values — **every PayPal path, always**

| Path | Extraction point | Values passed |
| --- | --- | --- |
| Return-capture (§2.2 step 3) | `purchase_units[0].payments.captures[0].amount` of the **capture response** | parsed pence + `currency_code` |
| Return-capture 422 reconcile (§2.2 step 4) | same field of the **GET order** response | parsed pence + `currency_code` |
| Webhook `PAYMENT.CAPTURE.COMPLETED` (§3.3) | `resource.amount` of the **verified** event | parsed pence + `currency_code` |
| Webhook `CHECKOUT.ORDER.APPROVED` backstop | delegates to §2.2 → capture-response path | (inherited) |

**Rule for the builder + the adversarial reviewer's #1 checklist item:** a PayPal
call site invoking `markPaymentSucceeded` with `null` observed values is a defect.
Stripe call sites keep passing null (unchanged behaviour; upgrading Stripe to pass
`amount_total`/`currency` from `checkout.session.completed` is Open Question Q4).

### 4.3 Strict minor-units parser — `paypalMoneyToPence(string $value): ?int`

```php
if (preg_match('/^([0-9]+)\.([0-9]{2})$/', $value, $m) !== 1) { return null; }
return ((int) $m[1]) * 100 + (int) $m[2];
```
- `null` (unexpected format — e.g. `"10.5"`, `"1,000.00"`, scientific notation,
  zero-decimal value) is treated as a mismatch → failed path. Never `(float)` casts,
  never locale-dependent parsing. Companion formatter is §1.3's `sprintf('%d.%02d', …)`.

---

## 5. Refund — `paypalRefund(array $settings, string $captureId): bool`

- Mirror `stripeRefund` exactly: token (null → false), guard `$captureId !== ''`,
  `POST {base}/v2/payments/captures/{captureId}/refund`, Bearer + `Content-Type: application/json`
  + `PayPal-Request-Id: rf-{captureId}` (refund idempotency), body `{}` (full refund),
  timeout 15, return `code >= 200 && code < 300` (expect 201).
- `Payments::refund()` change — add between the stripe branch and `if ($ok…)`:
  ```php
  } elseif ((string) $row['provider'] === 'paypal') {
      $ok = self::paypalRefund($settings, (string) $row['providerRef']);
  }
  ```
- **Invariant making this safe:** `refund()` requires `status === 'succeeded'`, and
  for PayPal a row only ever reaches `succeeded` via `markPaymentSucceeded(…, $captureId, …)`
  — so `providerRef` is guaranteed to be a capture id here (never an order id).
  Document this invariant in the docblock; the reviewer verifies it.
- `refund.php` needs **no change** (admin-only, CSRF'd, provider-neutral already).
- The `PAYMENT.CAPTURE.REFUNDED` webhook (§3.3) is the second writer of
  `status='refunded'` — both writers are idempotent no-ops for each other.

---

## 6. Checkout UI — the missing user-facing initiation

Provider-agnostic by design: these pages POST to the existing hardened
`/payments/checkout`; whether Stripe or PayPal answers is purely the server-side
`payments.provider` setting. No provider names in user-facing copy beyond
"our secure payment provider".

### 6.1 NEW `web/_apps/giving/give.php` — "Give online" page (route `giving/give`, protected)

- Standard app-page skeleton (`Auth::ensureSession(); Auth::requireLogin();`,
  header/footer templates, `declare(strict_types=1)`, full file-header comment,
  full-IF style — copy the shape of `giving/index.php`).
- Guards, in order: `payments.enabled === '1'` AND giving app enabled
  (`App::settings()['giving']` present / `giving.enabled` per AppRegistry
  convention — builder: mirror however `giving/index.php`'s route gating works;
  the route itself is already behind the giving app) — if payments disabled, render
  a friendly "Online giving isn't available yet" card, NOT a 404.
- Data: active categories
  `SELECT categoryID, name, description FROM tblGivingCategory WHERE siteID = ? AND isActive = 1 ORDER BY sortOrder, name`
  (prepared, site-scoped). Zero categories → friendly "not set up yet" card.
- The form (POST `/payments/checkout`):
  - `csrf_token` hidden = `Auth::csrfToken()`.
  - `purpose` hidden = `giving`.
  - `purposeRef` = category `<select>` (values = categoryID ints).
  - `amount` = `<input type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?">`
    with the currency symbol prefix (`match` on `payments.currency` like index.php),
    plus optional quick-amount buttons (£10/£20/£50 — JS sets the input; progressive
    enhancement only, server revalidates). Client `min` hint 1.00; the server-side
    100-pence floor in checkout.php remains the enforcement.
  - `return_to` hidden = `/giving/give` (sanitizeReturnTo re-validates server-side).
  - NO `description` field — §6.3 makes the server build it.
  - Submit: "Continue to secure payment". Copy notes the provider-hosted card entry
    ("You will be redirected… we never see your card details") and shows a
    `TEST MODE` badge when `payments.test_mode === '1'`.
  - All output through `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`; no `<table>`.
- Pounds→pence stays where it is today: the **existing boundary in checkout.php**
  (`preg_replace('/[^0-9.]/','',…)` → `(int) round(float*100)`). Do not duplicate
  conversion client-side.

### 6.2 Entry points

- `web/_apps/giving/index.php` — in the header button group, when
  `App::settings('payments.enabled') === '1'`, prepend:
  `<a href="/giving/give" class="btn btn-success btn-sm"><i class="fa-solid fa-heart me-1"></i>Give online</a>`.
- `web/_apps/projects/my-pledges.php` — "Pay now" on unfulfilled pledges:
  - Add `p.pledgeID` to the SELECT.
  - In the `Pending` badge branch, render an inline POST form to `/payments/checkout`
    with `csrf_token`, `purpose=pledge`, `purposeRef=<pledgeID>`,
    `return_to=/projects/my-pledges`, submit "Pay now". **No amount field** — §6.3
    forces the amount server-side from the pledge row (a POSTed amount for
    `purpose=pledge` is ignored).
  - Render only when `payments.enabled === '1'`.
- (Optional, small: `projects/view.php` gets the same Pay-now for the viewer's own
  unfulfilled pledge — include only if trivially greppable; my-pledges is the required surface.)

### 6.3 `checkout.php` hardening (REQUIRED — fixes a live vulnerability class)

Today `purpose`, `purposeRef`, and `description` come from POST unvalidated. Two
concrete abuses that must be closed **in this PR** because this PR is what makes the
endpoint reachable from real UI:

- **Pledge amount forgery:** POST `amount=1.00&purpose=pledge&purposeRef=<any pledgeID>`
  → pay £1 → `fulfilPledge` marks a £500 pledge fulfilled (fulfilPledge doesn't check
  the paid amount). Also works cross-user (any pledgeID).
- **Description injection:** POSTed `description` flows into the provider order/
  product name (receipt-text spoofing, phishing copy on PayPal's own page).

New validation block after the enabled/CSRF/method gates, before `startCheckout`:

1. `purpose` must be in `['giving','pledge']` for this endpoint version — anything
   else → flash "Invalid request" + redirect to sanitized return_to. (`membership`/
   `other` remain enum values in the DB for future flows but are not initiatable
   from the public form until a flow exists — least privilege.)
2. `purpose === 'giving'`:
   - `purposeRef` must be ctype_digit; category loaded via prepared
     `SELECT categoryID, name FROM tblGivingCategory WHERE categoryID = ? AND siteID = ? AND isActive = 1` — miss → reject.
   - amount = POSTed amount (existing parse + ≥100 pence floor). Add a sanity
     ceiling: `> 1_000_000` pence (£10,000) → reject with "For large gifts please
     contact the office" (fat-finger/fraud ceiling; make the constant obvious for tuning).
   - `description = 'Giving — ' . $categoryName` (server-built).
3. `purpose === 'pledge'`:
   - `purposeRef` ctype_digit; pledge loaded via prepared
     `SELECT p.pledgeID, p.amountPence, pr.title FROM tblProjectPledge p INNER JOIN tblProject pr ON pr.projectID = p.projectID WHERE p.pledgeID = ? AND p.donorID = ? AND pr.siteID = ? AND p.fulfilledAt IS NULL`
     (**donorID = logged-in user** — kills cross-user fulfilment) — miss → reject.
   - `amountPence` **forced** from `p.amountPence` (POSTed amount ignored entirely).
   - `description = 'Pledge — ' . $projectTitle` (truncated 120).
4. POSTed `description` is deleted from the flow entirely.
5. Everything else (CSRF, login, enabled gate, sanitizeReturnTo, 303) unchanged.

This block is provider-neutral and retro-hardens the existing Stripe flow too.

---

## 7. Settings + admin UI

### 7.1 Keys (all global `siteID NULL`, dot-notation under `payments.paypal.`)

| Key | isSensitive | Default | Notes |
| --- | --- | --- | --- |
| `payments.paypal.clientId` | **1** (flipped from 0 — §8 hazard) | `''` | Basic-auth username for OAuth. PayPal treats client ids as public identifiers, but this integration never needs it client-side → encrypt at rest (defence in depth, per gap-item directive). |
| `payments.paypal.secret` | 1 (already) | `''` | OAuth Basic-auth password. Already seeded + already saved encrypted by save.php. |
| `payments.paypal.webhookId` | 0 | `''` | The `WH-…`-page webhook id from the PayPal dashboard. Not a key — knowing it doesn't help forge (verification happens at PayPal) — but it IS required for verification, so empty ⇒ all PayPal webhooks 401. |
| `payments.paypal.mode` | 0 | `'sandbox'` | `'sandbox'` \| `'live'` only; anything else behaves as sandbox (§1.1). |

Reading: `App::settings()['payments']['paypal'][…]` — bootstrap already decrypts
`isSensitive='1'` rows into the in-memory tree; no new plumbing.

### 7.2 `web/_apps/payments/index.php` (admin config page)

- Provider `<select>`: label `PayPal (follow-up)` → `PayPal` (GoCardless keeps its badge).
- PayPal column (`col-md-3`), remove the "follow-up" badge, becomes:
  - Mode `<select name="pp_mode">` — `sandbox` (default) / `live`.
  - Client ID → **password-style keep-if-blank** (matches its new sensitive
    handling): `<input type="password" name="pp_client" placeholder="Leave blank to keep" autocomplete="off">`
    + "set" badge computed from `$ppClient !== ''` (rename the existing `$ppClient`
    usage — the value is no longer echoed into the input).
  - Secret — unchanged (already keep-if-blank password).
  - Webhook ID `<input type="text" name="pp_webhook_id" value="<esc>">` + "set" badge.
  - Hint block: webhook URL `…/payments/webhook?provider=paypal` (same
    scheme/host build as the Stripe hint) and "Subscribe the webhook to:
    `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED`".
  - Consistency warning when `pp mode === 'live'` while `test_mode === '1'`
    (small `text-warning` line — informational only).

### 7.3 `web/_apps/payments/save.php`

- `pp_mode`: whitelist `in_array($m, ['sandbox','live'], true)` else `'sandbox'`;
  upsert `payments.paypal.mode` non-sensitive.
- `pp_webhook_id`: `trim()`; upsert `payments.paypal.webhookId` non-sensitive
  (unconditional — clearing it is a legitimate admin action that disables the webhook).
- `pp_client`: switch to the sensitive keep-if-blank pattern (mirror `pp_secret`):
  `if ($ppClient !== '') { $upsert($db, 'payments.paypal.clientId', $ppClient, true); }`.
- `pp_secret`: unchanged.
- The `$upsert` closure already encrypts sensitive non-empty values and stamps
  `isSensitive` — no changes to it.

---

## 8. Migration — YES, one is needed (settings seeds + one route)

Runtime config lives in `tblSettings`, and two PayPal keys plus the `giving/give`
route don't exist yet, so a migration is required. **No DDL at all** — pure seed
rows (automatically MySQL-8-safe; `check_mariadb_only_ddl.py` trivially green).

- **Number:** the repo currently ends at `165_…`; the gap-analysis coordinator
  designated **167** for this item (a parallel gap item presumably claims 166).
  Builder: `ls web/_sql/` at build time and take the coordinator's number unless it
  is already used — never reuse. File name suggestion: `167_paypal_checkout.sql`.
- Content (house idioms from `157_eventhub_api.sql` / the full_schema payments block):
  ```sql
  -- 1) PayPal runtime settings (global defaults)
  INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
      (NULL, 'payments.paypal.webhookId', '', '', 0),
      (NULL, 'payments.paypal.mode',      'sandbox', 'sandbox', 0)
  ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

  -- 2) Flip clientId to encrypted-at-rest — ONLY where the stored value is empty.
  --    ⚠️ decrypt_setting() returns '' for plaintext input, so flipping a row that
  --    already holds a plaintext client id would silently blank it at bootstrap.
  --    Sites with a value keep isSensitive=0 until the admin re-saves the page
  --    (save.php now writes it encrypted + isSensitive=1). Idempotent by predicate.
  UPDATE `tblSettings` SET `isSensitive` = 1
   WHERE `settingKey` = 'payments.paypal.clientId'
     AND (`settingValue` = '' OR `settingValue` IS NULL);

  -- 3) Give-online page route
  INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
      ('giving/give', 'giving/give.php', 1)
  ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

  -- 4) Idempotent self-record
  INSERT INTO `tblMigrations` (`filename`) VALUES ('167_paypal_checkout.sql')
  ON DUPLICATE KEY UPDATE `filename` = `filename`;
  ```
- **full_schema.sql fold** (schema/seed parity check `check_schema_seed_parity.py`):
  add the two new settings rows to the payments settings block (~line 4148), change
  the `payments.paypal.clientId` seed's isSensitive column `0` → `1` in the same
  block, add the `giving/give` route row to the giving routes block (~line 3910),
  and append the `167_…` filename to the tblMigrations seed list at the bottom.
- Replay-proof: every statement above is a no-op on second run (predicate UPDATE,
  ON-DUP inserts). `check_migration_idempotency.py` + the e2e-migrations harness
  must pass.
- NOT needed: no tblPayment/tblWebhookEvent changes (the `paypal` enum value and
  the audit table already fit), no `api.*.enabled` flags (nothing here goes through
  ApiRouter — `/payments/*` are ordinary tblRoutes pages), no new tblRoutes rows for
  payments itself.

---

## 9. Security checklist — threat → mitigation map

The adversarial reviewer walks this table top to bottom; the build must make each
row point at real code.

| # | Threat | Mitigation (where) |
| --- | --- | --- |
| S1 ★ | **Underpayment / currency swap booked as full payment** (£1 captured, £500 fanned out) | Amount+currency integrity gate INSIDE `markPaymentSucceeded` (§4.1) — observed values from the capture response (return path), the GET-order reconcile (422 path), and the verified webhook `resource.amount`; strict `paypalMoneyToPence` parser rejects odd formats as mismatch; mismatch → `status='failed'`, `errorMsg='amount-mismatch…'`, `Logger` trail, **no fan-out**; PayPal call sites must never pass null observed values (§4.2 reviewer rule) |
| S2 | **Forged/replayed webhooks** | `paypalVerifyWebhook` via PayPal's verify-webhook-signature API, strict `verification_status === 'SUCCESS'`, fail-closed on ANY missing input (headers/webhookId/creds/token); unverified → audit row `verified=0` + HTTP 401, zero side effects; replay dedupe: `UNIQUE(provider, providerRef)` on the event id + the atomic pending→succeeded transition (§4.1.3) |
| S3 | **Payment-substitution / binding confusion** (a verified event for order X marking payment Y) | `custom_id` is server-set at order create and immutable to the payer; webhook handler additionally requires `row.providerRef` to match the event's order/capture id (§3.3 step 2); return path captures the order id **from the DB row**, never from GET `token` (§2.1); capture response's echoed `custom_id` cross-checked against paymentID (§2.2) |
| S4 | **Double capture / double fan-out** (return + webhook race, webhook replays, double-click) | Single atomic `UPDATE … WHERE status='pending'` + `affected_rows === 1` gate before fan-out (§4.1.3); `PayPal-Request-Id` on capture (§2.2); 422 `ORDER_ALREADY_CAPTURED` handled as reconcile-not-error (§2.2.4); `fulfilPledge` independently idempotent |
| S5 | **Secret leakage** | clientId/secret encrypted at rest (libsodium, §7/§8); OAuth token request-cached in a private static, never persisted, **never logged** (§1.2); `errorMsg` writes restricted to coarse issue codes — never raw provider bodies, tokens, or headers; admin UI renders "set" badges + keep-if-blank password inputs, never echoes stored secrets; token endpoint uses `CURLOPT_USERPWD` (credentials never in URL) |
| S6 | **CSRF on money endpoints** | `checkout.php` / `refund.php` / `save.php` already POST-only + `Auth::verifyCsrf` — unchanged; ALL new forms (give page, Pay-now, admin PayPal fields) carry `csrf_token` (§6, §7) |
| S7 | **Open redirect** | `sanitizeReturnTo()` unchanged (rejects `//`, `://`, non-rooted); the provider redirect URL comes exclusively from PayPal's own `links[rel=approve]` in an authenticated API response — never from user input |
| S8 | **SSRF** | All outbound URLs are the two hard-coded `api-m(.sandbox).paypal.com` bases + path segments built from DB-held ids (order id validated implicitly by PayPal; it is our own stored providerRef, regex-guard `^[A-Za-z0-9_-]+$` before URL interpolation as belt-and-braces); the attacker-influencable `cert_url` header is **forwarded as JSON data to PayPal, never fetched by us** (§3.1); mode string never concatenated into URLs (§1.1) |
| S9 | **IDOR / cross-user access** | `return.php` + `finalizeReturn` scope to `siteID + userID` (§2.1); pledge checkout requires `donorID = session user` (§6.3); category lookup site-scoped; refund admin-only + site-scoped (existing) |
| S10 | **Amount tampering at initiation** | Pledge amount FORCED server-side from the pledge row; giving amount floor (100p) + ceiling (£10k) server-side; pounds→pence conversion only at the existing server boundary (§6.3, §6.1) |
| S11 | **Provider-page text injection** | POSTed `description` eliminated; descriptions server-built from DB names, truncated, control-chars stripped (§6.3, §1.3) |
| S12 | **Error-message information leakage** | User-facing failures are generic flashes ("Could not start checkout…", "Payment is still being confirmed"); provider issue codes go to `errorMsg`/admin surfaces only; no PayPal debug bodies ever reach HTML |
| S13 | **Fail-open on partial config** | Empty clientId/secret → create returns null (checkout visibly fails); empty webhookId → verification false → 401 (admin hint text tells the operator why); unparseable capture states leave rows `pending`, never `succeeded` |
| S14 | **eCheck / delayed captures** | Capture `status === 'PENDING'` explicitly leaves the row pending awaiting `PAYMENT.CAPTURE.COMPLETED` (§2.2.3) — never treated as success |
| S15 | **TLS** | cURL defaults (peer+host verification) as in the Stripe block; no `CURLOPT_SSL_VERIFY*` overrides anywhere (grep-able reviewer check) |

---

## 10. Build decomposition, exact file list, verification

### 10.1 Files (11 code/SQL + docs)

| # | File | Change |
| --- | --- | --- |
| 1 | `web/_core/Payments.php` | PayPal section: `paypalBase`, `paypalAccessToken` (+ static cache prop), `paypalCreateCheckout`, `paypalCaptureOrder`, `paypalFetchOrder` (GET order, used by 422 reconcile), `paypalVerifyWebhook`, `handlePayPalEvent`, `paypalRefund`, `paypalMoneyToPence`; `startCheckout` paypal branch; `ingestWebhook` paypal branch; `refund` paypal branch; `markPaymentSucceeded` signature + integrity gate + atomic transition; new public `finalizeReturn`; header docblock updated (PayPal no longer "follow-up") |
| 2 | `web/_apps/payments/return.php` | Call `Payments::finalizeReturn` before render; render pending-after-ok state; keep own-payment scoping |
| 3 | `web/_apps/payments/checkout.php` | §6.3 validation block (purpose whitelist, category/pledge server checks, forced pledge amount, server-built description, amount ceiling) |
| 4 | `web/_apps/payments/index.php` | §7.2 admin PayPal column + provider label + warnings |
| 5 | `web/_apps/payments/save.php` | §7.3 new keys + sensitive clientId keep-if-blank |
| 6 | `web/_apps/giving/give.php` | **NEW** Give-online page (§6.1) |
| 7 | `web/_apps/giving/index.php` | Give-online button (§6.2) |
| 8 | `web/_apps/projects/my-pledges.php` | pledgeID in query + Pay-now form (§6.2) |
| 9 | `web/_sql/167_paypal_checkout.sql` | **NEW** migration (§8; renumber if 167 taken) |
| 10 | `web/_sql/full_schema.sql` | §8 fold (2 settings rows, clientId sensitive flip, giving/give route, migrations seed) |
| 11 | Docs: `CHANGELOG.md`, `FEATURES.md` (payments row: "PayPal live"; giving row: give-online; projects row: pay-pledge), `DEV_NOTES.md` (PayPal setup: sandbox app, webhook subscription + id, mode switch, integrity-gate note), `.claude/CLAUDE.md` recent-ships line | per standing instructions |

House style on every touched file: `declare(strict_types=1)`, full-IF, prepared
statements only, `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`, emoji section comments,
file-header block, `DIRECTORY_SEPARATOR` paths, no `<table>`, no native `confirm()`
(use `data-confirm`).

### 10.2 Sonnet build spec (ordered)

1. Read §0's listed files first (Payments.php, checkout/return/webhook/save/index,
   giving/index, projects/my-pledges, schema blocks) — do not skip.
2. Implement `Payments.php` bottom-up: money helpers → base/token → create →
   verify → capture → event handler → refund → `markPaymentSucceeded` rework →
   `finalizeReturn` → the three dispatch-branch edits. Keep the Stripe block
   byte-identical except the shared `markPaymentSucceeded`.
3. Implement checkout.php hardening, then return.php, then the two admin files.
4. Implement give.php + the two button surfaces.
5. Write the migration + full_schema fold together (parity check reads both).
6. Docs pass.
7. Run the §10.3 gates until all green. Commit (NO push unless asked); PR as
   **DRAFT** (§10.4).
8. Forbidden: no Composer/SDKs, no new tblRoutes rows beyond `giving/give`, no
   `api/*` anything, no float money math, no `CURLOPT_SSL_VERIFY*` lines, no
   logging of tokens/secrets, no `markPaymentSucceeded` call from a PayPal path
   without observed amount+currency.

### 10.3 Verification plan

- **Static:** `php -l` on every touched `.php` (zero warnings); `python3` each of
  `tools/audit-checks/check_mariadb_only_ddl.py`, `check_migration_idempotency.py`,
  `check_route_targets.py` (giving/give ↔ give.php parity), `check_schema_seed_parity.py`,
  `check_settings_keys.py` (new `payments.paypal.*` keys seeded), `check_sql_columns.py`,
  `check_php_table_refs.py`, `check_no_native_confirm.py`, `check_cdn_sri.py`,
  `check_mobile_readiness.py` — all green; CI (`pr-security.yml`, Psalm, CodeQL,
  actionlint, e2e-migrations) monitored per the STANDING instruction until the
  security comment is clean.
- **Self-review greps** (builder runs before committing):
  `grep -n 'error_log\|Logger' web/_core/Payments.php` — confirm no secret/token in
  any logged string; `grep -n 'markPaymentSucceeded' web/_core/Payments.php` — confirm
  every paypal call site passes 4 args; `grep -n 'VERIFY' web/_core/Payments.php` — empty.
- **Manual sandbox checklist** (PayPal developer account, sandbox app + sandbox buyer):
  1. Admin: enter clientId/secret, mode=sandbox, create webhook on
     `https://<dev-host>/payments/webhook?provider=paypal` subscribed to the 3 events,
     paste webhook id, provider=paypal, enable payments. Confirm secrets show "set"
     badges and are never re-echoed.
  2. Happy path: `/giving/give` → £10 → approve as sandbox buyer → return page
     "Thank you £10.00" → `tblPayment` succeeded with capture-id providerRef →
     ONE `tblGivingEntry` (£10, method card, reference `payment:{id}`).
  3. Abandon path: start checkout, approve, **close the tab** → within webhook
     delivery, row goes succeeded via CHECKOUT.ORDER.APPROVED backstop; exactly one
     giving entry.
  4. Cancel path: click cancel on PayPal → return `result=cancel` → row stays pending; no entries.
  5. Replay: PayPal dashboard "Resend" on PAYMENT.CAPTURE.COMPLETED → 200, **no**
     second giving entry, single tblWebhookEvent row (unique key).
  6. Forgery: `curl -X POST …/payments/webhook?provider=paypal -d '{"event_type":"PAYMENT.CAPTURE.COMPLETED", …}'`
     (no/garbage transmission headers) → **401**, tblWebhookEvent `verified=0`, row untouched.
  7. **Integrity drill (the S1 test):** start a checkout, then
     `UPDATE tblPayment SET amountPence = 99999 WHERE paymentID = <id>` before
     approving; approve+return → row must land `status='failed'`,
     `errorMsg='amount-mismatch…'`, NO giving entry, activity-log `PaymentIntegrityFail`.
  8. Double-capture race: after approval, hit the return URL twice fast (two tabs)
     → one success, one no-op; single giving entry.
  9. Pledge: pledge £25 on a project → My pledges "Pay now" → approve → pledge
     Fulfilled + giving linkage; then attempt
     `curl -b <session> -d 'purpose=pledge&purposeRef=<someone else's pledgeID>&csrf_token=…' /payments/checkout`
     → rejected. And `amount=1.00` POSTs for pledges are ignored (forced amount).
  10. Refund: admin refund → PayPal sandbox shows refund; row `refunded`. Then a
      dashboard-initiated refund on another payment → webhook flips it to `refunded`.
  11. Cross-provider sanity: switch provider back to stripe → give flow still works
      end-to-end (regression on the shared `markPaymentSucceeded`).
  12. IDOR: user B opens user A's `/payments/return?payment=<A>&result=ok` → generic
      page, no amount, **and no capture attempt fired** (finalizeReturn scoping).

### 10.4 PR posture

Open as **DRAFT** titled `feat(payments): PayPal adapter (Orders v2) + online giving checkout UI`,
labeled `type:feature`, `scope:core`, `priority:high` + a security-review request in
the description. The description must point the adversarial reviewer at: (1) the S1
integrity gate in `markPaymentSucceeded` and the §4.2 "no null observed values from
PayPal paths" rule, (2) `paypalVerifyWebhook` fail-closed branches, (3) the §3.3
providerRef-binding checks, (4) the atomic status transition. Do **not** mark ready
for review until that security pass and the pr-security bot comment are both clean.
A GitHub issue per the standing instructions (description/scope/acceptance criteria
mirroring §§1-9) precedes the PR; #268 is the parent to reference.

---

## OPEN QUESTIONS

1. **Migration number.** Repo tops out at `165`; the coordinator designated `167`
   (166 presumably claimed by a parallel gap item). Builder must take the next
   genuinely free number at build time and rename file + self-record + full_schema
   seed consistently.
2. **`payments.paypal.clientId` sensitivity.** Directive says encrypt it; PayPal
   itself treats client ids as public identifiers. Plan complies (flip-where-empty
   migration + keep-if-blank UI, §8 hazard handled). If the owner prefers the
   simpler Stripe-publishable-style plain treatment, drop the flip + keep the text
   input — a 5-line diff either way. Decide before build.
3. **Backstop capture on `CHECKOUT.ORDER.APPROVED` — in or out of v1?** Plan says IN
   (it closes the "approved but never returned" money-in-limbo gap). If the reviewer
   prefers a smaller v1 surface, dropping it degrades gracefully (orders left
   APPROVED auto-void at PayPal after ~3 days, payer never charged) — but then the
   return path is the only capture trigger.
4. **Stripe parity for the integrity gate.** `checkout.session.completed` carries
   `amount_total`/`currency` — passing them through the same observed-value params
   would extend S1 to Stripe. Recommended, but scoped OUT here to keep the Stripe
   diff zero; file a follow-up issue.
5. **Pledge currency edge.** `checkout.php` charges `payments.currency` while
   `tblProject.currency` can differ; a EUR project pledge would be charged in GBP at
   face value. Pre-existing quirk now user-reachable — v1 mitigation: on the Pay-now
   surface, hide the button when `project.currency !== payments.currency` (1 condition);
   proper multi-currency is a separate issue.
6. **`application_context` vs `payment_source.paypal.experience_context`.** Plan
   uses the former (universally supported); PayPal steers new integrations to the
   latter. If sandbox testing shows deprecation warnings, the swap is mechanical
   (same fields nested under `payment_source.paypal.experience_context`).
7. **Giving amount ceiling** (£10,000) — confirm with the owner; expose later as a
   `payments.maxAmountPence` setting if churches ask.
8. **`feePence` capture** from `seller_receivable_breakdown` — included as optional
   nicety; drop silently if the parse gets fiddly.
9. **Version bump** — feature-level: `1.4.0 → 1.5.0` in `web/_core/version.php`?
   Owner/coordinator call at release-prep time, not in this PR unless instructed.

---

**Sources** (PayPal API verification):
- [Verify webhook signature — PayPal REST reference](https://docs.paypal.ai/reference/api/rest/verify-webhook-signature/verify-webhook-signature)
- [Integrate webhooks | PayPal Developer](https://developer.paypal.com/api/rest/webhooks/rest)
- [paypal-rest-api-specifications — notifications_webhooks_v1.json](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/notifications_webhooks_v1.json)
- [Webhooks API v1 | PayPal Developer](https://developer.paypal.com/docs/api/webhooks/v1/)
