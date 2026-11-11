# D-147 · A date that was postponed before it was hidden comes back still postponed

**Date:** 2026-11-11  
**Status:** in force  
**Supersedes in part:** D-145 (only for a date that was postponed before it was hidden)

**Question:** D-146 brings a cancelled date back still cancelled. A date can also be "postponed" (shown to members as
postponed, with a reason). Brought back as a draft (D-145), it could be published as an ordinary live date. Should a
postponed date come back still postponed?

**Answer:** yes. A date that was postponed before it was hidden comes back still postponed, with its reason, exactly
like a cancelled one (D-146). Every other hidden date comes back as a draft (D-145).

**Rules out:** a postponed date returning as a draft that can be published by accident.

**Where:** #691 — `Portal\Core\HiddenSeriesDates::restore()`, `web/_apps/calendar/manage/series-edit.php`. Related:
D-146.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
