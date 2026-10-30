# Handoff archive — everything as it stood on 30 October 2026, before the restructure

**What this file is.** A verbatim copy of `.claude/HANDOFF.md` as it stood on 30 October 2026,
kept whole so that nothing could be lost when the live handoff was restructured. It is
**history, not instructions.**

**Why it was restructured.** The owner asked on 30 October 2026 whether old handoff entries
could simply be deleted rather than archived, since the work in them is finished. The answer
turned out to be no, and the reason is worth recording:

1. **Plan files cite the handoff by LINE NUMBER.** `.claude/plans/secret-settings-497-design.md`
   alone points at "HANDOFF line 264", "line 214", "lines 68-73", "line 185" and "lines
   100-102"; `.claude/plans/public-door-4-fable-2-challenge.md:83` says "HANDOFF line 138
   records the deliberate deferral". Those citations were ALREADY dangling, because the file had
   been archived twice before this. Keeping the text somewhere at least lets a reader find what
   was meant.
2. **Live content was interleaved with finished work.** The 11 October section was titled "THE
   ROLL-OUT PLAN (final; this is the queue now)" and read as current policy, but about 230 of
   its 238 lines were a minute-by-minute work log that ended "NOW: PACKAGE A2b BUILD" — stale by
   19 days. Buried inside it were owner decisions, deliberate deferrals and unresolved
   questions recorded nowhere else. Separating those by hand is exactly where a decision gets
   silently dropped, which is why the whole file was kept rather than pruned.
3. **A finished entry can still be the only record of why something was NOT done.** Delete
   those and somebody later "fixes" what was deliberately left, or reopens a settled question.

**What was wrong in it, found by the sense-check on 30 October 2026** (corrected in the new
handoff, left untouched here so the record is honest):

- **"#604 is in the roll-out set ... queue it after F6b and M4"** — #604 was BUILT; migration
  217 shipped and the time-zone work is settled. The line read as a pending instruction.
- **"NOT BUILT, moved to package B2: ending an erased person's open session"** — this WAS built,
  by a different route than the one the note proposed. `Auth::ensureSession()` runs at
  `web/public_html/index.php:67` on every request and calls
  `Auth::endSessionOfInactiveAccount()`. The note claimed an unfixed security hole that no
  longer existed, which is the worse direction for a stale claim to point.
- **An orphaned "START HERE" banner** promised that "everything needed to carry on is in this
  one block" when the block's contents had been archived, leaving the promise pointing at
  nothing.
- **A direct contradiction left unresolved:** 24 September says "One package at a time —
  REAFFIRMED ... Do not offer it again"; 12 October says packages that share no files run side
  by side. The later decision governs, and `.claude/CLAUDE.md` carries it, but nothing here said
  which won.
- **Three separate "moved to the archive" pointers** doing one job, in three different places.
- **The current-state section sat at line 597 of 996**, below 587 lines of finished work, in a
  file whose whole purpose is to be picked up quickly.

**The owner's standing approval for archiving** is decision 6 of 5 October 2026: "Handoff: move
old entries into an archive file beside it, marked as history — AFTER part 8 is committed."
Part 8 was committed as `6abed16`.

Earlier archives: `.claude/history/HANDOFF-archive-to-2026-10-29.md` and
`.claude/history/HANDOFF-archive-to-2026-10-05.md`.

---

# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-10-29 (kept current as the work happens, so it can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## 30 October 2026 — 37 calls PHP 8.5 deprecated, and a browser was here all along (#673)

**What changed.** Removed every `curl_close()` call under `web/` — **37 of them across 15 files**.
The call has done nothing since PHP 8.0 and PHP 8.5 deprecates it, so each one wrote a
`Deprecated` line to the error log every time the portal called an outside service. Added
`tools/audit-checks/check_no_curl_close.py` plus a `--selftest`, both hard gates in
`pr-security.yml` (now 30 steps). `CHANGELOG.md` and a new `DEV_NOTES.md` section record it.

**The issue's own figures were wrong.** #673 said 43 calls across 19 files. Six of those lines
were **comments mentioning `curl_close`** in the four files that had already removed it
(`SafeFetch`, `WebPush`, `MailerGoogle`, `Auth`). Counted with PHP's tokeniser: 37 calls, 15
files. Those same six comments are why the check reads the code through the tokeniser and not by
searching text — a text search reports every one of them as a fault.

**`curl_multi_close()` is deliberately not covered.** Measured on PHP 8.5.11, not assumed:
`curl_close` and `curl_share_close` raise a deprecation notice, **`curl_multi_close` raises
none**. Flagging all three would refuse a call PHP is happy with, and a check that cries wolf
gets switched off.

**Checked.** `php -l` clean on all 15; all 42 audit checks pass; the check's selftest passes in
both directions; planted calls caught at the right file and line, then restored from saved bytes.
Three suites covering three of the changed classes pass against a real `mysql:8.0.36` (schema +
227 migrations, 228 tables, 0 real failures): **QR 67/67, transcription 153/153, held texts
117/117 — 337 checks.** 0 dangling Docker volumes before and after.

### The independent check came back NOT CLEAN, and both findings were real

**1. The tripwire was blind to the spelling most likely to bring the fault back.** The check
read only `T_STRING` tokens. PHP tokenises a bare `curl_close` as `T_STRING` but a
fully-qualified `\curl_close` as **`T_NAME_FULLY_QUALIFIED`**, so the check reported **clean on a
real, live deprecation** — verified by planting `\curl_close($ch);` into `Zoom.php` and watching
it pass. That spelling is not exotic: **every file under `web/_core/` is `namespace
Portal\Core;`**, and it is exactly what php-cs-fixer's `native_function_invocation` rule writes
by itself. So the guard built to stop the fault returning was blind to the likeliest route.
Fixed to accept both token types, strip the leading backslash, and leave
`\Vendor\curl_close` alone as a different function.

**2. Four documents claimed something untrue about the blast radius.** They said every outside
call the portal made wrote a deprecation line, and listed **push** among the affected services.
`WebPush` had **already** removed its call — the comment explaining why is at lines 297-298 — as
had `SafeFetch`, `MailerGoogle` and `Auth`. So sending a push and sending mail were never
affected. This is the #487 shape: a claim about how far a fault reached that is wider than the
fault. Corrected in the check header, the workflow step comment, `CHANGELOG.md` and
`DEV_NOTES.md`. The DEV_NOTES section itself named WebPush as one of the four already-clean files
two paragraphs after saying a push wrote a line.

**And the checker's strongest "Found" was really a third fault of the same family.** The
`--selftest` exercised only `scan()`, never `main()`. Changing `if len(hits) == 0` to `>= 0`, or
neutering the vacuity guard, left **every** case green while the check reported clean on a tree
holding a real call — the untested half was exactly the half one character could neuter. `main()`
now takes its root as a parameter so the selftest calls it and asserts the exit code; the
selftest is **17 cases**, 13 on the scan and 4 on the verdict, and both mutants now go red.
Fixing it surfaced a fourth: moving the `--selftest` dispatch out of `main()` made `--selftest`
quietly run the ordinary check and report success — the #646 failure in miniature, inside the
very package that cites it.

A second, narrow round on the fix changes alone was commissioned before committing.

### Two corrections to my own earlier work, both worth keeping

