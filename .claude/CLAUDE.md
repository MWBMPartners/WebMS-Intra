# WebMS Intra - Claude Code Instructions

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

> ⚠️ **Database versions are in flux — read this before writing any SQL.**
> **MySQL 8.0 reached the end of its extended support in April 2026.**
> Everything here still assumes it, and the automated database test only covers
> `mysql:8.0.36`.
>
> Two things to be precise about, because loose wording has already caused
> confusion. First, **the version is now read, judged and acted on** — this
> changed on 10 September 2026 (#489). `Portal\Core\DbServer`
> (`web/_core/DbServer.php`) is the ONE place that decides whether a version is
> supported; the admin dashboard, the health page, the Server Information page
> and the installation wizard all call it, so they agree. The installer refuses
> to install onto a database too old to run the schema, and warns without
> blocking on anything else. **Do not write a second version check** — change
> the constants at the top of that file instead. Second, **MariaDB is not
> covered by any automated test here**, so its compatibility is unverified —
> which is not the same as saying it does not work.
>
> Moving to MySQL 9.7 / MariaDB 12.3 (with 8.4 / 11.4 as fallbacks) is tracked
> as **#475**. Until that lands, keep writing SQL to the MySQL 8.0 ∩ MariaDB
> convention below. It remains the sensible choice while the target is unsettled
> — but following a convention is not the same as proving compatibility.

- **Version:** 1.4.0 (on `main`; bump in `web/_core/version.php` — single source of truth)
- **Brand layer:** runtime product brand picked at install (#296, PR #297). Presets: `WebMS Intra` (generic, default), `ChurchMS` (church), `SchoolMS`/`CharityMS`/`CommunityMS`/`BusinessMS` (functional starter SVG kits shipped #306 — logo.svg wordmark is system-font pending a designer pass, icons are full-quality). See `web/_core/brand-defaults.php` + `Site::productName()`. PWA manifest is a brand-aware PHP controller (`manifest.php`, now with brand-aware `shortcuts[]`, #141); the OpenAPI spec is likewise served brand-aware via `public_html/openapi.php` + `_core/api-spec.json` (#307).
- **Licence:** All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
- **Repo:** github.com/MWBMPartners/WebMS-Intra
- **Server:** portal.millrdsdacambridge.uk
- **Full brief:** `.claude/ProjectBrief_Chat.claude`
- **Living feature inventory:** [FEATURES.md](../FEATURES.md) (always check this first)
- **Chronological history:** [CHANGELOG.md](../CHANGELOG.md)
- **Dev-facing technical notes:** [DEV_NOTES.md](../DEV_NOTES.md)

## Counts, and when they were last checked

These numbers go stale quickly and have been wrong before. Verified against the
code on **10 September 2026**:

| What | Count | How to re-check |
| --- | --- | --- |
| App folders | 54 | `ls -d web/_apps/*/ | wc -l` |
| Installable apps (the on/off list) | 47 | `ls web/_core/apps/*.php | wc -l` |
| Framework classes | 78 | `ls web/_core/*.php | wc -l` |
| Numbered database migrations | 188, numbered 000-189 | `ls web/_sql/[0-9][0-9][0-9]_*.sql | wc -l` |
| Database tables | 209 | `grep -c 'CREATE TABLE IF NOT EXISTS' web/_sql/full_schema.sql` |
| PHP files | 788 | `find web -name '*.php' | wc -l` |
| In-app help guides | 19 | `ls web/_apps/help/*.php | wc -l` |
| Live addresses the portal answers on | 544 | `python3 tools/audit-checks/check_route_targets.py` |
| Settings seeded | 566 | `python3 tools/audit-checks/check_settings_keys.py` |

**If a number here disagrees with the code, the code is right.** Numbers 168 and
169 are missing from the migration sequence: they were never used, and nothing
depends on the numbering being unbroken.

## Directory Layout

```
repo root/          <- NOT deployed (docs, CI/CD only)
web/                <- ALL deployable files (synced to server via SFTP)
  _core/            <- Framework classes (Portal\Core namespace, 78 classes)
  _apps/            <- App controllers — outside the webroot (#159). Every
                       app's PHP handlers live here; Router resolves
                       tblRoutes.targetFile against PORTAL_APPS = _apps/.
  _vendor/simplejwt/<- Vendored RS256 JWT verifier
  _sql/             <- Numbered SQL migrations (000-187 + full_schema.sql).
                     186 files, not 188: 168 and 169 were never used.
  _lang/            <- I18n translation files (en.php, cy.php, …)
  _install/         <- Standalone 6-step installation wizard (bootstrap-free)
  public_html/      <- Web root: ONLY the front controller + static assets +
                       the 3 entry-point PHP files (index.php, api-docs/,
                       error.php) Apache can serve directly. Every other
                       PHP file lives in _apps/. Branch-based deploy mirrors
                       this dir to the server's public_html/ (main),
                       public_html_beta/ (beta) or public_html_dev/ (alpha).
    index.php, error.php, .htaccess, manifest.php, openapi.php,
    robots.txt, sw.js, assets/, api-docs/, offline/
  private_html/, public_html_landing/, public_html_redir/  <- non-app server dirs
  _auth_keys/       <- Credentials + encryption key (gitignored, server-managed)
  _uploads/         <- User file uploads (gitignored, server-managed)
  _backups/         <- Server snapshots (gitignored, server-managed)
  _libraries/       <- Server-managed libs incl. dompdf 3.1.5
```

## Apps (shipped on `main`)

`web/_apps/` holds 54 top-level entries; `web/_core/apps/*.php` is the
AppRegistry — the single source of truth for **installable marketplace
apps** (toggleable per-site at `/admin/apps`), 47 of them. The table below
is every user-facing app (see note below the table for dirs that are
infrastructure rather than apps).

| Slug | Route | What it does |
| --- | --- | --- |
| admin | `/admin` | Users, roles, settings, sites, errors, activity, audit, migrations, integrations, workflows, reports (fixed dashboards, #93, **+ whitelist-driven custom report builder at `/admin/reports/builder`, #156**), **captcha config** |
| ai-assist | `/admin/ai-assist` | LLM-assisted drafting for announcements, prayer requests, newsletter (Anthropic / OpenAI / local ollama) |
| announcements | `/announcements` | Per-site text announcements, pinned + scheduled posts |
| approvals | `/approvals` | Generic inbox for the Workflow Execution Engine (`Portal\Core\Workflow`) — awaiting-decision queue, approve/reject/comment, decision history (#443) |
| assets | `/assets` | Physical & digital asset register — ownership/co-ownership, lending & borrowing, maintenance logs, GS1/RFID identifiers, software licence seats, printable QR labels, public lost-and-found page |
| attendance | `/attendance` | Sessions, headcount by service type, reports, CSV |
| auth | `/auth/*` | Local + MS365 + Google + WebAuthn + 2FA TOTP; password policy + strength meter; self-service "my account" pages live at `/account/*` |
| calendar | `/calendar` | Events, series, RSVP, exports; seven view modes shipped via #137/#138 |
| care | `/care` | Confidential pastoral / wellbeing register with visit log; role-restricted, encrypted notes |
| cop-live-chat | `/admin/live/chat` | Moderate viewer chat on livestream events (#313); viewer-facing chat widget served alongside the `/live` embed |
| dashboard | `/dashboard` | Portal home with app cards and pinned announcements |
| directory | `/directory` | Searchable member directory with opt-in per-field visibility, incl. an independent `visibilityCoords` tier for an optional home map pin (#456 Chunk B) |
| discipleship | `/discipleship` | Ordered formation pathways with per-member progress tracking, auto-completion from attendance/RSVPs, pastor roster (#303) |
| documents | `/documents` | File library with categories |
| expenses | `/expenses` | Submit, approve, treasury, withdraw, multi-approver, PDF, CSV |
| forms | `/forms` | Generic form designer — admin-built fields, internal + optional public (`/f/{token}`) fill, response review/CSV export; `Portal\Core\FormEngine` is the reusable injection-safety boundary (#153) |
| giving | `/giving` | Contributions log, Gift Aid capture, HMRC export, year-end statements (self-service + treasurer bulk batch generate/email, #440); two-person offering count, pledge campaigns, bank reconciliation (#299) |
| help | `/help/*` | In-app guides (getting-started, expenses, calendar, prayer-requests, admin, faq, …) |
| invites | `/invites` | Single-use invite links so new members self-register with role pre-assigned |
| kids | `/kids/*` | Children's ministry check-in / check-out with 6-digit safeguarding badge codes (#298) |
| leadership | `/leadership` | Roles + assignments + history + CSV |
| livestream | `/live` | Embed YouTube / Vimeo / Twitch / Facebook livestreams with countdown + session analytics; Web Push "we're live now" + service-reminder browser notifications (#322) |
| milestones | `/milestones` | Birthdays, anniversaries, joining dates with daily digest for designated roles |
| newsletter | `/newsletter` | Compose, schedule, send branded HTML newsletters (internal sender; MailerMatt adapter slot reserved) |
| noticeboard | `/noticeboard` | Visual poster wall (Canva embeds, image/video/text posters, weekday recurrence, QR share) (#360, #363) |
| offboarding | `/offboarding` | One-click revocation when a volunteer/staff member leaves: sessions, credentials, roles, leadership |
| payments | `/payments` | Pluggable payment processor (Stripe + PayPal live; GoCardless adapter reserved); `/giving/give` + Projects "Pay now" checkout UI; feeds Giving + Projects |
| photos | `/photos` | Photo gallery, moderation queue, tiered role-based visibility, EXIF-aware serving |
| praise | `/praise` | Share gratitude / answered prayers / celebrations — counterpart to Prayer Requests |
| prayer-requests | `/prayer-requests` | Logged-in + anonymous public submission, moderation, lifecycle, prayer-chain assignment (#311) |
| projects | `/projects` | Project fundraising pages with pledge thermometer, updates feed, public sharing |
| reading-plans | `/reading-plans` | Daily reading plans with streak tracking and per-day check-off |
| recordings | `/recordings` | Searchable audio/video library with podcast RSS feed, HTML5 playback |
| resources | `/resources` | Bookable resources (rooms, equipment, vehicles) with conflict detection + approval workflow |
| rota | `/rota` | Recurring duty / shift assignments with swap requests and reminders |
| salvation | `/decision-card` | Public decision-card / salvation tracker form + admin follow-up workflow (#316) |
| service-plans | `/service-plans` | Programme run-sheet builder (preacher, scripture, hymns, AV, welcome team); operator → confidence-monitor messaging (#300); local hymnal index + default-off remote lookup + congregation-facing public `/os/{token}` view (gap #128, migration 178) |
| settings | `/settings` | Generic dot-notation settings editor |
| site | `/site` | Multi-site switcher handler |
| small-groups | `/small-groups` | Groups/classes register — leaders, member assignment, join requests, meeting rolls tied to attendance service types (#150) |
| sms | `/admin/sms` | SMS notifications for critical alerts via Twilio / MessageBird / AWS SNS |
| tasks | `/tasks` | Reminders / task list |
| transcription | `/admin/transcription` | Auto-transcribe Recordings via Whisper / AssemblyAI / local whisper.cpp; full-text search |
| translation | `/admin/translation` | ⚠️ **NOT REACHABLE (#485).** The engine, the admin configuration page (provider, API keys, monthly spend cap) and the member opt-in at `/account/translation` all exist. But nothing a user can reach ever calls it: the only caller of `Translation::translate()` is `_apps/api/translate.php`, at an address ApiRouter cannot resolve. **Separate from interface translation** (`I18n` / `t()`), which works normally. Do not describe content translation as working. |
| venues | `/venues` | Tenant-side venue-hire register — schedule of agreed bookings of a rented building, configurable statuses/usage types, recurring generator, XLSX import, calendar overlay + "is it booked?" warnings, hire agreements + renewal reminders, payable invoice/payment ledger (#429); persisted per-event venue/room links + room-aware coverage verdicts (#436) |
| visitors | `/visitors` | First-time visitor capture with follow-up cadence + kanban workflow |
| worship | `/worship/*` | Live presentation layer for Service Plans — operator console, public projector display, song library + CCLI usage log (#308) |
| zoom | `/admin/integrations/zoom` | OAuth Zoom integration: create meetings from calendar events, auto-link recordings via webhook |
| api | `/api/*`, `/api/v1/*` | JSON REST API — read + write across events/announcements/attendance/prayer-requests/documents/expenses/leadership/tasks/noticeboard/users; dual-mode auth (session or bearer API key, #323 Phase 2) |
| offline | `/offline` | PWA offline fallback |

**Infrastructure, not apps:** several `web/_apps/` dirs back the apps above or
the framework rather than being standalone apps — `account/` (self-service
"my account" pages spanning several apps above: GDPR export/erasure, payment
methods, recurring giving, notifications, safeguarding, sms/translation
prefs), `cron/` (token-gated scheduled-job endpoints: event reminders, feed
import, discipleship sweep — no UI), `events/api/` + `users/api/` (REST
handlers backing the `api` app's events/users resources), `live/` +
`livechat/` (the public `/live` viewer page + its chat API — implementation
of `livestream`/`cop-live-chat` above), `privacy/` (GDPR consent banner +
policy pages, public, tied to Auth), `widget/` (public embeddable
countdown/calendar widgets for external sites), `qr.php` (shared QR-code
generator utility used by Noticeboard/Visitors/etc), `geo/` (session-authed
AJAX proxies — `w3w-suggest`/`lookup` — backing the shared location
partials' "Look up coordinates" button + W3W autosuggest, #456).

Calendar/Events/Preaching Plan is ONE app ("Events") — `/calendar` covers viewing/listing/subscribing; the manage UI handles preaching-plan/worship event types and series.

## Plain English (STANDING RULE — applies to everything written)

**Write the way you would explain something to a capable colleague who does not
work on this system.** This is not a style preference; the customer asked for it
explicitly on 2026-09-07 because jargon "can sometimes be confusing even for
some technically proficient users/developers".

It applies to **everything**, without exception:

- replies in chat
- code comments and file header comments
- commit messages
- pull request titles and descriptions
- GitHub issue titles, descriptions and closing comments
- every `.md` document in this repository
- the in-app help pages under `web/_apps/help/`
- anything shown to an end user: labels, buttons, error messages, tooltips

**What it means in practice**

- Use ordinary words. Say "a number that only ever counts upward and never
  resets", not "a monotonically increasing counter". Say "the portal checks who
  you are before letting you in", not "the middleware performs principal
  authentication".
- When a technical term is genuinely needed — a file name, a function name, a
  standard such as WCAG or OpenAPI — use it, then say in ordinary words what it
  means and why it matters.
- **Using more words is fine, and better, if it makes the meaning clearer.**
  Never compress an explanation into jargon to save space.
- Prefer short sentences. Break a long one into two.
- Explain the "why", not just the "what". "This runs after the save, because
  before the save the row does not have an identity number yet" is far more
  useful than "ordering constraint".
- Avoid unexplained abbreviations and internal shorthand on first use.
- Avoid filler that sounds impressive and says nothing.

**This does not lower the standard of the work.** The code, the analysis and the
precision stay exactly as rigorous. Only the way it is explained changes.

**When reporting on work done**, be direct about what is finished, what is not,
what was not checked, and what went wrong. Say "I could not test this because
there is no database on this machine" rather than implying it was verified.

## Codex review (STANDING RULE — every change, before it is committed)

**Every piece of work done here must also be reviewed by a different system —
Codex — before it counts as finished.** The customer asked for this on
2026-09-10 as a standing task, not a one-off.

The point is a genuinely independent second opinion. Claude plans and builds;
Codex reviews. If Codex built something, Claude reviews it instead. Two
different systems rarely make the same mistake in the same place, so this
catches things one reviewer alone would wave through. It supports the project's
stated aim of getting things right first time.

**How to run it.** Codex is installed and signed in on the development machine:

```bash
codex exec --skip-git-repo-check "<what you want reviewed>"
```

- `codex exec` is the non-interactive mode: it prints its answer and exits.
- It runs read-only by default, which is exactly what a review needs.
- Give it the real change — a diff, or the paths of the files — and ask for
  specific things: is it correct, is it safe, would anything here fail on
  MySQL 8.0, would anything here break on shared hosting with no command line.

**When to run it.** After the work is written and the mechanical checks pass
(`php -l`, the thirteen scripts in `tools/audit-checks/`, and the end-to-end
migration harness where the database is involved), but **before committing**.

**How to treat the result.** As a second opinion, not a verdict. Check each
point against the code before acting on it. Codex will sometimes be wrong;
saying so plainly, with the evidence, is the right response. Record in the
commit message that Codex reviewed the change and what came of it.

**Why this sits alongside the other checks, not instead of them.** The eleven
audit scripts and the migration harness catch mechanical faults — a mistyped
column, SQL that only works on MariaDB, a route pointing at a missing file.
They cannot judge whether the design is right or whether a change has an
unintended consequence. That is what the second reviewer is for.

## Deep analysis: sequential, one run at a time (STANDING RULE)

Deep analysis and deep planning use **sequential agents, never parallel** — and
this applies **on Opus too**, not just on Fable. Confirmed by the owner on
10 September 2026.

Two parts, and the second is the one that gets missed:

1. Within a run, each agent waits for the previous one and builds on what it
   found. A second opinion formed without seeing the first is worth much less.
2. **Never have two analysis runs going at once.** Ordering the agents correctly
   inside each run and then starting two runs together defeats the purpose. That
   exact mistake was made and corrected on 10 September 2026.

Stopping a run to keep the order is cheap: relaunch with `resumeFromRunId` and
the script path, and every agent that already finished returns its cached answer
immediately.

**Always try Fable first**, on every deep run, even if it failed last time. Fall
back to Opus only when Fable is unavailable, and put the fallback in the script
rather than deciding by hand. Implementation stays on Sonnet or Haiku — or Opus
when the work is genuinely complex.

## Never put ".php" in a web address (STANDING RULE, all projects)

Links, form targets, redirects and background requests use the **clean address**
the portal registers, never the file that answers it.

    /expenses/submit/save          yes
    /expenses/submit/save.php      no

Two reasons. It tells a stranger what the site is built with, which narrows down
for them which weaknesses are worth trying — a free advantage, given away for
nothing. And **in this portal such an address does not work at all**:
`.htaccess` answers 404 for every address ending in `.php`, except the three
pages that genuinely live in the web root (`/index.php`, `/error.php`,
`/api-docs/index.php`).

That second point is not theoretical. On 11 September 2026 this rule immediately
uncovered **three live Expenses forms** — submit, approve and treasury — every
one posting to an address ending in `.php`. Filling in a claim and pressing Save
would have produced "page not found". The correct addresses were already
registered and working; the forms simply named the wrong ones. It also found the
database upgrade page redirecting to a 404 whenever a form token expired,
stranding an administrator half way through an upgrade.

Nothing else caught it, because every file existed and every address was
registered. The mistake was in what the pages pointed AT.

`tools/audit-checks/check_no_php_in_urls.py` now checks this on every pull
request.

## Comment everything, in every language (STANDING RULE, all projects)

Detailed comments in HTML, PHP, CSS, JavaScript, XML, JSON and SQL. Specifically:

- **Explain the WHY, not the what.** "This runs after the save, because before
  the save the row has no identity number yet" beats "increments the counter".
- **Record what was tried and rejected.** The most valuable comment is often
  "this used to do X, which was wrong because Y" — it stops the next person
  reintroducing the fault, or tidying away something load-bearing.
- **Say what code CANNOT do** where that is not obvious. An overstated guarantee
  is worse than none.
- **JSON has no comments.** Never put `//` in a `.json` file — it stops being
  valid JSON. Put the explanation in the schema instead, where JSON Schema gives
  you `description` on every property and `$comment` for maintainer notes.

## A schema for every JSON and XML format (STANDING RULE, all projects)

Where this project produces or consumes JSON or XML, the schema describing it
lives beside it: a JSON Schema file (`*.schema.json`) or an XSD. Give every
property a `description` — the schema is the documentation as well as the
validator, which is exactly why the descriptions matter. Wire the validation
into a check so it actually runs; a schema nothing executes is a document, not a
check.

## Code Style (MUST FOLLOW)

- `declare(strict_types=1)` in every PHP file
- **Full IF notation:** `if ($x === true)` not `if ($x)`
- **Platform-neutral paths:** `DIRECTORY_SEPARATOR`, `dirname()`, PHP constants
- **Emoji-annotated comments** for major code sections
- **No `<table>` tags** for data display -- use `portal-data-list` component
- **MySQLi prepared statements only** -- never interpolate user input
- `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` for all output
- Detailed inline comments with reference links where applicable
- File header comments must include: file path, description, package, author, copyright (All Rights Reserved), version

## Key Constants (defined in _core/bootstrap.php)

- `PORTAL_ROOT` -- web/ on server
- `PORTAL_CORE` -- web/_core/
- `PORTAL_APPS` -- web/_apps/ (since #159)
- `PORTAL_VENDOR` -- web/_vendor/
- `PORTAL_SQL` -- web/_sql/
- `PORTAL_ENV` -- 'dev', 'beta', or 'prod' (auto-detected from DOCUMENT_ROOT)

## ApiRouter routing trap (apply on every new api/* endpoint)

- **`api/*` paths IGNORE tblRoutes.** `Router::handleSpecialRoutes` intercepts them and hands off to `ApiRouter::dispatch`, which splits the path into `appName` + `action` and loads `_apps/{appName}/api/{action}.php`. Handler at any other path is unreachable.
- **Every endpoint needs `api.{appName}.{action}.enabled = 'true'`** seeded in `tblSettings` or ApiRouter returns 403.
- **Don't register `api/...` routes in `tblRoutes`** — either the handler is at the convention path (settings flag does the gating) or it's dead code.
- **Adjacent gotcha**: the `ApiResponse` class exposes `::success()`, NOT `::ok()`. `::setJsonHeaders()` is `private`. Grep `_core/ApiResponse.php` for method names before calling.
- **v1 facade (#323 Phase 2)**: the `/api/v1/{resource}` facade maps REST verbs onto the same `{app}/{action}` handler files + `api.{app}.{action}.enabled` flags (`ApiRouter::dispatchV1`) — no separate gating vocabulary, nothing registered in `tblRoutes` for it either.

## Web-root shadowing trap (check whenever you add an address or a file)

**A real file or folder in `web/public_html/` silently beats any seeded address
of the same name.** `web/public_html/.htaccess` contains
`RewriteCond %{REQUEST_FILENAME} !-d` (and the same for `-f`), which means the
web server answers for anything that really exists on disk and never hands the
request to the portal. That rule is correct and necessary — it is what serves
stylesheets and images directly.

**It fails silently and invisibly.** No portal code runs, so nothing is written
to the error log. The visitor gets a bare folder listing or a flat refusal, and
there is nothing anywhere to explain it.

This has already bitten twice, found 10 September 2026:

- A folder `web/public_html/admin/` made the whole **Admin area** unreachable
  (#483). Fixed by moving its three files to `web/_apps/`, which needed no
  address change because `Router::dispatch` looks under `PORTAL_APPS` first and
  only falls back to the web root.
- A folder `web/public_html/widget/` hid a **public, sign-in-free** address
  (#478). That one was the dangerous shape: the address was unreachable, so
  nobody had noticed that the page behind it had no access check AND returned
  internal events. Removing the folder as a "tidy-up" would have published it.
  The address was deleted instead (migration 189); the folder holds
  `countdown.js`, which other people's websites embed, so it cannot move.

**Two collisions remain and both are deliberate:** `assets` has its own
`RewriteRule ^assets/?$ index.php` ahead of the folder rule, and `api-docs` is
meant to be served directly by the web server.

**There is now an automatic check for this** — `check_webroot_shadowing.py`,
wired into the pull-request checks. It compares every seeded address against
what really exists in the web root and reports any that the portal will never
see. Run it directly with:

```bash
python3 tools/audit-checks/check_webroot_shadowing.py
```

Note it compares the WHOLE address, because that is what the web server does.
`/admin` is hidden by a folder called `admin`, but `/admin/activity` is not —
there is no such folder, so that request gets through perfectly well even while
`/admin` is broken. The first draft compared only the first segment and reported
a dozen addresses that were entirely fine; a check that cries wolf gets switched
off, and then it catches nothing.

## Two variables, and only two (apply on every page under _apps/)

`web/_core/Router.php` does `global $mysqli, $SETTINGS;` immediately before it
loads a page. **Those two are the only things a page inherits.** Anything else
it reaches for is simply not there, and PHP does not complain until the moment
it is used.

Five Export CSV buttons were dead because their pages used `$db` (#482) — the
members list, the audit trail, the attendance register, the leadership roster
and the expenses queue. Each crashed the instant somebody pressed the button:
no file, no message on screen. Use `$mysqli`.

A helper that receives the connection as a **function parameter** is a different
thing and is fine — `web/_apps/announcements/_workflow-gate.php` does that
correctly.

## Name things by the right identifier (the shape behind several bugs)

Three separate faults on 10 September 2026 were the same mistake: something was
named by the wrong kind of identifier, and nothing caught it.

- `Maintenance.php`'s allow list is compared against the **address** a visitor
  typed. It contained `auth/login`, which is a **file path**. The sign-in page's
  address is `login`. So administrators were locked out during every upgrade,
  and the holding page's own sign-in link pointed at the same non-address.
- A search for `tblActivityLog` found nothing, because the table is
  `tblActivityLogs`. "Nothing found" reads exactly like "nothing to fix".

**When checking whether a name is right, check it against the thing that
actually uses it** — seeded addresses against `full_schema.sql`, table names
against the schema — not against the folder layout, which merely looks similar.

## Every database change goes in the install script too (STANDING RULE)

**Any change to the shape of the database must ALSO appear in the fresh-install
script.** The owner confirmed this on 11 September 2026 as a standing rule for
all future work, not a one-off.

It has to reach BOTH of the two ways a customer's database can come into being,
and they must agree:

1. **THE INSTALLER** — a brand-new database, built from nothing.
   `web/_sql/full_schema.sql`, run by `web/_install/index.php`.
2. **THE UPGRADE** — a database that already exists and is being brought up to
   date. A **numbered migration** in `web/_sql/`, replayed by
   `web/_core/Migrator.php` through `web/_install/upgrade.php` (which is what
   the Admin → Upgrade button reaches, via a one-line proxy at
   `web/_apps/admin/upgrade.php`).

A change that reaches only one of them is the dangerous case, and it is quiet.
Put it only in a migration and a brand-new install is missing it. Put it only in
the fresh-install script and every existing customer never gets it. Either way
two installations of the same version behave differently, and nobody finds out
until somebody hits it — by which time the difference is old and hard to trace.

Migrations must be safe to run twice, because the installer replays
`full_schema.sql` and then EVERY numbered migration, ignoring which ones have
already run.

`tools/audit-checks/check_schema_seed_parity.py` compares the two and fails when
they disagree, so this is enforced rather than remembered. Run it before
committing anything that touches `web/_sql/`.

**The storage engine is InnoDB and should stay that way.** All 209 tables use
it. It is what makes transactions and links between tables possible — and it is
what makes an all-or-nothing restore possible at all (#472). The alternative,
MyISAM, supports neither. Moving away from InnoDB would break the backup restore
and the safety of every multi-step database change in the portal.

## SQL dialect trap (apply on every migration)

- **Production runs MySQL 8** (DreamHost shared hosting offers no other engine and no version choice). **Which** MySQL 8 is not confirmed — 8.0's support ended April 2026, 8.4 LTS runs to 2029; see #475. Either way it is MySQL, so MariaDB-only `IF [NOT] EXISTS` on `ADD`/`DROP COLUMN`, `ADD`/`CREATE`/`DROP INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN` is rejected with **ERROR 1064** — `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` are standard MySQL and stay fine.
- **Use the `information_schema` + `PREPARE`/`EXECUTE` guard idiom** instead (see DEV_NOTES.md → "Portable DDL convention (MySQL 8.0 ∩ MariaDB)" for the full templates). House examples already shipped this way: migrations **037**, **112**, **138**.
- **Migrations must replay as no-ops** on an up-to-date schema — the installer replays every numbered migration after `full_schema.sql`, ignoring `tblMigrations`.
- **CI**: `tools/audit-checks/check_mariadb_only_ddl.py` + the `e2e-migrations` harness enforce this.

## Recent ships (chronological)

- **`claude/backlog156-reports`** (branched off `alpha`) — issue #156:
  Reports Builder, a whitelist-driven custom report builder at
  `/admin/reports/builder/*` alongside the pre-existing #93 fixed
  dashboards. `Portal\Core\ReportRegistry` (pure static data — no DB, no
  superglobal reads) is the ENTIRE whitelist: six v1 sources (users,
  events, attendance, expenses, giving, tasks), each a registry-owned
  table/alias/tenant-scope-expression/curated-JOINs entry with a closed
  per-column expr/type/gates/aggs whitelist; closed keyed sets for
  operators, aggregations, and date-bucket transforms.
  `ReportRegistry::assertSelfConsistent()` hard-fails if a future edit
  ever references care/kids/safeguarding/prayer-requests/auth/settings/
  API-key tables — those domains are structurally absent, not merely
  gated. `Portal\Core\ReportBuilder::compile()` is the ONE place report
  SQL is assembled: every identifier reaches the SQL string only via
  strict key lookup against the registry; every value is bound via
  `bind_param()` with a lockstep types string + an explicit
  `strlen()===count()` assert; `siteID = ?` is force-injected first,
  outside the user-filter parentheses, so no `OR` can bypass tenancy.
  Column gates (a role, `@siteAdmin`, or `@rootAdmin`) apply identically
  in SELECT and WHERE, closing the filter-as-oracle leak; financial
  totals need Treasurer/Site Admin, Giving donor identity needs Treasurer
  strictly. A saved definition is re-validated against the registry on
  EVERY run — a hand-edited DB row fails closed. Builder UI: drag-
  reorderable column chips (`Asset::sortableJs()`), repeatable filter rows
  (one AND/OR toggle), optional group-by + aggregates, an AJAX preview
  endpoint (session-authed, outside `api/*` — the `geo/` precedent), CSV
  export, and a bar/line chart on grouped results via a new SRI-pinned
  `Asset::chartJs()` (Chart.js 4.4.4, hash independently re-derived from
  the npm registry tarball — jsdelivr itself was policy-denied from this
  build's sandbox proxy). Migration 184: `tblReportDefinitions`
  (DEVIATION from #156's literal `tblReports` — documented in the
  migration header), 3 settings seeds (`reports.enabled` seeded ON so the
  #93 dashboards survive the upgrade unchanged), 8 route seeds. New
  AppRegistry entry `reports` folds BOTH the dashboards and the builder
  under one toggle. GDPR lockstep in the same PR (GdprEraser catalogue
  entry + data-export.php block). A committed, dependency-free red-team
  self-test (`tools/report-builder-selftest.php`) exercises the real
  classes — registry self-consistency, a benign compile with tenant scope
  provably first, and 15 hostile/malformed definitions each throwing
  `InvalidArgumentException` before any SQL string exists. All 11 audit
  checks green, `php -l` clean on every touched file, `node --check`
  clean on the new JS.
- **`claude/backlog153-forms`** (branched off `alpha`) — issue #153: new
  Forms Builder app at `/forms` (`web/_apps/forms/`, 16 pages/handlers) +
  `Portal\Core\FormEngine` — the single injection-safety boundary for a
  12-type field registry (`FormEngine::FIELD_TYPES` — a PHP whitelist, NOT a
  SQL ENUM, so adding a type is code-only, never a migration), a whitelist
  config sanitiser (`sanitiseConfig()` — `configJson` is DATA, re-sanitised
  on every read AND write), an escaped-everything renderer
  (`f_{fieldID}` server-integer field names; choice fields submit
  bounds-checked integer indexes, never raw option text), per-type
  server-side validators, and immutable `answersJson` snapshot persistence
  (`{fieldKey:{label,type,value}}`, survives later field edits/deletes).
  Admins build/publish forms at `/forms/edit` + `/forms/manage`; members
  fill published internal/both forms at `/forms/fill`; an OPTIONAL public
  link `/f/{token}` is a Router special route (cloned from service-plans'
  `/os/{token}`), default OFF (`forms.allowPublic='false'`), six-gate
  uniform-404 scoped to the FORM's own siteID throughout (never ambient
  `Site::id()` — no active-site context on a public route). Public POST:
  honeypot → CSRF → `Captcha::verify()` → `RateLimiter` (fake-success on
  `isBlocked()`) → 5/15min per-IP bucket (`prayer-requests/anonymous-save
  .php` + `assets/found-save.php` precedent). Responses reviewed/exported
  admin-only at `/forms/responses` (CSV via `FormEngine::csvRows()` +
  `CsvExporter`). GDPR lockstep in the SAME PR: `GdprEraser::catalogue()`
  hard-deletes a member's responses by `submitterID`;
  `auth/account/data-export.php` gained a matching `formResponses` block —
  a public (anonymous) response carries no `submitterID` and sits outside
  both by design (salvation decision-card precedent), documented in
  `/help/forms` + DEV_NOTES. Three residual defaults applied per the build
  spec (owner sign-off deferred, minimal-safe choice made): the
  display-only `heading` field type is included; NO per-role fill
  restriction in v1 (any signed-in site member may fill a published
  internal/both form); public-response retention = **keep indefinitely**
  in v1, with a `forms.responseRetentionDays` settings stub (seeded `'0'`
  = forever) for a future auto-purge cron. Migration 182: 3 new tables
  (`tblForms`/`tblFormFields`/`tblFormResponses`), 3 settings seeds, 15
  route seeds (13 protected + 2 public, no `api/*` rows — ApiRouter trap).
  All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/backlog150-groups`** (branched off `alpha`) — issue #150: new
  Small Groups app (`web/_apps/small-groups/`, slug `small-groups`) —
  groups/classes register for Sabbath School classes, home groups, Bible
  studies. Roster with leader/co-leader/member roles + optional
  self-service join requests (pending → approve/decline, last-active-leader
  guard on remove/demote/leave); per-meeting roll
  (`tblSmallGroupMeetingAttendance`, presence-row model mirroring
  `tblEventAttendance`) with an ADDITIVE headcount push into the existing
  Attendance app via a group's linked `tblAttendanceServiceTypes` row —
  several groups can share one service type/session, each contributing its
  own labelled `tblAttendanceCounts` row matched by `(sessionID,
  groupLabel)`; zero changes to Attendance's own schema/code. Meeting
  location reuses the #456 shared partials (`portal_location_input`/
  `portal_location_display`) with canonical column names, byte-identical
  to migration 180's `tblVenues` shape, plus a new `locationVisibility`
  gate (leaders/members/site, default `members` — no public tier, since
  groups often meet in a member's home). New `Portal\Core\SmallGroups`
  class is the tenant-safety choke-point AND the stable contract #304
  (group messaging) and #321 (watch-party rooms) are expected to consume —
  `groupID` scope anchor, `status='active'` membership predicate,
  `isLeader()`/`canManage()` gates; the denormalised `siteID` on
  member/meeting rows is written only by `SmallGroups::upsertMembership()`
  after confirming an ACTIVE `tblUserSites` row for the group's own site
  (leadership `assign.php:87-93` join precedent), making cross-site
  membership structurally impossible. New `groups_coordinator` role. GDPR
  lockstep in the same PR: 6 `GdprEraser::catalogue()` entries, 4
  `data-export.php` blocks, 1 `offboarding/do.php` step ending a leaver's
  memberships. **v1 is adults-only** — membership rows are portal users
  only; zero named-child rows anywhere (the Kids app's `tblKidProfiles`
  remains the sole place child identity lives, verified by grep). Migration
  183 (181 = alpha head at spec time, 182 reserved by #153 Forms in
  flight): 4 new tables, 7 settings seeds (`small-groups.enabled` defaults
  `'0'`, opt-in — the pre-existing `'1'`-vs-`'true'` nav/dashboard
  enable-flag quirk is inherited verbatim, not fixed here), 1 role seed
  (`WHERE NOT EXISTS` idiom), 13 route seeds (12 app + 1 help,
  `isProtected=0`), zero ALTERs to any existing table. Also found + fixed
  along the way: `check_sql_columns.py`'s SELECT-column regex false-
  positives on any `FROM tblSmallGroup*` clause carrying a short alias
  immediately after the table name — the bare substring "Group" inside
  every one of the four new table names lets the regex's own greedy-`\w+`
  backtracking mis-match "Group…" as a false `GROUP BY` terminator,
  truncating the captured table name to `tblSmall` and reporting a bogus
  unknown-table finding; worked around by never aliasing the PRIMARY
  `FROM tblSmallGroup*` table (using full-name column qualification
  instead) while still freely aliasing any table introduced via `JOIN`
  (invisible to that checker's FROM-anchored regex) — documented inline at
  each call site since the same shape will recur for any future table
  whose name embeds a bare SQL keyword. New help page (`/help/small-groups`)
  + help-index card. All 13 audit checks green, `php -l` clean on every
  touched file, zero raw `<table>` (portal-data-list throughout).
- **`claude/backlog-pwa-brand`** (branched off `alpha`) — two small,
  low-risk backlog finishers, one PR: **#141 residual** (the push half
  was already fully shipped as #322 — only install-prompt/manifest/iOS-meta
  remained) — self-hosted `assets/js/pwa-install.js` captures
  `beforeinstallprompt`, suppresses the mini-infobar
  (`event.preventDefault()`), and reveals a dismissible bottom-sheet
  banner (`#portal-install-prompt` in footer.php, same visual pattern as
  the existing cookie-consent banner) with a brand-aware "Install
  {product name}" heading; dismissal remembered 30 days in localStorage,
  a real install remembered permanently via `appinstalled`.
  `manifest.php` gains a brand-aware `shortcuts[]` (Dashboard / Calendar
  / Giving / Prayer Requests) gated through `AppRegistry::isEnabled()`,
  failing CLOSED (shortcut dropped) on any registry exception.
  `header.php` gains the missing `apple-mobile-web-app-title`
  (brand-aware — was absent entirely) + the standard-track
  `mobile-web-app-capable` twin of the pre-existing apple- tag. **#306**
  — functional starter SVG brand kits (`icon.svg`/`icon-192.svg`/
  `icon-512.svg`/`logo.svg`) for the four presets that previously fell
  back to generic WebMS-Intra assets:
  `assets/images/brandkit/assets/{schoolms,charityms,communityms,businessms}/`,
  each a distinct emblem (mortarboard/heart/interlocking-rings/bar-chart)
  on the same indigo-tile + gradient-token structure as the WebMS/
  ChurchMS kits. `brand-defaults.php`'s four stub presets now point
  `assetFolder` at their new kits. Known design debt (documented in-repo,
  not fixed — no font-outlining tool available): each `logo.svg`'s
  wordmark is set with a system-font stack, not the WebMS/ChurchMS kits'
  outlined vector glyphs — a designer pass is recommended before any of
  the four ship to a real customer; the PWA-install-facing `icon*.svg`
  files need no such caveat. Also fixed two stale DEV_NOTES.md doc-drift
  items found along the way: the "Per-brand assets" section still
  described the pre-brandkit-move `assets/images/brands/` path, and its
  #297 deferred-follow-ups list still showed sub-brand artwork and
  OpenAPI brand-awareness as open when both are now done (#306 here,
  #307 previously). No migration in either half. All 10 audit checks
  green, `php -l` clean on every touched PHP file, all 16 new SVGs
  well-formed XML.
- **`claude/gap322-webpush`** (branched off `alpha`) — issue #322: Web Push
  notifications ("we're live now" + service-reminder channels). Migration
  111 shipped `tblPushSubscriptions` + the four `push.vapid*`/
  `push.contact`/`push.enabled` settings, but the subscribe/unsubscribe
  handlers sat at `_apps/api/push/*` — a path ApiRouter never resolves
  (same routing trap already fixed for worship/livestream in #372/#373) —
  and NO sender existed anywhere. This ships the whole loop:
  `Portal\Core\WebPush` (VAPID ES256 JWT with the mandatory DER→JOSE
  signature conversion, RFC 8291 aes128gcm payload encryption via a fresh
  ephemeral P-256 keypair per message + triple `hash_hkdf()`, RFC 8030
  delivery with TTL/Urgency/Topic); a committed crypto self-test
  (`tools/webpush-selftest.php`, no DB/network, exercises the real private
  methods via reflection) that PASSES; relocated
  `_apps/push/api/{subscribe,unsubscribe}.php` + the two
  `api.push.*.enabled` flags migration 177 seeds; SSRF-guarded endpoint
  validation (https-only, no IP-literal/local host, admin-editable
  host-suffix allowlist) enforced at BOTH subscribe and send time; the
  VAPID private key sodium-encrypted at rest, never redisplayed, never
  sent to the client; client subscribe UI (`assets/js/push-subscribe.js`)
  + new `sw.js` `push`/`notificationclick` handlers; the "we're live now"
  manual admin button (`/admin/livestream` + Host Console) plus a
  default-OFF auto-detect cron (`cron/push-golive.php`, dedupe once per
  channel per day) and a default-OFF anonymous "starting soon" broadcast;
  a Web Push companion to `cron/event-reminders.php`'s 1h window (same
  dedupe claim as the email send); `/admin/integrations/push` config page
  (generate-or-paste keys, TTLs, toggles, per-channel subscription counts,
  test-send); GdprEraser + offboarding coverage of `tblPushSubscriptions`.
  INERT until an admin sets VAPID keys (`WebPush::isConfigured()` gates
  every send path). Migration 177 (renumbered from 175 — #423's UPC-E took
  175, #234's shared-mailbox took 176): 3 additive `tblPushSubscriptions`
  columns (dead-subscription pruning), settings seeds, 4 route seeds, no
  new tables (reuses `tblUserReminderLog` / `tblEventReminderLog` for
  dedupe). All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/gap128-oos`** (branched off `alpha`) — gap #128 residual
  (re-scoped #128 "Order of Service planner with iHymns integration"):
  service-plans (#262/#300) + Worship (#308/#355) already covered
  everything the issue asked for except three genuine gaps, all additive
  on the EXISTING `tblServicePlan`/`tblServicePlanItem`/`tblSongs` tables —
  no third service-plan model, no new app. **R1** local hymnal index —
  new `tblHymnals`/`tblHymnalEntries` (metadata only, never lyrics),
  `Portal\Core\Hymnal::searchLocal()`, admin CRUD + CSV import at
  `/admin/hymns`. **R2** optional remote ("iHymns") lookup, Tier 2,
  default OFF (`hymns.remote.enabled='false'`) — a generic SSRF-hardened
  HTTPS JSON client (`Hymnal::searchRemote()`: https-only, single-host
  allowlist, private/reserved-IP refusal, no-redirect-follow, 3s/5s
  timeouts, ~512 KB body cap, JSON-only, 24h cache in
  `tblHymnLookupCache`), reachable via session-authed
  `service-plans/api/hymn-search.php` (ApiRouter convention path,
  `api.service-plans.hymn-search.enabled` flag, NOT a tblRoutes row).
  **R3** congregation-facing public Order of Service — new
  `tblServicePlan.publicToken`/`isPublicShared`, Router special route
  `/os/{token}` (cloned from `/a/{token}`) → `service-plans/public.php`:
  congregation fields only, `notes` never queried, uniform 404 for
  unknown/unshared/unpublished/disabled tokens, OFF by default at both
  site (`service_plans.public_share.enabled`) and plan level; CSRF'd
  `service-plans/share.php` (enable/disable/rotate) + a `/qr.php` code.
  `print.php` gains `?version=leader|congregation` (default `leader`,
  byte-identical to before). **R4 (glue)** nullable
  `tblServicePlanItem.songID` FK → `tblSongs` — picking a hymn/song
  auto-promotes it into `tblSongs` (check-first upsert) and links it;
  free-text `title` stays the universal fallback. Migration 178 (176/177
  reserved by in-flight webpush/shared-mailbox work); all 11 audit checks
  green, `php -l` clean on every touched file.
- **`claude/gap436-venue-coverage`** (branched off `alpha`) — gap #436:
  additive follow-up to the shipped Venue Bookings app (#429, migration
  170) and the wall-clock fix (#435). `tblEvents` gains two optional
  nullable columns, `venueID`/`roomID` (migration 179, FKs
  `ON DELETE SET NULL`) — the calendar manage form's venue picker upgrades
  from a transient advisory-only check into a **persisted** link, with a
  cascading room `<select>` (disabled, not hidden, when the venue has no
  rooms). `Venues::classifyEventCoverage()` gains an optional trailing
  `?int $roomId = null` — when set, per-day booking rows are filtered to
  `roomID IS NULL OR roomID = $roomId` (a whole-venue booking still covers
  every room) before the untouched wall-clock day-cascade runs, and a new
  `COVERAGE_ROOM_NOT_COVERED` verdict (severity danger) fires when the room
  itself is uncovered but the venue has a confirmed bookable hire for a
  *different* room that day; null `$roomId` (both pre-#436 call sites)
  reproduces today's output bit-for-bit, and an unresolvable room silently
  degrades to venue-level coverage (no existence oracle). New public
  `Venues::getRoom()` accessor. `calendar/manage/save.php` validates and
  persists both links site+venue-scoped (`Venues::getVenue()`/`getRoom()`)
  on create/update — invalid/foreign posts silently NULL, never a
  save-blocking error — and OMITS the columns from the UPDATE entirely
  when the Venues app is disabled/absent/throws, so a toggle can never
  wipe an existing link; an event can only ever link its own site's
  venue/room. `full_schema.sql` folds the two columns + KEY indexes inline
  into `tblEvents`' CREATE but deliberately leaves the two FKs out
  (`tblEvents` is created thousands of lines before `tblVenues`/
  `tblVenueRooms` in that file — see DEV_NOTES.md's new "full_schema.sql
  fold pattern" subsection) — migration 179's guarded `ADD CONSTRAINT`
  blocks add both FKs on replay instead. Also fixed in this PR: the
  event-form's live "is it booked?" JS posted `startDateTime`/
  `endDateTime`/`timezone` while `venues/api/check.php` has always read
  `start`/`end`/`tz` — every live check silently 400'd since #429 shipped;
  canonicalised on `check.php`'s existing contract and fixed the JS to
  match, adding `roomID`. All 10 audit checks green, `php -l` clean on
  every touched file.
- **`claude/gap456-location-chunkB`** (branched off
  `claude/gap456-location-chunkA`) — #456 Chunk B: the PII + GDPR half.
  Migration 181 adds FOUR columns to `tblUsers` ONLY — `latitude`/
  `longitude`/`what3words` (member home coordinates, PRIVATE by default,
  NEVER auto-geocoded — no `geocodedAt`/`geocodeSource` pair) and
  `visibilityCoords` (ENUM, default `'private'`, INDEPENDENT of the
  existing `visibilityAddress`). Capture on the owner surface
  (`directory/me.php`, per the spec — NOT `/account`); `directory/
  save.php` validates the pair (10 → 14 bind_param placeholders).
  `directory/profile.php` gates coords through a SEPARATE
  `$can($u['visibilityCoords'])` check: owner/admin see full precision +
  the exact what3words, any other permitted viewer sees coords coarsened
  to 3dp (~110m, `GeoLocation::coarsenCoords()`) with an "Approximate
  location" badge and the what3words value suppressed entirely (a
  3m-precise W3W square can't be meaningfully coarsened). The pre-existing
  `$can()` "team tier = private" quirk is inherited verbatim, not fixed.
  GDPR lockstep in the SAME PR: `data-export.php` gained a
  `giftAidDeclarations` block (closed a pre-existing gap — Gift Aid
  address PII was never exported); `delete-confirm.php`'s tblUsers
  anonymise UPDATE now also nulls `displayAddress`/`displayPhone` (a
  separate pre-existing miss) plus the three new PII columns;
  `GdprEraser::catalogue()`'s tblUsers `nullCols` extended with
  `latitude`/`longitude`/`what3words`. GiftAid (`giving/gift-aid.php`) and
  Salvation (`salvation/card.php`) reuse the shared `location-input`
  partial in TEXT-ONLY "reduced names map" mode mapped onto their existing
  `address`/`postcode` POST fields — no new columns, no new erasure
  surface. Kids/Care/Visitors hard-excluded, verified by grep (zero
  matches). `UserCreate`/`UserUpdate` API schemas document that member
  coordinates/W3W are never readable or writable via the REST API in any
  mode. All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/gap456-location-chunkA`** (branched off `alpha`) — #456 Chunk A:
  full address + geocoordinates + what3words platform layer (foundation,
  non-PII, interactive map — Chunk B lands the PII/GDPR half in a later
  PR off this branch). Cross-repo data-format CONTRACT with
  ProjectBookIT/ProjectEPass (identical column shapes, canonical
  `location` JSON wire object, what3words canonical form) but fully
  standalone — zero runtime dependency on either repo. New
  `Portal\Core\GeoLocation` (address normalise/format mirroring
  `Venues::saveVenue()`, DECIMAL(10,7) coord validation, W3W
  canonicalisation, map link-outs, `toLocationObject()`/
  `fromLocationObject()` serializer), `Portal\Core\What3Words` (v3 API
  client — key in the QUERY STRING not a header, unlike every other
  Bearer adapter in this codebase; never logged; default OFF via
  `w3w.enabled`, which gates ONLY the API — the `///word.word.word` input
  is always present as a stored-field fallback), `Portal\Core\Geocoder`
  (Google primary → Nominatim/OSM fallback, policy-compliant User-Agent +
  ≤1 rps throttle + `tblGeocodeCache`, `geo.autoGeocode` default OFF,
  every method best-effort/never-throws). Three new shared partials —
  first in the codebase at `web/_core/partials/`:
  `location-display.php`/`location-input.php`/`location-map-assets.php`
  (pinned Leaflet 1.9.4 from cdn.jsdelivr.net with SRI — hashes verified
  by independently re-deriving them from the npm registry tarball, since
  jsdelivr itself was unreachable from the build sandbox; all four
  sha384/sha256 digests matched the build spec exactly). New admin pages
  (`/admin/integrations/{what3words,geocoding}`,
  `/admin/settings/organisation`) + two session-authed AJAX proxies
  (`/geo/w3w-suggest`, `/geo/lookup`) outside `api/*`. Wired into Venues,
  Events (existing `locationGeoLat/locationGeoLng/locationW3W` columns
  now validated + JSON-LD `geo` + interactive map + canonical `location`
  object additively emitted by the events REST API create/update/list/
  detail), Event occurrence overrides (`overrideGeoLat/overrideGeoLng/
  overrideW3W`, hand-entered, NULL = inherit), Resources, Asset Locations.
  Migration 180: five-column location block on
  `tblVenues`/`tblResource`/`tblAssetLocations`, three override columns on
  `tblEventOccurrenceOverrides`, new `tblGeocodeCache`, 14 settings seeds
  (all default OFF/empty), 10 route seeds — upgrade is a full no-op. No
  PII table touched (tblUsers/directory/GiftAid/Salvation are Chunk B).
  All 13 audit checks green, `php -l` clean on every touched file.
- **`claude/gap234-shared-mailbox`** (branched off `alpha`) — gap #234:
  MS365 Graph email via an admin-configured shared mailbox, formalising
  and hardening the app-only `Mailer::sendViaGraph()` path already in
  place rather than adding the issue body's delegated `Mail.Send.Shared`
  auth model (deferred — zero new secrets vs. an entire OAuth refresh
  surface + a dependency on a licensed human account). New
  `mail.ms365.sharedMailbox` (empty = off, today's behaviour unchanged)
  + `Mailer::effectiveSender()` resolver; explicit `message.from` object
  now built in BOTH modes (benign fix — `mail.defaultFromName` finally
  works on MS365, not just Google); 401-retry-once / 429-bounded-retry
  (≤5s `Retry-After` only) / 403-404-Graph-error-code-surfaced / optional
  opt-in `mail.fallbackProvider='google'` (default off, fail loud). New
  `tblEmailLog` (migration 176) logs every send from BOTH providers via
  the new public `Mailer::logSend()` — closes the #230 audit-trail
  dependency too; opportunistic retention prune, no new cron.
  `GdprEraser` gained a bespoke (not `catalogue()`) step scrubbing an
  erased user's address out of the comma-joined `toRecipients` column,
  captured before the catalogue's own `tblUsers` step nulls it. Admin UI:
  `/admin/integrations` MS365 Graph card gained a Shared-Mailbox Sending
  sub-section + CSRF'd save handler (`admin/integrations/ms365-mail-
  save.php`); Send Test Email now calls the real `Mailer::send()` instead
  of a duplicated inline cURL flow, closing the test/production drift
  risk permanently. Fold-in fix: `/admin/integrations/email` was reading
  a dead `email.provider`/`email.from` vocabulary and always reported
  "smtp" — now reports `Mailer::provider()` + effective sender, plus a
  "Recent sends" `portal-data-list`. Shared mailbox is admin-config-only
  (never request-derived); no secret ever logged. Migration 176:
  `tblEmailLog` + 5 non-sensitive settings seeds + 1 route seed, folded
  into `full_schema.sql`. All 13 audit checks green, `php -l` clean.
- **`claude/gap7-workflow-engine`** (branched off `alpha`) — gap #7
  (#443): Workflow Execution Engine + generic `/approvals` inbox.
  Migration 034 shipped four workflow tables + an admin definition CRUD
  but no code anywhere started/advanced/completed/timed-out an instance —
  this ships the engine (`Portal\Core\Workflow`, `web/_core/Workflow.php`).
  Every mutator (`start`/`act`/`cancelForSubject`/`timeoutSweep`) is
  atomic: `begin_transaction` + `SELECT … FOR UPDATE` + `UPDATE …
  WHERE currentStep=? AND status IN (…)` gated on `affected_rows === 1`
  (the `expenses/approve/save.php` / `Payments::markPaymentSucceeded`
  discipline) before recording the action row or applying any subject
  side effect — a losing racer does nothing. Authorisation (role/user/
  group match on the CURRENT step, or a default-on site-admin override)
  lives INSIDE `act()`, never trusted from the HTTP layer; a cross-tenant
  instanceID is indistinguishable from a missing one. New `/approvals`
  app (AppRegistry entry, default-on) with an awaiting-decision queue,
  CSRF'd approve/reject/comment handler, and history timeline. New
  token-gated hourly `cron/workflow-timeouts.php` — escalates an overdue
  step unless it explicitly sets `autoAction=approve|reject`; never
  auto-acts on a bare timeout. Reference consumer wired: Announcements
  publish approval behind default-off per-site
  `workflows.announcements.enabled` — final approval flips
  `tblAnnouncements.isPublished` inside the SAME transaction as the
  approval claim (no double-publish, no approved-but-unpublished ghost);
  a misconfigured gate fails OPEN (publishes directly + logs a platform
  warning) rather than blocking publishing. Admin CRUD completion at
  `/admin/workflows`: per-step delete (FK-aware, refuses with active
  instances), an `isActive` toggle, an `autoAction` selector. The seeded
  `expense_approval` definition (034) stays dormant by design — Expenses
  keeps its own independent multi-approver system. Migration 174: four
  additive `tblWorkflowInstances` columns + one composite index + one
  `tblWorkflowActions` enum value (no new tables), plus the
  `announcement_approver` role/definition/step, 8 settings seeds, 4
  route seeds. All 13 audit checks green, `php -l` clean on every
  touched file.
- **`claude/gap4-bulk-statements`** (this session, branched off `alpha`) —
  gap #4: treasurer-only bulk year-end giving statements at
  `/giving/statements` (#440). Generalised `Portal\Core\Giving::
  renderStatementPdf()` to `(siteId, donorId, from, to, label)` so the
  self-service page (`giving/my-statement.php`) and the new bulk batch
  render through the exact same function — byte-identical output. Three
  upgrades landed on that shared renderer: donor lookup is now site-scoped
  (active membership OR giving history at the site — an `OR` of two
  `EXISTS`, closing a latent cross-tenant render hole while still allowing
  a treasurer to pull a departed donor's historical statement); a
  Gift-Aid-eligible column + summary via correlated `EXISTS` (never a
  JOIN, so overlapping declarations can't double-count, mirroring
  `buildHmrcCsv()`'s own known hazard), deliberately with no projected 25%
  reclaim figure; and the output path is now namespaced by
  `{siteID}/{periodKey}`, fixing a cross-site filename overwrite the old
  flat naming had. New `tblGivingStatementLog` (migration 172) is a
  `UNIQUE(siteID, donorID, periodKey)` dedupe/audit log; generate/email
  both cap at `giving.statements.batchPerRun` (default 25) per request
  (Newsletter-dispatch pattern, re-trigger to continue), with an explicit
  audit-logged "resend" override, ZIP download (`ZipArchive`, never a
  combined PDF, degrades to per-row links), and an optional token-gated
  `cron/giving-statements.php` sweeper (`giving.cron_token`, empty ⇒ 403
  fail-closed). New `givingStatements` notifyPrefs opt-out (default on)
  enforced at both queue and live-send time; `GdprEraser` now also
  unlinks an erased donor's rendered statement PDFs from disk. All 11
  audit checks green, `php -l` clean on every touched file.
- **`claude/gap6-serviceplan-bridge`** (branched off `alpha`) — gap #6
  (#442): additive, non-destructive bridge between the two parallel
  "service plan" data models that never knew about each other — the
  run-sheet builder (`tblServicePlan` SINGULAR, migration 089, #262/#300)
  and the worship presentation engine (`tblServicePlans` PLURAL, migration
  137, #308/#355). New nullable, UNIQUE `tblServicePlans.runSheetPlanID`
  FK (`ON DELETE SET NULL` → `tblServicePlan.planID`) — NULL (unpaired) is
  the state of every pre-existing row, zero data migration. New
  `Portal\Core\ServicePlanLink` resolver is the only code that knows about
  both models; `pair()`/`unpair()` enforce same-site (hard), same-event
  when both sides declare one (hard, either-NULL always proceeds), and
  1:1 (hard — UNIQUE key + errno-1062 race catch, never fatal). A
  NULL-only, one-directional (worship → run-sheet) backfill wakes the
  run-sheet's dormant, write-dead `eventID` at pair time — never the
  reverse, since the worship side's `eventID` is ACL-bearing. New CSRF'd
  `worship/plan/link` POST handler (`worship/plan-link.php`) reuses the
  worship app's admin-or-coordinator write gate verbatim (copied, not
  refactored out of `plan-save.php`); own-row re-pairing overwrites, a
  foreign run-sheet claim is refused with a flash. Read-only counterpart
  panels on both editors (`service-plans/edit.php`, `worship/plan.php`),
  each guarded by `AppRegistry::isEnabled()` + try/catch (venue-overlay
  resilience precedent) so a disabled counterpart app or any resolver
  exception leaves the panel empty rather than breaking the page. No
  field sync in v1 — the song representations are structurally
  incompatible (free-text title vs canonical `songID` FK) — read-only
  visibility only. Migration 173: guarded MySQL-8-safe DDL, one route
  seed, no new settings keys, folded into `full_schema.sql`. All 11 audit
  checks green, `php -l` clean on every touched file.
- **`claude/gap3-reminders`** (branched off `alpha`) — gap #3 (#439):
  new `cron/user-reminders.php` sweeps three reminder fields earlier
  migrations shipped but no code ever consumed —
  `tblTasks.reminderDate`/`reminderSent` (036), `tblRotaSlot.
  reminderSentAt` + `rota.reminder_days_before` (074), and the milestones
  daily digest promised by `milestones.digest_recipients` (076). Token-
  gated (`user_reminders.cron_token`, empty-fails-closed), 15-minute
  cadence, per-site loop with `App::settingForSite()` read INSIDE the
  loop (venue-cron discipline). Dedupe: tasks/rota reuse their existing
  sent-flag columns via an atomic claim UPDATE; milestone-digest uses a
  new generic `tblUserReminderLog` `(refType, refID, dueDate)` table
  (check-first + race-catch), reserved so a future single-shot family
  (e.g. DBS-expiry) can reuse it with zero DDL. Milestone-digest is
  explicit opt-in only — an empty `milestones.digest_recipients` skips
  the site rather than falling back to admins. Two new notification
  preferences (`taskReminders`/`rotaReminders`, default on) on
  `/account/notifications` — the first prefs this codebase actually
  honours when sending. Write-path fixes so dedupe stamps stay correct as
  rows change: `tasks/save.php` re-arms `reminderSent` on a future
  reminder edit, `tasks/complete.php` carries the reminder forward
  (interval-shifted) into a recurring task's spawned next occurrence,
  `rota/swap-respond.php` clears `reminderSentAt` on an accepted swap.
  Also fixed in this PR: `cron/event-reminders.php` selected `u.email`
  from `tblUsers` (real column: `emailAddress`) — under this app's strict
  mysqli reporting, the very first `prepare()` threw, so the event-
  reminder cron 500'd on every single invocation; fixed throughout
  (`u.emailAddress AS email`). Three other `u.email` sites found during
  this work are tracked separately in #438, not touched here. Migration
  171: new `tblUserReminderLog` table + six settings seeds + one route
  seed, zero ALTERs, folded into `full_schema.sql`.
- **`claude/paypal-checkout`** (branched off `alpha`) — gap #1:
  PayPal Orders v2 fully wired into `Portal\Core\Payments` (create checkout,
  capture-on-return + `CHECKOUT.ORDER.APPROVED` webhook backstop for a payer
  who approves and never returns, verified webhooks via PayPal's own
  verify-webhook-signature API, refunds by capture id) plus the previously-
  missing user-facing checkout UI (new `giving/give.php` "Give online" page,
  Projects `my-pledges.php` "Pay now"). The S1 amount/currency integrity
  gate now lives INSIDE `markPaymentSucceeded()` itself (signature gained
  `?int $observedAmountPence, ?string $observedCurrency`) — every PayPal
  success path (return capture, 422 `ORDER_ALREADY_CAPTURED` reconcile,
  verified `PAYMENT.CAPTURE.COMPLETED`) asserts the captured amount+
  currency against the pending row before any Giving/Projects fan-out, and
  a mismatch marks the row `failed` + logs `PaymentIntegrityFail` instead.
  The status transition is now a single atomic
  `UPDATE … WHERE status = "pending"` gated on `affected_rows === 1`,
  closing the return-path-vs-webhook race (and incidentally Stripe's own
  two-event race too — Stripe call sites keep passing null observed values
  unchanged, upgrading them is a follow-up). `checkout.php` (already
  #430-hardened for pledge/giving/else purpose validation) gained the two
  remaining §6.3 pieces: a £10,000 online-giving ceiling
  (`GIVING_MAX_AMOUNT_PENCE`) and server-built descriptions
  (`'Giving — {category}'` / `'Pledge — {project}'`) — the POSTed
  `description` field is removed from the flow entirely. Migration 167
  (seeds only, no DDL): `payments.paypal.{webhookId,mode}`, `clientId`
  flipped to encrypted-at-rest (predicate-guarded — only where still
  empty, since `decrypt_setting()` returns `''` for a plaintext value), and
  the new `giving/give` route.
- **PR #372** (accumulating, draft, `claude/alpha-enhancements` → `alpha`) — a
  discovery-pass fold-in batch on top of #386/#387 (migrations 155-157):
  #373 ApiRouter half — `ApiRouter.php` never got the `global $mysqli,
  $SETTINGS;` import Router.php gained for #373, fatally breaking 6 live-chat
  /livestream handlers; fixed at both `dispatch()` and `dispatchV1()`. #339
  residual — `calendar/manage/save.php`'s create-flow slug-uniqueness probe
  now scopes to `siteID`. Worship live-sync (#308) — `/api/worship/state` +
  `/api/worship/advance` were unreachable (dead legacy `_apps/api/worship-
  *.php` + tblRoutes rows, no `api.worship.*.enabled` flags); relocated to
  the ApiRouter convention path `_apps/worship/api/{state,advance}.php`
  (precedent: migration 144's livestream/ping relocation). AppRegistry
  (#255) — added `_core/apps/{noticeboard,worship,salvation,kids}.php` so
  all 41 apps surface in `/admin/apps`; seeded the 3 missing enable flags
  (worship/salvation/kids) so registering them didn't silently 403 three
  live apps. Dead-route cleanup — removed 19 unreachable `api/*` tblRoutes
  rows (Router never consults tblRoutes for `api/*` paths) plus the matching
  full_schema.sql seed-block prune. Cloudflare Stream `testConnection()` +
  admin "Test connection" button (#386 parity with BookIT Phase 2). All in
  migration 158 + one full_schema fold; CI-green (10/10 audit checks, `php
  -l` clean). See `.claude/HANDOFF.md` for the fuller discovery-pass notes.
- **PR #372** — this
  session's additions on top of the #323 Phase 2 base below: #299 "Giving
  polish" sub-features — two-person offering-count session (sub-1, migration
  150), pledge campaigns (sub-2, migration 151), bank reconciliation (sub-3,
  migration 152), plus the online/project-gift auto-attribution follow-up
  wiring `Giving::attributeGift()` into `Payments::markPaymentSucceeded()` and
  `Projects::fulfilPledge()`; #303 Phase 2 Discipleship — per-user progress +
  auto-completion (migration 153); #300 v2 Service Plans — operator →
  confidence-monitor message channel (migration 154); a data-protection fix to
  `Portal\Core\GdprEraser::catalogue()` (wrong/mis-cased table names silently
  skipping erasure; added auth-residue tables `tblLocalAccounts` /
  `tblLinkedAccounts` / `tblTrustedDevices` / `tblPasswordResets` /
  `tblKidProfiles`) plus a demo-data-wipe table-name fix; a new
  `tools/audit-checks/check_php_table_refs.py` CI check (flags `tblXxx`
  identifiers hard-coded in PHP that aren't real tables) and a native
  `confirm()` → `data-confirm` cleanup sweep. All CI-green through migration
  154; see `.claude/HANDOFF.md` for the full remaining/next breakdown.
- **PR #372** — #323 Phase 2: REST API v1 write surface — dual-mode `ApiAuth` (bearer API key OR session), `/api/v1/{resource}[/{id}]` RESTful facade, new write endpoints (Attendance/Documents/Expenses create+delete/Users), canonical `ApiKey::SCOPES` + rotation grace, per-key rate limiting, `Site::forceContext` tenant pinning, admin scope-checkbox + audit source-badge UI, OpenAPI v1 paths + `bearerAuth` scheme (v1.4.0). Plus #324 outbound webhooks admin CRUD UI.
- **PR #358** (in flight) — #303 Discipleship Pathway Tracker Phase 1 + #313 COP Live Chat Phase 1 + Phase 2 (push prompts + viewer widget) + #317 Virtual Host Console Phase 2 (overlap on `tblLivePrompts`) + #360 Community Noticeboard Phase 1 (poster wall, self-hosted React, page-scoped CSP extension). Includes a Phase 1 hotfix (`::ok`→`::success`) and multiple security-check-clean bug fixes.
- **PR #357** — #317 Phase 1 + #323 API key infrastructure Phase 1.
- **PR #356** — Plus Jakarta Sans modular embed.
- **PR #355** — Worship Presentation Engine full v1.
- **PR #354** — Post-merge cleanups (composer fix + 1.3.0 + installer favicons).
- **PR #340** — Events platform overhaul (36 issues / 39 commits).
- **PR #297** — Multi-brand product layer (#296).
- **PR #129** — Prayer Requests app (logged-in + anonymous public route)
- **PR #130** — Multi-provider Captcha (Turnstile / reCAPTCHA v2+v3 / hCaptcha) with admin priority drag-and-drop
- **PR #131** — Release prep v0.11.0 (version bump + CHANGELOG stamp)
- **PR #132** — Password policy hardening (#53) — min 12 chars, full-flow coverage, JS strength meter
- **PR #133** — Debug mode refused in production (#54), error display silenced
- **PR #134** — Deploy `dry_run` workflow_dispatch + DEV_NOTES SFTP `--delete` docs (#107)
- **PR #135** — Anchor colour bound to `--bs-link-color` for both themes (was leaking browser-default blue)
- **PR #137** (in flight) — Calendar seven view modes (closes #136)
- **PR #138** (in flight) — Calendar per-month strap-lines + category display-style toggle

## GitHub Labels

- `type:` -- feature, enhancement, bug, security, docs, infrastructure, refactor
- `priority:` -- critical, high, medium, low
- `scope:` (blue) -- core, admin, auth, ui, i18n (cross-cutting concerns)
- `app:` (salmon) -- calendar, attendance, expenses, admin, dashboard, help, settings, prayer-requests
- `phase:` (purple) -- 3 through 13
- `status:` -- blocked, in-progress, review

## Standing Instructions (per ProjectBrief)

When making changes:

1. Create a GitHub Issue with description, scope, and acceptance criteria
2. Run ALL code through syntax/lint checks -- fix ALL issues until zero remain
3. Update CHANGELOG.md, **FEATURES.md**, DEV_NOTES.md, README.md as appropriate
4. Update `.claude/` memory and context
5. Update GitHub Wiki/Project/Milestones alongside Issues
6. COMMIT changes (DO NOT PUSH unless the user explicitly asks for a PR)
7. Close GitHub Issue with commit / PR reference

### STANDING: monitor & fix GitHub PR Security checks (always applicable)

On EVERY PR you touch, actively monitor GitHub's own automated checks — the
`pr-security.yml` "PR Security Checks" bot comment (route-target-missing,
MariaDB-only DDL, migration idempotency, SQL column drift, schema/seed parity,
etc.), CodeQL, Psalm, static-security, actionlint, and the migration harness —
and **fix any real issue each surfaces**, not only the hard PHP-lint gate. These
checks are non-blocking heuristics but a flagged item is treated as actionable:
resolve it correctly (e.g. a route pointing at a missing handler → build the
handler or remove the route + add the cleanup migration), or, only if it is a
genuine false positive, record why in the PR thread. Re-check after each push
until the security comment is clean. This applies regardless of session.

## Git Notes

- macOS case-insensitive: use two-step rename for case changes
- Never commit: `_auth_keys/`, `_uploads/`, `_backups/`, `_libraries/`, `.env`, `*.key`
- Deploy workflow syncs `web/` only, excluding server-managed dirs
- Shared dirs (`_core/`, `_vendor/`, `_sql/`, `_includes/`, `_functions/`, `_libraries/`) mirror with `--delete` — manual server-side edits to these dirs vanish on the next deploy (see DEV_NOTES.md → Troubleshooting)
