# Cross-origin survey — WebMS-Intra, 11 Sep 2026

Everything below was read from the working tree on branch `claude/alpha-wip`. Where I could not prove something from the repo I say so explicitly and say what would settle it.

Two conventions used throughout:
- **PROVEN** = read directly out of a file in this repo (or demonstrated by running PHP locally).
- **INFERRED** = follows from a published standard (Apache docs, the Fetch/CSP specs) applied to what the code does. Not tested against a real browser or a real DreamHost account.

---

## 0. Three things found while surveying that the design must not build on top of

These are defects, not design constraints. I am putting them first because two of them mean an "existing precedent" is not actually working.

### 0.1 `Site::name()` does not exist, and `Site::branding()` cannot be called with no arguments. Three public pages call both.

**PROVEN.** `web/_core/Site.php` has 28 methods. There is no `name()`. `branding()` is declared at line 184 as `public static function branding(string $key): ?string` — one required argument. There is no `__callStatic`.

I verified the parse and the PHP behaviour locally (PHP 8.5.10):

```
0-arg branding -> ArgumentCountError: Too few arguments ... 0 passed and exactly 1 expected
undefined name() -> Error: Call to undefined method S::name()
```

Both are `Throwable`, so `bootstrap.php`'s `set_exception_handler` catches them → `Logger::exception()` → HTTP 500 → `Router::renderError(500)`.

Callers:

| File | Lines | Address | Public? |
| --- | --- | --- | --- |
| `web/_apps/calendar/public-landing.php` | 68, 69 | `/e/{slug}` | yes, no sign-in |
| `web/_apps/worship/display.php` | 37, 38 | `/worship/display?t={64-hex}` | yes, `isProtected=0` |
| `web/_apps/calendar/widget.php` | 63 | none (address deleted, migration 189) | n/a |

The failure shape matters: in `public-landing.php` the `Site::name()` call sits at line 68, **after** the event lookup and its `404` exit at line 63. So a bad slug 400s/404s correctly, and a **valid, public, published event 500s**. Same shape in `worship/display.php` — bad token 400/404s at lines 23/35, valid token reaches line 37 and dies.

So `/e/{slug}` and `/worship/display` — the two existing "public page, no portal chrome" precedents — **do not currently work at all** on the happy path. Neither has been exercised. `/e/{slug}` is also the only dynamic entry the sitemap emits (`web/public_html/sitemap.php:230`), so every event URL offered to search engines is a 500.

### 0.2 `X-Frame-Options: ALLOWALL` is not a real header value

**PROVEN** that `web/_apps/calendar/widget.php:65` sends it, and that `header()` defaults to `$replace = true`, so it overwrites bootstrap's `SAMEORIGIN` (`web/_core/bootstrap.php:491`).

