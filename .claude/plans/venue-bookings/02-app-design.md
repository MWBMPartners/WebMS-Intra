# Venue Bookings — Stage 02: Application design

**Pipeline:** Stage 02 of 3. Inputs: `00-brief.md` (4 confirmed decisions), `01-data-model.md` (ACCEPTED 15-table schema), `01b-review-resolutions.md` (13 resolutions — **these override the data model where they differ**).
**Written:** 2026-08-27 against the live repo. Every pattern cited below was read this session (file + line refs given) — the build team copies them, never guesses.

**Headline overrides carried in from `01b-review-resolutions.md` (restated so nobody builds from the stale §§ of 01):**
1. **The `tblPayment` 'venue-hire' bridge is DROPPED** (Q3). Migration 170 ships **ZERO guarded ALTERs** — it is all `CREATE TABLE IF NOT EXISTS`. Concretely, relative to `01-data-model.md`: **delete §3.16 entirely**; in §3.12 `tblVenueInvoicePayments` **remove the `portalPaymentID` column, the `idx_venip_portal` key and the `fk_venip_portal` constraint**; do **not** touch `Payments::PURPOSES` or `markPaymentSucceeded()`; no full_schema edit to `tblPayment`. Everything else in §3 ships verbatim.
2. GDPR catalogue additions are mandatory (§8 below).
3. Cross-tenant invariants are PHP-enforced in every save handler (§4.0 below).
4. `venues.calendar_default_venue` selects the event-check venue (§5).
5. Generator duplicate-skip per (venue, date, roomID-NULL-safe) with skip reporting (§3.4).
6. Status seeding on first access + admin restore action (§3.2, §4.6).

---

## 0. Pattern sources (verified this session — cite these in code comments)

