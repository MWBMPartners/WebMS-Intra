# D-071 · A job with no record says "nothing is known yet", not a warning

**Date:** 2026-11-06
**Status:** in force

**Question:** #775's recording is new, so on the day the files land **no job on
any existing portal has ever written a record**. What should the "Check this
portal" page say about a job it has no record for?
**Answer:** **"nothing is known yet", until that job has run once.** Not a
warning, and not a backfilled run time.

**Rules out:** warning straight away; and writing a run time at upgrade so that
nothing looks overdue.

**Why.** Warning straight away means every correctly-configured portal shows a
page of warnings the morning after it upgrades, for jobs that are running
perfectly well, and the only way to clear them is to wait. That is a false
alarm by construction, affecting every customer at once, and it is the
strongest possible lesson in ignoring the page — the #483 cost again. Once each
job runs once it starts being watched, with no action from anybody.

Backfilling was ruled out for a different reason: it would write a record
saying a job ran when nobody knows whether it did — **inventing evidence in the
one place whose purpose is telling an administrator what is actually true.**

**What this does NOT weaken.** A portal whose jobs were never set up is still
caught, and caught better: `PortalCheck::scheduledJobs()` already reports a
PROBLEM for any job whose token is empty, because such a job "has never run and
never will", and that IS knowable from the database. So the never-configured
case has a real answer; what this decision covers is the gap between a portal
upgrading and its jobs next running.

It also keeps the restraint #477 was built for: that page refuses to say "all
clear" about anything it cannot see, and "nothing is known yet" is the truthful
form of that.

**Where:** #775.
