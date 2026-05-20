# Development Notes

> Living document -- kept up to date with tips, processes, and guidance for
> working on WebMS Intra.

---

## Repository vs Server Structure

The Git repository root contains documentation and CI/CD config. **All deployable
code lives inside `web/`**, which maps directly to the server domain directory:

```
Git repo root (NOT deployed)          Server: portal.millrdsdacambridge.uk/
├── .claude/                           ├── core/
├── .github/workflows/deploy.yml       ├── vendor/
├── CHANGELOG.md                       ├── sql/
├── DEV_NOTES.md                       ├── _auth_keys/    (server-managed)
├── README.md                          ├── _libraries/    (server-managed)
└── web/ ─── contents deployed ──────► ├── _uploads/      (server-managed)
    ├── core/                          ├── _backups/      (server-managed)
    ├── vendor/                        ├── _includes/
    ├── sql/                           ├── _functions/
    ├── _includes/                     ├── public_html/   (web root + apps)
    ├── _functions/                    ├── public_html_dev/
    ├── _libraries/ (gitignored)       ├── private_html/
    ├── public_html/                   ├── public_html_landing/
    │   ├── auth/                      └── public_html_redir/
    │   ├── dashboard/
    │   ├── expenses/
    │   ├── help/
    │   └── settings/
    ├── public_html_dev/
    └── ...
```

**Key rule:** when referencing paths in PHP code, use `PORTAL_ROOT` and related
constants (defined in `bootstrap.php`). When referencing paths in Git/CI, prefix
with `web/`.

---

## Deployment Model

WebMS Intra uses a **three-branch SFTP deployment model** modelled on the
iHymns pipeline. Only `web/` is synced; the active branch decides which
public web root the upload lands in.

| Branch  | Channel    | Public dir on server  | Auto-bump rule           |
| ------- | ---------- | --------------------- | ------------------------ |
| `alpha` | alpha/dev  | `public_html_dev/`    | PATCH (always)           |
| `beta`  | beta       | `public_html_beta/`   | Conventional Commits     |
| `main`  | production | `public_html/`        | none — tag `v*` manually |

### Remote layout (shared base)

All three branches share **one** remote base directory on DreamHost. Per
branch, `web/public_html/` mirrors to a different sibling; everything else
inside `web/` (`core/`, `vendor/`, `sql/`, `_includes/`, `_functions/`) goes
to the shared base from every branch — **last push wins for shared code**.

```text
SFTP_BASE_PATH/
├── core/                  ← from web/core/         (all branches)
├── vendor/                ← from web/vendor/       (all branches)
├── sql/                   ← from web/sql/          (all branches)
├── _auth_keys/            ← server-managed (excluded from sync)
├── _libraries/dompdf/     ← fetched at deploy time by tools/download-dompdf.sh
├── _uploads/              ← server-managed (excluded from sync)
├── _backups/              ← server-managed (excluded from sync)
├── public_html/           ← from web/public_html/  (main branch)
├── public_html_beta/      ← from web/public_html/  (beta branch)
└── public_html_dev/       ← from web/public_html/  (alpha branch)
```

### Workflows

- `deploy.yml` — push to alpha/beta/main, or manual dispatch. PHP-lint, fetch
  pinned dompdf, SFTP via lftp (SSH key first, password fallback).
- `version-bump.yml` — push to alpha or beta. Alpha always bumps PATCH; beta
  uses Conventional Commits (BREAKING/`!:` → major, `feat(` → minor, else patch).
- `changelog.yml` — push to alpha/beta/main. Appends per-branch sections to
  `CHANGELOG.md` from commit messages since the last `v*` tag.
- `release.yml` — push of any `v*` tag. Creates a GitHub Release from
  `CHANGELOG.md`; tags containing `-beta` or `-rc` are marked pre-release.
- `auto-merge-alpha.yml` — PR opened or synchronised against `alpha`. Enables
  GitHub native auto-merge and dispatches `deploy.yml` after merge. The
  bridge is required here because GitHub's *native* auto-merge IS attributed
  to `GITHUB_TOKEN`, which doesn't trigger downstream workflows. Manual UI
  merges on `beta` and `main` don't need a bridge — the `push:` event from
  a human-attributed merge fires normally.
