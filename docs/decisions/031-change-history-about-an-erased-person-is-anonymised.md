# D-031 · The change history about an erased person is anonymised, not deleted

**Date:** 2026-11-02  
**Status:** in force

**Question:** when a person is erased, should the audit-trail rows about them
be deleted or emptied?

**Answer:** ANONYMISE, not delete. The three value columns are emptied; who
acted, when, which table and which record all stay.

**Rules out:** deleting the rows, which would destroy a third party's record of
what was changed; and adding those columns to the ordinary instruction's
`nullCols`, which matches `userID` and would empty the before-and-after of every
change this person made to somebody ELSE's record while still reaching no row
about them.

**Where:** `GdprEraser::eraseAuditTrailAboutPerson()`; #707.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
