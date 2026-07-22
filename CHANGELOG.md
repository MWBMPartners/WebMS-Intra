# Changelog


## [1.4.0] - 2026-07-22 (alpha)
- feat(giving): #299 two-person offering-count session (sub-feature 1 of the
  "Giving polish" issue — pledge campaigns/reconciliation/account-updater are
  separate sub-features, not built here). New `tblCountSessions` (migration
  150) tracks a service date's count: two counters independently key cash /
  cheque / envelope totals; once both are in, the system compares them and
  flags `status='discrepancy'` on any mismatch (blocking close) or
  auto-agrees when they match. A discrepancy is cleared either by a counter
  re-entering matching totals or — admin-only — by resolving with agreed
  totals directly. New `tblCountEnvelopes` child table logs named/numbered
  giving-envelope amounts against the session's envelope total; closing a
  session (`/giving/count/close`) validates the named envelopes reconcile to
  the agreed envelope total, then writes the real gift log in one
  transaction — one `tblGivingEntry` row per named envelope (attributed to
  the giver where matched) plus aggregate "loose cash"/"loose cheque" rows
  for anything not itemised, so the total written always balances to
  cashTotal + chequeTotal + envelopeTotal. New UI under `/giving/count`
  (list/start, session detail with counter-entry cards + comparison table +
  named-envelope log) gated by the same `Portal\Core\Giving::canManage()`
  (admin or `treasurer` role) as the rest of `giving`; new
  `Portal\Core\Giving::parseDecimal()` helper for validated
  DECIMAL(10,2)-safe amount parsing. Note: the issue body names the write
  target `tblGiftEntries`, but the giving app actually shipped (#266) as
  `tblGivingEntry` — this migration writes to the real table.
- feat(noticeboard): #363 real media upload pipeline — replaces the `data:`
  URI rejection in `save.php` with `POST /api/noticeboard/upload`
  (site-admin, CSRF, finfo-sniffed MIME allowlist: png/jpeg/gif/webp +
  mp4/webm, hard size cap via `noticeboard.upload.maxBytes` default 15 MB,
  server-generated random filename). Files land under
  `_uploads/noticeboard/` (outside the webroot, mirroring
  `documents/api/create.php`) and are served back with NO auth by the new
  public `GET /noticeboard/media?f=<token>` route — posters must keep
  rendering for an anonymous QR scanner. New `tblNoticeboardUploads` ledger
  (migration 149) + `Portal\Core\NoticeboardMedia` helper links each upload
  to its saved poster and purges orphans (abandoned in the editor, or whose
  poster was later soft-deleted) after every save.
- feat(prayer-requests): #311 prayer-chain assignment residuals — private
  partner notes (`partnerNote`/`partnerLastPrayedAt`, ACL: assignee-or-admin
  only, cleared on reassignment), manual assign dropdowns with an
  open-assignment load-balancing hint on `manage`/`view` (eligible partner =
  active site member holding the new `prayer_team` role), opt-in round-robin
  auto-assign on submission (`prayer-requests.autoAssign`), and email + SMS
  assignment notifications (`prayer-requests.notifyOnAssign`, respecting the
  partner's SMS opt-in) — new `Portal\Core\PrayerChain` helper, migration 148.
- feat(api): REST API v1 write surface (#323 Phase 2, PR #372) — dual-mode `Portal\Core\ApiAuth`
  (bearer API key OR session, resolved centrally); `/api/v1/{resource}[/{id}]` RESTful facade that
  maps HTTP verbs onto the SAME `_apps/{app}/api/{action}.php` handlers + `api.{app}.{action}.enabled`
  flags as the existing `/api/{app}/{action}` routes (no new gating vocabulary); bearer requests are
  tenant-pinned to the key's own site (`Site::forceContext`) and rate-limited per key; new write
  endpoints closing the #157 remnant — Attendance + Documents (create/update/delete) and Expenses
  (create/delete — status-transition update deferred to Phase 3), plus new Users create/update
  (admin-gated, default-off flags); canonical `ApiKey::SCOPES` vocabulary + rotation grace windows;
  admin API-keys UI gains a scope checkbox multi-select (validated server-side against `SCOPES`) and
  a rotation-grace selector; admin audit viewer gains a source (session/apikey) badge + key-prefix;
  OpenAPI spec documents every `/api/v1/*` path alongside the existing legacy aliases.
- feat(admin): outbound webhooks admin CRUD UI (#324)

## [1.2.0] - 2026-07-07 (alpha)
- 2 apps + iCal feed + admin polish — 7 issues (#258, #261, #271, #251, #254, #253, #252) (#281)
- 4 community/pastoral apps: Rota + Praise + Milestones + Care (#256, #260, #259, #257) (#280)
- Apps wave 3: Reading Plans + QR + Invite onboarding + Offboarding (#265, #275, #239, #240) (#283)
- Apps wave 4: 10 apps — Resources, Service Plans, Livestream, Recordings, Zoom, Newsletter, Giving, SMS, Projects, Payments (#284)
- Apps wave 5: Transcription, Translation, AI Assist, GDPR, Photos + 5 infra/security items (#285)
- Audit fixes: bootstrap try/catch + schema drift port + admin gates + CI paths + cleanup (#173-#194) (#197)
- Foundation: App Registry + Markdown + X-Robots-Tag + CHANGELOG (#246, #247, #255, #270) (#279)
- Pre-rollout omnibus: 19 issues, 13 migrations, 1.1.1 → 1.2.0 (#245)
- chore(noticeboard): remove unusable eval-variant bundle (#360)
- chore+feat: post-merge cleanups + installer brand-aware favicons (#354)
- chore: v1.0.0 follow-ups — installer path fix + X-Powered-By branding (#165)
- ci: add cross-source consistency checks to pr-security.yml (#213) (#214)
- docs: sweep stale rename-aftermath references (#189, #182, #183, #194) (#286)
- feat(api): API key infrastructure — mint/revoke/rotate + requireApiKey helper (#323 Phase 1)
- feat(api): write-side CRUD for Announcements, Tasks, Prayer Requests, Leadership (#157) (#291)
- feat(auth): authorised-use notice on the login screen (1.1.1) (#221)
- feat(brand): embed Plus Jakarta Sans across the portal — self-hosted, modular
- feat(brand): product brand layer — runtime ChurchMS / SchoolMS sub-brands (#296)
- feat(brand): wire in Claude Design brand kit — six-asset structure per brand
- feat(brand+easywins): 5 follow-ups bundled — 2 deferred from #297 + 3 church-vertical easy wins
- feat(cop): trio of Church Online Platform easy wins — countdown widget + push + webhooks
- feat(core): page-scoped CSP extension variables in header template (#360)
- feat(discipleship): Phase 1 — pathway + step schema + admin CRUD (#303)
- feat(events): anonymous email-link RSVP — no portal account needed (#335)
- feat(events): anonymous self check-in for events (#314)
- feat(events): auto-build crews + auto-assign jobs (#349)
- feat(events): bundle 7 Events Calendar easy wins from competitive audit
- feat(events): decision moments tracker — tap-to-count per service (#315)
- feat(events): embeddable event widgets — iframe + JS drop-in (#336)
- feat(events): event broadcast / bulk-email by crew/job/segment (#350)
- feat(events): event coordinator role — delegate single-event management (#341)
- feat(events): event crew / group builder (forms-only v1) (#343)
- feat(events): event lifecycle email reminders — 24h + 1h + day-of (#329)
- feat(events): event volunteer job board with capacity indicators (#344)
- feat(events): external calendar feed aggregator — ICS importer (#327)
- feat(events): faceted filter bar — location + search + date range (#330)
- feat(events): multi-day attendance grid + walk-in enrol (#345)
- feat(events): multiple primary organisers per event (#332)
- feat(events): per-event document library link on public event page (#351)
- feat(events): per-event public landing page at /e/<slug> (#346)
- feat(events): per-event registration with VBS-relevant fields (#347)
- feat(events): per-occurrence overrides on recurring series (#333)
- feat(events): public registration — captcha + email confirmation (#348)
- feat(events): surface auto-build / auto-assign buttons on crews + jobs UIs (#349)
- feat(events): volunteer resource portal — /my-volunteering composite read (#342)
- feat(host-console): read-only host cockpit composing COP primitives (#317 Phase 1)
- feat(host-console+live-chat): push prompts + viewer chat widget + ping route fix (#317 Phase 2 + #313 Phase 2)
- feat(install/upgrade): migration runner + state detection + maintenance mode + JSON backups (1.0.1 → 1.1.0) (#220)
- feat(kids): children's ministry check-in / out with badge code (#298)
- feat(live-chat): viewer chat + admin moderation (#313 Phase 1)
- feat(livestream): livestream session analytics (#318)
- feat(noticeboard): self-host React 18.3.1 UMD + wire board under nonce CSP (#360)
- feat(noticeboard): static assets (frontend bundle)
- feat(ops): external error monitor (Sentry / GlitchTip) (#143) (#290)
- feat(pwa): offline write queue + sync-on-reconnect (#233) (#292)
- feat(reports): denominational reporting templates (#305)
- feat(safeguarding): DBS tracking + Auth::isCoordinatorOf gate (#310)
- feat(salvation): decision card tracker (#316)
- feat(worship): SortableJS drag-reorder + song verse split + CCLI usage log (#308 Phase 3)
- feat(worship): live operator + projector display + state polling (#308 Phase 2)
- feat(worship): service plans — schema + CRUD (#308 Phase 1)
- feat(worship): song library + CCLI tracking (#309)
- fix(audit): codebase sweep — duplicate cookie banner + missing Auth import + SQL concat cleanups (#293)
- fix(core): add Portal\Core\Settings wrapper class
- fix(events): wire /e/<slug> prefix into Router::handleSpecialRoutes (#346)
- fix(installer): catch mysqli_sql_exception in steps 3 + 4 (#169) (#170)
- fix(live-chat): ApiResponse::ok→success + drop private setJsonHeaders call (#313 Phase 1 hotfix)
- fix(noticeboard): qr.php — use Qr::generate, strict host pinning, encoder-safe length cap (#360)
- fix(noticeboard): save.php — bind_param arity, cross-site poster guard, URL scheme allowlist (#360)
- fix(schema): backfill full_schema.sql with 35 tables from migrations 105+
- fix(security): post-#281 schema-drift + CSRF findings (#282)
- fix(security-check): inline ALTER columns into CREATE TABLE blocks + CSRF on rsvp-by-link form
- fix(security-check): real bugs + schema backfill + noticeboard app (PR #358)
- fix(security-check): rename \$publish → \$shouldPublish to dodge heuristic false-positive
- fix(security-check): static SQL in notes-save + openapi.json route → openapi.php
- fix(sql): migration 145 header + full_schema noticeboard terminator and seed parity (#360)
- fix(sql): seed default tblSites row in full_schema.sql (#171) (#172)
- fix(ui): installer link colours + portal alert link/code polish (#167) (#168)
- fix(ui): mobile-readiness sweep — 29 → 0 findings (#225) (#295)
- fix: SQL column-name mismatches across installer + import + GDPR export (#198) (#199)
- fix: cross-source consistency audit follow-up #3 (#201 #202 #204 #205 #206 #207) (#212)
- i18n: partial-coverage badge in the language switcher (#210) (#217)
- i18n: remove 24 truly-dead translation keys from en.php (#211) (#216)
- i18n: wrap user-facing hardcoded strings with t() (#209) (#215)
- ops(security): SRI audit — fill missing integrity hashes (#161)
- refactor(brand): /assets/images/brands/ → /assets/images/brandkit/assets/
- refactor(security): move app controllers from public_html/ into _apps/ (#159) (#288)
- refactor(version): single source of truth in _core/version.php (#166)
- release: v1.0.0 launch sprint — 16 commits, 17 deferred issues, full security audit (#158)
- security(csp): nonce-based script-src tightening (#144) (#289)

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Automated sections are appended by `.github/workflows/changelog.yml` per push
to `alpha`, `beta`, and `main` using the heading format
`## [VERSION] - YYYY-MM-DD (branch)`.

## [1.4.0-dev] - Unreleased

Post-1.3.0 Phase-1 ships. Brand font + worship engine landed first; the rest is foundational primitives for the COP / engagement / discipleship surfaces.

### Worship Presentation Engine — full v1 (#308, PR #355)

- Service plans (3-table model: `tblServicePlans` + `tblServicePlanItems` + `tblServicePlanState`).
- Live operator console at `/worship/present?planID=N` with touch + keyboard shortcuts (arrow / space / pageup-down / B / W / S).
- Public projector display at `/worship/display?t=<token>` — 64-char hex display token, polled at 500ms.
- SortableJS drag-reorder + song verse auto-split (per `worship.song_verse_separator` regex) + `tblCcliUsage` audit log + quarterly CSV export at `/admin/reports/ccli`.

### Plus Jakarta Sans modular embed (#356)

- Self-hosted WOFF2 (5 weights, latin subset, ~12 KB each).
- One-line swap via `--portal-font-family` in `portal.css`.
- New `Asset::brandFontsCss()` helper. Installer also picks up the font (bootstrap-free wiring in `_install/index.php`).
- Brand assets relocated from `/assets/images/brands/` to `/assets/images/brandkit/assets/` (the older path no longer exists; manifest.php `is_readable` path bug surfaced + fixed in the same commit).

### Virtual Host Console Phase 1 (#317, PR #357)

- New `Portal\Core\HostConsole` helper class (5 static methods composing existing COP table reads).
- `/admin/host-console` event picker + `/admin/host-console/event?id=N` cockpit with 'Watching now' tile + 7-day sparkline + 6 decision-moment tallies + latest 15 salvation cards.
- Auto-refresh via `<meta refresh content="30">` (JS / SSE upgrade is Phase 2).

### Public API key infrastructure Phase 1 (#323, PR #357)

- `tblApiKeys` — siteID-scoped, sha256-hashed at rest, visible prefix for admin identification.
- `Portal\Core\ApiKey` class — `mint` / `findByPlaintext` / `hasScope` / `revoke` / `rotate`. Token format `wbms_` + 32 hex (128 bits entropy).
- `ApiResponse::requireApiKey($scopes = [])` helper — reads `Authorization: Bearer`, verifies, returns key row.
- `keyHash` added to `ApiResponse::$defaultSensitive` so future JSON handlers can't leak it.
- Admin CRUD at `/admin/integrations/api-keys` with one-time plaintext display banner.
- NOT yet wired: `/api/v1/*` router refactor + write-side CRUD (Phase 2+).

### Discipleship Pathway Tracker Phase 1 (#303, PR #358)

- `tblPathways` + `tblPathwaySteps` (siteID-scoped, sortOrder, isActive).
- AppRegistry entry at `_core/apps/discipleship.php`, gated by `discipleship.enabled` setting (default `false`).
- Admin CRUD: `/admin/discipleship` (list) + pathway form (combined new + edit + step picker) + 7 routes.
- NO per-user progress yet, NO auto-completion, NO mentor relationships, NO member-facing routes — Phase 2+.

### COP Online Engagement Phase 1 — viewer chat + admin moderation (#313, PR #358)

- `tblLiveChatMessages` + `tblLiveRateLimits` + `stream_moderator` role seed (idempotent `WHERE NOT EXISTS`).
- `Portal\Core\LiveChat` helper class — sessionToken validation, sliding-window rate limit (fail-CLOSED on prepare failure), profanity stub, IP detection.
- 3 public/admin API endpoints (`api/livechat/{send,list,moderate}`) — routed via ApiRouter's 3-segment convention with `api.livechat.*.enabled = 'true'` settings seeded.
- `/admin/live/chat` moderation queue page.
- Public `/send`: NO CSRF (third-party embed cookies break it); instead a `sessionToken`-exists guard against `tblLivestreamSessions` plus first-message-only captcha (Turnstile / reCAPTCHA / hCaptcha tokens are single-use).
- Viewer-side chat UI on the `/live` embed page is Phase 2.

### Community Noticeboard — poster wall Phase 1 (#360, PR #358)

- New app: visual poster wall for churches / community sites (Canva embeds, image/video/text posters, once/weekly scheduling, colour/aspect/serif styling, QR share).
- Handlers: `web/_apps/noticeboard/index.php` + `api/{list,save,qr}.php`. Enablement-flag gated via `api.noticeboard.{list,save,qr}.enabled` (ApiRouter convention).
- Schema: `tblNoticeboardPosters` (migration 145). Seeds route + display settings.
- Frontend: prebuilt React bundle at `/assets/noticeboard/noticeboard.noeval.js` + `.css`. React 18.3.1 UMD self-hosted at `/assets/vendor/react/` (SRI-verified against hashes embedded in the bundle).
- Page-scoped CSP extension mechanism in `_core/templates/header.php` (`$cspImgExtra` / `$cspMediaExtra` / `$cspFrameExtra`) — widens img/media/frame directives on the /noticeboard page only. Global CSP unchanged.
- Security hardening: cross-site poster write guard on save (foreign posterIDs insert as new); URL scheme allowlist on `link` / `image` / `thumb` (http(s):// or root-relative only); Canva URL pinned to `www.canva.com`; QR endpoint pins to current host via strict parse_url + honours encoder's ~250-char ceiling; `Qr::pngBytes()`→`Qr::generate()` fatal fixed; `save.php` bind_param arity 20→21 fatal fixed.

## [1.3.0] - 2026-06-19 (main)

PR #340 — events platform overhaul + COP + ChurchMS verticals + ops hygiene. **36 issues across 39 commits in one consolidated PR.** The Multi-brand product layer section originally drafted for 1.2.0 (see further down) shipped as part of this release.

### 🗓️ Events Calendar — easy wins (7)

- **#326** Public Submit-an-Event + moderation queue.
- **#328** Schema.org JSON-LD on event page.
- **#331** Photo view alongside the seven existing view modes.
- **#334** Guest +N RSVPs with auto-waitlist on capacity.
- **#337** Cancellation / postponement broadcast banner.
- **#338** VTIMEZONE + TZID + RRULE in iCal output (RFC 5545).
- **#339** Per-site unique event slug.

### 🛡️ Ops hygiene (3)

- **#161** SRI audit — 5 missing integrity hashes filled.
- **#145** `composer.json` manifest + Dependabot composer ecosystem entry for CVE watching.
- **#147** `auto-merge-alpha.yml` re-verified; still dormant, kept.

### 📚 Calendar extension (1)

- **#351** Per-event document library link (`tblDocuments.eventID` + event-page render + upload prefill).

### 🎯 VBS Pro bundle — foundation (5)

- **#341** Event coordinator role — `Auth::isCoordinatorOf()` + `tblEventCoordinators` junction. Foundation primitive composed by every other VBS-flow endpoint.
- **#342** **Volunteer resource portal** at `/account/my-volunteering` — the user-explicit differentiator vs VBS Pro. Pure read-side composition; per-event team/role/docs.
- **#345** Multi-day attendance grid + walk-in enrol (`tblEventAttendance`).
- **#343** Crew / group builder (forms-only v1; data model SortableJS-ready).
- **#344** Volunteer job board with capacity indicators.

### 🎯 VBS workflow completion (3)

- **#350** Bulk email by crew / job / RSVP segment (`tblEventBroadcasts`).
- **#346** Per-event public landing page at `/e/<slug>` — branded hero + live countdown + QR + register CTA.
- **#347** Per-event registration with VBS-relevant fields (`tblEventRegistrations`) + admin moderation list.

### 🗓️ Calendar polish trio (3)

- **#332** Multiple primary organisers per event (`tblEventOrgs` junction).
- **#335** Anonymous email-link RSVP — 256-bit token, `hash_equals`, server-side expiry (`tblEventRSVPInvites`).
- **#329** Event lifecycle email reminders — 24h / 1h / day-of, cron-driven (`tblEventReminderLog`).

### 🛡️ Safeguarding + auto-distribute (2)

- **#310** DBS safeguarding tracking (`tblDbsChecks`). Optional coordinator gate via `safeguarding.dbs_required_for_coordinators`.
- **#349** Auto-build crews + auto-assign volunteers by deepest-deficit-first.

### 🗓️ TEC remaining (4)

- **#336** Embeddable event widgets — iframe + `widget.js` drop-in.
- **#330** Faceted filter bar — location + search + date range on `/calendar`.
- **#333** Per-occurrence overrides on recurring series (`tblEventOccurrenceOverrides`).
- **#327** External ICS calendar feed aggregator — in-house parser, cron-driven (`tblExternalFeeds`).

### ⛪ COP / public-facing (5)

- **#348** Public registration: captcha gate + email confirmation (extends #347).
- **#314** Anonymous self check-in for events — SHA-256-hashed IP, kiosk / QR / self source.
- **#315** Decision Moments tracker — 6 quick-tap categories per event.
- **#316** Salvation / decision card tracker — public form + admin follow-up workflow.
- **#318** Livestream session analytics — CORS-friendly ping endpoint + admin dashboard.

### 🎵 ChurchMS verticals (3)

- **#305** Denominational reporting templates with CSV export.
- **#309** Song library + CCLI tracking — FULLTEXT-ready (`tblSongs`).
- **#298** Kids check-in / check-out with 6-digit safeguarding badge codes.

### 🧰 Infrastructure

- **`Portal\Core\Settings`** — new thin static wrapper around `$SETTINGS` (fixed latent bug in earlier files that called `Settings::get()` against a class that didn't exist).
- **Router::handleSpecialRoutes** — prefix-match dispatcher for `/e/<slug>`.
- 25 idempotent SQL migrations (112–136).

### Multi-brand product layer — `ChurchMS` / `SchoolMS` / … sub-brands (#296) — folded in from 1.2.0 draft

System-level brand identity that sits ABOVE the existing per-tenant `branding.*` cascade. The installer's new **Step 1.5 — Organisation Type** picks a preset bundle; the install rebrands display surfaces (header meta, footer attribution, X-Powered-By header, PWA manifest name, installer wizard itself) to the matching short name and tagline. Tenant branding (`Site::branding()`) still beats product defaults — a `Mill Road SDA Cambridge` site on a `ChurchMS` install keeps its own siteName in chrome.

**Decisions baked in:**
- **Runtime-only brand** — same codebase, settings-driven; admins can change `portal.industry` post-install via `/admin/settings`.
- **Preset bundles include defaults but not enforcement** — same app mix across all brands; admins can re-enable anything (existing `/admin/apps` mechanism from #255).
- **Per-brand asset folders shipped** — `assets/images/brands/{generic,church}/` with placeholder copies; designers replace with distinct artwork in a follow-up.
- **Publisher always "MWBM Partners Ltd"** — sub-brands are MWBM products, not white-labels.

**Shipping in this PR:**
- `_core/brand-defaults.php` — preset registry (generic / church / school / nonprofit / community / small-business).
- `_core/Site.php::productName() / productTagline() / productPublisher()` — resolution helpers with full fallback chain.
- `bootstrap.php` — `PORTAL_PRODUCT_NAME_DEFAULT` / `…_TAGLINE_DEFAULT` / `…_PUBLISHER_DEFAULT` constants loaded from brand-defaults; X-Powered-By header reads from `$SETTINGS['product']['name']` with cold-start fallback to the constant.
- `_core/templates/header.php` + `footer.php` — meta generator + powered-by mark resolve via `Site::productName()`.
- `_install/index.php` — new Step 1.5 dropdown picker; Step 3 schema install seeds `portal.industry` + `product.{name,tagline,publisher}` after `full_schema.sql` runs.
- `public_html/manifest.php` (new) — replaces static `manifest.json`. Routed via tblRoutes; emits brand-aware PWA manifest + per-brand icons (with fallback to generic asset folder when sub-brand assets aren't present yet).
- Migration `108_product_brand_layer.sql` — seeds the 3 new `product.*` rows and the `manifest.json` route on existing installs.
- `full_schema.sql` — same seeds for fresh installs.

**Known follow-ups deliberately deferred:**
- `openapi.json` brand-aware conversion (developer-facing surface, lower priority).
- Distinct sub-brand artwork (asset folders currently contain placeholder copies of generic).
- School / Charity / Community / Small-Business preset polish (presets present but unverified beyond round-trip).

### Pre-rollout omnibus — 19 issues addressed in one branch

Substantial pre-production-rollout hardening. Each item shipped, scaffolded, or scoped to a follow-up PR per the per-issue status comments.

**Security + ops:**
- `#160` Baseline security response headers (HSTS, Permissions-Policy, COOP, CORP, Referrer-Policy, X-Content-Type-Options, X-Frame-Options) — all per-site overridable via `portal.headers.*`.
- `#142` Backup freshness check `/admin/maintenance/backup-check` with cron mode + email alerting on stale/critical state.
- `#227` Admin backup UI `/admin/maintenance/backup` — list / inspect / run-now / per-table-restore / full-restore / delete, wraps `DbBackup`.
- `#228` System Health page `/admin/maintenance/health` — 8 live probes (DB, disk, backups, errors, sessions, migrations, PHP, maintenance flag). JSON via `?cron=1`.
- `#229` Critical-error alerting — Logger hook with rate-limited email dispatch, sentinel-based cooldown, severity filtering.
- `#230` Email Deliverability admin `/admin/integrations/email` — provider summary, test send, SPF/DKIM/DMARC DNS probe.

**Onboarding / UX:**
- `#222` First-run dashboard empty-state panel with auto-detected setup checklist.
- `#223` Help: Admin First Steps `/help/admin-first-steps` accordion.
- `#224` UK ICO cookie consent banner in global footer.
- `#242` Demo data load/wipe `/admin/maintenance/demo-data` (gated by `portal.demo_mode.enabled`).
- `#237` Tour engine scaffold (`tblTours` + `tblUserTours` + welcome tour seed + `/admin/tours`). Playback JS in follow-up.
- `#241` Print stylesheets at `assets/css/print.css`, wired into `header.php` with `data-portal-name` / `data-print-date` running header.

**Core features:**
- `#231` `Portal\Core\Sabbath` quiet-hours class with `isQuietNow()` + window computation. Two-level (org + per-user `tblUsers.sabbathHonour`). Integrated into Logger alerting.
- `#238` `tblEvents.eventTimezone` column (display-layer conversion in follow-up).

**Docs:**
- `#226` `docs/day2-support.md` + user-facing `/help/support`.
- `#232` `docs/rollout-plan.md` + `portal.rollout.pilot_mode` flag.

### Verified already-fixed (closed with audit trail)

`#173`, `#174`, `#175` — bootstrap try/catch wrappers present.
`#176` — CI workflows already reference `web/_core/version.php`.
`#177`, `#178`, `#179`, `#180` — export controllers use `App::isAdmin()` / `App::hasRole('Approver')`.
`#181`, `#188` — upgrade.php + Migrator have try/catch.
`#184`, `#185`, `#186`, `#187` — schema drift (siteID on tblRecurrenceRules, tblAnnouncements, tblDocCategories+tblDocuments, totp columns+tblTotpBackupCodes) all present in full_schema.sql.

### Scoped to follow-up PRs (left open)

`#161` SRI audit (gaps in Sortable + Swagger CDN tags; recommend vendoring).
`#225` Mobile audit (requires physical devices).
`#233` PWA offline-first with sync queue (XL).
`#234` MS365 Graph delegate sending (XL).
`#235` GDPR right-to-erasure (XL; needs policy doc first).
`#236` Photo approval queue (XL; needs photo upload pipeline first).
`#239` Invite-based onboarding (L).
`#240` Offboarding workflow (L).

### Added — HTML email templates + Portal.Confirm modal (post-omnibus polish)

- **#243** `Mailer::send()` signature broadened to `string|array $to` with auto-HTML detection; new `Mailer::sendTemplated($to, $subject, $template, $vars)` helper. 4 templates shipped under `web/_core/templates/email/`: `base`, `password-reset`, `invite`, `critical-alert`. Logger alerts now use the templated `critical-alert` for nicely-formatted email. Admin preview at `/admin/integrations/email-templates`. **Side fix:** original omnibus calls to `Mailer::send()` passed string `$to` to an array-typed param — TypeError under `strict_types=1`. Signature now accepts both.
- **#244** `Portal.Confirm` themed Bootstrap modal replacement for native `window.confirm()`. Promise-returning `Portal.Confirm.show({title, body, destructive, confirmLabel, cancelLabel})`. Auto-binds via `data-confirm` attribute on forms AND on buttons (preserving button name/value via hidden shim for multi-action forms). All 19 `confirm()` call sites migrated. CI guard `tools/audit-checks/check_no_native_confirm.py` prevents regression.

### Added — Apache-level themed error pages

`.htaccess` `ErrorDocument` directives route 403/404/500/503 through `/error.php` → `Router::renderError()` → themed templates. Fallback to self-contained themed HTML when bootstrap is broken (so even a bootstrap fatal renders the right brand).

### Added — Welsh locale gating + per-user Sabbath override

- `portal.i18n.minimum_coverage_for_switcher` setting — hides locales below threshold from the language switcher (except current). Set to `0.95` to hide Welsh until parity reached. Current locale always shown so user can switch back.
- `/account/notifications` surfaces the `tblUsers.sabbathHonour` per-user override (inherit / on / off) when `portal.sabbath.enabled = '1'` at org level.

### Fixed — Polish from omnibus review

- Cookie banner class-name mismatch (`print.css` now hides `.portal-cookie-banner`).
- Demo users now `isActive = 0` with valid bcrypt format so they cannot sign in regardless of password hash exposure.
- System Health "Active sessions" probe rewritten to count files in `session_save_path()` (codebase uses PHP native sessions, not the non-existent `tblSessions`).
- CSRF guard added to `/admin/settings/dismiss-first-run` handler (raised in `pr-security.yml` review of PR #245).
- Defence-in-depth `htmlspecialchars()` on top of `urlencode()` for email-template inspect iframe src (raised in same review).

### Migrations 061–072 + `demo_data.sql`

13 idempotent migrations added in this release. Fresh installs pick them up from `full_schema.sql`; stale-DB retries run them via the migration runner from PR #218.

### Changed — Version bumped 1.1.1 → 1.2.0

Net-new feature surface across security, ops, UX, and core → minor bump per semver.

### Added — Waves 3 / 4 / 5 install-on-demand apps (PRs #283 / #284 / #285)

16 new apps shipped across three branches, all on top of the App Registry pattern (`_core/apps/{slug}.php` auto-discovery, `enabled = 0` default, per-site toggle at `/admin/apps`).

**Wave 3 (#283):** `#265` Reading Plans (Bible-in-a-Year, streak counter) · `#275` QR generator + CueRCode adapter slot · `#239` Invite-based onboarding (SHA-256 hashed tokens, public acceptance route) · `#240` One-click offboarding (7-step atomic revocation + 7-day rehire window).

**Wave 4 (#284):** `#263` Resources (room/asset booking with overlap conflict detection) · `#262` Service Plans (printable run-sheet builder) · `#273` Livestream (YouTube/Vimeo/Twitch/Facebook embed + countdown) · `#264` Recordings (RSS podcast feed + HTTP Range streaming + FULLTEXT search) · `#274` Zoom (OAuth + meeting creation from calendar + webhook HMAC verification) · `#269` Newsletter (composer with auto-pulled content blocks, provider abstraction reserves a `webMailerMatt` slot) · `#266` Giving (tithe log, Gift Aid digital declaration, HMRC Schedule CSV, dompdf year-end statement) · `#272` SMS (Twilio + MessageBird + SigV4-signed AWS SNS, verification, per-category opt-in, Sabbath quiet hours) · `#267` Projects (public fundraising pages with captcha-gated anonymous pledges, thermometer, updates feed) · `#268` Payments (Stripe Checkout + v1 HMAC webhook + refund; side-effects route into Giving + Projects via `tblPayment.purpose`).

**Wave 5 (#285):** `#276` Transcription (Whisper / AssemblyAI / local whisper.cpp, FULLTEXT search, click-to-timestamp) · `#278` Translation (Anthropic / OpenAI / Google / DeepL / LibreTranslate, content-addressable cache, 10-locale heuristic detection including Welsh) · `#277` AI Assist (Anthropic / OpenAI / ollama, 4 editable prompt-template kinds, monthly cap + per-user daily limit + audit trail) · `#235` GDPR Article 17 erasure engine (19-table catalogue, sealed audit chain via chained SHA-256, one-month SLA queue) · `#236` Photos (4-tier role visibility, moderation queue, EXIF-aware GD re-encode strips metadata for non-privileged downloads) · `#249` Off-site backup (weekly AES-256-CBC to rclone/S3/SFTP) · `#250` Disaster-recovery runbook + in-portal landing · `#161` CDN SRI audit + Asset helpers for Sortable + Swagger UI · `#248` End-to-end MySQL migration test harness · `#225` Static mobile readiness audit + worksheet.

### Added — Post-wave-5 infrastructure hardening (PRs #286-#293)

- **PR #286** — Doc sweep cleaning up `core/` / `vendor/` / `sql/` → `_core/` / `_vendor/` / `_sql/` references (#189, #182, #183, #194). Issues #190, #191, #192, #193 verified already-resolved.
- **PR #287** — `auto-merge-alpha.yml` verification (#147). Workflow structurally correct; 0 runs to date because no PR has ever targeted `alpha`. Documented re-verification procedure + 6-month delete-if-still-unused criterion in DEV_NOTES.
- **PR #288** — Defence-in-depth refactor (#159): **317 PHP files** in 37 app dirs `git mv`'d from `web/public_html/` to `web/_apps/`. Only entry-point files (`index.php`, `error.php`, `api-docs/index.php`) remain in the webroot. Router falls back to `public_html/` for legitimate-entry-point routes.
- **PR #289** — Nonce-based CSP `script-src` tightening (#144). New `App::cspNonce()` static method (16-byte hex, request-memoised). Modern browsers strictly enforce the nonce; `'unsafe-inline'` retained as a fallback for older browsers per CSP3 semantics.
- **PR #290** — External error monitor (#143). New `Portal\Core\ErrorMonitor` adapter — Sentry- and GlitchTip-compatible store-API envelope; SigV4-style auth via `X-Sentry-Auth` header; 2 s connect + 5 s total timeout; sample-rate gate; admin smoke-test endpoint. Hooked into `Logger::errorPlatform()` alongside the existing critical-alert dispatch.
- **PR #291** — REST API write-side CRUD (#157): 10 new endpoints — Announcements full CRUD; Tasks create/complete/delete; Prayer Requests create/moderate; Leadership assign/unassign. OpenAPI spec now has 23 paths (up from 13). Documents/Attendance/Expenses deferred (different module shapes).
- **PR #292** — PWA offline write queue (#233). New `Portal.OfflineQueue` IndexedDB module; `data-offline-queueable` form interceptor; Background Sync API integration in `sw.js`; `/account/offline-queue` user inspector; connection indicator dot in footer. `X-Offline-Queued-At` header carries the original submission timestamp to receiving endpoints.
- **PR #293** — Codebase audit sweep: duplicate cookie banner in `footer.php` removed (was bypassing the GDPR consent pipeline); missing `Auth` import in `footer.php` fixed (would have thrown `ClassNotFoundException` on first cookie-banner render); 6 SQL `int`-concatenation queries converted to prepared statements for consistency with the rest of the codebase (no security impact — all int-cast at source).

### Changed — `_apps/` defence-in-depth refactor (PR #288)

Architectural-level change: every app controller now lives **outside** `public_html/`. The `PORTAL_APPS` constant in `bootstrap.php` now points at `web/_apps/`. Router-level fallback to `public_html/` for the small set of routes that legitimately render entry-point pages (Swagger UI, openapi.json, PWA offline fallback). `.htaccess` deny-`.php` rule simplified — exempts only the 3 known entry points (`index.php`, `api-docs/index.php`, `error.php`). All 7 audit scripts updated to scan `_apps/` alongside `public_html/`.

### Audit scripts (`tools/audit-checks/`)

Three new static-analysis scripts added across the post-wave-5 work:

- `check_cdn_sri.py` (#161) — flags `<script>`/`<link>` tags pointing at known CDN hosts without `integrity=` attributes.
- `check_migration_idempotency.py` (#248) — flags `CREATE TABLE` / `ADD COLUMN` / `CREATE INDEX` without `IF NOT EXISTS` and `INSERT` without `ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`. Quote-aware splitter respects `;` inside string literals.
- `check_mobile_readiness.py` (#225) — flags missing `<meta viewport>`, hard-coded widths > 320 px, bare `<table>` outside `.table-responsive`, file inputs without `accept=`, modals without `modal-fullscreen-sm-down`.

### Audit pass status (2026-06-03)

- `check_route_targets.py`: 304 routes, **0 missing target files**
- `check_sql_columns.py`: 109 tables, **0 mismatches**
- `check_no_native_confirm.py`: **0 findings**
- `check_cdn_sri.py`: **0 findings**
- `check_settings_keys.py`: 3 informational (all have `??` fallbacks)
- `check_migration_idempotency.py`: 19 informational (pre-multi-site historical cohort, Migrator-protected)
- `check_mobile_readiness.py`: 29 informational fix targets (concrete sweep candidates)

## [1.1.1] - Unreleased

### Added — Authorised-use notice on the login screen (#221)

All sign-in paths (local, MS365, Google, passkey) land on `/auth/login`, so a single notice at the bottom of that page covers every entry point. The notice is mandatory and cannot be excluded — there's no opt-out toggle:

> **Authorised use only.** {site_name} is a private intranet for staff and volunteers. All sign-ins, page views, and actions are logged and retained for audit purposes. By signing in you acknowledge that you are an authorised user and consent to monitoring of your activity.

i18n keys: `auth.terms_notice_heading`, `auth.terms_notice` (with `:site_name` placeholder). English + Welsh translations supplied.

## [1.1.0] - Unreleased

### Added — Installer state detection + Drop-and-rebuild option (#220)

The installer now probes the target database after credentials validate and classifies into one of:

- `EMPTY` — fresh install (current path)
- `PARTIAL` — tables exist, no `.installed` lock → render step 2.5 (Continue / Drop)
- `INSTALLED_CURRENT` — same version → redirect to portal
- `INSTALLED_UPGRADE` — older version → step 2.5 with upgrade messaging
- `FRESH_REQUIRED` — installed below `fresh_required_below` policy threshold → step 2.5 with continue disabled

Drop-and-rebuild is gated by a typed hostname confirmation (configurable via `portal.upgrade.require_hostname_confirm`).

New files:
- `web/_install/upgrade-policy.php` — static version-threshold + backup defaults
- `web/_install/db_state.php` — `detectDbState()` helper (bootstrap-free)

Installer modifications:
- Step 2 calls `detectDbState()` after credentials validate
- New step `2.5` renders the choice page with state-specific messaging
- Step 3 honours `$_SESSION['install_action']` — `'drop'` triggers `DROP TABLE` of every `tbl*` table (FK_CHECKS off) before re-running schema
- Step 5 writes `portal.installed_version = INSTALL_VERSION` and clears any leftover maintenance flag

### Added — Auto-backup before upgrade migrations + JSON snapshot engine

New `Portal\Core\DbBackup` class. Used by `/admin/upgrade`:

- Snapshots every `tbl*` table to `web/_backups/upgrade-YYYYMMDD-HHMMSS/{tableName}.json` before any migration runs.
- `_manifest.json` per snapshot with schema metadata, row counts, SHA-256 hashes.
- JSON per-table format chosen for programmatic restore (the future per-migration "rescue script" path for Tier-3 breaking changes).
- Retention: `portal.upgrade.backup.keep_last_n` (default 10) — pruned LIFO on each successful upgrade.
- Can be disabled via `portal.upgrade.backup.enabled = 0` (not recommended).

`DbBackup::restoreTable()` reads a snapshot and re-INSERTs in a transaction. The admin restore UI is a follow-up PR.

### Added — Maintenance mode (auto on during upgrade, auto off when done)

New `Portal\Core\Maintenance` class + front-controller gate:

- Active when EITHER `portal.maintenance.active = '1'` OR `portal.installed_version < PORTAL_VERSION` (version drift).
- Front controller (`public_html/index.php`) renders a themed 503 maintenance page (with `prefers-color-scheme`, auto-refresh every 60s) for non-admin / non-allow-listed requests.
- Allow list: `/auth/login`, `/auth/logout`, `/admin/upgrade`, `/admin/maintenance`, `/assets/`, `/offline`. Admins always pass through.
- `/admin/upgrade` flips the flag on at the start of migrations, updates `portal.installed_version` on success, flips the flag off. Both signals clear automatically.

### Added — Migration 060 + tblSettings registrations

`web/_sql/060_portal_versioning_and_maintenance.sql` seeds the new keys:
- `portal.installed_version`
- `portal.maintenance.active`, `portal.maintenance.message`
- `portal.upgrade.backup.enabled`, `portal.upgrade.backup.keep_last_n`
- `portal.upgrade.fresh_required_below`, `portal.upgrade.require_hostname_confirm`

All idempotent. Fresh installs pick them up from `full_schema.sql`'s seed; stale-DB retries get them via the migration runner from #218 / PR #219.

### Changed — Version bumped 1.0.1 → 1.1.0

Net-new feature surface → minor bump per semver.

### Not in this PR (follow-ups)

- Admin UI for browsing / restoring snapshots (`/admin/maintenance/backup`). The `DbBackup` engine is in place; the UI is a separate PR.
- Per-migration "rescue script" mechanism for Tier-3 breaking schema changes (needed when `fresh_required_below` is first bumped above `0.0.0`).
- Existing `/admin/settings` UI already supports editing the new `portal.upgrade.*` and `portal.maintenance.*` keys (they're regular tblSettings rows).

## [1.0.1] - Unreleased

### Fixed — Installer is now resilient to schema drift across retries

The installer's step 3 ran `full_schema.sql` and stopped there. Because
`CREATE TABLE IF NOT EXISTS` is a no-op when a table already exists,
any failed install that reached step 3 successfully locked the database
into whatever shape the schema had at that moment. If a later code
change added a column to one of those tables (via a numbered migration),
the user's retry would still hit the old shape — and step 4's INSERTs,
which assume the current shape, would fatal with
`Unknown column '<colname>' in 'field list'`.

This was the root cause of three consecutive support rounds:
- `tblUsers.siteID` doesn't exist (#198 / PR #199 — fixed the schema
   but stale DBs still fatalled)
- `tblLocalAccounts.isVerified` doesn't exist (this round)

The schema-vs-runtime audits the project ran in #197/#199/#212/#214
were all looking at the wrong dimension — schema vs PHP code — when
the actual breakage was schema-on-disk vs schema-in-this-DB.

**Fix**: step 3 now runs `full_schema.sql` AND then runs every
numbered migration in `web/_sql/0NN_*.sql` order. Every migration
uses idempotent constructs (`ADD COLUMN IF NOT EXISTS`,
`INSERT ... ON DUPLICATE KEY UPDATE`, `DELETE FROM ... WHERE`), so
fresh installs see them as no-ops (`full_schema.sql` already has
every column the migrations would add) and stale-DB retries pick up
the missing columns. The installer doesn't go through
`Portal\Core\Migrator` because (a) Migrator filters by
`tblMigrations`, which `full_schema.sql` seeds as fully-applied, and
(b) the installer is bootstrap-free.

### Fixed — `tblTasks` SELECT uses non-existent `dueAt` column (#218)

`web/public_html/tasks/api/list.php` issued a `SELECT taskID, title,
description, dueAt, ...` against `tblTasks`. The schema column is
`dueDate`, not `dueAt`. The `/api/tasks/list` endpoint would 500 on
every call. Surfaced by the widened
`tools/audit-checks/check_sql_columns.py` — see next entry.

### Changed — `check_sql_columns.py` now scans UPDATE + SELECT, not just INSERT

The CI consistency check added in #214 only walked `INSERT INTO tbl
(col, col)` column lists. It missed bugs in `UPDATE tbl SET col = ?`
clauses and `SELECT col, col FROM tbl` lists — which is how
`tblTasks.dueAt` slipped past the previous "exhaustive" sweep.

The widened check now matches:

- `INSERT INTO tbl (cols…)` (already supported)
- `UPDATE tbl SET col = …, col = …` — bounded so we don't span across
  PHP-concatenated multi-statement strings or beyond `WHERE` / `ORDER`
- `SELECT col, col FROM tbl` — only when the column list is simple
  identifiers (no functions, joins, aliases, or `*`); skips pure
  numeric "columns" so `SELECT 1 FROM tbl WHERE …` existence checks
  don't false-positive

Line-number reporting also fixed: under PHP string concatenation,
`'INSERT (' . 'col1, col2)'` is reconstructed for matching, which
collapses newlines and confused the previous offset lookup. We now
compute line numbers against the reconstructed text, which keeps the
report within a few lines of the actual SQL.

### Changed — Version bumped to 1.0.1

## [Unreleased]

### Fixed — Audit follow-up #3: cross-source consistency

A four-dimension parallel audit (SQL ↔ schema, routes ↔ files, settings ↔
code, i18n keys ↔ translations) surfaced 13 more findings. This entry
covers the 6 actionable runtime bugs (#201, #202, #204, #205, #206, #207).
Two settings findings (#203 + #208) were re-investigated and closed as
false positives — both keys were seeded further down in
`full_schema.sql` than the audit looked. The i18n findings (#209, #210,
#211) are tracked separately for cleanup / triage.

- **`tblEvents.deletedAt` missing column** (#201) — `events/api/delete.php`
  sets `deletedAt = NOW()` alongside `isDeleted = 1` in its soft-delete
  UPDATE, but the column was never added. Every admin event-delete
  fatalled with `Unknown column 'deletedAt'`. Added to schema +
  migration `054_events_deleted_at.sql`.
- **`admin/upgrade` route had invalid targetFile** (#202) — pointed at
  `../install/upgrade.php` (escapes `public_html/`, uses the
  pre-rename `install/` name). Clicking the link 404'd. New proxy
  file `web/public_html/admin/upgrade.php` `require`s the real handler
  at `_install/upgrade.php`; route now points at the proxy. Migration
  `055_admin_upgrade_route_fix.sql` repoints the route on existing
  installs.
- **Five redundant `api/*` routes** (#204) — migration 035 inserted
  `api/announcements/list`, `api/attendance/list`, `api/events/detail`,
  `api/events/list`, `api/users/list` against a routing shape the
  router doesn't actually use (the `api/` prefix is special-cased to
  `ApiRouter::dispatch()` which resolves `{app}/api/{action}.php`).
  Pure dead config. Migration `056_remove_redundant_api_routes.sql`
  DELETEs them on existing installs.
- **Dead `account/linked-accounts` route** (#205) — target file
  `auth/account/linked-accounts.php` was never created. Removed from
  schema + migration `057_remove_dead_linked_accounts_route.sql`.
- **`login/webauthn` route missing** (#206) — the WebAuthn AJAX
  endpoint at `auth/login/webauthn.php` was called from the login
  form via `fetch('/login/webauthn')` but had no route entry. Worked
  via `.htaccess` fall-through until the v1.0 security hardening
  tightened direct-PHP access; now needs the route. Added to schema +
  migration `058_login_webauthn_route.sql`. `isProtected=0` (pre-auth).
- **`api.expenses.delete.enabled` wrongly marked `isSensitive=1`**
  (#207) — the value is the boolean flag `'false'`, not a credential.
  Sensitive=1 makes the bootstrap loader try to libsodium-decrypt the
  plaintext on every request. Flipped to 0 in schema + migration
  `059_fix_api_expenses_delete_isSensitive.sql`.

### Fixed — Audit follow-up #2: SQL column-name mismatches (#198)

A class of bug my prior audit didn't check for: runtime SQL statements
that reference columns which don't exist on the named table. Surfaced
when the user hit step 4 of the installer with `Unknown column 'siteID'
in 'field list'`. A targeted re-audit found 5 more instances of the
same shape across 3 files.

- **Installer step 4** — `web/_install/index.php:306` removed `siteID`
  from the `tblUsers` INSERT. `tblUsers` has no `siteID` column; multi-
  site assignment is via `tblUserSites` (already inserted on the next
  statement).
- **Bulk user import** — `web/public_html/admin/users/import.php:156`
  had the identical `tblUsers.siteID` bug. Removed; `bind_param` updated.
- **GDPR data export** — `web/public_html/auth/account/data-export.php`:
  - `tblLinkedAccounts`: `createdAt` → `linkedAt`
  - `tblWebAuthnCredentials`: `label` → `friendlyName`
  - `tblExpenseClaims`: `submittedByID` → `userID`
  Three columns the schema never had / had renamed — would have fatalled
  every `/account/data-export` request.
- **tblLocalAccounts schema drift** — runtime code (installer step 4 +
  GDPR export) referenced `isVerified` and `createdAt` columns that no
  migration ever added to `tblLocalAccounts`. Both clearly intended
  (installer sets `isVerified = 1` for the admin; GDPR export reads
  both). Added to `full_schema.sql` CREATE TABLE; new migration
  `053_local_accounts_columns.sql` adds them to existing installs via
  idempotent `ADD COLUMN IF NOT EXISTS`.

The honest reason this slipped past the previous "thorough check": my
audit prompts covered uncaught exceptions, schema-vs-migration drift,
path drift, and CSRF/auth gates — but not "do runtime SQL column names
exist on the named tables". Added that dimension to the audit
playbook for the next sweep.


### Fixed — Codebase audit follow-ups

- **Bootstrap critical-path mysqli exceptions** (#173, #174, #175) — wrapped
  three unguarded mysqli call sites in `web/_core/bootstrap.php` (settings
  load at line 341, user-locale lookup at line 469, and `Site::preDetect()`
  at line 326) in try/catch with graceful fallbacks. Without these, any DB
  hiccup in the bootstrap critical path produced a bare HTTP 500 for every
  request to the portal — bypassing the global exception handler because
  these queries run before / outside its safety net.
- **CI workflow paths** (#176) — `version-bump.yml` and `changelog.yml`
  pointed at `web/core/App.php` which no longer exists (renamed to
  `_core/version.php`). The next push to `alpha`/`beta` would have failed.
  Workflows now read from `web/_core/version.php` using a `^return '...';`
  grep pattern matching the new file shape.
- **Admin/approver gates routed through Auth instead of App** (#177, #178,
  #179, #180) — four export handlers (`admin/activity/export.php`,
  `attendance/export.php`, `admin/users/export.php`,
  `expenses/api/export.php`) called `Auth::isAdmin()` and `Auth::isApprover()`
  which don't exist on `Portal\Core\Auth` — fatal on any access. Replaced
  with `App::isAdmin()` and `App::hasRole('Approver')` (the patterns used
  consistently elsewhere).
- **Schema drift — missing app tables** (#184, #185, #186, #187, #191,
  #192, #193, #194) — `web/_sql/full_schema.sql` was missing entire tables
  from 6 migrations even though `tblMigrations` was seeded marking them
  executed. Ported:
  - Migration 018: `siteID` column + FK on `tblRecurrenceRules`
  - Migration 029: `tblAnnouncements` + app routes + settings
  - Migration 030: `tblDocCategories`, `tblDocuments` + routes + settings
  - Migration 031: `tblAuditTrail` + admin/audit route
  - Migration 032: `totpSecret` / `totpEnabled` columns on `tblUsers`,
    `tblTotpBackupCodes` table, 2FA routes + settings
  - Migration 034: `tblWorkflows`, `tblWorkflowSteps`,
    `tblWorkflowInstances`, `tblWorkflowActions` + routes + default
    expense-approval workflow seed
  - Migration 036: `tblTasks` + routes + settings
  - Schema header updated to reflect actual coverage (000-052, was
    claiming 000-036).

### Fixed

- **`full_schema.sql` missing default site seed** (#171) — every
  fresh install since the migrations were consolidated into
  `web/_sql/full_schema.sql` was failing at step 3 with
  `Cannot add or update a child row: a foreign key constraint
  fails (...\`tblEventTypes\`, CONSTRAINT \`fk_etype_site\` FOREIGN KEY
  (\`siteID\`) REFERENCES \`tblSites\`(\`siteID\`))`. A dozen tables
  declare `siteID INT NOT NULL DEFAULT 1` with an FK back to
  `tblSites`, but the consolidated schema had dropped the
  `INSERT INTO tblSites … siteID=1 …` seed that the original
  `015_multisite.sql` migration provided. Restored the seed at the
  top of the data-seed section using the same idempotent
  `INSERT … SELECT … WHERE NOT EXISTS` pattern. This was masked by
  the original HTTP 500 (#169); now that the installer surfaces the
  underlying MySQL message the cause is visible and fixable.
- **Installer HTTP 500 on steps 3 and 4** (#169) — the `install_schema`
  and `create_admin` POST handlers in `web/_install/index.php` ran
  `multi_query` / `prepare` / `execute` without try/catch wrappers.
  Under PHP 8.1+ mysqli defaults to strict-exception mode (and
  `installGetDb()` re-asserts it), so ANY SQL error in
  `full_schema.sql` (DDL conflict, FK ordering, version-specific
  syntax, privilege denied, charset mismatch, …) — or a duplicate-email
  INSERT during admin creation — threw an uncaught
  `mysqli_sql_exception` and fatalled the request with a bare
  HTTP 500. The existing `if ($stmt === false)` / `$db->errno`
  branches were dead code under strict mode. Both handlers are now
  wrapped in `try { … } catch (\mysqli_sql_exception $e) { … }` that
  surfaces the real MySQL message to the user and re-renders the
  current step. The schema-error capture loop now keeps the FIRST
  error message (was overwriting with later "Commands out of sync"
  follow-ups).
- **Installer link colours** (#167) — fixed truncated Bootstrap 5.3.3 CSS
  SRI hash in `web/_install/index.php` that was causing the browser to
  reject the stylesheet, leaving anchors (most visibly the `← Back`
  button on every step) rendered in browser-default blue. Added a
  defensive `a { color: var(--bs-link-color); }` rule so the installer
  stays readable even if the CDN is blocked or the integrity check
  fails again. Added dark-mode overrides for `.alert-info`,
  `.alert-warning`, `.alert-success`, `.alert-danger` to mirror the
  runtime portal (`portal.css` already had them — the installer was
  missing them because it bundles its own standalone CSS). Re-themed
  the "Already Installed" lockout page to use the indigo palette with
  `prefers-color-scheme` awareness.
- **Alert links + code chips, portal-wide** (#167) — added `.alert a`
  and `.alert code` polish to `portal.css` so anchors and inline code
  inside any `.alert-*` box pick up the alert's own tonal foreground
  via `currentColor`, instead of falling through to the global indigo
  link colour (which clashes against alert-warning amber /
  alert-success green).

## [1.0.0] - 2026-05-22 — 🚀 v1.0 launch

A "bumper" release pulling everything needed for the v1.0 launch into one
cohesive ship. Includes every privacy / security / operational item that
was either launch-blocking or worth landing pre-launch, plus the API
surface + admin tooling to support it.

### Added — Operational basics

- **Healthcheck endpoint** at `/health` — JSON status with `db` ping
  (returns 503 + `db: "error"` if the database is unreachable). Designed
  for CloudFlare / Pingdom / GitHub Actions uptime probes.
- **`robots.txt`** + meta-robots fallback. Internal portal by default —
  general search engines + AI training crawlers (GPTBot, ClaudeBot,
  anthropic-ai, Google-Extended, PerplexityBot, CCBot, Bytespider, …) all
  blocked. Two per-site settings (`site.allowIndexing`,
  `site.allowAiIndexing`) for opt-in.
- **Release Notes viewer** at `/admin/release-notes` — renders this
  CHANGELOG with a paranoid mini-Markdown renderer (no script
  execution risk).
- **Audit-log retention sweeper** at `/admin/maintenance/retention` —
  hard-deletes rows from `tblActivityLogs` + `tblErrors` past the
  configured window (default 365 days). Two-mode: web UI for one-off
  cleanup, token-gated cron endpoint for nightly scheduling.

### Added — Security hardening

- **Composite IP + username rate-limit** (#52) — defends single-account
  targeted attacks rotating IPs (which pure IP limiting missed). New
  `RateLimiter::isUserOrIpBlocked()` checks both per-IP (default 5/15min)
  and per-username (default 10/15min) counters.
- **2FA "Remember this device"** cookie — after a successful TOTP
  challenge, users can opt in to a 30-day device-trust cookie (only the
  SHA-256 hash is stored). Revoked on password change for security.
- **Wired up the dormant TOTP gate** — the 2FA verify page existed but
  `$_SESSION['2fa_user_id']` was never set; `Auth::loginLocal()` skipped
  the challenge. Now correctly demotes the session post-password and
  routes through `/auth/2fa/verify` when `totpEnabled = 1`.
- **SVG upload XSS fix** — `calendar/manage/save.php` allowed SVG event
  images which could carry inline scripts. SVG removed from allow-list;
  added 5 MB size cap + `finfo_file()` MIME sniff so the detected type
  must match the claimed extension.

### Added — Privacy & GDPR (#47)

- **`/privacy`** — auto-generated public privacy policy reading
  admin-editable settings (`privacy.controllerName`, `contactEmail`,
  `dataRetentionDays`). Honours `privacy.policyURL` if set (external
  redirect).
- **Cookie consent banner** in footer — Accept / Necessary only / More
  info. Each decision recorded in the new `tblConsentLog` with a
  SHA-256 hash of the policy snapshot at decision time + IP + UA.
- **Data export** at `/account/data-export` — bundles every record about
  the user (profile, local-account, SSO links, passkeys, password-reset
  history, activity logs, expense claims, prayer requests, consent log,
  trusted devices) into a downloadable JSON. Sensitive fields
  (passwordHash, totpSecret, tokenHash) explicitly stripped.
- **Account self-deletion** at `/account/delete` + confirm — two-step
  flow with typed confirmation phrase. Hard-deletes secrets tables and
  anonymises everything else via `ON DELETE SET NULL` FKs. Root admins
  can't self-delete (would lock the umbrella).

### Added — REST API + Documentation

- **Relocated** 5 existing API endpoints from `public_html/api/{app}/{action}.php`
  to `public_html/{app}/api/{action}.php` (the path `ApiRouter::dispatch()`
  actually looks at — they were unreachable before).
- **Events full CRUD** at `/api/events/{list,detail,create,update,delete}`
  as the canonical write-side example (admin-only, CSRF via header or
  body, JSON request/response).
- **List endpoints for previously API-less modules**: leadership,
  tasks, prayer-requests, documents — all visibility-rule-aware
  (tasks own-only for non-admins; prayer-requests masks anonymous
  submitter for non-admin readers).
- **Per-endpoint enable flags** (`api.{module}.{action}.enabled`) so
  admins can selectively disable any endpoint per-site.
- **OpenAPI 3.0 spec** at `/openapi.json` describing every endpoint
  (paths, parameters, request/response schemas, security model).
- **Swagger UI** at `/api-docs` — loads the spec, includes a
  `requestInterceptor` that injects `X-CSRF-TOKEN` on writes so the
  interactive docs drive real authenticated requests against the
  portal.

### Added — Admin tooling

- **Notification preferences UI** at `/account/notifications` — 8
  per-channel switches stored as JSON in `tblUsers.notifyPrefs`.
  Delivery gated by `notifications.deliveryReady` (default false in v1.0);
  the UI explains preferences are saved but emails aren't sent yet.
- **Email template editor** at `/admin/email-templates` — DB-backed
  templates with Mustache-style `{{token}}` substitution. Global
  defaults + per-site overrides. Sandboxed `<iframe srcdoc>` preview
  with sample-token substitution. Seeded with three real templates
  (`auth.passwordReset`, `expenses.statusUpdate`, `expenses.approverNudge`).
- **Bulk import (CSV)** for events (`/calendar/manage/import`) and
  leadership assignments (`/leadership/manage/import`). Two-step
  preview/confirm UX matching `/admin/users/import`. Excel users
  directed to "Save As → CSV".

### Added — CI / DevEx

- **Dependabot** — weekly GitHub Actions version-bump PRs (grouped).
- **CodeQL** scanning JavaScript on every push + weekly schedule.
- **Psalm** PHP static analysis with SARIF upload to GitHub Security
  tab + a `php -l` lint sweep as a hard gate.
- **Existing**: `gitleaks` secret scanning (already in `pr-security.yml`),
  branch-protection ruleset audit (`repo-config-audit.yml`).

### Changed

- **Removed `web/public_html_dev/`** from the repo — never deployed
  anywhere (deploy source is `web/public_html/` for every branch,
  destinations differ). Documented branch-based deploy model in
  `bootstrap.php`, Gatekeeper, Router, README, DEV_NOTES, .claude/CLAUDE.md.
- **CHANGELOG.md now ships in `web/CHANGELOG.md`** at deploy time (copied
  from repo root) so the new Release Notes viewer can find it on the server.
- **Deprecated `curl_close()` calls** removed from `Auth.php` and
  `MailerGoogle.php` (PHP 8+ auto-closes cURL handles).

### Schema

Migrations **044 → 052**:

| # | What |
| --- | --- |
| 044 | `site.allowIndexing` + `site.allowAiIndexing` settings |
| 045 | `auth.rateLimit.maxAttemptsByUsername` (#52) |
| 046 | Audit-log retention settings + admin route |
| 047 | `tblTrustedDevices` + `auth.twoFactor.trustedDeviceDays` |
| 048 | `tblConsentLog` + `privacy.*` settings + 5 new routes |
| 049 | REST API enable-flag seeds + relocated endpoints + Swagger routes |
| 050 | `notifications.deliveryReady` gate + 2 new routes |
| 051 | `tblEmailTemplates` + 3 seed templates + 4 admin routes |
| 052 | Bulk-importer routes (events + leadership) |

### Known follow-ups (issues filed)

- #141 — PWA install prompt + push notifications
- #142–#147 — Operational tweaks (backup verification, Sentry monitoring,
  CSP nonces, composer.json/Dependabot, visual regression tests,
  auto-merge-alpha rollforward)
- #148–#154 — Brand-new app proposals (Pastoral Care, Stewardship /
  Giving, Sabbath School / Small Groups, Communications Hub, Resource
  Booking, Forms Builder, Library / Resources)
- #155 — Plugin / Extension framework
- #156 — Reports builder UI (drag-and-drop)
- #157 — Complete REST API write-side CRUD on remaining modules

## [0.12.0] - 2026-05-22

### Added — Calendar: per-month strap-lines + category display-style toggle (follow-ups to #136)

Two enhancements deferred from the original calendar-views PR (#137):

- **Per-month strap-lines / themes** on the year planner. A new table
  `tblCalendarMonthThemes` stores one text line per (site, year, month)
  that appears under each month name on `/calendar?view=year`. Managed
  via a new admin page at `/calendar/manage/month-themes` (year picker
  with 12 inputs; empty values delete the existing row).
- **`tblEventCategories.color` + `tblEventCategories.displayStyle`**
  columns. Categories can now carry a colour and choose whether that
  colour renders as a **tinted background band** (default — used for
  organisational scopes like "Area 8", "Conference", "Union") or as
  **coloured text** on the default background (used for events like
  Bank Holidays / Notable Days that just want to flag the day, not
  fill the band).
- Category management UI at `/calendar/manage/types` exposes a colour
  picker + display-style dropdown for every category. Existing
  categories get an inline form to update appearance in-place.
- Year-planner legend renders text-style categories as a coloured
  word rather than a swatch + name, mirroring how they'll appear in
  the planner.
- Migration `web/sql/043_calendar_categories_and_month_themes.sql`
  adds the new columns, the new table, and the admin route.

### Added — Calendar multi-view modes (#136)

The calendar app was previously list-view only. It now supports **seven**
view modes that the spec originally called for:

- **Day** — single day, vertical hour timeline.
- **Week** — full 7-day grid (Mon → Sun), hour timeline.
- **Weekdays** — 5-column grid (Mon → Fri).
- **Weekend** — 2-column grid (Sat → Sun).
- **Month** — 7×5/6 calendar grid; up to 3 event pills per day, "+ N more"
  link to the day view for busier days.
- **Year** — 12-column wall-planner grid (months across, days down).
  Cells show day-of-week initial + up to 3 colour-coded category dots.
- **List** — the original card-grid layout, refactored into the new shell.

Implementation:

- `web/public_html/calendar/index.php` is now a thin view router that
  validates `?view=`, resolves the visible date range, fetches events in
  one query, and delegates rendering to a per-view partial under
  `web/public_html/calendar/views/`.
- Day / Week / Weekdays / Weekend share a single hour-timeline renderer
  (`views/_day_columns.php`) parametrised by column count.
- Shared header (`views/_shared_header.php`) provides date navigation,
  view-switcher buttons, filters (category / type / show-past).
- Last-used view persists in `localStorage` (`portal-calendar-view`); a
  new admin setting `calendar.defaultView` controls the first-visit
  landing view (default: `month`).
- Events colour-code by `tblEventCategories.color` via an `--ev-color`
  CSS custom property; falls back to `--portal-primary`.
- Mobile-responsive: hour timelines scroll horizontally; month cells
  shrink; pill names hide below 640px.
- Migration `web/sql/042_calendar_default_view.sql` seeds
  `calendar.defaultView`.

### Fixed — Anchor colour falling back to browser default in dark mode

- `portal.css` now binds `--portal-link` (and its hover / RGB variants) to
  Bootstrap's `--bs-link-color`, `--bs-link-color-rgb`,
  `--bs-link-hover-color`, and `--bs-link-hover-color-rgb` in both the
  light `:root` and the `[data-bs-theme="dark"]` blocks. Every `<a>`,
  `.btn-link`, `.alert-link`, and `.link-*` Bootstrap utility now stays
  on the indigo brand colour instead of the browser-default blue that
  clashed in dark mode (visible on installer Step 1 "Continue →" link).
- `install/index.php` mirrors the same binding in its self-contained
  inline `<style>` block so the installer renders consistently with
  the rest of the portal.

### Changed — Deploy workflow: dry-run dispatch + DEV_NOTES troubleshooting (#107)

- `deploy.yml` now accepts a `dry_run` `workflow_dispatch` input. When
  set to `true`, lftp `--dry-run` is threaded into every `mirror`
  invocation (both per-branch `public_html/` and the `--delete` shared
  upload). The run prints exactly what would be uploaded **and what would
  be deleted** on the server, without making any changes — useful before
  structurally-significant deploys (renamed/moved files in `core/`,
  `vendor/`, `sql/`).
- `DEV_NOTES.md → Troubleshooting` gains a "file disappeared from the
  server after deploy" entry documenting the `--delete` mirror semantics
  and the survival rules for `_auth_keys/` / `_uploads/` / `_backups/`.
- Debug-panel troubleshooting note updated to mention the new prod
  refusal (cross-references #54).

### Security — Debug mode hardening in production (#54)

- `Debug::isEnabled()` and `App::isDebug()` now **unconditionally refuse**
  to enable debug mode when `PORTAL_ENV === 'prod'`, regardless of who is
  signed in or what query parameters are present.
- Attempts to set `?debug=true` in prod are logged once per request
  (`DebugBlocked` activity entry with IP + request path) so probing shows
  up in the activity log.
- `bootstrap.php` now hardens PHP error display: in prod `display_errors`,
  `display_startup_errors`, and `html_errors` are all forced to `0`.
  Errors are still **reported** so they're captured by `Logger::phpError()`
  and surface in the admin error log — they just aren't echoed into the
  response.
- The global exception handler already routed detailed traces through
  `App::isDebug()`; since that now refuses prod, no stack traces / file
  paths can leak in production even on unhandled exceptions.

### Security — Password policy hardening (#53)

- **Default minimum length raised from 8 to 12** characters (configurable
  via `auth.password.minLength`).
- Added `auth.password.maxLength` (default `128` — defends against
  pathological inputs; bcrypt truncates at 72 anyway).
- Added `auth.password.requireLowercase` setting (default `true`). The
  validator previously gated the lowercase check on the `requireUppercase`
  flag — fixed so each case requirement is independent.
- Server-side validation is now enforced on **every** password-set flow:
  - Reset password (`/reset-password`) — already validated; now uses
    the shared `Auth::passwordPolicy()` helper for hints.
  - Account change-password — already validated; same shared helper.
  - **Admin user create/update (`/admin/users`)** — previously unvalidated.
  - **Installer (`/install`)** — previously only checked length ≥ 8.
- New helper `Auth::passwordPolicy()` returns the active policy as a
  structured array (rules list + minLength/maxLength/required flags) so
  forms can render the policy consistently.
- **Client-side strength meter** added to all password-set forms via
  `data-portal-password-input` + `data-portal-password-meter` attributes
  (Bootstrap progress bar, 5-step score). Wired into `portal.js` and a
  self-contained inline copy for the bootstrap-free installer.
- Migration `web/sql/041_password_policy_hardening.sql`.

## [0.11.0] - 2026-05-22

### Added — Multi-provider Captcha

The captcha layer now supports three providers and admin-configurable priority:

- **Providers**: Cloudflare Turnstile (default first), Google reCAPTCHA
  (v2 checkbox or v3 invisible/score), and hCaptcha.
- **Admin UI** at `/admin/captcha` with drag-and-drop priority ordering
  (SortableJS) and per-provider site/secret-key inputs. reCAPTCHA gets
  a v2/v3 toggle plus action name + score-threshold inputs for v3.
- **Priority** is stored as `auth.captcha.priority` (comma-separated keys).
  The active provider is the first in the list with both keys configured;
  if nothing is configured the captcha is silently skipped.
- **reCAPTCHA v3** verification enforces both action match (anti-replay)
  and score threshold; default action `submit`, default threshold `0.5`.
- Migration `web/sql/040_captcha_providers.sql` adds hCaptcha key slots,
  v3 action/threshold settings, priority setting, and admin routes.
- `Captcha::activeProvider()`, `Captcha::listProviders()`, and
  `Captcha::normalisePriority()` are new public helpers used by the UI.

### Added — Prayer Requests app

A new portal app at `/prayer-requests` for collecting and tracking prayer
requests, with built-in moderation and an anonymous public-submission route.

- **Logged-in submissions** with per-request visibility — *leadership only*
  (default) or *congregation feed* (visible to all members of the site).
- **Anonymous "display as Anonymous" toggle** for logged-in submitters —
  members see "Anonymous"; leaders still see who submitted for pastoral
  follow-up.
- **Public anonymous route** at `/prayer-requests/anonymous` (no login).
  Protected by CSRF + Captcha + RateLimiter. Hard-coded to leadership-only
  visibility and `pending` status for moderator review.
- **Lifecycle**: pending → active → answered (with optional praise /
  testimony note) → archived. Moderators see a status-grouped queue and
  per-request quick actions.
- **Per-site settings** seeded with sane defaults — feature toggle,
  anonymous allowed, congregation feed allowed, require moderation,
  allow testimony.
- **Help guide** at `/help/prayer-requests` and a tile on the Help Centre
  landing page.
- Migration `web/sql/039_prayer_requests.sql` + `tblPrayerRequests`
  definition added to `full_schema.sql`.

## [0.10.0] - 2026-05-22

### Added — UI refresh: design system, theme modes, per-site branding

A six-PR sweep across the portal and the standalone installer (#111).
The portal now has a coherent design language with full per-site /
per-user customisation.

- **Design tokens** (#114) — `web/public_html/assets/css/portal.css`
  `:root` block refreshed with a Linear-style indigo palette
  (`#5e6ad2` default), expanded type scale, modern semi-bold (600)
  headings, Stripe-style multi-layer shadows, refined motion tokens
  (cubic-bezier easings), and an extended spacing scale.
- **Per-site branding flow** (#116) — `tblSites.primaryColor` and
  `tblSites.faviconPath` (new column via migration `037_site_favicon.sql`)
  flow into the portal's CSS tokens at render time. `header.php`
  injects `--portal-primary` and `--portal-primary-rgb` as an inline
  style on `<html>`; the hover / active / subtle variants are derived
  with `color-mix()` so the whole indigo family shifts when an admin
  picks a different brand colour. `Site::branding('favicon')` added.
- **Theme modes + colour-blind safe palette** (#117) — three theme
  modes (light / dark / `auto` following `prefers-color-scheme`)
  with a 3-state toggle in the navbar. New CB-safe palette via
  `[data-portal-cb="on"]` swapping the semantic tokens (success /
  danger / warning / accent) for a Wong-derived palette
  distinguishable for deutan + protan colour blindness. The
  standalone installer mirrors all of it inline.
- **Navbar + top chrome polish** (#118) — active nav-link state
  becomes primary-coloured on a primary-subtle background (was just
  font-weight); avatar gains primary-tinted ring on dropdown
  hover/open; dropdown menus use layered shadow + branded item
  hover/active states; mobile collapse gets a top-border separator.
- **Forms / cards / alerts / buttons / data-list** (#119) — every
  Bootstrap class consuming pages already use (`.form-control`,
  `.form-label`, `.form-text`, `.card`, `.alert`, `.btn-success`,
  `.btn-outline-*`, `.form-check-input`, validation classes) now
  reads from the design tokens. `portal-data-list` wrapped in a
  single rounded container with branded row hover. Empty-state and
  badge components use tokens (CB-safe automatically). New auth-shell
  card pattern picks up login + forgot/reset password screens
  without markup changes.
- **Dashboard hero + stat widgets + app card grid** (#120) — new
  greeting hero at the top (time-of-day aware, first-name, site-aware
  subtitle); stat widgets get 4 px brand-coloured left accent + lift
  on hover with token-driven colours (CB-safe-aware); app cards get a
  proper hover treatment (lift + layered shadow + primary border
  accent), inline `<style>` block removed.
- **Final polish + dark-mode audit** (#121) — Bootstrap tables, modals,
  pagination, list-group, progress, spinners, tooltips all now read
  from the design tokens. Breadcrumb uses a typographic `›` separator
  (was `/`). Footer gets an inset top-shadow. Dropzone hover uses
  `--portal-primary-subtle`. Explicit dark-mode overrides for tables,
  modals, progress, and portal-badge variants.

### Added — Installation wizard styling polish (#114 + #115)

- `web/install/index.php` standalone styling rewritten across all six
  step layouts (welcome, DB config, schema, admin, finalize, complete)
  to match the portal's design language. Step indicator badges, card
  shadows, form fields, button-row separators, and alert palettes all
  refreshed inline (the installer runs before `bootstrap.php` so it
  cannot load `portal.css`).
- Same theme + CB toggles in the installer header (inline SVG icons
  so the installer doesn't load Font Awesome). Identical FOUC script
  and `localStorage` keys as the portal.

### Added — Multi-branch SFTP deploy + auto-versioning

- **`alpha` / `beta` / `main` branch model** replacing the single-branch
  FTP deploy. `main` → `public_html/`, `beta` → `public_html_beta/`,
  `alpha` → `public_html_dev/`. Shared `core/`, `vendor/`, `sql/` deploy to
  the same remote base from every branch.
- **`.github/workflows/deploy.yml`** rewritten to SFTP (lftp),
  SSH-key-preferred with password fallback, per-branch target resolution,
  change detection, `[deploy all]` / `[skip ci]` flags, and a
  `vars.SFTP_ENABLED` kill switch. New secrets: `SFTP_HOST`, `SFTP_USER`,
  `SFTP_BASE_PATH`, plus `SFTP_KEY` and/or `SFTP_PASSWORD`.
- **`.github/workflows/version-bump.yml`** auto-bumps `web/core/App.php`
  `$version` on push: alpha = PATCH always; beta = Conventional Commits.
- **`.github/workflows/changelog.yml`** auto-appends CHANGELOG entries from
  commit messages since the last `v*` tag.
- **`.github/workflows/release.yml`** creates a GitHub Release on `v*` tag,
  extracting notes from CHANGELOG.md. Tags with `-beta` / `-rc` are pre-release.
- **`.github/workflows/auto-merge-alpha.yml`** enables GitHub auto-merge on
  PRs whose base is `alpha` and dispatches `deploy.yml` once merged.

### Added — dompdf at build time

- **`tools/download-dompdf.sh`** — idempotent fetch of pinned dompdf v3.1.5.
- `deploy.yml` runs the fetch script before SFTP so dompdf rides along in
  the shared upload; manual server-side install is no longer needed.

### Fixed

- **`web/core/bootstrap.php`** — environment detection now matches the new
  `public_html_beta/` directory name (previously only `beta_html`); ordering
  documented to avoid the `public_html` substring trap that would
  misclassify dev/beta as prod.

---

## [0.8.2] - 2026-03-08

### Added — Installation & Upgrade System (Issue #84)

- **Web-based installation wizard** (`install/index.php`) — 6-step guided setup for first-time installations: prerequisites check, database configuration, schema installation, admin user creation, encryption key generation, finalization
- **Upgrade handler** (`install/upgrade.php`) — admin-only page to detect and run pending SQL migrations with progress display and migration history
- **Auto-detection** — front controller redirects to installer when credentials file is missing
- **Shared hosting support** — graceful error handling when database creation permissions are restricted (prompts user to create DB via hosting panel)
- **Security protections** — lock file prevents re-installation; credentials stored outside web root; restrictive file permissions
- SQL migration `025_install_upgrade_route.sql` — route for admin upgrade page

### Added — Dashboard Widgets (Issue #85)

- **Stat widgets** on dashboard — pending expenses, events this week, active users (admin), 24h activity (admin) with quick-link cards

### Added — Email Digest / Notification Preferences (Issue #86)

- **Notification preferences** on account page — toggles for weekly email digest, expense updates, event reminders
- `notifyPrefs` JSON column on `tblUsers` for per-user preferences
- SQL migration `026_notification_preferences.sql`

### Added — Bulk User Import (Issue #87)

- **CSV import** for users (`admin/users/import`) — preview/validate/confirm workflow with duplicate detection
- SQL migration `027_user_import_route.sql`

### Added — Event RSVP / Registration (Issue #88)

- **RSVP system** for calendar events — Going / Maybe / Not Going with capacity limits
- RSVP card on event detail page with response counts and status display
- `tblEventRSVPs` table with UPSERT pattern for response changes
- `capacity` column on `tblEvents` for optional attendee limits
- SQL migration `028_event_rsvp.sql`

### Added — Announcements / Noticeboard (Issue #89)

- **Announcements module** — listing, detail view, admin CRUD with priority levels (normal/important/urgent), pinning, scheduled publish/expiry
- Pinned announcements displayed on dashboard
- SQL migration `029_announcements.sql`

### Added — File / Document Library (Issue #90)

- **Document library** — category browsing, file upload/download with counter, admin category management, file type icons
- SQL migration `030_document_library.sql`

### Added — Audit Trail Improvements (Issue #91)

- **Logger::audit()** method for before/after change tracking with automatic diff generation
- Admin audit trail viewer with table/action filters and pagination
- SQL migration `031_audit_trail.sql`

### Added — Two-Factor Authentication TOTP (Issue #92)

- **TOTP 2FA** — QR code setup, backup codes, post-login verification challenge, disable option
- Pure PHP RFC 6238 implementation (`Totp.php` core class)
- SQL migration `032_totp_2fa.sql`

### Added — Reporting / Analytics Dashboard (Issue #93)

- **Reports dashboard** — summary cards, expense status breakdown, activity trend chart, JSON data endpoint
- SQL migration `033_reports.sql`

### Added — Configurable Workflow Engine (Issue #94)

- **Workflow engine** — definitions, ordered steps, instance tracking, action logs, admin management UI
- SQL migration `034_workflow_engine.sql`

### Added — REST API Expansion (Issue #95)

- **New API endpoints** — events list/detail, attendance list, users list (admin), announcements list
- SQL migration `035_api_expansion.sql`

### Added — Recurring Task / Reminder System (Issue #96)

- **Tasks app** — task management with priorities, due dates, user assignment, recurring tasks
- Auto-spawns next occurrence when a recurring task is completed
- SQL migration `036_tasks_reminders.sql`

- Updated `full_schema.sql` — covers migrations 000–036

## [0.9.0] - 2026-03-08

### Added — Multi-Site Support (Phase 5, Issue #45)

- **Multi-site architecture** — single installation serving multiple sites/divisions with full data isolation via `siteID` foreign keys on all data tables
- **Three detection modes** (configurable via `multisite.detectionMode`): subdomain (`cambridge.portal.example.com`), path-prefix (`/cambridge/expenses`), session (navbar switcher dropdown)
- **4-tier permission hierarchy**: Umbrella Admin → Site Root Admin → Site Admin → User, with per-site role flags in `tblUserSites`
- **New tables**: `tblSites` (site definitions with branding), `tblUserSites` (user-to-site assignments with admin flags)
- **New core class**: `Site.php` — central site-context manager with detection, branding, user assignment, and URL generation
- **Site-aware bootstrap**: pre-settings site detection, settings query loads global defaults then site-specific overrides
- **Per-site branding**: logo, primary colour, copyright org, timezone per site — reflected in navbar, header meta, footer copyright
- **Site switcher** in navbar (shown when multisite enabled and user has 2+ sites)
- **Admin site management** (`admin/sites`) — create/edit sites, manage user-to-site assignments, toggle admin roles (umbrella admin only)
- **Site switch handler** (`site/switch`) — CSRF-protected POST handler for switching active site
- SQL migration `015_multisite.sql` — tblSites, tblUserSites, siteID columns on 12 tables, seed data, routes

### Changed — Multi-Site Core Updates

- `bootstrap.php` — pre-settings site detection via `Site::preDetect()`, site-aware settings query (`WHERE siteID IS NULL OR siteID = ?`), `Site::init()` after App
- `App.php` — added `siteId()`, `isSiteAdmin()`, `isSiteRootAdmin()`, `isUmbrellaAdmin()`; `user()` JOINs `tblUserSites`; `isAdmin()` uses 4-tier hierarchy
- `Router.php` — `extractPath()` strips site-key prefix in path mode; `url()` prepends site prefix
- `Auth.php` — sets `$_SESSION['active_site_id']` after all login flows; `createUser()` inserts into `tblUserSites`
- `Logger.php` — `activity()` and `errorPlatform()` include `siteID` column
- `nav.php` — site switcher dropdown, per-site logo/name, "Sites" admin link
- `header.php` — per-site theme-color meta tag
- `footer.php` — per-site copyright org
- All expense, calendar, attendance, admin, and settings queries — `AND siteID = ?` filtering
- `full_schema.sql` — consolidated with tblSites, tblUserSites, siteID columns, multisite settings/routes

### Added — JS Graceful Degradation (Issue #49)

- **Global `<noscript>` banner** in `header.php` — informs users when JS is disabled, listing affected features
- **No-JS CSS overrides** — accordion panels expanded, nav dropdowns open on hover/focus-within, Bootstrap collapse sections visible
- **Expense submit fallback** — 5 static line-item rows rendered inside `<noscript>` when JS can't generate dynamic rows; visible file input fallback for dropzone
- **Attendance record note** — contextual `<noscript>` message explaining limited row management
- **Account page passkey note** — `<noscript>` message explaining passkey registration requires JS
- **Settings page** — `<noscript>` style expands all accordion groups; note about search requiring JS
- **Admin modals** — `<noscript>` warnings on user management and site management pages where modal dialogs require JS
- **CSS-only nav dropdowns** — hover/focus-within fallback for navbar dropdown menus
- **Dark mode noscript support** — dark theme variant for noscript banner

### Added — Leadership App (Phase 9, Issue #38)

- **Leadership directory** — card-based view of all roles and current holders, with term dates, vacancy indicators, and summary stats
- **Role management** (`leadership/manage`) — admin CRUD for leadership role definitions with activate/deactivate toggle
- **Role assignment** (`leadership/assign`) — assign portal users or external people to roles, with start/end term dates and notes
- **Historical records** (`leadership/history`) — full audit trail of all assignments (current, past, removed) with role filter
- **17 default SDA church roles** seeded: Pastor, Elder, Deacon/Deaconess, Clerk, Treasurer, SS Superintendent, Youth Leader, etc.
- **Multi-site scoped** — all tables include `siteID` FK; queries filter by `Site::id()`
- **New tables**: `tblLeadershipRoles`, `tblLeadershipAssignments`
- SQL migration `017_leadership.sql` — tables, seed roles, routes, settings

### Added — Google Workspace Email Sending (Issue #48)

- **`MailerGoogle.php`** — Gmail API email backend using service account with domain-wide delegation (RS256 JWT, RFC 2822 MIME, attachments up to 25MB)
- **`Mailer.php` refactored** — multi-provider dispatcher; `mail.provider` setting toggles between `ms365` (default) and `google`
- **Integration Diagnostics** updated — Google email config panel with key file validation, delegate user display, active provider indicator, and test email sending via Gmail API
- **New settings**: `mail.provider`, `mail.google.serviceAccountKeyFile`, `mail.google.delegateUser`
- SQL migration `016_google_mail.sql` — new Google email settings

---

## [0.8.1] - 2026-03-08

### Added

- Event series bulk edit page for calendar management (#75)
- Leadership role transition workflow — auto-end outgoing holders (#76)
- CSV export across 5 apps: expenses, attendance, leadership, admin users, activity logs (#77)
- Input validation framework — Validator class with pipe-separated rules (#78)
- Transaction helpers — App::beginTransaction/commit/rollback (#79)
- Lightweight DI container — Container class alongside existing statics (#81)
- **Integration Diagnostics** (`admin/integrations/index.php`, Issue #46) — admin-only page to test MS365 OAuth login configuration, Graph API token acquisition (client-credentials flow), test email sending from shared mailbox via SendAs/delegate, and Google OAuth configuration status. Includes pass/fail badges, Azure AD permissions reference, and CSRF-protected forms.
- SQL migration `014_admin_integrations_route.sql` — adds `admin/integrations` protected route
- Integrations quick-link on Admin Dashboard

### Changed

- Separated API routing into dedicated ApiRouter class (#80)
- Standardized error handling — flash+redirect for all CSRF/OAuth errors (#82)

### Fixed

- Version seed in full_schema.sql and App.php now shows 0.8.1 (#83)

### Security

- All codebase passes lint with zero syntax errors
- No SQL injection, die(), or bare exit patterns remaining

---

## [0.8.0] - 2026-03-07

### Added - Polish & Hardening (Phase 9, Issues #35–#37)

- **PWA Support** (`manifest.json`, `sw.js`, `offline/index.php`) — web app manifest with standalone display, service worker with cache-first for static assets and network-first for HTML, offline fallback page with retry, SVG PWA icons (192 & 512)
- **WCAG 2.1 Accessibility** — skip-to-main-content link with focus-visible styling (WCAG 2.4.1), enhanced keyboard focus indicators (`*:focus-visible` with `outline`), ARIA `aria-live` regions on login alerts for screen readers, `aria-hidden="true"` on decorative Font Awesome icons, `role="main"` and `id="main-content"` on `<main>` element
- **Security Hardening** — Content-Security-Policy header (restricts scripts, styles, fonts, frames to known CDNs and self), Permissions-Policy header (blocks camera, microphone, geolocation), existing open redirect protections and CSRF coverage verified across all endpoints

### Changed — Polish & Hardening

- `portal.css` — added section 18 (Accessibility) with skip-link and focus-visible styles, renumbered RTL to 19 and Print to 20
- `header.php` — added CSP and Permissions-Policy security headers
- `login/index.php` — added `aria-live`, `aria-hidden` attributes for accessibility

---

## [0.7.0] - 2026-03-07

### Added - Translations / i18n (Phase 8, Issues #42–#44)

- **I18n Framework** (`core/I18n.php`) — translation class with `t('key')` helper, parameterised strings (`:name`), pluralisation (`singular|plural` and `zero|one|many`), fallback chain (user locale → default locale → raw key), Accept-Language browser auto-detection, per-user locale persistence in `tblUsers.locale`
- **Language Switcher** — dropdown in navbar (both authenticated and unauthenticated views) using `?lang=` query parameter with redirect, stores preference in session and database
- **English Baseline** (`lang/en.php`) — comprehensive translation file covering nav, auth, dashboard, expenses, calendar, attendance, admin, settings, help, errors, common UI, email, and date/number formatting
- **Welsh Proof-of-Concept** (`lang/cy.php`) — demonstrates multi-language support with nav, auth, error, and common UI translations
- **RTL Layout Support** — `dir="rtl"` on `<html>` tag when RTL locale active, Bootstrap RTL CSS variant (`bootstrap.rtl.min.css`) loaded conditionally via `Asset::bootstrapCss(true)`, CSS logical property overrides in `portal.css` for margin/text-alignment mirroring
- **Date/Time/Number/Currency Formatting** — `I18n::formatDate()`, `I18n::formatDateTime()`, `I18n::formatNumber()`, `I18n::formatCurrency()` with per-locale format strings from translation files
- **Template Integration** — header, footer, nav, and all three error pages (403, 404, 500) updated with `t()` calls; login page fully translated with all error/success messages using translation keys
- **Bootstrap Integration** — `bootstrap.php` initialises I18n, handles `?lang=` switcher with redirect, loads user locale from DB into session, defines global `t()` helper function
- **SQL Migration** (`012_i18n_phase8.sql`) — adds `locale` column to `tblUsers`, adds `i18n.defaultLocale` and `i18n.enabled` settings
- **Locale Metadata** — 13 locales defined with name, native name, and LTR/RTL direction (en, cy, fr, de, es, pt, ar, he, fa, ur, zh, ja, ko)

---

## [0.6.0] - 2026-03-07

### Added - SSO & Auth Enhancement (Phase 7, Issues #32–#34)

- **Google Workspace OAuth** (`core/Auth.php`) — full OAuth2 flow with JWKS JWT verification via Google's discovery endpoint, hosted domain restriction, profile sync (name, avatar), auto-link by email match, conditional login button
- **WebAuthn / PassKeys** (`core/WebAuthn.php`) — server-side WebAuthn implementation with no external dependencies: CBOR decoder, COSE-to-PEM key conversion (ES256 + RS256), registration (attestation) and authentication (assertion) flows, sign count tracking for clone detection
- **Account Linking** (`core/Auth.php`) — `linkAccount()`, `unlinkAccount()`, `getLinkedAccounts()`, `countLoginMethods()` methods; safety check prevents unlinking last login method; auto-link on OAuth login by email match
- **Login Page** (`auth/login/index.php`) — conditional Google sign-in button, passkey sign-in button with WebAuthn browser API integration, discoverable credential support
- **Account Page** (`auth/account/index.php`) — linked accounts card showing all providers with unlink buttons, passkeys card with registration modal and delete, account type badges, provider icons
- **WebAuthn Login Endpoint** (`auth/login/webauthn.php`) — AJAX API for passkey authentication with credential lookup, signature verification, session creation
- **Account Endpoints** — `auth/account/webauthn.php` (registration options + verify), `auth/account/webauthn-delete.php`, `auth/account/unlink.php`
- **MS365 OAuth Updated** — refactored to use account linking system (`tblLinkedAccounts`), backward-compatible with existing logins via email-based auto-linking
- **SQL Migration** (`011_auth_phase7.sql`) — creates `tblLinkedAccounts` and `tblWebAuthnCredentials` tables, adds Google hosted domain and WebAuthn RP settings, registers account management routes
- **Settings** — `auth.google.hostedDomain`, `auth.webauthn.rpName`, `auth.webauthn.rpID`
- **Routes** — `account/linked-accounts`, `account/unlink`, `account/webauthn`, `account/webauthn/delete`; special routes: `login/google`, `login/google/callback`, `login/webauthn`

---

## [0.5.0] - 2026-03-07

### Added - Expenses Completion (Phase 6)

- **Multi-Approver Workflow** (`expenses/approve/save.php`) — dept-based approver authorisation using `tblUserDepts` roles (dept lead, mandatory approver, dept approver, admin), rejection by any approver immediately rejects, all mandatory approvers must approve for final approval
- **Claim Detail Page** (`expenses/view/index.php`) — comprehensive view showing claim header, summary cards, line items, evidence files with stage badges, approval history with roles/decisions/comments, payment records, PDF download section, and context-aware action buttons
- **Email Notifications** (`core/ExpenseMailer.php`) — HTML email notifications via Microsoft Graph at each workflow stage (submitted → approvers, approved/rejected → claimant, reimbursed → claimant + approvers), with PDF attachment, graceful fallback if mail unconfigured
- **Enhanced PDF Generation** (`core/ExpensePdf.php`) — PDFs now include approval history (approver names, roles, decisions, dates) and payment records alongside line items; file versioning via `stage` column in `tblExpenseClaimFiles`
- **Treasury Improvements** (`expenses/treasury/save.php`) — proper flash messages, CSRF validation with redirect, claim status verification, `paidByID` tracking, email notification on reimbursement
- **Submit Improvements** (`expenses/submit/save.php`) — email notification to dept approvers on submission, flash messages instead of bare `exit()` calls
- **SQL Migration** (`010_expenses_phase6.sql`) — adds approval threshold/treasury/follow-up/email settings, `stage` column to files table, `approverRole` to approvals table, claim view route
- **Settings** — `expenses.approvalThreshold`, `expenses.requireTreasuryApproval`, `expenses.followUpDays`, `expenses.emailNotifications`

---

## [0.4.0] - 2026-03-07

### Added - Attendance Tracker App (Phase 5)

- **Attendance Session Recording** (`attendance/record.php`) — form for recording headcounts by service type and date, with dynamic group breakdown (Adults, Children, Visitors, etc.) and running total calculation
- **Attendance Dashboard** (`attendance/index.php`) — lists recent sessions with headcount totals, monthly stats cards, filters by service type and date range, pagination
- **Service Type Management** (`attendance/manage/`) — admin UI for viewing, creating, and activating/deactivating attendance service types with hierarchical parent/child structure
- **Attendance Reports** (`attendance/report.php`) — yearly and monthly breakdown views with totals by service type and headcount group, average-per-session calculations
- **SDA Church Service Types** seeded — Sabbath School (with 10 children's divisions: Babies through Baptismal Class), Family Worship, Afternoon Service, Prayer Meeting, Bible Study, Youth Programme, Special Event
- **Database Tables** — `tblAttendanceServiceTypes` (hierarchical service types), `tblAttendanceSessions` (sessions with optional event link), `tblAttendanceCounts` (headcount breakdowns per session)
- **SQL Migration** (`009_attendance_schema.sql`) — creates tables, seeds service types, registers routes, enables app in settings
- **Settings** — `attendance.enabled`, `attendance.displayName`, `attendance.displayIcon`, `attendance.brandColor` for dashboard and nav integration
- **Full Schema** (`full_schema.sql`) updated with attendance tables, routes, settings, and migration tracking

---

## [0.3.0] - 2026-03-06

### Changed - Directory Restructure (Phase 2.5)

- **Consolidated all deployable files under `web/`** — `core/`, `vendor/`, `sql/` now live inside `web/` alongside `public_html/`, matching the ProjectBrief server structure
- **App controllers inside web root** — app PHP files live in `web/public_html/{app}/` (e.g. `public_html/expenses/`, `public_html/auth/`) as specified by the ProjectBrief
- **Deploy workflow** updated to sync only `web/` to the server (was syncing entire repo root)
- **Added missing directories** from ProjectBrief: `_includes/`, `_functions/`, `_libraries/` with `.gitkeep` files
- **Updated `.gitignore`** for new `web/` prefixed paths

### Fixed — Directory Restructure

- **`Pdf.php`** — dompdf `require_once` at class load time caused fatal error when dompdf wasn't installed; now loads conditionally inside `create()` with graceful error logging
- **`Logger.php`** — `bind_param` type string `'sssssssiss'` had `i` at wrong position (8th param for `$ua` string); corrected to `'sssssissss'` (6th param for `$userId` int)
- **`settings/save.php`** — `dirname(__DIR__, 3)` resolved to wrong directory after restructure; corrected to `dirname(__DIR__, 2)`
- **Git case sensitivity** — removed duplicate `ProjectBrief_chat.claude` (lowercase) from tracking; only `ProjectBrief_Chat.claude` tracked

---

## [0.2.0] - 2026-03-06

### Added - Local Auth Enhancement (Phase 2)

- **Forgot Password** flow (`apps/auth/forgot-password/`) - email input, rate-limited token generation, timing-safe enumeration prevention, graceful Mailer fallback
- **Reset Password** flow (`apps/auth/reset-password/`) - token validation, password policy enforcement, rate limiting, token invalidation after use
- **Account/Profile** page (`apps/auth/account/`) - edit profile (name, email, phone), change password with policy validation, view roles and last login
- **Password Policy** engine (`Auth::validatePassword()`) - configurable min length, uppercase, number, special char requirements via tblSettings
- **MS365 Conditional UI** (`Auth::isMS365Configured()`) - login page shows MS365 button only when OAuth is configured
- **Consolidated Schema** (`sql/full_schema.sql`) - single-file schema for fresh installs with safe `IF NOT EXISTS` / `ON DUPLICATE KEY` semantics
- **Migration 006** (`sql/006_local_auth_enhancement.sql`) - tblPasswordResets, password policy settings, auth routes

### Changed — Local Auth

- **Login page** (`apps/auth/login/index.php`) - redesigned with local login as primary, MS365 conditional
- **Auth::loginLocal()** - fixed to query `tblLocalAccounts JOIN tblUsers` (was incorrectly querying tblUsers for passwordHash)
- **Nav dropdown** - added "My Account" link
- **Gatekeeper** - added forgot-password and reset-password to OPEN_PATHS

### Security - Full Codebase Audit (Issue #14)

- **Open Redirect** fixed in `Auth.php` and `login/index.php` - all `$_GET['redirect']` values now validated
- **Broken Authorization** fixed in `settings/save.php` - operator precedence bug allowed any user to edit settings; now requires `App::isAdmin()`
- **DOM XSS** eliminated in `approve/index.php` and `treasury/index.php` - `innerHTML` replaced with safe DOM API (`textContent`)
- **File Upload Validation** added to `submit/save.php` - extension allowlist, 10MB size limit, server-side MIME detection
- **Server-side Total** - expense total now recalculated server-side instead of trusting client hidden field
- **Gatekeeper bind_param bug** fixed - `$types` variable was defined but never used in dynamic query binding
- **Session Data Logging** - sensitive keys (CSRF, OAuth state) now stripped before serializing to activity logs
- **SSRF Prevention** - dompdf `isRemoteEnabled` set to `false`
- **Role Authorization** added to `approve/save.php` (Approver) and `treasury/save.php` (Treasurer)
- **Strict Comparisons** - all `==` changed to `===` in `App.php`, `Router.php`, `Gatekeeper.php`, `settings/index.php`
- **Timing-safe OAuth** - state comparison now uses `hash_equals()`
- **SSL Verification** explicitly enabled on all cURL calls (`Auth.php`, `Mailer.php`)
- **Rate Limiting** added to `reset-password/save.php`
- **htmlspecialchars Charset** - all calls now include `'UTF-8'` parameter
- **Timezone Validation** - validated against `timezone_identifiers_list()` before setting
- **Mailer Reformatted** - full code style compliance with SSL verification

---

## [0.1.0] - 2025-present

### Added - Initial Build (Phase 1)

#### Core Framework
- **Router** (`core/Router.php`) - Front-controller dispatcher with clean URL routing via tblRoutes, hardcoded special routes (login, logout, MS365 OAuth, health check, API), and error page rendering
- **App Registry** (`core/App.php`) - Static service registry replacing `global $mysqli, $SETTINGS` pattern. Methods: `db()`, `settings()`, `user()`, `isDebug()`, `version()`, `env()`, `hasRole()`, `isAdmin()`, `isRootAdmin()`
- **Asset Loader** (`core/Asset.php`) - CDN-with-fallback asset loading for Bootstrap 5.3.3, Font Awesome 6.5.1, with SRI integrity checks and onerror fallback handlers
- **Avatar System** (`core/Avatar.php`) - Avatar cascade: MS365 URL -> local file -> Gravatar -> placeholder SVG
- **Debug Panel** (`core/Debug.php`) - Admin-only diagnostic overlay showing page load time, peak memory, PHP version, DB queries, session data (activated via `?debug=true`)
- **Captcha Helper** (`core/Captcha.php`) - Centralised CloudFlare Turnstile / reCAPTCHA support replacing duplicated loose functions
- **API Response** (`core/ApiResponse.php`) - Standardised JSON API response builder with consistent envelope format
- **Rate Limiter** (`core/RateLimiter.php`) - Database-backed login rate limiting via tblActivityLogs
- **Migrator** (`core/Migrator.php`) - Web-based SQL migration runner for environments without CLI access

#### Authentication
- RS256 JWT verification (`vendor/simplejwt/JWT.php`) using JWKS key fetching, ASN.1 DER key conversion, and standard claim validation
- Session hardening: HttpOnly, Secure, SameSite=Lax cookie parameters
- Session fixation prevention via `session_regenerate_id(true)` after login
- CSRF token rotation after successful verification
- Local account authentication with bcrypt password verification
- Rate limiting integration on login attempts

#### Template System
- Shared header/footer templates eliminating boilerplate duplication
- Responsive navbar with dynamic app links, user avatar dropdown, dark mode toggle
- Breadcrumb support
- Error pages: 404, 403, 500

#### Design System
- Custom CSS (`portal.css`) with CSS custom properties for theming
- Dark mode support via `[data-bs-theme="dark"]`
- `portal-data-list` / `portal-data-row` responsive table replacement
- Status badges, avatar component, file dropzone, empty state
- WCAG-compliant focus indicators
- Print styles

#### JavaScript
- Dark mode toggle with localStorage persistence
- AJAX helper with CSRF token support
- Toast notification system
- File dropzone drag-and-drop feedback

#### Infrastructure
- `.htaccess` URL rewriting for 3 deployment channels (public, alpha, beta)
- SQL migration system with 5 initial migrations (000-004)
- Health check endpoint (`/health`) for CI/CD monitoring
- API routing: `api/{app}/{action}` pattern

#### API Endpoints
- `GET /api/expenses/list` - Paginated expense claim listing with status filtering

### Changed - Core Framework Refactor

- `core/Auth.php` - `ensureSession()` made public, `curlPost()` made public, added JWT verification via JWKS, added local login, improved logout with proper cookie deletion
- `core/bootstrap.php` - Integrated App registry, Debug timer, SimpleJWT autoloader, improved error handlers
- All app files refactored to use template system

### Fixed - Initial Release Issues

- Filesystem case-sensitivity bugs (`Core/` -> `core/`, `logger.php` -> `Logger.php`) that would break on Linux
- `vendor/simplejwt/JWT.php` was a copy of Auth.php - replaced with real JWT library
- Inline DDL (`CREATE TABLE IF NOT EXISTS`) in save handlers moved to proper migrations
- Duplicate captcha functions consolidated into Captcha class
