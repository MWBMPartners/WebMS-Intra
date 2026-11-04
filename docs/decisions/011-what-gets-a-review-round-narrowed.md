# D-011 · What gets a review round, narrowed

**Date:** 2026-10-05  
**Status:** in force

**Question:** does documentation get a review round?
**Answer:** no — "narrow exactly as machine-wide". Code, database changes,
anything touching data, money, credentials or personal details, or a safety gate.
Not documentation, renames or comment-only changes.
**Rules out:** a review round for an instruction rewrite.
**Where:** `.claude/CLAUDE.md` → "Codex review".

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
