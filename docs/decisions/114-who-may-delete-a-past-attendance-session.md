# D-114 · A past attendance session can be deleted by administrators, the attendance role and its recorder

**Date:** 2026-11-10  
**Status:** in force

**Question:** since D-109 ordinary members cannot see a session's headcount, but any member could still
delete any past attendance session — including ones they did not record and ones an administrator had
corrected — without seeing what it held. Who should be able to delete one?

**Answer:** **administrators, holders of the attendance permission (D-106), and the member who recorded
that session** (so they can fix their own mistake). Nobody else. The owner, 10 November 2026, choosing
the recommended option.

**Rules out:** any member deleting any session; administrators and the role only.

**Where:** #529 — `web/_apps/attendance/` (the list's delete control and the delete handler),
`AttendanceAccess`, `api/attendance/delete`. Extends D-109.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
