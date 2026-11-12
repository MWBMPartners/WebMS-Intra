# D-170 · A repair step is built for erased accounts re-saved before #977

**Date:** 2026-11-12  
**Status:** in force

**Question:** Before #977 an administrator could re-save an erased account and make it look un-erased; #977 stops that from now on, and no live installation exists (D-135). What should be done about repairing such accounts?

**Answer:** Build a repair step: an action that restores such an account to its erased state.

**Rules out:** Closing the issue as not needed; relying on a runbook entry alone.

**Where:** #986 — the erasure requests page and `Portal\Core\GdprEraser`. Related: #977, D-135.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
