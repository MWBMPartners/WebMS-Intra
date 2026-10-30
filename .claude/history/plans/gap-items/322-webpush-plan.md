# Implementation Plan — Issue #322: Web Push notifications ("we're live now" + service-reminder channels)

**Target branch:** `alpha` (audited at `c9b11c5`, 2026-08-28) · **Migration reserved: 175** (re-confirm at build)
**Stack constraints:** PHP 8.5 BC-to-8.4, MySQL 8.0, DreamHost shared hosting, no Composer, no CLI — every crypto step ships as house code on ext-openssl / ext-hash / ext-sodium only.
**Crypto validated empirically:** the full VAPID-ES256 + RFC 8291 aes128gcm pipeline below was executed end-to-end (sign→verify, encrypt→decrypt round-trip) on PHP 8.4.19 using exactly the function calls specified in §5. No step is speculative.

---

## 1. Current-state analysis (file:line evidence)

### 1.1 What EXISTS (the dormant substrate, migration 111 / #319 "COP easy wins")

| Piece | Location | State |
| --- | --- | --- |
| `tblPushSubscriptions` | `web/_sql/111_cop_easy_wins.sql:42-66`; folded in `web/_sql/full_schema.sql` | **Live table.** Columns: `subID` PK, `siteID` (FK `tblSites`, default 1), `userID` (nullable, FK `tblUsers` **ON DELETE SET NULL** — anonymous device subs are first-class), `endpoint VARCHAR(500)` with `UNIQUE uq_ps_endpoint (endpoint(255))`, `p256dhKey VARCHAR(255)` (base64url subscriber public key), `authKey VARCHAR(255)` (base64url auth secret), `userAgent`, `channels VARCHAR(500)` JSON array default `'["livestream","reminders"]'`, `createdAt`, `lastUsedAt`, `isActive TINYINT(1)` |
| Subscribe endpoint | `web/_apps/api/push/subscribe.php` | CSRF-gated POST (header `X-CSRF-Token` or body field, :48-62); gates on `push.enabled` setting (:65-69); channel whitelist `['livestream','reminders','announcements']` (:84-88); upsert by endpoint via `ON DUPLICATE KEY UPDATE` (:98-110). Accepts logged-in AND anonymous |
| Unsubscribe endpoint | `web/_apps/api/push/unsubscribe.php` | CSRF-gated POST; soft-delete `isActive = 0` by endpoint (:54-61) |
| VAPID settings keys | `web/_sql/111_cop_easy_wins.sql:73-83` → `full_schema.sql:4668-4671` | **Already seeded, all empty**: `push.vapidPublicKey` (isSensitive=0), `push.vapidPrivateKey` (**isSensitive=1** — encrypted-at-rest path already reserved), `push.contact` ("mailto:/https: contact required by RFC 8292"), `push.enabled = 'false'` |
| Service worker | `web/public_html/sw.js` | Offline caching (install/activate/fetch, :36-184), background sync (:195-204), message (:206-210). **NO `push` handler, NO `notificationclick` handler, NO `pushsubscriptionchange` handler** |
| SW registration | `web/_core/templates/footer.php:134-138` | `navigator.serviceWorker.register('/sw.js', { scope: '/' })` on every page |
| CSRF for JS | `web/_core/templates/header.php:174` | `<meta name="csrf-token" …>` already emitted on every page |
| PWA manifest | `web/public_html/manifest.php` | Brand-aware; icons at :65-70, :90. Nothing push-specific needed (VAPID web push requires no manifest keys) |

### 1.2 What is BROKEN (must be repaired by this feature)

