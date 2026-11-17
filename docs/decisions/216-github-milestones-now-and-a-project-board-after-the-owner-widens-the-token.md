# D-216 · GitHub milestones are created now; the project board follows once the owner widens the gh permission

**Date:** 2026-11-17  
**Status:** in force

**Question:** (#1132) D-214 wants the repository's plans kept up to date. Today there is one milestone and no board, and the `gh` tool lacks the `read:project` scope.

**Answer:** the owner chose: milestones now (Pilot install, Release per D-055, After release; every open issue placed in one and kept current at each landing), and the project board after the owner runs `gh auth refresh -s read:project -s project`.

**Rules out:** leaving the plan on labels alone; pretending a board is maintained before the permission exists.

**Where:** GitHub milestones on MWBMPartners/WebMS-Intra; `.claude/HANDOFF.md`; #1132.

**Supersedes:** nothing.

**Related:** D-214.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
