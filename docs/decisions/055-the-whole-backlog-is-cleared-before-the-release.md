# D-055 · The whole backlog is cleared before the first release

**Date:** 2026-11-05  
**Status:** in force

**Question:** the 63 medium and 52 low priority issues are the bulk of the
remaining work — roughly 36–46 packages against 14–20 for every high priority
item combined. D-041 decided which half-built FEATURES go into the first
release, but nothing had ever said whether the backlog itself must be emptied
first. Should it be cleared, or cut down to the security and bug items, or
triaged first?

**Answer:** **everything, before the release.** The release ships with an empty
backlog. The owner, 5 November 2026.

**Rules out:** three narrower options that were offered and declined —

* doing only the 11 security issues and the 38 bugs and deferring the 38
  enhancements and 20 infrastructure items (the recommendation, on the grounds
  that it cut the work by roughly two thirds and lost nothing, because every
  deferred item keeps its issue);
* doing only the 11 security issues;
* reading all 115 medium and low issues first and coming back with a grouped
  recommendation.

So there is **no backlog triage**. An issue is not deferred because it is small
or because its label says `enhancement`; the queue is worked to empty.

**What this costs, said plainly, because the owner was told it and chose it
anyway:** it roughly triples the work left before the release, so the release is
a long way out at the current rate. And the longer this branch runs unmerged,
the larger the single comprehensive Codex review at the end becomes (D-007,
D-035) — which is already the known weak point of deferring that review.

**What it does NOT change:** issues raised FROM HERE ON are still judged on
their merits. This decision is about the backlog as it stood on 5 November 2026
(131 open), not a promise that no issue may ever be deferred again — a new
issue raised during the remaining work can still be deferred with a reason, as
#225 was by D-053 on the same day.

**Where:** the release queue; `.claude/HANDOFF.md`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
