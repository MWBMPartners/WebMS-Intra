# Venue Bookings — Stage 03: BUILD PLAN

**Pipeline:** Stage 03 of 3 (final). Inputs, in override order (later overrides earlier):
`00-brief.md` → `01-data-model.md` → `01b-review-resolutions.md` → `02-app-design.md` → `02b-review-resolutions.md` → **this file**.
**Written:** 2026-08-27 against the live repo (branch lineage: `alpha`, migrations 000–165 in `web/_sql/`; Wave 1's `166_webhook_retry.sql` exists only on its pushed branch). Every file/line citation in §0 was re-verified this session.

**This is the document the implementation agents (Sonnet/Haiku) execute verbatim.** Where this plan says "02 §X verbatim", the agent opens `02-app-design.md` §X and builds exactly that; where this plan states a delta, THIS plan wins. The two binding overrides that already superseded earlier text:
1. **NO `tblPayment` bridge** (01b Q3): `tblVenueInvoicePayments` ships WITHOUT `portalPaymentID`/`idx_venip_portal`/`fk_venip_portal`; no `'venue-hire'` ENUM member; no `Payments::PURPOSES` change; migration 170 has **ZERO guarded ALTERs**.
2. **WALL-CLOCK comparison** (02b): `tblEvents` datetimes are wall-clock **event-local** (the "stored in UTC" column comment is a known doc bug — full_schema l.751-752). `tblVenueBookings` are wall-clock **venue-local**. `Venues::classifyEventCoverage()` compares wall-clock to wall-clock; **UTC appears nowhere in this path** (§3.6 below).

---

## 0. Ground truth re-verified this session (agents: trust THIS table)

| Fact | Verified value |
|---|---|
| Highest migration in repo | `165_widen_totp_secret.sql`; `full_schema.sql` = 6,947 lines, records 000–165 |
| Migration 166 | TAKEN by Wave 1 (`166_webhook_retry.sql`, pushed branch). 167–169 reserved for other in-flight gap items. **Venues = 170** (re-confirm at build time, §1.1) |
| Role-seed idiom | `web/_sql/159_asset_tracker.sql` l.484-486: `INSERT INTO \`tblRoles\` (\`roleKey\`, \`roleName\`) SELECT 'asset_manager', 'Asset Manager' WHERE NOT EXISTS (SELECT 1 FROM \`tblRoles\` WHERE \`roleKey\` = 'asset_manager');` — copy shape exactly (§1.6). `tblRoles` = `roleID, roleKey (UNIQUE), roleName` only (full_schema l.127-136) |
| Route-seed idiom | 159 l.531-557: batch VALUES + `ON DUPLICATE KEY UPDATE \`targetFile\` = VALUES(\`targetFile\`)` |
| Settings-seed idiom | 160 l.228-241: `(NULL, 'key', 'val', 'default', isSensitive)` + `ON DUPLICATE KEY UPDATE \`defaultValue\` = VALUES(\`defaultValue\`)` |
| Self-record idiom | tail of 161: `INSERT INTO \`tblMigrations\` (\`filename\`) VALUES ('…') ON DUPLICATE KEY UPDATE \`filename\` = \`filename\`;` |
| full_schema fold anchors | Asset routes seed ends `ON DUPLICATE KEY UPDATE \`targetFile\` = VALUES(\`targetFile\`);` at ~l.6707; `SECTION 7B` banner at ~l.6710; file ends with the `165_widen_totp_secret.sql` record at l.6946-6947. **Anchor by text, not line number** (166–169 folds may land first) |
| Parity-checker `--` bug | `tools/audit-checks/check_schema_seed_parity.py::strip_sql_comments` = `re.sub(r"--[^\n]*", "", text)` — NOT quote-aware. A literal `--` inside ANY string/COMMENT corrupts its parse. Rule + gate in §1.8/§4.3 |
| `tblSettings` unique key | `uq_setting_key_site (settingKey, siteID)`, `siteID` NULLable (full_schema l.84-103). MySQL treats NULLs as distinct ⇒ **ODKU does NOT dedupe `siteID = NULL` rows**. Migration seeds keep the house ODKU idiom anyway (all checkers expect it; house-wide existing behaviour). PHP writes: per-site rows (siteID = N) may use ODKU (key fires — both columns non-NULL); **NULL-siteID writes from PHP MUST use update-then-insert** (`web/_apps/admin/translation/save.php` l.43-55 shape). Resolves 02 open question 5 |
| Sensitive-setting writes | Stored PLAIN with `isSensitive=1` — house precedent `web/_apps/admin/settings/qr/index.php` l.51-58 writes an API key that way. The 02 §4.8 cron-token "Regenerate" button is safe to build as designed |
| e2e harness | `tools/e2e-migrations/run.sh` (+ `docker-compose.yml`, MySQL 8.0); CI `.github/workflows/e2e-migrations.yml`. Compares information_schema table/column/index counts after replay |
| The 10 audit checks | `tools/audit-checks/`: `check_cdn_sri.py`, `check_mariadb_only_ddl.py`, `check_migration_idempotency.py`, `check_mobile_readiness.py`, `check_no_native_confirm.py`, `check_php_table_refs.py`, `check_route_targets.py`, `check_schema_seed_parity.py`, `check_settings_keys.py`, `check_sql_columns.py` |
| `check_php_table_refs.py` truth source | **full_schema.sql CREATE TABLE statements ONLY** ⇒ the full_schema fold MUST be in the same PR as any PHP referencing `tblVenue*`, or every handler gets flagged |
| `check_sql_columns.py` truth source | full_schema CREATEs + migration `ADD COLUMN`s; validates PHP `INSERT INTO tblX (cols…)` — the fold must carry every column exactly |
| `check_route_targets.py` | parses ALL SQL files' `tblRoutes` INSERTs; each targetFile must exist under `web/_apps/` |
| `check_settings_keys.py` | flags `$SETTINGS['x']['y']`/`App::settings()['x']['y']` reads of unseeded keys. `Settings::get('dot.key')` reads are NOT scanned (regex is array-deref only) — no false positives from our `Settings::get` usage; unread seeded keys are noise, not failures |
| Core method signatures | `Auth::ensureSession()` l.71, `Auth::requireLogin()` l.108, `Auth::verifyCsrf(string $token): bool` l.289; `Router::renderError(int $code): void` l.429; `App::db(): mysqli` l.99, `App::settingForSite(string, int): ?string` l.166, `App::hasRole(string): bool` l.335, `App::isAdmin(): bool` l.388; `Site::forceContext(int): void` l.448; `Settings::get(string $dotKey, mixed $default = null): mixed` l.44; `Logger::activity(string $type, string $description = '', ?int $userId = null): void` l.48, `Logger::audit(...)` l.111; `Mailer::send(string|array $to, string $subj, string $body, array $files = []): bool` l.62; `CsvExporter::download(string $filename, array $rows, array $headers = []): void` l.37; `Pdf::create(string $html, string $filePath, string $watermark = ''): string|false` l.76; `I18n::t(string $key, array $params = []): string` l.252; `AppRegistry::isEnabled(string $slug): bool` l.95; `AssetRegister::listOrgs(int $siteId, bool $activeOnly = false): array` l.3529, `::saveOrg(int $siteId, int $orgId, array $data, int $actorUserId): int` l.3570, `::toggleOrgActive(int $orgId, int $siteId, int $actorUserId): bool` l.3642 |
| `tblAssetOrgs` columns | `orgID, siteID, orgName, contactName, contactEmail, contactPhone, agreementRef, notes, isActive, createdAt` (full_schema l.6136-6151) |
| `tblEvents` datetime columns | `startDateTime DATETIME NOT NULL`, `endDateTime DATETIME NULL`, `timezone VARCHAR(50) NOT NULL DEFAULT 'Europe/London'`, `eventTimezone VARCHAR(64) NOT NULL DEFAULT 'Europe/London'` (l.751-755) |
| GDPR export handler | **`web/_apps/auth/account/data-export.php`** — 02 §8's citation `_apps/account/data-export.php` is WRONG; use this path. Shape: entries in the `'data' => [...]` array via `$fetchUserRows('SELECT … WHERE col = ?')` |
| `GdprEraser::catalogue()` | entry shape `['table','userCol','action','nullCols','reason']`; final `tblUsers` entry at l.193-196 — venue entries insert immediately BEFORE it |
| `_core` class count | **61 class files** (+ bootstrap.php, brand-defaults.php, version.php = 64 `.php`). CLAUDE.md's "26 classes" is stale. After `Venues.php`: **62** (§6.4) |
| Handler shell | `web/_apps/resources/index.php` l.16-47: `Auth::ensureSession(); Auth::requireLogin();` … `$pageTitle/$pageSection/$breadcrumbs` … `require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';` (footer likewise) |
| Cron gate shape | `web/_apps/cron/asset-reminders.php` l.85-97 (verified): `$incoming = (string)($_GET['key'] ?? ''); $expected = (string)(Settings::get('assets.cron_token','') ?? ''); if ($expected === '' || hash_equals($expected, $incoming) === false) { http_response_code(403); exit('Forbidden'); }` then `header('Content-Type: text/plain; charset=utf-8');`. `Site::forceContext()` at l.262 inside the site loop; **no context reset after the loop** (process exits) — mirror that |
| AppRegistry entry shape | `web/_core/apps/resources.php` — 12-key return array; 02 §2.1's venues.php matches it key-for-key |
| Auto-merge on alpha | `.github/workflows/auto-merge-alpha.yml` enables GitHub auto-merge (squash) on every PR based on `alpha` — a Ready PR merges as soon as checks pass ⇒ **stay DRAFT until orchestrator review** (§5) |

---

## 1. Migration 170 — `web/_sql/170_venue_bookings.sql`

### 1.1 Number confirmation (FIRST action of the foundation agent)

```bash
git fetch origin --prune
git branch -r | cat                     # look for other in-flight feature branches
ls web/_sql/ | grep -E '^1(6[6-9]|7[0-9])_' || echo "none present locally"
gh pr list --state open --json headRefName,title | cat   # any PR touching web/_sql/16*/17*?
```
Decision rule: venues takes **170**. If (unexpectedly) a `170_*.sql` exists on any branch/PR targeted at `alpha`, take the **next free number ≥ 171**, then substitute the number consistently in: the filename, the self-record INSERT, the full_schema self-record fold, and every doc that names "migration 170" (§6). Record the substitution in the PR description. Do NOT take 166–169 under any circumstances.

`#VEN`: file the epic GitHub issue **before the PR opens** (title: "Venue Bookings app — tenant-side hire schedule, agreements, invoicing, calendar overlay (/venues)"; labels `type: feature`, `priority: high`, `scope: core`). Then `grep -rln '#VEN' web/ .claude/plans/venue-bookings/03-build-plan.md FEATURES.md CHANGELOG.md DEV_NOTES.md` and replace with the real number. Until then, every agent writes the literal placeholder `#VEN`.

### 1.2 Migration file header (type this, then the sections in order)

```sql
-- =============================================================================
-- Migration 170: Venue Bookings app — foundation schema + seeds (#VEN)
--
-- Path: web/_sql/170_venue_bookings.sql
-- The complete Venue Bookings ("/venues") schema: 15 new tables (tenant-side
-- register of hired external buildings — schedule, vocabularies, agreements,
-- payable invoices + payment ledger, import staging, reminder dedupe log),
-- plus route/settings/role seeds.
--
-- ZERO guarded ALTERs by design (see .claude/plans/venue-bookings/
-- 01b-review-resolutions.md Q3): everything below is plain
-- CREATE TABLE IF NOT EXISTS — idempotent by construction — plus
-- ON DUPLICATE KEY UPDATE / WHERE-NOT-EXISTS seeds. Replays as a no-op.
--
-- NO per-site vocabulary rows are seeded here (house rule — see
-- tblAttendanceServiceTypes precedent): statuses are seeded PHP-side by
-- Venues::seedStatuses() on first /venues access per site; usage types are
-- seeded per-venue at venue creation by Venues::seedUsageTypes().
--
-- NOTE for editors: never put a literal "--" inside a COMMENT/string in this
-- file — check_schema_seed_parity.py strips from any "--" to end-of-line,
-- even inside quotes. Use an em/en dash instead.
--
-- @package    Portal
-- @author     MWBM Partners Ltd (t/a MWservices)
-- @copyright  2026 MWBM Partners Ltd. All rights reserved.
-- @version    1.0.0
-- =============================================================================
```

### 1.3 Tables — 15 `CREATE TABLE IF NOT EXISTS` blocks, in EXACTLY this order

Copy the DDL **verbatim from `01-data-model.md`** section by section (it is build-ready house style: InnoDB, `utf8mb4`/`utf8mb4_general_ci`, `siteID INT NOT NULL DEFAULT 1` + `fk_ven*_site`, pence-integer money, `(#VEN)` in every table COMMENT). Order and sources:

| # | Table | DDL source | Delta vs source |
|---|---|---|---|
| 1 | `tblVenues` | 01 §3.1 | none |
| 2 | `tblVenueRooms` | 01 §3.2 | none |
| 3 | `tblVenueUsageTypes` | 01 §3.3 | none |
| 4 | `tblVenueUsageTypeWindows` | 01 §3.4 | none |
| 5 | `tblVenueStatuses` | 01 §3.5 | none |
| 6 | `tblVenueAgreements` | 01 §3.8 | none |
| 7 | `tblVenueAgreementFiles` | 01 §3.9 | none |
| 8 | `tblVenueBookingGroups` | 01 §3.6 | none |
| 9 | `tblVenueBookings` | 01 §3.7 | none |
| 10 | `tblVenueInvoices` | 01 §3.10 | none |
| 11 | `tblVenueInvoiceLines` | 01 §3.11 | none |
| 12 | `tblVenueInvoicePayments` | **§1.3.1 below — NOT 01 §3.12** | portalPaymentID bridge removed |
| 13 | `tblVenueImportBatches` | 01 §3.13 | none |
| 14 | `tblVenueImportRows` | 01 §3.14 | none |
| 15 | `tblVenueReminderLog` | 01 §3.15 | none |

(This is 01 §12's dependency order EXACTLY — rows 1-15 match its numbered list one-for-one. Nothing before row 9 references anything created after it; rows 10-14 only reference earlier rows; row 15 has no FKs by design.)

**Do NOT copy 01 §3.16** (the guarded ALTER) — it is dropped. There must be zero `ALTER TABLE` statements in this file. `grep -c "ALTER TABLE" web/_sql/170_venue_bookings.sql` must print `0`.

#### 1.3.1 `tblVenueInvoicePayments` — the EXACT final DDL (Q3 delta applied; type this, not 01 §3.12)

```sql
CREATE TABLE IF NOT EXISTS `tblVenueInvoicePayments` (
    `payID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `invoiceID`    INT          NOT NULL,
    `paidDate`     DATE         NOT NULL,
    `amountPence`  INT          NOT NULL COMMENT 'Integer minor units — house pence convention (#266); partial payments allowed',
    `method`       ENUM('bank-transfer','standing-order','cash','cheque','card','online','other') NOT NULL DEFAULT 'bank-transfer',
    `reference`    VARCHAR(100) DEFAULT NULL COMMENT 'Bank ref / cheque number',
    `notes`        VARCHAR(500) DEFAULT NULL,
    `recordedByID` INT          NOT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`payID`),
    KEY `idx_venip_invoice` (`invoiceID`),
    KEY `idx_venip_site_date` (`siteID`, `paidDate`),
    CONSTRAINT `fk_venip_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_venip_invoice`  FOREIGN KEY (`invoiceID`)    REFERENCES `tblVenueInvoices`(`invoiceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_venip_recorder` FOREIGN KEY (`recordedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Venue Bookings — payments recorded against hire invoices (pure outgoing ledger, no tblPayment bridge) (#VEN)';
```

### 1.4 Settings seed — exact SQL (02 §2.2, unchanged; ODKU idiom is correct here)

```sql
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'venues.enabled',                  '0',        '0',        0),
    (NULL, 'venues.currency',                 'GBP',      'GBP',      0),
    (NULL, 'venues.maxFileSize',              '10485760', '10485760', 0),
    (NULL, 'venues.unagreed_lead_days',       '21',       '21',       0),
    (NULL, 'venues.renewal_lead_days',        '60',       '60',       0),
    (NULL, 'venues.invoice_due_lead_days',    '7',        '7',        0),
    (NULL, 'venues.reminders_enabled',        '1',        '1',        0),
    (NULL, 'venues.reminder_roles',           '',         '',         0),
    (NULL, 'venues.cron_token',               '',         '',         1),
    (NULL, 'venues.calendar_default_venue',   '0',        '0',        0),
    (NULL, 'api.venues.check.enabled',        'true',     'true',     0),
    (NULL, 'api.venues.availability.enabled', 'true',     'true',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
```

Every one of these 12 keys has a named consumer (§4.4 reconciliation table). `venues.displayName`/`venues.displayIcon` from 01 §4 stay dropped (02 §2.2 deviation, upheld).

### 1.5 Role seed — exact SQL (migration-159 idiom verified this session)

```sql
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'venue_manager', 'Venue Manager'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'venue_manager');
```

### 1.6 Routes seed — exact SQL (all 26 rows; every targetFile ships REAL in this PR — no stubs)

```sql
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('venues',                       'venues/index.php',                1),
    ('venues/venue',                 'venues/venue.php',                1),
    ('venues/manage',                'venues/manage.php',               1),
    ('venues/venue-save',            'venues/venue-save.php',           1),
    ('venues/rooms',                 'venues/rooms.php',                1),
    ('venues/usage-types',           'venues/usage-types.php',          1),
    ('venues/statuses',              'venues/statuses.php',             1),
    ('venues/booking',               'venues/booking.php',              1),
    ('venues/booking-save',          'venues/booking-save.php',         1),
    ('venues/generate',              'venues/generate.php',             1),
    ('venues/import',                'venues/import.php',               1),
    ('venues/agreements',            'venues/agreements.php',           1),
    ('venues/agreement-save',        'venues/agreement-save.php',       1),
    ('venues/agreement-files',       'venues/agreement-files.php',      1),
    ('venues/agreement-download',    'venues/agreement-download.php',   1),
    ('venues/invoices',              'venues/invoices.php',             1),
    ('venues/invoice',               'venues/invoice.php',              1),
    ('venues/invoice-save',          'venues/invoice-save.php',         1),
    ('venues/invoice-payment-save',  'venues/invoice-payment-save.php', 1),
    ('venues/invoice-download',      'venues/invoice-download.php',     1),
    ('venues/invoice-pdf',           'venues/invoice-pdf.php',          1),
    ('venues/export',                'venues/export.php',               1),
    ('venues/schedule-pdf',          'venues/schedule-pdf.php',         1),
    ('venues/settings',              'venues/settings.php',             1),
    ('cron/venue-reminders',         'cron/venue-reminders.php',        0),
    ('help/venues',                  'help/venues.php',                 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
```

**NOTHING for `api/venues/*` in tblRoutes** — ApiRouter ignores tblRoutes for `api/*` (the #372 dead-route lesson). The two API handlers are gated solely by the two `api.venues.*.enabled` flags in §1.4.

### 1.7 Self-record (last statement in the file)

```sql
INSERT INTO `tblMigrations` (`filename`) VALUES ('170_venue_bookings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
```

### 1.8 full_schema.sql fold (same commit as the migration — `check_php_table_refs.py` requires it)

1. **Placement:** ONE new commented block inserted **after** the Asset Tracker routes seed's terminating line (`ON DUPLICATE KEY UPDATE \`targetFile\` = VALUES(\`targetFile\`);`, currently ~l.6707) and **before** the `-- ####…` banner of `SECTION 7B: MARK MIGRATIONS 082-083 + 090-145 AS EXECUTED` (currently ~l.6710). Anchor by those text strings. Rationale comment to open the block (mirrors the asset block's own out-of-order note at l.6100-6107):
   ```sql
   -- -----------------------------------------------------------------------------
   -- Migration 170: Venue Bookings (#VEN) — placed out of numeric order, at the
   -- end of the table sections, because tblVenues FKs tblAssetOrgs (defined in
   -- the Asset Tracker block above) and tblVenueBookings FKs tblEvents/tblUsers
   -- (defined far earlier) — all FK targets must already be in scope.
   -- -----------------------------------------------------------------------------
   ```
2. **Content:** the 15 CREATE TABLE blocks (§1.3, byte-identical to the migration), then the settings seed (§1.4), role seed (§1.5), and routes seed (§1.6) — seeds beside their tables, inside the same block.
3. **Self-record fold:** append at the **very end of the file**, after the `165_widen_totp_secret.sql` record (and after any 166–169 records other branches add — put it last):
   ```sql
   INSERT INTO `tblMigrations` (`filename`) VALUES ('170_venue_bookings.sql')
   ON DUPLICATE KEY UPDATE `filename` = `filename`;
   ```
4. **NO edit to the `tblPayment` CREATE TABLE** (~l.4092) — the Q3 override. `git diff web/_sql/full_schema.sql | grep -i tblPayment` must be empty.

### 1.9 The `--` COMMENT rule (hard rule, both files)

No literal `--` (two consecutive hyphens) anywhere inside a quoted string or COMMENT in `170_venue_bookings.sql` or the fold — the parity checker (and the idempotency checker) strip from `--` to end-of-line without quote awareness. The 01 DDL already uses `—`/`–` (em/en dash) — keep them; never "normalise" them to `--`. Gate:
```bash
# every occurrence of -- must start at column 1 or be preceded only by whitespace (i.e. a real comment line)
awk '/--/ && $0 !~ /^[[:space:]]*--/' web/_sql/170_venue_bookings.sql   # expect NO output
```
(Apply the same awk to the full_schema diff hunks you add.)

---

## 2. Build order + agent decomposition

### 2.1 Topology

```
        ┌──────────────────────────────────────────────────────────┐
        │ AGENT A — FOUNDATION (must land first; everything else   │
        │ builds against its Venues.php signatures + migration)     │
        └──────────────────────────────────────────────────────────┘
              │
   ┌──────────┼──────────────┬───────────────────┬─────────────┐
   ▼          ▼              ▼                   ▼             ▼
AGENT B    AGENT C        AGENT D            AGENT F        (wait)
schedule/  config/        agreements/money/  help + docs
bookings/  import         cron/API
generator                                                    │
   └──────────┴──────────────┴───────────────────────────────┘
                             ▼
        ┌──────────────────────────────────────────────────────────┐
        │ AGENT E — CALENDAR INTEGRATION + GDPR + i18n             │
        │ (touches SHARED calendar files — runs ALONE, after A;    │
        │  may run parallel with B/C/D/F since file sets are       │
        │  disjoint, but never split E's files across two agents)  │
        └──────────────────────────────────────────────────────────┘
```

- **Worktrees:** one git worktree per agent off the feature branch (§5); file sets below are **provably disjoint** — merges are conflict-free by construction. Agent A merges to the feature branch before B–F branch off (they compile against `Venues.php`).
- **Every agent reads, in order, before writing a line:** `01b-review-resolutions.md`, `02b-review-resolutions.md`, this file's §0 + its own spec, then the cited §§ of `02-app-design.md` and `01-data-model.md`, then each pattern file cited for its work. Pattern citations from 02 §0 are already repo-verified — copy patterns, never guess.
- **Universal handler conventions** (every B/C/D file): 02 §4.0 verbatim — file-header block (path, description, `@package Portal\Venues`, author/copyright/All Rights Reserved/`@version 1.0.0`, `@link` #VEN), `declare(strict_types=1);`, emoji section comments, full IF notation, `Auth::ensureSession(); Auth::requireLogin();`, manager gate `if (Venues::canManage() !== true) { Router::renderError(403); return; }`, CSRF **first** on every POST, PRG flash+redirect, every GET/POST id re-fetched with `siteID = Site::id()` (miss ⇒ `Router::renderError(404)` — uniform, no oracle), prepared statements only, `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on all output, `portal-data-list` (NEVER `<table>`), `data-confirm` (never native `confirm()`), header/footer via `require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php'` (resources/index.php l.16-47 shell).

### 2.2 AGENT A — Foundation (Sonnet — this is the precision-critical agent)

**Files (4):**
1. `web/_sql/170_venue_bookings.sql` — §1 verbatim.
2. `web/_sql/full_schema.sql` — §1.8 fold.
3. `web/_core/Venues.php` — the full class per §3 below (~every method; this is the biggest single file of the build).
4. `web/_core/apps/venues.php` — 02 §2.1 verbatim (12-key array, `color => '#b45309'`, `settingKey => 'venues.enabled'`, `route => 'venues'`).

**Contracts:** §3 of this plan IS the Venues.php contract (signatures normative — B/C/D/E call them as written). Class file: `namespace Portal\Core;`, filename exactly `Venues.php` (PSR-4-lite autoloader, bootstrap l.141-146), class-header doc block MUST contain: (a) the wall-clock rule — *"Booking rows are wall-clock venue-local DATE+TIME. tblEvents datetimes are wall-clock event-local in event.timezone/eventTimezone (the column comment saying 'stored in UTC' is a known doc bug — see #VEN follow-ups). classifyEventCoverage compares wall-clock to wall-clock; when the two IANA zones are equal there is NO conversion at all; UTC is never involved. Never 'fix' bookings or events into UTC."* (b) the Q12 room note — *"classifyEventCoverage rule (b) must tighten to room-aware coverage (b.roomID IS NULL OR b.roomID = event.roomID) if events ever gain room placement."* (c) the audit funnel — *"Every mutation funnels through self::audit() → Logger::audit() → tblAuditTrail; bulk ops write ONE summary row."*

**Security items owned (02 §12):** 2 (cross-tenant probes live in the class), 5 (parseWorkbook caps), 7 (all SQL prepared), 11 (pence integrity), 13 (✎ audit completeness).

**Self-verification (run all, zero findings):**
```bash
php -l web/_core/Venues.php && php -l web/_core/apps/venues.php
for f in tools/audit-checks/check_*.py; do python3 "$f" --strict || echo "FAIL $f"; done
grep -c "ALTER TABLE" web/_sql/170_venue_bookings.sql                      # must print 0
awk '/--/ && $0 !~ /^[[:space:]]*--/' web/_sql/170_venue_bookings.sql      # must print nothing
grep -c "CREATE TABLE IF NOT EXISTS" web/_sql/170_venue_bookings.sql       # must print 15
git diff web/_sql/full_schema.sql | grep -c "^+.*CREATE TABLE IF NOT EXISTS"  # must print 15
git diff web/_sql/full_schema.sql | grep -i "tblPayment"                   # must print nothing
tools/e2e-migrations/run.sh                                                # docker MySQL 8 harness — full pass
```
Note: until B–F land, `check_route_targets.py` WILL fail (26 routes, 0 handlers). Agent A runs it to see ONLY the 26 expected venue rows listed, records that in its report, and the epic gate (§4) requires it green at PR time. All other checks must be green for A in isolation.

### 2.3 AGENT B — Schedule, booking editor, generator, exports (Sonnet)

**Files (7), all new under `web/_apps/venues/`:**
`index.php`, `venue.php`, `booking.php`, `booking-save.php`, `generate.php`, `export.php`, `schedule-pdf.php`

**Per-file contract** (build from 02 §4.1/§4.3/§4.4/§9.1/§9.3 verbatim; summary):

| File | Route | Method | Gate | Key inputs | Mutations via | Renders |
|---|---|---|---|---|---|---|
| index.php | `venues` | GET | viewer | `venue,year,status,type,rejected,room` (all optional ints/flags) | — (calls `Venues::seedStatuses(Site::id())` first — idempotent) | month-grouped `portal-data-list` year schedule; manager toolbar (+ Booking/Generate/Import/Agreements/Invoices/Config▾); viewer toolbar CSV+PDF; `#today` anchor; cost + Edit column only when `Venues::canManage()` |
| venue.php | `venues/venue` | GET | viewer (manager sees money/actions) | `id` | — | identity/rooms/usage-types/agreements-summary/next-10-bookings cards |
| booking.php | `venues/booking` | GET | viewer read-only; manager form | `id` XOR `venue[&date]` | — | form per 02 §4.3 incl. windows-JSON progressive enhancement (`json_encode` embedded, JS prefills times; server stays authoritative); room select only when venue has active rooms; group panel; danger zone |
| booking-save.php | `venues/booking-save` | POST only | manager (re-checked) | `action ∈ {save, delete, group-status, group-delete}` + fields | `saveBooking` / `softDeleteBooking` / `setGroupStatus` / `softDeleteGroup` | PRG redirects; errors flashed joined |
| generate.php | `venues/generate` | GET+POST | manager | form params 02 §4.4; `?group=N` extend mode; POST `action ∈ {preview, commit}` | `previewSeries` (no writes) / `generateSeries` | two-step preview→commit; commit NEVER trusts the previewed date list (re-expands server-side) |
| export.php | `venues/export` | GET | viewer; Cost column ONLY when `canManage()` | `venue, year` or `from/to` | — | `CsvExporter::download('venue-schedule-….csv', $rows, $headers)` — columns Date, Day, Venue, Room, Usage type, Times, Status, Notes (+Cost) |
| schedule-pdf.php | `venues/schedule-pdf` | GET | viewer; cost manager-only | as export | — | CSS-Grid HTML (NO `<table>` even in PDF) → `Pdf::create()` → stream (my-statement.php l.26-37 headers) → `unlink()`; `false` ⇒ print-CSS fallback (labels-pdf.php l.192-207) |

**Cross-tenant invariants:** booking-save posts NOTHING that `Venues::saveBooking()` doesn't re-validate (venue∈site+active, status∈site, usageType∈venue, room∈venue, event∈site, agreement∈venue, group∈venue — §3.4); the handler's own job is CSRF-first + id-refetch-with-`Site::id()` + flash/redirect. generate.php validates `group∈site+venue` on extend mode before prefill.
**Security items owned:** 1 (IDOR on booking/venue/group ids), 6 (CSRF), 8 (XSS incl. notes), 9 (no redirect from input), 11 (pounds↔pence only at boundary), 12 (booking-save re-checks canManage).
**Self-verification:**
```bash
for f in web/_apps/venues/{index,venue,booking,booking-save,generate,export,schedule-pdf}.php; do php -l "$f"; done
grep -L "declare(strict_types=1)" web/_apps/venues/*.php                    # empty
grep -L "Site::id()" web/_apps/venues/{index,venue,booking,booking-save,generate,export,schedule-pdf}.php  # empty
grep -l "<table" web/_apps/venues/ -r                                       # empty
grep -c "verifyCsrf" web/_apps/venues/booking-save.php web/_apps/venues/generate.php   # ≥1 each
python3 tools/audit-checks/check_no_native_confirm.py --strict
python3 tools/audit-checks/check_mobile_readiness.py --strict
python3 tools/audit-checks/check_php_table_refs.py --strict                 # needs A's fold merged
python3 tools/audit-checks/check_sql_columns.py --strict
```

### 2.4 AGENT C — Venue/vocab config + settings + import wizard (Sonnet)

**Files (7), all new under `web/_apps/venues/`:**
`manage.php`, `venue-save.php`, `rooms.php`, `usage-types.php`, `statuses.php`, `settings.php`, `import.php`

| File | Route | Method | Gate | Key contract |
|---|---|---|---|---|
| manage.php | `venues/manage` | GET | manager | venue list + inline `?edit=` form (orgs.php l.84-99 prefill); landlord select from `AssetRegister::listOrgs($siteId, true)` + collapsible "New landlord…" fieldset; alarm-code helper text (02 §4.2); `/assets/orgs` deep-link only when `AppRegistry::isEnabled('assets')` |
| venue-save.php | `venues/venue-save` | POST only | manager; `delete` action re-checks `App::isAdmin()` | actions `save` (inline landlord → `AssetRegister::saveOrg($siteId, 0, …)`, 0 ⇒ flash+stop; then `Venues::saveVenue`; create ⇒ redirect `/venues/usage-types?venue=N` with seeded-types flash), `landlord_edit` (org∈site probe THEN `saveOrg($siteId,$orgId,…)`), `toggle`, `delete` (`Venues::deleteVenue`, refusal flashed) |
| rooms.php | `venues/rooms` | GET+POST self-posting | manager | `?venue=N` ∈site or 404; actions save/toggle/delete via `saveRoom`/`toggleRoomActive`/`deleteRoom`; future-bookings count column |
| usage-types.php | `venues/usage-types` | GET+POST | manager | `?venue=N` ∈site; actions save-type/toggle-type/add-window/delete-window/reapply per 02 §4.2; usageKind locked (tooltip) when bookings reference the type; effective-dating callout |
| statuses.php | `venues/statuses` | GET+POST | manager | per-SITE; `seedStatuses()` on GET; actions save/toggle/restore per 02 §4.2 incl. the countsAsConfirmed callout; colour input + "use category default" clear |
| settings.php | `venues/settings` | GET+POST | **admin only** (`App::isAdmin()`) | curated `venues.*` form per 02 §4.8. **WRITE IDIOM (this plan's resolution of 02 open Q5):** per-site rows (`calendar_default_venue`, `reminder_roles`, the 3 lead-day ints, `currency`, `reminders_enabled`) upsert with `siteID = Site::id()` — ODKU fires (both key columns non-NULL). The global `venues.cron_token` (siteID NULL, isSensitive 1) MUST use **update-then-insert** (translation/save.php l.43-55 shape) — NULL rows are NOT deduped by the unique key. Token regenerate = `bin2hex(random_bytes(32))`, shown ONCE in the flash with the full cron URL (plain-value + isSensitive=1 write is house precedent: qr/index.php l.51-58) |
| import.php | `venues/import` | GET+POST | manager | the 3-step wizard 02 §4.5 verbatim (upload → vocab-map loop → preview → commit/cancel), batch state machine on `tblVenueImportBatches.status`; `class_exists('ZipArchive') === false` ⇒ XLSX disabled up-front + CSV-fallback notice; duplicate-hash soft warning; every `?batch=N` access validates `batch.siteID = Site::id()`; **fuzzy suggestion pinned (02b #8):** case-fold + strip non-alnum both sides; suggest the seed status whose leading category token (`proposed|agreed|rejected|pending|standard`) equals the raw value's leading token; ties or no token match ⇒ NO preselection (never auto-apply) |

**Security items owned:** 1, 3 (import upload sniffing: xlsx ⇒ finfo `application/zip` or the xlsx MIME **and** `.xlsx` ext; csv ⇒ `text/csv|text/plain|application/csv` **and** `.csv`; size cap `venues.maxFileSize`, 10 MB fallback), 6, 8 (raw cells `htmlspecialchars`'d in wizard preview), 12 (settings.php admin gate; venue-delete admin re-check).
**Self-verification:** as Agent B's list, over its 7 files, plus:
```bash
python3 tools/audit-checks/check_settings_keys.py --strict
grep -n "ON DUPLICATE" web/_apps/venues/settings.php     # must appear ONLY for per-site (Site::id()) writes, never the NULL cron_token write
```

### 2.5 AGENT D — Agreements, invoices/payments, downloads, cron, API (Sonnet)

**Files (13):** `web/_apps/venues/`: `agreements.php`, `agreement-save.php`, `agreement-files.php`, `agreement-download.php`, `invoices.php`, `invoice.php`, `invoice-save.php`, `invoice-payment-save.php`, `invoice-download.php`, `invoice-pdf.php`; `web/_apps/venues/api/`: `check.php`, `availability.php`; `web/_apps/cron/venue-reminders.php`

| File | Route/endpoint | Method | Gate | Key contract |
|---|---|---|---|---|
| agreements.php | `venues/agreements` | GET | manager | 02 §4.6: list + `?edit=` inline form + files sub-list/upload form; renewal countdown badge (danger ≤30 days) |
| agreement-save.php | `venues/agreement-save` | POST | manager | actions save / set-status (manual family `active,terminated,expired` only) / supersede → `saveAgreement`/`supersedeAgreement` |
| agreement-files.php | `venues/agreement-files` | POST | manager | actions upload (§12-item-3 pipeline → `_uploads/venues/agreements/`, mkdir 0755 recursive) / delete → `attachAgreementFile`/`deleteAgreementFile` |
| agreement-download.php | `venues/agreement-download` | GET | manager | site-scoped fetch ⇒ uniform 404; `basename()` + `is_file`; stream attachment headers; NEVER 403 (anti-oracle, resource-download.php l.11-37) |
| invoices.php | `venues/invoices` | GET | manager | ledger list 02 §4.7 + pence-int footer totals |
| invoice.php | `venues/invoice` | GET | manager | 4 sections per 02 §4.7 (form / allocation lines / payments / status+delete buttons) |
| invoice-save.php | `venues/invoice-save` | POST | manager; `delete` re-checks admin | actions save (single attachment → `_uploads/venues/invoices/`; replacement unlinks old AFTER row update) / allocate / remove-line / set-status (disputed,cancelled ONLY) / delete |
| invoice-payment-save.php | `venues/invoice-payment-save` | POST | manager | actions add / delete → `recordPayment`/`deletePayment`; flash includes recomputed status + outstanding |
| invoice-download.php | `venues/invoice-download` | GET | manager | same anti-oracle streaming contract, rooted `_uploads/venues/invoices/` |
| invoice-pdf.php | `venues/invoice-pdf` | GET | manager | CSS-Grid HTML → `Pdf::create` → stream+unlink; print-CSS fallback (02 §9.2) |
| api/check.php | `GET /api/venues/check` | GET | `ApiResponse::requireAuth()` | 02 §5.3 verbatim: params `start`,`end` (`Y-m-d\TH:i`), `tz` (IANA, default Europe/London), `venueID` (optional, validated ∈ `Site::id()`, else `venues.calendar_default_venue`); dormant ⇒ `success` with `{classification:'not-configured', severity:'none'}`; invalid ⇒ `ApiResponse::error('invalid-range', 400)`. **`ApiResponse::success()` — `::ok()` does not exist.** `global $mysqli, $SETTINGS` are already imported by ApiRouter (#373) |
| api/availability.php | `GET /api/venues/availability` | GET | `ApiResponse::requireAuth()` | params `from`,`to` (`Y-m-d`, range ≤ 400 days else 400), optional `venueID` (site-validated); returns `{days:{...}}` from `availabilityForRange` — **no cost columns** (the §8.1 column list has none) |
| cron/venue-reminders.php | `cron/venue-reminders` (isProtected 0) | GET | token | clone `cron/asset-reminders.php` (cite it in the header), narrowed to the three sweeps of 02 §6: gate (§0 shape, `venues.cron_token`, empty ⇒ 403) → `Content-Type: text/plain` → site loop over `SELECT DISTINCT siteID FROM tblVenues WHERE isActive = 1`, skip unless `App::settingForSite('venues.enabled', $siteId) ∈ {'1','true'}` and `…reminders_enabled ≠ '0'`, `Site::forceContext($siteId)` (NO reset after loop — asset precedent, process exits) → three sweeps via `dueUnagreedBookings`/`dueAgreementRenewals`/`dueInvoices` + `reminderAlreadySent` check-first + `Mailer::send` per recipient + `logReminder` (recipientCount 0 STILL logs) → per-site + grand-total plain-text summary. Absolute URLs via local `venueReminderUrl()` (scheme+host from `$_SERVER`). Subjects via `I18n::t('venues.reminder.*_subject', [...])`; **no money amounts in `booking-unagreed` bodies**; amounts fine in `invoice-due` |

**Security items owned:** 1+4 (both download handlers), 3 (agreement/invoice uploads vs `Venues::AGREEMENT_FILE_MIME_EXT`), 6, 10 (cron token constant-time, empty⇒403), 11 (setInvoiceStatus whitelist excludes the paid family), 12 (invoice hard-delete admin re-check; API selects no cost).
**Self-verification:** Agent B's command list over these 13 files, plus:
```bash
grep -rn "ApiResponse::ok" web/_apps/venues/        # empty — ::ok() does not exist
grep -c "hash_equals" web/_apps/cron/venue-reminders.php   # ≥1
grep -n "requireAuth" web/_apps/venues/api/*.php    # both files
```

### 2.6 AGENT E — Calendar integration + GDPR + i18n (Sonnet — the ONLY agent allowed to touch shared files)

**Files (14): 1 new, 13 edits — every shared-file edit of the whole epic lives here, so no other agent can conflict.**

| File | Change |
|---|---|
| NEW `web/_apps/calendar/views/_venue_strip.php` | function-partial (guarded `function_exists`, `_day_columns.php` l.31 idiom): `render_venue_strip(string $dateKey, array $venueOverlay): string` + `venue_strip_day_color(string $dateKey, array $venueOverlay): ?string`, per 02 §5.1(2) verbatim (rejected rows filtered; hatch for tentative; `fa-door-closed` closed / `fa-ban` unavailable rendered DISTINCTLY; all text `htmlspecialchars`'d; colours re-validated hex before entering `style=""`; ≤20-line scoped CSS in the partial) |
| `web/_apps/calendar/index.php` | insert the canonical guard block (02 §5.0: `AppRegistry::isEnabled('venues')` + try/catch `\Throwable` ⇒ `$venueOverlay = []`) immediately after the events fetch; list view (`$rangeStart === null`) skips by design |
| `views/month.php` | `require_once … _venue_strip.php` + `echo render_venue_strip($k, $venueOverlay);` inside the day cell (after date-number header, before event pills) |
| `views/_day_columns.php` | optional 3rd param `array $venueOverlay = []`; strips in each day column's all-day area |
| `views/day.php`, `week.php`, `weekdays.php`, `weekend.php` | each passes `$venueOverlay` to `render_day_columns()` — modify ALL FOUR |
| `views/year.php` | worst-severity 3px inset bottom bar via `venue_strip_day_color()` |
| `views/_shared_header.php` | 4-swatch legend when `count($venueOverlay) > 0` |
| `manage/index.php` | compute `$defaultVenueId` once (`(int) Settings::get('venues.calendar_default_venue', '0')`, guarded by `AppRegistry::isEnabled('venues')`) and pass into the form include scope |
| `manage/_event_form.php` | Surface A per 02 §5.2: hidden alert div + debounced-fetch inline script (`textContent` ONLY, never innerHTML; fetch error ⇒ silent re-hide; fires once on load in edit mode) |
| `manage/save.php` | Surface B per 02 §5.2: the guarded post-save `classifyEventCoverage` warning block in BOTH create and update branches — flash-append ONLY, **never blocks the save**; `getVenue`-null ⇒ suppressed (dormant) |
| `web/_core/GdprEraser.php` | the 02 §8 comment block + 3 entries **verbatim**, inserted immediately BEFORE the final `tblUsers` entry (l.193). The three entries: `tblVenueBookings.updatedByID`, `tblVenueUsageTypeWindows.createdByID`, `tblVenueImportBatches.createdByID` — all anonymise, `nullCols: []` (all three columns are `DEFAULT NULL` + `ON DELETE SET NULL` — the UPDATE prepares) |
| `web/_apps/auth/account/data-export.php` | **(corrected path — NOT `_apps/account/`)** add three `'data'` entries with the existing `$fetchUserRows` shape: `'venueBookingsEdited' => $fetchUserRows('SELECT bookingID, venueID, bookingDate, updatedAt FROM tblVenueBookings WHERE updatedByID = ?')`, `'venueUsageWindowsCreated' => $fetchUserRows('SELECT windowID, usageTypeID, effectiveFrom, createdAt FROM tblVenueUsageTypeWindows WHERE createdByID = ?')`, `'venueImportBatches' => $fetchUserRows('SELECT batchID, venueID, fileName, status, createdAt FROM tblVenueImportBatches WHERE createdByID = ?')` — export/erasure parity. Also extend the file-header table list comment |
| `web/_lang/en.php` | append the `// 🏛️ Venue Bookings` block — the 02 §10 key list verbatim (28 keys: nav.venues … venues.reminder.invoice_subject) |

**Resilience contract (security item 14):** with `venues.enabled` unset/'0', or `_core/apps/venues.php` missing, or `Venues::` throwing — the calendar renders byte-identically to today and event saves succeed. Every venue touch in these files is inside `AppRegistry::isEnabled('venues') === true` + try/catch `\Throwable`.
**Self-verification:**
```bash
for f in $(git diff --name-only -- 'web/_apps/calendar/*' 'web/_core/GdprEraser.php' 'web/_apps/auth/account/data-export.php' 'web/_lang/en.php'); do php -l "$f"; done
grep -n "innerHTML" web/_apps/calendar/manage/_event_form.php    # empty
grep -c "isEnabled('venues')" web/_apps/calendar/index.php web/_apps/calendar/manage/save.php web/_apps/calendar/manage/index.php   # ≥1 each
php -r "require 'web/_lang/en.php';" 2>&1 | grep -v "^$"         # parse check (returns array — no output = clean)
# GDPR erasure dry-run parse (asserts the 3 new UPDATEs prepare against migration-170 schema — run inside the e2e harness DB):
#   see §4.5 gate G7
python3 tools/audit-checks/check_php_table_refs.py --strict
python3 tools/audit-checks/check_sql_columns.py --strict
```

### 2.7 AGENT F — Help page + documentation (Haiku-suitable)

**Files (6):** NEW `web/_apps/help/venues.php`; edits `FEATURES.md`, `CHANGELOG.md`, `DEV_NOTES.md`, `.claude/CLAUDE.md`, plus filing the two follow-up issues' text into the PR description (§7).

- `help/venues.php`: shape of `help/assets.php` l.24-50 (TOC badges + sections), **public** (route seeded isProtected 0 by Agent A), the 12-section outline of 02 §11.1 verbatim — including §7 "One day, several activities" (Q13 recommendation) and §12 "Data protection" (caretaker/landlord manual-erasure duty). No `<table>`; no login requirement; standard header/footer templates.
- Docs: §6 of this plan gives copy-ready text.
**Self-verification:** `php -l web/_apps/help/venues.php`; `grep -n "<table" web/_apps/help/venues.php` (empty); `python3 tools/audit-checks/check_mobile_readiness.py --strict`; markdown files render (visual check).

---

## 3. `Portal\Core\Venues` — method-by-method specification

Constants: 02 §3.1 **verbatim** (USAGE_KINDS … KIND_COLORS, AGREEMENT_FILE_MIME_EXT), plus fill the two private seed constants:

```php
private const DEFAULT_STATUSES = [
    // [statusName, statusCategory, countsAsConfirmed, isAvailable, sortOrder] — 01 §3.5 seed table
    ['Standard Agreement',                  'standing', 1, 1, 10],
    ['Pending Leadership Agreement',        'proposed', 0, 1, 20],
    ['Proposed to Landlord',                'proposed', 0, 1, 30],
    ['Agreed by Landlord',                  'agreed',   1, 1, 40],
    ['Rejected by Landlord',                'rejected', 0, 0, 50],
    ['Rejected – building already in use',  'rejected', 0, 0, 60],   // en dash, NOT --
];
private const DEFAULT_USAGE_TYPES = [
    // [typeName, usageKind, defaultStart|null, defaultEnd|null, sortOrder] — 01 §4
    ['Regular Hours',       'hire',        '09:30', '13:30', 10],
    ['Extended',            'hire',        '09:00', '15:00', 20],
    ['Extended (All Day)',  'hire',        '09:30', '17:30', 30],
    ['Custom',              'hire',        null,    null,    40],
    ['Closed - Not Needed', 'closed',      null,    null,    50],
    ['Building Unavailable','unavailable', null,    null,    60],
];
```

Full method surface = 02 §3.2 (signatures there are normative; every ✎ method calls `self::audit()` exactly once per logical mutation). Algorithms for the non-trivial methods:

### 3.1 `seedStatuses(int $siteId): void`
1. Short-circuit: `SELECT 1 FROM tblVenueStatuses WHERE siteID = ? LIMIT 1` — row exists ⇒ return (the hot path costs one indexed probe).
2. Else per DEFAULT_STATUSES row: `SELECT 1 … WHERE siteID = ? AND statusName = ?`; absent ⇒ prepared INSERT. Wrap each INSERT in try/catch `\mysqli_sql_exception` swallowing duplicate-key (1062) only — `uq_venst_site_name` is the race backstop. **No audit rows** (system bootstrap).

### 3.2 `restoreDefaultStatuses(int $siteId, int $actorUserId): int`
Per DEFAULT_STATUSES row: exists+inactive ⇒ `UPDATE … SET isActive = 1`; absent ⇒ INSERT; exists+active ⇒ skip. Count touched; ONE audit row (`tblVenueStatuses`, recordId 0, `create`, newData `{restored:[names]}`); return count.

### 3.3 `seedUsageTypes(int $siteId, int $venueId, int $actorUserId): void`
Per DEFAULT_USAGE_TYPES row: INSERT `tblVenueUsageTypes` (`isBookable = (kind === 'hire') ? 1 : 0`), then ONE `tblVenueUsageTypeWindows` row `effectiveFrom = '1970-01-01'` with the listed times (nullable pair — Custom/closed/unavailable get a NULL-times window; keeping a window row for every type makes `resolveWindow` uniform). No own audit (the venue-create audit row's newData carries `seededUsageTypes: 6`).

### 3.4 `saveBooking(int $siteId, int $bookingId, array $data, int $actorUserId): array{id: int, errors: string[]}`
THE invariant choke-point. Steps:
1. `$bookingId > 0` ⇒ fetch existing with `siteID = $siteId` (absent ⇒ `errors[] = 'not found'`, id 0).
2. Validate referenced rows via the private site-scoped probes — each is one prepared SELECT, failure appends an error: `validateVenue` (`venueID ∈ site`, and `isActive = 1` on create); `validateStatusForSite` (`status.siteID == $siteId`; `isActive = 1` required only when the status is being CHANGED — an edit keeping a retired status passes); `validateUsageTypeForVenue` (`usageType.venueID == posted venueID`; active-on-change same rule); `validateRoomForVenue` (when roomID set); `validateEventForSite` (`event.siteID == $siteId AND isDeleted = 0`, when set); `validateAgreementForVenue` (when set); `validateGroupForVenue` (when set).
3. `bookingDate` = valid `Y-m-d` (checkdate); `costPence` NULL or int ≥ 0; `currency` 3-alpha.
4. **Time resolution:** usage type kind ≠ 'hire' ⇒ force `startTime/endTime = NULL`, `timesOverridden = 0`. Kind 'hire': both posted times blank ⇒ `[$s,$e] = resolveWindow($usageTypeId, $bookingDate)` (nullable), `timesOverridden = 0`; posted times present ⇒ validate `H:i` format + `endTime > startTime` (Q4 — cross-midnight is an error string, not silently clamped), then `timesOverridden = (posted pair === resolved default pair) ? 0 : 1`; one time posted without the other ⇒ error.
5. Errors non-empty ⇒ return `{id: 0|$bookingId, errors}` — NO write.
6. Write (INSERT with createdByID / UPDATE with updatedByID), ONE `audit('tblVenueBookings', $id, create|update, $old, $new)`.

### 3.5 `resolveWindow(int $usageTypeId, string $date): ?array`
Exactly one query (01 §3.4 rule): `SELECT defaultStartTime, defaultEndTime FROM tblVenueUsageTypeWindows WHERE usageTypeID = ? AND effectiveFrom <= ? ORDER BY effectiveFrom DESC LIMIT 1`. No row ⇒ `null`. Row with NULL times ⇒ `['start' => null, 'end' => null]` (a real "no default" answer — callers treat both the same). Caller (not this method) has already validated the type belongs to the right venue.

### 3.6 `classifyEventCoverage(array $event, int $venueId): array` — THE wall-clock algorithm (02b binding)

```
IN : $event = ['startDateTime' => 'Y-m-d H:i[:s]', 'endDateTime' => ?..., 'timezone' => ?IANA]
     $venueId  (caller pre-validated ∈ Site::id())
OUT: ['classification','severity','message','perDay' => [...], 'dataConflict' => bool]

1. $venue = self::getVenue($venueId, Site::id());
   null ⇒ return the DORMANT sentinel: ['classification' => 'venue-missing', 'severity' => 'success', ...]
   (calendar/save.php treats severity 'success' as no-warning; api/check.php reports it explicitly).
2. Resolve zones — with try/catch fallback 'Europe/London' on each:
      $evTz  = new \DateTimeZone((string)($event['timezone'] ?? '') !== '' ? $event['timezone'] : 'Europe/London');
      $venTz = self::venueTimezone($venue);        // tblVenues.timezone, same guarded construction
3. WALL-CLOCK COMPARISON — two paths, NO UTC anywhere:
   a. FAST PATH (the overwhelmingly common case): $evTz->getName() === $venTz->getName()
      ⇒ $start = new \DateTimeImmutable($event['startDateTime'], $venTz);   // NO setTimezone() call
        $end   = endDateTime present ? same : $start->modify('+1 hour');
      The event's stored wall-clock IS venue wall-clock. Identity. Never convert.
   b. CROSS-ZONE PATH (rare): zones differ ⇒
        $start = (new \DateTimeImmutable($event['startDateTime'], $evTz))->setTimezone($venTz);
        $end   = likewise (or $start->modify('+1 hour'));
      i.e. interpret the stored value in the EVENT's zone, re-express in the VENUE's zone.
   In both paths: if $end <= $start ⇒ $end = $start->modify('+1 hour') (single-instant guard).
   PHP's zone database absorbs DST correctly in path (b); path (a) never touches offsets at all —
   which is precisely why the 2025-03-30 spring-forward check (§4.5 G4) must show NO spurious result.
4. Enumerate venue-local dates D from $start->format('Y-m-d') to $end->format('Y-m-d') inclusive;
   HARD CAP 31 days (longer events classify their first 31; note appended to perDay).
5. $days = self::availabilityForRange(Site::id(), $venueId, D_first, D_last).
6. Per date D, local window [ls, le] = (D == first ? $start->format('H:i:s') : '00:00:00')
                                     .. (D == last ? $end->format('H:i:s')   : '24:00:00'),
   FIRST MATCH WINS over that date's rows (the 6-rule precedence, 02 §3.5.2 verbatim):
     a. any row usageKind='unavailable'                                  ⇒ UNAVAILABLE
        (+ dataConflict = true when a countsAsConfirmed hire row coexists that day)
     b. any row countsAsConfirmed=1 AND isBookable=1 AND both times NOT NULL
        AND startTime <= ls AND endTime >= le                            ⇒ CONFIRMED
        (v1 venue-wide — roomID ignored; Q12 header note)
     c. any countsAsConfirmed=1 AND isBookable=1 row exists at all       ⇒ OUTSIDE_HOURS
     d. any row usageKind='closed'                                       ⇒ CLOSED
     e. any row isBookable=1 AND countsAsConfirmed=0
        AND statusCategory <> 'rejected'                                 ⇒ UNCONFIRMED
     f. else (no rows / rejected-only — attach rejected rows as info)    ⇒ NO_BOOKING
   String comparison 'HH:MM:SS' <= 'HH:MM:SS' is safe (fixed-width, zero-padded — TIME column
   values fetch as 'HH:MM:SS'); normalise ls/le to that width. Interior days of a multi-day event
   get le = '24:00:00', which no booking endTime can reach (endTime > startTime rule, TIME max
   23:59:59) ⇒ an interior full day can never classify CONFIRMED; with a confirmed booking present
   it classifies OUTSIDE_HOURS. That is the DESIGNED, conservative behaviour for multi-day events
   (a 24h span is genuinely not covered by a 09:30-13:30 hire); the perDay list in the message
   shows the reader exactly which day and why.
7. Event classification = worst per-day, worst-first:
   UNAVAILABLE > NO_BOOKING > OUTSIDE_HOURS > UNCONFIRMED > CLOSED > CONFIRMED
   (= the key order of COVERAGE_SEVERITY? NO — use an explicit WORST_ORDER const array in this
   exact order and pick the first classification present. Do not derive it from the severity map.)
8. severity = COVERAGE_SEVERITY[classification]; message = self::coverageMessage() via
   I18n::t('venues.coverage.*') with :venue/:date/:window/:status params; multi-day prepends
   'venues.coverage.multi_day_worst'.
```

### 3.7 `availabilityForRange(int $siteId, ?int $venueId, string $dateFrom, string $dateTo): array`
The 01 §8.1 SQL verbatim (site + `isDeleted = 0` + `bookingDate BETWEEN ? AND ?`, optional `venueID = ?`, the exact SELECT column list — **no costPence**), fetched then PHP-grouped `['Y-m-d' => [rows]]`; each row decorated `resolvedColor` (statusColor(); kinds closed/unavailable use KIND_COLORS) and `isRejected = ((int)$row['isBookable'] === 1 && $row['statusCategory'] === 'rejected')`. Date params validated `Y-m-d` before binding; invalid ⇒ `[]`.

### 3.8 `generateSeries(int $siteId, int $venueId, array $params, int $actorUserId): array` (and `previewSeries` = steps 1-3)
02 §3.4 verbatim. The load-bearing exactness:
- **Caps:** `dateTo - dateFrom <= 366 days` AND expanded candidates ≤ 200 — friendly error, no partial work.
- **Weekly/fortnightly inclusion test:** candidate `d` included iff `(int)$d->format('w') ∈ daysOfWeek` AND `intdiv(mondayOf($d)->diff(mondayOf($dateFrom))->days, 7) % $intervalVal === 0` (anchor = ISO week of `dateFrom`; `mondayOf(x) = x->modify('monday this week')` — note PHP's "monday this week" on a Sunday goes FORWARD; use `$d->modify('-' . (((int)$d->format('N')) - 1) . ' days')` instead to be deterministic).
- **Monthly:** the `dateFrom` day-of-month in each month; months lacking it (29/30/31) are reported in `errors`, never silently shifted.
- **Duplicate probe per candidate (Q5):** `SELECT 1 FROM tblVenueBookings WHERE venueID = ? AND bookingDate = ? AND isDeleted = 0 AND roomID <=> ? LIMIT 1` — **NULL-safe `<=>`** binds the roomID param as nullable int. Hit ⇒ `skipped[]`.
- **Commit:** transaction (`begin_transaction`/`commit`, rollback + error on any failure). Group row: new (`groupType`, params, dateFrom, dateTo) or, when `extendGroupID` posted, validate group ∈ site+venue then `UPDATE … SET dateTo = ?` keeping identity. Per surviving date: INSERT booking with `groupID`, `timesOverridden = 0`, `startTime/endTime = resolveWindow($usageTypeId, <that date>)` — **per-date resolution** (a series crossing an `effectiveFrom` boundary picks up the new window mid-series). ONE audit row on the group (`create`, or `update` when extending) `newData = {params, dates, skipped, count}`.
- Commit **re-runs expansion from posted params** — the preview's date list is never trusted.

### 3.9 `parseWorkbook(string $tmpPath, string $sourceKind): array` — native XLSX/CSV parse (§12-item-5 hardening)

Returns `['sheets' => [['name','year' => ?int,'rows' => [['rowNum','rawDate','rawHours','rawTimes','rawStatus','rawNotes']]]], 'backingData' => ['statuses' => string[], 'usageWindowsByYear' => [year => [typeName => ['start','end']]]]]`. Throws `\RuntimeException` with a user-safe message (no paths/internals) on any malformed input.

**CSV path** (`$sourceKind === 'csv'`): `fopen` + `fgetcsv`; first non-empty row = header; map columns by case-insensitive trimmed header name ∈ {DATE, HOURS, TIMES, NOTES, STATUS} (order-free; missing DATE or STATUS ⇒ RuntimeException). One synthetic sheet `['name' => basename, 'year' => null]`. Row cap 5,000; cell cap 500 chars (truncate + flag downstream via stageRows).

**XLSX path — the hardened native read:**
1. Pre-checks: `filesize($tmpPath)` ≤ the caller-passed cap (caller enforced `venues.maxFileSize` already — re-assert defensively); `class_exists('ZipArchive')` (caller guarantees; throw if not).
2. `$zip = new \ZipArchive(); $zip->open($tmpPath)` !== true ⇒ throw. `$zip->numFiles > 200` ⇒ throw ('workbook has too many parts').
3. For EVERY entry read, check `$zip->statIndex($i)['size'] <= 20971520` (20 MB) **BEFORE** `getFromIndex`/`getFromName` — zip-bomb guard.
4. Read ONLY: `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/sharedStrings.xml` (optional), and the `xl/worksheets/sheet*.xml` targets resolved below. Never iterate/extract anything else.
5. XML: `simplexml_load_string($xmlStr, \SimpleXMLElement::class, LIBXML_NONET)` — **never pass LIBXML_NOENT** (entities stay unexpanded; PHP ≥ 8 already refuses external entity loading — do not re-enable). `false` ⇒ throw.
6. `workbook.xml` → `<sheets><sheet name="2024" r:id="rId1"/>` list (namespace-aware: `$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id`). `workbook.xml.rels` → rId → `Target` (e.g. `worksheets/sheet1.xml`; prefix `xl/`, normalise `./`). Sheets whose name parses `/^\d{4}$/` get `year = (int)name`; the sheet named `BackingData` (case-insensitive) is diverted to step 9; other sheets are still staged with `year = null`.
7. Per worksheet: `sharedStrings.xml` `<si>` texts pre-indexed (concatenate all descendant `<t>` per `<si>` — handles rich-text runs). Iterate `<row r="N">` / `<c r="A2" t="…">`: column letter = the leading `[A-Z]+` of `@r`; value = `t="s"` ⇒ sharedStrings[(int)v]; `t="inlineStr"` ⇒ `is/t`; else raw `<v>` string. Row cap 5,000/sheet (excess ⇒ stop + record truncation in the batch's vocabMap notes); cell cap 500 chars (truncate).
8. Header row = first row whose cells (case-insensitive, trimmed) include both `DATE` and `STATUS`; its columns map by header name to rawDate/rawHours/rawTimes/rawNotes/rawStatus. No header row in a sheet ⇒ skip the sheet (recorded), not fatal. Data rows = subsequent rows with any non-empty mapped cell; `rowNum` = the sheet's `@r`.
9. `BackingData` best-effort parse (never fatal — undetected shapes just yield empty suggestions): the column headed `STATUS` (or column A when unheaded) ⇒ distinct non-empty strings → `backingData.statuses`. Window columns: a header row containing 4-digit year tokens ⇒ for each year column, rows pair usage-type name (leftmost text column) → time-range text; each parsed via `parseTimeRange()` → `usageWindowsByYear[year][typeName]`.

### 3.10 `stageRows(int $batchId, int $siteId, int $venueId, array $parsed): array`
Per parsed row, apply the 01 §11.1 rules and INSERT `tblVenueImportRows`:
- **Date:** numeric `rawDate` ⇒ `excelSerialToDate((float)$raw)` = `(new \DateTimeImmutable('1899-12-30'))->modify('+' . (int)$n . ' days')` (the −30 epoch absorbs Excel's phantom 1900-02-29 for all serials ≥ 61; this workbook's are ≥ 45k — verified 45542 → 2024-09-07 Saturday). Text ⇒ DateTime parse d/m/Y-preference. Outside 2000-01-01…2100-12-31 ⇒ `rowState='error'`, `stateNote='implausible date (1904 system?)'`. Blank/header-echo ⇒ `'skipped'`.
- **Hours/Status:** trimmed CI match against the venue's `tblVenueUsageTypes.typeName` / the site's `tblVenueStatuses.statusName` (the CI collation does it in SQL) ⇒ `mappedUsageTypeID`/`mappedStatusID`; miss ⇒ pending entry in the batch `vocabMap` JSON.
- **Times:** `parseTimeRange($rawTimes)` — regex `^\s*(\d{1,2})[:.](\d{2})\s*(?:-|–|—|to)\s*(\d{1,2})[:.](\d{2})\s*$/iu`; sentinel set ⇒ NULL pair: `''`, `'0'`, `'N/A'`, `'-'`, `'—'`, `'<enter times>'`, `'TBC'`, `'TBD'` (all case-insensitive, trimmed). Validate 0≤h≤23, 0≤m≤59, end > start.
- **State:** both vocab ids mapped + date parsed ⇒ `'ready'` (with `stateNote='times missing'` when hire-kind + NULL times + no default — still importable); any vocab unmapped ⇒ `'pending'`; `YEAR(parsedDate) <> sheetYear` ⇒ append `'sheet-year mismatch'` to stateNote (warning, importable).
- Update the batch: `rowCount`, `sheetCount`, `vocabMap` JSON, `status='mapping'`. Return `{staged, unknownHours[], unknownStatuses[]}`.

### 3.11 `applyVocabMap` / `commitBatch`
`applyVocabMap(int $batchId, int $siteId, array $resolutions, int $actorUserId)`: every `create` resolution routes through `saveUsageType()`/`saveStatus()` (identical invariants + audit as manual creation — security item 2); `map` resolutions re-validate the chosen id ∈ venue/site; then bulk-UPDATE matching rows' mapped ids (match on the raw string, CI), re-evaluate `rowState` `pending→ready`, persist the shrunken `vocabMap`.
`commitBatch(int $batchId, int $siteId, int $actorUserId)`: refuse unless batch ∈ site AND `status='mapping'` AND zero `pending` rows. Transaction: per `ready` row insert `tblVenueBookings` (times: parsed pair, else `resolveWindow(mappedUsageTypeID, parsedDate)` with `timesOverridden=0`; parsed==default ⇒ 0 else 1; non-hire kinds NULL+0), stamp `importedBookingID`, set batch `committed`/`committedAt`/counts. `error` rows excluded + counted; `skipped` counted. ONE audit row on the batch (`newData = {imported, skipped, errors, fileName}`). Also `importBackingDataWindows(...)` creates accepted year-windows via `saveWindow()` with `effectiveFrom = 'YYYY-01-01'`.

### 3.12 Invoice/payment helpers
`recomputeInvoiceStatus(int $invoiceID): string` — fetch invoice; status ∈ {disputed, cancelled} ⇒ return unchanged (manual family sticky). Else `SELECT COALESCE(SUM(amountPence),0) FROM tblVenueInvoicePayments WHERE invoiceID = ?`; 0 ⇒ `pending`; < amountPence ⇒ `part-paid`; ≥ ⇒ `paid`. Transition INTO paid ⇒ stamp `paidAt = NOW()`; leaving paid ⇒ NULL it. Machine write bypasses audit (the payment row is the audited event). `recordPayment`/`deletePayment` validate then call it. `setInvoiceStatus` whitelist = `['disputed','cancelled']` ONLY (the paid family cannot be spoofed — security item 11). `deleteInvoice` refuses while payments exist (admin-only caller). `allocateInvoiceLine` validates booking ∈ invoice's venue+site; duplicate `(invoiceID, bookingID)` caught → friendly error.

### 3.13 Reminder helpers
`dueUnagreedBookings(siteId, leadDays)`: 01 §9 row-1 predicate — `JOIN usageTypes/statuses WHERE b.siteID = ? AND b.isDeleted = 0 AND ut.isBookable = 1 AND st.countsAsConfirmed = 0 AND st.statusCategory <> 'rejected' AND b.bookingDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)`.
`dueAgreementRenewals(siteId, leadDays)`: `status = 'active' AND (renewalDate BETWEEN CURDATE() AND +? DAY OR (termEnd IS NOT NULL AND DATE_SUB(termEnd, INTERVAL COALESCE(noticePeriodDays,0) DAY) BETWEEN CURDATE() AND +? DAY))`; each row tagged `trigger ∈ {renewal, notice}` + the `dueDate` to log (`renewalDate` vs `termEnd`).
`dueInvoices(siteId, leadDays)`: `status IN ('pending','part-paid') AND dueDate IS NOT NULL AND dueDate <= DATE_ADD(CURDATE(), INTERVAL ? DAY)` (overdue included by construction).
`reminderAlreadySent`/`logReminder`: check-first SELECT on `(refType, refID, dueDate)`; `logReminder` INSERT in try/catch duplicate-swallow (`uq_venrl_ref` backstop); **recipientCount 0 still logs**.
`resolveReminderRecipients(siteId)`: CSV `venues.reminder_roles` → roleKeys (∪ `{'venue_manager'}` when CSV empty); SQL mirrors `AssetRegister::resolveReminderRecipients` l.8343-8359 — active site users with valid email WHERE admin flags OR `r.roleKey IN (` dynamic `?` placeholders `)`; de-dupe + `FILTER_VALIDATE_EMAIL`.

### 3.14 `audit(...)` and the rest
`audit(string $tableName, int $recordId, string $action, ?array $old, ?array $new, ?int $userId = null): void` — resolve `$userId` from session when null; delegate to `Logger::audit($tableName, $recordId, $action, $old, $new, $userId)` (Logger.php l.111 — changeSet diff + API-key attribution are its job). All remaining methods (listVenues/getVenue/saveVenue/toggleVenueActive/deleteVenue, rooms, usage types + windows + `reapplyWindowDefaults` (per-booking-date `resolveWindow`, `timesOverridden = 0` rows only, `bookingDate >= $fromDate`, one summary audit row, returns count), statuses + `statusColor`, `getBooking`/`listBookings`/`softDeleteBooking`/`setGroupStatus`/`softDeleteGroup`, agreements + files, invoices + lines, `coverageMessage`, private helpers) — build to the one-line contracts of 02 §3.2, which are unambiguous; where a validation list is named there, implement every item.

---

## 4. Verification plan — the "right first time" contract

### 4.1 Per-agent pre-commit (EVERY agent, before every commit)

```bash
# 1. PHP lint — zero errors, every changed file
git diff --name-only --cached -- '*.php' | xargs -r -n1 php -l

# 2. The 10 audit checks — all strict, all green (known exception: check_route_targets
#    is red for Agent A alone until handlers merge — see §2.2 note)
for f in tools/audit-checks/check_*.py; do python3 "$f" --strict || echo "FAIL: $f"; done

# 3. JS (only if a standalone .js was touched — none is planned; inline <script> in PHP
#    is covered by php -l structural validity + manual review)
git diff --name-only --cached -- '*.js' | xargs -r -n1 node --check
```

### 4.2 Epic-level acceptance gates (orchestrator runs before un-drafting the PR)

| # | Gate | Command / procedure | Pass condition |
|---|---|---|---|
| G1 | Migration replay no-op | `tools/e2e-migrations/run.sh` | full harness pass: fresh full_schema install == migration-chain install; replay drift zero; catch-up phase clean |
| G2 | 10/10 audit checks | the §4.1 loop at the PR head | zero findings, including `check_route_targets.py` (26 seeded routes ↔ 26 real files) and `check_schema_seed_parity.py` (170 recorded; 12 settings keys + 26 routes present in BOTH the migration and full_schema) |
| G3 | ZipArchive smoke | `php -r "exit(class_exists('ZipArchive') ? 0 : 1);" && echo OK` locally AND once on the DreamHost target (alpha env, via a temporary probe or SSH) | OK both; if the server ever lacks it, the wizard's built-in CSV-only degradation is the designed behaviour (not a blocker — record the finding) |
| G4 | **DST-boundary functional check** (02b mandatory) | §4.5 script | (i) Europe/London event 2025-03-30 10:00–12:00 vs booking 09:30–13:30 ⇒ CONFIRMED (no spurious OUTSIDE_HOURS across spring-forward); (ii) same-day event 13:00–14:00 ⇒ OUTSIDE_HOURS; (iii) cross-zone: event tz `America/New_York` 05:30–07:30 vs London booking 09:30–13:30 ⇒ CONFIRMED (05:30 NY = 10:30 London that date); (iv) equal-zone path performs NO conversion (assert `format('H:i')` of the compared window equals the stored event time verbatim) |
| G5 | Settings-key reconciliation | `python3 tools/audit-checks/check_settings_keys.py --strict` + the §4.4 consumer table audited by grep | every seeded key has its named consumer present |
| G6 | Security grep-gates | §4.3 — all 14 | every gate passes |
| G7 | GDPR parity | inside the harness DB: run migration 170, then `php -r` harness invoking `GdprEraser` dry-run (or minimally: `PREPARE` each of the 3 catalogue UPDATEs verbatim against the schema: `UPDATE tblVenueBookings SET updatedByID = NULL WHERE updatedByID = ?` etc.) | all three prepare + execute (0 rows OK); `data-export.php`'s three new SELECTs prepare too |
| G8 | Calendar resilience | with `venues.enabled` unset AND with `_core/apps/venues.php` temporarily renamed: load `/calendar` month/week/day/year + save an event | byte-identical calendar behaviour, event save succeeds, zero PHP notices in the log |
| G9 | Manual smoke (§4.6) | on the alpha environment after deploy | every step green |

### 4.3 Security grep-gates (map 1:1 to 02 §12's 14 items — run from repo root)

```bash
# Item 1 — IDOR/site-scope: every venues handler touches Site::id()
grep -L "Site::id()" web/_apps/venues/*.php web/_apps/venues/api/*.php            # EXPECT: empty
# Item 2 — cross-tenant probes: the saveBooking choke-point calls every validator
for p in validateStatusForSite validateUsageTypeForVenue validateRoomForVenue validateEventForSite validateAgreementForVenue; do
  grep -q "$p" web/_core/Venues.php || echo "MISSING $p"; done                    # EXPECT: no output
grep -n "roomID <=>" web/_core/Venues.php                                         # EXPECT: ≥1 (NULL-safe dup probe)
# Item 3 — uploads: finfo everywhere a file is accepted; no client-MIME trust
grep -l "\$_FILES" web/_apps/venues/*.php | xargs grep -L "finfo"                 # EXPECT: empty
grep -rn "AGREEMENT_FILE_MIME_EXT" web/_apps/venues/ | head -1                    # EXPECT: present
# Item 4 — traversal: both download handlers basename() + is_file
for f in web/_apps/venues/agreement-download.php web/_apps/venues/invoice-download.php; do
  grep -q "basename(" "$f" && grep -q "is_file(" "$f" || echo "GAP $f"; done      # EXPECT: no output
# Item 5 — zip caps present
for s in "numFiles" "statIndex" "LIBXML_NONET"; do grep -q "$s" web/_core/Venues.php || echo "MISSING $s"; done
grep -n "LIBXML_NOENT" web/_core/Venues.php                                       # EXPECT: empty (never used)
# Item 6 — CSRF-first on every POST surface
for f in web/_apps/venues/*-save.php web/_apps/venues/{generate,import,rooms,usage-types,statuses,settings}.php; do
  grep -q "verifyCsrf" "$f" || echo "NO CSRF: $f"; done                           # EXPECT: no output
# Item 7 — no interpolated SQL (heuristic: no variable directly inside a query string concat of user input)
grep -rn '\$mysqli->query(.*\$_' web/_apps/venues/ web/_core/Venues.php           # EXPECT: empty
# Item 8 — XSS: no innerHTML; htmlspecialchars in every renderer
grep -rn "innerHTML" web/_apps/venues/ web/_apps/calendar/views/_venue_strip.php web/_apps/calendar/manage/_event_form.php   # EXPECT: empty
# Item 9 — open redirect: no Location header built from input
grep -rn "Location: ' \. \$_\|Location: \" \. \$_" web/_apps/venues/              # EXPECT: empty
# Item 10 — cron token
grep -q "hash_equals" web/_apps/cron/venue-reminders.php || echo "GAP"            # EXPECT: no output
# Item 11 — money: status whitelist excludes paid family
grep -n "'disputed'" web/_core/Venues.php | grep -i "setInvoiceStatus" -A2 -B2    # manual eyeball: whitelist = disputed|cancelled only
# Item 12 — privilege: every -save re-checks canManage; admin actions re-check isAdmin
grep -L "canManage" web/_apps/venues/*-save.php                                    # EXPECT: empty
for f in web/_apps/venues/venue-save.php web/_apps/venues/invoice-save.php web/_apps/venues/settings.php; do
  grep -q "isAdmin" "$f" || echo "NO ADMIN GATE: $f"; done                        # EXPECT: no output
# Item 13 — audit completeness: count audit() call sites vs the ✎ inventory (manual review against 02 §3.2's ✎ list)
grep -c "self::audit(" web/_core/Venues.php                                       # EXPECT: ≥ 20; reviewer ticks each ✎ method
# Item 14 — calendar resilience guards
grep -c "isEnabled('venues')" web/_apps/calendar/index.php web/_apps/calendar/manage/save.php   # EXPECT: ≥1 each
# House style over the whole app
grep -L "declare(strict_types=1)" web/_apps/venues/*.php web/_apps/venues/api/*.php web/_core/Venues.php web/_apps/cron/venue-reminders.php web/_apps/help/venues.php   # EXPECT: empty
grep -rln "<table" web/_apps/venues/ web/_apps/help/venues.php                    # EXPECT: empty
grep -rn "ApiResponse::ok" web/_apps/venues/                                      # EXPECT: empty
```

### 4.4 Settings-key ↔ consumer reconciliation (gate G5's table)

| Key | Named consumer (must exist in shipped code) |
|---|---|
| `venues.enabled` | `AppRegistry::isEnabled('venues')` via `_core/apps/venues.php` settingKey; cron site-skip (`App::settingForSite`) |
| `venues.currency` | booking form default (booking.php); invoice form default (invoice.php) |
| `venues.maxFileSize` | import.php upload cap; agreement-files.php; invoice-save.php |
| `venues.unagreed_lead_days` / `venues.renewal_lead_days` / `venues.invoice_due_lead_days` | cron sweeps via `App::settingForSite` |
| `venues.reminders_enabled` | cron site-skip |
| `venues.reminder_roles` | `Venues::resolveReminderRecipients()`; settings.php form |
| `venues.cron_token` | cron gate; settings.php indicator/regenerate |
| `venues.calendar_default_venue` | calendar manage/index.php + save.php; api/check.php fallback; settings.php form |
| `api.venues.check.enabled` / `api.venues.availability.enabled` | ApiRouter's per-action gate (framework — no venue code reads them directly; this is the house pattern for every api.* flag) |

### 4.5 DST functional check script (gate G4 — run against the harness DB with one venue + one booking seeded)

```bash
php -r '
require "web/_core/bootstrap.php";                       // adjust to harness bootstrap entry
use Portal\Core\Venues;
// Booking seeded: venueID=1 (tz Europe/London), 2025-03-30, 09:30–13:30, confirmed status, hire kind.
$c1 = Venues::classifyEventCoverage(["startDateTime" => "2025-03-30 10:00:00", "endDateTime" => "2025-03-30 12:00:00", "timezone" => "Europe/London"], 1);
assert($c1["classification"] === Venues::COVERAGE_CONFIRMED);                      // spring-forward day, inside window
$c2 = Venues::classifyEventCoverage(["startDateTime" => "2025-03-30 13:00:00", "endDateTime" => "2025-03-30 14:00:00", "timezone" => "Europe/London"], 1);
assert($c2["classification"] === Venues::COVERAGE_OUTSIDE_HOURS);                  // past 13:30 end
$c3 = Venues::classifyEventCoverage(["startDateTime" => "2025-03-30 05:30:00", "endDateTime" => "2025-03-30 07:30:00", "timezone" => "America/New_York"], 1);
assert($c3["classification"] === Venues::COVERAGE_CONFIRMED);                      // 10:30–12:30 London after conversion
echo "DST gate PASS\n";'
```

### 4.6 Manual smoke checklist (gate G9, on alpha after deploy — tick in the PR)

1. `/admin/apps` → enable **Venue Bookings** (card present via the AppRegistry entry; toggling flips `venues.enabled`).
2. `/venues` (as admin) → six default statuses auto-seeded; empty-state card shows.
3. `/venues/manage` → create "Mill Road Baptist Church" venue with inline new-landlord fieldset → lands on `/venues/usage-types?venue=1` with the six seeded types + 1970-01-01 windows.
4. `/venues/generate` → weekly, Saturday, 2026-01-01 → 2026-12-31, Regular Hours, Standard Agreement → preview shows ~52 dates → commit → schedule shows them, times 09:30–13:30; re-run identical params → ALL dates report skipped (duplicate guard).
5. `/venues/import` → upload `Proposed_Mill_Road_Rental.xlsx` → year sheets staged; map "Pending Mill Rd Leadership Agreement" → seed "Pending Leadership Agreement" (fuzzy pre-selection), create one verbatim status; accept a BackingData 2026 window → commit → counts correct; a "times needed" row surfaces on the filtered schedule; re-upload same file → duplicate-hash warning.
6. `/venues/settings` → set calendar default venue = the venue. `/calendar` month view → venue strips render; confirmed vs proposed vs Closed vs Unavailable all visually distinct; legend appears; other views + year dots OK.
7. Calendar → create an event on a booked Saturday inside 09:30–13:30 → live check turns green; on an un-booked Sunday → red "No venue booking exists…" flash after save (save still succeeds).
8. `/venues/agreements` → create a standing agreement (renewal date next month) → countdown badge; upload a PDF; download round-trips; foreign-id download attempt (other site id) → 404 not 403.
9. `/venues/invoice` → create invoice for 4 Saturdays, allocate lines, record 2 part payments → status walks pending → part-paid → paid with `paidAt`; invoice PDF renders (or print-fallback); admin delete refused while payments exist.
10. Set `venues.cron_token`; hit `/cron/venue-reminders?key=…` → plain-text summary; unagreed/renewal/invoice reminders arrive once; second hit → `sent 0` (dedupe). Empty/wrong key → 403.
11. GDPR: export my data → the three venue sections appear; run an erasure against a test user who edited a booking → `updatedByID` nulled.
12. Disable the app → every `/venues/*` route 403s; calendar clean (gate G8's live confirmation).

---

## 5. PR / branch strategy

- **Branch:** `claude/venue-bookings` cut from `alpha` (after `git fetch origin && git checkout -b claude/venue-bookings origin/alpha`). Agents work in worktrees: `git worktree add ../venues-agent-<X> claude/venue-bookings-agent-<X>` branched from the feature branch; each merges back (fast-forward or trivial merge — file sets are disjoint) in the order **A → {B, C, D, F in any order} → E** (E last is cleanest for review even though it only touches non-venue files).
- **ONE PR** (`claude/venue-bookings` → `alpha`) — 02b disposition 10: all 26 handlers ship real, so no stubs, and `check_route_targets.py` is only ever green with the whole surface present. Commit sequence inside the PR mirrors the agent order (one commit per agent minimum, conventional messages: `feat(venues): …`).
- **DRAFT until orchestrator review — mandatory.** `.github/workflows/auto-merge-alpha.yml` enables auto-merge (squash) on every alpha-based PR: the instant this PR is marked Ready with green checks it merges and deploys to the alpha environment. Given the size (~50 files, 15 tables), the orchestrator reviews the ✎-audit inventory (gate item 13), the security grep-gates output pasted into the PR, and gates G1–G8 BEFORE un-drafting. Per the standing instruction, monitor the `pr-security.yml` bot comment + CodeQL/Psalm after every push until clean; fix real findings, justify any true false positive in-thread.
- PR body: the epic issue (`Closes #VEN`), migration number confirmation note, the two follow-up issues (§7), gate results table, smoke-checklist ticks, and the final i18n key list (for `cy.php` maintainers).

---

## 6. Documentation deltas (Agent F — copy-ready)

### 6.1 FEATURES.md — new app section (place alphabetically/with the other apps; adjust heading level to match neighbours)

```markdown
### Venue Bookings (`/venues`)
Tenant-side register for congregations that RENT their building from another
organisation — the mirror-image of Resources (rooms you own) and Assets
(things you own). Records the agreed hire schedule so leaders never plan an
event into an unbooked or unavailable slot. (#VEN, migration 170)

- **Year schedule** — one row per hire date (matches the source spreadsheet
  1:1), month-grouped, with status colours, "times needed" flags and a Today
  anchor; CSV + PDF exports (cost column manager-only).
- **Configurable vocabularies** — per-venue usage types ("Regular Hours",
  "Extended", "Closed - Not Needed", "Building Unavailable") with
  **effective-dated default time windows** (the hire hours can change per
  schedule year), and per-site booking statuses each carrying
  `countsAsConfirmed`/`isAvailable` flags that drive the calendar.
- **Recurring generator** — weekly/fortnightly/monthly/custom series with
  preview, duplicate-skip reporting, and per-date window resolution;
  multi-day runs (e.g. a VBS week) grouped for one-click status/delete.
- **XLSX/CSV import wizard** — native (no-Composer) parser with zip-bomb
  hardening, Excel-serial date conversion, vocab map-or-create step,
  BackingData year-window suggestions, and a CSV fallback path.
- **Calendar integration** — venue-layer strips on every calendar view
  (confirmed / proposed / closed / unavailable rendered distinctly) and an
  "is it booked?" warning on event save + a live event-form check
  (`/api/venues/check`), keyed off `venues.calendar_default_venue`.
- **Hire agreements** — standing/ad-hoc terms, rates (pence-integer), renewal
  + notice-period reminder sweep, document vault with gated downloads.
- **Payable invoice ledger** — money OUT to the landlord: invoices, per-booking
  allocation lines, partial payment records, machine-managed status
  (pending/part-paid/paid), invoice PDF. No card rails — pure tracking.
- **Reminders cron** — `cron/venue-reminders` (token-gated): un-agreed
  bookings, agreement renewals, invoices due; single-shot dedupe log.
- **Roles** — viewer (any logged-in user) vs `venue_manager`/admin (all
  management + costs); admin-only hard deletes + settings.
```

### 6.2 CHANGELOG.md (current release section)

```markdown
### Added — Venue Bookings app (#VEN)
- New `/venues` marketplace app (migration 170, 15 tables): tenant-side hire
  schedule for rented buildings — per-date bookings, per-venue usage types
  with effective-dated default hours, per-site configurable statuses with
  countsAsConfirmed flags, multi-day groups + recurring generator with
  duplicate-skip, native XLSX/CSV import wizard (Mill Road workbook shape),
  hire agreements with renewal reminders, payable invoice + payment ledger,
  calendar overlay + "is it booked?" event warnings, CSV/PDF exports,
  `venue_manager` role, token-gated reminders cron, GDPR eraser/export
  coverage, `/help/venues` guide.
```

### 6.3 DEV_NOTES.md — new "Venue Bookings" heading, three notes

```markdown
## Venue Bookings (#VEN)

### Wall-clock rule (make-or-break)
`tblVenueBookings` times are wall-clock VENUE-local (`DATE` + `TIME`, never
converted to UTC — a 09:30 hire stays 09:30 across DST). `tblEvents`
datetimes are wall-clock EVENT-local in `timezone`/`eventTimezone` (their
column comment claiming UTC is a known doc bug — see the follow-up issue).
`Venues::classifyEventCoverage()` therefore compares wall-clock to
wall-clock: identical IANA zones ⇒ direct comparison, NO conversion;
differing zones ⇒ convert event-zone → venue-zone via DateTimeImmutable.
UTC never appears in this path. Never "fix" either store to UTC.

### Native XLSX parser caps (no Composer)
`Venues::parseWorkbook()` reads xlsx via ZipArchive + SimpleXML with hard
caps: upload ≤ `venues.maxFileSize` (10 MB fallback), ≤ 200 zip entries,
≤ 20 MB per entry (checked pre-extraction), only workbook/rels/sheets/
sharedStrings parts read, `LIBXML_NONET` and never `LIBXML_NOENT`, 5,000
rows/sheet, 500 chars/cell. `ZipArchive` availability is smoke-tested at
deploy; the import wizard degrades to CSV-only with a visible notice.

### Scheduler
Add alongside the other cron lines (token in `venues.cron_token`, seeded
empty = endpoint inert):
    https://<host>/cron/venue-reminders?key=<venues.cron_token>   (daily)
```

### 6.4 `.claude/CLAUDE.md`
1. Apps table, new row (alphabetical position between `translation` and `visitors`):
```markdown
| venues | `/venues` | Tenant-side venue-hire register — schedule of agreed bookings of a rented building, configurable statuses/usage types with effective-dated hours, recurring generator, XLSX import, calendar overlay + "is it booked?" warnings, hire agreements + renewal reminders, payable invoice/payment ledger (#VEN) |
```
2. Directory-layout line: change `(Portal\Core namespace, 26 classes)` → `(Portal\Core namespace, 62 classes)` — the "26" is long-stale; the live count is 61 class files before this feature (verify at build time: `ls web/_core/*.php | grep -vE 'bootstrap|brand-defaults|version' | wc -l`) + `Venues.php`.
3. `_sql` line: `(000-158 + full_schema.sql)` → update the range to include 170 as part of the same edit if it is still stale.

### 6.5 Help page
`web/_apps/help/venues.php` — build the 02 §11.1 twelve-section outline verbatim (shape: `help/assets.php` l.24-50). No further spec needed here; the outline is the contract.

---

## 7. Follow-up issues to file at ship time (in the PR body, then as real issues)

1. **`tblEvents.*DateTime` "stored in UTC" column comments are wrong** — events are stored wall-clock event-local (proof: `calendar/manage/save.php` binds the raw POST; `calendar/event.php:241` reads it in the event's own zone; `Ical.php` emits TZID, never Z). Reconcile the comments project-wide; do NOT change storage. (02b resolution — doc bug only.)
2. **`tblEvents.venueID` (per-event venue) + room-aware coverage** — v1 uses the `venues.calendar_default_venue` site setting and venue-wide coverage (Q6/Q12); the richer path is a nullable `tblEvents.venueID` guarded ALTER + tightening `classifyEventCoverage` rule (b) to `b.roomID IS NULL OR b.roomID = event.roomID`.

---

## PRE-FLIGHT CHECKLIST (the implementer ticks EVERY box before opening the PR)

- [ ] `git fetch origin` done; migration number re-confirmed free (§1.1); filename + self-record + fold + docs all agree on the number
- [ ] Epic issue filed; `grep -rln '#VEN' web/ FEATURES.md CHANGELOG.md DEV_NOTES.md .claude/CLAUDE.md` returns ZERO files (all substituted)
- [ ] `web/_sql/170_venue_bookings.sql`: 15 `CREATE TABLE IF NOT EXISTS`, **0 `ALTER TABLE`**, settings (12 keys) + role + routes (26) + self-record, in §1's order
- [ ] `tblVenueInvoicePayments` has NO `portalPaymentID`/`idx_venip_portal`/`fk_venip_portal` (§1.3.1); `git diff full_schema.sql` touches nothing in `tblPayment`
- [ ] full_schema fold: 15 tables + seeds in the block before SECTION 7B; `170_venue_bookings.sql` record at EOF; the `--`-in-strings awk gate (§1.9) silent on both files
- [ ] All 26 route target files exist and are REAL handlers (no stubs); the 2 API handlers exist at `web/_apps/venues/api/{check,availability}.php`; nothing `api/*` was added to tblRoutes
- [ ] `php -l` clean on every changed/added PHP file (`git diff --name-only origin/alpha... -- '*.php' | xargs -n1 php -l`)
- [ ] All 10 `tools/audit-checks/check_*.py --strict` green at the PR head
- [ ] `tools/e2e-migrations/run.sh` full pass (G1)
- [ ] DST gate script passes all four assertions (G4); ZipArchive smoke OK (G3)
- [ ] All §4.3 security grep-gates pass; output pasted into the PR description
- [ ] GdprEraser 3 entries + data-export 3 sections in (`web/_apps/auth/account/data-export.php` — the corrected path); G7 prepare-check pass
- [ ] Calendar resilience double-check (G8): app disabled ⇒ calendar byte-identical, event save unblocked
- [ ] en.php parses; the 28 i18n keys present; key list copied into the PR body for cy.php follow-up
- [ ] FEATURES.md / CHANGELOG.md / DEV_NOTES.md / `.claude/CLAUDE.md` deltas applied (§6, incl. the 62-class count fix); `/help/venues` live
- [ ] Manual smoke checklist (§4.6) executed on alpha post-deploy; ticks recorded in the PR
- [ ] The two follow-up issues (§7) filed and linked
- [ ] PR opened as **DRAFT** against `alpha` (auto-merge trap, §5); `pr-security.yml` bot comment monitored until clean; orchestrator sign-off obtained BEFORE marking Ready
