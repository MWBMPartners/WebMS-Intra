# Adversarial Security Review — Bulk Giving Statements (gap #4 / #440)

Branch: `claude/gap4-bulk-statements` @ 7898b1b
Scope: treasurer-only `/giving/statements` bulk PDF generate + ZIP + per-donor email, the generalised `Giving::renderStatementPdf()`, the log/dedupe table (migration 172), the cron sweeper, the GdprEraser hook, and the notifyPrefs opt-out.
Method: full-path tracing against real code; every finding cited file:line and rated CONFIRMED (path fully traced) or PLAUSIBLE.

---

## VERDICT

**No CRITICAL or HIGH findings. Cross-tenant isolation is solid — a treasurer of one site cannot obtain or email another site's donor data by any traced path.** Four lower-severity findings, all privacy/robustness rather than tenancy leaks: two MEDIUM GDPR/consent gaps (departed donors are auto-emailed despite the plan forbidding it; the donor's raw email survives erasure in `emailedTo`), one LOW double-email race, one LOW/informational settings-seed replay caveat that is a pre-existing house idiom. Recommend fixing SEC-01 and SEC-02 before merge.

---

## FINDINGS TABLE

| ID | Severity | Confidence | Location | Title |
| --- | --- | --- | --- | --- |
| SEC-01 | MEDIUM | CONFIRMED | `web/_apps/giving/statements-email.php:94-103` (+ cron:75-88, `Giving.php:641-729`) | Departed donors ARE auto-emailed — plan Q3 gate on `usActive` was never implemented; code comment falsely claims enforcement |
| SEC-02 | MEDIUM | CONFIRMED | `web/_core/GdprEraser.php:226-232, 399-425`; `web/_core/Giving.php:722-724` | GDPR erasure leaves the donor's raw email address in `tblGivingStatementLog.emailedTo` (over-retention of PII) |
| SEC-03 | LOW | CONFIRMED | `web/_apps/giving/statements-email.php:116-147`; `web/_core/Giving.php:641-729` | No atomic row-claim before send — concurrent UI + cron (or double-submit) can double-email one donor |
| SEC-04 | LOW | PLAUSIBLE | `web/_sql/172_giving_bulk_statements.sql:115-119` | `(NULL, key) ON DUPLICATE KEY UPDATE` global-settings seed relies on a UNIQUE key catching NULL-siteID rows; MySQL treats NULLs as distinct (systemic/pre-existing house idiom) |

---

## PER-FINDING DETAIL

### SEC-01 — Departed donors are auto-emailed (MEDIUM, CONFIRMED)

The plan is unambiguous (plan §9 Q3): *"exclude from bulk email … gate queueing on it [`usActive`] … automated email to departed members is a GDPR-contact judgement the software shouldn't make."* `Giving::statementsPreview()`'s own docblock repeats the promise:

```
web/_core/Giving.php:355-359
 * Donors with no active tblUserSites row for this site (#440 Q3 …) are
 * INCLUDED here (flagged `usActive` = false) … they are simply never
 * queued for bulk email (enforced separately by the email handler).
```

The email handler does **not** enforce it. The queue UPDATE selects purely on render state:

```
web/_apps/giving/statements-email.php:94-103
'UPDATE tblGivingStatementLog SET queuedAt = NOW() '
. 'WHERE siteID = ? AND periodKey = ? AND pdfPath IS NOT NULL '
. '  AND emailedAt IS NULL AND errorMsg IS NULL AND queuedAt IS NULL'
```

No `usActive` / `tblUserSites` join anywhere in the queue step, the send loop (`:116-129`), the cron selector (`cron/giving-statements.php:75-88`), or `sendStatementEmail()` (`Giving.php:641-729`). Verified by grep: the only `usActive`/`tblUserSites` references in the feature are inside `renderStatementPdf`'s gate and `statementsPreview`'s SELECT/decoration — none in any email path.

Full attacker/trigger path (no attacker needed — it fires on normal use):
1. `statementsPreview` returns departed donors (LEFT JOIN, `HAVING SUM(amountPence) > 0`) — `Giving.php:380-387`.
2. `upsertStatementLogRows` iterates **all** preview donors → creates log rows for departed donors too — `Giving.php:500-539`.
3. `renderStatementBatch` renders a PDF for every pending log row, no membership filter — `Giving.php:568-608`.
4. Queue UPDATE sets `queuedAt` on the departed donor's row (above).
5. Send loop → `sendStatementEmail()` checks email-valid + not-opted-out + pdf-exists, then sends. A departed member who never touched notification prefs is opted-in by default (`isOptedOutOfStatements` returns false for absent key, `Giving.php:452-462`) and is emailed.

