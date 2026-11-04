# D-024 · The version number moves to release time

**Date:** 2026-11-04  
**Status:** in force

**Question:** how to finish #474 — the two jobs that write straight to `alpha`
block it.
**Answer:** **set the version at release time instead.** Stop raising it on every
push; `package.yml` sets it once when a release is built. Then no job writes to a
guarded branch, #474 and #476 both close, and no stored credential is needed.
`alpha`'s number is also brought back in line (1.4.0 against 1.4.1 elsewhere).
**Rules out:** creating a long-lived access token; leaving the two jobs broken;
the version number creeping up on every alpha push.
**Supersedes:** D-020 and D-021.
**Where:** #474, #476, #748 — not yet implemented.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
