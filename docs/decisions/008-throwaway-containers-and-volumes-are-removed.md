# D-008 · Throwaway containers and volumes are removed

**Date:** 2026-09-24  
**Status:** superseded

**Question:** how are test databases cleaned up?
**Answer:** `docker run --rm`, or `docker rm -v`; report the dangling-volume count
before and after; never `prune`.
**Rules out:** removing another project's container or a named volume.
**Superseded in part by:** D-023.
**Where:** `.claude/CLAUDE.md` → "Throwaway databases and containers".

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
