# D-148 · A visitor erasure request records a required tick and a short note on how the person was checked

**Date:** 2026-11-12  
**Status:** in force

**Question:** under D-140 an administrator files an erasure request for the e-mail address of a visitor who
suggested an event without an account, after checking off the portal that it really is that person. How is that
check recorded: a required tick only, or a tick plus a short note on how they checked?

**Answer:** a tick plus a short note. Nothing is filed unless the administrator ticks that they checked, outside the
portal, that the request comes from the person who uses the address, AND writes a short note on how (for example
"replied from that address"). The note is kept in the request's permanent record with the administrator's account
number.

**Rules out:** filing with the tick alone; filing with no record of the check.

**Where:** #964 — `Portal\Core\GdprEraser::fileVisitorAddressRequest()`, the erasure requests page. Related: D-140.
The note is kept for ever in the sealed erasure audit trail, so the page tells the administrator not to put the
person's details in it.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
