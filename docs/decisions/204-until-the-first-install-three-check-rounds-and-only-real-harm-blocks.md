# D-204 · Until the first install: three check rounds per package, and only real harm blocks

**Date:** 2026-11-17  
**Status:** in force

**Question:** the review rule for packages that touch money, personal details or stored data was "recheck until a round finds nothing". In one session three large packages took 4 to 12 rounds each, and most late findings were very narrow, so the queue was not clearing and the first install was getting no closer. How should the rounds be capped?

**Answer:** the owner chose: for the period up to the first install, every package gets at most three independent check rounds (later rounds look only at what changed, not the whole package again). After that it ships. Only a realistic harm blocks it, and is fixed first: losing or leaking somebody's data, losing or misstating money, or letting somebody in who should not be. Anything narrow, unlikely, needing an unusual sequence, or about wording or tests becomes an issue, named in the commit message, and does not hold the package. Checks and reviews by Claude still run on Opus.

**Rules out:** more than three rounds for a package in this period; letting a real data, money or access harm ship open; rechecking a whole package from scratch every round.

**Where:** `.claude/CLAUDE.md` and `.OpenAI/CONTEXT.md` (one pointer each beside the review-rounds rule). Applies from 17 November 2026 until the first install is done; the owner may restore the longer rule after that.

**Supersedes:** nothing.

**Related:** D-051 (the longer rule) stands for after the first install; D-064 and D-066 (rounds with only tooling findings) and D-186 (Opus checks) are unchanged.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