Impact: the software makes exactly the automated-contact decision the plan said it must not; a former member receives an unsolicited year-end statement. Not a cross-tenant leak (the mail goes to the departed donor's own current address), so MEDIUM, but it is a real GDPR/consent regression and the in-code comment actively misleads a future maintainer.

Fix: gate queueing on active membership. Either add to the queue UPDATE a `AND EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = tblGivingStatementLog.donorID AND us.siteID = tblGivingStatementLog.siteID AND us.isActive = 1)`, or add the same membership re-check inside `sendStatementEmail()` (preferred — it also covers the cron path and matches the "re-validate live at send time" design of the other checks there), marking the row `errorMsg = 'donor-left-site'` so it surfaces as a skip rather than a send.

---

### SEC-02 — Raw donor email survives GDPR erasure in `emailedTo` (MEDIUM, CONFIRMED)

The erasure hook is deliberately filesystem-only and leaves the log rows in place:

```
web/_core/GdprEraser.php:226-232
// tblGivingStatementLog rows themselves stay (donorID is NOT NULL there …)
// but the RENDERED PDF is a name-bearing document … the files are unlinked here.
$any = (self::eraseGivingStatementFiles($requestId, $userId) > 0) || $any;
```

`eraseGivingStatementFiles` only globs+unlinks `statement-{userId}-*.pdf` (`:399-425`). `tblGivingStatementLog` is **not** in `catalogue()` and nothing nulls its columns. But that table stores the concrete mailed address:

```
web/_core/Giving.php:722-724
$u = $db->prepare('UPDATE tblGivingStatementLog SET emailedAt = NOW(), emailedTo = ? WHERE logID = ?');
… $u->bind_param('si', $email, $logId);   // $email = the donor's real address
```

Schema: `emailedTo VARCHAR(255) … 'Address actually mailed (audit)'` (migration 172:95). After an Article-17 erasure, `tblUsers.emailAddress` is tombstoned (`GdprEraser.php:206-209`) but the same address persists verbatim in `emailedTo`. An email address is personal data; it is not part of the HMRC 6-year financial-record retention basis that justifies keeping `totalPence`/`giftAidPence`, so this is over-retention — the builder's claim in item 4 that "no orphaned PII in the log beyond the donorID FK" is disproven by `emailedTo`.

(By contrast `donorID` resolving to a tombstoned in-place user row, and `pdfPath` being a dangling string containing an integer userID, are acceptable residue — no name, no address.)

Fix: in `eraseGivingStatementFiles` (or a small catalogue-style step), also run `UPDATE tblGivingStatementLog SET emailedTo = NULL WHERE donorID = ?` for the erased user. This keeps the run-history/amount audit while removing the PII, matching the tblGivingEntry "amounts kept, identity detached" convention the code cites.

---

### SEC-03 — No atomic claim before send; double-email race (LOW, CONFIRMED)

`sendStatementEmail()` reads the row, sends, then sets `emailedAt` (`Giving.php:641-729`). Selection of pending rows (`statements-email.php:116-129` and `cron/giving-statements.php:75-88`) filters `emailedAt IS NULL` but nothing claims the row atomically before the mail is dispatched. The dedupe fence (`UNIQUE(siteID,donorID,periodKey)` + `emailedAt IS NULL`) genuinely prevents **sequential** re-selection, but two overlapping runs — a treasurer double-clicking "Email statements", or the UI run and the cron sweeper firing together — can both select the same queued row, both call `sendStatementEmail`, and both send before either writes `emailedAt`. Result: the donor receives the statement twice.

Impact is limited to duplicate mail (no leak, no wrong recipient), hence LOW. Fix: claim-then-send — `UPDATE … SET emailedAt = NOW() WHERE logID = ? AND emailedAt IS NULL`, proceed only if `affected_rows === 1`, and roll `emailedAt` back to NULL (or mark error) if the send throws. This mirrors the atomic `UPDATE … WHERE status='pending'` pattern the payments code adopted for its own two-event race.

---

### SEC-04 — Global-settings seed idempotency caveat (LOW, PLAUSIBLE)

```
web/_sql/172_giving_bulk_statements.sql:115-119
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'giving.statements.batchPerRun',  '25', '25', 0),
    (NULL, 'giving.statements.emailSubject', 'Your {year} giving statement', …, 0),
    (NULL, 'giving.cron_token',              '', '', 1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
```

`tblSettings` uniqueness is `UNIQUE KEY uq_setting_key_site (settingKey, siteID)` (full_schema.sql:99). MySQL treats NULL as distinct in a UNIQUE index, so two rows `('giving.cron_token', NULL)` do **not** collide and `ON DUPLICATE KEY UPDATE` never fires — the installer replays every migration after `full_schema.sql` (which already folds these three rows), so a replay can in principle insert a second copy of each global row.

This is **not introduced by this PR** — it is the identical idiom used by e.g. `167_paypal_checkout.sql:58-61` and the settings seeds throughout `_sql/`. If those replay cleanly under the `e2e-migrations` harness (the CLAUDE.md claim), so does 172; if there is a latent NULL-siteID duplicate-row issue, it is systemic and pre-existing. Flagged only for completeness against item 5's "idempotent" claim. No action needed for this PR beyond awareness; a codebase-wide fix would switch to a settings-upsert that keys on a non-null sentinel or a `WHERE NOT EXISTS` guard. The cron_token row itself is fine on first install (empty + `isSensitive=1` ⇒ fail-closed, verified below).

---

## PER-CATEGORY CLEAN STATEMENTS (with evidence)

**Cross-tenant isolation — CLEAN (CONFIRMED).** Every donor/entry/statement query binds the treasurer's own `Site::id()`:
- `renderStatementPdf` donor gate binds `siteId` to both EXISTS arms (`Giving.php:199-207`); entries query is `WHERE e.siteID = ? AND e.donorID = ?` (`:225-229`) — a site-B entry can never enter a site-A PDF.
- `statementsPreview` / `statementsExcludedSummary` / `upsertStatementLogRows` / `renderStatementBatch` all bind `siteId` (`:392, :482, :508-532 via preview, :575, :615`).
- `statements-download` single-donor SELECT: `WHERE l.siteID = ? AND l.donorID = ? AND l.periodKey = ?` (`:73-79`); ZIP SELECT `WHERE l.siteID = ? AND l.periodKey = ?` (`:116-123`).
- `statements-generate` retry UPDATE and `statements-email` resend/queue/select all bind `siteID` (`generate:79-83`, `email:72-78, 94-103, 116-122, 150-156`).
- The log rows are only ever created for the creating site (via `statementsPreview(siteId)`), so a treasurer's `Site::id()` never matches another site's row. Roles are global by schema design (`tblUserRoles` has no siteID column), so tenancy rests entirely on this data-layer scoping — which holds on every query.

**Treasurer-only authz — CLEAN.** `statements.php:36-39`, `statements-generate.php:41-44`, `statements-email.php:39-42`, `statements-download.php:48-51` each call `Giving::canManage()` (admin-or-treasurer, `Giving.php:30-33`) and `Router::renderError(403)` **before any work**. The admin entry point is a treasurer-visible button on the already-gated `giving/manage.php`. Cron is token-gated (below), not role-gated. No handler does work ahead of the gate.

**Cron token gate — CLEAN (fail-closed).** `cron/giving-statements.php:57-62`: `$expected === '' || hash_equals($expected, $incoming) === false` ⇒ 403. Constant-time compare; empty stored token always 403s; migration 172 seeds `giving.cron_token` empty + `isSensitive=1` so the endpoint is inert until an admin sets a real token. `Site::forceContext` per row also refuses cross-site switches when multisite is disabled (`Site.php:455-462`).

**CSRF — CLEAN.** Both state-changing POST handlers verify first: `statements-generate.php:45-48` and `statements-email.php:43-46` reject non-POST or bad `csrf_token` with 400. All POST forms embed `Auth::csrfToken()` (`statements.php:147,163,223`). `statements-download` is a read-only GET (streams already-generated files); the transient ZIP it writes lives under non-public `_uploads/` and is unlinked after send — no CSRF-sensitive state change, matching the house convention for read-only GETs.

**Gift Aid correctness — CLEAN.** Eligibility is a correlated `EXISTS` per entry, never a declaration JOIN: `renderStatementPdf` (`Giving.php:220-223`, summed in PHP `:246-256`) and `statementsPreview` (`SUM(CASE WHEN EXISTS(...) THEN e.amountPence ELSE 0 END)`, `:375-379`). Because `EXISTS` is boolean per entry, overlapping active declarations cannot double-count. No 25% reclaim projection anywhere — only "Gift Aid eligible" sum is shown, with an explicit donor-facing disclaimer (`:292-302`); grep for `25%`/`*0.25` finds only the comment documenting its deliberate absence.

**Path traversal / file handling — CLEAN.** The only user-influenced component of the PDF path is `periodKey = "{from}_{to}"` (`Giving.php:307-313`). Both `from`/`to` must pass `strtotime` (validated in `statements-generate.php:55-65` with a ≤5-year cap, re-validated in `renderStatementPdf:191-195`). Tested empirically (PHP 8.4): `strtotime` returns `false` for every slash-, null-, or backslash-bearing payload (`"2024-12-31/../../etc"`, `"2024-12-31\x00../"`, `"../../etc/passwd"` all → false), so no `/`, `\`, or NUL can reach the filename. Served paths in `statements-download` come from the site-scoped DB `pdfPath`, not raw input — no traversal, and the served file is always under `_uploads/giving/statements/{siteID}/`. `Content-Disposition: attachment` + `Content-Type: application/pdf`/`application/zip` are set on every stream (`download:95-97, 184-186`, `my-statement:39-41`).

**bind_param arity — CLEAN.** All 20+ new prepared statements verified placeholder-count == types-length == arg-count. Spot examples: donor gate `'iii'`/3 args (`Giving.php:207`); entries `'iiss'`/4 (`:229`); upsert `'iisssiii'`/8 (`:522-532`); preview `'iss'`/3 (`:392`); download single `'iis'`/3 (`download:79`); email queue `'is'`/2, select `'isi'`/3 (`email:100,122`); cron select `'i'`/1 (`cron:81`). No mismatch found.

**SQL injection — CLEAN.** Every query is a MySQLi prepared statement with bound params; no user input is interpolated into SQL. `GdprEraser` interpolates table/column identifiers but only from its hardcoded `catalogue()` array (no request data) — `:257, :320, :345-368`.

**XSS — CLEAN.** PDF HTML escapes all dynamic values via `$esc = htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` — periodLabel, charity name/no, donor name/email, category, method, reference, formatted amounts (`Giving.php:243, 270-302`). The email template escapes every interpolation with `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` (`giving-statement.html.php:12-33`). The list UI in `statements.php` escapes all row output (`:188-241`). Subject placeholders (`str_replace {year}/{period}/{charity}`, `Giving.php:696`) draw from settings/date, not donor input, and are not an HTML sink.

**`u.emailAddress` never `u.email` — CLEAN.** `renderStatementPdf:200`, `buildHmrcCsv:109`, `statementsPreview:371`, `sendStatementEmail:650,664`. Grep for `u.email`/`->email` finds only the guard comment at `Giving.php:662`.

**Dedupe integrity — CLEAN (sequential).** `UNIQUE(siteID,donorID,periodKey)` (migration 172:101); upsert refreshes totals only, never touching `pdfPath`/`emailedAt` (`Giving.php:508-513`); every re-run selector carries `emailedAt IS NULL`. The resend override is treasurer+CSRF gated, scoped to `siteID`+`periodKey`, and audit-logged via `Logger::activity('GivingStatementsResend', …)` (`statements-email.php:71-86`). (Concurrency gap tracked separately as SEC-03.)

**Migration MySQL-8 safety — CLEAN.** Only `CREATE TABLE IF NOT EXISTS` + `INSERT … ON DUPLICATE KEY UPDATE`; no MariaDB-only `IF [NOT] EXISTS` on ADD/DROP/CHANGE. Routes seed keyed on `routeKey` and self-record keyed on `filename` are genuinely idempotent. full_schema.sql fold matches the migration exactly (table incl. `emailedTo`, UNIQUE key, 3 FKs; 3 settings rows; 5 route rows) — verified against `git show 7898b1b -- web/_sql/full_schema.sql`.

**PDF/ZIP abuse & temp-file cleanup — CLEAN (with SEC-03 caveat).** dompdf receives only server-built, fully-escaped HTML with no user-controlled URLs (no remote-resource vector). ZIP is built with `ZipArchive`, degrades gracefully when the class is absent (`download:105-110`), refuses to bundle un-generated periods unless `&partial=1`, skips missing files per entry, and unlinks the transient bundle after streaming (`download:171-189`). Bundles live under non-public `_uploads/`. `Mailer::attach` and `sendStatementEmail` both enforce the 4 MB cap (`Mailer.php:327`, `Giving.php:679-682`) so an over-size PDF becomes an explicit `pdf-too-large` row error rather than a bodiless send.

---

## VERDICT ON EACH OF THE BUILDER'S 5 FLAGGED ITEMS

**1. `renderStatementPdf()` OR-of-two-EXISTS site-scope gate — CONFIRMED SOUND.** No `(donorID, siteID)` combination leaks another site's giving data. Both EXISTS arms bind the *same* `siteId` (`Giving.php:207`): arm 1 = active `tblUserSites` membership at this site, arm 2 = a `tblGivingEntry` recorded at this site. Even when a donor passes the gate via membership alone, the entries query (`WHERE e.siteID = ? AND e.donorID = ?`, `:225-229`) restricts the statement body to this site's gifts — a departed donor with history at site A yields site-A rows only; a donor with neither membership nor gifts at the site matches neither arm and renders nothing. The name/email printed come from the global `tblUsers` row, which the treasurer legitimately relates to via one of the two arms. Additionally, this function is never reachable with an attacker-chosen donorID: the only treasurer entry is `renderStatementBatch`, which iterates donors drawn from `statementsPreview(siteId)` (site-scoped). No cross-tenant render path exists.

**2. `statements-download.php?donorID=` single-donor branch — CONFIRMED SOUND.** The row lookup is fully site-scoped: `WHERE l.siteID = ? AND l.donorID = ? AND l.periodKey = ?` with `siteID` bound to `Site::id()` (`download:73-79`). A treasurer of site B cannot select a site-A row by guessing a donorID (their `Site::id()` is B). The streamed path is taken from the site-scoped row's `pdfPath` (DB-derived, written only as `_uploads/giving/statements/{siteID}/statement-{donorId}-{periodKey}.pdf`), never from raw input — no traversal. Stream sets `Content-Disposition: attachment` + `Content-Type: application/pdf` and the file lives under the per-site `_uploads` directory. `is_file()` re-check means a GDPR-unlinked file returns a friendly "not generated" flash rather than an error/leak.

**3. `sendStatementEmail()` live re-checks — PARTIALLY SOUND.** Confirmed correct: the recipient is always the *donor's own* address, re-read live from `tblUsers` by `$logRow['donorID']` (`Giving.php:650-664`), never from POST; the attached PDF is that same row's `pdfPath`, consistent with the row's donorID; opt-out, email validity, and 4 MB file-size are all re-checked at send time. The check ordering cannot be raced to email an opted-out donor (opt-out is re-read live and returns before send). **However**, two gaps: the departed-donor exclusion the plan requires is absent from this path (SEC-01), and there is no atomic row-claim, so concurrent runs can double-send (SEC-03).

**4. GdprEraser filesystem-only sweep — PARTIALLY SOUND.** The PDF unlink is correct and complete (globs across all site dirs by embedded userID, `GdprEraser.php:399-425`). Deliberately not nulling `donorID` (NOT NULL, resolves to an in-place tombstoned user) is coherent. **But** the log rows DO leak PII after erasure: `emailedTo` retains the donor's raw email address (SEC-02). The builder's stated assumption ("no orphaned PII in the log beyond the donorID FK") is incorrect — `emailedTo` must also be nulled.

**5. Migration 172 — SOUND, with one caveat.** Seeds-only + `CREATE TABLE IF NOT EXISTS`, MySQL-8-safe (no MariaDB-only DDL), `giving.cron_token` seeded empty + `isSensitive=1` ⇒ cron fail-closed (verified against the endpoint gate), full_schema fold parity exact (table/settings/routes), routes + self-record idempotent. Caveat SEC-04: the three global `(NULL, key)` settings rows rely on `ON DUPLICATE KEY UPDATE` catching a NULL-siteID unique collision, which MySQL does not treat as a collision — a replay-after-full_schema could duplicate them. This is the established house idiom (identical to migration 167 et al.), pre-existing and systemic, not a defect introduced here.

---

## KEY QUESTION

**Can a treasurer of one site obtain or email another site's donor data by any path? — NO.** Every generate, preview, download, email, retry, resend, and cron path constrains to `Site::id()` (or, in cron, to each row's own embedded `siteID` with a `forceContext` guard), and log rows only ever exist for the site that created them. No confirmed cross-tenant read, download, or email path exists. The residual findings (SEC-01/02) concern a donor's *own* data being over-contacted or over-retained, not another tenant's data.
