# D-145 · A date brought back comes back as a draft

**Date:** 2026-11-11  
**Status:** in force  
**Superseded in part by:** D-146 (a date cancelled before it was hidden comes back cancelled)

**Question:** when a hidden date of a series is brought back, should it return as a draft (hidden from members until
somebody publishes it) or with the status it had before it was hidden?

**Answer:** as a draft, whatever it was before; an administrator publishes it from the same bulk-edit page. The
reasoning is D-033's: a date nobody has reviewed should not appear to members unasked.

**Rules out:** restoring a date straight to published.

**Where:** #691 — `Portal\Core\HiddenSeriesDates::restore()`. Related: D-033 (whose other reason, that a hidden date
cannot be brought back, no longer holds since #691).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
