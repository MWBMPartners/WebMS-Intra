# D-131 · A home emptied and typed back in as "not anybody's home" is a known limit, with a warning

**Date:** 2026-11-11  
**Status:** in force

**Question:** under #809, only a place's named host may drop the host or mark the place "not anybody's home" while
the place keeps an address. One route remains: a leader empties the place (deleting its address and host, D-126),
then types the same address back in as a new place and ticks "not anybody's home". The member's home is then shown
on a map without their agreement, and their later erasure does not reach it because they are no longer named. The
portal cannot tell that the new address is the same home. How should this be handled?

**Answer:** accept it as a known limit, and warn. The "not anybody's home" tick says plainly that ticking it for
somebody's home shows that home on a map without them agreeing. The limit is recorded beside the rule in the code.

**Rules out:** keeping a fingerprint of past home addresses to recognise a retyped one (it would contradict deleting
an address straight away, D-126); restricting the tick to organisation administrators.

**Where:** #809 — `Portal\Core\SmallGroups::whyHostChangeRefused()` and the small-group place forms. Related:
D-124, D-126, D-128 (D-128 recorded only a home ticked by mistake; this records the deliberate route).

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
