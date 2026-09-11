# BUILD PLAN — two front doors, and a public surface registry

Verified against the working tree on `claude/alpha-wip`, 11 September 2026. Where I correct the design or the security review, I say so and give the evidence.

---

## 0. Five corrections to the inputs, settled before the plan starts

**C1 — ten audit scripts hard-code `web/public_html`, not eight.** The review missed two. The full list, with line numbers I read:

```
check_bind_param_arity.py:52      check_cdn_sri.py:32
check_mobile_readiness.py:40      check_no_native_confirm.py:28,39,73
check_php_table_refs.py:45        check_route_targets.py:35
check_settings_keys.py:40         check_sql_columns.py:33
check_static_calls.py:224,52,842  check_webroot_shadowing.py:77,10,26,29,47,94,190,198
```

There are **15** scripts in `tools/audit-checks/`, not thirteen or fourteen.

**C2 — the two web roots MUST share one parent, and the review's objection to that is answered by this repo's own layout.** The review said requiring `dirname(admin) === dirname(public)` "breaks the layout most customers will actually have". That is wrong here, and the evidence is in `deploy.yml:14-18`: this account already serves **three** web roots (`public_html/`, `public_html_beta/`, `public_html_dev/`) on **three different hostnames** from **one** base directory. DreamHost lets you set an arbitrary web directory per domain; that is exactly how beta and alpha already work.

It also has to be this way. `web/public_html/index.php` finds the shared code with `dirname(__DIR__) . '/_core/bootstrap.php'`. If the public web root sits under a different parent, `_core` is not there and every public request 500s. The alternatives are uploading `_core` twice (which duplicates the database credentials in `_auth_keys/`) or inventing a runtime path-discovery mechanism. Neither is worth it.

**SUPERSEDED 11 September 2026.** The owner decided the server folders mirror the repository exactly, so the public web root is `public_html` / `public_html_beta` / `public_html_dev` and the management portal's server folder IS renamed to `admin_html` / `admin_html_beta` / `admin_html_dev`. The equality assertion is also gone: every folder is joined onto `SFTP_ROOT_DIR`, so sharing a parent is structural rather than checked. See section 7. Route (c3) — the one-line include from another domain — passes a full path and is unaffected either way.

**C3 — `PORTAL_ENV` cannot be sniffed from the directory name any more, and it cannot come from `_auth_keys/` either.** The review proposed reading the channel from `_auth_keys/`. That does not work: `_auth_keys/` lives under the *shared* base, so all three channels read the same file. The directory sniff breaks anyway on `admin_html`, which contains neither `public_html_dev` nor `public_html` — see N2.

**So: the deploy workflow writes a one-line `.channel` file into each web root** (`live`, `beta`, `dev`), the front controller reads it, and bootstrap refuses to run without it. Deterministic, per-channel, written by the thing that knows the answer.

