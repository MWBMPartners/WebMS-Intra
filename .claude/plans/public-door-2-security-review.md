## Verdict

The design's spine is sound and I would keep four of its decisions unchanged: the public front controller never reading `tblRoutes`; the closed `handlers` map instead of a path fragment; the registry living in code rather than the database; and one bootstrap with the header block extracted. The drift argument against a second bootstrap is correct and well evidenced.

But it builds three of its load-bearing guarantees on things that are not true in this codebase, and one of them publishes a tenant's whole calendar with a single click. Findings are ranked below. Evidence is file and line from the working tree on `claude/alpha-wip`.

---

## CATASTROPHIC

### 1. (B) `tblEvents.isPublic` defaults to **1**, so the calendar surface publishes the back catalogue

`web/_sql/full_schema.sql:792` — `` `isPublic` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Visible on public calendar' ``

Worked example B says the calendar "needs **no schema change at all**" because `isPublic` already exists. A default-true column is not a decision anybody made. Every event any tenant has ever created is `isPublic = 1` unless somebody unticked it, and nobody has had a reason to untick it, because there has never been a public calendar. Switching that surface on republishes elders' meetings, safeguarding reviews, pastoral visits and funeral-planning meetings held at a family's home.

This is the project's own recorded pattern at the scale of a whole table. `web/_apps/calendar/public-landing.php:43-56` carries a comment saying exactly this about one page ("a page nobody can open is a page nobody has checked"). The `columnAllow` list does good work — it correctly drops `locationGeoLat`, `locationGeoLng`, `locationW3W`, `submitterEmail`, `submitterName`, `moderationNote`, `cancelReason` — but it keeps `locationName`, which in practice reads "Mrs Smith's, 14 Elm Road".

**Control.** A new column defaulting to 0 (`publicSurfaceOptIn`), set to 0 for every existing row in migration 193, and the surface requires `isPublic = 1 AND publicSurfaceOptIn = 1`. Publication must be a decision taken *after* the surface existed. The design already gets this right for the noticeboard (`isPublic … DEFAULT 0`); it must not make an exception for the app with the most rows.

### 2. (C) Power 1 is not root-only. Any site admin can switch the public door on for every tenant

`web/_apps/settings/index.php:38` gates the generic dot-notation settings editor on `App::isAdmin()`. `web/_core/App.php:393-403` makes that true for any `isSiteAdmin` or `isSiteRootAdmin`. Then `web/_apps/settings/save.php:80-89`:

```php
'UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW()
   WHERE settingID = ? AND (siteID = ? OR siteID IS NULL)'
```

A site administrator of tenant B may edit a **global** row. `public.enabled` is designed to be a global row. So a non-root admin of any tenant can publish the door for the whole installation from a page that already ships. They can also insert `public.{slug}.enabled = 'true'` for their own site — the INSERT branch scopes to `Site::id()` — which is the power §4 reserves to root.

§4 states "Settings rows, changed only from a new page at `/admin/public` gated on `App::isRootAdmin()` and nothing else." There is a second, wider-open writer of the same rows, and the design does not know about it.

**Control.** Refuse the `public.` key prefix in `settings/save.php` and the delete branch of `settings/index.php` unless `App::isRootAdmin()`. Make editing any `siteID IS NULL` row root-only. Add an audit check asserting no non-root-gated handler writes a `public.` key.

### 3. (A) The public web root's document root is chosen by hand, and there is no `.htaccess` above `web/public_html/`

`find web -name .htaccess` returns exactly two files: `web/public_html/.htaccess` and `web/_install/.htaccess`. Everything else is protected solely by being outside a web root.

