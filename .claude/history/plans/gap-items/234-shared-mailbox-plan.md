# Implementation Plan — #234: MS365 Graph email sending via delegate permission on a shared mailbox

**Status:** build-ready plan (no code) · **Branch base:** `alpha` @ `0201e60` · **Date:** 2026-08-28
**Issue:** [#234](https://github.com/MWBMPartners/WebMS-Intra/issues/234) (open, `type: feature`, `priority: high`, `scope: core`)

---

## 0. Executive summary

The portal **already sends every email app-only through Microsoft Graph as a shared mailbox**: `Mailer::sendViaGraph()` acquires a client-credentials token and POSTs to `/users/{mail.defaultFromAddress}/sendMail`. What #234 actually still needs is not a new auth model — it is the **formalisation and hardening of the shared-mailbox path**: an explicit, admin-configured shared-mailbox identity separate from the general from-address, an explicit `from` (with display name) in the Graph payload, the 401/429/5xx error-handling + optional fallback the issue specifies, the `tblEmailLog` audit trail, the admin UI/test flow, and the documented owner runbook (application `Mail.Send` + admin consent + mailbox-scoping policy).

**Chosen model: app-only (application permission `Mail.Send`) via `POST /users/{sharedMailbox}/sendMail`.** The delegated `Mail.Send.Shared` refresh-token mode named in the issue body is **explicitly deferred** (see §12/§13) — it adds an entire OAuth authorize/refresh surface, a new encrypted secret lifecycle, and a licensed "delegate" user whose password/MFA/conditional-access events silently kill mail, while buying nothing the app-only model + an Application Access Policy doesn't already provide. This recommendation should be recorded on the issue thread at build time (the 2026-07-21 re-scope comment framed the residue as "delegated mode only"; this plan proposes closing #234's *acceptance criteria* the least-fragile way and recording why).

Everything ships inert: with the new `mail.ms365.sharedMailbox` setting empty (the seeded default), byte-for-byte today's behaviour is preserved.

---

## 1. Current state — evidence (file:line)

### 1.1 Provider selection and dispatch

