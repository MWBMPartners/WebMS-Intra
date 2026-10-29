# Handoff archive — work entries to 29 October 2026

History. Nothing here is a live instruction. These are the work entries moved
out of `.claude/HANDOFF.md` on 29 October 2026, when it passed 1,600 lines — the
owner set the threshold at about 1,000, after #514 part 8 was committed.

What stayed behind in the handoff: the last five work entries, and **every
standing owner decision**, which are policy rather than history and must not be
archived.

One thing to know when reading these: several entries are headed "LATEST". They
were latest when written and the label was not cleared as the next one landed,
so **the dates are what to go by, not the word**. Entries run newest first, as
they did in the handoff.

---

## 29 OCTOBER 2026, LATEST — the database-shape audit (#487 task 6), commit `962269a`

The owner asked for the uniqueness-on-empty-columns review and then widened the brief: do whatever
else is needed for a first USABLE release, with as few half-finished features as possible.

**The audit.** Every unique key in the schema holding a column that can be empty, read out of a
LOADED DATABASE (not the SQL — `information_schema` calls a generated column nullable even when it
can never be empty, so `siteScope` looks like a fault and is not): **15 keys, 14 right, 1 broken.**

**The broken one was live.** `tblEventAttendance.uq_attendance_unique` covered
(eventID, userID, walkinName, dayDate); a row names its person by EITHER `userID` OR `walkinName`,
so no row ever filled in all four and the key refused NOTHING, ever — while the mark-present page
writes `INSERT IGNORE` under a comment saying "idempotent via unique key". Migration 224 fixes it
with a worked-out `attendeeRef`, VIRTUAL (STORED is refused, ERROR 1215, tried).

**The subtle part, do not undo it:** `attendeeRef` is NULL when the person is unknown, on purpose.
`GdprEraser` anonymises attendance rather than deleting it so the aggregate survives; two erased
people cannot be told apart; a key that collapsed them would delete the counts erasure exists to
preserve. The de-dup's `WHERE` carries the same restriction. Four anonymised rows planted — all
four survived, on all three engines.

**I OVERSTATED THE HARM IN FIVE PLACES AND IT WAS CAUGHT.** "Counted twice in every attendance
figure" is FALSE: the grid keys its display by person and day, walk-ins come back
`SELECT DISTINCT`, both Discipleship joins feed an `INSERT IGNORE` with no empty key part, there is
no `COUNT(*)` over the table and `ReportBuilder` cannot reach it. **Nothing shown to a user was ever
wrong.** Corrected everywhere, correction left visible.

**Two more real findings from the check, both fixed:** `full_schema.sql` and the migration disagreed
about the column COMMENT (the rule says they must agree) — and **the harness does NOT compare column
comments**, so all 42 checks passed over it; and a docblock in `Newsletter.php` still said the
unimplemented branches "throw" when they fall back, so the file carried both versions at once.

