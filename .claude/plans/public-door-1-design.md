I have verified the structures my design must extend. Five findings that change the naive plan, then the design.

---

# Two front doors — full structural design

## 0. Five things I verified that the plan must account for

These are new, from reading the code, and each one breaks a step of the obvious approach.

**N1 — `PORTAL_ROOT` does not depend on the web root, so the shared-code problem is much smaller than it looks.** `web/_core/bootstrap.php:57` is `define('PORTAL_ROOT', realpath(__DIR__ . '/..'))`. `__DIR__` is `web/_core`, so `PORTAL_ROOT` is `web/` no matter which front door required the file. `PORTAL_CORE`, `PORTAL_APPS`, `PORTAL_VENDOR` and `PORTAL_SQL` all hang off it. **Nothing about the path constants has to change.**

**N2 — the rename, on its own, turns on error display in the live management portal.** `bootstrap.php` (lines ~96-110) works out `PORTAL_ENV` by looking for the strings `public_html_dev`, `public_html_beta` and `public_html` inside `DOCUMENT_ROOT`. `admin_html` matches none of them, so it falls through to `$env = 'dev'` — and lines 118-120 then set `display_errors` to `'1'`. A rename done without touching this file publishes PHP warnings, file paths and query fragments to every visitor of the live admin portal. The comment calls defaulting to dev "for safety"; it is the opposite.

**N3 — `Router` has the same rename bug, twice.** `web/_core/Router.php:95` builds `PORTAL_ROOT . '/public_html'` as the fallback location for a route's target file, and line 257 does the same for the offline page. After the rename, both point at the **public** site. An admin route whose handler is missing from `_apps/` would be resolved out of the public web root.

**N4 — the public door needs its own copy of the assets, and this is forced by D2.** Every `Asset::` path is root-relative (`/assets/css/portal.css` and friends, `web/_core/Asset.php:129-180`). A `<link rel=stylesheet>` is a `no-cors` request, so `Cross-Origin-Resource-Policy: same-origin` on the admin door blocks the public origin from loading the admin origin's stylesheet. This bites on route (a), not just (b).

**N5 — the deploy workflow would copy the whole admin portal into the shared directory.** `WEB_ROOT_EXCLUDES` in `.github/workflows/deploy.yml` excludes `^public_html/`, `^public_html_dev/`, `^public_html_beta/`, `^public_html_landing/`, `^public_html_redir/`, `^private_html/`. It does not exclude `admin_html/`, because that does not exist yet.

Also relevant to §3 and §4: there is **no fine-grained permission table** in this codebase. `tblRoles` holds only `roleKey` and `roleName`, and `App::hasRole()` (`web/_core/App.php:340`) joins `tblUserRoles` with no site column at all. Roles are portal-wide and undifferentiated.

---

## 1. The two front doors

### What is in each

**`web/admin_html/`** — today's `web/public_html/`, moved, contents nearly unchanged:

`.htaccess`, `index.php`, `error.php`, `api-docs/`, `assets/`, `offline/`, `manifest.php`, `openapi.php`, `sw.js`, and a new `.door` file containing the word `admin` (see §8).

Three changes to what it holds: `robots.php` becomes a fixed two-line `Disallow: /` with no settings reads at all (see §7); `sitemap.php` and `sitemap.xsd` **move to the public door**, because the management portal has nothing to offer a search engine; `widget/` moves to the public door, because an embeddable script belongs with the public site.

**`web/public_html/`** — new, and deliberately small:

`.htaccess` (its own, different), `index.php` (the public front controller, a new file), `error.php` (a plain page that never touches the admin templates), `assets/` (its own small set — N4), `robots.php`, `sitemap.php`, `sitemap.xsd`, `widget/` (`countdown.js` plus the new embed scripts), `media/` (published static poster files — §6), `.door` containing `public`.

It has **no** `api-docs/`, no `manifest.php`, no `openapi.php`, no `sw.js`, no `offline/`. The public site is not a progressive web app and does not publish an API description.

### How the public door structurally cannot reach an admin page

The owner asked for this to be structural rather than a filter, and the filter version is genuinely tempting — read `tblRoutes`, respect `isProtected`. That is the wrong answer, because it would make a single database column the only thing between the open internet and `/admin/users`. Three independent mechanisms instead, and the first is the real one:

1. **The public front controller never calls `Router::dispatch()` and never reads `tblRoutes`.** Not "reads it and filters it" — never opens it. It resolves addresses against the public surface registry (§3), which is a separate, code-owned, allow-list-only structure that physically contains no admin entries. A route row cannot put anything into it, because nothing on the public door ever reads a route row.

2. **It never builds a path under `PORTAL_APPS` directly.** Handlers resolve only under `PORTAL_APPS/{slug}/public/`, and the resolved path is checked with `realpath()` against that prefix before the `require`. A declaration naming `../../admin/users.php` fails the prefix check.

3. **`define('PORTAL_DOOR', 'public')` before bootstrap**, and `Auth::ensureSession()`, `Auth::requireLogin()` and `App::user()` refuse outright when it is set. Belt and braces: if some shared helper ever reaches for a session on the public door, it fails loudly rather than half-working.

### What the public front controller must not set

It runs a **different header block** from the admin one — the same file, a different branch (§2).

| Header | Admin door | Public door | Why |
| --- | --- | --- | --- |
| `Cross-Origin-Resource-Policy` | `same-origin` (unchanged) | `same-site` baseline; `cross-origin` on anything embeddable | D2. `same-origin` blocks every `<img>`, `<video>`, `<script src>` and stylesheet an external page would pull |
| `X-Robots-Tag` | `noindex, nofollow, noai, noimageai` **unconditionally** | nothing, unless deliberately saying no | D3. Absence is what permits indexing. Making the admin value unconditional is what stops one settings row publishing both doors (§7) |
| `X-Frame-Options` | `SAMEORIGIN` (unchanged) | **never sent** | XFO cannot name a specific external site. `frame-ancestors` can. Sending both means the stricter wins, so sending XFO at all would break route (b) |
| `Content-Security-Policy` | as today, from `header.php` | its own, with `frame-ancestors` | The public door never includes `web/_core/templates/header.php`, which is where the hard-coded `SAMEORIGIN` (line 99), the narrowed `Permissions-Policy` (101) and the preload-HSTS (102) live |
| `X-Powered-By` | as today | never sent | A public site should not name its stack |
| `Strict-Transport-Security` | as today | as today | Unambiguously good on both |

