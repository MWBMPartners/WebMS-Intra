# D-191 · The installation's own administrator needs the merge permission in an organisation, like anyone else

**Date:** 2026-11-16  
**Status:** in force

**Question:** may the installation's own administrator (for example a hosting company) merge accounts inside an organisation without being given the merge permission there?

**Answer:** the owner chose "No, the organisation must give it". The `accounts.merge` permission does not fall open for a global administrator, as `erasure.review` does not (#724): the installation's administrator and the organisation's may be different parties, and a merge exposes every record of both accounts.

**Rules out:** an automatic merge right for the installation's administrator.

**Where:** #1064 (`Capabilities`, `globalAdminFallsOpen = false`). Related: D-017, D-190.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