**Release readiness, done:** translation (#485) ships off but looked finished if enabled — its page
now says nothing is being translated; newsletter documented three sending services when one exists —
header, docblock and page all corrected. **Checked and NOT a bug:** the `.enabled` defaults mix
`'0'`, `'false'` and `'true'`, which looked dangerous because `(bool)'false'` is true in PHP — but
every reader uses `=== '1' || === 'true'`, never a cast.

**Two limits recorded, neither hidden:** a whole-database restore of a pre-224 snapshot holding
duplicates is refused (`restoreAll()` stops on any rejected row) — the way out is a one-table restore,
which works because this table is not on `WHOLE_DATABASE_ONLY`; and the harness's comment blindness
above.

**THE PATTERN TO WATCH IN THIS WHOLE RUN:** three review rounds, all NOT CLEAN, **every finding about
TRUTHFULNESS rather than behaviour.** The engineering held each time; the write-ups kept overstating.

**Next:** continue the release-readiness sweep. Found while reading and NOT yet investigated — a
walk-in cannot be un-marked (`event-attendance-mark.php` handles removal only for `$userId > 0`, with
a comment deferring walk-in removal to "a v1.1 admin UI"), so a mistyped walk-in name may be
permanent, and migration 224 makes re-adding the corrected name a no-op if the typo is still there.
Also still open: #641 (api-spec vs handlers) and #569 (pre-push hook, visual audit).

## 28 OCTOBER 2026, LATEST — the migration harness now runs on MariaDB (#487), commit `97afe09`

The owner asked why the end-to-end migration harness could not run on MariaDB, and whether it
could be changed so it can. It can, and it now does: **all seven phases and all 42 checks pass on
MariaDB 11.4.13 — the same 42 that pass on MySQL 8.0.36 and 8.4.11.** `e2e-migrations.yml` runs
three jobs side by side.

**Neither blocker was in the portal.** MariaDB 11.x ships no `mysql` or `mysqladmin` program (only
`mariadb` and `mariadb-admin`), and the version check compared the server's WHOLE reported string,
which `11.4.13-MariaDB-ubu2404` never matches. `E2E_DB_ENGINE` is now the ONE place deciding the
image and both client names. The version check takes the leading three-part number: the moving-tag
guarantee is intact (`8.4` still fails against 8.4.11) but it IS looser in one way — it accepts
`8.0.36-log` — and must never be described as "exactly as strict as before".

**The real difference it found, and the wrong first guess.** Phase 2 failed because two `tblEvents`
links read `on update RESTRICT` where MySQL says `NO ACTION`. The engines do NOT normalise rules:
measured on all three, a DECLARED rule is recorded faithfully and only the default for an OMITTED
clause differs. Migration 179 omits `ON UPDATE`, which is why that pair showed it. The fold prints
the PAIR `NO ACTION/RESTRICT`; folding to one word was tried and was worse, because 26 links in
`full_schema.sql` DECLARE `ON DELETE RESTRICT` and a failure line would have named a rule the
schema does not contain. **What it gives up:** a declared RESTRICT vs NO ACTION is now invisible
(proved by planting) — harmless, but not catchable here.

**The lesson.** The comparison was never at fault; it is always same-engine and found the same two
links on both. The HARD-CODED expected list was at fault, written in one engine's spelling.

**Two review rounds, both NOT CLEAN, both right — and both about TRUTHFULNESS, not behaviour.**
Round 1: an untrue comment on a guard that cannot fire (the image and the client come from one
variable, and `wait_for_mysql` dies first), plus "byte-identical on MySQL" reached by diffing a
PASSING run. Round 2: four more, including **"MariaDB cannot tell them apart", which my own earlier
measurement disproves**, and "a passing run never prints a link line" (it prints exactly two — the
known-exceptions block — and those two are the lines this change reworded). All fixed, corrections
left VISIBLE in the notes. **The pattern to watch in this package: the engineering held every
round; the write-ups kept overstating.**

Two stale untruths found on the way and fixed: DEV_NOTES said "the test cannot see MariaDB", and
its phase list said "six phases" and listed six — phase 7 was added 13 October and never added.

**NOT verified:** nothing has run on GitHub. The matrix expansion and whether a MariaDB job fits
`timeout-minutes: 20` are reasoned from local timings. Codex has not reviewed this.

**Next on #487:** the owner's other concern — re-verifying the database shape, starting with
uniqueness rules on columns that can be empty. **No work done on it at all yet.**

---

## 28 OCTOBER 2026 — #653 and #652 both FIXED (the two items left as "Found")

The owner asked for both to be done now rather than carried. Both are, with evidence.

### #653 — a repeating date in the hour that happens twice came out an hour short

`IcsReader::laterOfARepeatedReading()` is the ONE place that decides what a reading in the
repeated hour means: the LATER of the two moments sharing it. Called from `buildOccurrence()` and
from the length measurement in `prepareEvent()`.

**Corrected 28 October 2026:** this entry used to say the later reading "is what `makeDate()`
already gave a one-off, so the two paths now agree by construction". **That is false and it is the
exact belief that made this fix a no-op twice.** `makeDate()` does NOT call
`laterOfARepeatedReading()` — grep it — and plain PHP does not give the later reading at all: it
gives standard time in Europe but DAYLIGHT time across the Americas. The two paths agree because
check I34c holds them together, never by construction.

**The symptom was wider than the issue said.** It reported zero length. Measured: a daily
01:30–05:30 series read `01:30 → 04:30` — **three hours instead of four**. The fault is "an hour
short"; zero is what that looks like on an hour-long event.

**Two things a future reader must not undo:**
- It **cannot move into `generateStarts()`.** The recurrence key is the start turned into UTC and is
  STORED as `externalRecurrenceKey`; resolving earlier renames every such date and the importer
  deletes and re-adds it. Check I34b pins the key.
- It takes **the zone the reading was WRITTEN in, never the organisation's.** A `Z` time is an
  instant, not a reading. **The first version got this wrong and moved a real event an hour late**;
  all 357 reader checks passed and only part G of `feed-importer-selftest.php` (database + web
  server) caught it. I34h now guards it in the test that needs neither, and planting proved it is
  the SOLE catcher.

Nine new checks (I34–I34h), six new fixtures. **Five faults planted, all five caught.** One plant
"passed" at first because `modify('-1 hour')` is a WALL-CLOCK operation — it fell through its own
condition and changed nothing. That looked like a hole in the checks and was not.

### #652 — a test that went red by itself as the year turned

`import-notifier-selftest.php` asked for two London clock changes in the twelve months from
*today*; from 25 October 2026 the window held one. Now anchored to a FIXED date
(`CHANGE_NIGHT_ANCHOR`, 1 January 2027). **Measured day by day over the 1,095 days from 25 October
2026:** the old shape fails on **20** of them, in four windows — 25–29 Oct 2026 and 25–29 Mar 2029
(London), 2–6 Apr 2028 and 2–6 Sep 2029 (**Santiago**, a latent fault nobody had hit). The new
shape fails on none. The issue's "until late March 2027" was wrong, and so was my own first figure
of "five days", which round 1 caught; both are corrected on the issue. The four nights are now
written out BY HAND in the test, so a time-zone data update cannot leave the comment quietly
untrue; and "fewer than two changes" reports SKIPPED, not FAIL.

### ROUND 1 OF THE INDEPENDENT CHECK FOUND THE FIX WAS HALF-DONE — now corrected

**`new DateTimeImmutable($text, $zone)` does NOT give the later of two ambiguous moments.** It
takes the first matching offset in the zone's table: standard time in Europe and Australasia, but
**DAYLIGHT time across the Americas**. So the first fix was a **no-op in New York, Chicago,
Los Angeles, Havana, Santiago and the Azores** — including America/Santiago, this repository's own
non-European test zone — and #653 stayed live there while all 357 checks passed, because
`ics-reader-selftest.php` hard-codes `$orgZone` as Europe/London and every new fixture was in it.

**The rule read one clause further on:** not "a zone that really changes" but **a zone that
resolves the OTHER way.** The helper now scans the zone's own transitions and keeps the latest
instant carrying the reading, with a cheap offset pre-filter so the transition array is only built
when it can matter. Verified across **fifteen** zones including Lord Howe (30-minute change) and
Chatham. New section I34i covers New York and Lord Howe directly.

Round 1's other three findings, all accepted and fixed: four documents stated the rule as
universal; the `buildOccurrence()` comment's EXDATE reason was BACKWARDS (an `EXDATE;TZID=` already
fails to match — moving the resolution earlier would make it START matching); and the DEV_NOTES
"#652 is five days" figure was wrong — re-measured, it is **20 days in 1,095, in four windows**.

