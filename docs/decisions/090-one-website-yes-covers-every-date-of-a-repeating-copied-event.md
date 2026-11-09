# D-090 · One website "Yes" for every date of a repeating copied-in event covers all its dates

**Date:** 2026-11-08  
**Status:** in force

**Question:** an event copied in from an outside calendar can be a REPEATING entry, such
as a weekly service. Under the stricter rule for copied-in events (D-080, "one tick per
copied event"), when an administrator says Yes, once, to showing EVERY date of that
repeating entry on the public website, should that put all its dates there — including
future dates the outside calendar adds later (#857)?

**Answer:** **yes — one Yes for every date covers all of them.** A repeating entry counts
as one event, as it does in the outside calendar. A later date follows that Yes only while
its title, details and request match the dates already approved; a changed date waits for
approval again. The owner, 8 November 2026, choosing the recommended option. This is how
#493 package P1 was already built, so nothing changes.

**Rules out:** requiring each date of a repeating copied-in entry to be ticked on its own
before it reaches the public website.

**Where:** `web/_core/EventVisibility.php` (`PER_EVENT_SOURCES`), `web/_core/FeedResolver.php`
(pass 2, a date following its approved siblings); #857, #493. Related: D-080, D-081.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
