# D-203 · Correction to the wording of D-202: a check is never run on a weaker tier than the build

**Date:** 2026-11-16  
**Status:** in force

**Question:** one sentence in the model-tier decision (D-202) reads "building never uses a weaker tier than a check would". Taken literally that would force building onto the strongest tier, the opposite of what the owner decided.

**Answer:** the intended meaning, which is what the owner decided and what every instruction file states: a check or review is never run on a weaker tier than the build it checks. Building may use Haiku or Sonnet; checks and reviews by Claude run on Opus.

**Rules out:** reading D-202 as requiring Opus for building.

**Where:** `.claude/CLAUDE.md`, `.OpenAI/CONTEXT.md`, `~/.claude/CLAUDE.md` already say it correctly. Found by the independent check of the #808 landing.

**Supersedes:** nothing.

---

*One decision per file, under `docs/decisions/`, so that every assistant and
every person reads the same record. Never edited — a decision taken again is
a NEW file that names the one it supersedes. See `docs/decisions/README.md`.*