**Found and filed, not fixed: #654** — a date cancelled with `EXDATE;TZID=…` **comes back** if its
reading falls in the repeated hour, and that is the form Google, Apple and Outlook all write.
Pre-existing; cannot be fixed where #653 sits, for the same stored-key reason.

### ROUND 2 FOUND IT HAD SHIPPED AS A NO-OP A THIRD TIME — now fixed

**`DateTimeImmutable::setTimestamp()` does not reliably set the instant it is given.** On an object
carrying a named zone PHP converts to the wall clock and resolves AGAIN, so when the instant asked
for is itself inside a repeated hour it snaps back to PHP's first-matching offset. Measured in
America/Metlakatla: `setTimestamp(1446373800)` returns **1446370200**. So the candidate built for
the later offset came back as the INPUT instant, never newer, never chosen — and the rewritten
helper collapsed to PHP's pick again.

Swept every zone PHP knows, 1900–2050, against an exhaustive reference: wrong in **81 zones** of
14,454 ambiguous readings. After building each candidate as
`(new DateTimeImmutable('@' . $ts))->setTimezone($writtenIn)`: **0**. The same unsafe construction
was in the cheap pre-filter and is fixed there too.

**Every affected case was a PERMANENT base-offset move** — standard time both sides, a country
changing its clocks for good. Today's data has none after 2025, so nobody is wrong now; that is the
luck of the data, not the code. I34i could not see it because every zone in it changes for SUMMER
time. **Check I34j** (Metlakatla 2015) pins it, and planting `setTimestamp()` back confirms I34j is
the only section that fails.

