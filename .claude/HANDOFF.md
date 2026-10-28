# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-10-28 (kept current as the work happens, so it can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## 28 OCTOBER 2026, LATER — #645 settled: a truncated download was deleting events

**Committed: nothing yet for this; the money package is `37a63de`.** #645's three items are all
settled, two with code changes and one disproved.

### Item 2 was the real one, and it could lose data

`IcsReader::parse()`'s `complete` flag is the ONE thing `FeedImporter` asks before letting a refresh
REMOVE events. It was worked out from four things — calendar started, calendar ended, did not stop
early, no line dropped — and **not one of them mentioned a block left open**. So a download cut off
INSIDE an event was reported as a whole calendar, and the importer then marked every event after the
cut as removed. A dropped connection part way through a transfer is ordinary.

**The comment above the calculation said `complete` was "false anyway".** It stated the guarantee
the code had forgotten to implement, which is why the fault survived several reviews: the comment
stopped people looking. That is the second comment of the day with that shape.

Fixed with `count($stack) > 0`, not `$current !== null` — the stack also catches an unclosed
`VALARM` inside an event that ended (previously reported whole, no warning at all). Planting the
simpler version passes I7b and FAILS I7c, which is why the stack earns its place.

Item 1 was **disproved**: `removalEndPoint()` returns `exact => false` for every capped read, so
removal uses `<` not `<=`, already pinned by `feed-importer-selftest.php:2136-2149`. Item 3 was
reproduced but is the RIGHT behaviour, now documented with check C13 on it.

### Two check rounds, and both found faults in my CHECKS again

- **Round 1 (NOT CLEAN, 3 problems).** The worst: **I7d, the check that exists to guard the
  over-reach direction, guarded nothing.** Of every fixture that existed, NOT ONE held a correctly
  closed nested block — no `END:VALARM`, no `BEGIN:VTIMEZONE` anywhere. Narrowing the stack pop to
  `VEVENT`/`VCALENDAR` passed all 345 checks while ten of thirty-four well-formed shapes became
  "incomplete": a realistic Google export, any event with a reminder, VTODO, VJOURNAL, VFREEBUSY.
  Every refresh of nearly every real calendar would have taken the "incomplete" branch for ever.
  Fixed with `nested-blocks-closed.ics`. **When a check exists to prove something ORDINARY still
  works, go and look at whether any fixture is actually ordinary** — a fixture set grows to pin
  faults, and every fault is unusual, so the normal case is exactly the hole that forms.
  Also: C13's comment claimed it pinned exact-versus-nominal duration; restoring the `diff()` route
  passed all four C13 checks, because none of its occurrences spans a transition (I26/I29 pin that).
- **Round 2 (NOT CLEAN, 1 problem, wording only).** My replacement comment for the clocks-going-back
  paragraph said the fault hits an event "whose start lands in the repeated hour". **That
  reproduction gives the RIGHT answer** — a series whose DTSTART is on that reading is correct,
  because the first date goes through `readDateTime()` like a one-off. It is the SECOND OR LATER
  date. So the replacement would have sent the next reader away satisfied: the same failure as the
  "needs nothing special" wording it replaced, by a different route. **#653's title said it too**;
  both corrected, and the mistake recorded on the issue rather than quietly edited away.

### Found and filed, not fixed

- **#653 (high)** — a later occurrence falling in the repeated hour comes out ZERO LENGTH
  (01:30 to 01:30), while the first date of a series and a one-off are correct, because the two
  paths resolve the ambiguous reading differently. **Pre-existing**, confirmed against the committed
  code. Needs the owner's decision on what an ambiguous reading means, like item 3's forward case.
- **#652 (medium)** — four `import-notifier-selftest.php` checks fail by themselves as the calendar
  rolls round: the test wants two Europe/London clock changes in the next twelve months and only one
  fits from late October. Proved pre-existing by running it against the committed `IcsReader.php`.
  It reports FAIL where it means "could not run", and will go red in CI on every pull request.

