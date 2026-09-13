<!--
  Fable catch-up review of public-door-3-build-plan.md, 13 September 2026.
  Run because the plan was designed on Opus while Fable was unavailable (standing fallback rule).
  Three sequential Fable agents: critique -> challenge (try to refute each point) -> corrections
  built only from points that survived. Copied here from the workflow journal so it survives a
  /tmp clean-out, which lost an unread review on 11-13 September.
-->

# Corrections to `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/WebMS-Intra/.claude/plans/public-door-3-build-plan.md`

Every correction below rests on a point the challenge marked SURVIVED, PARTLY, or MISSED-and-verified. Where I checked something further against the working tree myself, I say so. Ordered by how much harm each prevents. The file was not edited.

---

## Correction 1 — One database, three channels: a hostname row is required on every request, and "single-site → site 1" goes (B6, MISSED 1)

**Changes:** §2 row for `PublicSites.php`; §4 section A (`tblPublicHosts`); §6 (a new subsection "How the site is resolved"); Step 6 verification; §7 owner steps.

**Replacement text for `PublicSites::resolve(): ?int` (put it in §6, and point the §2 row at it):**

> 1. Take `$_SERVER['HTTP_HOST']`, lower-case it, cut from the first `:`. Empty → null.
> 2. `SELECT siteID FROM tblPublicHosts WHERE hostName = ? AND isActive = 1` (prepared, exact match). No row → null. **This runs on every request, token-addressed or not.**
> 3. Row has a `siteID` → that is the site. If the address also carries `/s/{token}/`, the token must resolve (`SELECT siteID FROM tblSites WHERE publicToken = ? AND isActive = 1`) to the same number, else null.
> 4. Row has `siteID` NULL (the installation's shared public hostname, used for embeds) → a token is required and names the site; no token or no match → null.
> 5. Any `\Throwable` anywhere → null, plus one `Logger::errorPlatformForSite(null, 'PublicSites', …)` entry.
> It never reads `multisite.enabled`, never calls `Site::isMultisiteEnabled()`, and has no default of 1. Null is turned into the door's uniform 404.

**Migration 194, `tblPublicHosts`:** change `siteID INT NOT NULL` to `siteID INT DEFAULT NULL COMMENT 'The organisation this hostname names. Empty means this is the installation''s shared public hostname: it answers only token-addressed requests, and the token names the organisation.'` The foreign key stays (NULL passes it). `/admin/public/hosts` offers two kinds of row: "names this organisation" (root admin, per site) and "shared hostname for embeds" (root admin, portal-wide, at most one).

**Add to Step 6's verification and to §7's owner steps, in these words:**

> The three channels share one database (`bootstrap.php:301` reads `_auth_keys/` from the shared root; DEV_NOTES 3b's diagram shows one root for all six web roots). Every `public.*` setting is therefore per organisation, not per channel. Switching site 1 on to test alpha switches it on for beta and live too. Each channel stays dark only because its public hostname has no `tblPublicHosts` row. So: add the alpha hostname first and only that; add the live hostname on the day live is meant to go public, never "to see if it works".

**Why:** PROVEN. The design's source 3 answers 1 with no host check, and `Site::$enabled` (Site.php:79) is only ever set inside `Site::init()` (Site.php:108-110), which the public door skips — so every install would look single-site and serve site 1 to any hostname that reaches the live public door. Requiring the host row is also the only per-channel lever the plan assumes it has.

---

## Correction 2 — A folder name of `..` or `.` walks past every guard (C4, MISSED 5)

**Changes:** §7 Guard 4 (plan lines 1339-1344).

**Replacement:** after trimming the outer slashes, refuse unless `[[ "$TRIMMED" =~ ^[A-Za-z0-9_-]+$ ]]`, with the message "`${NAME}` must be a plain folder name — letters, digits, underscore or hyphen only — got '${VALUE}'". Delete the inner-slash test; the pattern already covers it.

**Why:** PROVEN from the plan's own shell. `..` has no inner slash, is not empty, does not equal the root and is not a string prefix of the other path, so `$ROOT/..` = `/home/USER` passes all three trimmed checks and `mirror --reverse --delete` runs over the home directory holding every other domain on the account. `.` does the same against the shared base itself, deleting `_core/`, `_apps/`, `_sql/`.

---

## Correction 3 — The changeover order takes live down and can serve the old management portal on the public hostname (C5)

**Changes:** §7 "What the owner does", steps 4-11; Step 3 item 6 (rewrite DEV_NOTES 3c "The order to do it in" to match).

**Replacement — per channel, alpha first, live last:**

> 1. Deploy the channel (the workflow creates the new `admin_html…` folder; see Correction 5). The old `public_html…` folder is untouched and still serves the hostname.
> 2. In the panel, re-point that one hostname at the new folder (`/home/USER/portal.example.org/admin_html_dev` for alpha).
> 3. Sign in there. `curl -sI` and confirm `X-Robots-Tag: noindex`.
> 4. Only now delete the old `public_html…` folder's contents. They are the old management portal. Do not skip this: the next step re-uses that folder name for the public website.
> 5. (Step 4 of the build) Deploy again. The workflow now creates a fresh `public_html…` carrying `.door`. It refuses while the old folder still exists without a marker, and says why.
> 6. Only after that deploy succeeds, add the public hostname in the panel pointing at that folder. **Never point a public hostname at a `public_html*` folder that still holds the old files.**
> Repeat for beta, then main. Live's old folder is deleted only after live sign-in is verified on `admin_html`.

**Why:** PROVEN from the two documents. DEV_NOTES 3c deletes all three old roots (step 3) and re-points the panel at `admin_html` (step 5) before anything is deployed (steps 6-8), so nothing serves `portal.millrdsdacambridge.uk` until main deploys. And the plan's owner steps 4-7 create the public hostname before the old admin files are gone.

---

## Correction 4 — `error.php` and the offsite-backup logger break the day Step 2 lands; "refuse" and "default to prod" reconciled (A3, MISSED 3, learned-since #8)

**Changes:** Step 2 items 1 and 2; Step 2 verification (line 77); §2 bootstrap row.

**Replacement for Step 2 item 1:** "`web/public_html/index.php` AND `web/public_html/error.php` gain, before their `require_once` of bootstrap (`error.php:35`, inside its `try`): `define('PORTAL_DOOR', 'admin'); define('PORTAL_WEBROOT', __DIR__);` and `define('PORTAL_CHANNEL', …)` = the trimmed first line of `__DIR__ . '/.channel'`, or `''` if the file is missing or unreadable. `error.php` is Apache's `ErrorDocument` for 403/404/500/503 (`.htaccess:84-87`); `exit()` inside a required file is not a `Throwable`, so its `catch` at line 40 never runs and every Apache error page on the management portal would become the one-line refusal."

**Replacement for Step 2 item 2:** "Bootstrap, immediately after `PORTAL_ROOT`: if `PHP_SAPI === 'cli'`, define `PORTAL_DOOR = 'cli'`, `PORTAL_WEBROOT = ''`, `PORTAL_CHANNEL = getenv('PORTAL_ENV') ?: ''`, and skip the header block, the document-root refusal and the refusal below — a command-line script cannot be a stale web front controller, which is the only thing the refusal guards against, and `tools/offsite-backup/log-offsite-result.php:23` (plus every copy administrators were told to make by `docs/offsite-backup-setup.md:24` and `admin/maintenance/offsite-backup.php:213`) requires bootstrap with no defines. Otherwise: any of `PORTAL_DOOR`, `PORTAL_WEBROOT`, `PORTAL_CHANNEL` undefined → HTTP 500, one line, exit. Then decide the channel: `getenv('PORTAL_ENV')` non-empty → that value, `PORTAL_ENV_SOURCE = 'environment'`; else `PORTAL_CHANNEL` `live`→`prod`, `beta`→`beta`, `dev` or `alpha`→`dev`, source `'file'`; else `prod`, source `'fallback'`, with one `error_log()` line. Delete the `DOCUMENT_ROOT` sniff (bootstrap.php:94-108). `Gatekeeper::shouldEnforce()` (Gatekeeper.php:232-247) accepts `'environment'` or `'file'`, never `'fallback'`; `'folder'` no longer exists. Put the whole decision in one pure function `Door::validate(string $sapi, ?string $door, ?string $webroot, ?string $channel): array{refusal:?string, env:string, source:string}` in `web/_core/Door.php`; bootstrap calls it; the self-test (§8) calls it with `('apache2handler', null, null, null)` and asserts a refusal, and with `('cli', null, null, null)` and asserts none."

**Replace the verification sentence at line 77 with:** "`php tools/public-door-selftest.php` exercises `Door::validate()`; the shell command `php web/_core/bootstrap.php` is now the CLI door and will not refuse, so do not use it as the proof."

**Why:** PROVEN for `error.php` and the offsite tool. The reconciliation is the plan's own missing sentence: the front controller always defines the channel (possibly empty), so "refuse when undefined" and "default to prod when unrecognised" are both true.

---

## Correction 5 — The deployment workflow: seven settings, door-driven mirrors, no `first_run`, both sign-in variants, the stamp guard, the YAML typo, and the stale wording (C1, C2, C3, C9, MISSED 2, MISSED 6)

**Changes:** Step 2 item 7; Step 3 item 5; Step 3 verification (line 91); §7 header comment block (1221-1237), stamp step (1391-1402), Guard 1 (1404-1439), `first_run` (1441-1455), owner steps 1-2, 8, 11; Step 5 "replay 193" (115); Step 9's Codex prompt (136); §2 "Created — SQL" (246); §3 heading (278); §4 heading and self-record (306, 647).

**5a. Move the workflow rewrite into Step 2, not Step 3.** My own check: `.github/workflows/deploy.yml:154-186` still reads `SFTP_LIVE_PATH` / `SFTP_BETA_PATH` / `SFTP_DEV_PATH`, and DEV_NOTES 3b records those secrets as deleted on 11 September. So no deployment at all — including Step 2's "deploy to alpha" verification and any hotfix — can run until the workflow reads the seven `SFTP_PATH_*` names. Step 2 item 7 becomes: "Rewrite `deploy.yml` to the seven settings and the door-driven mirrors below." Step 3 item 5 becomes: "No workflow change is needed for the rename; verify the alpha deploy still passes."

**5b. Door-driven mirrors (replaces the stamp step, Guard 1, Guard 2 and `first_run`).** Replacement text for §7:

> For each of `web/admin_html` and `web/public_html` that exists in the tree:
> 1. Read its `.door`. Missing, or not exactly `admin` or `public` → refuse the whole run. (A tree with neither directory carrying a `.door` predates Step 2 — refuse with the message "this branch predates the two-door change".)
> 2. Write `.channel` into it (`echo "$CHAN" > "$d/.channel"`). Guarded by `[[ -d "$d" ]]` — the plan's unconditional write to `web/public_html/.channel` fails the run with "No such file or directory" on every deploy between Step 3 and Step 4.
> 3. Its target is the remote folder for that door (`remote_admin` for `admin`, `remote_public` for `public`).
> 4. Probe the remote folder. **Absent → create it and proceed** (creating a folder is never the dangerous direction). **Present without `.door` → refuse** ("this folder exists but carries no marker — if it holds the OLD management portal, delete it by hand (DEV_NOTES 3c), then re-run"). **Present with a different `.door` → refuse.**
> 5. Mirror with `--delete`.
> A source directory whose `.door` says `admin` must also contain `index.php` and `.htaccess`, else refuse.

This is the same check at Steps 2, 3 and 4 with no per-step edits: at Step 2 `web/public_html/.door` says `admin` and lands in `admin_html_dev/`; at Step 3 the file moves with the directory; at Step 4 the new `web/public_html/.door` says `public`. A Step-2-era workflow on a Step-4 tree, or a pre-rename tree on any workflow, is refused by the local `.door` read. Delete the `first_run` and `confirm_public_path` inputs and the step at line 1455 (`confirm_public_path` compared a folder name with a full joined path, so it could never match; and with absent-folder creation there is nothing left for it to protect). Delete `web/admin_html/.door` from Step 3 — it is created at Step 2 in `web/public_html/` and moves.

**5c. Both sign-in variants.** `check_door()` at plan line 1422 hard-codes `-i $HOME/.ssh/deploy_key`; this installation has no `SFTP_KEY` (DEV_NOTES 3b) and signs in with the password, so the `|| true` swallows the failure and every deploy is refused. Write the probe, `mkdir`, and `.door` fetch in both forms `deploy.yml` already uses — key (`lftp -f` with the `connect-program … -i ~/.ssh/deploy_key` line, as at 316-322) and password (`lftp -u "$SFTP_USER,$SFTP_PASS" -e "…" "sftp://$SFTP_HOST:$SFTP_PORT"`, as at 336-341) — selected by `steps.auth.outputs.method`. Use `cls -1 -d "$dir"` for the existence probe and lftp's `mkdir -p` to create. **Not proven here:** that `cls` on a missing directory exits non-zero under `lftp -c`; confirm on the first alpha run with `dry_run` before trusting it.

**5d. YAML typo:** line 1266 `PUBLIC_ALPHA:${{ … }}` needs a space after the colon or the step will not parse.

**5e. Stale wording, replace throughout:** "nine secrets", "the retired-secret refusal", "the parent-equality assertion" (line 84) → "the seven `SFTP_PATH_*` settings (DEV_NOTES 3b), the retired-name warning, the `.door` checks, the `^admin_html*/` excludes, the `public_dir` summary fix". Lines 1221-1237: `<shared>/public_html/` for the admin door and `<shared>/public_site/` → `<root>/admin_html/` and `<root>/public_html/`. Lines 1398-1399 "public_site and public_site_dev" → "admin_html and public_html". Owner steps 1-2 → "The seven settings are already set and the three old ones already deleted (11 September). Do not create `SFTP_ADMIN_PATH_*`, `SFTP_PUBLIC_PATH_*` or `SFTP_SHARED_PATH_*` — nothing reads them." Owner step 8 → an ordinary run of alpha. Owner step 11 → "repeat for beta, then main" (nothing to tick). Migration numbers: "replay 193 twice" (115) → 194; "migration 193" in the Codex prompt (136) → 194; `193_public_door.sql` (246, 306, 647) → `194_public_door.sql`; `194_public_robots_sitemap.sql` (246) → 195; "added by migration 193" (278) → 194.

**Why:** C1, C2, C3 PROVEN from the plan's shell: the stamp fails between Steps 3 and 4; every remote admin folder is a new name (`admin_html`, `admin_html_beta`, `admin_html_dev`, DEV_NOTES 3b) so "no `.door` → refuse" makes owner step 11 impossible and `first_run` — which skips the admin-side check too — would be needed on every channel twice.

---

## Correction 6 — The boundary proof (Part E) fails on day one; assert on the resolved file instead (A1)

**Changes:** §8 Part E (lines 1571-1581).

**Replacement:**

> For every `tblRoutes` seed row in `full_schema.sql` — every row, not only protected ones — and for both `$routeKey` and `'s/' . str_repeat('a', 32) . '/' . $routeKey`: let `$r = PublicRouter::resolveHandler($path, $surfaces)`. If `$r` is not null, `realpath($r)` must begin with `PORTAL_APPS/{slug}/public/` for the surface that matched, **and** must not equal `realpath(PORTAL_APPS . '/' . $targetFile)`. Plus one set-level assertion: no `tblRoutes.targetFile` in `full_schema.sql` contains the segment `/public/`, so the two doors' file sets are disjoint by construction and the test fails the moment anybody registers an admin route pointing into a `public/` directory. Keep the reverse-direction assertion (every surface's own addresses resolve). Delete the first-segment variant.

**Why:** PROVEN. `('noticeboard', 'noticeboard/index.php', 1)` is seeded at `full_schema.sql:5092`; the plan gives the noticeboard a public surface named `noticeboard` whose `''` handler is `board.php`; and migration 194 adds `noticeboard/publish` (protected). So `resolveHandler('noticeboard')` returns a file, the first-segment assertion fails by construction, and Part E's own reverse clause requires it to. The property that keeps admin handlers out is about the resolved file, not the address.

---

## Correction 7 — Nothing fails loudly when a public handler reaches for a session or the admin header (A2)

**Changes:** §2 "Changed — shared framework" (three new rows); Step 4; `check_public_surfaces.py` (§2 tools table, §8).

**Replacement rows:** `web/_core/Auth.php` — `ensureSession()`, `requireLogin()`, `csrfToken()`, `verifyCsrf()` throw `\RuntimeException('Not available on the public door')` as their first line when `PORTAL_DOOR === 'public'`. `web/_core/App.php` — `user()` the same. `web/_core/templates/header.php` — the same refusal as its first statement. **Add to `check_public_surfaces.py`:** every file under `web/_apps/*/public/` must contain none of `templates/header.php`, `Auth::`, `App::user`, `Site::id(`, `$_SESSION`, `session_start`, `Router::`.

**Why:** PROVEN. `header.php:32` calls `Auth::ensureSession()` (a `Set-Cookie`, and the "no session, cacheable" promise is gone), `:174` calls `Auth::csrfToken()`, and `:99` hard-codes `X-Frame-Options: SAMEORIGIN`, which silently re-breaks the iframe embed. The design named this as its third mechanism; the plan's file tables dropped it without saying so.

---

## Correction 8 — What bootstrap does on the public door, in order (B1, B3, B4, review finding 15)

**Changes:** §2 bootstrap row and `index.php` row; Step 4; §2 `PublicMaintenance` row.

**Replacement for the bootstrap row's public clause:** "On `PORTAL_DOOR === 'public'`, bootstrap connects to the database, defines the helper functions, registers the error and exception handlers, sets `$SETTINGS = []` and calls `App::init($mysqli, [])`, sets the timezone to UTC, and returns. It skips `Site::preDetect()` (bootstrap.php:355-359), the settings load (373-409), the header block (411-545), `Site::init()` (571) and `I18n::init()` (625). The public exception handler renders `PublicErrors::render(500)` and logs only through `Logger::errorPlatformForSite(PublicSites::resolvedIdOrNull(), …)` inside its own try/catch. It never calls `Logger::exception()`: that reaches `Logger::errorPlatform()` → `Site::id()` (Logger.php:210-250), which on the public door throws again inside the handler — PHP's 'exception thrown while handling exception' fatal, a blank page, nothing in `tblErrors`."

**Replacement for the `index.php` row — this exact order:**

> 1. Method gate: anything but GET/HEAD → 405 with `Allow: GET, HEAD`.
> 2. `PublicMaintenance::check()` — reads `portal.maintenance.active` and `portal.installed_version` itself with `WHERE siteID IS NULL` (it cannot use `Maintenance::isActive()`, which reads `App::settings()` — Maintenance.php:128, 137 — and nothing is loaded yet), compares with `PORTAL_VERSION`, renders a bare 503 with `Retry-After: 300`. It runs before site resolution because during the upgrade the tables the next step needs may not exist.
> 3. Surface gate: the first segment (after an optional `s/{token}/`) must be a key of `AppRegistry::publicSurfaces()`; miss → 404. No table is opened, so `/admin`, `/dashboard`, `/anything` never touch the database.
> 4. `PublicSites::resolve()` → null → 404.
> 5. `Site::initPublic($siteId)`: sets `$resolved = true` and `$currentSiteID`, calls `loadSiteRow()` (private today, Site.php:133 — without it `Site::branding()` returns null on every public page, Site.php:184-188). `$enabled` is left alone; nothing on this door reads it.
> 6. `$SETTINGS = PublicSurface::loadSettings($mysqli, $siteId)` (Correction 9), then `App::init($mysqli, $SETTINGS)` **again** — `App::settings()` reads the copy `init()` stored (App.php:66-69, 132-137), not the global; `init()` only assigns statics and re-reads `version.php`, so calling it twice is safe.
> 7. `date_default_timezone_set($SETTINGS['site']['timezone'] ?? 'UTC')` (mirrors bootstrap 547-552); `I18n::init()`.
> 8. `PublicSurface::isPublished()` (gates 3-5) → 404.
> 9. `Headers::emitPublic()`; then require, handing over `$mysqli, $SETTINGS, $publicSiteId, $surface`.

**Step 4 note to add:** "Until Step 5's migration lands, `tblPublicHosts` and `tblSites.publicToken` do not exist. `mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR)` (bootstrap.php:315) makes the missing table throw; `PublicSites` catches it, answers null (404) and logs one platform error per host-resolved request. Expect those entries on `/admin/errors` during the Step 4 window; they stop at Step 5."

**Why:** PROVEN. The plan's bootstrap row said bootstrap "loads a restricted `$SETTINGS`" for a site that is only resolved afterwards by the front controller; neither document mentioned re-applying the timezone, loading the site row, or how `PublicErrors` logs. Without these, every public calendar renders in UTC and every refusal is a blank fatal.

---

## Correction 9 — The restricted settings: the allow-list, the inheritance table, one resolver (B2, B5)

**Changes:** §4 section C comment; §6 `PublicSurface` (add `loadSettings()`, `setting()`, `SITE_ONLY_KEYS`); `Headers::publicPolicy()` contract; migration 194 seed list.

**`PublicSurface::loadSettings(\mysqli $db, int $siteId): array`** — the same query shape as bootstrap.php:382-386 (`siteID IS NULL OR siteID = ?`, portal-wide first so the site row overwrites) with `AND isSensitive = 0` in the SQL, a PHP prefix filter, and `assign_setting()` (bootstrap.php:281) to build the same nested shape. `decrypt_setting()` is never called. **Allowed prefixes:** `public.`, `branding.`, `product.`, `site.name`, `site.timezone`, `i18n.`, `portal.trustedProxies`, `portal.trustedProxyHeader`, and each published surface's own app prefix (`noticeboard.`, `calendar.`). Every one of these is read by something the public door runs: `RateLimiter::trustedProxies()` reads `App::settings('portal.trustedProxies')` (RateLimiter.php:615 — leave it out and behind Cloudflare every visitor shares one address), `I18n::init()` reads `i18n.defaultLocale` (I18n.php:84), the timezone at bootstrap 547.

**Inheritance, as a constant `PublicSurface::SITE_ONLY_KEYS`:** per-site only, no fall-back to the portal-wide row — `public.{slug}.enabled`, `public.embedOrigins`, `public.homeSurface`, `public.allowIndexing`, `public.allowAiIndexing`. Inherit the portal-wide row — `public.enabled`, `public.cacheSeconds`, `public.pollSeconds`, `public.noticeboard.slidesSeconds`. The rule, written into §4 C: anything that names an outside party or chooses what is shown or indexed is per-site; inheritance only where the portal-wide value is "no" or a harmless number. **One resolver** `PublicSurface::setting(string $key, int $siteId): ?string` — site-only keys use the narrow query already written for `surfaceEnabled()`, the rest use `App::settingForSite()`. `index.php` builds the `$config` array for `Headers::publicPolicy(array $surface, array $config)` only through that resolver. Delete the `portal.trustedProxies` row from migration 194's seed (193 seeds it).

**Why:** PROVEN. `App::settingForSite()` (App.php:171-180) falls back to the `siteID IS NULL` row for every key; the plan decided inheritance for two of the thirteen keys. A portal-wide `public.embedOrigins` row would name a website allowed to frame every tenant's pages.

---

## Correction 10 — Published media is served by the public door's own PHP with byte ranges; static copying is removed (E1, C7 residual, MISSED 7)

**Changes:** Step 6 (whole step); §2 rows for `PublicMedia.php`, `media/.gitignore`, `cron/public-media-sweep.php`, `GdprEraser.php`; §3 address table (`/media/…` row) and both reserved-name lists; §4 (delete `tblNoticeboardUploads.isPublic` and its fold edit 4; delete `public.cron_token`); §5 (delete `mediaKinds` from both declarations and the defaults block); §7 mirror step (delete `--exclude ^media/` and its comment); §10 row 6.

**Replacement for Step 6:** "Declaration with a fourth handler `'media' => 'media.php'`; four handlers; the admin-door publish toggle." **`web/_apps/noticeboard/public/media.php`:** the same `^[a-f0-9]{32}\.(png|jpg|jpeg|gif|webp|mp4|webm)$` check on `?f=` as `noticeboard/media.php:46`, before any filesystem call; then one prepared query joining `tblNoticeboardUploads u` to `tblNoticeboardPosters p ON p.posterID = u.posterID` requiring `u.storedName = ? AND u.siteID = ? AND p.siteID = ? AND p.isPublic = 1 AND p.isDeleted = 0 AND (p.publishFrom IS NULL OR p.publishFrom <= NOW()) AND (p.publishUntil IS NULL OR p.publishUntil >= NOW())` with `$publicSiteId` bound twice; no row → the uniform 404. Path = `PORTAL_ROOT/_uploads/noticeboard/` + `basename()` of the row's `storedName` (never the request value). Send `Content-Type` from the row, `X-Content-Type-Options: nosniff`, `Cache-Control: public, max-age=31536000, immutable`, `Cross-Origin-Resource-Policy: cross-origin`, `Access-Control-Allow-Origin: *`; then `Recordings::streamFile($path, $mimeType)` (Recordings.php:49-86 — my check: it already parses `HTTP_RANGE`, answers 206/416, sends `Accept-Ranges: bytes` and `Content-Range`, and uses only `fopen`/`fread`; no session, no `Site::`, no `App::`). Byte ranges are required, not optional: Safari and iOS will not play a `<video>` from a server that does not answer range requests, and a foyer iPad is the likely display. HEAD sends headers only.

**Delete** `PublicMedia`, the sweep, `public.cron_token`, `media/.gitignore`, the deploy exclusion, `tblNoticeboardUploads.isPublic`, `mediaKinds`, the `/media/{siteKey}/…` address, the word `media` from both reserved lists, and every "the file's existence is the publication state" sentence. **GDPR lockstep becomes:** `GdprEraser::catalogue()` entries for `tblNoticeboardPosters` and `tblNoticeboardUploads` nulling `createdByID`; the file stays, because it belongs to the poster, not the person (say so in the help page). **Step 6 verification becomes:** with the surface on and no poster public, the board renders empty; mark one poster public → `/noticeboard/media?f=…` on the public host answers 200 with `Cross-Origin-Resource-Policy: cross-origin` and `Accept-Ranges: bytes`, a `Range: bytes=0-99` request answers 206; unmark → 404; a private poster's real file name → 404 byte-identical to a nonsense name. §10 row 6: 6-9 hours, Opus for `media.php`, Sonnet for the page handlers.

**Why:** PROVEN on the folder and database facts. `PORTAL_ROOT` is one folder for all three channels and PHP has no setting telling it which of `public_html`, `public_html_beta`, `public_html_dev` to write into; with one shared database, `isPublic = 1` would describe a file that exists in at most one channel's folder; the plan's sweep only deletes. The design's stated reasons for static files (the app-disabled HTML trap, Router chrome) do not apply on the public door. Cost: one PHP process per media fetch, paid once per file per display because of the one-year immutable cache. If the owner nonetheless keeps static copying (see decisions), seed `public.cron_token` with `isSensitive = 1` like eight of the ten existing `*.cron_token` rows.

---

## Correction 11 — Two of the nine calendar column names do not exist (D1)

**Changes:** §5 calendar `columnAllow` (line 844-848); Step 7 verification; `check_public_surfaces.py`.

**Replacement:** `['eventID', 'eventSlug', 'eventName', 'description', 'startDateTime', 'endDateTime', 'isAllDay', 'locationName', 'categoryID']`. Add to the comment: "`description` is the administrator's full free text. The single-event page shows it in full; the list shows a plain-text excerpt (tags stripped, 200 characters). The help page says, in those words, that an opted-in event's description is public in full." Add to `check_public_surfaces.py` and Part A: every `columnAllow` name must be a column of the surface's table in `full_schema.sql` (`check_sql_columns.py` cannot see these — they are array entries, not SQL).

**Why:** PROVEN. `tblEvents` has `eventName VARCHAR(255) NOT NULL` and `description TEXT`, no `title`, no `summary`; `widget/countdown-json.php:38-39` already carries the warning "eventName (NOT title)". `PublicQuery` builds the SELECT by strict key lookup, so the public list would show no titles or every request would error.

---

## Correction 12 — `robots.txt` and `sitemap.xml` are not real files; the door must dispatch them (E4)

**Changes:** §3 address table (the two rows); §2 Moved table rows for `sitemap.php`/`robots.php`; Step 8.

**Replacement:** "`PublicRouter` handles these two names itself, before the surface lookup and only on the un-tokened path, from a fixed map `['robots.txt' => 'robots.php', 'sitemap.xml' => 'sitemap.php']` resolved under `PORTAL_WEBROOT` with the same `realpath()` prefix check. Both need a host-resolved site; on a shared host (Correction 1) `robots.txt` is `User-agent: *` / `Disallow: /` and `sitemap.xml` is 404. **`sitemap.php` is a rewrite, not a move:** it uses `$publicSiteId` (today `Site::id()`, sitemap.php:204), `PublicSurface::setting('public.allowIndexing', …)` (today `site.allowIndexing`, line 85), and emits public-door addresses only — never `/e/{slug}` (line 230). Version-one entries: each published, indexable surface's root address, plus one entry per opted-in event through `PublicQuery::select()` with the registry's `rowFilter` and a hard `LIMIT 5000`. `robots.php` reads the two `public.allow*Indexing` keys through the resolver and disallows `/s/`."

**Why:** PROVEN. The files are `robots.php` and `sitemap.php`; the requested names do not exist on disk, so `.htaccess:67-74` rewrites them to `index.php`; today they work only through `tblRoutes` rows (`full_schema.sql:8613-8614`) and `Router.php:95`'s fallback — neither exists on the public door, and the plan's own reserved list forbids them as surfaces.

---

## Correction 13 — Leave `widget/countdown.js` on the admin door (E3)

**Changes:** delete the `countdown.js` row from §2 Moved (line 153); Step 8 no longer moves `widget/`.

**Replacement:** "`web/admin_html/widget/countdown.js` and the `widget/countdown.json` route stay exactly where they are. Customers' pages load the script from the portal host (`countdown.js:12-17`, `data-portal`) and it fetches `PORTAL + '/widget/countdown.json'` (`:141`), a `tblRoutes` row on the admin door (`full_schema.sql:4853`). Moving the file makes every existing embed 404. The public door's `widget/board.js` is a separate file; a public countdown, if wanted, is a later surface."

---

## Correction 14 — Drop `PORTAL_ADMIN_WEBROOT`; Router uses `PORTAL_WEBROOT` (A4)

**Changes:** Step 2 item 5; Step 3 item 2; §2 Router row.

**Replacement:** "`Router.php:95` and `:257` use `PORTAL_WEBROOT`, which every front controller defines as `__DIR__`. Bootstrap refuses when it is undefined (Correction 4)." Delete Step 3 item 2.

**Why:** PROVEN. On the server `__DIR__` is `admin_html`, `admin_html_beta` or `admin_html_dev`; a constant fixed to `admin_html` would make beta and alpha read live's folder for `manifest.json`, `openapi.json`, `robots.txt`, `sitemap.xml` and `/offline` — and read nothing at all during the alpha-first rollout, before live is deployed.

---

## Correction 15 — One `web/.htaccess`, and an explicit grant in each web root (C6)

**Changes:** Step 2 item 6.

**Replacement:** "One new file `web/.htaccess`: `Require all denied` and `Options -Indexes`. Add `Require all granted` as the first directive of `web/admin_html/.htaccess` (today's `web/public_html/.htaccess` has no `Require` line) and of the new `web/public_html/.htaccess`. No per-folder copies: `_uploads/`, `_backups/` and `_auth_keys/` are gitignored (`.gitignore:27,32,35`) and excluded from the mirror (`deploy.yml:129-131`), so a file placed there is neither committed nor deployed; `web/.htaccess` does deploy (the shared mirror sends `web/`'s top-level files and `LFTP_EXCLUDES` has no `.htaccess` pattern). `web/_install/.htaccess` stays untouched — its `RewriteEngine Off` neither grants nor denies, and the installer is reached by `require` from `index.php:19-21`, never by address. **Inferred, not observed:** Apache merges the parent `.htaccess` before the child's, so without the explicit grant both doors would go dark. After the first alpha deploy confirm both hostnames answer before going further."

---

## Correction 16 — Probe both hostnames; correct the DEV_NOTES claim (C8)

**Changes:** §7 post-deploy probe (1480-1497); Step 3 item 6 (DEV_NOTES 3b line 627).

**Replacement:** the probe loops over both `PUBLIC_BASE_URL` and a new `ADMIN_BASE_URL` (variables `ADMIN_BASE_URL_LIVE/_BETA/_DEV`; replace the hard-coded `https://portal.millrdsdacambridge.uk/health` at `deploy.yml:421` with the live one), same four paths, each empty value skipped with a warning. DEV_NOTES 3b: replace "The portal refuses to start if it detects this" with "The portal refuses requests that reach a front controller from a too-high web directory. A request for `/_sql/full_schema.sql` never starts PHP, so only `web/.htaccess` and the post-deploy probe cover those."

**Why:** PROVEN. The panel mistake is at least as likely on the admin hostname — DEV_NOTES 3c step 5 is exactly such an edit — and the bootstrap refusal cannot fire for a file Apache serves directly.

---

## Correction 17 — The admin door's `X-Robots-Tag` becomes unconditional (MISSED 4)

**Changes:** Step 8 (new item); §2 admin-door table (new row for `Headers::emitAdmin()`).

**Replacement:** "`Headers::emitAdmin()` drops the condition at bootstrap.php:522-541: `X-Robots-Tag: noindex, nofollow, noai, noimageai` is sent on every admin-door response, in code, whatever `site.allowIndexing` says. Mark `site.allowIndexing` and `site.allowAiIndexing` as retired on the settings group page; leave the rows. Verification: set `site.allowIndexing = 'true'` on alpha, `curl -sI` the admin door, the header is still present." (Step 2 stays byte-identical; this is a Step 8 change.)

**Why:** PROVEN. The plan seeds the new `public.*` keys but never removes the old switch from the admin door, so one settings row can still make the management portal indexable — the accident the design set out to make impossible.

---

## Correction 18 — The public website must be created under the same hosting user, with the right folder names (C10, C9)

**Changes:** §7 owner steps 4, 5, 7.

**Replacement for step 4:** "Add the new website **under the same user as `portal.example.org`** (the user shown against it in Manage Websites). DreamHost runs one user per domain; a website under a different user cannot read another user's files and cannot have its web directory set inside them." **Steps 5 and 7:** the web directory is the full path `/home/USER/portal.example.org/public_html` (alpha: `…/public_html_dev`), exactly as DEV_NOTES 3b's table; delete every `public_site` / `public_site_dev`.

---

## Correction 19 — Two route rows are seeded a step before their handlers exist (F2)

**Changes:** Step 5, Step 6.

**Replacement:** build `web/_apps/noticeboard/publish.php` and `web/_apps/help/public-site.php` in Step 5, where migration 194 seeds their rows. With Correction 10, `publish.php` only flips `isPublic`, `publishFrom`, `publishUntil` behind `PublicSurface::canTune('noticeboard')` — it needs nothing from Step 6. The help page is extended in Step 9.

**Why:** PROVEN. Between Steps 5 and 6 `check_route_targets.py` fails and both addresses 404.

---

## Correction 20 — The JSON feeds send `Access-Control-Allow-Origin: *` (E2 — see decision 3)

**Changes:** Step 8; `data.php` handlers.

**Replacement (the default unless the owner chooses otherwise):** `noticeboard/public/data.php` and `calendar/public/data.php` send `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Methods: GET` (the `widget/countdown-json.php:28-29` pattern), no `Vary: Origin`, no allow-list. `public.embedOrigins` is used for `frame-ancestors` only. Never describe an origin list on the feed as a security control: the data is public and anyone can fetch it directly.

---

## Correction 21 — Step 1 note about `Logger.php` (F4)

**Changes:** Step 1 (add an item 6).

**Replacement:** "`Logger.php:525-535` (`clientIp()`) still believes `CF-Connecting-IP` and the leftmost `X-Forwarded-For` entry. Deliberately left until Step 1 is committed (HANDOFF, 13 September). It then delegates to `RateLimiter::clientIp()`, after checking that a rate-limiter log call cannot loop back into it. Do not touch it in Step 2."

---

## Correction 22 — Refresh the line references (MISSED 8; Step 2 item 4; §10 read list)

Step 2 item 4: the header block is bootstrap.php **411-545** (the "6b" banner to the closing brace after `X-Robots-Tag`), not 400-520. §10: `RateLimiter.php:439-454` no longer describes the code after Step 1 — read `RateLimiter::clientIp()` and `trustedProxies()`. Other landmarks as they stand now: bootstrap early return 36-37, `PORTAL_ENV` block 84-134, credentials 301, `mysqli_report` 315, `preDetect` 355-359, settings 373-409, timezone 547-552, `App::init` 561, `Site::init` 571, exception handler 598-610, `I18n::init` 625; Site.php `$currentSiteID` 70, `$resolved` 76, `$enabled` 79, `init()` 104-124, `loadSiteRow()` 133, `id()` 162, `branding()` 184, `preDetect()` 710; Logger `exception()` 210, `errorPlatform()` 242 (`Site::id()` at 250), `errorPlatformForSite()` 291.

---

## Correction 23 — `public.assetBase` (F3 — see decision 4)

Default: delete the row from migration 194 §C and the `Headers`/asset code path for it. Route (c3) is "never the design" by the plan's own §9; add the setting when a customer asks for (c3).

---

# Decisions only the owner can make

Settled and not re-opened: two front doors mirrored on the server; all three delivery routes, own subdomain first; a global administrator enables publishing and delegates tuning to selected administrators; portal-wide settings are global-administrator only; the seven `SFTP_PATH_*` settings.

**1. Recurring events: is "show on the public site" a per-occurrence or a per-series decision?** (D2)
Every `tblEvents` row is one occurrence (`full_schema.sql`, tblEvents header; `calendar/export.php:14-24`), so a weekly service is 52 rows a year, each with its own checkbox under the plan as written.
- **Recommended: series-level, with per-occurrence opt-out.** The series form gets the checkbox; `manage/save.php` writes `publicSurfaceOptIn` to every occurrence of that `seriesID` from today forward; an individual occurrence can be un-ticked. Cost: extra save logic and a "future occurrences only" rule to write down; about half a day.
- Per-occurrence only, with the help page saying so. Cost: unusable for the main public use case (a weekly service); no code cost.

**2. May a delegated administrator prepare a surface before it is switched on?** (F1)
`canTune()` refuses while the surface is off.
- **Recommended: allow tuning whenever the surface is declared and the app is on;** gate only whether visitors see it. A delegate can mark posters before launch day. Cost: one condition removed; a tuned-but-dark surface is harmless.
- Keep as written: the root admin switches on an empty board first, then delegates mark posters. Cost: launch happens in two steps with a visibly empty public board in between.

**3. Should there be a list of websites allowed to load the noticeboard script feed?** (E2)
- **Recommended: no.** The feed is public data; a list gives no confidentiality (anyone can fetch it directly) and adds one more silent failure (an empty box when an origin was not added). Cost: none.
- Yes, as a management list. Cost: admin work per embedding site, a `Vary: Origin` header (worse caching), and the help page must say plainly it is not a security control.

**4. Keep `public.assetBase` for route (c3)?** (F3)
- **Recommended: drop it** until a customer asks for the one-line include. Cost: if one does, one setting and a small code path are added then.
- Keep it seeded empty. Cost: an untested code path shipped for an arrangement nobody has asked for.

**5. How much of an opted-in event does the public see?** (D1 residue)
`description` is the administrator's full free text; there is no shorter public field.
- **Recommended: full description on the single-event page, an excerpt on the list,** with the help page stating an opted-in event's description is public in full. Cost: administrators must write descriptions knowing this.
- Name, date, time and place only. Cost: thinner public pages; a new "public description" column later if that proves too thin.

**6. Accept that publishing is per organisation across all three channels, with the hostname row as the only per-channel lever?** (Correction 1)
- **Recommended: accept.** Test on alpha by adding only the alpha hostname; live stays dark until its hostname is added. Cost: one discipline to keep; a slip publishes the same organisation's content on beta or live a day early — nothing that was not meant to be public anyway, but earlier than intended.
- Channel-scoped settings (a channel column on `tblSettings`, per-channel rows everywhere the public door reads). Cost: a structural change to the settings table and every reader of it; days of work; not in this plan.

**Not decided by the owner but reversed by this review, stated so it is not missed:** Correction 10 replaces the design's static-file media publishing with PHP-served media using byte ranges. The reason is a defect, not a preference — static copying cannot know which channel's folder to write into and cannot stay in step with one shared database — so it is written as a correction. If the owner wants static publishing kept regardless, the missing pieces are a channel-to-folder convention in PHP and a sweep that creates as well as deletes, and Correction 10's deletions are reversed.

**What I did not check:** no live server, Apache, lftp or DreamHost panel was touched. The Apache `.htaccess` merge order (Correction 15), lftp's exit status on a missing directory and its `mkdir -p` (Correction 5c), and Safari's byte-range requirement (Correction 10) are from documentation and experience, not observed here; each is marked at the point it matters.