# WebMS Intra

> **Version:** 0.1.0 | **PHP** 8.3/8.4 | **MySQL** 8.0+ | **DreamHost** shared hosting

A modular internal portal platform for organisations, providing centralised access to internal tools, expense management, and future modules (Calendar, Attendance, Leadership, Preaching Plan).

---

## Tech Stack

| Layer              | Choice                                                                           | Rationale                                        |
| ------------------ | -------------------------------------------------------------------------------- | ------------------------------------------------ |
| **Backend**        | PHP 8.4 (strict types), MySQL 8.0                                                | Ubiquitous LAMP stack; DreamHost-friendly        |
| **Routing**        | Front-controller + DB-backed router (tblRoutes)                                  | Clean URLs, app isolation, easy overrides        |
| **Auth**           | Microsoft 365 OAuth (primary), local accounts, Google Workspace (future)         | Secure SSO for staff, flexibility for volunteers |
| **UI**             | Bootstrap 5.3.3, Font Awesome 6.5.1, custom CSS design system                   | Responsive, WCAG compliant, dark mode            |
| **PDF**            | dompdf 2.0 (vendored)                                                            | Server-side PDF without external service         |
| **Email**          | Microsoft Graph "SendAs" via shared mailbox                                      | DKIM/DMARC compliance, modern auth               |
| **Bot Protection** | CloudFlare Turnstile (preferred) / reCAPTCHA                                     | Reduces spam without degrading UX                |

---

## Architecture

```
WebMS-Intra/
├── core/                    # Framework classes (Portal\Core namespace)
│   ├── App.php              # Application registry (db, settings, user)
│   ├── ApiResponse.php      # JSON API response builder
│   ├── Asset.php            # CDN-with-fallback asset loader (SRI)
│   ├── Auth.php             # Authentication (MS365, local, CSRF, JWT)
│   ├── Avatar.php           # Avatar cascade (MS365 → local → Gravatar → SVG)
│   ├── bootstrap.php        # Environment, DB, settings, autoloader
│   ├── Captcha.php          # Turnstile / reCAPTCHA helper
│   ├── Debug.php            # Debug panel (admin + ?debug=true)
│   ├── ExpensePdf.php       # Expense claim PDF generator
│   ├── Gatekeeper.php       # Dev/channel access control
│   ├── Logger.php           # Activity and error logging
│   ├── Mailer.php           # Email via Microsoft Graph API
│   ├── Migrator.php         # Web-based SQL migration runner
│   ├── Pdf.php              # dompdf wrapper
│   ├── RateLimiter.php      # Login rate limiting
│   ├── Router.php           # Front-controller URL dispatcher
│   └── templates/           # Shared page templates
│       ├── header.php       # DOCTYPE, head, navbar, breadcrumbs
│       ├── footer.php       # Footer, JS, debug panel
│       ├── nav.php          # Responsive navbar component
│       └── error-{403,404,500}.php
├── apps/                    # Modular application files
│   ├── auth/login/          # Login page (MS365 SSO + local)
│   ├── dashboard/           # Portal home with app cards
│   ├── expenses/            # Expense claim lifecycle
│   │   ├── submit/          # Claim submission form
│   │   ├── approve/         # Approval dashboard
│   │   ├── treasury/        # Reimbursement dashboard
│   │   └── api/             # JSON API endpoints
│   └── settings/            # Admin settings UI
├── sql/                     # Numbered SQL migration files
├── vendor/simplejwt/        # Lightweight RS256 JWT verifier
├── public_html/             # Web root (production)
│   ├── index.php            # Front controller
│   ├── .htaccess            # URL rewriting
│   └── assets/              # CSS, JS, images, webfonts
├── public_html_dev/         # Web root (development)
├── private_html/            # Private / non-live files
├── public_html_landing/     # Temporary landing page
└── public_html_redir/       # Temporary redirect page
```

## Request Flow

```text
Browser → .htaccess → index.php → bootstrap.php → Router::dispatch()
  ├── Special route? (login/ms365, logout, api/*, health) → handle directly
  ├── Query tblRoutes for matching routeKey
  ├── If isProtected=1, enforce Auth::requireLogin()
  └── Include target app file → header.php → content → footer.php
```

---

## Setup

### Prerequisites

- PHP 8.3+ with extensions: `mysqli`, `openssl`, `sodium`, `curl`, `mbstring`
- MySQL 8.0+
- Apache with `mod_rewrite`

### Configuration

1. Create `_auth_keys/auth_creds.php` returning an array with `db_host`, `db_user`, `db_pass`, `db_name`, `db_port`
2. Generate encryption key: `openssl rand -hex 32 > _auth_keys/enc.key`
3. Access the portal and run pending migrations via admin UI
4. Configure MS365 OAuth settings in the Settings admin page

### Local Development

```bash
export PORTAL_ENV=dev
php -S localhost:8080 -t public_html
```

---

## Deployment Channels

| Channel    | Directory              | Branch / Trigger | Purpose                  |
| ---------- | ---------------------- | ---------------- | ------------------------ |
| Production | `public_html/`         | Tagged `v*`      | Live users               |
| Dev        | `public_html_dev/`     | Push to `main`   | Developer testing        |
| Private    | `private_html/`        | --               | Non-live / internal      |
| Landing    | `public_html_landing/` | --               | Temporary landing page   |
| Redirect   | `public_html_redir/`   | --               | Temporary redirect page  |

CI/CD via GitHub Actions syncs files to DreamHost via FTP on push.

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

| Phase                                                                        | Status  |
| ---------------------------------------------------------------------------- | ------- |
| 1 - Core Framework                                                           | Done    |
| 2 - Auth Completion (Google OAuth, WebAuthn, 2FA)                            | Planned |
| 3 - Admin UI (error logs, activity logs, user management, migration runner)  | Planned |
| 4 - Expenses Completion (multi-approver, notifications, PDF at each stage)       | Planned |
| 5 - New Apps (Calendar, Attendance, Leadership, Preaching Plan)              | Planned |

---

## Licence

MIT License - Copyright 2025-present MWBM Partners Ltd (t/a MWservices)
