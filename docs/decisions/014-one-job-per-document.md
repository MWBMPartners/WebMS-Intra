# D-014 · One job per document

**Date:** 2026-10-30  
**Status:** in force

**Question:** where does each kind of record live?
**Answer:** issues are what is and is not done; the handoff is where we are right
now and is **overwritten**; the changelog is what shipped; dev notes are lessons;
plans get a terminal line and move to history. Anything deferred gets an issue in
the same action that decides it.
**Rules out:** something not-done living only in prose; citing a document by line
number.
**Where:** `.claude/CLAUDE.md` → "One job per document".

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
