# WebMS Intra

> **Version:** 1.0.0 | **PHP** 8.5 (backward-compatible with 8.4) | **MySQL** 8.0+ | **DreamHost** shared hosting

A modular internal portal platform for organisations, providing centralised access to internal tools, calendar / events, attendance, expenses, leadership directory, prayer requests, announcements, document library, tasks/reminders, multi-site support, and more.

📋 **For the current per-app feature inventory** (what's shipped, what's in flight, what's planned with issue numbers), see **[FEATURES.md](FEATURES.md)**.

---

## Tech Stack

| Layer              | Choice                                                                           | Rationale                                        |
| ------------------ | -------------------------------------------------------------------------------- | ------------------------------------------------ |
| **Backend**        | PHP 8.5 (strict types, backward-compatible with 8.4), MySQL 8.0                  | Ubiquitous LAMP stack; DreamHost-friendly        |
| **Routing**        | Front-controller + DB-backed router (tblRoutes)                                  | Clean URLs, app isolation, easy overrides        |
| **Auth**           | Local accounts, MS365 OAuth, Google OAuth, WebAuthn/PassKeys, account linking    | Multi-provider SSO, passwordless support         |
| **Multi-Site**     | Umbrella multi-site with subdomain, path-prefix, and session detection modes     | One install serves multiple locations/divisions  |
| **UI**             | Bootstrap 5.3.3 + design tokens; light/dark/auto theme + CB-safe palette         | Responsive, WCAG-conscious, per-site themable    |
| **PDF**            | dompdf 3.1.5 (fetched at deploy time by `tools/download-dompdf.sh`)              | Server-side PDF; pinned version vendored in CI   |
| **Email**          | Microsoft Graph "SendAs" via shared mailbox                                      | DKIM/DMARC compliance, modern auth               |
| **Bot Protection** | CloudFlare Turnstile (preferred) / reCAPTCHA                                     | Reduces spam without degrading UX                |

---

## Repository Structure

```
WebMS-Intra/                         # Git repository root (NOT deployed)
├── .claude/                         # Claude AI context and project brief
├── .github/workflows/deploy.yml     # CI/CD pipeline (syncs web/ to server)
├── CHANGELOG.md                     # Project-wide changelog
├── DEV_NOTES.md                     # Developer guide
├── README.md                        # This file
└── web/                             # ALL deployable server files
    │
    │  ── Server-side (NOT web-accessible — above DocumentRoot) ──
    ├── _core/                       # Framework classes (Portal\Core namespace)
    │   ├── App.php                  # Application registry (db, settings, user)
    │   ├── ApiResponse.php          # JSON API response builder
    │   ├── Site.php                 # Multi-site context manager (detection, branding)
    │   ├── Asset.php                # CDN-with-fallback asset loader (SRI)
    │   ├── Auth.php                 # Authentication (MS365, Google, local, WebAuthn, CSRF, JWT)
    │   ├── Avatar.php               # Avatar cascade (MS365 > local > Gravatar > SVG)
    │   ├── bootstrap.php            # Environment, DB, settings, autoloader
    │   ├── Captcha.php              # Turnstile / reCAPTCHA helper
    │   ├── Debug.php                # Debug panel (admin + ?debug=true)
    │   ├── ExpenseMailer.php        # Expense email notification helper
    │   ├── ExpensePdf.php           # Expense claim PDF generator
    │   ├── Gatekeeper.php           # Dev/channel access control
    │   ├── Logger.php               # Activity and error logging
    │   ├── Mailer.php               # Email via Microsoft Graph API
    │   ├── Migrator.php             # Web-based SQL migration runner
    │   ├── Pdf.php                  # dompdf wrapper (conditional load)
    │   ├── RateLimiter.php          # Login rate limiting
    │   ├── Router.php               # Front-controller URL dispatcher
    │   ├── ApiRouter.php            # Dedicated API route dispatcher
    │   ├── Container.php            # Lightweight dependency injection container
    │   ├── CsvExporter.php          # CSV export helper (expenses, attendance, etc.)
    │   ├── Validator.php            # Input validation framework (pipe-separated rules)
    │   ├── WebAuthn.php             # WebAuthn/PassKey server-side helper
    │   ├── I18n.php                 # Internationalisation framework (translations, RTL, formatting)
    │   └── templates/               # Shared page templates
    ├── _lang/                       # Translation files (en.php, cy.php, etc.)
    ├── _vendor/simplejwt/           # Lightweight RS256 JWT verifier
    ├── _sql/                        # Numbered SQL migration files
    ├── _install/                    # 6-step install wizard (run before first boot)
    ├── _auth_keys/                  # DB credentials, encryption key (gitignored)
    ├── _includes/                   # Shared includes (future)
    ├── _functions/                  # Shared functions (future)
    ├── _libraries/                  # Self-hosted libs e.g. dompdf (gitignored)
    ├── _uploads/                    # User file uploads (gitignored)
    ├── _backups/                    # Server backups (gitignored)
    │
    │  ── Apache DocumentRoot — the ONLY web-accessible tree ──
    ├── public_html/                 # Production web root
    │   ├── index.php                # Front controller
    │   ├── .htaccess                # URL rewriting
    │   ├── assets/                  # CSS, JS, images, webfonts
    │   ├── auth/                    # Login, forgot/reset password, account
    │   ├── dashboard/               # Portal home with app cards
    │   ├── expenses/                # Expense claim lifecycle
    │   ├── attendance/              # Attendance tracker app
    │   ├── leadership/              # Leadership roles & assignments
    │   ├── calendar/                # Calendar / Events / Preaching Plan
    │   ├── admin/                   # Admin panel (sites, users, logs, migrations)
    │   ├── help/                    # Help centre pages
    │   ├── site/                    # Site switcher handler
    │   └── settings/                # Admin settings UI
    ├── private_html/                # Private / non-live files
    ├── public_html_landing/         # Pre-launch landing page
    └── public_html_redir/           # Redirect page
```

The single `public_html/` directory is the source for **every** branch's deploy — `alpha` lands at the server's `public_html_dev/`, `beta` at `public_html_beta/`, and `main` at `public_html/`. There's no per-channel front controller in the repo.

## Request Flow

```text
Browser -> .htaccess -> index.php -> bootstrap.php -> Router::dispatch()
  |-- Special route? (login/ms365, login/google, login/webauthn, logout, api/*, health) -> handle directly
  |-- Query tblRoutes for matching routeKey
  |-- If isProtected=1, enforce Auth::requireLogin()
  '-- Include target app file -> header.php -> content -> footer.php
```

---

## Setup

### Prerequisites

- PHP 8.4+ with extensions: `mysqli`, `openssl`, `sodium`, `curl`, `mbstring`
- MySQL 8.0+
- Apache with `mod_rewrite`

### Fresh Installation

1. Upload `web/` contents to the server domain directory
2. Navigate to the portal URL in your browser — the installation wizard will start automatically
3. Follow the 6-step wizard: prerequisites check, database config, schema install, admin account, encryption key generation
4. Upload dompdf to `_libraries/dompdf/` (download from github.com/dompdf/dompdf)
5. Log in and configure site settings and OAuth providers as needed

### Upgrading

1. Upload the updated `web/` files to the server (FTP sync)
2. Navigate to Admin > Upgrade (or `/admin/upgrade.php`)
3. Review and run any pending SQL migrations
4. Verify the portal is working correctly

### Manual Configuration (alternative to wizard)

1. Create `_auth_keys/auth_creds.php` returning: `['db_host'=>..., 'db_user'=>..., 'db_pass'=>..., 'db_name'=>..., 'db_port'=>3306]`
2. Generate encryption key: `openssl rand -hex 32 > _auth_keys/enc.key`
3. Import `_sql/full_schema.sql` into your database
4. Create a lock file: `touch _auth_keys/.installed`

### Local Development

```bash
cd web
export PORTAL_ENV=dev
php -S localhost:8080 -t public_html
```

---

## Deployment

CI/CD via GitHub Actions syncs `web/` to DreamHost over **SFTP** (SSH key
preferred, password fallback) on a three-branch model:

| Branch  | Channel    | Public dir on server  | Auto-bump rule           |
| ------- | ---------- | --------------------- | ------------------------ |
| `alpha` | alpha/dev  | `public_html_dev/`    | PATCH (always)           |
| `beta`  | beta       | `public_html_beta/`   | Conventional Commits     |
| `main`  | production | `public_html/`        | none — tag `v*` manually |

Everything else inside `web/` (`_core/`, `_vendor/`, `_sql/`, `_lang/`,
`_install/`) deploys to the shared remote base from every branch.

Workflows in `.github/workflows/`:

- `deploy.yml` — SFTP sync; key-first / password-fallback auth
- `version-bump.yml` — updates `web/_core/App.php` on alpha/beta pushes
- `changelog.yml` — appends commit-message entries to CHANGELOG.md
- `release.yml` — creates a GitHub Release on `v*` tag push
- `auto-merge-alpha.yml` — enables auto-merge on PRs whose base is `alpha`

dompdf is fetched at deploy time by `tools/download-dompdf.sh` (pinned
version) and uploaded as part of the shared sync. Other server-managed
directories (`_auth_keys/`, `_uploads/`, `_backups/`) stay on the server and
are excluded from upload.

Required repo configuration (one-time): see **DEV_NOTES.md → CI/CD Secrets
Setup** for the step-by-step guide (SSH keypair, GitHub secrets, kill switch,
branch protection).

---

## Security

- MySQLi prepared statements (no raw SQL interpolation)
- Sensitive settings encrypted at rest (libsodium XSalsa20 + Poly1305)
- Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax`
- CSRF tokens with rotation after use
- Rate limiting on login attempts (configurable via settings)
- RS256 JWT verification with JWKS key fetching for MS365 tokens
- SRI integrity hashes on CDN resources
- Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`, `Permissions-Policy`

---

## Coding Conventions

- `declare(strict_types=1)` at the top of every PHP file
- Full IF notation (`if ($x === true)` not `if ($x)`)
- Platform-neutral paths using `DIRECTORY_SEPARATOR`
- Emoji-annotated comments for major code sections
- No `<table>` tags for data display - use `portal-data-list` responsive component
- MySQLi prepared statements only - never interpolate user input into SQL

---

## Roadmap

Phase-level milestones (granular per-feature state lives in [FEATURES.md](FEATURES.md)).

| Phase | Description                                                                     | Status           |
| ----- | ------------------------------------------------------------------------------- | ---------------- |
| 1     | Core Framework                                                                  | Done             |
| 2     | Local Auth Enhancement (forgot/reset password, account page, policy engine)     | Done             |
| 2.5   | Directory Restructure (web/ consolidation, deploy fix, bug fixes)               | Done             |
| 3     | Admin UI (error logs, activity logs, user management, migration runner)         | Done             |
| 4     | Calendar / Events / Preaching Plan (incl. seven view modes — see #136/#137)     | Done             |
| 5     | Attendance Tracker                                                              | Done             |
| 6     | Expenses — Multi-Approver, Email, PDF, Treasury                                 | Done             |
| 7     | SSO & Auth Enhancement (Google OAuth, WebAuthn/PassKeys, Account Linking)       | Done             |
| 8     | Translations / i18n (I18n framework, RTL support, language switcher)            | Done             |
| 9     | Polish & Hardening (PWA, WCAG 2.1, Security Hardening — incl. #53 #54)          | Done             |
| 10    | Multi-Site Support (umbrella orgs, site detection, 4-tier permissions)          | Done             |
| 11    | UI Refresh + Design System (themes, CB-safe palette, per-site branding)         | Done             |
| 12    | Prayer Requests app (incl. anonymous public route — #129)                       | Done             |
| 13    | Multi-provider Captcha (Turnstile + reCAPTCHA v2/v3 + hCaptcha — #130)          | Done             |

**Currently in flight (open PRs):**

- **#137** — Calendar seven view modes (Day, Week, Weekdays, Weekend, Month, Year planner, List).
- **#138** — Calendar per-month strap-lines + category `displayStyle` toggle (background-band vs text-only) — stacked on #137.

**Tracked but not started:** WordPress Multisite integration (#127), Order of Service planner with iHymns (#128), BookIT integration cluster (#97–#103), composite IP+username login rate-limit (#52), Privacy / GDPR helpers (#47), Payment integration prep (#40). See [FEATURES.md](FEATURES.md#tracked-but-not-started) for the full backlog with scope notes.

---

## Licence

All Rights Reserved. Copyright 2025-present MWBM Partners Ltd (t/a MWservices). No licence is granted for use, modification, or distribution without explicit written permission.
