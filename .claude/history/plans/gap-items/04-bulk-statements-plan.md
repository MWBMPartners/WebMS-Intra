# Gap #4 — Bulk Giving Statements (admin batch generate + email)
## Build-ready implementation plan — verified against `alpha` @ 4433b30 (2026-08-28)

All file:line references below were read from the real code on `alpha` (post-PayPal #434,
post-Venues #431). No code in this document — design + exact instructions only.

---

## 0. Scope decision (summary)

Build a **treasurer-only "Annual statements" page at `/giving/statements`**:

1. **Pick a period** — calendar-year selector (default: last full year), plus free
   from/to date inputs and a one-click "UK tax year (6 Apr – 5 Apr)" preset that just
   fills the from/to fields. *Calendar year is the verified house convention — see §1.5.*
2. **Preview list** (site-scoped, per-donor): name, email presence, total given, Gift-Aid-eligible
   total, opt-out status, and already-emailed status for this period. Zero-total donors and
   donor-less (anonymous / free-text-name) entries are excluded, with an informational count.
3. **Generate** — POST creates/refreshes one `tblGivingStatementLog` row per eligible donor and
   renders each donor's PDF through the **same renderer the self-service page uses**
   (extracted/generalised `Giving::renderStatementPdf()` — byte-identical output guaranteed
   by construction). Batched (default 25/request) with a "continue" re-trigger, exactly like
   the Newsletter dispatch pattern.
4. **Download all as ZIP** — GET streams a ZipArchive built from the generated per-donor PDFs.
5. **Email each donor their own PDF** — POST queues all eligible rows then sends up to
   `giving.statements.batchPerRun` (default 25) per invocation via `Mailer::send()` with the
   PDF attached; re-trigger to continue, **or** an optional token-gated
   `cron/giving-statements.php` sweeps the remainder unattended.
6. **Dedupe / sent-log** — `tblGivingStatementLog` UNIQUE `(siteID, donorID, periodKey)`;
   a row with `emailedAt` set is skipped on re-run unless the treasurer ticks an explicit
   "resend already-emailed" override.

**Sync vs cron: hybrid — synchronous capped batches driven from the UI, with an optional cron
sweeper.** Rationale: DreamHost shared FastCGI kills long requests regardless of
`set_time_limit()` (the sole house precedent for long sync work,
`web/_apps/admin/maintenance/offsite-backup-run.php:43` `@set_time_limit(900)`, documents the
risk in its own header comment at :7). dompdf ≈ 0.5–2 s/PDF + Graph sendMail ≈ 0.5–1 s/email
means 100 donors ≈ 2–5 min — never safe in one request. The house already solved exactly this
for Newsletter (`web/_core/Newsletter.php:154-228` caps each dispatch at
`newsletter.batchPerHour`, default 100; `web/_apps/newsletter/send.php:5-6` — "the rest go on
subsequent invocations — caller can re-trigger or a cron can sweep"). Copy that pattern.
A typical church site (≤ ~100 donors) finishes in 1–4 clicks with zero server configuration;
larger sites set `giving.cron_token` once and the cron finishes any queued run.

**Artifact shape:** per-donor PDF (emailed as that donor's attachment); bulk download is a ZIP
of the same per-donor PDFs (never a combined single PDF — it would be a cross-donor PII bundle
with no per-donor delivery value, and a 100-donor combined dompdf render is the exact
memory/time hazard we're avoiding).

---

## 1. Verified current state (evidence)

### 1.1 Self-service statement path (the code to reuse)

- **Entry point** `web/_apps/giving/my-statement.php` (37 lines):
  - :16-17 `Auth::ensureSession(); Auth::requireLogin();` — any logged-in member.
  - :19-24 `$siteId = Site::id();` `$userId = $_SESSION['user_id']`; `$year = (int)($_GET['year'] ?? date('Y'))` clamped to 2000–2100.
  - :26 calls `Giving::renderStatementPdf($siteId, $userId, $year)` → :33-36 streams the file
    with `Content-Disposition: attachment; filename="giving-statement-{year}.pdf"`.
- **Renderer** `web/_core/Giving.php::renderStatementPdf(int $siteId, int $donorId, int $year): string|false` (:170-258):
  - :174 donor lookup `SELECT userID, fullName, emailAddress FROM tblUsers WHERE userID = ?` —
    **not site-scoped** (any userID renders; safe today only because my-statement passes the
    session user — the generalised renderer must add site-membership scoping, §3.2).
  - :185-186 **period = calendar year**: `$from = $year.'-01-01'; $to = $year.'-12-31'` — NOT
    UK tax year.
  - :188-194 entries query: `tblGivingEntry e INNER JOIN tblGivingCategory c` filtered
    `e.siteID = ? AND e.donorID = ? AND e.donatedAt BETWEEN ? AND ?`, ordered by date.
  - :205-208 branding from settings: `giving.charityName`, `giving.charityNumber`,
    `giving.currency` (default GBP).
  - :210-250 inline HTML build — `htmlspecialchars` closure `$esc` (:210), total summed in
    pence (:211-214), `<table>` markup **inside the PDF** (fine — the "no `<table>`" rule is a
    web-UI `portal-data-list` rule; `ExpensePdf` uses tables in PDFs too), amounts via
    `Giving::formatAmount()` (:82-91: pence→`£/€/$ + number_format(/100, 2)`).
  - **NO Gift Aid content anywhere in the current statement** — the gap plan adds it (§3.2).
  - :252-257 output path `PORTAL_ROOT/_uploads/giving/statement-{donorId}-{year}.pdf` →
    `Pdf::create($html, $path)`. `_uploads/` is a sibling of `public_html/` — **outside the
    webroot, not directly servable** (deploy layout, `.claude/CLAUDE.md` Directory Layout), and
    gitignored. ⚠️ Latent flaw: the filename has **no siteID**, so the same donor in two sites
    overwrites one file with the other's data. Fix while generalising (§3.2).
- **PDF engine** `web/_core/Pdf.php::create(string $html, string $filePath, string $watermark = ''): string|false`
  (:76-117): loads vendored dompdf from `_libraries/dompdf/autoload.inc.php` (:47-48, logs +
  returns false if absent), `isRemoteEnabled=false` (:98, SSRF guard), A4 portrait (:101),
  writes file, returns path. One fresh `\Dompdf\Dompdf` per call — safe to call in a loop; the
  object goes out of scope each iteration.
- **Sole caller** of `renderStatementPdf` is my-statement.php (verified by grep) → the
  signature may be changed freely with one call-site update.
- Self-service page link: `web/_apps/giving/index.php:77` ("Year-end statement" button).

### 1.2 Giving data model

`web/_sql/full_schema.sql` (canonical; originally migration 094 + 151):

- **`tblGivingEntry`** (:3863-3892): `entryID` PK, `siteID` (FK tblSites, :3886),
  `donorID INT NULL` (FK tblUsers ON DELETE SET NULL, :3887), `donorName VARCHAR(255) NULL`
  (free-text non-member attribution), `categoryID` (FK, NOT NULL), `amountPence INT`
  (**minor-units pence convention**, Giving.php:9-11), `currency CHAR(3) DEFAULT 'GBP'`
  (per-entry), `donatedAt DATE`, `method ENUM('cash','cheque','bank-transfer','card','standing-order','other')`,
  `reference`, `notes`, `recordedByID`, `campaignID`/`pledgeID` (nullable, #299), timestamps.
  Indexes include `idx_ge_site_date (siteID, donatedAt)` :3881 and
  `idx_ge_donor_date (donorID, donatedAt)` :3882 — the bulk aggregate uses both.
  **Anonymous/cash**: `donorID NULL` + optionally `donorName` free text
  (`web/_apps/giving/entry-save.php:58-78` resolves typed names to members via a
  site-scoped `INNER JOIN tblUserSites` and stores free text otherwise; manage.php:204 "Leave
  the donor blank for anonymous cash"). → Statements are only meaningful for
  `donorID IS NOT NULL` rows.
- **`tblGivingCategory`** (:3803-3815): `categoryID`, `siteID`, `name`, `description`,
  `isActive`, `defaultFund`, `sortOrder`.
- **`tblGiftAidDeclaration`** (:3894-3912): `declarationID`, `siteID`, `donorID` (FK CASCADE),
  `status ENUM('active','lapsed','withdrawn')`, `validFrom DATE`, `validTo DATE NULL`,
  `address`, `postcode`, acceptance metadata.
- **Gift Aid eligibility rule** (the one already shipped — reuse verbatim):
  `Giving::buildHmrcCsv()` (Giving.php:104-163) joins declarations
  `d.siteID = e.siteID AND d.status='active' AND d.validFrom <= e.donatedAt AND (d.validTo IS NULL OR d.validTo >= e.donatedAt)`
  (:114-118); `Giving::hasActiveDeclaration()` (:263-279) is the single-donor form.
  ⚠️ buildHmrcCsv's INNER JOIN would double-count an entry if two overlapping active
  declarations existed — for the per-donor Gift Aid **sum**, use `EXISTS` instead of a join
  (§3.1) so the totals can never double-count.
- **Site scoping**: every giving query above filters `e.siteID = ?`; donor pickers scope
  membership via `tblUserSites` (`uq_user_site (userID, siteID)`, `isActive` —
  full_schema.sql tblUserSites block). Statements must do both (§7).
- **Users**: `tblUsers.emailAddress` (full_schema.sql:177, UNIQUE :205) — the column is
  `emailAddress`, **never `u.email`**. `tblUsers.notifyPrefs JSON` holds notification opt-outs.

### 1.3 Admin surface + auth

- **Gating**: `Giving::canManage()` (Giving.php:30-33) = `App::isAdmin() || App::hasRole('treasurer')`
  (`ROLE_KEY = 'treasurer'`, :24; `App::hasRole` checks `tblUserRoles`/`tblRoles.roleKey`,
  App.php:335-366, root admin implicit). Every treasurer page uses the identical prologue —
  e.g. `manage.php:18-23`, `reports.php:18-23`, `hmrc-export.php:18-23`:
  `Auth::ensureSession(); Auth::requireLogin(); if (Giving::canManage() === false) { Router::renderError(403); return; }`.
- **POST handlers** add (`entry-save.php:25-28`):
  `if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }`
  (`Auth::csrfToken()`/`verifyCsrf()` exist at Auth.php:268/:289).
- **Where to hang the UI**: the treasurer toolbar in `manage.php:114-124` (Campaigns / Count /
  Reconcile / Categories / Reports / HMRC CSV buttons) — add a "Statements" button there.
- **Page template pattern** (`manage.php:99-108`): flash from `$_SESSION['flash_msg']/_type`,
  `$pageTitle/$pageSection/$breadcrumbs`, `require PORTAL_CORE...templates/header.php`, footer
  at end. Data display via `portal-data-list` divs (manage.php:244-273), never `<table>`.
- **Routes**: giving routes seeded in tblRoutes (full_schema.sql:3914-3927), all
  `isProtected=1`, `targetFile` relative to `PORTAL_APPS`.

### 1.4 Delivery (email)

- **`Mailer::send(string|array $to, string $subj, string $body, array $files = []): bool`**
  (`web/_core/Mailer.php:62-97`) — `files` = array of file paths; attachments confirmed:
  Graph `fileAttachment` build at :323-338, base64-inlined, **files > 4 MB silently skipped**
  (:327 `filesize($p) > 4*1024*1024` → `continue`) — a statement PDF is tens of KB, but the
  implementer must still check `filesize()` and fail the row rather than send a bodiless
  email (§3.5). Google provider path `MailerGoogle::send(...)` at :91-93 takes the same files
  array. HTML autodetected; plain body auto-wrapped in the branded base template (:75-78).
- **`Mailer::sendTemplated($to, $subject, $template, $vars, $files)`** (:113-130) renders
  `web/_core/templates/email/{name}.html.php` (existing: base, critical-alert, invite,
  password-reset) — use a new `giving-statement.html.php`.
- **Precedent for emailing a PDF**: `web/_core/ExpenseMailer.php:181-193` — collect
  recipients, `filter_var(..., FILTER_VALIDATE_EMAIL)` (:173-175), `$attachments[] = $pdfPath`,
  `Mailer::send(...)` inside try/catch with `Logger::errorPlatform` on failure ("don't let
  email failure break the workflow", :190-193).
- **Batching precedent** (THE model to copy): `Newsletter::dispatch()`
  (`web/_core/Newsletter.php:154-228`) — recipient rows pre-locked into a table, each pass
  `SELECT ... WHERE deliveredAt IS NULL AND errorMsg IS NULL LIMIT ?` with
  `newsletter.batchPerHour` cap (:159, default 100), per-row `deliveredAt`/`errorMsg` update,
  driver `web/_apps/newsletter/send.php` re-triggerable POST that reports
  "N remaining (rate-limited — re-trigger to continue)" (:85-90). **Everything is synchronous
  per-batch; nothing queues in the background.**
- **Cron pattern** (for the optional sweeper): 6 files in `web/_apps/cron/`; canonical gate
  (`cron/venue-reminders.php:78-84`, `asset-reminders.php:81-90`):
  `?key=` vs `Settings::get('{app}.cron_token','')` via `hash_equals`, **empty stored token
  always 403s** (endpoint inert until an admin sets it); token seeded empty + `isSensitive=1`
  (170_venue_bookings.sql settings block). For giving the key is **`giving.cron_token`**.
  ⚠️ `cron/event-reminders.php:50` selects `u.email` — that is a pre-existing bug in that
  file, NOT the convention; do not copy it.

### 1.5 Date-range / tax-year logic (verified)

Calendar year everywhere today: renderer Giving.php:185-186 (`Y-01-01`…`Y-12-31`);
HMRC export defaults `hmrc-export.php:26-27` (`date('Y-01-01')`/`date('Y-12-31')`);
"My Giving" YTD total `index.php:48-53`. **There is no 6 Apr–5 Apr logic anywhere in the
codebase.** The bulk page therefore defaults to calendar year and offers a UK-tax-year
*preset* that simply populates the custom from/to fields (donor statements are informational;
the HMRC claim itself goes through the existing CSV export, which already takes any range).

### 1.6 PDF at scale + bulk precedents

- No existing feature renders N PDFs in one request. Assets QR labels render a single
  printable HTML page (browser prints it); venue schedule and expenses render one PDF each.
- `ZipArchive` is present and already relied on (`web/_apps/venues/import.php:125`
  `class_exists('ZipArchive')` guard; `Venues.php:1909-1913` uses it for XLSX) — guard the ZIP
  download the same way and degrade to per-donor links if absent.
- `@set_time_limit(900)` precedent: `admin/maintenance/offsite-backup-run.php:43` (with the
  header warning at :7 that FastCGI may still kill it) → batching, not time-limit raising, is
  the correct mechanism; a defensive `@set_time_limit(300)` inside batch handlers is fine.
- Dedupe-log precedent: `tblVenueReminderLog` (170_venue_bookings.sql:545-559) — UNIQUE
  natural key `(refType, refID, dueDate)`, insert-once semantics, helper pair
  `Venues::reminderAlreadySent()` / `logReminder()` that swallows the UNIQUE race.

### 1.7 Existing partial work (delta check)

- `renderStatementPdf` has exactly one caller (my-statement.php:26). No admin statement page,
  handler, or route exists (`grep statement` across `web/_apps/giving/`, `web/_sql/`).
- **No `giving.statement*` settings exist** (repo-wide grep: zero hits). Seeded giving keys are
  only enabled/displayName/displayIcon/currency/charityName/charityNumber/hmrcRef
  (full_schema.sql:3929-3937).
- No `giving.cron_token`. No statements-related notifyPrefs key (whitelist at
  `auth/account/notifications-save.php:37-46`).
- Conclusion: the whole admin bulk side is greenfield; the only refactor is generalising the
  renderer.

---

## 2. Architecture overview

```
/giving/statements  (GET, treasurer)            /account/notifications
  period picker + preview list                    + 'givingStatements' switch (default ON)
        │
        ├─ POST /giving/statements-generate  ──► upsert tblGivingStatementLog rows
        │      (batch ≤ cap per request)          + Giving::renderStatementPdf() per donor
        │                                         (shared renderer — same bytes as self-service)
        ├─ GET  /giving/statements-download  ──► ZipArchive of this period's PDFs (stream)
        │
        └─ POST /giving/statements-email     ──► queue rows (queuedAt=NOW) then send batch:
               (batch ≤ cap per request)          Mailer::send(donor, subj, tpl, [pdf])
                                                  emailedAt=NOW per success / errorMsg per fail
cron/giving-statements.php?key=…             ──► sweeps rows queuedAt IS NOT NULL
  (optional, giving.cron_token)                   AND emailedAt IS NULL AND errorMsg IS NULL
```

State lives entirely in `tblGivingStatementLog` — no separate "run" table. The batch loops are
stateless between requests (Newsletter model): each invocation picks up "next N unfinished
rows for this site+period".

---

## 3. Detailed design

### 3.1 Preview aggregate query (per-donor totals, Gift Aid, site-scoped)

One GROUP BY over entries, site-scoped, members only, with the Gift Aid sum computed by
correlated `EXISTS` (never a declaration join — overlap-proof, see §1.2):

```
SELECT e.donorID, u.fullName, u.emailAddress, u.notifyPrefs,
       COUNT(*)                    AS entryCount,
       SUM(e.amountPence)          AS totalPence,
       SUM(CASE WHEN EXISTS (
             SELECT 1 FROM tblGiftAidDeclaration d
             WHERE d.siteID = e.siteID AND d.donorID = e.donorID
               AND d.status = 'active'
               AND d.validFrom <= e.donatedAt
               AND (d.validTo IS NULL OR d.validTo >= e.donatedAt)
           ) THEN e.amountPence ELSE 0 END) AS giftAidPence
FROM tblGivingEntry e
INNER JOIN tblUsers u      ON u.userID = e.donorID
INNER JOIN tblUserSites us ON us.userID = e.donorID AND us.siteID = e.siteID AND us.isActive = 1
WHERE e.siteID = ? AND e.donorID IS NOT NULL AND e.donatedAt BETWEEN ? AND ?
GROUP BY e.donorID, u.fullName, u.emailAddress, u.notifyPrefs
HAVING SUM(e.amountPence) > 0
ORDER BY u.fullName
```

(bind `'iss'`: siteId, from, to — mind `check_bind_param_arity.py`). Notes:

- `INNER JOIN tblUserSites` mirrors the treasurer donor-picker hardening
  (manage.php:136-143) — a donor row for a user no longer active on this site is excluded
  from the *email* run; decide display for departed donors per Open Question Q3.
- `HAVING > 0` is the zero-total threshold (also guards negative/correction entries netting ≤ 0).
- A companion scalar query counts excluded donor-less gifts for the transparency line:
  `SELECT COUNT(*), COALESCE(SUM(amountPence),0) FROM tblGivingEntry WHERE siteID = ? AND donorID IS NULL AND donatedAt BETWEEN ? AND ?`.
- Per-row PHP decorates: opt-out (`notifyPrefs` JSON key `givingStatements` absent-or-true =
  opted in), email validity (`filter_var`), and log status (LEFT JOIN or keyed lookup against
  `tblGivingStatementLog` for this `(siteID, periodKey)` → generated? emailed-at?).
- `LIMIT 2000` guard on the preview to bound memory (same spirit as manage.php:57's LIMIT 500).

### 3.2 Shared renderer (DRY — one renderer, two callers)

Generalise **in place** in `web/_core/Giving.php` (single existing caller makes this cheap):

- New canonical signature:
  `renderStatementPdf(int $siteId, int $donorId, string $fromDate, string $toDate, string $periodLabel): string|false`
  - `$periodLabel` is what the PDF heading shows (`"2025"` for a calendar year;
    `"6 Apr 2025 – 5 Apr 2026"` for a range).
  - Keep every existing behaviour byte-for-byte (queries :188-203, HTML :216-250, totals),
    with exactly these deliberate changes (they apply to self-service AND bulk equally, so
    outputs remain identical between the two paths):
    1. **Donor lookup becomes site-scoped**: add
       `INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1`
       to the :174 query — closes the "render any userID for any site" latitude before a bulk
       caller exists (see Q3 for the departed-donor nuance).
    2. **Gift Aid on the statement** (the gap explicitly asks for Gift Aid totals): per-entry
       eligibility via the §1.2 rule (compute in the entries query with the same
       `EXISTS ... CASE` so it can't double-count) → a ✓/– "Gift Aid" column, and a summary
       block after the total: "Total given: £X · of which Gift Aid eligible: £Y" plus, when a
       declaration exists, the standard declaration-covered wording. **No projected 25%
       reclaim figure** (Q2 default — the reclaim belongs to the charity, not the donor).
    3. **Output path gains site + period**:
       `_uploads/giving/statements/{siteID}/statement-{donorID}-{periodKey}.pdf`
       (`{periodKey}` from §3.3; fixes the cross-site overwrite noted in §1.1). `mkdir` chain
       as :253-255 with `DIRECTORY_SEPARATOR` throughout.
- Add `Giving::statementPeriodKey(string $from, string $to): string` → `"{from}_{to}"`
  (e.g. `2025-01-01_2025-12-31`) — one canonical key used by filenames, the log table, and
  dedupe. Dates validated (`strtotime` !== false, from ≤ to) before it's ever built.
- Update `my-statement.php` (only caller): map its `?year=` into
  `renderStatementPdf($siteId, $userId, $year.'-01-01', $year.'-12-31', (string)$year)`.
  Everything else in that file stays as-is — a member's self-generated statement and the
  treasurer's bulk copy are the same bytes because they are the same function call.

### 3.3 Sent-log / dedupe table

```sql
CREATE TABLE IF NOT EXISTS `tblGivingStatementLog` (
    `logID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `donorID`      INT          NOT NULL,
    `periodKey`    VARCHAR(30)  NOT NULL COMMENT 'fromDate_toDate — Giving::statementPeriodKey()',
    `fromDate`     DATE         NOT NULL,
    `toDate`       DATE         NOT NULL,
    `totalPence`   INT          NOT NULL DEFAULT 0,
    `giftAidPence` INT          NOT NULL DEFAULT 0,
    `pdfPath`      VARCHAR(500) DEFAULT NULL COMMENT 'NULL until generated',
    `queuedAt`     DATETIME     DEFAULT NULL COMMENT 'Set when treasurer starts an email run; cron sweeps queued rows',
    `emailedAt`    DATETIME     DEFAULT NULL COMMENT 'Dedupe: re-runs skip rows with this set',
    `emailedTo`    VARCHAR(255) DEFAULT NULL COMMENT 'Address actually mailed (audit)',
    `errorMsg`     VARCHAR(255) DEFAULT NULL,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`logID`),
    UNIQUE KEY `uq_gsl_site_donor_period` (`siteID`, `donorID`, `periodKey`),
    KEY `idx_gsl_site_period` (`siteID`, `periodKey`),
    KEY `idx_gsl_queue` (`queuedAt`, `emailedAt`),
    CONSTRAINT `fk_gsl_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_gsl_donor`   FOREIGN KEY (`donorID`)     REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_gsl_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Giving — bulk year-end statement generate/email log + dedupe (gap #4)';
```

Semantics:
- **One row per (site, donor, period)** — the row IS the run state. Generate = upsert
  (`INSERT ... ON DUPLICATE KEY UPDATE totalPence/giftAidPence/pdfPath/updatedAt`) +
  PDF render. Email = set `emailedAt`/`emailedTo` on success, `errorMsg` on failure
  (mirrors tblNewsletterRecipient's deliveredAt/errorMsg pair, Newsletter.php:176-217).
- **Re-run safety**: the email selector is
  `WHERE siteID=? AND periodKey=? AND queuedAt IS NOT NULL AND emailedAt IS NULL AND errorMsg IS NULL LIMIT ?`
  → a completed row can never be picked again. "Resend already-emailed" override (explicit
  checkbox, Q4) clears `emailedAt`/`errorMsg` first and writes a `Logger::activity` entry.
- Failed rows (`errorMsg` set) are shown in the preview with a per-row "retry" that nulls
  `errorMsg`.
- FK `ON DELETE CASCADE` on donorID: GDPR erasure of the user sweeps their log rows
  automatically (see §7 for the PDF files themselves).
- `check_php_table_refs.py` passes because the table ships in the same PR as the PHP that
  names it.

### 3.4 Generate flow (`statements-generate.php`, POST)

1. Standard prologue (§1.3) + CSRF + method check; validate `from`/`to` (`strtotime`,
   from ≤ to, range ≤ 5 years as sanity), compute `periodKey`.
2. Run the §3.1 aggregate; upsert one log row per donor (totals refreshed even if the row
   exists — corrections flow through on regenerate).
3. Render PDFs for up to `giving.statements.batchPerRun` rows
   `WHERE ... pdfPath IS NULL` ordered by donorID (deterministic), calling the shared
   renderer; `@set_time_limit(300)` defensively.
   A "Regenerate PDFs" checkbox nulls all `pdfPath` for the period first (post-correction
   refresh) — it does NOT touch `emailedAt` (dedupe survives regeneration).
4. Flash `Generated X of Y — re-run to continue` when rows remain (newsletter wording,
   send.php:85-90); redirect back to `/giving/statements?from=&to=`.
5. `Logger::activity('GivingStatementsGenerate', ...)` (Logger.php:48 signature:
   `(string $type, string $description = '', ?int $userId = null)`), matching the
   `GivingHmrcExport` precedent (hmrc-export.php:35).

### 3.5 Email flow (`statements-email.php`, POST)

1. Prologue + CSRF; inputs: `from`, `to`, optional `resend=1` (Q4).
2. First invocation for a period (no queued rows yet): stamp `queuedAt = NOW()` on every
   eligible row — eligible means: log row exists, `pdfPath` set, donor **not opted out**
   (re-check `notifyPrefs.givingStatements` at send time, not just preview time), donor has a
   `FILTER_VALIDATE_EMAIL`-valid `emailAddress`, and `emailedAt IS NULL` (unless resend).
3. Process up to `giving.statements.batchPerRun` queued rows: for each —
   re-verify `is_file($pdfPath)` and `filesize() <= 4MB` (Mailer::attach silently drops
   oversize files, Mailer.php:327 — treat as row failure, never send without the attachment);
   `Mailer::sendTemplated($donorEmail, $subject, 'giving-statement', $vars, [$pdfPath])`
   inside try/catch (ExpenseMailer precedent :188-193); success → `emailedAt = NOW(),
   emailedTo = $donorEmail`; failure → `errorMsg`.
   Subject from `giving.statements.emailSubject` with `{year}`/`{period}`/`{charity}`
   placeholder substitution; template vars: donor first name, period label, charity
   name/number, portal URL. Recipient is **always and only** `u.emailAddress` of the row's
   own donorID — never a POSTed address.
4. Flash `Sent X, failed Y, Z remaining — re-run, or configure the giving cron to finish`
   → redirect back. `Logger::activity('GivingStatementsEmail', ...)`.
5. Throttle: the per-request cap IS the throttle (25 Graph calls ≈ 15–30 s, inside FastCGI
   limits and far below Graph/Gmail rate limits). No sleep() needed.

### 3.6 ZIP download (`statements-download.php`, GET)

Prologue-gated (treasurer). `class_exists('ZipArchive')` guard (venues precedent,
import.php:125) → friendly flash if unavailable. Select this period's rows with `pdfPath`
set; add each **existing** file as
`{donorID}-{sanitised fullName}-{periodKey}.pdf` (donorID prefix guarantees uniqueness;
sanitise to `[A-Za-z0-9 _-]`); write the archive to the scratch area
`_uploads/giving/statements/{siteID}/bundle-{periodKey}.zip`, stream with
Content-Type `application/zip` + attachment disposition + Content-Length, then `unlink()` the
zip (the per-donor PDFs stay). ~100 × ≤100 KB files is trivial for ZipArchive. If any rows
lack a PDF, flash "N statements not yet generated" instead of a partial silent bundle
(include `?partial=1` to override).

### 3.7 Cron sweeper (`cron/giving-statements.php`) — optional config

Clone `cron/venue-reminders.php`'s shape: token gate `?key=` vs `giving.cron_token`
(`hash_equals`, empty-token 403 — inert until configured), `Content-Type: text/plain`,
process up to `giving.statements.batchPerRun × 4` rows across ALL sites
`WHERE queuedAt IS NOT NULL AND emailedAt IS NULL AND errorMsg IS NULL ORDER BY queuedAt`
(the queue selector is inherently site+period-scoped row-by-row — each row carries its own
siteID/donorID/pdfPath, so no `Site::forceContext` needed; per-row settings reads are global
`giving.*` keys). Same send routine as §3.5 step 3 — factor it into
`Giving::sendStatementEmail(array $logRow): bool` so handler and cron share one code path.
Print per-site sent/failed counts. Suggested schedule: every 15 min (DreamHost panel cron
hitting the URL), same as event-reminders.

### 3.8 Opt-out (`givingStatements` notifyPrefs key)

- Add `'givingStatements'` to the whitelist at `auth/account/notifications-save.php:37-46`.
- Add a `$switchRow('givingStatements', 'Year-end giving statements', 'An annual statement of your recorded giving, emailed as a PDF.')`
  in the Giving/finance grouping of `auth/account/notifications.php` (switch-row helper at
  :98-110; defaults map near :76 gets `'givingStatements' => true`) and the default map in
  `auth/account/index.php:143-147`.
- Default **true** (absent key = opted in — consistent with every existing key's default).
- Enforced at queue time AND send time (§3.5); preview shows "opted out" badge and excludes
  from the queued count.

---

## 4. Migration — number reservation + contents

**Reserved number: `172_giving_bulk_statements.sql` — re-confirm at build time.**

Evidence for the reservation (scanned 2026-08-28):
- Local/`origin/alpha` `web/_sql/`: …165, 166, 167 (paypal), **168–169 absent**, 170 (venues).
- `origin/main` + `origin/beta` top out at 158; `origin/claude/venue-b…f` all jump
  166 → 170 (i.e. 167/168/169 were held for parallel in-flight work when venues shipped —
  167 has since landed as PayPal; 168/169 are presumed still held by unlanded sessions).
- The in-flight reminders build is stated to hold **171**.
- Numbering is monotonic with gaps allowed (`Migrator::allFiles()` sorts by filename,
  Migrator.php:115-143; the 168/169 gap already exists on alpha harmlessly).
→ Next unclaimed = **172**. **At build time re-run**: `git fetch --all`, then for every
branch from `git ls-remote --heads origin`, `git ls-tree --name-only <branch> web/_sql/`;
take max(NNN)+1. If 171 turns out unclaimed, do NOT take it — collision with the reminders
session costs more than a gap.

Contents (single file, MySQL-8-safe, idempotent, in this order):
1. Header comment block (167_paypal_checkout.sql:1-53 is the template: purpose, issue link,
   idempotency statement, DDL dialect note).
2. `CREATE TABLE IF NOT EXISTS tblGivingStatementLog` (§3.3) — plain `CREATE TABLE IF NOT
   EXISTS` is standard MySQL, no information_schema guard needed (guards are only for
   ALTER/CREATE INDEX — none here; `check_mariadb_only_ddl.py` + `check_migration_idempotency.py`
   both green by construction).
3. Settings seeds (`INSERT INTO tblSettings ... ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)`):
   - `giving.statements.batchPerRun` = '25' (isSensitive 0)
   - `giving.statements.emailSubject` = 'Your {year} giving statement' (0)
   - `giving.cron_token` = '' (**isSensitive 1** — venues.cron_token precedent; empty = cron inert)
4. Route seeds (`ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)`, all `isProtected` 1):
   `giving/statements` → `giving/statements.php`; `giving/statements-generate` →
   `giving/statements-generate.php`; `giving/statements-email` → `giving/statements-email.php`;
   `giving/statements-download` → `giving/statements-download.php`.
   (Plain tblRoutes rows — **no `api/*` anything**; ApiRouter trap not applicable.)
5. Self-record: `INSERT INTO tblMigrations ('172_giving_bulk_statements.sql') ON DUPLICATE KEY UPDATE filename = filename;`
   (167:…tail lines are the exact idiom).

**full_schema.sql fold** (`check_schema_seed_parity.py` enforces all three): the CREATE TABLE
into the Giving section (after tblGiftAidDeclaration, ~:3912); the 3 settings rows appended to
the giving settings block (~:3929-3937 area) or as a titled block; the 4 route rows; and the
self-record line appended to the tblMigrations block at the file tail (after
`170_venue_bookings.sql`, :7584).

---

## 5. File list (exact)

**New (7):**
| File | Purpose (one line) |
|---|---|
| `web/_sql/172_giving_bulk_statements.sql` | Log table + 3 settings + 4 routes + self-record (§4) |
| `web/_apps/giving/statements.php` | Treasurer page: period picker, preview `portal-data-list`, action buttons, progress badges |
| `web/_apps/giving/statements-generate.php` | POST: validate period, upsert log rows, render batch of PDFs via shared renderer |
| `web/_apps/giving/statements-email.php` | POST: queue eligible rows, send batch with PDF attached, per-row emailedAt/errorMsg |
| `web/_apps/giving/statements-download.php` | GET: ZipArchive of the period's generated PDFs, streamed then unlinked |
| `web/_apps/cron/giving-statements.php` | Token-gated sweeper for queued-but-unsent rows (`giving.cron_token`, inert when empty) |
| `web/_core/templates/email/giving-statement.html.php` | Branded email body (donor name, period, charity identity, "your statement is attached") |

**Changed (7 + docs):**
| File | Change |
|---|---|
| `web/_core/Giving.php` | Generalise `renderStatementPdf()` to (siteId, donorId, from, to, label) + site-scoped donor lookup + Gift Aid column/summary + site/period-keyed path; add `statementPeriodKey()`, `sendStatementEmail()`, preview-aggregate + log-upsert helpers |
| `web/_apps/giving/my-statement.php` | Adapt the single call site to the new signature (year → from/to/label) |
| `web/_apps/giving/manage.php` | Add "Statements" button to the treasurer toolbar (:116-123) |
| `web/_apps/auth/account/notifications-save.php` | Add `givingStatements` to the `$allowedKeys` whitelist (:37-46) |
| `web/_apps/auth/account/notifications.php` | Default `givingStatements => true` + new `$switchRow` |
| `web/_apps/auth/account/index.php` | Add key to the defaults map (:143-147) |
| `web/_sql/full_schema.sql` | Fold: table + settings + routes + tblMigrations line (§4) |
| Docs | `CHANGELOG.md`, `FEATURES.md` (Giving row: "+ admin bulk statements"), `DEV_NOTES.md` (cron URL + batch sizing note), `.claude/` memory |

No `web/public_html/` changes (handlers live in `_apps/`, Router resolves via tblRoutes).
No AppRegistry change (feature lives inside the existing `giving` app,
`web/_core/apps/giving.php` description may gain two words — optional).

---

## 6. House-convention checklist (implementer MUST honour)

- `declare(strict_types=1)` first statement in every new PHP file; full file-header comment
  (path, description, @package Portal\Giving / Portal\Core, author/copyright All Rights
  Reserved, version, @link issue URL).
- Full IF notation everywhere: `if ($x === true)` / `=== false`.
- Prologue order for pages/handlers exactly as manage.php:18-23 (+ POST/CSRF gate as
  entry-save.php:25-28 for the two POST handlers). CSRF hidden input named `csrf_token`.
- MySQLi prepared statements only; **count bind_param type chars vs args**
  (`check_bind_param_arity.py` gates literal type-strings; a mismatch is a fatal
  uncatchable ValueError — see the check's docstring). Nullable ints bind as `'i'` with a
  null variable (entry-save.php:92-96 precedent).
- `u.emailAddress` — never `u.email` (tblUsers, full_schema.sql:177; the `u.email` in
  cron/event-reminders.php:50 is a pre-existing bug, not a pattern).
- Output escaping `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on every echo of data.
- Web UI lists = `portal-data-list` divs (manage.php:244-273) — no `<table>` in pages;
  tables inside the PDF HTML are fine (existing renderer + ExpensePdf precedent).
- Money: integer pence in DB; display only via `Giving::formatAmount()`; per-entry
  `currency` respected with `giving.currency` fallback (renderer :243 pattern).
- Paths: `PORTAL_ROOT`/`PORTAL_CORE` + `DIRECTORY_SEPARATOR`, never hard-coded slashes.
- PDFs via `Pdf::create()` only (never instantiate Dompdf directly); check its
  `string|false` return per call and record row-level failure.
- Mailer: paths array as 4th arg; wrap sends in try/catch + `Logger::errorPlatform`
  (ExpenseMailer:188-193); validate addresses with `filter_var`.
- Flash pattern `$_SESSION['flash_msg']/'flash_type'` + redirect (entry-save.php:103-106).
- `data-confirm` attribute for destructive/irreversible buttons (never native `confirm()` —
  `check_no_native_confirm.py`); use it on "Email all" and "Resend" submits.
- Emoji-annotated section comments; inline comments with reference links.
- Migration: idempotent (ON DUPLICATE KEY UPDATE on every INSERT incl. self-record),
  MySQL-8-safe DDL, replays as no-op, full_schema fold in the same commit.
- New settings keys must be seeded in the migration AND full_schema before any PHP reads
  them (`check_settings_keys.py` + `check_schema_seed_parity.py`); guarded reads
  (`?? default`) regardless.
- Routes must point at files that exist in the same commit (`check_route_targets.py`).
- **No `api/*` endpoints in this feature** — nothing touches ApiRouter, no
  `api.giving.*.enabled` flags needed. Do not register any `api/...` key in tblRoutes.

## 7. Security checklist

1. **Treasurer-only**: all four handlers + page use `Giving::canManage()` 403 prologue;
   cron uses the empty-token-403 gate. The self-service route is untouched.
2. **CSRF**: generate/email POSTs verify `Auth::verifyCsrf`; download is GET but
   side-effect-free (reads existing files only) and treasurer-gated.
3. **Site scoping — every query**: aggregate (§3.1) filters `e.siteID` AND joins
   `tblUserSites`; log selects always `siteID = Site::id()` AND `periodKey = ?`; renderer's
   donor lookup becomes site-scoped (§3.2); ZIP only bundles rows matching
   `(Site::id(), periodKey)`. No handler ever accepts a siteID from the request.
4. **No cross-tenant donor leakage**: preview lists only tblUserSites-joined donors of the
   active site; PDF paths are namespaced by siteID; the treasurer never supplies donorIDs to
   generate/email — the server derives the donor set from the period query, and per-row
   retry/resend donorID inputs are re-validated against `(siteID, donorID, periodKey)` log
   rows.
5. **Statement contains only the donor's own data**: guaranteed structurally — the shared
   renderer's entries query is `donorID = ?` + `siteID = ?` (Giving.php:192).
6. **Email goes only to the correct donor**: recipient is read from tblUsers by the log
   row's donorID at send time; never from POST; `emailedTo` recorded for audit.
7. **PII at rest**: PDFs live under `_uploads/giving/statements/{siteID}/` — outside the
   webroot (deploy layout §1.1), gitignored, excluded from deploy sync. No auto-purge
   (HMRC 6-year retention rationale already encoded at GdprEraser.php:58). GDPR erasure:
   log rows CASCADE with the user; add an unlink of
   `_uploads/giving/statements/*/statement-{userID}-*.pdf` alongside (see Q5).
8. **Gift Aid correctness**: eligibility only via the shipped declaration-window rule
   (§1.2), summed with EXISTS (no join double-count); statements label the figure
   "Gift Aid eligible" — no reclaim projection; HMRC claims remain the CSV's job.
9. **Rate/throttle**: hard per-request cap (`batchPerRun`, default 25) bounds both runtime
   and provider call rate; failures never auto-retry (manual retry only) so a
   misconfiguration can't loop-spam donors.
10. **Attachment integrity**: send fails the row (never sends) when the PDF is missing or
    > 4 MB (Mailer silently drops oversize attachments — Mailer.php:327).
11. **Injection/traversal**: period inputs strtotime-validated then rebuilt as `Y-m-d`;
    periodKey built only from validated dates; ZIP entry names sanitised; all SQL bound.

## 8. Acceptance gates

1. `php -l` clean on every touched/new PHP file (zero warnings).
2. All **11** audit checks green (`tools/audit-checks/`: bind_param_arity, cdn_sri,
   mariadb_only_ddl, migration_idempotency, mobile_readiness, no_native_confirm,
   php_table_refs, route_targets, schema_seed_parity, settings_keys, sql_columns).
3. Migration harness: fresh install runs full_schema + replays 172 as a no-op; 172 applied
   twice on an up-to-date schema errors nowhere.
4. **Functional test (2-donor site)**: seed 2 donors on site A (one with an active Gift Aid
   declaration covering some entries, one without; plus one anonymous cash entry and one
   zero-total donor) → generate for the year → exactly 2 log rows + 2 PDFs; PDF totals match
   pence sums; Gift-Aid-eligible figure only counts declaration-covered entries of the
   declared donor; anonymous total appears only in the excluded-count line. Email run sends
   2; **immediate re-run sends 0** ("0 remaining"); resend-override sends 2 again and logs
   the override. ZIP contains exactly 2 correctly-named PDFs.
5. **Byte-parity test**: `/giving/my-statement?year=YYYY` output for donor 1 is identical to
   the bulk-generated `statement-{donor1}-{periodKey}.pdf` for the same period.
6. **Access tests**: non-treasurer member → 403 on all four routes; treasurer of site B sees
   an empty preview for site A's donors (no leakage); cron URL without/with-wrong key → 403;
   empty `giving.cron_token` → 403 even with `?key=`.
7. Opt-out test: donor flips the `givingStatements` switch off → preview shows opted-out
   badge; email run skips them; PDF still generable + in ZIP (opt-out governs email only).
8. Docs updated (CHANGELOG, FEATURES, DEV_NOTES) and migration number re-confirmed against
   all remote branches at build time (§4).

## 9. Open questions (each with a recommended default — proceed on the defaults)

- **Q1 — Period basis.** Calendar-year default with free from/to + UK-tax-year preset button?
  **Recommended: yes** — matches every shipped date default (§1.5); the preset is one line of JS.
- **Q2 — Show projected 25% Gift Aid reclaim on the statement?** **Recommended: no** — show
  "of which Gift Aid eligible" only; the reclaim is the charity's figure and belongs in the
  HMRC CSV workflow, and printing it invites donor tax-confusion.
- **Q3 — Donors who left the site (no active tblUserSites row) but gave during the period.**
  **Recommended: exclude from bulk email, include in preview greyed-out with a "download PDF"
  per-row link** — treasurer can hand-deliver; automated email to departed members is a
  GDPR-contact judgement the software shouldn't make. (Implementation: LEFT JOIN
  tblUserSites in the aggregate instead of INNER, flag `usActive`, gate queueing on it;
  renderer's site-scope check then keys off giving history rather than membership for the
  treasurer-initiated single download.)
- **Q4 — Resend override.** **Recommended: yes** — explicit "Resend to already-emailed donors"
  checkbox on the email form, `data-confirm` guarded, `Logger::activity` audit line.
- **Q5 — Statement PDFs of GDPR-erased users.** **Recommended: extend `GdprEraser`** with an
  unlink sweep of `_uploads/giving/statements/*/statement-{userID}-*.pdf` (entries stay
  anonymised per the 6-year note at GdprEraser.php:58, but a rendered statement is a
  name-bearing document with no retention duty once the subject is erased). Small,
  self-contained addition; if the team prefers to defer, file a follow-up issue in the PR.
- **Q6 — Non-member (free-text `donorName`) gifts.** **Recommended: out of scope** — no
  portal identity or email exists; they surface only in the excluded-count line. A future
  "external donors" register would be its own feature.
