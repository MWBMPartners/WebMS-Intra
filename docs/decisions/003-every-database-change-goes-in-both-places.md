# D-003 · Every database change goes in both places

**Date:** 2026-09-11  
**Status:** in force

**Question:** where does a schema change live?
**Answer:** in **both** `web/_sql/full_schema.sql` and a numbered migration, and
the two must agree.
**Rules out:** a migration without the install script, or the reverse.
**Where:** `.claude/rules/database.md`.

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
