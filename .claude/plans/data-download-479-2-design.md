# #479 data download — Stage 2: DESIGN

Date: 13 September 2026. Branch: `claude/alpha-wip`. Design only — no repository file was edited.

Written to: `/Users/lance.manasse/Projects/Coding & Development/MWBM Partners Ltd/GitHub/WebMS-Intra/.claude-work/reviews/design-479-2-design.md`

Builds on Stage 1 (the survey, `design-479-1-survey.md`). Where this document repeats a survey figure it says "(survey)"; where it says PROVEN without that, I read or ran it myself in this stage.

**How to read the marks.** PROVEN means I read it in the code or ran it on this machine in this stage. PROVEN (survey) means the survey established it and I did not repeat the work. INFERRED means I worked it out but could not run it — there is no database and no DreamHost server on this machine.

---

## The design in twelve lines

1. **Drive the download from the catalogue, through one new reader class both the download and the eraser use.** Every hand-written query block in `data-export.php` goes; the file becomes a thin controller. The catalogue gains, per table, an explicit map of every column that links a row to a person and whether that column means the record is **about** the person or that they **acted** on it. A check fails when the map disagrees with the real database structure.
2. **Rows matched through an "about" column are handed over in full** (minus credentials). **Rows matched only through an "acted" column are reduced to a summary line** — which table, which record number, what they did, when — because the rest of that row is the organisation's material or somebody else's personal data.
3. **Every link column is matched, not just the first.** One query per table with `OR` across its link columns; each row carries the list of relationships it matched on, in plain words. The same change fixes #492 item 1 in the eraser.
4. **Records kept by law are listed, not handed over** — count, why, for how long, who to ask — exactly as the owner decided. Whether a table is "listed" is a download decision written in the catalogue, separate from the erasure decision, because an expense claim must be both kept for six years AND handed over in full.
5. **Tables with no link to an account are named in the file** with a sentence each saying why they cannot be searched by account and how to ask a person to search them. Free-text fields are not searched by name; the file says so and says what to do.
6. **Prepared, not instant.** The person asks; the portal builds the download in short chunks (about twenty seconds each) that resume from the last row written, across as many requests as it takes, so it cannot be killed by the hosting's request limit and never holds more than one row in memory. When it is ready the person is told by email and downloads it from their account page. Most members will see it finish in one chunk.
7. **One ZIP**: `data.json` (small: what is in the download, what was left out and why), one JSON file per table under `tables/`, a self-contained readable `summary.html`, and under `files/` only the uploaded files that are about the person (expense receipts). Falls back to two plain files when the server has no ZIP support, and says so.
8. **A JSON Schema with a description on every property**, checked by a new audit script and by a dependency-free self-test that runs the real query-building code. There is no `*.schema.json` in the repository today (PROVEN), so this is the first, and the check is written to fail rather than pass when it cannot validate.
9. **Table and column names never come from the request.** Every name used in SQL must appear in BOTH the catalogue and `information_schema`; a name in one but not the other is refused and written into the file as "could not read", never silently skipped.
10. **Only the person, proven twice.** The request needs a fresh proof — current password, or a two-factor code, or an emailed confirmation link for people who sign in with Microsoft or Google and have no password — plus a rate limit, a one-per-24-hours rule, an audit-trail entry that actually names the person, and a "your download is ready" email to the registered address so the real owner always learns one was made.
11. **An administrator may queue one for somebody who asks in writing, but never receives the file** — it goes to the person's registered email as a link that still needs their sign-in. Owner decision, recommended on.
12. **Two database changes, both in a migration and in `full_schema.sql`**: a job table, and a "I am this child's parent or guardian" flag on event registrations, which is what settles #492 item 3.

---

## 0. Facts established in this stage that the survey did not have

| Fact | Mark | Where |
| --- | --- | --- |
| Stored request headers are passed through `redactSensitiveHeaders()` before being written to `tblActivityLogs`, and its comment says bearer tokens, session cookies, CSRF tokens and API keys are removed. | PROVEN | `web/_core/Logger.php:487-509` |
| Every one of the 131 catalogue tables has a single-column primary key. | PROVEN (ran a script over `full_schema.sql` and the catalogue) | this stage |
| Signing in with Microsoft 365 or Google never creates a `tblLocalAccounts` row. The only inserts into that table are the admin user form, the users API, invitation acceptance and the installer. So a person who only ever used SSO has no password to re-enter. | PROVEN | `grep 'INSERT INTO tblLocalAccounts'`: `admin/users/save.php:136`, `users/api/create.php:118`, `invites/accept.php:138`, `_install/index.php:895`; `Auth.php:495-560` updates `tblUsers` only |
| `change-password.php` already handles that case: no local row means "No local account found" and a redirect. | PROVEN | `web/_apps/auth/account/change-password.php:76-79` |
| A TOTP verifier exists: `Totp::verify(string $base32Secret, string $code, ?int $timestamp = null): bool`. | PROVEN | `web/_core/Totp.php:67` |
| The session records no sign-in time. The keys `Auth.php` sets are `user_id`, `user_name`, `user_email`, `csrf_token`, `oauth_state`, `login_redirect`, `active_site_id` and the two flash keys. So "signed in recently" cannot be checked today without adding a key. | PROVEN | `grep "_SESSION\['...'\] ="` over `Auth.php` |
| The erasure-request flow is a working precedent for an emailed confirmation link: a 64-hex token from `random_bytes(32)`, sent by `Mailer::send()`, accepted once within one day, then cleared. | PROVEN | `web/_apps/account/erasure-request.php:52-74`, `web/_apps/account/erasure-confirm.php:23` |
| `Mailer::send(string|array $to, string $subj, string $body, array $files = [])` accepts file paths to attach. (The design deliberately never attaches the download.) | PROVEN | `web/_core/Mailer.php:75` |
| `Logger::audit(string $tableName, int $recordId, string $action, ?array $oldData, ?array $newData, ?int $userId, ?int $apiKeyId, ?string $source)` writes `tblAuditTrail` and takes the user explicitly. | PROVEN | `web/_core/Logger.php:111-119` |
| `RateLimiter::tooMany(string $bucket, int $maxHits, int $windowSeconds)`, `recordHit()`, `retryAfter()` exist and are used by the public form submit (5 per 15 minutes) and the 2FA page. | PROVEN | `web/_core/RateLimiter.php:348-424`; `forms/public-submit.php:140`; `auth/2fa/verify.php:72` |
| Route seeds use `INSERT INTO tblRoutes (routeKey, targetFile, isProtected) VALUES (...) ON DUPLICATE KEY UPDATE targetFile = VALUES(targetFile)`; settings seeds use `(siteID, settingKey, settingValue, defaultValue, isSensitive)` with `ON DUPLICATE KEY UPDATE defaultValue = VALUES(defaultValue)`; guarded column adds use the `information_schema` + `PREPARE` idiom with `AFTER` to keep column order identical to a fresh install. | PROVEN | `web/_sql/190_*.sql:88-91`, `188_*.sql:69-72`, `191_*.sql:98-108,151-154` |
| The next free migration number is **194** (`193_trusted_proxies_and_channel_gate.sql` is in the working tree, untracked). | PROVEN | `ls web/_sql/` and `git status` |
| The `my-data` inventory page is `web/_apps/account/my-data.php` at address `account/my-data` (not under `auth/account/` as the survey placed it); its `?download=1` emits `{generatedAt, userID, inventory}` from `GdprEraser::inventory()`. | PROVEN | `web/_apps/account/my-data.php:21-31`; `full_schema.sql:4629` |
| Five files link to `account/data-export`: `privacy/index.php`, `auth/account/index.php`, `auth/account/delete.php`, `help/small-groups.php`, and the page itself. Keeping that address avoids touching any of them. | PROVEN | `grep -rln 'account/data-export' web/_apps` |
| No table named like `tblDataExport*`, `tblExportRequest*` or `tblAccessRequest*` exists. A job table would be new. | PROVEN | `grep -c` over `full_schema.sql` = 0 |
| The giving-statements ZIP is built under `_uploads/giving/statements/{siteID}/` with `ZipArchive`, and the page falls back to per-row links when `class_exists('ZipArchive')` is false. | PROVEN | `web/_apps/giving/statements-download.php:105,157-166` |
| Nothing starts output buffering in `bootstrap.php`, `public_html/index.php`, `Router.php` or `Template.php`; `set_time_limit` is used in exactly four places (300 s ×3, 900 s ×1). | PROVEN (re-run of the survey's grep) | this stage |
| There is no `*.schema.json` anywhere in the repository. The only JSON files outside `.git` are `composer.json`, two editor settings files and `web/_core/api-spec.json`. | PROVEN | `find . -name '*.schema.json'` = nothing |
| `tools/gdpr-coverage-selftest.php` is not run by any workflow file. | PROVEN | `grep -n selftest .github/workflows/*.yml` = nothing |
| `tblKidProfiles.pickupAuthorisedNames` is read at check-out to verify the typed collector's name against the list. | PROVEN that it is read there (`kids/checkout.php:43-59`); INFERRED that it splits on commas — I did not read the comparison itself |
| `tblAssetLoans.counterpartyContact` is trimmed, cut to 255 and stored whatever `counterpartyType` is. | PROVEN | `web/_core/AssetRegister.php:4015-4016,4070` |
| `event-register-save.php` writes `submittedByUserID` (migration 191) but records nothing about the submitter's relationship to the child. | PROVEN (the INSERT column list at line 118; no guardian/relationship column exists on the table) | `web/_apps/calendar/event-register-save.php:118`; `full_schema.sql` `tblEventRegistrations` |

One correction to the survey: it said the eraser's `catalogue()` "hand-written entry wins" for `tblRecording`, `tblCareAccessLog`, `tblAssetAudit`, `tblAssetScanLog` and `tblAssetValueHistory`. Confirmed (PROVEN, `GdprEraser.php:67,177,193,201,219`). The consequence for THIS design is the important part: a download that reads the catalogue's `columns` list would query `tblRecording.createdByID`, which does not exist, and — under today's `$fetchUserRows` — get an empty list that looks like "no rows". Section H closes that.

---

## A. Other people's data in "your" download

### Recommendation

Classify every link column on every table as **about** or **acted**, per table, in the catalogue — and let that classification decide what is handed over:

| The row matched on | What goes in the download | Why |
| --- | --- | --- |
| At least one **about** column (`userID`, `donorID`, `submitterID`, `recipientUserID`, `targetUserID`, `convertedUserID`, `personUserID`, …) | The full row, minus credential columns (section "The credential rule" below) | The record is about them. That is what a right of access gives. |
| Only **acted** columns (`createdByID`, `updatedByID`, `recordedByID`, `approvedByID`, `markedByID`, `uploadedByID`, …) | A **summary line**: the table in plain words, the record's identity number, the relationship in plain words ("you approved this"), and the timestamps on the row (`createdAt`, `updatedAt`, and any `…At` column that pairs with the link, e.g. `approvedAt` with `approvedByID`). No content columns. | The only personal data ABOUT the actor in such a row is the fact that they did it and when. The rest is the organisation's material (a song, a booking) or a third party's (a visitor's phone number). |
| A **listed** table (owner decision 3) | Counts only, with the reason, the period and who to ask | Settled by the owner. |

