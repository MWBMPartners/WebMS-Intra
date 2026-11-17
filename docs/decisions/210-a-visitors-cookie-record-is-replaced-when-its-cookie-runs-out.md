# D-210 · A visitor's cookie-choice record counts as replaced when its cookie runs out

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#934) a visitor who is not signed in makes a cookie choice and the portal keeps a record of it. If they never choose again it is never "replaced", so under D-166 as written it would be kept for ever. When should it be deleted?

**Answer:** the owner chose: treat such a record as replaced when the cookie it set runs out (365 days after it was made, when the browser asks again), so it is deleted 365 days plus the privacy data-retention period after it was made.

**Rules out:** keeping a visitor's cookie record for ever; a separate fixed period.

**Where:** #934 (package 2g, the consent-record clear-out in `cron/retention-sweep`).

**Supersedes:** nothing.

**Related:** D-166 stands; this settles what "replaced" means for a visitor.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
