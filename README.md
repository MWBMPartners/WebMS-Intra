# WebMS Intra

> **Version:** 1.4.1 (`web/_core/version.php` is the only source) | **PHP** 8.5 (backward-compatible with 8.4) | **MySQL** 8.0+ | shared hosting, no command line (DreamHost is the reference host)

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
├── .github/workflows/deploy.yml     # Uploads the staff pages and the code
│                                    # folders to one channel's own folder
├── CHANGELOG.md                     # Project-wide changelog (also uploaded,
│                                    # for the release-notes page)
├── DEV_NOTES.md                     # Developer guide
├── README.md                        # This file
└── web/                             # Everything that can be deployed lives
                                     # here; not all of it is (see below)
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

`web/public_html/` is the source of the staff pages for **every** channel, and there is no per-channel
front controller in the repository. On the server those pages land in a folder called `admin_html`
inside the channel's own folder — see **Deployment** below for the whole shape.

**Five folders in `web/` are never uploaded**, so "everything deployable lives in `web/`" is not the
same as "all of `web/` is deployed": `private_html/`, `public_html_landing/` and `public_html_redir/`
are kept in the repository by the owner's choice (14 September 2026), and `_includes/` and
`_functions/` are placeholders that may hold nothing but `.gitkeep` and the "refuse everything"
`.htaccess`. The one list of which is which is `tools/deploy/resolve-settings.sh`, and its "tree"
check refuses a deploy when it finds a folder in `web/` that is on none of those lists — so a new
folder cannot be silently left behind.

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
> receives no further fixes, and **MariaDB 10.6's maintenance ended on
> 6 July 2026**. The portal still **accepts** both and warns — the owner's
> decision of 3 November 2026, taken knowing the alternative was refusing to
> install. **The table below describes what is true today, not what we would
> recommend.**

**What the code does today, stated precisely:**

