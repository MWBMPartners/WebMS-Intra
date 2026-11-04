# D-022 · Administrators no longer bypass the live branch's rules

**Date:** 2026-11-03  
**Status:** in force

**Question:** the rules let any repository administrator through, always — remove
that for the live branch?
**Answer:** yes, remove it for `main`. Even the owner waits for the PHP syntax
check and the security checks.
**Rules out:** a red mark on `main` that stops nobody.
**Where:** ruleset "Protect main branch" (id 14829642), `bypass_actors: []`.

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
