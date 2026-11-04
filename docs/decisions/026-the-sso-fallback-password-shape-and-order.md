# D-026 · The SSO fallback password: shape and order

**Date:** 2026-11-04  
**Status:** in force

**Question:** three parts — may the people running the portal forbid somebody
adding a password to their own account; does #728 come first; and does SIGNula.id
need to agree?
**Answer:** (a) **yes, and the stricter level wins** — the three-tier shape of
D-013 applies. (b) **#728 first**, so one piece of code sets every password
before this adds a seventh writer on a recovery path. (c) **read SIGNula.id
first**, then propose one design for both.
**Rules out:** no setting at all; a single on/off for the whole copy; building
here and writing the decision down for SIGNula.id later.
**Where:** #731 — blocked on #728 and on a reading pass of
`github.com/MWBMPartners/SIGNula.id`.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
