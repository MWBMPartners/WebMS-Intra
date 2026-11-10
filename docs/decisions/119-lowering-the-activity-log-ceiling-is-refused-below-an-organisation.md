# D-119 · Lowering the installation's activity-log period below an organisation's is refused

**Date:** 2026-11-10  
**Status:** in force

**Question:** if the installation's administrator lowers the installation-wide activity-log period (for example from
"never delete" to 90 days) while some organisations have chosen a longer period, should the new limit apply at once,
or be refused?

**Answer:** refuse it, naming the organisations, as the giving-records ceiling does (D-032). Nothing is deleted until
each of those organisations lowers its own period first.

**Rules out:** applying a lower installation figure over an organisation's longer one at save time (as #932's first
build did). The reader still caps an organisation's stored figure by the installation's, so a figure that got past
the save (written by SQL, or restored) is held to the limit rather than obeyed — the two are not in conflict: this
decision is about the SAVE.

**Where:** #932 — `ActivityLogRetention`'s installation save. Related: D-032, D-074, D-117, D-118.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
