# Venue Bookings app — planning brief (ground truth for the Fable planning pipeline)

**Created:** 2026-08-27. Feeds the sequential Fable 5 planning agents. Do NOT build from this brief directly — build from the Fable plan (stages 01/02/03) it produces.

## What the user asked for
A church that is the **TENANT** hiring an external worship building from another organisation needs to **record, and let leaders view, the confirmed bookings of that hire** so they can plan events/programmes without scheduling into an unbooked/finished slot. Flexible, "think outside the box." Real example attached = `Proposed_Mill_Road_Rental.xlsx` (Mill Road SDA Cambridge congregation hiring the Mill Road Baptist Church building).

## Confirmed decisions (from the user, this session)
1. **Scope:** COMPREHENSIVE in one build — Phase-1 scheduling PLUS hire agreements, per-booking cost/rate, invoicing & payment tracking, renewal reminders, and an availability/conflict engine.
2. **Placement:** NEW dedicated `/venues` app (marketplace-toggleable), separate from Resources (internal rooms owned) and Assets (property owned).
3. **Calendar integration:** Overlay + "is it booked?" warnings — agreed hire slots show as a distinct venue layer on the Calendar; warn when an event is scheduled with no agreed booking behind it, outside the booked window, or when the building is Unavailable/Closed that day.
4. **Status model:** CONFIGURABLE per-site status vocabulary, seeded with the church's six, each carrying a **`countsAsConfirmed`** (and likely `isAvailable`) flag that drives the calendar/warnings. Not a hardcoded workflow.

## Domain model extracted from the real spreadsheet
- Workbook = **one sheet per YEAR** (2024, 2025, 2026) + a **`BackingData`** sheet holding the dropdown vocabularies. Everything is config-driven and the defaults change year to year.
- **One row per date** (mostly weekly Saturdays — SDA Sabbath worship — plus ad-hoc multi-day runs e.g. a VBS week of consecutive days). Dates are Excel serials (45542 = 2024-09-… Saturday). Import must convert serial → real date.
- Columns per row:
  - **DATE** — the hire date (or a day within a multi-day run).
  - **HOURS** — a *usage-type* from a controlled list: `Regular Hours`, `Extended`, `Extended (All Day)`, `Custom`, `Closed/Not Needed`, `Building Unavailable`. Each type has a **default time window** that is edited per year.
  - **TIMES** — the actual window (default from the usage-type, overridable): e.g. `09:30-13:30`, `09:30-17:30`, `09:00-15:00`, `N/A`, `0`, `<enter times>`.
  - **NOTES** — free text = the actual programme (Communion Service, Fellowship Lunch, Health Expo, VBS, Art.Space, Small Groups, Road closure…). This is the LINK TO EVENTS.
  - **STATUS** — an agreement/approval lifecycle from a controlled list: `Standard Agreement` (blanket standing hire), `Pending Mill Rd Leadership Agreement` (internal sign-off), `Proposed to Mill Rd Baptist Church` → `Agreed by Mill Rd Baptist Church` / `Rejected by Mill Rd Baptist Church` / `Rejected - building already in use`.
- **BackingData vocabularies (seed these as the per-site defaults):**
  - STATUS: Standard Agreement · Pending Leadership Agreement · Proposed to Landlord · Agreed by Landlord · Rejected by Landlord · Rejected – building already in use.
  - USAGE-TYPE → default TIMES: Regular Hours→09:30-13:30 · Extended→09:00-15:00 · Extended (All Day)→09:30-17:30 · Custom→(enter) · Closed/Not Needed→N/A · Building Unavailable→(none). NB defaults differ per year (2026 shows Regular→09:30-16:00 in one column) → usage-type default times should be effective-dated or per-schedule-year, not global constants.
