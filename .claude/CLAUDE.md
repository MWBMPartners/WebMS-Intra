# WebMS Intra - Claude Code Instructions

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, MySQL 8.0, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

- **Version:** 1.4.0 (on `main`; bump in `web/_core/version.php` — single source of truth)
- **Brand layer:** runtime product brand picked at install (#296, PR #297). Presets: `WebMS Intra` (generic, default), `ChurchMS` (church). School/charity/community/small-business stubbed. See `web/_core/brand-defaults.php` + `Site::productName()`. PWA manifest is a brand-aware PHP controller (`manifest.php`); the OpenAPI spec is likewise served brand-aware via `public_html/openapi.php` + `_core/api-spec.json` (#307).
- **Licence:** All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
- **Repo:** github.com/MWBMPartners/WebMS-Intra
- **Server:** portal.millrdsdacambridge.uk
- **Full brief:** `.claude/ProjectBrief_Chat.claude`
- **Living feature inventory:** [FEATURES.md](../FEATURES.md) (always check this first)
- **Chronological history:** [CHANGELOG.md](../CHANGELOG.md)
- **Dev-facing technical notes:** [DEV_NOTES.md](../DEV_NOTES.md)

## Directory Layout

```
repo root/          <- NOT deployed (docs, CI/CD only)
web/                <- ALL deployable files (synced to server via SFTP)
  _core/            <- Framework classes (Portal\Core namespace, 26 classes)
  _apps/            <- App controllers — outside the webroot (#159). Every
                       app's PHP handlers live here; Router resolves
                       tblRoutes.targetFile against PORTAL_APPS = _apps/.
  _vendor/simplejwt/<- Vendored RS256 JWT verifier
  _sql/             <- Numbered SQL migrations (000-145 + full_schema.sql)
  _lang/            <- I18n translation files (en.php, cy.php, …)
  _install/         <- Standalone 6-step installation wizard (bootstrap-free)
  public_html/      <- Web root: ONLY the front controller + static assets +
                       the 3 entry-point PHP files (index.php, api-docs/,
                       error.php) Apache can serve directly. Every other
                       PHP file lives in _apps/. Branch-based deploy mirrors
                       this dir to the server's public_html/ (main),
                       public_html_beta/ (beta) or public_html_dev/ (alpha).
    index.php, error.php, .htaccess, manifest.php, openapi.php,
    robots.txt, sw.js, assets/, api-docs/, offline/
  private_html/, public_html_landing/, public_html_redir/  <- non-app server dirs
  _auth_keys/       <- Credentials + encryption key (gitignored, server-managed)
  _uploads/         <- User file uploads (gitignored, server-managed)
  _backups/         <- Server snapshots (gitignored, server-managed)
  _libraries/       <- Server-managed libs incl. dompdf 3.1.5
```

## Apps (shipped on `main`)

| Slug | Route | What it does |
| --- | --- | --- |
| dashboard | `/dashboard` | Portal home with app cards |
| admin | `/admin` | Users, errors, activity, audit, migrations, integrations, sites, workflows, reports, **captcha config** |
| auth | `/auth/*` | Local + MS365 + Google + WebAuthn + 2FA TOTP; password policy + strength meter |
| calendar | `/calendar` | Events, series, RSVP, exports; **seven view modes in flight via #137/#138** |
| prayer-requests | `/prayer-requests` | Logged-in + anonymous public submission, moderation, lifecycle |
| attendance | `/attendance` | Sessions, headcount by service type, reports, CSV |
| expenses | `/expenses` | Submit, approve, treasury, withdraw, multi-approver, PDF, CSV |
| leadership | `/leadership` | Roles + assignments + history + CSV |
| announcements | `/announcements` | Per-site text announcements |
| noticeboard | `/noticeboard` | Visual poster wall (Canva embeds, image/video/text posters, weekday recurrence, QR share) |
| documents | `/documents` | File library with categories |
| tasks | `/tasks` | Reminders / task list |
| api | `/api/*`, `/api/v1/*` | JSON REST API — read + write across events/announcements/attendance/prayer-requests/documents/expenses/leadership/tasks/noticeboard/users; dual-mode auth (session or bearer API key, #323 Phase 2) |
| settings | `/settings` | Generic dot-notation settings editor |
| help | `/help/*` | In-app guides (getting-started, expenses, calendar, prayer-requests, admin, faq, …) |
| site | `/site` | Multi-site switcher handler |
| offline | `/offline` | PWA offline fallback |

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

## Key Constants (defined in _core/bootstrap.php)

- `PORTAL_ROOT` -- web/ on server
- `PORTAL_CORE` -- web/_core/
- `PORTAL_APPS` -- web/_apps/ (since #159)
- `PORTAL_VENDOR` -- web/_vendor/
- `PORTAL_SQL` -- web/_sql/
- `PORTAL_ENV` -- 'dev', 'beta', or 'prod' (auto-detected from DOCUMENT_ROOT)

## ApiRouter routing trap (apply on every new api/* endpoint)

- **`api/*` paths IGNORE tblRoutes.** `Router::handleSpecialRoutes` intercepts them and hands off to `ApiRouter::dispatch`, which splits the path into `appName` + `action` and loads `_apps/{appName}/api/{action}.php`. Handler at any other path is unreachable.
- **Every endpoint needs `api.{appName}.{action}.enabled = 'true'`** seeded in `tblSettings` or ApiRouter returns 403.
- **Don't register `api/...` routes in `tblRoutes`** — either the handler is at the convention path (settings flag does the gating) or it's dead code.
- **Adjacent gotcha**: the `ApiResponse` class exposes `::success()`, NOT `::ok()`. `::setJsonHeaders()` is `private`. Grep `_core/ApiResponse.php` for method names before calling.
- **v1 facade (#323 Phase 2)**: the `/api/v1/{resource}` facade maps REST verbs onto the same `{app}/{action}` handler files + `api.{app}.{action}.enabled` flags (`ApiRouter::dispatchV1`) — no separate gating vocabulary, nothing registered in `tblRoutes` for it either.

## SQL dialect trap (apply on every migration)

- **Production is MySQL 8.0.** MariaDB-only `IF [NOT] EXISTS` on `ADD`/`DROP COLUMN`, `ADD`/`CREATE`/`DROP INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN` is rejected with **ERROR 1064** on MySQL 8 — `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` are standard MySQL and stay fine.
- **Use the `information_schema` + `PREPARE`/`EXECUTE` guard idiom** instead (see DEV_NOTES.md → "Portable DDL convention (MySQL 8.0 ∩ MariaDB)" for the full templates). House examples already shipped this way: migrations **037**, **112**, **138**.
- **Migrations must replay as no-ops** on an up-to-date schema — the installer replays every numbered migration after `full_schema.sql`, ignoring `tblMigrations`.
- **CI**: `tools/audit-checks/check_mariadb_only_ddl.py` + the `e2e-migrations` harness enforce this.

## Recent ships (chronological)

- **PR #372** — #323 Phase 2: REST API v1 write surface — dual-mode `ApiAuth` (bearer API key OR session), `/api/v1/{resource}[/{id}]` RESTful facade, new write endpoints (Attendance/Documents/Expenses create+delete/Users), canonical `ApiKey::SCOPES` + rotation grace, per-key rate limiting, `Site::forceContext` tenant pinning, admin scope-checkbox + audit source-badge UI, OpenAPI v1 paths + `bearerAuth` scheme (v1.4.0). Plus #324 outbound webhooks admin CRUD UI.
- **PR #358** (in flight) — #303 Discipleship Pathway Tracker Phase 1 + #313 COP Live Chat Phase 1 + Phase 2 (push prompts + viewer widget) + #317 Virtual Host Console Phase 2 (overlap on `tblLivePrompts`) + #360 Community Noticeboard Phase 1 (poster wall, self-hosted React, page-scoped CSP extension). Includes a Phase 1 hotfix (`::ok`→`::success`) and multiple security-check-clean bug fixes.
- **PR #357** — #317 Phase 1 + #323 API key infrastructure Phase 1.
- **PR #356** — Plus Jakarta Sans modular embed.
- **PR #355** — Worship Presentation Engine full v1.
- **PR #354** — Post-merge cleanups (composer fix + 1.3.0 + installer favicons).
- **PR #340** — Events platform overhaul (36 issues / 39 commits).
- **PR #297** — Multi-brand product layer (#296).
- **PR #129** — Prayer Requests app (logged-in + anonymous public route)
- **PR #130** — Multi-provider Captcha (Turnstile / reCAPTCHA v2+v3 / hCaptcha) with admin priority drag-and-drop
- **PR #131** — Release prep v0.11.0 (version bump + CHANGELOG stamp)
- **PR #132** — Password policy hardening (#53) — min 12 chars, full-flow coverage, JS strength meter
- **PR #133** — Debug mode refused in production (#54), error display silenced
- **PR #134** — Deploy `dry_run` workflow_dispatch + DEV_NOTES SFTP `--delete` docs (#107)
- **PR #135** — Anchor colour bound to `--bs-link-color` for both themes (was leaking browser-default blue)
- **PR #137** (in flight) — Calendar seven view modes (closes #136)
- **PR #138** (in flight) — Calendar per-month strap-lines + category display-style toggle

## GitHub Labels

- `type:` -- feature, enhancement, bug, security, docs, infrastructure, refactor
- `priority:` -- critical, high, medium, low
- `scope:` (blue) -- core, admin, auth, ui, i18n (cross-cutting concerns)
- `app:` (salmon) -- calendar, attendance, expenses, admin, dashboard, help, settings, prayer-requests
- `phase:` (purple) -- 3 through 13
- `status:` -- blocked, in-progress, review

## Standing Instructions (per ProjectBrief)

When making changes:

1. Create a GitHub Issue with description, scope, and acceptance criteria
2. Run ALL code through syntax/lint checks -- fix ALL issues until zero remain
3. Update CHANGELOG.md, **FEATURES.md**, DEV_NOTES.md, README.md as appropriate
4. Update `.claude/` memory and context
5. Update GitHub Wiki/Project/Milestones alongside Issues
6. COMMIT changes (DO NOT PUSH unless the user explicitly asks for a PR)
7. Close GitHub Issue with commit / PR reference

### STANDING: monitor & fix GitHub PR Security checks (always applicable)

On EVERY PR you touch, actively monitor GitHub's own automated checks — the
`pr-security.yml` "PR Security Checks" bot comment (route-target-missing,
MariaDB-only DDL, migration idempotency, SQL column drift, schema/seed parity,
etc.), CodeQL, Psalm, static-security, actionlint, and the migration harness —
and **fix any real issue each surfaces**, not only the hard PHP-lint gate. These
checks are non-blocking heuristics but a flagged item is treated as actionable:
resolve it correctly (e.g. a route pointing at a missing handler → build the
handler or remove the route + add the cleanup migration), or, only if it is a
genuine false positive, record why in the PR thread. Re-check after each push
until the security comment is clean. This applies regardless of session.

## Git Notes

- macOS case-insensitive: use two-step rename for case changes
- Never commit: `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `.env`, `*.key`
- Deploy workflow syncs `web/` only, excluding server-managed dirs
- Shared dirs (`_core/`, `_vendor/`, `_sql/`, `_includes/`, `_functions/`, `_libraries/`) mirror with `--delete` — manual server-side edits to these dirs vanish on the next deploy (see DEV_NOTES.md → Troubleshooting)