**1. I said there was no browser on this machine, and that was wrong.** I had reported item 9
(the browser/visual half of #569) as blocked on the owner installing a headless browser. The
owner pushed back, correctly. **Microsoft Edge 155 is installed**, so is Safari, so is
`safaridriver`, and node 26 is on PATH. Edge headless writes a PNG at any viewport size with no
installs, and it can be driven over the DevTools protocol using **node's built-in `WebSocket`**
(global since node 22) — so the DOM can be measured, not just photographed, with **no npm
install at all**. I had checked for Playwright and Chrome, found neither, and turned that into
"no browser". **Item 9 is not blocked.**

**A real trap found while proving it, before any harness was written.** With
`Emulation.setDeviceMetricsOverride` and `mobile: true`, **Chromium widens the layout viewport to
fit the content**: a 900px-wide page asked to render at 390 reported `innerWidth` of 900. So
`document.documentElement.scrollWidth > window.innerWidth` — the obvious overflow test — is
**permanently false** and such a check can never fail. Compare the page's `scrollWidth` against
**the width the driver asked for**, which is known from outside the page. Measured on a
deliberately-overflowing fixture and a clean one; the correct comparison separates them.

**2. I overstated #677 and have corrected the issue.** I raised it as `priority: high`, said it
was "worse than #674", and claimed "two organisations, each with an administrator who fills in
their own address: whoever saves last wins". **That last claim is false.**
`web/_apps/admin/settings/organisation/save.php` refuses anybody who is not a global
administrator (`App::isRootAdmin() === false`) before any write, and tells the one person who can
save that these settings apply to every organisation on the installation. The behaviour is
deliberate and announced, not silent. The handler's **own header already documents it as an open
decision** — which I should have read before raising it. Relabelled `priority: medium`, with a
correction comment on the issue. What is genuinely left is a product decision about whether an
organisation's postal address should be per-organisation, open since 11 September 2026 and
correctly parked meanwhile.

**Lesson for both.** I stated two conclusions as facts without checking the thing that would
disprove them: "no browser" without looking in /Applications, and "whoever saves last wins"
without reading the access gate twenty lines above the code I had read. Both were caught by
somebody else, not by me.


## 30 October 2026 — the API description now has a check, and 17 missing endpoints are described (#641 part 1)

**What changed.** `web/_core/api-spec.json` had never been compared with the code that answers it.
Added `tools/audit-checks/check_api_spec_matches_code.py` (a hard gate in `pr-security.yml`),
documented the 17 reachable endpoints that were absent, gave the two described only under their
`api/v1/` address a legacy path of their own, corrected three operations that denied a legacy
address which works, and corrected three code comments that promised behaviour the code does
not have. The spec change is **purely additive** apart from those three descriptions — proved
structurally (19 paths and 6 schemas added, none removed, top-level blocks untouched) rather than by
reading a 7,944-line diff, 765 lines of which are pure reformatting.

**THREE independent check rounds, every one NOT CLEAN — 16 real problems in all (7, then 5, then
4), every one fixed and re-proved.** The rounds converged: round 1 found structural gaps in the
check itself, round 2 mostly wrong figures and a schema attached to the wrong responses, round 3
only untrue sentences in descriptions. **Round 3's fixes were verified by me against the code
rather than by a fourth round** — each was a factual claim checkable with a grep, and three rounds
is already past what this project likes; a fourth would have gone on finding wording. The four
worth carrying forward: check F could not see a handler at
`_apps/api/{sub}/{action}.php`, which is exactly where `push/subscribe`, `push/unsubscribe` and both
tours handlers sat before #322 and #363 moved them, so the one guard meant to spot an endpoint
parked out of reach would have missed all three real cases. My docstring justified the alias parse
with a FALSE MEASUREMENT (`aliases - targets` is empty; it changes nothing). And **a patch script of
mine aborted on an assertion partway through and I treated a follow-up fix for one file as having
finished the job** — two instruction files kept their wrong figures until round two found them. When
a multi-step patch aborts, re-run the remaining steps explicitly. Also: my own planting harness deleted a PHP file and
restored only the three files it watched — caught by its own closing "is it green again?" step. **A
plant that adds or deletes a file must back that file up too.**

**Checks run.** `php -l` on four files, all 39 audit scripts (0 failures, and the two that report
while exiting 0 were READ), Ruby YAML parse of the workflow (26 steps, no truncated names), spec
valid with no dangling `$ref`. **No database change, so the migration harness was not run. No HTTP
test of the 19 endpoints** — the descriptions come from reading the handlers, not from calling them.

**Still open on #641 at the end of part 1:** the JSON Schema beside the description (item 2), a
`description` on every property (item 4), and the remaining untrue statements in the issue's
section 3. **Items 2 and 4 then landed in part 2, below.** On the counting: three traversals gave
181, 274 and 286 undescribed properties, and **286 is the figure that stands** — the schema check's
independently written validator measures the same 286 against the committed file, which is why it
is now stated in the instruction files rather than left open.

**Raised.** #675 — `expenses/export` is a working CSV export whose switch was never seeded, so it has
always answered 403, and four more switches are seeded for handlers that do not exist. Both need the
owner's decision; named in the check, which fails if either stops being true.

**One more thing the third round taught, and it is the useful one.** The selftest asserted only
that the expected check fired, which cannot tell "caught by G" from "caught by G and also A". It now
compares the WHOLE SET of checks each plant trips, and declaring the set immediately exposed two
more impure plants that the old test had hidden. One was fixed; one is honestly declared as `AG`,
because seeding a handler's switch cannot help also making it reachable-and-undocumented. **A plant
that trips two checks is evidence about neither until you say so.**

**Part 2 then landed the same day.** `web/_core/api-spec.schema.json` plus
`check_api_spec_schema.py` (a third hard gate), and all 286 properties and 42 parameters described
from the handlers. The validator is hand-written because no JSON Schema library exists here, and it
**fails when the schema uses a keyword it cannot implement** — otherwise an unknown keyword would be
ignored and that part of the schema would be decoration. The selftest now plants 26 faults across
both checks; `and False` on any of eight conditions leaves a check green and the selftest red,
measured.

**What writing the descriptions found, which is the lasting value:** asset money is in PENCE and
expense money in POUNDS with no currency recorded anywhere; a great many limits **quietly shorten or
clamp a value rather than refusing it**; `licenseKey` is write-only and can never be cleared; the
attendance breakdown and the noticeboard poster list are **whole-state**, so an empty list wipes
them; and leadership's `assignmentID` is the assignment, not the role. `PaginatedListResponse`
describes a shape no handler returns, and `Asset` was referenced by nothing while the prose pointed
readers at it — both now corrected.

**Raised #676:** the events API's `status` allow-list does not match the column, so `archived` is
allowed by both writers and refused by the database (a 500), while `postponed` is returned by the
API and cannot be set through it. Not fixed because either correction changes behaviour.

**Part 3 finished #641.** Five operations described the inside of `data` as the whole reply, so
every field name was right and every path to it was wrong; `PaginatedListResponse` was deleted
rather than corrected, because it described a reply nobody sends; all 48 `Allow` examples are now
computed the way the router computes them instead of all saying `GET, POST`; 32 bearer-reachable
operations gained the rate-limit refusal they had never documented; and `events/{id}` stopped
calling itself the only v1 resource with a detail route.

**The independent check found two real problems in part 3, both mine.** The `Allow` fix ADDED the
computed example beside the stale one instead of replacing it, so all 48 headers briefly carried two
contradicting values — **the original fault survived and three documents claimed it was gone**. And
`/api/noticeboard/list`'s new schema was UNSATISFIABLE: it composed `V1SuccessEnvelope`, which fixes
`data` as an object, with a branch declaring an array, demanding both at once. Both fixed; the
envelope is now written out for that operation and its v1 twin.

**NOTHING HERE GUARDS WHAT PART 3 CHANGED.** The two checks compare which addresses exist and
whether every property has words; neither can tell a declared response shape from what the handler
sends, nor spot a stale example. The checker proved it by planting three of part 3's own corrections
in reverse and watching both checks stay green. These corrections will go stale the way the originals
did unless something calls each endpoint and compares the reply with its schema, which no harness
here does.

**AND I REPEATED MY OWN MISTAKE FROM PART 1.** A multi-step patch script aborted partway — one
replacement did not match because the text wrapped differently — and the commit that followed was
not chained to it, so part 3 was committed WITHOUT this paragraph's correction. That is the second
time in one session. **When a patch script aborts, re-run the remaining steps explicitly, and never
put a commit in the same block as an unverified patch.**

**The reusable lesson from part 3:** working out which endpoints a bearer key can reach, a text
search for `ApiAuth::requireWrite` returned 46 against a true 45 — the extra was a handler whose
COMMENT, added by this very package, says a bearer key cannot reach it. Searching for the text of a
call finds the text, not the call; strip comments with the tokenizer.

## 30 October 2026 — a form could push one organisation's setting to every organisation (#674, #668)

**What changed.** Admin, Transcription filled its boxes from the merged `$SETTINGS` while its Save
writes installation-wide, so a global administrator in an organisation holding its own value saw
that value and **pressing Save without touching anything made it the installation's, for everyone.**
Every box now reads `InstallationSettings::layer()`, and the page NAMES any setting where the
organisation differs — the difference is real, because everything that runs a transcription reads the
merged value, and nothing in the portal creates such a row, so one being there is worth knowing.

**It is FIFTEEN admin forms, not the one the issue named.** New hard gate
`tools/audit-checks/check_install_wide_prefill.py`, with the fourteen outstanding on a pending list
so the check gates from today and no new page can join them. They are deliberately not swept in one
go: each page needs reading field by field, and a page may legitimately show a merged value beside an
installation one. The list names which keys each page touches, including several API keys and two
money limits.

**The check reported its own fix as a fault**, which is why it has a term you would not expect: it
skips a page that reads the installation layer at all, because a fixed page often reads both
deliberately. Stated cost: a page fixed for one field and not another passes.

**Section S proves the harm rather than arguing it** — the old read put back makes S04 record the
installation's provider changing from `openai` to `assemblyai` on a Save that touched nothing.
149 checks in that file now, all passing against a real MySQL 8.0.36 with the full schema and every
migration replayed.

**A mistake worth recording:** undoing that plant with `git checkout --` wiped the uncommitted fix,
because the fix was not committed yet. **Restore a planted file from saved BYTES, never from git,
while the work is still uncommitted.**

**Next.** The browser/visual half of #569 — layouts at many widths, and how pages actually look.
**It is blocked:** there is no Playwright, Chromium or Chrome on this machine, and a visual audit also
needs a running portal (a MySQL container plus the built-in server). Installing a browser is the
owner's call. The static half is already covered and clean —
`check_mobile_readiness.py` reports nothing.

## 29 OCTOBER 2026, LATEST — transcription you can actually reach (#276, migration 229)

**State: built, verified, independently checked (NOT CLEAN → all fixed), committed.**

Transcription looked finished and did nothing: **no page anywhere posted to `recordings/transcribe`**,
so nothing was ever queued, no transcript was ever made, and `/recordings/search` searched an empty
table. The handler, its registered address, the transcript page and the search all existed. The
missing piece was a button.

Adding it exposed three more faults, each invisible to every check already here:

1. **The statement that SAVES a transcript could never run.** `fullText` is the reserved word
   `FULLTEXT`, so a bare `fullText = ?` does not parse — it must be qualified or backquoted. It would
   have thrown *after* the outside company was called and charged. Nothing had noticed because the
   statement was unreachable.
2. **"Process next" saved the settings.** It sat inside the Save form; HTML drops a nested `<form>`
   and gives its button to the outer one. The only control that could work the queue did nothing.
   Proved with PHP 8.4's own parser (`Dom\HTMLDocument` reports ONE form), not by reading.
3. **One organisation could see and spend another's.** Counts, month spend and the recent list
   (which carries recording titles) had no organisation filter, and "Process next" sent *another*
   organisation's audio to the provider on the installation's single API key.

**Also fixed:** the same recording could be sent twice while already with the provider (two bills);
`recordings/view.php` had no flash block at all, so every message from the new button would have been
swallowed; members were shown the provider's own error text; the transcript search was linked from
nowhere; a per-organisation admin was offered settings the handler refuses.

**New:** `tblTranscript.startedAt` (migration 229) so a job genuinely running can be told from one the
host killed — refusing `processing` outright was the wrong fix and wedged such a recording for ever.
`STUCK_AFTER_MINUTES` = 120, on the server's own clock to match `queuedAt`. And
`transcription.monthCapPence`, built to the **#668** rule: the installation's figure caps the
organisation's, read from `InstallationSettings::layer()`. £50 to start.

**Verified:** 38 audit checks (read, not just exit codes), `php -l` clean, migration 229 loaded and
replayed, **126 HTTP checks passing — also against a schema-only database, as CI runs it** — and
**21 planted faults, every one caught**. Wired into `portal-selftests.yml` (port 9081, `dom` added to
that job's extensions by name; the YAML was parsed with Ruby and no step name was truncated by its
`#`). 0 dangling Docker volumes.

**Two lessons about the checks, both from planting:**
- A plant that changes no behaviour proves nothing. Resolving "not chosen" to the default in
  `capFrom()` was caught by nothing — correctly, because `$SETTINGS` already carries the
  installation's figure, so the two branches agree. It exposed an **overstated comment** claiming
  that test was what prevented the fault. Corrected to say what actually carries it.
- A check on the TEXT of a guard stays green when `&& false` is added to its condition. Q04 was
  rewritten to pin the whole condition, the rollback and the return as ONE pattern.

**Found, not built:** **#672** nothing drains the queue on its own (deliberate — nobody should be
billed by a timer); **#673** `curl_close()` deprecated on PHP 8.5, 43 calls in 19 files, which will
fill the error log on every outgoing request.