- `pr-security.yml` — runs on every PR against alpha/beta/main. PHP lint
  (hard gate), gitleaks secrets scan, heuristic anti-pattern scan.
- `repo-config-audit.yml` — weekly + on PRs touching `.github/workflows/`.
  Detects orphaned required-status-check rules (see gotchas section).

### Day-to-Day Workflow

1. Branch off `alpha` for new work.
2. Open a PR against `alpha` → auto-merge fires once checks pass.
3. When `alpha` is stable, open a PR from `alpha` → `beta` for wider testing.
4. When `beta` is stable, open a PR from `beta` → `main` for production.
5. Tag a release on `main`:

```bash
git tag -a v0.9.0 -m "Release notes summary"
git push origin v0.9.0   # fires release.yml
```

### Manual Deploy Override

`Actions → Deploy via SFTP → Run workflow` accepts an override target
(`alpha` / `beta` / `main`) that bypasses the branch-based mapping for a
one-off deploy.

### Commit flags

- `[skip ci]` — skip every workflow on this commit
- `[deploy all]` — force a full re-sync regardless of change detection

---

## CI/CD Secrets Setup — Step-by-Step

Configure these once when bringing a fresh repo (or a new server) online.

### 1. Generate the SSH deploy keypair (preferred over password)

On your local machine:

```bash
ssh-keygen -t ed25519 -C "webms-intra-deploy@github" \
  -f ~/.ssh/webms_intra_deploy -N ''
```

Produces:

- `~/.ssh/webms_intra_deploy`     — private key (goes into GitHub secret `SFTP_KEY`)
- `~/.ssh/webms_intra_deploy.pub` — public key (goes onto the DreamHost server)

### 2. Authorise the public key on DreamHost

DreamHost panel → **Users → SFTP Users → [deploy user] → Manage Users**,
paste the contents of `~/.ssh/webms_intra_deploy.pub` into **Authorized Keys**.

Verify from your laptop:

```bash
ssh -i ~/.ssh/webms_intra_deploy -p 22 <SFTP_USER>@<SFTP_HOST> 'pwd; ls'
```

### 3. Set the GitHub repo secrets

| Secret           | Required | Example value                                                              |
| ---------------- | -------- | -------------------------------------------------------------------------- |
| `SFTP_HOST`      | yes      | `iad1-shared-XX-XX.dreamhost.com`                                          |
| `SFTP_USER`      | yes      | `dh_abcd1234`                                                              |
| `SFTP_LIVE_PATH` | yes      | `/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html`               |
| `SFTP_BETA_PATH` | yes      | `/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html_beta`          |
| `SFTP_DEV_PATH`  | yes      | `/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html_dev`           |
| `SFTP_PORT`      | no       | `22` (default if omitted)                                                  |
| `SFTP_KEY`       | one of   | full contents of `~/.ssh/webms_intra_deploy` (private key, preferred)      |
| `SFTP_PASSWORD`  | one of   | DreamHost SFTP password (fallback when `SFTP_KEY` is unset)                |

```bash
gh secret set SFTP_HOST      --body 'iad1-shared-XX-XX.dreamhost.com'
gh secret set SFTP_USER      --body 'dh_abcd1234'
gh secret set SFTP_LIVE_PATH --body '/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html'
gh secret set SFTP_BETA_PATH --body '/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html_beta'
gh secret set SFTP_DEV_PATH  --body '/home/dh_abcd1234/portal.millrdsdacambridge.uk/public_html_dev'
gh secret set SFTP_KEY       < ~/.ssh/webms_intra_deploy
# optional:
gh secret set SFTP_PORT      --body '22'
gh secret set SFTP_PASSWORD                       # prompts (avoids password in shell history)
```

**Shared-base note.** The shared `core/`, `vendor/`, `sql/` etc. upload to
`dirname()` of whichever per-branch path applies. When all three paths share
one parent (the default — recommended for the WebMS-Intra single-site setup),
all branches' shared code lands in the same place. Point them at different
parents if you want full isolation.

