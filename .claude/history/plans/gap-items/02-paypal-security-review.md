# Adversarial security review — PayPal Orders v2 checkout (`claude/paypal-checkout`)

**Verdict: PASS — the S1 amount/currency integrity gate holds on every PayPal success path; no CRITICAL or HIGH findings. Three low/informational notes, one of which (SEC-01) is a pre-existing open-redirect-guard weakness this PR merely relies on.**

Reviewed at HEAD `763028d` (worktree `…/agent-afe51f954c020ac28`). All eight scoped PHP files are `php -l` clean. Every control the plan (§4 integrity gate, §9 S1–S15) claims was independently re-derived against the code, not trusted from inline comments.

---

## Findings table

| ID | Severity | Confidence | Location | Title |
| --- | --- | --- | --- | --- |
| SEC-01 | LOW | CONFIRMED | `web/_apps/payments/checkout.php:42-53` | `sanitizeReturnTo()` open-redirect guard bypassable via `/\` backslash (pre-existing, CSRF-gated) |
| SEC-02 | INFO | CONFIRMED | `web/_core/Payments.php:399-416` | Stripe success paths still pass `null` observed values — S1 gate protects PayPal only (documented Q4, accepted) |
| SEC-03 | INFO | PLAUSIBLE | `web/_core/Payments.php:869-887` | `paypalVerifyWebhook` re-encodes `webhook_event` (decode→encode) — fail-closed reliability note, not a bypass |

No SQLi, no XSS, no CSRF gap, no IDOR, no payment-substitution, no underpayment-booking, no secret/token leak, no SSRF, no bind_param arity fault was found. Details and per-category CLEAN evidence below.

---

## SEC-01 — `sanitizeReturnTo()` open-redirect bypass via backslash (LOW, pre-existing)

`web/_apps/payments/checkout.php:42-53`:
```php
function sanitizeReturnTo(mixed $raw): string
{
    $value = (string) $raw;
    if ($value === ''
        || str_starts_with($value, '//') === true
        || str_contains($value, '://') === true
        || str_starts_with($value, '/') === false
    ) {
        return '/';
    }
    return $value;
}
```
The guard rejects `//host`, `scheme://host`, and non-rooted values, but a value like `/\evil.com` passes every check: it does **not** start with `//` (`/\` ≠ `//`), does not contain `://`, and does start with `/`. It is then emitted verbatim as `header('Location: /\evil.com')`. Per the WHATWG URL spec, browsers normalise `\` to `/` in the path-start state for http(s), so `/\evil.com` resolves as the protocol-relative `//evil.com` → `https://evil.com`. This is the well-known `/\` open-redirect bypass.

**Attacker steps:** get a logged-in victim to submit a POST to `/payments/checkout` with `return_to=/\evil.com` and an amount that fails validation (e.g. `amount=0`), landing them on `evil.com` after the flash redirect.

**Why LOW / not this PR's defect:** (a) `sanitizeReturnTo` is pre-existing (introduced in #430, unchanged by this commit — confirmed via `git diff 1f4644e 763028d`). (b) The redirect fires only on `/payments/checkout` **error** branches, and the endpoint is POST-only + `Auth::verifyCsrf`-gated, so an attacker cannot mount a classic cross-site POST without the victim's session CSRF token. (c) The two real callers (`give.php`, `my-pledges.php`) hard-code `return_to` to safe root-relative paths. Practical exploitability is therefore low, but the guard is genuinely bypassable.

