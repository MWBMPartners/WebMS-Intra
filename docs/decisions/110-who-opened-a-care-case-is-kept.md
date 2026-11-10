# D-110 · Who opened a pastoral care case is kept when that worker is erased

**Date:** 2026-11-10  
**Status:** in force

**Question:** D-016 keeps the name of who made a pastoral visit or recorded a safeguarding check
when that worker is erased. Should the name of who OPENED a pastoral care case
(`tblCareCase.openedByID`) be kept too? It was being removed.

**Answer:** **keep it.** Opening a care case is part of the same pastoral record; keeping who opened it
keeps the case accountable after the worker leaves. The owner, 10 November 2026, choosing the
recommended option.

**Rules out:** removing who opened a case when that worker is erased.

**Where:** #703 — `GdprEraser`, `personal-data-catalogue.php`, a saved-name column. Extends D-016.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
