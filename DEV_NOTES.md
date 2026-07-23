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

### Flow

```text
localStorage  ──FOUC script──▶  <html data-bs-theme="..." data-portal-cb="...">
                                       │
                                       ▼
                              portal.css token overrides
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
  light → dark → auto), `initCbToggle()` (on/off)
- `web/_install/index.php` — installer mirrors all of the above inline
  (it's standalone, can't load portal.css/portal.js)

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
<base>/core/
<base>/vendor/
<base>/sql/
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

Last updated: July 2026
