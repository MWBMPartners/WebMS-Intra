# D-196 · A hidden event suggestion still waiting for approval comes back as a draft, with any cancellation reason removed

**Date:** 2026-11-16  
**Status:** in force

**Question:** before #965, a visitor's event suggestion still waiting for approval could be set to Cancelled (with a reason members see). If that date was then hidden, "Bring it back" returned it still cancelled with its reason, as D-146 says for any cancelled date. A waiting suggestion that is also cancelled is now refused by every page (#965). What should bringing one back do (#1081)?

**Answer:** the owner chose to bring it back as a draft, still waiting for approval, with the cancellation reason removed. This is a narrow exception inside D-146 that applies only to a suggestion still waiting for approval; every other hidden date keeps its status and reason as D-146 and D-147 say.

**Rules out:** refusing to bring such a suggestion back; bringing it back still cancelled; widening the exception to any date that is not a waiting suggestion.

**Where:** #1081 (`HiddenSeriesDates::statusOnReturn()`, `restore()`, `EventCancelReason::write()`). Related: D-145, D-146, D-147, D-169.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