**Specific fix (in `sanitizeReturnTo`):** reject any value containing a backslash, and normalise before the checks — e.g. add `|| str_contains($value, '\\') === true` to the reject clause (and optionally require a second character that is not `/` or `\`). One line, provider-neutral, hardens Stripe and PayPal error redirects alike. Owner's call whether to fold into this PR since it is pre-existing.

---

## SEC-02 — Stripe success paths remain un-gated (INFO, accepted / documented)

`web/_core/Payments.php:399-416` — `handleStripeEvent()` still calls `markPaymentSucceeded($paymentId, $intentId)` with no observed amount/currency, so the S1 gate (`if ($observedAmountPence !== null)`, line 1115) is skipped for Stripe. This is the plan's explicit Q4 scope-out (Stripe diff kept zero), flagged here only so it is on record: if a site runs **Stripe** as the active provider, the "capture £1 / book £500" class is not defended by this gate for that rail. It is not a regression (Stripe never had the gate) and not reachable through the PayPal code. No action required for this PR; the follow-up issue to extend the gate to `checkout.session.completed`'s `amount_total`/`currency` is the correct fix.

---

## SEC-03 — `paypalVerifyWebhook` re-encodes the event body (INFO, fail-closed)

`web/_core/Payments.php:869-887` json-decodes the raw body and passes the decoded array back as `webhook_event`, which the whole verify envelope then json-encodes. PayPal's verify-webhook-signature API recomputes a CRC32 over the `webhook_event` it receives; a re-serialisation that differs from the transmitted bytes (numeric/unicode/whitespace representation) can make a **legitimate** webhook fail verification. Critically this can only produce **false negatives** (reject a real event → HTTP 401 → PayPal retries; the return-path capture covers the money regardless) — it can never make a forged event verify. So it is a reliability note, not a security hole. Recommend exercising plan step 10.3's replay/forgery sandbox tests to confirm the `CHECKOUT.ORDER.APPROVED` backstop actually verifies in practice.

---

## Control-by-control CLEAN evidence

### S1 — amount/currency integrity gate — **HOLDS on every success path. CLEAN.**
The gate lives at the single choke point `markPaymentSucceeded()` (`Payments.php:1093-1138`): when `$observedAmountPence !== null` it compares `$observedAmountPence` to `(int)$row['amountPence']` and `strtoupper($observedCurrency)` to `strtoupper($row['currency'])`; on any mismatch it writes `status='failed'` (scoped `WHERE paymentID=? AND status='pending'`), logs `PaymentIntegrityFail`, and returns **before** any fan-out. Every PayPal success path passes non-null observed values parsed by the strict parser:
- **Return capture** — `finalizeReturn` (263-296) → `paypalCaptureOrder` 2xx (717-719) → `paypalHandleCaptureResult` parses `purchase_units[0].payments.captures[0].amount` (805-808) → `markPaymentSucceeded($paymentId, $captureId, $obsPence, $obsCurrency)` (825).
- **422 `ORDER_ALREADY_CAPTURED` reconcile** — (733-739) → `paypalFetchOrder` GET order → same `paypalHandleCaptureResult` → same 4-arg call.
- **Verified webhook `PAYMENT.CAPTURE.COMPLETED`** — `handlePayPalEvent` parses `resource.amount` (968-970) → `markPaymentSucceeded($paymentId, $captureId, $obsPence, $obsCurrency)` (981).
- **`CHECKOUT.ORDER.APPROVED` backstop** — delegates to `paypalCaptureOrder` (1000) → capture-response path above.

Both PayPal extraction sites **fail the row** (status=`failed`, `PaymentIntegrityFail`) and return *without calling* `markPaymentSucceeded` when `captureId===''` or `obsPence===null` or `obsCurrency===''` (814-823, 971-980) — so a null observed value can never reach the gate as a "skip". No PayPal call site passes null. `grep` of the 4 `markPaymentSucceeded(` call sites confirms: the two Stripe sites pass 2 args (null observed, intended); the two PayPal sites pass 4. `paypalMoneyToPence` (476-482) is `^([0-9]+)\.([0-9]{2})$` only — `"10.5"`, `"1,000.00"`, `"1e3"`, zero-decimal all return `null` → treated as mismatch/failure, never mis-parsed.

### S2 / Atomic transition — **CLEAN.**
`markPaymentSucceeded` final transition (1146-1155) is a single `UPDATE … SET status='succeeded' … WHERE paymentID=? AND status='pending'` gated on `affected_rows !== 1 → return`, placed **before** the Giving/pledge fan-out (1157-1205). Return-path vs webhook vs replay all converge here; only the first winner fans out. The early guard (1108) returns on an already-`succeeded` row, and the mismatch UPDATE's `AND status='pending'` means a mismatch replay can never downgrade a succeeded row.

### S2 — webhook verification fail-closed — **CLEAN.**
`paypalVerifyWebhook` (852-909) returns `false` on: any of the five transmission headers empty, `webhookId` empty (863-867), non-array JSON body (869-872), null token (874-877 — which itself covers empty clientId/secret via `paypalAccessToken` 513), non-2xx (901), non-array response (904-906); returns `true` only on 2xx **and** strict `verification_status === 'SUCCESS'` (908). `ingestWebhook` (145-201) writes the audit row with `verified` 0/1 in all cases, then `return false` on unverified → `webhook.php:36-39` emits HTTP 401 with zero side effects; `handlePayPalEvent` runs only in the verified branch (197-198). Header names extracted (`Paypal-Transmission-Id`, `-Time`, `-Sig`, `Paypal-Cert-Url`, `Paypal-Auth-Algo`) exactly match `webhook.php:31`'s `str_replace('_','-', ucwords(strtolower(substr($k,5)),'_'))` normalisation (verified by hand: `HTTP_PAYPAL_TRANSMISSION_ID` → `Paypal-Transmission-Id`), and each also has the raw `HTTP_…` fallback.

### S3 — providerRef binding / payment substitution — **CLEAN.**
`custom_id` is server-set to `(string)$paymentId` at order creation (`Payments.php:588`) and is immutable to the payer. `paypalHandleCaptureResult` cross-checks the capture's echoed `custom_id` against `$paymentId` and fails the row on mismatch (773-788). `handlePayPalEvent`/`PAYMENT.CAPTURE.COMPLETED` derives `paymentId` from the verified event's `custom_id`, loads the row, then requires a positive binding (947-962): `rowRef===order_id`, OR `rowRef===captureId` (post-return replay), OR (`order_id` absent AND row pending AND `providerRef` is a real id, not `pending-…`); otherwise logs `PaymentIntegrityFail` and does nothing. Because a verified event's `custom_id` can only be the paymentId our own server set for the payer's own order, a verified-but-unrelated capture cannot be bound to a victim row. `return.php` never reads PayPal's appended `token`/`PayerID` — the order id comes solely from the DB row's `providerRef` (return.php:39-41, finalizeReturn:263-282).

### S4 — double capture / double fan-out — **CLEAN.**
Atomic `affected_rows===1` gate (above) + capture idempotency `PayPal-Request-Id: cap-{idem}` (707) + 422 `ORDER_ALREADY_CAPTURED` handled as reconcile not error (733-739) + `Projects::fulfilPledge` independently idempotent. Return-then-webhook, webhook replay, and double-return-click all resolve to exactly one fan-out.

### S5 — secret / token handling — **CLEAN.**
`grep error_log|Logger::` in `Payments.php` shows only `PaymentIntegrityFail` activity logs carrying `paymentID` + coarse reason strings — no token/clientId/secret/response body. `paypalAccessToken` returns `null` without logging `$resp` on failure (537-538). `errorMsg` writes are coarse codes only (`paypal-capture-{issue|code}`, `-customid-mismatch`, `-capture-pending`, `-amount-unparseable`, `amount-mismatch exp:…got:…` — amounts/currency only), each `substr(...,0,255)`. OAuth uses `CURLOPT_USERPWD` + `CURLAUTH_BASIC` (527-528), credentials never in a URL. Per-request token cache keyed on `sha256(mode.clientId)`, never persisted (69, 518-521, 546). Admin UI renders "set" badges and password keep-if-blank inputs, never re-echoes stored secrets (`index.php:160-163`, clientId now a `type=password`).

### S7 / open redirect — see SEC-01. The provider redirect itself is PayPal's own `links[rel=approve]` href from an authenticated API response (`Payments.php:626-638`), never user input. **CLEAN aside from the pre-existing guard weakness.**

### S8 — SSRF — **CLEAN.**
All outbound URLs are `paypalBase()`'s two hard-coded constants (490-494) + fixed path segments; the mode string is never concatenated into a URL (garbage mode → sandbox). Order/capture ids interpolated into paths are our own stored `providerRef`, `rawurlencode`d and regex-guarded `^[A-Za-z0-9_-]+$` before use (`paypalFetchOrder`:652, `paypalCaptureOrder`:693, `paypalRefund`:1039). `cert_url` is forwarded to PayPal as JSON data (882), never fetched by us. No `CURLOPT_SSL_VERIFY*`/`VERIFYPEER`/`VERIFYHOST` anywhere in `Payments.php` (grep empty) — cURL default peer+host verification retained (S15 CLEAN).

### S9 — IDOR / cross-user — **CLEAN.**
`return.php` SELECT scopes `paymentID = ? AND siteID = ? AND userID = ?` (44-46), and `finalizeReturn` re-applies the same triple scope itself (263) — a mismatched/foreign payment yields `null` and no capture fires. Pledge checkout requires `pledge.donorID === session user` (checkout.php:122); giving category lookup is site-scoped (141); `give.php`/`my-pledges.php`/`giving/index.php` all scope by site/donor.

### S10 / S11 — amount tampering & provider-page injection — **CLEAN.**
Pledge amount+currency **forced** from the pledge row, POSTed amount ignored (checkout.php:131-133). Giving floor 100p (82) + ceiling `GIVING_MAX_AMOUNT_PENCE` £10k (158-163). Descriptions server-built (`'Giving — '.name`, `'Pledge — '.title` truncated 120) — the POSTed `description` field is deleted from the flow (diff confirms `$description = trim($_POST['description'])…` removed → `'Donation'`). `paypalCreateCheckout` additionally strips control chars + truncates to 127 (580-581) as belt-and-braces. `purpose` outside `giving|pledge` is coerced to `other` with `purposeRef=null` (169-175) — no arbitrary purposeRef rides along.

### bind_param arity — **CLEAN (all 30 sites checked).**
Every `bind_param` in the eight scoped files was counted against its `?` placeholders and bound args. Spot-checks of the three multi-arg inserts: `Payments.php:107` `'iisssisss'` (9) vs 9 `?` vs 9 args; `:186` `'ssssi'` (5) vs 5 vs 5; `:1187` `'iiiissiii'` (9) vs 9 `?` (2 columns are `CURDATE()`/`"card"` literals) vs 9 args. Types match value types throughout; nullable binds (`userID`, `purposeRef`, `providerRef`, `campaignID`, `pledgeID`) use `i`/`s` with mysqli's NULL passthrough, which is valid. No venue-style mismatch.

### SQLi / XSS / CSRF — **CLEAN.**
100% prepared statements with `bind_param`; no user input concatenated into SQL (error strings are concatenated only into *parameterised* binds). All dynamic HTML output is `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` or numeric (`number_format`/`(int)`); `slug` is `urlencode`d (my-pledges:70). `return.php` shows only the row's own numeric amount + a `match`-derived currency symbol. Every money-moving/mutating endpoint is POST + `Auth::verifyCsrf` (checkout:64, save:26, refund pre-existing) and every new form carries `csrf_token` (give.php:86, my-pledges:80, admin index:112/205).

### Migration 167 / full_schema fold — **CLEAN.**
`167_paypal_checkout.sql` is seeds-only (no DDL → MySQL-8-safe): two `INSERT … ON DUPLICATE KEY UPDATE settingKey=settingKey` settings rows, the `giving/give` route `ON DUPLICATE KEY UPDATE targetFile=VALUES(targetFile)`, idempotent `tblMigrations` self-record. The clientId `isSensitive` flip is predicate-guarded to `settingValue='' OR IS NULL` (67-69) — it never blanks a site already holding a plaintext client id (the `decrypt_setting('')`-on-plaintext hazard), and replays as a no-op. full_schema fold matches: `giving/give` route row added, clientId seed flipped `0→1` (blank on fresh install so no hazard), `webhookId`/`mode` rows added, `167_…` appended to the migrations seed. `166_webhook_retry.sql` exists → 167 is the correct free number.

---

## Bottom line
The #1 threat (underpayment booked as full) is closed: every PayPal path that can set `succeeded` passes the *observed captured* amount+currency into the single choke-point gate, which refuses on any mismatch with no fan-out, and the transition is atomic. Forged/replayed webhooks fail closed (401, no side effects); payment substitution is blocked by server-set immutable `custom_id` plus a providerRef-binding cross-check; races and replays converge on one atomic winner; pledge amount is forced server-side and cross-user fulfilment is blocked; no secret/token is logged; no SSRF/SSL-verify override; no SQLi/XSS/CSRF/bind_param fault. Only SEC-01 (pre-existing, CSRF-gated open-redirect-guard weakness) and two informational notes remain.
