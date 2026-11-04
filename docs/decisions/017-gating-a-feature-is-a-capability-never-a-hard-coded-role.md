# D-017 · Gating a feature is a capability, never a hard-coded role

**Date:** 2026-11-03  
**Status:** in force

**Question:** how are features gated?
**Answer:** a feature declares a **capability** that an organisation points at
roles of its own choosing. An organisation's global administrator falls open on
everything and can re-assign. This is the mechanism for all new features.
**Rules out:** a new `App::hasRole('x')` call; `erasure.review` falling open for a
global administrator.
**Where:** `Portal\Core\Capabilities`; `.claude/CLAUDE.md`.

**Issue:** [#752](https://github.com/MWBMPartners/WebMS-Intra/issues/752) —
the standing issue for decisions that are not about a feature.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
