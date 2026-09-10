# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-09-07 (session in progress — this file is kept current as work
proceeds, so the session can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## Read this first — where we are right now

**Updated 10 September 2026.**

| Task | State |
| --- | --- |
| Audit and delete the six stale branches | ✅ Done — `.claude/plans/branch-audit-2026-09-07.md` |
| Single work-in-progress branch, local clone realigned | ✅ Done — `claude/alpha-wip` |
| Self-host Swagger UI + fix `/api-docs` | ✅ Done — commit `8f21094`, migration 185, issue #470 |
| Install PHP locally + write the setup guide | ✅ Done — PHP 8.5.10 |
| Reach three features that had no way in | ✅ Done — commit `6c01c47`, migration 186, issue #467 |
| Dead menu links, demo-data hazard, unwired checks | ✅ Done — commit `82241bc`, issues #468 #469 #471 |
| **Settings duplicate-rows fix** | 🔄 **Built and proven on MySQL 8.0.36; Codex round 1 found 6 HIGH, all fixed; round 2 pending** — issue #466 |
| Codex review as a standing rule | ✅ Done — memory + `.claude/CLAUDE.md` |
| GitHub issue sweep | 🔄 6 new (#466–#471), 6 existing updated (#47 #97 #107 #225 #273 #322) |
| Update all documentation to match reality | ⏳ Queued |
| Ranked list of proposed new work | ⏳ Queued |
| One pull request into `alpha`, watch CI | ⏳ Last step |

### The four analysis agents that failed

The deep audit was six sequential agents. Two finished (`01-inventory.md`,
`02-core-schema.md`, both in the session scratchpad under `analysis/`). The
other four — documentation drift, GitHub issues, API drift, and new-work
proposals — **failed because the account hit its monthly spend limit** on
7 September. That work is being done directly instead of re-spawning them.

### The Codex review loop — what it caught

New standing rule: every change gets a second opinion from Codex before it is
committed (`codex exec --skip-git-repo-check "<brief>"`, with standard input
closed — otherwise it hangs waiting for more).

**Four rounds were needed on the settings fix.** Every round found real
problems in work that had already passed the PHP syntax check, all eleven audit
scripts, and the full end-to-end database test against real MySQL 8.0.36. Worth
remembering: those checks catch mechanical faults, not wrong thinking.

**Round 1 — six HIGH, two of which destroyed data:**

1. The backup fix would have wiped every timestamp. It filtered columns on
   `EXTRA LIKE '%GENERATED%'`; MySQL uses that same word for ordinary columns
   declared `DEFAULT CURRENT_TIMESTAMP`, so every `createdAt` and `updatedAt`
   across 209 tables would have been silently dropped from every backup.
   Now tests `GENERATION_EXPRESSION`.
2. The clean-up would have deleted saved payment credentials. It kept "the
   newest copy", but `web/_apps/payments/save.php:38` and the captcha, SMS,
   translation and integration screens pick a row with an unordered
   `SELECT … LIMIT 1` and update that one — in practice the oldest.
3. The corrections overrode deliberate choices (no `siteID IS NULL`
   restriction). Both value-changing steps removed; seeds fixed instead.
4. Loading `full_schema.sql` onto an existing database recorded 187 as done
   without applying it, permanently preventing the fix.
5. A failed restore destroyed the data it was protecting (`TRUNCATE` commits
   and cannot be undone).
6. Migration 021 would have reset an administrator's chosen date format.

**Round 2 — four more HIGH.** Migration 012 would have reset the chosen
language; `SIGNAL` is not allowed in these files (MySQL error 1295, confirmed);
the "has this been edited?" test ignored capitalisation; and **every migration
from 180 onwards reported failure after succeeding**, because each records
itself and then `Migrator::runOne()` tried to record it again against a
uniqueness rule.

**Round 3 — told me to undo my own work, and was right.** Swapping `TRUNCATE`
for `DELETE` (the round-1 fix) set off the database's automatic tidying of
related records: restoring expense claims would delete every claim line.
Switching that checking off stopped the cascade but allowed a restore to commit
records pointing at nothing, and report success — a silent failure worse than
the loud one. **The response was to revert, not patch.** Redesigning restore
does not belong in a settings change, and nothing references `tblSettings`, so
the simple approach works for the only table this needed. The three genuine
restore faults are now **issue #472** — including that 71 of the 209 tables have
never been restorable at all.

**Round 4** — running at the time of writing.

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
