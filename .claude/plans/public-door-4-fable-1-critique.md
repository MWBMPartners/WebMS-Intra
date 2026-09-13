<!--
  Fable catch-up review of public-door-3-build-plan.md, 13 September 2026.
  Run because the plan was designed on Opus while Fable was unavailable (standing fallback rule).
  Three sequential Fable agents: critique -> challenge (try to refute each point) -> corrections
  built only from points that survived. Copied here from the workflow journal so it survives a
  /tmp clean-out, which lost an unread review on 11-13 September.
-->

# Catch-up review of `.claude/plans/public-door-3-build-plan.md`

Everything below was checked against the working tree on `claude/alpha-wip` on 13 September 2026. Step 1 is left alone except where the learned-since list does not already cover it. Points are grouped by the areas the brief asked about, then the rest. Each carries a category, the step it affects, file:line evidence, whether it is PROVEN from the code or INFERRED, and why it matters.

---

## A. Can the public front door reach an admin page?

**A1 — WRONG. The self-test's boundary proof (Part E) fails on day one, by construction.** Step 4, §8.
Part E asserts that for every `tblRoutes` row with `isProtected = 1`, `PublicRouter::resolveHandler($routeKey)` and `resolveHandler(firstSegment)` both return `null`. But `('noticeboard', 'noticeboard/index.php', 1)` is seeded at `web/_sql/full_schema.sql:5092`, and the plan gives the noticeboard a public surface named `noticeboard` whose `''` handler is `board.php` (§5). So `resolveHandler('noticeboard')` returns a file, not `null`, and every `noticeboard/*` protected row fails the first-segment variant too. PROVEN.
Why it matters: the one assertion the brief calls "the boundary proof" cannot pass while a surface shares its name with an admin app route prefix — which the plan does deliberately. The correct assertion is about the resolved FILE: for every protected row, the resolved path (when there is one) is never `PORTAL_APPS/{targetFile}` and always sits under `_apps/{slug}/public/`. That is the property that actually keeps admin handlers out.

**A2 — MISSING. The design's third mechanism was dropped without saying so.** Step 4.
The design (§1, mechanism 3) has `Auth::ensureSession()`, `Auth::requireLogin()` and `App::user()` refuse outright when `PORTAL_DOOR === 'public'`. The build plan's "Changed — shared framework" table (§2) lists bootstrap, Router, Site, AppRegistry, RateLimiter, Logger, GdprEraser and two app files — not `Auth.php`, not `App.php`. PROVEN that it is absent.
Why it matters: nothing then fails loudly if a public handler includes `web/_core/templates/header.php`. That file hard-codes `X-Frame-Options: SAMEORIGIN` (`header.php:99`), which silently re-breaks the iframe embed, and it renders the navigation for whoever `App::user()` finds — which on the public door means a `Set-Cookie` appears and the "no session, cacheable" promise is gone with no error anywhere. A refusal in those three methods turns a quiet regression into a 500 the self-test can see. INFERRED on the consequence.

**A3 — WRONG. `error.php` will break on the admin door the moment Step 2 lands.** Step 2.
`web/public_html/error.php:33` requires bootstrap on its own, with no `PORTAL_DOOR` defined; it is the Apache `ErrorDocument` target for 403/404/500/503 (`.htaccess:84-87`). Step 2 item 2 makes bootstrap refuse with `exit()` when `PORTAL_DOOR`/`PORTAL_CHANNEL` are undefined. `exit()` inside a required file is not an exception, so the `try/catch` at `error.php:35-42` never runs. Every Apache-level error page on the management portal becomes the one-line "Portal misconfigured" text. `error.php` is not in the Step 2 file list. PROVEN.
Fix: add `error.php` to Step 2 (define the three constants before its `require_once`), and say in the same place what the front controller does when `.channel` is absent — the plan currently says both "refuse when `PORTAL_CHANNEL` is undefined" and "default to `prod` when everything else is missing", which cannot both be true.

