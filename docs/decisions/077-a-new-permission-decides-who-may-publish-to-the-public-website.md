# D-077 · A new permission decides who may put an item on the public website

**Date:** 2026-11-07  
**Status:** in force

**Question:** each event and noticeboard poster gets a "show on our public website"
setting (D-057). Who may turn it on or off (#493)?

**Answer:** **a new permission that each organisation can give to any role on its Roles
page; out of the box only that organisation's administrators hold it.** The owner,
7 November 2026, choosing the recommended option. It follows D-017: a new gate is a
re-pointable capability, never a hard-coded role.

**Rules out:** "anyone who can edit the item may publish it"; a fixed
administrators-only gate that cannot be given to anyone else.

**Where:** #493, package P1 (`website.publish` in `Portal\Core\Capabilities`).
Related: D-017, D-052, D-056, D-057.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
