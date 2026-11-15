# D-187 · The network address on a change-history entry is kept for ever, with the entry (an exception to D-178)

**Date:** 2026-11-15  
**Status:** in force

**Question:** D-116 keeps the change history (`tblAuditTrail`, `tblAssetAudit`) for ever and rules out a separate
retention setting for it. D-178 says every place that keeps a network (IP) address needs a set keeping period. Each
change-history entry holds a network address beside it. Should the new "how long network addresses are kept" setting
also empty that address on old entries, keeping the entry itself?

**Answer:** the owner chose "Leave change-history addresses for ever". The address on a change-history entry stays
with the entry for ever. D-116 holds in full; D-178 stays in force with this one stated exception, for these two tables only. An erasure still reaches the
addresses on a person's own records where D-164 and D-178 say so. What members read must say so plainly: the
change history, with the address it recorded, is not cleared.

**Rules out:** emptying the address on change-history entries on any timer; a member-facing sentence implying the
change history's addresses are cleared or that every address has a time limit.

**Where:** #1019 (`NetworkAddressRetention::PLACES` does not list the two change-history tables; the catalogue
sentences and both privacy pages say "kept for ever"). Related: D-116, D-164, D-178.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
