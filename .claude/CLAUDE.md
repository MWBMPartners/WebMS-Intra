# WebMS Intra - Claude Code Instructions

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, MySQL 8.0, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

- **Version:** 0.3.0
- **Licence:** All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
- **Repo:** github.com/MWBMPartners/WebMS-Intra
- **Server:** portal.millrdsdacambridge.uk
- **Full brief:** `.claude/ProjectBrief_Chat.claude`

## Directory Layout

```
repo root/          <- NOT deployed (docs, CI/CD only)
web/                <- ALL deployable files (synced to server via FTP)
  core/             <- Framework classes (Portal\Core namespace)
  vendor/           <- Vendored libs (simplejwt)
  sql/              <- Numbered SQL migrations
  public_html/      <- Web root: front controller + assets + app controllers
    index.php, .htaccess, assets/
    auth/           <- Login, forgot/reset password, account
    dashboard/      <- Portal home
    expenses/       <- Expense claim lifecycle
    help/           <- Help centre
    settings/       <- Admin settings
```

## Apps

- Calendar/Events/Preaching Plan is ONE single app ("Events")
- Calendar = viewing/listing/subscribing; Preaching Plan = worship service event types
- Each app lives at `web/public_html/{appname}/`

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
- `PORTAL_CORE` -- web/core/
- `PORTAL_APPS` -- web/public_html/
- `PORTAL_VENDOR` -- web/vendor/
- `PORTAL_SQL` -- web/sql/
- `PORTAL_ENV` -- 'dev' or 'prod'

## GitHub Labels

- `type:` -- feature, enhancement, bug, security, docs, infrastructure, refactor
- `priority:` -- critical, high, medium, low
- `scope:` (blue) -- core, admin, auth, ui, i18n (cross-cutting concerns)
- `app:` (salmon) -- calendar, attendance, expenses, admin, dashboard, help, settings
- `phase:` (purple) -- 3 through 9
- `status:` -- blocked, in-progress, review

## Standing Instructions (per ProjectBrief)

When making changes:

1. Create a GitHub Issue with description, scope, and acceptance criteria
2. Run ALL code through syntax/lint checks -- fix ALL issues until zero remain
3. Update CHANGELOG.md, DEV_NOTES.md, README.md
4. Update `.claude/` memory and context
5. Update GitHub Wiki/Project/Milestones alongside Issues
6. COMMIT changes (DO NOT PUSH -- user pushes manually)
7. Close GitHub Issue with commit reference

## Git Notes

- macOS case-insensitive: use two-step rename for case changes
- Never commit: `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `.env`, `*.key`
- Deploy workflow syncs `web/` only, excluding server-managed dirs
