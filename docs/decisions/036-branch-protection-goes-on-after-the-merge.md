# D-036 · Branch protection goes on after the merge

**Date:** 2026-11-01  
**Status:** superseded

**Question:** should the required checks (#474) go on before or after this
branch is merged?

**Answer:** **after the merge**, not before.

**Measured at the time:** `main`, `beta` and `alpha` each had a protection
object with no required status checks, no required reviews and administrators
not enforced — so a pull request into `main` could merge with every check red.
An earlier claim that a deleted Psalm check was blocking every pull request was
**false**: nothing was required at all.

**SUPERSEDED BY D-037.**

**Where:** #474.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