**INFERRED** (RFC 7034 / the HTML spec's replacement, `frame-ancestors`): `ALLOWALL` was never a defined value. The defined set is `DENY` and `SAMEORIGIN`; `ALLOW-FROM` is dead and unsupported in Chrome and Safari. Browsers ignore an unparseable value, so framing is permitted — but by accident, not by instruction.

The correct modern mechanism is the CSP directive `frame-ancestors <origin-list>`, which is the only one that can name *which* external sites may frame a page. Note the portal's CSP (see §B) **has no `frame-ancestors` directive at all**, so today CSP contributes nothing to framing policy on any page.

### 0.3 `Cross-Origin-Resource-Policy: same-origin` is sent on every PHP-rendered response

**PROVEN.** `web/_core/bootstrap.php:474` sends it unconditionally (inside the `headers_sent() === false` block at line 443), defaulting from `portal.headers.corp`, seeded `'same-origin'` at `web/_sql/full_schema.sql:3019`. Nothing anywhere else in `web/_apps/` or `web/_core/` overrides it — the only other mentions are the admin settings form (`web/_apps/admin/settings/group.php:69`).

**INFERRED** (Fetch spec, "cross-origin resource policy check"): that check returns *allowed* immediately when the request's mode is not `no-cors`. So:

| How an external page pulls a portal URL | Request mode | CORP `same-origin` blocks it? |
| --- | --- | --- |
| `fetch()` / XHR cross-origin | `cors` | **No** (CORS rules apply instead) |
| `<img src>`, `<video src>`, `<audio src>` | `no-cors` | **Yes — blocked** |
| `<script src>` without `crossorigin` | `no-cors` | **Yes — blocked** |
| `<link rel=stylesheet>` | `no-cors` | **Yes — blocked** |
| `<iframe src>` | navigate | **No** (XFO / `frame-ancestors` decide) |

This is the single biggest hidden constraint in the whole survey. It means **`/noticeboard/media?f=…` cannot be displayed on an external website today**, even though it is a public address with no sign-in check, because it is served by PHP and therefore carries CORP `same-origin`. The same applies to any future portal-served poster image or looping video.

It does *not* affect `/widget/countdown.js`, because that is a real file on disk (§A.1) which Apache serves directly without ever starting PHP.

---

## A. Every existing way portal-managed content reaches a different origin

### A.1 Countdown widget script — `/widget/countdown.js`

| | |
| --- | --- |
| Files | `web/public_html/widget/countdown.js` (real file on disk, 157 lines) |
| Address | `/widget/countdown.js` |
| Login | none — Apache serves it, PHP never runs |
| Site identity | **none of its own.** The `data-portal` attribute only names the portal's base URL. |
| Origin policy | none. No CORP (no PHP), no CORS needed for a classic `<script src>`. |
| Works? | **Yes (PROVEN reachable).** `.htaccess` line "Skip rewriting for existing static files" (`RewriteCond %{REQUEST_FILENAME} !-f`) passes it straight to Apache. |

It is the one genuinely working cross-origin delivery mechanism in the codebase, and it works *because* it is a static file.

### A.2 Countdown data feed — `/widget/countdown.json`

| | |
| --- | --- |
| Files | `web/_apps/widget/countdown-json.php` |
| Address | seeded `('widget/countdown.json', 'widget/countdown-json.php', 0)` at `full_schema.sql:4853` |
| Login | none (`isProtected=0`, no auth call in the file) |
| Site identity | `Site::id()` at line 33 — i.e. **the host the request arrived on**, which for an external embed is always the portal host, so always the default site. |
| Origin policy | `Access-Control-Allow-Origin: *` + `Access-Control-Allow-Methods: GET` (lines 28-29), `Cache-Control: public, max-age=60` |
| Works? | **Yes.** No real file or folder named `widget/countdown.json` exists, so `!-f`/`!-d` both pass and the request reaches the front controller. The `fetch()` in `countdown.js:141` is `cors` mode, so CORP does not apply. |

Data safety: the query (lines 41-50) requires `isPublic = 1 AND isDeleted = 0 AND status = "published"`. That is correct.

**Note the shape**: this is the *only* end-to-end working pattern in the repo — static JS from the web root + a CORS-open JSON feed through the router. It works because it never asks the browser to make a `no-cors` request for anything PHP serves.

### A.3 Calendar iframe widget — `web/_apps/calendar/widget.php`

| | |
| --- | --- |
| Files | `web/_apps/calendar/widget.php` |
| Address | **none.** Migration 189 (`web/_sql/189_unreachable_admin_and_widget.sql:89-91`) deletes the `widget` route; `full_schema.sql:8585-8598` folds the same DELETE in. |
| Login | none — the file has no auth check of any kind (documented in its own header, lines 41-42) |
| Site identity | `Site::id()` (line 62) |
| Origin policy | `X-Frame-Options: ALLOWALL` (line 65, invalid — see §0.2), `header_remove('Content-Security-Policy')` (line 66, a no-op — see §B) |
| Works? | **No.** Unreachable by design, and it would fatal at line 63 anyway (`Site::branding()` with no argument — §0.1). |

The route was deleted rather than repaired because a real folder `web/public_html/widget/` shadowed it, and because publishing the page would have exposed internal events. Both queries were hardened to `isPublic = 1` at the same time (lines 74, 85).

### A.4 Public short-token routes

All four are hard-coded in `Router::handleSpecialRoutes()` (`web/_core/Router.php:189-410`), not in `tblRoutes`. All four run *after* `global $mysqli, $SETTINGS;` at line 205. `extractPath()` lowercases the whole path (line 320), so tokens must be lowercase — all four use `[a-f0-9]{32}`, which is safe.

| Address | Router line | Handler | Login | How the site is chosen | Origin policy | Works? |
| --- | --- | --- | --- | --- | --- | --- |
| `/e/{slug}` | 281-284 | `calendar/public-landing.php` | none | `Site::id()` (host) — line 38 | bootstrap defaults only; **no CSP** (does not include `header.php`) | **No — 500s on a valid event** (§0.1) |
| `/a/{token}` | 302-305 | `assets/tag.php` | none (`Auth::ensureSession()` only, line 87) | `Site::id()` for display; the token is the real key | bootstrap defaults only | Not disproven; no `Site::name()` call, so no §0.1 fault |
| `/os/{token}` | 322-325 | `service-plans/public.php` | none | **`$plan['siteID']` from the row itself, never `Site::id()`** (lines 34, 92, 140) | bootstrap defaults only | Not disproven |
| `/f/{token}` | 340-343 | `forms/public.php` | none (session started at line 95 for CSRF) | **`$form['siteID']` from the row itself** (lines 35, 73) | bootstrap defaults only | Not disproven |

`/os/{token}` and `/f/{token}` are the **correct precedent for site identification on a sessionless public request**: the token resolves to a row, and the row's own `siteID` is then used for everything. `/e/{slug}` and `/a/{token}` still use the ambient `Site::id()`.

None of these four set any CORS or framing header, so all four inherit bootstrap's `X-Frame-Options: SAMEORIGIN` and `Cross-Origin-Resource-Policy: same-origin` — they cannot be iframed from another origin and their sub-resources cannot be pulled cross-origin.

### A.5 Noticeboard media — `/noticeboard/media?f={32-hex}.{ext}`

| | |
| --- | --- |
| Files | `web/_apps/noticeboard/media.php` (92 lines) |
| Address | `('noticeboard/media', 'noticeboard/media.php', 0)` at `full_schema.sql:5158` |
| Login | **none** — no `Auth::` call anywhere in the file; documented as deliberate (header lines 11-14) |
| Site identity | **none, and deliberately so.** The lookup at lines 55-65 is `WHERE storedName = ?` with no `siteID` predicate; the header says it "serves BYTES ONLY; it never reveals anything about the owning poster". |
| Origin policy | `Content-Type` from the sniffed MIME, `Cache-Control: public, max-age=31536000, immutable`, `X-Content-Type-Options: nosniff` (lines 87-90). **No CORS header. Inherits `Cross-Origin-Resource-Policy: same-origin` from bootstrap.** |
| Works? | **Same-origin: yes. Cross-origin `<img>`/`<video>`: no** (INFERRED from CORP, §0.3). |

Hardening is genuinely good: strict `^[a-f0-9]{32}\.(png|jpg|jpeg|gif|webp|mp4|webm)$` regex (line 46) *before* any filesystem call, then the token must also exist in `tblNoticeboardUploads`, then `basename()` is re-applied to the **database** value, not the request value (line 75). Files live at `PORTAL_ROOT/_uploads/noticeboard/` — outside the web root.

One extra gate the header does not mention: `Router::dispatch` runs `AppRegistry::appForRoute('noticeboard/media')` at line 123, which matches the `noticeboard` app by prefix. If `noticeboard.enabled` is off, the request gets `renderAppDisabled()` (`Router.php:455-477`) — a **full HTML page with portal chrome, HTTP 403**. An `<img>` tag would silently get HTML.

### A.6 Live chat / livestream endpoints

| Address | File | Login | Site identity | Origin policy | Works? |
| --- | --- | --- | --- | --- | --- |
| `/api/livechat/list` | `web/_apps/livechat/api/list.php:26` | none | `Site::id()` (line 35) + `eventID` query param | `ACAO: *`, `Cache-Control: no-store` | Yes (flag `api.livechat.list.enabled` seeded `true`, `full_schema.sql:5077`) |
| `/api/livechat/send` | `web/_apps/livechat/api/send.php:43-48` | none | `Site::id()` | `ACAO: *` + explicit `OPTIONS → 204` preflight handling | Yes |
| `/api/livechat/prompts` | `web/_apps/livechat/api/prompts.php:24` | none | `Site::id()` | `ACAO: *` | Yes |
| `/api/livestream/ping` | `web/_apps/livestream/api/ping.php:29-34` | none | `Site::id()` | `ACAO: *` + `OPTIONS → 204` | Yes |
| `/api/worship/state` | `web/_apps/worship/api/state.php:41` | none | `Site::id()` | `ACAO: *` | Yes (flag at `full_schema.sql:5334`) |
| — | `web/_apps/api/livestream-ping.php:8-13` | — | — | `ACAO: *` | **No — dead code.** `ApiRouter` maps `api/{app}/{action}` → `_apps/{app}/api/{action}.php`; nothing resolves to `_apps/api/livestream-ping.php`. |

The mismatch worth recording: **the viewer page these APIs serve is login-gated.** `('live', 'live/index.php', 1)` at `full_schema.sql:3726` — `isProtected=1`. So the chat APIs are open to every origin on the internet while the page they exist for requires a sign-in. `send.php`'s own header comment says "embed page can live elsewhere", which is the intent, but nothing has been built to do that.

### A.7 Manifest, OpenAPI spec, robots, sitemap

| Address | File | Login | Site | Origin policy | Works? |
| --- | --- | --- | --- | --- | --- |
| `/manifest.json` | `web/public_html/manifest.php` (route seed `full_schema.sql:2887`) | none | `Site::productName()`, `Site::branding('color')` — host-derived | `Content-Type: application/manifest+json`, `max-age=300`. No CORS. | Yes, same-origin only |
| `/openapi.json` | `web/public_html/openapi.php` (seed `:3097`) | none | `Site::productName()`, `Site::productPublisher()` | `max-age=300`. No CORS. | Yes, same-origin only |
| `/robots.txt` | `web/public_html/robots.php` (seed `:8614`) | none | `App::settings('site.allowIndexing')` / `site.allowAiIndexing`, both default `'false'` | `text/plain`, `max-age=3600` | Yes |
| `/sitemap.xml` | `web/public_html/sitemap.php` (seed `:8613`) | none | `Site::id()` (line 204) | `application/xml`, `max-age=3600` | Reachable, but every `/e/{slug}` entry it emits (line 230) currently 500s (§0.1) |

Both `manifest.php` and `openapi.php` are reached through `Router::dispatch`'s web-root fallback (`Router.php:96-101`) because they are real files in `web/public_html/` — but the *address* has `.json` on the end, so `.htaccess`'s `.php$` block does not fire and nothing shadows them.

`robots.php`'s `ALWAYS_DISALLOWED` list (around line 106) blocks `/admin/`, `/account/`, `/settings/`, `/auth/`, `/api/`, `/cron/` and more even when indexing is switched on. Any new public address will need a deliberate decision here.

### A.8 Cross-origin allowlist precedent that already exists

`web/_core/CloudflareStream.php:67-71`:

```php
/** @var string Character class for one bare-hostname allowedOrigins entry. */
private const ORIGIN_RE = '/^[a-z0-9.-]+$/i';
/** @var int Maximum allowedOrigins entries accepted per call. */
private const MAX_ORIGINS = 10;
```

Comma-separated bare hostnames, validated one at a time, capped at ten. This is the closest thing in the codebase to a reusable "which external sites may embed us" list — but it is passed *to Cloudflare*, not used by any portal response header.

### A.9 The only sessionless read path to poster data that exists today

`GET /api/noticeboard/list` calls `ApiAuth::requireRead('noticeboard:read')` (`web/_apps/noticeboard/api/list.php:20`). `ApiAuth::requireRead` (`web/_core/ApiAuth.php:185`) accepts **either** a session **or** a bearer API key holding that scope — `'noticeboard:read'` is a real scope (`web/_core/ApiKey.php:61`).

So a sessionless read is already possible. It is not usable for a public embed for two reasons, both PROVEN: the endpoint sends **no** `Access-Control-Allow-Origin` header (nothing in `list.php` sets one, and `ApiResponse::setJsonHeaders()` at `web/_core/ApiResponse.php:250-263` sets only `Content-Type`, `nosniff`, `no-store`, and `X-Frame-Options: DENY`), so a browser on another origin cannot read the response; and putting a bearer key in a public page's JavaScript publishes the key.

Also note: `ApiRouter::dispatch` (`web/_core/ApiRouter.php:86-143`) has **no `AppRegistry` gate**. Turning the Noticeboard app off at `/admin/apps` stops `/noticeboard` and `/noticeboard/media` but does **not** stop `/api/noticeboard/list`. Only the `api.noticeboard.list.enabled` setting does that.

---

## B. The exact header order for a page rendered through Router

This is provable end to end by reading the include chain. Here it is.

**Step 1 — Apache.** `web/public_html/.htaccess`. Only one response header: `Header always unset Server`. No CSP, no CORP, no framing header.

**Step 2 — front controller.** `web/public_html/index.php:28` requires `_core/bootstrap.php`. Nothing is printed before this and there is **no `ob_start()` anywhere in the request path** — PROVEN: the only `ob_start()` calls in `web/_core/` are in `Barcode.php:1101`, `ExpensePdf.php:106`, `Mailer.php:165,215`, `Qr.php:151`, none of which run during bootstrap. So `headers_sent()` is false throughout step 3.

**Step 3 — bootstrap emits headers.** `web/_core/bootstrap.php`, in this order:

| Line | Header |
| --- | --- |
| 412-413 | `header_remove('X-Powered-By')` |
| 430 | `X-Powered-By: <brand>/<version>` (unless `branding.hidePoweredBy`) |
| 443 | `if (headers_sent() === false) {` — everything below is inside this |
| 452 | `Strict-Transport-Security` (HTTPS requests only) |
| 463 | `Permissions-Policy` |
| 470 | `Cross-Origin-Opener-Policy: same-origin` |
| 474 | `Cross-Origin-Resource-Policy: same-origin` |
| 481 | `Referrer-Policy: strict-origin-when-cross-origin` |
| 485 | `X-Content-Type-Options: nosniff` |
| 491 | `X-Frame-Options:` ← from `portal.headers.x_frame_options`, seeded `SAMEORIGIN` |
| 513 | `X-Robots-Tag: noindex, nofollow, noai, noimageai` (both indexing settings default `'false'`) |

**bootstrap.php never sends a `Content-Security-Policy` header.** PROVEN by exhaustive grep: the only four places in the whole tree that mention it are `web/public_html/api-docs/index.php:82`, `web/_core/templates/header.php:153`, `web/_apps/admin/email-templates/preview.php:78`, and `web/_apps/calendar/widget.php:66` (the `header_remove`).

Then `App::init()` at line 533 and `Site::init()` at line 543.

**Step 4 — Router.** `Router::dispatch()` → `handleSpecialRoutes()` → `findRoute()` → `Auth::requireLogin()` if `isProtected='1'` → `AppRegistry` gate → `require $targetFile` (line 138).

**Step 5 — the page.** If (and only if) the page does `require PORTAL_CORE . '/templates/header.php'`, that file re-emits at lines 97-166:

| Line | Header | Effect on step 3 |
| --- | --- | --- |
| 97 | `header_remove('X-Powered-By')` | undoes bootstrap line 430 |
| 98 | `X-Content-Type-Options: nosniff` | same value |
| 99 | `X-Frame-Options: SAMEORIGIN` | **hard-coded — silently overrides the `portal.headers.x_frame_options` setting on every chromed page** |
| 100 | `Referrer-Policy` | same value |
| 101 | `Permissions-Policy: camera=(), microphone=(), geolocation=()` | **narrower than bootstrap's list, replaces it** |
| 102 | `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` | **replaces bootstrap's, and adds `preload`, which bootstrap's own comment at line 445 says is deliberately avoided** |
| 153-166 | `Content-Security-Policy` | the only CSP any ordinary page ever gets |

The CSP it builds:

```
default-src 'self';
script-src 'self' 'nonce-…' 'unsafe-inline' https://cdn.jsdelivr.net https://challenges.cloudflare.com;
style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;
font-src 'self' https://cdnjs.cloudflare.com;
img-src 'self' data:{$cspImgExtra};
connect-src 'self'{$cspConnectExtra};
[media-src 'self'{$cspMediaExtra}; — only when the page opts in]
frame-src https://challenges.cloudflare.com{$cspFrameExtra};
base-uri 'self';
form-action 'self'
```

**There is no `frame-ancestors` directive.** So who may frame a portal page is decided *entirely* by `X-Frame-Options`, and there is currently no mechanism anywhere in the codebase for naming a specific external site that may frame something.

### The header_remove question, answered

**Does `header_remove('Content-Security-Policy')` succeed?** It depends entirely on whether `header.php` has run yet, and the answer is provable:

- **Called BEFORE `require header.php`** → removes nothing (no CSP has been set), and then `header.php:153` sets the CSP anyway. **The page ends up WITH a CSP.** The call is worse than useless — it looks like it opted out and did not.
- **Called AFTER `require header.php`** → the CSP exists, so it is genuinely removed. But `header.php` has already written the opening `<html>`/`<head>` markup (from line 168 onward), so `headers_sent()` is now **true** and `header_remove()` emits a warning and does nothing. **The page ends up WITH a CSP.**
- **Called in a page that never includes `header.php`** → removes nothing, because nothing set one. The page has no CSP, but that was already true. This is the `calendar/widget.php:66` case. **PROVEN: that line is dead.**

So: **on a chromed page you cannot remove the CSP from inside the page at all**, and on a chrome-free page there is nothing to remove. The only way to give a page a different CSP is to call `header()` with a full replacement policy *after* `header.php` but *before* any output — which is impossible via `header.php`, because it outputs — or to not use `header.php` and set your own, which is what `web/_apps/admin/email-templates/preview.php:78-79` does.

`X-Frame-Options` behaves differently and is easier: PHP's `header()` defaults to `$replace = true`, so a page can overwrite bootstrap's value at any point before output. `calendar/widget.php:65` genuinely does replace `SAMEORIGIN` with `ALLOWALL` — that part works, it is just the wrong value (§0.2).

**Not proven:** I have not run this against a live Apache/PHP-FPM stack. The reasoning above is from the include order and PHP's documented `header()`/`header_remove()`/`headers_sent()` semantics, which I am confident in. What would settle it beyond doubt: `curl -sI https://portal…/noticeboard/media?f=…` and `curl -sI https://portal…/dashboard` on the real server, and comparing the header sets.

---

## C. The site-identification path, end to end

### C.1 Where `preDetectedId` comes from

`web/_core/bootstrap.php:327-332`:

```php
$preDetectedSiteID = 1;
try {
    $preDetectedSiteID = \Portal\Core\Site::preDetect($mysqli);
} catch (\mysqli_sql_exception $e) { … falls back to 1 … }
```

This runs **before** settings are loaded, which is why it does its own raw queries.

`Site::preDetect()` (`web/_core/Site.php:710-757`):

1. Reads `multisite.enabled` where `siteID IS NULL`. **Seeded `'false'`** — `full_schema.sql:1657`. Anything other than exactly `'true'` → **return 1**.
2. Reads `multisite.detectionMode`. **Seeded `'session'`** — `full_schema.sql:1661`.
3. Branches to `detectFromSubdomain` / `detectFromPath` / `detectFromSession`. Any other value → return 1.

### C.2 How `hostPattern` is matched

`Site::detectFromSubdomain()` (`Site.php:767-787`):

```php
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host === '') { return 1; }
$stmt = $db->prepare('SELECT siteID FROM tblSites WHERE hostPattern = ? AND isActive = 1 LIMIT 1');
$stmt->bind_param('s', $host);
… if ($row !== null) { return (int) $row['siteID']; }
return 1;
```

**It is an exact string equality test against the raw `Host:` header.** Despite the column name, there is no wildcard, no `LIKE`, no pattern syntax. `tblSites.hostPattern` is `VARCHAR(255) DEFAULT NULL` (`full_schema.sql`, `tblSites` block) with the comment "Hostname for subdomain detection" and **no unique key** — `tblSites` has only `PRIMARY KEY (siteID)` and `UNIQUE KEY uq_site_key (siteKey)`. Two rows could hold the same `hostPattern`; `LIMIT 1` would pick one arbitrarily.

The port is not stripped. `HTTP_HOST` includes `:8080` when a non-default port is used, so `example.org:8080` would not match a stored `example.org`.

`detectFromPath()` (`Site.php:800-830`) matches the **first URL segment** against `tblSites.siteKey` and records it in `self::$pathPrefix`, which `Router::extractPath()` (`Router.php:333-339`) then strips.

`detectFromSession()` (`Site.php:844-853`) reads `$_SESSION['active_site_id']`, or 1.

### C.3 What happens for a sessionless request from an unknown host

Trace it exactly:

1. `multisite.enabled` is `'false'` by default → `preDetect` returns **1** immediately. `Site::init` (`Site.php:104-124`) then sees `enabled === false` and hard-sets `self::$currentSiteID = 1`.
2. Even with multisite **on** in `subdomain` mode, an unknown `Host:` falls through `detectFromSubdomain`'s final `return 1`.
3. With `path` mode, an unrecognised first segment falls through to `return 1`.
4. With `session` mode (the default), no session → `return 1`.

**In every single path, an unrecognised or sessionless request resolves to `siteID = 1`, silently.** There is no "unknown site" state, no error, and no log entry. It does not fail closed; it fails to the first tenant.

That is the core problem the brief names. For a request arriving from `mainsite.org/noticeboard`, the `Host:` header is the *portal's* host (the browser is talking to the portal), the `Origin`/`Referer` are `mainsite.org`, and neither is consulted by any of this code. **Nothing in the current site-detection path can tell you whose noticeboard to show.**

The two places in the repo that solve this correctly do it by **not using site detection at all**: `service-plans/public.php` and `forms/public.php` take the `siteID` off the row the token resolved to. That is the only pattern here that is safe for an unauthenticated cross-origin request.

### C.4 Exactly what `Site::forceContext()` refuses

`Site.php:448-490`. Three refusals, all by exception (`\RuntimeException`), never a silent fallback:

1. **Refuses whenever multisite is disabled and the requested site differs from the current one:**
   ```php
   if (self::isMultisiteEnabled() === false) {
       if ($siteId === self::$currentSiteID) { return; }   // no-op
       throw new \RuntimeException('Site::forceContext: multisite disabled; refusing to switch to site ' . $siteId);
   }
   ```
   Since multisite defaults to off and every install is site 1, `forceContext(1)` is a no-op and `forceContext(anything else)` throws.
2. Refuses if the `prepare()` fails.
3. Refuses if the site does not exist **or is not active** (`WHERE siteID = ? AND isActive = 1`).

It also **never writes `$_SESSION`** — deliberate, for bearer requests that have no session.

**A limitation that matters for the design and is not stated in the method's own docblock:** `forceContext()` changes `self::$currentSiteID` and reloads `self::$currentSite`, but it does **not** reload `$SETTINGS`. The global settings array was built in `bootstrap.php:355-372` using `$preDetectedSiteID`, so any per-site setting override still reflects the *host-detected* site. This is already known and worked around — `ApiRouter::resolveEnabledFlag()` (`ApiRouter.php:155-166`) deliberately re-reads the flag via `App::settingForSite()` rather than trusting the snapshot, and the noticeboard API handlers carry comments saying exactly this (`api/list.php:21-24`, `api/save.php:21-24`). Any new public cross-origin surface that reads a per-site setting must do the same.

---

## D. The Noticeboard as built, versus the spec

### D.1 `tblNoticeboardPosters` (migration 145; `full_schema.sql` from line 6086)

`posterID`, `siteID` (FK → `tblSites`), `title`, `kicker`, `category`, `scheduleType` ENUM(`once`,`weekly`), `eventDate` DATE, `weekday` TINYINT, `eventTime` TIME, `location`, `link` VARCHAR(1024) ("Official event page (opened on second tap)"), `mediaType` ENUM(`text`,`image`,`video`,`canva`), `mediaUrl`, `canvaUrl`, `thumbUrl`, `colorIndex`, `aspect` (default `'4/5'`), `useSerif`, `sortOrder`, `isDeleted`, `createdByID`, `updatedByID`, `createdAt`, `updatedAt`.

**There is no expiry column of any kind.** No `expiresAt`, no `publishFrom`/`publishUntil`, no `isPublic`, no `visibility`, no share token.

### D.2 `tblNoticeboardUploads` (migration 149; from line 6133)

`uploadID`, `siteID` (FK), `posterID` (nullable FK, `ON DELETE SET NULL`, NULL while staged in the editor), `storedName` VARCHAR(40) with `UNIQUE KEY` — the column comment says it is "the ONLY value media.php will ever serve; never derived from client input" — `mimeType` (finfo-sniffed), `fileSize`, `createdByID`, `createdAt`.

### D.3 What `index.php` renders

`web/_apps/noticeboard/index.php`, 110 lines.

- Lines 19-20: `Auth::ensureSession(); Auth::requireLogin();` — **belt and braces with `isProtected=1` at `full_schema.sql:5092`.**
- Line 22: `App::isSiteAdmin()` decides edit rights.
- Lines 32-34: opts the page into a widened CSP — `$cspImgExtra = 'https:'`, `$cspMediaExtra = 'https:'`, `$cspFrameExtra = 'https://www.canva.com'`.
- Line 36: `require header.php` — so this page gets the full portal chrome, nav, CSP and `X-Frame-Options: SAMEORIGIN`.
- Line 40: mounts `<div id="noticeboard-root">`.
- Lines 42-91: a `window.NoticeboardHost` bridge object with `isAdmin`, `csrf`, and four functions — `load()` → `GET /api/noticeboard/list`, `save(posters)` → `POST /api/noticeboard/save` (whole-array replace), `upload(file)` → `POST /api/noticeboard/upload`, `qrUrl(text)` → `/api/noticeboard/qr?data=…`. All use `credentials: 'same-origin'`.
- Lines 104-108: self-hosted fonts CSS, the generated board CSS, React 18.3.1 UMD ×2, then `noticeboard.noeval.js` — all nonce'd and deferred.

The board itself is a **generated** React bundle, `web/public_html/assets/noticeboard/noticeboard.noeval.js` (144 KB), produced from a Claude Design page and marked do-not-hand-edit (DEV_NOTES.md:2508-2514).

### D.4 What `api/list.php` returns and what gates it

`web/_apps/noticeboard/api/list.php`, 66 lines. Gate is `ApiAuth::requireRead('noticeboard:read')` at line 20 — session **or** bearer key. Plus `ApiRouter`'s `api.noticeboard.list.enabled` check, seeded `'true'` (`full_schema.sql:5100`).

Query (lines 30-36): `WHERE siteID = ? AND isDeleted = 0 ORDER BY sortOrder ASC, eventDate ASC, posterID ASC`. **No expiry filter, no visibility filter.** Every non-deleted poster for the site comes back.

Returned keys per poster (lines 43-61): `id` (`'p' . posterID`), `title`, `kicker`, `category`, `schedule`, `date`, `weekday`, `time`, `location`, `link`, `mediaType` (note: `'text'` is rewritten to `'image'` on the way out, line 54), `image`, `canva`, `thumb`, `colorIndex`, `aspect`, `serif`.

`api/save.php` requires `ApiAuth::requireWrite('noticeboard:write')` **plus** an explicit `App::isSiteAdmin()` check in session mode (lines 33-35), with a documented cross-site write guard at lines 67-80 that nulls any `posterID` not already belonging to this site. `api/upload.php` has the same gate (lines 82-90), a `finfo` MIME sniff (line 137), a size cap from `noticeboard.upload.maxBytes` default 15 MB (lines 123-128), and writes to `PORTAL_ROOT/_uploads/noticeboard/` (line 160). `api/qr.php` requires `noticeboard:read` and **pins the encoded URL to `$_SERVER['HTTP_HOST']`** (lines 38-44) so it cannot be used as an open QR redirector.

### D.5 How `media.php` serves files and what it checks

Covered in §A.5. Summary of its checks, in order: strict regex on `?f=` (line 46) → must exist in `tblNoticeboardUploads` (lines 55-70) → `basename()` re-derived from the DB row (line 75) → `is_readable()` (line 79) → serve with the stored MIME + `nosniff` + one-year immutable cache. **No site check, no auth check** — both deliberate and documented.

### D.6 Distance from the owner's spec

| Spec requirement | Built? | Evidence |
| --- | --- | --- |
| Visually rich poster wall | **Yes** | generated React bundle, four media types, per-poster colour/aspect/serif |
| Loopable video | **Yes** | bundle emits `<video autoplay muted loop playsinline>` for both board tile and opened card |
| Click to unpin, flip to front, larger | **Yes** (`sel`/`selected` state in the bundle; opened card renders the Canva iframe with `allowfullscreen`) | |
| Small "x" returns to board | **Yes** (close control exists in the bundle) | |
| Call-to-action links | **Partial** — exactly one link per poster (`link`, "opened on second tap"). No "more info + booking + RSVP" set. | schema |
| Vertical scrolling | **Yes** | board layout |
| Pinned in chronological order of the event | **Partial** — `sortOrder` wins first, `eventDate` second (`api/list.php:35`); manual drag order overrides chronology | |
| Rich slideshow view, auto-plays on foyer displays | **NO — does not exist.** | zero matches for `slideshow` / `carousel` in the 144 KB bundle |
| Secret link / QR, no login | **NO.** The QR the board generates points at `/noticeboard#notice-{id}` (bundle `shareUrl()`), and `/noticeboard` requires a login (`isProtected=1`). There is no share token on `tblNoticeboardPosters` and no public board address. | |
| Auto-expiry on every item | **NO server-side.** The bundle has one client-side helper: `isPast(p) { if (p.schedule !== 'once' || !p.date) return false; … return d.getTime() < Date.now(); }` — a display-time comparison only. Nothing expires, hides, or purges in the database, and `api/list.php` has no date filter. | bundle `// ---- expiry / archive ----` |
| Fully responsive | Presumed yes (React + viewport units); not verified | |
| **View not login-protected; only management requires login** | **NO — this is inverted today.** `/noticeboard` is `isProtected=1` *and* calls `Auth::requireLogin()`. The only public part is the raw media bytes. | `full_schema.sql:5092`, `index.php:19-20` |

Two further gaps that block publication even if a public address were added:
- **No per-poster or per-board "is this public" flag.** Every poster is equally visible to every signed-in member. Publishing the board as-is would publish posters nobody decided to publish. This is exactly the shape of the `calendar/widget.php` fault (`isPublic` never checked) that migration 189 was written to close.
- **The generated bundle depends on the portal's own CSP widening** (`index.php:32-34`) and reads `window.NoticeboardHost` with `credentials: 'same-origin'`. It is not, as written, a component that can be dropped onto another origin.

Also recorded in FEATURES.md:471-473 as known Phase 1 limits: whole-set replace on save (last writer wins), and Google Fonts blocked by CSP — the latter since fixed by self-hosting (DEV_NOTES.md:2551-2559).

---

## E. Hard constraints of DreamHost shared hosting

I will separate what this repository proves from what it does not, because the second list is longer and the brief asked for honesty about it.

### E.1 PROVEN from the repo

- **No command line, no Composer, no build step on the server.** `.claude/ProjectBrief_Chat.claude:607-608` and `:832` state it as a founding constraint; the whole codebase is built around it (vendored JWT library, hand-downloaded dompdf, generated JS committed to the repo).
- **mod_rewrite and mod_headers are available and in use.** `web/public_html/.htaccess` uses `RewriteEngine On`, `RewriteCond`, `RewriteRule`, `ErrorDocument`, and `<IfModule mod_headers.c>Header always unset Server</IfModule>`. This has been in production, so at minimum `AllowOverride` permits `FileInfo`, `Limit` and `Indexes`-class directives.
- **PHP can read files outside the web root, in the account's own tree.** `web/public_html/index.php:16` builds `dirname(__DIR__) . '/_auth_keys/auth_creds.php'` and line 28 requires `dirname(__DIR__) . '/_core/bootstrap.php'`. The entire application lives one level *above* the web root. So whatever `open_basedir` is set to (if any), it includes the portal subdomain's parent directory.
- **This repo deploys ONLY the portal subdomain.** `.github/workflows/deploy.yml:14-18` maps `main`/`beta`/`alpha` to `<base>/public_html/`, `<base>/public_html_beta/`, `<base>/public_html_dev/` — three siblings under one base named by `SFTP_LIVE_PATH` / `SFTP_BETA_PATH` / `SFTP_DEV_PATH`, with the shared base resolved as `dirname()` of the per-branch path (lines 192-198). The example in the comments is `/home/USER/portal.millrdsdacambridge.uk/public_html`.
- **The repo already anticipates other directories on the same account but refuses to touch them.** `deploy.yml:128-137` excludes `^public_html/`, `^public_html_dev/`, `^public_html_beta/`, `^public_html_landing/`, `^public_html_redir/`, `^private_html/` from the shared sync. `web/public_html_landing/index.html` is a "Coming Soon" page and `web/public_html_redir/index.html` is a meta-refresh to `https://portal.millrdsdacambridge.uk/` — evidence the owner already runs more than one directory on this account, and that neither is under this pipeline's control.
- **The main domain is not deployed by this repository at all.** Nothing in `deploy.yml` names it; there are no secrets for it.
- **Deploys of `_core/`, `_apps/`, `_sql/` etc. go to the shared base from every branch — last push wins** (`DEV_NOTES.md:274-278`). Shared dirs mirror with `--delete`, so anything hand-placed on the server in those directories is destroyed on the next deploy (`.claude/CLAUDE.md`, Git Notes).
- **Long-running requests are killed.** `DEV_NOTES.md:3616` — "DreamHost shared FastCGI can kill a long-running request regardless of" (timeout settings). Rules out any long-poll or streaming design.
- **There is no `php.ini`, no `.user.ini`, and no `open_basedir` note anywhere in the repo.** PROVEN by search — so nothing here tells you what PHP's filesystem policy actually is on the server.
- **Only two `.htaccess` files exist in the whole repo**: `web/public_html/.htaccess` and `web/_install/.htaccess` (which just does `RewriteEngine Off`).

### E.2 Apache's own documented rules (high confidence, not verified on this server)

- **`ProxyPass` / `ProxyPassReverse` cannot be used in `.htaccess` at all.** Apache's documented context for `ProxyPass` is server config, virtual host and directory — `.htaccess` is not in the list. This is an Apache rule, independent of the host. So "proxy `mainsite.org/noticeboard` through to the portal with `ProxyPass`" is not available to a shared customer under any circumstances.
- **`RewriteRule … [P]` (the proxy flag) *is* valid in `.htaccess`**, but it requires `mod_proxy` and `mod_proxy_http` to be loaded, and it is a common thing for shared hosts to disable. If `mod_proxy` is not loaded, `[P]` produces a 500.
- **`Options FollowSymLinks` / `SymLinksIfOwnerMatch` in `.htaccess` requires `AllowOverride Options` (or `AllowOverride All`).** This repo's `.htaccess` never uses `Options`, so nothing here proves it is permitted.

### E.3 NOT knowable from this repo — and exactly what would settle each

| Question | Why the repo can't answer it | What would settle it |
| --- | --- | --- |
| Is `mod_proxy` enabled for shared `.htaccess` on DreamHost? | Nothing in this codebase ever uses it. | Put `RewriteRule ^proxytest$ https://example.org/ [P,L]` in a throwaway `.htaccess` on the account and request it. 500 or a "not allowed" error means no. Or check DreamHost's own module list in the panel. |
| Can `.htaccess` be written into the main domain's directory? | The main domain is **often a completely different system** (WordPress, Wix, Squarespace, hand-built) and may not even be on this hosting account or on Apache at all. The repo assumes nothing about it. | Ask the customer, per install, three questions: (a) is the main domain on the same DreamHost account? (b) is it Apache with `.htaccess` honoured? (c) will the person who owns it accept a change? Each answer is per-customer, so no single design can assume any of them. |
| Does `open_basedir` allow PHP on the portal subdomain to read another domain's directory on the same account? | PROVEN only that it can read the portal subdomain's own parent. Sibling-domain access is a different question. | A one-line `file_exists('/home/USER/mainsite.org/public_html/index.php')` probe run through the portal, or DreamHost's `phpinfo` output for `open_basedir`. Note the portal already has a Server Information page at `/admin/system-info` (route seeded `full_schema.sql:8579`) and a phpinfo page — the value is readable there without writing anything new. |
| Are symlinks permitted, and does Apache follow them? | Never used in this repo. | `ln -s` is impossible without a shell; DreamHost's SFTP client may or may not offer it. Even if created, `AllowOverride Options` decides whether `.htaccess` may turn following on. Settle by testing on the account. |
| Does the main domain sit behind a CDN/proxy (Cloudflare) that could rewrite a path to the portal? | Out of scope of this repo entirely. | Ask the customer. |

### E.4 What a shared account CAN reliably do across two directories — as demonstrated by this codebase

The one mechanism that is **proven to work on this exact account** is the one the portal already relies on: **one PHP process reading files from a sibling directory on the same account via an absolute path built with `dirname()`/`__DIR__`**. `web/public_html/index.php` does this on every single request. That is a filesystem read, not a web request, and it needs no Apache module and no proxying.

What that does *not* give you: a way to make `mainsite.org/noticeboard` serve anything, unless the main domain is (a) on the same account, (b) running PHP, and (c) editable by someone willing to add a one-line include. All three are per-customer facts, and the honest position is that **none of them can be assumed by any design**.

The one thing that needs nothing at all from the main domain's host — no shared account, no PHP, no `.htaccess`, no cooperation beyond pasting HTML — is what `/widget/countdown.js` already does: a static script served from the portal, pasted into a page on any platform including Wix and Squarespace. That is the only delivery route proven to work here, and the reason it works is that it never asks the browser to make a `no-cors` request for anything PHP serves (§0.3).

---

## Summary of facts a design must respect

1. **`Cross-Origin-Resource-Policy: same-origin`** is sent on every PHP response (`bootstrap.php:474`). Any portal-served image or video embedded on another origin is blocked (INFERRED from the Fetch spec) until that header is deliberately changed on that response.
2. **No CSP `frame-ancestors` directive exists anywhere**, so there is currently no way to permit a named external site to frame a portal page. `X-Frame-Options: ALLOWALL` is not a mechanism; it is a typo that happens to be ignored.
3. **`Site::id()` is `1` for every sessionless request from an unrecognised host, silently.** The only safe site-identification pattern in the repo is "resolve a token to a row, then take the row's own `siteID`" (`service-plans/public.php`, `forms/public.php`).
4. **The Noticeboard has no expiry, no public flag, no share token, and no slideshow**, and its view is login-gated while the spec requires the opposite.
5. **`/e/{slug}` and `/worship/display` are both fatal on the happy path** (`Site::name()` does not exist). Neither can be cited as a working precedent until fixed.
6. **A real file or folder in `web/public_html/` beats any address.** The current occupants are: `api-docs`, `assets`, `error.php`, `index.php`, `manifest.php`, `offline`, `openapi.php`, `robots.php`, `sitemap.php`, `sitemap.xsd`, `sw.js`, `widget`. Any new public address must avoid all twelve, and `tools/audit-checks/check_webroot_shadowing.py` will catch a mistake.
7. **`api/*` bypasses `tblRoutes` and the `AppRegistry` app toggle**, and needs an `api.{app}.{action}.enabled` seed. Turning the Noticeboard app off does not turn its API off.
8. **The main domain is not deployed by this repo and may not be on this account, this host, or Apache.** Whatever is proposed has to say plainly what must be installed where, and by whom.