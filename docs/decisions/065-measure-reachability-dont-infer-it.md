# D-065 · Measure whether code runs; do not infer it from the source

**Date:** 2026-11-05  
**Status:** in force

**Question:** #763's guard exists to stop somebody later making three regions of
code silently stop running. It does that by reading the source — pinning the
regions' text and their nesting. After eight review rounds it is roughly 1,100
lines guarding about sixty, it still refuses correct edits, and the faults it
cannot see (`while (true) { }` above a region, `trigger_error` at
`E_USER_ERROR`, a conditional `require` of the file) are ones no reader of
source can ever catch. Keep it and keep fixing it, cut it back and build a test
that drives the page, or cut it back and defer that test?

**Answer:** **cut it back to what it can do honestly, and build the test now.**
What stays is presence and ORDER — that the calls exist, that they sit after the
line which starts the session, and that start-up makes neither of them — which
has never produced a single finding in eight rounds and cannot cry wolf. What
goes is the text pin, the nesting pin, the exit counting, the glue rule, the
sidecar and the PHP tokeniser probe: about 1,100 lines. What replaces them is an
HTTP test that signs in, loads the account page, chooses a language, saves, and
reads the column back. The owner, 5 November 2026.

**Why this is MORE protection, not less.** Every wrapping the static pin caught
would also fail the HTTP test, because a region wrapped in something that never
runs does not render a control or store a language. The HTTP test additionally
catches the four classes the pin provably cannot. And a check that refuses
correct work gets switched off (#483), so the pin's real-world value was already
falling.

**The transferable rule, which is the whole point of recording this.** **Where
the property is "this code RUNS", drive it. Where it is "this code LOOKS like
this", pin it.** Reachability is behavioural. I chose a static guard for a
behavioural property and then spent eight review rounds making the wrong
instrument sharper — 52 findings, not one of them in the fix. The instrument was
the mistake, not the sharpening.

**Rules out:** a ninth round on the static guard; growing the probe further;
and any claim that a source-reading check can establish that code is reached.

**What this costs, said plainly:** between this decision and the HTTP test being
built there is a window in which the static check proves only presence and
order. It is a short window because the test is built in the same package, and
no customer installation exists yet.

**What it does NOT settle:** whether other packages' static guards should be
reviewed the same way. #485's pinned script is the closest relative, and its own
justification is explicitly that NOTHING executes that file — which is the
opposite situation, and is why it stays.

**Where:** #763, #781, `tools/audit-checks/check_member_language_applied.py`.
Related: D-064, D-051, #483, #485.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
