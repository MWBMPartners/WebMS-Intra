<!--
  Fable catch-up review of public-door-3-build-plan.md, 13 September 2026.
  Run because the plan was designed on Opus while Fable was unavailable (standing fallback rule).
  Three sequential Fable agents: critique -> challenge (try to refute each point) -> corrections
  built only from points that survived. Copied here from the workflow journal so it survives a
  /tmp clean-out, which lost an unread review on 11-13 September.
-->

# Challenge of the catch-up review — checked against the working tree on `claude/alpha-wip`, 13 September 2026

Every line number below is from the files as they are now. Where the critique's numbers are stale I say so. "Plan" means `.claude/plans/public-door-3-build-plan.md`.

## A. Can the public front door reach an admin page?

**A1 — SURVIVES.** `web/_sql/full_schema.sql:5092` seeds `('noticeboard', 'noticeboard/index.php', 1)`. Plan §5 declares surface `noticeboard` with `'' => 'board.php'`. Part E (plan lines 1573-1577) asserts `resolveHandler('noticeboard')`, `resolveHandler(firstSegment)` and `resolveHandler('s/aaa…/noticeboard')` all return `null` for every protected row. All three fail on that row by construction, and Part E's own "reverse direction" clause (line 1581) requires `noticeboard` to resolve — the test contradicts itself. The plan's own migration adds `noticeboard/publish` (protected, line 639), whose first segment fails the same way. The `events` surface is safe: no route row begins `events`. Assert on the resolved file, as the critique says.

**A2 — SURVIVES, and the consequence is proven, not inferred.** Plan §2 "Changed — shared framework" lists bootstrap, Router, Site, AppRegistry, RateLimiter, Logger, GdprEraser and two app files; a grep of the plan for `Auth::` finds nothing. `web/_core/templates/header.php:32` calls `Auth::ensureSession()` and `:174` calls `Auth::csrfToken()`, so a public handler that includes it starts a session and sends `Set-Cookie`; `:99` hard-codes `X-Frame-Options: SAMEORIGIN`. Nothing in the plan refuses this.

**A3 — SURVIVES on the main point; PARTLY on the "cannot both be true" sub-point.** `web/public_html/error.php:30-44` does `require_once $bootstrap` inside `try/catch`; `.htaccess:84-87` names it as the ErrorDocument. `exit()` is not a Throwable, so the catch never runs. The plan's only mentions of `error.php` are the public door's own (lines 160-162) and the reserved-name list; the admin `error.php` is not in Step 2. Sub-point: "refuse when PORTAL_CHANNEL is undefined" and "default to prod when everything else is missing" are reconcilable (front controller always defines it, possibly empty; bootstrap maps unrecognised to prod), but the plan does not say so and its own verification text stumbles over exactly this (line 77).

**A4 — SURVIVES.** `web/_core/Router.php:95` and `:257` hard-code `PORTAL_ROOT/public_html`. DEV_NOTES 3b lines 603-608: `admin_html`, `admin_html_beta`, `admin_html_dev` all sit under one root. Plan Step 3 item 2 makes `PORTAL_ADMIN_WEBROOT` = `…/admin_html`, so on beta and alpha the fallback for `manifest.json`, `openapi.json`, `robots.txt`, `sitemap.xml` and `/offline` reads LIVE's folder — and during the alpha-first rollout, before live is deployed, reads nothing. `PORTAL_WEBROOT = __DIR__` (plan line 69) is right on every channel. One addition: `Router::renderError()` itself does not use the constant, so `error.php` only needs it defined for A3's sake, not for rendering.

## B. Tenant isolation on public requests

**B1 — SURVIVES in substance.** `bootstrap.php:373-409` builds `$SETTINGS` bound to `$preDetectedSiteID` (line 389); timezone applied at 547-552; `Site::branding()` returns `null` when `$currentSite === null` (`Site.php:184-188`) and `loadSiteRow()` is private, called only from `init()` and site switching. The plan's bootstrap row (line 186) says bootstrap "loads a restricted `$SETTINGS`" on the public door, which cannot happen before the front controller resolves the site (line 159). Worth saying: the design §2 already had the right order ("the front controller resolves the site … then calls `Site::initPublic()` and loads `$SETTINGS` for that site"); the plan's row contradicts its own design, and neither document mentions re-applying the timezone or loading the site row.

**B2 — SURVIVES.** Every read confirmed: `RateLimiter.php:615` `App::settings('portal.trustedProxies')`; `I18n.php:84` `App::settings('i18n.defaultLocale')`; `bootstrap.php:477-517` `$SETTINGS['portal']['headers'][…]`; `bootstrap.php:547` `site.timezone`. Correction: `Site::init()` reads `multisite.enabled` from the settings array (`Site.php:108`), but the public door skips `Site::init()` entirely — which produces a worse problem, listed under MISSED 1.

