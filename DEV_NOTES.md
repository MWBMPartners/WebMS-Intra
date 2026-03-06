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
| `full_schema.sql` | Consolidated schema for fresh installs (all tables + seeds) |

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
