# Development Notes

> Living document -- kept up to date with tips, processes, and guidance for
> working on WebMS Intra.

---

## Repository vs Server Structure

The Git repository root contains documentation and CI/CD config. **All deployable
code lives inside `web/`**, which maps directly to the server domain directory:

```
Git repo root (NOT deployed)          Server: portal.millrdsdacambridge.uk/
├── .claude/                           ├── _core/
├── .github/workflows/deploy.yml       ├── _vendor/
├── CHANGELOG.md                       ├── _sql/
├── DEV_NOTES.md                       ├── _auth_keys/    (server-managed)
├── README.md                          ├── _libraries/    (server-managed)
└── web/ ─── contents deployed ──────► ├── _uploads/      (server-managed)
    ├── _core/                         ├── _apps/         (app controllers, #159)
    ├── _apps/        (#159)           ├── _backups/      (server-managed)
    ├── _vendor/                       ├── _includes/
    ├── _sql/                          ├── _functions/
    ├── _lang/                         ├── public_html/   (front controller + static)
    ├── _install/                      ├── public_html_dev/   (alpha branch deploy)
    ├── _includes/                     ├── public_html_beta/  (beta branch deploy)
    ├── _functions/                    ├── private_html/
    ├── _libraries/ (gitignored)       ├── public_html_landing/
    ├── public_html/                   └── public_html_redir/
    │   ├── auth/
    │   ├── dashboard/
    │   ├── expenses/
    │   ├── help/
    │   └── settings/
    ├── private_html/
    ├── public_html_landing/
    └── public_html_redir/
```

The `_` prefix on every server-side dir is a naming convention: any dir
that starts with `_` is **above Apache's DocumentRoot** and cannot be
directly accessed via HTTP. Only `public_html/` (and its per-branch
siblings on the server) are web-accessible.

The repo holds **one** `public_html/` source tree. Branch-based deploy mirrors it to the appropriate server-side destination — `alpha` → `public_html_dev/`, `beta` → `public_html_beta/`, `main` → `public_html/`. There is no per-channel front controller in the repo.

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
inside `web/` (`_core/`, `_vendor/`, `_sql/`, `_lang/`, `_install/`,
`_includes/`, `_functions/`) goes to the shared base from every branch —
**last push wins for shared code**.

```text
SFTP_BASE_PATH/
├── _core/                 ← from web/_core/         (all branches)
├── _apps/                 ← from web/_apps/         (all branches, #159)
├── _vendor/               ← from web/_vendor/       (all branches)
├── _sql/                  ← from web/_sql/          (all branches)
├── _lang/                 ← from web/_lang/         (all branches)
├── _install/              ← from web/_install/      (all branches)
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
- `changelog.yml` — push to alpha or beta only (NOT main — the ruleset
  on main blocks the bot's direct push). Appends per-branch sections to
  `CHANGELOG.md` from commit messages since the last `v*` tag. Entries
  propagate to main via the normal beta → main merge.
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

**Shared-base note.** The shared `_core/`, `_vendor/`, `_sql/` etc. upload to
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

## Per-site branding flow

The portal supports per-site visual branding via `tblSites` columns. Site
admins can override the brand colour, logo and favicon per-site without
touching code.

### Data model

`tblSites` columns relevant to branding (see migration `037_site_favicon.sql`):

| Column         | Type         | Purpose                                          |
| -------------- | ------------ | ------------------------------------------------ |
| `siteName`     | VARCHAR(255) | Display name used in nav, page titles, footer    |
| `logoPath`     | VARCHAR(500) | Header logo URL/path (any image format)          |
| `faviconPath`  | VARCHAR(500) | Browser-tab favicon URL/path; NULL = default     |
| `primaryColor` | VARCHAR(7)   | `#RRGGBB` hex; default `#5e6ad2` (Linear indigo) |
| `copyrightOrg` | VARCHAR(255) | Footer copyright holder                          |

### How a value flows from DB to UI

1. `Site::loadCurrentSite()` selects the row into a class-level cache.
2. `Site::branding('color' | 'logo' | 'favicon' | 'name' | …)` returns the
   relevant column.
3. `web/_core/templates/header.php` reads `Site::branding('color')`,
   derives `--portal-primary-rgb` (R,G,B) from the hex, and **inline-
   styles** the `<html>` element:

   ```html
   <html data-bs-theme="light"
         style="--portal-primary: #5e6ad2; --portal-primary-rgb: 94, 106, 210;">
   ```

4. `web/public_html/assets/css/portal.css` defines the design tokens
   inside `:root`. Because the `<html>` inline style has higher
   specificity than `:root`, the per-site primary wins. The derived
   variants (`--portal-primary-hover`, `--portal-primary-active`,
   `--portal-primary-subtle`) are auto-derived from the primary via
   `color-mix()` and shift along with it on any browser that supports
   color-mix (Chrome 111+, Safari 16.2+, Firefox 113+). On older
   browsers, the literal indigo hex fallbacks defined in `:root` apply.
5. `header.php` also renders `<link rel="icon">` from
   `Site::branding('favicon')`, with `/assets/images/favicon.ico` as the
   fallback.

### Admin UI

Umbrella admins manage all sites at **`/admin/sites/`**. The "New / Edit
site" modal has form fields for `siteName`, `siteKey`, `hostPattern`,
`logoPath`, `faviconPath`, `primaryColor` (color picker), `copyrightOrg`,
`timezone`, and active status.

The save handler at `web/public_html/admin/sites/save.php` validates the
primary colour as `#RGB` or `#RRGGBB` and falls back to the indigo default
on invalid input.

### Defaults for white-label deploys

`tblSites` ships with the global "WebMS Intra" defaults. New sites
inherit `#5e6ad2` until the admin sets their own brand colour. Logo and
favicon default to `/assets/images/logo.svg` and
`/assets/images/favicon.ico` respectively.

### "Powered by &lt;product&gt;" footer attribution

When a site uses CUSTOM branding (any branding field differs from the
ACTIVE product brand — see "Two-layer brand model" below), the footer
renders a small "Powered by &lt;product&gt;" attribution after the
copyright line, where `<product>` resolves via `Site::productName()`.
Sites running the active product brand defaults don't show it — the
copyright line already names the product.

Detection (`Site::usesCustomBranding()` in `web/_core/Site.php`):

- `siteName` differs from `Site::productName()` (active product name
  resolved from `product.name` setting / `PORTAL_PRODUCT_NAME_DEFAULT`
  constant / `Site::DEFAULT_SITE_NAME` cold-start fallback), OR
- `logoPath` differs from `Site::DEFAULT_LOGO_PATH`
  (`'/assets/images/logo.svg'`), OR
- `primaryColor` differs from `Site::DEFAULT_PRIMARY_COLOR`
  (`'#5e6ad2'`, compared case-insensitively), OR
- `copyrightOrg` is non-empty (default is NULL), OR
- `faviconPath` is non-empty and differs from the default favicon path

Admins disable attribution globally via the `branding.hidePoweredBy`
setting in `/settings/` (set to the string `'true'`). Default is
`'false'`, so attribution is on out-of-the-box for custom-branded
deploys.

Markup lives in `web/_core/templates/footer.php`; styling is in the
`.portal-powered-by`, `.portal-powered-by-prefix`, and
`.portal-powered-by-mark` rules in `portal.css`. The mark class is a
hook for future hyperlinking when the product landing page exists.

The same detection ALSO drives a `<meta name="generator" content="<product>">`
tag in `web/_core/templates/header.php`. This is the standard SaaS / CMS
attribution mechanism — invisible to humans, picked up by site analysers
like Wappalyzer + "View page source" + browser dev tools.

---

## Two-layer brand model (issue #296)

There are TWO independent branding layers in the portal. They compose
top-down at render time.

```text
┌─ Layer 1 — PRODUCT (system) ────────────────────────────────────┐
│   Set ONCE at install via the installer's Step 1.5 picker.       │
│   Lives in tblSettings (siteID=NULL) as product.* + portal.industry. │
│   Drives: <meta name="generator">, footer "Powered by …",        │
│   X-Powered-By header, PWA manifest name/description,            │
│   installer wizard heading + footer.                             │
└──────────────────────────────────────────────────────────────────┘
                       ▼ overridden by ▼
┌─ Layer 2 — TENANT (per-site, already shipped) ──────────────────┐
│   Set per-site via /admin/sites/<id>/branding.                   │
│   Lives in tblSites columns + `branding.*` settings.             │
│   Drives: page chrome (siteName, logo, colour, favicon),         │
│   copyright org, "Powered by …" visibility opt-out.              │
└──────────────────────────────────────────────────────────────────┘
```

**Resolution rule everywhere**: tenant override > product default >
hardcoded constant.

### Why two layers

Tenants already had `branding.*` (Linear-style per-org skin). Adding a
SECOND layer above lets the same codebase ship as different sub-brands
without forking — `WebMS Intra` for the generic install, `ChurchMS` for
church installs, future `SchoolMS` / `CharityMS` etc. for other verticals.
Tenant branding stays decoupled; an install branded as `ChurchMS` can
still be deployed for `Mill Road SDA Cambridge` and the latter wins in
the chrome.

### Product brand presets

Defined in `web/_core/brand-defaults.php` as a `return [...]` array keyed
by `portal.industry` value (`generic`, `church`, `school`, `nonprofit`,
`community`, `small-business`). Each preset declares `name`, `tagline`,
`publisher`, `assetFolder`, and a human `displayLabel` for the installer
dropdown.

The file is **bootstrap-free** — it cannot reference any class, function,
or constant from elsewhere — so the installer (which runs before the
framework loads) can `require` it the same way the runtime does.

### Resolution helpers

| Helper | Reads from | Used by |
| --- | --- | --- |
| `Site::productName()` | `App::settings('product.name')` → `PORTAL_PRODUCT_NAME_DEFAULT` → `DEFAULT_SITE_NAME` | header meta, footer mark, X-Powered-By, manifest |
| `Site::productTagline()` | `App::settings('product.tagline')` → `PORTAL_PRODUCT_TAGLINE_DEFAULT` | manifest description, installer subtitle |
| `Site::productPublisher()` | `App::settings('product.publisher')` → `PORTAL_PRODUCT_PUBLISHER_DEFAULT` | footer copyright |

The `PORTAL_PRODUCT_*_DEFAULT` constants are seeded in `bootstrap.php`
from `brand-defaults.php`'s generic preset, so they're already valid
strings before `$SETTINGS` is loaded.

### Picking the brand at install time

The installer wizard's **Step 1.5 — Organisation Type** (encoded as the
string `'1.5'` in URLs, alongside the existing `'2.5'` data-choice page)
shows a dropdown of preset display labels. The chosen industry key is
stored in `$_SESSION['install_industry']`; all subsequent steps display
the matching brand. After `full_schema.sql` runs, Step 3 INSERTs the
preset's `name` / `tagline` / `publisher` values plus the industry key
into `tblSettings`.

### Changing brand post-install

The brand is fully reversible: admins can edit `portal.industry`,
`product.name`, `product.tagline`, or `product.publisher` via
`/admin/settings`. Changing `portal.industry` does **not** auto-rewrite
the other rows — the admin may have customised them. Re-seeding is a
manual SQL exercise if a full preset reset is wanted; documented as a
v1.x follow-up.

### Per-brand assets

Per-brand asset folders live at
`web/public_html/assets/images/brands/<assetFolder>/{logo,icon-192,icon-512}.svg`.
The brand-aware `manifest.php` controller resolves the active preset's
`assetFolder` and serves icons from there; it falls back to the existing
`/assets/images/{logo,icon-192,icon-512}.svg` placeholders if the per-brand
file isn't present yet. v1 ships placeholder copies for `generic` and
`church`; designers replace with distinct artwork in a follow-up PR
without touching code.

### Where the brand is NOT applied

By design, these surfaces stay as `WebMS Intra` regardless of preset:

- PHP `@package WebMS Intra` / `@author MWBM Partners Ltd` doc-tags —
  these document code authorship, not user-facing branding.
- `error_log('[WebMS-Intra] …')` server-log prefixes — codebase identity
  for operators reading logs, not user-facing brand.
- `robots.txt` — comment header is brand-neutral so the static file
  can be served without going through a PHP controller.
- `openapi.json` `info.title` — developer-facing surface; brand-aware
  conversion deferred to a v1.x follow-up (see below).

### Deferred follow-ups from the brand-layer PR (#297)

Tracked as separate issues; called out here so they don't get lost
between PRs.

1. **Distinct sub-brand artwork** — the `assets/images/brands/<type>/`
   folders currently contain placeholder copies of the generic SVGs
   so the `manifest.php` resolver finds something. Designers replace
   the artwork in a follow-up without touching code; the controller
   discovers new files at next render. ChurchMS gets the first
   distinct logo pass; school / charity / community / small-business
   stay placeholders until those presets need to ship.

2. **`openapi.json` brand-aware conversion** — `info.title`,
   `info.contact.name`, and `info.contact.url` are still hardcoded to
   `WebMS Intra REST API` / `MWBM Partners Ltd …` regardless of the
   active brand. Pattern would mirror `manifest.json` → `manifest.php`:
   move the static spec to `web/_core/api-spec.json`, add
   `web/public_html/openapi.php` that loads it and rewrites the
   `info` block before emitting, route via tblRoutes. Deferred because
   the OpenAPI surface is developer-facing (Swagger UI viewers,
   integrators) and the same brand value reads cleanly in both
   contexts.

