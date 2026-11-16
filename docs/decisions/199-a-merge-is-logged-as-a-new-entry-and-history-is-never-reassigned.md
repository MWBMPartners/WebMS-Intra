# D-199 · A merge is logged as a new activity entry; earlier history is never reassigned

**Date:** 2026-11-16  
**Status:** in force

**Question:** when two accounts are merged (#1064), the build moved "who made each change" on the change history and the activity log from the duplicate to the account that stays. That rewrites a record D-116 keeps for ever, and shows the surviving account acting before it existed. What should happen to the history?

**Answer:** the owner decided: do not change the historic action by reassigning "who did it" to the surviving account. Instead log the merge as a NEW activity-log entry that states that the person on the earlier actions is now the surviving account (the two account numbers and fixed words only, no names or e-mail addresses). That gives a truly accurate record: old rows stay exactly as written under the duplicate's number, and the merge entry says who they now belong to. Reading, exporting and erasing a person follows completed merges, so an erasure of the surviving account also reaches records under the numbers merged into it.

**Rules out:** rewriting the actor column of the change history or the activity log at merge time; leaving an erasure unable to reach the duplicate's history.

**Where:** #1064 (`AccountMerge`, the merge activity entry, `GdprEraser` following merged-from accounts). Related: D-116, D-190 (and #707: emptying the values on rows about an erased person stays).

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
