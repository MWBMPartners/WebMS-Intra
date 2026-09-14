# #479 data download — Stage 3: CHALLENGE

Date: 13 September 2026. Branch: `claude/alpha-wip`. Challenge only — no repository file was edited.

Written to: `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/WebMS-Intra/.claude-work/reviews/design-479-3-challenge.md`

This stage tries to break the design in `design-479-2-design.md`, assuming a motivated attacker and ordinary human error. It builds on the survey (`design-479-1-survey.md`) and re-read the brief, the four files it names, the schema blocks the design leans on, both GitHub issues and the latest handoff sections.

**How to read the marks.** PROVEN means I read it in the code or ran it on this machine. INFERRED means I worked it out but could not run it — there is no database and no DreamHost server here. Every finding says how serious it is, what the evidence is, and the specific change that removes it.

**Seriousness scale.** HIGH = another person's data or a credential reaches the wrong hands, or a hijacked session gets the file. MEDIUM = the download is wrong, incomplete or misleading in a way nobody would notice. LOW = worth fixing, unlikely to hurt anyone.

---

## The short version

The shape of the design holds: one catalogue, one reader, per-table "about/acted" classification, summary lines for actor rows, resumable chunks, identifiers only from the catalogue ∩ `information_schema`, refuse rather than skip, listed retained tables, no free-text search. Nothing here needs a command line, and I found nothing that works on MySQL but not MariaDB.

But four things would break it as written, and each is a fact in the code the design did not see:

1. **The session snapshot the design recommends handing over (D5) contains raw two-factor backup codes, and can contain the two-factor secret, a freshly minted API key and a webhook signing secret.** The scrub the design relies on removes three keys; the portal writes at least fifty-three, and five of them are credentials. (HIGH — section E1)
2. **A person can change their registered email address with no password.** The design's emailed-confirmation path for Microsoft/Google users, and its "tell the owner" layer, both send to that address. A hijacked session changes it first. (HIGH — section C1)
3. **The download link's token is shown on the account page to any signed-in session**, so on the email path it adds nothing against a hijacked session — the very case it exists for. (HIGH — section C2)
4. **A chunk killed part-way through a page leaves rows in the file with no cursor record of them**; the next chunk writes them again. The file ends with duplicates, or with a half-written row that is not valid JSON, and `completeness.status` still says whatever it said. (MEDIUM — section D1)

Plus one that is not the design's fault but lands squarely in it: **PHP stack traces stored in `tblErrors.errorDetail` include function argument values** — I ran it, the password came out in the trace — and the design leaves `tblErrors.userID` to default to "about", which hands the full row over. (HIGH — section E2)

---

## A. Another person's personal data reaching the download

### A1. `tblEventRegistrations.notes` — internal moderation notes newly handed over — MEDIUM

**Evidence.** Today's block hand-picks columns and leaves out `r.notes` and `r.reviewedByID` (PROVEN, `data-export.php:196-205`). The column is `notes VARCHAR(500) COMMENT 'Internal moderation notes'` (PROVEN, `full_schema.sql:5660`). Under the design, a row matched on an "about" link — or on `submittedByUserID` with the guardian flag set — is "the full row, minus credential columns". Nothing in the design excludes `notes` on this table; only `tblPrayerRequests.partnerNote` gets an `exclude`.

**Why it matters.** A moderation note on a child's registration is exactly where a leader writes "mother says father must not collect" or "check with safeguarding lead". The person who typed the registration would receive it.

**The general shape.** Moving from hand-picked columns to full rows silently ADDS every column today's blocks deliberately left out. The design does not list, table by table, which columns newly appear. That is where ordinary human error will land.

**Change.** Add `'exclude' => ['notes', 'reviewedByID', 'reviewedAt']` to `tblEventRegistrations`. More importantly, the self-test's golden file (see G1) must list, for every table the download reads in full, the free-text columns that will be handed over, so a reviewer sees each one once and has to say yes.

### A2. `tblExpenseClaimApprovals` and `tblExpenseClaimPayments` are one hop from the claimant, and the design's `reach` list does not include them — LOW (a gap, not a leak)

**Evidence.** `tblExpenseClaimApprovals` carries `claimID`, `decision`, `comments TEXT 'Optional comments from the approver'`, `decidedAt` (PROVEN, `full_schema.sql:472-489`). The design's one-hop `reach` list is four tables: `tblCareVisit`, `tblExpenseClaimFiles`, `tblKidCheckins`, `tblVenueImportRows`. The approver's decision and comment on MY claim is about me, and a right-of-access request would expect it.

**Change.** Add `reach` entries for `tblExpenseClaimApprovals` and `tblExpenseClaimPayments` through `claimID`; the approver's `userID` stays "acted" for the approver's own download (the design already gets that right). Also `tblVisitorContact` (through `visitorID` → `tblVisitor.convertedUserID`).

### A3. The care-case count tells the subject a pastoral case exists — for the owner to confirm, not a re-opening