3. **`prayerRequests.*` → `prayer-requests.*` setting-key naming
   standardisation** — drift dating to the original prayer-requests
   app (PR #129). Every other app uses kebab-case slugs as setting
   prefixes (`prayer-requests` is the directory name, `tblRoutes`
   key, app slug). The setting key is camelCase. A migration would
   rename the rows in `tblSettings` AND update the three handlers
   that read `App::settings('prayerRequests.*')`. Mechanical work;
   only deferred because it touches a wide-blast-radius app and
   isn't urgent enough to bundle into this PR.

Each is filed as its own GitHub issue with `for consideration` label
so the per-item decision happens later. Search the issue tracker for
"deferred from #297" to find them.

---

## Theme modes + colour-blind palette

The portal and the standalone installer support three theme modes and an
opt-in colour-blind safe palette. Both are user-level preferences stored in
`localStorage` (per-device, per-browser).

### Theme modes

- **Light** — light surfaces, dark text (the design's default visual)
- **Dark** — dark surfaces, light text (`[data-bs-theme="dark"]` overrides)
- **Auto** — follows the OS `prefers-color-scheme` and live-updates if the
  system flips

Click the half-stroke circle icon in the navbar to cycle through the modes.
The icon updates to indicate the active preference (sun = light, moon =
dark, half-stroke = auto). Persisted as `localStorage.portal-theme` =
`light` / `dark` / `auto`. Missing key defaults to `auto`.

The same control exists in the standalone installer at `/install/` (top-
right of the page). Its tokens mirror `portal.css` and are kept in sync
manually.

### Colour-blind safe palette

Opt-in toggle (`localStorage.portal-cb` = `on` / unset). When enabled,
the eye icon in the navbar shows as active and the semantic colours
(success, danger, warning, accent) shift to a palette from Wong (Nature
Methods, 2011) that's distinguishable for deutan + protan colour
blindness (~95 % of CB cases):

- `--portal-success`: default `#16a34a` (green) → CB `#009e73` (bluish-green); dark CB `#5dd1a8`
- `--portal-danger`: default `#dc2626` (red) → CB `#d55e00` (vermillion); dark CB `#ff8a4d`
- `--portal-warning`: default `#d97706` (amber) → CB `#e69f00` (orange); dark CB `#ffc04d`
- `--portal-accent`: default `#06b6d4` (cyan) → CB `#56b4e9` (sky blue); dark CB `#7fc6f0`

Primary stays untouched — it's the site's identity colour and is
user/site-set (see "Per-site branding flow" above).

**Accessibility note:** CB-safe tokens reduce the risk of mis-reading
status colours, but **colour alone should never be the only signal**.
Components that convey state (badges, alerts, validation messages)
should also use icons or text labels. The PR template's security
checklist already mentions this for new UI work.

### Dyslexia-friendly reading mode (#46)

A third opt-in toggle (`localStorage.portal-read` = `on` / unset), applied
as `<html data-portal-read="on">` and wired identically to the CB toggle
(FOUC script → nav button → `portal.js` `initReadToggle()`). When enabled,
`[data-portal-read="on"]` in `portal.css` swaps the body font to a clean
system sans-serif stack (`Verdana, Tahoma, "Trebuchet MS", …` — no web font
is fetched, so it stays CSP-safe and adds no network cost) and applies wider
letter/word spacing, ~1.6–1.7 line height and left-aligned (never justified)
body text. These choices follow the **British Dyslexia Association Style
Guide (2023)**. It composes with the theme and CB toggles — all three can be
on at once. The nav button uses the `fa-book-open-reader` icon; the help
page `/help/getting-started` documents it for end users.

### Flow

```text
localStorage  ──FOUC script──▶  <html data-bs-theme="..." data-portal-cb="..." data-portal-read="...">
                                       │
                                       ▼
                              portal.css token / rule overrides
                                       │
                                       ▼
                              all components inherit
```

The FOUC script runs synchronously in `<head>` before first paint, so
the chosen theme + CB mode are applied with no flash. portal.js (and
the installer's inline JS) then wire up the toggle buttons and listen
for `prefers-color-scheme` changes when in `auto` mode.

### Where to find the code

- `web/public_html/assets/css/portal.css` — token blocks for light,
  dark, CB-safe (and dark + CB-safe combined)
- `web/_core/templates/header.php` — inline FOUC script reading
  localStorage and applying the attrs
- `web/_core/templates/nav.php` — theme + CB toggle buttons
- `web/public_html/assets/js/portal.js` — `initThemeToggle()` (cycles
  light → dark → auto), `initCbToggle()` (on/off), `initReadToggle()` (on/off)
- `web/_install/index.php` — installer mirrors the theme + CB controls inline
  (it's standalone, can't load portal.css/portal.js; the reading-mode toggle
  is portal-only for now)

---

## Workflow: `auto-merge-alpha.yml` — verified-correct-but-unfired (#147)

**Last verification:** 2026-06-19 (originally 2026-06-03) · **Status:** unfired (still 0 runs since creation)

**2026-06-19 re-audit**: `gh run list --workflow auto-merge-alpha.yml --limit 20` returns `[]`. `gh pr list --base alpha --state all --limit 20` returns `[]`. No PR has ever targeted the `alpha` branch; the workflow has never executed. Decision below stands — retained, not deleted. Re-audit every ~6 months; if alpha branch usage stays at zero for the next audit window, revisit deletion.

The workflow at `.github/workflows/auto-merge-alpha.yml` calls
`gh pr merge --auto --squash` on any PR whose `base` is `alpha`, then waits
for the merge and dispatches `deploy.yml` so the alpha environment updates
(the dispatch is required because GitHub's anti-recursion rule suppresses
push events attributed to `GITHUB_TOKEN`).

It has **zero runs** to date because **no PR has been opened against `alpha`**
in the entire history of the repo — every PR (#280 - #286 reviewed) has
targeted `main` directly. The workflow is structurally correct (trigger,
permissions, command syntax all match GitHub's auto-merge contract), it
simply has not had the opportunity to execute.

### Re-verification procedure when alpha is actually exercised

```bash
git checkout alpha
git checkout -b test-auto-merge-$(date +%s)
echo "" >> README.md  # trivial no-op
git commit -am "test: auto-merge smoke"
git push -u origin HEAD
gh pr create --base alpha --title "test: auto-merge smoke" --body "verifying #147"
# Wait ≤30s for "Auto-Merge Alpha PRs" run to appear in Actions tab.
# PR should show the green "auto-merge" badge.
# Once required checks pass, GitHub auto-merges and dispatches deploy.yml.
gh pr view --json autoMergeRequest,mergeStateStatus
```

### Decision

Workflow retained, **not deleted**. Cost of keeping it = zero (it
only runs when triggered). Cost of deleting = re-authoring the
trigger/permissions/dispatch logic if the team starts using alpha.
Delete only if alpha branch usage stays at zero for the next 6 months.

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

Migrations live in `web/_sql/` as numbered `.sql` files. They are executed via
the web-based Migrator (admin-only) and tracked in `tblMigrations`.

### Adding a New Migration

1. Create `web/_sql/NNN_description.sql` (next sequential number)
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

## Portable DDL convention (MySQL 8.0 ∩ MariaDB)

**Supported engines:** MySQL 8.0+ (production target, DreamHost) and MariaDB 10.4+
(compatible). MySQL-wire-compatible managed/cloud databases — AWS RDS MySQL 8,
Aurora MySQL 3.x, Azure Database for MySQL (Flexible Server 8.0), GCP Cloud SQL for
MySQL — are covered for free by MySQL-8 compatibility, since they accept the same
DDL and reject the same MariaDB-only extensions. PostgreSQL, SQL Server, and
Vitess-based platforms (PlanetScale/TiDB/SingleStore — limited FOREIGN KEY support)
are explicitly **not supported**; the entire data layer is mysqli (no PDO
abstraction), so supporting them would be a platform port, not a SQL tweak.

**Rule: never use `IF [NOT] EXISTS` on `ADD`/`DROP COLUMN`, `ADD`/`CREATE`/`DROP
INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN`.** That clause is a MariaDB-only DDL
extension — MySQL 8.0 (all point releases, plus 8.4/9.x) rejects it with a parse
error, **ERROR 1064**, which aborts the whole statement/file under
`mysqli::multi_query` (the installer and Migrator both use it). `CREATE TABLE IF
NOT EXISTS` and `DROP TABLE IF EXISTS` are standard MySQL and remain fine to use
as-is.

Instead, guard every DDL object with an `information_schema` existence check and a
dynamic `PREPARE`/`EXECUTE`. This is the house idiom, already shipped and
production-proven under `mysqli::multi_query` in `web/_sql/037_site_favicon.sql`
(ADD COLUMN), `web/_sql/112_events_calendar_easy_wins.sql` (DROP INDEX), and
`web/_sql/138_worship_present_state.sql` (ADD UNIQUE KEY — its own comment notes
"some MySQL builds reject IF NOT EXISTS"). Conventions:

- One guard block per DDL object (per column / per index / per FK) — never batch a
  multi-column ALTER behind a single sentinel guard. `multi_query` aborts mid-file
  on connection loss, and per-object guards make a re-run self-healing.
- Keep the literal DDL textually contiguous inside the quoted string (don't split
  `` ALTER TABLE `tblX` ADD COLUMN `colY` `` across concatenation) —
  `tools/audit-checks/check_sql_columns.py` builds its column inventory from
  raw-text regexes that match inside string literals across newlines.
- Escape single quotes inside the literal by doubling (`''`), as in COMMENT clauses.
- Use `SELECT 1` as the no-op branch.

### Templates

**ADD COLUMN** — guard on `information_schema.COLUMNS`:

```sql
-- ➕ tblFoo.barColumn — guarded ADD COLUMN (portable: MySQL 8.0 + MariaDB 10.x)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND COLUMN_NAME  = 'barColumn'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `tblFoo` ADD COLUMN `barColumn` VARCHAR(64) DEFAULT NULL COMMENT ''What it is (#NNN)'' AFTER `bazColumn`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**ADD INDEX / CREATE INDEX** — guard on `information_schema.STATISTICS`:

```sql
-- 🔍 idx_foo_bar — guarded ADD INDEX
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND INDEX_NAME   = 'idx_foo_bar'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `tblFoo` ADD INDEX `idx_foo_bar` (`barColumn`, `bazColumn`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

Composite indexes produce one `STATISTICS` row per column, so `COUNT(*) = 0` / `> 0`
still works. For a unique index use `ADD UNIQUE KEY \`uq_…\` (…)` in the literal.
Standardise on `ALTER TABLE … ADD` rather than `CREATE INDEX` for consistency; both
are prepare-able.

**DROP INDEX** — same `STATISTICS` guard, inverted:

```sql
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND INDEX_NAME   = 'uq_old_index'
);
SET @sql := IF(@idx_exists > 0,
    'ALTER TABLE `tblFoo` DROP INDEX `uq_old_index`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**DROP COLUMN** — `COLUMNS` guard, inverted:

```sql
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND COLUMN_NAME  = 'obsoleteColumn'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `tblFoo` DROP COLUMN `obsoleteColumn`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**MODIFY / CHANGE COLUMN:**

- `MODIFY COLUMN` to a fixed target definition is naturally re-runnable (the same
  MODIFY twice succeeds) — no guard needed unless the migration must be conditional
  on the *current* type, in which case guard on
  `information_schema.COLUMNS.COLUMN_TYPE`/`DATA_TYPE`.
- `CHANGE COLUMN` (rename) is NOT re-runnable — the old name is gone on the second
  run — so guard on the **old** name:

```sql
SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblFoo'
      AND COLUMN_NAME  = 'oldName'
);
SET @sql := IF(@old_exists > 0,
    'ALTER TABLE `tblFoo` CHANGE COLUMN `oldName` `newName` VARCHAR(64) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**ADD FOREIGN KEY** — guard on `information_schema.TABLE_CONSTRAINTS`:

```sql
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'tblFoo'
      AND CONSTRAINT_NAME   = 'fk_foo_bar'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `tblFoo` ADD CONSTRAINT `fk_foo_bar` FOREIGN KEY (`barID`) REFERENCES `tblBar`(`barID`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

### `full_schema.sql` fold pattern: an ALTER's FK target is created LATER in the file

`full_schema.sql` contains **zero ALTER statements** — every migration's DDL folds
inline into the relevant `CREATE TABLE` block so a fresh install reproduces the
end-state of replaying every numbered migration. That's straightforward for a new
COLUMN or plain KEY index (no cross-table dependency), but breaks down for a FK
whose *referenced* table is created **further down** the file than the table being
altered — MySQL raises **errno 1824** ("Failed to open the referenced table") for a
forward-referencing FK inside a `CREATE TABLE`, and `full_schema.sql` has no
`FOREIGN_KEY_CHECKS` toggle to paper over it.

**First occurrence: migration 179 (#436)** — `tblEvents` (created at the very top of
`full_schema.sql`, alongside the earliest core tables) gained nullable
`venueID`/`roomID` columns whose FKs point at `tblVenues`/`tblVenueRooms`
(`web/_sql/170_venue_bookings.sql`), created thousands of lines later. `tblEvents`
itself can't move — dozens of later tables FK it — so the fold splits in two:

- The **columns + their plain KEY indexes** fold inline into `tblEvents`' `CREATE
  TABLE`, exactly like any other additive column (no cross-table dependency).
- The **FK constraints are deliberately left OUT of `full_schema.sql` entirely** —
  a comment at both the altered table's fold point and the referenced table's
  section explains why and points at the migration. The installer replays every
  numbered migration **after** loading `full_schema.sql` (see "SQL dialect trap" in
  `.claude/CLAUDE.md`), so migration 179's own guarded `ADD CONSTRAINT` blocks add
  both FKs on that replay — by the time they run, `tblVenues`/`tblVenueRooms`
  already exist (either from a real historical migration 170 run, or from
  `full_schema.sql`'s own `CREATE TABLE` for them earlier in the same install). End
  state is identical whether the install was upgraded from a live migration 170+179
  history or built fresh from `full_schema.sql` + migration replay.
- `check_schema_seed_parity.py` only compares migration filenames/settings
  keys/route keys (not FK presence), so this split creates no parity finding; the
  `179_event_venue_link.sql` filename still needs its own `tblMigrations` seed row
  in `full_schema.sql`'s seed block, same as any other migration.

Reach for this split pattern whenever a later migration ALTERs a table that sits
*before* its FK target in `full_schema.sql`'s creation order — it is NOT limited to
`tblEvents`/venues, just the first time the codebase needed it.

Note: this checks constraint *name* existence only — a same-named FK with a
different definition is silently accepted (the same tolerance the old
`IF NOT EXISTS` forms had for columns). Acceptable, but worth knowing.

### PREPARE-ability caveat (MySQL 8.0 manual — "SQL Syntax Permitted in Prepared Statements")

Prepare-able (everything the templates above need): `ALTER TABLE`, `CREATE INDEX`,
`DROP INDEX`, `CREATE TABLE`, `DROP TABLE`, `RENAME TABLE`, DML, `SET`, `SHOW`.

**NOT prepare-able** — do not try to wrap these in the guard idiom:
`CREATE`/`DROP TRIGGER`, `CREATE`/`ALTER`/`DROP PROCEDURE`/`FUNCTION`/`EVENT`,
`ALTER VIEW`, `LOCK TABLES`. If a future migration needs a *conditional*
trigger/procedure, the condition has to move to PHP (Migrator/installer side) — the
SQL-file guard idiom cannot express it. MariaDB's prepare-able set is a superset of
MySQL's, so the intersection constraint is exactly the MySQL list above.

### Replayability rule

`web/_install/index.php` loads `full_schema.sql` and then **replays every numbered
migration file in order**, deliberately ignoring `tblMigrations` (see the comment
above the replay loop) — this is how a stale partial install catches up to the
latest schema. That means **every migration must be a replayable no-op** on an
up-to-date schema:

- Every DDL statement needs an `information_schema` guard (per the templates
  above) — a bare `ADD COLUMN`/`ADD INDEX`/`ADD CONSTRAINT`/`DROP INDEX` fails with
  1060/1061/1826/1091 the moment it re-runs against a schema that already has the
  object.
- Every seed `INSERT` (including a migration's own `INSERT INTO tblMigrations
  (filename) VALUES (...)` self-record — `filename` is `UNIQUE KEY
  uq_filename`) needs `ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, or a `WHERE NOT
  EXISTS` guard, or it fails with ERROR 1062 on replay.

---

## File Structure Quick Reference

All paths below are relative to `web/` (the deployable root):

| Path | Purpose |
|------|---------|
| `_core/` | Framework classes (`Portal\Core` namespace) |
| `_core/templates/` | Shared page templates (header, footer, nav, errors) |
| `_vendor/simplejwt/` | Vendored RS256 JWT verifier (no Composer) |
| `_sql/` | Numbered SQL migration files |
| `_lang/` | I18n translation files (en.php, cy.php, …) |
| `_install/` | Installation wizard and upgrade handler |
| `public_html/` | The single web-root source; branch-based deploy maps this to `public_html/` (main), `public_html_dev/` (alpha) or `public_html_beta/` (beta) on the server |
| `public_html/{app}/` | App controllers (e.g. `expenses/`, `auth/`, `dashboard/`) |
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

The portal supports multiple languages via the `I18n` framework (`_core/I18n.php`).
All user-facing text is stored in **language files** under `web/_lang/`, one file
per locale. English (`en.php`) is the baseline — every other language file only
needs to include the keys it translates; missing keys fall back to English automatically.

### How It Works (The Big Picture)

```
User visits page
  → I18n checks: user DB preference → session → browser Accept-Language → default
  → Loads web/_lang/{locale}.php (e.g. lang/fr.php)
  → t('auth.sign_in') returns "Se connecter" instead of "Sign In"
  → Missing keys fall back to English automatically
```

### Language File Format

Each language file is a PHP file that returns a flat associative array.
Keys use **dot-notation** for logical grouping (e.g. `nav.dashboard`, `auth.sign_in`).

```php
<?php
// File: web/_lang/fr.php
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
   cp web/_lang/en.php web/_lang/fr.php
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

5. **Check the locale is registered** in `_core/I18n.php` in the `$locales` array.
   All 13 currently supported locales are already registered:
   `en, cy, fr, de, es, pt, ar, he, fa, ur, zh, ja, ko`

6. **Test it** — visit any page and add `?lang=fr` to the URL, or use the
   language switcher dropdown in the navigation bar.

### Step-by-Step: Translating a String

When you see a string you want to translate:

1. **Find the key** — search `web/_lang/en.php` for the English text:
   ```bash
   grep -n "Sign In" web/_lang/en.php
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

1. **Translator** creates or edits `web/_lang/{locale}.php`
2. **Developer** reviews the changes via Git pull request or code review
3. **Merge to `main`** — translations deploy to dev automatically
4. **Test on dev** — verify strings appear correctly in context
5. **Tag a release** — translations deploy to production

This keeps translations version-controlled, reviewable, and auditable.

---

## New Core Classes (v0.8.1)

### Container (`_core/Container.php`)

Lightweight dependency injection container that works alongside the existing static
`App` registry. Supports singleton and factory bindings with lazy resolution:

```php
$container = new Container();
$container->singleton('mailer', fn() => new Mailer($config));
$mailer = $container->get('mailer'); // same instance each time
```

Use `Container` for new service wiring; existing `App::db()`, `App::settings()` etc.
remain unchanged for backward compatibility.

### ApiRouter (`_core/ApiRouter.php`)

Dedicated API route dispatcher, extracted from the main `Router` class. Handles
all `api/{app}/{action}` patterns with JSON content-type enforcement, CORS headers,
and standardised error envelopes via `ApiResponse`. The main `Router::dispatch()`
delegates to `ApiRouter` for any path starting with `api/`.

### CsvExporter (`_core/CsvExporter.php`)

Generic CSV export helper used across five apps: expenses, attendance, leadership,
admin users, and activity logs. Accepts a column definition array and a MySQLi result
set, streams output with proper headers (`Content-Type: text/csv`,
`Content-Disposition: attachment`), and escapes fields to prevent formula injection.

### Validator (`_core/Validator.php`)

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

## Password policy & strength validation (#53 / PR #132)

`Auth::validatePassword()` and `Auth::passwordPolicy()` are the canonical
helpers — every password-set flow goes through them (reset, account
change-password, admin user create / update, and the standalone installer
which carries a self-contained copy).

**Settings (all `auth.password.*`):**

| Key | Default | Notes |
| --- | --- | --- |
| `minLength` | `12` | Bumped from 8 in migration 041 (OWASP ASVS L1) |
| `maxLength` | `128` | Defence against pathological inputs; bcrypt truncates at 72 anyway |
| `requireUppercase` | `true` | Independent of lowercase since #132 |
| `requireLowercase` | `true` | New flag — previously implicit |
| `requireNumber` | `true` | |
| `requireSpecial` | `true` | Any non-alphanumeric |

`Auth::passwordPolicy()` returns the active policy as a structured array
(rules list + min/max + required flags) so password forms can render
hints consistently. Forms also wire up the JS strength meter via the
`data-portal-password-input` + `data-portal-password-meter` attributes —
`portal.js` attaches the meter on every matching input; the installer
ships an inline copy because it loads before `bootstrap.php`.

**5-step score** mirrors the server policy:

- +1 length ≥ minLength
- +1 contains lowercase
- +1 contains uppercase
- +1 contains digit
- +1 contains symbol

Bands: 0-1 Very weak (red), 2 Weak (red), 3 Fair (warning), 4 Strong
(info), 5 Very strong (success).

---

## Multi-provider Captcha (#130)

`Portal\Core\Captcha` accepts three providers — Cloudflare Turnstile,
Google reCAPTCHA (v2 checkbox or v3 invisible-score), and hCaptcha — and
picks the active one based on an admin-configurable priority list.

**Settings:**

- `auth.captcha.priority` — comma-separated provider keys
  (default `turnstile,recaptcha,hcaptcha`); the first one with **both**
  site + secret keys configured wins.
- `auth.turnstile.{siteKey,secretKey}`
- `auth.recaptcha.{siteKey,secretKey,version}` (version = `v2` or `v3`)
- `auth.recaptcha.v3.{action,threshold}` (default action `submit`,
  threshold `0.5`)
- `auth.hcaptcha.{siteKey,secretKey}`

**Public API (unchanged contract from previous Captcha class):**

```php
Captcha::scriptTag()          // <script> tag(s) for the active provider
Captcha::widget()             // widget markup (or invisible hidden input for v3)
Captcha::verify($_POST)       // server-side verification
Captcha::isConfigured()       // true if at least one provider is wired up
Captcha::activeProvider()     // 'turnstile' | 'recaptcha' | 'hcaptcha' | ''
Captcha::listProviders()      // for the admin UI
Captcha::normalisePriority()  // for the admin save handler
```

**Admin UI** lives at `/admin/captcha` — SortableJS-powered drag-and-drop
priority list + per-provider key inputs + v2/v3 toggle + action / score
threshold inputs for v3.

**reCAPTCHA v3 verification** enforces **both** action match (anti-replay)
and score threshold; rejections are logged via `Logger::activity()` as
`CaptchaRejected` so probing surfaces in the activity log.

---

## Debug mode hardening (#54 / PR #133)

`Debug::isEnabled()` and `App::isDebug()` both refuse to enable debug
mode when `PORTAL_ENV === 'prod'`, regardless of admin status or query
params. Defence-in-depth:

1. `Debug::isEnabled()` — returns false in prod; `Debug::renderPanel()`
   is already gated on it. Attempts in prod are logged once per request
   as `DebugBlocked` activity (IP + path).
2. `App::isDebug()` — same prod refusal. The global exception handler
   in `bootstrap.php` already routes detailed traces through
   `App::isDebug()`, so stack traces / file paths can never leak in
   prod even on unhandled exceptions.
3. `bootstrap.php` — forces `display_errors`, `display_startup_errors`,
   and `html_errors` to `'0'` in prod. `error_reporting(E_ALL)` stays
   on so `Logger::phpError()` continues to capture everything.

---

## Anchor / link colour theme binding (PR #135)

`portal.css` binds `--portal-link` (and its hover / RGB variants) to
Bootstrap's `--bs-link-color`, `--bs-link-color-rgb`,
`--bs-link-hover-color`, and `--bs-link-hover-color-rgb` in both the
light `:root` and `[data-bs-theme="dark"]` blocks. Without these
bindings, every plain `<a>` / `.btn-link` / `.alert-link` / `.link-*`
falls back to the browser-default blue, which clashes hard in dark mode.

`_install/index.php` mirrors the same binding in its self-contained
inline `<style>` block because the installer doesn't load `portal.css`.

Per-site branding still flows through: `--portal-link` resolves to
`--portal-primary`, which `Site::branding()` overrides on
`<html style="--portal-primary: …">`, so anchor colour follows the
site's primary colour automatically.

---

## Calendar view modes (#136 / PRs #137 #138)

`web/public_html/calendar/index.php` is a thin **view router**. It:

1. Validates `?view=` against the whitelist
   (`day | week | weekdays | weekend | month | year | list`).
2. Resolves a visible date range from `?date=YYYY-MM-DD`
   (or `YYYY-MM`, `YYYY`, falling back to today on parse failure).
3. Fetches every event overlapping that range in **one** query
   (no per-cell N+1).
4. Delegates rendering to a per-view partial under `views/`.

**View partials (under `web/public_html/calendar/views/`):**

- `_shared_header.php` — date navigation (◀ Today ▶ + date picker),
  view switcher, filter row.
- `_day_columns.php` — **one** hour-timeline renderer reused by day /
  week / weekdays / weekend, parametrised by column count. Events
  position absolutely by start time and clip to each column's
  `[00:00, 24:00]` window. All-day events strip above the timeline.
- `day.php`, `week.php`, `weekdays.php`, `weekend.php` — thin wrappers
  around `_day_columns.php` with their own day list.
- `month.php` — 7-column 5/6-row calendar grid; up to 3 event pills
  per cell + "+ N more" link to day view.
- `year.php` — 12-month wall planner. 24-column grid (12 months ×
  day-number + content sub-columns), 31 day rows, blank cells where
  months are shorter. Multi-day event bands repeat on every covered
  day so they read as continuous strips. Auto-built legend with
  category swatches at the top.
- `list.php` — the original chronological card grid.

**Settings:** `calendar.defaultView` (default `month`).
**Per-user:** `localStorage['portal-calendar-view']` remembers the
last-used view across visits; URL `?view=` always wins.

**Category styling (#138):** `tblEventCategories.color` (hex,
regex-validated server-side) drives event background tints AND a
left-border accent. `tblEventCategories.displayStyle` toggles between
`'background'` (tinted band — default) and `'text'` (coloured text
on default background — used for Bank Holidays / Notable Days that
should flag a day rather than fill it).

**Per-month strap-lines (#138):** `tblCalendarMonthThemes` stores one
text line per `(siteID, year, month)`. Rendered as an italicised
strap-line under each month name on the year planner. Managed via
`/calendar/manage/month-themes` (year picker + 12 inputs; empty
values delete the row).

**Security:** all colour values are hex-validated by regex
(`/^#[0-9a-fA-F]{3,8}$/`) **before** persistence **and**
`htmlspecialchars`-escaped on output, blocking CSS injection via
crafted category colours.

---

## Prayer Requests (#129)

Self-contained app at `/prayer-requests/`. Single table
(`tblPrayerRequests`) with status lifecycle
(`pending → active → answered → archived`) and visibility flag
(`leadership | congregation`).

Anonymous public submission lives at `/prayer-requests/anonymous` —
no login required — and is gated by:

1. **CSRF** — session token issued by the GET form, verified on POST.
2. **Captcha** — whichever provider is active per the
   `auth.captcha.priority` setting (see above).
3. **RateLimiter** — same per-IP limiter used by the login form.
4. Hard-coded to `visibility = leadership` and `status = pending`
   (anonymous submissions never broadcast directly to the congregation).
5. Always redirects to the same generic success page so abusers can't
   fingerprint success vs failure.

Logged-in submitters get a "display as Anonymous" toggle — members
see "Anonymous", but the moderation queue still shows the real
submitter for pastoral follow-up.

---

## Asset Tracker (assets)

Top-level app at `/assets`, slug `assets`, model class
`Portal\Core\AssetRegister` (`_core/AssetRegister.php`), 13-table schema
(`tblAsset*`) shipped whole in migration 159 (#393/#395), with the
register/ownership/loans/maintenance/identifiers/licences/labels/lost-
and-found UI built out across #394-#402, and docs + a register CSV
export closing out Phase 1 (#403).

### Two meanings of "asset" — do not conflate

This codebase uses the word "asset" for two **completely unrelated**
things:

1. **`Portal\Core\Asset`** (`_core/Asset.php`) + `public_html/assets/` —
   web **infrastructure**. The `Asset` class builds CDN-with-fallback
   `<link>`/`<script>` tags (Bootstrap, Font Awesome) with SRI hashes;
   `public_html/assets/` is the static webroot directory (CSS, JS,
   images, fonts, vendor bundles, per-brand artwork). Nothing here is
   ever stored in the database.
2. **The Asset Tracker app** (`_apps/assets/`, slug `assets`, model
   `Portal\Core\AssetRegister`, tables `tblAsset*`) — a completely
   separate domain concept: the organisation's own physical/digital
   inventory (laptops, chairs, vehicles, software licences), with
   ownership, loans, maintenance, and a public lost-and-found page.

The two never share code, tables, or routes — but they DO share a
routing collision that needed an explicit fix (see below), so keep the
distinction in mind before assuming a grep hit for "asset" means the
other one.

### `.htaccess` — the bare `/assets` route rewrite

The physical static directory `public_html/assets/` and the Asset
Tracker's bare route `/assets` collide at the URL level. Apache's
existing "skip rewriting for an existing directory" rule
(`RewriteCond %{REQUEST_FILENAME} !-d`) would resolve a bare `/assets`
request to that static directory (a listing/404, depending on server
config) rather than routing it to the front controller, so the Asset
Tracker's own register page would never be reachable. `public_html/
.htaccess` carries a small rule ahead of the static-file/-directory
checks that intercepts ONLY the exact bare path (`^assets/?$`, with or
without a trailing slash) and sends it to `index.php` before those
checks run:

```apache
RewriteRule ^assets/?$ index.php [QSA,L]
```

Deep links like `/assets/item` or `/assets/edit` are untouched — they
don't match `^assets/?$` and fall through to the normal rewrite below
unaffected, since there's no colliding `assets/item/` directory on
disk.

### Register CSV export — no dedicated route

The register's CSV export (#403) is a query-string switch
(`?export=csv`) on the existing `_apps/assets/index.php` handler rather
than a new `assets/export` route — it reuses the exact same
`AssetRegister::listForSite()` call (and therefore the exact same
manager-gated confidential filter) the HTML view already makes, so the
two can never drift apart on what a given viewer is allowed to see.
The export column list is an explicit allow-list; `licenseKey` and
`publicToken` are never selected for it.

### Phase 3 (#411-#415) — stocktake, depreciation history, kits, kiosk mode, GS1 Digital Link/GEPIR

Five sub-issues, schema shipped whole in migration 161 (+ one small
migration 162 ENUM/route addition for the kiosk pass) — the same
"foundation migration first, logic in later passes" shape Phase 1 used.
The non-obvious bits a future dev needs, one per sub-issue:

**Kiosk security model (#414).** A kiosk terminal is a shared,
unattended, PUBLIC device — `assets/kiosk` and `assets/kiosk-action` are
seeded `isProtected = 0` and NEVER call `Auth::requireLogin()`. Identity
lives in TWO layers, both distinct from a normal portal login:

- `tblAssetKioskTokens` — the per-DEVICE credential (`?token=` in the
  URL), minted by an admin, shown once at mint time, never re-readable.
  `resolveKioskTerminal()` re-validates the `^[a-f0-9]{32}$` shape itself
  and treats "unknown" and "revoked" (`isActive = 0`) identically (no
  oracle) — same convention as `/a/{token}`.
- `tblAssetKioskPins` — the per-USER credential (a short PIN a member
  sets for themselves at `/assets/kiosk-pin`, a normal session-gated
  page). `kiosk.php`/`kiosk-action.php` NEVER set `$_SESSION['user_id']`
  — that would grant the shared device a real portal login. Instead they
  use their own `kiosk_token_id`/`kiosk_site_id`/`kiosk_user_id`/
  `kiosk_user_name`/`kiosk_expires` session keys, cleared on terminal
  switch or idle-timeout (server-enforced on every load, independent of
  the `assets.kiosk_auto_checkout` client-side countdown display).

Every kiosk check-in/out audits as entityType `'loan'` (it IS a loan
event) with the new `actorType: 'kiosk'` value +
`AssetRegister::audit()`'s `$actorUserIdOverride` parameter — the only
way to attribute a row to a real user with no session of their own. This
lets an auditor filter `actorType = 'kiosk'` to see every action taken on
an unattended device, separately from a logged-in `'user'` action.
`kioskCheckout()`/`kioskCheckin()` both reject a confidential asset with
the exact same message as a wrong-status one (no oracle) and never run
`cascadeKitCheckout()` — a kiosk hand-over is always a single asset.

**Reducing-balance depreciation — no `depreciationRate` column (#412).**
`tblAssets` has no stored percentage; `computeReducingBalanceValue()`
derives a CONSTANT yearly percentage once, from `costPence`,
`salvageValuePence`, and `usefulLifeYears`:

```
rate = 1 - (salvageValuePence / costPence) ^ (1 / usefulLifeYears)
```

then applies that same fixed rate compounding year-over-year from the
purchase date to today. Requires a POSITIVE `salvageValuePence` (unlike
straight-line, which doesn't) — an asset missing that input, or not
`depreciationMethod = 'reducing-balance'`, simply renders nothing on
item.php rather than guessing. `tblAssetValueHistory` (one row per
`(asset, valueDate)`, `uq_astvh_asset_date`) is the snapshot table the
`#405` cron writes to on a write-on-change-only basis — item.php's Value
History panel is pure read-only display over that table, never itself a
compute path.

**Kit design — one level deep, cascade only at checkout (#413).** A kit
is `tblAssets.parentAssetID` (column existed since migration 159,
unused until this pass). `attachKitChild()` enforces, in order: both
assets exist on this site; child ≠ parent; the PARENT is not itself
someone else's component (no two-level nesting); the CHILD doesn't
already have components of its own (same reason); the child has no
EXISTING parent (detach first); the child has no open loan. A kit's
components only ever move together at the LOAN boundary —
`cascadeKitCheckout()`/`cascadeKitCheckin()` fire ONLY for a top-level
loan (`parentLoanID IS NULL`) on an asset that currently has components,
and `cascadeKitCheckin()` additionally prompts for a PER-COMPONENT return
condition (a kit's individual pieces can come back in different states
even though they went out together).

**Stocktake `verifyStatus` lifecycle (#411).** `tblAssetStocktakes`
(`status`: `open`→`closed`) owns a run; `tblAssetStocktakeItems`
(`uq_aststi_stocktake_asset` — one row per asset per run, a re-scan is an
UPDATE not a duplicate) tracks each asset through:

- `pending` — expected (pre-populated from the run's location/category
  scope at open time), not yet scanned.
- `present` — scanned, at its recorded location.
- `moved` — scanned, but at a DIFFERENT location (`foundLocationID`
  records where).
- `unexpected` — scanned during this run but wasn't in the original
  expected set.
- `missing` — closed out with the run (never scanned) — the ONLY status
  a closed run can still show as unresolved.

**GS1 Digital Link resolver + GEPIR config gating (#415, the FINAL Asset
Tracker Phase 3 pass).** `Router::handleSpecialRoutes()` gained a THIRD
prefix-matched block (after `e/{slug}` and `a/{token}`) recognising the
three bare GS1 Digital Link path shapes Asset Tracker identifiers can
carry — `01/{gtin}` (GTIN, optionally `/21/{serial}`), `8003/{grai}`
(GRAI), `8004/{giai}` (GIAI) — each anchored-regex-matched and NOT a
`tblRoutes` row, requiring `_apps/assets/dl.php` directly exactly like
the `a/{token}` block requires `tag.php`. `dl.php` gates on
`assets.digital_link_enabled` (a GLOBAL/host-site-merged setting read via
`App::settings()`, since the resolver is deliberately cross-site — there
is no session-selected site to scope a per-site override to), then calls
`AssetRegister::resolveDigitalLink()`, which is ALSO deliberately global
(no `Site::id()` filter — GS1 keys are globally unique) but excludes a
confidential asset in the SQL `WHERE` clause itself (never a post-filter)
so there is no code path that could ever hand back a confidential
asset's token; an ambiguous match (>1 asset sharing a key) resolves to
null identically to "not found". A hit is a plain 302 redirect to the
asset's EXISTING `/a/{token}` public page — `dl.php` makes no further
access decision of its own, `tag.php` owns everything past that point.

GEPIR (Global Electronic Party Information Registry) verify is a
SEPARATE, manager-only, session-gated action (`identifier-verify.php` →
`AssetRegister::verifyIdentifier()`) — nothing to do with the public
resolver above beyond sharing the word "GS1". It ALWAYS runs the local
GS1 mod-10 check-digit first (`validateIdentifier()`, unchanged from
Phase 1) and ONLY attempts an outbound GEPIR HTTP lookup when BOTH
`assets.gepir_verify_enabled === 'true'` AND `assets.gepir_endpoint` is a
non-empty `https://` URL — both off/empty by default (migration 161), so
a fresh install never makes an outbound call until an admin explicitly
configures a real endpoint. The outbound call itself
(`AssetRegister::gepirLookup()`, private) is defensive-by-default: 5s
timeout, TLS verification always on, no redirects followed, response
size capped both via a `Range` request header and a hard `substr()`
after retrieval. ANY GEPIR failure — feature off, no endpoint, network/
timeout/parse error — falls back to the local check-digit result rather
than failing the verify action; `isVerified`/`verifiedAt`/`verifyNote`
(migration 161 columns on `tblAssetIdentifiers`) are the verify cache,
and every verify audits as entityType `'identifier'`, action `'update'`
(mirrors into the platform's generic audit trail like any other
identifier edit).

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

Append `?debug=true` to the URL. Only visible to admin users in **non-prod**
environments. Debug mode is unconditionally refused when `PORTAL_ENV=prod`
(any attempt is logged as a `DebugBlocked` activity entry). See issue #54.

### A file disappeared from the server after deploy

Almost certainly because **it isn't in the `web/` tree in the repo**. The
deploy workflow mirrors with `--delete` on the shared dirs, so any file
present on the server but absent from `web/` will be removed on the next
push.

Affected (mirrored) trees on the server:

```text
<base>/_core/
<base>/_vendor/
<base>/_sql/
<base>/_includes/
<base>/_functions/
<base>/_libraries/
```

What survives:

- `<base>/_auth_keys/` — server-only (credentials, encryption key)
- `<base>/_uploads/` — user uploads
- `<base>/_backups/` — server-managed snapshots

If you need a quick patch on the server during an incident, **commit + push**
instead of SCP'ing the file — manual edits to mirrored dirs vanish on the
next deploy. If a library or vendored asset must live on the server but
not in the repo, add it to `WEB_ROOT_EXCLUDES` in `.github/workflows/deploy.yml`.

Use the workflow_dispatch **dry-run** input on `deploy.yml` to preview what
a deploy would change (and crucially, what it would delete) before pushing.
See issue #107 for the full rationale and mitigation list.

## End-to-end migration test (#248)

Before each release, run every migration through a real MySQL 8.0.36
container to catch what the static `check_sql_columns.py` and `check_migration_idempotency.py` audits miss:

- Statement order — a later migration assuming a table a previous one
  forgot to create.
- FK constraints that pass static parsing but blow up at runtime on
  real data shapes.
- Genuine non-idempotency that the static audit can't model (e.g. a
  trigger that errors second-time-round).

### Procedure

Requires `docker` + `docker compose` on your local machine.

```bash
tools/e2e-migrations/run.sh                # all three phases (~30s)
tools/e2e-migrations/run.sh --skip-stale   # phases 1+2 only
tools/e2e-migrations/run.sh --keep         # leave container up for poking
```

The script drives three phases:
1. **Fresh install** — apply every `web/_sql/NNN_*.sql` in order to an
   empty DB. Any SQL error fails the run.
2. **Idempotency** — re-run the same loop. Schema row counts
   (information_schema.tables/columns/statistics) must be unchanged.
3. **Stale-DB upgrade** — wipe, apply first half, then apply the rest.
   Catch-up must reach the same final state as fresh install.

See `tools/e2e-migrations/README.md` for details.

### Static idempotency audit

`tools/audit-checks/check_migration_idempotency.py` is the fast
first-pass. Flags `CREATE TABLE` without `IF NOT EXISTS`, `ADD COLUMN`
without `IF NOT EXISTS`, and `INSERT` without `ON DUPLICATE KEY UPDATE`
or `INSERT IGNORE`. Quote-aware splitter — `;` inside string literals
and comments doesn't fragment the parse.

Some old migrations (014-018, 037, 043) flag because they used pre-multi-site
patterns without the `IF NOT EXISTS` clause. These have already run in
production and the Migrator wrapper skips already-applied files, so
they're safe. **New migrations must pass cleanly** — drop a non-idempotent
DDL in a fresh migration and the audit will catch it.

## Adding a new CDN dependency (#161)

Every `<script>` and `<link>` tag pointing at a third-party CDN MUST carry
an `integrity="sha384-…"` attribute and `crossorigin="anonymous"`. Without
SRI, a compromise of the CDN serves arbitrary JS/CSS to every visitor
simultaneously — and SRI is the only client-side mitigation.

The `tools/audit-checks/check_cdn_sri.py` script scans every PHP / HTML
file under `web/` and flags any CDN tag without an `integrity=` attribute.
It runs in CI and locally — drop a tag without SRI and the check fails.

### Procedure

1. **Pin the version.** SRI requires exact byte matching, so `@latest`
   and unpinned major versions (`bootstrap@5`) will break the check on
   every release. Use an exact patch version (`bootstrap@5.3.3`).

2. **Generate the hash:**
   ```bash
   curl -sL https://cdn.jsdelivr.net/npm/<package>@<version>/<file> \
     | openssl dgst -sha384 -binary \
     | openssl base64 -A
   ```
   Prefix the output with `sha384-`.

3. **Add to `web/_core/Asset.php`:**
   - Add a `*_VERSION` constant (one source of truth for bumps).
   - Add a `CDN_*` URL constant building from the version.
   - Add a `*_INTEGRITY` hash constant from the curl/openssl pipeline.
   - Add a helper method (`Asset::sortableJs()` style) that calls
     `self::css()` or `self::js()` with the constants. Helpers attach
     SRI automatically and route an `onerror` to the local fallback.

4. **Use the helper, never raw `<script>`:**
   ```php
   <?php echo \Portal\Core\Asset::sortableJs(); ?>
   ```
   Inline `<script src="https://cdn…">` tags will be caught by the audit
   AND don't get the local-fallback handler.

5. **Run the audit:**
   ```bash
   python3 tools/audit-checks/check_cdn_sri.py
   ```
   Should report `No CDN tags missing integrity= attribute.` ✅

### Currently-unfilled hashes

The Sortable + Swagger UI helpers ship with empty integrity constants
(TODO markers in `Asset.php`). The tags still render, but without
integrity verification. To fill them, run the curl/openssl command
above and update the four `*_INTEGRITY` constants in `Asset.php`.

---

## Noticeboard React bundle (#360 / PR #358)

The board's frontend at `web/public_html/assets/noticeboard/noticeboard.{css,noeval.js}` is **generated** — the `dc-runtime` header on line 1 of the JS files marks it. Do not hand-edit. Rebuild from the Claude Design page (https://claude.ai/design/p/6fab711c-d550-4200-8d96-42d6751a5fba) when the source component changes.

Two variants exist in the design output:
- `noticeboard.js` — eval variant (runtime-Babel from unpkg). **Never wire this in.** The portal CSP disallows `unsafe-eval` and does not allowlist unpkg, so it cannot run.
- `noticeboard.noeval.js` — precompiled variant, wired in `_apps/noticeboard/index.php`.

### Deliberate hand-edit exception (#363 — real upload pipeline)

`noticeboard.noeval.js`'s `handleFile()` already special-cased a host upload
hook (falling back to a `data:` URI `FileReader` read when absent), but named
it `host.uploadFile(file)`. #363 wires that hook up to a real backend via
`window.NoticeboardHost.upload(file)` (the bridge name the rest of the portal
uses — see `_apps/noticeboard/index.php`), so the **only** hand-edit made to
the generated bundle is renaming the two `uploadFile` references at
`handleFile()` to `upload`. Everything else in the function (the `Promise`
wrapping, `uploading` state, error `alert()`, and the `data:` URI fallback for
a standalone/local deployment with no host) is untouched.
**If you regenerate the bundle from the Claude Design source**, either carry
this rename forward again, or (better) rename the hook to `upload` in the
source component itself so a regeneration doesn't silently revert it.

### React hosting

React 18.3.1 UMD is **self-hosted** at `web/public_html/assets/vendor/react/`:
- `react-18.3.1.production.min.js` — sha384 `DGyLxAyjq0f9SPpVevD6IgztCFlnMF6oW/XQGmfe+IsZ8TqEiDrcHkMLKI6fiB/Z`
- `react-dom-18.3.1.production.min.js` — sha384 `gTGxhz21lVGYNMcdJOyq01Edg0jhn/c22nsx0kyqP0TxaV5WVdsSH1fSDUf5YJj1`

The bundle's `loadReactUmd()` short-circuits when `window.React` / `window.ReactDOM` pre-exist — so the CSP-blocked unpkg fetch path never fires when React is pre-loaded via the self-hosted `<script nonce defer>` tags. Verify SRI on any bundle regeneration; the hashes must match the strings embedded at `noticeboard.noeval.js:1495,1497`.

### Page-scoped CSP extension contract

`_core/templates/header.php` accepts three optional variables set by a page controller BEFORE the `require ... 'header.php';` line:

- `$cspImgExtra`   — space-separated source list appended to `img-src`
- `$cspMediaExtra` — space-separated source list appended to `media-src` (new directive; behaviour-neutral vs the previous `default-src 'self'` fallback when unset)
- `$cspFrameExtra` — space-separated source list appended to `frame-src`

Extensions are per-page — the underlying `default-src / img-src / frame-src` remain unchanged for every other page. `_apps/noticeboard/index.php` sets `https:` on img/media and `https://www.canva.com` on frame.

### Typography (#361 — self-hosted)

The generated bundle template still injects a `fonts.googleapis.com` stylesheet (Bricolage Grotesque / Instrument Serif / IBM Plex Mono / IBM Plex Sans) and the generated `noticeboard.css` still carries the matching `@import url(https://fonts.googleapis.com/...)`. The portal CSP does not allowlist Google Fonts (self-hosting stance, PR #356), so both of those remain **dead under CSP** — every request they'd make is blocked. They're left in place rather than hand-edited, per the "generated — do not edit" rule above; harmless once the fonts are self-hosted (below), since a broken/blocked `@import` just does nothing.

As of #361, all four families are **self-hosted** instead of falling back to `system-ui`:

- `web/public_html/assets/noticeboard/fonts-selfhost.css` — hand-maintained (NOT generated), `@font-face` rules for the four families/weights the bundle actually references (verified by grepping `noticeboard.noeval.js` / `noticeboard.css`): Bricolage Grotesque (variable, wght 400–800), Instrument Serif (400 upright + italic), IBM Plex Mono (400/500), IBM Plex Sans (400/500/600). `latin` + `latin-ext` subsets (the latter for Welsh diacritics — see `_lang/cy.php`); cyrillic/greek/vietnamese subsets skipped.
- `web/public_html/assets/noticeboard/fonts/*.woff2` — the actual font files, sourced from `@fontsource`/`@fontsource-variable` v5.3.0 (SIL OFL-1.1), ~360 KB total. `OFL-*.txt` license texts for each family are redistributed alongside them.
- Wired via a `<link rel="stylesheet" href="/assets/noticeboard/fonts-selfhost.css">` in `_apps/noticeboard/index.php`, placed immediately **before** the generated `noticeboard.css` `<link>` so the `@font-face` rules exist before the board renders any text.
- Fallback chain: `#noticeboard-root { font-family: 'IBM Plex Sans', 'Plus Jakarta Sans', system-ui, sans-serif; }` — if a self-hosted face were ever unavailable, the board falls back to the portal's own self-hosted Plus Jakarta Sans (PR #356) before system fonts. Per-poster inline `font-family` values are set by the generated bundle itself and can't route through that fallback without editing generated code — moot in practice since the `@font-face` rules make all four families resolve directly.
- CSP: no changes needed. `style-src`/`font-src` both include `'self'` at `_core/templates/header.php`, and the fonts are same-origin — `_apps/noticeboard/index.php` only widens `img-src`/`media-src`/`frame-src` (unchanged).

---

## REST API v1 (#323 Phase 2)

### Dual-mode contract — bearer OR session, never both

Every `_apps/{app}/api/{action}.php` handler resolves auth through one choke-point,
`Portal\Core\ApiAuth` (`web/_core/ApiAuth.php`):

- **Bearer** — `Authorization: Bearer wbms_…`. Detected purely by the `wbms_` prefix
  (`ApiAuth::isBearer()`); any other bearer scheme falls through to the session path
  untouched, so this never hijacks a future OAuth integration. Verified via
  `ApiKey::findByPlaintext()` (Phase 1), scope-gated via `ApiKey::hasScope()`
  (wildcards `*` / `{res}:*` supported), tenant-pinned to the KEY's own site
  (`Site::forceContext()` — see below), and per-key rate-limited. **No CSRF** — a
  bearer token travels in an explicit header set by calling code, never by a
  browser automatically, so CSRF protection is meaningless for it (the
  OWASP-sanctioned exemption for token auth).
- **Session** — the existing logged-in portal user. Reproduces the historical
  per-handler boilerplate verbatim (same order, same rejection strings/codes):
  `requireAuth` → optional `requireAdmin` → `Auth::ensureSession()` → CSRF via the
  `X-CSRF-TOKEN` header or `csrf_token` body field on writes.

Handlers call `ApiAuth::requireRead('{resource}:read', $sessionNeedsAdmin)` or
`ApiAuth::requireWrite('{resource}:write', $sessionNeedsAdmin)` (the latter returns
the decoded JSON body) instead of hand-rolling the boilerplate. `ApiAuth::source()`
/ `apiKeyId()` / `actorUserId()` feed `Logger::audit()`'s new `$apiKeyId` /
`$source` parameters (auto-resolved when omitted — every pre-existing call site
compiles and behaves unchanged).

### `/api/v1/{resource}[/{id}]` facade

`ApiRouter::dispatchV1()` translates `(HTTP verb, resource, id)` into the identical
legacy `(app, action)` pair and re-runs the **same** pipeline as
`/api/{app}/{action}`: same handler file, same `api.{app}.{action}.enabled` flag.
No new gating vocabulary, no `tblRoutes` rows. See CLAUDE.md's "ApiRouter routing
trap" section for the one-line summary. Per-resource action aliases (where the
handler file isn't named `create`/`update`/`delete`):

| Resource | POST → | PUT/PATCH → | DELETE → |
|---|---|---|---|
| noticeboard | `save` | — (none) | — (none) |
| leadership | `assign` | — (none) | `unassign` (id = assignmentID) |
| prayer-requests | `create` | `moderate` | — (none) |
| tasks | `create` | `complete` | `delete` |
| expenses | `create` | — (**deferred to Phase 3**) | `delete` |

`GET /api/v1/{resource}/{id}` (a "detail" route) exists for **events only** — every
other resource 404s an id-suffixed GET (no `detail.php` handler). Unknown resource
→ 404; non-numeric id → 400; unsupported verb → 405 with an `Allow` header built
from which handler files actually exist on disk.

### Scope vocabulary

`Portal\Core\ApiKey::SCOPES` is the single source of truth — twenty `{resource}:read`
/ `{resource}:write` pairs across the ten v1 resources (events, announcements,
attendance, prayer-requests, documents, expenses, leadership, tasks, noticeboard,
users). The admin mint form (`_apps/admin/integrations/api-keys.php`) renders this
constant as a checkbox grid — never a duplicated hardcoded list — and
`api-keys-save.php` re-validates every submitted token server-side against
`SCOPES` ∪ `{'*'}` ∪ `{'{resource}:*'}` for a KNOWN resource, rejecting anything
else. `ApiKey::hasScope()` honours both wildcard forms at verification time.

### Tenant pinning (`Site::forceContext`)

A bearer request carries no session, so `Site::id()` would otherwise resolve to
the host-detected default site rather than the key's own. `resolveBearer()` in
`ApiAuth` re-points the site context to the key's `siteID` via
`Site::forceContext(int $siteId)` before the handler runs, so every
`Site::id()`-scoped query in every handler becomes tenant-correct automatically.
`forceContext()` fails CLOSED: a key with `siteID <= 0`, a missing/inactive site,
or (when multisite is disabled) any site other than the install's single site, all
500 rather than silently falling back to the ambient default. `ApiRouter`'s
`api.{app}.{action}.enabled` gate is ALSO resolved against the pinned site
(`App::settingForSite`), not the frozen bootstrap `$SETTINGS` snapshot — a site's
own kill-switch can't be bypassed via the Host header on a bearer request.

### Per-key rate limiting

`RateLimiter::tooMany()` / `recordHit()` / `retryAfter()` implement a generic
sliding window against a new `tblApiRateLimits` table (bucket = `apikey:{keyID}`),
separate from the pre-existing login-attempt limiter. Limits default to 300
requests / 5 minutes, overridable per-site via `api.rateLimit.perKey.maxRequests`
/ `windowMinutes`. A 429 sets `Retry-After`. Session callers are never rate-limited
by this mechanism (unchanged — they're protected by login rate limiting instead).

### Rotation grace

`ApiKey::rotate($keyId, $byUserId, ?$graceHours)` mints a replacement key first,
then either revokes the old key immediately (`$graceHours === 0`) or caps its
`expiresAt` at `now + $graceHours` and stamps `rotatedToID` (`$graceHours` null
resolves against the `api.keys.rotationGraceHours` setting, default 24). The old
key stays `isActive = 1` and dies naturally via the existing `expiresAt` check in
`findByPlaintext()` once the cutoff passes — zero changes to the verification
path. The admin UI (`api-keys.php`) offers a grace `<select>` (immediate / 1h /
24h / 72h, default 24h) on the rotate control, and shows an "Expiring (rotated)"
badge on any key row where `rotatedToID IS NOT NULL AND isActive = 1`.

### ⚠️ Known limitations (documented, not bugs)

1. **The per-key rate limiter is check-then-record, not atomic.** `tooMany()` and
   `recordHit()` are two separate statements with no `SELECT ... FOR UPDATE` /
   advisory lock between them — a genuinely concurrent burst against the same key
   can slightly exceed `maxRequests` before the limiter catches up. This is
   approximate limiting by design (house pattern: fail-open on DB error, cheap
   single-row inserts + a covering index over a distributed lock), not a
   correctness bug. Revisit only if abuse patterns actually exploit the window.
2. **The bearer key-row `lastUsedAt`/`lastUsedIP` stamp runs on the PRE-gate
   lookup.** `ApiRouter::resolveEnabledFlag()` calls `ApiAuth::bearerKeyRow()` to
   discover the key's site for the `enabled` check BEFORE the handler (and its own
   `ApiAuth::requireRead/Write()` call) runs — and `ApiKey::findByPlaintext()`
   stamps `lastUsedAt` as a side effect of that same lookup. `bearerKeyRow()` is
   cached per-request, so this costs exactly one extra single-row `UPDATE`, not a
   duplicate DB round-trip — but it means a valid key hammering a DISABLED
   endpoint still drives one un-throttled `lastUsedAt` write per request (the
   per-key rate limiter only runs inside `resolveBearer()`, which a disabled
   endpoint never reaches). Self-contention against the key's own row; no
   cross-tenant impact; negligible load in practice.
3. **Expenses status-transition update (approve/reject/reimburse) is DEFERRED to
   Phase 3.** There is no `PUT /api/v1/expenses/{id}` in this release — v1 ships
   `create` + `delete` (Pending-only) only. The transition needs to share the
   existing multi-approver workflow + `ExpenseMailer` side-effects
   (`_apps/expenses/approve/`) rather than duplicating that logic, which wants its
   own extraction pass.
4. **Several write endpoints record creator/updater as NULL for bearer requests.**
   `ApiAuth::actorUserId()` returns `null` in bearer mode (there is no session
   user) — handlers use `ApiAuth::actorUserId() ?? 0`, so a bearer-created row's
   `createdByID`/`updatedByID` is `0`/`NULL` rather than a real user. Attribution
   for a bearer-made change is via the audit trail instead:
   `tblAuditTrail.apiKeyID` + `.source = 'apikey'` (see the admin Audit Trail
   viewer's new source badge + key-prefix column).

---

## Giving — two-person offering count session (#299 sub-feature 1)

Extension to the existing `giving` app (#266, migration 094). #299 ("Giving
polish") bundles FOUR sub-features — offering counting, pledge campaigns,
bank reconciliation, account-updater. Only sub-feature 1 is built here; the
other three are separate, tracked-but-not-started scope.

### Naming: `tblGiftEntries` vs the real `tblGivingEntry`

#299's issue body sketches the write target as `tblGiftEntries`, but the
giving app actually shipped as `tblGivingEntry` (singular "Entry", amounts in
PENCE via `amountPence` — see `Portal\Core\Giving`, `web/_sql/full_schema.sql`
§"Giving / contributions log"). This migration (150) writes to the REAL
table. New columns introduced here follow `tblGivingEntry`'s own convention
(`siteID INT NOT NULL DEFAULT 1`, `createdAt DATETIME NOT NULL DEFAULT
CURRENT_TIMESTAMP`, `fk_<short>_<col>` constraint names) rather than the
issue body's sketch verbatim.

### Schema

- **`tblCountSessions`** — one row per service date. `counter1ID`/`counter2ID`
  (nullable, assigned at creation or later) each get their own independent
  `cashTotal1/chequeTotal1/envelopeTotal1` and `…2` triplet (`DECIMAL(10,2)`,
  nullable until entered). `cashTotal`/`chequeTotal`/`envelopeTotal` (also
  nullable `DECIMAL(10,2)`) are the **agreed** totals — set only once the two
  independent counts match, or an admin resolves a discrepancy — and are what
  actually gets written to the gift log on close.
  **`categoryID` (NOT NULL, FK `tblGivingCategory`) is an addition beyond the
  issue body's column sketch** — it's required because `tblGivingEntry.categoryID`
  is `NOT NULL`, so every count session needs to know which giving
  category/fund its gift log posts to. Picked at session-creation time.
- **`tblCountEnvelopes`** — added per the issue body's own conditional ("if
  envelope-level named entries are needed to write per-envelope gift entries,
  add a child table"). Models the numbered/named giving-envelope breakdown of
  a session's agreed `envelopeTotal` — `giverID` (nullable, matched member) OR
  `giverName` (free text, mirrors `tblGivingEntry.donorName`'s own
  matched-vs-free-text pattern), `amount DECIMAL(10,2)`, `method
  ENUM('cash','cheque')`. Entered ONCE per session (not duplicated per
  counter) — only the aggregate cash/cheque/envelope totals are independently
  double-keyed; the named breakdown is collaborative, shared session data.

### State machine (`tblCountSessions.status`)

```
open ──(counter 1 OR 2 submits)──▶ counting ──(other counter submits, MATCH)──▶ counting (agreed totals set, ready to close)
                                       │
                                       └──(other counter submits, MISMATCH)──▶ discrepancy
                                                                                   │
                                                              (counter re-enters, now matches) ──▶ counting
                                                              (admin resolves w/ agreed totals) ──▶ counting
counting ──(close: agreed totals set AND envelopes reconcile)──▶ closed  [terminal]
```

`giving.countRequiresTwoCounters` (default `'true'`) — when `'false'`,
whichever counter slot is completed FIRST is auto-agreed immediately (no
discrepancy comparison at all), for sites that only run a single-counter
process.

### Close — writing a *balanced* gift log

The issue body says close writes "`tblGiftEntries` for each named envelope +
a single 'loose cash' entry". This implementation goes one step further for
correctness: it ALSO emits a single aggregate **"loose cheque"** row for any
agreed `chequeTotal` not covered by named cheque envelopes. Rationale: the
GIRFT requirement is that close "writes a **balanced** gift log" — the sum of
every `tblGivingEntry` row written for a session must equal `cashTotal +
chequeTotal + envelopeTotal` exactly, not just cover the cash bucket. All
three checks below run BEFORE the transaction opens (`_apps/giving/count/close.php`),
so a rejected close never touches the database:

1. Status must be `'counting'` (not `'open'`, `'discrepancy'`, or already `'closed'`).
2. Agreed `cashTotal`/`chequeTotal`/`envelopeTotal` must all be set (non-NULL).
3. `SUM(tblCountEnvelopes.amount)` must equal the agreed `envelopeTotal` EXACTLY
   (integer-pence comparison, never `==` on floats/DECIMALs).

Then, in one transaction: one `tblGivingEntry` row per named envelope
(`donorID`/`donorName` from the envelope, `categoryID`/`donatedAt`/`siteID`
from the session, `reference = 'Count #<id>'`) + a loose-cash row (if the
agreed cash total exceeds what named cash envelopes cover) + a loose-cheque
row (same, for cheques) + `UPDATE … SET status='closed' … WHERE status='counting'`
(the `WHERE status='counting'` guard + checking `affected_rows` catches a
concurrent close/edit between the pre-checks and the write, aborting the
transaction rather than double-writing).

### Gate

Every route (`/giving/count`, `/giving/count/session`, `/giving/count/save`,
`/giving/count/close`) uses `Portal\Core\Giving::canManage()` — the same gate
`giving`'s existing `manage.php`/`entry-save.php`/`cat-save.php` already use
(site admin OR the `treasurer` role, migration 017). Resolving a live
`'discrepancy'` (`action=resolve` in `save.php`) additionally requires
`App::isAdmin()` — matching the issue body's "an admin resolves" wording;
plain treasurers can only re-enter counts or close once no discrepancy
remains.

### New core helper

`Portal\Core\Giving::parseDecimal(string $input): ?string` — validated
non-negative `DECIMAL(10,2)`-safe amount parsing (round-trips through
float → round → `number_format`, never trusts the client string into SQL
verbatim). Sibling to the existing `Giving::parseAmount()` (pence-int, used
by `tblGivingEntry` writes) — this workflow's independent counter totals are
DECIMAL columns on `tblCountSessions`/`tblCountEnvelopes`, not pence.

---

## Discipleship Pathway Tracker Phase 2 (#303 Phase 2)

Extension to Phase 1 (migration 142 — `tblPathways`/`tblPathwaySteps`,
admin CRUD only, app hidden behind `discipleship.enabled = 'false'`).
Phase 2 adds per-user enrolment/progress, auto-completion, member-facing
routes, and a pastor roster. New core helper: `Portal\Core\Discipleship`.

### Adopted scoping decisions (issue #303 blocker comment, 2026-06-21)

1. **Auto-completion sources — option (a), per-user tables only.**
   `tblEventAttendance` (rows with `userID IS NOT NULL` — walk-ins are
   excluded automatically by the sweep's `ea.userID = e.userID` join) and
   `tblEventRSVPs`. `tblSalvationCards` has no `userID` and
   `tblDecisionMoments` is an aggregate counter with no per-user rows —
   both are structurally incompatible with a per-user completion model,
   not merely deprioritised; revisit if/when either table grows a
   per-user identity column.
2. **Pastor surface stays a flat roster list.** One `portal-data-list` row
   per enrolled member (progress bar, n/m required steps, last
   completion), with drill-down to a per-member step list. A
   members×steps matrix was explicitly rejected — it's the house
   `<table>` ban plus the issue's own recorded decision.
3. **Mentor relationships deferred.** No `tblPathwayMentor` schema, no UI,
   in this phase.

### The revoke-vs-delete unmark semantic

`tblPathwayProgress` has `UNIQUE(stepID, userID)` — at most one progress
row can ever exist per (step, member) pair, for the lifetime of that step.
Unmarking a step (admin action, or a member's own auto-completed step
being corrected) sets `revokedAt`/`revokedByID` on that SAME row; it is
**never** `DELETE`d. "Complete" everywhere in the app — `progressFor()`,
`rosterStats()`, `refreshEnrolmentStatuses()`, the member/admin views —
means `revokedAt IS NULL`.

This is deliberate, not an oversight: `Discipleship::autoSweep()` writes
via `INSERT IGNORE`, relying on the unique key to make repeat sweeps a
no-op. If unmarking deleted the row, the very next sweep (lazy, on the
next page view) would see no conflicting key, re-insert the row from the
still-existing attendance/RSVP evidence, and silently resurrect a step a
coordinator deliberately corrected. Keeping the (now-revoked) row means
its unique key permanently blocks that re-insertion — the only way to
"undo an unmark" is the admin explicitly re-marking it complete again
(`progress-mark.php`'s `complete` action, which clears `revokedAt`/
`revokedByID` on the SAME row rather than inserting a new one).

One consequence worth knowing: a step revoked once can only ever be
un-revoked by a human action (manual re-mark). It will never silently
flip back to complete on its own, even if the auto-evidence that
originally satisfied it still exists — this is the intended trade-off
(coordinator correction wins over automation), documented here so it
isn't mistaken for a bug during support triage.

### Lazy-sweep design (no scheduler dependency)

`Discipleship::autoSweep(int $siteId, ?int $pathwayId = null)` is pure
set-based SQL (three `INSERT IGNORE … SELECT` statements, one per
`autoRule` value, each joining active pathways × active enrolments × the
matching evidence table) — cheap enough to run synchronously on every
page load rather than needing a background job. It is invoked at the top
of:

- `discipleship/index.php` (member "My pathways") — scoped to the site.
- `discipleship/view.php` (member pathway detail) — scoped to the pathway.
- `admin/discipleship/progress-pathway.php` (pastor roster) — scoped to
  the pathway.

Because every rule's `INSERT IGNORE` is idempotent via
`UNIQUE(stepID, userID)`, calling `autoSweep()` on every page view never
duplicates work — a repeat call over already-swept data inserts zero new
rows. `cron/discipleship-sweep.php` exists purely so a site with low
member traffic on discipleship pages still gets fresh auto-completions
(e.g. overnight) — it is a convenience, never a correctness dependency.

### Cron token setup

Same pattern as `reminders.cron_token` (migration 122): the endpoint reads
`?key=<value>`, compares it to `Settings::get('discipleship.cron_token', '')`
via `hash_equals()`, and 403s whenever the stored token is the empty
string — so the cron endpoint is inert until an admin explicitly sets a
non-empty `discipleship.cron_token` value (via `/admin/settings`; the
setting is seeded `isSensitive = 1`, so it's encrypted at rest like other
secrets). Point an external scheduler (e.g. DreamHost's cron, or a
third-party uptime-ping-style scheduler) at:

```
https://<your-portal-host>/cron/discipleship-sweep?key=<your-token>
```

The route (`cron/discipleship-sweep`, migration 153) is seeded
`isProtected = 0` — it is public but token-gated, exactly like
`cron/event-reminders`. It loops every distinct `siteID` that owns at
least one active pathway and runs one site-wide `autoSweep()` per site,
returning a plain-text `OK {"sitesSwept":N,"inserted":{...}}` summary.

---

### Webhooks setup

`Portal\Core\WebhookDispatcher::emit()` (#324) POSTs a signed JSON payload to
every active `tblWebhooks` row subscribed to an event, synchronously,
inline with the triggering request. A non-2xx response (or a network
failure) leaves the `tblWebhookDeliveries` row in `status = 'failed'` with
`attemptCount` and a computed `nextRetryAt` rather than giving up —
`WebhookDispatcher::retryDue()` (migration 166, #324 v1.1) is the
cron-driven async retry worker that sweeps those rows back up.

Same cron-token pattern as `reminders.cron_token` / `assets.cron_token` /
`discipleship.cron_token`: the endpoint reads `?key=<value>`, compares it
to `App::settings('webhooks.cron_token')` via `hash_equals()`, and 403s
whenever the stored token is the empty string — migration 166 seeds
`webhooks.cron_token` empty (`isSensitive = 1`), so the endpoint is inert
until an admin sets a real value via `/admin/settings`. Point an external
scheduler at (every 5-15 minutes is a reasonable interval — failed
deliveries retry with exponential backoff, so a tighter interval mostly
just wastes requests on rows not yet due):

```
*/10 * * * * curl -fsS "https://<your-portal-host>/cron/webhook-retry?key=<your-token>" >/dev/null
```

The route (`cron/webhook-retry`, migration 166) is seeded `isProtected = 0`
— public but token-gated, exactly like every other `cron/*` route. Each
run selects up to 100 `tblWebhookDeliveries` rows in `status = 'failed'`
with `attemptCount < 6` (`WebhookDispatcher::MAX_ATTEMPTS`) whose
`nextRetryAt` has elapsed (or was never set), oldest-`createdAt` first, and
re-delivers each through the SAME `attemptDelivery()` private method
`emit()`'s own initial attempt uses — no separate retry-vs-first-try
signing/POST logic to drift apart. A delivery that keeps failing backs off
exponentially (base 60s × 2^attempts, capped at 21600s / 6h) via
`DATE_ADD(NOW(), INTERVAL ? SECOND)` computed in SQL (not PHP's own clock,
so an app-server clock skew can never desync `nextRetryAt` from the DB's
own `NOW()` retryDue()'s WHERE clause compares it against); once
`attemptCount` reaches `MAX_ATTEMPTS` the row moves to the terminal
`status = 'dead'` and is never retried again. Returns a plain-text
`OK {"checked":N,"delivered":N,"failed":N,"dead":N}` summary.

---

## Event Team Hub Phase 1 (#386)

### Architecture: extends the calendar app, not a new app

`/calendar/event/hub` lives in `_apps/calendar/` (`event-hub.php` +
`event-hub-save.php`), not a new top-level app — CLAUDE.md is explicit
that Calendar/Events/Preaching Plan is ONE app, and every input the hub
composes (event, coordinators, crews, jobs, people) already lives in the
calendar app's tables, keyed by `eventID`. The two genuinely reusable
pieces — provider detection, embed URLs, CF signed tokens, CSP frame-src
lists — live in `_core/VideoEmbed.php` so `/live`, noticeboard, or
recordings can adopt the same helper later without dragging in the
calendar app.

### Access control: `canView` vs `canManage`

Two distinct gates, deliberately different in breadth:

- `canView` = `Auth::isEventTeamMember($eventId)` — coordinator OR crew
  leader/participant OR job assignee OR a `tblEventPeople` row (host/
  speaker/organiser/…). Grants read access to the hub page only.
- `canManage` = `App::isAdmin() || Auth::isCoordinatorOf($eventId)` — the
  existing house idiom, unchanged, used by every other event sub-tool
  (crews/jobs/attendance/broadcast). Grants the inline add/edit/remove/
  reorder forms and the tool-strip links.

`isEventTeamMember()` mirrors `isCoordinatorOf()`'s shape exactly (same
`self::check()` + `$eventId <= 0` guards, same `$db->prepare()` /
`bind_param()` / `fetch_assoc()` / `close()` rhythm) so the two methods
stay trivially comparable during review. All four membership tables
(`tblEventCoordinators`, `tblEventCrewMembers`, `tblEventJobAssignments`,
`tblEventPeople`) cascade-delete with their event, so hub access can never
outlive the event it's scoped to.

### Video providers: allowlist detection, never a raw embed

`VideoEmbed::parse()` is the single place a pasted string becomes a
`{provider, ref}` pair — YouTube (`watch?v=`, `youtu.be/`, `/shorts/`,
`/live/`, `/embed/`), Vimeo (`vimeo.com/<id>`,
`player.vimeo.com/video/<id>`), or Cloudflare Stream (UID embedded in
`customer-*.cloudflarestream.com`, `watch.cloudflarestream.com`, or
`iframe.videodelivery.net`, or a bare 32-hex UID). Anything else returns
`null` — `event-hub-save.php`'s `addVideo` action flashes an error and
stores nothing. Every `embedUrl()` call re-validates the ref's character
class immediately before string-interpolating it into an iframe `src`
(the same discipline as `Livestream::embedUrl()`'s ID sanitiser), so even
a corrupted/tampered database row can't produce an XSS-bearing iframe.

### Cloudflare Stream signed-URL playback (no vendored JWT encoder)

`_vendor/simplejwt/JWT.php` is **verify-only** (built for MS365/Google ID
tokens) — it has no encode/sign capability, and it is deliberately not
extended for this, since it's scoped as an IdP-token verifier. Signing is
new code in `VideoEmbed::signedToken()`:

1. Build the compact JWT segments by hand: `header = {"alg":"RS256",
   "kid":<keyID>}`, `payload = {"sub":<uid>, "kid":<keyID>,
   "exp":now+ttl, "downloadable":false}`, both base64url-encoded (RFC
   4648 §5, no padding).
2. `openssl_sign($header.'.'.$payload, $signature, $pem,
   OPENSSL_ALGO_SHA256)` — the same primitive `simplejwt` uses for
   verification, just run in the opposite direction.
3. Token = `header.payload.` + base64url(`$signature`).

The PEM (`cfstream.signingKeyPem`, `isSensitive = 1`, libsodium-encrypted
via `encrypt_setting()`/`decrypt_setting()`) and the key ID never leave
the server — only the short-lived JWT reaches the browser, inside an
iframe `src` that itself is never logged. A missing/invalid key makes
`signedToken()` return `null` (logged via `Logger::errorPlatform()`,
**never** logging the PEM or signature) — the hub page renders an
"unavailable — check Stream settings" tile rather than a broken iframe.
Tokens are cached in `$_SESSION['cfstream_tokens'][$uid]` plus a
per-request static memo, reused while more than 1/10th of
`cfstream.tokenTtlSeconds` (default 21600s / 6h) remains — RSA-2048
signing is ~1ms, so this is belt-and-braces, not load-bearing.

### Cloudflare Stream credential-setup runbook

Two **separate** credentials — see the two-credential explainer on
`/admin/integrations/cloudflare-stream` itself:

1. **Pick the Cloudflare account** that owns (or will own) the Stream
   subscription. This portal's Cloudflare MCP connection exposes D1/KV/
   R2/Workers only, **not Stream** — the portal cannot see or create
   either credential below; an admin must do both in the Cloudflare
   dashboard/API directly.
2. **Create the Stream:Edit API token** (Phase 1.5 — captured now for
   forward-compatibility, unused by anything shipped in this release):
   Cloudflare dashboard → **My Profile → API Tokens → Create Token →
   Custom Token** → permission **Account → Cloudflare Stream → Edit**,
   scoped to the one account from step 1 (not "all accounts"), no zone
   permissions. Optionally pin it to the DreamHost server's outbound IP
   under "Client IP Address Filtering". Paste the token into **API
   token** on the admin page — it's `isSensitive`-encrypted and never
   re-displayed once saved.
3. **Create the signing key** (used TODAY for signed-URL playback): the
   Cloudflare dashboard has **no UI** for this — it must be created via
   the API:
   ```
   curl -X POST "https://api.cloudflare.com/client/v4/accounts/<accountID>/stream/keys" \
        -H "Authorization: Bearer <a token with Stream:Edit>"
   ```
   The response contains `result.id` (→ **Signing key ID**) and
   `result.pem` (→ **Signing key PEM**, base64-encoded in the API
   response — paste the decoded PEM, including the
   `-----BEGIN PRIVATE KEY-----`/`-----END-----` lines, into the
   textarea). **This is a one-time, non-retrievable secret** — Cloudflare
   does not let you fetch the private key again after creation, so paste
   it into the admin page (or a password manager) immediately.
4. Fill in **Account ID** (32-hex, from the Cloudflare dashboard URL or
   `GET /accounts`) and **Customer code** (the `customer-<code>` segment
   your account's Stream playback URLs already use — visible on any
   existing video's embed code, or `GET /accounts/{id}/stream` → any
   video's `preview`/`playback.hls` URL).
5. Tick **Enable Cloudflare Stream video embeds** and save. Existing
   Cloudflare Stream videos on the hub whose `requiresSignedUrl` is
   unticked will start rendering immediately (playback needs only the
   customer code); signed videos need the signing key from step 3.

### Phase 1 vs Phase 1.5 scope

Phase 1 (this build) is **external references only** — a coordinator
pastes a YouTube/Vimeo URL or a Cloudflare Stream UID/URL;
`tblEventHubVideos.uploadStatus` is always `'external'`. Deliberately OUT
of scope, tracked as Phase 1.5 against the same issue (#386):

- `Portal\Core\CloudflareStream` — the Cloudflare *management* API client
  (mint/poll/edit/delete direct-creator-uploads), modelled on `Zoom.php`.
- The direct-upload JSON endpoints (`calendar/event/hub/video/upload-url`,
  `calendar/event/hub/video/status`) and the vanilla-XHR upload widget
  (progress bar, no `tus` in the first build — CSP forbids CDN JS, and
  `tus-js-client` would have to be vendored).
- `$cspConnectExtra` — a new page-scoped CSP extension point mirroring
  the existing `$cspImgExtra`/`$cspMediaExtra`/`$cspFrameExtra` trio in
  `header.php`, needed so the browser can POST straight to Cloudflare's
  upload URL without touching DreamHost's PHP upload limits.

`tblEventHubVideos` already carries every Phase 1.5 column
(`uploadStatus`, `errorDetail`, `uploadedAt`, `lastCheckedAt`,
`allowedOrigins`) and the admin page already carries every Phase 1.5
setting (`cfstream.apiToken`, `cfstream.maxUploadDurationSeconds`,
`cfstream.uploadMintPerHour`, `cfstream.defaultRequireSignedUrls`,
`cfstream.allowedOrigins`) — Phase 1.5 should need zero schema/settings/
admin-page changes, only new POST handlers gated behind
`CloudflareStream::isConfigured()`.

#### Phase 1.5 — SHIPPED (migration 156, routes only)

As predicted, Phase 1.5 needed no schema/settings/admin-page change — just
`Portal\Core\CloudflareStream` and three page-route handlers seeded by
migration 156 (routes only):

- `calendar/event/hub/upload-url` (POST, JSON) — mints a one-time
  direct-upload URL via `CloudflareStream::createDirectUpload()` and inserts
  an `uploadStatus='pending'` row. Gated in order: session → CSRF →
  admin/coordinator → cross-site → `isConfigured()` → per-user hourly rate
  limit (counts `tblActivityLogs` `CfStreamUploadMinted` rows in the last 60
  min — no separate table) → input/origins validation → CF call. Returns the
  rotated CSRF token so the no-reload poll loop keeps working.
- `calendar/event/hub/video-status` (POST, JSON) — polled ~4 s; terminal
  (`external`/`ready`/`error`) + non-CF rows return the stored state with no
  CF call; `pending`/`processing` throttled to one CF GET per ~5 s;
  reconciles the `requiresSignedUrl`/`allowedOrigins` mirrors from every
  live GET; a transient CF failure only stamps `lastCheckedAt`, never flips
  a video to `error`.
- `calendar/event/hub/video-settings` (POST, form + redirect/flash) —
  **CF-first**: `CloudflareStream::updateVideo()` runs first, the local
  mirror updates only on confirmed success.

Client: `web/public_html/assets/js/event-hub-upload.js` — basic ≤200 MB
upload (tus deferred, still not vendored), enforces the size cap **and** a
host allowlist (`upload.videodelivery.net` / `upload.cloudflarestream.com`)
on the returned `uploadURL`, and writes each response's rotated CSRF token
back into `<meta name="csrf-token">` and every `input[name=csrf_token]`.
`$cspConnectExtra` was added to `header.php` (identical pattern to
`$cspFrameExtra`); the hub sets it to the two upload hosts only for a
manager on a configured install. `event-hub-save.php`'s `removeVideo`
best-effort-deletes a portal-uploaded CF video first (a CF failure is logged
but never blocks the local delete — a coordinator must never get stuck with
an undeleteable row, e.g. when CF already 404s the asset).

**Runbook — the second credential (API token).** Distinct from the signing
key (step 3 above): Cloudflare dashboard → **My Profile → API Tokens →
Create Custom Token** → permission **Account → Cloudflare Stream → Edit**,
scoped to the Stream-owning account only → paste into
`admin/integrations/cloudflare-stream` (`cfstream.apiToken`). With it set
plus `cfstream.accountID`, the "Upload to Cloudflare" panel appears on the
hub for coordinators/admins. Until it is set, `CloudflareStream::
isConfigured()` is false and the whole upload path is inert — the hub is
exactly the Phase-1 paste-a-link experience.

---

## Event Team Hub REST API read endpoints (#387)

### Why: projectBookIT Event Team Hub Phase 3 integration

`_apps/calendar/api/hub-resources.php` and `hub-videos.php` expose the two
tables from Event Team Hub Phase 1 (#386, migration 155) over the public
REST API so an external system — projectBookIT's Event Team Hub Phase 3
(projectbookit#347) — can pull a given event's resources/videos with a
site-scoped bearer key, without any session/cookie access to this portal.

### Convention path, ApiRouter routing trap applies exactly as usual

Both handlers live at `_apps/calendar/api/{action}.php` — the standard
convention path `ApiRouter::dispatch()` resolves directly from the URL
(`api/calendar/hub-resources` → `_apps/calendar/api/hub-resources.php`).
**Neither is registered in `tblRoutes`** — `api/*` paths never reach
`tblRoutes` at all (`Router::handleSpecialRoutes` hands them straight to
`ApiRouter::dispatch` first) — see CLAUDE.md's "ApiRouter routing trap".
The only gate is `api.calendar.hub-resources.enabled` /
`api.calendar.hub-videos.enabled` in `tblSettings`, seeded `'true'` by
migration 157 (settings-only migration — no schema, no routes).

Note `calendar` was **not** added to `ApiRouter::V1_RESOURCES` — these two
endpoints are legacy-convention-path only, reachable at
`/api/calendar/hub-resources` / `/api/calendar/hub-videos`, with no
`/api/v1/calendar/...` facade alias. Adding one is a reasonable future
follow-up but was out of scope for #387 (the projectBookIT consumer only
needs the legacy shape).

### Auth: same dual-mode pattern as `events/list.php`/`detail.php`

Both handlers open with `ApiAuth::requireRead('eventhub:read')` — the
identical one-line dual-mode (bearer OR session) + scope-check idiom used
by every other read endpoint. `eventhub:read` is a **read-only** scope
(no `eventhub:write` counterpart exists yet — the Team Hub's own
add/edit/remove/reorder actions stay on `event-hub-save.php`'s session-only
CSRF-protected form POST; the REST surface here is consumption-only for
Phase 3). The admin API-keys mint form's `scopeGroups` grouping
(`_apps/admin/integrations/api-keys.php`) already tolerates a
read-without-write resource — it renders only the checkboxes present in
`ApiKey::SCOPES` for that resource prefix, so `eventhub` shows a single
"Read" checkbox with no empty "Write" slot.

### Tenant guard: explicit 404, not a WHERE-clause-only filter

`tblEventHubResources`/`tblEventHubVideos` are child tables of `tblEvents`
with **no own `siteID` column** (matches the `tblEventCrews`/`tblEventJobs`
precedent from migrations 117/118 — see the migration 155 header). Both
handlers therefore run an explicit tenant-guard query BEFORE touching
either hub table:

```php
SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1
```

A miss (wrong site OR event doesn't exist OR soft-deleted) returns the
identical `ApiResponse::error('Event not found', 404)` in every case —
there is no separate "event exists but wrong tenant" response shape, so a
scan across `eventID` values from another tenant's API key can't be used
to enumerate which IDs exist elsewhere.

### No-secret-leak discipline on the video endpoint

`hub-videos.php` SELECTs an explicit column list — `videoID, provider,
videoRef, title, requiresSignedUrl, allowedOrigins, uploadStatus,
sortOrder` — never `SELECT *`, so a future `tblEventHubVideos` ALTER
(e.g. a Phase 2 column) can't silently widen the API response. `videoRef`
is intentionally included: it's the public YouTube/Vimeo ID or Cloudflare
Stream UID a player embeds against, not a secret. What's deliberately
EXCLUDED: any Cloudflare signing key, any signed playback token (minted
per-viewer by `VideoEmbed::signedToken()` on the portal's own hub page,
never handed to an API consumer), and every `cfstream.*` setting value —
none of those columns/settings are read by either handler at all.
`requiresSignedUrl`/`allowedOrigins` are playback-*policy* metadata (would
a token be required, from which origins), not the token/key material
itself, so returning them is safe and useful to a consumer deciding how to
embed the video.

---

## Discovery-pass fold-in batch (#373 ApiRouter half, #339, #308, #255, migration 158)

### ApiRouter never got the Router.php #373 fix

`Router::dispatch()` was fixed for #373 by importing `global $mysqli,
$SETTINGS;` immediately before `require $targetFile;` (commit `58871ca`) —
PHP include scope is the enclosing function's locals, so a legacy controller
reading the bootstrap DB handle as a bare `$mysqli` needs that global
imported into `dispatch()`'s scope or it resolves to `null`.
`ApiRouter::dispatch()`/`dispatchV1()` include handlers the identical way
(`require $apiFile;` inside a static method) but never got the same
import — six live handlers (`livechat/api/*`, `livestream/api/ping.php`)
read bare `$mysqli` and fatally errored (`Fatal error: Call to a member
function prepare() on null`) on every request. Fixed by mirroring
`Router.php:128` at both `ApiRouter.php` call sites. No-op for handlers
already using `App::db()` (the preferred pattern for new code).

### Worship live-sync relocation — same shape as migration 144

`worship/present.php` (operator console) and `worship/display.php` (public
projector display) poll `/api/worship/state` + POST `/api/worship/advance`.
The real handlers pre-existed at `_apps/api/worship-{state,advance}.php`
— a location `ApiRouter::dispatch()` can never resolve to, because it
builds the include path directly from the URL segments
(`_apps/{appName}/api/{action}.php`) and never queries `tblRoutes`. Moved
both files **verbatim** (same SQL, same auth checks, same CCLI logging) to
`_apps/worship/api/{state,advance}.php` — the only change is the file
location and the docblock. Precedent: migration 144 did the identical
relocation for `api/livestream/ping` → `_apps/livestream/api/ping.php`.
Migration 158 seeds `api.worship.state.enabled` / `api.worship.advance.
enabled` = `'true'`; ApiRouter 403s a convention-path handler with no
enabled flag exactly like a missing one, so the relocation alone isn't
enough.

### AppRegistry trap — registering an always-on app needs its enable seed IN THE SAME migration

`AppRegistry::isEnabled()` returns `false` when an app's `settingKey` is
absent from `$SETTINGS` (`AppRegistry.php:95-116`), and
`Router::dispatch()` renders a 403 "app disabled" page for any registered-
but-disabled app's routes (`Router.php:113-117`). Before this batch,
`noticeboard`/`worship`/`salvation`/`kids` had working routes/tables/
handlers but no `_core/apps/{slug}.php` file — `AppRegistry::appForRoute()`
never matched them, so `Router::dispatch()`'s gate check (`$owningApp !==
null && isEnabled(...) === false`) short-circuited on `$owningApp === null`
and every request passed through ungated. The moment a `_core/apps/{slug}
.php` file is added, that app becomes gate-eligible — if its `enabled`
setting isn't ALSO seeded `true` in the same change, the app goes dark
immediately (silent 403 on every route). `noticeboard.enabled` was already
seeded (migration 145) since it self-checks the setting directly in its own
code; `worship`/`salvation`/`kids` had no enable flag anywhere, so migration
158 seeds all three `= 'true'` in the same migration that ships their
`_core/apps/*.php` files — never split across two migrations/PRs.

### Dead `api/*` tblRoutes rows — the check_*.py DELETE-tombstone parser only understands `= '...'` / `IN (...)`

`check_route_targets.py` and `check_schema_seed_parity.py` both model
`DELETE FROM tblRoutes WHERE routeKey = '...'` and `... WHERE routeKey IN
(...)` structurally (see each script's `delete_re`/`DELETE_RE`) so they can
compute the "final state" after every migration replays and confirm
`full_schema.sql` stays in parity. A `LIKE 'api/%'` pattern is NOT
recognised by either regex — using it would have left the 19 removed rows
looking "still expected" in `full_schema.sql`, breaking parity. Migration
158 therefore spells out all 19 `routeKey` values explicitly in a `DELETE
... WHERE routeKey IN (...)` — functionally identical to a `LIKE` sweep
(nothing else currently starts with `api/`) but readable by the audit
tooling. Precedent: migration 056 did the same explicit-list `DELETE` for
an earlier batch of 5 dead `api/*` rows.

### Cloudflare Stream "Test connection" — machine-safe response only

`CloudflareStream::testConnection()` calls the cheapest Stream endpoint
that validates BOTH `cfstream.accountID` and `cfstream.apiToken` together —
`GET /accounts/{acct}/stream?per_page=1` — succeeds on a brand-new account
with zero videos, no uid needed. Returns `{success, message}` with a small
fixed set of generic messages (not-configured / 401-403 / 404 / generic
transport failure) — Cloudflare's own error text is deliberately never
echoed back to the browser (only logged server-side via the shared
`request()` path, same as every other `CloudflareStream` call), so a
copy-pasted screenshot of the admin page can't leak anything
token-adjacent. This is also the best low-risk way to firm up the
**[CF-kc]**-flagged endpoint set (see `CloudflareStream.php`'s class
docblock) before the first real upload — a wrong endpoint shape now
surfaces as a clear "could not reach Cloudflare Stream" on the settings
page instead of a silent failure discovered mid-upload.

---

## PayPal payment adapter + online giving/pledge checkout (gap #1)

### Sandbox setup

1. Create a PayPal Developer account at https://developer.paypal.com and a
   **Sandbox** app (Apps & Credentials → Create App). Note the **Client ID**
   and **Secret** — these are the sandbox pair, distinct from any live pair.
2. On `/payments` (admin), set Provider = PayPal, Mode = Sandbox, paste the
   Client ID + Secret, tick Enable. Client ID and Secret are both stored
   encrypted at rest (libsodium, same as Stripe's secret key) and rendered
   as password-style "leave blank to keep" inputs — the saved value is
   never re-echoed into the form.
3. Create a webhook subscription (Apps & Credentials → your app → Add
   Webhook) pointed at the URL shown on the admin page:
   `https://<your-host>/payments/webhook?provider=paypal`, subscribed to
   exactly these three events:
   - `CHECKOUT.ORDER.APPROVED`
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.REFUNDED`
4. Copy the webhook's **Webhook ID** (`WH-…`) into the admin page's
   "Webhook ID" field and save. This is the ONE value
   `paypalVerifyWebhook()` needs to call PayPal's verify-webhook-signature
   API — leaving it blank means every PayPal webhook 401s (fail-closed by
   design, not a bug: S13 in the threat table below).
5. Test end-to-end with a PayPal sandbox buyer account (created
   automatically alongside the sandbox app, under Sandbox → Accounts):
   `/giving/give` → pick a category → an amount → "Continue to secure
   payment" → approve as the sandbox buyer → land back on
   `/payments/return` → `tblPayment` should show `succeeded` with the
   PayPal capture id as `providerRef`, and exactly one `tblGivingEntry` row
   (`reference = 'payment:{id}'`).

Going live is the same five steps against a **Live** app + Mode = Live —
there is deliberately no code branch that reads `payments.test_mode` for
PayPal; `payments.paypal.mode` is the sole authority for which API base
URL (`api-m.sandbox.paypal.com` vs `api-m.paypal.com`) is used, and
anything other than the literal string `'live'` is treated as sandbox
(fail-safe: a typo or half-finished migration hits sandbox, never live
money).

### Why `intent=CAPTURE` orders need TWO capture triggers

Unlike Stripe Checkout (charged the moment the payer completes checkout),
a PayPal Orders v2 order with `intent: 'CAPTURE'` is only APPROVED when
the payer finishes on PayPal's page — an explicit second API call
(`POST /v2/checkout/orders/{id}/capture`) actually takes the money. Two
paths make that call, both funnelled through the same
`paypalCaptureOrder()` → `paypalHandleCaptureResult()` →
`markPaymentSucceeded()` chain:

1. **Return path** (primary, fast) — `payments/return.php` calls
   `Payments::finalizeReturn()` before rendering, which captures
   immediately when the row is still `pending` and the redirect result is
   `ok`. Most payers land here within seconds of approving.
2. **`CHECKOUT.ORDER.APPROVED` webhook** (backstop) — covers a payer who
   approves on PayPal's page and then closes the tab / loses connectivity
   before the redirect completes. Without this, that payment would sit
   `pending` forever (PayPal auto-voids an uncaptured APPROVED order after
   ~3 days, so the payer is never actually charged, but the giving/pledge
   record would never land either).

Both paths — plus the `PAYMENT.CAPTURE.COMPLETED` webhook that arrives
after ANY capture, whoever triggered it — can race each other for the same
row. That's safe by construction: `markPaymentSucceeded()`'s final
transition is a single atomic
`UPDATE tblPayment SET status='succeeded', … WHERE status='pending'`
gated on `affected_rows === 1` — only the first caller to win that WHERE
clause fans out into Giving/Projects; every later caller (a slower webhook
delivery, a double-click, a retried delivery) sees `affected_rows === 0`
and returns immediately.

### ★ The S1 integrity gate — the one control that matters most

`Payments::markPaymentSucceeded()` is the single choke point every success
path funnels through, and it now takes two additional parameters:
`?int $observedAmountPence, ?string $observedCurrency`. When non-null,
they are asserted EXACTLY (`===`, both amount and currency) against the
pending row's own `amountPence`/`currency` BEFORE the atomic transition
above runs. A mismatch:

```php
UPDATE tblPayment SET status = 'failed',
       errorMsg = 'amount-mismatch exp:{row} got:{observed}'
 WHERE paymentID = ? AND status = 'pending';
-- + Logger::activity('PaymentIntegrityFail', …)
-- + return; -- NEVER falls through to the fan-out below
```

Every PayPal call site extracts the observed amount from the field PayPal
itself reports back (the capture response's `amount.value`/
`currency_code`, the same fields on the 422-reconcile GET-order response,
or the verified webhook's `resource.amount`) and parses it through
`paypalMoneyToPence()` — a STRICT `^([0-9]+)\.([0-9]{2})$` parser that
returns `null` on anything else (`"10.5"`, `"1,000.00"`, scientific
notation). A `null` parse result is itself treated as an integrity failure
(row marked `failed`, `PaymentIntegrityFail` logged) — it is never passed
through to `markPaymentSucceeded()` as `null`, because a PayPal call site
passing `null` observed values would silently DISABLE the gate for that
call. **Grep rule for any future PayPal code path:**
`grep -n 'markPaymentSucceeded' _core/Payments.php` — every PayPal call
site must show 4 arguments, never 2. Stripe call sites keep passing 2 args
(unchanged behaviour) — extending the same gate to Stripe using
`checkout.session.completed`'s own `amount_total`/`currency` fields is a
noted follow-up, not done in this PR, to keep the Stripe diff at zero.

### `checkout.php` — what #430 already hardened vs. what this PR added

`checkout.php` already validated `purpose`/`purposeRef` server-side before
this PR (PR #430): the pledge branch requires `donorID` to be the logged-in
user and forces `amountPence`/`currency` from the pledge row (never the
POSTed amount — an under-payment could otherwise still fulfil a pledge in
full); the giving branch validates the category is active and site-scoped;
anything else coerces to `purpose = 'other'` with `purposeRef = null` (no
fan-out target, so nothing to forge). This PR's additions sit ADJACENT to
that, not instead of it:

- **`GIVING_MAX_AMOUNT_PENCE`** (currently `1_000_000` — £10,000) rejects
  an online GIVING amount above the ceiling with a "For large gifts please
  contact the office" flash. Pledges are exempt — a legitimate pledge can
  exceed this, and its amount is forced from the pledge row regardless of
  what (if anything) was POSTed.
- **Server-built descriptions** — `description` is no longer read from
  `$_POST` at all; it's built from the already-validated category name
  (`'Giving — {name}'`) or project title (`'Pledge — {title}'`, truncated
  to 120 chars). This closes a text-injection vector that becomes
  materially worse once a REAL provider-hosted page exists to inject into
  — a POSTed description previously flowed straight into the (until this
  PR, unreachable) provider checkout page's product/order name.

One implementation quirk worth knowing: the 100-pence floor check runs
BEFORE the purpose branch that overrides the amount for a pledge, so the
pledge "Pay now" form on `my-pledges.php` submits a throwaway
`amount=1.00` hidden field purely to clear that floor gate — the pledge
branch immediately overwrites `$amountPence` from the pledge row, so the
throwaway value never reaches the provider.

### Q5 — pledge/checkout currency mismatch

`/payments/checkout` always charges `payments.currency` (one site-wide
currency), but `tblProject.currency` is set per-project. A pledge on a
EUR-denominated project would, if charged, be billed `payments.currency`
(e.g. GBP) at face value — a pre-existing quirk that #430's pledge-amount
forcing doesn't address (it forces the AMOUNT, using
`pledge['currency'] ?? $currency`, but `Payments::startCheckout()` still
ultimately hands the provider whatever `$currency` ends up as). The v1
mitigation shipped here is minimal: `my-pledges.php` only renders the "Pay
now" button when `project.currency === payments.currency`; a currency
mismatch hides the button rather than risking a wrong-currency charge.
Proper multi-currency checkout is a separate, larger issue.
## Venue Bookings (#429)

### Wall-clock rule (make-or-break)

`tblVenueBookings` times are wall-clock VENUE-local (`DATE` + `TIME`, never
converted to UTC — a 09:30 hire stays 09:30 across DST). `tblEvents`
datetimes are wall-clock EVENT-local in `timezone`/`eventTimezone`.
`Venues::classifyEventCoverage()` therefore compares wall-clock to
wall-clock: identical IANA zones ⇒ direct comparison, NO conversion;
differing zones ⇒ convert event-zone → venue-zone via `DateTimeImmutable`.
UTC never appears in this path. Never "fix" either store to UTC.

**Follow-up issue #435 (fixed):** `tblEvents.*DateTime` column comments used
to say "stored in UTC" — that was wrong. Events are stored wall-clock
event-local (proof: `calendar/manage/save.php` binds the raw POST value
verbatim; `calendar/event.php` reads it back in the event's own zone;
`Ical.php` emits a `TZID`, never a trailing `Z`). Corrected the `startDateTime`/
`endDateTime` `COMMENT`s in `full_schema.sql` and their source migration
(`008_calendar_events_schema.sql`) to `'... wall-clock local (venue/site
local); NOT UTC'` — documentation only, no data change, no rebuild
migration (see "Datetime handling" note below).

### Datetime handling (#435)

`tblEvents.*DateTime` (and every other wall-clock date/time column in this
codebase — `tblVenueBookings`, etc.) store **wall-clock local** (venue/site
local), **NOT UTC**. Compare wall-clock-to-wall-clock; convert only across
differing IANA zones (`DateTimeImmutable`), never to/from UTC as an
intermediate step.

### Native XLSX parser caps (no Composer)

`Venues::parseWorkbook()` reads `.xlsx` via `ZipArchive` + `SimpleXML` with
hard caps: upload size ≤ `venues.maxFileSize` (10 MB fallback when unset),
≤ 200 zip entries, ≤ 20 MB per entry (checked pre-extraction), only the
workbook/rels/sheets/sharedStrings parts are read, `LIBXML_NONET` always
set and `LIBXML_NOENT` never set, 5,000 rows/sheet, 500 chars/cell. Excel
serial dates are converted explicitly rather than trusted from cell
formatting. `ZipArchive` availability isn't guaranteed on every shared host,
so the import wizard checks `class_exists('ZipArchive')` up front and
degrades to CSV-only with a visible notice when it's missing — the CSV path
never depends on `ZipArchive` at all.

### Scheduler

Add alongside the other cron lines (token in `venues.cron_token`, seeded
empty — the endpoint is inert until an admin sets a real value, same
pattern as `discipleship.cron_token` / `assets.cron_token`):

```
https://<your-portal-host>/cron/venue-reminders?key=<your-token>   (daily)
```

The route (`cron/venue-reminders`, migration 170) is seeded
`isProtected = 0` — public but token-gated, exactly like
`cron/asset-reminders`. It loops every distinct `siteID` with at least one
active venue, skips sites with `venues.enabled` or
`venues.reminders_enabled` off, and runs three sweeps per site (un-agreed
bookings, agreement renewals, invoices due) with a `(refType, refID,
dueDate)` dedupe key so a re-scheduled item naturally re-reminds without
spamming on every run.

### Per-event venue/room links + room-aware coverage (gap #436, migration 179)

Additive follow-up: `tblEvents` gains two optional nullable columns
(`venueID`, `roomID`, FKs `ON DELETE SET NULL`) so an event can declare
which hired venue — and optionally which room within it — it's actually
held at, upgrading the calendar manage form's venue picker from a
transient advisory-only check into a **persisted** link. See the
`full_schema.sql` fold pattern subsection above ("an ALTER's FK target is
created LATER in the file") for why the FKs live only in the migration,
not the fold.

- **`classifyEventCoverage(array $event, int $venueId, ?int $roomId =
  null)`** — the `$roomId` param is optional and trailing specifically so
  both pre-#436 call sites (`venues/api/check.php`, `calendar/manage/
  save.php`) compile and behave **identically** unchanged when they don't
  pass it. When set, it's re-validated site+venue-scoped via the existing
  private `validateRoomForVenue()` (no new query) — an unresolvable room
  (wrong venue/site, deleted, `<= 0`) silently degrades to venue-level
  coverage rather than erroring, the same "no existence oracle" posture as
  the pre-existing venue-missing sentinel.
- **The room filter runs on `availabilityForRange()`'s existing output** —
  that query already selects `b.roomID` (needed by the calendar strip
  tooltips), so no schema/query change was needed to make coverage
  room-aware, only a PHP-side `array_filter()` on rows already in hand:
  `roomID IS NULL` (whole-venue booking) or `roomID = $roomId` survives the
  filter; everything else (including that OTHER room's own
  `unavailable`/`closed` rows) is excluded — a Room B "unavailable" row
  must never mark a Room A event unavailable, and vice versa a whole-venue
  `unavailable` row still blocks every room.
- **New verdict `COVERAGE_ROOM_NOT_COVERED`** (`'room-not-covered'`,
  severity `danger`) fires when the room-filtered rows have nothing to say
  (no filtered row reached an earlier cascade branch) but the *unfiltered*
  day's rows include a confirmed bookable hire — i.e. the venue is
  genuinely booked that day, just not for this room. Slotted into
  `WORST_ORDER` immediately after `COVERAGE_NO_BOOKING`; unreachable when
  `$roomId` is null, so its insertion cannot change any existing
  (roomless) call's worst-case ordering.
- **Write-path invariant:** `roomID` never survives without a `venueID` it
  validated against — `calendar/manage/save.php` always re-validates the
  room against the *posted* venue (`Venues::getRoom($room, $venue,
  $site)`), so changing an event's venue automatically drops a stale room
  from the old venue (the room-belongs-to-venue check simply fails against
  the new one). The DB-level `ON DELETE SET NULL` FK only covers the
  narrower case of the room itself being deleted.
- **Disabled-app symmetry, both directions:** render-side, the picker sits
  entirely inside the pre-existing `$hasVenueCheck` guard (empty
  `$venueOptions` ⇒ no venue/room markup at all — the #429 resilience
  pattern, unchanged). Write-side, `save.php`'s UPDATE **omits**
  `venueID`/`roomID` from its `SET` list entirely when the Venues guard
  isn't active, rather than writing NULL — toggling the app off and
  editing an event must preserve that event's existing link, not silently
  erase it.
- **Bug fixed in the same PR:** the event-form's live "is it booked?" JS
  posted `startDateTime`/`endDateTime`/`timezone`, but `venues/api/
  check.php` has always read `start`/`end`/`tz` (its own documented
  header). Every live check 400'd on `invalid-range` and the JS's
  `.catch()` silently hid the alert — Surface A never actually worked
  since #429 shipped. Canonicalised on `check.php`'s existing contract
  (unchanged) and fixed the JS to match, adding `roomID` to the same
  request.

## Giving — Bulk year-end statements (gap #4, #440)

Treasurer-only "Annual statements" page at `/giving/statements`: pick a
period (calendar year default, free from/to fields, one-click UK-tax-year
preset), preview per-donor totals + Gift Aid eligible sums, then generate
PDFs, download them all as a ZIP, or email each donor their own statement.

### Renderer reuse (the whole point of this feature)

`Portal\Core\Giving::renderStatementPdf(int $siteId, int $donorId, string
$fromDate, string $toDate, string $periodLabel): string|false` is the
SAME function called by both the self-service page (`giving/my-
statement.php`) and the bulk batch (`giving/statements-generate.php`) —
their output is byte-identical by construction, not by convention. Three
deliberate upgrades landed on this shared function (so both callers get
them for free):

1. **Donor lookup is site-scoped** — a donor renders only if they are a
   currently-active member of `$siteId` OR have at least one giving entry
   recorded under `$siteId` (an `OR` of two `EXISTS` clauses, not a plain
   `INNER JOIN tblUserSites`). The first arm is the normal case (including
   a member who has never given, which the old — unscoped — query also
   allowed); the second arm lets a treasurer pull a single historical
   statement for a donor who has since left the site without reopening
   the original "any userID renders for any site" hole. A donor matching
   neither arm can never render, regardless of which site's treasurer
   asks.
2. **Gift Aid eligible column + summary** — per-entry eligibility via the
   existing declaration-window rule (`validFrom <= donatedAt AND (validTo
   IS NULL OR validTo >= donatedAt) AND status = 'active'`), computed with
   a correlated `EXISTS`, never a `JOIN` against `tblGiftAidDeclaration` —
   an overlapping pair of active declarations can never double-count a
   single entry, mirroring `buildHmrcCsv()`'s own known JOIN hazard.
   Statement shows "Total given" and "of which Gift Aid eligible"
   — deliberately **no** projected 25% reclaim figure; that belongs to the
   charity's own HMRC CSV claim, not a donor-facing estimate.
3. **Output path namespaced by siteID + periodKey** —
   `_uploads/giving/statements/{siteID}/statement-{donorID}-{periodKey}.pdf`
   (`periodKey` = `Giving::statementPeriodKey()`, `"{from}_{to}"`). Fixes
   the old flat `statement-{donor}-{year}.pdf` naming, which let the same
   donor in two sites overwrite one file with the other site's data.

### Batching (Newsletter dispatch pattern, copied deliberately)

DreamHost shared FastCGI can kill a long-running request regardless of
`set_time_limit()` (`admin/maintenance/offsite-backup-run.php` documents
this in its own header). `Giving::renderStatementBatch()` and the email
handler both cap their own work at `giving.statements.batchPerRun`
(default 25) per invocation — exactly `Newsletter::dispatch()`'s
`newsletter.batchPerHour` shape. Re-POST the same form ("Generate
statements" / "Email statements") to continue a larger run; the flash
message tells you how many remain.

### Dedupe / sent-log

One `tblGivingStatementLog` row per `(siteID, donorID, periodKey)`
(`UNIQUE` key) — the row IS the run state: `pdfPath` set = rendered,
`queuedAt` set = an email run has started, `emailedAt` set = sent (the
dedupe fence — every email selector adds `emailedAt IS NULL`, so a
completed row can never be re-picked without the explicit "Resend to
already-emailed donors" checkbox, which clears `emailedAt`/`errorMsg`
first and is audit-logged as `GivingStatementsResend`). `errorMsg` set on
a failed generate or send is never auto-retried — the preview page's
per-row "Retry" link (routes back through `statements-generate.php` with
`action=retry`) just clears it, re-entering the row into the normal next
batch.

### Opt-out

New `notifyPrefs` key `givingStatements` (default **on**, like every
other key) — whitelisted in `auth/account/notifications-save.php`, a
switch on `/account/notifications`. Enforced at BOTH queue time (the
preview page's "opted out" badge, and the email handler's queue-eligible
selector reads it indirectly via `Giving::sendStatementEmail()`) AND send
time (`Giving::sendStatementEmail()` re-decodes `notifyPrefs` fresh from
`tblUsers` immediately before sending — never trusts whatever was true
when the row was queued).

### Attachment integrity

`Mailer::attach()` silently DROPS any file over 4 MB rather than erroring
— a statement PDF is tens of KB, but `Giving::sendStatementEmail()` still
checks `filesize()` explicitly and marks the row `errorMsg = 'pdf-too-
large'` rather than ever letting a bodiless email go out.

### Optional cron sweeper

```
https://<your-portal-host>/cron/giving-statements?key=<your-token>   (every 15 min)
```

Token in `giving.cron_token`, seeded empty — the endpoint is inert until
an admin sets a real value (same pattern as `venues.cron_token` /
`assets.cron_token` / `discipleship.cron_token`). Unlike the venue/asset
reminder crons, this one is NOT required for a typical site — the
interactive "Email statements" button finishes a modest donor list in one
or two clicks. It exists for a larger site whose queue would otherwise
need many manual re-triggers: it sweeps every site's queued-but-unsent
rows in one pass (`giving.statements.batchPerRun × 4` per invocation),
calling `Site::forceContext()` per row purely so error logging attributes
correctly, and reading per-site settings (`giving.charityName` etc.) via
`App::settingForSite()` rather than the frozen `$SETTINGS` bootstrap
snapshot — the same cross-site-loop correctness rule `cron/venue-
reminders.php`'s header documents.

### GDPR erasure

`tblGivingStatementLog` rows are left alone by an erasure (donorID is
`NOT NULL` there, and the run history is retained like `tblGivingEntry`'s
own HMRC-adjacent 6-year rationale) — but `GdprEraser::execute()` now
also unlinks the erased user's rendered statement PDF files from disk
(`_uploads/giving/statements/*/statement-{userID}-*.pdf`) via a small,
filesystem-only `eraseGivingStatementFiles()` step: a rendered PDF is a
name-bearing document with no retention duty once its subject is erased,
even though the underlying ledger entries stay anonymised-in-place.

### User reminders cron (#439, migration 171)

Token in `user_reminders.cron_token`, seeded empty + `isSensitive = 1` — set
a real value at `/admin/settings` before adding the crontab line, same
empty-fails-closed pattern as every other cron token in this app. Add
alongside the other cron lines — this one needs **15-minute** granularity
(unlike the daily venue/asset sweeps) because the task family compares
against a DATETIME the user picked deliberately:

```
*/15 * * * * curl -fsS "https://<your-portal-host>/cron/user-reminders?key=<your-token>" > /dev/null
```

The route (`cron/user-reminders`, migration 171) is seeded
`isProtected = 0` — public but token-gated. It loops every distinct
**active site** (not pre-filtered to "owns a candidate row" — with three
unrelated families that pre-filter would be noisier than a few cheap empty
result sets) and runs three families per site, each gated by its own
per-site flag (`tasks.reminders_enabled` / `rota.reminders_enabled` /
`milestones.digest_enabled`, all default ON, read via
`App::settingForSite()` inside the loop):

- **task-reminder** — `tblTasks.reminderDate <= NOW()`, capped to the last
  `tasks.reminder_lookback_days` (default 7) so first activation on an
  install with years of stale `reminderSent = 0` rows doesn't blast every
  overdue task in one run. Dedupe is an atomic claim on the existing
  `tblTasks.reminderSent` flag column (migration 036) — no new log table.
  Honours the new `taskReminders` notification preference (default on).
- **rota-slot** — `tblRotaSlot` rows due within
  `rota.reminder_days_before` (default 3, migration 074) with
  `reminderSentAt IS NULL`. One grouped email per assignee per run listing
  every due duty; dedupe still claims each slot individually
  (`reminderSentAt = NOW() WHERE reminderSentAt IS NULL`). Honours the new
  `rotaReminders` notification preference (default on).
- **milestone-digest** — once per day, 06:00-08:59 server time (matches
  `cron/event-reminders.php`'s own day-of window). Sends today's
  birthdays/anniversaries to the roles listed in
  `milestones.digest_recipients` (migration 076) — **an empty recipients
  CSV skips the site entirely** (explicit opt-in only; no silent fallback
  to admins for birthday data). Dedupe uses the new generic
  `tblUserReminderLog` table (`(refType, refID, dueDate)` unique key,
  check-first + race-catch on the concurrent-run case) since
  `tblUserMilestone` carries no sent-flag column of its own — the table is
  deliberately generic so a future single-shot family (e.g. DBS expiry,
  deferred) can reuse it with zero DDL.

Also repaired in the same PR: `cron/event-reminders.php` was selecting
`u.email` from `tblUsers` — the real column is `emailAddress` — so under
this app's strict mysqli reporting the very first `prepare()` threw and the
event-reminder cron 500'd on **every** invocation. Fixed to
`u.emailAddress AS email` throughout; the three other `u.email` call sites
found during this work (`calendar/event-broadcast-send.php`,
`admin/calendar/coordinators.php`, `admin/safeguarding/dbs.php`) are
tracked separately in #438, not touched here.

## Service-Plans ↔ Worship additive bridge (gap #6, #442, migration 173)

### The two-model situation

Two independent "service plan" data models exist and always have —
neither was ever aware of the other:

| | Run-sheet builder (#262/#300) | Worship presentation (#308/#355) |
|---|---|---|
| Tables | `tblServicePlan` (SINGULAR) + `tblServicePlanItem` + `tblServicePlanMessages` | `tblServicePlans` (PLURAL) + `tblServicePlanItems` + `tblServicePlanState` + `tblCcliUsage` |
| Born in | migration 089 (+110, +154) | migration 137 (+138, +139) |
| Surface | `/service-plans` (login-only, no coordinator ACL) | `/worship` (admin-or-coordinator write ACL, driven by `eventID`) |
| `eventID` | Schema-only — **no handler in `_apps/service-plans/` ever writes it** (write-dead since migration 089) | Live and ACL-bearing — `plan-save.php`'s `$gate` closure derives write access from it |

Migration 154's own header calls the two "unrelated" — that stays true of
the DATA (no merge, no rename, ever — see "Explicitly out of scope"
below). Gap #6 adds ONE optional, additive cross-reference so a worship
plan can declare which run-sheet it presents.

### The bridge column

`tblServicePlans.runSheetPlanID` INT NULL, UNIQUE (`uq_plans_runsheet`),
`FOREIGN KEY … REFERENCES tblServicePlan(planID) ON DELETE SET NULL`
(migration 173). NULL = unpaired — the state of every row that existed
before this migration; nothing is backfilled in bulk.

**Why the column lives on the worship side, not the run-sheet side:** in
`full_schema.sql`, `tblServicePlan` (the run-sheet, migration 089) is
created ~2,200 lines before `tblServicePlans` (the worship plan, migration
137) — the FK folds inline into the child's CREATE, honouring the file's
"FK ordering respected" guarantee. The reverse direction would need an
out-of-order ALTER with zero precedent (every column added to an
already-created table in this file is folded inline into that table's own
CREATE, never a standalone ALTER — see migration 110's `startedAt`/
`closedAt` on `tblServicePlan` itself, or 138's `displayToken` on
`tblServicePlans`). This also means pairing only ever mutates a
`tblServicePlans` row, so that app's stricter existing write-ACL governs
pair/unpair for free — the ACL-free run-sheet model is never written by
the bridge except the one guarded exception below.

### Source-of-truth table (no field sync in v1 — Q4, decided)

| Data | Owner | The other side shows… |
|---|---|---|
| Programme order, sections, presenters, durations, AV notes, serviceDate, print | Run-sheet (`tblServicePlan`) | a read-only summary |
| Slides, canonical songs (`songID` → `tblSongs`), lyrics, projector state, displayToken, CCLI | Worship (`tblServicePlans`) | a read-only summary |
| The pairing itself | `tblServicePlans.runSheetPlanID` — single physical record, no mirror column | resolved by reverse lookup (`ServicePlanLink::runSheetForWorshipPlan()`) |
| Event binding | Worship's `eventID` is authoritative where both are set; the run-sheet's may be backfilled from it at pair time ONLY (never the reverse) | — |

The two item representations are structurally incompatible — Model A uses
a free-text `title` per section (`sectionType='song'` + "Hymn 256 — Amazing
Grace"); Model B uses a canonical `songID` FK into `tblSongs`. Any
automatic sync needs fuzzy title↔song matching with a real false-positive
risk, so v1 ships zero sync — the counterpart panels are read-only
visibility only. A one-shot, user-triggered "copy sections → slides"
button is the deliberate v2 candidate.

### Pairing invariants (`Portal\Core\ServicePlanLink`)

All enforced in `ServicePlanLink::pair()`/`unpair()` — mechanism only, NOT
the ACL decision (that's the caller's job, see below):

1. **Same site (hard).** Every row is loaded `siteID = Site::id()`-scoped;
   a foreign-tenant planID simply resolves to "not found", never a
   mismatch that needs its own error path.
2. **Same event (hard, when knowable).** Refused only when BOTH sides
   declare an `eventID` AND they differ. Either side NULL (the run-sheet's
   is NULL on every row today) always proceeds.
3. **1:1 (hard).** A run-sheet already claimed by a DIFFERENT worship plan
   is refused with a friendly flash naming the other plan. The UNIQUE key
   is the concurrency backstop — a mysqli errno 1062 on the pairing UPDATE
   is caught and turned into the same friendly refusal, never a fatal 500.
   Re-pairing a worship plan's own existing link (or the same pair again)
   is allowed — it's an overwrite of the row already being edited (Q6,
   decided).
4. **eventID backfill (soft, one-directional, NULL-only — Q1, decided:
   yes).** At successful pair, if the run-sheet's `eventID IS NULL` and the
   worship plan's is not, the run-sheet's dormant `eventID` is set from
   it. **Never the reverse** — writing the worship side's `eventID` would
   silently grant that event's coordinator write access to the plan
   (`plan-save.php`'s `$gate` closure derives from it); that column is
   ONLY ever set through the worship app's own explicit, authorised
   `save-metadata` flow.
5. **Unpair never touches the run-sheet row** — a prior eventID backfill
   stays; it's true information about the run-sheet regardless of whether
   the pairing that revealed it still exists.

### ACL — who may pair/unpair

Pairing mutates a `tblServicePlans` row, so `worship/plan-link.php` reuses
that app's existing write gate **verbatim** (copied, not refactored out of
`plan-save.php`): admin, OR `Auth::isCoordinatorOf($plan['eventID'])` when
the plan is event-bound; free-floating template plans are admin-only. CSRF
(`Auth::verifyCsrf`) + POST-only, matching `plan-save.php`'s own pattern.
UI-level control visibility is cosmetic only:

- **Worship editor panel** — controls show when the page's own `$canWrite`
  is true (same variable the rest of `plan.php` already gates on).
- **Run-sheet editor panel** — controls show for `App::isAdmin()` only
  (Q2, decided). The run-sheet app has no coordinator concept to check
  per-candidate without an extra query per row in the picker list; v1
  keeps that side admin-only. Non-admins still SEE the read-only summary
  when a pairing exists.

The handler re-checks the real gate regardless of which page's UI a POST
came from — a forged request bypassing the picker's visibility rule still
hits the same 403.

### Resilience — the venue-overlay precedent, again

Both counterpart panels (`service-plans/edit.php`, `worship/plan.php`)
follow the exact `calendar/index.php` venue-overlay pattern: guard the
whole resolve behind `AppRegistry::isEnabled('<counterpart-slug>')`, then
wrap the `ServicePlanLink` call in `try { … } catch (\Throwable $e) {
error_log(...); $link = null; }`. This means a disabled counterpart app, a
deploy where the migration hasn't landed yet (mysqli throws "unknown
column" under this repo's strict reporting), or any other resolver
exception leaves the panel silently empty — the page never breaks, and
neither existing surface's own queries changed at all.

### Explicitly out of scope (v2+ candidates)

- Paired badges on the two list pages (`service-plans/index.php`,
  `worship/plans.php`) — Q3, deferred; a 10-line follow-up (one extra LEFT
  JOIN each).
- Any field sync, in either direction — Q4, deferred; see the
  source-of-truth table above.
- Confidence-monitor (`live.php`/`confidence.php`) surfacing the worship
  operator's current slide via the paired plan's `tblServicePlanState`.
- Any rename or merge of the two confusingly-similar table names — never,
  or only in a major version with a compatibility view. Permanently out of
  scope for this gap item; the additive bridge is the whole point.

---

## Workflow Execution Engine + Generic Approvals Inbox (#443)

Migration 034 shipped four tables (`tblWorkflows`, `tblWorkflowSteps`,
`tblWorkflowInstances`, `tblWorkflowActions`) and an admin definition CRUD at
`/admin/workflows`, but nothing anywhere ever started, advanced, completed,
or timed out an instance. Migration 174 adds four additive
`tblWorkflowInstances` columns (`subjectLabel`, `contextJson`,
`currentStepStartedAt`, `outcome`) + one composite index
(`idx_wfi_site_status`) + one `tblWorkflowActions.action` enum value
(`'commented'`) — no redesign, no new tables — and this PR builds the engine
on top: `Portal\Core\Workflow` (`web/_core/Workflow.php`), a generic
`/approvals` inbox app, a token-gated hourly timeout cron, and exactly ONE
wired reference consumer (announcement publish approval).

### The state machine

A *definition* (`tblWorkflows` row + ordered `tblWorkflowSteps`) instantiates
into a `tblWorkflowInstances` row via `Workflow::start($definitionKey,
$subjectTable, $subjectId, $startedById, $subjectLabel, $context)`, which
walks `currentStep` (a **stepOrder** value, not a stepID) through the step
sequence via `Workflow::act($instanceId, $stepId, $actorId, $decision,
$comment)`, records one `tblWorkflowActions` row per decision, and
terminates with `status='completed'|'cancelled'` + the new `outcome` column
(`'approved'|'rejected'|'cancelled'`). `Workflow::cancelForSubject(...)`
closes an active instance out-of-band (e.g. the subject record was
deleted). `Workflow::timeoutSweep($siteId)` is the per-site timeout pass the
cron calls.

**Atomicity** — every mutator opens `begin_transaction()`, `SELECT … FOR
UPDATE`-locks the instance row (site-scoped — the IDOR wall: a foreign
instanceID is deliberately indistinguishable from a missing one, both
return `'not_found'`), resolves the CURRENT step fresh
(`ORDER BY stepOrder ASC, stepID ASC LIMIT 1` — `tblWorkflowSteps` has no
unique key on `(workflowID, stepOrder)`, so this ordering is how the engine
deterministically handles the double-submit-race duplicate-order edge
everywhere), and claims the transition with a single `UPDATE …
WHERE instanceID=? AND currentStep=? AND status IN ('pending','in_progress')`
gated on `affected_rows === 1` — the exact discipline
`expenses/approve/save.php`'s row-lock + reconfirm and
`Payments::markPaymentSucceeded`'s guarded UPDATE already use. A losing
racer gets `'conflict'` and does nothing. `act()`'s posted `$stepId` must
equal the server-resolved current step — a mismatch is `'stale_step'`
(the form was rendered before someone else advanced the instance).
`start()`'s duplicate-active guard is a single conditional
`INSERT … SELECT … FROM DUAL WHERE NOT EXISTS (...)` gated the same way.

**A `'rejected'`/`'cancelled'` decision always terminates the instance,
never advances it** — `claimTransition()`'s next-step lookup only ever runs
for an `'approved'` outcome; any other terminal outcome skips it entirely
and completes the instance at the CURRENT step, regardless of whether a
later step exists. This closes a security-review finding (SEC-01): the
advance-vs-complete branch used to key off "does a next step exist" alone,
so a reject at step 1 of a multi-step definition would advance to step 2
and let a later approve publish the very thing that was just rejected. The
same gate covers a human reject in `act()`, a timeout auto-reject in
`timeoutAutoAct()`, and an `'auto'`-type step that rejects in
`processAutoSteps()` — all three pass `$actionWord === $terminalOutcome`
into `claimTransition()`.

`start()`'s two "didn't start" outcomes are distinguishable via its
`reason` return value — `'no_definition'` (nothing configured; the only
case a caller should fail open) vs `'duplicate_active'`/`'error'` (a
concurrent submit already has an instance running, or an unexpected
failure — treat exactly like an already-pre-checked "awaiting approval",
never fail open). See "Announcements wiring" below for why this mattered
(SEC-03).

**Authorisation lives INSIDE `act()`**, never trusted from the HTTP layer:
the actor must (a) be an active `tblUserSites` member of the instance's own
site, AND (b) either match the current step's assignee (role/user/group per
`tblWorkflowSteps.assigneeType`/`assigneeValue`) OR — when
`workflows.admin_override` is `'1'` (default on) — be a site admin for that
site (the same 4-tier test as `App::isAdmin()`, evaluated explicitly for
the *acting* user + the *instance's* site rather than the session/`Site::
id()` pair `App::isAdmin()` itself checks).

**Subject-adapter registry** — `Workflow::applySubjectEffect()` is a single
`match` on `workflowKey`, run **inside the same transaction** as the final
approval's atomic claim. v1 ships one arm: `'announcement_publish'` flips
`tblAnnouncements.isPublished = 1` on outcome `'approved'` — so approval and
publish commit or fail together; there is no "approved but never
published" ghost, and no double-publish (the UPDATE is idempotent — a
re-run only matches 0 rows if the announcement was since soft-deleted, in
which case a `Logger::errorPlatform` Warning is logged but the workflow
still completes truthfully). Adding a second wired consumer later is one
new `match` arm here + one `Workflow::start()` call at that consumer's
submit point + one enable flag — no other engine change.

**Timeout policy — escalate-only unless a step opts in.** `timeoutSweep()`
NEVER auto-approves/auto-rejects on a bare timeout. Only a step whose
`autoAction` is explicitly `'approve'` or `'reject'` gets auto-decided
(`actedByID = NULL`, comment `"Auto-<word> after Nh timeout"`); a `NULL` or
`'escalate'` `autoAction` only escalates — one `'escalated'` action row per
`(instanceID, stepID)` (deduped, locked so two overlapping sweep runs can't
double-escalate) plus an email to the site's admins and the step's own
assignees, with the instance left active awaiting a human. The seeded
`announcement_publish` step ships `timeoutHours=72, autoAction=NULL`
(escalate after 72h, never auto-decide).

### Why the seeded `expense_approval` workflow stays dormant

Migration 034 seeded ONE `tblWorkflows` row (`expense_approval`, zero
steps) that nothing has ever called `start()` on. It stays exactly that way
— Expenses already has its own complete, independent, department-scoped
multi-approver system (`tblExpenseClaimApprovals`,
`expenses/approve/save.php`'s own `FOR UPDATE` + mandatory-approver logic).
Mirroring or driving Expenses through the generic engine would double-write
approval state for zero benefit and risk destabilising a money path with
stronger semantics than the generic step schema expresses. The admin
`/admin/workflows` UI carries an info box saying so.

### `/approvals` — the generic inbox

New infrastructure app (`web/_apps/approvals/`, AppRegistry entry
`web/_core/apps/approvals.php`, default-ON `approvals.enabled` — the inbox
itself is free to expose; the *consumer* flags that feed it stay
default-off per-consumer). Three sections, no `<table>`
(`portal-data-list` throughout):

1. **Awaiting your decision** — `Workflow::actionableForUser()`. Admins see
   every active instance for the site (with a Mine/All toggle); everyone
   else only ever sees rows they're actually eligible to act on. This is
   **display-only filtering** — `Workflow::act()` re-checks authorisation
   independently on every POST, so a stale/forged row in the DOM can never
   grant an action the engine wouldn't otherwise allow.
2. **Action controls** — one CSRF'd POST form per row to `/approvals/act`
   carrying hidden `instanceID` + `stepID` (the stale-form guard token),
   Approve/Reject/Comment-only buttons. `approvals/act.php` is transport
   only — CSRF check, input whitelist, call `Workflow::act()`, translate
   the result to a flash message. No authorisation logic in the handler.
3. **History** — last 50 completed/cancelled instances (non-admins: only
   ones they started or acted on), each row expandable (Bootstrap collapse)
   to the full `Workflow::actionsForInstance()` timeline.

### Timeout cron

`cron/workflow-timeouts.php` (hourly external cadence — `timeoutHours`
granularity is hours) — token-gated exactly like `cron/user-reminders.php`:
`?key=` vs `workflows.cron_token`, `hash_equals()`, **empty stored token
always 403s** (seeded empty + `isSensitive=1`). Global kill-switch
`workflows.enabled` read once before the per-site loop (genuinely global,
like the token itself); everything else is delegated to
`Workflow::timeoutSweep($siteId)` inside the standard
`Site::forceContext($siteId)` try/catch-continue loop.

### Announcements wiring (the reference consumer)

Per-site flag `workflows.announcements.enabled` (default `'false'`).
**Flag off ⇒ not one line of today's behaviour changes** for any of the
three write paths below. Flag on:

- **Create** (`announcements/save.php` AND `announcements/api/create.php`):
  posting `isPublished=1` (the API's own default) withholds the flag
  (`isPublished` forced to 0 before the INSERT) and calls
  `Workflow::start(...)` instead.
- **Update** (`announcements/save.php` AND `announcements/api/update.php`):
  only a genuine **publish request** (posted `isPublished=1` AND the stored
  row is currently `isPublished=0`) triggers the same withhold-and-start.
  Editing an already-published row, or unchecking Published (retract),
  passes through completely unchanged — no approval needed to edit or
  unpublish in v1.
- **The REST write API is gated identically to the HTML form** (closes
  SEC-02): `api/create.php` and `api/update.php` used to write
  `isPublished` straight to the database with no workflow gate at all,
  regardless of this setting — a caller with `announcements:write` scope
  could publish past a running approval process entirely via the API. Both
  handlers now `require_once` the same `announcements/_workflow-gate.php`
  helper `save.php` uses, so all three write paths share ONE implementation
  of the gate rather than three that could drift out of sync again. The API
  handlers read the flag via `App::settingForSite()` rather than
  `Settings::get()`'s ambient snapshot, since a bearer-key request's
  tenant site can differ from the host-detected site the snapshot was
  frozen for.
- **Fail-open** (deliberate, not a security boundary): if
  `Workflow::start()` reports `reason === 'no_definition'` (nothing
  active/steppable configured for the site), the gate helper publishes
  directly anyway and logs a `Logger::errorPlatform` Warning
  (`WF_MISCONFIG`) so admins see the misconfiguration in `/admin/errors` —
  a half-configured gate must never silently block every publish on a
  site. Any OTHER reason an instance didn't start — an instance already
  active for this subject (checked up front via
  `Workflow::activeInstanceForSubject()`, and again via `start()`'s own
  `'duplicate_active'` result if a concurrent submit won that race in
  between), or `'error'` — gets the same "already awaiting approval"
  response and NEVER publishes directly. This distinction (SEC-03) matters
  precisely because of that race: two near-simultaneous submits could both
  pass the up-front check, one wins `start()`'s atomic insert and a real
  approval begins, and the loser used to be indistinguishable from "gate
  misconfigured" — so it fell through the fail-open branch and published
  the subject anyway, right past the instance its twin had just started.
- `announcements/delete.php` calls `Workflow::cancelForSubject(...)` after
  the soft-delete so a deleted announcement never lingers in anyone's
  inbox.
- `announcements/manage.php` relabels the Published checkbox to "Publish
  (requires approval)" and shows an "Awaiting approval" badge (linking to
  `/approvals`) on any unpublished row with a running instance.

### Admin CRUD completion

`/admin/workflows` gained the three pieces migration 034's CRUD was always
missing: per-step **Delete** (new `admin/workflows/step-delete.php` —
site-ownership-checked, refuses while the workflow has any active
instance, and catches the `tblWorkflowActions.stepID` RESTRICT FK as a
friendly "this step has recorded history" refusal rather than a 500), an
**isActive** toggle on the workflow header form, and an **autoAction**
select on the Add Step row (feeds the timeout policy above). Step
*reorder* is deliberately out of scope — delete + re-add reaches the same
place, since steps are ordered by `stepOrder` and the existing add-step
logic already appends at `MAX(stepOrder)+1`.

### Extending the engine — adding consumer #2

1. Seed a new `tblWorkflows` definition (+ steps) for the target
   `workflowKey`, gated behind your own per-site enable flag.
2. At your consumer's submit point: check the flag, and when on, call
   `Workflow::start($yourKey, $yourTable, $yourId, $userId, $label,
   ['url' => $viewUrl])` instead of writing your "approved" state directly.
   It returns `['instanceId' => ?int, 'reason' => ?string]` — fail OPEN
   (act directly) only when `reason === 'no_definition'`; treat
   `'duplicate_active'` and `'error'` exactly like your own pre-checked
   "already awaiting approval" case (fail CLOSED — never act directly). Do
   the same up-front `Workflow::activeInstanceForSubject()` check
   `announcements/_workflow-gate.php` does before calling `start()` at all —
   see SEC-03 above for why both matter.
3. Add one `case '$yourKey':` arm inside
   `Workflow::applySubjectEffect()` for the `'approved'`/`'rejected'`/
   `'cancelled'` side effects. That's the entire integration surface — no
   other engine change.

`'review'` step type behaves identically to `'approval'` in v1 (both block
awaiting a human); the enum distinction is reserved for a later phase. The
`delegate` decision verb is deferred to v2 (needs target-picking UI + its
own authorisation surface) — v1 ships approve/reject/comment only.

---

## Web Push setup (#322)

`Portal\Core\WebPush` (`web/_core/WebPush.php`) sends VAPID (RFC 8292)
signed, RFC 8291 aes128gcm-encrypted browser push notifications for "we're
live now" and upcoming-service reminders. Migration 111 seeded the
`push.*` settings keys empty; migration 177 seeded everything else
(TTLs, auto-notify toggles, the SSRF host allowlist, the two
`api.push.*.enabled` ApiRouter flags, four route rows) — **no key
material**. The feature is **INERT** on every fresh install until an
owner completes the three steps below; `WebPush::isConfigured()` gates
every send path, the client subscribe UI, and both cron jobs, so an
unconfigured install's cron runs are cheap, harmless no-ops.

### 1. Generate or import a VAPID key pair

At `/admin/integrations/push`, either:

- Click **"Generate new key pair"** — one click, no OpenSSL needed. The
  private key is sodium-encrypted at rest via the house
  `encrypt_setting()` helper and is **never** re-displayed once saved
  (the page shows only a "configured ✔" badge and an 8-hex fingerprint of
  the *public* key — the private key precedent set by
  `admin/integrations/cloudflare-stream`'s signing key).
- Or generate a key pair yourself and paste it into the **"Import"** box.
  Either a full PEM:

  ```sh
  openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem
  cat vapid.pem   # paste the WHOLE file contents into the import box
  ```

  or the bare base64url 32-byte private scalar some tools (e.g. the
  `web-push` npm CLI's `generate-vapid-keys`) export standalone —
  `WebPush::importPrivateKey()` accepts either shape and derives the
  matching public key itself (validated in `tools/webpush-selftest.php`:
  ext-openssl reconstructs the public point from the private scalar alone
  at parse time, so no explicit public-key DER field is needed).

Then set a **contact** (`mailto:` or `https:` — RFC 8292 §2.1 requires
one; a push service uses it to reach you if this install starts
misbehaving) and tick **"Enable Web Push"**.

**Regenerating breaks existing subscriptions.** A real push service
validates that the sender's VAPID public key matches the one the
browser's `pushManager.subscribe()` call used — clicking "Generate" again
is a fresh start, not a rotation. Existing subscribers silently stop
receiving pushes until they revisit the portal and re-subscribe (the
`/account/notifications` and `/live` opt-in widgets re-run
`getSubscription()` on every page load, so this self-heals the next time
each subscriber's browser is online — there's no need to chase anyone
down).

### 2. Point the crontabs at the two push endpoints

| Endpoint | Cadence | Token setting |
| --- | --- | --- |
| `cron/push-golive?key=<token>` | every 5 minutes | `push.cron_token` |
| `cron/event-reminders?key=<token>` | every 15 minutes (already required for #329; Web Push now rides its 1h window) | `reminders.cron_token` |

Both tokens seed empty + `isSensitive=1` — set a real value at
`/admin/settings` (or paste one at `/admin/integrations/push` if a future
build adds that field) or the endpoint 403s forever (empty-fails-closed,
the six-of-six `cron/*` convention). `push.cron_token` is DEDICATED —
don't reuse `reminders.cron_token` — so the two endpoints can be rotated
independently.

`cron/push-golive.php` only does anything when its own settings are ALSO
turned on (both default OFF): `push.golive.auto` (auto-detect a schedule
window opening, deduped once per channel per day via
`tblUserReminderLog`) and `push.reminders.broadcast` (anonymous "starting
soon" broadcast to the whole `reminders` channel when the next scheduled
stream is within 60 minutes). The PRIMARY go-live trigger doesn't need
this cron at all — it's the manual **"Send 'We're live' notification"**
button on `/admin/livestream` and the Host Console, which fires
synchronously the moment an admin clicks it.

A channel with two separate live windows in one day needs the manual
button for the second window — the auto-detect dedupe key is
`(refType='push-golive', refID=channelID, dueDate=today)`, once per
CHANNEL per DAY by design (issue #322's decided default; revisit only if
a real site genuinely runs two auto-notified streams in a single day).

### 3. Verify it end-to-end

1. `/admin/integrations/push` → the banner should flip from amber
   ("Inert") to green ("Configured and active") once steps 1-2 are done.
2. `/account/notifications` (or `/live`) in Chrome/Firefox → the "Push
   notifications" card renders a working "Enable notifications on this
   device" button (it stays hidden entirely, with no button at all, while
   `WebPush::publicKey()` returns `''`) → click it, grant the browser
   permission prompt → a row appears in `tblPushSubscriptions` with the
   correct `siteID`/`userID`/`channels`.
3. `/admin/integrations/push` → **"Send test notification to my
   devices"** → a notification should arrive on that device within a few
   seconds; clicking it focuses an existing portal tab (or opens one) at
   the notification's target URL.
4. `/admin/livestream` with a channel currently inside its scheduled
   window → **"Send 'We're live' notification"** → the button is disabled
   with a tooltip explaining why whenever Web Push is unconfigured OR no
   channel is currently live.
5. Prune check: subscribe, then revoke notification permission for the
   site in the browser's own site settings, then send another test/live
   push → the push service returns 404/410 → the subscription row flips
   `isActive=0` + `lastHttpStatus` records the code → the admin page's
   subscription counts update on next load.

### Troubleshooting

**"401" / "403" from the push service, every single send** — almost
always one of two causes, both guarded by `tools/webpush-selftest.php`
(run it — `php tools/webpush-selftest.php` — before chasing anything
else):

- **Clock skew.** The VAPID JWT's `exp` claim is `now + 12h`; if the
  server's clock is badly wrong, every JWT looks expired (or not-yet-valid)
  to the push service. Check `date` on the server.
- **A broken DER→JOSE conversion.** `openssl_sign()` returns a DER
  `ECDSA-Sig-Value`; RFC 7518 §3.4 requires the JWS signature to be the
  RAW 64-byte `R‖S` concatenation instead. Shipping the DER bytes
  unconverted is THE classic implementer mistake for this exact protocol
  — every push service just returns 401/403 with zero diagnostic detail,
  because from its side the signature simply doesn't verify. If
  `tools/webpush-selftest.php` still passes after a refactor, this class
  of bug is ruled out; if it starts failing, this is almost certainly why.

**Nothing happens when I click "Enable notifications" on `/account/notifications`**
— open devtools → Application → Service Workers and confirm `/sw.js` is
registered and activated (footer.php registers it on every page load);
`push-subscribe.js` requires an active service worker registration before
it can call `pushManager.subscribe()`. Also check `Notification.permission`
isn't already `'denied'` from an earlier browser-level block — the widget
shows "Blocked in browser settings" in that case rather than a button.

**A legitimate push endpoint gets rejected at subscribe time** — the SSRF
host allowlist (`push.endpointHostAllowlist`, admin-editable at
`/admin/integrations/push`) is a suffix match against the endpoint's
host. A new browser vendor's push service host is not a code change —
just add it to the allowlist.

---

## MS365 Graph email via a shared mailbox (gap #234, migration 176)

### What was already there vs what #234 actually needed

`Mailer::sendViaGraph()` already did app-only client-credentials Graph
auth and posted to `/v1.0/users/{mail.defaultFromAddress}/sendMail` — i.e.
the portal was already sending through what is, mechanically, a shared
mailbox. What the issue's acceptance criteria actually needed was
**formalising and hardening** that path, not a new auth model: an
explicit shared-mailbox identity separate from the general from-address,
a `from` object in the payload (previously entirely absent — Graph
derived the sender purely from the URL mailbox), proper 401/429/403/404
handling, and a persisted send log. See
`.claude/plans/gap-items/234-shared-mailbox-plan.md` for the full
build-ready plan this session implemented from.

### Model decision: app-only, NOT delegated (recorded per the issue's Q1)

The issue body sketched a delegated `Mail.Send.Shared` model — a real
licensed user's OAuth token with Exchange "Send As"/"Send on Behalf"
permission on the shared mailbox. This was **deliberately not built**:

- It requires an entirely new interactive OAuth authorize/refresh-token
  flow, a new encrypted secret (the refresh token) with its own rotation
  lifecycle, and a dependency on a real human account whose password
  reset, MFA re-registration, or conditional-access policy change would
  silently kill portal mail — precisely the failure class the #227-era
  offboarding work exists to *cause* on purpose when a person leaves.
- Application `Mail.Send` (what was already in place) already lets the
  app send as any tenant mailbox with **zero new secrets** — it reuses
  `Mailer::accessToken()` verbatim. The "it's too broad" objection is
  answered by an **Application Access Policy** (or its RBAC-for-
  Applications successor) scoping the app to just the shared mailbox —
  see the runbook in `web/_apps/help/admin.php#ms365-shared-mailbox`.
- `sender` (the Graph field for send-on-behalf) is therefore never set
  anywhere on this path — only `from` is, which is what app-only send-as
  actually uses.

If a tenant ever refuses to grant application `Mail.Send` even with an
Application Access Policy, the delegated model remains a well-scoped
follow-up issue — nothing in this design blocks adding it later as a
second sub-mode alongside the shared-mailbox one.

### `effectiveSender()` — the one place the send identity is resolved

`Mailer::effectiveSender()` (private) is the single resolution point,
called only from inside `sendViaGraph()`:

1. `mail.ms365.sharedMailbox` non-empty AND `FILTER_VALIDATE_EMAIL`s →
   that address is BOTH the URL mailbox and the `from` address; display
   name from `mail.ms365.sharedMailboxName`, falling back to
   `mail.defaultFromName`.
2. Non-empty but invalid (someone hand-edited it via the generic
   `/admin/settings` editor, bypassing the save handler's own
   `FILTER_VALIDATE_EMAIL` check) — log `BAD_SHARED_MAILBOX` once via
   `Logger::errorPlatform()` and fall through to rule 3, so a typo can
   never take portal mail down entirely.
3. Empty (the seeded default) — `mail.defaultFromAddress`, byte-for-byte
   the pre-#234 behaviour. Returns `null` when that's empty too, and the
   caller throws `RuntimeException('From address missing')` exactly as
   before.

**Security-critical property:** `effectiveSender()` reads `$SETTINGS`
only — nothing on this path ever touches `$_POST`/`$_GET`. The ONLY
writer of `mail.ms365.sharedMailbox` is the CSRF'd, admin-gated
`admin/integrations/ms365-mail-save.php` handler. Grep-verify at any
future touch of this code: `grep -rn "ms365.*sharedMailbox" web/_apps/`
should show exactly one write site.

### Why the `from` object is added in BOTH modes (a deliberate, benign behaviour change)

Before #234, `Mailer.php`'s Graph payload had NO `from`/`sender` key at
all — Graph derived the sender purely from the URL mailbox, and
`mail.defaultFromName` was silently unused on the MS365 path (only the
Google path, `MailerGoogle.php:149`-ish, ever honoured it). Adding
`message.from` in the legacy/direct mode too — not just the new
shared-mailbox mode — means `mail.defaultFromName` finally takes effect
everywhere. This is flagged explicitly in the CHANGELOG as a visible-but-
benign change: recipients now see whatever display name is configured,
where before they saw the mailbox's own Exchange display name.

### Error matrix — why each threshold is what it is

| Condition | Behaviour | Why |
| --- | --- | --- |
| 401 | Clear `self::$token`, retry once | The static per-request token cache can outlive the token's actual server-side validity (revocation, secret rotation) — one fresh-token retry costs nothing and fixes the common case. A second 401 falls through to the fail path — never loop. |
| 429 | Read `Retry-After`; retry once ONLY if ≤5s | This runs synchronously inside a web request on **shared hosting** — an unbounded or long sleep would hold a PHP-FPM worker hostage. A short bounded wait is worth it; anything longer fails the same as any other error and relies on the caller's own retry/queue semantics (or a human re-clicking Send Test Email). |
| 403 `ErrorAccessDenied` / 404 `ErrorInvalidUser`\|`ErrorItemNotFound` | Parse Graph's own `error.code`, surface it | These almost always mean "mailbox outside the Application Access Policy scope", "mailbox deleted", or "consent missing" — a bare HTTP code tells the admin nothing; Graph's own machine-readable code tells them exactly what to check in the runbook. |
| 5xx / cURL failure | Fail path, no special retry | Graph outages are Microsoft's problem, not a shape this codebase should paper over with more retries on shared hosting. |
| Any failure, `mail.fallbackProvider==='google'` | One attempt via `MailerGoogle::send()` | Opt-in only (default `''`) — a silent provider failover changes the visible sending identity, which can break SPF/DKIM/DMARC alignment for anyone actually checking headers. An admin has to choose this explicitly. |

### `tblEmailLog` — shared by both providers, fail-soft by design

`Mailer::logSend()` is `public` (not `private`) specifically so
`MailerGoogle::send()` can call it directly on its own success/failure
branches — there is deliberately no per-provider duplicate of this
table or its writer. The whole method body is wrapped in try/catch with
`error_log()` on failure: a logging bug must never be the reason a real
send that already happened (or already definitively failed) throws an
uncaught exception back up through the caller. Retention (`mail.log.
retentionDays`, default 90) is pruned opportunistically on roughly
1-in-50 writes (`random_int(1, 50) === 1`) — the same "no dedicated cron
needed" trick used elsewhere in this codebase for low-stakes housekeeping.

`toRecipients` is a comma-joined free-text column, not a normalised
per-recipient table — deliberately, since this is an audit trail, not a
queryable-by-recipient index; the 10-row "Recent sends" admin list and
GDPR erasure (below) are the only two consumers and neither needs it
normalised.

### GDPR erasure — why this is a bespoke step, not a `catalogue()` entry

`GdprEraser::catalogue()`'s generic delete/anonymise actions are built
around ONE `userCol` FK per table. `tblEmailLog.toRecipients` is a
comma-joined address LIST — a single send can name several recipients,
and the column holds free-text addresses, not a `userID`. So instead of
a catalogue entry, `GdprEraser::eraseEmailLogRecipients()` does a
targeted `REPLACE(toRecipients, ?, '[erased]') WHERE toRecipients LIKE
?` — the row, provider, status, subject, and httpCode all stay (this is
an audit trail of SENDS, not of the recipient), only the address itself
is detached. This mirrors the `eraseGivingStatementFiles()` /
`tblGivingStatementLog.emailedTo` precedent immediately above it in
`GdprEraser.php` (#440 Q5) — "PII column scrubbed, row + other columns
retained for the audit trail".

The address has to be captured at the TOP of `execute()`, before the
catalogue is walked — the catalogue's own LAST entry anonymises
`tblUsers.emailAddress` to NULL, so by the time a bespoke post-catalogue
step (like the Giving-statements sweep) runs, the address is already
gone. `execute()` now does a plain `SELECT emailAddress FROM tblUsers
WHERE userID = ?` up front and threads that string through to the new
sweep at the same point the Giving-statements sweep already runs.

### Admin UI — closing the test/production drift permanently

`admin/integrations/index.php`'s "Send Test Email" button used to
duplicate the ENTIRE token-acquisition + sendMail cURL flow inline
(~150 lines) rather than calling `Mailer::send()`. That meant the test
could stay green while the real path silently drifted — exactly the kind
of gap #234's own audit surfaced. `sendTestEmail()` now calls
`\Portal\Core\Mailer::send()` directly and reads back the just-written
`tblEmailLog` row for its diagnostic detail (provider/httpCode/
errorCode/errorDetail) — whatever this button proves now also proves the
real send path, shared-mailbox mode included. The standalone "Test Token
Acquisition" button keeps its own separate inline flow deliberately — it
tests ONLY credential validity, a genuinely different diagnostic that
doesn't attempt a send.

### Fold-in fix — the dead `email.*` settings vocabulary

`admin/integrations/email.php` (#230's deliverability page) was reading
`email.provider` / `email.from` — settings seeded in `full_schema.sql`
but never written by any save handler anywhere in the codebase — so it
permanently reported "smtp" regardless of what was actually configured.
Fixed to read `Mailer::provider()` + the same effective-sender
resolution order as `effectiveSender()` (duplicated as a 5-line
display-only mirror, since `effectiveSender()` itself is `private` to
`Mailer`). The two dead `email.*` seed rows are left in place
deliberately — removing a seed needs its own cleanup migration, which is
out of scope here and not worth it for two harmless unread rows.

### Owner runbook (Azure AD / Microsoft 365 admin)

Full step-by-step lives in `web/_apps/help/admin.php` (anchor
`#ms365-shared-mailbox`) and is linked from `/admin/integrations`. Summary:

1. Create/identify the shared mailbox in the M365 admin centre — no
   licence needed.
2. Confirm the existing app-wide registration already has Application
   `Mail.Send` with admin consent (if portal email already works today,
   this is already done).
3. **Recommended:** scope it with `New-ApplicationAccessPolicy` (legacy
   but functional) or RBAC for Applications (successor) — otherwise the
   app token can send as any tenant mailbox, not just the configured one.
4. Set the shared mailbox + display name at `/admin/integrations` → Save
   → Send Test Email.
5. Deliverability note: mail leaves via Microsoft's own infrastructure,
   so the tenant's standard SPF/DKIM/DMARC records (not the web server's
   IP reputation) are what need to be correct — the Email Deliverability
   page's DNS probe checks them against the effective sender's domain.

---

## Location / Geocoordinates / What3Words platform — Chunk A + B (#456, migrations 180-181)

Cross-repo data-format CONTRACT shared with ProjectBookIT/ProjectEPass —
**standalone principle**: every repo builds its own `GeoLocation`/
`What3Words`/`Geocoder` classes and reads its own settings; no repo ever
calls another at runtime for a geo function. The CONTRACT is a shape
agreement only (column types, the canonical `location` JSON wire object,
what3words canonical form) — see the class docblocks in `_core/
GeoLocation.php` / `What3Words.php` / `Geocoder.php` for the full contract
text.

### What3Words key-in-query-string security note

Unlike every other Bearer-style adapter in this codebase (Zoom,
CloudflareStream), the what3words v3 API takes its API key as a
**query-string parameter** (`?key=…`), never a header. `What3Words::
request()` is the ONLY place `w3w.apiKey` is read; the key is appended via
`http_build_query()` (rawurlencoded exactly once, never concatenated raw)
and the `$url` local variable that embeds it must NEVER appear in a
`Logger::errorPlatform()` call, an exception message, or a return value —
every failure log line carries only `path=… httpCode=…`. Grep-audit gate:
`grep -rn "w3w.apiKey\|geo.google.apiKey"` across `web/_apps` + `web/_core`
should show it ONLY in the two adapters (`What3Words.php`, `Geocoder.php`)
and the two admin integration pages (index + save) — nowhere else, ever.

### Nominatim usage policy compliance

`Geocoder`'s Nominatim leg is built to comply with OSM's usage policy from
the first commit, not bolted on later:

1. **Descriptive User-Agent** — `Site::productName() . '/' . PORTAL_VERSION
   . ' (' . $contact . ')'`, where `$contact` resolves `privacy.
   contactEmail` → `mail.defaultFromAddress` → the literal `'admin contact
   unset'`. Sent via `CURLOPT_USERAGENT`.
2. **≤1 request/second throttle** — the last-call timestamp is persisted
   in the `geo.nominatim.lastCallAt` setting (global, `siteID IS NULL`);
   `Geocoder::throttle()` `usleep()`s the remainder up to a hard cap of
   1.2s (bounded — at most one geocode call happens per request, so this
   never compounds into a slow page).
3. **Cache-once** — `tblGeocodeCache` (global, no `siteID` — an address
   geocodes identically for every tenant) caches only successes; a given
   address/coordinate pair hits Nominatim at most once, ever.
4. **Attribution** — `location.map_attribution` ("Map data © OpenStreetMap
   contributors") is rendered in every Leaflet tile layer's `attribution`
   option by `location-map-assets.php`'s init script.

Both `What3Words` and `Geocoder` are entirely best-effort: every public
method returns `null`/`[]`/`false` on any failure and never throws, and
both API-enable flags (`w3w.enabled`, `geo.autoGeocode`) default OFF so a
fresh upgrade fires zero network calls until an admin opts in.

### Leaflet 1.9.4 SRI — verification method + upgrade procedure

The two Leaflet CDN tags live in `web/_core/partials/location-map-assets.php`
as literal `<link>`/`<script>` tags (not routed through `Asset.php`'s
helper pattern — this partial is self-contained by design per the feature
spec) with `integrity=` + `crossorigin="anonymous"`, matching what
`check_cdn_sri.py` requires.

**How the hashes were verified (no live fetch of jsdelivr was possible from
the build sandbox — its outbound proxy blocked it):** the exact `leaflet@
1.9.4` npm tarball was fetched from `registry.npmjs.org` (jsdelivr's `/npm/`
CDN mirrors the published npm tarball byte-for-byte — this is documented
jsdelivr behaviour, not an assumption), `dist/leaflet.js` and `dist/
leaflet.css` were extracted, and both sha384 AND sha256 digests were
independently recomputed and compared against the values already pinned in
the feature's build spec. All four matched exactly:

```bash
curl -sS -o leaflet-1.9.4.tgz https://registry.npmjs.org/leaflet/-/leaflet-1.9.4.tgz
tar -xzf leaflet-1.9.4.tgz package/dist/leaflet.js package/dist/leaflet.css
openssl dgst -sha384 -binary package/dist/leaflet.js  | openssl base64 -A   # -> cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH
openssl dgst -sha384 -binary package/dist/leaflet.css | openssl base64 -A   # -> sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H
```

**On any future Leaflet version bump:** re-run the same fetch-and-hash
procedure against the new version's tarball, update both `src`/`href` URLs
AND both `integrity=` attributes in `location-map-assets.php` together
(mismatched pairs silently break the map with no console hint beyond a
blocked-resource CSP-adjacent network error), and re-run `python3 tools/
audit-checks/check_cdn_sri.py --strict`.

**CSP:** `script-src`/`style-src` already allow `https://cdn.jsdelivr.net`
(header.php's hard-coded policy) — no header.php edit was needed. Tiles
need the page to set `$cspImgExtra = 'https://*.tile.openstreetmap.org';`
BEFORE requiring `header.php` (the existing #386 page-scoped CSP-widening
pattern). Leaflet's default marker PNG icons resolve relative to the CSS
URL (jsdelivr) — NOT covered by `img-src` — so the init script builds an
inline SVG `L.divIcon` marker instead of loading Leaflet's default icons,
keeping `img-src` limited to just the tile host.

### `w3w.enabled` semantics (locked decision)

The flag gates ONLY the What3Words API adapter (autosuggest, validation,
lat/lng↔W3W conversion) — the `///word.word.word` input field is ALWAYS
rendered by `location-input.php` regardless of this setting, and a typed
value is always stored and displayed with a working `///` link. This is
the "stored-field fallback" the whole feature is built around: turning the
API off never removes functionality that already existed, it only removes
the convenience layer on top.

### Directory `$can()` "team tier = private" quirk (inherited verbatim)

`directory/profile.php`'s existing `$can()` visibility check treats the
`team` tier as equivalent to `private` for non-admin viewers — a
pre-existing platform behaviour, not something Chunk A/B changes.
`visibilityCoords` (Chunk B, shipped) is gated through the SAME `$can()`
closure, so it inherits this quirk verbatim by construction; fixing it
was out of scope for this feature.

## Chunk B — member PII + GDPR lockstep (#456, migration 181)

Migration 181 adds FOUR columns to `tblUsers` ONLY: `latitude`/
`longitude`/`what3words` (member home coordinates, PRIVATE by default)
and `visibilityCoords` (ENUM, default `'private'`). Deliberately **no
`geocodedAt`/`geocodeSource` pair** — unlike the standard five-column
block migration 180 added elsewhere, a member's own coordinates are
NEVER auto-geocoded anywhere in this codebase (`geo.autoGeocode` never
applies to `tblUsers`); provenance is always "the member themself" via
either hand entry or their own explicit "Look up coordinates" click on
`directory/me.php`, so a provenance column would carry no information.

### `visibilityCoords` is INDEPENDENT of `visibilityAddress`

A member sharing their address TEXT never implies consent to show a map
PIN — a pin is strictly more precise (and more automatable to scrape at
scale) than a postal address string. `directory/profile.php` therefore
runs a SEPARATE `$can($u['visibilityCoords'])` check before rendering
anything coordinate-shaped, entirely independent of the existing
`$can($u['visibilityAddress'])` check for the address text `<dd>`. Both
default `'private'`.

### Non-owner coarsening + what3words suppression (the actual gate)

`directory/profile.php`'s coords block (`portal_location_display()` call,
gated by `$showCoordsBlock = $can($u['visibilityCoords']) && coords
non-null`):

- **Owner or admin** (`$isOwnerOrAdmin`): full DECIMAL(10,7) precision +
  the exact what3words value.
- **Any other viewer the tier permits**: coordinates pass through
  `GeoLocation::coarsenCoords()` (round to 3dp, ≈110m) before rendering,
  an "Approximate location" badge shows, the map renders an `L.circle`
  (150m radius) instead of a precise marker (`data-approx="1"` — see
  `location-map-assets.php`'s init script), and the what3words value is
  **suppressed entirely** — never coarsened, because a what3words square
  is ~3m×3m and cannot be meaningfully "rounded" the way a coordinate
  pair can; showing it next to an "approximate" pin would silently
  re-precise the whole point of coarsening.

This coarsen-and-suppress logic lives in the SHARED
`location-display.php` partial (`coarsen` config key) — Chunk B is the
first (and, per the spec, only) caller that ever passes `coarsen: true`;
every other Chunk A caller (Venues, Events, Resources) always shows full
precision because none of those tables carry personal data.

### GDPR lockstep — what changed and why

Shipped in the SAME PR as the schema (house rule: never a PII column
without its export/erasure wiring in the same commit):

1. **Export** (`auth/account/data-export.php`) — `tblUsers`'s existing
   `SELECT *` already re-exports the four new columns with zero code
   change; the real, PRE-EXISTING gap this chunk closed was that Gift Aid
   declarations (home address + postcode, HMRC-mandated) were never
   exported for the declaring donor at all. New `giftAidDeclarations`
   block, `WHERE donorID = ?`.
2. **Self-service erasure** (`auth/account/delete-confirm.php`) — the
   tblUsers anonymise `UPDATE` never nulled `displayAddress`/
   `displayPhone` (a separate pre-existing miss, found while auditing
   this column family) — both now null alongside the three new PII
   columns; `visibilityCoords` resets to `'private'` defensively.
3. **Admin erasure catalogue** (`Portal\Core\GdprEraser::catalogue()`) —
   the final `tblUsers` entry's `nullCols` gained `latitude`/`longitude`/
   `what3words`. `visibilityCoords` is a `NOT NULL` ENUM so it can't go
   through the generic null-column mechanism — left untouched there is
   harmless because with the coordinates already NULL there is nothing
   left for any visibility tier to gate.
4. **Round-trip proof** (manually traced, no local MySQL in this build
   sandbox): a user with address+coords+W3W+a Gift Aid declaration
   exports all four coordinate fields plus the declaration's address/
   postcode; after erasure (either path) a re-`SELECT` of `tblUsers`
   shows all three coordinate columns NULL, and the admin path's
   `tblGiftAidDeclaration` DELETE (pre-existing catalogue entry, unchanged
   this chunk) removes the declaration's address entirely.

### GiftAid / Salvation — text-only partial reuse, deliberately no schema change

Both apps reuse `location-input.php`'s "reduced names map" mode
(`showCoords: false, showW3W: false`, `names` mapping only the fields
each app already has) purely for UI/i18n consistency — mapped onto their
EXISTING `address`/`postcode` POST field names, so `gad-save.php`/
`card-save.php` needed zero changes. No `latitude`/`longitude`/
`what3words` column was added to `tblGiftAidDeclaration` or
`tblSalvationCards` — HMRC and pastoral follow-up need postal text, not a
map pin, and no new column means no new erasure surface to wire.

### Hard exclusion: Kids / Care / Visitors

Per the locked decision (safeguarding data, no consent mechanism for a
map pin), the shared partials are NEVER imported into `_apps/kids/`,
`_apps/care/`, or `_apps/visitors/`, and no location column was added to
`tblKidProfiles`/`tblCareCase`/`tblCareVisit`/`tblVisitor`. Verify on any
future touch of this feature with:

```bash
git diff --stat -- web/_apps/kids web/_apps/care web/_apps/visitors   # must be empty
git diff | grep -iE "tblKidProfiles|tblCareCase|tblCareVisit|tblVisitor" # must be empty
```

---

Last updated: August 2026