| Pattern | Source (file · lines) |
|---|---|
| Handler shell: `Auth::ensureSession()` + `requireLogin()` + `Site::id()` + header/footer templates | `web/_apps/resources/index.php` l.16-47, l.113 |
| Manager role gate (`App::isAdmin() !== true && App::hasRole('x') !== true` → `Router::renderError(403)`) | `web/_apps/assets/orgs.php` l.43-46 |
| Self-posting small reference-data CRUD (GET renders, POST mutates, `?edit=` inline prefill, flash + redirect) | `web/_apps/assets/orgs.php` l.51-99 (header l.9-13 names it the house pattern) |
| CSRF-first on POST before any side effect | `web/_apps/assets/orgs.php` l.53-58; `web/_apps/calendar/manage/save.php` l.38-43 |
| `portal-data-list` rendering (no `<table>`) | `web/_apps/assets/orgs.php` l.163-205 |
| Conflict-probe booking form (self-POST, prefill retained on error) | `web/_apps/resources/book.php` l.52-130 (probe l.86-96) |
| `AssetRegister::saveOrg()` contract: `(int $siteId, int $orgId, array $data, int $actorUserId): int` — 0 = blank-name failure; logs via `Logger::activity()` (venue-safe, no assetID) | `web/_core/AssetRegister.php` l.3570-3634; `listOrgs()` l.3529; `toggleOrgActive()` l.3642 |
| `Logger::audit(tableName, recordId, action, oldData, newData, userId?, apiKeyId?, source?)` — JSON changeSet diff, API-key attribution | `web/_core/Logger.php` l.111-190 |
| `GdprEraser::catalogue()` entry shape + the two conventions §8 relies on | `web/_core/GdprEraser.php` l.45-197 (NOT-NULL-actor convention l.117-124; no-per-user-column convention l.142-148) |
| Token-gated cron: constant-time compare, empty-token→403, per-site `Site::forceContext()` loop, check-first dedupe + unique-key catch, `recipientCount=0` still logs | `web/_apps/cron/asset-reminders.php` l.85-97 (gate), header l.27-43 (multi-site + dedupe), l.118-180 (`sendAssetReminder`) |
| Role-holder recipient SQL (admins + roleKey) | `web/_core/AssetRegister.php` `resolveReminderRecipients()` l.8272, role/admin SQL l.8343-8359 |
| Calendar view router: 7 views, range computation, `$events` fetch, partial dispatch | `web/_apps/calendar/index.php` l.63-73, l.129-177, l.274-316, l.371-379 |
| Month grid day-cell grouping `$byDate['Y-m-d']` | `web/_apps/calendar/views/month.php` l.26-49, l.83-90 |
| Shared function-partial guarded by `function_exists` | `web/_apps/calendar/views/_day_columns.php` l.31-40 (used by day/week/weekdays/weekend) |
| Event save handler (create l.173-234, update l.239-305), upload validation closure l.112-159 | `web/_apps/calendar/manage/save.php` |
| Event form datetime inputs (`name="startDateTime"` / `"endDateTime"`, `datetime-local`) | `web/_apps/calendar/manage/_event_form.php` l.62-69; included from `manage/index.php` l.210, l.247 |
| ApiRouter: `api/{app}/{action}` → `_apps/{app}/api/{action}.php`, flag `api.{app}.{action}.enabled`, bare `$mysqli` available (#373 `global $mysqli, $SETTINGS`) | `web/_core/ApiRouter.php` l.80-130; handler example `web/_apps/worship/api/state.php` l.35-63 |
| `ApiResponse` surface: `::success()` l.44, `::error()` l.73, `::requireAuth()` l.104, `::requireEnabled()` l.119 — **no `::ok()`** | `web/_core/ApiResponse.php` |
| `tblRoutes` DDL (`routeKey`, `targetFile`, `isProtected`) | `web/_sql/full_schema.sql` l.110-121 |
| Route-seed idiom (batch VALUES + `ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)`) | `web/_sql/159_asset_tracker.sql` l.531-557 |
| Settings-seed idiom (`(NULL, key, value, default, isSensitive)` + `ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)`) | `web/_sql/160_asset_tracker_phase2.sql` l.228-241 |
| Role-seed idiom (`WHERE NOT EXISTS`) | `web/_sql/159_asset_tracker.sql` l.483-486; folded at `full_schema.sql` l.6634-6636 |
| App-enable gating: Router auto-403s a disabled app's routes via `AppRegistry::appForRoute()` (longest route-prefix match) + `isEnabled()` (true iff setting `'1'` or `'true'`) | `web/_core/Router.php` l.123-124; `web/_core/AppRegistry.php` l.95-116, l.164-183 |
| AppRegistry entry shape | `web/_core/apps/resources.php`, `web/_core/apps/assets.php` (17-line return array) |
| Upload security (finfo-sniffed MIME → allow-list → server-generated random filename → outside webroot → gated download) | `web/_apps/assets/resource-save.php` header l.23-38 + const l.56; download path-safety `web/_apps/assets/resource-download.php` header l.11-22 + uniform-404 gates l.24-37 |
| CSV export | `web/_core/CsvExporter.php` `::download(filename, rows, headers)` l.37-85 |
| PDF: `Pdf::create(string $html, string $filePath, string $watermark=''): string|false` + print-CSS fallback when dompdf missing; **CSS Grid not `<table>` in PDF HTML** | `web/_core/Pdf.php` l.76; fallback `web/_apps/assets/labels-pdf.php` l.192-207; grid rationale `web/_core/AssetRegister.php` l.7098-7100 |
| PDF streaming handler | `web/_apps/giving/my-statement.php` l.26-37 (`Giving::renderStatementPdf` → `Pdf::create`, `Giving.php` l.257) |
| Import wizard as ONE self-posting route with POST actions (upload/map/confirm/cancel) | `web/_apps/assets/import.php` l.516-727 |
| Settings write idiom (upsert, per-key prepared statement) | `web/_apps/admin/settings/sabbath/index.php` l.54-64; update-then-insert alternative `web/_apps/admin/translation/save.php` l.43-51 |
| Cron routes seeded `isProtected = 0` | `full_schema.sql` l.4681, l.6694 |
| Help-page shape (TOC badges + sections; public route) | `web/_apps/help/assets.php` l.24-50; route seed `159_asset_tracker.sql` (`'help/assets'`, isProtected 0) |
| Per-site vocab seeded PHP-side, never in migrations | `01-data-model.md` §0/§4 (verified: no INSERT seeds for `tblAttendanceServiceTypes`/`tblGivingCategory`) |
| `tblEvents` datetime columns: `startDateTime`/`endDateTime` DATETIME "(stored in UTC)" comment + `timezone` VARCHAR(50) + `eventTimezone` VARCHAR(64) | `full_schema.sql` tblEvents block l.~751 |
| `App::settingForSite(string $key, int $siteId): ?string` (per-site setting resolution outside session context) | `web/_core/App.php` l.166 |
| `Site::forceContext(int $siteId)` | `web/_core/Site.php` l.448 |

---

## 1. Routes

### 1.1 `tblRoutes` seed (26 rows — migration 170, idiom of 159 l.531-557)

Every `targetFile` below MUST exist as a real file in the same migration's PR (full handler or stub) — `tools/audit-checks/check_route_targets.py` parses the batch-INSERT tuples and asserts each resolves under `web/_apps/` (it reads `INSERT INTO tblRoutes` across full_schema + all migrations).

| # | `routeKey` | `targetFile` | `isProtected` | Gate inside handler (on top of login) |
|---|---|---|---|---|
| 1 | `venues` | `venues/index.php` | 1 | viewer (any logged-in user) |
| 2 | `venues/venue` | `venues/venue.php` | 1 | viewer (read-only; manager sees actions/cost) |
| 3 | `venues/manage` | `venues/manage.php` | 1 | manager |
| 4 | `venues/venue-save` | `venues/venue-save.php` | 1 | manager (POST only; `delete` action admin-only) |
| 5 | `venues/rooms` | `venues/rooms.php` | 1 | manager |
| 6 | `venues/usage-types` | `venues/usage-types.php` | 1 | manager |
| 7 | `venues/statuses` | `venues/statuses.php` | 1 | manager |
| 8 | `venues/booking` | `venues/booking.php` | 1 | viewer read-only / manager edit |
| 9 | `venues/booking-save` | `venues/booking-save.php` | 1 | manager (POST only) |
| 10 | `venues/generate` | `venues/generate.php` | 1 | manager |
| 11 | `venues/import` | `venues/import.php` | 1 | manager |
| 12 | `venues/agreements` | `venues/agreements.php` | 1 | manager |
| 13 | `venues/agreement-save` | `venues/agreement-save.php` | 1 | manager (POST only) |
| 14 | `venues/agreement-files` | `venues/agreement-files.php` | 1 | manager (POST only) |
| 15 | `venues/agreement-download` | `venues/agreement-download.php` | 1 | manager |
| 16 | `venues/invoices` | `venues/invoices.php` | 1 | manager |
| 17 | `venues/invoice` | `venues/invoice.php` | 1 | manager |
| 18 | `venues/invoice-save` | `venues/invoice-save.php` | 1 | manager (POST only; hard `delete` admin-only) |
| 19 | `venues/invoice-payment-save` | `venues/invoice-payment-save.php` | 1 | manager (POST only) |
| 20 | `venues/invoice-download` | `venues/invoice-download.php` | 1 | manager |
| 21 | `venues/invoice-pdf` | `venues/invoice-pdf.php` | 1 | manager |
| 22 | `venues/export` | `venues/export.php` | 1 | viewer (cost column manager-only) |
| 23 | `venues/schedule-pdf` | `venues/schedule-pdf.php` | 1 | viewer (cost column manager-only) |
| 24 | `venues/settings` | `venues/settings.php` | 1 | **admin only** |
| 25 | `cron/venue-reminders` | `cron/venue-reminders.php` | **0** | `venues.cron_token` constant-time gate (empty → 403) |
| 26 | `help/venues` | `help/venues.php` | **0** | none (public help precedent: `help/assets` seeded isProtected 0) |

Seed clause: `ON DUPLICATE KEY UPDATE \`targetFile\` = VALUES(\`targetFile\`)` — replay-idempotent (159 precedent).

**Auto-gating:** with the AppRegistry entry in place (§2.1), `Router.php` l.123-124 403s every `venues/*` route while `venues.enabled` ≠ '1'/'true'. `cron/venue-reminders` and `help/venues` do NOT start with `venues/` so they are never blocked by the app toggle — the cron checks the per-site flag itself (§6), and the help page is deliberately reachable (matches `help/assets`).

### 1.2 API endpoints (ApiRouter convention — NOTHING in tblRoutes for these)

Handlers at `web/_apps/venues/api/{action}.php`, dispatched by `ApiRouter::dispatch()` (`ApiRouter.php` l.80-130), each gated by its `api.venues.{action}.enabled` settings flag (seeded `'true'`, §2.2). Bare `$mysqli` is available inside handlers (#373 `global $mysqli, $SETTINGS` import). Use `ApiResponse::success()` / `::error()` — never `::ok()`.

| Endpoint | File | Method | Auth | Purpose |
|---|---|---|---|---|
| `GET /api/venues/check` | `venues/api/check.php` | GET | `ApiResponse::requireAuth()` (session; bearer also passes) | The "is it booked?" classifier for the event form (§5.3). Params: `start`, `end` (both `Y-m-d\TH:i`, ≤ required/optional), `tz` (IANA string, default `'Europe/London'`), `venueID` (int, optional — default `venues.calendar_default_venue`). Validates `venueID` belongs to `Site::id()`. Returns `{classification, severity, message, perDay:[{date, classification, rows:[…]}]}` |
| `GET /api/venues/availability` | `venues/api/availability.php` | GET | `ApiResponse::requireAuth()` | Raw §8.1 overlay rows. Params: `from`, `to` (`Y-m-d`, range clamp ≤ 400 days → else `ApiResponse::error(...,400)`), `venueID` optional (site-validated). Returns `{days: {"YYYY-MM-DD": [rows…]}}` with the same column set as `Venues::availabilityForRange()` |

No write API in v1 (all mutations are session-form flows) — so no v1-facade actions (`list`/`create`/…) exist and no additional flags are needed. Adding `api/venues/list.php` later is a pure additive change (file + flag).

**Do NOT register any `api/venues/...` row in tblRoutes** — Router never consults tblRoutes for `api/*` (the #372 dead-route lesson removed 19 such rows).

---

## 2. AppRegistry + settings

### 2.1 `web/_core/apps/venues.php` (exact contents)

```php
<?php
// Path: _core/apps/venues.php
declare(strict_types=1);
return [
    'slug'        => 'venues',
    'name'        => 'Venue Bookings',
    'description' => 'Record and track the hire of external buildings — schedule, agreements, invoices, payments, and calendar conflict warnings.',
    'icon'        => 'fa-solid fa-building-columns',
    'color'       => '#b45309',
    'category'    => 'operations',
    'industries'  => ['church', 'community', 'school', 'nonprofit', 'small-business', 'coworking'],
    'route'       => 'venues',
    'settingKey'  => 'venues.enabled',
    'isCore'      => false,
    'version'     => '1.0.0',
];
```

Shape matches `_core/apps/resources.php`/`assets.php` byte-for-byte in structure. `route => 'venues'` is what makes `AppRegistry::appForRoute()` (l.164-183) auto-gate every `venues/*` route. `color` `#b45309` (amber-700) is unused by any existing registry entry (verified: existing entries use `#0d9488`, `#7c3aed`, …).

### 2.2 Settings seeds (migration 170; idiom of 160 l.228-241)

```sql
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'venues.enabled',                 '0',        '0',        0),
    (NULL, 'venues.currency',                'GBP',      'GBP',      0),
    (NULL, 'venues.maxFileSize',             '10485760', '10485760', 0),
    (NULL, 'venues.unagreed_lead_days',      '21',       '21',       0),
    (NULL, 'venues.renewal_lead_days',       '60',       '60',       0),
    (NULL, 'venues.invoice_due_lead_days',   '7',        '7',        0),
    (NULL, 'venues.reminders_enabled',       '1',        '1',        0),
    (NULL, 'venues.reminder_roles',          '',         '',         0),
    (NULL, 'venues.cron_token',              '',         '',         1),
    (NULL, 'venues.calendar_default_venue',  '0',        '0',        0),
    (NULL, 'api.venues.check.enabled',       'true',     'true',     0),
    (NULL, 'api.venues.availability.enabled','true',     'true',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
```

Notes:
- `venues.enabled = '0'` — opt-in, matching `resources.enabled`/`service_plans.enabled` (full_schema l.3432/l.3488). The #255 lesson: **the flag row MUST be seeded** or registering the app silently 403s it forever.
- `venues.cron_token` empty + `isSensitive=1` — the cron is inert until an admin sets a token (asset precedent, migration 160).
- `venues.reminder_roles` — CSV of `tblRoles.roleKey` values (e.g. `venue_manager,treasurer`). Empty ⇒ recipients fall back to site admins + `venue_manager` holders (§6.3).
- `venues.calendar_default_venue` — venueID as string; `'0'` = event-check disabled (§5.3). Per-site overridable via a siteID-scoped row written by `venues/settings.php`.
- **Deviation from `01-data-model.md` §4 (deliberate):** `venues.displayName` / `venues.displayIcon` are **dropped** — AppRegistry supplies name+icon to the dashboard/admin, no handler would read them, and unread seeded keys are noise for `check_settings_keys.py`. Everything else in §4 ships as listed above.

### 2.3 Role seed (migration 170; idiom of 159 l.483-486)

```sql
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'venue_manager', 'Venue Manager'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'venue_manager');
```

---

## 3. Core class `Portal\Core\Venues` (new file `web/_core/Venues.php`)

One static class, `namespace Portal\Core`, autoloaded by the PSR-4-lite loader (bootstrap l.133-139 — filename must be exactly `Venues.php`). Method-surface style mirrors `AssetRegister.php`: public statics with typed signatures, `App::db()` prepared statements, `error_log` + safe-default on prepare failure, class-header doc listing the design points. The class header MUST document: (a) the wall-clock timezone rule (§3.5.4), (b) the Q12 note — *"§3.5.2 rule 2 must tighten to room-aware coverage (`b.roomID IS NULL OR b.roomID = event.roomID`) if events ever gain room placement"*, and (c) the audit funnel contract.

### 3.1 Constants

```php
public const USAGE_KINDS        = ['hire', 'closed', 'unavailable'];
public const STATUS_CATEGORIES  = ['proposed', 'agreed', 'rejected', 'standing', 'unavailable'];
public const RATE_UNITS         = ['per-booking','per-hour','per-day','per-week','per-month','per-quarter','per-year','fixed-total'];
public const AGREEMENT_STATUSES = ['draft','active','expired','terminated','superseded'];
public const AGREEMENT_TYPES    = ['standing','ad-hoc'];
public const INVOICE_STATUSES   = ['pending','part-paid','paid','disputed','cancelled'];
public const PAYMENT_METHODS    = ['bank-transfer','standing-order','cash','cheque','card','online','other'];
public const GROUP_TYPES        = ['multi-day','recurring'];
public const FREQUENCIES        = ['weekly','fortnightly','monthly','custom'];

// §8.2 classifications, worst-first (array ORDER IS the precedence/worst-of order):
public const COVERAGE_UNAVAILABLE   = 'unavailable';
public const COVERAGE_NO_BOOKING    = 'no-booking';
public const COVERAGE_OUTSIDE_HOURS = 'outside-hours';
public const COVERAGE_UNCONFIRMED   = 'unconfirmed';
public const COVERAGE_CLOSED        = 'closed';
public const COVERAGE_CONFIRMED     = 'confirmed';
public const COVERAGE_SEVERITY = [                 // → Bootstrap alert/colour class
    self::COVERAGE_UNAVAILABLE   => 'danger',
    self::COVERAGE_NO_BOOKING    => 'danger',
    self::COVERAGE_OUTSIDE_HOURS => 'warning',
    self::COVERAGE_UNCONFIRMED   => 'warning',
    self::COVERAGE_CLOSED        => 'warning',
    self::COVERAGE_CONFIRMED     => 'success',
];

// Colour fallback when tblVenueStatuses.color IS NULL (keyed by statusCategory):
public const CATEGORY_COLORS = [
    'standing' => '#198754', 'agreed' => '#198754',       // confirmed greens
    'proposed' => '#ffc107',                              // amber
    'rejected' => '#dc3545', 'unavailable' => '#dc3545',  // red
];
public const KIND_COLORS = ['closed' => '#6c757d', 'unavailable' => '#dc3545'];

// Seed vocabularies (data model §3.5 table + §4) — used by seedStatuses()/seedUsageTypes():
private const DEFAULT_STATUSES = [ /* the six rows: name, category, countsAsConfirmed, isAvailable, sortOrder */ ];
private const DEFAULT_USAGE_TYPES = [ /* six types + usageKind + default window (or null) per data model §4 */ ];

public const AGREEMENT_FILE_MIME_EXT = [
    'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];
```

### 3.2 Method surface (signature + one-line contract; ✎ = funnels through `self::audit()`)

**Gate & seeding**
- `public static function canManage(): bool` — `App::isAdmin() === true || App::hasRole('venue_manager') === true`. The ONE capability check every manager handler calls (App.php l.335/l.388).
- `public static function seedStatuses(int $siteId): void` — idempotent: for each of the six `DEFAULT_STATUSES`, INSERT only when no row with that `statusName` exists for `$siteId` (check-first prepared SELECT; `uq_venst_site_name` is the race backstop, duplicate caught + ignored). Cheap short-circuit: `SELECT 1 … LIMIT 1` for the site first. Called from `index.php` and `statuses.php` (the two entry points) — Q8. **No audit rows** for seeding (system bootstrap, not a user mutation).
- `public static function restoreDefaultStatuses(int $siteId, int $actorUserId): int` ✎(one summary row, tableName `tblVenueStatuses`, recordId 0, action `create`, newData `{restored:[names]}`) — re-inserts any missing defaults AND re-activates (`isActive=1`) any default-named row previously retired; returns count touched. Admin-restore path of Q8.
- `public static function seedUsageTypes(int $siteId, int $venueId, int $actorUserId): void` — at venue creation: the six default types, each `usageKind` per data model §4, plus one `tblVenueUsageTypeWindows` row at `effectiveFrom='1970-01-01'` for the kinds that carry windows (Regular 09:30-13:30, Extended 09:00-15:00, Extended (All Day) 09:30-17:30; Custom/closed/unavailable get NULL-times windows or none). No audit (bundled inside the venue-create audit row's newData as `seededUsageTypes: 6`).

**Audit choke-point**
- `public static function audit(string $tableName, int $recordId, string $action, ?array $old, ?array $new, ?int $userId = null): void` — thin wrapper over `Logger::audit()` (Logger.php l.111): resolves `$userId` from session when null, passes through. EVERY venue mutation below funnels here (one call site per mutation). Bulk ops write ONE row: generator → `tblVenueBookingGroups`, import commit → `tblVenueImportBatches` (data model §1.5/§10). No redaction needed (no secret columns exist by design).

**Venues / rooms**
- `listVenues(int $siteId, bool $activeOnly = false): array` — joined with `tblAssetOrgs` (`orgName AS landlordName`), ordered venueName.
- `getVenue(int $venueId, int $siteId): ?array` — site-scoped single fetch (landlord join included).
- `saveVenue(int $siteId, int $venueId, array $data, int $actorUserId): int` ✎ — 0=create; validates venueName non-empty, `landlordOrgID` (when >0) exists with `siteID = $siteId` in `tblAssetOrgs`, `timezone` via `new \DateTimeZone()` try/catch → fallback `'Europe/London'` (sabbath idiom l.47-51), `countryCode` 2-alpha. On create: calls `seedUsageTypes()`. Returns id or 0.
- `toggleVenueActive(int $venueId, int $siteId, int $actorUserId): bool` ✎.
- `deleteVenue(int $venueId, int $siteId, int $actorUserId): array{ok: bool, error: ?string}` ✎ — HARD delete, caller must be admin; PHP-guard refuses when any `tblVenueBookings`/`tblVenueInvoices`/`tblVenueAgreements` row references it (despite CASCADE — history must be retired via isActive, not silently destroyed).
- `listRooms(int $venueId, int $siteId, bool $activeOnly = false): array`
- `saveRoom(int $siteId, int $venueId, int $roomId, array $data, int $actorUserId): int` ✎ — validates venue belongs to site; roomName unique per venue (`uq_venrm_venue_name` catch → friendly error).
- `toggleRoomActive(int $roomId, int $siteId, int $actorUserId): bool` ✎
- `deleteRoom(int $roomId, int $siteId, int $actorUserId): array{ok, error}` ✎ — refuses while any non-deleted booking with `bookingDate >= CURDATE()` references the room (data model §3.7 note); past-only rooms may be deleted (bookings' `roomID` SET NULL) or retired.

**Usage types & windows**
- `listUsageTypes(int $venueId, int $siteId, bool $activeOnly = false, ?string $forDate = null): array` — each row decorated with its resolved window for `$forDate` (default today).
- `saveUsageType(int $siteId, int $venueId, int $usageTypeId, array $data, int $actorUserId): array{id: int, error: ?string}` ✎ — validates venue∈site, `usageKind ∈ USAGE_KINDS`, **keeps `isBookable` in lockstep: `isBookable = (usageKind === 'hire') ? 1 : 0`** (the data-model §3.3 sync contract — the column is never user-posted). GUARD: changing `usageKind` on a type referenced by any booking is refused (`error='in-use — create a new type instead'`) so history is never silently reclassified.
- `toggleUsageTypeActive(int $usageTypeId, int $siteId, int $actorUserId): bool` ✎
- `saveWindow(int $siteId, int $usageTypeId, array $data, int $actorUserId): array{id, error}` ✎ — inserts a `tblVenueUsageTypeWindows` row; validates `effectiveFrom` date, times pair (both or neither; end > start), duplicate `(usageTypeID, effectiveFrom)` → friendly error (never ON DUPLICATE silently).
- `deleteWindow(int $windowId, int $siteId, int $actorUserId): bool` ✎
- `resolveWindow(int $usageTypeId, string $date): ?array{start: ?string, end: ?string}` — **THE data-model §3.4 resolution rule, exactly:** `SELECT defaultStartTime, defaultEndTime FROM tblVenueUsageTypeWindows WHERE usageTypeID = ? AND effectiveFrom <= ? ORDER BY effectiveFrom DESC LIMIT 1`. No row ⇒ null (times manual). Note the signature the brief asked for as `resolveWindow(venueId, usageTypeId, date)` — venueId is redundant (the type carries its venue) and is therefore validated by the CALLER (`usageType.venueID == booking.venueID`), keeping this a pure single-query helper.
- `reapplyWindowDefaults(int $siteId, int $usageTypeId, string $fromDate, int $actorUserId): int` ✎(one summary row) — for future non-deleted bookings of this type with `timesOverridden = 0` and `bookingDate >= $fromDate`: set `startTime`/`endTime` to `resolveWindow()` for **each booking's own date**. Returns rows updated. (The "re-apply changed defaults" tool data model §3.4 promised.)

**Statuses**
- `listStatuses(int $siteId, bool $activeOnly = false): array` — ordered sortOrder.
- `saveStatus(int $siteId, int $statusId, array $data, int $actorUserId): array{id, error}` ✎ — validates name non-empty/≤150, `statusCategory ∈ STATUS_CATEGORIES`, flags 0/1, `color` NULL or `/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/`, name-unique per site (uq catch → error).
- `toggleStatusActive(int $statusId, int $siteId, int $actorUserId): bool` ✎ — retire, never delete (booking FK is RESTRICT by design).
- `statusColor(array $row): string` — `$row['color']` when valid hex, else `CATEGORY_COLORS[$row['statusCategory']]`, else `#6c757d`.

**Bookings**
- `getBooking(int $bookingId, int $siteId): ?array` — the §8.1 joined row (+ group + agreement + event name).
- `listBookings(int $siteId, array $filters): array` — filters: `venueID`, `roomID`, `dateFrom`/`dateTo` (or `year` sugar), `statusID`, `usageTypeID`, `groupID`, `includeRejected` (default true — the schedule LISTS rejections; the calendar overlay hides them), `includeDeleted` (default false). Ordered `bookingDate, startTime`. Serves index.php, export.php, schedule-pdf.php.
- `saveBooking(int $siteId, int $bookingId, array $data, int $actorUserId): array{id: int, errors: string[]}` ✎ — THE invariant enforcement point; full checklist in §4.0. Time resolution: when usage type is `hire`-kind and both posted times are blank → `resolveWindow(usageTypeID, bookingDate)`; applied ⇒ `timesOverridden=0`; posted times equal to resolved default ⇒ 0, else 1; non-hire kinds force NULL times + 0. `endTime > startTime` PHP-enforced when both set (Q4 — no cross-midnight v1).
- `softDeleteBooking(int $bookingId, int $siteId, int $actorUserId): bool` ✎
- `setGroupStatus(int $groupId, int $siteId, int $statusId, int $actorUserId): int` ✎(one summary row on the group) — validates status∈site, group∈site; updates every non-deleted row of the group; returns count.
- `softDeleteGroup(int $groupId, int $siteId, int $actorUserId): int` ✎(one summary row).

**Generator (§3.4 below for the algorithm)**
- `previewSeries(int $siteId, int $venueId, array $params): array{dates: string[], skipped: string[], errors: string[]}` — pure expansion + duplicate probe, NO writes.
- `generateSeries(int $siteId, int $venueId, array $params, int $actorUserId): array{groupID: int, created: string[], skipped: string[], errors: string[]}` ✎(ONE row: tableName `tblVenueBookingGroups`, action `create` — or `update` when extending — newData `{params, dates, skipped, count}`) — transactional (`begin_transaction`/`commit`, rollback on any insert failure).

**Import (§4.5 for the wizard; parse specifics in data model §11)**
- `createImportBatch(int $siteId, int $venueId, string $fileName, string $fileHash, string $sourceKind, int $actorUserId): int`
- `parseWorkbook(string $tmpPath, string $sourceKind): array` — returns `{sheets: [{name, year|null, rows: [{rowNum, rawDate, rawHours, rawTimes, rawStatus, rawNotes}]}], backingData: {statuses: [...], usageWindowsByYear: {...}}}`; throws `\RuntimeException` with a user-safe message on malformed input. XLSX via `ZipArchive` + `SimpleXML` with the §12 hardening caps; CSV via `fgetcsv` (headered DATE/HOURS/TIMES/NOTES/STATUS, order-free by header match).
- `stageRows(int $batchId, int $siteId, int $venueId, array $parsed): array{staged: int, unknownHours: string[], unknownStatuses: string[]}` — inserts `tblVenueImportRows` applying data-model §11.1 parse rules (Excel serial epoch 1899-12-30, sanity window 2000-2100, times regex + sentinel set, CI vocab matching); writes the initial `vocabMap` JSON + counts onto the batch, status → `'mapping'`.
- `applyVocabMap(int $batchId, int $siteId, array $resolutions, int $actorUserId): array{resolved: int, created: int}` — for each `create` resolution inserts the vocab row (usage type prompts usageKind + window; status prompts category + flags — both via `saveUsageType`/`saveStatus` so invariants + audit apply); updates `mappedUsageTypeID`/`mappedStatusID` on matching rows; re-evaluates `rowState` (`pending`→`ready` when both mapped + date parsed); persists updated `vocabMap`.
- `commitBatch(int $batchId, int $siteId, int $actorUserId): array{imported: int, skipped: int, errors: int}` ✎(ONE row on the batch) — refuses unless batch∈site, status `mapping`, zero `pending` rows; transaction: for each `ready` row insert `tblVenueBookings` (times per §11.1 — resolveWindow fallback with `timesOverridden=0`), stamp `importedBookingID`, set batch `committed`/`committedAt` + counts.
- `abandonBatch(int $batchId, int $siteId, int $actorUserId): bool`
- `importBackingDataWindows(int $batchId, int $siteId, int $venueId, array $accepted, int $actorUserId): int` — creates `tblVenueUsageTypeWindows` rows from accepted BackingData year-columns with `effectiveFrom = 'YYYY-01-01'` (data model §11.2), via `saveWindow()`.

**Agreements**
- `listAgreements(int $siteId, array $filters): array` (`venueID`, `status`, `renewalWithinDays`).
- `getAgreement(int $agreementId, int $siteId): ?array` (+ files list).
- `saveAgreement(int $siteId, int $agreementId, array $data, int $actorUserId): array{id, errors}` ✎ — venue∈site; `agreementType`/`status`/`rateUnit` enum-validated; `termStart <= termEnd` when both; pence int ≥ 0.
- `supersedeAgreement(int $oldAgreementId, int $siteId, array $newData, int $actorUserId): array{newId, errors}` ✎(two rows: create new + update old) — creates the renewal, sets old `status='superseded'`, `supersededByID = newId`.
- `attachAgreementFile(int $siteId, int $agreementId, array $file, string $title, int $actorUserId): array{fileID: int, error: ?string}` ✎ — §12 upload pipeline; stores under `_uploads/venues/agreements/`.
- `deleteAgreementFile(int $fileId, int $siteId, int $actorUserId): bool` ✎ — unlinks disk file after row delete.

**Invoices & payments (pure outgoing ledger — Q3: NO tblPayment linkage anywhere)**
- `listInvoices(int $siteId, array $filters): array` — + computed `paidPence` (SUM of payments) per row; totals helper for the ledger footer.
- `getInvoice(int $invoiceId, int $siteId): ?array` — + lines (joined booking date/notes) + payments.
- `saveInvoice(int $siteId, int $invoiceId, array $data, int $actorUserId): array{id, errors}` ✎ — venue∈site; agreement (when set) belongs to the same venue; `amountPence` int > 0; `issueDate` valid; optional single file via the §12 pipeline into `_uploads/venues/invoices/`.
- `setInvoiceStatus(int $invoiceId, int $siteId, string $status, int $actorUserId): bool` ✎ — manual `disputed`/`cancelled` only; the paid-family is machine-managed.
- `deleteInvoice(int $invoiceId, int $siteId, int $actorUserId): array{ok, error}` ✎ — admin-only caller; refuses when payments exist.
- `allocateInvoiceLine(int $siteId, int $invoiceId, int $bookingId, ?int $amountPence, int $actorUserId): array{lineID, error}` ✎ — booking must belong to the invoice's venue+site; duplicate pair → friendly error (`uq_venil_invoice_booking`).
- `removeInvoiceLine(int $lineId, int $siteId, int $actorUserId): bool` ✎
- `recordPayment(int $siteId, int $invoiceId, array $data, int $actorUserId): array{payID, errors}` ✎ — `amountPence` int > 0, `paidDate` valid, `method ∈ PAYMENT_METHODS`; then `recomputeInvoiceStatus()`.
- `deletePayment(int $payId, int $siteId, int $actorUserId): bool` ✎ — then `recomputeInvoiceStatus()`.
- `recomputeInvoiceStatus(int $invoiceId): string` — leaves `disputed`/`cancelled` untouched; else SUM(payments): 0 → `pending`, < amountPence → `part-paid`, ≥ amountPence → `paid` (+ stamp `paidAt = NOW()` on the transition into paid; clear `paidAt` on leaving). Machine-only status writes bypass audit (the payment row IS the audited event).

**Calendar / conflict engine (§3.5)**
- `availabilityForRange(int $siteId, ?int $venueId, string $dateFrom, string $dateTo): array` — the data-model §8.1 query verbatim, returned **keyed by date**: `['YYYY-MM-DD' => [row, …]]`. Each row decorated with `resolvedColor` (statusColor / KIND_COLORS) + `isRejected` convenience flag. THE overlay data source.
- `classifyEventCoverage(array $event, int $venueId): array` — §3.5.2 algorithm; returns `{classification, severity, message, perDay: [{date, classification, rows}]}`.
- `coverageMessage(string $classification, array $context): string` — the i18n-keyed human message (§10).

**Reminder query helpers (§6 consumes)**
- `dueUnagreedBookings(int $siteId, int $leadDays): array` — §9 row 1 predicate (`ut.isBookable=1 AND st.countsAsConfirmed=0 AND st.statusCategory <> 'rejected' AND b.isDeleted=0 AND b.bookingDate BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY`).
- `dueAgreementRenewals(int $siteId, int $leadDays): array` — active agreements with `renewalDate` in window OR (`termEnd IS NOT NULL AND DATE_SUB(termEnd, INTERVAL COALESCE(noticePeriodDays,0) DAY)` in window); each row tagged with which trigger fired + the `dueDate` to log (`renewalDate` or `termEnd`).
- `dueInvoices(int $siteId, int $leadDays): array` — `status IN ('pending','part-paid') AND dueDate IS NOT NULL AND dueDate <= CURDATE() + INTERVAL ? DAY` (includes overdue).
- `reminderAlreadySent(string $refType, int $refId, string $dueDate): bool` / `logReminder(int $siteId, string $refType, int $refId, string $dueDate, int $recipientCount): void` — check-first + `uq_venrl_ref` duplicate-catch (asset cron l.148-180 shape).
- `resolveReminderRecipients(int $siteId): array` — §6.3 SQL; returns validated, de-duplicated emails.

**Private helpers** — `db()`, `parseTimeRange(string): ?array` (the §11.1 regex + sentinels), `excelSerialToDate(float): ?string` (epoch 1899-12-30, window 2000-2100), `venueTimezone(array $venue): \DateTimeZone` (try/catch → Europe/London), `validateStatusForSite(int, int): ?array`, `validateUsageTypeForVenue(int, int, int): ?array`, `validateRoomForVenue`, `validateEventForSite`, `validateAgreementForVenue`, `nextSortOrder`, `fetchAll(stmt)`.

### 3.3 Landlord CRUD = delegation, not duplication (Q1 resolved)

Venues does **not** own org SQL. `venues/manage.php` + `venue-save.php` call `AssetRegister::listOrgs($siteId, true)` (l.3529) and `AssetRegister::saveOrg($siteId, 0, $data, $userId)` (l.3570) directly. Verified contract this session: plain always-loaded core class (works with the Assets APP toggled off — app toggles are settings-only, classes always autoload); returns new/existing `orgID` int, `0` on blank `orgName`; soft-validates email via `FILTER_VALIDATE_EMAIL` (silently NULLs invalid); logs via `Logger::activity('AssetOrgSaved', …)` l.3611/3628 — site-level reference data, **no asset-scoped audit, no phantom assetID** (the venue-safety condition data model §1.1 required — confirmed). Full org management (edit/toggle) deep-links to `/assets/orgs` when the Assets app is enabled; when it is disabled, the venue form's inline "new landlord" fields are the supported creation path and edits are done by re-posting the same fields through `saveOrg()` with the existing `orgId` from `venue-save.php`'s `landlord_edit` action (§4.2).

### 3.4 `generateSeries` — exact algorithm (Q5 duplicate-skip)

Inputs (`$params`): `groupType ∈ GROUP_TYPES`, `frequency ∈ FREQUENCIES` (recurring only), `intervalVal` (int 1-52; UI hides it for `fortnightly`, stored 2), `daysOfWeek` (array of ints 0=Sun..6=Sat — same encoding as `tblRecurrenceRules.dayOfWeek`), `dateFrom`, `dateTo`, `roomID?`, `usageTypeID`, `statusID`, `notes?`, `label?`, `extendGroupID?`.

1. **Validate**: venue∈site+active; usageType∈venue (+active); status∈site (+active); room∈venue when set; `dateFrom <= dateTo`; **range cap: `dateTo - dateFrom <= 366 days` AND expanded date count ≤ 200** (bounds the transaction; friendly error otherwise); for `recurring`+`weekly|fortnightly`: non-empty `daysOfWeek ⊆ {0..6}`; `custom`: explicit `dates[]` list (each `Y-m-d`, within range).
2. **Expand** candidate dates:
   - `multi-day`: every date in `[dateFrom, dateTo]`.
   - `weekly`/`fortnightly`: iterate days in range; include when `(int)date('w') ∈ daysOfWeek` AND `floor(daysSince(mondayOfWeek(dateFrom)) / 7) % intervalVal === 0` (fortnightly ⇒ intervalVal 2; anchor = the ISO week containing `dateFrom`).
   - `monthly`: the `dateFrom` day-of-month in each month of the range; months lacking that day (e.g. 31st) are skipped and reported in `errors`.
   - `custom`: the validated explicit list.
3. **Duplicate probe** per candidate date (Q5): `SELECT 1 FROM tblVenueBookings WHERE venueID = ? AND bookingDate = ? AND isDeleted = 0 AND roomID <=> ? LIMIT 1` — **NULL-safe `<=>`** so "whole venue" collides only with "whole venue" and room-N only with room-N. Hit ⇒ date moves to `skipped`.
4. **Preview** (`previewSeries`) stops here and returns `{dates, skipped, errors}` — generate.php's preview step. Commit **re-runs steps 1-3 server-side from the posted params** (never trusts a client-posted date list).
5. **Commit** (transaction): insert-or-update the group row — new group when `extendGroupID` absent (`groupType`, params, `dateFrom`, `dateTo`); when extending, validate group∈site+venue then keep its identity and `UPDATE … SET dateTo = ?` — then one `tblVenueBookings` INSERT per surviving date with `groupID`, `timesOverridden = 0` and `startTime/endTime = resolveWindow(usageTypeID, <that generated date>)` (**per-date resolution** — a series crossing a window's `effectiveFrom` picks up the new default from that date, data model §6). One `audit()` row on the group (`create`, or `update` when extending) with `newData = {params, dates, skipped, count}`.
6. Return `{groupID, created, skipped, errors}` — the handler flashes "N created, M skipped (already booked): …" listing skips.

### 3.5 Calendar/conflict engine — exact algorithms

**3.5.1 `availabilityForRange`** — the data-model §8.1 SQL verbatim (site-scope + `isDeleted = 0` + `bookingDate BETWEEN ? AND ?` + optional venue), served by `idx_venbk_site_date`/`idx_venbk_venue_date`, then PHP-grouped by `bookingDate` and decorated (`resolvedColor`, `isRejected = (isBookable==1 && statusCategory=='rejected')`). Overlay callers filter `isRejected` client-side per §8.1's note; the schedule view keeps them.

**3.5.2 `classifyEventCoverage(array $event, int $venueId): array`** — spell-out (build exactly this):

```
IN:  $event = ['startDateTime' => 'Y-m-d H:i:s', 'endDateTime' => ?'Y-m-d H:i:s',
               'timezone' => ?IANA]   // tblEvents columns; endDateTime null ⇒ start + 1h
     $venueId (already validated ∈ Site::id() by the caller)

1. $venue = getVenue($venueId, Site::id()); null ⇒ ['classification' => 'no-booking', severity danger,
   message venues.coverage.venue_missing] (defensive — callers pre-validate).
2. Timezone conversion (data model §8.4 — the make-or-break rule):
      $srcTz = new DateTimeZone($event['timezone'] ?: 'Europe/London');   // guard try/catch
      $venTz = venueTimezone($venue);                                     // tblVenues.timezone
      $start = (new DateTimeImmutable($event['startDateTime'], $srcTz))->setTimezone($venTz);
      $end   = endDateTime ? same conversion : $start->modify('+1 hour');
      if ($end <= $start) → single-instant treated as [start, start+1h].
   NOTE (stage-03 verify item): tblEvents.startDateTime's column comment says "stored in UTC" but
   manage/save.php l.215-224 stores the datetime-local POST verbatim — i.e. wall clock in the form's
   `timezone` field. Interpreting via $event['timezone'] (NOT forcing UTC) matches what is actually
   stored today; when both tz's are Europe/London the conversion is identity. Document in the
   class header; add a DST-crossing test to the stage-03 plan.
3. Enumerate venue-local dates D touched: from $start's date to $end's date inclusive,
   HARD CAP 31 days (an event longer than a month classifies its first 31 days; note in perDay).
4. $days = availabilityForRange(siteId, $venueId, D_first, D_last).
5. Per date D, with local window [ls, le] = (D==first ? start-time : 00:00) .. (D==last ? end-time : 24:00),
   FIRST MATCH WINS (the §8.2 precedence, verbatim):
     a. any row usageKind='unavailable'                        ⇒ UNAVAILABLE
        (+ dataConflict flag when a countsAsConfirmed hire row coexists that day)
     b. any row countsAsConfirmed=1 AND isBookable=1 AND startTime/endTime NOT NULL
        AND startTime <= ls AND endTime >= le                  ⇒ CONFIRMED
        // v1 venue-wide: roomID ignored (Q12 header note — tighten to room-aware
        // (b.roomID IS NULL OR b.roomID = event.roomID) if events gain rooms)
     c. any countsAsConfirmed=1 AND isBookable=1 row exists (window not covering, or NULL times)
                                                              ⇒ OUTSIDE_HOURS
     d. any row usageKind='closed'                             ⇒ CLOSED
     e. any row isBookable=1 AND countsAsConfirmed=0 AND statusCategory <> 'rejected'
                                                              ⇒ UNCONFIRMED
     f. else (no rows, or rejected-only rows — attach them as info)  ⇒ NO_BOOKING
6. Event classification = the WORST per-day result, worst-first order:
   UNAVAILABLE > NO_BOOKING > OUTSIDE_HOURS > UNCONFIRMED > CLOSED > CONFIRMED.
7. severity = COVERAGE_SEVERITY[classification]; message = coverageMessage() (i18n §10,
   includes venue name, the failing date(s), and for OUTSIDE_HOURS the booked window vs event window).
OUT: {classification, severity, message, perDay: [{date, classification, rows}], dataConflict: bool}
```

**3.5.4 Timezone rule (restated, non-negotiable):** booking rows are wall-clock venue-local `DATE`+`TIME`, never converted to UTC in storage or display; the ONLY conversion anywhere is step 2 above. Never "fix" bookings to UTC (data model §8.4; class-header documentation mandatory).

---

## 4. Handlers — file-by-file (`web/_apps/venues/`)

### 4.0 Conventions applying to EVERY handler (stated once, MANDATORY everywhere)

- File header comment block: path, description, `@package Portal\Venues`, `@author MWBM Partners Ltd (t/a MWservices)`, `@copyright 2025-present …`, `@license All Rights Reserved`, `@version 1.0.0`, `@link` the venues GitHub issue (#VEN — stage 03 substitutes). `declare(strict_types=1);` first statement. Emoji-annotated section comments. Full IF notation everywhere.
- Shell: `Auth::ensureSession(); Auth::requireLogin();` then the gate — **viewer** pages: nothing further; **manager** pages: `if (Venues::canManage() !== true) { Router::renderError(403); return; }` (orgs.php l.43-46 shape); **admin-only**: `App::isAdmin()`.
- POST handlers: CSRF **first**, before any side effect: `if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { flash danger + redirect; exit(); }` (save.php l.38-43). Non-POST request to a `-save` handler ⇒ redirect to its parent page (save.php l.33-36). Flash + `header('Location: …'); exit();` after every mutation (PRG).
- **Site-scoping + cross-tenant invariants (Q7 — the hard gate):** every entity id arriving via GET/POST is re-fetched **with `siteID = Site::id()` in the WHERE clause** before use; absent ⇒ 404 (`Router::renderError(404)`) — never a cross-site oracle. On booking writes, `Venues::saveBooking()` additionally validates, each via site-scoped SELECT: `venue.siteID == Site::id()` + `venue.isActive` (create), `status.siteID == booking.siteID` (+`isActive=1` when the status is being *changed*; an existing booking may keep a retired status), `usageType.venueID == booking.venueID` (+ same for isActive on change), `room.venueID == booking.venueID`, `event.siteID == booking.siteID AND isDeleted=0`, `agreement.venueID == booking.venueID`, `group.venueID == booking.venueID`. Analogous per-save checks are listed per handler below. **MySQLi prepared statements only. `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on ALL output.** Data lists use `portal-data-list` (orgs.php l.163-205) — **no `<table>`** anywhere, including PDF HTML.
- All mutations call the relevant `Venues::` method (which audits); handlers never write venue tables directly. Page-level `Logger::activity()` calls: import commit, generator commit, venue create, invoice/payment record (activity type strings `VenueBookingSaved`, `VenueSeriesGenerated`, `VenueImportCommitted`, `VenueInvoicePaid`, … — mirror `EventCreated` style).

### 4.1 Schedule & venue views

**`venues/index.php`** — GET, viewer. THE landing page: the year schedule, spreadsheet-shaped.
- Calls `Venues::seedStatuses(Site::id())` first (Q8 first-access seeding — idempotent, cheap short-circuit).
- Query params: `venue` (int; default: the site's only active venue, else a required select), `year` (default current year), `status`, `type` (usageTypeID), `rejected=0|1` (default 1 — shown), `room`.
- Data: `Venues::listBookings()` for `[year-01-01, year-12-31]`; `listVenues()` for the switcher; `listStatuses()`/`listUsageTypes()` for filter selects. Year select = `SELECT DISTINCT YEAR(bookingDate)` for the venue ∪ {currentYear, currentYear+1}.
- Render: month-grouped `portal-data-list` — columns Date · Day · Usage type · Times (`—` for NULL; badge `times needed` when hire-kind + NULL) · Status (badge, `background: resolvedColor`) · Notes · (manager only: Cost, Edit link). "Today" row highlighted + `#today` anchor. Empty-state card linking manage/generate/import (manager) or "no bookings yet" (viewer).
- Toolbar (manager): **+ Booking** (`/venues/booking?venue=N&date=`), **Generate series**, **Import**, **Agreements**, **Invoices**, **Config ▾** (statuses/usage-types/rooms/manage/settings). Viewer toolbar: **CSV** + **PDF** export links only. A "times needed" post-import checklist is this page filtered by the badge (link target from the import wizard's done step).

**`venues/venue.php`** — GET `?id=`, viewer (manager sees action buttons + money).
- 404 unless venue∈site. Cards: identity (address, timezone, caretaker, landlord org name + contact via the `listVenues` join), rooms (active, with capacity), usage types (name, kind badge, **current** resolved window + count of future window changes), agreements summary (active/draft, renewal countdown badge — manager only), next 10 bookings (mini list), links to schedule filtered to this venue.

### 4.2 Venue admin

**`venues/manage.php`** — GET, manager. Venue list (`portal-data-list`: name, landlord, active badge, booking count, actions Edit/Toggle) + inline create/edit form via `?edit=ID` (orgs.php l.84-99 prefill pattern), posting to `venue-save`.
- Form fields: venueName*, landlordOrgID select (from `AssetRegister::listOrgs($siteId, true)`, first option "— none —") **plus a collapsible "New landlord…" fieldset** (`landlord_orgName`, `landlord_contactName`, `landlord_contactEmail`, `landlord_contactPhone`, `landlord_agreementRef`) — §3.3; address lines/city/region/postcode/countryCode, timezone (datalist of `DateTimeZone::listIdentifiers()`), caretakerName/Phone, notes. Helper text under notes: **"Never store alarm codes or key-safe numbers here — attach them to a hire agreement document instead"** (data model §3.1 column comment).
- When the Assets app is enabled (`AppRegistry::isEnabled('assets')`), show a "Manage organisations →  /assets/orgs" link; otherwise the inline fieldset (create) + `landlord_edit` action (below) are the full flow.

**`venues/venue-save.php`** — POST-only, manager. CSRF-first. Actions:
- `save`: if `landlord_orgName` non-empty → `AssetRegister::saveOrg($siteId, 0, [...], $userId)` → non-zero result becomes `landlordOrgID` (0 ⇒ flash danger "Landlord name required", stop). Then `Venues::saveVenue()` (validates chosen `landlordOrgID` ∈ site). On create, flash success + redirect to `/venues/usage-types?venue=N` with "Default usage types seeded — review the time windows for this venue" (this replaces the data-model §4 "tweak before saving" inline UI: same outcome, one screen later, far simpler form — deliberate simplification, document in code comment).
- `landlord_edit`: `orgID` + the same landlord_* fields → validate org∈site (`SELECT 1 FROM tblAssetOrgs WHERE orgID=? AND siteID=?`) → `AssetRegister::saveOrg($siteId, $orgId, …)`. Keeps landlord edits working with Assets disabled.
- `toggle`: `Venues::toggleVenueActive()`.
- `delete`: **admin-only** (`App::isAdmin()`), calls `Venues::deleteVenue()` — refusal reason flashed. Confirm via `data-confirm` attribute (the #372 native-`confirm()` sweep — `check_no_native_confirm.py` enforces).

**`venues/rooms.php`** — self-posting CRUD (orgs.php shape), manager. Requires `?venue=N` (404 unless venue∈site). Actions `save` (roomName*, description, capacity int≥0, sortOrder), `toggle`, `delete` (refusal reason from `Venues::deleteRoom` flashed). List shows a "future bookings" count per room so the delete guard is predictable.

**`venues/usage-types.php`** — self-posting, manager. `?venue=N` (404 unless ∈site).
- List: type name, `usageKind` badge (hire=primary / closed=secondary / unavailable=danger), active badge, current window, expandable window history (effectiveFrom · times · note · delete button), booking count.
- Actions: `save-type` (typeName*, usageKind select — **locked with an explanatory tooltip when bookings reference the type**, sortOrder) → `Venues::saveUsageType()`; `toggle-type`; `add-window` (effectiveFrom* date, start/end times — both-or-neither, note) → `saveWindow()`; `delete-window`; `reapply` (usageTypeID + fromDate, `data-confirm`) → `reapplyWindowDefaults()` → flash "N future bookings updated".
- Info callout explaining effective-dating: *"A new window applies to bookings dated on/after its start; existing bookings keep their saved times unless you Re-apply."*

**`venues/statuses.php`** — self-posting, manager, per-SITE (no venue param). Calls `seedStatuses()` on GET (second Q8 entry point).
- List: name, category badge, `countsAsConfirmed` ✓, `isAvailable` ✓, colour swatch, sortOrder, active toggle, booking count.
- Actions: `save` (statusName*, statusCategory select, two checkboxes, `<input type="color">` + "use category default" clear-checkbox, sortOrder) → `Venues::saveStatus()`; `toggle`; `restore` (`data-confirm`) → `restoreDefaultStatuses()` → flash "N default statuses restored" (Q8 admin restore).
- Callout: *"'Counts as confirmed' is what turns a booking green on the calendar and silences the 'is it booked?' warning — only tick it for statuses that mean the hire is actually secured."* (decision #4 surfaced to the admin).

### 4.3 Booking editor

**`venues/booking.php`** — GET, viewer(read-only)/manager(form). Modes: `?id=N` (existing — 404 unless ∈site) or `?venue=N[&date=Y-m-d]` (create prefill; venue∈site).
- Non-manager: read-only detail card (no cost), back link. Manager: form → `booking-save`.
- Fields: venueID hidden (locked); roomID select **only when the venue has ≥1 active room** (else omitted entirely — single-space degradation, data model §3.2), first option "Whole venue"; bookingDate*; usageTypeID select grouped hire/closed/unavailable; startTime/endTime (`type="time"`) with helper text showing the type's default window for the chosen date; statusID select (active statuses + the row's current status even if retired); notes textarea; eventID select (events on ±1 day of bookingDate, site-scoped, `isDeleted=0` — optional link, includes current linked event); agreementID select (venue's non-superseded agreements); costPence as a decimal `cost` input (pounds; converted ×100 server-side), currency (default `venues.currency`).
- Progressive enhancement: a `<script>` block embeds `{usageTypeID: {kind, windows: [{from, start, end}]}}` JSON (json_encode, escaped); on type/date change JS pre-fills times from the client-side-resolved window and disables time inputs for non-hire kinds. Server resolution in `saveBooking()` remains authoritative — no-JS works.
- Group panel (when `groupID` set): label, params summary, links "Set status for whole run", "Delete whole run" (both POST forms to `booking-save` with `data-confirm`), "Extend series" → `/venues/generate?group=N`.
- Danger zone (edit): soft-delete button (`data-confirm`) — helper text *"Cancellations should normally be a status change; delete only true mistakes"* (data model §5).

**`venues/booking-save.php`** — POST-only, manager. CSRF-first. Actions:
- `save` → `Venues::saveBooking()` (ALL §4.0 invariants + time resolution inside); `errors` non-empty ⇒ flash danger with the joined messages + redirect back to the form (id or venue+date preserved); success ⇒ redirect `/venues?venue=N&year=YYYY#d-YYYY-MM-DD`.
- `delete` → `softDeleteBooking()`.
- `group-status` (`groupID`, `statusID`) → `setGroupStatus()` — flash "Status applied to N bookings".
- `group-delete` (`groupID`) → `softDeleteGroup()`.

### 4.4 Recurring generator

**`venues/generate.php`** — GET+POST, manager. Single route, two-step (preview → commit), mirroring the resources conflict-probe self-post shape (book.php l.52-130) scaled up.
- GET: form — venue select (∈site), mode radio (`recurring` | `multi-day` consecutive run), frequency select (weekly/fortnightly/monthly/custom — custom reveals a one-date-per-line textarea), daysOfWeek checkboxes (Sun..Sat, the tblRecurrenceRules 0..6 encoding), dateFrom*/dateTo*, room select (when rooms exist), usageTypeID*, statusID* (default: the site's `standing`-category status if present, else first by sortOrder — matching the "most rows are Standard Agreement weekly worship" reality), notes, label. `?group=N` (extend mode): validate group∈site, prefill every param from the group row, `dateFrom = group.dateTo + 1 day`, hidden `extendGroupID`, banner "Extending series: {label}".
- POST `action=preview`: `Venues::previewSeries()` → re-render form (values retained) + a preview card: **N will be created** (date list, weekday-annotated), **M skipped — already booked** (dates + what occupies them), errors. Hidden re-submit of the raw params + `action=commit` button (+ back-to-edit).
- POST `action=commit`: `Venues::generateSeries()` (re-validates + re-expands server-side — the preview list is never trusted); flash success "Created N bookings (M skipped: …)"; redirect `/venues?venue=N&year=…`.

### 4.5 Import wizard

**`venues/import.php`** — GET+POST, manager. ONE route, staged in `tblVenueImportBatches`/`tblVenueImportRows` (data model §3.13/§3.14), POST actions like assets/import.php l.516-727. Steps keyed off batch `status`:

- **Step 1 — upload** (GET without `?batch`): venue select*, file input (`accept=".xlsx,.csv"`), guidance panel: expected columns DATE/HOURS/TIMES/NOTES/STATUS, one sheet per year + optional BackingData, and **the advertised CSV fallback** (Q9): *"If the Excel upload fails on this server, save each year's sheet as CSV and upload those instead — CSV always works."* If `class_exists('ZipArchive') === false`, the XLSX option is disabled up-front with that message shown as a warning.
- **POST `action=upload`**: CSRF; venue∈site; `$_FILES` error check; **size cap `venues.maxFileSize`** (missing/zero ⇒ 10 MB fallback, never unlimited — resource-save.php convention); finfo-sniff (never client type): xlsx ⇒ sniffed `application/zip` or `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` **and** `.xlsx` extension; csv ⇒ sniffed `text/csv`/`text/plain`/`application/csv` and `.csv`. SHA-256 of raw bytes; a prior **committed** batch on this site with the same hash ⇒ warning banner carried into step 2 ("This exact file was already imported on {date}") — soft guard only (data model §3.13). Then `createImportBatch` → `parseWorkbook` (hardening caps §12; `\RuntimeException` ⇒ batch `abandoned` + flash the safe message + CSV-fallback hint) → `stageRows` → redirect `?batch=N`.
- **Step 2 — vocab mapping** (`status='mapping'`, unresolved entries in `vocabMap`): per unknown HOURS value and unknown STATUS value, a row: raw value → radio [map to existing (select of the venue's types / site's statuses, **fuzzy suggestion pre-selected** — data model §11.1: `Proposed to Mill Rd Baptist Church` → `Proposed to Landlord` via normalised-token overlap)] | [create as new — reveals usageKind + window inputs (HOURS) or statusCategory + countsAsConfirmed/isAvailable (STATUS)]. BackingData panel: detected per-year usage-type windows offered as checkboxes → accepted ones become `tblVenueUsageTypeWindows` rows with `effectiveFrom = Jan 1 of that column's year` (`importBackingDataWindows` — this is how "2026 Regular → 09:30-16:00" lands, data model §11.2). **POST `action=map`** → `applyVocabMap` → redirect (loops until zero pending).
- **Step 3 — preview** (zero pending): counts by `rowState` (ready / skipped / error with `stateNote` breakdown — "times missing" is READY and importable, data model §11.1), per-sheet subtotals, sheet-year-mismatch warnings, first 20 rows rendered as they will import. Commit button (`data-confirm`) + Cancel.
- **POST `action=commit`** → `commitBatch` (transaction; refuses on pending rows / wrong status / wrong site) → done panel: imported/skipped/error counts, link "Review N bookings that still need times" (`/venues?venue=N&…` times-needed filter), link to schedule.
- **POST `action=cancel`** → `abandonBatch` → redirect step 1.
- Every `?batch=N` access validates `batch.siteID = Site::id()` (404 otherwise) and rejects actions unless status ∈ {uploaded, mapping}.

### 4.6 Agreements

**`venues/agreements.php`** — GET, manager. List (filter venue/status): title, type badge, venue, term, **renewal countdown badge** (danger when `renewalDate`/`termEnd - noticePeriodDays` ≤ 30 days), rate (`£x.xx / unit`), status badge, files count. Inline create/edit form via `?edit=` → posts `agreement-save`. Detail area for `?edit=` also lists files (title, size, uploader, date, download/delete buttons → `agreement-files`/`agreement-download`) + an upload sub-form.

**`venues/agreement-save.php`** — POST-only, manager. Actions: `save` → `Venues::saveAgreement()`; `set-status` (`status ∈ {active, terminated, expired}` from the manual family) → validated transition via `saveAgreement`; `supersede` (old id + the new-agreement field set) → `supersedeAgreement()` — flash "Renewal created; previous agreement marked superseded".

**`venues/agreement-files.php`** — POST-only, manager. Actions `upload` (agreementID∈site; title*; §12 pipeline: size cap → finfo sniff vs `Venues::AGREEMENT_FILE_MIME_EXT` → `bin2hex(random_bytes(16)) . '.' . $extFromSniffedMime` stored under `PORTAL_ROOT/_uploads/venues/agreements/` (mkdir 0755 recursive if absent) → `attachAgreementFile`) and `delete` (fileID∈site → `deleteAgreementFile`).

**`venues/agreement-download.php`** — GET `?id=`, manager. Fetch file row site-scoped (404 otherwise); path = uploads dir + **`basename($row['filePath'])`** (defence-in-depth, resource-download.php l.11-17 — the stored name is server-generated anyway); `is_file` check ⇒ 404; stream with `Content-Type: {mimeType}`, `Content-Disposition: attachment; filename="{sanitised original fileName}"` (CsvExporter::sanitiseFilename-style regex), `Content-Length`, `readfile`, `exit()`. Uniform 404 (never 403) for missing/foreign ids — no existence oracle.

### 4.7 Invoices & payments (pure outgoing ledger — Q3)

**`venues/invoices.php`** — GET, manager. Ledger list (filters: status multi, venue, issue-date range): ref, venue, description, period, issue/due dates (overdue = red due date), amount, paid-to-date, **status badge** (pending=secondary, part-paid=info, paid=success, disputed=warning, danger=cancelled struck), attachment icon. Footer totals: billed / paid / outstanding (pence-int arithmetic, `number_format($p / 100, 2)` display only). "+ Invoice" opens `invoice.php` create mode.

**`venues/invoice.php`** — GET `?id=` (or `?venue=` create), manager. Sections: (1) invoice form → `invoice-save` (venue locked after create; agreement select filtered to the venue; invoiceRef, description, periodStart/End, issueDate*, dueDate, amount as pounds-decimal → pence, currency, notes; file input for the single scanned-invoice attachment; current attachment shown with download link → `invoice-download`); (2) **allocation lines**: existing lines (booking date + notes + allocated amount + remove button) + add-line form (booking select = the invoice venue's bookings inside `periodStart..periodEnd` when set, else ±1 year of issueDate; optional amount) → `invoice-save action=allocate`; (3) **payments**: recorded payments list (date, amount, method, reference, recordedBy, delete button) + record-payment form (paidDate*, amount*, method select, reference, notes) → `invoice-payment-save`; running total + outstanding shown; (4) manual status buttons (Dispute / Cancel, `data-confirm`) and admin-only Delete (refused while payments exist).

**`venues/invoice-save.php`** — POST-only, manager. Actions `save` (incl. attachment via the §12 pipeline into `_uploads/venues/invoices/`; replacing an attachment unlinks the old file after the row update), `allocate` → `allocateInvoiceLine`, `remove-line` → `removeInvoiceLine`, `set-status` (disputed/cancelled) → `setInvoiceStatus`, `delete` (admin-only) → `deleteInvoice`.

**`venues/invoice-payment-save.php`** — POST-only, manager. Actions `add` → `recordPayment` (flash includes the recomputed status: "Payment recorded — invoice now part-paid (£x.xx outstanding)"), `delete` → `deletePayment`.

**`venues/invoice-download.php`** — GET, manager. Same streaming/anti-oracle contract as `agreement-download.php`, rooted at `_uploads/venues/invoices/`.

**`venues/invoice-pdf.php`** — GET `?id=`, manager. §9.2.

### 4.8 Exports & settings

**`venues/export.php`** / **`venues/schedule-pdf.php`** — §9.1/§9.3.

**`venues/settings.php`** — GET+POST, **admin-only**. Curated form over the `venues.*` keys: calendar_default_venue (select of the site's active venues + "— disabled —" ⇒ '0'), currency (3-alpha validated), the three lead-day ints, reminders_enabled checkbox, reminder_roles (checkbox per `tblRoles` row → CSV), cron_token (masked "set/not set" indicator + "Regenerate" button writing `bin2hex(random_bytes(32))`; shown ONCE post-regeneration in the flash, with the full cron URL `/cron/venue-reminders?key=…`). Writes: sabbath idiom (`INSERT … ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)`, sabbath/index.php l.54-64) — **stage-03 verify item:** confirm the `tblSettings` unique key makes the upsert fire for the chosen `siteID` scoping (per-site rows for calendar_default_venue/reminder_roles/lead-days, `NULL` global for cron_token); if NULL-siteID uniqueness doesn't dedupe, use the update-then-insert shape of `admin/translation/save.php` l.43-51 instead. `Logger::activity('VenueSettingsSaved', …)`.

---

## 5. Calendar integration (overlay + "is it booked?" warning)

Feature-flag guard EVERYWHERE in this section: the calendar must be byte-identically functional with Venues disabled. Canonical guard (calendar side):

```php
$venueOverlay = [];   // date-key → rows; [] means "render nothing venue-related at all"
if ($rangeStart !== null && $rangeEnd !== null
    && \Portal\Core\AppRegistry::isEnabled('venues') === true
) {
    try {
        $venueOverlay = \Portal\Core\Venues::availabilityForRange(
            $siteId, null, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')
        );
    } catch (\Throwable $e) { $venueOverlay = []; }   // overlay is decoration — never breaks the calendar
}
```

`AppRegistry` is core (always loaded); `isEnabled('venues')` is false whenever the registry file or the flag is missing (AppRegistry.php l.98-100, l.109-112), so this is safe even mid-deploy.

### 5.1 Overlay rendering (files touched)

1. **`_apps/calendar/index.php`** — insert the guard block immediately after the events fetch (after l.316, before the categories query). List view has `$rangeStart === null` ⇒ overlay skipped **by design** (documented: the list is unbounded/paginated; the schedule at `/venues` is the list-shaped surface).
2. **NEW `_apps/calendar/views/_venue_strip.php`** — function-partial guarded by `function_exists` (the `_day_columns.php` l.31 idiom): `function render_venue_strip(string $dateKey, array $venueOverlay): string`. Empty array/no rows/rejected-only ⇒ `''`. Renders ONE thin strip per booking row (rejected rows filtered out — §8.1 contract): `<div class="portal-venue-strip" style="border-left:3px solid {resolvedColor}; background:{resolvedColor}1a" title="{venueName · typeName · statusName · HH:MM–HH:MM · notes-excerpt}">` containing `<i class="fa-solid fa-building-columns"></i>` + compact text: confirmed hire → `HH:MM–HH:MM` + venue name (bold); unconfirmed hire → same but CSS class `portal-venue-strip-tentative` (`background: repeating-linear-gradient(45deg, transparent, transparent 4px, {color}22 4px, {color}22 8px)` hatch); `usageKind='closed'` → `fa-door-closed` + "Closed / not needed" grey; `usageKind='unavailable'` → `fa-ban` + "Building unavailable" red — the two non-booking states rendered DISTINCTLY (brief requirement). All interpolated text `htmlspecialchars`'d; colours pass the `statusColor()` hex validation before touching a style attribute. Small CSS block ships in the partial itself (scoped classes, ≤ 20 lines, both themes via `--bs-` variables for text).
3. **`views/month.php`** — `require_once __DIR__ . '/_venue_strip.php';` at top; inside the day cell (after the date-number header, before event pills, i.e. in the l.83-90 loop) `echo render_venue_strip($k, $venueOverlay);`.
4. **`views/_day_columns.php`** — `render_day_columns()` gains an optional third param `array $venueOverlay = []`; strips render inside each day column's all-day strip area (the natural slot — venue hire windows are day-scoped context, not hour-positioned events). Callers `day.php`/`week.php`/`weekdays.php`/`weekend.php` each pass `$venueOverlay` (default keeps them working unmodified if missed — but modify all four).
5. **`views/year.php`** — per-day mini-cells get worst-severity colour as a 3px inset bottom bar (`box-shadow: inset 0 -3px 0 {color}`) via a tiny helper in `_venue_strip.php`: `venue_strip_day_color(string $dateKey, array $venueOverlay): ?string` (returns the §3.5.2-precedence-worst row's colour; null ⇒ no style). Year cells are too small for strips; the colour cue + navigating to month is the interaction.
6. **`views/_shared_header.php`** — when `count($venueOverlay) > 0`, a one-line legend under the filters: ■ Confirmed hire · ▨ Proposed · ■ Closed/not needed · ■ Building unavailable (colour swatches from `CATEGORY_COLORS`/`KIND_COLORS`).

### 5.2 The "is it booked?" WARNING — where it surfaces (decision #3)

Target venue = `venues.calendar_default_venue` (Q6): read via `Settings::get('venues.calendar_default_venue', '0')`, int-cast; `<= 0` ⇒ the whole warning feature is dormant. Both surfaces validate the venue still exists ∈ site (stale setting ⇒ dormant, never an error). Severity → colour: `Venues::COVERAGE_SEVERITY` (§3.1) → Bootstrap `alert-success` / `alert-warning` / `alert-danger` — i.e. UNAVAILABLE & NO_BOOKING red; OUTSIDE_HOURS, UNCONFIRMED & CLOSED amber; CONFIRMED green (data model §8.2 severity mapping, verbatim).

**Surface A — live check in the event form.** `_apps/calendar/manage/_event_form.php`: below the endDateTime input (after l.69), guarded by `AppRegistry::isEnabled('venues') === true && $defaultVenueId > 0` (PHP-computed once in `manage/index.php` and passed in scope):
```html
<div id="venueCheck-<?php echo $isEdit ? 'edit' : 'new'; ?>" class="alert d-none small mb-2"></div>
```
plus an inline `<script>`: listens (`change` + 400 ms debounce) on this form's `startDateTime`/`endDateTime` inputs, `fetch('/api/venues/check?start=' + encodeURIComponent(s) + '&end=' + … + '&tz=' + tzField)` → on `{severity, message}` set `alert-{success|warning|danger}` + textContent (never innerHTML), un-hide; fetch error ⇒ re-hide (silent — progressive enhancement, no-JS loses only the live preview, not the check). On edit, fire once on load.

**Surface B — authoritative post-save warning (works with zero JS).** `_apps/calendar/manage/save.php`: in BOTH the create branch (after `Logger::activity`, l.228) and the update branch (l.299), before the redirect:
```php
if (\Portal\Core\AppRegistry::isEnabled('venues') === true) {
    try {
        $vId = (int) \Portal\Core\Settings::get('venues.calendar_default_venue', '0');
        if ($vId > 0) {
            $cov = \Portal\Core\Venues::classifyEventCoverage(
                ['startDateTime' => $startDateTime, 'endDateTime' => $endDt, 'timezone' => $timezone],
                $vId
            );
            if ($cov['severity'] !== 'success') {
                $_SESSION['flash_type'] = 'warning';
                $_SESSION['flash_msg'] .= ' ⚠️ Venue check: ' . $cov['message'];
            }
        }
    } catch (\Throwable $e) { /* warning only — never blocks an event save */ }
}
```
The save is NEVER blocked (decision #3 is warn, not prevent). `classifyEventCoverage` internally validates `$vId ∈ Site::id()` (stale/foreign id ⇒ defensive no-booking path is suppressed by the getVenue-null early return returning dormant — implement as: getVenue null ⇒ return severity 'success' sentinel `venue_missing` that save.php treats as no-warning; the API surface reports it explicitly instead).

### 5.3 `api/venues/check` semantics (Surface A's backend)

Interprets `start`/`end` as wall-clock in the `tz` param (default `Europe/London` — the same value the form's `timezone` field posts, matching what `save.php` will store, per the §3.5.2 note); builds the pseudo-event array; classifies against `venueID` (param, validated ∈ site) or the default-venue setting. Response `data`: `{classification, severity, message, perDay}` via `ApiResponse::success()`. Invalid dates/range ⇒ `ApiResponse::error('invalid-range', 400)`. Missing/dormant venue ⇒ `success` with `classification: 'not-configured', severity: 'none'` (the JS hides the panel).

---

## 6. Reminders cron — `web/_apps/cron/venue-reminders.php`

Clone of `cron/asset-reminders.php` (the file header of the new cron must cite it), narrowed to three families (§9 of the data model):

1. **Gate** (l.85-90 shape): `?key=` vs `Settings::get('venues.cron_token', '')` — `hash_equals` constant-time; **empty stored token ⇒ 403 always** (seeded empty, §2.2). Then `Content-Type: text/plain`.
2. **Site loop**: `SELECT DISTINCT siteID FROM tblVenues WHERE isActive = 1`; per site: skip unless `App::settingForSite('venues.enabled', $siteId)` ∈ {'1','true'} AND `App::settingForSite('venues.reminders_enabled', $siteId)` ≠ '0'; `Site::forceContext($siteId)` before the site's work (audit/activity attribution — asset cron header l.27-35 rationale).
3. **Three sweeps** per site (lead days via `App::settingForSite`, defaults 21/60/7):
   | refType | Source helper | dueDate logged | Email content (all `htmlspecialchars`'d; **no money amounts in `booking-unagreed`**, amounts fine in `invoice-due` since recipients are managers) |
   |---|---|---|---|
   | `booking-unagreed` | `Venues::dueUnagreedBookings()` | `bookingDate` | "{venueName} {date} ({usage type}) is still '{statusName}' — {N} days away. Review: {abs URL}/venues/booking?id=…" |
   | `agreement-renewal` | `dueAgreementRenewals()` | `renewalDate` or `termEnd` (whichever triggered — data model §9) | "{title} for {venueName}: renewal due {date} / notice period starts {date}. {URL}/venues/agreements?edit=…" |
   | `invoice-due` | `dueInvoices()` | `dueDate` | "Invoice {ref} for {venueName} — £{amount}, due {date}{, OVERDUE by N days}. {URL}/venues/invoice?id=…" |
   Absolute URLs via the local `venueReminderUrl()` helper (scheme+host from `$_SERVER`, asset cron l.110-116 pattern).
4. **Dedupe** per item: `Venues::reminderAlreadySent(refType, refId, dueDate)` check-first; send via `Mailer::send($email, $subject, $bodyHtml)` per recipient; then `logReminder(...)` inside try/catch `\mysqli_sql_exception` — `uq_venrl_ref` is the overlapping-run backstop; **`recipientCount = 0` still logs** (prevents infinite retry on recipient-less sites — asset cron l.122-126 contract). A dueDate change (re-scheduled booking / moved renewal) naturally re-reminds — that's the `(refType, refID, dueDate)` key working as designed.
5. **Recipients** (`Venues::resolveReminderRecipients`, Q11): parse `venues.reminder_roles` CSV → roleKeys; SQL mirrors AssetRegister l.8343-8359: users active on the site (`tblUserSites`) with valid email, WHERE `u.isAdmin=1 OR u.isRootAdmin=1 OR us.isSiteAdmin=1 OR us.isSiteRootAdmin=1 OR r.roleKey IN (…dynamic placeholders…)`; roleKey set = the CSV values ∪ {'venue_manager'} when the CSV is empty. Admins are ALWAYS in the audience (asset precedent). De-dupe + `FILTER_VALIDATE_EMAIL` before return; the send loop re-validates (dispatch-point re-check, asset cron l.160-171).
6. **Output**: plain-text per-site summary lines `site {id}: unagreed {sent}/{due}, renewals {sent}/{due}, invoices {sent}/{due}` + grand total — greppable from the scheduler's logs.

---

## 7. Roles & permissions model

**Chosen model: reuse-admin + one new role `venue_manager`** (seeded §2.3 — the `asset_manager` precedent exactly; migration 159 l.483-486). No permission-matrix machinery exists in this codebase to hook into; role + admin is the house capability model (`App::hasRole` l.335, `App::isAdmin` l.388 — note root admin implicitly passes `hasRole`).

| Capability | Who | Enforced where |
|---|---|---|
| View schedule, venue detail (no costs), CSV/PDF schedule export (no cost column) | any logged-in user (**leaders' view — decision: leaders can VIEW, brief "let leaders view"**) | route `isProtected=1` + no extra gate in index/venue/booking(read)/export/schedule-pdf |
| All venue/room/usage-type/status config; bookings CRUD; generator; import; agreements + files; invoices + payments; cost visibility everywhere | admin OR `venue_manager` | `Venues::canManage()` gate at top of every §4.2-4.7 handler; POST handlers double-gate (GET gate is not enough — each `-save` re-checks) |
| Venue hard-delete; invoice hard-delete | admin only | `App::isAdmin()` check on those specific actions inside the gated handlers |
| Settings page (default venue, lead days, reminder roles, cron token) | admin only | `venues/settings.php` top gate |
| Cron | token, not a user | §6 gate |
| API check/availability | any authenticated principal (session or bearer) | `ApiResponse::requireAuth()` — read-only, same data the viewer schedule shows minus costs (neither endpoint returns `costPence` — enforced by the §8.1 column list, which simply doesn't select it) |

Role assignment happens through the existing `/admin` users UI (tblUserRoles) — nothing venue-specific to build.

---

## 8. GDPR — `GdprEraser::catalogue()` additions (Q2, mandatory)

Add to `web/_core/GdprEraser.php::catalogue()` (l.45-197), **before the final `tblUsers` entry**, with this comment block and these exact entries:

```php
// #VEN Venue Bookings. Only the NULLABLE per-user columns are catalogued —
// every NOT NULL creator/actor column in the venue tables (tblVenues.createdByID,
// tblVenueBookings.createdByID, tblVenueBookingGroups.createdByID,
// tblVenueAgreements.createdByID, tblVenueAgreementFiles.uploadedByID,
// tblVenueInvoices.createdByID, tblVenueInvoicePayments.recordedByID) is FK'd
// ON DELETE RESTRICT with no SET NULL path, so it cannot be nulled by an
// UPDATE — the identical convention this catalogue already applies to
// tblAssetMaintenance.createdByID / tblAssetResources.uploadedByID /
// tblCareVisit.visitedByID (see the tblCareCase note above): attribution is
// detached when the final tblUsers step tombstones the person's name/email.
// tblVenues.caretakerName/caretakerPhone and tblAssetOrgs contact fields are
// free-text details of a person who need not be a portal user — no per-user
// column to match an erasure request against (same reasoning as
// tblAssetFoundReports above); they are corrected/removed through the venue
// and organisation edit forms, and the venues help page documents that duty.
// tblVenueImportRows/tblVenueReminderLog carry no personal data columns.
['table' => 'tblVenueBookings',         'userCol' => 'updatedByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'hire schedule retained as business record; editor identity detached'],
['table' => 'tblVenueUsageTypeWindows', 'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'time-window history retained; author identity detached'],
['table' => 'tblVenueImportBatches',    'userCol' => 'createdByID', 'action' => 'anonymise', 'nullCols' => [], 'reason' => 'import provenance retained; uploader identity detached'],
```

(All three columns are `DEFAULT NULL` with `ON DELETE SET NULL` FKs in the data model — the anonymise UPDATE succeeds.) **Org-contact handling decision (Q2 second half):** manual-through-UI, explicitly documented in the comment above + `help/venues.php` §"Data protection" + the admin help page — NOT catalogued, because erasure requests are keyed by `userID` and these fields have none (the eraser's own l.142-148 precedent). **Stage-03 verification must assert**: (a) the three entries prepare + execute cleanly against migration-170 schema (the #372 lesson was silently-failing UPDATEs from wrong names), (b) `check_php_table_refs.py` passes on the new class/handlers, and (c) `_apps/account/data-export.php`'s export list gains the same three tables (venue rows referencing the requesting user) — parity between export and erasure.

---

## 9. PDF / CSV exports

### 9.1 Schedule CSV — `venues/export.php`
GET; viewer. Params `venue` (∈site, required when >1 venue), `year` (default current) or `from`/`to`. Rows from `Venues::listBookings()`; columns `Date, Day, Venue, Room, Usage type, Times, Status, Notes` + `Cost` **appended only when `Venues::canManage()`** (pounds-decimal string). Stream via `CsvExporter::download('venue-schedule-' . $venueSlugish . '-' . $year . '.csv', $rows)` (CsvExporter.php l.37 — BOM + fputcsv, filename sanitised internally). Matches the source spreadsheet's row-per-date shape 1:1 → a church can round-trip its record.

### 9.2 Invoice PDF — `venues/invoice-pdf.php`
GET `?id=`; manager. Build HTML (site/brand name via `Site::productName()`, invoice fields, allocation lines, payments, outstanding) **using CSS Grid — never `<table>`, even in PDF HTML** (AssetRegister.php l.7098-7100: grid is dompdf's most reliable primitive AND honours the house rule). `Pdf::create($html, PORTAL_ROOT . '/_uploads/venues/tmp/invoice-' . $id . '-' . time() . '.pdf')` (Pdf.php l.76); `false` ⇒ render the same HTML as a print-CSS page with an auto-`window.print()` hint (labels-pdf.php l.192-207 fallback); success ⇒ stream with the my-statement.php l.33-37 header set, then `unlink()` the temp file after `readfile` (temp dir is server-managed under `_uploads/`, gitignored).

### 9.3 Schedule PDF — `venues/schedule-pdf.php`
GET; viewer (cost column manager-only, as CSV). Params as 9.1. Year schedule, month-sectioned, one grid row per date (Date · Day · Usage · Times · Status · Notes), status colour as a left border chip, legend footer. Same `Pdf::create` + print-CSS fallback + stream + unlink pipeline as 9.2. `@page` landscape for width.

---

## 10. i18n — `web/_lang/en.php` additions (`cy.php` falls back automatically)

Add one `// 🏛️ Venue Bookings` block (dot-notation grouping per the file header l.7-9). Keys the code actually reads (coverage messages via `t()` with `:params`; page chrome keys for the main surfaces):

```php
'nav.venues'                       => 'Venues',
'venues.title'                     => 'Venue Bookings',
'venues.schedule'                  => 'Hire schedule',
'venues.booking'                   => 'Booking',
'venues.generate'                  => 'Generate series',
'venues.import'                    => 'Import spreadsheet',
'venues.agreements'                => 'Hire agreements',
'venues.invoices'                  => 'Invoices & payments',
'venues.statuses'                  => 'Booking statuses',
'venues.usage_types'               => 'Usage types & hours',
'venues.rooms'                     => 'Rooms & spaces',
'venues.settings'                  => 'Venue settings',
'venues.whole_venue'               => 'Whole venue',
'venues.times_needed'              => 'Times needed',
'venues.overlay.closed'            => 'Closed / not needed',
'venues.overlay.unavailable'       => 'Building unavailable',
'venues.overlay.legend_confirmed'  => 'Confirmed hire',
'venues.overlay.legend_proposed'   => 'Proposed / awaiting agreement',
'venues.coverage.confirmed'        => ':venue is booked :date (:start–:end) — you are covered.',
'venues.coverage.unconfirmed'      => ':venue is requested for :date but the booking is not yet agreed (:status).',
'venues.coverage.outside_hours'    => ':venue is booked :date :window, but this event falls outside that window.',
'venues.coverage.closed'           => ':venue is marked closed / not needed on :date.',
'venues.coverage.unavailable'      => ':venue is UNAVAILABLE on :date — the building cannot be used that day.',
'venues.coverage.no_booking'       => 'No venue booking exists for :venue on :date.',
'venues.coverage.multi_day_worst'  => 'Across :count days, the worst finding is: :message',
'venues.reminder.unagreed_subject' => 'Venue booking not yet agreed — :venue :date',
'venues.reminder.renewal_subject'  => 'Hire agreement renewal due — :title',
'venues.reminder.invoice_subject'  => 'Venue invoice due — :ref',
```

`coverageMessage()` and the two email subjects use `t()`; page headings/buttons may use the chrome keys where convenient (house reality: most app UI is literal English — the coverage/reminder strings are the ones that MUST be keyed because they render in shared surfaces: calendar flashes + emails). List the final key set in the migration PR description so `cy.php` maintainers can follow up.

---

## 11. Help page + documentation updates

### 11.1 `web/_apps/help/venues.php` (route `help/venues`, isProtected 0 — `help/assets` precedent)
Shape of `help/assets.php` l.24-50 (TOC badge row + sections). Outline:
1. **What is Venue Bookings?** — for congregations that RENT their building: record what's been agreed with the landlord so leaders never plan into an unbooked slot. How it differs from Resources (rooms you own) and Assets (things you own).
2. **Reading the schedule** — the year view; what each column means; the status colours; what "Confirmed" means (countsAsConfirmed); "Closed/Not needed" vs "Building unavailable" (we chose not to use it vs we can't have it).
3. **The calendar overlay & warnings** — the strip on the calendar; the event-form check; what each warning colour means and what to do about each (ask the venue manager to propose/extend a booking).
4. **Adding bookings** — single day; multi-day runs; the recurring generator (weekly worship in one click; skipped-date reporting); editing a whole run.
5. **Usage types & default hours** — per-venue vocabulary; effective-dated windows ("the 2026 hours change"); re-applying defaults.
6. **Statuses & the approval journey** — configurable vocabulary; the seeded six; leadership vs landlord sign-off as statuses, not workflow.
7. **One day, several activities** (Q13 documented recommendation): *keep ONE booking per hired slot and describe the whole day in its notes; link the main service as the event. Create separate bookings only when the hire itself is separate (different times or different rooms).*
8. **Importing your spreadsheet** — expected columns; year sheets; BackingData; the CSV fallback; the "times needed" checklist after import.
9. **Agreements, invoices & payments** — standing vs ad-hoc; renewal reminders; recording what the landlord billed and what you paid (money OUT — this app never takes card payments).
10. **Reminders** — the three nudges and who receives them.
11. **Who can do what** — viewer vs Venue Manager vs admin.
12. **Data protection** — caretaker/landlord contact details are personal data: keep them current, remove them when people move on (the §8 manual-handling duty, user-facing).

### 11.2 Stage-03 documentation deltas (exact rows/snippets for the build plan)
- **FEATURES.md**: new app section "Venue Bookings (`/venues`)" — schedule, configurable statuses/usage types, effective-dated hours, multi-day + recurring generator, XLSX/CSV import wizard, calendar overlay + event warnings, agreements + renewal reminders, payable invoices + payment tracking, CSV/PDF exports, `venue_manager` role, cron.
- **CHANGELOG.md**: `### Added — Venue Bookings app (#VEN)` bullet list mirroring the above + migration 170.
- **DEV_NOTES.md**: (a) the wall-clock timezone rule (§3.5.4) under a "Venue Bookings" heading; (b) the native-XLSX parser caps (§12 item 7) and ZipArchive dependency note; (c) the `venues.cron_token` scheduler setup line (copy the asset-reminders entry).
- **`.claude/CLAUDE.md` apps table row**: `| venues | \`/venues\` | Tenant-side venue-hire register — schedule of agreed bookings of a rented building, configurable statuses/usage types, recurring generator, XLSX import, calendar overlay + "is it booked?" warnings, hire agreements + renewal reminders, payable invoice/payment ledger (#VEN) |` — and bump the `_core` class count (26 → 27: `Venues.php`).

---

## 12. Security checklist (Stage-03 verification maps 1:1 to this)

| # | Risk | Mitigation (exact) |
|---|---|---|
| 1 | IDOR / cross-site reads & writes | EVERY id from GET/POST re-fetched with `siteID = Site::id()` in the WHERE clause; miss ⇒ uniform 404 (§4.0). Applies to venue, room, booking, group, status, usageType, window, agreement, file, invoice, line, payment, batch, row ids. Downloads use the resource-download.php uniform-404 (never 403) anti-oracle contract. |
| 2 | Cross-tenant vocabulary attachment (Q7) | `Venues::saveBooking()` validates `status.siteID == booking.siteID` AND `usageType.venueID == booking.venueID` (+ room/event/agreement/group ∈ venue/site) via site-scoped SELECTs before any write — §4.0 list. `applyVocabMap`/`commitBatch` route created vocab through `saveStatus`/`saveUsageType` so imports get identical enforcement. Stage-03 hard gate: grep-assert each save path contains these probes. |
| 3 | File upload (agreements, invoices, import) | finfo-sniffed MIME vs closed allow-list (`AGREEMENT_FILE_MIME_EXT`; import: xlsx/csv set) — client type/filename never trusted; size cap `venues.maxFileSize` with 10 MB fallback when missing/zero (never unlimited); stored name = `bin2hex(random_bytes(16)) . '.'` + extension derived from the SNIFFED mime; stored OUTSIDE the webroot under `_uploads/venues/{agreements,invoices}/`; served only through gated download handlers. No SVG anywhere (XSS carrier — save.php l.124 precedent). |
| 4 | Path traversal on download | `filePath` is server-generated; handlers still apply `basename()` before joining to the uploads root (resource-download.php l.11-17 defence-in-depth) + `is_file` check; `Content-Disposition` filename passed through the sanitise regex. |
| 5 | XLSX zip-bomb / entity expansion (Q9) | `parseWorkbook`: pre-parse size cap (setting); `ZipArchive::numFiles ≤ 200`; per-entry `statIndex()['size'] ≤ 20 MB` checked BEFORE extraction; only `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/worksheets/sheet*.xml`, `xl/sharedStrings.xml` are read; SimpleXML loaded with `LIBXML_NONET` and WITHOUT `LIBXML_NOENT` (entities never substituted; PHP ≥ 8 disables external entity loading by default — do not re-enable); row cap 5,000/sheet, cell cap 500 chars (excess truncated + stateNote); parse failures throw a user-safe `\RuntimeException` (no path/internal leakage) and the batch is abandoned. |
| 6 | CSRF | `Auth::verifyCsrf()` FIRST on every POST in every handler (§4.0) — including api-adjacent self-posting pages (import/generate/statuses/usage-types/rooms/settings). The two GET API endpoints are read-only (no state change ⇒ no CSRF surface). |
| 7 | SQL injection | MySQLi prepared statements ONLY; the one dynamic-SQL spot (reminder-roles `IN (…)`) builds `?` placeholders from `count($roleKeys)` and binds — the AssetRegister l.8343 shape; column names never come from input (no `$authorityColumn`-style interpolation exists in this app). |
| 8 | XSS | `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on ALL output including notes, imported raw cells (rendered in wizard preview), org/venue names in emails (asset cron precedent l.50-51); overlay JS uses `textContent` never `innerHTML`; colour values regex-validated (`statusColor()`) before entering `style=""`. |
| 9 | Open redirect | No redirect ever takes a URL from input — every `header('Location: …')` is a hard-coded app path with only int/date query components (§4 handlers). Stage-03 grep gate: no `Location: ` . `$_` concatenation. |
| 10 | Public token surfaces | **None exist in this app** (no public/anonymous route; the cron is token-gated infra, not a user surface). The cron token: `hash_equals` constant-time compare, empty-token→403, `isSensitive=1` seed, shown once on regeneration. |
| 11 | Money integrity | All amounts integer pence end-to-end (#266); pounds-decimal only at the input/display boundary (`(int) round($pounds * 100)` in, `number_format($p/100, 2)` out); negative/zero amounts rejected in `recordPayment`/`saveInvoice`; invoice status machine-managed (manual family cannot be spoofed into `paid` — `setInvoiceStatus` whitelist excludes the paid family). |
| 12 | Privilege escalation | `-save` handlers re-check `canManage()` themselves (never rely on the rendering page's gate); admin-only actions (`delete` venue/invoice, settings) re-check `App::isAdmin()` inside the action branch; API endpoints select no cost columns. |
| 13 | Audit completeness | Every mutating `Venues::` method carries the ✎ marker in §3.2 — stage-03 review asserts each ✎ method calls `self::audit()` exactly once per logical mutation (bulk = one summary row). |
| 14 | Calendar resilience | Overlay + warning wrapped in `AppRegistry::isEnabled('venues')` + try/catch `\Throwable` (§5) — a venues regression can never 500 the calendar or block an event save. |

---

## OPEN QUESTIONS / RISKS for stage 03 (build plan)

1. **Migration number**: 170 assumed (166-169 reserved elsewhere). Stage 03 must re-verify the ledger at build time and substitute the real GitHub issue number for `#VEN` everywhere (headers, table COMMENTs, docs).
2. **`tblVenueInvoicePayments` DDL delta**: stage 03 copies §3.12 from the data model **minus** `portalPaymentID`/`idx_venip_portal`/`fk_venip_portal` (Q3). CI `check_schema_seed_parity.py`/`check_sql_columns.py` will catch a miss — but get it right first time.
3. **`tblEvents` datetime semantics** (§3.5.2 note): column comment says UTC, `manage/save.php` stores form-local wall clock. The design interprets via `$event['timezone']` (matches stored reality; identity when both Europe/London). Stage 03 MUST include a DST-boundary functional check (event at 2025-03-30 01:30 Europe/London vs a 09:30-13:30 booking) and, if the interpretation proves wrong for any legacy data, the fix is confined to `classifyEventCoverage` step 2.
4. **`ZipArchive` availability on DreamHost** (Q9): smoke-test in the verification plan (`php -r "exit(class_exists('ZipArchive') ? 0 : 1);"` on the target); the wizard already degrades to CSV-only with a visible notice.
5. **`tblSettings` per-site upsert** (§4.8): confirm the unique key makes `ON DUPLICATE KEY UPDATE` fire for both `siteID = NULL` and `siteID = N` rows; fall back to update-then-insert (translation/save.php l.43-51) if NULL rows can duplicate.
6. **`check_settings_keys.py` behaviour** on the 12 new keys: run it early; if it flags key/consumer mismatches, reconcile the §2.2 list against actual `Settings::get`/`settingForSite` call sites (every seeded key above has a named consumer in this design — keep it that way).
7. **`Site::forceContext` reset** after the cron's site loop: check whether the asset cron resets context at the end; mirror whatever it does (the process exits anyway, but mirror exactly).
8. **Fuzzy vocab suggestion** (import step 2): normalised-token-overlap is specified loosely — stage 03 should pin the exact scoring (e.g. case-fold, strip non-alnum, suggest the seed status sharing the leading token `proposed|agreed|rejected|pending`; ties → no preselection) so two implementers produce identical behaviour.
9. **Room-aware coverage (Q12)** and **`tblEvents.venueID` (Q6 follow-up)**: file the two follow-up issues at ship time; the class-header note (§3) and `venues.calendar_default_venue` carry v1.
10. **Stub policy for `check_route_targets.py`**: all 26 target files ship real in this build (nothing is deferred), so no stubs should be needed — but if stage 03 splits the build across PRs, any later-PR handler must ship as a stub in the migration-170 PR (159's documented stub precedent).
11. **Load surface**: the year schedule (≈52-120 rows) and overlay ranges (≤ 42 days for month view) are trivially indexed; the only heavy op is import commit (≤ ~1,100 rows for a 3-year workbook) — inside one transaction, well under shared-hosting limits. No pagination needed anywhere in v1 except the invoices ledger if it ever exceeds ~200 rows (add then, not now).
12. **E2E migration harness**: migration 170 is all `CREATE TABLE IF NOT EXISTS` + idempotent seeds — replay-as-no-op should hold by construction; still run the harness + `check_migration_idempotency.py` + `check_mariadb_only_ddl.py` (expected: zero findings, zero guarded ALTERs).
