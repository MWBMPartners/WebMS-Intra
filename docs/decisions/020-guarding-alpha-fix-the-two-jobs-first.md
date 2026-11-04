# D-020 · Guarding `alpha`: fix the two jobs first

**Date:** 2026-11-03  
**Status:** superseded

**Question:** should `alpha` and `beta` refuse a change until the tests pass,
given that two jobs write straight to them?
**Answer:** leave them unguarded for now; change the version-number and changelog
jobs to propose a merge request, then switch the rule on.
**SUPERSEDED BY D-021**, because of a measured fact: GitHub starts no checks on
anything created with the built-in token, so such a merge request could never
merge.
**Where:** #474, #748.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
