# D-115 · An API key with attendance-write may still delete attendance sessions

**Date:** 2026-11-10  
**Status:** in force

**Question:** D-114 limits deleting a past attendance session to administrators, the attendance role
and the person who recorded it — "nobody else". An API key (a password an administrator issues to
another program so it can use the portal directly) with permission to write attendance could delete
any session. A key is made only by an administrator, but it is not a person and keeps working even if
that administrator later loses their role. Should API keys keep being able to delete sessions?

**Answer:** **yes.** A key is the administrator's own tool for an integration and acts with the
administrator's authority: a key with attendance-write may delete any session, as before; a key
without it cannot. D-114's "nobody else" is about people. The owner, 10 November 2026, choosing the
recommended option.

**Rules out:** refusing every API key a delete.

**Where:** #529 — `web/_apps/attendance/api/delete.php`. Clarifies D-114.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
