# D-144 · Bringing back a date onto a day the series already uses needs a deliberate second confirmation

**Date:** 2026-11-11  
**Status:** in force

**Question:** when bringing back a hidden date, the series may already have a live date on that same day (for
example after a series was shortened and then extended over the same weeks). Refuse it (no accidental duplicates,
but a genuine morning-and-evening series cannot get one back), or allow it with a warning?

**Answer:** a combination of the two (the owner asked for one). The first press is refused and says why: this
series already has a date on that day. The page then offers a separate, deliberate "bring it back anyway" choice,
which restores the date and says plainly that the series now has two dates on that day.

**Rules out:** a silent duplicate on one press; an outright refusal with no way through.

**Where:** #691 — `Portal\Core\HiddenSeriesDates::restore()` and `words()`,
`web/_apps/calendar/manage/series-edit.php`. Related: D-143, D-145.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
