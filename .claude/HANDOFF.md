# Handoff — branch clean-up, full audit, documentation refresh

**Updated:** 2026-09-11 (session in progress — this file is kept current as work
proceeds, so the session can be picked up at any point).
**Working branch:** `claude/alpha-wip` — the single work-in-progress branch.
**Target:** one pull request into `alpha` at the end. No stacked pull requests.

---

## Read this first — where we are right now

## LATEST — 23 September 2026, about 06:00. RESUME FROM HERE.

**#514 P5 (reading calendar files): round-3 FIXES ARE DONE; Fable's ROUND-4 CHECK IS RUNNING.** Still uncommitted, still the same
five untracked entries. The working tree matches `.claude-work/resume/p514-p5-built-20260923-fixes3/fingerprints.txt` (54 files,
verified by me), nothing staged, nothing stashed, no leftover scratch, no stray servers or containers.

**What the third fix round did, and why it is different from rounds 1 and 2.** The brief told it to stop fixing shapes and fix the
CLASS of fault. It did. The rule is now in the class header and in one shared helper, `IcsReader::tooManyPieces()`, which counts
separators with `substr_count()` — a count that builds nothing — before any split happens:

> **Never turn text that came out of the file into an array before you know how many pieces it will make.**

It published an audit table of EVERY place the reader turns untrusted text into a list: ten newly bounded, ten already bounded or
not arrays at all. That table, not six passing files, is what shows the class is covered. New counted limits: settings on one
property line (64), category pieces (200), repeat-rule parts (32), time-zone name length (200) and parts (16), and warnings (100,
with exact repeats dropped as they arrive).

**It found three more of the same class while auditing** — which is the strongest evidence the audit was real. A seventh fatal (the
same semicolon line INSIDE an event, where round 3 had only measured it outside one), and two shapes that exited 0 but at **114 MB
and 116 MB of the 128 MB PHP usually allows**, in a process doing nothing else: a flood of warnings, and a million real short
categories. A real importer starts with a database connection, settings and a brand layer already in memory, so those two are over
the line in practice even though a bare test process survives them.

**Numbers.** All seven fatal shapes now exit 0 at 17-29 MB with a warning and the right flag. The two 114/116 MB shapes now finish
at 17 MB. All 47 hostile files exit 0; none of the earlier 24 regressed. The self-test went **184 → 227 checks passed, 0 failed,
2 skipped**, and nine bounds were removed one at a time in a sandbox copy — every plant made it fail, the comment-only control
passed. `php -l` clean on all four files (I re-ran it myself: rc=0 ×4).

**Two faults it found in its own test machinery, worth knowing because they would have hidden real faults:** a child process that
never loaded `WindowsTimeZones`, so a missing class read exactly like the fatal being tested for; and line-limit checks that passed
in-process for the wrong reason (the memory guard fired first, not the line limit).

**ROUND 4 IS RUNNING NOW** — Fable, background agent, brief `.claude-work/briefs/514-p5-check.md` (its new "ROUND 4" section says
what changed and where to attack), report `.claude-work/resume/p514-p5--verify-r4.md`. It is asked to attack the audit table for
something it missed, to check whether any NEW limit fires on a calendar a real customer might have, and to build its own ordinary
calendar rather than trusting the rebuilt control.

**Why this many rounds is right, not excessive.** P6 runs this reader in a scheduled job across every customer's calendars. A file
that kills the process ends the whole run for everyone, and no code can catch it. The feeds are untrusted by definition. Three
rounds have each found what the round before missed, so the owner's standing rule applies exactly as written: **keep going until a
round finds nothing.**

**WHEN ROUND 4 COMES BACK CLEAN:** run the standard checks myself (php -l ×4, all 20 audit checks, `check_static_calls.php`, every
`tools/*selftest*.php`, `generate-windows-timezones.php --check`), commit ONLY P5's five entries with a message saying plainly that
Codex has NOT reviewed it, push, comment on #514, then start P6 (`args: { part: "P6", tier: "opus" }`, migration 205).
**If it comes back NOT CLEAN:** another fix round, same pattern.

**Acceptance criterion 3 is still NOT PROVEN** and every place that says so must keep saying so: there are no real Google or
Microsoft 365 export files in this repository (owner decision, 21 September 2026). The self-test prints two SKIPPED lines for it.

## LATEST — 23 September 2026, about 04:35. RESUME FROM HERE.

**#514 P5, round 3 (Fable): NOT CLEAN — six more uncatchable fatals, all one over-long line, all under the fetcher's 5 MB.** A THIRD
FIX ROUND IS RUNNING (Opus, brief `.claude-work/briefs/514-p5-fix3.md`, report `p514-p5--fixes3.md`). P5 still uncommitted;
fingerprints in `p514-p5-built-20260923-fixes2/` until that round saves its own.

**The pattern, stated plainly, because it has now caught us three times:** every round's fix has been too narrow. Round 1 fixed the
shapes measured; round 2 found four more and bounded the comma lists INSIDE a repeat rule's BY… values; round 3 found six more, each
one a DIFFERENT `explode()` reached before any counted limit or the memory watch:
`splitList()` at `:841` (CATEGORIES, EXDATE, RDATE — one array slot per comma, and CATEGORIES then copies every piece again),
`parseRule()` at `:1362` (the rule's own semicolons), `parsePropertyLine()` at `:701` (a property line's semicolons, inside `parse()`
where no per-event limit applies), and `resolveZone()` at `:1131` (a TZID's slashes, with an O(n^2) loop behind it). Growth is about
64 MB per 4,000,000 separators.

**The fix brief therefore demands the CLASS, not the shapes:** never turn untrusted text into an array before counting how many
pieces it will make (`substr_count()` allocates nothing). Its chief deliverable is an AUDIT TABLE of every split in the file with its
bound — that table, not six passing files, is what shows the class is covered. Also: the 500,000-line cap is off by one with a
trailing newline; the self-test has no guard for any split path (all four unbounded still passes 184/0/2); and the header over-claims.

**Ordinary calendars are untouched** (control: 66 KB, 300 weekly series, exit 0, 14 MB), and round 3's correctness spot-checks all
passed on top of the 184 self-test checks, which it read in full.

**Then: round 4 with Fable.** Keep going until a round finds nothing — the owner's standing rule, and it is earning its keep here.

## LATEST — 23 September 2026, about 03:50. RESUME FROM HERE.

**#514 P5: the round-2 findings are all FIXED, and FABLE'S ROUND-3 CHECK IS RUNNING.** P5 is still uncommitted (5 entries, 50
fixtures). **Current fingerprints: `.claude-work/resume/p514-p5-built-20260923-fixes2/`** — the `-fixed` and `20260922` folders are
older states and no longer match. Reports so far: `verify-r1`, `fixes`, `verify-r2`, `fixes2`, and `verify-r3` being written.

**The second fix round bounded the CAUSES, not the shapes that were measured** (which is the mistake round 1 made):
- `parse()` no longer builds an array of every line; it walks line endings one at a time, with a counted `MAX_LINES_PER_FILE`
  (500,000, justified from the folding rule in RFC 5545).
- The two gathering guards moved INSIDE the per-event and per-changed-date loops (they ran once per UID, which is not "per event"
  when the calendar chooses its own UIDs).
- The per-event date limit now applies to the whole merged list, so added dates (`RDATE`) go through it too, and the added/skipped
  lists are cut at a new `MAX_DATE_LIST_PER_EVENT` = **4,000, deliberately not 400**: skipped dates build up over a series' life and
  are written in date order, so a low cut would drop the RECENT ones and silently bring deleted dates back.
- Each `BY…` list is measured with `substr_count` BEFORE being split, refused when longer than the values it could possibly hold, and
  de-duplicated — which also fixed repeats eating `COUNT`.
**48 runs over 24 files at 128 MB: not one fatal.** A 31 KB same-UID file now ends 0 at 12 MB. The ordinary Google-shaped control
(648 events) is untouched: complete, not capped, no warnings, 10 MB. Time on the BY-list shapes, never seen before because they died
of memory first: 0.00 s.

**The self-test: 140 → 184 checks**, five new fixtures, and a mutation run planting SEVENTEEN faults one at a time, every one now
failing it — including the nine round 2 found (Google's all-day changed date, a private series' changed dates, Microsoft 365's
comma-separated skipped dates, `COUNT` before the window, and the rest) and guard checks for the new limits themselves.

**One honest limit the builder recorded:** with BOTH gathering guards removed outright, the self-test dies with a fatal at I9 instead
of printing FAIL. CI still fails, just less helpfully; the comment beside I9 says so. Round 3 is asked to confirm that.

## LATEST — 23 September 2026, about 00:45. RESUME FROM HERE.

**#514 P5, round 2 (Fable): NOT CLEAN again, and it matters. A SECOND FIX ROUND IS RUNNING** (Opus background agent, brief
`.claude-work/briefs/514-p5-fix2.md`, report `.claude-work/resume/p514-p5--fixes2.md`). P5 is still uncommitted; current fingerprints
in `.claude-work/resume/p514-p5-built-20260923-fixed/`. The check report is `p514-p5--verify-r2.md`.

**Round 2 confirmed all seven round-1 fixes are real, then answered the brief's own question — can a file the fetcher allows still
kill the reader? — with YES, four more ways, all inside the 5 MB cap:**
- (a) a file of bare line breaks (4-5 MB): `explode()` at `:314` builds every line BEFORE the first memory check at `:331`;
- (b) **250 never-ending series sharing ONE UID — 31 KB**: the guards at `:1986-1994` run once per UID, not per event;
- (c) ONE event with 100,000 `RDATE` moments (1.6 MB): `RDATE`s are appended with no per-series cap;
- (d) a rule repeating one BY… value 300,000 times (900 KB): dies inside `parseRule()` at `:1225`. Nothing de-duplicates the lists,
  which also makes repeats eat `COUNT` (`BYMONTHDAY=1,1;COUNT=3` gives two dates).
**Ordinary calendars are untouched** (648 events, 1,816 dates: complete, not capped, 10 MB), which is the other half of the test.

**And the self-test still passes on NINE realistic faults**, three that would bite in real life: an all-day changed date the way
GOOGLE writes it; a private series not keeping its changed dates private (a leak the class promises to prevent); and a comma-separated
`EXDATE` line, which is how MICROSOFT 365 writes skipped dates. It also found `yearly-count.ics`'s own note untrue (its third date is
outside the window), so that check never exercises `COUNT` stopping a series.

