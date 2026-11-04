# D-024 · The version number moves to release time

**Date:** 2026-11-04  
**Status:** in force

**Question:** how to finish #474 — the two jobs that write straight to `alpha`
block it.
**Answer:** **set the version at release time instead.** Stop raising it on every
push; `package.yml` sets it once when a release is built. Then no job writes to a
guarded branch, #474 and #476 both close, and no stored credential is needed.
`alpha`'s number is also brought back in line (1.4.0 against 1.4.1 elsewhere).
**Rules out:** creating a long-lived access token; leaving the two jobs broken;
the version number creeping up on every alpha push.
**Supersedes:** D-020 and D-021.
**Where:** #474, #476, #748.

**Implemented 4 November 2026.** `version-bump.yml` and `changelog.yml` run on
`workflow_dispatch` only; both refuse to run against `alpha`, `beta`, `main` or
`release-candidate`, because a branch that requires its tests refuses every
direct push and a confusing push failure is worse than an honest refusal. The
number travels to the release branches through the merge, and `package.yml`
still refuses to build a release whose number was not raised above the last
tag — which is what makes the hand-run step impossible to forget.
`web/_core/version.php` is raised to 1.4.1 on the working branch, so the merge
brings `alpha` level with `beta` and `main` rather than anybody pushing to a
release branch.

**Two faults this would have introduced, caught before committing.** Both jobs
read `github.event.head_commit.message`, and **there is no `head_commit` on a
`workflow_dispatch` event** — so `beta` would have taken a PATCH bump for ever
(no message, so no "BREAKING CHANGE" and no "feat("), and a release that should
have been 2.0.0 would quietly have been 1.4.2. The bump is now worked out from
every commit since `web/_core/version.php` last changed, which is the right
basis for a number set at release time. And both jobs carried
`if: !contains(github.event.head_commit.message, '[skip ci]')`, which on a
dispatch can never fire — a condition that cannot fail, removed rather than
left to mislead.

**With that done, #474's other half went on:** `PHP -l lint` and
`Static security checks` are now required on `alpha`, `beta`,
`release-candidate` and `main`. Administrators keep their bypass on the core
ruleset — D-022 deliberately removed it for the live branch only.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
