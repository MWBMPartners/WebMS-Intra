# D-045 · Report at a commit boundary, and keep going in the same turn

**Date:** 2026-11-04  
**Status:** in force

**Question:** is a commit a place to stop and report?

**Answer:** no. **It is a place to SAY WHAT LANDED and carry straight on.**
Commit, give a few lines on what landed and what is next, and **in the same turn
make the next tool call.** The owner's words, having had to say it more than
once: *"as stated previously report on those commit boundaries, but continue to
run, dont stop! ive stated this previously!"*

**Rules out:** ending a turn after a commit while queued work remains. "A clean
working tree" and "everything is committed and pushed" are status lines, not
conclusions — a clean tree is the CHEAPEST moment to start the next thing,
because nothing is half-done.

**Why it kept happening**, named so it can be caught rather than just resolved
against: a commit boundary FEELS like a finish. The tests are green, there is
something satisfying to report, and the report gets written as a closing
statement instead of an aside. The cost falls on the owner, who then nudges for
work that was already decided — and the nudge is the whole reason this is a
decision rather than a preference.

**Still the only reasons to end a turn**, unchanged: a question only the owner
can answer (ask it and carry on with everything not blocked), or something
genuinely destructive that needs approval first.

**Where:** `~/.claude/CLAUDE.md` (so it binds every project on the device),
`.claude/CLAUDE.md`, `.OpenAI/CONTEXT.md`. Related: D-009 (never wait for a
nudge), which this sharpens — D-009 said not to wait for permission; this says a
commit is not permission to stop either.

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
