# D-037 · Branch protection goes on now, before the merge

**Date:** 2026-11-04  
**Status:** in force

**Question:** the owner asked why #474 was not finished — should the required
checks go on now?

**Answer:** **yes, now**, before the merge. Both `PHP -l lint` and
`Static security checks` are required on `alpha`, `beta`, `release-candidate`
and `main`, as of `e50738d`.

**Rules out:** waiting for the merge, which D-036 had chosen.

**What changed between the two, which is why this is a supersession and not a
contradiction:** on 1 November the blocker was that two jobs wrote straight to
`alpha` and `beta`, so requiring checks there would have broken them. D-024
settled that on 4 November by moving the version number to release time, and the
owner then asked for #474 to be completed. **The practical consequence, said
plainly: the first pull request into any release branch is now also the first
real proof that all thirty-five of `pr-security.yml`'s gates pass**, because
GitHub could not read that file between 28 October and 3 November (#745). If
something rotted in that window, the merge is where it shows — which is the
point of a required check.

**Supersedes:** D-036.

**Where:** #474 (closed); rulesets "Keep core branches" and "Protect main
branch".

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
