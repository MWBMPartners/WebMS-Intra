# D-006 · Times respect the zone, and both clock changes

**Date:** 2026-09-23  
**Status:** in force

**Question:** how are times handled?
**Answer:** every stored, computed, compared or displayed time respects its zone
and stays correct across both clock-change nights. Where a repeat spans the
change hour, follow RFC 5545 §3.8.5.3 (same exact length).
**Rules out:** matching whatever Google happens to show; assuming a day is 24
hours; a hard-coded zone.
**Where:** `.claude/CLAUDE.md` → "Times always respect the time zone".

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
