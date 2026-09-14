# #479 data download — Stage 4: FINAL DESIGN AND BUILD PLAN

Date: 13 September 2026. Branch: `claude/alpha-wip`. Design and plan only — no repository file was edited.

Written to: `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/WebMS-Intra/.claude-work/reviews/design-479-4-plan.md`

This is the last of four sequential stages. It folds every sound point from the challenge (`design-479-3-challenge.md`) into the design from Stage 2 (`design-479-2-design.md`), on the facts the survey established (`design-479-1-survey.md`). Where this document repeats an earlier stage's evidence it says which stage proved it. Where it says PROVEN with no stage named, I read or ran it myself in this stage.

**How to read the marks.** PROVEN means read in the code or run on this machine. INFERRED means worked out from what was read but not run — there is no database and no DreamHost server here. Nothing in this document was run against a database.

---

## Part 0 — Facts checked in this stage that change or settle the plan

| Fact | Mark | Where |
| --- | --- | --- |
| No file named `194_*` exists in `web/_sql/`; the highest is `193_trusted_proxies_and_channel_gate.sql` (untracked in the working tree). **Migration 194 is free.** | PROVEN | `ls web/_sql/`, `git status` |
| `check_personal_data_coverage.py` finds a catalogue entry with the regular expression `'(tbl…)'\s*=>\s*\[\s*'decision'\s*=>` — so **`'decision'` must stay the FIRST key of every entry**. I tested it: an entry with `links` before `decision` is not seen at all, and the table would then be reported as "no decision recorded". | PROVEN (ran the regex on both orderings) | `check_personal_data_coverage.py:145-147` |
| The coverage check has no exclusion list. Every table with a column matching its `PERSONAL_COLUMNS` pattern must be in the catalogue. So adding `tblDataDownloadJobs` (which has `userID`) will make the check demand a catalogue entry for it — which is what we want. | PROVEN | same file, lines 86-112, 160-175 |
| `pr-security.yml` runs fifteen Python checks; **none of the six `tools/*-selftest.php` files runs in any workflow**, and there is no `pip install` or `setup-python` step (the runner is `ubuntu-latest`, which carries Python 3). | PROVEN | `grep` over `.github/workflows/*.yml` |
| A fixtures folder already exists, untracked: `tools/audit-checks/fixtures/static_calls/…` (the check-16 rebuild in flight). A `fixtures/data-download/` folder beside it follows that convention. | PROVEN | `find tools/audit-checks/fixtures` |
| `RateLimiter::clientIp()` in the working tree now believes a forwarded header only from a listed trusted proxy (`portal.trustedProxies`, migration 193). `Logger::clientIp()` still believes any `CF-Connecting-IP` or first `X-Forwarded-For` hop. | PROVEN | `RateLimiter.php:467-520`; `Logger.php:525-536` |
| The only portal-wide-only settings read is `Gatekeeper::portalWideSetting()` (`… WHERE settingKey = ? AND siteID IS NULL LIMIT 1`), and it is private. `RateLimiter::loadProxySettings()` does the same inline. | PROVEN | `Gatekeeper.php:329-333`; `RateLimiter.php:849-862` |
| The Google sign-in start sends `prompt=select_account`; the Microsoft start sends **no** `prompt` at all. Both callbacks verify the ID token, take `sub` and `email`, and look the person up by `findUserByLink(provider, sub)` before falling back to email. | PROVEN | `Auth.php:380-392, 616, 714-737` |
| The sign-in page records a failed attempt as `Logger::activity('LoginFailed', 'Failed login attempt for: ' . $identifier)`, and `RateLimiter::isUserOrIpBlocked()` counts recent failures per address and per username. | PROVEN that both exist; INFERRED that the per-username count reads those `LoginFailed` rows (I did not read `countRecentFailuresByUsername()`) | `Auth.php:864`; `RateLimiter.php:134-160` |
| An administrator setting a member's password writes only a generic `UserUpdated` activity row — nothing records that a **password** was set. | PROVEN | `admin/users/save.php:246, 256` |
| `account/save.php` changes the email address with CSRF only, no password re-check (the challenge's C1). `2fa/disable.php` does re-check the password and refuses for accounts with no local password. | PROVEN | `account/save.php:38,120`; `2fa/disable.php:53-80` |
| `2fa/setup.php` writes the plaintext backup codes into the session at line 104 and logs `TotpEnabled` at line 107 — so that log row's snapshot carries the codes (the challenge's E1). | PROVEN | `auth/2fa/setup.php:104-107` |
| `kids/checkout.php` splits `pickupAuthorisedNames` on commas and compares lower-cased, trimmed names. So the list's format is settled: comma-separated, case-insensitive. | PROVEN (the Stage 2 inference is now confirmed) | `kids/checkout.php:59-63` |
| The self-service "delete my account" page (`delete-confirm.php`) does **not** go through `GdprEraser`: it runs its own seven `DELETE`s and one `UPDATE tblUsers`. So anything the eraser must do about prepared downloads has to be done on that path too. | PROVEN | `delete-confirm.php:83-126` |
| `erasure-request.php` sends its confirmation link with `Mailer::send()` inside a try/catch that swallows failure, and the page still says "A confirmation email has been sent". | PROVEN | `account/erasure-request.php:71-79` |
| `tblZoomMeeting.passcode` VARCHAR(50) and `tblUserSmsPreference.verificationCode` VARCHAR(10) are the two columns the challenge could not place. The first is a meeting passcode (the organisation's); the second is the person's own SMS verification code. | PROVEN | `full_schema.sql:3854, 4212` |
| Every cron route is seeded `isProtected = 0` (ten of them). | PROVEN | `full_schema.sql` cron route seeds |
| The seed idioms to copy: settings `INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) VALUES … ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)`; routes `… ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)`; self-record `INSERT INTO tblMigrations (filename) VALUES ('…') ON DUPLICATE KEY UPDATE filename = filename`; guarded column add via `information_schema` + `PREPARE`. | PROVEN | migrations 191 and 193 |
| The parity check requires every migration filename in `full_schema.sql`'s `tblMigrations` seed, and every settings key and route key seeded by a migration to be seeded in `full_schema.sql` too. The idempotency check refuses a bare `ADD COLUMN`, `CREATE TABLE` without `IF NOT EXISTS`, or an `INSERT` without `ON DUPLICATE KEY UPDATE`. | PROVEN | both scripts' headers and rule lists |
| The E2E migration harness runs `full_schema.sql` fresh and then replays every numbered migration on `mysql:8.0.36`. Migration 194 will run there first. | PROVEN | `e2e-migrations.yml:12-35` |
| PHP 8.5.10 on this machine has `ZipArchive`, `ZipArchive::CM_STORE` and `JSON_INVALID_UTF8_SUBSTITUTE`. Whether the live server's PHP has `ZipArchive` is still unknown. | PROVEN here; the server INFERRED | `php -r` |
| Baseline before any change: `tools/gdpr-coverage-selftest.php` 13 passed, 0 failed; the coverage check reports OK with 131 tables. | PROVEN | ran both |

---

## Part 1 — The design in short

1. **One list drives both rights.** `web/_core/personal-data-catalogue.php` gains, per table, an explicit map of every column linking a row to a person and whether that column means the record is **about** the person or that they **acted** on it. A new reader class loads it for both the eraser and the download. A hand-written query block can never come back: a self-test fails if `data-export.php` names a table.
2. **About-rows are handed over in full; acted-rows become a summary line** (table, record number, "you approved this", when). Six of today's blocks already hand over actor-matched content and will stop doing so.
3. **Every link column is matched, one query per table, one row once**, tagged with the relationships it matched on. The same change fixes #492 item 1 in the eraser.
4. **Records kept by law are listed, not handed over.** For pastoral-care tables the listed line is a fixed sentence that never confirms whether a record exists (owner decision D10).
5. **Tables with no link are named**, each with a sentence saying why and how to ask. Free text is not searched by name; the file says so.
6. **Prepared, in resumable chunks, crash-safe.** A job table records a cursor AND a byte offset; each chunk truncates any half-written page before continuing, releases its lock when it returns, and never holds more than one page of rows. Most members' downloads finish in the first twenty-second chunk.
7. **One ZIP**: `data.json`, one JSON file per table, a self-contained `summary.html`, `README.txt`, and only the person's own expense receipts under `files/` (stored uncompressed, capped). Folder fallback when the server has no ZIP support.
8. **A JSON Schema with a description on every property**, checked in CI by a Python validator that fails if it cannot validate, against fixtures the real PHP class regenerates.
9. **Identifiers only from catalogue ∩ `information_schema`**; every value bound; a missing table or column is written into the file as "could not read", never an empty list.
10. **Credential rule with a type correction, plus allow-lists inside JSON columns.** The session snapshot and request headers are handed over only through an allow-list of known-safe keys; everything else is replaced with a placeholder naming the key. Error traces are never handed over.
11. **Proof, twice.** Password (plus a two-factor code when enabled); or, for people with no password, a fresh sign-in through their provider (owner decision D9) with an emailed link as the fallback; the emailed path is refused for seven days after the registered address changes. The download link's token travels only in email; the account page never shows it; downloading from the page needs a proof made in the last fifteen minutes.
12. **Audit that names the person**, rate limits shared with the sign-in lockout, a "ready" and a "collected" email to the registered address, and a "your email address was changed" notice to the old address.
13. **An administrator may queue one for somebody who asks in writing** (owner decision D1), never receives the file, and the design says plainly that an administrator who resets the password could still collect it — with a 24-hour refusal after any administrative password reset, so it is at least slow and logged.
14. **Erasure cancels and deletes any prepared download**, on both erasure paths, and at the moment an erasure request is confirmed.
15. **Migration 194 and `full_schema.sql`**: one new table, three new columns, eleven settings, seven routes.

---

## Part 2 — What the challenge found, and what happened to each point

Every point was checked against the code before being adopted. "Adopted" means it is in Part 3 or 4 below and in the build plan.

| # | Finding (severity as the challenge rated it) | Verdict | Where it lands |
| --- | --- | --- | --- |
| A1 | `tblEventRegistrations.notes` (internal moderation notes) newly handed over — MEDIUM | **Adopted.** `exclude` on that table, and a committed golden file listing every free-text column each full-tier table hands over. | Part 4.1; Step 1; Step 3 (golden file) |
| A2 | Approvals, payments and visitor contacts are one hop from the person — LOW | **Adopted.** `reach` for `tblExpenseClaimApprovals`, `tblExpenseClaimPayments`, `tblVisitorContact`. | Part 3 B; Step 1 |
| A3 | A care-case count confirms a case exists — for the owner | **Adopted, subject to D10.** A fixed sentence for `tblCareCase`, `tblCareVisit`, `tblCareAccessLog`; the true count only in the audit trail. | Part 3 D; owner decision D10 |
| A4 | "By construction" overstated for loan contact details — LOW | **Adopted.** Wording only. | Part 3 E, K2 |
| A5 | Audit-trail rows ABOUT the person (an administrator changed their profile) are not found — MEDIUM | **Adopted.** A bespoke reader entry: `tblAuditTrail WHERE tableName = 'tblUsers' AND recordID = ?`. | Part 3 B; Step 3 |
| B | Root administrator's summary list is long — note | **Adopted.** One sentence in `summary.html`. | Part 4.5 |
| C1 | Email address changes without a password; both email layers send there — HIGH | **Adopted, four ways.** Provider round-trip preferred for SSO users (D9); TOTP required in addition to password when enabled; emailed path refused for 7 days after an address change (`tblUsers.emailChangedAt`, migration 194); a notice to the OLD address on every change. "Require the password to change the address" is filed as its own issue. | Part 3 I; Steps 4, 7; Part 6 |
| C2 | Download token shown to the session — HIGH | **Adopted, simplified.** The page never renders the token on any path. The file route accepts EITHER the emailed token OR a proof made in the last fifteen minutes; `proofMethod` is recorded on the job. | Part 4.4 |
| C3 | The password re-proof is a guessing endpoint — MEDIUM | **Adopted.** Failures are written exactly as the sign-in page writes them so the same per-username lockout counts them; `isUserOrIpBlocked()` is checked first. | Part 4.4 |
| C4 | Confirmation on a GET is fetched by mail filters — MEDIUM | **Adopted.** The confirm address shows a POST button with CSRF; the GET changes nothing. The same fault in `erasure-confirm.php` is filed separately. | Part 4.4; Part 6 |
| C5 | An administrator who resets the password can collect the file — MEDIUM | **Adopted.** D1 says so plainly; `tblLocalAccounts.passwordSetByAdminAt` (migration 194) blocks collection for 24 hours after an administrative reset; a "collected from" email goes to the registered address on every fetch. | Part 3 H; Part 4.4 |
| C6 | Recorded address is spoofable — LOW | **Adopted.** `RateLimiter::clientIp()` is passed into the job row and the emails. | Part 4.4 |
| D1 | A killed chunk duplicates rows or leaves invalid JSON — MEDIUM | **Adopted.** `cursorOffset` + `ftruncate()` on claim; cursor and offset persisted in one UPDATE after `fflush()`; self-test simulates a crash. | Part 4.3 |
| D2 | The lock is never released between chunks — MEDIUM | **Adopted.** `lockedAt = NULL` in the same UPDATE that persists the cursor on a normal return. | Part 4.3 |
| D3 | Memory is not "one row" for wide tables — MEDIUM | **Adopted, the simpler way.** Page size by column type: 500 rows for tables with no TEXT/BLOB/JSON column, 50 where there is one. `get_result()` stays (a bounded page), rather than `bind_result()` streaming, because it is less new code. | Part 4.3 |
| D4 | Invalid UTF-8 silently makes an empty download today — MEDIUM | **Adopted.** Per-row encoding with `JSON_INVALID_UTF8_SUBSTITUTE`, return value checked, a failed row named in `completeness.notes`; self-test feeds a bad byte. | Part 4.3 |
| D5 | The final step cannot be chunked and can be big — LOW-MEDIUM | **Adopted.** Receipts stored with `CM_STORE`; `dataDownload.maxFilesMb` (50); the finish is split into resumable stages; `summary.html` is streamed from the per-table files. | Part 4.3 |
| D6 | Chunk-by-chunk logging pollutes the exported table — LOW | **Adopted.** Log requested / confirmed / ready / fetched / expired / failed / cancelled only; chunk progress lives in the job row. | Part 4.4 |
| D7 | A catalogue change mid-job changes the plan — LOW | **Adopted.** Ordered table list and the catalogue file's SHA-256 snapshotted into the job at queue time; the hash goes into `data.json`. | Part 4.3 |
| D8 | Two rapid requests make two jobs — LOW | **Adopted.** `INSERT … SELECT … WHERE NOT EXISTS (…)` gated on `affected_rows === 1`. | Part 4.4 |
| E1 | The session snapshot holds raw credentials the scrub misses — HIGH | **Adopted, and made stricter.** Instead of a deny-list of known-bad keys, an **allow-list of known-safe keys**; every other key is replaced by a placeholder naming the key. The same for request headers. The source fixes (Logger strip; `2fa/setup.php` ordering) are filed separately. D5 is "yes" only with this in place. | Part 4.2; Part 6 |
| E2 | Error rows contain stack traces with argument values — HIGH | **Adopted.** `tblErrors.userID` classified `acted`; `errorDetail`, `requestHeaders`, `requestURL` always excluded. The source fix is filed separately. | Part 4.2; Step 1 |
| E3 | `Referer` carries tokens — LOW | **Adopted.** In the headers allow-list, `Referer` is kept only with its query string removed. Source fix filed. | Part 4.2; Part 6 |
| E4 | `passcode`, `verificationCode` — LOW | **Adopted.** Both always excluded (`tblZoomMeeting.passcode`, `tblUserSmsPreference.verificationCode`). | Part 4.2 |
| F1 | Hand-written eraser entries still win, so the two lists are one in name only — MEDIUM | **Adopted.** Self-test: for every hand-written table, the set of `userCol` values must equal the keys of the catalogue's `links`. This will demand new hand-written entries for second link columns (for example `tblEventAttendance.markedByID`), which is the point. | Step 1 |
| F2 | `tblExpenseClaims` hand-written entry must change with the catalogue — LOW | **Adopted.** Hand-written `action => 'retain'` with the six-year reason (subject to D2). | Step 1 |
| F3 | Erasure does not know about prepared files — MEDIUM-HIGH | **Adopted.** `DataDownload::cancelForUser()` called from the eraser, from `delete-confirm.php`, and at erasure-request confirmation; catalogue entry `erase` for `tblDataDownloadJobs` naming the bespoke step. Lands in the SAME step as the job table. | Step 4 |
| F4 | Guardian-flag erasure un-registers a child from a future event — LOW-MEDIUM | **Adopted.** Delete only where the event has ended; otherwise anonymise the submitter and keep the row, with the reason in the erasure audit. | Part 3 K3; Step 5 |
| G1 | Flipping one word in the catalogue changes the download unnoticed — MEDIUM | **Adopted.** Golden file `tools/audit-checks/fixtures/data-download/tiers.json` regenerated by the self-test and compared. | Step 3 |
| G2 | The draft script defaults `userID` to "about" — LOW | **Adopted.** The committed draft helper flags any link column whose schema COMMENT contains any of the words approver, reviewer, actor, "who ", acting, moderat, logged-in, and defaults those to `acted`. | Step 1 |
| H | Nothing MySQL-only, MariaDB-only or command-line-only | Nothing to fold. The self-test includes both `json` and `longtext` as the DATA_TYPE of a JSON column. | Step 3 |
| I | `tblEmailLog` `LIKE` unescaped, and the eraser's `REPLACE` damages other addresses | **Adopted.** Escaped, comma-bounded match for the download count; the eraser's `REPLACE` fixed in Step 6 (same file, same shape). | Steps 3, 6 |
| I | `Mailer::send()` returns false silently | **Adopted.** `lastError` on the job; the page says what to do. | Part 4.4 |
| I | `dataDownload.enabled` must be portal-wide only | **Adopted.** A private `portalWideSetting()` copied from `Gatekeeper` (not shared yet — `Gatekeeper.php` is mid-change in another package). | Part 4.4 |
| I | ZIP entry names / `realpath()` under `_uploads/expenses/` | **Adopted.** | Part 4.3 |
| I | `Content-Security-Policy: sandbox` on the folder-fallback route | **Adopted.** | Part 4.4 |
| I | Total disk cap | **Adopted.** `dataDownload.maxTotalMb` (500), summed from the job table's `fileSize` of ready jobs. | Part 4.3 |
| I | Expiry computed in SQL | **Adopted.** `readyAt + INTERVAL ? DAY`, compared in SQL. | Part 4.3 |
| I | `tblCareAccessLog` for the care subject | **Adopted via D10.** Hidden with the case under the fixed sentence. | Part 3 D |
| I | K1's dangerous-delete check must run on per-column instructions | **Adopted.** | Step 6 |

Nothing in the challenge was declined. Two of its proposals were narrowed: D3 (page size by type rather than a streaming fetch) and E1 (an allow-list rather than a deny-list), both in the "refuse when unsure" direction.

---

## Part 3 — The final design, A to K

Each answer states the recommendation, the evidence, and what changed from Stage 2.

### A. Other people's data in "your" download

**Recommendation.** Classify every link column on every table, per (table, column), in the catalogue as `about` or `acted`. A row matched on at least one `about` column is handed over in full, minus excluded and credential columns. A row matched only on `acted` columns becomes a **summary line**: the table's plain label, the record's identity number, the relationship phrases ("you approved this"), the organisation (`siteID`), the timestamps, and — only where the catalogue's `summaryExtra` names them — a non-personal title or file name so the line is recognisable ("you uploaded `service-2026-03.mp3` on 3 March"). A **listed** table gives counts only, or a fixed sentence (D10).

**Evidence** (PROVEN, survey §1c and design §A): the three counter-examples that prove a name-only rule fails — `tblExpenseClaimApprovals.userID` is the approver; `tblVisitor.assignedToID` and `tblSalvationCards.assignedToID` are the follow-up worker while `tblTasks.assignedToID` is the person; `tblAuditTrail.userID` is who made a change to somebody else's record. Six of today's blocks return content of actor-matched rows (PROVEN, `data-export.php:138-170, 231-235`).

**Changed since Stage 2.** (1) `tblEventRegistrations` gets `exclude => ['notes', 'reviewedByID', 'reviewedAt']` — `notes` is "Internal moderation notes" (PROVEN, `full_schema.sql:5660`) and today's block deliberately leaves it out. (2) `tblErrors.userID` is `acted` — an error row is about the portal failing while the person was signed in, and `errorDetail` holds stack traces with argument values (PROVEN by the challenge running PHP). (3) A committed golden file lists, for every full-tier table, the free-text columns that will be handed over, so the move from hand-picked columns to full rows is reviewed column by column once, and any later change appears as a diff.

**The two "about" rows that still carry other people's data.** `tblPrayerRequests.partnerNote` is excluded (`exclude`). `tblKidProfiles` matched on `parentUserID` (acted, PROVEN `GdprEraser.php:376`) gives the parent a summary line per child (D3). `tblEventRegistrations` on `submittedByUserID` gives the full row only when `submitterIsGuardian = 1` (K3); NULL is never guardian.

### B. Finding the person everywhere

**Recommendation.** One query per table, `OR` across every classified link column, `ORDER BY` the single-column primary key, paged. Each row carries `_relationships`: the plain phrase for each link column whose value equals the person's id. A row appears once whatever it matched on. The phrase dictionary lives in the reader class; the self-test fails if any name in `LINK_COLUMNS` has no phrase.

**Widen the vocabulary.** `GdprEraser::LINK_COLUMNS` (41 names, PROVEN) gains the 23 the survey found (§2f): the 13 with a real foreign key to `tblUsers` (`uploadedByID`, `preparedByID`, `actorUserID`, `importedByID`, `personUserID`, `visitedByID`, `followUpAssignedToID`, `operatorID`, `sentByID`, `postedByID`, `contactedByID`, `actedByID`, `performedByUserID`) and the 10 without (`assignedToUserID`, `checkedInByID`, `checkedOutByID`, `viewerID`, `paidByID`, `matchedByID`, `resolvedByID`, `submittedByID`, `statusChangedByID`, `giverID`). All go into `ACTOR_COLUMNS` except `personUserID` (the care-case subject) and `giverID` (the envelope giver) — 21 names. These constants become the DEFAULT the draft helper proposes; the catalogue's explicit `links` map is what runs. The Python check stops keeping its own fifteen-name list and reads `LINK_COLUMNS` from `GdprEraser.php`, and ALSO treats any `FOREIGN KEY (…) REFERENCES tblUsers` as a link, so a brand-new column name is caught by the database structure.

**One hop away.** `reach` on seven tables: `tblCareVisit` (`caseID` → `tblCareCase.personUserID`), `tblCareAccessLog` (same), `tblExpenseClaimFiles`, `tblExpenseClaimApprovals`, `tblExpenseClaimPayments` (`claimID` → `tblExpenseClaims.userID`), `tblKidCheckins` (`childID` → `tblKidProfiles.parentUserID`), `tblVenueImportRows` (`batchID` → `tblVenueImportBatches.createdByID`), `tblVisitorContact` (`visitorID` → `tblVisitor.convertedUserID`). The reader builds `WHERE through IN (SELECT key FROM parent WHERE <parent about-link> = ?)`; the tier comes from the parent's link classification (so a kid check-in reached through an acted parent link is a summary line; an approval reached through the claimant's about link is a full row, with the approver's `userID` staying as an identity number — the same acceptance already made for `moderatorID`). One hop only.

**Rows about the person that carry no link at all.** One bespoke reader entry, hard-coded in the class beside the three label lookups: `tblAuditTrail WHERE tableName = 'tblUsers' AND recordID = ?` — "who changed your profile, when, from what to what" (the challenge's A5; columns PROVEN `full_schema.sql:1225-1237`). Audit rows about the person's OTHER records (their claims, their bookings) are two hops and are left for a later version; `summary.html` says so in one sentence.

**Changed since Stage 2.** The reach list grew from four to eight tables; the audit-trail bespoke entry is new.

### C. One list, not two

**Recommendation.** A single reader, `Portal\Core\PersonalDataCatalogue`, loaded by both `GdprEraser` and `DataDownload`. The 21 hand-written blocks in `data-export.php` are removed; the file becomes a page. The exceptions they encoded are catalogue keys (`exclude`, `reach`, `download`, `summaryExtra`, `fullWhen`). Names for identity numbers come from three hard-coded lookups (`tblSites.siteName`, `tblEvents.eventName`, `tblSmallGroups.groupName` — all PROVEN columns) written into `data.json.labels` after the tables are done, never per-row JOINs.

**What the checks enforce** (the full list is in Step 1 and Step 3): every entry has `links` or `reach` or `unsearchable`; every named column exists in `full_schema.sql`; every link column on the table (by name or by foreign key) is classified; every classification is `about` or `acted`; every `LINK_COLUMNS` name has a phrase; every `retain` has `period`; `decision` is the first key; `data-export.php` contains no `tbl` identifier; both consumers name the reader and neither `require`s the catalogue file directly; the hand-written eraser entries' `userCol` set equals the `links` keys per table (F1); the golden tiers file matches; the pure query builder produces valid, fully-bound SQL for every entry; the schema validates the fixtures.

**Changed since Stage 2.** The F1 check and the golden file are new; `summaryExtra` and `fullWhen` keys are new.

### D. The tables with no account link

**Recommendation.** Named in `data.json.unsearchable[]` with the catalogue's sentence; the self-test refuses an entry with no `links`, no `reach` and no `unsearchable`. The kinds from Stage 2 stand (genuinely anonymous; reachable one hop; mis-sorted; named person with no account). `tblAttendanceCounts` is re-sorted `not-personal` (its only "personal" column is an INT session number — PROVEN survey §5). `tblEmailLog` and `tblCountEnvelopes` gain catalogue entries. `tblEmailLog` gets the one text search the design keeps — a count of sends to the person's current address — with the match written as `CONCAT(',', REPLACE(toRecipients, ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')` and `%`, `_`, `\` escaped in the bound value (the challenge's I; `_` is a LIKE wildcard).

**Changed since Stage 2 (D10).** For `tblCareCase`, `tblCareVisit` and `tblCareAccessLog` the listed line is one fixed sentence whether or not any row exists: "Pastoral and safeguarding records, if any are held about you, are kept under the safeguarding policy and are not searched automatically. To ask about them, contact …". The true count is written to `tblAuditTrail` for the administrator only. `tblDbsChecks` and `tblGivingStatementLog` keep real counts — the person already knows those exist.

### E. Personal data in free text

**Recommendation.** Not searched by name in the self-service download; `data.json.notSearched` and `summary.html` say so and say how to ask. The three reasons stand (over-match discloses others; under-match gives false comfort; a person is genuinely needed). The one kept text search is the exact-address count on `tblEmailLog` (D).

**The two named columns.** `tblAssetLoans.counterpartyName`/`counterpartyContact` are handed over on rows matched on `counterpartyUserID` (an about row) and cleared on erasure for those rows (`clears`). The phrase "by construction" is dropped: `AssetRegister.php:4015-4016,4070` stores the contact whatever the counterparty type (PROVEN, Stage 2), so a stray name typed for somebody else can sit there; the risk is small and the row is still the borrower's. `tblKidProfiles.pickupAuthorisedNames` is a comma-separated, case-insensitive list (PROVEN this stage, `kids/checkout.php:59-63`); on erasure the erased person's exact `fullName` is removed from it on rows matched on `parentUserID`, every other name stays, and the erasure audit says it cannot find an adult who is in the list but is not the linked parent.

### F. Format

**Recommendation.** One ZIP, `your-data-{date}.zip`:

```
README.txt                 three lines: what this is, when it was made, where to ask
data.json                  the manifest: what is here, what is not, and why (small)
tables/tblXxx.json         one JSON array per table, written one row at a time
summary.html               readable, self-contained, no scripts, no outside resources, no <table>
files/expenses/{id}-{name} the person's own expense receipts and claim PDFs, stored uncompressed
```

JSON per table for moving data elsewhere; HTML for reading; no PDF and no CSV in v1 (reasons unchanged from Stage 2). Uploaded files: only `tblExpenseClaimFiles` (reached through the person's own claim), and `tblUsers.displayPhoto` / `tblGiftAidDeclaration.signaturePath` if ever populated (both dormant — no code writes them, PROVEN survey §7). Every other file table is the organisation's material and appears as a summary line naming the file and date. Receipts are added with `ZipArchive::CM_STORE` (already-compressed images and PDFs) and capped at `dataDownload.maxFilesMb` (50); above the cap the remaining files are listed by name with "ask for a copy" and `completeness.status = "partial"`.

**Folder fallback** when `class_exists('ZipArchive')` is false (the `statements-download.php` shape, PROVEN survey §9): `summary.html` and a single `data.json` with the table arrays embedded (streamed from the per-table files, one row at a time), no `files/`, and one sentence on the page saying so.

**The schema** is in Part 4.5.

### G. Size and time on shared hosting

**Recommendation.** Prepared in chunks of `dataDownload.secondsPerChunk` (20) seconds, each resumable from a cursor AND a byte offset, with page size by column type, lock released on every normal return, five resumable finishing stages, per-row encoding with substitution, no row cap, and every limit written into `completeness.notes`. Details in Part 4.3. Today's silent `LIMIT 5000` on activity logs goes (PROVEN `data-export.php:121`).

**Ticked by** the person's own page (auto-refresh while running) and by a token-gated `cron/data-downloads.php` (ten precedents, PROVEN); either alone is enough.

**Changed since Stage 2.** All of D1–D8.

### H. Safety

**Recommendation.** Unchanged in substance: identifiers from the intersection of the catalogue and `information_schema.COLUMNS` (bound `TABLE_NAME`), back-quoted, `^[A-Za-z0-9_]+$` re-checked; only two kinds of bound value (the person's id, the cursor); types string built from the same list that built the SQL, with `strlen() === count()` asserted; no `siteID` filter, every row keeps its `siteID`, `data.json.organisations[]` lists every site the person belongs or belonged to (`tblUserSites.isActive` PROVEN); the only user id ever bound is the session's, or on the administrator path the job row's; the file route checks `job.userID === session userID` before anything else. A missing table is "not installed here", a missing column is "could not read", a failed `prepare()` is logged to `tblErrors` and named in the file — never an empty list.

**Administrator path (D1).** Portal-wide administrators only (`App::isRootAdmin()`, PROVEN `App.php:410`), behind `dataDownload.adminCanRequest` (seeded off), with a typed reason, audited via `Logger::audit()` naming the administrator, shown on the person's own account page. The file goes only to the person's registered address as a token link that still needs their sign-in. **Said plainly, as the challenge asked:** an administrator who can set the member's password (PROVEN `admin/users/save.php:246`) could sign in as them and collect the file. Two guards make that slow and visible rather than impossible: `tblLocalAccounts.passwordSetByAdminAt` (new, migration 194) is written by that page, and the file route refuses any collection for 24 hours after it; and every fetch sends a "your download was collected from address X at time Y" email to the registered address.

### I. Proving it is really them

**Recommendation — the proof at request time**, in this order:

1. **Local password** where `tblLocalAccounts` has a row (`password_verify()`, the `change-password.php:82` check — PROVEN Stage 2), **AND a two-factor code when `tblUsers.totpEnabled = 1`** (`Totp::verify()`, PROVEN). Both, not either: six digits defeat a phished password.
2. **Otherwise a two-factor code alone** where TOTP is enabled but there is no local password.
3. **Otherwise — people who only ever signed in with Microsoft or Google (PROVEN: SSO never creates a `tblLocalAccounts` row, Stage 2) and have no TOTP:**
   - **Recommended (D9): a fresh sign-in through the provider.** `Auth::startReproof()` sends the person to the same provider with `prompt=login` (Google today sends `select_account`; Microsoft sends none — both PROVEN this stage; both providers honour `prompt=login` — INFERRED from their documentation, not tested here). The callback, right after it has verified the ID token and read `sub`, checks `$_SESSION['reproof']`; if set, it compares `sub` with the session user's linked account (`findUserByLink()`, PROVEN) and, on a match, sets `$_SESSION['data_download_proof_at']` and returns to the page — it never rewrites `user_id`. Instant, needs no mail, and cannot be redirected by changing the email address.
   - **Fallback: an emailed confirmation link**, the erasure-request pattern (PROVEN), **refused when the registered address changed in the last 7 days** (`tblUsers.emailChangedAt`, new), with the page saying why. The confirm address shows a POST button (C4).

Every failed password or code goes into the same per-username lockout the sign-in page uses, by writing the identical `LoginFailed` activity row (PROVEN `Auth.php:864`) — INFERRED that `countRecentFailuresByUsername()` reads those rows; the builder confirms by reading it — and `RateLimiter::isUserOrIpBlocked()` is checked before verifying. A per-address bucket `data-download:{ip}` (5 per hour) and the per-person rules (no new job while one is open; none within `dataDownload.minHoursBetween` of the last ready one) sit on top.

**The proof at collection time.** The account page never shows the token. The file route accepts EITHER `?t={token}` from the email (hashed, `hash_equals`) OR a session proof made in the last fifteen minutes (`data_download_proof_at`); otherwise it shows the proof form again. For jobs whose only possible proof was the email link, the page says "use the link in your email". So a hijacked session that has not passed a proof gets nothing on any path.

**Audit and notice.** `Logger::activity($type, $desc, $userId)` WITH the id (today's row has `userID` NULL — PROVEN `data-export.php:249` against `Logger.php:48`) for requested / confirmed / ready / fetched / expired / failed / cancelled; `Logger::audit('tblDataDownloadJobs', $jobId, …, $userId)` for requested, ready and fetched, with the address from `RateLimiter::clientIp()` (C6). Emails to the registered address: "ready" (with the token, unless the address changed in the last 7 days — then without, and the page path with a fresh proof is the only way), "collected" on every fetch, and — from `account/save.php` and `admin/users/save.php` — "your email address was changed" to the OLD address.

**Encrypt at rest (D4).** Not in v1, reasons unchanged.

### J. Instant, or prepared

**Prepared.** Unchanged from Stage 2: the first chunk runs in the request that queued the job (unless email confirmation or the provider round-trip is needed), most members see the file inside twenty seconds, and the page shows progress with an auto-refresh while `running`. `/account/data-export` becomes a page at the same address, so the five existing links stay valid (PROVEN Stage 2).

### K. The #492 follow-ups

| # | Settlement | Changed since Stage 2 |
| --- | --- | --- |
| 1 | Every classified link column is matched in the eraser as well as the download: `fromPersonalDataCatalogue()` emits one instruction per link column (`erase` tables: `delete` on each `about` column, `anonymise` on each `acted` column; `unlink` tables: `anonymise` on each); `inventory()` counts per column. | The F1 self-test check makes hand-written tables keep up. |
| 2 | `clears` on `tblAssetLoans.counterpartyUserID` → `counterpartyName`, `counterpartyContact`; a bespoke `erasePickupNames()` removes the exact name from the comma list on `parentUserID` rows. | Wording (no "by construction"); the eraser's `tblEmailLog` `REPLACE` gets the same escaped, comma-bounded match. |
| 3 | `tblEventRegistrations.submitterIsGuardian TINYINT(1) DEFAULT NULL` (migration 194), a checkbox shown only when signed in. Erasure: a bespoke `eraseGuardianRegistrations()` deletes only where `submitterIsGuardian = 1` **and the event has ended** (later of start and end, the retention clear-out's own rule — PROVEN survey/#479 comment 5); every other row has `submittedByUserID` anonymised and stays, with the reason in the audit. Download: guardian → full row; otherwise summary line. NULL is never guardian. | The "event has ended" condition (F4). |
| 4 | `tblExpenseClaims` → `decision => 'retain'`, `period => '6 years (HMRC)'`, `download => 'full'` (D2). The hand-written eraser entry changes to `action => 'retain'` (F2) — today it attempts an anonymise the database refuses and logs "partial" (PROVEN `GdprEraser.php:61`, `848-861`), so the rows are kept either way and only the audit wording changes. `tblCareCase` stays `unlink` on erasure and `listed` (fixed sentence) on download (D8, D10). | F2. |

---

## Part 4 — The rules the code enforces

### 4.1 The catalogue entry, final shape

```php
'tblVisitor' => [
    'decision' => 'erase',      // MUST remain the first key: the Python check finds an entry by this (PROVEN)
    'reason'   => '…plain words…',
    'columns'  => ['fullName', 'email', 'phone', 'assignedToID', 'notes', 'convertedUserID', 'createdByID'],
    'links'    => [             // REQUIRED unless 'reach' or 'unsearchable' is present
        'convertedUserID' => 'about',   // the visitor themselves, once they have an account
        'assignedToID'    => 'acted',   // the worker asked to follow up
        'createdByID'     => 'acted',   // whoever typed it in
    ],
    'label'        => 'Visitors',                 // optional; default derived from the table name
    'download'     => 'full',                     // optional: full | summary | listed; default: retain→listed, any about→full, else summary
    'exclude'      => ['notes'],                  // optional: never handed over on this table, whatever the tier
    'summaryExtra' => ['source'],                 // optional: non-personal columns allowed on a summary line (reviewed; in the golden file)
    'fullWhen'     => ['submitterIsGuardian' => 1], // optional: full row only when this column has this value; otherwise summary
    'reach'        => ['through' => 'visitorID', 'table' => 'tblVisitor', 'key' => 'visitorID'], // optional: one hop
    'clears'       => ['counterpartyUserID' => ['counterpartyName', 'counterpartyContact']],        // optional: erasure only
    'unsearchable' => 'Stored under the name typed in, not an account. …', // required when no links and no reach
    'listedText'   => 'Pastoral and safeguarding records, if any …',        // optional: listed tier, fixed sentence, no count
    'period'       => '6 years (HMRC)',           // retain only
],
```

`columns` stays as the reviewer's aid it is today. Every column named anywhere in an entry must exist on the table in `full_schema.sql` (this catches the five wrong names the survey found, §1a).

### 4.2 The credential rule, final

**Leave a column out** when its name matches `hash|secret|token|password|passwd|key|salt|cipher|signature|nonce|credential|otp|session` (case-insensitive, anywhere) AND its `information_schema.COLUMNS.DATA_TYPE` is a string or binary type (`char`, `varchar`, `tinytext`, `text`, `mediumtext`, `longtext`, `tinyblob`, `blob`, `mediumblob`, `longblob`, `binary`, `varbinary`, `json`). Keep it when the type is a whole number, decimal, date, time, datetime, timestamp, year, enum or set. So `tblAttendanceSessions.sessionDate` (DATE) stays and every real secret the survey listed (all text) goes. MariaDB reports a JSON column as `longtext`; MySQL as `json`; both are in the string list.

**Always out, whatever the name** (`ALWAYS_EXCLUDE`, keyed table.column): `tblPushSubscriptions.endpoint`, `tblKidCheckins.badgeCode`, `tblLinkedAccounts.providerSub`, `tblZoomMeeting.passcode`, `tblUserSmsPreference.verificationCode`, `tblErrors.errorDetail`, `tblErrors.requestHeaders`, `tblErrors.requestURL`.

**JSON and snapshot columns are not handed over whole.** For `tblActivityLogs.sessionDataSnapshot` and `tblActivityLogs.requestHeaders` (D5), the value is decoded and rebuilt from an **allow-list**:

- Session keys kept: `user_id`, `user_name`, `user_email`, `active_site_id`, `login_redirect`, `flash_msg`, `flash_type`, `2fa_passed`. Every other key — including `totp_setup_secret`, `totp_backup_codes`, `api_key_minted`, `webhook_secret_minted`, `kiosk_new_token`, `webauthn_challenge`, `2fa_user_id`, `install_*` (all PROVEN to exist by the challenge) and any key added in future — is replaced by `"[left out: not on the list of session values that are safe to hand over]"`, so the person can see the key existed without seeing its value.
- Header names kept: `Host`, `User-Agent`, `Accept`, `Accept-Language`, `Accept-Encoding`, `Referer` (with everything from `?` onwards removed — E3), `Origin`, `Sec-Fetch-*`, `DNT`. Everything else is replaced by the same kind of placeholder. `Cookie`, `Authorization` and the two token headers are already `[redacted]` at storage (PROVEN `Logger.php:514-522`); the allow-list does not rely on that.
- `tblFormResponses.answersJson` and `tblAuditTrail.changeSet`/`oldValue`/`newValue` are handed over whole on about-rows (they are the person's own answers / their own record's history); the credential name pattern is applied to their keys recursively as a second net.

The allow-lists are constants in the reader class, and the self-test feeds a snapshot containing every known-bad key and asserts none survives, plus one unknown key and asserts it is replaced.

**Every column left out for any reason is named** in `data.json.tables[].columnsLeftOut[]` with a one-sentence reason. `tblUsers.calendarToken` — in every download produced today (PROVEN survey §5) — is the first entry most people will see.

### 4.3 The job: states, stages, chunks, crash safety

**States.** `awaiting_confirmation` (email path only) → `queued` → `running` → `ready` → `expired`; or `failed`; or `cancelled` (by erasure, or by an administrator). `running` with `lockedAt IS NULL` means "waiting for the next tick".

**Stages** (`stage` column, each resumable): `tables` → `files` → `summary` → `package` → `notify` → done (`status = 'ready'`).

**Claim.** `UPDATE tblDataDownloadJobs SET lockedAt = NOW() WHERE jobID = ? AND status IN ('queued','running') AND (lockedAt IS NULL OR lockedAt < NOW() - INTERVAL 5 MINUTE)` gated on `affected_rows === 1`. Then `status = 'running'` if it was `queued`.

**Plan snapshot (D7).** At queue time the ordered list of tables to walk and the SHA-256 of `personal-data-catalogue.php` are written into `progress` (JSON). Chunks walk that list, not the live catalogue; the hash goes into `data.json.catalogueHash`.

**The tables stage.** For the current table from (`cursorTable`, `cursorKey`, `cursorOffset`):

1. Open `tables/tblXxx.json` for read-write; `ftruncate()` it to `cursorOffset` (0 on first touch, which writes `[`). Anything written after the last persisted cursor is discarded and will be rewritten (D1).
2. Page size: 500 rows when the table has no TEXT/BLOB/JSON column, 50 when it has (D3), from the memoised `information_schema` read.
3. `SELECT <allowed columns> FROM tbl WHERE (link1 = ? OR link2 = ? …) AND pk > ? ORDER BY pk LIMIT n` (or the `reach` form). `get_result()`, `fetch_assoc()` in a loop; classify; scrub; encode with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`; if `json_encode()` returns false, write nothing for that row and add "row {id} of {label} could not be encoded" to the notes (D4); `fwrite` `,\n` + row.
4. After the page: `fflush()`; one UPDATE persisting `cursorKey`, `cursorOffset = ftell()`, the running count in `progress`. If fewer than n rows came back the table is done: write `]`, persist, move `cursorTable` to the next in the snapshot, `cursorKey = 0`, `cursorOffset = 0`.
5. After each page, if the budget is spent: persist and `lockedAt = NULL` in the same UPDATE (D2), return `['status' => 'running', 'stage' => 'tables', 'done' => k, 'of' => total]`.

A table absent from the database → note "not installed here", skip. A catalogue column absent from the table → note "expected a column … and did not find it; this table was skipped", skip. `prepare()` false → `Logger::errorPlatform()`, note, skip. All three are written to `completeness.notes[]`; `completeness.status` is `partial` whenever the list is non-empty.

**The files stage.** Walk `tblExpenseClaimFiles` for the person's claims (through `reach`), one file per iteration: `realpath()` must lie under `PORTAL_ROOT/_uploads/expenses/` (the survey's PROVEN folder) or `_uploads/pdfs/`; the entry name is `files/expenses/{fileID}-{basename}`; a file missing on disk is noted; stop adding once `dataDownload.maxFilesMb` is reached and list the rest by name. Progress is the last `fileID` copied.

**The summary stage.** `summary.html` is written by streaming each `tables/*.json` back through the renderer one row at a time (a hand-written incremental JSON array reader over `,\n`-separated rows — the writer's own format, so it need not be a general parser), never `json_decode()` on a whole file. Escaped everything, no `<table>`, `portal-data-list`-style markup, inline CSS only. Cursor: the table index.

**The package stage.** Build the ZIP (`CM_STORE` for `files/`, default compression for the rest) into `{workDir}/your-data-{date}.zip.part`, then rename to the final path under `PORTAL_ROOT/_uploads/data-downloads/{userID}/`. A killed package stage leaves a `.part` that the next claim deletes and rebuilds. Folder fallback when no `ZipArchive`: `format = 'folder'`. Then `readyAt = NOW()`, `expiresAt = readyAt + INTERVAL ? DAY` (in SQL), `fileSize`, remove the working folder's `tables/` copies only after the ZIP is complete.

**The notify stage.** Send the "ready" email; on `Mailer::send()` returning false, `lastError = 'ready email could not be sent'`, still `ready` (the page path with a fresh proof still works), and the page shows the error line.

**Disk.** At request time: refuse with a plain message when `SUM(fileSize)` of `ready` jobs exceeds `dataDownload.maxTotalMb` × 1,048,576.

**Retention and pruning.** `pruneExpired()` — `WHERE status = 'ready' AND expiresAt < NOW()` — unlinks the file and folder, sets `expired`, logs it. Called by the cron tick and opportunistically by the account page (the `tblEmailLog` prune pattern).

**Time.** `@set_time_limit($budget + 30)` — advisory on FastCGI (PROVEN survey §9); the chunk does not depend on it.

### 4.4 Proof and safety flows

**Request** (`POST account/data-export/request`): `requireLogin`; CSRF; `dataDownload.enabled` read from the portal-wide row only; `RateLimiter::isUserOrIpBlocked($email)` and `tooMany('data-download:' . $ip, 5, 3600)`; disk cap; verify the proof (Part 3 I) — on failure `Logger::activity('LoginFailed', 'Failed login attempt for: ' . $email, $userId)` and `recordHit()`; then `INSERT … SELECT ? … WHERE NOT EXISTS (SELECT 1 FROM tblDataDownloadJobs WHERE userID = ? AND status IN ('awaiting_confirmation','queued','running'))` and not within `minHoursBetween` of the last `ready`, gated on `affected_rows === 1` (D8). Record `proofMethod`, `requestIP` (from `RateLimiter::clientIp()`), `requestedByID` NULL. Set `$_SESSION['data_download_proof_at'] = time()`. If the proof was `email`: status `awaiting_confirmation`, `confirmTokenHash`, send the link; if sending fails, `lastError` and the page says "we could not send the email — ask an administrator". Otherwise `queued` and run `advance()` once in the same request.

**Confirm** (`GET account/data-export/confirm?t=…` shows a page with one POST button; `POST` with CSRF does `UPDATE … SET status = 'queued', confirmedAt = NOW(), confirmTokenHash = NULL WHERE confirmTokenHash = ? AND status = 'awaiting_confirmation' AND requestedAt >= NOW() - INTERVAL 1 DAY AND userID = ?`, the session's user — a token cannot confirm somebody else's job).

**Continue** (`POST account/data-export/continue`): `requireLogin`; CSRF; the job must be the session user's; `advance($jobId, $budget)`; redirect back. The page auto-submits it every five seconds while `running` (a `<meta http-equiv="refresh">` on a page that posts is awkward — a small inline script that submits the form is the precedent, PROVEN by the pwa-install and push-subscribe files).

**Cron** (`GET cron/data-downloads?key=…`): the constant-time gate on `dataDownload.cronToken`, empty = 403 (PROVEN idiom, `cron/giving-statements.php:54-60`); advances every `queued`/`running` job with `lockedAt IS NULL` for one budget each; prunes; plain-text report.

**File** (`GET account/data-export/file?job=…[&t=…]`): `requireLogin`; job belongs to the session user; `status = 'ready'`; not expired; **refuse if `tblLocalAccounts.passwordSetByAdminAt >= NOW() - INTERVAL 1 DAY`** for this user (C5); then either `t` matches `downloadTokenHash` or `data_download_proof_at` is within 15 minutes (`proofMethod` in password/totp/sso) — otherwise show the proof form; serve with `Content-Disposition: attachment`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Content-Length` + `readfile()`; on the folder fallback add `Content-Security-Policy: sandbox`; `downloadCount++`, `lastDownloadedAt`; activity + audit; "collected from {ip} at {time}" email.

**Admin** (`POST admin/users/data-download`): `requireLogin`; `App::isRootAdmin()`; `dataDownload.adminCanRequest === 'true'`; CSRF; target user must exist and be active (or the page says "re-activate the account first — an inactive account cannot sign in to collect"); reason required; `proofMethod = 'admin'`, `requestedByID = admin`; audit naming the administrator; queued; the ready email goes to the person with the token, unless their address changed in the last 7 days (then no token; a note says the person must sign in and prove).

**Cancel on erasure** (`DataDownload::cancelForUser($userId, $why)`): every job for the user → `cancelled`, files and folders removed, activity + audit. Called from `GdprEraser::execute()` (a bespoke step before the catalogue walk), from `delete-confirm.php` (PROVEN it does not use the eraser), and from `erasure-confirm.php` at the moment a request becomes `pending_review` (so nothing can run to `ready` during the review window).

### 4.5 The format and its schema

**`data.json`** (the manifest — small):

| Property | What it holds |
| --- | --- |
| `format` | the constant `"webms-intra-data-download"` |
| `formatVersion` | `2` (today's file says `exportVersion: "1"`, PROVEN) |
| `catalogueHash` | SHA-256 of the catalogue file the job walked |
| `preparedAt`, `expiresAt` | when it was made and when it will be deleted |
| `preparedFor` | `userID`, `fullName`, `emailAddress` |
| `requestedBy` | `{"self": true}` or `{"administrator": true, "reason": "…", "at": "…"}` and `proofMethod` |
| `organisations[]` | `siteID`, `name`, `active` — every site from `tblUserSites` |
| `tables[]` | per table walked: `table`, `label`, `tier` (`full`/`summary`), `file`, `rows`, `relationships[]` (phrases that occurred), `columnsLeftOut[]` (`column`, `why`), `reason` (the catalogue's) |
| `listed[]` | `table`, `label`, and either `count` + `why` + `period` + `whoToAsk`, or `text` (fixed sentence) |
| `unsearchable[]` | `table`, `label`, `text` |
| `files[]` | `path`, `table`, `id`, `bytes`, `included` (false when over the cap or missing) |
| `notSearched` | `freeText` sentence, `howToAsk` |
| `completeness` | `status` (`complete`/`partial`), `notes[]` |
| `labels` | `sites`, `events`, `groups` — identity number → name |

**Table files**: a JSON array; each element is a `fullRow` (`_id`, `_relationships[]`, then one property per column handed over) or a `summaryRow` (`_id`, `_relationships[]`, `_summary: true`, `siteID`, timestamps, `summaryExtra` values).

**`web/_core/data-download.schema.json`**: JSON Schema draft 2020-12, `$id` `https://github.com/MWBMPartners/WebMS-Intra/schemas/data-download`, `$defs` for `manifest`, `organisation`, `tableSection`, `listedSection`, `unsearchableSection`, `fileEntry`, `columnLeftOut`, `completeness`, `labels`, `tableFile`, `fullRow`, `summaryRow`. Every property carries `description`; `$comment` carries maintainer notes (the no-`//`-in-JSON rule). `fullRow` allows additional properties with a description saying "one property per database column handed over; names are the column names". `formatVersion` is `const: 2`.

**Checked by two things that run.** `tools/data-download-selftest.php` builds a sample manifest and two table files with the real class (no database — the schema parse stands in for `information_schema`) and writes them to `tools/audit-checks/fixtures/data-download/`; it fails if the regenerated fixtures differ from the committed ones ("fixture out of date — commit the regenerated file"). `tools/audit-checks/check_json_schemas.py` finds every `*.schema.json` in `web/`, parses it, fails on any property without a `description`, and validates every fixture under `fixtures/<schema-name>/` against the matching `$defs` entry using Python's `jsonschema` (`Draft202012Validator`); if `jsonschema` cannot be imported it FAILS with the one-line install instruction. `pr-security.yml` gains `pip install jsonschema` and runs the PHP self-tests before the Python check.

---

## Part 5 — Build plan

**Standing rules for every step.** `declare(strict_types=1)`; full `if` notation; `$mysqli` on pages, never `$db`; no `.php` in any address; no `<table>`; `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on all output; file headers with path, description, package, author, copyright, version; plain-English comments that say why and what was rejected. Before each commit: `php -l` on every touched file; every script in `tools/audit-checks/`; every `tools/*-selftest.php`; `python3 tools/audit-checks/check_schema_seed_parity.py --strict` for anything touching `web/_sql/`; then a **Codex review** (`codex exec --skip-git-repo-check …`, driven from the main session, output to `.claude-work/reviews/`), with the outcome recorded in the commit message. Nothing here can be run against a database on this machine; the E2E harness in CI is the first place migration 194 runs. Keep `.claude/HANDOFF.md` current after every step.

**Order and why.** Steps 1–3 change no runtime behaviour a member can see (Step 1 changes erasure slightly, deliberately). Step 4 is the switch-over and must land as ONE commit because route seeds and handlers need each other (`check_route_targets.py` would otherwise report six missing targets). Steps 5–6 depend on migration 194. Step 7 is optional (D9). Step 8 closes out.

### Step 1 — The catalogue becomes complete and checkable

**Files.** `web/_core/personal-data-catalogue.php`; `web/_core/GdprEraser.php` (constants + hand-written entries only); `tools/audit-checks/check_personal_data_coverage.py`; `tools/gdpr-coverage-selftest.php`; new `tools/draft-personal-data-links.php`; `.github/workflows/pr-security.yml`.

**Work.**
1. `LINK_COLUMNS` + 23 names; `ACTOR_COLUMNS` + 21 (all but `personUserID`, `giverID`). The private `$linkColumns` inside `fromPersonalDataCatalogue()` is left alone here (Step 6 replaces it).
2. New `tools/draft-personal-data-links.php`: reads `full_schema.sql`, and for a named table (or all) prints a proposed `links` map using `LINK_COLUMNS`/`ACTOR_COLUMNS` and foreign keys to `tblUsers`, defaulting to `acted` any column whose schema COMMENT matches `approver|reviewer|actor|who |acting|moderat|logged-in` (G2). Committed because the next new table needs it too.
3. Add the 17 missing tables with a decision and reason each (survey §2f and §3): the 11 with a foreign key to `tblUsers` (`tblAssetResources`, `tblBankImports`, `tblCcliUsage`, `tblDocuments`, `tblEventBroadcasts`, `tblProjectUpdate`, `tblServicePlan`, `tblVenueAgreementFiles`, `tblVisitorContact`, `tblWorkflowActions`, `tblExpenseClaimPayments`) plus the six with personal data and no such key (`tblEmailLog`, `tblKidCheckins`, `tblCountEnvelopes`, `tblAssetFoundReports`, `tblEventMaterials`, `tblExpenseClaimFiles`). `tblExpenseClaimApprovals` is already present; `tblDataDownloadJobs` is added in Step 4 when it exists.
4. Add `links` to every entry (drafted by the helper, reviewed by hand — the review is the work); fix the five wrong column names (`tblRecording` → `uploadedByID`; `tblCareAccessLog` → `viewerID`; `tblAssetAudit`/`tblAssetScanLog` → `actorUserID`; `tblAssetValueHistory` → `recordedByID`); `reach` on the eight one-hop tables; `exclude` on `tblPrayerRequests` (`partnerNote`) and `tblEventRegistrations` (`notes`, `reviewedByID`, `reviewedAt`); `unsearchable` sentences; `download` where the default is wrong (`tblExpenseClaims` full, `tblCareCase` listed, `tblKidProfiles` summary); `listedText` on the three care tables (D10); `summaryExtra` on file tables (`originalFilename`/`fileName`/`name`/`title` as each table has them); `clears` on `tblAssetLoans`; `fullWhen` on `tblEventRegistrations` (the column arrives in Step 4 — the self-test checks column existence against `full_schema.sql`, so `fullWhen` is added in Step 5, not here). Re-sort `tblAttendanceCounts` to `not-personal`; `tblExpenseClaims` to `retain` (D2); fix the stale section headings and counts (survey §1a).
5. Hand-written eraser entries: `tblExpenseClaims` → `action => 'retain'` (F2); add the anonymise entries the F1 check will demand for second link columns on hand-written tables (for example `tblEventAttendance.markedByID`, `tblGivingEntry.recordedByID`, `tblPrayerRequests.moderatorID` and `assignedToUserID`, `tblCareCase` is already two, `tblAssetLoans.approvedByID`/`requestedByID`, `tblKidProfiles` none). Each is `anonymise` with an empty `nullCols` — the existing `IF(col = ?, NULL, col)` per-column logic does the right thing (PROVEN `GdprEraser.php:814-817`).
6. The Python check: read `LINK_COLUMNS` from `GdprEraser.php` by regex (the self-test already reads hand-written entries that way, PROVEN); treat `FOREIGN KEY (…) REFERENCES \`tblUsers\`` as a link; verify every column an entry names exists on the table; verify every link column on every catalogue table is in `links` (or the entry has `reach`/`unsearchable`); keep the "decision first" regex but ALSO report an entry whose `decision` is not first as a distinct error, so the failure mode is named rather than silent. **Prove it fires** by temporarily breaking one entry each way before committing (the memory lesson: a check that has never failed has never been proven to look).
7. The PHP self-test: add checks 3–6 and the F1 check; keep all thirteen existing.
8. `pr-security.yml`: a new hard-gate step running every `tools/*-selftest.php` and failing the job on a non-zero exit (today none runs — PROVEN).

**Acceptance criteria.**
- `php tools/gdpr-coverage-selftest.php` exits 0 with every existing check still listed as PASS and the new checks present.
- `python3 tools/audit-checks/check_personal_data_coverage.py` reports OK with **148** tables (131 + 17); the same script with any one `links` entry removed, or any one column misspelt, or `decision` moved off the first position, exits 1 with the table named (three runs, recorded in the commit message).
- Every table in `full_schema.sql` with a foreign key to `tblUsers` is in the catalogue (the check itself proves this).
- `grep -c "'download' =>" personal-data-catalogue.php` ≥ 3 and `grep -c "'listedText' =>"` = 3.
- The catalogue's own heading comments state the real counts.
- `pr-security.yml` shows the self-test step; a deliberately failing self-test in a scratch branch fails the job (proved once, noted in the commit).
- Codex review recorded.

*Leaves the portal working because* nothing at runtime reads the new keys yet, `fromPersonalDataCatalogue()` ignores keys it does not know, and the only behaviour change is that erasure now also unlinks second link columns on hand-written tables and records `tblExpenseClaims` as `retain` — both in the safe direction.

### Step 2 — The shared reader

**Files.** New `web/_core/PersonalDataCatalogue.php` (`Portal\Core\PersonalDataCatalogue`); `web/_core/GdprEraser.php` (`fromPersonalDataCatalogue()`, `catalogueSummary()`, `nullableColumns()` switch to it); `tools/gdpr-coverage-selftest.php` (checks that both consumers name the reader and neither `require`s the catalogue file directly).

**Class surface (all static).** `all(): array`; `entry(string $table): ?array`; `links(string $table): array`; `downloadTier(string $table): string`; `reach(string $table): ?array`; `excluded(string $table): array`; `summaryExtra(string $table): array`; `fullWhen(string $table): ?array`; `clears(string $table): array`; `label(string $table): string`; `phrase(string $linkColumn): string`; `orderedTables(): string[]`; `fileHash(): string`; `realColumns(\mysqli $db, string $table): ?array` (name → `type`, `nullable`, `isPrimary`; memoised; `null` when the table does not exist); `nullableColumns(\mysqli $db, string $table): array` (moved from the eraser, same body); `isCredential(string $table, string $column, string $dataType): bool`; `scrubJsonValue(string $table, string $column, string $json): string` (the allow-lists); constants `CREDENTIAL_NAME_PATTERN`, `STRING_TYPES`, `ALWAYS_EXCLUDE`, `SESSION_KEYS_ALLOWED`, `HEADER_NAMES_ALLOWED`, `PHRASES`. It validates every entry's shape on load and throws `\InvalidArgumentException` naming the table on the first fault — an eraser or download that starts on a malformed catalogue must not run.

**Acceptance criteria.**
- The eraser produces byte-identical instructions before and after (prove it: a throw-away script dumps `GdprEraser::catalogue()` as JSON before and after the change and `diff` is empty — put the two files in `.claude-work/`, not `/tmp`).
- The self-test has a new PASS line: "both the eraser and the download read the one reader".
- `php -l` clean; Codex review recorded.

### Step 3 — The download engine, the schema, the self-test, the golden file (no runtime callers yet)

**Files.** New `web/_core/DataDownload.php`; new `web/_core/data-download.schema.json`; new `tools/data-download-selftest.php`; new `tools/audit-checks/check_json_schemas.py`; new `tools/audit-checks/fixtures/data-download/{data.json, tables/tblExpenseClaims.json, tables/tblAttendanceSessions.json, tiers.json}`; `.github/workflows/pr-security.yml` (`pip install jsonschema`; run the new check).

**`Portal\Core\DataDownload` surface.** Public: `request(int $userId, string $proofMethod, ?int $requestedById, string $reason, string $ip): array`; `confirm(string $token, int $userId): ?int`; `advance(int $jobId, int $budgetSeconds): array`; `latestFor(int $userId): ?array`; `historyFor(int $userId, int $limit = 5): array`; `fileFor(int $jobId, int $userId, ?string $token, bool $freshProof): ?array`; `cancelForUser(int $userId, string $why): int`; `pruneExpired(): int`; `setting(string $key, string $default): string` (portal-wide row only). Pure and public for the self-test: `buildTableQuery(string $table, array $links, ?array $reach, array $realColumns, string $tier, ?array $fullWhen, int $afterKey, int $limit): array{sql, types, values, selected}`; `classifyRow(array $row, array $links, ?array $fullWhen, int $userId): array{tier, relationships}`; `encodeRow(array $row): ?string`; `summaryLine(array $row, string $table, array $links, array $extra): array`; `pageSizeFor(array $realColumns): int`; `escapeLike(string $value): string`. Private: the stage runners, the incremental array reader, the HTML renderer (takes a writer callback), the ZIP/folder finisher, the three label lookups, the `tblAuditTrail` and `tblEmailLog` bespoke readers, the emails.

**The self-test** (on the `tools/report-builder-selftest.php` model — `define('PORTAL_CORE', …)`, `require` the classes, no bootstrap, PROVEN): parses `full_schema.sql` into a fake `realColumns` map (both `json` and `longtext` spellings for JSON columns), then for EVERY catalogue entry: builds the query and asserts every identifier is in the schema, `'?'` count equals `count(values)` equals `strlen(types)`, no credential column is selected, `listed` produces `COUNT(*)` only, `summary` selects only the key, the links, `…At` columns and `summaryExtra`; feeds `classifyRow` an about-match, an acted-match, a both-match, a `fullWhen` NULL and a `fullWhen` 1; feeds `scrubJsonValue` a snapshot with every known-bad key and one unknown key and asserts none survives; feeds `encodeRow` a row with an invalid byte; simulates a crash (write a page, do not persist, re-open with `ftruncate` to the persisted offset, write again, assert no duplicate `_id` in the file); asserts the F1 sets; regenerates `tiers.json` (per table: tier, about/acted split, full-tier free-text columns handed over) and the two sample table files and `data.json`, and fails if any differs from the committed copy (G1).

**Acceptance criteria.**
- `php tools/data-download-selftest.php` exits 0; the golden file lists every catalogue table.
- `python3 tools/audit-checks/check_json_schemas.py` exits 0; with `jsonschema` uninstalled it exits 1 with the install line; with one `description` removed from the schema it exits 1 naming the property (three runs recorded).
- Changing `tblKidProfiles` to `'download' => 'full'` in a scratch copy makes the self-test fail with a diff on `tiers.json` (proved once, recorded).
- `php -l` clean; Codex review recorded.

*Leaves the portal working because* nothing calls the class.

### Step 4 — Migration 194, the pages, the cron, the switch-over, and erasure's awareness of prepared files (one commit)

**Files.** New `web/_sql/194_data_download_jobs.sql`; `web/_sql/full_schema.sql`; `web/_apps/auth/account/data-export.php` (rewritten as the page, zero SQL); new `web/_apps/auth/account/data-export-request.php`, `data-export-confirm.php`, `data-export-continue.php`, `data-export-file.php`, `_data-export-proof.php` (a helper taking `$mysqli` as a parameter — the `announcements/_workflow-gate.php` shape); new `web/_apps/admin/users/data-download.php` + a button on the admin user form; new `web/_apps/cron/data-downloads.php`; `web/_apps/auth/account/index.php` (button text → "Prepare a copy of my data"); `web/_apps/auth/account/save.php` and `web/_apps/admin/users/save.php` (set `emailChangedAt` when the address changes and email the OLD address; `admin/users/save.php` also sets `passwordSetByAdminAt`); `web/_apps/account/erasure-confirm.php` and `web/_apps/auth/account/delete-confirm.php` (call `DataDownload::cancelForUser()`); `web/_core/GdprEraser.php` (bespoke `cancelPreparedDownloads()` step before the catalogue walk); `web/_core/personal-data-catalogue.php` (`tblDataDownloadJobs` → `erase`, reason naming the bespoke step; `links: userID => about, requestedByID => acted`); new `web/_apps/help/data-download.php` + a card in `help/index.php`; `web/_apps/privacy/index.php` wording.

**Migration 194 — `194_data_download_jobs.sql`** (header in the 193 style: what, why, what replay does, a note for the next migration; every column with a plain-English COMMENT):

1. `CREATE TABLE IF NOT EXISTS tblDataDownloadJobs` — `jobID INT AUTO_INCREMENT PK`; `userID INT NOT NULL` (FK `tblUsers` ON DELETE CASCADE — a belt; the eraser step is the braces, because the user row is never deleted, PROVEN); `requestedByID INT NULL` (FK ON DELETE SET NULL); `reason VARCHAR(500) NULL`; `status ENUM('awaiting_confirmation','queued','running','ready','failed','expired','cancelled') NOT NULL DEFAULT 'queued'`; `proofMethod ENUM('password','totp','sso','email','admin') NOT NULL`; `stage ENUM('tables','files','summary','package','notify','done') NOT NULL DEFAULT 'tables'`; `confirmTokenHash CHAR(64) NULL`; `downloadTokenHash CHAR(64) NULL`; `cursorTable VARCHAR(64) NULL`; `cursorKey BIGINT NOT NULL DEFAULT 0`; `cursorOffset BIGINT NOT NULL DEFAULT 0`; `progress JSON NULL`; `catalogueHash CHAR(64) NULL`; `workDir VARCHAR(500) NULL`; `filePath VARCHAR(500) NULL`; `fileSize BIGINT NULL`; `format ENUM('zip','folder') NULL`; `lockedAt DATETIME NULL`; `requestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`; `confirmedAt`, `readyAt`, `expiresAt`, `notifiedAt`, `lastDownloadedAt DATETIME NULL`; `downloadCount INT NOT NULL DEFAULT 0`; `lastError VARCHAR(500) NULL`; `requestIP VARCHAR(45) NULL`; keys `(userID, status)`, `(status, lockedAt)`, `(expiresAt)`; InnoDB utf8mb4.
2. Guarded `ALTER TABLE tblEventRegistrations ADD COLUMN submitterIsGuardian TINYINT(1) DEFAULT NULL COMMENT '…' AFTER submittedByUserID` (the 191 idiom, PROVEN).
3. Guarded `ALTER TABLE tblUsers ADD COLUMN emailChangedAt DATETIME DEFAULT NULL COMMENT '…' AFTER emailAddress`.
4. Guarded `ALTER TABLE tblLocalAccounts ADD COLUMN passwordSetByAdminAt DATETIME DEFAULT NULL COMMENT '…'` after `passwordHash` (the builder reads that table's column order first).
5. Settings, all `siteID NULL`, `ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)`: `dataDownload.enabled 'true'`; `dataDownload.retentionDays '7'`; `dataDownload.minHoursBetween '24'`; `dataDownload.secondsPerChunk '20'`; `dataDownload.includeFiles 'true'`; `dataDownload.maxFilesMb '50'`; `dataDownload.maxTotalMb '500'`; `dataDownload.adminCanRequest 'false'`; `dataDownload.retainedContact ''`; `dataDownload.emailChangeCooldownDays '7'`; `dataDownload.cronToken ''` (`isSensitive 1`). The header says these are read from the portal-wide row only and why (the 193 wording).
6. Routes, `ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)`: `account/data-export` (1, unchanged target), `account/data-export/request` (1), `account/data-export/confirm` (1), `account/data-export/continue` (1), `account/data-export/file` (1), `admin/users/data-download` (1), `cron/data-downloads` (0, like the ten existing cron rows — PROVEN). (The `account/data-export/reprove` route belongs to Step 7.)
7. Self-record `('194_data_download_jobs.sql') ON DUPLICATE KEY UPDATE filename = filename`.

**`full_schema.sql` folds**: the table block after `tblErasureAudit` (line ~4626, the GDPR section); the three columns inline in their tables in the same positions with the same comments; the settings and routes beside the erasure seeds; `('194_data_download_jobs.sql')` in the `tblMigrations` seed block.

**Acceptance criteria.**
- `check_schema_seed_parity.py --strict`, `check_migration_idempotency.py`, `check_mariadb_only_ddl.py`, `check_settings_keys.py`, `check_route_targets.py`, `check_webroot_shadowing.py`, `check_no_php_in_urls.py`, `check_sql_columns.py`, `check_php_table_refs.py`, `check_bind_param_arity.py` all clean; the E2E harness green in CI (the first real run of the SQL).
- `grep -c "FROM tbl\|tbl[A-Z]" web/_apps/auth/account/data-export.php` = 0 (the self-test check 1 enforces it).
- A scratch-branch walk-through written into the commit message: request with password → job ready in one chunk → file served → activity row has `userID` set → audit row exists → "ready" and "collected" emails attempted. (Cannot be run here without a database; recorded as the acceptance test the first deployment must perform, and written into HANDOFF as unverified until then.)
- `Logger::activity('DataExport…', …, $userId)` — every call passes the id (grep).
- `cancelForUser()` is called from three places (grep: `GdprEraser.php`, `delete-confirm.php`, `erasure-confirm.php`).
- The help page exists and the help index links it; `/account` button text changed; `privacy/index.php` wording updated.
- Codex review recorded, with attention asked on: the claim UPDATE, the truncate-on-claim, the token compare, the `NOT EXISTS` insert, and the file route's five checks.

### Step 5 — Event registrations: the guardian flag

**Files.** `web/_apps/calendar/event-register.php` (checkbox, shown only when signed in), `web/_apps/calendar/event-register-save.php` (writes `submitterIsGuardian`), `web/_core/GdprEraser.php` (bespoke `eraseGuardianRegistrations()`: delete where guardian AND the event has ended, using the later of start and end; anonymise `submittedByUserID` otherwise, reason in the audit), `web/_core/personal-data-catalogue.php` (`fullWhen => ['submitterIsGuardian' => 1]`; reason text updated), `tools/gdpr-coverage-selftest.php` (`$deliberateDeletes` reason updated; assert the bespoke function exists and the entry mentions it).

**Acceptance criteria.** Self-tests pass; the golden `tiers.json` diff shows `tblEventRegistrations` moving to "full when guardian"; `check_sql_columns.py` accepts the new DELETE (it reads deletes now — PROVEN #479 comment 5); Codex review recorded.

### Step 6 — The eraser matches every link column (#492 items 1, 2 and the email-log fix)

**Files.** `web/_core/GdprEraser.php` (`fromPersonalDataCatalogue()` emits per link column from `PersonalDataCatalogue::links()`; `inventory()` counts per column and reports which relationship; new `erasePickupNames()`; `clears` handling; `eraseEmailLogRecipients()` uses the escaped, comma-bounded match — today's `LIKE '%' . $email . '%'` and `REPLACE()` damage other addresses, PROVEN `GdprEraser.php:1014-1017`); `tools/gdpr-coverage-selftest.php` (the dangerous-delete check runs against the per-column instructions; `tblKidProfiles.parentUserID` and `tblExpenseClaimApprovals.userID` must remain anonymise-only); `web/_apps/account/my-data.php` (shows relationships per table).

**Acceptance criteria.** The self-test's "list and code agree" and "never deletes for an actor" both PASS on the per-column output; a scratch script dumps `catalogue()` before and after and the diff contains ONLY added instructions (never a changed action on an existing one) — recorded; Codex review recorded. Last of the code steps because it changes what erasure does.

### Step 7 — SSO re-proof through the provider (only if D9 is yes)

**Files.** `web/_core/Auth.php` (`startReproof(string $provider, string $returnTo)`; `prompt=login` in both starts when re-proving; an early branch in `callbackMS365()` and `callbackGoogle()` immediately after the ID token is verified: if `$_SESSION['reproof']` is set and `findUserByLink($provider, $sub)` equals `$_SESSION['user_id']`, set `data_download_proof_at`, clear `reproof`, redirect to `returnTo`; on mismatch clear and redirect with an error; never touch `user_id`); new `web/_apps/auth/account/data-export-reprove.php`; route `account/data-export/reprove` (1) in a small **migration 195** (or in 194 if D9 is answered before Step 4 lands); `_data-export-proof.php` offers the provider button for accounts with no password and no TOTP.

**Acceptance criteria.** `php -l`; a reproof branch that is reached with no `reproof` key in the session is a no-op (self-test by including the callback logic in a small pure function `Auth::reproofOutcome(?array $reproof, ?int $sessionUserId, ?int $linkedUserId): string` — the only part testable without a provider); **cannot be tested end to end on this machine** — the first deployment must try both providers and the handoff must say so; Codex review with the sign-in regression risk named.

### Step 8 — Documents, issues, memory

`CHANGELOG.md`; `FEATURES.md`; `DEV_NOTES.md` (a "Data download" section: the tiers, the catalogue keys, the chunk/offset mechanics, the job states, the credential rule and allow-lists, what is not searched, the schema and its checks); `README.md` if it lists account features; `.claude/CLAUDE.md` counts (migrations 189→190 files; tables 209→210; routes; settings) and a short "Data download" trap paragraph (decision-first regex; identifiers from two lists); `.claude/HANDOFF.md`; memory (a note on the decision-first regex and on `tblErrors` traces). Comment on #479 with what shipped and what remains (two-hop audit rows; PDF/CSV; at-rest encryption); comment on #492 closing items 1–4 with the commits. File the separate issues in Part 6.

---

## Part 6 — Issues to file separately (this design depends on them, or found them)

1. **`Logger::activity()` stores raw credentials from the session** (`totp_setup_secret`, `totp_backup_codes`, `api_key_minted`, `webhook_secret_minted`, `kiosk_new_token`, `webauthn_challenge` — PROVEN by the challenge); strip them at the source, and log `TotpEnabled` before writing the codes into the session (`2fa/setup.php:104-107`, PROVEN). Erasure's "unlink" leaves those secrets in the database forever until this is fixed. **HIGH.**
2. **Stack traces in `tblErrors.errorDetail` include argument values** (PROVEN by running PHP). Redact bracketed argument lists before storing, or set `zend.exception_ignore_args = On` if DreamHost honours `.user.ini` (unknown). **HIGH.**
3. **`Referer` is not redacted** by `redactSensitiveHeaders()` and carries tokens after the reset, confirm, `/f/{token}` and `/os/{token}` pages (PROVEN `Logger.php:516`). Strip the query string at the source.
4. **`account/save.php` changes the email address without the current password** (PROVEN). Ask for it, as `2fa/disable.php` does; SSO-only accounts have no password, so for them the "changed" notice to the old address (built in Step 4) is the only control.
5. **`erasure-confirm.php` confirms on a GET** (PROVEN `:15-23`) — mail filters can confirm an erasure request. Make it a POST button, as this design does for downloads.
6. **`Logger::clientIp()` still believes any forwarded header** while `RateLimiter::clientIp()` no longer does (both PROVEN). Part of #496; this design passes the rate limiter's answer explicitly rather than waiting.
7. **`Router.php:83` compares `isProtected` as a string** (INFERRED never true, survey §6). Every page calls `requireLogin()` itself, so nothing here depends on it.
8. **`GdprEraser::inventory()` and `$fetchUserRows` swallow failures** (PROVEN) — the inventory half is fixed in Step 6; the download half is replaced in Step 4.
9. **Off-site backup scope**: whether `_backups/sync-offsite.sh` (server-managed, not in the repository) copies `_uploads/` is unknown; if it does, `_uploads/data-downloads/` must be excluded. Ask the operator.

---

## OWNER DECISIONS

Recommendation first, then the alternatives and what each costs. Everything else in this document is settled by the code and the standing rules.

**D1. May an administrator queue a download for somebody who asks in writing?**
- **Recommended: yes, narrowly.** Portal-wide administrators only, a typed reason, audit-trailed, seeded off, the file only ever sent to the person's registered address as a link that still needs their sign-in. *Said plainly:* an administrator who can set the person's password could sign in as them and collect it; the design makes that slow (24-hour refusal after an administrative password reset) and visible (a "collected from" email on every fetch, and every step logged), not impossible. *Cost:* one admin page, one setting, two new columns' writes; a deactivated account must be re-activated to collect.
- Alternative: no — written requests are handled by hand. *Cost:* nothing to build; "by hand" for 148 tables means somebody running queries.
- Alternative: an administrator may download it with a second administrator's approval. *Cost:* a two-person step that does not exist; a member's complete file in an administrator's hands.

**D2. Does an expense claim keep the claimant's identity after erasure?**
- **Recommended: yes** — `retain`, six years (HMRC), handed over in full on request. The link points at a tombstone that names nobody (PROVEN). Today the catalogue claims "the claimant's name is removed" while the database refuses it (PROVEN). *Cost:* one catalogue entry and one hand-written entry; the audit wording changes from "partial" to "retained".
- Alternative: make `tblExpenseClaims.userID` nullable and unlink. *Cost:* a schema change on a financial table; a reimbursement that points at nobody; every claim list and report must cope with NULL.

**D3. What does a parent see of a child's Kids profile in their own download?**
- **Recommended: a summary line per child** — that a profile is linked to them as parent, when it was created, who to ask for a copy. The self-service download cannot know the account holder is the child's legal guardian (`parentUserID` can be set by whoever creates the profile). *Cost:* a parent who is the guardian asks a person. Consistent with settled decision 6.
- Alternative: the full profile, including allergies, medical notes and the collector list. *Cost:* the collector list names other adults; a link set by a leader is treated as proof of guardianship.

**D4. Encrypt the prepared file at rest?**
- **Recommended: not in v1.** Outside the web root, a hashed token plus sign-in plus a fresh proof, seven-day life, everything else in `_uploads` is plain. *Cost:* none.
- Alternative: encrypt with the site key. *Cost:* streaming encryption of tens of megabytes on shared hosting, a decrypt on every fetch, and inconsistency with every other file in `_uploads` unless that is done too.

**D5. Hand over the activity log's session snapshot and request headers?**
- **Recommended: yes, through the allow-lists** in Part 4.2 — only the known-safe session keys and header names; everything else replaced by a placeholder that names the key. #479's second comment asked for this widening; the challenge proved the stored snapshot contains raw two-factor backup codes, so the whole value must never be handed over. *Cost:* larger activity-log files; a short list to maintain when a new safe session key is added (an unknown key is left out, never leaked).
- Alternative: leave both columns out entirely and say so. *Cost:* the widening #479 asked for does not happen; simpler code.

**D6. Seven days' retention, one job per 24 hours, 50 MB of receipts, 500 MB total on disk?**
- **Recommended: 7 / 24 / 50 / 500**, all settings. *Cost:* none; a person who misses the window asks again; a member with more than 50 MB of receipts gets them listed by name and asks.
- Alternatives: 72 hours (safer, more re-requests); 30 days (a complete file on disk for a month); no receipt cap (a single job could fill the hosting quota).

**D7. Search free text for the person's name?**
- **Recommended: no.** Say so, say how to ask. *Cost:* a note that mentions the person only by name is found when they ask.
- Alternative: search `notes`/`body`/`description` for the exact full name and email. *Cost:* other people's rows disclosed on a common name; false assurance on a variant — the harm question A exists to prevent.

**D8. `tblCareCase` on erasure: `unlink` (as the code does) or `retain` (as the catalogue's heading implies)?**
- **Recommended: leave `unlink`** — the case and its visit notes stay for the remaining team, the subject's identity is detached. *Cost:* none; today's documented behaviour.
- Alternative: `retain`. *Cost:* the case keeps pointing at the person after they asked to be forgotten; the safeguarding policy would have to justify it.

**D9. For members with no password and no two-factor code, prove identity by a fresh sign-in through Microsoft/Google (touching the sign-in callbacks), or by an emailed link only?**
- **Recommended: the provider round-trip, with the emailed link as the fallback.** It is instant, needs no mail, and cannot be redirected by changing the email address — which a hijacked session can do today with no password (PROVEN). *Cost:* an early branch in each of the two sign-in callbacks and a `prompt=login` parameter; **cannot be tested end to end without live Microsoft and Google accounts**, so the first deployment must try both and the change carries a real, if bounded, sign-in regression risk. Built last (Step 7) so everything else ships regardless.
- Alternative: emailed link only, with the seven-day refusal after an address change and the notice to the old address. *Cost:* no sign-in code touched; for an SSO-only member a hijacker who changes the address and waits seven days can still request the download — the old-address notice is then the only thing that catches it.

**D10. Should the download confirm that a pastoral-care record exists?**
- **Recommended: no — a fixed sentence for the three care tables**, identical whether or not any row exists, with the true count written to the audit trail for the administrator. This narrows settled decision 3 for two tables (and the access log) without reversing it: those records are still listed, still not handed over, still "ask a person". *Cost:* a member who does have a case is not told so by the file; the person they ask decides, which is what decision 3 intended.
- Alternative: a real count, as for DBS checks and giving statements. *Cost:* an automated line confirming existence to somebody who may be the subject of a third party's concern — the confirmation the exemption sometimes exists to withhold.

---

## What was not checked

- Nothing was run against a database. Every SQL claim is about the text of the schema and the code; the E2E harness in CI is where migration 194 first runs, and a first deployment must perform the Step 4 walk-through before the feature is called verified.
- The live server's `memory_limit`, `max_execution_time`, FastCGI kill threshold, `ZipArchive` availability and `.user.ini` support remain unknown; the design assumes the worst on each and degrades rather than fails.
- That Microsoft and Google honour `prompt=login` is taken from their documentation, not tested.
- That `RateLimiter::countRecentFailuresByUsername()` counts the `LoginFailed` activity rows the sign-in page writes — I read the callers, not that function.
- Whether the GitHub runner has `jsonschema` — assumed not; the plan installs it.
- Whether `_backups/sync-offsite.sh` copies `_uploads/` — server-managed, not in the repository.
- No Codex review was run on this document. The standing rule applies to each build step; this document is the plan those reviews will check the code against.

---

## OWNER ANSWERS — 13 September 2026

Recorded by the main session from the owner's answers. Where an answer below is given, it settles that decision.

- **D1 — an administrator starting a download for a written request: YES, NARROWLY** (the recommendation).
  Global administrators only, with a typed reason, fully logged, and switched off until turned on. The file only
  goes to the person's registered email, as a link that still needs their sign-in. A 24-hour refusal applies after
  an administrator resets the password, and a "collected from" email is sent on every fetch.
- **D9 — how members who sign in only with Microsoft or Google prove it is them: SIGN IN AGAIN THROUGH THE PROVIDER,
  with the emailed link as the fallback** (the recommendation). It cannot be fully tested without live Microsoft and
  Google accounts, so the first deployment must try both.
- **D3 — a child's Kids profile in a parent's download: A SUMMARY LINE** (the recommendation). That a profile is
  linked to them, when it was created, and who to ask for a copy.
- **D2 — expense claims after erasure: KEEP THE CLAIMANT'S IDENTITY FOR SIX YEARS** (`retain`, HMRC), handed over
  in full on request (the recommendation).
- **D4 — encrypt the prepared file at rest: NOT IN THE FIRST VERSION** (the recommendation).
- **D5 — activity-log session data and request headers: YES, THROUGH THE ALLOW-LISTS ONLY** (Part 4.2); anything
  not on the list is replaced by a placeholder naming it (the recommendation).
- **D6 — limits: 7 days' retention, one job per 24 hours, 50 MB of receipts, 500 MB total on disk**, all as
  settings (the recommendation).
- **D7 — search free text for the person's name: NO**; the download says so and says how to ask (the
  recommendation).
- **D8 — `tblCareCase` on erasure: STAYS `unlink`** — the case stays for the care team, the identity is detached
  (the recommendation).
- **D10 — confirm whether a pastoral-care record exists: NO** — a fixed sentence for the three care tables whether
  or not rows exist, the true count to the audit trail (the recommendation). This narrows settled decision 3 for
  those tables only.

**All ten decisions are now answered. Nothing in this plan waits on the owner.** Each D2-D10 answer was given
individually as its own question, at the owner's request, not accepted as a block.
