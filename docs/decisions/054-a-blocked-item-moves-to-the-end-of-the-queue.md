# D-054 · A blocked item moves to the end of the queue

**Date:** 2026-11-05  
**Status:** in force

**Question:** #754 — registering the six workflow files that GitHub has never
run — cannot be finished without a pull request, and no pull request is raised
until the Codex review is done (D-035, D-007). It was sitting at position 10 in
the queue, ahead of the medium and low priority work, where it could only be
reported as blocked each time it came round. Should it stay in priority order
or move?

**Answer:** **move it after the medium and low priority work**, so the queue
never stops on an item that cannot be started. The owner, 5 November 2026:
*"Given 754 is blocked awaiting a PR, can we not maybe move item 10 (754)
after 11 and 12 in aid of our autonomous running?"*

**The general rule this sets:** an item whose only blocker is the pull request
moves to just before the Codex review, rather than holding its priority
position. Priority decides what is done FIRST among things that can be done;
it does not make an item that cannot be started jump over work that can.

**Rules out:** leaving a blocked item in priority order and reporting it as
blocked on each pass, which spends the owner's attention on a status line that
has not changed. It does NOT rule out raising the blocker: if an item is
blocked on something only the owner can decide, that still goes under "Blocked
on me" immediately rather than waiting for its turn.

**What it does not change:** #754's priority label stays `high`, because the
label describes how much it matters, not when it is reachable. Nothing about
D-035 or D-007 changes — the review still comes first, and the pull request
still waits for it.

**Where:** the release queue; `.claude/HANDOFF.md`; #754.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