### Does it start a session?

**No, not by default.** Three consequences, and the second is the one that earns the decision:

- No `Set-Cookie` means the response is cacheable by the browser and by any CDN in front of it. That is what a foyer display and an embedded page need, and it is what DreamHost's FastCGI request-killing (DEV_NOTES:3616) makes desirable.
- It removes an entire class of bug where a public page accidentally reads a signed-in administrator's session and shows them more than a stranger would see. That class of bug is invisible in testing, because the developer is always signed in.
- The exception: a surface that accepts a POST needs a CSRF token, which needs a session. The registry declaration carries `'acceptsPost' => true`; only then is a session started, and with a **different cookie name** (`WEBMSPUB`), so a public session can never be an admin session. This copies what `web/_apps/forms/public.php` already does at line 95.

### How it refuses anything not explicitly published

Uniform 404 — same status, same body, every time, so that nothing is an oracle for whether a thing exists. Five gates in order:

1. The first address segment is a key in the compiled public surface map. Miss → 404.
2. The site resolves (§5). Miss → 404.
3. `public.enabled` is `'true'` **for that site**. Off → 404.
4. `public.{slug}.enabled` and `{slug}.enabled` are both `'true'` for that site. Off → 404.
5. The handler file resolves under the app's own `public/` directory. Fail → 404 **plus a platform log entry**, because that one is a deployment fault rather than a visitor mistake.

Gates 3 and 4 must read with `App::settingForSite()`, **not** from `$SETTINGS` and **not** via `AppRegistry::isEnabled()`. `AppRegistry::isEnabled()` reads `App::settings()`, which is the snapshot bootstrap built from the host-detected site (`bootstrap.php:355-372`). On a public request where the site came from a token rather than the host, that snapshot is for the wrong site. `ApiRouter::resolveEnabledFlag()` already works around exactly this, and the noticeboard API handlers carry comments saying so.

---

## 2. Reaching the shared code from both doors

Because of N1, the path constants need no change at all. Three things do, and all three are the same bug — code that assumes the web root is called `public_html`:

- `web/_core/bootstrap.php`, the `PORTAL_ENV` block: match the new directory names, and **default to `prod`, not `dev`**. Defaulting to dev is a fail-open default in a security-relevant setting (N2).
- `web/_core/Router.php:95` and `:257`: replace the hard-coded `PORTAL_ROOT . '/public_html'` with a constant (N3).

Each front controller declares itself **before** requiring bootstrap:

```php
// web/admin_html/index.php          // web/public_html/index.php
define('PORTAL_DOOR', 'admin');      define('PORTAL_DOOR', 'public');
define('PORTAL_WEBROOT', __DIR__);   define('PORTAL_WEBROOT', __DIR__);
```

and bootstrap, immediately after `PORTAL_ROOT`, refuses anything else:

```php
// 🚪 Which front door are we? There is no sensible default. A file that
//    reaches bootstrap without going through one of the two front
//    controllers — including a stale front controller left on the server
//    by a half-finished deployment — stops here rather than running with
//    a guessed identity.
if (defined('PORTAL_DOOR') === false) {
    http_response_code(500);
    exit('Portal misconfigured: no front door declared.');
}
```

That single refusal is the loud failure the brief asks for, at runtime.

### Should the public door use the same bootstrap? Yes — and here is the argument, not the assertion

A separate, smaller `bootstrap-public.php` is genuinely attractive: 150 lines instead of 560, cannot accidentally load `Auth`, and a reviewer could read the whole thing in one sitting. I considered it properly. Three things decide against it.

**Drift is certain, silent, and this codebase already has two examples.** `header.php` re-sends four headers bootstrap already sent, with different values, and nobody noticed it silently overrides the `portal.headers.x_frame_options` setting on every chromed page. `_apps/api/livestream-ping.php` is a dead copy of a file that moved. A second bootstrap would be the third instance — and the day somebody fixes a settings-decryption or connection-handling bug in one, the copy that keeps the bug is the one facing the open internet.

**The expensive parts are not optional.** The public door still needs the database connection, the settings loader with its decryption, the autoloader, the error and exception handlers, and the timezone. That is most of bootstrap's body. What it does not need — `Auth`, the maintenance gate, `Debug` — bootstrap does not load anyway; those are lazy-loaded or called from the front controller.

**What genuinely differs is one contiguous block** — step 6b, the header section, roughly lines 400-520.

So: one bootstrap. Extract step 6b into `web/_core/Headers.php` as `Headers::emitAdmin()` and `Headers::emitPublic(array $surface)`, and have bootstrap call the right one on `PORTAL_DOOR`. The connection, settings, autoload and handler code is then provably identical for both doors, because it is literally the same lines.

**One thing the public door does skip, and it matters.** `Site::preDetect()` (bootstrap:327) and `Site::init()` (bootstrap:543). Running host-pattern detection on the public door would install a silently wrong default — site 1 — that later code might read. On `PORTAL_DOOR === 'public'`, bootstrap leaves `Site` uninitialised; the front controller resolves the site explicitly (§5), then calls a new `Site::initPublic(int $siteId)` and loads `$SETTINGS` for **that** site. That incidentally makes the public door the only place in the codebase where the settings snapshot and the active site are guaranteed to agree.

---

## 3. The public surface registry

### Extend AppRegistry — and why that is right

