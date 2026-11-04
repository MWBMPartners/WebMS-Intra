# D-034 · A limit recorded beside the code rather than forced

**Date:** 2026-11-02  
**Status:** in force

**Question:** an erasure instruction is correct but can never match, because
nothing writes the column — delete the instruction, or change the page?

**Answer:** neither. Record the limit beside the instruction. Whether the
feature should write that column is a separate product question.

**Rules out:** deleting a correct instruction because it is unreachable today,
and changing a page to make a test's instruction matter.

**Where:** `tblCareVisit.followUpAssignedToID`, `tblKidCheckins.pickupName`;
#708.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
