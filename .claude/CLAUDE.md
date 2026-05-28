# WebMS Intra - Claude Code Instructions

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, MySQL 8.0, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

- **Version:** 0.11.0 (on `main`)
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
  _vendor/simplejwt/<- Vendored RS256 JWT verifier
  _sql/             <- Numbered SQL migrations (000-052 + full_schema.sql)
  _lang/            <- I18n translation files (en.php, cy.php, …)
  _install/         <- Standalone 6-step installation wizard (bootstrap-free)
  public_html/      <- Web root: ONE front controller + assets + app controllers.
                       Branch-based deploy mirrors this dir to the server's
                       public_html/ (main), public_html_beta/ (beta) or
                       public_html_dev/ (alpha) — no per-channel copy in repo.
    index.php, .htaccess, assets/
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
| announcements | `/announcements` | Per-site noticeboard |
| documents | `/documents` | File library with categories |
| tasks | `/tasks` | Reminders / task list |
| api | `/api/*` | Read-only JSON endpoints (attendance, announcements, users, events) |
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

## Key Constants (defined in core/bootstrap.php)

- `PORTAL_ROOT` -- web/ on server
- `PORTAL_CORE` -- web/_core/
- `PORTAL_APPS` -- web/public_html/
- `PORTAL_VENDOR` -- web/_vendor/
- `PORTAL_SQL` -- web/_sql/
- `PORTAL_ENV` -- 'dev', 'beta', or 'prod' (auto-detected from DOCUMENT_ROOT)

## Recent ships (chronological)

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

## Git Notes

- macOS case-insensitive: use two-step rename for case changes
- Never commit: `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `.env`, `*.key`
- Deploy workflow syncs `web/` only, excluding server-managed dirs
- Shared dirs (`core/`, `vendor/`, `sql/`, `_includes/`, `_functions/`, `_libraries/`) mirror with `--delete` — manual server-side edits to these dirs vanish on the next deploy (see DEV_NOTES.md → Troubleshooting)