### 4. Enable the kill switch

```bash
gh variable set SFTP_ENABLED --body 'true'
```

While `SFTP_ENABLED != 'true'`, all deploy runs no-op.

### 5. Repo settings (one-time UI clicks)

1. **Settings → General → Pull Requests → Allow auto-merge** = ON.
2. **Settings → Branches → Add rule** on `alpha`, `beta`, and `main`:
   - Disable allow-deletions
   - Disable allow-force-pushes
   - (Recommended) Require status check on `main`

### 6. Verify

```bash
gh workflow run deploy.yml --ref main
gh run watch
```

### Rotating the SSH key

1. Generate a new keypair
2. Add the new public key on DreamHost (don't remove the old yet)
3. Update `SFTP_KEY` in GitHub
4. Trigger a manual deploy to confirm
5. Remove the old public key from DreamHost

---

## dompdf at deploy time

Expense PDF generation depends on dompdf in `_libraries/dompdf/`. The library
is **not** committed to this repo — `tools/download-dompdf.sh` fetches the
pinned version at deploy time and the lftp mirror uploads it as part of the
shared `web/` sync. Update the pinned version by editing `DOMPDF_VERSION` in
that script.

For local development:

```bash
bash tools/download-dompdf.sh
```

The script is idempotent — re-runs skip if the right version is already present.

---

## Branch protection & rulesets — gotchas

Two GitHub mechanisms can guard a branch in parallel: classic **branch
protection rules** (Settings → Branches) and the newer **rulesets**
(Settings → Rules → Rulesets). This repo currently uses **both**, which
is allowed but creates traps. Read this before adding or modifying any
required check.

### Required-check name format

When you add a required status check to a ruleset or branch protection,
the **context name** you enter must match the exact string GitHub records
on the check_run — which for GitHub Actions is the **job's `name:` field**
(or the job ID if no `name:` is set). It is **not** the prefixed
`Workflow Name / Job Name` form you see in the PR UI's checks list.

Example. Given this workflow:

```yaml
name: PR Security Checks    # workflow name
jobs:
  security:                  # job ID
    name: Static security checks   # job name — THIS is what to enter
```

The PR UI shows `PR Security Checks / Static security checks (pull_request)`.
But the required-check context to enter is just:

```text
Static security checks
```

If you enter the prefixed form, the rule waits forever for a check that
never arrives — the same orphan condition that bit PR #104.

### Orphans: required check names with no producing workflow

A required check that no workflow emits silently soft-locks every future
PR. Common causes:

- A workflow gets renamed and the rule isn't updated
- A required check is added in anticipation of a workflow that never ships
- A `name:` field is changed without thinking about the rule

**`.github/workflows/repo-config-audit.yml`** runs weekly and on PRs that
touch any workflow. It calls `tools/audit-required-checks.py`, which
cross-references every required check name against every workflow job
name in the repo. Orphans fail the audit and post a comment on the PR.

Run the audit locally:

```bash
python3 tools/audit-required-checks.py
```

Exits 0 on clean (or degraded mode), 1 on orphans, 2 on unexpected error.

### Optional: enabling the full audit in CI

The default `GITHUB_TOKEN` in workflow runs **cannot read rulesets or
branch protection** — the GitHub Actions permissions model has no
`administration: read` key. Without that, the CI audit runs in
**degraded mode** (it can still emit a useful summary based on
workflow-file inspection, but can't catch orphans).

To unlock the full CI audit, create a **fine-grained personal access
token** scoped to this repo with **Administration: Read** permission,
then store it as a repo secret named `RULESET_AUDIT_TOKEN`:

1. GitHub → your account → Settings → Developer settings → Personal
   access tokens → Fine-grained tokens → Generate new token
2. Repository access: select **only** `WebMS-Intra` (least privilege)
3. Repository permissions: **Administration: Read** (rest stay None)
4. Generate and copy the token
5. In the repo: Settings → Secrets and variables → Actions →
   New repository secret → name `RULESET_AUDIT_TOKEN`, value =
   the PAT

The workflow auto-detects the secret and uses it when present; absent
secret = degraded mode, no failure. Local `gh` runs are unaffected
since you're already authenticated as an admin.

### Branch protection + rulesets are additive

If a check is required by **either** source, the PR is blocked until it
passes. Removing a rule from branch protection does not remove a
duplicate copy in a ruleset. When debugging "why is this PR blocked?",
inspect both:

```bash
# Branch protection on a branch
gh api repos/MWBMPartners/WebMS-Intra/branches/main/protection

# All active rulesets
gh api repos/MWBMPartners/WebMS-Intra/rulesets
gh api repos/MWBMPartners/WebMS-Intra/rulesets/<id>
```

### Modifying a ruleset's required checks

`PUT /repos/.../rulesets/<id>` with the full ruleset body (after stripping
server-only fields like `id`, `created_at`, `updated_at`, `_links`).
Easier-but-slower: use the GitHub UI at Settings → Rules → Rulesets →
[ruleset] → Edit.

### Solo-dev branch protection profile

Set on `main`, `beta`, and `alpha` to disallow deletions and force-pushes
without requiring PR reviews you can't satisfy:

- Disallow allow_deletions, allow_force_pushes
- Do not enforce_admins (so you can bypass when needed)
- No required_pull_request_reviews (would block solo dev)
- Required linear history on `main` only (forces squash/rebase)
- Required status checks: `Static security checks` on `main`

---

## Dev Site Access Control

The dev site (`public_html_dev/`) is **not** protected by `.htaccess` basic
auth. Instead, it uses the portal's own authentication and authorisation
system via `Gatekeeper::enforce('dev')`.

### How Access Works

1. User visits the dev site
2. If not logged in, they are redirected to the login page (MS365 SSO or local)
3. After login, the Gatekeeper checks:
   - **Root Admins** (`isRootAdmin=1` in tblUsers) -- always allowed
   - **Admins** (`isAdmin=1` in tblUsers) -- always allowed
   - **Role-based** -- if the user's roles match `portal.devAccessRoles` setting
4. If denied, they see a 403 error page and the attempt is logged

### Managing Dev Access

To grant a non-admin user access to the dev site:

1. Go to **Settings** in the portal admin UI
2. Find or create the setting `portal.devAccessRoles`
3. Set the value to a comma-separated list of role keys, e.g.: `Developer,Tester`
4. Ensure the user has the matching role assigned in `tblUserRoles`

This approach is better than `.htaccess` because:

- Uses the same SSO login (no separate passwords to manage)
- Role-based (grant/revoke via DB, not file editing)
- Audit trail (denied access is logged via Logger)
- Consistent UX with the rest of the portal

---

## Environment Detection

The portal automatically detects which environment it is running in,
based on the `PORTAL_ENV` environment variable or the server's document
root directory name:

| Directory | PORTAL_ENV | Behaviour |
|-----------|-----------|-----------|
| `public_html/` | `prod` | Errors hidden, no debug panel |
| `public_html_dev/` | `dev` | Errors displayed, debug panel available |

You can override detection by setting the `PORTAL_ENV` environment variable
in your shell or hosting panel.

### Local Development

```bash
cd web
export PORTAL_ENV=dev
php -S localhost:8080 -t public_html
```

---

## Version Tagging Convention

Use [Semantic Versioning](https://semver.org/):

```
v{MAJOR}.{MINOR}.{PATCH}
```

- **MAJOR** -- breaking changes (e.g. DB schema changes requiring migration)
- **MINOR** -- new features, new app modules
- **PATCH** -- bug fixes, minor tweaks

Examples: `v0.1.0`, `v0.2.0`, `v1.0.0`

### Release Checklist

1. Ensure all changes are committed and pushed to `main`
2. Verify the dev site works correctly
3. Run pending SQL migrations on production (if any)
4. Tag the release:

```bash
git tag -a v0.3.0 -m "Directory restructure"
git push origin v0.3.0
```

5. Monitor the GitHub Actions deploy
6. Verify the production health check: `https://portal.millrdsdacambridge.uk/health`

---

## Coding Conventions

These are enforced across the codebase. Follow them in all new code.

- `declare(strict_types=1)` at the top of every PHP file
- Full IF notation: `if ($x === true)` not `if ($x)`
- Platform-neutral paths: use `DIRECTORY_SEPARATOR` instead of `/`
- Emoji-annotated comments for major code sections
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` for all output escaping
- No `<table>` tags for data display -- use `portal-data-list` component
- MySQLi prepared statements only -- never interpolate user input into SQL
- Use `Portal\Core\App::` methods over `global` keyword in new code

---

## SQL Migrations

Migrations live in `web/sql/` as numbered `.sql` files. They are executed via
the web-based Migrator (admin-only) and tracked in `tblMigrations`.

### Adding a New Migration

1. Create `web/sql/NNN_description.sql` (next sequential number)
2. Write idempotent SQL (use `IF NOT EXISTS`, `IF EXISTS` where appropriate)
3. Push to `main` -- it deploys to dev
4. Run the migration on dev via the admin migration runner
5. Test thoroughly
6. Before tagging a production release, run the migration on production

### Current Migrations

| File | Purpose |
|------|---------|
| `000_create_migrations_table.sql` | Migration tracking table |
| `001_create_tblErrors.sql` | Error logging |
| `002_create_expense_support_tables.sql` | Expense approvals + payments |
| `003_add_missing_settings.sql` | Required settings entries |
| `004_seed_routes.sql` | Initial route definitions |
| `006_local_auth_enhancement.sql` | Password resets, password policy settings, auth routes |
| `007_admin_routes.sql` | Admin section routes |
| `008_calendar_events_schema.sql` | Calendar / Events / Preaching Plan tables and seeds |
| `009_attendance_schema.sql` | Attendance service types, sessions, counts tables and seeds |
| `010_expenses_phase6.sql` | Expense multi-approver settings, file stage column, approver role column, view route |
| `011_auth_phase7.sql` | Linked accounts table, WebAuthn credentials table, Google/WebAuthn settings, account routes |
| `012_i18n_phase8.sql` | Adds locale column to tblUsers, i18n settings (defaultLocale, enabled) |
| `013_help_translations_route.sql` | Adds route for translations help page |
| `014_admin_integrations_route.sql` | Adds route for admin integration diagnostics page |
| `015_multisite.sql`                | Multi-site support: tblSites, tblUserSites, siteID columns, multisite settings/routes |
| `016_google_mail.sql`              | Google Workspace email settings: mail.provider, service account key, delegate user |
| `017_leadership.sql`               | Leadership app: roles, assignments tables, seed roles, routes, settings |
| `018_multisite_fixes.sql`          | Multi-site bug fixes: missing siteID on recurrence rules, open redirect prevention |
| `019_slug_uniqueness_multisite.sql` | Composite unique index on event slugs (slug + siteID) |
| `020_composite_indexes.sql`        | Composite indexes for multi-site query performance |
| `021_display_format_settings.sql`  | Configurable date/time display format settings |
| `022_expense_withdrawal.sql`       | Expense claim withdrawal feature, concurrent approval lock |
| `023_series_bulk_edit_route.sql`   | Event series bulk edit route |
| `024_csv_export_routes.sql`        | CSV export routes for expenses, attendance, leadership, admin |
| `025_install_upgrade_route.sql`    | Upgrade handler route for admin upgrade page |
| `026_notification_preferences.sql` | notifyPrefs JSON column, digest settings |
| `027_user_import_route.sql`        | User CSV import route |
| `028_event_rsvp.sql`               | tblEventRSVPs, capacity column on tblEvents, RSVP route |
| `029_announcements.sql`            | tblAnnouncements, announcement routes and app settings |
| `030_document_library.sql`         | tblDocCategories, tblDocuments, document routes and settings |
| `031_audit_trail.sql`              | tblAuditTrail for before/after change tracking |
| `032_totp_2fa.sql`                 | TOTP columns on tblUsers, tblTotpBackupCodes, 2FA routes |
| `033_reports.sql`                  | Reports/analytics dashboard routes |
| `034_workflow_engine.sql`          | tblWorkflows, Steps, Instances, Actions tables |
| `035_api_expansion.sql`            | REST API routes for events, attendance, users, announcements |
| `036_tasks_reminders.sql`          | tblTasks with recurrence, task routes and app settings |
| `full_schema.sql`                  | Consolidated schema for fresh installs (covers 000–036) |

---

## File Structure Quick Reference

All paths below are relative to `web/` (the deployable root):

| Path | Purpose |
|------|---------|
| `core/` | Framework classes (`Portal\Core` namespace) |
| `core/templates/` | Shared page templates (header, footer, nav, errors) |
| `vendor/simplejwt/` | Vendored RS256 JWT verifier (no Composer) |
| `sql/` | Numbered SQL migration files |
| `public_html/` | Production web root (front controller, assets, app controllers) |
| `public_html/{app}/` | App controllers (e.g. `expenses/`, `auth/`, `dashboard/`) |
| `public_html_dev/` | Dev web root (Gatekeeper-protected) |
| `install/` | Installation wizard and upgrade handler |
| `_auth_keys/` | Credentials and encryption keys (gitignored, created by installer) |
| `_uploads/` | User file uploads (gitignored) |
| `_backups/` | Server backups (gitignored) |
| `_libraries/` | Self-hosted libs e.g. dompdf (gitignored) |
| `_includes/` | Shared includes (future) |
| `_functions/` | Shared functions (future) |

---

## Adding a New App Module

1. Create directory: `web/public_html/{appname}/index.php`
2. Add route to `tblRoutes` (or create a migration)
3. In the app file, set page metadata and include templates:

```php
<?php
declare(strict_types=1);

use Portal\Core\Auth;

$pageTitle   = 'My App';
$pageSection = 'myapp';
$breadcrumbs = ['Dashboard' => '/', 'My App' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- App content here -->

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
```

4. If the app needs a settings-based enable flag, add `myapp.enabled = true`
   to `tblSettings`
5. The nav will pick it up automatically if configured in the template

---

## Translations (i18n)

The portal supports multiple languages via the `I18n` framework (`core/I18n.php`).
All user-facing text is stored in **language files** under `web/lang/`, one file
per locale. English (`en.php`) is the baseline — every other language file only
needs to include the keys it translates; missing keys fall back to English automatically.

### How It Works (The Big Picture)

```
User visits page
  → I18n checks: user DB preference → session → browser Accept-Language → default
  → Loads web/lang/{locale}.php (e.g. lang/fr.php)
  → t('auth.sign_in') returns "Se connecter" instead of "Sign In"
  → Missing keys fall back to English automatically
```

### Language File Format

Each language file is a PHP file that returns a flat associative array.
Keys use **dot-notation** for logical grouping (e.g. `nav.dashboard`, `auth.sign_in`).

```php
<?php
// File: web/lang/fr.php
declare(strict_types=1);

return [
    'nav.dashboard'    => 'Tableau de bord',
    'nav.sign_in'      => 'Se connecter',
    'auth.sign_in'     => 'Se connecter',
    'auth.password'    => 'Mot de passe',
    // ... only include keys you want to translate
    // anything missing falls back to English
];
```

### Key Naming Convention

Keys follow the pattern `{section}.{description}` using lowercase and underscores:

| Prefix         | Section                      | Example                                    |
| -------------- | ---------------------------- | ------------------------------------------ |
| `nav.`         | Navigation bar               | `nav.dashboard`, `nav.sign_out`            |
| `auth.`        | Login, password, account     | `auth.sign_in`, `auth.forgot_password`     |
| `dashboard.`   | Dashboard page               | `dashboard.welcome`                        |
| `expenses.`    | Expense claims               | `expenses.submit_title`                    |
| `calendar.`    | Calendar / Events            | `calendar.all_categories`                  |
| `attendance.`  | Attendance tracker           | `attendance.record_title`                  |
| `admin.`       | Admin panel                  | `admin.user_management`                    |
| `settings.`    | Settings page                | `settings.add_setting`                     |
| `help.`        | Help centre                  | `help.title`                               |
| `error.`       | Error pages (403/404/500)    | `error.page_not_found`                     |
| `common.`      | Shared UI elements           | `common.save`, `common.cancel`             |
| `email.`       | Email templates              | `email.greeting`                           |
| `format.`      | Date/number/currency formats | `format.date.short`                        |

### Step-by-Step: Adding a New Language

1. **Copy the English baseline** as a starting point:
   ```bash
   cp web/lang/en.php web/lang/fr.php
   ```

2. **Edit the file header** — update the language name and flag emoji:
   ```php
   /**
    * French (fr) Translation File 🇫🇷
    */
   ```

3. **Translate each string value** (the part after `=>`). Do NOT change the keys
   (the part before `=>`):
   ```php
   // ✅ Correct — only change the value
   'nav.dashboard' => 'Tableau de bord',

   // ❌ Wrong — never change the key
   'nav.tableau_de_bord' => 'Tableau de bord',
   ```

4. **Remove keys you haven't translated yet** — they'll fall back to English
   automatically. This is better than leaving English text in a French file.

5. **Check the locale is registered** in `core/I18n.php` in the `$locales` array.
   All 13 currently supported locales are already registered:
   `en, cy, fr, de, es, pt, ar, he, fa, ur, zh, ja, ko`

6. **Test it** — visit any page and add `?lang=fr` to the URL, or use the
   language switcher dropdown in the navigation bar.

### Step-by-Step: Translating a String

When you see a string you want to translate:

1. **Find the key** — search `web/lang/en.php` for the English text:
   ```bash
   grep -n "Sign In" web/lang/en.php
   ```
   Result: `'auth.sign_in' => 'Sign In',`

2. **Add the key to your language file** with the translated value:
   ```php
   'auth.sign_in' => 'Se connecter',
   ```

3. **Save and test** — the change is live immediately (no build step needed).

### Parameterised Strings

Some strings include dynamic values using `:param` syntax:

```php
// English
'auth.too_many_attempts' => 'Too many attempts. Try again in :minutes minute(s).',

// French
'auth.too_many_attempts' => 'Trop de tentatives. Réessayez dans :minutes minute(s).',
```

The `:minutes` placeholder is replaced at runtime. Keep the `:param` names exactly
as they are in the English file — only translate the surrounding text.

### Pluralisation

Strings that change based on a count use `|` as a separator:

```php
// Two forms: singular | plural
'expenses.claim_count' => 'One claim|:count claims',

// Three forms: zero | one | many
'items.count' => 'No items|One item|:count items',
```

French example:

```php
'expenses.claim_count' => 'Une réclamation|:count réclamations',
'items.count' => 'Aucun élément|Un élément|:count éléments',
```

### RTL (Right-to-Left) Languages

RTL locales (Arabic, Hebrew, Farsi, Urdu) are handled automatically:

- The `<html>` tag gets `dir="rtl"`
- Bootstrap loads its RTL CSS variant
- Portal CSS applies margin/text-alignment overrides

No special action is needed when translating — just provide the translated text
and the framework handles the layout direction.

### Using Translations in PHP Code

In any PHP file loaded after bootstrap:

```php
// Simple translation
echo t('nav.dashboard');  // "Dashboard" or translated equivalent

// With parameters
echo t('auth.too_many_attempts', ['minutes' => 5]);

// With pluralisation
echo t('items.count', ['count' => 3]);

// Always escape for HTML output
echo htmlspecialchars(t('auth.sign_in'), ENT_QUOTES, 'UTF-8');
```

### Language Switcher

Users change their language via the globe dropdown in the navigation bar.
When a user switches language:

1. A `?lang=fr` query parameter is sent
2. The preference is stored in their session
3. If logged in, it's also saved to `tblUsers.locale` in the database
4. On next login, their preference is loaded from the database automatically

### Admin Settings

Two settings control i18n behaviour (in the portal Settings page):

| Setting Key          | Purpose                                               | Default |
| -------------------- | ----------------------------------------------------- | ------- |
| `i18n.defaultLocale` | The default language for users who haven't chosen one | `en`    |
| `i18n.enabled`       | Whether the i18n system is active                     | `true`  |

### Translation Review / Approval Workflow

There is no built-in approval UI — translations are managed as code:

1. **Translator** creates or edits `web/lang/{locale}.php`
2. **Developer** reviews the changes via Git pull request or code review
3. **Merge to `main`** — translations deploy to dev automatically
4. **Test on dev** — verify strings appear correctly in context
5. **Tag a release** — translations deploy to production

This keeps translations version-controlled, reviewable, and auditable.

---

## New Core Classes (v0.8.1)

### Container (`core/Container.php`)

Lightweight dependency injection container that works alongside the existing static
`App` registry. Supports singleton and factory bindings with lazy resolution:

```php
$container = new Container();
$container->singleton('mailer', fn() => new Mailer($config));
$mailer = $container->get('mailer'); // same instance each time
```

Use `Container` for new service wiring; existing `App::db()`, `App::settings()` etc.
remain unchanged for backward compatibility.

### ApiRouter (`core/ApiRouter.php`)

Dedicated API route dispatcher, extracted from the main `Router` class. Handles
all `api/{app}/{action}` patterns with JSON content-type enforcement, CORS headers,
and standardised error envelopes via `ApiResponse`. The main `Router::dispatch()`
delegates to `ApiRouter` for any path starting with `api/`.

### CsvExporter (`core/CsvExporter.php`)

Generic CSV export helper used across five apps: expenses, attendance, leadership,
admin users, and activity logs. Accepts a column definition array and a MySQLi result
set, streams output with proper headers (`Content-Type: text/csv`,
`Content-Disposition: attachment`), and escapes fields to prevent formula injection.

### Validator (`core/Validator.php`)

Input validation framework using pipe-separated rule syntax:

```php
$v = new Validator($_POST, [
    'email'  => 'required|email|max:255',
    'amount' => 'required|numeric|min:0.01',
    'date'   => 'required|date',
]);
if ($v->fails()) {
    $errors = $v->errors(); // ['email' => ['The email field is required.']]
}
```

Built-in rules: `required`, `email`, `numeric`, `integer`, `min`, `max`,
`date`, `in`, `regex`, `string`, `boolean`. Custom rules can be added via closures.

### Transaction Helpers

`App::beginTransaction()`, `App::commit()`, and `App::rollback()` wrap MySQLi
transaction methods for cleaner multi-statement operations:

```php
App::beginTransaction();
try {
    // multiple inserts/updates
    App::commit();
} catch (\Throwable $e) {
    App::rollback();
    throw $e;
}
```

---

## Error Handling Standardisation (v0.8.1)

All CSRF validation failures and OAuth errors now follow a consistent
**flash + redirect** pattern instead of mixed approaches (some pages used
`die()`, others rendered inline errors, others returned JSON):

- **CSRF failures** — set a flash error message in `$_SESSION['flash']` and
  redirect back to the originating form. The header template renders flash
  messages automatically.
- **OAuth errors** — capture error details, flash a user-friendly message,
  and redirect to the login page. Technical details are logged via `Logger`.
- **No remaining `die()` or bare `exit()` calls** — all early-termination
  paths use flash+redirect or `ApiResponse::error()` (for API endpoints).

This was tracked in Issue #82.

---

## Troubleshooting

### "CSRF" error on form submission

The CSRF token has expired or was already used (tokens rotate after use).
Reload the form page to get a fresh token.

### Changes not appearing on dev site

Check GitHub Actions for deploy failures. Common causes:

- PHP lint error (syntax issue blocks deploy)
- FTP credentials expired (check DH_HOST/DH_USER/DH_PASS secrets)

### 403 on dev site after login

Your user account lacks dev access. Either:

- Set `isAdmin=1` on your user record in `tblUsers`, or
- Add your role to `portal.devAccessRoles` in Settings

### Debug panel not showing

Append `?debug=true` to the URL. Only visible to admin users.
Check that `isAdmin=1` or `isRootAdmin=1` on your user record.

---

Last updated: March 2026
