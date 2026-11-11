# D-143 · A series' bulk-edit page offers every hidden date back, however it was hidden

**Date:** 2026-11-11  
**Status:** in force

**Question:** an administrator can now bring back hidden dates from a repeating series' bulk-edit page. A date can
be hidden by changing the repeat pattern, by the Delete button on Manage Events, or through the events API, and
nothing records which. Should the page offer every hidden date, or only those a pattern change hid?

**Answer:** every hidden date in the series, however it was hidden. The page says that this includes dates
somebody deleted by hand.

**Rules out:** recording how each date was hidden in order to offer only pattern-change dates.

**Where:** #691 — `Portal\Core\HiddenSeriesDates::listFor()`, `web/_apps/calendar/manage/series-edit.php`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