**Evidence.** Owner decision 3 says retained records are listed "that they exist, how many, why they are kept, for how long, and who to ask". `tblCareCase` has `personUserID` (the subject) and `status ENUM('active','resolved','long-term')` (PROVEN, `full_schema.sql:2648-2665`). The design lists it with a count.

**Why I raise it.** The owner's reason for listing rather than handing over was that these records "need a person to apply exemptions". The count itself is a disclosure no person has reviewed: an automated line saying "1 pastoral care case is held about you" confirms existence to somebody who may be the subject of a concern raised by a third party. In UK safeguarding practice that confirmation is sometimes the thing the exemption exists to withhold.

**Change (within the decision).** For `tblCareCase` and `tblCareVisit` only, make the listed line the same fixed sentence whether or not rows exist ("Pastoral and safeguarding records, if any are held about you, are kept under the safeguarding policy and are not searched automatically; ask …"), and record the true count only in the audit trail for the administrator. `tblDbsChecks` and `tblGivingStatementLog` can keep real counts — the person already knows those exist. This needs the owner's yes; it narrows decision 3 for two tables rather than reversing it.

### A4. `tblAssetLoans.counterpartyName/counterpartyContact` — "by construction the matched person's own details" is overstated — LOW

**Evidence.** The design's own fact (section 0) is that `AssetRegister.php:4015-4016,4070` stores `counterpartyContact` "whatever `counterpartyType` is". So a loan row with `counterpartyUserID` set can also carry a name and phone typed for somebody else (a spouse who will collect, a colleague). The design hands both over as part of the "about" row and, on erasure (K2), clears both.