`web/_core/AppRegistry.php` is already the single source of truth for "does this app exist and is it on"; it is already one file per app, so a new app joins by adding a file and touching nothing shared; `all()` already applies defaults, so an app that says nothing about public surfaces gets a safe empty default for free; and `/admin/apps` is already where an administrator goes. A parallel mechanism would have to re-solve all four.

**One thing I would not do: put the declaration in the database.** Not `tblRoutes`, not a new table. A row is editable by anyone with database access and is never reviewed. A file in `web/_core/apps/` goes through a pull request. The entire value of the public surface list is that it is short, human-readable, and **cannot be extended at runtime**.

### What a declaration looks like

A new optional `public` key. Absent — which is all 47 apps today — means the app has no public side and nothing can turn one on.

```php
// web/_core/apps/noticeboard.php
return [
    'slug' => 'noticeboard',
    // … existing keys unchanged …

    // 🌍 The public side of this app.
    //    Its presence here does NOT publish anything. It declares what COULD
    //    be published. Two settings must both be 'true' for the site before
    //    any of it is reachable, and both seed 'false'.
    'public' => [
        'surface'     => 'noticeboard',      // first segment of the public address
        'handlers'    => [                   // a CLOSED map, not a path fragment
            ''       => 'board.php',         // /noticeboard
            'poster' => 'poster.php',        // /noticeboard/poster
            'slides' => 'slides.php',        // /noticeboard/slides  (foyer display)
        ],
        'acceptsPost' => false,
        'embeddable'  => true,               // may be framed by route (b)
        'indexable'   => true,               // may be crawled and listed in the sitemap
        'mediaKinds'  => ['poster-image', 'poster-video'],
    ],
];
```

Every key is a promise the **door** enforces, rather than something the handler is trusted to honour:

- `handlers` is a closed map, so the address segment is a key lookup and never a path fragment. `/noticeboard/../../admin/users` normalises to a segment that is not a key, and gets a 404. Same discipline `ReportRegistry` already uses for report columns, for the same reason.
- `acceptsPost => false` makes the door answer 405 to a POST **before loading the handler**. A handler cannot opt itself in.
- `embeddable => false` forces `frame-ancestors 'none'` whatever the site has configured.
- `indexable => false` forces `X-Robots-Tag: noindex` and drops the surface from the sitemap.

### How a public address is registered — it is not

**There are no `tblRoutes` rows for public addresses, deliberately.** That is the same lesson as the ApiRouter trap: two routing tables for one door is how you get unreachable handlers and orphan rows. The map is compiled per request:

```php
AppRegistry::publicSurfaces(): array   // ['noticeboard' => [...], 'events' => [...]]
```

It filters `all()` to entries carrying a `public` key, keys them by `public.surface`, and **hard-fails on a duplicate surface name, or on a surface name that collides with a real file or directory in `web/public_html/`**. That second assertion is the web-root shadowing trap — two outages already — turned into a startup check instead of a thing to remember.

Two audit-check changes go with it: `tools/audit-checks/check_webroot_shadowing.py` gains a second pass for the public door (it currently hard-codes `WEBROOT = REPO_ROOT / "web" / "public_html"` at line 77, which after the rename would silently start checking the wrong directory and report zero findings — indistinguishable from success), and a new `check_public_surfaces.py` asserts every declared handler file exists, which is the route-target check's equivalent for this door.

### How the door resolves one

```
/noticeboard/slides
  → segments ['noticeboard','slides']
  → surfaces['noticeboard']                              miss → 404
  → site resolution (§5)                                 miss → 404
  → settingForSite('public.enabled')          !== 'true' → 404
  → settingForSite('public.noticeboard.enabled') !== 'true' → 404
  → settingForSite('noticeboard.enabled')     !== 'true' → 404
  → handlers['slides']                                   miss → 404
  → realpath() must sit under PORTAL_APPS/noticeboard/public/   else 404 + log
  → Headers::emitPublic($surface)
  → require, handing it exactly four things
```

That last line matters. The two-variables trap — five dead Export CSV buttons because pages used `$db` — applies here too, so the public door's contract is written down and is deliberately **four** variables: `$mysqli`, `$SETTINGS`, `$publicSiteId`, `$surface`. A public handler must never call `Site::id()`.

### How a future app joins without touching shared code

Add a `public` key to its own `web/_core/apps/{slug}.php`; add `web/_apps/{slug}/public/*.php`; add two settings seeds in one migration. No shared file changes. That is the test this design has to pass, and it passes.

### Worked example A — Noticeboard

The survey's §D.6 shows three things are missing. All three go in migration 193, in both the migration and `web/_sql/full_schema.sql`:

- `tblNoticeboardPosters.isPublic TINYINT(1) NOT NULL DEFAULT 0` — **default 0**, so publishing the board publishes nothing until somebody chooses each poster. Without this, turning the board on republishes exactly the fault migration 189 was written to close on `calendar/widget.php`.
- `publishFrom DATETIME NULL`, `publishUntil DATETIME NULL` — the spec's auto-expiry, enforced in SQL. The React bundle's `isPast()` helper is a display-time comparison and is not a control.
- `tblNoticeboardUploads.isPublic`, maintained from the poster's flag. `web/_apps/noticeboard/media.php` looks a file up by `storedName` alone, with no site and no poster check. Without this column, a public media address would serve a private poster's bytes to anyone who could name the file. Guessing a 32-hex name is infeasible; the point is that the check should not rest on that.

`web/_apps/noticeboard/public/board.php` then runs one query, and it is the whole security model:

```sql
SELECT … FROM tblNoticeboardPosters
WHERE siteID = ?                                    -- from §5, never Site::id()
  AND isPublic = 1 AND isDeleted = 0
  AND (publishFrom  IS NULL OR publishFrom  <= NOW())
  AND (publishUntil IS NULL OR publishUntil >= NOW())
```

