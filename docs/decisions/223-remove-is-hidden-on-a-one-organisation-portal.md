# D-223 · "Remove" on the organisation users page is hidden on a one-organisation portal

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#1142) on a portal with ONE organisation and multisite off (the default), Admin, Organisations, Users, Remove deletes the membership but the person can still sign in and read the directory and prayer requests, because the account's own Active switch is the only gate. What should Remove do there?

**Answer:** the owner chose: hide it on a one-organisation portal (recommended), and say in one sentence that Offboarding and the Active tick are how a person leaves; the posted Remove request is refused there too. Several-organisation portals keep Remove exactly as it is (D-212).

**Rules out:** a button that looks like it removes access but does not; making Remove switch the account off (overlaps D-213).

**Where:** `web/_apps/admin/sites/users.php`; package R4 (#1136); #1142.

**Supersedes:** nothing.

**Related:** D-212, D-213.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
