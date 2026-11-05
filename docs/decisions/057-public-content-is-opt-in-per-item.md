# D-057 · Public content is opt-in, one item at a time

**Date:** 2026-11-05  
**Status:** in force

**Question:** the database behind the new public surface (#493, D-056) also
holds pastoral care notes, safeguarding records, children's medical details and
the member directory. #493 asks for "a structural boundary, not a filter". How
should an item become publicly visible: somebody ticks "show publicly" per
item; anything already visible to every member; or public by type with the
option to hide one?

**Answer:** **somebody ticks "show publicly", one item at a time.** Nothing is
public until a person deliberately marks it so. The owner, 5 November 2026.

**Why this one, in the words it was chosen on:** a new content type, a new
column or a mistake in a query cannot leak anything, because the default is
private and the failure mode is silence. The other two both make the default
EXPOSURE, so a mistake shows something rather than hiding it.

**Rules out:**

* **"Anything already visible to every member."** Rejected because "visible to
  every member" and "visible to the whole world" are different things, and that
  option makes one mean the other. A member-only notice would have become
  public without anybody choosing it.
* **"Public by type, with the option to hide one."** Rejected for the same
  reason in a smaller form.

**What it costs, said plainly:** somebody has to remember to tick it, so
content will sometimes not appear on the public website until they do. That is
the price of the failure mode being silence, and it was chosen knowing it.

**What this does NOT settle:** whether the tick is one switch or a date range,
who may set it, and whether it is recorded in the activity log. Those belong in
the build plan on #493.

**Where:** #493.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
