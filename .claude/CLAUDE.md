# WebMS Intra - Claude Code Instructions

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, MySQL 8.0, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

- **Version:** 1.2.0 (on `main`)
- **Licence:** All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
- **Repo:** github.com/MWBMPartners/WebMS-Intra
- **Server:** portal.millrdsdacambridge.uk
- **Full brief:** `.claude/ProjectBrief_Chat.claude`
- **Living memory:** `.claude/MEMORY.md` (rolling state — most-recent ships, open
  PRs, current branch focus). Update alongside CLAUDE.md edits.
- **Living feature inventory:** [FEATURES.md](../FEATURES.md) (always check this first)
- **Chronological history:** [CHANGELOG.md](../CHANGELOG.md)
- **Dev-facing technical notes:** [DEV_NOTES.md](../DEV_NOTES.md)

## Directory Layout

```
repo root/          <- NOT deployed (docs, CI/CD only)
web/                <- ALL deployable files (synced to server via SFTP)
  _core/            <- Framework classes (Portal\Core namespace, 27+ classes)
                       Recent additions: ErrorMonitor (#143), GdprAudit, AiTranscription
  _apps/            <- App controllers — outside the webroot (#159, shipped in #288).
                       Every app's PHP handlers live here; Router resolves
                       tblRoutes.targetFile against PORTAL_APPS = _apps/, with
                       a fallback to public_html/ for the three entry-point
                       handlers Apache must reach.
  _vendor/simplejwt/<- Vendored RS256 JWT verifier
  _sql/             <- Numbered SQL migrations (000-107 + full_schema.sql)
                       Latest: 105 (error monitoring), 106 (API write CRUD),
                       107 (offline queue route).
  _lang/            <- I18n translation files (en.php, cy.php, …)
  _install/         <- Standalone 6-step installation wizard (bootstrap-free)
  public_html/      <- Web root: ONLY the front controller + static assets +
                       the 3 entry-point PHP files (index.php, api-docs/,
                       error.php) Apache can serve directly. Every other
                       PHP file lives in _apps/. Branch-based deploy mirrors
                       this dir to the server's public_html/ (main),
                       public_html_beta/ (beta) or public_html_dev/ (alpha).
    index.php, error.php, .htaccess, manifest.json, openapi.json,
    robots.txt, sw.js, assets/, api-docs/, offline/, monitoring/ admin pages
  private_html/, public_html_landing/, public_html_redir/  <- non-app server dirs
  _auth_keys/       <- Credentials + encryption key (gitignored, server-managed)
  _uploads/         <- User file uploads (gitignored, server-managed)
  _backups/         <- Server snapshots (gitignored, server-managed)
  _libraries/       <- Server-managed libs incl. dompdf 3.1.5
tools/
  audit-checks/     <- Repo-local lint/audit scripts (mobile, route targets,
                       SQL columns, CSRF, secrets, etc.) — run in CI
  offsite-backup/   <- Offsite-backup helper scripts (rclone, snapshot rotate)
```

## Apps (shipped on `main` — v1.2.0)