It does **not** reuse `web/_apps/noticeboard/api/list.php`. That handler is gated by `ApiAuth::requireRead` and returns every non-deleted poster for the site. Giving it a public mode would make one function carry two security postures, and the public one would be the easy one to get wrong. A separate sixty-line handler with its own query is cheaper to review than a flag on a shared one.

**The React bundle.** `web/public_html/assets/noticeboard/noticeboard.noeval.js` is generated, marked do-not-hand-edit, and reads `window.NoticeboardHost` with `credentials: 'same-origin'`. The public board ships a **different** host object: `load()` returns data PHP has already inlined into the page — no fetch at all, which removes CORS from route (a) entirely — `isAdmin: false`, and `save`/`upload`/`qrUrl` absent. *Assumption I am carrying and have not tested:* the bundle tolerates `isAdmin: false` with the write functions missing. If it does not, the public board gets a plain server-rendered page and the bundle stays admin-only — which is a better outcome for a foyer display anyway, because it drops a 144 KB React dependency from a page that mostly scrolls.

### Worked example B — Calendar, where only some entries may be shown

This is the harder case and it is why a declaration carries a data contract, not just a file name.

```php
'public' => [
    'surface'  => 'events',
    'handlers' => ['' => 'list.php', 'event' => 'event.php', 'ics' => 'feed.php'],
    'acceptsPost' => false,
    'embeddable'  => true,
    'indexable'   => true,

    // 📋 The ONLY rows this surface may ever show. Written in the registry
    //    rather than in the handler, so the rule is reviewed in one place
    //    and a future handler cannot quietly widen it.
    'rowFilter'   => 'isPublic = 1 AND status = "published" AND isDeleted = 0',

    // 📋 The ONLY columns it may emit. tblEvents carries things a public page
    //    must never publish — after #456 it holds locationGeoLat/Lng and
    //    locationW3W, so a SELECT * here would publish a venue's exact
    //    three-metre what3words square.
    'columnAllow' => ['eventID','eventSlug','title','summary','startDateTime',
                      'endDateTime','locationName','isAllDay','categoryID'],
],
```

`columnAllow` does real work. The door provides one helper, `PublicQuery::select($surface, $extraWhere, $params)`, which builds the column list by strict key lookup against the registry and force-injects `siteID = ?` **first, outside any parentheses the handler adds** — the same discipline `ReportBuilder::compile()` already uses, so that no filter can `OR` its way past tenancy.

`tblEvents.isPublic` already exists, so the calendar needs **no schema change at all** — only a declaration and three small handlers. That is the general case working: the app already knew which rows were public; what it lacked was a door.

Two calendar notes. `/e/{slug}` currently lives in `Router::handleSpecialRoutes` on the admin door and is the only dynamic address the sitemap emits. Once the public door exists, it should be **removed from the admin door** and re-offered on the public one, because a public landing page on the admin origin always inherits the admin origin's headers and is always one settings change from being wrong. And on D1: my design does not depend on the `Site::name()` fix, because the public door's own template never calls it — but the sitemap's `/e/{slug}` entries do, so if D1 has not landed when this ships, the sitemap must point at the public address from day one.

---

## 4. The permission model

Two powers, genuinely different in kind, so stored differently.

### Power 1 — "a public surface may exist at all". Root only.

Settings rows, changed only from a new page at `/admin/public` gated on `App::isRootAdmin()` and nothing else:

- `public.enabled` — master switch for the whole public door. Seeded `'false'`, held **globally** (`siteID IS NULL`).
- `public.{slug}.enabled` — per surface, per site. Seeded `'false'`.

Both must be `'true'`. The master switch exists so a customer who has not configured a public site has one thing to leave off, and so that switching the public site off in a hurry is one row rather than forty-seven.

Why a setting and not a role: this is a property of the installation, not of a person, and it has to be readable on a request with **no user at all** — which a role cannot be.

### Power 2 — "may tune what is shown". Delegable, per app, to named administrators.

A new table. Migration 193, and `full_schema.sql` alongside it.

