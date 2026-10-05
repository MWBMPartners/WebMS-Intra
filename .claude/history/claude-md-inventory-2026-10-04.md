# Inventories moved out of `.claude/CLAUDE.md` — a snapshot as of 4 October 2026

**What this is.** Four sections that used to sit inside `.claude/CLAUDE.md`, copied here
word for word: the counts table, the directory layout, the apps table (with its note on
folders that are infrastructure rather than apps), and the key constants. They were
removed from that file on 5 October 2026 at the owner's decision (Salem874), because
they describe things anyone can find by reading the code, they go out of date within
days, and that file is loaded into every Claude Code session.

**Treat every number and listing below as a snapshot, not as the truth.** Some of it was
already stale when it was moved: the counts table said 16 audit checks and 8 self-tests
when there were 21 and 17. **When this disagrees with the code, the code is right.**

**Where each fact lives now:**
- What each app does: `FEATURES.md` (the living feature inventory). The warning that
  content translation is not reachable (#485) is at `FEATURES.md` and also stays, as one
  line, in `.claude/CLAUDE.md`.
- Which branch deploys to which server folder: `DEV_NOTES.md` (the deploy section), and
  one line in `.claude/CLAUDE.md` "Git Notes".
- Which three PHP files may be served directly from the web root: `web/public_html/.htaccess`,
  and one line in `.claude/CLAUDE.md` "Git Notes".
- The constants: `web/_core/bootstrap.php`, where they are defined.
- Migration numbers 168, 169 and 195 were never used: one line in `.claude/CLAUDE.md`.
- Counts: re-count from the code with the commands in the table below.

Claude Code does not load this folder by itself.

---

## Counts, and when they were last checked

These numbers go stale quickly and have been wrong before. Verified against the
code on **20 September 2026**:

| What | Count | How to re-check |
| --- | --- | --- |
| App folders | 54 | `ls -d web/_apps/*/ | wc -l` |
| Installable apps (the on/off list) | 47 | `ls web/_core/apps/*.php | wc -l` |
| Framework classes | 81 | `ls web/_core/*.php | wc -l` |
| Numbered database migrations | 196 files, numbered 000-198 | `ls web/_sql/[0-9][0-9][0-9]_*.sql | wc -l` |
| Database tables | 213 | `grep -c 'CREATE TABLE IF NOT EXISTS' web/_sql/full_schema.sql` |
| PHP files | 803 | `find web -name '*.php' | wc -l` |
| In-app help guides | 19 | `ls web/_apps/help/*.php | wc -l` |
| Live addresses the portal answers on | 552 | `python3 tools/audit-checks/check_route_targets.py` |
| Settings seeded | 575 | `python3 tools/audit-checks/check_settings_keys.py` |
| Automatic checks in `tools/audit-checks/` | 16 | `ls tools/audit-checks/check_*.py | wc -l` |
| Self-tests in `tools/` | 8 | `ls tools/*selftest*.php | wc -l` |

**If a number here disagrees with the code, the code is right.** Numbers 168,
169 and 195 are missing from the migration sequence: they were never used, and
nothing depends on the numbering being unbroken.

## Directory Layout

```
repo root/          <- NOT deployed (docs, CI/CD only)
web/                <- ALL deployable files (synced to server via SFTP)
  _core/            <- Framework classes (Portal\Core namespace, 78 classes)
  _apps/            <- App controllers — outside the webroot (#159). Every
                       app's PHP handlers live here; Router resolves
                       tblRoutes.targetFile against PORTAL_APPS = _apps/.
  _vendor/simplejwt/<- Vendored RS256 JWT verifier
  _sql/             <- Numbered SQL migrations (000-198 + full_schema.sql).
                     196 files, not 199: 168, 169 and 195 were never used.
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

## Key Constants (defined in _core/bootstrap.php)

- `PORTAL_ROOT` -- web/ on server
- `PORTAL_CORE` -- web/_core/
- `PORTAL_APPS` -- web/_apps/ (since #159)
- `PORTAL_VENDOR` -- web/_vendor/
- `PORTAL_SQL` -- web/_sql/
- `PORTAL_ENV` -- 'dev', 'beta', or 'prod' (auto-detected from DOCUMENT_ROOT)