### Verified

2,073 checks: ics-reader 348/348 (2 pre-existing skips), feed-importer 139/139, feed-admin 934/934,
feed-resolver 208/208, event-visibility 192/192, safefetch 252/252, plus 36/36 audit checks with
their output READ. Four planted faults, all caught after the fixtures were converted to CRLF.
**Not verified:** MariaDB; PHP 8.4 (run on 8.5.11); no real Google or Microsoft export exists in the
repository, so section M stays skipped and "works against a real provider feed" is still unproven.

---

## 28 OCTOBER 2026 — slice 9 done as a whole-app review, a claim could be paid twice, and an edit handler

**Owner answered all eight queued decisions** (question tool, 28 October): keep #640 as a warning;
hold the advisory and publish as ONE once `main` is fixed; chase all three #645 items, item 1
first; wire all twelve #646 checks, hard gates where green; prove MariaDB and do #487 while #475
stays blocked; #569 as the whole package with markup reading as the visual audit; #641 check first
then fix; #643 confirm the premise then record as intended. **Owner also supplied the database
fact #475 was blocked on:** DreamHost shared hosting runs **MySQL 8.0.41-0ubuntu0.24.04.1**,
confirmed by `SELECT version();`, and the first customer will be on it — so 8.0 stays the floor,
while newer MySQL and MariaDB must also be supported. Note the vendor packaging suffix: that is
the shape that breaks version parsing, and `DbServer` needs checking against it (not yet done).

**Owner asked for an edit handler mid-run and approved building it.** Also asked for the
narrow-hotfix route onto `main`: a branch carrying ONLY the sign-in fixes, which I prepare and
they push. `main` is **563 commits behind** this branch and still carries all four sign-in holes
(`userRequires2fa` appears in two files there, four places here). No branch protection, no open PR.

### Slice 9, done properly as a whole-application review, not a diff