```sql
CREATE TABLE IF NOT EXISTS `tblPublicSurfaceGrants` (
    `grantID`     INT NOT NULL AUTO_INCREMENT,
    `siteID`      INT NOT NULL,
    `appSlug`     VARCHAR(50) NOT NULL
                  COMMENT 'AppRegistry slug. Validated against the registry on write, never trusted from the request.',
    `userID`      INT NOT NULL,
    `grantedByID` INT NOT NULL
                  COMMENT 'Who delegated this, so a grant traces back to the root admin who made it.',
    `grantedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`grantID`),
    UNIQUE KEY `uq_grant` (`siteID`, `appSlug`, `userID`),
    KEY `idx_grant_user` (`userID`),
    CONSTRAINT `fk_psg_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_psg_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Why a per-app grant table rather than a role or a setting:**

- **A role cannot express this.** `tblRoles` has two columns, and `App::hasRole()` joins `tblUserRoles` with no site column at all. A `public_editor` role would make somebody a public editor for every app on every site — which is precisely the "all administrators" the owner ruled out.
- **The grant is three-dimensional** — site, app, person — and only a table holds three dimensions cleanly. A settings key would have to be a comma-joined list of user IDs, which is the shape that makes cross-tenant mistakes easy and audit impossible.
- **It must be revocable in one row when somebody leaves.** One `DELETE … WHERE userID = ?` step in `web/_apps/offboarding/do.php`, one `GdprEraser::catalogue()` entry. That is the same lockstep every other membership-shaped thing in this codebase already has.

**The check — one function, the only place this is decided:**

```php
AppRegistry::canTunePublic(string $slug): bool
```

True when the user is signed in, **and** `public.enabled` and `public.{slug}.enabled` are both `'true'` for the current site (nobody tunes a surface that is off — there is nothing to tune), **and** either `App::isRootAdmin()`, or (`App::isSiteAdmin()` **and** a matching grant row exists for this site and slug).

Note both halves of that last branch. A grant alone is not enough — the person must still be a site administrator. So removing somebody's admin status revokes their public-tuning ability immediately, without anybody remembering to clear grants.

**What a delegated administrator cannot do,** stated plainly because it is the point of splitting the powers: they can change what an already-published surface shows — which posters, which date window. They cannot enable a surface, cannot enable an app, cannot add an embedder origin (that is the list of websites permitted to frame us, so it is root-only), and cannot change the indexing settings. Those four stay with the root admin.

Everything off by default: no grants exist until a root admin creates one, both settings seed `'false'`, and `isPublic` on a poster seeds `0`.

---

## 5. Identifying the site on a public request

Today, as the survey proves, **every unrecognised sessionless request silently becomes site 1** in all four detection branches — no error, no log. For an intranet that is a harmless default. For a public door on a multi-tenant install it is a cross-tenant leak that leaves no trace. So the public door does not use `Site::preDetect()` at all.

**The rule: the public door resolves exactly one site, explicitly, and refuses the request if it cannot.** Never a fallback. This follows the one pattern in the repo that already gets it right — `service-plans/public.php` and `forms/public.php` take the `siteID` off the row the token resolved to.

Three sources, in order, first match wins, **no fallback at the end**:

**1. Exact host match against a new table.** Not `tblSites.hostPattern`, and the break is deliberate: that column is misnamed (it is exact equality, not a pattern), has **no unique key** so two sites can claim one host with `LIMIT 1` picking arbitrarily, does not strip the port, and is already load-bearing for admin-side detection. Overloading it would mean one column with two meanings, one of them facing the internet.

```sql
CREATE TABLE IF NOT EXISTS `tblPublicHosts` (
    `publicHostID` INT NOT NULL AUTO_INCREMENT,
    `siteID`       INT NOT NULL,
    `hostName`     VARCHAR(255) NOT NULL
                   COMMENT 'Exact lowercase host, no port, no scheme. e.g. noticeboard.example.org',
    `isActive`     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`publicHostID`),
    UNIQUE KEY `uq_public_host` (`hostName`),   -- one host, one site. The database enforces it, not LIMIT 1.
    CONSTRAINT `fk_ph_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

Lowercase `HTTP_HOST`, strip from `:` onward, match exactly with `isActive = 1`. Root-admin-only to edit.

**2. A public site token in the address.** For routes (b) and (c), where the `Host:` header is the portal's own and says nothing about whose content is wanted. A new `tblSites.publicToken CHAR(32)` — lowercase hex, unique, rotatable, NULL until a root admin generates one — appears as a path segment:

```
/s/{32-hex}/noticeboard
```

The token resolves to a row, and the row's own `siteID` is used for everything after. Exactly the `/os/{token}` and `/f/{token}` precedent. Lowercase hex is not cosmetic: `Router::extractPath()` lowercases the whole path, so a mixed-case token would be a bug that only shows up on the second install.

**The token is not a secret and must not be treated as one** — it sits in a script tag on a public page. It is a *name*, not a key. What it buys is that it cannot be guessed into naming a different tenant, and that rotating it cuts off an embed without touching anything else.

**3. Single-site installs.** When `multisite.enabled` is not `'true'` there is exactly one site and the answer is 1, taken directly. This is not a fallback — it is a complete determination, and it is what almost every install will use. I state it separately so the "no fallback" rule stays true in the multi-tenant case, where it is the one that matters.

If none of the three resolves: **404, logged once as a platform warning.** Not site 1, not a redirect, not an error page naming any tenant.

**How each route names its site:**

- **(a) own subdomain** — source 1. `noticeboard.example.org` is a row in `tblPublicHosts`. Nothing appears in the address.
- **(b) embedded** — source 2. The pasted snippet carries `data-site="{token}"`, and every request goes to `https://public.example.org/s/{token}/…`.
- **(c) path on the main domain** — source 2, because whatever gets the request from `example.org/noticeboard` to the portal cannot be relied on to preserve a useful `Host:`. The token is in the address, so it survives any number of hops.

**One extra refusal worth building in:** if source 1 and source 2 both answer and they **disagree**, refuse with a 404 rather than picking one. A request arriving at tenant A's public host carrying tenant B's token is either a misconfiguration or an attempt, and there is no reading of it that should serve content.

---

## 6. The three delivery routes, end to end

### (a) Own subdomain → `web/public_html/`

The straightforward one, and the only one needing nothing from the customer's own website.

**Customer does by hand, once:** add `public.example.org` in the DreamHost panel pointed at a new directory under the same user, and enable the free Let's Encrypt certificate. We cannot do this — it is panel work on their account.

**We do:** set `SFTP_PUBLIC_PATH_LIVE` (§8). A root admin adds the host to `tblPublicHosts`, sets `public.enabled`, and turns on the surfaces.

**Headers:** CORP `same-site` (both doors sit under one registrable domain, so same-site is enough and stricter than cross-origin); no `X-Frame-Options`; CSP with `frame-ancestors 'self'`; no `X-Robots-Tag` when the surface is indexable.

**Assets** — the non-obvious part (N4). A stylesheet is a `no-cors` request, so the public origin cannot load `https://portal.example.org/assets/css/portal.css` while the admin door sends CORP `same-origin`. And every `Asset::` constant is root-relative, which on the public origin means the public web root. So `web/public_html/assets/` is its own directory with its own small set: a public stylesheet, the Plus Jakarta Sans woff2 files, and any per-surface bundle. **Not** a copy of the admin set — the public site has no Bootstrap modal, no Font Awesome kit, no `portal.js`. Duplicating two font files is the right trade against a cross-origin font request CORP would block anyway.

### (b) Embedded in a page on the customer's own website

Two mechanisms; the customer picks by what their platform allows. Both are pure copy-and-paste HTML, which is the only thing that works on Wix, Squarespace, WordPress.com and a hand-built site alike.

**(b1) The script embed — the default, and the pattern already proven here.**

```html
<div id="webms-noticeboard"></div>
<script src="https://public.example.org/widget/board.js"
        data-site="a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
        data-surface="noticeboard" defer></script>
```

