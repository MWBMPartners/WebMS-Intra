# D-121 · A public address may be requested by several organisations until one is approved

**Date:** 2026-11-11  
**Status:** in force

**Question:** each organisation can request its own public website address, which the installation's administrator
must approve before the door answers on it (D-101). An address is unique even before approval, so one organisation
can request another's real address first and block it. How should this work?

**Answer:** any number of organisations may request the same address while it waits. The installation's
administrator sees that requests clash and approves only one; the others are then told the address is taken.
Uniqueness is enforced at the moment of approval, so nobody can block another by asking first.

**Rules out:** first-come uniqueness of an unapproved request; approving two organisations for one address.

**Where:** #895 — `Portal\Core\PublicWebsiteSetup` and the approval page. Related: D-101.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
