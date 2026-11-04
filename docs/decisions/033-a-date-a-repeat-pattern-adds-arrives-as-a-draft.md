# D-033 · A date a repeat pattern adds arrives as a draft

**Date:** 2026-10-31  
**Status:** in force

**Question:** what status should a date get when changing a series' pattern
adds it?

**Answer:** a DRAFT. It does not inherit the template's status, and the
template's status is not read at all.

**Rules out:** publishing it at once. A new date has no registration window of
its own and nothing beyond the series' description, so publishing would put a
half-finished entry in front of members — and a hidden date cannot be brought
back (#691), so the recoverable mistake is the one to make.

**Where:** `Portal\Core\SeriesRepattern`; #692, #669.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