**The independent check came back NOT CLEAN with five real problems, all fixed:**

1. **A whole queue could be lost.** `admin/transcription/run.php` checked login, role, POST and
   token — and nothing else. The only thing stopping a run with no company configured was the
   button's `disabled`. A posted request claimed every waiting row, got `…-not-configured`, and
   marked the lot FAILED. Reachable: queue rows under OpenAI, then switch to AssemblyAI with no key.
   The handler now asks `providerIsReady()`, the same question the page asks. Checks R01–R03.
2. **The self-test did not put the database back, and its clean-up comment said it did.** Proved by
   experiment: section M09 posts the real settings form, and `save.php` writes SEVEN
   installation-wide rows, four of which nothing was remembering — so a clean pass left
   transcription switched ON installation-wide. CI never saw it because each test gets a fresh
   database. Now every `transcription.%` row is read before the run and restored after.
3. **Migration 229 and `full_schema.sql` disagreed** on `startedAt`'s COLUMN COMMENT, so an upgraded
   database carried it and a fresh install did not. The harness cannot see this —
   `dump_inventory()` does not read COLUMN_COMMENT. Fixed and verified: fresh and upgraded now give
   a byte-identical column.
4. **A stale comment.** `save.php`'s header still said the page "was not changed, so an
   administrator of one organisation still sees a Save button there". This package changed exactly
   that.
5. **An untrue on-screen line.** `$cappedAbove` compared the effective cap with the MERGED setting,
   so where the installation's own figure was above the hard maximum the page told a global
   administrator their organisation had asked for more — about a number the organisation never
   touched. And the self-test was CREATING that state (J06) while checking only the figure.

**Plus seven of its observations acted on:** per-job containment so one bad row cannot end a batch;
`$cap` clamped where it is used; `bind_param` types matching the values; a zero budget given its own
reason and words; `TranscriptionRefused` no longer logged for refusals nobody can act on; the
"charged either way" claim softened to what is knowable; and Q07 rewritten to ask about the
transaction rather than "some commit before some call".

**Two more lessons about checks, both from planting the fixes:** R13 and Q04 each passed on a guard
disabled with `&& false`, because they looked for the TEXT of the guard. Both now pin the whole
condition, and R13b asserts the sweep never re-throws at all.

**After the fixes:** 143 checks pass (also on a schema-only database, as CI runs it), **27 planted
faults, every one caught**, 38 audit checks clean, migration harness 42/42 on all seven phases.

**Found, not built:** **#674** the page prefills installation-wide settings from the merged
`$SETTINGS`, so a global administrator pressing Save can push one organisation's choice to every
organisation — pre-existing, and the same shape worth checking on the translation, AI-assist and SMS
pages.