**B3 — PARTLY.** Mechanism confirmed: `bootstrap.php:598-610` (the critique's 570-582 is stale) → `Logger::exception()` (`Logger.php:210-214`) → `errorPlatform()` → `Site::id()` (`:250`); `errorPlatformForSite()` exists at `:291`. A throw inside the exception handler is a fatal with nothing logged — correct. But the plan's bootstrap row (line 186) does say "routes exceptions to `PublicErrors`" on the public door. What it does not say is how `PublicErrors` logs. So the critique's requirement is a real gap in the plan, not a contradiction of it.

**B4 — SURVIVES, with a caveat on "PROVEN".** `mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR)` is at `bootstrap.php:315` (not 287). Step 4 (plan line 96) ships `PublicSites` and `Site::initPublic()`; Step 5's migration creates `tblPublicHosts` and `tblSites.publicToken`. The plan's `index.php` description (line 159) resolves the site BEFORE handing to `PublicRouter`, so at Step 4 every request queries a missing table and throws. The caveat: the design's gate order (§1: surface first, site second) would answer `/admin` and `/dashboard` with 404 at gate 1 without touching the database. The plan is inconsistent with itself about the order, so "by construction" depends on which description the builder follows. `/` needs `public.homeSurface` for a resolved site either way. The fix stands.

**B5 — SURVIVES.** `App.php:171-190` falls back to the `siteID IS NULL` row. Plan §4 section C (lines 552-565) decides inheritance for `public.enabled` and `public.{name}.enabled` only; `embedOrigins`, `homeSurface`, `assetBase`, `allowIndexing`, `allowAiIndexing`, `cacheSeconds`, `pollSeconds` and `slidesSeconds` are all seeded portal-wide with no decision, and `Headers::publicPolicy()` is described as taking "config" without naming the resolver.

**B6 — SURVIVES, and is stronger than stated.** `bootstrap.php:301` reads `PORTAL_ROOT/_auth_keys/auth_creds.php`; DEV_NOTES 3b lines 600-608 show one root holding all six web roots; `deploy.yml:363-405` mirrors shared code to one base from every branch. One database, so per-site settings are shared across channels. Beyond the token route: design §5 source 3 says "when `multisite.enabled` is not `'true'` … the answer is 1, taken directly" with no host check at all, and `multisite.enabled` is seeded `'false'`. So on a default install every hostname that reaches the live public door serves site 1 the moment the alpha test switches site 1 on. The fix is a host row on every request, which also means dropping source 3 (security review finding 32, which the plan did not adopt).

## C. What the deployment does on the first run after the rename

**C1 — SURVIVES.** Plan lines 1400-1401 write `web/public_html/.channel` unconditionally. GitHub's default `run:` shell is `bash -e`, so a failed redirect stops the step. Guard 1 (lines 1425-1428) refuses when the download produces no file, which is what an absent directory produces. Both bite every non-first_run deploy between Step 3 and Step 4.

**C2 — SURVIVES.** All six server folder names are new (DEV_NOTES 3b lines 449-454). Guard 1 refuses "no `.door`" and is skipped only by `first_run`, whose `if:` (line 1408) is on the whole step, so both `check_door` calls are skipped together. Owner step 11 (line 1521) says beta and main run "without ticking first_run" — impossible. Step 4's first public deploy needs it again.

**C3 — SURVIVES.** Line 1450 asks for "the `SFTP_PATH_*_PUBLIC_DIR` value again" (a folder name); line 1455 compares it with `remote_public`, which is `$ROOT_DIR/$PUBLIC_DIR` (lines 1353, 1385). Never equal.

**C4 — SURVIVES.** Guard 4 (lines 1339-1344) trims outer slashes and refuses only an inner `/`. `..` passes; `$ROOT/..` fails none of the three string checks at 1364-1372. With `first_run`, `mirror --reverse --delete` runs over `/home/USER`. `.` passes too — see MISSED 5.

**C5 — SURVIVES.** DEV_NOTES 3c lines 709-722, as read: step 3 deletes all three old roots; step 5 re-points the panel at `admin_html`, which does not exist until main deploys at step 8.

**C6 — SURVIVES.** `.gitignore` ignores `web/_auth_keys/`, `web/_uploads/`, `web/_backups/`; `deploy.yml:129-131` excludes them. The shared mirror (`deploy.yml:380` and `402-404`) is `web/ → base` with `LFTP_EXCLUDES`, which has no `.htaccess` pattern (only `^\.htpasswd`), so a `web/.htaccess` would upload. The Apache merge behaviour is inferred, as the critique labels it; the current `public_html/.htaccess` contains no `Require all granted`.

**C7 — REFUTED against the plan (valid only against the design).** The plan's file table (line 160) puts the CORP header and the no-execution rule in the top-level `web/public_html/.htaccess`, and lists only `media/.gitignore` under `media/` (line 166). A grep of the plan finds no `media/.htaccess` anywhere. The design §6 did propose a `.htaccess` inside `media/`, so the plan should say explicitly that it chose otherwise. Residual truth: `--exclude ^media/` plus `--no-empty-dirs` means the deploy never creates `media/`, so `PublicMedia::publish()` must create it.

**C8 — SURVIVES.** `deploy.yml:421` hard-codes `https://portal.millrdsdacambridge.uk/health`; the plan's probe (lines 1479-1497) uses `PUBLIC_BASE_URL` only. DEV_NOTES 3b line 627 ("the portal refuses to start if it detects this") overstates a PHP-side check that cannot fire for `/_sql/full_schema.sql`, which Apache serves without starting PHP.

**C9 — SURVIVES.** All confirmed in the plan text: line 84 ("nine secrets, the retired-secret refusal, … the parent-equality assertion"); 1221 ("nine secrets above"); 1226-1227 and 1398-1399 (`public_site`); 1505-1506 ("Add the nine new secrets", "stops on purpose"); 1512 and 1514 (`public_site`, `public_site_dev`); 136 ("migration 193"); 115 ("replay 193 twice"). DEV_NOTES 3b lines 564-568 confirm the seven names are set and the retired ones gone.

**C10 — SURVIVES.** Hosting facts §2. The design §6(a) said "under the same user"; the plan's owner steps 4-7 (lines 1511-1514) and DEV_NOTES 3b lines 614-619 do not.

## D. The calendar's `isPublic` default

**D1 — SURVIVES.** `full_schema.sql:761` `eventName`, `:763` `description`; there is no `title` and no `summary` column in `tblEvents`. The other seven names exist. `web/_apps/widget/countdown-json.php:38-39` even carries the warning "eventName (NOT title)".

**D2 — SURVIVES, and is proven rather than inferred.** `full_schema.sql:753-754`: "Each row is a single scheduled occurrence (standalone or part of a series)"; `calendar/export.php:14-24` confirms expansion is one `tblEvents` row per occurrence and that nothing writes `tblRecurrenceRules`. `tblEventOccurrenceOverrides` (line 5714) exists for per-date overrides, so the storage model is mixed, but the working model is one row per occurrence. The decision is real and should be put to the owner.

## E. How media reaches an external page

**E1 — SURVIVES on the diagnosis; the alternative is a judgment.** Shared root and database confirmed under B6. "PHP has no way to learn" the sibling folder name is too strong — `PORTAL_WEBROOT` (`…/admin_html_dev`) could be mapped to `public_html_dev` by convention — but the plan specifies nothing, and with one database a published file can exist in at most one channel's folder while `tblNoticeboardUploads.isPublic = 1` says it is published everywhere. Plan line 220 confirms the sweep only deletes. The PHP-served alternative is coherent: the design's stated reasons for static files (the app-disabled trap, Router chrome) do not apply on the public door. Range requests are the genuine cost.

**E2 — PARTLY.** `countdown-json.php:28` sends `Access-Control-Allow-Origin: *`, confirmed. The technical point is right: an origin allow-list gives no confidentiality on public data, since anyone can fetch the feed directly. Whether the owner wants a "which websites may embed our script" control is a product decision. Keep it only if wanted, and never describe it as a security control.

**E3 — SURVIVES.** `countdown.js:11-12` is loaded from the portal host and `data-portal` names it; `:141` fetches `PORTAL + '/widget/countdown.json'`, a `tblRoutes` row (`full_schema.sql:4853`) the plan leaves on the admin door. After the move, `/widget/countdown.js` on the admin host is no longer a file, so `.htaccess:67-74` rewrites it to `index.php`, no route matches, 404. On the public door `widget/` is a real folder, so a `data-portal` pointing there gets a 404 for the JSON too.

**E4 — SURVIVES.** The files are `robots.php` and `sitemap.php` (listing of `web/public_html`); `.htaccess:67-74` rewrites non-files to `index.php`; routes at `full_schema.sql:8613-8614`; Router fallback at `:95`. `sitemap.php:85` reads `site.allowIndexing`, `:204` `Site::id()`, `:230` emits `/e/{slug}`. The plan's reserved list (lines 276, 1539) forbids the names as surfaces, so `PublicRouter` must handle them itself.

## F. Everything else

**F1 — PARTLY.** Confirmed at plan lines 1077-1079. It is a usability decision, not a defect: the plan's Step 6 flow (switch on with zero posters, then mark) works, and an empty public board is harmless. Worth an explicit owner decision.

**F2 — SURVIVES.** Migration 194 §D (lines 639-640) seeds `noticeboard/publish` and `help/public-site`; both handlers are Step 6 work (lines 118, 219, 221). `check_route_targets.py` fails in between.

**F3 — PARTLY.** True that `public.assetBase` exists only for route (c3) (plan lines 590-594, 1623). Dropping it is reasonable; a seeded-empty setting is also cheap. Judgment.

**F4 — SURVIVES.** `Logger.php:526-535` `clientIp()` believes `CF-Connecting-IP`, then the LEFTMOST `X-Forwarded-For` entry. HANDOFF line 138 records the deliberate deferral; the plan's Logger row (line 191) mentions only `errorPlatformForSite()`.

## What the critique MISSED

1. **`Site::isMultisiteEnabled()` is always false on the public door, so "source 3" answers site 1 for every install.** `Site.php:76-77` `private static bool $enabled = false;`, set only inside `init()` (`:108-109`). The plan's C5 and its bootstrap row deliberately skip `Site::init()` on the public door. Design §5 source 3 says "when `multisite.enabled` is not `'true'` the answer is 1, taken directly". Any `PublicSites` that asks `Site::isMultisiteEnabled()` will therefore treat a multi-tenant install as single-site and serve site 1 — the exact silent default the whole design exists to remove. `PublicSites` must read `multisite.enabled` from the database itself (as `Site::preDetect()` does at `Site.php:711-716`), or source 3 goes. The plan never says which. PROVEN on the mechanism.

2. **Guard 1 cannot run on this installation as written.** The `check_door` snippet (plan line 1422) hard-codes key authentication (`-i $HOME/.ssh/deploy_key`). DEV_NOTES 3b line 567 and plan lines 1173-1174: `SFTP_KEY` is not set; this account signs in with a password. The `|| true` swallows the failed download, the marker reads as missing, and every non-first_run deploy is refused. Today's `deploy.yml` carries a key variant and a password variant of every SFTP step (lines 305-341, 363-405); the guard needs the same. PROVEN from the two documents.

3. **The bootstrap refusal breaks the documented offsite-backup logger.** `tools/offsite-backup/log-offsite-result.php:23` requires bootstrap with no `PORTAL_DOOR` defined. `docs/offsite-backup-setup.md:24` and `web/_apps/admin/maintenance/offsite-backup.php:213` tell the administrator to copy it into `web/_backups/` and run it from cron. Step 2's own verification (plan line 77) relies on "run bootstrap from the shell → refusal", so this tool stops working the day Step 2 lands. It needs a command-line door (`PHP_SAPI === 'cli'`) or the tool must declare the constants itself. The 20 `_apps/*` files and `_install/upgrade.php` that also `require_once` bootstrap are safe: they are reached only through the Router, so `bootstrap.php:36` returns early. PROVEN.

4. **The admin door's `X-Robots-Tag` stays conditional.** Design §7 makes it unconditional and retires `site.allowIndexing` from the header path. A grep of the plan for `X-Robots` finds only the public door (lines 1593-1595) and a verification line (91); Step 2 says `Headers::emitAdmin()` is byte-identical. `bootstrap.php:529-542` still drops `noindex` when `site.allowIndexing = 'true'`. The plan seeds the new `public.allowIndexing` keys but never removes the old switch from the admin door, so the accident the design set out to make impossible remains possible. PROVEN.

5. **`.` as a folder name also passes Guard 4** (extends C4). `$ROOT/.` is string-unequal to `$ROOT`, so the "cannot be the shared directory" check (line 1370) passes, and the admin mirror runs `--delete` against the shared base itself with only `LFTP_EXCLUDES` applied — `_core/`, `_apps/` and `_sql/` would be deleted. Refuse anything not matching `^[A-Za-z0-9_-]+$`. PROVEN from the plan's shell.

6. **A YAML typo in the plan's workflow.** Line 1266 `PUBLIC_ALPHA:${{ … }}` has no space after the colon, so it is not a key/value pair and the step will not parse. Trivial, but it sits in the one file the plan says must never go to a cheaper model. PROVEN.

7. **One worry checked and dropped, recorded so nobody re-checks it.** `tblSettings` has `uq_setting_key_scope (settingKey, siteScope)` (`full_schema.sql:99-105`, from migration 187), so the plan's `INSERT … ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)` replays correctly for portal-wide rows; migration 193 uses the same idiom. Minor inconsistency only: `public.cron_token` seeds `isSensitive = 0` while five of the seven existing `*.cron_token` rows seed `1` (`full_schema.sql:4178, 4878, 5244, 6922, 7557`).

8. **Line-number drift.** The critique's `bootstrap.php` references (287, 353-381, 519-523, 570-582) are from an older revision; in the working tree they are 315, 373-409, 547-552 and 598-610. The substance of each claim is unchanged.

## What I did not check

No live server, Apache or lftp was touched; everything about `.htaccess` merging, `ErrorDocument` handling and `mirror --reverse` creating a missing directory is inferred from documentation. I did not run the audit scripts or self-tests.