The design creates a second web root on the same account and never says what its document root must be. On DreamHost the web directory for a domain is set in the panel, and the natural thing to do when you want the public door to "share the code" is to point it at the shared base. Do that and Apache serves `_sql/full_schema.sql`, `_backups/` (database snapshots), `_uploads/` (pastoral attachments, kids' photos, expense receipts, Gift Aid addresses), `_core/`, `_apps/` and `_install/` — whose own `.htaccess` reads "Allow direct access to install files" and turns rewriting off.

The mistake is in the hosting panel, not in a secret, so every deployment guard in §8 passes cleanly while this is happening.

**Controls, in order of reliability.**
1. A deterministic runtime refusal in bootstrap: `realpath($_SERVER['DOCUMENT_ROOT'])` must sit strictly *inside* `PORTAL_ROOT`; if it equals `PORTAL_ROOT` or is an ancestor of it, 500 and stop. This does not depend on Apache configuration.
2. A post-deploy probe that fetches `https://<public host>/_sql/full_schema.sql` and `/_uploads/` and fails the run on a 200. This is the only check that tests the live arrangement.
3. Ship `.htaccess` files denying everything into `web/`, `_sql/`, `_uploads/`, `_backups/`, `_auth_keys/`, `_core/`, `_apps/` — as defence in depth only. Whether Apache honours an `.htaccess` above the document root depends on `AllowOverride` for that directory, which nothing in this repo proves.

---

## CRITICAL

### 4. (A) The partial revert. Guard 1 only checks the remote side

`.github/workflows/deploy.yml` lives in the repo. Revert `web/` without reverting `.github/` — a hotfix branch cut from before the rename, a cherry-pick, a stale CI checkout — and you have the new workflow (which mirrors `web/public_html/` to `SFTP_PUBLIC_PATH_LIVE`) pointed at a tree where `web/public_html/` is once again the entire management portal.

§8's Guard 1 downloads the **remote** `.door` and compares it "with what it is about to upload". A pre-rename tree has no `.door` at all. Remote says `public`; local says nothing. The design never says that refuses — and it is the single most likely way the catastrophic direction actually happens.

**Control.** Two-sided and content-based. Refuse unless the local source directory contains `.door` with the channel's expected content **and** the remote one matches. Plus one structural assertion that cannot be got wrong: `web/admin_html/index.php` must exist before any upload runs. If it does not, this is a pre-rename tree and the run stops.

### 5. (B, D) `Site::id()` answers **1** on the public door, silently

`web/_core/Site.php:70` — `private static int $currentSiteID = 1;`
`web/_core/Site.php:162-165` — `id()` returns it with no check.
`web/_core/Site.php:76` — `$resolved` exists and `id()` never consults it.

§2 says the public door "leaves `Site` uninitialised". It does not leave it refusing; it leaves it answering site 1 — the exact cross-tenant default the design set out to remove. Every shared helper the public door touches inherits it: `App::user()` (`App.php:221`), `Logger::activity()` (`Logger.php:61`), `Logger::audit()` (`:122`), `Logger::errorPlatform()` (`:236`).

"A public handler must never call `Site::id()`" is a house rule, and this project's own history is a list of house rules that were followed until they were not.

**Control.** Start `$currentSiteID` at `0` and make `id()` throw when `$resolved === false`. Add `Site::initPublic(int $siteId)` which sets both. Four lines, and it converts the rule into a mechanism: a forgetful handler produces a 500, not a leak.

### 6. (B, C) `App::settingForSite()` falls back to the global row — one row publishes every tenant

`web/_core/App.php:177-181`:

```php
'SELECT settingValue FROM tblSettings
   WHERE settingKey = ? AND (siteID = ? OR siteID IS NULL)
   ORDER BY (siteID IS NULL) ASC LIMIT 1'
```

Gates 3 and 4 use this function. A single global `public.noticeboard.enabled = 'true'` publishes that surface for **every tenant that has not written its own row**. And `web/_apps/admin/settings/group.php:129-133` writes `siteID = NULL` unconditionally — so if these keys are ever folded into a settings group page, one click does it.

**Control.** Read the per-surface flag site-scoped only (`WHERE settingKey = ? AND siteID = ?`, no global fallback) through one helper that every gate calls. Keep the global fallback only for the master `public.enabled` kill switch, where inheriting "off" is the safe direction.

### 7. (A) `web/public_html/media/` cannot be protected by `WEB_ROOT_EXCLUDES`

`deploy.yml` step 1 (both the key and password variants) runs:

```
mirror --reverse --delete ... $LFTP_EXCLUDES web/public_html/ ${REMOTE_DIR}
```

Only `$LFTP_EXCLUDES`. `$WEB_ROOT_EXCLUDES` appears **only** in step 2, the shared mirror. §8 step 1 says to add `^public_html/media/` to `WEB_ROOT_EXCLUDES`; that pattern is anchored relative to `web/` and the per-channel mirror will never consult it.

Result: the first deploy after anything is published deletes every published poster. And because §6 makes file existence the publication state, the posters silently unpublish with no database change and nothing anywhere to explain it.

**Control.** Add `--exclude ^media/` to the per-channel public mirror specifically, and an audit check asserting that string is present. This is the "single most important line in the deployment change" by the design's own account, and as written it does nothing.

### 8. (B) "The file's existence is the publication state" is the failure mode, not the safety property

Once bytes are in `web/public_html/media/`, the database is no longer authoritative. Unpublishing, soft-deleting a poster, a GDPR erasure, an offboarding, a restore from an older snapshot and the deploy's `--delete` all disagree with what Apache is serving.

`grep -n "noticeboard" web/_core/GdprEraser.php` returns **nothing** — the noticeboard is not in the erasure catalogue today, so a member's face on a poster already survives an erasure request. The design doubles that surface with no lockstep.

**Control.** Keep static publishing (the argument for it in §6 is genuinely strong — Apache serves byte ranges, PHP never starts, no CORP), but add: a reconciliation sweep that lists the directory and deletes any file with no matching `isPublic = 1` row; a `GdprEraser::catalogue()` entry; an `offboarding/do.php` step; and finding 7's exclusion. Without the sweep the database stops being the record of what is published, which is the one thing that must stay true.

### 9. (B) `$SETTINGS` is handed to every public handler with the secrets decrypted

`web/_core/bootstrap.php:365-372` decrypts every `isSensitive = '1'` row into `$SETTINGS` on every request — Stripe and PayPal keys, the MS365 client secret, the Web Push VAPID private key, what3words and Google keys, SMS credentials. §3 names `$SETTINGS` as one of the four variables a public handler receives.

Handing that array to the code whose entire job is rendering pages for anonymous strangers is a needless concentration. One `print_r` in a debug session, one templating helper that walks the array, one error page that dumps its scope.

**Control.** The public door builds its own settings array containing only an allow-listed prefix set (`public.*`, `branding.*`, the surface's own `{slug}.*`). Same discipline as `ReportRegistry`'s column whitelist, applied to configuration.

### 10. (E) Rate limiting on the public door is bypassable with one header

`web/_core/RateLimiter.php:304-319`:

```php
if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) === true) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'];
}
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) === true) { ... }
return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
```

No check that the request arrived through a trusted proxy. On DreamHost shared hosting there is none by default. Any visitor sends `CF-Connecting-IP: 1.2.3.4` and gets a fresh identity per request. Every per-IP bucket the design leans on — the forms precedent's five-per-fifteen-minutes, the login lockout, everything — is theatre.

This is a pre-existing defect, but the admin door is behind a login and the public door is not. Fix it **before** the public door ships, not after.

**Control.** Trust those headers only when `REMOTE_ADDR` is in a configured trusted-proxy list, seeded empty (trust nothing).

### 11. (D) The site token's case handling is undefined on a door that does not use `Router`

`Router::extractPath()` lowercases the whole path (`Router.php:320`), which is why `/os/{token}` and `/f/{token}` are safe with `[a-f0-9]{32}`. The public door does not use `Router`. §5 notes the lowercase point but leaves the enforcement to code that does not exist yet — so whether it holds depends on the next person.

**Control.** Generate with `bin2hex(random_bytes(16))`, store `CHAR(32)`, normalise with `strtolower()` + `ctype_xdigit()` before the lookup, and refuse a non-lowercase-hex token on write. State it in the registry contract, not in a comment.

---

## HIGH

### 12. (A) Guard 3's string checks are both a false alarm and no protection

§8 proposes refusing if a public path contains `admin_html` or an admin path contains `public_html`. That conflates repo directory names with **remote** directory names, which are unrelated. Today's live admin root is `/home/USER/portal.millrdsdacambridge.uk/public_html` (`deploy.yml:33-38`), and nothing forces a customer to rename it on the server — doing so means changing the web directory in the hosting panel and risking downtime. So for most upgrades `SFTP_ADMIN_PATH_LIVE` contains the string `public_html`, guard 3 refuses a correct deployment, somebody deletes the guard, and it is gone for the real case.

**Control.** Keep only: the two paths must differ, neither may be a prefix of the other, neither may equal or contain the shared path. Content-based `.door` (finding 4) is the real guard.

### 13. (A) `PORTAL_ENV` is guessed from a directory name that the customer owns

N2 is correct — `bootstrap.php:96-105` matches `public_html_dev`, `public_html_beta`, `public_html`, and falls through to `$env = 'dev'`, which at `:118-127` turns `display_errors` on. But the proposed fix keeps the fragile mechanism and adds a failure: on route (c3) the `DOCUMENT_ROOT` is the customer's main domain directory, whose name we do not control, so the channel is inferred from a stranger's folder name. Defaulting to `prod` then silently disables error display on a genuinely mis-named dev channel.

**Control.** Stop inferring. Each front controller declares `PORTAL_CHANNEL` (or it is read from `_auth_keys/`, which is server-managed and already per-channel), and bootstrap refuses when it is absent, exactly like `PORTAL_DOOR`. Keep the directory sniff only as a logged fallback that forces `prod`.

### 14. (A, general) Eight audit checks hard-code `web/public_html`, not two

The design names `check_webroot_shadowing.py` (line 77) and `check_route_targets.py` (line 35). The full list is:

```
check_bind_param_arity.py:52    check_cdn_sri.py:32
check_mobile_readiness.py:40    check_no_native_confirm.py:28,39
check_php_table_refs.py:45      check_route_targets.py:35
check_settings_keys.py:40       check_webroot_shadowing.py:77
```

After the rename each silently scans the new, nearly-empty public directory and reports zero findings — indistinguishable from success. That is this project's own recorded lesson ("checks only cover what they read"). There are fourteen scripts in `tools/audit-checks/`, not thirteen.

**Control.** Every one of the eight gains both directories, and `check_webroot_shadowing.py` gains a second pass for the public door. Prove each still fires by planting a deliberate fault, as §8 step 1 says for one of them — do it for all eight.

### 15. (E) The maintenance gate is lost, and losing it is not safe

`web/public_html/index.php:55-62` runs the gate before dispatch. `Maintenance::isActive()` is true while the installed version is behind `PORTAL_VERSION` — precisely the window in which a column a public query names may not exist yet. During the 193 upgrade itself, `tblNoticeboardPosters.isPublic` does not exist, the query throws, and the exception handler renders a 500.

**Control.** The public door runs the check too, but renders a small static "temporarily unavailable" page with `Retry-After`. Never the admin holding page, and never `Maintenance::currentUserCanBypass()`, which reaches `App::isAdmin()` → `App::user()` → the session.

### 16. (E) An exception on the public door renders the full admin chrome

`bootstrap`'s exception handler → `Logger::exception()` → `Router::renderError(500)` (`Router.php:483-493`) → `web/_core/templates/error-500.php:21` → `header.php`. That emits the navigation, the tenant's enabled app list, the admin CSP, and `X-Frame-Options: SAMEORIGIN` (`header.php:99`) — which breaks a framed embed at the exact moment the visitor most needs a sensible message, and enumerates the tenant's apps to an anonymous stranger.

**Control.** Door-aware error rendering with the public door's own bare templates, including nothing from `_core/templates/`.

### 17. (E) `Gatekeeper::enforce()` is called from nowhere, and the design puts a public door on alpha and beta

`grep -rn "Gatekeeper" web/` finds only `web/_lang/en.php:263` and the help pages. The class that keeps non-admins out of the alpha and beta channels is never invoked from any front controller. So today's pre-release admin portals are open to anyone who knows the hostname.

§8 steps 4 and 5 deploy a *public* door to those channels on purpose, with robots and sitemap logic live on them.

**Control.** On any channel that is not live, the public door sends `X-Robots-Tag: noindex` unconditionally in **code**, not from a setting, and preferably refuses entirely. Separately, wire `Gatekeeper::enforce()` in or delete it — a security class nothing calls is the project's "shipped but unreachable" pattern pointing the wrong way.

### 18. (F) CORP scoping is tied to the wrong flag

`same-site` is right only when both doors share one registrable domain. §8 deliberately allows different parents (correctly — DreamHost gives each domain its own directory), and route (c3) puts the public door under a different domain entirely. In that arrangement `same-site` blocks the very embed it was chosen to permit, silently, with no console error anywhere — the exact failure shape the survey documents at §0.3.

Making CORP depend on the per-surface `embeddable` flag compounds it: the value then varies by surface and nobody can see what was actually sent.

**Control.** Set `Cross-Origin-Resource-Policy: cross-origin` for static assets and media in the public door's `.htaccess`, where PHP is not involved. Leave HTML documents at `same-origin` — nothing pulls a document `no-cors` anyway. Framing is `frame-ancestors`' job; do not mix the two.

### 19. (F) `frame-ancestors` must name the scheme, and `header.php` can still undo it

Two additions to a section that is otherwise right.

`web/_core/CloudflareStream.php:67-71` — `ORIGIN_RE = '/^[a-z0-9.-]+$/i'` — accepts a **bare hostname**. A bare host in a CSP source list matches both `http://` and `https://`. A customer whose main site is still plaintext would then be permitted to frame us over http, where anyone on the same network can inject into the parent page.

And `web/_core/templates/header.php:99` hard-codes `X-Frame-Options: SAMEORIGIN`, with `header()` defaulting to replace. Any public page that reaches `header.php` — including through the error template in finding 16 — silently re-breaks framing.

**Control.** Emit `https://example.org` explicitly, never the bare host; refuse `*`. Emit `frame-ancestors 'none'` when the list is empty rather than omitting the directive — an absent directive is permissive.

### 20. (G) Public writes are not designed, and the noticeboard spec asks for them

`acceptsPost => false` is correct for the noticeboard *today*. But `tblNoticeboardPosters.link` is a free VARCHAR(1024) and the spec calls for booking, RSVP and registration calls to action. The moment one of those points at a portal address, it is either a write on the **admin** door (login wall, so it just fails) or a public write surface that does not exist.

**Control.** State that v1 public surfaces are read-only, full stop: `acceptsPost` exists in the registry but no shipped surface sets it, and calls to action are outbound links to the customer's own booking system. When a public write is eventually wanted it needs the full `forms/public.php` stack — honeypot → CSRF → `Captcha::verify()` → `RateLimiter` → per-IP bucket — and finding 10 must be fixed first or the last two steps do nothing.

### 21. (G) CSRF and cacheability are in direct conflict

`Auth::csrfToken()` (`Auth.php:268-277`) stores the token in the session; `Auth::verifyCsrf()` (`:301`) **rotates it on success**. §1 argues the public door's value is that it emits no `Set-Cookie` and is therefore cacheable. A page carrying a CSRF token cannot be cached: a shared cache serves one visitor's token — and possibly their `Set-Cookie` — to another.

**Control.** `acceptsPost => true` must imply `Cache-Control: no-store` in the door, not by convention. Say it in the registry.

### 22. (G) `Captcha` reads the settings snapshot, not the resolved site

`web/_core/Captcha.php` has no `Site::id()` or `settingForSite()` call anywhere — it reads `App::settings()`. On the public door that snapshot is for the host-detected (or site 1) tenant, so tenant B's captcha configuration silently comes from tenant A. If tenant A has captcha off, tenant B's public form has no captcha.

`ApiRouter::resolveEnabledFlag()` already works around exactly this. Apply the same discipline to Captcha on the public door.

### 23. (H) No rate limit, no cache, and shared hosting

The design has no rate-limiting story for the public door at all, and the one mechanism that exists is bypassable (finding 10). Every public page is a PHP process plus a database connection. A foyer display polling forever, a crawler, and an embed on a popular page are indistinguishable. The sitemap will offer up to 5000 event addresses (`sitemap.php` `LIMIT 5000`), each a PHP page, and invite a crawler to fetch them in a burst.

**Controls.** Fix finding 10. Put `Cache-Control: public, max-age=60, stale-while-revalidate=300` on every read-only surface — only possible *because* the design correctly refuses to start a session, which is the strongest argument in §1. Cap the foyer display's poll interval server-side and deliver it in the JSON, so a misbehaving display can be slowed without a re-deploy. `DEV_NOTES:3616` (FastCGI kills long requests) already rules out streaming; say that the slides surface polls. Hard `LIMIT` on every public query.

### 24. (I) The embed ranking is the wrong way round

§6 makes the script embed the default and the iframe the fallback. `/widget/board.js` on a third-party origin with a `data-site` attribute is the exact shape of a tracking script; blockers catch it, and the failure is an empty `<div>` on the customer's page that only their visitors ever see. An iframe is a navigation — harder to block generically, and it fails visibly.

**Control.** Recommend the iframe first for anything the customer cares about, the script embed as the enhancement, and add a fourth option that always works: a plain link and a QR code to the public subdomain. The script embed must render a visible fallback ("Noticeboard unavailable — view it at …") when the fetch fails, rather than leaving the page looking fine.

---

## MEDIUM

25. **(A) `web/admin_html/` missing from `WEB_ROOT_EXCLUDES`** (`deploy.yml:126-136`). N5 names it; the consequence is worth stating precisely. The shared mirror runs on **every** branch and targets `<base>/`, so `web/admin_html/` would land at `<base>/admin_html/` — the live admin root. An alpha push would overwrite the live admin portal.

26. **(A) `mirror --delete` into a mistyped path.** `SFTP_PUBLIC_PATH_LIVE` typed as `/home/USER/example.org/public_html` — plausible, since §8's own example uses a second domain directory — mirrors with `--delete` over the customer's main website. Control: refuse a remote directory that does not already carry the expected `.door`, with the one-time bootstrap as an explicit workflow input that requires the path typed again as confirmation.

27. **(A) Rollback is not "three secrets and one dispatch".** It is finding 4, plus the fact that publication is a settings row rather than code: rolling the code back leaves `public.enabled = 'true'`. State that a rollback switches the surfaces off first.

28. **(C) Malformed declarations do not fail closed enough.** `AppRegistry::all()` (`AppRegistry.php:57-84`) skips a file that does not return an array with a `slug`, but `require $file` on a file with a PHP fatal is uncaught — a broken `public` declaration takes down **both** doors, including the admin page where you would switch it off. And the `$meta + [...]` defaults block has no entries for the `public` sub-array, so missing keys are `null`, which behaves differently in loose and strict tests. Control: try/catch the require and skip the app; extend the defaults block with `'public' => ['embeddable' => false, 'indexable' => false, 'acceptsPost' => false, 'handlers' => []]`; add an `assertSelfConsistent()` that hard-fails on a declaration naming care, kids, safeguarding, directory, giving, expenses, prayer-requests, auth, admin, api or settings — structurally absent, not merely off.

29. **(C) Truthiness is inconsistent.** `AppRegistry::isEnabled()` (`AppRegistry.php:105-110`) accepts `'1'` **or** `'true'`; the design's gates compare `!== 'true'`. The `'1'`-versus-`'true'` quirk is already recorded as inherited and unfixed. Today's mismatch fails closed (a tenant whose toggle wrote `'1'` never publishes), but any future code reusing `isEnabled()` as a public gate fails open. One comparison, one function, every gate calls it.

30. **(C) Per-row publication *is* a publication power.** §4 says a delegated admin "can change what an already-published surface shows — which posters". Choosing which posters are public is the publication decision. Say so plainly, and add `rowFilter` and `columnAllow` to the list of things delegation can never reach (they live in code, so this is free).

31. **(D) `tblPublicHosts` uniqueness is a denial-of-configuration.** Tenant B claiming `noticeboard.tenantA.org` gains them nothing but stops tenant A from ever adding it, because of the unique key. Root-only writes plus a clear "already claimed" message plus an activity-log entry.

32. **(D) Drop source 3.** "Single-site installs → site 1 taken directly" re-introduces the silent default at the moment `multisite.enabled` is later switched on: every embed that relied on it starts resolving through sources 1 or 2, and any with neither starts 404ing. Fails closed, which is right, but nobody will predict it. Write the host row at install time and keep one code path.

33. **(D, E) Refusals are filed under the wrong tenant.** `Logger::errorPlatform()` stamps `Site::id()` (`Logger.php:236`) — site 1 on the public door (finding 5). Every public-door refusal for every tenant lands in tenant 1's error log. A logging path that takes the site explicitly, or `null` when unknown.

34. **(G) The two doors share one session store.** Same Unix user, same `session.save_path`; a different cookie name does not separate them. Nothing is gained by an attacker today (a public session has no `user_id`), but the public door can create session files at will, which on shared hosting is a cheap way to fill a disk. Give the public door its own `session.save_path` subdirectory.

35. **(I) Mixed content and certificates.** An http main site cannot be safely named in `frame-ancestors` (finding 19) — refuse it and tell them to fix the certificate. A lapsed certificate on the public subdomain makes the script embed fail silently and the iframe show a browser interstitial inside their page; the admin dashboard should read the peer certificate's expiry (`stream_socket_client`, no library needed) and warn at fourteen days.

36. **(I) Every embed hard-codes the public hostname.** A domain change breaks every embed on sites we do not control, silently. Generate the snippet from the portal with a copy button, and list on the admin page which origins have fetched the feed in the last 30 days — that falls straight out of the `Origin` header the CORS check already reads, and it tells the customer who to warn.

---

## LOW

37. **(A) `.door` returns 403, not 404.** `web/public_html/.htaccess` blocks `(^|/)\.(?!well-known)` with `[F]`, so its existence is confirmable from outside. Harmless, but add it to the robots deny list and do not give it a name that tells an attacker anything.

38. **(A) `deploy.yml`'s summary references `steps.target.outputs.public_dir`, which is never set** — the deployment summary has always printed an empty value. Fix it while the file is open.

39. **(I) `Router::healthCheck()` (`Router.php:521+`) is unauthenticated** and returns env, version, PHP version and the current site ID. It stays on the admin door, so the public door does not widen it — but it is worth noting while headers and doors are being reconsidered.

---

## What I would refuse to ship as designed

1. The calendar surface reading `tblEvents.isPublic` with no new opt-in column (1).
2. The permission model, until `/settings` and `/admin/settings/{group}` are closed against `public.*` keys and global-row editing (2, 6).
3. Any arrangement that does not deterministically refuse when the public document root sits at or above `PORTAL_ROOT` (3).
4. `Site::id()` continuing to answer 1 on the public door (5).
5. Static media publishing without the reconciliation sweep, the GDPR catalogue entry, the offboarding step and the corrected deploy exclusion (7, 8).
6. `$SETTINGS` with decrypted secrets passed to public handlers (9).
7. Any public surface relying on rate limiting while `RateLimiter::getClientIp()` trusts a client-supplied header (10).
8. Guard 3's string checks as the deployment safety net, and Guard 1 checking only the remote side (4, 12).
9. A public door on the alpha and beta channels while `Gatekeeper::enforce()` is called from nowhere (17).

## What is right and should survive review unchanged

The public front controller never opening `tblRoutes`; the closed `handlers` map with a `realpath()` prefix check; the registry in code rather than in the database; retiring `SFTP_LIVE_PATH` / `SFTP_BETA_PATH` / `SFTP_DEV_PATH` so a forgotten update fails loudly; separate shared-path secrets instead of `dirname()`; the unconditional `noindex` on the admin door and the fixed `Disallow: /`; renaming the indexing settings keys rather than inheriting them; refusing when host and token disagree; one bootstrap with the header step extracted; server-rendered pages rather than an iframe pointed at itself; and the honest statement that route (c) has no mechanism that works for every customer.