Slice 9 had read only a diff and said plainly it could not judge five things. All five answered:
**7 findings, filed #647-#650.** The big one: **an expense claim could be paid twice.**
`treasury/save.php` read the status outside its transaction with no lock, never re-read it inside,
and its UPDATE had no status guard — and there is no unique key on `tblExpenseClaimPayments`.
Four things would each have stopped it; **none was present.** Two treasurers, or one double-click,
each wrote a payment reference, each set `Reimbursed`, each e-mailed the claimant.
**It is an omission, not a decision:** `approve/save.php` and `withdraw/save.php` always locked.
Also found: the export and claim page ignore the department model (#648, the unsettled #541 item 3
— the site-wide `Approver` role downloads every claim in the organisation while the decision
handler says that role is "not authority to decide"); a receipt that fails to attach is silently
dropped while the claimant is told the claim saved (#649); and four small faults (#650).

**The HTTP harness CANNOT test the payment race** and this must not be re-attempted there:
`access-pages-selftest.php` runs the built-in server with ONE worker
(`unset($env['PHP_CLI_SERVER_WORKERS'])`), so two requests never overlap and such a test **passes
on the broken code**. `tools/expense-payment-race-selftest.php` races two real connections instead.

### #651, the edit handler, bounded on purpose

The bound is **"a claim nobody has decided on"**, never "a Pending claim": a claim in a department
needing several approvals stays Pending after the first approval, so editing it then would leave
an approval against figures nobody approved. `ExpenseRules::whyNotEditable()` is the one place;
it takes FACTS not a connection, because the handler asks it twice — once cheaply, once inside
its `FOR UPDATE` lock. Claimant only, never an admin (#545's reasoning). Receipts untouched (#649).

### The independent check found SIX real problems — and three plants my tests missed

A fresh Opus agent that did not build it. **All six fixed; all three plants now caught.**
- **P1, the worst: migration 223 was never marked in `full_schema.sql`'s `tblMigrations` seed
  block**, so a brand-new installation would report an upgrade waiting that has nothing to do.
  **`check_schema_seed_parity.py` PRINTED this and exited 0**, and I had measured "36/36 audit
  checks pass" by exit code alone. The migration-222 comment in that very file warns: *"a clean
  run of the audit scripts does not prove this block is complete. Read what it prints."*
  **Standing lesson: run the audit scripts and READ THEM.** Fixed, and proved on a fresh install.
- **P2 and P3 were tests that passed on broken code**, the thing this project fears most.
  P2: a check searched for a call and was satisfied by the HTML COMMENT beside the button that
  merely mentions it — `codeOnly()` strips PHP comments but keeps inline HTML (deliberately, so
  the `<script>` check can fail). There is now a second helper, `phpOnly()`. P3: the locked
  re-ask's ANSWER was never checked, so keeping the call and discarding the result passed 53/53 —
  the £20 → £2,000 sequence. Both now brace-walk the refusal block, as the race test already did.
- P4, P5, P6 were untrue comments (403 vs 404 are not "the same answer"; "the approvers were told
  when it was submitted" is false once the department can change; "comment for comment" overstated
  the match — treasury is strictly stronger than the two it claimed to copy).
- Also closed two coverage gaps the checker found: nothing tested the edit handler's organisation
  scoping, and nothing at all tested the `payRef`/`comments` type guards.

**One plant of mine was faulty before the check was** — again. My F9 re-plant spliced on the first
`$pageTitle` match, which sits at line 35 BEFORE the block, so it duplicated text instead of
removing it and left the original call in place. **Always prove a plant landed** before concluding
anything about the check.

### #645, all three items settled by evidence

1. **Disproved.** `FeedImporter::removalEndPoint()` returns `exact => false` for every capped read,
   so the removal uses `startDateTime < point`, never `<=`. Already pinned by
   `feed-importer-selftest.php:2136-2149`. The reviewer never read the consumer and said so.
2. **REAL, and worse than reported — this is the one that can lose data, not item 1.** A file
   ending inside a `VEVENT` reports `complete = true` *alongside a warning saying it was not
   complete*. `FeedImporter.php:451` uses that flag as the SINGLE gate stopping an incomplete
   download removing events, so a truncated download soft-deletes every event after the cut.
   **Not yet fixed** — next package.
3. **Reproduced, but it is the chosen behaviour** (01:30 → 02:30 on the spring-forward night,
   exact length kept, counted against `COUNT`), matching the owner's 23 September decision and
   every mainstream calendar. **But neither commented nor tested**, so it could be silently
   reversed. Needs a note and a check, not a behaviour change.

### Verified, and what was not

668 checks green: 36/36 audit checks (output READ, not just exit codes), expense-payment-race
25/25, expense-edit 59/59, expense-rules 58/58, expense-withdraw 37/37, access-pages 298/298 with
a clean PHP error log, page-scripts 88/88, site-addresses 103/103. Migration 223 run twice on a
fresh database: two routes, one migration row. Fresh install now records 223. Lint clean.
**Not verified:** MariaDB; the new pages in a browser (markup read, never rendered); the two
workflow steps actually running (no YAML library locally — `actionlint` in CI will confirm);
Codex has reviewed none of this.

### THREE check rounds, and every round's findings were in my CHECKS, not the handlers

Said plainly because the owner's rule asks for it when a chunk passes three rounds: this one took
**three**. The shipped handlers have been correct since round 1. What kept failing was the evidence
that they were.

- **Round 2** confirmed all six round-1 fixes and then defeated **three of the new checks**, each
  with a planted fault: (a) the brace-matching walk counted braces inside STRING LITERALS, so one
  `{` in a log message made it read 2,432 characters instead of 157 — the rest of the function —
  and with a second plausible `$refuse(` later in the `try`, **all 59 checks passed with the fraud
  gate deleted**; (b) `substr_count` of `WHEREclaimID=?ANDsiteID=?` required `>= 2` while THREE
  statements share that prefix, so removing the organisation scoping from the lock left the count at
  2 and the check passed *while asserting both reads were scoped*; (c) the test held its own LIFTED
  COPY of the payment-reference parsing, so dropping `array_filter` from the HANDLER alone passed
  everything — and that drift would have written an EMPTY payment reference against a real claim.
- **The fix for (c) was not a cleverer test.** `ExpenseRules::paymentReferences()` is now the ONE
  place payment references are read; the handler calls it and so do the checks. A copy cannot drift
  when there is no copy. That change is why a round 3 was needed: it is shipped code.
- **Round 3: CLEAN.** 23 planted faults, all caught — round 2's exact plant, eleven attempts to
  defeat the brace walker (heredoc, nowdoc, interpolation, escaped quote, `'{{{'`, `'}}}'`,
  trailing backslash), four scoping removals and seven parsing drifts. It also compared the new
  method against a rebuild of the old inline code over 30 input shapes and found them identical,
  and confirmed `array_values()` is neutral for all three call sites. One dead-store evasion of B11
  was reported as Polish with a one-line fix; **I took it** (B11a) and proved it catches the plant.

**Four lessons now written into both instruction files**, because each cost a round:
1. A check that searches a page for a CALL can be satisfied by an HTML COMMENT mentioning it.
   `codeOnly()` keeps inline HTML deliberately (the `<script>` check needs it); `phpOnly()` is for
   "what does this page DO".
2. A brace-matching walk must step over string literals, and should fail safe on an implausible
   length rather than search half a file.
3. `substr_count` of a shared SQL prefix cannot tell statements apart. Name each one.
4. **Run the audit scripts and READ THEM.** `check_schema_seed_parity.py` named the missing
   migration mark in its output while exiting 0, and "36 of 36 passed" measured by exit code alone
   nearly shipped migration 223 unrecorded.

**And twice my own plant was faulty before the check was.** The second time, a splice anchored on
the first `$pageTitle` match — which sits at line 35, BEFORE the block — duplicated text instead of
removing it and left the original call in place, so a working check looked blind. **Always prove a
plant landed with a diff before drawing any conclusion from it.**

### Next, in order

1. #645 item 2 — the truncated-file gate. Data loss; highest priority of what remains. The fix is
   designed: `$complete` is computed from `$sawCalendarStart && $sawCalendarEnd && !$stoppedEarly
   && !$droppedLine` and nothing in it mentions a block left OPEN, so an unterminated `VEVENT`
   followed by `END:VCALENDAR` satisfies all four. The comment at `IcsReader.php:1247` already
   claims `complete` "is false anyway" — it states the guarantee the code forgot to implement.
   There is a `$stack` of open blocks in scope, so the robust term is "nothing left open", which
   also catches an unclosed `VALARM`. **Verify empirically that a well-formed file leaves that
   stack empty before relying on it**, or every file becomes incomplete.
3. #645 item 3 — a comment and a test for the missing-hour behaviour.
4. #646 — wire the twelve (shares `portal-selftests.yml` with this package, so it waits).
5. #643 — confirm the auto-increment premise against the table, then record as intended.
6. The narrow hotfix branch off `main` for the owner to push; #487 + the MariaDB matrix; #641; #569.

---

## 27 OCTOBER 2026, LATEST — slice 8 done, #640 built, and a queued run that produced nothing

**Slice 8 and slice 9 both ran; #640 (PHP2Shell) is built and under independent check.**
The documentation sweep's committed portion landed as `1fa082c`, and what was left of it is
filed as **#641** (nothing executes the API description against the code).

### A queued Codex run produced NOTHING, and the cause was mine

The detached runner for slice 8 fired at 20:07 as planned and came back with no verdict — not a
usage limit this time. **There was no `slice-8-prompt.txt`**, so `$(cat ...)` expanded to an empty
string, Codex read the repository's own instruction files, asked "what would you like me to work
on?" and exited, spending about 14,600 tokens of the allowance. The runner script now REFUSES to
start on a missing or empty prompt file (`exit 2`), proved with a planted empty prompt.
**Lesson: a queued job that builds its own arguments must refuse to run on empty ones** — an
allowance spent on nothing is worse than a job that did not start, because it looks like it ran.

### Slice 8 (FeedAdmin + ImportNotifier): five findings, two real

Re-run properly: "not safe to ship", five findings. **Two acted on, three not, and the two I
rejected I rejected with evidence:**
- **FIXED (hardening).** A confirmation value read as a number accepted an ARRAY, because any
  non-empty array casts to 1 — the same shape as last week's two-factor bypass. **Not exploitable:**
  the one page that calls it reads the field through a helper that refuses anything but a string, so
  an array arrives as `'0'`. Hardened anyway: the method's signature says `mixed`.
- **FIXED (untrue comment).** `buildEmail()`'s docblock promised every calendar's count came from
  the same read as its rows. True only of calendars whose rows are LISTED; past the twenty-event
  limit the rest keep a count a few seconds old. Now says what the code does, and why re-reading
  every calendar is not worth a query per calendar inside a timed job.
- **REJECTED: "a demoted administrator can still be sent event titles."** The gate IS inside the
  per-person loop, immediately before that person's message is built. The window is a few
  statements wide and the person was an administrator moments earlier.
- **REJECTED: "an interrupted claim loses the day's e-mail."** That is the house rule working as
  intended — never twice comes before always delivered — and no comment promises a retry.
- **NOT FIXED, worth an issue: a push can skip an event** whose approval number was allocated
  before, but committed after, a higher one on another calendar. Narrow, and the dashboard and
  daily e-mail still show it.

### Slice 9 (money) found a REAL money fault, and it is the most serious thing today

`expenses/approve/save.php` read the approver's decision as
`($_POST['decision'] ?? '') === 'Rejected' ? 'Rejected' : 'Approved'`, so **everything that was not
the exact text "Rejected" recorded an APPROVAL** — a missing field, lower case, "Reject", or
`decision[]=Rejected`. If that was the last approval the claim needed, **a claim somebody meant to
reject became payable.** Filed and fixed as **#642**; four checks, 4 of 54 fail on the old line.

**Why it survived, and the lesson:** nothing in the portal sends any of those — the form is a
two-option list with exactly those values and the column is an ENUM of the same two. **A guessed
default on a money page is wrong even when today's form is right**, because the form is not the only
thing that can post. Same shape as the `?redirect[]=x` two-factor bypass a week earlier. Worth
searching for other `X ? A : B` reads of a posted value where B is the permissive branch.

Slice 9's second finding — any Approver can open any claim in the organisation, across departments —
is **already tracked as #541 item 3**, and the code's own comment names it. Left alone deliberately.
Worth noting Codex found it from the code alone, which strengthens the case for acting on #541.

**What slice 9 did NOT cover, and should:** double-payment protection, the completeness of the
locking, whether amounts can change after approval, who may download a statement, and the money
calculations. It read a diff, not the app. **A money app deserves a pass over the whole thing.**

### #640 built: the PHP version is now judged, not printed

`Portal\Core\PhpRuntime` is the ONE place, in `DbServer`'s shape. **The owner's instruction read
as "warn, do not block"** — its second sentence asks for a warning and a link — and that is also
the only honest answer: a version number cannot tell a vendor-patched build from an unpatched one.
Reversing it is one constant, `BLOCK_INSTALL_WHEN_UNPATCHED`, which the installer reads.
Facts re-verified against php.net before being written into customer-facing words: fixed
21 October 2026 in **8.5.12 / 8.4.27** (8.3.36 / 8.2.35 below our floor), official link
`https://www.php.net/archive/2026.php#2026-10-21-5`.

**What was actually wrong before: the installer accepted any PHP from 8.4.0 up, so 8.4.0 passed
with a tick**, and a running portal never looked at the version again.

**Three things worth carrying forward:**
- **`support` and `security` are separate answers and must stay so.** 8.3.36 carries the October
  fix and is STILL too old to run the portal. One verdict cannot say both without lying.
- **My own plant was faulty before the check was.** "Honesty wording removed" appeared not to be
  caught; the phrase occurs twice and `replace(..., 1)` hit the COMMENT, not the message. Re-planted
  on the message: caught. Six of six now caught. The same trap as "a check that reads prose judges
  the wrong thing", from the other end — **a PLANT can hit prose too.**
- **The installer's amber badge said "⚠ Assumed" for a security fix.** The word was hard-wired to
  the channel check, the only note that could be amber. Each entry now brings its own word, and the
  channel keeps "Assumed" — measured on the rendered page, both ways, not reasoned about.

Evidence: 66/66 php-runtime-selftest (no database needed, which also proves the class has no
dependencies), 36/36 audit checks, 135/135 installer, 88/88 page scripts, 103/103 site addresses.
Zero dangling Docker volumes before and after.

### BOTH independent checks came back NOT CLEAN, and both were worth their cost

Two packages, two checkers, eight real problems. **Three of the eight were mine, in the place that
matters most — the checks themselves.**

**#640: the worst was a security judgement that was simply false.** The logic said any release line
newer than the newest known must contain the fix, "because it was published later". **PHP 8.6
disproves that**: its pre-releases began 2 July 2026 and RC3 came out on 8 October, both BEFORE the
21 October fix, and PHP's own RC4 note tells everyone on an earlier 8.6.0 build to update
immediately. So **8.6.0RC3 was shown a green panel saying it carried a fix it does not have** — the
one direction the file's own header calls worse than having no check at all. Verified against php.net
myself before changing anything. 8.6 is now pinned at `8.6.0RC4`, the comparison keeps the
pre-release marker (`version_compare` ranks RC below the release), and the durable rule is: **a
pre-release of an unknown newer line is "cannot tell", not "fine".**

**My self-test proved the constants were self-consistent, not correct.** Section C derived "one
below" and "one above" FROM the constant, so a planted `'8.5' => '8.5.1'` passed all 66 checks while
the class told every 8.5 customer they were safe when they were not. Every fixed-release number is
now written out by hand. **A check that derives its expectation from the thing it is checking proves
only that the thing agrees with itself.**

**The tripwire's own list of blind spots was untrue.** It never mentioned `phpversion()`, and matched
only the `PHP_VERSION` constant, so `version_compare(phpversion(), '8.4.0', '<')` passed both the
tripwire and the self-test. **A list of what a check cannot see is itself a claim, and an untrue one
is worse than no list, because it stops the next person looking.**

Also from that round: the installer's note label asserted "PHP carries the October 2026 security fix"
directly above a value saying it does not (a note has no tick to qualify it, so its label must be a
TOPIC); a comment claiming "exactly one of them is ever printed" that was false with the blocking
constant on; Server Information taking its colour from the OVERALL state and its sentence from the
SUPPORT state, so an amber card sat above reassuring prose; and the installer losing its paragraph
breaks and printing php.net as text rather than a LINK — which was the owner's actual requirement, so
L20 of the installer test now allows exactly the outside addresses that appear in the product's own
constants.

**#642: one of my four new checks passed on code that put the money bug back.** The checker planted
`if ($decision === '') { $decision = 'Approved'; }` — "be forgiving about an older form" — and got
**54 of 54 green** while a missing `decision` field approved claims again. The check only looked for
the text of the `if`, never inside the block. It now reads the block and requires it to stop the
request and to hold no assignment to `$decision`. Two of my needles were also silently broken by PHP
string interpolation (`"if($decision===''){"` in double quotes), one of which was passing by that
accident.

**The claim NUMBER had the same fault as the decision**, three lines above it, in all three money
handlers: `(int) ['x']` is 1 silently, so `claimID[]=916301` acted on claim **one**. Guarded in
approve, treasury and withdraw together.

**The behaviour test I said did not exist already existed.** `tools/access-pages-selftest.php` posts
to this exact address as a signed-in approver. Five checks added there; on the original code **four
fail and the control passes** — the first proof the fault was real over real HTTP, not in theory.

### Slice 7 re-run: five findings, one fixed, three NOT verified

Finding 1 is real, reproduced and fixed (**#644**): a moved date of a PRIVATE series imported as NOT
private, because one call site handed the changed date to itself as its own series and the flags are
an OR of the two. **An existing check for that exact promise passed** — its moved date lines up with
a series date, which is a different and correct call site. Two paths, one checked. New fixture and
check F6: the only one of 334 that fails on the old code.

Findings 2, 3 and 5 are filed as **#645 and marked NOT VERIFIED BY ME** — a cut-off that can name a
dropped event (the only finding anywhere in this pass that could DELETE something), an unterminated
event reported as complete, and a repeat manufacturing an occurrence in the missing spring hour.
Finding 4 was already #639. Recording them unverified is deliberate: this pass has already produced
one finding that turned out not to be exploitable, so they are leads, not facts.

### Still owed

- **ALL NINE CODEX SLICES ARE NOW DONE.** Slice 7 was re-run successfully; its earlier no-verdict
  output is kept as `slice-7-raw-partial-no-verdict.txt`.
- **A round-2 independent check of both packages is owed before this commit counts as reviewed.**
  Round 1 found eight real problems; everything above is the fix round, and nothing has re-checked it.
- **#645's first item is the one to look at first:** it is the only finding in this whole pass that
  could DELETE a live event.
- Issues filed today: #639, #640, #641, #642, #643, #644, #645.
- TWO independent checks are RUNNING, one per package, because the money fix arrived after the
  first had started: #640 + the slice-8 fixes, and #642 on its own. They share no files.
- **#643** filed and not fixed (a push can skip an event when two calendars commit out of order).
- A whole-app review of the money pages is owed — slice 9 read a diff only.
- #641 (the API description) and #569 (the release audit, needs a browser) are not started.
- `push-subscribe.js` and `report-builder.js` still drop their replacement token.
- Everything carried over from the entry below, unchanged.

## 27 OCTOBER 2026, the four earlier entries — moved to the archive

Slices 3 to 7 of the Codex pass, the advisory being created, #634 and #594, the documentation
sweep, and the two faults #626 left behind. Moved to
`.claude/history/HANDOFF-archive-to-2026-10-05.md` on 28 October 2026, when this file passed
1,000 lines. **Nothing a decision depends on was moved:** every "OWNER DECISION" section is
still below, in place and unchanged, and the 27 October LATEST entry above is kept because the
queue still refers to it.

## 27 OCTOBER 2026, X8/X9 and the #626 build — moved to the archive

Moved to `.claude/history/HANDOFF-archive-to-2026-10-05.md` (third batch) when this file passed
1,000 lines again. It covered X8 and X9 being committed, the WF fix round, and #626 going into
check. Nothing in it is outstanding; the entries above carry everything still live.

## 26 OCTOBER 2026 — moved to the archive

Both of that day's narrative entries — X3/X7/X8 landing, the recovered WF report and what its checks found, and the
account of the 14 October session being cut off mid-flight and what was recovered from it — are history, and are now
in `.claude/history/HANDOFF-archive-to-2026-10-05.md` under the heading "APPENDED 27 OCTOBER 2026 (second batch)".
Moved on 27 October 2026 when this file passed 1,000 lines again. **Nothing a decision depends on was moved:** every
"OWNER DECISION" section is still below, in place and unchanged.

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