Round 2's other findings, all accepted: `"45 (Pacific/Chatham)"` was a false measurement (45 minutes
is Chatham's OFFSET; its change is an ordinary 3,600s); DEV_NOTES still carried the backwards EXDATE
clause corrected in three other places; and CHANGELOG turned a one-off fifteen-zone sweep into a
standing claim about the tests.

**One of my own new checks was vacuous and I caught it in writing it.** I34j's third check asserted
WHICH instant was chosen, joined by `||` so it would pass: branch A can never pass and branch B
compared the reading with itself. Deleted, with the reason recorded — **a stored reading does not
record which of the two moments it names**, so the stored END reading is the only observable.
*An assertion joined by `||` so that it will go green is not an assertion.*

**THIS IS ROUND 3 OF THE CHECK, AND THE PROJECT'S RULE SAYS TO SAY SO.** One fix, three independent
rounds, three no-ops — each shipped with every committed check green. Ways to go faster are under
"Blocked on me" in the report.

### ROUND 3 FOUND A FOURTH FAULT — and this one STORED WRONG TIMES

The first three were no-ops: the fix failing to fix. This one is different.

`$lengthSeconds` was measured between the two moments `readDateTime()` returned, and that resolves
an ambiguous reading with PHP's pick — the EARLIER across the Americas. So a New York `DTSTART` of
01:30 was read as EDT while its `DTEND` of 02:30 was read as EST, and **the subtraction absorbed the
clock change**: a one-hour event measured 7,200 seconds. Pre-existing for the second and later dates
of a series; **#653 made the first date and the one-off wrong too**. Measured, file says 01:30-02:30:

```
before #653   01:30 -> 02:30,  01:30 -> 03:30,  01:30 -> 03:30
with #653     01:30 -> 03:30,  01:30 -> 03:30,  01:30 -> 03:30
now           01:30 -> 02:30,  01:30 -> 02:30,  01:30 -> 02:30
```

Fixed in `prepareEvent()`: BOTH ends resolved the same way before the length is measured. That makes
every zone answer as Europe/London already did — which is why no London check caught it. **Check
I34k** is the only thing that does.

### THE SWEEP NOW RUNS IN CI — my own reason for leaving it out was wrong by 100x

