# D-146 · A date that was cancelled before it was hidden comes back still cancelled

**Date:** 2026-11-11  
**Status:** in force  
**Supersedes in part:** D-145 (only for a date that was cancelled before it was hidden)

**Question:** a hidden date of a series comes back as a draft (D-145). A date may have been cancelled first (shown
to members as "Cancelled", with a reason) and hidden afterwards; as a draft, publishing it would put a cancelled
service live again. What should such a date come back as?

**Answer:** still cancelled, with its reason, so members see it as cancelled. Every other hidden date comes back as
a draft, as D-145 says.

**Rules out:** a cancelled date returning as a draft that can be published by accident; clearing its cancellation
reason on the way back.

**Where:** #691 — `Portal\Core\HiddenSeriesDates::restore()`, `web/_apps/calendar/manage/series-edit.php`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