**The classification must be per (table, column), not per column name.** Three tables prove a name-only rule is wrong, all PROVEN from the schema and the catalogue's own comments:

- `tblExpenseClaimApprovals.userID` means the APPROVER — the catalogue's own comment says so (`personal-data-catalogue.php:224-235`). Under a name rule "userID = about" it would hand an approver every claim they signed off, which is the claimant's financial record.
- `tblVisitor.assignedToID` and `tblSalvationCards.assignedToID` are the follow-up worker; the person the record is about has no link at all (`tblSalvationCards` has no user link besides `assignedToID`; `tblVisitor` also has `convertedUserID`, which IS about the visitor). Under a name rule "assignedToID = about" (it is in the about half of `LINK_COLUMNS`, `GdprEraser.php:359-360`) a volunteer's download would contain every decision card assigned to them: other people's names, phones, addresses and prayer requests. This is the survey's sharpest example (§10 item 9).
- `tblAuditTrail.userID` is who made the change; `oldValue`/`newValue`/`changeSet` are the before-and-after values of OTHER records, routinely about other people (survey §10 item 10). Acted, not about.
- Against those: `tblTasks.assignedToID` and `tblRotaSlot.assignedToID` ARE about the person — the duty is theirs. So the same column name is "about" on one table and "acted" on another.

So the catalogue entry gains a `links` map, for example:

```
'tblVisitor' => [
    'decision' => 'erase',
    'download' => 'full',          // see C
    'links'    => [
        'convertedUserID' => 'about',  // the visitor themselves, once they have an account
        'assignedToID'    => 'acted',  // the worker asked to follow up
        'createdByID'     => 'acted',  // whoever typed it in
    ],
    ...
```

The name rule (`LINK_COLUMNS` minus `ACTOR_COLUMNS` = about) becomes the DEFAULT the check proposes, and the catalogue's explicit map is what runs. The check (section C) fails when a real link column on the table has no classification, so nothing falls through to the default at runtime.

**Two "about" rows that still carry other people's data, and what to do with each:**