`/widget/countdown.js` works today for exactly one reason: it is a real file Apache serves without PHP running, so it never carries CORP. `board.js` follows it — a static file in `web/public_html/widget/`, no PHP, no CORP, no CORS needed, because a classic `<script src>` runs regardless.

It then fetches `https://public.example.org/s/{token}/noticeboard/data`. That is `fetch()`, which is **cors** mode, which CORP does not block (survey §0.3). The public door answers with `Access-Control-Allow-Origin` set from the per-site embedder allowlist, plus `Vary: Origin`, and no credentials.

One honest limit for the install instructions: the host page's own CSP may block a third-party script, and there is nothing we can do about that. Better said up front than discovered.

**(b2) The iframe embed — for a site whose CSP blocks third-party scripts, and for a foyer display.**

```html
<iframe src="https://public.example.org/s/{token}/noticeboard?embed=1"
        style="width:100%;height:900px;border:0" loading="lazy"
        title="Noticeboard"></iframe>
```

An iframe is a navigation, so CORP does not apply. What decides it is `frame-ancestors` — and the portal has **no such directive anywhere today**, which is why `X-Frame-Options: ALLOWALL` was reached for and why it does nothing. The public door adds one, built from a per-site `public.embedOrigins`: comma-separated bare hostnames, validated one at a time and capped, **copying `CloudflareStream::ORIGIN_RE` and `MAX_ORIGINS`** (`web/_core/CloudflareStream.php:67-71`) rather than inventing a second format. Empty list → `frame-ancestors 'self'`, so nothing is framable until somebody is named. Root-admin-only. And the public door never sends `X-Frame-Options` at all, because sending both means the stricter wins and XFO cannot name a third party.

**Posters and looping video on an external page — solving D2.**

Media is the one thing neither embed can route around, because `<img>` and `<video>` are `no-cors` by definition. Three options:

1. *Relax CORP on `noticeboard/media.php`.* One line, and it works — but the bytes are still served by PHP, so every foyer-display refresh is a database round trip and a `readfile()` on a host that kills long requests. Worse, `media.php` goes through `Router::dispatch`, so when the Noticeboard app is switched off an `<img>` silently receives a **full HTML 403 page with portal chrome** (survey §A.5). A broken image, and nothing anywhere explaining why.
2. *Serve media from the public door's PHP.* Same costs, minus the app-disabled trap.
3. **Publish the bytes as real static files into the public web root and let Apache serve them. Chosen.**

When a poster is marked public, a new `PublicMedia::publish()` copies the stored upload from `web/_uploads/noticeboard/` to `web/public_html/media/noticeboard/{siteKey}/{storedName}`; unmarking it deletes the copy. Then:

- Apache serves it. PHP never runs, so there is no CORP header, so `<img>` and `<video>` work from any origin. The countdown-script mechanism, applied to media.
- No database round trip and no FastCGI process per image. A foyer display looping a 20 MB video becomes a static range request, which is what Apache is for.
- **The file's existence is the publication state.** There is no flag to get wrong, and no path that could forget a check, because the bytes of a private poster are simply not there.
- A `.htaccess` in `media/` sets a long immutable cache, `Cross-Origin-Resource-Policy: cross-origin`, `Access-Control-Allow-Origin: *`, and a handler guard so nothing in that directory can ever execute. The upload MIME allowlist (finfo sniff, seven types) already stands; this is defence in depth.
- **`web/public_html/media/` is excluded from the deploy mirror**, exactly as `_uploads/` is — or the next deploy's `--delete` wipes every published poster. This is the single most important line in the deployment change, and I repeat it in §8.

The cost, plainly: published media is stored twice. For a poster wall that is megabytes, and disk is the cheap resource on shared hosting. If a customer ever has a genuinely large library, option 1 stays available per surface.

**What the customer installs by hand for (b):** paste one snippet into one page. That is all, deliberately — the survey's §E and the hosting facts establish we cannot assume their main domain is on this account, on Apache, or editable by us. A root admin must also add their hostname to `public.embedOrigins` before the iframe form works.

### (c) A path on the customer's main domain

Be honest first: **there is no mechanism that makes this work for every customer.** DreamHost offers `mod_proxy` only on Managed VPS and Dedicated plans, and Apache forbids `ProxyPass` in `.htaccess` under any configuration. So `example.org/noticeboard` cannot be made to *serve* portal content at the web-server level on shared hosting. Three things that do work, in the order I would offer them:

**(c1) A redirect. Works everywhere, one line.**

```apache
RewriteRule ^noticeboard/?$ https://public.example.org/noticeboard [R=302,L]
```

or the equivalent in the dashboard of Wix, Squarespace or WordPress — all three have one. The address bar changes, which is the honest cost. Offer this first because it always works.

**(c2) A page on their site that embeds ours. The recommendation for most customers.** They create a real page at `example.org/noticeboard` on their own site and paste the route (b) snippet into it. The address stays on their domain, the content is ours, and it needs nothing but the ability to make a page. Routes (b) and (c) turn out to be the same thing seen from two angles.

**(c3) A one-line PHP include, where and only where the setup allows it.** If — and all three must hold — the main domain is on the same DreamHost account under the same user, runs PHP, and is editable, then `example.org/noticeboard/index.php` can be:

```php
<?php
declare(strict_types=1);
define('PORTAL_DOOR', 'public');
define('PORTAL_WEBROOT', '/home/USER/portal.example.org/public_html');
define('PORTAL_PUBLIC_SITE_TOKEN', 'a1b2…');  // names the site: Host: is now example.org
require '/home/USER/portal.example.org/public_html/index.php';
```

This is the one same-filesystem mechanism the survey **proves** works on this account, because `web/public_html/index.php` already reaches `_core/bootstrap.php` by absolute path on every single request. It needs no `mod_proxy`, no symlinks, no `AllowOverride Options`, and no HTTP hop. It is a fourth front door, so it must declare `PORTAL_DOOR` — which the bootstrap refusal in §2 enforces rather than hopes for.