I had written that the all-zone sweep "took about a minute" and so did not belong in a
per-pull-request job. **Measured: 419 zones, 334,240 readings, 145,265 ambiguous, in 1.4 seconds.**
It is now `tools/ics-zone-sweep.php`, a hard gate in `calendar-selftests.yml`, run `--dense`.

`--dense` matters: light sampling starts at 1900 and MISSES the pre-filter form of fault 3 entirely
(the readings that expose it sit a DAY apart, at the 1867 Alaska / 1892 Samoa / 1899 Rarotonga
date-line moves). That plant is caught by `--dense` and **by nothing else** — not the light sweep,
not any of the 368 self-test checks.

Round 3's other findings, all verified and corrected: the Lord Howe instants were 30 minutes out;
"every affected case was a base-offset move" was untrue (10 transitions in 7 zones had summer time
on one or both sides); "no such move after 2025" was untrue (Casablanca and El_Aaiun, 20 Sep 2026 —
the conclusion survives only because PHP resolves THEIR repeated hour to the later, i.e. luck);
88-vs-81 was a sampling difference and is now quoted with its sampling; the plant table had two rows
that had stopped being true; and the pre-filter counts differ between London and New York at 01:30.

### THIS IS NOW FOUR ROUNDS ON ONE ITEM — the rule says consider SIMPLIFYING

`.claude/CLAUDE.md`: "If the same item fails two rounds running, consider simplifying the design
before refining it again." My judgement, for the owner to overrule: **the design is not the problem.**
"Find the latest instant whose reading equals this text, in this zone" is about as simple as the
question allows. All four faults were PHP API traps — the constructor's resolution order,
`setTimestamp()` re-resolving, and a length measured across two inconsistently-resolved moments —
not design complexity. What was missing was an instrument that searched the space instead of five
hand-picked points, and that now exists and runs. The alternative (revert #653, reopen it, accept a
weekly service displaying 01:30-01:30) is under "Blocked on me".

### ROUND 4 FOUND SIX MORE — two behavioural, and one of them a REGRESSION against main

**Half the fix could be deleted and nothing noticed.** Resolving only the `DTSTART` and leaving the
`DTEND` raw passed **all 368 checks AND the all-zone sweep**, while the behaviour really changed: a
start of 00:30 with an end of 01:30 in New York became a one-hour series where its London twin
stayed two. The comment beside the code **named I34f as the guard** — and I34f's fixture is London,
where both calls are no-ops, so it could never have seen it. Check **I34l** now does.

**An event could LOSE ITS END ENTIRELY.** A local-time start with a UTC end
(`DTSTART;TZID=America/New_York:…013000` + `DTEND:…060000Z`, which Microsoft produces) had the
resolved start pushed PAST the end, the pair stopped making sense, and the code fell through to "no
length": stored `01:30 → 01:30`, shown as a start time only, **no warning**, where `main` stores
`01:30 → 01:00` — an END BEFORE THE START, so that shape was ALREADY broken before this package
(round 5 measured it; my own note had said `01:30 → 02:00`, which made the released behaviour sound
fine). The length now falls back to the FILE's own pair when the resolved pair is incoherent, which
repairs the pre-existing fault too. Check **I34m**.

Both plants are now caught by exactly the check written for them, and resolving only the `DTEND` is
caught by I34k alone.

### Three untrue statements in the one paragraph meant to say what the code CANNOT do

- "reading both back … gives the full length" — true in London, FALSE in the Americas.
- "LOOKS an hour short" — it is short by the size of THAT NIGHT'S change (30 min in Lord Howe).
- "it does not make the two paths produce the same INSTANT" — they DO; both pass through the single
  call in `buildOccurrence()`. Measured with UTC as the organisation's zone: both 06:30Z. **That
  paragraph has now been wrong in BOTH directions** across rounds — first claiming agreement "by
  construction", then denying agreement that exists.

### The sweep reported PASS over nothing