| Slug | Route | What it does |
| --- | --- | --- |
| dashboard | `/dashboard` | Portal home with app cards |
| admin | `/admin` | Users, errors, activity, audit, migrations, integrations, sites, workflows, reports, captcha config, monitoring (#143), offline queue inspector |
| auth | `/auth/*` | Local + MS365 + Google + WebAuthn + 2FA TOTP; password policy + strength meter |
| calendar | `/calendar` | Events, series, RSVP, exports, iCal feed, seven view modes, per-month strap-lines |
| prayer-requests | `/prayer-requests` | Logged-in + anonymous public submission, moderation, lifecycle |
| attendance | `/attendance` | Sessions, headcount by service type, reports, CSV |
| expenses | `/expenses` | Submit, approve, treasury, withdraw, multi-approver, PDF, CSV |
| leadership | `/leadership` | Roles + assignments + history + CSV |
| announcements | `/announcements` | Per-site noticeboard |
| documents | `/documents` | File library with categories |
| tasks | `/tasks` | Reminders / task list |
| rota | `/rota` | Service rota — schedule, swap, role-types |
| service-plans | `/service-plans` | Service order / order-of-service planning |
| resources | `/resources` | Equipment/asset booking |
| reading-plans | `/reading-plans` | Daily / multi-day scripture reading plans |
| visitors | `/visitors` | Visitor contact capture + follow-up |
| livestream, recordings, zoom, newsletter, giving, sms, projects, payments, photos | various | Install-on-demand apps from waves 4/5 |
| transcription, translation, ai-assist | various | AI-assisted media tooling (#272 / wave 5) |
| gdpr | `/admin/gdpr` | Erasure pipeline + audit log (wave 5) |
| api | `/api/*` | Read + write JSON endpoints (announcements/tasks/prayer-requests/leadership now write-side too, #157) |
| settings | `/settings` | Generic dot-notation settings editor |
| help | `/help/*` | In-app guides (getting-started, expenses, calendar, prayer-requests, admin, faq, disaster-recovery, …) |
| site | `/site` | Multi-site switcher handler |
| account | `/account/*` | User self-service incl. offline-queue inspector (#233) |
| offline | `/offline` | PWA offline fallback page |

Calendar/Events/Preaching Plan is ONE app ("Events") — `/calendar` covers viewing/listing/subscribing; the manage UI handles preaching-plan/worship event types and series.

## Code Style (MUST FOLLOW)

- `declare(strict_types=1)` in every PHP file
- **Full IF notation:** `if ($x === true)` not `if ($x)`
- **Platform-neutral paths:** `DIRECTORY_SEPARATOR`, `dirname()`, PHP constants
- **Emoji-annotated comments** for major code sections
- **No `<table>` tags** for data display -- use `portal-data-list` component
- **MySQLi prepared statements only** -- never interpolate user input
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` for all output
- Detailed inline comments with reference links where applicable
- File header comments must include: file path, description, package, author, copyright (All Rights Reserved), version
- New CSP rule (#144): inline `<script>` blocks **must** carry
  `nonce="<?php echo App::cspNonce(); ?>"`. Inline event handlers
  (`onclick="…"`) are disallowed; bind in a nonce'd script block.
- Mobile readiness (#225): Bootstrap `.modal-dialog` requires
  `modal-fullscreen-sm-down`; bare `<table>` must be wrapped in
  `<div class="table-responsive">`; `<input type="file">` must carry an
  `accept=` hint. The static audit `tools/audit-checks/check_mobile_readiness.py`
  enforces all three.

## Key Constants (defined in _core/bootstrap.php)

- `PORTAL_ROOT` -- web/ on server
- `PORTAL_CORE` -- web/_core/
- `PORTAL_APPS` -- web/_apps/ (since #159 / PR #288)
- `PORTAL_VENDOR` -- web/_vendor/
- `PORTAL_SQL` -- web/_sql/
- `PORTAL_ENV` -- 'dev', 'beta', or 'prod' (auto-detected from DOCUMENT_ROOT)

## Recent ships (chronological — v1.2.0 cycle, most recent first)

- **PR #295** — Mobile-readiness sweep — 29 → 0 audit findings (#225)
- **PR #294** — FEATURES + CHANGELOG comprehensive sweep — waves 3/4/5 + post-wave hardening
- **PR #293** — Codebase audit pass — duplicate cookie banner + missing Auth import + SQL int-concat → prepared-statement cleanups
- **PR #292** — PWA offline write queue + sync-on-reconnect (#233)
- **PR #291** — REST API write-side CRUD: Announcements / Tasks / Prayer Requests / Leadership (#157)
- **PR #290** — External error monitor — Sentry / GlitchTip envelope (#143)
- **PR #289** — Nonce-based CSP `script-src` tightening (#144)
- **PR #288** — `_apps/` defence-in-depth refactor — app controllers moved out of webroot (#159)
- **PR #287** — `auto-merge-alpha.yml` verification + delete-criterion doc (#147)
- **PR #286** — Doc sweep — stale rename-aftermath references (#189, #182, #183, #194)
- **PR #285** — Apps wave 5 — Transcription / Translation / AI Assist / GDPR / Photos + 5 infra/security items
- **PR #284** — Apps wave 4 — 10 install-on-demand apps (Resources, Service Plans, Livestream, Recordings, Zoom, Newsletter, Giving, SMS, Projects, Payments)
- **PR #283** — Apps wave 3 — Reading Plans + QR + Invite onboarding + Offboarding (#265, #275, #239, #240)
- **PR #282** — post-#281 schema-drift + CSRF findings
- **PR #281** — 2-apps + iCal feed + admin polish omnibus (#258, #261, #271, #251, #254, #253, #252)

## GitHub Labels

- `type:` -- feature, enhancement, bug, security, docs, infrastructure, refactor
- `priority:` -- critical, high, medium, low
- `scope:` (blue) -- core, admin, auth, ui, i18n (cross-cutting concerns)
- `app:` (salmon) -- calendar, attendance, expenses, admin, dashboard, help, settings, prayer-requests, rota, service-plans, resources, reading-plans, visitors, livestream, recordings, zoom, newsletter, giving, sms, projects, payments, photos, transcription, translation, ai-assist, gdpr, account
- `phase:` (purple) -- 3 through 13
- `status:` -- blocked, in-progress, review

## Audit pipeline (`tools/audit-checks/`)

Run any of these locally before opening a PR — every one is wired into CI:

- `check_route_targets.py` — every `tblRoutes.targetFile` must resolve to a
  real file (under `PORTAL_APPS` first, then `public_html/` for the three
  entry-point handlers).
- `check_sql_columns.py` — every INSERT/UPDATE/SELECT references columns
  that actually exist in the migrations.
- `check_csrf.py` — every state-mutating route reads + verifies the CSRF token.
- `check_mobile_readiness.py` — viewport tag present, no oversize hard-coded
  widths, `<table>` wrapped in `.table-responsive`, modal-dialog has
  `modal-fullscreen-sm-down`, file inputs carry `accept=`.
- `check_secrets.py` — no hard-coded credentials, no `*.key` checked in.
- `check_app_registry.py` — every app slug in `_core/apps/` resolves to a
  real `_apps/<slug>/` directory.
- `check_php_lint.sh` — `php -l` over every `.php` file in `web/`.

## Standing Instructions (per ProjectBrief)

When making changes:

1. Create a GitHub Issue with description, scope, and acceptance criteria
2. Run ALL code through syntax/lint checks -- fix ALL issues until zero remain
3. Update CHANGELOG.md, **FEATURES.md**, DEV_NOTES.md, README.md as appropriate
4. Update `.claude/CLAUDE.md` + `.claude/MEMORY.md`
5. Update GitHub Wiki/Project/Milestones alongside Issues
6. COMMIT changes (DO NOT PUSH unless the user explicitly asks for a PR)
7. Close GitHub Issue with commit / PR reference

## Git Notes

- macOS case-insensitive: use two-step rename for case changes
- Never commit: `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `.env`, `*.key`
- Deploy workflow syncs `web/` only, excluding server-managed dirs
- Shared dirs (`_core/`, `_vendor/`, `_sql/`, `_includes/`, `_functions/`, `_libraries/`) mirror with `--delete` — manual server-side edits to these dirs vanish on the next deploy (see DEV_NOTES.md → Troubleshooting)
- macOS user's local `.claude/MEMORY.md` was previously a symlink into the
  user's home — it has been replaced with a real file in the repo so that
  remote / CI sessions can read it. Keep it as a real file.
