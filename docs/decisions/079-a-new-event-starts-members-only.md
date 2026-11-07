# D-079 · A new event starts members-only

**Date:** 2026-11-07  
**Status:** in force

**Question:** each event's "public" box (`tblEvents.isPublic`) starts ticked, so the
portal's own signed-out calendar, the countdown widget and the calendar feed show every
published event unless somebody unticks it. Should a new event start unticked (#493)?

**Answer:** **yes — a new event starts unticked, members-only, until somebody ticks it.**
Events that already exist keep what they have. The owner, 7 November 2026, choosing the
recommended option. Built as its own package straight after #493's P1.

**Rules out:** keeping new events public by default.

**Where:** #493 (the follow-on package after P1). Related: D-057.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
