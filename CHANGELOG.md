# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Automated sections are appended by `.github/workflows/changelog.yml` per push
to `alpha`, `beta`, and `main` using the heading format
`## [VERSION] - YYYY-MM-DD (branch)`.

## [Unreleased]

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
