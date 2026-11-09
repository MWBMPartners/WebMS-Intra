# D-088 · Pushes to the working branch go through the permission check too

**Date:** 2026-11-08  
**Status:** in force

**Question:** D-005 says finished work on `claude/alpha-wip` is committed and pushed
once an independent Claude check has run. On 8 November Claude Code's automatic
permission check refused `git push origin claude/alpha-wip` as "Git Destructive", so
`b34d839` (#832/#833) stayed local. How should pushes to this branch work?

**Answer:** **the owner allows them** — by approving the prompt or adding a permission
rule — so the assistant pushes this branch after each commit, as D-005 intends, and
closes an issue only once its commit is on GitHub. The owner, 8 November 2026, choosing
the recommended option.

**Rules out:** the owner pushing by hand; holding every commit locally until the end of
the queue (no off-machine copy of the work meanwhile).

**Does NOT change:** pushes to a release or protected branch, force pushes, pull
requests, merges and tags all still need the owner's explicit approval each time.

**Supersedes:** nothing.

**Where:** this session's handoff; #752 (decisions not about a feature). Related: D-005, which it
confirms for the permission check.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
