# Stage 02 review — orchestrator resolutions (feed into Stage 03 build plan)

Stage 02 (`02-app-design.md`) is ACCEPTED as the application-design baseline. Its 14-item security checklist and 26-route/handler surface stand. Resolutions below.

## CRITICAL correctness ratification — `tblEvents` datetime semantics (overrides Stage 01 §8.2 wording)
I independently verified the storage reality (do NOT trust the column comment):
- `full_schema.sql` comments `tblEvents.startDateTime` "stored in UTC" — **this comment is WRONG (a latent doc bug in the existing schema).**
- `calendar/manage/save.php` binds the raw `$_POST['startDateTime']` with **no UTC conversion** and stores a separate `timezone`/`eventTimezone`.
- `calendar/event.php:241` reads it back as `new DateTimeImmutable($event['startDateTime'], new DateTimeZone($tz))` — i.e. it treats the stored value as **local wall-clock in the event's own timezone**.
- `calendar/export.php` / `_core/Ical.php` emit **TZID + VTIMEZONE (local), never bare-UTC "Z"** — confirming events are local-time.

**RESOLUTION (binding for Stage 03 / the build):** `tblVenueBookings` rows are wall-clock **venue-local** (Stage 01 §8.4). `tblEvents` datetimes are wall-clock **event-local** (in `event.timezone` / `eventTimezone`). `Venues::classifyEventCoverage()` therefore compares **wall-clock to wall-clock**:
1. If `event.timezone == venue.timezone` (the overwhelmingly common single-locale case, both `Europe/London`) → compare the event's stored date+times DIRECTLY against the booking's date+times. **No conversion. Never route through UTC.**
2. If the two IANA zones differ (rare, cross-locale) → convert the event's wall-clock FROM `event.timezone` TO `venue.timezone` with `DateTimeImmutable`/`DateTimeZone`, then compare.
Stage 01's "convert E's UTC datetimes to venue-local" phrasing is SUPERSEDED — there is no UTC in this path. Stage 03's verification plan MUST include the DST-boundary functional check Stage 02 specified (an event around the 2025-03-30 01:00→02:00 Europe/London spring-forward vs a 09:30–13:30 booking) and assert no spurious OUTSIDE_HOURS.
- Out of scope now, file at ship: a follow-up issue noting the misleading `tblEvents.*DateTime` "UTC" comment so it gets corrected/reconciled project-wide (do NOT "fix" storage under this feature).

## The 12 Stage-02 open questions — dispositions for Stage 03
1. **Migration number** — Stage 03 re-verifies the free number at build time (166 is taken by the pushed Wave 1 branch; confirm 167–169 vs the other gap items, take the next free ≥170 for venues). Substitute the real GitHub issue number for `#VEN` once the epic issue is filed.
2. **`tblVenueInvoicePayments` DDL delta** — build §3.12 MINUS `portalPaymentID`/`idx_venip_portal`/`fk_venip_portal` (my Q3 override). Zero guarded ALTERs in the venues migration. Confirmed.
3. **`tblEvents` datetime semantics** — RESOLVED above (wall-clock, no UTC). DST-boundary test mandatory. Confirmed.
4. **`ZipArchive` availability** — smoke-test in the verification plan; CSV fallback advertised in the wizard. Accept.
5. **`tblSettings` per-site upsert** — verify the unique key fires `ON DUPLICATE KEY UPDATE` for both `siteID = NULL` and `siteID = N`; fall back to the update-then-insert pattern (`translation/save.php` l.43-51) if NULL rows can duplicate. Accept.
6. **`check_settings_keys.py`** — run early; every seeded `venues.*` key MUST have a named `Settings::get`/`settingForSite` consumer in the shipped code (no orphan keys). Hard gate.
7. **`Site::forceContext` reset** in the cron — mirror exactly what `asset-reminders.php` does at the end of its site loop. Accept.
8. **Fuzzy vocab suggestion** — pin the exact scoring: case-fold + strip non-alnum, suggest the seed status sharing the leading category token (`proposed|agreed|rejected|pending`); ties → no preselection (never auto-apply a guess). Accept.
9. **Room-aware coverage (Q12) + `tblEvents.venueID` (Q6)** — file BOTH as follow-up issues at ship; v1 carries the class-header note + `venues.calendar_default_venue`. Accept.
10. **Stub policy** — all 26 handlers ship real in ONE PR (no split), so no stubs needed; `check_route_targets.py` must be green because every seeded route's target file exists. If Stage 03 decides to split PRs, any later-PR handler MUST ship as a stub in the migration PR (159 precedent). Prefer single PR.
11. **Load surface** — no pagination in v1 except add it to the invoices ledger only if it exceeds ~200 rows (later, not now). Accept.
12. **E2E migration harness** — run harness + `check_migration_idempotency.py` + `check_mariadb_only_ddl.py`; expect zero findings, zero guarded ALTERs. Hard gate.

## Reaffirm for Stage 03
- The whole build MUST pass ALL 10 `tools/audit-checks/check_*.py` + `php -l` + the e2e-migration harness before any PR merges. This is the "right first time, no false positives" bar.
- Security checklist §12 (14 items) is the verification contract — Stage 03 maps each to a concrete check/grep-gate.
- GDPR catalogue additions (§8 of app-design) are mandatory (the #372 silent-skip lesson).
- Implementation agents = Sonnet/Haiku only. Stage 03 must decompose the build into agent-sized specs with disjoint file sets (to parallelise safely in worktrees) and an explicit build/verify order.
