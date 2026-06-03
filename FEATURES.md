# WebMS Intra — Features

> **Living working summary.** Kept current alongside the codebase. Refer to
> [CHANGELOG.md](CHANGELOG.md) for chronological history and to [README.md](README.md)
> for setup, deployment, and licence info.
>
> **Snapshot:** 2026-06-03 · **Version on `main`:** 1.2.0
> · **Major recent landings:** waves 3/4/5 apps (~16 new apps),
> `_apps/` defence-in-depth refactor (#159), nonce-based CSP (#144),
> external error monitor (#143), PWA offline write queue (#233),
> REST API write-side CRUD (#157).
> · **In-flight branches:** `feat/calendar-view-modes` (PR #137),
> `feat/calendar-themes-and-display-style` (PR #138)

---

## How to read this document

- **Status legend**
  - ✅ **Shipped** — on `main`, available to users.
  - 🛠️ **In flight** — open PR; behaviour described is the proposed state.
  - 🟡 **Partial** — works in some flows but has known gaps.
  - 🔜 **Planned** — tracked by a GitHub issue but not started.
- Each section names the **routes**, **DB tables**, and **settings** involved
  so you can locate the implementation quickly.
- Anything marked 🛠️ here will move to ✅ when the named PR merges; the
  description should not need to change.

---

## Core framework (`web/_core/`)

Foundational classes loaded by every request via `bootstrap.php`. All ✅.

| Class | Purpose |
| --- | --- |
| `App` | Service registry — `db()`, `settings()`, `user()`, `isAdmin()`, `siteId()`, transaction helpers |
| `Auth` | Sessions, CSRF, local + MS365 + Google OAuth + WebAuthn, password policy, 2FA TOTP, account linking |
| `Router`, `ApiRouter` | Front-controller URL dispatch + dedicated JSON API dispatch |
| `Site` | Multi-site context — detection, branding, per-site settings overrides |
| `Captcha` | Provider-agnostic — Turnstile / reCAPTCHA v2+v3 / hCaptcha with admin-configurable priority |
| `Mailer`, `MailerGoogle` | Microsoft Graph "SendAs" + Google Workspace SendAs |
| `ExpenseMailer`, `ExpensePdf`, `Pdf` | Expense email notifier, PDF generator, dompdf wrapper |
| `Logger` | Activity + error logging into `tblActivityLogs` / `tblErrors` |
| `Migrator` | Web-based SQL migration runner |
| `Validator` | Pipe-separated rule validator (`required|email|min:8|…`) |
| `Asset` | CDN-with-local-fallback loader with SRI |
| `Avatar` | Cascade: MS365 → local → Gravatar → generated SVG |
| `Gatekeeper` | Dev/beta channel access control |
| `RateLimiter` | IP-based login + form rate limiting |
| `I18n` | Translations, RTL, formatting helpers |
| `Totp` | TOTP 2FA RFC 6238 implementation |
| `WebAuthn` | Server-side WebAuthn / PassKeys helper |
| `Container` | Lightweight DI container |
| `CsvExporter` | Shared CSV export |
| `ApiResponse` | JSON API response builder |
| `Debug` | Debug panel — refuses in prod since #54 |

---

## Apps

### 📊 Dashboard — `/dashboard/` ✅

Portal home with brand banner, hero, app card grid.

- Per-site brand colour + favicon drive the visual identity.
- App cards link out to every enabled app on the site.

---

### 🛡️ Admin — `/admin/` ✅

Central operations hub for admins / site admins.

| Route | What it does |
| --- | --- |
| `/admin` | Dashboard with summary cards (errors, users, activity, pending migrations) |
| `/admin/users` + `/users/import` + `/users/export` | User CRUD + CSV bulk import + export |
| `/admin/errors` | Error log viewer (`tblErrors`) |
| `/admin/activity` + `/activity/export` | Activity log viewer + CSV export |
| `/admin/audit` | Before/after change tracking (#91) |
| `/admin/migrations` | Web-based migration runner |
| `/admin/integrations` | Live integration diagnostics (MS365 OAuth/Graph, Google OAuth/Gmail) |
| `/admin/sites` | Umbrella admin: site CRUD + per-site user management |
| `/admin/workflows` | Configurable workflow engine config (#94) |
| `/admin/reports` | Reporting / analytics dashboard (#93) |
| `/admin/captcha` | **Multi-provider captcha config — drag-and-drop priority + per-provider keys (#130)** |
| `/settings` | Generic dot-notation settings editor |

---

### 🔐 Auth — `/auth/` ✅

Local + SSO + multi-factor sign-in.

| Flow | Status |
| --- | --- |
| Local username + password login | ✅ |
| MS365 OAuth (PKCE + ID-token validation) | ✅ |
| Google OAuth | ✅ |
| WebAuthn / PassKey registration + login | ✅ |
| Forgot password → email reset link | ✅ |
| Reset password (token verified, single-use) | ✅ |
| Account page (profile, change password, linked accounts, WebAuthn keys, unlink) | ✅ |
| 2FA TOTP setup / verify / disable | ✅ |
| **Password policy** — min 12 chars (configurable), independent complexity flags, max length, **client-side strength meter** | ✅ (#132) |
| Login rate limiting (IP-based currently; #52 wants composite IP+username) | 🟡 |

**Tables:** `tblUsers`, `tblLocalAccounts`, `tblPasswordResets`, `tblLinkedAccounts`, `tblWebAuthnCredentials`, `tblUserTotp`
**Settings:** `auth.password.minLength`, `auth.password.maxLength`, `auth.password.requireUppercase`, `auth.password.requireLowercase`, `auth.password.requireNumber`, `auth.password.requireSpecial`, `auth.passwordReset.tokenExpiry`, `auth.ms365.*`, `auth.google.*`, `auth.turnstile.*`, `auth.recaptcha.*`, `auth.hcaptcha.*`, `auth.captcha.priority`

---

### 📅 Calendar — `/calendar/` ✅ + 🛠️

Events, series, RSVP, exports, and (in flight) seven view modes.

**Shipped:**
- Event CRUD with hero images, location, all-day support, public/featured flags.
- Event series with bulk edit (#75).
- Event categories + types (hierarchical, per-site).
- Recurring rules: weekly / fortnightly / monthly / quarterly / yearly / custom.
- RSVP system (#88) — capacity, waitlist, confirmation emails.
- iCal export (`/calendar/export`).
- Public + admin-managed views.

**🛠️ In flight (PR #137 — closes #136):**
- Seven view modes — `/calendar?view=day|week|weekdays|weekend|month|year|list`.
- Day / Week / Weekdays / Weekend share an hour-timeline renderer parametrised by column count.
- Month view as a 7-column grid with up to 3 event pills per cell + "+ N more".
- Year planner as a 12-month-column wall planner (24-column grid; day-number + content sub-columns; weekend tints; multi-day event bands).
- Date navigation, view-switcher buttons, filter row.
- Last-used view persists in `localStorage`; admin sets `calendar.defaultView` (default `month`).
- Events colour-coded by `tblEventCategories.color` (regex-validated server-side).

**🛠️ In flight (PR #138 — stacked on #137):**
- Per-month strap-line text under each month name on the year planner (`tblCalendarMonthThemes`).
- `tblEventCategories.displayStyle` — `'background'` (default — tinted band) vs `'text'` (coloured text, no band) — matches how traditional planners flag Bank Holidays / Notable Days.
- Admin pages: `/calendar/manage/types` (colour + style picker) and `/calendar/manage/month-themes`.

**🔜 Open issues:**
- #97–#103: BookIT calendar-provider abstraction (7-PR series).
- #128: New Order of Service planner with iHymns integration (gated on iHymns permission).

**Tables:** `tblEvents`, `tblEventCategories`, `tblEventTypes`, `tblEventSeries`, `tblEventThemes`, `tblEventRecurrence`, `tblEventRsvps`, `tblCalendarMonthThemes` (🛠️)
**Settings:** `calendar.enabled`, `calendar.displayName`, `calendar.displayIcon`, `calendar.brandColor`, `calendar.defaultView`, `calendar.enablePublicView`, `calendar.allowRecurringEvents`

---

### 🙏 Prayer Requests — `/prayer-requests/` ✅ (#129)

Per-site prayer-request submission with moderation and anonymous public submission.

- Logged-in submissions with per-request visibility (leadership-only / congregation feed).
- "Display as Anonymous" toggle (moderators still see who submitted).
- Public anonymous route at `/prayer-requests/anonymous` (no login) — CSRF + CAPTCHA + RateLimiter; always pending, leadership-only.
- Lifecycle: pending → active → answered (optional praise/testimony note) → archived.
- Moderation queue at `/prayer-requests/manage`.
- Help page at `/help/prayer-requests`.

**Tables:** `tblPrayerRequests`
**Settings:** `prayerRequests.enabled`, `prayerRequests.allowAnonymous`, `prayerRequests.allowCongregationFeed`, `prayerRequests.requireModeration`, `prayerRequests.allowTestimony`

---

### 📋 Attendance — `/attendance/` ✅

Service-type-aware headcount tracker.

- Sessions with date / time / event linkage / notes.
- Counts split by service type (hierarchical: e.g. Worship → Sabbath School → Adult).
- Filters by service type, date range; CSV export; trend reports.
- Bulk session templates (#74).

**Tables:** `tblAttendanceSessions`, `tblAttendanceCounts`, `tblAttendanceServiceTypes`

---

### 💷 Expenses — `/expenses/` ✅

Full claim lifecycle with multi-approver, treasury, PDF, CSV.

- Submit (`/expenses/submit`) — claim with line items, receipt uploads, auto-attached PDF.
- Approve (`/expenses/approve`) — multi-approver workflow with comments.
- Treasury (`/expenses/treasury`) — record reimbursement, payment reference.
- Withdraw (`/expenses/withdraw`) — claimant can cancel pre-approval (#73).
- View (`/expenses/view`) — claim detail + audit trail.
- API endpoints: `/expenses/api/list`, `/expenses/api/export`.

**🔜 Open issues:** #40 (Payment integration prep — design phase).

**Tables:** `tblExpenseClaims`, `tblExpenseLines`, `tblExpenseAttachments`, `tblExpenseApprovals`, `tblExpenseStatuses`

---

### 👥 Leadership — `/leadership/` ✅

Roles + assignments + history.

- Hierarchical roles per site.
- Assign / unassign users; history preserved (#70 fix: no CASCADE wipe).
- Leadership transition workflow (#76).
- CSV export.

**Tables:** `tblLeadershipRoles`, `tblLeadershipAssignments`

---

### 📣 Announcements — `/announcements/` ✅ (#89)

Per-site noticeboard.

- Manage / view / save / delete.
- Visibility windows (start + end dates).

**Tables:** `tblAnnouncements`

---

### 📁 Documents — `/documents/` ✅ (#90)

File library with categories.

- Upload / download / delete; uploads land under `_uploads/`.
- Category management.

**Tables:** `tblDocuments`, `tblDocumentCategories`

---

### ✅ Tasks — `/tasks/` ✅ (#96)

Reminder / task system.

- Per-user assigned tasks with due dates.
- Complete / dismiss actions.

**Tables:** `tblTasks`, `tblTaskReminders`

---

### 🛰️ API — `/api/` 🟡

Read-only JSON list endpoints over `Portal\Core\ApiRouter`.

- `/api/attendance/list`
- `/api/announcements/list`
- `/api/users/list`
- `/api/events/list`, `/api/events/detail`

**Gaps:** #95 was closed as "REST API expansion — CRUD for all modules" but only list endpoints exist. Full CRUD would still be additional work.

---

### ⚙️ Settings — `/settings/` ✅

Generic admin settings editor.

- Auto-grouped by dot-notation prefix.
- Sensitive values encrypted at rest (libsodium XSalsa20+Poly1305).
- Site-scoped + global-default behaviour.

**Tables:** `tblSettings`

---

### 📖 Help Centre — `/help/` ✅

In-app documentation per app.

| Page | Covers |
| --- | --- |
| `/help/getting-started` | Login, navigation, theme cycle, CB-safe palette, per-site branding |
| `/help/expenses` | Submit, statuses, receipts, withdrawal |
| `/help/approvals` | For approvers |
| `/help/treasury` | For treasury staff |
| `/help/admin` | Settings, user roles, site branding, captcha config (🛠️ to add: calendar views) |
| `/help/translations` | Language + i18n |
| `/help/prayer-requests` | Prayer requests lifecycle, anonymous route, moderation |
| `/help/faq` | Common questions |

---

### 🌐 Site switcher — `/site/` ✅

Multi-site handler. Switches `Site::id()` for the current session.

---

### ⛅ Offline — `/offline/` ✅

PWA offline fallback page.

---

### 🛠️ Installer — `/install/` ✅

Self-contained 6-step setup wizard (bootstrap-free).

- Prerequisites check (PHP version, extensions, paths).
- DB credentials + connection test.
- Schema install from `full_schema.sql`.
- Admin account creation — **enforces the same 12-char-min password policy as the rest of the portal (#132)**, with the client-side strength meter inline.
- Encryption key generation.
- Lock file written; further installation attempts blocked.

---

## Cross-cutting

### 🎨 UI / Design system ✅ (Phase 11)

- Linear-style indigo design tokens (`#5e6ad2`).
- `color-mix()` derivations with hex fallbacks (Chrome <111 / Safari <16.2 / Firefox <113).
- Three theme modes: light / dark / auto via `prefers-color-scheme`.
- CB-safe palette toggle (Wong, Nature Methods 2011).
- Per-site `Site::branding()` overrides `--portal-primary` and friends via inline style on `<html>`.
- "Powered by WebMS Intra" attribution rule (Site::usesCustomBranding).
- `<meta name="generator" content="WebMS Intra">` alongside footer attribution.
- **Anchor colour now bound to `--portal-link` → `--bs-link-color` in both themes (#135)** — fixes browser-default blue leaking through in dark mode.

### 🔒 Security

| Item | Status |
| --- | --- |
| MySQLi prepared statements throughout | ✅ |
| CSRF rotation after sensitive actions | ✅ |
| Sensitive settings encrypted at rest (libsodium) | ✅ |
| RS256 JWT verification with JWKS (MS365) | ✅ |
| Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax` | ✅ |
| SRI integrity hashes on CDN resources | ✅ |
| Security headers (CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, X-Content-Type-Options) | ✅ |
| Password policy hardened (min 12, independent complexity, max length, full-flow validation) | ✅ (#132) |
| Multi-provider Captcha with admin priority | ✅ (#130) |
| Debug mode refused in production (logged, exception traces don't leak) | ✅ (#54) |
| Login rate limiting on composite IP+username | 🔜 (#52) |
| Signed commits enforced | 🔜 (#106) |
| Prod secrets behind GitHub Environment + reviewer gate | 🔜 (#105) |
| Privacy / GDPR helpers | 🔜 (#47) |
| 2FA TOTP available | ✅ (#92) |

### 🌍 Multi-site (Phase 10) ✅

- Umbrella → sites → users with 4-tier permission hierarchy (Umbrella / Site Root / Site Admin / Legacy).
- Detection modes: subdomain, path-prefix, session.
- Per-site `tblSites.primaryColor` + `tblSites.faviconPath` drive branding.

### 🌐 Internationalisation (Phase 8) ✅

- `I18n` framework, translations under `web/_lang/{xx}.php`.
- RTL support.
- Per-user language preference.
- Date/time format settings (#69).

### 🚀 CI/CD ✅

- 3-branch SFTP deploy (alpha / beta / main) via `lftp`, SSH-key with password fallback.
- `--delete` mirror on shared dirs (`core/`, `vendor/`, `sql/`, …) — see [DEV_NOTES.md → Troubleshooting](DEV_NOTES.md#troubleshooting) for survival rules.
- `dry_run` `workflow_dispatch` input on `deploy.yml` for preview-mode deploys (#107).
- `gitleaks` CLI for secret scanning (free MIT binary, not the licensed action).
- Repo config audit workflow (#108).
- `version-bump.yml`, `changelog.yml`, `release.yml`, `auto-merge-alpha.yml`.

### 🧱 Stack baseline

- PHP 8.5 (BC with 8.4), MySQL 8.0+, Apache + mod_rewrite, DreamHost shared.
- Bootstrap 5.3.3, Font Awesome 6.5.1.
- dompdf 3.1.5 (fetched at deploy time by `tools/download-dompdf.sh`).
- Microsoft Graph for email + OAuth (SendAs from a shared mailbox).
- Google Workspace ready (config slots present).
- CloudFlare Turnstile preferred for captcha.

---

## Migrations on disk

`web/_sql/` contains numbered migrations 000-043. `full_schema.sql` is kept in sync so fresh installs are wired up out of the box.

Latest additions:

| # | What |
| --- | --- |
| 039 | Prayer Requests (#129) |
| 040 | Multi-provider Captcha (#130) |
| 041 | Password policy hardening (#132) |
| 042 | Calendar `defaultView` setting (🛠️ #137) |
| 043 | Calendar category colour + displayStyle, month themes (🛠️ #138) |

---

## In-flight PR stack

| PR | Title | Status |
| --- | --- | --- |
| #137 | Calendar seven view modes (closes #136) | 🛠️ Open |
| #138 | Calendar month themes + category display-style (stacked on #137) | 🛠️ Open |

When these merge, the 🛠️ markers above flip to ✅ without further edits to this file — language is already written in the past tense.

---

## Tracked but not started

| Issue | Scope |
| --- | --- |
| #127 | WordPress Multisite integration — design + phased implementation (3–4 weeks) |
| #128 | Order of Service planner app + iHymns integration (gated on iHymns permission) |
| #97–#103 | BookIT calendar-provider abstraction (7-PR series) |
| #111 | UI refresh umbrella — practically done via PRs #114–#126; can likely be closed |
| #52 | Login rate-limit composite IP+username |
| #47 | Privacy & GDPR compliance helpers |
| #40 | Payment integration prep |
| #106 | Enforce signed commits |
| #105 | Prod secrets behind GitHub Environment + reviewer gate |
| #107 | SFTP `--delete` operational documentation (partially addressed by PR #134) |

---

## Waves 3 / 4 / 5 — install-on-demand apps (2026-06)

All apps default `enabled = 0` and toggle per-site via `/admin/apps`. Every app
ships with a `_core/apps/{slug}.php` config that AppRegistry auto-discovers,
a `_core/{Slug}.php` helper class, a numbered SQL migration, and route +
setting seeds.

### Wave 3 (#283) — PR landed 2026-06-02

| App | Issue | Migration | Status |
|---|---|---|---|
| Reading Plans (Bible-in-a-year, chronological, streak counter) | #265 | 084 | ✅ |
| QR generator + CueRCode adapter slot | #275 | 085 | ✅ (CueRCode hash empty pending its public API) |
| Invite-based onboarding (SHA-256 hashed tokens, public acceptance route) | #239 | 086 | ✅ |
| One-click offboarding (7-step atomic revocation + 7-day rehire window) | #240 | 087 | ✅ |

### Wave 4 (#284) — PR landed 2026-06-02

| App | Issue | Migration | Status |
|---|---|---|---|
| Resources (room/asset booking with overlap conflict detection) | #263 | 088 | ✅ |
| Service Plans (run-sheet builder, printable) | #262 | 089 | ✅ |
| Livestream (YouTube/Vimeo/Twitch/Facebook embed + countdown) | #273 | 090 | ✅ |
| Recordings (RSS podcast feed + HTTP Range streaming + FULLTEXT search) | #264 | 091 | ✅ |
| Zoom (OAuth, meeting creation from calendar, webhook HMAC) | #274 | 092 | ✅ |
| Newsletter (composer with auto-pulled content blocks, provider abstraction → MailerMatt slot) | #269 | 093 | ✅ |
| Giving (tithe log, Gift Aid digital declaration, HMRC schedule CSV, year-end PDF) | #266 | 094 | ✅ |
| SMS (Twilio + MessageBird + SigV4-signed AWS SNS; verification + per-category opt-in + Sabbath quiet hours) | #272 | 095 | ✅ |
| Projects (public fundraising page, pledge thermometer, captcha-gated anonymous pledges) | #267 | 096 | ✅ |
| Payments (Stripe Checkout + v1 HMAC webhook + refund; side-effects into Giving/Projects) | #268 | 097 | ✅ |

### Wave 5 (#285) — PR landed 2026-06-03

| Item | Issue | Migration | Status |
|---|---|---|---|
| Transcription (Whisper / AssemblyAI / local; FULLTEXT search; click-to-timestamp) | #276 | 098 | ✅ |
| Translation (Anthropic / OpenAI / Google / DeepL / LibreTranslate; content-addressable cache) | #278 | 099 | ✅ |
| AI Assist (Anthropic / OpenAI / ollama; editable prompt templates; cap + daily limit + audit) | #277 | 100 | ✅ |
| GDPR Article 17 erasure engine (19-table catalogue, sealed audit chain, 1-month SLA queue) | #235 | 101 | ✅ |
| Photos (4-tier visibility, moderation queue, EXIF-aware GD re-encode for non-privileged downloads) | #236 | 102 | ✅ |
| Off-site backup (weekly AES-256-CBC to rclone/S3/SFTP) | #249 | 103 | ✅ |
| Disaster-recovery runbook + `/help/disaster-recovery` | #250 | 104 | ✅ |
| CDN SRI audit script + Asset helpers for Sortable + Swagger UI | #161 | — | ✅ (4 hashes empty pending curl-and-fill) |
| End-to-end MySQL migration test harness (docker-compose 8.0.36 + 3-phase script) | #248 | — | ✅ (Docker required to actually run) |
| Static mobile readiness audit + worksheet (29 fix targets surfaced) | #225 | — | ✅ (device walk-through still needs hardware) |

### Post-wave-5 hardening (PRs #286-#293, 2026-06-03)

| Item | Issue | PR | Status |
|---|---|---|---|
| Rename-aftermath doc sweep (README, CLAUDE.md, DEV_NOTES, full_schema header) | #189, #182, #183, #194, #190, #191, #192, #193 | #286 | ✅ |
| `auto-merge-alpha.yml` verification (0 runs — workflow correct, awaiting first alpha PR) | #147 | #287 | ✅ |
| App controllers moved from `public_html/` into `_apps/` outside the webroot | #159 | #288 | ✅ |
| Nonce-based CSP `script-src` tightening + `App::cspNonce()` | #144 | #289 | ✅ |
| External error monitor — `Portal\Core\ErrorMonitor` adapter for Sentry / GlitchTip | #143 | #290 | ✅ |
| REST API write-side CRUD: Announcements / Tasks / Prayer Requests / Leadership (10 new endpoints) | #157 | #291 | 🟡 (Documents / Attendance / Expenses deferred) |
| PWA offline write queue + sync-on-reconnect (`Portal.OfflineQueue` IndexedDB module + `/account/offline-queue`) | #233 | #292 | ✅ |
| Codebase audit sweep — duplicate cookie banner removed; missing `Auth` import fixed; 6 SQL int-concat queries → prepared statements | — | #293 | ✅ |

---

## Audit scripts (`tools/audit-checks/`)

CI-runnable static audits invoked from PHP-static-analysis workflow:

| Script | What it catches |
|---|---|
| `check_route_targets.py` | `tblRoutes.targetFile` pointing at a non-existent file |
| `check_sql_columns.py` | INSERT/UPDATE/SELECT referencing a column not in `full_schema.sql` |
| `check_no_native_confirm.py` | Inline `confirm()` calls bypassing `Portal.Confirm` modal |
| `check_settings_keys.py` | Code reading a setting key not seeded in any migration |
| `check_cdn_sri.py` | `<script>`/`<link>` to a known CDN host without `integrity=` |
| `check_migration_idempotency.py` | DDL without `IF NOT EXISTS` / inserts without `ON DUPLICATE KEY UPDATE` |
| `check_mobile_readiness.py` | Hard-coded widths > 320 px, bare `<table>`, missing `accept=`, modal without `modal-fullscreen-sm-down` |

Audit pass status as of 2026-06-03: **0 missing routes · 0 column mismatches · 0 native confirms · 0 CDN tags without SRI**. Mobile readiness reports 29 informational findings (concrete fix targets); migration idempotency reports 19 historical (pre-multi-site cohort, already deployed and Migrator-protected).

---

## Infrastructure helpers (`tools/`)

| Path | Purpose |
|---|---|
| `tools/audit-checks/` | Static-analysis scripts (above) |
| `tools/e2e-migrations/` | docker-compose MySQL 8.0.36 + `run.sh` 3-phase migration smoke test (#248) |
| `tools/offsite-backup/` | Reference `sync-offsite.sh` + `log-offsite-result.php` (admin copies into gitignored `web/_backups/`) (#249) |
