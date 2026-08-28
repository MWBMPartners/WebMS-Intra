# WebMS Intra - Claude Code Instructions

## Project

Internal portal platform (PHP 8.5, backward-compatible with 8.4, MySQL 8.0, Bootstrap 5.3.3) hosted on DreamHost shared hosting. No CLI, no Composer.

- **Version:** 1.4.0 (on `main`; bump in `web/_core/version.php` — single source of truth)
- **Brand layer:** runtime product brand picked at install (#296, PR #297). Presets: `WebMS Intra` (generic, default), `ChurchMS` (church). School/charity/community/small-business stubbed. See `web/_core/brand-defaults.php` + `Site::productName()`. PWA manifest is a brand-aware PHP controller (`manifest.php`); the OpenAPI spec is likewise served brand-aware via `public_html/openapi.php` + `_core/api-spec.json` (#307).
- **Licence:** All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
- **Repo:** github.com/MWBMPartners/WebMS-Intra
- **Server:** portal.millrdsdacambridge.uk
- **Full brief:** `.claude/ProjectBrief_Chat.claude`
- **Living feature inventory:** [FEATURES.md](../FEATURES.md) (always check this first)
- **Chronological history:** [CHANGELOG.md](../CHANGELOG.md)
- **Dev-facing technical notes:** [DEV_NOTES.md](../DEV_NOTES.md)

## Directory Layout

```
repo root/          <- NOT deployed (docs, CI/CD only)
web/                <- ALL deployable files (synced to server via SFTP)
  _core/            <- Framework classes (Portal\Core namespace, 64 classes)
  _apps/            <- App controllers — outside the webroot (#159). Every
                       app's PHP handlers live here; Router resolves
                       tblRoutes.targetFile against PORTAL_APPS = _apps/.
  _vendor/simplejwt/<- Vendored RS256 JWT verifier
  _sql/             <- Numbered SQL migrations (000-179 + full_schema.sql)
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

`web/_apps/` holds ~47 top-level entries; `web/_core/apps/*.php` is the
AppRegistry — the single source of truth for **installable marketplace
apps** (toggleable per-site at `/admin/apps`), 43 of them. The table below
is every user-facing app (see note below the table for dirs that are
infrastructure rather than apps).

| Slug | Route | What it does |
| --- | --- | --- |
| admin | `/admin` | Users, roles, settings, sites, errors, activity, audit, migrations, integrations, workflows, reports, **captcha config** |
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
| directory | `/directory` | Searchable member directory with opt-in per-field visibility |
| discipleship | `/discipleship` | Ordered formation pathways with per-member progress tracking, auto-completion from attendance/RSVPs, pastor roster (#303) |
| documents | `/documents` | File library with categories |
| expenses | `/expenses` | Submit, approve, treasury, withdraw, multi-approver, PDF, CSV |
| giving | `/giving` | Contributions log, Gift Aid capture, HMRC export, year-end statements (self-service + treasurer bulk batch generate/email, #440); two-person offering count, pledge campaigns, bank reconciliation (#299) |
| help | `/help/*` | In-app guides (getting-started, expenses, calendar, prayer-requests, admin, faq, …) |
| invites | `/invites` | Single-use invite links so new members self-register with role pre-assigned |
| kids | `/kids/*` | Children's ministry check-in / check-out with 6-digit safeguarding badge codes (#298) |
| leadership | `/leadership` | Roles + assignments + history + CSV |
| livestream | `/live` | Embed YouTube / Vimeo / Twitch / Facebook livestreams with countdown + session analytics |
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
| service-plans | `/service-plans` | Programme run-sheet builder (preacher, scripture, hymns, AV, welcome team); operator → confidence-monitor messaging (#300) |
| settings | `/settings` | Generic dot-notation settings editor |
| site | `/site` | Multi-site switcher handler |
| sms | `/admin/sms` | SMS notifications for critical alerts via Twilio / MessageBird / AWS SNS |
| tasks | `/tasks` | Reminders / task list |
| transcription | `/admin/transcription` | Auto-transcribe Recordings via Whisper / AssemblyAI / local whisper.cpp; full-text search |
| translation | `/admin/translation` | Auto-translate user content via Anthropic / OpenAI / Google / DeepL / LibreTranslate, cached after first translate |
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
generator utility used by Noticeboard/Visitors/etc).

Calendar/Events/Preaching Plan is ONE app ("Events") — `/calendar` covers viewing/listing/subscribing; the manage UI handles preaching-plan/worship event types and series.

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

## SQL dialect trap (apply on every migration)

- **Production is MySQL 8.0.** MariaDB-only `IF [NOT] EXISTS` on `ADD`/`DROP COLUMN`, `ADD`/`CREATE`/`DROP INDEX`/`KEY`, or `CHANGE`/`MODIFY COLUMN` is rejected with **ERROR 1064** on MySQL 8 — `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` are standard MySQL and stay fine.
- **Use the `information_schema` + `PREPARE`/`EXECUTE` guard idiom** instead (see DEV_NOTES.md → "Portable DDL convention (MySQL 8.0 ∩ MariaDB)" for the full templates). House examples already shipped this way: migrations **037**, **112**, **138**.
- **Migrations must replay as no-ops** on an up-to-date schema — the installer replays every numbered migration after `full_schema.sql`, ignoring `tblMigrations`.
- **CI**: `tools/audit-checks/check_mariadb_only_ddl.py` + the `e2e-migrations` harness enforce this.

## Recent ships (chronological)

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
  route seeds. All 11 audit checks green, `php -l` clean on every
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
