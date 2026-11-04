# D-044 · A series that has already started is still published as one entry

**Date:** 2026-10-29  
**Status:** in force

**Question:** should a repeating series that has already begun be published to
calendar subscribers as one repeating entry, or as separate dates?

**Answer:** still one entry, starting at its own first date.

**Rules out:** splitting a started series into separate dates, which would make
every subscriber's calendar churn.

**Where:** `calendar/export.php`; #669's rule row.

**Note the limit this works with:** the single entry is published only while it
still describes the series truthfully — once a date is moved or a draft remains,
subscribers see the dates separately (#670).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
