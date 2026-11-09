# D-093 · Only an organisation's global administrator may hand out "Set up the public website"

**Date:** 2026-11-09  
**Status:** in force

**Question:** any administrator of an organisation can change which role opens any
permission on the Roles page, and can give anyone a role — themselves included — on the
Users page. So an ordinary administrator could give themselves the new "Set up the public
website" job (D-085), which only a global administrator holds by default. Should that be
allowed?

**Answer:** **no — only the organisation's global administrator** (or the installation's)
may change which roles open this permission, or give or take away a role that currently
opens it. The owner, 9 November 2026, choosing the recommended option.

**Rules out:** treating this permission like every other, where any administrator can hand
it out, including to themselves.

**Where:** #493 package P3 — the Roles page and the Users page's role assignment, and
`Portal\Core\PublicWebsiteSetup`. Related: D-017, D-077, D-085.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