- Two orthogonal "not a normal booking" states: **Building Unavailable** (landlord's own use / road closure — church CAN'T have it) vs **Closed/Not Needed** (church doesn't need it that week). Both must show distinctly on the calendar.
- The STATUS field encodes a **two-sided approval** (internal leadership + landlord) — but per decision #4 it's modelled as a configurable vocabulary with flags, NOT a fixed workflow.

## Generalisation requirements (NOT hardcoded to Mill Road)
- Multiple venues; each venue has a landlord org + address + optional rooms/spaces.
- Per-site (or per-venue) configurable usage-types (with default windows) and status vocabulary (with countsAsConfirmed/isAvailable flags).
- Heavy recurrence: a "generate weekly (or other cadence) bookings across a date range for a year" tool is essential — most rows are identical weekly worship.
- Excel/CSV importer mapping DATE/HOURS/TIMES/NOTES/STATUS (+ multi-year sheets, + BackingData) so a church migrates its existing record in one upload; unknown vocab values get created or mapped in the wizard.

## Existing infrastructure to REUSE (do not reinvent — confirm exact APIs during planning)
- **Landlord modelling:** the Assets app already has external orgs + a cross-org agreement vault — `tblAssetOrgs` and related (see `web/_core/AssetRegister.php`, `web/_apps/assets/orgs.php`). DEFAULT: reuse/extend this for the landlord + hire agreement rather than a duplicate org schema. Planner must verify fit and decide reuse-vs-thin-new.
- **Payments/invoicing:** `web/_core/Payments.php` (provider-agnostic: startCheckout → provider → markPaymentSucceeded fan-out; purposes giving/pledge/membership/other). Invoicing/payment tracking for hire could add a `venue`/`rent` purpose. Also see Giving/Expenses for money patterns.
- **Calendar/Events:** the overlay + warnings integrate here. `web/_apps/calendar/` (7 view modes, `calendar/index.php`), `tblEvents` (+ capacity). Events already key work off `eventID`; bookings should optionally link to an event and/or the calendar should query venue bookings for the warning.
- **Reminders cron:** copy the token-gated pattern in `web/_apps/cron/event-reminders.php` / `asset-reminders.php` (constant-time token, empty→403, dedupe). Renewal/un-agreed/payment reminders.
- **AppRegistry:** add `web/_core/apps/venues.php` so `/admin/apps` can toggle it (see existing `_core/apps/*.php`).
- **Framework conventions (MUST):** routes in `tblRoutes` (+ full_schema seed); settings flags `venues.*` in `tblSettings`; `PORTAL_APPS` handlers under `web/_apps/venues/`; `declare(strict_types=1)`; full IF notation (`=== true`/`=== null`); MySQLi prepared statements ONLY; `htmlspecialchars($v,ENT_QUOTES,'UTF-8')`; NO `<table>` — use `portal-data-list`; CSRF on every POST; multi-site scoping via `Site::id()` on every query; file-header comment blocks with @version; emoji-annotated sections.
- **SQL portability (MUST):** MySQL 8.0 ∩ MariaDB. Plain `CREATE TABLE IF NOT EXISTS` OK; for ALTER use the information_schema + PREPARE/EXECUTE guard idiom (NO MariaDB `IF [NOT] EXISTS` on ADD/DROP/MODIFY). Every migration replays as a no-op + self-records into tblMigrations + folds into `full_schema.sql`. See DEV_NOTES "Portable DDL convention".
- **ApiRouter trap:** any `api/*` endpoint ignores tblRoutes — handler at `_apps/venues/api/{action}.php`, gated by `api.venues.{action}.enabled` setting; `ApiResponse::success()` not `::ok()`.
- **Migration numbers:** COORDINATE centrally — the in-flight 8 gap-analysis items also add migrations (Wave 1 uses 166). Reserve a contiguous venue range decided in the build-plan stage; do not hardcode until then.

## Planning pipeline (sequential Fable 5; Opus fallback, retry Fable next run)
- **Stage 01 — Domain & data model:** full schema (tables/columns/enums/flags/relationships), config vocabularies, per-year effective-dated usage-type defaults, recurrence model, agreements, invoicing/payments link, and the exact reuse decisions vs existing infra. Output → `01-data-model.md`.
- **Stage 02 — Application design:** routes, AppRegistry, settings, every controller/handler, recurring-generator UX, Excel/CSV import wizard (exact column+vocab mapping), leader schedule view, calendar overlay + conflict-warning logic, agreements, invoicing/payment tracking, reminders cron, roles/permissions, security, i18n. Output → `02-app-design.md`.
- **Stage 03 — Build plan:** migration files + coordinated numbers, full_schema folds, file-by-file build order, the precise per-agent Sonnet/Haiku build specs, verification/test plan (php -l + all audit checks + functional checks), and an edge-case/risk list to get it right first time. Output → `03-build-plan.md`.

Each Fable stage READS this brief + the repo + the prior stage's output. Persist each stage's output to this dir and commit as we go.