1. **The subscribe/unsubscribe handlers are unreachable dead code.** `Router::handleSpecialRoutes` hands all `api/*` paths to `ApiRouter::dispatch`, which resolves `api/{appName}/{action}` → `_apps/{appName}/api/{action}.php` (`web/_core/ApiRouter.php:80-81, 104-117`). So `api/push/subscribe` loads `_apps/push/api/subscribe.php` — the shipped files sit at `_apps/api/push/…` and can never be loaded. The tblRoutes rows migration 111 registered (`111_cop_easy_wins.sql:68-71`) were correctly deleted as dead by migration 158 (`web/_sql/158_worship_api_route_cleanup.sql:105-106`; tombstone comment `full_schema.sql:4659`). And **no `api.push.subscribe.enabled` / `api.push.unsubscribe.enabled` flags are seeded anywhere** — even at the right path, ApiRouter would 403 (`ApiRouter.php:130-134`). Fix: relocate + seed flags (precedent: migration 144 `livestream/ping` relocation; PR #372 worship `state`/`advance` relocation).
2. **No client-side subscription code exists.** Zero hits for `pushManager`, `applicationServerKey`, `Notification.permission` in `web/` — no browser ever subscribes today.
3. **No server-side sender exists.** Zero hits for `openssl_pkey_derive`, `hash_hkdf`, aes128gcm, VAPID JWT, or any POST to a push service. `openssl_sign` is used only for RS256 JWTs (`web/_core/VideoEmbed.php:291`, `web/_core/MailerGoogle.php:266`).
4. **No VAPID key material exists anywhere** (keys seeded empty; nothing generates or reads them).
5. **Data-protection gaps:** `tblPushSubscriptions` appears in neither `web/_core/GdprEraser.php` (endpoint URL + keys + userAgent tied to `userID` are personal data) nor the offboarding revocation flow (`web/_apps/offboarding/do.php`).

### 1.3 Notification prefs + channel model

- `tblUsers.notifyPrefs` — JSON column (migrations 026/050). **First honoured** by #439: `web/_apps/cron/user-reminders.php:138-149` `notifyPrefAllows(?string $json, string $key): bool` — **default-on when the key is missing or JSON is empty** (:146-148). Selected alongside recipients as `u.notifyPrefs` (:322) and checked per send (`taskReminders` :357, `rotaReminders` :454).
- Prefs UI: `web/_apps/auth/account/notifications.php` — `$switchRow($key, $label, $help)` checkbox helper (:110-111), known-keys map (:86-88: `givingStatements`, `taskReminders`, `rotaReminders`). Save side: `web/_apps/auth/account/notifications-save.php:37-61` — `$allowedKeys` whitelist, absent checkbox ⇒ `false`.
- **Per-DEVICE channel opt-in already exists** in `tblPushSubscriptions.channels` (JSON array; whitelist in subscribe.php:84). Two prefs layers therefore compose: channels = per-device (works for anonymous), notifyPrefs = per-user master override (logged-in only).

### 1.4 The two trigger points

- **"We're live now"**: liveness is *schedule-derived*, not event-driven — `Portal\Core\Livestream::currentlyLive(int $siteId)` (`web/_core/Livestream.php:28-66`) returns a channel row when an active `tblLivestreamSchedule` window (dayOfWeek + startTime…endTime in the schedule's timezone) covers now. There is **no explicit go-live state transition anywhere** to hook — the `/live` viewer page (`web/_apps/live/index.php:23`) just asks this function. Admin surfaces: `/admin/livestream` channel+schedule CRUD (`web/_apps/admin/livestream/index.php` — single action-dispatched POST endpoint, :30-36 CSRF + admin gate at :20-22) and the per-event Host Console (`web/_apps/admin/host-console/{index,event}.php`, #317). ⇒ the trigger must be (a) a **manual admin button** and/or (b) a **cron sweep that detects the window opening** with a dedupe log.
- **Service reminders**: `web/_apps/cron/event-reminders.php` (#329) — 15-minute external cadence, token-gated (`reminders.cron_token`, constant-time compare :29-33), three windows (24h/1h/day, header :11-16), single-shot dedupe via `tblEventReminderLog (eventID, reminderType)`. The 1h window emails RSVP'd "going/confirmed" users (`sendReminderBatch`, :44-62). It honours **no** notifyPrefs today. `cron/user-reminders.php` (#439) sweeps task/rota/milestone reminders and *does* honour prefs. Cron endpoints all follow the empty-token-fails-closed pattern (`171_user_reminders.sql:80-86`).

### 1.5 House machinery this feature reuses

- **Encrypt-at-rest**: `encrypt_setting()` / `decrypt_setting()` (`web/_core/bootstrap.php:179-239`) — sodium secretbox keyed off `_auth_keys/enc.key`; `decrypt_setting()` returns `''` on any failure. `tblSettings.isSensitive=1` marks the column (`full_schema.sql:82-94`). Admin save pages that already encrypt: `admin/integrations/cloudflare-stream/save.php` (blank submitted value PRESERVES the stored secret — copy that idiom), `admin/sms/save.php`, `admin/captcha/save.php`.
- **P-256 ASN.1**: `web/_core/WebAuthn.php:493-560` — uncompressed-point→SPKI-PEM DER prefix (`coseToPem`, :508-522), `asn1Integer`/`asn1Length` helpers. The exact DER constants WebPush.php needs already ship in-house.
- **Outbound HTTP**: `web/_core/WebhookDispatcher.php:276-288` — curl POST with `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_TIMEOUT`, `CURLOPT_CONNECTTIMEOUT => 5`. Copy the shape, tighten per §7.
- **Rate limiting**: `Portal\Core\RateLimiter::tooMany(string $bucket, int $maxHits, int $windowSeconds)` + `recordHit()` (`web/_core/RateLimiter.php:307-…`), backed by `tblApiRateLimits`, fails open on DB error.
- **Generic single-shot dedupe log**: `tblUserReminderLog (refType, refID, dueDate)` — explicitly reserved for future single-shot families "with zero DDL" (`web/_sql/171_user_reminders.sql:20-26, 57-60`). The go-live dedupe uses it (`refType='push-golive'`).
- **CSP**: `header.php:153-158`. Client subscribe calls are same-origin (`connect-src 'self'` already covers them); the outbound push-service POST happens **server-side** — **no CSP change is needed anywhere**.
- **Cron auth idiom**: `Settings::get('x.cron_token')` + `hash_equals`, empty ⇒ 403 (`cron/event-reminders.php:29-33`).

### 1.6 Migration number

`web/_sql/` on alpha tops out at `174_workflow_engine.sql` (numbers 168/169 are burnt gaps — do not backfill). Remote heads checked 2026-08-28: `beta` tops at 158; `claude/venue-*` top at 170; dependabot branches carry no migrations. **Reserve 175.** Re-run `git ls-remote --heads origin` + `ls web/_sql | sort | tail` at build time; if 175 is taken, everything below renames mechanically.

---

## 2. Deliverable overview

| # | Deliverable | Summary |
| --- | --- | --- |
| D1 | `Portal\Core\WebPush` | VAPID ES256 JWT builder + RFC 8291 aes128gcm encryptor + HTTPS POST with TTL/Urgency/Topic + 404/410 pruning + channel fan-out |
| D2 | Endpoint repair | Relocate subscribe/unsubscribe to the ApiRouter convention path; seed the two `api.push.*.enabled` flags |
| D3 | Client side | `push-subscribe.js` (explicit-gesture subscribe/unsubscribe, per-device channel choice) + sw.js `push`/`notificationclick` handlers |
| D4 | Admin config | `/admin/integrations/push` — generate/paste VAPID keys (private encrypted at rest, never redisplayed), contact, master enable, TTL, auto-go-live toggle, test-send; **inert-until-keys banner** (PayPal/CF-Stream pattern) |
| D5 | Channel wiring A | "We're live now": manual notify button on `/admin/livestream` + host-console, and optional cron auto-detect of the schedule window opening |
| D6 | Channel wiring B | Service reminders: push alongside email in the `event-reminders` 1h window (per-RSVP), + optional broadcast "starting soon" to the site's `reminders` channel |
| D7 | Prefs | Two new `notifyPrefs` keys + device-level enable UI on `/account/notifications`; per-site enable flags |
| D8 | Hygiene | GdprEraser + offboarding coverage of `tblPushSubscriptions`; dead-subscription pruning columns |
| D9 | Migration 175 + full_schema fold + docs (CHANGELOG/FEATURES/DEV_NOTES "Web Push setup" — the section migration 111's header already promises) |

**INERT-UNTIL-CONFIGURED (call-out):** exactly like PayPal (`payments.paypal.*` empty ⇒ adapter inert) and Cloudflare Stream, the entire feature does nothing until the owner: (1) opens `/admin/integrations/push`, (2) clicks **Generate VAPID keys** (or pastes a pair), (3) sets the RFC 8292 contact (`mailto:` or `https:`), (4) flips `push.enabled` to true. `WebPush::isConfigured()` gates every send path AND the client bootstrap (no public key ⇒ no subscribe button rendered). All four settings keys already exist, seeded empty by migration 111 — migration 175 seeds **no key material**.

---

## 3. Protocol specification (normative for the build)

Three RFCs govern; cite them in code comments at each step.

- **RFC 8030** (HTTP Web Push): the delivery request — POST the encrypted body to `PushSubscription.endpoint`; `TTL` header REQUIRED (§5.2), `Urgency: very-low|low|normal|high` optional (§5.3), `Topic` optional replacement key (§5.4). Success = **201 Created**. `404`/`410 Gone` = subscription no longer valid ⇒ prune (§7.3). `429` carries `Retry-After`. Payload limit: services MUST support ≥ 4096 bytes of content (§7.2) — treat 4 KB ciphertext as a hard cap, so plaintext ≤ ~3900 bytes.
- **RFC 8291** (Message Encryption for Web Push): exactly one content coding — `aes128gcm` — with the ECDH+HKDF key derivation of §3 and the single-record requirement of §4 (record size MUST be big enough that the message is ONE record; delimiter octet is `0x02`).
- **RFC 8292** (VAPID): ES256 JWT (JWS per RFC 7515/7518) with claims `aud` = the **origin** of the push endpoint (scheme+host[+port] only, §2), `exp` ≤ 24 h ahead (§2), `sub` = the operator contact (§2.1). Carried as `Authorization: vapid t=<jwt>, k=<base64url uncompressed VAPID public key>` (§3, the `vapid` auth scheme parameters `t` and `k`).
- **RFC 8188** (Encrypted Content-Encoding for HTTP): the `aes128gcm` wire format — header `salt(16) ‖ rs(4, uint32 BE) ‖ idlen(1) ‖ keyid` (§2.1), HKDF derivations of CEK and NONCE (§2.2, §2.3).

### 3.1 aes128gcm encryption steps (RFC 8291 §3.1-3.4, §4 + RFC 8188 §2) — with the exact PHP call per step

Inputs: subscriber public key `ua_public` = base64url-decoded `p256dhKey` (65-byte uncompressed point `0x04‖x‖y`), auth secret = base64url-decoded `authKey` (16 bytes), plaintext ≤ ~3.9 KB.

| Step | Algorithm (RFC cite) | PHP implementation (validated) |
| --- | --- | --- |
| 1 | Generate **ephemeral** server ECDH key pair `as_*` on P-256 — fresh per message; this is NOT the VAPID pair (RFC 8291 §3.1) | `openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])`; export the 65-byte `as_public` from `openssl_pkey_get_details()['ec']['x','y']` as `0x04 ‖ pad32(x) ‖ pad32(y)` (each coordinate left-padded to 32 with `str_pad(..., 32, "\0", STR_PAD_LEFT)` — details can return 31-byte coordinates) |
| 2 | `ecdh_secret = ECDH(as_private, ua_public)` — 32 bytes (RFC 8291 §3.1) | Wrap `ua_public` into an SPKI PEM using the 26-byte P-256 DER prefix `3059 3013 0607 2a8648ce3d0201 0608 2a8648ce3d030107 0342 00` (identical constant to `WebAuthn.php:513-518`), then `openssl_pkey_derive(openssl_pkey_get_public($uaPem), $asPrivateKey)` → must be exactly 32 bytes; abort the send otherwise |
| 3 | `IKM = HKDF-SHA-256(salt = auth_secret, ikm = ecdh_secret, info = "WebPush: info" ‖ 0x00 ‖ ua_public ‖ as_public, L = 32)` (RFC 8291 §3.3-3.4; info is 144 bytes: 13+1+65+65) | `hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret)` — PHP's `hash_hkdf` performs Extract+Expand in one call, which is exactly the RFC 5869 composition both RFCs specify |
| 4 | `salt` = 16 random bytes (RFC 8188 §2.1) | `random_bytes(16)` |
| 5 | `CEK = HKDF-SHA-256(salt, IKM, "Content-Encoding: aes128gcm" ‖ 0x00, 16)` (RFC 8188 §2.2 as profiled by RFC 8291 §3.4) | `hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt)` |
| 6 | `NONCE = HKDF-SHA-256(salt, IKM, "Content-Encoding: nonce" ‖ 0x00, 12)` (RFC 8188 §2.3). Single record ⇒ SEQ=0 ⇒ the derived nonce is used as-is | `hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt)` |
| 7 | Pad: `record = plaintext ‖ 0x02` (delimiter for the LAST record, RFC 8188 §2 / RFC 8291 §4 — exactly one record, no additional 0x00 padding needed in v1) | `$record = $plaintext . "\x02"` |
| 8 | `ciphertext = AES-128-GCM(CEK, NONCE, record)` with 16-byte tag (RFC 8188 §2) | `openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16)` — `$tag` returned by reference; body ciphertext = `$ct . $tag` |
| 9 | Body = header ‖ ciphertext: `salt(16) ‖ rs = 4096 (uint32 BE) ‖ idlen = 65 ‖ keyid = as_public(65)` = 86-byte header (RFC 8188 §2.1; RFC 8291 §4 requires keyid = the ephemeral server public key) | `$salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag` |

Round-trip verified: a simulated browser (independent P-256 pair + 16-byte auth secret) decrypted the produced body back to the exact plaintext using only the RFC-specified derivations.

### 3.2 VAPID JWT steps (RFC 8292 §2-3 + RFC 7515/7518 ES256)

| Step | Spec | PHP implementation (validated) |
| --- | --- | --- |
| 1 | Header `{"typ":"JWT","alg":"ES256"}`; claims `{"aud": "<scheme://host[:port] of endpoint>", "exp": time()+43200, "sub": "<push.contact>"}` — `aud` is the ORIGIN only (RFC 8292 §2), `exp` ≤ 24 h (use 12 h) | `parse_url()` the endpoint for scheme+host+port; base64url(JSON) both parts; signing input = `header . '.' . claims` |
| 2 | ES256 signature (RFC 7518 §3.4): ECDSA P-256/SHA-256, JOSE serialisation = raw `R‖S`, 64 bytes | `openssl_sign($input, $derSig, $vapidPrivatePem, OPENSSL_ALGO_SHA256)` produces a **DER `ECDSA-Sig-Value` (SEQUENCE{INTEGER r, INTEGER s})** — MUST be converted: parse the two INTEGERs, strip leading `0x00` sign bytes, left-pad each to 32 (`str_pad(..., 32, "\0", STR_PAD_LEFT)`), concatenate. **Shipping the DER bytes raw is the classic bug — every push service rejects it with 401/403.** A `derToJose()` private method with this exact parse ships in WebPush.php |
| 3 | `Authorization: vapid t=<jwt>, k=<base64url(uncompressed 65-byte VAPID public point)>` (RFC 8292 §3.2) | Ship the modern `vapid` scheme only. FCM, Mozilla autopush, Apple (web.push.apple.com, Safari 16+) and WNS all accept it; the legacy `WebPush …` + `Crypto-Key: p256ecdsa=` dual-header scheme is dead weight — do not implement |
| 4 | Per-origin JWT cache | The JWT depends only on (origin, exp, sub) — cache per origin in a static array for the request (a 200-subscription fan-out to FCM signs once, not 200 times) |

`base64url` helper: `rtrim(strtr(base64_encode($bin), '+/', '-_'), '=')` and the padded inverse — ship as two private static methods (no house helper exists; `simplejwt` is verify-only RS256).

### 3.3 The HTTP POST (RFC 8030)

```
POST {endpoint}
Authorization: vapid t=<jwt>, k=<vapidPublicB64url>
Content-Encoding: aes128gcm
Content-Type: application/octet-stream
Content-Length: <len(body)>
TTL: <seconds>            ← required (RFC 8030 §5.2)
Urgency: normal|high      ← RFC 8030 §5.3 ('high' for go-live, 'normal' for reminders)
Topic: <token>            ← optional collapse key, ≤32 chars [A-Za-z0-9_-] (RFC 8030 §5.4)
```

curl options (WebhookDispatcher:276-288 shape, hardened): `CURLOPT_POST`, `CURLOPT_POSTFIELDS => $body`, `CURLOPT_RETURNTRANSFER => true`, `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_MAXREDIRS => 0`, `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS`, `CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS`, `CURLOPT_CONNECTTIMEOUT => 5`, `CURLOPT_TIMEOUT => 10`, TLS verification defaults untouched.

Response handling: `201` ⇒ success, stamp `lastUsedAt = NOW()`, reset `failCount = 0`. `404`/`410` ⇒ dead: `isActive = 0`, stamp `lastHttpStatus` (§7.3 prune policy). `413` ⇒ payload too big — log platform warning, never retry. `429`/`5xx`/timeout ⇒ transient: `failCount + 1`, `lastFailureAt = NOW()`; a subscription reaching `failCount >= 8` is deactivated (push endpoints are cheap to re-create; the client re-subscribes on next visit). No in-request retries — the sender is best-effort fan-out inside user-facing requests and crons.

---

## 4. New/changed schema — migration `175_web_push_sender.sql`

Guarded MySQL-8-safe DDL only (information_schema + PREPARE/EXECUTE idiom per DEV_NOTES "Portable DDL convention", house examples 037/112/138). Must replay as a no-op. Prefix reservation: `ps_` continues migration 111's naming; no new FKs.

1. **Three additive columns** on `tblPushSubscriptions` (each individually guarded):
   - `failCount TINYINT UNSIGNED NOT NULL DEFAULT 0` — consecutive transient failures
   - `lastFailureAt DATETIME NULL DEFAULT NULL`
   - `lastHttpStatus SMALLINT NULL DEFAULT NULL` — last push-service response code (diagnostics on the admin page)
2. **Settings seeds** (`INSERT … ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)`, 171:86 idiom; all `siteID = NULL` globals unless noted):
   - `api.push.subscribe.enabled = 'true'`, `api.push.unsubscribe.enabled = 'true'` — ApiRouter gating (the trap: without these the relocated handlers 403)
   - `push.ttl.golive = '900'`, `push.ttl.reminder = '3600'` — TTL defaults (a go-live push older than 15 min is stale)
   - `push.golive.auto = 'false'` — cron auto-notify opt-in
   - `push.reminders.broadcast = 'false'` — anonymous "starting soon" broadcast opt-in
   - `push.cron_token = ''`, **isSensitive=1** — empty-fails-closed token for `cron/push-golive` (dedicated token, per the six-of-six convention noted in 171:83-85)
   - `push.endpointHostAllowlist = 'fcm.googleapis.com,push.services.mozilla.com,push.apple.com,notify.windows.com,windows.com,pushsvc.mozilla.com'` — suffix-matched SSRF allowlist, admin-editable so a new browser vendor never requires a release (§7.2)
   - **NOT seeded**: `push.vapidPublicKey`, `push.vapidPrivateKey`, `push.contact`, `push.enabled` — all exist since migration 111
3. **Route seeds** (`tblRoutes`, protected=1): `admin/integrations/push` → `admin/integrations/push/index.php`; `admin/integrations/push/save` → `…/save.php`; `admin/integrations/push/test` → `…/test.php`. Cron route (protected=0, token-gated internally): `cron/push-golive` → `cron/push-golive.php`. **No `api/…` rows** (ApiRouter trap — settings flags are the gate).
4. `INSERT INTO tblMigrations (filename) VALUES ('175_web_push_sender.sql')` idiom as per 171:118.
5. **full_schema.sql fold**: add the three columns to the `tblPushSubscriptions` CREATE block, append the settings + route seeds (schema/seed-parity audit `check_schema_seed_parity.py` enforces this; remember its comment-stripping quirk — never a literal `--` inside a string, 171:31-34).

No new tables: go-live dedupe reuses `tblUserReminderLog` (its documented reserved purpose, 171:20-26); the reminder push dedupe rides the existing `tblEventReminderLog` row (push goes out iff the email batch for that window goes out — one shared single-shot).

---

## 5. `Portal\Core\WebPush` (new: `web/_core/WebPush.php`)

Final class, all-static (house style of `Livestream`/`WebhookDispatcher`). Public surface:

```
isConfigured(): bool
    push.enabled === 'true' AND vapidPublicKey !== '' AND decrypt_setting(vapidPrivateKey) !== '' AND contact !== ''
    (decrypt_setting returns '' on any failure — bootstrap.php:206-239 — so a corrupt key fails INERT, never fatal)

publicKey(): string                         // base64url point for the client; '' when unconfigured

generateKeys(): array{publicKey: string, privateKeyPem: string}
    openssl_pkey_new P-256 → openssl_pkey_export PEM + details→point (§3.1 step 1 mechanics)

importPrivateKey(string $pasted): ?string
    Accepts a PEM, or a 43-char base64url 32-byte scalar d (the web-push-CLI format) reconstructed
    to PEM via RFC 5915 ECPrivateKey DER:
      SEQUENCE{ INTEGER 1, OCTET STRING d(32), [0]{OID prime256v1}, [1]{BIT STRING 0x00‖point} }
    (validated: openssl_pkey_get_private accepts the construction and signs).
    Derives + returns the matching public point; null on garbage.

send(array $sub, string $json, int $ttl, string $urgency = 'normal', ?string $topic = null): int
    $sub = tblPushSubscriptions row. Returns the HTTP status (0 = transport error, -1 = refused
    pre-flight: endpoint failed §7.2 validation or payload > 3900 bytes).
    Pipeline: validateEndpoint → encryptPayload (§3.1) → vapidAuthHeader (§3.2, per-origin cached)
    → POST (§3.3) → recordOutcome (prune/failCount per §3.3).

sendToChannel(int $siteId, string $channel, array $payload, int $ttl, string $urgency,
              ?string $topic = null, ?string $notifyPrefKey = null, ?array $onlyUserIds = null): array
    Fan-out helper both wirings call. Selects
      tblPushSubscriptions WHERE siteID=? AND isActive=1 AND JSON_CONTAINS(channels, '"<channel>"')
      [AND userID IN (…) when $onlyUserIds]
      LEFT JOIN tblUsers for notifyPrefs when $notifyPrefKey set — anonymous rows (userID NULL) always
      pass the prefs check (channels JSON is their only, sufficient, opt-in).
    JSON_CONTAINS is MySQL 8-native; channels column is VARCHAR holding JSON — CAST as needed, or
    fall back to LIKE '%"livestream"%' if the CAST proves fragile on utf8mb4_general_ci (decide at build;
    LIKE is safe here because the whitelist in subscribe.php:84 means values are from a fixed set).
    $payload is ['title'=>…, 'body'=>…, 'url'=>…, 'tag'=>…] → json_encode once, size-check once.
    Hard cap per invocation: 500 subscriptions (ORDER BY subID, LIMIT), 10 s curl budget each is
    theoretical worst-case-slow — but sends are sequential single-threaded; log a platform warning
    when the cap truncates (shared-hosting reality; batch continuation is a v2 concern).
    Returns ['sent'=>n,'failed'=>n,'pruned'=>n].
```

Private: `encryptPayload`, `vapidAuthHeader` (+ static per-request `$jwtCache` keyed by origin), `derToJose`, `p256PointToPem` (DER prefix constant, WebAuthn precedent), `b64url`/`b64urlDecode`, `validateEndpoint` (§7.2), `recordOutcome`.

**Failure discipline**: every public method wraps its body so that a missing table / bad key / curl absence degrades to a logged no-op (`Livestream.php:62-64` try/catch precedent). Push is never allowed to break the page or cron that triggered it. Log via `Logger::activity` with counts only — **never the endpoint URL beyond a 40-char prefix, never p256dh/auth, never any JWT or key material** (subscribe.php:115 already truncates; keep that discipline).

---

## 6. The two channel wirings

### 6.1 "We're live now" (channel `livestream`)

**Primary — manual button (deterministic, host-controlled):**
- `/admin/livestream` (`web/_apps/admin/livestream/index.php`): new POST action `notify_live` in the existing action dispatcher (:36 pattern) + a "📣 Send 'We're live' notification" button rendered only when `WebPush::isConfigured()` AND `Livestream::currentlyLive($siteId) !== null` (grey/disabled with reason otherwise). Handler: CSRF (existing :32), admin gate (existing :20-22), then
  `WebPush::sendToChannel($siteId, 'livestream', ['title' => site name.' is live now', 'body' => channel name, 'url' => '/live', 'tag' => 'golive'], ttl = push.ttl.golive, urgency = 'high', topic = 'golive'.$siteId, notifyPrefKey = 'pushLivestream')`.
  `Topic` means a second press replaces, not stacks, any undelivered copy (RFC 8030 §5.4). Rate-limit the action: `RateLimiter::tooMany('pushgolive:'.$siteId, 3, 900)` — max 3 blasts per 15 min per site.
  Result flash shows sent/failed/pruned counts.
- Host Console (`web/_apps/admin/host-console/event.php`): same button POSTing to the same `/admin/livestream` action with `eventID` — payload `url` becomes `/live?eventID=N` so the chat widget binds (live/index.php:130-141). Small addition; the handler accepts optional `eventID` (validated site-scoped, same probe as live/index.php:132-136).

**Secondary — auto-detect (default OFF, `push.golive.auto`):** new `web/_apps/cron/push-golive.php`, external cadence every 5 min. Token gate: `push.cron_token`, empty ⇒ 403 (171 idiom). Per active site loop (user-reminders.php per-site discipline, settings read inside the loop): if `push.golive.auto === 'true'` and `Livestream::currentlyLive($siteId)` returns channel C, claim a `tblUserReminderLog` row (`refType='push-golive'`, `refID=C.channelID`, `dueDate=today` in the schedule's timezone) — insert-first with errno-1062 race-catch (171 check-first + race-catch discipline) — and on a fresh claim fire the same `sendToChannel` call as the button. The dedupe key means one auto-blast per channel per day; a channel with two windows a day is precisely the case for the manual button (documented in DEV_NOTES).

### 6.2 Service reminders (channel `reminders`)

**Per-RSVP push alongside email** — edit `web/_apps/cron/event-reminders.php`, 1h window only (24h stays email-only; a push a day early is noise):
- The window already computes the event + recipient list once and dedupes via `tblEventReminderLog (eventID, '1h')`. Inside the same guarded block, after the email batch: collect the recipient `userID`s (the RSVP query at :48-52 — extend it to also select `r.userID`), then
  `WebPush::sendToChannel($siteId, 'reminders', ['title' => eventName.' starts soon', 'body' => start time + location, 'url' => '/calendar/event/…', 'tag' => 'evt'.$eventID], ttl = push.ttl.reminder, urgency = 'normal', topic = 'evt'.$eventID, notifyPrefKey = 'pushServiceReminders', onlyUserIds = $rsvpUserIds)`.
- No new dedupe: push shares the email's single-shot claim. If email sending threw before the push line, the log row still exists — acceptable (same failure envelope as today's email path).
- `WebPush::isConfigured()` short-circuits first, so unconfigured installs pay one settings read.

**Broadcast "starting soon"** (default OFF, `push.reminders.broadcast`) — in `cron/push-golive.php` (same file, same site loop): if the next `Livestream::nextScheduled($siteId)` slot starts within 60 min, claim `tblUserReminderLog` (`refType='push-service-1h'`, `refID=channelID`, `dueDate=today`) and broadcast to the whole `reminders` channel (no `onlyUserIds`) — this is the anonymous-viewer service reminder the per-RSVP path can't reach. Kept in the cron (not event-reminders) because it keys off the livestream schedule, not tblEvents.

### 6.3 Per-user prefs + per-site flags

- `notifyPrefs` keys: `pushLivestream`, `pushServiceReminders` — both default-on via the existing missing-key-is-true semantics (user-reminders.php:146-148). Add `$switchRow` entries to `auth/account/notifications.php` (new "Push notifications" card) and both keys to the `$allowedKeys` whitelist in `notifications-save.php:37`. No DDL (JSON column).
- Per-site: `push.enabled` is global; site-scoping of sends is inherent (subscriptions carry `siteID`; both wirings pass `$siteId`). `push.golive.auto` / `push.reminders.broadcast` are read via `App::settingForSite()` inside cron loops so a site override row works (`App.php:166`).
- Per-device: the existing `channels` JSON remains the primary opt-in; the subscribe UI (§6.4) exposes the two channels as checkboxes at subscribe time and re-POSTs to update.

### 6.4 Client side

- **Relocate** `web/_apps/api/push/subscribe.php` → `web/_apps/push/api/subscribe.php` and `unsubscribe.php` alongside (git mv; two-step if case weirdness — Git Notes). Delete the now-empty `_apps/api/push/` dir. Updates while touching them: (a) drop the per-file `header('Content-Type…')`/manual-JSON style *only if* trivially swappable for `ApiResponse::success()` — otherwise leave the working bodies alone (they were written for this exact table); (b) subscribe gains `WebPush::publicKey()`-matching check? No — keep it dumb; (c) add `RateLimiter::tooMany('pushsub:'.$ip, 30, 3600)` to both (authenticated-or-anonymous public POSTs); (d) unsubscribe stays keyed by full endpoint string — endpoints are high-entropy capability URLs, unguessable, so endpoint-knowledge is proof-of-possession (note in header comment).
- **New** `web/public_html/assets/js/push-subscribe.js` (vanilla, nonce'd script include): feature-detect (`'serviceWorker' in navigator && 'PushManager' in window && Notification.permission !== 'denied'`); render state into a `[data-push-optin]` container (used on `/account/notifications` and `/live`); **request permission only on explicit button click** (never on load — browser UX policy and basic decency); `urlBase64ToUint8Array(publicKey)` from the container's `data-vapid-key` attribute (server-rendered from `WebPush::publicKey()`, PUBLIC key only); `registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey})`; POST `subscription.toJSON()` + chosen channels to `/api/push/subscribe` with `X-CSRF-Token` from the `csrf-token` meta (header.php:174); unsubscribe = `subscription.unsubscribe()` then POST endpoint to `/api/push/unsubscribe`.
- **sw.js additions** (bump `CACHE_VERSION`):
  - `push`: `event.waitUntil(...)`; `event.data.json()` in try/catch (a push with an unparseable body shows a generic "New notification" — a push event SHOULD always show something when `userVisibleOnly` was promised); `self.registration.showNotification(title, { body, icon: '/assets/images/icon-192.svg', badge, tag, data: { url } })`.
  - `notificationclick`: `notification.close()`; `event.waitUntil(clients.matchAll({type:'window', includeUncontrolled:true}))` → focus an existing same-origin tab and `navigate(url)`, else `clients.openWindow(url)`. URL is **validated to be same-origin-relative** (`url.startsWith('/')`) before use — the payload is server-authored but defence-in-depth costs one line.
  - `pushsubscriptionchange`: deliberately omitted in v1 (open question Q4).

---

## 7. Security checklist (each item is an acceptance gate)

1. **VAPID private key**: stored via `encrypt_setting()` (sodium secretbox, key file outside webroot — bootstrap.php:179-197) in `push.vapidPrivateKey` (isSensitive=1, already seeded so). **Never** echoed back to any page (save.php blank-preserves like cloudflare-stream/save.php; index.php shows only "configured ✔ / fingerprint `sha256(publicKey)` first 8 hex"); never sent to the client; never logged. Only the PUBLIC key reaches the browser (`data-vapid-key`), which is by design public (RFC 8292 §3.2 `k=`).
2. **SSRF — the endpoint URL is attacker-influenced** (any subscriber controls their `endpoint` string). `validateEndpoint()` enforced at BOTH subscribe-time (reject + 400) and send-time (refuse, return -1): `parse_url` succeeds; scheme `https` exactly; no `user`/`pass`; port absent or 443; host is not an IP literal (`filter_var(…, FILTER_VALIDATE_IP)` false) and not `localhost`/`*.local`/`*.internal`; host suffix-matches the admin-editable `push.endpointHostAllowlist` (dot-boundary suffix match: host === entry or ends with `'.'.entry`). Plus transport hardening: `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_MAXREDIRS => 0`, `CURLOPT_PROTOCOLS/REDIR_PROTOCOLS => CURLPROTO_HTTPS` — a redirect answer is treated as failure, never followed (WebhookDispatcher:288 precedent, tightened). DNS-rebinding is out of scope given the https+allowlist gate.
3. **Subscription endpoints**: CSRF-verified (already), rate-limited (new, §6.4), gated on `push.enabled` (already) and now actually reachable + flag-gated by ApiRouter. Anonymous subscribe remains allowed by design (issue requirement: anonymous viewers get "we're live"); the rate limit + endpoint validation bound the abuse surface (worst case: attacker registers their own valid push endpoints = they spam themselves).
4. **No enumeration**: unsubscribe/subscribe respond identically whether or not the endpoint existed (both already do — unsubscribe.php:54-64 returns `ok` regardless; keep). Subscription listing appears ONLY on the admin page as per-channel counts, never as endpoint lists.
5. **Payloads contain no secrets**: title/body/url only — public-lobby-shoutable content ("X is live", "Y starts at 10:30"). Push payload encryption (RFC 8291) protects transport, but payloads sit readable on the device lockscreen: nothing personal, no tokens, no names of individuals beyond public event/channel names. Reminder pushes carry the event name only — same information as the public calendar.
6. **Dead-endpoint pruning**: 404/410 ⇒ `isActive=0` + `lastHttpStatus` immediately (RFC 8030 §7.3); transient-failure ladder `failCount >= 8` ⇒ deactivate. `lastUsedAt` refreshed on 201. Admin page surfaces active/pruned counts.
7. **Key/token logging discipline**: `Logger::activity` messages carry counts + truncated endpoint prefixes only; the VAPID JWT, CEK, ECDH secret, auth secret, p256dh never appear in logs, errors, or exceptions (catch-and-summarise around the crypto block).
8. **GDPR/offboarding**: add `tblPushSubscriptions` to `GdprEraser::catalogue()` (hard DELETE by userID — endpoint+keys are erasable credentials, not history; follows the PR-#372-session auth-residue precedent of tblTrustedDevices) and to `offboarding/do.php` revocation (DELETE by userID — offboarding's whole point is credential revocation).
9. **Crypto hygiene**: fresh ephemeral ECDH pair per message (never reuse `as_*` across messages — RFC 8291 §3.1); `random_bytes` for salt; ECDH output length asserted = 32; `openssl_*` failures abort the single send, never the fan-out; the VAPID pair is used ONLY for JWT signing, the ephemeral pair ONLY for ECDH (two different keys with two different jobs — conflating them is a known implementer error worth a comment).
10. **Cron endpoints**: `push.cron_token` empty-fails-closed 403 (`hash_equals`); no site-enumeration in output (counts per site only).
11. **CSP untouched**: subscribe traffic is same-origin (`connect-src 'self'`, header.php:158); push-service POSTs are server-side. No `$cspConnectExtra` usage, no worker-src addition (SW is same-origin, covered by `script-src 'self'`).

---

## 8. File list

**New (10):**
| File | Purpose |
| --- | --- |
| `web/_core/WebPush.php` | Sender class (§5) — the only file containing Web Push crypto |
| `web/_apps/push/api/subscribe.php` | Relocated from `_apps/api/push/subscribe.php` (git mv + §6.4 tweaks) |
| `web/_apps/push/api/unsubscribe.php` | Relocated likewise |
| `web/_apps/admin/integrations/push/index.php` | Config page: inert banner, key status/fingerprint, sub counts per channel, prune stats, settings form, test button |
| `web/_apps/admin/integrations/push/save.php` | CSRF'd POST: generate-keys action / paste-import (PEM or base64url d via `importPrivateKey`) / contact / enable / TTLs / auto toggles / allowlist. Private key via `encrypt_setting`, blank-preserves |
| `web/_apps/admin/integrations/push/test.php` | CSRF'd POST: test push to the CURRENT admin's own subscriptions only (`onlyUserIds=[me]`) — the documented manual-test hook |
| `web/_apps/cron/push-golive.php` | Token-gated 5-min sweep: auto go-live + broadcast starting-soon (§6.1/§6.2), tblUserReminderLog dedupe |
| `web/public_html/assets/js/push-subscribe.js` | Subscribe/unsubscribe UI logic (§6.4) |
| `web/_sql/175_web_push_sender.sql` | §4 |
| *(deleted dir)* `web/_apps/api/push/` | Removed by the relocation |

**Edited (9):** `web/public_html/sw.js` (push/notificationclick, CACHE_VERSION bump) · `web/_apps/cron/event-reminders.php` (1h push fan-out) · `web/_apps/admin/livestream/index.php` (notify_live action + button) · `web/_apps/admin/host-console/event.php` (button) · `web/_apps/live/index.php` (viewer opt-in bell) · `web/_apps/auth/account/notifications.php` + `notifications-save.php` (2 prefs keys + device panel) · `web/_core/GdprEraser.php` · `web/_apps/offboarding/do.php` · plus `web/_sql/full_schema.sql` (fold) and docs (`CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md` → new "Web Push setup" operator section: generate-or-paste keys, cron URLs + cadence, the openssl CLI alternative `openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem` with the caveat that the *paste* box accepts that PEM directly, troubleshooting 401-from-push-service = clock skew or DER-sig bug).

House conventions checklist for every file: `declare(strict_types=1)`; full-IF (`=== true`); prepared statements with **bind_param arity re-counted after every SQL edit** (`check_bind_param_arity.py` gates); `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on all output; file headers (path/description/package/author/copyright All Rights Reserved/version/@link #322); emoji section comments; `DIRECTORY_SEPARATOR` paths; no `<table>` (admin counts use `portal-data-list`); CSRF on every state-changing POST; site-scoping on every query (`siteID = ?` from `Site::id()` or loop variable); no AppRegistry entry (push is infrastructure like `widget/`/`cron/` — the `/admin/apps` marketplace is not where an ops feature lives; gating is `push.enabled`).

---

## 9. Acceptance gates

1. `php -l` zero-error on every touched file (PHP 8.4 + 8.5 if available).
2. All **11** audit checks green (`tools/audit-checks/`): notably `check_settings_keys.py` (every `push.*`/`api.push.*` read must trace to a seed — the §4 seeds cover each read), `check_route_targets.py` (3 admin routes + 1 cron route → files exist), `check_mariadb_only_ddl.py` + `check_migration_idempotency.py` (guarded ALTERs), `check_schema_seed_parity.py` (full_schema fold matches 175), `check_sql_columns.py` (the new columns referenced in PHP exist in schema), `check_php_table_refs.py` (tblPushSubscriptions/tblUserReminderLog spelled right), `check_bind_param_arity.py`.
3. Migration replay: 175 applied twice = no-op; fresh-install path (full_schema then all numbered) = no-op on 175's ALTERs (columns already in the folded CREATE).
4. **Crypto self-check**: temporary harness (not committed, or committed under `tools/`— see Q5) executes the §3.1 pipeline against a locally generated "browser" keypair and asserts round-trip decryption + `openssl_verify` of the JWT — i.e. the PoC that validated this plan, re-run against the real class.
5. **Documented manual test** (DEV_NOTES): (a) `/admin/integrations/push` → Generate keys → set contact → enable; page banner flips from inert-amber to configured-green; (b) `/account/notifications` in Chrome/Firefox → "Enable on this device" → permission grant → row appears in `tblPushSubscriptions` with correct siteID/userID/channels; (c) `/admin/integrations/push` → "Send test" → notification arrives on that device, clicking focuses/opens the target URL; (d) `/admin/livestream` with an in-window schedule → "Send 'We're live'" → notification on a second (anonymous-subscribed) browser; `Topic` re-press replaces; (e) prune: subscribe then remove site notification permission in browser settings, send again → push service returns 410 → row flips `isActive=0`, `lastHttpStatus=410`, admin counts update; (f) 1h event-reminder window (adjust a test event's start) → email AND push arrive for an RSVP'd user; user with `pushServiceReminders` off gets email only.
6. PR Security checks bot comment clean after push (standing instruction), CodeQL/Psalm no new findings.

---

## 10. Open questions (each with a RECOMMENDED default)

1. **Should the broadcast "service starting soon" ship in v1, or per-RSVP only?** It adds the anonymous-viewer reach the issue implies but doubles the go-live cron's logic. **RECOMMENDED: ship it, default OFF (`push.reminders.broadcast='false'`)** — the code is ~40 lines inside a cron that exists anyway, and off-by-default costs nobody anything.
2. **`announcements` channel** — the schema whitelists it (subscribe.php:84) but #322 names only live + reminders. **RECOMMENDED: leave it registered in the whitelist but wire no sender** (one sentence in DEV_NOTES: "reserved; a future announcements-publish hook can call `sendToChannel(…, 'announcements', …)` with zero schema work" — the Workflow announcement-publish consumer from #443 is the natural future caller).
3. **Auto-go-live dedupe granularity** — once per channel per *day* (recommended, `tblUserReminderLog` dueDate key) vs once per schedule *window* (needs window identity in the key). **RECOMMENDED: per-day**, with the manual button as the escape hatch for multi-service days; revisit only if a real site runs two auto-notified streams a day.
4. **`pushsubscriptionchange` SW handler** — proper handling needs an authenticated server call from a worker with no session/CSRF context (would require a per-subscription bearer token column). Browsers fire it rarely (Chrome effectively never). **RECOMMENDED: omit in v1**; the stale endpoint self-heals via 410-prune + the client re-subscribing on next portal visit. Note it in the class header as the known v2 item (with the token-column sketch).
5. **Where does the crypto self-test live?** Options: none (manual test only), a `tools/` PHP script run in CI, or a hidden admin "self-test" button. **RECOMMENDED: commit `tools/webpush-selftest.php`** (repo root tools/, not deployed — mirrors the audit-check placement) and wire it into CI next to the audit checks; it needs no DB and catches any future refactor that breaks DER→JOSE or the HKDF info strings — the two silent-401 classes of bug.
6. **Payload icon** — brand icon paths are per-brand (`manifest.php:65-70`). **RECOMMENDED: send `icon: '/assets/images/icon-192.svg'` fallback path in v1** (SVG works in Chromium; Safari ignores icons) and let sw.js pass it through; brand-aware icon resolution is cosmetic polish for later.

---

## 11. Build order (suggested commits)

1. `feat(core): Portal\Core\WebPush — VAPID ES256 + RFC 8291 aes128gcm sender` (+ selftest tool) — the class alone, no callers.
2. `fix(push #322): relocate subscribe/unsubscribe to ApiRouter convention path + enable flags + rate limits` — migration 175 lands here (whole of §4).
3. `feat(push): client subscribe UI + sw.js push/notificationclick handlers`.
4. `feat(admin): /admin/integrations/push config + test-send`.
5. `feat(push): go-live wiring — manual button + auto cron`.
6. `feat(push): service-reminder wiring in event-reminders + prefs keys`.
7. `chore(privacy): GdprEraser + offboarding cover tblPushSubscriptions`.
8. Docs + full_schema fold verification + CHANGELOG/FEATURES.

Each commit `php -l` + audit-green independently (house standing instruction #2).
