# D-092 · Approving an event a member of the public suggested keeps it members-only

**Date:** 2026-11-09  
**Status:** in force

**Question:** D-079 says a new event starts members-only until somebody ticks "Public".
An event suggested through the portal's public "submit an event" form is approved on the
moderation page, and pressing Approve made it Public at the same moment, though the page
never showed or mentioned a "Public" choice. What should Approve do?

**Answer:** **Approve publishes it to members only**, like every other new event; an
administrator ticks "Public" on the event if it should be seen by people who are not signed
in, and the moderation page says so. The owner, 9 November 2026, choosing the recommended
option.

**Rules out:** Approve making a suggested event public by itself, and a "Public" tick beside
Approve.

**Where:** `web/_apps/admin/calendar/moderate.php`, `web/_apps/admin/calendar/moderation.php`;
#493. Related: D-079.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