**A4 — OVERBUILT and wrong on two channels. `PORTAL_ADMIN_WEBROOT` duplicates `PORTAL_WEBROOT` and points at the wrong folder.** Steps 2–3.
Step 2 defines `PORTAL_ADMIN_WEBROOT = PORTAL_ROOT/public_html`, Step 3 changes it to `.../admin_html`. But beta's admin root is `admin_html_beta` and alpha's is `admin_html_dev` (DEV_NOTES 3b table). So `Router.php:95` and `:257` would resolve the offline page and web-root fallbacks out of the LIVE folder from alpha and beta. The same step already defines `PORTAL_WEBROOT = __DIR__` in each front controller, which is right on every channel. PROVEN. Use `PORTAL_WEBROOT` in Router and drop the new constant.

---

## B. Tenant isolation on public requests

**B1 — SEQUENCING inside Step 4. Bootstrap cannot load "a restricted `$SETTINGS` for that site" before the site is known.** Step 4.
The bootstrap row in §2 says that on the public door bootstrap "skips `Site::preDetect()`, loads a restricted `$SETTINGS`". But the site is resolved by the front controller AFTER bootstrap returns (§1 of the design, and the plan's `index.php` description). `$SETTINGS` is built at `bootstrap.php:353-381` from `$preDetectedSiteID`; the timezone is applied at `:519-523` from `$SETTINGS['site']['timezone']`. PROVEN.
Why it matters: either the front controller reloads settings and re-applies the timezone after `Site::initPublic()`, or site resolution moves inside bootstrap. If neither is written down, the public calendar renders every time in UTC (or in site 1's zone), and per-site branding reads the wrong tenant. `Site::initPublic()` must also call `loadSiteRow()`, or `Site::branding('name')` returns `null` on every public page (`Site.php:184-190`).

**B2 — MISSING. The restricted `$SETTINGS` allow-list is never enumerated, and the obvious list breaks things.** Step 4.
Things that read the snapshot and must keep working on the public door: `RateLimiter::trustedProxies()` reads `App::settings('portal.trustedProxies')` (`RateLimiter.php:610`) — leave it out and behind Cloudflare every visitor shares one address; `I18n::init()` reads `i18n.defaultLocale`; bootstrap's timezone reads `site.timezone`; the header block reads `portal.headers.*`; `Site::init()` reads `multisite.enabled`. PROVEN for each read.
Write the list into the plan: `public.*`, `branding.*`, `product.*`, `portal.trustedProxies`, `portal.trustedProxyHeader`, `portal.headers.*`, `site.timezone`, `i18n.*`, `multisite.enabled`, plus each published surface's own `{slug}.*`. Nothing marked `isSensitive`.

**B3 — SEQUENCING inside Step 4. Making `Site::id()` throw collides with the exception handler.** Step 4.
Bootstrap's handler (`bootstrap.php:570-582`) calls `Logger::exception()` → `errorPlatform()` → `Site::id()` (`Logger.php:210-250`). With the plan's `id()` throwing before `initPublic()`, any failure that happens before site resolution throws again INSIDE the handler: PHP's "exception thrown while handling exception" fatal, blank page, nothing written to `tblErrors`. PROVEN.
The public exception handler must log through `errorPlatformForSite(null, …)` (which now exists, `Logger.php:291`) and never through `Logger::exception()`. Say so in the bootstrap row.

**B4 — SEQUENCING between Steps 4 and 5. The empty door queries tables that do not exist yet.** Steps 4–5.
Step 4 ships `PublicSites` and `Site::initPublic()`, which read `tblPublicHosts` and `tblSites.publicToken`. Both are created by Step 5's migration. `mysqli_report(MYSQLI_REPORT_STRICT)` at `bootstrap.php:287` makes a missing table throw, and with B3 that is a blank fatal — not the bare 404 Step 4's verification list expects for `/`, `/admin`, `/dashboard`. PROVEN.
Either move the two tables and the `publicToken` column into Step 4 as the first slice of migration 194, or make `PublicSites` treat any exception as "unresolved → 404 + logged".

**B5 — MISSING. Inheritance of the portal-wide row is decided for two of the thirteen `public.*` keys.** Step 5.
`App::settingForSite()` (`App.php:171-190`) falls back to the `siteID IS NULL` row for every key. The plan settles `public.enabled` (inherits) and `public.{slug}.enabled` (does not). `public.embedOrigins`, `public.homeSurface`, `public.assetBase`, `public.allowIndexing` are not decided. PROVEN on the fallback; INFERRED on the effect.
Why it matters: a portal-wide `public.embedOrigins` row names a website that may frame EVERY tenant's pages. Rule to write down: per-site only for anything that names an outside party or chooses a surface; inheritance only for switches whose portal-wide value is "no".

**B6 — MISSING. The three channels share one database, so the plan's alpha-only verification of publishing is not isolated from live.** Steps 3–6.
`_auth_keys/` sits once under `SFTP_PATH_ROOT_DIR` (DEV_NOTES 3b diagram) and `bootstrap.php:273` reads it from `PORTAL_ROOT`, which is the same folder for all three channels. So `public.enabled` and `public.noticeboard.enabled` are per-SITE, not per-channel: Step 6's "turn it on for site 1 on alpha" turns it on for the live public door too. The only thing keeping the live hostname dark is the absence of its `tblPublicHosts` row — and the `/s/{token}/…` form resolves the site from the token with no host check at all, so token-addressed content is live everywhere the moment it exists. PROVEN on the shared credentials file.
Fix: `PublicSites` requires the request host to be an active `tblPublicHosts` row for EVERY request, token-addressed or not (the design's "host and token disagree → 404" becomes "host unknown → 404"). Then a channel is dark until its hostname is added, which is the per-channel lever the plan assumes it already has.

---

## C. What the deployment does on the very first run after the rename

**C1 — WRONG. Step 3's own workflow cannot run.** Step 3.
Step 3 is defined as the state where `web/public_html/` does not exist in the tree. The "Stamp the channel" step writes `web/public_html/.channel` unconditionally (plan line 1401): bash fails with "No such file or directory" and the run stops. Guard 1 then calls `check_door "$remote_public" public` against a remote folder that has never been created, which refuses. Both run on every deploy between Step 3 and Step 4. PROVEN from the plan's own shell. Guard both on `[[ -d web/public_html ]]`.

**C2 — WRONG. The door check refuses an absent remote directory, so beta and main can never deploy "without first_run".** Steps 3, 4, §7.
Every channel's admin root is a NEW folder name (`admin_html`, `admin_html_beta`, `admin_html_dev`). Guard 1 treats "no `.door`" as "predates the rename → refuse" (plan lines 1425-1428). So owner step 11 — "repeat for beta, then main, this time WITHOUT ticking first_run" — cannot pass; each channel needs `first_run`, and `first_run` skips the ADMIN-side check as well, which is the one that matters. And Step 4's first deploy of a brand-new `web/public_html/` needs `first_run` a second time. PROVEN.
Simpler rule that removes the `first_run` input entirely: an absent remote directory is created (creating a folder is never the dangerous direction); refuse only when the directory EXISTS and either has no `.door` or has a disagreeing one. The old `public_html/` full of admin files then still refuses, which is what forces the manual clean-out in DEV_NOTES 3c.

**C3 — WRONG. `confirm_public_path` can never match.** §7.
The input asks the owner to "type the `SFTP_PATH_*_PUBLIC_DIR` value again" — a folder name like `public_html_dev/` — and the step compares it with `remote_public`, a full joined path (plan lines 1449-1455, 1518). They are never equal, so `first_run` is always refused. PROVEN. (Moot if C2 is adopted.)

**C4 — WRONG. A folder setting of `..` walks past every guard and mirrors `--delete` over the user's home directory.** §7 guard 4.
Guard 4 refuses an inner `/` only. `..` has none, is not empty, does not equal the root and is not a string prefix of the other path, so `REMOTE_ADMIN="$ROOT/.."` = `/home/USER/` passes all three of the trimmed Guard 3 checks (plan lines 1339-1372). With `first_run`, `mirror --reverse --delete` then runs against the folder holding every other domain on the account. PROVEN from the plan's shell. Refuse anything outside `^[A-Za-z0-9_-]+$`.

**C5 — SEQUENCING. The changeover order takes the live management portal down for the whole alpha → beta → main cycle.** §7 and DEV_NOTES 3c.
DEV_NOTES 3c's order: delete all three old web roots (step 3), re-point the panel (step 5), THEN deploy alpha (6), verify (7), then beta, then main (8). The live `public_html/` is deleted at step 3 and nothing serves `portal.millrdsdacambridge.uk` until main deploys at step 8 — and step 5 re-points it at `admin_html`, which does not exist until then. PROVEN from the two documents.
Reorder per channel: deploy the new admin root into its NEW folder (old one untouched), re-point that one hostname, verify sign-in, delete the old folder. Alpha first, live last, and live's old folder is deleted only after live is verified.

**C6 — WRONG. The deny-all `.htaccess` files cannot reach three of the folders, and the one at `web/` would take both doors down.** Step 2 item 6.
`web/_uploads/`, `web/_backups/` and `web/_auth_keys/` are gitignored (`.gitignore` lines "web/_auth_keys/", "web/_uploads/", "web/_backups/") and excluded from the mirror (`deploy.yml:129-131`), so a file placed there is neither committed nor deployed. PROVEN. Separately, a `Require all denied` in `web/.htaccess` is inherited by every subdirectory — including `admin_html/` and `public_html/` — because Apache merges parent `.htaccess` files before the child's. Both doors go dark unless each web-root `.htaccess` explicitly starts with `Require all granted`. INFERRED from Apache's documented merge order.
Fix: one `web/.htaccess` (the shared mirror does upload top-level files of `web/`, and `LFTP_EXCLUDES` does not exclude `.htaccess`), `Require all granted` at the top of both web-root `.htaccess` files, and drop the per-folder copies.

**C7 — WRONG. `--exclude ^media/` also excludes `media/.htaccess`, the file that provides the CORP header and the no-execution guard.** Step 6, §7.
The exclusion is a path regex; `media/.htaccess` matches it, so the file never reaches the server, and with `--no-empty-dirs` the folder is never created by the deploy either. PROVEN from the plan's mirror line. (Only relevant if static copying survives — see E1.)

**C8 — MISSING. The "web directory too high" probe covers the public hostname only, and the bootstrap refusal cannot protect the files it is for.** §7, Step 2 item 3.
The identical panel mistake on the ADMIN hostname is at least as likely — DEV_NOTES 3c step 5 is precisely a panel edit of the portal domain's web directory — and the admin URL is already hard-coded for the health check (`deploy.yml:421`). Probe both. PROVEN. Also: a request for `/_sql/full_schema.sql` against a too-high web directory is answered by Apache without PHP ever starting, so the `DOCUMENT_ROOT` refusal in bootstrap only fires for requests that happen to reach a front controller. DEV_NOTES 3b's "the portal refuses to start if it detects this" overstates it; only `web/.htaccess` (if honoured) and the probe do the work. INFERRED.

**C9 — MISSING. Stale instructions from before the seven-secret decision and learned-since #6.** Steps 3, 9, §7.
Still in the plan: "nine secrets" and "the retired-secret refusal" and "the parent-equality assertion" (Step 3 item 5); "nine secrets above" and `public_site`/`public_site_dev` (§7 header comment block, stamp-step comment, owner steps 5 and 7); "Add the nine new secrets" and "if any [old one] is left … the deployment stops on purpose" (owner steps 1-2); "migration 193" in Step 9's Codex prompt. The owner has already set the seven names and the retired names are gone at both levels (DEV_NOTES 3b "What is set up right now"). PROVEN. A builder following the owner-steps as written creates secrets that must not exist and points a DreamHost web directory at a folder name the deploy never writes.

**C10 — MISSING (DreamHost). The new public website must run under the SAME hosting user as the portal.** §7 owner step 4.
Hosting facts §2: DreamHost runs one user per domain, and a domain under a different user "cannot access the website files under any other user". A public subdomain created under another user cannot have its web directory set inside `portal.example.org/`. The owner steps never say "assign it to user X". PROVEN from the hosting facts document.

---

## D. The calendar's `isPublic` default

The core hazard is handled correctly: `tblEvents.isPublic` defaults to 1 (`full_schema.sql`, tblEvents block) and migration 194 adds `publicSurfaceOptIn DEFAULT 0` with a surface filter requiring both. Two things on top:

**D1 — WRONG. Two of the nine names in `columnAllow` are not columns of `tblEvents`.** Step 7, §5.
`columnAllow` lists `title` and `summary`. The table has `eventName` and `description` (`full_schema.sql`, tblEvents CREATE: `eventName VARCHAR(255) NOT NULL`, `description TEXT`). PROVEN. `PublicQuery` builds the SELECT from this list by strict key lookup, so either it refuses both names and the public list shows no titles, or it emits SQL that errors on every request. Fix the names — and note that `description` is the admin's full free text, which is a wider disclosure than "summary" implies.

**D2 — DECISION. Opt-in is per row, and a recurring series is one row per occurrence.** Step 7.
`calendar/index.php:255-302` lists `tblEvents` rows joined to `tblEventSeries`, and `calendar/export.php:20-27` "collapses a recurring series into ONE VEVENT + RRULE" from those rows — so a weekly service is 52 rows a year, each needing its own checkbox. INFERRED from the storage shape. The owner decides: series-level opt-in propagated by `manage/save.php`, or per-row only with the help page saying so.

---

## E. How media reaches an external page given `Cross-Origin-Resource-Policy`

**E1 — OVERBUILT, with a hole the plan cannot fill. Static copying into `web/public_html/media/` does not know which public folder to write to, and cannot stay in step with one shared database.** Step 6.
`PublicMedia::publish()` runs on the ADMIN door (`noticeboard/publish.php`). `PORTAL_ROOT` is the same folder for all three channels, and the sibling public folder's name (`public_html`, `public_html_beta`, `public_html_dev`) exists only in the deploy secrets — PHP has no way to learn it. And because the channels share one database (B6), `tblNoticeboardUploads.isPublic = 1` describes a file that exists in at most one of three public folders; the sweep only deletes, it never creates. PROVEN on the folder/database facts.
Simpler alternative: serve media through the public door's own PHP as a `noticeboard/media` surface handler, sending `Cross-Origin-Resource-Policy: cross-origin`, `Access-Control-Allow-Origin: *` and the existing `Cache-Control: public, max-age=31536000, immutable` (`noticeboard/media.php:87-90`). With a one-year immutable cache a foyer display fetches each file once, so the per-request PHP cost the design feared is paid once per file per display. Checks: `isPublic = 1` on the upload row AND the owning poster's `siteID` equals the resolved site. This deletes `PublicMedia`, the sweep, `public.cron_token`, the `media/` folder, the deploy exclusion (C7), the GDPR disk-purge step, and the "file existence is the publication state" hazard the security review flagged. What it cannot do on its own: byte-range requests — a looping video needs `Accept-Ranges` and 206 responses, which `readfile()` does not provide; implement Range handling or accept whole-file downloads. INFERRED on browser caching behaviour.

**E2 — OVERBUILT. A CORS allowlist on the JSON feed.** Step 8.
The feed is public data with no credentials; `widget/countdown-json.php:28` already sends `Access-Control-Allow-Origin: *` and it is the one proven cross-site pattern in the repo. Restricting the feed to `public.embedOrigins` adds admin work and one more silent failure (an empty box when an origin was not added). PROVEN. Use `*` on `data` handlers; keep the origin list for `frame-ancestors`, where it does real work.

**E3 — WRONG. Moving `widget/countdown.js` to the public door breaks every existing embed.** Step 8.
Customers' pages load it from the PORTAL host (`countdown.js:12-17`, `data-portal` names the portal), and its data feed `widget/countdown.json` is a `tblRoutes` row on the ADMIN door (`full_schema.sql:4853`) that the plan leaves in place. After the move, the admin host answers `/widget/countdown.js` with a 404 HTML page through the front controller. PROVEN. Leave it where it is — it works there today — or keep a copy on both doors.

**E4 — WRONG. `/robots.txt` and `/sitemap.xml` are not "real files served by Apache's rewrite fallthrough".** Step 8, §3 address table.
The files are `robots.php` and `sitemap.php`; the requested names do not exist on disk, so `.htaccess:67-74` rewrites them to `index.php`. Today they work only because `tblRoutes` rows (`full_schema.sql:8613-8614`) plus Router's web-root fallback (`Router.php:95`) map them. The public door has neither — and the plan's own reserved list forbids `robots.txt` and `sitemap.xml` as surface names, so the resolver 404s them. `PublicRouter` must dispatch those two names itself. PROVEN. Also `sitemap.php` is a rewrite, not a move: it reads `App::settings('site.allowIndexing')` (`sitemap.php:85`), `Site::id()` (`:204`) and emits `/e/{slug}` admin-door addresses (`:232`).

---

## F. Everything else

**F1 — DECISION. `canTune()` refuses while the surface is off, so nothing can be prepared before launch.** Step 5, §6.
"Nobody tunes a surface that is off — there is nothing to tune" gets it backwards for a launch: the root admin switches the surface on to an empty board, and only then can a delegate start marking posters. Allow tuning whenever the surface is DECLARED; gate only whether visitors see it. INFERRED.

**F2 — SEQUENCING between Steps 5 and 6. Route rows are seeded one step before their handlers exist.** Steps 5–6.
Migration 194 seeds `noticeboard/publish` and `help/public-site` (§3, §4 section D) in Step 5; both handlers are created in Step 6. For that gap `check_route_targets.py` fails and the two addresses 404. PROVEN from the plan's own step contents. Seed those two rows in Step 6's slice.

**F3 — OVERBUILT. `public.assetBase` exists for route (c3), which the plan itself says is "never the design".** Step 5.
A setting and a code path for an optional per-customer arrangement nobody has asked for yet. Drop it until a customer needs it. PROVEN from §9.

**F4 — MISSING (Step 1, not covered by the learned-since list). `Logger.php` still builds the visitor address from the forwarded headers itself** (`Logger.php:528-533`). The handoff records this as deliberately deferred until Step 1 is committed; the plan should say the same, so a builder does not "fix" it in Step 2 while Step 1 is still under review. PROVEN.

---

## What I did not check

No live server was touched, so every claim about Apache behaviour (merge order of `.htaccess` files, `ErrorDocument` handling of PHP-set status codes) and lftp behaviour (`--exclude` also protecting excluded remote files from `--delete`, `mirror --reverse` creating an absent target directory) is INFERRED from documentation, not observed. The DreamHost panel's willingness to nest one domain's web directory inside another domain's folder is taken as PROVEN only because the beta and alpha channels already do it (DEV_NOTES 3b). I did not run any audit script or self-test; the plan's counts of scripts (15) and of `public_html` hard-codes were confirmed by listing and grep only.