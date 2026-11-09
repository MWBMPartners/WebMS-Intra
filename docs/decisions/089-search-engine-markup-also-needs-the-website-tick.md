# D-089 · An event's search-engine markup also needs the public-website tick

**Date:** 2026-11-08  
**Status:** in force

**Question:** an event's own page on the portal carries hidden search-engine markup (an
`application/ld+json` "Event" block) that lets Google and others list the event in
their own results with its name, date and place. For the portal's own events it
followed the "Public" box alone. With the new "Show on our public website" tick
(D-057, D-078), should that markup also need the tick (#850)?

**Answer:** **yes — it also needs the tick**, and today inside the event's website dates.
The page itself stays visible to anyone who may open it; only the advertisement to
search engines waits for the deliberate tick. The owner, 8 November 2026, choosing the
recommended option. Built as a small package straight after #493 package P1.

**Rules out:** search-engine markup following "Public" alone.

**Where:** `web/_apps/calendar/event.php`; #850. Related: D-057, D-078, D-080, D-081.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