Plus: exactly 6,000 events is called incomplete (the same edge as round 1's 400), and four comments describe guards as per-event when
they are per-UID.

**When the fix round reports: re-check with Fable (round 3).** This is the third time a Fable check has found things four Opus rounds
would not have. Keep Fable as the checker for every remaining part.

## LATEST — 23 September 2026, about 00:20. RESUME FROM HERE.

**#514 P5: all seven findings from Fable's check are FIXED (Opus builder), and FABLE'S ROUND-2 CHECK IS RUNNING.** P5 is still
uncommitted. Reports: `p514-p5--verify-r1.md` (round 1), `p514-p5--fixes.md` (the fixes), `p514-p5--verify-r2.md` (round 2, being
written). **The current copy with fingerprints is `.claude-work/resume/p514-p5-built-20260923-fixed/`** — the `20260922` snapshot is
the build BEFORE the fixes and no longer matches.

**What the fixes did:**
- **Memory: three guards** — a cap on event blocks held while reading (6,000), a cap on dates gathered before the list is cut
  (6,000), and a check of `memory_get_usage(true)` against the process's `memory_limit` beside the deadline checks; `parse()` frees
  its copies of the file. Measured at 128 MB: a 2.52 MB file that used to die with a fatal now ends 0 with a warning and peaks at
  49 MB; the fetcher's full 5 MB limit peaks at 71 MB; an ordinary 2,000-event calendar is untouched. **The builder found a case the
  check had not: a 95 KB file holding 500 never-ending daily series killed the process just as dead** — no event count would have
  caught it, which is why the second cap exists. The header now says a fatal is very unlikely, NOT impossible.
- **The self-test:** seven new fixtures, 104 → 140 checks, and its own mutation run plants eleven faults one at a time, each of which
  now fails it (including RFC 5545's own worked example for `WKST`).
- Yearly `BYDAY` without an ordinal and yearly `BYMONTHDAY` are now SUPPORTED (52 and 12 dates, not 4 and 1); only ambiguous mixtures
  are refused. A backwards all-day end gives one day. 400 dates is no longer called cut short. The Windows list gains
  `Dateline Standard Time` (139). Invalid UTF-8 now loses only the bad bytes, not every accent.

**When round 2 reports:** fix anything found, re-check until clean, then my own standard checks, commit ONLY P5's five entries, push,
comment on #514, then P6 (`args: { part: "P6", tier: "opus" }`, migration 205).

## LATEST — 22 September 2026, about 23:35. RESUME FROM HERE.

**#514 P5's check came back NOT CLEAN (Fable, the first Fable check since the credits returned) and a FIX ROUND IS RUNNING** (Opus
background agent, brief `.claude-work/briefs/514-p5-fix.md`, report `.claude-work/resume/p514-p5--fixes.md`). P5 is still uncommitted.
The check report is `.claude-work/resume/p514-p5--verify-r1.md` — it is the most thorough yet (30 planted faults, 69 adversarial
cases, memory measured at five file sizes).

**The two that matter:**
1. **Memory is not bounded.** A 2.80 MB calendar — inside the 5 MB the P4 fetcher allows — dies with an UNCATCHABLE PHP fatal
   ("Allowed memory size … exhausted") at the default 128 MB, inside `buildOccurrence()`. In P6's job that would end the whole run.
   The class header claims every limit "ends the work quietly with a warning", which is not true of memory.
2. **The self-test passes on five realistic faults** (25 of 30 caught): `UNTIL` treated as exclusive (silently drops the last date of
   every ended GOOGLE series), `INTERVAL` ignored (fortnightly becomes weekly), dates before `DTSTART` not skipped, `WKST` ignored,
   and an all-day start pushed through a UTC conversion (invisible because every all-day fixture is in December).

Five low findings as well: two yearly rule shapes read silently wrong with no warning; an all-day event whose end is before its start
becomes three days; exactly 400 dates reported as cut short; the Windows list misses `Dateline Standard Time`; and comments that are
not true (including "keep the characters that are" while it drops every non-ASCII byte).

**When the fix round reports:** re-check with FABLE (it found what four Opus rounds' worth of checking on P4 would have taken longer
to find), fix until clean, then my own standard checks, commit ONLY P5's files, push, comment on #514, then P6 (migration 205).

## LATEST — 22 September 2026, about 23:05. NOT BLOCKED ANY MORE. RESUME FROM HERE.

**The limits lifted early.** At the owner's prompting I probed again: **Fable answered in 8 seconds and Opus in 5** — both are
available, well before the 27 September reset the error message named. **Always probe before believing a limit is still in force.**

**#514 P5's independent check is RUNNING, on FABLE** (the first Fable check since its credits ran out this morning), as a background
agent from the new brief `.claude-work/briefs/514-p5-check.md`; report `.claude-work/resume/p514-p5--verify-r1.md`. P5 is still
uncommitted (its five entries, copy and fingerprints in `.claude-work/resume/p514-p5-built-20260922/`).

**When it passes:** my own standard checks, commit ONLY P5's files, push, comment on #514, then P6 (`args: { part: "P6", tier:
"opus" }`, migration 205). The owner's two decisions of 22:40 stand otherwise: P5 is checked by a Claude agent (not Codex), and Codex
does ONE sweep at the end of the whole queue.

## LATEST — 22 September 2026, about 22:45. THE QUEUE IS BLOCKED UNTIL SATURDAY. RESUME FROM HERE.

**#514 P5 IS BUILT BUT NOT CHECKED, AND NOT COMMITTED.** Its builder finished; both checkers then died:
Fable "out of usage credits", then Opus **"You've hit your weekly limit · resets Sep 27 at 4pm (Europe/London)"**. So no helper
agent can check anything until **Saturday 27 September 16:00**.

**The owner decided (22:40):** P5 waits for a Claude agent check on Saturday — do NOT commit it unchecked, and do NOT have Codex
check it. Codex still does **one sweep at the end of the whole queue**, unchanged.

**WHAT IS IN THE WORKING TREE (uncommitted, 5 untracked entries):** `web/_core/IcsReader.php`, `web/_core/WindowsTimeZones.php`,
`tools/ics-reader-selftest.php`, `tools/generate-windows-timezones.php`, `tools/fixtures/ics/`. **A durable copy with fingerprints is
in `.claude-work/resume/p514-p5-built-20260922/`** (git-excluded but on disk), with `git-status.txt` and `fingerprints.txt`. The build
report is `p514-p5--build.md`. If the working tree is ever lost, restore from that copy and re-check the fingerprints.
**My own mechanical checks on it (NOT an independent check):** php -l clean on all four files; all 20 audit checks exit 0;
check_static_calls.php exits 0; its own self-test 104 passed, 0 failed, 2 SKIPPED (the real Google and Microsoft 365 exports, which
do not exist — owner decision 9). The dead checker's leftovers were cleaned up: container `p514-p5-check` removed, four `php -S`
servers on 9196-9199 stopped.

**ON SATURDAY, in this order:** (1) probe Fable first with a one-word agent task, then Opus; (2) run the P5 check as a background
agent with a brief file (the workflow tool's steps die on long commands) — reuse `.claude-work/briefs/514-p4-fix-check.md` as the model
and its "HOW TO RUN THE SELF-TEST WITHOUT BEING KILLED" section; (3) fix what it finds, re-check until clean; (4) commit ONLY P5's
files; (5) then P6 (`args: { part: "P6", tier: "opus" }`, migration 205).

**CODEX IS FIXED (22:35).** It had been refusing everything with "The 'gpt-6-sol' model is not supported when using Codex with a
ChatGPT account". Its config named a model this account cannot use; the model that worked here on 20 September is **gpt-6-astra**.
`~/.codex/config.toml` now says `model = "gpt-6-astra"` (backup: `~/.codex/config.toml.backup-2026-09-22`), and
`codex exec --skip-git-repo-check "..." < /dev/null` answers normally. **Run it with stdin closed**, or it waits for typing. A CLI
update is available (0.155.1 against 0.154.0); not installed. Connectivity and the ChatGPT login were both fine — only the model name
was wrong.

**WHAT IS AND IS NOT POSSIBLE UNTIL SATURDAY:** anything needing an independent check cannot be finished, and #514's parts must be
built in order, so P6 onwards cannot start either. This session itself still works.

## LATEST — 22 September 2026, about 22:15. RESUME FROM HERE.

**#514 P4 IS COMMITTED: `51cb46b`, pushed, #514 commented.** SafeFetch (the safe fetcher), Hymnal's stricter refusal, and
`tools/safefetch-selftest.php` (252 checks). **Five check rounds, every one by a separate agent, every one on OPUS** (Fable was out
of usage credits all day, refusing four times). Rounds 2-5's fixes were made by the ORCHESTRATOR after three builder agents stalled.

**The stalls are explained and the remedy is written into the briefs:** an agent is killed after 10 minutes with no output, and one
self-test run took 10-17 minutes when the network was slow. Agents must run long commands detached and poll them, printing a line
each time. Also: never read an exit code through a pipe, and read this test's output with `grep -a` (it prints one invalid byte on
purpose).

**What the rounds caught, worth remembering:** a self-test that printed "no outbound network" and passed while the fetcher was
completely broken; a certificate test that failed on macOS's own openssl over tooling, not a fault; and a fallback server that
listened on EVERY network interface (removed, not documented — measured afterwards: 0 listening sockets on the skip path, and only
127.0.0.1 otherwise).

**NOW: P5** (`args: { part: "P5", tier: "opus" }`): IcsReader and WindowsTimeZones with fixtures — repeats, skipped and changed
dates, Windows zone names, all-day ends, daylight saving. No network and no database, so its runs should be fast; the workflow tool
is fine for it. The real-export proof prints SKIPPED (no test calendars exist; owner decision 9). Reports `p514-p5--*.md`.

## LATEST — 22 September 2026, about 20:30. RESUME FROM HERE.

**#514 P4 is still UNCOMMITTED and in its fourth check round.** `SafeFetch.php` (`f054d157…`) and `Hymnal.php` (`598d9893…`) have not
changed since they passed check r1; every round since has been about `tools/safefetch-selftest.php`, the regression guard for the
fetcher. Reports: `p514-p4--verify-r1.md` … `r4.md`, and the builder's own `p514-p4--fixes.md` (rounds 1-3).

- **Round 2** proved the first four fixes and found four more faults (part F's connection skip still decided by SafeFetch; the
  certificate part FAILED on macOS LibreSSL; signals orphaned the servers; the K9 note too narrow). All fixed.
- **Round 3** proved all eight and found two: the header understated WHEN part I is skipped (and that a broken certificate setting is
  NOT caught on such a machine), and the bare-port fallback **listened on every network interface**. The fallback was REMOVED (only
  `-accept 127.0.0.1:port`; otherwise SKIP with the reason), the header corrected, the five-second wait shortened. Proven: normal run
  252/0/0 in 9 s; LibreSSL 248/0/1 in 9 s; **0 listening sockets opened by openssl during the LibreSSL run**; K1/K2 still fail where
  the server works.
- **Round 4 is running** (Opus; Fable refused again on credits, three times today).
- **Every fix round since the first was done by the ORCHESTRATOR** after three builder agents stalled. Reason found: an agent is
  killed after 10 minutes with no output, and one self-test run took 10-17 minutes while the network was slow. The checker brief now
  tells agents to run it detached and poll.
- Housekeeping: two `php -S` servers left listening since 20 September (#514 P2's scratch work, ports 9110/9111) were identified and
  stopped.

**WHEN ROUND 4 PASSES:** my own standard checks, commit ONLY the three P4 files (the message must say the orchestrator made the fixes
after three stalled builders, and that every check ran on Opus because Fable is out of credits), push, comment on #514, then P5.

## LATEST — 22 September 2026, about 14:40. RESUME FROM HERE.

**P4's four self-test fixes are DONE — by the ORCHESTRATOR itself, as a FALLBACK** (recorded in `.claude-work/resume/p514-p4--fixes.md`).
The background builder retry at 12:40 stalled too, while READING files (a stream stall, not a hung command), making three stalled
attempts. Only `tools/safefetch-selftest.php` changed; SafeFetch.php and Hymnal.php are unchanged (`f054d157…`, `598d9893…`). Self-test
253/0/0 in 10 s. Mutation proof: K1, K2, K3 and K18 now each fail the self-test on exactly the intended check; the unchanged code and
the K14 control pass; nothing left running. Some runs took minutes because the network/AI service was slow at the time (the auto-mode
safety check itself timed out once); a plain re-run took 10 s. The mutation script is `p4-mutate.py` in the session scratchpad.

**NOW: independent check** by a separate background agent (dev-team:opus-builder with model **fable**, trying Fable first), brief
`.claude-work/briefs/514-p4-fix-check.md`, report `.claude-work/resume/p514-p4--verify-r2.md`. If Fable is out of credits it fails
fast: relaunch the same brief on Opus. **When it passes:** commit ONLY the three P4 files, saying plainly that the orchestrator made
the fixes after three builders stalled; push; comment on #514; then P5 as a BACKGROUND AGENT with a brief file if the workflow tool
keeps stalling.

## LATEST — 22 September 2026, about 11:20. RESUME FROM HERE.

**The P4 fix round via the workflow (run `wf_9c79db1b-f8b`) FAILED without changing anything:** its builder was started SIX times and
each was killed for three minutes without progress (the known "service is slow" pattern). The three P4 files still match the
fingerprints of the version that passed the check (SafeFetch `f054d157…`, self-test `14fbbb84…`, Hymnal `598d9893…`).

**The same four fixes now run as a BACKGROUND AGENT** (Agent tool, dev-team:opus-builder; no three-minute limit), from the brief
`.claude-work/briefs/514-p4-fix.md`; progress in `.claude-work/resume/p514-p4--fixes.md`. **When it reports:** run an independent
check the same way (a fresh background agent that did not build it; Fable first, Opus if Fable is still out of credits), then my own
checks, commit ONLY the three P4 files, push, comment on #514, then P5. **If the service stays slow, keep using background agents with
brief files rather than the workflow tool** for P5 onwards.

## LATEST — 22 September 2026, about 09:10. RESUME FROM HERE.

**#514 P4 (the safe fetcher) PASSED its independent check** after the restart (run `wf_a2a2586e-88f`; check on OPUS, Fable still out
of credits). SafeFetch itself was found correct (55 tricky addresses refused; pinning works). Still uncommitted: `web/_core/SafeFetch.php`,
`web/_core/Hymnal.php`, `tools/safefetch-selftest.php`.

**A FIX ROUND IS RUNNING before the commit** (run `wf_9c79db1b-f8b`, `args.fixPlan`): (1) the self-test decided two SKIPs with the code
under test, so a fetcher that refused everything still passed; decide them with dns_get_record() instead; (2) the certificate checks are
never tested; add a local self-signed `openssl s_server` test; (3) the one-number hex form `0x7f000001` added to part A; (4) K8, K9, K13
listed in the self-test's "cannot prove" section. The checker's K1-K20 mutation set is re-run. **When it passes:** my own checks,
commit ONLY the three P4 files, push, comment on #514, then P5 (`args: { part: "P5", tier: "opus" }`).
For P11's follow-up list: the curl_close() deprecation in Hymnal.php (every remote hymn fetch logs a 'Fatal' 8192 row). #536 gained
the hymn pages' unprefixed form targets.

## LATEST — 22 September 2026, about 08:10. RESUME FROM HERE.

**#514 P4's first build was CUT OFF by a server overload (API error 529)** after ~27 minutes (run `wf_a80c9b76-188`), before any
report or check. Its partial work is in the working tree, uncommitted: `web/_core/SafeFetch.php` (new), `web/_core/Hymnal.php`
(changed as planned), `tools/safefetch-selftest.php` (new; last run 176/0/0). It was mid-way through a mutation run, where three planted
faults were NOT caught (M5 ::/96 dropped; M18 user:pass allowed; M19 only the first resolved address checked), and normaliseUrl()
accepted shortened IPv4 forms (`0x7f.1`, `127.1`, `010.0.0.1`). **A copy is saved in
`.claude-work/resume/p514-p4-partial-20260922-0806/`.** No server or container was left running.

**RESTARTED at 08:10** as run `wf_a2a2586e-88f` with the part script's new `args.continueNote` (the builder reviews and finishes the
partial work rather than starting blind; the previous script version has no such option). Reports `p514-p4--*.md`. If this run also
dies part way, save the tree's P4 files again BEFORE relaunching.

## LATEST — 22 September 2026, about 03:10. RESUME FROM HERE.

**#514 P3 IS COMMITTED: `4a915db`, pushed** (73 files). Imported events read-only everywhere else; `check_event_visibility.py` wired
into pr-security.yml (owner's yes). First check NOT CLEAN (documents linkable to imported/hidden/other-organisation events, imported
series offered on the event form, series update/delete doing different work for refused vs missing), fixed via re-plan + fix round;
second check PASS. After it I corrected four over-claiming comments (no behaviour change). **All deep steps ran on OPUS: Fable is still
out of usage credits** (check r1, re-plan r1, check r2). actionlint with CI's own settings (`SHELLCHECK_OPTS=--severity=error`) exits
0; without them it prints two info notes that the committed file already had.

**New issues:** **#547** "my volunteering" crashes for everyone (MySQL 1055, ONLY_FULL_GROUP_BY; older than #514). **#548** anyone can
post livestream chat messages against any event number (ping.php issues sessions for any number). Both are follow-ups after #514.
**Plan note for P6/P8** added at the end of section 0 of p514--plan-r2.md: series-edit still opens an imported SERIES by number.

**NOW: P4 launching** (`args: { part: "P4", tier: "opus" }`): the safe fetcher (SafeFetch, the shared address test). Reports
`p514-p4--*.md`.

## LATEST — 21 September 2026, about 23:25. RESUME FROM HERE.

**#514 P2 IS COMMITTED: `de31cb8`, pushed.** The rule and detail level in every outlet (18 files). Independent check PASS in round 1 (on
OPUS; Fable still out of credits). Two small no-behaviour-change fixes made by me after the check (full notation in event-reminders.php;
an API description that named a field not added until P6). Comments posted: #514, #534 (items 1-2 DONE; re-scoped to items 3-4), #544
(superseded by the full rule), #536 (sitemap has no organisation prefix).
**For P11's follow-up list:** the events API still returns `isPublic` (1) on imported events, where it means nothing; hiding it changes
the API contract, so it needs its own issue.

**NOW: P3 launching** (`args: { part: "P3", tier: "sonnet" }`): imported events read-only everywhere else, and the new
`tools/audit-checks/check_event_visibility.py` WIRED INTO `.github/workflows/pr-security.yml` (owner's explicit yes, 18:05 — the only
workflow edit any part may make). Reports `p514-p3--*.md`.

## LATEST — 21 September 2026, about 22:00. RESUME FROM HERE.

**#514 P1 IS COMMITTED: `2cd68f1`, pushed, #514 commented.** Two independent checks passed (both on OPUS: Fable was out of usage
credits, recorded in the commit). Fixes made before the commit: the self-test now catches all nine planted faults (131 checks); API keys
follow owner answer 3 literally (full detail only when the calendar itself is Public; noted in p514--plan-r2.md section 0.4 for P2
rows g/h and P7); the self-test's exit code; two comments; and, by me with a proof, the error handler registered before the rule file
loads.

**NOTE FOR EVERY LATER PART:** `tools/event-visibility-selftest.php` needs a throwaway database (`SELFTEST_DB_NAME=selftest_…` plus
host/port/user/pass) built the installer's way. Without one it REFUSES with exit 1 **by design**, so "nothing checked" never looks
like a pass. The part script's HOUSE text now says so. Every other self-test runs without a database. (Also: never read an exit code
through a pipe; `$?` after `| tail` is the tail's.)

**NOW: P2 launching** (`args: { part: "P2", tier: "opus" }`): the rule and the detail level in every place events are shown, about
twenty files; closes #534 items 1-2; replaces #544's interim test. Reports `p514-p2--*.md`.

## LATEST — 21 September 2026, about 20:45. RESUME FROM HERE.

**#514 P1 is BUILT (uncommitted, 9 files in the working tree) and PASSED its independent check** (run `wf_9898d9d7-5f4`; reports
`.claude-work/resume/p514-p1--build.md`, `p514-p1--verify-r1.md`). Migration **204** (`204_external_calendar_visibility.sql`), new
`web/_core/EventVisibility.php`, new `tools/event-visibility-selftest.php`, plus data-export, GdprEraser, catalogue, feeds-save,
import-feeds and full_schema.sql.

**FALLBACK RECORDED:** the P1 check's Fable attempt failed with "You're out of usage credits"; **Opus ran that check** (the script's
per-step fallback). Try Fable first again on every later step. Say this in P1's commit message.

**A FIX ROUND IS RUNNING** before P1 is committed (run `wf_f7256878-cb6`; `wf-514-part.js` with `args.fixPlan`; the previous script is
saved as `wf-514-part.before-fixplan.js`; reports `p514-p1--fixes.md`, `p514-p1--verify-r1.md` is overwritten by the new check):
1. the self-test missed 8 of 9 realistic planted faults: strengthen it until all nine fail it;
2. **API keys follow owner answer 3 literally**: full detail only when the calendar ITSELF is Public (the plan's 1.3 formula read it
   wrongly and gave a key full detail on a Members calendar); a note goes into p514--plan-r2.md section 0.4 for P2 rows g/h and P7;
3. the self-test exits 255 instead of the promised 1 when a table is missing;
4. two over-claiming comments (204's A11; EventVisibility's "five other pages").
**When it passes:** run my own checks, commit ONLY P1's files (list them explicitly; never `git add -A`), push, comment on #514, then
launch P2: `args: { part: "P2", tier: "opus" }` (no fixPlan).

**New issues:** **#546** no JSON format in the project has a schema (standing rule), including the personal-data download. Comment
added to **#543**: the erasure-coverage self-test passes when erasure is switched off for a table. Both are follow-ups after #514.

## LATEST — 21 September 2026, about 19:15. RESUME FROM HERE.

**#542 IS DONE: `8e7fb21`, independent check PASS in round 1.** A department flag (lead, approver, required approver of the claim's
OWN department) is enough to open and decide its claims; new `Departments::approverDepts()`; no migration, so **204 is still free for
#514 P1**. One documented change: someone whose only flag is in another department gets the plain "Forbidden" role holders always got.

**#545 raised and DECIDED by the owner (19:10):** refuse self-approval outright (no approving or rejecting your own claim; your own
claims leave your approvals list; if you are the only lead or required approver, an administrator or another approver decides), and a
treasurer may NOT pay their own claim (another treasurer or an administrator pays). **Queued with the follow-ups after #514.** A small
"claim exists" signal in the decision handler was added to #541 as item 5.

**NOW: the #514 BUILD, part by part.** One reusable script: `.claude-work/resume/wf-514-part.js`, run as
`Workflow({scriptPath: ".claude-work/resume/wf-514-part.js", args: { part: "P1", tier: "opus" }})`. It builds ONE part from
`p514--plan-r2.md` on the plan's tier, then independent Fable checks with re-plan and fix rounds; reports land in
`.claude-work/resume/p514-p1--*.md` (and p514-p2--… for later parts). Containers p514-pN-mysql / p514-pN-check; ports 9190-9199. After
each part passes: my own checks, commit ONLY that part's files, push, comment on #514, update this handoff, then the next part.
Tiers from the settled plan: P1 opus, P2 opus, P3 sonnet (may wire its check into pr-security.yml), P4 opus, P5 opus, P6 opus, P7 opus,
P8 sonnet, P9 sonnet, P10 sonnet, P11 sonnet.

**FOLLOW-UPS after #514:** #534 (items 3-4 only), #535, #536, #539, #540, #541 (five items), #543, #545. Then the documentation/CI pass
(wire the three approved checks; CLAUDE.md counts), the comprehensive Codex review (including `560a4f2` on main and `1830dd7` on beta
by name), and stop for the owner before any pull request.

## LATEST — 21 September 2026, about 18:10. RESUME FROM HERE.

**The #514 re-plan is SETTLED** (run `wf_59816f10-2d2`, all Fable, no fallbacks, no owner questions). **The plan to build from is now
`.claude-work/resume/p514--plan-r2.md`** (section 0.4 lists every change; `p514--review.md`, `p514--replan.md`, `p514--settle.md` have
the reasoning). The challenge step made eleven corrections in place, among them: the new check keeps marker comments (candidates from
comment-stripped text, markers searched in the original); the scheduled job's push link must NOT go through `Site::url()` (the prefix
is fixed from the job's own address); P2's bind order on calendar/index.php; P6 proofs 11/13 contradicted P2 row a.
- **Build order, one commit each:** P1 (migration 204, opus) -> P2 (opus; closes #534 items 1-2; replaces #544's interim test) -> P3
  (sonnet; adds `check_event_visibility.py` AND wires it into pr-security.yml) -> P4 (opus) -> P5 (opus) -> P6 (migration 205, opus)
  -> P7 (206, opus) -> P8 (207, sonnet) -> P9 (sonnet) -> P10 (sonnet) -> P11 (sonnet). Migration numbers are re-read at build time;
  if #542 takes one, every #514 number moves up by one.
- **Owner, 18:05: YES, P3 may wire `check_event_visibility.py` into `.github/workflows/pr-security.yml`** (a fourth check, same as the
  three approved earlier). The owner first checked it was repository-side only: all Python lives in `tools/`, 0 files under `web/`,
  and only `web/` deploys. **Never put Python under `web/`** (shared hosts may not run it).
- Real Google/Microsoft 365 calendars: still NOT PROVEN (decision 9); P5's real-export proof prints SKIPPED.

**NOW: #542** (department flag is enough to view and approve) launching as `.claude-work/resume/wf-542.js`, reports `p542--*.md`,
containers p542-mysql/p542-check, ports 9180-9189. **Then the #514 build, P1 first.**

## LATEST — 21 September 2026, about 16:35. RESUME FROM HERE.

**The #514 catch-up review is DONE** (run `wf_59816f10-2d2`, Fable, no fallbacks; report `.claude-work/resume/p514--review.md`, proof
logs in `/private/tmp/p514-review/`). Verdict: **re-plan needed, but small.** The design and the leak hunt's 33 findings still hold, and
the P10 contract from #516/#517 is met exactly (16/16 on MySQL 8.0.36). Three things settled since 17 September contradict the plan's
TEXT: (1) P1's membership rule keeps the single-organisation "no row counts as a member" branch the owner removed on 20 September (#533);
(2) P1 turns "remove from site" into an UPDATE, which would now leave #516/#517 roles, groups and department approver flags behind and
restore them on re-add, so P1 must NOT touch `admin/sites/users.php`; (3) P2 sends signed-out visitors to sign in, where the owner chose
the one not-available page. Plus stale facts (#518, #519, #520 are fixed; P2 delivers #534 items 1-2; P9/P8 links need `Site::url()`;
check allow-list additions; collation note; line drift).

**It found a LIVE LEAK and asked one question. The owner chose (b): fix it NOW as a tiny package.** Anyone, even signed out, can
download an internal event series from `/calendar/export?series=N`. Raised as **#544**; running as workflow `wf_a5200c76-f44` (script
`.claude-work/resume/wf-544.js`, reports `p544--*.md`, containers p544-mysql/p544-check, ports 9170-9179).

**#544 IS DONE: `e49ae58`, independent check PASS in round 1** (78-request matrix against the committed code; refusals byte-identical to a missing series; everything else unchanged). #544 commented; the live site stays exposed until promotion.

**17:30 — #514 re-plan RESUMED** with the owner's answer (the command below, with the commit ID filled in). It is the same run ID.

**THEN resume the #514 re-plan:** `Workflow({scriptPath: ".claude-work/resume/wf-514-review.js", resumeFromRunId: "wf_59816f10-2d2",
args: { answers: "..." }})`. The script now accepts `args.answers` (the review step's prompt is unchanged, so it replays from cache;
the previous version is saved as `wf-514-review.before-answers.js`). The answer to pass: the owner chose (b), the series download was
fixed ahead of #514 as #544 in commit e49ae58; P2 row d2 replaces that fix with the full rule.

**ORDER:** #544 -> #514 re-plan (resume) -> #542 -> #514 build, one commit per part -> follow-ups (#534 re-scoped to its items 3-4,
#535, #536, #539, #540, #541, #543) -> documentation/CI pass -> comprehensive Codex review -> stop for the owner.

## LATEST — 21 September 2026, about 15:45. FOUR OWNER ANSWERS.

1. **#542 (approval trap): a department flag is enough.** Anyone an administrator has made a lead, approver or required approver
   of the claim's OWN department may approve its claims, with or without the Expense Approver role.
2. **Order: #542 goes straight after the #514 plan review and BEFORE the #514 build**, as its own small package. #543 goes with
   the follow-ups after #514.
3. **YES: wire the three new checks into `.github/workflows/pr-security.yml`** (`check_reserved_site_keys.py`, `check_role_keys.py`,
   `check_membership_queries_scoped.py`), in the documentation/CI pass. This is the owner's explicit approval for that workflow
   edit ONLY: adding steps that run existing checks, nothing loosened.
4. **Psalm's step-level "allowed to fail" flag on alpha may travel to beta and main** at the next promotion. (The owner asked what
   Psalm is: explain tool names in plain words the first time; it is a code-checking tool on GitHub, not part of the product.)

**ORDER NOW:** Fable review of the #514 plan (launching) -> #542 -> #514 build, one commit per part -> #534, #535, #536, #539, #540,
#541, #543 -> documentation/CI pass -> comprehensive Codex review -> stop for the owner before any pull request.

## LATEST — 21 September 2026, about 15:30. RESUME FROM HERE.

**#517 IS DONE: committed `d3b906d` and pushed, independent check PASS in round 1, no fallbacks.** User groups and departments
per organisation, manageable at `/admin/groups` and `/admin/departments` (plus `/admin/users/memberships-unplaced`); migration 203;
new classes `UserGroups` and `Departments` with `memberSql()` for #514 P10; new check `check_membership_queries_scoped.py`; new
self-test `memberships-selftest.php`. 43 files, exactly the plan's list. One small fault from the check (the Add-member list offered
accounts a site administrator could never add) was fixed and proven on a throwaway MySQL 8.0.36 before the commit. **Next free
migration number: 204.** Counts now: 19 audit checks, 11 self-tests, 86 framework classes, 201 numbered migrations, 218 tables
(CLAUDE.md's counts table is stale; the documentation pass updates it).

**New issues from #517:** **#542** (high) an expense claim can get stuck for ever when a department's lead or required approver lacks
the Expense Approver role (`expenses/approve/save.php:49` tests the role before the department) — **needs the owner's decision**
(recommended: a department must-approve flag counts as authority). **#543** two automatic checks can pass when they should not.
Notes added to #536 (unprefixed Expenses addresses) and #541 (the claim form never shows its messages).

**Left for the documentation/CI pass (from the #517 plan, section 15):** wire `check_reserved_site_keys.py` (#515),
`check_role_keys.py` (#516) and `check_membership_queries_scoped.py` (#517) into `.github/workflows/pr-security.yml` — a WORKFLOW
edit, so it needs the owner's explicit yes first (the auto-mode safety check refuses workflow edits otherwise); one sentence in
`help/small-groups.php` saying committees are "user groups" under Admin; the CLAUDE.md counts.

**NEXT: the Fable review of the #514 plan** (`.claude-work/resume/p514--plan.md`), now that #516 and #517 have built the contracts its
part P10 relies on (`Roles::holdsSql()`, `UserGroups::memberSql()`, `Departments::memberSql()`). If it finds real changes, re-plan the
affected parts (owner decision 10), then build #514 one commit per part.

## LATEST — 21 September 2026, 11:45. RESUME FROM HERE.

**#538 IS DONE: committed `5753bf3` and pushed, independent check PASS in round 1, no fallbacks.** `/expenses/treasury` now
refuses anyone who is neither a treasurer of this organisation nor an administrator, with the standard 403 page and no claims query;
the pay handler refuses the same way (role check now before the token check). Measured first: EVERY signed-in account could read the
queue, including members and the treasurer of ANOTHER organisation. 4 files, no migration. #538 commented, left open for the sweep.
**Production stays exposed until this branch is promoted.**

**New issues from #538's check:** **#540** withdrawing an expense claim has never worked (the handler writes 'Withdrawn', the ENUM
has no such value; MySQL error 1265; #73 promised the migration and closed without it). **#541** three small Expenses mismatches
(Export button drawn for people it refuses; treasurers cannot export, which needs an owner decision; a comment understating who may
open a claim). **Default placement: both with #534-#536 and #539 after the #514 build, like #539. Not yet confirmed by the owner.**
Also recorded, not raised: the older portal-wide `tblUsers.isAdmin` flag opens every organisation's treasury, as on every other page.

**Alpha's copy of `actions: read` is DONE: `26aa70d`**, byte-identical to production, with alpha's existing upload-step comment
reconciled (two review rounds, the second clean). **The whole Dependabot request is now finished.** Note for the owner: alpha's
EXISTING step-level `continue-on-error` on the Psalm upload (from #463/#464) will travel to beta and main at the next promotion; the
"dropped for good" note above is about NEW flags only.

**12:05 — #517 RELAUNCHED** as run `wf_276323d6-e95` (script `.claude-work/resume/wf-517.js`). Planning finished at about 13:00
(settled plan `.claude-work/resume/p517--plan.md`, 702 lines, 15 proofs; migration **203**; builder **Opus**; 41 files) and stopped
for three owner questions. **The owner chose the recommended answer to all three:**
1. Placing a parked group: place the group and every qualifying member; leave the rest parked with their own Place/Discard buttons,
   counted in the message.
2. Delete a group or department only when nothing depends on it (no members, no owned assets, no workflow step naming the group, no
   expense claims on the department); otherwise retire it.
3. A retired department's pending claims are finished by its own approvers as before; retiring only stops NEW claims.
**Resumed** with those answers as `args.answers` (same run ID); the planning steps replay from cache and the build is under way.

**NEW ORDER:** alpha permission commit -> #517 -> Fable review of the #514 plan -> #514 build (one commit per part) -> #534, #535,
#536, #539, #540, #541 -> full documentation pass -> comprehensive Codex review (including `560a4f2` on main and `1830dd7` on beta by
name) -> stop and wait for the owner before any pull request.

## LATEST — 21 September 2026, 10:25. RESUME FROM HERE.

**10:55 — OWNER ANSWERS, and the order changed.**
- **#538 (treasury list visible to any member) goes FIRST, ahead of #517**, because it is probably live on production. The #517 run
  (`wf_11f8fb60-55d`) was STOPPED part way through its first planning step. Nothing had finished, so a resume will simply start
  planning again; no containers or files were left. Relaunch #517 once #538 is committed.
- **#538 LAUNCHED** as `wf_cd961b65-531` (script `.claude-work/resume/wf-538.js`, reports `p538--*.md`, containers p538-mysql /
  p538-check, ports 9150-9159). One self-challenging Fable planning step, sweeping the whole Expenses app for the same gap and hiding
  the Treasury link from people who will be refused.
- **#539 (invitations grant no role) goes with #534-#536, after the #514 build.**
- **#516's six defaults are all kept.**
- The fix lands on this working branch, so **production stays exposed until the branch is promoted** (or the owner orders a
  separate hotfix, which needs a pull request and therefore the owner's explicit go-ahead).

**NEW ORDER:** #538 → #517 → Fable review of the #514 plan → #514 build (one commit per part) → #534, #535, #536, #539 → full
documentation pass → comprehensive Codex review → stop and wait for the owner before any pull request.

**10:45 — #517 LAUNCHED** as workflow run `wf_11f8fb60-55d` (script `.claude-work/resume/wf-517.js`; reports land in
`.claude-work/resume/p517--*.md`). It carries the three fixes from the #516 lesson below, and **stops after planning if the owner has
questions** (overnight rule 1). Then relaunch with `resumeFromRunId` and `args: { answers: "..." }`; the planning steps replay from
cache. Container names p517-mysql / p517-check, ports 9140-9149.

**#516 IS DONE: committed `c99dc87` and pushed, independent check round 3 PASS.** Roles per organisation, grantable at last:
39 files, migration 202, new `Portal\Core\Roles`, `/admin/roles`, `/admin/users/roles-unplaced`, new check `check_role_keys.py`,
new self-test `roles-selftest.php`. Three over-claiming comments the checker found were corrected before the commit (wording only;
all checks re-run clean). #516 is commented, left open for the Codex sweep, like #515. **Next free migration number: 203.**

**Defaults taken on the planner's seven questions** (listed in the #516 comment; the owner may change any): event coordinator in the
standard set as audience-only; standard roles renameable, never deletable, keys fixed; the pre-release access list counts a role held
in any organisation with an active membership; placing a parked holding never creates a membership; carry-over follows #533 exactly;
the role list is managed by that organisation's administrators.

**Two new issues from #516's check:** **#538** (security, high) any signed-in member can open `/expenses/treasury` and see every
approved claim awaiting payment: name, department, amount. Only the pay button checks for the treasurer role. **#539** invitations
offer volunteer/staff as the role on acceptance and grant nothing. Neither is in the queue yet: ask the owner where they go.

**LESSON (the #516 run):** `wf-516.js` `buildAgent()` fell back to Opus only when the Sonnet builder threw or stalled, never when it
RETURNED a refusal, so two check rounds found the package unbuilt. It was finally built on the Sonnet tier in fix round 2. Fix this in
any copied workflow script: treat a builder's "I decline / route elsewhere" return as a failure and escalate.

**DEPENDENCY PRs (owner's request, 21 September): ALL THREE DONE AND GREEN at 11:10 BST.** #537 (alpha) and #506 (beta) merged
earlier; **#504 squash-merged at 10:07 UTC as `560a4f2`** on the production branch, after the fix commit `a19f57e` was added to it.
Production now matches the four reviewed files exactly. After the merge: CodeQL, Lint Workflows and PHP Static Analysis all green, and
**Security Backport ran and was SKIPPED**, which proves the new guard applies to the merge that adds it. Beta got the permission as
`1830dd7`; its three runs were green. Nothing deployed (no `web/**` change). No Dependabot PRs are open now; expect fresh
4.38.0 -> 4.38.1 ones for the production branch and beta at Dependabot's next run. **Still to do: the alpha copy of `actions: read`**
(see below). Full record: `.claude-work/dependabot-status.md`.
- The owner made the repository **public (temporarily)**, which cleared the Actions billing refusal and the code scanning refusal.
- **#537 (alpha)** was auto-merged by alpha's own workflow at 08:28 UTC. **#506 (beta)** was squash-merged at 08:41 UTC (`a6931d1`);
  its post-merge CodeQL, lint and Psalm runs were all green. Nothing deploys: `deploy.yml` only runs for `web/**` changes.
- **#504 (main): every check green, NOT merged yet.** Merging it would start `security-backport.yml` (it exists on main only),
  which copies merged Dependabot PRs down to the other branches. For a workflow-only update the copy is refused whenever there is
  anything to copy, because the built-in token may not change workflow files (alpha's copy of #424, 7 September; the August copies
  were green only because they were empty). #504's copy onto alpha would conflict and be refused the same way. **The owner allowed**
  (10:05) a guard line there, skipping `dependabot/github_actions/` branches, plus `actions: read` in `php-static-analysis.yml` on
  **all three** branches, with identical text.
- **The edits were reviewed in four rounds** by an independent Claude agent. The final diffs are
  `.claude-work/reviews/dependabot-{504,beta}-fix-r3.diff` (scratch worktrees `wt-504` and `wt-beta`); round 3 found the file changes
  clean, and round 4's two wording points on the commit messages were corrected. Messages: `.claude-work/commit-504-fix.txt`,
  `commit-beta-fix.txt`, `merge-504-body.txt`. Then: commit onto #504's branch, commit to beta, re-run checks, merge #504 with the
  edited squash subject and body, check main holds all three files as reviewed, and watch every run after the merge until green.
  **The alpha copy of the permission goes on this working branch after #538 is committed**, with the same text, and must also
  reconcile alpha's own upload-step comment (it blames Code Scanning for the "Resource not accessible" message, which was really the
  missing permission).
- **Round 1 also warned:** Dependabot logged this morning that #504 "should be superseded" by a 4.38.1 update, so merge promptly. After
  the merge, expect Dependabot to raise a fresh 4.38.0 -> 4.38.1 PR for main (and beta).
- **CODEX SWEEP: these commits live OUTSIDE the working branch, so `git diff 110e47d~1..HEAD` will never show them. Review them by
  name:** (1) **`560a4f2`** on **main** (the #504 squash: the codeql-action bump plus the backport guard, the permission and the
  DEV_NOTES paragraph); (2) **`1830dd7`** on **beta** (adds `actions: read`). Both say in their messages that they are listed here.
- **Claude Code's auto-mode safety check refuses workflow-file edits ("CI Bypass")** unless the owner has explicitly allowed them. A
  first attempt that also added step-level `continue-on-error` flags was refused and **dropped for good**: the owner was not asked
  about those. Never work round the refusal with another tool.

## LATEST — 21 September 2026, 01:45. RESUME FROM HERE.

**TWELVE OWNER DECISIONS TAKEN AT 01:40, so the queue can run overnight. These govern everything that follows.**

**How to work**
1. **PAUSE on anything these answers do not cover.** Do not take a new decision alone: stop, write down what is needed and why, and
   wait. (The owner chose this deliberately over "decide and log".)
2. **A package whose check keeps failing: KEEP TRYING.** Do not park it and move on; keep fixing and re-checking.
3. **A new fault found along the way: fix it if it is SMALL** — meaning it is in a file this package already changes, needs no design
   decision, and can carry its own proof. Anything larger becomes its own issue, and the queue continues.
4. **The Codex sweep at the end:** review as many areas as its usage allows, fix what comes back, and leave the rest clearly listed
   for when its limit resets. **Never record an area as reviewed when it was not.**
5. **DO NOT OPEN THE PULL REQUEST.** Not even a draft. Everything waits for the owner's explicit go-ahead.

**What to build**
6. **#516 roles: EVERYTHING per organisation, including the list of roles itself.** Each organisation gets the standard set
   (treasurer, event coordinator, groups coordinator and the rest), can RENAME them for its own language, and can add its own. **The
   internal name each feature looks for stays fixed**, so the 62 places in the code that check for a role keep working. New
   organisations get the standard set automatically. Holding a role is per organisation. An organisation's administrator grants roles
   in their own organisation; a global administrator anywhere. Any rows that already exist are carried over the way #533 does it:
   automatic where there is one organisation, listed for a person to place where there are several.
7. **#517: build BOTH user groups and departments, each belonging to one organisation**, managed by that organisation's
   administrators. They stay two different things: groups are committees and working groups; departments carry the lead, assistant,
   secretary and approver flags that expenses approval already depends on.
8. **#514: one commit per part**, planned, built, checked and committed on its own, in the plan's safe order.
9. **#514 test calendars do not exist yet.** Build and prove everything else with hand-written calendar files, and report the
   acceptance criterion "works against a real Google or Microsoft 365 calendar" as **NOT PROVEN**. Do not claim it as covered.
10. **If the Fable review of the #514 plan finds real changes are needed, re-plan the affected parts** (challenged as usual) and carry
    on building. Do not build a plan the review says is out of date.
11. **Do the full documentation pass** if the queue reaches it: every document, the in-app help and the API description, brought in
    line with everything built this week.
12. (From earlier.) Every package still gets a fresh Claude agent that did not build it, before it is committed; every commit message
    still says Codex has not reviewed it.

**THE ORDER:** #516 → #517 → Fable review of the #514 plan → #514 build (one commit per part) → #534, #535, #536 → full documentation
pass → **the comprehensive Codex review of the whole branch** (`git diff 110e47d~1..HEAD`, area by area, through
`.claude-work/codex-queue.sh`) → fix or log every finding → **stop, and wait for the owner before any pull request.**

## LATEST — 21 September 2026, 01:30. RESUME FROM HERE.

**#515 IS DONE: committed `6989b26` and pushed, independent check PASS.** Reserved organisation keys (built from three live sources,
so the list cannot go stale), the refusal at save time, the refusal to switch a clashing organisation back on, warnings for
organisations that already clash, a health light that only goes amber where the clash actually bites, and a new automatic check. The
maintenance bypass is narrowed to global administrators only (#509 part 4). 13 files, no migration — 202 stays free.

Two residuals the checker raised were fixed before committing: a comment naming the old rule, and — the one that mattered — the new
check only recognised the exact words "INSERT INTO", so the "INSERT IGNORE INTO" shape used in eleven files here would have slipped
past it. Fixed with a contrast proof, along with a multi-line case and a third fault found while testing. #515 and #509 are commented.

**NEXT: #516** (nobody can be given a role; roles are not tied to an organisation), then **#517** (user groups and departments exist
only in the database). Both are prerequisites for the #514 build's last part. Then the Fable review of the #514 plan, then the #514
build, then #534, #535 and #536, then **one comprehensive Codex review of the whole branch**, then one pull request to alpha.

**Everything committed since Codex last reviewed:** 5f278cb, 12e637a, 1e0809c, bd1ef12, 6989b26 and the documentation commits. The
review list to build for the sweep is still "everything from `110e47d~1` to the tip".

## LATEST — 20 September 2026, 23:10. RESUME FROM HERE.

**PACKAGE 2 IS DONE: committed `bd1ef12` and pushed, independent check PASS.** The owner's dead-link answer (one "not available" page
for both cases, with a sign-in link, answering not-found underneath), #508, #512 parts 1-3, #528, #530 and #509 parts 1-3. 31 files,
one migration (201 removes the browser description nobody read). The checker ran the committed code and the fixed code side by side
against the same database, so every result has a control. All five issues are commented.

**TWO OWNER DECISIONS, 23:05.**
1. **Maintenance mode: narrow the bypass to GLOBAL administrators only.** Today any administrator walks past the closed sign,
   including anyone holding the older all-organisations flag. Sign-in, the upgrade page and the health check stay open to everybody.
   It is a one-word change in `Maintenance::currentUserCanBypass()`, and it is **folded into the #515 package now running** rather
   than run as a package of its own.
2. **The three issues package 2 raised all wait until after the #514 build:** **#534** any signed-in person can read another
   organisation's internal event page and download it (verified: `event.php` has no membership check at all, unlike the registration
   pages); **#535** "delete my data" silently skips four tables that DO link to a person, including the record of who opened somebody's
   pastoral file (verified table by table: `uploadedByID`, `viewerID` and `actorUserID` are not in the eraser's list of known column
   names; `recordedByID` is, so the fifth suspected table is fine); **#536** the navigation, sign-in and error pages drop the
   organisation prefix (the navigation alone has 20 bare addresses; only 8 files in `web/` use the address builder).

**RUNNING NOW: the #515 package** — organisation keys that can take over one of the portal's own addresses, plus the maintenance
narrowing above.

**THE ORDER FROM HERE:** #515 (+ maintenance) → #516 → #517 → Fable review of the #514 plan → #514 build → #534, #535, #536 →
**one comprehensive Codex review of the whole branch** → fix or log every finding → one pull request to alpha. Then back to
per-package Codex reviews.

## LATEST — 20 September 2026, 22:20. RESUME FROM HERE.

**PACKAGE 1 IS DONE: committed `1e0809c` and pushed, independent check PASS.** #521, #522, #529, #531, #532, #533 and the
deleted-events rule, 33 files, two new database changes (199 fills in missing membership records, 200 adds the attendance-report
visibility setting). The checker ran the current code and the fixed code side by side against the same database, so every result has
a control. All six issues are commented. The "one package in flight, checking included" rule is now written into `.claude/CLAUDE.md`.

**THE OWNER ANSWERED PACKAGE 1's FOUR QUESTIONS.** Three confirmed what was built: three visibility choices for the attendance
totals with administrators only as the default; the bulk import counts toward the same five-an-hour bound as the page and the API; and
a switched-off organisation still counts as an organisation, so a portal with one active and one inactive gets the list to place by
hand rather than an automatic placement.

**The fourth changed something already shipped, and is now the FIRST item of package 2.** Package 1 made a refused event answer the
same way as one that does not exist — but the answer chosen was "go and sign in", so somebody following a dead link (an old shared
link, a search result, a bookmark for a deleted event) is asked to sign in for something that no longer exists. The owner chose a
third answer instead: **ONE page for both cases**, saying the event is not available, with a sign-in link for anybody who has an
account, answering with a not-found status underneath so search engines drop dead addresses. The two cases must stay byte-identical
for a signed-out visitor and cost the same database work, and the same treatment must be applied wherever else this shape now exists,
including the check-in page.

**PACKAGE 2 IS RUNNING: run `wf_7c0e9359-5cb`, task `wnz2uk9ma`, script `.claude-work/resume/wf-package2.js`, outputs
`.claude-work/resume/p2--*.md`.** Six pieces: the dead-link answer above (first), #508 the two admin pages that crash part way through
drawing themselves, #512 calendar links that drop the organisation prefix, #528 the attendance grid repeating or skipping a day at
the clock change, #530 the browser description nothing reads, and #509 maintenance-mode side faults. Nothing is committed by the run.

**NOTE:** the run's own summary line says "Package 1" because the script was copied from package 1's; the agent instructions are
package 2's. Cosmetic only.

**AFTER PACKAGE 2:** #515 → #516 → #517 → Fable review of the #514 plan → #514 build → **one comprehensive Codex review of the whole
branch** → fix or log every finding → one pull request to alpha. Then back to per-package Codex reviews.

## LATEST — 20 September 2026, 21:55. RESUME FROM HERE.

**OWNER DECISION, 21:55: STRICTLY SEQUENTIAL, and checking counts.** Nothing new starts — not even planning — while a package is
being independently checked. The standing rule "never have two analysis runs going at once" now explicitly covers verification runs
too: one package is in flight at a time, from planning through checking to commit. **OWED: write this into the "Deep analysis" rule in
`.claude/CLAUDE.md` as soon as package 1 is committed** (not before, because the checker verifies which files have changed and that
file is not on package 1's list).

Asked because package 1's check had an hour or more to run and package 2's planning could have used the time. The answer is no: the
time is left idle deliberately.

**PACKAGE 1 IS BUILT AND UNDER INDEPENDENT CHECK** (run `wf_8e4e27d5-3ff`, task `wah6coqwm`). Plan drafted 20:01, settled 20:23, built
21:26, checking since 21:27 on a fresh Fable agent with its own database and two servers (the fixed tree and the current code side by
side). 33 files: 27 changed, 6 new, including two new database changes (199 fills in missing membership records, 200 adds the
attendance-report visibility setting) and two new pieces of code (`AttendanceAccess.php`, and an admin page listing accounts that
belong to no organisation).

The builder reported two things honestly rather than hiding them: one genuine deviation from the plan, and one sub-proof (a 20-way
simultaneous flood) it could not finish because of a tooling limit. **Both must be checked before this is committed.**

**NOTHING FROM PACKAGE 1 IS COMMITTED YET.** The working tree holds all 33 files. If this session ends, read
`.claude-work/resume/p1--build.md` and `p1--verify-r1.md` first.

## LATEST — 20 September 2026, 19:45. RESUME FROM HERE.

**THE CODEX-FIX PACKAGE IS DONE: committed `5f278cb` and pushed.** All thirteen findings fixed, each proved real on the old code and
fixed afterwards by an independent agent with its own database and web server (`p525`-style controls: the accounts API wrote one
UPDATE before and none after; the waiting list promoted in all six cases it should not have and none after; twenty simultaneous
check-ins let ten through before and at most five after; the sign-out page left before a slow deletion finished before, and leaves
last after). Issues #498, #507, #518, #519, #520 and #533 are commented.

**The documentation corrections are also committed (`afa4665`):** the counts table (81 framework classes, 213 tables, 196 migrations
numbered to 198 with gaps at 168, 169 and 195, 803 PHP files, 552 addresses, 575 settings, 16 automatic checks, 8 self-tests), the
standing rule that wrongly said thirteen checks, this week's changelog entries, and two stale changelog facts (the check-in limit is
500, not 300; the check-in counts are no longer "for somebody to pick up").

**THE FOUR QUESTIONS WERE ANSWERED AT 19:55, all confirming what was built, so nothing changed:** the check-in limit counts attempts rather than accepted check-ins; the waiting list keeps promoting for postponed events; no tidy-up migration for old demo records; and the feature list is left describing what is true today, with the history staying in the changelog.

**PACKAGE 1 IS RUNNING: run `wf_8e4e27d5-3ff`, task `wah6coqwm`, script `.claude-work/resume/wf-package1.js`, outputs `.claude-work/resume/p1--*.md`.** It covers #533, #522, #531, #532, #529, #521 and leaving deleted events out of the check-in spreadsheet. The plan step is told to say plainly if any piece is bigger than it looks and should be split out.

**The questions as they were put (kept for the record):**
1. Refused check-in attempts now count toward the 500-in-five-minutes allowance, so the limit counts attempts rather than accepted
   check-ins. Recommended: accept.
2. The waiting list still promotes for POSTPONED events (not cancelled, not draft). Recommended: keep.
3. Whether a hygiene migration should also fill in the organisation number on old demo-register entries. The code fix already
   excludes them from every newsletter, so this is tidiness only. Recommended: not needed.
4. Whether FEATURES.md should note that the waiting list, live chat moderation and the leadership endpoint were broken for a period.
   They are described correctly today.

**#533 IS CONFIRMED AS A REGRESSION, not just a tidy-up:** accounts with no membership record really do exist on upgraded portals
(pre-#518 "Add User" created none), and the calendar-feed rule that needs one arrived in `110e47d`. Their calendar subscriptions now
answer "Invalid token". The owner has chosen to create the missing records. Worth settling at the same time: the check-in page and the
account guard treat "no membership record" as belonging on a single-organisation portal, and the feed does not — the three should
agree.

**NEXT, in order:** package 1 (things that leak or reveal: #522, #531, #532, #529, #521, #533, plus leaving deleted events out of the
per-event spreadsheet) → package 2 (plain faults: #508, #512, #528, #530, #509) → #515 → #516 → #517 → Fable review of the #514 plan →
#514 build → **the one comprehensive Codex review of the whole branch** → fix or log every finding → one pull request to alpha. After
that pull request, go back to sending each finished piece to Codex as it lands.

## LATEST — 20 September 2026, 18:30. RESUME FROM HERE.

**HOW CODEX REVIEWS ARE DONE HAS CHANGED, TEMPORARILY. OWNER DECISION, 18:25.**

- **For the whole of the current queue: do NOT send each finished package to Codex as it lands.** One comprehensive Codex sweep
  happens **after the #514 build**, covering BOTH the older work that was never reviewed (the owner committed some of it to GitHub so
  it could not be lost) AND everything built since, today's work included.
- **Nothing is raised as a pull request until that sweep is done** and every finding is either fixed and re-reviewed clean, or written
  up as its own issue the owner has seen and agreed to leave for later.
- **This is temporary.** Once the current queue is finished and the pull request is raised, go back to sending each finished piece to
  Codex as it lands.
- Claude-side independent checking does NOT change: every package still gets a fresh agent that did not build it, before it is
  committed.

**What this changes in practice, right now:** the fifth catch-up area (the automatic checks) and a first review of #525 are NO LONGER
run separately — they fold into the final sweep. That also keeps Codex's usage for the sweep itself, which matters: it hit its limit
today after four areas.

**WHAT THE FINAL SWEEP MUST COVER** (build the list from the branch, do not trust this one): everything from `110e47d~1` to the tip of
`claude/alpha-wip`. That is the owner's backup commit and everything after it — #498 demo data, #503, #507, #501 and the column
check's short-table-name support, #518 accounts, #519/#520/#523/#524 calendar, #525/#527 check-in counts, the Codex-fix package now
running, the two themed packages, #515, #516, #517, and the #514 build. Reviews run one area at a time through
`.claude-work/codex-queue.sh`, because a single giant review would not survive Codex's usage limit.

**STILL RUNNING NOW:** the Codex-fix package (`wf_e5eead5f-fa6`, task `w2lc0vibe`) fixing the thirteen findings Codex already gave us,
and the documentation scribe correcting stale facts in `.claude/CLAUDE.md`, `CHANGELOG.md`, `FEATURES.md` and `DEV_NOTES.md`.
**OWED once the scribe finishes:** add this temporary arrangement to the standing rule in `.claude/CLAUDE.md` (do not edit that file
while the scribe is working on it).

## LATEST — 20 September 2026, 18:15. RESUME FROM HERE.

**SEVEN OWNER DECISIONS TAKEN AT 18:00. These set the order of everything that follows.**

1. **Calendar subscriptions and accounts with no organisation:** keep the strict rule and FIX THE DATA. New issue **#533** (high): the
   members page must always create a membership record, and existing accounts with none get one. On a portal with exactly one
   organisation that happens automatically; where there are several, **nothing is guessed** — the accounts are listed for a global
   administrator to place, on screen and in the upgrade notes.
2. **A deleted event's check-ins:** keep the headcounts in the organisation totals, but leave deleted events OUT of the per-event
   spreadsheet, so a deleted event's name cannot reappear. (A small change inside #525's code — do it in package 1 below.)
3. **The ten smaller issues: two themed packages, next.**
   **Package 1 (things that leak or reveal):** #522 dashboard counts every organisation, #531 two people can take the same last seat,
   #532 registration reveals whether an internal event exists, #529 restrict who may see attendance totals (the owner already chose
   "restrict"), #521 the "email already exists" message, **plus #533** and **plus decision 2 above**.
   **Package 2 (plain faults and tidy-ups):** #508 breadcrumb crash on the upgrade and import pages, #512 path mode drops the
   organisation prefix, #528 the attendance grid can repeat or skip a day at the clock change, #530 whether to keep recording the
   browser description, #509 maintenance-mode side faults.
4. **Documentation:** small factual corrections NOW (a scribe agent is doing them: the counts table, "thirteen" checks that are really
   sixteen, the migration counts, and CHANGELOG/FEATURES entries for this week's work), and the FULL pass after #514.
5. **The pull request to alpha: not yet.** Only at the very end, one pull request for everything. Keep committing to
   `claude/alpha-wip`.
6. **The Fable review of the #514 plan:** just before its build starts, not now, because #515, #516 and #517 change the ground it
   stands on.
7. (From earlier today.) #525 is committed; the Codex catch-up findings are being fixed.

**RUNNING NOW:** the Codex-fix package (`wf_e5eead5f-fa6`, task `w2lc0vibe`) — draft plan done, challenge-and-settle step started about
18:10; and the documentation scribe agent. The scribe may only touch `.claude/CLAUDE.md`, `CHANGELOG.md`, `FEATURES.md` and
`DEV_NOTES.md`; commit its work promptly so the tree is clean before the fix package's build starts, or its checker will see
unexpected files.

**THE FULL ORDER FROM HERE:** Codex-fix package → package 1 → package 2 → #515 → #516 → #517 → Fable review of the #514 plan → #514
build (11 parts) → full documentation pass → one pull request to alpha. Codex reviews every package as it lands, and the fifth
catch-up area (`bash .claude-work/codex-queue.sh cat-checks brief-cat-checks.txt`) plus a first review of #525 (`12e637a`) are still
owed once its usage limit resets.

## LATEST — 20 September 2026, 17:45. RESUME FROM HERE.

**#525 IS DONE: committed `12e637a` and pushed.** Its round-2 check (Fable) PASSED, with a control proving its own test could fail
(0 error rows with the month fix, 33 without). One LOW residual it raised — the same "every signed-in member of your organisation"
understatement on the transfer form — was fixed in three places by a Haiku edit and a fourth by hand before committing. #525 and #527
are commented; #527 stays open on GitHub until the branch merges, which is normal.

**THE CODEX CATCH-UP RAN, AND EVERY AREA CAME BACK NOT CLEAN.** Four of five areas were reviewed before Codex hit its usage limit
again. **Every finding, with what happens to each, is written up in `.claude-work/reviews/codex-catchup-findings.md`** — read that
first. The answers themselves are `.claude-work/reviews/cat-{accounts,calendar,offline,demo}.answer.md`.

Five of the findings were re-read in the code by the orchestrator and hold up, including one in our own pull-request wiring: the new
account-change check cannot fail a build, because the workflow step throws its exit code away with `|| true`.

**STILL OWED FROM CODEX:** the fifth area (the automatic checks). Re-run when its limit resets:
`bash .claude-work/codex-queue.sh cat-checks brief-cat-checks.txt`
And then a first Codex review of #525 itself (`12e637a`), which was committed after the catch-up diffs were made.

**RUNNING NOW: the fix package for the thirteen in-scope findings** — run `wf_e5eead5f-fa6`, task `w2lc0vibe`, script
`.claude-work/resume/wf-codex-fixes.js`, outputs `.claude-work/resume/pcx--*.md`. Plan (Fable first), challenge and settle, Sonnet
build, independent check with fix rounds. It commits nothing. The plan step is told to judge each finding for itself and to say so
plainly if Codex is wrong about one.

**OPENED FROM THE CATCH-UP (pre-existing, not in this fix package):** **#531** an ordinary answer and a waiting-list promotion can take
the same last seat, so an event can be overbooked; **#532** the registration pages reveal whether an internal event exists.

**TWO SMALLER THINGS OWED:** check whether accounts with no membership row can exist on a real installation (they would lose their
calendar subscription under the newer feed rule — Codex flagged it as worth checking, not as a fault); and ask the owner whether a
deleted event's check-ins should still count in the organisation totals (found by the #525 round-2 check).

**THE QUEUE AFTER THIS:** #526 (four-level settings resolver, covering both the check-in limit and who may see the figures), #515,
#516, #517, then the #514 build (11 parts), then the thorough documentation pass. A Fable review of the #514 planning steps that Opus
produced is still owed before that build starts.

## LATEST — 20 September 2026, 17:10. RESUME FROM HERE.

**Both AI services are available again.** The Claude weekly limit reset at 16:00 today (it stopped work at about 02:40 on 18 September,
mid-way through #525's round-2 check), and Codex came back at 16:30. Both were probed at 17:00 and answered.

**TWO TRACKS ARE RUNNING RIGHT NOW.**

1. **#525 round-2 check** — a fresh FABLE background agent (Fable is available again, so it is no longer on Opus), brief
   `.claude-work/briefs/525-check-r2.md`, report `.claude-work/resume/p525--verify-r2.md`. It re-proves the five findings the round-1
   check raised and re-runs enough of round 1 to show the fixes broke nothing. **#525 stays UNCOMMITTED until this passes** (17 entries
   in the working tree: 11 modified, 6 untracked).

2. **The owed Codex catch-up review** — running as a background queue, one review at a time:
   `bash .claude-work/codex-queue.sh cat-accounts brief-cat-accounts.txt cat-calendar brief-cat-calendar.txt cat-offline
   brief-cat-offline.txt cat-demo brief-cat-demo.txt cat-checks brief-cat-checks.txt`
   (log `.claude-work/reviews/catchup-queue.log`; answers land as `.claude-work/reviews/cat-*.txt`). **If the session ends, re-run that
   command by hand for whichever names have no answer file yet.**
   Rather than replaying each commit, each review gets the NET change since Codex last reviewed (`git diff 110e47d~1..HEAD` limited to
   that area), because later work already replaced some of the earlier versions. The five areas: accounts (#518), calendar (#519,
   #520, #523, #524 and the final state of #503), offline cache (#507), demo data and erasure (#498 and the rest of the owner's backup
   commit 110e47d), and the automatic checks (#501 and the short-table-name support).

**AFTER THE CATCH-UP COMES BACK:** fix whatever it finds, re-review until clean, then commit and push each fix on its own, and comment
on the issues. Everything committed since `110e47d` carries "NOT YET REVIEWED BY CODEX" in its message; once a package is reviewed and
clean, say so on its issue so the record is honest.

**THE QUEUE AFTER THAT** (unchanged by the interruption): #526 (four-level check-in limit), #515 (site keys that clash with portal
addresses), #516 (giving roles, per organisation), #517 (user groups and departments), then the #514 build (11 parts, P6 onward needs
#518 which is now done, P10 needs #516 and #517), then the thorough documentation pass. A Fable review of the #514 planning steps that
Opus produced is still owed before that build starts.

## LATEST — 16 September 2026, about 22:15. RESUME FROM HERE.

**The owner's new instructions (16 Sept, evening), now standing:**
- They are recorded as "Working with the owner" in `.claude/CLAUDE.md` and in memory
  `owner-working-instructions-sept16.md`.
- Plain English in all feedback; the handoff kept current; Fable plans sequentially, with Opus per step and Fable
  retried; Sonnet or Haiku build; dev-team plugins allowed; cross-system review repeated until clean.
- **After each task:** commit and push to this branch, update the issues, update `.claude/`, update `.OpenAI/` (to be
  created), update the handoff.
- Autonomy, with decisions raised UP FRONT; progress shown as a table.
- The rule for switching AI systems (tool-agnostic) was already in the project CLAUDE.md, `~/.claude/CLAUDE.md` and
  `~/.codex/AGENTS.md`.

**New issue #514** (created 16 Sept): import outside calendars (`webcal://`), with visibility choices: public,
members, or selected volunteer or staff groups. It builds on #327, which the code check found only partly done:
- webcal addresses are refused (`feeds-save.php:31`);
- every imported event is public (`import-feeds.php:117`);
- there is no repeat or cancellation handling, events deleted from the feed are kept, and time zones are dropped;
- there is no internal-address guard;
- `/admin/calendar/feeds` is `App::isAdmin()`.

The body is in `.claude-work/resume/calendar-import-issue-body.md`. **It is queued LAST, as the owner asked.**

**OWNER DECISIONS, answered 16 Sept about 22:20:**
- **A: COMMIT AND PUSH NOW.** Commit and push each piece as soon as its independent Claude check passes, with the
  commit message stating plainly that Codex has NOT reviewed it yet. From 20 Sept Codex reviews everything committed
  this way, and fixes follow as new commits. **This replaces the 14 Sept "hold commits" decision.**
- **B, for #514: EVERY supported kind of group is selectable:** roles, small groups, leadership roles, user groups,
  and any other supported group, including custom or bespoke groups. This was recorded as a comment on #514.

**THE QUEUE, in order:**

| # | Task | Status |
| --- | --- | --- |
| 1 | run-d: 498-r5 check | DONE, PASS (Fable) |
| 2 | run-d: 503b-r4 (four handlers + live page) | DONE: check PASS (Fable); **committed `ed5d89f`, pushed**; #503 commented on; not yet reviewed by Codex |
| 3 | run-d: sqlcols-r6 | DONE: check PASS (Fable); **committed `bda3d84`, pushed**; #501 commented on; not yet reviewed by Codex. Follow-up (low): the example in the new `SmallGroups.php:104` comment matches the header blind-spot-15 grep ("written once" now gives 2 hits). NOTE: the dev-team guard hook blocks any Bash command containing the word for the production branch plus "push", even inside a commit message, so commit with `git commit -F <file>` and push in a separate command. |
| 4 | run-d: offline-cache-r4 (wording) | **DONE: committed `1c48e3c`, pushed, #507 commented on. Not yet reviewed by Codex.** Comments only (Auth.php, php -w identical). Check r1 failed on one clause; the Fable re-plan and Sonnet fix followed, and check r2 PASSED 00:44 (`runD--offline-cache-r4-verify-r2.md`). Follow-up **#515** was opened (site keys that clash with fixed portal addresses); the owner was asked at 00:40 where to queue it (suggested: straight after #514 planning). **run-d is COMPLETE**: all 4 packages verified, no Opus fallback needed. |
| 5 | **#514 outside calendars** (MOVED UP by the owner, 16 Sept about 22:35): Fable deep plan (sequential), Sonnet build (Opus if complex), check, commit, then Codex review | **starts as soon as run-d (items 3-4) finishes**; it cannot overlap run-d's planning (one analysis run at a time). **Planning script READY (23:26):** `.claude-work/resume/wf-514-plan.js`. Start it with the Workflow tool, `scriptPath` = that file. It has four sequential steps, Fable first with Opus per step: map, draft design, leak hunt, settle and split into build parts. Outputs go to `.claude-work/resume/p514--{map,design-draft,leak-hunt,plan}.md`. The build run is written AFTER the settled plan returns, one part at a time. **Owner questions 2-5 ANSWERED 17 Sept about 00:10, all as recommended** (recorded in the script as settled, and on #514). Q2: each organisation keeps its own list, and global administrators can manage any. Q3: turning a feed off hides its events; deleting a feed deletes them. Q4: nothing goes to the public website feeds without a separate tick, off by default. Q5: imported events are read-only, except that an admin can hide one, and it stays hidden after refreshes. **SCOPE ADDED 17 Sept about 00:05-00:12 (owner): per-event visibility choices, approval-gated rules, notifications, category matching.** Every owner answer is written into `wf-514-plan.js` as decisions D1-D17 (the one list to check against), and posted on #514 (text kept at `.claude-work/resume/issue-514-scope-addition.md`, with added acceptance criteria 8-13). Summary: single date / series / rule (categories, title words, location); both directions (Public, Members, Selected groups, Hidden); detail level defaults to "title, date and time only"; the most specific setting wins and conflicts fail safe; a changed identity falls back to the calendar level; rules never widen private-marked events; rules can combine conditions, have exceptions, and carry start and end dates; NEW matches that widen visibility WAIT FOR APPROVAL (Approvals inbox, dashboard count, daily email, push); re-approval is needed when a publicly shown detail other than the date or time changes; outside categories can be matched to portal categories. **Planning script updated 00:13:** it now has 6 sequential steps (map, design, leak hunt, settle, decision check, revise only if gaps are found) and passes a syntax check. **PLANNING RUN STARTED 00:45, 17 Sept: run `wf_59987118-c7a` (task `wggv3o3he`, resumed 01:39 as task `w0mh92e6g`).** If the session stops: `Workflow({scriptPath: ".claude-work/resume/wf-514-plan.js" (absolute path), resumeFromRunId: "wf_59987118-c7a"})`; finished steps replay from cache. The durable outputs are `.claude-work/resume/p514--{map,design-draft,leak-hunt,plan,decision-check}.md`. **Step 1 (map) DONE on Fable 01:12** (`p514--map.md`). Step 2 (design) has been running since 01:12. **The map found (VERIFIED by me at about 01:17):** nothing in PHP ever adds a row to `tblUserRoles` (only offboarding and the GDPR eraser delete them), no seed grants a role, and `tblUserRoles` has no siteID, yet 62 files call hasRole. `tblGroups` and `tblUserGroups` have no writer and no siteID; `tblDepts` and `tblUserDepts` have no writer. Opened **#516** (roles, priority high) and **#517** (user groups and departments, priority medium). **Owner decided about 01:20:** D1 AMENDED (every kind supported, including roles, user groups and departments, which get fixed FIRST by #516 and #517; #514 does not build that management and states what it needs from them) and a new D18 (audiences can mix groups with named people, failing closed when someone leaves). Both were added as a `LATE` block to the prompts of steps 3-6 ONLY (the step 1-2 prompts were verified byte-identical; backup at `wf-514-plan.before-late-decisions.js`). **DONE 01:39:** step 2 (design) finished (`p514--design-draft.md`); the run was stopped and resumed as task `w0mh92e6g` (same run id `wf_59987118-c7a`). The map and design replayed from cache, and the leak hunt restarted with the late decisions in its prompt. **FABLE OUT OF USAGE CREDITS at 01:50, 17 Sept** (the Fable leak-hunt agent `a1bf895eaf1d93cab` got "You're out of usage credits"). The script's per-step fallback worked: **step 3 (leak hunt) was done on OPUS** (01:50-02:14, `p514--leak-hunt.md`), and **step 4 (settle) is running on OPUS** since 02:16 (Fable was tried first and refused at once). Steps 5-6 will try Fable first again. **Owed once Fable is back: a Fable catch-up review of every Opus-produced #514 step (leak hunt, settle, and the check or revise if they also fall back), as one body, before the #514 build starts.** This is the second Fable credit run-out (the first was 15 Sept), which suggests the Fable limit may need raising. **Leak hunt (Opus) found 33 points (4 high).** Two are EXISTING faults in the live code; both were verified by reading the code at about 02:30 and opened as issues: **#518 (CRITICAL)**: `admin/users/save.php` lets any administrator of one organisation change ANY account in every organisation (password, email, active, the portal-wide isAdmin flag), with no organisation check on the UPDATE (lines 169, 203-249); CSRF is checked; not reproduced on a database. **#519 (high)**: `/attend` (anon-checkin.php) shows any published event's name and date to anyone, internal events included, and accepts check-ins. **Owner decided: #518 goes NEXT after #514 planning, and #519 straight after #518** (then #515, #516, #517). **#514 PLANNING COMPLETE (about 03:27, from the run duration of 108 min after the 01:39 resume).** Map and design ran on Fable; leak hunt, settle, decision check and revise ran on OPUS (Fable out of credits). The decision check found 16 gaps; the reviser fixed them in `.claude-work/resume/p514--plan.md`. The summary is in the journal of run `wf_59987118-c7a` (last result). Build parts: P1 visibility rule and tables (opus); P2 adopt the rule everywhere, about 60 files (opus); P3 imported events read-only plus a new check (sonnet); P4 SafeFetch (opus); P5 IcsReader (opus); P6 FeedImporter (opus; blocked by #518); P7 choices, rules and approvals (opus); P8 admin pages (sonnet); P9 notifications (sonnet); P10 roles, groups and departments as audiences (sonnet; blocked by #516 and #517); P11 docs (sonnet). **8 OWNER QUESTIONS ANSWERED about 06:30** (recorded at the TOP of `p514--plan.md` and on #514; all as recommended except Q4, which CHANGES the plan to show own members-only events to members, and Q5): 1 private-marked events at calendar level; 2 content changes under choices; 3 API keys; 4 members-only events in calendar views; 5 real Google and Microsoft 365 test calendars; 6 later series dates follow an approval; 7 approval notices when an organisation has no site admin; 8 what the basic detail level shows. **Also owed before the build: a Fable catch-up review of the Opus-produced steps.** Existing fault noted by the plan: `Events.php:138` selects `u.email`, which does not exist, so waiting-list promotion likely fails; not yet verified, and possibly already covered by #438. **Q5 test calendars:** the owner chose "Claude creates them", but the tools cannot create or publish a calendar, and Microsoft cannot create repeating events. **Owner chose (about 06:45): the owner creates and publishes two EMPTY test calendars (Google and Microsoft 365) and shares their addresses; Claude adds all the Google events and every single Microsoft event; the owner adds the one Microsoft repeating event by hand from a short list Claude provides.** Needed before P5. **New issue #520 (high):** `Events.php:138` selects `u.email`, so waiting-list promotion ALWAYS fails silently (caught, rolled back, logged). Verified by reading; queued WITH #519 (owner). |
| 5a | **#518 (CRITICAL)** one organisation's administrator can change any account | **DONE 17 Sept: committed `e1d0a34` (fix + `docs/post-deploy-check-518.md`) and `24ddd10` (the new check wired into pr-security.yml as step 21; the self-test now catches a loosened flag test), both pushed; #518 and #508 commented on.** Independent check by a fresh OPUS agent (Fable out of credits): **PASS**, 238 own checks plus 84 control checks proving the attacks still work on the old code. Build proof: 199 checks, twice. I verified the tree, all checks and the loosened-guard case myself. Follow-ups opened: **#521** (the "email already exists" message spans organisations) and **#522** (the admin dashboard counts every organisation). The import page's own messages stay invisible until **#508** is fixed. A claim that `Workflow.php:1057` compares a flag as text was CHECKED AND IS WRONG: it casts to text first, so it works; no issue needed. **Not yet reviewed by Codex.** |
| 5a2 | **#519 + #520 (+ #523, #524)** | **DONE 17 Sept: committed `a75297a`, pushed; #519, #520, #523, #524 and #526 commented on.** Independent check PASS (Opus; Fable out of credits) with a control run beside every proof. The default limit was raised from 300 to 500 on the owner's instruction before committing, and I proved on a real database that it seeds once and replays once. `check_sql_columns.py` now understands short table names, which is the blind spot that hid #438, #520, #523 and #524. **Not yet reviewed by Codex.** |
| 5a4 | **#526** check-in limit settable per event, venue, organisation or installation (most specific wins; default 500 in five minutes) | queued after #525 |
| 5a3 | **#525** anonymous check-ins are recorded but nothing reads them: show the counts to administrators, coordinators and hosts (owner asked 17 Sept about 21:45) | **RUN STARTED 23:05, 17 Sept: run `wf_42b94761-1a3`, task `wz46o6cw4`** (script `.claude-work/resume/wf-525.js`; plan, challenge and settle, build, independent check; outputs `.claude-work/resume/p525--*.md`). **Draft plan DONE on OPUS 23:36** (`p525--plan-draft.md`), with parts A-H: a new `AnonymousCheckins` class as the only reader; the event attendance page; the event hub tile; the attendance report plus a new CSV; the livestream dashboard; migration 198; retention; help pages. **It found four more real faults, all verified by me at 23:45 and opened as issues: #527** (the livestream dashboard's per-event list is not limited to one organisation — being fixed inside this package, as it edits that same query), **#528** (the multi-day attendance grid can repeat or skip a day at the clock change; also notes a raw `<table>` there), and **#529** (a DECISION for the owner: any signed-in member can see the organisation's attendance totals). It also found that the "forget me" list promises to erase anonymous check-ins but cannot, because the table has no link to a person — the 90-day clear-out makes that entry honest. **THE OWNER ANSWERED ALL SIX AS QUESTIONS, 18 Sept about 00:05, and TWO CHANGED the draft:** (A2) who may see the anonymous figures is now a SETTING with four choices — administrators only (default), administrators and event coordinators, event coordinators only, same as the rest of the page — seeded installation-wide and settable per organisation here, with venue and event levels coming in #526 via one shared resolver; (A6) the "probably unique" figure must be worked out and STORED before the detail is cleared, so an old event's figure does not drift. Unchanged: build the add-to-session action now; retention 90 days with 0 meaning for ever; fix #527 here; the hub tile for coordinators and administrators only. Separately the owner decided **#529: restrict the attendance totals page**, planned with the same four choices — its own piece of work, not this package. **The run was stopped and resumed at 00:10 as task `w0mtf5qcn` (same run id) with a LATE block added to the settle, build and check prompts only; the draft planner prompt was verified unchanged, so it replayed from cache.** Backup of the previous script: `wf-525.before-owner-answers.js`. **LESSON (17 Sept, 23:52-23:58): a resumed workflow does NOT replay a step that went through the Opus fallback, even with an unchanged prompt** — the draft planner ran again (about 5 minutes) before the settle restarted at 23:58 with the owner's answers. So when a stop-and-resume is needed, expect to pay again for any step that fell back. The interrupted settle had already written a settled plan at 23:51 WITHOUT the owner's answers; the new settle overwrites it. **SETTLED PLAN DONE on OPUS 00:11, 18 Sept** (`p525--plan.md`, 903 lines; it found 14 corrections to the draft, two of them serious: the settings editor CANNOT hold a per-organisation value for a setting that already has an installation-wide one, so a small screen is needed; and the 90-day clear-out compared timestamps, so it would have cleared part of a day and frozen a half-day figure for ever). **It asked seven questions; the owner answered all seven between 00:05 and 00:15, and THREE changed the plan:** administrators are ALWAYS included whatever the setting says; the setting therefore has THREE choices (administrators only [default], administrators and coordinators, same as the rest of the page) because the fourth would have behaved identically; and a day that gains late check-ins after its figure was stored is MARKED APPROXIMATE on screen rather than silently double-counted. Confirmed: build the small settings screen; a site administrator sets their own organisation's value while only a global administrator changes the installation default; the CSV download stays administrators-only whatever the setting says; the "same as the page" choice is offered on the report with plain wording; keep recording the browser description for now (a separate issue about stopping that is OWED — orchestrator to open). **BUILD RESTARTED 00:20 as a BACKGROUND AGENT (Opus builder, chosen by the plan for complexity), brief `.claude-work/briefs/525-build.md`, progress `.claude-work/resume/p525--build.md`.** The workflow was stopped instead of resumed, because a resume re-runs any step that used the fallback model and would have repeated the whole settle. **BUILD COMPLETE about 01:05, 18 Sept: 206 proof checks, 0 failures** (`p525--build.md`). 6 new files and 11 changed; #527 fixed in the same package with a control run proving the old code really did show organisation B's event on A's dashboard. The builder caught two real faults of its own: the PLAN's snapshot SQL was wrong (it would have marked ordinary days as having late arrivals), and transferring figures into an attendance session puts the EVENT NAME on a page every member can open, so a warning now appears before the button. It also found that `check_sql_columns.py` does not read the new aggregate queries at all (its own documented limit), so its green result proves nothing about them; the database proofs do. **Independent check r1 launched 01:09 as a background agent (Opus; Fable still out of credits), brief `.claude-work/briefs/525-check-r1.md`, report `.claude-work/resume/p525--verify-r1.md`.** **#530 opened** (the owed issue: nothing reads the visitor's browser description — decide whether to keep recording it). **CHECK r1 came back NOT CLEAN about 01:55** (`p525--verify-r1.md`, 553 lines; 158 of its own scripted checks plus 108 access observations, and it STAGED the simultaneous-press race the builder had only reasoned about: one row every time, and with the lock removed from its own copy, two rows every time). Five findings: (1) MEDIUM, a real regression — a made-up month in the address (`/attendance/report?month=99`) fills `tblErrors`, 33 rows from three requests on the new code versus 0 on the committed code, because the new card prints `$monthNames[$reportMonth]` unguarded at `report.php:407`; (2) LOW-MEDIUM, the settings screen and help say the widest choice means every member "of your organisation", but that page has no organisation test at all, so it also means anybody from another organisation signed in on that address; (3) LOW, a comment promises the transfer form shows the number already stored, and nothing calls the method that would provide it; (4) LOW, a comment gives a NULL-uniqueness reason that stopped being true at migration 187; (5) LOW, the "forget me" catalogue still says `erase` for a table the eraser skips. It agreed with both of the builder's own admissions and reproduced them. **FIX RUN launched about 02:05 as a background agent (Sonnet), brief `.claude-work/briefs/525-fix-r1.md`, progress `.claude-work/resume/p525--fix-r1.md`. **FIX DONE about 02:30: all five fixed** (`p525--fix-r1.md`), six files touched. The month fix proved: 3 x `?month=99` plus 3 x `?month=13` add 0 error rows, while an unfixed control copy still adds 33. The transfer form now really shows how many are already stored under the label, so the comment and the code agree. The fixer also caught and fixed its own regression on the way (a comment placed where `check_personal_data_coverage.py`'s text parser could not read the entry). **Round 2 re-check requested from the SAME checker at 02:35** (it keeps its round-1 context), report `.claude-work/resume/p525--verify-r2.md`. Two faults the fixer reported as pre-existing were CHECKED BY ME: the erasure run that stops part way IS already issue **#510** (the code's own comments name it), and the retain-versus-erase point is cosmetic because a table with no link column is skipped before its decision is read — noted on **#479** rather than opened as a new issue.** **STILL OWED AFTER THE CHECK PASSES:** commit and push; close #527 in the commit; comment on #525 and #527; `.claude/CLAUDE.md` says thirteen audit checks but there are SIXTEEN (fix in a separate commit, not while the checker is verifying the changed-file list). The leftover `p518-mysql` container was removed. The draft's own recommendations were: build the "add to this attendance session" action now (one labelled row, re-runnable, never doubling); the new anonymous figures on the report are administrator-only; one retention setting `attend.detailRetentionDays` seeded at 90, 0 means keep for ever; fix #527 here; the event hub tile only for coordinators and administrators; and the "probably unique" figure gets less exact for events older than the retention window, said plainly on screen. NOTE: the run's own description line still quotes the #519/#520 package because the script was copied from it; the agent prompts are #525's. Fixed in the file for any re-run. Plan must settle: who may see; duplicate handling via the stored scrambled address; whether the counts feed the Attendance app's sessions (recommended NOT automatic); how long to keep the browser description and scrambled address (recommended 90 days, a setting); never identify a person. |
| 5b | **#515** site keys that clash with portal addresses (owner: straight after #514 planning) | queued: Fable sequential plan, then Sonnet build, check, commit |
| 5c | **#516** give and remove roles; roles per organisation (owner: before #514's build) | queued: Fable sequential plan (must provide what the #514 plan needs), build, check, commit |
| 5d | **#517** manage user groups and departments; user groups per organisation (owner: before #514's build) | queued: as #516 |
| 5e | **#514 build**, part by part, following the settled plan | queued after 5b-5d |
| 6 | Create `.OpenAI/` context and memory for Codex | **DONE.** Written by Sonnet; independent check rounds (Fable) found and got fixed: the commit rule, an invented rule, release channels, review stance, GIRFT and wording. The last low point (an unprovable "channels still share one database" sentence) was fixed with the checker's exact wording (Haiku edit, verified by grep). The three out-of-date passages in `.claude/CLAUDE.md` and the codex-review memory were aligned with the 16 Sept commit decision. Committed with this handoff. |
| 7 | After-task steps for each finished package: commit and push (decision A), issues, `.claude/`, `.OpenAI/`, handoff | rolling |
| 8 | Codex catch-up review of `110e47d`, `ed5d89f` and later work; fix and re-review until clean | from 20 Sept 16:30 |
| 9 | Thorough documentation update: every .md, in-app help, OpenAPI spec (#482: 29 undocumented endpoints), Swagger UI check (already self-hosted with fallback), `.claude/` and `.OpenAI/` | queued after #514 |

## Earlier on 16 September 2026, about 20:00 (older entries follow).

**What happened since 15 September 12:45:**
- **`run-c` ended part-way:** the Claude WEEKLY limit was hit ("resets Sep 20 at 4pm"). The 498-r5 BUILD failed to
  start, so NO code change was made for it. Its settled plan is saved in `.claude-work/resume/runC--498-r5-plan.md`.
  The 503b-r4 and sqlcols-r6 planning never ran. offline-cache-r3 had finished (code proven, 1 P4 wording gap left;
  see the 15 September entries).
- **COMMIT `110e47d` (15 Sept 13:15, author Salem874, pushed to origin/claude/alpha-wip) was NOT made by Claude.** Its
  message reads like an editor-generated one ("Refactor demo data handling and improve service worker caching
  strategy"). It committed ALL the held work plus the handoff, plans and `.dev-team/FEATURES.md`: 31 files covering
  498, followups-498, offline-cache (r3 state), sqlcols (r5b state) and 503b (r3 state). **This goes against the
  owner's 14 September "hold commits until Codex" decision, so the owner has been asked to confirm it was
  intentional. Do NOT revert it without the owner.** Codex still has to catch-up review all of it, now as a committed
  diff: `git diff 3effa5a 110e47d`.
- **OWNER CONFIRMED (16 September): `110e47d` was intentional,** a precautionary backup to GitHub in case of a local
  system problem while waiting for Codex. **Its contents STILL need the full review as originally planned:** Codex
  catch-up review from 20 September 16:30, package by package (498 with followups-498, offline-cache, sqlcols, 503b),
  using the existing briefs updated for anything changed since. Treat `110e47d` as committed but NOT yet reviewed.
- **16 September: the owner reports Claude capacity is back**, and asked for:
  1. **a GitHub issue for WinterCMS support**, paired with the WordPress issue #127;
  2. to pick up where we left off;
  3. the method: plan and analyse with SEQUENTIAL Fable agents (Opus per step only if Fable is unavailable, retrying
     Fable every step), and implement with Sonnet.

**About 20:40 — WinterCMS issue DONE: #513 created,** labelled type: feature, priority: medium, scope: core, for
consideration. Its sources are `.claude-work/resume/wintercms-{research,issue-draft,issue-final,issue-body}.md`, and
all 3 planning steps ran on FABLE (so Fable has credits again). A sibling comment was posted on #127; it notes that
issue #127's phase 3 shortcodes should use the #493 public door, not the bearer-key `/api/v1/events`, which does not filter
`isPublic` (and `isPublic` defaults ON). **`run-d` launched** (run `wf_bf06569f-293`, task `wt2c6mgvu`; container `rund-mysql`, ports 9040-9049; every step writes `.claude-work/resume/runD--<package>-<step>.md`): the continuation of run-c, with the same order
and method as below.

**20:46 — run-d, 498-r5: the Sonnet build found BOTH fixes ALREADY IN `110e47d`** (`demo-data.php` @version 2.4.0:
`pack('E')` float fingerprints and approximate-number columns read as `col * 1e0`; `GdprEraser` DELETE plus audit in
one transaction, with a checked reset). It changed nothing. **Correction to the 16 Sept note above:** the 15 Sept
498-r5 build DID run and edit those files before the weekly limit killed it, and the owner's backup commit captured
those edits. **So `110e47d` contains 498-r5 work that was UNVERIFIED when committed.** run-d's check r1 is verifying it
now. (503b-r4 and sqlcols-r6 never started on 15 Sept, so they have no such edits.)
**21:04 — 498-r5 check r1 PASS, on FABLE.** Both Codex round-4 findings are fixed as `110e47d` holds them: the round-4
code reproduces both faults, every attempt to break the fixes failed, and all checks exit 0. The report is
`.claude-work/resume/runD--498-r5-verify-r1.md`. For the Codex catch-up, write `brief-498-r5.txt` from the r5 plan
and this check. **503b-r4 planning is now running.**

**The order now (only ONE analysis run at a time):**
1. Workflow `wintercms-issue`: research, then draft, then challenge. Outputs go to
   `.claude-work/resume/wintercms-{research,issue-draft,issue-final}.md`. Then Claude creates the issue from the final
   text.
2. THEN a continuation of run-c, as new uncommitted changes on top of `110e47d`:
   - **498-r5:** start at the Sonnet BUILD from the saved settled plan, then the check and fix rounds;
   - **503b-r4:** the four handlers;
   - **sqlcols-r6:** the stand-in findings plus the two comment follow-ups;
   - optionally **offline-cache-r4**, for the P4 wording.

   Each package: a Fable plan (Opus per step), a Sonnet build, and an independent check.
3. Codex catch-up review from 20 September 16:30 of `110e47d` plus the new changes. Ask the owner whether commits stay
   on hold.

## Previous — 13 September 2026.

### ✅ Fable plan review — DONE (13 September)

Ran on Fable itself (no fallback needed): critique, then a challenge that tried to refute every point,
then corrections built only from points that survived. **23 corrections, plus decisions only the owner
can make.** Saved, committed, in `.claude/plans/public-door-4-fable-{1-critique,2-challenge,3-corrections}.md`.
`public-door-3-build-plan.md` now opens with a banner: read the corrections first; where they disagree,
the corrections win. Headlines: the plan's own boundary self-test could never pass; Step 2 would break every
error page on the management portal; a design safety layer (sign-in methods refusing on the public door) was
silently dropped; Step 4 queries tables Step 5 creates; a folder name of `..` walks past every guard; the
changeover order could serve the old management portal on the public hostname.

**Owner decisions on the plan review — answered 13 September.** Recorded at the end of
`.claude/plans/public-door-4-fable-3-corrections.md`. Series-level public tick with per-date opt-out; full
description on an event's own page and an excerpt in lists; no embed allow-list; delegates may prepare a
surface before switch-on; `public.assetBase` dropped. **Still open: per-channel publishing** — the owner
prefers it now and asked for a real estimate before choosing. Being measured two ways (a per-channel switch on
the public hostname record, versus a channel column on the whole settings table).

### BUILD ROUND FINISHED, 13 September (workflow w882qn0nu, run wf_39955250-631) — state and what is next

Per-agent results: `~/.claude/projects/<this project>/<session>/subagents/workflows/wf_39955250-631/journal.jsonl`
(result order: pkg2 build, pkg2 verify, pkg1 build, pkg3 build, pkg1 verify, pkg3 verify, pkg1 fix, pkg3 fix).

| Package | Build | Independent verify | Fix | Re-verified? |
|---|---|---|---|---|
| pkg2 — 22 portal-wide settings pages (#495) | done | **PASS** | not needed | n/a |
| pkg1 — Step 1 review fixes (#493) | done | **FAIL**, criterion 10 (blocker below) | stop-gap applied | **no** |
| pkg3 — method-call checker on PHP's tokenizer (#494) | done | **FAIL**, criterion 2: case-6 fixture was not a real nested heredoc; plus 5 minor | all six fixed | **no** |

Main session re-ran the checks on the whole working tree afterwards. `php -l` on 64 changed or new PHP files:
clean. All 15 `tools/audit-checks/check_*.py`: exit 0. All 6 `tools/*selftest*.php`: exit 0.

**BLOCKER FOUND — pre-existing and serious, not caused by this round.** `web/_core/App.php` compares user-record
flags with the text `'1'` (`=== '1'`, around lines 276, 348, 399-400, 416, 443 and 464). But `App::user()` loads
the row through a prepared statement, which hands back whole numbers. Proven on MySQL 8.0.36 with PHP 8.5 and
mysqlnd: `App::isRootAdmin()` is FALSE for a real global administrator. A root or legacy administrator with no
site-administrator flag gets 403 on /settings. pkg1 and pkg2 reserve portal-wide changes for
`App::isRootAdmin()`, so as built NOBODY could change a portal-wide setting. The pkg1 fix agent put a stop-gap
in three pages only (settings/save.php, settings/index.php, admin/settings/group.php). pkg2's 22 pages have no
stop-gap. bootstrap.php's settings loader has the same shape (`isSensitive === '1'` decides decryption), with
its real type not yet proven.

**Being fixed now as pkg4** (Opus builder, background). Brief: `.claude-work/briefs/pkg4-admin-flags.md`. It covers:
- App.php made type-safe;
- the bootstrap comparison proven and made type-safe;
- the stop-gap removed;
- **Logger.php's own address reader moved onto RateLimiter::clientIp()** — the deferred #496 item, with no recursion and no need for the database;
- a scan listing every other text-versus-number comparison, which becomes a new GitHub issue. Nothing outside its list is fixed.

**Codex reviews launched, round 1:** pkg2 (`brief-pkg2b.txt`, which is `brief-pkg2.txt` plus a note that the App.php
fix is separate) writing to `.claude-work/reviews/codex-pkg2-r1.txt`; pkg3 (`brief-pkg3.txt`) writing to `codex-pkg3-r1.txt`.
The diffs are in `.claude-work/reviews/pkg2.diff` (22 files) and `pkg3.diff` (26 files, including pr-security.yml step 20).
**pkg1 and forged-address (#496) reviews wait for pkg4**, because pkg4 edits their files.

**Commit order once each review comes back clean:**
1. pkg1 and pkg4 as ONE commit, since they share the three settings pages;
2. pkg2;
3. pkg3;
4. forged-address, including Logger.php.

Stage named paths only.

**Follow-ups for one new issue (found by the agents, NOT fixed):**
- The pages behind 17 of the pkg2 handlers still show a working Save button to site administrators. The server refuses the save and explains why, but only afterwards.
- The dashboard shows the set-up checklist's Dismiss button to every administrator.
- The read-only captcha page still says "Drag to re-order".
- The QR settings page says "leave empty to keep", but saving writes the empty value over the stored API key. It also stores that key unencrypted while marking it sensitive.
- The apps page escapes the app name twice in its success message.
- The header comment in venues/settings.php is out of date: migration 187 stops the duplicate rows it describes.
- The admin help page never mentions `portal.devAccessRoles`.
- A mistyped `portal.trustedProxies` entry is ignored silently.
- Header-comment dates are inconsistent (11 v 13 September).

### 14 September ~12:15 — NEW SESSION (68f7195e): the 09:04 stop recovered; what is being relaunched

**What happened.** At 08:53 the old session (19f94bec) asked the owner a question and waited for the answer. At 09:04
the session ended. Every background task it owned was killed: **waiter 3 (the 12:37 Codex queue)** and three test web
servers. Three workflows were cut off part-way. Nothing on disk was lost.

**Checked at 12:03, before touching anything:**
- No Codex queue or waiter process is alive, so the 12:37 queue will NOT start by itself.
- All eight Codex review diffs still match the working tree. The one apparent exception is `498.diff`: its
  `full_schema.sql` now differs only by lines pkg6b added later, and all 25 of its own changed lines are still present.
- All nine Codex briefs exist and are not empty.
- `codex-pkg6-catchup.txt` and `codex-forged-r3.txt` hold only the 07:49 usage-limit error. They are NOT reviews.

**Interrupted work.** The finished reports are saved in `.claude-work/resume/`, because the old session's folders
are not durable.
- **pkg6b** (`wf_0c7a1efd-c6c`): the build is done, and verify round 1 FAILED on 4 gaps. Fix round 1 is done (it corrected false
  comments about `calendar/views/photo.php`, and the job headers now describe maintenance mode accurately). **Verify
  round 2 never returned.** What happens to each gap:
  - Gap 2 needs an **owner decision**. During maintenance mode the three new `cron/...` addresses get the 503
    maintenance page, whereas the old `?cron=1` addresses answered with JSON. Allowing only `cron/health` would need
    `web/_core/Maintenance.php`.
  - Gap 3 is a real fault that pkg6b did not cause: `bootstrap.php:397` never decrypts secret settings. It goes with
    pkg5, migration 195.
  - Gap 4: four background requests now redirect a signed-out visitor to the sign-in page instead of answering. It
    needs a follow-up issue.
- **small-faults-sql-columns-2** (`wf_7543f710-779`): the builder found and fixed FOUR faults, not two:
  - `coordinators-save.php` used `email` instead of `emailAddress`;
  - `reports/index.php` summed a `headcount` column that does not exist, and used `createdAt` twice instead of
    `timestamp`.

  **Verify round 1 never returned.** The builder also found the same headcount fault in
  `web/_apps/admin/reports/data.php:87`, and did not fix it.
- **Issue sweep** (`wf_e4c43146-809`): gather and batches 1-3 are done (`sweep/batch-01..03.json`). **Batch 4 of 9
  never returned.**

**`web/_apps/venues/settings.php` was left out of commit `e414cf1` by mistake, as far as anyone can tell.** It is in
`pkg2.files` and in the reviewed `pkg2.diff` (Codex pkg2-r3: CLEAN), and its working-tree changes are line-for-line
identical to what was reviewed. The commit has 23 of the 24 files, and no reason for leaving it out is recorded. It
is to be committed as pkg2's last file once `php -l` and the checks pass.

**Being relaunched now:**
1. The workflow `resume-builds`, with two chains whose files do not overlap:
   - pkg6b verify round 2, then fix rounds if needed;
   - small faults: fix `reports/data.php` too, then verify all three files.
2. The workflow `issue-sweep-resume`: batches 4-9, then the double-check, project state, research and proposals.
   Fable is tried first. It is the only analysis run.
3. **Codex queue 4, started by hand** as a background command that waits until 12:37. Its log is
   `.claude-work/codex-queue-4.log`. The order is the same as waiter 3: pkg6-catchup, 501-r2, forged-r3, pkg3-r4,
   followups-498-r1, 503-r1, 498-r2, sqlcols-r2, 503b-r1.

**LAUNCHED at about 12:20 (update as they finish):**
- **`3e56f25` COMMITTED AND PUSHED:** `venues/settings.php`, pkg2's last file. Local HEAD equals `origin/claude/alpha-wip`.
- **`resume-builds`:** run `wf_cf95bc03-171`, task `wfrbv8t09`.
  Script: `~/.claude/projects/<project>/68f7195e-91e3-4a62-997b-e5e9acb09538/workflows/scripts/resume-builds-wf_cf95bc03-171.js`.
  - Chain A: pkg6b, container `pkg6b-r2-mysql`, ports 8970-8979.
  - Chain B: small faults, container `sf2-mysql`, ports 8980-8989.
  - **When each chain comes back verified, write its Codex brief and diff.** Neither has one yet:
    - `brief-pkg6b-r1.txt` covers Router, the cron files, 196, the three maintenance pages, the help page, one
      DEV_NOTES row, and full_schema's route block;
    - `brief-smallfaults-r1.txt` covers coordinators-save, reports/index and reports/data.
- **`issue-sweep-resume`:** run `wf_c3380904-b9b`, task `wwwb8rgdz`, in the same scripts folder. It covers batches 4-9, then
  the double-check, project state, research and proposals.
- **Codex queue 4:** background task `bq9u3ce8e`, log `.claude-work/codex-queue-4.log`. The two limit-only files were
  moved aside as `codex-pkg6-catchup.FAILED-0749.txt` and `codex-forged-r3.FAILED-0750.txt`.
  **If this session dies before the queue ends,** read the log to see which reviews finished, then run the rest by
  hand, from the repository root:
  `bash .claude-work/codex-queue.sh codex-pkg6-catchup brief-pkg6-catchup.txt codex-501-r2 brief-501-r2.txt codex-forged-r3 brief-forged-r3.txt codex-pkg3-r4 brief-pkg3-r4.txt codex-followups-498-r1 brief-followups-498-r1.txt codex-503-r1 brief-503-r1.txt codex-498-r2 brief-498-r2.txt codex-sqlcols-r2 brief-sqlcols-r2.txt codex-503b-r1 brief-503b-r1.txt`

**OWNER DECISIONS, answered at about 12:25 on 14 September:**
1. **RSVP-by-link invitations work for INTERNAL events too.** This keeps what the shipped code already does. The
   invitation goes deliberately to one named address, from someone trusted to run the event, and the page shows only
   the event's name, date and location. So the code needs no change. The comment in `rsvp-by-link.php` and the
   503b Codex brief must say this is deliberate, so it is not reported as a fault.
2. **Let ONLY `cron/health` through maintenance mode.** The retention clear-out and the backup check stay blocked, so
   they never run against a half-upgraded database. This needs `web/_core/Maintenance.php`, and new header wording in
   the three cron job files. **Build it as a pkg6b follow-up round AFTER chain A's verify returns**, never underneath
   a running verify, and include `Maintenance.php` in the pkg6b Codex brief.
   - Watch out: `Maintenance::isAllowed()` matches with `str_starts_with`, so a bare `cron/health` entry would also let
     through any future address starting with those letters. Match that one exactly.

**22:46 — CODEX IS OUT UNTIL 20 SEPTEMBER 2026, 16:30 (a weekly-scale limit). FABLE IS ALSO OUT OF CREDITS.
DECISION ASKED OF THE OWNER.**
- **Queue 9 started on time at 22:41:**
  - **codex-498-r4 COMPLETE: NOT CLEAN**, with two MEDIUM findings:
    1. `demo-data.php:696` turns numbers into text using PHP's precision of 14, so two DOUBLE values that differ
       slightly can give the SAME fingerprint. Current DECIMAL columns are not affected. It needs a lossless number
       representation, and conservative handling of fingerprints already recorded.
    2. The audit calls at `GdprEraser.php:1163` and `:1200` sit OUTSIDE the failure handling that resets the request.
       If an audit write throws, the request stays at `processing`, and the portal refuses a retry. It must be covered
       by the same reset, reporting accurately if the register entries were already deleted. This is separate from
       #510.
  - **codex-offline-cache-r2:** it hit the limit at 22:44 and did NOT review. Its output was moved aside as
    `codex-offline-cache-r2.FAILED-2244.txt`.
  - **codex-sqlcols-r5:** never started.
  - The message now says "try again at Sep 20th, 2026 4:30 PM".
- **Nothing is running** (no workflow and no waiter).
- **Unreviewed by Codex and verified only by Claude:** offline-cache-r2 (its diff and brief are ready) and sqlcols-r5
  (its diff and brief are ready).
- **Under the fallback rule** the reviewer must not be replaced silently. The owner has been asked to choose between
  waiting, adding Codex credit, or a recorded stand-in review.
- **OWNER ANSWER (after midnight into 15 September): HOLD ALL COMMITS UNTIL CODEX HAS REVIEWED.** Nothing from 498,
  offline-cache, sqlcols or 503b is committed on a stand-in review.
- **OWNER ANSWER, 15 September: "Stand-in review now".** A fresh agent that built none of the work reviews
  offline-cache-r2 and sqlcols-r5 using their Codex briefs. It tries Fable first, with Opus as fallback. Its result is
  recorded as a STAND-IN, NOT a full review.
- **The combined fix run then goes ahead, as ONE sequential workflow `run-c`.**
  - **Package order:**
    1. offline-cache-r3, only if the stand-in finds something;
    2. 498-r5, covering Codex 498-r4's two MEDIUM findings;
    3. 503b-r4, the four handlers;
    4. sqlcols-r6, covering the stand-in's findings plus the line-3163 comment and the `SmallGroups.php:101-112`
       comment.
  - **Each package:** a Fable plan (Opus per step if needed), a Sonnet build, an independent check, and up to 3
    re-plan and fix rounds.
  - **Every step writes its report to `.claude-work/resume/runC--*.md`.**
- **Commits stay ON HOLD.** Codex does a catch-up review of every package on or after 20 September 16:30, using
  briefs written after run-c.
- **LAUNCHED:** workflow `run-c`, run `wf_2cb105b3-281`, task `wht6qoh7x`. It uses container `runc-mysql` and ports
  9030-9039.
  - Stand-in reviews are written to `.claude-work/reviews/standin-offline-cache-r2.md` and `standin-sqlcols-r5.md`.
  - Every package step writes `.claude-work/resume/runC--<package>-<step>.md`.
  - **If the session dies:** the run cannot be resumed from a new session. Read the runC files to see how far it got,
    then run a fresh continuation script for the remaining packages, using the same method.
- The owner asked to be told when each build and check completes. A journal watcher does that and must be restarted
  after each step.

**15 September, 08:20-11:55 — run-c progress (every planning, review and check step so far on OPUS; Fable refused
each one for lack of credits):**
- **Stand-in review offline-cache-r2: NOT CLEAN**, with two points (`reviews/standin-offline-cache-r2.md`):
  - **P2:** on a front-controller server, `Vary: *` on a signed-in visitor's /offline/ made the all-or-nothing
    `cache.addAll()` store nothing;
  - **P3:** comments overstated which responses are covered.
- **Stand-in review sqlcols-r5: NOT CLEAN**, with two P3 points (`reviews/standin-sqlcols-r5.md`):
  - the `live_matches()` equivalence depends on the first word not overlapping itself, which is not stated;
  - pr-security.yml step 9 makes a crash look like a clean run.
- **offline-cache-r3 finished NOT VERIFIED after 3 check rounds.**
  - **The CODE is proven** in Edge, Firefox and WebKit on a front-controller server and on Apache:
    `Auth::offlinePageAnswered()` exempts the real offline/index.php from `Vary: *`, sw.js installs file by file,
    the Content-Type test is `str_contains`, and sign-out no longer keeps /offline/ inside portal-v1/v2 stores.
  - **The last remaining gap is P4 wording.** `Auth.php:238-239` and `306-307` claim the sign-out page and activate
    handler remove every earlier copy. They do not remove one narrow case: an organisation whose site key is
    "offline", on a front-controller server, where the current sw.js installed while a pre-#507 Auth.php was live, so
    its dashboard sits as /offline/ in portal-v3.
  - **Carry this into the Codex catch-up brief** as a known point: either correct the wording, or decide in code
    whether sign-out should stop always keeping /offline/.
  - Reports are in `.claude-work/resume/runC--offline-cache-r3-*.md`.
- **Now running:** 498-r5 (planning).

**21:55 — `sqlcols-r5b` VERIFIED (verify r1 on Opus: PASS). THE COLUMN CHECKER IS READY FOR THE 22:41 CODEX QUEUE.**
- Sonnet reworded the two passages (blind spot 15's SmallGroups sentence, and the `live_matches()` docstring's "committed
  version"). No code changed (AST-confirmed); `--strict` exits 0 and all audit checks exit 0.
- `sqlcols.diff` has been rebuilt (check_sql_columns.py plus pr-security.yml), and `brief-sqlcols-r5.txt` written; the
  queue 9 list already names it.
- **Follow-ups outside this round, for combined run C:**
  - the line 3163 comment "(the committed script ...)" should say "the older version, commit 9c77216";
  - the comment at `web/_core/SmallGroups.php` lines 101-112 falsely says the primary FROM table has no short name.
- **Model record for all of sqlcols-r5 and r5b:** Fable was refused ("out of usage credits") on EVERY planning and
  checking step (9 steps), so Opus did each one. Sonnet did every build. Record this in the eventual commit message.

**21:42 — `sqlcols-r5` FINISHED. Verify r3 (Opus) FAILED on 2 wording slips ONLY; the verifier confirms the code is correct and
everything else holds.** It used file checksum c9b47584…, and the result is saved as `sqlcols-r5--verify_r3.md`.
**The slips:**
1. Blind spot 15 falsely says no code writes a GROUP-named table with a short name. `SmallGroups.php:120` does, and it is
   only unreached by SELECT_RE.
2. The `live_matches()` docstring still says "the committed version", which should be "the older version (commit
   9c77216)".

**Fable failed ("out of usage credits") on all 7 planning and checking steps of `sqlcols-r5`; Opus did every one.**
Workflow `sqlcols-r5b` has been launched: a Fable re-plan (Opus fallback), a Sonnet wording edit, then an independent
check, with up to 2 check rounds. **If it verifies before 22:41,** rebuild `sqlcols.diff` and write
`brief-sqlcols-r5.txt`, which the queue 9 list already names.

**21:26 — `sqlcols-r5` round 2:** the re-plan r1 (Opus) and fix r1 (Sonnet: seven wording edits, no code change) are
done. **Verify r2 (Opus) FAILED, again on wording only.** The verifier states the code is correct and all six
original gaps are closed. The points:
1. Blind spot 3's example says `reports/index.php:91` still uses `createdAt`, which is stale since 3effa5a;
2. the summary paragraph's list of wrong-report routes omits 12, which now describes one;
3. line 685 is 98 characters long (cosmetic).

Re-plan r2 is running now; after it come fix r2 (Sonnet) and verify r3, the LAST round of this run. The saved result
is in `.claude-work/resume/sqlcols-r5--verify_r2.md`.

**21:08 — `sqlcols-r5`: Sonnet build DONE (report in `.claude-work/resume/sqlcols-r5--build.md`). Verify r1 (on OPUS;
Fable unavailable) FAILED, on 3 minor documentation-accuracy points only, with 0 findings on the tree:**
1. Blind spot 19, bullet 2 wrongly says the WHERE run-on cannot happen between double-quoted strings.
   `read_where_after_set()` can follow `" . "` into ordinary text (a contrived shape).
2. Blind spot 15 says "on the same line", but SELECT_RE's `\s+` before FROM can cross line breaks.
3. The `where_comes_later()` docstring has a stale "78", which should be 79.

Re-plan r1 is running (Opus again). Fable has now failed on every step of this run so far. Next come the Sonnet fix,
then verify r2. The verify r1 result is saved in `.claude-work/resume/sqlcols-r5--verify_r1.md`.

**20:47 — `sqlcols-r5` progress: BOTH planning steps ran on OPUS, because Fable was unavailable for each (tried first
each time; the quota is probably used up).** This fallback is to be recorded in the commit message and the Codex brief.
The draft plan (`sqlcols-r5-plan-draft.md`, written 20:12) and the settled plan (`sqlcols-r5-plan.md`, 20:34) are
saved. The Sonnet build has been running since about 20:35 and is still active. The check comes next, trying Fable
first again. The queue 9 waiter is alive for 22:41.

**19:58 — OWNER: "fix the column checker failures now, then queue the post-fix recheck with the other Codex reviews at
22:41". This SUPERSEDES the sqlcols decision in the 19:50 entry.**
- **Workflow `sqlcols-r5` launched**, following the owner's method:
  1. a Fable draft plan, written to `.claude-work/resume/sqlcols-r5-plan-draft.md`;
  2. a Fable challenge that settles it, written to `.claude-work/resume/sqlcols-r5-plan.md`;
  3. a Sonnet build;
  4. an independent check (Fable, with Opus per step only if Fable is unavailable);
  5. then a Fable re-plan and a Sonnet fix, for up to 3 rounds.

  Its aim is to converge: remove the two regressions against the committed checker (help-text false reports, and the
  lost `" . "WHERE` coverage) and the quadratic slowdown, and make the blind-spot list exact.
- **The queue 9 list** is now `codex-498-r4`, `codex-offline-cache-r2`, `codex-sqlcols-r5 brief-sqlcols-r5.txt`.
  `brief-sqlcols-r4.txt` was set aside as `brief-sqlcols-r4.SUPERSEDED.txt` so r4 is not reviewed.
- **When `sqlcols-r5` VERIFIES:** rebuild `sqlcols.diff` (check_sql_columns.py plus pr-security.yml) and write
  `brief-sqlcols-r5.txt` BEFORE 22:41. If it is not ready by then, the queue refuses the missing brief and skips it; run
  it by hand afterwards with `bash .claude-work/codex-queue.sh codex-sqlcols-r5 brief-sqlcols-r5.txt`.

**19:50 — UPDATE TO THE ENTRY BELOW: `sqlcols-r4` has FINISHED. Only the queue 9 waiter is still running.**
- **sqlcols-r4 verify r3 FAILED, with 6 MINOR gaps** (saved in `.claude-work/resume/sqlcols-r4--verify_r3.md`):
  1. `live_matches()` has undocumented quadratic time;
  2. the blind-spot 17 `_CARRY_RE` description is wrong (dot-to-dot stretches with commas carry on);
  3. blind spot 15/18: the SELECT column-list route to wrong reports is not listed;
  4. example SQL inside ordinary PHP strings (help text) is now wrongly reported, which is new against the committed
     version;
  5. `" . "WHERE` names are no longer checked by anything, and this is not recorded as a loss;
  6. the blind spot 18 "379 outside" figure is swapped.
- **Decision** (following the owner's "let the reviews finish, then apply all fixes at once"): sqlcols-r4 is ALSO sent
  to Codex, LAST in queue 9, with those 6 listed as known. Its gaps plus any Codex findings go into combined run C.
  `sqlcols.diff` has been rebuilt (it includes pr-security.yml step 9), and `brief-sqlcols-r4.txt` is written.
- **The queue 9 list is now final:** 498-r4, offline-cache-r2, sqlcols-r4. So in step B below, there is no need to
  re-verify sqlcols first; just run the queue.

**19:45 — THE OWNER MAY INTERRUPT THIS SESSION. IF YOU ARE A NEW SESSION, START HERE.**

**What was running at 19:45 (both die if the session ends):**
1. Workflow `sqlcols-r4` (run `wf_6749828a-a47`) was on its LAST agent, "verify r3". Verify r1 and r2 FAILED, and
   fix 2 and fix 3 are done. Its finished reports are saved in `.claude-work/resume/sqlcols-r4--*.md`.
2. Background waiter "queue 9", sleeping until 22:41.

**How to pick up in a new session:**
- **A. Codex reviews (after 22:39).** First check Codex with `codex exec --skip-git-repo-check "Reply with exactly one word: READY" < /dev/null`.
  Then, from the repository root, run
  `bash -c 'set -f; bash .claude-work/codex-queue.sh $(cat .claude-work/codex-queue-9.list)'`
  The list holds `codex-498-r4 brief-498-r4.txt` and `codex-offline-cache-r2 brief-offline-cache-r2.txt`. Both
  diffs and briefs are written, and both packages are VERIFIED.
- **B. The column checker.** A new session cannot resume `sqlcols-r4`. Run a fresh INDEPENDENT check of the current
  `tools/audit-checks/check_sql_columns.py` (and any change to the check_sql_columns step in
  `.github/workflows/pr-security.yml`) against `.claude-work/reviews/codex-sqlcols-r3.txt` and the saved reports,
  following the owner's method: Fable, falling back to Opus per step. If it passes, write `sqlcols.diff` and
  `brief-sqlcols-r4.txt`, then queue it for Codex after A. If it fails, its gaps go into the combined fix run in C.
- **C. After ALL Codex reviews:** ONE sequential run. For each package in turn: a Fable plan (Opus per step if
  needed, retrying Fable every step), a Sonnet build, and an independent check (Fable, falling back to Opus). It covers
  the four 503b handlers (see the 18:22 entry), and every NOT CLEAN finding from 498-r4, offline-cache-r2 and
  sqlcols. Then Codex reviews each, one at a time, and whatever is CLEAN is committed (498 together with
  followups-498).
- **Nothing is uncommitted that has not been saved.** The uncommitted packages are 498 with followups-498,
  offline-cache, 503b and sqlcols. The stopped Opus 503b-r4 edits are in `.claude-work/resume/503b-r4-opus-partial/`,
  NOT in the tree.

**About 19:40 — `codex-fixes-r4` FINISHED: offline-cache-r2 VERIFIED (verify r1 PASS). Added to the queue 9 list.**
- **Design change from the brief (evidence in `.claude-work/resume/r4--offline-cache-r2_build.md`).** The builder
  first built and tested the brief's "put the new worker in charge before cleaning up" idea, and it failed in all three
  engines. Instead, `Auth::sendOfflineCopyHeaders()` adds `Vary: *` (through the new `oldServiceWorkerWouldStore()`)
  to every signed-in page and every signed-in `/assets/` or static-ending response. Under the Cache Storage standard,
  `cache.put()` then refuses to store it, whichever worker asks.
- **Tested:** real Edge 153, Firefox 155 and WebKit 26.6 behind real Apache with php-fpm. Before the fix, a late page
  landed in portal-v2 with the person's name in all three engines. After it, 12 runs showed none.
- `offline-cache.diff` has been rebuilt and `brief-offline-cache-r2.txt` written.
- **The queue 9 list now holds 498-r4 and offline-cache-r2. Add sqlcols-r4 when it verifies.**
- **The owner's standing rule, restated on 14 September:** Fable plans and analyses using SEQUENTIAL agents. If Fable
  is unavailable, Opus stands in for THAT step only, and every later step retries Fable. Sonnet builds.

**18:55 — CODEX USAGE LIMIT AGAIN at 18:50; the reset is at 22:39.** The 498-r4 review did NOT run. Its output was
moved aside as `codex-498-r4.FAILED-1850.txt`, so it is not a review.
- **Queue 9 waiter** (background, this session only): at 22:41 it runs the NAME BRIEF pairs in
  `.claude-work/codex-queue-9.list`, which currently holds only `codex-498-r4 brief-498-r4.txt`. **Add
  `codex-offline-cache-r2 brief-offline-cache-r2.txt` and `codex-sqlcols-r4 brief-sqlcols-r4.txt` when those packages
  verify** and their diffs and briefs are written. The log is `.claude-work/codex-queue-9.log`.
- **If this session ends before 22:41,** run this by hand after the reset:
  `bash -c 'set -f; bash .claude-work/codex-queue.sh $(cat .claude-work/codex-queue-9.list)'`

**18:52 — "Pick up where we left off": the same session is still running. No Codex review was ever stopped (only the
Opus BUILD `503b-r4`). The owner's point: let all the Codex reviews finish, then apply every fix in ONE run. That
matches the order below.**
- **498-r4 VERIFIED** (verify r1 PASS; reports in `.claude-work/resume/r4--498-r4_*.md`). Invisible columns are read
  from information_schema and named explicitly; the eraser stops before the catalogue on any error other than 1146,
  sets the request back to pending_review and throws; migration 194 has guarded upgrade steps from draft 1, draft 2
  and round 3, marks old entries so they can never be wiped, and drops checkValue. Tested on MySQL 8.0.36 and MariaDB
  11.4 and 12.3.
- `498.diff` has been rebuilt (the full_schema.sql hunk is now only the 194 block, since 196 is committed).
  `brief-498-r4.txt` is written, and the **Codex review is running as queue 8** (`.claude-work/codex-queue-8.log`),
  started early because review only reads files.
- **Still running:** offline-cache-r2 (verify r1), and sqlcols-r4 (verify r1 FAILED, fix 2 running).
- Then, as planned: the Codex reviews of offline-cache-r2 and sqlcols-r4, one at a time after queue 8 ends. Then ONE
  sequential Fable-plan / Sonnet-build run for the four 503b handlers plus every Codex finding from 498-r4,
  offline-cache-r2 and sqlcols-r4.

**18:30 — OWNER INSTRUCTION: QUEUE IN ORDER, AND USE THE PROPER MODELS. `503b-r4` (Opus) STOPPED.**

The owner said:
> "Once the currently queued tasks are complete, (including the fixes for 507, 498 and the column checker, also queue
> the fix for: the events API's single-event lookup; the two event-hub lists (resources and videos); the RSVP page.
> and any fixes found from the codex checks for 507, 498 and the column checker. Remember, plan and analyse using
> Fable sequential agents (or Opus if fable isnt available) then implement using Sonnet"

**Correction recorded:** the fix rounds today (`codex-fixes-r3`, `codex-fixes-r4`, `sqlcols-r4`, the maintenance
rounds and `503b-r3`) all used **Opus builders and Opus verifiers**, not a Fable plan and a Sonnet build. The ones
still running (`codex-fixes-r4`, `sqlcols-r4`) are allowed to finish, as the owner asked. The memory file
`webms-working-method.md` has been updated.

**The order from here (do not start a step before the previous one has finished):**
1. **Let these finish:** `codex-fixes-r4` (offline-cache-r2 and 498-r4) and `sqlcols-r4`.
2. **Codex reviews, ONE AT A TIME,** each only if its package verified: offline-cache-r2, then 498-r4, then
   sqlcols-r4. Check Codex with a one-word prompt first. Write each brief and diff from the verified state.
   Commit any that come back CLEAN (498 goes with followups-498).
3. **ONE sequential fix run** containing:
   - 503b-r4, the four handlers from Codex 503b-r3: `events/api/detail.php:109`, `calendar/api/hub-resources.php:84`,
     `hub-videos.php:91` and `rsvp.php:99` must decide rights before the lookup so the query log is identical for a
     missing, draft or deleted event, for every caller kind, including API keys;
   - a fix for every NOT CLEAN finding from step 2.

   **For each package, one after another:** a **Fable plan** (Opus fallback built into the script), then a **Sonnet
   build**, then an independent check (Fable, falling back to Opus), then a Fable re-plan of any gaps, then Sonnet
   again, for up to 3 rounds.
4. **Codex reviews of step 3's packages, one at a time;** commit what comes back CLEAN.

**Stopped run `503b-r4`** (run `wf_71abc5ff-839`, stopped about 3 minutes in): it HAD already edited the nine part-2
files (most in detail.php, rsvp.php and both hub files). Its unverified edits are saved in
`.claude-work/resume/503b-r4-opus-partial/`, as `working-tree-at-stop.diff` plus copies of the files. The nine files
were restored to HEAD + `503b.diff`, confirmed identical to what Codex reviewed in round 3. The container
`lane2-mysql` and `/private/tmp/503b-r4-work` were removed. The Fable planner in step 3 MAY read the saved partial
diff as a starting idea, but must not assume it is correct.

**18:22 — Codex 503b-r3 NOT CLEAN; fix round `503b-r4` launched (then stopped at 18:30, see above).**
- Codex confirmed the invitation page is now fine. Its round-2 findings are fixed: the refusal paths run identical
  statements, the rights match `App::isAdmin()`, and the links use `Site::url()`.
- **New P2, the same timing shape in four other part-2 handlers:** `events/api/detail.php:109`,
  `calendar/api/hub-resources.php:84` and `hub-videos.php:91` call `App::isAdmin()` only when an event was found,
  and `rsvp.php:99` only for a draft. That triggers `App::user()`'s account query, so a draft and a missing event
  differ by one query for a signed-in non-administrator.
- `503b-r4` (lane2-mysql, ports 9010-9019) must decide the viewer's rights before the lookup on every request, with
  query-log parity for every caller kind, including API keys.

**18:20 — `503b-r3` VERIFIED (verify r1 PASS, no gaps); CODEX IS AVAILABLE AGAIN (a READY test at 18:19); its review is
running as queue 7.**
- rsvp-by-link.php: the draft rule sits in the lookup's WHERE clause (it joins tblUsers U and tblUserSites US for the
  event's own organisation). `App::user()` is read on every request and `App::isAdmin()` is no longer called. The
  query log is identical for every refusal case, and the timing difference is within ±0.18 ms. The details link uses
  `Site::url()`. A deliberate change: an administrator with a deactivated account is refused their own organisation's
  draft too.
- `503b.diff` has been regenerated (9 files) and `brief-503b-r3.txt` written. The queue 7 log is
  `.claude-work/codex-queue-7.log`.
- **Queue one package at a time:** start the next queue only after queue 7 ends, to keep Codex runs from overlapping
  and to limit usage.
- Reports are saved in `.claude-work/resume/503b3--*.md`.

**About 18:15 — `3effa5a` (smallfaults, #501) COMMITTED AND PUSHED; #501 commented on.** It covers the three files.
The two P3 comments Codex raised were corrected before committing (comment only), and those are the only differences
from the reviewed diff. It was proven on HEAD plus the three files alone.
**Commits today so far:** 3e56f25, 6c8712b, 3a1e437, dc193b1, 90ff001, 6c84a48, 3effa5a.
**Still uncommitted and in fix rounds:** 503b (`503b-r3`), offline-cache (`codex-fixes-r4`), 498 with followups-498
(`codex-fixes-r4`; followups-498 is already CLEAN and is committed with 498), and sqlcols (`sqlcols-r4`).

**17:56 — CODEX QUEUE 6 ENDED (all 7 are real answers, with no limit hit).**
- **smallfaults-r2 NOT CLEAN, on two P3 COMMENT points only.** It found no functional defect, and every one of the 14
  queries matches the schema. The two points:
  - `reports/index.php:112` calls `timestamp` a reserved word; it is a NONRESERVED keyword.
  - `data.php:167` says MySQL builds an index on the derived table; it CAN, depending on the plan.

  **Plan:** the main session fixes both comments, then commits the three files, following the 503 export.php
  precedent, and records that in the commit message.
- **sqlcols-r3 NOT CLEAN**, with three P2 and two P3 findings:
  - SQL inside a quoted value is scanned as a second statement;
  - `''` and `\'` escapes in SET values give a regression (a miss) and a false report;
  - aliases after `$var` and bare table names are reported as unknown tables;
  - blind spot 16 wrongly includes qualified SET names;
  - the PR workflow's `tail -n +5` drops the SELECT coverage heading on a clean run.

  **Workflow `sqlcols-r4` launched** (it may also touch that one step in pr-security.yml).
- **Nothing is queued for Codex yet.** The next queue needs: 503b-r3, offline-cache-r2, 498-r4, sqlcols-r4. Check
  Codex availability first with a one-word prompt.

**About 18:05 — Codex queue 6 results 4 and 5 are both NOT CLEAN; workflow `codex-fixes-r4` launched.**
- **offline-cache-r1 NOT CLEAN:**
  - **P1:** the sign-out script sweeps Cache Storage once, but the OLD portal-v2 worker stores responses asynchronously,
    so another tab's signed-in response that finishes after the sweep lands in portal-v2 and stays readable offline.
  - **P3:** the comment claiming JavaScript-off browsers have nothing stored is not true.
  - **Wording:** "empties" needs qualifying (Chrome is partial, Firefox 138+, Safari 17+).
- **498-r3 NOT CLEAN**, with three MEDIUM findings:
  1. `SELECT *` leaves out INVISIBLE columns, so data in an invisible column added after Load is wiped;
  2. GdprEraser's register step swallows its errors and then carries on into the catalogue, which deletes memberships,
     so a retry cannot find the remaining entries;
  3. migration 194 does not upgrade a table built from an earlier draft; it needs guarded ALTERs that keep entries,
     and Wipe must never delete an entry without a full fingerprint.
- **`codex-fixes-r4`** runs two parallel chains:
  - offline-cache-r2 (lane1-mysql, ports 9000-9009, Playwright browsers);
  - 498-r4 (lane3-mysql, ports 9020-9029).
- **Still running separately:** `503b-r3` (ports 9010-9019), and the Codex queue with smallfaults-r2, then sqlcols-r3.

**About 18:00 — `6c84a48` (503 part 1 + Site.php comment) COMMITTED AND PUSHED; #503 commented on.** Proven on
HEAD + those 3 files alone. The only difference from the reviewed diff is the softened export.php comment. 503 part 2
stays uncommitted until `503b-r3` is verified and Codex has reviewed it.

**About 17:55 — `90ff001` (pkg6b, #497 and #501) COMMITTED AND PUSHED.**
- It was proven on a worktree holding HEAD plus pkg6b only: every check and self-test passed, including parity
  `--strict`.
- The 17 files were staged, and full_schema.sql was staged using `git apply --cached` on a patch holding only pkg6b's
  two hunks. The #498 `tblDemoDataRegister` hunk is still uncommitted in the working tree.
- #497 has been commented on. 503-r2 is next: the export.php comment has been softened as Codex asked (comment only),
  then commit event.php, export.php and Site.php.
- **Now possible:** fix the Router.php comment "logout() calls exit() after redirect" once offline-cache is committed.

**17:47 — QUEUE 6 STARTED ON TIME (17:39:00). First three results:**
- **pkg6b-r1: CLEAN.** Ready to commit. `full_schema.sql` also holds the #498 hunk, so stage ONLY pkg6b's two hunks
  (use hash-object/update-index, or commit #498 first). Files are listed in `pkg6b.diff`.
- **503-r2: CLEAN.** Ready to commit (event.php, export.php, Site.php). One minor wording point: the export.php:163-165
  comment says a missing Host header happens "only" for very old clients; soften that at commit time.
- **503b-r2: NOT CLEAN**, with two P2 findings, both in `rsvp-by-link.php`:
  1. A signed-in visitor holding another organisation's draft token triggers the extra rights query (lines 159-168),
     but an unknown token does not, so timing can tell the two apart. Fold the rights check into the lookup, or give
     every path the same queries.
  2. The "View event details" links (lines 234 and 285) use a bare `/calendar/event?slug=`, which loses the path
     prefix. Use `Site::url()`.

  Fix round **`503b-r3`** was launched at 17:47.
- offline-cache-r1 is reviewing now; 498-r3, smallfaults-r2 and sqlcols-r3 follow.

**About 15:15 — `codex-fixes-r3` FINISHED (18 agents). All six packages are ready for Codex; the 17:39 list is final.**
- **offline-cache (#507):** verify r1 failed, then a fix, then **verify r2 PASS**.
  - The r1 failures were that Apache redirects `/offline` to `/offline/` (browsers refuse a stored redirected answer
    for a page load) and that sign-out deleted `/manifest.json`. Both are fixed.
  - The main session then corrected the flash-message comment in `Auth.php` (comment only, NOT re-verified).
  - Design: `Auth::ensureSession()` sends `X-Offline-Copy: allow` only when the session holds harmless keys; `sw.js`
    keeps only pages carrying that marker, with CACHE_VERSION `portal-v3`; sign-out is now a small page that sends
    `Clear-Site-Data: "cache"` and a script that clears Cache Storage.
  - Tested in real Edge 153, Firefox 155 and WebKit 26.6 (Playwright), and on real Apache.
- **sqlcols-r3:** verify failed three times. The last failure was only the header's blind spot 15 missing one
  wrong-report route: the SET reading runs on when the SQL string ends in `=`, `,` or `(`. The main session added that
  to blind spot 15, the paragraph listing wrong-report routes, and the UPDATE_RE comment (documentation only, NOT
  re-verified). The code was accepted by the verifier.
- **Diffs:** `offline-cache.diff` (Auth.php, sw.js) and `sqlcols.diff`. **Briefs:** `brief-offline-cache-r1.txt` and
  `brief-sqlcols-r3.txt`.
- **`codex-queue-6.list` final order:** pkg6b-r1, 503b-r2, 503-r2, offline-cache-r1, 498-r3, smallfaults-r2,
  sqlcols-r3. Queue 6 runs them at 17:39; if the limit hits part-way, the rest need running by hand after the next
  reset.
- **Follow-ups to do after BOTH pkg6b and offline-cache are committed:** the Router.php comment "logout() calls exit()
  after redirect" should read "logout() sends the sign-out page and calls exit()". DEV_NOTES text for the service
  worker is suggested in `.claude-work/resume/fx--offline-cache_build.md` (notDone).
- **Nothing is left running except the queue 6 waiter.**

**About 15:05 — four more packages VERIFIED; their Codex briefs are queued; issues #510-#512 opened.**
- **Verified in `codex-fixes-r3` (reports: `.claude-work/resume/fx--*.md`):**
  - **503-r2:** event.php and export.php build every portal link with `Site::url()`, and export.php's built-in
    live-domain fallback is removed; Site.php's comment is verified.
  - **503b-r2:** in rsvp-by-link.php, draft rights are checked against the EVENT'S organisation. The lookup is still
    open to every organisation, because emailed links carry no prefix. The "View event details" link only appears for
    the same organisation.
  - **498-r3:** every column is fingerprinted (not `tblAnnouncements.updatedAt`), plus a new `fingerprintColumns`
    column in 194 and full_schema. The catalogue marks it erase; there is a new
    `GdprEraser::eraseDemoDataRegisterEntries()` that runs first, a `data-export.php` block, and selftest check 10.
    The LOW point was declined again.
  - **smallfaults-r2:** `monthly_logins` counts only completed sign-ins, using session links for LoginLocal, and
    `OR siteID IS NULL` is removed. The overstated session-link comment in data.php was corrected by the main session
    afterwards (comment only).
- **Diffs regenerated:**
  - `503.diff` (event, export, Site);
  - `503b.diff` (9 files);
  - `498.diff` (9 files, including only the 194 hunk of full_schema and the deleted demo_data.sql);
  - `smallfaults.diff` — to be regenerated after the comment edit.
- **Briefs written:** `brief-503-r2`, `brief-503b-r2`, `brief-498-r3`, `brief-smallfaults-r2` (a FULL review, because
  r1 was cut off by the limit).
- **`codex-queue-6.list` order:** pkg6b-r1, 503b-r2, 503-r2, 498-r3, smallfaults-r2. Add sqlcols-r3 and offline-cache
  (#507) when their lanes are verified.
- **New issues:**
  - **#510 (critical):** GdprEraser stops part-way. The catalogue erases `tblErasureRequest`, the audit rows cascade,
    FK error 1452 follows, 65 of 124 steps never run, and no audit is left.
  - **#511 (high):** being signed in counts as membership of any organisation (an account in A read an internal event
    in B). The coordinator grant looks users up across organisations, and the legacy isAdmin flag covers every
    organisation.
  - **#512:** path-mode links without a prefix (rsvp.php redirect, event-assign returnTo, invites-send; uploads may not
    be served at all).
- **Still running:** sqlcols-r3 (in fix rounds) and offline-cache (fix round 1 done, verify r2 running).

**About 15:00 — ISSUE SWEEP FINISHED, BUT PARTLY ON THE FALLBACK: FABLE RAN OUT OF USAGE CREDITS at about 13:30.**
- **Workflow `issue-sweep-resume` (run `wf_c3380904-b9b`):**
  - batches 4-7 ran on **Fable**;
  - batch 8, batch 9, the double-check, project state, research and proposals each failed on Fable with "You're out of
    usage credits". **Each one then ran on OPUS**, which is the script's built-in fallback.
  - Every output exists: `.claude-work/sweep/batch-01..09.json`, `doublecheck.json`, `project-state.md`,
    `proposals.md` (also at `.claude/plans/alpha-proposals.md`), and `.dev-team/FEATURES.md`.
  - The double-check tested 60 recommendations (14 reopen, 1 close, 45 follow-up), and 58 held up.
- **Fallback record:** those six stages did NOT get Fable's deep analysis. **When Fable is available again, run a
  catch-up review on Fable** of `doublecheck.json`, `project-state.md` and `proposals.md` as ONE body of work. Treat
  its conclusions as not fully reviewed until then. NOTHING from the sweep has been written to GitHub yet.
- The build lanes (`codex-fixes-r3`) use Opus and Sonnet, so the Fable limit did not affect them.

**About 14:50 — pkg6b is VERIFIED IN FULL; its Codex brief is queued for 17:39; issues #508 and #509 opened.**
- `pkg6b-maintenance-health-r3` (run `wf_4d7265a4-0df`): fix round 3, then **verify round 4 PASS**. Reports:
  `.claude-work/resume/maint3--*.md`.
  - `cron/health.php` switches `display_errors` off for the whole page. During maintenance its harmless handler goes in
    before the token check and is never removed, and exceptions give a 500 JSON answer with no database row.
  - The duplicate heading is gone. The staff-page note and a comment in `public_html/index.php` were updated, and a
    sentence was added to the disaster-recovery help page.
- **`pkg6b.diff`** has 17 file sections. For `full_schema.sql` it keeps only the two pkg6b hunks: the tblRoutes block for
  196, and the removal of the photo row. The #498 hunk was filtered out by content. `brief-pkg6b-r1.txt` is written,
  and `codex-pkg6b-r1 brief-pkg6b-r1.txt` has been added to `codex-queue-6.list`.
- **When committing pkg6b:** `full_schema.sql` holds both pkg6b and #498 changes. Stage only pkg6b's hunks, using the
  `git hash-object -w` / `git update-index --cacheinfo` method used for 4eba0ee, or commit #498 first.
- **#508:** `/admin/upgrade` and `/admin/users/import` crash on the breadcrumb shape (the fix is one line each).
  **#509:** during maintenance, a malformed session cookie or `?lang[]=` writes error rows on any address, and a
  signed-in global administrator skips maintenance, so a cron address opened by hand runs its job.
- pkg6b's Codex brief lists these as known; there is no need to report them again.

**About 14:45 — `pkg6b-maintenance-health` FINISHED NOT VERIFIED after 3 verify rounds. Round 3 launched as
`pkg6b-maintenance-health-r3`.** Reports are in `.claude-work/resume/maint--*.md`.
- **Built:** `Maintenance.php` has `EXACT_ALLOW_LIST = ['cron/health']`, matched against `Router::extractPath()`
  output, which is lower case, has no query string, has its slashes trimmed and its organisation prefix removed. 25
  look-alikes were proven blocked.
- **Measured:** a normal call writes nothing; 210 table checksums, a file hash and the MySQL query log (SELECT and SET
  NAMES only) all agree.
- **Faults each verify round found, in turn:**
  - Round 1: a warning inside the probes, even one hidden with `@`, is written to `tblErrors` by the portal handler.
    Fixed with a harmless handler used only while maintenance is on.
  - Round 2: `?token[]=x` triggered "Array to string" before that handler was installed. Fixed: the token must be
    text, and the handler is now installed earlier.
  - Round 3: the `finally` block restores the portal handler BEFORE `header()`. With display_errors on and output
    unbuffered (php-cgi), "headers already sent" reaches the portal handler and writes a row. It needs the right
    token. **Being fixed now.**
- Also open: a duplicate heading in `cron/health.php`, and the staff-page note (on pre-release copies the gate sends a
  monitor to sign-in). Stale wording: `public_html/index.php:55` and `help/disaster-recovery.php:55`.
- **Already there before this work, for an issue:**
  1. `/admin/upgrade`, signed in during maintenance, writes about 10 `tblErrors` rows (`htmlspecialchars` is given an
     array at `templates/header.php:298`). The round-3 fixer is asked for the cause.
  2. A signed-in global administrator skips maintenance mode entirely, so opening `/cron/retention-sweep?token=` in a
     signed-in browser runs the clear-out during an upgrade.
- The pkg6b Codex brief still waits for this round, and must include `Maintenance.php`, `cron/health.php` and whatever
  this round changes.

**About 13:20 — `3a1e437` (pkg3, #494) and `dc193b1` (forged, #496) COMMITTED AND PUSHED. CODEX LIMITED UNTIL 17:37.**
- Both were proven first on a throwaway worktree: HEAD, then HEAD + pkg3, then HEAD + pkg3 + forged. Every audit
  check and self-test exited 0, and `php -l` was clean. The re-check of each file against its reviewed diff passed
  before staging. #494 and #496 have been commented on. (`Logger::criticalAlert` exists only in a comment in `Sms.php`,
  and `RateLimiter::DEFAULT` is a constant; neither is a fault.)
- **Queue 5 hit Codex's usage limit at 13:00** during `smallfaults-r1`; the reset is at 17:37. The file
  `codex-smallfaults-r1.txt` is INCOMPLETE and is not a review. Before stopping, it found one real point: with
  two-step verification, `LoginLocal` is logged BEFORE the second step, and SSO logs `...Pending2fa` then
  `TotpVerified`. So `monthly_logins` misses some completed sign-ins and counts unfinished ones.
- **Queue 6 waiter:** background task `beosopa1j`, log `.claude-work/codex-queue-6.log`. At 17:39 it runs the NAME
  BRIEF pairs listed in `.claude-work/codex-queue-6.list`, one pair per line (for example
  `codex-smallfaults-r2 brief-smallfaults-r2.txt`). **Add each line as its brief is written.** If the session dies,
  run `bash -c 'set -f; bash .claude-work/codex-queue.sh $(cat .claude-work/codex-queue-6.list)'` by hand after
  17:37.
- **New issue #507:** the service worker keeps signed-in pages readable offline after sign-out (Codex 503-r1 P1). It
  affects EVERY signed-in page, not just drafts, and is pre-existing.
- **Workflow `codex-fixes-r3` launched** (run `wf_8cfaf941-503`, task `wgiwgeg6q`; containers `lane1-mysql`,
  `lane2-mysql`, `lane3-mysql`; ports 9000-9029). It runs three lanes, each running two packages one after the other:
  - lane 1: 503-r2 (the event.php URL prefix from the catch-up review, plus the Site.php comment), then the
    offline-cache fix;
  - lane 2: 503b-r2 (the invitation link's organisation check), then smallfaults-r2 (counting sign-ins that use
    two-step verification);
  - lane 3: 498-r3 (fingerprint columns, and the catalogue's handling of register rows), then sqlcols-r3 (three false
    positives).

  No lane may touch pkg6b's files. `pkg6b-maintenance-health` is still running.

**13:00 — CODEX QUEUE 4 FINISHED: all 9 reviews are real answers. Queue 5 started at 12:59 (smallfaults-r1 first).**

| Review | Verdict | What next |
| --- | --- | --- |
| pkg6-catchup | NOT CLEAN | event.php search-engine URL loses the organisation prefix, and a Site.php comment (already fixed). Fold both into the 503 round 2 package. |
| 501-r2 | CLEAN | Committed `6c8712b`. |
| forged-r3 | CLEAN | Commit after proving it on HEAD alone (Auth.php is in it, so commit BEFORE any fix touches Auth.php). |
| pkg3-r4 | CLEAN | Commit after proving it on HEAD alone (tools and pr-security.yml only). |
| followups-498-r1 | CLEAN | **HOLD until 498 is clean.** It removes references to `demo_data.sql`, and only the 498 package deletes that file. |
| 503-r1 | NOT CLEAN | P1: the service worker caches the draft event page a manager saw, so after sign-out it can still be opened offline in the same browser. Check whether every signed-in page has this problem. |
| 498-r2 | NOT CLEAN | HIGH: the fingerprint leaves out displayAddress, displayBio, displayPhone, photos and coordinates, so Wipe could delete real details added to a demo person. MEDIUM: the catalogue says tblDemoDataRegister holds no personal data, but tableName+rowID identifies a user, so it needs conditional export and erasure. LOW: the GitHub issue link. **Decline again with the reason** (a code-repository link is not a customer address). |
| sqlcols-r2 | NOT CLEAN | Three P2 false positives: SQL comments (`--` and `#`), a JOIN alias named like `tblX` treated as a table, and a number like `1e3` read as a column. |
| 503b-r1 | NOT CLEAN | P1: the rsvp-by-link lookup has no organisation check, and its draft test uses `App::isAdmin()` for the CURRENT organisation, so an admin of A holding B's token can open B's draft. Take care: guests have no session, and Site::id() for a signed-out visitor may detect site 1 (#502). Checking admin rights for the EVENT'S organisation may be safer than filtering by Site::id(). |

**About 13:15 — `6c8712b` (#501: dashboard and task list) COMMITTED AND PUSHED; small faults queued for Codex; catch-up plan**
- Codex 501-r2 was CLEAN (a real answer; short because round 2 only covered the API description). The three files
  matched `501.diff` exactly, and #501 has been commented on.
- **The `data.php:40-42` comment is corrected** (comment only). `smallfaults.diff` (3 files) and
  `brief-smallfaults-r1.txt` are written.
- **Codex catch-up for `eb077e1`, which came back NOT CLEAN; both points were checked against the code and both are real:**
  1. `calendar/event.php:320-322` builds the search-engine markup's event address from `HTTP_HOST` +
     `/calendar/event?slug=`, so in path mode an organisation's prefix is lost. The fix is `Site::url('calendar/event')`
     (the hero image address is a static file in the web root, so it needs no prefix). **Wait for Codex `503-r1` and
     commit #503 part 1 first, because `event.php` is in that review.**
  2. The `Site.php` flagIsOn docblock overstated the fault. **Already corrected** (comment only, uncommitted): only the
     two admin-flag methods said "no" for everybody, while userBelongsTo only refused a global admin without a
     membership row.

  Together these become the **catch-up-fixes** package. Write `brief-catchup-fixes-r1.txt` and `catchup-fixes.diff`
  (Site.php plus event.php's URL lines) once (1) is done.
- **Waiter 5** (background task `bwn8anpmh`; log `.claude-work/codex-queue-5.log`) polls
  until queue 4 prints "queue ended", then runs `codex-smallfaults-r1`, `codex-pkg6b-r1` and
  `codex-catchup-fixes-r1`. A brief that does not exist yet is REFUSED and skipped. If so, run it by hand:
  `bash .claude-work/codex-queue.sh codex-pkg6b-r1 brief-pkg6b-r1.txt` (and likewise for the others).
- **Workflow `pkg6b-maintenance-health`:** run `wf_784bf1f6-53c`, task `wevwkqvw1`.

**About 13:00 — `resume-builds` FINISHED. Both chains are verified. Reports: `.claude-work/resume/rb--*.md`.**
- **pkg6b: verify round 2 PASS**, with no new gaps. The follow-up for maintenance mode (owner decision 2) is launched
  as workflow `pkg6b-maintenance-health` (Opus build and verify; container `maint-mysql`, ports 8990-8999). It edits
  `Maintenance.php`, the three cron job headers, the note on the staff health page, and the DEV_NOTES row. **The
  pkg6b Codex brief waits until that is verified,** and it must include `Maintenance.php`. One stale doc was found:
  HANDOFF line ~702 still describes `?cron=1`, and needs refreshing.
- **Small faults: verify round 1 FAILED, then a fix, then verify round 2 PASSED.** Changes over and above the four
  column fixes:
  - `reports/data.php` `attendance_monthly` now joins the headcount table as well;
  - both attendance queries now leave out deleted sessions (`s.isDeleted = 0`), which the attendance page already
    did;
  - `monthly_logins` now counts `LoginLocal`, `LoginMS365`, `LoginGoogle` and `LoginWebAuthn`. It used to look for
    `Login`, which nothing ever writes.

  Still to do before its Codex brief: fix the old comment at `data.php:40-42` (it claims `prepare()` fails silently;
  under strict reporting that is a 500), which is comment-only. Not faults, but follow-ups:
  - nothing in the portal calls `/admin/reports/data`;
  - the Top Activity list has no tie-break.
- **Codex queue 4:** `pkg6-catchup` completed at 12:40 and is **NOT CLEAN**:
  - **P2:** `calendar/event.php:266-268` builds the search-engine markup's event address as
    `/calendar/event?...`, without the organisation's address prefix (path mode). Codex suggests `Site::url()`.
  - A comment: `Site.php:717` overstates the fault.

  Being checked against the code now. `event.php` is also in the 503 package, which is 6th in the Codex queue, so do
  not edit it underneath that review.

**Done at about 12:30:**
- Both decisions are posted on #503 and #497.
- The decision is recorded in the comment in `rsvp-by-link.php`, which was a comment-only edit; `php -l` is clean.
- `503b.diff` is regenerated (still 9 files, and the only difference is that comment).
- The "known, do not report" note in `brief-503b-r1.txt` now states the decision.

**Lesson:** a background waiter lives only as long as the session that started it. After any stop, run `ps` for
`codex-queue` before assuming a scheduled queue will run.

### 14 September ~09:30 — sqlcols and #503 part 2 VERIFIED; Codex briefs written; one owner question asked

- **Workflow `sqlcols-r2-and-503b` (run `wf_fb54cc29-20f`) finished.**
  - **check_sql_columns.py:** verify FAIL, fix, FAIL, fix, **PASS on round 3.** It now scans single-table bare-column
    WHERE comparisons in SELECT, UPDATE and DELETE; skips sub-queries; reads only the WHERE clause itself; ignores PHP
    `$variables`; prints honest coverage; and lists eight blind spots. The one remaining false-accusation shape is a
    sub-query kept in its own PHP variable.
    - **It found 2 REAL faults:** `admin/calendar/coordinators-save.php:72` uses `tblUsers.email` (should be
      `emailAddress`), and `admin/reports/index.php:121` uses `tblActivityLogs.createdAt` (should be `timestamp`);
      `reports/index.php:91` may be the same but is invisible to the check.
    - Added to #501; **workflow `small-faults-sql-columns-2` is fixing them** (Sonnet build, Opus verify).
  - **#503 part 2: verify PASS on round 1.** 9 files:
    web/_apps/calendar/api/hub-resources.php, web/_apps/calendar/api/hub-videos.php, web/_apps/calendar/event-register-save.php, web/_apps/calendar/event-register.php, web/_apps/calendar/feed.php, web/_apps/calendar/rsvp-by-link.php, web/_apps/calendar/rsvp.php, web/_apps/events/api/detail.php, web/_apps/live/index.php.
    - API keys see drafts only with `events:write`; for a key request the session is ignored.
    - Internal events now need sign-in to register (public-event registration is deliberate and unchanged).
    - The feed excludes drafts for everyone, including managers.
- `503b.diff` and `sqlcols.diff` were written; `brief-503b-r1.txt` and `brief-sqlcols-r2.txt` are written, so waiter 3
  picks them up at 12:37. (The first attempt at the 503b file list failed to parse and was rebuilt from git status.)
- **OWNER QUESTION asked:** may an RSVP-by-link invitation (sent to a named email by an administrator or coordinator)
  let a guest answer for an INTERNAL event without an account? Proposal #335 said public events only; the shipped code
  never enforced it; the builder left it as it is.
- **Follow-ups (not fixed; for an issue or the sweep):**
  1. The draft-visibility rule is now copied in about 11 files; a shared helper, for example on `Portal\Core\Events`,
     is recommended.
  2. `calendar/anon-checkin.php` (`/attend?eventID=N`) shows an INTERNAL published event's name signed out.
  3. The `web/_core/Ical.php:90,99` comment is stale (the feed now sends STATUS).
  4. The feed now refuses a user with no active membership, so a global administrator with no membership row gets 403.
     Check this against the `Site::userBelongsTo` rule (global admins belong everywhere).
  5. Hub APIs show drafts only to App::isAdmin or an `events:write` key, not to coordinators.
  6. Nothing in the portal ever turns `registrationEnabled` on, so the registration form is reachable only after a
     direct database edit.
  7. Test note: `event-register-save.php:147` gives a 500 after saving when no mail sender is configured (pre-existing).

### 14 September ~08:55 — Newsletter follow-up DONE by the main session; #498 wording tidied; diffs regenerated

- `web/_core/Newsletter.php`: the demo exclusion is now
  `NOT IN (SELECT rowID FROM tblDemoDataRegister WHERE tableName = ? AND siteID = ?)`, bound
  `'isii', $siteId, $demoTableName, $siteId, $count`. The comment says a changed demo announcement stays out of
  newsletters until its register entry is removed (the safe direction).
- `demo-data.php`: the header's fingerprint column list names the phone number, and the Wipe messages read correctly
  for a count of one. These were the verifier's two minor points.
- `php -l` is clean on both; all audit checks and self-tests exit 0. `498.diff` and `followups-498.diff` were
  regenerated, and both briefs note these edits, for the 12:37 Codex queue.

### 14 September ~08:40 — `fix-498-r2` finished; pkg6b launched; a Newsletter follow-up is needed

- **#498 round 2 (run `wf_1a0127b5-0bb`): built and verified.**
  - `tblDemoDataRegister.checkValue` is replaced by `siteID` plus `fingerprint CHAR(64)`: SHA-256 over the table,
    the row id, and length-prefixed values of the columns Load set, including the creation time.
  - Wipe deletes only rows still in the recorded organisation with a matching fingerprint, and lists changed rows
    "left in place". Demo rows linked to a kept row stay with it. Both Codex failure shapes were reproduced before and
    shown fixed after.
  - Migration 194 and its full_schema block were updated in place and are identical; they replay twice on MySQL
    8.0.36 and run twice on MariaDB 11.4.
  - `498.diff` was regenerated and `brief-498-r2.txt` written, so waiter 3 will pick it up at 12:37.
  - Known limits:
    - there is no page control to take a kept row off the register, so Load stays blocked until it is removed by hand
      (the page says so);
    - an undeclared link is documented, not detected.
- **NEW FOLLOW-UP:** `web/_core/Newsletter.php` (followups-498 package, uncommitted, awaiting Codex) excludes register
  rows by number only. It must also match `siteID`. A kept, now-real announcement still being left out of newsletters
  until its register entry is removed is acceptable and should be documented. Fix it before the followups-498 Codex
  review at 12:37, then regenerate `followups-498.diff` and add a note to its brief.
- **Workflow `pkg6b-router-and-cron` launched** (Opus build, Opus verify, up to 3 rounds): cron addresses for
  retention, health and backup-check; the `Router.php:83` fix; removal of the stray calendar photo route; migration
  196. `full_schema.sql` route seeds only; the 194 block is left alone.
- **Running now:** the sweep (Fable), `sqlcols-r2-and-503b` (2 Opus chains), `pkg6b` (Opus). The Codex queue is at
  12:37.

### LATEST — 14 September ~08:05: two packages COMMITTED; #498 in a fix round; Codex limited again until 12:35

**Codex queue 1 (07:35):**
- pkg1-r3 **CLEAN**, pkg2-r3 **CLEAN**, 498-r1 **NOT CLEAN**;
- pkg6-catchup hit the Codex usage limit at 07:49 (Codex says "try again at 12:35 PM");
- another Codex session on this machine (`codex exec -m gpt-5.6-sol ... codex-review-4b.log`) appears to be drawing on
  the same allowance.

Queue 2 (waiter 2) ran after it and stopped at its first review on the same limit; see its log.

**COMMITTED AND PUSHED:**
- **`e414cf1`: pkg2 (#495),** 23 files. All 22 portal-wide settings pages are global-admin only; backup.php,
  retention.php page and sweep, and offsite-backup.php's "Run now" are all global-admin only; apps, QR and Sabbath
  check the token once; the QR key is kept and encrypted. Commented on #495.
- **`4eba0ee`: pkg1 (#493 Step 1 and #496's foundation):**
  - RateLimiter trusted proxies, Gatekeeper, `PORTAL_ENV_SOURCE`, the index.php gate call;
  - settings save, index and group protections, and AppRegistry;
  - migration 193 plus ONLY its full_schema block.

  The `full_schema.sql` split was done by staging an intermediate copy with `git hash-object -w` and
  `git update-index --cacheinfo`. **The 21-line 194 `tblDemoDataRegister` block is still UNCOMMITTED** in the working
  tree, and belongs to #498. Commented on #493 and #496.
  - Checked before committing: no pkg1 file uses Logger's uncommitted `errorPlatformForSite`. That method stays with
    the forged package.

**#498 Codex round 1 finding (HIGH):** Wipe trusts one check value (email, number or slug). An announcement edited
through the API with its slug kept, or a reused number with the same slug in another organisation, would be deleted.
- **Workflow `fix-498-r2` running:** it records organisation and a content fingerprint at Load, and Wipe deletes only
  rows still exactly as loaded, listing changed ones as "left in place". It edits `demo-data.php`, migration 194, the
  full_schema 194 block and the catalogue entry.
- **Declined Codex point, with the reason:** GitHub issue links in code comments are not "built-in addresses" under
  the #500 rule. That rule is about the customer's portal and our deployment domains; `@link` references to the code
  repository are fine.

**Waiter 3 (background)** starts at 12:37 and queues every brief that exists by then, in order: pkg6-catchup, 501-r2,
forged-r3, pkg3-r4, followups-498-r1, 503-r1, 498-r2, sqlcols-r2, 503b-r1.
**Write `brief-498-r2.txt`, `brief-sqlcols-r2.txt` and `brief-503b-r1.txt` when those workflows finish verified.**

**Waiting (file clashes):**
- pkg6b (Router, cron moves, photo route) launches AFTER `fix-498-r2` finishes, because both edit full_schema.sql;
- pkg5 (secret settings build) after #498 and pkg6b are committed.

**Running now:** the issue sweep (Fable), `sqlcols-r2-and-503b` (two Opus chains in parallel), `fix-498-r2` (Opus).

### LATEST — 14 September ~04:00: plan-6 answered; #503 verified; the sweep RUNNING; sqlcols round 2 and #503 part 2 RUNNING

- **Owner answers to plan-6** (recorded at the end of `.claude/plans/public-door-6-configurable-domains-and-package.md`):
  1. **KEEP** the placeholder folders (NOT the recommendation): our address in `public_html_redir/index.html` becomes
     `https://portal.example.org/` with a placeholder comment; the package drops the folders; no allow-list entry.
  2. **YES** to CDN-fallback copies in the zip.
  3. **YES**, edit migration 062, recording the exception in its header; migration 197 fixes existing installs.
- **`build-503-and-sqlcols` (run `wf_e49ab8aa-2d3`) finished.**
  - **#503 part 1: verify PASS.** `calendar/event.php` returns the same "not found" for a draft unless the viewer passes
    `App::isAdmin()` (the calendar manage-page check), with a draft notice for managers. The `export.php` single-event
    branch gets the same rule plus sign-in for non-public events; before, ANY draft or internal event was downloadable
    signed out by counting ids.
  - `503.diff` and `brief-503-r1.txt` are written, so waiter 2 will queue it.
  - **sqlcols: verify FAIL, then fix, then re-verify FAIL.** Open: a sub-query inside UPDATE or DELETE is mis-scanned by
    the SELECT loop (a medium false-accusation risk), and the header omits a blind spot (a quoted value before FROM).
    **No Codex brief yet.**
- **Workflow `sqlcols-r2-and-503b` launched** (the two run in parallel; files do not overlap):
  - sqlcols round 2: Opus fix and verify, up to 3 rounds;
  - #503 part 2: drafts and deleted events via `events/api/detail.php`, `calendar/rsvp.php`, `calendar/feed.php` (also
    deleted events), `rsvp-by-link.php`, event registration (internal events without sign-in; check first whether it is
    deliberate), `calendar/api/hub-resources.php` and `hub-videos.php`, and `live/index.php`. Opus build and verify, up
    to 3 rounds.
  - **Deliberately left out:** `export.php`'s series branch, which does not check isPublic. Its file is in the part-1
    Codex review; do it after that commit.
- **Workflow `issue-sweep-and-proposals` launched** (run `wf_e4c43146-809`):
  - gather (Sonnet); then sequential Fable batches of 45 issues; an adversarial double-check; project state; featurefind
    research writing `.dev-team/FEATURES.md`; ranked proposals written to `.claude-work/sweep/proposals.md` and
    `.claude/plans/alpha-proposals.md`.
  - It writes NOTHING to GitHub: apply the double-checked changes afterwards.
  - It is the only analysis run active.
- **Claude chains now:** the sweep, sqlcols-r2 and 503b (3). Codex queue 1 starts at 07:35, and waiter 2 runs after it
  (currently with briefs forged-r3, pkg3-r4, followups-498-r1 and 503-r1).

### 14 September ~03:45 — plan revision r2 FINISHED; 3 owner decisions asked; the sweep is next

- **`plan-amend-channels-r2` (run `wf_9867eb01-c8f`) finished; all four stages on Fable.** Outputs:
  `.claude-work/reviews/plan-amend2-{1-survey,2-design,3-challenge,4-revision}.md` and
  `.claude/plans/public-door-6-configurable-domains-and-package.md` (119 KB, identical copies).
  - Sections: what it replaces; every built-in address and what it becomes; the portal's own address; the channel
    for a package install; hybrid hosting in the deploy; the installation package; replacement text for the amendment;
    docs; build placement; design choices; what was not checked.
  - Migration **197** fixes the support-email seed on existing installs.
  - **3 OWNER DECISIONS asked 14 September:**
    1. delete the placeholder folders `public_html_redir`, `public_html_landing` and `private_html`;
    2. ship CDN-fallback copies in the zip;
    3. edit old migration 062 to remove our support address.
- **The sweep brief was updated** with the in-progress issues (#479 and #491-#503), the facts found this week, the
  featurefind method writing `.dev-team/FEATURES.md` (sequential slices, never the top-level FEATURES.md), and outputs
  in `.claude-work/sweep/`.
- **NEXT:** launch the sweep workflow (sequential Fable batches, a double-check, project state, feature research,
  then proposals). It is the only analysis run active; `build-503-and-sqlcols` is a build and may overlap.

### 14 September ~03:40 — `review-fixes-round3` FINISHED: all four packages verified

- **Built and verify PASS (no fix rounds needed):**
  - pkg1-r3: settings and proxy;
  - pkg2-r3: retention and off-site page gates;
  - forged-r2: Logger never throws, loops or needs the database;
  - pkg3-r3: the method-call checker's three Codex round 2 findings.
- `pkg3.diff` was regenerated and `brief-pkg3-r4.txt` written. Waiter 2 now has briefs for forged-r3, pkg3-r4 and
  followups-498-r1; 503 and sqlcols come when their workflow finishes.
- Claude chains running now: `plan-amend-channels-r2` (Fable) and `build-503-and-sqlcols`. Codex queue 1 is at 07:35,
  queue 2 after it.

### 14 September ~03:30 — `followups-498` finished; its Codex brief is written for queue 2

- **Build and verify PASS.** Changed: `Migrator.php` (the user-visible message and comments no longer name the deleted
  `demo_data.sql`), dead exclusions removed from `check_migration_idempotency.py` and `check_mariadb_only_ddl.py`, and
  a reference in `tools/e2e-migrations/run.sh`. Both checks give identical findings, and the e2e harness passes all 4
  phases.
- **`Newsletter.php`** leaves out announcements in `tblDemoDataRegister`, checks for the table with `SHOW TABLES LIKE`,
  and treats a missing table as none; genuine database faults still surface. Proven with the table present, empty and
  dropped.
- `followups-498.diff` and `brief-followups-498-r1.txt` are written, so waiter 2 will queue it. The brief asks Codex
  about LIKE wildcards in `SHOW TABLES LIKE 'tblDemoDataRegister'` (no `_` or `%` in the name, but check).
- **New follow-up found (not fixed):** `web/_apps/announcements/api/list.php` (`/api/announcements` and
  `/api/v1/announcements`, API-key reachable) also returns [DEMO] announcements while demo data is loaded.

### LATEST — 14 September ~03:10: Claude credits back; Codex still limited until 07:33

- **Codex was checked with a one-word test prompt at 03:05:** still limited, "try again at 7:33 AM". Waiter 1
  (python, PID 97544) starts queue 1 at about 07:35: pkg1-r3, pkg2-r3, 498-r1, pkg6-catchup, 501-r2.
- **Waiter 2 (background)** waits for queue 1's "queue ended" line, then runs `codex-queue.sh` for every brief that
  exists by then, in this order:
  1. `brief-forged-r3.txt` (WRITTEN: Logger round 2 done, verify PASS; `forged.diff` regenerated);
  2. `brief-pkg3-r4.txt`;
  3. `brief-followups-498-r1.txt`;
  4. `brief-503-r1.txt`;
  5. `brief-sqlcols-r1.txt`.

  **Write each of those briefs when its build and verify finish.** A brief missing at that moment is skipped, and
  must then be run by hand.
- **State of the Claude runs at 03:04:**
  - `review-fixes-round3`: pkg1-r3 PASS, pkg2-r3 PASS, forged-r2 PASS (Logger), pkg3-r3 built with its verify
    running;
  - `plan-amend-channels-r2`: survey and design saved, challenge being written, revision next;
  - `followups-498`: built, verify running.
- **New workflow `build-503-and-sqlcols`,** sequential. Both files are outside every review set:
  1. #503: draft events visible only to those allowed to manage events, using the same permission as the calendar
     manage pages. Opus build, Opus verify. File: `calendar/event.php` (plus `export.php` only if it serves a single
     draft).
  2. `check_sql_columns.py` reads SELECT and UPDATE WHERE clauses: zero false positives, coverage printed, and both
     historical faults caught if possible. Sonnet build, Opus verify.
- **Frozen until reviewed and committed** (do not edit):
  - the pkg1, pkg2, 498, 501 and forged file sets;
  - the pkg3 tools and pr-security.yml;
  - the followups-498 files (Migrator, Newsletter, two check scripts, e2e run.sh).

  Also, `eb077e1`'s files are being catch-up reviewed from the COMMITTED diff. Editing `calendar/event.php` for #503
  does not change what Codex reviews, because the brief uses `pkg6-commit.diff`.

### 14 September ~03:25 — CODEX USAGE LIMIT again at 02:51; the five code reviews are scheduled for its reset

- The v2 queue started correctly (the real brief reached Codex) but hit **"You've hit your usage limit"** on the first
  review at 02:51. It stopped, as designed. **Still NO Codex review exists** for pkg1-r3, pkg2-r3, 498-r1, pkg6-catchup
  or 501-r2.
- The stale broken files were moved aside as `codex-*.FAILED-HHMM.txt`, so none can be mistaken for a review.
- **Codex said "try again at 7:33 AM", so the waiter will start the queue at about 07:35 on 14 September.** Its
  log stays empty until then, because Python holds its messages back while it waits; that is expected. If nothing
  has happened by 07:45, run the script by hand.
- **A background waiter reads the reset time from Codex's own message, waits until 2 minutes after it, then runs
  `bash .claude-work/codex-queue.sh`** for the same five, in the same order. If it cannot read the time, it says so
  and schedules nothing: then run the script by hand after the reset.
- Under the fallback rule nothing is committed on a stand-in review this time. The stand-in reviews cost about 1.2M
  Claude tokens per package and helped trigger the Claude limit. Codex is the reviewer; the wait is recorded here.
- Claude work continues meanwhile, capped at about 3 chains: `review-fixes-round3` (forged-r2, pkg3-r3),
  `plan-amend-channels-r2`, `followups-498`.

### 14 September ~03:15 — the 02:44 Codex queue sent EMPTY prompts; script v2; code reviews re-queued

- **The zsh trap struck again, inside the background queue:** `set -- $pair` does not split in zsh. All five "reviews"
  were `codex exec` with an EMPTY prompt, written to files named like `codex-pkg1-r3 brief-pkg1-r3.txt.txt`, and the
  v1 completeness check called them COMPLETE. **Those five files were deleted. No review of pkg1-r3, pkg2-r3, 498-r1,
  pkg6-catchup or 501-r2 exists yet.** `501.diff` had not been regenerated either; it now has been, and includes the
  spec.
- **`.claude-work/codex-queue.sh` is now VERSION 2:**
  - it refuses a missing or empty brief;
  - it adds a footer asking for a final CLEAN / NOT CLEAN line;
  - a review counts only if its final answer is at least 300 characters and contains CLEAN, plus all the v1 checks
    (limit, capacity, last `codex` after last `exec`, `tokens used`);
  - retry and fallback model as before.

  **ALWAYS run it as `bash .claude-work/codex-queue.sh NAME BRIEF ...` with separate arguments.**
- **Re-queued now, via the script:** pkg1-r3, pkg2-r3, 498-r1, pkg6-catchup, 501-r2.
- Memory updated: `zsh-does-not-split-variables.md` and `codex-limit-looks-like-success.md`.

### 14 September ~03:10 — docs commit `89ad68d` confirmed on GitHub; #498 follow-ups started

- **`89ad68d` pushed and verified:** local HEAD equals `origin/claude/alpha-wip`. Commented on #493, #479, #497 and #500.
- **Workflow `followups-498` launched** (Sonnet build, Opus verify). It deliberately touches ONLY files that are not
  under review: `web/_core/Migrator.php`, the two check scripts, `tools/e2e-migrations/run.sh`, and
  `web/_core/Newsletter.php`.
  1. It removes stale references to the deleted `demo_data.sql`, including the user-visible Migrator message.
  2. Newsletters never include announcements recorded in `tblDemoDataRegister`, and this must not break when that
     table does not exist yet (code uploaded before the upgrade).
- **Held back, because their files are under Codex review right now:**
  - `escapeshellcmd` on the off-site run handler;
  - the broken "← Maintenance" link;
  - the stale comment in `retention.php`;
  - #503 (calendar/event.php, part of the catch-up review);
  - #502 (bootstrap.php, part of pkg1).
- **Claude agent chains running now (about 3, per the concurrency note):** `review-fixes-round3` (forged-r2, pkg3-r3),
  `plan-amend-channels-r2`, `followups-498`. The Codex queue is separate.

### 14 September ~03:05 — docs Codex round 4: CLEAN; docs, config and plans committed and pushed

- `codex-docs-channels-r4.txt` passes the full completeness check (last `codex` after last `exec`, `tokens used`,
  no capacity or ERROR lines) and says **CLEAN**. It covers `.dev-team/config.yml` and the corrections doc's owner
  decisions.
- **Committed and pushed in one docs commit:**
  - `.dev-team/config.yml` and `.dev-team/.gitignore`;
  - `.claude/plans/public-door-4-fable-3-corrections.md`;
  - `.claude/plans/public-door-5-channels-amendment.md`, with the owner's 10 answers;
  - `.claude/plans/data-download-479-{1-survey,2-design,3-challenge,4-plan}.md`, with the owner's 10 answers;
  - `.claude/plans/secret-settings-497-design.md`, with the owner's 2 answers;
  - `.claude/CLAUDE.md`, with the new standing rule "No web address is ever built in";
  - this handoff.

  The commit message states which of these Codex reviewed.
- The Codex code-review queue is still running: pkg1-r3, pkg2-r3, 498-r1, pkg6-catchup, 501-r2.

### 14 September ~02:55 — Codex queue mostly FAILED ("model at capacity"); re-queued with a proper completeness check

- **The 02:33 queue "finished" in 9 minutes, but only `codex-501-r1.txt` is a real review.**
  `codex-pkg1-r3`, `codex-pkg2-r3`, `codex-498-r1` and `codex-pkg6-catchup` each died with "ERROR: Selected model is at
  capacity" and exit 0. The old check (`^codex$` present) passed them. That is WRONG: the marker appears before every
  progress message, and inside re-read old reviews. `codex-pkg2-r3.txt` "looked" like a review only because Codex had
  cat'ed the r2b file. **Do not act on any of those four files.**
- **#501 Codex round 1: no code defect; NOT CLEAN only because of the API description.** Fixed in
  `web/_core/api-spec.json` exactly as Codex suggested, still valid JSON:
  - summaries and descriptions for `GET /api/tasks/list` and `GET /api/v1/tasks`;
  - both `userID` descriptions;
  - csrfToken removed from the v1 GET session alternative;
  - a security override added on the legacy GET. Confirmed first that `ApiAuth::requireRead()` does not check CSRF.
  - Other GET operations still listing csrfToken are listed in `.claude-work/spec-get-csrf-list.txt`, for the docs
    pass.
- **New Codex queue (background).** It waits for the docs r4 run to end, then:
  - re-runs docs r4 if that was incomplete;
  - then pkg1-r3, pkg2-r3, 498-r1, pkg6-catchup, and 501-r2 (with a regenerated `501.diff` that includes the spec).
  - It is ONE AT A TIME. A review counts only if `complete()` passes: no limit text, no capacity or ERROR in the last
    lines, the last `codex` line after the last `exec` line, and `tokens used` present.
  - "At capacity" means wait 5 minutes and retry, up to 5 attempts; attempts 4 and 5 use `-m gpt-5.6-sol`, and the
    model used is logged.
  - A usage limit stops the queue.
  - Failed attempts are kept as `*.attemptN.txt`.
- Memory `codex-limit-looks-like-success.md` updated with the capacity lesson and the completeness rule.
- **Reusable script saved:** `.claude-work/codex-queue.sh` (syntax checked). Run it as
  `bash .claude-work/codex-queue.sh NAME1 BRIEF1 NAME2 BRIEF2 ...` from the repo root. It has the same `complete()` check,
  the retry, the fallback model and stop-on-limit. Use it for every future Codex review instead of hand-written loops.

### 14 September ~02:40 — docs Codex round 3: NOT CLEAN (3 wording points), fixed; round 4 queued

`codex-docs-channels-r3b.txt` found three points, each verified against the plugin's hook scripts (updated since our
first read) and bootstrap.php:
1. The shell guard's `auto` mode is active when `.dev-team/autopilot.json` EXISTS, or `DEV_TEAM_GUARD` is set, not
   only "while a run is going".
2. Rule 1 looks at the first THREE lines of a Write, and its names now include `readme.md`. `.claude/HANDOFF.md` is
   outside rule 2 but INSIDE rule 1.
3. The folder warning needed "provided no parent folder already matches an earlier name".

All three are fixed in `.dev-team/config.yml` and the corrections doc. **Round 4 is queued**
(`codex-docs-channels-r4.txt`); it starts after the main Codex queue reports "queue ended".

### LATEST — 14 September ~02:25: CLAUDE LIMIT HIT AGAIN (reset 02:20); state re-established; Codex back at 02:31

**Committed and pushed before the limit:** `eb077e1`, the #497 comparisons (App.php plus the 9 files).
- App.php was Codex-reviewed.
- The 9 files were interim-reviewed by Fable.
- A Codex catch-up review is queued.

**What the limit killed, and what was done about it (fallback rule):**
- **`review-fixes-round3`:**
  - pkg1-r3 (settings/proxy): DONE, verify PASS;
  - pkg2-r3 (retention and off-site gates): DONE, verify PASS;
  - forged-r2 (Logger safety) and pkg3-r3 (checker): builds DIED.

  **Resumed** with `resumeFromRunId wf_46cf3fe6-d72` (the finished ones replay from cache).
  - pkg1-r3 changes, now uncommitted: bare `public` is reserved; NOBODY can create a value/group name clash (e.g.
    `portal`); a proxy entry with whitespace or a comma touching the slash is refused; a site administrator cannot
    update or delete a stored name with characters outside `A-Za-z0-9._-`.
  - Follow-ups (minor): the clash check is not atomic; older stored clashes need a loader change.
  - pkg2-r3: `retention.php` page and sweep are global-admin only (the cron path is unchanged); `offsite-backup.php`
    shows "Run now" to global admins only. Minor follow-up: a stale comment in `run_retention_sweep()` (~line 505).
- **Interim review of #501 (`wf_e65fa51c-c04`):** rounds 1-2 done, round 3 died.
  - **Its fixer went beyond the brief:** `tasks/api/list.php` now gives an API-KEY caller every open task in the
    key's organisation (or ?userID), mirroring `expenses/api/list.php`. Codex has been asked specifically whether
    that is right.
  - Follow-ups: `api-spec.json` now misdescribes tasks list; list rows carry no assignee or status; there is no
    isDeleted filter; `check_sql_columns.py` cannot see WHERE columns.
  - **NOT resumed; Codex takes over.**
- **Interim review of #498 (`wf_768fc165-a5e`):** died before round 1. **NOT resumed; Codex takes over.**
- **Plan revision r2 (`wf_9867eb01-c8f`):** survey saved (`plan-amend2-1-survey.md`, 45 KB); design, challenge and
  revision died. **Resumed** (survey cached, Fable retried first).
- **Codex docs review waiter:** still alive and due at 02:32 (`codex-docs-channels-r3b.txt`).

**Codex queue launched** (background; waits until 02:33; ONE AT A TIME; stops at a limit or a missing answer block).
Briefs are `.claude-work/reviews/brief-*.txt`; the diffs were regenerated.
1. `codex-pkg1-r3.txt`: `pkg1.diff`, now WITHOUT App.php;
2. `codex-pkg2-r3.txt`: `pkg2.diff`, now WITH retention.php;
3. `codex-498-r1.txt`: `498.diff`, demo data plus the off-site run handler;
4. `codex-501-r1.txt`: `501.diff`;
5. `codex-pkg6-catchup.txt`: `git show eb077e1` catch-up.

**Stand-in reviews STOP now that Codex is back.** Return to the usual reviewer promptly.

**Filed #503:** a draft event marked public (the default) is visible in full by direct link, because
`calendar/event.php` never checks the status. Commented on #497 with commit `eb077e1`.

**Order from here:**
1. Read each Codex review, fix, re-review until clean, then commit and push per package (pkg1, pkg2, #498 with the
   off-site handler, #501) and update issues.
2. pkg6b (Router + cron + photo route) launches after pkg2 is COMMITTED, because it edits retention.php.
3. pkg5 (secret settings build) after pkg1, #498 and pkg6b are committed.
4. forged-r2 and pkg3-r3 go to Codex when their builds finish.
5. Plan r2, then the sweep (sequential Fable), then the docs pass.

**Concurrency note:** Claude has hit its limit TWICE with 5 or 6 agents running. Keep at most about 3 Claude agent
chains going at once.

### 13 September ~23:45 — interim review of the #497 comparisons done; next is the commit

- **Workflow `interim-review-pkg6` (run `wf_5c438808-9d9`)** ran 4 rounds on Fable. Nothing is wrong in the code of
  the 9 files. Remaining items:
  - A Site.php comment overstated what the old code did in session mode. **Reworded by the main session.**
  - **The commit must include `web/_core/App.php`,** or the My Account badge would say "Root Admin" while the
    committed App.php still refuses that person. App.php's checks were already reviewed by CODEX in pkg1 r2b ("App.php
    permissions: correct", 200 checks), so it is safe to commit with these files.
  - **A pre-existing fault the review found, verified by the main session and filed as a new issue:** session-mode
    multi-organisation never works. `bootstrap.php:358` detects the site before `index.php:48` starts the session,
    and `detectFromSession()` needs an active session, so every request is organisation 1 and `/site/switch` does
    nothing. `site/switch.php:66` also ignores `Site::set()` returning false.
  - Also noted, pre-existing and NOT filed yet: a DRAFT event marked public is shown in full to anyone with its link
    (`calendar/event.php:74` checks only isPublic). **Verify it, then file it.**
- **NEXT:** mechanical checks, then **commit and push** `web/_core/App.php` plus the 9 files: Site.php,
  auth/account/index.php, tasks/index.php, admin/workflows/index.php, announcements/manage.php, calendar/event.php,
  calendar/manage/index.php, calendar/views/list.php, attendance/manage/index.php. **Router.php is NOT included.**
  - The message must say: App.php Codex-reviewed; the 9 files INTERIM-reviewed by Fable, 4 rounds, because Codex is
    out; a Codex catch-up is owed.
  - Then comment on #497.
- **Interim review of #498 plus the off-site trigger launched** (run `wf_768fc165-a5e`, reusable `interim-review` script).
- **COMMIT MECHANICS WARNING:** in `web/_sql/full_schema.sql`, the uncommitted migration 193 settings block (pkg1)
  and the 194 `tblDemoDataRegister` block (#498) sit in ONE diff hunk at line ~8659. To commit them separately, write
  a patch with only one block and `git apply --cached` it. Check with `git diff --cached` before committing.

### 13 September ~23:30 — off-site trigger and #498 demo data: BOTH VERIFIED

Workflow `maintenance-498` (run `wf_f9900c13-181`) finished; 6 agents.

- **Off-site "Run now" (`offsite-backup-run.php`).** Built (Sonnet), then verify FAIL on one major point: the message
  wrongly said "nothing has been logged". Fixed (Opus), then re-verify **PASS**.
  - Now: a non-administrator gets an immediate 403 as before. A site administrator or legacy administrator gets a
    403 page drawn by the handler itself, in backup.php's style; the script is not run and there is no sync-log row.
    A valid-token refusal is logged as `OffsiteBackupRunRefused`.
- **#498 demo data:** built (Opus), then verify **PASS**.
  - The page is for global administrators only, and the switch is read from the portal-wide row only.
  - Load uses database-assigned numbers, and records every created row in a new register table
    `tblDemoDataRegister (tableName, rowID, checkValue, createdAt)`, all in one transaction. It refuses when demo
    data is already loaded.
  - Wipe deletes only registered rows whose value still matches, and refuses (listing them) if a real row links to
    demo data through a declared foreign key. All or nothing.
  - `demo_data.sql` was DELETED and replaced by a PHP-driven load into the CURRENT organisation. Demo people are
    inactive and cannot sign in.
  - Migration 194 plus a full_schema hunk; the catalogue entry is `not-personal`.
  - Old demo rows (9000-9004) are never touched automatically, and the page explains how to recognise them. The old
    load could never have run on this schema anyway; the old WIPE was the real danger.
- **Follow-ups (not fixed; put into a small follow-up package or the sweep):**
  1. `Migrator.php:228` still shows a user-visible message naming the deleted `demo_data.sql`, plus comments at
     `Migrator.php:142-147` and `:221`, `check_migration_idempotency.py:117`, `check_mariadb_only_ddl.py:18` and
     `tools/e2e-migrations/run.sh:166`.
  2. **While demo data is loaded, a real newsletter's "latest announcements" (`Newsletter.php:348`) can include the
     [DEMO] announcements**, and real members see them in the portal. This is the one effect on real people. Default
     plan: exclude registered demo rows from newsletters.
  3. `offsite-backup-run.php` uses `escapeshellcmd()` on a path, which breaks when the hosting path contains spaces.
     Use `escapeshellarg()`.
  4. The "← Maintenance" link on demo-data, health and offsite-backup-run goes to `/admin/maintenance`, which is not
     a registered address.
  5. The stale header comment in `offsite-backup.php` says the run handler is unchanged.
  6. A non-administrator gets a bare 'Forbidden' on demo-data (matching backup.php).
- **Next:** one interim review (Fable) of the off-site file plus all the #498 files, then commit and push as the #498
  commit and comment on #498. **`maintenance-498` finishing unblocks pkg6b ONLY once `review-fixes-round3` also
  finishes.**

### 13 September ~23:15 — #497 secret-settings DESIGN FINISHED; plan revision r2 LAUNCHED

- **Design run `design-497-secret-settings` (run `wf_ec141e58-e3b`) finished: all three stages on Fable.**
  - Outputs: `.claude-work/reviews/pkg5-{1-investigation,2-challenge,3-design}.md` and
    `.claude/plans/secret-settings-497-design.md` (58 KB, identical).
  - Key PROVEN facts:
    - the loader has never decrypted (a prepared statement returns int 1);
    - fixing the comparison alone makes EVERY page fatal: the `auth.ms365.tenantOnly`='true' seed throws
      SodiumException, which nothing catches;
    - `tenantOnly` is read by NOTHING, so it can be deleted with no security effect;
    - all 13 dedicated admin pages encrypt, so their integrations get ciphertext today. That includes CAPTCHA,
      which would lock out 13 forms;
    - the generic `/settings` editor silently stores secrets as plain text AND turns the flag off. That is why
      Microsoft and Google sign-in, Graph mail and most cron tokens work at all today;
    - nothing double-decrypts once fixed.
  - The build plan has 10 steps: a new `SecretSettings` class; bootstrap helpers become wrappers; migration **195**
    plus full_schema; Captcha fails closed with a notice; captcha pages stop pre-filling secrets; the generic editor
    keeps secrets encrypted; `upgrade.php` repairs stored rows once; admin dashboard and health notices; a selftest
    plus a new `check_sensitive_seeds.py`; docs.
  - **2 OWNER DECISIONS ANSWERED 13 September** (recorded at the end of the design file):
    - (1) **Option B, NOT the recommendation:** with unreadable CAPTCHA keys, refuse the PUBLIC forms but ALLOW
      sign-in and password reset. So `Captcha::verify()` gets a which-form parameter at all 13 call sites, and the
      admin notice says sign-in is running without anti-spam.
    - (2) **Option A:** encrypt readable secrets automatically on the next Upgrade, and document backing up
      `enc.key` together with the database.
  - **The pkg5 BUILD must wait for:** `review-fixes-round3` (edits settings/save.php and index.php),
    `maintenance-498` (full_schema.sql), and pkg6b (edits health.php). Its steps touch all of those.
- **Plan revision r2 launched** (Workflow `plan-amend-channels-r2`, run `wf_9867eb01-c8f`, sequential Fable). It
  covers configurable addresses (#500), hybrid hosting and the zip package (#499). Outputs:
  `.claude-work/reviews/plan-amend2-{1-survey,2-design,3-challenge,4-revision}.md` and
  `.claude/plans/public-door-6-configurable-domains-and-package.md`. It is the only analysis run active; **the sweep
  waits for it.**

### 13 September ~23:00 — #501 items 1 and 2 FIXED and verified; interim review launched

- **Workflow `small-faults-dashboard-tasks` (run `wf_85ca6902-d96`): builder done, independent verify PASS.**
  - `dashboard/index.php`: `createdAt` → `` `timestamp` `` in the Activity (24h) widget.
  - `tasks/api/list.php`: `assignedUserID` → `assignedToID`.
  - Proven on MySQL 8.0.36: 500 errors before, 200 after, zero new error rows, and the counts match the database.
  - The other three tasks api handlers were already correct.
- **Confirmed checker gap for the sweep:** `check_sql_columns.py` never reads WHERE clauses in SELECT or UPDATE
  (only in one simple DELETE shape). Both faults were WHERE-clause columns.
- **Side note from the builder:** local testing with `PORTAL_ENV=dev` sends non-admins to the pre-release gate, so
  test role-based pages with the channel detected as prod (or give the test users a gate role).
- **A reusable Workflow `interim-review` was created and launched for these 2 files** (Fable reviewer, Sonnet fixer).
  Script (exact): `~/.claude/projects/-Users-lance-manasse-Projects-Coding---Development-MWBM-Partners-Ltd-GitHub-WebMS-Intra/19f94bec-c3e9-4f58-b234-28317b147f87/workflows/scripts/interim-review-wf_e65fa51c-c04.js` (run `wf_e65fa51c-c04`). Relaunch it for any package with args
  `{name, files, purpose, questions, fixerType}`.
- When clean: commit and push those 2 files as a #501 commit, noting the INTERIM Fable review and that a Codex
  catch-up is owed; comment on #501.

### 13 September ~22:40 — #501 filed; small fix running; the stray login file is gone

- **#501 filed** for three pre-existing faults, each confirmed in the code:
  1. `/dashboard` fails for every administrator: `dashboard/index.php:171-174` uses `createdAt` on `tblActivityLogs`,
     whose column is `timestamp`;
  2. `/api/tasks/list` gives a 500 error when signed in. The columns it selects all exist, so the cause is not yet
     traced;
  3. `calendar/views/photo` is a fragment registered as a route.

  **Checker gap:** `check_sql_columns.py` apparently does not read WHERE clauses. That goes to the sweep.
- **Workflow `small-faults-dashboard-tasks` launched** (run `wf_85ca6902-d96`; Sonnet build, Opus verify) for #501
  items 1 and 2.
- **#501 item 3 (the photo route) was added to the pkg6b brief,** in the same migration 196 as the cron moves.
- **The stray `web/_auth_keys/auth_creds.php` is gone**; `web/_auth_keys/` does not exist in the real tree.
  `.claude-work/briefs/common-builder.md` gained a rule: never create anything under `web/_auth_keys`, `_uploads` or
  `_backups` in the real tree; use a scratch copy.
- **Running now:**
  - `maintenance-498`;
  - `review-fixes-round3`;
  - `design-497-secret-settings` (Fable, the only analysis run);
  - `interim-review-pkg6`;
  - `small-faults-dashboard-tasks`;
  - the Codex docs review waiter, due at 02:32.
- **Waiting to launch:** pkg6b (after `review-fixes-round3` AND `maintenance-498`); the plan revision r2 (after the
  #497 design run); interim reviews and commits as packages finish; the Codex catch-up from 02:32.

### 13 September ~22:30 — #497 comparisons: 12 of 13 FIXED; the Router fix held back for a good reason

Workflow `pkg6-number-flags` (run `wf_6e5ac591-46d`) finished: build, verify (FAIL), fix, re-verify (FAIL). **Both
failures are ONLY the held-back Router item.**
- **Fixed and proven on MySQL 8.0.36 (uncommitted):** Site.php (userBelongsTo and userIsSiteAdmin/RootAdmin, with a
  private `flagIsOn`); the My Account badge; editing tasks, workflows and announcements no longer turns
  recurrence, active, pinned or published off; calendar featured badges, primary marker and search-engine markup;
  attendance greying.
- **`Router.php:83` NOT changed.** Three routes seeded protected are called by scheduled jobs with a token and no
  session: `admin/maintenance/retention`, `health` and `backup-check` (`?cron=1&token=`). The Router fix would
  silently stop them.
  **Decided (technical, not an owner question):** move those job modes to `cron/*` addresses, `isProtected=0`, as
  every other job already is, then fix the Router. Brief: `.claude-work/briefs/pkg6b-router-and-cron.md`; migration
  **196**. **Launch only after `review-fixes-round3` (edits retention.php) and `maintenance-498` (may edit
  full_schema.sql) have both finished.**
- **Confirmed OPEN to signed-out visitors today:** `expenses/treasury` (names, titles, amounts), `expenses/submit`,
  `expenses/approve`, `dashboard`, `attendance`, `help/support`.
- **Pre-existing faults found, not caused by this work, to be filed as one issue:**
  - `/dashboard` gives a 500 error for an administrator (a wrong column name);
  - `/api/tasks/list` gives a 500 error when signed in;
  - `calendar/views/photo` is a page fragment registered as a route (500 error).
- **Stray file:** an agent created `web/_auth_keys/auth_creds.php` in the REAL tree, pointing at its throwaway database
  container. It is gitignored, so it cannot be committed, but it changes what the real tree does. **Delete it once no
  build agent is running,** and add to `common-builder.md`: never create files under `web/_auth_keys/` in the real
  tree; use a scratch copy.
- **Interim review launched:** Workflow `interim-review-pkg6`. A Fable reviewer (Opus if Fable is refused) reviews
  the 9 changed files, with Opus fixing and Fable re-reviewing until clean. **Labelled INTERIM: Codex is out until
  02:31.** When clean: commit and push those 9 files as a #497 part commit, saying which model reviewed, and add them
  to the Codex catch-up queue.

### 13 September ~22:05 — Codex queue results; CODEX LIMITED AGAIN until 02:31 on 14 September

**Codex round 2 results** (each read in full, from the last `codex` line onwards):
- **pkg1 round 2b (`codex-pkg1-r2b.txt`): NOT CLEAN, 3 medium findings.**
  1. A site administrator can create the bare key `public`, which replaces the whole `public.*` group for their
     organisation through the loader's nesting.
  2. `192.0.2.1 /0` in `portal.trustedProxies` splits into a trusted `192.0.2.1`.
  3. An existing key with a combining accent (`públic.`) can slip past the UPDATE's LIKE test.

  Everything else passed: proxy chains (71 checks), Gatekeeper, App.php permissions (200 checks), group.php single
  CSRF check, AppRegistry, and migration 193 matching.
- **pkg2 round 2b (`codex-pkg2-r2b.txt`): the round 2 fixes PASS.** Three maintenance gaps remain:
  - [P1] `retention.php` sweep open to any administrator;
  - [P1] demo-data, already #498. Codex adds: `demo_data.sql:15-28` loads into ORGANISATION 1 whatever the caller's
    organisation. **Check this is handled when reviewing #498.**
  - [P2] `offsite-backup.php:288` shows "Run now" to site administrators.

  Codex's per-file view of which settings belong to each organisation is kept for the sweep; not acted on.
- **Forged-address round 1b (`codex-forged-r1b.txt`): all ten replacements CORRECT.** Two faults were already in
  Logger before this change:
  - `writeErrorRow()` can throw when the database is missing or failing (a TypeError at Logger.php:41);
  - alert mail failures can recurse (Logger.php:459 → Mailer → Logger).

  Side notes: an empty `REMOTE_ADDR` now gives '0.0.0.0' rather than ''; AssetRegister hash buckets restart for
  visitors whose recorded address text changes (harmless).
- **pkg3 round 2b (`codex-pkg3-r2b.txt`): NOT CLEAN, 3 medium findings.**
  1. `Holder::Site::ok()` and `$obj->Site::ok()` are read as a class `Site`.
  2. `...` inside a parameter's attribute marks the parameter optional.
  3. A crash message without a trailing newline glues the wrapper's '•' line onto it, so step 20's second grep
     drops it; also, exit 1 with a mid-line '•' skips the fallback.
- **Docs round 3: CODEX USAGE LIMIT, not reviewed.** Codex says try again at **02:31 on 14 September**.

**Launched: Workflow `review-fixes-round3`** (sequential, one package at a time). Briefs:
- `.claude-work/briefs/fix-pkg1-r3.md` (Opus): also covers the general "a single-word key hides a whole group"
  shape, e.g. `portal`;
- `fix-pkg2-r3.md` (Sonnet): retention page and sweep global-admin-only, cron path unchanged; the off-site page's
  "Run now" button global-admin-only;
- `fix-forged-r2.md` (Opus): Logger never throws, loops or needs the database; alerting re-entrancy guard;
- `fix-pkg3-r3.md` (Opus).

Each package is verified by Opus on a real database.

**THE PLAN WHILE CODEX IS LIMITED (fallback rule):**
- Keep building and fixing.
- When a package passes its independent verification, give it an **INTERIM review by a different Claude model
  (Fable), with no memory of building it, clearly labelled "interim — not the Codex review"**.
- Then commit and push, with the commit message saying Codex was unavailable, which model reviewed instead, and that
  a Codex catch-up is owed.
- From **02:32 on 14 September**, run a **Codex catch-up review of everything committed while it was away**, one
  package at a time, starting with docs r3.
- Codex findings are then fixed and re-reviewed as usual.

**Still running:**
- `pkg6-number-flags` (#497 comparisons);
- `maintenance-498` (off-site trigger, then #498);
- `design-497-secret-settings` (Fable design; the only analysis run active);
- `review-fixes-round3`.

### 13 September ~22:00 — the owner answered the re-plan's 10 decisions; the plan needs a revision

The answers are recorded at the end of `.claude/plans/public-door-5-channels-amendment.md` ("OWNER ANSWERS"). In short:
- **Recommended answers for 1, 5, 6, 7, 8, 9 and 10:** fail closed; no scheduled jobs on test copies; media through
  the portal; a fresh beta; `health` on the allow list; delete the old folders by hand; wipe an empty live.
- **2 and 3 OVERRULED: NO HARD-CODED WEB ADDRESSES ANYWHERE.** WebMS-Intra is a product for many customers, and every
  domain is configurable at installation. Ours is just one configuration.
- **NEW: a GitHub Action that builds a downloadable ZIP installation package** for future customers.
- **4: HYBRID.** Ours is one DreamHost user for all channels; other customers may have separate hosting accounts, or
  other hosts, per channel. The deploy must support both.
- **Revision run queued** (brief `.claude-work/briefs/plan-amend-channels-r2.md`, sequential Fable). It launches after
  the secret-settings design run finishes, and before the sweep.
- New issues are being opened for the zip package and for configurable addresses; the answers are commented on #493.
- **Do NOT build any #493 step 2+ until that revision exists.**

### 13 September ~21:45 — channel re-plan FINISHED; secret-settings design STARTED

- **Re-plan done** (run `wf_a32bd4e5-673`; all three stages ran on Fable, and stage 3 succeeded on the retry).
  - The amendment is saved in BOTH `.claude-work/reviews/plan-amend-3-amendment.md` and
    `.claude/plans/public-door-5-channels-amendment.md` (identical, 85 KB).
  - Section 0 says exactly what it replaces in the corrections, the build plan and DEV_NOTES.
  - Section 3 gives the deploy workflow exactly: per-folder uploads, never `web/` as a whole; guards; health checks
    on every channel.
  - Section 4 is the hard ordering; section 6 is the changeover for someone using only the panel and SFTP.
  - It ends with **10 OWNER DECISIONS**, asked on 13 September (answers to be recorded in the amendment and here).
  - It adds `health` to `Maintenance::ALLOW_LIST` as a new Step 2 item.
- **Secret-settings design run launched** (Workflow `design-497-secret-settings`, run `wf_ec141e58-e3b`,
  sequential Fable). Outputs:
  - `.claude-work/reviews/pkg5-{1-investigation,2-challenge,3-design}.md`
  - `.claude/plans/secret-settings-497-design.md`

  It is the only analysis run active. **The sweep waits for it.**
- Still running: the Codex queue (pkg1 r2b first); `pkg6-number-flags`; `maintenance-498` (off-site trigger, then
  #498).

### LATEST — 13 September 21:40: the owner's new instructions, what is running, and the order of work

**The owner's instructions (13 September, after the limits reset):**
- Pick up everything that was interrupted.
- Do #497 (all of it: the comparisons AND secret-settings decryption), #498 (demo data), and tighten the off-site
  backup "Run now" trigger.
- Then Codex review, fix, and re-review until clean.
- The full standing block was re-issued: plain English; keep the handoff current; Fable for deep analysis (Opus
  fallback), Sonnet/Haiku for building (Opus if complex); dev-team plugin; cross-review; after each task commit AND
  PUSH to the working branch and update each issue individually; memory and context; a thorough documentation pass;
  autonomy; progress tables; no stacked PRs; the LLM fallback rule.
- The three standing rules (plain English, handoff, LLM fallback) were ALREADY in `.claude/CLAUDE.md` and
  `~/.claude/CLAUDE.md`; this was checked, not added twice.

**Checked alive at 21:37 (nothing was lost this time):**
- The Codex queue: `codex-pkg1-r2b.txt` being written by a live `codex exec` process. Next in the queue: pkg2 r2b,
  forged r1b, pkg3 r2b, docs r3.
- Workflow `pkg6-number-flags` (run `wf_6e5ac591-46d`): the builder agent is active. It has edited Site.php and 8 page
  files; **Router.php is not yet changed** (by design, it lists the open pages and public routes at risk first).
- Workflow `plan-amend-channels` (run `wf_a32bd4e5-673`): the stage-3 amendment agent is active (Fable retry).

**Launched now:** Workflow `maintenance-498` runs sequentially, to limit concurrent usage:
1. The off-site trigger. Brief `.claude-work/briefs/offsite-trigger.md`; Sonnet builder, then Opus verifier, then a
   fix and re-verify if needed.
2. #498 demo data. Brief `.claude-work/briefs/demo-data-498.md`; Opus builder, then the same.

**Migration numbers RESERVED (to stop clashes):**
- **194 = #498** demo-data register (only if it needs one);
- **195 = #497** secret settings (pkg5);
- the #479 and #493 plans take the next free numbers when they are built, and must renumber from their plan text.

**Commit mechanics to remember:** `web/_sql/full_schema.sql` will hold hunks from pkg1 (migration 193) and from #498
(and later pkg5). Commit each package's hunks separately: build a filtered patch with `git diff` and apply it with
`git apply --cached`. Never commit the whole file for one package.

**Order of work from here:**
1. When the re-plan finishes: launch the pkg5 secret-settings design run (brief `.claude-work/briefs/pkg5-secret-settings.md`,
   sequential Fable), then build it.
2. As each package's build and verify finishes: queue its Codex review; fix and re-review until clean; then commit and
   push that package alone; update its issue; update memory and handoff.
3. Commit order once clean:
   - pkg1+pkg4 (Step 1 + App.php);
   - forged-address (#496);
   - pkg2 (#495, incl. backup.php);
   - pkg3 (#494);
   - docs, config and plans;
   - pkg6 (#497 comparisons);
   - the off-site trigger;
   - #498;
   - pkg5 (#497 secrets).
4. Then: the issue sweep and ranked proposals (sequential Fable, featurefind writing `.dev-team/FEATURES.md`); the
   thorough documentation pass; the #479 build; the #493 build under the amendment.

### ⚠️ CLAUDE USAGE LIMIT HIT, 13 September evening — what died and how it was resumed

The Claude session limit (it reset at 21:20 London time) killed four pieces of work at once. Codex was NOT affected,
because it has its own limit. Recorded under the fallback rule:
- **pkg6, the number-versus-text comparisons: agent died mid-build.** It may have left partial edits. Relaunched as
  Workflow `pkg6-number-flags`: build, independent verify on a real database, fix, re-verify. The builder is told
  to inspect `git diff` on its files for partial edits first.
- **pkg5, the secret-settings investigation: died at its first step, nothing written.** Queued, not relaunched.
  It is design work, so it runs as a sequential Fable analysis run AFTER the channel re-plan finishes (never two
  analysis runs at once). Its brief is the pkg5 prompt recorded in this session; it produces
  `.claude-work/reviews/pkg5-secret-settings-investigation.md`.
- **Channel re-plan: stages 1 (re-walk) and 2 (challenge) completed on Fable. Stage 3 (amendment) failed on BOTH
  Fable and Opus.** Resumed with `resumeFromRunId wf_a32bd4e5-673`, so the cached stages replay and Fable is tried
  first again. Stage 1 found, among other things:
  - `Maintenance::ALLOW_LIST` lacks `health`, so the post-deploy health check always hits the 503 holding page
    during an upgrade;
  - under option C, live's channel folder IS the old shared base, so live keeps its existing `_auth_keys/`.
- **The owner's answers to D2, D4-D8 and D10 were recorded after the reset.** All ten #479 decisions are answered,
  all as recommended; see the plan file's "OWNER ANSWERS" section.

### Maintenance pages checked against the code, 13 September (while Codex was limited)

The pkg2 fix agent flagged pages outside its list that only check `App::isAdmin()`. Read directly:
- **`admin/maintenance/demo-data.php`: DATA-LOSS HAZARD, filed as #498** (body in `.claude-work/issue-demo-data.md`).
  `demo_data.sql` uses ON DUPLICATE KEY UPDATE, so LOADING also overwrites real accounts numbered 9000-9004.
  - Any administrator can use it (line 30).
  - Its wipe runs `DELETE FROM tblUsers WHERE userID >= 9000`, plus four other tables, with no organisation filter
    (lines 79-83).
  - `userID` is plain auto-increment, so real accounts reach 9000, and from then on Wipe deletes real people.
  - The switch `portal.demo_mode.enabled` is seeded '0' portal-wide (full_schema.sql:2948).
  - `demo_data.sql` uses fixed account numbers 9000-9004.
- **`admin/maintenance/offsite-backup-run.php:24`:** any administrator can run the off-site sync of the whole
  installation's backups. It should be made global-administrator-only to match backup.php. **Fold into pkg2 round 3**
  (Codex pkg2 r2b was asked to look for exactly these pages, so wait for its answer first).
- **`admin/maintenance/retention.php`:** any administrator; deletes activity logs and errors for every organisation.
  **Already covered by #491.** Note for #491's decision: the owner's settled GDPR decision "logs kept forever, with
  the user link decoupled" sits uneasily with an activity-log sweeper that deletes logs at all. Raise it when #491
  is decided.
- **`health.php`, `backup-check.php`:** display only, isAdmin-only. Not examined further.
- **pkg5 (secret settings) design brief saved** to `.claude-work/briefs/pkg5-secret-settings.md`, as three sequential
  Fable stages. Launch after the re-plan finishes, BEFORE the sweep.

### ⚠️ CODEX ALSO HIT ITS USAGE LIMIT — the four reviews launched around the Claude limit never ran

`codex-pkg1-r2.txt`, `codex-forged-r1.txt`, `codex-pkg2-r2.txt` and `codex-pkg3-r2.txt` each contain only the brief
plus "ERROR: You've hit your usage limit ... try again at 9:30 PM". **None of those reviews happened.** Under the
fallback rule, nothing was quietly reviewed by Claude instead. The changes stay NOT REVIEWED and uncommitted.

A background queue waits until 21:31, then runs the reviews ONE AT A TIME, so a second limit loses at most one. It
stops at the first "usage limit" answer. Order and output files:
1. `codex-pkg1-r2b.txt`
2. `codex-pkg2-r2b.txt`
3. `codex-forged-r1b.txt`
4. `codex-pkg3-r2b.txt`
5. `codex-docs-channels-r3.txt`

The same briefs as before are used; the docs review uses the new round 3 brief. If the queue stops, re-run the rest
by hand with the same command shape.

**Docs round 2 points: all verified and fixed.**
1. The guard rule 2 promise was wrong. Checked in the plugin's own `hooks/guard-ledgers.sh`: `id_pattern`
   `\b(FG|F|B|G|V|I|M)-[0-9]{3}\b` matches with no marker, and with `guard: on`, `run_active=1` always. The
   config.yml comment now states the limitation and the workaround, and says why `on` is kept.
2. Corrections item 6: "ours was not" and "nothing is live" are now attributed to the owner, not the code.
3. The folder warning was rewritten: the `PORTAL_ENV` variable wins; the match order; an unrecognised staff path
   also skips the gate; the reader must produce exactly `prod`, and a source the gate accepts.

### Results that came back around the limit

- **pkg3 fix (checker): DONE, all 8 Codex findings reproduced and fixed.**
  - Also found and fixed: `use Auth;` was being resolved as `Portal\Core\Auth`.
  - The real run is identical before and after: 788 files, 6,486 calls, 0 findings. Self-test 100 of 100.
  - Step 20's greps now use `grep -a`, so an invalid byte cannot swallow a finding line.
  - **Codex round 2 launched,** writing to `codex-pkg3-r2.txt`.
- **pkg2 fix (settings pages): DONE.** All 4 findings reproduced and fixed. `backup.php` is now global-administrator
  only for every action. It flagged pages outside its list that still only check `isAdmin()`:
  offsite-backup-run, retention, backup-check, health, **demo-data (loads or wipes live tables)**, plus help pages
  pointing site administrators at backups. These go in the follow-ups issue, or into pkg2 round 3 if Codex r2
  raises them.
- **pkg4 (App.php flags and Logger): DONE,** apart from the bootstrap step, which was deliberately NOT done: fixing it
  alone crashes every page because of the `auth.ms365.tenantOnly` seed. That became pkg5.
  - It also found and fixed group.php's double CSRF check.
  - Its scan found 14 broken comparisons. **CRITICAL: `Router.php:83` means the Router never forces sign-in.**
- **New GitHub issue #497** for the number-versus-text comparisons (critical, security). A **#479 comment** was posted: design finished, all ten decisions answered.
- **Codex reviews launched before the limit, all three finished:** pkg1 round 2 (`codex-pkg1-r2.txt`),
  forged-address round 1 (`codex-forged-r1.txt`), pkg2 round 2 (`codex-pkg2-r2.txt`). Being read now.
- **Docs and config Codex round 2 (`codex-docs-channels-r2.txt`): NOT CLEAN, 3 points.**
  1. The guard comment wrongly promises it never blocks a person's own edit. Rule 2 also matches record IDs
     such as `FG-001` with no marker; being verified in `guard-ledgers.sh`.
  2. Corrections item 6's "ours was not" and "nothing is live" are the owner's information, not provable from
     code, and must say so.
  3. The folder warning needs qualifying: the `PORTAL_ENV` variable wins; `public_html_dev` and the like are
     matched first; an unrecognised staff path ALSO bypasses the pre-release gate; and the production value must
     be exactly `prod`.

  Round 3 follows once those are fixed.

### #479 DESIGN FINISHED, pkg2 review back, re-plan RUNNING — 13 September (later)

- **#479 design run finished** (`w46hpsp1l`, run `wf_ff8a3d5d-dc0`; all four stages ran on Fable).
  - Copied to `.claude/plans/data-download-479-{1-survey,2-design,3-challenge,4-plan}.md` so they get committed.
  - The final plan (`4-plan.md`) has Parts 0 to 6, a build plan in 8 steps (migration 194 is in its Step 4),
    **10 OWNER DECISIONS D1 to D10**, and a "What was not checked" list.
  - No Codex review of the plan yet; the standing rule applies to each build step.
  - Survey facts worth keeping:
    - the catalogue really has 55 erase, 70 unlink, 3 retain and 3 not-personal; its heading comments are stale;
    - the download covers 20 of 131 tables;
    - `calendarToken` leaks in today's download;
    - 5 catalogue entries name columns that do not exist;
    - 11 tables with a link to a user sit outside the catalogue under column names the coverage check misses.
  - **Owner decisions asked 13 September:** D1 (an administrator queues a download for a written request),
    D9 (how Microsoft/Google-only members prove it is them), D3 (a child's Kids profile in a parent's download),
    and whether to accept the recommended answers for D2, D4-D8 and D10.
  - **#479 migration-number clash to resolve:** BOTH this plan and the #493 plan want migration 194. Whichever
    builds first takes 194; the other renumbers.
- **pkg2 Codex review (`codex-pkg2-r1.txt`): NOT CLEAN.**
  - [P1] apps, qr and sabbath call `Auth::verifyCsrf()` twice. The first call replaces the token
    (Auth.php:297-301), so a global administrator's save silently does nothing.
  - [P1, pre-existing] `admin/maintenance/backup.php:38` only checks `isAdmin()`, so a site administrator can
    restore `tblSettings` (portal-wide rows included) from a snapshot.
  - [P2, pre-existing] the QR API key: a blank submission overwrites the stored key, which is also stored
    unencrypted and copied into `defaultValue`.
  - No ungated portal-wide write was found in the 22 files, and no text-versus-number comparisons.
  - Codex also gave a per-file view of which settings arguably belong to each ORGANISATION rather than the
    installation: payments, Sabbath quiet hours, organisation address, QR, apps, and the MS365 mailbox. **Not
    acted on.** It is a design question for the sweep, or for the owner.
  - **Fix agent launched** (Opus). Files: apps/index.php, qr/index.php, sabbath/index.php,
    maintenance/backup.php (plus its handler if any), captcha/index.php ("Drag to re-order" text).
- **Channel re-plan run LAUNCHED** (Workflow `plan-amend-channels`: sequential Fable with Opus fallback).
  Outputs go to `.claude-work/reviews/plan-amend-{1-rewalk,2-challenge,3-amendment}.md` and
  `.claude/plans/public-door-5-channels-amendment.md`. The brief's facts were corrected first: separate folder
  is not the same as separate database, and the `public_html`/`admin_html` detection hazard.
- **Docs and config Codex review round 2 launched**, writing to `codex-docs-channels-r2.txt`.
- **Running now:** pkg4 (administrator flags and Logger), the pkg3 fix (checker), the pkg2 fix (settings pages),
  the re-plan, and docs review round 2. **The sweep and proposals run waits for the re-plan to finish.**

### Codex round 1 results, 13 September

- **Docs and config review (`codex-docs-channels-r1.txt`): NOT CLEAN. Every point was checked against the plugin reference and bootstrap.php, and all were right. Fixed:**
  - The `.dev-team/config.yml` comments were rewritten. Featurefind ALREADY writes `.dev-team/FEATURES.md` by itself. `guard` covers two hooks (shell commands, and file writes and edits). auto-handoff writes `.dev-team/HANDOFF.md`. "balanced" builds on OPUS, not Sonnet (model-routing.md:69-75); kept, with the comment now saying so. The branch settings do not enforce one working branch.
  - Corrections doc item 6: the "one database" claim is qualified (paths come from secrets). "Separate folder ≠ separate database": each channel needs its own database and installer run, and `_auth_keys/` must never be copied between channels.
  - **New hazard recorded:** `bootstrap.php:98-107` treats any path containing `public_html` as live and anything else as 'dev', which shows errors. So a folder rename before the channel file lands would show PHP errors on live's staff side and make alpha's public side look live. The rename and the channel file must ship together.
  - The re-plan brief gained items 10 (hard ordering) and 11 (databases and keys per channel).
  - **Round 2 of this review still to run.**
- **pkg3 method-call checker (`codex-pkg3-r1.txt`): NOT CLEAN.**
  - The ten original cases pass, and the real run is clean (788 files, 6,485 calls, 0.67s); self-test 41/41.
  - 8 medium findings: imports leak between namespaces; comma-separated imports lost, and grouped function/const imports treated as classes; core map keyed by short name; conditional declarations overwrite each other; methods returning by reference are missed; four trait `insteadof`/`as`/abstract argument-count errors; the Python wrapper has no timeout; the wrapper's decode or launch errors fail invisibly in pr-security step 20.
  - **Fix agent launched** (Opus). It must reproduce each finding first. Where the checker cannot be sure, it must neither accuse nor count the call as verified. Brief is inline in the agent prompt, and the findings are in `codex-pkg3-r1.txt` from line 3623.
- pkg2 review: still running.

### Also done, 13 September (after the build round), and what is waiting

- **#493 comment posted** recording the channel decision, the option C settings, and what changes in the plan:
  https://github.com/MWBMPartners/WebMS-Intra/issues/493#issuecomment-5654890165
- **Memory saved:** `webms-channels-fully-separate.md`, `db-flags-come-back-as-numbers.md` (MEMORY.md index updated).
- **`.dev-team/config.yml` + `.dev-team/.gitignore` created** (uncommitted). Settings:
  - `default-branch: alpha`, `stack-prs: false`, `single-pr: true`;
  - `issues: off`, because issues are updated by hand and individually;
  - `guard: on`;
  - `auto-handoff: off`, because `.claude/HANDOFF.md` is the handoff;
  - `auto-commit: off`, because Codex reviews before commit and only named files are staged.

  The comment in the file records that a featurefind run must be TOLD to write `.dev-team/FEATURES.md`. The plugin has no setting for that path, and the top-level FEATURES.md is our own.
- **Brief for the channel re-plan written:** `.claude-work/briefs/plan-amend-channels.md`. It is a sequential Fable run with an Opus fallback. **Launch only after the #479 design run finishes.** Its outputs go to `.claude-work/reviews/plan-amend-{1-rewalk,2-challenge,3-amendment}.md` and `.claude/plans/public-door-5-channels-amendment.md`.
- **Codex review of the docs and config launched:** `brief-docs-channels.txt`, writing to `codex-docs-channels-r1.txt`. Once it is clean, commit `.dev-team/config.yml`, `.dev-team/.gitignore`, `.claude/plans/public-door-4-fable-3-corrections.md` and `.claude/HANDOFF.md`.
- **Nothing reads any `SFTP_PATH_*` secret yet.** `deploy.yml` still reads `SFTP_LIVE_PATH`, `SFTP_BETA_PATH` and `SFTP_DEV_PATH`, so the owner can change the secrets now without breaking anything.

**Waiting on (all in the background):**
- pkg4, the administrator-flags fix (Opus agent);
- Codex reviews: pkg2 (`codex-pkg2-r1.txt`), pkg3 (`codex-pkg3-r1.txt`) and docs (`codex-docs-channels-r1.txt`);
- the #479 design run (`w46hpsp1l`, run `wf_ff8a3d5d-dc0`). Stage 1 is saved as `design-479-1-survey.md`.

**Then:**
1. Codex reviews pkg1 (plus pkg4) and forged-address.
2. Commits, in the order above.
3. Update #493, #494, #495 and #496, and create the issue for the text-versus-number comparisons from pkg4's scan and the follow-ups issue.
4. Channel re-plan run.
5. Sweep and proposals (with featurefind writing to `.dev-team/FEATURES.md`).
6. #479 build.
7. Docs pass.

### ⚠️ FOUND 13 September — the three channels are not separate on the server at all

Found while measuring what "publish separately per channel" would cost. PROVEN from the code, not a document:

- `web/_core/bootstrap.php:301` loads the database login from `_auth_keys/auth_creds.php` under the ONE shared
  root. Nothing chooses a different database per channel.
- `.github/workflows/deploy.yml` sends `_core/`, `_apps/`, `_sql/` and the rest to `dirname()` of each channel's
  web root — the SAME parent folder for all three. DEV_NOTES already says it: "last push wins for shared code".
  The planned `SFTP_PATH_ROOT_DIR` scheme keeps exactly this shape.

So **alpha, beta and live share one database AND one copy of the framework and app code**; only the web roots
differ. An alpha deploy changes the code live runs, and testing on alpha reads and writes real members' data —
including safeguarding records. Nothing is deployed yet, so it is cheap to change now.

Measured cost of the per-channel options:
- A channel column on every setting (the review's costing): **517 settings reads in 199 files**, plus the two
  uniqueness rules on `tblSettings` (`uq_setting_key_site`, `uq_setting_key_scope`), on a table that has already
  produced duplicate rows once. Three to five days, high regression risk.
- A per-channel switch on the public hostname record the plan already needs (`tblPublicHosts`): a few hours,
  but alpha still changes live's code and data.
- **Fully separate channels** — own code folder and own database each: removes the question entirely, since each
  channel then has its own settings. Roughly half a day to a day for deploy and docs, plus about an hour of the
  owner's time. Changes the SFTP settings again.

**OWNER DECIDED, 13 September: FULLY SEPARATE CHANNELS, the iHymns way.** In the owner's words: "each channel's code
stays as is, and is separated at repo level only by branch. Code is then separated into separate folders on the
server, purely by GitHub Action deployment based on source branch (similar to iHymns)".

What that means, checked against iHymns' own files (not its docs alone):
- iHymns gives each channel its own DreamHost folder — `/home/user/ihymns.app/`, `/home/user/beta.ihymns.app/`,
  `/home/user/dev.ihymns.app/` (iHymns `DEV_NOTES.md:109-111`). Its deploy puts shared pieces in `dirname()` of
  that channel's path, which is therefore DIFFERENT per channel (iHymns `deploy.yml:1014`, `:1073`).
- Its database login sits beside the web folder (`dirname(__DIR__, 2)/.auth/db_credentials.php`,
  `includes/db_mysql.php:56`), so a separate folder means a separate database with NO code choosing one.
- The deploy writes the channel into a file (`.env-channel`, iHymns `deploy.yml:521`) and the PHP reads it
  (`includes/environment.php:34`).

For WebMS the same falls out almost for free: `bootstrap.php:302` already reads `_auth_keys/auth_creds.php`
beside the code, so each channel folder gets its own `_auth_keys/`, its own `enc.key`, its own `_uploads/`
and its own database. The repo does not change per channel.

**Consequences to carry into the #493 plan (not yet applied to the plan files):**
1. Correction 1's premise ("one database, three channels") is gone. Each channel's database holds only its own
   public hostname rows. Re-check whether `tblPublicHosts.siteID` still needs to allow NULL.
2. Correction 5 (deploy redesign) changes shape: shared code goes to the CHANNEL's root, not one shared root.
   "Last push wins for shared code" (deploy.yml:21-22) stops being true, and that comment must go.
3. **The channel can no longer come from the folder name.** `bootstrap.php:98-105` spots `public_html_dev` /
   `public_html_beta` in the web folder's path. In the new layout every channel's folders are named
   `admin_html/` and `public_html/`, so all three would fall back to 'dev'. The `.channel` marker file the plan
   already has in Step 2 becomes REQUIRED, not optional. Migration 193's gate declines on a guessed channel,
   so this fails safe (no lock-out) but also means the alpha/beta gate would do nothing until Step 2 lands.
4. Per-channel publishing needs no code at all: each channel has its own settings table.
5. **The deploy settings change again — OWNER DECIDED OPTION C, 13 September.** Four settings:
   `SFTP_PATH_ROOT_DIR` = the hosting HOME folder only (`/home/USER/`), plus `SFTP_PATH_LIVE_DIR`,
   `SFTP_PATH_BETA_DIR`, `SFTP_PATH_ALPHA_DIR`, each naming one channel's folder inside it (e.g.
   `portal.millrdsdacambridge.uk/`, `beta.portal.millrdsdacambridge.uk/`, `alpha.portal.millrdsdacambridge.uk/`).
   Inside every channel folder the doors are FIXED as `admin_html/` and `public_html/`, matching the repo.
   RETIRED: the six `SFTP_PATH_<CHANNEL>_<DOOR>_DIR` settings the owner set up earlier the same day.
   `SFTP_PATH_ROOT_DIR` KEEPS its name but its VALUE changes (drop the `portal.millrdsdacambridge.uk/` part).
   Rejected: A (three full paths) and B (nine). The owner was shown that the SERVER layout is identical under
   all three; only how GitHub describes it differs. Known limit of C, accepted: every channel must sit under
   one hosting home folder. That already holds, because there is one SFTP_USER / SFTP_PASSWORD.
   Worth knowing, not acted on: with one hosting user, alpha's code could in principle read live's
   `_auth_keys/`. Nothing does so by accident (every path is relative to its own folder); a separate DreamHost
   user for alpha would be the stronger setup, and would need per-channel login settings and a secrets change.
6. DEV_NOTES 3b and 3c (the settings table, slash rules, changeover steps) must be rewritten.
7. The plan amendment is planning work, so it runs as a sequential Fable run AFTER the #479 design run finishes
   (never two analysis runs at once).

**`.dev-team/` — the owner's suggestion, confirmed.** SIGNula.id keeps its dev-team state in `.dev-team/`
(`FEATURES.md`, `PROJECT.md`, `autopilot.json`, `specs/`; its `.gitignore` excludes only `loop.lock/`).
Using the same folder lets `dev-team-featurefind` write its ledger to `.dev-team/FEATURES.md` without touching
this project's own `FEATURES.md` — and `.dev-team/` is outside `web/`, so it is never deployed. Featurefind
runs its market researchers side by side by default; they will be run one at a time, because the owner's
sequential-analysis rule wins over a skill's default.

### 🟡 #479 data-download design — RUNNING (run `wf_ff8a3d5d-dc0`)

Four sequential Fable stages: survey, design, challenge, plan. Each stage writes its own output to
`.claude-work/reviews/design-479-{1-survey,2-design,3-challenge,4-plan}.md` before returning, so the result
does not depend on the task output under `/tmp` surviving.

### The owner's latest instructions (13 September) and the order they will be done in

The owner re-issued the full standing instructions, adding three explicit asks: a sweep of EVERY issue
(open and closed) checked against the real code; a ranked list of proposals for new work; and a thorough
documentation update.

**Already standing rules — nothing to add:** plain English; the fallback rule, including that it makes this
handoff crucial; no PR stacking; the Codex review loop. All are in `.claude/CLAUDE.md`, the device-wide
`~/.claude/CLAUDE.md` and memory. Self-hosted Swagger UI already exists (commit `8f21094`) — the docs pass
verifies it rather than rebuilding it.

**The order, and why:**

1. The build round and the Fable plan review finish (running now).
2. **#479 data-download design** — Fable, short. It goes before the sweep because it is high priority and
   small, and once designed its BUILD can run alongside the long sweep (building is not an analysis run).
3. **Issue sweep + proposals, as ONE analysis run.** Only one analysis run may go at a time, and the
   proposals should build on what the sweep finds. Brief: `.claude-work/briefs/sweep-and-proposals.md`.
4. **Documentation pass** once the code has settled, so it is done once, not twice.

**Defaults for the sweep, so it does not stop to ask:**

- Closed, but the core ask was never delivered → reopen, with evidence.
- Closed, core delivered, a gap remains → stays closed; ONE linked follow-up issue.
- Open but verifiably done → comment with evidence, then close.
- Cannot be verified without a live server, database or outside account → comment saying exactly what could
  not be checked; state unchanged.
- #493 to #496 describe the round in progress — never closed or reopened by the sweep.
- Nothing is written to GitHub until every close and reopen has been double-checked by a second agent.

**Checked with the GitHub API and the code on 13 September — so the sweep and docs pass start from facts:**

- **362 issues** (319 closed, 43 open); numbers run to #496 because pull requests share the numbering.
- **Project board** "WebMS Intra Development" (org project #2) holds only **32** of them — 31 Done, 1 Todo.
  Default: every OPEN issue goes on it with a real status; closed old issues are not added.
- **Milestones** are stale: "v1.0.0 — Phase 9" is still open while `main` is on 1.4.0, and milestones hold
  only about 52 of 362 issues. The sweep RECOMMENDS; restructuring them is the owner's call.
- **Labels** are inconsistent (`security` beside `type: security`; `app:noticeboard` without the space).
- **The wiki has content** — the docs pass checks it.
- **Swagger UI IS self-hosted** — `web/public_html/assets/vendor/swagger-ui/`, version 5.17.14, public copy
  first with automatic fallback to the local files, plus `api.docs.local_assets_only`. (It is not in
  `api-docs/`, which holds only `index.php` — that is why a first look suggested otherwise.)
- **The API description may be well behind the code:** 63 handlers, 55 documented paths, 30 handlers not
  obviously described (some may be covered by the `/api/v1/...` form). Two handlers answer 403 to everyone
  because their `api.<app>.<action>.enabled` switch was never seeded: `/api/expenses/export` (looks like
  another built-but-unreachable feature) and `/api/assets/_coerce` (looks like a helper file, not an endpoint).
- The docs-pass brief is ready: `.claude-work/briefs/docs-pass.md`. The sweep brief now covers the board,
  milestones, labels and those two handlers.

**Why `dev-team-featurefind` is not being run as-is:** it fits the proposals task, but it writes its own
`FEATURES.md`, which would overwrite this project's living feature inventory. Its competitor-comparison
idea is built into the proposals stage instead.

### The services are back

Usage limits reset. On 13 September Fable, Codex and the build agents all answered a probe.

### State as verified on 13 September, before anything new started

- 34 changed PHP files in the working tree, **0 syntax errors**. All 15 checks and all 5
  self-tests pass.
- **The 22 portal-wide settings pages (#495):** the agent that "failed" had actually changed
  **17 of them (920 lines)** before its weekly limit ran out. **None of it was verified.** The
  5 it never reached: `admin/apps/index.php`, `admin/captcha/index.php`,
  `admin/maintenance/offsite-backup.php`, `admin/settings/qr/index.php`,
  `admin/settings/sabbath/index.php`.
- **`check_static_calls.py`** is still the 1,486-line version that was verified by hand.
  The tokenizer rebuild never started — that agent hit its limit before writing anything.
- **LOST:** the `/private/tmp` scratchpad was cleared over the two days. The address-fix Codex
  review never produced a surviving result, so it must run again. The Step 1 review file is
  gone too, but its findings are written down further below in this file.

### Lesson: nothing that must survive lives in /tmp any more

Briefs, scan scripts and review transcripts now live in **`.claude-work/`** inside the repo. It
is excluded through `.git/info/exclude` (local only; `.gitignore` is untouched), so it survives
a clean-out of `/tmp` but is never committed. The design documents survived only because they
had already been copied into `.claude/plans/`.

### This round

**Build workflow — three packages in parallel, each touching only its own files, each built and
then checked by an independent verifier agent.** The briefs, with full acceptance criteria:

- `.claude-work/briefs/pkg1-step1-fixes.md` — fix everything Codex found in Step 1.
- `.claude-work/briefs/pkg2-settings22.md` — verify the 17 suspect pages, finish the other 5.
- `.claude-work/briefs/pkg3-checker-rebuild.md` — rebuild check 16 on PHP's `token_get_all()`,
  with all 17 known cases as committed regression fixtures.
- Shared rules for every builder and verifier: `.claude-work/briefs/common-builder.md` and
  `common-verifier.md`.

**Analysis workflow, separate — a Fable catch-up review of the public-door plan**, because that
plan was designed entirely on Opus while Fable was down. Brief:
`.claude-work/briefs/plan-catchup-review.md`. Sequential agents, as the standing rule requires.

**Running now (13 September):** build workflow run `wf_39955250-631`, and the Fable plan
review run `wf_688222d8-d65`. Those run IDs can only be resumed from the SAME session. If the
session is lost part way through, do not try to resume them — start again from the briefs.
Each package brief in `.claude-work/briefs/` is self-contained: it lists its own files, what
to fix, and its acceptance criteria. Before re-running a package, check `git diff` on that
package's own files first — an interrupted builder may already have done part of the work, as
happened with the 22 settings pages.

**Ready to fire, so nothing waits on drafting:**

- Codex review briefs for each package, and for the address fix:
  `.claude-work/reviews/brief-{pkg1,pkg2,pkg3,forged}.txt` — checked: each names the real
  repository path and its diff, with no mangled shell syntax. Each expects its diff at
  `.claude-work/reviews/<name>.diff`. Build that diff with the file paths WRITTEN OUT, never from a
  shell variable (on this machine zsh passes an unquoted variable as one argument, and `git diff`
  then silently returns nothing). Count the files in the diff before sending it.
- The address-fix review runs only AFTER Package 1 lands and `Logger.php` has been changed, because
  it must check that the logger asking the rate limiter for an address cannot loop.

**Issues tracking this round:** #493 (the public-door plan, Step 1), #494 (pages calling methods
that do not exist, and the check being rebuilt), #495 (the 22 portal-wide settings pages), and
**#496 (ten places that believed a visitor's claimed address — two real rate-limit bypasses)**.

**The #479 data-download design brief is ready** at `.claude-work/briefs/design-479-data-download.md`.
It waits for the plan review to finish, because only one analysis run may go at a time. It carries a
real design question worth knowing now: a row matched because a person ACTED on it (created it,
recorded it, approved it) usually holds SOMEBODY ELSE'S personal data — a visitor record a volunteer
entered holds the visitor's name and phone number. Handing that whole row over in "your" download
would disclose a third party's data, which a right-of-access request must not do.

**Then, driven from the main session:** a Codex review of each package, fix, and review again
until clean. Then the address-fix review (it waits for Package 1, because it depends on how the
rate limiter finally decides which address to believe), and `Logger.php`'s direct header read.

### Decisions taken for Package 1 — recorded so they are not re-argued

- `portal.trustedProxies` accepts single addresses AND ranges (IPv4 and IPv6). A `/0` range,
  an out-of-range prefix, or anything malformed is ignored and never trusted.
- X-Forwarded-For is walked from the **right**, skipping trusted hops.
- A new `portal.trustedProxyHeader` (`x-forwarded-for` by default, or `cf-connecting-ip`) names
  the ONE header the trusted proxies guarantee to overwrite. Nothing else is believed.
- IPv4 written in IPv6 form (`::ffff:…`) is normalised before comparing.
- A new `PORTAL_ENV_SOURCE` records whether the channel came from the environment, the folder
  name, or the fallback. The alpha/beta gate engages only on the first two. How `PORTAL_ENV`
  is decided, and error display, are deliberately left for Step 2's explicit channel file.
- `/` is gated like `/dashboard`; the invitation page and `/offline` are let through.
- The `public.` settings guard is case-insensitive (keys are NOT forced to lower case — many
  are camelCase). A new key differing only in letter case from an existing one is refused.
- Authorisation is repeated inside the UPDATE and DELETE themselves.
- AppRegistry gets a loading guard, and keeps each definition file's result instead of
  requiring it twice (not `require_once` — a returned array comes back as `true` the second time).

### Decided against

- **Running Codex inside workflow agents.** A Codex review routinely runs longer than the
  10-minute limit on a single agent command. Reviews are driven from the main session instead,
  in the background, with their output written to `.claude-work/reviews/`.
- **Embedding the briefs inside the workflow script.** The briefs are full of backslashes
  (`Portal\Core\Site`), and inside a JavaScript template string every one is silently eaten.
  The briefs are files that the agents read.

---


## Earlier — 11 September 2026, late (superseded by the section above)

### Where things stand

| Item | State |
| --- | --- |
| Deployment secrets | ✅ **Set by the owner** under the final names `SFTP_PATH_ROOT_DIR` + `SFTP_PATH_<LIVE\|BETA\|ALPHA>_<ADMIN\|PUBLIC>_DIR`. Verified with the GitHub API. Retired names are gone at repo AND org level. Password authentication (no `SFTP_KEY`); `SFTP_PORT` inherited from the organisation. See DEV_NOTES 3b. |
| #493 Step 1 — pre-existing defects | 🟡 Built. All 15 checks + 5 self-tests green. **NOT committed, NOT yet reviewed** — Codex review started. Files: `web/_apps/settings/{save,index}.php`, `web/_apps/admin/settings/group.php`, `web/_core/{AppRegistry,Gatekeeper,Logger,RateLimiter}.php`, `web/public_html/index.php`, `web/_sql/full_schema.sql`, `web/_sql/193_trusted_proxies_and_channel_gate.sql`. |
| Hardened `tools/audit-checks/check_static_calls.py` | 🟡 Untracked, 1,486 lines. **Verified working** with 7 fixtures (see below). **Not yet wired into `.github/workflows/pr-security.yml`.** |
| 22 admin pages writing portal-wide settings | 🔴 **Live privilege problem**: any site administrator can change the payment keys (Stripe, PayPal), text-message and mail credentials used by EVERY organisation. Fix relaunched; issue opened. |
| #493 Steps 2–9 | ⬜ Not started. |

### Progress since that table was written (same evening)

| Item | State |
| --- | --- |
| Check 16 wired into `.github/workflows/pr-security.yml` as step 20 | 🟡 Done, **uncommitted** — to be committed WITH `check_static_calls.py` once its review is back. Parses as YAML. `actionlint` shows two info-level shellcheck notes (SC2016) — **both were already there before this change**; nothing is an error. Proved silent on a clean tree. |
| Forged-address fix — nine files | 🟡 Done, **uncommitted, not yet reviewed.** Every place that read the Cloudflare / X-Forwarded-For headers itself now calls `RateLimiter::clientIp()`. Two were real rate-limit bypasses: `AssetRegister::clientIp()` (public lost-and-found — proven to run `publicIpHash()` → `ipHash()` → `saltedHash(clientIp())`) and `LiveChat::clientIp()` (chat). Seven wrote a visitor-chosen address into stored records. Review brief staged at `scratchpad/forged-brief.txt`; **start it only after the Step 1 review finishes** so two Codex runs never overlap. |
| `web/_core/Logger.php` | ⏸️ Still reads the headers directly. **Deliberately deferred**: it is part of Step 1, which is under review. Fix after Step 1 is committed. |

### 🛑 Codex reviewed Step 1 — DO NOT COMMIT STEP 1 AS IT STANDS

Two findings would lock people out. Fix both before Step 1 goes anywhere.

- **P1 — the alpha/beta gate locks out EVERY administrator, root included.**
  `web/_core/Gatekeeper.php:143` compares the admin flags with `=== '1'`, but a
  prepared statement's `get_result()` returns TINYINT columns as native integers,
  so `1 !== '1'` and nobody passes. The documented recovery ("an administrator can
  switch it off") is broken by the same bug.
- **P1 — a LIVE server can be gated.** `web/_core/bootstrap.php:103` falls back to
  `PORTAL_ENV = 'dev'` when the web folder name is unrecognised, and the gate is on
  by default, so ordinary members would be blocked on a live site. Fix: engage the
  gate only on a POSITIVE channel match — never on the fallback — until Step 2's
  explicit `.channel` file exists.
- **P2 — `/` walks past the gate.** `Gatekeeper.php:85` exempts the empty path and
  `Router.php:212` then turns it into the dashboard.
- `/auth/invite` is missing from the gate's allowed list, so an invitee cannot
  accept. `/offline` is missing too.
- **Settings:** the `public.` guard is case-sensitive but the database lookup is
  not (`utf8mb4_unicode_ci`), so `Public.x` slips past — normalise or reject
  non-canonical keys. Also put the authorisation conditions INTO the UPDATE's
  `WHERE` to close a read-then-write race.
- **AppRegistry:** an app definition that calls `AppRegistry::all()` re-enters for
  ever (no "loading" guard); `invalidate()` can then hit a class redeclaration.
- Fine: Logger (`activity()`/`audit()` byte-identical to before), migration 193.
- **`check_static_calls.py` is NOT reliable proof.** Six reproducible false
  accusations (grouped `use {A, B}`, `use Vendor\Site`, bracketed namespace, trait
  `insteadof`, backtick strings, nested heredoc) and four missed faults (aliased
  `use X as S`, enums and interfaces never mapped, a call inside `{$a[Site::x()]}`,
  `extends \Base` mis-mapped). My seven fixtures passed but did not cover these
  shapes. **Being rebuilt on PHP's own tokenizer** with every case as a committed
  regression fixture. Until then it is advisory only — never make it blocking.

Item 1 of that review (the address handling, and the Cloudflare question below) is
read next and decides what goes into the Step 1 fix.

### ⚠️ Open question — probably needs fixing, not asking

`portal.trustedProxies` is seeded **empty**, and `RateLimiter` has **no range (CIDR) support**. If the live site sits behind **Cloudflare**, every visitor will appear to come from one of Cloudflare's own edge addresses. Every rate limit then becomes shared between all visitors on that edge: one person could lock everybody out, or ordinary users could be blocked for someone else's behaviour. And without range support an operator cannot simply list Cloudflare's published ranges.

This was introduced by Step 1 (the forged-address fix just makes more code rely on it). Asked Codex about it in both reviews. The likely right answer is to **add range support** — then it works both with and without Cloudflare, and the owner only has to fill in a setting rather than make a decision. Do not ship Step 1 to a Cloudflare-fronted server without settling this.


### What happened with the AI services — read this if something has failed

- **Fable refused on every attempt all day** (monthly spend limit). Every deep
  analysis ran on Opus instead, via the fallback written into each workflow.
- **Late on, two build agents died mid-task** with "monthly spend limit … weekly
  limit resets Sep 13 at 4pm (Europe/London)", on `claude-opus-5` and
  `claude-sonnet-5`.
- Straight afterwards a `haiku` probe succeeded and Codex answered, so the limit
  was not total. Always retry the preferred model first on each new task.
- **One dead agent HAD rewritten `check_static_calls.py`** (902 → 1,486 lines)
  but stopped before running its own proofs. It was then verified by hand with
  seven fixtures, all passing: a trait method is not accused; a class with an
  unknown parent is skipped; `\Vendor\Site::x()` is not checked as the core
  `Site`; a call inside `"{$a["Site::x()"]}"` is not accused; a call AFTER a
  heredoc ending `TXT);` IS reported; `Site::name()` IS reported; a core class
  used with no `use` line IS reported. If that file ever changes again, re-run
  these before trusting it.
- The other dead agent (the 22 pages) changed nothing — verified with `git diff`.

### Tried and rejected — do not redo these

- **Refusing a deployment when a retired secret is visible.** Wrong for this
  organisation: organisation secrets are inherited by every repository, and SFTP
  settings are already shared between the organisation's repositories. Another
  repository adding `SFTP_LIVE_PATH` at organisation level would have stopped
  every WebMS-Intra deployment. Downgraded to a warning in the build plan.
  Nothing reads the old names, so a leftover cannot misdirect anything.
- **Making every `siteID = NULL` writer root-only.** The four under
  `web/_apps/cron/` are token-gated scheduled jobs, never reached by a signed-in
  person. They are correctly different. Leave them.
- **Intermediate secret names** `SFTP_ROOT_DIR`, `SFTP_ADMIN_DIR_*`,
  `SFTP_ADMIN_PATH_*` — superseded the same evening, never set.

### Decisions the owner took today — do not re-open

- Two front doors: `web/admin_html/` + `web/public_html/`. Server folders mirror
  the repository exactly.
- All three public delivery routes, own subdomain first.
- A global administrator enables publishing, and may delegate tuning of it to
  selected administrators — not to all of them.
- Portal-wide settings are global-administrator only.
- A child's Kids record survives a parent's erasure; only the parent link goes.
- Legally-kept records are LISTED in a data download, not handed over.
- Standing rule (project AND device-wide): hand over when a service or agent runs
  out, return promptly, run a full catch-up review on return, and keep this file
  current as the work happens.

### Next, in order

1. Read the Codex review of Step 1, act on it, commit.
2. Wire `check_static_calls.py` into `pr-security.yml`; commit it with the checker.
3. Land the 22-page fix; Codex review; commit.
4. Close the two per-address limits that still trust forged headers —
   `AssetRegister.php:1111` (via `assets/found-save.php:202`) and
   `LiveChat.php:212` (via `livechat/api/send.php:105`) — by delegating to
   `RateLimiter::clientIp()`.
5. #493 Step 2 onwards. **Step 5's migration is 194, not 193**, and must not
   seed `portal.trustedProxies` again.
6. Then: Noticeboard, #479 full data download, #488 runbook, closed-issue sweep,
   documentation sweep, one PR into `alpha`.

---


## ARCHITECTURE CONSTRAINT ADDED 11 September 2026 — read before touching the Noticeboard

The owner set a requirement that changes the shape of the Noticeboard work and
everything public that follows it. Tracked as **#493**.

**The portal is the back office.** It lives on its own subdomain and that is
where content is managed. But public content must be viewable from the
organisation's **main website** — at a path on the main domain
(`example.org/noticeboard`) or on a separate subdomain — not by sending the
public to the portal.

This is not Noticeboard-specific. Public events, service programmes and
lost-and-found will all want the same path, so it needs one reusable structure.

**Four things make it harder than it first appears:**

1. The main website is usually **somebody else's system** — WordPress, Wix,
   Squarespace. Some website builders forbid script tags or frames outright.
2. **The deploy cannot reach it.** `deploy.yml` only writes to three directories
   under the portal subdomain's own base path. Anything on the main domain is
   installed by the customer, by hand, once. Any design must be honest about that.
3. **No session, unrelated hostname.** Site identity normally comes from
   `tblSites.hostPattern`, falling back to site 1. That is wrong here, and on a
   multi-organisation install it would show one organisation's content to
   another's visitors. The site must be named explicitly and safely.
4. **The same database** holds pastoral care notes, safeguarding records,
   children's medical details, financial records and the member directory. The
   boundary has to be structural, not a filter. Note this project's own history:
   making an unreachable page reachable has TWICE turned out to publish something
   with no access check, because nobody checks a page nobody can open.

**Reuse, do not reinvent.** `web/public_html/widget/countdown.js` plus
`web/_apps/widget/countdown-json.php` is a working cross-site embed (#319).
`web/_apps/calendar/widget.php` is an iframe embed — but it sets
`X-Frame-Options: ALLOWALL`, which is **not a valid value**; it works only
because browsers ignore what they do not recognise. Review it rather than copy it.

**The Noticeboard cannot be finished until this is settled.** Its view page
currently REQUIRES A LOGIN (`('noticeboard','noticeboard/index.php',1)`), which
is the opposite of the owner's specification: the board is public, only managing
it needs a login, and the foyer slideshow is reached by a secret link with no
login at all.

A design pass is running. The decision and build plan go on #493 before any code
is written.

---


## LATEST: children's registration privacy shipped (11 September 2026)

Commit `9c77216`, pushed. Part of #479. Issues #490 and #491 opened from what it
turned up.

`tblEventRegistrations` — a child's name, date of birth, allergies and medical
notes, beside a parent's telephone number and email — was the one table a
"delete everything you hold about me" request could never reach, and it was
missing from the data download too. There was no link to any account to match a
person against, so adding it to the list would have changed nothing.

Now: the submitter's account is recorded when they are signed in (empty
otherwise, which is normal — a parent must not have to create an account to
bring their child to a holiday club), and everything else is covered by a time
limit of 90 days after the event, per site, with a per-event override. Zero means
keep indefinitely and must be set deliberately. Migration 191, folded into
`full_schema.sql` with matching column order.

### Three things it turned up, all worse than the task itself

1. **Any administrator could have destroyed every site's registrations.** The
   clear-out read one site's settings then deleted portal-wide, and the page is
   open to any administrator. Fixed for registrations. **The activity-log and
   error clear-outs still have the same shape — that is #491, and it needs a
   decision rather than a guess.**
2. **The safety net had a hole the size of the bug.** The first version referred
   to `e.recurrenceRule`, a column that has never existed, and all fourteen
   checks passed — because none of them ever read a `DELETE`. Now fixed: 90
   statements, 176 column names. Four wrong accusations Codex found in that fix
   are also fixed. That is #490.
3. **An event could be saved ending before it started.** So anything measuring
   "how long ago did this finish" read a past date for a future event. Now
   refused at the save, and the clear-out takes whichever time is later anyway.

### Worth remembering from the review

Comparing two wall-clock times by converting them to moments in time **breaks
once a year**. On the morning the clocks go forward PHP reads 01:45 as 02:45, so
an event starting 02:30 and ending 01:45 passed validation. Compare the text.
Codex found this; it was my own bug in the fix for someone else's.

### #479 is NOT finished

The written list names **123 tables** holding personal information. The download
covers **23**.

| | Count | Note |
| --- | --- | --- |
| In the download already | 23 | |
| Missing, findable by account | **81** | Buildable now |
| Missing, no link to any account | 15 | Cannot be found by person; need a time limit each |
| Kept for legal reasons | 4 | Should be *listed*, not handed over |

**Do not hand-write 81 more query blocks.** That is exactly the drift that caused
this issue in the first place. Drive the download from
`web/_core/personal-data-catalogue.php` — the same written list the erasure
already reads — so the two can never disagree again.

Groundwork already checked: all 102 linkable tables exist and every link column
is real, so a catalogue-driven download will work. The sensitive-column rule to
use: **block by name pattern, but allow integers.** Credentials are text;
`sessionID` is `VARCHAR(255)` in the activity log (a real session string, block)
but `INT` in attendance sessions (a row number, harmless). That one rule
correctly handles `totpSecret` vs `totpEnabled`, `token` vs `tokenID`,
`inputTokens`, and `isDeptSecretary` — which a pattern alone gets wrong.

---


**Updated 10 September 2026, evening.** Working branch `claude/alpha-wip`, 27+
commits ahead of `alpha`, nothing open against it. The repository is fully
aligned with GitHub: no stale local branches, `alpha` in step with the remote,
and the working branch is ahead-only so a rebase would change nothing.

### THE QUESTION THAT MATTERS: can this go to a first real customer yet?

**Not yet, and there is one reason.** It is not missing features — the product is
broad and largely built. It is that **there is currently no recovery path anybody
should trust.**

- **#472** — restoring a backup can DESTROY the data it was protecting.
  `web/_core/DbBackup.php:596` empties the table with `TRUNCATE`, which commits
  immediately and cannot be undone. If a row fails half way through, the original
  contents are already gone. On top of that, MySQL refuses that command outright
  on any table other tables point at — 71 of 209 — so restoring those has never
  worked at all.
- **#488** — the emergency instructions somebody would follow at 2am do not work.

Until both are fixed, a customer with a problem could end up worse off than
before they asked for help. Everything else on the list can be fixed while live.
These cannot.

### The agreed order of work

| # | Task | Why this position |
| --- | --- | --- |
| 1 | **#472** restore must never destroy data | The go/no-go. Nothing else matters if recovery cannot be trusted. |
| 2 | **#488** make the emergency instructions true | No point having a safe restore nobody can find in a crisis. |
| 3 | **#479** data deletion and data download | Legal exposure, and it is cheapest to fix before real data exists. |
| 4 | **Noticeboard** enhancements | Owner-requested. Sound today, but a noticeboard that cannot be displayed is half a feature. |

### The owner's decisions on data protection, settled 10 September 2026

- **ONE standard for everybody.** Behaviour does NOT vary by where a person
  lives. Working out somebody's country is itself processing their personal
  data, it is unreliable, and two sets of rules doubles the chance of a mistake
  in the sensitive one. The right to have data deleted exists under UK and EU
  law, and now under Brazilian and Californian law too. One high standard is
  simpler and holds up everywhere.
- **Erase by default, with a SHORT NAMED LIST of lawful exceptions.** Deleting
  everything regardless would break the law in the other direction. Kept:
  Gift Aid declarations (6 years after the tax year, HMRC), financial and
  expense records (6 years), safeguarding records (kept and flagged, never
  silently deleted). Each exception recorded so the organisation can show why.
- **Event registrations** get linked to an account where one exists, AND are
  deleted automatically 90 days after the event.
- **The download** must be ONE file a person can open, holding readable copies
  plus a machine-readable copy, covering EVERY table with their personal data.

### ⚠️ The deletion list is longer than the issue says

#479 named five tables. It is at least seven: `tblNoticeboardPosters` and
`tblNoticeboardUploads` were found by ACCIDENT while looking at something else,
and are in neither the deletion list nor the download.

That is the real lesson, and it changes the fix. **Build the list from the
database structure itself**, not from tables somebody happens to name, or the
next table added repeats this exactly. `tblNoticeboardUploads` also points at a
file on disk — deleting the row is not enough, or the picture outlives every
record that it existed.

---



**Updated 10 September 2026, later in the day.** Working branch `claude/alpha-wip`.
Nothing is open against `alpha`. The owner has NOT installed on the live server
yet and wants the product fleshed out as much as possible first — that changes
what is worth doing, because anything cheap now and expensive once real customer
data exists is at its cheapest moment.

### The current queue

| # | Task | Issue | Status |
| --- | --- | --- | --- |
| 1 | Deep analysis and plan (Fable, run one after another) | — | in progress |
| 2 | Admin area unreachable + widget gated in the SAME commit | #483, #478 | ✅ `20d7a05` |
| 3 | Maintenance mode locks administrators out | #477 | ✅ `f0eb9d5` + `bf2399e` |
| 4 | Five Export CSV buttons crash before producing anything | #482 | ✅ `f0eb9d5` |
| 5 | Codex review loop until a round comes back clean | — | ⏸️ queued for 18:33, limit hit |
| 6 | Documentation sweep (.md, in-app help, OpenAPI/Swagger) | — | ✅ done |
| 7 | GitHub issue sweep, open and closed | — | in progress |
| 8 | Memory, context and this handoff | — | ✅ updated |
| 9 | *(added)* A twelfth automatic check for the shadowing fault | #483 | ✅ `be9afcd` |
| 10 | *(added)* The API was reporting version 1.2.0, not 1.4.0 | #482 | ✅ `eb0443c` |

### A note on commit `be9afcd`, so the history makes sense

That commit's message describes the new automatic check. It ALSO contains a
documentation agent's edits to `CHANGELOG.md` and `DEV_NOTES.md`, which the
message does not mention. The cause was mine: `git add -A` while a background
agent was still writing in the same working folder. Everything landed intact and
was checked afterwards, but the commit boundary is not what the message implies.

The practice that avoids it — stage by explicit path while any agent is running,
never `git add -A` — is written into the memory notes.

### What was checked and deliberately NOT changed

- `FEATURES.md` needed no change. The only mention of embeddable widgets sits
  inside a dated historical snapshot of what an earlier release contained, not a
  claim about today, and the countdown widget genuinely still works. Verified
  rather than taken on the agent's word.
- Both remaining widget addresses (`/widget/countdown` and
  `/widget/countdown.json`) reach the portal correctly. Only the calendar one
  was removed.

### ⏸️ BOTH review systems hit their limits today

- **Fable** reached its MONTHLY SPEND limit. Deep analysis fell back to Opus,
  which is what the owner's standing instruction says to do. Retry Fable on the
  next deep-analysis run.
- **Codex** reached its usage limit at about 15:40, resets **18:32**. A review of
  commits `20d7a05`, `f0eb9d5` and `bf2399e` is queued to run automatically and
  retry every five minutes. **Those three commits are NOT yet reviewed** and must
  not be treated as signed off until that round has run and come back clean.

### What was fixed, and the one thing that was nearly missed

The four faults are described further down. Two things worth carrying forward:

**The widget could not be fixed the way the plan assumed.** The plan said delete
the shadowing folder. But `/widget/countdown.js` is a script other people's
websites already load from this server — moving it breaks their pages with no
warning. So the folder stays and the dead ADDRESS was removed instead. That
turned out better anyway, because it exposed a second fault: the page's queries
returned INTERNAL events, since it lacked the `isPublic = 1` filter every other
public calendar page already had.

**The maintenance fix was a half-fix at first.** Opening the sign-in page was not
enough: anyone with two-factor turned on typed the right password and was sent to
`/auth/2fa/verify`, straight back into the same wall. Fixed in `bf2399e`. Signing
in is a journey, not a page — and a half-fix that looks whole is worse than none,
because it fails only for the administrators careful enough to use two-factor.

### Four faults confirmed against the code, not taken from issue text

A 35-agent assessment checked every open work-queue issue against the actual
code, then challenged each assessment. Four faults came out of it, and **none of
them is what its issue title says**. Every one below was then re-verified by
hand before being acted on.

1. **The Admin area never reaches the portal (#483).** The web server's rule
   says a request matching a REAL FOLDER is answered by the web server itself
   and never handed to the portal (`RewriteCond %{REQUEST_FILENAME} !-d`).
   `web/public_html/admin/` is a real folder, and there is a seeded address
   `admin`. So an administrator clicking Admin gets a folder listing or a
   refusal, and nothing appears in the error log because no portal code ran.

2. **Fixing that on its own would publish something to the internet (#478).**
   `web/public_html/widget/` shadows the seeded address `widget`, which points
   at `calendar/widget.php` with **no login required**. That file contains no
   access check of any kind — verified. Right now nobody can reach it BECAUSE
   the folder is in the way. Delete the folder as a tidy-up and it goes live.
   **These two must land in one commit.**

3. **Maintenance mode locks administrators out of their own portal (#477).**
   The seeded sign-in address is `login`. `Maintenance.php:55` allows
   `auth/login`, which is the target FILE PATH, not an address. The holding
   page's own sign-in link points at the same non-address. Maintenance mode
   switches itself on whenever the code is newer than the database — every
   upgrade. One line to fix.

4. **Five Export CSV buttons crash instantly (#482).** `Router.php:138` puts
   only `$mysqli` and `$SETTINGS` into a page's scope. These five reach for
   `$db`, which is never defined: `attendance/export.php`,
   `admin/users/export.php`, `admin/activity/export.php`,
   `leadership/export.php`, `expenses/api/export.php`.

### Decisions taken without asking

- The public calendar widget will be **off by default**, opt-in per site.
  Nothing depends on it today because it is unreachable, and switching
  something public on by default during a bug fix is the wrong direction.
- #480 (minimum password length) is still the owner's decision and is not in
  this batch.

### Not verified, and not claimed

- The GDPR erasure gap (#479) — reportedly five tables missed. Not checked here.
- The database compatibility result that downgraded #475. The assessment itself
  flagged this as unverified.
- The end-to-end migration harness has not run since the disk filled up (see
  below).

### ⚠️ This machine has run out of disk space

926 GB disk, about 2 GB free. Docker's daemon is down as a result, so the
end-to-end migration harness CANNOT run. `~/_ENCODES` is 301 GB and is the
obvious candidate, but it is the owner's data and has been left alone. Only
temporary files created by this session were removed. Any commit made while
this is true says plainly that the harness did not run.

---



**Updated 10 September 2026.** Working branch `claude/alpha-wip`, cut from the
updated `alpha`. **Nothing is open against `alpha`** — pull request #473 merged
and deployed earlier today.

### This session, part two: the work queue

Fifteen items were agreed and added to the queue. **Every one now has a GitHub
issue.** The index is `.claude/plans/work-queue.md`; the issues carry the detail.

| Item | Issue |
| --- | --- |
| Drop end-of-life MySQL 8.0; target MySQL 9.7 / MariaDB 12.3 / PHP 8.5 | **#475** ⛔ blocked |
| Repair version + changelog automation on `alpha` | **#476** |
| "Check my portal" page for administrators | **#477** |
| Make switching an app off actually switch it off | **#478** |
| Prove the "delete my data" list is complete | **#479** |
| Decide the minimum password length | **#480** ⛔ needs a decision |
| Stop three migrations switching apps back on | **#481** |
| Document the other 29 data endpoints | **#482** |
| Finish the mobile pass on a real phone | #225 (existing) |
| Small clean-ups worth doing together | **#483** |
| Warn when a deploy is about to remove files | #107 (existing) |
| Keep a history of settings changes | **#484** |
| In-app messaging | #304 (existing) |
| Update the self-hosted Swagger UI | **#486** |
| Re-verify the database structure before the first customer | **#487** |
| *(added)* Translation is a shell | **#485** |

### Latest task: the database version question, answered from inside the product

**Shipped 10 September 2026, commit `64ad87a`, issue #489.** The owner asked
three things: check DreamHost's MySQL version again against their own sources;
add a database check to the installation wizard; and add an admin-only page
showing server information — database version, connection details and the PHP
report.

**On the first**: DreamHost's own documentation says only **"MySQL 8"** and
never a point release, and explicitly answers *"Can I update the MySQL
version?"* with *"No"*. Their pages were checked again on 10 September 2026.
**So the documents cannot answer this**, and waiting for them to is pointless.
One earlier assumption corrected: **DreamHost shared hosting does not offer
MariaDB.**

**So the product now asks the server itself.** That is what unblocks #475.
Somebody needs to open `/admin/system-info` on the live site and report the
version; then #475 can be planned properly.

What was built:

| Piece | Where | What it does |
| --- | --- | --- |
| The judgement | `web/_core/DbServer.php` | Reads the database product and version and says: supported, worth knowing about, or too old to run on. **The one place that decides.** Rules are constants at the top of the file. |
| Its self-test | `tools/db-server-selftest.php` | 15 real version strings, no database needed. `php tools/db-server-selftest.php` |
| Wizard check | `web/_install/index.php` (the `db_config` handler) + `db_server_banner.php` | Asks the moment it first connects — the earliest it can be known, since before that nobody has typed any database details in. |
| The page | `web/_apps/admin/system-info/index.php` | Route `/admin/system-info`. Any administrator. |
| The PHP report | `web/_apps/admin/system-info/phpinfo.php` | Route `/admin/system-info/phpinfo`. Umbrella administrators only. |
| Routes | `web/_sql/188_server_information_page.sql` | Two route seeds. Folded into `full_schema.sql`. |

**Three decisions worth not re-litigating:**

1. **The installer warns but does not block** on an out-of-support database. It
   stops only when the database is genuinely too old to hold the schema. On
   shared hosting the customer cannot change the version, so blocking would
   only lock them out of their own portal.
2. **The health page's traffic light stays green** on an ageing database. That
   page is polled by uptime monitors, and a permanently amber light is one
   everybody learns to ignore — so a real problem arriving later would land in
   a warning nobody reads.
3. **The database password is not on the Server Information page and is not
   redacted** — it is never loaded into the page at all. Connection facts are
   asked of the live connection rather than read from the credentials file, so
   no future edit to that page can print something it never had.

**The one real defect found and fixed during this work**, worth reading because
it would have been easy to ship: leaving `INFO_ENVIRONMENT` and
`INFO_VARIABLES` out of `phpinfo()` is **not enough**. When PHP runs as an
Apache module, the `apache2handler` entry inside the components section prints
"Apache Environment" and "HTTP Headers Information" **of its own accord** — and
the second contains the request's `Cookie` header, which is the reader's
session token. It would have arrived by a different door, on the page most
likely to be pasted into a support ticket. The report is now captured and those
tables removed before anything reaches the browser, and as a final check the
page refuses to render at all if `session_id()` appears in the output.

Two related things checked rather than assumed: `phpinfo()` masks nothing (a
`mysqli.default_pw` and a `sendmail_path` carrying a password both printed in
full), and the command line prints plain **text** not HTML, so a filter tested
only from the command line silently matches nothing and looks like it works.
Verified through `php -S` instead.

**Verified:** `php -l` clean on every touched file · all 11 audit checks pass ·
the new self-test passes 15 of 15 · the end-to-end migration harness passes
every phase against MySQL 8.0.36. **The Codex round for this work has not run
yet** — the account was still rate-limited. See the section below.

---

### ⏸️ One thing left undone: a final Codex review round

The review rule says keep going until a round finds nothing. Four rounds ran on
this documentation and each found something real. **The account hit its usage
limit part-way through round four** (resets 1:30 PM). Everything round four
surfaced before stopping has been fixed, but **a clean pass has not been
observed** — so treat this documentation as reviewed-and-corrected rather than
signed off.

Re-run when the limit clears:

```bash
codex exec --skip-git-repo-check "$(cat <the brief>)" < /dev/null
```

The briefs used are in the session scratchpad as `codex-brief-5.txt` through
`codex-brief-8.txt`, and the reviews as `codex-review-5.txt` onward.

**What the four rounds caught is worth reading**, because the pattern was mine
rather than random: narrow a search, then state the result as an absolute. That
produced one flatly false claim that reached a GitHub issue
(`Translation::translate()` "has no caller anywhere" — the unreachable file does
call it), then the same mistake a second time, plus several guarantees the
evidence did not support. It also caught that migration 187's own header — which
had already merged — still described the approach that was tried and rejected.

### ⛔ Two things are blocked on the owner

**1. Which MySQL 8 the live site is running** — this decides most of #475.

DreamHost's published documentation says shared hosting runs **MySQL version 8**,
offers no MariaDB, and does **not** let a customer choose the version — a newer
one needs a Dedicated or DreamCompute plan. (An earlier draft of this note
guessed MariaDB. That was wrong, and it was corrected after a review.)

So "target MySQL 9.7" cannot mean what customers on shared hosting will run. It
can only mean what the code supports. Still worth having, but forward planning
rather than a fix.

**The question that matters is which MySQL 8.** On **8.4 LTS**, support runs to
2029 and there is no urgent problem. On **8.0**, the product is on a database
that stopped receiving security fixes in April 2026 — an owner decision: stay
put, move hosting tier, or support other hosts.

**PHP is settled.** A `phpinfo()` page from the server was shared on
10 September 2026 showing `mysqlnd 8.5.5` — and mysqlnd's version number is
PHP's own, because it is the driver bundled inside PHP. (Checked rather than
assumed: a local PHP 8.5.10 reports `mysqlnd 8.5.10`.) So the server runs
**PHP 8.5.5**, which is the target version. Nothing to do there.

That same page does **not** reveal the database server version — `mysqlnd`
describes the client library inside PHP, not the server it talks to. The same
driver version appears whether the server is 5.7, 8.0, 8.4 or 9.x.

**Quick to check:** the portal already reads and shows the database version on
the admin dashboard (`web/_apps/admin/index.php:133`) and on the health page.
Sign in to the live site and look.

Still true either way: no minimum database version is enforced, the automated
test covers only MySQL 8.0.36, and MariaDB is not covered by any test here.

Also unsettled: how far #475 should go. Raising the minimums and testing against
the new versions is a few days. Adding conditional code so one codebase runs
correctly on old and new versions is considerably more and adds a branch to
maintain forever. Recommendation recorded in #475: do the first stage on its
own, because it is the foundation the second would need anyway.

**2. The minimum password length** — #480. An existing site could still be
enforcing an 8-character minimum while the documentation, the help page and the
code's own fallback all say 12. Raise them automatically, or tell administrators
and let them choose? Recommendation in the issue: tell them. The 8 is almost
certainly the ghost of an old seed rather than anybody's decision, but that
cannot be shown for any particular site.

### 🔴 The finding worth reading before anything else

**#485 — automatic translation of user-written content has no way in.** An
administrator can pick a paid provider (Anthropic, OpenAI, Google, DeepL), enter
an API key and set a spending cap. A member can go to their account page and opt
in. **Neither does anything**, because nothing a user can reach ever calls the
translation code.

Be precise about two things here, because loose wording caused a false claim in
the first draft of this note. The only caller of `Translation::translate()` is
`web/_apps/api/translate.php` — so it is not true that there is "no caller
anywhere". That file simply cannot be reached: addresses starting `api/` go to
ApiRouter, which needs `api/{app}/{action}` and looks elsewhere. And this is
**separate from interface translation** (`I18n` and the `t()` function), which
works normally — the portal does translate its own screens.

Two reasons it is urgent rather than merely wrong. It goes to a first customer
soon, and somebody could enter a billable credential for something that will
never make a request. And it must be settled **before** #483, because that
clean-up deletes the unreachable file that is the only surviving description of
how it was meant to work.

Note migration 158 removed that file's routing-table row, but the address was
**already** unreachable — `api/` paths never consult the routing table. So 158
tidied a dead row; it did not break a working feature.

Issue #278 was closed as delivering this. It has been commented, not reopened.

**This is the fourth time this pattern has appeared** — #322 web push, #273
livestream, #278 translation, and six unreachable help guides. Built, reviewed,
merged, closed; nobody could reach it. Recorded in memory as
`webms-shipped-but-unreachable`. It is the strongest argument for #477.

### Suggested order when work resumes

1. **#476** — about an hour, and stops `alpha` reporting the wrong version.
2. **#485** — decide before #483 destroys the evidence.
3. **#477** — widest reach; would have caught most of this audit.
4. **#481, #483** — small and mechanical.
5. **#475** once the hosting question is answered, then **#487** after it.

## 1. The branch clean-up (done)

Six branches were sitting on GitHub with no pull request attached.

**`claude/venue-b` … `claude/venue-f`** were five workers building one job — the
Venues app — side by side from a shared starting point. Their output was
gathered into `claude/venue-bookings` and merged into `alpha` as pull request
**#431** on 28 August. Checked file by file, every one of them was either
already identical to `alpha` or an older version `alpha` has since improved. A
trial merge of each produced conflicts that would have **undone** later work —
most importantly the room-aware booking coverage added for issue #436 in pull
request #452. **Nothing was outstanding; merging would have caused a
regression.** All five deleted.

**`claude/dependabot-alpha-beta-branches-q24zd7`** had a misleading name — no
dependency updates and no program code, only twelve planning documents that
existed nowhere else, covering work that has since shipped in nine pull
requests (434, 441, 444, 445, 446, 451, 452, 454 and 455) and including two
security reviews. Those twelve documents were copied onto `claude/alpha-wip` under
`.claude/plans/gap-items/`. The branch was then deleted.

The full method and evidence is in `.claude/plans/branch-audit-2026-09-07.md`.
**The lesson worth keeping: never judge a branch by its commit messages.**

## 2. Swagger UI + the /api-docs page (done — commit `8f21094`)

Three problems, all fixed:

1. **The page broke without internet access.** Swagger UI was only ever fetched
   from a public content delivery network, with no local copy to fall back on —
   unlike Bootstrap and Font Awesome, which have always had one. On a network
   that blocks public CDNs the page was a blank white screen. The three files
   are now committed under `public_html/assets/vendor/swagger-ui/` and wired
   into the existing fallback machinery. Each was verified to hash to the SRI
   value `Asset.php` already recorded, so the local copy is provably identical
   to what the CDN serves.
2. **"Try it out" could never write anything.** The page read the anti-forgery
   token from a `<meta name="csrf-token">` tag, but the page deliberately
   bypasses the shared template that emits that tag — so the token was always
   empty and every write was refused with "CSRF check failed". The page now
   emits its own. And because the portal replaces the token every time it
   accepts one, `ApiAuth::requireWrite()` now hands the replacement back as
   `meta.csrfToken` so a second write does not fail.
3. **The page phoned home** to `validator.swagger.io`. Switched off.

New setting `api.docs.local_assets_only` (migration 185, seeded off) skips the
CDN entirely for sites whose network blocks it.

## 3. PHP on the development machine (done)

There was no PHP installed, so Visual Studio Code could not check files and the
build's hard lint gate could not be reproduced locally. Installed **PHP 8.5.10**
via Homebrew (the project's exact target version), pointed the editor at it via
`.vscode/settings.json` (which git ignores, so it stays on this machine), and
confirmed all 785 PHP files pass `php -l`.

`DEV_NOTES.md` now has **"Installing PHP on your own machine"** with
step-by-step instructions for macOS, Windows, Linux and Raspberry Pi.

---

## 4. What the audit found, and what has been done about it

Audit reports so far, in the session scratchpad under `analysis/`:
`01-inventory.md` (apps, routes, handlers) and `02-core-schema.md` (core
classes, migrations, tables, CI). Every finding below was independently
re-verified before being acted on.

### ✅ Already fixed — commit `6c01c47` (migration 186)

- **The notification preferences page could not be opened.** Two pages were
  both called "Notification preferences"; migration 093 gave the address to
  the newsletter-only one, hiding the real page. Browser push opt-in was
  therefore only reachable from the livestream page. The newsletter checkbox
  is now a card on the real page, the newsletter-only page is deleted, and
  the address points back where it belongs.
- **The livestream channels and schedule page could not be opened** — with it
  went the only button that tells subscribers "we are live now". It now has
  its own address, `/admin/livestream/channels`, linked from the analytics
  page.
- **Six help guides nobody could find** — Venue Bookings, Forms Builder,
  Reports, Admin First Steps, Disaster Recovery and Getting Support existed
  with working addresses but no link anywhere. All 18 guides are now on the
  Help Centre index.
- Removed four settings that switched on API endpoints whose handler files do
  not exist.

### ✅ Already fixed — commit `82241bc`

- **Six menu and dashboard links led to "page not found"** (`/expenses`,
  `/kids`, `/reports`, `/salvation`, `/worship`, `/webhooks`). New
  `Router::routeExists()` means those generated links are left out when a
  successful lookup finds no registered address for them, and a new optional `landing` field on an app's registry entry
  says where to link when the app's own prefix is not a page. The `route`
  field could not simply be corrected — it is the prefix used to decide which
  app owns a page for the on/off switch.
- **An app switched on at `/admin/apps` never appeared in the menu.** That
  screen writes `'1'`; the menu insisted on `'true'`. Both now accepted.
- **"Run all pending migrations" would have put demo data into a live site.**
  `Migrator::allFiles()` accepted any `.sql` file, so `full_schema.sql` and
  `demo_data.sql` always showed as pending. Both `allFiles()` and `runOne()`
  now accept only numbered files.
- **Four of the eleven safety checks were never run by any workflow.** All
  four now run on every pull request.

### ⚠️ SUPERSEDED — this section described problems that are now FIXED

**Everything that used to be listed here has shipped**, in pull request #473
(merged 10 September 2026). The section has been removed rather than left in
place, because it did more than go stale: it recommended an approach that was
later tried and **proved wrong**.

For the record, so nobody repeats it:

- It proposed de-duplicating the settings table by **keeping the newest row**.
  That would have deleted saved payment credentials. Several screens find their
  row with an unordered `SELECT … LIMIT 1` and update that one, which in
  practice is the OLDEST copy. The rule that shipped prefers the row that looks
  edited, then the most recently written, then the newest.
- It proposed a **STORED** derived column. MySQL refuses a cascading foreign key
  on the base column of a STORED derived column, and `tblSettings.siteID` has
  one, so `full_schema.sql` would not load at all. The column that shipped is
  **VIRTUAL**.
- It proposed `COALESCE(siteID, 0)`. The value that shipped is **-1**, because a
  site numbered 0 is not impossible on an imported database, and 0 would then be
  confused with "applies to every site".

Both mistakes were caught by the Codex review, not by any automated check —
every one of those checks passed on the wrong version. That is the argument for
the review rule in `.claude/CLAUDE.md`.

What actually shipped is described in the changelog, in migration 187, and in
`DEV_NOTES.md` under "The settings table". Issues #466, #467, #468, #469, #470
and #471 are all closed with evidence.

## 5. Verified-clean baseline

Measured on this branch, so a later regression is obvious:

- **785** PHP files, all pass `php -l` (the exact command CI uses).
- **All 11** checks in `tools/audit-checks/` pass.
- **559** live routes, **0** pointing at a missing file.
- **566** settings keys seeded, **0** read without being seeded and without a
  fallback.
- **184** numbered migrations (000-184; 168 and 169 were never used), all
  re-runnable, **no** MariaDB-only syntax.
- **209** database tables, **0** column-name mismatches.
- **54** app directories, **47** in the installable-app registry, **19** help
  pages, **62** API handlers, **63** documented API operations.

---

## 6. How this session is being run

- **Deep analysis and planning:** sequential Fable 5 agents, never parallel.
  Fall back to Opus only if Fable is unavailable, and retry Fable next time.
- **Building:** Sonnet or Haiku; Opus only when genuinely hard.
- **Everything written in plain English** — replies, comments, commits, pull
  requests, issues, documentation, in-app help. Now a standing rule in
  `.claude/CLAUDE.md`.
- **After each piece of work:** commit and push, update the GitHub issue, update
  `.claude/`, update this file.
- **One pull request only**, at the end, into `alpha`.

## 7. If you are picking this up cold

1. `git checkout claude/alpha-wip && git pull`
2. Read `.claude/plans/branch-audit-2026-09-07.md` for why the branches went.
3. Read the audit reports in the session scratchpad under `analysis/` —
   `01-inventory.md` is the ground truth for apps, routes and handlers.
4. The findings in section 4 above are the work queue. None is fixed yet.
5. `php -l` and `python3 tools/audit-checks/*.py` are the local gates. Both were
   clean at the last commit.
