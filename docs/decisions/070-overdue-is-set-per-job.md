# D-070 · "Overdue" is set per job, from what each job is for

**Date:** 2026-11-06
**Status:** in force

**Question:** #775 gives every scheduled job a record that it ran, and the
"Check this portal" page will say when one looks overdue. Nothing says how late
is too late — `docs/INSTALL.md` step 6 deliberately points at the release notes
rather than naming a timetable — so there was no existing answer to follow. One
threshold for all jobs, two tiers, or one per job?
**Answer:** **one threshold per job, derived from what that job is for.**

| job | overdue after | why |
|---|---|---|
| mail queue | 15 minutes | the existing window; the one job that must run almost continuously |
| event reminders | 2 hours | it promises a reminder one hour ahead, so a two-hour gap has already missed one |
| import feeds | 6 hours | an outside calendar changing is not urgent |
| retention sweep | 36 hours | nightly, plus a day's grace |
| backup run | 36 hours | nightly, plus a day's grace |
| giving statements | 8 days | weekly at most |
| everything else | 36 hours | nightly, plus a day's grace |

**Rules out:** a single threshold for everything, and a two-tier
minutes-or-a-day split.

**Why, in the owner's terms.** The range is too wide for one number. Anything
loose enough for giving statements (weekly) would let the mail queue sit dead
for a week; anything tight enough for the mail queue (minutes) would warn about
the nightly backup every single day. The two-tier version was rejected for a
measured consequence: statements go out weekly, so they would read as overdue
six days in seven — a permanent false alarm, and **a check that cries wolf gets
switched off (#483)**, which this project has already paid for once.

**Each threshold is ONE constant**, so changing any of them later is one line.

**Where:** #775. The spine is `PortalCheck::JOB_TOKENS`, which already names
what a customer loses without each job — **but note a token is NOT a job**:
`maintenance.cronToken` alone covers backups, the health check, the mail queue
and the retention sweep, so the new record is keyed by JOB.

**Superseded in part by D-076** (7 November 2026): the "everything else → 36 hours" row no longer applies to the six jobs whose own headers say they run every few minutes or hourly (`push-golive`, `webhook-retry`, `user-reminders`, `asset-reminders`, `venue-reminders`, `workflow-timeouts`); each gets an allowance from its own timing. Everything else in this decision stands.

**Note, 7 November 2026 (#793 build):** a SEVENTH job also left 36 hours under this decision: `cron/health`, whose header is silent but which the portal tells customers to call hourly (`0 * * * *` on the staff health page), now has 3 hours — exactly the case this decision's **Where** foresaw ("judged from what they do"). `discipleship-sweep` stays at 36 hours (called once a day). So "six" above means the six whose headers state a frequency; seven moved in all.
