# D-151 · A worker's download counts, and does not list, records in organisations they have left

**Date:** 2026-11-12  
**Status:** in force

**Question:** under D-142 a worker's data download lists the pastoral, DBS and children's check-in records that carry
their name (type and date only). Some may be in an organisation the worker has since left; on one installation,
organisations may be run by different people, each in charge of its own records. List those too, or only count them?

**Answer:** count only. Records in organisations the worker no longer belongs to are counted, with a sentence saying
that organisation can tell them about the records; records in their current organisations are listed as D-142 says.

**Rules out:** listing another organisation's records in a worker's download.

**Where:** #929 — `Portal\Core\GdprEraser::recordsCarryingYourName()`, the My data page and the full data copy.
Related: D-142.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
