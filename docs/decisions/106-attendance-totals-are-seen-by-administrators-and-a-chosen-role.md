# D-106 · An organisation's attendance totals are seen by administrators and a role it chooses

**Date:** 2026-11-10  
**Status:** in force

**Question:** the attendance reports page shows headcounts by month and by service type (totals,
never named people). Any signed-in member could open it, and an account from another organisation
could too, by using this organisation's address (#529). Who should see these figures?

**Answer:** **administrators, plus whoever the organisation points a permission at on the Roles
page** (shipped as attendance coordinators) — a capability, as D-017 requires of any new gate.
Members and other organisations' accounts get the same "not found" as a page that does not exist.
The owner, 10 November 2026, choosing the recommended option.

**Rules out:** every member of the organisation seeing the totals; administrators only with no
role; another organisation's account reaching them.

**Where:** #529 — `web/_apps/attendance/report.php`, its CSV export and any endpoint returning the
same figures; `Portal\Core\Capabilities`. Related: D-017, #511, #525.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
