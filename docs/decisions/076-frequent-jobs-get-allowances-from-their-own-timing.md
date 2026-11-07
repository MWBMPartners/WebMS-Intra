# D-076 · Frequent scheduled jobs get allowances from their own timing

**Date:** 2026-11-07  
**Status:** in force

**Question:** D-070 set "everything else" to count as stopped after 36 hours ("nightly,
plus a day's grace"). Measured (#793): six of those eleven jobs say in their own page
headers that they run every few minutes or hourly — `push-golive` (every 5 minutes),
`webhook-retry` (every 5–15 minutes), `user-reminders` (every 15 minutes),
`asset-reminders` (every 15–60 minutes), `venue-reminders` (every 30–60 minutes) and
`workflow-timeouts` (hourly) — so a 5-minute job could stop for a day and a half before
the Check-this-portal page said anything. What should their allowance be?

**Answer:** **set each from its own timing** — an allowance a few times its normal gap
(for example about 30 minutes for a 5-minute job and about 3 hours for an hourly one).
The truly nightly jobs (`retention-sweep`, `backup-run`, `backup-check`, and any other
that really runs once a day) keep 36 hours. The owner, 7 November 2026.

**Rules out:** one shorter tier for all six; keeping 36 hours for them.

**Supersedes:** **D-070's "everything else → 36 hours" row, for those six jobs only.** The
rest of D-070 (per-job allowances; 15 minutes for the mail queue, 2 hours for event
reminders, 6 for feed imports, 8 days for giving statements) stands.

**Where:** #793; `ScheduledJobs::JOBS` and `ScheduledJobs::allowancesInWords()`. The two
jobs whose headers say nothing (`health`, `discipleship-sweep`) are judged from what they
do, and the reason written beside each.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