**C4 — v1 public surfaces are read-only and session-free, full stop.** The public door refuses every HTTP method except `GET` and `HEAD`, at the top, unconditionally, before any registry lookup. This disposes of review findings 20, 21, 22 and 34 (public writes, CSRF versus caching, Captcha reading the wrong tenant's settings, shared session store) by removing the surface they live on. `acceptsPost` stays in the declaration as the documented future hook; the self-test asserts no shipped surface sets it `true`.

**C5 — `Site::id()` refusal is scoped to the public door.** The review wanted `$currentSiteID` to start at `0` and `id()` to throw whenever `$resolved === false`. That is a larger blast radius than it looks — `Site::init()` runs at `bootstrap.php:543`, and anything reachable before that would start throwing. The surgical version closes the same hole with no risk to the admin door, and I verified it is sufficient: `grep -n "Site::" web/_core/bootstrap.php` returns calls at only lines 329 (`preDetect`) and 543 (`init`), and `App::init()` does not touch `Site` at all. The public door skips both, so nothing between `PORTAL_ROOT` and the front controller's own `Site::initPublic()` call can reach `Site::id()`.

---

# 1. THE ORDER OF WORK

Ten steps. Each one leaves the portal working and deployable. **The single most important sequencing decision is Step 3: the rename lands with `web/public_html/` absent from the repository entirely.** During that window there is no directory called `public_html` in the tree, so an old workflow finds nothing to mirror and fails, and a new workflow has nothing to push into the public path. The dangerous meaning-swap has no window to happen in.

### Step 0 — Branch and issue
Branch `claude/public-door` off `alpha`. One GitHub issue with the ten steps as a checklist.

**Verified by:** nothing yet.

### Step 1 — Pre-existing defects that the public door would otherwise inherit
No rename. No new directory. Nothing about the public door is reachable.

1. `RateLimiter::getClientIp()` (`web/_core/RateLimiter.php:439-454`) — trust `CF-Connecting-IP` and `X-Forwarded-For` only when `REMOTE_ADDR` is in `portal.trustedProxies`. Seeded empty, so today's default is "trust nothing".
2. `web/_apps/settings/save.php` and the delete branch of `web/_apps/settings/index.php` — refuse a `public.` key, and refuse editing a row whose `siteID IS NULL`, unless `App::isRootAdmin()`. Same in `web/_apps/admin/settings/group.php` and its save handler.
3. `Portal\Core\Gatekeeper` — wire `Gatekeeper::enforce()` into the front controller for non-live channels. `grep -rn "Gatekeeper" web/` finds only the class itself and a language string; the class that keeps strangers out of alpha and beta is called from nowhere.
4. `AppRegistry::all()` — wrap `require $file` in `try { } catch (\Throwable) { continue; }` so one broken app file cannot take down both doors, including the admin page you would fix it from.
5. `Logger::errorPlatformForSite(?int $siteId, ...)` — a sibling of `errorPlatform()` that takes the site explicitly instead of stamping `Site::id()`.

**Verified by:** all 15 audit checks green; `php -l` clean; sign in and change a non-`public.` setting as a site admin (still works); try to edit a global row as a non-root admin (refused with a clear message); `php tools/report-builder-selftest.php` and `php tools/gdpr-coverage-selftest.php` still exit 0.

### Step 2 — Door identity, still one door
The admin portal is still at `web/public_html/`. Nothing moves.

1. `web/public_html/index.php` gains, before anything else: `define('PORTAL_DOOR', 'admin');`, `define('PORTAL_WEBROOT', __DIR__);`, and a `.channel` read that defines `PORTAL_CHANNEL`.
2. `web/_core/bootstrap.php` — refuse when `PORTAL_DOOR` or `PORTAL_CHANNEL` is undefined (HTTP 500, one line of text, no stack trace). Replace the `DOCUMENT_ROOT` sniff with `PORTAL_CHANNEL`, keeping `getenv('PORTAL_ENV')` as the first branch for local work, and defaulting to `prod` when everything else is missing.
3. `web/_core/bootstrap.php` — the document-root refusal (review finding 3): if `realpath($_SERVER['DOCUMENT_ROOT'])` equals `PORTAL_ROOT` or is an ancestor of it, HTTP 500 and stop.
4. Extract bootstrap's header block (roughly lines 400-520) into `web/_core/Headers.php` as `Headers::emitAdmin()`. Bootstrap calls it. **Byte-identical output** at this step — this is a move, not a change.
5. `web/_core/Router.php:95` and `:257` — replace the two hard-coded `PORTAL_ROOT . '/public_html'` with a new constant `PORTAL_ADMIN_WEBROOT`, defined in bootstrap as `PORTAL_ROOT . DIRECTORY_SEPARATOR . 'public_html'` for now.
6. Deny-all `.htaccess` files in `web/`, `web/_core/`, `web/_apps/`, `web/_sql/`, `web/_lang/`, `web/_vendor/`, `web/_uploads/`, `web/_backups/`, `web/_auth_keys/`. **Do not touch `web/_install/.htaccess`** — it deliberately turns rewriting off and the installer is reached by `require` from the front controller.
7. `deploy.yml` — write `.channel` into the staged web root before mirroring.

**Verified by:** deploy to alpha; sign in; `curl -sI` the admin door and diff the header set against a capture taken before Step 2 — it must be identical. Confirm `/admin`, `/dashboard`, `/expenses` all load. Confirm a request with `PORTAL_DOOR` undefined (simulate by renaming `.channel` away — wait, that tests channel; instead run `php web/_core/bootstrap.php` directly from the shell) returns the refusal rather than running.

### Step 3 — The rename, with no `public_html` in the tree
1. `git mv web/public_html web/admin_html`. No case change, so no two-step rename needed.
2. `PORTAL_ADMIN_WEBROOT` becomes `.../admin_html`.
3. `web/admin_html/.door` created, containing the single word `admin`.
4. All ten audit scripts repointed to `web/admin_html` (C1). `check_webroot_shadowing.py` keeps one pass, against `admin_html`.
5. `deploy.yml` rewritten: nine secrets, the retired-secret refusal, the `.door` guards, the parent-equality assertion, `^admin_html/` added to `WEB_ROOT_EXCLUDES`, and the `public_dir` summary bug fixed. The public mirror step is written but is a no-op because `web/public_html/` does not exist — the workflow skips it with a notice when the source directory is absent.
6. Docs: `DEV_NOTES.md`, `CLAUDE.md` directory layout, `README.md`.

**Verified by, in this order, and do not proceed past a failure:**
- `python3 tools/audit-checks/check_webroot_shadowing.py` — then **prove it still fires**: `mkdir web/admin_html/dashboard`, re-run, confirm it reports `/dashboard` as shadowed, `rmdir`. A check reading the wrong directory reports zero findings, which looks exactly like success.
- Repeat that planted-fault proof for the other nine scripts. `check_route_targets.py` — delete a route's handler temporarily and confirm it reports. `check_settings_keys.py` — add a bogus `App::settings()['nonsense']['key']` read and confirm it reports. And so on. This is the project's own recorded lesson and it is worth the hour.
- `grep -rn "public_html" web/ tools/ .github/ --include='*.php' --include='*.py' --include='*.yml'` returns only intentional references (`public_html_landing`, `public_html_redir`, historic comments).
- Set the nine secrets, delete the three old ones, run the one-time first-run dispatch on **alpha only**. Sign in on the alpha admin door. `curl -sI` it and confirm `X-Robots-Tag: noindex` is present.

### Step 4 — The empty public door
`web/public_html/` is created new. It answers 404 to everything, because every gate defaults off and no app declares a public surface yet.

New files: the public front controller, `.htaccess`, `.door`, a bare `error.php`, its own small `assets/`, `media/.gitignore`. New classes: `PublicRouter`, `PublicSurface`, `PublicSites`, `PublicErrors`, `PublicMaintenance`, plus `Headers::emitPublic()`. `AppRegistry` gains `publicSurfaces()` and `assertSelfConsistent()`. `Site::initPublic()` and the public-door `Site::id()` refusal land here.

**Verified by:** `php tools/public-door-selftest.php` exits 0 (§8). Deploy to alpha. Then, against the alpha public host:
- `curl -sI https://<public alpha host>/` → 404
- `curl -sI https://<public alpha host>/admin` → 404, and the body is byte-identical to `/anything-else`
- `curl -sI https://<public alpha host>/dashboard` → 404
- `curl -sI https://<public alpha host>/_sql/full_schema.sql` → not 200 (this is the document-root probe, review finding 3)
- `curl -sI https://<public alpha host>/_uploads/` → not 200
- Response headers on the public door: **no** `X-Frame-Options`, **no** `Set-Cookie`, `X-Robots-Tag: noindex` present (because alpha is not the live channel), `Cross-Origin-Resource-Policy: same-origin` on the HTML document.
- `curl -sI https://<public alpha host>/assets/css/public.css` → `Cross-Origin-Resource-Policy: cross-origin`.

### Step 5 — Migration 193, the permission model, and `/admin/public`
The database gains its new shape and the admin door gains the pages that control publication. **Still nothing is published**, because every setting seeds `'false'` and no grants exist.

**Verified by:** `python3 tools/audit-checks/check_mariadb_only_ddl.py`, `check_migration_idempotency.py`, `check_schema_seed_parity.py`, `check_sql_columns.py`, `check_settings_keys.py`, `check_route_targets.py` all green. Run the `tools/e2e-migrations` harness — it must replay 193 twice with no error. On alpha: `/admin/public` loads for a root admin and returns 403 for a site admin; granting and revoking works; `/admin/public/hosts` refuses a host already claimed by another site with a plain message.

### Step 6 — The noticeboard public surface
Declaration, three handlers, `PublicMedia`, the reconciliation sweep, the GDPR and offboarding lockstep, the admin-door publish toggle, the help page.

**Verified by:** turn on `public.enabled` and `public.noticeboard.enabled` for site 1 on alpha with **no poster marked public** — the board renders with zero posters, not an error. Mark one poster public, confirm it appears and its image loads from `/media/...` with `Cross-Origin-Resource-Policy: cross-origin`. Unmark it, confirm the file is gone from disk (`curl` the media address → 404). Run `php web/_apps/cron/public-media-sweep.php` with the token and confirm it reports zero orphans. Then hand-place a junk file in the media directory and confirm the sweep deletes it.

### Step 7 — The calendar public surface
Declaration, three handlers, `PublicQuery`, the `publicSurfaceOptIn` column and its checkbox on the existing event form.

**Verified by:** with the surface on and **no event opted in**, the public list is empty. This is the single most important assertion in the whole plan — `tblEvents.isPublic` defaults to `1` at `full_schema.sql:793`, so without the new column this step would republish every event every tenant has ever created. Opt one event in, confirm it appears. Confirm `locationGeoLat`, `locationGeoLng`, `locationW3W`, `submitterEmail`, `submitterName`, `moderationNote` and `cancelReason` appear nowhere in the page source or the JSON feed.

### Step 8 — Embeds, robots and sitemap
Iframe embed, script embed with visible fallback, the CORS allowlist, `frame-ancestors`, the snippet generator on `/admin/public`. `robots.php` and `sitemap.php`/`sitemap.xsd` move to the public door; the admin door's `robots.php` becomes a fixed two-line refusal and its `sitemap.php` is deleted. Migration 194 removes the admin `sitemap.xml` route row and reseeds `robots.txt`.

**Verified by:** build a throwaway HTML page on a different origin, paste both snippets, open it. The iframe renders with the origin on the allowlist and shows the browser's refusal when it is not. The script embed renders, and with the network blocked it shows the fallback text rather than an empty box. `curl -sI` the public robots address → `Disallow: /media/` and `Disallow: /s/` present, `Sitemap:` line present. `curl -sI` the admin robots address → `Disallow: /` and nothing else.

### Step 9 — Documents, second opinion, commit
`CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md`, `.claude/CLAUDE.md`, the help page. Then:

```bash
codex exec --skip-git-repo-check "Review the diff on claude/public-door. Focus on: can any address registered in tblRoutes with isProtected=1 be reached through web/public_html/index.php; does any public handler read a per-site setting from the \$SETTINGS snapshot rather than App::settingForSite(); does migration 193 replay as a no-op on MySQL 8.0; can the deploy workflow ever mirror web/admin_html/ into the public remote path."
```

Treat the answer as a second opinion, check each point against the code, and record the outcome in the commit message.

---

# 2. EVERY FILE TO CREATE, MOVE OR CHANGE

### Moved

| Path | What and why |
| --- | --- |
| `web/public_html/` → `web/admin_html/` | The management portal keeps every file it has; only the directory name changes, so that the name `public_html` can be reused for the genuinely public site without ever meaning both things at once. |
| `web/admin_html/sitemap.php` → `web/public_html/sitemap.php` (Step 8) | The management portal has nothing to offer a search engine. The sitemap belongs on the door that does. |
| `web/admin_html/sitemap.xsd` → `web/public_html/sitemap.xsd` | Travels with the sitemap it describes. |
| `web/admin_html/robots.php` → `web/public_html/robots.php` (Step 8) | The generator that can say "yes, index this" only makes sense on the public door. |
| `web/admin_html/widget/countdown.js` → `web/public_html/widget/countdown.js` (Step 8) | An embeddable script belongs with the public site. It is a real static file, which is the only reason it works cross-origin today; that property survives the move unchanged. |

### Created — the public web root

| Path | What and why |
| --- | --- |
| `web/public_html/index.php` | The public front controller. Declares `PORTAL_DOOR='public'`, reads `.channel`, requires bootstrap, refuses any method but GET and HEAD, resolves the site, then hands off to `PublicRouter`. It never calls `Router::dispatch()` and never reads `tblRoutes`. |
| `web/public_html/.htaccess` | Its own rewrite rules. Blocks hidden files, blocks every `.php` address except `/index.php` and `/error.php`, sets `Cross-Origin-Resource-Policy: cross-origin` on `assets/` and `media/`, and turns off PHP execution inside `media/`. |
| `web/public_html/.door` | One line containing `public`. The deploy workflow compares it against the remote copy before moving a single byte, so a push cannot be aimed at the wrong directory. **Shadowing note: harmless — `.htaccess` blocks any address beginning with a dot, so it returns 403 and is not addressable.** |
| `web/public_html/error.php` | The Apache `ErrorDocument` target for the public door. A self-contained page with no portal chrome and no include from `_core/templates/`. |
| `web/public_html/assets/css/public.css` | The public site's own stylesheet. It cannot use the admin door's, because a `<link rel=stylesheet>` is a `no-cors` request and the admin door sends `Cross-Origin-Resource-Policy: same-origin`, which blocks exactly that. **Shadowing note: `assets` is a real folder, so no surface may be named `assets`.** |
| `web/public_html/assets/fonts/plus-jakarta-sans/*.woff2` | Copies of the brand font files. Duplicating two files is cheaper than a cross-origin font request that would be blocked anyway. |
| `web/public_html/assets/js/public-board.js` | Progressive enhancement for the noticeboard page — auto-advance on the slides view and the poster lightbox. The page works fully without it. |
| `web/public_html/media/.gitignore` | Holds the directory in git while ignoring its contents. Published poster images and videos are copied here so Apache can serve them without PHP ever starting. **Shadowing note: `media` is a real folder, so no surface may be named `media`. It is also excluded from the deploy mirror — see §7.** |
| `web/public_html/widget/board.js` | The script embed (Step 8). A real static file, so Apache serves it with no `Cross-Origin-Resource-Policy` header at all, which is the only reason a classic `<script src>` from another site works. **Shadowing note: `widget` is a real folder, so no surface may be named `widget`.** |

### Created — shared framework classes

| Path | What and why |
| --- | --- |
| `web/_core/Headers.php` | The one place response headers are decided. `emitAdmin()` is bootstrap's old block, moved unchanged. `emitPublic()` is the public door's different block. `publicPolicy()` is a pure function returning the header array, so the self-test can check it with no web server. |
| `web/_core/PublicRouter.php` | Resolves a public address to a handler file through the registry's closed map, applies the five gates in order, and returns the same 404 for every refusal. Contains `resolveHandler()`, a pure static function with no database and no superglobal reads, so the self-test can attack it directly. |
| `web/_core/PublicSurface.php` | The gates and the permission model. Every read of a `public.*` setting goes through here; nothing else may read one. |
| `web/_core/PublicSites.php` | Works out which tenant a public request is for, from an exact host match or a token in the address. Refuses rather than guessing. |
| `web/_core/PublicMedia.php` | Copies a published poster's file into the public web root, deletes it when unpublished, and reconciles the directory against the database. |
| `web/_core/PublicQuery.php` | Builds a `SELECT` for a public surface using only column names the registry lists, and forces `siteID = ?` in first, outside any brackets the handler adds, so no filter can slip past tenancy. Same discipline as `ReportBuilder::compile()`. |
| `web/_core/PublicErrors.php` | Bare error pages for the public door. Exists because bootstrap's exception handler currently ends at `Router::renderError()` → `error-500.php` → `header.php`, which would print the tenant's whole navigation and app list to an anonymous stranger, and re-send `X-Frame-Options: SAMEORIGIN` (`header.php:99`), breaking a framed embed at the worst possible moment. |
| `web/_core/PublicMaintenance.php` | A small "temporarily unavailable" page with `Retry-After`, for when the installed version is behind the code. It never calls `Maintenance::currentUserCanBypass()`, because that reaches `App::isAdmin()` → `App::user()` → the session, and the public door has no session. |

### Changed — shared framework

| Path | What and why |
| --- | --- |
| `web/_core/bootstrap.php` | Refuses without `PORTAL_DOOR` and `PORTAL_CHANNEL`; refuses when the web directory is set at or above `web/`; channel now comes from the `.channel` file rather than a directory-name guess, and defaults to `prod` instead of `dev`; header block extracted to `Headers`; on the public door it skips `Site::preDetect()` and `Site::init()`, loads a restricted `$SETTINGS`, and routes exceptions to `PublicErrors`. |
| `web/_core/Router.php` | Lines 95 and 257 use `PORTAL_ADMIN_WEBROOT` instead of a hard-coded `public_html`. Without this, an admin route whose handler is missing from `_apps/` would be resolved out of the **public** web root after the rename. |
| `web/_core/Site.php` | `initPublic(int $siteId)` sets the site explicitly and marks it resolved. `id()` throws a `RuntimeException` when `PORTAL_DOOR === 'public'` and `initPublic()` has not run — so a forgetful public handler produces a 500 rather than silently serving tenant 1's data, which is what `$currentSiteID = 1` at line 70 does today. |
| `web/_core/AppRegistry.php` | `require` wrapped in try/catch; defaults block gains a `public` sub-array so every key exists; new `publicSurfaces()` and `assertSelfConsistent()`. |
| `web/_core/RateLimiter.php` | `getClientIp()` trusts `CF-Connecting-IP` / `X-Forwarded-For` only from a configured trusted proxy. Today any visitor can send one of those headers and get a fresh identity per request, which makes every per-IP bucket in the codebase decorative. |
| `web/_core/Logger.php` | New `errorPlatformForSite(?int $siteId, ...)`. `errorPlatform()` stamps `Site::id()` at line 236, so without this every public-door refusal for every tenant would be filed under tenant 1. |
| `web/_core/GdprEraser.php` | Catalogue entries for `tblNoticeboardPosters` and `tblNoticeboardUploads`, plus a bespoke step calling `PublicMedia::purgeForUser()`. `grep -n "noticeboard" web/_core/GdprEraser.php` returns nothing today — a member's face on a poster already survives an erasure request, and static publishing would double that surface. |
| `web/_core/apps/noticeboard.php` | Gains its `public` declaration (§5). |
| `web/_core/apps/calendar.php` | Gains its `public` declaration (§5). |

### Changed — admin door

| Path | What and why |
| --- | --- |
| `web/_apps/settings/save.php` | Refuses a `public.` key and refuses editing a `siteID IS NULL` row unless the user is a root admin. Today's `UPDATE ... WHERE settingID = ? AND (siteID = ? OR siteID IS NULL)` at lines 80-89 lets any site administrator of any tenant edit a portal-wide row. |
| `web/_apps/settings/index.php` | The same two refusals on its delete branch. |
| `web/_apps/admin/settings/group.php` + its save handler | Refuses any `public.` key. It writes `siteID = NULL` unconditionally, so one click would otherwise publish a surface for every tenant. |
| `web/_apps/calendar/manage/index.php` + `save.php` | A "Show this on the public site" checkbox writing `publicSurfaceOptIn`, defaulting to off. |
| `web/_apps/noticeboard/index.php` | A "Show on the public board" control per poster, visible only when the surface is published and the user passes `PublicSurface::canTune('noticeboard')`. |
| `web/_apps/offboarding/do.php` | One step deleting the leaver's `tblPublicSurfaceGrants` rows. |
| `web/admin_html/robots.php` | Becomes a fixed `User-agent: *` / `Disallow: /` with no settings reads at all. The dangerous configuration — one settings row that could make both doors indexable — stops existing rather than being defended. |

### Created — admin pages and handlers

| Path | What and why |
| --- | --- |
| `web/_apps/admin/public/index.php` | The root administrator's control page: master switch, per-surface switches, indexing, embed origins, the generated embed snippets. |
| `web/_apps/admin/public/save.php` | The only handler in the codebase permitted to write a `public.` settings key. |
| `web/_apps/admin/public/grants.php` | Lists and manages who may tune each published surface. |
| `web/_apps/admin/public/grant-save.php` | Creates and revokes a delegation. Root admin only. |
| `web/_apps/admin/public/hosts.php` | Lists the hostnames that name this tenant's public site. |
| `web/_apps/admin/public/host-save.php` | Adds and removes one. Root admin only. |
| `web/_apps/admin/public/token.php` | Generates or rotates the site's public token. Root admin only. |
| `web/_apps/noticeboard/publish.php` | Marks one poster public or private, and copies or deletes its media file in the same request. |
| `web/_apps/cron/public-media-sweep.php` | Token-gated. Lists the published media directory and deletes anything with no matching published poster, so the database stays the record of what is published. |
| `web/_apps/help/public-site.php` | The in-app guide, in plain English, for an administrator setting this up. |

### Created — public handlers

| Path | What and why |
| --- | --- |
| `web/_apps/noticeboard/public/board.php` | The public poster wall. Its own sixty-line query; it deliberately does not reuse `api/list.php`, which returns every non-deleted poster for the site and is gated by `ApiAuth::requireRead`. One function carrying two security postures is how the easy one gets got wrong. |
| `web/_apps/noticeboard/public/poster.php` | One poster, full size, with its own link preview tags. |
| `web/_apps/noticeboard/public/slides.php` | The foyer-display view. Polls for changes at a server-set interval; it does not stream, because DreamHost's shared FastCGI kills long requests (`DEV_NOTES.md:3616`). |
| `web/_apps/noticeboard/public/data.php` | The JSON feed the script embed fetches. Served by the public front controller, **not** by ApiRouter — so no `api.*.enabled` seed is needed and the ApiRouter trap does not apply. |
| `web/_apps/calendar/public/list.php` | The public events list. Note the directory is keyed by the **app slug** (`calendar`), while the address is keyed by the **surface name** (`events`). |
| `web/_apps/calendar/public/event.php` | One event. |
| `web/_apps/calendar/public/feed.php` | An iCalendar subscription file of the opted-in events. |
| `web/_apps/calendar/public/data.php` | The JSON feed for the events script embed. |

### Created — tools

| Path | What and why |
| --- | --- |
| `tools/public-door-selftest.php` | Proves the boundary holds, with no database. §8. |
| `tools/audit-checks/check_public_surfaces.py` | Every declared handler file exists, no surface name collides with anything real in the public web root, no duplicate surface names, no forbidden app declares one. |
| `tools/audit-checks/check_public_settings_writes.py` | Every PHP file that writes `tblSettings` either refuses the `public.` prefix or is on a short explicit allow-list. |

### Created — SQL

`web/_sql/193_public_door.sql`, the matching fold into `web/_sql/full_schema.sql`, and `web/_sql/194_public_robots_sitemap.sql` at Step 8.

---

# 3. EVERY ADDRESS

### The rule that makes this list short

**The public door has no rows in `tblRoutes` at all.** Its addresses come from the registry, compiled in code, at request time. This is deliberate and it is the lesson of the ApiRouter trap: two routing tables for one door is how you get unreachable handlers and orphan rows. `check_route_targets.py` keeps scanning only the admin door, and must **not** be taught about the public one.

### Public door addresses — served by `web/public_html/index.php`, no `tblRoutes` row, no login ever

| Address | Handler | Notes |
| --- | --- | --- |
| `/` | `public.homeSurface` names one, else 404 | Seeded empty, so the bare address 404s until a root admin chooses. |
| `/noticeboard` | `_apps/noticeboard/public/board.php` | |
| `/noticeboard/poster` | `_apps/noticeboard/public/poster.php` | |
| `/noticeboard/slides` | `_apps/noticeboard/public/slides.php` | |
| `/noticeboard/data` | `_apps/noticeboard/public/data.php` | JSON. Not an `api/*` address. |
| `/events` | `_apps/calendar/public/list.php` | |
| `/events/event` | `_apps/calendar/public/event.php` | |
| `/events/ics` | `_apps/calendar/public/feed.php` | |
| `/events/data` | `_apps/calendar/public/data.php` | JSON. |
| `/s/{32-hex}/…` | any of the above | The token names the tenant. Everything after it is one of the addresses above. |
| `/robots.txt` | `web/public_html/robots.php` | Real file, served by Apache's rewrite fallthrough. |
| `/sitemap.xml` | `web/public_html/sitemap.php` | Real file. |
| `/widget/board.js` | real static file | Apache serves it; PHP never runs, which is the whole point. |
| `/media/{siteKey}/{32-hex}.{ext}` | real static file | Apache serves it. |
| `/assets/…` | real static files | |

Reserved first segments no surface may use, asserted by `check_public_surfaces.py` and the self-test: `s`, `media`, `assets`, `widget`, `robots.txt`, `sitemap.xml`, `sitemap.xsd`, `favicon.ico`, `error.php`, `index.php`, `.well-known`.

### Admin door route rows added by migration 193 — `web/admin_html/index.php`

| `routeKey` | `targetFile` | `isProtected` |
| --- | --- | --- |
| `admin/public` | `admin/public/index.php` | 1 |
| `admin/public/save` | `admin/public/save.php` | 1 |
| `admin/public/grants` | `admin/public/grants.php` | 1 |
| `admin/public/grant-save` | `admin/public/grant-save.php` | 1 |
| `admin/public/hosts` | `admin/public/hosts.php` | 1 |
| `admin/public/host-save` | `admin/public/host-save.php` | 1 |
| `admin/public/token` | `admin/public/token.php` | 1 |
| `noticeboard/publish` | `noticeboard/publish.php` | 1 |
| `help/public-site` | `help/public-site.php` | 0 |

Every one of these is a clean address with no `.php` on the end, and every handler also re-checks its own permission rather than trusting `isProtected`. None collides with a real file or folder in `web/admin_html/` — there is no `admin` folder there any more (migration 189 / #483 removed it), and no `noticeboard` or `help` folder either.

### `api/*` addresses

**None.** The two JSON feeds are public-door surfaces, so they bypass ApiRouter entirely and need no `api.{app}.{action}.enabled` seed. This is stated explicitly in migration 193's header so the next person does not add one out of habit.

### Route row removed by migration 194 (Step 8)

`sitemap.xml` — the admin door stops offering a sitemap. `robots.txt` keeps its row, now pointing at the fixed refusal.

---

# 4. MIGRATION 193 IN FULL

`web/_sql/193_public_door.sql`

```sql
-- =============================================================================
-- Migration 193: the public front door
-- =============================================================================
-- Adds the database shape that a genuinely public website needs, and nothing
-- else. NOTHING IS PUBLISHED BY THIS MIGRATION. Every switch it adds is off,
-- every new "show this publicly" column starts at no, and no delegation exists
-- until somebody creates one by hand.
--
-- -----------------------------------------------------------------------------
-- WHY A NEW COLUMN ON tblEvents, WHEN isPublic ALREADY EXISTS
-- -----------------------------------------------------------------------------
-- This is the most important paragraph in the file.
--
-- tblEvents already has a column called isPublic. It sounds like the answer.
-- It is not, because it was created with a default of YES (full_schema.sql
-- line 793, "TINYINT(1) NOT NULL DEFAULT 1"). There has never been a public
-- calendar, so nobody has ever had a reason to untick it. Every event every
-- organisation has ever created is therefore marked public already: elders'
-- meetings, safeguarding reviews, pastoral visits, funeral planning held at a
-- family's home. Switching a public calendar on and reading that column would
-- publish all of it in one click.
--
-- A default that nobody chose is not a decision. So this migration adds a
-- SECOND column, publicSurfaceOptIn, defaulting to NO, and the public calendar
-- requires BOTH. Publication then has to be a decision taken after the public
-- site existed, which is the only kind of decision worth trusting.
--
-- The same reasoning gives every new column here a default of no.
--
-- -----------------------------------------------------------------------------
-- WHAT REPLAY DOES
-- -----------------------------------------------------------------------------
-- Nothing, on a database that already has these changes. The installer runs
-- full_schema.sql and then replays every numbered migration regardless of what
-- has already run, so each change below asks information_schema first and only
-- acts if it is still needed. The short "IF NOT EXISTS" form that MariaDB
-- accepts on ALTER is rejected by MySQL 8 with error 1064, so it is not used.
--
-- @package   Portal\Core
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- =============================================================================

-- #############################################################################
-- 🏛️  A. Two new tables
-- #############################################################################

-- Who may tune a published public surface.
--
-- There are two different powers here and they are stored differently on
-- purpose. "This organisation may have a public noticeboard at all" is a
-- property of the installation, so it is a setting (section D below), and only
-- the global administrator may change it. "This particular person may choose
-- which posters appear on it" is a property of a person, so it is a row here.
--
-- It cannot be a role. tblRoles has two columns and App::hasRole() joins
-- tblUserRoles with no site column at all, so a "public editor" role would make
-- somebody a public editor for every app on every site — which is exactly the
-- "all administrators" the owner ruled out. The grant is three things at once
-- (which site, which app, which person) and only a table holds three cleanly.
CREATE TABLE IF NOT EXISTS `tblPublicSurfaceGrants` (
    `grantID`     INT NOT NULL AUTO_INCREMENT,
    `siteID`      INT NOT NULL,
    `appSlug`     VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL
                  COMMENT 'An app slug from web/_core/apps/. Checked against the registry whenever a row is written; never taken on trust from a web request.',
    `userID`      INT NOT NULL,
    `grantedByID` INT DEFAULT NULL
                  COMMENT 'Who delegated this, so a grant can always be traced back to the administrator who made it. Empty once that person has asked to be forgotten.',
    `grantedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`grantID`),
    UNIQUE KEY `uq_public_grant` (`siteID`, `appSlug`, `userID`),
    KEY `idx_public_grant_user` (`userID`),
    CONSTRAINT `fk_psg_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_psg_user`    FOREIGN KEY (`userID`)      REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_psg_granter` FOREIGN KEY (`grantedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Who may tune a published public surface. Empty by default — a delegation only exists once a global administrator creates one.';


-- Which hostname belongs to which organisation's public site.
--
-- This is NOT tblSites.hostPattern, and the break is deliberate. That column is
-- misnamed — it is an exact string comparison, not a pattern — it has no unique
-- key, so two organisations can claim one hostname and the query's LIMIT 1
-- picks whichever the database happens to return first, and it does not strip
-- the port number. It is also already load-bearing for signing in. Giving one
-- column two meanings, one of them facing the open internet, is how quiet
-- cross-tenant mistakes happen.
CREATE TABLE IF NOT EXISTS `tblPublicHosts` (
    `publicHostID` INT NOT NULL AUTO_INCREMENT,
    `siteID`       INT NOT NULL,
    `hostName`     VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL
                   COMMENT 'Exactly the hostname, lower case, no port and no https:// — for example noticeboard.example.org',
    `isActive`     TINYINT(1) NOT NULL DEFAULT 1,
    `createdByID`  INT DEFAULT NULL,
    `createdAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`publicHostID`),
    UNIQUE KEY `uq_public_host` (`hostName`),
    KEY `idx_public_host_site` (`siteID`),
    CONSTRAINT `fk_ph_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_ph_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='One hostname, one organisation. The unique key enforces it, rather than a query hoping for the best.';


-- #############################################################################
-- ✏️  B. New columns, each guarded so a replay does nothing
-- #############################################################################

-- tblSites.publicToken — names the organisation when the hostname cannot.
--
-- When somebody embeds our noticeboard in a page on their own website, the
-- browser is talking to US, so the Host header is our hostname and says nothing
-- about whose noticeboard is wanted. The token, sitting in the address, does.
--
-- It is NOT a secret. It appears in a script tag on a public page. It is a
-- NAME, not a key. What it buys is that it cannot be guessed into naming a
-- different organisation, and that rotating it cuts off an embed without
-- touching anything else. Empty until an administrator generates one.
--
-- Lower-case hexadecimal is not cosmetic: the address is lower-cased before it
-- is read, so a token with a capital letter in it would work on the machine
-- that made it and fail everywhere else.
SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSites' AND COLUMN_NAME = 'publicToken'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblSites` ADD COLUMN `publicToken` CHAR(32) DEFAULT NULL COMMENT ''Lower-case hexadecimal name for this organisation''''s public site, used when the hostname cannot identify it. Not a secret. Empty until generated.'' AFTER `hostPattern`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSites' AND INDEX_NAME = 'uq_site_public_token'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblSites` ADD UNIQUE KEY `uq_site_public_token` (`publicToken`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- tblEvents.publicSurfaceOptIn — see the long note at the top of this file.
-- Default 0: nothing is published until somebody chooses it, one event at a
-- time, on a form that did not exist before now.
SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND COLUMN_NAME = 'publicSurfaceOptIn'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblEvents` ADD COLUMN `publicSurfaceOptIn` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Show this event on the public website. Separate from isPublic, which defaults to yes and therefore cannot be trusted as a publishing decision — see migration 193''''s header.'' AFTER `isPublic`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblEvents' AND INDEX_NAME = 'idx_event_public_surface'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblEvents` ADD KEY `idx_event_public_surface` (`siteID`, `publicSurfaceOptIn`, `isPublic`, `status`, `isDeleted`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Belt and braces. The column is new so every row already has 0; this says so
-- out loud, and makes a replay after a hand-edited default still correct.
UPDATE `tblEvents` SET `publicSurfaceOptIn` = 0 WHERE `publicSurfaceOptIn` IS NULL;


-- tblNoticeboardPosters.isPublic — default 0.
-- Without this, switching the public board on would publish every poster the
-- organisation has ever made, including any nobody decided to publish. That is
-- the same fault migration 189 was written to close on the old calendar widget.
SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblNoticeboardPosters' AND COLUMN_NAME = 'isPublic'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblNoticeboardPosters` ADD COLUMN `isPublic` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Show this poster on the public board. No by default — publishing is a per-poster decision.'' AFTER `isDeleted`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- publishFrom / publishUntil — the automatic expiry the specification asked
-- for, enforced by the database query rather than by the browser. The poster
-- wall's own JavaScript has a helper that hides a past event, but that is a
-- display choice made after the data has already been sent, so it is not a
-- control. Empty means "no limit at that end".
SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblNoticeboardPosters' AND COLUMN_NAME = 'publishFrom'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblNoticeboardPosters` ADD COLUMN `publishFrom` DATETIME DEFAULT NULL COMMENT ''Do not show publicly before this moment. Empty means no earliest date.'' AFTER `isPublic`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblNoticeboardPosters' AND COLUMN_NAME = 'publishUntil'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblNoticeboardPosters` ADD COLUMN `publishUntil` DATETIME DEFAULT NULL COMMENT ''Stop showing publicly after this moment. Empty means no latest date.'' AFTER `publishFrom`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblNoticeboardPosters' AND INDEX_NAME = 'idx_poster_public'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblNoticeboardPosters` ADD KEY `idx_poster_public` (`siteID`, `isPublic`, `isDeleted`, `publishFrom`, `publishUntil`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- tblNoticeboardUploads.isPublic — kept in step with the poster that uses the
-- file. It exists so that the housekeeping sweep can tell, from the database
-- alone, which files are allowed to sit in the public folder. media.php looks a
-- file up by its stored name only, with no check of which organisation or which
-- poster it belongs to; guessing a 32-character random name is not realistic,
-- but the check should not have to rest on that.
SET @needed := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblNoticeboardUploads' AND COLUMN_NAME = 'isPublic'
);
SET @sql := IF(@needed = 1,
    'ALTER TABLE `tblNoticeboardUploads` ADD COLUMN `isPublic` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Set from the owning poster. 1 means a copy of this file is allowed to sit in the public folder where the web server can serve it directly.'' AFTER `fileSize`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- #############################################################################
-- ⚙️  C. Settings — every one of them off
-- #############################################################################
-- All seeded portal-wide (siteID empty). Two different jobs are being done here
-- and it is worth being precise about which is which:
--
--   public.enabled is a REAL portal-wide default. An organisation that has not
--   set its own row inherits this one, and this one says no. That is the safe
--   direction to inherit.
--
--   public.{name}.enabled rows are NOT inherited. The code that decides whether
--   a surface is live reads the organisation's own row and nothing else, with
--   no fall back to the portal-wide row. If it did fall back, one portal-wide
--   row set to yes would publish that surface for every organisation that had
--   not written its own — which is the shape of a very quiet accident. The
--   rows below exist so the settings page knows the key exists, so the
--   automatic checks can see it, and so the default is written down.

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    -- The master switch. One row to leave off, and one row to turn off in a
    -- hurry, rather than forty-seven.
    (NULL, 'public.enabled',              'false', 'false', 0),
    -- Per surface. Not inherited — see the note above.
    (NULL, 'public.noticeboard.enabled',  'false', 'false', 0),
    (NULL, 'public.events.enabled',       'false', 'false', 0),
    -- Which surface answers the bare address. Empty means nothing does, and the
    -- bare address returns "not found" until somebody chooses.
    (NULL, 'public.homeSurface',          '',      '',      0),
    -- Search engines. These are NEW keys, deliberately not the existing
    -- site.allowIndexing / site.allowAiIndexing. An installation upgrading from
    -- today may already have those set to yes for some other reason, and
    -- inheriting that would publish a site to search engines the moment the
    -- public door appeared. Absence of a refusal is what permits indexing, so
    -- these must start as no.
    (NULL, 'public.allowIndexing',        'false', 'false', 0),
    (NULL, 'public.allowAiIndexing',      'false', 'false', 0),
    -- Which other websites may put our pages inside a frame on their own.
    -- Comma-separated bare hostnames, at most ten, same format and same limit
    -- as the existing Cloudflare Stream allow-list so there is one format to
    -- learn. Empty means nobody but ourselves.
    (NULL, 'public.embedOrigins',         '',      '',      0),
    -- Where the public pages look for their stylesheet and images. Empty means
    -- "at the root of this website", which is right for a public site on its
    -- own address. It exists for the case where the public pages are included
    -- into a page on somebody else''s domain, where the root is theirs.
    (NULL, 'public.assetBase',            '',      '',      0),
    -- How long a browser or a content network may keep a public page. Sixty
    -- seconds is a compromise between a foyer display that should notice a new
    -- poster quickly and a shared hosting account that should not be asked to
    -- start a PHP process for every visitor.
    (NULL, 'public.cacheSeconds',         '60',    '60',    0),
    -- How often a foyer display may ask for new information, in seconds. The
    -- number is sent to the display in its data, so a misbehaving screen can be
    -- slowed down without changing any code.
    (NULL, 'public.pollSeconds',          '120',   '120',   0),
    -- How long each poster stays on screen in the foyer display, in seconds.
    (NULL, 'public.noticeboard.slidesSeconds', '8', '8',    0),
    -- Which machines are allowed to tell us a visitor''s real address. Empty
    -- means none, so the address the web server saw is the only one believed.
    -- Until now any visitor could send a CF-Connecting-IP header and be treated
    -- as a brand new person on every request, which made every "too many
    -- attempts" limit in the portal decorative.
    (NULL, 'portal.trustedProxies',       '',      '',      0),
    -- The housekeeping sweep that deletes published files with no published
    -- poster behind them. Empty refuses every request, so the address is
    -- useless until an administrator sets a value.
    (NULL, 'public.cron_token',           '',      '',      0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);


-- #############################################################################
-- 🗺️  D. Addresses (9, all on the management portal)
-- #############################################################################
-- There is deliberately NOTHING here for the public website. Its addresses are
-- worked out from the app registry in code, at the moment of the request. Two
-- lists of addresses for one door is how you end up with handlers nobody can
-- reach and rows pointing at files that no longer exist — the exact problem the
-- api/ addresses already have. There are also no api/ rows and no
-- api.something.enabled settings, because the public website''s two data feeds
-- are answered by the public front controller itself and never go near
-- ApiRouter.

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/public',            'admin/public/index.php',      1),
    ('admin/public/save',       'admin/public/save.php',       1),
    ('admin/public/grants',     'admin/public/grants.php',     1),
    ('admin/public/grant-save', 'admin/public/grant-save.php', 1),
    ('admin/public/hosts',      'admin/public/hosts.php',      1),
    ('admin/public/host-save',  'admin/public/host-save.php',  1),
    ('admin/public/token',      'admin/public/token.php',      1),
    ('noticeboard/publish',     'noticeboard/publish.php',     1),
    ('help/public-site',        'help/public-site.php',        0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- 📋 E. Self-record
-- #############################################################################
INSERT INTO `tblMigrations` (`filename`) VALUES ('193_public_door.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
```

### The fold into `web/_sql/full_schema.sql`

Six edits. `tools/audit-checks/check_schema_seed_parity.py` fails the build if any is missed.

1. **`tblSites`** — add `publicToken CHAR(32) DEFAULT NULL` after `hostPattern`, and `UNIQUE KEY uq_site_public_token (publicToken)`, inline in the `CREATE`.
2. **`tblEvents`** (around line 793) — add `publicSurfaceOptIn TINYINT(1) NOT NULL DEFAULT 0` after `isPublic`, and `KEY idx_event_public_surface (siteID, publicSurfaceOptIn, isPublic, status, isDeleted)`.
3. **`tblNoticeboardPosters`** (around line 6087) — add `isPublic`, `publishFrom`, `publishUntil` after `isDeleted`, plus `KEY idx_poster_public`.
4. **`tblNoticeboardUploads`** (around line 6135) — add `isPublic` after `fileSize`.
5. **Two new `CREATE TABLE` blocks** — `tblPublicSurfaceGrants` and `tblPublicHosts`, placed **after** `tblSites` and `tblUsers` and after `tblNoticeboardPosters`, since they carry foreign keys to all of them. Copy them verbatim from section A above.
6. **The seed blocks** — the thirteen settings rows and the nine route rows, appended to the existing seed sections, plus `('193_public_door.sql')` in the `tblMigrations` seed block.

All tables stay `ENGINE=InnoDB`. It is what makes an all-or-nothing restore possible at all, and moving away from it would break the backup restore and every multi-step change in the portal.

---

# 5. THE PUBLIC SURFACE DECLARATIONS, IN FULL

### `web/_core/apps/noticeboard.php`

```php
<?php
// Path: _core/apps/noticeboard.php
declare(strict_types=1);
return [
    'slug'        => 'noticeboard',
    'name'        => 'Noticeboard',
    'description' => 'Visual poster wall — Canva embeds, image/video/text posters, weekday recurrence, QR share.',
    'icon'        => 'fa-solid fa-thumbtack',
    'color'       => '#caa063',
    'category'    => 'communications',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business'],
    'route'       => 'noticeboard',
    'settingKey'  => 'noticeboard.enabled',
    'isCore'      => false,
    'version'     => '1.1.0',

    // 🌍 The public side of this app.
    //
    // READ THIS BEFORE CHANGING ANYTHING BELOW. Being listed here does NOT
    // publish anything. It says what COULD be published. Three separate
    // switches must all be on for this organisation before a single visitor
    // sees a single poster, and all three start off:
    //   public.enabled                 (the whole public website)
    //   public.noticeboard.enabled     (this surface)
    //   noticeboard.enabled            (the app itself)
    // and then each poster has its own isPublic column, which also starts off.
    //
    // Every promise below is kept by the DOOR, not by the handler. A handler
    // cannot opt itself into accepting form submissions, cannot make itself
    // framable, and cannot make itself appear in search results. That is the
    // point: the list of things a public page is allowed to do is reviewed in
    // one short file, in a pull request, rather than spread across handlers.
    'public' => [
        // 🏷️ The first part of the web address. Note this is separate from the
        //    slug: the address is /noticeboard, and the handler files live in
        //    _apps/noticeboard/public/, but the two need not match — see the
        //    calendar, whose slug is "calendar" and whose surface is "events".
        'surface'  => 'noticeboard',

        // 📋 A CLOSED list, looked up by exact key. The part of the address
        //    after the surface name is never used to build a file path, so
        //    "/noticeboard/../../admin/users" simply is not a key here and gets
        //    the same "not found" as any other nonsense. This is the same
        //    discipline the report builder uses for column names, for the same
        //    reason.
        'handlers' => [
            ''       => 'board.php',   // /noticeboard          the poster wall
            'poster' => 'poster.php',  // /noticeboard/poster   one poster, full size
            'slides' => 'slides.php',  // /noticeboard/slides   the foyer display
            'data'   => 'data.php',    // /noticeboard/data     JSON, for the embed
        ],

        // 📮 Whether this surface may receive a form submission.
        //    FALSE, and it must stay false in this version. The public website
        //    answers GET and HEAD and nothing else, refusing anything else
        //    before it even looks at this list. Accepting a submission would
        //    need a session for the form token, a captcha, and a per-visitor
        //    limit — and the per-visitor limit is worthless until the trusted
        //    proxy work lands, so ordering matters. The key exists so that when
        //    somebody does want a public form, they change one line here and
        //    the door starts enforcing the extra steps.
        'acceptsPost' => false,

        // 🖼️ May another website put these pages inside a frame on their page?
        //    Yes in principle — but only the hostnames an administrator has
        //    actually listed in public.embedOrigins, which starts empty. With
        //    an empty list the answer is nobody.
        'embeddable' => true,

        // 🔎 May search engines list these pages?
        //    Yes in principle, and again only when an administrator has said so
        //    with public.allowIndexing, which starts as no. A page is only left
        //    unmarked — and being unmarked is what permits listing — when the
        //    surface says yes here AND the setting says yes AND this is the
        //    live channel rather than a test one.
        'indexable' => true,

        // 🎞️ Which kinds of file this surface may publish as real files into
        //    the public folder, where the web server hands them out directly
        //    without PHP ever starting. This is how a poster image or a looping
        //    video can appear on somebody else's website at all: a browser
        //    fetching an image makes what the standard calls a "no-cors"
        //    request, and the portal's own pages refuse those. A plain file on
        //    disk carries no such refusal.
        'mediaKinds' => ['poster-image', 'poster-video'],

        // 🔒 There is no row filter or column list here, unlike the calendar,
        //    because this surface does not use the shared query builder. Its
        //    query is written out in board.php, which asks for the seven
        //    columns it displays by name. It deliberately does NOT reuse
        //    _apps/noticeboard/api/list.php: that one returns every poster the
        //    organisation has, and giving it a second, public mode would make
        //    one function carry two different security postures. The public one
        //    would be the easy one to get wrong.
    ],
];
```

### `web/_core/apps/calendar.php`

```php
<?php
// Path: _core/apps/calendar.php
declare(strict_types=1);
return [
    'slug'        => 'calendar',
    'name'        => 'Calendar',
    'description' => 'Events, series, RSVP, recurring schedule.',
    'icon'        => 'fa-solid fa-calendar-days',
    'color'       => '#10b981',
    'category'    => 'community',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business'],
    'route'       => 'calendar',
    'settingKey'  => 'calendar.enabled',
    'isCore'      => false,
    'version'     => '1.1.0',

    // 🌍 The public side of this app — the harder case, and the reason a
    //    declaration carries a description of the DATA it may show and not just
    //    a list of file names.
    'public' => [
        // The address is /events even though the app is called calendar. People
        // looking for a church's services do not search for "calendar".
        'surface'  => 'events',

        // Handler files live in _apps/calendar/public/ — keyed by the app's
        // slug, not by the surface name above.
        'handlers' => [
            ''      => 'list.php',   // /events        the list
            'event' => 'event.php',  // /events/event  one event
            'ics'   => 'feed.php',   // /events/ics    a subscription file
            'data'  => 'data.php',   // /events/data   JSON, for the embed
        ],

        'acceptsPost' => false,
        'embeddable'  => true,
        'indexable'   => true,

        // 📋 THE ONLY ROWS THIS SURFACE MAY EVER SHOW.
        //
        //    Written here rather than inside the handler so that the rule is
        //    reviewed in one place and a future handler cannot quietly widen
        //    it. The shared query builder pastes this in and adds nothing.
        //
        //    Note publicSurfaceOptIn, which migration 193 added, and note that
        //    isPublic ALONE WOULD NOT DO. isPublic defaults to yes, and has
        //    done since the table was created, back when there was no public
        //    website for it to mean anything on. Every event every organisation
        //    has ever made is therefore already marked yes — including elders'
        //    meetings, safeguarding reviews and pastoral visits at people's
        //    homes. Requiring the new column means publication has to be a
        //    decision somebody took after the public website existed.
        'rowFilter' => 'isPublic = 1 AND publicSurfaceOptIn = 1 '
                     . 'AND status = "published" AND isDeleted = 0',

        // 📋 THE ONLY COLUMNS IT MAY EMIT.
        //
        //    tblEvents holds things a public page must never show. Since the
        //    location work landed it also holds locationGeoLat, locationGeoLng
        //    and locationW3W — and a what3words value names a three-metre
        //    square, so publishing one for an event held at a member's home
        //    publishes their front door. It also holds submitterName,
        //    submitterEmail, moderationNote and cancelReason.
        //
        //    A "select everything" query would ship all of that. This list is
        //    checked by exact key lookup: a name not on it cannot reach the
        //    query at all.
        //
        //    locationName IS on the list, and that is a judgement worth stating
        //    plainly rather than leaving implied. In practice it sometimes
        //    reads "Mrs Smith's, 14 Elm Road". The protection against that is
        //    the opt-in column above — an event at somebody's home should not
        //    be opted in — and the help page says so in those words.
        'columnAllow' => [
            'eventID', 'eventSlug', 'title', 'summary',
            'startDateTime', 'endDateTime', 'isAllDay',
            'locationName', 'categoryID',
        ],

        // 🎞️ No published files. Events carry no images of their own.
        'mediaKinds' => [],
    ],
];
```

### The default applied to every other app

`AppRegistry::all()`'s defaults block gains:

```php
'public' => [
    'surface'     => '',
    'handlers'    => [],
    'acceptsPost' => false,
    'embeddable'  => false,
    'indexable'   => false,
    'rowFilter'   => '',
    'columnAllow' => [],
    'mediaKinds'  => [],
],
```

An app that says nothing gets an empty surface name, and `publicSurfaces()` drops any entry whose surface name is empty. Forty-five of the forty-seven apps are in that state and nothing can change it but a code change.

---

# 6. THE PERMISSION MODEL AS CODE

Two powers, checked in two different places, because they are different kinds of thing.

### Power 1 — "a public surface may exist at all". Root administrator only.

Checked in exactly three places, and nowhere else may write a `public.` key:

```php
// web/_apps/admin/public/save.php — the ONLY handler permitted to write one.
if (App::isRootAdmin() === false) {
    Router::renderError(403);
    return;
}
```

```php
// web/_core/PublicSurface.php
/**
 * Refuse unless the signed-in person is a global administrator.
 * Throws rather than returning false, so a caller cannot forget to check the
 * answer. Every path that changes what is published goes through this.
 */
public static function assertRootOrRefuse(): void
{
    if (App::isRootAdmin() !== true) {
        Logger::activity('PublicSurfaceRefused', 'Not a global administrator', $_SESSION['user_id'] ?? null);
        throw new \RuntimeException('Only a global administrator may change what is published.');
    }
}
```

And the three doors that could otherwise write the same rows are closed. This is a real hole today, not a theoretical one: `web/_apps/settings/index.php:38` gates the generic settings editor on `App::isAdmin()`, which `App.php:393-403` makes true for any site administrator, and `web/_apps/settings/save.php:80-89` then runs `UPDATE tblSettings ... WHERE settingID = ? AND (siteID = ? OR siteID IS NULL)` — so a site administrator of any tenant can edit a portal-wide row.

```php
// web/_apps/settings/save.php — inserted immediately after the isAdmin gate.
//
// 🔒 Two refusals, both needed, and neither is about this page being wrong
//    before now. They are about what the public website makes possible.
//
//    First: keys beginning "public." decide whether an organisation's pages
//    are visible to the whole internet. That decision belongs to the global
//    administrator alone. This page has always been open to any site
//    administrator, which was fine when no setting could publish anything.
//
//    Second: a row with no organisation against it is the portal-wide default
//    that EVERY organisation inherits. Letting one organisation's administrator
//    change it lets them change every other organisation's behaviour. That was
//    already true and already wrong; it becomes dangerous now.
$existingKey  = '';
$existingSite = null;
if ($settingId > 0) {
    $probe = $mysqli->prepare('SELECT settingKey, siteID FROM tblSettings WHERE settingID = ?');
    if ($probe !== false) {
        $probe->bind_param('i', $settingId);
        $probe->execute();
        $row = $probe->get_result()->fetch_assoc();
        $probe->close();
        if ($row !== null) {
            $existingKey  = (string) $row['settingKey'];
            $existingSite = $row['siteID'];
        }
    }
}
$touchesPublic = str_starts_with($settingKey, 'public.') === true
              || str_starts_with($existingKey, 'public.') === true;
if (($touchesPublic === true || $existingSite === null) && App::isRootAdmin() === false) {
    $_SESSION['flash_msg']  = 'Only a global administrator can change this setting. '
                            . 'Settings that control the public website, and settings that apply '
                            . 'to every organisation, are managed at Admin → Public website.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /settings');
    exit();
}
```

The identical block goes into the delete branch of `web/_apps/settings/index.php` and into `web/_apps/admin/settings/group.php` plus its save handler. `tools/audit-checks/check_public_settings_writes.py` then asserts that every PHP file containing `tblSettings` alongside `INSERT`, `UPDATE`, `REPLACE` or `DELETE` either contains the string `public.` guard or is on this allow-list: `admin/public/save.php`, `settings/save.php`, `settings/index.php`, `admin/settings/group.php`, `admin/settings/group-save.php`, `_install/`.

### The gates the door itself applies

```php
// web/_core/PublicSurface.php

/**
 * Is this value a yes?
 *
 * One function, because this codebase stores yes as both '1' and 'true'
 * depending on which migration wrote the row, and AppRegistry::isEnabled()
 * accepts both. If the gates below compared against 'true' alone they would
 * disagree with the app toggle, and the disagreement would be invisible.
 * Today that mismatch happens to fail safely (a surface would stay off), but
 * the next person to reuse one of these helpers would not be so lucky.
 */
public static function isYes(?string $value): bool
{
    return $value === '1' || $value === 'true';
}

/**
 * Is the whole public website switched on for this organisation?
 *
 * This one DOES inherit the portal-wide row, because the portal-wide row says
 * no, and inheriting "no" is the safe direction.
 */
public static function masterEnabled(int $siteId): bool
{
    return self::isYes(App::settingForSite('public.enabled', $siteId));
}

/**
 * Is this one surface switched on for this organisation?
 *
 * This one does NOT inherit. It reads the organisation's own row and nothing
 * else. App::settingForSite() falls back to the portal-wide row when the
 * organisation has none (App.php:177-181), which would mean a single
 * portal-wide row set to yes published this surface for every organisation
 * that had not written its own — and admin/settings/group.php:129-133 writes
 * portal-wide rows unconditionally, so that is one click away. Hence the
 * deliberately narrower query here.
 */
public static function surfaceEnabled(string $slug, int $siteId): bool
{
    $stmt = App::db()->prepare(
        'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1'
    );
    if ($stmt === false) {
        return false;                       // cannot tell → not published
    }
    $key = 'public.' . $slug . '.enabled';
    $stmt->bind_param('si', $key, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null && self::isYes((string) $row['settingValue']);
}

/**
 * The full test the public door applies before it will load anything.
 * Any doubt anywhere returns false, and the door turns false into the same
 * "not found" it gives for an address that never existed.
 */
public static function isPublished(string $slug, int $siteId): bool
{
    try {
        if (self::masterEnabled($siteId) === false)      { return false; }
        if (self::surfaceEnabled($slug, $siteId) === false) { return false; }
        // The app's own on/off switch, read for THIS organisation rather than
        // from the snapshot bootstrap built for whichever organisation the
        // hostname suggested. AppRegistry::isEnabled() reads that snapshot,
        // which on a public request can easily be the wrong organisation.
        $meta = AppRegistry::all()[$slug] ?? null;
        if ($meta === null) { return false; }
        if (($meta['isCore'] ?? false) === true) { return true; }
        return self::isYes(App::settingForSite((string) $meta['settingKey'], $siteId));
    } catch (\Throwable $e) {
        return false;
    }
}
```

### Power 2 — "may tune what is shown". Delegable, per app, to named people.

```php
// web/_core/PublicSurface.php

/**
 * May the signed-in person change what this published surface shows?
 *
 * True only when ALL of these hold:
 *   • somebody is signed in;
 *   • the surface is actually published (there is nothing to tune otherwise);
 *   • and EITHER they are a global administrator,
 *     OR they are an administrator of this organisation AND somebody has
 *     granted them this app.
 *
 * Note both halves of that last branch. A grant on its own is not enough — the
 * person must still be an administrator here. So taking somebody's
 * administrator status away also takes away their ability to change the public
 * website, immediately, without anybody having to remember to clear grants.
 *
 * WHAT THIS DOES NOT ALLOW, stated plainly because it is the whole point of
 * splitting the two powers. Somebody with this permission may choose which
 * posters appear and set their dates. They may NOT switch a surface on, switch
 * an app on, add a website to the list allowed to frame us, change the search
 * engine settings, or change which rows and columns a surface may ever show —
 * those last two live in code and cannot be reached from any web page at all.
 */
public static function canTune(string $slug): bool
{
    try {
        $user = App::user();
        if ($user === null) {
            return false;
        }
        $meta = AppRegistry::all()[$slug] ?? null;
        if ($meta === null || ($meta['public']['surface'] ?? '') === '') {
            return false;                   // no public side to tune
        }
        $siteId = Site::id();
        if (self::isPublished($slug, $siteId) === false) {
            return false;
        }
        if (App::isRootAdmin() === true) {
            return true;
        }
        if (App::isSiteAdmin() === false) {
            return false;
        }
        return self::hasGrant($slug, $siteId, (int) $user['userID']);
    } catch (\Throwable $e) {
        Logger::errorPlatformForSite(null, 'PublicSurface', 'Warning', 'canTune',
            'Refused because the check itself failed', $e->getMessage());
        return false;                       // any doubt at all means no
    }
}

/**
 * Is there a usable grant row?
 *
 * A row is usable only when its app slug is a real app in the registry. A row
 * naming an app that has been removed, or a slug that was never real, is
 * ignored — a grant that cannot be understood grants nothing. It is logged once
 * so somebody can tidy it up, rather than silently ignored, because a grant
 * that stops working without explanation is its own kind of problem.
 */
private static function hasGrant(string $slug, int $siteId, int $userId): bool
{
    if (isset(AppRegistry::all()[$slug]) === false) {
        return false;
    }
    $stmt = App::db()->prepare(
        'SELECT appSlug FROM tblPublicSurfaceGrants '
        . 'WHERE siteID = ? AND appSlug = ? AND userID = ? LIMIT 1'
    );
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('isi', $siteId, $slug, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null;
}

/**
 * Create a delegation. Global administrator only.
 * Refuses an app slug that is not in the registry, and refuses a person who is
 * not an active member of this organisation — so a grant can never reach
 * across organisations, whatever the form posted.
 */
public static function grant(string $slug, int $siteId, int $userId, int $byUserId): bool;

/** Remove one. Global administrator only. */
public static function revoke(string $slug, int $siteId, int $userId): bool;

/** Everyone currently granted this app at this organisation, for the admin page. */
public static function grantsFor(string $slug, int $siteId): array;
```

**Missing grant** → `canTune()` returns false, the control is not rendered, and the handler behind it returns 403. **Malformed grant** (app slug not in the registry, or a `userID` whose row has gone) → treated as absent, logged once as a platform warning against the right organisation, never an error page.

### Where each check is actually made

| What | Where | Which power |
| --- | --- | --- |
| Change any `public.*` setting | `admin/public/save.php` line 1 | 1 — `App::isRootAdmin()` |
| Add or remove a public hostname | `admin/public/host-save.php` | 1 |
| Generate or rotate a site token | `admin/public/token.php` | 1 |
| Create or revoke a delegation | `admin/public/grant-save.php` | 1 |
| Mark a poster public | `noticeboard/publish.php` | 2 — `PublicSurface::canTune('noticeboard')` |
| Set a poster's publish dates | `noticeboard/publish.php` | 2 |
| Opt an event into the public list | `calendar/manage/save.php` | 2 — `PublicSurface::canTune('calendar')` |
| Show the "public" controls at all | `noticeboard/index.php`, `calendar/manage/index.php` | 2 (for display only; the handler re-checks) |

---

# 7. THE DEPLOYMENT CHANGE

### The seven secrets

> UPDATED 11 September 2026 on the owner's instruction. This replaces the nine
> secrets originally proposed here. Two changes: one `SFTP_ROOT_DIR` replaces the
> three identical `SFTP_SHARED_PATH_*` secrets, and the six front-door secrets now
> hold **a folder name only**, not a full path. They are renamed `..._DIR_...`
> because their meaning changed — a setting that keeps its name while its meaning
> changes is the exact hazard this whole piece of work exists to avoid.
>
> Also note: the owner decided the SERVER folders mirror the repository exactly.
> The management portal's server folder IS renamed to `admin_html`, and the
> `public_site` naming proposed earlier is not used.

Retire `SFTP_LIVE_PATH`, `SFTP_BETA_PATH` and `SFTP_DEV_PATH` completely.

| Secret | Value (substituting the real user name and domain) |
| --- | --- |
| `SFTP_ROOT_DIR` | `/home/USER/portal.example.org/` |
| `SFTP_ADMIN_DIR_LIVE` | `admin_html/` |
| `SFTP_ADMIN_DIR_BETA` | `admin_html_beta/` |
| `SFTP_ADMIN_DIR_ALPHA` | `admin_html_dev/` |
| `SFTP_PUBLIC_DIR_LIVE` | `public_html/` |
| `SFTP_PUBLIC_DIR_BETA` | `public_html_beta/` |
| `SFTP_PUBLIC_DIR_ALPHA` | `public_html_dev/` |

`SFTP_HOST`, `SFTP_USER`, `SFTP_PORT`, `SFTP_KEY` / `SFTP_PASSWORD` and the
`SFTP_ENABLED` variable are unchanged.

**What this buys.** The rule that both web roots must sit inside the shared
directory stops being something the workflow checks and becomes something that
cannot be otherwise — every folder is joined onto `SFTP_ROOT_DIR`. The shared
upload target is `SFTP_ROOT_DIR` itself rather than `dirname()` of a web root, so
a typo can no longer quietly relocate the shared code somewhere plausible.

**What the workflow must validate, and fail loudly on:**

1. `SFTP_ROOT_DIR` is set and starts with `/`. Empty or relative → stop.
2. Each of the six folder settings is set. Empty → stop, naming which one.
3. No folder setting looks like a full path — refuse a value starting with `/`
   or containing an inner `/`. This catches somebody pasting the old full-path
   style into the new setting, which would otherwise build
   `/home/u/d//home/u/d/admin_html` and mirror into nowhere useful.
4. Any of the three retired secrets still being set → stop, with a message saying
   to delete them. A leftover is a sign the changeover was done half way.
5. Trim a trailing slash from `SFTP_ROOT_DIR` and any slashes from the folder
   name, then join with exactly one `/`, so every spelling works.

Worked example for the `alpha` channel:

```
ROOT=/home/USER/portal.example.org        (trailing slash trimmed)
ADMIN=$ROOT/admin_html_dev                (from SFTP_ADMIN_DIR_ALPHA)
PUBLIC=$ROOT/public_html_dev              (from SFTP_PUBLIC_DIR_ALPHA)
SHARED=$ROOT                              (no dirname() guesswork)
```

### The diff to `.github/workflows/deploy.yml`

**Header comment block** — replace the `CHANNEL MODEL` and `SECRETS REQUIRED` sections with the nine secrets above and this note:

```
# TWO WEB ROOTS PER CHANNEL
# -------------------------
#   main   -> web/admin_html/  -> <shared>/public_html/       (the management portal)
#          -> web/public_html/ -> <shared>/public_site/       (the public website)
#   beta   -> ..._beta, alpha -> ..._dev
#
# BOTH web roots for a channel MUST sit directly inside the shared directory.
# This is not a preference. web/public_html/index.php finds the shared code with
# dirname(__DIR__) . '/_core/bootstrap.php', so if the public web root lives
# somewhere else the shared code is not there and every public page fails. The
# workflow checks it and refuses rather than deploying something that cannot
# work. This account already runs three web roots on three hostnames from one
# base directory, so this is the arrangement that is already proven here.
```

**`WEB_ROOT_EXCLUDES`** — add three lines. Without the first, the shared upload (which runs on **every** branch and targets the shared base) would push `web/admin_html/` to `<shared>/admin_html/`; and on a customer whose admin root happens to be called that, an alpha push would overwrite the live management portal.

```diff
       WEB_ROOT_EXCLUDES: >-
         --exclude ^_auth_keys/
         --exclude ^_uploads/
         --exclude ^_backups/
+        --exclude ^admin_html/
+        --exclude ^admin_html_dev/
+        --exclude ^admin_html_beta/
         --exclude ^public_html/
         --exclude ^public_html_dev/
         --exclude ^public_html_beta/
```

**The target-resolution step** — replace `Determine target channel` wholesale:

```yaml
      - name: Determine target channel
        id: target
        env:
          ROOT_DIR:    ${{ secrets.SFTP_ROOT_DIR }}
          ADMIN_LIVE:  ${{ secrets.SFTP_ADMIN_DIR_LIVE }}
          ADMIN_BETA:  ${{ secrets.SFTP_ADMIN_DIR_BETA }}
          ADMIN_ALPHA: ${{ secrets.SFTP_ADMIN_DIR_ALPHA }}
          PUBLIC_LIVE: ${{ secrets.SFTP_PUBLIC_DIR_LIVE }}
          PUBLIC_BETA: ${{ secrets.SFTP_PUBLIC_DIR_BETA }}
          PUBLIC_ALPHA:${{ secrets.SFTP_PUBLIC_DIR_ALPHA }}
          OLD_LIVE:    ${{ secrets.SFTP_LIVE_PATH }}
          OLD_BETA:    ${{ secrets.SFTP_BETA_PATH }}
          OLD_DEV:     ${{ secrets.SFTP_DEV_PATH }}
        run: |
          # ── Guard 1: the old settings must be GONE, not merely ignored. ──
          # A half-finished change is exactly the state in which somebody has
          # updated one thing and not another. Stopping the whole run beats
          # running half of it.
          if [[ -n "$OLD_LIVE" || -n "$OLD_BETA" || -n "$OLD_DEV" ]]; then
            echo "::error::SFTP_LIVE_PATH / SFTP_BETA_PATH / SFTP_DEV_PATH are retired and must be DELETED."
            echo "::error::Replace them with SFTP_ROOT_DIR plus SFTP_{ADMIN,PUBLIC}_DIR_{LIVE,BETA,ALPHA}."
            exit 1
          fi

          # ── Guard 2: this must be a tree that has had the rename. ──
          # A branch cut from before the rename still has the whole management
          # portal sitting at web/public_html/. Pointed at the public folder by
          # this workflow, that would publish every management page to the open
          # internet. The single most dangerous mistake available here, so it is
          # checked structurally rather than by reading a path string.
          if [[ ! -f web/admin_html/index.php ]]; then
            echo "::error::web/admin_html/index.php is missing — this branch predates the two-door rename."
            echo "::error::Refusing to deploy: web/public_html/ in this tree is the MANAGEMENT PORTAL, not the public site."
            exit 1
          fi

          # ── Guard 3: the base folder. ──
          if [[ -z "$ROOT_DIR" ]]; then
            echo "::error::SFTP_ROOT_DIR is not set. It holds the folder everything else sits inside,"
            echo "::error::for example /home/USER/portal.example.org/"
            exit 1
          fi
          if [[ "$ROOT_DIR" != /* ]]; then
            echo "::error::SFTP_ROOT_DIR must be a full path starting with / — got '$ROOT_DIR'"
            exit 1
          fi
          ROOT_DIR="${ROOT_DIR%/}"

          MANUAL_TARGET="${{ github.event.inputs.target }}"
          if [[ -n "$MANUAL_TARGET" && "$MANUAL_TARGET" != "auto" ]]; then
            CHANNEL="$MANUAL_TARGET"
          else
            CHANNEL="${{ github.ref_name }}"
          fi

          case "$CHANNEL" in
            main)  ADMIN_DIR="$ADMIN_LIVE";  PUBLIC_DIR="$PUBLIC_LIVE";  CHAN=live  ;;
            beta)  ADMIN_DIR="$ADMIN_BETA";  PUBLIC_DIR="$PUBLIC_BETA";  CHAN=beta  ;;
            alpha) ADMIN_DIR="$ADMIN_ALPHA"; PUBLIC_DIR="$PUBLIC_ALPHA"; CHAN=alpha ;;
            *) echo "::error::Unknown channel: $CHANNEL"; exit 1 ;;
          esac

          # ── Guard 4: each folder setting is present, and is a FOLDER NAME. ──
          # Refusing a full path here catches somebody pasting the old style
          # into the new setting. Joined to the base that would build
          # /home/u/d//home/u/d/admin_html and mirror into nowhere useful.
          UPPER="$(echo "$CHAN" | tr a-z A-Z)"
          for pair in "SFTP_ADMIN_DIR_${UPPER}:${ADMIN_DIR}" "SFTP_PUBLIC_DIR_${UPPER}:${PUBLIC_DIR}"; do
            NAME="${pair%%:*}"; VALUE="${pair#*:}"
            if [[ -z "$VALUE" ]]; then
              echo "::error::${NAME} is empty. Set it to a folder name such as 'admin_html/'."
              exit 1
            fi
            TRIMMED="${VALUE%/}"; TRIMMED="${TRIMMED#/}"
            if [[ "$TRIMMED" == */* ]]; then
              echo "::error::${NAME} should be a FOLDER NAME only, not a path — got '${VALUE}'."
              echo "::error::The base folder lives in SFTP_ROOT_DIR. Set this to e.g. 'admin_html/'."
              exit 1
            fi
          done

          ADMIN_DIR="${ADMIN_DIR%/}";  ADMIN_DIR="${ADMIN_DIR#/}"
          PUBLIC_DIR="${PUBLIC_DIR%/}"; PUBLIC_DIR="${PUBLIC_DIR#/}"

          # Both web roots are placed INSIDE the base folder by construction, so
          # the rule that they share a parent cannot be broken and needs no check.
          REMOTE_ADMIN="${ROOT_DIR}/${ADMIN_DIR}"
          REMOTE_PUBLIC="${ROOT_DIR}/${PUBLIC_DIR}"
          REMOTE_SHARED="${ROOT_DIR}"

          REMOTE_ADMIN="${REMOTE_ADMIN%/}"; REMOTE_PUBLIC="${REMOTE_PUBLIC%/}"; REMOTE_SHARED="${REMOTE_SHARED%/}"

          # ── Guard 3, trimmed to checks that cannot cry wolf. ──
          # An earlier draft also refused a public path containing the text
          # "admin_html". That would have refused a perfectly correct set-up,
          # because the management portal's directory on the server keeps its
          # existing name, which is usually "public_html". A check that refuses
          # correct work gets deleted, and then it catches nothing.
          if [[ "$REMOTE_ADMIN" == "$REMOTE_PUBLIC" ]]; then
            echo "::error::The management portal and the public website cannot share one directory."; exit 1
          fi
          if [[ "$REMOTE_ADMIN" == "$REMOTE_PUBLIC"/* || "$REMOTE_PUBLIC" == "$REMOTE_ADMIN"/* ]]; then
            echo "::error::One web root is inside the other. They must be separate directories."; exit 1
          fi
          if [[ "$REMOTE_ADMIN" == "$REMOTE_SHARED" || "$REMOTE_PUBLIC" == "$REMOTE_SHARED" ]]; then
            echo "::error::A web root cannot be the shared directory itself — that would publish the whole codebase."; exit 1
          fi

          # ── No "must sit inside the shared directory" check is needed. ──
          # Both web roots are built as "$ROOT_DIR/$FOLDER", so they sit inside
          # it by construction. This used to be three separate path settings that
          # could disagree with each other, and the workflow had to check they
          # did not. One base setting removes the possibility instead of
          # policing it. The only thing still worth refusing is a folder name
          # that resolves back to the base itself, which the check above does.

          { echo "channel=$CHAN"
            echo "remote_admin=$REMOTE_ADMIN"
            echo "remote_public=$REMOTE_PUBLIC"
            echo "remote_shared=$REMOTE_SHARED"; } >> "$GITHUB_OUTPUT"
```

**New step — write the channel marker, before any mirror runs:**

```yaml
      - name: Stamp the channel into both web roots
        if: steps.changes.outputs.has_changes == 'true'
        run: |
          # Each web root carries a one-line file naming its channel. The front
          # controllers read it to decide whether to show PHP errors on screen.
          # This replaces guessing from the directory name, which was never
          # reliable and stops working entirely once the directories are called
          # public_site and public_site_dev. Blocked from the web by the
          # .htaccess rule that refuses any address starting with a dot.
          echo "${{ steps.target.outputs.channel }}" > web/admin_html/.channel
          echo "${{ steps.target.outputs.channel }}" > web/public_html/.channel
```

**New step — guard 1, the two-sided door check:**

```yaml
      - name: Check both remote directories are the doors we think they are
        if: steps.changes.outputs.has_changes == 'true' && github.event.inputs.first_run != 'true'
        env: { ... host/user/port/key as the other steps ... }
        run: |
          # Download whatever .door file is already at each remote path and
          # compare it with the one we are about to upload. This is the check
          # that stops the management portal being pushed into the directory
          # that serves the public website — the one mistake in this whole
          # change that would be a genuine disaster, because the name
          # "public_html" kept its path in the repository and changed its
          # meaning.
          #
          # A remote directory with NO .door file is the layout from before the
          # rename. That is also refused, and points at the one-time first run.
          check_door () {   # $1 = remote dir, $2 = expected word
            lftp -c "set sftp:connect-program \"ssh -o StrictHostKeyChecking=no -i $HOME/.ssh/deploy_key -p ${SFTP_PORT}\"; \
                     open sftp://${SFTP_USER}@${SFTP_HOST}:${SFTP_PORT}; \
                     get ${1}/.door -o /tmp/remote.door" 2>/dev/null || true
            if [[ ! -s /tmp/remote.door ]]; then
              echo "::error::$1 has no .door marker — it predates the two-door change."
              echo "::error::Run this workflow once by hand with first_run = true, after checking the path is right."
              exit 1
            fi
            FOUND="$(tr -d '[:space:]' < /tmp/remote.door)"
            if [[ "$FOUND" != "$2" ]]; then
              echo "::error::REFUSING TO DEPLOY. $1 says it is the '$FOUND' door; we were about to write the '$2' door there."
              exit 1
            fi
            rm -f /tmp/remote.door
          }
          check_door "${{ steps.target.outputs.remote_admin }}"  admin
          check_door "${{ steps.target.outputs.remote_public }}" public
```

**The first-run input,** added to `workflow_dispatch`, with the typed-back confirmation the review asked for:

```yaml
      first_run:
        description: "FIRST RUN ONLY — skip the .door check because the remote directories are empty. Dangerous; read confirm_public_path."
        required: false
        type: boolean
        default: false
      confirm_public_path:
        description: "Required when first_run is ticked: type the SFTP_PUBLIC_DIR_* value again, exactly."
        required: false
        type: string
```

with a step that refuses when `first_run` is true and `confirm_public_path` does not match `remote_public` exactly. This is what stops a mistyped public path being mirrored with `--delete` over somebody's main website.

**Mirror step 1 — split into two mirrors.** The admin one is today's step with the source path changed. The public one is new, and carries the media exclusion:

```yaml
          # NOTE THE --exclude ^media/ AND WHY IT IS HERE AND NOT IN
          # WEB_ROOT_EXCLUDES. Published poster images and videos are real files
          # written into web/public_html/media/ by the portal at the moment
          # somebody publishes a poster. They are not in the repository. This
          # mirror runs with --delete, so without this exclusion the next deploy
          # would delete every published poster — and because the file's
          # existence is what makes a poster public, they would all quietly
          # unpublish with no change in the database and nothing anywhere to
          # explain it. WEB_ROOT_EXCLUDES is not used by this step at all; its
          # patterns are written relative to web/, so ^public_html/media/ would
          # never match here.
          mirror --reverse --delete ${LFTP_DRYRUN_FLAG} --verbose --only-newer --no-empty-dirs \
            $LFTP_EXCLUDES --exclude ^media/ web/public_html/ ${REMOTE_DIR}
```

**The summary step** — `${{ steps.target.outputs.public_dir }}` has never been set, so the deployment summary has always printed an empty value. Replace with `remote_admin` and `remote_public`.

**Post-deploy probe — new, and the only check that tests the real arrangement:**

```yaml
      - name: Check the public web directory is not set too high
        if: steps.changes.outputs.has_changes == 'true'
        run: |
          # The one mistake no guard above can catch, because it is made in the
          # hosting panel rather than in a secret: pointing the public site's
          # web directory at the shared base instead of at public_site/. If that
          # happened, the web server would hand out the database schema, the
          # backups, every uploaded file including pastoral notes and children's
          # photographs, and the whole source code. Every check in this file
          # would pass while it was happening.
          for probe in "_sql/full_schema.sql" "_uploads/" "_core/bootstrap.php" "_auth_keys/"; do
            CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "${PUBLIC_BASE_URL}/${probe}" || echo 000)
            if [[ "$CODE" == "200" ]]; then
              echo "::error::${PUBLIC_BASE_URL}/${probe} returned 200. The public site's web directory is set too high."
              exit 1
            fi
          done
```

`PUBLIC_BASE_URL` is a new **variable** (not a secret — it is a public address), one per channel: `PUBLIC_BASE_URL_LIVE`, `_BETA`, `_DEV`. When it is empty the step prints a warning and skips, so nothing is blocked before the public site exists.

### What the owner does, in order

**In GitHub, at Settings → Secrets and variables → Actions:**

1. Add the nine new secrets from the table above, filling in your own user name and domain. Copy the paths carefully — a wrong path is the one mistake the checks cannot always catch.
2. **Delete** the three old ones: `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH`. Deleting them matters. If any is left with a value in it, the deployment stops on purpose and tells you why. That is deliberate: a half-finished change is exactly when a mistake gets made.
3. On the **Variables** tab, add `PUBLIC_BASE_URL_DEV` with the full web address of your alpha public site, for example `https://public-dev.example.org`. Add the live and beta ones later, when those sites exist.

**In the DreamHost panel, at Websites → Manage Websites:**

4. Add a new website for the address you want the public site on — for example `public.example.org`, or `noticeboard.example.org`. Whichever you pick, people will see it in the address bar, so choose something you are happy saying out loud.
5. On that website's settings, find **Web directory** and set it to `portal.example.org/public_site` — using your own portal domain, and the folder name `public_site` exactly. This is the step people get wrong. It must be a folder **inside** your portal's folder, not the portal's folder itself. If you set it to the portal's folder, your database backups and every uploaded file become downloadable by anyone on the internet. The deployment checks this afterwards and stops if it is wrong, but it is easier to get right first.
6. Make sure **Add a free Let's Encrypt certificate** is ticked for the new website. Without it the embed will not work on anyone else's site, and browsers will warn visitors.
7. Repeat steps 4 to 6 for `public-dev.example.org` pointing at `portal.example.org/public_site_dev`, if you want to test on alpha first. You do. That is what step 8 below assumes.

**Back in GitHub, at Actions → Deploy via SFTP → Run workflow:**

8. Choose **alpha**, tick **first_run**, and type the alpha public path into the confirmation box exactly as you typed it into the secret. Run it. This is the only run that skips the door check, because the directories are empty and have no marker yet.
9. Open your alpha public address in a browser. You should see a plain "not found" page. That is correct and is the whole point: everything is switched off until somebody deliberately turns it on.
10. Open your alpha portal address and sign in as normal. Nothing about the management portal should look any different.
11. When both of those are right, repeat step 8 for **beta**, then for **main** — this time **without** ticking first_run, so the door check runs and protects you.
12. Only now, sign in as the global administrator and go to **Admin → Public website**. Nothing is published until you switch it on there.

**If something goes wrong:** switch the public surfaces off at Admin → Public website **first**, then roll the code back. Rolling the code back on its own does not unpublish anything, because publication is a setting in the database rather than something in the code.

---

# 8. THE SELF-TEST

`tools/public-door-selftest.php` — no database, no network, no bootstrap. Run with `php tools/public-door-selftest.php`. Exit 0 when everything passes, 1 on any failure, with the failing assertion printed.

It loads the real classes (`AppRegistry`, `PublicRouter`, `PublicSurface`, `Headers`), exactly as `tools/report-builder-selftest.php` does — it does not reimplement them, because a test of a reimplementation proves nothing about the code that runs.

### What it asserts

**Part A — the registry is well formed.**
- `AppRegistry::publicSurfaces()` returns only entries whose `public.surface` is a non-empty string.
- No two apps claim the same surface name.
- No surface name is one of the reserved words: `s`, `media`, `assets`, `widget`, `robots.txt`, `sitemap.xml`, `sitemap.xsd`, `favicon.ico`, `index.php`, `error.php`, `.well-known`.
- No surface name matches a real file or directory in `web/public_html/`. This is the web-root shadowing trap — which has caused two outages already — turned into an assertion instead of a thing to remember.
- Every file named in every `handlers` map exists on disk at `web/_apps/{slug}/public/{file}` and is readable.
- Every `handlers` key is either the empty string or matches `/^[a-z0-9-]{1,32}$/`.

**Part B — forbidden apps are structurally absent, not merely switched off.**
No declaration may exist for any of: `admin`, `auth`, `account`, `api`, `settings`, `site`, `care`, `kids`, `safeguarding`, `directory`, `giving`, `expenses`, `payments`, `prayer-requests`, `offboarding`, `invites`, `visitors`, `sms`, `ai-assist`, `transcription`, `zoom`, `reports`, `approvals`, `cron`, `offline`. This fails the build if somebody adds a `public` key to one of them, rather than relying on a code reviewer noticing.

**Part C — nothing shipped accepts a submission.**
No surface sets `acceptsPost => true`. Version one is read-only, and this is the assertion that keeps it that way until somebody deliberately changes the test as well as the declaration.

**Part D — the resolver refuses everything it should. The red-team pass.**
`PublicRouter::resolveHandler(string $path, array $surfaces)` is a pure static function with no database and no superglobal reads, so it can be attacked directly. Every one of these must return `null`, which the door turns into the same "not found" it gives any unknown address:

```
admin                         admin/users                  admin/settings
dashboard                     settings                     auth/login
account/notifications         api/noticeboard/list          api/v1/users
cron/event-reminders          noticeboard/board.php         noticeboard/../admin
noticeboard/../../admin/users  ../_core/bootstrap.php       ../../_auth_keys/auth_creds.php
noticeboard/%2e%2e/%2e%2e/admin  noticeboard%2f..%2fadmin    noticeboard/./../admin
noticeboard\..\..\admin       noticeboard//slides           //admin
noticeboard/slides/../../admin  noticeboard/\0slides        (a 5000-character path)
s/ABCDEF0123456789ABCDEF0123456789/noticeboard   (capitals in the token)
s/zzzz0123456789012345678901234567/noticeboard   (not hexadecimal)
s/0123/noticeboard                               (too short)
s//noticeboard                                   (no token)
noticeboard/data/../../../etc/passwd
```

And for every path it does **not** refuse, the returned file path, after `realpath()`, must begin with `web/_apps/{slug}/public/`. A returned path outside that prefix is a failure even if the file exists.

**Part E — the boundary proof. This is the assertion the brief asks for.**

It reads `web/_sql/full_schema.sql`, extracts **every** `tblRoutes` seed row with `isProtected = 1`, and asserts that for each one:

- `PublicRouter::resolveHandler($routeKey, $surfaces)` returns `null`; and
- `PublicRouter::resolveHandler($firstSegmentOf($routeKey), $surfaces)` returns `null`; and
- `PublicRouter::resolveHandler('s/' . str_repeat('a', 32) . '/' . $routeKey, $surfaces)` returns `null`.

This is what fails if an admin page ever becomes reachable from the public door. It is data-driven from the schema, so it grows on its own: every protected address anybody adds in future is automatically covered, with nobody having to remember to add a test. Today that is over four hundred addresses checked in about a second.

It also asserts the reverse direction — that every surface's own addresses resolve to something — so that a passing Part E can never be the result of the resolver simply returning `null` for everything.

**Part F — the gates fail closed.**
`PublicSurface` is exercised through an injectable settings resolver (a closure the test passes in, so no database is touched). With the resolver returning `null` for every key, `isPublished()` must be false for every surface. With `public.enabled` yes but the surface's own key absent, false. With both yes but the app's own `{slug}.enabled` absent, false. With all three yes, true. And with the resolver throwing, false.

**Part G — the headers are right, checked as data.**
`Headers::publicPolicy(array $surface, array $config): array` is pure. Assert:
- The returned array never contains an `X-Frame-Options` key, on any input. That header cannot name a particular external website, and sending it alongside `frame-ancestors` means the stricter of the two wins — so sending it at all would silently break the embed.
- With an empty embed list, the policy contains `frame-ancestors 'none'` — present and saying no, never simply absent, because an absent directive permits everything.
- With `['example.org', 'www.example.org']`, the policy contains `frame-ancestors https://example.org https://www.example.org` — full addresses with `https://` on the front, never bare hostnames. A bare hostname in this list matches plain `http://` too, and a customer whose own site is still unencrypted could then frame us on a page anybody on the same network can tamper with.
- `*` anywhere in the embed list produces `frame-ancestors 'none'` and a logged refusal, never a wildcard.
- More than ten entries are cut to ten, matching the existing Cloudflare Stream limit.
- On any channel other than `live`, `X-Robots-Tag` contains `noindex` **whatever the settings say** — this is in code, not configuration, so a test or beta public site can never be indexed.
- With `indexable => false`, `X-Robots-Tag` contains `noindex`.
- With `indexable => true`, `public.allowIndexing` yes and the live channel, there is no `X-Robots-Tag` key at all.
- `Cache-Control` is `no-store` whenever `acceptsPost` is true, and `public, max-age=...` otherwise.
- `Cross-Origin-Resource-Policy` on an HTML response is never `cross-origin`.

**Part H — `AppRegistry::assertSelfConsistent()` passes on the live registry.**

### The two CI scripts that go with it

`tools/audit-checks/check_public_surfaces.py` runs Parts A and B as a pull-request check, so a broken declaration is caught without needing PHP. `tools/audit-checks/check_public_settings_writes.py` asserts the `public.` prefix guard is present in every file that writes `tblSettings`. Both are added to `tools/audit-required-checks.py`, taking the count from fifteen to seventeen.

---

# 9. WHAT THIS PLAN DELIBERATELY DOES NOT DO

**No public writes.** No public form, no public submission, no comments, no sign-up. The door refuses every method but GET and HEAD. To add one later: set `acceptsPost => true` on a surface, and the door must then start a session with its own cookie name and its own session storage directory, issue a form token, call `Captcha::verify()` through a site-aware wrapper (`web/_core/Captcha.php` reads `App::settings()`, which on a public request is the wrong organisation's configuration), apply a per-visitor limit, and send `Cache-Control: no-store`. **The per-visitor limit is worthless until `portal.trustedProxies` is actually populated**, so that has to come first.

**No content network, and no caching beyond the browser.** `Cache-Control` headers are sent and are correct, but nothing sits in front of the portal. On a busy public page every request is a PHP process and a database connection on shared hosting. To improve: put Cloudflare in front of the public hostname, at which point `portal.trustedProxies` becomes load-bearing.

**No certificate expiry warning.** The review asked for the admin dashboard to read the public site's certificate and warn at fourteen days. It needs an outbound socket and a scheduled job slot, and it is a convenience rather than a boundary control. Later, alongside the other integration health checks.

**No record of who is embedding us.** The review suggested listing which websites have fetched the feed in the last thirty days, from the `Origin` header the check already reads. It is a good idea and it needs a table and a pruning job. Later.

**No `Site::name()` fix.** That is a separate piece of work running in parallel. This plan depends on it in exactly one place: the sitemap moves to the public door and emits public-door addresses, so `/e/{slug}` on the admin door stops being offered to search engines whether or not the fix has landed. `web/_apps/calendar/public-landing.php:68-69` and `web/_apps/worship/display.php:37-38` still fatal on a valid record until it does.

**The noticeboard's React bundle is not used on the public board.** `web/public_html/assets/noticeboard/noticeboard.noeval.js` is generated, marked do-not-hand-edit, and reads `window.NoticeboardHost` with `credentials: 'same-origin'`. The public board is a plain server-rendered page with a small enhancement script instead. That is better for a search engine, better for a foyer display, and drops a 144 KB dependency; the cost is that the two boards do not look identical, and the public one must be styled separately.

**No public surface for any other app.** Forty-five of the forty-seven declare nothing, and cannot be published by any settings change. Adding one is: a `public` key in that app's own file in `web/_core/apps/`, handlers in `web/_apps/{slug}/public/`, and two settings seeds in one migration. No shared file changes at all — that was the design test and it passes.

**Route (c), a path on the customer's main domain, has no mechanism that works for everybody, and this plan does not pretend otherwise.** DreamHost offers `mod_proxy` only on Managed VPS and Dedicated plans, and Apache forbids `ProxyPass` in a `.htaccess` file under any configuration. What is offered instead, in order: a redirect (works everywhere, one line, but the address bar changes); a page on their own site that embeds ours (recommended — the address stays theirs and needs nothing but the ability to make a page); and, only where the main domain is on the same account under the same user, runs PHP, and is editable, a four-line include file. The last is an optional faster path offered per customer after three questions, never the design.

**Only the alpha channel is proven by this plan's own verification steps.** Beta and live follow the same steps, but nobody has run them at the time of writing.

---

# 10. EFFORT, AND WHO SHOULD DO WHICH PART

Hours are for a focused implementer who already knows this codebase. Add roughly 40% for the audit checks, the Codex review round and the documentation.

| Step | Work | Hours | Tier | Why |
| --- | --- | --- | --- | --- |
| 0 | Branch, issue | 0.5 | Haiku | Mechanical. |
| 1 | Pre-existing fixes (rate limiter, settings guards, Gatekeeper, registry try/catch, logger) | 4-6 | **Opus** | Every one of these is a security control being added to code somebody else wrote. The settings guard in particular has to reason about an `UPDATE` whose `WHERE` clause already spans two tenancy cases. Getting it slightly wrong locks administrators out of settings they should be able to change. |
| 2 | Door identity, header extraction, document-root refusal, deny-all `.htaccess` files | 6-8 | **Opus** | The header extraction must be byte-identical, and proving that means reading `bootstrap.php:400-520` line by line against the new `Headers::emitAdmin()`. A missed header is a silent security regression on every page of the live portal. |
| 3 | The rename, ten audit scripts repointed, `deploy.yml` rewrite | 8-10 | **Opus** for `deploy.yml` and the planted-fault proofs; **Sonnet** for the mechanical repointing | The `git mv` and the ten path constants are mechanical. The workflow guards are not — they are the thing standing between a mistake and publishing the management portal, and the review already found one proposed guard that would have refused correct work. Proving each audit script still fires needs judgement about what a good planted fault looks like. |
| 4 | The empty public door: front controller, five new classes, `.htaccess`, self-test | 14-18 | **Opus** | This is the boundary. `PublicRouter::resolveHandler()` and the five gates are the code the whole design rests on. Write the self-test first and make it fail, then make it pass. |
| 5 | Migration 193, the fold, `/admin/public`, the permission model | 12-16 | **Opus** for `PublicSurface` and the migration; **Sonnet** for the four admin pages once the class exists | The migration must replay as nothing on a database that already has it, and the guard idiom is unforgiving — MariaDB's short form is error 1064 on MySQL 8. The admin pages are ordinary form-and-list work once `PublicSurface::canTune()` exists and is trusted. |
| 6 | Noticeboard surface, `PublicMedia`, the sweep, the GDPR and offboarding lockstep | 10-14 | **Opus** for `PublicMedia` and the erasure lockstep; **Sonnet** for the three page handlers | `PublicMedia` is where the database stops being the only record of what is published, and the reconciliation sweep is what keeps that from becoming a lie. The erasure work touches a chained audit log. The page handlers themselves are straightforward once the query is decided. |
| 7 | Calendar surface, `PublicQuery`, the opt-in column and checkbox | 8-10 | **Opus** for `PublicQuery`; **Sonnet** for the handlers and the form field | `PublicQuery` forces `siteID = ?` in ahead of everything, outside any brackets — the same discipline `ReportBuilder::compile()` uses, and the same reason. Get it wrong and a filter can slip past tenancy. |
| 8 | Embeds, CORS allowlist, `frame-ancestors`, robots and sitemap move, migration 194 | 10-12 | **Opus** for the header policy and the origin allow-list; **Sonnet** for `board.js` and the snippet generator | The header policy is where a bare hostname versus a full `https://` address decides whether an unencrypted site can frame us. The embed script and the copy-button page are ordinary front-end work. |
| 9 | Documents, Codex review, fixing what it finds, commit | 6-8 | **Sonnet** for the writing; **Opus** for judging Codex's findings | Codex will be right about some things and wrong about others, and telling which is which against the real code is the skill. |

**Total: roughly 80 to 105 hours of focused work**, of which about 60 genuinely need care and about 25 could go to a cheaper model once the class it depends on exists and is trusted.

**Two pieces I would not hand to a cheaper model under any circumstances**, because a plausible-looking mistake in either is invisible until it is public: the `deploy.yml` guards in Step 3, and `PublicRouter` plus the self-test in Step 4. Everything else has a failing test or a failing audit check waiting for it.

**Files that must be read in full before starting**, listed so nobody has to go looking: `web/_core/bootstrap.php` (all of it), `web/_core/Router.php:85-145` and `:248-268` and `:320-345`, `web/_core/Site.php:60-200` and `:700-860`, `web/_core/App.php:160-200` and `:330-420`, `web/_core/AppRegistry.php:57-115`, `web/_core/RateLimiter.php:439-454`, `web/_apps/settings/save.php`, `web/public_html/.htaccess`, `web/public_html/index.php`, `.github/workflows/deploy.yml`, `tools/audit-checks/check_webroot_shadowing.py`, and `tools/report-builder-selftest.php` for the self-test house style.