| | What actually happens | Which versions an automated test really runs on |
| --- | --- | --- |
| PHP | The installer's first screen checks for 8.4 or newer and hides "Continue" if it fails. **This can be bypassed** by going straight to a later step of the installer, so treat it as a warning rather than a barrier. `Portal\Core\PhpRuntime` is the one place the version is judged (#640). The live server was confirmed on **8.5.5** in September 2026. | **8.4 and 8.5.** `php -l` runs over every PHP file under `web/` on both, as two steps of one job. Until 3 November 2026 only 8.4 was linted — so the version the portal *recommends* was never checked by the gate that exists to stop a syntax error reaching a customer. |
| Database version | The version is **read, judged, and acted on** (#489). `Portal\Core\DbServer` classifies whatever is connected as supported, worth knowing about, or too old for this portal's own database changes to run on. The installer checks it the moment it first connects and **refuses to continue only when the database is too old to install onto** — anything else is shown and never blocks. **Admin → Server Information** reports the same verdict, and so do the admin dashboard and the health page, so the screens that used to disagree now agree. Backup files are deliberately left alone: a snapshot records the raw version string the server reported at the time, because a verdict like "supported" would be stale the moment it was written to an archive. | **MySQL 8.0.36, 8.4.11 and 9.7.4.** The end-to-end migration harness loads the install script and replays every numbered database change on each — all seven phases and all 42 checks. |
| MariaDB | Judged by the same one class, with its own release-line rules. | **MariaDB 11.4.13 and 12.3.3** in the migration harness (all 42 checks on each), and **11.4** in the calendar self-tests (six test files). **Not** 10.6, 10.11 or 11.8, which the portal still accepts. |

**Be careful what you conclude from that last row, in both directions.** Until
28 October 2026 no MariaDB appeared in any workflow here at all (#487), and
MySQL 9.x and MariaDB 12.x had never been run until 3 November 2026 (#475) —
so versions this portal called "supported" were called that on the strength of
nobody having tried. They have been tried now, and they pass. **That is still
not the same as a customer running the portal on MariaDB in production, which
nobody does.** Every database change is also written to a convention meant to
work on both engines (`DEV_NOTES.md` → "Portable DDL convention"), and
following a convention is not proof either — though it is what makes five
engines pass.

**Where that claim lives, and why it cannot go stale again.**
`DbServer::TESTED_LINES` names the release lines the harness runs on, and the
sentence a customer reads is **derived** from it rather than written by hand.
Two hand-written ones had gone untrue on a customer-facing page: every MariaDB
answer said the tests "only ever run against MySQL" eight days after they
stopped doing so, and every supported-MySQL answer told somebody on 9.7 that
they were ahead of what is tested. `tools/audit-checks/check_tested_db_lines.py`
now compares the constant with the workflow's own matrix and fails both ways.

`DbServer`'s own judgement is checked by a dependency-free self-test against
version strings that real servers report, which tests the *logic* rather than a
live server. To see what it covers, run it:

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

**Where we are heading** — issue #475. **Both database targets below now pass
the migration harness** (MySQL 9.7.4 and MariaDB 12.3.3, all 42 checks each, as
of 3 November 2026), and PHP 8.5 is linted. What remains is a decision about
what to *require* rather than merely accept: the portal still installs on MySQL
8.0 and MariaDB 10.6 with a warning, by the owner's choice. Support dates below
are quoted with the category they belong to, because "supported until" means
different things for each product:

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
- **On a channel put there by `deploy.yml` none of this is needed.** The deploy writes `.channel`
  itself, first of everything it uploads, and each channel has its own `_core`, so no file ever
  speaks for more than one copy. The folder-name route does not come into it either: the deployed
  web folder is called `admin_html`. The shared-`_core` warning above is about an arrangement
  somebody made by hand, or one left over from the layout the deploy used before October 2026.

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

`deploy.yml` uploads the portal over **SFTP** (SSH key preferred, password fallback) when one of
the three release branches changes. **No hosting company is assumed**: the host, the login and the
folders all come from repository secrets, so one login for every channel, a login per channel or a
server per channel are all supported. The branch decides the channel and nothing else does.

| Branch  | Channel    | Where it lands on the server                  |
| ------- | ---------- | --------------------------------------------- |
| `alpha` | alpha      | its own folder, named by `SFTP_PATH_ALPHA_DIR` |
| `beta`  | beta       | its own folder, named by `SFTP_PATH_BETA_DIR`  |
| `main`  | live       | its own folder, named by `SFTP_PATH_LIVE_DIR`  |

Each of those is a folder **name**, never a path, inside the base folder `SFTP_PATH_ROOT_DIR` — or
inside `SFTP_PATH_LIVE_ROOT_DIR` / `_BETA_` / `_ALPHA_` where a channel has been given a base folder
of its own. All three channel names are needed on every run, whichever channel is being deployed,
so that no two can be sent to the same place.

**Every channel is a complete, separate installation in a folder of its own** — its own code, its
own database login and encryption key, its own uploads and backups. Nothing is shared, so a deploy
of one channel cannot change what another runs. Inside a channel's folder:

```text
<SFTP_PATH_ROOT_DIR>/<the channel's folder>/
  .channel        one word (live, beta or alpha), uploaded first, read by the portal
  CHANGELOG.md    for the release-notes page
  admin_html/     <- web/public_html/  (point the website's address HERE and nowhere else)
  _apps/ _core/ _install/ _lang/ _sql/ _vendor/ _libraries/   <- the same folders in web/
  _auth_keys/ _uploads/ _backups/   the server's own: created once if missing,
                                    never uploaded to, never deleted from
```

Each folder is mirrored on its own, with deletion, so a file removed from the repository is removed
from that folder on the server. `web/` is never mirrored as a whole, so a deletion can only happen
inside a folder the repository owns completely; anything else in the channel's folder is simply
never touched.

**Where the names come from:** `admin_html` is set in one place,
`STAFF_WEB_DIR_DEFAULT` in `tools/deploy/resolve-settings.sh`. That script makes every decision
about where files go and which login is used, and the workflow only calls it — so the two cannot
drift apart. On any refusal the run stops before it connects to anything.

**Before this, the three channels shared one base folder**, with `public_html/`,
`public_html_beta/` and `public_html_dev/` side by side in it and the code folders uploaded to the
shared base on *every* branch. The old file said so itself — `git show
c61211e~1:.github/workflows/deploy.yml` opens with "Three branches map to three web roots on a
SINGLE shared remote base" and warns that "Whichever branch pushed last wins for shared code".
`c61211e` (27 October 2026, #499) is the rewrite.

**While a deploy runs, a channel is part old and part new**, from the first folder uploaded to the
last, and a page may show an error. No order of the folders avoids that. If a run stops part way,
the channel stays in that state until a run finishes: start the workflow again by hand from the
same branch, which always uploads.

**On a server's first deploy**, run the workflow by hand with "dry run" ticked and read what it says
it would upload and delete. A dry run creates nothing, so the channel's folder has to exist already;
a real run creates it when it is missing. If that folder already holds a portal from the old shared
layout, tick "adopt existing folder" as well, once.

### Making a release

**The version number is set when a release is prepared, not on every push** (the owner,
4 November 2026 — [D-024](docs/decisions/024-the-version-number-moves-to-release-time.md)).
`version-bump.yml` and `changelog.yml` are started **by hand only**, against the working branch a
release is being prepared on, and both refuse to run against `alpha`, `beta`, `main` or `rc`: a
branch that requires its tests to pass refuses every direct push, including theirs. The number then
reaches `main` through the merge like everything else, and `package.yml` refuses to build a release
whose number was not raised above the last tag — which is what makes the by-hand step impossible to
forget.

**A merge into `main` BUILDS the release by itself** — `package.yml` works out the version
and makes the installation zip. Nobody pushes a `v*` tag any more, and a tag pushed by hand
publishes nothing.

**Whether it is PUBLISHED is a separate switch:** the repository variable
`PACKAGE_PUBLISH_ENABLED` must be exactly `true`. With it off — which is how it sits today —
the run still builds the zip and keeps it on the run, makes no tag, and prints what it would
have done. This paragraph said a merge "publishes the release by itself" until 27 October 2026,
which would have had somebody expecting a release that never appeared.

### Workflows in `.github/workflows/`

- `deploy.yml` — the SFTP upload described above; key-first, password fallback. It ends by asking
  the channel's own staff address (`ADMIN_BASE_URL_LIVE` / `_BETA` / `_ALPHA`) which channel it
  thinks it is. That health check is the only proof that the address really points at the folder
  just uploaded to; with the variable unset it is skipped with a warning, and no address is written
  into the workflow
- `version-bump.yml` — works out the next version number and writes it to
  `web/_core/version.php`. **Started by hand only**, and it refuses to run against `alpha`, `beta`,
  `main` or `rc`. It used to run on every push to `alpha` and `beta`
  (`git show c61211e:.github/workflows/version-bump.yml`, which still carries
  `push: branches: [alpha, beta]`); that stopped on 4 November 2026 in `e50738d`, because a job
  writing straight to a branch is what stopped those branches from being guarded at all
- `changelog.yml` — appends commit-message entries to CHANGELOG.md. **Started by hand only**, for
  the same reason as `version-bump.yml`, and with the same refusal
- `package.yml` — builds the installation zip by itself on every push to `main`, `rc`, `beta` or
  `alpha`, and publishes a GitHub Release for `main` and `rc` **only while `PACKAGE_PUBLISH_ENABLED`
  is on**. It replaced `release.yml`, which made a Release only when somebody pushed a
  `v*` tag by hand; pushing a tag now publishes nothing
- `auto-merge-alpha.yml` — enables auto-merge on PRs whose base is `alpha`,
  then starts `deploy.yml` and `package.yml` itself. GitHub raises no event
  for a merge it makes with its own token, so nothing that listens for a push
  to `alpha` would otherwise hear these merges
- `pr-security.yml` — the hard gate. Checks every PHP file for syntax errors, scans for committed
  secrets, and runs all but two of the scripts in `tools/audit-checks/`, on every pull request into
  `main`, `rc`, `beta` or `alpha`, with no path filter. **No step in it uses `continue-on-error`**,
  so any finding turns the run red.

  **Never trust a count written here.** Count them with
  `ls tools/audit-checks/check_*.py | wc -l`, and check each is wired with
  `for f in tools/audit-checks/check_*.py; do grep -rq "$(basename "$f")" .github/workflows/ ||
  echo "not wired: $f"; done`. The reason to count rather than read is that **this file names every
  audit script individually — no workflow here globs the folder** (`.githooks/pre-push` does, but
  that hook is turned on per clone and `git push --no-verify` skips it), so a new script is not
  wired up until somebody names it.

  **The two that do NOT run on every pull request, and why:**
  - `check_giving_retention_data.py` — named only in `backup-selftest.yml`, which is path-filtered.
    It validates `web/_core/data/giving-retention-countries.json` against its schema, so it has
    nothing to say about a pull request that leaves that file alone.
  - `check_workflow_expression_length.py` — named only in `lint.yml`, which runs when a file under
    `.github/workflows/` changes. That is the only thing it reads: it guards GitHub's ceiling on how
    long one `${{ … }}`-bearing value may be, which on 27 October 2026 made GitHub refuse the whole
    of `pr-security.yml` for a week, so about thirty-five hard gates ran and checked nothing (#745).
- `portal-selftests.yml`, `mail-selftests.yml`, `calendar-selftests.yml` — the
  repository's own self-tests, on **every** pull request. Some need only PHP;
  the rest run against a real database. `calendar-selftests.yml` runs its tests
  on **three engines** — `mysql:8.0.36`, `mysql:8.4` and `mariadb:11.4` — which
  is the one matrix here that covers MariaDB as well as MySQL
- `backup-selftest.yml` — the backup and restore self-tests, **only when a pull
  request changes one of its named paths** (`DbBackup.php`, the backup
  pages, `web/_sql/**` and so on; count them from the workflow). This used to be listed above as
  running on every pull request, which it has never done: the commit that created the file already
  carried the filter (`git log --diff-filter=A -- .github/workflows/backup-selftest.yml` names
  `c61211e`, 27 October 2026, and `git show c61211e:.github/workflows/backup-selftest.yml` shows the
  `paths:` list in it). **A path-filtered check must
  never be made a required status check**: GitHub leaves it pending for ever on
  a pull request that does not touch those paths, and nothing can then merge
- `e2e-migrations.yml` — loads the database from scratch and replays every
  change on top, to prove nothing breaks on upgrade. It runs **five engine-and-version
  pairs side by side**: MySQL 8.0.36, 8.4.11 and 9.7.4, and MariaDB 11.4.13 and 12.3.3. The
  versions are exact — the harness refuses to run if the server reports anything else — and
  MySQL 8.0.36 is kept on purpose although its support ended in April 2026, because a customer's
  shared host may still be running it. It is path-filtered too, on
  `web/_sql/` and the harness itself, so the warning above applies to it as well
- `php-static-analysis.yml` — **a hard gate, not advisory**: `php -l` on every
  PHP file under `web/`, on **PHP 8.4 (the oldest supported) and PHP 8.5 (the recommended
  version)**, with no `continue-on-error`, so a syntax error turns the
  run red. It was listed as advisory here until 27 October 2026, which was true
  only while it also carried Psalm; Psalm was removed on 13 October and the
  lint is all that is left
- `lint.yml` — workflow files only, when one changes. `actionlint` (its embedded shellcheck tuned
  to `--severity=error`, so cosmetic lint never fails a build) **and** the expression-length gate
  described above. Neither step carries `continue-on-error`, so both are hard gates
- `repo-config-audit.yml` — weekly, and on a pull request that touches `.github/workflows/`. It
  looks for a rule that requires a status check by a name no workflow here produces, which
  soft-locks every later pull request into waiting for a check that never arrives. It fails the run
  when it finds one — but **without a repository secret `RULESET_AUDIT_TOKEN`** (a fine-grained
  token with `Administration: Read`) it cannot see the rules at all and degrades to a no-op summary
- `codeql.yml` — **advisory, on purpose.** It keeps a `continue-on-error` because GitHub's paid Code
  Scanning is not enabled here and the upload is refused without it; removing the flag would turn
  every run red for a billing reason. With Psalm gone, **no code-analysis check here can turn a run
  red** (#618)

dompdf is not kept in the repository. It is fetched at deploy time by
`tools/download-dompdf.sh`, at a pinned version, into `web/_libraries/dompdf/`, and uploaded with
the `_libraries` folder like any other code. `_auth_keys/`, `_uploads/` and `_backups/` belong to
the server: the deploy creates each one, empty, if it is missing, and after that never uploads to
it and never deletes from it.

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
