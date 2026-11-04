# D-029 · Rows a restore could not put back wait with no timer

**Date:** 2026-10-31  
**Status:** in force

**Question:** should the rows a restore could not put back be deleted after
some period?

**Answer:** no timer at all. They wait until an administrator clears them.

**Rules out:** a clock that deletes them — that would be the same silent loss
this feature exists to prevent, one step on, and it would fall due in the middle
of somebody's recovery. The Backup page's card is the only thing stopping them
being forgotten, and that is accepted.

**Where:** `DbBackup`, `tblRestoreHeldRows`; #694.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