Its own header calls that "worse than no sweep" — and it did it: an emptied reference gave
`ambiguous: 0`, no mismatches, PASS, exit 0. There is now a floor (under 2,000 ambiguous readings
REFUSES, exit 1, against 145,265 today). Two more header claims were untrue and are fixed. Also:
the counts "81/82/88" were over 1900-2050; the gate runs 1850-2100, where the same plant gives
**117** — the year span matters more than the sampling, and both are now quoted.

### ROUND 5 (FINAL) FOUND A SEVENTH FAULT — the fourth `setTimestamp()`, now fixed and committed

`buildOccurrence()` built the END as `$startLocal->setTimestamp(...)`. `$startLocal` is the RESOLVED
(later) start, so an event **shorter than that night's change** had its end inside the repeated hour
too, the snap pulled it back before the start, and the clamp turned that into **no length at all**:
a fifteen-minute event stored `23:30 → 23:30`, no warning. **The released code stored it correctly,
so this was a regression.** 24 zones, 17 transitions after 2000 (Argentina ×9, Cambridge Bay, Santo
Domingo, Caracas, Ciudad Juárez, Eirunepé, Rio Branco, Metlakatla ×2). `DURATION` was never
affected.

**It is the same trap this class documents at length** — and the comment sitting directly on it
claimed `setTimestamp()` was safe there, which was true about the ZONE and silent about the INSTANT.
Check **I34n** is the only thing that catches it; the all-zone sweep cannot, because it tests the
resolution rule and not how a resolved start is used.

Round 5's other findings, all verified and corrected:
- **"where the released code stores `01:30 → 02:00`" was untrue in four files.** It stores
  `01:30 → 01:00` — an end BEFORE the start. So the fallback repairs a PRE-EXISTING fault too, and
  my wording had told the next reader the opposite.
- **"6,822 readings of that shape" could not be reproduced** (three reasonable samplings give
  7,959 / 3,411 / 4,548). Dropped rather than restated.
- **"which Microsoft really does produce" is unsupported from here** — and two of this repository's
  own skipped checks record that no real Microsoft export has ever been captured. Reworded to what
  is checkable: RFC 5545 permits the shape.
- **CHANGELOG still generalised "reads back correctly"**, which is a Europe-only property.
- **The plant tables were stale again**, missing I34l and I34m. Re-measured; one row removed as
  unreproducible (no fixture yields a `lengthSeconds` of 0, so that experiment cannot be run).
- The sweep's `--zone=UTC` refusal said "should find thousands" when the floor for one zone is 1,
  and an unknown zone name died with an uncaught exception. Both fixed.

### One deliberate consequence the owner should know about

A series whose **first instance's `DTEND`** is the ambiguous reading now stores every LATER date two
hours long where the released code stored one. That follows from the exact-length rule (the owner's
decision of 23 September 2026) plus resolving both ends, and it is what Europe/London already did —
but on an ordinary night the stored pair no longer matches the file's own readings, and Google, Apple
and Outlook would show one hour. Narrow (the series must START on the change night), member-visible
from now on, and never an explicit decision — only a consequence of two that were. Written up in
DEV_NOTES and CHANGELOG.

### A watchdog trap, worth one line before somebody repeats it

`tools/watchdog.sh quiet` was pointed at a **subagent's harness transcript file**
(`<session>/tasks/<agentId>.output`). It fired a false "finished or stalled" after 920 seconds while
the agent was working perfectly well: the harness ROTATES that file, so it read 1.2 MB to `cat` and
220 bytes to `stat` moments apart, and its modification time stopped moving. What proved the agent
alive was its own work — the test calendar server still listening on the port it had been given.

The rule's own example watches a file **the agent writes** (`.claude-work/resume/<report>.md`), not
the harness's transcript. Watch something the work itself produces, or use a long backstop and let
the completion notice be the primary signal.

### Where this leaves things

