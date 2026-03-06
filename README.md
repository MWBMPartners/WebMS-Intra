# WebMS Intra

> **Version:** 0.3.0 | **PHP** 8.4+ (8.3 compatible) | **MySQL** 8.0+ | **DreamHost** shared hosting

A modular internal portal platform for organisations, providing centralised access to internal tools, expense management, and future modules (Calendar, Attendance, Leadership, Preaching Plan).

---

## Tech Stack

| Layer              | Choice                                                                           | Rationale                                        |
| ------------------ | -------------------------------------------------------------------------------- | ------------------------------------------------ |
| **Backend**        | PHP 8.4 (strict types), MySQL 8.0                                                | Ubiquitous LAMP stack; DreamHost-friendly        |
| **Routing**        | Front-controller + DB-backed router (tblRoutes)                                  | Clean URLs, app isolation, easy overrides        |
| **Auth**           | Local accounts (primary), MS365 OAuth (conditional), SIGNula SSO (future)        | Flexible auth, SSO integration planned           |
| **UI**             | Bootstrap 5.3.3, Font Awesome 6.5.1, custom CSS design system                   | Responsive, WCAG compliant, dark mode            |
| **PDF**            | dompdf 2.0 (in `_libraries/`, manually uploaded)                                 | Server-side PDF without external service         |
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
    ├── core/                        # Framework classes (Portal\Core namespace)
    │   ├── App.php                  # Application registry (db, settings, user)
    │   ├── ApiResponse.php          # JSON API response builder
    │   ├── Asset.php                # CDN-with-fallback asset loader (SRI)
    │   ├── Auth.php                 # Authentication (MS365, local, CSRF, JWT)
    │   ├── Avatar.php               # Avatar cascade (MS365 > local > Gravatar > SVG)
    │   ├── bootstrap.php            # Environment, DB, settings, autoloader
    │   ├── Captcha.php              # Turnstile / reCAPTCHA helper
    │   ├── Debug.php                # Debug panel (admin + ?debug=true)
    │   ├── ExpensePdf.php           # Expense claim PDF generator
    │   ├── Gatekeeper.php           # Dev/channel access control
    │   ├── Logger.php               # Activity and error logging
    │   ├── Mailer.php               # Email via Microsoft Graph API
    │   ├── Migrator.php             # Web-based SQL migration runner
    │   ├── Pdf.php                  # dompdf wrapper (conditional load)
    │   ├── RateLimiter.php          # Login rate limiting
    │   ├── Router.php               # Front-controller URL dispatcher
    │   └── templates/               # Shared page templates
    ├── vendor/simplejwt/            # Lightweight RS256 JWT verifier
    ├── sql/                         # Numbered SQL migration files
    ├── _auth_keys/                  # DB credentials, encryption key (gitignored)
    ├── _includes/                   # Shared includes (future)
    ├── _functions/                  # Shared functions (future)
    ├── _libraries/                  # Self-hosted libs e.g. dompdf (gitignored)
    ├── _uploads/                    # User file uploads (gitignored)
    ├── _backups/                    # Server backups (gitignored)
    ├── public_html/                 # Production web root
    │   ├── index.php                # Front controller
    │   ├── .htaccess                # URL rewriting
    │   ├── assets/                  # CSS, JS, images, webfonts
    │   ├── auth/                    # Login, forgot/reset password, account
    │   ├── dashboard/               # Portal home with app cards
    │   ├── expenses/                # Expense claim lifecycle
    │   ├── help/                    # Help centre pages
    │   └── settings/                # Admin settings UI
    ├── public_html_dev/             # Dev web root (Gatekeeper-protected)
    ├── private_html/                # Private / non-live files
    ├── public_html_landing/         # Pre-launch landing page
    └── public_html_redir/           # Redirect page
```

## Request Flow

```text
Browser -> .htaccess -> index.php -> bootstrap.php -> Router::dispatch()
  |-- Special route? (login/ms365, logout, api/*, health) -> handle directly
  |-- Query tblRoutes for matching routeKey
  |-- If isProtected=1, enforce Auth::requireLogin()
  '-- Include target app file -> header.php -> content -> footer.php
```

---

## Setup

### Prerequisites

- PHP 8.3+ with extensions: `mysqli`, `openssl`, `sodium`, `curl`, `mbstring`
- MySQL 8.0+
- Apache with `mod_rewrite`

### Server Configuration

1. Upload `web/` contents to the server domain directory
2. Create `_auth_keys/auth_creds.php` returning: `['db_host'=>..., 'db_user'=>..., 'db_pass'=>..., 'db_name'=>..., 'db_port'=>3306]`
3. Generate encryption key: `openssl rand -hex 32 > _auth_keys/enc.key`
4. Upload dompdf to `_libraries/dompdf/` (download from github.com/dompdf/dompdf)
5. Access the portal and run pending migrations via admin UI
6. Configure MS365 OAuth settings in the Settings admin page

### Local Development

```bash
cd web
export PORTAL_ENV=dev
php -S localhost:8080 -t public_html
```

---

## Deployment

CI/CD via GitHub Actions syncs the `web/` directory to DreamHost via FTP.

- **Push to `main`** deploys automatically to the server
- **Tagged `v*`** also deploys (production releases)
- **Manual dispatch** available via GitHub Actions UI

Server-managed directories (`_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`) are excluded from FTP sync.

---

## Security

- MySQLi prepared statements (no raw SQL interpolation)
- Sensitive settings encrypted at rest (libsodium XSalsa20 + Poly1305)
- Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax`
- CSRF tokens with rotation after use
- Rate limiting on login attempts (configurable via settings)
- RS256 JWT verification with JWKS key fetching for MS365 tokens
- SRI integrity hashes on CDN resources
- Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`

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

| Phase | Description                                                                     | Status  |
| ----- | ------------------------------------------------------------------------------- | ------- |
| 1     | Core Framework                                                                  | Done    |
| 2     | Local Auth Enhancement (forgot/reset password, account page, policy engine)     | Done    |
| 2.5   | Directory Restructure (web/ consolidation, deploy fix, bug fixes)               | Done    |
| 3     | Admin UI (error logs, activity logs, user management, migration runner)         | Planned |
| 4     | Expenses Completion (multi-approver, email notifications, PDF at each stage)    | Planned |
| 5     | New Apps (Calendar, Attendance, Leadership, Preaching Plan)                     | Planned |

---

## Licence

MIT License - Copyright 2025-present MWBM Partners Ltd (t/a MWservices)
