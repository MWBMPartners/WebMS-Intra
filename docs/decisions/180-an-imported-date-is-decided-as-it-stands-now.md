# D-180 · An imported date is approved or declined as it stands now

**Date:** 2026-11-12  
**Status:** in force

**Question:** a date copied in from an outside calendar waits for an administrator to approve or decline it. The
calendar's rule about which dates may be copied in can change, and can have an end date. When the administrator
presses Approve or Decline, should the decision be made on the date as it was queued, or after re-checking it under the
calendar's rule as it stands at that moment?

**Answer:** as it stands now. The calendar is re-worked under the rule in force, inside the same lock, before the
decision is made. A date queued under a rule that has since ended or changed cannot be approved or declined on its old
basis: the administrator is told it had changed since they opened the page, and sees it as it now stands.

**Rules out:** deciding a date on the basis of a rule that no longer applies; storing, per queued date, which rule it
was queued under so that an old basis could be honoured.

**Where:** #855 — `Portal\Core\FeedAdmin::decide()` and `inFeedLock()`'s `$resolveFirst`; `tools/feed-admin-selftest.php`
D20.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