- `web/_core/Mailer.php:89` — `$provider = strtolower($SETTINGS['mail']['provider'] ?? 'ms365');` — `'google'` → `MailerGoogle::send()` (line 92), everything else → `sendViaGraph()` (line 96). Default provider is `ms365` (seeded at `web/_sql/full_schema.sql:1633`: `('mail.provider', 'ms365', 0, NULL)`).
- 24 files call `Mailer::send`/`sendTemplated` (grep across `web/_apps` + `web/_core`); none passes a from-address — sender identity is entirely settings-derived. `web/_core/Logger.php:358` (critical alerting #229) and `web/_core/ExpenseMailer.php:48` are representative callers.

### 1.2 MS365 Graph auth — app-only client credentials, already in place

- `web/_core/Mailer.php:351-403` — `accessToken()`: `grant_type=client_credentials`, `scope=https://graph.microsoft.com/.default`, against `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, with a per-request static token cache (`Mailer.php:46-47`, 60-s early-expiry guard at line 353). **There is no delegated/refresh-token flow anywhere in the codebase** (confirmed by the issue's own 2026-07-21 re-scope comment and by grep: no `refresh_token` grant in `web/`).
- Credential settings (read at `Mailer.php:359-361`):
  - `auth.ms365.appwide.clientID`
  - `auth.ms365.appwide.clientSecret` (stored `isSensitive=1` → libsodium-encrypted at rest; decrypt on load at `web/_core/bootstrap.php:368-370`; crypto helpers at `bootstrap.php:171-240`, key file `web/_auth_keys/enc.key`)
  - `auth.ms365.tenantID`

### 1.3 The sendMail call and from/sender handling today

- `web/_core/Mailer.php:228` — the sending identity is `mail.defaultFromAddress` (seeded empty at `full_schema.sql:1617`; labelled **"From Address (Shared Mailbox)"** in the admin UI at `web/_apps/admin/integrations/index.php:139`).
- `web/_core/Mailer.php:247` — endpoint: `https://graph.microsoft.com/v1.0/users/' . urlencode($fromAddr) . '/sendMail` — i.e. already the `/users/{mailbox}/sendMail` app-only form, **not** `/me/sendMail`.
- `web/_core/Mailer.php:234-244` — the JSON body contains **no `from` or `sender` object at all** (subject/body/toRecipients/attachments only) — Graph derives the sender from the URL mailbox, and the display name shown to recipients is whatever the mailbox's own display name is. `mail.defaultFromName` (seeded `full_schema.sql:1613`) is **unused in the real MS365 send path** — only the Google path (`web/_core/MailerGoogle.php:64,149`) and the test email's HTML body use it.
- Error handling today: non-2xx → one `Logger::errorPlatform` line and `return false` (`Mailer.php:271-276`). No 401-retry, no 429 backoff, no fallback, no persisted log.

### 1.4 Google provider (contrast)

- `web/_core/MailerGoogle.php:59-119` — service-account JWT (domain-wide delegation), sends via `gmail/v1/users/{mail.google.delegateUser}/messages/send` with an RFC 2822 MIME message whose `From:` header it builds itself (`MailerGoogle.php:149`) from `delegateUser` + `mail.defaultFromName`. i.e. the Google path already implements "send as a designated org identity with a display name" — the MS365 path is the one missing the explicit identity object.

### 1.5 Admin surface today

- `web/_apps/admin/integrations/index.php:134-141` — MS365 Graph config status card (masked secrets, lines 148-158).
- `index.php:336-362` — "Token Acquisition Test" button (client-credentials probe, handler at 653-726).
- `index.php:366-414` + `sendTestEmail()` at `index.php:738-…` — "MS365 Graph API — Send Test Email" card, which **duplicates** the token+sendMail flow inline (token: 758-792; body proclaims "client_credentials + /users/{mailbox}/sendMail", line 816) instead of calling `Mailer::send()`.
- `web/_apps/admin/integrations/email.php` (#230 deliverability page) — **reads a dead settings vocabulary**: `email.provider` / `email.from` (`email.php:35-36`; seeded but consumed nowhere else — `full_schema.sql:2921-2922`), so it reports "smtp" while the portal actually sends via Graph. Test-send at `email.php:56-58` correctly goes through `Mailer::send()`. SPF/DKIM/DMARC DNS probe at 73-134.
- Per-app settings save handlers with encrypt-on-save + blank-preserves-existing pattern: `web/_apps/admin/integrations/cloudflare-stream/save.php:113-114`, `web/_apps/admin/sms/save.php:30-31`, etc. — the house pattern the new save handler follows.

### 1.6 Missing pieces vs #234's acceptance criteria

- **No `tblEmailLog`** anywhere (grep of `web/` — zero hits).
- No 401-refresh / 429-backoff / 5xx-fallback.
- No documented Azure app-registration runbook in `web/_apps/help/`.
- No admin-editable shared-mailbox field (settings only editable via the generic `/admin/settings` editor).

---

## 2. Graph mechanics — the two candidate models

### Model A — app-only, application permission `Mail.Send` (RECOMMENDED — and already what the code does)

- `POST https://graph.microsoft.com/v1.0/users/{sharedMailboxUPN|id}/sendMail` with an app-only token. Application `Mail.Send` lets the app send **as any mailbox in the tenant** — shared mailboxes included; no Exchange "Send As" grant on the mailbox is needed in this model, and the shared mailbox needs **no licence**.
- Because that is deliberately over-broad, Microsoft's mitigation is scoping the app to specific mailboxes: an **Application Access Policy** (`New-ApplicationAccessPolicy -PolicyScopeGroupId <mail-enabled security group> -AccessRight RestrictAccess`) or its successor, **RBAC for Applications** in Exchange Online (Application Access Policies are now flagged legacy; both must appear in the owner runbook).
- Owner setup delta from where this portal already is: *possibly nothing* — if MS365 mail sending already works in production, application `Mail.Send` is already consented; the owner only (a) creates/identifies the shared mailbox and (b) optionally adds the scoping policy.

### Model B — delegated, `Mail.Send.Shared` (issue-body proposal — DEFERRED)

- A **user** token (auth-code + `offline_access` refresh token) for a real licensed account that has Exchange **Send As** (→ set `from`) or **Send on Behalf** (→ set `sender`) permission on the shared mailbox; send via `/me/sendMail` (or the shared mailbox's own `/users/{shared}/sendMail` submission URL if the copy should land in the shared mailbox's Sent Items) with `from` set to the shared mailbox.
- Cost: a new interactive OAuth authorize flow + callback route, an encrypted refresh-token row + rotation handling, and an availability dependency on a human account (password reset, MFA registration change, conditional-access policy, offboarding of that person → portal mail silently dies — the exact failure class #227-era offboarding exists to cause). This is the model with the **most** new secret-handling and the **least** reuse of `accessToken()`.

**Decision: Model A.** It reuses `Mailer::accessToken()` verbatim, introduces **zero new secrets**, and the "broadness" objection is answered in the runbook by the scoping policy. Model B remains documented as a rejected alternative (§13 Q1).

Sources: [user: sendMail — Microsoft Graph](https://learn.microsoft.com/en-us/graph/api/user-sendmail), [Send Outlook messages from another user](https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user), [Application Access Policies (legacy)](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-access-policies), [Mail.Send permission reference](https://graphpermissions.merill.net/permission/Mail.Send), [Sending from a shared mailbox via Graph (endpoint choice / Sent Items)](https://github.com/gscales/Graph-Powershell-101-Binder/blob/master/Shared-Mailboxes/Sending%20a%20Mail%20from%20a%20Shared%20Mailbox.md), [RBAC for Applications replacing AAP](https://office365itpros.com/2026/02/17/mail-send-rbac-for-applications/). (learn.microsoft.com was egress-blocked in this session; facts cross-checked via the mirrors above — re-verify the two learn.microsoft.com pages at build time.)

---

## 3. Design — `Mailer` changes (minimal, inert-by-default)

### 3.1 Effective-sender resolution (new private helper `effectiveSender(): array`)

Resolution order inside `sendViaGraph()` (replacing the bare `Mailer.php:228` read):

1. `mail.ms365.sharedMailbox` — if non-empty **and** passes `FILTER_VALIDATE_EMAIL` → this is both the URL mailbox and the `from` address. Display name: `mail.ms365.sharedMailboxName`, falling back to `mail.defaultFromName`.
2. If `mail.ms365.sharedMailbox` is non-empty but **invalid** (hand-edited via the generic `/admin/settings` editor): `Logger::errorPlatform('Graph','Error','BAD_SHARED_MAILBOX',…)` once, then fall back to rule 3 so portal mail keeps flowing.
3. Empty (seeded default) → `mail.defaultFromAddress` exactly as today (`RuntimeException('From address missing')` when that is empty too, unchanged) — **the feature is inert until configured**.

The provider string recorded in `tblEmailLog` distinguishes the modes: `ms365` vs `ms365-shared` (satisfies the issue's "provider" logging criterion and gives the admin UI its status badge). No change to `Mailer::send()`'s public signature; `mail.provider` keeps its exact current vocabulary (`ms365` | `google`) — the shared-mailbox path is a **sub-mode of `ms365`**, keyed purely off the new setting, not a third provider value (introducing `ms365_graph` as the issue body sketched would break the default-path dispatch at `Mailer.php:89-96` for zero benefit).

### 3.2 Exact Graph request shape

```
POST https://graph.microsoft.com/v1.0/users/{urlencode(sharedMailbox)}/sendMail
Authorization: Bearer {client-credentials token — accessToken(), unchanged}
Content-Type: application/json

{
  "message": {
    "subject": "…",
    "body": { "contentType": "HTML", "content": "…" },
    "from": {
      "emailAddress": {
        "address": "office@church.org",          // effectiveSender address
        "name": "Church Office"                   // sharedMailboxName ?? defaultFromName; omit "name" key when blank
      }
    },
    "toRecipients": [ { "emailAddress": { "address": "…" } }, … ],
    "attachments": [ { "@odata.type": "#microsoft.graph.fileAttachment", … } ]   // attach() unchanged, Mailer.php:323-338
  },
  "saveToSentItems": true          // from mail.ms365.saveToSentItems ('true' default) — keeps an audit copy in the shared mailbox's Sent Items
}
```

Notes: URL mailbox and `from.address` are always the **same admin-configured value** — never diverging, never request-derived (see §9). Posting to the shared mailbox's own `/users/{shared}/sendMail` (rather than some other user + `from` override) is what makes `saveToSentItems` land the copy in the *shared* mailbox. `sender` is intentionally not set — app-only send-as does not use it (it's the send-on-behalf field for the deferred delegated model). The `from` object is added in **both** modes (rule-3 fallback uses `defaultFromAddress` + `defaultFromName`) — this is the one visible improvement to the legacy path: `mail.defaultFromName` finally takes effect for MS365, matching what the Google path has always done (`MailerGoogle.php:149`). Flag this in the CHANGELOG as a deliberate, benign behaviour change.

### 3.3 Error handling (replacing `Mailer.php:268-276`)

| Condition | Action |
| --- | --- |
| 2xx (Graph returns **202** for sendMail) | log `sent` row to `tblEmailLog`, return true |
| **401** (expired/revoked token) | clear the static token cache (`self::$token = ''`), re-acquire once, retry the send **once**; second 401 → fail path |
| **403** `ErrorAccessDenied` / **404** `ErrorInvalidUser`/`ErrorItemNotFound` (mailbox outside the Application Access Policy scope, mailbox deleted, or consent missing) | fail; log with the parsed Graph `error.code` so the admin UI can show the targeted remedial hint ("check Mail.Send admin consent / Application Access Policy membership / mailbox address") rather than a bare HTTP code |
| **429** | read `Retry-After`; if ≤ 5 s, sleep-and-retry **once** (bounded — this runs inside a web request on shared hosting); else fail path. Never loop. |
| **5xx / cURL failure** | fail path |
| Fail path | log `failed` row (httpCode + Graph `error.code` + truncated `error.message`, never the token/secret), then: if `mail.fallbackProvider` = `'google'` **and** the Google provider is configured → one attempt via `MailerGoogle::send()` (logged as its own `tblEmailLog` row, provider `google`, so the audit trail shows the failover); otherwise `return false` exactly as today. Callers already handle false (e.g. `email.php:67` "Mailer returned false."). |

`mail.fallbackProvider` seeds **empty = off** (recommended default): silent failover changes the visible sending identity and can break DMARC alignment — an admin must opt in. There is no SMTP provider in this codebase (the issue's "SMTP" fallback referred to the dead `email.provider='smtp'` vocabulary — see §7), so `google` is the only legal fallback value; validate on save.

### 3.4 `tblEmailLog` write (new private helper `logSend(...)`)

Written on every attempt (both providers — one-line calls added to the Google path's success/failure branches at `MailerGoogle.php:113-118`), prepared statements, and **fail-soft**: a logging exception must never break mail sending (wrap in try/catch, `error_log` on failure). Opportunistic retention prune (DELETE of rows older than `mail.log.retentionDays`, default 90) piggybacked on ~1-in-50 writes (`random_int`), so no new cron endpoint is needed.

---

## 4. New table — `tblEmailLog` (migration 176)

| Column | Type | Notes |
| --- | --- | --- |
| `emailLogID` | INT AUTO_INCREMENT PK | |
| `siteID` | INT NULL | `Site::id()` at send time; NULL when sent outside site context (cron) |
| `provider` | VARCHAR(20) NOT NULL | `ms365` \| `ms365-shared` \| `google` |
| `fromAddress` | VARCHAR(255) NOT NULL DEFAULT '' | effective sender |
| `toRecipients` | TEXT NOT NULL | comma-joined recipient list |
| `subject` | VARCHAR(500) NOT NULL DEFAULT '' | truncated |
| `attachmentCount` | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| `status` | ENUM('sent','failed') NOT NULL | |
| `httpCode` | SMALLINT NULL | |
| `errorCode` | VARCHAR(100) NOT NULL DEFAULT '' | Graph `error.code` (e.g. `ErrorAccessDenied`) |
| `errorDetail` | VARCHAR(500) NOT NULL DEFAULT '' | truncated `error.message`; **never** body/token/secret material |
| `sentAt` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| index | `KEY idx_emaillog_site_sent (siteID, sentAt)` | powers the admin "recent sends" list + prune |

utf8mb4, InnoDB, created with `CREATE TABLE IF NOT EXISTS` (standard MySQL 8, no guard idiom needed). This is also the #230 dependency the issue's first comment names.

---

## 5. Settings (all seeded in migration 176; none is a secret — no new `isSensitive=1` rows)

| Key | Default | Sensitive | Purpose |
| --- | --- | --- | --- |
| `mail.ms365.sharedMailbox` | `''` | no | The shared-mailbox address to send through. **Empty = feature off (today's behaviour).** Validated `FILTER_VALIDATE_EMAIL` on save and on use. |
| `mail.ms365.sharedMailboxName` | `''` | no | Display name for the `from` object; empty → falls back to `mail.defaultFromName`. |
| `mail.ms365.saveToSentItems` | `'true'` | no | Keep a copy in the shared mailbox's Sent Items. |
| `mail.fallbackProvider` | `''` | no | `''` (fail loudly, default) or `'google'`. |
| `mail.log.retentionDays` | `'90'` | no | `tblEmailLog` prune horizon. |

Existing keys reused untouched: `auth.ms365.appwide.clientID`/`.clientSecret` (already encrypted), `auth.ms365.tenantID`, `mail.defaultFromAddress`, `mail.defaultFromName`, `mail.provider`. `check_settings_keys.py` (CI) requires every key read in PHP to be seeded — all five new keys are in both migration 176 **and** the `full_schema.sql` fold.

---

## 6. Admin UI

### 6.1 `/admin/integrations` (edit `web/_apps/admin/integrations/index.php`)

Extend the existing "MS365 Graph API" card (`index.php:366-414` area) with a **"Shared-mailbox sending"** sub-section:

- Status rows in the existing card style (`index.php:143-164` pattern): shared mailbox address (non-secret, shown in full), display name, save-to-sent, fallback provider, mode badge — "Shared mailbox (`ms365-shared`)" vs "Direct (`mail.defaultFromAddress`)".
- A small CSRF'd edit form for the four values POSTing to a **new** `ms365-mail-save.php` (house pattern: `cloudflare-stream/save.php` — admin gate, CSRF verify, per-key whitelist, `FILTER_VALIDATE_EMAIL` on the mailbox, whitelist `''|'google'` on fallback, `'true'|'false'` on saveToSentItems; no encrypt branch needed since nothing is sensitive). Redirect back with flash.
- Rework `sendTestEmail()` (`index.php:738-…`) to call `\Portal\Core\Mailer::send()` instead of its duplicated inline token+curl flow, so the test exercises the **real** path incl. the new from/sender shape, error mapping and `tblEmailLog` write; surface the newest `tblEmailLog` row (httpCode/errorCode) as the diagnostic detail. This closes the drift risk between test and production paths permanently. Keep the separate token-acquisition test button as-is.
- "Last send" indicator (issue's status-indicator ask): newest `tblEmailLog` row's status + timestamp, shown in the card.

### 6.2 `/admin/integrations/email` (edit `web/_apps/admin/integrations/email.php`) — fold-in fix

- Replace the dead `email.provider`/`email.from` reads (`email.php:35-36`) with `Mailer::provider()` + the effective sender (shared mailbox ?? `mail.defaultFromAddress`) so the deliverability page finally reports the truth; point its DNS probe's sender-domain at the effective sender (SPF/DKIM/DMARC then get checked against the domain mail actually leaves from — for Graph sending that's the M365 tenant's domain, which passes by construction; keep the probe, reword the hint).
- Add a "Recent sends (last 10)" list from `tblEmailLog` using the **`portal-data-list` component** (house rule: no `<table>` for data display — note the page's existing DNS `<table>` at `email.php:197` predates the rule; do not add another).
- Leave the two dead `email.*` seed rows in place (harmless; removing seeds needs a cleanup migration — out of scope, note in DEV_NOTES).

### 6.3 Help (edit `web/_apps/help/admin.php`)

New section "Sending email as a shared mailbox (Microsoft 365)" containing the owner runbook below. No new route needed.

---

## 7. Owner runbook (goes into help/admin.php + DEV_NOTES.md)

1. **Create/identify the shared mailbox** in the M365 admin centre (e.g. `office@church.org`). No licence needed. (No Exchange "Send As" delegate grant is required for the app-only model.)
2. **App registration** — reuse the existing app-wide registration already configured for portal mail (`auth.ms365.appwide.clientID`): in Entra ID → App registrations → API permissions, confirm **Application** permission `Microsoft Graph → Mail.Send` is present with **admin consent granted**. (If portal email already works today, this is already done — nothing to change.)
3. **Recommended: scope the permission.** Either (legacy but still functional) `New-ApplicationAccessPolicy -AppId <clientID> -PolicyScopeGroupId <mail-enabled security group containing the shared mailbox> -AccessRight RestrictAccess -Description "WebMS Intra mail"`, then `Test-ApplicationAccessPolicy` to verify; or the successor **RBAC for Applications** in Exchange Online (management-scope–restricted `Mail.Send` role assignment). Without this, the app token can send as any tenant mailbox.
4. **Portal config** — `/admin/integrations` → MS365 Graph card → set Shared mailbox + display name → Save → **Send Test Email**. Confirm receipt, the `ms365-shared` badge, and (if enabled) the copy in the shared mailbox's Sent Items.
5. **Deliverability note** — mail now leaves Microsoft's infrastructure, so the org domain's standard M365 SPF (`include:spf.protection.outlook.com`), DKIM (selector1/selector2 CNAMEs enabled in Defender portal) and DMARC records make it align; the DreamHost server's IP reputation is no longer involved. The `/admin/integrations/email` DNS probe verifies this.

---

## 8. Migration — `web/_sql/176_ms365_shared_mailbox.sql`

- **Number 176**: local `web/_sql/` on `alpha` tops at `174_workflow_engine.sql`; **175 is reserved by the in-flight webpush branch** (not yet visible in `git ls-remote --heads origin`, which currently shows only `venue-b…f` topping at 170 — trust the reservation). **Re-confirm both at build time** (`ls web/_sql/ | tail` + `git ls-remote --heads origin` + a look at open PRs) before naming the file.
- Contents (in the migration-167 house style — see `web/_sql/167_paypal_checkout.sql:55-81` for the exact idioms):
  1. `CREATE TABLE IF NOT EXISTS tblEmailLog (…)` per §4 (standard MySQL-8 syntax; no MariaDB-only `IF EXISTS` variants anywhere — `check_mariadb_only_ddl.py`).
  2. Five settings seeds via `INSERT … VALUES (NULL, key, default, default, 0) ON DUPLICATE KEY UPDATE settingKey = settingKey`.
  3. One route seed: `('admin/integrations/ms365-mail-save', 'admin/integrations/ms365-mail-save.php', 1)` with `ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)` (`check_route_targets.py` requires the handler file to exist in the same PR).
  4. Idempotent self-record into `tblMigrations`.
- **Replay-safe as a no-op** on an up-to-date schema (installer replays every migration after `full_schema.sql`) — all statements above are idempotent by construction; `check_migration_idempotency.py` + the e2e-migrations harness enforce.
- **`full_schema.sql` fold**: table DDL + the five setting seeds + the route row added to the schema file in the same commit (`check_schema_seed_parity.py`).

---

## 9. Security checklist

- [ ] **Sender identity is never request-derived.** `Mailer::send()`'s signature is unchanged (no from parameter); the shared mailbox comes only from `tblSettings` written by an admin-gated, CSRF-verified save handler. Grep-verify at build that no caller path feeds user input into `effectiveSender()`.
- [ ] **No new secrets.** The only credential is the existing `auth.ms365.appwide.clientSecret`, already `isSensitive=1`/libsodium-encrypted (`bootstrap.php:179-197`). The shared-mailbox address is deliberately non-sensitive (it's a public email address) and displayed unmasked for verification, matching `mail.defaultFromAddress` handling (`index.php:153`).
- [ ] **No secret/token ever logged.** `tblEmailLog.errorDetail` is built only from Graph's `error.code`/`error.message` (truncated 500); the Authorization header and token response are never persisted; keep the existing masking in the admin card.
- [ ] **Validation**: `FILTER_VALIDATE_EMAIL` on save AND on use (hand-edits via the generic settings editor bypass the save handler); invalid-at-use falls back + logs `BAD_SHARED_MAILBOX` (§3.1).
- [ ] **Save handler**: `Auth::ensureSession()` + `Auth::requireLogin()` + `App::isAdmin()` 403 gate + `Auth::verifyCsrf` — copy `cloudflare-stream/save.php` structure; prepared statements only.
- [ ] **Least privilege documented**: runbook step 3 (Application Access Policy / RBAC for Applications) so application `Mail.Send` cannot be abused to send as arbitrary tenant mailboxes even if the portal DB is compromised — and note in DEV_NOTES that the *portal* enforces its own single-identity discipline regardless.
- [ ] **SPF/DKIM/DMARC**: alignment note in help + deliverability page hint reworded (§6.2, §7). Fallback-to-Google off by default partly for this reason (§3.3).
- [ ] **GDPR**: `tblEmailLog` holds recipient addresses → (a) retention-pruned (default 90 days, §3.4); (b) add a bespoke step in `web/_core/GdprEraser.php` blanking `toRecipients` rows containing an erased user's address (the catalogue at `GdprEraser.php:45-…` is `userCol`-keyed and doesn't fit a comma-joined column — precedent for bespoke steps: the statement-PDF unlink added for #440); (c) `check_php_table_refs.py` passes because the table ships in `full_schema.sql`.
- [ ] **429 handling is bounded** (one retry, ≤ 5 s) — no unbounded sleep loops inside web requests on shared hosting.

---

## 10. File list (10 files + docs)

| File | Change |
| --- | --- |
| `web/_core/Mailer.php` | `effectiveSender()`, from/sender in payload, 401-retry-once, 429 bounded retry, Graph error-code parse, fallback hook, `logSend()` + prune; version bump 0.9.0 → 1.0.0 |
| `web/_core/MailerGoogle.php` | two one-line `Mailer::logSend()` calls (success/failure) |
| `web/_core/GdprEraser.php` | bespoke `tblEmailLog.toRecipients` blanking step |
| `web/_apps/admin/integrations/index.php` | shared-mailbox status + edit form; `sendTestEmail()` re-routed through `Mailer::send()`; last-send indicator |
| `web/_apps/admin/integrations/ms365-mail-save.php` | **NEW** — CSRF'd admin save handler (cloudflare-stream pattern) |
| `web/_apps/admin/integrations/email.php` | dead `email.*` vocabulary → `Mailer::provider()` + effective sender; recent-sends `portal-data-list`; DNS-probe domain = effective sender |
| `web/_apps/help/admin.php` | owner runbook section |
| `web/_sql/176_ms365_shared_mailbox.sql` | **NEW** — table + 5 seeds + 1 route + self-record |
| `web/_sql/full_schema.sql` | fold: table + seeds + route |
| Docs | `CHANGELOG.md`, `FEATURES.md` (admin row + auth/mail note), `DEV_NOTES.md` (model decision, AAP/RBAC note, defaultFromName behaviour change, dead `email.*` keys note) |

House conventions applying throughout: `declare(strict_types=1)`; full IF notation (`=== true`); emoji-annotated section comments; full file-header blocks; `DIRECTORY_SEPARATOR` paths; prepared statements only; `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on all output; `portal-data-list` (never `<table>`) for the new list; no `api/*` routes involved (plain admin route → tblRoutes is correct here, ApiRouter trap n/a).

---

## 11. Acceptance gates

1. `php -l` clean on every touched PHP file.
2. All **11** audit checks green (`tools/audit-checks/`): `check_settings_keys`, `check_schema_seed_parity`, `check_mariadb_only_ddl`, `check_sql_columns`, `check_migration_idempotency`, `check_route_targets`, `check_php_table_refs`, `check_bind_param_arity`, `check_no_native_confirm`, `check_cdn_sri`, `check_mobile_readiness`.
3. e2e-migrations harness: 176 applies on prior schema AND replays as a no-op on `full_schema.sql`.
4. **Manual send test (documented in the PR):** (a) with `mail.ms365.sharedMailbox` empty — test email sends exactly as before (regression gate); (b) set the shared mailbox via the new form — test email arrives showing the shared mailbox + display name as sender, `tblEmailLog` row `ms365-shared`/`sent`, copy in the shared mailbox's Sent Items when enabled; (c) set a mailbox *outside* the Application Access Policy — admin card surfaces the `ErrorAccessDenied` hint and a `failed` log row; (d) revoke/expire scenario: token-cache 401 path retried transparently (verifiable by clearing the cached token mid-session or by log inspection).
5. PR Security checks bot comment clean (route-target, DDL, idempotency, schema/seed parity heuristics) per the standing instruction; re-check after every push.
6. Issue thread updated: model decision recorded (app-only chosen over the issue body's delegated sketch, with rationale), acceptance-criteria checklist mapped (all met except "fallback to SMTP" — no SMTP provider exists; met as opt-in Google fallback + fail-loud default, note this explicitly).

---

## 12. Explicitly out of scope (deferred, recorded on #234)

- Delegated `Mail.Send.Shared` auth-code/refresh-token mode (Model B) — deferred with rationale (§2); reopen as its own issue only if a tenant refuses application `Mail.Send` even with an Application Access Policy.
- An SMTP provider (issue's fallback wording) — does not exist in this codebase; the dead `email.provider='smtp'` seeds are documented, not removed.
- Per-app/per-message sender overrides (e.g. newsletter from a different mailbox) — the single admin-configured identity is a deliberate security property; a future multi-identity design would need its own allowlist model.
- Bounce/`last bounce` tracking (issue wishlist) — Graph sendMail gives no synchronous bounce signal; would need mailbox-read permissions (scope creep against least-privilege).

## 13. Open questions → recommended defaults

| # | Question | Recommended default |
| --- | --- | --- |
| Q1 | Honour the issue-body's delegated `Mail.Send.Shared` design instead? | **No** — app-only (§2); record on the thread. If the owner insists, it becomes a separate follow-up issue; nothing in this design blocks adding it later as a second sub-mode. |
| Q2 | Fallback provider on Graph failure? | **Off by default** (`mail.fallbackProvider=''` → fail loudly + `tblEmailLog` + existing #229 critical alerting picks up repeated failures); `'google'` opt-in. |
| Q3 | `saveToSentItems`? | **`true`** — an office shared mailbox wants the audit copy; setting exposed for tenants that don't. |
| Q4 | Separate enable toggle vs empty-key-off? | **Empty-key-off** — one fewer setting, impossible to half-configure (toggle on + empty mailbox). |
| Q5 | Add `from` object in legacy (non-shared) mode too? | **Yes** — makes `mail.defaultFromName` finally work on MS365; flag as benign behaviour change in CHANGELOG. If the owner wants zero visible change, gate the `from` object on shared-mode only (one-line difference). |
| Q6 | `tblEmailLog` retention default? | **90 days** (`mail.log.retentionDays`), opportunistic prune — no new cron. |
| Q7 | Migration number | **176** (175 = webpush reservation); re-verify at build (§8). |
| Q8 | Rework the duplicated inline test-send to call `Mailer::send()`? | **Yes** (§6.1) — removes permanent drift risk; the token-acquisition test button keeps its standalone diagnostic value. |