Tests all green: ics-reader **375**, import-notifier 134/134, feed-importer 139, feed-admin 934,
feed-resolver 208, event-visibility 192, and the all-zone sweep **0 wrong of 145,265 ambiguous
readings**. All 36 audit checks pass and their output was READ, not just their exit codes.
`calendar-selftests.yml` parses under Ruby's YAML reader and the new step's name is QUOTED (the `#`
truncation trap). #652 and #653 carry comments with the corrections; **#654** is filed for the
pre-existing `EXDATE;TZID=` fault. **THE OWNER CHOSE: ONE MORE BOUNDED ROUND, THEN COMMIT (28 October 2026).** Asked to choose between
committing now, one more bounded round, or reverting #653 and reopening it, the owner took the
middle option. So round 5 is running, told plainly that it is the LAST round and that the work is
committed after it reports whatever it finds — which is why its brief asks it to rank its findings
as must-fix-before-commit or not, and to say what it did not get to.

What round 5 is NOT asked to do: re-derive the resolution rule. That is settled by two independent
exhaustive sweeps (419 zones, 1850-2100, 1.65M readings, 229k ambiguous, 0 mismatches) and the
sweep now runs in CI. Every fault after the first was in HOW the resolved value is used, which the
sweep structurally cannot see, so round 5's effort goes there — chiefly the new file's-own-pair
fallback.

(An earlier version of this very paragraph said "Round 5 is running" before the owner had decided,
and then said no round was launched. Both were written within minutes and one was always going to be
untrue. Recorded rather than overwritten, because it is the same class of fault — a confident written
statement that is not true — that rounds 2, 3 and 4 kept finding in my code comments.)

**Nothing is committed.**

---

## 28 OCTOBER 2026 — the sign-in hotfix for `main` is built and WAITING ON A PUSH

**`hotfix/sign-in-1.4.2`, commit `75e83a1`, in a worktree at
`<scratch>/hotfix`.** Three files, 83 lines, of which **19 are code**. NOT pushed — that is the
owner's, and it is the one thing blocking the advisory.

### It closes TWO of the four holes, by the owner's decision

A cherry-pick was never possible: `Auth.php` on this branch differs from `main` by **+1851/-41**
across a dozen commits (several organisations, address rewriting, secret settings, the channel,
sign-in limits). So the fix was written against `main`'s own code.

| Hole | In 1.4.2? | Why |
| --- | --- | --- |
| 1, OAuth `state` accepted with no sign-in started | **yes** | two lines each in both callbacks |
| 2, second factor skipped by `?redirect[]=x` | **yes** | `safeRedirectUrl()` takes `mixed`; the gate unsets first |
| 0, Microsoft/Google/passkey skip two-factor entirely | no | **`main` has NO gate on those routes at all** |
| 3, the passkey route asks for nothing | no | same |

**The owner chose this on a measurement, 28 October 2026.** On `main`,
`git grep -c userRequires2fa` finds one hit in `Auth.php` (the definition) and none in
`webauthn.php`, so closing holes 0 and 3 there means writing NEW security code in three places
against a codebase 568 commits behind, which this branch's tests cannot exercise. They are already
fixed and tested on this branch, so they ride the release. Severity agrees with the cost: hole 1
needs nothing from the victim, hole 2 needs their password alone, holes 0 and 3 need control of
their Microsoft or Google account.

### Measured against `main`'s own code, not this branch's

- The gate-order check — written AFTER `main`'s last commit, pointed at the tree with `--web` —
  **FAILS on unpatched `main`**, naming lines 107 and 108, and **PASSES on the hotfix**.
- The OAuth condition over six cases: the old one **ACCEPTED** "no sign-in started, address carries
  `state=`" and threw a TypeError on `state[]=x`; the new one refuses both and still accepts a real
  sign-in with a matching value.
- `php -l` clean. **This branch's test suite was NOT run against it and cannot be** — it assumes the
  newer architecture. Say so when reporting it.

