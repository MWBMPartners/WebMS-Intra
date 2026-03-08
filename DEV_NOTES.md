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

WebMS Intra uses a **single-branch deployment model**. Only the `web/` directory
is synced to the server via FTP.

| Environment | Branch / Trigger | What Deploys | URL |
| ----------- | --------------- | ------------ | --- |
| **Dev** | Every push to `main` | `web/` → server root | dev subdomain |
| **Production** | Tagged release (`v*`) | `web/` → server root | main domain |

### How It Works

```
commit ──► push to main ──► GitHub Actions ──► FTP sync web/ to server
                                                  (automatic, every push)

git tag v0.3.0 ──► push tag ──► GitHub Actions ──► FTP sync web/ to server
                                                      (production release)
```

Server-managed directories are **excluded** from sync:
`_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`

### Day-to-Day Workflow

1. Work directly on `main` (or short-lived feature branches merged into `main`)
2. Push to `main` -- dev site updates automatically
3. Test on the dev site (restricted to authorised users via Gatekeeper)
4. When ready for production, tag a release:

```bash
git tag v0.3.0
git push origin v0.3.0
```

5. GitHub Actions deploys the tagged code to the server

### Manual Deploy Override

The workflow also supports manual dispatch via the GitHub Actions UI:
**Actions > Deploy to DreamHost > Run workflow** -- choose `dev` or `live`.

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
| `full_schema.sql`                  | Consolidated schema for fresh installs (all tables + seeds) |

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
| `_auth_keys/` | Credentials and encryption keys (gitignored) |
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