- `tblPrayerRequests.partnerNote` (written by a prayer partner about the request), `moderatorID`, `assignedToUserID`: hand over the request minus `partnerNote`. Add an optional `'exclude' => ['partnerNote']` key to the entry. `moderatorID` and `assignedToUserID` are identity numbers of staff, not names; they stay (they are already in today's `SELECT *`, PROVEN `data-export.php:128`).
- `tblKidProfiles` matched on `parentUserID`: the child's record, not the parent's. `parentUserID` is in `ACTOR_COLUMNS` already (PROVEN `GdprEraser.php:376`), so under this design the parent gets a summary line per child ("a child's profile is linked to you as parent — created on …, ask the children's ministry leader for a copy"). The self-service download must not assume the account holder is the child's legal guardian; that link can be set by whoever creates the profile. **Owner decision D3.**
- `tblEventRegistrations` matched on `submittedByUserID` (an actor column, PROVEN `GdprEraser.php:375`): today handed over in full (PROVEN `data-export.php:196-205`), including a child's medical notes. With the guardian flag from section K item 3, the rule becomes: submitter declared themselves the parent or guardian → full row; otherwise → summary line. Until that flag exists, keep today's behaviour (full row) and say so in the file — the submitter typed every field themselves.

**This changes six of today's blocks.** `venueBookingsEdited`, `venueUsageWindowsCreated`, `venueImportBatches`, `smallGroupsCreated`, `smallGroupMembersAdded` and `reportDefinitions` (PROVEN `data-export.php:138-170,231-235`) all return content columns of actor-matched rows today — booking notes, an import's file name, other members' group roles, a report definition that may contain a name in a filter value (that risk is recorded in `GdprEraser.php:241-247`). Under the new rule they become summary lines. That is a deliberate reduction in what is handed over, and the file's `summary.html` explains it in one sentence: "For records you created or changed but that are not about you, we list that you did so and when; the content belongs to the organisation or to other people."

### What it costs

The `links` map is roughly 131 entries × 1–5 columns, written once. A throwaway script can draft it from the schema and the name rule; a person then reviews the about/acted split — the three exceptions above are the kind of thing only a reader catches. Half a day, mostly reading.

---

## B. Finding the person everywhere

### Recommendation

**One query per table, `OR` across every classified link column, each row tagged with the relationships it matched.**

```
SELECT <every non-credential column>
FROM `tblAttendanceSessions`
WHERE `createdByID` = ? OR `updatedByID` = ?
ORDER BY `sessionID`
LIMIT 500
```

In PHP, for each row: `relationships = [column for each link column where row[column] == userId]`. If any relationship is "about" → keep the full row; otherwise reduce it to the summary line (A). The row appears **once**, whichever and however many columns it matched — this is what stops a row being counted twice (C), which two separate queries per column (today's `tblSmallGroupMembers` pair, PROVEN `data-export.php:216-235`) cannot guarantee.

Each row in a table's JSON file carries:

```
{ "_id": 412, "_relationships": ["you created this", "you last changed this"], "createdAt": "...", "updatedAt": "..." }
```

The plain-words phrase for each link column lives in one dictionary in the new reader class (`userID` → "this record is about you", `createdByID` → "you created this", `approvedByID` → "you approved this", `counterpartyUserID` → "you borrowed or lent this", `parentUserID` → "you are recorded as the parent", …). The self-test fails if any name in `LINK_COLUMNS` has no phrase, so a new link name cannot reach the download without words a person can read.

**Widening `LINK_COLUMNS`.** The survey found 24 person-link column names the constant does not know (§2f), 14 of them with a real FOREIGN KEY to `tblUsers` (`uploadedByID`, `preparedByID`, `actorUserID`, `importedByID`, `personUserID`, `visitedByID`, `followUpAssignedToID`, `operatorID`, `sentByID`, `postedByID`, `contactedByID`, `actedByID`, `performedByUserID`) and the rest without (`assignedToUserID`, `checkedInByID`, `checkedOutByID`, `viewerID`, `paidByID`, `matchedByID`, `resolvedByID`, `submittedByID`, `statusChangedByID`, `giverID`). All go into `LINK_COLUMNS`; all but `personUserID`, `assignedToUserID` and `giverID` go into `ACTOR_COLUMNS`. (`assignedToUserID` on `tblPrayerRequests` is a prayer-chain member assigned to pray — acted, on reflection; `giverID` on `tblCountEnvelopes` is the giver — about; `personUserID` is the care-case subject — about.) The coverage check's own regular expression (PROVEN `check_personal_data_coverage.py:104-106`, fifteen names) should stop keeping its own copy and read `LINK_COLUMNS` from `GdprEraser.php` by regex, the way the self-test already reads the hand-written entries (PROVEN `gdpr-coverage-selftest.php:294-300`). Better still, the check should ALSO treat any column with `FOREIGN KEY (...) REFERENCES tblUsers` in the schema as a link, so a new column with a brand-new name is caught by the database structure rather than by somebody remembering to add a name.

**Reaching a person one hop away.** Four tables hold the person's data but link to them only through another table (PROVEN from the schema): `tblCareVisit` (via `caseID` → `tblCareCase.personUserID`), `tblExpenseClaimFiles` (via `claimID` → `tblExpenseClaims.userID`), `tblKidCheckins` (via `childID` → `tblKidProfiles.parentUserID`), `tblVenueImportRows` (via `batchID` → `tblVenueImportBatches.createdByID`). Add one optional key:

```
'reach' => ['through' => 'claimID', 'table' => 'tblExpenseClaims', 'key' => 'claimID'],
```

meaning "this table's rows belong to whoever the named parent row belongs to". The reader builds `… WHERE claimID IN (SELECT claimID FROM tblExpenseClaims WHERE userID = ?)` and the relationship phrase comes from the parent's link. One hop only; anything deeper is listed as unsearchable rather than guessed at.

**The same matching goes into the eraser** (#492 item 1). `fromPersonalDataCatalogue()` currently breaks on the first name it finds (PROVEN `GdprEraser.php:459-465`). It should emit one instruction per classified link column: for an `unlink` table, `anonymise` on each column (the `IF(col = ?, NULL, col)` sets from `4349c76` already do the right thing per column, PROVEN lines 814-817); for an `erase` table, `delete` on each **about** column and `anonymise` on each **acted** column — which is exactly the hand-written `tblSmallGroupMembers` pair (PROVEN lines 255-256), generalised. `inventory()` (PROVEN lines 654-684) then counts on every column too, so `/account/my-data` stops under-counting.

### Evidence that it performs

Every catalogue table has a single-column primary key (PROVEN), so `ORDER BY pk LIMIT 500` with `WHERE pk > ?` on the next chunk (section G) works on all of them. Each link column that matters already has an index in the schema for the common cases (`idx_registration_submitted_by`, `idx_astln_cpuser`, `idx_visitor_assignee`, `idx_kid_parent`, … PROVEN in the CREATE blocks read this stage). INFERRED: with an `OR` across two or three indexed columns MySQL uses an index merge or a short scan; at the row counts a church portal sees this is milliseconds. I could not measure it.

---

## C. One list, not two

### Recommendation

**A single reader class, `Portal\Core\PersonalDataCatalogue`, that both `GdprEraser` and the new `DataDownload` call.** It loads `personal-data-catalogue.php`, validates every entry's shape, memoises the result, and answers: what tables, what decision, which link columns and their classification, the download tier, the reach rule, the columns to exclude, the plain-words label, and — from `information_schema`, memoised per request — the real columns and types of any table.

**The catalogue entry grows four optional keys and one required one.** Required: `links` (A). Optional: `download` (`full` | `summary` | `listed`; default derived — `retain` → `listed`, otherwise by link tier), `reach` (B), `exclude` (A), `unsearchable` (D — the sentence shown when the table has no link at all), `label` (plain-words name; default derived from the table name, `tblExpenseClaims` → "Expense claims").

**The hand-written blocks do not coexist with the catalogue part — they are removed.** `data-export.php`'s 21 blocks become zero. The exceptions they encoded are now catalogue keys: the `tblPrayerRequests` exclusion is `'exclude'`, the `tblEventRegistrations` JOIN to the event name becomes a `labels` lookup (below), the two `tblSmallGroupMembers` blocks become one query with two link columns. There is then nothing left to disagree, and no row can be counted twice because there is exactly one query per table.

**Names for identity numbers, without per-row JOINs.** Today three blocks JOIN for context (`e.eventName`, `g.groupName`, `mt.topic`, PROVEN `data-export.php:197-229`). A JOIN language in the catalogue would be a second thing to keep in step. Instead `data.json` carries a `labels` object built after the tables are written: `{"sites": {"1": "Cambridge"}, "events": {"12": "Holiday club"}, "groups": {"3": "Tuesday home group"}}` for the identity numbers that actually appeared, from a closed list of three lookups hard-coded in the class (`tblSites.siteName`, `tblEvents.eventName`, `tblSmallGroups.groupName`). `summary.html` uses them; `data.json` stays joinable by anyone else.

**What the self-test should enforce** — not "every table is represented in the download" (with a catalogue-driven download there is no per-table list in the download to compare), but the things that would let drift restart:

1. `data-export.php` contains no `FROM tbl` and no `tbl` identifier at all (regex). A hand-written block cannot come back unnoticed.
2. `DataDownload.php` and `GdprEraser.php` both name `PersonalDataCatalogue` and neither `require`s `personal-data-catalogue.php` directly (only the reader does).
3. Every catalogue entry has `links`; every column in `links` and `exclude` and `reach.through` exists on the table in `full_schema.sql` (this catches the five wrong names the survey found, §1a); every column on the table whose name is in `LINK_COLUMNS` or that has a FOREIGN KEY to `tblUsers` appears in `links` (this catches the 29 unmentioned links, §2e, and the 11 tables outside the catalogue, §2f, because they will first have to be added).
4. Every classification is `about` or `acted`; every name in `LINK_COLUMNS` has a relationship phrase.
5. Every `retain` entry has `period`; every `listed` entry has `whoToAsk` text or falls back to the setting (D).
6. Every entry with no `links` and no `reach` has `unsearchable` text (D).
7. The pure query builder, run over every catalogue entry against the parsed schema with a fake user id, (a) produces SQL whose every identifier is in the schema, (b) binds exactly as many values as it has placeholders, (c) never selects a credential column, (d) for `listed` tables produces `COUNT(*)` only, (e) for `summary` tier selects only the identity number, the link columns and `…At` columns.
8. The JSON Schema file parses, has `description` on every property (walked), and the self-test's sample `data.json` validates against it.

Items 1–6 are a few hundred lines of PHP in `tools/gdpr-coverage-selftest.php`; item 7 is a new `tools/data-download-selftest.php` on the `tools/report-builder-selftest.php` model (it exercises real classes with no database, PROVEN that model exists — `.claude/CLAUDE.md` describes it); item 8 is a new Python check plus the PHP self-test. All of them must be wired into `pr-security.yml`, which today runs none of the six `tools/*-selftest.php` files (PROVEN).

### Why not keep the hand-written blocks "for the special cases"

Because the survey showed what happens: five apps each added a block (PROVEN (survey), the last five commits on the file), the file header describes tables and columns the file does not read (PROVEN `data-export.php:19` claims line items and attachments; nothing reads `tblExpenseClaimFiles`), and six blocks quietly hand over actor-matched content. Every special case above has a catalogue key that a check can see. A block in PHP is invisible to every check.

---

## D. The tables with no account link

### Recommendation

**The download names them.** `data.json` gets a section per such table with `tier: "unsearchable"`, the plain label, and the catalogue's `unsearchable` sentence: what the table holds, why it cannot be found by account, and what to do. `summary.html` renders them under "Things we hold that this search cannot find by account". The self-test refuses a catalogue entry with no link, no reach and no sentence — so a table can never be silently absent.

**Split the survey's list into three kinds** (all PROVEN from the schema in the survey, §3, re-read this stage where a decision changes):

| Kind | Tables | What the sentence says | Also do |
| --- | --- | --- | --- |
| Genuinely anonymous — the record deliberately carries no account | `tblAnonymousCheckins`, `tblLivestreamSessions`, `tblLiveChatMessages`, `tblAssetFoundReports`, public `tblFormResponses` and `tblEventRegistrations` rows (no submitter), `tblSalvationCards` (the subject), `tblEventRSVPInvites` (the invitee), `tblInvitation` (the invitee), `tblVenues` (the caretaker) | "Stored under the name or email you typed, not your account. If you want a copy, tell us the name, the email address and roughly when, and a person will search." | nothing — this is by design |
| Reachable one hop away | `tblCareVisit`, `tblExpenseClaimFiles`, `tblKidCheckins`, `tblVenueImportRows` | (not unsearchable — handled by `reach`, B) | add `reach` |
| Mis-sorted | `tblAttendanceCounts` (its only "personal" column is an INT session number — PROVEN (survey) §5); `tblServicePlanItems` and `tblServicePlanItem` (worship slides and run-sheet items; `notes`/`slideNotes` are operator notes about a service, not about a person) | — | re-sort `tblAttendanceCounts` to `not-personal`; leave the two service-plan tables `unlink` on `presenterID` (which exists on `tblServicePlanItem`, PROVEN (survey)) and `unsearchable` for `tblServicePlanItems` |
| Named person with no account, by nature | `tblAssetOrgs` (a partner organisation's contact person), `tblCountEnvelopes` (a named envelope giver, `giverID` may be set), `tblEmailLog` (recipient addresses) | "Holds the name/email typed in. Searched by a person on request." | `tblEmailLog` and `tblCountEnvelopes` need catalogue entries (they have none, PROVEN (survey) §3); `tblEmailLog` gets a one-line exact-address match on `toRecipients LIKE ?` — the eraser already does exactly that (PROVEN `GdprEraser.php:1017`), so the download can safely show "N emails were sent to your current address" as a count |

The `tblEmailLog` count is the one text search I recommend (E), because it is an exact match on the person's own current email address, the eraser already relies on the same match, and the result is a count, never other people's rows.

---

## E. Personal data in free text

### Recommendation

**Do not search free text by name in the self-service download. Say plainly that it is not searched, and how to ask.**

Can they be found? Only by text search on values known from `tblUsers` (full name, email, phone) — `LIKE '%value%'` per column. The codebase already has one such search, the eraser's `eraseEmailLogRecipients()` (PROVEN `GdprEraser.php:1007-1031`), and already refuses another: `data-export.php` will not match `tblSalvationCards` by name or email because "another person's card sharing the same name/email" is a false-positive risk it "must not take" (PROVEN lines 237-244).

Should they be? Three reasons no, in this download:

1. **Over-match discloses other people.** A common name matches other people's rows — a visitor record, a loan, a child's collector list — and a download hands those rows over. That is exactly the harm A exists to prevent, and it would happen automatically, at scale, without a person looking.
2. **Under-match gives false comfort.** A nickname, an old address, a second phone number or a spelling variant is missed, and the file would still say "searched". A file that says "not searched" is honest; one that says "searched" and missed things is worse than nothing.
3. **It is the one place a person is genuinely needed.** UK GDPR expects a reasonable search; it does not expect an automated one to find every mention in free text. A sentence in the download — "We do not search notes and free-text fields by name. If you believe a note mentions you, tell us where or roughly when and a person will look" — is the defensible position.

**The two columns the brief names**, and what happens to each:

- `tblAssetLoans.counterpartyContact` (and `counterpartyName`): when the loan is matched on `counterpartyUserID` the row is "about" the person and the full row — including those two columns — is handed over. So for the download nothing special is needed. For erasure (K item 2) the same two columns are cleared on rows matched on `counterpartyUserID` only: they are the matched person's own details by construction.
- `tblKidProfiles.pickupAuthorisedNames`: a comma-separated list (PROVEN column comment). For the download: the parent gets a summary line (A / owner decision D3), so the list is not handed over by default. For erasure (K item 2): remove only the exact, case-insensitive, trimmed match of the erased person's `fullName` from the list on rows matched on `parentUserID`; leave every other name. This cannot find a departing adult who is named in the list but is not the linked parent — that is the free-text limit again, and the file says so.

**One text search is kept**, the exact-address count on `tblEmailLog` (D), because it is exact, it is on the person's own address, and it returns a number.

---

## F. Format

### Recommendation

**One ZIP file**, named `your-data-{date}.zip`, containing:

```
data.json                 what is in this download, what is not, and why (small)
tables/tblXxx.json        one JSON array per table, written one row at a time
summary.html              readable, self-contained, no outside resources
files/expenses/...        uploaded files that are ABOUT the person (see below)
README.txt                three lines: what this is, when it was made, where to ask
```

- **JSON for moving data elsewhere** — one file per table rather than one giant document, so the writer never holds a table in memory (G) and so a reader can open the one table they care about.
- **HTML for reading** — a single self-contained page (inline CSS, no scripts, no fonts fetched) with a section per table: the plain label, the reason it is kept, the relationship phrases, the rows as a readable list (`portal-data-list`-style markup, no `<table>` — the house rule), the listed tables with their counts and who to ask, the unsearchable tables with their sentences, and the columns left out and why. It opens on any phone or computer and screen readers handle it. **Not PDF**: `Pdf::create()` exists (PROVEN `web/_core/Pdf.php:76`, dompdf), but a long member's summary would be hundreds of pages, dompdf on shared hosting is slow on large documents (INFERRED — not measured), and HTML is the more accessible of the two. PDF can be added later from the same HTML.
- **Not CSV per table in v1.** `CsvExporter::download()` streams straight to the browser (PROVEN `web/_core/CsvExporter.php:46`) rather than to a file, so it would need a small refactor, and JSON already serves the "move it elsewhere" purpose. Owner may opt in later.

**Uploaded files: only those that are about the person.** Three groups, from the survey's §7 table (PROVEN there):

| Belongs in `files/`? | Which | Why |
| --- | --- | --- |
| **Yes** | `tblExpenseClaimFiles` (receipts and the claim's own generated PDFs, reached via `claimID` → their claim); `tblUsers.displayPhoto` and `tblGiftAidDeclaration.signaturePath` if ever populated (both dormant — no code writes them, PROVEN (survey)) | Their own uploads about their own claim; their own photo and signature. |
| **No — listed by name and date only** | `tblPhoto`, `tblDocuments`, `tblNoticeboardUploads`, `tblRecording`, `tblAssetResources`, `tblVenueAgreementFiles`, `tblVenueInvoices`, `tblVenueImportBatches`, `tblBankImports`, `tblEventMaterials` | The organisation's material (a gallery photo, a sermon recording, a hire contract, a bank statement naming many donors). Actor-tier: the summary line says "you uploaded `service-2026-03.mp3` on 3 March". |
| **No — listed** | `tblGivingStatementLog.pdfPath` | A `retain` table by the owner's decision; the count and the "ask the treasurer" line cover it. |

`tblUsers.avatarPath` is usually an external `https` URL from Microsoft or Google (PROVEN (survey)); the URL stays in the row, no file is fetched.

Total size is bounded by the receipts; expense receipts are images and PDFs a few hundred kilobytes each. INFERRED: a member with a hundred claims might reach tens of megabytes. Section G caps nothing but reports sizes.

**The JSON Schema.** `web/_core/data-download.schema.json` (JSON Schema draft 2020-12), with `$defs` for `section`, `tableRow`, `summaryRow`, `fileEntry`, `columnLeftOut`, `limit`. Every property carries a `description`; `$comment` carries the maintainer notes (the rule against `//` in JSON, PROVEN in the standing rules). `data.json` names its schema in a `$schema`-style `format`/`formatVersion` pair (`"format": "webms-intra-data-download", "formatVersion": 2` — today's file is `exportVersion: "1"`, PROVEN `data-export.php:89`). Per-table files are arrays of `tableRow` | `summaryRow` and are validated by the same schema through `$defs`. **Checked by two things that run:** `tools/audit-checks/check_json_schemas.py` (finds every `*.schema.json`, parses it, fails on any property without a `description`, and validates each fixture under `tools/audit-checks/fixtures/json-schemas/` against its schema using Python's `jsonschema`; if `jsonschema` is not importable it FAILS with a one-line install instruction rather than passing — refuse when unsure); and `tools/data-download-selftest.php`, which builds a sample `data.json` with the real class and writes it to that fixtures folder, so the Python check validates what the PHP actually produces. INFERRED: GitHub-hosted runners have Python 3 and `pip`; `pr-security.yml` would gain `pip install jsonschema` in its setup. I did not read the workflow's setup steps to confirm what is already installed.

**When the server has no ZIP support.** `ZipArchive` is not verified enabled on the live server (PROVEN (survey) §9). The job finishes with `format: 'folder'` and the account page offers `data.json`+`tables/` as one tarless choice? — no: it offers two links, `summary.html` and a single concatenated `data.json` that embeds the table arrays (built by streaming the per-table files into one, still one row at a time), and `files/` is not offered (a folder cannot be sent). The page says exactly that in one sentence. Same shape as `statements-download.php`'s per-row fallback (PROVEN line 105).

---

## G. Size and time on shared hosting

### Recommendation

**Prepare in resumable chunks; never hold a table in memory; report every limit in the file.**

The facts this rests on: DreamHost's FastCGI can kill a request regardless of `set_time_limit()` (PROVEN (survey) DEV_NOTES.md:3945-3953, and the giving-statements handlers cap themselves at 25 per request for exactly that reason); nothing sets `memory_limit` and the live values are unknown (PROVEN (survey) §9); today's file builds every row of every block into PHP arrays and one pretty-printed string (PROVEN `data-export.php:88-256`).

**The job.** A row in a new `tblDataDownloadJobs` table (H, and the build plan) records: whose, who asked (self or an administrator), status, the cursor (`cursorTable`, `cursorKey`), counts, the output folder, the file, expiry. `DataDownload::advance(int $jobId, int $budgetSeconds): array` does this, and nothing else does the work:

1. Claim the job atomically: `UPDATE … SET status = 'running', lockedAt = NOW() WHERE jobID = ? AND status IN ('queued','running') AND (lockedAt IS NULL OR lockedAt < NOW() - INTERVAL 5 MINUTE)` gated on `affected_rows === 1` — the `Workflow`/`Payments` discipline (PROVEN in `.claude/CLAUDE.md`'s description of those classes), so two ticks cannot write the same chunk.
2. `@set_time_limit($budget + 30)`; note the start time.
3. Walk the catalogue tables in a fixed order (the catalogue's own order). For the current table, from `cursorKey`: `SELECT … WHERE (<link> = ? OR …) AND pk > ? ORDER BY pk LIMIT 500`, `get_result()`, `fetch_assoc()` in a loop, append each row to `tables/tblXxx.json` (open in append mode; the file starts with `[`, rows are separated by `,\n`, and `]` is written when the table is finished). Update `cursorKey` in memory; after each 500-row page, persist the cursor to the job row. When the page returns fewer than 500 rows the table is done: write `]`, record the count in the job's `progress` JSON, move `cursorTable` to the next catalogue table, `cursorKey = 0`.
4. After each page, if `time() - start >= $budget`, persist the cursor and return `['status' => 'running', 'done' => n, 'of' => total]`. The next tick resumes at exactly that row.
5. When every table is done: write `data.json`, `summary.html`, copy the `files/`, build the ZIP (or the folder fallback), set `status = 'ready'`, `readyAt`, `expiresAt = readyAt + retentionDays`, remove the working folder, and send the "ready" email.

Memory: one row at a time plus the 500-row `mysqli_result` buffer. INFERRED peak: a few megabytes even for `LONGTEXT` rows, well under any plausible `memory_limit`. Time: a chunk is at most `dataDownload.secondsPerChunk` (seeded 20) plus one page's worth of overrun, well under the shortest FastCGI limit anyone has reported. Nothing in the design needs more than one page of a table per query, so a table of a million rows still costs 2,000 short queries, not one long one.

**Who ticks it.** Two callers of the same `advance()`:

- The person's own account page: after "Prepare my download" the page shows progress and a "Continue" button; the page auto-submits it (a `<meta http-equiv="refresh">` or a small script — the site already uses inline scripts, PROVEN by the pwa-install and push-subscribe files described in CLAUDE.md) every few seconds while `status = running`. So a member who waits sees it finish; most jobs finish in the first tick anyway.
- A token-gated `cron/data-downloads.php` on the ten existing `cron/*.php` model (PROVEN `ls web/_apps/cron/`; token pattern PROVEN `cron/giving-statements.php:54-60` — empty token always 403). It advances every `queued`/`running` job for one budget each, prunes expired files, and sends ready emails. So a member who closed the tab still gets their download. If no cron is configured the page path alone still works; the difference is only whether the tab must stay open.

**Limits, said in plain words.** The design imposes no row cap and no size cap. The only things that can be limited are: a table that could not be read (H — the name in the catalogue does not exist in the database, or `prepare()` failed), a table whose app is not installed (the table is absent — reported as "not installed here", not as "no rows"), a file recorded in the database but missing on disk, and ZIP unavailable. Each goes into `data.json.completeness.notes[]` as a sentence and into the top of `summary.html`, and `completeness.status` is `"partial"` whenever the list is non-empty. **Today's silent `LIMIT 5000` on activity logs goes** (PROVEN `data-export.php:121`); the table is streamed like every other.

**Retention of the prepared file.** Deleted `dataDownload.retentionDays` (seeded 7) after it became ready, by the cron tick or, failing that, by the next request from anyone that touches the job table (the opportunistic-prune pattern `tblEmailLog` uses, PROVEN in CLAUDE.md's description of migration 176). The person can download it as many times as they like inside that window; every fetch is logged.

---

## H. Safety

### Recommendation

**Identifiers.** Every table and column name in any SQL the download runs comes from the intersection of two lists the request cannot influence: the catalogue file on disk, and `information_schema.COLUMNS` for that table (`WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?`, bound). A name in the catalogue but not in the database is NOT queried: it is written to `completeness.notes` ("Expected a column `createdByID` on `tblRecording` and did not find it; this table was skipped") and the job carries on. A table in the catalogue but absent from the database is reported as "not installed here". `prepare()` returning false is a hard error on that table, logged with `$mysqli->error` to `tblErrors` through `Logger`, reported in the file, and the job carries on. **Nothing returns an empty list that looks like "no rows"** — that is the fault in `$fetchUserRows` (PROVEN `data-export.php:72-75`) and in `inventory()` (PROVEN `GdprEraser.php:679-681`), and this design does not copy it. Identifiers are back-quoted; the builder refuses any name that is not `^[A-Za-z0-9_]+$` (belt and braces — the schema parse already guarantees it).

**Values.** Exactly two kinds are ever bound: the subject's `userID` (as many times as there are link columns) and the cursor `pk`. Types string is built from the same list that built the SQL, with the `strlen() === count()` assertion the report builder uses (PROVEN in CLAUDE.md's description of `ReportBuilder::compile()`).

**Scope.** No `siteID` filter — the match is on the person's own identity number, so every row from every organisation they belong to comes back, which is what a right of access requires. `data.json.organisations[]` lists the sites they are or were a member of (`tblUserSites` incl. `isActive = 0`, PROVEN the column exists) with names from `tblSites.siteName`, and each row keeps its `siteID` so the reader can tell which organisation holds it. Today's file records only the current site (PROVEN `data-export.php:91`), which misleads a multi-site member.

**Never another person's rows.** Guaranteed by construction: the only user id bound is the session's (`App::user()['userID']`), or — on the administrator path — the target user id taken from the job row that the administrator created, never from the download request. The file-fetch address checks `job.userID === session userID` before `readfile()`. There is no request parameter that names a user anywhere on the self-service path.

**The route's own guard.** The survey noted that `Router.php:83` compares `isProtected` as a string and INFERRED it never enforces login (§6). Every new page here calls `Auth::ensureSession(); Auth::requireLogin();` itself, as the existing page does (PROVEN `data-export.php:54-55`), so the design does not depend on that comparison. It still belongs in the sweep.

**Should an administrator be able to produce one for somebody who asks in writing?** — *Owner decision D1, recommendation: yes, narrowly.*

The case for it is real: a right-of-access request can arrive by letter from somebody who cannot sign in (a member whose account was deactivated when they left; somebody who has forgotten everything), and today nobody can produce a download for anybody else (PROVEN (survey) §6 — no impersonation facility). The risk is equally real: an administrator holding a member's complete file. The narrow shape:

- A **portal-wide** administrator (`isRootAdmin`, because the data spans sites) can queue a job for a user from the admin user page, typing a reason ("written request received 12 September, ref …").
- The job is flagged `requestedByID = <admin>` and `reason`, written to `tblAuditTrail` through `Logger::audit()` naming the admin, and shown on the person's own account page.
- The administrator **never** receives the file. The "ready" email goes to the person's registered address, and the link still requires that person to sign in. If the account is deactivated, the administrator re-activates it (an existing action, logged) for the person to collect, or handles it as the manual process it would be anyway.
- Seeded off (`dataDownload.adminCanRequest = 'false'`) so a site that does not want it never has it.

The alternative — administrator downloads the file with a second administrator's approval — is a v2 if the owner wants it; it needs a two-person step that does not exist yet.

---

## I. Proving it is really them

### Recommendation

**Five layers, none of which alone is the protection; together they make a hijacked session insufficient and make the real owner aware.**

1. **A fresh proof at the moment of asking.** The "Prepare my download" form asks for, in this order of availability: the current password where the person has a local account (`password_verify()` against `tblLocalAccounts.passwordHash`, the exact check `change-password.php:82` does, PROVEN); otherwise a two-factor code where `tblUsers.totpEnabled = 1` (`Totp::verify()`, PROVEN); otherwise — people who only ever signed in with Microsoft or Google and have no password (PROVEN, section 0) and no TOTP — an **emailed confirmation link**, the erasure-request pattern (PROVEN `erasure-request.php:52-74`): job status `awaiting_confirmation`, a 64-hex `confirmToken`, valid one day, single use, and the job only becomes `queued` when it is clicked. **Why not always email?** It costs a round trip for everybody and depends on mail being configured; the password and TOTP paths are instant and equally strong against a stolen cookie. **Why not "signed in within the last ten minutes"?** The session records no sign-in time (PROVEN, section 0); adding one is cheap but it is weaker than a re-proof, because the hijack could happen inside the window.
2. **CSRF** on the POST that creates the job (`Auth::verifyCsrf()`, PROVEN it exists — `delete-confirm.php:49`), and the typed-phrase pattern is NOT copied: it protects against accidents, not hijacks, and a download is not destructive.
3. **Rate limits.** `RateLimiter::tooMany('data-download:' . $ip, 5, 3600)` on the request handler (5 per hour per address — the public-form figure, PROVEN `forms/public-submit.php:140`), plus a per-person rule in the job table: no new job while one is `queued`/`running`, and none within `dataDownload.minHoursBetween` (seeded 24) of the last `ready`. A download of everything is not something anyone needs twice in a day; a hijacker probing repeatedly is.
4. **Audit, naming the person.** Today's `Logger::activity('DataExport', …)` writes a row whose `userID` is NULL because the third argument is never passed (PROVEN `data-export.php:249` against `Logger.php:48-86`). Every step here — requested, confirmed, each chunk, ready, each file fetch, expired — calls `Logger::activity($type, $desc, $userId)` WITH the id, and requested/ready/fetched also go to `tblAuditTrail` via `Logger::audit('tblDataDownloadJobs', $jobId, …, $userId)` with the IP. That is the "record in the audit trail" the brief asks for, and it also covers the admin path (H).
5. **Tell the owner.** The "your download is ready" email always goes to the registered address, whether the person or an administrator asked, and says who asked and from where. A person who did not ask learns immediately. This is the layer that catches a hijack that beat the others.

**The file link.** `/account/data-export/file?job={id}&t={64-hex}` — the token is stored hashed (`hash('sha256', …)`, like `tblTrustedDevices.tokenHash`, PROVEN (survey)) and compared with `hash_equals()`; the page additionally requires `job.userID === session userID`. So a leaked email is useless without the session and the session is useless without the token. Served with `Content-Disposition: attachment`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Content-Length` + `readfile()` (the `statements-download.php` shape, PROVEN (survey) §9). The file lives under `PORTAL_ROOT/_uploads/data-downloads/{userID}/`, outside the web root (PROVEN `_uploads/` is under `web/`, not `web/public_html/` — CLAUDE.md layout).

**Encrypt the prepared file at rest?** — *Owner decision D4, recommendation: not in v1.* The file sits outside the web root for at most seven days behind two checks; `_uploads` already holds receipts, Gift Aid statements and pastoral documents unencrypted. Encrypting one file type while the rest of `_uploads` is plain would be security theatre. If the owner wants at-rest encryption it should be for all of `_uploads`, as its own piece of work.

---

## J. Instant, or prepared

### Recommendation

**Prepared.** The law allows a month (INFERRED as a matter of law — I did not check the statute in this stage; the brief states it). Nothing about a right of access requires the file to appear in the same request, and every hard problem in G, H and I is solved by preparing it: a request that cannot be killed, a file that can be delivered by link, an administrator path that never hands the file to the administrator, and a "ready" email that tells the real owner.

But prepared must not mean slow. The first chunk runs in the same request that queued the job (unless the email-confirmation path is needed), and for an ordinary member — a few hundred rows across a couple of dozen tables — INFERRED that it finishes inside that first twenty seconds and the page shows the download at once. The person only waits when their history is genuinely large, and then the page tells them how far it has got and that an email will follow.

**What the person sees.** `/account/data-export` becomes a page (it is a bare download today, PROVEN): a paragraph explaining what the download contains and does not; the "Prepare my download" form with the proof field; the current job's state (queued / running with a count / ready with the file link, size and expiry / expired); and the last few jobs with who asked and when. The address is kept so the five existing links stay valid (PROVEN, section 0).

---

## K. The #492 follow-ups

| # | Question | Recommendation | Where it lands |
| --- | --- | --- | --- |
| 1 | Only the first link column is matched | Match on every classified link column, in the eraser as well as the download (B). The eraser emits one instruction per link column; `inventory()` counts on each. | `GdprEraser::fromPersonalDataCatalogue()`, `inventory()`; self-test check "every classified link column produces an eraser instruction" |
| 2 | Free-text contact details survive an unlink | `tblAssetLoans.counterpartyName`/`counterpartyContact` cleared on rows matched on `counterpartyUserID` (they are that person's own details by construction; the row was matched on them) — the eraser's existing "not a link → free text belonging to the matched person → NULL" branch (PROVEN `GdprEraser.php:819-823`) does it once the columns are listed against that link. `tblKidProfiles.pickupAuthorisedNames`: remove the exact match of the erased person's `fullName` from the comma list, in PHP, on rows matched on `parentUserID`; every other name stays. Cannot find an adult who is in the list but is not the linked parent — said in the erasure audit trail and in the download's free-text sentence (E). | catalogue `links` gain an optional `'clears' => ['counterpartyName','counterpartyContact']` per link; a small bespoke eraser step on the `eraseEmailLogRecipients()` model |
| 3 | Who has authority over a child's registration | Add `tblEventRegistrations.submitterIsGuardian TINYINT(1) DEFAULT NULL` and a checkbox on the registration form, "I am this child's parent or guardian", shown only when signed in (the public form has no submitter to ask). Erasure: DELETE only where `submittedByUserID = ? AND submitterIsGuardian = 1`; otherwise anonymise `submittedByUserID` and keep the record — the safe direction. Download: guardian → full row; otherwise → summary line ("you entered a registration for *Holiday club* on 3 March"). NULL (before the flag existed, or unticked) is never treated as guardian. No existing row has `submittedByUserID` set before migration 191 (PROVEN, that migration's "what this does not do"), and there is no real customer data yet (PROVEN (survey)), so the default costs nobody anything today. | migration 194 + `full_schema.sql`; `calendar/event-register-save.php` and its form; `GdprEraser` hand-written entry for `tblEventRegistrations` (currently reached only through the catalogue's `submittedByUserID` link and the self-test's `$deliberateDeletes`, PROVEN `gdpr-coverage-selftest.php:218-222`) |
| 4 | Does an expense claim keep the claimant's identity? | **Keep it** — *owner decision D2, recommendation: yes.* HMRC's six-year rule is about being able to show what was reimbursed to whom; a reimbursement record that points at nobody is a weaker record, and the link points at an account that is already a tombstone naming nobody (`[Deleted User]`, `deleted-{rid}@example.invalid`, PROVEN `GdprEraser.php:31-32,282-285`). So the honest catalogue entry is `decision => 'retain'`, `period => '6 years (HMRC)'`, `download => 'full'` — kept whole on erasure, handed over in full on request. Today the catalogue says `unlink` and claims "the claimant's name is removed" (PROVEN `personal-data-catalogue.php:849-853`), which the database prevents (`userID INT NOT NULL`, PROVEN) — the entry asserts something the code cannot do. `tblCareCase`: keep the code's `unlink` on erasure (case stays, subject identity detached — the documented behaviour, PROVEN `GdprEraser.php:143-176`) and `download => 'listed'` (the owner's decision 3). The two keys being separate is what lets both tables be described truthfully. | catalogue entries; the self-test's "the written list and the code agree" check already compares decision to action per table (PROVEN lines 316-357) and will pass once the entries say what the code does |

**Why the `download` key must be separate from `decision`.** `retain` has been carrying two meanings: "kept on erasure" and "listed, not handed over, on download". `tblExpenseClaims` needs the first and NOT the second; `tblCareCase` needs the second and, on erasure, is unlinked rather than retained. One key cannot say both. Two keys on the same entry keep it one list.

---

## The credential rule, as adopted

Validated in the survey (§5) and adopted with three corrections:

**Rule.** Leave a column out when its NAME matches `hash|secret|token|password|passwd|key|salt|cipher|signature|nonce|credential|otp|session` (case-insensitive, anywhere in the name) AND its `DATA_TYPE` from `information_schema` is a string or binary type (`char`, `varchar`, `text`, `tinytext`, `mediumtext`, `longtext`, `blob` family, `binary`, `varbinary`, `json`). Keep it when the type is a whole number, decimal, date, time, datetime, enum or boolean-style `tinyint`. So `tblAttendanceSessions.sessionDate` (DATE) and `sessionTime` (TIME) are kept — the brief's "unless integer" wording would have dropped them (PROVEN (survey) §5) — and every real secret the survey listed is text and goes.

**Always out, whatever the name says** (a short list in the reader class, self-tested): `tblPushSubscriptions.endpoint` (with the two keys it lets anyone push to that browser), `tblKidCheckins.badgeCode` (the safeguarding collection code), `tblLinkedAccounts.providerSub` (the provider's identifier — not a secret, not for publishing). The survey found all three (§5).

**Always in, whatever the name says** — *owner decision D5*: `tblActivityLogs.sessionDataSnapshot` (their own session as JSON, with `csrf_token`/`oauth_state`/`oauth_nonce` stripped before storage, PROVEN `Logger.php:57`) and `tblActivityLogs.requestHeaders` (redacted of cookies, bearer tokens, CSRF and API keys before storage, PROVEN `Logger.php:501-509`). Recommendation: include both — #479's second comment asked for exactly this widening, and what is stored is already scrubbed. `tblSongs.defaultKey`, `periodKey`, `sourceKey`, `fieldKey`, `bankKey`, `recordKey`, `keyPrefix` are harmless labels the rule drops; leave them dropped rather than grow the exception list — none is personal data.

**What today's file gets wrong that this fixes:** `tblUsers.calendarToken` (VARCHAR(64), the iCal feed credential) is in every download produced today, because the block strips only `totpSecret` (PROVEN `data-export.php:102-105` against the schema). Under the rule it goes, and `data.json.columnsLeftOut[]` says so: `{"table":"tblUsers","column":"calendarToken","why":"a credential — it lets anyone read your calendar feed"}`. Every column left out by the rule is listed there, so the file never hides that it hid something.

---

## The build plan

Ordered so that every step leaves the portal working and each can be committed on its own. Every step ends with `php -l` on touched files, the audit checks, the self-tests, and a Codex review before commit (standing rule). Nothing here is verified against a database; the migration harness in CI is the first place the SQL runs.

### Step 1 — The catalogue becomes complete and checkable (no behaviour change)

**Files:** `web/_core/personal-data-catalogue.php`, `web/_core/GdprEraser.php` (constants only), `tools/audit-checks/check_personal_data_coverage.py`, `tools/gdpr-coverage-selftest.php`, `.github/workflows/pr-security.yml`.

1. Widen `GdprEraser::LINK_COLUMNS` and `ACTOR_COLUMNS` with the 24 names (B). Constants only; `fromPersonalDataCatalogue()`'s private `$linkColumns` list is left alone in this step so erasure behaviour is unchanged.
2. Add the 17 missing tables to the catalogue with a decision and reason each: the 11 with a foreign key to `tblUsers` (`tblAssetResources`, `tblBankImports`, `tblCcliUsage`, `tblDocuments`, `tblEventBroadcasts`, `tblProjectUpdate`, `tblServicePlan`, `tblVenueAgreementFiles`, `tblVisitorContact`, `tblWorkflowActions`, `tblExpenseClaimPayments`) plus `tblEmailLog`, `tblKidCheckins`, `tblCountEnvelopes`, `tblAssetFoundReports`, `tblEventMaterials`, `tblExpenseClaimFiles` (survey §2f, §3). Most are `unlink` on an actor column; `tblExpenseClaimFiles` and `tblKidCheckins` get `reach`; `tblAssetFoundReports`/`tblEventMaterials` get `unsearchable`.
3. Add `links` to every entry (draft by script, review by hand — A), fix the five wrong column names, add `reach` to the four one-hop tables, `exclude` to `tblPrayerRequests`, `unsearchable` sentences to the no-link tables, and the `download` key where the default is wrong (`tblExpenseClaims` full, `tblCareCase` listed, `tblKidProfiles` summary). Re-sort `tblAttendanceCounts` to `not-personal`. Change `tblExpenseClaims` to `retain` (K4). Fix the stale section headings and counts (survey §1a).
4. The Python check: read `LINK_COLUMNS` from `GdprEraser.php` instead of its own fifteen-name list; treat `FOREIGN KEY … REFERENCES tblUsers` as a link; verify every column the catalogue names exists; verify every link column on every catalogue table is in `links`. Prove the check fires by temporarily breaking one entry before committing (the "checks only cover what they read" lesson in memory).
5. The PHP self-test: checks 3–6 from section C. Keep every existing check.
6. `pr-security.yml`: run all six existing `tools/*-selftest.php` files as a step, failing the job on a non-zero exit (today none run in CI — PROVEN).

*Leaves the portal working because:* nothing at runtime reads the new keys yet; `fromPersonalDataCatalogue()` ignores keys it does not know.

### Step 2 — The shared reader

**File:** new `web/_core/PersonalDataCatalogue.php` (`Portal\Core\PersonalDataCatalogue`).

Public static methods: `all()`, `entry(string $table)`, `links(string $table): array`, `downloadTier(string $table): string`, `reach(string $table): ?array`, `excluded(string $table): array`, `label(string $table): string`, `phrase(string $linkColumn): string`, `realColumns(\mysqli $db, string $table): ?array` (name → `['type' => DATA_TYPE, 'nullable' => bool, 'isPrimary' => bool]`, memoised; `null` when the table does not exist), `isCredential(string $table, string $column, string $dataType): bool`, and the constants `CREDENTIAL_NAME_PATTERN`, `STRING_TYPES`, `ALWAYS_EXCLUDE`, `ALWAYS_INCLUDE`, `PHRASES`. `GdprEraser::fromPersonalDataCatalogue()` and `nullableColumns()` switch to it (same behaviour, one loader). Self-test checks 2 and 4 (C) start to apply.

*Leaves the portal working because:* the eraser's output is byte-for-byte the same instructions; the self-test's existing "list and code agree" check proves it.

### Step 3 — Migration 194 and the fresh-install script

**Files:** new `web/_sql/194_data_download_jobs.sql`; `web/_sql/full_schema.sql`.

1. `CREATE TABLE IF NOT EXISTS tblDataDownloadJobs` — `jobID INT AUTO_INCREMENT PK`, `userID INT NOT NULL` (FK `tblUsers` ON DELETE CASCADE — a job must not outlive its subject), `requestedByID INT NULL` (FK ON DELETE SET NULL; NULL = the person themselves), `reason VARCHAR(500) NULL`, `status ENUM('awaiting_confirmation','queued','running','ready','failed','expired') NOT NULL DEFAULT 'queued'`, `confirmTokenHash CHAR(64) NULL`, `downloadTokenHash CHAR(64) NULL`, `cursorTable VARCHAR(64) NULL`, `cursorKey BIGINT NOT NULL DEFAULT 0`, `progress JSON NULL` (per-table counts and notes), `workDir VARCHAR(500) NULL`, `filePath VARCHAR(500) NULL`, `fileSize BIGINT NULL`, `format ENUM('zip','folder') NULL`, `lockedAt DATETIME NULL`, `requestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `confirmedAt`, `readyAt`, `expiresAt`, `notifiedAt DATETIME NULL`, `downloadCount INT NOT NULL DEFAULT 0`, `lastError VARCHAR(500) NULL`, `requestIP VARCHAR(45) NULL`; keys on `(userID, status)`, `(status, lockedAt)`, `(expiresAt)`. InnoDB, utf8mb4, with a plain-English comment on every column.
2. Guarded `ALTER TABLE tblEventRegistrations ADD COLUMN submitterIsGuardian TINYINT(1) DEFAULT NULL COMMENT '…' AFTER submittedByUserID` (the migration-191 idiom, PROVEN).
3. Settings seeds, `siteID NULL`: `dataDownload.enabled 'true'`, `dataDownload.retentionDays '7'`, `dataDownload.minHoursBetween '24'`, `dataDownload.secondsPerChunk '20'`, `dataDownload.includeFiles 'true'`, `dataDownload.adminCanRequest 'false'`, `dataDownload.retainedContact ''`, `dataDownload.cronToken ''` (`isSensitive 1`).
4. Route seeds (`isProtected 1` — every handler also calls `requireLogin()` itself): `account/data-export` (kept, now the page), `account/data-export/request` (POST), `account/data-export/confirm` (GET with token), `account/data-export/continue` (POST), `account/data-export/file` (GET), `admin/users/data-download` (POST, admin path), `cron/data-downloads` (token-gated, `isProtected 0` like the other cron rows — INFERRED they are 0; check one seed before copying).
5. The same table, column, settings and routes folded into `full_schema.sql` in the matching positions; `tblMigrations` self-record.

Run `check_schema_seed_parity.py`, `check_mariadb_only_ddl.py`, `check_migration_idempotency.py`, `check_settings_keys.py`, `check_route_targets.py` (the last will report the seven route targets missing until Step 5 — so land Steps 3–5 in one commit, or seed the routes in Step 5's part of the same migration file; **recommendation: one commit for Steps 3–5**).

### Step 4 — The download engine

**Files:** new `web/_core/DataDownload.php` (`Portal\Core\DataDownload`); new `web/_core/data-download.schema.json`; new `tools/data-download-selftest.php`; new `tools/audit-checks/check_json_schemas.py` + `tools/audit-checks/fixtures/json-schemas/`; `.github/workflows/pr-security.yml`.

`DataDownload` public static methods: `request(int $userId, ?int $requestedById, string $reason, string $ip, bool $needsEmailConfirmation): int`, `confirm(string $token): ?int`, `advance(int $jobId, int $budgetSeconds): array`, `latestFor(int $userId): ?array`, `fileFor(int $jobId, int $userId, string $token): ?string`, `pruneExpired(): int`, and the **pure** `buildTableQuery(string $table, array $links, ?array $reach, array $realColumns, string $tier, int $afterKey, int $limit): array{sql: string, types: string, values: array, selected: string[]}` and `classifyRow(array $row, array $links, int $userId): array{tier, relationships}` that the self-test drives. Private: the row writer, the `summary.html` renderer (escaped everything, no `<table>`), the ZIP/folder finisher, the email.

The self-test runs `buildTableQuery()` over every catalogue entry against the parsed schema (check 7 in C), writes a sample `data.json` fixture, and validates the phrases. The Python check validates every `*.schema.json` and every fixture (check 8).

*Leaves the portal working because:* nothing calls the class yet.

### Step 5 — The pages, the cron, and the switch-over

**Files:** `web/_apps/auth/account/data-export.php` (rewritten as the page — zero SQL in it), new `data-export-request.php`, `data-export-confirm.php`, `data-export-continue.php`, `data-export-file.php` under the same folder; new `web/_apps/admin/users/data-download.php`; new `web/_apps/cron/data-downloads.php`; `web/_apps/auth/account/index.php` (button text: "Download my data" → "Prepare a copy of my data"); `web/_apps/help/` — a new `data-download.php` guide and the help index card; `web/_apps/privacy/index.php` wording.

Self-test check 1 (no `tbl` in `data-export.php`) applies from here. The old `?download=1` on `account/my-data` stays as the inventory it is.

### Step 6 — Event registrations: the guardian flag in the form and both routines

**Files:** `web/_apps/calendar/event-register.php` (the form — checkbox shown only when signed in), `web/_apps/calendar/event-register-save.php` (writes `submitterIsGuardian`), `web/_core/GdprEraser.php` (hand-written entries: delete where guardian, anonymise otherwise), `tools/gdpr-coverage-selftest.php` (`$deliberateDeletes` reason updated), catalogue entry (`download` rule via a `'fullWhen' => ['submitterIsGuardian' => 1]` key, or the simpler route of the classifier reading that one column by name — recommend the key, so it is in the list).

### Step 7 — The eraser matches every link column (#492 items 1 and 2)

**Files:** `web/_core/GdprEraser.php` (`fromPersonalDataCatalogue()` emits per-column; `inventory()` counts per column; new bespoke `erasePickupNames()` step; `clears` handling), `web/_core/personal-data-catalogue.php` (`clears` on `tblAssetLoans.counterpartyUserID`), `tools/gdpr-coverage-selftest.php` (per-column instruction check), `web/_apps/account/my-data.php` (shows relationships per table).

Last, because it changes what erasure does and deserves its own Codex round.

### Step 8 — Documents and the issues

`CHANGELOG.md`, `FEATURES.md`, `DEV_NOTES.md` (a "Data download" section: the tiers, the chunking, the job table, the credential rule, what is not searched), `README.md` if it lists the account features, `.claude/CLAUDE.md` counts (migrations, tables, routes, settings), `.claude/HANDOFF.md`, memory. Comment on #479 with what shipped and what remains; comment on #492 closing items 1–4 with the commit.

---

## Decisions only the owner can make

Recommendation first, then the options and what each costs.

**D1. May an administrator queue a download for somebody who asks in writing?**
- **Recommended: yes, narrowly** — portal-wide administrators only, typed reason, audit-trailed, the file goes only to the person by emailed link that still needs their sign-in, seeded off. *Cost:* one admin page and one setting; a deactivated account must be re-activated to collect.
- Alternative: no — written requests are handled by hand outside the portal. *Cost:* nothing to build; every written request is manual, and "manual" for 131 tables means somebody running queries.
- Alternative: yes, and the administrator can download it with a second administrator's approval. *Cost:* a two-person approval step that does not exist; a member's complete file in an administrator's hands.

**D2. Does an expense claim keep the claimant's identity after erasure?**
- **Recommended: yes** — `retain`, six years, handed over in full on request. The link points at a tombstone that names nobody. *Cost:* one catalogue entry; the reason text stops claiming something the database prevents.
- Alternative: make `tblExpenseClaims.userID` nullable (a migration) and unlink. *Cost:* a schema change on a financial table, and a reimbursement record that points at nobody — weaker for HMRC, and every list and report that JOINs the claimant has to cope with NULL.

**D3. What does a parent see of a child's Kids profile in their own download?**
- **Recommended: a summary line per child** — that a profile is linked to them, when it was created, and who to ask for a copy. The self-service download cannot know the account holder is the child's legal guardian. *Cost:* a parent who is the guardian has to ask a person. Consistent with owner decision 6 (the child's record is not the parent's).
- Alternative: the full profile, including allergies, medical notes and the collector list. *Cost:* the collector list names other adults; a `parentUserID` set by a leader is treated as proof of guardianship.

**D4. Encrypt the prepared file at rest?**
- **Recommended: not in v1** — outside the web root, hashed token plus sign-in, seven-day life, everything else in `_uploads` is plain. *Cost:* none.
- Alternative: encrypt with the site key. *Cost:* streaming encryption for files that may be tens of megabytes on shared hosting, a decrypt on every fetch, and inconsistency with every other file in `_uploads` unless that is done too.

**D5. Hand over `sessionDataSnapshot` and `requestHeaders` from the activity log?**
- **Recommended: yes, both** — both are already scrubbed of credentials before storage (PROVEN), #479 asked for the widening, and they are the person's own activity. *Cost:* larger activity-log files; nothing else.
- Alternative: list them as "held but not included; ask". *Cost:* the widening #479 asked for does not happen.

**D6. Seven days' retention of the prepared file, and one job per 24 hours?**
- **Recommended: 7 and 24**, both settings. *Cost:* none; a person who misses the window asks again.
- Alternative: shorter (72 hours) — safer, more re-requests; longer (30 days) — a complete file sits on disk for a month.

**D7. Should the download search free text for the person's name?**
- **Recommended: no** (E); say so, say how to ask. *Cost:* a note that mentions the person by name only is found when they ask.
- Alternative: search `notes`/`body`/`description` columns for the exact full name and email and include matches. *Cost:* other people's rows disclosed on a common name; false assurance on a variant; this is the harm A prevents.

**D8. Is `retain` for `tblCareCase` on erasure (as the catalogue's heading implies) or `unlink` (as the code does)?**
- **Recommended: leave `unlink`** — the case and its visit notes stay for the remaining team, the subject's identity is detached; the download lists it (owner decision 3). *Cost:* none; it is today's behaviour, documented.
- Alternative: `retain` — the case keeps pointing at the person after they asked to be forgotten. *Cost:* a decision the safeguarding policy would have to justify.

---

## What was not checked

- Nothing was run against a database. Every SQL claim is about the text of the schema and the code. The migration harness in CI is the first place migration 194 will run.
- The live server's `memory_limit`, `max_execution_time`, FastCGI kill threshold and whether `ZipArchive` is enabled remain unknown; the design assumes the worst on all four and degrades rather than fails.
- Whether `pr-security.yml`'s runner already has `jsonschema` available — INFERRED not; the plan installs it.
- Whether the existing `cron/*` route seeds are `isProtected 0` — check one before copying the shape.
- The statute's one-month period (J) is taken from the brief, not verified.
- No Codex review was run on this design; the standing review rule applies to the change, and the next stage (challenge) is the independent pass on this document.