**Change.** Keep the download behaviour (the row is about the borrower; a stray name is the organisation's data-entry habit, and the risk is small) but drop the phrase "by construction" from the design and the code comments. Overstated guarantees are the thing the standing rules warn against.

### A5. `tblAuditTrail` rows ABOUT the person are not found — MEDIUM (completeness)

**Evidence.** The design classifies `tblAuditTrail.userID` as "acted" (right — `oldValue`/`newValue`/`changeSet` are other people's records). But rows where an administrator changed the person's OWN record are matched by `tableName = 'tblUsers' AND recordID = <their id>` (PROVEN, columns at `full_schema.sql:1231-1237`), not by `userID`. Nothing in the design finds them. "Who changed my profile, when, from what to what" is plainly the person's data, and it is the one place the download could show them what an administrator did to their account.

**Change.** One bespoke reader entry, like the `tblEmailLog` count: `tblAuditTrail WHERE tableName = 'tblUsers' AND recordID = ?` → full rows (they are about the person; the `userID` on those rows is the administrator's identity number, which is acceptable, as the design already accepts for `moderatorID`).

---

## B. One organisation's data reaching another's member

I looked for this hard and found nothing that crosses organisations wrongly. The match is on the person's own `userID`; `siteID` on every row tells the reader which organisation holds it; `tblUserSites` including `isActive = 0` lists where they were (PROVEN the column exists, `full_schema.sql:236`). The `labels` lookups name sites and events whose identity numbers appear in rows about the person — a site the person left is one they were a member of. The admin path is root-admin only. **This part holds up.**

One thing to state plainly in the design: a root administrator's own download will contain summary lines for actions across every site, because they acted across every site. That is correct and harmless, but the file should say why the list is long.

---

## C. A hijacked session getting everything

### C1. The email address can be changed without a password, and both email layers send to it — HIGH

**Evidence.** `web/_apps/auth/account/save.php` requires login and a CSRF token, then `UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ? WHERE userID = ?` — there is no `password_verify` anywhere in the file (PROVEN by grep; lines 36, 38, 120). Compare `auth/2fa/disable.php`, which does re-prove the password and fails closed for SSO-only accounts (PROVEN, lines 53-80).

**The attack.** A hijacked session belonging to a Microsoft/Google-only member with no TOTP: change the email address to the attacker's, request the download, receive the confirmation link, click it, receive the "ready" link with the token, download. Layer 1 (fresh proof) is satisfied by the attacker's own mailbox; layer 5 (tell the owner) tells the attacker. For a local-account member the password stops it — unless the password was phished too, in which case TOTP would have helped and the design does not ask for it when a password exists.

**Changes.**
1. **Prefer a fresh sign-in through the provider over an emailed link for SSO users.** `Auth.php` already sends `prompt => 'select_account'` to the provider (PROVEN, line 616). A "prove it is you" round-trip that sends `prompt=login` (Microsoft and Google both honour it) and checks the returned identity matches the session is instant, needs no mail, and cannot be redirected by an email change. Keep the emailed link only as the fallback when the provider round-trip fails.
2. **When TOTP is enabled, require the code as well as the password**, not instead of. It costs the person six digits and defeats a phished password.
3. **Refuse the emailed path when the registered address changed in the last 7 days**, and say why on the page. This needs one fact the portal does not record today — when the email last changed — so add `emailChangedAt` to `tblUsers` in migration 194 (guarded ADD, both places) and set it in `account/save.php` and `admin/users/save.php`.
4. **Send a "your email address was changed" notice to the OLD address** from `account/save.php`. Standard practice; not this design's job to build, but this design depends on it, so file it as its own issue and say so in the build plan.
5. Separately, `account/save.php` should require the current password to change the email address, exactly as `2fa/disable.php` does. File it.

### C2. The download token is shown to the session, so the email path is only as strong as the session — HIGH

**Evidence.** Section J: the page shows "ready with the file link". Section I: the file route requires `job.userID === session userID` AND the token. If the account page renders the link with the token in it, a hijacked session gets the token from the page and satisfies both checks. On the password/TOTP paths that is fine — the session just proved itself. On the emailed path it means the email proved nothing, because the file was collectable without it.

**Change.** Record on the job HOW it was proven (`proofMethod ENUM('password','totp','sso','email','admin')`). For `email` and `admin` jobs, the account page says "the link is in your email" and never renders the token; the token travels only in the email. For `password`/`totp`/`sso` jobs, render the link but require the session to have re-proven within the last 15 minutes (store `dataDownloadProofAt` in the session at request time; the file route checks it and, if stale, shows the proof form again). Then a hijacked session that did not pass the proof step gets nothing, whichever path the job took.

### C3. The password re-proof form is an online password-guessing endpoint — MEDIUM

**Evidence.** The design rate-limits the request handler at 5 per hour per IP. The sign-in page uses `RateLimiter::isBlocked()` per IP AND `isUserOrIpBlocked($identifier)` per username (PROVEN, `Auth.php:833`, `RateLimiter.php:134`), precisely because a targeted attacker rotates addresses (the class's own comment, `RateLimiter.php:112-121`). The 2FA verify page records a per-user bucket too (PROVEN, `auth/2fa/verify.php:158-159`). A per-IP limit alone lets an attacker with many addresses try 5 passwords an hour from each.

**Change.** On a failed proof, `recordHit()` into the SAME per-username bucket the sign-in page reads, so failures here lock the account's sign-in as they would at the door, and check `isUserOrIpBlocked()` before verifying. Log each failure with `Logger::activity('DataDownloadProofFailed', …, $userId)`.

### C4. The confirmation link confirms on a GET — MEDIUM

**Evidence.** The precedent the design copies, `account/erasure-confirm.php`, reads `$_GET['token']` and runs the `UPDATE … WHERE confirmToken = ?` in the same request (PROVEN, lines 15-23). Corporate mail filters (Microsoft Safe Links, Mimecast and others) fetch links in incoming mail before the person sees them.

**Why it matters here.** On the emailed path a hijacker requests the download; the owner's mail filter fetches the link; the job is confirmed without anybody clicking. The owner still holds the ready-email token (C2 fixed), so the file is safe — but the "proof" step proved nothing, and every erasure request confirmed the same way today was confirmed by a robot.

**Change.** The confirm address shows a page with one POST button ("Yes, prepare my download") carrying the token in a hidden field and a CSRF token; the GET changes nothing. Same fix belongs on `erasure-confirm.php`; file it.

### C5. An administrator who can reset passwords can collect the file — MEDIUM (honesty of D1)

**Evidence.** `admin/users/save.php` sets `passwordHash` on an existing account (PROVEN, lines 235-246). So the design's claim that on the admin path "the administrator never receives the file" is not enforceable: reset the password, sign in as the member, collect. Every step is logged, which is the real control.

**Change.** Say so in D1 rather than claiming otherwise. Add two cheap guards: refuse collection of any job for 24 hours after an administrative password reset on that account (the audit trail already records the reset; the file route checks it), and send a "your download was collected from address X at time Y" email to the registered address on every fetch — so the person learns of a collection they did not make.

### C6. The IP recorded against the request and quoted in the emails is spoofable — LOW

**Evidence.** `Logger::clientIp()` returns `HTTP_CF_CONNECTING_IP` or the first `HTTP_X_FORWARDED_FOR` hop whenever either is present, with no check that the request came through a trusted proxy (PROVEN, `Logger.php:525-536`). `RateLimiter::clientIp()` honours `portal.trustedProxies` (PROVEN, `RateLimiter.php:467-660`). The design's audit rows go through `Logger`, and the "who asked, from where" email would quote them. Anyone can send a header.

**Change.** Take `requestIP` from `RateLimiter::clientIp()` explicitly and pass it into the job row and the emails; do not rely on what `Logger` records. (The uncommitted change to `Logger.php` in the working tree that I read did not touch `clientIp()`; whether migration 193's trusted-proxy work reaches it is INFERRED unknown.)

---

## D. The download timing out, running out of memory, or leaving things out without saying so

### D1. A killed chunk duplicates rows or leaves invalid JSON — MEDIUM

**Evidence.** Design section G step 3: rows are appended to `tables/tblXxx.json` as they are fetched; the cursor is persisted "after each 500-row page". FastCGI can kill a request at any moment (PROVEN (survey), DEV_NOTES.md:3945-3953). So: rows 1-300 of a page are on disk, the process dies, the cursor still says the page has not started, the next chunk (from the page path or the cron, after the 5-minute lock expires) writes rows 1-500. Result: rows 1-300 twice, or — if the kill landed inside `fwrite` — a truncated row that makes the whole file unparseable. `completeness` never learns.

**Change.** Store a byte offset with the cursor (`cursorOffset BIGINT`). On every claim, `ftruncate()` each open table file to the recorded offset before writing, so anything written after the last persisted cursor is discarded and rewritten. Persist cursor + offset together, in one UPDATE, only after the page's bytes are flushed (`fflush` + `fsync` is overkill on shared hosting; `fflush` is enough for this purpose). The self-test can prove it: write a page, "crash" by not persisting, re-run, assert no duplicate `_id`.

### D2. The lock is never released between chunks — MEDIUM (it makes every job slow, not wrong)

**Evidence.** The claim is `… AND (lockedAt IS NULL OR lockedAt < NOW() - INTERVAL 5 MINUTE)`. Step 4 says "persist the cursor and return". Nothing says `lockedAt = NULL` on return. As written, a chunk that finished normally at 12:00:20 cannot be claimed again until 12:05:20. A ten-chunk job takes fifty minutes with the tab open.

**Change.** Release the lock (`lockedAt = NULL`) in the same UPDATE that persists the cursor on a normal return; keep the 5-minute rule only for a chunk that died. Say so in the design.

### D3. Memory is not "one row at a time" for wide tables — MEDIUM

**Evidence.** `get_result()` on a prepared statement under mysqlnd buffers the whole result set in PHP memory (PHP documentation; INFERRED that DreamHost uses mysqlnd, which is the PHP default). `tblActivityLogs.requestHeaders` is `LONGTEXT` and `sessionDataSnapshot` is `TEXT`; `tblErrors.errorDetail` and `requestHeaders` are `LONGTEXT` (PROVEN, `full_schema.sql:1054-1059, 1086-1091`). 500 rows × a few kilobytes each is fine; 500 rows carrying a multi-megabyte backtrace or a large session snapshot is not, and `memory_limit` on the live server is unknown (PROVEN (survey)).

**Change.** Fetch with `bind_result()`/`fetch()` (which streams from the server) instead of `get_result()`, or make the page size a per-table figure: 500 for tables with no TEXT/BLOB columns, 50 where `information_schema` shows one. The reader already knows the column types.

### D4. A row that is not valid UTF-8 cannot be encoded, and today that silently produces an empty download — MEDIUM (existing fault; the design must not inherit it)

**Evidence.** Today's file does `echo json_encode($payload, JSON_PRETTY_PRINT | …)` with no `JSON_INVALID_UTF8_SUBSTITUTE` and no check of the return value (PROVEN, `data-export.php:256`). One byte of invalid UTF-8 anywhere in any row — a legacy import, a pasted Word character in a note — makes `json_encode` return `false`, and `echo false` sends an empty body with a 200 status and a `.json` filename. The person gets a zero-byte file and no message.

**Change.** Encode per row with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE`, check the return, and on `false` write the row's `_id` into `completeness.notes` ("row 412 of Expense claims could not be encoded") rather than dropping it. The self-test should feed the encoder a row with an invalid byte.

### D5. The final step cannot be chunked, and it is the one that can be big — LOW-MEDIUM

**Evidence.** Design step 5 builds the ZIP and copies `files/` in one go; `ZipArchive::close()` writes the whole archive at that moment. Expense receipts are the only files included; the design itself estimates "tens of megabytes" for a member with a hundred claims. Compressing already-compressed images and PDFs inside a 20-second budget on shared hosting is INFERRED to be tight.

**Change.** Add receipt files with `ZipArchive::CM_STORE` (no compression — they are already compressed), compress only the JSON and HTML, and cap the receipt total at a setting (`dataDownload.maxFilesMb`, seeded 50) above which the files are listed by name with "ask for a copy" and `completeness.status = 'partial'`. Also `summary.html` must be written by streaming the per-table files back through the renderer, never by loading them into memory — the design says "written after every table is done" but not how.

### D6. Chunk-by-chunk activity logging pollutes the very table it is exporting — LOW

**Evidence.** Design I4 logs "each chunk". A 2,000-chunk job writes 2,000 `tblActivityLogs` rows, each with headers and a session snapshot (PROVEN what `Logger::activity` writes, `Logger.php:52-58`), all of which are then "about" rows in the person's next download.

**Change.** Log requested / confirmed / ready / each fetch / expired / failed. Keep chunk progress in the job row's `progress` JSON only.

### D7. A catalogue change mid-job changes the plan under the cursor — LOW

**Evidence.** The job walks "the catalogue's own order" and stores `cursorTable`. A deploy between chunks that reorders or adds tables makes the next chunk skip or repeat tables silently.

**Change.** Snapshot the ordered table list (and the catalogue file's hash) into `progress` when the job is queued, and walk that list. Write the hash into `data.json` so a file says which catalogue produced it.

### D8. Two rapid requests make two jobs — LOW

**Evidence.** The per-person rule "no new job while one is queued/running" is a read-then-insert unless the design says otherwise. Two POSTs in the same second both pass the read.

**Change.** `INSERT INTO tblDataDownloadJobs (…) SELECT … WHERE NOT EXISTS (SELECT 1 FROM tblDataDownloadJobs WHERE userID = ? AND status IN ('awaiting_confirmation','queued','running'))` gated on `affected_rows === 1`. Standard MySQL and MariaDB.

---

## E. A credential slipping through the column rule

### E1. The session snapshot holds raw credentials the scrub does not know about — HIGH

**Evidence — what the scrub removes.** `Logger::activity()` copies `$_SESSION`, unsets `csrf_token`, `oauth_state`, `oauth_nonce`, and stores the rest as JSON in `sessionDataSnapshot` (PROVEN, `Logger.php:56-58`).

**Evidence — what the portal puts in the session.** A grep of every `$_SESSION['…'] =` under `web/` finds fifty-three distinct keys (PROVEN, this stage). Among them:

| Key | What it holds | Where written |
| --- | --- | --- |
| `totp_setup_secret` | the raw base32 TOTP secret shown as a QR code, kept in the session until setup completes, and re-used if setup is abandoned and resumed (the generate step is `if (isset(...) === false)`) | `auth/2fa/setup.php:116` |
| `totp_backup_codes` | the PLAINTEXT backup codes, written at line 104; `Logger::activity('TotpEnabled', …)` runs at line 107, AFTER the write, so that log row's snapshot contains them | `auth/2fa/setup.php:104-107` |
| `api_key_minted` | `['plaintext' => <the raw API key>, …]` | `admin/integrations/api-keys-save.php:96` |
| `webhook_secret_minted` | `['plaintext' => <the raw signing secret>, …]` | `admin/integrations/webhooks/save.php:198` |
| `kiosk_new_token` | the raw kiosk token | `assets/kiosk-save.php:82` |
| `webauthn_challenge`, `2fa_user_id`, `2fa_passed` | authentication state | various |

All PROVEN. 223 files call `Logger::activity` (PROVEN by count), so any request between "open the 2FA setup page" and "confirm the code" that logs an activity — a save, a view, a sign-in elsewhere in the same session — stores the exact secret that is about to become the person's live TOTP secret. The `TotpEnabled` row itself stores the backup codes with certainty.

**Why it matters for THIS design.** D5 recommends handing `sessionDataSnapshot` over because it is "already scrubbed of credentials before storage (PROVEN `Logger.php:57`)". The scrub exists; the claim that it is sufficient is wrong. The design's own list of session keys (section 0) was taken from `Auth.php` alone and is incomplete. Under D5, a download contains the person's TOTP backup codes and quite possibly their TOTP secret — and section I offers TOTP as one of the proofs that the person is really them. A hijacked session that somehow gets one download (C1/C2) then has the second factor for every future one. It also means the erasure's "unlink" leaves those secrets in the database forever, which is outside this design but should be filed.

**Changes.**
1. **In the download**: apply the credential name rule recursively INSIDE JSON columns (`sessionDataSnapshot`, `requestHeaders`, `notifyPrefs`, `answersJson`, `changeSet`, `progress`) — any key matching the pattern, plus an explicit list (`totp_setup_secret`, `totp_backup_codes`, `api_key_minted`, `webhook_secret_minted`, `kiosk_new_token`, `webauthn_challenge`, `2fa_user_id`, `2fa_passed`, `install_db`, `install_db_server`), is replaced with `"[left out: a credential]"`. The self-test feeds a snapshot containing each of those keys and asserts none survives. Only with that in place is D5's "yes" safe.
2. **At the source, as its own issue**: `Logger::activity()` should strip those keys before storing, and `2fa/setup.php` should log `TotpEnabled` BEFORE writing the backup codes into the session (or write them after logging). That fixes the database, not just the download.

### E2. Error rows contain PHP stack traces, and stack traces contain argument values — HIGH

**Evidence.** `Logger::exception()` stores `$ex->getTraceAsString()` in `errorDetail` (PROVEN, `Logger.php:212`). I ran PHP on this machine: a function called as `f('alice', 'hunter2secret')` that throws produces the trace line `#0 Command line code(1): f('alice', 'hunter2secret')` — the argument values are printed, up to `zend.exception_string_param_max_len` characters each, which is 15 here and by PHP default (PROVEN, both by running it). So an exception thrown anywhere beneath a sign-in, a password change, a Gift Aid save or a form submission can store the first fifteen characters of a password, another person's phone number, or whatever was passed. `requestURL` on the same row stores the full query string (PROVEN, `Logger.php:329` and column comment).

**Why it matters for THIS design.** `tblErrors.userID` is "UserID of logged-in user" (PROVEN, column comment). The design's default rule makes `userID` an "about" link, and the design does not classify `tblErrors` anywhere. So every error row the person was signed in for is handed over in full — `errorDetail`, `requestHeaders`, `requestURL` included. Those traces routinely contain data belonging to whichever record was being processed, which is often somebody else's.

**Changes.** Classify `tblErrors.userID` as **acted** (the person was merely signed in when the portal failed) so the download carries a summary line per error — when, which page, the title — and never `errorDetail`, `requestHeaders` or `requestURL`. Add all three to `ALWAYS_EXCLUDE`. Separately file: `Logger` should redact trace arguments (`zend.exception_ignore_args = On` in `.htaccess`/`.user.ini` if DreamHost honours it, or strip the bracketed argument lists before storing).

### E3. `Referer` is not redacted and carries tokens — LOW

**Evidence.** `redactSensitiveHeaders()` redacts exactly `authorization`, `cookie`, `x-csrf-token`, `x-api-key` (PROVEN, `Logger.php:516`). A browser sends `Referer` with the full previous address on same-site requests. The addresses that carry a secret in the query string are the password-reset link, the erasure-confirm link, the public form link `/f/{token}`, the public order-of-service `/os/{token}`, and this design's own confirm and file links. The POST that follows any of those pages logs a `Referer` containing the token.

**Change.** Redact `referer` when it contains `?`, at the source. In the download, `requestHeaders` should also pass through the recursive rule from E1 (`Referer` with a query string → left out).

### E4. The rule itself, checked against the schema — holds, with two names to add

PROVEN by scanning every column name for `code|pin|slug|uuid|badge|endpoint|providerSub|challenge|passcode|verificationCode`: the design's `ALWAYS_EXCLUDE` already names `endpoint`, `badgeCode` and `providerSub`. Two more should join it: `passcode VARCHAR(50)` and `verificationCode VARCHAR(10)` (both exist; which tables they sit on I did not trace — the reader should refuse them wherever they appear). `pinHash` and `codeHash` are caught by the pattern. Slugs are public addresses, not secrets. **The type-based correction (keep dates, drop strings) is right and is what stops `sessionDate` being lost.**

---

## F. The download and the erasure disagreeing about a table

### F1. The hand-written eraser entries still exist and still win — MEDIUM

**Evidence.** `GdprEraser::catalogue()` merges `$handWritten` (about sixty entries) with the catalogue-derived ones, and any table in `$handWritten` is skipped when the catalogue is read (PROVEN, `GdprEraser.php:300, 431-433`). The design moves the download entirely onto the catalogue's `links` map but leaves `$handWritten` in place (Step 7 changes `fromPersonalDataCatalogue()`, not the hand-written list). So for those sixty tables the download reads `links` and the eraser reads a hand-written `userCol`. Add a link to `links` — say `closedByID` on `tblCareCase` — and the download finds it while the eraser, which never consults `links` for a hand-written table, does not. The two lists are one list again in name only.

**Change.** Extend self-test check 9: for every hand-written table, the set of `userCol` values across its hand-written entries must equal the set of keys in the catalogue's `links` for that table. Then a link cannot be added to one side only. Longer term, the hand-written entries should become catalogue keys (`'erase' => ['personUserID' => 'anonymise', …]`) so there is genuinely one list — but that is a bigger change and the check above is enough to stop drift now.

### F2. `tblExpenseClaims` — the hand-written entry must change with the catalogue — LOW

**Evidence.** The hand-written entry is `action => 'anonymise'` (PROVEN, `GdprEraser.php:61`). The design changes the catalogue to `retain`. Self-test check 9 compares the two and will FAIL ("the list says retain but the code does anonymise") until the hand-written entry also says `retain`. The design says the check "will pass once the entries say what the code does", which is backwards here: the code has to change to say what the decision is.

**Change.** In Step 1 (or K4), change the hand-written `tblExpenseClaims` entry to `action => 'retain'` with the six-year reason, and delete the `'nothing could be emptied … see migration 192'` path for it. State it in the build plan.

### F3. The erasure does not know about the download's prepared files — MEDIUM-HIGH

**Evidence.** The design puts the ZIP under `PORTAL_ROOT/_uploads/data-downloads/{userID}/` for up to seven days, and gives `tblDataDownloadJobs.userID` a foreign key `ON DELETE CASCADE` "so a job must not outlive its subject". But the eraser never deletes the `tblUsers` row — it anonymises it in place (PROVEN, `GdprEraser.php:282-285`; #479's own second comment makes exactly this point about other cascades). So the cascade never fires, the job rows survive, and **a complete copy of everything the portal held about a person sits on disk for up to a week after they were erased**, downloadable by anyone who can satisfy the file route — which, after erasure, is nobody legitimate and one tombstone account.

**Change.** A bespoke eraser step, on the `eraseGivingStatementFiles()` model: cancel every job for the user, delete the working folder and the prepared file, mark the jobs `expired`, and audit it. The catalogue entry for `tblDataDownloadJobs` (which the coverage check will demand, because the table has `userID`) should be `erase` with that bespoke step named in its reason. Also cancel jobs when an erasure request is CONFIRMED, not only when it is processed, so a job cannot run to completion during the review window.

### F4. Guardian-flag erasure can un-register a child from a future event — LOW-MEDIUM

**Evidence.** K3: erasure does `DELETE … WHERE submittedByUserID = ? AND submitterIsGuardian = 1`. Nothing distinguishes a registration for last year's holiday club from one for next month's. A parent who closes their account in June deletes their child's July place, and the organiser learns when the child turns up.

**Change.** Delete only where the event has ended (`tblEvents.endDateTime < NOW()`, taking the later of start and end as the retention clear-out already does); for a future event, anonymise `submittedByUserID` and keep the row, and write the reason into the erasure audit ("kept: event not yet held; covered by the 90-day rule after it ends").

---

## G. A catalogue change silently changing what is exported

### G1. Flipping one word in the catalogue changes the download and nothing notices — MEDIUM

**Evidence.** The design's checks (section C, items 1-8) verify structure: every link classified, every column real, every phrase present. None of them notices that somebody changed `tblKidProfiles` from `'download' => 'summary'` to `'full'`, or `tblVisitor.assignedToID` from `acted` to `about`. Both are single-word edits that pass every check and hand over other people's data.

**Change.** A committed golden file, `tools/audit-checks/fixtures/data-download-tiers.json`: for every catalogue table, the tier, the about/acted split, and — for full-tier tables — the list of free-text columns that will be handed over (A1). The self-test regenerates it from the real catalogue and fails on any difference. A change to what the download contains then always appears as a diff to that file in the same pull request, where a reviewer sees it. This is the same idea as the report builder's self-test: make the dangerous edit visible, not merely valid. Also write the catalogue file's hash into `data.json` (D7).

### G2. The draft classification defaults `userID` to "about" — the one case the catalogue already documents as wrong — LOW

**Evidence.** The design's draft script uses "the name rule" as the default and relies on a human reviewer to catch `tblExpenseClaimApprovals.userID` (the approver — PROVEN, catalogue lines 224-239) and its kin. Ordinary human error is exactly that reviewer skimming 131 tables.

**Change.** The draft script should flag for review every link column whose schema `COMMENT` contains any of `approver|reviewer|actor|who |acting|moderat` and default THOSE to `acted`. The comment on `tblExpenseClaimApprovals.userID` says "the approver" (PROVEN, `full_schema.sql:475`); the comment on `tblErrors.userID` says "UserID of logged-in user" — worth adding `logged-in` to the list. Cheap, and it catches the two cases found so far without a person having to notice.

---

## H. MySQL-only, MariaDB-only, or command-line-only

**I found nothing that fails on either database.** PROVEN by reading the design's SQL against both dialects: `CREATE TABLE IF NOT EXISTS`, `ENUM`, `JSON` (MariaDB 10.2+ treats it as `LONGTEXT` with a validity check — the schema already uses `JSON` in nine columns, PROVEN by grep), `DATETIME DEFAULT CURRENT_TIMESTAMP`, `NOW() - INTERVAL 5 MINUTE`, `IF(col = ?, NULL, col)`, `INSERT … ON DUPLICATE KEY UPDATE … VALUES(…)`, `information_schema.COLUMNS` with `TABLE_SCHEMA = DATABASE()`, `IN (SELECT …)` without `LIMIT`, the guarded `ADD COLUMN` idiom. Two things to write down so nobody trips later:

- `information_schema.COLUMNS.DATA_TYPE` reports `json` on MySQL and `longtext` on MariaDB for a JSON column. The credential rule treats both as string types, so the outcome is the same; the self-test should include both spellings.
- Every catalogue table has a single-column integer primary key — I re-checked with two patterns and found no composite key and no text key anywhere in `full_schema.sql` (PROVEN). The `pk > ?` cursor is safe.

**Nothing needs a command line.** The self-tests and the Python schema check run only in CI (the design says so; `pr-security.yml` runs on GitHub's runner). On the server: `ZipArchive` has a fallback, the cron has a page-path alternative, `set_time_limit` is advisory and the chunks do not depend on it. `jsonschema` is a `pip install` on the runner (INFERRED available; the design already says to install it).

---

## I. Smaller things, quickly

- **`tblEmailLog` count uses `LIKE` with an unescaped address — over-counts, and the eraser's `REPLACE` it copies damages other people's addresses** (LOW, but the eraser half is a real bug). `_` is a `LIKE` wildcard and common in email addresses; `ann@x.org` is a substring of `joann@x.org`. The eraser does `REPLACE(toRecipients, ?, '[erased]') WHERE toRecipients LIKE ?` with `'%' . $email . '%'` and no escaping (PROVEN, `GdprEraser.php:1014-1017`), so erasing Ann rewrites Joann's address to `jo[erased]`. For the download count: match on `CONCAT(',', REPLACE(toRecipients, ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')` with `%`, `_` and `\` escaped in the value. File the eraser half as its own issue.
- **`Mailer::send()` returns `bool`** (PROVEN, `Mailer.php:75`). The design does not say what happens when the confirmation or ready email fails. It must: job stays `awaiting_confirmation` with `lastError`, and the page says "we could not send the email — ask an administrator", not silence.
- **`dataDownload.enabled` is read per site by `App::settingForSite()`** (PROVEN the helper exists, `App.php:171`). The download spans sites. Read the portal-wide row only, and say so in the setting's comment; otherwise one site's administrator can switch off a member's right of access for rows held by another site — or believe they have when they have not.
- **ZIP entry names and file reads from `storedFilename`/`originalFilename`.** Both are app-written (PROVEN, columns at `full_schema.sql:456-457`), but the finisher should still `realpath()`-check that the source lies under `_uploads/expenses/` and name the entry `files/expenses/{fileID}-{basename}` — a `../` in a name only hurts the person's own machine, but it costs one line to prevent.
- **Serve `summary.html` as an attachment with `Content-Security-Policy: sandbox`** on the folder-fallback route, so a `<script>` typed into somebody's rota note can never run on the portal's origin. The design says `attachment` (right); the header is belt and braces.
- **Disk.** Nothing bounds the total space prepared files may use: N members × one job a day × seven days × tens of megabytes on a shared-hosting quota. A portal-wide `dataDownload.maxTotalMb` (seeded 500) checked at request time, refusing with a plain message, is enough.
- **Timezones.** `expiresAt` compared with `NOW()` in SQL but computed from `time()` in PHP mixes two clocks. Compute expiry in SQL (`readyAt + INTERVAL ? DAY`) and compare in SQL.
- **The `tblCareAccessLog` rows for a care SUBJECT** — "who opened my file, when" — are about the subject and reachable one hop through `caseID`; the design gives them only to the viewer as summary lines. If A3 is adopted they stay hidden with the case; if not, add `reach`.
- **"Every classified link column produces an eraser instruction" (K1) needs `tblKidProfiles.parentUserID` and `tblExpenseClaimApprovals.userID` to remain anonymise-only** whatever the table-level decision says. The self-test's `$dangerous` check already covers erase-tables (PROVEN, lines 247-272); make sure it runs against the per-column instructions after Step 7, not the per-table decision.

---

## What holds up

Stated plainly, because a challenge that only lists faults misleads:

- **Driving the download from the catalogue through one reader** (C) is right, and removing every hand-written block from `data-export.php` with a regex check that they cannot return is the only version that survives the next five apps.
- **Per-(table, column) about/acted classification with a check that refuses an unclassified link** (A) is right; the three counter-examples the design gives are real (PROVEN) and prove a name rule cannot work.
- **Summary lines for actor rows** (A) are the correct answer to the brief's question A and reduce what six of today's blocks hand over. Nothing I found argues for handing more of an actor row over.
- **One query per table, `OR` across links, one row once** (B) is right and closes #492 item 1 in both routines.
- **Identifiers from the catalogue ∩ `information_schema`, values bound, "could not read" written into the file rather than an empty list** (H) is right, and is exactly the fault in today's `$fetchUserRows` and `inventory()` (PROVEN, `data-export.php:72-75`, `GdprEraser.php:679-681`).
- **Prepared in resumable chunks, one JSON file per table, no row cap** (F, G, J) is the correct response to a host that kills requests; the faults in D are in the mechanics, not the idea.
- **Not searching free text and saying so** (E) is the defensible position; the reasons given are correct.
- **A separate `download` key from `decision`** (K) is necessary — the `tblExpenseClaims`/`tblCareCase` pair proves one key cannot say both.
- **The credential rule with the type correction** (E4) is right; my scan of the schema found nothing it misclassifies except the two names to add.
- **NULL is never treated as guardian** (K3) is the safe direction.
- **Two database places, MySQL ∩ MariaDB SQL, nothing needing a command line** — all hold.
- **Wiring the six existing self-tests into CI** (Step 1 item 6) is overdue and right.

## What was not checked

- Nothing was run against a database; every SQL claim is about the text of the schema and the code.
- I did not trace which tables carry `passcode` and `verificationCode`, only that the columns exist.
- Whether `zend.exception_ignore_args` can be set on DreamHost shared hosting (E2's source fix) is INFERRED possible via `.user.ini`, not verified.
- Whether migration 193's trusted-proxy work reaches `Logger::clientIp()` — I read only the first part of the uncommitted diff.
- What `_backups/sync-offsite.sh` copies (it is server-managed and not in the repository) — so whether prepared download files would be swept into off-site backups is unknown. If it copies `_uploads`, exclude `_uploads/data-downloads/`.
- No Codex review was run on this document; the standing rule applies to the change, and this stage is itself the adversarial pass on the design.