DreamHost's one-user-per-domain policy pushes against this, and a domain owned by a different user cannot read the portal's files at all. So (c3) is an **optional faster path**, offered per customer after three questions, never the design.

Assets need one accommodation on (c3): root-relative `/assets/...` resolves to the *main domain's* root. Make the public door's asset base configurable (`public.assetBase`, empty = root-relative), and set CORP `cross-origin` on the public door's static assets in `.htaccess`. That costs nothing on route (a) and makes (c3) work.

---

## 7. SEO and sharing for route (a)

**What a crawler sees.** `public.example.org/noticeboard` returns server-rendered HTML — a real `<h1>`, real text per poster, `<time datetime>` on dates, and JSON-LD `Event` objects on event surfaces. Not a React mount point with an empty div. This is why route (a) is server-rendered rather than being the iframe embed pointed at itself: a crawler that gets an empty div indexes nothing, and one that gets an iframe indexes its `src` at best. The React bundle, where used, enhances a page that is already complete.

**Overcoming D3 without exposing the admin portal.** The fix is to give the two doors separate, independent policy, and to take the admin door's off settings entirely:

- **Admin door** — `X-Robots-Tag: noindex, nofollow, noai, noimageai` on every response, **unconditionally**. Today it is conditional on `site.allowIndexing`, which means the one settings row that makes the public site indexable would also make the management portal indexable. That is exactly the accident the brief is worried about, and the way to make it impossible is to delete the condition. `web/admin_html/robots.php` becomes a fixed `User-agent: * / Disallow: /` with no settings reads. The dangerous configuration ceases to exist rather than being defended.
- **Public door** — emits `X-Robots-Tag` only to say *no*: when the surface declares `indexable => false`, or when `public.allowIndexing` is not `'true'`. Otherwise it emits nothing, and absence is what permits indexing.

**New keys, not the old ones.** `site.allowIndexing` and `site.allowAiIndexing` are retired from the header path and replaced by `public.allowIndexing` / `public.allowAiIndexing`, seeded `'false'`, root-admin-only. Renaming rather than reusing is deliberate: an installation upgrading from today may already have `site.allowIndexing = 'true'` set for some other reason, and inheriting it would publish a site the moment the public door appeared.

**robots.txt — one per door, doing different jobs.**

- `public.example.org/robots.txt` — the existing generator (`web/public_html/robots.php`) moves to the public door and reads the new keys. Its `ALWAYS_DISALLOWED` list shrinks a lot, because on this origin there is no `/admin/`, no `/account/`, no `/api/` and no `/cron/` to disallow — they do not exist here. What stays is `/media/` (raw bytes, no page value) and `/s/` (token-addressed duplicates of pages that also have a clean address). It emits `Sitemap: https://public.example.org/sitemap.xml`.
- `portal.example.org/robots.txt` — fixed `Disallow: /`. No settings, and no AI-crawler list needed, because everything is already disallowed.

**Sitemap.** `sitemap.php` moves to the public door and is built from the surface registry rather than a hard-coded static list: for each enabled, `indexable` surface on the resolved site, ask the surface for its own entries. This also disposes of the D1 dependency — the sitemap stops emitting admin-origin addresses, so the `Site::name()` fatal on `/e/{slug}` stops being an SEO problem the moment this ships, whether or not the separate fix has landed.

**Sharing.** Open Graph and Twitter card tags on public pages, with the image being the published static file from `public_html/media/`. That matters: a social scraper fetching an OG image is a `no-cors` request, so it would be blocked by CORP if the image came from PHP. The same choice in §6 that makes route (b) work is what makes link previews work.

**One easy thing to miss:** send `<link rel="canonical">` on token-addressed pages pointing at the clean address, so `/s/{token}/noticeboard` and `/noticeboard` are not indexed as two pages with identical content.

---

## 8. The deployment change

### Secret names — validating the owner's proposal, with one change

The proposal is right in shape: retire the three old secrets, name the new ones so a forgotten update fails loudly. I am changing one thing and adding one.

**Change: do not require the two web roots to share a parent, and do not derive the shared path with `dirname()`.** Today the workflow does `REMOTE_SHARED="$(dirname "$REMOTE_PUBLIC")"`. With two web roots there are two dirnames, and requiring them to be equal breaks the layout most customers will actually have — on DreamHost each domain gets its own directory under the home directory, so `/home/USER/public.example.org/public_html` and `/home/USER/portal.example.org/admin_html` do **not** share a parent. Verifying `dirname(admin) === dirname(public)` would fail on the normal arrangement and would rule out route (c) entirely.

Deriving with `dirname()` also hides mistakes: a typo in a web-root secret quietly relocates the shared code somewhere plausible-looking. A separate secret is either right or empty, and empty fails loudly.

**Nine secrets in a regular three-by-three grid**, so a missing one is an obvious hole:

| Secret | Value, exactly as the owner would type it |
| --- | --- |
| `SFTP_ADMIN_PATH_LIVE` | `/home/USER/portal.example.org/admin_html` |
| `SFTP_ADMIN_PATH_BETA` | `/home/USER/portal.example.org/admin_html_beta` |
| `SFTP_ADMIN_PATH_ALPHA` | `/home/USER/portal.example.org/admin_html_dev` |
| `SFTP_PUBLIC_PATH_LIVE` | `/home/USER/public.example.org/public_html` |
| `SFTP_PUBLIC_PATH_BETA` | `/home/USER/public.example.org/public_html_beta` |
| `SFTP_PUBLIC_PATH_ALPHA` | `/home/USER/public.example.org/public_html_dev` |
| `SFTP_SHARED_PATH_LIVE` | `/home/USER/portal.example.org` |
| `SFTP_SHARED_PATH_BETA` | `/home/USER/portal.example.org` |
| `SFTP_SHARED_PATH_DEV` | `/home/USER/portal.example.org` |