**Next:** archive this handoff (now past 1,600 lines), then item 6 (#641 in full).

---

## 29 OCTOBER 2026, LATEST — backups a customer can keep (#569, migration 228)

**State: built, verified, independently checked (NOT CLEAN → all fixed), committed.**

Three things the portal could not do: take a backup by itself, get a copy off the server, or bring
one back. `Portal\Core\BackupArchive` is the ONE place a snapshot folder becomes one encrypted file
and back, on libsodium's `secretstream`, streamed. `backup-download.php` packs and sends it;
`backup-import.php` unpacks (and deliberately does NOT restore — one audited restore path);
`cron/backup-run.php` takes one on a schedule and prunes. Migration 228 adds two settings and three
routes.

**Verified:** 38 audit checks, `php -l` clean, 47 archive checks (no database), 181 HTTP checks,
migration 228 replayed three times with a customer's own choice surviving, an old database gaining
the rows. 0 dangling Docker volumes.

**The independent check came back NOT CLEAN with four real problems, all fixed:**

1. **A HANG.** Unpacking a file whose entry declared more bytes than followed looped for ever at full
   CPU — burning a web worker until the host killed it, and leaving the half-unpacked folder behind.
   The end-of-stream test required no entry to be pending, so the one case that mattered fell through
   it. Check C11, in a CHILD PROCESS, because a hang cannot be seen from inside the hanging process.
2. **DATA LOSS.** `_backups/incoming/` — the folder the import page tells customers to use — appeared
   on the Backup page as "Unfinished — not a backup" WITH A DELETE BUTTON, and Delete removed it and
   everything in it. `DbBackup::NOT_SNAPSHOTS` is now the one list of reserved names.
3. **Unbounded key work** taken from the unauthenticated envelope and paid before anything is
   authenticated: a file claiming 1 TB never returned. Now bounded 8 KB–1 GB.
4. **A cancelled download left a full encrypted copy of the database** on the server, which nothing
   would ever remove.

**TWO LESSONS WORTH MORE THAN THE FIXES.**

First: **restoring a planted file from a scratch copy taken at the wrong moment silently undid three
finished fixes.** Only a `grep` for the new constant caught it. When planting, save the restore copy
immediately before the plant — never reuse one from earlier in the session — and grep for the fix
after restoring.

Second: **a check I wrote for the cancelled-download leak passed with BOTH guards removed.** The page
test's snapshot is small enough that the server finishes before the client gives up, so no
cancellation was ever staged and the check measured the ordinary path twice. It is now an honest
comment saying the path is unproven, in both the test and the page.

**Next:** the transcription trigger (item 5), then #641 in full, then the browser/visual half of #569.
Found and logged, not built: #668 (limit settings application-wide — a known live instance), #669,
#670, #671 (offsite backup needs a shell it will not have on shared hosting).

---

## 29 OCTOBER 2026 — backups: the reconnaissance that scoped the above

**Nothing built yet.** This is the next item on the owner's release list. What is already there,
measured rather than assumed:

- `Portal\Core\DbBackup` (3,842 lines) already does `snapshot()`, `restoreAll()`, `restoreTable()`,
  `prune()`, `listSnapshots()`, `manifestOf()`, and `WHOLE_DATABASE_ONLY` for the three tables a
  one-table restore refuses. `_backups/` is already outside the web root.
- `/admin/maintenance/backup` (709 lines) offers `snapshot_now`, `restore_full`, `restore_table`,
  `delete` and `check_snapshot`. So manual snapshot and restore WORK.
- `cron/backup-check.php` + `_backup-freshness.php` check how old the newest snapshot is — they do
  not TAKE one.

**So the three missing pieces are exactly the owner's list:** a scheduled snapshot, a download, and
an upload/import. Plus the owner's choice for protecting a downloaded snapshot: **a key the global
administrator defines (the default), with including the portal's own key file as a selectable
addition** — safest by default, flexible when moving host.

**A real finding, now #671.** `/admin/maintenance/offsite-backup` looks finished — a settings page,
six seeded `backup.offsite.*` settings, a Run button — but `offsite-backup-run.php` line 163 runs
`sync-offsite.sh` through `exec()`. This product targets DreamHost shared hosting with no command
line; the script is not in the repository and `rclone` cannot be installed there. It ships switched
off, so nobody is silently misled, but the page says none of this. Recommended a standing notice now
and a decision on remove-or-rebuild once the download and upload exist.

**Things to be careful of when building it, from this package's own experience:**
- A snapshot carries personal data. A download is the one place it leaves the server, so the
  encryption choice is load-bearing and the key must never be written to a log or an address.
- `DbBackup::WHOLE_DATABASE_ONLY` exists because a one-table restore of `tblMigrations`,
  `tblTotpBackupCodes` or `tblSettings` can lose data. An IMPORT of a whole file has the same
  exposure and more.
- A scheduled snapshot runs under a job token; `Logger::withoutJobToken()` is the one place that
  strips it from anything recorded.
- Shared hosting has a request time limit. `snapshot()` already works within one; an import of a
  large file may not, and that has to be measured rather than hoped.

---

## 29 OCTOBER 2026 — repeating events, and the installation-caps-organisation rule (#569, #668, migration 227), commit `6c6b854`

**State: COMMITTED as `6c6b854`. Not pushed — pushes are the owner's.**

**What changed.** The portal could not create a repeating event at all: no repeat control on the
event form, and nothing had ever written `tblRecurrenceRules`, while FEATURES.md listed the feature
as shipped. Now:

- `web/_core/EventRecurrence.php` — the date generator. NOMINAL lengths (a wall-clock reading plus a
  zone), deliberately the opposite of `IcsReader`'s exact-length rule, with the reason written beside
  both. Skipped days rather than moved ones. Both ceilings report when they cut a series short.
- Repeat controls on the create form (creation only, on purpose — #669 is changing an existing
  series), and `calendar/manage/save.php` writes the series, the rule, and each date as a COPY of the
  row just inserted. `calendar/export.php` already collapsed a series-with-a-rule into one `RRULE`,
  so the calendar feed started working with no change to it.
- **How big a series may be is a setting at two levels** (`/admin/settings/repeats`, migration 227),
  and the owner's rule is that **the installation's limit CAPS an organisation's** — lower is allowed,
  higher never. The two levels may be different people, so it is enforced in the handler and the
  number is refused with the reason, never quietly clamped.
- `web/_core/InstallationSettings.php` — the installation-wide settings layer, kept for the request.

**The trap that cost the most, and the rule it proves.** The layer began as a third global,
`$SETTINGS_INSTALLATION`. `Router.php` does `global $mysqli, $SETTINGS;` before loading a page and
**those two are the only things a page inherits** — so the form and the settings page read an empty
array and an organisation's 500-date series sailed past an installation limit of 6. Found by the HTTP
test, not by reading. Widening the two-variable rule was rejected; a named class is the answer.

**Verified:** 38 audit checks, `php -l` clean, 60 generator checks, 126 HTTP checks against MySQL
8.0.36, migration 227 replayed three times (one row each), a customer's own chosen value survives the
replay, an old database gains the rows. **Seventeen planted faults, all caught.** 0 dangling Docker
volumes before and after.

**TWO rounds of independent check, nine real faults between them, all fixed and guarded** (sections
L and M of the HTTP test, sections I and H of the generator test).

Round 1 (seven): the repeat block re-read `$_POST['seriesID']`, bypassing the #514 validation, so an
administrator of one organisation could post another's series number and the copies landed in it;
the feed published an endless series where the diary held five dates, and a 400-date series where the
installation allowed five (a limit that held for `tblEvents` and not for what the installation
PUBLISHES); a date pushed out of the missing hour lost its length; and two checks had been pinning a
message that told people their series was cut short when it was not.

Round 2 (two, both in the join between the dates made and the pattern published): `array_slice($dates,
1)` assumed the typed start satisfied the pattern, so a Wednesday start with Thursday ticked threw
away a real occurrence AND left the diary holding a day the pattern never makes; and the portal counts
"every other week" from Sunday while RFC 5545 defaults to Monday, so "Sunday and Wednesday, every
fortnight" sent three of its six dates wrong to every subscriber.

Round 3 (two): the invariant was being enforced in the SAVE HANDLER, and round 3 proved that cannot
work — **attaching an ordinary one-off event to a repeating series, two clicks on the event form and
nowhere near the repeat code, left a PUBLIC event that appeared in nobody's subscribed calendar at
all**, because collapsing a series skips every other row of it; and moving one date made the feed
advertise a date that had never existed. Also, the R4 gap comments claimed the opposite of what the
code measurably does (the old fictional reading resolved to the SAME instant, and the occurrence is
not really longer — only looks it).

**THE DESIGN THAT CAME OUT OF IT, and the thing to remember:** the guarantee lives in
`calendar/export.php`, at the one place a pattern is USED. `$patternStillTrue()` expands the stored
rule and requires it to equal exactly the rows in hand. A writer cannot know about every later edit;
a reader can always ask. Comparing COUNTS is not enough (moving a date leaves the count unchanged),
and it compares against the rows THIS VIEWER MAY SEE — otherwise a series holding an event somebody
may not see would collapse and publish a count covering it.

Round 4 (two): a stale CHANGELOG paragraph left standing beside its own correction, saying the
opposite of the code; and the comparison looked at START readings only, so renaming and lengthening
one occurrence left the series collapsed — the renamed public event in nobody's calendar and the old
finishing time still advertised. It now compares the ends AND every field the entry renders
(eventName, description, locationName, locationAddress, status, timezone, isAllDay), named explicitly
rather than "everything but the dates".

**A lesson about PLANTING from round 4, worth more than the fix:** section P changed the name AND the
end together, so the two halves of the guard masked each other — each could be deleted with all 106
checks still green. Isolating checks (P11–P16, one changed thing each) were needed. And a `perl -0pi`
plant that does not match is SILENT; three plants were believed to have passed when they had never
applied. Plant with an assertion that the text was found.

**The owner's decision of 29 October 2026, after round 4:** keep the collapsing AND make it work for
a series that has already started. The first version compared against the rows in hand, and the
whole-calendar download carries only events from today on — so an ongoing weekly service could never
match its own pattern. `$seriesRunForViewer()` now reads the whole run under the request's own
visibility rule with NO time filter, and the entry starts where the series starts. The two filters
had to be told apart: time must not stop a pattern being used, visibility must.

Round 5 (three, ALL comment/document claims — no wrong behaviour): a DEV_NOTES paragraph saying "a
series stops being tidy about a week after it begins", which the owner's change had just removed, left
standing beside its own correction; a comment naming the MAP POSITION as something the calendar file
does not render, which it does (as a GEO line), so a pin moved on one date was published for every
date — the coordinates are compared now; and the substitution's own justification, which claimed
every rendered field was compared when `uid`, `url` and `lastModified` never can be. That third one
led to taking all five from the series' first row, which also removed a weekly UID churn nobody had
noticed.

Five rounds on one package is well past the project's "say so after three" threshold, and the same
item failed all five — which is what the rule means by "simplify rather than refine": three
handler-side guards became one question asked by the reader. The remaining known limit is #670.

Fixing round 2 also introduced and caught a bug of its own (the copy loop ended up inside the
rule-writing condition, so joining an existing series made one event and no repeats — check M10).

#670 records what the feed still cannot do: a single date cancelled or moved later is invisible to
subscribers, because the feed sends the pattern and there is no `EXDATE`.

**Next:** commit this, then the backup package (scheduling, download, upload) — the next item on the
owner's release list.

**#668 is the big one left behind:** the installation-caps-organisation rule is now written down as a
standing rule but has NOT been swept across the application. 30+ limit-shaped settings, and one known
live instance — `/admin/settings/attendance` writes `attend.detailRetentionDays` per organisation,
0–3650 days, with no installation cap.

**Still over the archive threshold:** this file is ~1,400 lines against a ~1,000 line limit. Flagged
again, not acted on.

---

## 29 OCTOBER 2026 — #569 part one and the release-readiness batch, commit `1e4da0e`

**What changed.** Two bodies of work in one commit, 54 files. (1) #569's head tags: one shared
`web/_core/templates/head-meta.php` writes the meta description, canonical address and Open Graph /
Twitter tags; `header.php` and the twelve own-`<head>` pages all use it. Plus the pre-push hook
(`.githooks/pre-push`, `tools/install-git-hooks.sh`), a hard-gate audit check and an HTTP self-test.
(2) The release batch the owner picked from a ranked table: offboarding that erased nothing now files
an audited erasure request with a configurable mode (migration 226); fifteen apps switched on for a
new install (migration 225); admin-only apps out of every member's menu; seven dead notification
switches removed; GoCardless unselectable; sermon notes shown; the podcast address shown; six
unlinked pages linked; the GMN check implemented; stale names in FEATURES.md corrected; standing
notices on AI Assist and Zoom.

**Owner decisions taken during the run.** Erasure timing is CONFIGURABLE (`on_request` default,
`after_undo_window`), with immediate erasure logged as #663. The offboarding form has TWO options,
honestly named. Notices added for AI Assist and Zoom now. Repeating events are to be BUILT before
release (not started — see below). Transcription's trigger is to be ADDED (not started). Backups
need scheduling plus download/upload, with a global-admin-defined key by default and including the
key file as an opt-in (not started).

**What was learned, worth keeping.** The erasure sweep must join to the NEWEST offboarding row, not
any row for that person — otherwise one old row makes a request filed today due tonight and the undo
window is bypassed. It must CLAIM the request with the same conditional UPDATE the staff page uses,
or the Execute button stays armed mid-run and GdprEraser's own put-back never applies. `||` is
logical OR in MySQL, not concatenation. Removing a preference switch is two changes, because
`notifications-save.php` rebuilds the whole column. Removing a feature must not remove the test that
guards a past fault (#612). A `disabled` control is not submitted, so disabling the GoCardless option
would have switched the provider silently. Full detail in DEV_NOTES.md.

**Checks.** 38 audit checks, `php -l` on 54 files, MySQL 8.0.36 (schema loads, both migrations replay
twice, upgraded old DB equals a fresh install), page-head 15/15, asset-identifier 15/15,
go-live 127/0. 0 dangling volumes. NOT done: the browser/visual half of #569, MySQL 8.4 / MariaDB,
the e2e harness, offboarding end to end over HTTP.

**Review.** Fresh Opus agent, NOT CLEAN, nine real findings, all fixed before the commit. Codex has
not seen any of it.

**Next, in order.** (1) Repeating events — the largest item and the one that affects daily use; the
reading half is already built and tested, this is the writing half. (2) Backup scheduling plus
download and import. (3) Transcription's trigger. (4) #641 in full. (5) The browser/visual audit for
#569. Owner still needs to push: `hotfix/sign-in-1.4.2` (`75e83a1`) and everything on
`claude/alpha-wip` through `1e4da0e`.

**Housekeeping note.** This file is now about 1,340 lines, past the ~1,000 at which CLAUDE.md says
older entries should move into the archive beside it. Not done here: it is a restructure of a shared
document and should be its own change, not folded into a code commit.

---

## Older work entries — moved to the archive

Everything from the database-shape audit (#487 task 6, `962269a`) back to 26 October 2026 is in
`.claude/history/HANDOFF-archive-to-2026-10-29.md`, moved there on 29 October 2026 when this file
passed 1,600 lines. Earlier still: `.claude/history/HANDOFF-archive-to-2026-10-05.md`.

**Only work entries were moved.** Every standing owner decision is still below, because those are
policy and not history.

## Read this first — where we are right now

**OWNER, 13 OCTOBER 2026 — three answers and one instruction about how to ask.**
1. **`gitleaks` is installed** on this Mac (Homebrew, 8.30.1), with the owner's yes. The secrets search can now really run on a package; F6b's fix builder was told.
2. **#604 is in the roll-out set** (events' two time-zone columns). Queue it after F6b and M4: it touches the events API's detail answer, `Venues.php`, the feed importer and possibly a migration (217 is the next free number unless M4 took it; M4 did not).
3. **The public history IS to be rewritten to remove the owner's real name.** NOT YET DONE; do nothing destructive without the answers below. Facts (13 October): 805 commits on six remote branches and two tags; the name is in file contents in about 23 commits, in no commit message, and in the author or committer identity of 134 commits (a name, and an e-mail address holding the first name); the repository is public with no forks; two open pull requests (Dependabot, #601 and #602); `main`, `alpha` and `beta` refuse forced pushes (the owner must lift that for the push); a push to a release branch starts the deploy workflow (it must be switched off for the push, or it deploys); this Mac's settings refuse a forced push for the assistant, so the owner runs the push or lifts that rule. What a rewrite cannot do: reach copies somebody already made, or GitHub's own kept copies of old commits (that needs a request to GitHub Support). Every commit number written in the handoff, the changelog and issue comments stops matching afterwards. The owner was asked, with the question tool: WHEN; whether the 134 identities change too; who runs the push. **Their answers are recorded below this note when given.**
4. **Questions go through the question tool from now on**, not as lines under "Blocked on me" ("They are getting missed just as messages in that chat"). Memory note: `ask-decisions-with-the-question-tool`.
5. **THE OWNER'S ANSWERS ON THE HISTORY REWRITE, 13 October 2026 (given through the question tool; settled, do not ask again):**
   - **When:** ONCE, just before the pull request, when all queued work is committed. Not before.
   - **Attribution:** YES: every commit's author and committer become `Salem874` with GitHub's private no-reply address (`Salem874@users.noreply.github.com`), as well as the name coming out of file contents.
   - **The push:** the assistant PREPARES AND REHEARSES in a separate copy (a mirror clone outside the working folder), proves the name is gone from every commit, message, file and identity on every branch and tag, and that every file is otherwise byte for byte the same; then gives the owner the exact commands and a checklist. **The OWNER** unlocks `main`, `alpha` and `beta`, switches the deploy off, runs the forced push, and locks up again. **The assistant never force-pushes.** The checklist must also say: close or re-make the two Dependabot pull requests; every other copy of the repository must be downloaded afresh; ask GitHub Support to purge cached commits; commit numbers quoted in documents and issues stop matching.
6. **The old installer on the first customer's live address: CLOSED.** Asked what to do about it, the owner answered: "There is not current install/distributed package". Nothing is installed or distributed there now. Do not raise it again.

**PRIVACY, 12 October 2026 evening — the maintainer's real name was in seven tracked files** (found by the F4 builder in one; a search found the rest). Six are corrected in `160ac36` (a code comment, the sample inviter in the e-mail template preview, four old plans and the handoff archive, which recorded the local folder path). The seventh, `docs/day2-support.md`, is corrected in F4's fix round because F4 is changing that file. No commit MESSAGE holds the name. **The name is still in this PUBLIC repository's history (since `fa7967b`, 31 May 2026); rewriting history is the owner's decision and has been put to them once. Do not rewrite history without an explicit instruction.** Every agent's brief already says to write Salem874.

**OWNER, 12 OCTOBER 2026, about 17:45 — do not go over the same ground twice.** The owner was asked about the sign-in lockout rule
(5 wrong tries, 15 minutes) twice, in two forms, and said so: "we should not be re-going over the same things again. this just
causes elongated time to work and wasts usage credits/tokens!" From now on: search this file before putting any question, and never
ask again in another form; a build detail that serves an answer already given is decided here and reported in one line; each package
gets ONE independent check and at most ONE fix round, committed on the fix builder's proofs; shorter updates. The lockout rule is
settled: no more than 5 wrong tries in any 15 minutes, as built.

## OWNER DECISION, 12 OCTOBER 2026, ~06:05 — packages that share no files run SIDE BY SIDE; work autonomously

The owner, on the pace: "Let packages that share no files run side by side. Also run autonomously, but do ask me questions if you
need any clarifications or decisions." Written into `.claude/CLAUDE.md` ("Deep analysis"), `.OpenAI/CONTEXT.md` rule 3 and memory.
How it is run: each builder gets its exact file list; `CHANGELOG.md`, `DEV_NOTES.md` and `FEATURES.md` are never edited by two
packages at once (a builder working alongside another writes its entries to `<evidence>/DOC-ENTRIES.md`, added at commit); **stage each
package by ITS OWN file list — never from `git status`, which now mixes packages**; own named containers and ports.

## WHERE THINGS ARE, 12 OCTOBER 2026 — moved to the archive

That day's progress table (packages H1 to H8, F1 to F9, M1 to M4 and the rest, each with its commit and what its
checks found) is history, and it is now in `.claude/history/HANDOFF-archive-to-2026-10-05.md` under the heading
"APPENDED 27 OCTOBER 2026". Moved on 27 October 2026 when this file passed 1,000 lines, which is what the owner asked
for on 5 October. **Nothing a decision depends on was moved:** every "OWNER DECISION" section is still below, in
place and unchanged.

## OWNER DECISIONS, 11 OCTOBER 2026 — THE ROLL-OUT PLAN (final; this is the queue now)

The owner: "we really are wanting to get through this so we can try and finalise and begin roll-out usage — so lets focus on this and
cleaning up anything else now". Asked up front, with facts from `.claude-work/resume/rollout-issue-status.md` (85 open issues
classified: 37 already fixed on this branch, 38 not started, 7 partly):

1. **The first roll-out is an organisation the owner is involved in — AND it includes #499 (zip installer), #500 (no built-in
   address) and #502 (multi-organisation "session" mode).**
