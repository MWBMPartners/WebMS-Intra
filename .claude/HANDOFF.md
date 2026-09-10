# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-09-07 (session in progress — this file is kept current as work
proceeds, so the session can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## Read this first — where we are right now

**Updated 10 September 2026, after the merge.**

**Pull request #473 is MERGED into `alpha`** (squashed as `b2c42b4`), the deploy
to the alpha site ran, and the local clone is realigned. The previous working
branch was deleted on GitHub and locally. This branch, `claude/alpha-wip`, is a
fresh one cut from the updated `alpha`, ready for whatever comes next. **Nothing
is open against `alpha`.**

### Everything asked for in this session is done

| Task | State |
| --- | --- |
| Audit and delete the six stale branches | ✅ `.claude/plans/branch-audit-2026-09-07.md` |
| Single work-in-progress branch, local clone realigned | ✅ |
| Self-host Swagger UI + fix `/api-docs` | ✅ migration 185, closed #470 |
| Install PHP locally + write the setup guide | ✅ PHP 8.5.10 |
| Reach three features that had no way in | ✅ migration 186, closed #467 |
| Dead menu links, demo-data hazard, unwired checks | ✅ closed #468 #469 #471 |
| Settings duplicated instead of saving | ✅ migration 187, closed #466 |
| Codex review as a standing rule | ✅ memory + `.claude/CLAUDE.md` |
| GitHub issue sweep | ✅ 8 opened (#466–#472, #474), 6 closed, 6 updated |
| Documentation update | ✅ counts, README, FEATURES, DEV_NOTES, `/help/admin` |
| Ranked list of proposed next work | ✅ `.claude/plans/proposed-next-work-2026-09-10.md` |
| Pull request into `alpha`, all checks green, merged | ✅ #473 |
| Deploy to alpha | ✅ started automatically after the merge |

### Two decisions waiting on the owner

1. **The minimum password length.** Existing sites may still require 8
   characters while the project believes 12. Migration 187 deliberately does not
   change it: a leftover seed and a deliberate choice cannot be told apart. New
   installs are correct, and `/help/admin` now warns administrators to check
   their own value. Raise existing sites automatically, or leave it to them?
2. **What to work on next** — twelve ranked proposals in
   `.claude/plans/proposed-next-work-2026-09-10.md`. The one worth pressing for
   is **a "check my portal" page for administrators**, because almost every
   problem found in this audit was invisible from inside the product.

### Two things found late that are worth knowing

- **#474 — no check has to pass before merging to `alpha`.** The only rules on
  it are "cannot delete" and "cannot force-push". Automatic merging therefore
  does not wait for anything; it has been merging on open for months. `main`
  requires exactly one check, and it is **not** the PHP syntax gate the
  project's own documentation calls "the only hard gate".
- **#472 — 71 of the 209 tables have never been restorable from a backup.**
  MySQL refuses the method used to empty a table when other tables point at it.
  Nobody had noticed, which suggests restore has rarely been used in earnest —
  worth knowing, because it is the safety net the upgrade process relies on.

### Still true and still unfixed: the version number on `alpha`

`Version Bump` and `Changelog` have not run on `alpha` since 7 July 2026. The
merge just made will not have changed that. `alpha` still reports **1.4.0**
while `beta` and `main` report 1.4.1. This is proposal number 1 and is about an
hour's work — the cause is understood and the fix follows a pattern already
proven in the same file.

### The four analysis agents that failed

The deep audit was planned as six sequential agents. Two finished
(`01-inventory.md`, `02-core-schema.md`, in the session scratchpad under
`analysis/`). The other four — documentation drift, GitHub issues, API drift,
new-work proposals — **failed because the account hit its monthly spend limit**
on 7 September. That work was done directly instead, which for verifying issues
against code is more reliable anyway.

## 1. The branch clean-up (done)

Six branches were sitting on GitHub with no pull request attached.

**`claude/venue-b` … `claude/venue-f`** were five workers building one job — the
Venues app — side by side from a shared starting point. Their output was
gathered into `claude/venue-bookings` and merged into `alpha` as pull request
**#431** on 28 August. Checked file by file, every one of them was either
already identical to `alpha` or an older version `alpha` has since improved. A
trial merge of each produced conflicts that would have **undone** later work —
most importantly the room-aware booking coverage added for issue #436 in pull
request #452. **Nothing was outstanding; merging would have caused a
regression.** All five deleted.

**`claude/dependabot-alpha-beta-branches-q24zd7`** had a misleading name — no
dependency updates and no program code, only twelve planning documents that
existed nowhere else, covering work that has since shipped in nine pull
requests (434, 441, 444, 445, 446, 451, 452, 454 and 455) and including two
security reviews. Those twelve documents were copied onto `claude/alpha-wip` under
`.claude/plans/gap-items/`. The branch was then deleted.

The full method and evidence is in `.claude/plans/branch-audit-2026-09-07.md`.
**The lesson worth keeping: never judge a branch by its commit messages.**

## 2. Swagger UI + the /api-docs page (done — commit `8f21094`)

Three problems, all fixed:

1. **The page broke without internet access.** Swagger UI was only ever fetched
   from a public content delivery network, with no local copy to fall back on —
   unlike Bootstrap and Font Awesome, which have always had one. On a network
   that blocks public CDNs the page was a blank white screen. The three files
   are now committed under `public_html/assets/vendor/swagger-ui/` and wired
   into the existing fallback machinery. Each was verified to hash to the SRI
   value `Asset.php` already recorded, so the local copy is provably identical
   to what the CDN serves.
2. **"Try it out" could never write anything.** The page read the anti-forgery
   token from a `<meta name="csrf-token">` tag, but the page deliberately
   bypasses the shared template that emits that tag — so the token was always
   empty and every write was refused with "CSRF check failed". The page now
   emits its own. And because the portal replaces the token every time it
   accepts one, `ApiAuth::requireWrite()` now hands the replacement back as
   `meta.csrfToken` so a second write does not fail.
3. **The page phoned home** to `validator.swagger.io`. Switched off.

New setting `api.docs.local_assets_only` (migration 185, seeded off) skips the
CDN entirely for sites whose network blocks it.

## 3. PHP on the development machine (done)

There was no PHP installed, so Visual Studio Code could not check files and the
build's hard lint gate could not be reproduced locally. Installed **PHP 8.5.10**
via Homebrew (the project's exact target version), pointed the editor at it via
`.vscode/settings.json` (which git ignores, so it stays on this machine), and
confirmed all 785 PHP files pass `php -l`.

`DEV_NOTES.md` now has **"Installing PHP on your own machine"** with
step-by-step instructions for macOS, Windows, Linux and Raspberry Pi.

---

## 4. What the audit found, and what has been done about it

Audit reports so far, in the session scratchpad under `analysis/`:
`01-inventory.md` (apps, routes, handlers) and `02-core-schema.md` (core
classes, migrations, tables, CI). Every finding below was independently
re-verified before being acted on.

### ✅ Already fixed — commit `6c01c47` (migration 186)

- **The notification preferences page could not be opened.** Two pages were
  both called "Notification preferences"; migration 093 gave the address to
  the newsletter-only one, hiding the real page. Browser push opt-in was
  therefore only reachable from the livestream page. The newsletter checkbox
  is now a card on the real page, the newsletter-only page is deleted, and
  the address points back where it belongs.
- **The livestream channels and schedule page could not be opened** — with it
  went the only button that tells subscribers "we are live now". It now has
  its own address, `/admin/livestream/channels`, linked from the analytics
  page.
- **Six help guides nobody could find** — Venue Bookings, Forms Builder,
  Reports, Admin First Steps, Disaster Recovery and Getting Support existed
  with working addresses but no link anywhere. All 18 guides are now on the
  Help Centre index.
- Removed four settings that switched on API endpoints whose handler files do
  not exist.

### ✅ Already fixed — commit `82241bc`

- **Six menu and dashboard links led to "page not found"** (`/expenses`,
  `/kids`, `/reports`, `/salvation`, `/worship`, `/webhooks`). New
  `Router::routeExists()` means a link is never drawn for a page that does
  not exist, and a new optional `landing` field on an app's registry entry
  says where to link when the app's own prefix is not a page. The `route`
  field could not simply be corrected — it is the prefix used to decide which
  app owns a page for the on/off switch.
- **An app switched on at `/admin/apps` never appeared in the menu.** That
  screen writes `'1'`; the menu insisted on `'true'`. Both now accepted.
- **"Run all pending migrations" would have put demo data into a live site.**
  `Migrator::allFiles()` accepted any `.sql` file, so `full_schema.sql` and
  `demo_data.sql` always showed as pending. Both `allFiles()` and `runOne()`
  now accept only numbered files.
- **Four of the eleven safety checks were never run by any workflow.** All
  four now run on every pull request.

### Still outstanding — not yet fixed

### 🔴 The settings table cannot de-duplicate itself — highest priority

`tblSettings` has `UNIQUE KEY uq_setting_key_site (settingKey, siteID)`, and
every global setting is stored with `siteID = NULL`. MySQL allows any number of
rows whose indexed value is NULL, so two rows for the same global setting are
**not** duplicates as far as that key is concerned. The project already knows
this — migration `015_multisite.sql` says so in its own comment — but only
noted it as the reason per-site overrides work, not as a problem.

The consequence is that `INSERT … ON DUPLICATE KEY UPDATE` **never fires** for a
global setting. It always inserts another row. Verified places this happens:

- `web/_apps/admin/settings/group.php:129` — the main admin settings editor.
  **Every save of any settings group adds new rows** instead of updating.
- `web/_apps/admin/apps/index.php:52` — the app on/off switch. Every toggle
  adds a row.
- The installer: 392 of the 434 global settings are seeded in both
  `full_schema.sql` and at least one numbered migration, and the installer runs
  `full_schema.sql` and then replays every migration. So a **fresh install
  starts with at least two rows for each of those 392 settings.**

Which value actually applies depends on which row `bootstrap.php` reads last —
its query orders only by `siteID IS NULL DESC`, so among the duplicates the
order is whatever the storage engine returns. In practice that is usually
primary-key order, so the newest row wins, which is accidentally the right
answer. It is not guaranteed by SQL.

**Not yet verified on a real database.** There is no MySQL on this machine. Run
this on the server to see the real damage:

```sql
SELECT settingKey, COUNT(*) AS copies
FROM tblSettings WHERE siteID IS NULL
GROUP BY settingKey HAVING copies > 1 ORDER BY copies DESC;
```

**Proposed fix** (not applied — it touches the most-read table in the product
and deserves its own careful change): de-duplicate global rows keeping the
newest per key, then add a stored generated column
`siteKey = COALESCE(siteID, 0)` and a unique key on `(settingKey, siteKey)`, so
`ON DUPLICATE KEY UPDATE` starts working. No application code would need to
change. The end-to-end migration harness runs against a real MySQL 8.0 in CI
and can prove both the de-duplication and the new constraint.

### 🔴 The notification preferences page cannot be opened

`_apps/auth/account/notifications.php` is the page for choosing digest emails,
event reminders, expense updates, task and rota reminders, workflow approval
requests, **and web-push opt-in**. No route points at it.

`/account/notifications` — the URL every link in the product uses — points at
`_apps/account/notifications.php`, which only handles newsletter opt-in.
Migration `093_newsletter.sql:79` re-seeded that route key with the newsletter
page and the fuller page lost its only way in. Its saver
(`auth/account/notifications-save.php`) is still routed, so the form handler for
a page nobody can reach is still live.

The practical loss: **web-push opt-in is only reachable from the livestream
page**, so members cannot subscribe to service reminders at all.

### 🟠 Menu and dashboard links that lead to "page not found"

`_core/templates/nav.php:81-100` and `_apps/dashboard/index.php:189-199` build
links from settings groups whose value is exactly `'true'`, without checking
that a matching route exists. With the seeded defaults, `/expenses`, `/kids`,
`/worship`, `/salvation`, `/reports` and `/webhooks` are all rendered and all
return "page not found". The dashboard also hard-codes `/expenses` for its "My
Pending Claims" panel.

### 🟠 Two different spellings of "switched on"

`/admin/apps` writes `'1'` or `'0'`. `AppRegistry::isEnabled()` accepts `'1'`
or `'true'`. But the menu and the dashboard require exactly `'true'`. So an app
switched on through the admin screen works when you type its address, but never
appears in the menu or as a dashboard card. Whether an app is visible depends on
*how* it was switched on.

### 🟠 Livestream channel and schedule management cannot be opened

`_apps/admin/livestream/index.php` lost its route when
`133_livestream_analytics.sql:24` re-pointed `admin/livestream` at the viewer
analytics dashboard. `cron/push-golive.php` depends on that page's "notify
subscribers we are live" action, which now has no reachable button.

### 🟡 Smaller items

- Three dead files in `_apps/api/` (`ai-improve.php`, `translate.php`,
  `livestream-ping.php`) whose routes were removed in migration 158. Nothing
  calls them. `translate.php` describes a "translate this content" link that
  does not exist anywhere in the product.
- Six help pages exist and are routed but the Help Centre index does not link
  to any of them, so they are invisible: `admin-first-steps`,
  `disaster-recovery`, `forms`, `reports`, `support`, `venues`.
- Four settings flags point at API handlers that do not exist
  (`api.expenses.stats/attachments/update/update-status.enabled`).
  `api.expenses.delete.enabled` is seeded twice with opposite values.
- Three PHP handlers live in the web root
  (`public_html/admin/integrations/monitoring/*`) rather than in `_apps/`.
- API endpoints are not covered by the app on/off switch — switching an app off
  hides its pages but leaves its API answering.

### 🟠 Two build jobs have not run on `alpha` since 7 July

`Version Bump` and `Changelog` are both triggered by a push to `alpha`. Neither
has run since 7 July 2026, across roughly thirty merged pull requests. The cause
is GitHub's rule that anything done using the built-in build token does not
trigger further jobs — and `alpha` merges are performed by the auto-merge job
using exactly that token. The auto-merge job already works around this for the
deploy job, by starting it explicitly; the other two never got the same
treatment, and neither can be started by hand because neither offers a manual
trigger.

Effects: `alpha` still reports version **1.4.0** while `beta` and `main` report
1.4.1, and the changelog for `alpha` is only up to date because people have been
editing it by hand inside each pull request.

---

## 5. Verified-clean baseline

Measured on this branch, so a later regression is obvious:

- **785** PHP files, all pass `php -l` (the exact command CI uses).
- **All 11** checks in `tools/audit-checks/` pass.
- **559** live routes, **0** pointing at a missing file.
- **566** settings keys seeded, **0** read without being seeded and without a
  fallback.
- **184** numbered migrations (000-184; 168 and 169 were never used), all
  re-runnable, **no** MariaDB-only syntax.
- **209** database tables, **0** column-name mismatches.
- **54** app directories, **47** in the installable-app registry, **19** help
  pages, **62** API handlers, **63** documented API operations.

---

## 6. How this session is being run

- **Deep analysis and planning:** sequential Fable 5 agents, never parallel.
  Fall back to Opus only if Fable is unavailable, and retry Fable next time.
- **Building:** Sonnet or Haiku; Opus only when genuinely hard.
- **Everything written in plain English** — replies, comments, commits, pull
  requests, issues, documentation, in-app help. Now a standing rule in
  `.claude/CLAUDE.md`.
- **After each piece of work:** commit and push, update the GitHub issue, update
  `.claude/`, update this file.
- **One pull request only**, at the end, into `alpha`.

## 7. If you are picking this up cold

1. `git checkout claude/alpha-wip && git pull`
2. Read `.claude/plans/branch-audit-2026-09-07.md` for why the branches went.
3. Read the audit reports in the session scratchpad under `analysis/` —
   `01-inventory.md` is the ground truth for apps, routes and handlers.
4. The findings in section 4 above are the work queue. None is fixed yet.
5. `php -l` and `python3 tools/audit-checks/*.py` are the local gates. Both were
   clean at the last commit.
