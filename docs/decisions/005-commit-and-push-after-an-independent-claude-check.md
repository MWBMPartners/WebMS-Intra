# D-005 · Commit and push after an independent Claude check

**Date:** 2026-09-16  
**Status:** in force

**Question:** must work wait for Codex before being committed?
**Answer:** no. Commit and push once a fresh Claude agent that did not build it
has checked it, with the commit message saying plainly that Codex has not.
**Rules out:** holding finished work until Codex is available.
**Where:** `.claude/CLAUDE.md` → "When to run it".

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