The three shared paths are separate rather than one, even though today all three would hold the same value. Two reasons: the naming stays perfectly regular, and it makes the "all three channels share one copy of `_core`, last push wins" coupling — which DEV_NOTES already flags as a hazard — a visible decision rather than one the code makes silently. A customer who wants isolated channels sets three different values and gets it.

Unchanged: `SFTP_HOST`, `SFTP_USER`, `SFTP_PORT`, `SFTP_KEY` / `SFTP_PASSWORD`, and the `SFTP_ENABLED` variable.

**Deleted, not left in place:** `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH`.

### Sequencing so a half-finished deployment cannot publish the portal

The hazard, stated precisely. `web/public_html/` **keeps its path and changes its meaning.** New repo with the old workflow mirrors the new tiny public site into `<base>/public_html/` with `--delete`, destroying the live management portal — an outage. Old repo with a new workflow mirrors the admin portal into the *public* directory — and that is the one that publishes management pages to the open internet.

Five guards, so both fail loudly:

**Guard 1 — a marker file, checked before any byte moves.** Each web root carries a one-line file naming which door it is: `web/admin_html/.door` containing `admin`, `web/public_html/.door` containing `public`. Before mirroring, the workflow downloads the `.door` already at the remote path and refuses if it disagrees with what it is about to upload. A remote path with no `.door` is treated as the pre-rename layout and also refuses, pointing at the one-time first-run dispatch. This catches the dangerous direction — pushing the admin door at a directory that is currently the public one — **before** the upload starts. (Both `.htaccess` files block `(^|/)\.` with `[F]`, so `.door` is 403 over the web while SFTP reads it fine.)

**Guard 2 — the workflow refuses the retired secrets.** If `SFTP_LIVE_PATH`, `SFTP_BETA_PATH` or `SFTP_DEV_PATH` is non-empty, fail with a message naming the nine replacements. A half-finished secret migration stops the pipeline rather than half-running.

**Guard 3 — cheap string checks that catch a copy-paste.** Refuse if a public path equals an admin path, if any `SFTP_PUBLIC_PATH_*` contains `admin_html`, or if any `SFTP_ADMIN_PATH_*` contains `public_html`.

**Guard 4 — the `PORTAL_DOOR` refusal in bootstrap (§2).** The runtime backstop. Any stale front controller surviving on the server from before the rename reaches bootstrap without declaring a door and gets a 500. It cannot run with a guessed identity.

**Guard 5 — `PORTAL_ENV` defaults to `prod`.** Part of the same rename (N2), and the difference between a misclassified live portal printing stack traces and one that does not.

**The order, and what is verified after each step:**

1. **In the repo, on a branch.** `git mv web/public_html web/admin_html` (no case change, so no two-step needed). Create the new `web/public_html/`. Fix `Router.php:95` and `:257`, the `PORTAL_ENV` block, and the audit checks that hard-code `web/public_html` — `check_webroot_shadowing.py` (line 77) and `check_route_targets.py` above all, since those two would otherwise silently start reading the wrong directory. Add `^admin_html/`, `^admin_html_dev/`, `^admin_html_beta/` and `^public_html/media/` to `WEB_ROOT_EXCLUDES` (N5, and the media exclusion from §6). *Verify:* all fourteen audit checks green, `php -l` clean, and **prove `check_webroot_shadowing.py` still fires** by temporarily creating a colliding folder — a check reading the wrong directory reports zero findings, which is indistinguishable from success.
2. **Create the remote directories by hand, once.** Customer adds the public subdomain in the DreamHost panel. Nothing deployed yet.
3. **Set all nine secrets, delete the three old ones.** Guard 2 means step 4 cannot start until this is finished.
4. **One-time first-run dispatch, alpha channel only.** Uploads both doors and writes the two `.door` files, skipping guard 1. *Verify on alpha before going further:* `curl -sI` the admin door and confirm `X-Robots-Tag: noindex` and a working sign-in; `curl -sI` the public door and confirm **no** `X-Frame-Options`, CORP not `same-origin`, and **no** `Set-Cookie`; request `/admin` on the public host and confirm a bare 404; request an unpublished poster's media file on the public host and confirm 404.
5. **Beta, then main.** Ordinary dispatches, with guard 1 now armed.
6. **Only then** does a root admin set `public.enabled` and turn on the first surface.

Steps 1-5 deploy a public door that answers 404 to everything, because every gate is off by default. That separation is the point: a mistake in the structural change cannot publish anything, because publishing is a separate, later, deliberate act.

**Rollback** is re-pointing three secrets and one dispatch. The admin door is untouched by any of this except its directory name, and nothing in the rename touches `_core`, `_apps`, or the database except additively.

---

## What I did not verify, and assumptions I am carrying

- **No live server was touched.** Everything about actual response headers is reasoned from the include order plus PHP's documented `header()` / `headers_sent()` semantics and the Fetch specification, exactly as the survey flagged. `curl -sI` against the real host would settle it, and should be step 4's verification.
- **The noticeboard React bundle's tolerance of a read-only host object is untested.** If it hard-requires `save`/`upload`, the public board falls back to plain server-rendered HTML — a better outcome for a foyer display in any case.
- **DreamHost panel specifics** for adding a subdomain and issuing a certificate are from their documentation, not from doing it.
- **D1 (the `Site::name()` fatal) is assumed fixed in parallel.** The only place my design touches it is the sitemap, noted in §3.
- **Migration number 193** is correct as of now: `web/_sql/` currently ends at `192_removable_creator_links.sql`. It carries `tblPublicSurfaceGrants`, `tblPublicHosts`, `tblSites.publicToken`, the three noticeboard columns, `tblNoticeboardUploads.isPublic`, and all settings seeds — in **both** the numbered migration and `web/_sql/full_schema.sql`, with the `information_schema` + `PREPARE`/`EXECUTE` guard so it replays as a no-op.