**A trap worth remembering.** The first run of that check reported "all 4 two-factor gates take the
sign-in away first" and looked like a pass. It had scanned THIS branch, because the script resolves
its root from `__file__` and ignores the working directory. `main` has ONE gate, not four, and that
number is what gave it away. Its `--web` flag then crashed on a path outside the repository
(`relative_to()` raises), fixed in `d76369d`. **A check that silently measures the wrong tree is
worse than one that crashes.**

### Advisory: both open points are now decided

Hold until `main` is fixed, publish as ONE. The draft
(`.claude-work/resume/security/ADVISORY-DRAFT--two-sign-in-holes.md`, git-ignored) now carries the
per-hole split and the evidence, so publishing is mechanical once the hotfix is merged.

### Note on worktrees

There are 24 worktrees from earlier sessions under `.claude-work/worktrees/`, plus mine. **Not
touched** — the standing rule is to preserve active worktrees and never prune without exact
reviewed candidates and the owner's approval.

---

## 28 OCTOBER 2026, LATEST — every check runs now (#646)

**Not committed when this was written; see the commit after `6985b38`.**

Ten audit checks and two self-tests were invoked by NO workflow: 26 of 36 checks and 51 of 53
tests were wired. Each guards something found broken at least once. All twelve now run on every
pull request; the ten checks are hard gates (`if: always()`, no `|| true`). **Now 36 of 36 and
53 of 53.**

The one that mattered: `check_2fa_gate_order.py`, the tripwire for the 27 October sign-in bypass,
whose own note says the self-test CANNOT catch a re-reversal of the order. The half that would was
the half running nowhere. Proved both ways — with the `unset()` moved back below the redirect it
fails and names the file and both lines.

### The independent check found FOUR faults in the wiring, two of them runner-only

1. **`gd` was missing from the job's extensions.** `qr-selftest.php` REFUSES without it, so the step
   would have reddened the job and checked nothing. **No workflow here had ever asked for gd**, it is
   not in `setup-php`'s defaults, and no other test uses it — so no green run had ever proved the
   runner had it. It passed locally because this Mac does. **Ask what a test NEEDS, not whether it
   passes here.**
2. **`id: 2fa_gate_order` begins with a digit**, which may make the whole of `pr-security.yml`
   invalid — taking all twenty-four existing checks with it — and `steps.2fa_gate_order.outcome` is
   not valid syntax anyway. Renamed `twofa_gate_order`.
3. **A count in the header comment was already wrong** ("thirteen" when there were fourteen, missed
   when #640 was wired) and this change made it fifteen while correcting two others four lines below.
4. **The qrencode fallback could never work**: the install step had no `continue-on-error: true`, so
   a failed `apt-get` failed the job and the fallback branch would print into an already-red run.

Also taken from its Polish: `page-scripts-selftest.php`'s DEFAULT port was 9770 — the port the
activity-log test is given in the same job — so dropping its env line would have collided silently.
Moved to 9651. And the job summary now reports the ten as one line, proved by extracting the summary
shell and running it with outcomes substituted, in both the all-passed and the something-failed case.

### Ruby parses YAML, so workflows are validated locally now

There is no `actionlint`, `yq` or `pyyaml` on this machine, but Ruby is there:
`ruby -ryaml -e 'YAML.load_file(ARGV[0])'`. That found twelve of my own step names silently
truncated (a `#` with a space before it starts a comment, so `(hard gate, #646)` became
`(hard gate,`), confirmed every `if:` points at a step id that exists in the same job, and proved
no port collides. **This retires the "actionlint in CI is what will confirm it" caveat** attached to
two earlier commits — all 15 workflows now parse before a push.

### Verified

36/36 audit checks; page-scripts 88/88 on both the new default port and the workflow's explicit one;
qr-selftest 67/67 on the fallback branch; all 15 workflows parse; no port or database clash; three
of the ten gates proved to refuse their exact fault. **Not proved:** that any of it runs on GitHub —
that needs a push, and two of the four faults above are exactly the kind only a runner can disprove.

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
