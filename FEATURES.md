# WebMS Intra — Features

> 🏷️ **Product brand layer (#296)** — the same codebase ships under
> several sub-brands picked at install time: `WebMS Intra` (generic),
> `ChurchMS` (church / place of worship), and placeholder presets for
> `SchoolMS` / `CharityMS` / `CommunityMS` / `BusinessMS`. Affects only
> display surfaces (name, tagline, PWA install prompt, X-Powered-By
> header, footer attribution). Tenant branding (per-site `siteName`,
> logo, colour) still beats the product layer. See DEV_NOTES "Two-layer
> brand model" for the resolution cascade.
>
> **Living working summary.** Kept current alongside the codebase. Refer to
> [CHANGELOG.md](CHANGELOG.md) for chronological history and to [README.md](README.md)
> for setup, deployment, and licence info.
>
> **Snapshot:** 2026-06-21 · **Version on `main`:** 1.2.1
>
> **Phase 1 ships sitting on PR #358 — Discipleship Pathway Tracker (#303) + COP Live Chat (#313).** The latter shipped with structural reworks the adversarial review caught (file relocation to ApiRouter's 3-segment convention; CSRF dropped on public /send replaced with sessionToken-exists guard; first-message-only captcha; rate-limit fail-CLOSED). **Discipleship Phase 2 (per-user progress + auto-completion, migration 153) has since landed** — see the dedicated section near the end of this document.
>
> **Already merged to main since the prior snapshot:** PR #355 worship engine (#308 full v1: schema + CRUD + live operator + projector + state polling + SortableJS drag-reorder + song verse auto-split + CCLI usage log + brand asset folder move to /brandkit/assets/). PR #356 Plus Jakarta Sans modular embed (self-hosted, single-source-of-truth via Asset::brandFontsCss + --portal-font-family — one-line swap for future brand-font changes). PR #357 #317 Virtual Host Console Phase 1 + #323 API key infrastructure Phase 1 (`Portal\Core\HostConsole` + `Portal\Core\ApiKey` + `ApiResponse::requireApiKey($scopes)`).
>
> **Original snapshot retained below for reference:** 2026-06-19 · **Version on `main`:** 1.3.0
> · **Major recent landings:** PR #340 (36 issues / 39 commits) —
> events platform overhaul (registration form builder, public landing
> page at `/e/<slug>`, embeddable widgets, ICS feed importer, per-occurrence
> overrides, faceted filter bar, multiple primary organisers,
> anonymous email-link RSVP, event lifecycle reminders, broadcast
> bulk-email), VBS bundle (coordinator role, volunteer resource portal,
> multi-day attendance grid, crew + job board, auto-build),
> COP recordkeeping (anonymous attendance, decision moments, salvation
> cards, livestream analytics), ChurchMS verticals (denominational
> reports, song library + CCLI, kids check-in/out with safeguarding
> badges), DBS safeguarding tracking.
> · **In-flight branches:** `chore/post-merge-cleanups` (composer fix +
> version bump + this snapshot update).

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
| `Site` | Multi-site context — detection, branding, per-site settings overrides; product-brand resolution helpers `productName()` / `productTagline()` / `productPublisher()` (#296) |
| `AppRegistry` | Single source of truth for installable apps; powers `/admin/apps` toggle + Router enablement gating + industry filter (#255) |
| `Captcha` | Provider-agnostic — Turnstile / reCAPTCHA v2+v3 / hCaptcha with admin-configurable priority |
| `Mailer`, `MailerGoogle` | Microsoft Graph app-only send (direct or admin-configured shared mailbox, #234) + Google Workspace SendAs; every send logged to `tblEmailLog` |
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
| `/admin/workflows` | Configurable workflow definition CRUD (#94) — step delete/isActive/autoAction (#443); the running engine is the `/approvals` app below |
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
| Login rate limiting — composite username+IP (`RateLimiter::isUserOrIpBlocked`) | ✅ (#52) |

**Tables:** `tblUsers`, `tblLocalAccounts`, `tblPasswordResets`, `tblLinkedAccounts`, `tblWebAuthnCredentials`, `tblUserTotp`
**Settings:** `auth.password.minLength`, `auth.password.maxLength`, `auth.password.requireUppercase`, `auth.password.requireLowercase`, `auth.password.requireNumber`, `auth.password.requireSpecial`, `auth.passwordReset.tokenExpiry`, `auth.ms365.*`, `auth.google.*`, `auth.turnstile.*`, `auth.recaptcha.*`, `auth.hcaptcha.*`, `auth.captcha.priority`

---

### 📅 Calendar — `/calendar/` ✅

Events, series, RSVP, exports, and seven view modes.

**Shipped:**
- Event CRUD with hero images, location, all-day support, public/featured flags.
- Event series with bulk edit (#75).
- Event categories + types (hierarchical, per-site).
- Recurring rules: weekly / fortnightly / monthly / quarterly / yearly / custom.
- RSVP system (#88) — capacity, waitlist, confirmation emails.
- iCal export (`/calendar/export`).
- Public + admin-managed views.
- Crews (#343), volunteer job board (#344), multi-day attendance grid (#345), segment broadcast (#119), registrations (#347) — per-event coordinator tools at `/calendar/event/{crews,jobs,attendance,broadcast}` + `/admin/calendar/registrations`.
- **Event Team Hub (#386 Phase 1)** — per-event staff/volunteer/organiser landing page at `/calendar/event/hub`, gathering the above tools plus a Resources list and a YouTube/Vimeo/Cloudflare Stream video grid. See the dedicated section below.

**Shipped (PR #137, closed #136):**
- Seven view modes — `/calendar?view=day|week|weekdays|weekend|month|year|list`.
- Day / Week / Weekdays / Weekend share an hour-timeline renderer parametrised by column count.
- Month view as a 7-column grid with up to 3 event pills per cell + "+ N more".
- Year planner as a 12-month-column wall planner (24-column grid; day-number + content sub-columns; weekend tints; multi-day event bands).
- Date navigation, view-switcher buttons, filter row.
- Last-used view persists in `localStorage`; admin sets `calendar.defaultView` (default `month`).
- Events colour-coded by `tblEventCategories.color` (regex-validated server-side).

**Shipped (PR #138):**
- Per-month strap-line text under each month name on the year planner (`tblCalendarMonthThemes`).
- `tblEventCategories.displayStyle` — `'background'` (default — tinted band) vs `'text'` (coloured text, no band) — matches how traditional planners flag Bank Holidays / Notable Days.
- Admin pages: `/calendar/manage/types` (colour + style picker) and `/calendar/manage/month-themes`.

**🔜 Open issues:**
- #97–#103: BookIT calendar-provider abstraction (7-PR series).

**Tables:** `tblEvents`, `tblEventCategories`, `tblEventTypes`, `tblEventSeries`, `tblEventThemes`, `tblEventRecurrence`, `tblEventRsvps`, `tblCalendarMonthThemes`
**Settings:** `calendar.enabled`, `calendar.displayName`, `calendar.displayIcon`, `calendar.brandColor`, `calendar.defaultView`, `calendar.enablePublicView`, `calendar.allowRecurringEvents`

---

### 🙏 Prayer Requests — `/prayer-requests/` ✅ (#129, #311)

Per-site prayer-request submission with moderation and anonymous public submission.

- Logged-in submissions with per-request visibility (leadership-only / congregation feed).
- "Display as Anonymous" toggle (moderators still see who submitted).
- Public anonymous route at `/prayer-requests/anonymous` (no login) — CSRF + CAPTCHA + RateLimiter; always pending, leadership-only.
- Lifecycle: pending → active → answered (optional praise/testimony note) → archived.
- Moderation queue at `/prayer-requests/manage`, with a per-row prayer-chain
  partner assign dropdown; full assign UI + private-note admin panel on
  `/prayer-requests/view`.
- **Prayer-chain partner assignment (#311, migration 148):** eligible
  partner = an active site member holding the `prayer_team` role
  (`Portal\Core\PrayerChain`). Manual assign from `manage`/`view` shows each
  partner's current OPEN-assignment count as a load-balancing hint. Opt-in
  round-robin **auto-assign** on submission (`prayer-requests.autoAssign`)
  picks the least-loaded eligible partner (ties → lowest userID) across
  `save.php`, `anonymous-save.php`, and `api/create.php`. Assignment
  (manual or auto) emails + SMS-pings the partner (respecting their
  verified-number + `prayer_assignment` category opt-in), gated by
  `prayer-requests.notifyOnAssign`.
- **`/account/my-prayer-list`:** the assigned partner's own view of their
  OPEN assignments (pending/active), with a "mark prayed for" action and a
  **private note** (`partnerNote`) only they (or an admin) can read/write —
  cleared automatically on reassignment to a different partner.
- Help page at `/help/prayer-requests`.

**Tables:** `tblPrayerRequests` (+ `partnerNote`, `partnerLastPrayedAt` — migration 148)
**Settings:** `prayerRequests.enabled`, `prayerRequests.allowAnonymous`, `prayerRequests.allowCongregationFeed`, `prayerRequests.requireModeration`, `prayerRequests.allowTestimony`, `prayer-requests.autoAssign`, `prayer-requests.notifyOnAssign`

---

### 🧾 Forms Builder — `/forms/` ✅ (#153)

Generic form designer — "we need a quick form" without a code change.
Admins build a form's fields, publish it internally and/or publicly, and
review/export the responses. Built as a reusable engine
(`Portal\Core\FormEngine`) so a future app (e.g. #302 mission-trips) can
create/render/validate/persist a form programmatically without any HTTP
involvement.

- **Field types (12):** Short text, Long text, Email, Phone, Number, Date,
  Time, Dropdown, Choose one (radio), Choose many (checkboxes), Single
  tick/consent, and a display-only Section heading. The registry
  (`FormEngine::FIELD_TYPES`) is a PHP whitelist, not a SQL ENUM — adding a
  type is a code change, never a migration; a type is never removed once
  shipped (historic response snapshots still reference it).
- **Injection safety (the whole point of `FormEngine`):** field configuration
  (`configJson`) is DATA, whitelist-copied by `sanitiseConfig()` on both
  read and write; a choice field (`select`/`radio`/`checkboxes`) submits as
  a bounds-checked INTEGER INDEX into its own sanitised options array — the
  stored value is the server-side option string at that index, never raw
  client text; the form field's HTML `name` is always `f_{fieldID}`, a
  server-controlled integer. Every SQL statement is a MySQLi prepared
  statement; every rendered value is `htmlspecialchars(…, ENT_QUOTES,
  'UTF-8')`'d at the echo point.
- **Responses are an immutable snapshot** — `answersJson` captures
  `{fieldKey: {label, type, value}}` at submission time, so editing or
  deleting a field afterwards can never corrupt or orphan a historical
  answer. CSV export reflects current fields by position/label, with any
  orphaned (since-deleted) field's answers appended as trailing columns
  keyed by their original `fieldKey` — nothing is silently dropped.
- **Internal fill (`/forms`, `/forms/fill`):** signed-in members of the site
  see published `internal`/`both` forms currently within their open window;
  an `allowMultiple = 0` form shows a "Submitted" badge instead of the fill
  link once answered.
- **Public fill (`/f/{token}`):** a Router special route (cloned from
  service-plans' `/os/{token}`), six-gate uniform-404 (token exists /
  audience public|both / published / open window / site
  `forms.allowPublic` / site `forms.enabled` — all scoped to the FORM's own
  site, never the request's ambient site). **Default OFF**
  (`forms.allowPublic = 'false'`) — an admin must opt a site in before the
  public/both audience options unlock on the builder. Public POST layers
  honeypot → CSRF → `Captcha::verify()` → `RateLimiter::isBlocked()`
  (fake-success on trip) → a 5-per-15-minute per-IP bucket. QR code + link
  shown on `/forms/manage`; "Rotate link" mints a fresh token, invalidating
  the old one immediately.
- **Admin-only** for all build/publish/responses/export surfaces (v1 — no
  separate "forms manager" role yet).
- **Responses (`/forms/responses`):** new/reviewed tabs, expandable answer
  detail, mark reviewed/new, delete, CSV export (`/forms/export`).
- Help page at `/help/forms`.

**GDPR:** an internal response is erased (hard delete, `submitterID` match)
alongside the rest of a member's data via `GdprEraser`, and included in
their `/account/data-export`. A public (anonymous) response carries no
`submitterID` — only `submitterIP` for abuse-tracing — so it sits outside
subject-linked erasure by design (same reasoning as Salvation's decision
cards); an admin can still delete any individual response by hand. Public
responses are kept indefinitely in v1 (`forms.responseRetentionDays` is a
seeded `'0'` stub for a future auto-purge cron — see DEV_NOTES).

**Tables:** `tblForms`, `tblFormFields`, `tblFormResponses`
**Settings:** `forms.enabled`, `forms.allowPublic`, `forms.responseRetentionDays`

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

Per-site text announcements (short-form notices with visibility windows). Distinct from the visual poster wall in the Noticeboard app.

- Manage / view / save / delete.
- Visibility windows (start + end dates).

**Tables:** `tblAnnouncements`

---

### 📌 Noticeboard — `/noticeboard/` ✅ (#360, #363)

Visual poster wall — pinboard of event posters. Distinct from the text-based Announcements app.

**Features:**
- Poster cards (image / video / Canva embed / text-only) with colour, aspect, and serif toggles
- Scheduling: one-off event (date) OR weekly recurrence (weekday + time)
- Manual sort ordering (drag-and-drop persisted); auto-fallback to chronological
- QR share panel — links to poster's deep-link URL, server-encoded via `Portal\Core\Qr` and pinned to the current host
- Site-admin gated writes; any authenticated user can view
- Real media upload pipeline (#363) — finfo-sniffed, size-capped (`noticeboard.upload.maxBytes`, default 15 MB), server-generated filename; served back publicly (no login) via `/noticeboard/media?f=<token>` so posters keep rendering for an anonymous QR scanner. Orphaned uploads (abandoned in the editor, or whose poster was later soft-deleted) are purged automatically after each save.

**Tables:** `tblNoticeboardPosters`, `tblNoticeboardUploads`

**Routes / API:**
- `GET  /noticeboard`             — board page (authed)
- `GET  /noticeboard/media`       — poster media bytes, by token (PUBLIC, no auth — #363)
- `GET  /api/noticeboard/list`    — poster feed (authed)
- `POST /api/noticeboard/save`    — bulk upsert (site-admin, CSRF, cross-site guard)
- `POST /api/noticeboard/upload`  — media upload (site-admin, CSRF, finfo MIME allowlist — #363)
- `GET  /api/noticeboard/qr`      — QR PNG/SVG (authed, host-pinned)

**Phase 1 limitations:**
- Whole-set replace on save — last-writer-wins if two admins edit simultaneously
- Google Fonts blocked by CSP → typography degrades to system-font stack

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

### ✅🔏 Approvals — `/approvals/` ✅ (#443)

Generic inbox for the Workflow Execution Engine (`Portal\Core\Workflow`,
`web/_core/Workflow.php`) — migration 034 shipped four workflow tables +
an `/admin/workflows` definition CRUD, but nothing ran an instance until
this. See DEV_NOTES.md → "Workflow Execution Engine + Generic Approvals
Inbox (#443)" for the full state-machine writeup.

- **"Awaiting your decision"** — every active instance the viewer is
  eligible to act on (role/user/group match on the current step's
  assignee), admins see all + a Mine/All toggle. Approve / Reject /
  Comment-only, one CSRF'd POST per row — `Workflow::act()` re-checks
  authorisation independently of what the page shows.
- **History** — last 50 completed/cancelled instances with an expandable
  full decision timeline.
- **Atomic, authorised, tenant-scoped transitions** — `FOR UPDATE` row
  lock + `affected_rows === 1` guarded claim UPDATE; a foreign
  (cross-site) instanceID is indistinguishable from a missing one; a
  stale posted stepID is refused (`stale_step`).
- **Timeout escalation, never silent auto-act** — `cron/workflow-
  timeouts.php` (hourly, token-gated) escalates an overdue step (notify +
  keep waiting) unless that step explicitly sets `autoAction=approve|
  reject`.
- **Reference consumer wired:** Announcements publish approval, behind
  per-site `workflows.announcements.enabled` (default OFF — manual
  publish is byte-for-byte unchanged until a site opts in). Final
  approval flips `tblAnnouncements.isPublished` inside the SAME
  transaction as the approval claim.
- **Admin CRUD completion** at `/admin/workflows` — per-step delete
  (`admin/workflows/step-delete.php`), an `isActive` toggle, and an
  `autoAction` selector on the Add Step row (all missing from the
  original #94 CRUD).
- The seeded `expense_approval` definition (migration 034) stays
  intentionally dormant — Expenses has its own independent, department-
  scoped multi-approver system; the generic engine never drives it.

**Tables:** `tblWorkflows`, `tblWorkflowSteps`, `tblWorkflowInstances`,
`tblWorkflowActions` (all from migration 034; four additive columns + one
index + one enum value from migration 174)
**Settings:** `approvals.enabled`, `approvals.displayName`,
`approvals.displayIcon`, `workflows.enabled`, `workflows.notify_email`,
`workflows.admin_override`, `workflows.cron_token`,
`workflows.announcements.enabled`

---

### 🛰️ API — `/api/` 🟡

Read-only JSON list endpoints over `Portal\Core\ApiRouter`.

- `/api/attendance/list`
- `/api/announcements/list`
- `/api/users/list`
- `/api/events/list`, `/api/events/detail`
- `/api/calendar/hub-resources`, `/api/calendar/hub-videos` — Event Team Hub read endpoints, scope `eventhub:read` (#387)

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
| `/help/admin` | Settings, user roles, site branding, captcha config |
| `/help/translations` | Language + i18n |
| `/help/prayer-requests` | Prayer requests lifecycle, anonymous route, moderation |
| `/help/forms` | Field types, building/publishing a form, the public link, responses/CSV export, privacy |
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
- Dyslexia-friendly reading-mode toggle (#46) — British Dyslexia Association style guide: clean system sans-serif (no web font fetched, CSP-safe), wider letter/word spacing, taller line height, left-aligned body text. Opt-in, persisted per-browser (`portal-read`), applied pre-paint by the header FOUC script. Sits beside the theme + CB toggles in the nav.
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
| Login rate limiting on composite IP+username | ✅ (#52) |
| Signed commits enforced | 🔜 (#106) |
| Prod secrets behind GitHub Environment + reviewer gate | 🔜 (#105) |
| Privacy / GDPR helpers | 🔜 (#47) |
| 2FA TOTP available | ✅ (#92) |

### 📍 Location & maps (#456) ✅ (foundation + PII/GDPR)

Full address + geocoordinates + what3words platform layer, shared as a
cross-repo data-format CONTRACT with ProjectBookIT/ProjectEPass (identical
column shapes, canonical `location` JSON wire object, what3words canonical
form) — but each repo is fully standalone: **no runtime dependency on
another repo, ever**.

- `Portal\Core\GeoLocation` — address normalise/format (mirrors
  `Venues::saveVenue()`'s rules exactly), DECIMAL(10,7) coordinate
  validation/coarsening, what3words canonicalisation (`word.word.word`,
  no leading `///`, Unicode-aware), map link-outs (Directions / OSM /
  `///w3w`), and the `toLocationObject()`/`fromLocationObject()` wire
  serializer. Pure value/service class — zero network.
- `Portal\Core\What3Words` — v3 API client (`convertTo3wa`,
  `convertToCoordinates`, server-proxied `autosuggest`, `testConnection`).
  Key passed as a query-string param, never logged. Default OFF
  (`w3w.enabled`) — the flag gates ONLY the API; the `///word.word.word`
  input field is always present as a manual-entry fallback.
- `Portal\Core\Geocoder` — Google primary → OpenStreetMap Nominatim
  fallback, forward + reverse. Nominatim policy compliance built in:
  descriptive User-Agent, ≤1 request/second throttle (persisted
  `geo.nominatim.lastCallAt`), and a `tblGeocodeCache` result cache (an
  address geocodes once, ever). `geo.autoGeocode` default OFF; a manual
  "Look up coordinates" action always works regardless.
- Interactive map: Leaflet 1.9.4 from `cdn.jsdelivr.net` with SRI (hashes
  independently re-derived from the npm registry tarball and matched
  exactly — see DEV_NOTES for the verification method + upgrade
  procedure). Inline SVG marker icon (no external icon assets needed —
  keeps `img-src` limited to the OSM tile host). Tiles via the existing
  per-page `$cspImgExtra` CSP hook, set only on pages that will render a
  map with coordinates present.
- Three new shared partials (`web/_core/partials/` — first in the
  codebase): `location-display.php` (label + address + link row + map),
  `location-input.php` (address/coords/W3W fields, configurable field
  names for legacy columns, optional lookup button + W3W autosuggest),
  `location-map-assets.php` (the Leaflet tags + one nonce'd init script,
  included once per map-bearing page).
- Admin pages: `/admin/integrations/what3words`,
  `/admin/integrations/geocoding` (both with a "Test connection" action),
  `/admin/settings/organisation` (site-HQ address, nine `org.*` settings —
  the `portal.sabbath.location_lat/lng` precedent).
- Two session-authed AJAX proxies (`/geo/w3w-suggest`, `/geo/lookup`) —
  deliberately outside `api/*` so the browser never sees either API key.
- **Wired into (Chunk A, non-PII):** Venues (structured address +
  coords/W3W + interactive map on the detail page), Events (existing
  `locationGeoLat/locationGeoLng/locationW3W` columns now validated on
  save with a W3W dual-mode verify/fill, JSON-LD `geo`, interactive map on
  the event page, canonical `location` object additively emitted by the
  events REST API's create/update/list/detail — kept alongside the legacy
  `locationName` field for backward compatibility), Event occurrence
  overrides (hand-entered `overrideGeoLat/overrideGeoLng/overrideW3W`,
  `NULL` = inherit the parent event), Resources, Asset Locations.
- **Chunk B — member PII + GDPR lockstep** (migration 181, `tblUsers`
  ONLY: `latitude`/`longitude`/`what3words` + a dedicated
  `visibilityCoords` ENUM tier, default `'private'`, INDEPENDENT of the
  existing `visibilityAddress` — sharing address text never implies
  consent to show a map pin). Deliberately NO `geocodedAt`/`geocodeSource`
  on `tblUsers` — a member's own coordinates are NEVER auto-geocoded, only
  hand-entered or set via their own explicit "Look up coordinates" click.
  Capture on the owner edit surface (`directory/me.php`); display
  (`directory/profile.php`) gates coords through a SEPARATE
  `$can($u['visibilityCoords'])` check stricter than the address text: the
  owner/admin sees full precision + the exact what3words, any other
  permitted viewer sees coordinates coarsened to 3dp (~110m) with an
  "Approximate location" badge and the what3words value suppressed
  entirely (a 3m-precise W3W square cannot be meaningfully coarsened). The
  pre-existing `$can()` "team tier behaves as private" quirk is inherited
  verbatim. GDPR lockstep shipped in the SAME PR as the schema: the export
  (`/account/data-export`) gained a `giftAidDeclarations` block (closed a
  pre-existing gap — Gift Aid address PII was never exported at all); the
  self-service delete path (`delete-confirm.php`) now also nulls
  `displayAddress`/`displayPhone` (a separate pre-existing miss) plus the
  four new columns; the admin erasure catalogue (`GdprEraser::
  catalogue()`) extended to null the three PII coordinate/W3W columns.
  GiftAid/Salvation reuse the shared input partial in TEXT-ONLY mode (no
  coordinates, no new columns, no new erasure surface). Kids/Care/
  Visitors remain permanently excluded (safeguarding apps, no consent
  mechanism) — verified by a diff-level grep, zero references.
- Migration 180 (Chunk A): `latitude/longitude/what3words/geocodedAt/
  geocodeSource` on `tblVenues`/`tblResource`/`tblAssetLocations`;
  `overrideGeoLat/overrideGeoLng/overrideW3W` on
  `tblEventOccurrenceOverrides`; new `tblGeocodeCache`; 14 settings seeds
  (all default OFF/empty); 10 route seeds. Migration 181 (Chunk B): four
  columns on `tblUsers` only, no settings/route seeds. A fresh upgrade is
  a full no-op until an admin opts in / a member sets their own coords.

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
- `dry_run` `workflow_dispatch` input on `deploy.yml` for preview-mode deploys (#107 — mostly done; residual: server-side `--delete` deletion-log/audit monitor).
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
| 042 | Calendar `defaultView` setting (#137) |
| 043 | Calendar category colour + displayStyle, month themes (#138) |

---

## In-flight PR stack

| PR | Title | Status |
| --- | --- | --- |
| #137 | Calendar seven view modes (closed #136) | ✅ Merged |
| #138 | Calendar month themes + category display-style (stacked on #137) | ✅ Merged |

When these merge, the 🛠️ markers above flip to ✅ without further edits to this file — language is already written in the past tense.

---

## Tracked but not started

| Issue | Scope |
| --- | --- |
| #127 | WordPress Multisite integration — design + phased implementation (3–4 weeks) |
| #97–#103 | BookIT calendar-provider abstraction (7-PR series) |
| #47 | Privacy & GDPR compliance helpers |
| #40 | Payment integration prep |
| #106 | Enforce signed commits |
| #105 | Prod secrets behind GitHub Environment + reviewer gate |
| #107 | SFTP `--delete` operational documentation — mostly done (dry-run + docs shipped via PR #134); residual: server-side deletion-log/audit monitor |
| #299 | Giving polish — account-updater webhook for recurring giving (sub-features 1-3 — two-person offering count, pledge campaigns, bank reconciliation — all shipped, see "Giving" section above) |

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
| Service Plans (run-sheet builder, printable; live runtime + confidence monitor + operator→monitor messaging, #300) | #262 | 089 | ✅ |
| Livestream (YouTube/Vimeo/Twitch/Facebook embed + countdown) | #273 | 090 | ✅ |
| Recordings (RSS podcast feed + HTTP Range streaming + FULLTEXT search) | #264 | 091 | ✅ |
| Zoom (OAuth, meeting creation from calendar, webhook HMAC) | #274 | 092 | ✅ |
| Newsletter (composer with auto-pulled content blocks, provider abstraction → MailerMatt slot) | #269 | 093 | ✅ |
| Giving (tithe log, Gift Aid digital declaration, HMRC schedule CSV, year-end PDF; online give-online checkout added later, see "PayPal payment adapter" section below; treasurer bulk year-end statements added later, see "Giving — Bulk year-end statements" section below) | #266 | 094 | ✅ |
| SMS (Twilio + MessageBird + SigV4-signed AWS SNS; verification + per-category opt-in + Sabbath quiet hours) | #272 | 095 | ✅ |
| Projects (public fundraising page, pledge thermometer, captcha-gated anonymous pledges; member "Pay now" pledge checkout added later, see "PayPal payment adapter" section below) | #267 | 096 | ✅ |
| Payments (Stripe Checkout + PayPal Orders v2 + v1 HMAC/verified webhooks + refund; side-effects into Giving/Projects — PayPal + online checkout UI added later, see "PayPal payment adapter" section below) | #268 | 097 | ✅ |

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
| REST API write-side CRUD: Announcements / Tasks / Prayer Requests / Leadership (10 new endpoints) | #157 | #291 | ✅ (remaining Documents / Attendance / Expenses CRUD landed via #323 Phase 2, below) |
| PWA offline write queue + sync-on-reconnect (`Portal.OfflineQueue` IndexedDB module + `/account/offline-queue`) | #233 | #292 | ✅ |
| Codebase audit sweep — duplicate cookie banner removed; missing `Auth` import fixed; 6 SQL int-concat queries → prepared statements | — | #293 | ✅ |

### REST API v1 write surface (PR #372, 2026-07-22)

| Item | Issue | PR | Status |
|---|---|---|---|
| Dual-mode `ApiAuth` (bearer API key OR session) + `/api/v1/{resource}[/{id}]` RESTful facade over the existing `{app}/{action}` handlers; per-key rate limiting; tenant pinning via `Site::forceContext` | #323 Phase 2 | #372 | ✅ |
| New write endpoints: Attendance + Documents (create/update/delete), Expenses (create/delete), Users (create/update, admin-gated + default-off flags) | #323 Phase 2 (#157 remnant) | #372 | ✅ (Expenses status-transition update deferred to Phase 3) |
| Canonical `ApiKey::SCOPES` vocabulary + rotation grace windows; admin API-keys UI scope checkbox multi-select (server-validated) + grace selector + "rotated" badge; audit viewer source (session/apikey) badge + key-prefix | #323 Phase 2 | #372 | ✅ |
| OpenAPI spec (`api-spec.json`) documents every `/api/v1/*` path + `bearerAuth` scheme alongside the existing legacy aliases | #323 Phase 2 | #372 | ✅ |
| Outbound webhooks admin CRUD UI | #324 | #372 | ✅ |

---

### Giving — two-person offering count session (#299 sub-feature 1, 2026-07-22)

Extension to the existing `giving` app (#266). #299 bundles four "Giving polish"
sub-features (offering counting, pledge campaigns, bank reconciliation,
account-updater) — only sub-feature 1 is built; the other three remain
tracked-but-not-started.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `tblCountSessions` — per-service-date session; two counters independently key cash/cheque/envelope totals, auto-compared, `status` ENUM('open','counting','discrepancy','closed') | #299 | 150 | ✅ |
| Discrepancy flagging — any mismatch between the two independent counts blocks close until a counter re-enters matching totals or an admin (`App::isAdmin()`) resolves with agreed totals | #299 | 150 | ✅ |
| `tblCountEnvelopes` — named/numbered giving-envelope breakdown of the agreed envelope total | #299 | 150 | ✅ |
| Close (`/giving/count/close`) — validates named envelopes reconcile to the agreed envelope total, then writes the gift log to `tblGivingEntry` in one transaction: one row per named envelope + aggregate "loose cash"/"loose cheque" rows for anything not itemised | #299 | 150 | ✅ |
| UI: `/giving/count` (list + start), `/giving/count/session` (counter entry, comparison, resolve, envelopes, close) — gated by `Portal\Core\Giving::canManage()` | #299 | 150 | ✅ |

---

### Giving — pledge campaigns (#299 sub-feature 2, 2026-07-22)

Extension to the existing `giving` app (#266). Bank reconciliation and the
account-updater webhook remain the two not-started #299 sub-features.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `tblPledgeCampaigns` — goal amount, currency, date window, active flag | #299 | 151 | ✅ |
| `tblPledges` — one row per member per campaign, `UNIQUE (campaignID, userID)` upsert (re-pledging, including after cancellation, updates the same row) | #299 | 151 | ✅ |
| Auto-attribution — `tblGivingEntry.campaignID`/`pledgeID` (nullable, `ON DELETE SET NULL`) instead of a link table; `Portal\Core\Giving::attributeGift()` is the sole code path that sets them: explicit treasurer choice (honoured even outside the campaign window), or "Auto" only when the donor holds exactly ONE open pledge to a currently active, in-window campaign (2+ matches is left unattributed — never guessed) | #299 | 151 | ✅ |
| Hooked into both manual `tblGivingEntry` writers: `giving/entry-save.php` (new Campaign selector — Auto/None/explicit) and the offering-count close path (named-envelope rows only) | #299 | 151 | ✅ |
| `Giving::pledgeExpectedToDate()` — on-schedule progress math; one-off owes in full immediately, weekly/monthly owe their first instalment from the pledge's start, monthly uses calendar-month arithmetic | #299 | 151 | ✅ |
| UI: `/giving/campaigns` (card grid + thermometers + canManage new-campaign form), `/giving/campaign` (detail: thermometer, stats, member pledge/cancel form, canManage pledger list + attributed gifts + edit form) | #299 | 151 | ✅ |
| `Projects.php`/`Payments.php` online/project-pledge giving now also auto-attributed — `Giving::attributeGift()` (Auto) called before each automatic `tblGivingEntry` INSERT, using the same siteID + gift date the row is stamped with; anonymous/no-user donor (`<= 0`) passed as `null`, never `0` | #299 follow-up | 151 | ✅ |

---

### Giving — bank reconciliation (#299 sub-feature 3, 2026-07-22)

Extension to the existing `giving` app (#266). Only the account-updater
webhook for recurring giving remains a not-started #299 sub-feature.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `tblBankImports` + `tblBankTxns` — one row per uploaded statement CSV batch, one row per imported CREDIT line (debits never stored); `matchedCount` deliberately not a stored column (derived via aggregate join) | #299 | 152 | ✅ |
| CSV import (`/giving/reconcile/import`) — header-NAME column mapping (never positional) against a UK-bank alias table, with a manual mapping screen when auto-detection can't resolve every required column; SHA-256 `fileHash` + `UNIQUE(siteID, fileHash)` blocks duplicate imports; a non-empty credit that fails amount/date parsing fails the WHOLE upload (no partial imports) | #299 | 152 | ✅ |
| Matching — exact-amount, window-based (`giving.reconcile.toleranceDays`, default 5 days) with two nullable FKs on `tblBankTxns`: `matchedEntryID` (1:1 gift match) or `matchedCountSessionID` (whole offering-count deposit); 2+ equal-amount in-window candidates is always left unmatched, never guessed; count-close's gift-log rows (`reference LIKE 'Count #%'`) excluded from entry-matching to avoid double-counting against their deposit | #299 | 152 | ✅ |
| UI: `/giving/reconcile` (imports dashboard + site-wide unmatched summary), `/giving/reconcile/view` (matched/unmatched/ignored lists, inline match-suggestion mini-forms, two-way "gift log not in this statement" gap panel with in-transit-vs-missing badges), `/giving/reconcile/match` (manual match/unmatch/ignore/rematch/delete-import) — gated by `Portal\Core\Giving::canManage()`; "Count"/"Reconcile" nav buttons added to `giving/manage.php` | #299 | 152 | ✅ |

---

### Giving — Bulk year-end statements (gap #4, #440, 2026-08-28)

Treasurer-only batch generate + email of year-end giving statements at
`/giving/statements`, reusing (and hardening) the self-service PDF
renderer so both paths emit byte-identical output — see DEV_NOTES.md
"Giving — Bulk year-end statements" for the full design rationale.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `Giving::renderStatementPdf()` generalised to `(siteId, donorId, from, to, label)` — site-scoped donor lookup (active membership OR giving history at the site, closing a latent cross-tenant render hole), Gift-Aid-eligible column + summary (EXISTS, never a JOIN — no double-count), output path namespaced by `{siteID}/{periodKey}` (fixes a cross-site filename overwrite); `giving/my-statement.php` is the only other caller and now maps its `?year=` into the same call | #440 | 172 | ✅ |
| `tblGivingStatementLog` — one row per `(siteID, donorID, periodKey)`, `UNIQUE`-keyed dedupe/audit log (`pdfPath`/`queuedAt`/`emailedAt`/`emailedTo`/`errorMsg`) | #440 | 172 | ✅ |
| Batch generate (`/giving/statements-generate`, POST) + batch email (`/giving/statements-email`, POST) — capped at `giving.statements.batchPerRun` (default 25) per invocation, Newsletter-dispatch pattern, re-trigger to continue; explicit audit-logged "resend to already-emailed donors" override; per-row manual-only "retry" clears a stuck `errorMsg` | #440 | 172 | ✅ |
| ZIP download (`/giving/statements-download`, GET) — `ZipArchive` bundle of the period's generated PDFs (never a combined PDF), degrades to per-row PDF links when `ZipArchive` is unavailable; per-donor single-PDF download also served from this route | #440 | 172 | ✅ |
| Optional token-gated sweeper `cron/giving-statements.php` (`giving.cron_token`, empty ⇒ 403 fail-closed) — sweeps queued-but-unsent rows across every site for a larger donor list that would otherwise need many manual re-triggers | #440 | 172 | ✅ |
| `givingStatements` notifyPrefs opt-out (default on) — enforced at both queue and live send time; GDPR erasure additionally unlinks the erased donor's rendered statement PDF files from disk (`GdprEraser::eraseGivingStatementFiles()`) | #440 | 172 | ✅ |

---

### Discipleship Pathway Tracker Phase 2 — per-user progress + auto-completion (#303 Phase 2, 2026-07-22)

Extension to Phase 1 (migration 142, admin CRUD only, app OFF by default via `discipleship.enabled`). Adopted the three recommended resolutions from issue #303's blocker comment: (1) auto-complete ONLY from per-user evidence tables — `tblSalvationCards`/`tblDecisionMoments` structurally excluded; (2) pastor surface stays a flat roster list, never a members×steps `<table>` matrix; (3) mentor relationships deferred to a later phase.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `tblPathwayEnrolments` — who is assigned to which pathway (`status` active/completed/withdrawn); an explicit table rather than inferred from progress rows, so a member with ZERO completed steps still shows up | #303 Phase 2 | 153 | ✅ |
| `tblPathwayProgress` — one row per (step, member); `UNIQUE(stepID, userID)`; unmark = `revokedAt` set, NEVER a DELETE (a deleted row would let the auto-sweep resurrect a step a coordinator deliberately unmarked) | #303 Phase 2 | 153 | ✅ |
| `tblPathwaySteps.autoRule`/`autoRefID` (guarded ADD COLUMN) — optional per-step rule: `attended_event`, `attended_category`, or `rsvpd_event` (an RSVP only counts once the event has started) | #303 Phase 2 | 153 | ✅ |
| `Portal\Core\Discipleship::autoSweep()` — three set-based `INSERT IGNORE … SELECT` statements (one per rule), idempotent via the unique key, then `refreshEnrolmentStatuses()` flips `active ⇄ completed` from the current progress state; lazily invoked on every discipleship page view (no scheduler dependency), plus an optional `cron/discipleship-sweep.php` (token-gated like `reminders.cron_token`) for freshness without page views | #303 Phase 2 | 153 | ✅ |
| Member routes fixing the Phase 1 dead dashboard link: `/discipleship` ("My pathways" + progress bars) and `/discipleship/view` (step list, auto/manual badges) — every query scoped to `Site::id()` AND `$_SESSION['user_id']`; parameter-tampered `?id=` 404s rather than leaking another member's progress | #303 Phase 2 | 153 | ✅ |
| Admin/pastor routes: `/admin/discipleship/progress` (pathway list + enrolment counts), `/admin/discipleship/progress/pathway` (roster list + enrol/withdraw), `/admin/discipleship/progress/member` (per-member mark-complete/unmark + notes, auto-evidence, revocation state) | #303 Phase 2 | 153 | ✅ |
| `pathway-form.php`/`step-save.php` extended with the `autoRule` select + site-scoped event/category ref picker; `step-save.php` validates the ref resolves at THIS site before saving; a stale ref (event/category later deleted — deliberately no FK) renders a "(missing)" warning | #303 Phase 2 | 153 | ✅ |
| `GdprEraser` catalogue registration for both new per-user tables (hard delete; `markedByID`/`enrolledByID`/`revokedByID` attributions self-heal via `ON DELETE SET NULL`) | #303 Phase 2 | 153 | ✅ |
| Mentor relationships — deferred (no `tblPathwayMentor` schema, no UI) | #303 | — | 🔜 (Phase 3) |

---

### Service Plans — operator → confidence-monitor messaging (#300 v2, 2026-07-23)

Closes the last open piece of #300. v1 (migration 110) shipped `/service-plans/live` (operator clock + start/close) and `/service-plans/confidence` (full-screen speaker-facing clock) with the message channel deferred. Issue #300 explicitly blessed a polling fallback ("fall back to polling if it doesn't play nice with DreamHost") — v2 uses plain JSON polling, no SSE, no new dependencies.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `tblServicePlanMessages` — one row per operator message; `isCleared`/`clearedAt` rather than DELETE, so the live view stays the full audit record of how the service ran; indexed `(planID, isCleared, messageID)` for an O(1) poll | #300 v2 | 154 | ✅ |
| `/service-plans/live-message` — admin-only POST (CSRF-checked), `action=send` (rejected once the plan is closed) / `action=clear` (allowed even after close); plain form + 303 redirect back to `/service-plans/live`, matching the app's only existing submit idiom (`live-toggle.php`) | #300 v2 | 154 | ✅ |
| `/service-plans/message-poll` — GET-only JSON poll, any logged-in user (same gate as `confidence.php`), `Cache-Control: no-store`, `ApiResponse::success()` envelope; `sinceID`/`lastID` dedup short-circuits to `changed:false` so an unchanged poll re-sends no payload | #300 v2 | 154 | ✅ |
| `live.php` operator panel — current active message + "Clear from monitor" form + send form (`maxlength=255`, `mb_substr` server-side cap); send form hidden once the plan is closed | #300 v2 | 154 | ✅ |
| `confidence.php` banner — polled every 4s (matching the `livechat-widget.js` house cadence), high-contrast themed banner with a reduced-motion-guarded pulse; message body injected via `textContent` only, NEVER `innerHTML` — the client-side XSS line of defence alongside the server's `htmlspecialchars()` escaping on `live.php` | #300 v2 | 154 | ✅ |
| Every query siteID-scoped (`Site::id()`); a plan at another site polling the same `planID` gets `message: null`, never another site's data | #300 v2 | 154 | ✅ |
| No new `tblSettings` — plain `service-plans/*` page routes (not under `api/*`), inheriting the existing `service_plans.enabled` app gate | #300 v2 | 154 | ✅ |

---

### Event Team Hub Phase 1 — per-event staff/volunteer/organiser portal (#386, 2026-08-11)

Extends the calendar app (Calendar/Events/Preaching Plan is ONE app per `.claude/CLAUDE.md`) — not a new top-level app — with a "Team Hub" landing page at `/calendar/event/hub?eventID=N` that gathers a Resources list, a video grid, the viewer's own crew/job/role roster context, and (for coordinators/admins) a tool strip linking the previously-unlinked crews/jobs/attendance/broadcast/registrations pages. Phase 1 video handling is external references only (paste a YouTube/Vimeo URL or a Cloudflare Stream UID) with signed-URL playback; the Cloudflare *management* API (direct uploads, `CloudflareStream` class) is a Phase 1.5 follow-up — `tblEventHubVideos` already ships its final shape so no future ALTER is needed.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `tblEventHubResources` — links/notes grouped by a free-text `section`; notes rendered via `Portal\Core\Markdown::render()` (escaped-first) | #386 | 155 | ✅ |
| `tblEventHubVideos` — final shape from day one, incl. Phase-1.5 upload-lifecycle columns (`uploadStatus` default `'external'`, `errorDetail`, `uploadedAt`, `lastCheckedAt`, `allowedOrigins`) so Phase 1.5 needs no ALTER | #386 | 155 | ✅ |
| `Portal\Core\VideoEmbed` — allowlist `parse()` (YouTube/Vimeo/Cloudflare URL or bare ID → provider+ref, never an arbitrary raw URL), `embedUrl()`, `frameSrcOrigins()` for the page-scoped `$cspFrameExtra`, and `signedToken()` — hand-built RS256 JWT via `openssl_sign()` (the vendored `simplejwt` is verify-only) for Cloudflare Stream signed-URL playback | #386 | — | ✅ |
| `Auth::isEventTeamMember()` — coordinator OR crew leader/participant OR job assignee OR `tblEventPeople` row; broader than `isCoordinatorOf()` (view vs. manage) | #386 | — | ✅ |
| `/calendar/event/hub` (view, any team member) + `/calendar/event/hub/save` (POST, coordinator/admin only — `addResource`/`editResource`/`removeResource`/`addVideo`/`removeVideo`/`reorder`) | #386 | 155 | ✅ |
| Admin `admin/integrations/cloudflare-stream` — full `cfstream.*` field list (incl. `apiToken`, reserved for Phase 1.5) seeded now so the Phase 1.5 upload build needs no follow-up migration; two-credential model (signing key vs. API token) explained on the page; secrets never re-displayed, blank input preserves the existing value | #386 | 155 | ✅ |
| Entry points: "Team Hub" button on `my-events.php` rows and on the event page (`event.php`) for any viewer passing `canView` | #386 | — | ✅ |
| CF videos whose signing key is unconfigured render an "unavailable — check Stream settings" tile, never a broken iframe | #386 | — | ✅ |
| `Portal\Core\CloudflareStream` — management-API client (`createDirectUpload`/`getVideo`/`updateVideo`/`deleteVideo`); Bearer `cfstream.apiToken`, TLS at cURL defaults, token never logged | #386 | 156 | ✅ (Phase 1.5) |
| Direct browser→Cloudflare upload — `calendar/event/hub/upload-url` mints a one-time URL (per-user hourly rate limit via `tblActivityLogs`), `/video-status` polls readiness, `/video-settings` edits Require-Signed-URLs/Allowed-Origins **CF-first**; basic ≤200 MB (tus deferred), file never touches the server; `event-hub-upload.js` enforces the size cap + host allowlist + CSRF-rotation tracking | #386 | 156 | ✅ (Phase 1.5) |
| Core `$cspConnectExtra` — page-scoped `connect-src` widening (identical pattern to `$cspFrameExtra`); the hub adds Cloudflare's upload hosts only for a manager on a configured install, every other page byte-identical | #386 | — | ✅ (Phase 1.5) |

**Tables:** `tblEventHubResources`, `tblEventHubVideos`
**Settings:** `cfstream.enabled`, `cfstream.accountID`, `cfstream.customerCode`, `cfstream.apiToken`, `cfstream.signingKeyID`, `cfstream.signingKeyPem`, `cfstream.tokenTtlSeconds`, `cfstream.maxUploadDurationSeconds`, `cfstream.uploadMintPerHour`, `cfstream.defaultRequireSignedUrls`, `cfstream.allowedOrigins`

---

### Event Team Hub REST API read endpoints — projectBookIT Phase 3 integration (#387, 2026-08-12)

Exposes the Event Team Hub tables shipped in #386 (migration 155) to external integrations, built for the projectBookIT Event Team Hub Phase 3 consumer (projectbookit#347). Read-only; no schema changes.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `_apps/calendar/api/hub-resources.php` — `GET /api/calendar/hub-resources?eventID=`, returns that event's `tblEventHubResources` rows (`resourceID, section, resourceType, title, url, body, sortOrder`), ordered `section, sortOrder, resourceID` | #387 | 157 | ✅ |
| `_apps/calendar/api/hub-videos.php` — `GET /api/calendar/hub-videos?eventID=`, returns that event's `tblEventHubVideos` rows (`videoID, provider, videoRef, title, requiresSignedUrl, allowedOrigins, uploadStatus, sortOrder`), ordered `sortOrder, videoID`. Never emits a signing key, playback token, or any `cfstream.*` credential — `videoRef` is the public provider ID/UID a player embeds against | #387 | 157 | ✅ |
| Both mirror the `events/list.php`/`detail.php` dual-mode-auth pattern (`ApiAuth::requireRead('eventhub:read')`) with an explicit tenant guard — the requested event must belong to `Site::id()` or the endpoint 404s, never leaking another tenant's event | #387 | — | ✅ |
| New bearer scope `eventhub:read` added to `ApiKey::SCOPES` — mintable immediately, surfaces in the Admin → Integrations → API Keys checkbox grid with no other UI changes needed | #387 | — | ✅ |
| Settings-only migration — `api.calendar.hub-resources.enabled` / `api.calendar.hub-videos.enabled` seeded `'true'`; NO `tblRoutes` rows (`api/*` paths are dispatched directly by `ApiRouter`, never via `tblRoutes` — see .claude/CLAUDE.md → "ApiRouter routing trap") | #387 | 157 | ✅ |
| OpenAPI — new `Event Team Hub` tag, `EventHubResource`/`EventHubVideo` schemas, both `GET /api/calendar/hub-*` paths documented in `_core/api-spec.json` | #387 | — | ✅ |

---

### Discovery-pass fold-in batch — ApiRouter fix, worship live-sync, AppRegistry completion (#373, #339, #308, #255, #386, 2026-08-14)

A reviewed batch of correctness fixes + small enhancements surfaced by a discovery pass over `claude/alpha-enhancements`. All grouped into one migration (158) + one `full_schema.sql` fold.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `ApiRouter::dispatch()`/`dispatchV1()` now import `global $mysqli, $SETTINGS;` immediately before including a handler — mirrors the `Router::dispatch()` fix for #373, which `ApiRouter` never got. Unbreaks 6 live handlers that fatally errored on bare `$mysqli`: `livechat/api/{send,list,moderate,prompts,prompt-publish}.php` + `livestream/api/ping.php` | #373 | — | ✅ |
| `calendar/manage/save.php` create-flow slug-uniqueness probe now scopes `AND siteID = ?` — closes the #339 residual (schema half shipped in migration 112) | #339 | — | ✅ |
| Worship live-sync relocated: `/api/worship/state` + `/api/worship/advance` moved from the unreachable legacy `_apps/api/worship-{state,advance}.php` (dead `tblRoutes` rows, no enable flags) to the ApiRouter convention path `_apps/worship/api/{state,advance}.php` — the operator console ↔ projector display sync (#308) was unreachable before this fix | #308 | 158 | ✅ |
| AppRegistry entries added: `_core/apps/{noticeboard,worship,salvation,kids}.php` — all four now surface in `/admin/apps` (37 → 41 registered apps). `worship.enabled` / `salvation.enabled` / `kids.enabled` seeded `'true'` (previously no enable flag at all — always-on by virtue of not being registered; registering without seeding would have silently 403'd all three) | #255 | 158 | ✅ |
| Dead `api/*` `tblRoutes` cleanup — 19 rows across migrations 035(→056)/082/099/100/106/111/133/138 that `ApiRouter` can never reach via `tblRoutes` removed (`DELETE ... WHERE routeKey IN (...)`, idempotent); matching rows pruned from `full_schema.sql`'s seed blocks. No `api.*.enabled` settings touched; orphaned `_apps/api/{tours,push,translate,ai-improve}.php` handlers left parked (no live caller) | — | 158 | ✅ |
| `Portal\Core\CloudflareStream::testConnection()` — minimal `GET /accounts/{acct}/stream?per_page=1`, machine-safe `{success,message}` only (never Cloudflare's raw error text) — plus a "Test connection" button on `/admin/integrations/cloudflare-stream` (new `test.php` handler, admin+CSRF gated) | #386 | 158 | ✅ |

---

### Asset Tracker — physical & digital asset register, Phase 1 complete (#393-#403, 2026-08-16)

New top-level app at `/assets`, slug `assets` — a register of physical and digital assets (equipment, furniture, vehicles, software licences, subscriptions) with ownership, lending, maintenance, identifiers, licence seats, printable QR labels, and a public lost-and-found page. 13-table schema (`tblAsset*`) shipped whole in one foundation migration (159); every subsequent sub-issue built CRUD/UI on top of it without further schema churn, closing out with docs/help/CSV export.

| Item | Issue | Migration | Status |
|---|---|---|---|
| Foundation — 13-table schema, `AppRegistry` entry, route/setting seeds (with documented stub handlers for not-yet-built sub-issues), `AssetRegister::audit()` choke-point every mutation writes through (redacts `licenseKey`/`publicToken` from change-sets) | #393 / #395 | 159 | ✅ |
| Register CRUD — list/create/edit/soft-delete, categories, locations, file/link resources, `_uploads/assets/` uploads | #394 | 159 | ✅ |
| Co-ownership — owner/custodian parties (user, department, group, OR an external organisation via `tblAssetOrgs`), fractional share, per-owner lending/maintenance authority flags, and the confidential "Ownership & legal documents" vault (agreement/insurance/legal resources, visible only to managers + responsible owner-parties) | #396 | 159 | ✅ |
| Identifiers — GS1 key family (GIAI/GRAI/GTIN/GLN/SSCC/…), retail barcodes (EAN/UPC/ITF-14), RFID/EPC carrier codes; format/check-digit validation is a non-blocking warning, never a hard reject | #397 | 159 | ✅ |
| Loan register — lend (out) and borrow (in) directions, request → approve/decline → check-out → check-in lifecycle, condition captured at both check-out and check-in, swap-chain support via `parentLoanID` | #398 | 159 | ✅ |
| Maintenance log — service/repair/inspection/calibration/upgrade history, cost + next-due tracking, plus a display-only straight-line depreciation estimate on the asset page (never persisted, never invented when the inputs are incomplete) | #399 | 159 | ✅ |
| Software licences & seats — encrypted licence key (manager-only reveal), per-device/per-user/free-text seat assignment ledger with active/released history, seat-usage summary (used/free/over-allocated) | #400 | 159 | ✅ |
| Public lost-and-found — `/a/{token}` public page (`Router` special-case, not a `tblRoutes` row) with a uniform-404 access model so confidential/disabled/unknown tokens are indistinguishable from the outside; anonymous "I found this" submissions (CSRF + Captcha + honeypot + rate-limited) land in a manager triage queue | #401 | 159 | ✅ |
| QR asset-tag labels — GET-only live-preview label designer (size preset, field selection, QR/barcode symbology, sheet start-offset, copies) plus a PDF generator sharing the exact same layout engine as the preview | #402 | 159 | ✅ |
| In-app Help guide at `/help/assets` (plain-English, no jargon) + a Help Centre index card; register CSV export (`?export=csv` on the existing index page — no new route) honouring the same manager-gated confidential filter as the HTML view, excluding `licenseKey`/`publicToken`/every other secret column, logged via the activity trail | #403 | 159 (route seed appended) | ✅ |

**Tables:** `tblAssetCategories`, `tblAssetLocations`, `tblAssetOrgs`, `tblAssets`, `tblAssetOwners`, `tblAssetLoans`, `tblAssetMaintenance`, `tblAssetResources`, `tblAssetFoundReports`, `tblAssetIdentifierTypes`, `tblAssetIdentifiers`, `tblAssetLicenseAssignments`, `tblAssetAudit`
**Settings:** `assets.enabled`, `assets.maxFileSize`, `assets.public_page_enabled`, `assets.found_report_retention_days`, `assets.license_seat_block`, `api.assets.qr.enabled`

Phase 2 (#404-#410, migration 160) followed on directly: label-PDF polish, scan-log + "my assets" + reminder-log foundation, the reminder sweep + value dashboard cron, asset ↔ event assignments, and insurance fields — see `AssetRegister.php`'s class header points 8-13 for the full breakdown. Phase 3 below closes out the two Phase 1 limitations (stocktake and kiosk mode) and adds depreciation history, kits, and the GS1 Digital Link/GEPIR resolver.

### Asset Tracker Phase 3 — stocktake, kits, depreciation history, kiosk mode, GS1 Digital Link/GEPIR (#411-#415, 2026-08-17)

| Item | Issue | Migration | Status |
|---|---|---|---|
| Depreciation value history — `tblAssetValueHistory` snapshot table; `AssetRegister::computeReducingBalanceValue()` (constant-percentage reducing-balance, no `depreciationRate` column — the fixed percentage is derived from cost/salvage/useful-life) alongside the existing straight-line method; item.php's read-only Value History panel is populated by the `#405` cron's write-on-change-only snapshot | #412 | 161 | ✅ |
| Parent/child kits — `tblAssets.parentAssetID` (already existed, migration 159) now UI-wired: one-level-deep kit hierarchy (a component cannot itself have components), `AssetRegister::attachKitChild()`/`detachKitChild()`/`kitChildren()`/`eligibleKitChildCandidates()`, and kit-aware loans (`cascadeKitCheckout()`/`cascadeKitCheckin()`) that hand a top-level asset's WHOLE kit out/back together, with a per-component return condition captured at check-in | #413 | 161 | ✅ |
| Stocktake / scan-to-verify — `tblAssetStocktakes` + `tblAssetStocktakeItems`; a manager opens a run (optionally scoped to one location/category), scans assets against the expected set, and `verifyStatus` tracks pending/present/missing/moved/unexpected per asset until the run is closed | #411 | 161 | ✅ |
| Kiosk self check-in/out — unattended, PUBLIC shared-terminal mode (`tblAssetKioskTokens` per-device credential + `tblAssetKioskPins` per-user PIN); `/assets/kiosk`/`kiosk-action` carry NO login session of their own — identity lives in separate `kiosk_*` session keys, idle-timeout auto-checkout, and every action audits with `actorType: 'kiosk'` attributed to the PIN-resolved user | #414 | 161, 162 | ✅ |
| GS1 Digital Link resolver + GEPIR verify — public `01/{gtin}`, `8003/{grai}`, `8004/{giai}` short URLs (a `Router::handleSpecialRoutes()` block, NOT a `tblRoutes` row) resolve straight to the matching asset's existing `/a/{token}` public page, globally/cross-site, NEVER matching a confidential asset (excluded in the SQL itself — no oracle); a manager-only "Verify" button on item.php's Identifiers panel runs the local GS1 mod-10 check-digit always, plus an optional GEPIR registry lookup when `assets.gepir_verify_enabled`/`assets.gepir_endpoint` are configured (off by default), caching the outcome in `verifiedAt`/`verifyNote` | #415 | 161 | ✅ |

**Phase 3 tables:** `tblAssetStocktakes`, `tblAssetStocktakeItems`, `tblAssetValueHistory`, `tblAssetKioskTokens`, `tblAssetKioskPins`
**Phase 3 settings:** `assets.kiosk_enabled`, `assets.kiosk_idle_timeout_seconds`, `assets.kiosk_auto_checkout`, `assets.digital_link_enabled`, `assets.gepir_verify_enabled`, `assets.gepir_endpoint`, `api.assets.stocktake-scan.enabled`

**Phase 3 note:** identifier-scheme vocabulary management still has no admin screen (the 21 seeded types from migration 159 remain fixed) — the one Phase 1 limitation Phase 3 does NOT close.

---

### Webhook async retry, RSVP waitlist promotion-on-cancel, public podcast feed (#324 v1.1 / #334 v1.1 / #264 v1.1, 2026-08-27)

Three small, independent feature completions, each closing out a v1.1 follow-up its own parent feature's docs had reserved.

| Item | Issue | Migration | Status |
|---|---|---|---|
| Outbound webhook async retry cron — new `WebhookDispatcher::retryDue()` sweeps `tblWebhookDeliveries` rows still `status='failed'`, re-delivering through the SAME `attemptDelivery()` private method `emit()`'s own first attempt uses; exponential backoff (base 60s × 2^attempts, capped ~6h) stamped into a new `nextRetryAt` column, dead-lettered (`status='dead'`) once `attemptCount` reaches `MAX_ATTEMPTS` (6). New `cron/webhook-retry.php`, token-gated exactly like every other `cron/*` endpoint (`webhooks.cron_token`, seeded empty) | #324 v1.1 | 166 | ✅ |
| RSVP waitlist promotion-on-cancel — new `Portal\Core\Events::promoteFromWaitlist()` (transactional, row-locked, never throws); `calendar/rsvp.php` calls it after every write that frees a confirmed seat (switching to maybe/not_going, cancelling outright, or reducing `guestCount`), promoting the earliest-waitlisted RSVP(s) that now fit the freed capacity and emailing each promoted user. No schema change | #334 v1.1 | — | ✅ |
| Public podcast feed — new `recordings/podcast.php`, no login required (unlike the existing session-gated `recordings/feed.php`), gated by a per-site unguessable `recordings.podcast_token` (lazily generated + encrypted in `tblSettings` via `Recordings::podcastToken()`, deliberately never seeded by a migration). RSS 2.0 + `xmlns:itunes` feed listing only `isPublished=1` recordings. Both `externalUrl`-backed AND self-hosted (`filePath`) episodes are podcast-app-reachable: self-hosted enclosures point at a new public `recordings/podcast-media.php` (Range-aware, `isProtected=0`, re-authenticates via the same podcast token rather than a session) instead of the login-gated `recordings/stream` | #264 v1.1 | — (both route seeds folded straight into `full_schema.sql`) | ✅ |

**New files:** `web/_core/Events.php`, `web/_apps/recordings/podcast-media.php`. **New seeded setting:** `webhooks.cron_token` (migration 166, empty by default — admin must set a real value). **New runtime-only setting (never seeded):** `recordings.podcast_token`.

---

### PayPal payment adapter + online giving/pledge checkout UI (gap #1, 2026-08-28)

The Payments app's `paypal` branch was previously stubbed ("not-implemented" — every checkout attempt failed visibly) and neither Giving nor Projects exposed any button that could reach `/payments/checkout` at all — even Stripe was unreachable by end users before this. Both gaps close together: PayPal Orders v2 is now fully wired, and the missing checkout UI ships alongside it.

| Item | Issue | Migration | Status |
|---|---|---|---|
| PayPal Orders v2 — `paypalCreateCheckout()` (create + approve-link redirect), `paypalCaptureOrder()`/`paypalFetchOrder()` (capture-on-return + 422 `ORDER_ALREADY_CAPTURED` reconcile), `paypalVerifyWebhook()` (PayPal's own verify-webhook-signature API, fail-closed on any missing input), `handlePayPalEvent()` (`CHECKOUT.ORDER.APPROVED` backstop capture for a payer who approves and never returns, `PAYMENT.CAPTURE.COMPLETED`, best-effort `PAYMENT.CAPTURE.REFUNDED`), `paypalRefund()` — all raw cURL, no SDK, 15s timeouts, same shape as the existing Stripe block | #268 | 167 | ✅ |
| ★ S1 integrity gate — `Payments::markPaymentSucceeded()` gained `?int $observedAmountPence, ?string $observedCurrency`; every PayPal success path (return capture, 422 reconcile, verified webhook) asserts the captured amount+currency EXACTLY against the pending row before any Giving/Projects fan-out — a mismatch marks the row `failed` (`errorMsg='amount-mismatch…'`) and logs `PaymentIntegrityFail`, never books the wrong amount. The status transition is now a single atomic `UPDATE … WHERE status='pending'` (`affected_rows === 1` gate), closing the return-path-vs-webhook race | #268 | 167 | ✅ |
| Checkout UI — new `giving/give.php` "Give online" page (active-category picker + amount, quick-amount buttons, TEST MODE badge) linked from `giving/index.php`; a "Pay now" form on `projects/my-pledges.php`'s unfulfilled pledges (hidden when the pledge's project currency ≠ `payments.currency` — no silent wrong-currency charge) | #268 | 167 | ✅ |
| `checkout.php` hardening completion — a £10,000 ceiling on self-service online giving (`GIVING_MAX_AMOUNT_PENCE`, pledges exempt — their amount is always forced from the pledge row) and server-built order descriptions (`'Giving — {category}'` / `'Pledge — {project}'`, truncated + control-chars stripped); the POSTed `description` field is removed from the flow entirely, closing a provider-page text-injection vector. (Purpose/purposeRef validation, the pledge donor-ownership check, and the forced pledge amount were already shipped by #430 and are unchanged) | #268 | — | ✅ |
| Admin config — PayPal column on `/payments` gains Mode (sandbox/live) + Webhook ID fields and a live-mode-while-test-mode-on warning; Client ID becomes password-style keep-if-blank (matches Secret) now that it's encrypted at rest | #268 | 167 | ✅ |

**New settings:** `payments.paypal.webhookId`, `payments.paypal.mode` (`sandbox`\|`live`, default `sandbox`). **Changed:** `payments.paypal.clientId` isSensitive `0`→`1` (predicate-guarded flip on existing installs — only where still empty). **New route:** `giving/give`.
### Venue Bookings (`/venues`) (#429, migration 170)

Tenant-side register for congregations that RENT their building from another
organisation — the mirror-image of Resources (rooms you own) and Assets
(things you own). Records the agreed hire schedule so leaders never plan an
event into an unbooked or unavailable slot.

- **Year schedule** — one row per hire date (matches the source spreadsheet
  1:1), month-grouped, with status colours, "times needed" flags and a Today
  anchor; CSV + PDF exports (cost column manager-only).
- **Configurable vocabularies** — per-venue usage types ("Regular Hours",
  "Extended", "Closed - Not Needed", "Building Unavailable") with
  **effective-dated default time windows** (the hire hours can change per
  schedule year), and per-site booking statuses each carrying
  `countsAsConfirmed`/`isAvailable` flags that drive the calendar.
- **Recurring generator** — weekly/fortnightly/monthly/custom series with
  preview, duplicate-skip reporting, and per-date window resolution;
  multi-day runs (e.g. a VBS week) grouped for one-click status/delete.
- **XLSX/CSV import wizard** — native (no-Composer) parser with zip-bomb
  hardening, Excel-serial date conversion, vocab map-or-create step,
  BackingData year-window suggestions, and a CSV fallback path.
- **Calendar integration** — venue-layer strips on every calendar view
  (confirmed / proposed / closed / unavailable rendered distinctly) and an
  "is it booked?" warning on event save + a live event-form check
  (`/api/venues/check`), keyed off `venues.calendar_default_venue`.
- **Per-event venue/room links + room-aware coverage (#436, migration
  179)** — the calendar manage form's venue picker is a **persisted**
  link (not just an advisory check): an optional cascading room `<select>`
  sits beside it, and `tblEvents.venueID`/`roomID` (both nullable, FKs
  `ON DELETE SET NULL`) record which venue/room the event is actually
  held at, site+venue scoped so an event can never link a foreign
  tenant's venue/room. `classifyEventCoverage()` narrows to that room —
  a whole-venue booking still covers every room, but a booking scoped to
  a *different* room no longer does — and reports a new `room-not-covered`
  verdict when the room itself is uncovered but the venue has a confirmed
  hire elsewhere that day. Invalid/foreign posted links silently save as
  NULL (never block the event save); disabling the Venues app leaves
  existing links untouched and the calendar/form byte-identical to
  pre-#429 output. Also fixed: the live "is it booked?" check's field
  names had drifted from `check.php`'s contract, so it silently 400'd on
  every request — now aligned on `start`/`end`/`tz`.
- **Hire agreements** — standing/ad-hoc terms, rates (pence-integer), renewal
  + notice-period reminder sweep, document vault with gated downloads.
- **Payable invoice ledger** — money OUT to the landlord: invoices, per-booking
  allocation lines, partial payment records, machine-managed status
  (pending/part-paid/paid), invoice PDF. No card rails — pure tracking.
- **Reminders cron** — `cron/venue-reminders` (token-gated): un-agreed
  bookings, agreement renewals, invoices due; single-shot dedupe log.
- **Roles** — viewer (any logged-in user) vs `venue_manager`/admin (all
  management + costs); admin-only hard deletes + settings.

**New files:** `web/_core/Venues.php`, `web/_core/apps/venues.php`,
`web/_apps/venues/*`, `web/_apps/cron/venue-reminders.php`,
`web/_apps/help/venues.php`. **Schema:** migration 170, 15 new `tblVenue*`
tables, zero guarded ALTERs. **New seeded settings:** `venues.enabled`,
`venues.currency`, `venues.maxFileSize`, `venues.unagreed_lead_days`,
`venues.renewal_lead_days`, `venues.invoice_due_lead_days`,
`venues.reminders_enabled`, `venues.reminder_roles`, `venues.cron_token`,
`venues.calendar_default_venue`, `api.venues.check.enabled`,
`api.venues.availability.enabled`.

**#436 additions:** migration 179 — guarded, additive `tblEvents.venueID`/
`roomID` (nullable, FKs `ON DELETE SET NULL` → `tblVenues`/`tblVenueRooms`,
added on replay since `tblEvents` precedes those tables in
`full_schema.sql`). `Venues::classifyEventCoverage()` gained an optional
trailing `?int $roomId`; new `Venues::COVERAGE_ROOM_NOT_COVERED` constant
and public `Venues::getRoom()` accessor. Touched:
`web/_apps/calendar/manage/{index.php,save.php,_event_form.php}`,
`web/_apps/venues/api/check.php`. No new settings keys, no new routes.

### User reminders sweep — Tasks/Rota/Milestones (gap #439, migration 171)

Three reminder fields shipped by earlier migrations but never consumed by
any code: `tblTasks.reminderDate`/`reminderSent` (036), `tblRotaSlot.
reminderSentAt` + `rota.reminder_days_before` (074), and the milestones
"daily digest for designated roles" the app's own description promised
(076). All three now fire via one new cron endpoint.

- **`cron/user-reminders`** (token-gated, 15-minute cadence) — three
  families per active site: **task-reminder** (assignee-only email once a
  task's `reminderDate` arrives, capped by a first-activation lookback
  window so turning the sweep on doesn't blast years of backlog), **rota-
  slot** (one grouped email per assignee listing every duty due within
  `rota.reminder_days_before`), **milestone-digest** (once-daily 06:00-
  08:59 digest of today's birthdays/anniversaries to the roles listed in
  `milestones.digest_recipients` — an empty CSV skips the site entirely,
  explicit opt-in only for birthday data).
- **Dedupe** — tasks/rota reuse their existing sent-flag columns via an
  atomic claim UPDATE; milestone-digest uses a new generic
  `tblUserReminderLog` table (check-first + race-catch), reserved so a
  future single-shot family (e.g. DBS expiry) can reuse it with zero DDL.
- **Two new notification preferences** — `taskReminders` / `rotaReminders`
  on `/account/notifications` (default on), the first prefs this codebase
  actually honours when sending (every earlier switch on that page was
  captured but never read).
- **Write-path fixes** so the dedupe stamps stay correct when the
  underlying row changes: `tasks/save.php` re-arms `reminderSent` on a
  future reminder edit; `tasks/complete.php` carries the reminder forward
  (interval-shifted) into a recurring task's next occurrence instead of
  dropping it; `rota/swap-respond.php` clears `reminderSentAt` on an
  accepted swap so the new assignee gets their own reminder.
- **Fixed in the same PR:** `cron/event-reminders.php` selected `u.email`
  from `tblUsers` — the real column is `emailAddress` — so under this
  app's strict mysqli reporting the event-reminder cron 500'd on every
  single invocation. Three other `u.email` sites found during this work
  are tracked separately (#438), not touched here.

**New files:** `web/_apps/cron/user-reminders.php`. **Schema:** migration
171, new `tblUserReminderLog` table, zero ALTERs. **New seeded settings:**
`user_reminders.cron_token`, `user_reminders.enabled`,
`tasks.reminders_enabled`, `tasks.reminder_lookback_days`,
`rota.reminders_enabled`, `milestones.digest_enabled`.

### Service-Plans ↔ Worship additive bridge (gap #6, #442, migration 173)

Two independent "service plan" data models have existed side-by-side since
migration 137 with neither aware of the other: the run-sheet builder
(`tblServicePlan` SINGULAR, #262/#300) and the worship presentation engine
(`tblServicePlans` PLURAL, #308/#355). Migration 154's own header calls the
two "unrelated" — this gap item adds an OPTIONAL, ADDITIVE cross-reference
so a worship presentation can declare which run-sheet it presents, with
zero merge, zero data migration, and zero change to either surface's
existing behaviour when unpaired.

- **New column** — `tblServicePlans.runSheetPlanID` (nullable, UNIQUE,
  `FOREIGN KEY … REFERENCES tblServicePlan(planID) ON DELETE SET NULL`).
  NULL = unpaired, the state of every pre-existing row.
- **`Portal\Core\ServicePlanLink`** — the only code in the codebase that
  knows about both models: resolves each side's paired counterpart summary
  (name/title, event or template badge, item/slide count, song titles) and
  implements `pair()`/`unpair()` with the hard invariants — same site
  (rows simply resolve to "not found" across tenants), same event when
  BOTH sides declare one (refused otherwise, either side NULL always
  proceeds), and 1:1 (a run-sheet already claimed by a different worship
  plan is refused; the UNIQUE key is the race backstop, caught as a
  friendly message, never a fatal 500).
- **eventID backfill (Q1, decided: yes)** — NULL-only, one-directional
  (worship → run-sheet): pairing a worship plan that already has an event
  wakes the run-sheet's dormant, write-dead `eventID` column ONLY when it
  is currently NULL. Never the reverse — the worship side's `eventID` is
  ACL-bearing (it decides who may edit the plan) and is only ever set
  through that app's own explicit, authorised binding flow.
- **`worship/plan/link`** — new CSRF'd POST handler
  (`worship/plan-link.php`) serving both editors' pair/unpair controls.
  Reuses the worship app's existing write gate verbatim (admin OR
  coordinator of the plan's bound event; template plans admin-only) —
  pairing mutates a `tblServicePlans` row, so that row's own rule governs.
  Re-pairing a worship plan's own existing link overwrites; claiming a
  run-sheet already linked elsewhere is refused (Q6, decided).
- **Read-only counterpart panels** on both editors
  (`service-plans/edit.php`, `worship/plan.php`) — each guarded by
  `AppRegistry::isEnabled()` + try/catch (venue-overlay resilience
  precedent), so a disabled counterpart app, a not-yet-migrated column, or
  any resolver exception leaves the panel empty rather than breaking the
  page. Pair/unpair controls: worship-side gated by the page's own
  `$canWrite`; run-sheet side is admin-only in v1 (Q2, decided — the
  run-sheet app itself has no coordinator concept to check per-candidate).
- **No field sync in v1 (Q4, decided)** — the two item representations are
  structurally incompatible (Model A: free-text `title` per section; Model
  B: canonical `songID` FK into `tblSongs`). Panels are read-only visibility
  only; a one-shot, user-triggered "copy sections → slides" action is a
  deliberate v2 candidate, not built here. List-page paired badges (Q3)
  are likewise deferred to v2.

**New files:** `web/_core/ServicePlanLink.php`,
`web/_apps/worship/plan-link.php`. **Schema:** migration 173 — guarded
MySQL-8-safe DDL (column + UNIQUE key + FK on `tblServicePlans`), one
route seed (`worship/plan/link`), no new settings keys (gating rides on
the existing `worship.enabled` / `service_plans.enabled`).

---

### Web Push notifications (#322, migration 177)

Migration 111 shipped `tblPushSubscriptions` + the four `push.vapid*`/
`push.contact`/`push.enabled` settings, but the subscribe/unsubscribe
handlers sat at the wrong path for ApiRouter to ever reach (same routing
trap #372/#373/#387 already fixed for worship/livestream) and NO sender
existed anywhere. This closes the whole loop: relocation, sender class,
client subscribe UI, and two notification channels.

| Item | Issue | Migration | Status |
|---|---|---|---|
| `Portal\Core\WebPush` — VAPID (RFC 8292) ES256 JWT signer with the mandatory DER→JOSE signature conversion (`openssl_sign()` yields DER; every real push service 401s on the raw bytes), RFC 8291 aes128gcm payload encryption (fresh ephemeral P-256 keypair per message, `openssl_pkey_derive()` ECDH, three `hash_hkdf()` derivations), RFC 8030 delivery POST with TTL/Urgency/Topic. Committed self-test (`tools/webpush-selftest.php`) exercises the real private methods via reflection — sign→verify + encrypt→decrypt round-trip, no DB/network needed | #322 | 177 | ✅ |
| Relocated `_apps/api/push/{subscribe,unsubscribe}.php` → `_apps/push/api/{subscribe,unsubscribe}.php` (the ApiRouter convention path) + seeded `api.push.{subscribe,unsubscribe}.enabled` flags — the original location was unreachable dead code from the day it shipped. Added rate limiting (`RateLimiter`, 30/hour/IP) and endpoint SSRF validation at subscribe time (`WebPush::validateEndpoint()` — https-only, no IP-literal/local host, admin-editable host-suffix allowlist) | #322 | 177 | ✅ |
| Client subscribe UI — `assets/js/push-subscribe.js` (vanilla, explicit-gesture `pushManager.subscribe`, never on page load) + `sw.js` `push`/`notificationclick` handlers (neither existed before). Rendered on `/account/notifications` (master prefs `pushLivestream`/`pushServiceReminders`, both default-on) and `/live` (anonymous viewer opt-in bell) | #322 | — | ✅ |
| "We're live now" — manual admin button on `/admin/livestream` + Host Console (`Topic` header replaces, not stacks, a re-press; rate-limited 3/15min/site), plus default-OFF `cron/push-golive.php` auto-detect (`push.golive.auto`, dedupe once per channel per day via `tblUserReminderLog`) and a default-OFF anonymous "starting soon" broadcast (`push.reminders.broadcast`) | #322 | 177 | ✅ |
| Service reminders — `cron/event-reminders.php`'s 1h window now ALSO pushes to the same RSVP'd users, riding the existing `tblEventReminderLog` single-shot claim (no separate dedupe). 24h window stays email-only | #322 | — | ✅ |
| GdprEraser + offboarding coverage — `tblPushSubscriptions` hard-deleted on both right-to-erasure and offboarding revocation (endpoint + keys are device credentials, not history worth retaining) | #322 | — | ✅ |
| Admin config `/admin/integrations/push` — generate-or-paste VAPID keys (private key sodium-encrypted at rest, never re-displayed; only a public-key fingerprint shown), contact/enable/TTL/auto-toggle settings, per-channel subscription counts (no endpoint enumeration), "send test" to the admin's own devices | #322 | 177 | ✅ |

**INERT UNTIL CONFIGURED** — exactly like PayPal/Cloudflare Stream: with
`push.vapidPublicKey`/`push.vapidPrivateKey` empty (as migration 111 seeded
them), `WebPush::isConfigured()` is false and every send path, the client
bootstrap, and both cron jobs no-op silently. **New files:**
`web/_core/WebPush.php`, `web/_apps/admin/integrations/push/{index,save,test}.php`,
`web/_apps/cron/push-golive.php`, `web/public_html/assets/js/push-subscribe.js`,
`tools/webpush-selftest.php`. **Schema:** migration 177 — three additive
`tblPushSubscriptions` columns (`failCount`/`lastFailureAt`/`lastHttpStatus`,
dead-subscription pruning), settings seeds (TTLs, auto-notify toggles,
`push.cron_token` empty+sensitive, `push.endpointHostAllowlist`, the two
`api.push.*.enabled` flags), 4 route seeds. No new tables — reuses
`tblUserReminderLog` (go-live dedupe) and `tblEventReminderLog` (reminder
dedupe).
### Hymnal lookup + public Order of Service (gap #128 residual, migration 178) ✅

Re-scope of #128 ("Order of Service planner with iHymns integration") — a
2026-08-28 architecture pass found service-plans (#262/#300) + the Worship
Presentation Engine (#308/#355) already shipped ~90% of what the issue
asked for (item CRUD, reorder, presenters, durations, notes, templates,
event linking, song library + CCLI). This ships ONLY the residual, all
additive on the EXISTING `tblServicePlan`/`tblServicePlanItem`/`tblSongs`
tables — **no new app, no third service-plan data model.**

- **R1 — Local hymnal index (`/admin/hymns`).** New `tblHymnals` +
  `tblHymnalEntries` — one hymn book per site, multi-hymnal by design
  (SDA Hymnal, Mission Praise, … are just rows). Entries are METADATA
  ONLY — number, title, first line, author, tune, meter, CCLI number,
  copyright line — **never lyrics** (copyright risk; lyrics stay
  hand-entered in the Worship Song Library under the church's own CCLI
  licence, unchanged). Manual entry + CSV import
  (`number,title,firstLine,author,tuneName,meter,ccliNumber,copyrightLine`
  — only `number`/`title` required, upsert on hymn number so a re-import
  is idempotent). `Portal\Core\Hymnal::searchLocal()` — FULLTEXT + numeric
  hymn-number prefix matching.
- **R2 — Remote ("iHymns") lookup, Tier 2, default OFF.** No public
  iHymns API is documented (the issue's linked domain is unreachable from
  this build sandbox); repo docs (`deploy.yml`, `DEV_NOTES.md`) suggest
  iHymns is an in-house sibling deployment. Shipped as a **generic**
  HTTPS JSON client (`Hymnal::searchRemote()`) an owner can point at any
  endpoint speaking a documented `GET {baseUrl}/search?q=…` contract (see
  DEV_NOTES.md) — default OFF (`hymns.remote.enabled = 'false'`) until an
  admin supplies + confirms a host. SSRF-hardened: https-only + single-
  host allowlist (refused at both settings-save and request time),
  private/reserved-IP refusal (IP literal AND DNS-resolved), no redirect
  following, HTTPS-only curl protocols, 3s connect / 5s total timeout, a
  ~512 KB response cap, JSON-only parsing, and a 24h server-side cache
  (`tblHymnLookupCache`) so a repeat query never re-hits the remote host.
  ANY failure (disabled, misconfigured, timeout, malformed) degrades
  silently to local-only results — never blocking, never throwing. The
  API key is encrypted at rest and never logged or echoed to the client.
- **Picker endpoint.** New session-authenticated
  `service-plans/api/hymn-search.php` (ApiRouter convention path, gated
  by `api.service-plans.hymn-search.enabled` — NOT a tblRoutes row, per
  the standing ApiRouter trap) merges local-hymnal + song-library +
  (when enabled) remote results for a typeahead on the run-sheet editor's
  song rows. Progressive enhancement — with JS disabled the `title` field
  is a plain text input exactly as before.
- **R3 — Congregation-facing public Order of Service.** New
  `tblServicePlan.publicToken` (mirrors `tblAssets.publicToken`) +
  `isPublicShared`. A Router special route `/os/{token}` (cloned from
  `/a/{token}`) → `service-plans/public.php` renders order/titles/hymns/
  **presenter names** (decided default: yes) — internal AV/tech `notes`
  are NEVER queried, let alone rendered. Uniform 404 for an unknown
  token, an unshared/unpublished plan, the site-level switch off, or the
  app disabled — no oracle distinguishing any of those from each other.
  **OFF by default** at both the site level
  (`service_plans.public_share.enabled = 'false'`) and the plan level
  (`isPublicShared` defaults to 0 on every plan, existing and new). New
  CSRF'd `service-plans/share.php` (enable / disable / rotate the token)
  plus a QR code via the existing `/qr.php` utility. `print.php` gains
  `?version=leader|congregation` (default `leader` — byte-identical to
  before #128) — the congregation variant additionally suppresses the
  internal sectionType tag, matching a normal printed bulletin.
- **R4 — Glue.** Nullable `tblServicePlanItem.songID` FK → `tblSongs`
  (`ON DELETE SET NULL`). Picking a hymnal/remote result auto-promotes it
  into `tblSongs` (default: yes — check-first upsert on
  `(siteID, hymnalCode, hymnNumber)`, metadata only) and links the item;
  picking an already-canonical song-library result links directly. Free-
  text `title` remains the universal, always-working fallback — every
  pre-existing item has `songID` NULL and renders unchanged. `tblSongs`
  gains `hymnalCode`/`hymnNumber`/`tuneName`, surfaced in
  `worship/song.php`/`songs.php` so "when did we last sing hymn X" works
  across BOTH the run-sheet and the Worship CCLI log.
- **Explicitly not built (per the plan's decided defaults):** no
  `sectionType` ENUM widen for benediction/children's-story (`other`/
  `prayer` + free title already covers it); no dompdf PDF export (print
  CSS covers both variants); no change to the run-sheet's existing
  any-logged-in-user write ACL (tracked separately).

**New files:** `web/_core/Hymnal.php`, `web/_apps/admin/hymns.php`,
`web/_apps/admin/hymns-save.php`,
`web/_apps/service-plans/api/hymn-search.php`,
`web/_apps/service-plans/share.php`, `web/_apps/service-plans/public.php`.
**Schema:** migration 178 — 3 new tables (`tblHymnals`,
`tblHymnalEntries`, `tblHymnLookupCache`), guarded ADD COLUMN on
`tblSongs` (×3), `tblServicePlanItem` (`songID` + key + FK), and
`tblServicePlan` (`publicToken` + `isPublicShared` + unique key); 3 route
seeds; 7 settings seeds (incl. the `api.service-plans.hymn-search.enabled`
ApiRouter flag). All 11 audit checks green, `php -l` clean.

---

### MS365 Graph email via a shared mailbox (gap #234, migration 176)

The portal already sent every email app-only through Microsoft Graph
(`POST /users/{mail.defaultFromAddress}/sendMail`) — this gap item
formalises and hardens that path rather than adding a new auth model: an
explicit, admin-configured shared-mailbox identity, an explicit `from`
object (with display name) in both modes, 401/429/403/404 error handling
with an optional Google fallback, and a `tblEmailLog` audit trail
(also closes the #230 dependency).

- **Model chosen: app-only, application permission `Mail.Send`**, via
  `POST /users/{sharedMailbox}/sendMail` — reuses `Mailer::accessToken()`
  verbatim, zero new secrets. The issue body's delegated
  `Mail.Send.Shared` refresh-token model is explicitly deferred (would add
  an OAuth authorize/refresh surface, a new encrypted secret lifecycle,
  and a dependency on a licensed "delegate" human account whose password/
  MFA/offboarding events would silently kill portal mail).
- **`Mailer::effectiveSender()`** — resolution order: (1) non-empty +
  valid `mail.ms365.sharedMailbox` → the shared mailbox is both the URL
  mailbox and the `from` address, display name from
  `mail.ms365.sharedMailboxName` falling back to `mail.defaultFromName`;
  (2) non-empty but invalid (hand-edited) → log once, fall through; (3)
  empty (seeded default) → `mail.defaultFromAddress` exactly as before.
  **Empty mailbox = feature off** — no separate enable toggle, so it's
  impossible to half-configure toggle-on-but-empty-mailbox.
- **Benign behaviour change:** the `from` object is now built in BOTH
  modes, so `mail.defaultFromName` finally takes effect on the MS365 path
  (previously unused there — only the Google path honoured it).
  `sender` is intentionally never set (that's the delegated
  send-on-behalf field, not app-only send-as).
- **Error matrix:** 401 → clear cached token, retry once; 429 → bounded
  single retry only when Graph's own `Retry-After` is ≤5s (never an
  unbounded sleep inside a web request on shared hosting); 403/404 →
  parse Graph's own `error.code` for a targeted admin hint instead of a
  bare HTTP code; optional `mail.fallbackProvider='google'` (default `''`
  = fail loudly) makes one fallback attempt via `MailerGoogle::send()`.
- **`tblEmailLog`** (migration 176) — per-send audit row from BOTH
  providers via the new `Mailer::logSend()` (public, called from
  `sendViaGraph()` and from `MailerGoogle::send()`'s own success/failure
  branches). Fail-soft (a logging exception never breaks a send).
  Opportunistic retention prune (`mail.log.retentionDays`, default 90) on
  ~1-in-50 writes — no new cron endpoint. `GdprEraser` gained a bespoke
  step (not a `catalogue()` entry — `toRecipients` is a comma-joined
  free-text list, not a single `userCol` FK) that scrubs an erased user's
  address out of it, captured before the catalogue's own `tblUsers` step
  nulls the address.
- **Admin UI** (`/admin/integrations`) — Shared-Mailbox Sending
  sub-section on the MS365 Graph API card (status, mode badge, last-send
  indicator, CSRF'd save form → new `admin/integrations/ms365-mail-save.php`);
  Send Test Email now calls the real `Mailer::send()` instead of
  duplicating the token+sendMail cURL flow inline, closing the
  test-vs-production drift risk permanently.
- **Fold-in fix** — `/admin/integrations/email` was reading a dead
  `email.provider`/`email.from` settings vocabulary (seeded, never
  written by any save handler) and always reported "smtp"; now reports
  the real `Mailer::provider()` + effective sender, plus a "Recent sends
  (last 10)" `portal-data-list` from `tblEmailLog`.
- **Security:** the shared mailbox is ADMIN-CONFIG ONLY — read
  exclusively from `tblSettings` via `effectiveSender()`, written only by
  the CSRF'd, admin-gated save handler; no request/user input reaches it.
  No secret is ever logged (`tblEmailLog.errorDetail` holds only Graph's
  own truncated `error.code`/`error.message`).

**New files:** `web/_apps/admin/integrations/ms365-mail-save.php`.
**Schema:** migration 176 — `tblEmailLog` (standard `CREATE TABLE IF NOT
EXISTS`, no guard idiom needed for a new table), 5 non-sensitive settings
seeds, 1 route seed, folded into `full_schema.sql`.

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
