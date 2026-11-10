# D-109 · Ordinary members do not see attendance sessions' headcounts

**Date:** 2026-11-10  
**Status:** in force

**Question:** D-106 decided that only administrators and a chosen role see an organisation's
attendance TOTALS. But the attendance page listed each recorded session with its own headcount, and
the form for recording or correcting a session showed that session's count, so any member could add
the session counts up to reach the totals (#917). Should ordinary members still see each session's
own headcount?

**Answer:** **no.** Only administrators and the chosen role (D-106) see any headcounts. A member who
records attendance still records it, and sees the count only for the session they are entering —
not the list of past sessions' figures. The owner, 10 November 2026, choosing the recommended option.

**Rules out:** members seeing past sessions' headcounts while only the summed totals are restricted.

**Where:** #917 (raised by #529) — `web/_apps/attendance/index.php`, `record.php` and any list or
form showing a session's count; `AttendanceAccess`. Extends D-106.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