2. **Must-fix before roll-out — "the roll-out set":** #510 (erasure stops part-way, critical), #535 (erasure skips four tables), #545
   (self-approval of expenses), #547 (my-volunteering crashes), #568 (wrong library fingerprints), #570 (wrong app name), #563
   (`set_time_limit` crash), #478 (a switched-off app's data endpoints still answer), the rest of #534 (items 3-4), #488 (make the
   recovery runbook true). **Everything else waits until after roll-out** (#569's audit and push check, #564, the upgrade test and
   #553-#556, #558-#562, #549 and the rest) unless the owner pulls it forward.
3. **#514 parts 9, 10 and 11 are finished BEFORE roll-out** (not deferred).
4. **Codex: the riskiest parts only, NOT a gate, and only once the queued items are done.** A fresh Opus agent's independent check
   before a commit stays available as an option. **"Minimise multiple rounds of code reviews, wasting potential codex
   usage/credits/tokens."** So: one independent check per code package, sized to the risk; tiny wording-and-test fixes after it are
   committed on the builder's proofs without another round; Codex gets ONE lean, security-first pass over the riskiest areas at the end
   (sign-in and permissions, erasure, expenses and payments, calendar import), split across its resets, blocking nothing.

**#514 PART 8 IS COMMITTED: `6abed16` (11 Oct ~17:35), pushed; #514 commented.** 799 checks; final whole-part check by an Opus
stand-in for Codex; the last narrow check's two LOW faults + four uncaught preview faults fixed and committed on the builder's proofs
(no further round, per decision 4). Follow-ups opened: #571 (a very long series can lose dates on an every-date choice), #572
(migration 207's deprecated `VALUES()`), #573 (the result message off screen on a phone). Still to record for the Codex pass: part 8
is one of its "riskiest parts". Evidence for all 46+ rounds: `.claude-work/resume/p514-p8-*`.
**THE ROLL-OUT SET IS PLANNED** — `.claude-work/resume/rollout-set--plan.md` (407 lines; no database change; every fix has a check
that fails today). Packages, one in flight at a time, ONE independent check each: **A erasure** (#510, #535 — Opus; #535's four named
tables already have instructions, what is really skipped is `tblExternalFeedRuns`, `tblRecording.presenterID` and eight catalogue
entries); **B access and money** (#478, #570, #545, rest of #534 — Opus; two confirmed leaks: `calendar/api/hub-resources.php`,
`hub-videos.php`); **C broken pages** (#547, #568, #563 — Sonnet; a FOURTH wrong fingerprint in `web/_install/index.php:1286`);
**D the recovery runbook** (#488 — Opus; commands must be proved by running them). The plan's decisions 1-7 follow its
recommendations. Found list F1-F11 in the plan.
**OWNER, 11 Oct ~18:10:** (8) **Gift Aid declarations are KEPT on erasure, with the reason recorded** (builder A told); (9) **the
twelve tables that link to an account but are not in the erasure list JOIN the roll-out set** — package A2, straight after A (same
files), once the owner has settled each table (builder A lists what each holds); **fresh install or upgrade: "not sure yet" → #553
and #555 JOIN the roll-out set** as package E, with a test that upgrades from the last release; the stray folder — DONE (the #516
report moved to `.claude-work/resume/p516--fix-r1.md`; the empty misnamed folders removed).
**PACKAGE A BUILT (11 Oct ~19:30), NOT committed — nine files, snapshot `.claude-work/resume/rollout-A-snapshot/`:** an erasure now
finishes (every erasure used to crash on `fk_ea_request` — #510 reproduced on a real database), keeps and closes its own request last,
writes one audit line per instruction; Gift Aid declarations KEPT (owner); `tblExternalFeedRuns`, `tblRecording.presenterID` and others
now reached; four lists said truthfully to be unreachable; `/account/my-data` lists the person's own request again. 68/68 and 26/26;
16 planted faults caught. The builder went beyond the plan in six small ways (its report) — the checker judges them.
**NOW: PACKAGE A's ONE CHECK** (fresh Opus, Codex stand-in; brief `.claude-work/briefs/rollout-A-check.md`; report
`.claude-work/resume/rollout-A--check.md`; watchdog).
**PACKAGE A IS COMMITTED: `3750fb0` (11 Oct ~20:40), pushed; #510 and #535 commented.** One independent check (Opus, Codex stand-in:
a real erasure on linked records — nothing lost or leaked, permissions hold); its one untrue audit line (`tblCareVisit`) and four
test gaps fixed and committed on the builder's proofs, no second round. 79 + 26 checks; 21 faults caught.
**NOW: PACKAGE B BUILD** (access and money: #478, #570, #545, rest of #534; fresh Opus builder; brief = the plan's Package B; report
`.claude-work/resume/rollout-B--build.md`; watchdog). Then ONE check, then commit; then C (Sonnet), D, E.
**Research, report 1 DONE** (`.claude-work/resume/donation-retention-research.md`, 24 countries): periods run from 3-4 years (US) to
10 (Germany, Italy, Philippines); most count from the END OF THE YEAR of the last gift, not the gift date; several extend during an
audit (→ a "hold" switch); donor-identifying records required in 15 of 24, not in Australia, NOT FOUND for 7; most data-protection
laws read allow keeping what another law requires. Report 2 (38 more countries, every continent) still running.
For A2 from A's check: a "processing" request of the same account stays stuck; another ORGANISATION's waiting request is closed too;
deleting a Zoom account cascades into kept `tblZoomMeeting`; a pledge delete empties `pledgeID` on kept gifts; more unlisted "who did
it" columns (`tblExpenseClaimPayments.paidByID`, `tblKidCheckins.checkedIn/OutByID`, `tblErasureRequest.processedByID`,
`tblCareVisit.followUpAssignedToID`); nothing can put back a request stuck at "processing"; Cancel can cancel a completed request.

**PACKAGE A2 — THE OWNER'S DECISIONS ARE COMPLETE (11 Oct ~21:15): `.claude-work/briefs/rollout-A2-decisions.md` is the brief.**
Others' records: keep, clear the worker. "Who did it" columns: keep the record, clear who did it. Giving records, country-neutral:
identity kept only on tax-related gifts by default (organisation may switch to all gifts), for a per-organisation number of years
counted from the END OF THE FINANCIAL YEAR of the last gift, suggested per country from a JSON data file (source strength + link),
**10 years, clearly flagged, for an unknown country**, confirmed once by an administrator, a "hold" switch for audits, cleared
automatically by the retention job. Research 2 DONE (`…-research-2.md`, 40 countries: 5-10 years; donor records follow the general
tax/accounting period almost everywhere; six countries' erasure exception weak or unclear). **Running now (all with watchdogs):
the A2 PLAN (Opus, read-only) → `.claude-work/resume/rollout-A2--plan.md`; research 3, the rest of the world →
`…-research-3.md`; a re-check of the weak entries against primary sources → `…-research-recheck.md`** (the owner asked whether to
expand the research: yes, in the background — it only adds rows to the data file and blocks nothing).

**A2 IS PLANNED AND DECIDED (11 Oct ~22:00).** Plan: `.claude-work/resume/rollout-A2--plan.md` (two packages: **A2a** others'
records + "who did it" columns + smaller items + "Delete my account" filing an erasure request + photo and biography cleared —
migration 208, Sonnet per the plan but see the note; **A2b** the giving-records feature — migration 209, Opus). **The owner's answers
override the plan where they differ — all in `.claude-work/briefs/rollout-A2-decisions.md` section 5:** a country PER ORGANISATION
now; statement PDFs KEPT with the tax evidence; no clearing before "confirmed"; minimum suggestion 6 years; "Delete my account" files
an erasure request (in A2a); the new tests go into the pull-request checks (workflow edit APPROVED, one change after A2b).
Research 3 DONE (`…-research-3.md`, 147 rows: the rest of the world; mostly one 2019 secondary survey). Re-check of weak entries
still running (`…-research-recheck.md`).
**PACKAGE B IS BUILT (24 files, NOT committed; snapshot `.claude-work/resume/rollout-B-snapshot/`) and IN ITS ONE CHECK** (fresh Opus;
brief `.claude-work/briefs/rollout-B-check.md`; report `.claude-work/resume/rollout-B--check.md`; watchdog). Built: the app switch
now gates every `/api/…` address of a switched-off app (two hand-written lists in `AppRegistry`; an unlisted `api` folder is
REFUSED); nobody can approve, reject or pay their OWN expense claim (`ExpenseRules`, new); the two Team Hub data endpoints and the
venue booking card follow `EventVisibility`; the "app is disabled" page names the right app. 77 + 46 + 829 checks; 17/17 faults.
**OWNER: ALL of builder B's Found items join the roll-out set → package B2** (plan after B commits): `admin/calendar/
registrations-act.php` has no organisation test; `attendance/record/save.php` lets any signed-in account record figures; the venue
booking itself shows across organisations; the Team Hub data endpoints are wider than the Team Hub page; `livechat/api/list.php` and
`prompts.php` need no sign-in (with #548); `live/index.php` reveals which event numbers exist; three handlers store any event number
they are sent (`kids/checkin-do.php`, `salvation/card-save.php`, `livestream/api/ping.php`); expense handlers redirect to bare
addresses; the `AppRegistry::all()` comment; `FEATURES.md` and `api-spec.json` not updated for B.
**PACKAGE B IS COMMITTED: `6223e2e` (11 Oct ~23:10), pushed; #545, #478, #570, #534 commented.** One check: PASS, no real fault. Seven
planted faults that passed the tests were closed afterwards (tests only; the access and money code byte for byte as checked — I
compared it with the snapshot). New committed tests: `tools/expense-rules-selftest.php` (50), `tools/app-switch-selftest.php` (86
with a database), `tools/access-pages-selftest.php` (94, drives real pages; must not run at the same moment as
`feed-admin-selftest.php` on one database); `feed-admin-selftest.php` is now 831.
**NOW: PACKAGE A2a BUILD** (fresh OPUS builder — the plan said Sonnet, but this decides what personal data is deleted or kept and now
rebuilds "Delete my account"; brief = `.claude-work/briefs/rollout-A2-decisions.md` + the plan's A2a; migration 208; report
`.claude-work/resume/rollout-A2a--build.md`; watchdog). Research re-check DONE (`…-research-recheck.md`: 29 entries, 24 now on
primary text; Philippines 5 by statute but keep 10 as the cautious figure; Bangladesh has no official figure; Zambia 6; Ecuador,
Zambia and Morocco have clear exceptions; France 10 confirmed) — A2b's builder merges all four files.

**PACKAGE A2a IS BUILT (12 Oct ~01:00; 19 files, NOT committed; snapshot `.claude-work/resume/rollout-A2a-snapshot/`) and IN ITS ONE
CHECK** (fresh Opus; brief `.claude-work/briefs/rollout-A2a-check.md`; report `.claude-work/resume/rollout-A2a--check.md`; watchdog).
Built: other people's records kept, worker cleared (delete on an "acted on it" column now refused by construction); the eraser builds
one instruction for EVERY person column a table has (195 instructions; 31 of 37 extra "who did it" columns cleared); the person's own
visitor record deleted; photo, biography, `calendarToken`, `notifyPrefs` cleared; **"Delete my account" files a CONFIRMED erasure
request through `GdprEraser::fileRequest()` and changes nothing itself**; an administrator can "Confirm on the person's behalf";
a request stuck at "processing" can be put back (15 minutes, `claimedAt` in UTC); migration **208** (four columns emptiable;
`tblErasureRequest.claimedAt`), proved on 8.0.36 and 8.4.11 and from the previous release. 126 + 40 checks; 75 faults caught.
**Six columns left pinned in `KNOWN_STILL_NAMED`, needing decisions:** five may not be empty (`tblAssetLicenseAssignments.linkedByID`,
`tblAssetLoans.requestedByID`, `tblOffboarding.offboardedByID`, `tblResourceBooking.bookedByID`, `tblRotaSwapRequest.requestedByID`
— a migration); `tblInvitation.acceptedByID` (the row holds the invited person's own email address — delete an accepted invitation
with its person?). **For the owner's safeguarding lead:** who made a pastoral visit, and who recorded a DBS check, are now cleared.
Builder's other Found items: its report, list items 2, 4, 6-13, 16, 17.
**PACKAGE A2a IS COMMITTED: `f58fdd2` (12 Oct ~02:10), pushed; issue #574 opened for it (and for A2b).** 26 files; migration 208
(nine "who did it" columns emptiable; `tblErasureRequest.claimedAt`); 132 + 43 + 38 checks on 8.0.36 and 8.4.11; migration test on
both; upgrade from `6223e2e` equals a fresh install; 115 faults caught. No second check (owner's instruction).
**NOT BUILT, moved to package B2:** ending an erased person's open session — the portal keeps sessions as PHP's own files, and
`Auth::check()` (`web/_core/Auth.php:556`) asks only whether the session holds an account number; it must also confirm
`tblUsers.isActive = 1` once per request and destroy the session when not. **Also for B2:** the erasure pages write bare addresses
(`/admin/erasure-requests/...`, `/account/...`) instead of `Site::url()`, so in first-part-of-the-address mode their buttons reach
the default organisation (read, not run; #536's family). **Decisions still open:** `tblWorkflowSteps.assigneeValue` (an approval
step given to one named person — what should it become when they are erased?); the safeguarding names (cleared as built).
**NOW: PACKAGE A2b BUILD** (giving records, country-neutral; fresh Opus builder; brief `.claude-work/briefs/rollout-A2-decisions.md`
sections 3 and 5 + the plan's section 2; migration 209; the four research files merged into a JSON data file with a schema and copied
to `docs/research/giving-retention/`; report `.claude-work/resume/rollout-A2b--build.md`; watchdog). Then ONE check → commit → B2 → C
→ D → E → #499/#500/#502 → #514 parts 9-11 → the workflow edit → documentation sweep → Codex pass.

**PACKAGE A2b IS BUILT (12 Oct ~03:30; 30 files, NOT committed; snapshot `.claude-work/resume/rollout-A2b-snapshot/`) and IN ITS ONE
CHECK** (fresh Opus; brief `.claude-work/briefs/rollout-A2b-check.md`; report `.claude-work/resume/rollout-A2b--check.md`; watchdog).
Built: `GivingRetention` (new) + `tblGivingKeptDonors` (migration **209**, seven settings, two addresses); a donor's name and address
kept on tax-related gifts (or all gifts, the organisation's choice) until the end of the financial year of the last kept gift plus the
organisation's years; a country PER ORGANISATION (`giving.retention.countryCode`); suggestions from
`web/_core/data/giving-retention-countries.json` (211 countries, schema + check; never under 6; flagged 10 when unknown); nothing
cleared before "confirmed" or while on hold; statement files kept with the evidence; the page Admin → Giving records
(`/admin/settings/giving-records`); research copied to `docs/research/giving-retention/`. 54 + 53 + 188 + 48 checks on 8.0.36 and
8.4.11; 71 faults caught. The builder left the plan in twelve places (its report) — the checker judges them.
**A2b's ONE CHECK (12 Oct ~04:15): NOT CLEAN — one MEDIUM, three LOW** (end of `.claude-work/resume/rollout-A2b--check.md`; proofs
`scenario2.php`, `scenario3.php` in its evidence). Held: nothing cleared early on any not-confirmed, hold, date, year-end, zone or
other-organisation path (21 donors, 12 organisations; 997 instants in 15 zones); no name kept without a reason; the words never state
a law as fact; data file sound; migration 209 right. **F1 MEDIUM:** a kept row made by a run that did NOT finish is never brought up
to date and the clearing guard never checks the account was emptied — a gift recorded between a stopped run and its re-run is
cleared a year early; and if the stopped request is cancelled, a LIVE member is treated as an erased donor (`isErasedDonor()` true)
and the sweep took the donor off a five-day-old gift. F2 an untrue privacy-page sentence; F3 no test for "country change un-ticks
confirmed"; F4 a changelog sentence.
**NOW: A2b FIX ROUND (same Opus builder)** — F1-F4, plus: keep a self-downloaded statement when every gift on it is kept; lock the
seven settings out of the general `/settings` editor; show the installation-wide and the organisation's hold separately; an open
count's envelope keeps its giver for a kept donor; Uruguay's flag; a test for the "no request left" guard. Watchdog on
`.claude-work/resume/rollout-A2b--build.md`. **THEN a NARROW independent check of the F1 fix only** (tax evidence cleared early
cannot be undone — a check sized to that risk; everything else goes in on the builder's proofs) → commit → B2.

**A2b FIX ROUND 1 DONE (~05:10)** (F1 by four rules: "emptied" = switched off AND "[Deleted User]" AND a deleted address, one
definition; keep-until from the LATER of stored date / newest linked gift / newest linked envelope; later runs recalculate and never
write a made-up name over a real one; a request whose giving step has run cannot be cancelled. 210 + 59 + 56 + 54 checks on both
versions; 43 faults). Snapshot `.claude-work/resume/rollout-A2b-snapshot2/`.
**NARROW CHECK OF THAT FIX (~05:40): NOT CLEAN — it found a fault the fix introduced** (end of
`.claude-work/resume/rollout-A2b--check2.md`; proofs `c2-scenario.php` E3, `c2-race.php`): **C2-1 MEDIUM** — when the account is
ALREADY emptied, a later run re-sorts the gifts by TODAY's "which gifts" rule and its "nothing to keep" branch empties the kept name
with no guard (no hold, no confirmation, no date); C2-2 LOW a milliseconds gap (the clearing decides before its transaction); C2-3
LOW untrue words ("the account is emptied last" — it is step 101 of 207). Everything else in the four rules held (four stops of one
request; seven near-miss accounts; every way round the cancel refusal).
**NOW: A2b FIX ROUND 2 (same builder)** — C2-1 (sort by the rule STORED on the kept row; never the unguarded branch), C2-2 (re-read
inside the lock; files deleted after), C2-3, plus: refuse to cancel whenever an earlier run deleted or emptied ANYTHING (a live
member lost their membership row), and behaviour tests for the two key details. **THEN the same checker (`a6aac53c844c9c476`)
re-runs its own two proofs against the fix — minutes, not a round — and A2b is committed.**

**PACKAGE A2b IS COMMITTED: `fd08b7f` (12 Oct ~07:45), pushed; #574 commented.** 33 files; migration 209; 223 + 60 + 57 + 54 checks on
8.0.36 and 8.4.11; 110 faults. Reviewed by one full check, a narrow check of the F1 fix (found C2-1, which the fix had introduced),
that checker's re-run (found C2-4, a small mirror), all fixed; the checker's last probe line compares two different people's numbers
and cannot pass as written — I read it and confirmed (his gift now carries his number; 3 kept gifts, was 2).
**THE PLAN FOR B2, D AND E IS IN: `.claude-work/resume/rollout-B2-D-E--plan.md`** (481 lines; a table of files per package and what
each can run beside; ten owner decisions, each with a recommendation the builders follow). Its coordinator's point: "shares no
files" is not enough in ONE folder — the page tests copy all of `web/` and the migration test replays all of `web/_sql/` — so
**side-by-side builders now work in their OWN checkouts (the Agent tool's `isolation: worktree`), commit there, and the
commissioning session brings each commit across** (cherry-pick onto `claude/alpha-wip`; never pushed from the worktree).
**RUNNING NOW (12 Oct ~08:00), each with a watchdog:**
- **C's one check** (Opus; `.claude-work/resume/rollout-C--check.md`). C = exactly the 18 files in
  `.claude-work/resume/rollout-C-FILES.txt` (uncommitted in the MAIN folder); its document entries are in
  `…/rollout-C--build-evidence/DOC-ENTRIES.md` — add them at commit.
- **B2a build** (Opus, own worktree; items 1-9, 11, 12 incl. ending an erased or switched-off account's session in
  `Auth::ensureSession()`; report `…/rollout-B2a--build.md`; container `roB2a-mysql`, ports 33430-39).
- **E build** (Opus, own worktree; migration 124 corrected, new 210, new migration-test phases from `origin/main` and from a frozen
  6 March install script; workflow changes written as a diff, NOT applied; report `…/rollout-E--build.md`; ports 33440-49).
- **D build** (Opus, MAIN folder, documents only; every runbook command run, a real backup and restore; report
  `…/rollout-D--build.md`; ports 33450-59). One sentence ("lock one account out") waits for B2a.
**Then:** B2b (the erasure pages' addresses; after B2a) → #499/#500/#502 → #514 parts 9-11 → the one workflow change → documentation
sweep → Codex pass → pull request when the owner says.

**(earlier)** RUNNING SIDE BY SIDE since 12 Oct ~06:15 (owner's decision above):
- **A2b fix round 2** (Opus builder `a25b4240c3ddf008d`; report `.claude-work/resume/rollout-A2b--build.md`; watchdog) — at 05:56 its
  fixes passed on both versions (220 + 57 + 54 + 60); planting faults. Then the checker `a6aac53c844c9c476` re-runs `c2-scenario.php`
  and `c2-race.php`; then commit. **A2b's file list for staging: `.claude-work/resume/rollout-A2b-snapshot2/FINGERPRINTS.txt` plus
  whatever the builder's last addendum adds — NOT `git status`.**
- **Package C build** (Sonnet; #547, #568, #563; report `.claude-work/resume/rollout-C--build.md`; own container `roC-mysql`, ports
  33410-33419; must not touch the shared documents — its entries go to `…/rollout-C--build-evidence/DOC-ENTRIES.md`; watchdog).
- **The plan for B2, D and E** (Opus, read-only; `.claude-work/resume/rollout-B2-D-E--plan.md`; watchdog) — it states each package's
  files and what each can run beside. B2 includes ending an erased or switched-off person's session (`Auth::check()`).

**SEEN ON THE LIVE ADDRESS (12 Oct ~03:40, read-only curl): the example customer's live address answers EVERY path with the
installer's "Install — Welcome" page** — so that installation is NOT installed (which answers "fresh install or upgrade?": fresh),
and the install wizard is open to anybody who visits. Told the owner. Nothing was sent to the server.

**(history)** A2a's ONE CHECK (12 Oct ~01:15): NOT CLEAN on three small points, no data loss (end of `.claude-work/resume/rollout-A2a--check.md`):
its own erasure on linked data deleted eight rows, all the person's own (the old code lost 18, most of them other people's); 34 of 34
page attacks refused; migration 208 right. Findings: three unlinked "who did it" columns never reached (`tblEvents.submittedByID`,
`tblErrors.resolvedByID`, `tblMigrations.executedByID`) with four untrue sentences; the emptied account checked against 15 named
columns only (K19 passes); one untrue reason in `check_account_writes_guarded.py`.
**OWNER, 12 Oct ~01:00 ("just OK those now, we need to resolve them anyway"):** the five pinned columns get the database change
(extend 208 — not committed yet); an accepted invitation is DELETED with its person. NOT decided (no recommendation was given): the
safeguarding question — who made a pastoral visit / recorded a DBS check is CLEARED as built; say so in the roll-out notes.
**NOW: A2a FIX ROUND (same Opus builder; no second check)** — the three findings; the two owner items; and from the checker's Found
list: end the erased person's sessions (if that needs an `Auth` change on every page it goes to B2 instead — the builder reports);
the approval line that vanishes once its approver is erased (inner joins in `expenses/view/index.php`, `ExpensePdf.php`); a committed
page-driving test for the erasure pages; three untrue sentences on the pages. Watchdog on `.claude-work/resume/rollout-A2a--build.md`.
**THEN:** commit → A2b (giving records; migration 209; Opus) → B2 → C → D → E → #499/#500/#502 → #514
parts 9-11 → the workflow edit → documentation sweep → Codex pass.

**(done)** NEXT, in order (one package in flight; nothing edits the tree while B is being checked): B's check → fix → commit → A2a build →
check → commit → A2b → B2 → C → D → E → #499/#500/#502 → #514 parts 9-11 → the workflow edit → documentation sweep → Codex pass.

**(older note)** PACKAGE A2 (erasure, part two) — being designed with the owner: (i) erasing a follow-up worker today DELETES other people's
records (`tblVisitor`, `tblSalvationCards`, `tblTasks`, `tblRotaSlot` on `assignedToID`; `tblRotaSwapRequest` on `targetUserID`) —
OWNER: keep the record, clear the worker; (ii) nine "who did it" tables not in the list — OWNER: keep the record, clear who did it
(three columns cannot be empty today → a migration, 208); (iii) giving records — the owner asked about legal duties: HMRC requires the
donor's name and address on the declaration and a link from each claimed gift to it (sources in this session: gov.uk Chapter 3 and
7, the 2016 Regulations), so names on tax-claimed gifts must be KEPT for a period; and the owner wants it right for EVERY country,
not only the UK. Design direction: country-neutral — keep the tax evidence the organisation's own country requires for a period that
is a per-organisation setting, default suggested from `org.address.countryCode`, then removed automatically by the retention job;
everything else erased at once. **Research running** (Sonnet, facts only, 24 countries, sources quoted):
`.claude-work/resume/donation-retention-research.md`. Also for A2: the email-log step alters longer addresses (`ann@` inside `joann@`);
kept declarations carry no name today; `/account/erasure-request` prints "retaind".

**ORDER:** part 8's commit → the roll-out set → #499, #500, #502 → #514 parts 9-11 → documentation sweep → the Codex pass alongside →
the pull request when the owner says.

**At merge time:** commits `f0eb9d5` ("Closes #477", "Closes #482") and `20d7a05` ("Closes #483") would close issues that are NOT done
(the #477 page does not exist; 33 of 62 endpoints documented; three dead files remain). History cannot be rewritten — reopen those
three straight after the merge, and say so in the pull request.
**Open question for the owner (asked 11 Oct):** is the roll-out a FRESH install or an UPGRADE of an existing database? An upgrade of a
database installed before 25 June 2026 stops at migration 124 (#555), so #553/#555 would join the roll-out set.

## OWNER DECISIONS, 5 OCTOBER 2026, about 03:00 — eleven answers to questions put up front (final; do not reopen)

1. **Chunk 3 closed by carrying its last fixes into chunk 4** — accepted.
2. **The builder's two FOUND items:** the `#categories-help` phone-width fix is folded into P8-4 (builder told); the wrong app name on
   the app-switched-off page is its own issue, **#570**, fixed after part 8 alongside #569. Part 8's checks must not rely on that heading.
3. **Builder:** Opus for the rest of part 8; Sonnet for the simpler items after it (#568, #563 …); Opus still checks.
4. **Thinking effort `xhigh` → `high`** — DONE in `~/.claude/settings.json` (top level + both per-model entries). A settings-file
   change applies from the NEXT session (Claude Code's docs); `/effort high` applies it to a running one.
5. **Instruction reviews (`.claude-work/resume/instructions-review.md` and `instructions-audit-2.md`): apply EVERYTHING now** — all 103
   verdicts including the big trim of `.claude/CLAUDE.md` (history and inventory out; its two buried traps saved to DEV_NOTES first),
   the five new instructions, and the "real problem" definition. Being done by its own Opus agent (see section 2).
6. **Handoff:** move old entries into an archive file beside it, marked as history — AFTER part 8 is committed.
7. **Deny rules** for force-push, hard reset and docker prune — DONE and PROVED (each refused; the same commands without the flag
   ran). Deny only; no "ask" prompts. They cannot catch `bash -c '…'` or `/usr/bin/git …` (Claude Code's docs).
8. **The cache add-on** moved from `.claude/skills/prompt-cache-control/` to `~/.claude/skills/` — DONE; the repository no longer
   holds it, so the "leave it out of every commit" rule is retired.
9. **#569's pre-push check:** one per repository, with its own installer (the FileMoCo shape); not a global hooks folder.
10. **Helper agents: at most six at once, in writing AND enforced** by `CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS=6` — DONE. The seventh
    agent is REFUSED, not queued, and the setting is read only at session start: shape workflows to six at a time.
11. The cut-off "Also find" in the second instruction review: **nothing more** — drop it.
12. **The 01:59 edit to `~/.claude/CLAUDE.md` (5 Oct) was the owner's — keep it.** It added the five new instructions in Anthropic's
    wording, narrowed reviews to harm-capable changes, and prefers the other service over a helper as reviewer.
13. **What gets reviewed HERE: narrowed exactly as machine-wide.** Only changes that could do real harm (code, database, data,
    money, credentials, personal details, safety gates) get a review round. Documentation of every kind — instruction files, memory,
    the handoff and notes — renames and comment-only changes do not. The owner chose this knowing the instruction rewrite is then
    committed without a review round. Written into `.claude/CLAUDE.md` ("Codex review"), `.OpenAI/CONTEXT.md` rule 2, `AGENTS.md`
    and the review memory.
14. **Who checks: a fresh agent checks each code package as Codex's named stand-in; the FINAL whole-of-part-8 check goes to CODEX**
    (`codex … -c model="gpt-6-astra" < /dev/null`; read the output for a limit message — a limit can exit 0); the end-of-queue
    whole-branch Codex review still happens, possibly after a reset.
15. **Deleting a web-root folder that hides an address needs the owner's explicit approval first** (first review S13). Written into
    `.claude/CLAUDE.md` (web-root section), `.OpenAI/CONTEXT.md` rule 16 and the shadowing memory.

**Instruction work — DONE (5 Oct ~03:50):** both reviews applied in full by an Opus editor (report: end of
`.claude-work/resume/instructions-apply.md`; before-copies in `instructions-apply-before/`). `.claude/CLAUDE.md` 1,506 → ~590 lines;
nothing deleted outright — "Recent ships" and the inventories moved word for word to `.claude/history/`, three folder-only traps to
`.claude/rules/` (path-scoped). Also fixed: `.dev-team/config.yml`'s stale review comments (first review C2) and FEATURES.md's pointer
to the removed counts table. **Left for later, noted by the editor:** two plans (`.claude/plans/public-door-5…:1063-1064`,
`public-door-6…:1280`) edit sections that have moved — fix when #499/#500 is built; `common-builder.md:25-26` (a saved brief) still
promises a per-package Codex review; path rules and HTML-comment stripping are documented but not yet seen working in a live session.

## OWNER DECISION, 25 SEPTEMBER 2026, about 16:00 — `tools/watchdog.sh` shortcut fix APPROVED (recorded by a CueRCode session)
The owner approved the fix for the watchdog "always fires on a shortcut" fault: add `-L` to both `stat` calls in `mtime()` so
the script reads the real file's age, not the shortcut's. Apply it to **both copies together** — this repo's `tools/watchdog.sh`
and the device-wide `~/.claude/bin/watchdog.sh` on the owner's Mac — and keep them identical. Details, exact change and checks:
**#564**. Not done yet: the CueRCode cloud session can't reach the Mac, so a session on the Mac should do it. Until then, point
`quiet` at the real file (`ls -la` the `.output` shortcut to see where it leads).

## OWNER DECISIONS, 24 SEPTEMBER 2026, late evening — two workflow-file changes APPROVED

1. **Run `tools/audit-checks/check_fk_references_unique_key.py` on every pull request** — owner: "if it is feasible to check the
   run on each PR then yes". It is feasible: it only reads text and takes seconds, like the other twenty. Wire it into
   `.github/workflows/pr-security.yml` with **step 9's pattern** (keep the exit code, do not trim lines), so a crash or a missing
   file can never look clean.
2. **The end-to-end migration test runs on MySQL 8.4 as well as 8.0.36** — owner: "end-to-end migration test should also run on
   mysql 8.4". Both versions, not a swap: 8.0.36 stays until #475 decides otherwise. The image is set in
   `tools/e2e-migrations/docker-compose.yml`; the workflow is `.github/workflows/e2e-migrations.yml`. Prove the harness passes on
   8.4 locally before wiring it in.

**When:** straight after #552 is committed — NOT while #552's check is running (nothing may change the tree under a checker). Built
as ONE workflow package together with queue task 4 (the three calendar self-tests into the pull-request checks, approved earlier),
so the workflow files change once. Change only what was approved; never add `continue-on-error` unasked.

## DECISIONS TAKEN 24 SEPTEMBER 2026, about 09:00 — read before building anything

The owner asked for every pending decision to be raised up front. These are the answers. **Everything below the START HERE
block that contradicts them is out of date.**

**1. API keys — CHANGED, and it reverses committed code.** An API key now sees an imported event **exactly as a signed-out
visitor would**, plus a new per-calendar **"don't show via API"** box — the "show on our website" box in reverse. Unticked (the
default) = keys receive the calendar's events. **This replaces the owner's 17 September answer**, which parts 1 and 2 were built
to; the owner confirmed today's answer stands after being shown the two side by side. **CORRECTED the same afternoon:** this note first said it
WIDENS what existing installations send on upgrade. **That was wrong** — checked against `origin/alpha`, where an API key's event
list (`events/api/list.php`) filters only by organisation, not-deleted and published, and `EventVisibility` does not exist at all.
**Released versions already send keys EVERY imported event in full detail.** The "keys receive almost nothing" behaviour exists only
on this unreleased branch. So on upgrade customers keep receiving their calendars' events through keys, and **private-marked events
stop** — a narrowing. The owner was told the wrong direction and told the correction; the decision stands. The upgrade notes must
say what actually changes.
**Built into P7 and P8**, beside the website box it mirrors. Full detail at the top of `.claude-work/resume/p514--plan-r2.md`.

**2. Five design questions were re-asked by mistake and answered exactly as on 17 September** — private events hidden until
chosen; a changed event goes back for approval; later dates of a repeating event follow an approved one when nothing differs; an
organisation with no site administrator of its own has its approval notices sent to every global administrator; at "title, date
and time only" the category shows and the RSVP counts do not. **Nothing changes for any of them.** They were re-asked because the
plan's "built as recommended" notes were read as unconfirmed; the answers were at the top of the same file. Saved to memory as a
lesson: read the settled answers before asking.

**3. Codex reviews the whole branch at the END OF THE WHOLE QUEUE** — not after #514. It moves to the very end again if tasks are
added. Run it with `-c model="gpt-6-astra"` and stdin closed, e.g. `codex review --uncommitted -c model="gpt-6-astra" < /dev/null`,
or it refuses with a message naming `gpt-6-sol` — which is the model name, not a lack of credit. **The cost, said plainly:** this
makes the stretch of work Codex has not seen as long as it can be, against the machine-wide rule's advice to review frequently. The
owner chose it knowing Codex's allowance is small.

**4. Both calendar self-tests go into the pull-request checks, with a throwaway MySQL database.** GitHub starts the database
beside the check and removes it when the job ends, so nothing accumulates anywhere. Still to build — it is task 4 in the queue.

**4b. One package at a time — REAFFIRMED on 24 September.** Offered a separate git worktree so the live faults (#545, #547) could
be fixed in parallel without touching #514's files, the owner chose to keep strictly one at a time. **Do not offer it again.**

**5. Throwaway containers are removed WITH their data — now a standing rule, machine-wide and in this project.** Checking after
the owner asked found **415 left-behind database volumes taking 101 GB**, plus 8 stopped test containers from this project going
back to 10 September. Every agent had reported its clean-up as done; they removed containers and never their data. **Cleared the
same day with the owner's approval: 101.1 GB reclaimed, 425 volumes down to 2.** Kept: round 3's running checker, and
`g2ml-mysql` and `wrapper-v2`, which belong to other projects. **Every brief that lets an agent start a container must now say:
`docker run --rm` or `docker rm -v`, name it after its work, and report `docker ps -a` and the count of left-behind volumes.**

**6. Using agents rather than the workflow tool** — stated rather than asked. The rules say to use "workflows and agents" where
they fit. Background agents with a progress file are used because this project recorded that workflow agents are killed after
three minutes without output and the whole run fails; that workaround saved two builders on 23 September.

# ============================================================================
# START HERE — written 23 September 2026, about 21:00, for a COMPLETELY FRESH
# SESSION with no chat history. Everything needed to carry on is in this one
# block. Everything below it is history, kept because it explains WHY.
# ============================================================================

---

## Older entries

Everything written before 5 October 2026 (the sections that used to follow here: "0. Before you restart the session"
through the old queue and its history) is in `.claude/history/HANDOFF-archive-to-2026-10-05.md`. It is history, not
instructions.
