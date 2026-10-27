# WebMS Intra

> **Version:** 1.4.0 | **PHP** 8.5 (backward-compatible with 8.4) | **MySQL** 8.0+ | **DreamHost** shared hosting

A modular internal portal platform for organisations, providing centralised access to internal tools, calendar / events, attendance, expenses, leadership directory, prayer requests, announcements, document library, tasks/reminders, multi-site support, and more.

📋 **For the current per-app feature inventory** (what's shipped, what's in flight, what's planned with issue numbers), see **[FEATURES.md](FEATURES.md)**.

---

## Tech Stack

| Layer              | Choice                                                                           | Rationale                                        |
| ------------------ | -------------------------------------------------------------------------------- | ------------------------------------------------ |
| **Backend**        | PHP 8.5 (strict types, backward-compatible with 8.4), MySQL — see **Supported versions** below | Ubiquitous LAMP stack; DreamHost-friendly        |
| **Routing**        | Front-controller + DB-backed router (tblRoutes)                                  | Clean URLs, app isolation, easy overrides        |
| **Auth**           | Local accounts, MS365 OAuth, Google OAuth, WebAuthn/PassKeys, account linking    | Multi-provider SSO, passwordless support         |
| **Multi-Site**     | Umbrella multi-site with subdomain, path-prefix, and session detection modes     | One install serves multiple locations/divisions  |
| **UI**             | Bootstrap 5.3.3 + design tokens; light/dark/auto theme + CB-safe palette         | Responsive, WCAG-conscious, per-site themable    |
| **PDF**            | dompdf 3.1.5 (fetched at deploy time by `tools/download-dompdf.sh`)              | Server-side PDF; pinned version vendored in CI   |
| **Email**          | Microsoft 365, Google Workspace, or an ordinary SMTP mailbox (set at Admin, Integrations, Email) | One route for the installation; certificate always checked |
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
    │   ├── Mailer.php               # The one door for sending e-mail (Microsoft 365, Google, SMTP)
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
    ├── _install/                    # the installer and the upgrade page
    ├── _auth_keys/                  # DB credentials, encryption key (gitignored)
    ├── _includes/                   # Shared includes (future)
    ├── _functions/                  # Shared functions (future)
    ├── _libraries/                  # Self-hosted libs e.g. dompdf (gitignored)
    ├── _uploads/                    # User file uploads (gitignored)
    ├── _backups/                    # Server backups (gitignored)
    │
    │
    │  ── App controllers (outside webroot, #159) ──
    ├── _apps/                       # Every app's PHP handlers
    │   ├── auth/                    # Login, forgot/reset password, account
    │   ├── dashboard/               # Portal home with app cards
    │   ├── expenses/                # Expense claim lifecycle
    │   ├── attendance/              # Attendance tracker app
    │   ├── leadership/              # Leadership roles & assignments
    │   ├── calendar/                # Calendar / Events / Preaching Plan
    │   ├── admin/                   # Admin panel (sites, users, logs, migrations)
    │   ├── help/                    # Help centre pages
    │   ├── site/                    # Site switcher handler
    │   ├── settings/                # Admin settings UI
    │   └── …                        # ~35 more app dirs (account, announcements,
    │                                  api, care, directory, documents, events,
    │                                  giving, invites, live, milestones,
    │                                  newsletter, offboarding, payments,
    │                                  photos, praise, prayer-requests, privacy,
    │                                  projects, reading-plans, recordings,
    │                                  resources, rota, service-plans, tasks,
    │                                  users, visitors)
    │
    │  ── Apache DocumentRoot — the ONLY web-accessible tree ──
    ├── public_html/                 # Production web root — lean (#159)
    │   ├── index.php                # Front controller (only PHP entry point)
    │   ├── error.php                # Apache ErrorDocument target
    │   ├── .htaccess                # URL rewriting + .php deny
    │   ├── assets/                  # CSS, JS, images, webfonts
    │   ├── api-docs/                # Swagger UI (DirectoryIndex)
    │   ├── offline/                 # PWA offline fallback page
    │   ├── manifest.php             # Brand-aware PWA manifest controller
    │   ├── openapi.php              # Brand-aware OpenAPI spec controller
    │   ├── robots.php               # Answers the /robots.txt address
    │   ├── sitemap.php              # Answers the /sitemap.xml address
    │   ├── sitemap.xsd              # The sitemap's own schema
    │   ├── widget/                  # countdown.js, embedded by other websites.
    │   │                            # Do NOT remove: see #478 — the folder hid a
    │   │                            # sign-in-free address, and deleting it as
    │   │                            # clutter would have published that page
    │   └── sw.js                    # Service worker
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

### Supported versions

> ⚠️ **MySQL 8.0 reached the end of its extended support in April 2026** and
> receives no further fixes. Moving off it is tracked as
> [#475](https://github.com/MWBMPartners/WebMS-Intra/issues/475). **The table
> below describes what is true today, not what we would recommend.**

**What the code does today, stated precisely:**

| | What actually happens | What is covered by automated tests |
| --- | --- | --- |
| PHP | The installer's first screen checks for 8.4 or newer and hides "Continue" if it fails. **This can be bypassed** by going straight to a later step of the installer, so treat it as a warning rather than a barrier. The live server was confirmed on **8.5.5** in September 2026. | 8.4 only |
| Database version | The version is now **read, judged, and acted on** (#489). New `Portal\Core\DbServer` classifies whatever is connected as supported, worth knowing about, or too old for this portal's own database changes to run on. The installer checks it the moment it first connects and **refuses to continue only when the database is too old to install onto** — anything else is shown and never blocks. **Admin → Server Information** (new) reports the same verdict. The admin dashboard and the health page now read that same judgement too, so the three screens that used to disagree — two of them printing the word "MySQL" whatever the database actually was — now agree. Backup files are deliberately left alone: a snapshot records the raw version string the server reported at the time, because a verdict like "supported" would be stale the moment it was written to an archive. | MySQL 8.0.36 only |
| MariaDB | — | **Not covered.** No MariaDB appears in any workflow or test configuration in this repository. |

On that last row, be careful what you conclude. Every database change here is
written to a convention meant to work on both MySQL and MariaDB
(`DEV_NOTES.md` → "Portable DDL convention"), and that rule is followed
carefully. But **MariaDB is not covered by any automated test in this
repository**, so its compatibility is unverified. That is not the same as "it
does not work" — it means nothing here can currently show that it does. The
same caveat applies to `DbServer`'s own MariaDB judgement: it is checked by a
dependency-free self-test against version strings that real servers report,
which tests the *logic*, not a live MariaDB server — it is not the same claim
as "tested on MariaDB". To see what it covers, run it:

```bash
php tools/db-server-selftest.php
```

The portal also makes its own database connection strict (#593). Its test needs a
THROWAWAY database server started loose, and changes that server's own `sql_mode`
for about a minute before putting it back:

```bash
# docker run --rm ... mysql:8.0.36 --sql-mode=""
SELFTEST_DB_NAME=selftest_strict SELFTEST_DB_PORT=<port> SELFTEST_DB_PASS=<password> php tools/db-strict-mode-selftest.php
```

The mail queue's page and the ways past a failed certificate have a page test of their own (#592, part 4). It needs a
throwaway database that already holds `web/_sql/full_schema.sql`, and two free ports (the second stands in for
Microsoft 365):

```bash
SELFTEST_DB_NAME=selftest_x SELFTEST_DB_PORT=<port> SELFTEST_DB_PASS=<password> SELFTEST_WEB_DIR=/a/quiet/folder php tools/mail-queue-pages-selftest.php
```

(That command is given instead of a number on purpose. This sentence used to
say "15 crafted version strings". The count reached 36 within hours, and the
sentence did not. A number in prose goes stale the moment somebody improves the
thing it describes; a command cannot.)

**Where we are heading** — issue #475. Support dates below are quoted with the
category they belong to, because "supported until" means different things for
each product:

| | Target | Fallback |
| --- | --- | --- |
| MySQL | **9.7 LTS** — premier support now, extended to April 2034 | **8.4 LTS** — premier to April 2029, extended to April 2032 |
| MariaDB | **12.3 LTS** — community maintenance to 12 June 2029 | **11.4 LTS** — community maintenance to 29 May 2029 |
| PHP | **8.5** — active to 31 Dec 2027, security to 31 Dec 2029 | **8.4** — active to **31 Dec 2026**, security to 31 Dec 2028 |

Two notes. MySQL **9.7 is a long-term release** despite the 9.x numbering, not
one of the short-lived quarterly ones. And **PHP 8.4 stops receiving anything
but security fixes at the end of December 2026**, so treat it as contingency
rather than a destination.

- PHP 8.4+ with extensions: `mysqli`, `openssl`, `sodium`, `curl`, `mbstring`
- A MySQL or MariaDB database — see **Supported versions** below
- Apache with `mod_rewrite`

### Fresh Installation

The step-by-step guide for somebody with only a hosting panel is [docs/INSTALL.md](docs/INSTALL.md). In short:

1. Upload the package so that the website's address points at the **web folder** and every other folder sits one
   level above it. Never point an address at the folder that holds `_core`.
2. Open the portal's plain address in a browser. The installer starts by itself. A list of folders, "Forbidden" or
   "Not found" there means the address points at the wrong folder.
3. Read the installation code from `_auth_keys/install-code.php` in the hosting panel's file manager and type it in.
4. Follow the steps: checks, kind of organisation, database, tables, first administrator, web addresses, finish.
5. Keep a copy of the `_auth_keys` folder with every backup of the database.

### Upgrading

The step-by-step guide is "Upgrading to a newer package" in [docs/INSTALL.md](docs/INSTALL.md). In short:

1. Read the new version's notes in `CHANGELOG.md` and make a backup. Some things have to be done before the new
   files are uploaded.
2. Put the new files in place: unpack the new package over the old one, or let your deploy copy them.
3. Sign in as a global administrator and open `/admin/upgrade`. If the new version brings a database upgrade,
   everybody else sees a holding page until you press the button there. If it brings none, the portal stays open:
   press **Finish the upgrade** all the same, so that the new version is recorded.
4. Check that the portal is working.

### After upgrading to the next release

- **Set the portal's web address.** Sign in as a global administrator and open Admin: a notice shows the address you
  are on and a **Use this address** button. Until it is set, a password-reset link follows whatever host name the
  request named, which matters on hosting where the portal answers for any name.
- **Type your support e-mail address again** at Admin → Settings → Organisation, "Web addresses". The one that used to
  be built in is blanked by the upgrade.
- **Before uploading, switch off anything you do not want to start.** Features that were switched on, with a secret
  saved on their own page, never worked until now and start as soon as the new files are on the server: error reports
  to an outside error service, the venue reminders job, web push; and text messages, payments, AI, transcription and
  maps when somebody uses them.
- **Keep a copy of `_auth_keys/enc.key` with every database backup.** From this version every stored secret depends on
  it. Then press Upgrade: it encrypts secrets an older version stored as readable text and names what it did.
- **A scheduled job whose token was regenerated may stop.** Copy the token its page now shows into the scheduler.
- **Add one scheduled job for waiting mail**, every five minutes, in your hosting panel's scheduler (the address is an
  example: use your portal's own, and the maintenance token from Admin → Settings):
  `*/5 * * * * curl -fsS -H "Authorization: Bearer YOUR_TOKEN" "https://YOUR-PORTAL/cron/mail-queue" > /dev/null`.
  Until it runs, mail goes as it did before, a password reset included, but anything that could not be sent at once
  waits, and the health page shows that in red. After a backup is restored, waiting mail is held.
- **If you copy events in from outside calendars, add one scheduled job for them**, every 15 minutes (the address is an example:
  use your portal's own, and the value of the `feeds.cron_token` setting at Admin → Settings in place of `YOUR_KEY`):
  `*/15 * * * * curl -fsS "https://YOUR-PORTAL/cron/import-feeds?key=YOUR_KEY" > /dev/null`.
  Without it nothing is refreshed, and administrators are not e-mailed or sent a push notification when imported events
  are waiting for approval (the dashboard card still shows them). The page is Outside calendars, at `/admin/calendar/feeds`.
- The full list, including what is not sent when the portal has no usable address, is in `CHANGELOG.md`.

### Say which copy it is (recommended)

The portal needs to know whether it is the live site or a pre-release copy. It asks, in order: the `PORTAL_ENV`
setting; a file named `.channel` in the folder that holds `_core` (NOT the web folder); the web folder's own name, if
it is exactly `public_html` (live), `public_html_beta` (beta) or `public_html_dev` (dev). If none of those says, it
treats itself as the live site, with errors hidden.

- Create a plain text file named `.channel` beside `_core` holding one word on its first line: `live`, `beta`, `alpha`
  or `dev`. Then open `/health`: it should say `"channelSource":"file"`.
- **A pre-release copy in a folder of another name must be given the file (or the setting).** Without it that copy is
  treated as live and has no staff sign-in gate.
- **Where several copies share one `_core` folder, do NOT create the file**: one file would speak for every copy. Keep
  the three folder names, or give each web folder its own `PORTAL_ENV`.

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

| Branch  | Channel    | Where it lands on the server                     | Auto-bump rule       |
| ------- | ---------- | ------------------------------------------------ | -------------------- |
| `alpha` | alpha/dev  | its own folder, named by `SFTP_PATH_ALPHA_DIR`    | PATCH (always)       |
| `beta`  | beta       | its own folder, named by `SFTP_PATH_BETA_DIR`     | Conventional Commits |
| `main`  | production | its own folder, named by `SFTP_PATH_LIVE_DIR`     | none on `main`       |

**A merge into `main` BUILDS the release by itself** — `package.yml` works out the version
and makes the installation zip. Nobody pushes a `v*` tag any more, and a tag pushed by hand
publishes nothing.

**Whether it is PUBLISHED is a separate switch:** the repository variable
`PACKAGE_PUBLISH_ENABLED` must be exactly `true`. With it off — which is how it sits today —
the run still builds the zip and keeps it on the run, makes no tag, and prints what it would
have done. This paragraph said a merge "publishes the release by itself" until 27 October 2026,
which would have had somebody expecting a release that never appeared.

Each channel now has a folder of its own, and the web address points at `admin_html`
inside it. The three channels no longer share one base folder, so a deploy of one cannot
overwrite another's code — which the old shared layout did, last push winning.

Inside a channel's folder the deploy makes `admin_html` (the staff pages) and the code
folders (`_apps`, `_core`, `_install`, `_lang`, `_sql`, `_vendor`, `_libraries`), and
creates `_auth_keys`, `_uploads` and `_backups` once if they are missing, never touching
them again.

Workflows in `.github/workflows/`:

- `deploy.yml` — SFTP sync; key-first / password-fallback auth
- `version-bump.yml` — raises the version number in `web/_core/version.php`
  on pushes to `alpha` or `beta`
- `changelog.yml` — appends commit-message entries to CHANGELOG.md
- `package.yml` — builds the installation zip by itself on every merge into a
  release branch, and publishes a GitHub Release for `main` and `rc`. It
  replaced `release.yml`, which made a Release only when somebody pushed a
  `v*` tag by hand; pushing a tag now publishes nothing
- `auto-merge-alpha.yml` — enables auto-merge on PRs whose base is `alpha`,
  then starts `deploy.yml` and `package.yml` itself. GitHub raises no event
  for a merge it makes with its own token, so nothing that listens for a push
  to `alpha` would otherwise hear these merges
- `pr-security.yml` — the hard gate. Checks every PHP file for syntax errors,
  scans for committed secrets, and runs most of the checks in
  `tools/audit-checks/`. **Seven of the 32 are named in no workflow at all**
  and run only when somebody runs them by hand, two of them tripwires added
  this week (#625's inline-script check and #622's session-identifier check);
  that gap is tracked as #637. Count from the workflow rather than
  trusting a number written here — an earlier version of this line said "all
  eleven", which was both stale and untrue
- `portal-selftests.yml`, `mail-selftests.yml`, `calendar-selftests.yml` — the
  repository's own self-tests, on **every** pull request. Some need only PHP;
  the rest run against a real MySQL. `calendar-selftests.yml` is pinned to
  MySQL 8.0.36 only, with no matrix
- `backup-selftest.yml` — the backup and restore self-tests, **only when a pull
  request changes one of about sixteen named paths** (`DbBackup.php`, the backup
  pages, `web/_sql/**` and so on). This used to be listed above as running on
  every pull request, which it has never done. **A path-filtered check must
  never be made a required status check**: GitHub leaves it pending for ever on
  a pull request that does not touch those paths, and nothing can then merge
- `e2e-migrations.yml` — loads the database from scratch and replays every
  change on top, to prove nothing breaks on upgrade. It runs against **both
  MySQL 8.0.36 and 8.4.11**, as a matrix of exact versions (the harness refuses
  to run if the server reports anything else). It is path-filtered too, on
  `web/_sql/` and the harness itself, so the warning above applies to it as well
- `php-static-analysis.yml` — **a hard gate, not advisory**: `php -l` on every
  PHP file under `web/`, with no `continue-on-error`, so a syntax error turns the
  run red. It was listed as advisory here until 27 October 2026, which was true
  only while it also carried Psalm; Psalm was removed on 13 October and the
  lint is all that is left
- `codeql.yml`, `lint.yml`, `repo-config-audit.yml` — advisory. `codeql.yml`
  keeps a `continue-on-error` on purpose, because GitHub's paid Code Scanning
  is not enabled here and the upload is refused without it; removing the flag
  would turn every run red for a billing reason. With Psalm gone, **no
  code-analysis check here can turn a run red** (#618)

> ⚠️ **Known problem: `version-bump.yml` and `changelog.yml` have not run on
> `alpha` since 7 July 2026**, across roughly thirty merged pull requests. Both
> are triggered by a push. Pull requests into `alpha` are merged automatically
> by `auto-merge-alpha.yml` using the build service's own token, and GitHub
> deliberately does not let one automated job start another that way. The
> auto-merge job already works around this for deployment, by starting it
> explicitly; the other two never got the same treatment, and neither offers a
> manual trigger. The visible effect is that `alpha` still reports version
> 1.4.0 while `beta` and `main` report 1.4.1, and the changelog for `alpha` is
> only current because people have been editing it by hand in each pull
> request.

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

**Shipped since Phase 13:**

- **#137** — Calendar seven view modes (Day, Week, Weekdays, Weekend, Month, Year planner, List). Merged (PR #137).
- **#138** — Calendar per-month strap-lines + category `displayStyle` toggle (background-band vs text-only) — stacked on #137. Merged (PR #138).
- **#128** — Re-scoped: service-plans (#262/#300) + Worship (#308/#355) already covered the "Order of Service planner" ask; the residual — local hymnal index, an optional default-off remote ("iHymns") lookup, and a congregation-facing public `/os/{token}` view — shipped in migration 178.
- **#153** — Forms Builder app: generic admin-built form designer, internal + optional public (`/f/{token}`) fill, response review/CSV export, built on a reusable `Portal\Core\FormEngine`. Merged (migration 182).

**Tracked but not started:** WordPress Multisite integration (#127), BookIT integration cluster (#97–#103), Privacy / GDPR helpers (#47), Payment integration prep (#40). See [FEATURES.md](FEATURES.md#tracked-but-not-started) for the full backlog with scope notes.

---

## Licence

All Rights Reserved. Copyright 2025-present MWBM Partners Ltd (t/a MWservices). No licence is granted for use, modification, or distribution without explicit written permission.
