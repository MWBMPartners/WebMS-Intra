# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Automated sections are appended by `.github/workflows/changelog.yml` per push
to `alpha`, `beta`, and `main` using the heading format
`## [VERSION] - YYYY-MM-DD (branch)`.

## [Unreleased]

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
